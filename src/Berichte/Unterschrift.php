<?php
declare(strict_types=1);

namespace App\Berichte;

/**
 * Unterschriften der Übergabeberichte: nur Geometrie.
 *
 * Erfasst wird im Browser (unterschrift.js) als Liste von Strichen, jeder
 * Punkt ein Paar ganzer Zahlen 0..10000 auf einer Fläche 3:1 – x bezogen
 * auf die Breite, y auf die Höhe. Keine Zeit, kein Druck, keine
 * Geschwindigkeit: Schreibdynamik wäre ein biometrisches Merkmal (DSGVO
 * Art. 9), und für den Beweiswert trägt sie hier nichts bei – den tragen
 * das Einfrieren, die Kette, das Siegel und das PDF beim Kunden.
 *
 * Geprüft wird streng: oben nur „s“, jeder Punkt genau [int, int]. Was
 * anders aussieht (Gleitkomma, Strings, weitere Schlüssel wie t oder p),
 * wird abgelehnt statt still zurechtgebogen – so landet nie mehr in der
 * Datenbank als die Linie selbst.
 */
final class Unterschrift
{
    public const MAX_BYTES = 65536;
    public const MAX_STRICHE = 200;
    public const MAX_PUNKTE = 6000;
    public const RASTER = 10000;

    /** Mindestgröße: Rahmen 10 % der Breite, 5 % der Höhe, Linienlänge (in Breiten-Einheiten). */
    public const MIN_BREITE = 1000;
    public const MIN_HOEHE = 500;
    public const MIN_LAENGE = 3000;

    public const ZU_KLEIN = 'Unterschrift zu klein oder leer – bitte größer unterschreiben.';

    private const STEUERZEICHEN = '/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u';

    /**
     * @return array{ok:bool, message:string, striche?:list<list<array{0:int,1:int}>>}
     */
    public static function pruefen(string $json): array
    {
        $nein = static fn (string $m): array => ['ok' => false, 'message' => $m];
        if (trim($json) === '') {
            return $nein(self::ZU_KLEIN);
        }
        if (strlen($json) > self::MAX_BYTES) {
            return $nein('Die Unterschrift ist zu groß – bitte löschen und neu unterschreiben.');
        }
        try {
            $daten = json_decode($json, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $nein('Die Unterschrift ist nicht lesbar.');
        }
        if (!is_array($daten) || array_keys($daten) !== ['s'] || !is_array($daten['s']) || !array_is_list($daten['s'])) {
            return $nein('Die Unterschrift ist nicht lesbar.');
        }
        if (count($daten['s']) > self::MAX_STRICHE) {
            return $nein('Zu viele Striche – bitte löschen und neu unterschreiben.');
        }

        $striche = [];
        $punkte = 0;
        foreach ($daten['s'] as $strich) {
            if (!is_array($strich) || !array_is_list($strich) || $strich === []) {
                return $nein('Die Unterschrift ist nicht lesbar.');
            }
            $linie = [];
            foreach ($strich as $p) {
                if (!is_array($p) || !array_is_list($p) || count($p) !== 2 || !is_int($p[0]) || !is_int($p[1])) {
                    return $nein('Die Unterschrift ist nicht lesbar.');
                }
                $linie[] = [self::begrenzen($p[0]), self::begrenzen($p[1])];
            }
            $punkte += count($linie);
            if ($punkte > self::MAX_PUNKTE) {
                return $nein('Zu viele Punkte – bitte löschen und neu unterschreiben.');
            }
            $striche[] = $linie;
        }
        if ($striche === [] || !self::grossGenug($striche)) {
            return $nein(self::ZU_KLEIN);
        }
        return ['ok' => true, 'message' => '', 'striche' => $striche];
    }

    /**
     * Reicht die Unterschrift? Rahmen um alle Punkte und Länge der Linie,
     * y auf Breiten-Einheiten umgerechnet (die Fläche ist dreimal so breit
     * wie hoch).
     *
     * @param list<list<array{0:int,1:int}>> $striche
     */
    public static function grossGenug(array $striche): bool
    {
        $minX = $minY = self::RASTER;
        $maxX = $maxY = 0;
        $laenge = 0.0;
        foreach ($striche as $linie) {
            $vorher = null;
            foreach ($linie as [$x, $y]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
                if ($vorher !== null) {
                    $laenge += sqrt(($x - $vorher[0]) ** 2 + (($y - $vorher[1]) / 3) ** 2);
                }
                $vorher = [$x, $y];
            }
        }
        return $maxX - $minX >= self::MIN_BREITE && $maxY - $minY >= self::MIN_HOEHE && $laenge >= self::MIN_LAENGE;
    }

    /**
     * Die Unterschrift als SVG (3000 × 1000), geglättet über quadratische
     * Kurven durch die Mittelpunkte wie beim Zeichnen. Alles wird zu ganzen
     * Zahlen – auch aus einer veränderten Datenbank kommt hier nur
     * Geometrie heraus, nie Markup.
     */
    public static function svg(array $striche): string
    {
        $d = [];
        foreach ($striche as $linie) {
            if (!is_array($linie)) {
                continue;
            }
            $p = [];
            foreach ($linie as $punkt) {
                if (is_array($punkt) && count($punkt) >= 2) {
                    $werte = array_values($punkt);
                    $p[] = [intdiv(self::begrenzen((int) $werte[0]) * 3, 10), intdiv(self::begrenzen((int) $werte[1]), 10)];
                }
            }
            if ($p === []) {
                continue;
            }
            $teil = 'M' . $p[0][0] . ' ' . $p[0][1];
            $n = count($p);
            if ($n === 1) {
                $teil .= 'l0 0';
            } else {
                for ($i = 1; $i < $n - 1; $i++) {
                    $teil .= 'Q' . $p[$i][0] . ' ' . $p[$i][1] . ' ' . intdiv($p[$i][0] + $p[$i + 1][0], 2) . ' ' . intdiv($p[$i][1] + $p[$i + 1][1], 2);
                }
                $teil .= 'L' . $p[$n - 1][0] . ' ' . $p[$n - 1][1];
            }
            $d[] = $teil;
        }
        return '<svg class="unterschrift-svg" viewBox="0 0 3000 1000" role="img" aria-label="Unterschrift" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="' . implode('', $d) . '" fill="none" stroke="currentColor" stroke-width="14" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    /** Name, Funktion, Ort: einzeilig, ohne Steuer- und Richtungszeichen, gekürzt. */
    public static function text(mixed $wert, int $laenge = 120): string
    {
        $s = is_scalar($wert) ? (string) $wert : '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        $s = (string) preg_replace(self::STEUERZEICHEN, ' ', $s);
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $s)), 0, $laenge);
    }

    /** Mehrzeiliger Text (Vorbehalte des Kunden): Zeilenumbrüche bleiben. */
    public static function absatz(mixed $wert, int $laenge = 2000): string
    {
        $s = is_scalar($wert) ? str_replace(["\r\n", "\r"], "\n", (string) $wert) : '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        $zeilen = array_map(static fn (string $z): string => self::text($z, $laenge), explode("\n", $s));
        return mb_substr(trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $zeilen))), 0, $laenge);
    }

    private static function begrenzen(int $wert): int
    {
        return max(0, min(self::RASTER, $wert));
    }
}
