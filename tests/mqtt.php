<?php
declare(strict_types=1);

/**
 * MQTT: eigener Client, Shelly schalten (Gen2 mit Antwort, Gen1), Sammler
 * veröffentlicht normalisierte Messwerte. Braucht einen Broker (in den
 * Prüfungen: Mosquitto auf 127.0.0.1:1883 ohne Anmeldung).
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Datenquellen;
use App\Messung\Mqtt;
use App\Messung\Profile;
use App\Messung\Sammler;
use App\Messung\Steuerung;
use App\Support\Config;
use App\Support\Database;

/*
 * Ein eigener Broker auf einem freien Port: so hängt die Prüfung nicht davon
 * ab, wie der Mosquitto der Maschine eingerichtet ist (auf einem fertig
 * installierten Server verlangt er eine Anmeldung).
 */
$host = '127.0.0.1';
$mosquitto = \App\Support\Programm::finden('mosquitto', ['-h']) ?? (is_file('/usr/sbin/mosquitto') ? '/usr/sbin/mosquitto' : null);
if ($mosquitto === null) {
    uebersprungen('mqtt', 'mosquitto ist nicht installiert');
}
$port = freierPort();
$brokerConf = sys_get_temp_dir() . '/pm-mosquitto-' . $port . '.conf';
file_put_contents($brokerConf, "listener {$port} 127.0.0.1\nallow_anonymous true\npersistence false\n");
hintergrund([$mosquitto, '-c', $brokerConf]);
for ($i = 0; $i < 50 && !erreichbar($host, $port); $i++) {
    usleep(100_000);
}
if (!erreichbar($host, $port)) {
    uebersprungen('mqtt', 'eigener Broker startet nicht');
}
Config::set('mqtt.host', $host);
Config::set('mqtt.port', $port);
Profile::grundbestand();
Config::set('app.demo', false);
Config::set('mqtt.username', '');
Config::set('mqtt.password', '');
Config::set('sammler.ziel', 'mqtt');

$kennung = 'pm-pruef-' . bin2hex(random_bytes(3));
$quellen = [];
$protokoll = sys_get_temp_dir() . '/' . $kennung . '.log';

try {
    // --------------------------------------------- Client selbst
    $a = new Mqtt();
    $a->verbinden($host, $port, '', '', $kennung . '-a');
    $a->abonnieren([$kennung . '/#']);
    $b = new Mqtt();
    $b->verbinden($host, $port, '', '', $kennung . '-b');
    $b->veroeffentlichen($kennung . '/qos0', 'hallo');
    $b->veroeffentlichen($kennung . '/qos1', str_repeat('x', 300), 1);
    $b->veroeffentlichen($kennung . '/behalten', 'gemerkt', 0, true);
    $empfangen = $a->lauschen(3, static fn () => false);
    $themen = array_column($empfangen, 'nachricht', 'thema');
    gleich($themen[$kennung . '/qos0'] ?? null, 'hallo', 'QoS 0 kommt an');
    gleich(strlen($themen[$kennung . '/qos1'] ?? ''), 300, 'QoS 1 mit längerer Nachricht (Restlänge über 127)');
    $c = new Mqtt();
    $c->verbinden($host, $port, '', '', $kennung . '-c');
    $c->abonnieren([$kennung . '/behalten']);
    $behalten = $c->lauschen(2, static fn () => true);
    pruefe(($behalten[0]['retain'] ?? false) === true && $behalten[0]['nachricht'] === 'gemerkt', 'Retained-Nachricht wird zugestellt');
    $b->veroeffentlichen($kennung . '/behalten', '', 0, true); // wieder weg
    $a->trennen();
    $b->trennen();
    $c->trennen();

    // --------------------------------------------- Shelly schalten
    $admin = (int) Database::scalar("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key = 'admin' ORDER BY ur.user_id LIMIT 1");
    alsBenutzer($admin);
    $gen2 = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Prüf-Shelly Gen2', 'geraet' => $kennung . '-gen2',
        'extern_id' => $kennung . '-gen2', 'profil' => 'gen2', 'aktiv' => 1])['id'];
    $gen1 = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Prüf-Shelly Gen1', 'geraet' => $kennung . '-gen1',
        'extern_id' => 'shellies/' . $kennung . '-gen1', 'profil' => 'gen1', 'aktiv' => 1])['id'];
    array_push($quellen, $gen2, $gen1);

    hintergrund(['php', __DIR__ . '/hilfen/shelly-attrappe.php', $host, (string) $port, $kennung . '-gen2', $protokoll]);
    hintergrund(['php', __DIR__ . '/hilfen/shelly-attrappe.php', $host, (string) $port, 'shellies/' . $kennung . '-gen1', $protokoll]);
    for ($i = 0; $i < 50 && substr_count((string) @file_get_contents($protokoll), 'bereit') < 2; $i++) {
        usleep(100_000);
    }

    $erg = Steuerung::ausfuehren($gen2, 'aus', 'switch:0');
    pruefe($erg['ok'] && str_contains($erg['message'], 'ausgeschaltet'), 'Gen2: Antwort des Shelly ausgewertet – ' . $erg['message']);
    $erg = Steuerung::ausfuehren($gen1, 'ein', 'relay:1');
    pruefe($erg['ok'], 'Gen1: Befehl gesendet – ' . $erg['message']);
    usleep(500_000);
    $log = (string) @file_get_contents($protokoll);
    pruefe(str_contains($log, '"method":"Switch.Set"') && str_contains($log, '"on":false'), 'Gen2 hat Switch.Set on=false erhalten');
    pruefe(str_contains($log, 'shellies/' . $kennung . '-gen1/relay/1/command on'), 'Gen1 hat „on“ auf relay/1 erhalten');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM schaltvorgaenge WHERE geraet LIKE ? AND ergebnis = 'ok'", [$kennung . '%']), 2, 'beide Schaltvorgänge protokolliert');
    $erg = Steuerung::ausfuehren($gen2, 'aus', 'dimmer:0');
    pruefe(!$erg['ok'], 'unbekannter Kanal wird abgelehnt');
    pruefe(preg_match('#"src":"pm-steuerung-[0-9a-f]{8}"#', $log) === 1, 'ohne eigenen MQTT-Zugang: Antwort wie bisher an pm-steuerung-…/rpc');

    // Mit eigenem MQTT-Zugang antwortet der Shelly in seinen eigenen Baum
    // (nur dorthin darf er schreiben) – Steuerung fragt dann mit src im Baum.
    $jetzt = gmdate('c');
    Database::insert('mqtt_zugaenge', ['benutzer' => $kennung . '-gen2', 'praefix' => $kennung . '-gen2', 'geraet' => $kennung . '-gen2', 'generation' => 2,
        'kennwort_hash' => \App\Messung\MqttZugaenge::hash('x'), 'zustand' => 'eigen', 'kennwort_am' => $jetzt, 'created_at' => $jetzt]);
    $erg = Steuerung::ausfuehren($gen2, 'ein', 'switch:0');
    pruefe($erg['ok'], 'Gen2 mit eigenem Zugang geschaltet – ' . $erg['message']);
    $log = (string) @file_get_contents($protokoll);
    pruefe(str_contains($log, '"src":"' . $kennung . '-gen2/pm-steuerung-'), 'mit eigenem Zugang: src im Baum des Geräts');
    Database::execute('DELETE FROM mqtt_zugaenge WHERE praefix = ?', [$kennung . '-gen2']);

    // Eine Antwort mit falschem dst zählt nicht.
    putenv('ATTRAPPE_FALSCHES_DST=1');
    $gen2b = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Prüf-Shelly falsch', 'geraet' => $kennung . '-falsch',
        'extern_id' => $kennung . '-falsch', 'profil' => 'gen2', 'aktiv' => 1])['id'];
    $quellen[] = $gen2b;
    hintergrund(['php', __DIR__ . '/hilfen/shelly-attrappe.php', $host, (string) $port, $kennung . '-falsch', $protokoll]);
    putenv('ATTRAPPE_FALSCHES_DST');
    for ($i = 0; $i < 50 && substr_count((string) @file_get_contents($protokoll), 'bereit') < 3; $i++) {
        usleep(100_000);
    }
    alsBenutzer($admin);
    $erg = Steuerung::ausfuehren($gen2b, 'ein', 'switch:0');
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'nicht geantwortet'), 'Antwort mit falschem dst wird nicht gezählt');

    // Ohne Schalt-Recht: abgelehnt und protokolliert
    alsBenutzer(null);
    $erg = Steuerung::ausfuehren($gen2, 'ein', 'switch:0');
    pruefe(!$erg['ok'], 'ohne Anmeldung/Recht wird nicht geschaltet');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM schaltvorgaenge WHERE geraet = ? AND ergebnis = 'fehler'", [$kennung . '-gen2']), 1, 'Ablehnung ohne Recht protokolliert');

    // --------------------------------------------- Sammler → MQTT
    $mbPort = freierPort();
    hintergrund(['php', __DIR__ . '/hilfen/modbus-attrappe.php', (string) $mbPort, json_encode(['100' => [817 => 1200, 818 => 1100, 819 => 900, 843 => 64]])]);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $mbPort); $i++) {
        usleep(100_000);
    }
    $gx = (int) Datenquellen::speichern(null, ['art' => 'modbus', 'name' => 'Prüf-GX', 'geraet' => $kennung . '-gx', 'host' => '127.0.0.1',
        'port' => $mbPort, 'unit_id' => 227, 'profil' => 'victron_gx', 'intervall_s' => 1, 'aktiv' => 1])['id'];
    $quellen[] = $gx;
    $lauscher = new Mqtt();
    $lauscher->verbinden($host, $port, '', '', $kennung . '-lauscher');
    $lauscher->abonnieren(['pm/messwert/' . $kennung . '-gx/#']);
    $sammler = new Sammler();
    gleich($sammler->runde([Datenquellen::find($gx)], true), 1, 'Sammler fragt die Quelle ab');
    $nachrichten = $lauscher->lauschen(3, static fn (string $t) => str_ends_with($t, '/batterie'));
    $jeThema = [];
    foreach ($nachrichten as $n) {
        $jeThema[$n['thema']] = json_decode($n['nachricht'], true);
    }
    $verbrauch = $jeThema['pm/messwert/' . $kennung . '-gx/verbrauch'] ?? null;
    pruefe($verbrauch !== null, 'Messwert unter pm/messwert/<gerät>/verbrauch veröffentlicht');
    gleich($verbrauch['phasen']['L1']['p'] ?? null, 1200.0, 'Format: phasen.L1.p');
    gleich($verbrauch['typ'] ?? null, 'victron', 'Format: typ');
    pruefe(is_int($verbrauch['ts'] ?? null) && $verbrauch['ts'] > 1700000000000, 'Format: ts in Millisekunden');
    gleich($jeThema['pm/messwert/' . $kennung . '-gx/batterie']['zustand']['soc'] ?? null, 64.0, 'Zustandswerte im Format');
    pruefe(Database::scalar('SELECT letzter_kontakt FROM datenquellen WHERE id = ?', [$gx]) !== null, 'letzter Kontakt vermerkt');
    $lauscher->trennen();
} finally {
    alsBenutzer(null);
    foreach ($quellen as $id) {
        Datenquellen::loeschen($id);
    }
    Database::execute('DELETE FROM schaltvorgaenge WHERE geraet LIKE ?', [$kennung . '%']);
    Database::execute('DELETE FROM mqtt_zugaenge WHERE praefix LIKE ?', [$kennung . '%']);
    @unlink($protokoll);
    @unlink($brokerConf);
}

ende('mqtt');
