<?php
declare(strict_types=1);

namespace App\Auth;

use App\Auth\Sso\Anmeldung;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\Schutz;
use App\Kunden\Kundenbereich;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Session;
use App\Support\Zeit;

/**
 * Anmeldung, Sperrlogik und Rechteprüfung.
 *
 * Aufgebaut wie in MasterAdmin, mit einem Unterschied: Konten und
 * Rollenzuweisungen haben einen Gültigkeitszeitraum. Geprüft wird bei
 * **jeder** Anfrage, nicht nur bei der Anmeldung – ein Zugang, der um
 * Mitternacht endet, endet um Mitternacht, auch in einer offenen Sitzung.
 *
 * Zwei Wege hinein, ein Abschluss (sitzungStarten()): Benutzername und
 * Kennwort (attempt()) oder die Anmeldung über SSO (Sso\Anmeldung). Der Weg
 * steht in der Sitzung (anmeldeweg(): kennwort | sso | notzugang); eine
 * SSO-Sitzung prüft user() bei jeder Anfrage gegen Sso\Schutz::sitzungGilt(),
 * eine Kennwort-Sitzung gegen Sso\Schutz::kennwortVerboten() („nur SSO“).
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;

    /**
     * Sitzungsschlüssel: das Konto, dem die Sitzung zuletzt gehörte. Bleibt
     * stehen, wenn user() die Anmeldung vergisst – die nächste Anmeldung
     * erkennt daran einen Kontowechsel (sitzungUebernehmen()).
     */
    private const KONTO = 'konto';

    public static function attempt(string $username, string $password, string $ip): array
    {
        $max     = max(1, (int) Config::get('security.lockout_attempts', 5));
        $fenster = 60 * max(1, (int) Config::get('security.lockout_minutes', 15));
        $falsch  = ['ok' => false, 'message' => 'Benutzername oder Passwort falsch.'];

        // Je Adresse: wer viele Konten durchprobiert, wird gebremst – ganz
        // gleich, ob es sie gibt. Das begrenzt auch, wie viele Konten sich von
        // einer Adresse aus sperren lassen.
        $vonHier = self::fehlversuche('ip', $ip, time() - $fenster);
        if (count($vonHier) >= max($max, (int) Config::get('security.ip_attempts', 20))) {
            $min = max(1, (int) ceil(((int) Zeit::ts($vonHier[0]) + $fenster - time()) / 60));
            self::logFailure($username, $ip, 'Adresse gebremst');
            return ['ok' => false, 'message' => "Zu viele fehlgeschlagene Anmeldungen von dieser Adresse. Bitte in {$min} Minute(n) erneut versuchen."];
        }

        $user = Database::first('SELECT * FROM users WHERE username = ?', [$username]);

        if (!$user) {
            // Ein unbekanntes Konto verhält sich wie ein bekanntes: gleiche
            // Rechenzeit, gleiche Meldung, und nach zu vielen Versuchen auch
            // „gesperrt“ – sonst verriete die Sperre, welche Namen es gibt.
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1OVGm5Buu');
            $versuche = self::fehlversuche('username', $username, time() - $fenster);
            if (count($versuche) >= $max) {
                $min = max(1, (int) ceil(((int) Zeit::ts($versuche[count($versuche) - 1]) + $fenster - time()) / 60));
                return ['ok' => false, 'message' => "Konto gesperrt. Bitte in {$min} Minute(n) erneut versuchen."];
            }
            self::logFailure($username, $ip, 'unbekannter Benutzer');
            return $falsch;
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            $min = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
            return ['ok' => false, 'message' => "Konto gesperrt. Bitte in {$min} Minute(n) erneut versuchen."];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            // Hochzählen in der Datenbank, nicht im PHP: gleichzeitige
            // Anfragen zählen sonst alle von demselben Stand aus weiter.
            Database::execute('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [(int) $user['id']]);
            Database::execute(
                'UPDATE users SET locked_until = ?, failed_logins = 0 WHERE id = ? AND failed_logins >= ?',
                [gmdate('c', time() + $fenster), (int) $user['id'], $max]
            );
            self::logFailure($username, $ip, 'falsches Passwort');
            return $falsch;
        }

        // Erst nach dem Kennwort: sonst verriete die Meldung, dass es das Konto gibt.
        if ((int) $user['is_active'] !== 1) {
            self::logFailure($username, $ip, 'Konto deaktiviert');
            return ['ok' => false, 'message' => 'Dieses Konto ist deaktiviert.'];
        }

        // Erst nach dem Kennwort prüfen: sonst verriete die Meldung, dass
        // es das Konto gibt.
        $zeitraum = self::kontoZeitraum($user);
        if ($zeitraum !== null) {
            self::logFailure($username, $ip, 'außerhalb des Gültigkeitszeitraums');
            return ['ok' => false, 'message' => $zeitraum];
        }
        // Kundenzugang eines beendeten Kunden – ebenfalls erst nach dem Kennwort.
        $kunde = Kundenbereich::sperre($user);
        if ($kunde !== null) {
            self::logFailure($username, $ip, 'Kunde nicht aktiv');
            return ['ok' => false, 'message' => $kunde];
        }
        // „Nur SSO“ – auch erst nach dem Kennwort, sonst erkennte man an der
        // Meldung verknüpfte Konten.
        $nurSso = Schutz::kennwortVerboten($user);
        if ($nurSso !== null) {
            self::logFailure($username, $ip, 'nur SSO');
            return ['ok' => false, 'message' => $nurSso];
        }

        $hash = (string) $user['password_hash'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            Database::update('users', (int) $user['id'], ['password_hash' => $hash]);
            $user['password_hash'] = $hash;
        }

        // Rollen aus Gruppen gelten nur für eine SSO-Sitzung; mit Kennwort
        // hat das Konto nur, was ihm von Hand zugewiesen ist.
        Database::execute("DELETE FROM user_roles WHERE user_id = ? AND quelle = 'sso'", [(int) $user['id']]);
        Zugriff::vergessen();
        $weg = Einstellungen::schalter(Einstellungen::NUR_SSO) && Einstellungen::aktiv() && Schutz::notzugangWirksam($user)
            ? 'notzugang' : 'kennwort';
        self::sitzungStarten($user, $ip, $weg);

        return ['ok' => true, 'user' => $user, 'must_change_password' => (int) $user['must_change_password'] === 1];
    }

    /**
     * Der gemeinsame Abschluss jeder Anmeldung – mit Kennwort (attempt()),
     * über den Notzugang oder über SSO (Sso\Anmeldung): Zähler und Sperre
     * der Kennwort-Bremse zurück (auch bei SSO, damit keine Altsperre stehen
     * bleibt), bei einem Kontowechsel die Sitzung leeren, neue Sitzungs-ID,
     * Weg und bei SSO die Angaben dazu (Identität, Marke, auth_time, Name,
     * zweiter Faktor) in die Sitzung, Bindung ans Kennwort, neues CSRF-Token.
     *
     * $weg: kennwort | notzugang | sso. Der Notzugang meldet sich laut an:
     * Audit und Fehlerprotokoll.
     *
     * @param array<string,mixed> $sso nur bei $weg = 'sso'
     */
    public static function sitzungStarten(array $user, string $ip, string $weg, array $sso = []): void
    {
        $id = (int) $user['id'];
        Database::update('users', $id, [
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => gmdate('c'),
            'last_login_ip' => $ip,
        ]);
        self::sitzungUebernehmen($id);
        Session::regenerate();
        Session::put('user_id', $id);
        Session::put(self::KONTO, $id);
        Session::put('angemeldet', time());
        Session::put('anmeldeweg', $weg);
        // Was zu einer früheren SSO-Sitzung gehörte, gilt für diese nicht.
        foreach (['sso', 'sso_hinweis', 'sso_bestaetigt', 'sso_abgemeldet'] as $schluessel) {
            Session::forget($schluessel);
        }
        if ($weg === 'sso') {
            Session::put('sso', $sso);
        }
        self::sitzungBinden((string) $user['password_hash']);
        Csrf::erneuern();
        self::$user = null;
        self::$permissions = null;
        Zugriff::vergessen();
        Audit::log('auth.login', 'user', (string) $id, ['ip' => $ip, 'weg' => $weg]);
        if ($weg === 'notzugang') {
            Logger::error('auth', ['notzugang' => (string) $user['username'], 'ip' => $ip]);
        }
    }

    /**
     * Wie die Sitzung angemeldet wurde: kennwort | sso | notzugang – '' ohne
     * Anmeldung. Sitzungen von vor SSO haben keinen Eintrag: kennwort.
     */
    public static function anmeldeweg(): string
    {
        if (!self::check()) {
            return '';
        }
        $weg = Session::get('anmeldeweg', 'kennwort');
        return in_array($weg, ['kennwort', 'sso', 'notzugang'], true) ? $weg : 'kennwort';
    }

    /**
     * Darf dieses Konto jetzt angemeldet sein? null = ja, sonst die Meldung.
     *
     * Eine Stelle für die Anmeldung, jede Anfrage (user()), die Bindung eines
     * Tokens der Schnittstelle (ApiZugang) und künftig SSO: der Zeitraum des
     * Kontos und – bei Kundenzugängen – ob ihr Kunde aktiv und gültig ist.
     * Ob das Konto selbst aktiv ist (is_active), prüft, wer fragt.
     */
    public static function zugangSperre(array $user, ?int $jetzt = null): ?string
    {
        return self::kontoZeitraum($user, $jetzt) ?? Kundenbereich::sperre($user, $jetzt);
    }

    /**
     * Liegt jetzt im Gültigkeitszeitraum des Kontos? null = ja, sonst die Meldung.
     */
    public static function kontoZeitraum(array $user, ?int $jetzt = null): ?string
    {
        $jetzt ??= time();
        $ab  = Zeit::ts($user['gueltig_ab'] ?? null);
        $bis = Zeit::ts($user['gueltig_bis'] ?? null);
        if ($ab !== null && $jetzt < $ab) {
            return 'Dieser Zugang gilt erst ab ' . Zeit::anzeige($user['gueltig_ab']) . '.';
        }
        if ($bis !== null && $jetzt > $bis) {
            return 'Dieser Zugang ist am ' . Zeit::anzeige($user['gueltig_bis']) . ' abgelaufen.';
        }
        return null;
    }

    /**
     * Fehlgeschlagene Anmeldungen seit $seit – nach Adresse oder Benutzername,
     * älteste zuerst (Zeitpunkte).
     *
     * @return list<string>
     */
    private static function fehlversuche(string $spalte, string $wert, int $seit): array
    {
        $spalte = $spalte === 'ip' ? 'ip' : 'username';
        return array_column(Database::select(
            "SELECT created_at FROM audit_log WHERE action = 'auth.login_failed' AND {$spalte} = ? AND created_at > ? ORDER BY created_at",
            [$wert, gmdate('c', $seit)]
        ), 'created_at');
    }

    /**
     * Vor der Anmeldung von $userId: Gehörte diese Sitzung zuletzt einem
     * anderen Konto, wird sie geleert.
     *
     * Endet eine Sitzung, ohne zerstört zu werden – Kennwort zurückgesetzt,
     * Höchstdauer, Ende des Zugangs oder seines Kunden –, vergisst user() nur
     * die Anmeldung; alles andere bliebe stehen und ginge an jeden, der sich
     * danach in diesem Browser anmeldet: Formulareingaben, Eventworx-Treffer
     * und geladene Vorgänge, die Rückfrage einer Zuordnung, die Auswahl der
     * Auswertung, die Sperre des Kundenmodus – einem Kundenzugang etwa, was
     * das Personal zuletzt eingegeben hat. Meldet sich dasselbe Konto wieder
     * an, bleibt alles (auch die Bremse des Kundenmodus). Ist nicht bekannt,
     * wem eine Sitzung gehörte, in der schon jemand angemeldet war (angelegt
     * vor dieser Regel), wird sie ebenso geleert.
     */
    private static function sitzungUebernehmen(int $userId): void
    {
        $vorher = Session::get(self::KONTO, Session::get('user_id'));
        $warAngemeldet = $vorher !== null || Session::get('kennwort') !== null;
        if ($warAngemeldet && (int) $vorher !== $userId) {
            Session::leeren();
        }
    }

    /**
     * Die Sitzung gilt nicht mehr (user()): die Anmeldung vergessen – und
     * Meldungen, die noch auf ihre Seite warten. Sie gehören dem Konto, das
     * nicht mehr angemeldet ist, und stünden sonst auf der Anmeldeseite vor
     * dem Nächsten, der sie öffnet. Was danach kommt („bitte neu anmelden“),
     * bleibt. KONTO bleibt stehen (sitzungUebernehmen()).
     */
    private static function sitzungBeenden(): void
    {
        Session::forget('user_id');
        Session::forget('flash');
    }

    /**
     * Die Sitzung an das jetzige Kennwort binden.
     *
     * Wird das Kennwort geändert – selbst oder von der Verwaltung –, endet
     * jede Sitzung, die mit dem alten angemeldet war: auch die auf dem
     * Tablet, das jemand liegen gelassen hat, oder die mit einem
     * abgegriffenen Sitzungs-Cookie.
     */
    public static function sitzungBinden(string $kennwortHash): void
    {
        Session::put('kennwort', self::kennwortMarke($kennwortHash));
    }

    private static function kennwortMarke(string $kennwortHash): string
    {
        return hash('sha256', 'pm-sitzung|' . $kennwortHash);
    }

    private static function logFailure(string $username, string $ip, string $reason): void
    {
        Database::insert('audit_log', [
            'user_id'     => null,
            'username'    => $username,
            'action'      => 'auth.login_failed',
            'target_type' => 'user',
            'target_id'   => '',
            'details'     => json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
            'ip'          => $ip,
            'created_at'  => gmdate('c'),
        ]);
    }

    /**
     * Eine heikle Handlung noch einmal bestätigen – das Token eines Zugangs
     * der Schnittstelle anzeigen (`token:<id>`), den Kundenmodus eines
     * Berichts entsperren (`kundenmodus:<id>`).
     *
     * Mit Kennwort: nur, wenn sich das Konto auch mit Kennwort anmeldet – für
     * ein Konto „nur über SSO“ (Schutz::nurSso) gilt kein Kennwort, auch
     * nicht das richtige. Ohne Kennwort ($kennwort = ''): eine frische
     * Bestätigung über den Anmeldedienst für genau diesen Zweck und dieses
     * Konto (Anmeldung::bestaetigt – einmalig, höchstens fünf Minuten alt;
     * dieselbe Identität und die Schranken wie beim Anmelden prüft der
     * Rückruf).
     *
     * Ein Fehler zählt nicht gegen das Konto (anders als attempt()) – sonst
     * sperrte ein Kunde, der am Tablet herumtippt, den Prüfer für 15 Minuten
     * aus. Er steht im Audit (`auth.bestaetigung_fehlversuch` mit dem
     * Zweck); beim Kundenmodus zählt die Sitzung (Kundenmodus::fehlversuch).
     */
    public static function bestaetigen(string $zweck, #[\SensitiveParameter] string $kennwort): bool
    {
        if (preg_match('/^(kundenmodus|token):\d{1,10}$/', $zweck) !== 1) {
            throw new \LogicException('Bestätigen: unbekannter Zweck');
        }
        $user = self::user();
        if ($user === null) {
            return false;
        }
        $ok = $kennwort === ''
            ? Anmeldung::bestaetigt($zweck)
            : !Schutz::nurSso($user) && password_verify($kennwort, (string) $user['password_hash']);
        if (!$ok) {
            Audit::log('auth.bestaetigung_fehlversuch', 'user', (string) $user['id'], ['zweck' => $zweck]);
        }
        return $ok;
    }

    /**
     * Den Kundenmodus des Berichts $berichtId mit dem Kennwort des
     * angemeldeten Kontos entsperren – bestaetigen() mit `kundenmodus:<id>`.
     * Über den Anmeldedienst entsperrt der Rückruf selbst (Sso\Anmeldung,
     * Zweck kundenmodus:<id>); hier gilt nur das Kennwort.
     */
    public static function kennwortBestaetigen(int $berichtId, #[\SensitiveParameter] string $kennwort): bool
    {
        return self::bestaetigen('kundenmodus:' . $berichtId, $kennwort);
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('auth.logout', '', '', ['sso' => self::anmeldeweg() === 'sso']);
        }
        Session::destroy();
        self::$user = null;
        self::$permissions = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('user_id');
        if (!$id) {
            return null;
        }
        $user = Database::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
        if (!$user) {
            // Deaktiviert oder gelöscht: die Sitzung ist zu Ende – auch wenn
            // das Konto später wieder aktiv wird.
            self::sitzungBeenden();
            return null;
        }
        // Kennwort seitdem geändert, oder die Sitzung ist älter als erlaubt:
        // dann ist sie zu Ende – auch wenn sie die ganze Zeit benutzt wurde.
        $hoechstens = (int) Config::get('security.max_session', 43200);
        if (!hash_equals(self::kennwortMarke((string) $user['password_hash']), (string) Session::get('kennwort', ''))
            || ($hoechstens > 0 && time() - (int) Session::get('angemeldet', 0) > $hoechstens)) {
            self::sitzungBeenden();
            return null;
        }
        // Abgelaufen, während die Sitzung offen war – oder der Kunde eines
        // Kundenzugangs ist beendet: dann ist sie zu Ende.
        if (self::zugangSperre($user) !== null) {
            self::sitzungBeenden();
            return null;
        }
        // SSO-Sitzung: SSO noch an, dieselbe Einrichtung, Identität noch da,
        // nicht „Sitzungen beendet“, Schranken erfüllt, nicht zu alt.
        // Kennwort-Sitzung: endet, sobald das Konto nur noch per SSO darf.
        $weg = Session::get('anmeldeweg', 'kennwort');
        $aus = $weg === 'sso'
            ? !Schutz::sitzungGilt($user, (array) Session::get('sso', []), (int) Session::get('angemeldet', 0))
            : Schutz::kennwortVerboten($user) !== null;
        if ($aus) {
            self::sitzungBeenden();
            return null;
        }
        return self::$user = $user;
    }

    /** Für die Kommandozeile und Prüfskripte: als dieser Benutzer handeln. */
    public static function alsBenutzer(?int $userId): void
    {
        self::$user = $userId === null ? null : Database::first('SELECT * FROM users WHERE id = ?', [$userId]);
        self::$permissions = null;
        Zugriff::vergessen();
        Kundenbereich::vergessen();
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function name(): string
    {
        $u = self::user();
        return $u ? (string) $u['username'] : 'system';
    }

    /**
     * Die Rechte aus allen **jetzt gültigen** Rollenzuweisungen.
     *
     * @return array<int,string>
     */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $user = self::user();
        if (!$user) {
            return self::$permissions = [];
        }
        // Kundenzugänge haben keine Rechte – auch nicht über eine Rolle, die
        // ihnen jemand per SQL zugewiesen hat. „*“ erfüllte sonst jedes Recht.
        if (Kundenbereich::istKundenzugang($user)) {
            return self::$permissions = [];
        }
        return self::$permissions = self::rechteAusRollen((int) $user['id'], time());
    }

    /**
     * Die Rechte eines beliebigen Kontos aus seinen jetzt (bzw. zu $jetzt)
     * gültigen Rollenzuweisungen – von Hand und aus SSO-Gruppen. Ein
     * Kundenzugang hat keine. Ohne Sitzung und ohne Zwischenspeicher: für
     * die Schranken der SSO-Anmeldung (Sso\Schutz), die in user() laufen und
     * darum nie can() fragen dürfen.
     *
     * @return list<string>
     */
    public static function rechteVon(int $userId, ?int $jetzt = null): array
    {
        $konto = Database::first('SELECT id, kunde_id FROM users WHERE id = ?', [$userId]);
        if ($konto === null || Kundenbereich::istKundenzugang($konto)) {
            return [];
        }
        return self::rechteAusRollen($userId, $jetzt ?? time());
    }

    /** @return list<string> */
    private static function rechteAusRollen(int $userId, int $jetzt): array
    {
        $rows = Database::select(
            'SELECT rp.permission, ur.gueltig_ab, ur.gueltig_bis FROM role_permissions rp
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?',
            [$userId]
        );
        $perms = [];
        foreach ($rows as $row) {
            if (Zeit::imFenster($jetzt, $row['gueltig_ab'], $row['gueltig_bis'])) {
                $perms[(string) $row['permission']] = true;
            }
        }
        return array_keys($perms);
    }

    /**
     * Nach einer Änderung an Rollenzuweisungen (Rollen aus Gruppen, Lösen,
     * SSO aus): die Rechte dieser Anfrage neu lesen – die Anmeldung bleibt.
     */
    public static function rechteVergessen(): void
    {
        self::$permissions = null;
    }

    public static function can(string $permission): bool
    {
        $perms = self::permissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public static function roles(): array
    {
        $user = self::user();
        if (!$user) {
            return [];
        }
        return Database::select(
            'SELECT r.*, ur.gueltig_ab, ur.gueltig_bis, ur.quelle FROM roles r JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? ORDER BY r.name',
            [(int) $user['id']]
        );
    }

    /** Erste Seite, die der angemeldete Benutzer tatsächlich sehen darf. */
    public static function landingPath(): string
    {
        if (Kundenbereich::istKundenzugang()) {
            return Kundenbereich::PFAD;
        }
        $candidates = [
            '/dashboard'       => 'dashboard.view',
            '/karte'           => 'karte.view',
            '/anlagen'         => 'anlagen.view',
            '/statistik'       => 'statistik.view',
            '/system/benutzer' => 'system.users.manage',
        ];
        foreach ($candidates as $path => $permission) {
            if (self::can($permission)) {
                return $path;
            }
        }
        return '/profil';
    }

    public static function validatePassword(string $password): ?string
    {
        $min = (int) Config::get('security.password_min_length', 12);
        if (strlen($password) < $min) {
            return "Das Passwort muss mindestens {$min} Zeichen lang sein.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Das Passwort muss Buchstaben und Ziffern enthalten.';
        }
        return null;
    }

    /**
     * Das eigene Kennwort ändern – aus dem Profil und aus dem Kundenbereich.
     *
     * Verlangt das aktuelle Kennwort und die Wiederholung. Danach enden alle
     * anderen Sitzungen mit dem alten Kennwort (user()); diese läuft mit
     * neuer Sitzungs-ID und neuem Token weiter.
     *
     * @return array{ok:bool,message:string}
     */
    public static function kennwortAendern(array $user, string $aktuell, string $neu, string $wiederholung): array
    {
        if (!password_verify($aktuell, (string) $user['password_hash'])) {
            return ['ok' => false, 'message' => 'Das aktuelle Passwort stimmt nicht.'];
        }
        if ($neu !== $wiederholung) {
            return ['ok' => false, 'message' => 'Die neuen Passwörter stimmen nicht überein.'];
        }
        $fehler = self::validatePassword($neu);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $hash = password_hash($neu, PASSWORD_DEFAULT);
        Database::update('users', (int) $user['id'], [
            'password_hash'        => $hash,
            'must_change_password' => 0,
            'updated_at'           => gmdate('c'),
        ]);
        Audit::log('auth.passwort', 'user', (string) $user['id']);
        Session::regenerate();
        self::sitzungBinden($hash);
        Csrf::erneuern();
        self::$user = null;
        self::$permissions = null;
        return ['ok' => true, 'message' => 'Passwort geändert.'];
    }

    /** Für Prüfskripte. */
    public static function vergessen(): void
    {
        self::$user = null;
        self::$permissions = null;
        Kundenbereich::vergessen();
    }
}
