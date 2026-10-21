<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Kacheln für Grundrisse.
 *
 * Ein Hallenplan ist schnell 8000 Pixel breit. Als ein Bild geladen ruckelt
 * die Übersicht und der Browser hält das Ganze unskaliert im Speicher. Die
 * Karte holt darum nur die Ausschnitte, die gerade zu sehen sind – dafür
 * wird der Plan einmal in eine Kachelpyramide zerlegt:
 *
 *   Stufe maxZoom  Plan in Originalgröße, in 256er-Kacheln geschnitten
 *   Stufe z-1      je vier Kacheln der Stufe darüber auf eine verkleinert
 *   …
 *   Stufe 0        der ganze Plan in einer Kachel (Übersicht)
 *
 * Gebaut wird von oben nach unten aus den bereits geschriebenen Kacheln.
 * Dadurch liegen nie mehr als fünf Kacheln gleichzeitig im Speicher, auch
 * bei einem Plan mit 50 Megapixeln.
 *
 * PDF wird hier ebenfalls gekachelt, seit der Weg über pdf.js im Browser
 * sich als Sackgasse erwiesen hat (die Begründung steht in
 * PdfRasterService). Gerastert wird streifenweise, damit auch ein A3-Plan
 * in 6144 px Breite nicht den Speicher sprengt. Fehlt poppler auf dem
 * Server, bleibt es beim Zeichnen im Browser – dann aber mit begrenzter
 * Zoomtiefe, siehe quelle().
 *
 * SVG bleibt ungekachelt – es skaliert von sich aus verlustfrei.
 */
final class PlanTileService
{
    /** Kantenlänge einer Kachel in Pixeln (Leaflet-Vorgabe). */
    public const KACHEL = 256;

    /** Darunter lohnt das Kacheln nicht – das Bild kommt am Stück. */
    public const AB_PIXELN = 1600;

    /** Obergrenze, damit ein riesiger Scan den Server nicht lahmlegt. */
    public const MAX_PIXEL = 50000000;

    /**
     * Zusätzliche Zoomstufen, wenn ein PDF im Browser gezeichnet wird.
     *
     * Der Viewport, den pdf.js dafür aufspannt, vervierfacht sich je
     * Stufe. Bei einem A3-Plan liegt Stufe maxZoom+1 noch unter Safaris
     * Canvas-Grenze von rund 16,8 Megapixeln, Stufe +2 darüber. Mehr als
     * eine Stufe Reserve ist deshalb nicht zu haben.
     */
    public const PDF_ZUSATZ_BROWSER = 1;

    /** Zwischenlager für Kacheln, die der Browser schickt. */
    private const ROH = '/.roh';

    /** Ein Streifen ist so breit wie der Plan – aber keine 12 MB groß. */
    private const MAX_STREIFEN_BYTES = 12582912;

    /** Mehr Kachelzeilen als das hat kein Plan, der unter MAX_PIXEL bleibt. */
    private const MAX_ZEILEN = 512;

    /**
     * Längste Kante, auf die ein Plan gerechnet wird.
     *
     * Darüber wird die Datei größer als der Nutzen: 12288 px sind bei A3
     * rund 740 dpi, und die Fläche bleibt unter MAX_PIXEL.
     */
    public const MAX_KANTE = 12288;

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
        $pfad = self::planDir($floor) . '/.sperre';
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

        return null;
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
            return false;
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
     * @return array{ok:bool,message:string,kacheln:int,meta:?array}
     */
    public static function bauen(array $floor, bool $neu = false, bool $warten = true): array
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
            return ['ok' => false, 'message' => 'Für diese Datei werden keine Kacheln gebraucht.', 'kacheln' => 0, 'meta' => null];
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
            return self::schneiden($floor, $quelle, $ordner);
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }

    private static function schneiden(array $floor, string $quelle, string $ordner): array
    {
        $start  = microtime(true);
        $ext    = strtolower(pathinfo($quelle, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return self::schneidenPdf($floor, $quelle, $ordner, $start);
        }
        $format = $ext === 'png' ? 'png' : 'jpg';

        $bild = $format === 'png' ? @imagecreatefrompng($quelle) : @imagecreatefromjpeg($quelle);
        if (!$bild instanceof \GdImage) {
            return ['ok' => false, 'message' => 'Bilddatei konnte nicht gelesen werden.', 'kacheln' => 0, 'meta' => null];
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

        // ---- darunterliegende Stufen aus je vier Kacheln der Stufe darüber
        $anzahl += self::pyramideUnten($ordner, $spalten, $zeilen, $maxZ, $format);

        $meta = [
            'breite'   => $breite,
            'hoehe'    => $hoehe,
            'max_zoom' => $maxZ,
            'kachel'   => self::KACHEL,
            'format'   => $format,
            'kacheln'  => $anzahl,
            'quelle'   => basename($quelle),
            'gebaut'   => gmdate('c'),
            'dauer_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        file_put_contents(self::metaPfad($floor), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
     * Gearbeitet wird streifenweise: poppler rendert die volle Breite und
     * eine Kachelhöhe, daraus wird eine Kachelzeile geschnitten, dann
     * kommt der nächste Streifen. So hängt der Speicherbedarf an der
     * Breite des Plans, nicht an seiner Fläche – bei 6144 px sind das rund
     * 8 MB statt rund 100 MB für die ganze Seite.
     *
     * @return array{ok:bool,message:string,kacheln:int,meta:?array}
     */
    private static function schneidenPdf(array $floor, string $quelle, string $ordner, float $start): array
    {
        $seite = max(1, (int) ($floor['plan_page'] ?? 1));

        /*
         * Erst die Zielauflösung, dann die Maße – in dieser Reihenfolge.
         *
         * Ein großer Plan verträgt und braucht mehr Pixel als ein kleiner:
         * auf einem A0-Hallenplan steht bei gleicher Kantenlänge viermal so
         * viel auf derselben Fläche. Die Zielauflösung wächst deshalb mit
         * der Seite, gedeckelt durch MAX_KANTE und die Flächengrenze.
         */
        $kante = self::zielKante($quelle, $seite);
        $masse = PdfRasterService::masse($quelle, $seite, $kante);
        if ($masse === null) {
            return [
                'ok'      => false,
                'message' => 'Das PDF ließ sich nicht rastern. ' . PdfRasterService::werkzeug()['hinweis'],
                'kacheln' => 0,
                'meta'    => null,
            ];
        }

        /*
         * Die Fläche wird nicht größer, als die Obergrenze erlaubt – lieber
         * ein etwas gröberer Plan als ein Server, der am Hochladen erstickt.
         *
         * Auch hier wird nicht halbiert: zielKante() rechnet die Grenze aus
         * dem Seitenverhältnis aus, das aus einer 256 Pixel breiten Probe
         * stammt und deshalb um ein halbes Prozent danebenliegen kann. Wer
         * bei dieser Genauigkeit halbiert, verliert eine ganze Zoomstufe –
         * gemessen kamen aus 8407 zulässigen Pixeln so 4203.
         */
        if ($masse['breite'] * $masse['hoehe'] > self::MAX_PIXEL) {
            $faktor = sqrt(self::MAX_PIXEL / ($masse['breite'] * $masse['hoehe']));
            $kante  = max(1024, (int) floor($kante * $faktor));
            $masse  = PdfRasterService::masse($quelle, $seite, $kante) ?? $masse;
        }

        // Sicherheitsnetz, falls die Schätzung noch immer darüber liegt.
        while ($masse['breite'] * $masse['hoehe'] > self::MAX_PIXEL && $kante > 1024) {
            $kante = (int) ($kante * 0.9);
            $masse = PdfRasterService::masse($quelle, $seite, $kante) ?? $masse;
        }

        $format  = 'png';
        $breite  = $masse['breite'];
        $hoehe   = $masse['hoehe'];
        $maxZ    = self::maxZoom($breite, $hoehe);
        $spalten = (int) ceil($breite / self::KACHEL);
        $zeilen  = (int) ceil($hoehe / self::KACHEL);
        $anzahl  = 0;

        for ($y = 0; $y < $zeilen; $y++) {
            $ziel     = PdfRasterService::tempName('streifen');
            $streifen = PdfRasterService::streifen(
                $quelle,
                $seite,
                $kante,
                $y * self::KACHEL,
                self::KACHEL,
                $ziel
            );

            if (!$streifen['ok']) {
                PdfRasterService::aufraeumen($ziel);
                return ['ok' => false, 'message' => 'Streifen ' . ($y + 1) . ': ' . $streifen['message'], 'kacheln' => 0, 'meta' => null];
            }

            /*
             * Liegt der Ausschnitt hinter dem Seitenende, rendert poppler
             * stillschweigend die ganze Seite. Ein Streifen, der höher ist
             * als angefordert, ist also genau das – und keine Kachelzeile.
             * Dann sind wir am unteren Rand angekommen.
             */
            if ($streifen['hoehe'] > self::KACHEL) {
                PdfRasterService::aufraeumen($ziel);
                $zeilen = $y;
                break;
            }

            $bild = @imagecreatefrompng($streifen['pfad']);
            PdfRasterService::aufraeumen($ziel);
            if (!$bild instanceof \GdImage) {
                return ['ok' => false, 'message' => 'Streifen ' . ($y + 1) . ' ließ sich nicht lesen.', 'kacheln' => 0, 'meta' => null];
            }

            $streifenBreite = imagesx($bild);
            $streifenHoehe  = imagesy($bild);
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
                    0,
                    min(self::KACHEL, $streifenBreite - $links),
                    min(self::KACHEL, $streifenHoehe)
                );
                self::schreiben($kachel, $ordner, $maxZ, $x, $y, $format);
                imagedestroy($kachel);
                $anzahl++;
            }
            imagedestroy($bild);
        }

        if ($anzahl === 0) {
            return ['ok' => false, 'message' => 'Das PDF ergab keine Kacheln.', 'kacheln' => 0, 'meta' => null];
        }

        $anzahl += self::pyramideUnten($ordner, $spalten, $zeilen, $maxZ, $format);

        $meta = [
            'breite'   => $breite,
            'hoehe'    => $hoehe,
            'max_zoom' => $maxZ,
            'kachel'   => self::KACHEL,
            'format'   => $format,
            'kacheln'  => $anzahl,
            'quelle'   => basename($quelle),
            'seite'    => $seite,
            'kante'    => $kante,
            'gebaut'   => gmdate('c'),
            'dauer_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        file_put_contents(self::metaPfad($floor), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'ok'      => true,
            'message' => sprintf('%d Kacheln in %d Stufen aus dem PDF berechnet.', $anzahl, $maxZ + 1),
            'kacheln' => $anzahl,
            'meta'    => $meta,
        ];
    }

    // ------------------------------------------- Kacheln aus dem Browser

    /**
     * Einen im Browser gezeichneten Streifen entgegennehmen und schneiden.
     *
     * Hintergrund: poppler ist auf einem LXC schnell nachinstalliert, aber
     * eben nicht überall vorhanden – und solange es fehlt, blieb ein
     * PDF-Grundriss in Safari unbrauchbar. Der Browser kann die Seite
     * jedoch selbst zeichnen; er darf es nur nicht bei jedem Aufruf neu
     * tun, weil ihm dabei auf tiefen Stufen die Canvasfläche ausgeht.
     *
     * Also einmal: der Browser rechnet die oberste Stufe Streifen für
     * Streifen, schickt sie her, und ab da ist ein PDF-Grundriss dasselbe
     * wie ein JPEG – ein Stapel gewöhnlicher Bildkacheln.
     *
     * Ein Streifen ist so breit wie der Plan und eine Kachel hoch. Das
     * spart Anfragen gegenüber einzelnen Kacheln und ist derselbe
     * Zuschnitt wie beim Rastern mit poppler.
     *
     * @return array{ok:bool,message:string,kacheln:int}
     */
    public static function streifenAnnehmen(array $floor, int $zeile, string $png): array
    {
        if ($zeile < 0 || $zeile > self::MAX_ZEILEN) {
            return ['ok' => false, 'message' => 'Streifen liegt außerhalb des Erlaubten.', 'kacheln' => 0];
        }
        if ($png === '' || strlen($png) > self::MAX_STREIFEN_BYTES) {
            return ['ok' => false, 'message' => 'Streifen ist leer oder zu groß.', 'kacheln' => 0];
        }

        /*
         * Was hier ankommt, kommt aus einem Browser – also wird geprüft,
         * ob es wirklich ein PNG in der erwarteten Form ist, und nicht
         * bloß geglaubt. Sonst läge im Kachelordner, was immer jemand
         * schickt.
         */
        $masse = @getimagesizefromstring($png);
        if ($masse === false || $masse[2] !== IMAGETYPE_PNG) {
            return ['ok' => false, 'message' => 'Nur PNG wird angenommen.', 'kacheln' => 0];
        }
        if ((int) $masse[1] > self::KACHEL || (int) $masse[0] > self::MAX_KANTE) {
            return ['ok' => false, 'message' => 'Der Streifen hat nicht das erwartete Maß.', 'kacheln' => 0];
        }

        if (!extension_loaded('gd')) {
            // Ohne gd gäbe es hier einen Fatal Error statt einer Antwort,
            // und der Browser rechnete den ganzen Plan umsonst.
            return ['ok' => false, 'message' => self::GD_FEHLT, 'kacheln' => 0];
        }

        $bild = @imagecreatefromstring($png);
        if (!$bild instanceof \GdImage) {
            return ['ok' => false, 'message' => 'Streifen ließ sich nicht lesen.', 'kacheln' => 0];
        }

        $ordner  = self::planDir($floor) . self::ROH;
        $breite  = imagesx($bild);
        $hoehe   = imagesy($bild);
        $spalten = (int) ceil($breite / self::KACHEL);
        $anzahl  = 0;

        for ($x = 0; $x < $spalten; $x++) {
            $links  = $x * self::KACHEL;
            $kachel = self::leer('png');
            imagecopy(
                $kachel,
                $bild,
                0,
                0,
                $links,
                0,
                min(self::KACHEL, $breite - $links),
                min(self::KACHEL, $hoehe)
            );
            // Die oberste Stufe steht noch nicht fest – erst beim
            // Abschließen ist die Gesamtzahl der Zeilen bekannt. Bis
            // dahin liegen die Kacheln unter Stufe 0 im Zwischenlager.
            self::schreiben($kachel, $ordner, 0, $x, $zeile, 'png');
            imagedestroy($kachel);
            $anzahl++;
        }
        imagedestroy($bild);

        return ['ok' => true, 'message' => $anzahl . ' Kacheln abgelegt.', 'kacheln' => $anzahl];
    }

    /**
     * Die hochgeladenen Streifen zur fertigen Pyramide machen.
     *
     * Der Browser liefert nur die oberste Stufe; alles darunter rechnet
     * der Server aus dem, was schon auf der Platte liegt – dafür braucht
     * es kein PDF-Werkzeug, nur gd.
     *
     * @return array{ok:bool,message:string,kacheln:int,meta:?array}
     */
    public static function kachelnAbschliessen(array $floor, int $breite, int $hoehe): array
    {
        $start  = microtime(true);
        $quelle = SiteService::planPath($floor);
        if ($quelle === null) {
            return ['ok' => false, 'message' => 'Kein Grundriss hinterlegt.', 'kacheln' => 0, 'meta' => null];
        }
        if (!extension_loaded('gd')) {
            return ['ok' => false, 'message' => self::GD_FEHLT, 'kacheln' => 0, 'meta' => null];
        }
        if ($breite < self::KACHEL || $hoehe < self::KACHEL) {
            return ['ok' => false, 'message' => 'Die gemeldeten Maße ergeben keinen Plan.', 'kacheln' => 0, 'meta' => null];
        }
        if ($breite > self::MAX_KANTE || $hoehe > self::MAX_KANTE || $breite * $hoehe > self::MAX_PIXEL) {
            return ['ok' => false, 'message' => 'Der Plan wäre größer als erlaubt.', 'kacheln' => 0, 'meta' => null];
        }

        $roh     = self::planDir($floor) . self::ROH;
        $ordner  = self::planDir($floor);
        $maxZ    = self::maxZoom($breite, $hoehe);
        $spalten = (int) ceil($breite / self::KACHEL);
        $zeilen  = (int) ceil($hoehe / self::KACHEL);

        // Ältere Kacheln müssen weg, sonst mischen sich zwei Auflösungen.
        foreach (glob($ordner . '/[0-9]*', GLOB_ONLYDIR) ?: [] as $alt) {
            self::verzeichnisWeg($alt);
        }

        // Das Zwischenlager an seinen Platz bringen: dort liegt alles
        // unter Stufe 0, hier bekommt es die richtige Stufennummer.
        $anzahl = 0;
        for ($x = 0; $x < $spalten; $x++) {
            for ($y = 0; $y < $zeilen; $y++) {
                $her = self::pfad($roh, 0, $x, $y, 'png');
                if (!is_file($her)) {
                    continue;
                }
                $hin = self::pfad($ordner, $maxZ, $x, $y, 'png');
                if (!is_dir(dirname($hin))) {
                    mkdir(dirname($hin), 0775, true);
                }
                if (@rename($her, $hin)) {
                    $anzahl++;
                }
            }
        }

        self::verzeichnisWeg($roh);

        if ($anzahl === 0) {
            return ['ok' => false, 'message' => 'Es kam keine einzige Kachel an.', 'kacheln' => 0, 'meta' => null];
        }

        $anzahl += self::pyramideUnten($ordner, $spalten, $zeilen, $maxZ, 'png');

        $meta = [
            'breite'   => $breite,
            'hoehe'    => $hoehe,
            'max_zoom' => $maxZ,
            'kachel'   => self::KACHEL,
            'format'   => 'png',
            'kacheln'  => $anzahl,
            'quelle'   => basename($quelle),
            'seite'    => max(1, (int) ($floor['plan_page'] ?? 1)),
            'herkunft' => 'browser',
            'gebaut'   => gmdate('c'),
            'dauer_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        file_put_contents(self::metaPfad($floor), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'ok'      => true,
            'message' => sprintf('%d Kacheln in %d Stufen aus dem Browser übernommen.', $anzahl, $maxZ + 1),
            'kacheln' => $anzahl,
            'meta'    => $meta,
        ];
    }

    /**
     * Lohnt sich ein zweiter Durchgang?
     *
     * Ja, wenn poppler inzwischen da ist und mehr herausholen würde als
     * das, was vorliegt – etwa weil der Plan seinerzeit im Browser
     * gerechnet wurde und dort an dessen Grenze hing.
     *
     * Bei einem Plan, der schon auf der Zielauflösung liegt, wird nichts
     * angeboten. Ein Knopf, der dasselbe Ergebnis noch einmal rechnet,
     * ist schlimmer als keiner.
     */
    private static function gehtSchaerfer(array $floor, array $meta): bool
    {
        $pfad = SiteService::planPath($floor);
        if ($pfad === null || strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) !== 'pdf') {
            return false;
        }
        if (!PdfRasterService::verfuegbar()) {
            return false;
        }

        $ziel = self::zielKante($pfad, max(1, (int) ($floor['plan_page'] ?? 1)));

        // Ein Zehntel Unterschied merkt niemand – erst darüber lohnt es.
        return (int) $meta['breite'] < (int) ($ziel * 0.9);
    }

    /**
     * Auf welche Kantenlänge ein PDF gerechnet wird.
     *
     * Maßstab ist die Seitengröße in Punkt: etwa 5 Pixel je Punkt, also
     * rund 360 dpi. Bei A3 kommen so knapp 6000 px heraus, bei A0 das
     * Doppelte – begrenzt durch MAX_KANTE und die Flächengrenze.
     */
    public static function zielKante(string $pdf, int $seite): int
    {
        $punkte = PdfRasterService::punkte($pdf, $seite);
        if ($punkte === null) {
            return PdfRasterService::KANTE;
        }

        $langeKante = max($punkte['breite'], $punkte['hoehe']);
        $kante      = max(2048, min(self::MAX_KANTE, (int) round($langeKante * 5)));

        /*
         * Die Fläche im Blick behalten: ein langes schmales Format darf
         * nicht über die Grenze laufen, nur weil eine Kante kurz ist.
         *
         * Halbiert wurde hier früher, und das kostete eine Zoomstufe.
         * Gemessen an einem A0-Plan: 12288 px ergäben 107 Megapixel, also
         * zu viel – halbiert kamen 6144 px und 27 Megapixel heraus, bei
         * einer Grenze von 50. Der Browser rechnete denselben Plan
         * unterdessen auf 8192 px. Es wird deshalb nicht halbiert, sondern
         * genau so weit heruntergerechnet, wie die Fläche es zulässt.
         */
        $verhaeltnis = min($punkte['breite'], $punkte['hoehe']) / max($langeKante, 1);
        if ($verhaeltnis > 0) {
            $grenze = (int) floor(sqrt(self::MAX_PIXEL / $verhaeltnis));
            $kante  = max(2048, min($kante, $grenze));
        }

        return $kante;
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
     * Die Stufen unterhalb der obersten, je vier Kacheln auf eine.
     *
     * Gelesen wird ausschließlich, was bereits auf der Platte liegt –
     * deshalb sind nie mehr als fünf Kacheln gleichzeitig im Speicher,
     * unabhängig davon, wie groß der Plan ist.
     */
    private static function pyramideUnten(string $ordner, int $spalten, int $zeilen, int $maxZ, string $format): int
    {
        $anzahl = 0;
        for ($z = $maxZ - 1; $z >= 0; $z--) {
            $spalten = (int) ceil($spalten / 2);
            $zeilen  = (int) ceil($zeilen / 2);
            for ($x = 0; $x < $spalten; $x++) {
                for ($y = 0; $y < $zeilen; $y++) {
                    $gross = self::leer($format, self::KACHEL * 2);
                    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dy]) {
                        $teil = self::lesen($ordner, $z + 1, $x * 2 + $dx, $y * 2 + $dy, $format);
                        if ($teil === null) {
                            continue;
                        }
                        imagecopy($gross, $teil, $dx * self::KACHEL, $dy * self::KACHEL, 0, 0, self::KACHEL, self::KACHEL);
                        imagedestroy($teil);
                    }
                    $klein = imagescale($gross, self::KACHEL, self::KACHEL, IMG_BICUBIC_FIXED);
                    imagedestroy($gross);
                    if ($klein instanceof \GdImage) {
                        self::schreiben($klein, $ordner, $z, $x, $y, $format);
                        imagedestroy($klein);
                        $anzahl++;
                    }
                }
            }
        }
        return $anzahl;
    }

    private static function leer(string $format, int $kante = self::KACHEL): \GdImage
    {
        $bild = imagecreatetruecolor($kante, $kante);
        if ($format === 'png') {
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

    private static function schreiben(\GdImage $bild, string $ordner, int $z, int $x, int $y, string $format): void
    {
        $ziel = self::pfad($ordner, $z, $x, $y, $format);
        $dir  = dirname($ziel);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if ($format === 'png') {
            imagesavealpha($bild, true);
            imagepng($bild, $ziel, 6);
        } else {
            imagejpeg($bild, $ziel, 85);
        }
    }

    private static function lesen(string $ordner, int $z, int $x, int $y, string $format): ?\GdImage
    {
        $pfad = self::pfad($ordner, $z, $x, $y, $format);
        if (!is_file($pfad)) {
            return null;
        }
        $bild = $format === 'png' ? @imagecreatefrompng($pfad) : @imagecreatefromjpeg($pfad);
        if (!$bild instanceof \GdImage) {
            return null;
        }
        if ($format === 'png') {
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
        return ['pfad' => $pfad, 'mime' => $format === 'png' ? 'image/png' : 'image/jpeg'];
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
     * Alles, was die Karte im Browser über diesen Plan wissen muss.
     *
     * @return array{art:string,url:string,breite:int,hoehe:int,seite:int,max_zoom:int,zusatz:int,kachel_url:?string,kachel:int}|null
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
                    'herkunft'   => (string) ($meta['herkunft'] ?? 'server'),
                    // Ließe sich der Plan noch feiner rechnen? Nur dann
                    // wird es angeboten – ein Knopf, der dasselbe Ergebnis
                    // noch einmal rechnet, ist schlimmer als keiner.
                    'schaerfer'  => self::gehtSchaerfer($floor, $meta),
                ];
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
            ];
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
