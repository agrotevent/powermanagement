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
        // Abgelaufene Sitzungsdateien wegräumen lassen – vor session_start(),
        // dort läuft die GC.
        self::aufraeumenEinstellen();
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

    /**
     * Die Müllabfuhr der Sitzungsdateien einstellen (Befund 9). Debian
     * schaltet sie ab (session.gc_probability = 0) und räumt per Cron nur das
     * gemeinsame /var/lib/php/sessions – das eigene Verzeichnis des Pools
     * (storage/sessions) räumte niemand, und jede Anfrage ohne Cookie
     * (Anmeldeseite, Monitoring) ließ dort eine Datei zurück.
     *
     * Wie lange eine Datei bleibt, folgt der Konfiguration, keiner festen
     * Zahl: Nach idle_timeout ohne Anfrage ist eine Sitzung zu Ende, nach
     * max_session ohnehin – es gilt die längere der beiden Grenzen. Ohne jede
     * Grenze (beide 0) wird nichts weggeräumt. Ist die GC schon eingeschaltet,
     * bleibt ihr Takt; sonst räumt jeder tausendste Start auf.
     *
     * Nur im eigenen Verzeichnis: Eines, in das jeder schreiben darf
     * (/var/lib/php/sessions, /tmp), teilen sich mehrere Anwendungen – dort
     * gälte unsere Lebensdauer auch für fremde Sitzungen, es räumt sein
     * Besitzer, und lesen darf PHP es meist nicht (jede GC endete mit einer
     * Meldung).
     */
    private static function aufraeumenEinstellen(): void
    {
        // „N;/pfad“ bzw. „N;MODUS;/pfad“: der Ordner steht zuletzt.
        $pfad = (string) ini_get('session.save_path');
        $trenner = strrpos($pfad, ';');
        $pfad = $trenner === false ? $pfad : substr($pfad, $trenner + 1);
        if ($pfad === '') {
            $pfad = sys_get_temp_dir();
        }
        if (!is_dir($pfad) || !is_readable($pfad) || (fileperms($pfad) & 0002) !== 0) {
            return;
        }
        $dauer = max((int) Config::get('security.idle_timeout', 3600), (int) Config::get('security.max_session', 43200));
        if ($dauer <= 0) {
            ini_set('session.gc_probability', '0');
            return;
        }
        ini_set('session.gc_maxlifetime', (string) $dauer);
        if ((int) ini_get('session.gc_probability') <= 0) {
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '1000');
        }
    }
}
