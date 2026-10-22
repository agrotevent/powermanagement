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

    /*
     * Warten, ohne die Sitzung zu halten.
     *
     * PHP sperrt die Sitzungsdatei, solange sie offen ist – für die ganze
     * Anfrage. Wartet eine Anfrage dabei (InfluxDB, Eventworx, eine andere
     * Instanz), steht jede weitere desselben Browsers so lange an: andere
     * Reiter, Live-Abfragen, das Speichern eines Formulars. Drei Wege:
     *
     *   freigeben()   schließen; danach wird nur noch gelesen.
     *   nachtragen()  nach freigeben() kurz wieder öffnen, um etwas
     *                 Kleines nachzutragen (der Eventworx-Vorgang), und
     *                 gleich wieder schließen.
     *   ohneSperre()  nur für das Warten schließen und danach wieder öffnen:
     *                 die Anfrage schreibt danach weiter (Meldungen, Eingaben)
     *                 und leitet weiter – Anfragen an andere Instanzen.
     *
     * Wiedergeöffnet wird über wiederOeffnen(): dieselbe Kennung, ohne
     * zweites Set-Cookie und nur, wenn es noch dieselbe Sitzung desselben
     * Benutzers ist. Was eine andere Anfrage in der Zwischenzeit geschrieben
     * hat, gilt – sie hat unseren Stand gelesen und ihren dazugeschrieben.
     */

    /**
     * Die Sitzung schließen, bevor eine Anfrage auf etwas wartet – Livewerte
     * aus der InfluxDB etwa. Danach wird nur noch gelesen; was noch in
     * $_SESSION geschrieben würde, bliebe nicht erhalten (dafür nachtragen()).
     */
    public static function freigeben(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Nach freigeben() doch noch etwas in die Sitzung schreiben, wenn das
     * Warten vorbei ist: Die Sitzung wird kurz wieder geöffnet (wiederOeffnen()),
     * $aenderung schreibt in $_SESSION, dann wird sie wieder freigegeben. Ist
     * es inzwischen eine andere Sitzung oder ein anderer Benutzer (abgemeldet,
     * Kennwort geändert, abgelaufen), wird nichts geschrieben: false – der
     * Aufrufer antwortet dann wie ohne Anmeldung. Ist die Sitzung noch offen,
     * läuft $aenderung einfach so.
     */
    public static function nachtragen(callable $aenderung): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $aenderung();
            return true;
        }
        if (!self::wiederOeffnen(self::kennung(), $_SESSION['user_id'] ?? null)) {
            return false;
        }
        $aenderung();
        session_write_close();
        return true;
    }

    /**
     * $arbeit ohne Sitzungssperre ausführen – das Warten auf eine andere
     * Instanz etwa, bei einer Plandatei bis zu Minuten – und die Sitzung
     * danach wieder öffnen (wiederOeffnen()). Anders als nach freigeben() ist
     * sie dann wieder offen: Die Anfrage kann noch Meldungen (Flash) schreiben
     * und weiterleiten. $arbeit selbst schreibt nichts in $_SESSION, es ginge
     * verloren. Wiedergeöffnet wird nur, solange noch nichts ausgegeben ist –
     * also vor der Ansicht aufrufen. Ohne offene Sitzung (Kommandozeile, /api,
     * schon freigegeben) läuft $arbeit einfach so, und die Sitzung bleibt zu.
     *
     * Gibt es die Sitzung danach nicht mehr – ein anderer Reiter hat sich
     * abgemeldet oder das Kennwort geändert (neue ID, die alte Datei
     * gelöscht) –, bleibt sie zu. Was die Anfrage dann noch in $_SESSION
     * schreibt, geht verloren; $_SESSION ist leer, die Anfrage gilt als
     * abgemeldet.
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
        $id = self::kennung();
        $benutzer = $_SESSION['user_id'] ?? null;
        session_write_close();
        try {
            return $arbeit();
        } finally {
            self::wiederOeffnen($id, $benutzer);
        }
    }

    /** Die Kennung der Sitzung – auch nach session_write_close() noch die alte. */
    private static function kennung(): string
    {
        $id = session_id();
        return is_string($id) ? $id : '';
    }

    /**
     * Die Sitzung $id nach session_write_close() wieder öffnen. true nur,
     * wenn es noch sie ist und sie noch $benutzer gehört; sonst bleibt sie zu.
     *
     * Name, Cookie-Parameter und strict_mode stehen noch von start(). Die
     * Kopfzeilen gegen Zwischenspeichern hat der erste Start gesetzt – nicht
     * noch einmal, sie überschrieben sonst, was die Anfrage seitdem selbst
     * gesetzt hat. Und PHP schickt beim Wiederöffnen das Sitzungscookie noch
     * einmal: Die Set-Cookie-Zeilen stehen danach wieder genau so wie davor.
     *
     * Hat ein anderer Reiter die Sitzung inzwischen beendet (Abmelden,
     * Kennwortwechsel: neue ID, die alte Datei gelöscht), legte PHP wegen
     * strict_mode eine neue, leere an und schickte ihr Cookie. Das
     * überschriebe im Browser das aus dem Kennwortwechsel (abgemeldet,
     * „Passwort geändert.“ weg), oder nach dem Abmelden landeten Meldung und
     * Eingaben dieser Anfrage in einer anonymen Sitzung und überdauerten die
     * nächste Anmeldung. Die neue Sitzung kommt also gleich wieder weg –
     * samt Datei und Cookie.
     */
    private static function wiederOeffnen(string $id, mixed $benutzer): bool
    {
        if ($id === '' || session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return false;
        }
        $cookies = self::setCookieZeilen();
        $offen = session_start(['cache_limiter' => '']);
        self::setCookieZeilenSetzen($cookies);
        if (!$offen) {
            return false;
        }
        if (session_id() !== $id) {
            $_SESSION = [];
            session_destroy();
            return false;
        }
        if (($_SESSION['user_id'] ?? null) !== $benutzer) {
            // Dieselbe Sitzung, aber ein anderer Benutzer: nichts schreiben
            // und mit ihren Daten auch nicht weiterarbeiten.
            session_abort();
            $_SESSION = [];
            return false;
        }
        return true;
    }

    /** @return list<string> alle Set-Cookie-Zeilen, die die Antwort bisher hat */
    private static function setCookieZeilen(): array
    {
        return array_values(array_filter(headers_list(), static fn (string $kopf): bool => preg_match('/^set-cookie:/i', $kopf) === 1));
    }

    /** @param list<string> $zeilen genau diese Set-Cookie-Zeilen, keine anderen */
    private static function setCookieZeilenSetzen(array $zeilen): void
    {
        header_remove('Set-Cookie');
        foreach ($zeilen as $kopf) {
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

    /**
     * Alles aus der Sitzung entfernen, sie selbst bleibt (anders als
     * destroy()) – nur die Zeit der letzten Aktivität bleibt stehen. Für den
     * Kontowechsel bei der Anmeldung (Auth::attempt()), vor regenerate().
     */
    public static function leeren(): void
    {
        $_SESSION = isset($_SESSION['last_activity']) ? ['last_activity' => $_SESSION['last_activity']] : [];
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
