<?php
declare(strict_types=1);

/**
 * InfluxDB: schreiben und mit InfluxQL lesen – Livewerte, Verläufe im
 * Datenfenster, Energie je Tag aus dem Zählerstand, Geräteliste, Herzschlag.
 * Tanksensoren: letzter Rohwert ab Einbau mit echter Zeit, Sensorfehler
 * (auch wieder weg), Lebenszeichen, Median, Analogkanäle in der Geräteliste
 * nach Tagen ohne Meldung, kein Livewert außerhalb des Datenfensters,
 * Verbrauch je Tag aus seltenen Meldungen (nur bei Änderung).
 *
 * Startet eine eigene InfluxDB 1.x auf freien Ports (influxd aus dem
 * Paket „influxdb“), damit die Prüfung nicht an einer eingerichteten
 * Datenbank hängt. InfluxDB 2.x spricht über /query und /write dasselbe.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Influx;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\LiveService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Database;
use App\Support\Zeit;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

$influxd = \App\Support\Programm::finden('influxd', ['version']) ?? (is_file('/usr/bin/influxd') ? '/usr/bin/influxd' : null);
if ($influxd === null) {
    uebersprungen('influx', 'influxd ist nicht installiert');
}
$tmp = sys_get_temp_dir() . '/pm-influx-' . bin2hex(random_bytes(3));
mkdir($tmp . '/meta', 0775, true);
$port = freierPort();
$rpc = freierPort();
file_put_contents($tmp . '/influxdb.conf', <<<CONF
reporting-disabled = true
bind-address = "127.0.0.1:{$rpc}"
[meta]
  dir = "{$tmp}/meta"
[data]
  dir = "{$tmp}/data"
  wal-dir = "{$tmp}/wal"
  query-log-enabled = false
[http]
  bind-address = "127.0.0.1:{$port}"
  log-enabled = false
[monitor]
  store-enabled = false
CONF);
hintergrund([$influxd, '-config', $tmp . '/influxdb.conf']);
for ($i = 0; $i < 100 && !erreichbar('127.0.0.1', $port); $i++) {
    usleep(100_000);
}
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($tmp)));
if (!erreichbar('127.0.0.1', $port)) {
    uebersprungen('influx', 'eigene InfluxDB startet nicht');
}

Config::set('app.demo', false);
Config::set('influx.url', 'http://127.0.0.1:' . $port);
Config::set('influx.database', 'strom');
Config::set('influx.token', '');
Config::set('influx.username', '');
$fehler = null;
Influx::abfragen('CREATE DATABASE "strom"', $fehler);
pruefe($fehler === null, 'Datenbank angelegt');

// ---- Testdaten: 3 Tage, je 10 Minuten, Drehstrom mit Zählerstand
$jetzt = time();
$start = $jetzt - 3 * 86400;
$zeilen = [];
$e = 1_000_000.0;
for ($t = $start; $t <= $jetzt - 60; $t += 600) {
    $p = 6000.0;                      // 6 kW Dauerlast
    $e += $p * 600 / 3600;
    $ms = $t * 1000;
    foreach (['L1' => 9.0, 'L2' => 8.5, 'L3' => 8.0] as $l => $i) {
        $zeilen = array_merge($zeilen, Influx::zeilen('pruef-3em', 'em:0', ['ts' => $ms, 'typ' => 'shelly', 'phasen' => [$l => ['u' => 230, 'i' => $i, 'p' => $p / 3]]]));
    }
    $zeilen = array_merge($zeilen, Influx::zeilen('pruef-3em', 'em:0', ['ts' => $ms, 'typ' => 'shelly', 'phasen' => ['sum' => ['p' => $p, 'e' => $e]]]));
}
$zeilen = array_merge($zeilen, Influx::zeilen('pruef-3em', 'em:0', ['ts' => ($jetzt - 5) * 1000, 'typ' => 'shelly', 'phasen' => ['sum' => ['p' => 7000]], 'zustand' => ['temperatur' => 41.5]]));
$zeilen[] = 'geraet,geraet=pruef-3em,typ=shelly modell="SPEM-003CEBEU",name="Prüfzähler",praefix="shellypro3em-pruef",generation=2.0 ' . ($jetzt * 1000);
$zeilen[] = 'pipeline,quelle=nodered geschrieben=100.0,fehler=0.0,puffer=0.0,verworfen=0.0 ' . (($jetzt - 10) * 1000);
pruefe(Influx::schreiben($zeilen, $fehler), 'Zeilen geschrieben ' . (string) $fehler);

$p = Influx::pruefen();
pruefe($p['ok'] && str_contains($p['message'], 'strom'), 'Prüfung: ' . $p['message']);

// ---- Livewerte
$letzte = Messdaten::letzte([['pruef-3em', 'em:0']], $fehler);
$w = $letzte['pruef-3em|em:0'] ?? null;
pruefe($w !== null, 'Livewerte gelesen ' . (string) $fehler);
gleich($w['phasen']['sum']['p'] ?? null, 7000.0, 'letzte Leistung');
gleich($w['phasen']['L1']['i'] ?? null, 9.0, 'letzter Strom L1');
gleich($w['zustand']['temperatur'] ?? null, 41.5, 'Zustand');
pruefe(abs((int) ($w['zeit'] ?? 0) - ($jetzt - 5) * 1000) < 2000, 'echte Zeit des letzten Werts');

// ---- Verlauf ohne und mit Datenfenster
$r = Messdaten::reihe('strom', 'pruef-3em', 'em:0', 'p', ['L1', 'L2', 'L3', 'sum'], $jetzt - 86400, $jetzt, null, 3600);
pruefe(count($r['zeiten']) >= 24, 'Stundenwerte über 24 h');
gleich(array_keys($r['serien']), ['L1', 'L2', 'L3', 'sum'], 'eine Serie je Phase');
nahe((float) $r['serien']['sum'][2], 6000.0, 0.01, 'Mittelwert Summenleistung');
$fenster = [[$jetzt - 6 * 3600, $jetzt - 3 * 3600]];
$r = Messdaten::reihe('strom', 'pruef-3em', 'em:0', 'p', ['sum'], $jetzt - 86400, $jetzt, $fenster, 600);
$zeiten = array_keys(array_filter(array_combine($r['zeiten'], $r['serien']['sum']), static fn ($v) => $v !== null));
pruefe($zeiten !== [] && min($zeiten) >= ($jetzt - 6 * 3600 - 600) * 1000 && max($zeiten) <= ($jetzt - 3 * 3600) * 1000, 'nur Werte im Datenfenster');
$leer = Messdaten::reihe('strom', 'pruef-3em', 'em:0', 'p', ['sum'], $jetzt - 86400, $jetzt, [], 600);
gleich($leer['zeiten'], [], 'ohne Freigabe keine Abfrage, keine Werte');

// ---- Energie je Tag aus dem Zählerstand
$tage = Messdaten::energieTage('pruef-3em', 'em:0', $start, $jetzt, null, $fehler);
pruefe($tage !== [], 'Energie je Tag ' . (string) $fehler);
$gestern = (new DateTimeImmutable('yesterday', Zeit::zone()))->format('Y-m-d');
nahe($tage[$gestern] ?? null, 6000 * 24 - 1000, 1100.0, 'ein voller Tag ≈ 144 kWh (6 kW × 24 h)');

// ---- Kennzahlen, Geräte, Herzschlag
$k = Messdaten::kennzahlen('pruef-3em', 'em:0', $jetzt - 86400, $jetzt, null);
pruefe(($k['p_max'] ?? 0) >= 6000 && ($k['i_max'] ?? 0) >= 9.0, 'Kennzahlen');
$geraete = Messdaten::geraete($fehler);
$g = array_values(array_filter($geraete, static fn ($x) => $x['geraet'] === 'pruef-3em'))[0] ?? null;
pruefe($g !== null && $g['modell'] === 'SPEM-003CEBEU' && $g['kanal'] === 'em:0', 'Gerät mit Modell gefunden');
pruefe($g !== null && $g['praefix'] === 'shellypro3em-pruef' && $g['generation'] === 2, 'MQTT-Präfix und Generation aus Node-RED');
$herz = Messdaten::pipeline();
gleich($herz['geschrieben'] ?? null, 100.0, 'Herzschlag von Node-RED gelesen');

// ---- Tanksensor: Rohwerte (Gen2 meldet nur bei Änderung, sensorfehler mit jeder Meldung)
$uni = 'pruef-uni-' . bin2hex(random_bytes(3));
$tz = static fn (int $ts, string $felder, string $kanal = 'voltmeter:100'): string => 'zustand,geraet=' . $uni . ',kanal=' . $kanal . ',typ=shelly ' . $felder . ' ' . ($ts * 1000);
$wert = static fn (?array $w, string $k): mixed => is_array($w) && array_key_exists($k, $w) ? $w[$k] : 'fehlt';
$abfrage = static fn (int $ab, int $abOnline = 0): ?array => Messdaten::tankLetzte([['geraet' => $uni, 'kanal' => 'voltmeter:100', 'feld' => 'u', 'ab' => $ab,
    'ab_online' => $abOnline ?: $jetzt - 7 * 86400]], $fehler)[$uni . '|voltmeter:100'] ?? null;
pruefe(Influx::schreiben([$tz($jetzt - 3 * 86400, 'u=4.0,sensorfehler=0'), $tz($jetzt - 3 * 3600, 'u=5.0,sensorfehler=0')], $fehler), 'Rohwerte geschrieben');
$w = $abfrage($jetzt - 2 * 3600);
gleich([$wert($w, 'zeit'), $wert($w, 'roh'), $wert($w, 'fehler'), $wert($w, 'online')], [null, null, false, null], 'Einbau vor 2 h, letzter Wert vor 3 h: kein Wert von vor dem Einbau');
Influx::schreiben([$tz($jetzt - 3600, 'u=6.0,sensorfehler=0')], $fehler);
$w = $abfrage($jetzt - 2 * 3600);
gleich([$w['zeit'] ?? null, $w['roh'] ?? null, $w['fehler'] ?? null], [($jetzt - 3600) * 1000, 6.0, false], 'Wert vor 1 h mit seiner echten Zeit');
Influx::schreiben([$tz($jetzt - 1800, 'sensorfehler=1')], $fehler);
$w = $abfrage($jetzt - 2 * 3600);
gleich([$w['roh'] ?? null, $w['zeit'] ?? null, $w['fehler'] ?? null, $w['fehler_zeit'] ?? null], [6.0, ($jetzt - 3600) * 1000, true, ($jetzt - 1800) * 1000],
    'Sensorfehler vor 30 min: Fehler, der Rohwert bleibt der letzte');
Influx::schreiben([$tz($jetzt - 900, 'u=6.1')], $fehler);
$w = $abfrage($jetzt - 2 * 3600);
gleich([$w['roh'] ?? null, $w['fehler'] ?? null, TankService::live([])], [6.1, true, []], 'tankLetzte meldet das letzte sensorfehler = 1 …');
pruefe((new ReflectionMethod(TankService::class, 'fehlerAktiv'))->invoke(null, $w) === false, '… es gilt aber nicht, wenn danach ein Wert ohne Fehler kam (klebt nicht)');
Influx::schreiben([$tz($jetzt - 600, 'u=6.2,sensorfehler=0')], $fehler);
$w = $abfrage($jetzt - 2 * 3600);
gleich([$w['roh'] ?? null, $w['fehler'] ?? null], [6.2, false], 'nächste Meldung ohne Fehler: der Fehler ist weg');
Influx::schreiben([$tz($jetzt - 300, 'online=0', 'geraet')], $fehler);
$w = $abfrage($jetzt - 2 * 3600);
gleich([$w['online'] ?? null, $w['online_zeit'] ?? null], [false, ($jetzt - 300) * 1000], 'Lebenszeichen: online=0 auf dem Kanal „geraet“');
gleich($wert($abfrage($jetzt - 2 * 3600, $jetzt - 60), 'online'), null, 'Lebenszeichen nur ab seiner Untergrenze');
// Median statt Mittel: drei Werte in einer Stunde, 1 / 2 / 9 V → 2 V (Mittel wäre 4 V)
$t0 = intdiv($jetzt - 3 * 86400, 3600) * 3600 + 60;
Influx::schreiben([$tz($t0, 'u=1.0', 'voltmeter:101'), $tz($t0 + 10, 'u=2.0', 'voltmeter:101'), $tz($t0 + 20, 'u=9.0', 'voltmeter:101')], $fehler);
$r = Messdaten::reihe('zustand', $uni, 'voltmeter:101', 'u', [], $t0 - 60, $t0 + 3000, null, 3600, 'median', $fehler);
gleich([$r['serien']['u'][0] ?? null, $fehler], [2.0, null], 'Median als Aggregat (Messdaten::reihe)');
// Gefälle (Verbrauch aus dem Pegel): Median je 10 min ab „ab“, mehrere Sensoren in einer Anfrage, nur im laufenden Datenfenster
$b0 = intdiv($jetzt - 5 * 3600, 600) * 600;
Influx::schreiben([$tz($b0 + 10, 'u=8.0', 'voltmeter:102'), $tz($b0 + 3600 + 10, 'u=7.0', 'voltmeter:102'), $tz($b0 + 3600 + 20, 'u=7.1', 'voltmeter:102'),
    $tz($b0 + 3600 + 30, 'u=9.9', 'voltmeter:102'), $tz($b0 + 7200 + 10, 'u=6.5', 'voltmeter:102'), $tz($jetzt - 7 * 3600, 'u=9.5', 'voltmeter:102')], $fehler);
$g = Messdaten::tankGefaelle([['geraet' => $uni, 'kanal' => 'voltmeter:102', 'feld' => 'u', 'ab' => $jetzt - 6 * 3600, 'fenster' => null],
    ['geraet' => $uni, 'kanal' => 'voltmeter:101', 'feld' => 'u', 'ab' => $jetzt - 6 * 3600, 'fenster' => null]], $fehler);
gleich([$g[$uni . '|voltmeter:102'] ?? null, $g[$uni . '|voltmeter:101'] ?? null, $fehler],
    [[[$b0 * 1000, 8.0], [($b0 + 3600) * 1000, 7.1], [($b0 + 7200) * 1000, 6.5]], [], null], 'Gefälle: Median je 10 min ab 6 h zurück, zwei Sensoren in einer Anfrage');
gleich(Messdaten::tankGefaelle([['geraet' => $uni, 'kanal' => 'voltmeter:102', 'feld' => 'u', 'ab' => $jetzt - 6 * 3600, 'fenster' => [[$jetzt - 86400, $jetzt - 1800]]]], $fehler), [],
    'Gefälle: Datenfenster vor 30 min zu Ende – nichts');
$alt = 'pruef-alt-' . bin2hex(random_bytes(3));
Influx::schreiben(['zustand,geraet=' . $alt . ',kanal=voltmeter:100,typ=shelly u=7.5,sensorfehler=0 ' . (($jetzt - 3 * 86400) * 1000)], $fehler);
$liste = array_filter(Messdaten::geraete($fehler), static fn ($g) => $g['geraet'] === $alt && $g['kanal'] === 'voltmeter:100');
pruefe(count($liste) === 1, 'Geräteliste: ein Analogkanal, der vor 3 Tagen zuletzt meldete');

// ---- Tank mit Sensor über TankService: ab Einbau, Fehler, offline, Datenfenster
$kennung = 'pm-influx-' . bin2hex(random_bytes(3));
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung ' . $kennung]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Wiese']);
$admin = BenutzerService::anlegen($kennung, 'Pruefung2026!!', 'admin', false);
$kunde = BenutzerService::anlegen($kennung . '-kunde', 'Pruefung2026!!', 'kunde', false);
try {
    \App\Auth\Auth::alsBenutzer($admin);
    $eig = ['inhalt_l' => '3000', 'kraftstoff' => 'Diesel', 'liter_je_mm' => '2,78', 'sumpf_l' => '140'];
    $tank = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennung . ' Tank', 'floor_id' => $floorId, 'eigenschaften' => $eig])['id'];
    $mp = Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => $uni, 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand', 'bezeichnung' => $kennung,
        'gueltig_ab' => Zeit::iso($jetzt - 2 * 3600), 'created_at' => gmdate('c')]);
    $r = TankRechnung::rechnung(['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
        'bereich_einheit' => 'mbar'], ['offset_mm' => 20], $eig, [])['rechnung'];
    TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]), $r, 'einsatz', Zeit::iso($jetzt - 2 * 3600), false);
    $l = LiveService::fuerObjekte([AnlagenService::find($tank)])[$tank];
    // Zuletzt: 6,2 V vor 10 min, Gerät offline vor 5 min
    pruefe($l['stufe'] === 'stumm' && ($l['tank']['roh'] ?? null) === 6.2 && ($l['tank']['online'] ?? null) === false && str_contains($l['hinweis'], 'offline'),
        'Tank: letzter Rohwert seit Einbau, Gerät offline → stumm, Werte sichtbar – ' . $l['hinweis']);
    nahe($l['tank']['liter'] ?? null, (float) TankRechnung::umrechnen($r, 6.2)['liter'], 0.1, 'Tank: Liter aus dem Rohwert');
    Influx::schreiben([$tz($jetzt - 120, 'online=1', 'geraet'), $tz($jetzt - 60, 'sensorfehler=1')], $fehler);
    $l = LiveService::fuerObjekte([AnlagenService::find($tank)])[$tank];
    pruefe($l['stufe'] === 'bad' && str_contains($l['hinweis'], 'Sensorfehler') && !str_contains($l['hinweis'], 'leer') && $wert($l['tank'], 'liter') === null,
        'Tank: Sensorfehler des Geräts → rot, nie „leer“, keine Liter – ' . $l['hinweis']);
    Influx::schreiben([$tz($jetzt - 30, 'u=1.5,sensorfehler=0')], $fehler);
    $l = LiveService::fuerObjekte([AnlagenService::find($tank)])[$tank];
    pruefe($l['stufe'] === 'bad' && str_contains($l['hinweis'], '3,6 mA'), 'Tank: 1,5 V (unter 3,6 mA) → Sensorfehler Leitungsbruch – ' . $l['hinweis']);
    gleich(TankService::rohJetzt(AnlagenService::find($tank), Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]))['roh'] ?? null, 1.5,
        'rohJetzt: der letzte Rohwert vom Server (auch außerhalb der Grenzen – die Rechnung entscheidet)');
    // Verbrauch je Tag: ein Shelly, der nur bei Änderung meldet – alle 2,5 h 0,1 V weniger (≈ 42,4 l, also ≈ 17 l/h),
    // gestern gegen 10 Uhr +3,0 V (≈ 1.273 l) mit Lieferschein. Stille ist keine Lücke.
    $mpTage = Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => $uni, 'kanal' => 'voltmeter:103', 'rolle' => 'fuellstand', 'bezeichnung' => $kennung . '-tage',
        'gueltig_ab' => Zeit::iso($jetzt - 3 * 86400), 'gueltig_bis' => Zeit::iso($jetzt - 2 * 3600), 'created_at' => gmdate('c')]);
    TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mpTage]), $r, 'einsatz', Zeit::iso($jetzt - 3 * 86400), true);
    $gestern = (new DateTimeImmutable('@' . $jetzt))->setTimezone(Zeit::zone())->modify('-1 day');
    $zehn = $gestern->setTime(10, 0)->getTimestamp();
    $meldungen = [];
    // Ab 7,5 V: nach dem Sprung höchstens ≈ 9,1 V (≈ 102 % des Nennvolumens) – über 105 % wäre der Wert unplausibel, eine Lücke.
    for ($k = 0, $t = $jetzt - 3 * 86400 + 600; $t < $jetzt - 2 * 3600; $k++, $t += 9000) {
        $meldungen[] = $tz($t, 'u=' . number_format(7.5 - 0.1 * $k + ($t >= $zehn ? 3.0 : 0.0), 2, '.', '') . ',sensorfehler=0', 'voltmeter:103');
    }
    Influx::schreiben($meldungen, $fehler);
    $lieferschein = Database::insert('tank_ereignisse', ['objekt_id' => $tank, 'messpunkt_id' => $mpTage, 'art' => 'betankung', 'zeit' => Zeit::iso($zehn), 'liter' => 1273.0,
        'lieferschein' => $kennung, 'von' => $kennung, 'created_at' => gmdate('c')]);
    $d = TankService::tage(AnlagenService::find($tank), $jetzt - 2 * 86400, $jetzt, $fehler, $mpTage);
    $g = array_column($d['tage'], null, 'tag')[$gestern->format('Y-m-d')] ?? [];
    $tagLaenge = ($gestern->setTime(0, 0)->modify('+1 day')->getTimestamp() - $gestern->setTime(0, 0)->getTimestamp()) / 3600;
    nahe($g['verbrauch_l'] ?? null, 0.1 * 152.6497 * 2.78 / 2.5 * $tagLaenge, 42.4, 'Verbrauch je Tag aus Meldungen nur bei Änderung (alle 2,5 h): ≈ 17 l/h ('
        . var_export($g['verbrauch_l'] ?? null, true) . ', ' . (string) $fehler . ')');
    gleich([$g['betankt_l'] ?? null, $g['stunden'] ?? null, array_column($d['betankungen'], 'quelle'), array_column($d['betankungen'], 'ereignisse')],
        [1273.0, round($tagLaenge, 2), ['lieferschein'], [[$lieferschein]]], '… ganzer Tag ohne Lücke, die Betankung mit der Menge laut Lieferschein');
    // Der zweite Sensor stört die Prüfungen danach nicht.
    Database::execute('DELETE FROM tank_ereignisse WHERE id = ?', [$lieferschein]);
    Database::execute('DELETE FROM tank_kennlinien WHERE messpunkt_id = ?', [$mpTage]);
    Database::execute('DELETE FROM messpunkte WHERE id = ?', [$mpTage]);
    Database::insert('freigaben', ['subject_type' => 'user', 'subject_id' => $kunde, 'ziel_art' => 'site', 'ziel_id' => $siteId, 'darf_bearbeiten' => 0, 'darf_steuern' => 0,
        'daten_ab' => Zeit::iso($jetzt - 86400), 'daten_bis' => Zeit::iso($jetzt - 3000), 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c')]);
    \App\Auth\Auth::alsBenutzer($kunde);
    $l = LiveService::fuerObjekte([AnlagenService::find($tank)])[$tank];
    gleich([$l['stufe'], $l['tank']], ['gesperrt', null], 'Datenfenster endete vor 50 min: kein Livewert');
    $verlauf = TankService::reihe(AnlagenService::find($tank), null, $jetzt - 4 * 3600, $jetzt, 'roh', $fehler);
    $werte = array_values(array_filter(array_combine($verlauf['zeiten'], $verlauf['serien']['roh'] ?? []), static fn ($v) => $v !== null));
    gleich($werte, [6.0], 'Verlauf: nur ab Einbau und im Datenfenster (6,0 V vor 1 h; 5,0 V davor, 6,2 und 1,5 V danach nicht)');
} finally {
    \App\Auth\Auth::alsBenutzer(null);
    AnlagenService::loeschen($tank ?? 0);
    SiteService::deleteSite($siteId);
    foreach ([$admin, $kunde] as $u) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$u]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$u]);
        Database::execute('DELETE FROM users WHERE id = ?', [$u]);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennung . '%', '%' . $kennung . '%']);
}

// ---- Fehlerfall: falsche Datenbank
Config::set('influx.database', 'gibtsnicht');
$fehler = null;
Messdaten::letzte([['pruef-3em', 'em:0']], $fehler);
pruefe($fehler !== null, 'unbekannte Datenbank meldet einen Fehler');

ende('influx');
