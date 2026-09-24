<?php
declare(strict_types=1);

/**
 * Location-Abgleich gegen ein echtes Git-Repository im Format von
 * MasterAdmin: übernehmen, Stromplanung hochladen, location.json
 * unangetastet lassen, Änderungen aus MasterAdmin wieder holen.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\LeitungService;
use App\Services\LocationSync;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Database;
use App\Support\Programm;
use App\Support\Settings;

if (Programm::finden('git') === null) {
    uebersprungen('location-abgleich', 'git fehlt');
}

$tmp = sys_get_temp_dir() . '/pm-location-' . bin2hex(random_bytes(4));
$fern = $tmp . '/fern.git';
$mo = $tmp . '/masteradmin';
$sh = static function (string $befehl): string {
    $aus = [];
    exec($befehl . ' 2>&1', $aus, $rc);
    if ($rc !== 0) {
        throw new RuntimeException($befehl . ': ' . implode("\n", $aus));
    }
    return implode("\n", $aus);
};
$git = static fn (string $wo, string $was): string => $sh('git -C ' . escapeshellarg($wo) . ' -c user.name=Test -c user.email=test@local ' . $was);

// Arbeitskopie und Einstellungen der Installation beiseitelegen
$kopie = LocationSync::arbeitsKopie();
$beiseite = is_dir(dirname($kopie)) ? dirname($kopie) . '.pruefung-' . bin2hex(random_bytes(3)) : null;
if ($beiseite !== null) {
    rename(dirname($kopie), $beiseite);
}
$alteUrl = Settings::get('inventory.repo_url');
$alterZweig = Settings::get('inventory.branch');

$siteId = null;
try {
    mkdir($tmp, 0775, true);
    $sh('git init -q --bare -b main ' . escapeshellarg($fern));
    $sh('git clone -q ' . escapeshellarg($fern) . ' ' . escapeshellarg($mo));
    mkdir($mo . '/locations', 0775, true);
    $sh('cp -r ' . escapeshellarg(__DIR__ . '/hilfen/location-lager') . ' ' . escapeshellarg($mo . '/locations/lager'));
    $git($mo, 'checkout -q -b main');
    $git($mo, 'add -A');
    $git($mo, 'commit -q -m "Location lager aus MasterAdmin"');
    $git($mo, 'push -q origin main');

    Settings::put('inventory.repo_url', 'file://' . $fern);
    Settings::put('inventory.branch', 'main');
    Config::set('inventory.repo_url', 'file://' . $fern);
    pruefe(LocationSync::hindernis() === null, 'ohne Token bereit (lokales Repository)');

    $liste = LocationSync::locationsImRepo();
    pruefe($liste['ok'], 'Repository lesbar: ' . $liste['message']);
    $lager = array_values(array_filter($liste['locations'], static fn ($l) => $l['schluessel'] === 'lager'))[0] ?? null;
    pruefe($lager !== null && $lager['status'] === 'neu' && $lager['plaene'] === 1, 'Location „lager“ als neu erkannt');

    $erg = LocationSync::uebernehmen('lager');
    pruefe($erg['ok'], 'übernommen: ' . $erg['message']);
    $site = Database::first("SELECT * FROM sites WHERE sync_key = 'lager'");
    $siteId = (int) $site['id'];
    $etage = Database::first("SELECT * FROM floors WHERE site_id = ? AND sync_key = 'grundriss'", [$siteId]);
    pruefe($etage !== null && $etage['plan_file'] !== '' && is_file(SiteService::planDir() . '/' . $etage['plan_file']), 'Grundriss übernommen');
    gleich((float) $etage['massstab_m'], 40.0, 'Maßstab übernommen');
    $fremd = json_decode((string) $etage['fremdbestand'], true);
    gleich(count($fremd['dosen']), 2, 'Dosen als Fremdbestand');
    gleich($fremd['racks'][0]['watt'] ?? 0, 165, 'Rack mit Leistungsaufnahme der Einbauten');

    // Stromplanung anlegen
    $floorId = (int) $etage['id'];
    $vt = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Lagerverteiler', 'floor_id' => $floorId, 'plan_x' => 20, 'plan_y' => 30])['id'];
    SchutzService::vorschlagen($vt);
    $an = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Hausanschluss Lager', 'floor_id' => $floorId, 'plan_x' => 5, 'plan_y' => 5])['id'];
    $ab = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' LIMIT 1", [$an]);
    $ein = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' LIMIT 1", [$vt]);
    $k = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $an, 'von_anschluss_id' => (int) $ab['id'],
        'zu_objekt_id' => $vt, 'zu_anschluss_id' => (int) $ein['id'], 'kabeltyp' => 'H07RN-F', 'querschnitt_mm2' => 16, 'punkte' => [[5, 5], [20, 30]]]);
    pruefe($k['ok'], 'Kabel angelegt');
    \App\Services\MesspunktService::speichern($vt, null, ['geraet' => 'shellypro3em-lager', 'kanal' => 'em:0', 'rolle' => 'einspeisung']);

    $hoch = LocationSync::locationHochladen($siteId, 'pruefung');
    pruefe($hoch['ok'], 'hochgeladen: ' . $hoch['message']);
    $strom = json_decode($sh('git --git-dir=' . escapeshellarg($fern) . ' show main:locations/lager/strom.json'), true);
    gleich(count($strom['anlagen'] ?? []), 2, 'strom.json enthält die Anlagen');
    gleich(count($strom['leitungen'] ?? []), 1, 'strom.json enthält das Kabel');
    $vtJson = array_values(array_filter($strom['anlagen'], static fn ($a) => $a['name'] === 'Lagerverteiler'))[0];
    pruefe(count($vtJson['schutzorgane']) > 2 && count($vtJson['messpunkte']) === 1, 'Schutzorgane und Messgeräte in strom.json');
    $loc = json_decode($sh('git --git-dir=' . escapeshellarg($fern) . ' show main:locations/lager/location.json'), true);
    gleich(count($loc['etagen'][0]['dosen']), 2, 'location.json: Dosen von MasterAdmin unangetastet');
    gleich($loc['etagen'][0]['racks'][0]['einbauten'][1]['label'] ?? '', 'USV', 'location.json: Racks unangetastet');

    // Neue Etage hier → wird in location.json ergänzt
    SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Außenfläche', 'level_no' => 1]);
    LocationSync::locationHochladen($siteId, 'pruefung');
    $loc = json_decode($sh('git --git-dir=' . escapeshellarg($fern) . ' show main:locations/lager/location.json'), true);
    gleich(count($loc['etagen']), 2, 'neue Etage in location.json ergänzt');
    gleich(count($loc['etagen'][0]['dosen']), 2, 'Dosen bleiben dabei erhalten');

    // MasterAdmin ändert location.json (neue Dose) – strom.json bleibt
    $git($mo, 'pull -q origin main');
    $d = json_decode((string) file_get_contents($mo . '/locations/lager/location.json'), true);
    $d['etagen'][0]['dosen'][] = ['schluessel' => 'a3', 'label' => 'A3', 'raum' => '', 'x' => 50, 'y' => 50, 'art' => 'outlet', 'buchsen' => []];
    file_put_contents($mo . '/locations/lager/location.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $git($mo, 'commit -q -am "Dose A3"');
    $git($mo, 'push -q origin main');
    $liste = LocationSync::locationsImRepo();
    $lager = array_values(array_filter($liste['locations'], static fn ($l) => $l['schluessel'] === 'lager'))[0] ?? null;
    gleich($lager['status'] ?? '', 'neuer', 'Änderung aus MasterAdmin wird als „neuer“ erkannt');

    // Stromplanung hier verlieren und aus dem Repository zurückholen
    AnlagenService::loeschen($vt);
    AnlagenService::loeschen($an);
    Database::execute('DELETE FROM leitungen WHERE floor_id = ?', [$floorId]);
    $erg = LocationSync::uebernehmen('lager');
    pruefe($erg['ok'], 'erneut übernommen: ' . $erg['message']);
    $vt2 = Database::first("SELECT * FROM objekte WHERE name = 'Lagerverteiler' AND site_id = ?", [$siteId]);
    pruefe($vt2 !== null && (float) $vt2['plan_x'] === 20.0, 'Anlage mit Position zurück');
    gleich(count(SchutzService::liste((int) $vt2['id'])), count($vtJson['schutzorgane']), 'Schutzorgane zurück');
    $ohne = (int) Database::scalar("SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND schutzorgan_id IS NULL", [(int) $vt2['id']]);
    gleich($ohne, 0, 'Zuordnung Abgang → Sicherung zurück');
    $kabel = Database::first("SELECT * FROM leitungen WHERE floor_id = ? AND art = 'kabel'", [$floorId]);
    pruefe($kabel !== null && $kabel['zu_anschluss_id'] !== null && $kabel['von_anschluss_id'] !== null, 'Kabel mit beiden Anschlüssen zurück');
    $etage = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
    gleich(count(json_decode((string) $etage['fremdbestand'], true)['dosen']), 3, 'neue Dose aus MasterAdmin angekommen');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte WHERE objekt_id = ?', [(int) $vt2['id']]), 1, 'Messgerät zurück');

    // Zweimal übernehmen verdoppelt nichts
    LocationSync::uebernehmen('lager');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ?', [$siteId]), 2, 'erneutes Übernehmen legt nichts doppelt an');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM leitungen WHERE floor_id = ?', [$floorId]), 1, 'Kabel nicht doppelt');
} finally {
    if ($siteId !== null) {
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$siteId]) as $o) {
            AnlagenService::loeschen((int) $o['id']);
        }
        foreach (Database::select('SELECT plan_file FROM floors WHERE site_id = ?', [$siteId]) as $f) {
            if ($f['plan_file'] !== '') {
                @unlink(SiteService::planDir() . '/' . $f['plan_file']);
            }
        }
        SiteService::deleteSite($siteId);
    }
    $alteUrl === null ? Settings::forget('inventory.repo_url') : Settings::put('inventory.repo_url', $alteUrl);
    $alterZweig === null ? Settings::forget('inventory.branch') : Settings::put('inventory.branch', $alterZweig);
    exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg(dirname($kopie)));
    if ($beiseite !== null) {
        rename($beiseite, dirname($kopie));
    }
}

ende('location-abgleich');
