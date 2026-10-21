<?php
declare(strict_types=1);

/**
 * Grundrisse bis 100 MB: Grenzen, Prüfung beim Hochladen, große Bilder über
 * poppler statt gd (Speicher), Kacheln im Upload nur bis zur Zeitgrenze und
 * danach im Hintergrund, Streifenhöhe und die nächtliche Nachrechnung.
 *
 * Große Dateien entstehen dünn belegt (hilfen/grundriss-dateien.php) – ein
 * PDF mit 100 MB kostet so weder Zeit noch Platte.
 */

require __DIR__ . '/hilfen/pruefung.php';
require __DIR__ . '/hilfen/grundriss-dateien.php';

use App\Services\BildPdf;
use App\Services\PdfRasterService;
use App\Services\PlanTileService;
use App\Services\SiteService;
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
    pruefe(preg_match('#location = /standorte/grundriss \{\s*client_max_body_size 105m;#', $site) === 1
        && preg_match('#location ~ \^/index\\\\\.php\$ \{[^}]*client_max_body_size 105m;#s', $site) === 1
        && preg_match('#\n    client_max_body_size 16m;#', $site) === 1, 'nginx-Vorlage: 105m nur für den Grundriss (und index.php dahinter), sonst 16m');

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
    // Kopf mit 0 × 0 Pixeln: libmagic hält es für ein PNG, lesen lässt es sich nicht.
    pruefPngKopf($tmp . '/kaputt.png', 0, 0);
    $r = SiteService::uploadPlan($siteId, $datei($tmp . '/kaputt.png', 'kaputt.png'), ['neue_etagen' => true]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'beschädigt'), 'PNG ohne lesbaren Kopf → abgelehnt: ' . $r['message']);
    pruefe($planDateien() === $vorher && count(SiteService::floors($siteId)) === $etagenVorher, 'abgelehnt heißt: keine Datei abgelegt, keine Etage angelegt');
    if (extension_loaded('gd')) {
        imagepng(imagecreatetruecolor(640, 480), $tmp . '/klein.png');
        gleich(SiteService::bildPruefen($tmp . '/klein.png'), null, 'ein gewöhnliches kleines Bild passt');
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
        $audit = Database::first("SELECT details FROM audit_log WHERE action = 'plan.upload' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $siteId]);
        gleich(json_decode((string) ($audit['details'] ?? ''), true)['im_hintergrund'] ?? null, $neu, 'Audit: welche Etagen im Hintergrund');
        $fertig = false;
        for ($i = 0; $i < 900 && !$fertig; $i++) {
            $fertig = array_filter($neu, static fn (int $id): bool => PlanTileService::meta(SiteService::floor($id)) === null) === [];
            if (!$fertig) {
                usleep(100_000);
            }
        }
        pruefe($fertig, 'beide Seiten im Hintergrund gekachelt (bin/power plan:kacheln --etage=…)');
        $log = (string) @file_get_contents(dirname(__DIR__) . '/storage/logs/kacheln.log');
        pruefe(str_contains($log, $kennung . ' / Etage (Seite 2): '), 'storage/logs/kacheln.log nennt Standort und Etage');

        // Abbruch mitten im Bau: keine halbe Pyramide, kein meta
        $eine = SiteService::floor($neu[0]);
        PlanTileService::aufraeumen($eine);
        $b = PlanTileService::bauen($eine, true, true, microtime(true) - 1);
        $reste = glob(PlanTileService::planDir($eine) . '/[0-9]*', GLOB_ONLYDIR) ?: [];
        pruefe(!$b['ok'] && ($b['abgebrochen'] ?? false) === true && PlanTileService::meta($eine) === null && $reste === [],
            'bauen() mit abgelaufener Frist: abgebrochen, keine Kacheln, kein meta');

        // Die nächtliche Wartung rechnet nach, was fehlt
        $zeilen = PlanTileService::fehlendeNachrechnen();
        pruefe(PlanTileService::meta(SiteService::floor($neu[0])) !== null
            && array_filter($zeilen, static fn (string $z): bool => str_starts_with($z, 'Kacheln nachgerechnet: ' . $kennung)) !== [],
            'Wartung: fehlende Kacheln nachgerechnet – ' . implode(' | ', array_filter($zeilen, static fn (string $z): bool => str_contains($z, $kennung))));
        pruefe(array_filter(PlanTileService::fehlendeNachrechnen(), static fn (string $z): bool => str_contains($z, $kennung)) === [],
            'Wartung: ein zweiter Lauf hat nichts mehr zu tun');
    }

} finally {
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
