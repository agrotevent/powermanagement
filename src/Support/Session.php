<?php
declare(strict_types=1);

namespace App\Support;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) Config::get('security.session_name', 'pm_session'));
        // Nur Sitzungs-IDs, die der Server selbst vergeben hat.
        ini_set('session.use_strict_mode', '1');
        // „Secure“ von Hand erzwingen, wenn der Proxy davor kein
        // X-Forwarded-Proto schickt; ohne Angabe: wie die Anfrage kam.
        $sicher = Config::get('security.cookie_secure');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $sicher === null ? self::isHttps() : (bool) $sicher,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $idle = (int) Config::get('security.idle_timeout', 3600);
        if ($idle > 0 && isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $idle) {
            self::destroy();
            session_start();
            $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Sitzung wegen Inaktivität beendet.'];
        }
        $_SESSION['last_activity'] = time();
    }

    /**
     * Die Sitzung schließen, bevor eine Anfrage auf etwas wartet – Livewerte
     * aus der InfluxDB etwa. PHP sperrt die Sitzungsdatei, solange sie offen
     * ist: Jede weitere Anfrage desselben Browsers stünde so lange an, auch
     * das Speichern eines Formulars. Danach wird nur noch gelesen; was noch in
     * $_SESSION geschrieben würde, bliebe nicht erhalten.
     */
    public static function freigeben(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Nach freigeben() doch noch etwas in die Sitzung schreiben, wenn das
     * Warten vorbei ist: Die Sitzung wird kurz wieder geöffnet – dabei neu
     * gelesen, was andere Anfragen inzwischen geschrieben haben, bleibt also
     * erhalten –, $aenderung schreibt in $_SESSION, dann wird sie wieder
     * freigegeben. Ist es inzwischen eine andere Sitzung oder ein anderer
     * Benutzer (abgemeldet, abgelaufen), wird nichts geschrieben: false.
     */
    public static function nachtragen(callable $aenderung): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $aenderung();
            return true;
        }
        $id = session_id();
        $benutzer = $_SESSION['user_id'] ?? null;
        // Dieselbe Kennung wie vorhin, ohne das Cookie ein zweites Mal zu
        // senden – und ohne eins für eine neue, leere Sitzung.
        if ($id === '' || $id === false || session_status() !== PHP_SESSION_NONE || headers_sent() || !session_start(['use_cookies' => 0])) {
            return false;
        }
        if (session_id() !== $id || ($_SESSION['user_id'] ?? null) !== $benutzer) {
            session_abort();
            return false;
        }
        $aenderung();
        session_write_close();
        return true;
    }

    /** Hinter einem Reverse-Proxy kommt das Schema per Header. */
    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return strtolower((string) $proto) === 'https';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        session_destroy();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
