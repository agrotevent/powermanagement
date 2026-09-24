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
        echo $html;
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

    public static function notFound(string $message = 'Seite nicht gefunden.'): void
    {
        self::html(View::render('errors/error', ['code' => 404, 'message' => $message]), 404);
    }

    public static function forbidden(string $message = 'Für diese Funktion fehlt dir die Berechtigung.'): void
    {
        self::html(View::render('errors/error', ['code' => 403, 'message' => $message]), 403);
    }

    public static function download(string $filename, string $content, string $mime = 'text/plain'): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }
}
