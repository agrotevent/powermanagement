<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use App\Support\Programm;

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
 * groß der Plan auch ist. Bei einem PDF rechnet poppler die kleinen Stufen
 * (bis STREIFEN_PIXEL) selbst – dort zeigt die Übersicht Haarlinien wie ein
 * PDF-Betrachter, statt sie wegzumitteln (siehe schneidenPdf()).
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
 * eine Weile selbst (bauen() mit Frist) und gibt den Rest an einen eigenen
 * Prozess (imHintergrund()); was dabei liegen bleibt, holt die Wartung nach.
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
     * poppler, PDF bis 200 Megapixel, JPEG in Qualität 92, WebP.
     */
    public const FASSUNG = 2;

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

    /** So lange dürfen die kleinen Stufen eines PDF bei poppler mindestens dauern (Sekunden). */
    public const KLEINE_STUFEN_ZEIT = 60;

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

    /** Bereits berechnete Pyramide, sonst null. */
    public static function meta(array $floor): ?array
    {
        $pfad = self::metaPfad($floor);
        if (!is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);
        return is_array($daten) && isset($daten['breite'], $daten['max_zoom']) ? $daten : null;
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
        // Wich der Bau schon wegen Zeit oder Platte aus, hülfe „fein“ nicht.
        return self::qualitaet($meta['qualitaet'] ?? null) !== 'fein' && !in_array($meta['grenze'] ?? null, ['zeit', 'platte'], true)
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
        $pfad = $ordner . '/.sperre';
        if (!is_file($pfad)) {
            return false;
        }
        $griff = @fopen($pfad, 'r');
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
     * Ist die Pyramide schon vorhanden, passiert nichts. Gleichzeitige
     * Aufrufe werden über eine Sperrdatei abgefangen – beim ersten Aufruf
     * der Seite lädt sonst jede Kachelanfrage den Bau erneut an.
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
        if (!$neu && ($meta = self::meta($floor)) !== null) {
            return ['ok' => true, 'message' => 'Kacheln liegen bereits vor.', 'kacheln' => (int) $meta['kacheln'], 'meta' => $meta];
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

        $ordner = self::planDir($floor);
        if (!is_dir($ordner) && !mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return ['ok' => false, 'message' => 'Kachelordner konnte nicht angelegt werden.', 'kacheln' => 0, 'meta' => null];
        }

        $sperre = fopen($ordner . '/.sperre', 'c');
        if ($sperre === false) {
            return ['ok' => false, 'message' => 'Kachelordner ist nicht beschreibbar.', 'kacheln' => 0, 'meta' => null];
        }
        if (!flock($sperre, LOCK_EX | LOCK_NB)) {
            if (!$warten) {
                // Beim Seitenaufruf: einer rechnet schon, ein zweiter
                // Arbeiter, der danebensteht und wartet, bringt nichts.
                fclose($sperre);
                return ['ok' => false, 'message' => 'Der Plan wird gerade berechnet.', 'kacheln' => 0, 'meta' => null];
            }
            // Ein anderer Aufruf baut gerade – auf dessen Ergebnis warten.
            flock($sperre, LOCK_EX);
            flock($sperre, LOCK_UN);
            fclose($sperre);
            $meta = self::meta($floor);
            return ['ok' => $meta !== null, 'message' => 'Kacheln wurden bereits gebaut.', 'kacheln' => (int) ($meta['kacheln'] ?? 0), 'meta' => $meta];
        }

        try {
            /*
             * Was die vorhandene Pyramide schon festgelegt hat, bleibt: die
             * Qualität, wenn keine verlangt ist, und das Seitenverhältnis.
             * Anlagen, Maßstab und Georeferenz stehen in Prozent von Breite
             * und Höhe – mit einem anderen Verhältnis säßen sie daneben.
             */
            $alt = self::meta($floor);
            if ($alt !== null && ((string) ($alt['quelle'] ?? '') !== basename($quelle)
                || (int) ($alt['seite'] ?? 1) !== max(1, (int) ($floor['plan_page'] ?? 1)))) {
                $alt = null;
            }
            $qualitaet = self::qualitaet($qualitaet ?? ($alt['qualitaet'] ?? null));
            $ergebnis = self::schneiden($floor, $quelle, $ordner, $bis, $qualitaet, $alt);
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }

        /*
         * Wurde die Etage gelöscht (oder bekam sie einen anderen Plan),
         * während hier gerechnet wurde – im Hintergrund dauert das Minuten –,
         * hat SiteService die Kacheln schon weggeräumt; geschrieben wurde
         * danach weiter, der Ordner entstand neu. Ohne Etage, die auf ihn
         * zeigt, kommt er wieder weg.
         */
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
        $ordner = self::planDir($floor);
        foreach (Database::select('SELECT plan_file, plan_page FROM floors WHERE plan_file = ?', [$datei]) as $f) {
            if (self::planDir($f) === $ordner) {
                return false;
            }
        }
        return true;
    }

    /** Ein abgebrochener oder gescheiterter Bau: Zoomstufen und kacheln.json weg. */
    private static function teilbauWeg(array $floor): void
    {
        // Erst das meta – ohne es zeigt die Karte keine Kacheln mehr an,
        // die gleich darauf fehlen; der Bau beginnt beim nächsten Mal von vorn.
        @unlink(self::metaPfad($floor));
        foreach (glob(self::planDir($floor) . '/[0-9]*', GLOB_ONLYDIR) ?: [] as $teil) {
            self::verzeichnisWeg($teil);
        }
    }

    /**
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
        for ($x = 0; $x < $spalten; $x++) {
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
        self::metaSchreiben($floor, $meta);
        self::ueberzaehligeWeg($ordner, $raster, $format);

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
     * Die Stufen darunter entstehen durch Mitteln (stufeMitteln()) – bis
     * auf die kleinen (bis STREIFEN_PIXEL, ein Aufruf je Stufe): die rechnet
     * poppler selbst. Ein Mittel ist physikalisch richtig, nur macht es
     * eine Haarlinie je Stufe halb so kräftig; zwei Stufen unter der
     * obersten war sie gemessen nicht mehr zu sehen (0 % der Linienpunkte
     * mit einem Viertel Kontrast), ein PDF-Betrachter zeichnet sie aber
     * mindestens ein Pixel breit (direkt gerechnet 87–95 %). Bei schweren
     * Plänen kostet jeder Aufruf so lange wie ein Streifen oder länger – sind
     * die kleinen Stufen absehbar teurer als kleineDirekt() erlaubt, werden
     * auch sie gemittelt.
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
        $abgebrochen = static function () use ($floor): array {
            // Der Aufrufer wartet nicht länger (Upload): ein halber Plan
            // hilft niemandem, also weg damit – imHintergrund() rechnet ihn.
            self::teilbauWeg($floor);
            return ['ok' => false, 'abgebrochen' => true, 'message' => 'Die Zeit im Upload ist um – der Plan wird im Hintergrund fertig gerechnet.', 'kacheln' => 0, 'meta' => null];
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
        foreach (self::NOTSTUFEN as $notstufe) {
            if ($notstufe < self::QUALITAETEN[$qualitaet]['pixel']) {
                $flaechen[] = $notstufe;
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
            Logger::info(sprintf('Kacheln: %s × %s Pixel %s – es wird kleiner gerechnet.', $ziel['breite'], $ziel['hoehe'],
                $ausgewichen === 'zeit' ? 'dauerten länger als ' . intdiv(self::ZEITBUDGET, 60) . ' Minuten' : 'passten nicht auf die Platte'));
        }
        if (!empty($oben['abgebrochen'])) {
            return $abgebrochen();
        }
        if (!$oben['ok']) {
            // Was bis hierher geschnitten ist, passt zu keiner Pyramide.
            if ($oben['anzahl'] > 0) {
                self::teilbauWeg($floor);
            }
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
         * Die Stufen darunter. Bis eine in einen Aufruf passt, wird
         * gemittelt; die kleinen rechnet poppler, solange kleineDirekt()
         * die Zeit dafür gibt – sonst wird ab da weiter gemittelt.
         */
        $selbst  = $bild === null;
        $je      = $oben['sekunden'] / max(1, $oben['aufrufe']);
        $budget  = max(self::KLEINE_STUFEN_ZEIT, 0.5 * $oben['sekunden']);
        $bisher  = 0.0;
        for ($z = $maxZ - 1; $z >= 0; $z--) {
            $kanteZ = max(1, (int) round($ziel['kante'] / 2 ** ($maxZ - $z)));
            $masseZ = PdfRasterService::masseAus($verhaeltnis, $kanteZ);
            if ($selbst && $masseZ['breite'] * $masseZ['hoehe'] <= self::STREIFEN_PIXEL) {
                $selbst = self::kleineDirekt($bisher, $je, $z + 1, $budget);
            }
            if ($selbst && $masseZ['breite'] * $masseZ['hoehe'] <= self::STREIFEN_PIXEL) {
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
                    $je = max($je, $stufe['sekunden']);
                    continue;
                }
                // poppler mag diese Stufe nicht: gemittelt ist sie auch gut.
                Logger::info('Kacheln: Stufe ' . $z . ' ließ sich nicht direkt rechnen (' . $stufe['message'] . ') – sie wird gemittelt.');
                $selbst = false;
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
        self::metaSchreiben($floor, $meta);
        self::ueberzaehligeWeg($ordner, $raster, $format);

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
     * Noch eine kleine Stufe direkt von poppler? Ja, solange die bisher
     * dafür gebrauchte Zeit und die geschätzte für die übrigen $rest Stufen
     * ($je Sekunden je Aufruf – so lange wie ein Streifen der obersten Stufe
     * oder die teuerste kleine Stufe bis hier) in $budget passen
     * (KLEINE_STUFEN_ZEIT, mindestens die Hälfte der obersten Stufe).
     */
    public static function kleineDirekt(float $bisher, float $je, int $rest, float $budget): bool
    {
        return $bisher + $je * $rest <= $budget;
    }

    /**
     * Würde die oberste Stufe zu teuer? Hochgerechnet aus dem ersten
     * Streifen: $sekunden und $bytes für $kacheln Kacheln, $streifen
     * Streifen insgesamt, $alle Kacheln der obersten Stufe. Die Stufen
     * darunter kommen mit einem Drittel dazu, und auf der Platte soll das
     * Dreifache frei sein ($frei, null: unbekannt).
     *
     * @return 'zeit'|'platte'|null
     */
    public static function zuTeuer(float $sekunden, int $streifen, int $bytes, int $kacheln, int $alle, ?float $frei): ?string
    {
        if ($sekunden * $streifen > self::ZEITBUDGET) {
            return 'zeit';
        }
        if ($frei !== null && $kacheln > 0 && 3 * ($bytes / $kacheln) * $alle * 4 / 3 > $frei) {
            return 'platte';
        }
        return null;
    }

    /**
     * Eine Stufe der Pyramide mit poppler rendern und schneiden – die
     * oberste in Streifen, eine kleine (bis STREIFEN_PIXEL) in einem Aufruf.
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
     * 'zu_teuer' aufhören, statt weiterzurechnen.
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

        $jeStreifen = $breite * $zeilen * self::KACHEL <= self::STREIFEN_PIXEL ? $zeilen + 2 : self::zeilenJeStreifen($breite, $zeilen);
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
                $frei = @disk_free_space($ordner);
                $teuer = self::zuTeuer(microtime(true) - $beginn, (int) ceil($zeilen / $jeStreifen), $bytes, $geschrieben,
                    $spalten * $zeilen, $frei === false ? null : (float) $frei);
                if ($teuer !== null) {
                    return $ergebnis(false, '', ['zu_teuer' => $teuer]);
                }
            }

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
     * Kacheln in einem eigenen Prozess rechnen lassen – für den Upload, der
     * nicht länger warten kann (SiteService::KACHELZEIT_IM_UPLOAD).
     *
     * Ein eigener Prozess statt Weiterrechnen nach der Antwort: Der Pool
     * zählt mit max_execution_time (300 s, als php_admin_value –
     * set_time_limit() greift dort nicht) die Rechenzeit der ganzen Anfrage,
     * und ein PDF mit 60 Seiten braucht mehr. `bin/power plan:kacheln` läuft
     * auf der Kommandozeile ohne diese Grenze, als derselbe Benutzer, und
     * hält keinen PHP-FPM-Arbeiter fest.
     *
     * Die Ausgabe geht nach storage/logs/kacheln.log: /dev/null darf PHP
     * unter open_basedir nicht öffnen, und ein geschlossenes Rohr beendete
     * den Prozess bei der ersten Zeile. Stirbt er (php-fpm neu gestartet),
     * rechnet die nächtliche Wartung nach (`bin/power wartung`).
     *
     * @param list<int> $etagenIds
     */
    public static function imHintergrund(array $etagenIds): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $etagenIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
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
        $prozess = @proc_open(
            [$php, $wurzel . '/bin/power', 'plan:kacheln', '--etage=' . implode(',', $ids)],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $rohre
        );
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
     * Grundrisse ohne Kacheln nachrechnen – für die nächtliche Wartung.
     *
     * Der Upload rechnet große Pläne in einem eigenen Prozess weiter
     * (imHintergrund). Starb der – php-fpm beim Update neu gestartet, der
     * Container neu gebootet –, fehlen die Kacheln bis hierher. Wer gerade
     * rechnet, wird nicht gestört; was nicht gekachelt wird (SVG, kleine
     * Bilder), fällt gar nicht erst auf.
     *
     * @return list<string> eine Zeile je Etage, für die etwas getan wurde
     */
    public static function fehlendeNachrechnen(): array
    {
        $meldungen = [];
        foreach (SiteService::allFloors() as $etage) {
            $pfad = SiteService::planPath($etage);
            if ($pfad === null || self::meta($etage) !== null || !self::lohnt($pfad) || self::laeuft($etage)) {
                continue;
            }
            // Wie im Hintergrund nie neben einem anderen Bau (nacheinander()).
            $ergebnis = self::nacheinander(static function () use ($etage): ?array {
                // Während gewartet wurde, kann die Etage gelöscht oder schon gerechnet sein.
                $jetzt = SiteService::floor((int) $etage['id']);
                return $jetzt === null || self::meta($jetzt) !== null ? null : self::bauen($jetzt, false, false);
            });
            if ($ergebnis === null) {
                continue;
            }
            $meldungen[] = ($ergebnis['ok'] ? 'Kacheln nachgerechnet: ' : 'Kacheln fehlen weiter: ')
                . $etage['site_name'] . ' / ' . $etage['name'] . ' – ' . $ergebnis['message'];
        }
        return $meldungen;
    }

    /** Wo die Prozesse im Hintergrund aufeinander warten (nacheinander()). */
    public static function hintergrundSperre(): string
    {
        return self::dir() . '/.hintergrund';
    }

    /**
     * $arbeit rechnen, aber nie neben einem anderen Bau im Hintergrund – für
     * bin/power plan:kacheln (so rechnet imHintergrund()) und die Wartung.
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
     * eine Zeile, wenn gewartet werden muss.
     *
     * @template T
     * @param callable():T $arbeit
     * @param (callable(string):void)|null $warten
     * @return T
     */
    public static function nacheinander(callable $arbeit, ?callable $warten = null): mixed
    {
        // Zum Sperren genügt Lesen – auch wenn root die Datei einmal angelegt hat.
        $griff = @fopen(self::hintergrundSperre(), 'c') ?: @fopen(self::hintergrundSperre(), 'r');
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
                flock($griff, LOCK_EX);
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
        $je = max(1, intdiv(self::STREIFEN_PIXEL, max(1, $breite) * self::KACHEL));
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

    /**
     * kacheln.json schreiben – über eine Zwischendatei und rename(): so
     * liest die Karte nie ein halbes (direkt geschrieben war gemessen jede
     * fünfzigste Lesung unlesbar, während gerade geschrieben wurde).
     */
    private static function metaSchreiben(array $floor, array $meta): void
    {
        $pfad = self::metaPfad($floor);
        $zwischen = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($zwischen, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !@rename($zwischen, $pfad)) {
            @unlink($zwischen);
        }
    }

    /**
     * Was nach einem Bau nicht mehr zur Pyramide gehört: Stufen über der
     * obersten, Kacheln außerhalb des Rasters einer Stufe ($raster: Spalten
     * und Zeilen je Stufe) und in einem anderen Format – Reste einer
     * früheren Pyramide derselben Datei. Angefasst werden nur Namen, die
     * das Kacheln selbst vergibt (Stufe/Spalte/Zeile.Format).
     *
     * @param array<int,array{int,int}> $raster
     */
    private static function ueberzaehligeWeg(string $ordner, array $raster, string $format): void
    {
        foreach (scandir($ordner) ?: [] as $stufe) {
            $pfadZ = $ordner . '/' . $stufe;
            if (preg_match('/^\d{1,2}$/', $stufe) !== 1 || !is_dir($pfadZ) || is_link($pfadZ)) {
                continue;
            }
            if (!isset($raster[(int) $stufe])) {
                self::verzeichnisWeg($pfadZ);
                continue;
            }
            [$spalten, $zeilen] = $raster[(int) $stufe];
            foreach (scandir($pfadZ) ?: [] as $spalte) {
                $pfadX = $pfadZ . '/' . $spalte;
                if (preg_match('/^\d+$/', $spalte) !== 1 || !is_dir($pfadX) || is_link($pfadX)) {
                    continue;
                }
                if ((int) $spalte >= $spalten) {
                    self::verzeichnisWeg($pfadX);
                    continue;
                }
                foreach (scandir($pfadX) ?: [] as $datei) {
                    if (preg_match('/^(\d+)\.(png|jpg|webp)$/', $datei, $m) === 1 && ((int) $m[1] >= $zeilen || $m[2] !== $format)) {
                        @unlink($pfadX . '/' . $datei);
                    }
                }
            }
        }
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

    /** Eine einzelne Kachel ausliefern – oder null, wenn sie nicht existiert. */
    public static function kachel(array $floor, int $z, int $x, int $y): ?array
    {
        $meta = self::meta($floor);
        if ($meta === null || $z < 0 || $x < 0 || $y < 0 || $z > (int) $meta['max_zoom']) {
            return null;
        }
        $format = (string) ($meta['format'] ?? 'jpg');
        $pfad   = self::pfad(self::planDir($floor), $z, $x, $y, $format);
        if (!is_file($pfad)) {
            return null;
        }
        return ['pfad' => $pfad, 'mime' => self::mime($format)];
    }

    public static function aufraeumen(array $floor): void
    {
        $ordner = self::planDir($floor);
        if (!is_dir($ordner) || !str_starts_with($ordner, self::dir() . '/')) {
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
     * Alles, was die Karte im Browser über diesen Plan wissen muss.
     *
     * Bei Kacheln dazu, wie sie gerechnet sind (gerechnet()).
     *
     * @return array{art:string,url:string,breite:int,hoehe:int,seite:int,max_zoom:int,zusatz:int,kachel_url:?string,kachel:int,fassung?:int,qualitaet?:string,veraltet?:bool,fein_moeglich?:bool}|null
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
                    'kachel_url' => '/grundriss/' . $id . '/kachel/{z}/{x}/{y}',
                    'kachel'     => (int) ($meta['kachel'] ?? self::KACHEL),
                ] + self::gerechnet($meta);
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
                'aufbereiten' => $gdFehlt
                    ? 'unmoeglich'
                    : (PdfRasterService::verfuegbar() ? 'server' : 'browser'),
                'hindernis'   => $gdFehlt ? self::GD_FEHLT : '',
                'laeuft'      => !$gdFehlt && self::laeuft($floor),
            ];
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
                'kachel_url' => '/grundriss/' . $id . '/kachel/{z}/{x}/{y}',
                'kachel'     => (int) ($meta['kachel'] ?? self::KACHEL),
            ] + self::gerechnet($meta);
        }

        return [
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
    }
}
