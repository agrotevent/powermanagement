<?php
declare(strict_types=1);

namespace App\Auth\Sso;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\UpdateService;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Session;
use App\Support\Settings;

/**
 * Einstellungen der Anmeldung über SSO (OpenID Connect) – ohne Netz.
 *
 * Die Werte stehen in `settings` (nie in Settings::OVERRIDABLE, nie in der
 * Laufzeitkonfiguration). Das Client-Secret liegt verschlüsselt und gilt nur
 * für genau die Verbindung (Aussteller + Client-ID), für die es eingetragen
 * wurde (`sso.secret_fuer`) – zieht die Verbindung um, ginge es sonst beim
 * nächsten Einlösen an die neue Adresse, wer immer das ist.
 *
 * Was bestimmt, wohin das Secret geht und unter welcher Adresse die
 * Anmeldung zurückkommt, steht nur in config/config.php: `app.url` (Pflicht,
 * https) und der Block `sso` (verbinden_ueber, ca_datei, lokal_http).
 * Die Rückruf-Adresse entsteht nie aus Host oder X-Forwarded-*.
 */
final class Einstellungen
{
    public const RUECKRUF = '/login/sso/rueckruf';
    public const AKTIV = 'sso.aktiv', NAME = 'sso.name', AUSSTELLER = 'sso.aussteller', CLIENT_ID = 'sso.client_id',
        SECRET_FUER = 'sso.secret_fuer', SCOPES = 'sso.scopes', GRUPPEN_CLAIM = 'sso.gruppen_claim',
        PFLICHTGRUPPEN = 'sso.pflichtgruppen', ANLEGEN = 'sso.anlegen', ADMINS = 'sso.admins', MFA = 'sso.mfa_verwaltung',
        NUR_SSO = 'sso.nur_sso', KUNDEN = 'sso.kunden', RUECKKEHR = 'sso.abmelde_rueckkehr', INAKTIV_TAGE = 'sso.inaktiv_tage',
        MARKE = 'sso.marke', DISCOVERY = 'sso.discovery', DISCOVERY_AM = 'sso.discovery_am', JWKS = 'sso.jwks',
        JWKS_AM = 'sso.jwks_am', JWKS_NEU_AM = 'sso.jwks_neu_am', GEPRUEFT_AM = 'sso.geprueft_am', BEFUND = 'sso.befund',
        DISCOVERY_NEU_AM = 'sso.discovery_neu_am';

    public const VORGABE_NAME = 'Authentik';
    public const VORGABE_SCOPES = 'openid profile email';
    public const GRUPPEN_CLAIMS = ['groups', 'entitlements', 'roles'];
    public const MAX_SCOPES = 8, MAX_PFLICHTGRUPPEN = 20, MAX_INAKTIV_TAGE = 3650;
    /** Sitzung und SSO-Rollen ohne security.max_session: 12 h. */
    public const SITZUNG_VORGABE = 43200;
    /** Zum Einschalten von „nur SSO“ einzutippen (ohne Groß/Klein). */
    public const NUR_SSO_EINTIPPEN = 'nur SSO';

    /** Schalter, die optionenSpeichern() setzt (schutzSpeichern() und kundenSchalten() setzen die übrigen). */
    private const OPTIONEN_SCHALTER = ['anlegen' => self::ANLEGEN, 'abmelde_rueckkehr' => self::RUECKKEHR];
    private const ADRESSE_ZEILE = "'url' => 'https://strom.example.de'";

    /** Für Prüfskripte: Zeitsprünge (Sekunden seit 1970). */
    public static ?\Closure $uhr = null;

    public static function jetzt(): int
    {
        return self::$uhr !== null ? (int) (self::$uhr)() : time();
    }

    // ------------------------------------------------------------ Zustand

    /** sso.aktiv ∧ eingerichtet() – nur Einstellungen, nie Netz (Anmeldeseite, jede Anfrage). */
    public static function aktiv(): bool
    {
        try {
            return Settings::get(self::AKTIV) === '1' && self::eingerichtet();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Issuer gültig, Client-ID, Secret für genau diese Verbindung, app.url gültig. */
    public static function eingerichtet(): bool
    {
        try {
            return self::ausstellerFehler(self::aussteller()) === null
                && self::clientIdGueltig(self::clientId())
                && self::secret() !== ''
                && self::appUrlFehler() === null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Beschriftung des Knopfs oder null – wirft nie (die Anmeldeseite muss immer gehen). */
    public static function knopf(): ?string
    {
        try {
            return self::aktiv() ? self::name() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function name(): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) Settings::get(self::NAME, '')));
        return $name === '' ? self::VORGABE_NAME : mb_substr($name, 0, 40);
    }

    public static function aussteller(): string
    {
        return (string) Settings::get(self::AUSSTELLER, '');
    }

    public static function clientId(): string
    {
        return (string) Settings::get(self::CLIENT_ID, '');
    }

    /** Entschlüsselt; '' wenn nicht für die jetzige Verbindung (secret_fuer) oder unlesbar. */
    public static function secret(): string
    {
        $abgelegt = (string) Settings::get(Settings::GEHEIM_SSO_SECRET, '');
        if ($abgelegt === '' || !self::secretPasst()) {
            return '';
        }
        try {
            return Crypto::decrypt($abgelegt);
        } catch (\Throwable) {
            return ''; // ohne gültigen app.key kein Secret
        }
    }

    /** hinterlegt | fehlt | andere_verbindung (für einen anderen Aussteller/Client) | unlesbar (app.key) – nie das Secret. */
    public static function secretZustand(): string
    {
        if ((string) Settings::get(Settings::GEHEIM_SSO_SECRET, '') === '') {
            return 'fehlt';
        }
        if (!self::secretPasst()) {
            return 'andere_verbindung';
        }
        return self::secret() === '' ? 'unlesbar' : 'hinterlegt';
    }

    /** app.url + RUECKRUF, '' ohne gültige app.url. */
    public static function rueckrufAdresse(): string
    {
        return self::appUrlFehler() === null ? self::appUrl() . self::RUECKRUF : '';
    }

    /** Rücksprung nach dem Abmelden beim Anmeldedienst: app.url + /login, '' ohne gültige app.url. */
    public static function abmeldeRueckkehr(): string
    {
        return self::appUrlFehler() === null ? self::appUrl() . '/login' : '';
    }

    /**
     * Taugt app.url für SSO? null = ja, sonst die Meldung.
     *
     * Nur `https://host[:port]` ohne Pfad (außer „/“), Query und Fragment –
     * der Router kennt keine Installation in einem Unterordner. http nur mit
     * `sso.lokal_http` auf 127.0.0.1/localhost (Prüfskripte).
     */
    public static function appUrlFehler(): ?string
    {
        $roh = trim((string) Config::get('app.url', ''));
        if ($roh === '') {
            return 'In config/config.php fehlt app.url – für die Anmeldung über SSO Pflicht, etwa ' . self::ADRESSE_ZEILE . ' (https, ohne Pfad).';
        }
        $fehler = self::adresseFehler($roh, false);
        if ($fehler === null) {
            $pfad = (string) parse_url($roh, PHP_URL_PATH);
            if ($pfad !== '' && $pfad !== '/') {
                $fehler = 'ohne Pfad – mit SSO geht keine Installation in einem Unterordner.';
            }
        }
        return $fehler === null ? null
            : 'app.url in config/config.php taugt nicht für SSO (' . $fehler . ') Beispiel: ' . self::ADRESSE_ZEILE . '.';
    }

    /**
     * Taugt diese Adresse als Aussteller (Issuer)? null = ja, sonst die Meldung.
     * https (http nur mit sso.lokal_http auf Loopback), ohne Benutzer/Kennwort,
     * Query und Fragment, höchstens 255 Zeichen.
     */
    public static function ausstellerFehler(string $url): ?string
    {
        if ($url === '') {
            return 'Bitte den Aussteller (Issuer) eintragen – in Authentik unter Providers → <Provider> → OpenID Configuration Issuer.';
        }
        if (strlen($url) > 255) {
            return 'Der Aussteller ist länger als 255 Zeichen.';
        }
        $fehler = self::adresseFehler($url, false);
        return $fehler === null ? null : 'Der Aussteller taugt nicht: ' . $fehler;
    }

    /** Ein Hinweis (kein Fehler) zum Aussteller – etwa ein Authentik-Pfad ohne „/“ am Ende. */
    public static function ausstellerWarnung(string $url): ?string
    {
        $pfad = (string) parse_url($url, PHP_URL_PATH);
        if (str_contains($pfad, '/application/o/') && !str_ends_with($pfad, '/')) {
            return 'Der Aussteller endet nicht mit „/“ – Authentik nennt ihn mit „/“ am Ende; sonst passt „iss“ im Token nicht.';
        }
        return null;
    }

    /** https (bzw. lokal_http auf Loopback) und gleiche Herkunft (Schema, Host, Port) wie der Issuer? */
    public static function gleicheHerkunft(string $url): bool
    {
        $soll = self::herkunft(self::aussteller());
        return $soll !== null && self::herkunft($url) === $soll;
    }

    /**
     * Schema://host:port einer Adresse, an die PowerManagement etwas schicken
     * darf – null, wenn nicht (kein https bzw. lokal_http, Benutzer/Kennwort,
     * Fragment, Leer- oder Steuerzeichen). Eine Query ist erlaubt (OAuth
     * erlaubt sie am Authorization-Endpunkt).
     */
    public static function herkunft(string $url): ?string
    {
        if (self::adresseFehler($url, true) !== null) {
            return null;
        }
        $p = parse_url($url);
        $schema = (string) $p['scheme'];
        return $schema . '://' . strtolower((string) $p['host']) . ':' . (int) ($p['port'] ?? ($schema === 'https' ? 443 : 80));
    }

    /** Legt die Marke an, wenn sie fehlt. Sie steht in jeder SSO-Sitzung; neu heißt: alle laufenden SSO-Sitzungen enden. */
    public static function marke(): string
    {
        $marke = (string) Settings::get(self::MARKE, '');
        if (preg_match('/^[0-9a-f]{16}$/', $marke) !== 1) {
            $marke = bin2hex(random_bytes(8));
            Settings::put(self::MARKE, $marke);
        }
        return $marke;
    }

    /**
     * Ist SSO noch an und die Einrichtung dieselbe wie bei $marke (dem Start
     * eines Vorgangs)? Liest die Einstellungen frisch – nicht aus dem
     * Zwischenspeicher dieser Anfrage: Der Rückruf hat womöglich lange auf
     * den Anmeldedienst gewartet, und inzwischen hat jemand SSO ausgeschaltet
     * oder die Verbindung geändert.
     */
    public static function unveraendert(string $marke): bool
    {
        Settings::vergessen();
        return preg_match('/^[0-9a-f]{16}$/', $marke) === 1 && self::aktiv() && hash_equals(self::marke(), $marke);
    }

    public static function markeErneuern(): void
    {
        Settings::put(self::MARKE, bin2hex(random_bytes(8)));
    }

    /** max_session (> 0) oder 43200 s – Höchstdauer einer SSO-Sitzung und Gültigkeit der SSO-Rollen. */
    public static function sitzungDauer(): int
    {
        $hoechstens = (int) Config::get('security.max_session', self::SITZUNG_VORGABE);
        return $hoechstens > 0 ? $hoechstens : self::SITZUNG_VORGABE;
    }

    public static function scopes(): string
    {
        $liste = self::scopeListe((string) Settings::get(self::SCOPES, ''));
        return is_array($liste) ? implode(' ', $liste) : self::VORGABE_SCOPES;
    }

    public static function gruppenClaim(): string
    {
        $claim = (string) Settings::get(self::GRUPPEN_CLAIM, '');
        return in_array($claim, self::GRUPPEN_CLAIMS, true) ? $claim : 'groups';
    }

    /** @return list<string> */
    public static function pflichtgruppen(): array
    {
        $liste = json_decode((string) Settings::get(self::PFLICHTGRUPPEN, '[]'), true);
        if (!is_array($liste)) {
            return [];
        }
        return array_values(array_filter($liste, static fn (mixed $g): bool => is_string($g) && $g !== ''));
    }

    public static function schalter(string $schluessel): bool
    {
        return Settings::get($schluessel) === '1';
    }

    /** Tage ohne SSO-Anmeldung, nach denen „wartung“ ein nur_sso-Konto deaktiviert (0 = nie). */
    public static function inaktivTage(): int
    {
        $tage = (string) Settings::get(self::INAKTIV_TAGE, '0');
        return ctype_digit($tage) ? min((int) $tage, self::MAX_INAKTIV_TAGE) : 0;
    }

    /**
     * Für Oberfläche und CLI – ohne Geheimnis: aussteller, client_id,
     * secret (hinterlegt|fehlt|andere_verbindung|unlesbar), name, aktiv,
     * rueckruf, Cache-Alter, kids, befund, schalter.
     */
    public static function zustand(): array
    {
        $jetzt = self::jetzt();
        $cache = Anbieter::zwischenspeicher();
        $d = $cache['discovery'];
        $befund = json_decode((string) Settings::get(self::BEFUND, '[]'), true);
        $befund = is_array($befund) ? array_values(array_filter($befund, static fn (mixed $b): bool
            => is_array($b) && is_bool($b['ok'] ?? null) && is_string($b['text'] ?? null))) : [];
        $geprueft = (string) Settings::get(self::GEPRUEFT_AM, '');
        $fremde = 0;
        try {
            $fremde = (int) Database::scalar('SELECT COUNT(*) FROM sso_identitaeten WHERE aussteller <> ?', [self::aussteller()]);
        } catch (\Throwable) {
            // vor der Migration
        }
        return [
            'aktiv'              => self::schalter(self::AKTIV),
            'wirksam'            => self::aktiv(),
            'eingerichtet'       => self::eingerichtet(),
            'name'               => self::name(),
            'aussteller'         => self::aussteller(),
            'aussteller_warnung' => self::aussteller() === '' ? null : self::ausstellerWarnung(self::aussteller()),
            'client_id'          => self::clientId(),
            'secret'             => self::secretZustand(),
            'app_url_fehler'     => self::appUrlFehler(),
            'rueckruf'           => self::rueckrufAdresse(),
            'abmelde_rueckkehr'  => self::abmeldeRueckkehr(),
            'algs'               => $d['algs'] ?? [],
            'endpunkte'          => $d === null ? null : (string) parse_url($d['authorization_endpoint'], PHP_URL_HOST),
            'abmelden_angeboten' => $d !== null && $d['end_session_endpoint'] !== null,
            'discovery_alter'    => $cache['discovery_am'] === null ? null : max(0, $jetzt - $cache['discovery_am']),
            'jwks_alter'         => $cache['jwks_am'] === null ? null : max(0, $jetzt - $cache['jwks_am']),
            'kids'               => $cache['kids'],
            'geprueft_am'        => ctype_digit($geprueft) ? (int) $geprueft : null,
            'befund'             => $befund,
            'schalter'           => [
                'anlegen'           => self::schalter(self::ANLEGEN),
                'admins'            => self::schalter(self::ADMINS),
                'mfa_verwaltung'    => self::schalter(self::MFA),
                'nur_sso'           => self::schalter(self::NUR_SSO),
                'kunden'            => self::schalter(self::KUNDEN),
                'abmelde_rueckkehr' => self::schalter(self::RUECKKEHR),
            ],
            'scopes'             => self::scopes(),
            'gruppen_claim'      => self::gruppenClaim(),
            'pflichtgruppen'     => self::pflichtgruppen(),
            'inaktiv_tage'       => self::inaktivTage(),
            'sitzung_dauer'      => self::sitzungDauer(),
            'fremde_identitaeten'=> $fremde,
            // Aus config.php: wohin die Anfragen an den Anmeldedienst gehen
            'verbindung'         => self::verbindung() + ['fehler' => self::verbindungFehler()],
        ];
    }

    // ------------------------------------------------------------ Speichern

    /**
     * Verbindung speichern: Aussteller, Client-ID, Secret, Beschriftung.
     *
     * Secret: '' = unverändert, '-' = entfernen. Ändern sich Aussteller oder
     * Client-ID, sind die Zwischenspeicher und die letzte Prüfung weg, SSO ist
     * aus und laufende SSO-Sitzungen enden (Marke); ein bisheriges Secret gilt
     * dann nicht mehr und wird entfernt, wenn kein neues mitkommt.
     *
     * Eine neue Verbindung, während es Zuordnungen von Gruppen gibt, nur von
     * jemandem, der jede davon selbst anlegen dürfte (Konten::
     * zuordnungRechtFehler): Der andere Anmeldedienst vergäbe über sie Rollen
     * und Kundenzugänge – mit „SSO einrichten“ allein wären das Rechte, die
     * man nicht vergeben darf. Die Kommandozeile ($kommandozeile, root auf
     * dem Server) prüft das nicht.
     *
     * @return array{ok:bool,message:string,warnung?:bool}
     */
    public static function verbindungSpeichern(string $aussteller, string $clientId, #[\SensitiveParameter] string $secret, string $name,
        bool $kommandozeile = false): array
    {
        $aussteller = trim($aussteller);
        $clientId = trim($clientId);
        $secret = trim($secret);
        $name = trim($name);
        $fehler = self::ausstellerFehler($aussteller);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        if (!self::clientIdGueltig($clientId)) {
            return ['ok' => false, 'message' => 'Die Client-ID besteht aus 1 bis 255 sichtbaren Zeichen ohne Leerzeichen – bitte so einfügen, wie Authentik sie anzeigt.'];
        }
        $neuesSecret = $secret !== '' && $secret !== '-';
        if ($neuesSecret) {
            if (preg_match('/^[\x21-\x7e]{16,512}$/', $secret) !== 1) {
                return ['ok' => false, 'message' => 'Das Client-Secret besteht aus 16 bis 512 sichtbaren Zeichen ohne Leerzeichen – bitte so einfügen, wie Authentik es anzeigt.'];
            }
            $schluessel = Crypto::schluesselFehler();
            if ($schluessel !== null) {
                return ['ok' => false, 'message' => $schluessel];
            }
        }
        if ($name === '') {
            $name = self::VORGABE_NAME;
        }
        if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || mb_strlen($name) > 40) {
            return ['ok' => false, 'message' => 'Die Beschriftung hat 1 bis 40 Zeichen, ohne Steuerzeichen.'];
        }

        $hatteSecret = (string) Settings::get(Settings::GEHEIM_SSO_SECRET, '') !== '';
        $warAktiv = self::schalter(self::AKTIV);
        $neueVerbindung = $aussteller !== self::aussteller() || $clientId !== self::clientId();
        if ($neueVerbindung && !$kommandozeile) {
            $fehler = self::verbindungRechtFehler();
            if ($fehler !== null) {
                return ['ok' => false, 'message' => $fehler];
            }
        }
        Settings::put(self::AUSSTELLER, $aussteller);
        Settings::put(self::CLIENT_ID, $clientId);
        Settings::put(self::NAME, $name);

        $stand = 'unverändert';
        $warnung = false;
        $meldungen = ['Verbindung gespeichert.'];
        if ($neueVerbindung) {
            foreach ([self::DISCOVERY, self::DISCOVERY_AM, self::DISCOVERY_NEU_AM, self::JWKS, self::JWKS_AM, self::JWKS_NEU_AM, self::GEPRUEFT_AM, self::BEFUND] as $k) {
                Settings::forget($k);
            }
            Settings::put(self::AKTIV, '0');
            self::markeErneuern();
            if ($neuesSecret) {
                self::secretSetzen($secret);
                $stand = 'gesetzt';
            } else {
                if ($hatteSecret) {
                    self::secretEntfernen();
                    $stand = 'entfernt';
                }
                $warnung = true;
                $meldungen[] = $hatteSecret ? 'Für eine neue Verbindung bitte das Secret neu eingeben.' : 'Bitte noch das Client-Secret eintragen.';
            }
            if ($warAktiv) {
                $warnung = true;
                $meldungen[] = 'Die Anmeldung über SSO ist jetzt aus – nach „Verbindung prüfen“ wieder einschalten.';
            }
        } elseif ($secret === '-') {
            if ($hatteSecret) {
                self::secretEntfernen();
                Settings::put(self::AKTIV, '0');
                self::markeErneuern();
                $stand = 'entfernt';
                $warnung = true;
                $meldungen[] = 'Das Secret ist entfernt, die Anmeldung über SSO ist aus.';
            }
        } elseif ($neuesSecret) {
            self::secretSetzen($secret);
            self::markeErneuern();
            $stand = 'gesetzt';
            $meldungen[] = 'Das neue Secret gilt ab sofort; laufende SSO-Sitzungen enden.';
        }
        $hinweis = self::ausstellerWarnung($aussteller);
        if ($hinweis !== null) {
            $warnung = true;
            $meldungen[] = $hinweis;
        }
        Audit::log('einstellungen.sso', 'settings', '', ['aussteller' => $aussteller, 'client_id' => $clientId, 'secret' => $stand]);
        $fremde = 0;
        try {
            $fremde = (int) Database::scalar('SELECT COUNT(*) FROM sso_identitaeten WHERE aussteller <> ?', [$aussteller]);
        } catch (\Throwable) {
            // vor der Migration
        }
        if ($fremde > 0) {
            $warnung = true;
            $meldungen[] = $fremde . ' Verknüpfung(en) gehören zu einem anderen Aussteller – gehört er zur selben Authentik-Installation: '
                . 'runuser -u www-data -- php bin/power sso:aussteller-umstellen.';
        }
        return ['ok' => true, 'message' => implode(' ', $meldungen), 'warnung' => $warnung];
    }

    /**
     * Darf der Angemeldete die Verbindung zu einem anderen Anmeldedienst
     * umstellen? null = ja (keine Zuordnungen, oder er dürfte jede selbst
     * anlegen), sonst die Meldung mit der ersten Gruppe, die er nicht dürfte.
     * Zuordnungen auf eine Rolle, die es nicht mehr gibt, vergeben nichts.
     */
    public static function verbindungRechtFehler(): ?string
    {
        try {
            $alle = Database::select('SELECT z.gruppe, z.art, z.ziel_id FROM sso_zuordnungen z ORDER BY z.id');
        } catch (\Throwable) {
            return null;   // vor der Migration
        }
        foreach ($alle as $z) {
            $art = (string) $z['art'];
            $ziel = (int) $z['ziel_id'];
            if ($art === 'rolle' && Database::scalar('SELECT id FROM roles WHERE id = ?', [$ziel]) === false) {
                continue;
            }
            $f = Konten::zuordnungRechtFehler($art, $ziel);
            if ($f !== null) {
                return 'Zu einem anderen Anmeldedienst (Aussteller oder Client-ID) wechselt nur, wer jede der ' . count($alle)
                    . ' Zuordnungen von Gruppen selbst anlegen dürfte – sonst vergäbe der andere Anmeldedienst über sie Rollen bzw.'
                    . ' Kundenzugänge, die du nicht vergeben darfst. Gruppe „' . $z['gruppe'] . '“: ' . $f
                    . ' Erst die Zuordnungen entfernen oder die Verbindung von jemandem mit diesen Rechten ändern lassen.';
            }
        }
        return null;
    }

    /**
     * Optionen der Anmeldung: scopes, gruppen_claim, pflichtgruppen (Liste
     * oder eine je Zeile), anlegen, abmelde_rueckkehr, inaktiv_tage. Was in
     * $o fehlt, bleibt. Erst wird alles geprüft, dann geschrieben.
     *
     * @return array{ok:bool,message:string}
     */
    public static function optionenSpeichern(array $o): array
    {
        $setzen = [];
        if (array_key_exists('scopes', $o)) {
            $liste = is_string($o['scopes']) ? self::scopeListe($o['scopes']) : null;
            if ($liste === null) {
                return ['ok' => false, 'message' => 'Scopes: höchstens ' . self::MAX_SCOPES . ', durch Leerzeichen getrennt, je 1–64 Zeichen aus Buchstaben, Ziffern und _ : . / -; „openid“ muss dabei sein, „offline_access“ nie.'];
            }
            $setzen[self::SCOPES] = implode(' ', $liste);
        }
        if (array_key_exists('gruppen_claim', $o)) {
            if (!is_string($o['gruppen_claim']) || !in_array($o['gruppen_claim'], self::GRUPPEN_CLAIMS, true)) {
                return ['ok' => false, 'message' => 'Der Gruppen-Claim ist einer aus ' . implode(', ', self::GRUPPEN_CLAIMS) . '.'];
            }
            $setzen[self::GRUPPEN_CLAIM] = $o['gruppen_claim'];
        }
        if (array_key_exists('pflichtgruppen', $o)) {
            $roh = is_string($o['pflichtgruppen']) ? preg_split('/\R/u', $o['pflichtgruppen']) : $o['pflichtgruppen'];
            if (!is_array($roh)) {
                return ['ok' => false, 'message' => 'Pflichtgruppen: eine Gruppe je Zeile.'];
            }
            $gruppen = [];
            foreach ($roh as $g) {
                $g = is_string($g) ? trim($g) : null;
                if ($g === '') {
                    continue;
                }
                if ($g === null || preg_match('//u', $g) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $g) === 1 || mb_strlen($g) > 150) {
                    return ['ok' => false, 'message' => 'Pflichtgruppen: je Gruppe 1 bis 150 Zeichen, ohne Steuerzeichen.'];
                }
                if (!in_array($g, $gruppen, true)) {
                    $gruppen[] = $g;
                }
            }
            if (count($gruppen) > self::MAX_PFLICHTGRUPPEN) {
                return ['ok' => false, 'message' => 'Höchstens ' . self::MAX_PFLICHTGRUPPEN . ' Pflichtgruppen.'];
            }
            $setzen[self::PFLICHTGRUPPEN] = (string) json_encode($gruppen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        foreach (self::OPTIONEN_SCHALTER as $feld => $schluessel) {
            if (array_key_exists($feld, $o)) {
                $setzen[$schluessel] = self::wahr($o[$feld]) ? '1' : '0';
            }
        }
        if (array_key_exists('inaktiv_tage', $o)) {
            $tage = is_int($o['inaktiv_tage']) ? (string) $o['inaktiv_tage'] : (is_string($o['inaktiv_tage']) ? trim($o['inaktiv_tage']) : '');
            if ($tage === '') {
                $tage = '0';
            }
            if (!ctype_digit($tage) || (int) $tage > self::MAX_INAKTIV_TAGE) {
                return ['ok' => false, 'message' => 'Tage ohne Anmeldung: eine ganze Zahl von 0 (nie) bis ' . self::MAX_INAKTIV_TAGE . '.'];
            }
            $setzen[self::INAKTIV_TAGE] = (string) (int) $tage;
        }
        foreach ($setzen as $schluessel => $wert) {
            Settings::put($schluessel, $wert);
        }
        Audit::log('einstellungen.sso', 'settings', '', ['optionen' => [
            'scopes'            => self::scopes(),
            'gruppen_claim'     => self::gruppenClaim(),
            'pflichtgruppen'    => count(self::pflichtgruppen()),
            'anlegen'           => self::schalter(self::ANLEGEN),
            'abmelde_rueckkehr' => self::schalter(self::RUECKKEHR),
            'inaktiv_tage'      => self::inaktivTage(),
        ]]);
        return ['ok' => true, 'message' => 'Optionen der Anmeldung über SSO gespeichert.'];
    }

    /**
     * Der Schutz: Verwaltung per SSO (`admins`), MFA-Pflicht für
     * Verwaltungskonten (`mfa_verwaltung`), „nur SSO“ (`nur_sso`) – nur mit
     * allen Rechten („*“; der Controller prüft es, hier noch einmal).
     *
     * „Nur SSO“ einschalten nur mit allen Vorbedingungen (§6.12,
     * Schutz::nurSsoVorbedingungen) und dem eingetippten NUR_SSO_EINTIPPEN;
     * ausschalten geht immer. Solange „nur SSO“ an ist, bleibt „Verwaltung per
     * SSO“ an – verknüpfte Verwaltungskonten kämen sonst nur noch über den
     * Notzugang herein. Die MFA-Pflicht schaltet eine Sitzung über SSO ohne
     * zweiten Faktor nicht ein – sie endete damit sofort, und ob der
     * Anmeldedienst den zweiten Faktor überhaupt meldet (amr), wäre ungeprüft.
     *
     * `admins` aus löscht sofort die Rollen aus Gruppen, die Verwaltungsrechte
     * tragen oder Systemrolle sind (W19); laufende SSO-Sitzungen von
     * Verwaltungskonten enden über Schutz::sitzungGilt.
     *
     * @param array{admins?:mixed, mfa_verwaltung?:mixed, nur_sso?:mixed} $o fehlende Schlüssel bleiben, wie sie sind
     * @return array{ok:bool,message:string}
     */
    public static function schutzSpeichern(array $o, string $bestaetigung): array
    {
        if (!Auth::can('*')) {
            return ['ok' => false, 'message' => 'Verwaltung per SSO, MFA und „nur SSO“ ändert nur, wer alle Rechte hat.'];
        }
        $schluessel = ['admins' => self::ADMINS, 'mfa_verwaltung' => self::MFA, 'nur_sso' => self::NUR_SSO];
        $vorher = array_map([self::class, 'schalter'], $schluessel);
        $neu = $vorher;
        foreach (array_keys($schluessel) as $feld) {
            if (array_key_exists($feld, $o)) {
                $neu[$feld] = self::wahr($o[$feld]);
            }
        }
        if ($neu === $vorher) {
            return ['ok' => true, 'message' => 'Am Schutz hat sich nichts geändert.'];
        }
        $name = self::name();
        if ($neu['nur_sso'] && !$neu['admins']) {
            return ['ok' => false, 'message' => 'Solange „nur SSO“ an ist, bleibt „Verwaltung per SSO“ an – verknüpfte Verwaltungskonten kämen sonst nur noch über den Notzugang herein. Erst „nur SSO“ ausschalten.'];
        }
        $sso = (array) Session::get('sso', []);
        if ($neu['mfa_verwaltung'] && !$vorher['mfa_verwaltung'] && Auth::anmeldeweg() === 'sso' && empty($sso['mfa'])) {
            return ['ok' => false, 'message' => 'Diese Sitzung ist über ' . $name . ' ohne zweiten Faktor angemeldet – mit der MFA-Pflicht endete sie sofort. Erst bei ' . $name . ' mit zweitem Faktor anmelden (oder hier mit Kennwort), dann einschalten.'];
        }
        if ($neu['nur_sso'] && !$vorher['nur_sso']) {
            foreach (Schutz::nurSsoVorbedingungen($neu['admins']) as $b) {
                if (!$b['ok']) {
                    return ['ok' => false, 'message' => '„Nur SSO“ bleibt aus: ' . $b['fehlt']];
                }
            }
            $getippt = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $bestaetigung)));
            if ($getippt !== mb_strtolower(self::NUR_SSO_EINTIPPEN)) {
                return ['ok' => false, 'message' => '„Nur SSO“ bleibt aus: zur Bestätigung „' . self::NUR_SSO_EINTIPPEN . '“ eintippen.'];
            }
        }
        foreach ($schluessel as $feld => $s) {
            if ($neu[$feld] !== $vorher[$feld]) {
                Settings::put($s, $neu[$feld] ? '1' : '0');
            }
        }
        $details = ['schalter' => array_filter($neu, static fn (bool $w, string $k): bool => $w !== $vorher[$k], ARRAY_FILTER_USE_BOTH)];
        $meldung = ['Schutz der Anmeldung über ' . $name . ' gespeichert.'];
        if ($vorher['admins'] && !$neu['admins']) {
            $rollen = Schutz::verwaltendeRollen();
            $weg = $rollen === [] ? 0 : Database::execute("DELETE FROM user_roles WHERE quelle = 'sso' AND role_id IN ("
                . implode(',', array_fill(0, count($rollen), '?')) . ')', $rollen);
            Zugriff::vergessen();
            Auth::rechteVergessen();
            $details['sso_rollen'] = $weg;
            $meldung[] = 'Verwaltungskonten melden sich wieder nur mit Kennwort an; ihre Sitzungen über ' . $name . ' enden'
                . ($weg > 0 ? ', ' . $weg . ' verwaltende Rolle(n) aus Gruppen sind entfernt' : '') . '.';
        }
        if ($neu['nur_sso'] && !$vorher['nur_sso']) {
            $meldung[] = 'Verknüpfte Konten (' . Schutz::nurSsoWarnungen()['verknuepft'] . ') melden sich ab jetzt nur noch über ' . $name
                . ' an; ihre laufenden Kennwort-Sitzungen enden.';
        }
        Audit::log('einstellungen.sso', 'settings', '', $details);
        return ['ok' => true, 'message' => implode(' ', $meldung)];
    }

    /**
     * Kundenzugänge über Gruppen (`sso.kunden`, Vorgabe aus) – nur mit dem
     * Kundenbereich und nur, wer Kunden pflegen darf (kunden.manage; der
     * Controller prüft es, hier noch einmal).
     *
     * An: Wer beim Anmeldedienst in genau einer Kundengruppe ist (und in
     * keiner Rollen-Gruppe), bekommt bei der ersten Anmeldung einen
     * Kundenzugang dieses Kunden (Konten::aufloesen). Stehen schon
     * Kundengruppen da (von einem früheren „an“), gelten sie damit wieder –
     * das darf nur, wer die Zugänge jedes dieser Kunden auch selbst ausgeben
     * dürfte (KundenService::darfZugangAusgeben).
     *
     * Aus: Die Kundengruppen bleiben stehen, wirken aber nicht; laufende
     * SSO-Sitzungen von Kundenzugängen enden bei ihrer nächsten Anfrage
     * (Schutz::sitzungGilt → darfPerSso „kunden_aus“), ihre nächste Anmeldung
     * über SSO wird abgelehnt.
     *
     * @return array{ok:bool,message:string}
     */
    public static function kundenSchalten(bool $an): array
    {
        if (!Kundenbereich::verfuegbar()) {
            return ['ok' => false, 'message' => 'Ohne Kundenbereich gibt es keine Kundenzugänge über Gruppen – Kunden dann über eine kundeneigene Rolle zuordnen.'];
        }
        if (!Auth::can('kunden.manage')) {
            return ['ok' => false, 'message' => 'Kundenzugänge über Gruppen schaltet nur, wer Kunden pflegen darf.'];
        }
        $name = self::name();
        if ($an === self::schalter(self::KUNDEN)) {
            return ['ok' => true, 'message' => 'Kundenzugänge über ' . $name . ' sind schon ' . ($an ? 'an' : 'aus') . '.'];
        }
        if ($an) {
            foreach (Konten::kundengruppen() as $z) {
                if (!Kundenbereich::darfZugangAusgeben((int) $z['ziel_id'])) {
                    return ['ok' => false, 'message' => 'Es stehen noch Kundengruppen für Kunden da, deren Zugänge du nicht ausgeben darfst (Gruppe „'
                        . $z['gruppe'] . '“) – erst entfernen lassen oder von jemandem einschalten lassen, der alles sieht, was diese Kunden sehen.'];
                }
            }
        }
        Settings::put(self::KUNDEN, $an ? '1' : '0');
        Audit::log('einstellungen.sso', 'settings', '', ['schalter' => ['kunden' => $an]]);
        return ['ok' => true, 'message' => $an
            ? 'Kundenzugänge über ' . $name . ' sind an: Wer in einer Kundengruppe ist, bekommt bei der ersten Anmeldung einen Zugang dieses Kunden.'
            : 'Kundenzugänge über ' . $name . ' sind aus – ihre Sitzungen über ' . $name . ' enden; Kundenzugänge mit Kennwort bleiben, wie sie sind.'];
    }

    /** War die letzte „Verbindung prüfen“ (auch beim Einschalten) ganz erfolgreich? */
    public static function letztePruefungOk(): bool
    {
        if (!ctype_digit((string) Settings::get(self::GEPRUEFT_AM, ''))) {
            return false;
        }
        $befund = json_decode((string) Settings::get(self::BEFUND, '[]'), true);
        if (!is_array($befund) || $befund === []) {
            return false;
        }
        foreach ($befund as $b) {
            if (!is_array($b) || ($b['ok'] ?? null) !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * Frisch prüfen (Anbieter::pruefen) und nur bei Erfolg einschalten.
     *
     * @return array{ok:bool,message:string,befunde?:list<array{ok:bool,text:string}>}
     */
    public static function einschalten(): array
    {
        $fehler = self::appUrlFehler();
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        if (!self::eingerichtet()) {
            return ['ok' => false, 'message' => 'Erst Aussteller, Client-ID und Secret eintragen, dann einschalten.'];
        }
        $p = Anbieter::pruefen();
        if (!$p['ok']) {
            $erster = '';
            foreach ($p['befunde'] as $b) {
                if (!$b['ok']) {
                    $erster = $b['text'];
                    break;
                }
            }
            return ['ok' => false, 'message' => 'Nicht eingeschaltet – die Prüfung ist fehlgeschlagen: ' . $erster, 'befunde' => $p['befunde']];
        }
        // Geprüft wurde mit Warten auf den Anmeldedienst. Hat inzwischen ein
        // Update begonnen, bleibt es aus – die Sicherung kennte das Einschalten
        // nicht (auch für sso:an, das sich nicht wie ein POST anmeldet).
        if (UpdateService::wartungAktiv()) {
            return ['ok' => false, 'message' => 'Gerade läuft ein Update – nicht eingeschaltet. Bitte in etwa einer Minute noch einmal.', 'befunde' => $p['befunde']];
        }
        Settings::put(self::AKTIV, '1');
        Audit::log('einstellungen.sso', 'settings', '', ['aktiv' => true]);
        return ['ok' => true, 'message' => 'Die Anmeldung über ' . self::name() . ' ist eingeschaltet.', 'befunde' => $p['befunde']];
    }

    /**
     * Aus: Marke neu (laufende SSO-Sitzungen enden), sso.nur_sso = 0, alle
     * quelle='sso'-Rollen weg. $nurNurSso: nur „nur SSO“ aus (Notausgang der
     * Kommandozeile) – SSO selbst bleibt an.
     *
     * @return array{ok:bool,message:string}
     */
    public static function ausschalten(bool $nurNurSso = false): array
    {
        if ($nurNurSso) {
            Settings::put(self::NUR_SSO, '0');
            Audit::log('einstellungen.sso', 'settings', '', ['schalter' => ['nur_sso' => false]]);
            return ['ok' => true, 'message' => '„Nur SSO“ ist aus – verknüpfte Konten melden sich wieder auch mit Kennwort an.'];
        }
        Settings::put(self::AKTIV, '0');
        Settings::put(self::NUR_SSO, '0');
        self::markeErneuern();
        $rollen = Database::execute("DELETE FROM user_roles WHERE quelle = 'sso'");
        Zugriff::vergessen();
        Auth::rechteVergessen();
        Audit::log('einstellungen.sso', 'settings', '', ['aktiv' => false, 'sso_rollen' => $rollen]);
        return ['ok' => true, 'message' => 'Die Anmeldung über ' . self::name() . ' ist aus; laufende SSO-Sitzungen enden, Rollen aus Gruppen sind entfernt.'];
    }

    /**
     * Http-Optionen aus config.php für jede Anfrage an den Anmeldedienst:
     * `sso.verbinden_ueber` (curl CONNECT_TO – ohne Hairpin-NAT an eine
     * interne Adresse, Name, SNI und Zertifikat bleiben die des Ausstellers)
     * und `sso.ca_datei` (eigene CA). Nur gültige Werte – was nicht taugt,
     * nennt verbindungFehler(), und „Verbindung prüfen“ scheitert daran.
     *
     * @return array{verbinden_ueber?:string, ca_datei?:string}
     */
    public static function verbindung(): array
    {
        $o = [];
        $ueber = self::configText('sso.verbinden_ueber');
        if ($ueber !== '' && self::verbindenUeberFehler($ueber) === null) {
            $o['verbinden_ueber'] = $ueber;
        }
        $ca = self::configText('sso.ca_datei');
        if ($ca !== '' && self::caDateiFehler($ca) === null) {
            $o['ca_datei'] = $ca;
        }
        return $o;
    }

    /**
     * Was an `sso.verbinden_ueber` und `sso.ca_datei` in config.php nicht
     * taugt – leer, wenn alles passt (oder nichts eingetragen ist).
     *
     * @return list<string>
     */
    public static function verbindungFehler(): array
    {
        $fehler = [];
        foreach (['verbinden_ueber' => [self::class, 'verbindenUeberFehler'], 'ca_datei' => [self::class, 'caDateiFehler']] as $name => $pruefen) {
            $roh = Config::get('sso.' . $name);
            if ($roh === null || $roh === '') {
                continue;
            }
            $f = is_string($roh) ? $pruefen(trim($roh)) : 'muss ein Text sein';
            if ($f !== null) {
                $fehler[] = 'sso.' . $name . ' in config/config.php: ' . $f;
            }
        }
        return $fehler;
    }

    /**
     * „host:port:ziel:zielport“ – host und port müssen die des Ausstellers
     * sein (sonst wendet curl es nie an), ziel ein Name, eine IPv4- oder
     * eine [IPv6]-Adresse. null = taugt.
     */
    private static function verbindenUeberFehler(string $wert): ?string
    {
        if (preg_match('/^([A-Za-z0-9.-]{1,253}):(\d{1,5}):([A-Za-z0-9.-]{1,253}|\[[0-9A-Fa-f:.]{2,45}\]):(\d{1,5})$/', $wert, $m) !== 1
            || (int) $m[2] < 1 || (int) $m[2] > 65535 || (int) $m[4] < 1 || (int) $m[4] > 65535) {
            return 'erwartet „host:port:ziel:zielport“, etwa auth.example.com:443:10.20.0.20:443';
        }
        $soll = self::herkunft(self::aussteller());
        if ($soll !== null) {
            $host = (string) parse_url($soll, PHP_URL_HOST);
            $port = (string) parse_url($soll, PHP_URL_PORT);
            if (strcasecmp($m[1], $host) !== 0 || $m[2] !== $port) {
                return 'beginnt nicht mit Host und Port des Ausstellers (' . $host . ':' . $port . ') – so wirkt es nie';
            }
        }
        return null;
    }

    /** Eine lesbare PEM-Datei mit absolutem Pfad – null = taugt. */
    private static function caDateiFehler(string $pfad): ?string
    {
        if (!str_starts_with($pfad, '/') || preg_match('/[\x00-\x1F\x7F]/', $pfad) === 1) {
            return 'braucht einen absoluten Pfad';
        }
        // Unter PHP-FPM gilt open_basedir: dann nur im Programmordner (etwa config/).
        if (!@is_file($pfad) || !@is_readable($pfad)) {
            return 'Datei fehlt oder ist nicht lesbar (unter PHP-FPM nur innerhalb des Programmordners, etwa config/)';
        }
        $inhalt = (string) @file_get_contents($pfad, false, null, 0, 1048576);
        if (!str_contains($inhalt, '-----BEGIN CERTIFICATE-----')) {
            return 'enthält kein Zertifikat im PEM-Format';
        }
        return null;
    }

    private static function configText(string $schluessel): string
    {
        $wert = Config::get($schluessel);
        return is_string($wert) ? trim($wert) : '';
    }

    // ------------------------------------------------------------ intern

    private static function clientIdGueltig(string $clientId): bool
    {
        return preg_match('/^[\x21-\x7e]{1,255}$/', $clientId) === 1;
    }

    private static function secretPasst(): bool
    {
        return hash_equals(self::secretFuer(self::aussteller(), self::clientId()), (string) Settings::get(self::SECRET_FUER, ''));
    }

    private static function secretFuer(string $aussteller, string $clientId): string
    {
        return hash('sha256', $aussteller . "\n" . $clientId);
    }

    private static function secretSetzen(#[\SensitiveParameter] string $secret): void
    {
        Settings::geheimnisSetzen(Settings::GEHEIM_SSO_SECRET, $secret);
        Settings::put(self::SECRET_FUER, self::secretFuer(self::aussteller(), self::clientId()));
    }

    private static function secretEntfernen(): void
    {
        Settings::forget(Settings::GEHEIM_SSO_SECRET);
        Settings::forget(self::SECRET_FUER);
    }

    /** app.url ohne „/“ am Ende. */
    private static function appUrl(): string
    {
        return rtrim(trim((string) Config::get('app.url', '')), '/');
    }

    /**
     * Gemeinsame Prüfung für app.url, Aussteller und Endpunkte: null = taugt.
     * $mitQuery: eine Query ist erlaubt (Endpunkte), sonst nicht.
     */
    private static function adresseFehler(string $url, bool $mitQuery): ?string
    {
        if (preg_match('/^[\x21-\x7e]+$/', $url) !== 1) {
            return 'ohne Leer- und Steuerzeichen';
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || $p['host'] === '') {
            return 'keine vollständige Adresse';
        }
        $lokal = in_array(strtolower($p['host']), ['127.0.0.1', 'localhost', '[::1]'], true);
        if ($p['scheme'] !== 'https' && !($p['scheme'] === 'http' && $lokal && Config::get('sso.lokal_http') === true)) {
            return 'nur https://';
        }
        if (!str_starts_with($url, $p['scheme'] . '://')) {
            return 'keine vollständige Adresse';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'ohne Benutzer und Kennwort in der Adresse';
        }
        if (isset($p['fragment']) || str_contains($url, '#')) {
            return 'ohne #';
        }
        if (!$mitQuery && (isset($p['query']) || str_contains($url, '?'))) {
            return 'ohne ?';
        }
        return null;
    }

    /** @return list<string>|null */
    private static function scopeListe(string $text): ?array
    {
        $liste = [];
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $s) {
            if ($s === '') {
                continue;
            }
            if (preg_match('#^[A-Za-z0-9_:./-]{1,64}$#', $s) !== 1 || $s === 'offline_access') {
                return null;
            }
            if (!in_array($s, $liste, true)) {
                $liste[] = $s;
            }
        }
        if (count($liste) > self::MAX_SCOPES || !in_array('openid', $liste, true)) {
            return null;
        }
        return $liste;
    }

    private static function wahr(mixed $wert): bool
    {
        return $wert === true || $wert === 1 || $wert === '1' || $wert === 'on';
    }
}
