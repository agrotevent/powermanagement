<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Audit;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Session;
use App\Support\Zeit;

/**
 * Anmeldung, Sperrlogik und Rechteprüfung.
 *
 * Aufgebaut wie in MasterAdmin, mit einem Unterschied: Konten und
 * Rollenzuweisungen haben einen Gültigkeitszeitraum. Geprüft wird bei
 * **jeder** Anfrage, nicht nur bei der Anmeldung – ein Zugang, der um
 * Mitternacht endet, endet um Mitternacht, auch in einer offenen Sitzung.
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;

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

        $hash = (string) $user['password_hash'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            Database::update('users', (int) $user['id'], ['password_hash' => $hash]);
        }

        Database::update('users', (int) $user['id'], [
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => gmdate('c'),
            'last_login_ip' => $ip,
        ]);

        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('angemeldet', time());
        self::sitzungBinden($hash);
        Csrf::erneuern();
        self::$user = null;
        self::$permissions = null;
        Audit::log('auth.login', 'user', (string) $user['id'], ['ip' => $ip]);

        return ['ok' => true, 'user' => $user, 'must_change_password' => (int) $user['must_change_password'] === 1];
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

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('auth.logout');
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
            return null;
        }
        // Kennwort seitdem geändert, oder die Sitzung ist älter als erlaubt:
        // dann ist sie zu Ende – auch wenn sie die ganze Zeit benutzt wurde.
        $hoechstens = (int) Config::get('security.max_session', 43200);
        if (!hash_equals(self::kennwortMarke((string) $user['password_hash']), (string) Session::get('kennwort', ''))
            || ($hoechstens > 0 && time() - (int) Session::get('angemeldet', 0) > $hoechstens)) {
            Session::forget('user_id');
            return null;
        }
        // Abgelaufen, während die Sitzung offen war: dann ist sie zu Ende.
        if (self::kontoZeitraum($user) !== null) {
            Session::forget('user_id');
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
        $rows = Database::select(
            'SELECT rp.permission, ur.gueltig_ab, ur.gueltig_bis FROM role_permissions rp
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?',
            [(int) $user['id']]
        );
        $jetzt = time();
        $perms = [];
        foreach ($rows as $row) {
            if (Zeit::imFenster($jetzt, $row['gueltig_ab'], $row['gueltig_bis'])) {
                $perms[(string) $row['permission']] = true;
            }
        }
        return self::$permissions = array_keys($perms);
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
            'SELECT r.*, ur.gueltig_ab, ur.gueltig_bis FROM roles r JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? ORDER BY r.name',
            [(int) $user['id']]
        );
    }

    /** Erste Seite, die der angemeldete Benutzer tatsächlich sehen darf. */
    public static function landingPath(): string
    {
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

    /** Für Prüfskripte. */
    public static function vergessen(): void
    {
        self::$user = null;
        self::$permissions = null;
    }
}
