<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'layout/app'): void
    {
        $data['currentUser'] = Auth::user();
        $data['flashes']     = Flash::pull();
        Response::html(View::render($template, $data, $layout));
    }

    protected function redirect(string $to): void
    {
        Response::redirect($to);
    }

    protected function back(string $fallback = '/'): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $this->redirect($referer !== '' ? $referer : $fallback);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    /** Antwort als JSON oder als Meldung mit Weiterleitung – je nachdem, wer fragt. */
    protected function antwort(Request $request, array $ergebnis, string $ziel): void
    {
        if ($request->wantsJson()) {
            $this->json($ergebnis, !empty($ergebnis['ok']) ? 200 : 422);
            return;
        }
        !empty($ergebnis['ok']) ? Flash::success((string) $ergebnis['message']) : Flash::error((string) $ergebnis['message']);
        $this->redirect($ziel);
    }

    /**
     * Etwas tun, nachdem die Seite beim Besucher ist.
     *
     * Für Arbeit, die zur Seite gehört, aber nicht auf sie warten lassen
     * darf – das Kacheln eines PDF-Grundrisses etwa dauert bei einem
     * A0-Plan gemessen 13 Sekunden, und solange sah der Besucher bisher
     * eine leere Seite.
     *
     * Unter PHP-FPM schließt `fastcgi_finish_request()` die Verbindung und
     * lässt den Prozess weiterlaufen. Ohne FPM – Kommandozeile, eingebauter
     * Server – gibt es das nicht; dann wird die Arbeit einfach hier
     * erledigt, wie bisher. Das ist langsam, aber nie falsch.
     */
    protected function nachDerAuslieferung(callable $arbeit): void
    {
        // Der Besucher ist weg, die Arbeit bleibt – sonst bricht sie mitten
        // im Kachelordner ab und hinterlässt eine halbe Pyramide.
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $arbeit();
    }
}
