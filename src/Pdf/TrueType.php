<?php
declare(strict_types=1);

namespace App\Pdf;

/**
 * Eine TrueType-Schrift für den PDF-Schreiber: Breiten, Zeichen → Glyphe
 * (cmap 3/1, Format 4) und die Teilmenge zum Einbetten.
 *
 * Die Teilmenge entsteht, indem alle nicht benutzten Glyphen geleert
 * werden – die Glyphen-IDs bleiben, so passt CIDToGIDMap /Identity, und
 * zusammengesetzte Glyphen behalten ihre Bestandteile. Was ein PDF-
 * Betrachter nicht braucht (name, post …), fällt weg.
 *
 * Gelesen werden nur die eigenen Schriften aus resources/fonts; trotzdem
 * prüft jeder Zugriff die Grenzen der Datei – eine kaputte Schrift ergibt
 * eine Ausnahme, keine Warnung und keinen falschen Bericht.
 */
final class TrueType
{
    /** Zeichen, das für alles ohne Glyphe steht (nie .notdef). */
    public const ERSATZ = 0x3F;

    public readonly string $psName;
    /** @var array{0:int,1:int,2:int,3:int} in 1/1000 em */
    public readonly array $bbox;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $capHeight;

    private string $daten;
    /** @var array<string,array{0:int,1:int}> Tabelle → [Versatz, Länge] */
    private array $tabellen = [];
    /** @var array<int,int> Unicode → Glyphe */
    private array $cmap = [];
    /** @var list<int> Vorschub je Glyphe in 1/1000 em */
    private array $breiten = [];
    private int $anzahlGlyphen;
    private bool $kurzeLoca;

    /** @var array<string,self> */
    private static array $geladen = [];

    /** Eine Schrift einmal je Prozess lesen. */
    public static function laden(string $datei): self
    {
        return self::$geladen[$datei] ??= new self($datei);
    }

    public function __construct(string $datei)
    {
        $daten = is_file($datei) ? file_get_contents($datei) : false;
        if (!is_string($daten) || strlen($daten) < 12) {
            throw new \RuntimeException('Schrift nicht lesbar: ' . basename($datei));
        }
        $this->daten = $daten;
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            $tag = $this->bytes($o, 4);
            $versatz = $this->u32($o + 8);
            $laenge = $this->u32($o + 12);
            if ($versatz + $laenge > strlen($daten)) {
                throw new \RuntimeException('Schrift beschädigt: Tabelle ' . $tag);
            }
            $this->tabellen[$tag] = [$versatz, $laenge];
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'cmap', 'loca', 'glyf'] as $pflicht) {
            if (!isset($this->tabellen[$pflicht])) {
                throw new \RuntimeException('Schrift unvollständig: ' . $pflicht);
            }
        }

        $head = $this->tabellen['head'][0];
        $einheiten = $this->u16($head + 18);
        if ($einheiten <= 0) {
            throw new \RuntimeException('Schrift beschädigt: unitsPerEm');
        }
        $skala = 1000 / $einheiten;
        $this->bbox = [
            (int) round($this->s16($head + 36) * $skala), (int) round($this->s16($head + 38) * $skala),
            (int) round($this->s16($head + 40) * $skala), (int) round($this->s16($head + 42) * $skala),
        ];
        $this->kurzeLoca = $this->s16($head + 50) === 0;
        $hhea = $this->tabellen['hhea'][0];
        $this->ascent = (int) round($this->s16($hhea + 4) * $skala);
        $this->descent = (int) round($this->s16($hhea + 6) * $skala);
        $metriken = $this->u16($hhea + 34);
        $this->anzahlGlyphen = $this->u16($this->tabellen['maxp'][0] + 4);
        $os2 = $this->tabellen['OS/2'][0] ?? null;
        $this->capHeight = $os2 !== null && $this->u16($os2) >= 2 && $this->tabellen['OS/2'][1] >= 90
            ? (int) round($this->s16($os2 + 88) * $skala) : (int) round($this->ascent * 0.7);

        $hmtx = $this->tabellen['hmtx'][0];
        $letzte = 0;
        for ($g = 0; $g < $this->anzahlGlyphen; $g++) {
            if ($g < $metriken) {
                $letzte = $this->u16($hmtx + 4 * $g);
            }
            $this->breiten[$g] = (int) round($letzte * $skala);
        }
        $this->cmapLesen();
        if (!isset($this->cmap[self::ERSATZ])) {
            throw new \RuntimeException('Schrift ohne Fragezeichen');
        }
        $this->psName = $this->nameLesen();
    }

    /** Glyphe zu einem Unicode-Zeichen – 0, wenn die Schrift es nicht hat. */
    public function glyphe(int $zeichen): int
    {
        return $this->cmap[$zeichen] ?? 0;
    }

    /** Vorschub einer Glyphe in 1/1000 em. */
    public function breite(int $glyphe): int
    {
        return $this->breiten[$glyphe] ?? $this->breiten[0];
    }

    /**
     * Die Schrift mit nur diesen Glyphen (dazu .notdef und die Bestandteile
     * zusammengesetzter Glyphen) – die Daten für FontFile2.
     *
     * @param list<int> $glyphen
     */
    public function teilmenge(array $glyphen): string
    {
        $glyf = $this->tabellen['glyf'][0];
        $behalten = [0 => true];
        $offen = $glyphen;
        while ($offen !== []) {
            $g = (int) array_pop($offen);
            if ($g < 0 || $g >= $this->anzahlGlyphen || (isset($behalten[$g]) && $g !== 0)) {
                continue;
            }
            $behalten[$g] = true;
            [$a, $e] = [$this->ort($g), $this->ort($g + 1)];
            if ($e - $a < 10 || $this->s16($glyf + $a) >= 0) {
                continue;   // leer oder einfache Glyphe
            }
            $p = $glyf + $a + 10;
            do {
                $flags = $this->u16($p);
                $teil = $this->u16($p + 2);
                if (!isset($behalten[$teil])) {
                    $offen[] = $teil;
                }
                $p += 4 + (($flags & 0x0001) ? 4 : 2);
                if ($flags & 0x0008) {
                    $p += 2;
                } elseif ($flags & 0x0040) {
                    $p += 4;
                } elseif ($flags & 0x0080) {
                    $p += 8;
                }
            } while (($flags & 0x0020) && $p < $glyf + $e);
        }

        $neuGlyf = '';
        $neuLoca = '';
        for ($g = 0; $g < $this->anzahlGlyphen; $g++) {
            $neuLoca .= pack('N', strlen($neuGlyf));
            if (isset($behalten[$g])) {
                $a = $this->ort($g);
                $stueck = $this->bytes($glyf + $a, $this->ort($g + 1) - $a);
                $neuGlyf .= $stueck . str_repeat("\0", (4 - strlen($stueck) % 4) % 4);
            }
        }
        $neuLoca .= pack('N', strlen($neuGlyf));

        // head: lange loca, checkSumAdjustment wird unten gesetzt.
        $head = $this->tabelle('head');
        $head = substr_replace($head, "\0\0\0\0", 8, 4);
        $head = substr_replace($head, pack('n', 1), 50, 2);

        $neu = ['glyf' => $neuGlyf, 'head' => $head, 'loca' => $neuLoca];
        foreach (['cmap', 'cvt ', 'fpgm', 'hhea', 'hmtx', 'maxp', 'OS/2', 'prep'] as $tag) {
            if (isset($this->tabellen[$tag])) {
                $neu[$tag] = $this->tabelle($tag);
            }
        }
        ksort($neu, SORT_STRING);

        $anzahl = count($neu);
        $hoch = 1;
        $exp = 0;
        while ($hoch * 2 <= $anzahl) {
            $hoch *= 2;
            $exp++;
        }
        $kopf = pack('Nnnnn', 0x00010000, $anzahl, $hoch * 16, $exp, $anzahl * 16 - $hoch * 16);
        $versatz = 12 + 16 * $anzahl;
        $verzeichnis = '';
        $rumpf = '';
        $kopfVersatz = 0;
        foreach ($neu as $tag => $inhalt) {
            $laenge = strlen($inhalt);
            $inhalt .= str_repeat("\0", (4 - $laenge % 4) % 4);
            if ($tag === 'head') {
                $kopfVersatz = $versatz + strlen($rumpf);
            }
            $verzeichnis .= pack('a4NNN', $tag, self::pruefsumme($inhalt), $versatz + strlen($rumpf), $laenge);
            $rumpf .= $inhalt;
        }
        $schrift = $kopf . $verzeichnis . $rumpf;
        // checkSumAdjustment: 0xB1B0AFBA minus der Prüfsumme der ganzen Datei.
        $anpassung = (0xB1B0AFBA - self::pruefsumme($schrift)) & 0xFFFFFFFF;
        return substr_replace($schrift, pack('N', $anpassung), $kopfVersatz + 8, 4);
    }

    // ------------------------------------------------------------ intern

    private function cmapLesen(): void
    {
        [$cm, $laenge] = $this->tabellen['cmap'];
        $anzahl = $this->u16($cm + 2);
        $kandidaten = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $plattform = $this->u16($cm + 4 + 8 * $i);
            $kodierung = $this->u16($cm + 6 + 8 * $i);
            $versatz = $this->u32($cm + 8 + 8 * $i);
            if ($versatz < $laenge && (($plattform === 3 && $kodierung === 1) || ($plattform === 0 && $kodierung === 3))) {
                $kandidaten[$plattform === 3 ? 0 : 1] = $cm + $versatz;
            }
        }
        ksort($kandidaten);
        foreach ($kandidaten as $t) {
            if ($this->u16($t) !== 4) {
                continue;
            }
            $segX2 = $this->u16($t + 6);
            $enden = $t + 14;
            $anfaenge = $enden + $segX2 + 2;
            $deltas = $anfaenge + $segX2;
            $bereiche = $deltas + $segX2;
            for ($s = 0; $s < intdiv($segX2, 2); $s++) {
                $ende = $this->u16($enden + 2 * $s);
                $anfang = $this->u16($anfaenge + 2 * $s);
                $delta = $this->u16($deltas + 2 * $s);
                $ro = $this->u16($bereiche + 2 * $s);
                for ($c = $anfang; $c <= $ende && $c !== 0xFFFF; $c++) {
                    if ($ro === 0) {
                        $g = ($c + $delta) & 0xFFFF;
                    } else {
                        $g = $this->u16($bereiche + 2 * $s + $ro + 2 * ($c - $anfang));
                        if ($g !== 0) {
                            $g = ($g + $delta) & 0xFFFF;
                        }
                    }
                    if ($g !== 0 && $g < $this->anzahlGlyphen) {
                        $this->cmap[$c] = $g;
                    }
                }
            }
            return;
        }
        throw new \RuntimeException('Schrift ohne Unicode-Tabelle (cmap 3/1, Format 4)');
    }

    /** PostScript-Name (name ID 6), nur Buchstaben, Ziffern und Bindestrich. */
    private function nameLesen(): string
    {
        if (!isset($this->tabellen['name'])) {
            return 'Schrift';
        }
        $nt = $this->tabellen['name'][0];
        $anzahl = $this->u16($nt + 2);
        $speicher = $nt + $this->u16($nt + 4);
        for ($i = 0; $i < $anzahl; $i++) {
            $r = $nt + 6 + 12 * $i;
            if ($this->u16($r) === 3 && $this->u16($r + 6) === 6) {
                $name = mb_convert_encoding($this->bytes($speicher + $this->u16($r + 10), $this->u16($r + 8)), 'UTF-8', 'UTF-16BE');
                $name = (string) preg_replace('/[^A-Za-z0-9-]/', '', $name);
                return $name !== '' ? $name : 'Schrift';
            }
        }
        return 'Schrift';
    }

    /** Versatz einer Glyphe in glyf (aus loca). */
    private function ort(int $g): int
    {
        $loca = $this->tabellen['loca'][0];
        return $this->kurzeLoca ? $this->u16($loca + 2 * $g) * 2 : $this->u32($loca + 4 * $g);
    }

    private function tabelle(string $tag): string
    {
        return $this->bytes($this->tabellen[$tag][0], $this->tabellen[$tag][1]);
    }

    private static function pruefsumme(string $daten): int
    {
        $daten .= str_repeat("\0", (4 - strlen($daten) % 4) % 4);
        $summe = 0;
        foreach (unpack('N*', $daten) ?: [] as $wort) {
            $summe = ($summe + $wort) & 0xFFFFFFFF;
        }
        return $summe;
    }

    private function bytes(int $o, int $n): string
    {
        if ($o < 0 || $n < 0 || $o + $n > strlen($this->daten)) {
            throw new \RuntimeException('Schrift beschädigt (Versatz außerhalb der Datei)');
        }
        return substr($this->daten, $o, $n);
    }

    private function u16(int $o): int
    {
        return unpack('n', $this->bytes($o, 2))[1];
    }

    private function s16(int $o): int
    {
        $v = $this->u16($o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        return unpack('N', $this->bytes($o, 4))[1];
    }
}
