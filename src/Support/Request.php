<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key);
        return in_array($v, ['1', 'on', 'true', 'yes', 1, true], true);
    }

    /** @return array<int,string> */
    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        if (!is_array($v)) {
            return $v === null || $v === '' ? [] : [(string) $v];
        }
        return array_values(array_map(static fn ($x) => (string) $x, $v));
    }

    public function ip(): string
    {
        $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('security.trusted_proxies', []);
        if (in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Von rechts lesen: der Proxy hängt an, was er gesehen hat. Links
            // steht, was der Aufrufer selbst mitgeschickt hat – das zählt nicht.
            $teile = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($teile as $adresse) {
                if (filter_var($adresse, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                if (!in_array($adresse, $trusted, true)) {
                    return $adresse;
                }
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * Erwartet die Anfrage JSON? Nur der Accept-Kopf zählt, wie im Router –
     * nicht X-Requested-With. Damit schickte `data-laeuft` (app.js) seine
     * Formulare ab; Controller::antwort() gab dann JSON statt Meldung und
     * Weiterleitung zurück, und das Neuladen danach zeigte keine Meldung –
     * auch keinen Fehler (Verteiler-Abgleich, MQTT-Zugänge). Wer JSON will
     * (PMNetz.post, PM.post, live.js, karte.js), sagt es mit Accept.
     */
    public function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * Ein Kopf der Anfrage, gleich wie geschrieben („authorization“ ist
     * „Authorization“); '' wenn er fehlt.
     *
     * Authorization reicht nicht jeder Server als HTTP_AUTHORIZATION durch:
     * Apache nach einer Umschreibung als REDIRECT_HTTP_AUTHORIZATION, manche
     * nur über getallheaders().
     */
    public function kopf(string $name): string
    {
        $schluessel = strtoupper(str_replace('-', '_', $name));
        $kandidaten = ['HTTP_' . $schluessel];
        if ($schluessel === 'AUTHORIZATION') {
            $kandidaten[] = 'REDIRECT_HTTP_AUTHORIZATION';
        }
        // Inhaltstyp und -länge stehen unter CGI/FPM ohne „HTTP_“ davor.
        if (in_array($schluessel, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $kandidaten[] = $schluessel;
        }
        foreach ($kandidaten as $k) {
            if (isset($_SERVER[$k]) && is_scalar($_SERVER[$k]) && (string) $_SERVER[$k] !== '') {
                return trim((string) $_SERVER[$k]);
            }
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $kopf => $wert) {
                if (strcasecmp((string) $kopf, $name) === 0 && is_scalar($wert)) {
                    return trim((string) $wert);
                }
            }
        }
        return '';
    }

    /**
     * Hat PHP den Körper verworfen, weil er größer war als post_max_size?
     *
     * Dann sind $_POST und $_FILES leer – samt CSRF-Token. Die Länge in
     * Bytes, sonst null.
     */
    public function koerperVerworfen(): ?int
    {
        if ($this->method() !== 'POST' || $_POST !== [] || $_FILES !== []) {
            return null;
        }
        $laenge = $this->kopf('Content-Length');
        $grenze = (int) @ini_parse_quantity((string) ini_get('post_max_size'));
        if (!ctype_digit($laenge) || strlen($laenge) > 18 || $grenze <= 0) {
            return null;
        }
        return (int) $laenge > $grenze ? (int) $laenge : null;
    }

    /**
     * Der Körper der Anfrage (etwa JSON) – höchstens $max Bytes.
     *
     * null, wenn er größer ist: schon angekündigt (Content-Length) oder beim
     * Lesen bemerkt (ohne Längenangabe). Gelesen wird nie mehr als $max + 1.
     */
    public function koerper(int $max): ?string
    {
        $laenge = $this->kopf('Content-Length');
        if (ctype_digit($laenge) && (strlen($laenge) > 18 || (int) $laenge > $max)) {
            return null;
        }
        $ein = @fopen('php://input', 'rb');
        if ($ein === false) {
            return '';
        }
        $daten = stream_get_contents($ein, $max + 1);
        fclose($ein);
        if ($daten === false) {
            return '';
        }
        return strlen($daten) > $max ? null : $daten;
    }
}
