<?php
declare(strict_types=1);

/**
 * Verteiler-Abgleich gegen ein echtes Git-Repository: Standard-Verteiler und
 * Inventar hochladen (ein Stück nimmt seinen Typ mit), Änderungen einer
 * zweiten Installation erkennen und übernehmen, eigene Änderungen
 * hochladen, beide Seiten geändert, Shellys an einem eingesetzten Stück,
 * Rechte, Entfernen, Schlüssel als Pfad, Location-Abgleich lässt Typen in
 * Ruhe.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\LocationBundle;
use App\Services\LocationSync;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Services\VerteilerBundle;
use App\Services\VerteilerSync;
use App\Support\Config;
use App\Support\Database;
use App\Support\Programm;
use App\Support\Settings;

if (Programm::finden('git') === null) {
    uebersprungen('verteiler-abgleich', 'git fehlt');
}

$tmp = sys_get_temp_dir() . '/pm-verteiler-' . bin2hex(random_bytes(4));
$fern = $tmp . '/fern.git';
$andere = $tmp . '/andere';
$sh = static function (string $befehl): string {
    $aus = [];
    exec($befehl . ' 2>&1', $aus, $rc);
    if ($rc !== 0) {
        throw new RuntimeException($befehl . ': ' . implode("\n", $aus));
    }
    return implode("\n", $aus);
};
$git = static fn (string $wo, string $was): string => $sh('git -C ' . escapeshellarg($wo) . ' -c user.name=Andere -c user.email=andere@local ' . $was);
$dortDatei = static fn (string $pfad): ?array => json_decode((string) @shell_exec('git --git-dir=' . escapeshellarg($fern) . ' show main:' . escapeshellarg($pfad) . ' 2>/dev/null'), true);

// Arbeitskopie, Einstellungen und Abgleich-Vermerke der Installation beiseitelegen
$kopie = LocationSync::arbeitsKopie();
$beiseite = is_dir(dirname($kopie)) ? dirname($kopie) . '.pruefung-' . bin2hex(random_bytes(3)) : null;
if ($beiseite !== null) {
    rename(dirname($kopie), $beiseite);
}
$alteUrl = Settings::get('inventory.repo_url');
$alterZweig = Settings::get('inventory.branch');
$vermerkeTypen = Database::select('SELECT id, sync_key, synced_hash, synced_at FROM objekte WHERE ist_vorlage = 1');
$vermerkeInventar = Database::select('SELECT id, sync_key, synced_hash, synced_at FROM inventar');

AnlagenService::grundbestand();
$stecker = [];
foreach (AnlagenService::steckertypen() as $t) {
    $stecker[(string) $t['schluessel']] = (int) $t['id'];
}
$k = 'pv' . bin2hex(random_bytes(3));
$K = strtoupper($k);
$siteId = null;
$objektId = null;

$eintrag = static function (array $liste, string $bereich, string $schluessel): ?array {
    foreach ($liste[$bereich] as $e) {
        if ($e['schluessel'] === $schluessel) {
            return $e;
        }
    }
    return null;
};
$status = static function (string $bereich, string $schluessel) use ($eintrag): string {
    LocationSync::holen();
    return (string) ($eintrag(VerteilerSync::liste(VerteilerSync::dort()), $bereich, $schluessel)['status'] ?? 'fehlt');
};
$andereAendern = static function (string $pfad, callable $aendern, string $nachricht) use ($git, $andere): void {
    $git($andere, 'pull -q origin main');
    $datei = $andere . '/' . $pfad;
    $daten = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
    $daten = $aendern($daten);
    if (!is_dir(dirname($datei))) {
        mkdir(dirname($datei), 0775, true);
    }
    file_put_contents($datei, json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $git($andere, 'add -A');
    $git($andere, 'commit -q -m ' . escapeshellarg($nachricht));
    $git($andere, 'push -q origin main');
};

try {
    // ------------------------------------------------ Bestand hier
    $typId = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $k . ' Foodtruck-Typ', 'modell' => $k . '-PD63',
        'hersteller' => 'Prüfbau', 'nennstrom_a' => 63, 'ist_vorlage' => 1, 'ohne_anschluesse' => true,
        'eigenschaften' => ['schutzart' => 'IP44', 'fi_typ' => 'A']])['id'];
    AnlagenService::anzahlSetzen($typId, ['eingang' => [$stecker['cee63'] => 1], 'abgang' => [$stecker['cee32'] => 2, $stecker['cee16'] => 3]]);
    SchutzService::vorschlagen($typId);
    $typSchluessel = VerteilerBundle::typSchluessel($typId);
    gleich($typSchluessel, $k . '-foodtruck-typ', 'Schlüssel des Typs aus seinem Namen');

    $stellen = array_keys(InventarService::stellen(['art' => 'verteilung', 'vorlage_id' => $typId]));
    $abgang = array_values(array_filter($stellen, static fn ($s) => str_starts_with($s, 'abgang:')))[0];
    $v = InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $K . '-V1', 'name' => 'Foodtruck', 'vorlage_id' => $typId,
        'messgeraete' => [
            ['geraet' => 'shellypro3em-' . $k, 'kanal' => 'em:0', 'stelle' => 'einspeisung'],
            ['geraet' => 'shellypro1pm-' . $k, 'kanal' => 'switch:0', 'stelle' => $abgang, 'bezeichnung' => 'Kühlung'],
        ]]);
    pruefe($v['ok'], 'Verteiler angelegt: ' . $v['message']);
    $vId = (int) $v['id'];
    $z = InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => $K . '-Z1', 'steckertyp_id' => $stecker['cee63'],
        'messgeraete' => [['geraet' => 'shellypro3em-' . $k . '-z', 'kanal' => 'em:0', 'stelle' => 'durchgang']]]);
    $zId = (int) $z['id'];
    $vKey = VerteilerBundle::stueckSchluessel($vId);
    $zKey = VerteilerBundle::stueckSchluessel($zId);
    gleich([$vKey, $zKey], [$k . '-v1', $k . '-z1'], 'Schlüssel der Stücke aus ihrer Inventarnummer');

    $ohne = VerteilerSync::liste(null);
    gleich($eintrag($ohne, 'inventar', $vKey)['status'] ?? '', 'nie', 'ohne Repository: noch nicht abgeglichen');

    // ------------------------------------------------ Repository
    mkdir($tmp, 0775, true);
    $sh('git init -q --bare -b main ' . escapeshellarg($fern));
    Settings::put('inventory.repo_url', 'file://' . $fern);
    Settings::put('inventory.branch', 'main');
    Config::set('inventory.repo_url', 'file://' . $fern);
    pruefe(LocationSync::hindernis() === null, 'ohne Token bereit (lokales Repository)');

    // Ein Stück hochladen: sein Typ geht mit, das leere Repository bekommt sein Gerüst
    $hoch = VerteilerSync::hochladen([['inventar', $vKey]], 'pruefung');
    pruefe($hoch['ok'], 'Verteiler hochgeladen: ' . $hoch['message']);
    $vDatei = $dortDatei('verteiler/inventar/' . $vKey . '.json');
    $tDatei = $dortDatei('verteiler/typen/' . $typSchluessel . '.json');
    pruefe($vDatei !== null && $tDatei !== null, 'Datei des Stücks und seines Typs im Repository');
    gleich($vDatei['stueck']['typ'] ?? null, $typSchluessel, 'Stück verweist über den Schlüssel auf seinen Typ');
    gleich(count($vDatei['stueck']['messgeraete'] ?? []), 2, 'Shellys des Stücks in der Datei');
    gleich(array_column($vDatei['stueck']['messgeraete'] ?? [], 'stelle'), ['einspeisung', $abgang], 'Stellen der Shellys');
    gleich(count($tDatei['typ']['anschluesse'] ?? []), 6, 'Anschlüsse des Typs in der Datei');
    pruefe(count($tDatei['typ']['schutzorgane'] ?? []) >= 2 && ($tDatei['typ']['eigenschaften']['schutzart'] ?? '') === 'IP44', 'Schutzorgane und Eigenschaften des Typs');
    pruefe(in_array('cee63', array_column($tDatei['steckertypen'] ?? [], 'schluessel'), true), 'Steckertypen des Typs dabei');
    pruefe($dortDatei('verteiler/inventar/' . $zKey . '.json') === null, 'Zwischenstecker nicht mitgeschickt');
    $readme = (string) @shell_exec('git --git-dir=' . escapeshellarg($fern) . ' show main:README.md 2>/dev/null');
    pruefe(str_contains($readme, 'verteiler/'), 'Gerüst des Repositories beschreibt den Ordner verteiler/');

    gleich($status('inventar', $vKey), 'aktuell', 'hochgeladenes Stück aktuell');
    gleich($status('typ', $typSchluessel), 'aktuell', 'mitgeschickter Typ aktuell');
    gleich($status('inventar', $zKey), 'nur_hier', 'Zwischenstecker nur hier');

    $alle = VerteilerSync::hochladen(null, 'pruefung');
    pruefe($alle['ok'], 'alle hochgeladen: ' . $alle['message']);
    gleich($status('inventar', $zKey), 'aktuell', 'Zwischenstecker nach „Alle hochladen“ aktuell');
    $nochmal = VerteilerSync::hochladen([['inventar', $vKey]], 'pruefung');
    pruefe($nochmal['ok'] && str_contains($nochmal['message'], 'nichts zu tun'), 'unverändertes Stück erzeugt keinen Commit');

    // ------------------------------------------------ Eine zweite Installation ändert
    $sh('git clone -q ' . escapeshellarg($fern) . ' ' . escapeshellarg($andere));
    $andereAendern('verteiler/inventar/' . $vKey . '.json', static function (array $d) use ($k): array {
        $d['stueck']['name'] = 'Foodtruck Nord';
        $d['stueck']['messgeraete'][1]['geraet'] = 'shellypro1pm-' . $k . '-neu';
        $d['von'] = 'PowerManagement Nord';
        return $d;
    }, 'Shelly getauscht');
    $andereAendern('verteiler/typen/' . $typSchluessel . '.json', static function (array $d) use ($k): array {
        $d['typ']['name'] = $k . ' Foodtruck-Typ XL';
        $d['typ']['nennstrom_a'] = 80;
        return $d;
    }, 'Typ umbenannt');
    // Neuer Typ und ein Stück davon – nur dort
    $fremdTyp = $k . '-fremdtyp';
    $andereAendern('verteiler/typen/' . $fremdTyp . '.json', static fn (): array => [
        'format' => 1, 'art' => 'verteiler-typ', 'schluessel' => $fremdTyp, 'stand' => gmdate('c'), 'von' => 'PowerManagement Nord',
        'typ' => ['art' => 'verteilung', 'fest' => false, 'name' => $k . ' Fremdtyp', 'hersteller' => '', 'modell' => $k . '-FT',
            'nennleistung_kva' => null, 'nennstrom_a' => 32, 'spannung_v' => 400, 'phasen' => 3, 'eigenschaften' => [],
            'steuerung_gesperrt' => false, 'notizen' => '',
            'schutzorgane' => [['ref' => 's1', 'art' => 'rcd', 'bezeichnung' => 'F1', 'nennstrom_a' => 40, 'charakteristik' => '', 'fehlerstrom_ma' => 30,
                'rcd_typ' => 'A', 'pole' => 4, 'vor' => null, 'notizen' => '']],
            'anschluesse' => [
                ['richtung' => 'eingang', 'stecker' => 'cee32', 'nr' => 1, 'bezeichnung' => 'X0', 'schutz' => null, 'phase' => '', 'notizen' => ''],
                ['richtung' => 'abgang', 'stecker' => 'cee16', 'nr' => 1, 'bezeichnung' => 'X1', 'schutz' => 's1', 'phase' => '', 'notizen' => ''],
                ['richtung' => 'abgang', 'stecker' => 'schuko', 'nr' => 2, 'bezeichnung' => 'X2', 'schutz' => 's1', 'phase' => 'L2', 'notizen' => ''],
            ]],
        'steckertypen' => [],
    ], 'Neuer Typ');
    $nKey = $k . '-n1';
    $andereAendern('verteiler/inventar/' . $nKey . '.json', static fn (): array => [
        'format' => 1, 'art' => 'verteiler-inventar', 'schluessel' => $nKey, 'stand' => gmdate('c'), 'von' => 'PowerManagement Nord',
        'stueck' => ['art' => 'verteilung', 'kennung' => $K . '-N1', 'name' => '', 'typ' => $fremdTyp, 'stecker' => null,
            'seriennummer' => 'SN-1', 'defekt' => false, 'notizen' => '',
            'messgeraete' => [['geraet' => 'shellyplus1pm-' . $k, 'kanal' => 'switch:0', 'stelle' => 'abgang:2', 'bezeichnung' => '']]],
        'steckertypen' => [],
    ], 'Neues Stück');

    // Hier ändert sich nur der Zwischenstecker
    InventarService::speichern($zId, ['art' => 'zwischenstecker', 'kennung' => $K . '-Z1', 'steckertyp_id' => $stecker['cee63'], 'defekt' => 1,
        'messgeraete' => [['geraet' => 'shellypro3em-' . $k . '-z', 'kanal' => 'em:0', 'stelle' => 'durchgang']]]);

    LocationSync::holen();
    $liste = VerteilerSync::liste(VerteilerSync::dort());
    $ev = $eintrag($liste, 'inventar', $vKey);
    gleich($ev['status'] ?? '', 'dort_neuer', 'Änderung der anderen Installation erkannt');
    pruefe(in_array('Name', $ev['unterschiede'] ?? [], true) && in_array('Shellys', $ev['unterschiede'] ?? [], true), 'Unterschiede benannt: ' . implode(', ', $ev['unterschiede'] ?? []));
    gleich($ev['dort_von'] ?? '', 'PowerManagement Nord', 'Absender der Änderung');
    gleich($eintrag($liste, 'typ', $typSchluessel)['status'] ?? '', 'dort_neuer', 'umbenannter Typ dort neuer');
    gleich($eintrag($liste, 'typ', $fremdTyp)['status'] ?? '', 'neu', 'neuer Typ nur im Repository');
    gleich($eintrag($liste, 'inventar', $nKey)['status'] ?? '', 'neu', 'neues Stück nur im Repository');
    gleich($eintrag($liste, 'inventar', $zKey)['status'] ?? '', 'hier_neuer', 'hier geänderter Zwischenstecker');
    pruefe($liste['uebernehmen'] >= 4 && $liste['hochladen'] >= 1, 'Zähler für „Alle übernehmen“ und „Alle hochladen“');

    // Alle übernehmen: nimmt das Neue und Neuere, lässt den hier geänderten Zwischenstecker
    $ueber = VerteilerSync::uebernehmen(null, ['typ' => true, 'inventar' => true]);
    pruefe($ueber['ok'], 'alle übernommen: ' . $ueber['message']);
    $vNeu = InventarService::find($vId);
    gleich($vNeu['name'], 'Foodtruck Nord', 'Name aus dem Repository');
    gleich(array_column(InventarService::messgeraete($vId), 'geraet'), ['shellypro3em-' . $k, 'shellypro1pm-' . $k . '-neu'], 'getauschter Shelly übernommen');
    $typNeu = AnlagenService::find($typId);
    gleich([$typNeu['name'], (float) $typNeu['nennstrom_a'], (int) $typNeu['ist_vorlage']], [$k . ' Foodtruck-Typ XL', 80.0, 1], 'Typ umbenannt, bleibt Katalog-Typ');
    $fremd = Database::first('SELECT * FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$fremdTyp]);
    pruefe($fremd !== null && $fremd['site_id'] === null, 'neuer Typ im Katalog');
    gleich(count(AnlagenService::anschluesse((int) $fremd['id'])), 3, 'Anschlüsse des neuen Typs');
    $fremdSchutz = SchutzService::liste((int) $fremd['id']);
    gleich([count($fremdSchutz), (int) ($fremdSchutz[0]['fehlerstrom_ma'] ?? 0)], [1, 30], 'Schutzorgan des neuen Typs');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND schutzorgan_id IS NULL", [(int) $fremd['id']]), 0, 'Abgänge hängen am RCD');
    $n = Database::first('SELECT * FROM inventar WHERE sync_key = ?', [$nKey]);
    pruefe($n !== null && (int) $n['vorlage_id'] === (int) $fremd['id'] && $n['kennung'] === $K . '-N1', 'neues Stück mit seinem Typ');
    gleich(array_column(InventarService::messgeraete((int) $n['id']), 'stelle'), ['abgang:2'], 'Shelly am Abgang des neuen Typs');
    gleich((int) InventarService::find($zId)['defekt'], 1, 'hier geänderter Zwischenstecker bleibt, wie er hier ist');

    // Genau abgebildet: nach dem Übernehmen gilt alles als aktuell
    LocationSync::holen();
    $liste = VerteilerSync::liste(VerteilerSync::dort());
    foreach ([['inventar', $vKey], ['inventar', $nKey], ['typ', $typSchluessel], ['typ', $fremdTyp]] as [$b, $s]) {
        gleich($eintrag($liste, $b, $s)['status'] ?? '', 'aktuell', 'nach dem Übernehmen aktuell: ' . $s);
    }

    $alle = VerteilerSync::hochladen(null, 'pruefung');
    pruefe($alle['ok'] && str_contains($alle['message'], '1 Inventarstück'), 'hier Geändertes hochgeladen: ' . $alle['message']);
    gleich($dortDatei('verteiler/inventar/' . $zKey . '.json')['stueck']['defekt'] ?? null, true, 'defekt im Repository');

    // ------------------------------------------------ Beide Seiten geändert
    InventarService::speichern($vId, ['art' => 'verteilung', 'kennung' => $K . '-V1', 'name' => 'Foodtruck Nord', 'vorlage_id' => $typId, 'notizen' => 'hier',
        'messgeraete' => InventarService::messgeraete($vId)]);
    $andereAendern('verteiler/inventar/' . $vKey . '.json', static function (array $d): array {
        $d['stueck']['seriennummer'] = 'SN-dort';
        return $d;
    }, 'Seriennummer');
    gleich($status('inventar', $vKey), 'beide', 'hier und dort geändert');
    VerteilerSync::uebernehmen(null, ['typ' => true, 'inventar' => true]);
    VerteilerSync::hochladen(null, 'pruefung');
    gleich((string) InventarService::find($vId)['notizen'], 'hier', '„Alle übernehmen“ überschreibt keine Änderung hier');
    gleich($dortDatei('verteiler/inventar/' . $vKey . '.json')['stueck']['seriennummer'] ?? '', 'SN-dort', '„Alle hochladen“ überschreibt keine Änderung dort');
    $entscheid = VerteilerSync::hochladen([['inventar', $vKey]], 'pruefung');
    pruefe($entscheid['ok'], 'ausdrücklich hochgeladen: ' . $entscheid['message']);
    $vDatei = $dortDatei('verteiler/inventar/' . $vKey . '.json');
    gleich([$vDatei['stueck']['notizen'] ?? '', $vDatei['stueck']['seriennummer'] ?? ''], ['hier', ''], 'Stand von hier gilt');
    gleich($status('inventar', $vKey), 'aktuell', 'danach aktuell');

    // ------------------------------------------------ Shelly getauscht an einem eingesetzten Stück
    $siteId = SiteService::saveSite(null, ['name' => 'Prüfung Verteiler ' . $k]);
    $floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Halle']);
    $objektId = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $k . ' Foodtruck-Verteiler', 'floor_id' => $floorId, 'vorlage_id' => $typId])['id'];
    $ein = InventarService::einsetzen($vId, $objektId);
    pruefe($ein['ok'], 'Stück eingesetzt: ' . $ein['message']);
    $andereAendern('verteiler/inventar/' . $vKey . '.json', static function (array $d) use ($k): array {
        $d['stueck']['messgeraete'][0]['geraet'] = 'shellypro3em-' . $k . '-tausch';
        return $d;
    }, 'Shelly an der Einspeisung getauscht');
    $ueber = VerteilerSync::uebernehmen([['inventar', $vKey]], ['typ' => true, 'inventar' => true]);
    pruefe($ueber['ok'], 'Tausch übernommen: ' . $ueber['message']);
    $aktiv = array_column(Database::select('SELECT geraet FROM messpunkte WHERE objekt_id = ? AND gueltig_bis IS NULL ORDER BY id', [$objektId]), 'geraet');
    pruefe(in_array('shellypro3em-' . $k . '-tausch', $aktiv, true) && !in_array('shellypro3em-' . $k, $aktiv, true), 'an der Anlage misst jetzt der neue Shelly');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte WHERE objekt_id = ? AND geraet = ? AND gueltig_bis IS NOT NULL', [$objektId, 'shellypro3em-' . $k]), 1,
        'der alte Shelly bleibt als früherer Messpunkt');

    // ------------------------------------------------ Ein Shelly, der hier schon in einem anderen Stück steckt
    $andereAendern('verteiler/inventar/' . $nKey . '.json', static function (array $d) use ($k): array {
        $d['stueck']['messgeraete'][0] = ['geraet' => 'shellypro3em-' . $k . '-z', 'kanal' => 'em:0', 'stelle' => 'einspeisung', 'bezeichnung' => ''];
        return $d;
    }, 'Shelly doppelt');
    $ueber = VerteilerSync::uebernehmen([['inventar', $nKey]], ['typ' => true, 'inventar' => true]);
    pruefe(!$ueber['ok'] && str_contains($ueber['message'], 'steckt schon in ' . $K . '-Z1'), 'doppelter Shelly abgelehnt: ' . $ueber['message']);
    gleich(array_column(InventarService::messgeraete((int) $n['id']), 'geraet'), ['shellyplus1pm-' . $k], 'Stück bleibt, wie es war');
    gleich($status('inventar', $nKey), 'dort_neuer', 'und bleibt „dort neuer“');

    // ------------------------------------------------ Rechte
    $andereAendern('verteiler/typen/' . $fremdTyp . '.json', static function (array $d): array {
        $d['typ']['notizen'] = 'geändert';
        return $d;
    }, 'Notiz');
    $ohneRecht = VerteilerSync::uebernehmen(null, ['typ' => false, 'inventar' => false]);
    pruefe(!$ohneRecht['ok'] && str_contains($ohneRecht['message'], 'Katalog pflegen darfst du nicht'), 'ohne Recht nichts übernommen: ' . $ohneRecht['message']);
    gleich((string) Database::scalar('SELECT notizen FROM objekte WHERE id = ?', [(int) $fremd['id']]), '', 'Typ unverändert');

    // ------------------------------------------------ Entfernen
    $weg = VerteilerSync::entfernen('inventar', $zKey, 'pruefung');
    pruefe($weg['ok'], 'aus dem Repository entfernt: ' . $weg['message']);
    pruefe($dortDatei('verteiler/inventar/' . $zKey . '.json') === null, 'Datei im Repository gelöscht');
    pruefe(InventarService::find($zId) !== null, 'hier bleibt das Stück');
    gleich($status('inventar', $zKey), 'dort_entfernt', 'Status „dort entfernt“');
    VerteilerSync::hochladen(null, 'pruefung');
    pruefe($dortDatei('verteiler/inventar/' . $zKey . '.json') === null, '„Alle hochladen“ holt Entferntes nicht zurück');

    // ------------------------------------------------ Kaputte und fremde Dateien, Schlüssel als Pfad
    $andereAendern('verteiler/inventar/' . $k . '-zukunft.json', static fn (): array => [
        'format' => 99, 'art' => 'verteiler-inventar', 'schluessel' => $k . '-zukunft', 'stueck' => ['kennung' => $K . '-ZUKUNFT'],
    ], 'Aus der Zukunft');
    gleich($status('inventar', $k . '-zukunft'), 'fehler', 'Datei aus einer neueren Fassung wird nicht gelesen');
    $zukunft = VerteilerSync::uebernehmen([['inventar', $k . '-zukunft']], ['typ' => true, 'inventar' => true]);
    pruefe(!$zukunft['ok'] && str_contains($zukunft['message'], 'neueren'), 'und nicht übernommen: ' . $zukunft['message']);
    $vorherStecker = (int) Database::scalar('SELECT COUNT(*) FROM steckertypen');
    $gesichert = LocationBundle::steckertypenSichern([['schluessel' => 'CEE63', 'name' => 'CEE 63 A (von Hand)', 'strom_a' => 63]]);
    gleich([$gesichert['CEE63'] ?? 0, (int) Database::scalar('SELECT COUNT(*) FROM steckertypen')], [$stecker['cee63'], $vorherStecker],
        'Steckertyp „CEE63“ aus einer Datei ist der vorhandene „cee63“');
    gleich(VerteilerSync::pfad('inventar', '../../etc/passwd'), null, 'Pfad als Schlüssel abgelehnt');
    gleich(VerteilerSync::pfad('inventar', 'PD63-07'), null, 'nur normalisierte Schlüssel');
    gleich(VerteilerSync::pfad('location', 'x'), null, 'nur bekannte Bereiche');
    pruefe(!VerteilerSync::entfernen('inventar', '../README', 'pruefung')['ok'], 'Entfernen mit Pfad abgelehnt');

    // ------------------------------------------------ Anderes Repository eingetragen
    $zweites = $tmp . '/zweites.git';
    $sh('git init -q --bare -b main ' . escapeshellarg($zweites));
    Settings::put('inventory.repo_url', 'file://' . $zweites);
    Config::set('inventory.repo_url', 'file://' . $zweites);
    $holen = LocationSync::holen();
    pruefe($holen['ok'], 'neues Repository geholt: ' . $holen['message']);
    gleich(trim($sh('git -C ' . escapeshellarg($kopie) . ' remote get-url origin')), 'file://' . $zweites, 'Arbeitskopie zeigt auf das neue Repository');
    $hoch = VerteilerSync::hochladen([['inventar', $vKey]], 'pruefung');
    pruefe($hoch['ok'], 'ins neue Repository hochgeladen: ' . $hoch['message']);
    gleich((int) trim($sh('git --git-dir=' . escapeshellarg($zweites) . ' rev-list --count main')), 1, 'das neue Repository bekommt nicht die Geschichte des alten');
    pruefe(trim($sh('git --git-dir=' . escapeshellarg($zweites) . ' ls-tree -r --name-only main -- verteiler/inventar')) === 'verteiler/inventar/' . $vKey . '.json',
        'im neuen Repository nur das hochgeladene Stück');
    Settings::put('inventory.repo_url', 'file://' . $fern);
    Config::set('inventory.repo_url', 'file://' . $fern);

    // ------------------------------------------------ Location-Abgleich lässt Katalog-Typen in Ruhe
    $strom = LocationBundle::stromAnwenden($siteId, ['format' => 2, 'art' => 'strom', 'anlagen' => [[
        'schluessel' => $typSchluessel, 'art' => 'anschluss', 'fest' => true, 'name' => $k . ' Säule', 'etage' => null,
        'schutzorgane' => [], 'anschluesse' => [],
    ]]]);
    pruefe($strom['ok'], 'strom.json mit gleichem Schlüssel angewandt');
    $typDanach = AnlagenService::find($typId);
    gleich([(int) $typDanach['ist_vorlage'], $typDanach['name'], $typDanach['site_id']], [1, $k . ' Foodtruck-Typ XL', null], 'Katalog-Typ unverändert');
    pruefe(Database::first('SELECT id FROM objekte WHERE site_id = ? AND name = ? AND ist_vorlage = 0', [$siteId, $k . ' Säule']) !== null, 'Feststromanschluss daneben angelegt');
} finally {
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$K . '-%']) as $i) {
        foreach (Database::select('SELECT id FROM einsaetze WHERE inventar_id = ? AND bis IS NULL', [(int) $i['id']]) as $e) {
            InventarService::beenden((int) $e['id']);
        }
        InventarService::loeschen((int) $i['id']);
    }
    if ($siteId !== null) {
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$siteId]) as $o) {
            AnlagenService::loeschen((int) $o['id']);
        }
        SiteService::deleteSite($siteId);
    }
    foreach (Database::select('SELECT id FROM objekte WHERE ist_vorlage = 1 AND name LIKE ?', [$k . ' %']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    // Die Abgleich-Vermerke des übrigen Bestands wie vorher
    foreach ($vermerkeTypen as $r) {
        Database::execute('UPDATE objekte SET sync_key = ?, synced_hash = ?, synced_at = ? WHERE id = ?', [$r['sync_key'], $r['synced_hash'], $r['synced_at'], $r['id']]);
    }
    foreach ($vermerkeInventar as $r) {
        Database::execute('UPDATE inventar SET sync_key = ?, synced_hash = ?, synced_at = ? WHERE id = ?', [$r['sync_key'], $r['synced_hash'], $r['synced_at'], $r['id']]);
    }
    $alteUrl === null ? Settings::forget('inventory.repo_url') : Settings::put('inventory.repo_url', $alteUrl);
    $alterZweig === null ? Settings::forget('inventory.branch') : Settings::put('inventory.branch', $alterZweig);
    exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg(dirname($kopie)));
    if ($beiseite !== null) {
        rename($beiseite, dirname($kopie));
    }
}

ende('verteiler-abgleich');
