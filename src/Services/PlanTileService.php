<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use App\Support\Programm;
use App\Support\Zeit;

/**
 * Kacheln für Grundrisse.
 *
 * Ein Hallenplan ist schnell 8000 Pixel breit. Als ein Bild geladen ruckelt
 * die Übersicht und der Browser hält das Ganze unskaliert im Speicher. Die
 * Karte holt darum nur die Ausschnitte, die gerade zu sehen sind – dafür
 * wird der Plan einmal in eine Kachelpyramide zerlegt:
 *
 *   Stufe maxZoom  Plan in Originalgröße, in 256er-Kacheln geschnitten
 *   Stufe z-1      je vier Kacheln der Stufe darüber auf eine gemittelt
 *   …
 *   Stufe 0        der ganze Plan in einer Kachel (Übersicht)
 *
 * Gebaut wird von oben nach unten aus den bereits geschriebenen Kacheln.
 * Dadurch liegen nie mehr als fünf Kacheln gleichzeitig im Speicher, wie
 * groß der Plan auch ist. Bei einem PDF rechnet poppler auch die Stufen
 * darunter selbst, große wie die oberste in Streifen – dort zeigen Linien
 * und Schrift dieselbe Tinte wie in einem PDF-Betrachter, statt mit jeder
 * Stufe weiter zu verblassen (siehe schneidenPdf()).
 *
 * Was die Pixel ändert, zählt FASSUNG hoch: Eine ältere Pyramide bleibt
 * nutzbar, ist aber als veraltet erkennbar (veraltet()).
 *
 * PDF wird hier ebenfalls gekachelt, seit der Weg über pdf.js im Browser
 * sich als Sackgasse erwiesen hat (die Begründung steht in
 * PdfRasterService). Gerastert wird streifenweise, damit auch ein A0-Plan
 * in 16 773 px Breite nicht den Speicher sprengt. Fehlt poppler auf dem
 * Server, bleibt es beim Zeichnen im Browser – dann aber mit begrenzter
 * Zoomtiefe, siehe quelle().
 *
 * PNG und JPEG über MAX_PIXEL gehen denselben Weg: als einseitiges PDF
 * (BildPdf) zu poppler, das sie streifenweise liest – gd packte sie sonst
 * ganz aus. Über MAX_BILDPIXEL rechnet poppler sie herunter.
 *
 * Mit Plänen bis 100 MB dauert das Minuten. Der Upload rechnet deshalb nur
 * eine Weile selbst (bauen() mit Frist) und gibt den Rest als Auftrag an
 * den einen Prozess im Hintergrund (imHintergrund(), arbeiten()); was dabei
 * liegen bleibt, holt die Wartung nach. Es rechnet nie mehr als ein Bau
 * zugleich (nacheinander()).
 *
 * Gerechnet wird nie in den Ordner, aus dem die Karte liest:
 *
 *   kacheln/
 *     .hintergrund       hält, wer gerade baut – nie zwei zugleich (nacheinander())
 *     .arbeiter          hält der eine Prozess im Hintergrund (arbeiten())
 *     <plan>[_sN]/
 *       kacheln.json     Zeiger auf die aktive Fassung ('ordner'), mit ihren Maßen
 *       v<stand>/        aktive Fassung: z/x/y.<format> und ein eigenes kacheln.json
 *       v<stand>.bau/    im Bau – erst fertig, dann per rename() eingesetzt
 *       .sperre          hält, wer baut (laeuft())
 *       .auftrag         hält, wer den Auftrag gerade rechnet (auftrag())
 *       .auftrag.json    Auftrag und sein Zustand für die Karte (zustand())
 *
 * Unter ihrem Stand kommt eine abgelöste Fassung noch GNADE Sekunden
 * (kachel()), weggeräumt wird sie beim nächsten Bau oder in der Wartung;
 * scheitert ein Bau oder wird er abgebrochen, bleibt die bisherige ganz.
 * Pyramiden von vor den Fassungsordnern liegen mit ihren Stufen direkt im
 * Planordner und werden beim nächsten Bau abgelöst (umschalten()).
 *
 * SVG bleibt ungekachelt – es skaliert von sich aus verlustfrei.
 */
final class PlanTileService
{
    /** Kantenlänge einer Kachel in Pixeln (Leaflet-Vorgabe). */
    public const KACHEL = 256;

    /** Darunter lohnt das Kacheln nicht – das Bild kommt am Stück. */
    public const AB_PIXELN = 1600;

    /**
     * Bis hierher schneidet gd ein Bild (Pixel).
     *
     * gd packt ein Bild ganz aus, außerhalb von memory_limit (Debians
     * php-gd nutzt die libgd des Systems): gemessen 228 MB im PHP-Prozess
     * für ein JPEG mit 49 Megapixeln, 371 MB für ein PNG mit 50. Größere
     * Bilder gehen über poppler (BildPdf).
     *
     * Zugleich die Notbremse für PDF: Kann pdfimages nicht sagen, wie groß
     * die Bilder auf einer Seite sind, wird die Seite höchstens so groß
     * gerechnet – so viel hat poppler auch mit einem eingebetteten Scan
     * immer vertragen (gemessen 216 MB).
     */
    public const MAX_PIXEL = 50000000;

    /**
     * So groß rechnet poppler ein Bild höchstens (Pixel in der Ausgabe).
     *
     * pdftoppm rechnet ein Bild bei jedem Aufruf auf seine ganze
     * Ausgabegröße hoch, auch wenn nur ein Streifen davon gebraucht wird –
     * rund 3 Byte je Pixel, gleich wie groß das Bild in der Datei ist.
     * Gemessen an einem Scan mit 151 Megapixeln: 25 MP Ausgabe 147 MB,
     * 50 MP 216 MB, 79 MP 295 MB, 151 MP 504 MB, 200 MP 636 MB. Ein
     * Vektorplan bleibt bei 70–80 MB, ganz gleich wie groß gerechnet.
     *
     * Ein eingehülltes Bild (BildPdf) wird darum höchstens so groß, und ein
     * PDF nur so groß, dass sein größtes Bild darunter bleibt (siehe
     * PdfRasterService::bildflaeche()) – mit 80 Megapixeln rund 300 MB bei
     * poppler, dazu bis 200 MB im PHP-Prozess.
     *
     * Das gilt für ein Bild als Grundlinien-JPEG (so hüllt BildPdf ein, ein
     * progressives lehnt es ab). Steckt im PDF ein progressives JPEG, packt
     * poppler es bei jedem Aufruf ganz aus, und der Speicher hängt an seiner
     * Größe in der Datei, nicht an der Ausgabe: gemessen 402 statt 238 MB
     * bei 58 Megapixeln, 695 statt 297 MB bei 139. Dagegen hilft keine
     * Fläche – pdftoppm läuft deshalb mit einer harten Grenze
     * (PdfRasterService::SPEICHER); reicht die nicht, wird kleiner gerechnet
     * oder der Bau scheitert, und der bisherige Plan bleibt.
     */
    public const MAX_BILDPIXEL = 80_000_000;

    /**
     * Wie fein ein PDF gerechnet wird.
     *
     * Ziel sind PX_JE_PT Pixel je Punkt (rund 360 dpi), begrenzt durch die
     * Fläche ('pixel') und die lange Kante ('kante'). „standard“ deckt A0
     * fast ganz ab (16 773 statt 16 850 px), ein Geländeplan von 2 × 1 m
     * kommt auf 20 000 px (rund 250 dpi); „fein“ gibt ihm die vollen
     * 360 dpi, braucht dafür aber doppelt so lange und doppelt so viel
     * Platte. Der Speicher hängt an keiner der beiden Grenzen: PHP hält
     * einen Streifen (STREIFEN_PIXEL), poppler bei Vektorplänen 70–80 MB.
     *
     * Bilder (PNG, JPEG) rechnen beide Stufen gleich: bis MAX_PIXEL in
     * Originalgröße mit gd, darüber über poppler bis MAX_BILDPIXEL.
     */
    public const QUALITAETEN = [
        'standard' => ['pixel' => 200_000_000, 'kante' => 20_480, 'name' => 'Standard'],
        'fein'     => ['pixel' => 400_000_000, 'kante' => 32_768, 'name' => 'Fein'],
    ];

    /** Pixel je Punkt der PDF-Seite (72 Punkt je Zoll): rund 360 dpi. */
    public const PX_JE_PT = 5;

    /**
     * Fassung der Pyramide, steht in kacheln.json (fehlt sie: Fassung 1).
     *
     * Hochzählen bei jeder Änderung, die Pixel der Kacheln ändert – wie
     * BerichtPdf::ERZEUGER. 2: Mitteln statt bikubisch (keine verlorenen
     * Haarlinien, kein Grauschleier), kleine Stufen eines PDF direkt von
     * poppler, PDF bis 200 Megapixel, JPEG in Qualität 92, WebP. 3: auch die
     * großen Stufen unter der obersten direkt von poppler (in Streifen) –
     * in Fassung 2 war die Stufe darunter, die gewöhnliche Lesestufe, ein
     * Mittel mit blassen Linien und Schrift.
     */
    public const FASSUNG = 3;

    /** JPEG-Qualität der Kacheln: gemessen wich 85 im Mittel um 3,1 vom Scan ab, 92 um 1,0 – für ein Drittel mehr Bytes. */
    public const JPEG_QUALITAET = 92;

    /**
     * Ab dieser mittleren Größe einer Kachel als PNG ist ein Plan ein
     * Scan oder Foto – dann JPEG statt verlustfrei (fotoartig()). Gemessen:
     * Scan 64–80 KB, saubere CAD-Kacheln 1–5 KB. Dichte Schraffur eines
     * Vektorplans kommt auch auf 100 KB – ein PDF wird deshalb nur dann
     * geprüft, wenn ein Bild mindestens die halbe Seite bedeckt.
     */
    public const FOTO_BYTES = 30_720;

    /**
     * Länger soll ein Bau nicht rechnen (Sekunden). Sagt die Hochrechnung
     * nach dem ersten Streifen mehr voraus, wird mit der nächstkleineren
     * Fläche gerechnet (NOTSTUFEN, zuTeuer()).
     */
    public const ZEITBUDGET = 900;

    /**
     * So lange dürfen die Stufen unter der obersten eines PDF bei poppler
     * mindestens dauern (Sekunden) – sonst so lange wie die oberste.
     */
    public const KLEINE_STUFEN_ZEIT = 60;

    /**
     * So viel Platz bleibt auf der Platte immer frei (Bytes): Darunter
     * rechnet kein Bau weiter – SQLite und InfluxDB liegen auf derselben
     * Platte und bekämen sonst kein Byte mehr. Gemessen füllte ein Neubau
     * eine Platte mit 9,6 MB frei bis zum letzten Byte, bevor libpng
     * aufgab.
     */
    public const PLATTE_RESERVE = 256 * 1024 * 1024;

    /**
     * Mindestens so viel belegt eine Kachel auf der Platte (Bytes): ein
     * Block des Dateisystems. Gemessen 4,5 MB Kacheln, aber 17 MB belegt.
     */
    public const KACHEL_BLOCK = 4096;

    /**
     * Für Prüfskripte: Grenzen, die sich sonst nur mit stundenlangen Bauten
     * oder einer vollen Platte zeigten – 'zeitbudget' (ZEITBUDGET),
     * 'kleine_stufen_zeit' (KLEINE_STUFEN_ZEIT), 'streifen_pixel'
     * (STREIFEN_PIXEL), 'notstufen' (NOTSTUFEN), 'frei' (freier Platz in
     * Bytes statt disk_free_space()). Leer im Betrieb.
     *
     * @var array<string,mixed>
     */
    public static array $pruefung = [];

    /**
     * Größtes Bild, das überhaupt noch aufbereitet wird (Pixel).
     *
     * poppler liest es für jeden Streifen ganz (gemessen 4–8 Sekunden je
     * Aufruf bei 139 Megapixeln) – das wächst mit der Fläche. 400
     * Megapixel sind ein Scan von 20 000 × 20 000 Pixeln, mehr als ein
     * A0-Plan in 600 dpi. Darüber ist es eher eine Bombe (ein weißes PNG
     * mit 40 000 × 40 000 Pixeln hat keine 5 MB) als ein Grundriss.
     */
    public const MAX_PIXEL_QUELLE = 400_000_000;

    /**
     * Zusätzliche Zoomstufen, wenn ein PDF im Browser gezeichnet wird.
     *
     * Der Viewport, den pdf.js dafür aufspannt, vervierfacht sich je
     * Stufe. Bei einem A3-Plan liegt Stufe maxZoom+1 noch unter Safaris
     * Canvas-Grenze von rund 16,8 Megapixeln, Stufe +2 darüber. Mehr als
     * eine Stufe Reserve ist deshalb nicht zu haben.
     */
    public const PDF_ZUSATZ_BROWSER = 1;

    /**
     * Fläche eines Streifens, den poppler auf einmal rendert (Pixel).
     *
     * gd hält ihn als Truecolor-Bild samt Lesepuffer – rund 7 Byte je
     * Pixel, außerhalb von memory_limit (Debians php-gd nutzt die libgd
     * des Systems). 16 Megapixel sind so rund 110 MB im PHP-Prozess und
     * rund 50 MB Bitmap bei poppler; dafür muss poppler eine Seite nur
     * noch ein Viertel bis ein Siebtel so oft lesen.
     */
    public const STREIFEN_PIXEL = 16_000_000;

    /**
     * So lange kommt eine abgelöste Fassung nach dem Umschalten noch unter
     * ihrem Stand (Sekunden). Eine Karte, die dabei offen war, holt ihre
     * Kacheln so lange weiter (kachel()), statt Lücken zu zeigen – bis sie
     * den Wechsel bemerkt (Status, Livewerte) und umsteigt oder neu geladen
     * wird. Danach räumt sie der nächste Bau dieses Plans oder die Wartung
     * weg – dafür wartet kein Prozess (gemessen hielt das Warten im
     * Hintergrund 91–93 MB je Plan zwei Minuten lang). Gemessen kostet das
     * Umschalten selbst 0,5–1,1 ms, das Löschen einer Fassung mit 1 700
     * Kacheln 36–54 ms.
     */
    public const GNADE = 120;

    /** Zustände eines Auftrags, die noch nicht zu Ende sind (zustand()). */
    public const OFFEN = ['angefordert', 'wartet', 'laeuft'];

    /**
     * So lange gilt ein Auftrag ohne Prozess noch als „angefordert“
     * (Sekunden): Zwischen dem Klick und dem Moment, in dem der Prozess im
     * Hintergrund seine Sperre hält, vergeht ein Augenblick. Danach heißt
     * ein offener Auftrag ohne Prozess: er ist gestorben. Hält der Prozess
     * im Hintergrund seine Sperre (.arbeiter), „wartet“ er, so lange es
     * dauert – der Prozess nimmt ihn noch mit.
     */
    private const ANGEFORDERT_FRIST = 60;

    /** Beendete Aufträge (.auftrag.json) räumt die Wartung nach so vielen Sekunden weg. */
    private const AUFTRAG_AUFBEWAHREN = 30 * 86400;

    /** Ordner einer Fassung: „v“ und ihr Stand (stand()). */
    private const FASSUNGSORDNER = '/^v[0-9a-f]{12}$/';

    /** … und solange sie gebaut wird. */
    private const BAUORDNER = '/^v[0-9a-f]{12}\.bau$/';

    /** Stufen einer Pyramide von vor den Fassungsordnern (direkt im Planordner). */
    private const STUFENORDNER = '/^\d{1,2}$/';

    public static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/plans/kacheln';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Ordner der Kacheln einer Plandatei.
     *
     * Mehrere Etagen teilen sich eine Datei – die Kacheln also auch. Bei
     * einem mehrseitigen PDF gehört aber zu jeder Seite ein eigener Plan;
     * ohne die Seite im Namen würde Seite 2 die Kacheln von Seite 1
     * überschreiben.
     */
    public static function planDir(array $floor): string
    {
        $name = pathinfo(basename((string) $floor['plan_file']), PATHINFO_FILENAME);
        if (strtolower(pathinfo((string) $floor['plan_file'], PATHINFO_EXTENSION)) === 'pdf') {
            $name .= '_s' . max(1, (int) ($floor['plan_page'] ?? 1));
        }
        return self::dir() . '/' . $name;
    }

    // ----------------------------------------------------------- Abmessungen

    /**
     * Breite und Höhe einer Plandatei in Pixeln.
     *
     * Bei SVG steht oft keine Pixelgröße in der Datei – dann entscheidet die
     * viewBox über das Seitenverhältnis. Die Karte braucht nur ein richtiges
     * Verhältnis, der Maßstab ergibt sich aus dem Zoom.
     */
    public static function masse(string $path): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return self::svgMasse($path);
        }

        $info = @getimagesize($path);
        if ($info === false || (int) $info[0] === 0 || (int) $info[1] === 0) {
            return null;
        }
        return ['breite' => (int) $info[0], 'hoehe' => (int) $info[1]];
    }

    public static function svgMasse(string $path): ?array
    {
        // Nur der Kopf der Datei wird gebraucht; ein CAD-Export kann etliche
        // Megabyte Pfaddaten enthalten, die hier nichts zu suchen haben.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $kopf = (string) fread($handle, 8192);
        fclose($handle);

        if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.eE]+[,\s]+[-\d.eE]+[,\s]+([\d.eE]+)[,\s]+([\d.eE]+)/', $kopf, $m)) {
            $w = (float) $m[1];
            $h = (float) $m[2];
            if ($w > 0 && $h > 0) {
                return ['breite' => (int) round($w), 'hoehe' => (int) round($h)];
            }
        }
        if (preg_match('/\bwidth\s*=\s*["\']([\d.]+)/', $kopf, $mw)
            && preg_match('/\bheight\s*=\s*["\']([\d.]+)/', $kopf, $mh)) {
            $w = (float) $mw[1];
            $h = (float) $mh[1];
            if ($w > 0 && $h > 0) {
                return ['breite' => (int) round($w), 'hoehe' => (int) round($h)];
            }
        }
        // Letzte Rettung: A3 quer, damit die Karte überhaupt ein Verhältnis hat.
        return ['breite' => 1191, 'hoehe' => 842];
    }

    /**
     * Koordinatenraum eines SVG.
     *
     * Ein SVG hat keine Auflösung – die viewBox eines CAD-Exports steht oft
     * in Millimetern, also etwa 420 × 297 für A3. Als Plangröße genommen
     * wäre bei vierfacher Vergrößerung Schluss, obwohl die Zeichnung noch
     * beliebig scharf wäre. Deshalb wird das Verhältnis beibehalten und die
     * lange Kante auf eine Größe gebracht, die genug Zoomstufen ergibt.
     */
    public static function svgRaum(array $masse): array
    {
        $lang = max($masse['breite'], $masse['hoehe'], 1);
        if ($lang >= 2048) {
            return $masse;
        }
        $faktor = 2048 / $lang;
        return [
            'breite' => max(1, (int) round($masse['breite'] * $faktor)),
            'hoehe'  => max(1, (int) round($masse['hoehe'] * $faktor)),
        ];
    }

    /** Höchste Zoomstufe: darauf liegt der Plan in Originalgröße. */
    public static function maxZoom(int $breite, int $hoehe): int
    {
        $lang = max($breite, $hoehe, 1);
        return max(0, (int) ceil(log($lang / self::KACHEL, 2)));
    }

    // -------------------------------------------------------------- Pyramide

    public static function metaPfad(array $floor): string
    {
        return self::planDir($floor) . '/kacheln.json';
    }

    /**
     * Bereits berechnete Pyramide, sonst null – der Zeiger kacheln.json
     * (mit 'ordner', der aktiven Fassung; ohne: eine Pyramide von vor den
     * Fassungsordnern, ihre Stufen liegen direkt im Planordner).
     */
    public static function meta(array $floor): ?array
    {
        $daten = self::jsonLesen(self::metaPfad($floor));
        return $daten !== null && isset($daten['breite'], $daten['max_zoom']) ? $daten : null;
    }

    /** Ordner der Kacheln, die die Karte dieser Etage jetzt zeigt (z/x/y.<format>) – null ohne Pyramide. */
    public static function aktiveFassung(array $floor): ?string
    {
        $meta = self::meta($floor);
        return $meta !== null ? self::kachelOrdner(self::planDir($floor), $meta) : null;
    }

    /** Wo die Kacheln der Pyramide $meta liegen: ihr Fassungsordner oder (ältere) der Planordner selbst. */
    private static function kachelOrdner(string $planOrdner, array $meta): string
    {
        $ordner = (string) ($meta['ordner'] ?? '');
        return preg_match(self::FASSUNGSORDNER, $ordner) === 1 ? $planOrdner . '/' . $ordner : $planOrdner;
    }

    /**
     * Eine JSON-Datei lesen – ohne vorher nachzusehen, ob es sie gibt: Was
     * zwischen Nachsehen und Lesen verschwindet (umgeschaltet, aufgeräumt),
     * ist dann einfach null.
     */
    private static function jsonLesen(string $pfad): ?array
    {
        $text = @file_get_contents($pfad);
        if (!is_string($text) || $text === '') {
            return null;
        }
        $daten = json_decode($text, true);
        return is_array($daten) ? $daten : null;
    }

    /**
     * Eine JSON-Datei schreiben – über eine Zwischendatei und rename(): so
     * liest niemand ein halbes (direkt geschrieben war gemessen jede
     * fünfzigste Lesung unlesbar, während gerade geschrieben wurde).
     */
    private static function jsonSchreiben(string $pfad, array $daten): bool
    {
        $zwischen = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $text = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($text) || @file_put_contents($zwischen, $text) === false || !@rename($zwischen, $pfad)) {
            @unlink($zwischen);
            return false;
        }
        return true;
    }

    /** Fassung einer Pyramide (FASSUNG); ohne Eintrag ist es Fassung 1. */
    public static function fassung(array $meta): int
    {
        return max(1, (int) ($meta['fassung'] ?? 1));
    }

    /** Gerechnet, bevor FASSUNG zuletzt hochgezählt wurde – neu rechnen lohnt. */
    public static function veraltet(array $meta): bool
    {
        return self::fassung($meta) < self::FASSUNG;
    }

    /** Eine der QUALITAETEN – Unbekanntes wird „standard“. */
    public static function qualitaet(?string $wert): string
    {
        return isset(self::QUALITAETEN[(string) $wert]) ? (string) $wert : 'standard';
    }

    /**
     * Würde „fein“ diesen Plan deutlich schärfer machen (mindestens ein
     * Zehntel mehr Kante)? Nur bei einem PDF, dessen Standard an der Fläche
     * oder Kante hing – steht im meta, ohne die Datei noch einmal zu lesen.
     */
    public static function feinMoeglich(array $meta): bool
    {
        $fein = (int) ($meta['kanten']['fein'] ?? 0);
        // Wich der Bau schon wegen Zeit, Platte oder Speicher aus, hülfe „fein“ nicht.
        return self::qualitaet($meta['qualitaet'] ?? null) !== 'fein' && !in_array($meta['grenze'] ?? null, ['zeit', 'platte', 'speicher'], true)
            && $fein >= 1.1 * max(1, (int) ($meta['kante'] ?? 0));
    }

    /**
     * Rechnet gerade jemand an den Kacheln dieser Etage?
     *
     * Die Sperrdatei weiß es schon – sie wird für den Bau exklusiv
     * gehalten. Wer sie nicht einmal geteilt bekommt, weiß: da ist einer
     * dran. Das erspart einen zweiten Zustand, der mit dem ersten aus dem
     * Tritt geraten könnte.
     */
    public static function laeuft(array $floor): bool
    {
        return self::ordnerGesperrt(self::planDir($floor));
    }

    /** Hält jemand die Sperrdatei dieses Kachelordners (siehe laeuft())? */
    private static function ordnerGesperrt(string $ordner): bool
    {
        return self::gehalten($ordner . '/.sperre');
    }

    /**
     * Hält jemand diese Sperrdatei? Nachgesehen mit einer geteilten Sperre
     * für einen Augenblick – darum holt sperreHolen() eine kurz belegte
     * Sperre trotzdem.
     */
    private static function gehalten(string $pfad): bool
    {
        if (!is_file($pfad)) {
            return false;
        }
        $griff = @fopen($pfad, 're');
        if ($griff === false) {
            return false;
        }
        $frei = flock($griff, LOCK_SH | LOCK_NB);
        if ($frei) {
            flock($griff, LOCK_UN);
        }
        fclose($griff);
        return !$frei;
    }

    /**
     * Eine Sperrdatei exklusiv holen. Wer nicht warten will, bekommt sie
     * trotzdem, wenn sie nur einen Augenblick belegt ist: gehalten() hält
     * sie dafür geteilt – das ist kein Bau, der hält sie Sekunden bis
     * Minuten. Ohne die Wiederholung ließe ein Blick des Status-Endpunkts
     * (alle 2,5 Sekunden) einen Bau glauben, ein anderer rechne schon.
     *
     * @param resource $griff
     */
    private static function sperreHolen($griff, bool $warten): bool
    {
        if ($warten) {
            return flock($griff, LOCK_EX);
        }
        for ($versuch = 0; $versuch < 20; $versuch++) {
            if (flock($griff, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(10_000);
        }
        return false;
    }

    /**
     * Sperrdatei öffnen und exklusiv holen (sperreHolen()) – mit „e“: Ein
     * Kindprozess (poppler, der Prozess im Hintergrund) erbt den Griff
     * nicht und hält die Sperre so auch nicht über das Ende dieses
     * Prozesses hinaus. Zum Sperren genügt Lesen – auch wenn root die Datei
     * einmal angelegt hat (wie bei nacheinander()).
     *
     * @return resource|null
     */
    private static function sperren(string $pfad, bool $warten)
    {
        $griff = @fopen($pfad, 'ce') ?: (is_file($pfad) ? @fopen($pfad, 're') : false);
        if ($griff === false) {
            return null;
        }
        if (!self::sperreHolen($griff, $warten)) {
            fclose($griff);
            return null;
        }
        return $griff;
    }

    /** @param resource|null $griff */
    private static function freigeben($griff): void
    {
        if (is_resource($griff)) {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    /** Ohne gd gibt es keine Kachel – weder aus PDF noch aus JPEG. */
    public const GD_FEHLT = 'Zum Kacheln von Grundrissen fehlt php-gd. '
        . 'Auf dem Server nachinstallieren: apt-get install -y php-gd '
        . '(danach php-fpm neu starten).';

    /**
     * Was dem Kacheln im Weg steht – oder null, wenn nichts fehlt.
     *
     * Der Unterschied ist wichtiger, als er aussieht. „Für diese Datei
     * werden keine Kacheln gebraucht" stimmt bei einem kleinen JPEG; bei
     * einem PDF, für das nur gd fehlt, ist es eine Unwahrheit, die genau
     * dorthin führt, wo der Fehler nicht liegt. Gemessen ist genau das
     * passiert: auf einem Server ohne php-gd blieb „Plan jetzt
     * aufbereiten" wirkungslos, meldete den Satz mit den nicht
     * gebrauchten Kacheln und hinterließ den Eindruck, der Knopf sei
     * kaputt.
     *
     * Deshalb steht hier, was fehlt – mit dem Befehl, der es behebt.
     */
    public static function hindernis(string $path): ?string
    {
        if (!extension_loaded('gd')) {
            return self::GD_FEHLT;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'pdf' && !PdfRasterService::verfuegbar()) {
            return PdfRasterService::werkzeug()['hinweis'];
        }
        // Ein Bild über MAX_PIXEL rechnet poppler (BildPdf) – gd allein nicht.
        if (in_array($ext, ['png', 'jpg', 'jpeg'], true) && self::ueberGd($path) && !PdfRasterService::verfuegbar()) {
            return 'Bilder über ' . intdiv(self::MAX_PIXEL, 1_000_000) . ' Megapixel bereitet der Server mit poppler auf. '
                . PdfRasterService::werkzeug()['hinweis'];
        }

        return null;
    }

    /** Ist das Bild zu groß, um es mit gd ganz auszupacken (über MAX_PIXEL)? */
    public static function ueberGd(string $path): bool
    {
        $masse = self::masse($path);
        return $masse !== null && $masse['breite'] * $masse['hoehe'] > self::MAX_PIXEL;
    }

    /** Lohnt sich das Kacheln für diese Datei? */
    public static function lohnt(string $path): bool
    {
        if (self::hindernis($path) !== null) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // PDF lohnt sich immer – ungekachelt käme es gar nicht erst an
        // (siehe PdfRasterService). Dass poppler da ist, hat hindernis()
        // schon festgestellt.
        if ($ext === 'pdf') {
            return true;
        }

        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return false;
        }
        $masse = self::masse($path);
        if ($masse === null) {
            return false;
        }
        if ($masse['breite'] * $masse['hoehe'] > self::MAX_PIXEL) {
            // Über poppler (BildPdf), wenn das Bild sich dafür eignet und
            // nicht größer ist als MAX_PIXEL_QUELLE.
            return $masse['breite'] * $masse['hoehe'] <= self::MAX_PIXEL_QUELLE && BildPdf::pruefen($path)['ok'];
        }
        return max($masse['breite'], $masse['hoehe']) >= self::AB_PIXELN;
    }

    /**
     * Zerlegt einen Raster-Grundriss in Kacheln.
     *
     * Ist die Pyramide schon vorhanden, passiert nichts (außer mit $neu).
     * Gleichzeitige Aufrufe werden über eine Sperrdatei abgefangen; wer
     * warten musste und danach eine fertige neue Pyramide vorfindet, rechnet
     * nicht noch einmal.
     *
     * Gerechnet wird in einen eigenen Ordner (v<stand>.bau), nie in den, aus
     * dem die Karte liest. Erst wenn alles fertig ist, setzt umschalten() ihn
     * ein. Gemessen stand vorher 25 von 27 Sekunden eines Neubaus eine
     * Pyramide aus zwei Maßstäben unter einem kacheln.json, und ein
     * abgebrochener Bau ließ sie für immer so; ein Fehler löschte die
     * fertige Pyramide ganz. Jetzt bleibt bei Fehler und Abbruch die
     * bisherige, wie sie war.
     *
     * $bis (microtime): Wer nur bis dahin warten kann – der Upload –, bekommt
     * danach 'abgebrochen' => true zurück, bevor poppler den nächsten
     * Streifen rechnet. Was schon geschnitten war, ist dann wieder weg; den
     * Plan rechnet imHintergrund() von vorn.
     *
     * $qualitaet: eine der QUALITAETEN. Ohne Angabe bleibt es bei der, mit
     * der die vorhandene Pyramide gerechnet ist, sonst „standard“.
     *
     * @return array{ok:bool,message:string,kacheln:int,meta:?array,abgebrochen?:bool}
     */
    public static function bauen(array $floor, bool $neu = false, bool $warten = true, ?float $bis = null, ?string $qualitaet = null): array
    {
        $quelle = SiteService::planPath($floor);
        if ($quelle === null) {
            return ['ok' => false, 'message' => 'Kein Grundriss hinterlegt.', 'kacheln' => 0, 'meta' => null];
        }
        $vorher = self::meta($floor);
        if (!$neu && $vorher !== null) {
            return ['ok' => true, 'message' => 'Kacheln liegen bereits vor.', 'kacheln' => (int) $vorher['kacheln'], 'meta' => $vorher];
        }
        $hindernis = self::hindernis($quelle);
        if ($hindernis !== null) {
            // Nicht „wird nicht gebraucht": hier fehlt etwas, und der
            // Aufrufer kann es beheben, sobald er weiß, was.
            return ['ok' => false, 'message' => $hindernis, 'kacheln' => 0, 'meta' => null];
        }
        if (!self::lohnt($quelle)) {
            // Ein Bild über MAX_PIXEL, das poppler nicht nehmen kann, braucht
            // sehr wohl Kacheln – es bekommt nur keine. Dann das Warum.
            $grund = self::ueberGd($quelle) ? SiteService::bildPruefen($quelle) : null;
            return ['ok' => false, 'message' => $grund ?? 'Für diese Datei werden keine Kacheln gebraucht.', 'kacheln' => 0, 'meta' => null];
        }
        $qualitaet = $qualitaet !== null ? self::qualitaet($qualitaet) : null;

        $ordner = self::planDir($floor);
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return ['ok' => false, 'message' => 'Kachelordner konnte nicht angelegt werden.', 'kacheln' => 0, 'meta' => null];
        }

        $sperre = @fopen($ordner . '/.sperre', 'ce');
        if ($sperre === false) {
            return ['ok' => false, 'message' => 'Kachelordner ist nicht beschreibbar.', 'kacheln' => 0, 'meta' => null];
        }
        if (!self::sperreHolen($sperre, $warten)) {
            // Beim Seitenaufruf: einer rechnet schon, ein zweiter Arbeiter,
            // der danebensteht und wartet, bringt nichts.
            fclose($sperre);
            return ['ok' => false, 'message' => 'Der Plan wird gerade berechnet.', 'kacheln' => 0, 'meta' => null];
        }

        try {
            /*
             * Hat ein anderer Bau fertig gerechnet, während hier auf die
             * Sperre gewartet wurde, ist nichts mehr zu tun – außer es ist
             * eine andere Qualität verlangt. Nur „schon gebaut“ genügt
             * nicht: Wer nachsieht, ob gerechnet wird (gehalten()), hält
             * die Sperre einen Augenblick, und dahinter stand bisher jeder
             * Neubau ohne Ergebnis wieder auf.
             */
            $jetzt = self::meta($floor);
            if ($jetzt !== null && (!$neu || (($vorher === null || self::stand($jetzt) !== self::stand($vorher))
                && ($qualitaet === null || self::qualitaet($jetzt['qualitaet'] ?? null) === $qualitaet)))) {
                return ['ok' => true, 'message' => 'Kacheln wurden inzwischen gebaut.', 'kacheln' => (int) $jetzt['kacheln'], 'meta' => $jetzt];
            }

            // Wer die Sperre hält, ist allein: Bauordner sind Reste getöteter
            // Bauten, Abgelöstes über GNADE kann weg.
            self::wegraeumen($ordner, self::GNADE, 0);

            /*
             * Was die vorhandene Pyramide schon festgelegt hat, bleibt: die
             * Qualität, wenn keine verlangt ist, und das Seitenverhältnis.
             * Anlagen, Maßstab und Georeferenz stehen in Prozent von Breite
             * und Höhe – mit einem anderen Verhältnis säßen sie daneben.
             */
            $alt = $jetzt;
            if ($alt !== null && ((string) ($alt['quelle'] ?? '') !== basename($quelle)
                || (int) ($alt['seite'] ?? 1) !== max(1, (int) ($floor['plan_page'] ?? 1)))) {
                $alt = null;
            }
            $qualitaet ??= self::qualitaet($alt['qualitaet'] ?? null);

            $name = 'v' . bin2hex(random_bytes(6));
            $bau = $ordner . '/' . $name . '.bau';
            if (!@mkdir($bau, 0775) && !is_dir($bau)) {
                return ['ok' => false, 'message' => 'Kachelordner ist nicht beschreibbar.', 'kacheln' => 0, 'meta' => null];
            }
            try {
                try {
                    $ergebnis = self::schneiden($floor, $quelle, $bau, $bis, $qualitaet, $alt);
                } catch (\RuntimeException $voll) {
                    // Die Platte reicht nicht (plattePruefen()): aufhören, bevor sie voll ist.
                    if ($voll->getCode() !== self::PLATTE_VOLL) {
                        throw $voll;
                    }
                    Logger::info('Kacheln: ' . $voll->getMessage());
                    $ergebnis = ['ok' => false, 'message' => $voll->getMessage(), 'kacheln' => 0, 'meta' => null];
                }
                /*
                 * Wurde die Etage gelöscht (oder bekam sie einen anderen
                 * Plan), während hier gerechnet wurde – im Hintergrund dauert
                 * das Minuten –, hat SiteService die Kacheln schon
                 * weggeräumt; geschrieben wurde danach weiter, der Ordner
                 * entstand neu. Dann wird nicht umgeschaltet (siehe unten).
                 */
                if ($ergebnis['ok'] && $ergebnis['meta'] !== null && !self::verwaist($floor)) {
                    $meta = ['ordner' => $name] + $ergebnis['meta'];
                    $ergebnis = self::umschalten($ordner, $bau, $name, $meta, $jetzt)
                        ? ['meta' => $meta] + $ergebnis
                        : ['ok' => false, 'message' => 'Die neuen Kacheln ließen sich nicht einsetzen – der bisherige Plan bleibt.', 'kacheln' => 0, 'meta' => null];
                }
            } finally {
                // Gescheitert, abgebrochen oder verwaist: nur der Bau ist weg.
                if (is_dir($bau)) {
                    self::verzeichnisWeg($bau);
                }
            }
        } finally {
            self::freigeben($sperre);
        }

        // Ohne Etage, die auf den Ordner zeigt, kommt er ganz weg.
        if (self::verwaist($floor)) {
            self::aufraeumen($floor);
            return ['ok' => false, 'message' => 'Den Plan gibt es nicht mehr – die Etage wurde gelöscht oder hat einen anderen bekommen.', 'kacheln' => 0, 'meta' => null];
        }
        return $ergebnis;
    }

    /**
     * Zeigt keine Etage mehr auf die Kacheln dieser Etage (Plandatei und,
     * bei einem PDF, Seite)? Gefragt wird die Datenbank, nicht $floor –
     * der kann älter sein als die Etage.
     */
    public static function verwaist(array $floor): bool
    {
        $datei = basename((string) ($floor['plan_file'] ?? ''));
        if ($datei === '') {
            return false;
        }
        return self::etagenMitPyramide($floor) === [];
    }

    /**
     * Alle Etagen, die sich die Pyramide von $floor teilen (dieselbe
     * Plandatei, bei einem PDF dieselbe Seite) – aus der Datenbank.
     *
     * @return list<array{id:int,site_id:int,name:string,plan_file:string,plan_page:int}>
     */
    public static function etagenMitPyramide(array $floor): array
    {
        $datei = basename((string) ($floor['plan_file'] ?? ''));
        if ($datei === '') {
            return [];
        }
        $ordner = self::planDir($floor);
        $etagen = [];
        foreach (Database::select('SELECT id, site_id, name, plan_file, plan_page FROM floors WHERE plan_file = ? ORDER BY id', [$datei]) as $f) {
            if (self::planDir($f) === $ordner) {
                $etagen[] = ['id' => (int) $f['id'], 'site_id' => (int) $f['site_id'], 'name' => (string) $f['name'],
                    'plan_file' => (string) $f['plan_file'], 'plan_page' => (int) $f['plan_page']];
            }
        }
        return $etagen;
    }

    /**
     * Die fertige Fassung einsetzen, ohne dass die Karte je eine halbe sieht:
     *
     *  1. ihr eigenes kacheln.json in den Bauordner,
     *  2. rename() zum Fassungsordner – ein Schritt im Dateisystem,
     *  3. der Zeiger kacheln.json im Planordner (Zwischendatei + rename()),
     *  4. die bisherige Fassung als abgelöst markiert (.abgeloest): Sie
     *     bleibt GNADE Sekunden liegen, kachel() liefert sie unter ihrem
     *     Stand weiter.
     *
     * Eine Pyramide von vor den Fassungsordnern (ihre Stufen direkt im
     * Planordner) zieht dabei in einen Ordner mit ihrem Stand um – so findet
     * kachel() auch sie noch. Gemessen dauert das alles unter 2 ms.
     */
    private static function umschalten(string $ordner, string $bau, string $name, array $meta, ?array $vorher): bool
    {
        if (!self::jsonSchreiben($bau . '/kacheln.json', $meta) || !@rename($bau, $ordner . '/' . $name)) {
            return false;
        }
        if (!self::jsonSchreiben($ordner . '/kacheln.json', $meta)) {
            // Der Zeiger blieb, wie er war – die neue Fassung ist ein Rest, den wegraeumen() holt.
            return false;
        }
        if ($vorher === null) {
            return true;
        }
        $alt = (string) ($vorher['ordner'] ?? '');
        if (preg_match(self::FASSUNGSORDNER, $alt) === 1) {
            if ($alt !== $name && is_dir($ordner . '/' . $alt)) {
                @touch($ordner . '/' . $alt . '/.abgeloest');
            }
            return true;
        }
        $ziel = $ordner . '/v' . self::stand($vorher);
        if (!is_dir($ziel) && !@mkdir($ziel, 0775) && !is_dir($ziel)) {
            // Dann bleiben die Stufen liegen, bis wegraeumen() sie holt.
            return true;
        }
        foreach (scandir($ordner) ?: [] as $stufe) {
            $pfad = $ordner . '/' . $stufe;
            if (preg_match(self::STUFENORDNER, $stufe) === 1 && is_dir($pfad) && !is_link($pfad)) {
                @rename($pfad, $ziel . '/' . $stufe);
            }
        }
        self::jsonSchreiben($ziel . '/kacheln.json', $vorher);
        @touch($ziel . '/.abgeloest');
        return true;
    }

    /**
     * Abgelöste Fassungen eines Planordners wegräumen, sobald sie
     * $mindestalter Sekunden abgelöst sind, dazu Bauordner ab $bauAlter –
     * für den Prozess im Hintergrund (nach GNADE) und die Wartung. Rechnet
     * gerade jemand, bleibt alles, wie es ist: Er räumt beim Start selbst
     * auf (bauen()).
     *
     * @return array{fassungen:int,bauten:int,bytes_fassungen:int,bytes_bauten:int}
     */
    public static function abgeloesteWeg(string $ordner, int $mindestalter = self::GNADE, int $bauAlter = 86400): array
    {
        $nichts = ['fassungen' => 0, 'bauten' => 0, 'bytes_fassungen' => 0, 'bytes_bauten' => 0];
        if (!is_dir($ordner) || is_link($ordner) || !str_starts_with($ordner, self::dir() . '/') || !is_file($ordner . '/.sperre')) {
            return $nichts;
        }
        $sperre = self::sperren($ordner . '/.sperre', false);
        if ($sperre === null) {
            return $nichts;
        }
        try {
            return self::wegraeumen($ordner, $mindestalter, $bauAlter);
        } finally {
            self::freigeben($sperre);
        }
    }

    /**
     * Was im Planordner nicht mehr gebraucht wird – der Aufrufer hält die
     * Sperre: abgelöste Fassungen (und Fassungsordner, die nie eingesetzt
     * wurden) nach $mindestalter, Stufen einer älteren Pyramide neben einer
     * aktiven Fassung ebenso, Bauordner ab $bauAlter. Die aktive Fassung nie,
     * andere Namen auch nicht.
     *
     * @return array{fassungen:int,bauten:int,bytes_fassungen:int,bytes_bauten:int}
     */
    private static function wegraeumen(string $ordner, int $mindestalter, int $bauAlter): array
    {
        $ergebnis = ['fassungen' => 0, 'bauten' => 0, 'bytes_fassungen' => 0, 'bytes_bauten' => 0];
        $aktiv = (string) (self::jsonLesen($ordner . '/kacheln.json')['ordner'] ?? '');
        $mitFassung = preg_match(self::FASSUNGSORDNER, $aktiv) === 1;
        $jetzt = time();
        $stufenWeg = false;
        foreach (scandir($ordner) ?: [] as $name) {
            $pfad = $ordner . '/' . $name;
            if ($name === $aktiv || !is_dir($pfad) || is_link($pfad)) {
                continue;
            }
            clearstatcache(true, $pfad);
            $bau = preg_match(self::BAUORDNER, $name) === 1;
            $fassung = preg_match(self::FASSUNGSORDNER, $name) === 1;
            // Stufen direkt im Planordner sind nur neben einer aktiven Fassung ein Rest – sonst sind sie die Pyramide.
            $stufe = $mitFassung && preg_match(self::STUFENORDNER, $name) === 1;
            if (!$bau && !$fassung && !$stufe) {
                continue;
            }
            $marke = $pfad . '/.abgeloest';
            $seit = $fassung && is_file($marke) ? (int) filemtime($marke) : (int) filemtime($pfad);
            if ($jetzt - $seit < ($bau ? $bauAlter : $mindestalter)) {
                continue;
            }
            $bytes = self::groesse($pfad);
            self::verzeichnisWeg($pfad);
            if (is_dir($pfad)) {
                continue;
            }
            $ergebnis[$bau ? 'bytes_bauten' : 'bytes_fassungen'] += $bytes;
            if ($bau) {
                $ergebnis['bauten']++;
            } elseif ($fassung) {
                $ergebnis['fassungen']++;
            } elseif (!$stufenWeg) {
                // Alle Stufen zusammen sind eine Fassung.
                $stufenWeg = true;
                $ergebnis['fassungen']++;
            }
        }
        return $ergebnis;
    }

    /** Bytes aller Dateien unter $pfad. */
    private static function groesse(string $pfad): int
    {
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS)) as $eintrag) {
            $bytes += $eintrag->isFile() ? (int) $eintrag->getSize() : 0;
        }
        return $bytes;
    }

    /**
     * Kacheln nach $ordner (dem Bauordner) schneiden; zurück kommt das
     * meta der neuen Pyramide – geschrieben wird es von bauen().
     *
     * @param ?array $alt kacheln.json der bisherigen Pyramide derselben Datei und Seite
     */
    private static function schneiden(array $floor, string $quelle, string $ordner, ?float $bis, string $qualitaet, ?array $alt): array
    {
        $start  = microtime(true);
        $ext    = strtolower(pathinfo($quelle, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return self::schneidenPdf($floor, $quelle, $ordner, $start, $bis, $qualitaet, $alt);
        }

        /*
         * Über MAX_PIXEL nicht mit gd: das Bild käme ganz in den Speicher
         * (4 Byte je Pixel, außerhalb von memory_limit). Stattdessen als
         * einseitiges PDF zu poppler, das es streifenweise liest – siehe
         * BildPdf. Ein JPEG bleibt JPEG; ob ein PNG ein Scan ist, zeigt der
         * erste Streifen.
         */
        if (self::ueberGd($quelle)) {
            $kopf = BildPdf::pruefen($quelle);
            // Die Hülle ist eine Kopie des Bildes (bis 100 MB) – erst, wenn daneben die Reserve bleibt.
            self::plattePruefen($ordner, (int) @filesize($quelle));
            $huelle = PdfRasterService::tempName('bild') . '.pdf';
            try {
                if (!BildPdf::schreiben($quelle, $huelle)) {
                    return ['ok' => false, 'message' => 'Das Bild ließ sich nicht für poppler aufbereiten. ' . $kopf['grund'], 'kacheln' => 0, 'meta' => null];
                }
                return self::schneidenPdf(['plan_page' => 1] + $floor, $huelle, $ordner, $start, $bis, $qualitaet, $alt, [
                    'name'   => basename($quelle),
                    'breite' => $kopf['breite'],
                    'hoehe'  => $kopf['hoehe'],
                    'format' => $kopf['art'] === 'jpeg' ? 'jpg' : 'auto',
                ]);
            } finally {
                @unlink($huelle);
            }
        }

        $bild = $ext === 'png' ? @imagecreatefrompng($quelle) : @imagecreatefromjpeg($quelle);
        if (!$bild instanceof \GdImage) {
            return ['ok' => false, 'message' => 'Bilddatei konnte nicht gelesen werden.', 'kacheln' => 0, 'meta' => null];
        }

        /*
         * Ein JPEG bleibt JPEG – verlustfrei gespeichert würde es nur
         * größer. Ein PNG bleibt verlustfrei, außer es ist ein Scan
         * (fotoartig()): dann wären die Kacheln fünfmal so groß wie als JPEG
         * und bei WebP zehnmal so langsam. Mit Transparenz bleibt es immer
         * verlustfrei – JPEG kennt keine.
         */
        $format = 'jpg';
        if ($ext === 'png') {
            $format = BildPdf::pruefen($quelle)['durchsichtig'] || !self::fotoartig($bild) ? self::verlustfrei() : 'jpg';
        }

        $breite = imagesx($bild);
        $hoehe  = imagesy($bild);
        $maxZ   = self::maxZoom($breite, $hoehe);
        $anzahl = 0;

        // ---- oberste Stufe: direkt aus der Quelle schneiden
        $spalten = (int) ceil($breite / self::KACHEL);
        $zeilen  = (int) ceil($hoehe / self::KACHEL);
        // Platz für alle Stufen (ein Drittel dazu), je Kachel mindestens ein Block – und die Reserve.
        self::plattePruefen($ordner, (int) ($spalten * $zeilen * 4 / 3 * self::KACHEL_BLOCK));
        for ($x = 0; $x < $spalten; $x++) {
            self::plattePruefen($ordner);
            for ($y = 0; $y < $zeilen; $y++) {
                $kachel = self::leer($format);
                imagecopy(
                    $kachel,
                    $bild,
                    0,
                    0,
                    $x * self::KACHEL,
                    $y * self::KACHEL,
                    min(self::KACHEL, $breite - $x * self::KACHEL),
                    min(self::KACHEL, $hoehe - $y * self::KACHEL)
                );
                self::schreiben($kachel, $ordner, $maxZ, $x, $y, $format);
                imagedestroy($kachel);
                $anzahl++;
            }
        }
        imagedestroy($bild);

        // ---- darunterliegende Stufen: je vier Kacheln der Stufe darüber gemittelt
        $raster = [$maxZ => [$spalten, $zeilen]];
        $anzahl += self::pyramideUnten($ordner, $raster, $maxZ, $format);

        $meta = [
            'breite'     => $breite,
            'hoehe'      => $hoehe,
            'max_zoom'   => $maxZ,
            'kachel'     => self::KACHEL,
            'format'     => $format,
            'kacheln'    => $anzahl,
            'quelle'     => basename($quelle),
            'fassung'    => self::FASSUNG,
            'qualitaet'  => $qualitaet,
            'kante'      => max($breite, $hoehe),
            'kante_ziel' => max($breite, $hoehe),
            'gebaut'     => gmdate('c'),
            'dauer_ms'   => (int) round((microtime(true) - $start) * 1000),
        ];
        return [
            'ok'      => true,
            'message' => sprintf('%d Kacheln in %d Stufen berechnet.', $anzahl, $maxZ + 1),
            'kacheln' => $anzahl,
            'meta'    => $meta,
        ];
    }

    /**
     * Zerlegt einen PDF-Grundriss in Kacheln.
     *
     * Die oberste Stufe rechnet poppler streifenweise: die volle Breite und
     * einige Kachelzeilen (höchstens STREIFEN_PIXEL), daraus werden die
     * Kacheln geschnitten, dann kommt der nächste Streifen. So hängt der
     * Speicher von PHP an der Fläche eines Streifens, nicht an der des
     * Plans.
     *
     * Die Stufen darunter rechnet poppler ebenso, große in Streifen wie die
     * oberste. Ein Mittel (stufeMitteln()) ist physikalisch richtig, nur
     * macht es eine Haarlinie je Stufe halb so kräftig; zwei Stufen unter
     * der obersten war sie gemessen nicht mehr zu sehen (0 % der
     * Linienpunkte mit einem Viertel Kontrast), ein PDF-Betrachter zeichnet
     * sie aber mindestens ein Pixel breit (direkt gerechnet 87–95 %). Auch
     * eine Stufe tiefer kosten Linien und Schrift im Mittel ein Drittel ihrer
     * Tinte – gemessen am A0-Plan auf der gewöhnlichen Lesestufe 0,57–0,71
     * statt 0,79–0,88 im Vergleich zu poppler. Bei schweren Plänen kostet
     * jeder Aufruf so lange wie ein Streifen oder länger – was absehbar
     * teurer wird, als kleineDirekt() erlaubt (höchstens so lange wie die
     * oberste Stufe, mindestens KLEINE_STUFEN_ZEIT), wird gemittelt, die
     * kleinsten Stufen zuerst. Gemessen kostet das am leichten A0-Plan
     * 5 Sekunden mehr (4 Streifen für Stufe 6), am schweren Vektorplan mit
     * 97 MB rund 76 (248 statt 172 Sekunden, alle Stufen direkt).
     *
     * $bild: ist $quelle nur die Hülle eines Bildes (BildPdf) – Name, Maße
     * und Kachelformat ('jpg', oder 'auto': nach dem ersten Streifen). Ein
     * Bild rechnet poppler nicht schärfer, als das Mittel es tut; seine
     * Stufen werden alle gemittelt.
     *
     * @return array{ok:bool,message:string,kacheln:int,meta:?array,abgebrochen?:bool}
     */
    private static function schneidenPdf(
        array $floor,
        string $quelle,
        string $ordner,
        float $start,
        ?float $bis,
        string $qualitaet,
        ?array $alt,
        ?array $bild = null
    ): array {
        $seite = max(1, (int) ($floor['plan_page'] ?? 1));
        $fehler = static fn (string $text): array => ['ok' => false, 'message' => $text, 'kacheln' => 0, 'meta' => null];
        $abgebrochen = static function (): array {
            // Der Aufrufer wartet nicht länger (Upload, Zeitbudget der Wartung):
            // ein halber Plan hilft niemandem, bauen() räumt ihn weg – den
            // Plan rechnet der Hintergrund oder die nächste Wartung.
            return ['ok' => false, 'abgebrochen' => true, 'message' => 'Die Zeit war um, bevor der Plan fertig gerechnet war – er wird später fertig gerechnet.', 'kacheln' => 0, 'meta' => null];
        };

        /*
         * Das Seitenverhältnis: beim Bild aus seinem Kopf, sonst aus der
         * bisherigen Pyramide – Anlagen, Maßstab und Georeferenz stehen in
         * Prozent von Breite und Höhe und sitzen so nach dem Neurechnen, wo
         * sie saßen. Erst ohne beides aus der Probe: jede weitere hieße, die
         * ganze Seite noch einmal zu lesen (bei einem 100-MB-Plan Sekunden).
         */
        $verhaeltnis = match (true) {
            $bild !== null => $bild['hoehe'] / max(1, $bild['breite']),
            (float) ($alt['verhaeltnis'] ?? 0) > 0 => (float) $alt['verhaeltnis'],
            (int) ($alt['breite'] ?? 0) > 0 && (int) ($alt['hoehe'] ?? 0) > 0 => (int) $alt['hoehe'] / (int) $alt['breite'],
            default => PdfRasterService::verhaeltnis($quelle, $seite),
        };
        if ($verhaeltnis === null || $verhaeltnis <= 0) {
            return $fehler('Das PDF ließ sich nicht rastern. ' . PdfRasterService::werkzeug()['hinweis']);
        }
        $punkte = $bild === null ? PdfRasterService::punkte($quelle, $seite) : null;
        $bildflaeche = $bild === null
            ? PdfRasterService::bildflaeche($quelle, $seite, $punkte !== null ? $punkte['breite'] * $punkte['hoehe'] : null)
            : null;

        /*
         * Erst die volle Größe der Qualität. Sagt der erste Streifen, dass
         * sie länger als ZEITBUDGET dauern oder die Platte sprengen würde,
         * geht es mit der nächstkleineren Fläche weiter (NOTSTUFEN) – bis zur
         * letzten, die dann auf jeden Fall gerechnet wird.
         */
        $flaechen = [null];
        foreach ((array) self::grenze('notstufen', self::NOTSTUFEN) as $notstufe) {
            if ($notstufe < self::QUALITAETEN[$qualitaet]['pixel']) {
                $flaechen[] = (int) $notstufe;
            }
        }
        /*
         * Kachelformat: beim Bild steht es fest (JPEG bleibt JPEG). Ein PDF
         * ohne großes Bild ist ein Vektorplan – verlustfrei, auch wenn eine
         * dichte Schraffur im ersten Streifen nach Foto aussähe. Bedeckt ein
         * Bild die halbe Seite oder mehr (ein Scan, ein Luftbild), oder weiß
         * es keiner, entscheidet der erste Streifen (fotoartig()).
         */
        $format = $bild['format'] ?? ($bildflaeche !== null && $punkte !== null
            && $bildflaeche < 0.5 * $punkte['breite'] * $punkte['hoehe'] ? self::verlustfrei() : 'auto');

        $ziel = null;
        $oben = null;
        $ausgewichen = null;
        foreach ($flaechen as $i => $flaeche) {
            $versuch = self::ziel($verhaeltnis, $qualitaet, $punkte, $bildflaeche, $bild, $flaeche);
            if ($ziel !== null && $versuch['kante'] >= $ziel['kante']) {
                continue;
            }
            // Hochgerechnet wird nur, wo es noch kleiner ginge.
            $kleiner = false;
            foreach (array_slice($flaechen, $i + 1) as $danach) {
                $kleiner = $kleiner || self::ziel($verhaeltnis, $qualitaet, $punkte, $bildflaeche, $bild, $danach)['kante'] < $versuch['kante'];
            }
            $ziel = $versuch;
            $oben = self::stufeRendern($quelle, $seite, $ziel['kante'], $ziel['breite'], $ziel['hoehe'],
                self::maxZoom($ziel['breite'], $ziel['hoehe']), $ordner, $format, $bis, $kleiner);
            if (!isset($oben['zu_teuer'])) {
                break;
            }
            $ausgewichen = $oben['zu_teuer'];
            Logger::info(sprintf('Kacheln: %s × %s Pixel %s – es wird kleiner gerechnet.', $ziel['breite'], $ziel['hoehe'], match ($ausgewichen) {
                'zeit'     => 'dauerten länger als ' . intdiv(self::ZEITBUDGET, 60) . ' Minuten',
                'speicher' => 'bekamen bei poppler nicht genug Speicher',
                default    => 'passten nicht auf die Platte',
            }));
        }
        if (!empty($oben['abgebrochen'])) {
            return $abgebrochen();
        }
        if (!$oben['ok']) {
            // Was bis hierher geschnitten ist, räumt bauen() weg – der bisherige Plan bleibt.
            return $fehler($oben['message']);
        }
        if ($oben['anzahl'] === 0) {
            return $fehler('Das PDF ergab keine Kacheln.');
        }

        $maxZ   = self::maxZoom($ziel['breite'], $ziel['hoehe']);
        $format = $oben['format'];
        $anzahl = $oben['anzahl'];
        $raster = [$maxZ => [$oben['spalten'], $oben['zeilen']]];
        $direkt = [];

        /*
         * Die Stufen darunter rechnet poppler ebenso, eine große wie die
         * oberste in Streifen – von oben nach unten, solange kleineDirekt()
         * die Zeit für die Aufrufe dieser Stufe gibt (geschätzt mit dem
         * teuersten Aufruf bis hier). Reicht sie nicht, wird diese Stufe
         * gemittelt und bei der nächsten, billigeren wieder gefragt. Die
         * großen zuerst: Die Stufe unter der obersten ist die, auf der man
         * gewöhnlich liest, zwei, drei darunter liegt die Übersicht; die
         * kleinsten zeigt die Karte nur, wenn man weiter herauszoomt, als
         * der Plan auf den Schirm passt. Gemessen am schweren A0-Plan
         * (97 MB, ein Aufruf 6–16 s) kosteten die Stufen darunter so 76 s;
         * zählten die Aufrufe aller kleineren mit, wurde Stufe 4 – die
         * Übersicht am Desktop – gemittelt und 3 bis 0 direkt gerechnet.
         */
        $selbst  = $bild === null;
        $je      = $oben['sekunden'] / max(1, $oben['aufrufe']);
        $budget  = max((float) self::grenze('kleine_stufen_zeit', self::KLEINE_STUFEN_ZEIT), $oben['sekunden']);
        $bisher  = 0.0;
        for ($z = $maxZ - 1; $z >= 0; $z--) {
            $kanteZ = max(1, (int) round($ziel['kante'] / 2 ** ($maxZ - $z)));
            $masseZ = PdfRasterService::masseAus($verhaeltnis, $kanteZ);
            if ($selbst && self::kleineDirekt($bisher, $je, self::aufrufe($masseZ['breite'], $masseZ['hoehe']), $budget)) {
                if ($bis !== null && microtime(true) > $bis) {
                    return $abgebrochen();
                }
                $stufe = self::stufeRendern($quelle, $seite, $kanteZ, $masseZ['breite'], $masseZ['hoehe'], $z, $ordner, $format, $bis, false);
                if (!empty($stufe['abgebrochen'])) {
                    return $abgebrochen();
                }
                if ($stufe['ok'] && $stufe['anzahl'] > 0) {
                    $raster[$z] = [$stufe['spalten'], $stufe['zeilen']];
                    $anzahl += $stufe['anzahl'];
                    $direkt[] = $z;
                    $bisher += $stufe['sekunden'];
                    $je = max($je, $stufe['sekunden'] / max(1, $stufe['aufrufe']));
                    continue;
                }
                // poppler mag diese Stufe nicht: gemittelt ist sie auch gut, die übrigen ebenso.
                Logger::info('Kacheln: Stufe ' . $z . ' ließ sich nicht direkt rechnen (' . $stufe['message'] . ') – sie wird gemittelt.');
                $selbst = false;
                // Was die Stufe schon geschrieben hat, ersetzt das Mittel ganz.
                self::verzeichnisWeg($ordner . '/' . $z);
            }
            $anzahl += self::stufeMitteln($ordner, $z, $raster[$z + 1], $format);
            $raster[$z] = [(int) ceil($raster[$z + 1][0] / 2), (int) ceil($raster[$z + 1][1] / 2)];
        }

        $meta = [
            'breite'      => $ziel['breite'],
            'hoehe'       => $ziel['hoehe'],
            'max_zoom'    => $maxZ,
            'kachel'      => self::KACHEL,
            'format'      => $format,
            'kacheln'     => $anzahl,
            'quelle'      => $bild['name'] ?? basename($quelle),
            'seite'       => $seite,
            'kante'       => $ziel['kante'],
            'fassung'     => self::FASSUNG,
            'qualitaet'   => $qualitaet,
            'verhaeltnis' => round($verhaeltnis, 8),
            'kante_ziel'  => $ziel['kante_ziel'],
            'kanten'      => $ziel['kanten'],
            'grenze'      => $ausgewichen ?? $ziel['grenze'],
            'direkt'      => $direkt,
            'gebaut'      => gmdate('c'),
            'dauer_ms'    => (int) round((microtime(true) - $start) * 1000),
        ];

        $masse = number_format($ziel['breite'], 0, ',', '.') . ' × ' . number_format($ziel['hoehe'], 0, ',', '.') . ' Pixel';
        return [
            'ok'      => true,
            'message' => match (true) {
                $bild === null => sprintf('%d Kacheln in %d Stufen aus dem PDF berechnet (%s).', $anzahl, $maxZ + 1, $masse),
                $ziel['breite'] < $bild['breite'] => sprintf('%d Kacheln in %d Stufen berechnet (auf %s verkleinert).', $anzahl, $maxZ + 1, $masse),
                default => sprintf('%d Kacheln in %d Stufen berechnet (%s, Originalgröße).', $anzahl, $maxZ + 1, $masse),
            },
            'kacheln' => $anzahl,
            'meta'    => $meta,
        ];
    }

    /**
     * Notstufen der Fläche (Pixel), wenn eine Qualität zu lange dauern oder
     * die Platte sprengen würde: erst 200, dann 50 Megapixel (so groß
     * rechnete Fassung 1 jeden Plan).
     */
    private const NOTSTUFEN = [200_000_000, 50_000_000];

    /**
     * Auf welche Größe eine Seite gerechnet wird.
     *
     * Ziel sind PX_JE_PT Pixel je Punkt (ein eingehülltes Bild: seine
     * Originalgröße), begrenzt durch
     *  - 'kante' und 'flaeche' der Qualität ($flaeche ersetzt die Fläche –
     *    für die Notstufen),
     *  - 'bild': das größte Bild der Seite höchstens MAX_BILDPIXEL in der
     *    Ausgabe (Speicher von poppler); weiß das keiner ($bildflaeche
     *    null), die ganze Seite höchstens MAX_PIXEL wie in Fassung 1.
     * 'grenze' sagt, welche Grenze gegriffen hat (null: keine), 'kanten' je
     * Qualität die Kante, die sie ergäbe – für feinMoeglich().
     *
     * Nicht halbiert, sondern genau so weit verkleinert, wie die Grenze es
     * verlangt: Früher kostete das Halbieren eine ganze Zoomstufe (aus 8407
     * zulässigen Pixeln wurden 4203).
     *
     * @param float $verhaeltnis Höhe durch Breite
     * @param ?array{breite:float,hoehe:float} $punkte Seitengröße in Punkt (pdfinfo)
     * @param ?float $bildflaeche größtes Bild in Punkt² (PdfRasterService::bildflaeche()), 0.0: keines
     * @param ?array{breite:int,hoehe:int} $bild Maße eines eingehüllten Bildes
     * @return array{kante:int,breite:int,hoehe:int,kante_ziel:int,grenze:?string,kanten:array<string,int>}
     */
    public static function ziel(float $verhaeltnis, string $qualitaet, ?array $punkte, ?float $bildflaeche, ?array $bild = null, ?int $flaeche = null): array
    {
        $kurz = $verhaeltnis <= 1.0 ? $verhaeltnis : 1 / $verhaeltnis;
        $kurz = max($kurz, 1e-6);
        $langPt = $punkte !== null ? max($punkte['breite'], $punkte['hoehe']) : 0.0;
        $kanteZiel = match (true) {
            $bild !== null  => max($bild['breite'], $bild['hoehe']),
            $langPt > 0     => max(2048, (int) round($langPt * self::PX_JE_PT)),
            default         => PdfRasterService::KANTE,
        };

        // Speicher von poppler: das größte Bild der Seite in der Ausgabe.
        $bildKante = match (true) {
            $bild !== null => (int) floor(sqrt(self::MAX_BILDPIXEL / $kurz)),
            $bildflaeche === null || $langPt <= 0 => (int) floor(sqrt(self::MAX_PIXEL / $kurz)),
            $bildflaeche > 0 => (int) floor($langPt * sqrt(self::MAX_BILDPIXEL / $bildflaeche)),
            default => PHP_INT_MAX,
        };

        $rechnen = static function (string $q, ?int $flaeche) use ($verhaeltnis, $kurz, $kanteZiel, $bildKante, $bild): array {
            $pixel = $flaeche ?? self::QUALITAETEN[$q]['pixel'];
            $grenzen = [
                'bild'    => $bildKante,
                'flaeche' => (int) floor(sqrt($pixel / $kurz)),
                'kante'   => self::QUALITAETEN[$q]['kante'],
            ];
            $kante = min($kanteZiel, ...array_values($grenzen));
            $grenze = $kante < $kanteZiel ? (string) array_search($kante, $grenzen, true) : null;
            // Ganz genau: die Maße werden gerundet, die Fläche darf trotzdem nicht darüber.
            $hoechstens = min($pixel, $bild !== null ? self::MAX_BILDPIXEL : PHP_INT_MAX);
            $masse = PdfRasterService::masseAus($verhaeltnis, $kante);
            while ($kante > 1 && $masse['breite'] * $masse['hoehe'] > $hoechstens) {
                $kante--;
                $masse = PdfRasterService::masseAus($verhaeltnis, $kante);
                $grenze ??= 'flaeche';
            }
            return ['kante' => $kante, 'breite' => $masse['breite'], 'hoehe' => $masse['hoehe'], 'grenze' => $grenze];
        };

        $ergebnis = $rechnen($qualitaet, $flaeche);
        $kanten = [];
        foreach (array_keys(self::QUALITAETEN) as $q) {
            $kanten[$q] = $rechnen($q, null)['kante'];
        }
        return $ergebnis + ['kante_ziel' => $kanteZiel, 'kanten' => $kanten];
    }

    /**
     * Noch eine Stufe direkt von poppler? Ja, solange die bisher dafür
     * gebrauchte Zeit und die geschätzte für die $rest Aufrufe dieser Stufe
     * ($je Sekunden je Aufruf – so lange wie ein Streifen der obersten Stufe
     * oder der teuerste Aufruf bis hier) in $budget passen (so lange wie
     * die oberste Stufe, mindestens KLEINE_STUFEN_ZEIT).
     */
    public static function kleineDirekt(float $bisher, float $je, int $rest, float $budget): bool
    {
        return $bisher + $je * $rest <= $budget;
    }

    /**
     * So oft ruft stufeRendern() poppler für eine Stufe von $breite × $hoehe
     * Pixeln auf (ohne Wiederholungen nach einer Zeitüberschreitung).
     */
    public static function aufrufe(int $breite, int $hoehe): int
    {
        $zeilen = (int) ceil($hoehe / self::KACHEL);
        if ($breite * $zeilen * self::KACHEL <= (int) self::grenze('streifen_pixel', self::STREIFEN_PIXEL)) {
            return 1;
        }
        return (int) ceil($zeilen / self::zeilenJeStreifen($breite, $zeilen));
    }

    /**
     * Würde die oberste Stufe zu teuer? Hochgerechnet aus dem ersten
     * Streifen: $sekunden und $bytes für $kacheln Kacheln, $streifen
     * Streifen insgesamt, $alle Kacheln der obersten Stufe. Die Stufen
     * darunter kommen mit einem Drittel dazu, und über PLATTE_RESERVE hinaus
     * soll das Dreifache frei sein ($frei, null: unbekannt).
     *
     * Eine Kachel zählt dabei mindestens einen Block (KACHEL_BLOCK): Der
     * erste Streifen ist meist weißer Rand – gemessen 48 Byte je Kachel an
     * einem Vektorplan, der dann 36 MB belegte; verlangt waren so 0,6 MB.
     *
     * @return 'zeit'|'platte'|null
     */
    public static function zuTeuer(float $sekunden, int $streifen, int $bytes, int $kacheln, int $alle, ?float $frei): ?string
    {
        if ($sekunden * $streifen > (float) self::grenze('zeitbudget', self::ZEITBUDGET)) {
            return 'zeit';
        }
        if ($frei !== null && $kacheln > 0
            && 3 * max($bytes / $kacheln, self::KACHEL_BLOCK) * $alle * 4 / 3 > $frei - self::PLATTE_RESERVE) {
            return 'platte';
        }
        return null;
    }

    /** Eine Grenze: im Betrieb $vorgabe (die Konstante), in Prüfskripten aus $pruefung. */
    private static function grenze(string $name, mixed $vorgabe): mixed
    {
        return self::$pruefung[$name] ?? $vorgabe;
    }

    /** Freier Platz der Platte unter $ordner (Bytes) – null, wenn es keiner sagen kann. */
    private static function frei(string $ordner): ?float
    {
        if (isset(self::$pruefung['frei'])) {
            return (float) self::$pruefung['frei'];
        }
        $frei = @disk_free_space($ordner);
        return $frei === false ? null : (float) $frei;
    }

    /** Fehlernummer der Ausnahme aus plattePruefen() – bauen() fängt sie. */
    private const PLATTE_VOLL = 507;

    /**
     * Bleiben neben $mehr Bytes noch PLATTE_RESERVE frei? Sonst hört der Bau
     * auf (eine Ausnahme, die bauen() zur Meldung macht – der bisherige Plan
     * bleibt). Gefragt wird vor dem Bau eines Bildes und nach jedem Streifen
     * bzw. jeder Spalte Kacheln: Die Hochrechnung (zuTeuer()) kennt nur den
     * ersten Streifen.
     */
    private static function plattePruefen(string $ordner, int $mehr = 0): void
    {
        $frei = self::frei($ordner);
        if ($frei !== null && $frei - $mehr < self::PLATTE_RESERVE) {
            throw new \RuntimeException(sprintf('Auf der Platte des Servers ist zu wenig frei (%s frei%s; %s bleiben immer für Datenbank und Messwerte)'
                . ' – der Plan wird nicht weiter gerechnet, der bisherige bleibt.',
                SiteService::mb((int) max(0, $frei)), $mehr > 0 ? ', gebraucht rund ' . SiteService::mb($mehr) : '',
                SiteService::mb(self::PLATTE_RESERVE)), self::PLATTE_VOLL);
        }
    }

    /**
     * Eine Stufe der Pyramide mit poppler rendern und schneiden – eine große
     * in Streifen, eine kleine (bis STREIFEN_PIXEL) in einem Aufruf
     * (aufrufe()). Nach jedem Streifen muss die Reserve auf der Platte
     * bleiben (plattePruefen()).
     *
     * Ein Streifen ist so hoch, wie STREIFEN_PIXEL es erlaubt: poppler
     * liest und deutet bei jedem Aufruf die ganze Seite, ganz gleich, wie
     * schmal der Ausschnitt ist. Gemessen an einem Vektorplan mit 104 MB
     * (eine A0-Seite) dauerte jeder Aufruf rund 11 Sekunden – mit einer
     * Kachelzeile je Aufruf waren das 33 Streifen und 380 Sekunden. Ein
     * eingebetteter Scan wird bei jedem Aufruf ganz ausgepackt (gemessen
     * 15 Sekunden je Streifen bei 80 Megapixeln). Braucht poppler für einen
     * Streifen länger als seine Zeitgrenze, wird er halbiert und noch einmal
     * versucht – ein schwerer Vektorplan scheitert so nicht an der Höhe der
     * Streifen. Passt die ganze Stufe in einen Streifen, reicht ein Aufruf
     * ab dem oberen Rand, bewusst höher als die Seite (siehe unten).
     *
     * $format 'auto': nach dem ersten Streifen (fotoartig()).
     * $waechter: nach dem ersten Streifen hochrechnen (zuTeuer()) und mit
     * 'zu_teuer' aufhören, statt weiterzurechnen – ebenso, wenn poppler
     * schon für den ersten Streifen zu wenig Speicher hatte ('speicher').
     *
     * @return array{ok:bool,message:string,anzahl:int,spalten:int,zeilen:int,format:string,aufrufe:int,sekunden:float,abgebrochen?:bool,zu_teuer?:string}
     */
    private static function stufeRendern(
        string $quelle,
        int $seite,
        int $kante,
        int $breite,
        int $hoehe,
        int $z,
        string $ordner,
        string $format,
        ?float $bis,
        bool $waechter
    ): array {
        $beginn  = microtime(true);
        $spalten = (int) ceil($breite / self::KACHEL);
        $zeilen  = (int) ceil($hoehe / self::KACHEL);
        $anzahl  = 0;
        $aufrufe = 0;
        $ergebnis = static function (bool $ok, string $text, array $mehr = []) use (&$anzahl, &$spalten, &$zeilen, &$format, &$aufrufe, $beginn): array {
            return $mehr + [
                'ok' => $ok, 'message' => $text, 'anzahl' => $anzahl, 'spalten' => $spalten, 'zeilen' => $zeilen,
                'format' => $format, 'aufrufe' => $aufrufe, 'sekunden' => microtime(true) - $beginn,
            ];
        };

        $jeStreifen = self::aufrufe($breite, $hoehe) === 1 ? $zeilen + 2 : self::zeilenJeStreifen($breite, $zeilen);
        $streifenHoch = $jeStreifen * self::KACHEL;

        $y = 0;
        while ($y < $zeilen) {
            if ($bis !== null && microtime(true) > $bis) {
                return $ergebnis(false, '', ['abgebrochen' => true]);
            }
            $nummer   = $y + 1;
            $ziel     = PdfRasterService::tempName('streifen');
            $streifen = PdfRasterService::streifen($quelle, $seite, $kante, $y * self::KACHEL, $streifenHoch, $ziel);
            $aufrufe++;

            if (!$streifen['ok']) {
                PdfRasterService::aufraeumen($ziel);
                if (!empty($streifen['zeit']) && $jeStreifen > 1) {
                    $jeStreifen   = max(1, intdiv(min($jeStreifen, $zeilen), 2));
                    $streifenHoch = $jeStreifen * self::KACHEL;
                    continue;
                }
                // Zu wenig Speicher gleich im ersten Streifen: kleiner geht es vielleicht (ein Bild braucht je Pixel der Ausgabe).
                if (!empty($streifen['speicher']) && $waechter && $y === 0) {
                    return $ergebnis(false, '', ['zu_teuer' => 'speicher']);
                }
                return $ergebnis(false, 'Streifen ab Zeile ' . $nummer . ': ' . $streifen['message']);
            }

            /*
             * Liegt der Ausschnitt hinter dem Seitenende, rendert poppler
             * stillschweigend die ganze Seite. Ein Streifen, der höher ist
             * als angefordert, ist also genau das – und keine Kachelzeile.
             * Dann sind wir am unteren Rand angekommen.
             */
            if ($streifen['hoehe'] > $streifenHoch) {
                PdfRasterService::aufraeumen($ziel);
                $zeilen = $y;
                break;
            }

            $bild = @imagecreatefrompng($streifen['pfad']);
            PdfRasterService::aufraeumen($ziel);
            if (!$bild instanceof \GdImage) {
                return $ergebnis(false, 'Streifen ab Zeile ' . $nummer . ' ließ sich nicht lesen.');
            }
            if ($format === 'auto') {
                $format = self::fotoartig($bild) ? 'jpg' : self::verlustfrei();
            }

            $streifenBreite = imagesx($bild);
            $streifenHoehe  = imagesy($bild);
            $reihen         = (int) ceil($streifenHoehe / self::KACHEL);
            $bytes          = 0;
            $geschrieben    = 0;
            for ($r = 0; $r < $reihen && $y + $r < $zeilen; $r++) {
                for ($x = 0; $x < $spalten; $x++) {
                    $links = $x * self::KACHEL;
                    if ($links >= $streifenBreite) {
                        break;
                    }
                    $kachel = self::leer($format);
                    imagecopy(
                        $kachel,
                        $bild,
                        0,
                        0,
                        $links,
                        $r * self::KACHEL,
                        min(self::KACHEL, $streifenBreite - $links),
                        min(self::KACHEL, $streifenHoehe - $r * self::KACHEL)
                    );
                    $bytes += self::schreiben($kachel, $ordner, $z, $x, $y + $r, $format);
                    imagedestroy($kachel);
                    $geschrieben++;
                }
            }
            imagedestroy($bild);
            $anzahl += $geschrieben;

            if ($waechter && $y === 0 && $streifenHoehe >= $streifenHoch) {
                $teuer = self::zuTeuer(microtime(true) - $beginn, (int) ceil($zeilen / $jeStreifen), $bytes, $geschrieben,
                    $spalten * $zeilen, self::frei($ordner));
                if ($teuer !== null) {
                    return $ergebnis(false, '', ['zu_teuer' => $teuer]);
                }
            }
            self::plattePruefen($ordner);

            // Kürzer als verlangt: das war der untere Rand.
            if ($streifenHoehe < $streifenHoch) {
                $zeilen = min($zeilen, $y + $reihen);
                break;
            }
            $y += $jeStreifen;
        }

        return $ergebnis(true, '');
    }

    /**
     * Kacheln im Hintergrund rechnen lassen – für den Upload, der nicht
     * länger warten kann (SiteService::KACHELZEIT_IM_UPLOAD), und
     * übernommene Pläne (LocationBundle::kachelnBauen()); „Plan neu
     * rechnen“ kommt über neuRechnenAnfordern() denselben Weg.
     *
     * Je Pyramide ein Auftrag in .auftrag.json (auftragEintragen()), dann
     * arbeiterStarten(): Es gibt nur einen Prozess im Hintergrund, und der
     * rechnet alle Aufträge nacheinander ab (arbeiten()). Vorher startete
     * jeder Klick und jeder Upload einen eigenen, der dann auf die Sperre
     * wartete – gemessen 38 MB je Wartendem, nach dem Umschalten noch zwei
     * Minuten 91–93 MB; zwanzig Klicks auf der Standortseite hielten so
     * rund 760 MB neben dem laufenden Bau.
     *
     * $optionen: 'neu' rechnet vorhandene Pyramiden neu, 'qualitaet' ist
     * eine der QUALITAETEN (ohne: die der vorhandenen Pyramide).
     *
     * @param list<int> $etagenIds
     * @param array{neu?:bool,qualitaet?:?string} $optionen
     * @return bool false: kein Prozess zu starten (php fehlt) – die Aufträge holt die Wartung nach
     */
    public static function imHintergrund(array $etagenIds, array $optionen = []): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $etagenIds), static fn (int $id): bool => $id > 0)));
        $gesehen = [];
        foreach ($ids as $id) {
            $etage = SiteService::floor($id);
            if ($etage === null || SiteService::planPath($etage) === null || isset($gesehen[self::planDir($etage)])) {
                continue;
            }
            $gesehen[self::planDir($etage)] = true;
            self::auftragEintragen($etage, !empty($optionen['neu']), $optionen['qualitaet'] ?? null);
        }
        return $gesehen === [] || self::arbeiterStarten();
    }

    /** Hält der Prozess im Hintergrund, solange er Aufträge abarbeitet (arbeiten()). */
    public static function arbeiterSperre(): string
    {
        return self::dir() . '/.arbeiter';
    }

    /**
     * Den Prozess im Hintergrund starten – außer er läuft schon (er hält
     * arbeiterSperre()): dann nimmt er neue Aufträge noch mit, bevor er
     * endet (arbeiten()).
     *
     * Ein eigener Prozess statt Weiterrechnen nach der Antwort: Der Pool
     * zählt mit max_execution_time (300 s, als php_admin_value –
     * set_time_limit() greift dort nicht) die Rechenzeit der ganzen Anfrage,
     * und ein PDF mit 60 Seiten braucht mehr. `bin/power plan:kacheln
     * --auftraege` läuft auf der Kommandozeile ohne diese Grenze, als
     * derselbe Benutzer, und hält keinen PHP-FPM-Arbeiter fest.
     *
     * Die Ausgabe geht nach storage/logs/kacheln.log: /dev/null darf PHP
     * unter open_basedir nicht öffnen, und ein geschlossenes Rohr beendete
     * den Prozess bei der ersten Zeile. Stirbt er (php-fpm neu gestartet),
     * rechnet die nächtliche Wartung nach (`bin/power wartung`).
     */
    public static function arbeiterStarten(): bool
    {
        if (self::gehalten(self::arbeiterSperre())) {
            return true;
        }
        // Unter php-fpm ist PHP_BINARY php-fpm selbst – gebraucht wird php.
        $php = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : Programm::finden('php', ['-v']);
        if ($php === null || $php === '') {
            Logger::info('Kacheln im Hintergrund: php (Kommandozeile) nicht gefunden – bleibt für bin/power plan:kacheln.');
            return false;
        }
        $wurzel = dirname(__DIR__, 2);
        $log = $wurzel . '/storage/logs/kacheln.log';
        $prozess = @proc_open([$php, $wurzel . '/bin/power', 'plan:kacheln', '--auftraege'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $rohre);
        if (!is_resource($prozess)) {
            Logger::info('Kacheln im Hintergrund: bin/power ließ sich nicht starten.');
            return false;
        }
        fclose($rohre[0]);
        // Nicht warten: Das Kind läuft weiter, wenn diese Anfrage endet –
        // PHP räumt den Griff ohne Warten weg (waitpid mit WNOHANG).
        return true;
    }

    /**
     * Die Aufträge abarbeiten, die angefordert sind – der älteste zuerst,
     * jeder mit auftrag() (nie neben einem anderen Bau, nacheinander()). Das
     * ist der Prozess im Hintergrund (`bin/power plan:kacheln --auftraege`,
     * gestartet von arbeiterStarten()); er hält arbeiterSperre(), solange er
     * arbeitet, ein zweiter endet sofort (null).
     *
     * Bevor er endet, lässt er die Sperre los und sieht noch einmal nach:
     * Wer gerade einen Auftrag eingetragen hat, sah die Sperre noch gehalten
     * und startete keinen eigenen Prozess – den Auftrag nimmt dieser hier
     * dann noch mit (oder ein neuer, der die Sperre inzwischen hat).
     * Abgelöstes über GNADE räumt er zuletzt weg; was jünger ist, bleibt für
     * den nächsten Bau oder die Wartung – gewartet wird darauf nicht.
     *
     * @param (callable(string):void)|null $aus eine Zeile je Plan
     * @param (callable(string):void)|null $warten wie bei nacheinander()
     * @return int|null erledigte Aufträge; null, wenn schon ein anderer arbeitet
     */
    public static function arbeiten(?callable $aus = null, ?callable $warten = null): ?int
    {
        $griff = self::sperren(self::arbeiterSperre(), false);
        if ($griff === null) {
            return null;
        }
        $erledigt = 0;
        $erledigtIn = [];
        // Ein Auftrag, den dieser Prozess schon hatte (Nummer je Ordner) – nie zweimal.
        $gesehen = [];
        try {
            while (true) {
                $naechster = self::naechsterAuftrag($gesehen);
                if ($naechster === null) {
                    self::freigeben($griff);
                    $griff = null;
                    if (self::naechsterAuftrag($gesehen) === null || ($griff = self::sperren(self::arbeiterSperre(), false)) === null) {
                        break;
                    }
                    continue;
                }
                [$etage, $offen, $etagen] = $naechster;
                $ordner = self::planDir($etage);
                $gesehen[$ordner] = (string) ($offen['nr'] ?? '');
                $wer = $etage['site_name'] . ' / ' . implode(', ', array_map(static fn (array $e): string => (string) $e['name'], $etagen));
                $start = microtime(true);
                $qualitaet = (string) ($offen['qualitaet'] ?? '');
                $ergebnis = self::auftrag($etage, !empty($offen['neu']), isset(self::QUALITAETEN[$qualitaet]) ? $qualitaet : null, null,
                    $warten !== null ? static fn (string $satz) => $warten($wer . ': ' . $satz) : null);
                if ($ergebnis === null) {
                    // Den Plan rechnet gerade ein anderer Prozess (Kommandozeile) – der führt auch den Auftrag.
                    continue;
                }
                // Hat auftrag() ihn nicht angefasst (kein Plan mehr, nichts zu kacheln), ist er hier zu Ende.
                $danach = self::auftragLesen($ordner);
                if (($danach['zustand'] ?? '') === 'angefordert' && (string) ($danach['nr'] ?? '') === $gesehen[$ordner]) {
                    self::auftragSchreiben($ordner, ['zustand' => 'fehler', 'ende' => gmdate('c'), 'meldung' => self::ohnePfade((string) $ergebnis['message'])] + $danach);
                }
                $erledigt++;
                $erledigtIn[$ordner] = true;
                if ($aus !== null) {
                    $aus($wer . ': ' . $ergebnis['message'] . sprintf(' (%.0f s)', microtime(true) - $start));
                }
            }
        } finally {
            self::freigeben($griff);
        }
        foreach (array_keys($erledigtIn) as $ordner) {
            self::abgeloesteWeg($ordner);
        }
        return $erledigt;
    }

    /**
     * Der älteste angeforderte Auftrag, den gerade keiner rechnet – mit
     * seiner Etage und allen Etagen, die sich die Pyramide teilen. Aus der
     * Datenbank: ein Auftrag im Ordner einer gelöschten Etage zählt nicht.
     *
     * @param array<string,string> $gesehen Ordner => Nummer des Auftrags, den der Prozess schon hatte
     * @return array{0:array,1:array,2:list<array>}|null
     */
    private static function naechsterAuftrag(array $gesehen): ?array
    {
        $plaene = [];
        foreach (SiteService::allFloors() as $etage) {
            if ((string) $etage['plan_file'] !== '') {
                $plaene[self::planDir($etage)][] = $etage;
            }
        }
        $bester = null;
        foreach ($plaene as $ordner => $etagen) {
            $auftrag = self::auftragLesen($ordner);
            if (($auftrag['zustand'] ?? '') !== 'angefordert' || self::gehalten($ordner . '/.auftrag')
                || (isset($gesehen[$ordner]) && $gesehen[$ordner] === (string) ($auftrag['nr'] ?? ''))) {
                continue;
            }
            if ($bester === null || strcmp((string) ($auftrag['angefordert'] ?? ''), (string) ($bester[1]['angefordert'] ?? '')) < 0) {
                $bester = [$etagen[0], $auftrag, $etagen];
            }
        }
        return $bester;
    }

    // -------------------------------------------------------------- Aufträge

    private static function auftragLesen(string $ordner): ?array
    {
        return self::jsonLesen($ordner . '/.auftrag.json');
    }

    private static function auftragSchreiben(string $ordner, array $auftrag): bool
    {
        return self::jsonSchreiben($ordner . '/.auftrag.json', $auftrag);
    }

    /**
     * Wie steht es um den Plan dieser Etage – für die Karte (Status, quelle())
     * und die Standortseite:
     *
     *   keiner       noch nie im Hintergrund gerechnet
     *   angefordert  angestoßen, der Prozess im Hintergrund beginnt gleich
     *   wartet       der Prozess wartet, bis ein anderer Plan fertig ist (nacheinander())
     *   laeuft       es wird gerechnet – im Hintergrund, im Upload oder auf der Kommandozeile
     *   fertig       gerechnet und umgeschaltet
     *   fehler       gescheitert – die bisherige Pyramide ist geblieben
     *   abgebrochen  der Prozess starb (php-fpm neu gestartet) oder hatte keine Zeit mehr
     *
     * Ob ein Prozess lebt, sagen die Sperren (.sperre, .auftrag), nicht die
     * Datei: Stirbt er, sind sie frei, und ein offener Auftrag gilt als
     * abgebrochen – die Wartung holt ihn nach (fehlendeNachrechnen()).
     * 'meldung' ist für Menschen, ohne Pfade; 'seit' ist UTC (ISO 8601).
     *
     * @return array{zustand:string,meldung:string,seit:?string,qualitaet:?string}
     */
    public static function zustand(array $floor): array
    {
        $ordner = self::planDir($floor);
        $auftrag = self::auftragLesen($ordner) ?? [];
        $roh = (string) ($auftrag['zustand'] ?? '');
        $angefordert = strtotime((string) ($auftrag['angefordert'] ?? '')) ?: 0;
        $gestorben = false;
        if (self::ordnerGesperrt($ordner)) {
            $zustand = 'laeuft';
        } elseif (self::gehalten($ordner . '/.auftrag')) {
            $zustand = $roh === 'laeuft' ? 'laeuft' : 'wartet';
        } elseif ($roh === 'angefordert' && self::gehalten(self::arbeiterSperre())) {
            // Der Prozess im Hintergrund lebt und nimmt ihn mit (arbeiten()), so lange es dauert.
            $zustand = 'wartet';
        } elseif ($roh === 'angefordert' && time() - $angefordert <= self::ANGEFORDERT_FRIST) {
            $zustand = 'angefordert';
        } elseif (in_array($roh, self::OFFEN, true)) {
            $zustand = 'abgebrochen';
            $gestorben = true;
        } elseif (in_array($roh, ['fertig', 'fehler', 'abgebrochen'], true)) {
            $zustand = $roh;
        } else {
            $zustand = 'keiner';
        }

        $meta = self::meta($floor);
        $bisher = $meta !== null;
        $grund = trim((string) ($auftrag['meldung'] ?? ''));
        $meldung = match ($zustand) {
            'angefordert' => 'Angestoßen – der Server beginnt gleich.',
            'wartet'      => 'Wartet, bis ein anderer Plan fertig gerechnet ist.',
            'laeuft'      => 'Wird gerechnet' . ($bisher ? ' – bis dahin zeigt die Karte den bisherigen Plan.' : '.')
                . self::dauerText($meta, ' Zuletzt dauerte es %s.'),
            'fertig'      => 'Fertig gerechnet' . (isset($auftrag['ende']) ? ' am ' . Zeit::anzeige((string) $auftrag['ende']) : '') . '.'
                . ($grund !== '' ? ' ' . $grund : ''),
            'fehler'      => ($bisher ? 'Neu rechnen ist gescheitert – der bisherige Plan ist geblieben.' : 'Aufbereiten ist gescheitert.')
                . ($grund !== '' ? ' ' . $grund : ''),
            'abgebrochen' => $gestorben
                ? 'Der Server wurde währenddessen neu gestartet – ' . ($bisher ? 'der bisherige Plan ist geblieben.' : 'die nächtliche Wartung rechnet den Plan nach.')
                : ($grund !== '' ? $grund : 'Abgebrochen.') . ($bisher ? ' Der bisherige Plan ist geblieben.' : ''),
            default       => '',
        };
        $seit = match ($zustand) {
            'laeuft'                => $auftrag['beginn'] ?? null,
            'wartet', 'angefordert' => $auftrag['angefordert'] ?? null,
            'keiner'                => null,
            default                 => $auftrag['ende'] ?? $auftrag['beginn'] ?? $auftrag['angefordert'] ?? null,
        };
        $qualitaet = (string) ($auftrag['qualitaet'] ?? '');
        return [
            'zustand'   => $zustand,
            'meldung'   => $meldung,
            'seit'      => is_string($seit) ? $seit : null,
            'qualitaet' => isset(self::QUALITAETEN[$qualitaet]) ? $qualitaet : null,
        ];
    }

    /** „rund 18 s“ / „rund 3 min“ aus dauer_ms der Pyramide – leer ohne. */
    private static function dauerText(?array $meta, string $muster = '%s'): string
    {
        $ms = (int) ($meta['dauer_ms'] ?? 0);
        if ($ms <= 0) {
            return '';
        }
        $s = (int) max(1, round($ms / 1000));
        return sprintf($muster, $s < 120 ? 'rund ' . $s . ' s' : 'rund ' . (int) round($s / 60) . ' min');
    }

    /** Eine Meldung für Status und Oberfläche: ohne Pfade des Servers, höchstens 300 Zeichen. */
    private static function ohnePfade(string $text): string
    {
        $text = (string) preg_replace('#(?<![\w.~-])/(?:[^\s/\'"`]+/)+([^\s/\'"`]*)#u', '…/$1', $text);
        return mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 300, '…');
    }

    /**
     * Einen Auftrag für den Plan dieser Etage eintragen (.auftrag.json,
     * „angefordert“) – für den Prozess im Hintergrund (arbeiten()). Läuft
     * für den Plan schon etwas – ein Auftrag, ein Upload, die Wartung –,
     * bleibt es dabei: 'neu' ist dann false.
     *
     * .anfrage hält nur den Augenblick zwischen Nachsehen und Eintragen:
     * Zwei Klicks zugleich ergeben einen Auftrag. Losgelassen wird sie vor
     * dem Start des Prozesses – er soll sie nicht erben.
     *
     * @param bool $neu vorhandene Kacheln neu rechnen (sonst nur, was fehlt)
     * @param ?string $qualitaet eine der QUALITAETEN; null: die der vorhandenen Pyramide
     * @return array{ok:bool,neu:bool,message:string,auftrag?:array}
     */
    public static function auftragEintragen(array $floor, bool $neu, ?string $qualitaet = null): array
    {
        if (SiteService::planPath($floor) === null) {
            return ['ok' => false, 'neu' => false, 'message' => 'Kein Grundriss hinterlegt.'];
        }
        $ordner = self::planDir($floor);
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return ['ok' => false, 'neu' => false, 'message' => 'Kachelordner konnte nicht angelegt werden.'];
        }
        $qualitaet = $qualitaet !== null && isset(self::QUALITAETEN[$qualitaet]) ? $qualitaet : null;
        $anfrage = self::sperren($ordner . '/.anfrage', false);
        if ($anfrage === null) {
            return ['ok' => true, 'neu' => false, 'message' => 'Der Plan wird schon neu gerechnet.'];
        }
        try {
            $jetzt = self::zustand($floor);
            if (in_array($jetzt['zustand'], self::OFFEN, true)) {
                return ['ok' => true, 'neu' => false, 'message' => 'Der Plan wird schon gerechnet: ' . $jetzt['meldung']];
            }
            $meta = self::meta($floor);
            $auftrag = [
                'zustand'        => 'angefordert',
                'nr'             => bin2hex(random_bytes(6)),
                'neu'            => $neu,
                'qualitaet'      => $qualitaet,
                'angefordert'    => gmdate('c'),
                'beginn'         => null,
                'ende'           => null,
                'meldung'        => '',
                'fassung_vorher' => $meta !== null ? self::fassung($meta) : null,
                'fassung_ziel'   => self::FASSUNG,
                'stand_vorher'   => $meta !== null ? self::stand($meta) : null,
                'stand'          => null,
            ];
            if (!self::auftragSchreiben($ordner, $auftrag)) {
                return ['ok' => false, 'neu' => false, 'message' => 'Kachelordner ist nicht beschreibbar.'];
            }
        } finally {
            self::freigeben($anfrage);
        }
        return ['ok' => true, 'neu' => true, 'message' => '', 'auftrag' => $auftrag];
    }

    /**
     * „Plan neu rechnen“ anstoßen – für den Knopf in der Oberfläche
     * (KarteController::planNeuRechnen). Gerechnet wird nicht hier: Der
     * Auftrag kommt nach .auftrag.json (auftragEintragen()), der Prozess im
     * Hintergrund rechnet ihn (arbeiterStarten() → arbeiten() → auftrag()),
     * und die Anfrage ist sofort zurück.
     *
     * @param ?string $qualitaet eine der QUALITAETEN; null: die der vorhandenen Pyramide
     * @return array{ok:bool,neu:bool,message:string}
     */
    public static function neuRechnenAnfordern(array $floor, ?string $qualitaet = null): array
    {
        $eingetragen = self::auftragEintragen($floor, true, $qualitaet);
        if (!$eingetragen['neu']) {
            return $eingetragen;
        }
        $meta = self::meta($floor);
        if (!self::arbeiterStarten()) {
            $auftrag = (array) $eingetragen['auftrag'];
            $auftrag['zustand'] = 'fehler';
            $auftrag['ende'] = gmdate('c');
            $auftrag['meldung'] = 'Der Server konnte dafür keinen eigenen Prozess starten (php für die Kommandozeile fehlt?). '
                . 'Von Hand: bin/power plan:kacheln --neu --etage=' . (int) $floor['id'];
            self::auftragSchreiben(self::planDir($floor), $auftrag);
            return ['ok' => false, 'neu' => false, 'message' => $auftrag['meldung']];
        }
        return ['ok' => true, 'neu' => true, 'message' => 'Der Plan wird im Hintergrund neu gerechnet'
            . self::dauerText($meta, ' (zuletzt dauerte es %s, schärfer gerechnet auch länger)')
            . '. Die Karte bleibt so lange benutzbar und zeigt danach den neuen Plan.'];
    }

    /**
     * Den Plan einer Etage rechnen und dabei den Auftrag führen – für
     * bin/power plan:kacheln --etage (so startet ihn imHintergrund()) und
     * die Wartung.
     *
     * .auftrag hält dieser Prozess vom Start bis zum Ende: Ein zweiter für
     * denselben Plan hört sofort auf (null), und stirbt dieser, ist sie frei
     * – der Auftrag gilt dann als abgebrochen (zustand()). Gerechnet wird
     * nie neben einem anderen Bau (nacheinander()); bis dahin „wartet“ der
     * Auftrag. Etagen mit derselben Plandatei teilen sich die Pyramide und
     * damit den Auftrag.
     *
     * @param (callable(string):void)|null $warten wie bei nacheinander()
     * @return array{ok:bool,message:string,kacheln:int,meta:?array,abgebrochen?:bool,umgeschaltet:bool}|null
     */
    public static function auftrag(array $floor, bool $neu, ?string $qualitaet = null, ?float $bis = null, ?callable $warten = null): ?array
    {
        $pfad = SiteService::planPath($floor);
        if ($pfad === null) {
            return ['ok' => false, 'message' => 'Kein Grundriss hinterlegt.', 'kacheln' => 0, 'meta' => null, 'umgeschaltet' => false];
        }
        // Was nie gekachelt wird (SVG, kleines Bild), bekommt auch keinen Auftrag; fehlt ein Werkzeug, sagt der Auftrag es.
        if (self::hindernis($pfad) === null && !self::lohnt($pfad)) {
            return ['ok' => false, 'message' => 'Für diese Datei werden keine Kacheln gebraucht.', 'kacheln' => 0, 'meta' => null, 'umgeschaltet' => false];
        }
        $ordner = self::planDir($floor);
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return ['ok' => false, 'message' => 'Kachelordner konnte nicht angelegt werden.', 'kacheln' => 0, 'meta' => null, 'umgeschaltet' => false];
        }
        $griff = self::sperren($ordner . '/.auftrag', false);
        if ($griff === null) {
            return null;
        }
        try {
            $vorher = self::meta($floor);
            $gewesen = self::auftragLesen($ordner);
            $offen = in_array($gewesen['zustand'] ?? '', self::OFFEN, true) ? $gewesen : null;
            // Ohne Angabe die aus der Anforderung (Oberfläche), sonst die der vorhandenen Pyramide (bauen()).
            $verlangt = (string) ($offen['qualitaet'] ?? '');
            $qualitaet ??= isset(self::QUALITAETEN[$verlangt]) ? $verlangt : null;
            $auftrag = [
                'zustand'        => 'wartet',
                'nr'             => is_string($offen['nr'] ?? null) ? $offen['nr'] : bin2hex(random_bytes(6)),
                'neu'            => $neu,
                'qualitaet'      => $qualitaet,
                'angefordert'    => is_string($offen['angefordert'] ?? null) ? $offen['angefordert'] : gmdate('c'),
                'beginn'         => null,
                'ende'           => null,
                'meldung'        => '',
                'fassung_vorher' => $vorher !== null ? self::fassung($vorher) : null,
                'fassung_ziel'   => self::FASSUNG,
                'stand_vorher'   => $vorher !== null ? self::stand($vorher) : null,
                'stand'          => null,
            ];
            self::auftragSchreiben($ordner, $auftrag);

            $ergebnis = self::nacheinander(static function () use (&$auftrag, $ordner, $floor, $neu, $qualitaet, $bis): array {
                $auftrag['zustand'] = 'laeuft';
                $auftrag['beginn'] = gmdate('c');
                self::auftragSchreiben($ordner, $auftrag);
                // Während gewartet wurde, kann die Etage gelöscht sein oder einen anderen Plan bekommen haben.
                $jetzt = SiteService::floor((int) $floor['id']);
                if ($jetzt === null || self::planDir($jetzt) !== $ordner) {
                    return ['ok' => false, 'message' => 'Den Plan gibt es nicht mehr – die Etage wurde gelöscht oder hat einen anderen bekommen.', 'kacheln' => 0, 'meta' => null];
                }
                return self::bauen($jetzt, $neu, true, $bis, $qualitaet);
            }, $warten, $bis);

            if ($ergebnis === null && $offen === null) {
                // Die Frist war um, bevor der Plan an der Reihe war, und verlangt hatte ihn keiner (die Wartung):
                // Gerechnet wurde nichts – es bleibt beim vorigen Zustand, die nächste Nacht versucht es wieder.
                $gewesen !== null ? self::auftragSchreiben($ordner, $gewesen) : @unlink($ordner . '/.auftrag.json');
                return ['ok' => false, 'abgebrochen' => true, 'kacheln' => 0, 'meta' => null, 'umgeschaltet' => false,
                    'message' => 'Die Zeit war um, bevor der Plan an der Reihe war.'];
            }
            $ergebnis ??= ['ok' => false, 'abgebrochen' => true, 'kacheln' => 0, 'meta' => null,
                'message' => 'Die Zeit war um, bevor der Plan an der Reihe war – den Rest rechnet die nächtliche Wartung.'];

            $nachher = self::meta($floor);
            $auftrag['zustand'] = $ergebnis['ok'] ? 'fertig' : (!empty($ergebnis['abgebrochen']) ? 'abgebrochen' : 'fehler');
            $auftrag['ende'] = gmdate('c');
            $auftrag['meldung'] = self::ohnePfade((string) $ergebnis['message']);
            $auftrag['stand'] = $nachher !== null ? self::stand($nachher) : null;
            if (!empty($ergebnis['abgebrochen']) && $offen === null && $bis !== null) {
                // Abgebrochen an der Frist der Wartung, nicht gestorben: kein Fall für fehlendeNachrechnen() –
                // veralteteNachrechnen() versucht es wieder, wenn die Zeit dafür reicht.
                $auftrag['frist'] = true;
                $auftrag['meldung'] = 'Die Zeit der nächtlichen Wartung war um, bevor der Plan fertig war – sie rechnet ihn in einer der nächsten Nächte.';
            }
            // Ist der Ordner weg (Etage gelöscht), entsteht er hier nicht neu.
            if (is_dir($ordner)) {
                self::auftragSchreiben($ordner, $auftrag);
            }
            // Umgeschaltet heißt hier: Es gibt jetzt eine abgelöste Fassung.
            return $ergebnis + ['umgeschaltet' => $auftrag['stand'] !== null && $auftrag['stand_vorher'] !== null && $auftrag['stand'] !== $auftrag['stand_vorher']];
        } finally {
            self::freigeben($griff);
        }
    }

    /**
     * Grundrisse ohne Kacheln nachrechnen – für die nächtliche Wartung.
     *
     * Der Upload rechnet große Pläne in einem eigenen Prozess weiter
     * (imHintergrund), „Plan neu rechnen“ ebenso. Starb der – php-fpm beim
     * Update neu gestartet, der Container neu gebootet –, fehlen die Kacheln
     * bis hierher, oder der Auftrag ist abgebrochen (dann mit der verlangten
     * Qualität). Jede Pyramide einmal, auch wenn Etagen sie teilen. Wer
     * gerade rechnet, wird nicht gestört; was nicht gekachelt wird (SVG,
     * kleine Bilder), fällt gar nicht erst auf.
     *
     * @return list<string> eine Zeile je Plan, für den etwas getan wurde
     */
    public static function fehlendeNachrechnen(): array
    {
        $meldungen = [];
        $gesehen = [];
        foreach (SiteService::allFloors() as $etage) {
            $pfad = SiteService::planPath($etage);
            if ($pfad === null) {
                continue;
            }
            $ordner = self::planDir($etage);
            if (isset($gesehen[$ordner])) {
                continue;
            }
            $gesehen[$ordner] = true;
            $fehlt = self::meta($etage) === null;
            $zustand = self::zustand($etage);
            // An der Frist der Wartung abgebrochen ist nicht gestorben: Das holt veralteteNachrechnen() mit seinem Budget nach.
            $frist = !empty(self::auftragLesen($ordner)['frist']);
            if ((!$fehlt && ($zustand['zustand'] !== 'abgebrochen' || $frist)) || in_array($zustand['zustand'], self::OFFEN, true) || !self::lohnt($pfad)) {
                continue;
            }
            $ergebnis = self::auftrag($etage, !$fehlt, $zustand['zustand'] === 'abgebrochen' ? $zustand['qualitaet'] : null);
            if ($ergebnis === null) {
                continue;
            }
            $meldungen[] = match (true) {
                $ergebnis['ok'] && $fehlt => 'Kacheln nachgerechnet: ',
                $ergebnis['ok']           => 'Abgebrochenes Neurechnen nachgeholt: ',
                $fehlt                    => 'Kacheln fehlen weiter: ',
                default                   => 'Neu rechnen weiter gescheitert (der bisherige Plan bleibt): ',
            } . $etage['site_name'] . ' / ' . $etage['name'] . ' – ' . $ergebnis['message'];
        }
        return $meldungen;
    }

    /**
     * Pyramiden einer älteren Fassung (veraltet()) neu rechnen – für die
     * nächtliche Wartung (bin/power wartung, plan:kacheln --veraltet).
     *
     * Einzeln und nur, solange $budget (Sekunden) reicht: die schnellsten
     * zuerst, geschätzt aus der Dauer des letzten Baus (schärfer gerechnet
     * gemessen rund das Dreieinhalbfache, höchstens ZEITBUDGET). Die Frist
     * gilt auch beim Warten auf einen anderen Bau und im Bau selbst
     * (auftrag() mit $bis): Gemessen dauerte `--veraltet --bis=40` vorher
     * 175 s, 84 davon hinter einem Bau aus der Oberfläche. Bricht ein Plan
     * an der Frist ab, bleibt der bisherige; geschätzt wird er danach mit
     * ZEITBUDGET. Jeder Plan holt sich nacheinander() für sich – ein „Plan
     * neu rechnen“ aus der Oberfläche kommt so dazwischen, statt hinter allen
     * zu warten. Ein Plan, an dem diese Fassung schon gescheitert ist, wird
     * nicht jede Nacht wieder versucht. Was bleibt, sagt die letzte Zeile
     * („Noch N …“).
     *
     * @param (callable(string):void)|null $warten wie bei nacheinander()
     * @return list<string>
     */
    public static function veralteteNachrechnen(int $budget, ?callable $warten = null): array
    {
        $start = microtime(true);
        $faellig = [];
        $gesehen = [];
        $noch = 0;
        foreach (SiteService::allFloors() as $etage) {
            $pfad = SiteService::planPath($etage);
            $ordner = self::planDir($etage);
            if ($pfad === null || isset($gesehen[$ordner])) {
                continue;
            }
            $gesehen[$ordner] = true;
            $meta = self::meta($etage);
            if ($meta === null || !self::veraltet($meta) || (string) ($meta['quelle'] ?? '') !== basename($pfad)) {
                continue;
            }
            $auftrag = self::auftragLesen($ordner);
            if (($auftrag['zustand'] ?? '') === 'fehler' && (int) ($auftrag['fassung_ziel'] ?? 0) >= self::FASSUNG) {
                $noch++;
                continue;
            }
            $faellig[] = ['etage' => $etage, 'schaetzung' => !empty($auftrag['frist']) && (int) ($auftrag['fassung_ziel'] ?? 0) >= self::FASSUNG
                ? self::ZEITBUDGET
                : (int) min(self::ZEITBUDGET, max(10, 3.5 * (int) ($meta['dauer_ms'] ?? 0) / 1000))];
        }
        usort($faellig, static fn (array $a, array $b): int => $a['schaetzung'] <=> $b['schaetzung']);
        $bis = $budget < PHP_INT_MAX ? $start + $budget : null;

        $meldungen = [];
        foreach ($faellig as ['etage' => $etage, 'schaetzung' => $schaetzung]) {
            if (microtime(true) - $start + $schaetzung > $budget || in_array(self::zustand($etage)['zustand'], self::OFFEN, true)) {
                $noch++;
                continue;
            }
            $ergebnis = self::auftrag($etage, true, null, $bis, $warten);
            if ($ergebnis === null || !$ergebnis['ok']) {
                $noch++;
            }
            if ($ergebnis !== null) {
                $meldungen[] = match (true) {
                    $ergebnis['ok']                  => 'Kacheln neu gerechnet (ältere Fassung): ',
                    !empty($ergebnis['abgebrochen']) => 'Neu rechnen abgebrochen, die Zeit war um (der bisherige Plan bleibt): ',
                    default                          => 'Neu rechnen gescheitert (der bisherige Plan bleibt): ',
                } . $etage['site_name'] . ' / ' . $etage['name'] . ' – ' . $ergebnis['message'];
            }
        }
        if ($noch > 0) {
            $meldungen[] = sprintf('Noch %d Grundriss(e) mit älterer Kachelfassung – die Wartung rechnet sie Nacht für Nacht neu '
                . '(sofort: bin/power plan:kacheln --veraltet oder „Plan neu rechnen“ in der Karte).', $noch);
        }
        return $meldungen;
    }

    /** Wo die Prozesse im Hintergrund aufeinander warten (nacheinander()). */
    public static function hintergrundSperre(): string
    {
        return self::dir() . '/.hintergrund';
    }

    /**
     * $arbeit rechnen, aber nie neben einem anderen Bau – für den Prozess im
     * Hintergrund (auftrag(), arbeiten()), den Upload (SiteService, der nicht
     * wartet, sondern dann an den Hintergrund gibt), bin/power plan:kacheln
     * und die Wartung.
     *
     * Jeder Bau eines großen Plans hält gemessen 175–200 MB im PHP-Prozess
     * (gd außerhalb von memory_limit, auf der Kommandozeile ohnehin ohne
     * Grenze) und 70–80 MB bei pdftoppm, mit einem eingebetteten Scan bis
     * 300 MB (MAX_BILDPIXEL). Lädt jemand Halle für Halle vier
     * große Pläne hoch, liefen sonst vier solcher Prozesse nebeneinander –
     * über 1 GB, in einem Container mit 2 GB samt InfluxDB ein Fall für den
     * OOM-Killer –, und jeder einzelne würde langsamer. pm.max_children
     * zählt sie nicht: Sie laufen außerhalb des Pools.
     *
     * Die Sperre ist ein flock auf hintergrundSperre(): Der nächste wartet,
     * bis der vorige fertig ist; stirbt einer, ist sie frei. $warten bekommt
     * eine Zeile, wenn gewartet werden muss. Mit $bis (microtime) wird nur
     * bis dahin gewartet – danach kommt null zurück, ohne $arbeit.
     *
     * @template T
     * @param callable():T $arbeit
     * @param (callable(string):void)|null $warten
     * @return T|null
     */
    public static function nacheinander(callable $arbeit, ?callable $warten = null, ?float $bis = null): mixed
    {
        // Zum Sperren genügt Lesen – auch wenn root die Datei einmal angelegt hat.
        $griff = @fopen(self::hintergrundSperre(), 'ce') ?: @fopen(self::hintergrundSperre(), 're');
        if ($griff === false) {
            // Ohne Sperrdatei lieber rechnen als gar nicht – der Bau selbst
            // meldet, wenn der Ordner nicht beschreibbar ist.
            return $arbeit();
        }
        try {
            if (!flock($griff, LOCK_EX | LOCK_NB)) {
                if ($warten !== null) {
                    $warten('Ein anderer Prozess rechnet gerade Kacheln – es geht weiter, sobald er fertig ist.');
                }
                if ($bis === null) {
                    flock($griff, LOCK_EX);
                } else {
                    while (!flock($griff, LOCK_EX | LOCK_NB)) {
                        if (microtime(true) > $bis) {
                            return null;
                        }
                        usleep(250_000);
                    }
                }
            }
            return $arbeit();
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    /** So heißen Plandateien, die der Upload und der Abgleich ablegen (site3_…, sync12_…). */
    private const PLANDATEI_NAME = '/^[a-z]+\d+_[0-9a-f]{16}\.(pdf|png|jpe?g|svg)$/';

    /** … und ihre Kachelordner (bei einem PDF mit der Seite). */
    private const KACHELORDNER_NAME = '/^[a-z]+\d+_[0-9a-f]{16}(_s\d+)?$/';

    /**
     * Was beim Kacheln liegen geblieben ist – für die nächtliche Wartung.
     *
     *  - Zwischendateien in kacheln/.tmp (die Hülle eines großen Bildes,
     *    Streifen von poppler): Wird ein Bau getötet – systemctl restart
     *    php-fpm beendet auch die Prozesse im Hintergrund –, räumt sein
     *    finally nicht mehr auf; bei einem Bild mit 100 MB sind das je Mal
     *    über 100 MB.
     *  - Plandateien, auf die keine Etage mehr zeigt: Der Git- und
     *    Partner-Abgleich ersetzt einen Plan in einer Transaktion, die noch
     *    scheitern kann – den alten löscht er deshalb nicht selbst.
     *  - Kachelordner ohne Etage: die Seite eines PDF, deren Etage gelöscht
     *    wurde, ein ersetzter Plan.
     *
     * Nur was seit $alter Sekunden unverändert ist: Was gerade entsteht (ein
     * Upload zwischen Ablegen und Eintragen, eine Übernahme vor dem Commit),
     * bleibt – ebenso ein Ordner, an dem gerade gerechnet wird. Andere Namen
     * als die, die PowerManagement selbst vergibt, werden nie angefasst.
     *
     * @return list<string> je Art eine Zeile, wenn etwas weg ist
     */
    public static function wartung(int $alter = 86400): array
    {
        $grenze = time() - $alter;
        $meldungen = [];
        $zeile = static function (string $was, int $zahl, int $bytes) use (&$meldungen): void {
            if ($zahl > 0) {
                $meldungen[] = sprintf('Aufgeräumt: %d %s (%s).', $zahl, $was, SiteService::mb($bytes));
            }
        };

        $zahl = $bytes = 0;
        foreach (glob(self::dir() . '/.tmp/*') ?: [] as $datei) {
            clearstatcache(true, $datei);
            if (is_file($datei) && (int) filemtime($datei) < $grenze) {
                $groesse = (int) filesize($datei);
                if (@unlink($datei)) {
                    $zahl++;
                    $bytes += $groesse;
                }
            }
        }
        $zeile('Zwischendatei(en) vom Kacheln, älter als ein Tag', $zahl, $bytes);

        $dateien = $ordner = [];
        foreach (Database::select("SELECT plan_file, plan_page FROM floors WHERE plan_file <> ''") as $f) {
            $dateien[basename((string) $f['plan_file'])] = true;
            $ordner[basename(self::planDir($f))] = true;
        }

        $zahl = $bytes = 0;
        foreach (scandir(SiteService::planDir()) ?: [] as $name) {
            $pfad = SiteService::planDir() . '/' . $name;
            clearstatcache(true, $pfad);
            if (preg_match(self::PLANDATEI_NAME, $name) !== 1 || isset($dateien[$name]) || !is_file($pfad) || is_link($pfad)
                || (int) filemtime($pfad) >= $grenze) {
                continue;
            }
            $groesse = (int) filesize($pfad);
            if (@unlink($pfad)) {
                $zahl++;
                $bytes += $groesse;
            }
        }
        $zeile('Plandatei(en) ohne Etage', $zahl, $bytes);

        $zahl = $bytes = 0;
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $pfad) {
            $name = basename($pfad);
            clearstatcache(true, $pfad);
            if (preg_match(self::KACHELORDNER_NAME, $name) !== 1 || isset($ordner[$name]) || is_link($pfad)
                || (int) filemtime($pfad) >= $grenze || self::ordnerGesperrt($pfad)) {
                continue;
            }
            $groesse = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS)) as $eintrag) {
                $groesse += $eintrag->isFile() ? (int) $eintrag->getSize() : 0;
            }
            self::verzeichnisWeg($pfad);
            if (!is_dir($pfad)) {
                $zahl++;
                $bytes += $groesse;
            }
        }
        $zeile('Kachelordner ohne Etage', $zahl, $bytes);

        /*
         * In den Kachelordnern, die noch gebraucht werden: abgelöste Fassungen
         * (nach GNADE – meist hat sie der Prozess im Hintergrund schon
         * geholt), Bauordner getöteter Bauten und liegen gebliebene
         * Zwischendateien (ab $alter), beendete Aufträge nach
         * AUFTRAG_AUFBEWAHREN. Wer gerade rechnet, wird nicht gestört
         * (abgeloesteWeg() holt sich die Sperre, sonst bleibt alles).
         */
        $fassungen = $bauten = $bytesFassung = $bytesBau = $auftraege = 0;
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $pfad) {
            $name = basename($pfad);
            if (preg_match(self::KACHELORDNER_NAME, $name) !== 1 || !isset($ordner[$name]) || is_link($pfad)) {
                continue;
            }
            $weg = self::abgeloesteWeg($pfad, self::GNADE, $alter);
            $fassungen += $weg['fassungen'];
            $bauten += $weg['bauten'];
            $bytesFassung += $weg['bytes_fassungen'];
            $bytesBau += $weg['bytes_bauten'];
            foreach (scandir($pfad) ?: [] as $datei) {
                $zwischen = $pfad . '/' . $datei;
                clearstatcache(true, $zwischen);
                if (str_ends_with($datei, '.tmp') && is_file($zwischen) && !is_link($zwischen) && (int) filemtime($zwischen) < $grenze) {
                    @unlink($zwischen);
                }
            }
            $auftrag = self::auftragLesen($pfad);
            $datei = $pfad . '/.auftrag.json';
            if ($auftrag !== null && !in_array($auftrag['zustand'] ?? '', self::OFFEN, true) && !self::gehalten($pfad . '/.auftrag')
                && (int) @filemtime($datei) < time() - self::AUFTRAG_AUFBEWAHREN && @unlink($datei)) {
                $auftraege++;
            }
        }
        $zeile('abgelöste Kachelfassung(en)', $fassungen, $bytesFassung);
        $zeile('Kachelbau(ten) ohne Prozess, älter als ein Tag', $bauten, $bytesBau);
        if ($auftraege > 0) {
            $meldungen[] = sprintf('Aufgeräumt: %d beendete(r) Kachelauftrag/-aufträge, älter als %d Tage.', $auftraege, intdiv(self::AUFTRAG_AUFBEWAHREN, 86400));
        }

        return $meldungen;
    }

    /**
     * Wie viele Kachelzeilen ein Streifen umfasst.
     *
     * Höchstens so viele, wie in STREIFEN_PIXEL passen – und nie die ganze
     * Seite bis auf weniger als zwei Zeilen. Der Grund ist die Eigenheit
     * von poppler, hinter dem Seitenende die ganze Seite zu liefern: ist
     * ein Streifen niedriger als die Seite, verrät ihn genau das (er kommt
     * höher zurück als verlangt). Wäre er so hoch wie die Seite, sähe die
     * ganze Seite aus wie ein gewöhnlicher Streifen. Die geschätzte Höhe
     * liegt höchstens ein halbes Prozent daneben – zwei Zeilen Abstand
     * reichen bis weit über die größte Kante der QUALITAETEN.
     */
    public static function zeilenJeStreifen(int $breite, int $zeilen): int
    {
        $je = max(1, intdiv((int) self::grenze('streifen_pixel', self::STREIFEN_PIXEL), max(1, $breite) * self::KACHEL));
        return $zeilen > 2 ? max(1, min($je, $zeilen - 2)) : 1;
    }

    /** Ein Verzeichnis samt Inhalt entfernen – nur innerhalb der Kachelordner. */
    private static function verzeichnisWeg(string $pfad): void
    {
        if (!is_dir($pfad) || !str_starts_with($pfad, self::dir())) {
            return;
        }
        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($lauf as $eintrag) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }
        @rmdir($pfad);
    }

    /**
     * Die Stufen unterhalb von $vonZ bis 0, je vier Kacheln auf eine
     * (stufeMitteln()). $raster bekommt Spalten und Zeilen jeder Stufe.
     *
     * Gelesen wird ausschließlich, was bereits auf der Platte liegt –
     * deshalb sind nie mehr als fünf Kacheln gleichzeitig im Speicher,
     * unabhängig davon, wie groß der Plan ist.
     *
     * @param array<int,array{int,int}> $raster
     */
    private static function pyramideUnten(string $ordner, array &$raster, int $vonZ, string $format): int
    {
        $anzahl = 0;
        for ($z = $vonZ - 1; $z >= 0; $z--) {
            $anzahl += self::stufeMitteln($ordner, $z, $raster[$z + 1], $format);
            $raster[$z] = [(int) ceil($raster[$z + 1][0] / 2), (int) ceil($raster[$z + 1][1] / 2)];
        }
        return $anzahl;
    }

    /**
     * Stufe $z aus der Stufe darüber ($oben: deren Spalten und Zeilen): je
     * vier Kacheln auf eine, als genaues Mittel aus 2 × 2 Pixeln.
     *
     * Bis Fassung 1 verkleinerte das imagescale(IMG_BICUBIC_FIXED). Das
     * tastet mit einem B-Spline an geraden Pixeln ab: eine Linie von einem
     * Pixel blieb auf gerader Spalte mit 68 % Kontrast, auf ungerader wurde
     * sie zu zwei Pixeln mit je 16 % – eine Stufe tiefer fiel so jede
     * zweite Haarlinie aus, Schrägen wurden zur Perlschnur. Dazu sank Weiß
     * je Stufe um drei Werte (Stufe 0: 237), und außerhalb der Seite blieb
     * ein Schleier (Alpha 115 statt 127). imagecopyresampled() bei genau
     * der halben Größe ist ein Flächenmittel (höchstens 0,5 daneben) und
     * war gemessen sogar schneller (4,3 statt 5,1 s für 50 Megapixel).
     * Durchsichtige Pixel zählen dabei nach ihrer Deckung.
     *
     * @param array{int,int} $oben
     */
    private static function stufeMitteln(string $ordner, int $z, array $oben, string $format): int
    {
        $spalten = (int) ceil($oben[0] / 2);
        $zeilen  = (int) ceil($oben[1] / 2);
        $anzahl  = 0;
        for ($x = 0; $x < $spalten; $x++) {
            self::plattePruefen($ordner);
            for ($y = 0; $y < $zeilen; $y++) {
                $gross = self::leer($format, self::KACHEL * 2);
                imagealphablending($gross, false);
                foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dy]) {
                    $teil = self::lesen($ordner, $z + 1, $x * 2 + $dx, $y * 2 + $dy, $format);
                    if ($teil === null) {
                        continue;
                    }
                    imagecopy($gross, $teil, $dx * self::KACHEL, $dy * self::KACHEL, 0, 0, self::KACHEL, self::KACHEL);
                    imagedestroy($teil);
                }
                $klein = imagecreatetruecolor(self::KACHEL, self::KACHEL);
                imagealphablending($klein, false);
                imagesavealpha($klein, true);
                imagecopyresampled($klein, $gross, 0, 0, 0, 0, self::KACHEL, self::KACHEL, self::KACHEL * 2, self::KACHEL * 2);
                imagedestroy($gross);
                self::schreiben($klein, $ordner, $z, $x, $y, $format);
                imagedestroy($klein);
                $anzahl++;
            }
        }
        return $anzahl;
    }

    /**
     * Ist das Bild ein Scan oder Foto? Dann werden die Kacheln JPEG –
     * verlustfrei wären sie drei- bis fünfmal so groß (gemessen: Scan als
     * PNG 74–78 MB, als JPEG 22–24 MB) und als WebP zehnmal so langsam.
     *
     * Die Probe: bis zu zwölf Kacheln, über das Bild verteilt, als PNG – im
     * Mittel über FOTO_BYTES ist es einer (Papierrauschen hält jede Kachel
     * groß, ein sauberer CAD-Plan bleibt bei wenigen KB).
     */
    private static function fotoartig(\GdImage $bild): bool
    {
        $spalten = intdiv(imagesx($bild), self::KACHEL);
        $zeilen  = intdiv(imagesy($bild), self::KACHEL);
        if ($spalten === 0 || $zeilen === 0) {
            return false;
        }
        $bytes = $proben = 0;
        $quer = min(4, $spalten);
        $hoch = min(3, $zeilen);
        for ($i = 0; $i < $quer; $i++) {
            for ($j = 0; $j < $hoch; $j++) {
                $x = intdiv((2 * $i + 1) * $spalten, 2 * $quer);
                $y = intdiv((2 * $j + 1) * $zeilen, 2 * $hoch);
                $kachel = imagecreatetruecolor(self::KACHEL, self::KACHEL);
                imagecopy($kachel, $bild, 0, 0, $x * self::KACHEL, $y * self::KACHEL, self::KACHEL, self::KACHEL);
                ob_start();
                imagepng($kachel, null, 6);
                $bytes += strlen((string) ob_get_clean());
                imagedestroy($kachel);
                $proben++;
            }
        }
        return $bytes / $proben > self::FOTO_BYTES;
    }

    /**
     * Das verlustfreie Kachelformat: WebP, wenn gd es verlustfrei schreibt
     * – gemessen 45–49 % der Bytes von PNG, bei gleichen Pixeln –, sonst PNG.
     *
     * Ob es das tut, zeigt ein kleines Muster mit Farben und Alpha, einmal
     * je Prozess: Eine libgd, die IMG_WEBP_LOSSLESS nicht kennt, nähme die
     * Zahl als Qualität und schriebe verlustbehaftet.
     */
    public static function verlustfrei(): string
    {
        static $format = null;
        if ($format !== null) {
            return $format;
        }
        $format = 'png';
        if (!defined('IMG_WEBP_LOSSLESS') || !function_exists('imagewebp') || !function_exists('imagecreatefromwebp')
            || empty(gd_info()['WebP Support'])) {
            return $format;
        }
        $muster = imagecreatetruecolor(16, 16);
        imagealphablending($muster, false);
        imagesavealpha($muster, true);
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                imagesetpixel($muster, $x, $y, (($x * $y * 7) % 128) << 24 | (($x * 37 + $y * 11) % 256) << 16 | (($x * 5 + $y * 71) % 256) << 8 | (($x ^ $y) * 13 % 256));
            }
        }
        ob_start();
        $ok = @imagewebp($muster, null, IMG_WEBP_LOSSLESS);
        $daten = (string) ob_get_clean();
        $zurueck = $ok && $daten !== '' ? @imagecreatefromstring($daten) : false;
        if (!$zurueck instanceof \GdImage) {
            return $format;
        }
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $a = imagecolorat($muster, $x, $y);
                $b = imagecolorat($zurueck, $x, $y);
                // Ganz durchsichtig zählt nur das Alpha – die Farbe darunter sieht keiner.
                if ($a >> 24 !== $b >> 24 || ($a >> 24 !== 127 && ($a & 0xFFFFFF) !== ($b & 0xFFFFFF))) {
                    return $format;
                }
            }
        }
        return $format = 'webp';
    }

    /** Mime-Typ einer Kachel im Format $format. */
    public static function mime(string $format): string
    {
        return match ($format) {
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private static function leer(string $format, int $kante = self::KACHEL): \GdImage
    {
        $bild = imagecreatetruecolor($kante, $kante);
        if ($format !== 'jpg') {
            // Durchsichtige Pläne bleiben durchsichtig.
            imagealphablending($bild, false);
            imagesavealpha($bild, true);
            imagefilledrectangle($bild, 0, 0, $kante - 1, $kante - 1, imagecolorallocatealpha($bild, 255, 255, 255, 127));
            imagealphablending($bild, true);
        } else {
            imagefilledrectangle($bild, 0, 0, $kante - 1, $kante - 1, imagecolorallocate($bild, 255, 255, 255));
        }
        return $bild;
    }

    private static function pfad(string $ordner, int $z, int $x, int $y, string $format): string
    {
        return $ordner . '/' . $z . '/' . $x . '/' . $y . '.' . $format;
    }

    /** Eine Kachel schreiben; zurück kommen ihre Bytes (0: nicht geschrieben). */
    private static function schreiben(\GdImage $bild, string $ordner, int $z, int $x, int $y, string $format): int
    {
        $ziel = self::pfad($ordner, $z, $x, $y, $format);
        $dir  = dirname($ziel);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if ($format !== 'jpg') {
            imagesavealpha($bild, true);
        }
        $ok = match ($format) {
            'png'   => imagepng($bild, $ziel, 6),
            'webp'  => imagewebp($bild, $ziel, IMG_WEBP_LOSSLESS),
            default => imagejpeg($bild, $ziel, self::JPEG_QUALITAET),
        };
        clearstatcache(true, $ziel);
        return $ok ? (int) @filesize($ziel) : 0;
    }

    private static function lesen(string $ordner, int $z, int $x, int $y, string $format): ?\GdImage
    {
        $pfad = self::pfad($ordner, $z, $x, $y, $format);
        if (!is_file($pfad)) {
            return null;
        }
        $bild = match ($format) {
            'png'   => @imagecreatefrompng($pfad),
            'webp'  => @imagecreatefromwebp($pfad),
            default => @imagecreatefromjpeg($pfad),
        };
        if (!$bild instanceof \GdImage) {
            return null;
        }
        if ($format !== 'jpg') {
            imagealphablending($bild, false);
            imagesavealpha($bild, true);
        }
        return $bild;
    }

    /** Bis zu dieser Größe taugt ein ungekachelter Plan als Vorschau am Stück. */
    public const VORSCHAU_BYTES = 2097152;

    /**
     * Ein kleines Bild des ganzen Plans für eine Vorschau (Kundenbereich):
     * die Kachel der Stufe 0 (256 Pixel), wenn der Plan gekachelt ist; sonst
     * der Plan selbst – nur ein Bild (kein PDF) und nur bis VORSCHAU_BYTES.
     * Ein großer Rasterplan ohne Kacheln hat keine Vorschau: am Telefon jedes
     * Mal 40 MB für ein Vorschaubild wären zu viel.
     *
     * @return array{pfad:string,mime:string,kachel:bool}|null
     */
    public static function vorschau(array $floor): ?array
    {
        $pfad = SiteService::planPath($floor);
        if ($pfad === null) {
            return null;
        }
        $meta = self::meta($floor);
        if ($meta !== null && (string) ($meta['quelle'] ?? '') === basename($pfad)) {
            $kachel = self::kachel($floor, 0, 0, 0);
            if ($kachel !== null) {
                return $kachel + ['kachel' => true];
            }
        }
        $mime = SiteService::planMime($floor);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/svg+xml'], true) || (int) filesize($pfad) > self::VORSCHAU_BYTES) {
            return null;
        }
        return ['pfad' => $pfad, 'mime' => $mime, 'kachel' => false];
    }

    /**
     * Stand der Kachelpyramide – er steht in der Adresse jeder Kachel
     * (`?v=`, quelle()).
     *
     * Kacheln bleiben ein Jahr im Browser (KarteController::planKachel).
     * Rechnet der Server einen Plan neu – schärfer, in anderer Größe, mit
     * mehr Stufen –, bekommt die Pyramide einen neuen Stand und damit neue
     * Adressen. Ohne ihn holte der Browser unter derselben Adresse weiter
     * die alte Kachel aus dem Cache, und eine offene Karte setzte Kacheln
     * der neuen Pyramide an die Stellen der alten: gemessen ein
     * Flickenteppich aus zwei Maßstäben.
     *
     * Eine Fassung im eigenen Ordner trägt ihn im Namen (v<stand>, zufällig
     * beim Bau vergeben). Eine Pyramide von vor den Fassungsordnern bekommt
     * ihn aus ihrem kacheln.json: `gebaut` ändert sich bei jedem Bau, die
     * übrigen Felder stehen dabei, damit eine Pyramide aus derselben Sekunde
     * mit anderer Geometrie nicht denselben Stand bekommt.
     */
    public static function stand(array $meta): string
    {
        $ordner = (string) ($meta['ordner'] ?? '');
        if (preg_match(self::FASSUNGSORDNER, $ordner) === 1) {
            return substr($ordner, 1);
        }
        return substr(hash('sha256', implode('|', array_map(
            static fn (string $feld): string => is_scalar($meta[$feld] ?? null) ? (string) $meta[$feld] : '',
            ['quelle', 'gebaut', 'breite', 'hoehe', 'max_zoom', 'kachel', 'format', 'fassung', 'qualitaet']
        ))), 0, 12);
    }

    /** Stand der Pyramide, die die Karte dieser Etage jetzt zeigt – null ohne (passende) Pyramide. */
    public static function aktuellerStand(array $floor): ?string
    {
        $meta = self::meta($floor);
        $pfad = SiteService::planPath($floor);
        return $meta !== null && $pfad !== null && (string) ($meta['quelle'] ?? '') === basename($pfad) ? self::stand($meta) : null;
    }

    /**
     * Eine einzelne Kachel ausliefern – oder null, wenn sie nicht existiert.
     *
     * $stand: der Stand aus der Kacheladresse. Ist es der der aktiven
     * Pyramide, kommt deren Kachel. Ist es der einer abgelösten Fassung,
     * die noch liegt (GNADE nach dem Umschalten), kommt deren Kachel – eine
     * offene Karte zeigt so bis zum Umsteigen weiter ihren Plan statt
     * Lücken. Sonst gibt es die Kachel nicht: Eine Kachel der neuen Pyramide
     * säße an der Stelle einer alten daneben. null: ohne Stand (Seiten von
     * vor dem Update), dann die Kachel, die gerade liegt.
     *
     * @return array{pfad:string,mime:string,stand:string}|null
     */
    public static function kachel(array $floor, int $z, int $x, int $y, ?string $stand = null): ?array
    {
        $meta = self::meta($floor);
        if ($meta === null || $z < 0 || $x < 0 || $y < 0) {
            return null;
        }
        $planOrdner = self::planDir($floor);
        $jetzt = self::stand($meta);
        $ordner = self::kachelOrdner($planOrdner, $meta);
        if ($stand !== null && !hash_equals($jetzt, $stand)) {
            // Nur ein Name, den das Kacheln selbst vergibt – nie ein Pfad aus der Adresse.
            if (preg_match('/^[0-9a-f]{12}$/', $stand) !== 1) {
                return null;
            }
            $ordner = $planOrdner . '/v' . $stand;
            // Nur eine abgelöste Fassung, und nur GNADE Sekunden lang – ganz gleich, wann sie weggeräumt wird.
            $seit = @filemtime($ordner . '/.abgeloest');
            if ($seit === false || time() - $seit > self::GNADE) {
                return null;
            }
            $meta = self::jsonLesen($ordner . '/kacheln.json');
            if ($meta === null || !isset($meta['max_zoom'])) {
                return null;
            }
            $jetzt = $stand;
        }
        if ($z > (int) $meta['max_zoom']) {
            return null;
        }
        $format = (string) ($meta['format'] ?? 'jpg');
        $pfad   = self::pfad($ordner, $z, $x, $y, $format);
        if (!is_file($pfad)) {
            return null;
        }
        return ['pfad' => $pfad, 'mime' => self::mime($format), 'stand' => $jetzt];
    }

    /** Adresse der Kacheln für Leaflet ({z}/{x}/{y}), mit dem Stand der Pyramide. */
    private static function kachelUrl(int $id, array $meta): string
    {
        return '/grundriss/' . $id . '/kachel/{z}/{x}/{y}?v=' . self::stand($meta);
    }

    /** Die Kacheln dieser Etage ganz weg (alle Fassungen, Aufträge) – nur mit Plandatei. */
    public static function aufraeumen(array $floor): void
    {
        // Ohne Plandatei wäre planDir() der Kachelordner aller Pläne.
        if (basename((string) ($floor['plan_file'] ?? '')) === '') {
            return;
        }
        $ordner = self::planDir($floor);
        if (!is_dir($ordner) || $ordner === self::dir() . '/' || !str_starts_with($ordner, self::dir() . '/')) {
            return;
        }
        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($eintraege as $eintrag) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }
        @rmdir($ordner);
    }

    // ------------------------------------------------------- Karte beliefern

    /**
     * Wie die Pyramide gerechnet ist – aus kacheln.json, ohne die Datei zu
     * lesen (ein Kartenaufruf fragt weder pdfinfo noch pdfimages):
     * Fassung, Qualität, ob sie veraltet ist (veraltet()) und ob „fein“
     * sie deutlich schärfer machte (feinMoeglich()). Ließe sich nichts
     * gewinnen, wird nichts angeboten – ein Knopf, der dasselbe Ergebnis
     * noch einmal rechnet, ist schlimmer als keiner.
     *
     * @return array{fassung:int,qualitaet:string,veraltet:bool,fein_moeglich:bool}
     */
    public static function gerechnet(array $meta): array
    {
        return [
            'fassung'       => self::fassung($meta),
            'qualitaet'     => self::qualitaet($meta['qualitaet'] ?? null),
            'veraltet'      => self::veraltet($meta),
            'fein_moeglich' => self::feinMoeglich($meta),
        ];
    }

    /**
     * Was die Oberfläche zum Neurechnen wissen muss – ohne die Plandatei zu
     * lesen: Stand der Pyramide, Zustand des Auftrags (zustand()), ob neu
     * rechnen empfohlen ist (eine ältere Fassung, veraltet()) und ob es
     * überhaupt geht ($rechenbar). Ob es auch darf, entscheidet der
     * Aufrufer (KarteController).
     *
     * @return array{stand:string,zustand:string,zustand_text:string,empfohlen:bool,neu_rechenbar:bool,gebaut:?string,dauer:string}
     */
    private static function neuRechnen(array $floor, ?array $meta, bool $rechenbar): array
    {
        $zustand = self::zustand($floor);
        return [
            'stand'         => $meta !== null ? self::stand($meta) : '',
            'zustand'       => $zustand['zustand'],
            'zustand_text'  => $zustand['meldung'],
            'empfohlen'     => $meta !== null && self::veraltet($meta),
            'neu_rechenbar' => $rechenbar,
            'gebaut'        => is_string($meta['gebaut'] ?? null) ? $meta['gebaut'] : null,
            'dauer'         => self::dauerText($meta),
        ];
    }

    /**
     * Antwort des Status-Endpunkts (KarteController::planStand): Zustand des
     * Auftrags und die Pyramide, die jetzt gilt – genug, damit eine offene
     * Karte auf die neue umsteigen kann. Ohne Pfade, ohne Benutzernamen,
     * ohne die Plandatei zu lesen.
     *
     * @return array{zustand:string,meldung:string,seit:?string,stand:?string,plan:?array{kachel_url:string,breite:int,hoehe:int,max_zoom:int,kachel:int},fassung:?int,qualitaet:?string,empfohlen:bool,gebaut:?string,dauer:string}
     */
    public static function status(array $floor): array
    {
        $meta = self::meta($floor);
        $pfad = SiteService::planPath($floor);
        if ($meta !== null && ($pfad === null || (string) ($meta['quelle'] ?? '') !== basename($pfad))) {
            $meta = null;
        }
        $zustand = self::zustand($floor);
        return [
            'zustand'   => $zustand['zustand'],
            'meldung'   => $zustand['meldung'],
            'seit'      => $zustand['seit'],
            'stand'     => $meta !== null ? self::stand($meta) : null,
            'plan'      => $meta === null ? null : [
                'kachel_url' => self::kachelUrl((int) $floor['id'], $meta),
                'breite'     => (int) $meta['breite'],
                'hoehe'      => (int) $meta['hoehe'],
                'max_zoom'   => (int) $meta['max_zoom'],
                'kachel'     => (int) ($meta['kachel'] ?? self::KACHEL),
            ],
            'fassung'   => $meta !== null ? self::fassung($meta) : null,
            'qualitaet' => $meta !== null ? self::qualitaet($meta['qualitaet'] ?? null) : null,
            'empfohlen' => $meta !== null && self::veraltet($meta),
            // Für das Fenster „Plan neu rechnen“, schon in der Zeitzone der Installation.
            'gebaut'    => is_string($meta['gebaut'] ?? null) ? Zeit::anzeige($meta['gebaut']) : null,
            'dauer'     => self::dauerText($meta),
        ];
    }

    /**
     * Eine Zeile für die Standortseite: Maße und Fassung der Pyramide, der
     * Zustand des Auftrags und ob neu rechnen geht – null für einen Plan,
     * der nicht gekachelt wird (SVG, kleines Bild) und nie wurde.
     *
     * Liest nur kacheln.json und den Auftrag; ob poppler da ist, nur bei
     * einem PDF ohne Kacheln (Programm::finden(), einmal je Anfrage).
     *
     * @return array{breite:int,hoehe:int,fassung:int,veraltet:bool,qualitaet:string,zustand:string,zustand_text:string,neu_rechenbar:bool,gekachelt:bool}|null
     */
    public static function uebersicht(array $floor): ?array
    {
        $pfad = SiteService::planPath($floor);
        if ($pfad === null) {
            return null;
        }
        $meta = self::meta($floor);
        if ($meta !== null && (string) ($meta['quelle'] ?? '') !== basename($pfad)) {
            $meta = null;
        }
        $zustand = self::zustand($floor);
        if ($meta === null) {
            // Ein PDF ohne Kacheln, sobald poppler da ist; ein Bild, wenn es Kacheln bekäme (lohnt(), liest nur den Kopf) –
            // etwa weil sein Bau im Hintergrund starb.
            $pdf = strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) === 'pdf';
            $rechenbar = $pdf ? extension_loaded('gd') && PdfRasterService::verfuegbar() : self::lohnt($pfad);
            if (!$pdf && !$rechenbar && $zustand['zustand'] === 'keiner') {
                return null;
            }
        } else {
            $rechenbar = extension_loaded('gd');
        }
        return [
            'breite'        => (int) ($meta['breite'] ?? 0),
            'hoehe'         => (int) ($meta['hoehe'] ?? 0),
            'fassung'       => $meta !== null ? self::fassung($meta) : 0,
            'veraltet'      => $meta !== null && self::veraltet($meta),
            'qualitaet'     => self::qualitaet($meta['qualitaet'] ?? null),
            'zustand'       => $zustand['zustand'],
            'zustand_text'  => $zustand['meldung'],
            'neu_rechenbar' => $rechenbar,
            'gekachelt'     => $meta !== null,
        ];
    }

    /**
     * Alles, was die Karte im Browser über diesen Plan wissen muss.
     *
     * Bei Kacheln dazu, wie sie gerechnet sind (gerechnet()), und was es
     * zum Neurechnen braucht (neuRechnen()); die Kacheladresse trägt den
     * Stand der Pyramide (stand()).
     *
     * @return array{art:string,url:string,breite:int,hoehe:int,seite:int,max_zoom:int,zusatz:int,kachel_url:?string,kachel:int,fassung?:int,qualitaet?:string,veraltet?:bool,fein_moeglich?:bool,stand?:string,zustand?:string,zustand_text?:string,empfohlen?:bool,neu_rechenbar?:bool,gebaut?:?string,dauer?:string}|null
     */
    public static function quelle(array $floor): ?array
    {
        $pfad = SiteService::planPath($floor);
        if ($pfad === null) {
            return null;
        }

        $id  = (int) $floor['id'];
        $url = '/grundriss/' . $id . '/datei';
        $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            $meta = self::meta($floor);
            if ($meta !== null && (string) ($meta['quelle'] ?? '') === basename($pfad)) {
                // Der Regelfall: das PDF liegt gerastert als Kachelpyramide
                // vor und wird wie jeder andere Plan ausgeliefert.
                return [
                    'art'        => 'kacheln',
                    'url'        => $url,
                    'breite'     => (int) $meta['breite'],
                    'hoehe'      => (int) $meta['hoehe'],
                    'seite'      => 1,
                    'max_zoom'   => (int) $meta['max_zoom'],
                    'zusatz'     => 2,
                    'kachel_url' => self::kachelUrl($id, $meta),
                    'kachel'     => (int) ($meta['kachel'] ?? self::KACHEL),
                ] + self::gerechnet($meta) + self::neuRechnen($floor, $meta, extension_loaded('gd'));
            }

            /*
             * Noch keine Kacheln. Gezeichnet wird dann im Browser – aber
             * nur, bis er den Plan einmal aufbereitet hat.
             *
             * Dabei gilt eine Zoomgrenze: pdf.js bekommt den Ausschnitt
             * über einen Viewport der ganzen Seite in Kachelauflösung, und
             * der wächst je Stufe auf das Vierfache. Gemessen kommen ab
             * rund 256 Megapixeln Viewport leere Kacheln zurück, in Safari
             * mit seiner deutlich kleineren Canvas-Fläche entsprechend
             * früher. Lieber zwei Stufen weniger als ein leerer Plan.
             *
             * `aufbereiten` sagt der Seite, welcher Weg offensteht:
             * „server", wenn poppler da ist und nur noch gerechnet werden
             * muss, „browser", wenn der Browser einspringt – und
             * „unmoeglich", wenn gd fehlt. Dann kann weder der Server
             * rechnen noch der Browser seine Streifen loswerden, und auf
             * die Seite gehört kein Knopf, sondern der Satz, was zu tun
             * ist. Ein Knopf, der nichts bewirken kann, ist schlimmer als
             * keiner.
             */
            $gdFehlt = !extension_loaded('gd');
            $aufbereiten = $gdFehlt ? 'unmoeglich' : (PdfRasterService::verfuegbar() ? 'server' : 'browser');

            return [
                'art'         => 'pdf',
                'url'         => $url,
                'breite'      => 0,
                'hoehe'       => 0,
                'seite'       => max(1, (int) $floor['plan_page']),
                'max_zoom'    => 0,
                'zusatz'      => self::PDF_ZUSATZ_BROWSER,
                'kachel_url'  => null,
                'kachel'      => 512,
                'aufbereiten' => $aufbereiten,
                'hindernis'   => $gdFehlt ? self::GD_FEHLT : '',
                'laeuft'      => !$gdFehlt && self::laeuft($floor),
            ] + self::neuRechnen($floor, null, $aufbereiten === 'server');
        }

        $masse = self::masse($pfad) ?? ['breite' => 1191, 'hoehe' => 842];
        if ($ext === 'svg') {
            $masse = self::svgRaum($masse);
        }
        $meta  = self::meta($floor);

        if ($meta !== null && (string) ($meta['quelle'] ?? '') === basename($pfad)) {
            return [
                'art'        => 'kacheln',
                'url'        => $url,
                'breite'     => (int) $meta['breite'],
                'hoehe'      => (int) $meta['hoehe'],
                'seite'      => 1,
                'max_zoom'   => (int) $meta['max_zoom'],
                // Über die Originalauflösung hinaus wird ein Rasterplan nur
                // unscharf – zwei Stufen zum Heranholen reichen.
                'zusatz'     => 2,
                'kachel_url' => self::kachelUrl($id, $meta),
                'kachel'     => (int) ($meta['kachel'] ?? self::KACHEL),
            ] + self::gerechnet($meta) + self::neuRechnen($floor, $meta, extension_loaded('gd'));
        }

        /*
         * Ein Bild ohne Kacheln kommt am Stück. Bekäme es welche (lohnt():
         * nur der Kopf der Datei) – ein großer Plan, dessen Bau im
         * Hintergrund starb –, gibt es dafür „Plan aufbereiten“; sonst
         * stand bis zur nächsten Nacht das ganze Bild in der Karte.
         */
        $bild = [
            'art'        => 'bild',
            'url'        => $url,
            'breite'     => $masse['breite'],
            'hoehe'      => $masse['hoehe'],
            'seite'      => 1,
            'max_zoom'   => self::maxZoom($masse['breite'], $masse['hoehe']),
            // SVG skaliert verlustfrei, ein Rasterbild nicht.
            'zusatz'     => $ext === 'svg' ? 4 : 2,
            'kachel_url' => null,
            'kachel'     => self::KACHEL,
        ];
        return $ext === 'svg' ? $bild : $bild + self::neuRechnen($floor, null, self::lohnt($pfad));
    }
}
