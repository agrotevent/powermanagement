<?php
declare(strict_types=1);

/**
 * Tanks und Tanksensoren.
 *
 * A – Art ohne Strom: keine Anschlüsse und Schutzorgane, feste elektrische
 * Felder, kein Wechsel zwischen elektrischer und nicht elektrischer Art,
 * kein Kabel und kein Zwischenstecker am Tank, nicht im Verteilbaum, in
 * Bereichen und beim Schalten; Kurzform, Katalog-Typ, Eigenschaften.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Zugriff;
use App\Messung\Datenquellen;
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
}

ende('tanks');
