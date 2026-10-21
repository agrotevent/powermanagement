<?php
declare(strict_types=1);

/**
 * Plandateien für die Prüfskripte – schnell erzeugt, ohne Bibliothek.
 *
 * Große Dateien entstehen dünn belegt: Ein PDF bekommt einen ungenutzten
 * Strom, dessen Inhalt ein Loch in der Datei ist (fseek statt schreiben).
 * Die Datei ist so groß wie verlangt, kostet aber weder Zeit noch Platte.
 */

/**
 * Ein gültiges PDF mit $seiten Seiten (A4 quer, zwei Linien je Seite).
 *
 * $groesse > 0: die Datei wird genau so groß (Bytes) – mit einem
 * ungenutzten Strom aus Nullen, den poppler nie liest.
 */
function pruefPdf(string $pfad, int $seiten = 1, int $groesse = 0): void
{
    $fuellung = 0;
    for ($runde = 0; $runde < 4; $runde++) {
        pruefPdfSchreiben($pfad, $seiten, $fuellung);
        clearstatcache(true, $pfad);
        $ist = (int) filesize($pfad);
        if ($groesse <= 0 || $ist === $groesse) {
            return;
        }
        // Der Füllstrom bringt etwas Kopf mit – die ersten Runden gleichen das aus.
        $fuellung = max(1, $fuellung + $groesse - $ist);
    }
    throw new RuntimeException('Prüf-PDF hat nicht die verlangte Größe: ' . filesize($pfad) . ' statt ' . $groesse);
}

function pruefPdfSchreiben(string $pfad, int $seiten, int $fuellung): void
{
    $f = fopen($pfad, 'wb');
    ftruncate($f, 0);
    $versatz = [];
    $obj = static function (int $nr, string $inhalt) use ($f, &$versatz): void {
        $versatz[$nr] = ftell($f);
        fwrite($f, $nr . " 0 obj\n" . $inhalt . "\nendobj\n");
    };
    fwrite($f, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
    $kinder = [];
    for ($i = 0; $i < $seiten; $i++) {
        $kinder[] = (3 + 2 * $i) . ' 0 R';
    }
    $obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
    $obj(2, '<< /Type /Pages /Kids [' . implode(' ', $kinder) . '] /Count ' . $seiten . ' >>');
    for ($i = 0; $i < $seiten; $i++) {
        $inhalt = "0 0 0 RG 3 w 40 40 m 800 555 l S 40 555 m 800 40 l S\n";
        $obj(3 + 2 * $i, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents ' . (4 + 2 * $i) . ' 0 R >>');
        $obj(4 + 2 * $i, '<< /Length ' . strlen($inhalt) . " >>\nstream\n" . $inhalt . "\nendstream");
    }
    $anzahl = 3 + 2 * $seiten;
    if ($fuellung > 0) {
        $versatz[$anzahl] = ftell($f);
        fwrite($f, $anzahl . " 0 obj\n<< /Length " . $fuellung . " >>\nstream\n");
        fseek($f, $fuellung, SEEK_CUR);
        fwrite($f, "\nendstream\nendobj\n");
        $anzahl++;
    }
    $xref = ftell($f);
    fwrite($f, "xref\n0 " . $anzahl . "\n0000000000 65535 f \n");
    for ($i = 1; $i < $anzahl; $i++) {
        fwrite($f, sprintf("%010d 00000 n \n", $versatz[$i]));
    }
    fwrite($f, "trailer\n<< /Size " . $anzahl . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
    fclose($f);
}

/** Ein PNG-Chunk mit Prüfsumme. */
function pruefPngChunk(string $typ, string $daten): string
{
    return pack('N', strlen($daten)) . $typ . $daten . pack('N', crc32($typ . $daten));
}

/**
 * Ein PNG mit Graustufen-Linienraster (Farbtyp 0) in voller Größe – über
 * zlib Zeile für Zeile, ohne gd. 12 000 × 8 000 Pixel brauchen so eine
 * halbe Sekunde und wenige MB.
 */
function pruefPngGrau(string $pfad, int $breite, int $hoehe): void
{
    $f = fopen($pfad, 'wb');
    fwrite($f, "\x89PNG\r\n\x1A\n" . pruefPngChunk('IHDR', pack('NNCCCCC', $breite, $hoehe, 8, 0, 0, 0, 0)));
    $hell = str_repeat("\xF0\xF0\xF0\xF0\xF0\xF0\xF0\x20", intdiv($breite, 8)) . str_repeat("\xF0", $breite % 8);
    $dunkel = str_repeat("\x30", $breite);
    $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 1]);
    $puffer = '';
    for ($y = 0; $y < $hoehe; $y++) {
        $puffer .= deflate_add($z, "\x00" . ($y % 64 === 0 ? $dunkel : $hell), ZLIB_NO_FLUSH);
        if (strlen($puffer) > 1 << 20) {
            fwrite($f, pruefPngChunk('IDAT', $puffer));
            $puffer = '';
        }
    }
    $puffer .= deflate_add($z, '', ZLIB_FINISH);
    fwrite($f, pruefPngChunk('IDAT', $puffer) . pruefPngChunk('IEND', ''));
    fclose($f);
}

/**
 * Nur der Kopf eines PNG (IHDR, ein winziges IDAT, IEND) – für Prüfungen,
 * die nie ein Pixel lesen dürfen: Maße und Art stehen im Kopf.
 */
function pruefPngKopf(string $pfad, int $breite, int $hoehe, int $farbtyp = 2, int $zeilensprung = 0): void
{
    file_put_contents($pfad, "\x89PNG\r\n\x1A\n"
        . pruefPngChunk('IHDR', pack('NNCCCCC', $breite, $hoehe, 8, $farbtyp, 0, 0, $zeilensprung))
        . pruefPngChunk('IDAT', (string) gzcompress("\x00\x00\x00\x00"))
        . pruefPngChunk('IEND', ''));
}

/** Nur der Kopf eines JPEG mit dem Rahmen $sof (0xC0 baseline, 0xC2 progressiv) und $farben Komponenten. */
function pruefJpegKopf(string $pfad, int $breite, int $hoehe, int $sof = 0xC2, int $farben = 3): void
{
    $komponenten = '';
    for ($i = 1; $i <= $farben; $i++) {
        $komponenten .= chr($i) . ($i === 1 ? "\x22" : "\x11") . "\x00";
    }
    $rahmen = "\x08" . pack('nn', $hoehe, $breite) . chr($farben) . $komponenten;
    file_put_contents($pfad, "\xFF\xD8"
        . "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        . "\xFF" . chr($sof) . pack('n', strlen($rahmen) + 2) . $rahmen
        . "\xFF\xD9");
}

/** Hochladen wie aus einem Formular: eine Datei mit Pfad, ohne sie in den Speicher zu holen. */
function pruefHochladen(string $basis, string $dose, string $pfad, array $felder, string $datei, string $name, string $typ, int $sekunden = 180): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => $sekunden,
        CURLOPT_HTTPHEADER     => ['Expect:'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $felder + ['plan' => new CURLFile($datei, $typ, $name)],
    ]);
    $roh = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    preg_match('/^Location:\s*(\S+)/mi', substr($roh, 0, $kopfLaenge), $m);
    return ['status' => $status, 'text' => substr($roh, $kopfLaenge), 'ziel' => $m[1] ?? null];
}
