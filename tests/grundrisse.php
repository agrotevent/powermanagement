<?php
declare(strict_types=1);

/**
 * Grundrisse bis 100 MB: Grenzen (auch wie nginx sie anwendet), Prüfung beim
 * Hochladen, Seiten zählen und PNG-Kopf lesen ohne Speicher zu sprengen,
 * große Bilder über poppler statt gd (Speicher), Kacheln im Upload nur bis
 * zur Zeitgrenze und danach im Hintergrund – nacheinander –, übernommene
 * Pläne in einer Web-Anfrage gleich dort, Abbruch und gelöschte Etage ohne
 * Reste, ersetzte Pläne weg, Streifenhöhe und die nächtliche Wartung.
 *
 * Neu rechnen: in einen eigenen Ordner je Fassung, erst fertig per rename()
 * eingesetzt – während ein Prozess im Hintergrund rechnet (ein träges
 * pdftoppm vorn in seinem PATH), liefert kachel() die alte Kachel; scheitert
 * er oder bricht er ab, bleibt die bisherige Fassung. Aufträge (einer je
 * Plan, abgebrochene holt die Wartung nach), ältere Pyramiden (empfohlen,
 * nachts mit Budget) und das Aufräumen abgelöster Fassungen.
 *
 * Große Dateien entstehen dünn belegt (hilfen/grundriss-dateien.php) – ein
 * PDF mit 100 MB kostet so weder Zeit noch Platte.
 */

require __DIR__ . '/hilfen/pruefung.php';
require __DIR__ . '/hilfen/grundriss-dateien.php';
require __DIR__ . '/hilfen/nginx-vorlage.php';

use App\Kunden\Pruefnachweise;
use App\Services\AnlagenService;
use App\Services\BildPdf;
use App\Services\KatalogImport;
use App\Services\LocationBundle;
use App\Services\PdfRasterService;
use App\Services\PlanTileService;
use App\Services\SiteService;
use App\Support\Anfragezeit;
use App\Support\Database;

$kennung = 'pm-grundriss-' . bin2hex(random_bytes(3));
$tmp = sys_get_temp_dir() . '/' . $kennung;
mkdir($tmp, 0700, true);
$poppler = PdfRasterService::verfuegbar() && extension_loaded('gd');
$auditVorher = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log');

/** Eine Datei wie aus $_FILES – uploadPlan() verschiebt sie (rename, kein echter Upload). */
$datei = static function (string $pfad, string $name, int $fehler = UPLOAD_ERR_OK) use ($tmp): array {
    $kopie = $tmp . '/upload-' . bin2hex(random_bytes(4));
    if (is_file($pfad)) {
        rename($pfad, $kopie);
    }
    return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $kopie, 'error' => $fehler, 'size' => is_file($kopie) ? (int) filesize($kopie) : 0];
};
$planDateien = static fn (): array => array_values(array_filter(scandir(SiteService::planDir()) ?: [], static fn (string $f): bool => is_file(SiteService::planDir() . '/' . $f)));

$siteId = SiteService::saveSite(null, ['name' => $kennung]);
$tankId = null;
// Etagen, die die Prüfung selbst löscht – ihre Audit-Einträge kommen am Ende mit weg.
$geloeschteEtagen = [];
try {
    // ------------------------------------------------ eine Zahl, überall dieselbe
    gleich(SiteService::MAX_UPLOAD_BYTES, 100 * 1024 * 1024, 'Grenze: 100 MiB');
    gleich([SiteService::mb(SiteService::MAX_UPLOAD_BYTES), SiteService::mb(SiteService::MAX_UPLOAD_BYTES + 1, true), SiteService::mb(1288490)],
        ['100 MB', '100,1 MB', '1,2 MB'], 'Anzeige in MB (über der Grenze aufgerundet)');
    $grenzeBei = static function (string $datei, string $koerper): int {
        return (int) shell_exec(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=' . $datei . ' -d post_max_size=' . $koerper
            . ' -r ' . escapeshellarg('require "' . dirname(__DIR__) . '/src/bootstrap.php"; echo App\Services\SiteService::uploadGrenze();'));
    };
    gleich($grenzeBei('100M', '105M'), 100 * 1024 * 1024, 'Pool wie die Vorlage (100M/105M): das Formular nennt 100 MB');
    gleich($grenzeBei('64M', '66M'), 64 * 1024 * 1024, 'alter Pool (64M/66M): das Formular nennt die 64 MB, die PHP wirklich annimmt');
    gleich($grenzeBei('200M', '100M'), 100 * 1024 * 1024 - 64 * 1024, 'post_max_size kleiner: abzüglich Platz für die Formularfelder');
    $vorlage = (string) file_get_contents(dirname(__DIR__) . '/deploy/php-fpm-pool.conf');
    pruefe(str_contains($vorlage, 'php_admin_value[upload_max_filesize] = 100M') && str_contains($vorlage, 'php_admin_value[post_max_size] = 105M'),
        'PHP-FPM-Vorlage: 100M / 105M');
    $site = (string) file_get_contents(dirname(__DIR__) . '/deploy/nginx-powermanagement.conf');
    $block = static fn (string $kopf): string => preg_match('#\n    location ' . preg_quote($kopf, '#') . ' \{(.*?)\n    \}#s', $site, $m) === 1 ? $m[1] : '';
    pruefe(str_contains($block('= /standorte/grundriss'), 'client_max_body_size 105m;') && str_contains($block('= /standorte/grundriss'), 'fastcgi_pass ')
        && !str_contains($block('= /standorte/grundriss'), 'try_files')
        && str_contains($block('^~ /api/'), 'client_max_body_size 50m;') && str_contains($block('^~ /api/'), 'fastcgi_pass ')
        && !str_contains($block('~ ^/index\\.php$'), 'client_max_body_size') && $block('~ ^/index\\.php$') !== ''
        && preg_match('#\n    client_max_body_size 16m;#', $site) === 1,
        'nginx-Vorlage: Grundriss (105m) und Schnittstelle (50m) mit eigenem FastCGI-Block, index.php mit der Grenze der Site (16m)');
    // Die übrigen Uploads (Prüfnachweise des Kundenbereichs, Katalog-Import)
    // bleiben mit Rand für die Formularfelder unter der Grenze der Site –
    // sonst käme statt der Meldung der App die 413-Seite von nginx.
    $siteGrenze = preg_match('#\n    client_max_body_size (\d+)m;#', $site, $m) === 1 ? (int) $m[1] * 1024 * 1024 : 0;
    pruefe(Pruefnachweise::MAX_BYTES + 1024 * 1024 <= $siteGrenze && KatalogImport::GROESSE + 1024 * 1024 <= $siteGrenze,
        sprintf('Prüfnachweise (%s) und Katalog-Import (%s) unter der Grenze der Site (%s)', SiteService::mb(Pruefnachweise::MAX_BYTES),
            SiteService::mb(KatalogImport::GROESSE), SiteService::mb($siteGrenze)));

    // ------------------------------------------------ Prüfung beim Hochladen
    $vorher = $planDateien();
    $etagenVorher = count(SiteService::floors($siteId));
    $gross = $tmp . '/gross.pdf';
    pruefPdf($gross, 1, SiteService::MAX_UPLOAD_BYTES + 1);
    $r = SiteService::uploadPlan($siteId, $datei($gross, 'gross.pdf'), ['neue_etagen' => true]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'Die Datei ist 100,1 MB groß – Grundrisse lassen sich bis 100 MB hochladen.'), 'ein Byte über 100 MiB → abgelehnt: ' . $r['message']);
    $r = SiteService::uploadPlan($siteId, $datei($tmp . '/fehlt', 'x.pdf', UPLOAD_ERR_INI_SIZE), ['neue_etagen' => true]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'größer, als der Server annimmt (höchstens'), 'upload_max_filesize überschritten → Meldung mit der Grenze: ' . $r['message']);

    // Bilder: nur der Kopf zählt – keines davon wird je ausgepackt.
    $faelle = [
        'bombe.png'      => [static fn (string $p) => pruefPngKopf($p, 25000, 20000), 'höchstens 400 Megapixel'],
        'progressiv.jpg' => [static fn (string $p) => pruefJpegKopf($p, 9000, 7000, 0xC2), 'progressiv'],
        'cmyk.jpg'       => [static fn (string $p) => pruefJpegKopf($p, 9000, 7000, 0xC0, 4), 'CMYK'],
        'alpha.png'      => [static fn (string $p) => pruefPngKopf($p, 9000, 7000, 6), 'Transparenz'],
        'zeilen.png'     => [static fn (string $p) => pruefPngKopf($p, 9000, 7000, 2, 1), 'Zeilensprung'],
    ];
    foreach ($faelle as $name => [$machen, $grund]) {
        $pfad = $tmp . '/' . $name;
        $machen($pfad);
        $r = SiteService::uploadPlan($siteId, $datei($pfad, $name), ['neue_etagen' => true]);
        pruefe(!$r['ok'] && str_contains($r['message'], $grund) && str_contains($r['message'], 'Megapixel'), $name . ' → abgelehnt mit Grund: ' . $r['message']);
    }
    // PNG aus mehr Teilen (Chunks), als BildPdf liest: abgelehnt, ohne sie alle zu merken.
    // Mit Millionen davon (100 MB) hielt die Liste gemessen über 2 GB.
    $teile = fopen($tmp . '/teile.png', 'wb');
    fwrite($teile, "\x89PNG\r\n\x1A\n" . pruefPngChunk('IHDR', pack('NNCCCCC', 10000, 6000, 8, 0, 0, 0, 0)));
    $zehntausend = str_repeat(pruefPngChunk('IDAT', "\0"), 10_000);
    for ($i = 0; $i < 2 * BildPdf::MAX_CHUNKS / 10_000; $i++) {
        fwrite($teile, $zehntausend);
    }
    fwrite($teile, pruefPngChunk('IEND', ''));
    fclose($teile);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $sockel = memory_get_usage();
    $start = microtime(true);
    $p = BildPdf::pruefen($tmp . '/teile.png');
    $mb = (memory_get_peak_usage() - $sockel) / 1048576;
    pruefe(!$p['ok'] && str_contains($p['grund'], 'Teilen (Chunks)') && microtime(true) - $start < 5 && $mb < 64,
        sprintf('PNG aus %d Teilen: abgelehnt nach %d, %.1f MB, %.2f s – %s', 2 * BildPdf::MAX_CHUNKS, BildPdf::MAX_CHUNKS, $mb, microtime(true) - $start, $p['grund']));
    $r = SiteService::uploadPlan($siteId, $datei($tmp . '/teile.png', 'teile.png'), ['neue_etagen' => true]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'Teilen (Chunks)'), 'Upload eines solchen PNG → abgelehnt mit Grund: ' . $r['message']);

    // Kopf mit 0 × 0 Pixeln: libmagic hält es für ein PNG, lesen lässt es sich nicht.
    pruefPngKopf($tmp . '/kaputt.png', 0, 0);
    $r = SiteService::uploadPlan($siteId, $datei($tmp . '/kaputt.png', 'kaputt.png'), ['neue_etagen' => true]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'beschädigt'), 'PNG ohne lesbaren Kopf → abgelehnt: ' . $r['message']);
    pruefe($planDateien() === $vorher && count(SiteService::floors($siteId)) === $etagenVorher, 'abgelehnt heißt: keine Datei abgelegt, keine Etage angelegt');
    if (extension_loaded('gd')) {
        imagepng(imagecreatetruecolor(640, 480), $tmp . '/klein.png');
        gleich(SiteService::bildPruefen($tmp . '/klein.png'), null, 'ein gewöhnliches kleines Bild passt');
    }

    // ------------------------------------------------ Seiten zählen, ohne Treffer zu sammeln
    // Als Liste hielten fast zehn Millionen „/Type/Page“ über 512 MB – der
    // Upload starb an memory_limit, die Datei blieb ohne Etage liegen.
    $zaehlen = static fn (string $pfad): string => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M -r '
        . escapeshellarg('require "' . dirname(__DIR__) . '/src/bootstrap.php"; echo App\Services\SiteService::countPdfPages($argv[1]), " ", '
            . 'round(memory_get_peak_usage(true) / 1048576);') . ' ' . escapeshellarg($pfad) . ' 2>&1'));
    foreach (['/Type/Page' => '60', '/Count 1' => '1'] as $muster => $soll) {
        $f = fopen($tmp . '/seiten.pdf', 'wb');
        fwrite($f, "%PDF-1.4\n");
        $block = str_repeat($muster . "\n", 100_000);
        for ($n = 9; $n + strlen($block) <= SiteService::MAX_UPLOAD_BYTES; $n += strlen($block)) {
            fwrite($f, $block);
        }
        fclose($f);
        [$seiten, $mb] = explode(' ', $zaehlen($tmp . '/seiten.pdf') . ' ');
        pruefe($seiten === $soll && (int) $mb < 256, sprintf('PDF mit 100 MB aus lauter „%s“: %s Seite(n), %s MB (memory_limit 512M)', $muster, mb_substr($seiten, 0, 120), $mb));
        @unlink($tmp . '/seiten.pdf');
    }
    file_put_contents($tmp . '/zahl.pdf', "%PDF-1.5\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Kids [] /Count 7 >> endobj\n%%EOF\n");
    pruefPdf($tmp . '/drei.pdf', 3);
    gleich([SiteService::countPdfPages($tmp . '/drei.pdf'), SiteService::countPdfPages($tmp . '/zahl.pdf')], [3, 7], 'gewöhnliches PDF mit drei Seiten; nur /Count 7 lesbar → 7');

    // ------------------------------------------------ nginx: die Grenze je Adresse, auch in Stücken
    $mib = 1024 * 1024;
    $ng = pruefNginxGrenzen(dirname(__DIR__) . '/deploy/nginx-powermanagement.conf', $tmp, [
        'login'         => ['/login', 17 * $mib, false],
        'login-stuecke' => ['/login', 17 * $mib, true],
        'nachweis-11'   => ['/kunden/1/nachweise/hochladen', 11 * $mib, true],
        'nachweis-17'   => ['/kunden/1/nachweise/hochladen', 17 * $mib, false],
        'api-stuecke'   => ['/api/v1/locations/x/vorschlaege', 51 * $mib, true],
        'api-20'        => ['/api/v1/locations/x/vorschlaege', 20 * $mib, true],
        'plan-100'      => ['/standorte/grundriss', 100 * $mib, true],
        'plan-106'      => ['/standorte/grundriss', 106 * $mib, true],
    ]);
    if ($ng === null) {
        fwrite(STDOUT, "  (ohne nginx: Grenzen der Vorlage im Betrieb übersprungen – das prüft tests/installation-debian.sh)\n");
    } elseif ($ng !== []) {
        gleich(array_map(static fn (array $r): int => $r[0], $ng), ['login' => 413, 'login-stuecke' => 413, 'nachweis-11' => 502, 'nachweis-17' => 413,
            'api-stuecke' => 413, 'api-20' => 502, 'plan-100' => 502, 'plan-106' => 413],
            'nginx mit der Vorlage (502 = an PHP weitergereicht): 16m für /login und Prüfnachweise (11 MB durch, die App meldet die Größe), 50m für /api/, '
            . '105m nur für den Grundriss – auch in Stücken (chunked)');
        pruefe(str_contains($ng['api-stuecke'][1], '"ok":false') && str_contains($ng['plan-106'][1], 'Grundrisse lassen sich bis 100 MB hochladen'),
            'nginx: 413 in Stücken mit der eigenen Antwort (JSON für /api/, Seite für den Grundriss)');
    }

    // ------------------------------------------------ Streifen
    foreach ([[5962, 33], [8386, 24], [12288, 48], [2048, 3], [4000, 2], [100000, 400]] as [$breite, $zeilen]) {
        $je = PlanTileService::zeilenJeStreifen($breite, $zeilen);
        pruefe($je >= 1 && ($zeilen <= 2 ? $je === 1 : $je <= $zeilen - 2) && ($je === 1 || $breite * $je * PlanTileService::KACHEL <= PlanTileService::STREIFEN_PIXEL),
            sprintf('Streifen bei %d px Breite, %d Zeilen: %d Zeilen je Aufruf', $breite, $zeilen, $je));
    }
    gleich(PlanTileService::zeilenJeStreifen(5962, 33), 10, 'A0 hochkant (5962 px): zehn Kachelzeilen je Aufruf statt einer');

    // ------------------------------------------------ Schärfe: auf welche Größe eine Seite gerechnet wird
    // Fassung 1 deckelte jeden Plan bei 50 Megapixeln: A0 kam auf 8 386 px
    // (179 dpi), ein Geländeplan von 2 × 1 m auf 9 960 px (126 dpi).
    $a0 = ['breite' => 2383.94, 'hoehe' => 3370.39];
    $a0v = $a0['hoehe'] / $a0['breite'];
    $z = PlanTileService::ziel($a0v, 'standard', $a0, 0.0);
    pruefe($z['kante'] >= 16_800 && $z['breite'] * $z['hoehe'] <= 200_000_000 && $z['grenze'] === 'flaeche' && $z['kante_ziel'] === 16_852,
        sprintf('A0 (Vektor): %d × %d px, fast die vollen 5 px/pt (%d) – Fassung 1: 8 386', $z['breite'], $z['hoehe'], $z['kante_ziel']));
    $f = PlanTileService::ziel($a0v, 'fein', $a0, 0.0);
    gleich([$f['kante'], $f['grenze'], $z['kanten']], [16_852, null, ['standard' => $z['kante'], 'fein' => 16_852]], 'A0 fein: volle 5 px/pt');
    pruefe(!PlanTileService::feinMoeglich(['kante' => $z['kante'], 'kanten' => $z['kanten'], 'qualitaet' => 'standard']),
        'A0: „fein“ wird nicht angeboten – es brächte keine 0,5 % mehr');
    $gelaende = ['breite' => 5669.29, 'hoehe' => 2834.65];
    $g = PlanTileService::ziel(0.5, 'standard', $gelaende, 0.0);
    $gf = PlanTileService::ziel(0.5, 'fein', $gelaende, 0.0);
    pruefe($g['kante'] === 20_000 && $gf['kante'] >= 28_000 && $gf['breite'] * $gf['hoehe'] <= 400_000_000
        && PlanTileService::feinMoeglich(['kante' => $g['kante'], 'kanten' => $g['kanten'], 'qualitaet' => 'standard'])
        && !PlanTileService::feinMoeglich(['kante' => $gf['kante'], 'kanten' => $gf['kanten'], 'qualitaet' => 'fein'])
        && !PlanTileService::feinMoeglich(['kante' => $g['kante'], 'kanten' => $g['kanten'], 'qualitaet' => 'standard', 'grenze' => 'zeit']),
        sprintf('Gelände 2 × 1 m: standard %d px (254 dpi), fein %d px (360 dpi) – angeboten nur bei standard und nicht nach Zeitnot', $g['kante'], $gf['kante']));
    $a3 = PlanTileService::ziel(841.89 / 1190.55, 'standard', ['breite' => 1190.55, 'hoehe' => 841.89], 0.0);
    gleich([$a3['kante'], $a3['grenze']], [5953, null], 'A3: 5 px/pt ohne Grenze');
    // Ein eingebetteter Scan: poppler rechnet ihn bei jedem Aufruf auf die
    // ganze Ausgabegröße hoch (3 Byte je Pixel) – die Seite wird so groß,
    // dass das Bild unter MAX_BILDPIXEL bleibt (gemessen 295 MB).
    $s = PlanTileService::ziel($a0v, 'fein', $a0, $a0['breite'] * $a0['hoehe']);
    pruefe($s['grenze'] === 'bild' && $s['breite'] * $s['hoehe'] <= PlanTileService::MAX_BILDPIXEL && $s['breite'] * $s['hoehe'] > 79_000_000
        && $s['kanten'] === ['standard' => $s['kante'], 'fein' => $s['kante']],
        sprintf('Scan als A0-PDF: %d × %d px, so groß, dass poppler das Bild auf höchstens 80 Megapixel rechnet – auch mit „fein“', $s['breite'], $s['hoehe']));
    $halb = PlanTileService::ziel($a0v, 'standard', $a0, $a0['breite'] * $a0['hoehe'] / 4);
    pruefe($halb['grenze'] === 'flaeche', 'Bild auf einem Viertel der Seite: es bleibt bei der Fläche der Qualität');
    $ohne = PlanTileService::ziel($a0v, 'standard', $a0, null);
    pruefe($ohne['breite'] * $ohne['hoehe'] <= PlanTileService::MAX_PIXEL && $ohne['kante'] > 8_300 && $ohne['grenze'] === 'bild',
        sprintf('ohne pdfimages (Bilder unbekannt): wie Fassung 1 höchstens 50 Megapixel (%d px)', $ohne['kante']));
    $h = PlanTileService::ziel(8000 / 12000, 'fein', null, null, ['breite' => 12000, 'hoehe' => 8000]);
    $h2 = PlanTileService::ziel(7000 / 9000, 'standard', null, null, ['breite' => 9000, 'hoehe' => 7000]);
    pruefe($h['breite'] * $h['hoehe'] <= PlanTileService::MAX_BILDPIXEL && $h['breite'] >= 10_900 && $h['grenze'] === 'bild'
        && [$h2['breite'], $h2['hoehe'], $h2['grenze']] === [9000, 7000, null],
        sprintf('Bild über poppler: 96 Megapixel auf %d × %d, 63 Megapixel in Originalgröße (Fassung 1: beide auf 50)', $h['breite'], $h['hoehe']));
    // Hochrechnung nach dem ersten Streifen (Ausweichen auf eine kleinere Fläche)
    gleich([PlanTileService::zuTeuer(10.0, 91, 1, 1, 1, null), PlanTileService::zuTeuer(10.0, 90, 30_000, 10, 100_000, 2e9),
        PlanTileService::zuTeuer(1.0, 10, 30_000, 10, 100_000, 1e9)], ['zeit', null, 'platte'],
        'zu teuer: über 15 Minuten hochgerechnet, oder auf der Platte nicht das Dreifache frei');
    gleich([PlanTileService::kleineDirekt(0.0, 10.0, 6, 60.0), PlanTileService::kleineDirekt(0.0, 11.0, 6, 60.0), PlanTileService::kleineDirekt(50.0, 11.0, 1, 60.0)],
        [true, false, false], 'kleine Stufen direkt, solange die Zeit dafür reicht (mindestens 60 s)');
    pruefe(PlanTileService::veraltet(['breite' => 1, 'max_zoom' => 0]) && PlanTileService::fassung(['breite' => 1]) === 1
        && !PlanTileService::veraltet(['fassung' => PlanTileService::FASSUNG]) && PlanTileService::FASSUNG >= 2,
        'kacheln.json ohne Fassung: Fassung 1, veraltet');

    // ------------------------------------------------ BildPdf: Kopf, Hülle, Farben
    pruefPngKopf($tmp . '/kopf.png', 9000, 7000);
    $p = BildPdf::pruefen($tmp . '/kopf.png');
    gleich([$p['ok'], $p['art'], $p['breite'], $p['hoehe']], [true, 'png', 9000, 7000], 'BildPdf: PNG-Kopf gelesen');
    pruefJpegKopf($tmp . '/kopf.jpg', 9000, 7000, 0xC0, 1);
    $p = BildPdf::pruefen($tmp . '/kopf.jpg');
    gleich([$p['ok'], $p['art'], $p['breite'], $p['hoehe']], [true, 'jpeg', 9000, 7000], 'BildPdf: JPEG-Kopf (baseline, Graustufen) gelesen');
    if ($poppler) {
        // Farbblöcke: durch die Hülle und poppler müssen die Farben genau ankommen
        mt_srand(11);
        $bild = imagecreatetruecolor(320, 200);
        for ($y = 0; $y < 200; $y += 20) {
            for ($x = 0; $x < 320; $x += 20) {
                imagefilledrectangle($bild, $x, $y, $x + 19, $y + 19, mt_rand(0, 0xFFFFFF));
            }
        }
        $palette = imagecreatetruecolor(320, 200);
        imagecopy($palette, $bild, 0, 0, 0, 0, 320, 200);
        imagetruecolortopalette($palette, false, 255);
        foreach (['rgb' => $bild, 'palette' => $palette] as $art => $soll) {
            imagepng($soll, $tmp . '/farben.png', 9);
            $ok = BildPdf::schreiben($tmp . '/farben.png', $tmp . '/farben.pdf');
            exec('pdftoppm -r 72 -png -singlefile ' . escapeshellarg($tmp . '/farben.pdf') . ' ' . escapeshellarg($tmp . '/farben-r') . ' 2>&1', $_, $rc);
            $ist = @imagecreatefrompng($tmp . '/farben-r.png');
            $abweichung = $ist instanceof GdImage ? 0 : 255;
            for ($y = 10; $ist instanceof GdImage && $y < 200; $y += 20) {
                for ($x = 10; $x < 320; $x += 20) {
                    $a = imagecolorat($ist, $x, $y);
                    $b = imagecolorsforindex($soll, imagecolorat($soll, $x, $y));
                    $abweichung = max($abweichung, abs((($a >> 16) & 255) - $b['red']), abs((($a >> 8) & 255) - $b['green']), abs(($a & 255) - $b['blue']));
                }
            }
            pruefe($ok && $rc === 0 && $abweichung === 0, 'BildPdf: PNG (' . $art . ') unverändert eingehüllt, poppler liest dieselben Farben (Abweichung ' . $abweichung . ')');
        }
    }

    // ------------------------------------------------ Pyramide aus einem Bild (gd): Mittel, Formate, Fassung
    /** Ein Bild als Plan einer neuen Etage dieses Standorts. */
    $bildEtage = static function (GdImage $bild, string $endung, string $name) use ($siteId, $tmp): array {
        $pfad = $tmp . '/' . $name . '.' . $endung;
        $endung === 'png' ? imagepng($bild, $pfad, 6) : imagejpeg($bild, $pfad, 95);
        $id = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => $name]);
        $datei = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.' . $endung;
        rename($pfad, SiteService::planDir() . '/' . $datei);
        Database::update('floors', $id, ['plan_file' => $datei, 'plan_type' => 'image', 'plan_page' => 1]);
        return SiteService::floor($id);
    };
    if (extension_loaded('gd')) {
        // Linien von einem Pixel, auf gerader wie ungerader Spalte, auf
        // weißem Papier. Fassung 1 verkleinerte bikubisch: eine Stufe tiefer
        // blieb jede zweite Linie mit 16 % Kontrast (statt 50 %), Weiß sank
        // je Stufe um drei Werte, außerhalb des Plans blieb ein Schleier.
        $bild = imagecreatetruecolor(2000, 1500);
        imagefilledrectangle($bild, 0, 0, 1999, 1499, 0xFFFFFF);
        $spalten = range(101, 1900, 37);
        foreach ($spalten as $x) {
            imageline($bild, $x, 60, $x, 1440, 0x000000);
        }
        $linien = $bildEtage($bild, 'png', 'Linien');
        $b = PlanTileService::bauen($linien, true);
        $meta = (array) ($b['meta'] ?? []);
        $fmt = (string) ($meta['format'] ?? '');
        $ordnerL = (string) PlanTileService::aktiveFassung($linien);
        pruefe(preg_match('#/v[0-9a-f]{12}$#', $ordnerL) === 1 && str_starts_with($ordnerL, PlanTileService::planDir($linien) . '/')
            && ($meta['ordner'] ?? '') === basename($ordnerL) && is_file($ordnerL . '/kacheln.json'),
            'Kacheln in einem eigenen Fassungsordner (' . basename($ordnerL) . ') mit eigenem kacheln.json, der Zeiger im Planordner nennt ihn');
        pruefe($b['ok'] && $fmt === PlanTileService::verlustfrei() && in_array($fmt, ['png', 'webp'], true)
            && ($meta['fassung'] ?? null) === PlanTileService::FASSUNG && ($meta['qualitaet'] ?? '') === 'standard' && (int) ($meta['max_zoom'] ?? 0) === 3,
            'CAD-Bild (PNG): verlustfreie Kacheln (' . $fmt . '), kacheln.json mit Fassung ' . PlanTileService::FASSUNG);
        $oben = pruefStufe($ordnerL, 2, $fmt);
        $sichtbar = array_map(static fn (int $x): float => pruefLinieSichtbar($oben, $x / 2, true, 40, 700), $spalten);
        pruefe(min($sichtbar) >= 0.95, sprintf('eine Stufe tiefer: jede Linie zu sehen, auf gerader wie ungerader Spalte (die schwächste zu %d %%)', round(100 * min($sichtbar))));
        $papier = $aussen = [];
        for ($zz = 2; $zz >= 0; $zz--) {
            $stufe = pruefStufe($ordnerL, $zz, $fmt);
            $papier[$zz] = imagecolorat($stufe, intdiv(40, 2 ** (3 - $zz)), intdiv(700, 2 ** (3 - $zz)));
            $aussen[$zz] = imagecolorat($stufe, intdiv(2020, 2 ** (3 - $zz)), intdiv(1520, 2 ** (3 - $zz))) >> 24;
        }
        gleich([$papier, $aussen], [[2 => 0xFFFFFF, 1 => 0xFFFFFF, 0 => 0xFFFFFF], [2 => 127, 1 => 127, 0 => 127]],
            'Papier bleibt auf allen Stufen weiß (255), außerhalb des Plans ganz durchsichtig');
        gleich([PlanTileService::kachel($linien, 0, 0, 0)['mime'] ?? null, PlanTileService::vorschau($linien)['mime'] ?? null],
            [PlanTileService::mime($fmt), PlanTileService::mime($fmt)], 'Kachel und Vorschau mit dem Mime-Typ des Formats');
        $q = PlanTileService::quelle($linien);
        gleich([$q['fassung'] ?? null, $q['veraltet'] ?? null, $q['qualitaet'] ?? null], [PlanTileService::FASSUNG, false, 'standard'], 'Karte: Fassung, nicht veraltet');
        // Stand der Pyramide in der Kacheladresse: die Kachel gibt es nur
        // unter dem Stand, der gerade liegt (ohne Stand: Seiten von vorher).
        $stand = PlanTileService::stand($meta);
        $k = PlanTileService::kachel($linien, 1, 0, 0, $stand);
        gleich([preg_match('/^[0-9a-f]{12}$/', $stand), $q['kachel_url'] ?? null, $k['stand'] ?? null, is_file((string) ($k['pfad'] ?? '')),
            PlanTileService::kachel($linien, 1, 0, 0, 'ffffffffffff'), PlanTileService::kachel($linien, 1, 0, 0, '../' . $stand),
            PlanTileService::kachel($linien, 1, 0, 0)['pfad'] ?? null],
            [1, '/grundriss/' . $linien['id'] . '/kachel/{z}/{x}/{y}?v=' . $stand, $stand, true, null, null, $k['pfad'] ?? ''],
            'Kacheladresse mit dem Stand der Pyramide; ein anderer Stand: keine Kachel, ohne Stand die, die liegt');
        // Neu gerechnet: in einen neuen Ordner, eingesetzt per rename() – neuer
        // Stand in der Adresse. Unter dem alten kommen GNADE Sekunden lang noch
        // die alten Kacheln (eine offene Karte zeigt ihren Plan weiter, statt
        // Lücken), danach keine mehr – nie ein Bild der neuen Pyramide unter
        // der Adresse der alten, die der Browser ein Jahr behält.
        $altBytes = (string) @file_get_contents((string) ($k['pfad'] ?? ''));
        $altOrdner = $ordnerL;
        $neu = (array) (PlanTileService::bauen($linien, true)['meta'] ?? []);
        $standNeu = PlanTileService::stand($neu);
        $altKachel = PlanTileService::kachel($linien, 1, 0, 0, $stand);
        gleich([$standNeu !== $stand, $altBytes !== '' && (string) @file_get_contents((string) ($altKachel['pfad'] ?? '')) === $altBytes, $altKachel['stand'] ?? null,
            PlanTileService::kachel($linien, 1, 0, 0, $standNeu)['stand'] ?? null, PlanTileService::quelle($linien)['kachel_url'] ?? null,
            is_file($altOrdner . '/.abgeloest'), PlanTileService::aktiveFassung($linien) !== $altOrdner],
            [true, true, $stand, $standNeu, '/grundriss/' . $linien['id'] . '/kachel/{z}/{x}/{y}?v=' . $standNeu, true, true],
            'neu gerechnet: eigener Ordner, neuer Stand in der Adresse; unter dem alten noch die alten Bytes (abgelöst, GNADE ' . PlanTileService::GNADE . ' s)');
        $planL = PlanTileService::planDir($linien);
        $weg = PlanTileService::abgeloesteWeg($planL);
        $nochDa = is_dir($altOrdner);
        touch($altOrdner . '/.abgeloest', time() - PlanTileService::GNADE - 1);
        $weg2 = PlanTileService::abgeloesteWeg($planL);
        gleich([$weg['fassungen'], $nochDa, $weg2['fassungen'], is_dir($altOrdner), PlanTileService::kachel($linien, 1, 0, 0, $stand),
            is_file((string) (PlanTileService::kachel($linien, 1, 0, 0, $standNeu)['pfad'] ?? ''))],
            [0, true, 1, false, null, true], 'abgelöste Fassung: vor Ablauf von GNADE bleibt sie, danach weg – unter ihrem Stand keine Kachel mehr, die aktive bleibt');
        $meta = $neu;

        // Eine Pyramide von vor den Fassungsordnern (Fassung 1): ihre Stufen
        // direkt im Planordner, kacheln.json ohne Fassung, Ordner und Qualität.
        $fassungL = (string) PlanTileService::aktiveFassung($linien);
        foreach (glob($fassungL . '/[0-9]*', GLOB_ONLYDIR) ?: [] as $stufe) {
            rename($stufe, $planL . '/' . basename($stufe));
        }
        $altMeta = $meta;
        unset($altMeta['fassung'], $altMeta['qualitaet'], $altMeta['ordner']);
        file_put_contents(PlanTileService::metaPfad($linien), json_encode($altMeta));
        @unlink($fassungL . '/kacheln.json');
        @rmdir($fassungL);
        $standAlt = PlanTileService::stand($altMeta);
        $altBytes = (string) @file_get_contents((string) (PlanTileService::kachel($linien, 1, 0, 0, $standAlt)['pfad'] ?? ''));
        $q = PlanTileService::quelle($linien);
        gleich([$q['art'] ?? null, $q['fassung'] ?? null, $q['veraltet'] ?? null, $q['empfohlen'] ?? null, $altBytes !== '',
            str_ends_with((string) ($q['kachel_url'] ?? ''), '?v=' . $standAlt), $standAlt !== $standNeu, preg_match('/^[0-9a-f]{12}$/', $standAlt)],
            ['kacheln', 1, true, true, true, true, true, 1],
            'Pyramide von vorher (Stufen im Planordner, ohne Fassung): Kacheln wie bisher, mit Stand in der Adresse, veraltet – neu rechnen empfohlen');
        // Die Wartung rechnet veraltete nur, solange ihr Budget reicht: mit 0 keinen – sie sagt, wie viele noch warten.
        $zeilen = PlanTileService::veralteteNachrechnen(0);
        pruefe(PlanTileService::stand((array) PlanTileService::meta($linien)) === $standAlt && preg_match('/^Noch \d+ Grundriss/', (string) end($zeilen)) === 1,
            'veralteteNachrechnen(0): nichts gerechnet – „' . (string) end($zeilen) . '“');
        // Neu gerechnet: Die alten Stufen ziehen in einen Ordner mit ihrem Stand um und bleiben dort GNADE Sekunden.
        $b = PlanTileService::bauen($linien, true);
        $m2 = (array) ($b['meta'] ?? []);
        $alteK = PlanTileService::kachel($linien, 1, 0, 0, $standAlt);
        gleich([$m2['fassung'] ?? null, PlanTileService::veraltet($m2), glob($planL . '/[0-9]*', GLOB_ONLYDIR), is_file($planL . '/v' . $standAlt . '/.abgeloest'),
            (string) @file_get_contents((string) ($alteK['pfad'] ?? '')) === $altBytes, PlanTileService::quelle($linien)['empfohlen'] ?? null],
            [PlanTileService::FASSUNG, false, [], true, true, false],
            'neu gerechnet: Fassung ' . PlanTileService::FASSUNG . ', die alten Stufen abgelöst in v<Stand> – darunter noch die alten Bytes, nichts mehr empfohlen');

        // Ein JPEG bleibt JPEG – jetzt in Qualität 92 (Fassung 1: 85).
        $foto = imagecreatetruecolor(1800, 1200);
        imagefilledrectangle($foto, 0, 0, 1799, 1199, 0xF4F1EA);
        for ($i = 0; $i < 60; $i++) {
            imagefilledellipse($foto, 30 * $i, 20 * $i, 90, 60, 0x204080 + $i);
        }
        $jpeg = $bildEtage($foto, 'jpg', 'Foto');
        $b = PlanTileService::bauen($jpeg, true);
        $dc = pruefJpegDc(PlanTileService::aktiveFassung($jpeg) . '/' . (int) ($b['meta']['max_zoom'] ?? 0) . '/0/0.jpg');
        gleich([$b['meta']['format'] ?? null, $dc], ['jpg', 3], 'JPEG-Quelle: Kacheln JPEG in Qualität 92 (Quantisierung DC 3, bei 85 wäre es 5)');

        // Ein Scan als PNG wird JPEG – mit Transparenz bleibt er verlustfrei.
        mt_srand(17);
        $rauschen = imagecreatetruecolor(509, 509);
        for ($y = 0; $y < 509; $y++) {
            for ($x = 0; $x < 509; $x++) {
                $grau = mt_rand(150, 255);
                imagesetpixel($rauschen, $x, $y, ($grau << 16) | ($grau << 8) | max(0, $grau - 9));
            }
        }
        $scan = imagecreatetruecolor(1700, 1200);
        imagesettile($scan, $rauschen);
        imagefilledrectangle($scan, 0, 0, 1699, 1199, IMG_COLOR_TILED);
        $b = PlanTileService::bauen($bildEtage($scan, 'png', 'Scan'), true);
        imagesavealpha($scan, true);
        imagealphablending($scan, false);
        imagefilledrectangle($scan, 0, 0, 300, 300, imagecolorallocatealpha($scan, 255, 255, 255, 127));
        $b2 = PlanTileService::bauen($bildEtage($scan, 'png', 'Scan mit Alpha'), true);
        gleich([$b['meta']['format'] ?? null, $b2['meta']['format'] ?? null], ['jpg', PlanTileService::verlustfrei()],
            'Scan als PNG: JPEG-Kacheln; mit Transparenz verlustfrei');
    }

    // ------------------------------------------------ Anzeige: Kachelstufe nach Gerätepixeln (Node)
    // Das echte Leaflet und die echte planKarte() auf einem DOM von Hand
    // (hilfen/plan-kacheln.cjs), ein A0-Plan mit sieben Stufen. Vorher zog
    // der Browser am Telefon jede Kachel auf das Zwei- bis Vierfache und
    // streckte jede auf 257 Pixel.
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        $wurzel = dirname(__DIR__);
        $js = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/hilfen/plan-kacheln.cjs') . ' '
            . escapeshellarg($wurzel . '/public/assets/vendor/leaflet/leaflet.js') . ' '
            . escapeshellarg($wurzel . '/public/assets/js/module/plan-karte.js')), true) ?: [];
        pruefe(!isset($js['abbruch']) && ($js['fehler'] ?? null) === [], 'Karte (Node): ohne Fehler ' . (string) ($js['abbruch'] ?? json_encode($js['fehler'] ?? null)));
        gleich($js['kachelStufe'] ?? null, [0, 3, 3, 4, 4, 5, 7], 'kachelStufe(): aufgerundet in Gerätepixeln, eine Spur (0,05) Vergrößerung lädt keine Stufe nach');
        foreach (['1', '1.25', '2', '3'] as $dichte) {
            $d = $js['dichten'][$dichte] ?? [];
            gleich([$d['stufeFalsch'] ?? null, $d['vergroessert'] ?? null, $d['zuKlein'] ?? null, $d['ohneKacheln'] ?? null],
                [0, 0, 0, 0], 'devicePixelRatio ' . $dichte . ': über ' . (int) ($d['ansichten'] ?? 0)
                . ' Zoomstufen die Stufe nach Gerätepixeln – nie vergrößert, solange es eine feinere gibt, nie unter die Hälfte');
            gleich([$d['gestreckt'] ?? null, $d['adresseFalsch'] ?? null, $d['versatz'] ?? null, $d['markerAufPunkt'] ?? null, $d['grund'] ?? null],
                [0, 0, 0, true, ['zIndex' => '150', 'pfade' => ['#ffffff']]],
                'devicePixelRatio ' . $dichte . ': Kacheln ungestreckt (256 px) mit Stand in der Adresse, Ecken höchstens 1 px neben dem Marker auf demselben Planpunkt ('
                . (string) ($d['groessterVersatz'] ?? '?') . ' px), Papierweiß darunter');
        }
        $w = $js['wechseln'] ?? [];
        gleich([$w['anders'] ?? null, $w['ok'] ?? null, $w['neueEbene'] ?? null, $w['alteWeg'] ?? null, $w['ebenen'] ?? null, $w['maxZoom'] ?? null,
            $w['nativ'] ?? null, $w['punktGleich'] ?? null, $w['adressen'] ?? null],
            [false, true, true, true, 1, 1, 8, true, true],
            'neu gerechnet, offene Karte (kachelnWechseln): doppelt so fein steigt sie um – neue Ebene mit neuem Stand, eine Stufe mehr, derselbe Planpunkt an derselben Stelle, die alte Ebene weg; andere Fläche: neu laden');
        foreach (['1', '2', '3'] as $dichte) {
            $a = $js['animiert'][$dichte] ?? ['schritte' => [], 'fehler' => ['fehlt']];
            pruefe(count($a['schritte']) === 4 && $a['fehler'] === []
                && array_filter($a['schritte'], static fn (array $s): bool => $s['stufe'] !== $s['erwartet'] || $s['stufenMitKacheln'] !== [$s['erwartet']]) === [],
                'devicePixelRatio ' . $dichte . ': animiert über mehrere Stufen ohne Fehler, am Ende genau die passende Stufe '
                . json_encode(array_map(static fn (array $s): array => [$s['zoom'], $s['stufe']], $a['schritte'])));
        }
    }

    // ------------------------------------------------ Wartung: was liegen blieb
    // Zwischendateien eines getöteten Baus, Pläne und Kacheln ohne Etage –
    // nur, was älter ist als ein Tag, nur unter eigenen Namen.
    $alt = time() - 2 * 86400;
    $tmpAlt = PdfRasterService::tempName('bild') . '.pdf';
    $tmpNeu = PdfRasterService::tempName('streifen') . '-1.png';
    file_put_contents($tmpAlt, str_repeat('x', 4096));
    file_put_contents($tmpNeu, 'x');
    touch($tmpAlt, $alt);
    $hex = static fn (): string => bin2hex(random_bytes(8));
    $waise = SiteService::planDir() . '/site' . $siteId . '_' . $hex() . '.pdf';
    $benutzt = 'site' . $siteId . '_' . $hex() . '.svg';
    $fremd = SiteService::planDir() . '/' . $kennung . '-eigener-name.pdf';
    foreach ([$waise, SiteService::planDir() . '/' . $benutzt, $fremd] as $f) {
        file_put_contents($f, '%PDF-1.4');
        touch($f, $alt);
    }
    $benutztId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Mit altem Plan']);
    Database::update('floors', $benutztId, ['plan_file' => $benutzt, 'plan_type' => 'svg', 'plan_page' => 1]);
    $ordnerWaise = PlanTileService::dir() . '/site' . $siteId . '_' . $hex() . '_s2';
    $ordnerFrisch = PlanTileService::dir() . '/site' . $siteId . '_' . $hex();
    $ordnerGesperrt = PlanTileService::dir() . '/site' . $siteId . '_' . $hex();
    foreach ([$ordnerWaise, $ordnerFrisch, $ordnerGesperrt] as $o) {
        mkdir($o . '/0/0', 0775, true);
        file_put_contents($o . '/0/0/0.png', 'x');
    }
    $griff = fopen($ordnerGesperrt . '/.sperre', 'c');
    flock($griff, LOCK_EX);
    touch($ordnerWaise, $alt);
    touch($ordnerGesperrt, $alt);
    try {
        $zeilen = PlanTileService::wartung();
    } finally {
        flock($griff, LOCK_UN);
        fclose($griff);
    }
    pruefe(!is_file($tmpAlt) && is_file($tmpNeu), 'Wartung: alte Zwischendatei in .tmp weg, eine frische bleibt');
    pruefe(!is_file($waise) && is_file(SiteService::planDir() . '/' . $benutzt) && is_file($fremd),
        'Wartung: alte Plandatei ohne Etage weg; benutzte und fremd benannte bleiben');
    pruefe(!is_dir($ordnerWaise) && is_dir($ordnerFrisch) && is_dir($ordnerGesperrt),
        'Wartung: alter Kachelordner ohne Etage weg; frischer und gerade gesperrter bleiben');
    pruefe(array_filter($zeilen, static fn (string $z): bool => str_starts_with($z, 'Aufgeräumt: ')) !== [], 'Wartung meldet es: ' . implode(' | ', $zeilen));
    @unlink($tmpNeu);
    @unlink($fremd);
    foreach ([$ordnerFrisch, $ordnerGesperrt] as $o) {
        @unlink($o . '/0/0/0.png');
        @unlink($o . '/.sperre');
        @rmdir($o . '/0/0');
        @rmdir($o . '/0');
        @rmdir($o);
    }

    // Im Kachelordner einer Etage: abgelöste Fassungen nach GNADE, Bauordner
    // getöteter Bauten nach einem Tag, Stufen einer älteren Pyramide neben
    // einer aktiven Fassung, Zwischendateien, alte Aufträge – die aktive
    // Fassung, Frisches und Fremdes bleiben, ebenso alles, solange jemand rechnet.
    $mitEtage = PlanTileService::planDir((array) SiteService::floor($benutztId));
    $v = static fn (): string => 'v' . bin2hex(random_bytes(6));
    [$aktiv, $abgeloest, $frischAbgeloest, $bauAlt, $bauNeu] = [$v(), $v(), $v(), $v() . '.bau', $v() . '.bau'];
    foreach ([$aktiv, $abgeloest, $frischAbgeloest, $bauAlt, $bauNeu, '0', 'eigener-ordner'] as $o) {
        mkdir($mitEtage . '/' . $o . '/0/0', 0775, true);
        file_put_contents($mitEtage . '/' . $o . '/0/0/0.png', 'x');
    }
    file_put_contents($mitEtage . '/kacheln.json', json_encode(['breite' => 1, 'hoehe' => 1, 'max_zoom' => 0, 'format' => 'png', 'ordner' => $aktiv]));
    touch($mitEtage . '/.sperre');
    touch($mitEtage . '/' . $abgeloest . '/.abgeloest', time() - PlanTileService::GNADE - 5);
    touch($mitEtage . '/' . $frischAbgeloest . '/.abgeloest');
    foreach ([$bauAlt, $aktiv, '0', 'eigener-ordner'] as $o) {
        touch($mitEtage . '/' . $o, $alt);
    }
    file_put_contents($mitEtage . '/kacheln.json.0a1b2c3d.tmp', '{');
    touch($mitEtage . '/kacheln.json.0a1b2c3d.tmp', $alt);
    file_put_contents($mitEtage . '/.auftrag.json', json_encode(['zustand' => 'fertig', 'ende' => gmdate('c', time() - 40 * 86400)]));
    touch($mitEtage . '/.auftrag.json', time() - 40 * 86400);
    $zeilen = PlanTileService::wartung();
    $da = static fn (string $n): bool => file_exists($mitEtage . '/' . $n);
    gleich([$da($aktiv), $da($abgeloest), $da($frischAbgeloest), $da($bauAlt), $da($bauNeu), $da('0'), $da('eigener-ordner'),
        $da('kacheln.json.0a1b2c3d.tmp'), $da('.auftrag.json'), $da('kacheln.json')],
        [true, false, true, false, true, false, true, false, false, true],
        'Wartung im Kachelordner einer Etage: abgelöste Fassung nach GNADE, Bau ohne Prozess nach einem Tag, alte Stufen neben der aktiven Fassung, '
        . 'Zwischendatei und alter Auftrag weg – aktive Fassung, frisch Abgelöstes, frischer Bau und Fremdes bleiben');
    pruefe(array_filter($zeilen, static fn (string $z): bool => str_contains($z, 'abgelöste Kachelfassung')) !== []
        && array_filter($zeilen, static fn (string $z): bool => str_contains($z, 'Kachelbau(ten) ohne Prozess')) !== [],
        'Wartung meldet es: ' . implode(' | ', array_filter($zeilen, static fn (string $z): bool => str_contains($z, 'Kachel'))));
    $gesperrtAbgeloest = $v();
    mkdir($mitEtage . '/' . $gesperrtAbgeloest);
    touch($mitEtage . '/' . $gesperrtAbgeloest . '/.abgeloest', time() - PlanTileService::GNADE - 5);
    $griff = fopen($mitEtage . '/.sperre', 'c');
    flock($griff, LOCK_EX);
    try {
        PlanTileService::wartung();
    } finally {
        flock($griff, LOCK_UN);
        fclose($griff);
    }
    pruefe($da($gesperrtAbgeloest) && $da($frischAbgeloest), 'Wartung: solange jemand rechnet (Sperre gehalten), bleibt auch Abgelöstes liegen');
    PlanTileService::aufraeumen((array) SiteService::floor($benutztId));

    if (!$poppler) {
        fwrite(STDOUT, "  (ohne poppler-utils oder gd: Kacheln über poppler übersprungen)\n");
    } else {
        // ------------------------------------------------ großes Bild: poppler statt gd
        // 12 000 × 8 000 Pixel Graustufen: gd bräuchte allein für das Bild
        // 384 MB (4 Byte je Pixel, außerhalb von memory_limit).
        pruefPngGrau($tmp . '/hallenscan.png', 12000, 8000);
        gleich(SiteService::bildPruefen($tmp . '/hallenscan.png'), null, 'PNG mit 96 Megapixeln (ohne Transparenz) passt zum Hochladen');
        // Die Kacheln rechnet ein eigener Prozess, der dabei misst.
        $etageId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Hallenscan']);
        $name = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.png';
        rename($tmp . '/hallenscan.png', SiteService::planDir() . '/' . $name);
        Database::update('floors', $etageId, ['plan_file' => $name, 'plan_type' => 'image', 'plan_page' => 1, 'plan_original_name' => 'hallenscan.png']);
        $etage = SiteService::floor($etageId);
        $m = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg(__DIR__ . '/hilfen/kacheln-messen.php')
            . ' ' . (int) ($etage['id'] ?? 0)), true) ?: [];
        $meta = (array) ($m['meta'] ?? []);
        // Fassung 1 rechnete es auf 50 Megapixel herunter (8 660 px breit).
        pruefe(($m['ok'] ?? false) && ($meta['format'] ?? '') === PlanTileService::verlustfrei() && isset($meta['kante'])
            && (int) $meta['breite'] * (int) $meta['hoehe'] <= PlanTileService::MAX_BILDPIXEL && (int) $meta['breite'] >= 10_900
            && ($meta['grenze'] ?? '') === 'bild' && ($meta['direkt'] ?? null) === [],
            sprintf('über poppler auf höchstens %d Megapixel gerechnet, verlustfrei, alle Stufen gemittelt: %s', intdiv(PlanTileService::MAX_BILDPIXEL, 1_000_000), (string) ($m['message'] ?? '?')));
        pruefe(($m['prozess_mb'] ?? null) === null || $m['prozess_mb'] < 300,
            sprintf('Speicher des PHP-Prozesses %s MB, unter 300 (mit gd über 500: 384 MB Bild, 96 MB Lesepuffer), memory_limit-Anteil %s MB, %s s',
                (string) ($m['prozess_mb'] ?? '?'), (string) ($m['php_mb'] ?? '?'), (string) ($m['sekunden'] ?? '?')));
        // pdftoppm rechnet das Bild auf die ganze Ausgabe hoch (3 Byte je
        // Pixel): bei 80 Megapixeln gemessen 295 MB. Gezählt wird der größte
        // Kindprozess – mindestens so viel, wie PHP beim Start hielt.
        pruefe(($m['kind_mb'] ?? null) === null || $m['kind_mb'] < 340,
            sprintf('Speicher von pdftoppm höchstens %s MB, unter 340', (string) ($m['kind_mb'] ?? '?')));
        $karte = $etage !== null ? PlanTileService::quelle(SiteService::floor((int) $etage['id'])) : null;
        gleich($karte['art'] ?? null, 'kacheln', 'die Karte bekommt Kacheln, nicht das ganze Bild');

        // ------------------------------------------------ PDF: Haarlinien in der Übersicht, Qualität, Fassung
        // Senkrechte und waagerechte Linien mit 0,1 pt, dicht genug, dass sie
        // auf jeder Stufe gerade wie ungerade Pixel treffen. Gemittelt wäre
        // eine Haarlinie zwei Stufen tiefer nicht mehr zu sehen; poppler
        // zeichnet sie auf den kleinen Stufen wie ein PDF-Betrachter.
        $xs = $ys = [];
        for ($x = 40.0; $x < 560; $x += 6.7) {
            $xs[] = $x;
        }
        for ($y = 40.0; $y < 360; $y += 5.9) {
            $ys[] = $y;
        }
        $haarId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Haarlinien']);
        $haarName = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        pruefPdfHaarlinien(SiteService::planDir() . '/' . $haarName, 600, 400, $xs, $ys);
        Database::update('floors', $haarId, ['plan_file' => $haarName, 'plan_type' => 'pdf', 'plan_page' => 1]);
        $haar = SiteService::floor($haarId);
        $b = PlanTileService::bauen($haar, true);
        $meta = (array) ($b['meta'] ?? []);
        $fmt = (string) ($meta['format'] ?? '');
        $ordnerH = (string) PlanTileService::aktiveFassung($haar);
        pruefe($b['ok'] && [$meta['breite'] ?? 0, $meta['max_zoom'] ?? 0, $meta['direkt'] ?? null, $meta['fassung'] ?? 0] === [3000, 4, [3, 2, 1, 0], PlanTileService::FASSUNG]
            && $fmt === PlanTileService::verlustfrei() && array_key_exists('grenze', $meta) && $meta['grenze'] === null,
            'PDF 600 × 400 pt: 3 000 px (5 px/pt), Stufen 3 bis 0 direkt von poppler, verlustfrei, Fassung ' . PlanTileService::FASSUNG . ' – ' . $b['message']);
        $schwach = [];
        foreach ([3, 2, 1] as $zz) {
            $stufe = pruefStufe($ordnerH, $zz, $fmt);
            $massstab = 5 / 2 ** (4 - $zz);
            $werte = array_merge(
                array_map(static fn (float $x): float => pruefLinieSichtbar($stufe, $x * $massstab, true, (int) (60 * $massstab), (int) (340 * $massstab)), $xs),
                array_map(static fn (float $y): float => pruefLinieSichtbar($stufe, $y * $massstab, false, (int) (60 * $massstab), (int) (540 * $massstab)), $ys)
            );
            $schwach[$zz] = round(min($werte), 2);
        }
        gleich($schwach, [3 => 1.0, 2 => 1.0, 1 => 1.0], 'Haarlinien eine, zwei und drei Stufen unter der obersten überall zu sehen (Anteil der Punkte, schwächste Linie)');

        // Neu rechnen: Qualität und Seitenverhältnis bleiben. Gerechnet wird
        // in einen neuen Ordner, die bisherige Fassung abgelöst; Fremdes im
        // Planordner bleibt; kacheln.json wird ersetzt, nicht überschrieben.
        $planH = PlanTileService::planDir($haar);
        file_put_contents($planH . '/notiz.txt', 'fremd');
        $b = PlanTileService::bauen($haar, true, true, null, 'fein');
        $inode = fileinode(PlanTileService::metaPfad($haar));
        $vorher = (string) PlanTileService::aktiveFassung($haar);
        $b2 = PlanTileService::bauen($haar, true);
        clearstatcache();
        pruefe(($b['meta']['qualitaet'] ?? '') === 'fein' && ($b2['meta']['qualitaet'] ?? '') === 'fein'
            && ($b2['meta']['verhaeltnis'] ?? 0) === ($meta['verhaeltnis'] ?? 1) && ($b2['meta']['hoehe'] ?? 0) === ($meta['hoehe'] ?? 1),
            'neu gerechnet ohne Angabe: Qualität „fein“ bleibt, Seitenverhältnis und Höhe wie zuvor');
        $reste = array_values(array_filter(scandir($planH) ?: [], static fn (string $n): bool => str_ends_with($n, '.tmp') || str_ends_with($n, '.bau')));
        pruefe(fileinode(PlanTileService::metaPfad($haar)) !== $inode && $reste === [] && is_file($planH . '/notiz.txt')
            && is_file($vorher . '/.abgeloest') && PlanTileService::aktiveFassung($haar) !== $vorher,
            'kacheln.json über eine Zwischendatei ersetzt (neue Inode), keine Zwischendatei und kein Bauordner übrig, die bisherige Fassung abgelöst, Fremdes bleibt');
        @unlink($planH . '/notiz.txt');

        // pdfimages: wie groß stehen Bilder auf der Seite? Ein Bild über die
        // ganze Seite (wie ein Scan) deckelt die Seite, ein Plan ohne Bilder nicht.
        // (BildPdf: eine Seite von 4 800 × 3 200 Punkt – 384 Megapixel bei 5 px/pt.)
        $foto = imagecreatetruecolor(4800, 3200);
        imagefilledrectangle($foto, 0, 0, 4799, 3199, 0xD0E0F0);
        imagejpeg($foto, $tmp . '/seite.jpg', 90);
        imagedestroy($foto);
        BildPdf::schreiben($tmp . '/seite.jpg', $tmp . '/seite.pdf');
        $flaeche = PdfRasterService::bildflaeche($tmp . '/seite.pdf', 1, 4800 * 3200);
        if ($flaeche === null) {
            fwrite(STDOUT, "  (ohne pdfimages: Bildfläche übersprungen)\n");
        } else {
            $punkte = PdfRasterService::punkte($tmp . '/seite.pdf', 1);
            $zs = PlanTileService::ziel(3200 / 4800, 'standard', $punkte, $flaeche);
            pruefe(abs($flaeche - 4800 * 3200) < 4800 * 3200 * 0.02 && PdfRasterService::bildflaeche((string) SiteService::planPath($haar), 1) === 0.0
                && $zs['grenze'] === 'bild' && $zs['breite'] * $zs['hoehe'] <= PlanTileService::MAX_BILDPIXEL && $zs['breite'] * $zs['hoehe'] > 79_000_000,
                sprintf('pdfimages: Bild über die ganze Seite %.0f pt² (Seite 15 360 000), ohne Bilder 0 – die Seite mit Bild auf %d × %d px', $flaeche, $zs['breite'], $zs['hoehe']));
        }

        // ------------------------------------------------ Neu rechnen im Hintergrund
        // Ein träges pdftoppm vorn im PATH des Prozesses im Hintergrund (eine
        // Sekunde je Aufruf): So lange rechnet der Bau, dass sich nachsehen
        // lässt, was die Karte währenddessen bekommt.
        $echt = (string) PdfRasterService::werkzeug()['pfad'];
        $attrappe = static function (string $name, string $vorher) use ($tmp, $echt): string {
            $bin = $tmp . '/' . $name;
            @mkdir($bin, 0700);
            file_put_contents($bin . '/pdftoppm', "#!/bin/sh\n" . $vorher . "\nexec " . escapeshellarg($echt) . " \"\$@\"\n");
            chmod($bin . '/pdftoppm', 0700);
            return $bin;
        };
        $traege = $attrappe('traege', 'sleep 1');
        $kliLog = $tmp . '/kli.log';
        touch($kliLog);
        /** bin/power mit $bin vorn im PATH – bis zum Ende (null) oder im Hintergrund (Prozess, beim Beenden gestoppt). */
        $kli = static function (array $argumente, string $bin, bool $warten) use ($kliLog): mixed {
            $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/power'], $argumente),
                [0 => ['pipe', 'r'], 1 => ['file', $kliLog, 'a'], 2 => ['file', $kliLog, 'a']], $rohre, null, ['PATH' => $bin . ':' . (string) getenv('PATH')] + getenv());
            fclose($rohre[0]);
            if ($warten) {
                proc_close($p);
                return null;
            }
            register_shutdown_function(static function () use ($p): void {
                if (is_resource($p)) {
                    proc_terminate($p);
                    proc_close($p);
                }
            });
            return $p;
        };
        $kliNeu = static function () use ($kliLog): callable {
            clearstatcache(true, $kliLog);
            $ab = (int) filesize($kliLog);
            return static function () use ($kliLog, $ab): string {
                clearstatcache(true, $kliLog);
                return substr((string) file_get_contents($kliLog), $ab);
            };
        };
        $sobald = static function (callable $bedingung, float $sekunden): bool {
            $ende = microtime(true) + $sekunden;
            while (!$bedingung()) {
                if (microtime(true) > $ende) {
                    return false;
                }
                usleep(100_000);
            }
            return true;
        };

        // Angestoßen wie aus der Oberfläche: sofort zurück, ein Auftrag – während
        // gerechnet wird, liefert kachel() Byte für Byte die bisherige Kachel.
        $haar = SiteService::floor($haarId);
        $standVorher = (string) PlanTileService::aktuellerStand($haar);
        $kVorher = (array) PlanTileService::kachel($haar, 2, 0, 0, $standVorher);
        $bytesVorher = (string) file_get_contents((string) $kVorher['pfad']);
        $pfadVorher = (string) getenv('PATH');
        $start = microtime(true);
        putenv('PATH=' . $traege . ':' . $pfadVorher);
        try {
            $r = PlanTileService::neuRechnenAnfordern($haar);
        } finally {
            putenv('PATH=' . $pfadVorher);
        }
        $zweite = PlanTileService::neuRechnenAnfordern($haar);
        $dauer = microtime(true) - $start;
        pruefe($r['ok'] && $r['neu'] && $zweite['ok'] && !$zweite['neu'] && $dauer < 1,
            sprintf('Neu rechnen angestoßen und sofort zurück (%.2f s); ein zweiter Klick ergibt keinen zweiten Auftrag – %s', $dauer, $zweite['message']));
        $laeuft = $sobald(static fn (): bool => PlanTileService::zustand($haar)['zustand'] === 'laeuft', 20);
        $gleich = true;
        $bau = false;
        $proben = 0;
        for ($i = 0; $i < 10 && PlanTileService::zustand($haar)['zustand'] === 'laeuft'; $i++) {
            $k = PlanTileService::kachel($haar, 2, 0, 0, $standVorher);
            $gleich = $gleich && (string) @file_get_contents((string) ($k['pfad'] ?? '')) === $bytesVorher
                && PlanTileService::aktuellerStand($haar) === $standVorher && (PlanTileService::kachel($haar, 2, 0, 0)['pfad'] ?? '') === $kVorher['pfad'];
            $bau = $bau || glob(PlanTileService::planDir($haar) . '/v*.bau', GLOB_ONLYDIR) !== [];
            $proben++;
            usleep(200_000);
        }
        pruefe($laeuft && $proben > 0 && $gleich && $bau,
            'während des Neubaus im Hintergrund (träges pdftoppm): dieselbe Kachel Byte für Byte, derselbe Stand – gerechnet wird daneben (v….bau), ' . $proben . ' Proben');
        $neueZeilen = $kliNeu();
        $kli(['plan:kacheln', '--neu', '--etage=' . $haarId], $traege, true);
        pruefe(str_contains($neueZeilen(), 'läuft schon'), 'ein zweiter Prozess für denselben Plan endet sofort mit „läuft schon“');
        $fertig = $sobald(static fn (): bool => PlanTileService::zustand($haar)['zustand'] === 'fertig', 90);
        $standNachher = (string) PlanTileService::aktuellerStand($haar);
        $altK = PlanTileService::kachel($haar, 2, 0, 0, $standVorher);
        $z = PlanTileService::zustand($haar);
        gleich([$fertig, $standNachher !== $standVorher, (string) @file_get_contents((string) ($altK['pfad'] ?? '')) === $bytesVorher, str_starts_with($z['meldung'], 'Fertig gerechnet')],
            [true, true, true, true], 'danach umgeschaltet: neuer Stand, unter dem alten noch die alten Bytes (GNADE) – ' . $z['meldung']);

        // Ein Auftrag ohne Prozess (der Server wurde neu gestartet): abgebrochen,
        // die Wartung holt ihn nach – mit der verlangten Qualität.
        $auftragDatei = PlanTileService::planDir($haar) . '/.auftrag.json';
        file_put_contents($auftragDatei, json_encode(['zustand' => 'laeuft', 'qualitaet' => 'standard', 'angefordert' => gmdate('c', time() - 300), 'beginn' => gmdate('c', time() - 290)]));
        $z = PlanTileService::zustand($haar);
        file_put_contents($auftragDatei, json_encode(['zustand' => 'angefordert', 'angefordert' => gmdate('c', time() - 120)]));
        $z2 = PlanTileService::zustand($haar);
        file_put_contents($auftragDatei, json_encode(['zustand' => 'angefordert', 'angefordert' => gmdate('c', time() - 5)]));
        $z3 = PlanTileService::zustand($haar);
        gleich([$z['zustand'], str_contains($z['meldung'], 'neu gestartet – der bisherige Plan ist geblieben'), $z2['zustand'], $z3['zustand']],
            ['abgebrochen', true, 'abgebrochen', 'angefordert'],
            'Auftrag ohne Prozess (laufend, oder vor über einer Minute angefordert): abgebrochen; eben erst angefordert: angefordert – ' . $z['meldung']);
        file_put_contents($auftragDatei, json_encode(['zustand' => 'laeuft', 'qualitaet' => 'standard', 'angefordert' => gmdate('c', time() - 300)]));
        $zeilen = PlanTileService::fehlendeNachrechnen();
        pruefe(array_filter($zeilen, static fn (string $z): bool => str_starts_with($z, 'Abgebrochenes Neurechnen nachgeholt: ' . $kennung . ' / Haarlinien')) !== []
            && (PlanTileService::meta($haar)['qualitaet'] ?? '') === 'standard' && PlanTileService::zustand($haar)['zustand'] === 'fertig',
            'Wartung: abgebrochenes Neurechnen nachgeholt, in der verlangten Qualität („standard“ statt bisher „fein“)');

        // pdftoppm scheitert am zweiten Streifen: Kacheln und kacheln.json
        // bleiben, wie sie waren, kein Bauordner bleibt liegen, der Zustand
        // sagt es – ohne Pfade des Servers.
        $streifenId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Zwei Streifen']);
        $streifenName = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        pruefPdfHaarlinien(SiteService::planDir() . '/' . $streifenName, 1100, 800, [100.0, 500.0, 900.0], [100.0, 400.0, 700.0]);
        Database::update('floors', $streifenId, ['plan_file' => $streifenName, 'plan_type' => 'pdf', 'plan_page' => 1]);
        $streifen = SiteService::floor($streifenId);
        $b = PlanTileService::bauen($streifen, true);
        $metaVorher = PlanTileService::meta($streifen);
        $kVorher = (array) PlanTileService::kachel($streifen, (int) ($metaVorher['max_zoom'] ?? 0), 0, 0);
        $bytesVorher = (string) @file_get_contents((string) ($kVorher['pfad'] ?? ''));
        $zaehler = $tmp . '/zaehler';
        file_put_contents($zaehler, '0');
        $kaputt = $attrappe('kaputt', 'n=$(( $(cat ' . escapeshellarg($zaehler) . ') + 1 )); echo "$n" > ' . escapeshellarg($zaehler) . "\n"
            . 'if [ "$n" -ge 2 ]; then echo "Syntax Error: Couldn' . "'" . 't read ' . $tmp . '/geheim/plan.pdf" >&2; exit 1; fi');
        $kli(['plan:kacheln', '--neu', '--etage=' . $streifenId], $kaputt, true);
        $z = PlanTileService::zustand($streifen);
        $reste = array_values(array_filter(scandir(PlanTileService::planDir($streifen)) ?: [], static fn (string $n): bool => str_ends_with($n, '.bau')));
        $kNachher = PlanTileService::kachel($streifen, (int) ($metaVorher['max_zoom'] ?? 0), 0, 0);
        pruefe($b['ok'] && (int) file_get_contents($zaehler) >= 2 && PlanTileService::meta($streifen) === $metaVorher && $bytesVorher !== ''
            && (string) @file_get_contents((string) ($kNachher['pfad'] ?? '')) === $bytesVorher && $reste === [] && $z['zustand'] === 'fehler'
            && str_contains($z['meldung'], 'der bisherige Plan ist geblieben') && str_contains($z['meldung'], 'plan.pdf') && !str_contains($z['meldung'], $tmp),
            'pdftoppm scheitert am zweiten Streifen: Kacheln und kacheln.json unverändert, kein Bauordner übrig, Zustand „fehler“ ohne Pfade – ' . $z['meldung']);
        pruefe(PlanTileService::veralteteNachrechnen(0) === [] || PlanTileService::zustand($streifen)['zustand'] === 'fehler',
            '… und die Wartung versucht ihn nicht jede Nacht wieder (er ist ohnehin aktuell)');

        // Dieselbe Datei für zwei Etagen: eine Pyramide, einmal gerechnet –
        // beim Hochladen wie beim Neurechnen.
        $hallen = [SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Halle A']), SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Halle B'])];
        $bild = imagecreatetruecolor(2400, 1600);
        imagefilledrectangle($bild, 0, 0, 2399, 1599, 0xFFFFFF);
        imageline($bild, 0, 0, 2399, 1599, 0x000000);
        imagepng($bild, $tmp . '/hallen.png');
        $r = SiteService::uploadPlan($siteId, $datei($tmp . '/hallen.png', 'hallen.png'), ['floor_ids' => $hallen]);
        $hA = (array) SiteService::floor($hallen[0]);
        $hB = (array) SiteService::floor($hallen[1]);
        $planHallen = PlanTileService::planDir($hA);
        pruefe($r['ok'] && $planHallen === PlanTileService::planDir($hB) && count(glob($planHallen . '/v*', GLOB_ONLYDIR) ?: []) === 1
            && str_contains($r['message'], 'Kacheln berechnet.') && !str_contains($r['message'], 'Seiten)'),
            'dieselbe Datei für zwei Etagen: eine Pyramide, beim Hochladen einmal gerechnet – ' . $r['message']);
        $standA = (string) PlanTileService::aktuellerStand($hA);
        $neueZeilen = $kliNeu();
        $kli(['plan:kacheln', '--neu', '--etage=' . implode(',', $hallen)], $tmp . '/leer', false);
        $sobald(static fn (): bool => str_contains($neueZeilen(), 'Kacheln in'), 60);
        $ausgabe = $neueZeilen();
        pruefe(substr_count($ausgabe, 'Kacheln in') === 1 && str_contains($ausgabe, $kennung . ' / Halle A, Halle B: ')
            && PlanTileService::aktuellerStand($hA) !== $standA && PlanTileService::aktuellerStand($hA) === PlanTileService::aktuellerStand($hB),
            'plan:kacheln --neu --etage=A,B: einmal gerechnet, eine Zeile für beide Etagen – ' . trim($ausgabe));

        // Etage gelöscht, während ihr Plan im Hintergrund neu gerechnet wird:
        // Plandatei und Kachelordner sind danach weg, nichts entsteht neu.
        $loeschId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Wird gelöscht']);
        $geloeschteEtagen[] = (string) $loeschId;
        $loeschName = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        pruefPdfHaarlinien(SiteService::planDir() . '/' . $loeschName, 600, 400, [100.0, 300.0], [100.0, 200.0]);
        Database::update('floors', $loeschId, ['plan_file' => $loeschName, 'plan_type' => 'pdf', 'plan_page' => 1]);
        $loesch = (array) SiteService::floor($loeschId);
        PlanTileService::bauen($loesch, true);
        $p = $kli(['plan:kacheln', '--neu', '--etage=' . $loeschId], $traege, false);
        $laeuft = $sobald(static fn (): bool => PlanTileService::zustand($loesch)['zustand'] === 'laeuft', 20);
        SiteService::deleteFloor($loeschId);
        $vorbei = $sobald(static fn (): bool => !proc_get_status($p)['running'], 60);
        pruefe($laeuft && $vorbei && !is_dir(PlanTileService::planDir($loesch)) && !is_file(SiteService::planDir() . '/' . $loeschName),
            'Etage gelöscht, während ihr Plan im Hintergrund neu gerechnet wurde: kein Kachelordner und keine Plandatei bleiben');

        // ------------------------------------------------ Zeitgrenze: Abbruch, dann Hintergrund
        $pdf = $tmp . '/zwei.pdf';
        pruefPdf($pdf, 2);
        $r = SiteService::uploadPlan($siteId, $datei($pdf, 'zwei.pdf'), ['neue_etagen' => true, 'kachelzeit' => 0]);
        $neu = array_map('intval', $r['floors']);
        pruefe($r['ok'] && count($neu) === 2 && str_contains($r['message'], 'rechnet der Server im Hintergrund weiter'),
            'Zeit im Upload um: Antwort sofort, der Rest im Hintergrund – ' . $r['message']);
        $audit = Database::first("SELECT details FROM audit_log WHERE action = 'plan.upload' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $siteId]);
        gleich(json_decode((string) ($audit['details'] ?? ''), true)['im_hintergrund'] ?? null, $neu, 'Audit: welche Etagen im Hintergrund');
        // Ein Tank und sein Kraftstoffschlauch sind Objekte im Plan wie jede
        // Anlage: Kacheln im Hintergrund und ein neuer Plan rühren ihre Lage
        // nicht an (sie steht in Plan-Koordinaten an der Anlage bzw. Leitung).
        $tankId = (int) (AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennung . ' Tank', 'fest' => 1, 'floor_id' => $neu[0],
            'plan_x' => 70, 'plan_y' => 12, 'eigenschaften' => ['inhalt_l' => '1000', 'bauform' => 'Quader']])['id'] ?? 0);
        $schlauchId = Database::insert('leitungen', ['art' => 'kraftstoff', 'fest' => 0, 'floor_id' => $neu[0], 'name' => $kennung . ' Schlauch',
            'kabeltyp' => 'DN 25 (1″)', 'anzahl' => 2, 'modell' => '', 'von_objekt_id' => $tankId, 'punkte' => '[[70,12],[72,14]]', 'farbe' => '', 'notizen' => '',
            'created_at' => gmdate('c'), 'updated_at' => gmdate('c')]);
        $tankLage = static fn (): array => [Database::first('SELECT art, floor_id, plan_x, plan_y FROM objekte WHERE id = ?', [$tankId]),
            Database::first('SELECT art, floor_id, von_objekt_id, punkte FROM leitungen WHERE id = ?', [$schlauchId])];
        $tankVorher = $tankLage();
        pruefe($tankId > 0 && ($tankVorher[0]['art'] ?? '') === 'tank' && ($tankVorher[1]['art'] ?? '') === 'kraftstoff', 'Tank mit Schlauch auf der Etage, die im Hintergrund rechnet');
        $fertig = false;
        for ($i = 0; $i < 900 && !$fertig; $i++) {
            $fertig = array_filter($neu, static fn (int $id): bool => PlanTileService::meta(SiteService::floor($id)) === null) === [];
            if (!$fertig) {
                usleep(100_000);
            }
        }
        pruefe($fertig, 'beide Seiten im Hintergrund gekachelt (bin/power plan:kacheln --etage=…)');
        gleich($tankLage(), $tankVorher, 'Tank und Schlauch nach den Kacheln im Hintergrund unverändert (Art, Etage, Lage)');
        $log = (string) @file_get_contents(dirname(__DIR__) . '/storage/logs/kacheln.log');
        pruefe(str_contains($log, $kennung . ' / Etage (Seite 2): '), 'storage/logs/kacheln.log nennt Standort und Etage');

        // Im Hintergrund nie zwei Bauten nebeneinander: Hält einer die Sperre,
        // wartet der nächste (und sagt es im Protokoll), statt mitzurechnen.
        $logPfad = dirname(__DIR__) . '/storage/logs/kacheln.log';
        $zweite = SiteService::floor($neu[1]);
        PlanTileService::aufraeumen($zweite);
        $sperre = fopen(PlanTileService::hintergrundSperre(), 'c');
        flock($sperre, LOCK_EX);
        try {
            clearstatcache(true, $logPfad);
            $logVorher = (int) @filesize($logPfad);
            PlanTileService::imHintergrund([(int) $zweite['id']]);
            $wartet = false;
            for ($i = 0; $i < 200 && !$wartet; $i++) {
                usleep(100_000);
                $wartet = str_contains(substr((string) @file_get_contents($logPfad), $logVorher), 'Ein anderer Prozess rechnet gerade Kacheln');
            }
            usleep(500_000);
            pruefe($wartet && PlanTileService::meta(SiteService::floor($neu[1])) === null, 'zweiter Bau im Hintergrund wartet, solange ein anderer die Sperre hält');
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
        for ($i = 0; $i < 600 && PlanTileService::meta(SiteService::floor($neu[1])) === null; $i++) {
            usleep(100_000);
        }
        pruefe(PlanTileService::meta(SiteService::floor($neu[1])) !== null, '… und rechnet, sobald sie frei ist');

        // Abbruch über einer fertigen Pyramide (der Upload mit Frist): Die
        // bisherige bleibt, wie sie war – vorher war sie danach ganz weg, und
        // die Karte zeichnete im Browser bis zur nächsten Nacht.
        $eine = SiteService::floor($neu[0]);
        $metaVorher = PlanTileService::meta($eine);
        pruefe($metaVorher !== null, 'vorher: fertige Pyramide');
        $b = PlanTileService::bauen($eine, true, true, microtime(true) - 1);
        $reste = array_values(array_filter(scandir(PlanTileService::planDir($eine)) ?: [], static fn (string $n): bool => str_ends_with($n, '.bau')));
        pruefe(!$b['ok'] && ($b['abgebrochen'] ?? false) === true && PlanTileService::meta($eine) === $metaVorher && $reste === []
            && (PlanTileService::quelle($eine)['art'] ?? '') === 'kacheln',
            'bauen() mit abgelaufener Frist: abgebrochen – die bisherige Pyramide bleibt, kein Bauordner übrig');

        // Die nächtliche Wartung rechnet nach, was fehlt
        PlanTileService::aufraeumen($eine);
        $zeilen = PlanTileService::fehlendeNachrechnen();
        pruefe(PlanTileService::meta(SiteService::floor($neu[0])) !== null
            && array_filter($zeilen, static fn (string $z): bool => str_starts_with($z, 'Kacheln nachgerechnet: ' . $kennung)) !== [],
            'Wartung: fehlende Kacheln nachgerechnet – ' . implode(' | ', array_filter($zeilen, static fn (string $z): bool => str_contains($z, $kennung))));
        pruefe(array_filter(PlanTileService::fehlendeNachrechnen(), static fn (string $z): bool => str_contains($z, $kennung)) === [],
            'Wartung: ein zweiter Lauf hat nichts mehr zu tun');

        // Etage gelöscht, während ihr Plan rechnet (im Hintergrund Minuten):
        // SiteService räumt die Kacheln weg, der Bau schriebe danach weiter.
        // Er sieht am Ende nach, ob noch eine Etage auf sie zeigt.
        $wegId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Gleich weg']);
        $wegName = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        pruefPdf(SiteService::planDir() . '/' . $wegName, 1);
        Database::update('floors', $wegId, ['plan_file' => $wegName, 'plan_type' => 'pdf', 'plan_page' => 1]);
        $weg = SiteService::floor($wegId);
        Database::execute('DELETE FROM floors WHERE id = ?', [$wegId]);
        $b = PlanTileService::bauen($weg, true);
        pruefe(!$b['ok'] && !is_dir(PlanTileService::planDir($weg)), 'Etage während des Baus gelöscht: kein Kachelordner bleibt – ' . $b['message']);
        @unlink(SiteService::planDir() . '/' . $wegName);

        // Neuer Plan auf einer Etage, die schon einen hat: Der alte geht samt
        // Kacheln, sobald keine Etage ihn mehr nutzt – bei einem PDF je Seite.
        $seite1 = SiteService::floor($neu[0]);
        $seite2 = SiteService::floor($neu[1]);
        $pdfAlt = (string) SiteService::planPath($seite1);
        pruefe(PlanTileService::meta($seite1) !== null && PlanTileService::meta($seite2) !== null, 'vorher: beide Seiten gekachelt');
        imagepng(imagecreatetruecolor(2000, 1400), $tmp . '/ersatz1.png');
        $r = SiteService::uploadPlan($siteId, $datei($tmp . '/ersatz1.png', 'ersatz1.png'), ['floor_ids' => [$neu[0]]]);
        pruefe($r['ok'] && is_file($pdfAlt) && !is_dir(PlanTileService::planDir($seite1)) && is_dir(PlanTileService::planDir($seite2)),
            'neuer Plan für Seite 1: deren Kacheln weg, das PDF bleibt für Seite 2');
        $ersatz1 = SiteService::floor($neu[0]);
        pruefe(is_file(SiteService::planDir() . '/' . $ersatz1['plan_file']) && is_dir(PlanTileService::planDir($ersatz1)), 'der erste Ersatz liegt samt Kacheln da');
        imagepng(imagecreatetruecolor(2000, 1400), $tmp . '/ersatz2.png');
        $r = SiteService::uploadPlan($siteId, $datei($tmp . '/ersatz2.png', 'ersatz2.png'), ['floor_ids' => [$neu[0], $neu[1]]]);
        pruefe($r['ok'] && !is_file($pdfAlt) && !is_dir(PlanTileService::planDir($seite2)) && !is_file(SiteService::planDir() . '/' . $ersatz1['plan_file'])
            && !is_dir(PlanTileService::planDir($ersatz1)) && PlanTileService::meta(SiteService::floor($neu[0])) !== null,
            'neuer Plan für beide: das PDF und der erste Ersatz samt Kacheln weg, der neue gekachelt');
        gleich($tankLage(), $tankVorher, 'Tank und Schlauch nach zwei neuen Plänen auf ihrer Etage unverändert (Art, Etage, Lage)');

        // Übernommene Pläne (Git, andere Instanz, Vorschlag): In einer
        // Web-Anfrage rechnet kachelnBauen() nicht selbst – das hielte die
        // Sitzung und liefe in die Frist von nginx –, sondern gibt sie gleich
        // an den Hintergrund. Auf der Kommandozeile rechnet es selbst.
        $syncId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Übernommen']);
        $syncName = 'sync' . $syncId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        pruefPdf(SiteService::planDir() . '/' . $syncName, 1);
        Database::update('floors', $syncId, ['plan_file' => $syncName, 'plan_type' => 'pdf', 'plan_page' => 1]);
        Anfragezeit::$beginn = microtime(true);
        try {
            $start = microtime(true);
            $spaeter = LocationBundle::kachelnBauen([$syncId]);
            $dauer = microtime(true) - $start;
            $sofort = PlanTileService::meta(SiteService::floor($syncId));
        } finally {
            Anfragezeit::$beginn = null;
        }
        pruefe($spaeter === [$syncId] && $sofort === null && $dauer < 2,
            sprintf('Web-Anfrage: übernommener Plan geht gleich in den Hintergrund (%.2f s, nichts selbst gerechnet)', $dauer));
        for ($i = 0; $i < 600 && PlanTileService::meta(SiteService::floor($syncId)) === null; $i++) {
            usleep(100_000);
        }
        pruefe(PlanTileService::meta(SiteService::floor($syncId)) !== null, '… dort gekachelt');
        PlanTileService::aufraeumen(SiteService::floor($syncId));
        $spaeter = LocationBundle::kachelnBauen([$syncId]);
        pruefe($spaeter === [] && PlanTileService::meta(SiteService::floor($syncId)) !== null, 'Kommandozeile: kachelnBauen() rechnet selbst');
    }

} finally {
    if ($tankId !== null && $tankId > 0) {
        AnlagenService::loeschen($tankId);
    }
    $etagenIds = $geloeschteEtagen;
    foreach (SiteService::floors($siteId) as $f) {
        $etagenIds[] = (string) $f['id'];
        PlanTileService::aufraeumen($f);
    }
    SiteService::deleteSite($siteId);
    Database::execute('DELETE FROM audit_log WHERE id > ? AND ((target_type = ? AND target_id = ?) OR details LIKE ?)', [$auditVorher, 'site', (string) $siteId, '%' . $kennung . '%']);
    foreach ($etagenIds as $id) {
        Database::execute("DELETE FROM audit_log WHERE id > ? AND target_type = 'floor' AND target_id = ?", [$auditVorher, $id]);
    }
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}

ende('grundrisse');
