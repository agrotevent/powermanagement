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
 *
 * C – Tanksensor im Inventar: Sensordaten und genau ein Messkanal, Einbau nur
 * in einen Tank (Messpunkt „Füllstand“, erste Fassung ab Einbau, ungeprüft),
 * ein neuer ersetzt den alten, Abbau, Wiedereinsatz woanders; neue Fassungen
 * bei Shelly-Tausch, geänderten Sensordaten und Tankdaten (sonst keine);
 * ältere Fassungen bleiben unverändert; Messpunkte am Tank nur aus dem
 * Inventar; Löschen von Messpunkt und Tank räumt Fassungen und Ereignisse weg.
 *
 * D – Live und Verlauf (Demo-Modus, Demo-Sonde shellyplusuni-tank1): Füllstand
 * live ab Einbau mit der jetzigen Fassung, Stufe, Grenzen aus den
 * Einstellungen; Verlauf je Fassung (Liter erst mit Inhalt je mm, Rohwert
 * durchgehend), Kunde mit Datenfenster (Live gesperrt, Verlauf, Fassungen und
 * Ereignisse nur im Fenster); Tank ohne Sensor mit letzter Peilung.
 *
 * E – Kalibrieren vor Ort (Demo-Modus): Peilung mit dem Rohwert vom Server
 * (Anzeige, Inhalt, Peilstab; bei unbekanntem Inhalt je mm lernt der Tank),
 * Tank ohne Sensor, Betankung (jetzt: erst ab 30 min auswertbar; um 06:00
 * aus dem Sprung der Demo-Sonde kalibriert, Inhalt je mm im Tank mit
 * Herkunft), Löschen nur der jüngsten Kalibrierung (Fassung „zurueck“,
 * Inhalt je mm wie vorher), Steigung aus zwei Peilungen nur auf Wunsch, Anker
 * und Steigung von Hand (rückwirkend nur ohne Datengrenze, keine fremden
 * Schlüssel, nur endliche Zahlen), Rechte; Fassungen werden nie geändert.
 *
 * F – Kraftstoffschlauch und Versorgung (Demo-Modus): Schlauch nur zwischen
 * Tank und Generator (verkehrt herum getauscht, ohne Anschlüsse, Vorgaben
 * Vor- und Rücklauf und HK-A3, Enden umstecken, nicht lösen), Hinweise
 * „Rücklauf fehlt?“ und „Rücklauf muss in denselben Tank“, nicht in Belegung
 * und Speisung; Verbrauch aus Kennlinie × Last (80 % → 18 l/h), Vorrat zweier
 * Tanks, Restlaufzeit, leer um, nachbestellen bis, Stufen (rot, „meldet
 * nicht“), Generator steht, ohne Kennlinie, ohne Livewert, Demo-Aggregat im
 * zweiten Durchgang; ein Generator ohne Freigabe verrät weder Namen noch
 * Leistung; Verbrauch aus dem Pegel; Betankung mit abgezogenem Verbrauch;
 * Material mit Schläuchen und Kupplungen.
 *
 * G – Verbrauch je Tag: Anfang + getankt − Ende je Kalendertag (rein, Prüfwerte
 * §7.5: −20 l/h mit +1.500 l, Lieferschein ersetzt den Sprung, Rauschen, der
 * 25.10.2026 mit 25 h), Meldungen nur bei Änderung, Lücken, eingetragen ohne
 * Messwerte bzw. ohne Sprung, Zapfpause, über Mitternacht, Wärme; im
 * Demo-Modus über TankService::tage (erkannte Betankung um 06:00, eintragen,
 * Lieferschein, Kunde mit Datenfenster, ohne Kalibrierung, ohne Sensor).
 *
 * H – Kommandozeile und Demo: tank:liste nur mit --als (gesperrtes und
 * unbekanntes Konto, Standort), Zeile mit Liter, %, Restlaufzeit und Grund,
 * --warnungen mit Rückgabewert, Kunde nur mit seiner Freigabe und im
 * Datenfenster; tank:rechnen mit den Prüfwerten aus B; das Demo-Gelände in
 * eigener Instanz (Tank-Typen aus dem Katalog, TS-01 mit zwei Fassungen,
 * Betankung DEMO-0815, Reservetank mit Peilung, TS-02 im Lager).
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Zugriff;
use App\Berichte\BerichtService;
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
use App\Services\MaterialService;
use App\Services\MesspunktService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Messung\Demo;
use App\Support\Config;
use App\Support\Database;
use App\Support\Zeit;
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
// Verbrauch aus dem Pegel: nur ab der letzten Betankung, erst ab drei Meldeschritten Abfall in den 6 h
$pegel = static fn (callable $liter): array => array_map(static fn (int $i): array => [$t0 + $i * 600_000, $liter($i)], range(0, 36));
nahe(TankRechnung::pegelVerbrauch($pegel(static fn (int $i): float => 2000.0 - 20.0 * $i / 6), 40.0, 3000.0), 20.0, 0.001, 'Pegel: 20 l/h über 6 h (120 l = 3 Schritte zu 40 l)');
nahe(TankRechnung::pegelVerbrauch($pegel(static fn (int $i): float => $i < 12 ? 1000.0 - 20.0 * $i / 6 : 2500.0 - 20.0 * ($i - 12) / 6), 10.0, 3000.0), 20.0, 0.001,
    'Pegel: erst ab der Betankung (Sprung +1.500 l nach 2 h)');
gleich(TankRechnung::pegelVerbrauch($pegel(static fn (int $i): float => 2000.0 - 20.0 * $i / 6), 45.0, 3000.0), null, 'Pegel: 120 l sind keine drei Schritte zu 45 l – nichts');
gleich(TankRechnung::pegelVerbrauch($pegel(static fn (int $i): float => 2000.0 + ($i % 2) * 42.4), 42.4, 3000.0), null, 'Pegel: Rauschen ±1 Schritt – nichts');
gleich(TankRechnung::pegelVerbrauch($pegel(static fn (int $i): float => 1000.0 + 20.0 * $i / 6), 10.0, 3000.0), null, 'Pegel: steigender Inhalt ist kein Verbrauch');
pruefe(TankRechnung::pegelVerbrauch($pegel(static fn (int $i): ?float => $i % 3 === 0 ? null : 2000.0 - 20.0 * $i / 6), 10.0, null) !== null, 'Pegel: Lücken übergangen');

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
// Langes Tanken (IBC- oder Umfüllpumpe): 2.000 l in 100 min bei 2,78 l/mm, leicht verrauscht – es zählt der ganze Anstieg, nicht nur 60 min davon.
$hub = 2000 / 2.78 / (float) $r['sensor']['mm_je_roh'];
$lang = [];
for ($m = -180; $m <= 60; $m++) {
    $wert = $m <= -100 ? 2.6 : ($m >= 0 ? 2.6 + $hub : 2.6 + $hub * ($m + 100) / 100);
    $lang[] = [$t10 + $m * 60_000, $wert + (($m + 300) % 3 - 1) * 0.02];
}
$a = TankRechnung::anstieg($lang, $r);
$b = $a !== null ? TankRechnung::betanken($r, $a['roh_vor'], $a['roh_nach'], 2000.0) : [];
nahe($b['liter_je_mm'] ?? null, 2.78, 0.02, 'Tanken über 100 min: der ganze Anstieg zählt – 2,78 l/mm (' . var_export($b['liter_je_mm'] ?? $b['fehler'] ?? null, true) . ')');
pruefe($a !== null && $a['t_vor'] <= $t10 - 99 * 60_000 && abs($a['roh_vor'] - 2.6) < 0.03 && abs($a['roh_nach'] - 2.6 - $hub) < 0.03,
    '… vom Anfang des Tankens (2,6 V) bis danach (' . round(2.6 + $hub, 2) . ' V)');

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
gleich($st(['zeit' => null, 'alter_s' => null, 'prozent' => null, 'liter' => null, 'ohne_wert' => 'kein Wert in den letzten 7 Tagen']),
    ['stufe' => 'stumm', 'hinweis' => 'kein Wert in den letzten 7 Tagen'], '… seit mehr als 7 Tagen eingebaut: „kein Wert in den letzten 7 Tagen“');
$s = $st(['restlaufzeit_h' => 192.0]);
pruefe($s['stufe'] === 'ok' && str_contains($s['hinweis'], 'noch 8 Tage') && isset($s['kurz']) && !str_contains($s['kurz'], 'noch 8 Tage') && str_contains($s['kurz'], 'Stand '),
    'Hinweis mit Restlaufzeit, „kurz“ (Kachel, Panel) ohne – ' . $s['hinweis']);
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

// ------------------------------------------------ C – Tanksensor im Inventar, Einbau und Fassungen
$kennungC = 'pm-tank-' . bin2hex(random_bytes(3));
$siteC = SiteService::saveSite(null, ['name' => 'Prüfung Tanksensoren ' . $kennungC]);
$floorC = SiteService::saveFloor(null, ['site_id' => $siteC, 'name' => 'Wiese']);
$benutzerC = BenutzerService::anlegen($kennungC, 'Pruefung2026!!', 'admin', false);
$tsDaten = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => '500', 'bereich_von' => '0', 'bereich_bis' => '100',
    'bereich_einheit' => 'mbar', 'schritt_roh' => '0,1'];
$fassungenVon = static fn (int $objekt): array => Database::select('SELECT id, messpunkt_id, ab, rechnung, anlass, geprueft FROM tank_kennlinien WHERE objekt_id = ? ORDER BY id', [$objekt]);
$laufend = static fn (int $objekt): array => Database::select("SELECT * FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL ORDER BY id", [$objekt]);

try {
    alsBenutzer($benutzerC);
    $tankC = static function (string $name, array $eig) use ($floorC): int {
        return (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $name, 'floor_id' => $floorC, 'plan_x' => 20.0, 'plan_y' => 20.0, 'eigenschaften' => $eig])['id'];
    };
    $tankA = $tankC($kennungC . ' Tank A', ['inhalt_l' => '3000', 'kraftstoff' => 'Diesel', 'bauform' => 'Quader', 'liter_je_mm' => '2,78', 'sumpf_l' => '140']);
    $tankB = $tankC($kennungC . ' Tank B', ['inhalt_l' => '1000', 'kraftstoff' => 'Diesel']);
    $vtC = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennungC . ' Verteilung', 'floor_id' => $floorC])['id'];
    $sensor = static fn (string $nr, array $daten = [], ?array $kanaele = null) => InventarService::speichern(null, [
        'art' => 'tanksensor', 'kennung' => $kennungC . '-' . $nr, 'eigenschaften' => $daten,
        'messgeraete' => $kanaele ?? [['geraet' => $kennungC . '-uni-' . $nr, 'kanal' => 'voltmeter:100', 'stelle' => 'einspeisung']],
    ]);

    // ------------------------------------------------ Speichern
    $e = $sensor('TS1', $tsDaten);
    pruefe($e['ok'], 'Tanksensor ohne Typ und Stecker gespeichert: ' . $e['message']);
    $ts1 = (int) ($e['id'] ?? 0);
    $item = InventarService::find($ts1);
    gleich([$item['art_name'], $item['typ'], $item['vorlage_id'], $item['steckertyp_id']], ['Tanksensor', 'Hydrostatisch 0–100 mbar, 4–20 mA an 500 Ω', null, null],
        'Art und Typ aus den Sensordaten');
    gleich($item['eigenschaften'], TankRechnung::sensorNormal($tsDaten), 'Sensordaten normalisiert gespeichert');
    $roh = (string) Database::scalar('SELECT eigenschaften FROM inventar WHERE id = ?', [$ts1]);
    pruefe(str_contains($roh, '"buerde_ohm":500.0') && str_contains($roh, '"schritt_roh":0.1') && str_contains($roh, '"u_bei_4ma":null'), 'Sensordaten als JSON mit Floats: ' . $roh);
    gleich(array_column(InventarService::messgeraete($ts1), 'stelle'), ['fuellstand'], 'der Messkanal sitzt am Füllstand, was das Formular auch sagt');
    gleich(InventarService::stellen($item), ['fuellstand' => 'Füllstand'], 'Stellen eines Tanksensors');
    pruefe(in_array($ts1, array_column(InventarService::liste(['art' => 'tanksensor']), 'id'), true), 'Liste nach Art');
    $e = $sensor('TS9', $tsDaten, [['geraet' => $kennungC . '-a', 'kanal' => 'voltmeter:100'], ['geraet' => $kennungC . '-a', 'kanal' => 'voltmeter:101']]);
    gleich([$e['ok'], $e['message']], [false, 'Ein Tanksensor hat genau einen Messkanal.'], 'zwei Messkanäle abgelehnt');
    $e = $sensor('TS9', $tsDaten, []);
    gleich([$e['ok'], $e['message']], [false, 'Ein Tanksensor hat genau einen Messkanal.'], 'ohne Messkanal abgelehnt');
    $e = $sensor('TS9', ['buerde_ohm' => ''] + $tsDaten);
    pruefe(!$e['ok'] && $e['message'] === TankRechnung::sensorPruefen(['buerde_ohm' => ''] + $tsDaten), 'ungültige Sensordaten: Meldung aus sensorPruefen – ' . $e['message']);
    $e = $sensor('TS9', ['prinzip' => ['hydrostatisch'], 'bereich_bis' => ['x' => 1]] + $tsDaten);
    pruefe(!$e['ok'] && $e['message'] === 'Bitte das Messprinzip wählen.', 'Listen statt Werten fallen weg (keine PHP-Meldung)');
    pruefe(Database::scalar('SELECT id FROM inventar WHERE kennung = ?', [$kennungC . '-TS9']) === false, 'nichts Ungültiges gespeichert');
    $zsC = (int) InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => $kennungC . '-ZS', 'steckertyp_id' => $typ['cee16'],
        'eigenschaften' => $tsDaten, 'messgeraete' => [['geraet' => $kennungC . '-zs', 'kanal' => 'switch:0']]])['id'];
    gleich(Database::scalar('SELECT eigenschaften FROM inventar WHERE id = ?', [$zsC]), null, 'ein Zwischenstecker hat keine Sensordaten');
    $e = InventarService::speichern($zsC, ['art' => 'tanksensor', 'kennung' => $kennungC . '-ZS', 'eigenschaften' => $tsDaten,
        'messgeraete' => [['geraet' => $kennungC . '-zs', 'kanal' => 'switch:0']]]);
    $e2 = InventarService::speichern($ts1, ['art' => 'verteilung', 'kennung' => $kennungC . '-TS1', 'messgeraete' => [['geraet' => $kennungC . '-uni-TS1', 'kanal' => 'voltmeter:100']]]);
    pruefe(!$e['ok'] && !$e2['ok'] && InventarService::find($zsC)['art'] === 'zwischenstecker' && InventarService::find($ts1)['art'] === 'tanksensor',
        'kein Wechsel zwischen Tanksensor und Verteiler/Zwischenstecker – ' . $e['message']);

    // ------------------------------------------------ Einsetzen
    $e = InventarService::einsetzen($ts1, $vtC);
    gleich([$e['ok'], $e['message']], [false, 'Ein Tanksensor gehört in einen Tank.'], 'Tanksensor in eine Verteilung abgelehnt');
    $e = InventarService::einsetzen($ts1, $tankA, null, true, ['offset_mm' => 'tief']);
    pruefe(!$e['ok'] && str_contains($e['message'], 'Einbautiefe') && InventarService::einsaetzeAnObjekt($tankA) === [], 'unlesbares Einbaumaß abgelehnt, kein Einsatz');
    $e = InventarService::einsetzen($ts1, $tankA, 999999, true, ['offset_mm' => '20', 'fremd' => 'x']);
    pruefe($e['ok'] && str_contains($e['message'], 'Kennlinie ab dem Einbau'), 'Tanksensor in den Tank: ' . $e['message']);
    $einsatz1 = InventarService::einsaetzeAnObjekt($tankA)[0] ?? [];
    $mp = $laufend($tankA);
    gleich(count($mp), 1, 'ein Messpunkt am Tank');
    $mp1 = $mp[0] ?? [];
    gleich([$mp1['rolle'] ?? null, array_key_exists('anschluss_id', $mp1) ? $mp1['anschluss_id'] : 'x', $mp1['gueltig_ab'] ?? null, $mp1['bezeichnung'] ?? null,
        (int) ($mp1['einsatz_id'] ?? 0), array_key_exists('anschluss_id', $einsatz1) ? $einsatz1['anschluss_id'] : 'x'],
        ['fuellstand', null, $einsatz1['ab'] ?? '', $kennungC . '-TS1', (int) ($einsatz1['id'] ?? -1), null], 'Messpunkt „Füllstand“ ohne Anschluss, gültig ab dem Einsatz, mit der Kennung');
    $f = $fassungenVon($tankA);
    gleich([count($f), $f[0]['anlass'] ?? null, (int) ($f[0]['geprueft'] ?? 1), $f[0]['ab'] ?? null, (int) ($f[0]['messpunkt_id'] ?? 0)],
        [1, 'einsatz', 0, $mp1['gueltig_ab'] ?? '', (int) ($mp1['id'] ?? -1)], 'Fassung „einsatz“ ab dem Einbau, ungeprüft');
    $r1 = json_decode((string) ($f[0]['rechnung'] ?? ''), true);
    $soll = TankRechnung::rechnung($tsDaten, ['offset_mm' => 20], AnlagenService::find($tankA)['eigenschaften'], [])['rechnung'];
    gleich([TankRechnung::pruefen($r1), $r1['feld'] ?? null, $r1['sensor'] ?? null, $r1['tank'] ?? null], [null, 'u', $soll['sensor'], $soll['tank']],
        'die Fassung rechnet aus Sensordaten, Einbau und Tank');
    nahe($r1['sensor']['mm_je_roh'] ?? null, 152.6497, 0.001, 'Steigung der Fassung wie in den Prüfwerten');
    nahe(TankRechnung::umrechnen($r1, 7.5)['liter'], 2389.61, 0.5, 'Liter bei 7,5 V aus der gespeicherten Fassung');
    gleich([$r1['herkunft']['sensor']['kennung'] ?? null, $r1['herkunft']['einbau'] ?? null, $r1['herkunft']['tank']['name'] ?? null],
        [$kennungC . '-TS1', ['offset_mm' => 20.0], $kennungC . ' Tank A'], 'Herkunft: Sensor, Einbau, Tank');
    $s = TankService::sensor($tankA);
    gleich([(int) ($s['id'] ?? 0), $s['kennung'] ?? null, (int) ($s['inventar_id'] ?? 0)], [(int) $mp1['id'], $kennungC . '-TS1', $ts1], 'TankService::sensor: der laufende Tanksensor');
    gleich((int) (TankService::fassungJetzt((int) $mp1['id'])['id'] ?? 0), (int) $f[0]['id'], 'jetzt gilt die Fassung des Einbaus');
    gleich(TankService::fassungJetzt((int) $mp1['id'], (int) Zeit::ts((string) $mp1['gueltig_ab']) - 60), null, 'vor dem Einbau gilt keine');
    $audit = Database::first("SELECT details FROM audit_log WHERE action = 'tank.kennlinie' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $tankA]);
    gleich(json_decode((string) ($audit['details'] ?? ''), true), ['messpunkt' => (int) $mp1['id'], 'anlass' => 'einsatz', 'rueckwirkend' => false], 'Audit der Fassung');
    $seite = TankService::seitenDaten(AnlagenService::find($tankA));
    pruefe(($seite['sensor']['kennung'] ?? null) === $kennungC . '-TS1' && ($seite['fassung']['anlass'] ?? null) === 'einsatz', 'Tankseite kennt Sensor und Fassung');
    $vorher = $fassungenVon($tankA);

    // Messpunkte am Tank gibt es nur aus dem Inventar
    $e = MesspunktService::speichern($tankA, null, ['geraet' => $kennungC . '-hand', 'kanal' => 'voltmeter:100']);
    gleich([$e['ok'], $e['message']], [false, 'Am Tank misst ein Tanksensor aus dem Inventar.'], 'kein Messpunkt von Hand am Tank');
    $e = MesspunktService::speichern($vtC, null, ['geraet' => $kennungC . '-hand', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand']);
    pruefe(!$e['ok'] && Database::scalar('SELECT COUNT(*) FROM messpunkte WHERE geraet = ?', [$kennungC . '-hand']) == 0, 'die Rolle „Füllstand“ gibt es von Hand nicht');
    gleich(MesspunktService::haupt([['id' => 1, 'rolle' => 'fuellstand', 'anschluss_id' => null]]), null, 'ein Füllstand steht nie für die ganze Anlage');
    LocationBundle::belegungErsetzen($tankA, ['messpunkte' => [['geraet' => $kennungC . '-datei', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand']]], []);
    gleich([count($laufend($tankA)), (int) Database::scalar('SELECT COUNT(*) FROM messpunkte WHERE geraet = ?', [$kennungC . '-datei'])], [1, 0],
        'aus einer Datei kein Messpunkt am Tank – der eingesetzte bleibt');
    LocationBundle::belegungErsetzen($vtC, ['messpunkte' => [['geraet' => $kennungC . '-datei', 'kanal' => 'em:0', 'rolle' => 'fuellstand']]], []);
    gleich(Database::scalar('SELECT rolle FROM messpunkte WHERE geraet = ?', [$kennungC . '-datei']), 'einspeisung', 'die Rolle „Füllstand“ kommt nicht aus einer Datei');

    // ------------------------------------------------ Änderungen im Einsatz
    // Shelly getauscht: neuer Messpunkt, dieselbe Rechnung als Fassung „shelly“
    $e = InventarService::speichern($ts1, ['art' => 'tanksensor', 'kennung' => $kennungC . '-TS1', 'eigenschaften' => $tsDaten,
        'messgeraete' => [['geraet' => $kennungC . '-uni-TS1b', 'kanal' => 'voltmeter:100']]]);
    pruefe($e['ok'], 'Shelly im Tanksensor getauscht: ' . $e['message']);
    $mp = $laufend($tankA);
    $mp2 = $mp[0] ?? [];
    pruefe(count($mp) === 1 && ($mp2['geraet'] ?? '') === $kennungC . '-uni-TS1b' && (int) $mp2['id'] !== (int) $mp1['id'], 'ein neuer laufender Messpunkt mit dem neuen Shelly');
    gleich(Database::scalar('SELECT gueltig_bis FROM messpunkte WHERE id = ?', [(int) $mp1['id']]), $mp2['gueltig_ab'] ?? 'x', 'der alte endet, wo der neue beginnt');
    $f2 = TankService::fassungJetzt((int) $mp2['id']);
    gleich([$f2['anlass'] ?? null, $f2['ab'] ?? null, $f2['geprueft'] ?? null, $f2['rechnung'] ?? null], ['shelly', $mp2['gueltig_ab'] ?? '', false, $r1],
        'Fassung „shelly“: dieselbe Rechnung wie zuvor, ungeprüft');

    // Sensordaten im Einsatz geändert: Bürde 500 → 470 Ω
    $e = InventarService::speichern($ts1, ['art' => 'tanksensor', 'kennung' => $kennungC . '-TS1', 'eigenschaften' => ['buerde_ohm' => '470'] + $tsDaten,
        'messgeraete' => [['geraet' => $kennungC . '-uni-TS1b', 'kanal' => 'voltmeter:100']]]);
    pruefe($e['ok'] && str_contains($e['message'], 'neuen Sensordaten'), 'Bürde geändert: ' . $e['message']);
    $f3 = TankService::fassungJetzt((int) $mp2['id']);
    [$teil470] = TankRechnung::sensorTeil(['buerde_ohm' => 470] + $tsDaten, ['offset_mm' => 20], 0.835);
    gleich([$f3['anlass'] ?? null, $f3['geprueft'] ?? null, $f3['rechnung']['sensor'] ?? null, $f3['rechnung']['tank'] ?? null, $f3['rechnung']['herkunft']['sensor']['buerde_ohm'] ?? null],
        ['sensor', false, $teil470, $r1['tank'], 470.0], 'Fassung „sensor“: neue Steigung aus 470 Ω, Tankteil und Einbau bleiben');
    pruefe(abs((float) $f3['rechnung']['sensor']['mm_je_roh'] - 152.6497) > 1, 'die Steigung hat sich geändert');
    $n = count($fassungenVon($tankA));
    $e = InventarService::speichern($ts1, ['art' => 'tanksensor', 'kennung' => $kennungC . '-TS1', 'name' => 'neu', 'eigenschaften' => ['buerde_ohm' => '470', 'hinweis' => 'PUR-Kabel'] + $tsDaten,
        'messgeraete' => [['geraet' => $kennungC . '-uni-TS1b', 'kanal' => 'voltmeter:100']]]);
    gleich([$e['ok'], count($fassungenVon($tankA)), count($laufend($tankA)), (int) $laufend($tankA)[0]['id']], [true, $n, 1, (int) $mp2['id']],
        'nur Name und Hinweis geändert: keine neue Fassung, kein neuer Messpunkt');
    // Nach einer Peilung (Peilstab 700 mm bei 7,4 V): nur der Hinweis – keine Fassung, der Anker bleibt;
    // nur der Meldeschritt – die Fassung der Peilung mit ihm, Anker, Steigung und „geprüft“ bleiben.
    $peil = TankRechnung::peilen($f3['rechnung'], 7.4, ['hoehe_mm' => 700.0])['rechnung'];
    $idPeil = TankService::fassungAnlegen($mp2, $peil, 'peilung', gmdate('c'), true);
    $daten470 = ['buerde_ohm' => '470', 'hinweis' => 'PUR-Kabel'] + $tsDaten;
    $speichernTs1 = static fn (array $daten): array => InventarService::speichern($ts1, ['art' => 'tanksensor', 'kennung' => $kennungC . '-TS1', 'name' => 'neu',
        'eigenschaften' => $daten, 'messgeraete' => [['geraet' => $kennungC . '-uni-TS1b', 'kanal' => 'voltmeter:100']]]);
    $e = $speichernTs1(['hinweis' => 'Kabel neu verlegt'] + $daten470);
    gleich([$e['ok'], (int) (TankService::fassungJetzt((int) $mp2['id'])['id'] ?? 0)], [true, $idPeil], 'nach einer Peilung nur den Hinweis geändert: keine Fassung, der Anker bleibt');
    $e = $speichernTs1(['hinweis' => 'Kabel neu verlegt', 'schritt_roh' => '0,05'] + $daten470);
    $fs = TankService::fassungJetzt((int) $mp2['id']);
    gleich([$fs['anlass'] ?? null, $fs['geprueft'] ?? null, $fs['rechnung']['sensor'] ?? null, $fs['rechnung']['tank'] ?? null],
        ['sensor', true, ['schritt_roh' => 0.05] + $peil['sensor'], $peil['tank']], 'nur den Meldeschritt geändert: Anker und Steigung der Peilung bleiben, geprüft – ' . $e['message']);
    nahe(TankRechnung::hoehe($fs['rechnung'], 7.4), 700.0, 0.001, '… bei 7,4 V weiter 700 mm');
    // zurück auf den Stand vor der Peilung (für die Prüfungen unten)
    $speichernTs1($daten470);
    TankService::fassungAnlegen($mp2, $f3['rechnung'], 'hand', gmdate('c'), false);

    // Tankdaten geändert
    $speichernA = static fn (array $eig, string $name = '') => AnlagenService::speichern($tankA, ['art' => 'tank', 'name' => $name !== '' ? $name : $kennungC . ' Tank A',
        'floor_id' => $floorC, 'eigenschaften' => $eig]);
    $eigA = ['inhalt_l' => '3000', 'kraftstoff' => 'Diesel', 'bauform' => 'Quader', 'liter_je_mm' => '2,78', 'sumpf_l' => '140'];
    $e = $speichernA(['inhalt_l' => '2850'] + $eigA);
    $f4 = TankService::fassungJetzt((int) $mp2['id']);
    pruefe($e['ok'] && str_contains($e['message'], 'neuen Tankdaten'), 'Nennvolumen geändert: ' . $e['message']);
    gleich([$f4['anlass'] ?? null, $f4['rechnung']['sensor'] ?? null, $f4['rechnung']['tank']['nenn_l'] ?? null, $f4['rechnung']['tank']['liter_je_mm'] ?? null],
        ['tank', $teil470, 2850.0, 2.78], 'Fassung „tank“: Sensorteil unverändert, neues Nennvolumen');
    $n = count($fassungenVon($tankA));
    $e = $speichernA(['inhalt_l' => '2850', 'zulassung' => 'Z-38.12-223'] + $eigA, $kennungC . ' Tank A neu');
    pruefe($e['ok'] && !str_contains($e['message'], 'Tankdaten') && count($fassungenVon($tankA)) === $n, 'nur Name und Zulassung geändert: keine Fassung');
    $e = $speichernA(['inhalt_l' => '2850', 'kraftstoff' => 'HVO'] + $eigA);
    $f5 = TankService::fassungJetzt((int) $mp2['id']);
    [$teilHvo] = TankRechnung::sensorTeil(['buerde_ohm' => 470] + $tsDaten, ['offset_mm' => 20], 0.78);
    gleich([$f5['anlass'] ?? null, $f5['geprueft'] ?? null, $f5['rechnung']['sensor'] ?? null, $f5['rechnung']['herkunft']['tank']['dichte_kg_l'] ?? null],
        ['tank', false, $teilHvo, 0.78], 'Kraftstoff HVO: Sensorteil neu mit der Dichte von HVO');
    // Was die Liter je mm nicht berührt, bleibt geprüft; eine andere Dichte nicht
    TankService::fassungAnlegen($mp2, $f5['rechnung'], 'peilung', gmdate('c'), true);
    $speichernA(['inhalt_l' => '2850', 'kraftstoff' => 'HVO', 'sumpf_l' => '100'] + $eigA);
    $fg = TankService::fassungJetzt((int) $mp2['id']);
    gleich([$fg['anlass'] ?? null, $fg['geprueft'] ?? null, $fg['rechnung']['tank']['sumpf_l'] ?? null, $fg['rechnung']['sensor'] ?? null], ['tank', true, 100.0, $teilHvo],
        'nur der Sumpf geändert: bleibt geprüft');
    $speichernA(['inhalt_l' => '2850', 'kraftstoff' => 'Diesel', 'sumpf_l' => '100'] + $eigA);
    $fg = TankService::fassungJetzt((int) $mp2['id']);
    gleich([$fg['anlass'] ?? null, $fg['geprueft'] ?? null, $fg['rechnung']['sensor'] ?? null], ['tank', false, $teil470], 'zurück auf Diesel: Sensorteil neu, ungeprüft');
    TankService::fassungAnlegen($mp2, $fg['rechnung'], 'peilung', gmdate('c'), true);
    $speichernA(['inhalt_l' => '2850', 'kraftstoff' => 'Diesel', 'sumpf_l' => '100', 'liter_je_mm' => '2,75'] + $eigA);
    gleich(TankService::fassungJetzt((int) $mp2['id'])['geprueft'] ?? null, false, 'Inhalt je mm von Hand geändert: ungeprüft');
    $n = count($fassungenVon($tankA));
    AnlagenService::eigenschaftSetzen($tankA, ['liter_je_mm' => 2.8, 'liter_je_mm_herkunft' => 'Betankung'], true);
    gleich(count($fassungenVon($tankA)), $n, 'eigenschaftSetzen ohne Fassung (Peilung, Betankung legen ihre eigene an)');
    AnlagenService::eigenschaftSetzen($tankA, ['liter_je_mm' => 2.9]);
    $f6 = TankService::fassungJetzt((int) $mp2['id']);
    gleich([count($fassungenVon($tankA)), $f6['anlass'] ?? null, $f6['rechnung']['tank']['liter_je_mm'] ?? null], [$n + 1, 'tank', 2.9], 'eigenschaftSetzen sonst mit Fassung');

    // ------------------------------------------------ Ein zweiter Sensor ersetzt den ersten; Abbau; Wiedereinsatz woanders
    $ts2 = (int) $sensor('TS2', ['prinzip' => 'ultraschall', 'feld' => 'abstand_mm', 'signal' => 'direkt', 'totzone_mm' => '200'])['id'];
    $e = InventarService::einsetzen($ts2, $tankA);
    pruefe($e['ok'] && str_contains($e['message'], $kennungC . '-TS1 ersetzt') && str_contains($e['message'], TankRechnung::HINWEIS_NUR_ROH),
        'zweiter Sensor im Tank: ersetzt den ersten; Ultraschall ohne Einbaumaß nur Rohwert – ' . $e['message']);
    gleich(InventarService::find($ts1)['zustand'], 'lager', 'der erste ist wieder im Lager');
    $s = TankService::sensor($tankA);
    $fRoh = $s !== null ? TankService::fassungJetzt((int) $s['id']) : null;
    gleich([$s['kennung'] ?? null, $fRoh['rechnung']['sensor']['art'] ?? null, count($laufend($tankA))], [$kennungC . '-TS2', 'roh', 1], 'jetzt misst TS2, ohne Einbaumaß als Rohwert');
    $e = InventarService::einsetzen($ts2, $tankA, null, true, ['d_leer_mm' => '1300']);
    $s = TankService::sensor($tankA);
    $fUs = $s !== null ? TankService::fassungJetzt((int) $s['id']) : null;
    pruefe($e['ok'] && ($fUs['rechnung']['sensor']['art'] ?? '') === 'linear' && TankRechnung::hoehe($fUs['rechnung'], 300.0) == 1000.0,
        'mit Sensorfläche 1300 mm: Abstand 300 → 1000 mm');
    $e = InventarService::beenden((int) $s['einsatz_id']);
    pruefe($e['ok'] && TankService::sensor($tankA) === null && $laufend($tankA) === [], 'Abbau: kein laufender Tanksensor mehr');
    gleich(Database::scalar('SELECT gueltig_bis FROM messpunkte WHERE id = ?', [(int) $s['id']]) !== null, true, '… sein Messpunkt endet');
    $nA = count($fassungenVon($tankA));
    $e = InventarService::einsetzen($ts1, $tankB, null, true, ['offset_mm' => '0']);
    pruefe($e['ok'], 'TS1 in Tank B: ' . $e['message']);
    $sB = TankService::sensor($tankB);
    $fB = $fassungenVon($tankB);
    gleich([count($fB), $fB[0]['anlass'] ?? null, $fB[0]['ab'] ?? null], [1, 'einsatz', $sB['gueltig_ab'] ?? ''], 'Tank B: eine neue Fassung ab dem Einbau');
    $rB = json_decode((string) ($fB[0]['rechnung'] ?? ''), true);
    gleich([$rB['tank']['form'] ?? null, $rB['sensor']['mm_anker'] ?? null, $rB['herkunft']['sensor']['buerde_ohm'] ?? null], ['unbekannt', 0.0, 470.0],
        '… aus den Sensordaten von jetzt, Einbau und Tank B (Inhalt je mm noch unbekannt)');
    pruefe(str_contains($e['message'], TankRechnung::HINWEIS_INHALT), 'Hinweis „peilen“ beim Tank ohne Inhalt je mm');
    gleich(count($fassungenVon($tankA)), $nA, 'Tank A behält seine Fassungen');
    gleich(count(TankService::sensoren($tankA)), 4, 'Tank A kennt seine früheren Sensoren');

    // ------------------------------------------------ Fassungen ändern sich nie
    $danach = array_column($fassungenVon($tankA), null, 'id');
    $gleich = true;
    foreach ($vorher as $alt) {
        $gleich = $gleich && isset($danach[$alt['id']]) && $danach[$alt['id']] == $alt;
    }
    pruefe($gleich && count($danach) > count($vorher), 'ältere Fassungen unverändert, nur neue Zeilen');
    pruefe(array_filter(array_merge($fassungenVon($tankA), $fassungenVon($tankB)), static fn ($f) => TankRechnung::pruefen(json_decode((string) $f['rechnung'], true)) !== null) === [],
        'jede gespeicherte Fassung besteht pruefen()');
    $kaputt = Database::insert('tank_kennlinien', ['messpunkt_id' => (int) $sB['id'], 'objekt_id' => $tankB, 'ab' => gmdate('c'), 'rechnung' => '{"v":1}',
        'anlass' => 'hand', 'created_at' => gmdate('c')]);
    gleich((int) (TankService::fassungJetzt((int) $sB['id'])['id'] ?? 0), (int) $fB[0]['id'], 'eine kaputte Zeile rechnet nicht mit');
    Database::execute('DELETE FROM tank_kennlinien WHERE id = ?', [$kaputt]);
    $abgelehnt = false;
    try {
        TankService::fassungAnlegen($sB, ['v' => 1, 'feld' => 'u'], 'hand', gmdate('c'), false);
    } catch (\InvalidArgumentException) {
        $abgelehnt = true;
    }
    pruefe($abgelehnt && count($fassungenVon($tankB)) === 1, 'fassungAnlegen nimmt keine ungültige Rechnung');

    // ------------------------------------------------ Löschen
    MesspunktService::loeschen($tankA, (int) $mp1['id']);
    gleich((int) Database::scalar('SELECT COUNT(*) FROM tank_kennlinien WHERE messpunkt_id = ?', [(int) $mp1['id']]), 0, 'Messpunkt gelöscht: seine Fassungen weg');
    $ereignis = Database::insert('tank_ereignisse', ['objekt_id' => $tankA, 'messpunkt_id' => (int) $mp2['id'], 'art' => 'peilung', 'zeit' => gmdate('c'), 'prozent' => 60.0,
        'created_at' => gmdate('c')]);
    MesspunktService::loeschen($tankA, (int) $mp2['id']);
    gleich(Database::first('SELECT objekt_id, messpunkt_id FROM tank_ereignisse WHERE id = ?', [$ereignis]), ['objekt_id' => $tankA, 'messpunkt_id' => null],
        '… eine Peilung bleibt im Protokoll des Tanks');
    AnlagenService::loeschen($tankA);
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM tank_kennlinien WHERE objekt_id = ?', [$tankA]),
        (int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankA])], [0, 0], 'Tank gelöscht: Fassungen und Ereignisse weg');
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$kennungC . '%']) as $i) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), (int) $i['id']]);
        InventarService::loeschen((int) $i['id']);
    }
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennungC . '%']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    // Auch wenn ein Löschen oben versagt hat: nichts von dieser Prüfung bleibt stehen.
    foreach ([$tankA ?? 0, $tankB ?? 0] as $id) {
        Database::execute('DELETE FROM tank_kennlinien WHERE objekt_id = ?', [(int) $id]);
        Database::execute('DELETE FROM tank_ereignisse WHERE objekt_id = ?', [(int) $id]);
    }
    SiteService::deleteSite($siteC);
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$benutzerC]);
    Database::execute('DELETE FROM users WHERE id = ?', [$benutzerC]);
    Database::execute('DELETE FROM audit_log WHERE username = ? OR details LIKE ?', [$kennungC, '%' . $kennungC . '%']);
}

// ------------------------------------------------ D – Live und Verlauf (Demo-Modus)
Config::set('app.demo', true);
$kennungD = 'pm-tank-' . bin2hex(random_bytes(3));
$siteD = SiteService::saveSite(null, ['name' => 'Prüfung Tankstand ' . $kennungD]);
$floorD = SiteService::saveFloor(null, ['site_id' => $siteD, 'name' => 'Wiese']);
$adminD = BenutzerService::anlegen($kennungD, 'Pruefung2026!!', 'admin', false);
$kundeD = BenutzerService::anlegen($kennungD . '-kunde', 'Pruefung2026!!', 'kunde', false);
$demo = ['geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100'];
$warnungVorher = Config::get('grenzen.tank_warnung');

try {
    alsBenutzer($adminD);
    $jetzt = time();
    $eigD = ['inhalt_l' => '3000', 'kraftstoff' => 'HVO', 'bauform' => 'Quader', 'sumpf_l' => '140'];
    $tankD = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungD . ' Tank', 'floor_id' => $floorD, 'plan_x' => 30.0, 'plan_y' => 30.0,
        'eigenschaften' => $eigD])['id'];
    // Eingebaut vor drei Tagen – den Messpunkt direkt anlegen, sonst ließe sich die Zeit des Einbaus nicht zurückstellen.
    $mpD = Database::insert('messpunkte', ['objekt_id' => $tankD, 'geraet' => $demo['geraet'], 'kanal' => $demo['kanal'], 'rolle' => 'fuellstand',
        'bezeichnung' => $kennungD . '-TS', 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
    $mD = Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mpD]);
    $sensorD = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
        'bereich_einheit' => 'mbar', 'schritt_roh' => 0.1];
    // k1 beim Einbau (Inhalt je mm unbekannt), kX 36 h zurück (ebenso), k2 vor 12 h aus einer Betankung (2,78 l/mm)
    $k1 = TankRechnung::rechnung($sensorD, ['offset_mm' => 20], $eigD, ['kennung' => $kennungD . '-TS', 'name' => $kennungD . ' Tank', 'text' => 'Einbau'])['rechnung'];
    gleich($k1['tank']['form'], 'unbekannt', 'Prüfbestand: Fassung beim Einbau ohne Inhalt je mm');
    $idK1 = TankService::fassungAnlegen($mD, $k1, 'einsatz', Zeit::iso($jetzt - 3 * 86400), false);
    $idKx = TankService::fassungAnlegen($mD, $k1, 'sensor', Zeit::iso($jetzt - 36 * 3600), false);
    $k2 = TankRechnung::mitTank($k1, TankRechnung::tankTeil(['liter_je_mm' => '2,78'] + $eigD));
    $tK2 = $jetzt - 12 * 3600;
    $idK2 = TankService::fassungAnlegen($mD, $k2, 'betankung', Zeit::iso($tK2), true);
    $tankObj = static fn () => AnlagenService::find($tankD);

    // ------------------------------------------------ Live
    $l = LiveService::fuerObjekte([$tankObj()])[$tankD] ?? [];
    $t = $l['tank'] ?? [];
    $w = Demo::tankLetzte([$demo + ['feld' => 'u', 'ab' => $jetzt - 3 * 86400, 'ab_online' => $jetzt - 86400]])[$demo['geraet'] . '|' . $demo['kanal']];
    pruefe(($t['liter'] ?? 0) > 0 && isset($l['zustand']['tank_prozent']) && ($l['alter_s'] ?? -1) >= 0 && ($l['stufe'] ?? 'keine') !== 'keine',
        'Demo-Tank mit Sensor: Liter, Füllstand, Alter, Stufe ' . ($l['stufe'] ?? '?') . ' – ' . ($l['hinweis'] ?? ''));
    nahe($t['liter'] ?? null, (float) TankRechnung::umrechnen($k2, $w['roh'])['liter'], 0.1, 'Live rechnet mit der jetzigen Fassung (k2)');
    nahe($t['liter'] ?? null, 2700 - 22 * ((int) $w['zeit'] / 1000 - (new DateTimeImmutable('@' . ((int) $w['zeit'] / 1000)))->setTimezone(Zeit::zone())->setTime(6, 0)
        ->modify((int) (new DateTimeImmutable('@' . ((int) $w['zeit'] / 1000)))->setTimezone(Zeit::zone())->format('G') < 6 ? '-1 day' : '+0 day')->getTimestamp()) / 3600,
        60.0, 'Demo-Sonde und Kennlinie passen zum Demo-Tank (2.700 l um 06:00, 22 l/h, ± Meldeschritt)');
    gleich([$t['sensor'] ?? null, $t['messpunkt'] ?? null, $t['roh_feld'] ?? null, $t['roh_einheit'] ?? null, $t['zeit'] ?? null, $t['online'] ?? null, $t['geprueft'] ?? null,
        $t['kalibriert'] ?? null, array_key_exists('fehler', $t) ? $t['fehler'] : 'x', array_key_exists('p', $l) ? $l['p'] : 'x', $l['messpunkte'] ?? 'x'],
        [true, $mpD, 'u', 'V', $w['zeit'], true, true, true, null, null, []], 'Antwort je Tank: Sensor, Rohwert mit echter Zeit, online, geprüft – ohne Leistung');
    pruefe(str_contains((string) ($t['kennlinie'] ?? ''), '2,78 l/mm') && str_contains((string) ($t['kennlinie'] ?? ''), 'geprüft'), 'Kennlinie im Klartext: ' . ($t['kennlinie'] ?? ''));
    pruefe(str_contains((string) ($l['hinweis'] ?? ''), 'Stand ') && is_string($t['stand'] ?? null), 'Hinweis nennt den Stand mit Alter: ' . ($l['hinweis'] ?? ''));
    nahe($t['schritt_l'] ?? null, 0.1 * 163.4135 * 2.78, 0.05, 'Liter je Meldeschritt (HVO, 0,1 V)');
    gleich(array_keys(TankService::live([$tankObj()])), [$tankD], 'TankService::live je Tank');

    // Grenzen aus den Einstellungen (grenzen.tank_*); Warnung des Tanks geht vor
    Config::set('grenzen.tank_warnung', '99');
    $s = LiveService::fuerObjekte([$tankObj()])[$tankD];
    pruefe($s['stufe'] === 'warn' && str_contains($s['hinweis'], 'nur noch'), 'Warngrenze 99 % aus den Einstellungen: gelb – ' . $s['hinweis']);
    Config::set('grenzen.tank_warnung', $warnungVorher);
    AnlagenService::eigenschaftSetzen($tankD, ['alarm_prozent' => 99], true);
    gleich(LiveService::fuerObjekte([$tankObj()])[$tankD]['stufe'], 'bad', 'Alarm am Tank (99 %) geht vor: rot');
    AnlagenService::eigenschaftSetzen($tankD, ['alarm_prozent' => null], true);
    gleich(LiveService::fuerObjekte([$tankObj()])[$tankD]['stufe'], 'ok', '… ohne wieder grün');

    // Nie ein Wert von vor dem Einbau: ein eben eingebauter Sensor am selben Shelly hat noch keinen
    $tankNeu = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungD . ' Tank neu', 'floor_id' => $floorD, 'eigenschaften' => $eigD])['id'];
    Database::insert('messpunkte', ['objekt_id' => $tankNeu, 'geraet' => $demo['geraet'], 'kanal' => $demo['kanal'], 'rolle' => 'fuellstand',
        'bezeichnung' => $kennungD . '-TS2', 'gueltig_ab' => Zeit::iso(min(time(), intdiv((int) $w['zeit'], 1000) + 1)), 'created_at' => gmdate('c')]);
    $mNeu = Database::first("SELECT * FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand'", [$tankNeu]);
    TankService::fassungAnlegen($mNeu, $k2, 'einsatz', (string) $mNeu['gueltig_ab'], false);
    $l = LiveService::fuerObjekte([AnlagenService::find($tankNeu)])[$tankNeu];
    pruefe($l['stufe'] === 'stumm' && array_key_exists('roh', $l['tank']) && $l['tank']['roh'] === null && $l['tank']['liter'] === null && str_contains($l['hinweis'], 'seit dem Einbau'),
        'eben eingebaut: kein Wert von vor dem Einbau – „' . $l['hinweis'] . '“');
    gleich(TankService::rohJetzt(AnlagenService::find($tankNeu), $mNeu), null, 'rohJetzt: kein Rohwert von vor dem Einbau');
    // Eingebaut vor 10 Tagen, seit mehr als 7 Tagen keine Meldung: nicht „seit dem Einbau“
    $tankStill = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungD . ' Tank still', 'floor_id' => $floorD, 'eigenschaften' => $eigD])['id'];
    Database::insert('messpunkte', ['objekt_id' => $tankStill, 'geraet' => $kennungD . '-still', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
        'bezeichnung' => $kennungD . '-TS3', 'gueltig_ab' => Zeit::iso($jetzt - 10 * 86400), 'created_at' => gmdate('c')]);
    $mStill = Database::first("SELECT * FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand'", [$tankStill]);
    TankService::fassungAnlegen($mStill, $k2, 'einsatz', (string) $mStill['gueltig_ab'], false);
    $l = LiveService::fuerObjekte([AnlagenService::find($tankStill)])[$tankStill];
    pruefe($l['stufe'] === 'stumm' && str_contains($l['hinweis'], 'kein Wert in den letzten 7 Tagen') && !str_contains($l['hinweis'], 'Einbau'),
        'eingebaut vor 10 Tagen, still: „' . $l['hinweis'] . '“');
    $roh = TankService::rohJetzt($tankObj(), $mD);
    gleich([$roh['roh'] ?? null, $roh['zeit'] ?? null, $roh['feld'] ?? null], [$w['roh'], $w['zeit'], 'u'], 'rohJetzt: der letzte Rohwert vom Server');

    // ------------------------------------------------ Verlauf je Fassung
    $r = TankService::reihe($tankObj(), null, $jetzt - 86400, $jetzt, 'liter');
    $zeiten = $r['zeiten'];
    $sortiert = $zeiten;
    sort($sortiert);
    pruefe(count($zeiten) > 100 && $zeiten === $sortiert, 'Verlauf 24 h: Zeiten aufsteigend (' . count($zeiten) . ')');
    gleich([$r['groesse'], $r['einheit'], $r['name'], array_keys($r['serien'])], ['liter', 'l', 'Inhalt', ['liter']], 'Verlauf: Größe, Einheit, Name');
    $liter = $r['serien']['liter'];
    $vor = $nach = [];
    foreach ($zeiten as $i => $ms) {
        if ($ms < $tK2 * 1000 - 600_000) {
            $vor[] = $liter[$i];
        } elseif ($ms >= $tK2 * 1000 + 600_000 && $ms <= ($jetzt - 600) * 1000) {
            $nach[] = $liter[$i];
        }
    }
    pruefe($vor !== [] && array_filter($vor, static fn ($v) => $v !== null) === [], 'vor k2 (Inhalt je mm unbekannt): keine Liter');
    pruefe($nach !== [] && array_filter($nach, static fn ($v) => $v === null || $v <= 0) === [], 'nach k2: Liter (' . count($nach) . ' Punkte)');
    $rr = TankService::reihe($tankObj(), null, $jetzt - 86400, $jetzt, 'roh');
    $rohWerte = array_slice($rr['serien']['roh'], 0, -1);   // der letzte Punkt ist die Lücke am Ende
    pruefe($rr['zeiten'] === $zeiten && array_filter($rohWerte, static fn ($v) => $v === null) === [] && $rr['einheit'] === 'V' && $rr['name'] === 'Rohwert (Spannung)',
        'Rohwert durchgehend, auch über die Grenze der Fassungen');
    $i = array_key_last(array_filter($liter, static fn ($v) => $v !== null));
    nahe($liter[$i], (float) TankRechnung::umrechnen($k2, (float) $rr['serien']['roh'][$i])['liter'], 0.1, 'ein Punkt nach k2 rechnet mit k2');
    $h = TankService::reihe($tankObj(), null, $jetzt - 86400, $jetzt, 'hoehe_mm');
    $j = array_key_first(array_filter($h['serien']['hoehe_mm'], static fn ($v) => $v !== null));
    nahe($h['serien']['hoehe_mm'][$j], (float) TankRechnung::hoehe($k1, (float) $rr['serien']['roh'][$j]), 0.1, 'Füllhöhe vor k2 aus kX');
    gleich(TankService::reihe($tankObj(), $mpD + 999999, $jetzt - 86400, $jetzt, 'liter')['zeiten'], [], 'ein fremder Messpunkt liefert nichts');
    gleich(TankService::reihe($tankObj(), null, $jetzt - 3600, $jetzt, 'unsinn')['groesse'], 'liter', 'unbekannte Größe: Liter');

    // ------------------------------------------------ Ereignisse, Fassungen und der Kunde mit Datenfenster
    $idK3 = TankService::fassungAnlegen($mD, $k2, 'hand', Zeit::iso($jetzt - 1800), true);
    $peilungAlt = Database::insert('tank_ereignisse', ['objekt_id' => $tankD, 'messpunkt_id' => $mpD, 'art' => 'peilung', 'zeit' => Zeit::iso($jetzt - 2 * 3600),
        'prozent' => 60.0, 'von' => $kennungD, 'created_at' => gmdate('c')]);
    $peilungNeu = Database::insert('tank_ereignisse', ['objekt_id' => $tankD, 'messpunkt_id' => $mpD, 'art' => 'peilung', 'zeit' => Zeit::iso($jetzt - 1800),
        'hoehe_mm' => 650.0, 'von' => $kennungD, 'created_at' => gmdate('c')]);
    gleich(array_column(TankService::ereignisse($tankObj()), 'id'), [$peilungNeu, $peilungAlt], 'Ereignisse jüngste zuerst');
    gleich(array_column(TankService::ereignisse($tankObj()), 'text'), ['Peilstab 650 mm', 'Peilung 60 %'], 'Ereignisse als Text');
    gleich(array_column(TankService::fassungenSichtbar($tankObj()), 'id'), [$idK3, $idK2, $idKx, $idK1], 'ohne Grenze: alle Fassungen, jüngste zuerst');
    gleich(count(TankService::reihe($tankObj(), null, $jetzt - 86400, $jetzt, 'liter')['marken']), 2, 'Verlauf: Peilungen als Marken');
    $freigabe = (int) Database::insert('freigaben', ['subject_type' => 'user', 'subject_id' => $kundeD, 'ziel_art' => 'site', 'ziel_id' => $siteD,
        'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'gueltig_ab' => null, 'gueltig_bis' => null,
        'daten_ab' => Zeit::iso($jetzt - 86400), 'daten_bis' => Zeit::iso($jetzt - 3600), 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c')]);
    alsBenutzer($kundeD);
    pruefe(Zugriff::siehtObjekt($tankObj()), 'Kunde sieht den Tank');
    $l = LiveService::fuerObjekte([$tankObj()])[$tankD];
    gleich([$l['stufe'], $l['tank'], $l['zustand'], $l['alter_s']], ['gesperrt', null, [], null], 'Kunde (Datenfenster endete vor 1 h): Live gesperrt, keine Zahl');
    gleich(TankService::rohJetzt($tankObj(), $mD), null, 'Kunde: kein Rohwert für jetzt');
    $r = TankService::reihe($tankObj(), null, $jetzt - 2 * 86400, $jetzt, 'roh');
    $mit = array_keys(array_filter(array_combine($r['zeiten'], $r['serien']['roh']), static fn ($v) => $v !== null));
    pruefe($mit !== [] && min($mit) >= ($jetzt - 86400 - 300) * 1000 && max($mit) <= ($jetzt - 3600) * 1000, 'Kunde: Verlauf nur im Datenfenster');
    gleich($r['teile'], [[$jetzt - 86400, $jetzt - 3600]], 'Kunde: Teile des Verlaufs = Datenfenster');
    gleich(array_column(TankService::fassungenSichtbar($tankObj()), 'id'), [$idK2, $idKx], 'Kunde: nur Fassungen, die im Datenfenster galten');
    gleich(array_column(TankService::ereignisse($tankObj()), 'id'), [$peilungAlt], 'Kunde: nur Ereignisse im Datenfenster');
    gleich(array_column(TankService::reihe($tankObj(), null, $jetzt - 86400, $jetzt, 'liter')['marken'], 'zeit'), [($jetzt - 2 * 3600) * 1000], 'Kunde: Marken nur im Fenster');
    $seite = TankService::seitenDaten($tankObj());
    gleich([$seite['fassung'], $seite['kennlinie'], $seite['darfAendern'], $seite['sensorFrei']], [null, 'keine – nur Rohwert', false, []],
        'Kunde: die jetzige Fassung (außerhalb des Fensters) nicht, nichts zu ändern');
    alsBenutzer($adminD);

    // ------------------------------------------------ Tank ohne Sensor: letzte Peilung
    $ohne = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungD . ' Reserve', 'floor_id' => $floorD, 'eigenschaften' => ['inhalt_l' => '1000']])['id'];
    $l = LiveService::fuerObjekte([AnlagenService::find($ohne)])[$ohne];
    gleich([$l['stufe'], $l['hinweis'], $l['tank']['sensor'] ?? null], ['keine', 'kein Tanksensor eingesetzt', false], 'Tank ohne Sensor: keine Stufe');
    Database::insert('tank_ereignisse', ['objekt_id' => $ohne, 'art' => 'peilung', 'zeit' => Zeit::iso($jetzt - 3 * 3600), 'prozent' => 60.0, 'liter' => 600.0,
        'von' => $kennungD, 'created_at' => gmdate('c')]);
    $l = LiveService::fuerObjekte([AnlagenService::find($ohne)])[$ohne];
    pruefe($l['stufe'] === 'keine' && str_contains($l['hinweis'], 'Peilung') && str_contains($l['hinweis'], '60 %') && ($l['tank']['peilung']['prozent'] ?? null) == 60.0,
        'Tank ohne Sensor nennt die letzte Peilung: ' . $l['hinweis']);
    // Ein Messpunkt ohne Kennlinie und ohne Sensordaten: nichts zu lesen – kein Schein-Wert
    $leer = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungD . ' ohne Daten', 'floor_id' => $floorD, 'eigenschaften' => $eigD])['id'];
    Database::insert('messpunkte', ['objekt_id' => $leer, 'geraet' => $demo['geraet'], 'kanal' => $demo['kanal'], 'rolle' => 'fuellstand',
        'bezeichnung' => $kennungD . '-TS3', 'gueltig_ab' => Zeit::iso($jetzt - 86400), 'created_at' => gmdate('c')]);
    $l = LiveService::fuerObjekte([AnlagenService::find($leer)])[$leer];
    pruefe($l['stufe'] === 'stumm' && str_contains($l['hinweis'], 'Sensordaten unvollständig') && $l['tank']['roh'] === null, 'Sensor ohne Kennlinie und Sensordaten: ' . $l['hinweis']);
    $roh = TankService::reihe(AnlagenService::find($leer), null, $jetzt - 3600, $jetzt, 'roh');
    $lit = TankService::reihe(AnlagenService::find($leer), null, $jetzt - 3600, $jetzt, 'liter');
    pruefe(array_filter($roh['serien']['roh'] ?? [], static fn ($v) => $v !== null) !== [] && array_filter($lit['serien']['liter'] ?? [], static fn ($v) => $v !== null) === [],
        '… im Verlauf nur der Rohwert (Vorgabe Spannung), keine Liter');
    $alle = LiveService::fuerObjekte([AnlagenService::find($ohne), $tankObj()]);
    gleich(array_keys($alle), [$ohne, $tankD], 'mehrere Tanks in einem Aufruf');
} finally {
    alsBenutzer(null);
    Config::set('grenzen.tank_warnung', $warnungVorher);
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennungD . '%']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    SiteService::deleteSite($siteD);
    foreach ([$adminD, $kundeD] as $u) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$u]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$u]);
        Database::execute('DELETE FROM users WHERE id = ?', [$u]);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennungD . '%', '%' . $kennungD . '%']);
}

// ------------------------------------------------ E – Kalibrieren vor Ort (Demo-Modus)
Config::set('app.demo', true);
$kennungE = 'pm-tank-' . bin2hex(random_bytes(3));
$siteE = SiteService::saveSite(null, ['name' => 'Prüfung Tank vor Ort ' . $kennungE]);
$floorE = SiteService::saveFloor(null, ['site_id' => $siteE, 'name' => 'Wiese']);
$adminE = BenutzerService::anlegen($kennungE, 'Pruefung2026!!', 'admin', false);
$kundeE = BenutzerService::anlegen($kennungE . '-kunde', 'Pruefung2026!!', 'kunde', false);
$rolleE = (int) BenutzerService::rolleSpeichern(null, $kennungE . ' technik', '', ['anlagen.view', 'anlagen.manage', 'statistik.view'])['id'];
$technikE = BenutzerService::anlegen($kennungE . '-technik', 'Pruefung2026!!', (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rolleE]), false);
$alleFassungen = static fn (array $tanks): array => array_column(Database::select('SELECT * FROM tank_kennlinien WHERE objekt_id IN ('
    . implode(',', array_map('intval', $tanks ?: [0])) . ') ORDER BY id'), null, 'id');

try {
    alsBenutzer($adminE);
    $jetzt = time();
    $eigE = ['inhalt_l' => '3000', 'kraftstoff' => 'HVO', 'bauform' => 'Quader', 'sumpf_l' => '140'];
    $sensorE = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => '500', 'bereich_von' => '0', 'bereich_bis' => '100',
        'bereich_einheit' => 'mbar', 'schritt_roh' => '0,1'];
    $tankE = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungE . ' Tank', 'floor_id' => $floorE, 'eigenschaften' => $eigE])['id'];
    $tankF = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungE . ' Tank unbekannt', 'floor_id' => $floorE, 'eigenschaften' => $eigE])['id'];
    $ohneE = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungE . ' Reserve', 'floor_id' => $floorE, 'eigenschaften' => ['inhalt_l' => '1000']])['id'];
    // Ein Tanksensor mit Sensordaten (eigener Shelly im Stück), eingebaut vor drei
    // Tagen; gemessen wird mit der Demo-Sonde – Einsatz und Messpunkt direkt
    // angelegt, sonst ließe sich die Zeit des Einbaus nicht zurückstellen.
    $stueck = static function (string $nr, int $tank) use ($kennungE, $sensorE, $jetzt): array {
        $item = (int) InventarService::speichern(null, ['art' => 'tanksensor', 'kennung' => $kennungE . '-' . $nr, 'eigenschaften' => $sensorE,
            'messgeraete' => [['geraet' => $kennungE . '-uni-' . $nr, 'kanal' => 'voltmeter:100']]])['id'];
        $einsatz = Database::insert('einsaetze', ['inventar_id' => $item, 'objekt_id' => $tank, 'anschluss_id' => null, 'objekt_name' => 'Tank',
            'ab' => Zeit::iso($jetzt - 3 * 86400), 'bis' => null, 'von' => 'test', 'notizen' => '', 'created_at' => gmdate('c')]);
        $mp = Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
            'bezeichnung' => $kennungE . '-' . $nr, 'einsatz_id' => $einsatz, 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
        $m = Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]);
        $k1 = TankRechnung::rechnung($sensorE, ['offset_mm' => 20], AnlagenService::find($tank)['eigenschaften'], ['kennung' => $kennungE . '-' . $nr, 'text' => 'Einbau'])['rechnung'];
        $id = TankService::fassungAnlegen($m, $k1, 'einsatz', (string) $m['gueltig_ab'], false);
        return [$m, $id, $k1];
    };
    [$mE, $idK1E, $k1E] = $stueck('TS1', $tankE);
    [$mF] = $stueck('TS2', $tankF);
    $t = static fn (int $id): array => AnlagenService::find($id);
    $vorher = $alleFassungen([$tankE, $tankF]);
    $ereignis = static fn (int $id): ?array => Database::first('SELECT * FROM tank_ereignisse WHERE id = ?', [$id]);
    $roh = TankService::rohJetzt($t($tankE), $mE);
    pruefe($roh !== null && $roh['roh'] > 2.0, 'Prüfbestand: die Demo-Sonde meldet seit dem Einbau (' . ($roh['roh'] ?? '–') . ' V)');

    // ------------------------------------------------ Eintragen je Tank nur einmal zur Zeit (ohne Sitzungssperre)
    $innen = null;
    $aussen = TankService::gesperrt($tankE, static function () use (&$innen, $tankE, $tankF): array {
        $innen = [TankService::gesperrt($tankE, static fn (): array => ['ok' => true, 'message' => 'doppelt']),
            TankService::gesperrt($tankF, static fn (): array => ['ok' => true, 'message' => 'anderer Tank'])];
        return ['ok' => true, 'message' => 'erster'];
    });
    gleich([$aussen['message'], $innen[0]['ok'] ?? null, $innen[0]['message'] ?? null, $innen[1]['message'] ?? null],
        ['erster', false, TankService::GERADE_SCHON, 'anderer Tank'], 'Sperre des Tanks: der zweite Versuch hört sofort auf, ein anderer Tank ist frei');
    gleich(TankService::gesperrt($tankE, static fn (): array => ['ok' => true, 'message' => 'danach'])['message'], 'danach', 'Sperre des Tanks: danach wieder frei');
    pruefe(glob(dirname(__DIR__) . '/storage/cache/tank-*.sperre') === [], 'Sperre des Tanks: keine Sperrdatei bleibt liegen');

    // ------------------------------------------------ Betankung jetzt: erst ab 30 min auswertbar
    $e = TankService::betankungSpeichern($t($tankE), ['liter' => '2000', 'lieferschein' => 'LS-1', 'notizen' => $kennungE]);
    $abText = Zeit::anzeige(Zeit::iso(time() + TankService::AUSWERTEN_NACH_S), 'H:i');
    pruefe($e['ok'] && empty($e['kalibriert']) && str_contains($e['message'], 'ab ' . $abText), 'Betankung mit Zeit jetzt: eingetragen, Kalibrieren ab ' . $abText . ' – ' . $e['message']);
    $bJetzt = (int) ($e['id'] ?? 0);
    gleich([$ereignis($bJetzt)['art'] ?? null, (float) ($ereignis($bJetzt)['liter'] ?? 0), (int) ($ereignis($bJetzt)['messpunkt_id'] ?? 0), $ereignis($bJetzt)['lieferschein'] ?? null],
        ['betankung', 2000.0, (int) $mE['id'], 'LS-1'], 'Ereignis der Betankung mit dem Sensor, der im Tank war');
    gleich(count($alleFassungen([$tankE])), count($vorher) - 1, 'eine Betankung von eben ergibt noch keine Fassung');
    $a = TankService::betankungAuswerten($t($tankE), $bJetzt, false);
    pruefe(!$a['ok'] && str_contains($a['message'], 'ab ' . $abText), 'auswerten noch nicht möglich: ' . $a['message']);
    $eintrag = array_values(array_filter(TankService::ereignisse($t($tankE)), static fn ($x) => $x['id'] === $bJetzt))[0] ?? [];
    pruefe(($eintrag['auswerten_ab'] ?? null) !== null && ($eintrag['auswerten_jetzt'] ?? true) === false && ($eintrag['loeschbar'] ?? false) === true,
        'Protokoll: Knopf „Kalibrieren“ erst ab 30 min, löschbar');
    foreach ([['liter' => '0'], ['liter' => 'viel'], ['liter' => '4501'], ['liter' => '100', 'zeit' => Zeit::fuerEingabe(Zeit::iso($jetzt + 7200))],
        ['liter' => '100', 'zeit' => Zeit::fuerEingabe(Zeit::iso($jetzt - 8 * 86400))], ['liter' => '100', 'zeit' => 'gestern'], ['liter' => '100', 'lieferschein' => str_repeat('x', 65)]] as $falsch) {
        $e = TankService::betankungSpeichern($t($tankE), $falsch);
        pruefe(!$e['ok'], 'Betankung abgelehnt: ' . json_encode($falsch) . ' – ' . $e['message']);
    }
    gleich((int) Database::scalar("SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ? AND art = 'betankung'", [$tankE]), 1, '… und nichts davon eingetragen');

    // ------------------------------------------------ Betankung um 06:00 (die Demo-Sonde springt dort auf 2.700 l)
    $sechs = (new DateTimeImmutable('@' . $jetzt))->setTimezone(Zeit::zone())->setTime(6, 0);
    if ($sechs->getTimestamp() > $jetzt - TankService::AUSWERTEN_NACH_S) {
        $sechs = $sechs->modify('-1 day');
    }
    $e = TankService::betankungSpeichern($t($tankE), ['liter' => '528', 'zeit' => $sechs->format('Y-m-d\TH:i'), 'lieferschein' => 'DEMO-0815']);
    $bSechs = (int) ($e['id'] ?? 0);
    $eigNach = $t($tankE)['eigenschaften'];
    pruefe($e['ok'] && !empty($e['kalibriert']) && str_contains($e['message'], 'Kalibriert'), 'Betankung um 06:00: gleich kalibriert – ' . $e['message']);
    pruefe(($eigNach['liter_je_mm'] ?? 0) > 2.5 && ($eigNach['liter_je_mm'] ?? 0) < 3.3 && str_starts_with((string) ($eigNach['liter_je_mm_herkunft'] ?? ''), 'Betankung '),
        'Inhalt je mm im Tank samt Herkunft: ' . ($eigNach['liter_je_mm'] ?? '–') . ' · ' . ($eigNach['liter_je_mm_herkunft'] ?? ''));
    $fB = TankService::fassungJetzt((int) $mE['id']);
    gleich([$fB['anlass'] ?? null, $fB['geprueft'] ?? null, $fB['ereignis_id'] ?? null, $fB['rechnung']['tank']['liter_je_mm'] ?? null, $fB['rechnung']['tank']['form'] ?? null,
        $fB['rechnung']['sensor'] ?? null], ['betankung', false, $bSechs, $eigNach['liter_je_mm'] ?? -1, 'quader', $k1E['sensor']],
        'Fassung „betankung“: Inhalt je mm wie im Tank, Sensorteil bleibt – ungeprüft wie davor (die Einbautiefe prüft nur eine Peilung)');
    gleich([(float) ($ereignis($bSechs)['roh_vor'] ?? 0), (float) ($ereignis($bSechs)['roh_nach'] ?? 0)], [6.7, 7.8], 'Rohwerte vor und nach dem Tanken (vom Server) im Ereignis');
    $a = TankService::betankungAuswerten($t($tankE), $bSechs, false);
    pruefe(!$a['ok'] && str_contains($a['message'], 'schon ausgewertet'), 'zweimal auswerten geht nicht');
    $l = LiveService::fuerObjekte([$t($tankE)])[$tankE];
    pruefe(($l['tank']['liter'] ?? 0) > 0 && ($l['tank']['geprueft'] ?? true) === false && str_contains((string) ($l['tank']['kennlinie'] ?? ''), 'Betankung')
        && str_contains((string) ($l['tank']['kennlinie'] ?? ''), 'seit Einbau nicht gepeilt'),
        'Live rechnet jetzt mit Litern aus der Betankung, „seit Einbau nicht gepeilt“ – ' . ($l['tank']['kennlinie'] ?? ''));
    pruefe(str_contains((string) ($l['tank']['kennlinie'] ?? ''), 'Betankung ' . $sechs->format('d.m.Y H:i')),
        'Kennlinie nennt die Zeit der Betankung (' . $sechs->format('H:i') . '), nicht die der Auswertung – ' . ($l['tank']['kennlinie'] ?? ''));

    // ------------------------------------------------ Peilung 60 % am Demo-Tank
    $e = TankService::peilen($t($tankE), ['prozent' => '60', 'notizen' => 'Anzeige am Domdeckel']);
    $pSechzig = (int) ($e['id'] ?? 0);
    pruefe($e['ok'] && !empty($e['kalibriert']), 'Peilung 60 %: kalibriert – ' . $e['message']);
    $p = $ereignis($pSechzig);
    $roh = TankService::rohJetzt($t($tankE), $mE);
    gleich([$p['art'] ?? null, (float) ($p['prozent'] ?? 0), (float) ($p['liter'] ?? 0), (float) ($p['roh'] ?? 0), (int) ($p['messpunkt_id'] ?? 0), $p['notizen'] ?? null],
        ['peilung', 60.0, 1800.0, (float) ($roh['roh'] ?? -1), (int) $mE['id'], 'Anzeige am Domdeckel'], 'Ereignis mit dem Rohwert vom Server, Inhalt aus dem Nennvolumen');
    $fP = TankService::fassungJetzt((int) $mE['id']);
    gleich([$fP['anlass'] ?? null, $fP['geprueft'] ?? null, $fP['ereignis_id'] ?? null, $fP['rechnung']['sensor']['mm_je_roh'] ?? null],
        ['peilung', true, $pSechzig, $k1E['sensor']['mm_je_roh']], 'Fassung „peilung“: neuer Anker, Steigung bleibt');
    nahe(TankRechnung::umrechnen($fP['rechnung'], (float) $p['roh'])['prozent'], 60.0, 0.1, 'die neue Fassung ergibt bei diesem Rohwert 60 %');
    nahe(LiveService::fuerObjekte([$t($tankE)])[$tankE]['tank']['prozent'] ?? null, 60.0, 0.1, 'Live zeigt nach der Peilung 60 %');
    gleich((float) ($t($tankE)['eigenschaften']['liter_je_mm'] ?? 0), (float) $eigNach['liter_je_mm'], 'bei bekanntem Inhalt je mm ändert die Peilung den Tank nicht');
    foreach ([[], ['prozent' => '60', 'liter' => '1800'], ['prozent' => '120'], ['hoehe_mm' => '-1'], ['liter' => '5000'], ['hoehe_mm' => 'tief']] as $falsch) {
        $e = TankService::peilen($t($tankE), $falsch);
        pruefe(!$e['ok'], 'Peilung abgelehnt: ' . json_encode($falsch) . ' – ' . $e['message']);
    }
    $e = TankService::peilen(array_merge($t($ohneE), ['eigenschaften' => []]), ['prozent' => '60']);
    pruefe(!$e['ok'] && str_contains($e['message'], 'Nennvolumen'), 'Anzeige in % nur mit Nennvolumen – ' . $e['message']);

    // Bei unbekanntem Inhalt je mm lernt der Tank aus der Peilung
    $e = TankService::peilen($t($tankF), ['prozent' => '60']);
    $eigF = $t($tankF)['eigenschaften'];
    $fF = TankService::fassungJetzt((int) $mF['id']);
    pruefe($e['ok'] && ($eigF['liter_je_mm'] ?? 0) > 0 && str_starts_with((string) ($eigF['liter_je_mm_herkunft'] ?? ''), 'Peilung ') && str_contains((string) $eigF['liter_je_mm_herkunft'], '(60 %)'),
        'Tank ohne Inhalt je mm: die Peilung bestimmt ihn – ' . ($eigF['liter_je_mm_herkunft'] ?? ''));
    gleich([$fF['anlass'] ?? null, $fF['rechnung']['tank']['form'] ?? null, $fF['rechnung']['tank']['liter_je_mm'] ?? null], ['peilung', 'quader', $eigF['liter_je_mm'] ?? -1],
        '… Fassung und Tank haben denselben Wert');
    nahe(TankRechnung::umrechnen($fF['rechnung'], (float) TankService::rohJetzt($t($tankF), $mF)['roh'])['liter'], 1800.0, 0.5, '… und rechnen bei diesem Rohwert 1.800 l');

    // Tank ohne Sensor: nur das Ereignis, Stufe „keine“, der Hinweis nennt die Peilung
    $nVorher = count($alleFassungen([$tankE, $tankF, $ohneE]));
    $e = TankService::peilen($t($ohneE), ['prozent' => '40']);
    $l = LiveService::fuerObjekte([$t($ohneE)])[$ohneE];
    pruefe($e['ok'] && empty($e['kalibriert']) && $ereignis((int) $e['id'])['messpunkt_id'] === null && $l['stufe'] === 'keine'
        && str_contains($l['hinweis'], 'Peilung') && str_contains($l['hinweis'], '40 %') && str_contains($l['hinweis'], '400 l'),
        'Tank ohne Sensor: Peilung nur im Protokoll – „' . $l['hinweis'] . '“');
    gleich(count($alleFassungen([$tankE, $tankF, $ohneE])), $nVorher, '… ohne Fassung');
    $e = TankService::betankungSpeichern($t($ohneE), ['liter' => '500']);
    pruefe($e['ok'] && $ereignis((int) $e['id'])['messpunkt_id'] === null && str_contains($e['message'], 'nur im Protokoll'), 'Betankung ohne Sensor: nur im Protokoll');

    // ------------------------------------------------ Löschen: nur die jüngste Kalibrierung
    $e = TankService::ereignisLoeschen($t($tankE), $bSechs);
    pruefe(!$e['ok'] && str_contains($e['message'], 'Danach wurde schon neu kalibriert') && $ereignis($bSechs) !== null,
        'eine Betankung mit späterer Fassung lässt sich nicht löschen – ' . $e['message']);
    $liste = array_column(TankService::ereignisse($t($tankE)), null, 'id');
    gleich([$liste[$bSechs]['loeschbar'] ?? null, $liste[$bSechs]['fassung'] ?? null, $liste[$pSechzig]['loeschbar'] ?? null], [false, true, true], 'Protokoll: was sich löschen lässt');
    $e = TankService::ereignisLoeschen($t($tankE), $pSechzig);
    $z1 = TankService::fassungJetzt((int) $mE['id']);
    pruefe($e['ok'] && $ereignis($pSechzig) === null, 'die jüngste Peilung gelöscht: ' . $e['message']);
    gleich([$z1['anlass'] ?? null, $z1['ab'] ?? null, $z1['rechnung']['sensor'] ?? null, $z1['rechnung']['tank'] ?? null, $z1['ereignis_id'] ?? null],
        ['zurueck', $fP['ab'], $fB['rechnung']['sensor'], $fB['rechnung']['tank'], $bSechs], 'Fassung „zurueck“ ab der gelöschten: eine Kopie der vorigen');
    $e = TankService::ereignisLoeschen($t($tankE), $bSechs);
    $z2 = TankService::fassungJetzt((int) $mE['id']);
    $eig = $t($tankE)['eigenschaften'];
    pruefe($e['ok'] && $ereignis($bSechs) === null, 'danach lässt sich die Betankung löschen: ' . $e['message']);
    gleich([$z2['anlass'] ?? null, $z2['ab'] ?? null, $z2['rechnung']['sensor'] ?? null, $z2['rechnung']['tank'] ?? null, array_key_exists('liter_je_mm', $eig), array_key_exists('liter_je_mm_herkunft', $eig)],
        ['zurueck', $fB['ab'], $k1E['sensor'], $k1E['tank'], false, false], 'Betankung gelöscht: Kennlinie und Inhalt je mm wie vorher (unbekannt)');
    $l = LiveService::fuerObjekte([$t($tankE)])[$tankE];
    pruefe(array_key_exists('liter', $l['tank'] ?? []) && $l['tank']['liter'] === null && ($l['tank']['hoehe_mm'] ?? null) !== null, 'Live wieder ohne Liter, nur mit Füllhöhe');
    $audit = array_column(Database::select("SELECT action FROM audit_log WHERE target_type = 'objekt' AND target_id = ? AND action LIKE 'tank.%'", [(string) $tankE]), 'action');
    pruefe(in_array('tank.peilung', $audit, true) && in_array('tank.betankung', $audit, true) && in_array('tank.ereignis_geloescht', $audit, true), 'Audit: Peilung, Betankung, Löschen');
    $e = TankService::ereignisLoeschen($t($tankE), $bJetzt);
    pruefe($e['ok'] && $ereignis($bJetzt) === null && TankService::fassungJetzt((int) $mE['id'])['id'] === $z2['id'], 'ein Ereignis ohne Fassung ist einfach weg');
    gleich(TankService::ereignisLoeschen($t($tankF), (int) Database::scalar('SELECT id FROM tank_ereignisse WHERE objekt_id = ? LIMIT 1', [$ohneE]))['ok'], false,
        'ein Ereignis eines anderen Tanks lässt sich nicht löschen');

    // ------------------------------------------------ Steigung aus zwei Peilungen – nur auf Wunsch
    $roh = (float) TankService::rohJetzt($t($tankE), $mE)['roh'];
    $alt = Database::insert('tank_ereignisse', ['objekt_id' => $tankE, 'messpunkt_id' => (int) $mE['id'], 'art' => 'peilung', 'zeit' => Zeit::iso($jetzt - 7200),
        'hoehe_mm' => 480.0, 'roh' => $roh - 2.0, 'von' => $kennungE, 'created_at' => gmdate('c')]);
    gleich(TankService::steigungAngebot($t($tankE)), null, 'nach einer einzelnen Peilung kein Angebot');
    $e = TankService::peilen($t($tankE), ['hoehe_mm' => '800']);
    $pStab = (int) ($e['id'] ?? 0);
    $angebot = TankService::steigungAngebot($t($tankE));
    pruefe($e['ok'] && str_contains($e['message'], 'Steigung'), 'Peilstab 800 mm: kalibriert, mit Hinweis auf die Steigung – ' . $e['message']);
    $fStab = TankService::fassungJetzt((int) $mE['id']);
    gleich([$fStab['rechnung']['sensor']['roh_anker'] ?? null, $fStab['rechnung']['sensor']['mm_anker'] ?? null, $fStab['rechnung']['sensor']['mm_je_roh'] ?? null],
        [round($roh, 6), 800.0, $k1E['sensor']['mm_je_roh']], 'der Peilstab setzt nur den Anker');
    nahe($angebot['mm_je_roh'] ?? null, 160.0, 0.001, 'Angebot: Steigung aus beiden Peilungen 320 mm / 2 V');
    gleich([$angebot['ereignis'] ?? null, array_key_exists('warnung', $angebot ?? []) ? $angebot['warnung'] : 'x', round((float) ($angebot['abweichung'] ?? 0), 1)], [$pStab, null, -2.1],
        '… knapp an den Sensordaten (163,41 mm/V), ohne Warnung');
    $n = count($alleFassungen([$tankE]));
    $e = TankService::steigungSetzen($t($tankE), $pStab + 999);
    gleich([$e['ok'], count($alleFassungen([$tankE]))], [false, $n], 'ein anderes Ereignis setzt nichts');
    $e = TankService::steigungSetzen($t($tankE), $pStab);
    $fSt = TankService::fassungJetzt((int) $mE['id']);
    pruefe($e['ok'] && ($fSt['anlass'] ?? '') === 'peilung' && ($fSt['geprueft'] ?? false) && ($fSt['ereignis_id'] ?? 0) === $pStab
        && abs((float) $fSt['rechnung']['sensor']['mm_je_roh'] - 160.0) < 1e-6 && (float) $fSt['rechnung']['sensor']['mm_anker'] === 800.0,
        'Steigung gesetzt: neue Fassung mit 160 mm/V, Anker auf der jüngeren Peilung – ' . $e['message']);
    gleich(TankService::steigungAngebot($t($tankE)), null, 'danach kein Angebot mehr');
    nahe(TankRechnung::hoehe($fSt['rechnung'], $roh - 2.0), 480.0, 0.01, 'beide Peilungen liegen auf der neuen Kennlinie');
    Database::execute('DELETE FROM tank_ereignisse WHERE id = ?', [$alt]);

    // ------------------------------------------------ Anker und Steigung von Hand, rückwirkend
    $hand = ['messpunkt' => (int) $mE['id'], 'sensor' => ['roh_anker' => '2', 'mm_anker' => '20', 'mm_je_roh' => '163,4135']];
    foreach ([['sensor' => $hand['sensor'] + ['roh_min' => '0']], ['sensor' => ['mm_je_roh' => '1e999'] + $hand['sensor']], ['sensor' => ['mm_je_roh' => '0'] + $hand['sensor']],
        ['sensor' => 'kein Feld'], ['messpunkt' => (int) $mE['id'] + 999]] as $falsch) {
        $e = TankService::sensorVonHand($t($tankE), $falsch + $hand, false);
        pruefe(!$e['ok'], 'Kennlinie von Hand abgelehnt: ' . json_encode($falsch) . ' – ' . $e['message']);
    }
    $n = count($alleFassungen([$tankE]));
    gleich(count($alleFassungen([$tankE])), $n, '… nichts angelegt');
    $e = TankService::sensorVonHand($t($tankE), $hand, false);
    $fH = TankService::fassungJetzt((int) $mE['id']);
    pruefe($e['ok'] && ($fH['anlass'] ?? '') === 'hand' && ($fH['geprueft'] ?? true) === false && (float) $fH['rechnung']['sensor']['mm_je_roh'] === 163.4135
        && $fH['rechnung']['tank'] === $fSt['rechnung']['tank'], 'von Hand ab jetzt: Fassung „hand“, ungeprüft, Tankteil bleibt');
    // rückwirkend nur ohne Datengrenze
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $technikE, 'ziel_art' => 'site', 'ziel_id' => $siteE, 'darf_bearbeiten' => 1,
        'daten_ab' => Zeit::fuerEingabe(Zeit::iso($jetzt - 2 * 86400))]);
    alsBenutzer($technikE);
    pruefe(Zugriff::darfBearbeiten($t($tankE)) && Zugriff::datenFenster($t($tankE)) !== null, 'Technik: darf bearbeiten, sieht Daten erst ab vorgestern');
    $e = TankService::sensorVonHand($t($tankE), $hand, true);
    pruefe(!$e['ok'] && str_contains($e['message'], 'Rückwirkend'), 'rückwirkend mit begrenztem Datenfenster abgelehnt – ' . $e['message']);
    pruefe(TankService::seitenDaten($t($tankE))['rueckwirkend'] === false, '… und der Haken steht nicht auf der Seite');
    $e = TankService::peilen($t($tankE), ['hoehe_mm' => '700']);
    pruefe($e['ok'] && !empty($e['kalibriert']), 'Technik peilt im freigegebenen Zeitraum: ' . $e['message']);
    $peilTechnik = (int) $e['id'];
    $nEreignisse = (int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankE]);
    $b = TankService::betankungSpeichern($t($tankE), ['liter' => '500', 'zeit' => Zeit::fuerEingabe(Zeit::iso($jetzt - 3 * 86400)), 'kalibrieren' => false]);
    pruefe(!$b['ok'] && str_contains($b['message'], 'außerhalb deines freigegebenen Zeitraums')
        && (int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankE]) === $nEreignisse,
        'Technik: Betankung vor dem eigenen Datenfenster abgelehnt, nichts eingetragen – ' . $b['message']);
    // Das Datenfenster endete vor einer Stunde: sehen ja, eintragen nicht mehr – was ab jetzt gälte, sähe er nicht.
    Database::execute("UPDATE freigaben SET daten_bis = ? WHERE subject_type = 'user' AND subject_id = ?", [Zeit::iso($jetzt - 3600), $technikE]);
    Zugriff::vergessen();
    $nFassungen = count($alleFassungen([$tankE]));
    foreach (['peilen' => [['hoehe_mm' => '650']], 'betankungSpeichern' => [['liter' => '100', 'zeit' => Zeit::fuerEingabe(Zeit::iso($jetzt - 7200))]],
        'ereignisLoeschen' => [$peilTechnik], 'sensorVonHand' => [$hand, false], 'betankungAuswerten' => [$bSechs, false], 'steigungSetzen' => [$peilTechnik]] as $methode => $argumente) {
        $nein = TankService::$methode($t($tankE), ...$argumente);
        pruefe(!$nein['ok'] && $nein['message'] === TankService::AUSSERHALB_FENSTER, 'Technik nach dem Ende des Datenfensters: ' . $methode . ' abgelehnt');
    }
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankE]), count($alleFassungen([$tankE]))], [$nEreignisse, $nFassungen],
        '… nichts eingetragen, keine Fassung');
    $seiteT = TankService::seitenDaten($t($tankE));
    $panelT = TankService::panelDaten($t($tankE));
    gleich([$seiteT['darfAendern'], $seiteT['darfEintragen'], $seiteT['steigung'], $panelT['darf'], TankService::tage($t($tankE), $jetzt - 86400, $jetzt)['darf_eintragen']],
        [true, false, null, ['peilen' => false, 'betanken' => false, 'einsetzen' => false], false], '… Tankseite, Kartenpanel und Tage ohne Eintragen');
    Database::execute("UPDATE freigaben SET daten_bis = NULL WHERE subject_type = 'user' AND subject_id = ?", [$technikE]);
    Zugriff::vergessen();
    alsBenutzer($kundeE);
    foreach (['peilen' => [['prozent' => '50']], 'betankungSpeichern' => [['liter' => '100']], 'ereignisLoeschen' => [(int) $e['id']],
        'sensorVonHand' => [$hand, false], 'betankungAuswerten' => [(int) $e['id'], false], 'steigungSetzen' => [(int) $e['id']]] as $methode => $argumente) {
        gleich(TankService::$methode($t($tankE), ...$argumente)['ok'], false, 'ohne „bearbeiten“ trägt niemand etwas ein: ' . $methode);
    }
    alsBenutzer($adminE);
    $e = TankService::sensorVonHand($t($tankE), $hand, true);
    $fR = TankService::fassungJetzt((int) $mE['id']);
    pruefe($e['ok'] && ($fR['anlass'] ?? '') === 'rueckwirkend' && ($fR['ab'] ?? '') === $mE['gueltig_ab'], 'rückwirkend mit allen Rechten: Fassung ab dem Einbau – ' . $e['message']);
    gleich((int) (TankService::fassungJetzt((int) $mE['id'], $jetzt - 2 * 86400)['id'] ?? 0), (int) $fR['id'], '… sie gilt auch für vorgestern');
    $audit = Database::first("SELECT details FROM audit_log WHERE action = 'tank.kennlinie' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $tankE]);
    gleich(json_decode((string) ($audit['details'] ?? ''), true)['rueckwirkend'] ?? null, true, 'im Audit als rückwirkend vermerkt');

    // ------------------------------------------------ Erste Peilung beim Einsetzen und ein Sensorfehler
    $e = TankService::peilen($t($tankE), ['hoehe_mm' => '650']);
    pruefe($e['ok'] && !empty($e['kalibriert']), 'Peilstab nach „rückwirkend“: kalibriert');

    // ------------------------------------------------ Fassungen ändern sich nie
    $danach = $alleFassungen([$tankE, $tankF]);
    $gleich = true;
    foreach ($vorher as $id => $zeile) {
        $gleich = $gleich && isset($danach[$id]) && $danach[$id] == $zeile;
    }
    pruefe($gleich && count($danach) > count($vorher) + 8, 'Peilen, Betanken, Löschen, von Hand: nur neue Fassungen, ältere unverändert (' . count($danach) . ')');
    pruefe(array_filter($danach, static fn ($f) => TankRechnung::pruefen(json_decode((string) $f['rechnung'], true)) !== null) === [], 'jede neue Fassung besteht pruefen()');
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$kennungE . '%']) as $i) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), (int) $i['id']]);
        Database::execute('UPDATE messpunkte SET gueltig_bis = ? WHERE bezeichnung LIKE ? AND gueltig_bis IS NULL', [gmdate('c'), $kennungE . '%']);
        InventarService::loeschen((int) $i['id']);
    }
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennungE . '%']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    Database::execute('DELETE FROM einsaetze WHERE objekt_id IN (' . implode(',', array_map('intval', [$tankE ?? 0, $tankF ?? 0, $ohneE ?? 0])) . ')');
    SiteService::deleteSite($siteE);
    foreach ([$adminE, $kundeE, $technikE] as $u) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$u]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$u]);
        Database::execute('DELETE FROM users WHERE id = ?', [$u]);
    }
    BenutzerService::rolleLoeschen($rolleE);
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennungE . '%', '%' . $kennungE . '%']);
    foreach ([$tankE ?? 0, $tankF ?? 0, $ohneE ?? 0] as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'objekt' AND target_id = ?", [(string) $id]);
    }
}

// ------------------------------------------------ F – Kraftstoffschlauch, Versorgung und Restlaufzeit (Demo-Modus)
Config::set('app.demo', true);
$kennungF = 'pm-tank-' . bin2hex(random_bytes(3));
$siteF = SiteService::saveSite(null, ['name' => 'Prüfung Versorgung ' . $kennungF]);
$floorF = SiteService::saveFloor(null, ['site_id' => $siteF, 'name' => 'Wiese']);
// quadratischer Plan, 1 % = 2 m – Schläuche haben eine Länge
Database::update('floors', $floorF, ['massstab_ax' => 0.0, 'massstab_ay' => 50.0, 'massstab_bx' => 50.0, 'massstab_by' => 50.0,
    'massstab_m' => 100.0, 'massstab_verhaeltnis' => 1.0, 'massstab_von' => 'test', 'massstab_am' => gmdate('c')]);
$adminF = BenutzerService::anlegen($kennungF, 'Pruefung2026!!', 'admin', false);
$kundeF = BenutzerService::anlegen($kennungF . '-kunde', 'Pruefung2026!!', 'kunde', false);

try {
    alsBenutzer($adminF);
    $jetzt = time();
    $eigF = ['inhalt_l' => '3000', 'kraftstoff' => 'HVO', 'bauform' => 'Quader', 'sumpf_l' => '140', 'liter_je_mm' => '2,78'];
    $sensorF = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
        'bereich_einheit' => 'mbar', 'schritt_roh' => 0.1];
    $neuF = static fn (array $d): int => (int) AnlagenService::speichern(null, $d + ['floor_id' => $floorF, 'plan_x' => 40.0, 'plan_y' => 40.0])['id'];
    // Zwei Tanks an der Demo-Sonde (Einbau vor drei Tagen, kalibriert mit 2,78 l/mm), ein Generator mit Kennlinie, eine Verteilung
    $tankMit = static function (string $name) use ($neuF, $eigF, $sensorF, $kennungF, $jetzt): int {
        $id = $neuF(['art' => 'tank', 'name' => $kennungF . ' ' . $name, 'eigenschaften' => $eigF]);
        $mp = Database::insert('messpunkte', ['objekt_id' => $id, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
            'bezeichnung' => $kennungF . '-' . $name, 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
        $r = TankRechnung::rechnung($sensorF, ['offset_mm' => 20], $eigF, ['kennung' => $kennungF . '-' . $name, 'text' => 'Einbau'])['rechnung'];
        TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]), $r, 'einsatz', Zeit::iso($jetzt - 3 * 86400), true);
        return $id;
    };
    $t1 = $tankMit('Tank 1');
    $t2 = $tankMit('Tank 2');
    $gen = $neuF(['art' => 'generator', 'name' => $kennungF . ' Aggregat', 'nennleistung_kva' => 100, 'ohne_anschluesse' => true,
        'eigenschaften' => ['verbrauch_kennlinie' => '100% 22L / 70% 16L / 50% 12L', 'verbrauch_l_h' => 22, 'tank_l' => 400]]);
    $gen2 = $neuF(['art' => 'generator', 'name' => $kennungF . ' Reserve-Aggregat', 'nennleistung_kva' => 40, 'ohne_anschluesse' => true]);
    $vert = $neuF(['art' => 'verteilung', 'name' => $kennungF . ' Verteilung']);
    $o = static fn (int $id): array => AnlagenService::find($id);
    $schlauch = static fn (array $d): array => LeitungService::speichern(null, $d + ['art' => 'kraftstoff', 'floor_id' => $floorF, 'name' => $kennungF . ' Schlauch']);

    // ------------------------------------------------ Schlauch nur zwischen Tank und Generator
    gleich(LeitungService::ARTEN['kraftstoff'] ?? null, 'Kraftstoffschlauch', 'Leitungsart Kraftstoffschlauch');
    pruefe(in_array('DN 10 (3/8″)', LeitungService::SCHLAUCHTYPEN, true), 'Schlauchtypen als Vorschlag');
    $e = $schlauch(['von_objekt_id' => $gen, 'zu_objekt_id' => $t1, 'punkte' => [[50, 50], [45, 45], [40, 40]]]);
    $s1 = (int) ($e['id'] ?? 0);
    $l = LeitungService::find($s1);
    pruefe($e['ok'] && (int) $l['von_objekt_id'] === $t1 && (int) $l['zu_objekt_id'] === $gen && $l['punkte'][0] === ['x' => 40.0, 'y' => 40.0],
        'Schlauch Generator → Tank gezogen: getauscht, „von“ ist der Tank, der Weg beginnt dort – ' . $e['message']);
    gleich([$l['anzahl'], $l['modell'], $l['von_anschluss_id'], $l['zu_anschluss_id'], $l['querschnitt_mm2'], $l['adern'], $l['fest']], [2, 'HK-A3', null, null, null, null, 0],
        'Vorgaben: Vor- und Rücklauf, Kupplung HK-A3, keine Anschlüsse, kein Querschnitt, mobil');
    gleich($l['hinweise'], [], 'Schlauch mit Vor- und Rücklauf ohne Hinweis');
    foreach ([
        'Tank ↔ Verteilung'   => [['von_objekt_id' => $t1, 'zu_objekt_id' => $vert], 'ist kein Generator'],
        'Generator ↔ Verteilung' => [['von_objekt_id' => $vert, 'zu_objekt_id' => $gen], 'ist kein Tank'],
        'Tank ↔ Tank'         => [['von_objekt_id' => $t1, 'zu_objekt_id' => $t2], 'ist kein Generator'],
        'ein Ende offen'      => [['von_objekt_id' => $t1, 'zu_objekt_id' => 0], 'beide Enden'],
        'drei Stränge'        => [['von_objekt_id' => $t1, 'zu_objekt_id' => $gen, 'anzahl' => 3], 'zwei'],
    ] as $was => [$daten, $text]) {
        $e = $schlauch($daten + ['punkte' => [[1, 1], [2, 2]]]);
        pruefe(!$e['ok'] && str_contains($e['message'], $text), 'Schlauch abgelehnt (' . $was . '): ' . $e['message']);
    }
    $typT = $neuF(['art' => 'tank', 'name' => $kennungF . ' Tank-Typ', 'ist_vorlage' => 1]);
    pruefe(!$schlauch(['von_objekt_id' => $typT, 'zu_objekt_id' => $gen])['ok'], 'Schlauch an einem Katalog-Typ abgelehnt');
    gleich([LeitungService::kupplungVorschlag(['eigenschaften' => ['tankanschluss' => 'Steckkupplung HK-A3']]),
        LeitungService::kupplungVorschlag(['eigenschaften' => ['tankanschluss' => '2 Anschlüsse']]), LeitungService::kupplungVorschlag(null)],
        ['Steckkupplung HK-A3', 'HK-A3', 'HK-A3'], 'Kupplung aus dem Tankanschluss, „2 Anschlüsse“ ist keine');
    pruefe(!LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorF, 'von_objekt_id' => $gen, 'zu_objekt_id' => $t1, 'punkte' => [[1, 1], [2, 2]]])['ok'],
        'ein Kabel endet weiterhin nicht am Tank');
    // ohne gezeichneten Weg: gerade vom Tank zum Generator; nur Vorlauf ergibt einen Hinweis
    $e = $schlauch(['von_objekt_id' => $t2, 'zu_objekt_id' => $gen2, 'anzahl' => '1', 'kabeltyp' => 'DN 13 (1/2″)']);
    $s2 = (int) ($e['id'] ?? 0);
    $l = LeitungService::find($s2);
    pruefe($e['ok'] && count($l['punkte']) === 2 && in_array('Nur Vorlauf – Rücklauf fehlt? Der Generator führt nicht verbrannten Kraftstoff zurück.', $l['hinweise'], true),
        'Schlauch ohne Weg: gerade Linie; nur Vorlauf – „Rücklauf fehlt?“');
    // Enden umstecken: an einen anderen Generator ja, ins Leere oder an eine Verteilung nicht
    pruefe(!LeitungService::endeSetzen($s2, 'zu', null, [[1, 1], [2, 2]])['ok'], 'Schlauchende lässt sich nicht lösen');
    pruefe(!LeitungService::endeSetzen($s2, 'zu', $vert, [[1, 1], [2, 2]])['ok'], 'Schlauchende nicht an eine Verteilung');
    $e = LeitungService::endeSetzen($s2, 'zu', $gen, [[40, 40], [45, 45]]);
    pruefe($e['ok'] && (int) LeitungService::find($s2)['zu_objekt_id'] === $gen && str_contains($e['message'], 'Schlauch an'), 'Schlauchende an den anderen Generator: ' . $e['message']);
    // Ein Generator an zwei Tanks: Rücklauf in denselben Tank
    pruefe(array_filter(LeitungService::find($s1)['hinweise'], static fn ($h) => str_contains($h, 'Rücklauf muss in denselben Tank')) !== []
        && !str_contains(implode(' ', LeitungService::find($s1)['hinweise']), 'Aggregat'),
        'Generator an zwei Tanks: „Rücklauf muss in denselben Tank“ – ohne Namen (die Karte zeigt Hinweise jedem, der den Schlauch sieht)');
    gleich(TankService::versorgung($t1), ['tanks' => [$t1, $t2], 'generatoren' => [$gen]], 'Versorgung: beide Tanks am selben Generator');
    gleich(array_map(static fn ($g) => (int) $g['id'], TankService::verbraucher([$t1])[$t1] ?? []), [$gen], 'Verbraucher am Schlauch des Tanks');
    // Schlauch zählt nicht als Kabel
    gleich([AnlagenService::belegung($gen), AnlagenService::speisung($gen), LeitungService::anObjekt($gen)], [[], [], []], 'Schlauch nicht in Belegung, Speisung und Kabeln des Generators');
    gleich(LeitungService::stueckliste($floorF)['schlaeuche']['DN 13 (1/2″)']['anzahl'] ?? null, 1, 'Stückliste: Schläuche je Typ');
    gleich(LeitungService::stueckliste($floorF)['kupplungen']['HK-A3'] ?? null, 6, 'Stückliste: zwei Kupplungen je Strang (2 + 1 Stränge)');

    // ------------------------------------------------ Restlaufzeit aus Kennlinie × Last
    $gLive = static fn (float $p, array $zustand = []): array => [$gen => ['stufe' => 'ok', 'p' => $p, 'zustand' => $zustand, 'alter_s' => 2]];
    $l = TankService::live([$o($t1)], $gLive(64000.0))[$t1];
    $t = $l['tank'];
    $n1 = (float) $t['nutzbar_l'];
    pruefe(abs($t['verbrauch_l_h'] - 18.0) < 1e-6 && $t['verbrauch_quelle'] === 'last' && $t['verbrauch_text'] === '18 l/h aus der Last',
        'Aggregat 64 kW bei 100 kVA = 80 % Last → 18 l/h aus der Kennlinie');
    $n2 = (float) (TankService::live([$o($t2)], $gLive(64000.0))[$t2]['tank']['nutzbar_l'] ?? 0);
    nahe($t['vorrat_l'], $n1 + $n2, 0.2, 'Vorrat = Σ nutzbar beider Tanks');
    nahe($t['restlaufzeit_h'], ($n1 + $n2) / 18, 0.1, 'Restlaufzeit = Vorrat / 18 l/h');
    gleich([(int) Zeit::ts($t['leer_um']) - (int) Zeit::ts($t['nachbestellen_bis']), $t['vorrat_tanks'], $t['vorrat_vollstaendig']], [24 * 3600, 2, true],
        'nachbestellen bis = leer um − 24 h Lieferzeit');
    pruefe(abs((int) Zeit::ts($t['leer_um']) - ($jetzt + $t['restlaufzeit_h'] * 3600)) < 120 && is_string($t['nachbestellen_text']) && is_string($t['leer_um_text']),
        'leer um = jetzt + Restlaufzeit (' . $t['leer_um_text'] . ', nachbestellen bis ' . $t['nachbestellen_text'] . ')');
    gleich($t['generatoren'], [['id' => $gen, 'name' => $kennungF . ' Aggregat', 'laeuft' => true]], 'Generatoren der Versorgung: Name und „läuft“, keine Leistung');
    pruefe(!str_contains(json_encode($l), '64000'), 'Die Antwort des Tanks nennt die Leistung des Generators nicht');
    pruefe(str_contains($l['hinweis'], 'noch ') && in_array($l['stufe'], ['ok', 'warn'], true), 'Hinweis mit Restlaufzeit: ' . $l['hinweis']);
    $beide = TankService::live([$o($t1), $o($t2)], $gLive(64000.0));
    gleich([$beide[$t1]['tank']['restlaufzeit_h'], $beide[$t1]['tank']['vorrat_l']], [$beide[$t2]['tank']['restlaufzeit_h'], $beide[$t2]['tank']['vorrat_l']],
        'zwei Tanks an einem Generator: gleiche Restlaufzeit, gleicher Vorrat');
    gleich(array_keys($beide), [$t1, $t2], 'Antwort in der gefragten Reihenfolge');
    // Viel Verbrauch, aber der Pegel meldet seit Stunden nichts: „meldet nicht“
    AnlagenService::eigenschaftSetzen($gen, ['verbrauch_kennlinie' => '100% 900L'], true);
    $l = TankService::live([$o($t1)], $gLive(80000.0))[$t1];
    // Die Demo-Sonde meldet etwa alle zwei Stunden einen neuen Schritt: Ist ihr Wert älter als 1 h (bei 900 l/h
    // müssten es 20 Schritte je Stunde sein), meldet sie nicht; sonst ist die Restlaufzeit (5 h) zu knapp für die Lieferzeit.
    pruefe($l['stufe'] === 'warn' && str_contains($l['hinweis'], $l['alter_s'] > 3600 ? 'meldet nicht, obwohl ' . $kennungF . ' Aggregat läuft' : 'nachbestellen'),
        'Generator verbraucht 900 l/h, letzter Wert ' . $l['tank']['stand'] . ': gelb – ' . $l['hinweis']);
    AnlagenService::eigenschaftSetzen($gen, ['verbrauch_kennlinie' => '100% 22L / 70% 16L / 50% 12L'], true);
    // Wenig Vorrat (Sumpf fast so groß wie der Inhalt): Restlaufzeit unter 4 h – rot; die Tankdaten ergeben je eine neue Fassung
    $liter = (float) $l['tank']['liter'];
    foreach ([$t1, $t2] as $id) {
        AnlagenService::eigenschaftSetzen($id, ['sumpf_l' => round($liter - 30)]);
    }
    $l = TankService::live([$o($t1)], $gLive(64000.0))[$t1];
    pruefe($l['stufe'] === 'bad' && str_contains($l['hinweis'], 'sofort nachtanken') && $l['tank']['restlaufzeit_h'] < 4 && abs($l['tank']['vorrat_l'] - 60) < 1,
        'Vorrat 60 l bei 18 l/h: Restlaufzeit unter 4 h, rot – ' . $l['hinweis']);
    foreach ([$t1, $t2] as $id) {
        AnlagenService::eigenschaftSetzen($id, ['sumpf_l' => 140]);
    }

    // Generator steht: kein Verbrauch, keine Restlaufzeit
    $l = TankService::live([$o($t1)], $gLive(0.0))[$t1];
    pruefe(str_contains($l['hinweis'], 'Generator steht') && $l['tank']['restlaufzeit_h'] === null && $l['tank']['verbrauch_l_h'] == 0.0
        && $l['tank']['generatoren'][0]['laeuft'] === false, 'Generator steht (0 W): „Generator steht“, keine Restlaufzeit – ' . $l['hinweis']);
    // läuft ohne bekannte Leistung (nur Drehzahl): kleinster Punkt der Kennlinie, geschätzt
    $l = TankService::live([$o($t1)], [$gen => ['stufe' => 'ok', 'p' => null, 'zustand' => ['drehzahl' => 1500.0]]])[$t1];
    gleich([$l['tank']['verbrauch_l_h'], $l['tank']['verbrauch_geschaetzt']], [12.0, true], 'läuft ohne Leistung: kleinster Kennlinienpunkt, geschätzt');
    // ohne Kennlinie: Volllastverbrauch, geschätzt; ganz ohne Angabe: aus dem Pegel
    AnlagenService::eigenschaftSetzen($gen, ['verbrauch_kennlinie' => null], true);
    $l = TankService::live([$o($t1)], $gLive(40000.0))[$t1];
    gleich([$l['tank']['verbrauch_l_h'], $l['tank']['verbrauch_quelle'], $l['tank']['verbrauch_geschaetzt']], [22.0, 'last', true], 'ohne Kennlinie: 22 l/h Volllast, geschätzt');
    AnlagenService::eigenschaftSetzen($gen, ['verbrauch_l_h' => null], true);
    $l = TankService::live([$o($t1)], $gLive(40000.0))[$t1];
    pruefe(in_array($l['tank']['verbrauch_quelle'], ['pegel', null], true), 'ohne Verbrauchsangabe: aus dem Pegel – ' . var_export($l['tank']['verbrauch_quelle'], true));
    AnlagenService::eigenschaftSetzen($gen, ['verbrauch_kennlinie' => '100% 22L / 70% 16L / 50% 12L', 'verbrauch_l_h' => 22], true);
    // ohne Livewert des Generators (kein Messgerät): aus dem Pegel
    $l = LiveService::fuerObjekte([$o($t1)])[$t1];
    pruefe(in_array($l['tank']['verbrauch_quelle'], ['pegel', null], true) && $l['tank']['generatoren'][0]['laeuft'] === false,
        'Generator ohne Messgerät: Verbrauch aus dem Pegel, nicht aus der Last');
    // mit Messgerät (Demo-Aggregat): der zweite Durchgang von LiveService liest den Generator nach
    $mpGen = Database::insert('messpunkte', ['objekt_id' => $gen, 'geraet' => 'ig200-aggregat1', 'kanal' => 'generator', 'rolle' => 'erzeugung',
        'bezeichnung' => $kennungF . '-gen', 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
    $l = LiveService::fuerObjekte([$o($t1)])[$t1];
    $erwartet = TankRechnung::verbrauch(TankRechnung::kennlinieLesen('100% 22L / 70% 16L / 50% 12L'), 22.0,
        TankRechnung::last((float) (LiveService::fuerObjekte([$o($gen)])[$gen]['p'] ?? 0), 100.0))['l_h'];
    pruefe($l['tank']['verbrauch_quelle'] === 'last' && abs((float) $l['tank']['verbrauch_l_h'] - $erwartet) < 1.0 && $l['tank']['generatoren'][0]['laeuft'] === true,
        'Demo-Aggregat mit Messgerät: Verbrauch aus der Last (' . $l['tank']['verbrauch_l_h'] . ' l/h)');
    $l = LiveService::fuerObjekte([$o($t1), $o($gen)]);
    gleich(array_keys($l), [$gen, $t1], 'Übersicht mit Tank und Generator: beide, der Generator aus dem ersten Durchgang');
    pruefe(str_contains(json_encode(TankService::seitenDaten($o($t1))['versorgung']), $kennungF . ' Aggregat'), 'Tankseite: Versorgung nennt den Generator');

    // ------------------------------------------------ Generator unsichtbar: keine Leistung, kein Name
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $kundeF, 'ziel_art' => 'objekt', 'ziel_id' => $t1]);
    \App\Auth\Zugriff::vergessen();
    alsBenutzer($kundeF);
    pruefe(Zugriff::siehtObjekt($o($t1)) && !Zugriff::siehtObjekt($o($gen)), 'Kunde sieht den Tank, nicht den Generator');
    foreach (['LiveService' => LiveService::fuerObjekte([$o($t1)])[$t1], 'mit Livewert des Generators' => TankService::live([$o($t1)], $gLive(64000.0))[$t1]] as $wie => $l) {
        $text = json_encode($l, JSON_UNESCAPED_UNICODE);
        pruefe(array_key_exists('verbrauch_quelle', $l['tank']) && in_array($l['tank']['verbrauch_quelle'], ['pegel', null], true) && $l['tank']['generatoren'] === []
            && !str_contains($text, 'Aggregat') && !str_contains($text, '64000') && !str_contains($text, '"' . $gen . '"') && ($l['tank']['vorrat_tanks'] ?? 0) === 1,
            'Kunde (' . $wie . '): Verbrauch nur aus dem Pegel, ohne Generator, ohne Tank 2 im Vorrat');
    }
    $seite = TankService::seitenDaten($o($t1))['versorgung'];
    pruefe($seite['generatoren'] === [] && $seite['verborgen'] === 1 && count($seite['schlaeuche']) === 1 && $seite['schlaeuche'][0]['generator'] === null
        && !str_contains(json_encode($seite, JSON_UNESCAPED_UNICODE), 'Aggregat'), 'Kunde: Tankseite nennt den Generator am Schlauch nicht');
    alsBenutzer($adminF);

    // ------------------------------------------------ Verbrauch aus dem Pegel (Demo-Sonde, ohne Schlauch)
    $roh = Messdaten::tankGefaelle([['geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'feld' => 'u', 'ab' => $jetzt - 6 * 3600, 'fenster' => null]]);
    $punkte = $roh['shellyplusuni-tank1|voltmeter:100'] ?? [];
    $zeiten = array_column($punkte, 0);
    $sortiert = $zeiten;
    sort($sortiert);
    pruefe(count($punkte) >= 30 && $zeiten === $sortiert && $zeiten[1] - $zeiten[0] === 600_000, 'Rohwerte fürs Gefälle: Median je 10 min, aufsteigend (' . count($punkte) . ')');
    gleich(Messdaten::tankGefaelle([['geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'feld' => 'u', 'ab' => $jetzt - 6 * 3600,
        'fenster' => [[$jetzt - 86400, $jetzt - 3600]]]]), [], 'Gefälle nur im laufenden Teil des Datenfensters');
    $feinT = $neuF(['art' => 'tank', 'name' => $kennungF . ' fein', 'eigenschaften' => $eigF]);
    $mpFein = Database::insert('messpunkte', ['objekt_id' => $feinT, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
        'bezeichnung' => $kennungF . '-fein', 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
    // Meldeschritt laut Sensordaten 0,01 V (4,5 l): 3 Schritte Abfall in 6 h sind dann sicher erreicht
    $rFein = TankRechnung::rechnung(['schritt_roh' => 0.01] + $sensorF, ['offset_mm' => 20], $eigF, ['kennung' => 'fein', 'text' => 'Einbau'])['rechnung'];
    TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mpFein]), $rFein, 'einsatz', Zeit::iso($jetzt - 3 * 86400), true);
    $l = LiveService::fuerObjekte([$o($feinT)])[$feinT];
    $seitSechs = $jetzt - (new DateTimeImmutable('@' . $jetzt))->setTimezone(Zeit::zone())->setTime(6, 0)->getTimestamp();
    $seitSechs = $seitSechs < 0 ? $seitSechs + 86400 : $seitSechs;
    if ($seitSechs >= 7 * 3600) {
        pruefe($l['tank']['verbrauch_quelle'] === 'pegel' && $l['tank']['verbrauch_l_h'] > 12 && $l['tank']['verbrauch_l_h'] < 32 && $l['tank']['restlaufzeit_h'] > 0,
            'ohne Schlauch: Verbrauch aus dem Pegel ≈ 22 l/h (' . var_export($l['tank']['verbrauch_l_h'], true) . ') und Restlaufzeit');
    } else {
        pruefe(in_array($l['tank']['verbrauch_quelle'], ['pegel', null], true), 'ohne Schlauch, kurz nach der Demo-Betankung: Pegel oder noch nichts');
    }
    gleich($l['tank']['generatoren'], [], 'ohne Schlauch keine Generatoren');

    // ------------------------------------------------ Betankung: Verbrauch des Generators währenddessen abgezogen
    $sechs = (new DateTimeImmutable('@' . $jetzt))->setTimezone(Zeit::zone())->setTime(6, 0);
    if ($sechs->getTimestamp() > $jetzt - TankService::AUSWERTEN_NACH_S) {
        $sechs = $sechs->modify('-1 day');
    }
    LeitungService::loeschen($s2);
    $tb = $neuF(['art' => 'tank', 'name' => $kennungF . ' Betankung', 'eigenschaften' => array_diff_key($eigF, ['liter_je_mm' => 1])]);
    $mpB = Database::insert('messpunkte', ['objekt_id' => $tb, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
        'bezeichnung' => $kennungF . '-B', 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
    $rB = TankRechnung::rechnung($sensorF, ['offset_mm' => 20], array_diff_key($eigF, ['liter_je_mm' => 1]), ['kennung' => 'B', 'text' => 'Einbau'])['rechnung'];
    TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mpB]), $rB, 'einsatz', Zeit::iso($jetzt - 3 * 86400), false);
    LeitungService::loeschen($s1);
    $sB = (int) ($schlauch(['von_objekt_id' => $tb, 'zu_objekt_id' => $gen, 'kabeltyp' => 'DN 10 (3/8″)', 'punkte' => [[1, 1], [2, 2]]])['id'] ?? 0);
    $e = TankService::betankungSpeichern($o($tb), ['liter' => '528', 'zeit' => $sechs->format('Y-m-d\TH:i'), 'lieferschein' => $kennungF]);
    $ev = Database::first('SELECT * FROM tank_ereignisse WHERE id = ?', [(int) ($e['id'] ?? 0)]);
    $dh = (float) TankRechnung::hoehe($rB, (float) ($ev['roh_nach'] ?? 0)) - (float) TankRechnung::hoehe($rB, (float) ($ev['roh_vor'] ?? 0));
    $ljm = (float) ($o($tb)['eigenschaften']['liter_je_mm'] ?? 0);
    pruefe($e['ok'] && !empty($e['kalibriert']) && str_contains($e['message'], 'Abgezogen:') && $dh > 0 && $ljm < 528 / $dh - 0.005 && $ljm > (528 - 30) / $dh,
        'Betankung mit Generator am Schlauch: Verbrauch während des Tankens abgezogen (' . round($ljm, 3) . ' statt ' . round(528 / max($dh, 1), 3) . ' l/mm) – ' . $e['message']);
    Database::execute('DELETE FROM messpunkte WHERE id = ?', [$mpGen]);
    // Zwei Tanks am Generator: nicht abziehbar, das sagt die Meldung
    $s3 = (int) ($schlauch(['von_objekt_id' => $t1, 'zu_objekt_id' => $gen, 'kabeltyp' => 'DN 10 (3/8″)', 'punkte' => [[1, 1], [2, 2]]])['id'] ?? 0);
    Database::execute('UPDATE tank_ereignisse SET roh_vor = NULL, roh_nach = NULL WHERE id = ?', [(int) $ev['id']]);
    Database::execute('DELETE FROM tank_kennlinien WHERE ereignis_id = ?', [(int) $ev['id']]);
    $a = TankService::betankungAuswerten($o($tb), (int) $ev['id'], true);
    pruefe($a['ok'] && str_contains($a['message'], 'nicht abgezogen (mehrere Tanks an einem Generator)'), 'zwei Tanks am Generator: nicht abgezogen, mit Grund – ' . $a['message']);
    pruefe($s3 > 0 && $sB > 0, 'Prüfbestand: Schläuche angelegt');

    // ------------------------------------------------ Material und Aufbau
    $mat = MaterialService::fuerEtage($floorF);
    gleich(array_column($mat['schlaeuche'], 'straenge', 'titel'), ['DN 10 (3/8″)' => 4], 'Material: Schläuche je Typ (2 Schläuche × 2 Stränge)');
    gleich($mat['kupplungen'], ['HK-A3' => 8], 'Material: zwei Kupplungen je Strang');
    pruefe(in_array('Tank · ohne Typangabe', array_column($mat['anlagen'], 'titel'), true), 'Material: Tanks stehen als Anlagen da');

    // ------------------------------------------------ Übergabeberichte gelten der elektrischen Anlage
    $berichteVorher = (int) Database::scalar('SELECT COUNT(*) FROM berichte');
    $b = BerichtService::anlegen($siteF, null, [$gen, $t1]);
    pruefe(!$b['ok'] && str_contains($b['message'], 'ist ein Tank'), 'Übergabebericht: ein Tank lässt sich nicht als Anlage wählen – ' . $b['message']);
    gleich((int) Database::scalar('SELECT COUNT(*) FROM berichte'), $berichteVorher, '… und es entsteht kein Bericht');
} finally {
    alsBenutzer(null);
    Database::execute('DELETE FROM leitungen WHERE name LIKE ?', [$kennungF . '%']);
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennungF . '%']) as $obj) {
        AnlagenService::loeschen((int) $obj['id']);
    }
    SiteService::deleteSite($siteF);
    foreach ([$adminF, $kundeF] as $u) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$u]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$u]);
        Database::execute('DELETE FROM users WHERE id = ?', [$u]);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennungF . '%', '%' . $kennungF . '%']);
}

// ------------------------------------------------ G – Verbrauch je Tag (rein, Prüfwerte §7.5)
$zoneG = new DateTimeZone('Europe/Berlin');
$um = static fn (string $lokal): int => (new DateTimeImmutable($lokal, $zoneG))->getTimestamp();
// Eine Reihe je 10 min von … bis (Ortszeit); $liter(t in s) – null ist eine Lücke.
$reiheG = static function (string $von, string $bis, callable $liter) use ($um): array {
    $out = [];
    for ($t = $um($von); $t <= $um($bis); $t += 600) {
        $out[] = [$t * 1000, $liter($t)];
    }
    return $out;
};
$schwelleG = TankRechnung::betankungSchwelle(3000.0, 42.4);
gleich(array_map(static fn (float $x): float => round($x, 6), [$schwelleG, TankRechnung::betankungSchwelle(null, null), TankRechnung::betankungSchwelle(30000.0, 10.0)]),
    [127.2, 50.0, 900.0],
    'Schwelle einer Betankung: max(50 l, 3 % des Nennvolumens, 3 Meldeschritte)');
$bilanz = static fn (array $reihe, array $erfasst = [], ?int $von = null, ?int $bis = null): array
    => TankRechnung::tagesbilanz($reihe, $erfasst, $schwelleG, $zoneG, 42.4, $von, $bis);

// −20 l/h über den Tag, um 10:00 in 20 min +1.500 l
$t0G = $um('2026-10-19 22:00');
$tanken = static fn (int $t, string $ab, float $menge = 1500.0, int $dauer = 1200): float
    => $t <= $um($ab) ? 0.0 : ($t >= $um($ab) + $dauer ? $menge : $menge * ($t - $um($ab)) / $dauer);
$reihe = $reiheG('2026-10-19 22:00', '2026-10-21 02:00', static fn (int $t): float => 2000 - 20 * ($t - $t0G) / 3600 + $tanken($t, '2026-10-20 10:00'));
$b = $bilanz($reihe);
$tag = $b['tage']['2026-10-20'] ?? [];
nahe($tag['verbrauch_l'] ?? null, 480.0, 42.4, '−20 l/h mit Betankung: Verbrauch 480 l ± 1 Meldeschritt');
nahe($tag['betankt_l'] ?? null, 1500.0, 42.4, '… getankt 1.500 l ± 1 Meldeschritt');
gleich([$tag['stunden'] ?? null, $tag['hinweis'] ?? null], [24.0, ''], '… über 24 h, ohne Hinweis');
nahe($tag['l_h'] ?? null, 20.0, 1.8, '… Ø 20 l/h');
gleich(array_map(static fn (array $x): array => [$x['quelle'], $x['zeit'], $x['von']], $b['betankungen']), [['erkannt', $um('2026-10-20 10:20'), $um('2026-10-20 10:00')]],
    'eine erkannte Betankung, 10:00 bis 10:20');
$b = $bilanz($reihe, [['zeit' => $um('2026-10-20 10:30'), 'liter' => 1500.0, 'id' => 7, 'lieferschein' => 'LS-7']]);
$tag = $b['tage']['2026-10-20'];
gleich($tag['betankt_l'], 1500.0, 'Lieferschein 1.500 l um 10:30: getankt genau 1.500 l');
nahe($tag['verbrauch_l'], 480.0, 0.1, '… Verbrauch 480 l');
gleich(array_map(static fn (array $x): array => [$x['quelle'], $x['liter'], $x['ereignisse'], $x['lieferschein']], $b['betankungen']),
    [['lieferschein', 1500.0, [7], 'LS-7']], '… die Betankung zählt mit der Menge laut Lieferschein');
nahe($b['betankungen'][0]['erkannt_l'], 1493.3, 0.1, '… und nennt den Sprung (1.500 l minus Verbrauch in 20 min)');
$b = $bilanz($reihe, [['zeit' => $um('2026-10-20 08:00'), 'liter' => 1500.0, 'id' => 8]]);
gleich(array_column($b['betankungen'], 'quelle'), ['ohne_sprung', 'erkannt'], 'Lieferschein 2 h vor dem Sprung: gehört nicht dazu');
pruefe($b['tage']['2026-10-20']['betankt_l'] < 1500 && str_contains($b['tage']['2026-10-20']['hinweis'], 'Betankung 08:00 (1.500 l) ohne Sprung in den Messwerten'),
    '… zählt nicht, Hinweis am Tag: ' . $b['tage']['2026-10-20']['hinweis']);
$b = $bilanz($reihe, [['zeit' => $um('2026-10-20 10:15'), 'liter' => 1000.0, 'id' => 1], ['zeit' => $um('2026-10-20 10:25'), 'liter' => 500.0, 'id' => 2]]);
gleich([$b['tage']['2026-10-20']['betankt_l'], $b['betankungen'][0]['ereignisse'] ?? null, count($b['betankungen'])], [1500.0, [1, 2], 1],
    'zwei Lieferscheine zu einem Sprung: zusammen');

// Nur Rauschen ±1 Meldeschritt
$i = 0;
$b = $bilanz($reiheG('2026-10-19 22:00', '2026-10-21 02:00', static function () use (&$i): float {
    return 1500 + ($i++ % 3 - 1) * 42.4;
}));
pruefe($b['betankungen'] === [] && ($b['tage']['2026-10-20']['verbrauch_l'] ?? 99) <= 42.4, 'nur Rauschen ±1 Schritt: keine Betankung, Verbrauch ≤ 42,4 l ('
    . var_export($b['tage']['2026-10-20']['verbrauch_l'] ?? null, true) . ')');

// 25.10.2026: Zeitumstellung, der Tag hat 25 h
$t1G = $um('2026-10-24 20:00');
$b = $bilanz($reiheG('2026-10-24 20:00', '2026-10-26 04:00', static fn (int $t): float => 2500 - 20 * ($t - $t1G) / 3600));
nahe($b['tage']['2026-10-25']['verbrauch_l'] ?? null, 500.0, 1.0, '25.10.2026 (25 h) bei −20 l/h: 500 l');
gleich($b['tage']['2026-10-25']['stunden'] ?? null, 25.0, '… über 25 Stunden');
gleich(array_keys($b['tage']), ['2026-10-24', '2026-10-25', '2026-10-26'], 'Tage in Ortszeit');

// Ein Shelly meldet nur bei Änderung: alle 42,4 l (17 l/h → etwa alle 2,5 h) – Stille ist keine Lücke
$t2G = $um('2026-10-19 00:00');
$selten = [];
for ($k = 0; $k * 42.4 / 17 * 3600 < 3 * 86400; $k++) {
    $t = $t2G + (int) round($k * 42.4 / 17 * 3600);
    $selten[] = [intdiv($t, 600) * 600 * 1000, 2000 - 42.4 * $k];
}
$b = $bilanz($selten);
nahe($b['tage']['2026-10-20']['verbrauch_l'] ?? null, 408.0, 42.4, 'Meldungen nur bei Änderung (alle ≈ 2,5 h): 17 l/h · 24 h = 408 l');
gleich([$b['tage']['2026-10-20']['stunden'] ?? null, $b['tage']['2026-10-20']['hinweis'] ?? null, $b['betankungen']], [24.0, '', []], '… ganzer Tag, ohne Lücke');

// Lücke (keine Freigabe) 12:00–18:00 – wie Messdaten::reihe sie liefert: ein null am Ende des
// freigegebenen Teils –, darin eine eingetragene Betankung: zählt zum Getankten, in keine Bilanz
$mitLuecke = array_values(array_filter($reiheG('2026-10-19 22:00', '2026-10-21 02:00', static fn (int $t): float
    => 2000 - 20 * ($t - $t0G) / 3600 + ($t >= $um('2026-10-20 18:00') ? 1000 : 0)),
    static fn (array $x): bool => $x[0] <= $um('2026-10-20 12:00') * 1000 || $x[0] >= $um('2026-10-20 18:00') * 1000));
$mitLuecke[] = [$um('2026-10-20 12:00') * 1000 + 1, null];
$b = $bilanz($mitLuecke, [['zeit' => $um('2026-10-20 14:00'), 'liter' => 1000.0, 'id' => 9]]);
$tag = $b['tage']['2026-10-20'];
nahe($tag['verbrauch_l'], 20.0 * 18, 0.1, 'Lücke 12–18 Uhr: Verbrauch nur aus den 18 h mit Messwerten');
gleich([$tag['betankt_l'], $tag['stunden'], array_column($b['betankungen'], 'quelle')], [1000.0, 18.0, ['protokoll']], '… die Betankung in der Lücke zählt zum Getankten');
pruefe(str_contains($tag['hinweis'], 'Messwerte für 18 von 24 h'), '… Hinweis: ' . $tag['hinweis']);

// Zapfpause: +800 l, 10 min Pause, +700 l – eine Betankung
$b = $bilanz($reiheG('2026-10-19 22:00', '2026-10-21 02:00', static fn (int $t): float => 2000 - 20 * ($t - $t0G) / 3600
    + $tanken($t, '2026-10-20 10:00', 800.0, 600) + $tanken($t, '2026-10-20 10:20', 700.0, 600)));
gleich(count($b['betankungen']), 1, 'Zapfpause von 10 min: eine Betankung');
nahe($b['betankungen'][0]['liter'] ?? null, 1500.0, 42.4, '… mit beiden Teilen');

// Über Mitternacht: 23:50 bis 00:10 – jeder Tag bekommt seinen Teil
$b = $bilanz($reiheG('2026-10-19 22:00', '2026-10-22 02:00', static fn (int $t): float => 2000 - 20 * ($t - $t0G) / 3600 + $tanken($t, '2026-10-20 23:50')));
nahe(($b['tage']['2026-10-20']['betankt_l'] ?? 0) + ($b['tage']['2026-10-21']['betankt_l'] ?? 0), 1500.0, 42.4, 'Betankung über Mitternacht: zusammen 1.500 l');
pruefe(($b['tage']['2026-10-20']['betankt_l'] ?? 0) > 500 && ($b['tage']['2026-10-21']['betankt_l'] ?? 0) > 500, '… auf beide Tage verteilt');
nahe($b['tage']['2026-10-21']['verbrauch_l'] ?? null, 480.0, 42.4, '… Verbrauch am zweiten Tag');

// Betankung nach langer Ruhe: letzte Meldung gestern 20:00 bei 1.000 l, getankt heute 10:00–10:20 auf 3.000 l – alles zählt zu heute
$ruhe = [[$um('2026-10-19 20:00') * 1000, 1000.0], [$um('2026-10-20 10:10') * 1000, 2000.0], [$um('2026-10-20 10:20') * 1000, 3000.0],
    [$um('2026-10-20 18:00') * 1000, 2840.0], [$um('2026-10-21 02:00') * 1000, 2680.0]];
$b = $bilanz($ruhe);
gleich([$b['tage']['2026-10-19']['betankt_l'] ?? null, $b['tage']['2026-10-19']['verbrauch_l'] ?? null], [0.0, 0.0], 'Betankung nach langer Ruhe: am Vortag nichts getankt, nichts verbraucht');
nahe($b['tage']['2026-10-20']['betankt_l'] ?? null, 2000.0, 0.1, '… getankt 2.000 l am Tag der Betankung');
pruefe(count($b['betankungen']) === 1 && $b['betankungen'][0]['von'] >= $um('2026-10-20 09:30'), '… sie beginnt erst 30 min vor der ersten Meldung');
$b = $bilanz($ruhe, [['zeit' => $um('2026-10-20 08:00'), 'liter' => 2000.0, 'id' => 5]]);
gleich([array_column($b['betankungen'], 'quelle'), $b['tage']['2026-10-20']['betankt_l'] ?? null], [['lieferschein'], 2000.0],
    '… ein Lieferschein mitten in der Ruhe (08:00) gehört trotzdem zu diesem Sprung');

// Wärme: Inhalt steigt um 100 l über den Tag, ohne Sprung – Verbrauch 0 mit Hinweis
$b = $bilanz($reiheG('2026-10-19 22:00', '2026-10-21 02:00', static fn (int $t): float => 1000 + 100 * ($t - $t0G) / (28 * 3600)));
gleich([$b['tage']['2026-10-20']['verbrauch_l'], $b['betankungen']], [0.0, []], 'Wärme ohne Sprung: Verbrauch 0, keine Betankung');
pruefe(str_contains($b['tage']['2026-10-20']['hinweis'], 'gestiegen'), '… mit Hinweis: ' . $b['tage']['2026-10-20']['hinweis']);

// Zeitraum: nur von … bis zählt, die Punkte davor geben den Wert an der Grenze
$b = $bilanz($reihe, [], $um('2026-10-20 00:00'), $um('2026-10-20 09:00'));
gleich(array_keys($b['tage']), ['2026-10-20'], 'Zeitraum: nur die Tage darin');
nahe($b['tage']['2026-10-20']['verbrauch_l'], 180.0, 0.1, '… 9 h zu 20 l/h');
gleich([$b['tage']['2026-10-20']['stunden'], $b['tage']['2026-10-20']['hinweis'], $b['betankungen']], [9.0, '', []], '… keine Betankung außerhalb');
gleich(TankRechnung::tagesbilanz([], [], 50.0, $zoneG), ['tage' => [], 'betankungen' => []], 'ohne Werte: nichts');
gleich(array_keys($bilanz([[1, null], [2, 'x'], [3, INF]])['tage']), [], 'nur Lücken: keine Tage');

// ------------------------------------------------ G – Verbrauch je Tag über TankService (Demo-Modus)
Config::set('app.demo', true);
$kennungG = 'pm-tank-' . bin2hex(random_bytes(3));
$siteG = SiteService::saveSite(null, ['name' => 'Prüfung Tank je Tag ' . $kennungG]);
$floorG = SiteService::saveFloor(null, ['site_id' => $siteG, 'name' => 'Wiese']);
$adminG = BenutzerService::anlegen($kennungG, 'Pruefung2026!!', 'admin', false);
$kundeG = BenutzerService::anlegen($kennungG . '-kunde', 'Pruefung2026!!', 'kunde', false);

try {
    alsBenutzer($adminG);
    $jetzt = time();
    $eigG = ['inhalt_l' => '3000', 'kraftstoff' => 'HVO', 'bauform' => 'Quader', 'sumpf_l' => '140', 'liter_je_mm' => '2,78'];
    $sensorG = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
        'bereich_einheit' => 'mbar', 'schritt_roh' => 0.1];
    $neuG = static fn (string $name, array $eig) => (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungG . ' ' . $name, 'floor_id' => $floorG,
        'eigenschaften' => $eig])['id'];
    // Demo-Sonde: 2.700 l um 06:00, 22 l/h – eingebaut vor 3 Tagen, kalibriert mit 2,78 l/mm
    $einbauen = static function (int $tank, array $eig) use ($kennungG, $sensorG, $jetzt): int {
        $mp = Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
            'bezeichnung' => $kennungG . '-TS' . $tank, 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
        $r = TankRechnung::rechnung($sensorG, ['offset_mm' => 20], $eig, ['kennung' => 'G', 'text' => 'Einbau'])['rechnung'];
        TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]), $r, 'einsatz', Zeit::iso($jetzt - 3 * 86400), true);
        return $mp;
    };
    $tankG = $neuG('Tank', $eigG);
    $einbauen($tankG, $eigG);
    $oG = static fn (int $id): array => AnlagenService::find($id);
    $lokal = static fn (int $ts): DateTimeImmutable => (new DateTimeImmutable('@' . $ts))->setTimezone(Zeit::zone());
    $gestern = $lokal($jetzt)->modify('-1 day')->format('Y-m-d');
    $sechsGestern = (new DateTimeImmutable($gestern . ' 06:00', Zeit::zone()))->getTimestamp();

    $d = TankService::tage($oG($tankG), $jetzt - 2 * 86400, $jetzt);
    $tage = array_column($d['tage'], null, 'tag');
    gleich(array_keys($tage), [$lokal($jetzt - 2 * 86400)->format('Y-m-d'), $gestern, $lokal($jetzt)->format('Y-m-d')], 'Tage: ganze Tage ab Mitternacht vor zwei Tagen bis heute');
    gleich($d['von'], (new DateTimeImmutable($lokal($jetzt - 2 * 86400)->format('Y-m-d'), Zeit::zone()))->getTimestamp(), '… ab Mitternacht (Ortszeit)');
    $g = $tage[$gestern] ?? [];
    $stunden = (new DateTimeImmutable($gestern, Zeit::zone()))->modify('+1 day')->getTimestamp() - (new DateTimeImmutable($gestern, Zeit::zone()))->getTimestamp();
    nahe($g['verbrauch_l'] ?? null, 22.0 * $stunden / 3600, 100.0, 'Demo-Sonde gestern: Verbrauch ≈ 22 l/h über den Tag (' . var_export($g['verbrauch_l'] ?? null, true) . ')');
    nahe($g['betankt_l'] ?? null, 528.0, 50.0, '… getankt ≈ 528 l um 06:00 (' . var_export($g['betankt_l'] ?? null, true) . ')');
    gleich([$g['stunden'] ?? null, $d['kalibriert'], $d['schwelle_l']], [round($stunden / 3600, 2), true, round(3 * 0.1 * 163.4135 * 2.78, 1)],
        '… mit Messwerten über den ganzen Tag, kalibriert, Schwelle 3 Meldeschritte');
    pruefe(str_contains((string) ($g['text'] ?? ''), $lokal($sechsGestern)->format('d.m.')) && ($g['verbrauch_text'] ?? '') !== '' && isset($g['l_h_text']), 'Tag mit Wochentag und Texten: ' . ($g['text'] ?? ''));
    $erkannt = array_values(array_filter($d['betankungen'], static fn (array $x): bool => abs($x['zeit'] - $sechsGestern) <= 1200));
    pruefe(count($erkannt) === 1 && $erkannt[0]['quelle'] === 'erkannt' && $erkannt[0]['eintragen'] === true && $erkannt[0]['eintragen_zeit'] === Zeit::fuerEingabe(Zeit::iso($erkannt[0]['zeit'])),
        'Betankung gestern 06:00 erkannt, ohne Eintrag – eintragbar');
    // Der Sprung von heute 06:00 ist erst ab dem nächsten 10-min-Punkt sicher zu sehen.
    $sechsUhr = [$sechsGestern - 86400, $sechsGestern, $sechsGestern + 86400];
    $anzahlErkannt = count(array_filter($d['betankungen'], static fn (array $x): bool => $x['quelle'] === 'erkannt'));
    pruefe($anzahlErkannt >= count(array_filter($sechsUhr, static fn (int $t): bool => $t >= $d['von'] && $t < $jetzt - 600))
        && $anzahlErkannt <= count(array_filter($sechsUhr, static fn (int $t): bool => $t >= $d['von'] && $t <= $jetzt)), 'je Tag eine erkannte Betankung um 06:00 (' . $anzahlErkannt . ')');
    pruefe($d['darf_eintragen'] && str_contains($d['summe']['text'], 'verbraucht') && $d['hinweis'] === '', 'Summe, darf eintragen, kein Hinweis');

    // Eintragen mit Lieferschein: ersetzt die erkannte Menge
    $e = TankService::betankungSpeichern($oG($tankG), ['liter' => '528', 'zeit' => $erkannt[0]['eintragen_zeit'] ?? '', 'lieferschein' => $kennungG, 'kalibrieren' => false]);
    pruefe($e['ok'] && empty($e['kalibriert']), 'erkannte Betankung eingetragen (ohne zu kalibrieren): ' . $e['message']);
    $d = TankService::tage($oG($tankG), $jetzt - 2 * 86400, $jetzt);
    $g = array_column($d['tage'], null, 'tag')[$gestern] ?? [];
    $ls = array_values(array_filter($d['betankungen'], static fn (array $x): bool => $x['quelle'] === 'lieferschein'));
    gleich([$g['betankt_l'] ?? null, count($ls), $ls[0]['ereignisse'] ?? null, $ls[0]['lieferschein'] ?? null, $ls[0]['eintragen'] ?? null],
        [528.0, 1, [(int) ($e['id'] ?? 0)], $kennungG, false], 'mit Eintrag: getankt laut Lieferschein, nicht mehr eintragbar');

    // Neu kalibriert mitten im Zeitraum (Anker −50 mm ≈ −139 l): der Sprung ist weder Verbrauch noch Betankung
    $vorKal = TankService::tage($oG($tankG), $jetzt - 2 * 86400, $jetzt);
    $mG = TankService::sensor($tankG);
    $rG = TankService::fassungJetzt((int) $mG['id'])['rechnung'];
    $rG['sensor']['mm_anker'] -= 50;
    $abKal = $jetzt - 6 * 3600;
    $stunde = (int) $lokal($abKal)->format('G') * 60 + (int) $lokal($abKal)->format('i');
    if ($stunde >= 5 * 60 + 20 && $stunde <= 6 * 60 + 50) {
        $abKal -= 3 * 3600;    // nicht mitten in die Betankung der Demo-Sonde um 06:00
    }
    $idKal = TankService::fassungAnlegen($mG, $rG, 'hand', Zeit::iso($abKal), false);
    $nachKal = TankService::tage($oG($tankG), $jetzt - 2 * 86400, $jetzt);
    nahe($nachKal['summe']['verbrauch_l'], $vorKal['summe']['verbrauch_l'], 2.0, 'neue Kennlinie (−139 l) mitten im Zeitraum: derselbe Verbrauch ('
        . $vorKal['summe']['verbrauch_l'] . ' → ' . $nachKal['summe']['verbrauch_l'] . ' l)');
    gleich([array_column($nachKal['betankungen'], 'quelle'), $nachKal['summe']['betankt_l']], [array_column($vorKal['betankungen'], 'quelle'), $vorKal['summe']['betankt_l']],
        '… und keine Betankung daraus');
    Database::execute('DELETE FROM tank_kennlinien WHERE id = ?', [$idKal]);

    // Ohne Kalibrierung keine Liter; ohne Sensor nur das Protokoll
    $ohneLjm = array_diff_key($eigG, ['liter_je_mm' => 1]);
    $tankU = $neuG('unkalibriert', $ohneLjm);
    $einbauen($tankU, $ohneLjm);
    $d = TankService::tage($oG($tankU), $jetzt - 86400, $jetzt);
    pruefe($d['tage'] === [] && $d['kalibriert'] === false && str_contains($d['hinweis'], 'Inhalt je mm'), 'ohne Inhalt je mm: keine Tage, Hinweis „peilen“ – ' . $d['hinweis']);
    $tankO = $neuG('ohne Sensor', ['inhalt_l' => '1000']);
    TankService::betankungSpeichern($oG($tankO), ['liter' => '300', 'zeit' => Zeit::fuerEingabe(Zeit::iso($jetzt - 3 * 3600)), 'lieferschein' => 'O-1']);
    $d = TankService::tage($oG($tankO), $jetzt - 86400, $jetzt);
    $heute = array_column($d['tage'], null, 'tag')[$lokal($jetzt - 3 * 3600)->format('Y-m-d')] ?? [];
    gleich([array_key_exists('verbrauch_l', $heute) ? $heute['verbrauch_l'] : 'x', $heute['betankt_l'] ?? null, array_column($d['betankungen'], 'quelle')], [null, 300.0, ['protokoll']],
        'ohne Sensor: die Betankung zählt zum Getankten, Verbrauch unbekannt');

    // Kunde mit Datenfenster: nur Tage und Betankungen darin, nichts einzutragen
    Database::insert('freigaben', ['subject_type' => 'user', 'subject_id' => $kundeG, 'ziel_art' => 'site', 'ziel_id' => $siteG,
        'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'gueltig_ab' => null, 'gueltig_bis' => null,
        'daten_ab' => Zeit::iso($jetzt - 86400), 'daten_bis' => Zeit::iso($jetzt - 3600), 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c')]);
    alsBenutzer($kundeG);
    $d = TankService::tage($oG($tankG), $jetzt - 3 * 86400, $jetzt);
    $ab = $lokal($jetzt - 86400);
    $ersterTag = $ab->format('Y-m-d');
    // Beginnt das Fenster kurz vor Mitternacht, liegt der erste 10-min-Punkt schon am nächsten Tag.
    pruefe($d['tage'] !== [] && in_array(min(array_column($d['tage'], 'tag')), [$ersterTag, $lokal($jetzt - 86400 + 600)->format('Y-m-d')], true),
        'Kunde: Tage erst ab dem Datenfenster (' . implode(', ', array_column($d['tage'], 'tag')) . ')');
    pruefe(array_filter($d['betankungen'], static fn (array $x): bool => $x['zeit'] < $jetzt - 86400 || $x['zeit'] > $jetzt - 3600) === []
        && array_filter($d['betankungen'], static fn (array $x): bool => $x['eintragen']) === [] && $d['darf_eintragen'] === false,
        'Kunde: Betankungen nur im Datenfenster, nichts einzutragen');
    if ((int) $ab->format('G') >= 2 && (int) $ab->format('G') <= 22) {
        pruefe(str_contains((string) (array_column($d['tage'], null, 'tag')[$ersterTag]['hinweis'] ?? ''), 'Messwerte für'), 'Kunde: der erste Tag nur zum Teil');
    }
    alsBenutzer($adminG);
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennungG . '%']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    SiteService::deleteSite($siteG);
    foreach ([$adminG, $kundeG] as $u) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$u]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$u]);
        Database::execute('DELETE FROM users WHERE id = ?', [$u]);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennungG . '%', '%' . $kennungG . '%']);
}

// ------------------------------------------------ H – Kommandozeile und Demo-Gelände
$kennungH = 'pm-tank-' . bin2hex(random_bytes(3));
$wurzel = dirname(__DIR__);
$ordnerH = sys_get_temp_dir() . '/' . $kennungH;
mkdir($ordnerH, 0700, true);
register_shutdown_function(static function () use ($ordnerH): void {
    // Nur den eigenen Ordner.
    foreach (glob($ordnerH . '/*') ?: [] as $datei) {
        @unlink($datei);
    }
    @rmdir($ordnerH);
});
// Die Konfiguration dieser Prüfung (dieselbe Datenbank) im Demo-Modus – die Werte kommen von der Demo-Sonde.
$basisH = getenv('POWERMANAGEMENT_CONFIG');
$basisH = is_string($basisH) && $basisH !== '' && is_file($basisH) ? $basisH
    : (is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php');
$hierH = $ordnerH . '/hier.php';
file_put_contents($hierH, '<?php
$c = require ' . var_export($basisH, true) . ';
$c["app"]["demo"] = true;
return $c;
');
/** bin/power aufrufen – mit eigener Konfiguration oder der dieser Prüfung. @return array{0:int,1:string,2:string} Rückgabewert, Ausgabe, Fehlerausgabe */
$power = static function (array $argumente, ?string $config = null) use ($wurzel, $hierH): array {
    $p = proc_open(array_merge(['php', $wurzel . '/bin/power'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, $wurzel,
        ['POWERMANAGEMENT_CONFIG' => $config ?? $hierH] + getenv());
    $aus = (string) stream_get_contents($rohre[1]);
    $err = (string) stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    return [proc_close($p), $aus, $err];
};
$zeileMit = static function (string $ausgabe, string $text): string {
    foreach (explode("\n", $ausgabe) as $z) {
        if (str_contains($z, $text)) {
            return $z;
        }
    }
    return '';
};
Config::set('app.demo', true);
$siteH = SiteService::saveSite(null, ['name' => 'Prüfung Kommandozeile ' . $kennungH]);
$floorH = SiteService::saveFloor(null, ['site_id' => $siteH, 'name' => 'Wiese']);
$adminH = BenutzerService::anlegen($kennungH, 'Pruefung2026!!', 'admin', false);
$kundeH = BenutzerService::anlegen($kennungH . '-kunde', 'Pruefung2026!!', 'kunde', false);
$spaetH = BenutzerService::anlegen($kennungH . '-spaet', 'Pruefung2026!!', 'kunde', false);
$ausH = BenutzerService::anlegen($kennungH . '-aus', 'Pruefung2026!!', 'admin', false);
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$ausH]);

try {
    alsBenutzer($adminH);
    $jetzt = time();
    $eigH = ['inhalt_l' => '3000', 'kraftstoff' => 'Diesel', 'bauform' => 'Quader', 'sumpf_l' => '140', 'liter_je_mm' => '2,78'];
    $sensorH = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
        'bereich_einheit' => 'mbar', 'schritt_roh' => 0.1];
    $tankH = static function (string $name, array $eig, bool $mitSensor) use ($floorH, $kennungH, $sensorH, $jetzt): array {
        $id = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennungH . ' ' . $name, 'floor_id' => $floorH,
            'plan_x' => 30.0, 'plan_y' => 30.0, 'eigenschaften' => $eig])['id'];
        $mp = null;
        if ($mitSensor) {
            // an der Demo-Sonde, eingebaut vor drei Tagen
            $mp = Database::insert('messpunkte', ['objekt_id' => $id, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
                'bezeichnung' => $kennungH . '-' . $name, 'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'created_at' => gmdate('c')]);
            $r = TankRechnung::rechnung($sensorH, ['offset_mm' => 20], $eig, ['kennung' => $kennungH . '-' . $name, 'text' => 'Einbau'])['rechnung'];
            TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]), $r, 'einsatz', Zeit::iso($jetzt - 3 * 86400), false);
        }
        return [$id, $mp];
    };
    [$h1, $mpH1] = $tankH('Tank A', $eigH, true);
    [$h2] = $tankH('Tank B', $eigH + ['alarm_prozent' => '99'], true);
    $tankH('Tank C', ['inhalt_l' => '1000'], false);

    // ------------------------------------------------ tank:liste nur mit Konto
    [$rc, $out, $err] = $power(['tank:liste']);
    pruefe($rc === 2 && $out === '' && str_contains($err, '--als=<benutzer>'), 'tank:liste ohne --als: Abbruch mit Rückgabewert 2 und Meldung');
    [$rc, , $err] = $power(['tank:liste', '--als=' . $kennungH . '-aus']);
    pruefe($rc === 2 && str_contains($err, 'gesperrt'), 'tank:liste mit gesperrtem Konto: Abbruch – ' . trim($err));
    [$rc, , $err] = $power(['tank:liste', '--als=' . $kennungH . '-gibtsnicht']);
    pruefe($rc === 2 && str_contains($err, 'gibt es nicht'), 'tank:liste mit unbekanntem Konto: Abbruch');
    [$rc, , $err] = $power(['tank:liste', '--als=' . $kennungH, '--standort=abc']);
    pruefe($rc === 2 && str_contains($err, '--standort'), 'tank:liste mit unlesbarem Standort: Abbruch');

    [$rc, $out] = $power(['tank:liste', '--als=' . $kennungH, '--standort=' . $siteH]);
    $a = $zeileMit($out, $kennungH . ' Tank A');
    $b = $zeileMit($out, $kennungH . ' Tank B');
    $c = $zeileMit($out, $kennungH . ' Tank C');
    pruefe($rc === 0 && substr_count(trim($out), "\n") === 2, 'tank:liste: drei Tanks am Standort, Rückgabewert 0');
    pruefe(str_starts_with($a, 'ok ') && preg_match('/ \d[\d.]* l  \d+ %/u', $a) === 1 && str_contains($a, '(Prüfung Kommandozeile ' . $kennungH . ')')
        && preg_match('/\(Stand (vor |gerade eben)/u', $a) === 1, 'Zeile mit Liter, Prozent, Standort und Alter: ' . $a);
    pruefe(str_starts_with($b, 'bad ') && str_contains($b, '– nur noch'), 'Alarm des Tanks (unter 99 %) mit Grund: ' . $b);
    pruefe(str_starts_with($c, 'keine ') && str_contains($c, 'kein Tanksensor'), 'Tank ohne Sensor: ' . $c);
    [$rc, $out] = $power(['tank:liste', '--als=' . $kennungH, '--standort=' . $siteH, '--warnungen']);
    pruefe($rc === 1 && $zeileMit($out, 'Tank B') !== '' && $zeileMit($out, 'Tank A') === '' && $zeileMit($out, 'Tank C') === '',
        '--warnungen: nur der rote Tank, Rückgabewert 1');
    AnlagenService::eigenschaftSetzen($h2, ['alarm_prozent' => null], true);
    [$rc, $out] = $power(['tank:liste', '--als=' . $kennungH, '--standort=' . $siteH, '--warnungen']);
    pruefe($rc === 0 && trim($out) === '', '--warnungen ohne Warnung: keine Zeile, Rückgabewert 0');

    // Kunde: nur, was freigegeben ist – und nur im Datenfenster
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $kundeH, 'ziel_art' => 'objekt', 'ziel_id' => $h1]);
    Database::insert('freigaben', ['subject_type' => 'user', 'subject_id' => $spaetH, 'ziel_art' => 'site', 'ziel_id' => $siteH,
        'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'gueltig_ab' => null, 'gueltig_bis' => null,
        'daten_ab' => Zeit::iso($jetzt - 86400), 'daten_bis' => Zeit::iso($jetzt - 3600), 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c')]);
    [$rc, $out] = $power(['tank:liste', '--als=' . $kennungH . '-kunde']);
    pruefe($rc === 0 && $zeileMit($out, 'Tank A') !== '' && $zeileMit($out, 'Tank B') === '' && $zeileMit($out, 'Tank C') === ''
        && substr_count(trim($out), "\n") === 0, 'Kunde: nur der freigegebene Tank – ' . trim($out));
    [$rc, $out] = $power(['tank:liste', '--als=' . $kennungH . '-kunde', '--standort=' . $siteH]);
    pruefe($rc === 0 && $zeileMit($out, 'Tank A') !== '' && substr_count(trim($out), "\n") === 0, 'Kunde mit --standort: auch dort nur der freigegebene Tank');
    [$rc, $out] = $power(['tank:liste', '--als=' . $kennungH . '-spaet']);
    $a = $zeileMit($out, 'Tank A');
    pruefe($rc === 0 && str_starts_with($a, 'gesperrt ') && preg_match('/ \d[\d.]* l /u', $a) !== 1 && !str_contains($a, '%'),
        'Datenfenster vorbei: „gesperrt“, keine Liter – ' . $a);

    // ------------------------------------------------ tank:rechnen (Prüfwerte B: Diesel, 500 Ω, 2,78 l/mm, Sumpf 140 l)
    $f = TankService::fassungJetzt((int) $mpH1);
    [$rc, $out] = $power(['tank:rechnen', (string) $mpH1, '7,5']);
    gleich([$rc, explode("\n", trim($out))], [0, ['7,5 V → 859,6 mm → 2.389,6 l → 79,7 % (Fassung ' . $f['id'] . ', einsatz, ab ' . Zeit::anzeige($f['ab'], 'd.m. H:i') . ', ungeprüft)',
        'nutzbar 2.249,6 l']], 'tank:rechnen: Rohwert → Füllhöhe → Liter → % mit der jetzigen Fassung');
    [$rc, $out] = $power(['tank:rechnen', (string) $mpH1, '1.7']);
    pruefe($rc === 0 && str_contains($out, 'Leitungsbruch') && !str_contains($out, ' l →') && !str_contains($out, 'nutzbar'), 'tank:rechnen 1,7 V: Sensorfehler, keine Liter');
    [$rc, , $err] = $power(['tank:rechnen', (string) $mpH1, 'NAN']);
    pruefe($rc === 1 && str_contains($err, 'keine Zahl'), 'tank:rechnen mit NAN: abgelehnt');
    $vtH = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennungH . ' Verteilung', 'floor_id' => $floorH])['id'];
    $mpFremd = (int) Database::insert('messpunkte', ['objekt_id' => $vtH, 'geraet' => $kennungH . '-em', 'kanal' => 'em:0', 'rolle' => 'einspeisung',
        'bezeichnung' => $kennungH . '-em', 'created_at' => gmdate('c')]);
    pruefe($mpFremd > 0, 'Prüfbestand: ein Messpunkt „Einspeisung“ an einer eigenen Verteilung');
    [$rc, , $err] = $power(['tank:rechnen', (string) $mpFremd, '7']);
    pruefe($rc === 1 && str_contains($err, 'kein Messpunkt eines Tanksensors'), 'tank:rechnen an einem anderen Messpunkt: abgelehnt');

    // ------------------------------------------------ Demo-Gelände in eigener Instanz
    $config = $ordnerH . '/config.php';
    $datenbank = $ordnerH . '/datenbank.sqlite';
    $vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
    file_put_contents($config, '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = true;
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($datenbank, true) . ';
return $c;
');
    [$rc, , $err] = $power(['install', 'admin', 'Pruefung2026!!', '--ohne-wechsel'], $config);
    [$rc2, $seed, $err2] = $power(['demo:seed'], $config);
    pruefe($rc === 0 && $rc2 === 0 && str_contains($seed, '2 Tanks'), 'Demo-Gelände in eigener Instanz angelegt: ' . trim($seed . $err . $err2));
    $db = new PDO('sqlite:' . $datenbank, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $dort = static function (string $sql, array $werte = []) use ($db): array {
        $s = $db->prepare($sql);
        $s->execute($werte);
        return $s->fetchAll();
    };
    $tankDemo = $dort("SELECT * FROM objekte WHERE art = 'tank' AND ist_vorlage = 0 AND name = 'Tank Aggregat 1'")[0] ?? [];
    $eigDemo = json_decode((string) ($tankDemo['eigenschaften'] ?? ''), true) ?: [];
    $typDemo = $dort("SELECT id, sync_key FROM objekte WHERE art = 'tank' AND ist_vorlage = 1 ORDER BY sync_key");
    gleich(array_column($typDemo, 'sync_key'), ['tank-quadro-ag-1000', 'tank-quadro-ag-3000'], 'Demo: die Tank-Typen aus dem mitgelieferten Katalog');
    pruefe(($tankDemo['modell'] ?? '') === 'QUADRO-AG 3000' && ($eigDemo['zulassung'] ?? '') === 'D/BAM/16125/31A, Z-38.12-223'
        && ($eigDemo['kraftstoff'] ?? '') === 'HVO' && (float) ($eigDemo['sumpf_l'] ?? 0) === 140.0, 'Demo: Tank Aggregat 1 aus dem QUADRO-AG 3000, HVO, Sumpf 140 l');
    $mpDemo = $dort("SELECT m.*, i.kennung FROM messpunkte m JOIN einsaetze e ON e.id = m.einsatz_id JOIN inventar i ON i.id = e.inventar_id
                     WHERE m.objekt_id = ? AND m.rolle = 'fuellstand' AND m.gueltig_bis IS NULL", [(int) ($tankDemo['id'] ?? 0)]);
    gleich([count($mpDemo), $mpDemo[0]['kennung'] ?? null, $mpDemo[0]['geraet'] ?? null, $mpDemo[0]['kanal'] ?? null], [1, 'TS-01', 'shellyplusuni-tank1', 'voltmeter:100'],
        'Demo: TS-01 misst im Tank des Aggregats');
    $fassDemo = $dort('SELECT anlass, geprueft, ab, ereignis_id FROM tank_kennlinien WHERE messpunkt_id = ? ORDER BY id', [(int) ($mpDemo[0]['id'] ?? 0)]);
    gleich([array_column($fassDemo, 'anlass'), array_map('intval', array_column($fassDemo, 'geprueft'))], [['einsatz', 'betankung'], [0, 0]],
        'Demo: zwei Fassungen – Einbau und Betankung, beide ungeprüft (TS-01 wurde nie gepeilt)');
    pruefe(($fassDemo[0]['ab'] ?? '') === ($mpDemo[0]['gueltig_ab'] ?? '-') && Zeit::ts((string) ($mpDemo[0]['gueltig_ab'] ?? '')) < time() - 2 * 86400
        && Zeit::ts((string) ($fassDemo[1]['ab'] ?? '')) < time() - 86400 + 3600, 'Demo: Einbau vor drei Tagen, Kalibrierung seit gestern bzw. vorgestern');
    $ereignisDemo = $dort("SELECT * FROM tank_ereignisse WHERE objekt_id = ? AND art = 'betankung'", [(int) ($tankDemo['id'] ?? 0)]);
    pruefe(count($ereignisDemo) === 1 && $ereignisDemo[0]['lieferschein'] === 'DEMO-0815' && (float) $ereignisDemo[0]['liter'] === 528.0
        && $ereignisDemo[0]['roh_vor'] !== null && (float) $ereignisDemo[0]['roh_nach'] > (float) $ereignisDemo[0]['roh_vor']
        && (int) ($fassDemo[1]['ereignis_id'] ?? 0) === (int) $ereignisDemo[0]['id'], 'Demo: Betankung DEMO-0815 aus dem Sprung der Demo-Sonde ausgewertet');
    pruefe((float) ($eigDemo['liter_je_mm'] ?? 0) > 2.5 && (float) ($eigDemo['liter_je_mm'] ?? 0) < 3.2 && str_starts_with((string) ($eigDemo['liter_je_mm_herkunft'] ?? ''), 'Betankung'),
        'Demo: Inhalt je mm im Tank, aus der Betankung (' . ($eigDemo['liter_je_mm'] ?? '–') . ')');
    $reserve = $dort("SELECT o.id, e.prozent, e.zeit FROM objekte o LEFT JOIN tank_ereignisse e ON e.objekt_id = o.id WHERE o.name = 'Reserve Catering'");
    pruefe(count($reserve) === 1 && (float) $reserve[0]['prozent'] === 60.0 && Zeit::ts((string) $reserve[0]['zeit']) <= time()
        && $dort("SELECT id FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand'", [(int) $reserve[0]['id']]) === [],
        'Demo: Reserve Catering ohne Sensor, mit einer Peilung 60 %');
    gleich($dort("SELECT i.kennung FROM inventar i WHERE i.art = 'tanksensor' AND NOT EXISTS (SELECT 1 FROM einsaetze e WHERE e.inventar_id = i.id AND e.bis IS NULL)"),
        [['kennung' => 'TS-02']], 'Demo: TS-02 liegt im Lager');
    [$rc, $out] = $power(['tank:liste', '--als=technik'], $config);
    $agg = $zeileMit($out, 'Tank Aggregat 1');
    pruefe($rc === 0 && preg_match('/^(ok|warn|bad) /', $agg) === 1 && preg_match('/ \d[\d.]* l  \d+ %/u', $agg) === 1
        && str_starts_with($zeileMit($out, 'Reserve Catering'), 'keine ') && str_contains($zeileMit($out, 'Reserve Catering'), '60 %'),
        'Demo: tank:liste als Technik – ' . trim($out));
    [$rc, $out] = $power(['tank:rechnen', (string) ($mpDemo[0]['id'] ?? 0), '7,8'], $config);
    pruefe($rc === 0 && str_contains($out, '7,8 V →') && str_contains($out, ' l → ') && str_contains($out, 'betankung'), 'Demo: tank:rechnen an TS-01 – ' . trim($out));
    [$rc, $out] = $power(['katalog:import', $wurzel . '/resources/kataloge/bss-strombibel.json', '--pruefen'], $config);
    pruefe($rc === 0 && str_contains($out, '  schon da    QUADRO-AG 1000') && str_contains($out, '  schon da    QUADRO-AG 3000') && str_contains($out, '  neu         QUADRO-AG 2000'),
        'Demo: die Tank-Typen stehen genau wie im Katalog – der Import sieht sie „schon da“');
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennungH . '%']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    SiteService::deleteSite($siteH);
    foreach ([$adminH, $kundeH, $spaetH, $ausH] as $u) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$u]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$u]);
        Database::execute('DELETE FROM users WHERE id = ?', [$u]);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennungH . '%', '%' . $kennungH . '%']);
}

ende('tanks');
