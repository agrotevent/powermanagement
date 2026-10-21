<?php
declare(strict_types=1);

namespace App\Pdf;

/**
 * Eine Tabelle mit festen Spalten und zweizeiligem Gruppenkopf, der auf
 * jeder Seite wiederholt wird. Zeilen werden so hoch wie ihr längster
 * umbrochener Inhalt; passt eine Zeile nicht mehr auf die Seite, beginnt
 * $neueSeite eine neue (mit eigenem Seitenkopf) und gibt das y zurück, an
 * dem die Tabelle weitergeht.
 *
 * Eine Zelle ist ein Text oder ein Array:
 *   ['teile' => [['t' => 'Bühne', 'pt' => 7.5, 'fett' => false, 'grau' => 0.0], …],
 *    'symbol' => 'haken'|'kreuz'|'', 'marke' => '¹']
 * Jeder Teil beginnt eine neue Zeile und wird in der Spalte umbrochen.
 * Kopftexte dürfen Indizes tragen („R_{iso}“, Schreiber::textIndex).
 */
final class Tabelle
{
    public const PT = 7.5;
    private const KOPF_PT = 6.5;
    private const POLSTER = 0.9;
    private const GRUPPE_H = 3.6;

    private float $y;
    /** @var list<float> linke Kante je Spalte */
    private array $xe = [];

    /**
     * @param list<array{titel:list<string>,breite:float,ausrichtung?:string}> $spalten
     * @param list<array{titel:string,von:int,bis:int}> $gruppen Spaltenindizes, einschließlich
     * @param callable():float $neueSeite
     */
    public function __construct(
        private Schreiber $pdf,
        private float $x,
        float $y,
        private array $spalten,
        private array $gruppen,
        private $neueSeite,
        private float $unten = 268.0,
    ) {
        $this->y = $y;
        $lauf = $x;
        foreach ($spalten as $s) {
            $this->xe[] = $lauf;
            $lauf += (float) $s['breite'];
        }
    }

    public function y(): float
    {
        return $this->y;
    }

    /** Höhe einer Zeile von $mm bei $pt. */
    public static function zeilenhoehe(float $pt): float
    {
        return $pt * 0.3528 * 1.18;
    }

    /** Den Kopf an der aktuellen Stelle zeichnen. */
    public function kopf(): void
    {
        $pdf = $this->pdf;
        $zeilen = 1;
        foreach ($this->spalten as $s) {
            $zeilen = max($zeilen, count($s['titel']));
        }
        $titelH = $zeilen * self::zeilenhoehe(self::KOPF_PT) + 2 * self::POLSTER;
        $gesamt = self::GRUPPE_H + $titelH;
        $inGruppe = [];
        foreach ($this->gruppen as $gi => $g) {
            for ($i = $g['von']; $i <= $g['bis']; $i++) {
                $inGruppe[$i] = $gi;
            }
            $gx = $this->xe[$g['von']];
            $gb = $this->xe[$g['bis']] + (float) $this->spalten[$g['bis']]['breite'] - $gx;
            $pdf->rechteck($gx, $this->y, $gb, self::GRUPPE_H, 0.9);
            $this->kopfText($g['titel'], $gx, $gb, $this->y + self::GRUPPE_H - 1.0);
        }
        foreach ($this->spalten as $i => $s) {
            $oben = isset($inGruppe[$i]) ? $this->y + self::GRUPPE_H : $this->y;
            $h = isset($inGruppe[$i]) ? $titelH : $gesamt;
            $b = (float) $s['breite'];
            $pdf->rechteck($this->xe[$i], $oben, $b, $h, 0.9);
            $n = count($s['titel']);
            $start = $oben + ($h - $n * self::zeilenhoehe(self::KOPF_PT)) / 2 + self::zeilenhoehe(self::KOPF_PT) * 0.78;
            foreach ($s['titel'] as $k => $t) {
                $this->kopfText($t, $this->xe[$i], $b, $start + $k * self::zeilenhoehe(self::KOPF_PT));
            }
        }
        $this->y += $gesamt;
    }

    /**
     * Eine Zeile. Passt sie nicht mehr, geht es auf einer neuen Seite mit
     * neuem Kopf weiter.
     *
     * @param list<string|array> $zellen je Spalte
     */
    public function zeile(array $zellen): void
    {
        $pdf = $this->pdf;
        $inhalt = [];
        $hoehe = 0.0;
        foreach ($this->spalten as $i => $s) {
            $zelle = $zellen[$i] ?? '';
            $teile = is_array($zelle) ? ($zelle['teile'] ?? []) : [['t' => (string) $zelle]];
            $zeilen = [];
            $h = 0.0;
            foreach ($teile as $t) {
                $pt = (float) ($t['pt'] ?? self::PT);
                $fett = (bool) ($t['fett'] ?? false);
                foreach ($pdf->umbruch((string) ($t['t'] ?? ''), (float) $s['breite'] - 2 * self::POLSTER, $pt, $fett) as $z) {
                    $zeilen[] = ['t' => $z, 'pt' => $pt, 'fett' => $fett, 'grau' => (float) ($t['grau'] ?? 0.0)];
                    $h += self::zeilenhoehe($pt);
                }
            }
            if (is_array($zelle) && ($zelle['symbol'] ?? '') !== '') {
                $h = max($h, 3.0);
            }
            $inhalt[$i] = $zeilen;
            $hoehe = max($hoehe, $h);
        }
        $hoehe = max(5.0, $hoehe + 2 * self::POLSTER);

        if ($this->y + $hoehe > $this->unten) {
            $this->y = ($this->neueSeite)();
            $this->kopf();
        }
        foreach ($this->spalten as $i => $s) {
            $x = $this->xe[$i];
            $b = (float) $s['breite'];
            $pdf->rechteck($x, $this->y, $b, $hoehe);
            $zelle = $zellen[$i] ?? '';
            $ausrichtung = (string) ($s['ausrichtung'] ?? 'links');
            $y = $this->y + self::POLSTER;
            if (is_array($zelle) && ($zelle['symbol'] ?? '') !== '') {
                $marke = (string) ($zelle['marke'] ?? '');
                $kante = 2.6;
                $sx = $x + ($b - $kante) / 2 - ($marke !== '' ? 0.7 : 0);
                $pdf->symbol($sx, $y + 0.2, (string) $zelle['symbol'], $kante);
                if ($marke !== '') {
                    $pdf->text($sx + $kante + 0.2, $y + 1.6, $marke, 6.0);
                }
                $y += 3.0;
            }
            foreach ($inhalt[$i] as $z) {
                $y += self::zeilenhoehe($z['pt']);
                $grundlinie = $y - self::zeilenhoehe($z['pt']) * 0.24;
                match ($ausrichtung) {
                    'mitte'  => $pdf->textMitte($x + $b / 2, $grundlinie, $z['t'], $z['pt'], $z['fett'], $z['grau']),
                    'rechts' => $pdf->textRechts($x + $b - self::POLSTER, $grundlinie, $z['t'], $z['pt'], $z['fett'], $z['grau']),
                    default  => $pdf->text($x + self::POLSTER, $grundlinie, $z['t'], $z['pt'], $z['fett'], $z['grau']),
                };
            }
        }
        $this->y += $hoehe;
    }

    /** Kopftext mittig, notfalls kleiner, bis er in die Spalte passt. */
    private function kopfText(string $t, float $x, float $b, float $grundlinie): void
    {
        $pt = self::KOPF_PT;
        while ($pt > 4.5 && $this->pdf->breiteIndex($t, $pt, true) > $b - 1.0) {
            $pt -= 0.25;
        }
        $this->pdf->textIndex($x + ($b - $this->pdf->breiteIndex($t, $pt, true)) / 2, $grundlinie, $t, $pt, true);
    }
}
