<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Audit;
use App\Support\Config;
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
        $user = Database::first('SELECT * FROM users WHERE username = ?', [$username]);

        if (!$user) {
            // Timing angleichen, damit gültige Benutzernamen nicht erkennbar sind.
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1OVGm5Buu');
            self::logFailure($username, $ip, 'unbekannter Benutzer');
            return ['ok' => false, 'message' => 'Benutzername oder Passwort falsch.'];
        }

        if ((int) $user['is_active'] !== 1) {
            self::logFailure($username, $ip, 'Konto deaktiviert');
            return ['ok' => false, 'message' => 'Dieses Konto ist deaktiviert.'];
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            $min = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
            return ['ok' => false, 'message' => "Konto gesperrt. Bitte in {$min} Minute(n) erneut versuchen."];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $attempts = (int) $user['failed_logins'] + 1;
            $max      = (int) Config::get('security.lockout_attempts', 5);
            $data     = ['failed_logins' => $attempts];
            if ($attempts >= $max) {
                $data['locked_until']  = gmdate('c', time() + 60 * (int) Config::get('security.lockout_minutes', 15));
                $data['failed_logins'] = 0;
            }
            Database::update('users', (int) $user['id'], $data);
            self::logFailure($username, $ip, 'falsches Passwort');
            return ['ok' => false, 'message' => 'Benutzername oder Passwort falsch.'];
        }

        // Erst nach dem Kennwort prüfen: sonst verriete die Meldung, dass
        // es das Konto gibt.
        $zeitraum = self::kontoZeitraum($user);
        if ($zeitraum !== null) {
            self::logFailure($username, $ip, 'außerhalb des Gültigkeitszeitraums');
            return ['ok' => false, 'message' => $zeitraum];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', (int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        Database::update('users', (int) $user['id'], [
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => gmdate('c'),
            'last_login_ip' => $ip,
        ]);

        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
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
