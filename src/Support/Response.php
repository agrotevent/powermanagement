<?php
declare(strict_types=1);

namespace App\Support;

use App\Kunden\Kundenbereich;

final class Response
{
    /**
     * Kopfzeilen jeder Seite des Kundenbereichs (auch seiner Fehlerseiten):
     * nichts zwischenspeichern – ein Firmenrechner wird geteilt –, und keine
     * Adresse als Referer hinaus.
     */
    public const KUNDENBEREICH = ['Cache-Control: private, no-store', 'Referrer-Policy: no-referrer'];

    /** @param list<string> $kopfzeilen ersetzen die Vorgaben gleichen Namens (etwa Referrer-Policy) */
    public static function html(string $html, int $status = 200, array $kopfzeilen = []): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Content-Security-Policy: ' . self::sicherheitsRichtlinie());
        foreach ($kopfzeilen as $kopf) {
            header($kopf);
        }
        echo $html;
    }

    /**
     * Content-Security-Policy für HTML-Seiten.
     *
     * Skripte nur aus eigenen Dateien und mit der Nonce dieser Antwort – so
     * läuft eingeschleustes HTML mit <script> oder onclick="…" nicht, falls
     * irgendwo doch einmal etwas ungefiltert durchrutscht. Das wiegt hier
     * schwerer als anderswo: unter /node-red/ liegt auf derselben Adresse
     * der Node-RED-Editor, und wer dort ein Skript ausführen kann, führt
     * Code auf dem Server aus.
     *
     * style-src erlaubt Inline-Stile (style="…" in Karte und Verteilerplan),
     * wasm-unsafe-eval nur WebAssembly für pdf.js – kein eval().
     */
    public static function sicherheitsRichtlinie(): string
    {
        return "default-src 'self'; script-src 'self' 'nonce-" . View::nonce() . "' 'wasm-unsafe-eval'; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
            . "worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function redirect(string $to): void
    {
        header('Location: ' . $to, true, 302);
    }

    /**
     * Ein Weiterleitungsziel, das sicher auf dieser Seite bleibt – oder null.
     *
     * Nur ein Pfad: „/\evil.example“ oder „/<Tab>/evil.example“ machen
     * Browser zu „//evil.example“, also zu einer fremden Seite. Deshalb weder
     * Backslash noch Steuer- oder Leerzeichen, und kein zweiter Schrägstrich
     * am Anfang.
     */
    public static function sichererPfad(string $ziel): ?string
    {
        return preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$#', $ziel) === 1 ? $ziel : null;
    }

    /**
     * Zurück, wo die Anfrage herkam – aber nur innerhalb dieser Seite.
     *
     * Der Referer kommt vom Browser; eine fremde Adresse darin wird nicht
     * angesteuert, dann gilt das Ersatzziel.
     */
    public static function zurueckZiel(string $ersatz = '/'): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $teile = $referer !== '' ? parse_url($referer) : false;
        if (!is_array($teile) || !isset($teile['host'])) {
            return $ersatz;
        }
        $hier = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($hier === '' || strtolower($teile['host']) !== $hier) {
            return $ersatz;
        }
        $pfad = ($teile['path'] ?? '/') . (isset($teile['query']) ? '?' . $teile['query'] : '');
        return self::sichererPfad($pfad) ?? $ersatz;
    }

    public static function notFound(string $message = 'Seite nicht gefunden.'): void
    {
        self::fehler(404, $message);
    }

    public static function forbidden(string $message = 'Für diese Funktion fehlt dir die Berechtigung.'): void
    {
        self::fehler(403, $message);
    }

    /** Eine Fehlerseite senden – für Kundenzugänge im Kundenbereich, mit dessen Kopfzeilen. */
    public static function fehler(int $code, string $message): void
    {
        [$html, $kunde] = self::fehlerSeite($code, $message);
        self::html($html, $code, $kunde ? self::KUNDENBEREICH : []);
    }

    /**
     * Die Fehlerseite als HTML, dazu: ist es die des Kundenbereichs?
     *
     * Für das Personal ein ganzes Dokument ohne Layout, also auch ohne
     * Navigation, „Profil“ und „Abmelden“ (und ohne den Zugriff auf den
     * Benutzer, den es ohne Anmeldung nicht gibt) – so auch im Kundenmodus.
     *
     * Ein Kundenzugang sieht nie das Personal-Layout, auch hier nicht: er
     * bekommt die Fehlerseite im Layout des Kundenbereichs, für 403 und 404
     * mit demselben Text – ob etwas fehlt oder einem anderen Kunden gehört,
     * soll man ihr nicht ansehen. Geht dabei etwas schief (der 500-Weg nach
     * einem Datenbankfehler), bleibt es bei der schlichten Seite.
     *
     * @return array{0:string,1:bool}
     */
    public static function fehlerSeite(int $code, string $message): array
    {
        $ebene = ob_get_level();
        try {
            if (Kundenbereich::istKundenzugang()) {
                return [View::render('kundenbereich/fehler', ['code' => $code, 'flashes' => Flash::pull()], 'layout/kundenbereich'), true];
            }
        } catch (\Throwable) {
            // Was die halbe Seite schon gepuffert hat, kommt weg; weiter mit der schlichten.
            while (ob_get_level() > $ebene) {
                ob_end_clean();
            }
        }
        return [View::render('errors/error', ['code' => $code, 'message' => $message], ''), false];
    }

    /**
     * Ein PDF ausliefern: inline (ansehen) oder als Anhang (speichern).
     *
     * Nicht zwischenspeichern, kein Referer hinaus, kein Erraten des Typs.
     * Der Dateiname nur aus [A-Za-z0-9._-] (höchstens 100 Zeichen) – nie
     * Kundennamen oder andere Angaben aus dem Inhalt in der Kopfzeile.
     */
    public static function pdf(string $name, string $bytes, bool $anhang = false): void
    {
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($anhang ? 'attachment' : 'inline') . '; filename="' . self::sichererDateiname($name) . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        echo $bytes;
    }

    /**
     * Ein Dateiname für Content-Disposition: nur [A-Za-z0-9._-], höchstens
     * 100 Zeichen, kein Punkt oder Strich am Rand – nie ein Anführungszeichen,
     * Zeilenumbruch oder Umlaut in der Kopfzeile.
     */
    public static function sichererDateiname(string $name, string $ersatz = 'dokument.pdf'): string
    {
        $name = substr(trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.'), 0, 100);
        return $name === '' ? $ersatz : $name;
    }

    public static function download(string $filename, string $content, string $mime = 'text/plain'): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }
}
