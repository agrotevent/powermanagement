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
        pruefe(($m['ok'] ?? false) && ($meta['format'] ?? '') === 'png' && isset($meta['kante'])
            && (int) $meta['breite'] * (int) $meta['hoehe'] <= PlanTileService::MAX_PIXEL && (int) $meta['breite'] > 8000,
            sprintf('über poppler auf höchstens %d Megapixel gerechnet: %s', intdiv(PlanTileService::MAX_PIXEL, 1_000_000), (string) ($m['message'] ?? '?')));
        pruefe(($m['prozess_mb'] ?? null) === null || $m['prozess_mb'] < 300,
            sprintf('Speicher des PHP-Prozesses %s MB, unter 300 (mit gd über 500: 384 MB Bild, 96 MB Lesepuffer), memory_limit-Anteil %s MB, %s s',
                (string) ($m['prozess_mb'] ?? '?'), (string) ($m['php_mb'] ?? '?'), (string) ($m['sekunden'] ?? '?')));
        $karte = $etage !== null ? PlanTileService::quelle(SiteService::floor((int) $etage['id'])) : null;
        gleich($karte['art'] ?? null, 'kacheln', 'die Karte bekommt Kacheln, nicht das ganze Bild');

        // ------------------------------------------------ Zeitgrenze: Abbruch, dann Hintergrund
        $pdf = $tmp . '/zwei.pdf';
        pruefPdf($pdf, 2);
        $r = SiteService::uploadPlan($siteId, $datei($pdf, 'zwei.pdf'), ['neue_etagen' => true, 'kachelzeit' => 0]);
        $neu = array_map('intval', $r['floors']);
        pruefe($r['ok'] && count($neu) === 2 && str_contains($r['message'], 'rechnet der Server im Hintergrund weiter'),
            'Zeit im Upload um: Antwort sofort, der Rest im Hintergrund – ' . $r['message']);
        // Ins Audit vor dem Kacheln (ein Update, das währenddessen beginnt,
        // sichert sonst ohne den Eintrag), welche Etagen im Hintergrund
        // rechnen, ins Protokoll.
        $audit = Database::first("SELECT details FROM audit_log WHERE action = 'plan.upload' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $siteId]);
        $details = json_decode((string) ($audit['details'] ?? ''), true) ?: [];
        pruefe(($details['floors'] ?? null) === $neu && !isset($details['im_hintergrund']), 'Audit: Etagen des Uploads (vor dem Kacheln geschrieben)');
        $protokoll = (string) @file_get_contents(dirname(__DIR__) . '/storage/logs/app.log', false, null, max(0, (int) @filesize(dirname(__DIR__) . '/storage/logs/app.log') - 65536));
        pruefe(str_contains($protokoll, 'Grundriss: Kacheln im Hintergrund ' . json_encode(['standort' => $siteId, 'etagen' => $neu])),
            'Protokoll: welche Etagen im Hintergrund');
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

        // Abbruch über einer fertigen Pyramide (der Upload und früher der
        // Abgleich mit Frist, auf einem Namen, der schon Kacheln hat): keine
        // halbe Pyramide und kein kacheln.json, das auf fehlende Kacheln zeigt.
        $eine = SiteService::floor($neu[0]);
        pruefe(PlanTileService::meta($eine) !== null, 'vorher: fertige Pyramide');
        $b = PlanTileService::bauen($eine, true, true, microtime(true) - 1);
        $reste = glob(PlanTileService::planDir($eine) . '/[0-9]*', GLOB_ONLYDIR) ?: [];
        pruefe(!$b['ok'] && ($b['abgebrochen'] ?? false) === true && PlanTileService::meta($eine) === null && $reste === []
            && (PlanTileService::quelle($eine)['art'] ?? '') === 'pdf',
            'bauen() mit abgelaufener Frist: abgebrochen, keine Kacheln, kein kacheln.json – die Karte zeichnet im Browser statt ins Leere');

        // Die nächtliche Wartung rechnet nach, was fehlt
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
    $etagenIds = [];
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
