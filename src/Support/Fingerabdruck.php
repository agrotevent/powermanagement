<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Fingerabdruck eines Datenstands – gleich, solange der Inhalt gleich ist.
 *
 * Schlüssel in Objekten zählen nicht in ihrer Reihenfolge (eine umsortierte
 * Datei ist dieselbe), Listen schon: wo die Reihenfolge nicht zählt, sortiert
 * der Aufrufer vorher. Benutzt vom Verteiler-Abgleich und von der
 * Schnittstelle („stand“, „hier geändert“).
 */
final class Fingerabdruck
{
    /**
     * sha256 (hex) über den Inhalt.
     *
     * Vorher einmal durch JSON und zurück: Objekte werden Arrays, 40.0 und 40
     * sind dasselbe (wie es auch eine Gegenseite liest, die die Zahl aus
     * JSON hat), und was nicht in JSON geht, zählt nicht.
     */
    public static function von(mixed $daten): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $rund = json_decode((string) json_encode($daten, $flags), true, 512, JSON_BIGINT_AS_STRING);
        return hash('sha256', (string) json_encode(self::geordnet($rund), $flags));
    }

    /** Maps rekursiv nach Schlüssel sortiert, Listen in ihrer Reihenfolge. */
    public static function geordnet(mixed $wert): mixed
    {
        if (!is_array($wert)) {
            return $wert;
        }
        $wert = array_map([self::class, 'geordnet'], $wert);
        if (!array_is_list($wert)) {
            ksort($wert);
        }
        return $wert;
    }
}
