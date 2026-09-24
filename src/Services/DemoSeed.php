<?php
declare(strict_types=1);

namespace App\Services;

use App\Messung\Datenquellen;
use App\Support\Config;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Ein Demo-Festivalgelände: Netzanschluss, Aggregat, Speicher, Verteilungen
 * mit Sicherungen und RCDs, Kabel, Kabelbrücken, Messgeräte, Benutzer mit
 * zeitlich begrenzten Freigaben.
 *
 * Grundlage der Screenshots und der Prüfskripte. Die Messwerte dazu
 * erzeugt App\Messung\Demo.
 */
final class DemoSeed
{
    public static function anlegen(bool $zuruecksetzen): string
    {
        if ($zuruecksetzen) {
            foreach (['leitungen', 'messpunkte', 'anschluesse', 'schutzorgane', 'objekte', 'datenquellen', 'freigaben', 'floors', 'sites', 'schaltvorgaenge'] as $tabelle) {
                Database::execute('DELETE FROM ' . $tabelle);
            }
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
        $vorlage('SV 16 – Schukoverteiler', 'SV 16', ['cee16' => 1], ['schuko' => 6], 16);

        $setzen = static function (array $daten, float $x, float $y) use ($siteId, $floorId): int {
            return (int) AnlagenService::speichern(null, $daten + ['site_id' => $siteId, 'floor_id' => $floorId, 'plan_x' => $x, 'plan_y' => $y, 'status' => 'in_betrieb'])['id'];
        };

        // ---- Anlagen
        $netz = $setzen(['art' => 'anschluss', 'name' => 'Baustromanschluss Netz', 'kennung' => 'BA-01', 'nennstrom_a' => 250,
            'eigenschaften' => ['anschlussart' => 'Baustromverteiler', 'absicherung_a' => 250, 'netzbetreiber' => 'Stadtwerke Musterstadt'],
            'ohne_anschluesse' => true], 7.5, 12.0);
        AnlagenService::anzahlSetzen($netz, ['abgang' => [$typ['powerlock'] => 1, $typ['cee125'] => 1]]);

        $gen = $setzen(['art' => 'generator', 'name' => 'Aggregat 1', 'kennung' => 'G-100-07', 'hersteller' => 'Musteraggregate',
            'modell' => 'SG 100', 'nennleistung_kva' => 100, 'eigenschaften' => ['steuerung' => 'ComAp InteliGen 200', 'kraftstoff' => 'HVO', 'tank_l' => 400, 'verbrauch_l_h' => 22],
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
        LeitungService::speichern(null, ['art' => 'kabelweg', 'floor_id' => $floorId, 'name' => 'Trasse Ost (Reserve)', 'punkte' => [[45.0, 38.0], [80.0, 38.0], [88.0, 30.0]]]);
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
        Database::update('users', $technik, ['full_name' => 'Elektrik vor Ort']);
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

        return 'Demo-Gelände angelegt: ' . (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 0') . ' Anlagen, '
            . (int) Database::scalar('SELECT COUNT(*) FROM leitungen') . ' Leitungen, '
            . (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1') . ' Standard-Verteiler. '
            . 'Zugänge: veranstalter / Veranstalter2026! (nur Festivalwoche), technik / Technik2026!! (schaltet nur das Licht).'
            . (Config::isDemo() ? '' : ' Hinweis: app.demo ist aus – ohne InfluxDB bleiben die Messwerte leer.');
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
    <g stroke="#1e293b" stroke-width="4"><line x1="60" y1="950" x2="460" y2="950"/><line x1="60" y1="935" x2="60" y2="965"/><line x1="460" y1="935" x2="460" y2="965"/></g>
    <text x="260" y="935" text-anchor="middle" font-size="22" fill="#1e293b">100 m</text>
  </g>
</svg>
SVG;
    }
}
