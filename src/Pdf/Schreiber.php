<?php
declare(strict_types=1);

namespace App\Pdf;

/**
 * Ein kleiner PDF-Schreiber ohne Bibliothek: A4 hoch, Koordinaten in mm
 * von oben links, Text in Liberation Sans (eingebettet als Teilmenge,
 * Type0/Identity-H mit ToUnicode – Ω, Δ, ≤, ², µ lassen sich kopieren),
 * Linien, Rechtecke, Kästchen und Unterschriften als Vektorpfad. Bilder
 * gibt es nicht.
 *
 * Reproduzierbar: dieselben Aufrufe ergeben dieselben Bytes – das Datum
 * und die Dokument-ID kommen von außen (ausgabe()), es gibt weder Uhr
 * noch Zufall. Text steht im Inhalt nur als Glyphen-Nummern in Hex –
 * Klammern, Backslash oder „<script>“ aus Benutzereingaben können den
 * Datenstrom nicht stören. Zeichen ohne Glyphe werden „?“ (nie .notdef),
 * Steuerzeichen fallen weg.
 */
final class Schreiber
{
    public const BREITE = 210.0;
    public const HOEHE = 297.0;
    /** Punkte je Millimeter. */
    private const PT = 72 / 25.4;
    private const STIL = ['R' => 'LiberationSans-Regular.ttf', 'B' => 'LiberationSans-Bold.ttf'];

    /** @var array<string,TrueType> */
    private array $schriften = [];
    /** @var array<string,array<int,int>> je Stil: Glyphe → Unicode (für ToUnicode) */
    private array $benutzt = ['R' => [], 'B' => []];
    /** @var list<string> Inhalt je Seite */
    private array $seiten = [];
    private int $seite = -1;
    /** @var array{hex:string,pt:float,breite:float}|null */
    private ?array $wasserzeichen = null;

    /** @param array<string,string> $info Title, Author, Subject (UTF-8) */
    public function __construct(private array $info = [])
    {
        $ordner = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/resources/fonts/';
        foreach (self::STIL as $stil => $datei) {
            $this->schriften[$stil] = TrueType::laden($ordner . $datei);
        }
    }

    // ------------------------------------------------------------ Seiten

    /** Eine neue Seite (A4 hoch); alles Weitere landet dort. */
    public function seite(): void
    {
        $this->seiten[] = '';
        $this->seite = count($this->seiten) - 1;
    }

    public function seitenzahl(): int
    {
        return count($this->seiten);
    }

    /** Auf eine frühere Seite zurück (0 = erste) – für Fußzeilen und Verweise. */
    public function aufSeite(int $index): void
    {
        if (!isset($this->seiten[$index])) {
            throw new \OutOfRangeException('Diese Seite gibt es nicht.');
        }
        $this->seite = $index;
    }

    public function aktuelleSeite(): int
    {
        return $this->seite;
    }

    // ------------------------------------------------------------ Text

    /** Text ab (x, Grundlinie y). */
    public function text(float $x, float $y, string $s, float $pt, bool $fett = false, float $grau = 0.0): void
    {
        $hex = $this->glyphen($s, $fett ? 'B' : 'R');
        if ($hex === '') {
            return;
        }
        $farbe = $grau > 0 ? self::z($grau) . ' g ' : '';
        $this->aus('BT ' . $farbe . '/F' . ($fett ? 'B' : 'R') . ' ' . self::z($pt) . ' Tf ' . $this->x($x) . ' ' . $this->y($y) . ' Td <' . $hex . '> Tj ET'
            . ($farbe !== '' ? ' 0 g' : ''));
    }

    /** Text rechtsbündig an x. */
    public function textRechts(float $x, float $y, string $s, float $pt, bool $fett = false, float $grau = 0.0): void
    {
        $this->text($x - $this->breite($s, $pt, $fett), $y, $s, $pt, $fett, $grau);
    }

    /** Text mittig um x. */
    public function textMitte(float $x, float $y, string $s, float $pt, bool $fett = false, float $grau = 0.0): void
    {
        $this->text($x - $this->breite($s, $pt, $fett) / 2, $y, $s, $pt, $fett, $grau);
    }

    /** Breite eines Textes in mm. */
    public function breite(string $s, float $pt, bool $fett = false): float
    {
        $schrift = $this->schriften[$fett ? 'B' : 'R'];
        $summe = 0;
        foreach (self::zeichen($s) as $z) {
            $g = $schrift->glyphe(mb_ord($z));
            $summe += $schrift->breite($g !== 0 ? $g : $schrift->glyphe(TrueType::ERSATZ));
        }
        return $summe / 1000 * $pt / self::PT;
    }

    /**
     * Text mit tiefgestellten Teilen: „R_{iso}“, „U_{B}“, „IΔ_{n}“ – die
     * Indizes kleiner und etwas tiefer.
     */
    public function textIndex(float $x, float $y, string $s, float $pt, bool $fett = false): void
    {
        foreach (self::indexTeile($s) as [$teil, $tief]) {
            $groesse = $tief ? $pt * 0.72 : $pt;
            $this->text($x, $tief ? $y + $pt * 0.09 : $y, $teil, $groesse, $fett);
            $x += $this->breite($teil, $groesse, $fett);
        }
    }

    public function breiteIndex(string $s, float $pt, bool $fett = false): float
    {
        $summe = 0.0;
        foreach (self::indexTeile($s) as [$teil, $tief]) {
            $summe += $this->breite($teil, $tief ? $pt * 0.72 : $pt, $fett);
        }
        return $summe;
    }

    /**
     * Text in Zeilen von höchstens $mm Breite. Absätze (Zeilenumbrüche)
     * bleiben; ein Wort, das allein zu breit ist, wird nach Zeichen geteilt.
     *
     * @return list<string> mindestens eine (vielleicht leere) Zeile
     */
    public function umbruch(string $s, float $mm, float $pt, bool $fett = false): array
    {
        $zeilen = [];
        $leer = $this->breite(' ', $pt, $fett);
        foreach (preg_split('/\R/u', $s) ?: [''] as $absatz) {
            $zeile = '';
            $breite = 0.0;
            foreach (preg_split('/[ \t]+/u', trim($absatz)) ?: [] as $wort) {
                if ($wort === '') {
                    continue;
                }
                $w = $this->breite($wort, $pt, $fett);
                if ($zeile !== '' && $breite + $leer + $w <= $mm) {
                    $zeile .= ' ' . $wort;
                    $breite += $leer + $w;
                    continue;
                }
                if ($zeile !== '') {
                    $zeilen[] = $zeile;
                }
                while ($w > $mm && mb_strlen($wort) > 1) {
                    [$stueck, $wort] = $this->teilen($wort, $mm, $pt, $fett);
                    $zeilen[] = $stueck;
                    $w = $this->breite($wort, $pt, $fett);
                }
                $zeile = $wort;
                $breite = $w;
            }
            $zeilen[] = $zeile;
        }
        return $zeilen;
    }

    // ------------------------------------------------------------ Grafik

    public function linie(float $x1, float $y1, float $x2, float $y2, float $staerke = 0.2, float $grau = 0.0): void
    {
        $this->aus('q ' . self::z($staerke * self::PT) . ' w ' . self::z($grau) . ' G ' . $this->x($x1) . ' ' . $this->y($y1) . ' m '
            . $this->x($x2) . ' ' . $this->y($y2) . ' l S Q');
    }

    /**
     * Rechteck ab (x, y) oben links. $flaeche: Grauwert der Füllung (1 = weiß)
     * oder null für keine Füllung; $rahmen: Rand zeichnen.
     */
    public function rechteck(float $x, float $y, float $b, float $h, ?float $flaeche = null, bool $rahmen = true, float $staerke = 0.2): void
    {
        if ($flaeche === null && !$rahmen) {
            return;
        }
        $op = $flaeche !== null ? ($rahmen ? 'B' : 'f') : 'S';
        $this->aus('q ' . self::z($staerke * self::PT) . ' w 0 G ' . ($flaeche !== null ? self::z($flaeche) . ' g ' : '')
            . $this->x($x) . ' ' . $this->y($y + $h) . ' ' . self::z($b * self::PT) . ' ' . self::z($h * self::PT) . ' re ' . $op . ' Q');
    }

    /**
     * Ein Kästchen (Kante in mm) ab (x, y) oben links: io → Haken, nio und
     * x → Kreuz, entf → „entf.“, sonst leer. Haken und Kreuz sind Pfade –
     * die Schrift hat dafür keine Glyphen.
     */
    public function kaestchen(float $x, float $y, string $zustand, float $kante = 4.0): void
    {
        $this->rechteck($x, $y, $kante, $kante, null, true, 0.25);
        match ($zustand) {
            'io'       => $this->symbol($x, $y, 'haken', $kante),
            'nio', 'x' => $this->symbol($x, $y, 'kreuz', $kante),
            'entf'     => $this->textMitte($x + $kante / 2, $y + $kante * 0.66, 'entf.', $kante * 1.15),
            default    => null,
        };
    }

    /** Haken oder Kreuz als Pfad in einem Quadrat der Kante $kante ab (x, y). */
    public function symbol(float $x, float $y, string $art, float $kante): void
    {
        $p = fn (float $fx, float $fy): string => $this->x($x + $fx * $kante) . ' ' . $this->y($y + $fy * $kante);
        $staerke = self::z(max(0.25, $kante * 0.09) * self::PT);
        if ($art === 'haken') {
            $this->aus('q ' . $staerke . ' w 1 J 1 j 0 G ' . $p(0.2, 0.55) . ' m ' . $p(0.42, 0.78) . ' l ' . $p(0.82, 0.22) . ' l S Q');
        } elseif ($art === 'kreuz') {
            $this->aus('q ' . $staerke . ' w 1 J 0 G ' . $p(0.22, 0.22) . ' m ' . $p(0.78, 0.78) . ' l ' . $p(0.78, 0.22) . ' m ' . $p(0.22, 0.78) . ' l S Q');
        }
    }

    /**
     * Eine Unterschrift als Vektorpfad: Striche aus ganzen Zahlen 0..10000
     * auf einer Fläche 3:1 (Unterschrift::pruefen), eingepasst in das
     * Rechteck (x, y, b, h) – links ausgerichtet, Seitenverhältnis bleibt.
     * Geglättet wie im Browser: quadratische Kurven durch die Mittelpunkte.
     *
     * @param list<list<array{0:int,1:int}>> $striche
     */
    public function unterschrift(array $striche, float $x, float $y, float $b, float $h): void
    {
        $hoehe = min($h, $b / 3);
        $breite = $hoehe * 3;
        $y += ($h - $hoehe) / 2;
        $punkt = static fn (mixed $q): array => [
            $x + max(0, min(10000, (int) ($q[0] ?? 0))) / 10000 * $breite,
            $y + max(0, min(10000, (int) ($q[1] ?? 0))) / 10000 * $hoehe,
        ];
        $ops = '';
        foreach ($striche as $strich) {
            if (!is_array($strich) || $strich === []) {
                continue;
            }
            $p = array_map($punkt, array_values(array_filter($strich, 'is_array')));
            if ($p === []) {
                continue;
            }
            $n = count($p);
            $ops .= $this->x($p[0][0]) . ' ' . $this->y($p[0][1]) . ' m ';
            if ($n === 1) {
                $ops .= $this->x($p[0][0]) . ' ' . $this->y($p[0][1]) . ' l S ';
                continue;
            }
            [$ax, $ay] = $p[0];
            for ($i = 1; $i < $n - 1; $i++) {
                [$cx, $cy] = $p[$i];
                [$mx, $my] = [($p[$i][0] + $p[$i + 1][0]) / 2, ($p[$i][1] + $p[$i + 1][1]) / 2];
                // quadratisch (a, c, m) als kubische Kurve
                $ops .= $this->x($ax + 2 / 3 * ($cx - $ax)) . ' ' . $this->y($ay + 2 / 3 * ($cy - $ay)) . ' '
                    . $this->x($mx + 2 / 3 * ($cx - $mx)) . ' ' . $this->y($my + 2 / 3 * ($cy - $my)) . ' ' . $this->x($mx) . ' ' . $this->y($my) . ' c ';
                [$ax, $ay] = [$mx, $my];
            }
            $ops .= $this->x($p[$n - 1][0]) . ' ' . $this->y($p[$n - 1][1]) . ' l S ';
        }
        if ($ops !== '') {
            $this->aus('q 0.06 0.14 0.43 RG 1 J 1 j ' . self::z(max(0.3, $hoehe * 0.016) * self::PT) . ' w ' . $ops . 'Q');
        }
    }

    /** Ein diagonaler Schriftzug in hellem Grau auf jeder Seite, unter dem Inhalt (Vorschau). */
    public function wasserzeichen(string $text): void
    {
        $pt = 40.0;
        $breite = $this->breite($text, $pt, true);
        if ($breite > 250) {
            $pt *= 250 / $breite;
            $breite = 250.0;
        }
        $this->wasserzeichen = ['hex' => $this->glyphen($text, 'B'), 'pt' => $pt, 'breite' => $breite];
    }

    // ------------------------------------------------------------ Ausgabe

    /**
     * Das fertige PDF. $erzeugtAm (ISO 8601) wird CreationDate und ModDate,
     * $idHex (32 Hexzeichen) die Dokument-ID – beides fest, damit zweimal
     * erzeugt dieselben Bytes ergibt.
     */
    public function ausgabe(string $erzeugtAm, string $idHex): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $idHex) !== 1) {
            throw new \InvalidArgumentException('Die Dokument-ID muss aus 32 Hexzeichen bestehen.');
        }
        $zeit = strtotime($erzeugtAm);
        if ($zeit === false) {
            throw new \InvalidArgumentException('Das Datum des PDF ist nicht lesbar.');
        }
        if ($this->seiten === []) {
            $this->seite();
        }
        $objekte = [];
        $neu = static function () use (&$objekte): int {
            $objekte[] = '';
            return count($objekte);
        };
        $setze = static function (int $nr, string $inhalt) use (&$objekte): void {
            $objekte[$nr - 1] = $inhalt;
        };
        $strom = static fn (string $dict, string $daten): string => '<< ' . $dict . ($dict !== '' ? ' ' : '') . '/Length ' . strlen($daten) . " >>\nstream\n" . $daten . "\nendstream";

        $katalog = $neu();
        $seitenBaum = $neu();

        $schriftRes = '';
        foreach (['R', 'B'] as $stil) {
            if ($this->benutzt[$stil] === []) {
                continue;
            }
            $s = $this->schriften[$stil];
            $benutzt = $this->benutzt[$stil];
            ksort($benutzt);
            // Kennung der Teilmenge: sechs Großbuchstaben aus den benutzten Glyphen.
            $kennung = strtr(substr(hash('sha256', $stil . ':' . implode(',', array_keys($benutzt))), 0, 6), '0123456789abcdef', 'ABCDEFGHIJKLMNOP');
            $name = $kennung . '+' . $s->psName;
            $teil = $s->teilmenge(array_keys($benutzt));
            $datei = $neu();
            $setze($datei, $strom('/Filter /FlateDecode /Length1 ' . strlen($teil), (string) gzcompress($teil, 9)));
            $beschreibung = $neu();
            $setze($beschreibung, sprintf(
                '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
                $name, $s->bbox[0], $s->bbox[1], $s->bbox[2], $s->bbox[3], $s->ascent, $s->descent, $s->capHeight, $stil === 'B' ? 120 : 80, $datei
            ));
            $w = '';
            foreach ($benutzt as $g => $_) {
                $w .= $g . ' [' . $s->breite($g) . '] ';
            }
            $cid = $neu();
            $setze($cid, '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $name . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                . ' /FontDescriptor ' . $beschreibung . ' 0 R /DW 1000 /W [ ' . $w . '] /CIDToGIDMap /Identity >>');
            $tu = $neu();
            $setze($tu, $strom('/Filter /FlateDecode', (string) gzcompress(self::toUnicode($benutzt), 9)));
            $f = $neu();
            $setze($f, '<< /Type /Font /Subtype /Type0 /BaseFont /' . $name . ' /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R] /ToUnicode ' . $tu . ' 0 R >>');
            $schriftRes .= '/F' . $stil . ' ' . $f . ' 0 R ';
        }
        $ressourcen = $neu();
        $setze($ressourcen, '<< /Font << ' . $schriftRes . '>> /ProcSet [/PDF /Text] >>');

        $kinder = [];
        $format = '[0 0 ' . self::z(self::BREITE * self::PT) . ' ' . self::z(self::HOEHE * self::PT) . ']';
        foreach ($this->seiten as $inhalt) {
            if ($this->wasserzeichen !== null) {
                $inhalt = $this->wasserzeichenOps() . $inhalt;
            }
            $c = $neu();
            $setze($c, $strom('/Filter /FlateDecode', (string) gzcompress($inhalt, 9)));
            $p = $neu();
            $setze($p, '<< /Type /Page /Parent ' . $seitenBaum . ' 0 R /MediaBox ' . $format . ' /Resources ' . $ressourcen . ' 0 R /Contents ' . $c . ' 0 R >>');
            $kinder[] = $p . ' 0 R';
        }
        $setze($seitenBaum, '<< /Type /Pages /Kids [' . implode(' ', $kinder) . '] /Count ' . count($kinder) . ' >>');
        $setze($katalog, '<< /Type /Catalog /Pages ' . $seitenBaum . ' 0 R /Lang (de-DE) >>');

        $datum = '(D:' . gmdate('YmdHis', $zeit) . 'Z)';
        $info = '';
        foreach (['Title', 'Author', 'Subject'] as $schluessel) {
            $wert = trim((string) ($this->info[$schluessel] ?? ''));
            if ($wert !== '') {
                $info .= '/' . $schluessel . ' ' . self::textString($wert) . ' ';
            }
        }
        $infoNr = $neu();
        $setze($infoNr, '<< ' . $info . '/Creator (PowerManagement) /Producer (pm-pdf-1) /CreationDate ' . $datum . ' /ModDate ' . $datum . ' >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $versatz = [];
        foreach ($objekte as $i => $o) {
            $versatz[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objekte) + 1) . "\n0000000000 65535 f \n";
        foreach ($versatz as $v) {
            $pdf .= sprintf("%010d 00000 n \n", $v);
        }
        return $pdf . "trailer\n<< /Size " . (count($objekte) + 1) . ' /Root ' . $katalog . ' 0 R /Info ' . $infoNr . ' 0 R /ID [<' . $idHex . '> <' . $idHex . ">] >>\n"
            . "startxref\n" . $xref . "\n%%EOF\n";
    }

    // ------------------------------------------------------------ intern

    /** Die Zeichen eines Textes – gültiges UTF-8, ohne Steuerzeichen, Tabulator als Leerzeichen. */
    private static function zeichen(string $s): array
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = strtr($s, ["\t" => ' ', "\r" => '', "\n" => ' ']);
        $s = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $s);
        return $s === '' ? [] : mb_str_split($s);
    }

    /** Glyphen eines Textes als Hex (4 Zeichen je Glyphe); merkt sie für Teilmenge und ToUnicode. */
    private function glyphen(string $s, string $stil): string
    {
        $schrift = $this->schriften[$stil];
        $hex = '';
        foreach (self::zeichen($s) as $z) {
            $u = mb_ord($z);
            $g = $schrift->glyphe($u);
            if ($g === 0) {
                $u = TrueType::ERSATZ;
                $g = $schrift->glyphe($u);
            }
            $this->benutzt[$stil][$g] ??= $u;
            $hex .= sprintf('%04X', $g);
        }
        return $hex;
    }

    /** @return list<array{0:string,1:bool}> [Teil, tiefgestellt] */
    private static function indexTeile(string $s): array
    {
        $teile = [];
        foreach (preg_split('/(_\{[^}]*\})/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            $teile[] = str_starts_with($t, '_{') ? [substr($t, 2, -1), true] : [$t, false];
        }
        return $teile;
    }

    /** @return array{0:string,1:string} [was passt, Rest] – mindestens ein Zeichen */
    private function teilen(string $wort, float $mm, float $pt, bool $fett): array
    {
        $zeichen = mb_str_split($wort);
        $stueck = '';
        foreach ($zeichen as $i => $z) {
            if ($stueck !== '' && $this->breite($stueck . $z, $pt, $fett) > $mm) {
                return [$stueck, implode('', array_slice($zeichen, $i))];
            }
            $stueck .= $z;
        }
        return [$stueck, ''];
    }

    private function wasserzeichenOps(): string
    {
        $w = $this->wasserzeichen;
        $winkel = deg2rad(54.7);   // etwa die Diagonale von A4
        $c = self::z(cos($winkel));
        $s = self::z(sin($winkel));
        $mx = self::z(self::BREITE / 2 * self::PT);
        $my = self::z(self::HOEHE / 2 * self::PT);
        return 'q 0.86 g 1 0 0 1 ' . $mx . ' ' . $my . ' cm ' . $c . ' ' . $s . ' -' . $s . ' ' . $c . ' 0 0 cm BT /FB ' . self::z($w['pt']) . ' Tf '
            . self::z(-$w['breite'] / 2 * self::PT) . ' ' . self::z(-$w['pt'] * 0.35) . ' Td <' . $w['hex'] . "> Tj ET Q\n";
    }

    /** @param array<int,int> $benutzt Glyphe → Unicode, sortiert */
    private static function toUnicode(array $benutzt): string
    {
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($benutzt, 100, true) as $block) {
            $cmap .= count($block) . " beginbfchar\n";
            foreach ($block as $g => $u) {
                $cmap .= sprintf("<%04X> <%s>\n", $g, strtoupper(bin2hex(mb_convert_encoding(mb_chr($u, 'UTF-8'), 'UTF-16BE', 'UTF-8'))));
            }
            $cmap .= "endbfchar\n";
        }
        return $cmap . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    /** Textzeichenkette im Info-Wörterbuch: UTF-16BE mit BOM, als Hex. */
    private static function textString(string $s): string
    {
        $s = implode('', self::zeichen($s));
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    private function aus(string $op): void
    {
        if ($this->seite < 0) {
            $this->seite();
        }
        $this->seiten[$this->seite] .= $op . "\n";
    }

    private static function z(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private function x(float $mm): string
    {
        return self::z($mm * self::PT);
    }

    private function y(float $mm): string
    {
        return self::z((self::HOEHE - $mm) * self::PT);
    }
}
