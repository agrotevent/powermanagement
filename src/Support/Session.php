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
     * $arbeit ohne Sitzungssperre ausführen – das Warten auf eine andere
     * Instanz etwa, bei einer Plandatei bis zu Minuten – und die Sitzung
     * danach wieder öffnen.
     *
     * PHP sperrt die Sitzungsdatei für die ganze Anfrage. Wer dabei wartet,
     * hält jede weitere Anfrage desselben Browsers auf: andere Reiter,
     * Live-Abfragen, das Speichern eines Formulars. Anders als nach
     * freigeben() ist die Sitzung danach wieder offen – die Anfrage kann noch
     * Meldungen (Flash) schreiben und weiterleiten.
     *
     * Beim Schließen wird geschrieben, was bis dahin in $_SESSION steht. Beim
     * Wiederöffnen kommt $_SESSION neu aus der Ablage: Was eine andere
     * Anfrage in der Zwischenzeit geschrieben hat, gilt – sie hat unseren
     * Stand gelesen und ihren dazugeschrieben. $arbeit selbst schreibt
     * deshalb nichts in $_SESSION, es ginge verloren. Wiedergeöffnet wird
     * nur, solange noch nichts ausgegeben ist – also vor der Ansicht
     * aufrufen. Ohne offene Sitzung (Kommandozeile, /api) läuft $arbeit
     * einfach so.
     *
     * Gibt es die Sitzung danach nicht mehr, bleibt sie zu: Ein anderer
     * Reiter hat sich abgemeldet oder das Kennwort geändert (neue ID, die
     * alte Datei gelöscht). Wegen strict_mode legte PHP sonst eine neue,
     * leere Sitzung an und schickte ihr Cookie – das überschriebe im Browser
     * das aus dem Kennwortwechsel (abgemeldet, „Passwort geändert.“ weg),
     * oder nach dem Abmelden landeten Meldung und Eingaben dieser Anfrage in
     * einer anonymen Sitzung und überdauerten die nächste Anmeldung. Was die
     * Anfrage dann noch in $_SESSION schreibt, geht verloren.
     *
     * @template T
     * @param callable():T $arbeit
     * @return T
     */
    public static function ohneSperre(callable $arbeit): mixed
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $arbeit();
        }
        $id = session_id();
        session_write_close();
        try {
            return $arbeit();
        } finally {
            // Name, Cookie-Parameter und strict_mode stehen noch von start().
            // Die Kopfzeilen gegen Zwischenspeichern hat der erste Start
            // gesetzt – nicht noch einmal, sie überschrieben sonst, was die
            // Anfrage seitdem selbst gesetzt hat.
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                session_start(['cache_limiter' => '']);
                if (session_id() !== $id) {
                    // Die neue, leere Sitzung wieder weg – samt ihrem Cookie.
                    $_SESSION = [];
                    session_destroy();
                    self::sitzungsCookieZuruecknehmen();
                }
            }
        }
    }

    /** Das Set-Cookie der Sitzung aus der Antwort nehmen; andere Cookies bleiben. */
    private static function sitzungsCookieZuruecknehmen(): void
    {
        $sitzung = '/^set-cookie:\s*' . preg_quote(session_name(), '/') . '=/i';
        $andere = array_filter(headers_list(), static fn (string $kopf): bool
            => preg_match('/^set-cookie:/i', $kopf) === 1 && preg_match($sitzung, $kopf) !== 1);
        header_remove('Set-Cookie');
        foreach ($andere as $kopf) {
            header($kopf, false);
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
}
