<?php
declare(strict_types=1);

/**
 * Aufbau-Modus: Plan festhalten, Positionen bestätigen, fertig, beenden –
 * und der Abgleich Planung ↔ Umsetzung.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\BenutzerService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\MesspunktService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Database;

AnlagenService::grundbestand();
$typ = [];
foreach (AnlagenService::steckertypen() as $t) {
    $typ[(string) $t['schluessel']] = (int) $t['id'];
}
$kennung = 'pm-auf-' . bin2hex(random_bytes(3));
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Aufbau ' . $kennung]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Wiese']);
// quadratischer Plan, 1 % = 2 m
Database::update('floors', $floorId, ['massstab_ax' => 0.0, 'massstab_ay' => 50.0, 'massstab_bx' => 50.0, 'massstab_by' => 50.0,
    'massstab_m' => 100.0, 'massstab_verhaeltnis' => 1.0, 'massstab_von' => 'test', 'massstab_am' => gmdate('c')]);
$anderer = SiteService::saveSite(null, ['name' => 'Prüfung anderer ' . $kennung]);
$benutzer = BenutzerService::anlegen($kennung, 'Pruefung2026!!', 'admin', false);
$angelegt = [];

try {
    alsBenutzer($benutzer);
    $vorlage = static function (string $name, int $strom, array $ein, array $ab) use (&$angelegt, $typ, $kennung): int {
        $id = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' ' . $name, 'modell' => $kennung . ' ' . $name,
            'nennstrom_a' => $strom, 'ist_vorlage' => 1, 'ohne_anschluesse' => true])['id'];
        $angelegt[] = $id;
        AnlagenService::anzahlSetzen($id, [
            'eingang' => array_combine(array_map(static fn ($k) => $typ[$k], array_keys($ein)), array_values($ein)),
            'abgang'  => array_combine(array_map(static fn ($k) => $typ[$k], array_keys($ab)), array_values($ab)),
        ]);
        SchutzService::vorschlagen($id);
        return $id;
    };
    $pd63 = $vorlage('PD63', 63, ['cee63' => 1], ['cee32' => 2]);
    $pd32 = $vorlage('PD32', 32, ['cee32' => 1], ['schuko' => 2]);
    $neu = static function (array $d) use ($floorId, &$angelegt): int {
        $id = (int) AnlagenService::speichern(null, $d + ['floor_id' => $floorId])['id'];
        $angelegt[] = $id;
        return $id;
    };
    $saeule = $neu(['art' => 'anschluss', 'name' => $kennung . ' Säule', 'fest' => 1, 'plan_x' => 10.0, 'plan_y' => 10.0, 'ohne_anschluesse' => true]);
    $a = $neu(['art' => 'verteilung', 'name' => $kennung . ' A', 'vorlage_id' => $pd63, 'plan_x' => 20.0, 'plan_y' => 20.0]);
    $b = $neu(['art' => 'verteilung', 'name' => $kennung . ' B', 'vorlage_id' => $pd32, 'plan_x' => 40.0, 'plan_y' => 20.0]);
    $c = $neu(['art' => 'verteilung', 'name' => $kennung . ' C', 'vorlage_id' => $pd32, 'plan_x' => 60.0, 'plan_y' => 20.0]);
    $leitung = static function (array $d) use ($floorId, $kennung): int {
        $e = LeitungService::speichern(null, $d + ['floor_id' => $floorId, 'name' => $kennung . ' ' . ($d['name'] ?? '')]);
        pruefe($e['ok'], 'Leitung angelegt: ' . $e['message']);
        return (int) ($e['id'] ?? 0);
    };
    $abgang = static fn (int $o, string $st): int => (int) Database::scalar('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr LIMIT 1', [$o, 'abgang', $typ[$st]]);
    $eingang = static fn (int $o): int => (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang'", [$o]);
    $kAB = $leitung(['art' => 'kabel', 'name' => 'A-B', 'von_objekt_id' => $a, 'von_anschluss_id' => $abgang($a, 'cee32'), 'zu_objekt_id' => $b,
        'zu_anschluss_id' => $eingang($b), 'kabeltyp' => 'H07RN-F', 'adern' => 5, 'querschnitt_mm2' => 6, 'punkte' => [[20.0, 20.0], [40.0, 20.0]]]);   // 40 m
    $kAC = $leitung(['art' => 'kabel', 'name' => 'A-C', 'von_objekt_id' => $a, 'zu_objekt_id' => $c, 'kabeltyp' => 'H07RN-F', 'adern' => 5,
        'querschnitt_mm2' => 6, 'punkte' => [[20.0, 20.0], [20.0, 30.0], [60.0, 30.0], [60.0, 20.0]]]);                                              // 100 m
    $bruecke = $leitung(['art' => 'kabelbruecke', 'name' => 'Brücke', 'modell' => 'Midi', 'anzahl' => 2, 'punkte' => [[30.0, 19.0], [30.0, 21.0]]]);
    $leitung(['art' => 'kabelweg', 'name' => 'Trasse', 'punkte' => [[10.0, 50.0], [90.0, 50.0]]]);

    // ------------------------------------------------ Starten
    pruefe(!AufbauService::starten($siteId + 999999, 'x')['ok'], 'unbekannter Standort abgelehnt');
    $e = AufbauService::starten($siteId, $kennung . ' Fest');
    pruefe($e['ok'], 'Aufbau gestartet: ' . $e['message']);
    $id = (int) $e['id'];
    pruefe(!AufbauService::starten($siteId, 'zweiter')['ok'], 'nur ein laufender Aufbau je Standort');
    $aufbau = AufbauService::find($id);
    gleich(array_map(static fn ($o) => $o['name'], $aufbau['plan']['objekte']), [$kennung . ' A', $kennung . ' B', $kennung . ' C'], 'Plan: nur mobile Anlagen');
    gleich(array_map(static fn ($l) => $l['art'], $aufbau['plan']['leitungen']), ['kabel', 'kabel', 'kabelbruecke'], 'Plan: mobile Kabel und Kabelbrücken, kein Kabelweg');
    pruefe(isset($aufbau['plan']['material']['kabel']) && $aufbau['plan']['material']['kabel'] !== [], 'Plan hält die Materialliste fest');
    gleich(AufbauService::aktiv($siteId)['id'] ?? null, $id, 'läuft auf dem Standort');
    $u = AufbauService::uebersicht($aufbau);
    gleich($u['zaehler']['offen'], 6, 'alle sechs Positionen offen');

    // ------------------------------------------------ Bestätigen
    pruefe(!AufbauService::postenSetzen($id, 'objekt', $saeule, 'bestaetigt')['ok'], 'Feststromanschluss gehört nicht zum Aufbau');
    $fremd = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' fremd', 'site_id' => $anderer])['id'];
    $angelegt[] = $fremd;
    pruefe(!AufbauService::postenSetzen($id, 'objekt', $fremd, 'bestaetigt')['ok'], 'Anlage eines anderen Standorts abgelehnt');
    pruefe(!AufbauService::postenSetzen($id, 'objekt', $a, 'irgendwas')['ok'], 'unbekannter Stand abgelehnt');
    pruefe(AufbauService::postenSetzen($id, 'objekt', $a, 'bestaetigt')['ok'], 'A wie geplant');
    gleich(AnlagenService::find($a)['status'], 'aufgebaut', 'bestätigte Anlage ist aufgebaut');
    pruefe(AufbauService::postenSetzen($id, 'objekt', $b, 'geaendert', null, 'PD 63 statt PD 32')['ok'], 'B geändert');
    pruefe(AufbauService::postenSetzen($id, 'objekt', $c, 'entfallen')['ok'], 'C entfällt');
    pruefe(AufbauService::postenSetzen($id, 'leitung', $kAB, 'geaendert', 60.0, 'um die Bühne')['ok'], 'Kabel A-B länger');
    pruefe(AufbauService::postenSetzen($id, 'leitung', $kAC, 'entfallen')['ok'], 'Kabel A-C entfällt');
    pruefe(AufbauService::postenSetzen($id, 'leitung', $bruecke, 'bestaetigt')['ok'], 'Brücke wie geplant');
    AufbauService::postenSetzen($id, 'leitung', $bruecke, 'offen');
    gleich(AufbauService::posten($id)['l:' . $bruecke] ?? null, null, '„offen“ nimmt die Bestätigung zurück');
    AufbauService::postenSetzen($id, 'leitung', $bruecke, 'bestaetigt');
    $kurz = AufbauService::kurz(AufbauService::find($id));
    pruefe(($kurz['posten']['o:' . $b] ?? '') === 'geaendert' && ($kurz['laengen']['l:' . $kAB] ?? null) == 60.0 && ($kurz['notizen']['o:' . $b] ?? '') === 'PD 63 statt PD 32',
        'Kurzform für die Karte: Stand, Länge, Notiz');

    // Beim Aufbau geändert: B wird versetzt und auf PD 63 umgestellt, dazu kommt eine Anlage D
    AnlagenService::position($b, $floorId, 50.0, 20.0);   // 10 % = 20 m
    AnlagenService::typUebernehmen($b, $pd63);
    $d = $neu(['art' => 'verteilung', 'name' => $kennung . ' D', 'vorlage_id' => $pd32, 'plan_x' => 80.0, 'plan_y' => 80.0]);
    $u = AufbauService::uebersicht(AufbauService::find($id));
    gleich($u['status']['o:' . $d] ?? null, 'zusaetzlich', 'neue Anlage zählt als zusätzlich');
    gleich($u['zaehler'], ['offen' => 0, 'bestaetigt' => 2, 'geaendert' => 2, 'entfallen' => 2, 'zusaetzlich' => 1], 'Zähler');

    // ------------------------------------------------ Fertig
    $e = AufbauService::fertig($id);
    pruefe($e['ok'], 'Aufbau fertig: ' . $e['message']);
    pruefe(!AufbauService::fertig($id)['ok'], 'nur einmal fertig');
    $aufbau = AufbauService::find($id);
    gleich($aufbau['status'], 'betrieb', 'in Betrieb');
    pruefe(in_array($d, array_map(static fn ($o) => (int) $o['id'], $aufbau['ist']['objekte']), true), 'Ist-Stand enthält die zusätzliche Anlage');

    // ------------------------------------------------ Abgleich
    $g = AufbauService::abgleich($aufbau);
    $anlagen = array_column($g['anlagen'], null, 'id');
    pruefe(in_array('20 m versetzt', $anlagen[$b]['abweichungen'], true), 'Versatz aus dem Maßstab: ' . implode(', ', $anlagen[$b]['abweichungen']));
    pruefe(in_array('Typ ' . $kennung . ' PD32 → ' . $kennung . ' PD63', $anlagen[$b]['abweichungen'], true), 'Typwechsel erkannt');
    gleich($anlagen[$d]['status'] ?? null, 'zusaetzlich', 'zusätzliche Anlage im Abgleich');
    gleich($anlagen[$a]['abweichungen'], [], 'A ohne Abweichung');
    $kabel = array_column($g['kabel'], null, 'id');
    gleich([$kabel[$kAB]['laenge_plan'], $kabel[$kAB]['laenge_ist'], $kabel[$kAB]['differenz']], [40.0, 60.0, 20.0], 'Kabel: Plan 40 m, echt 60 m');
    gleich($kabel[$kAC]['laenge_ist'], null, 'entfallenes Kabel hat keine echte Länge');
    gleich([$g['meter_plan'], $g['meter_ist'], $g['abweichung_prozent']], [40.0, 60.0, 50.0], 'Summe nur über gelegte Kabel: +50 %');
    gleich($g['reserve_empfehlung'], 55, 'Reserve-Empfehlung: 50 % Abweichung → 55 %');
    $material = array_column($g['material'], null, 'titel');
    $cee = $material['CEE 32 A · H07RN-F 5G6 mm²'] ?? null;
    $ohne = $material['ohne Stecker · H07RN-F 5G6 mm²'] ?? null;
    pruefe($cee !== null && $cee['plan'] === [50 => 1] && $cee['ist'] === [50 => 1, 20 => 1], 'Material A-B: geplant 50 m, gebraucht 50 + 20 m');
    pruefe($ohne !== null && $ohne['meter_plan'] > 0 && $ohne['ist'] === [], 'Material A-C: entfallen – nichts gebraucht');

    // Last im Betrieb (Demo-Messwerte)
    if (Config::isDemo()) {
        MesspunktService::speichern($a, null, ['geraet' => 'shellypro3em-catering', 'kanal' => 'em:0', 'rolle' => 'einspeisung']);
        // Ein Tank mit einem Messgerät hat trotzdem keinen Nennstrom und keine Last.
        $tank = $neu(['art' => 'tank', 'name' => $kennung . ' Tank', 'plan_x' => 30.0, 'plan_y' => 30.0]);
        Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => 'shellypro3em-foodtruck', 'kanal' => 'em:0', 'rolle' => 'einspeisung',
            'bezeichnung' => '', 'gueltig_ab' => gmdate('c', time() - 7 * 3600), 'created_at' => gmdate('c')]);
        Database::update('aufbauten', $id, ['betrieb_ab' => gmdate('c', time() - 6 * 3600)]);
        $last = array_column(AufbauService::abgleich(AufbauService::find($id))['last'], null, 'id');
        pruefe(isset($last[$a]) && $last[$a]['i_max'] > 0 && $last[$a]['nennstrom'] == 63.0 && $last[$a]['auslastung'] > 0,
            'Last: höchster Strom gegen 63 A – ' . ($last[$a]['auslastung'] ?? '?') . ' %');
        pruefe(($last[$a]['empfehlung']['text'] ?? '') !== '', 'Empfehlung: ' . ($last[$a]['empfehlung']['text'] ?? ''));
        pruefe(!isset($last[$tank]), 'Last: keine Zeile für einen Tank');
    } else {
        echo "  (Last übersprungen: kein Demo-Modus)\n";
    }

    // ------------------------------------------------ Beenden: Inventar abbauen
    $stueck = (int) InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $kennung . '-PD', 'vorlage_id' => $pd63])['id'];
    InventarService::einsetzen($stueck, $a);
    $e = AufbauService::beenden($id, true);
    pruefe($e['ok'] && str_contains($e['message'], '1 Inventar-Stück'), 'beendet und abgebaut: ' . $e['message']);
    gleich(InventarService::find($stueck)['zustand'], 'lager', 'Inventar wieder im Lager');
    gleich(AufbauService::find($id)['status'], 'beendet', 'beendet');
    gleich(AufbauService::aktiv($siteId), null, 'kein laufender Aufbau mehr');
    pruefe(!AufbauService::postenSetzen($id, 'objekt', $a, 'entfallen')['ok'], 'nach dem Ende keine Bestätigungen mehr');
    pruefe(AufbauService::starten($siteId, $kennung . ' Nächstes')['ok'], 'danach lässt sich der nächste starten');

    // Standort gelöscht: Aufbauten weg
    SiteService::deleteSite($anderer);
    $anderer = 0;
    gleich((int) Database::scalar('SELECT COUNT(*) FROM aufbauten WHERE site_id = ?', [$siteId]), 2, 'zwei Aufbauten auf dem Standort');
    InventarService::loeschen($stueck);
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$kennung . '%']) as $i) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), (int) $i['id']]);
        InventarService::loeschen((int) $i['id']);
    }
    Database::execute('DELETE FROM leitungen WHERE name LIKE ?', [$kennung . '%']);
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    SiteService::deleteSite($siteId);
    if ($anderer > 0) {
        SiteService::deleteSite($anderer);
    }
    gleich((int) Database::scalar('SELECT COUNT(*) FROM aufbauten WHERE site_id = ?', [$siteId]), 0, 'mit dem Standort gelöscht');
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$benutzer]);
    Database::execute('DELETE FROM users WHERE id = ?', [$benutzer]);
    Database::execute('DELETE FROM audit_log WHERE username = ? OR details LIKE ?', [$kennung, '%' . $kennung . '%']);
}

ende('aufbau');
