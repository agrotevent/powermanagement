<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Ein großes Rasterbild als einseitiges PDF – damit poppler es in Streifen
 * rechnet, statt dass gd es ganz in den Speicher holt.
 *
 * gd packt ein Bild immer vollständig aus: vier Byte je Pixel, bei einem
 * Scan mit 139 Megapixeln über 550 MB – und das außerhalb von memory_limit,
 * weil Debians php-gd die libgd des Systems benutzt. Gemessen hielt der
 * PHP-Prozess schon bei einem PNG mit 50 Megapixeln 371 MB. Bis dahin
 * (PlanTileService::MAX_PIXEL) bleibt es deshalb bei gd.
 *
 * Darüber wird das Bild in ein PDF gehüllt, ohne es auszupacken: JPEG
 * kommt als DCTDecode-Strom unverändert hinein, PNG als FlateDecode mit dem
 * PNG-Prädiktor – die IDAT-Daten eines PNG sind genau das. poppler liest
 * beides Zeile für Zeile; gemessen 215 MB bei einem Streifen aus einem
 * JPEG mit 139 Megapixeln. Danach ist es ein PDF-Grundriss wie jeder
 * andere (PlanTileService::schneidenPdf), heruntergerechnet auf MAX_PIXEL.
 *
 * Nicht jedes Bild geht so:
 *  - progressive JPEG: libjpeg hält dafür alle Koeffizienten im Speicher –
 *    gemessen 1 GB bei poppler für dasselbe Bild mit 139 Megapixeln;
 *  - JPEG mit 12 Bit oder CMYK, PNG mit Transparenz oder Zeilensprung:
 *    PDF kennt dafür keinen unveränderten Weg.
 * Solche Bilder über MAX_PIXEL nimmt der Upload nicht an und sagt, was zu
 * tun ist (SiteService::uploadPlan).
 */
final class BildPdf
{
    /** Größte Seitenkante in Punkt, die Acrobat & Co. noch öffnen. */
    private const SEITE_MAX = 14400.0;

    /**
     * Was im Kopf des Bildes steht – und ob es sich einhüllen lässt.
     *
     * Gelesen wird nur der Kopf (JPEG bis zum Bildanfang, PNG die Chunks bis
     * zum ersten IDAT), nie das Bild selbst.
     *
     * @return array{ok:bool,art:string,breite:int,hoehe:int,grund:string}
     *         grund: warum nicht (leer, wenn ok)
     */
    public static function pruefen(string $pfad): array
    {
        $kopf = self::kopf($pfad);
        if ($kopf === null) {
            return ['ok' => false, 'art' => '', 'breite' => 0, 'hoehe' => 0, 'grund' => 'Der Kopf der Bilddatei ist unlesbar.'];
        }
        return ['ok' => $kopf['grund'] === ''] + array_intersect_key($kopf, ['art' => 1, 'breite' => 1, 'hoehe' => 1, 'grund' => 1]);
    }

    /**
     * Das Bild als einseitiges PDF nach $ziel schreiben.
     *
     * Die Bilddaten werden in Blöcken umkopiert – nie ganz gelesen.
     */
    public static function schreiben(string $bild, string $ziel): bool
    {
        $kopf = self::kopf($bild);
        if ($kopf === null || $kopf['grund'] !== '') {
            return false;
        }
        $ein = @fopen($bild, 'rb');
        $aus = @fopen($ziel, 'wb');
        if ($ein === false || $aus === false) {
            is_resource($ein) && fclose($ein);
            is_resource($aus) && fclose($aus);
            return false;
        }

        try {
            // Seite im Seitenverhältnis des Bildes, höchstens SEITE_MAX Punkt.
            $faktor = min(1.0, self::SEITE_MAX / max($kopf['breite'], $kopf['hoehe']));
            $w = round($kopf['breite'] * $faktor, 2);
            $h = round($kopf['hoehe'] * $faktor, 2);

            $versatz = [];
            $schreibe = static function (string $text) use ($aus): void {
                fwrite($aus, $text);
            };
            $objekt = static function (int $nr, string $inhalt) use ($aus, &$versatz, $schreibe): void {
                $versatz[$nr] = ftell($aus);
                $schreibe($nr . " 0 obj\n" . $inhalt . "\nendobj\n");
            };

            $schreibe("%PDF-1.5\n%\xE2\xE3\xCF\xD3\n");
            $objekt(1, '<< /Type /Catalog /Pages 2 0 R >>');
            $objekt(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
            $objekt(3, sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Contents 4 0 R /Resources << /XObject << /Bild 5 0 R >> >> >>',
                self::zahl($w), self::zahl($h)));
            $inhalt = sprintf("q %s 0 0 %s 0 0 cm /Bild Do Q\n", self::zahl($w), self::zahl($h));
            $objekt(4, '<< /Length ' . strlen($inhalt) . " >>\nstream\n" . $inhalt . "\nendstream");

            // Objekt 5: das Bild – Kopf, dann die Daten in Blöcken.
            $versatz[5] = ftell($aus);
            $schreibe("5 0 obj\n<< /Type /XObject /Subtype /Image /Width " . $kopf['breite'] . ' /Height ' . $kopf['hoehe']
                . ' ' . $kopf['pdf'] . ' /Length ' . $kopf['laenge'] . " >>\nstream\n");
            $kopiert = 0;
            foreach ($kopf['bloecke'] as [$von, $laenge]) {
                fseek($ein, $von);
                $rest = $laenge;
                while ($rest > 0) {
                    $stueck = fread($ein, min($rest, 1 << 20));
                    if ($stueck === false || $stueck === '') {
                        return false;
                    }
                    $schreibe($stueck);
                    $rest -= strlen($stueck);
                    $kopiert += strlen($stueck);
                }
            }
            if ($kopiert !== $kopf['laenge']) {
                return false;
            }
            $schreibe("\nendstream\nendobj\n");

            $xref = ftell($aus);
            $schreibe("xref\n0 6\n0000000000 65535 f \n");
            for ($i = 1; $i <= 5; $i++) {
                $schreibe(sprintf("%010d 00000 n \n", $versatz[$i]));
            }
            $schreibe("trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
            return true;
        } finally {
            fclose($ein);
            fclose($aus);
        }
    }

    // ------------------------------------------------------------ Kopf lesen

    /**
     * @return array{art:string,breite:int,hoehe:int,grund:string,pdf:string,laenge:int,bloecke:list<array{int,int}>}|null
     */
    private static function kopf(string $pfad): ?array
    {
        $griff = @fopen($pfad, 'rb');
        if ($griff === false) {
            return null;
        }
        try {
            $anfang = (string) fread($griff, 8);
            if (str_starts_with($anfang, "\xFF\xD8")) {
                return self::jpeg($griff, (int) filesize($pfad));
            }
            if ($anfang === "\x89PNG\r\n\x1A\n") {
                return self::png($griff);
            }
            return null;
        } finally {
            fclose($griff);
        }
    }

    /** @param resource $griff */
    private static function jpeg($griff, int $groesse): ?array
    {
        $ergebnis = ['art' => 'jpeg', 'breite' => 0, 'hoehe' => 0, 'grund' => '', 'pdf' => '', 'laenge' => $groesse, 'bloecke' => [[0, $groesse]]];
        fseek($griff, 2);
        // Segmente bis zum Bildrahmen (SOF) – höchstens 4 MB Kopf (EXIF, ICC).
        while (ftell($griff) < min($groesse, 4 << 20)) {
            $marke = fread($griff, 2);
            if ($marke === false || strlen($marke) < 2 || $marke[0] !== "\xFF") {
                return null;
            }
            $typ = ord($marke[1]);
            if ($typ === 0xFF) {
                fseek($griff, -1, SEEK_CUR);
                continue;
            }
            $laenge = unpack('n', (string) fread($griff, 2))[1] ?? 0;
            if ($laenge < 2) {
                return null;
            }
            // SOF0 … SOF15 außer DHT (C4), JPG (C8) und DAC (CC)
            if ($typ >= 0xC0 && $typ <= 0xCF && !in_array($typ, [0xC4, 0xC8, 0xCC], true)) {
                $sof = (string) fread($griff, 6);
                if (strlen($sof) < 6) {
                    return null;
                }
                $bits = ord($sof[0]);
                $ergebnis['hoehe'] = unpack('n', substr($sof, 1, 2))[1];
                $ergebnis['breite'] = unpack('n', substr($sof, 3, 2))[1];
                $farben = ord($sof[5]);
                if ($ergebnis['breite'] === 0 || $ergebnis['hoehe'] === 0) {
                    return null;
                }
                $ergebnis['grund'] = match (true) {
                    in_array($typ, [0xC2, 0xC6, 0xCA, 0xCE], true) => 'Das JPEG ist progressiv gespeichert – so bräuchte das Aufbereiten über 1 GB Arbeitsspeicher.',
                    !in_array($typ, [0xC0, 0xC1], true) => 'Das JPEG nutzt eine seltene Kodierung (verlustfrei, hierarchisch oder arithmetisch).',
                    $bits !== 8 => 'Das JPEG hat ' . $bits . ' Bit je Farbe – erwartet sind 8.',
                    !in_array($farben, [1, 3], true) => 'Das JPEG ist in CMYK gespeichert – erwartet ist RGB oder Graustufen.',
                    default => '',
                };
                // Ob YCbCr oder RGB, liest poppler wie jeder JPEG-Leser aus
                // den Markern (JFIF, Adobe) – hier ist nichts umzurechnen.
                $ergebnis['pdf'] = '/ColorSpace /' . ($farben === 1 ? 'DeviceGray' : 'DeviceRGB') . ' /BitsPerComponent 8 /Filter /DCTDecode';
                return $ergebnis;
            }
            fseek($griff, $laenge - 2, SEEK_CUR);
        }
        return null;
    }

    /** @param resource $griff */
    private static function png($griff): ?array
    {
        $ergebnis = ['art' => 'png', 'breite' => 0, 'hoehe' => 0, 'grund' => '', 'pdf' => '', 'laenge' => 0, 'bloecke' => []];
        $tiefe = $typ = $zeilensprung = 0;
        $palette = '';
        $durchsichtig = false;
        $gesehen = false;
        while (true) {
            $kopf = fread($griff, 8);
            if ($kopf === false || strlen($kopf) < 8) {
                break;
            }
            $laenge = unpack('N', substr($kopf, 0, 4))[1];
            $name = substr($kopf, 4, 4);
            $daten = ftell($griff);
            if ($name === 'IHDR') {
                $ihdr = (string) fread($griff, 13);
                if (strlen($ihdr) < 13) {
                    return null;
                }
                $ergebnis['breite'] = unpack('N', substr($ihdr, 0, 4))[1];
                $ergebnis['hoehe'] = unpack('N', substr($ihdr, 4, 4))[1];
                $tiefe = ord($ihdr[8]);
                $typ = ord($ihdr[9]);
                $zeilensprung = ord($ihdr[12]);
                $gesehen = true;
            } elseif ($name === 'PLTE' && $laenge <= 768) {
                $palette = (string) fread($griff, $laenge);
            } elseif ($name === 'tRNS') {
                $durchsichtig = true;
            } elseif ($name === 'IDAT') {
                $ergebnis['bloecke'][] = [$daten, $laenge];
                $ergebnis['laenge'] += $laenge;
            } elseif ($name === 'IEND') {
                break;
            }
            fseek($griff, $daten + $laenge + 4);
        }
        if (!$gesehen || $ergebnis['breite'] === 0 || $ergebnis['hoehe'] === 0 || $ergebnis['laenge'] === 0) {
            return null;
        }
        $farben = match ($typ) {
            0, 3 => 1,
            2 => 3,
            default => 0,
        };
        $ergebnis['grund'] = match (true) {
            $zeilensprung !== 0 => 'Das PNG ist mit Zeilensprung (interlaced) gespeichert.',
            $typ === 4 || $typ === 6 || $durchsichtig => 'Das PNG hat Transparenz.',
            $farben === 0 => 'Das PNG hat einen unbekannten Farbtyp.',
            $typ === 3 && $palette === '' => 'Dem PNG fehlt die Farbpalette.',
            !in_array($tiefe, [1, 2, 4, 8, 16], true) || ($typ === 2 && $tiefe < 8) => 'Das PNG hat eine unerwartete Farbtiefe.',
            default => '',
        };
        $raum = match ($typ) {
            0 => '/DeviceGray',
            2 => '/DeviceRGB',
            default => '[/Indexed /DeviceRGB ' . max(0, intdiv(strlen($palette), 3) - 1) . ' <' . bin2hex($palette) . '>]',
        };
        $ergebnis['pdf'] = '/ColorSpace ' . $raum . ' /BitsPerComponent ' . $tiefe . ' /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 15 /Colors ' . $farben . ' /BitsPerComponent ' . $tiefe . ' /Columns ' . $ergebnis['breite'] . ' >>';
        return $ergebnis;
    }

    private static function zahl(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 2, '.', ''), '0'), '.');
    }
}
