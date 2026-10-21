<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Wie lange eine Web-Anfrage noch rechnen darf.
 *
 * nginx wartet höchstens fastcgi_read_timeout (300 s) auf PHP, dann
 * antwortet es mit 504 – PHP rechnet weiter, und seine Meldung sieht
 * niemand. Die Fristen einzelner Schritte (ein Git-Aufruf bis 280 s, eine
 * Plandatei von einer anderen Instanz bis 180 s) gelten je Aufruf; eine
 * Anfrage macht aber mehrere davon – Holen und Hochladen über Git etwa.
 * Deshalb zählt zusätzlich eine Frist für die ganze Anfrage, ab ihrem
 * Beginn (REQUEST_TIME_FLOAT): SEKUNDEN, ein wenig unter den 300 s, damit
 * die Meldung noch ankommt.
 *
 * Auf der Kommandozeile gibt es keine solche Frist (rest() ist null) – dort
 * wartet kein Webserver.
 */
final class Anfragezeit
{
    /** So lange darf eine Web-Anfrage insgesamt dauern – knapp unter fastcgi_read_timeout. */
    public const SEKUNDEN = 285;

    /**
     * Nur für Prüfskripte: der Beginn einer gedachten Web-Anfrage
     * (microtime) – damit lässt sich auf der Kommandozeile prüfen, wie sich
     * eine Anfrage nahe ihrer Frist verhält.
     */
    public static ?float $beginn = null;

    /** Sekunden bis zum Ende der Frist (auch negativ) – null ohne Frist (Kommandozeile). */
    public static function rest(): ?float
    {
        $beginn = self::$beginn;
        if ($beginn === null) {
            if (PHP_SAPI === 'cli') {
                return null;
            }
            $beginn = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        }
        return $beginn + self::SEKUNDEN - microtime(true);
    }

    /** $sekunden, höchstens aber, was der Anfrage noch bleibt (nie unter 0). */
    public static function hoechstens(float $sekunden): float
    {
        $rest = self::rest();
        return $rest === null ? $sekunden : max(0.0, min($sekunden, $rest));
    }

    /** Wartet hier ein Webserver auf die Antwort? */
    public static function imWeb(): bool
    {
        return self::rest() !== null;
    }
}
