<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;
use App\Support\Programm;

/**
 * PDF-Grundrisse zu Rasterstreifen rechnen.
 *
 * Vorgeschichte: gekachelt wurde ein PDF bisher im Browser mit pdf.js –
 * jede Kachel bekam ihren Ausschnitt direkt aus den Vektordaten. Auf dem
 * Papier ist das die schönere Lösung (auf jeder Zoomstufe scharf, kein
 * Werkzeug auf dem Server). In der Praxis scheitert sie an einer Grenze,
 * die kein Fehler meldet: pdf.js bekommt den Ausschnitt über einen
 * Viewport der *ganzen* Seite in Kachelauflösung. Auf der tiefsten Stufe
 * eines A3-Plans sind das über eine Milliarde Pixel. Legt pdf.js dafür
 * eine Zwischenfläche an – bei Transparenz und Weichmasken tut es das –,
 * verweigert der Browser sie stillschweigend und die Kachel bleibt weiß.
 * Safari ist dabei mit rund 16,8 Megapixeln je Canvas deutlich strenger
 * als Chrome und steigt entsprechend früher aus.
 *
 * Hier wird der Plan deshalb einmalig auf dem Server gerastert. Danach ist
 * ein PDF-Grundriss genau das, was ein JPEG-Grundriss schon immer war: ein
 * Stapel gewöhnlicher Bildkacheln. Kein Modul, kein Worker, keine
 * Canvas-Grenze – das läuft in jedem Browser.
 *
 * Gerechnet wird streifenweise: poppler rendert einen Ausschnitt über die
 * volle Breite und einige Kachelzeilen, der wird geschnitten, dann der
 * nächste. Damit liegt nie mehr als ein Streifen im Speicher (höchstens
 * PlanTileService::STREIFEN_PIXEL) statt der ganzen Seite.
 */
final class PdfRasterService
{
    /** Wie lange poppler für einen Aufruf höchstens brauchen darf (Sekunden). */
    private const ZEITGRENZE = 120;

    /** Wie viel von stdout höchstens gelesen wird. */
    private const HOECHSTENS = 1_048_576;

    /**
     * Lange Kante des gerasterten Plans in Pixeln, wenn pdfinfo die
     * Seitengröße nicht nennt (sonst PlanTileService::ziel()).
     *
     * Bei A3 sind das rund 370 dpi – deutlich mehr, als ein Bildschirm auf
     * der tiefsten Zoomstufe zeigt.
     */
    public const KANTE = 6144;


    /**
     * Welches Werkzeug rastert hier PDF – und wenn keines, warum nicht.
     *
     * Bewusst keine Bibliothek, sondern ein Programm: Imagick braucht für
     * PDF ohnehin Ghostscript und ist in Debians Vorgabe-policy.xml für
     * PDF gesperrt. poppler-utils ist klein, ohne Zusatzdienste und auf
     * einem LXC in Sekunden nachinstalliert.
     *
     * @return array{werkzeug:?string,pfad:?string,hinweis:string}
     */
    public static function werkzeug(): array
    {
        foreach (['pdftoppm', 'pdftocairo'] as $name) {
            $pfad = self::finden($name);
            if ($pfad !== null) {
                return ['werkzeug' => $name, 'pfad' => $pfad, 'hinweis' => ''];
            }
        }

        return [
            'werkzeug' => null,
            'pfad'     => null,
            'hinweis'  => 'Zum Rastern von PDF-Grundrissen fehlt poppler-utils. '
                . 'Auf dem Server nachinstallieren: apt-get install -y poppler-utils',
        ];
    }

    public static function verfuegbar(): bool
    {
        return self::werkzeug()['werkzeug'] !== null;
    }

    /**
     * Ein Programm suchen, ohne eine Shell zu bemühen.
     *
     * Über Programm::finden(), weil der PHP-FPM-Pool open_basedir setzt:
     * dort sah is_file('/usr/bin/pdftoppm') nichts, und die Oberfläche
     * hielt poppler-utils für fehlend, obwohl es installiert war.
     * poppler-Werkzeuge melden ihre Version mit „-v“ und enden mit 0.
     */
    private static function finden(string $name): ?string
    {
        return Programm::finden($name, ['-v']);
    }

    // ------------------------------------------------------------ Rastern

    /**
     * Einen Ausschnitt der Seite als PNG-Datei rendern.
     *
     * Die Maße beziehen sich auf die Seite, die auf `$kante` skaliert ist.
     * `$hoehe` darf über den unteren Rand hinausreichen – poppler schneidet
     * dann ab und liefert einen niedrigeren Streifen.
     *
     * Vorsicht bei `$y`: liegt der Wert *hinter* dem Seitenende, ignoriert
     * poppler den Ausschnitt und rendert die ganze Seite. Ein Streifen, der
     * höher zurückkommt als angefordert, ist deshalb kein Streifen, sondern
     * genau dieser Fall – der Aufrufer muss ihn verwerfen.
     *
     * 'zeit' ist true, wenn poppler die Zeitgrenze überschritten hat – ein
     * niedrigerer Streifen kann dann noch gehen.
     *
     * @return array{ok:bool,pfad:?string,breite:int,hoehe:int,message:string,zeit?:bool}
     */
    public static function streifen(
        string $pdf,
        int $seite,
        int $kante,
        int $y,
        int $hoehe,
        string $zielOhneEndung
    ): array {
        $w = self::werkzeug();
        if ($w['pfad'] === null) {
            return ['ok' => false, 'pfad' => null, 'breite' => 0, 'hoehe' => 0, 'message' => $w['hinweis']];
        }

        $argumente = [
            $w['pfad'],
            '-f', (string) $seite,
            '-l', (string) $seite,
            '-scale-to', (string) $kante,
            '-x', '0',
            '-y', (string) max(0, $y),
            // Absichtlich zu breit: poppler klemmt auf die Seitenbreite.
            // So muss die Breite nicht vorher ausgerechnet werden.
            '-W', (string) ($kante * 2),
            '-H', (string) max(1, $hoehe),
            '-png',
            '-r', '0',
            $pdf,
            $zielOhneEndung,
        ];

        $ergebnis = self::starten($argumente);
        if (!$ergebnis['ok']) {
            return ['ok' => false, 'pfad' => null, 'breite' => 0, 'hoehe' => 0, 'message' => $ergebnis['message'], 'zeit' => !empty($ergebnis['zeit'])];
        }

        // poppler hängt die Seitennummer an: ziel-1.png, ziel-01.png, …
        $treffer = glob($zielOhneEndung . '*.png') ?: [];
        if ($treffer === []) {
            return ['ok' => false, 'pfad' => null, 'breite' => 0, 'hoehe' => 0, 'message' => 'Kein Bild erzeugt.'];
        }
        $pfad  = $treffer[0];
        $masse = @getimagesize($pfad);
        if ($masse === false) {
            @unlink($pfad);
            return ['ok' => false, 'pfad' => null, 'breite' => 0, 'hoehe' => 0, 'message' => 'Erzeugtes Bild ist unlesbar.'];
        }

        return [
            'ok'      => true,
            'pfad'    => $pfad,
            'breite'  => (int) $masse[0],
            'hoehe'   => (int) $masse[1],
            'message' => '',
        ];
    }

    /**
     * Maße der gerasterten Seite, ohne sie in voller Größe zu rendern.
     *
     * Dafür wird die Seite auf eine Briefmarke gerechnet; das Verhältnis
     * stimmt, der Rest ist Dreisatz. Die wirkliche Höhe stellt sich beim
     * Schneiden heraus und wird dort nachgezogen.
     *
     * @return array{breite:int,hoehe:int}|null
     */
    public static function masse(string $pdf, int $seite, int $kante = self::KANTE): ?array
    {
        $verhaeltnis = self::verhaeltnis($pdf, $seite);
        return $verhaeltnis === null ? null : self::masseAus($verhaeltnis, $kante);
    }

    /** Höhe durch Breite der Seite – aus der Briefmarke (siehe masse()). */
    public static function verhaeltnis(string $pdf, int $seite): ?float
    {
        $ziel = self::tempName('masse');
        $klein = self::streifen($pdf, $seite, 256, 0, 4096, $ziel);
        self::aufraeumen($ziel);
        if (!$klein['ok'] || $klein['breite'] === 0) {
            return null;
        }
        return $klein['hoehe'] / $klein['breite'];
    }

    /**
     * Maße der auf $kante gerechneten Seite aus ihrem Verhältnis (Höhe/Breite).
     *
     * @return array{breite:int,hoehe:int}
     */
    public static function masseAus(float $verhaeltnis, int $kante): array
    {
        // Quer wie hoch: die lange Kante bekommt die Zielauflösung.
        if ($verhaeltnis <= 1.0) {
            $breite = $kante;
            $hoehe  = max(1, (int) round($kante * $verhaeltnis));
        } else {
            $hoehe  = $kante;
            $breite = max(1, (int) round($kante / $verhaeltnis));
        }

        return ['breite' => $breite, 'hoehe' => $hoehe];
    }

    /**
     * Seitengröße in Punkt, mit Drehung – so, wie die Seite gedruckt wird.
     *
     * @return array{breite:float,hoehe:float}|null
     */
    public static function punkte(string $pdf, int $seite): ?array
    {
        $pfad = self::finden('pdfinfo');
        if ($pfad === null) {
            return null;
        }
        $ergebnis = self::starten([$pfad, '-f', (string) $seite, '-l', (string) $seite, $pdf]);
        if (!$ergebnis['ok']
            || !preg_match('/^Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $ergebnis['ausgabe'], $m)) {
            // Ohne Seitenangabe nennt pdfinfo die Größe ohne Nummer.
            if (!preg_match('/^Page size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $ergebnis['ausgabe'] ?? '', $m)) {
                return null;
            }
        }

        $breite = (float) $m[1];
        $hoehe  = (float) $m[2];

        // Eine um 90 Grad gedrehte Seite wird quer gedruckt; poppler
        // rendert sie so, also gehört die Drehung hier mitgerechnet.
        if (preg_match('/^Page\s+\d+\s+rot:\s+(\d+)/m', $ergebnis['ausgabe'], $r)
            || preg_match('/^Page rot:\s+(\d+)/m', $ergebnis['ausgabe'], $r)) {
            if (((int) $r[1]) % 180 === 90) {
                [$breite, $hoehe] = [$hoehe, $breite];
            }
        }

        return ['breite' => $breite, 'hoehe' => $hoehe];
    }

    /**
     * Die größte Fläche, auf die poppler ein Bild dieser Seite hochrechnet,
     * in Punkt² – eine Maske zählt ein Drittel (1 statt 3 Byte je Pixel).
     *
     * pdftoppm rechnet jedes Bild bei jedem Aufruf auf seine ganze Größe in
     * der Ausgabe, auch für einen schmalen Streifen (siehe
     * PlanTileService::MAX_BILDPIXEL). Wie groß es auf der Seite steht,
     * sagt pdfimages -list (Pixel und Auflösung je Bild), ohne ein Bild
     * auszupacken: gemessen 0,05 s für einen leichten Plan, 3,3 s für einen
     * Vektorplan mit 90 MB Inhalt. Eine weiche Maske (smask) zählt zu ihrem
     * Bild – poppler rechnet beide zugleich. Mehr als die Seite
     * ($seitenflaeche in Punkt²) zählt kein Bild; eines ohne Auflösung
     * (gestreckt, unter 0,5 ppi) zählt als ganze Seite.
     *
     * @return float|null 0.0 ohne Bilder; null, wenn pdfimages fehlt oder scheitert
     */
    public static function bildflaeche(string $pdf, int $seite, ?float $seitenflaeche = null): ?float
    {
        $pfad = self::finden('pdfimages');
        if ($pfad === null) {
            return null;
        }
        $ergebnis = self::starten([$pfad, '-list', '-f', (string) $seite, '-l', (string) $seite, $pdf]);
        if (!$ergebnis['ok']) {
            return null;
        }
        $groesste = $zeichnung = 0.0;
        foreach (preg_split('/\R/', $ergebnis['ausgabe']) ?: [] as $zeile) {
            // page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio
            // (object ID ist bei einem eingebetteten Bild „[inline]“ – also von hinten zählen)
            $t = preg_split('/\s+/', trim($zeile)) ?: [];
            $n = count($t);
            if ($n < 14 || !ctype_digit($t[0]) || !ctype_digit($t[3]) || !ctype_digit($t[4])) {
                continue;
            }
            $xppi = (float) $t[$n - 4];
            $yppi = (float) $t[$n - 3];
            $flaeche = $xppi > 0 && $yppi > 0 ? ((int) $t[3] / $xppi * 72) * ((int) $t[4] / $yppi * 72) : INF;
            if ($seitenflaeche !== null && $seitenflaeche > 0) {
                $flaeche = min($flaeche, $seitenflaeche);
            }
            if (!is_finite($flaeche)) {
                // Ohne Seitengröße und ohne Auflösung: nicht abzuschätzen.
                return null;
            }
            $zeichnung = match ($t[2]) {
                'image'         => $flaeche,
                'smask', 'mask' => $zeichnung + $flaeche / 3,
                default         => $flaeche / 3,
            };
            $groesste = max($groesste, $zeichnung);
        }
        return $groesste;
    }

    /** Seitenzahl eines PDF, soweit poppler sie hergibt. */
    public static function seiten(string $pdf): int
    {
        $pfad = self::finden('pdfinfo');
        if ($pfad === null) {
            return 0;
        }
        $ergebnis = self::starten([$pfad, $pdf]);
        if (!$ergebnis['ok'] || !preg_match('/^Pages:\s+(\d+)/m', $ergebnis['ausgabe'], $m)) {
            return 0;
        }
        return (int) $m[1];
    }

    // -------------------------------------------------------------- Innen

    /** Eindeutiger Name für eine Zwischendatei im Kachelordner. */
    public static function tempName(string $zweck): string
    {
        $dir = PlanTileService::dir() . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/' . $zweck . '_' . bin2hex(random_bytes(6));
    }

    /** Alles wegräumen, was zu einem Zwischennamen gehört. */
    public static function aufraeumen(string $zielOhneEndung): void
    {
        foreach (glob($zielOhneEndung . '*') ?: [] as $datei) {
            @unlink($datei);
        }
    }

    /**
     * Ein Programm ohne Shell starten.
     *
     * Ohne Shell heißt: der Dateiname eines Grundrisses kann enthalten was
     * er will, er wird nie interpretiert.
     *
     * @param list<string> $argumente
     * @return array{ok:bool,ausgabe:string,message:string,zeit?:bool}
     */
    private static function starten(array $argumente): array
    {
        $deskriptoren = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $prozess = @proc_open($argumente, $deskriptoren, $rohre);
        if (!is_resource($prozess)) {
            return ['ok' => false, 'ausgabe' => '', 'message' => 'Programm ließ sich nicht starten: ' . $argumente[0]];
        }

        /*
         * Beide Ausgaben gleichzeitig leeren, mit Zeitgrenze. Vorher wurde
         * erst stdout ganz gelesen, dann stderr: schreibt poppler bei einem
         * kaputten PDF Megabytes an Warnungen nach stderr, ist dessen Puffer
         * voll, poppler wartet – und PHP wartet auf stdout. Der Prozess hing
         * für immer, und mit ihm ein PHP-Arbeiter (max_execution_time zählt
         * Rechenzeit, Warten zählt nicht). Zehn solche Uploads, und die
         * Oberfläche stand.
         */
        stream_set_blocking($rohre[1], false);
        stream_set_blocking($rohre[2], false);
        $ausgabe = '';
        $fehler  = '';
        $ende    = microtime(true) + self::ZEITGRENZE;
        $anhaengen = static function ($rohr, string &$ziel, int $hoechstens): void {
            $teil = stream_get_contents($rohr);
            if (is_string($teil) && $teil !== '' && strlen($ziel) < $hoechstens) {
                $ziel .= substr($teil, 0, $hoechstens - strlen($ziel));
            }
        };
        while (true) {
            $lesen = array_values(array_filter([$rohre[1], $rohre[2]], static fn ($r): bool => !feof($r)));
            if ($lesen !== []) {
                $schreiben = $ausnahmen = null;
                @stream_select($lesen, $schreiben, $ausnahmen, 0, 200_000);
            }
            $anhaengen($rohre[1], $ausgabe, self::HOECHSTENS);
            $anhaengen($rohre[2], $fehler, 65_536);
            $stand = proc_get_status($prozess);
            if (!$stand['running']) {
                // Nur hier steht der Rückgabewert sicher (proc_close liefert
                // danach bis PHP 8.2 nur noch -1).
                $rc = (int) $stand['exitcode'];
                break;
            }
            if (microtime(true) > $ende) {
                proc_terminate($prozess, 9);
                fclose($rohre[1]);
                fclose($rohre[2]);
                proc_close($prozess);
                Logger::info('PDF-Rastern abgebrochen: ' . basename((string) $argumente[0]) . ' brauchte länger als ' . self::ZEITGRENZE . ' s.');
                return ['ok' => false, 'ausgabe' => '', 'zeit' => true, 'message' => 'Das PDF ließ sich nicht in ' . self::ZEITGRENZE . ' Sekunden verarbeiten.'];
            }
        }
        stream_set_blocking($rohre[1], true);
        stream_set_blocking($rohre[2], true);
        $anhaengen($rohre[1], $ausgabe, self::HOECHSTENS);
        $anhaengen($rohre[2], $fehler, 65_536);
        fclose($rohre[1]);
        fclose($rohre[2]);
        proc_close($prozess);

        if ($rc !== 0) {
            Logger::info('PDF-Rastern fehlgeschlagen (rc=' . $rc . '): ' . trim($fehler));
            return [
                'ok'      => false,
                'ausgabe' => $ausgabe,
                'message' => trim($fehler) !== '' ? trim($fehler) : 'Rückgabewert ' . $rc,
            ];
        }

        return ['ok' => true, 'ausgabe' => $ausgabe, 'message' => ''];
    }
}
