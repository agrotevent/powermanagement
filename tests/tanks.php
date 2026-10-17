<?php
declare(strict_types=1);

/**
 * Tanks und Tanksensoren.
 *
 * A – Art ohne Strom: keine Anschlüsse und Schutzorgane, feste elektrische
 * Felder, kein Wechsel zwischen elektrischer und nicht elektrischer Art,
 * kein Kabel und kein Zwischenstecker am Tank, nicht im Verteilbaum, in
 * Bereichen und beim Schalten; Kurzform, Katalog-Typ, Eigenschaften.
 *
 * B – Rechnung (rein, TankRechnung): Rohwert → Füllhöhe → Liter für
 * 4–20 mA an der Bürde und über den Wandler, 0–10 V, Ultraschall, Schwimmer,
 * liegenden Zylinder; Sensorfehler statt „leer“, Prüfung von Sensordaten und
 * Fassungen, Abschnitte der Fassungen, Peilung (auch zwei mit dem
 * Peilstab), Betankung und ihr Sprung, Verbrauch je Last, Stufen. Dazu
 * Migration 018 und die Beschriftungen der Rohwerte.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Zugriff;
use App\Messung\Datenquellen;
use App\Messung\Messdaten;
use App\Messung\Steuerung;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\BereichService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LiveService;
use App\Services\LocationBundle;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Database;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

// ------------------------------------------------ A – Art und Felder (ohne Datenbank)
gleich(AnlagenService::elektrisch('tank'), false, 'ein Tank ist keine elektrische Anlage');
gleich(AnlagenService::elektrisch('generator'), true, 'ein Generator ist elektrisch');
gleich(AnlagenService::elektrisch('unbekannt'), true, 'eine unbekannte Art gilt als elektrisch');
pruefe(array_filter(AnlagenService::ARTEN, static fn (array $a): bool => !isset($a['neu'], $a['elektrisch'])) === [], 'jede Art nennt „neu“ und „elektrisch“');
gleich([AnlagenService::ARTEN['tank']['neu'], AnlagenService::ARTEN['verteilung']['neu']], ['Neuer Tank', 'Neue Verteilung'], 'Überschrift für eine neue Anlage');
pruefe(!isset(AnlagenService::VORSCHLAG['tank']), 'kein Anschluss-Vorschlag für Tanks');
gleich(AnlagenService::FELDER['generator']['verbrauch_kennlinie'][1] ?? null, 'text', 'Generator: Verbrauch je Last als Text');
$gruppiert = array_merge(...array_values(AnlagenService::FELDGRUPPEN['tank']));
$alle = array_keys(AnlagenService::FELDER['tank']);
sort($alle);
$sortiert = $gruppiert;
sort($sortiert);
pruefe($sortiert === $alle && count($gruppiert) === count(array_unique($gruppiert)), 'jede Tank-Eigenschaft steht in genau einer Gruppe');
gleich(array_keys(AnlagenService::feldGruppen('tank')), ['Katalog', 'Maße', 'Kennlinie', 'Grenzen'], 'Gruppen des Tanks, ohne „Weitere Angaben“');
gleich(array_keys(AnlagenService::feldGruppen('generator')), [''], 'ohne Gruppen: eine Gruppe ohne Titel');
pruefe(array_filter(AnlagenService::FELDER['tank'], static fn (array $f): bool => !in_array($f[1], ['text', 'zahl', 'auswahl'], true)) === [], 'Tank-Felder sind skalar');
gleich(AnlagenService::eigenschaftenFiltern('tank', ['inhalt_l' => '3000', 'liter_je_mm' => '2,78', 'gewicht_kg' => '1e999', 'fremd' => 'x',
    'kraftstoff' => 'HVO', 'zulassung' => ['kein', 'Text']]), ['inhalt_l' => 3000.0, 'kraftstoff' => 'HVO', 'liter_je_mm' => 2.78],
    'Eigenschaften: Komma als Dezimalzeichen, unendlich, fremde Schlüssel und Listen fallen weg');
gleich(TankService::kurzform(['eigenschaften' => ['inhalt_l' => 3000.0, 'kraftstoff' => 'Diesel', 'bauform' => 'Quader']]), '3.000 l · Diesel · Quader', 'Kurzform');
gleich(TankService::kurzform(['eigenschaften' => '{"inhalt_l":1250.5}']), '1.250,5 l', 'Kurzform aus JSON mit Nachkommastelle');
gleich(TankService::kurzform(['eigenschaften' => []]), 'Nennvolumen nicht eingetragen', 'Kurzform ohne Angaben');
gleich([TankService::zahl(2.78), TankService::zahl(3000.0), TankService::zahl(0.835)], ['2,78', '3.000', '0,835'], 'Zahlen mit Tausenderpunkt, nur nötige Stellen');

// ------------------------------------------------ Testbestand
AnlagenService::grundbestand();
$typ = [];
foreach (AnlagenService::steckertypen() as $t) {
    $typ[(string) $t['schluessel']] = (int) $t['id'];
}
$kennung = 'pm-tank-' . bin2hex(random_bytes(3));
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Tanks ' . $kennung]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Wiese']);
$benutzer = BenutzerService::anlegen($kennung, 'Pruefung2026!!', 'admin', false);
$angelegt = [];
$quellen = [];
$anzahl = static fn (string $tabelle, int $objekt): int => (int) Database::scalar('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE objekt_id = ?', [$objekt]);
$meldungB1 = 'Ein Tank wird keine elektrische Anlage (und umgekehrt) – bitte neu anlegen.';

try {
    alsBenutzer($benutzer);
    $neu = static function (array $d) use ($floorId, &$angelegt): array {
        $e = AnlagenService::speichern(null, $d + ['floor_id' => $floorId, 'plan_x' => 10.0, 'plan_y' => 10.0]);
        if ($e['ok']) {
            $angelegt[] = (int) $e['id'];
        }
        return $e;
    };

    // ------------------------------------------------ Speichern: ohne Anschlüsse, elektrische Felder fest
    $e = $neu(['art' => 'tank', 'name' => $kennung . ' Tank', 'nennleistung_kva' => '100', 'nennstrom_a' => '63', 'spannung_v' => '230',
        'phasen' => '1', 'steuerung_gesperrt' => 1, 'eigenschaften' => ['inhalt_l' => '3000', 'kraftstoff' => 'Diesel', 'bauform' => 'Quader', 'fremd' => 'weg']]);
    pruefe($e['ok'] && str_starts_with($e['message'], 'Tank angelegt'), 'Tank angelegt: ' . $e['message']);
    $tank = (int) ($e['id'] ?? 0);
    $t = AnlagenService::find($tank);
    gleich([$t['nennleistung_kva'], $t['nennstrom_a'], (int) $t['spannung_v'], (int) $t['phasen'], (int) $t['steuerung_gesperrt']], [null, null, 400, 3, 0],
        'Nennleistung, Nennstrom, Spannung, Phasen und Sperre fest – was das Formular auch schickt');
    gleich([$anzahl('anschluesse', $tank), $anzahl('schutzorgane', $tank)], [0, 0], 'ein neuer Tank hat keine Anschlüsse und Schutzorgane');
    gleich($t['eigenschaften'], ['inhalt_l' => 3000.0, 'kraftstoff' => 'Diesel', 'bauform' => 'Quader'], 'Eigenschaften des Tanks');
    gleich((int) $t['fest'], 0, 'ein Tank ist von Haus aus mobil');
    $kurz = AnlagenService::kurzform($tank);
    pruefe(str_contains($kurz, ' l') && !str_contains($kurz, 'keine Anschlüsse dokumentiert'), 'Kurzform nennt den Inhalt statt der Anschlüsse: ' . $kurz);
    $e = AnlagenService::speichern($tank, ['art' => 'tank', 'name' => $kennung . ' Tank', 'floor_id' => $floorId, 'nennleistung_kva' => '50', 'phasen' => 1,
        'eigenschaften' => ['inhalt_l' => '2850', 'kraftstoff' => 'Diesel', 'bauform' => 'Quader']]);
    $t = AnlagenService::find($tank);
    pruefe($e['ok'] && $t['nennleistung_kva'] === null && (int) $t['phasen'] === 3 && (float) $t['eigenschaften']['inhalt_l'] === 2850.0,
        'Bearbeiten: elektrische Felder bleiben fest, Nennvolumen geändert');

    // ------------------------------------------------ Kein Wechsel zwischen elektrisch und nicht elektrisch (B1)
    $e = $neu(['art' => 'generator', 'name' => $kennung . ' Aggregat']);
    $gen = (int) $e['id'];
    pruefe($anzahl('anschluesse', $gen) > 0, 'Gegenprobe: ein Generator bekommt den Anschluss-Vorschlag');
    $e = AnlagenService::speichern($gen, ['art' => 'tank', 'name' => $kennung . ' Aggregat', 'floor_id' => $floorId]);
    pruefe(!$e['ok'] && $e['message'] === $meldungB1, 'Generator → Tank abgelehnt: ' . $e['message']);
    gleich([AnlagenService::find($gen)['art'], $anzahl('anschluesse', $gen) > 0], ['generator', true], '… der Generator bleibt mit seinen Anschlüssen');
    $e = AnlagenService::speichern($tank, ['art' => 'generator', 'name' => $kennung . ' Tank', 'floor_id' => $floorId]);
    pruefe(!$e['ok'] && $e['message'] === $meldungB1, 'Tank → Generator abgelehnt');
    gleich(AnlagenService::find($tank)['art'], 'tank', '… der Tank bleibt ein Tank');
    $e = AnlagenService::speichern($gen, ['art' => 'wechselrichter', 'name' => $kennung . ' Aggregat', 'floor_id' => $floorId]);
    pruefe($e['ok'] && AnlagenService::find($gen)['art'] === 'wechselrichter', 'Gegenprobe: zwischen elektrischen Arten bleibt der Wechsel möglich');
    AnlagenService::speichern($gen, ['art' => 'generator', 'name' => $kennung . ' Aggregat', 'floor_id' => $floorId]);

    // ------------------------------------------------ Katalog-Typ
    $e = AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennung . ' QUADRO', 'modell' => $kennung . ' QUADRO-AG 3000', 'ist_vorlage' => 1,
        'eigenschaften' => ['artikelnummer' => '1004093', 'inhalt_l' => '3000', 'zulassung' => 'Z-38.12-223', 'tankanschluss' => '2 Anschlüsse']]);
    pruefe($e['ok'], 'Tank-Typ im Katalog: ' . $e['message']);
    $tankTyp = (int) $e['id'];
    $angelegt[] = $tankTyp;
    $e = $neu(['art' => 'tank', 'name' => $kennung . ' aus Typ', 'vorlage_id' => $tankTyp, 'eigenschaften' => ['kraftstoff' => 'HVO']]);
    $ausTyp = AnlagenService::find((int) $e['id']);
    gleich([$ausTyp['modell'], $ausTyp['eigenschaften']['zulassung'] ?? null, $ausTyp['eigenschaften']['kraftstoff'] ?? null, $anzahl('anschluesse', (int) $ausTyp['id'])],
        [$kennung . ' QUADRO-AG 3000', 'Z-38.12-223', 'HVO', 0], 'Tank aus dem Katalog: Typ und Eigenschaften übernommen, keine Anschlüsse');
    $e = AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' PD', 'modell' => $kennung . ' PD', 'ist_vorlage' => 1,
        'ohne_anschluesse' => true, 'eigenschaften' => ['artikelnummer' => 'V-1']]);
    $pd = (int) $e['id'];
    $angelegt[] = $pd;
    AnlagenService::anzahlSetzen($pd, ['eingang' => [$typ['cee32'] => 1], 'abgang' => [$typ['cee16'] => 2]]);
    $e = $neu(['art' => 'tank', 'name' => $kennung . ' aus Verteiler', 'vorlage_id' => $pd]);
    $ausPd = AnlagenService::find((int) $e['id']);
    gleich([$ausPd['modell'], $ausPd['eigenschaften'], $anzahl('anschluesse', (int) $ausPd['id'])], ['', [], 0],
        'ein Verteiler-Typ ist kein Bauplan für einen Tank (weder Typ noch Eigenschaften noch Anschlüsse)');

    // ------------------------------------------------ Anschlüsse und Schutzorgane gibt es am Tank nicht
    $e = AnlagenService::anzahlSetzen($tank, ['abgang' => [$typ['cee16'] => 2]]);
    pruefe(!$e['ok'] && $anzahl('anschluesse', $tank) === 0, 'Anschlüsse am Tank abgelehnt');
    pruefe(!SchutzService::speichern($tank, null, ['art' => 'ls', 'bezeichnung' => 'F1'])['ok'] && !SchutzService::vorschlagen($tank)['ok']
        && $anzahl('schutzorgane', $tank) === 0, 'Sicherungen am Tank abgelehnt');
    LocationBundle::belegungErsetzen($tank, ['anschluesse' => [['richtung' => 'abgang', 'stecker' => 'cee16', 'nr' => 1]],
        'schutzorgane' => [['art' => 'ls', 'bezeichnung' => 'F1']]], ['cee16' => $typ['cee16']]);
    gleich([$anzahl('anschluesse', $tank), $anzahl('schutzorgane', $tank)], [0, 0], 'auch aus einer Datei (Abgleich, Import) keine Anschlüsse am Tank');
    $e = AnlagenService::kopieren($tank, $kennung . ' Tank Kopie');
    $angelegt[] = (int) ($e['id'] ?? 0);
    $kopie = AnlagenService::find((int) ($e['id'] ?? 0));
    gleich([$kopie['art'] ?? null, $kopie['eigenschaften']['inhalt_l'] ?? null, $anzahl('anschluesse', (int) ($e['id'] ?? 0))], ['tank', 2850.0, 0], 'Kopie eines Tanks');

    // ------------------------------------------------ Kabel enden nicht am Tank (B3)
    $vt = (int) $neu(['art' => 'verteilung', 'name' => $kennung . ' Verteilung'])['id'];
    $genAbgang = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND steckertyp_id = ?", [$gen, $typ['cee63']]);
    $vtEingang = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang'", [$vt]);
    $kabel = static fn (array $d): array => LeitungService::speichern(null, $d + ['art' => 'kabel', 'floor_id' => $floorId, 'name' => $kennung,
        'punkte' => [['x' => 10, 'y' => 10], ['x' => 20, 'y' => 20]]]);
    $e = $kabel(['von_objekt_id' => $gen, 'von_anschluss_id' => $genAbgang, 'zu_objekt_id' => $tank]);
    pruefe(!$e['ok'] && $e['message'] === 'Ein Kabel endet nicht an einem Tank.', 'Kabel zum Tank abgelehnt: ' . $e['message']);
    pruefe(!$kabel(['von_objekt_id' => $tank, 'zu_objekt_id' => $vt])['ok'], 'Kabel vom Tank abgelehnt');
    $e = $kabel(['von_objekt_id' => $gen, 'von_anschluss_id' => $genAbgang, 'zu_objekt_id' => $vt, 'zu_anschluss_id' => $vtEingang]);
    pruefe($e['ok'], 'Kabel Generator → Verteilung bleibt möglich: ' . $e['message']);
    $kabelId = (int) ($e['id'] ?? 0);
    pruefe(!LeitungService::endeSetzen($kabelId, 'zu', $tank, [['x' => 10, 'y' => 10], ['x' => 30, 'y' => 30]])['ok']
        && (int) Database::scalar('SELECT zu_objekt_id FROM leitungen WHERE id = ?', [$kabelId]) === $vt, 'ein Kabelende lässt sich nicht auf den Tank ziehen');

    // ------------------------------------------------ Kein Zwischenstecker und kein Verteiler im Tank (B2)
    $zs = (int) InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => $kennung . '-ZS', 'steckertyp_id' => $typ['cee16'],
        'messgeraete' => [['geraet' => $kennung . '-zs', 'kanal' => 'switch:0']]])['id'];
    $e = InventarService::einsetzen($zs, $tank);
    pruefe(!$e['ok'] && $e['message'] === 'In einen Tank gehört kein Zwischenstecker.', 'Zwischenstecker in den Tank abgelehnt: ' . $e['message']);
    gleich([InventarService::einsaetzeAnObjekt($tank), $anzahl('messpunkte', $tank)], [[], 0], '… kein Einsatz, kein Messpunkt am Tank');
    $stueck = (int) InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $kennung . '-PD', 'vorlage_id' => $pd])['id'];
    pruefe(!InventarService::einsetzen($stueck, $tank)['ok'], 'Verteiler in den Tank abgelehnt');
    pruefe(InventarService::einsetzen($zs, $vt)['ok'], 'Gegenprobe: Zwischenstecker in die Verteilung');

    // ------------------------------------------------ Verteilbaum
    $imBaum = [];
    $sammeln = static function (array $knoten) use (&$sammeln, &$imBaum): void {
        foreach ($knoten as $k) {
            $imBaum[] = (int) $k['id'];
            $sammeln($k['kinder']);
        }
    };
    $sammeln(AnlagenService::baum($siteId));
    pruefe(!in_array($tank, $imBaum, true) && in_array($gen, $imBaum, true) && in_array($vt, $imBaum, true), 'Verteilbaum ohne Tank');

    // ------------------------------------------------ Am Tank wird nie geschaltet (B4)
    foreach (['uni' => $tank, 'pm' => $vt] as $name => $objekt) {
        $q = Datenquellen::speichern(null, ['art' => 'shelly', 'name' => $kennung . ' ' . $name, 'geraet' => $kennung . '-' . $name, 'extern_id' => $kennung . '-' . $name]);
        $quellen[$name] = (int) $q['id'];
        Database::insert('messpunkte', ['objekt_id' => $objekt, 'geraet' => $kennung . '-' . $name, 'kanal' => 'switch:0', 'rolle' => 'einspeisung',
            'bezeichnung' => '', 'gueltig_ab' => gmdate('c'), 'created_at' => gmdate('c')]);
    }
    Zugriff::vergessen();
    $tankObjekt = AnlagenService::find($tank);
    $vtObjekt = AnlagenService::find($vt);
    pruefe(LiveService::schaltbar([$vtObjekt]) !== [], 'Gegenprobe: an der Verteilung lässt sich schalten');
    gleich(LiveService::schaltbar([$tankObjekt]), [], 'am Tank gibt es nichts zu schalten');
    gleich(array_column(LiveService::schaltbar([$tankObjekt, $vtObjekt]), 'objekt_id'), [$vt], 'gemischt: nur die Verteilung');
    $uni = Datenquellen::find($quellen['uni']);
    pruefe(!Zugriff::darfSteuern($uni, 'switch:0') && !Zugriff::darfSteuern($uni, 'switch:0', $tankObjekt),
        'ein Shelly im Tank schaltet niemand – auch nicht, wer alles darf');
    pruefe(!Steuerung::ausfuehren($quellen['uni'], 'ein', 'switch:0', $tank)['ok'] && !Steuerung::ausfuehren($quellen['uni'], 'aus', 'switch:0')['ok'],
        'Steuerung lehnt ab – mit und ohne Anlage');

    // ------------------------------------------------ Bereiche zählen keine Tanks
    $tankMp = (int) Database::scalar('SELECT id FROM messpunkte WHERE objekt_id = ?', [$tank]);
    $e = BereichService::speichern(null, ['name' => $kennung . ' Bereich', 'site_id' => $siteId, 'mitglieder' => ['o:' . $tank, 'm:' . $tankMp, 'o:' . $vt]]);
    $bereich = (int) $e['id'];
    gleich(BereichService::mitgliedSchluessel($bereich), ['o:' . $vt], 'ein Bereich nimmt weder den Tank noch sein Messgerät auf');
    Database::insert('bereich_mitglieder', ['bereich_id' => $bereich, 'objekt_id' => $tank, 'messpunkt_id' => null, 'created_at' => gmdate('c')]);
    gleich(array_column(BereichService::aufbau(BereichService::find($bereich)), 'schluessel'), ['o:' . $vt], 'ein Tank als Mitglied (aus älterer Zeit) zählt nicht mit');

    // ------------------------------------------------ Einzelne Eigenschaften setzen
    AnlagenService::eigenschaftSetzen($tank, ['liter_je_mm' => 2.78, 'liter_je_mm_herkunft' => 'Betankung', 'fremd' => 'x', 'bauform' => null]);
    gleich(AnlagenService::find($tank)['eigenschaften'], ['inhalt_l' => 2850.0, 'kraftstoff' => 'Diesel', 'liter_je_mm' => 2.78, 'liter_je_mm_herkunft' => 'Betankung'],
        'eigenschaftSetzen: zusammengeführt, Fremdes weg, null nimmt heraus');
    $audit = Database::first("SELECT details FROM audit_log WHERE action = 'anlage.eigenschaft' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $tank]);
    gleich(json_decode((string) ($audit['details'] ?? ''), true), ['schluessel' => ['liter_je_mm', 'liter_je_mm_herkunft', 'bauform']], 'Audit nennt nur die Schlüssel');

    // ------------------------------------------------ Migration 018
    $spalten = static fn (string $tabelle): array => array_keys(Database::first('SELECT * FROM ' . $tabelle . ' LIMIT 1') ?? []);
    Database::execute('UPDATE inventar SET eigenschaften = ? WHERE id = ?', ['{"prinzip":"hydrostatisch"}', $zs]);
    gleich(Database::scalar('SELECT eigenschaften FROM inventar WHERE id = ?', [$zs]), '{"prinzip":"hydrostatisch"}', 'Migration 018: inventar.eigenschaften');
    Database::execute('UPDATE inventar SET eigenschaften = NULL WHERE id = ?', [$zs]);
    $kennlinie = Database::insert('tank_kennlinien', ['messpunkt_id' => $tankMp, 'objekt_id' => $tank, 'ab' => gmdate('c'), 'rechnung' => '{}',
        'anlass' => 'einsatz', 'created_at' => gmdate('c')]);
    $ereignis = Database::insert('tank_ereignisse', ['objekt_id' => $tank, 'art' => 'peilung', 'zeit' => gmdate('c'), 'prozent' => 60.5, 'created_at' => gmdate('c')]);
    gleich([$spalten('tank_kennlinien'), $spalten('tank_ereignisse')], [
        ['id', 'messpunkt_id', 'objekt_id', 'ab', 'rechnung', 'anlass', 'ereignis_id', 'geprueft', 'von', 'notizen', 'created_at'],
        ['id', 'objekt_id', 'messpunkt_id', 'art', 'zeit', 'liter', 'prozent', 'hoehe_mm', 'roh', 'roh_vor', 'roh_nach', 'lieferschein', 'von', 'notizen', 'created_at'],
    ], 'Migration 018: Spalten von tank_kennlinien und tank_ereignisse');
    gleich([(int) Database::scalar('SELECT geprueft FROM tank_kennlinien WHERE id = ?', [$kennlinie]),
        (float) Database::scalar('SELECT prozent FROM tank_ereignisse WHERE id = ?', [$ereignis])], [0, 60.5], '… mit Vorgaben und Kommazahlen');
    Database::execute('DELETE FROM tank_kennlinien WHERE id = ?', [$kennlinie]);
    Database::execute('DELETE FROM tank_ereignisse WHERE id = ?', [$ereignis]);
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$kennung . '%']) as $i) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), (int) $i['id']]);
        InventarService::loeschen((int) $i['id']);
    }
    Database::execute('DELETE FROM bereich_mitglieder WHERE bereich_id IN (SELECT id FROM bereiche WHERE name LIKE ?)', [$kennung . '%']);
    Database::execute('DELETE FROM bereiche WHERE name LIKE ?', [$kennung . '%']);
    Database::execute('DELETE FROM leitungen WHERE name LIKE ?', [$kennung . '%']);
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    foreach ($quellen as $id) {
        Datenquellen::loeschen($id);
    }
    Database::execute('DELETE FROM schaltvorgaenge WHERE geraet LIKE ?', [$kennung . '%']);
    SiteService::deleteSite($siteId);
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$benutzer]);
    Database::execute('DELETE FROM users WHERE id = ?', [$benutzer]);
    Database::execute('DELETE FROM audit_log WHERE username = ? OR details LIKE ?', [$kennung, '%' . $kennung . '%']);
    foreach ($angelegt as $id) {
        Database::execute('DELETE FROM tank_kennlinien WHERE objekt_id = ?', [(int) $id]);
        Database::execute('DELETE FROM tank_ereignisse WHERE objekt_id = ?', [(int) $id]);
    }
}

// ------------------------------------------------ B – Rechnung (rein, ohne Datenbank)
// Prüfwerte: Plus Uni, Bürde 500 Ω, Sonde 0–100 mbar, Diesel 0,835, Sondenspitze 20 mm über dem Boden.
$sonde = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
    'bereich_einheit' => 'mbar', 'schritt_roh' => 0.1];
$einbau = ['offset_mm' => 20];
$quader = ['inhalt_l' => 3000.0, 'kraftstoff' => 'Diesel', 'bauform' => 'Quader', 'liter_je_mm' => 2.78, 'sumpf_l' => 140.0];
$ohneLiter = ['inhalt_l' => 3000.0, 'kraftstoff' => 'Diesel', 'bauform' => 'Quader'];
$fassung = static fn (array $sensor, array $einbau, array $tank): array => TankRechnung::rechnung($sensor, $einbau, $tank, ['kennung' => 'TS-01'])['rechnung'];

[$teil, $hinweise] = TankRechnung::sensorTeil($sonde, $einbau, 0.835);
nahe($teil['mm_je_roh'], 152.6497, 0.01, 'Sensorteil 500 Ω: mm je V');
gleich([$teil['art'], $teil['roh_anker'], $teil['mm_anker'], $teil['roh_min'], $teil['roh_max'], $teil['signal'], $hinweise],
    ['linear', 2.0, 20.0, 1.8, 10.5, '4-20mA', []], 'Sensorteil 500 Ω: Anker bei 4 mA, Grenzen bei 3,6 und 21 mA');
$r = $fassung($sonde, $einbau, $quader);
gleich(TankRechnung::pruefen($r), null, 'die Fassung ist in sich stimmig');
$u = TankRechnung::umrechnen($r, 7.5);
nahe($u['hoehe_mm'], 859.57, 0.01, '7,5 V: Füllhöhe');
nahe($u['liter'], 2389.61, 0.5, '7,5 V: Liter bei 2,78 l/mm');
nahe($u['prozent'], 79.654, 0.01, '7,5 V: Prozent von 3.000 l');
nahe($u['nutzbar_l'], 2249.61, 0.5, '7,5 V: nutzbar bei 140 l Sumpf');
gleich([$u['fehler'], $u['ueber_grenze']], [null, false], '7,5 V: kein Fehler, unter der Füllgrenze');
nahe(TankRechnung::hoehe($r, 10.0), 1241.20, 0.01, '10 V (20 mA): Füllhöhe am Ende des Messbereichs');
nahe(TankRechnung::schrittLiter($r), 42.44, 0.01, 'Liter je Meldeschritt 0,1 V');
$json = json_decode((string) json_encode($r), true);
pruefe(TankRechnung::pruefen($json) === null && TankRechnung::umrechnen($json, 7.5) == $u, 'nach JSON hin und zurück dieselbe Rechnung');
gleich(TankRechnung::kalibriert($r), true, 'mit Liter je mm kalibriert');
gleich([TankRechnung::dichte(['kraftstoff' => 'HVO']), TankRechnung::dichte(['kraftstoff' => 'HVO', 'dichte_kg_l' => '0,79']), TankRechnung::dichte([])],
    [0.78, 0.79, 0.835], 'Dichte: eingetragen vor Kraftstoff vor Diesel');

$hvo = $fassung($sonde, $einbau, ['kraftstoff' => 'HVO'] + $quader);
nahe($hvo['sensor']['mm_je_roh'], 163.4135, 0.01, 'HVO: mm je V');
nahe(TankRechnung::umrechnen($hvo, 7.5)['hoehe_mm'], 918.77, 0.01, 'HVO: Füllhöhe bei 7,5 V');
$addon = $fassung(['feld' => 'eingang', 'buerde_ohm' => 470] + $sonde, $einbau, $quader);
gleich([$addon['sensor']['roh_anker'], $addon['sensor']['roh_min'], $addon['sensor']['roh_max']], [18.8, 16.92, 98.7], 'Add-on 470 Ω: 4 mA = 18,8 %, Grenzen 16,92 und 98,7 %');
nahe(TankRechnung::hoehe($addon, 94.0), 1241.20, 0.01, 'Add-on 470 Ω: 20 mA = 94 %');
nahe(TankRechnung::umrechnen($addon, 56.4)['hoehe_mm'], 630.60, 0.01, 'Add-on 470 Ω: 56,4 % = 12 mA');

$u = TankRechnung::umrechnen($r, 1.7);
pruefe(str_contains((string) $u['fehler'], 'Leitungsbruch') && $u['liter'] === null && $u['prozent'] === null && $u['hoehe_mm'] === null,
    '1,7 V (unter 3,6 mA): Sensorfehler „Leitungsbruch“, keine Werte – nicht 0 l');
pruefe(str_contains((string) TankRechnung::umrechnen($r, 10.6)['fehler'], 'über 21 mA'), '10,6 V: über 21 mA');
$u = TankRechnung::umrechnen($r, 7.5, true);
pruefe(str_contains((string) $u['fehler'], 'Sensorfehler') && $u['liter'] === null, 'Sensorfehler des Geräts: Fehler, keine Werte');
gleich(TankRechnung::umrechnen($r, null), ['hoehe_mm' => null, 'liter' => null, 'prozent' => null, 'nutzbar_l' => null, 'fehler' => null, 'ueber_grenze' => false],
    'ohne Rohwert: nichts');

// Sonde über einen Wandler „0–20 mA → 0–10 V“ (4 mA = 2 V), 0–2 mWS, Diesel, Sondenspitze auf dem Boden.
$wandler = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA-wandler', 'u_bei_4ma' => '2,0', 'u_bei_20ma' => '10,0',
    'bereich_von' => '0', 'bereich_bis' => '2', 'bereich_einheit' => 'mWS'];
gleich(TankRechnung::sensorPruefen($wandler), null, 'Wandler 2–10 V: Sensordaten in Ordnung');
$aufBoden = ['offset_mm' => 0];
$w = TankRechnung::rechnung($wandler, $aufBoden, $ohneLiter, []);
nahe(TankRechnung::umrechnen($w['rechnung'], 6.0)['hoehe_mm'], 1197.6, 0.05, 'Wandler: 6,00 V = 12 mA = 1000 mm WS = 1197,6 mm Diesel');
gleich(TankRechnung::umrechnen($w['rechnung'], 2.0)['hoehe_mm'], 0.0, 'Wandler: 2,00 V = 4 mA = 0 mm');
foreach ([[1.7, 'unter 3,6 mA'], [0.0, 'Leitungsbruch?'], [10.6, 'über 21 mA']] as [$volt, $text]) {
    $u = TankRechnung::umrechnen($w['rechnung'], $volt);
    pruefe(str_contains((string) $u['fehler'], $text) && $u['hoehe_mm'] === null, 'Wandler: ' . $volt . ' V ist ein Sensorfehler (' . $text . '), nie 0 mm');
}
$u = TankRechnung::umrechnen($w['rechnung'], 10.4);
pruefe($u['fehler'] === null && $u['hoehe_mm'] > 2395, 'Wandler: 10,4 V (20,8 mA) gilt noch, leicht über dem Messbereich');
pruefe(!in_array(TankRechnung::HINWEIS_LEITUNGSBRUCH, $w['hinweise'], true), 'Wandler 2–10 V: Leitungsbruch bleibt erkennbar (kein Hinweis)');
$mitBuerde = TankRechnung::sensorTeil(['signal' => '4-20mA', 'buerde_ohm' => 500] + $wandler, $aufBoden, 0.835)[0];
$mitWandler = TankRechnung::sensorTeil($wandler, $aufBoden, 0.835)[0];
gleich(array_diff_key($mitWandler, ['signal' => 1]), array_diff_key($mitBuerde, ['signal' => 1]), 'Wandler 2–10 V rechnet wie eine Bürde von 500 Ω');
$null = TankRechnung::rechnung(['u_bei_4ma' => 0, 'u_bei_20ma' => 10] + $wandler, $aufBoden, $ohneLiter, []);
nahe(TankRechnung::umrechnen($null['rechnung'], 5.0)['hoehe_mm'], 1197.6, 0.05, 'Wandler 0–10 V: 5,00 V = 12 mA = 1197,6 mm');
$u = TankRechnung::umrechnen($null['rechnung'], 0.0);
pruefe($u['hoehe_mm'] === 0.0 && $u['fehler'] === null, 'Wandler 0–10 V: 0,00 V = 0 mm, kein Fehler');
pruefe(in_array(TankRechnung::HINWEIS_LEITUNGSBRUCH, $null['hinweise'], true) && in_array(TankRechnung::HINWEIS_LEITUNGSBRUCH, TankRechnung::hinweise($null['rechnung']), true),
    '… aber mit dem Hinweis „Leitungsbruch wird so nicht erkannt“ – auch aus der gespeicherten Fassung');
pruefe(TankRechnung::sensorTeil(['u_bei_4ma' => 0] + $wandler, $aufBoden, 0.835)[1] === [TankRechnung::HINWEIS_LEITUNGSBRUCH]
    && TankRechnung::sensorTeil(['u_bei_4ma' => 0.66, 'u_bei_20ma' => 3.3] + $wandler, $aufBoden, 0.835)[1] === [], 'Hinweis im Sensorteil nur unter 0,5 V bei 4 mA');
gleich(array_map([TankRechnung::class, 'wandlerVorschlag'], TankRechnung::WANDLER_ENDWERTE), [0.66, 1.0, 2.0], 'Vorschlag für 4 mA je Endwert des Wandlers (0–20 mA)');
$fuenf = TankRechnung::rechnung(['bereich_bis' => 5] + $wandler, $aufBoden, $ohneLiter, [])['rechnung'];
nahe(TankRechnung::umrechnen($fuenf, 6.0)['hoehe_mm'], 2994.0, 0.05, 'Sonde 0–5 mWS: 6,00 V = 2500 mm WS = 2994,0 mm Diesel');
nahe(TankRechnung::umrechnen($fuenf, 4.072)['hoehe_mm'], 1550.9, 0.05, 'Sonde 0–5 mWS: 4,072 V (8,144 mA) = 1550,9 mm');
$ag3000 = $ohneLiter + ['abmessungen' => '2.400 × 1.200 × 1.548 mm (L × B × H)'];
gleich(TankRechnung::tankHoehe($ag3000), 1548.0, 'Tankhöhe aus „L × B × H“');
gleich(TankRechnung::messbereichAnteil(['bereich_bis' => 5] + $wandler, 1548.0, 0.835), 26, 'Sonde 0–5 mWS im AG 3000: 26 % des Messbereichs');
$hinweise = TankRechnung::rechnung(['bereich_bis' => 5] + $wandler, $aufBoden, $ag3000, [])['hinweise'];
pruefe((bool) preg_grep('/nur etwa 26 % ihres Messbereichs/', $hinweise), 'Hinweis: die Sonde nutzt nur etwa 26 % ihres Messbereichs');
pruefe(!preg_grep('/Messbereichs/', TankRechnung::rechnung($wandler, $aufBoden, $ag3000, [])['hinweise']), 'Sonde 0–2 mWS im AG 3000: kein Hinweis (65 %)');
gleich(TankRechnung::sensorKurz($wandler), 'Hydrostatisch 0–2 mWS, 4–20 mA über Wandler 2–10 V', 'Kurzform mit Wandler');
pruefe(in_array(TankRechnung::HINWEIS_0_10V, TankRechnung::rechnung(['signal' => '0-10V'] + $sonde, $einbau, $quader, [])['hinweise'], true),
    '0–10 V: Hinweis auf den fehlenden Nullpunkt-Schutz');

// Ultraschall von oben: Sensorfläche 1300 mm über dem Boden, Totzone 200 mm.
$ultra = ['prinzip' => 'ultraschall', 'feld' => 'abstand_mm', 'signal' => 'direkt', 'totzone_mm' => 200];
gleich(TankRechnung::sensorPruefen($ultra), null, 'Ultraschall mit Abstand direkt: Sensordaten in Ordnung');
$us = $fassung($ultra, ['d_leer_mm' => 1300], $quader);
nahe(TankRechnung::umrechnen($us, 300.0)['hoehe_mm'], 1000.0, 0.01, 'Ultraschall: Abstand 300 mm = 1000 mm Füllhöhe');
$u = TankRechnung::umrechnen($us, 150.0);
pruefe(str_contains((string) $u['fehler'], 'Totzone') && $u['liter'] === null, 'Ultraschall: Abstand 150 mm bei Totzone 200 ist ein Fehler, keine Werte');
pruefe(str_contains((string) TankRechnung::umrechnen($us, 1400.0)['fehler'], 'Abstand größer als der Tank'), 'Ultraschall: Abstand größer als der Tank');
$ohneMass = TankRechnung::rechnung($ultra, [], $quader, []);
gleich([$ohneMass['rechnung']['sensor']['art'], in_array(TankRechnung::HINWEIS_NUR_ROH, $ohneMass['hinweise'], true), TankRechnung::umrechnen($ohneMass['rechnung'], 300.0)['hoehe_mm']],
    ['roh', true, null], 'Ultraschall ohne Einbaumaß: nur Rohwert, mit Hinweis');
$ultra420 = ['prinzip' => 'ultraschall', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 200, 'bereich_bis' => 2000, 'bereich_einheit' => 'mm'];
$us420 = $fassung($ultra420, ['d_leer_mm' => 2100], $quader);
gleich([TankRechnung::hoehe($us420, 2.0), TankRechnung::hoehe($us420, 10.0), TankRechnung::umrechnen($us420, 6.0)['hoehe_mm']], [1900.0, 100.0, 1000.0],
    'Ultraschall 4–20 mA (200–2000 mm): 4 mA = 1900 mm, 20 mA = 100 mm, 12 mA = 1000 mm');
$schwimmer = $fassung(['prinzip' => 'schwimmer', 'feld' => 'fuellhoehe_mm', 'signal' => 'direkt'], [], $quader);
nahe(TankRechnung::umrechnen($schwimmer, 500.0)['liter'], 1390.0, 0.01, 'Füllhöhe direkt: 500 mm × 2,78 l/mm');

// Liegender Zylinder D 1000 mm, L 2000 mm.
$zylinder = ['bauform' => 'Zylinder liegend', 'durchmesser_mm' => 1000, 'laenge_innen_mm' => 2000, 'inhalt_l' => 1570.80];
$tz = TankRechnung::tankTeil($zylinder);
nahe(TankRechnung::liter($tz, 250.0), 307.09, 0.01, 'liegender Zylinder: 250 mm = 307,09 l');
nahe(TankRechnung::liter($tz, 1000.0), 1570.80, 0.01, 'liegender Zylinder: voll');
$u = TankRechnung::umrechnen($fassung(['prinzip' => 'schwimmer', 'feld' => 'fuellhoehe_mm', 'signal' => 'direkt'], [], $zylinder), 250.0);
nahe($u['prozent'], 19.550, 0.001, 'liegender Zylinder: 250 mm = 19,550 %');
foreach ([100.0, 500.0, 900.0] as $h) {
    nahe(TankRechnung::hoeheAusLiter($tz, (float) TankRechnung::liter($tz, $h)), $h, 0.5, 'liegender Zylinder: Höhe aus Litern bei ' . $h . ' mm');
}
$tzOhne = TankRechnung::tankTeil(['bauform' => 'Zylinder liegend', 'inhalt_l' => 1570.80]);
gleich([$tzOhne['form'], TankRechnung::liter($tzOhne, 250.0)], ['zylinder_liegend', null], 'liegender Zylinder ohne Maße: keine Liter (und keine falsche Steigung)');
nahe(TankRechnung::tankTeil(['bauform' => 'Zylinder stehend', 'durchmesser_mm' => 1000])['liter_je_mm'], 0.785398, 0.000001, 'stehender Zylinder: Liter je mm aus dem Durchmesser');
gleich([TankRechnung::tankTeil($ohneLiter)['form'], TankRechnung::tankTeil($ohneLiter)['sumpf_l'], TankRechnung::tankTeil($ohneLiter)['grenze_prozent']],
    ['unbekannt', 150.0, 95.0], 'Quader ohne Liter je mm: Form unbekannt; Sumpf 5 %, Füllgrenze 95 %');
$u = TankRechnung::umrechnen($fassung($sonde, $einbau, $ohneLiter), 7.5);
gleich([$u['liter'], $u['prozent'], $u['fehler']], [null, null, null], 'ohne Liter je mm: nur Füllhöhe, keine Schätzung');

// Prozent werden nicht gekappt.
$literSensor = ['prinzip' => 'direkt', 'feld' => 'tank_liter', 'signal' => 'direkt', 'direkt_einheit' => 'liter'];
$direkt = $fassung($literSensor, [], $quader);
$u = TankRechnung::umrechnen($direkt, 3000.0);
gleich([$u['prozent'], $u['ueber_grenze'], $u['fehler']], [100.0, true, null], '100 %: über der Füllgrenze, kein Fehler');
$u = TankRechnung::umrechnen($direkt, 3300.0);
gleich([$u['prozent'], $u['ueber_grenze']], [110.0, true], '110 % bleibt 110 % (nicht gekappt)');
$u = TankRechnung::umrechnen($direkt, 3180.0);
pruefe(str_contains((string) $u['fehler'], 'unplausibel') && $u['prozent'] === 106.0 && $u['liter'] === 3180.0, '106 %: „unplausibel“, die Werte bleiben');
gleich(TankRechnung::umrechnen($fassung(['feld' => 'tank_prozent', 'direkt_einheit' => 'prozent'] + $literSensor, [], $quader), 60.0)['liter'], 1800.0,
    'Füllstand in % vom Gerät: Liter aus dem Nennvolumen');

// Fassungen prüfen.
foreach (['500 Ω' => $r, 'HVO' => $hvo, 'Add-on' => $addon, 'Wandler' => $w['rechnung'], 'Wandler 0–10 V' => $null['rechnung'], 'Ultraschall' => $us,
    'Ultraschall ohne Einbaumaß' => $ohneMass['rechnung'], 'Ultraschall 4–20 mA' => $us420, 'Schwimmer' => $schwimmer, 'direkt' => $direkt,
    'ohne Liter je mm' => $fassung($sonde, $einbau, $ohneLiter)] as $was => $k) {
    gleich(TankRechnung::pruefen($k), null, 'pruefen() nimmt jede gebaute Fassung an: ' . $was);
}
$kaputt = [
    'fremder Schlüssel'        => $r + ['fremd' => 1],
    'fremder Schlüssel im Sensorteil' => array_replace_recursive($r, ['sensor' => ['extra' => 1]]),
    'unendlich'                => array_replace_recursive($r, ['sensor' => ['mm_je_roh' => INF]]),
    '1e999 aus JSON'           => json_decode(str_replace('"mm_je_roh":152.649701', '"mm_je_roh":1e999', (string) json_encode($r)), true),
    'Steigung 0'               => array_replace_recursive($r, ['sensor' => ['mm_je_roh' => 0.0]]),
    'Liter je mm 0'            => array_replace_recursive($r, ['tank' => ['liter_je_mm' => 0.0]]),
    'Liter je mm negativ'      => array_replace_recursive($r, ['tank' => ['liter_je_mm' => -2.78]]),
    'Nennvolumen 0'            => array_replace_recursive($r, ['tank' => ['nenn_l' => 0.0]]),
    'Text statt Zahl'          => array_replace_recursive($r, ['sensor' => ['roh_anker' => '2']]),
    'Herkunft mit Code'        => array_replace_recursive($r, ['herkunft' => ['skript' => '<script>']]),
    'kein Objekt'              => 'abc',
];
foreach ($kaputt as $was => $k) {
    pruefe(TankRechnung::pruefen($k) !== null, 'pruefen() lehnt ab: ' . $was);
}

// Sensordaten prüfen.
$abgelehnt = [
    '4–20 mA an u ohne Bürde'   => ['buerde_ohm' => ''] + $sonde,
    'Bürde 5000 Ω'              => ['buerde_ohm' => 5000] + $sonde,
    'Messbereich bis ≤ von'     => ['bereich_von' => 100, 'bereich_bis' => 100] + $sonde,
    'direkt ohne Einheit'       => ['prinzip' => 'direkt', 'feld' => 'x', 'signal' => 'direkt'],
    'Ultraschall in mbar'       => ['prinzip' => 'ultraschall'] + $sonde,
    'Wandler ohne Spannungen'   => ['u_bei_4ma' => '', 'u_bei_20ma' => ''] + $wandler,
    'Wandler 20 mA unter 4 mA'  => ['u_bei_4ma' => 10, 'u_bei_20ma' => 2] + $wandler,
    'Wandler am Analogeingang'  => ['feld' => 'eingang'] + $wandler,
    'Messbereich fehlt'         => TankRechnung::SENSOR_VORGABE,
    'Prozent ohne „direkt“'     => ['feld' => 'tank_prozent'] + $sonde,
    'Abstand hydrostatisch'     => ['feld' => 'abstand_mm', 'signal' => 'direkt'] + $sonde,
    'Zahl mit Text'             => ['bereich_bis' => '100 mbar'] + $sonde,
    'unendlich'                 => ['bereich_bis' => '1e999'] + $sonde,
];
foreach ($abgelehnt as $was => $e) {
    pruefe(TankRechnung::sensorPruefen($e) !== null, 'sensorPruefen() lehnt ab: ' . $was);
}
pruefe(str_contains((string) TankRechnung::sensorPruefen(TankRechnung::SENSOR_VORGABE), 'Messbereich'), 'Vorgabe ohne Messbereich: Pflicht, kein stiller Wert');
gleich(TankRechnung::sensorPruefen(['bereich_bis' => '2'] + TankRechnung::SENSOR_VORGABE), null, 'Vorgabe mit Messbereich bis 2 mWS: in Ordnung');
$normal = TankRechnung::sensorNormal(['fremd' => 'x', 'buerde_ohm' => '499', 'hinweis' => '  Uni im Schatten  '] + $wandler);
gleich(array_keys($normal), ['prinzip', 'feld', 'signal', 'buerde_ohm', 'u_bei_4ma', 'u_bei_20ma', 'bereich_von', 'bereich_bis', 'bereich_einheit',
    'direkt_einheit', 'totzone_mm', 'schritt_roh', 'hinweis'], 'sensorNormal: feste Schlüssel, Fremdes weg');
gleich([$normal['buerde_ohm'], $normal['u_bei_4ma'], $normal['bereich_bis'], $normal['schritt_roh'], $normal['hinweis']], [null, 2.0, 2.0, 0.05, 'Uni im Schatten'],
    'sensorNormal: Bürde passt nicht zum Wandler, Zahlen als Float, Meldeschritt nach Feld');
gleich(TankRechnung::sensorNormal($normal), $normal, 'sensorNormal ist stabil');
gleich(TankRechnung::sensorKurz($sonde), 'Hydrostatisch 0–100 mbar, 4–20 mA an 500 Ω', 'Kurzform der Sensordaten');
gleich(array_map([TankRechnung::class, 'feldVorschlag'], ['voltmeter:100', 'adc:0', 'input:100', 'bthome:200']), ['u', 'u', 'eingang', 'abstand_mm'],
    'Feld nach Kanal vorgeschlagen');
pruefe(TankRechnung::einbauPruefen(['offset_mm' => '20']) === null && TankRechnung::einbauPruefen(['offset_mm' => '-5']) !== null
    && TankRechnung::einbauPruefen(['d_leer_mm' => 'hoch']) !== null, 'Einbaumaß geprüft');

// Verbrauch je Last.
gleich(TankRechnung::kennlinieLesen('100% 22L / 70% 16L / 50% 12L'), [[50, 12], [70, 16], [100, 22]], 'Kennlinie aus der Strombibel gelesen');
gleich(TankRechnung::kennlinieLesen('100% 33L / 70% 26,5L / 50% 24L')[1][1], 26.5, 'Kennlinie mit Komma');
gleich([TankRechnung::kennlinieLesen(''), TankRechnung::kennlinieLesen('22')], [[], [[100, 22]]], 'leer bzw. nur ein Wert (Volllast)');
$k = TankRechnung::kennlinieLesen('100% 22L / 70% 16L / 50% 12L');
gleich([TankRechnung::verbrauch($k, null, 60.0), TankRechnung::verbrauch($k, null, 30.0), TankRechnung::verbrauch($k, null, 110.0)],
    [['l_h' => 14.0, 'geschaetzt' => false], ['l_h' => 12.0, 'geschaetzt' => false], ['l_h' => 24.0, 'geschaetzt' => false]], 'Verbrauch bei 60/30/110 % Last');
nahe(TankRechnung::verbrauch(TankRechnung::kennlinieLesen('100% 33L / 70% 26,5L / 50% 24L'), null, 60.0)['l_h'] ?? null, 25.25, 0.001, 'Verbrauch 60 % (zweite Kennlinie)');
gleich([TankRechnung::verbrauch([], 22.0, 60.0), TankRechnung::verbrauch([], null, 60.0), TankRechnung::verbrauch($k, null, null)],
    [['l_h' => 22.0, 'geschaetzt' => true], null, ['l_h' => 12.0, 'geschaetzt' => true]], 'ohne Kennlinie Volllast (geschätzt), ohne Last der kleinste Punkt');
gleich([TankRechnung::last(48000.0, 100.0), TankRechnung::last(null, 100.0), TankRechnung::last(1000.0, 0.0)], [60.0, null, null], 'Last: P / (kVA · 800 W)');
$t0 = 1_760_000_000_000;
nahe(TankRechnung::gefaelle(array_map(static fn (int $i): array => [$t0 + $i * 600_000, 2000.0 - 20.0 * $i / 6], range(0, 36))), 20.0, 0.001, 'Gefälle: 20 l/h');
gleich(TankRechnung::gefaelle([[$t0, 1.0], [$t0 + 1000, 2.0]]), null, 'Gefälle: zu wenig Punkte');

// Fassungen über der Zeit: es gilt die größte id unter allen mit ab ≤ t.
[$ta, $tb, $tc] = [1000, 2000, 3000];
$k1 = ['id' => 11, 'ab' => $ta, 'rechnung' => ['k' => 1]];
$k2 = ['id' => 12, 'ab' => $tb, 'rechnung' => ['k' => 2]];
$k3 = ['id' => 13, 'ab' => $ta, 'rechnung' => ['k' => 3]];
$k4 = ['id' => 14, 'ab' => $tc, 'rechnung' => ['k' => 4]];
gleich(TankRechnung::abschnitte([$k1, $k2, $k3], 0, 9000), [[$ta, 9000, ['k' => 3], 13]], 'rückwirkende k3 gilt ab t0, k2 nirgends, vor t0 nichts');
gleich(TankRechnung::abschnitte([$k1, $k2, $k3, $k4], 0, 9000), [[$ta, $tc, ['k' => 3], 13], [$tc, 9000, ['k' => 4], 14]], 'danach k4 ab t2');
gleich(TankRechnung::abschnitte([$k1, $k2], 1500, 2500), [[1500, $tb, ['k' => 1], 11], [$tb, 2500, ['k' => 2], 12]], 'Ausschnitt mitten im Zeitraum');
gleich([TankRechnung::abschnitte([$k1], 0, 900), TankRechnung::abschnitte([], 0, 9000)], [[], []], 'vor der ersten Fassung und ohne Fassung: nichts');

// Sprung einer Betankung: 2,6 V bis 10:00, Rampe bis 10:20, danach 7,4 V – Punkte je Minute.
$punkte = [];
$t10 = 1_760_608_800_000;
for ($m = -180; $m <= 60; $m++) {
    $wert = $m <= 0 ? 2.6 : ($m >= 20 ? 7.4 : 2.6 + 4.8 * $m / 20);
    $punkte[] = [$t10 + $m * 60_000, $wert];
}
$a = TankRechnung::anstieg($punkte, $r);
pruefe($a !== null && abs($a['roh_vor'] - 2.6) < 0.01 && abs($a['roh_nach'] - 7.4) < 0.01 && $a['t_vor'] <= $t10 && $a['t_nach'] >= $t10 + 1_200_000,
    'Betankung erkannt: vorher 2,6 V, nachher 7,4 V');
$rauschen = array_map(static fn (array $p): array => [$p[0], 5.0 + (intdiv($p[0], 60_000) % 3 - 1) * 0.1], $punkte);
gleich(TankRechnung::anstieg($rauschen, $r), null, 'nur Rauschen ±0,1 V: keine Betankung');
$verrauscht = array_map(static fn (array $p): array => [$p[0], $p[1] + (intdiv($p[0], 60_000) % 3 - 1) * 0.1], $punkte);
$a = TankRechnung::anstieg($verrauscht, $r);
pruefe($a !== null && abs($a['roh_vor'] - 2.6) <= 0.1 && abs($a['roh_nach'] - 7.4) <= 0.1, 'Betankung mit Rauschen: Median davor und danach');
$fallend = array_map(static fn (array $p): array => [$p[0], 1300.0 - ($p[1] - 2.6) / 4.8 * 700], $punkte);
$a = TankRechnung::anstieg($fallend, $us);
pruefe($a !== null && abs($a['roh_vor'] - 1300) < 1 && abs($a['roh_nach'] - 600) < 1, 'Ultraschall: fallender Abstand ist ein Anstieg');
gleich(TankRechnung::anstieg($fallend, $r), null, 'gleiche Punkte an einer Sonde: das ist kein Anstieg');

// Peilung.
$ru = $fassung($sonde, $einbau, $ohneLiter);
$p = TankRechnung::peilen($ru, 6.0, ['prozent' => '60']);
nahe($p['liter_je_mm'] ?? null, 2.85443, 0.0001, 'Peilung 60 % bei unbekanntem Inhalt je mm: Liter je mm');
nahe(TankRechnung::umrechnen($p['rechnung'], 6.0)['liter'], 1800.0, 0.05, '… und bei diesem Rohwert 1.800 l');
gleich([$p['rechnung']['sensor'], $p['rechnung']['tank']['form']], [$ru['sensor'], 'quader'], '… der Sensorteil bleibt');
$p = TankRechnung::peilen($r, 6.0, ['liter' => 1800]);
gleich($p['rechnung']['sensor']['roh_anker'], 6.0, 'Peilung 1.800 l bei bekannten 2,78 l/mm: Anker bei 6,0 V');
nahe($p['rechnung']['sensor']['mm_anker'], 647.48, 0.01, '… auf 647,48 mm');
nahe(TankRechnung::umrechnen($p['rechnung'], 7.0)['liter'], 2224.37, 0.5, '… Liter bei 7,0 V');
gleich([$p['rechnung']['sensor']['mm_je_roh'], $p['rechnung']['tank']], [$r['sensor']['mm_je_roh'], $r['tank']], '… Steigung und Tank bleiben');
$p = TankRechnung::peilen($r, 6.0, ['hoehe_mm' => '650']);
gleich([$p['rechnung']['sensor']['roh_anker'], $p['rechnung']['sensor']['mm_anker']], [6.0, 650.0], 'Peilstab 650 mm: Anker (6,0 V; 650 mm)');
pruefe(str_contains((string) (TankRechnung::peilen($ru, 2.4, ['liter' => 200])['fehler'] ?? ''), 'Zu wenig drin'), 'unter 100 mm Füllhöhe: „zu wenig drin“');
pruefe(isset(TankRechnung::peilen($r, 6.0, ['liter' => 1800, 'prozent' => 60])['fehler'], TankRechnung::peilen($r, 6.0, [])['fehler'],
    TankRechnung::peilen($r, 6.0, ['prozent' => 120])['fehler'], TankRechnung::peilen($r, 6.0, ['hoehe_mm' => -1])['fehler']),
    'Peilung: genau eine Angabe in ihren Grenzen');
pruefe(str_contains((string) (TankRechnung::peilen($r, 1.0, ['hoehe_mm' => 650])['fehler'] ?? ''), 'nur protokolliert'), 'Peilung bei Sensorfehler: nur protokolliert');
$p = TankRechnung::peilen($direkt, 1860.0, ['liter' => 1800]);
pruefe(!isset($p['rechnung']) && abs(($p['abweichung'] ?? 0) - 3.3) < 0.05, 'Gerät liefert den Füllstand: nur die Abweichung (+3,3 %)');
$us2 = TankRechnung::peilen($us, 300.0, ['hoehe_mm' => 1050])['rechnung'];
gleich([TankRechnung::umrechnen($us2, 300.0)['hoehe_mm'], $us2['sensor']['mm_max']], [1050.0, 1150.0], 'Ultraschall: Peilstab verschiebt Anker und Totzone');

// Zwei Peilungen mit dem Peilstab (Wandler 2–10 V, 0–2 mWS, Diesel: aus den Sensordaten 299,4 mm/V).
$rw = $w['rechnung'];
nahe($rw['sensor']['mm_je_roh'], 299.40, 0.01, 'Sensordaten: 299,4 mm je V');
$zp = TankRechnung::zweiPeilungen($rw, ['roh' => 3.0, 'hoehe_mm' => 300], ['roh' => 5.7, 'hoehe_mm' => 1100], $rw['sensor']['mm_je_roh']);
nahe($zp['mm_je_roh'] ?? null, 296.30, 0.01, 'zwei Peilungen 300 mm / 1100 mm: 296,30 mm je V');
pruefe(array_key_exists('warnung', (array) $zp) && $zp['warnung'] === null, '… keine Warnung (−1 %)');
pruefe(abs(TankRechnung::umrechnen($zp['rechnung'], 5.7)['hoehe_mm'] - 1100) < 0.01 && abs(TankRechnung::umrechnen($zp['rechnung'], 3.0)['hoehe_mm'] - 300) < 0.01,
    '… die neue Fassung trifft beide Peilungen');
$zp = TankRechnung::zweiPeilungen($rw, ['roh' => 3.0, 'hoehe_mm' => 300], ['roh' => 6.6, 'hoehe_mm' => 1100], $rw['sensor']['mm_je_roh']);
nahe($zp['mm_je_roh'] ?? null, 222.22, 0.01, 'zwei Peilungen bei 3,0 / 6,6 V: 222,22 mm je V');
pruefe(abs(($zp['abweichung'] ?? 0) + 25.8) < 0.05 && str_contains((string) ($zp['warnung'] ?? ''), 'Messbereich, Dichte oder Wandler prüfen'), '… Warnung bei −25,8 %');
gleich(TankRechnung::zweiPeilungen($rw, ['roh' => 3.0, 'hoehe_mm' => 300], ['roh' => 3.9, 'hoehe_mm' => 590], 299.4), null, 'unter 300 mm Abstand: kein Angebot');

// Betankung: 2,6 → 7,4 V sind 732,72 mm.
$b = TankRechnung::betanken($ru, 2.6, 7.4, 2036.96);
nahe($b['liter_je_mm'] ?? null, 2.78, 0.0001, 'Betankung 2.036,96 l zwischen 2,6 und 7,4 V: 2,78 l/mm');
gleich([$b['rechnung']['tank']['form'], $b['rechnung']['sensor']], ['quader', $ru['sensor']], '… der Tank lernt, der Sensorteil bleibt');
pruefe(str_contains((string) (TankRechnung::betanken($ru, 2.6, 3.1, 500.0)['fehler'] ?? ''), 'Kein deutlicher Anstieg'), 'Anstieg 0,5 V (unter 10 Schritten): abgelehnt');
$b = TankRechnung::betanken($r, 2.6, 7.4, 2.78 * 1.3 * 732.7186);
pruefe(isset($b['fehler']) && !isset($b['rechnung']) && abs(($b['abweichung'] ?? 0) - 30) < 0.1, '30 % mehr als die bekannten 2,78 l/mm: abgelehnt');
$b = TankRechnung::betanken($r, 2.6, 7.4, 2.78 * 1.3 * 732.7186, true);
nahe($b['liter_je_mm'] ?? null, 3.614, 0.001, '… mit „trotzdem“ übernommen');
pruefe(isset(TankRechnung::betanken($r, 2.6, 7.4, 10.0)['fehler']), 'unplausible Liter je mm abgelehnt');
$b = TankRechnung::betanken($fassung(['prinzip' => 'schwimmer', 'feld' => 'fuellhoehe_mm', 'signal' => 'direkt', 'schritt_roh' => 10], [], $zylinder), 250.0, 500.0, 500.0);
pruefe(!isset($b['rechnung']) && isset($b['abweichung']), 'liegender Zylinder: nur die Abweichung der Kennlinie');

// Stufen (Tabelle §8); Grenzen aus den Einstellungen, Tank-Eigenschaften gehen vor.
$jetzt = 1_760_608_800;
$basis = ['sensor' => true, 'zeit' => ($jetzt - 600) * 1000, 'alter_s' => 600, 'online' => true, 'prozent' => 50.0, 'liter' => 1500.0,
    'nutzbar_l' => 1350.0, 'hoehe_mm' => 540.0, 'ueber_grenze' => false, 'grenze_prozent' => 95.0, 'jetzt' => $jetzt];
$st = static fn (array $t, array $tank = []): array => TankRechnung::stufe($t + $basis, TankRechnung::GRENZEN, $tank);
foreach ([1.7, 10.6] as $volt) {
    $s = TankRechnung::stufe(array_merge($basis, TankRechnung::umrechnen($r, $volt)), TankRechnung::GRENZEN);
    pruefe($s['stufe'] === 'bad' && str_contains($s['hinweis'], 'Sensorfehler') && !str_contains($s['hinweis'], 'leer'), 'Sensorfehler ' . $volt . ' V: rot, nie „leer“');
}
gleich($st(['prozent' => 9.0])['stufe'], 'bad', '9 %: rot');
gleich($st(['prozent' => 22.0])['stufe'], 'warn', '22 %: gelb');
gleich([$st(['alter_s' => 30 * 3600, 'online' => null])['stufe'], $st(['online' => false])['stufe']], ['stumm', 'stumm'], '30 h ohne Lebenszeichen und offline: stumm');
$s = $st(['alter_s' => 30 * 3600, 'zeit' => ($jetzt - 30 * 3600) * 1000]);
pruefe($s['stufe'] === 'ok' && str_contains($s['hinweis'], 'vor 1 Tag'), '30 h alt, Gerät online, 50 %: ok „vor 1 Tag“ – ' . $s['hinweis']);
$s = $st(['alter_s' => 10 * 3600, 'verbrauch_l_h' => 14.0, 'schritt_l' => 42.4, 'generatoren' => [['id' => 1, 'name' => 'Aggregat 1', 'laeuft' => true]]]);
pruefe($s['stufe'] === 'warn' && str_contains($s['hinweis'], 'meldet nicht, obwohl Aggregat 1 läuft'), 'Generator läuft, seit 10 h kein Wert: gelb „meldet nicht“');
gleich($st(['alter_s' => 8 * 3600, 'verbrauch_l_h' => 14.0, 'schritt_l' => 42.4, 'generatoren' => [['name' => 'Aggregat 1', 'laeuft' => true]]])['stufe'], 'ok',
    '… nach 8 h (unter 3 Meldeschritten) noch ok');
$s = $st(['prozent' => 96.0, 'ueber_grenze' => true]);
pruefe($s['stufe'] === 'warn' && str_contains($s['hinweis'], 'Füllgrenze 95 %'), '96 %: gelb „über der Füllgrenze“');
gleich($st(['prozent' => 8.0, 'alter_s' => 30 * 3600, 'online' => null])['stufe'], 'bad', 'ein alter Wert mit 8 % bleibt rot');
gleich([$st(['prozent' => 15.0], ['alarm_prozent' => 20])['stufe'], $st(['prozent' => 15.0])['stufe']], ['bad', 'warn'], 'Alarm des Tanks (20 %) schlägt die Einstellung (10 %)');
gleich([$st(['restlaufzeit_h' => 3.0])['stufe'], $st(['restlaufzeit_h' => 10.0])['stufe'], $st(['nutzbar_l' => 0.0])['stufe']], ['bad', 'warn', 'bad'],
    'Restlaufzeit 3 h rot, 10 h gelb; nichts entnehmbar rot');
pruefe(str_contains($st(['restlaufzeit_h' => 3.5])['hinweis'], 'noch 3,5 h'), 'Restlaufzeit im Hinweis');
gleich($st(['nachbestellen_bis' => gmdate('c', $jetzt - 60)])['stufe'], 'warn', 'nachbestellen vorbei: gelb');
gleich($st(['gesperrt' => true]), ['stufe' => 'gesperrt', 'hinweis' => 'Livewerte außerhalb des freigegebenen Zeitraums'], 'außerhalb des Datenfensters: gesperrt');
gleich($st(['sensor' => false]), ['stufe' => 'keine', 'hinweis' => 'kein Tanksensor eingesetzt'], 'ohne Sensor: keine');
$s = $st(['sensor' => false, 'peilung' => ['zeit' => gmdate('c', $jetzt - 3 * 3600), 'prozent' => 60.0, 'liter' => 1800.0]]);
pruefe($s['stufe'] === 'keine' && str_contains($s['hinweis'], 'Peilung') && str_contains($s['hinweis'], '60 % (≈ 1.800 l)') && str_contains($s['hinweis'], 'vor 3 h'),
    'ohne Sensor: die letzte Peilung mit Alter – ' . $s['hinweis']);
gleich($st(['zeit' => null, 'alter_s' => null, 'prozent' => null, 'liter' => null])['stufe'], 'stumm', 'kein Wert seit dem Einbau: stumm');
$s = $st(['liter' => null, 'prozent' => null, 'nutzbar_l' => null, 'hoehe_mm' => 860.0]);
pruefe($s['stufe'] === 'ok' && str_contains($s['hinweis'], 'Füllhöhe 860 mm – Inhalt unbekannt: peilen'), 'ohne Kalibrierung: Füllhöhe und „peilen“');
gleich(TankRechnung::grenzen(['tank_warnung' => '30', 'tank_alarm' => 'x'], ['warnung_prozent' => 40.0])['tank_warnung'], 40.0, 'Grenzen: Tank vor Einstellung');
gleich(array_map([TankRechnung::class, 'alterText'], [30, 37 * 60, 3 * 3600, 30 * 3600, 2 * 86400]), ['gerade eben', 'vor 37 min', 'vor 3 h', 'vor 1 Tag', 'vor 2 Tagen'],
    'Alter als Text');

// Rohwerte haben Beschriftungen.
foreach (array_keys(TankRechnung::ROHFELDER) as $feld) {
    pruefe(Messdaten::feldName($feld) !== $feld, 'Beschriftung für ' . $feld);
}
gleich([Messdaten::feldName('sensorfehler'), Messdaten::einheit('i_ma'), Messdaten::feldName('tank_liter'), Messdaten::einheit('tank_liter'), Messdaten::einheit('x')],
    ['Sensorfehler', 'mA', 'Tankinhalt', 'l', ''], 'Zustandsfelder der Tanksensoren');

ende('tanks');
