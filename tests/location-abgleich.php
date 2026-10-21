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
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LocationBundle;
use App\Services\LocationSync;
use App\Services\LocationUebertrag;
use App\Services\MesspunktService;
use App\Services\PlanGeoService;
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
$kopieId = null;
$inventarId = null;
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
    // Ein fest eingebautes Messgerät am selben Abgang (geht mit hoch)
    pruefe(MesspunktService::speichern($an, null, ['geraet' => 'shellypro3em-lager-ha', 'kanal' => 'em:0', 'anschluss_id' => (int) $ab['id']])['ok'],
        'Messgerät am Abgang des Hausanschlusses');
    $stelle = static function (mixed $anschlussId) use ($an): ?string {
        if ($anschlussId === null || $anschlussId === false) {
            return null;
        }
        $r = Database::first('SELECT richtung, nr FROM anschluesse WHERE id = ? AND objekt_id = ?', [(int) $anschlussId, $an]);
        return $r === null ? 'zeigt ins Leere' : $r['richtung'] . ':' . (int) $r['nr'];
    };
    $abStelle = 'abgang:' . (int) $ab['nr'];
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'name' => 'Brücke Tor', 'anzahl' => 2, 'punkte' => [[10, 50], [14, 50]]]);
    // Fest verlegt: Erdkabel vom Hausanschluss zum Wandverteiler (dessen Eingang)
    $wvEin = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' ORDER BY nr LIMIT 1", [$wv]);
    $erd = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'name' => 'Erdkabel Halle', 'von_objekt_id' => $an,
        'zu_objekt_id' => $wv, 'zu_anschluss_id' => (int) $wvEin['id'], 'kabeltyp' => 'NYY-J', 'querschnitt_mm2' => 25, 'punkte' => [[5, 5], [60, 5], [60, 10]]]);
    pruefe($erd['ok'] && (int) Database::scalar('SELECT fest FROM leitungen WHERE id = ?', [(int) $erd['id']]) === 1, 'Erdkabel zwischen Feststromanschlüssen ist fest verlegt');

    $hoch = LocationSync::locationHochladen($siteId, 'pruefung');
    pruefe($hoch['ok'], 'hochgeladen: ' . $hoch['message']);
    $strom = json_decode($sh('git --git-dir=' . escapeshellarg($fern) . ' show main:locations/lager/strom.json'), true);
    gleich($strom['format'] ?? null, 2, 'strom.json im Format 2');
    $namen = array_column($strom['anlagen'] ?? [], 'name');
    sort($namen);
    gleich($namen, ['Hausanschluss Lager', 'Wandverteiler Halle'], 'strom.json enthält nur die Feststromanschlüsse');
    gleich(array_column($strom['leitungen'] ?? [], 'name'), ['Erdkabel Halle'], 'strom.json enthält nur das fest verlegte Kabel – kein mobiles, keine Kabelbrücke');
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

    // Ein Zwischenstecker aus dem Inventar steckt am selben Abgang – nur hier,
    // ein Einsatz geht nicht ins Repository.
    $inventarId = (int) InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => 'PRUEF-LAGER-' . bin2hex(random_bytes(3)),
        'steckertyp_id' => (int) Database::scalar("SELECT id FROM steckertypen WHERE schluessel = 'cee125'"),
        'messgeraete' => [['geraet' => 'shellypro3em-lager-zs', 'kanal' => 'em:0', 'stelle' => 'durchgang']]])['id'];
    $einsatz = InventarService::einsetzen($inventarId, $an, (int) $ab['id']);
    pruefe($einsatz['ok'], 'Zwischenstecker am Hausanschluss eingesetzt: ' . $einsatz['message']);
    $einsatzId = (int) ($einsatz['einsatz_id'] ?? 0);
    $verweise = static fn (): array => [
        $stelle(Database::scalar("SELECT anschluss_id FROM messpunkte WHERE objekt_id = ? AND geraet = 'shellypro3em-lager-ha' AND einsatz_id IS NULL", [$an])),
        $stelle(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE objekt_id = ? AND einsatz_id = ?', [$an, $einsatzId])),
        $stelle(Database::scalar('SELECT anschluss_id FROM einsaetze WHERE id = ?', [$einsatzId])),
    ];
    gleich($verweise(), [$abStelle, $abStelle, $abStelle], 'Messgerät, Zwischenstecker und Einsatz am Abgang');

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
    gleich($verweise(), [$abStelle, $abStelle, $abStelle], 'nach dem Übernehmen: Messgerät, Zwischenstecker und Einsatz am neuen Abgang (gleiche Stelle)');
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
    gleich($verweise(), [$abStelle, $abStelle, $abStelle], 'nach dem zweiten Übernehmen: alle Verweise am selben Abgang');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte m LEFT JOIN anschluesse a ON a.id = m.anschluss_id
        WHERE m.objekt_id = ? AND m.anschluss_id IS NOT NULL AND a.id IS NULL', [$an]), 0, 'kein Messpunkt zeigt auf einen gelöschten Anschluss');

    // location.json ohne „dosen“ (Format 1, etwa von Hand gekürzt): die
    // bekannten Dosen bleiben – fehlend heißt „unbekannt“, leer heißt „keine“.
    $git($mo, 'pull -q origin main');
    $d = json_decode((string) file_get_contents($mo . '/locations/lager/location.json'), true);
    unset($d['etagen'][0]['dosen']);
    file_put_contents($mo . '/locations/lager/location.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $git($mo, 'commit -q -am "ohne Dosen"');
    $git($mo, 'push -q origin main');
    pruefe(LocationSync::uebernehmen('lager')['ok'], 'location.json ohne Dosen übernommen');
    $fremd = json_decode((string) Database::scalar('SELECT fremdbestand FROM floors WHERE id = ?', [$floorId]), true);
    gleich([count($fremd['dosen'] ?? []), $fremd['racks'][0]['watt'] ?? 0], [3, 165], 'ohne „dosen“ bleiben die Dosen, die Racks kommen wie gehabt');
    $d['etagen'][0]['dosen'] = [];
    file_put_contents($mo . '/locations/lager/location.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $git($mo, 'commit -q -am "keine Dosen"');
    $git($mo, 'push -q origin main');
    LocationSync::uebernehmen('lager');
    $fremd = json_decode((string) Database::scalar('SELECT fremdbestand FROM floors WHERE id = ?', [$floorId]), true);
    gleich(count($fremd['dosen'] ?? [1]), 0, 'leere Liste: keine Dosen mehr');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ? AND fest = 0', [$siteId]), 1, 'mobile Anlagen unverändert');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM leitungen WHERE floor_id = ? AND fest = 0', [$floorId]), 2, 'mobile Leitungen unverändert');

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

    // ------------------------------------------------ Prüfsumme der Plandatei
    $etage = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
    $planSha = (string) hash_file('sha256', (string) SiteService::planPath($etage));
    gleich(SiteService::planPruefsumme($etage), ['sha256' => $planSha, 'bytes' => (int) filesize((string) SiteService::planPath($etage))], 'Prüfsumme und Größe der Plandatei');
    pruefe((string) Database::scalar('SELECT plan_sha_marke FROM floors WHERE id = ?', [$floorId]) !== '', 'Prüfsumme mit Marke festgehalten');
    Database::update('floors', $floorId, ['plan_sha256' => str_repeat('a', 64)]);
    gleich(SiteService::planPruefsumme(Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]))['sha256'] ?? '', str_repeat('a', 64), 'solange die Marke stimmt, gilt der gespeicherte Wert');
    Database::update('floors', $floorId, ['plan_sha_marke' => 'veraltet']);
    gleich(SiteService::planPruefsumme(Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]))['sha256'] ?? '', $planSha, 'Marke passt nicht mehr: neu gerechnet');

    // ------------------------------------------------ Übertrag (Schnittstelle)
    // Derselbe Bestand, wie ihn eine andere Instanz liefert, über
    // LocationUebertrag: auf einen neuen Standort (B übernimmt), noch einmal
    // auf ihn (B übernimmt erneut) und auf den eigenen (A nimmt an).
    $marke = 'pm-api-' . bin2hex(random_bytes(3));
    $repo = LocationBundle::locationFuerRepo($siteId, null);
    $dortLoc = json_decode((string) json_encode($repo['daten']), true);
    $dortLoc['name'] = $marke . ' Lager dort';
    foreach ($dortLoc['etagen'] as &$e) {
        if ($e['schluessel'] === 'grundriss') {
            $e['dosen'] = [['label' => 'D1', 'raum' => '', 'x' => 5, 'y' => 5]];
        }
    }
    unset($e);
    $dortStrom = json_decode((string) json_encode(LocationBundle::stromLesen($siteId)), true);
    $dortStrom['georeferenz'] = ['grundriss' => ['a' => ['x' => 10.0, 'y' => 10.0, 'lat' => 52.5, 'lon' => 13.4],
        'b' => ['x' => 90.0, 'y' => 10.0, 'lat' => 52.5, 'lon' => 13.41], 'verhaeltnis' => 0.7, 'von' => 'pruefung', 'am' => '2026-10-01T00:00:00+00:00']];
    $aufloesen = static fn (string $pfad): ?string => $repo['dateien'][$pfad] ?? null;
    $festDort = count($dortStrom['anlagen']);
    $haKey = (string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [$an]);
    $wvKey = (string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [(int) $wv2['id']]);
    $nachB = ['teile' => ['grundriss', 'feststrom'], 'zuordnung' => 'partner', 'plan_praefix' => 'sync', 'fremdbestand' => true, 'neue_etagen' => true];

    $u = LocationUebertrag::anwenden(null, $dortLoc, $dortStrom, ['grundriss' => 0], $aufloesen, $nachB);
    pruefe($u['ok'], 'auf einen neuen Standort übertragen: ' . $u['message']);
    $kopieId = (int) $u['site_id'];
    $kopieSite = SiteService::find($kopieId);
    pruefe($kopieId !== $siteId && $kopieSite['name'] === $marke . ' Lager dort' && $kopieSite['sync_key'] !== 'lager'
        && str_starts_with((string) $kopieSite['sync_key'], 'lager-'), 'neuer Standort mit Namen von dort und freiem Schlüssel: ' . $kopieSite['sync_key']);
    $kEtagen = SiteService::floors($kopieId);
    gleich(count($kEtagen), 1, 'nur die gewählte Etage');
    $kEtage = $kEtagen[0];
    gleich($u['etagen'], ['grundriss' => (int) $kEtage['id']], 'Ergebnis nennt die Etage hier');
    pruefe(str_starts_with((string) $kEtage['plan_file'], 'sync' . $kEtage['id'] . '_') && hash_file('sha256', (string) SiteService::planPath($kEtage)) === $planSha,
        'Plan übernommen, gleicher Inhalt');
    gleich((string) $kEtage['plan_sha256'], $planSha, 'Prüfsumme beim Übernehmen festgehalten');
    gleich((float) $kEtage['massstab_m'], 40.0, 'Maßstab übernommen');
    pruefe(PlanGeoService::lesen($kEtage) !== null, 'Georeferenz übernommen');
    gleich(count(json_decode((string) $kEtage['fremdbestand'], true)['dosen'] ?? []), 1, 'Fremdbestand übernommen');
    gleich([$u['anlagen'], $u['leitungen']], [$festDort, count($dortStrom['leitungen'])], 'Feststromanschlüsse und feste Leitungen übertragen');
    $kAnlagen = Database::select('SELECT * FROM objekte WHERE site_id = ?', [$kopieId]);
    pruefe(count($kAnlagen) === $festDort && array_filter($kAnlagen, static fn ($o) => $o['sync_key'] !== '' || $o['partner_schluessel'] === '') === [],
        'neue Anlagen tragen den Schlüssel von dort als partner_schluessel, keinen eigenen sync_key');
    gleich([(int) AnlagenService::find($an)['site_id'], (int) AnlagenService::find((int) $wv2['id'])['site_id']], [$siteId, $siteId],
        'nichts wandert vom anderen Standort herüber (gleiche Schlüssel)');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte m JOIN objekte o ON o.id = m.objekt_id WHERE o.site_id = ?', [$kopieId]), 0, 'keine Messpunkte übertragen');
    $kHa = Database::first('SELECT * FROM objekte WHERE site_id = ? AND partner_schluessel = ?', [$kopieId, $haKey]);
    $kKabel = Database::first("SELECT * FROM leitungen WHERE floor_id = ? AND name = 'Erdkabel Halle'", [(int) $kEtage['id']]);
    pruefe($kHa !== null && $kKabel !== null && (int) $kKabel['fest'] === 1 && $kKabel['partner_schluessel'] !== '' && $kKabel['sync_key'] === ''
        && (int) $kKabel['von_objekt_id'] === (int) $kHa['id'], 'festes Kabel an den neuen Anlagen');

    $u2 = LocationUebertrag::anwenden($kopieId, $dortLoc, $dortStrom, ['grundriss' => (int) $kEtage['id']], $aufloesen, $nachB);
    gleich([$u2['ok'], $u2['plaene'], $u2['anlagen'], $u2['leitungen']], [true, $u['plaene'], $u['anlagen'], $u['leitungen']], 'noch einmal: dieselben Zahlen');
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ?', [$kopieId]),
        (int) Database::scalar('SELECT COUNT(*) FROM leitungen WHERE floor_id = ?', [(int) $kEtage['id']])],
        [$festDort, count($dortStrom['leitungen'])], 'noch einmal: nichts doppelt');

    // Hier geändert: Schaltsperre, ein Messpunkt, Namen. Dort fehlen Maßstab
    // und Georeferenz, die Anlage ist ungesperrt und verschoben.
    Database::update('objekte', (int) $kHa['id'], ['steuerung_gesperrt' => 1]);
    $kAb = Database::first("SELECT id, nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr LIMIT 1", [(int) $kHa['id']]);
    $kMess = (int) MesspunktService::speichern((int) $kHa['id'], null, ['geraet' => 'shellypro3em-' . $marke, 'kanal' => 'em:0', 'anschluss_id' => (int) $kAb['id']])['id'];
    SiteService::saveFloor((int) $kEtage['id'], ['site_id' => $kopieId, 'name' => 'Erdgeschoss hier', 'level_no' => 3, 'sort_order' => 3]);
    Database::update('sites', $kopieId, ['name' => $marke . ' Lager hier']);
    $dort2Loc = $dortLoc;
    foreach ($dort2Loc['etagen'] as &$e) {
        unset($e['referenz']);
    }
    unset($e);
    $dort2Strom = $dortStrom;
    unset($dort2Strom['georeferenz']);
    foreach ($dort2Strom['anlagen'] as &$a) {
        if ($a['schluessel'] === $haKey) {
            $a['steuerung_gesperrt'] = false;
            $a['x'] = 33.0;
        }
    }
    unset($a);
    $u3 = LocationUebertrag::anwenden($kopieId, $dort2Loc, $dort2Strom, ['grundriss' => (int) $kEtage['id']], $aufloesen, $nachB);
    pruefe($u3['ok'], 'mit Änderungen hier erneut übertragen: ' . $u3['message']);
    $kHa2 = AnlagenService::find((int) $kHa['id']);
    gleich([(int) $kHa2['steuerung_gesperrt'], (float) $kHa2['plan_x']], [1, 33.0], 'Schaltsperre hier bleibt, die Lage kommt von dort');
    $kMessNach = Database::first('SELECT m.id, a.richtung, a.nr FROM messpunkte m JOIN anschluesse a ON a.id = m.anschluss_id AND a.objekt_id = m.objekt_id WHERE m.id = ?', [$kMess]);
    pruefe($kMessNach !== null && $kMessNach['richtung'] . ':' . $kMessNach['nr'] === 'abgang:' . (int) $kAb['nr'], 'Messpunkt hier bleibt, am selben Abgang');
    $kEtage = Database::first('SELECT * FROM floors WHERE id = ?', [(int) $kEtage['id']]);
    gleich([$kEtage['name'], (int) $kEtage['level_no'], (string) Database::scalar('SELECT name FROM sites WHERE id = ?', [$kopieId])],
        ['Erdgeschoss hier', 3, $marke . ' Lager hier'], 'Namen und Ebene hier bleiben');
    pruefe((float) $kEtage['massstab_m'] === 40.0 && PlanGeoService::lesen($kEtage) !== null, 'fehlender Maßstab und fehlende Georeferenz dort löschen hier nichts');

    // Eine PHP-Datei mit Endung .svg wird kein Plan
    $falsch = $tmp . '/falsch.svg';
    file_put_contents($falsch, "<?php echo 'x';\n");
    $u4 = LocationUebertrag::anwenden($kopieId, $dortLoc, $dortStrom, ['grundriss' => (int) $kEtage['id']], static fn (): string => $falsch,
        ['teile' => ['grundriss']] + $nachB);
    pruefe($u4['ok'] && $u4['plaene'] === 0 && (string) Database::scalar('SELECT plan_file FROM floors WHERE id = ?', [(int) $kEtage['id']]) === (string) $kEtage['plan_file'],
        'Datei, die kein Plan ist, bleibt draußen – der Plan hier bleibt');

    // Alles oder nichts: scheitert der Feststrom, bleibt auch der Grundriss
    $dort3Loc = $dortLoc;
    foreach ($dort3Loc['etagen'] as &$e) {
        $e['massstab'] = 'sollte nicht ankommen';
    }
    unset($e);
    $u5 = LocationUebertrag::anwenden($kopieId, $dort3Loc, ['art' => 'falsch', 'anlagen' => []] + $dortStrom, ['grundriss' => (int) $kEtage['id']], $aufloesen, $nachB);
    pruefe(!$u5['ok'], 'kaputter Feststrom abgelehnt: ' . $u5['message']);
    gleich((string) Database::scalar('SELECT scale_note FROM floors WHERE id = ?', [(int) $kEtage['id']]), (string) $kEtage['scale_note'], 'und der Grundriss nicht halb übernommen');
    $u6 = LocationUebertrag::anwenden($kopieId, $dortLoc, $dortStrom, ['grundriss' => $floorId], $aufloesen, $nachB);
    pruefe(!$u6['ok'] && str_contains($u6['message'], 'gehört nicht'), 'Etage eines anderen Standorts abgelehnt: ' . $u6['message']);

    // A nimmt an: auf dem eigenen Standort, nur Feststrom, über die eigenen Schlüssel
    $kHaKey = LocationBundle::schluesselSichern('objekte', (int) $kHa['id'], 'Hausanschluss Lager', 'x');
    $vorschlag = $dortStrom;
    foreach ($vorschlag['anlagen'] as &$a) {
        if ($a['schluessel'] === $wvKey) {
            $a['x'] = 77.0;
        }
    }
    unset($a);
    $vorschlag['anlagen'][] = ['schluessel' => $kHaKey, 'art' => 'anschluss', 'fest' => true, 'name' => $marke . ' Neu', 'etage' => 'grundriss',
        'x' => 1, 'y' => 1, 'schutzorgane' => [], 'anschluesse' => []];
    $vorschlag['anlagen'][] = ['schluessel' => $marke . '-ohne-etage', 'art' => 'anschluss', 'fest' => true, 'name' => $marke . ' ohne Etage', 'etage' => null,
        'schutzorgane' => [], 'anschluesse' => []];
    $wvMess = array_column(Database::select('SELECT id FROM messpunkte WHERE objekt_id = ? ORDER BY id', [(int) $wv2['id']]), 'id');
    $planVorher = (string) Database::scalar('SELECT plan_file FROM floors WHERE id = ?', [$floorId]);
    $a1 = LocationUebertrag::anwenden($siteId, $dortLoc, $vorschlag, ['grundriss' => $floorId], $aufloesen,
        ['teile' => ['feststrom'], 'zuordnung' => 'standort', 'plan_praefix' => 'vorschlag', 'fremdbestand' => false, 'neue_etagen' => false]);
    pruefe($a1['ok'] && $a1['uebergangen'] >= 1, 'auf dem eigenen Standort angenommen: ' . $a1['message']);
    gleich((float) AnlagenService::find((int) $wv2['id'])['plan_x'], 77.0, 'Feststromanschluss verschoben');
    $neuHier = Database::first('SELECT * FROM objekte WHERE site_id = ? AND name = ?', [$siteId, $marke . ' Neu']);
    pruefe($neuHier !== null && $neuHier['sync_key'] !== $kHaKey && LocationBundle::schluesselGueltig((string) $neuHier['sync_key']),
        'neue Anlage mit freiem Schlüssel: ' . ($neuHier['sync_key'] ?? '–'));
    gleich((int) AnlagenService::find((int) $kHa['id'])['site_id'], $kopieId, 'die mit demselben Schlüssel auf dem anderen Standort bleibt dort');
    pruefe(Database::first('SELECT id FROM objekte WHERE name = ?', [$marke . ' ohne Etage']) === null, 'Anlage ohne Etage übergangen');
    gleich(array_column(Database::select('SELECT id FROM messpunkte WHERE objekt_id = ? ORDER BY id', [(int) $wv2['id']]), 'id'), $wvMess, 'Messpunkte bleiben, wie sie sind');
    gleich((string) Database::scalar('SELECT plan_file FROM floors WHERE id = ?', [$floorId]), $planVorher, 'ohne „grundriss“ bleibt der Plan');

    // Steckertypen, die es hier nicht gibt, und freie Schlüssel
    gleich(LocationUebertrag::fehlendeSteckertypen(['format' => 2, 'art' => 'strom', 'anlagen' => [['schluessel' => 'x', 'art' => 'anschluss', 'fest' => true,
        'anschluesse' => [['stecker' => 'CEE63'], ['stecker' => $marke . '-stecker'], ['stecker' => 'schuko']]]]]), [$marke . '-stecker'],
        'fehlende Steckertypen erkannt (CEE63 ist cee63)');
    $frei = LocationBundle::freierSchluessel('sites', 'Lager');
    pruefe(!in_array($frei, ['lager', (string) $kopieSite['sync_key']], true) && str_starts_with($frei, 'lager-'), 'freier Schlüssel neben vergebenen: ' . $frei);
    pruefe(strlen(LocationBundle::freierSchluessel('sites', str_repeat('Lager ', 20))) <= 64, 'freier Schlüssel höchstens 64 Zeichen');
    gleich(LocationBundle::freierSchluessel('floors', $marke . ' x', [$marke . '-x']), $marke . '-x-2', 'gerade vergebene Schlüssel zählen mit');
} finally {
    if ($inventarId !== null) {
        foreach (Database::select('SELECT id FROM einsaetze WHERE inventar_id = ? AND bis IS NULL', [$inventarId]) as $e) {
            InventarService::beenden((int) $e['id']);
        }
        InventarService::loeschen($inventarId);
    }
    if ($kopieId !== null && $kopieId > 0) {
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$kopieId]) as $o) {
            AnlagenService::loeschen((int) $o['id']);
        }
        foreach (Database::select('SELECT plan_file FROM floors WHERE site_id = ?', [$kopieId]) as $f) {
            if ($f['plan_file'] !== '') {
                @unlink(SiteService::planDir() . '/' . $f['plan_file']);
            }
        }
        SiteService::deleteSite($kopieId);
    }
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
