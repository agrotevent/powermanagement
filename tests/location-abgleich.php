<?php
declare(strict_types=1);

/**
 * Location-Abgleich gegen ein echtes Git-Repository im Format von
 * MasterAdmin: übernehmen, Feststromanschlüsse hochladen (mobile Anlagen
 * und Kabel bleiben hier), location.json unangetastet lassen, Änderungen
 * aus MasterAdmin wieder holen, ältere strom.json (Format 1) lesen.
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

    // Planung anlegen: zwei Feststromanschlüsse (gehören zur Location), ein
    // mobiler Verteiler mit Kabel am Hausanschluss (gehört zur Veranstaltung)
    $floorId = (int) $etage['id'];
    $an = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Hausanschluss Lager', 'floor_id' => $floorId, 'plan_x' => 5, 'plan_y' => 5])['id'];
    $wv = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Wandverteiler Halle', 'fest' => 1, 'floor_id' => $floorId, 'plan_x' => 60, 'plan_y' => 10])['id'];
    SchutzService::vorschlagen($wv);
    \App\Services\MesspunktService::speichern($wv, null, ['geraet' => 'shellypro3em-lager', 'kanal' => 'em:0', 'rolle' => 'einspeisung']);
    $vt = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Lagerverteiler', 'floor_id' => $floorId, 'plan_x' => 20, 'plan_y' => 30])['id'];
    SchutzService::vorschlagen($vt);
    gleich([(int) AnlagenService::find($an)['fest'], (int) AnlagenService::find($wv)['fest'], (int) AnlagenService::find($vt)['fest']], [1, 1, 0], 'fest/mobil wie angelegt');
    $ab = Database::first("SELECT id, nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr LIMIT 1", [$an]);
    $ein = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' LIMIT 1", [$vt]);
    $k = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $an, 'von_anschluss_id' => (int) $ab['id'],
        'zu_objekt_id' => $vt, 'zu_anschluss_id' => (int) $ein['id'], 'kabeltyp' => 'H07RN-F', 'querschnitt_mm2' => 16, 'punkte' => [[5, 5], [20, 30]]]);
    pruefe($k['ok'], 'Kabel vom Feststromanschluss zum mobilen Verteiler angelegt');
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'name' => 'Brücke Tor', 'anzahl' => 2, 'punkte' => [[10, 50], [14, 50]]]);
    // Fest verlegt: Erdkabel vom Hausanschluss zum Wandverteiler (dessen Eingang)
    $wvEin = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' ORDER BY nr LIMIT 1", [$wv]);
    $erd = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'name' => 'Erdkabel Halle', 'von_objekt_id' => $an,
        'zu_objekt_id' => $wv, 'zu_anschluss_id' => (int) $wvEin['id'], 'kabeltyp' => 'NYY-J', 'querschnitt_mm2' => 25, 'punkte' => [[5, 5], [60, 5], [60, 10]]]);
    pruefe($erd['ok'] && (int) Database::scalar('SELECT fest FROM leitungen WHERE id = ?', [(int) $erd['id']]) === 1, 'Erdkabel zwischen Feststromanschlüssen ist fest verlegt');
    // Ein fest verlegter Kraftstoffschlauch bleibt hier – ältere Gegenstellen kennen keinen Schlauch
    $festerSchlauch = Database::insert('leitungen', ['art' => 'kraftstoff', 'fest' => 1, 'floor_id' => $floorId, 'name' => 'Schlauch Bodentank', 'kabeltyp' => 'DN 25 (1″)',
        'anzahl' => 2, 'modell' => 'HK-A3', 'punkte' => '[[70,10],[72,12]]', 'farbe' => '', 'notizen' => '', 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')]);

    $hoch = LocationSync::locationHochladen($siteId, 'pruefung');
    pruefe($hoch['ok'], 'hochgeladen: ' . $hoch['message']);
    $strom = json_decode($sh('git --git-dir=' . escapeshellarg($fern) . ' show main:locations/lager/strom.json'), true);
    gleich($strom['format'] ?? null, 2, 'strom.json im Format 2');
    $namen = array_column($strom['anlagen'] ?? [], 'name');
    sort($namen);
    gleich($namen, ['Hausanschluss Lager', 'Wandverteiler Halle'], 'strom.json enthält nur die Feststromanschlüsse');
    gleich(array_column($strom['leitungen'] ?? [], 'name'), ['Erdkabel Halle'], 'strom.json enthält nur das fest verlegte Kabel – kein mobiles, keine Kabelbrücke, kein Schlauch');
    $erdJson = $strom['leitungen'][0] ?? [];
    pruefe(($erdJson['fest'] ?? false) === true && $erdJson['von'] !== null && $erdJson['zu'] !== null && ($erdJson['zu_anschluss'] ?? '') === 'eingang:1',
        'festes Kabel mit beiden Enden und Anschluss');
    pruefe(array_filter($strom['anlagen'], static fn ($a) => ($a['fest'] ?? false) !== true) === [], 'jede Anlage als fest gekennzeichnet');
    $wvJson = array_values(array_filter($strom['anlagen'], static fn ($a) => $a['name'] === 'Wandverteiler Halle'))[0];
    pruefe(count($wvJson['schutzorgane']) > 2 && count($wvJson['messpunkte']) === 1, 'Schutzorgane und Messgeräte des Feststromanschlusses in strom.json');
    $loc = json_decode($sh('git --git-dir=' . escapeshellarg($fern) . ' show main:locations/lager/location.json'), true);
    gleich(count($loc['etagen'][0]['dosen']), 2, 'location.json: Dosen von MasterAdmin unangetastet');
    gleich($loc['etagen'][0]['racks'][0]['einbauten'][1]['label'] ?? '', 'USV', 'location.json: Racks unangetastet');
    $liste = LocationSync::locationsImRepo();
    $lager = array_values(array_filter($liste['locations'], static fn ($l) => $l['schluessel'] === 'lager'))[0] ?? null;
    gleich([$lager['fest'] ?? null, $lager['fest_leitungen'] ?? null], [2, 1], 'Liste zählt Feststromanschlüsse und feste Leitungen');

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

    // Hier geht etwas verloren bzw. wird verändert – Übernehmen stellt die
    // Feststromanschlüsse wieder her und lässt die mobile Planung stehen.
    AnlagenService::loeschen($wv);
    Database::update('objekte', $an, ['name' => 'Hausanschluss (versehentlich umbenannt)']);
    Database::execute('DELETE FROM schutzorgane WHERE objekt_id = ?', [$an]);
    $vtVorher = AnlagenService::find($vt);
    $erg = LocationSync::uebernehmen('lager');
    pruefe($erg['ok'] && str_contains($erg['message'], '2 Feststromanschlüsse und 1 fest verlegte Leitung'), 'erneut übernommen: ' . $erg['message']);
    $wv2 = Database::first("SELECT * FROM objekte WHERE name = 'Wandverteiler Halle' AND site_id = ?", [$siteId]);
    pruefe($wv2 !== null && (float) $wv2['plan_x'] === 60.0 && (int) $wv2['fest'] === 1, 'gelöschter Feststromanschluss mit Position zurück');
    gleich(count(SchutzService::liste((int) $wv2['id'])), count($wvJson['schutzorgane']), 'Schutzorgane zurück');
    $ohne = (int) Database::scalar("SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND schutzorgan_id IS NULL", [(int) $wv2['id']]);
    gleich($ohne, 0, 'Zuordnung Abgang → Sicherung zurück');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte WHERE objekt_id = ?', [(int) $wv2['id']]), 1, 'Messgerät zurück');
    gleich((string) Database::scalar('SELECT name FROM objekte WHERE id = ?', [$an]), 'Hausanschluss Lager', 'Feststromanschluss auf den Stand des Repositorys gebracht');
    $vtNachher = AnlagenService::find($vt);
    pruefe($vtNachher !== null && $vtNachher['name'] === $vtVorher['name'] && (int) $vtNachher['fest'] === 0, 'mobiler Verteiler bleibt unverändert');
    $kabel = Database::first("SELECT * FROM leitungen WHERE floor_id = ? AND art = 'kabel'", [$floorId]);
    $kabelAb = $kabel !== null && $kabel['von_anschluss_id'] !== null ? Database::first('SELECT objekt_id, richtung, nr FROM anschluesse WHERE id = ?', [(int) $kabel['von_anschluss_id']]) : null;
    pruefe($kabelAb !== null && (int) $kabelAb['objekt_id'] === $an && $kabelAb['richtung'] === 'abgang' && (int) $kabelAb['nr'] === (int) $ab['nr'],
        'Kabel hängt nach dem Übernehmen weiter am selben Abgang des Feststromanschlusses');
    pruefe($kabel !== null && (int) $kabel['zu_objekt_id'] === $vt && $kabel['zu_anschluss_id'] !== null, 'Kabel hängt weiter am mobilen Verteiler');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM leitungen WHERE floor_id = ? AND art = 'kabelbruecke'", [$floorId]), 1, 'Kabelbrücke bleibt');
    $erd2 = Database::first("SELECT * FROM leitungen WHERE floor_id = ? AND name = 'Erdkabel Halle'", [$floorId]);
    $erdEin = $erd2 !== null && $erd2['zu_anschluss_id'] !== null ? Database::first('SELECT objekt_id, richtung, nr FROM anschluesse WHERE id = ?', [(int) $erd2['zu_anschluss_id']]) : null;
    pruefe($erd2 !== null && (int) $erd2['fest'] === 1 && (int) $erd2['von_objekt_id'] === $an && (int) $erd2['zu_objekt_id'] === (int) $wv2['id']
        && $erdEin !== null && $erdEin['richtung'] === 'eingang' && (int) $erdEin['nr'] === 1, 'festes Kabel hängt wieder an beiden Feststromanschlüssen');
    $etage = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
    gleich(count(json_decode((string) $etage['fremdbestand'], true)['dosen']), 3, 'neue Dose aus MasterAdmin angekommen');

    // Festes Kabel hier gelöscht → kommt zurück; zweimal übernehmen verdoppelt nichts
    LeitungService::loeschen((int) $erd2['id']);
    LocationSync::uebernehmen('lager');
    LocationSync::uebernehmen('lager');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM leitungen WHERE floor_id = ? AND name = 'Erdkabel Halle' AND fest = 1", [$floorId]), 1, 'gelöschtes festes Kabel zurück, nicht doppelt');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ? AND fest = 1', [$siteId]), 2, 'erneutes Übernehmen legt nichts doppelt an');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ? AND fest = 0', [$siteId]), 1, 'mobile Anlagen unverändert');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM leitungen WHERE floor_id = ? AND fest = 0', [$floorId]), 2, 'mobile Leitungen unverändert');
    gleich((string) Database::scalar('SELECT art FROM leitungen WHERE id = ?', [$festerSchlauch]), 'kraftstoff', 'der feste Schlauch bleibt beim Übernehmen stehen');

    // Eine strom.json im alten Format 1 (mit ganzer Planung) liefert nur
    // ihre Stromanschlüsse – mobile Anlagen und Kabel daraus kommen nicht an.
    $git($mo, 'pull -q origin main');
    $alt = json_decode((string) file_get_contents($mo . '/locations/lager/strom.json'), true);
    $alt['format'] = 1;
    foreach ($alt['anlagen'] as &$a) {
        unset($a['fest']);
    }
    unset($a);
    $alt['anlagen'][] = ['schluessel' => 'fremder-verteiler', 'art' => 'verteilung', 'name' => 'Verteiler aus fremder Planung', 'etage' => 'grundriss', 'x' => 70, 'y' => 70,
        'schutzorgane' => [], 'anschluesse' => [], 'messpunkte' => []];
    $alt['anlagen'][] = ['schluessel' => 'zweiter-anschluss', 'art' => 'anschluss', 'name' => 'Baustrom Tor 2', 'etage' => 'grundriss', 'x' => 90, 'y' => 90,
        'schutzorgane' => [], 'anschluesse' => [['richtung' => 'abgang', 'stecker' => 'cee63', 'nr' => 1]], 'messpunkte' => []];
    $alt['leitungen'] = [['schluessel' => 'fremdes-kabel', 'art' => 'kabel', 'etage' => 'grundriss', 'punkte' => [[1, 1], [2, 2]]]];
    file_put_contents($mo . '/locations/lager/strom.json', json_encode($alt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $git($mo, 'commit -q -am "strom.json im Format 1"');
    $git($mo, 'push -q origin main');
    $erg = LocationSync::uebernehmen('lager');
    pruefe($erg['ok'], 'Format 1 übernommen: ' . $erg['message']);
    pruefe(Database::first("SELECT id FROM objekte WHERE name = 'Baustrom Tor 2' AND site_id = ? AND fest = 1", [$siteId]) !== null, 'Format 1: Stromanschluss kommt als Feststromanschluss an');
    pruefe(Database::first("SELECT id FROM objekte WHERE name = 'Verteiler aus fremder Planung'") === null, 'Format 1: mobiler Verteiler kommt nicht an');
    pruefe(Database::first("SELECT id FROM leitungen WHERE sync_key = 'fremdes-kabel'") === null, 'Format 1: Kabel kommen nicht an');
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
