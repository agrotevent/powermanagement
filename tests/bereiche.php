<?php
declare(strict_types=1);

/**
 * Bereiche (Summe ohne Doppelzählung) und Materialliste (Stückelung).
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\BereichService;
use App\Services\LeitungService;
use App\Services\MaterialService;
use App\Services\MesspunktService;
use App\Support\Config;
use App\Support\Database;
use App\Services\SiteService;

// ------------------------------------------------ Stückelung (reine Rechnung)
gleich(MaterialService::stueckeln(26, false), [25, 5], '26 m → 25 + 5');
gleich(MaterialService::stueckeln(50, false), [50], '50 m → ein Stück');
gleich(MaterialService::stueckeln(62, false), [50, 20], '62 m → 50 + 20 (lieber etwas länger als drei Stücke)');
gleich(MaterialService::stueckeln(90, false), [50, 50], '90 m → 2 × 50');
gleich(MaterialService::stueckeln(3, false), [5], 'kurzes Kabel → 5 m');
gleich(MaterialService::stueckeln(176, false), [50, 50, 50, 25, 5], '176 m normal → ohne 75/100 m');
gleich(MaterialService::stueckeln(176, true), [100, 75, 5], '176 m schwer → 100 + 75 + 5 (spart zwei Stücke)');
gleich(MaterialService::stueckeln(200, true), [100, 100], '200 m schwer → 2 × 100');
gleich(MaterialService::stueckeln(130, true), [50, 50, 25, 5], '130 m schwer → 100 + 25 + 5 spart nur ein Stück, also Standard');
gleich(MaterialService::stueckeln(143, true), [50, 50, 50], '143 m schwer → 3 × 50');
foreach ([7, 18, 33, 47, 81, 112, 149, 233, 310] as $m) {
    $s = MaterialService::stueckeln($m, false);
    pruefe(array_sum($s) >= $m && array_diff($s, MaterialService::LAENGEN) === [], "{$m} m: nur übliche Längen, reicht (" . implode('+', $s) . ')');
    $t = MaterialService::stueckeln($m, true);
    pruefe(array_sum($t) >= $m && count($t) <= count($s), "{$m} m schwer: reicht, nie mehr Stücke als normal");
}
pruefe(MaterialService::schwer(63, 'CEE 63 A', 16, 'H07RN-F'), 'CEE 63 A ist schwer');
pruefe(MaterialService::schwer(125, 'CEE 125 A', 35, 'H07RN-F'), 'CEE 125 A ist schwer');
pruefe(MaterialService::schwer(400, 'Powerlock', 95, 'Powerlock-Einleiter'), 'Powerlock ist schwer');
pruefe(!MaterialService::schwer(32, 'CEE 32 A', 16, 'H07RN-F'), 'CEE 32 A mit 16 mm² bleibt normal');
pruefe(MaterialService::schwer(null, null, 35, 'NYY-J'), 'ohne Stecker: ab 16 mm² schwer');
pruefe(!MaterialService::schwer(null, null, 6, 'H07RN-F'), 'ohne Stecker: 6 mm² normal');

// ------------------------------------------------ Materialliste aus Anlagen und Leitungen
$reserve = MaterialService::reserve();
$leitung = static fn (array $d): array => $d + [
    'art' => 'kabel', 'fest' => 0, 'name' => '', 'von_name' => 'A', 'zu_name' => 'B', 'von_typ' => null, 'zu_typ' => null,
    'von_strom_a' => null, 'zu_strom_a' => null, 'querschnitt_mm2' => null, 'kabeltyp' => 'H07RN-F', 'adern' => 5,
    'laenge' => null, 'laenge_m' => null, 'anzahl' => 1, 'modell' => '',
];
$objekt = static fn (array $d): array => $d + ['art' => 'verteilung', 'modell' => 'PD 32/16', 'fest' => 0, 'name' => 'V'];
$liste = MaterialService::berechnen(
    [$objekt(['name' => 'V1']), $objekt(['name' => 'V2']), $objekt(['name' => 'ES', 'art' => 'anschluss', 'modell' => 'ES 32', 'fest' => 1])],
    [
        $leitung(['name' => 'k1', 'von_typ' => 'CEE 32 A', 'von_strom_a' => 32, 'querschnitt_mm2' => 6, 'laenge' => 40 / (1 + $reserve / 100)]),
        $leitung(['name' => 'k2', 'von_typ' => 'CEE 32 A', 'von_strom_a' => 32, 'querschnitt_mm2' => 6, 'laenge' => 12.0, 'anzahl' => 2]),
        $leitung(['name' => 'pl', 'von_typ' => 'Powerlock', 'von_strom_a' => 400, 'querschnitt_mm2' => 95, 'kabeltyp' => 'Powerlock-Einleiter', 'laenge' => 20.0]),
        $leitung(['name' => 'ohne', 'von_typ' => 'CEE 16 A', 'von_strom_a' => 16]),
        $leitung(['name' => 'erd', 'fest' => 1, 'laenge' => 80.0]),
        $leitung(['art' => 'kabelbruecke', 'modell' => '5-Kanal', 'anzahl' => 3]),
        $leitung(['art' => 'kabelbruecke', 'modell' => '5-Kanal', 'anzahl' => 2]),
        $leitung(['art' => 'kabelweg', 'laenge' => 300.0]),
    ]
);
gleich(count($liste['anlagen']), 1, 'mobile Anlagen nach Typ zusammengefasst');
gleich($liste['anlagen'][0]['anzahl'] ?? 0, 2, '2 × PD 32/16');
gleich(count($liste['vorhanden']), 1, 'Feststromanschluss steht unter „vor Ort vorhanden“');
gleich($liste['feste_kabel'], 1, 'fest verlegtes Kabel wird nicht mitgebracht');
gleich($liste['bruecken'], ['5-Kanal' => 5], 'Kabelbrücken: Elemente addiert');
gleich(count($liste['ohne_laenge']), 1, 'Kabel ohne Länge wird gemeldet');
$gruppen = array_column($liste['kabel'], null, 'titel');
$cee = $gruppen['CEE 32 A · H07RN-F 5G6 mm²'] ?? null;
pruefe($cee !== null, 'Gruppe nach Stecker, Typ und Querschnitt: ' . implode(' | ', array_keys($gruppen)));
gleich($cee['stuecke'] ?? null, [50 => 1, 20 => 2], '40 m mit Reserve → 50 m; 2 × 12 m parallel → 2 × 20 m');
gleich($cee['kabel'] ?? null, 3, 'parallel gelegte Kabel zählen einzeln');
$pl = $gruppen['Powerlock · Powerlock-Einleiter 95 mm²'] ?? null;
gleich($pl['stuecke'] ?? null, [25 => 5], 'Powerlock-Einleiter: je Leiter ein Stück (5 ×)');
gleich(array_key_first($gruppen), 'Powerlock · Powerlock-Einleiter 95 mm²', 'stärkste Kabel zuerst');

// ------------------------------------------------ Bereiche auf eigenem Standort
AnlagenService::grundbestand();
$typ = [];
foreach (AnlagenService::steckertypen() as $t) {
    $typ[(string) $t['schluessel']] = (int) $t['id'];
}
$kennung = 'pm-bereich-' . bin2hex(random_bytes(3));
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Bereiche ' . $kennung]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Fläche']);
// 100 m über die halbe Breite, quadratischer Plan: 1 % = 2 m
Database::update('floors', $floorId, ['massstab_ax' => 0.0, 'massstab_ay' => 50.0, 'massstab_bx' => 50.0, 'massstab_by' => 50.0,
    'massstab_m' => 100.0, 'massstab_verhaeltnis' => 1.0, 'massstab_von' => 'test', 'massstab_am' => gmdate('c')]);
$anderer = SiteService::saveSite(null, ['name' => 'Prüfung anderer ' . $kennung]);
$benutzer = BenutzerService::anlegen($kennung, 'Pruefung2026!!', 'admin', false);
$angelegt = [];

try {
    alsBenutzer($benutzer);
    $neu = static function (array $d) use ($floorId, &$angelegt): int {
        $id = (int) AnlagenService::speichern(null, $d + ['floor_id' => $floorId, 'plan_x' => 10.0, 'plan_y' => 10.0])['id'];
        $angelegt[] = $id;
        return $id;
    };
    $anschluss = static function (int $objekt, string $richtung, string $stecker, int $n = 0) use ($typ): int {
        return (int) Database::select('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr', [$objekt, $richtung, $typ[$stecker]])[$n]['id'];
    };
    $g = $neu(['art' => 'generator', 'name' => $kennung . ' Aggregat', 'nennleistung_kva' => 100, 'ohne_anschluesse' => true]);
    AnlagenService::anzahlSetzen($g, ['abgang' => [$typ['cee63'] => 2]]);
    $v1 = $neu(['art' => 'verteilung', 'name' => $kennung . ' V1', 'ohne_anschluesse' => true, 'modell' => 'PD 63']);
    AnlagenService::anzahlSetzen($v1, ['eingang' => [$typ['cee63'] => 1], 'abgang' => [$typ['cee32'] => 1, $typ['schuko'] => 2]]);
    $v2 = $neu(['art' => 'verteilung', 'name' => $kennung . ' V2', 'ohne_anschluesse' => true, 'modell' => 'PD 32']);
    AnlagenService::anzahlSetzen($v2, ['eingang' => [$typ['cee32'] => 1], 'abgang' => [$typ['schuko'] => 2]]);
    $w = $neu(['art' => 'verteilung', 'name' => $kennung . ' Licht', 'ohne_anschluesse' => true, 'modell' => 'PD 63']);
    AnlagenService::anzahlSetzen($w, ['eingang' => [$typ['cee63'] => 1], 'abgang' => [$typ['schuko'] => 2]]);
    $fremd = $neu(['art' => 'verteilung', 'name' => $kennung . ' fremd', 'site_id' => $anderer, 'floor_id' => null]);

    $kabel = static function (int $von, int $vonA, int $zu, int $zuA, array $punkte, float $q = 16) use ($floorId, $kennung): int {
        $e = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'name' => $kennung, 'von_objekt_id' => $von, 'von_anschluss_id' => $vonA,
            'zu_objekt_id' => $zu, 'zu_anschluss_id' => $zuA, 'kabeltyp' => 'H07RN-F', 'adern' => 5, 'querschnitt_mm2' => $q, 'punkte' => $punkte]);
        pruefe($e['ok'], 'Kabel angelegt: ' . $e['message']);
        return (int) ($e['id'] ?? 0);
    };
    // Aggregat → V1 (Abgang 1), V1 → V2, Aggregat → Licht (Abgang 2)
    $gAb1 = $anschluss($g, 'abgang', 'cee63');
    $kabel($g, $gAb1, $v1, $anschluss($v1, 'eingang', 'cee63'), [[10.0, 10.0], [10.0, 40.0]]);                  // 60 m
    $kabel($v1, $anschluss($v1, 'abgang', 'cee32'), $v2, $anschluss($v2, 'eingang', 'cee32'), [[10.0, 40.0], [20.0, 40.0]], 6); // 20 m
    $kabel($g, $anschluss($g, 'abgang', 'cee63', 1), $w, $anschluss($w, 'eingang', 'cee63'), [[10.0, 10.0], [60.0, 10.0]]);     // 100 m
    // Kabelbrücke über dem Kabel zu V1 – und eine weit weg
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'name' => $kennung, 'modell' => 'Midi', 'anzahl' => 2, 'punkte' => [[9.0, 25.0], [11.0, 25.0]]]);
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'name' => $kennung, 'modell' => 'Maxi', 'anzahl' => 7, 'punkte' => [[80.0, 80.0], [82.0, 80.0]]]);

    $mp = static function (int $objekt, string $geraet, string $kanal, string $rolle, ?int $anschluss = null): int {
        $e = MesspunktService::speichern($objekt, null, ['geraet' => $geraet, 'kanal' => $kanal, 'rolle' => $rolle, 'bezeichnung' => '', 'anschluss_id' => $anschluss]);
        return (int) $e['id'];
    };
    $mV1 = $mp($v1, 'shellypro3em-foodtruck', 'em:0', 'einspeisung');
    $mV2 = $mp($v2, 'shellypro3em-catering', 'em:0', 'einspeisung');
    $kaffeeAbgang = $anschluss($v1, 'abgang', 'schuko');
    Database::update('anschluesse', $kaffeeAbgang, ['phase' => 'L2']);
    $mKaffee = $mp($v1, 'shellypro1pm-kaffee', 'switch:0', 'abgang', $kaffeeAbgang);
    $mGAb1 = $mp($g, 'shellypro3em-grilltruck', 'em:0', 'abgang', $gAb1);
    $mG = $mp($g, 'ig200-aggregat1', 'generator', 'erzeugung');
    $mFremd = $mp($fremd, 'shellypro3em-buehne', 'em:0', 'einspeisung');

    $bereich = static function (array $mitglieder, array $mehr = []) use ($siteId, $kennung): array {
        $e = BereichService::speichern(null, $mehr + ['site_id' => $siteId, 'name' => $kennung . ' ' . implode(',', $mitglieder), 'mitglieder' => $mitglieder]);
        pruefe($e['ok'], 'Bereich gespeichert: ' . $e['message']);
        return BereichService::find((int) $e['id']);
    };
    $zaehlt = static function (array $b): array {
        $out = [];
        foreach (BereichService::aufbau($b) as $e) {
            $out[$e['schluessel']] = $e['enthalten_in'] === null;
        }
        return $out;
    };

    // Speichern: Name und Standort Pflicht, fremde Mitglieder fallen weg
    pruefe(!BereichService::speichern(null, ['site_id' => $siteId, 'name' => ''])['ok'], 'Bereich ohne Namen abgelehnt');
    pruefe(!BereichService::speichern(null, ['site_id' => 0, 'name' => $kennung])['ok'], 'Bereich ohne Standort abgelehnt');
    $b = $bereich(['o:' . $v1, 'o:' . $fremd, 'm:' . $mFremd, 'x:1', 'o:' . $v1], ['farbe' => 'rot', 'grenze_kw' => '12,5', 'grenze_a' => '-3']);
    gleich(BereichService::mitgliedSchluessel((int) $b['id']), ['o:' . $v1], 'nur Mitglieder dieses Standorts, keine doppelten');
    gleich((float) $b['grenze_kw'], 12.5, 'Grenze mit Komma gelesen');
    gleich($b['grenze_a'], null, 'negative Grenze → keine');
    gleich($b['farbe'], '', 'ungültige Farbe → leer');

    // Doppelzählung
    gleich($zaehlt($bereich(['o:' . $v1, 'o:' . $v2])), ['o:' . $v1 => true, 'o:' . $v2 => false], 'V2 hängt hinter V1 → zählt nicht doppelt');
    gleich($zaehlt($bereich(['o:' . $v2, 'o:' . $v1])), ['o:' . $v2 => false, 'o:' . $v1 => true], 'Reihenfolge egal: V2 steckt in V1');
    gleich($zaehlt($bereich(['o:' . $v1, 'm:' . $mKaffee])), ['o:' . $v1 => true, 'm:' . $mKaffee => false], 'Abgang einer Mitglieds-Verteilung zählt nicht doppelt');
    gleich($zaehlt($bereich(['m:' . $mGAb1, 'o:' . $v1, 'o:' . $v2])), ['m:' . $mGAb1 => true, 'o:' . $v1 => false, 'o:' . $v2 => false],
        'gemessener Aggregat-Abgang enthält die Verteilung dahinter');
    gleich($zaehlt($bereich(['o:' . $v1, 'm:' . $mV1])), ['o:' . $v1 => true, 'm:' . $mV1 => false], 'Anlage und ihr Einspeise-Messgerät: die Anlage zählt');
    gleich($zaehlt($bereich(['o:' . $g, 'o:' . $v1])), ['o:' . $g => true, 'o:' . $v1 => false], 'ganzes Aggregat enthält alles dahinter');
    gleich($zaehlt($bereich(['m:' . $mGAb1, 'o:' . $w])), ['m:' . $mGAb1 => true, 'o:' . $w => true], 'Licht am anderen Abgang zählt eigenständig');

    // Aufräumen beim Löschen eines Messgeräts
    $b2 = $bereich(['o:' . $v1, 'm:' . $mV2]);
    MesspunktService::loeschen($v2, $mV2);
    gleich(BereichService::mitgliedSchluessel((int) $b2['id']), ['o:' . $v1], 'gelöschtes Messgerät verschwindet aus dem Bereich');
    $mV2 = $mp($v2, 'shellypro3em-catering', 'em:0', 'einspeisung');

    // Ein Tank zählt in keinem Bereich – weder gewählt noch als Mitglied aus älterer Zeit
    $tank = $neu(['art' => 'tank', 'name' => $kennung . ' Tank', 'eigenschaften' => ['inhalt_l' => '1000']]);
    $mTank = Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => 'shellypro3em-buehne', 'kanal' => 'em:0', 'rolle' => 'einspeisung',
        'bezeichnung' => '', 'gueltig_ab' => gmdate('c'), 'created_at' => gmdate('c')]);
    $bt = $bereich(['o:' . $v1, 'o:' . $tank, 'm:' . $mTank]);
    gleich(BereichService::mitgliedSchluessel((int) $bt['id']), ['o:' . $v1], 'Tank und sein Messgerät werden kein Mitglied');
    Database::insert('bereich_mitglieder', ['bereich_id' => (int) $bt['id'], 'objekt_id' => $tank, 'messpunkt_id' => null, 'created_at' => gmdate('c')]);
    gleich($zaehlt($bt), ['o:' . $v1 => true], 'ein Tank als Mitglied zählt nicht mit');
    if (Config::isDemo()) {
        gleich(array_keys(BereichService::live($bt)['mitglieder']), ['o:' . $v1], 'Livewert des Bereichs ohne den Tank');
    }

    // Livewerte und Verläufe (Demo-Messwerte)
    if (Config::isDemo()) {
        $b = $bereich(['o:' . $v1, 'o:' . $v2, 'm:' . $mKaffee, 'm:' . $mGAb1], ['grenze_kw' => '200']);
        $aufbau = BereichService::aufbau($b);
        $live = BereichService::live($b, $aufbau);
        $summe = 0.0;
        foreach ($live['mitglieder'] as $schluessel => $m) {
            if ($m['zaehlt']) {
                $summe += (float) $m['p'];
            }
        }
        pruefe($live['p'] !== null && $live['p'] > 0, 'Livewert des Bereichs: ' . Messdaten::format($live['p'], 'W'));
        nahe($live['p'], $summe, 1.0, 'Summe = zählende Mitglieder (Aggregat-Abgang enthält V1 und V2)');
        gleich(array_keys(array_filter($live['mitglieder'], static fn ($m) => $m['zaehlt'])), ['m:' . $mGAb1], 'nur der Aggregat-Abgang zählt');
        nahe((float) $live['auslastung'], $live['p'] / 2000, 0.2, 'Auslastung gegen die Grenze in kW');
        pruefe(str_contains($live['hinweis'], 'zählen nicht doppelt'), 'Hinweis nennt die nicht doppelt gezählten');

        $nurKaffee = BereichService::live($bereich(['m:' . $mKaffee]));
        gleich(array_keys($nurKaffee['i']), ['L2'], 'einphasiges Messgerät zählt auf der Phase seines Abgangs');

        $b = $bereich(['o:' . $v1, 'o:' . $w]);
        $aufbau = BereichService::aufbau($b);
        $von = time() - 3 * 3600;
        $bis = time();
        $reihe = BereichService::reihe($b, 'p', $von, $bis, $aufbau);
        $schritt = $reihe['schritt'];
        $a = Messdaten::reihe('strom', 'shellypro3em-foodtruck', 'em:0', 'p', ['sum'], $von, $bis, null, $schritt);
        pruefe(count($reihe['zeiten']) > 10, 'Summenverlauf hat Punkte: ' . count($reihe['zeiten']));
        $k = (int) floor(count($reihe['zeiten']) / 2);
        $ms = $reihe['zeiten'][$k];
        $ia = array_search($ms, $a['zeiten'], true);
        pruefe($ia !== false, 'gleiche Zeitraster');
        // Licht hat kein Messgerät → die Summe ist die von V1
        nahe((float) $reihe['serien']['sum'][$k], (float) $a['serien']['sum'][$ia], 0.5, 'Summe zeitgleich addiert');
        $kz = BereichService::kennzahlen($b, $von, $bis, $aufbau);
        nahe((float) $kz['p_max'], max(array_filter($reihe['serien']['sum'], static fn ($v) => $v !== null)), 0.01, 'Spitze = höchste gleichzeitige Last');
        pruefe(str_contains(BereichService::live($b, $aufbau)['hinweis'], 'ohne Messgerät'), 'Mitglied ohne Messgerät wird gemeldet');
    } else {
        echo "  (Livewerte übersprungen: kein Demo-Modus)\n";
    }

    // Materialliste des Bereichs: Anlagen, Kabel daran, Brücke darüber
    $b = $bereich(['o:' . $v1, 'o:' . $v2]);
    $mat = MaterialService::fuerBereich($b);
    gleich(array_sum(array_column($mat['anlagen'], 'anzahl')), 2, 'zwei Verteilungen');
    $einzeln = array_column($mat['kabel_einzeln'], 'benoetigt', 'laenge');
    gleich(count($mat['kabel_einzeln']), 2, 'Zuleitung vom Aggregat und Kabel V1 → V2 (nicht das Licht-Kabel)');
    pruefe(isset($einzeln['60']) && isset($einzeln['20']), 'Längen aus dem Maßstab: ' . implode(', ', array_keys($einzeln)));
    gleich($mat['bruecken'], ['Midi' => 2], 'nur die Kabelbrücke über einem Kabel des Bereichs');
    $etage = MaterialService::fuerEtage($floorId);
    gleich(count($etage['kabel_einzeln']), 3, 'Etage: alle drei Kabel');
    gleich($etage['bruecken'], ['Maxi' => 7, 'Midi' => 2], 'Etage: alle Kabelbrücken');

    // Aufräumen beim Löschen einer Anlage
    AnlagenService::loeschen($v2);
    $angelegt = array_values(array_diff($angelegt, [$v2]));
    gleich(BereichService::mitgliedSchluessel((int) $b['id']), ['o:' . $v1], 'gelöschte Anlage verschwindet aus dem Bereich');

    $e = BereichService::loeschen((int) $b['id']);
    pruefe($e['ok'] && BereichService::find((int) $b['id']) === null, 'Bereich gelöscht');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM bereich_mitglieder WHERE bereich_id = ?', [(int) $b['id']]), 0, 'Mitglieder mit gelöscht');
} finally {
    alsBenutzer(null);
    Database::execute('DELETE FROM bereich_mitglieder WHERE bereich_id IN (SELECT id FROM bereiche WHERE name LIKE ?)', [$kennung . '%']);
    Database::execute('DELETE FROM bereiche WHERE name LIKE ?', [$kennung . '%']);
    Database::execute('DELETE FROM leitungen WHERE name = ?', [$kennung]);
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    SiteService::deleteSite($siteId);
    SiteService::deleteSite($anderer);
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$benutzer]);
    Database::execute('DELETE FROM users WHERE id = ?', [$benutzer]);
    Database::execute('DELETE FROM audit_log WHERE username = ? OR details LIKE ?', [$kennung, '%' . $kennung . '%']);
}

ende('bereiche');
