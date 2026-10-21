<?php
declare(strict_types=1);

/**
 * Inventar: Verteiler und Zwischenstecker mit Shellys, Einsatz in Anlagen,
 * Gültigkeit der Messpunkte, Bereiche über wechselnde Geräte, Schalten.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\BereichService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LiveService;
use App\Services\LocationBundle;
use App\Services\MesspunktService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Zeit;

// ------------------------------------------------ Datenfenster zuschneiden
gleich(Zugriff::schneiden(null, null, null), null, 'ohne Grenzen bleibt alles offen');
gleich(Zugriff::schneiden(null, 100, 200), [[100, 200]], 'offenes Fenster auf den Einsatz begrenzt');
gleich(Zugriff::schneiden([[100, 300]], 200, null), [[200, 300]], 'Einsatz läuft noch: ab Einsatzbeginn');
gleich(Zugriff::schneiden([[100, 200]], 300, 400), [], 'Einsatz außerhalb der Freigabe: nichts');
gleich(Zugriff::schneiden([[null, 150], [250, null]], 100, 300), [[100, 150], [250, 300]], 'mehrere Fenster einzeln geschnitten');
gleich(Zugriff::schneiden([], 100, 200), [], 'ohne Freigabe bleibt nichts');

// ------------------------------------------------ Testbestand
AnlagenService::grundbestand();
$typ = [];
foreach (AnlagenService::steckertypen() as $t) {
    $typ[(string) $t['schluessel']] = (int) $t['id'];
}
$kennung = 'pm-inv-' . bin2hex(random_bytes(3));
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Inventar ' . $kennung]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Halle']);
$benutzer = BenutzerService::anlegen($kennung, 'Pruefung2026!!', 'admin', false);
$angelegt = [];
$gr = static fn (string $n): string => $kennung . '-' . $n;

try {
    alsBenutzer($benutzer);
    $neu = static function (array $d) use ($floorId, &$angelegt): int {
        $id = (int) AnlagenService::speichern(null, $d + ['floor_id' => $floorId, 'plan_x' => 10.0, 'plan_y' => 10.0])['id'];
        $angelegt[] = $id;
        return $id;
    };
    $vorlage = static function (string $name, array $ein, array $ab) use (&$angelegt, $typ, $kennung): int {
        $id = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' ' . $name, 'modell' => $kennung . ' ' . $name,
            'ist_vorlage' => 1, 'ohne_anschluesse' => true])['id'];
        $angelegt[] = $id;
        AnlagenService::anzahlSetzen($id, [
            'eingang' => array_combine(array_map(static fn ($k) => $typ[$k], array_keys($ein)), array_values($ein)),
            'abgang'  => array_combine(array_map(static fn ($k) => $typ[$k], array_keys($ab)), array_values($ab)),
        ]);
        SchutzService::vorschlagen($id);
        return $id;
    };
    $pd63 = $vorlage('PD63', ['cee63' => 1], ['cee32' => 2, 'schuko' => 3]);
    $pd32 = $vorlage('PD32', ['cee32' => 1], ['cee16' => 2, 'schuko' => 2]);
    $abgang = static fn (int $objekt, string $stecker, int $n = 0): int
        => (int) Database::select('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr', [$objekt, 'abgang', $typ[$stecker]])[$n]['id'];
    $nrVon = static fn (int $anschluss): int => (int) Database::scalar('SELECT nr FROM anschluesse WHERE id = ?', [$anschluss]);

    // ------------------------------------------------ Speichern
    pruefe(!InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => ''])['ok'], 'ohne Inventarnummer abgelehnt');
    pruefe(!InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => $gr('zs')])['ok'], 'Zwischenstecker ohne Stecker abgelehnt');
    pruefe(!InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $gr('x'), 'vorlage_id' => $floorId + 999999])['ok'], 'unbekannter Typ abgelehnt');
    pruefe(!InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $gr('x'), 'messgeraete' => [
        ['geraet' => $gr('a'), 'kanal' => 'em:0'], ['geraet' => $gr('a'), 'kanal' => 'em:0']]])['ok'], 'Kanal doppelt abgelehnt');

    $schukoNr = $nrVon((int) Database::scalar('SELECT id FROM anschluesse WHERE objekt_id = ? AND steckertyp_id = ? ORDER BY nr LIMIT 1', [$pd63, $typ['schuko']]));
    $e = InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $gr('PD63-01'), 'vorlage_id' => $pd63, 'seriennummer' => 'SN-1', 'messgeraete' => [
        ['geraet' => $gr('3em'), 'kanal' => 'em:0', 'stelle' => 'einspeisung'],
        ['geraet' => $gr('1pm'), 'kanal' => 'switch:0', 'stelle' => 'abgang:' . $schukoNr, 'bezeichnung' => 'Kaffee'],
        ['geraet' => '', 'kanal' => 'leer'],
    ]]);
    pruefe($e['ok'], 'Verteiler gespeichert: ' . $e['message']);
    $v1 = (int) $e['id'];
    gleich(count(InventarService::messgeraete($v1)), 2, 'leere Zeile übergangen');
    pruefe(!InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => strtoupper($gr('pd63-01'))])['ok'], 'Inventarnummer ohne Rücksicht auf Groß/klein eindeutig');
    pruefe(!InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $gr('y'), 'messgeraete' => [['geraet' => $gr('3em'), 'kanal' => 'em:0']]])['ok'],
        'Shelly-Kanal, der schon in einem anderen Stück steckt, abgelehnt');
    $stellen = InventarService::stellen(InventarService::find($v1));
    pruefe(isset($stellen['einspeisung'], $stellen['abgang:' . $schukoNr]) && count($stellen) === 6, 'Stellen: Einspeisung und die fünf Abgänge des Typs');
    $zs = (int) InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => $gr('ZS63'), 'steckertyp_id' => $typ['cee63'],
        'messgeraete' => [['geraet' => $gr('zs'), 'kanal' => 'em:0', 'stelle' => 'irgendwo']]])['id'];
    gleich(InventarService::messgeraete($zs)[0]['stelle'] ?? '', 'durchgang', 'Zwischenstecker misst im Durchgang');
    // Tanksensor: kein Stecker, kein Typ, genau ein Messkanal am Füllstand – und Sensordaten
    $ts = InventarService::speichern(null, ['art' => 'tanksensor', 'kennung' => $gr('TS-01'), 'steckertyp_id' => $typ['cee16'], 'vorlage_id' => $pd63,
        'eigenschaften' => ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA-wandler', 'u_bei_4ma' => '2', 'u_bei_20ma' => '10',
            'bereich_bis' => '2', 'bereich_einheit' => 'mWS'],
        'messgeraete' => [['geraet' => $gr('uni'), 'kanal' => 'voltmeter:100', 'stelle' => 'einspeisung']]]);
    pruefe($ts['ok'], 'Tanksensor ohne Stecker gespeichert: ' . $ts['message']);
    $tsItem = InventarService::find((int) ($ts['id'] ?? 0));
    gleich([$tsItem['art_name'], $tsItem['typ'], $tsItem['steckertyp_id'], $tsItem['vorlage_id'], InventarService::stellen($tsItem)],
        ['Tanksensor', 'Hydrostatisch 0–2 mWS, 4–20 mA über Wandler 2–10 V', null, null, ['fuellstand' => 'Füllstand']],
        'aufbereiten und stellen() für den Tanksensor: Typ aus den Sensordaten, weder Stecker noch Typ');
    gleich(InventarService::messgeraete((int) $tsItem['id'])[0]['stelle'] ?? '', 'fuellstand', 'Tanksensor misst am Füllstand');
    gleich([InventarService::find($v1)['eigenschaften'], InventarService::find($zs)['eigenschaften']], [[], []], 'Verteiler und Zwischenstecker ohne Sensordaten');
    $v2 = (int) InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $gr('PD63-02'), 'vorlage_id' => $pd63,
        'messgeraete' => [['geraet' => $gr('3em-b'), 'kanal' => 'em:0', 'stelle' => 'einspeisung']]])['id'];

    // ------------------------------------------------ Einsetzen in eine geplante Anlage
    $gen = $neu(['art' => 'generator', 'name' => $kennung . ' Aggregat', 'ohne_anschluesse' => true]);
    AnlagenService::anzahlSetzen($gen, ['abgang' => [$typ['cee63'] => 2]]);
    $planA = $neu(['art' => 'verteilung', 'name' => $kennung . ' Meile A', 'vorlage_id' => $pd63]);
    $planB = $neu(['art' => 'verteilung', 'name' => $kennung . ' Meile B', 'vorlage_id' => $pd32]);
    pruefe(!InventarService::einsetzen($v1, $gen)['ok'], 'Verteiler nicht in ein Aggregat');

    $e = InventarService::einsetzen($v1, $planA);
    pruefe($e['ok'] && !str_contains($e['message'], 'Typ geändert'), 'eingesetzt, gleicher Typ: ' . $e['message']);
    $o = AnlagenService::find($planA);
    pruefe($o['kennung'] === $gr('PD63-01') && $o['seriennummer'] === 'SN-1' && $o['status'] === 'aufgebaut', 'Anlage trägt Nummer und Seriennummer, ist aufgebaut');
    $mps = MesspunktService::fuerObjekt($planA);
    gleich(count($mps), 2, 'zwei Messpunkte aus dem Verteiler');
    $kaffee = array_values(array_filter($mps, static fn ($m) => $m['rolle'] === 'abgang'))[0] ?? null;
    pruefe($kaffee !== null && $nrVon((int) $kaffee['anschluss_id']) === $schukoNr && $kaffee['bezeichnung'] === 'Kaffee', 'Abgangs-Shelly sitzt am Abgang gleicher Nummer');
    pruefe(($mps[0]['einsatz_id'] ?? null) !== null && ($mps[0]['gueltig_ab'] ?? null) !== null && ($mps[0]['inventar_kennung'] ?? '') === $gr('PD63-01'),
        'Messpunkte kennen Einsatz, Beginn und Inventarnummer');
    gleich(InventarService::find($v1)['zustand'], 'im_einsatz', 'Stück ist im Einsatz');
    pruefe(!InventarService::loeschen($v1)['ok'], 'im Einsatz nicht löschbar');

    // Anderer Typ: die geplante PD 32 wird zur PD 63, das Kabel bleibt am Eingang
    $kabel = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'name' => $kennung, 'von_objekt_id' => $gen,
        'von_anschluss_id' => $abgang($gen, 'cee63'), 'zu_objekt_id' => $planB,
        'zu_anschluss_id' => (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang'", [$planB]),
        'kabeltyp' => 'H07RN-F', 'adern' => 5, 'querschnitt_mm2' => 16, 'punkte' => [[10.0, 10.0], [20.0, 10.0]]]);
    pruefe($kabel['ok'], 'Kabel zur geplanten PD 32');
    $e = InventarService::einsetzen($v2, $planB);
    pruefe($e['ok'] && str_contains($e['message'], 'Typ geändert'), 'anderer Typ: umgestellt – ' . $e['message']);
    gleich(AnlagenService::find($planB)['modell'], $kennung . ' PD63', 'Anlage hat jetzt den Typ des Verteilers');
    $eingang = Database::first("SELECT a.*, t.schluessel FROM anschluesse a JOIN steckertypen t ON t.id = a.steckertyp_id WHERE a.objekt_id = ? AND a.richtung = 'eingang'", [$planB]);
    gleich($eingang['schluessel'] ?? '', 'cee63', 'Eingang ist jetzt CEE 63');
    gleich((int) Database::scalar('SELECT zu_anschluss_id FROM leitungen WHERE id = ?', [(int) $kabel['id']]), (int) $eingang['id'], 'Kabel steckt am neuen Eingang');

    // Zwischenstecker am Aggregat-Abgang, dann an der Einspeisung einer Verteilung
    $e = InventarService::einsetzen($zs, $gen, $abgang($gen, 'cee63', 1));
    pruefe($e['ok'], 'Zwischenstecker am Abgang: ' . $e['message']);
    $zsMp = array_values(array_filter(MesspunktService::fuerObjekt($gen), static fn ($m) => $m['geraet'] === $gr('zs')))[0] ?? null;
    pruefe($zsMp !== null && $zsMp['rolle'] === 'abgang' && (int) $zsMp['anschluss_id'] === $abgang($gen, 'cee63', 1), 'misst am Abgang');

    // Bereich mit dem Abgang – er soll der Stelle folgen, nicht dem Gerät
    $bereich = BereichService::find((int) BereichService::speichern(null, ['site_id' => $siteId, 'name' => $kennung . ' Meile',
        'mitglieder' => ['m:' . (int) $zsMp['id']]])['id']);

    // Umsetzen: derselbe Zwischenstecker an die Einspeisung von Meile A
    $e = InventarService::einsetzen($zs, $planA, null);
    pruefe($e['ok'] && str_contains($e['message'], 'abgezogen'), 'umgesetzt: ' . $e['message']);
    gleich(array_values(array_filter(MesspunktService::fuerObjekt($gen), static fn ($m) => $m['geraet'] === $gr('zs'))), [], 'am Aggregat misst er nicht mehr');
    $frueher = array_values(array_filter(MesspunktService::fuerObjekt($gen, true), static fn ($m) => $m['geraet'] === $gr('zs')));
    pruefe(count($frueher) === 1 && $frueher[0]['gueltig_bis'] !== null && str_contains(MesspunktService::titel($frueher[0] + ['objekt_name' => 'x']), '(bis '),
        'früherer Messpunkt bleibt mit Ende');
    // Einsetzen und Umsetzen lagen in derselben Sekunde – die Zeit am Aggregat
    // für die Prüfung auf eine Stunde bis vor einer Minute legen.
    Database::execute('UPDATE messpunkte SET gueltig_ab = ?, gueltig_bis = ? WHERE id = ?', [gmdate('c', time() - 3600), gmdate('c', time() - 60), (int) $frueher[0]['id']]);
    $frueher = array_values(array_filter(MesspunktService::fuerObjekt($gen, true), static fn ($m) => $m['geraet'] === $gr('zs')));
    $neuMp = array_values(array_filter(MesspunktService::fuerObjekt($planA), static fn ($m) => $m['geraet'] === $gr('zs')))[0] ?? null;
    pruefe($neuMp !== null && $neuMp['rolle'] === 'einspeisung' && $neuMp['anschluss_id'] === null, 'an der Einspeisung: Rolle Einspeisung');

    // Fenster: Admin sieht alles – begrenzt auf die Zeit am Ort
    $f = MesspunktService::fenster($frueher[0], AnlagenService::find($gen));
    pruefe(is_array($f) && count($f) === 1 && $f[0][0] === Zeit::ts($frueher[0]['gueltig_ab']) && $f[0][1] === Zeit::ts($frueher[0]['gueltig_bis']),
        'Daten des früheren Messpunkts nur für seinen Einsatz');
    gleich(MesspunktService::fenster(['gueltig_ab' => null, 'gueltig_bis' => null], AnlagenService::find($gen)), null, 'fest eingebaut: ohne Grenze');

    // Ein anderer Zwischenstecker an derselben Stelle des Aggregats
    $zs2 = (int) InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => $gr('ZS32'), 'steckertyp_id' => $typ['cee32'],
        'messgeraete' => [['geraet' => $gr('zs2'), 'kanal' => 'em:0']]])['id'];
    $e = InventarService::einsetzen($zs2, $gen, $abgang($gen, 'cee63', 1));
    pruefe($e['ok'] && str_contains($e['message'], 'Achtung'), 'Stecker passt nicht: Hinweis – ' . $e['message']);
    $aufbau = BereichService::aufbau($bereich);
    $eintrag = $aufbau[0] ?? [];
    gleich(array_map(static fn ($m) => $m['geraet'], $eintrag['quellen'] ?? []), [$gr('zs2')], 'Bereich: jetzt zählt der neue Zwischenstecker');
    $zeit = [];
    foreach ($eintrag['zeitquellen'] ?? [] as $z) {
        $zeit[$z['m']['geraet']] = $z['teile'];
    }
    pruefe(isset($zeit[$gr('zs')], $zeit[$gr('zs2')]), 'Bereich über die Zeit: beide Geräte an derselben Stelle');
    pruefe(($zeit[$gr('zs')][0] ?? null) === [Zeit::ts($frueher[0]['gueltig_ab']), Zeit::ts($frueher[0]['gueltig_bis'])]
        && isset($zeit[$gr('zs2')][0]) && $zeit[$gr('zs2')][0][1] === null,
        'das alte bis zu seinem Ende, das neue ab seinem Beginn offen');

    // Shellys getauscht, während das Stück steckt: ab jetzt der neue
    $e = InventarService::speichern($zs2, ['art' => 'zwischenstecker', 'kennung' => $gr('ZS32'), 'steckertyp_id' => $typ['cee32'],
        'messgeraete' => [['geraet' => $gr('zs2-neu'), 'kanal' => 'em:0']]]);
    pruefe($e['ok'], 'Shelly im Stück getauscht');
    gleich(array_map(static fn ($m) => $m['geraet'], array_values(array_filter(MesspunktService::fuerObjekt($gen), static fn ($m) => str_starts_with($m['geraet'], $gr('zs2'))))),
        [$gr('zs2-neu')], 'an der Anlage misst jetzt der neue Shelly');

    // Zweiter Verteiler in dieselbe Anlage: der erste ist damit raus
    $v3 = (int) InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $gr('PD63-03'), 'vorlage_id' => $pd63])['id'];
    $e = InventarService::einsetzen($v3, $planA);
    pruefe($e['ok'] && str_contains($e['message'], $gr('PD63-01') . ' ersetzt'), 'Verteiler ersetzt: ' . $e['message']);
    gleich(InventarService::find($v1)['zustand'], 'lager', 'der ersetzte ist wieder im Lager');
    gleich(array_values(array_filter(MesspunktService::fuerObjekt($planA), static fn ($m) => $m['geraet'] === $gr('3em'))), [], 'seine Shellys messen dort nicht mehr');
    pruefe(count(array_filter(MesspunktService::fuerObjekt($planA), static fn ($m) => $m['geraet'] === $gr('zs'))) === 1, 'der Zwischenstecker an der Einspeisung bleibt');

    // Abbau
    $einsatz = InventarService::aktuellerEinsatz($zs);
    gleich(InventarService::beenden((int) $einsatz['id'])['ok'], true, 'Zwischenstecker abgebaut');
    gleich(InventarService::beenden((int) $einsatz['id'])['ok'], false, 'zweimal abbauen geht nicht');
    Zugriff::vergessen();
    gleich(Zugriff::objekteZuGeraet($gr('zs')), [], 'abgebautes Gerät gehört zu keiner Anlage mehr');
    $live = LiveService::fuerObjekte([AnlagenService::find($planA)])[$planA];
    pruefe(!isset($live['messpunkte'][(int) $neuMp['id']]), 'Livewerte ohne das abgebaute Gerät');

    // Schalten: ein weitergezogener Shelly gehört nicht mehr zur alten Anlage
    alsBenutzer($benutzer);
    pruefe(!Zugriff::darfSteuern(['id' => 0, 'geraet' => $gr('zs'), 'art' => 'shelly'], 'switch:0', AnlagenService::find($planA)),
        'abgebautes Gerät lässt sich über die alte Anlage nicht schalten');

    // Feststromanschluss mit Zwischenstecker: der geht nicht in den Location-Abgleich
    $saeule = $neu(['art' => 'anschluss', 'name' => $kennung . ' Säule', 'fest' => 1, 'ohne_anschluesse' => true]);
    AnlagenService::anzahlSetzen($saeule, ['abgang' => [$typ['cee63'] => 1]]);
    Database::update('objekte', $saeule, ['sync_key' => $kennung . '-saeule']);
    InventarService::einsetzen($zs, $saeule, $abgang($saeule, 'cee63'));
    $strom = LocationBundle::stromLesen($siteId);
    $anlage = array_values(array_filter($strom['anlagen'], static fn ($a) => ($a['name'] ?? '') === $kennung . ' Säule'))[0] ?? null;
    pruefe($anlage !== null && ($anlage['messpunkte'] ?? []) === [], 'Zwischenstecker-Messpunkt nicht in strom.json');
    LocationBundle::stromAnwenden($siteId, json_decode((string) json_encode($strom), true));
    gleich(count(MesspunktService::fuerObjekt($saeule)), 1, 'Location-Abgleich lässt den Einsatz-Messpunkt stehen');

    // Anlage gelöscht: der Einsatz endet, das Stück ist frei
    AnlagenService::loeschen($saeule);
    $angelegt = array_values(array_diff($angelegt, [$saeule]));
    gleich(InventarService::find($zs)['zustand'], 'lager', 'Anlage gelöscht: Zwischenstecker wieder im Lager');
    pruefe(InventarService::geschichte($zs) !== [] && InventarService::geschichte($zs)[0]['bis'] !== null, 'Geschichte behält den beendeten Einsatz');

    // Defekt: nicht einsetzbar
    InventarService::speichern($v1, ['art' => 'verteilung', 'kennung' => $gr('PD63-01'), 'vorlage_id' => $pd63, 'defekt' => 1]);
    pruefe(!InventarService::einsetzen($v1, $planB)['ok'], 'defektes Stück nicht einsetzbar');
    gleich(InventarService::liste(['frei' => true, 'suche' => $kennung]) !== [] && !in_array($gr('PD63-01'), array_column(InventarService::liste(['frei' => true, 'suche' => $kennung]), 'kennung'), true), true,
        'Liste „frei“ ohne defekte und eingesetzte');
    pruefe(InventarService::loeschen($zs)['ok'], 'freies Stück löschbar');
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$kennung . '%']) as $i) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), (int) $i['id']]);
        InventarService::loeschen((int) $i['id']);
    }
    Database::execute('DELETE FROM bereich_mitglieder WHERE bereich_id IN (SELECT id FROM bereiche WHERE name LIKE ?)', [$kennung . '%']);
    Database::execute('DELETE FROM bereiche WHERE name LIKE ?', [$kennung . '%']);
    Database::execute('DELETE FROM leitungen WHERE name = ?', [$kennung]);
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    SiteService::deleteSite($siteId);
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$benutzer]);
    Database::execute('DELETE FROM users WHERE id = ?', [$benutzer]);
    Database::execute('DELETE FROM audit_log WHERE username = ? OR details LIKE ?', [$kennung, '%' . $kennung . '%']);
}

ende('inventar');
