<?php
declare(strict_types=1);

/**
 * InfluxDB: schreiben und mit InfluxQL lesen – Livewerte, Verläufe im
 * Datenfenster, Energie je Tag aus dem Zählerstand, Geräteliste, Herzschlag.
 *
 * Startet eine eigene InfluxDB 1.x auf freien Ports (influxd aus dem
 * Paket „influxdb“), damit die Prüfung nicht an einer eingerichteten
 * Datenbank hängt. InfluxDB 2.x spricht über /query und /write dasselbe.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Influx;
use App\Messung\Messdaten;
use App\Support\Config;
use App\Support\Zeit;

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

// ---- Fehlerfall: falsche Datenbank
Config::set('influx.database', 'gibtsnicht');
$fehler = null;
Messdaten::letzte([['pruef-3em', 'em:0']], $fehler);
pruefe($fehler !== null, 'unbekannte Datenbank meldet einen Fehler');

ende('influx');
