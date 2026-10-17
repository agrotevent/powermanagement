<?php
declare(strict_types=1);

namespace App\Support;

final class Response
{
    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Content-Security-Policy: ' . self::sicherheitsRichtlinie());
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

    /*
     * Die Fehlerseite ist schon ein ganzes Dokument – ohne Layout, also auch
     * ohne Navigation, „Profil“ und „Abmelden“ (und ohne den Zugriff auf
     * den Benutzer, den es ohne Anmeldung nicht gibt).
     */
    public static function notFound(string $message = 'Seite nicht gefunden.'): void
    {
        self::html(View::render('errors/error', ['code' => 404, 'message' => $message], ''), 404);
    }

    public static function forbidden(string $message = 'Für diese Funktion fehlt dir die Berechtigung.'): void
    {
        self::html(View::render('errors/error', ['code' => 403, 'message' => $message], ''), 403);
    }

    public static function download(string $filename, string $content, string $mime = 'text/plain'): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }
}
