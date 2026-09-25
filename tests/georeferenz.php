<?php
declare(strict_types=1);

/**
 * Georeferenz: Rechnung Plan ↔ Welt, Koordinaten lesen, Speichern, Längen
 * aus der Georeferenz, GeoJSON-Daten und Location-Abgleich (strom.json).
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\LeitungService;
use App\Services\LocationBundle;
use App\Services\PlanGeoService;
use App\Services\PlanMassstabService;
use App\Services\SiteService;
use App\Support\Database;

// ------------------------------------------------ Koordinaten lesen
$lesen = static fn (string $t): ?array => PlanGeoService::koordinatenAusText($t);
gleich($lesen('51.23456, 7.12345'), ['lat' => 51.23456, 'lon' => 7.12345], 'Dezimal mit Punkt und Komma');
gleich($lesen('51.23456 7.12345'), ['lat' => 51.23456, 'lon' => 7.12345], 'Dezimal mit Leerzeichen');
gleich($lesen('51,23456; 7,12345'), ['lat' => 51.23456, 'lon' => 7.12345], 'deutsche Schreibweise mit Semikolon');
gleich($lesen('51,5 7,25'), ['lat' => 51.5, 'lon' => 7.25], 'deutsche Schreibweise mit Leerzeichen');
gleich($lesen('-33.8568, 151.2153'), ['lat' => -33.8568, 'lon' => 151.2153], 'Südhalbkugel');
$dms = $lesen('51°14\'04.2"N 7°07\'24.2"E');
nahe($dms['lat'] ?? null, 51.234500, 0.00001, 'Grad/Minuten/Sekunden: Breite');
nahe($dms['lon'] ?? null, 7.123389, 0.00001, 'Grad/Minuten/Sekunden: Länge');
$dmsVertauscht = $lesen('7°07′24.2″O, 51°14′04.2″N');
nahe($dmsVertauscht['lat'] ?? null, 51.2345, 0.00001, 'Grad mit O(st) und in anderer Reihenfolge');
gleich($lesen('https://www.openstreetmap.org/?mlat=51.44&mlon=7.56#map=19/51.44/7.56'), ['lat' => 51.44, 'lon' => 7.56], 'OpenStreetMap-Link');
gleich($lesen('https://www.google.com/maps/@51.4401,7.5602,17z'), ['lat' => 51.4401, 'lon' => 7.5602], 'Google-Maps-Link');
gleich($lesen('geo:51.44,7.56'), ['lat' => 51.44, 'lon' => 7.56], 'geo:-Adresse');
gleich($lesen('91.0, 7.0'), null, 'Breite über 90° abgelehnt');
gleich($lesen('Hauptweg'), null, 'Text ohne Zahlen abgelehnt');
gleich($lesen('51,2,7,1'), null, 'mehrdeutige Kommas abgelehnt');

// ------------------------------------------------ Rechnen
nahe(PlanGeoService::entfernung(51.44, 7.56, 51.44, 7.56), 0.0, 0.001, 'Entfernung zu sich selbst');
nahe(PlanGeoService::entfernung(0.0, 0.0, 1.0, 0.0), 111195.0, 5.0, 'ein Grad Breite ≈ 111,2 km');

// Quadratischer Plan, 1 % = 2 m, Plan-oben = Norden: A unten links, B 100 m östlich
$nordOben = ['a' => ['x' => 10.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56], 'verhaeltnis' => 1.0];
$kx = 6371008.8 * M_PI / 180 * cos(deg2rad(51.44));
$nordOben['b'] = ['x' => 60.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56 + 100 / $kx];
nahe(PlanGeoService::drehung($nordOben), 0.0, 0.01, 'Plan nach Norden ausgerichtet: 0°');
$oben = PlanGeoService::zuGeo($nordOben, 10.0, 40.0);
nahe(PlanGeoService::entfernung(51.44, 7.56, $oben['lat'], $oben['lon']), 100.0, 0.05, '50 % nach oben = 100 m');
pruefe($oben['lat'] > 51.44 && abs($oben['lon'] - 7.56) < 1e-7, 'nach oben im Plan = nach Norden');
$zurueck = PlanGeoService::zuPlan($nordOben, $oben['lat'], $oben['lon']);
nahe($zurueck['x'], 10.0, 0.005, 'zurück: x (auf 1 cm)');
nahe($zurueck['y'], 40.0, 0.005, 'zurück: y (auf 1 cm)');

// Gedreht und nicht quadratisch: Hin und zurück stimmt überall
$gedreht = ['a' => ['x' => 3.75, 'y' => 95.0, 'lat' => 51.44, 'lon' => 7.56],
    'b' => ['x' => 28.75, 'y' => 95.0, 'lat' => 51.439813, 'lon' => 7.5614112], 'verhaeltnis' => 0.625];
nahe(PlanGeoService::drehung($gedreht), 12.0, 0.1, 'gedrehter Plan: 12°');
foreach ([[0, 0], [100, 100], [50, 20], [88, 18]] as [$x, $y]) {
    $g = PlanGeoService::zuGeo($gedreht, (float) $x, (float) $y);
    $p = PlanGeoService::zuPlan($gedreht, $g['lat'], $g['lon']);
    pruefe(abs($p['x'] - $x) < 0.002 && abs($p['y'] - $y) < 0.002, "Hin und zurück bei ({$x}|{$y})");
}
// Längen: 25 % Breite = 100 m, also die ganze Breite 400 m, die Höhe 250 m
$lo = PlanGeoService::zuGeo($gedreht, 0.0, 0.0);
$ro = PlanGeoService::zuGeo($gedreht, 100.0, 0.0);
$lu = PlanGeoService::zuGeo($gedreht, 0.0, 100.0);
nahe(PlanGeoService::entfernung($lo['lat'], $lo['lon'], $ro['lat'], $ro['lon']), 400.0, 0.2, 'Planbreite 400 m');
nahe(PlanGeoService::entfernung($lo['lat'], $lo['lon'], $lu['lat'], $lu['lon']), 250.0, 0.2, 'Planhöhe 250 m (Seitenverhältnis)');

// ------------------------------------------------ Speichern und Längen
$kennung = 'pm-geo-' . bin2hex(random_bytes(3));
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Georeferenz ' . $kennung]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Wiese']);
$angelegt = [];
try {
    $a = $nordOben['a'];
    $b = $nordOben['b'];
    pruefe(!PlanGeoService::speichern($floorId, ['x' => 120.0] + $a, $b, 1.0)['ok'], 'Passpunkt außerhalb des Plans abgelehnt');
    pruefe(!PlanGeoService::speichern($floorId, $a, ['lat' => 51.44, 'lon' => 7.56] + $b, 1.0)['ok'], 'gleiche Koordinaten abgelehnt');
    pruefe(!PlanGeoService::speichern($floorId, $a, ['lat' => 52.44] + $b, 1.0)['ok'], 'über 50 km abgelehnt');
    pruefe(!PlanGeoService::speichern($floorId, $a, ['x' => 10.2, 'y' => 90.0] + $b, 1.0)['ok'], 'Planpunkte zu nah abgelehnt');
    pruefe(!PlanGeoService::speichern($floorId, $a, $b, 0.0)['ok'], 'ohne Seitenverhältnis abgelehnt');
    gleich(PlanMassstabService::fuerEtage($floorId), null, 'ohne Maßstab und Georeferenz keine Länge');

    $e = PlanGeoService::speichern($floorId, $a, $b, 1.0);
    pruefe($e['ok'] && str_contains($e['message'], 'Kabellängen kommen jetzt aus der Georeferenz'), 'Georeferenz gespeichert: ' . $e['message']);
    $m = PlanMassstabService::fuerEtage($floorId);
    pruefe($m !== null && ($m['quelle'] ?? '') === 'georeferenz', 'wirksamer Maßstab kommt aus der Georeferenz');
    gleich(PlanMassstabService::lesen(SiteService::floor($floorId)), null, 'gesetzt ist trotzdem kein Maßstab');

    $v = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' V', 'floor_id' => $floorId, 'plan_x' => 60.0, 'plan_y' => 40.0])['id'];
    $angelegt[] = $v;
    $lage = PlanGeoService::fuerObjekt(AnlagenService::find($v));
    pruefe($lage !== null, 'Anlage hat Koordinaten');
    nahe(PlanGeoService::entfernung(51.44, 7.56, $lage['lat'], $lage['lon']), hypot(100.0, 100.0), 0.1, 'Anlage liegt 100 m östlich und 100 m nördlich von A');
    pruefe(str_contains(PlanGeoService::kartenLink($lage['lat'], $lage['lon']), 'mlat=' . number_format($lage['lat'], 6, '.', '')), 'Kartenlink mit Koordinaten');

    $l = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'name' => $kennung, 'von_objekt_id' => $v,
        'kabeltyp' => 'H07RN-F', 'adern' => 5, 'querschnitt_mm2' => 6, 'punkte' => [[60.0, 40.0], [60.0, 90.0], [10.0, 90.0]]]);
    pruefe($l['ok'], 'Kabel angelegt');
    nahe((float) LeitungService::find((int) $l['id'])['laenge'], 200.0, 0.2, 'Kabellänge aus der Georeferenz: 200 m');

    // Ein gesetzter Maßstab hat Vorrang – und eine Abweichung fällt auf
    PlanMassstabService::speichern($floorId, ['x' => 10.0, 'y' => 90.0], ['x' => 60.0, 'y' => 90.0], 120.0, 1.0);
    nahe((float) LeitungService::find((int) $l['id'])['laenge'], 240.0, 0.2, 'gesetzter Maßstab hat Vorrang');
    $e = PlanGeoService::speichern($floorId, $a, $b, 1.0);
    pruefe($e['ok'] && str_contains($e['message'], 'weicht um 17 %'), 'Abweichung zwischen Maßstab und Georeferenz gemeldet: ' . $e['message']);
    PlanMassstabService::entfernen($floorId);
    nahe((float) LeitungService::find((int) $l['id'])['laenge'], 200.0, 0.2, 'ohne Maßstab wieder aus der Georeferenz');

    // ------------------------------------------------ Location-Abgleich über strom.json
    $strom = LocationBundle::stromLesen($siteId);
    $etageKey = (string) Database::scalar('SELECT sync_key FROM floors WHERE id = ?', [$floorId]);
    $daten = json_decode((string) json_encode($strom), true);
    pruefe(isset($daten['georeferenz'][$etageKey]['a']['lat']), 'strom.json enthält die Georeferenz der Etage');
    pruefe(!isset($daten['georeferenz'][$etageKey]['meter']), 'nur Passpunkte, keine abgeleiteten Werte');
    PlanGeoService::entfernen($floorId);
    $erg = LocationBundle::stromAnwenden($siteId, $daten);
    pruefe($erg['ok'], 'strom.json angewendet');
    $zurueck = PlanGeoService::lesen(SiteService::floor($floorId));
    pruefe($zurueck !== null && abs($zurueck['b']['lon'] - $b['lon']) < 1e-6, 'Georeferenz aus strom.json übernommen');
    unset($daten['georeferenz']);
    LocationBundle::stromAnwenden($siteId, $daten);
    pruefe(PlanGeoService::lesen(SiteService::floor($floorId)) !== null, 'ältere strom.json ohne Georeferenz lässt die hiesige stehen');
    $daten['georeferenz'] = [];
    LocationBundle::stromAnwenden($siteId, $daten);
    gleich(PlanGeoService::lesen(SiteService::floor($floorId)), null, 'strom.json ohne Georeferenz für die Etage entfernt sie');
    gleich(json_encode(LocationBundle::stromLesen($siteId)['georeferenz']), '{}', 'leere Georeferenz als Objekt');
} finally {
    Database::execute('DELETE FROM leitungen WHERE name = ?', [$kennung]);
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    SiteService::deleteSite($siteId);
    Database::execute("DELETE FROM audit_log WHERE action LIKE 'plan.%' AND target_type = 'floor' AND target_id = ?", [(string) $floorId]);
}

ende('georeferenz');
