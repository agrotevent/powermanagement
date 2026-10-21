<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Berichte\BerichtService;
use App\Berichte\Einstellungen;
use App\Berichte\Pruefgeraete;
use App\Messung\Datenquellen;
use App\Support\Config;
use App\Support\Database;
use App\Support\Zeit;
use App\Tanks\TankService;

/**
 * Ein Demo-Festivalgelände: Netzanschluss, Aggregat mit Tank am
 * Kraftstoffschlauch, Speicher, Verteilungen mit Sicherungen und RCDs, Kabel,
 * Kabelbrücken, Messgeräte, Benutzer mit zeitlich begrenzten Freigaben,
 * Prüfgeräte und drei Übergabeberichte (abgeschlossen, vom Prüfer
 * unterschrieben, Entwurf).
 *
 * Der Tank des Aggregats (QUADRO-AG 3000 aus dem mitgelieferten Katalog)
 * misst mit dem Tanksensor TS-01 seit drei Tagen; vorgestern bzw. gestern um
 * 06:00 ist er laut Lieferschein DEMO-0815 betankt worden, daraus kennt er
 * seinen Inhalt je mm. Der Reservetank am Catering hat keinen Sensor, nur
 * eine Peilung von heute früh; TS-02 liegt im Lager.
 *
 * Alle Namen, Firmen und Unterschriften sind erfunden.
 *
 * Grundlage der Screenshots und der Prüfskripte. Die Messwerte dazu
 * erzeugt App\Messung\Demo.
 */
final class DemoSeed
{
    public static function anlegen(bool $zuruecksetzen): string
    {
        if ($zuruecksetzen) {
            // Mit allen Berichten auch die Nummernzähler: die Demo beginnt wieder bei …-0001.
            foreach (['bericht_ereignisse', 'bericht_pdf', 'bericht_unterschriften', 'bericht_objekte', 'berichte', 'bericht_nummern', 'kundenmodus_geraete', 'pruefgeraete',
                'tank_ereignisse', 'tank_kennlinien',
                'aufbau_posten', 'aufbauten', 'einsaetze', 'inventar_messgeraete', 'inventar', 'bereich_mitglieder', 'bereiche', 'leitungen', 'messpunkte', 'anschluesse', 'schutzorgane', 'objekte', 'datenquellen', 'freigaben', 'floors', 'sites', 'schaltvorgaenge', 'mqtt_zugaenge'] as $tabelle) {
                Database::execute('DELETE FROM ' . $tabelle);
            }
            // Wie SiteService::deleteSite: Standort-Freigaben der Tokens
            // gehen mit, Vorschläge bleiben – nur ohne Standort.
            Database::execute('DELETE FROM api_token_standorte');
            Database::execute('UPDATE api_vorschlaege SET site_id = NULL WHERE site_id IS NOT NULL');
            Database::execute("DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE username IN ('veranstalter', 'technik'))");
            Database::execute("DELETE FROM users WHERE username IN ('veranstalter', 'technik')");
            @unlink(dirname(__DIR__, 2) . '/storage/demo/schalter.json');
        } elseif (Database::first("SELECT id FROM sites WHERE sync_key = 'demo-festival'") !== null) {
            return 'Das Demo-Gelände gibt es schon (mit --reset neu anlegen).';
        }
        AnlagenService::grundbestand();

        // ---- Standort mit Plan
        $siteId = SiteService::saveSite(null, ['name' => 'Festivalgelände Nordwiese', 'address' => 'Nordwiese 1, Musterstadt', 'notes' => 'Demo-Gelände']);
        Database::execute("UPDATE sites SET sync_key = 'demo-festival' WHERE id = ?", [$siteId]);
        $floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Gelände', 'level_no' => 0, 'sort_order' => 0]);
        $datei = 'demo_gelaende.svg';
        file_put_contents(SiteService::planDir() . '/' . $datei, self::planSvg());
        Database::update('floors', $floorId, [
            'plan_file' => $datei, 'plan_type' => 'svg', 'plan_page' => 1, 'plan_original_name' => 'gelaende.svg',
            'plan_uploaded_at' => gmdate('c'), 'plan_uploaded_by' => 'demo', 'sync_key' => 'gelaende',
            // 100 m zwischen den beiden Maßstabspunkten unten links.
            'massstab_ax' => 3.75, 'massstab_ay' => 95.0, 'massstab_bx' => 28.75, 'massstab_by' => 95.0,
            'massstab_m' => 100.0, 'massstab_verhaeltnis' => 0.625, 'massstab_von' => 'demo', 'massstab_am' => gmdate('c'),
            // Georeferenz an denselben Punkten: Der Plan ist um 12° gedreht
            // (Plan-oben zeigt nach Nordnordost).
            'geo_ax' => 3.75, 'geo_ay' => 95.0, 'geo_a_lat' => 51.44, 'geo_a_lon' => 7.56,
            'geo_bx' => 28.75, 'geo_by' => 95.0, 'geo_b_lat' => 51.439813, 'geo_b_lon' => 7.5614112,
            'geo_verhaeltnis' => 0.625, 'geo_von' => 'demo', 'geo_am' => gmdate('c'),
        ]);

        $typ = [];
        foreach (AnlagenService::steckertypen() as $t) {
            $typ[(string) $t['schluessel']] = (int) $t['id'];
        }

        // ---- Standard-Verteiler (Katalog)
        $vorlage = static function (string $name, string $modell, array $ein, array $ab, float $nennstrom) use ($typ): int {
            $id = (int) AnlagenService::speichern(null, [
                'art' => 'verteilung', 'name' => $name, 'modell' => $modell, 'hersteller' => 'Musterverleih',
                'nennstrom_a' => $nennstrom, 'ist_vorlage' => 1, 'ohne_anschluesse' => true,
                'eigenschaften' => ['schutzart' => 'IP44'],
            ])['id'];
            AnlagenService::anzahlSetzen($id, [
                'eingang' => array_combine(array_map(static fn ($k) => $typ[$k], array_keys($ein)), array_values($ein)),
                'abgang'  => array_combine(array_map(static fn ($k) => $typ[$k], array_keys($ab)), array_values($ab)),
            ]);
            SchutzService::vorschlagen($id);
            return $id;
        };
        $pd125 = $vorlage('PD 125 – Hauptverteiler', 'PD 125/63/32', ['powerlock' => 1], ['cee63' => 2, 'cee32' => 2, 'cee16' => 2], 125);
        $pd63 = $vorlage('PD 63 – Bühnenverteiler', 'PD 63/32/16', ['cee63' => 1], ['cee32' => 3, 'cee16' => 3, 'schuko' => 6], 63);
        $pd32 = $vorlage('PD 32 – Unterverteiler', 'PD 32/16', ['cee32' => 1], ['cee16' => 4, 'schuko' => 6], 32);
        $sv16 = $vorlage('SV 16 – Schukoverteiler', 'SV 16', ['cee16' => 1], ['schuko' => 6], 16);

        // Ein Feststromanschluss-Typ: die Energiesäulen, die fest auf dem
        // Gelände stehen. Aus diesem Katalog-Eintrag entstehen fest
        // installierte Anlagen – sie gehen beim Location-Abgleich mit.
        $saeule = (int) AnlagenService::speichern(null, [
            'art' => 'anschluss', 'name' => 'Energiesäule ES 32', 'modell' => 'ES 32/16', 'hersteller' => 'Stadtwerke Musterstadt',
            'nennstrom_a' => 63, 'ist_vorlage' => 1, 'fest' => 1, 'ohne_anschluesse' => true,
            'eigenschaften' => ['anschlussart' => 'Energiesäule', 'absicherung_a' => 63],
        ])['id'];
        AnlagenService::anzahlSetzen($saeule, ['abgang' => [$typ['cee32'] => 2, $typ['cee16'] => 2, $typ['schuko'] => 2]]);
        SchutzService::vorschlagen($saeule);

        // Tank-Typen aus dem mitgelieferten Katalog (Strombibel Seite 20) –
        // genau wie ein Import sie anlegt, ein späterer Import sieht sie „schon da“.
        $tankTyp = [];
        $paket = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/resources/kataloge/bss-strombibel.json'), true);
        foreach ((array) ($paket['typen'] ?? []) as $datei) {
            $schluessel = is_array($datei) ? (string) ($datei['schluessel'] ?? '') : '';
            if (in_array($schluessel, ['tank-quadro-ag-1000', 'tank-quadro-ag-3000'], true)) {
                $erg = VerteilerBundle::typAnwenden($schluessel, $datei);
                $tankTyp[$schluessel] = $erg['ok'] ? (int) $erg['id'] : null;
            }
        }

        $setzen = static function (array $daten, float $x, float $y) use ($siteId, $floorId): int {
            return (int) AnlagenService::speichern(null, $daten + ['site_id' => $siteId, 'floor_id' => $floorId, 'plan_x' => $x, 'plan_y' => $y, 'status' => 'in_betrieb'])['id'];
        };

        // ---- Feststromanschlüsse: gehören zum Gelände
        $netz = $setzen(['art' => 'anschluss', 'name' => 'Netzanschluss Nordwiese', 'kennung' => 'HA-01', 'nennstrom_a' => 250,
            'eigenschaften' => ['anschlussart' => 'Hausanschluss', 'absicherung_a' => 250, 'netzbetreiber' => 'Stadtwerke Musterstadt'],
            'ohne_anschluesse' => true], 7.5, 12.0);
        $saeuleDaten = ['art' => 'anschluss', 'vorlage_id' => $saeule, 'nennstrom_a' => 63, 'hersteller' => 'Stadtwerke Musterstadt', 'modell' => 'ES 32/16',
            'eigenschaften' => ['anschlussart' => 'Energiesäule', 'absicherung_a' => 63]];
        $saeule1 = $setzen(['name' => 'Energiesäule 1', 'kennung' => 'ES-01'] + $saeuleDaten, 32.0, 46.0);
        $saeule2 = $setzen(['name' => 'Energiesäule 2', 'kennung' => 'ES-02'] + $saeuleDaten, 52.0, 88.0);

        // ---- Mobile Anlagen: gehören zur Veranstaltung
        AnlagenService::anzahlSetzen($netz, ['abgang' => [$typ['powerlock'] => 1, $typ['cee125'] => 1]]);

        $gen = $setzen(['art' => 'generator', 'name' => 'Aggregat 1', 'kennung' => 'G-100-07', 'hersteller' => 'Musteraggregate',
            'modell' => 'SG 100', 'nennleistung_kva' => 100, 'eigenschaften' => ['steuerung' => 'ComAp InteliGen 200', 'kraftstoff' => 'HVO', 'tank_l' => 400, 'verbrauch_l_h' => 22,
                // die 100-kVA-Zeile der Strombibel – Verbrauch und Restlaufzeit des Tanks am Schlauch
                'verbrauch_kennlinie' => '100% 22L / 70% 16L / 50% 12L'],
            'ohne_anschluesse' => true], 88.0, 18.0);
        AnlagenService::anzahlSetzen($gen, ['abgang' => [$typ['powerlock'] => 1, $typ['cee63'] => 2, $typ['cee32'] => 1]]);
        SchutzService::vorschlagen($gen);

        $wr = $setzen(['art' => 'wechselrichter', 'name' => 'Speicher Backstage', 'kennung' => 'WR-02', 'hersteller' => 'Victron Energy',
            'modell' => '3× Quattro 48/15000', 'nennleistung_kva' => 45, 'eigenschaften' => ['system' => 'Victron GX (Cerbo)', 'batterie_kwh' => 60, 'batterie_typ' => 'LiFePO4'],
            'ohne_anschluesse' => true], 72.0, 76.0);
        AnlagenService::anzahlSetzen($wr, ['eingang' => [$typ['cee63'] => 1], 'abgang' => [$typ['cee32'] => 2, $typ['cee16'] => 2]]);
        SchutzService::vorschlagen($wr);

        $hv = $setzen(['art' => 'verteilung', 'name' => 'Hauptverteiler', 'kennung' => 'PD125-01', 'vorlage_id' => $pd125, 'nennstrom_a' => 125, 'modell' => 'PD 125/63/32'], 22.0, 30.0);
        $buehne = $setzen(['art' => 'verteilung', 'name' => 'Bühne', 'kennung' => 'PD63-04', 'vorlage_id' => $pd63, 'nennstrom_a' => 63, 'modell' => 'PD 63/32/16'], 50.0, 20.0);
        $catering = $setzen(['art' => 'verteilung', 'name' => 'Catering', 'kennung' => 'PD32-11', 'vorlage_id' => $pd32, 'nennstrom_a' => 32, 'modell' => 'PD 32/16'], 30.0, 70.0);
        $licht = $setzen(['art' => 'verteilung', 'name' => 'Licht Gelände', 'kennung' => 'PD32-12', 'vorlage_id' => $pd32, 'nennstrom_a' => 32, 'modell' => 'PD 32/16'], 62.0, 50.0);
        $kasse = $setzen(['art' => 'verteilung', 'name' => 'Kasse Einlass', 'kennung' => 'SV16-03', 'vorlage_id' => $sv16, 'nennstrom_a' => 16, 'modell' => 'SV 16'], 60.0, 93.0);
        $truckV = $setzen(['art' => 'verteilung', 'name' => 'Foodtruck-Verteiler', 'kennung' => 'PD63-07', 'vorlage_id' => $pd63, 'nennstrom_a' => 63, 'modell' => 'PD 63/32/16'], 75.0, 42.0);
        // Der Tank des Aggregats (doppelwandig, HVO wie das Aggregat) und ein
        // Reservetank am Catering, beide aus dem Katalog. Den Inhalt je mm
        // kennt keiner – er kommt aus der ersten Betankung bzw. Peilung.
        $tankAgg = $setzen(['art' => 'tank', 'name' => 'Tank Aggregat 1', 'kennung' => 'T3000-02', 'modell' => 'QUADRO-AG 3000',
            'vorlage_id' => $tankTyp['tank-quadro-ag-3000'] ?? null,
            'eigenschaften' => ['inhalt_l' => 3000, 'kraftstoff' => 'HVO', 'bauform' => 'Quader', 'sumpf_l' => 140, 'tankanschluss' => '2 Anschlüsse']], 84.0, 26.0);
        $tankReserve = $setzen(['art' => 'tank', 'name' => 'Reserve Catering', 'kennung' => 'T1000-05', 'modell' => 'QUADRO-AG 1000',
            'vorlage_id' => $tankTyp['tank-quadro-ag-1000'] ?? null,
            'eigenschaften' => ['inhalt_l' => 1000, 'kraftstoff' => 'Diesel', 'bauform' => 'Quader']], 25.0, 80.0);

        // ---- Kabel
        $anschluss = static function (int $objekt, string $richtung, string $stecker, int $n = 0) use ($typ): ?int {
            $rows = Database::select('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr', [$objekt, $richtung, $typ[$stecker]]);
            return isset($rows[$n]) ? (int) $rows[$n]['id'] : null;
        };
        $kabel = static function (int $von, ?int $vonA, int $zu, ?int $zuA, string $kabeltyp, float $q, array $punkte, string $name) use ($floorId): void {
            LeitungService::speichern(null, [
                'art' => 'kabel', 'floor_id' => $floorId, 'name' => $name, 'von_objekt_id' => $von, 'von_anschluss_id' => $vonA,
                'zu_objekt_id' => $zu, 'zu_anschluss_id' => $zuA, 'kabeltyp' => $kabeltyp, 'adern' => $kabeltyp === 'Powerlock-Einleiter' ? 5 : 5,
                'querschnitt_mm2' => $q, 'punkte' => $punkte,
            ]);
        };
        $kabel($netz, $anschluss($netz, 'abgang', 'powerlock'), $hv, $anschluss($hv, 'eingang', 'powerlock'), 'Powerlock-Einleiter', 95,
            [[7.5, 12.0], [7.5, 30.0], [22.0, 30.0]], 'Einspeisung HV');
        $kabel($hv, $anschluss($hv, 'abgang', 'cee63'), $buehne, $anschluss($buehne, 'eingang', 'cee63'), 'H07RN-F', 35,
            [[22.0, 30.0], [22.0, 20.0], [50.0, 20.0]], 'HV → Bühne');
        $kabel($hv, $anschluss($hv, 'abgang', 'cee32'), $catering, $anschluss($catering, 'eingang', 'cee32'), 'H07RN-F', 16,
            [[22.0, 30.0], [22.0, 70.0], [30.0, 70.0]], 'HV → Catering');
        $kabel($hv, $anschluss($hv, 'abgang', 'cee63', 1), $wr, $anschluss($wr, 'eingang', 'cee63'), 'H07RN-F', 16,
            [[22.0, 30.0], [45.0, 38.0], [45.0, 76.0], [72.0, 76.0]], 'HV → Speicher');
        $kabel($gen, $anschluss($gen, 'abgang', 'cee32'), $licht, $anschluss($licht, 'eingang', 'cee32'), 'H07RN-F', 16,
            [[88.0, 18.0], [88.0, 50.0], [62.0, 50.0]], 'Aggregat → Licht');
        // Fest verlegt: Erdkabel vom Netzanschluss zu den Energiesäulen – ein
        // Kabel zwischen zwei Feststromanschlüssen ist von Haus aus fest und
        // geht mit dem Location-Abgleich mit.
        $kabel($netz, null, $saeule1, null, 'NYY-J', 35, [[7.5, 12.0], [7.5, 46.0], [32.0, 46.0]], 'Erdkabel Säule 1');
        $kabel($saeule1, null, $saeule2, null, 'NYY-J', 35, [[32.0, 46.0], [45.0, 46.0], [45.0, 88.0], [52.0, 88.0]], 'Erdkabel Säule 2');
        // Ein mobiler Verteiler am Feststromanschluss des Geländes.
        $kabel($saeule2, $anschluss($saeule2, 'abgang', 'cee16'), $kasse, $anschluss($kasse, 'eingang', 'cee16'), 'H07RN-F', 2.5,
            [[52.0, 88.0], [60.0, 93.0]], 'Energiesäule 2 → Kasse');
        // Foodtruck-Meile: ein Verteiler am Aggregat, ein Truck direkt am
        // Aggregat. Die Trucks selbst sind keine Anlagen – ihre Kabel enden offen.
        $kabel($gen, $anschluss($gen, 'abgang', 'cee63'), $truckV, $anschluss($truckV, 'eingang', 'cee63'), 'H07RN-F', 16,
            [[88.0, 18.0], [91.0, 22.0], [91.0, 42.0], [75.0, 42.0]], 'Aggregat → Foodtrucks');
        $offen = static function (int $von, ?int $vonA, float $q, array $punkte, string $name) use ($floorId): void {
            LeitungService::speichern(null, [
                'art' => 'kabel', 'floor_id' => $floorId, 'name' => $name, 'von_objekt_id' => $von, 'von_anschluss_id' => $vonA,
                'kabeltyp' => 'H07RN-F', 'adern' => $q >= 4 ? 5 : 3, 'querschnitt_mm2' => $q, 'punkte' => $punkte,
            ]);
        };
        $offen($truckV, $anschluss($truckV, 'abgang', 'cee32'), 6, [[75.0, 42.0], [70.0, 42.0]], 'Foodtruck 1');
        $offen($truckV, $anschluss($truckV, 'abgang', 'cee32', 1), 6, [[75.0, 42.0], [80.0, 42.0]], 'Foodtruck 2');
        $offen($truckV, $anschluss($truckV, 'abgang', 'cee32', 2), 6, [[75.0, 42.0], [75.0, 38.0], [85.0, 38.0]], 'Foodtruck 3');
        $offen($truckV, $anschluss($truckV, 'abgang', 'schuko'), 2.5, [[75.0, 42.0], [66.0, 42.0], [66.0, 40.0]], 'Kaffeemobil');
        $offen($gen, $anschluss($gen, 'abgang', 'cee63', 1), 16, [[88.0, 18.0], [95.0, 18.0], [95.0, 42.0], [90.0, 44.0]], 'Grill-Truck');
        LeitungService::speichern(null, ['art' => 'kabelweg', 'floor_id' => $floorId, 'name' => 'Trasse Ost (Reserve)', 'punkte' => [[45.0, 38.0], [80.0, 38.0], [88.0, 30.0]]]);
        // Kraftstoffschlauch vom Tank zum Aggregat: Vor- und Rücklauf mit Steckkupplungen HK-A3.
        LeitungService::speichern(null, ['art' => 'kraftstoff', 'floor_id' => $floorId, 'name' => 'Tank → Aggregat 1', 'von_objekt_id' => $tankAgg, 'zu_objekt_id' => $gen,
            'kabeltyp' => 'DN 10 (3/8″)', 'modell' => 'HK-A3', 'anzahl' => 2, 'punkte' => [[84.0, 26.0], [86.0, 21.0], [88.0, 18.0]]]);
        LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'name' => 'Überfahrt Hauptweg', 'modell' => '5-Kanal', 'anzahl' => 4, 'punkte' => [[20.5, 50.0], [23.5, 50.0]]]);
        LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'name' => 'Rettungsweg', 'modell' => 'Defender Midi', 'anzahl' => 3, 'punkte' => [[43.5, 60.0], [46.5, 60.0]]]);

        // ---- Datenquellen und Messgeräte
        $shelly = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Shelly Pro 4PM Licht', 'geraet' => 'shellypro4pm-licht',
            'extern_id' => 'shellypro4pm-licht', 'profil' => 'gen2', 'aktiv' => 1, 'intervall_s' => 60])['id'];
        $gx = (int) Datenquellen::speichern(null, ['art' => 'modbus', 'name' => 'Cerbo GX Backstage', 'geraet' => 'gx-backstage',
            'host' => '10.20.30.2', 'port' => 502, 'unit_id' => 227, 'profil' => 'victron_gx', 'intervall_s' => 2, 'aktiv' => 1,
            'notizen' => 'Über den WireGuard-Tunnel zum Event-Router erreichbar.'])['id'];
        Datenquellen::speichern(null, ['art' => 'modbus', 'name' => 'ComAp IG200 Aggregat 1', 'geraet' => 'ig200-aggregat1',
            'host' => '10.20.30.10', 'port' => 502, 'unit_id' => 1, 'profil' => 'comap_ig200', 'intervall_s' => 2, 'aktiv' => 0,
            'notizen' => 'Adressen aus InteliConfig ins Profil übernehmen, dann aktivieren.']);

        $mp = static function (int $objekt, string $geraet, string $kanal, string $rolle, string $bezeichnung = '', ?int $anschluss = null): void {
            MesspunktService::speichern($objekt, null, ['geraet' => $geraet, 'kanal' => $kanal, 'rolle' => $rolle, 'bezeichnung' => $bezeichnung, 'anschluss_id' => $anschluss]);
        };
        $mp($hv, 'shellypro3em-einspeisung', 'em:0', 'einspeisung', 'Einspeisung (Pro 3EM)');
        $mp($buehne, 'shellypro3em-buehne', 'em:0', 'einspeisung', 'Einspeisung (Pro 3EM)');
        $mp($catering, 'shellypro3em-catering', 'em:0', 'einspeisung', 'Einspeisung (Pro 3EM)');
        // Die vier Lichtkreise hängen an Schuko-Abgängen, verteilt auf die Phasen.
        $phasen = ['L1', 'L2', 'L3', 'L1'];
        foreach (Database::select('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr LIMIT 4', [$licht, 'abgang', $typ['schuko']]) as $i => $a) {
            Database::update('anschluesse', (int) $a['id'], ['phase' => $phasen[$i], 'notizen' => 'Lichtkreis ' . ($i + 1)]);
            $mp($licht, 'shellypro4pm-licht', 'switch:' . $i, 'abgang', 'Lichtkreis ' . ($i + 1), (int) $a['id']);
        }
        $mp($wr, 'gx-backstage', 'ac_aus', 'erzeugung', 'AC-Ausgang');
        $mp($wr, 'gx-backstage', 'batterie', 'batterie', 'Batterie');
        $mp($gen, 'ig200-aggregat1', 'generator', 'erzeugung', 'Generator');
        $mp($gen, 'ig200-aggregat1', 'motor', 'erzeugung', 'Motor');
        // ---- Inventar: Verteiler und Zwischenstecker mit ihren Shellys. Der
        // PD63-07 steht seit drei Tagen als Foodtruck-Verteiler, der
        // Zwischenstecker ZS-63-02 steckt am Aggregat-Abgang des Grill-Trucks.
        $kaffee = $anschluss($truckV, 'abgang', 'schuko');
        Database::update('anschluesse', (int) $kaffee, ['phase' => 'L2', 'notizen' => 'Kaffeemobil']);
        $schukoNr = (int) Database::scalar('SELECT nr FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr LIMIT 1', [$pd63, 'abgang', $typ['schuko']]);
        $inventar = static function (array $daten): int {
            return (int) InventarService::speichern(null, $daten)['id'];
        };
        $pd6307 = $inventar(['art' => 'verteilung', 'kennung' => 'PD63-07', 'name' => 'PD 63 mit Pro 3EM', 'vorlage_id' => $pd63, 'seriennummer' => 'MV-2023-0417',
            'messgeraete' => [
                ['geraet' => 'shellypro3em-foodtruck', 'kanal' => 'em:0', 'stelle' => 'einspeisung', 'bezeichnung' => 'Einspeisung (Pro 3EM)'],
                ['geraet' => 'shellypro1pm-kaffee', 'kanal' => 'switch:0', 'stelle' => 'abgang:' . $schukoNr, 'bezeichnung' => 'Schuko-Abgang (Pro 1PM)'],
            ]]);
        $zs6302 = $inventar(['art' => 'zwischenstecker', 'kennung' => 'ZS-63-02', 'name' => 'Messadapter CEE 63', 'steckertyp_id' => $typ['cee63'],
            'messgeraete' => [['geraet' => 'shellypro3em-grilltruck', 'kanal' => 'em:0', 'stelle' => 'durchgang']]]);
        $inventar(['art' => 'verteilung', 'kennung' => 'PD32-15', 'vorlage_id' => $pd32,
            'messgeraete' => [['geraet' => 'shellypro3em-pd3215', 'kanal' => 'em:0', 'stelle' => 'einspeisung']]]);
        $inventar(['art' => 'zwischenstecker', 'kennung' => 'ZS-32-05', 'steckertyp_id' => $typ['cee32'],
            'messgeraete' => [['geraet' => 'shellypro3em-zs3205', 'kanal' => 'em:0', 'stelle' => 'durchgang']]]);
        $inventar(['art' => 'zwischenstecker', 'kennung' => 'ZS-125-01', 'steckertyp_id' => $typ['cee125'], 'defekt' => 1,
            'notizen' => 'Stromwandler L2 gebrochen – zur Reparatur.']);
        // Tanksensoren: TS-01 (Plus Uni, Sonde 0–100 mbar an 500 Ω, meldet in
        // Schritten von 0,1 V) steckt im Tank des Aggregats, TS-02 (Sonde
        // 0–2 mWS am Wandler 0–20 mA → 0–10 V) liegt im Lager.
        $ts01 = $inventar(['art' => 'tanksensor', 'kennung' => 'TS-01',
            'eigenschaften' => ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500,
                'bereich_von' => 0, 'bereich_bis' => 100, 'bereich_einheit' => 'mbar', 'schritt_roh' => 0.1],
            'messgeraete' => [['geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'stelle' => 'fuellstand']]]);
        $inventar(['art' => 'tanksensor', 'kennung' => 'TS-02', 'name' => 'TL-136, 5 m Kabel',
            'eigenschaften' => ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA-wandler', 'u_bei_4ma' => 2.0, 'u_bei_20ma' => 10.0,
                'bereich_von' => 0, 'bereich_bis' => 2, 'bereich_einheit' => 'mWS', 'schritt_roh' => 0.05],
            'messgeraete' => [['geraet' => 'shellyplusuni-tank2', 'kanal' => 'voltmeter:100', 'stelle' => 'fuellstand']],
            'notizen' => 'Wandler auf 0–20 mA → 0–10 V gestellt (4 mA = 2,00 V). Kabel 5 m, belüftet.']);
        InventarService::einsetzen($pd6307, $truckV);
        InventarService::einsetzen($zs6302, $gen, $anschluss($gen, 'abgang', 'cee63', 1));
        InventarService::einsetzen($ts01, $tankAgg, null, true, ['offset_mm' => 20]);
        // Alles seit drei Tagen im Einsatz – auch die erste Kennlinie des
        // Tanksensors (ab dem Einbau, noch ohne Inhalt je mm).
        $seit = gmdate('c', time() - 3 * 86400);
        Database::execute('UPDATE einsaetze SET ab = ?', [$seit]);
        Database::execute('UPDATE messpunkte SET gueltig_ab = ? WHERE einsatz_id IS NOT NULL', [$seit]);
        Database::execute('UPDATE tank_kennlinien SET ab = ?', [$seit]);

        // ---- Bereich: die Foodtruck-Meile. Das Aggregat versorgt auch das
        // Licht, zählt also nicht als Ganzes – nur sein Abgang zum Grill-Truck.
        // Das Kaffeemobil steckt im Foodtruck-Verteiler und zählt nicht doppelt.
        $messpunkt = static fn (string $geraet): int => (int) Database::scalar('SELECT id FROM messpunkte WHERE geraet = ? ORDER BY id LIMIT 1', [$geraet]);
        BereichService::speichern(null, [
            'site_id' => $siteId, 'name' => 'Foodtruck-Meile', 'farbe' => '#db2777', 'grenze_kw' => '90', 'grenze_a' => '130',
            'notizen' => 'Trucks am Hauptweg Ost und das Catering. Zugesagt: 90 kW.',
            'mitglieder' => ['o:' . $truckV, 'o:' . $catering, 'm:' . $messpunkt('shellypro3em-grilltruck'), 'm:' . $messpunkt('shellypro1pm-kaffee')],
        ]);

        // ---- Aufbau: vor 3½ Tagen gestartet, seit 2½ Tagen in Betrieb. Fast
        // alles wie geplant; die Kasse steht 8 m weiter, das Kabel zum
        // Grill-Truck wurde länger, Foodtruck 3 hat abgesagt, und für die Bar
        // kam eine Verlängerung dazu.
        $aufbau = (int) AufbauService::starten($siteId, 'Sommerfestival 2026')['id'];
        $plan = AufbauService::find($aufbau)['plan'];
        foreach ($plan['objekte'] as $o) {
            $o['id'] === $kasse
                ? AufbauService::postenSetzen($aufbau, 'objekt', $kasse, 'geaendert', null, 'an die Zufahrt versetzt – 8 m weiter rechts')
                : AufbauService::postenSetzen($aufbau, 'objekt', (int) $o['id'], 'bestaetigt');
        }
        AnlagenService::position($kasse, $floorId, 62.0, 93.0);
        foreach ($plan['leitungen'] as $l) {
            match ($l['name']) {
                'Grill-Truck'  => AufbauService::postenSetzen($aufbau, 'leitung', (int) $l['id'], 'geaendert', 125.0, 'um den Technikbereich herum verlegt'),
                'Foodtruck 3'  => AufbauService::postenSetzen($aufbau, 'leitung', (int) $l['id'], 'entfallen', null, 'Truck hat abgesagt'),
                default        => AufbauService::postenSetzen($aufbau, 'leitung', (int) $l['id'], 'bestaetigt'),
            };
        }
        $offen($catering, $anschluss($catering, 'abgang', 'schuko'), 2.5, [[30.0, 70.0], [36.0, 64.0]], 'Verlängerung Bar');
        AufbauService::fertig($aufbau);
        Database::update('aufbauten', $aufbau, ['gestartet_am' => gmdate('c', time() - 84 * 3600), 'betrieb_ab' => gmdate('c', time() - 60 * 3600)]);
        Database::execute('UPDATE aufbau_posten SET am = ?', [gmdate('c', time() - 70 * 3600)]);

        // ---- Benutzer mit Zeitfenstern
        $jetzt = time();
        $veranstalter = BenutzerService::anlegen('veranstalter', 'Veranstalter2026!', 'kunde', false);
        Database::update('users', $veranstalter, ['full_name' => 'Veranstalter Nordwiese', 'gueltig_bis' => Zeit::iso($jetzt + 10 * 86400)]);
        Database::insert('freigaben', [
            'subject_type' => 'user', 'subject_id' => $veranstalter, 'ziel_art' => 'site', 'ziel_id' => $siteId, 'ziel_kanal' => '',
            'darf_bearbeiten' => 0, 'darf_steuern' => 0,
            'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'gueltig_bis' => Zeit::iso($jetzt + 7 * 86400),
            'daten_ab' => Zeit::iso($jetzt - 3 * 86400), 'daten_bis' => Zeit::iso($jetzt + 7 * 86400),
            'notiz' => 'Festivalwoche', 'created_by' => 'demo', 'created_at' => gmdate('c'),
        ]);
        $technik = BenutzerService::anlegen('technik', 'Technik2026!!', 'technik', false);
        Database::update('users', $technik, ['full_name' => 'Tom Technik']);
        Database::insert('freigaben', [
            'subject_type' => 'user', 'subject_id' => $technik, 'ziel_art' => 'site', 'ziel_id' => $siteId, 'ziel_kanal' => '',
            'darf_bearbeiten' => 1, 'darf_steuern' => 0, 'gueltig_ab' => null, 'gueltig_bis' => null, 'daten_ab' => null, 'daten_bis' => null,
            'notiz' => 'Aufbau und Betrieb', 'created_by' => 'demo', 'created_at' => gmdate('c'),
        ]);
        // Schalten nur am Lichtgerät – je Gerät vergeben.
        Database::insert('freigaben', [
            'subject_type' => 'user', 'subject_id' => $technik, 'ziel_art' => 'geraet', 'ziel_id' => $shelly, 'ziel_kanal' => '',
            'darf_bearbeiten' => 0, 'darf_steuern' => 1, 'gueltig_ab' => null, 'gueltig_bis' => null, 'daten_ab' => null, 'daten_bis' => null,
            'notiz' => 'Lichtkreise schalten', 'created_by' => 'demo', 'created_at' => gmdate('c'),
        ]);
        unset($gx);

        // ---- Tanks vor Ort, eingetragen von der Technik: Betankung des
        // Aggregat-Tanks laut Lieferschein (die Demo-Sonde springt jeden Tag um
        // 06:00 auf 2.700 l, 528 l nach 24 h mit 22 l/h) – ausgewertet wie in
        // der App, daraus lernt der Tank seinen Inhalt je mm. Die Fassung gilt
        // ab eine Stunde nach dem Tanken, wie wenn sie dann ausgewertet worden
        // wäre. Am Reservetank eine Peilung von heute 08:00.
        Auth::alsBenutzer($technik);
        $ortszeit = static fn (int $t): \DateTimeImmutable => (new \DateTimeImmutable('@' . $t))->setTimezone(Zeit::zone());
        $sechs = $ortszeit(time())->setTime(6, 0);
        $sechs = ($sechs->getTimestamp() > time() ? $sechs->modify('-1 day') : $sechs)->modify('-1 day');
        $tank = AnlagenService::find($tankAgg);
        $betankung = $tank !== null ? TankService::betankungSpeichern($tank, ['liter' => '528', 'zeit' => $sechs->format('Y-m-d\TH:i'),
            'lieferschein' => 'DEMO-0815', 'notizen' => 'Tankwagen Musteröl']) : ['ok' => false];
        if (!empty($betankung['kalibriert'])) {
            Database::execute("UPDATE tank_kennlinien SET ab = ?, created_at = ? WHERE objekt_id = ? AND anlass = 'betankung'",
                [Zeit::iso($sechs->getTimestamp() + 3600), Zeit::iso($sechs->getTimestamp() + 3600), $tankAgg]);
        }
        $acht = $ortszeit(time())->setTime(8, 0);
        $acht = $acht->getTimestamp() > time() ? $acht->modify('-1 day') : $acht;
        $tank = AnlagenService::find($tankReserve);
        $peilung = $tank !== null ? TankService::peilen($tank, ['prozent' => '60', 'notizen' => 'Anzeige am Tank']) : ['ok' => false];
        if (!empty($peilung['id'])) {
            Database::execute('UPDATE tank_ereignisse SET zeit = ?, created_at = ? WHERE id = ?',
                [Zeit::iso($acht->getTimestamp()), Zeit::iso($acht->getTimestamp()), (int) $peilung['id']]);
        }
        Auth::alsBenutzer(null);

        // ---- Eigene MQTT-Zugänge: das Licht ist umgestellt, das Kaffeemobil
        // wartet auf seinen Neustart. Der Hash ist ein Platzhalter – im
        // Demo-Modus gibt es keinen Broker.
        foreach (['shellypro4pm-licht' => ['eigen', 30], 'shellypro1pm-kaffee' => ['uebertragen', 2]] as $praefix => [$zustand, $tage]) {
            $am = gmdate('c', time() - $tage * 86400);
            Database::insert('mqtt_zugaenge', [
                'benutzer' => $praefix, 'praefix' => $praefix, 'geraet' => $praefix, 'generation' => 2,
                'kennwort_hash' => '$7$101$' . str_repeat('A', 16) . '$' . str_repeat('A', 86) . '==',
                'zustand' => $zustand, 'neustart_noetig' => $zustand === 'uebertragen' ? 1 : 0,
                'kennwort_am' => $am, 'uebertragen_am' => $am, 'bestaetigt_am' => $zustand === 'eigen' ? gmdate('c', time() - ($tage - 1) * 86400) : null,
                'meldung' => $zustand === 'uebertragen' ? 'Wirksam ab dem nächsten Neustart des Geräts.' : '',
                'created_by' => null, 'created_at' => $am, 'updated_at' => $am,
            ]);
        }

        // ---- Übergabeberichte
        self::berichte($siteId, $aufbau, $technik, ['aggregat' => $gen, 'licht' => $licht, 'foodtruck' => $truckV, 'catering' => $catering, 'kasse' => $kasse]);

        return 'Demo-Gelände angelegt: ' . (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 0') . ' Anlagen (davon '
            . (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 0 AND fest = 1') . ' Feststromanschlüsse, '
            . (int) Database::scalar("SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 0 AND art = 'tank'") . ' Tanks), '
            . (int) Database::scalar('SELECT COUNT(*) FROM leitungen') . ' Leitungen, '
            . (int) Database::scalar('SELECT COUNT(*) FROM bereiche') . ' Bereich, '
            . (int) Database::scalar('SELECT COUNT(*) FROM inventar') . ' Inventarstücke, '
            . (int) Database::scalar('SELECT COUNT(*) FROM aufbauten') . ' Aufbau, '
            . (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1') . ' Katalog-Einträge, '
            . (int) Database::scalar('SELECT COUNT(*) FROM berichte') . ' Übergabeberichte. '
            . 'Zugänge: veranstalter / Veranstalter2026! (nur Festivalwoche), technik / Technik2026!! (schaltet nur das Licht, unterschreibt Übergabeberichte als Prüfer).'
            . (Config::isDemo() ? '' : ' Hinweis: app.demo ist aus – ohne InfluxDB bleiben die Messwerte leer.');
    }

    /**
     * Drei Übergabeberichte zum Aufbau „Sommerfestival 2026“, angelegt und
     * unterschrieben über BerichtService wie am Tablet – als Prüfer
     * „technik“:
     *
     *  1. abgeschlossen: Aggregat 1 und „Licht Gelände“ (am Aggregat), mit
     *     Messwerten, einer Zeile „Abschaltung durch RCD“, Unterschriften
     *     von Prüfer und Kunde und gespeichertem PDF;
     *  2. vom Prüfer unterschrieben: „Kasse Einlass“ – wartet auf den Kunden
     *     (Kundenmodus zum Ausprobieren);
     *  3. Entwurf: Foodtruck-Verteiler und Catering, halb ausgefüllt, ohne
     *     Kunde (Eventworx-Suche zum Ausprobieren).
     *
     * Die Unterschriften sind feste, erfundene Strichlisten
     * (resources/demo); jeder Bericht bekommt eigene Striche, sonst greift
     * der Kopierschutz.
     *
     * @param array{aggregat:int, licht:int, foodtruck:int, catering:int, kasse:int} $anlagen
     */
    private static function berichte(int $siteId, int $aufbauId, int $technik, array $anlagen): void
    {
        // Erfundene Firmendaten – nur im Demo-Modus und nur, wenn noch keine eingetragen sind.
        if (Config::isDemo() && Einstellungen::wert('firma.name') === '') {
            Einstellungen::speichern([
                'firma.name'            => 'Muster Eventstrom GmbH',
                'firma.zusatz'          => 'Mobile Stromversorgung für Veranstaltungen',
                'firma.anschrift'       => "Muster Eventstrom GmbH\nMusterstraße 1\n12345 Musterstadt",
                'firma.kontakt'         => "Tel. 0123 4567-0\ninfo@example.org\nwww.example.org",
                'firma.bank'            => "Musterbank Musterstadt\nIBAN DE00 0000 0000 0000 0000 00\nBIC MUSTDEXXXX",
                'firma.register'        => "Amtsgericht Musterstadt HRB 0000\nGeschäftsführung: Max Muster\nUSt-IdNr. DE000000000",
                'berichte.bereitschaft' => '0170 0000000',
            ]);
        }
        Database::update('users', $technik, ['pruefer_qualifikation' => 'Elektrofachkraft für Veranstaltungstechnik']);
        $heute = new \DateTimeImmutable('today', Zeit::zone());
        $fluke = (int) Pruefgeraete::speichern(null, ['fabrikat' => 'Fluke', 'typ' => '1664 FC', 'seriennummer' => 'DEMO-0001',
            'kalibriert_bis' => $heute->modify('+1 year')->format('Y-m-d'), 'aktiv' => 1])['id'];
        Pruefgeraete::speichern(null, ['fabrikat' => 'Benning', 'typ' => 'IT 130', 'seriennummer' => 'DEMO-0002',
            'kalibriert_bis' => $heute->modify('-2 months')->format('Y-m-d'), 'aktiv' => 1, 'notizen' => 'Erdungsmesser – Kalibrierung abgelaufen, zur Kalibrierung geben.']);

        $striche = static function (string $wer, int $versatz = 0): string {
            $s = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/demo/unterschrift-' . $wer . '.json'), true);
            foreach ($s['s'] as &$linie) {
                foreach ($linie as &$p) {
                    $p = [max(0, min(10000, $p[0] + $versatz)), max(0, min(10000, $p[1] + intdiv($versatz, 2)))];
                }
            }
            return json_encode(['s' => $s['s']]);
        };
        $anfrage = static fn (): string => bin2hex(random_bytes(16));
        // Querschnitt der Abgänge (innen) nach Steckverbinder.
        $querschnitt = ['CEE 63 A' => '16', 'CEE 32 A' => '6', 'CEE 16 A' => '2.5', 'Schuko' => '2.5'];
        $wert = static fn (string $w, string $op = ''): array => ['op' => $op, 'w' => $w];
        /**
         * Messwerte für $kreise[$von..$bis): je Kreis Zs und R_iso, die RCD-Werte
         * einmal je RCD und Anlage – die weiteren Kreise am selben RCD verweisen
         * mit rcd_von auf diese Zeile.
         */
        $messen = static function (array $kreise, int $von, int $bis, array $zs = []) use ($querschnitt, $wert): array {
            $rcdZeile = [];
            for ($n = $von; $n < min($bis, count($kreise)); $n++) {
                $k = $kreise[$n];
                $art = (string) $k['leitung']['art'];
                if (!isset($querschnitt[$art])) {
                    $k['status'] = 'nicht_gemessen';
                    $k['grund'] = 'Abgang nicht belegt';
                    $kreise[$n] = $k;
                    continue;
                }
                $k['leitung']['querschnitt'] = $querschnitt[$art];
                $k['status'] = 'gemessen';
                // Zs 0,35–0,90 Ω je nach Lage; C32 unter 0,72 Ω.
                $k['w']['zs'] = $wert($zs[$n] ?? sprintf('%.2f', min((string) $k['ueb']['in'] === '32' ? 0.68 : 0.9, 0.35 + (($n * 37) % 56) / 100)));
                $k['w']['riso'] = $wert('550', '>');
                $gruppe = $k['objekt_id'] . '|' . $k['rcd']['bez'];
                if ((string) $k['rcd']['idn'] === '') {
                    // ohne RCD
                } elseif (isset($rcdZeile[$gruppe])) {
                    $k['rcd_von'] = $rcdZeile[$gruppe];
                } else {
                    $rcdZeile[$gruppe] = (string) $k['z'];
                    $idn = (int) $k['rcd']['idn'];
                    $k['w']['idelta'] = $wert((string) intdiv($idn * (60 + ($n * 7) % 21), 100));
                    $k['w']['ta'] = $wert((string) (14 + ($n * 5) % 15));
                    $k['w']['ub'] = $wert(sprintf('%.1f', 0.4 + (($n * 3) % 9) / 10));
                }
                $kreise[$n] = $k;
            }
            return $kreise;
        };
        $kurzlisteIo = static function (): array {
            $punkte = ['besichtigung' => [], 'erprobung' => []];
            foreach (['besichtigung' => \App\Berichte\Katalog::besichtigung(), 'erprobung' => \App\Berichte\Katalog::erprobung()] as $teil => $katalog) {
                foreach ($katalog as $schluessel => $punkt) {
                    if ($punkt['kurz']) {
                        $punkte[$teil][$schluessel] = 'io';
                    }
                }
            }
            return $punkte;
        };
        $speichern = static function (int $id, array $inhalt): void {
            $r = BerichtService::speichern($id, (int) BerichtService::find($id)['version'], (string) json_encode($inhalt, JSON_UNESCAPED_UNICODE));
            if (!$r['ok']) {
                throw new \RuntimeException('Demo-Bericht: ' . $r['message']);
            }
        };
        $unterschreiben = static function (int $id, string $striche) use ($anfrage): void {
            $b = BerichtService::find($id);
            $p = BerichtService::abschlussPruefen($b);
            if ($p['fehler'] !== []) {
                throw new \RuntimeException('Demo-Bericht: ' . implode(' ', array_column($p['fehler'], 'text')));
            }
            $r = BerichtService::prueferUnterschreiben($id, (int) $b['version'], ['striche' => $striche, 'name' => 'Tom Technik', 'ort' => 'Musterstadt',
                'anfrage' => $anfrage(), 'warnungen' => array_column($p['warnungen'], 'code')]);
            if (!$r['ok']) {
                throw new \RuntimeException('Demo-Bericht: ' . $r['message']);
            }
        };
        $anlegen = static function (array $objekte) use ($siteId, $aufbauId): int {
            $r = BerichtService::anlegen($siteId, $aufbauId, $objekte);
            if (!$r['ok']) {
                throw new \RuntimeException('Demo-Bericht: ' . $r['message']);
            }
            return (int) $r['id'];
        };

        Auth::alsBenutzer($technik);
        try {
            // ---- 1: abgeschlossen
            $id = $anlegen([$anlagen['aggregat'], $anlagen['licht']]);
            $i = BerichtService::find($id)['inhalt'];
            $i['kopf']['kunde'] = ['firma' => 'Musterfilm Produktion GmbH', 'anschrift' => "Filmallee 5\n12345 Musterstadt", 'kundennummer' => '',
                'ansprechpartner' => 'Max Mustermann', 'telefon' => '0123 9876-0', 'email' => 'produktion@example.org', 'quelle' => 'freitext'];
            $i['kopf']['ort'] = 'Musterstadt';
            $i['kopf']['anlage'] = ['bezeichnung' => 'Aggregat 1 (G-100-07) mit Verteiler „Licht Gelände“ (PD32-12)', 'details' => 'Stromerzeuger 100 kVA, Unterverteilung PD 32/16',
                'aufstellort' => 'Festivalgelände Nordwiese, Technikfläche'];
            $i['kopf']['anlagenverantwortlicher'] = 'Max Mustermann';
            $i['netz'] = ['einspeisung' => 'generator', 'spannung' => '400', 'frequenz' => '50', 'form' => 'TN-S', 'erder' => 'Staberder am Aggregat',
                'u_ln' => '231', 'drehfeld' => 'rechts'];
            $i['punkte'] = $kurzlisteIo();
            $i['punkte']['besichtigung']['zus_potentialausgleich'] = 'entf';
            $i['messung']['erdung'] = ['op' => '', 'w' => '12.4', 'methode' => 'Erdungsmessung 3-Leiter', 'basis' => 'generator', 'grenze' => ''];
            $i['messung']['riso_u'] = '500';
            $i['messung']['geraete'] = [(string) $fluke];
            // Kreis 3 (Grill-Truck, 108 m Kabel): Zs 0,90 Ω an C63 – abgeschaltet wird über den RCD.
            $i['kreise'] = $messen($i['kreise'], 0, count($i['kreise']), [1 => '0.35', 2 => '0.90', 3 => '0.58']);
            $i['ergebnis'] = ['entspricht' => 'ja', 'maengelfrei' => 'ja', 'instand_bis' => '', 'plakette' => 'ja', 'plakette_nr' => 'P-2026-0117',
                'naechste' => \App\Berichte\BerichtSchema::NAECHSTE, 'errichter' => '1'];
            $i['bemerkungen'] = 'Prüfung nach dem Aufbau im Beisein des Kunden. Abgang X1 (Powerlock) nicht belegt.';
            $speichern($id, $i);
            $unterschreiben($id, $striche('pruefer'));
            $r = BerichtService::kundeUnterschreiben($id, ['striche' => $striche('kunde'), 'name' => 'Max Mustermann', 'funktion' => 'Produktionsleitung',
                'ort' => 'Musterstadt', 'bestaetigungen' => ['erhalten', 'eingewiesen', 'uebernommen'], 'vorbehalte' => '', 'anfrage' => $anfrage(),
                'inhalt_hash' => (string) BerichtService::find($id)['inhalt_hash']]);
            if (!$r['ok']) {
                throw new \RuntimeException('Demo-Bericht: ' . $r['message']);
            }

            // ---- 2: vom Prüfer unterschrieben, der Kunde fehlt noch
            $id = $anlegen([$anlagen['kasse']]);
            $i = BerichtService::find($id)['inhalt'];
            $i['kopf']['kunde'] = ['firma' => 'Muster Events GmbH & Co. KG', 'anschrift' => "Musterstraße 1\n12345 Musterstadt", 'kundennummer' => 'K-10023',
                'ansprechpartner' => 'Erika Musterfrau', 'telefon' => '+49 30 1234567', 'email' => 'erika.musterfrau@example.org', 'quelle' => 'freitext'];
            $i['kopf']['ort'] = 'Musterstadt';
            $i['kopf']['anlage'] = ['bezeichnung' => 'Schukoverteiler „Kasse Einlass“ (SV16-03)', 'details' => 'an Energiesäule 2', 'aufstellort' => 'Einlass, Kassenhaus'];
            $i['netz'] = ['einspeisung' => 'fest', 'spannung' => '400', 'frequenz' => '50', 'form' => 'TN-C-S', 'erder' => '', 'u_ln' => '229', 'drehfeld' => 'rechts'];
            $i['punkte'] = $kurzlisteIo();
            $i['messung']['riso_u'] = '500';
            $i['messung']['geraete'] = [(string) $fluke];
            $i['kreise'] = $messen($i['kreise'], 0, count($i['kreise']));
            $i['ergebnis'] = ['entspricht' => 'ja', 'maengelfrei' => 'ja', 'instand_bis' => '', 'plakette' => 'nein', 'plakette_nr' => '',
                'naechste' => \App\Berichte\BerichtSchema::NAECHSTE, 'errichter' => '1'];
            $speichern($id, $i);
            $unterschreiben($id, $striche('pruefer', 37));

            // ---- 3: Entwurf, halb ausgefüllt, ohne Kunde
            $id = $anlegen([$anlagen['foodtruck'], $anlagen['catering']]);
            $i = BerichtService::find($id)['inhalt'];
            $i['kopf']['ort'] = 'Musterstadt';
            $i['kopf']['anlage']['aufstellort'] = 'Foodtruck-Meile und Catering';
            $i['netz']['einspeisung'] = 'generator';
            $i['punkte']['besichtigung'] = ['auswahl' => 'io', 'maengelfrei' => 'io', 'verlegung' => 'nio', 'kennzeichnung' => 'io'];
            $i['punkt_bemerkung'] = ['verlegung' => 'Kabel zur Bar liegt ohne Kabelbrücke über dem Fluchtweg.'];
            $i['messung']['geraete'] = [(string) $fluke];
            $i['kreise'] = $messen($i['kreise'], 0, intdiv(count($i['kreise']), 2));
            $speichern($id, $i);
        } finally {
            Auth::alsBenutzer(null);
        }
    }

    /** Ein einfaches Festivalgelände als SVG (1600 × 1000). */
    public static function planSvg(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000" viewBox="0 0 1600 1000">
  <rect width="1600" height="1000" fill="#eef5e9"/>
  <g fill="none" stroke="#d6c7a1" stroke-width="46" stroke-linecap="round" stroke-linejoin="round">
    <path d="M 60 500 L 1540 500"/>
    <path d="M 720 500 L 720 960"/>
    <path d="M 340 120 L 340 880"/>
  </g>
  <g font-family="Helvetica, Arial, sans-serif" font-size="26" fill="#475569">
    <rect x="660" y="90" width="340" height="170" fill="#cbd5e1" stroke="#64748b" stroke-width="3"/>
    <text x="830" y="182" text-anchor="middle" font-size="34" font-weight="bold">BÜHNE</text>
    <path d="M 640 290 Q 830 460 1020 290" fill="none" stroke="#94a3b8" stroke-width="3" stroke-dasharray="10 8"/>
    <text x="830" y="340" text-anchor="middle">Publikum</text>
    <rect x="380" y="620" width="300" height="200" fill="#fde68a" stroke="#b45309" stroke-width="3"/>
    <text x="530" y="728" text-anchor="middle" font-weight="bold">CATERING</text>
    <rect x="1040" y="620" width="400" height="260" fill="#e9d5ff" stroke="#7c3aed" stroke-width="3"/>
    <text x="1240" y="760" text-anchor="middle" font-weight="bold">BACKSTAGE</text>
    <rect x="1300" y="140" width="220" height="180" fill="#fed7aa" stroke="#c2410c" stroke-width="3"/>
    <text x="1410" y="238" text-anchor="middle">Technikfläche</text>
    <rect x="60" y="60" width="200" height="140" fill="#bae6fd" stroke="#0369a1" stroke-width="3"/>
    <text x="160" y="138" text-anchor="middle">Netzanschluss</text>
    <text x="80" y="540" font-size="22">Hauptweg / Feuerwehrzufahrt</text>
    <g fill="#fecdd3" stroke="#be123c" stroke-width="2">
      <rect x="1030" y="386" width="52" height="28" rx="4"/><rect x="1094" y="428" width="52" height="28" rx="4"/>
      <rect x="1254" y="428" width="52" height="28" rx="4"/><rect x="1334" y="340" width="52" height="28" rx="4"/>
      <rect x="1410" y="446" width="60" height="28" rx="4"/>
    </g>
    <text x="1250" y="552" text-anchor="middle" font-size="20" fill="#be123c">Foodtruck-Meile</text>
    <g stroke="#1e293b" stroke-width="4"><line x1="60" y1="950" x2="460" y2="950"/><line x1="60" y1="935" x2="60" y2="965"/><line x1="460" y1="935" x2="460" y2="965"/></g>
    <text x="260" y="935" text-anchor="middle" font-size="22" fill="#1e293b">100 m</text>
  </g>
</svg>
SVG;
    }
}
