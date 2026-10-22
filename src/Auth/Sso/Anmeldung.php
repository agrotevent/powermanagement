<?php
declare(strict_types=1);

namespace App\Auth\Sso;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Berichte\Kundenmodus;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Response;
use App\Support\Session;
use App\Support\Settings;
use App\Support\Zeit;

/**
 * Der Ablauf der Anmeldung über SSO: Vorgang starten, Rückruf prüfen,
 * Bestätigung (Step-up), Bremse.
 *
 * Authorization Code mit PKCE (S256), state und nonce, response_mode=query.
 * Ein Vorgang liegt in der Sitzung (höchstens fünf, je zehn Minuten) und
 * ist an sie gebunden: Eine Rückruf-Adresse aus einem anderen Browser findet
 * ihren Vorgang nicht (Login-CSRF). Der Vorgang wird beim Rückruf sofort
 * entfernt – vor jedem weiteren Schritt, auch vor dem Netz –, gilt also
 * genau einmal.
 *
 * Gewartet wird nie mit gesperrter Sitzung (Discovery, Token-Endpunkt,
 * JWKS, Userinfo – je bis 8 s): Die Sitzung wird dafür geschlossen und
 * danach wieder geöffnet (Session::ohneSperre). Hat sich in der Zwischenzeit
 * jemand in einem anderen Reiter an- oder abgemeldet, ist sie danach zu –
 * dann wird nichts geschrieben, auch kein Konto (Code `sitzung`). Danach
 * werden Einrichtung (frisch aus der Datenbank, nicht aus dem
 * Zwischenspeicher dieser Anfrage) und Sitzung noch einmal geprüft: Wer
 * während des Wartens SSO ausschaltet oder die Verbindung ändert, bekommt
 * keine Rolle und kein Konto mehr zurück (Code `einrichtung`).
 *
 * Zwecke: anmelden; verbinden (Profil, angemeldet mit Kennwort);
 * kundenmodus:<Bericht> und token:<Zugang> (Bestätigung mit frischer
 * Anmeldung beim Anmeldedienst, dieselbe Identität, Schranke wie beim
 * Anmelden).
 *
 * Vor einem gültigen state wird weder ins Audit noch für die Bremse
 * geschrieben – nur ins Log; unangemeldete Abrufe füllen sonst das Audit.
 * Gezählt (auth.sso_fehlgeschlagen je Adresse) wird erst, wenn ein echter
 * Vorgang scheitert. Ins Log und ins Audit kommen nie Code, Tokens, Secret
 * oder rohe Claims.
 */
final class Anmeldung
{
    public const SITZUNG = 'sso_vorgaenge', COOKIE_NEU = 'pm_sso_neu';
    public const ZWECKE = '/^(anmelden|verbinden|kundenmodus:\d{1,10}|token:\d{1,10})$/';
    public const VORGANG_SEKUNDEN = 600, HOECHSTENS_VORGAENGE = 5, BESTAETIGT_SEKUNDEN = 300;
    /** Sitzung: ID-Token für das Abmelden beim Anmeldedienst (id_token_hint) – sonst nirgends. */
    public const HINWEIS = 'sso_hinweis';
    /** Sitzung: frische Bestätigung {zweck, user_id, am}. */
    public const BESTAETIGT = 'sso_bestaetigt';
    /**
     * Sitzung: In diesem Browser ist jemand beim Anmeldedienst angemeldet –
     * auch in einer Kennwort-Sitzung nach Bestätigen oder Verbinden. Beim
     * Abmelden hier gibt es dann prompt=login für die nächste SSO-Anmeldung
     * (pm_sso_neu) und den Link zum Abmelden dort (geteilte Tablets).
     */
    public const BEIM_ANBIETER = 'sso_idp';
    /** Sitzung: das Kennwort für „Verbinden“ eben bestätigt {user_id, am} – einmal, höchstens BESTAETIGT_SEKUNDEN. */
    public const VERBINDEN = 'sso_verbinden';

    public const T_STATE = 'Die Anmeldung ist abgelaufen oder wurde schon benutzt – bitte erneut anmelden.';
    public const T_INTERN = 'Die Anmeldung über SSO ist fehlgeschlagen – Details im Log.';
    public const T_SITZUNG = 'Während der Anmeldung hat sich in einem anderen Fenster jemand an- oder abgemeldet – bitte erneut anmelden.';
    public const T_NICHT_EINGERICHTET = 'Die Anmeldung über SSO ist nicht eingerichtet.';
    public const T_GEAENDERT = 'Die Anmeldung über SSO wurde inzwischen geändert oder ausgeschaltet – bitte erneut anmelden.';
    public const T_GEBREMST = 'Zu viele Fehlversuche – das Entsperren ist noch gebremst.';

    private const COOKIE_NEU_SEKUNDEN = 43200;
    /** Claims, die aus der Userinfo ergänzt werden dürfen – nie iss/sub/aud/azp/nonce/exp/iat/nbf/auth_time/amr/acr/sid. */
    private const PROFIL = ['preferred_username', 'name', 'given_name', 'family_name', 'nickname', 'email', 'email_verified'];

    // ------------------------------------------------------------ Start

    /**
     * Einen Vorgang in der Sitzung anlegen und die Adresse zum Anmeldedienst
     * zurückgeben. $neuAnmelden (nach dem Abmelden in diesem Browser) und
     * jeder Zweck außer „anmelden“ verlangen eine neue Anmeldung dort
     * (prompt=login); Bestätigungen und Verbinden dazu max_age=0.
     *
     * @throws SsoFehler nicht eingerichtet, Discovery, Sitzung
     */
    public static function starten(string $zweck, ?string $ziel, bool $neuAnmelden, ?int $userId = null): string
    {
        if (preg_match(self::ZWECKE, $zweck) !== 1) {
            throw new \LogicException('SSO: unbekannter Zweck');
        }
        if (!Einstellungen::aktiv()) {
            throw new SsoFehler('einrichtung', self::T_NICHT_EINGERICHTET);
        }
        $d = Anbieter::discoveryGespeichert() ?? self::ohneSitzungssperre(static fn (): array => Anbieter::discovery());
        $a = $d['authorization_endpoint'];
        if (!Einstellungen::gleicheHerkunft($a)) {
            throw new SsoFehler('discovery', 'Der Anmelde-Endpunkt liegt nicht beim Aussteller.');
        }
        $jetzt = Einstellungen::jetzt();
        $vorgaenge = self::vorgaenge($jetzt);
        while (count($vorgaenge) >= self::HOECHSTENS_VORGAENGE) {
            array_shift($vorgaenge);   // der älteste zuerst (Einfügereihenfolge)
        }
        $state = self::zufall();
        $nonce = self::zufall();
        $verifier = self::zufall();
        $vorgaenge[$state] = ['zweck' => $zweck, 'ziel' => $ziel, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => $jetzt,
            'marke' => Einstellungen::marke(), 'user_id' => $userId, 'neu' => $neuAnmelden];
        $_SESSION[self::SITZUNG] = $vorgaenge;
        $p = [
            'response_type'         => 'code',
            'client_id'             => Einstellungen::clientId(),
            'redirect_uri'          => Einstellungen::rueckrufAdresse(),
            'scope'                 => Einstellungen::scopes(),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',   // Authentiks Vorgabe wäre plain
            'response_mode'         => 'query',
        ];
        if ($neuAnmelden || $zweck !== 'anmelden') {
            $p['prompt'] = 'login';
        }
        if ($zweck !== 'anmelden') {
            $p['max_age'] = '0';   // Authentik übergeht 0, andere Anbieter verlangen damit auth_time
        }
        return $a . (str_contains($a, '?') ? '&' : '?') . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
    }

    // ------------------------------------------------------------ Rückruf

    /**
     * Der ganze Rückruf (§6.2) – Prüfkette in fester Reihenfolge, danach je
     * Zweck anmelden, verbinden oder bestätigen. Schreibt die Sitzung selbst
     * (Anmeldung, Bestätigung, Hinweis fürs Abmelden); der Controller zeigt
     * nur die Meldung und leitet auf `ziel` (immer ein eigener Pfad).
     *
     * $get: die Query des Rückrufs ($_GET – nie Request::input, das zuerst
     * $_POST liest). Arrays an Stelle von Strings zählen als fehlend.
     *
     * @return array{ok:bool, code:string, zweck:string, ziel:string, meldung:string, user?:array, angelegt?:bool}
     */
    public static function rueckruf(#[\SensitiveParameter] array $get, string $ip): array
    {
        $wert = static fn (string $k): ?string => is_string($get[$k] ?? null) ? $get[$k] : null;
        $jetzt = Einstellungen::jetzt();

        // 1. state: den Vorgang sofort entfernen (abgelaufene mit) – vor allem anderen.
        $state = $wert('state');
        $vorgaenge = self::vorgaenge($jetzt);
        $v = null;
        if ($state !== null && preg_match('/^[A-Za-z0-9_-]{43}$/', $state) === 1 && isset($vorgaenge[$state])) {
            $v = $vorgaenge[$state];
            unset($vorgaenge[$state]);
        }
        if ($vorgaenge === []) {
            unset($_SESSION[self::SITZUNG]);
        } else {
            $_SESSION[self::SITZUNG] = $vorgaenge;
        }
        if ($v === null) {
            Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => 'state']);
            return ['ok' => false, 'code' => 'state', 'zweck' => 'anmelden', 'ziel' => '/login', 'meldung' => self::T_STATE];
        }

        $claims = [];
        $gruppen = [];
        try {
            // 2. Bremse – ohne Anfrage an den Anmeldedienst, ohne Zählen.
            $gebremst = self::gebremst($ip);
            if ($gebremst !== null) {
                Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => 'gebremst']);
                return ['ok' => false, 'code' => 'gebremst', 'zweck' => self::basis($v), 'ziel' => self::fehlerZiel($v), 'meldung' => $gebremst];
            }
            // 3. Einrichtung unverändert.
            self::einrichtungPruefen($v);
            // 4. Zweck passt zur Sitzung.
            self::zweckPruefen($v);
            // 5. iss im Rückruf (RFC 9207) – Authentik schickt ihn nicht.
            $iss = $wert('iss');
            if ($iss !== null && !hash_equals(Einstellungen::aussteller(), $iss)) {
                throw new SsoFehler('iss', IdToken::T_ISS);
            }
            // 6. Fehler des Anmeldedienstes.
            $fehler = $wert('error');
            if ($fehler !== null) {
                throw self::abgebrochen($fehler, $wert('error_description'));
            }
            // 7. Code.
            $code = $wert('code');
            if ($code === null || preg_match('/^[\x21-\x7e]{1,2048}$/', $code) !== 1) {
                throw new SsoFehler('code', 'Der Anmeldedienst hat keinen gültigen Anmeldecode geschickt – bitte erneut anmelden.');
            }
            // 8.–10. Einlösen, ID-Token, Userinfo – ohne Sitzungssperre.
            [$claims, $idToken] = self::ohneSitzungssperre(static fn (): array => self::tokenHolen($code, $v));
            // Gewartet (bis zu 8 s je Anfrage): Was andere Anfragen inzwischen
            // geändert haben, gilt – SSO aus, eine neue Verbindung (Marke),
            // „Verwaltung per SSO“ aus, das Konto deaktiviert. Die Zwischenspeicher
            // dieser Anfrage kennen nur den Stand von vorher; also frisch lesen
            // und Schritt 3 und 4 noch einmal, bevor irgendetwas geschrieben wird.
            Settings::vergessen();
            Auth::vergessen();
            Zugriff::vergessen();
            self::einrichtungPruefen($v);
            self::zweckPruefen($v);
            // 11. Gruppen.
            $gruppen = Konten::gruppen($claims);
            $name = Konten::nameDort($claims);
            Audit::akteur('sso' . ($name !== '' ? ':' . $name : ''));
            // 12. Nach Zweck.
            return match (self::basis($v)) {
                'anmelden'  => self::anmelden($v, $claims, $gruppen, $idToken, $ip),
                'verbinden' => self::verbinden($claims, $gruppen, $idToken),
                default     => self::bestaetigen($v, $claims, $idToken),
            };
        } catch (SsoFehler $e) {
            return self::fehlgeschlagen($e, $v, $ip, $claims, $gruppen);
        } catch (\Throwable $e) {
            Logger::error('sso', ['fehler' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300)]);
            return self::fehlgeschlagen(new SsoFehler('intern', self::T_INTERN), $v, $ip, $claims, $gruppen);
        } finally {
            Audit::akteur(null);
        }
    }

    // ------------------------------------------------------------ Bestätigung, Bremse, Ziel, Cookie

    /**
     * Gibt es eine frische Bestätigung (Step-up) genau dieses Zwecks für das
     * angemeldete Konto – höchstens BESTAETIGT_SEKUNDEN alt? $verbrauchen:
     * einmalig (danach weg).
     */
    public static function bestaetigt(string $zweck, bool $verbrauchen = true): bool
    {
        $b = Session::get(self::BESTAETIGT);
        $id = Auth::id();
        $jetzt = Einstellungen::jetzt();
        $am = is_array($b) ? (int) ($b['am'] ?? 0) : 0;
        $ok = is_array($b) && $id !== null && ($b['zweck'] ?? null) === $zweck && (int) ($b['user_id'] ?? 0) === $id
            && $jetzt >= $am && $jetzt - $am <= self::BESTAETIGT_SEKUNDEN;
        if ($verbrauchen && is_array($b) && ($b['zweck'] ?? null) === $zweck) {
            Session::forget(self::BESTAETIGT);
        }
        return $ok;
    }

    /**
     * Meldung, wenn diese Adresse gebremst ist – so viele gescheiterte
     * SSO-Vorgänge im Fenster `security.lockout_minutes` wie die Kennwort-
     * Bremse erlaubt (`security.ip_attempts`, mindestens `lockout_attempts`),
     * mit eigenem Zähler (auth.sso_fehlgeschlagen). Sonst null.
     */
    public static function gebremst(string $ip): ?string
    {
        $fenster = 60 * max(1, (int) Config::get('security.lockout_minutes', 15));
        $grenze = max(max(1, (int) Config::get('security.lockout_attempts', 5)), (int) Config::get('security.ip_attempts', 20));
        $jetzt = Einstellungen::jetzt();
        $zeiten = array_column(Database::select(
            "SELECT created_at FROM audit_log WHERE action = 'auth.sso_fehlgeschlagen' AND ip = ? AND created_at > ? ORDER BY created_at",
            [$ip, gmdate('c', $jetzt - $fenster)]
        ), 'created_at');
        if (count($zeiten) < $grenze) {
            return null;
        }
        $min = max(1, (int) ceil(((int) Zeit::ts((string) $zeiten[0]) + $fenster - $jetzt) / 60));
        return "Zu viele fehlgeschlagene Anmeldungen über SSO von dieser Adresse. Bitte in {$min} Minute(n) erneut versuchen.";
    }

    /**
     * Ein sicheres Ziel nach der Anmeldung – nur ein Pfad dieser Seite
     * (Response::sichererPfad), nie die Anmeldung selbst, das Abmelden oder
     * /abgemeldet; sonst null.
     */
    public static function zielNach(mixed $ziel): ?string
    {
        // Auch kein Zeilenumbruch am Ende (sichererPfad prüft mit „$“).
        if (!is_string($ziel) || $ziel === '' || strlen($ziel) > 2000 || preg_match('/[\x00-\x20\x7f]/', $ziel) === 1) {
            return null;
        }
        $pfad = Response::sichererPfad($ziel);
        if ($pfad === null) {
            return null;
        }
        $nur = '/' . trim((string) strtok($pfad, '?#'), '/');
        foreach (['/login', '/logout', '/abgemeldet'] as $verboten) {
            if ($nur === $verboten || str_starts_with($nur, $verboten . '/')) {
                return null;
            }
        }
        return $pfad;
    }

    /**
     * Darf das angemeldete Konto sich jetzt im Profil mit dem Anmeldedienst
     * verbinden? null = ja, sonst die Meldung. Nur in einer Sitzung mit
     * Kennwort (nicht über SSO, nie als Notzugang – ein Notzugang wird nie
     * verknüpft), nie ein Kundenzugang, nur ohne Identität beim jetzigen
     * Aussteller und nicht der letzte Notzugang bei „nur SSO“. Für den Start
     * (SsoController::verbinden) und den Link im Profil.
     */
    public static function verbindenFehler(): ?string
    {
        $user = Auth::user();
        if ($user === null) {
            return 'Bitte zuerst anmelden.';
        }
        if (!Einstellungen::aktiv()) {
            return self::T_NICHT_EINGERICHTET;
        }
        $weg = Auth::anmeldeweg();
        if ($weg === 'notzugang') {
            return 'Ein Notzugang wird nie mit dem Anmeldedienst verknüpft.';
        }
        if ($weg !== 'kennwort') {
            return 'Verbinden geht nur in einer Sitzung, die mit Kennwort angemeldet ist.';
        }
        if (Kundenbereich::istKundenzugang($user)) {
            return Konten::T_KUNDE_NIE;
        }
        if (Konten::identitaet((int) $user['id']) !== null) {
            return 'Dieses Konto ist schon mit einer Anmeldung bei ' . Einstellungen::name() . ' verknüpft.';
        }
        if (Schutz::letzterNotzugang((int) $user['id'])) {
            return Schutz::T_LETZTER_NOTZUGANG;
        }
        return null;
    }

    /**
     * Verbinden vorbereiten (POST im Profil): das Kennwort des angemeldeten
     * Kontos noch einmal. Eine neue Art der Anmeldung an ein Konto zu hängen
     * ist mindestens so heikel wie ein Token anzuzeigen – sonst hängte, wer
     * eine offene Kennwort-Sitzung hat (Tablet, gestohlenes Cookie), seine
     * eigene Identität beim Anmeldedienst dauerhaft an fremde Konten; die
     * Verknüpfung überlebte jeden Kennwortwechsel. prompt=login beweist nur,
     * dass sich dort irgendwer frisch angemeldet hat.
     *
     * Gelingt es, merkt sich die Sitzung das (VERBINDEN, höchstens
     * BESTAETIGT_SEKUNDEN, nur dieses Konto); der Start (GET
     * /login/sso/verbinden, ein Link wegen CSP form-action) verbraucht es.
     * Ein falsches Kennwort zählt nicht gegen das Konto (wie
     * Auth::bestaetigen), steht aber im Audit und bremst das Verbinden
     * dieses Kontos nach `security.lockout_attempts` Fehlversuchen für
     * `security.lockout_minutes`. null = vorbereitet, sonst die Meldung.
     */
    public static function verbindenVorbereiten(#[\SensitiveParameter] string $kennwort): ?string
    {
        $fehler = self::verbindenFehler();
        if ($fehler !== null) {
            return $fehler;
        }
        $user = (array) Auth::user();
        $id = (int) $user['id'];
        // Die Bremse rechnet wie die der Kennwort-Anmeldung mit der Uhr des Servers (die Zeiten im Audit).
        $fenster = 60 * max(1, (int) Config::get('security.lockout_minutes', 15));
        $versuche = array_column(Database::select(
            "SELECT created_at FROM audit_log WHERE action = 'auth.bestaetigung_fehlversuch' AND target_type = 'user' AND target_id = ?
             AND details LIKE ? AND created_at > ? ORDER BY created_at",
            [(string) $id, '%"zweck":"verbinden"%', gmdate('c', time() - $fenster)]
        ), 'created_at');
        if (count($versuche) >= max(1, (int) Config::get('security.lockout_attempts', 5))) {
            $min = max(1, (int) ceil(((int) Zeit::ts((string) $versuche[0]) + $fenster - time()) / 60));
            return "Zu viele falsche Kennwörter – Verbinden geht in {$min} Minute(n) wieder.";
        }
        if ($kennwort === '' || !password_verify($kennwort, (string) ($user['password_hash'] ?? ''))) {
            Audit::log('auth.bestaetigung_fehlversuch', 'user', (string) $id, ['zweck' => 'verbinden']);
            return 'Das Kennwort stimmt nicht.';
        }
        Session::put(self::VERBINDEN, ['user_id' => $id, 'am' => Einstellungen::jetzt()]);
        return null;
    }

    /**
     * Hat das angemeldete Konto sein Kennwort für „Verbinden“ eben bestätigt
     * (höchstens BESTAETIGT_SEKUNDEN)? $verbrauchen: danach weg.
     */
    public static function verbindenBestaetigt(bool $verbrauchen = false): bool
    {
        $b = Session::get(self::VERBINDEN);
        $id = Auth::id();
        $jetzt = Einstellungen::jetzt();
        $am = is_array($b) ? (int) ($b['am'] ?? 0) : 0;
        $ok = is_array($b) && $id !== null && (int) ($b['user_id'] ?? 0) === $id && $jetzt >= $am && $jetzt - $am <= self::BESTAETIGT_SEKUNDEN;
        if ($verbrauchen || (is_array($b) && !$ok)) {
            Session::forget(self::VERBINDEN);
        }
        return $ok;
    }

    /**
     * Darf das angemeldete Konto diesen Zweck jetzt über den Anmeldedienst
     * bestätigen (Step-up, §6.8)? null = ja, sonst die Meldung.
     *
     * SSO an; das Konto hat eine Identität beim jetzigen Aussteller und darf
     * sich per SSO anmelden (Schutz::darfPerSso – W7: ein Verwaltungskonto
     * ohne „Verwaltung per SSO“ bestätigt auch nicht so, sonst sähe, wer die
     * Identität dort hat, hier API-Tokens). kundenmodus:<id> nur im
     * Kundenmodus genau dieses Berichts (Kundenmodus::aktiv() gilt nur für
     * das Konto, das ihn gestartet hat), im Kundenmodus nichts anderes. Ob
     * das Entsperren gerade gebremst ist, prüft der Start eigens. Für den
     * Start (SsoController::bestaetigen) und die Links (Kundenseite,
     * Schnittstelle).
     */
    public static function bestaetigenFehler(string $zweck): ?string
    {
        if (preg_match('/^(kundenmodus|token):\d{1,10}$/', $zweck) !== 1) {
            return 'Diese Bestätigung gibt es nicht.';
        }
        $user = Auth::user();
        if ($user === null) {
            return 'Bitte zuerst anmelden.';
        }
        if (!Einstellungen::aktiv()) {
            return self::T_NICHT_EINGERICHTET;
        }
        $name = Einstellungen::name();
        if (Konten::identitaet((int) $user['id']) === null) {
            return 'Dieses Konto ist nicht mit ' . $name . ' verknüpft – bitte mit dem Kennwort bestätigen.';
        }
        $sperre = Schutz::darfPerSso($user);
        if ($sperre !== null) {
            return match ($sperre) {
                'verwaltung' => 'Bestätigen über ' . $name . ' geht für Verwaltungskonten erst, wenn „Verwaltung per SSO“ eingeschaltet ist.',
                'kunden_aus' => Schutz::T_KUNDEN_AUS,
                default      => Schutz::T_KUNDE_LOKAL,
            };
        }
        [$art, $id] = explode(':', $zweck, 2);
        $m = Kundenmodus::aktiv();
        if ($art === 'kundenmodus') {
            if ($m === null || $m['bericht'] !== (int) $id) {
                return 'Dieser Kundenmodus ist nicht mehr aktiv.';
            }
        } elseif ($m !== null) {
            return 'Im Kundenmodus geht nur das Entsperren dieses Berichts.';
        }
        return null;
    }

    /** Nach dem Abmelden: Die nächste SSO-Anmeldung in diesem Browser verlangt prompt=login (12 h). */
    public static function cookieNeuSetzen(): void
    {
        self::cookie('1', time() + self::COOKIE_NEU_SEKUNDEN);
    }

    public static function cookieNeuLoeschen(): void
    {
        if (isset($_COOKIE[self::COOKIE_NEU])) {
            self::cookie('', time() - 42000);
            unset($_COOKIE[self::COOKIE_NEU]);
        }
    }

    // ------------------------------------------------------------ Zwecke

    /** @param list<string> $gruppen */
    private static function anmelden(#[\SensitiveParameter] array $v, array $claims, array $gruppen, #[\SensitiveParameter] string $idToken, string $ip): array
    {
        $r = Konten::aufloesen($claims, $gruppen, (string) $v['marke']);
        $user = $r['user'];
        Auth::sitzungStarten($user, $ip, 'sso', [
            'ident'     => (int) $r['identitaet']['id'],
            'marke'     => (string) $v['marke'],
            'auth_time' => is_int($claims['auth_time'] ?? null) ? $claims['auth_time'] : Einstellungen::jetzt(),
            'name'      => Konten::nameDort($claims),
            'mfa'       => Schutz::mitZweitemFaktor($claims),
        ]);
        self::beimAnbieterMerken($idToken);
        self::cookieNeuLoeschen();
        Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => 'ok', 'zweck' => 'anmelden']);
        $ziel = Kundenbereich::istKundenzugang($user) ? Auth::landingPath() : ($v['ziel'] ?? null);
        return ['ok' => true, 'code' => 'ok', 'zweck' => 'anmelden', 'ziel' => is_string($ziel) ? $ziel : Auth::landingPath(),
            'meldung' => $r['angelegt'] ? 'Willkommen – das Konto wurde über ' . Einstellungen::name() . ' angelegt.' : '',
            'user' => $user, 'angelegt' => $r['angelegt']];
    }

    /** Zweck „verbinden“: das angemeldete Konto (Kennwort-Sitzung) mit dieser Identität verknüpfen. @param list<string> $gruppen */
    private static function verbinden(array $claims, array $gruppen, #[\SensitiveParameter] string $idToken): array
    {
        $iss = Einstellungen::aussteller();
        $sub = (string) $claims['sub'];
        $userId = (int) Auth::id();
        if (Konten::identitaetFinden($iss, $sub) !== null) {
            throw new SsoFehler('verknuepft', Konten::T_VERKNUEPFT);
        }
        if (Auth::anmeldeweg() !== 'kennwort') {
            throw new SsoFehler('sitzung', 'Verbinden geht nur in einer Sitzung, die mit Kennwort angemeldet ist.');
        }
        Database::transaktion(static function () use ($userId, $iss, $sub, $claims, $gruppen): void {
            Konten::verknuepfen($userId, $iss, $sub, 'profil', Konten::nameDort($claims), $gruppen);
            Database::execute('DELETE FROM sso_anfragen WHERE schluessel = ?', [Konten::schluessel($iss, $sub)]);
        });
        Audit::log('sso.verknuepft', 'user', (string) $userId, ['user_id' => $userId, 'weg' => 'profil']);
        self::beimAnbieterMerken($idToken);
        Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => 'ok', 'zweck' => 'verbinden']);
        return ['ok' => true, 'code' => 'ok', 'zweck' => 'verbinden', 'ziel' => '/profil', 'meldung' => 'Mit ' . Einstellungen::name() . ' verbunden.'];
    }

    /**
     * Zwecke kundenmodus:<id> und token:<id>: dieselbe Identität wie die des
     * angemeldeten Kontos, frisch angemeldet (auth_time, schon in tokenHolen
     * geprüft), dieselbe Schranke wie beim Anmelden (W7).
     */
    private static function bestaetigen(#[\SensitiveParameter] array $v, array $claims, #[\SensitiveParameter] string $idToken): array
    {
        $user = (array) Auth::user();
        $ident = Konten::identitaet((int) $user['id']);
        if ($ident === null || !hash_equals((string) $ident['schluessel'], Konten::schluessel(Einstellungen::aussteller(), (string) $claims['sub']))) {
            throw new SsoFehler('andere_person', 'Diese Anmeldung gehört zu einer anderen Person – nicht bestätigt.');
        }
        $sperre = Schutz::darfPerSso($user);
        if ($sperre !== null) {
            throw new SsoFehler($sperre, match ($sperre) {
                'verwaltung' => Schutz::T_VERWALTUNG,
                'kunden_aus' => Schutz::T_KUNDEN_AUS,
                default      => Schutz::T_KUNDE_LOKAL,
            });
        }
        // Wie beim Anmelden: ein Verwaltungskonto mit MFA-Pflicht bestätigt nur mit zweitem Faktor.
        if (Schutz::mfaFehlt($user, $claims)) {
            throw new SsoFehler('mfa', Schutz::T_MFA);
        }
        [$art, $id] = explode(':', (string) $v['zweck'], 2);
        $id = (int) $id;
        if ($art === 'kundenmodus') {
            $m = Kundenmodus::aktiv();
            if ($m === null || $m['bericht'] !== $id || $m['user'] !== (int) $user['id']) {
                throw new SsoFehler('kundenmodus', 'Dieser Kundenmodus ist nicht mehr aktiv.');
            }
            if (Kundenmodus::wartezeit() > 0) {
                throw new SsoFehler('kundenmodus', self::T_GEBREMST);
            }
            BerichtService::ereignis($id, 'kundenmodus_ende', ['weg' => 'sso']);
            Kundenmodus::beenden();
            Session::regenerate();
            Csrf::erneuern();
            self::beimAnbieterMerken($idToken);
            Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => 'ok', 'zweck' => 'kundenmodus']);
            $b = Auth::can('berichte.view') ? BerichtService::find($id) : null;
            return ['ok' => true, 'code' => 'ok', 'zweck' => 'kundenmodus', 'meldung' => 'Kundenmodus beendet.',
                'ziel' => $b !== null && BerichtService::sichtbar($b) ? '/berichte/' . $id : Auth::landingPath()];
        }
        Session::put(self::BESTAETIGT, ['zweck' => (string) $v['zweck'], 'user_id' => (int) $user['id'], 'am' => Einstellungen::jetzt()]);
        self::beimAnbieterMerken($idToken);
        Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => 'ok', 'zweck' => $art]);
        return ['ok' => true, 'code' => 'ok', 'zweck' => $art, 'ziel' => self::fehlerZiel($v), 'meldung' => 'Bestätigt.'];
    }

    // ------------------------------------------------------------ intern

    /**
     * Code einlösen, ID-Token prüfen, fehlende Profil-Claims aus der
     * Userinfo – das ist das Warten auf den Anmeldedienst.
     *
     * @return array{0:array<string,mixed>, 1:string} Claims und ID-Token
     */
    private static function tokenHolen(#[\SensitiveParameter] string $code, #[\SensitiveParameter] array $v): array
    {
        $t = Anbieter::codeEinloesen($code, (string) $v['verifier']);
        $d = Anbieter::discovery();
        $soll = [
            'aussteller' => Einstellungen::aussteller(),
            'client_id'  => Einstellungen::clientId(),
            'algs'       => $d['algs'],
            'nonce'      => (string) $v['nonce'],
            'jetzt'      => Einstellungen::jetzt(),
            'auth_nach'  => $v['zweck'] === 'anmelden' ? null : (int) $v['at'] - IdToken::SPIEL,
        ];
        try {
            $claims = IdToken::pruefen($t['id_token'], $soll, Anbieter::schluessel(...));
        } catch (SsoFehler $e) {
            // Signiert der Anmeldedienst mit einem Verfahren, das wir annehmen,
            // das die gespeicherte Discovery (bis 24 h alt) aber nicht nennt,
            // wurde dort wohl der Schlüssel gewechselt (Signing Key RSA → EC).
            // Dann die Discovery einmal neu – gedrosselt wie das JWKS – und mit
            // ihren Verfahren noch einmal prüfen; sonst bliebe die Anmeldung
            // bis zum Ablauf des Zwischenspeichers gesperrt.
            $alg = IdToken::kopfAlg($t['id_token']);
            if ($e->pruefung !== 'alg' || $alg === null || !in_array($alg, Anbieter::ALGS, true) || in_array($alg, $d['algs'], true)) {
                throw $e;
            }
            $neu = Anbieter::discoveryAuffrischen();
            if ($neu === null || !in_array($alg, $neu['algs'], true)) {
                throw $e;
            }
            $d = $neu;
            $claims = IdToken::pruefen($t['id_token'], ['algs' => $d['algs']] + $soll, Anbieter::schluessel(...));
        }
        $profil = array_merge(self::PROFIL, [Einstellungen::gruppenClaim()]);
        $fehlt = array_filter(['preferred_username', 'name', 'email', Einstellungen::gruppenClaim()],
            static fn (string $k): bool => !array_key_exists($k, $claims)) !== [];
        if ($fehlt && $d['userinfo_endpoint'] !== null && $t['access_token'] !== '') {
            $info = Anbieter::userinfo($t['access_token']);
            // OIDC Core 5.3.2: sub der Userinfo muss genau der des ID-Tokens sein.
            if (!is_string($info['sub'] ?? null) || !hash_equals((string) $claims['sub'], $info['sub'])) {
                throw new SsoFehler('userinfo', 'Die Userinfo des Anmeldedienstes gehört zu einer anderen Person – abgelehnt.');
            }
            foreach ($profil as $k) {
                if (!array_key_exists($k, $claims) && array_key_exists($k, $info)) {
                    $claims[$k] = $info[$k];
                }
            }
        }
        return [$claims, $t['id_token']];
    }

    /**
     * Ein Vorgang ist gelungen: In diesem Browser ist jetzt jemand beim
     * Anmeldedienst angemeldet (BEIM_ANBIETER) – und das ID-Token bleibt als
     * id_token_hint für das Abmelden dort, wenn der Anmeldedienst es anbietet.
     * Sonst nirgends.
     */
    private static function beimAnbieterMerken(#[\SensitiveParameter] string $idToken): void
    {
        Session::put(self::BEIM_ANBIETER, true);
        if ((Anbieter::discoveryGespeichert()['end_session_endpoint'] ?? null) !== null) {
            Session::put(self::HINWEIS, $idToken);
        }
    }

    /** SSO an und dieselbe Einrichtung (Marke) wie beim Start – sonst SsoFehler('einrichtung'). */
    private static function einrichtungPruefen(#[\SensitiveParameter] array $v): void
    {
        if (!Einstellungen::unveraendert((string) ($v['marke'] ?? ''))) {
            throw new SsoFehler('einrichtung', self::T_GEAENDERT);
        }
    }

    /** Zweck „anmelden“ nur abgemeldet; die anderen nur für das Konto, das sie gestartet hat; im Kundenmodus nur dessen Entsperren. */
    private static function zweckPruefen(#[\SensitiveParameter] array $v): void
    {
        $id = Auth::id();
        if (self::basis($v) === 'anmelden') {
            if ($id !== null) {
                throw new SsoFehler('angemeldet', 'Hier ist schon jemand angemeldet – erst abmelden, dann mit einem anderen Konto anmelden.');
            }
        } elseif ($id === null || $id !== (int) ($v['user_id'] ?? 0)) {
            throw new SsoFehler('sitzung', 'Die Anmeldung gehört zu einer anderen Sitzung – bitte erneut versuchen.');
        }
        $m = $id !== null ? Kundenmodus::aktiv() : null;
        if ($m !== null && $v['zweck'] !== 'kundenmodus:' . $m['bericht']) {
            throw new SsoFehler('kundenmodus', 'Im Kundenmodus geht nur das Entsperren dieses Berichts.');
        }
        // Gebremst: gar nicht erst beim Anmeldedienst einlösen.
        if ($m !== null && Kundenmodus::wartezeit() > 0) {
            throw new SsoFehler('kundenmodus', self::T_GEBREMST);
        }
    }

    /** error/error_description des Anmeldedienstes – Code geprüft, Text ohne Steuerzeichen, Meldung ≤ 200 Zeichen. */
    private static function abgebrochen(string $fehler, ?string $beschreibung): SsoFehler
    {
        $fehler = preg_match('/^[a-z_]{1,64}$/', $fehler) === 1 ? $fehler : 'unbekannt';
        $text = $beschreibung !== null && preg_match('//u', $beschreibung) === 1
            ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $beschreibung)) : '';
        $vorne = Einstellungen::name() . ' meldet: ';
        return new SsoFehler('abgebrochen', mb_substr($vorne . ($text !== '' ? $text : $fehler), 0, 200), false, '', [], $fehler);
    }

    /**
     * Ein gescheiterter Vorgang (ab Schritt 3): Anfrage merken (nach dem
     * Rollback), Vermerke ins Audit, auth.sso_fehlgeschlagen mit der Adresse
     * (zählt für die Bremse), Log; im Kundenmodus ein Fehlversuch.
     *
     * @param list<string> $gruppen
     */
    private static function fehlgeschlagen(SsoFehler $e, #[\SensitiveParameter] array $v, string $ip, array $claims, array $gruppen): array
    {
        $name = $e->nameDort !== '' ? $e->nameDort : Konten::nameDort($claims);
        try {
            if ($e->anfrage && $claims !== []) {
                Konten::anfrageMerken($claims, $gruppen, $e->pruefung);
            }
            Audit::akteur('sso' . ($name !== '' ? ':' . $name : ''));
            foreach ($e->vermerke as [$aktion, $typ, $zielId, $details]) {
                Audit::log((string) $aktion, (string) $typ, (string) $zielId, (array) $details);
            }
            $user = Auth::user();
            $details = ['grund' => $e->pruefung, 'zweck' => self::basis($v)];
            if ($e->fehler !== '') {
                $details['fehler'] = $e->fehler;
            }
            Database::insert('audit_log', [
                'user_id'     => $user['id'] ?? null,
                'username'    => mb_substr((string) ($user['username'] ?? ('sso' . ($name !== '' ? ':' . $name : ''))), 0, 64),
                'action'      => 'auth.sso_fehlgeschlagen',
                'target_type' => 'user',
                'target_id'   => (string) ($v['user_id'] ?? ''),
                'details'     => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip'          => $ip,
                'created_at'  => gmdate('c', Einstellungen::jetzt()),
            ]);
            // Im Kundenmodus zählt ein gescheitertes Entsperren wie ein falsches
            // Kennwort – auch die Anmeldung einer anderen Person dort.
            // Ist das Entsperren schon gebremst, wurde nichts geprüft – wie beim
            // Kennwort zählt das nicht (die Wartezeit verdoppelte sich sonst).
            $m = self::basis($v) === 'kundenmodus' ? Kundenmodus::aktiv() : null;
            if ($m !== null && Kundenmodus::wartezeit() === 0) {
                $fehl = Kundenmodus::fehlversuch();
                if ($fehl >= Kundenmodus::HOECHSTENS_FEHL) {
                    BerichtService::ereignis($m['bericht'], 'kundenmodus_gebremst', ['fehlversuche' => (string) $fehl, 'weg' => 'sso']);
                }
            }
        } catch (\Throwable $x) {
            Logger::error('sso', ['fehler' => get_class($x) . ': ' . mb_substr($x->getMessage(), 0, 300)]);
        } finally {
            Audit::akteur(null);
        }
        Logger::info('sso', ['schritt' => 'rueckruf', 'grund' => $e->pruefung, 'zweck' => self::basis($v)]);
        return ['ok' => false, 'code' => $e->pruefung, 'zweck' => self::basis($v), 'ziel' => self::fehlerZiel($v), 'meldung' => $e->getMessage()];
    }

    /** anmelden | verbinden | kundenmodus | token */
    private static function basis(#[\SensitiveParameter] array $v): string
    {
        return explode(':', (string) ($v['zweck'] ?? 'anmelden'), 2)[0];
    }

    /** Wohin nach dem Vorgang: /login · /profil · Kundenseite · das Ziel der Bestätigung. */
    private static function fehlerZiel(#[\SensitiveParameter] array $v): string
    {
        $zweck = (string) ($v['zweck'] ?? 'anmelden');
        return match (self::basis($v)) {
            'anmelden'    => '/login',
            'verbinden'   => '/profil',
            'kundenmodus' => '/berichte/' . (int) explode(':', $zweck, 2)[1] . '/kunde',
            default       => Response::sichererPfad((string) ($v['ziel'] ?? '')) ?? '/',
        };
    }

    /**
     * Die offenen Vorgänge der Sitzung – nur gültige, nicht älter als
     * VORGANG_SEKUNDEN, in Einfügereihenfolge.
     *
     * @return array<string, array<string,mixed>>
     */
    private static function vorgaenge(int $jetzt): array
    {
        $alle = $_SESSION[self::SITZUNG] ?? [];
        $out = [];
        foreach (is_array($alle) ? $alle : [] as $state => $v) {
            if (is_string($state) && is_array($v) && is_string($v['zweck'] ?? null) && preg_match(self::ZWECKE, $v['zweck']) === 1
                && $jetzt - (int) ($v['at'] ?? 0) <= self::VORGANG_SEKUNDEN && $jetzt >= (int) ($v['at'] ?? 0)) {
                $out[$state] = $v;
            }
        }
        return $out;
    }

    /**
     * $arbeit (Warten auf den Anmeldedienst) ohne Sitzungssperre. War die
     * Sitzung offen und ist sie danach zu – ein anderer Reiter hat sich an-
     * oder abgemeldet –, wird nichts mehr geschrieben: SsoFehler('sitzung').
     *
     * @template T
     * @param callable():T $arbeit
     * @return T
     */
    private static function ohneSitzungssperre(callable $arbeit): mixed
    {
        $offen = session_status() === PHP_SESSION_ACTIVE;
        $ergebnis = Session::ohneSperre($arbeit);
        if ($offen && session_status() !== PHP_SESSION_ACTIVE) {
            throw new SsoFehler('sitzung', self::T_SITZUNG);
        }
        return $ergebnis;
    }

    /** 32 Zufallsbytes als base64url – 43 Zeichen. */
    private static function zufall(): string
    {
        return self::b64url(random_bytes(32));
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function cookie(string $wert, int $ablauf): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $sicher = Config::get('security.cookie_secure');
        setcookie(self::COOKIE_NEU, $wert, [
            'expires'  => $ablauf,
            'path'     => '/',
            'secure'   => $sicher === null ? Session::isHttps() : (bool) $sicher,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
