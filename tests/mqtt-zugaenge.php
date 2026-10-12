<?php
declare(strict_types=1);

/**
 * Eigene MQTT-Zugänge je Shelly – von der App bis zum Broker.
 *
 * Eigener Mosquitto mit der echten ACL aus deploy/mosquitto und dem echten
 * Helfer deploy/mqtt-zugaenge.sh (ein Wächter ersetzt die systemd-.path-
 * Einheit). Shelly-Attrappen für Gen2 und Gen1 melden sich erst mit dem
 * gemeinsamen Zugang „shelly“ an, nach dem „Neustart“ mit dem eigenen.
 *
 * Geprüft: Einrichten (Gen2: Vorprüfung, Kontoprobe, MQTT.SetConfig nur mit
 * Benutzer/Kennwort, kein Neustart; Gen1: Kennwort einmal), Umstellung
 * prüfen (ohne Neustart abgelehnt, Sperr-Probe schlägt fehl und nimmt die
 * Sperre zurück, nach Neustart „eigen“ mit dauerhafter Sperre des
 * gemeinsamen Zugangs), Steuerung mit Antwort im Baum, neues Kennwort,
 * Entfernen, Namensregeln (PHP = Helfer), Kontoprobe gegen falsche ACLs,
 * keine Geheimnisse in Audit, Log, Soll- und Ergebnis-Datei.
 */

require __DIR__ . '/hilfen/pruefung.php';
require __DIR__ . '/hilfen/mqtt-broker.php';

use App\Messung\Datenquellen;
use App\Messung\Mqtt;
use App\Messung\MqttZugaenge;
use App\Messung\ShellyRpc;
use App\Messung\Steuerung;
use App\Support\Config;
use App\Support\Database;

if ((\App\Support\Programm::finden('mosquitto', ['-h']) ?? (is_file('/usr/sbin/mosquitto') ? '/usr/sbin/mosquitto' : null)) === null) {
    uebersprungen('mqtt-zugaenge', 'mosquitto ist nicht installiert');
}

// Nicht „pm-…“: das ist für Gerätezugänge reserviert.
$kennung = 'pruef-' . bin2hex(random_bytes(3));
$kw = ['shelly' => 'gemeinsam-' . bin2hex(random_bytes(6)), 'nodered' => 'nr-' . bin2hex(random_bytes(6)), 'powermanagement' => 'pm-' . bin2hex(random_bytes(6))];
$broker = mqttTestbroker($kw);
$dateien = [];
$quellen = [];
$attrappen = [];
$logDatei = APP_ROOT . '/storage/logs/app.log';
$logVorher = is_file($logDatei) ? filesize($logDatei) : 0;

Config::set('app.demo', false);
Config::set('mqtt.host', $broker['host']);
Config::set('mqtt.port', $broker['port']);
Config::set('mqtt.username', 'powermanagement');
Config::set('mqtt.password', $kw['powermanagement']);
Config::set('mqtt.zugaenge_verzeichnis', $broker['mqttdir']);
mqttWaechterStarten($broker);

/** Kennwort in eine Datei – nicht in die Prozessliste. */
$kennwortDatei = static function (string $kennwort) use (&$dateien): string {
    $f = tempnam(sys_get_temp_dir(), 'pm-kw-');
    file_put_contents($f, $kennwort);
    $dateien[] = $f;
    return $f;
};
/** Attrappe starten und warten, bis sie bereit (oder abgelehnt) ist. */
$starten = static function (string $praefix, string $protokoll, string $benutzer, string $kennwort) use ($broker, $kennwortDatei, &$attrappen) {
    $vorher = substr_count((string) @file_get_contents($protokoll), "\n");
    $p = hintergrund(['php', __DIR__ . '/hilfen/shelly-attrappe.php', $broker['host'], (string) $broker['port'], $praefix, $protokoll, $benutzer, $kennwortDatei($kennwort)]);
    $attrappen[] = $p;
    for ($i = 0; $i < 60; $i++) {
        $zeilen = array_slice(explode("\n", (string) @file_get_contents($protokoll)), $vorher);
        foreach ($zeilen as $z) {
            if ($z === 'bereit' || str_starts_with($z, 'abgelehnt')) {
                return [$p, $z === 'bereit'];
            }
        }
        usleep(100_000);
    }
    return [$p, false];
};
$stoppen = static function ($p): void {
    if (is_resource($p)) {
        proc_terminate($p);
        proc_close($p);
    }
};
$gesperrt = static fn (string $praefix): bool => in_array($praefix, MqttZugaenge::ergebnis()['gesperrt'] ?? [], true);
/** Schutzliste für Node-RED, wie sie gespeichert (retained) am Broker liegt. */
$schutz = static function () use ($broker, $kw, $kennung): array {
    $leser = mqttVerbinden($broker, 'nodered', $kw['nodered'], $kennung . '-schutz-' . bin2hex(random_bytes(2)));
    $leser->abonnieren(['pm/status/mqtt-zugaenge']);
    $n = $leser->lauschen(1.5, static fn (string $t, string $_m, bool $retain): bool => $retain);
    $leser->trennen();
    $letzte = end($n);
    return $letzte !== false ? (array) (json_decode($letzte['nachricht'], true)['geschuetzt'] ?? []) : [];
};
$konten = static fn (): array => MqttZugaenge::ergebnis()['konten'] ?? [];

$admin = (int) Database::scalar("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key = 'admin' ORDER BY ur.user_id LIMIT 1");
alsBenutzer($admin);

try {
    // --------------------------------------------- Grundlagen
    gleich(MqttZugaenge::verfuegbar(), null, 'Broker-Abgleich bereit');
    gleich(MqttZugaenge::sperreHindernis(), null, 'gemeinsamer Zugang lässt sich sperren (wirksame ACL, user shelly)');
    $hash = MqttZugaenge::hash('geheim');
    pruefe(preg_match('#^\$7\$101\$[A-Za-z0-9+/]{16}\$[A-Za-z0-9+/]{86}==$#D', $hash) === 1, 'Hash im Format von mosquitto_passwd');
    pruefe(MqttZugaenge::hash('geheim') !== $hash, 'jeder Hash mit eigenem Salz');
    pruefe(preg_match('/^[A-HJ-NP-Za-km-z2-9]{24}$/', MqttZugaenge::kennwortErzeugen()) === 1, 'Kennwort: 24 Zeichen ohne Verwechsler');

    // Namensregeln: PHP und Helfer sagen dasselbe
    $faelle = [
        [2, 'shellypro4pm-a0b1c2d3e4f5'], [2, 'shelly/halle-3'], [1, 'shelly1-B929CC'], [2, 'shelly'], [2, 'shellies'], [2, 'nodered'],
        [2, 'powermanagement'], [2, 'pm'], [2, 'pm-x'], [1, 'PM_x'], [1, 'command'], [1, 'Announce'], [2, 'a/b'], [2, 'shelly/a/b'],
        [2, 'Shelly/x'], [2, 'ABC'], [1, 'shellies/x'], [2, 'x y'], [2, 'x+'], [2, 'x#'], [2, 'x:y'], [2, '$SYS'], [2, '%u'], [2, ''],
        [2, str_repeat('a', 65)], [2, str_repeat('a', 64)], [3, 'abc'], [1, 'shelly1-b929cc'], [2, 'shelly/command'], [2, '-x'],
    ];
    $eingabe = implode("\n", array_map(static fn (array $f): string => $f[0] . ' ' . $f[1], $faelle)) . "\n";
    $helfer = mqttHelfer($broker, ['--nur-namen'], $eingabe);
    $antworten = explode("\n", trim($helfer['ausgabe']));
    $gleich = count($antworten) === count($faelle);
    foreach ($faelle as $i => [$generation, $name]) {
        $php = MqttZugaenge::namensFehler($name, $generation);
        $bash = $antworten[$i] ?? '';
        if (($php === null ? 'ok' : 'fehler ' . $php) !== $bash) {
            $gleich = false;
            pruefe(false, 'Namensregel „' . $name . '“ (Gen' . $generation . '): PHP ' . ($php ?? 'ok') . ', Helfer ' . $bash);
        }
    }
    pruefe($gleich, 'Namensregeln in PHP und Helfer gleich (' . count($faelle) . ' Fälle)');
    gleich(MqttZugaenge::namensFehler('shelly/halle-3', 2), null, 'shelly/<name> ist ein gültiger Gen2-Zugang');
    gleich(MqttZugaenge::namensFehler('pm-x', 2), 'reserviert', 'pm-… ist reserviert');
    gleich(MqttZugaenge::benutzerAusPraefix('shellies/shelly1-B929CC', 1), 'shelly1-B929CC', 'Gen1: Benutzer = Geräte-ID');
    gleich(MqttZugaenge::benutzerAusPraefix('shelly1-B929CC', 1), null, 'Gen1 ohne shellies/ → kein Zugang');
    gleich(MqttZugaenge::geraetName('shellies/shelly1-B929CC'), 'shelly1-b929cc', 'Gerätename klein wie in Node-RED');

    // ShellyRpc: nur die feste Methodenliste, SetConfig nur mit Zugangsdaten
    $rpcTest = Mqtt::ausKonfiguration('pm-pruef-rpc');
    foreach (['Shelly.Reboot', 'Script.Eval', 'Sys.SetConfig'] as $methode) {
        $erg = ShellyRpc::aufrufen($rpcTest, $kennung . '-x', ShellyRpc::quelle($kennung . '-x', 'konto', true), $methode);
        pruefe(!$erg['ok'] && str_contains($erg['message'], 'nicht an Shellys'), $methode . ' geht nicht hinaus');
    }
    $erg = ShellyRpc::aufrufen($rpcTest, $kennung . '-x', ShellyRpc::quelle($kennung . '-x', 'konto', true), 'MQTT.SetConfig', ['config' => ['user' => 'a', 'pass' => 'b', 'server' => 'evil:1883']]);
    pruefe(!$erg['ok'] && !str_contains($erg['message'], 'evil'), 'MQTT.SetConfig mit server wird abgelehnt (ohne Parameter in der Meldung)');
    $erg = ShellyRpc::aufrufen($rpcTest, $kennung . '-x/#', 'pm-konto-00000000', 'Sys.GetStatus');
    pruefe(!$erg['ok'], 'Präfix mit # wird abgelehnt');
    $rpcTest->trennen();

    // --------------------------------------------- Gen2 von Anfang bis Ende
    $p2 = $kennung . '-gen2';
    $log2 = sys_get_temp_dir() . '/' . $p2 . '.log';
    $dateien[] = $log2;
    $dateien[] = $log2 . '.konfig';
    [$geraet2, $bereit] = $starten($p2, $log2, 'shelly', $kw['shelly']);
    pruefe($bereit, 'Gen2-Attrappe am gemeinsamen Zugang');
    $quelle2 = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Prüf-Shelly ' . $p2, 'geraet' => $p2, 'extern_id' => $p2, 'profil' => 'gen2', 'aktiv' => 1])['id'];
    $quellen[] = $quelle2;

    $uebersicht = array_column(MqttZugaenge::uebersicht(), null, 'praefix');
    pruefe(($uebersicht[$p2]['zustand'] ?? '') === 'gemeinsam' && $uebersicht[$p2]['umstellbar'], 'Übersicht: Gerät am gemeinsamen Zugang, umstellbar');

    $erg = Steuerung::ausfuehren($quelle2, 'aus', 'switch:0');
    pruefe($erg['ok'], 'vor der Umstellung geschaltet – ' . $erg['message']);
    pruefe(preg_match('#"src":"pm-steuerung-[0-9a-f]{8}"#', (string) file_get_contents($log2)) === 1, 'ohne eigenen Zugang: src wie bisher');

    $erg = MqttZugaenge::vorpruefen($p2);
    pruefe($erg['ok'] && ($erg['info']['benutzer'] ?? '') === 'shelly', 'Vorprüfung: antwortet im eigenen Baum, nutzt „shelly“ – ' . $erg['message']);

    $erg = MqttZugaenge::einrichten($p2, 2);
    pruefe($erg['ok'] && !isset($erg['kennwort']), 'Gen2 eingerichtet, kein Kennwort in der Antwort – ' . $erg['message']);
    $zugang2 = MqttZugaenge::nachPraefix($p2);
    gleich($zugang2['zustand'] ?? null, 'uebertragen', 'Gen2: Zustand „wartet auf Neustart“');
    pruefe(in_array($p2, $konten(), true), 'Zugang steht in der Kennwortdatei des Brokers');
    $konfig = (array) json_decode((string) @file_get_contents($log2 . '.konfig'), true);
    pruefe(($konfig['user'] ?? '') === $p2 && strlen((string) ($konfig['pass'] ?? '')) === 24 && count($konfig) === 2, 'MQTT.SetConfig nur mit Benutzer und Kennwort');
    $kennwort2 = (string) ($konfig['pass'] ?? '');
    $protokoll2 = (string) file_get_contents($log2);
    pruefe(!str_contains($protokoll2, 'Shelly.Reboot'), 'kein Neustart ausgelöst');
    pruefe(str_contains($protokoll2, '"src":"' . $p2 . '/pm-konto-'), 'Zugangs-Anfragen mit src im Baum des Geräts');
    pruefe(!str_contains((string) file_get_contents($broker['passwd']), $kennwort2), 'Kennwort nicht im Klartext beim Broker');

    $erg = Steuerung::ausfuehren($quelle2, 'ein', 'switch:0');
    pruefe($erg['ok'] && str_contains((string) file_get_contents($log2), '"src":"' . $p2 . '/pm-steuerung-'), 'mit eigenem Zugang: Steuerung fragt mit src im Baum – ' . $erg['message']);

    $erg = MqttZugaenge::pruefen((int) $zugang2['id']);
    pruefe(!$erg['ok'] && !empty($erg['neustart']), 'Umstellung prüfen ohne Neustart: abgelehnt – ' . $erg['message']);
    $erg = MqttZugaenge::pruefen((int) $zugang2['id'], true);
    pruefe(!$erg['ok'], 'trotzdem prüfen: Gerät hängt noch am gemeinsamen Zugang → fehlgeschlagen – ' . $erg['message']);
    gleich(MqttZugaenge::nachPraefix($p2)['zustand'] ?? null, 'uebertragen', 'nach fehlgeschlagener Prüfung wieder „wartet auf Neustart“');
    pruefe(!$gesperrt($p2), 'Prüf-Sperre wieder aufgehoben');
    // (Vorprüfen sagte jetzt „wartet auf Neustart“ – also direkt fragen.)
    $frage = Mqtt::ausKonfiguration($kennung . '-frage');
    pruefe(ShellyRpc::aufrufen($frage, $p2, ShellyRpc::quelle($p2, 'konto', true), 'Sys.GetStatus')['ok'], 'Gerät über den gemeinsamen Zugang wieder erreichbar');
    $frage->trennen();

    // „Neustart“: die Attrappe meldet sich mit dem gespeicherten Zugang an
    $stoppen($geraet2);
    [$geraet2, $bereit] = $starten($p2, $log2, $p2, $kennwort2);
    pruefe($bereit, 'nach dem Neustart: Anmeldung mit dem eigenen Zugang');
    // „Erneut übertragen“ hinge es jetzt ab (neues Kennwort am Broker) – abgelehnt
    $hashVorher = Database::scalar('SELECT kennwort_hash FROM mqtt_zugaenge WHERE id = ?', [(int) $zugang2['id']]);
    $erg = MqttZugaenge::uebertragen((int) $zugang2['id']);
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'nutzt ihn vermutlich schon'), 'Übertragen nach dem Neustart abgelehnt – ' . $erg['message']);
    gleich(Database::scalar('SELECT kennwort_hash FROM mqtt_zugaenge WHERE id = ?', [(int) $zugang2['id']]), $hashVorher, 'Kennwort am Broker unverändert');
    $erg = MqttZugaenge::pruefen((int) $zugang2['id']);
    pruefe($erg['ok'], 'Umstellung bestätigt – ' . $erg['message']);
    gleich(MqttZugaenge::nachPraefix($p2)['zustand'] ?? null, 'eigen', 'Zustand „eigener Zugang“');
    pruefe($gesperrt($p2), 'gemeinsamer Zugang dauerhaft gesperrt');
    gleich($schutz()[MqttZugaenge::geraetName($p2)] ?? null, $p2, 'Schutzliste für Node-RED: Gerätename → Präfix (retained unter pm/)');
    $gemeinsamSchreiber = mqttVerbinden($broker, 'shelly', $kw['shelly'], $kennung . '-faelscher');
    $gemeinsamSchreiber->veroeffentlichen('pm/status/mqtt-zugaenge', '{"geschuetzt":{}}', 1, true);
    $gemeinsamSchreiber->trennen();
    pruefe(isset($schutz()[MqttZugaenge::geraetName($p2)]), 'gemeinsamer Zugang kann die Schutzliste nicht überschreiben');

    $gemeinsam = mqttVerbinden($broker, 'shelly', $kw['shelly'], $kennung . '-gemeinsam');
    $beobachter = mqttVerbinden($broker, 'nodered', $kw['nodered'], $kennung . '-beobachter');
    pruefe(!mqttKommtAn($gemeinsam, $beobachter, $p2 . '/rpc'), 'gemeinsamer Zugang kann das umgestellte Gerät nicht mehr ansprechen');
    pruefe(!mqttKommtAn($beobachter, $gemeinsam, $p2 . '/status/switch:0'), 'gemeinsamer Zugang liest das umgestellte Gerät nicht mehr mit');
    pruefe(mqttKommtAn($gemeinsam, $beobachter, $kennung . '-andere/rpc'), 'gemeinsamer Zugang erreicht andere Geräte weiter');
    $eigen = mqttVerbinden($broker, $p2, $kennwort2, $kennung . '-eigen-probe');
    pruefe(!mqttKommtAn($eigen, $beobachter, $kennung . '-andere/rpc'), 'eigener Zugang erreicht kein fremdes Gerät');
    pruefe(!mqttKommtAn($eigen, $beobachter, 'pm/messwert/' . $kennung . '/x'), 'eigener Zugang schreibt nicht unter pm/');
    $eigen->trennen();

    $erg = Steuerung::ausfuehren($quelle2, 'aus', 'switch:0');
    pruefe($erg['ok'], 'nach der Umstellung geschaltet – ' . $erg['message']);
    $erg = MqttZugaenge::pruefen((int) $zugang2['id']);
    pruefe($erg['ok'], '„eigen“ erneut prüfen – ' . $erg['message']);

    // Node-RED bestätigt die Schutzliste (hier von Hand als „nodered“)
    $liste = MqttZugaenge::schutzStatus();
    pruefe($liste !== null && $liste['liste'] !== null && !$liste['aktuell'], 'ohne Bestätigung von Node-RED: Schutz gilt als nicht übernommen');
    $quittung = mqttVerbinden($broker, 'nodered', $kw['nodered'], $kennung . '-quittung');
    $quittung->veroeffentlichen('pm/status/nodered-schutz', (string) json_encode(['ts' => $liste['liste'] ?? 0, 'anzahl' => 1, 'zeit' => 1]), 1, true);
    $quittung->trennen();
    pruefe((MqttZugaenge::schutzStatus()['aktuell'] ?? false) === true, 'mit Bestätigung von Node-RED: Schutz übernommen');

    // Neues Kennwort: einmal angezeigt (nie per MQTT), am Gerät eingetragen
    $erg = MqttZugaenge::kennwortErneuern((int) $zugang2['id']);
    pruefe($erg['ok'] && strlen((string) ($erg['kennwort'] ?? '')) === 24 && ($erg['generation'] ?? 0) === 2, 'Gen2: neues Kennwort einmal zurück – ' . $erg['message']);
    $kennwortNeu = (string) ($erg['kennwort'] ?? '');
    pruefe(!str_contains((string) file_get_contents($log2), $kennwortNeu), 'neues Kennwort ging nicht über MQTT');
    file_put_contents($log2 . '.konfig', (string) json_encode(['user' => $p2, 'pass' => $kennwortNeu]));
    $abgewiesen = false;
    try {
        mqttVerbinden($broker, $p2, $kennwort2, $kennung . '-alt')->trennen();
    } catch (\RuntimeException) {
        $abgewiesen = true;
    }
    pruefe($abgewiesen, 'altes Kennwort gilt am Broker nicht mehr');
    $stoppen($geraet2);
    [$geraet2, $bereit] = $starten($p2, $log2, $p2, $kennwortNeu);
    pruefe($bereit, 'nach dem Neustart mit dem neuen Kennwort angemeldet');
    $erg = MqttZugaenge::pruefen((int) $zugang2['id']);
    pruefe($erg['ok'] && (int) (MqttZugaenge::nachPraefix($p2)['neustart_noetig'] ?? 1) === 0, 'nach dem Neustart: „Neustart nötig“ erledigt');

    // Entfernen: Zugang und Sperre weg, gemeinsamer Zugang darf wieder
    $erg = MqttZugaenge::entfernen((int) $zugang2['id']);
    pruefe($erg['ok'] && !in_array($p2, $konten(), true) && !$gesperrt($p2), 'entfernt: kein Zugang, keine Sperre – ' . $erg['message']);
    pruefe(mqttKommtAn($gemeinsam, $beobachter, $p2 . '/rpc'), 'gemeinsamer Zugang erreicht das Gerät wieder');
    pruefe(!isset($schutz()[MqttZugaenge::geraetName($p2)]), 'entfernt: nicht mehr in der Schutzliste');
    $stoppen($geraet2);

    // --------------------------------------------- Gen2: Entfernen fragt das Gerät, von Hand einrichten
    $p3 = $kennung . '-gen2b';
    $log3 = sys_get_temp_dir() . '/' . $p3 . '.log';
    array_push($dateien, $log3, $log3 . '.konfig');
    [$geraet3, $bereit] = $starten($p3, $log3, 'shelly', $kw['shelly']);
    pruefe($bereit, 'zweite Gen2-Attrappe am gemeinsamen Zugang');
    $erg = MqttZugaenge::einrichten($p3, 2);
    $zugang3 = MqttZugaenge::nachPraefix($p3);
    pruefe($erg['ok'] && ($zugang3['zustand'] ?? '') === 'uebertragen', 'zweites Gerät übertragen – ' . $erg['message']);
    $erg = MqttZugaenge::entfernen((int) $zugang3['id']);
    pruefe(!$erg['ok'] && !empty($erg['erzwingbar']) && MqttZugaenge::nachPraefix($p3) !== null, 'Entfernen abgelehnt: Gerät hat den Zugang gespeichert – ' . $erg['message']);
    $erg = MqttZugaenge::entfernen((int) $zugang3['id'], true);
    pruefe($erg['ok'] && MqttZugaenge::nachPraefix($p3) === null, 'Entfernen erzwungen');
    // Der Bediener trägt am Gerät wieder den gemeinsamen Zugang ein und startet neu
    $stoppen($geraet3);
    @unlink($log3 . '.konfig');
    [$geraet3, $bereit] = $starten($p3, $log3, 'shelly', $kw['shelly']);
    $logLaenge = strlen((string) file_get_contents($log3));
    $erg = MqttZugaenge::einrichten($p3, 2, true);
    pruefe($erg['ok'] && strlen((string) ($erg['kennwort'] ?? '')) === 24 && ($erg['generation'] ?? 0) === 2, 'Gen2 von Hand: Kennwort einmal zurück');
    $kennwort3 = (string) ($erg['kennwort'] ?? '');
    $neu = substr((string) file_get_contents($log3), $logLaenge);
    pruefe(!str_contains($neu, $kennwort3) && !str_contains($neu, 'MQTT.SetConfig'), 'von Hand: nichts per MQTT übertragen');
    $zugang3 = MqttZugaenge::nachPraefix($p3);
    gleich($zugang3['zustand'] ?? null, 'angelegt', 'von Hand: Zustand „angelegt“');
    $erg = MqttZugaenge::pruefen((int) $zugang3['id']);
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'anderer Benutzer'), 'vor dem Eintragen: Prüfung lehnt ab – ' . $erg['message']);
    // „Weboberfläche“: Zugang eintragen, speichern, neu starten
    file_put_contents($log3 . '.konfig', (string) json_encode(['user' => $p3, 'pass' => $kennwort3]));
    $stoppen($geraet3);
    [$geraet3, $bereit] = $starten($p3, $log3, $p3, $kennwort3);
    pruefe($bereit, 'von Hand eingetragen, mit eigenem Zugang angemeldet');
    $erg = MqttZugaenge::pruefen((int) $zugang3['id']);
    pruefe($erg['ok'] && (MqttZugaenge::nachPraefix($p3)['zustand'] ?? '') === 'eigen' && $gesperrt($p3), 'von Hand eingerichtet und bestätigt – ' . $erg['message']);
    $stoppen($geraet3);

    // Doppelgänger: zwei Präfixe mit demselben Gerätenamen → kein Zugang
    $dg = $kennung . '-dg';
    $quellen[] = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Prüf-Doppelgänger', 'geraet' => $dg . '-quelle', 'extern_id' => 'shelly/' . $dg, 'profil' => 'gen2', 'aktiv' => 1])['id'];
    $erg = MqttZugaenge::einrichten($dg, 2);
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'mehrere MQTT-Präfixe'), 'Doppelgänger-Präfix: Einrichten abgelehnt – ' . $erg['message']);
    $zeilen = array_column(MqttZugaenge::uebersicht(), null, 'praefix');
    pruefe(($zeilen['shelly/' . $dg]['doppelt'] ?? true) === false, 'Übersicht: einzelnes Präfix ist kein Doppelgänger');

    // --------------------------------------------- Gen1
    $id1 = $kennung . '-G1AB';
    $p1 = 'shellies/' . $id1;
    $log1 = sys_get_temp_dir() . '/' . $kennung . '-gen1.log';
    $dateien[] = $log1;
    $erg = MqttZugaenge::einrichten($p1, 1);
    pruefe($erg['ok'] && ($erg['benutzer'] ?? '') === $id1 && strlen((string) ($erg['kennwort'] ?? '')) === 24, 'Gen1: Zugang angelegt, Kennwort einmal zurück');
    $kennwort1 = (string) ($erg['kennwort'] ?? '');
    $zugang1 = MqttZugaenge::nachPraefix($p1);
    gleich($zugang1['zustand'] ?? null, 'angelegt', 'Gen1: Zustand „angelegt“');
    pruefe(in_array($id1, $konten(), true), 'Gen1-Zugang am Broker (Benutzer = ID)');

    // Noch am gemeinsamen Zugang: die Prüfung schlägt fehl, die Sperre fällt weg
    [$geraet1, $bereit] = $starten($p1, $log1, 'shelly', $kw['shelly']);
    pruefe($bereit, 'Gen1-Attrappe am gemeinsamen Zugang');
    $erg = MqttZugaenge::pruefen((int) $zugang1['id']);
    pruefe(!$erg['ok'] && !$gesperrt($p1), 'Gen1 noch am gemeinsamen Zugang: Prüfung fehlgeschlagen, Sperre weg – ' . $erg['message']);
    $stoppen($geraet1);

    [$geraet1, $bereit] = $starten($p1, $log1, $id1, $kennwort1);
    pruefe($bereit, 'Gen1 mit eigenem Zugang angemeldet');
    $erg = MqttZugaenge::pruefen((int) $zugang1['id']);
    pruefe($erg['ok'] && $gesperrt($p1), 'Gen1 umgestellt, gemeinsamer Zugang gesperrt – ' . $erg['message']);
    $eigen1 = mqttVerbinden($broker, $id1, $kennwort1, $kennung . '-g1-probe');
    pruefe(mqttKommtAn($beobachter, $eigen1, 'shellies/command'), 'Gen1-Zugang liest den Rundruf shellies/command');
    pruefe(!mqttKommtAn($eigen1, $beobachter, 'shellies/announce'), 'Gen1-Zugang schreibt nicht in shellies/announce');
    pruefe(!mqttKommtAn($eigen1, $beobachter, 'shellies/' . $kennung . '-ANDERS/relay/0'), 'Gen1-Zugang schreibt nicht in fremde Gen1-Bäume');
    $eigen1->trennen();
    $stoppen($geraet1);

    $erg = MqttZugaenge::kennwortErneuern((int) $zugang1['id']);
    pruefe($erg['ok'] && isset($erg['kennwort']) && $erg['kennwort'] !== $kennwort1, 'Gen1: neues Kennwort einmal zurück');
    $kennwort1b = (string) ($erg['kennwort'] ?? '');

    // --------------------------------------------- Eindeutigkeit
    $erg = MqttZugaenge::einrichten($p1, 1);
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'schon einen eigenen Zugang'), 'derselbe Zugang kein zweites Mal');
    $erg = MqttZugaenge::einrichten('shellies/' . strtolower($id1), 1);
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'demselben Namen'), 'gleicher Gerätename (nur Groß/Klein anders) abgelehnt');

    // --------------------------------------------- keine Geheimnisse
    $suchen = [$kennwort2, $kennwortNeu, $kennwort3, $kennwort1, $kennwort1b];
    $audit = (string) json_encode(Database::select("SELECT * FROM audit_log WHERE action LIKE 'mqtt_zugang.%'"));
    $soll = (string) @file_get_contents($broker['soll']);
    $ist = (string) @file_get_contents($broker['ist']);
    $log = is_file($logDatei) ? (string) file_get_contents($logDatei, false, null, $logVorher) : '';
    $uebersichtText = (string) json_encode(MqttZugaenge::uebersicht());
    foreach ($suchen as $geheim) {
        foreach (['Audit' => $audit, 'Soll-Datei' => $soll, 'Ergebnis' => $ist, 'app.log' => $log, 'Übersicht' => $uebersichtText] as $wo => $text) {
            if ($geheim !== '' && str_contains($text, $geheim)) {
                pruefe(false, 'Kennwort steht in ' . $wo);
            }
        }
    }
    pruefe(!str_contains($audit . $ist . $log . $uebersichtText, '$7$'), 'kein Hash in Audit, Ergebnis, Log und Übersicht');
    pruefe(str_contains($audit, 'mqtt_zugang.bestaetigt'), 'Umstellung im Audit-Log');

    $gemeinsam->trennen();
    $beobachter->trennen();

    // --------------------------------------------- falsche ACLs
    $grundlage = (string) file_get_contents(APP_ROOT . '/deploy/mosquitto/powermanagement.acl');
    $ohneMuster = mqttTestbroker($kw, preg_replace('/^pattern .*$/m', '', $grundlage));
    Config::set('mqtt.port', $ohneMuster['port']);
    Config::set('mqtt.zugaenge_verzeichnis', $ohneMuster['mqttdir']);
    pruefe(str_contains((string) MqttZugaenge::verfuegbar(), 'Muster'), 'ACL ohne Muster: Einrichten gesperrt');

    $zuOffen = mqttTestbroker($kw, $grundlage . "\npattern readwrite #\n");
    mqttWaechterStarten($zuOffen);
    Config::set('mqtt.port', $zuOffen['port']);
    Config::set('mqtt.zugaenge_verzeichnis', $zuOffen['mqttdir']);
    Database::execute('DELETE FROM mqtt_zugaenge WHERE praefix LIKE ?', ['%' . $kennung . '%']);
    $erg = MqttZugaenge::einrichten('shellies/' . $kennung . '-OFFEN', 1);
    pruefe(!$erg['ok'] && str_contains($erg['message'], 'mehr als seinen eigenen Bereich') && !isset($erg['kennwort']), 'zu offene ACL: Kontoprobe schlägt an, nichts eingerichtet – ' . $erg['message']);
    gleich(MqttZugaenge::nachPraefix('shellies/' . $kennung . '-OFFEN'), null, 'kein Zugang in der Datenbank zurückgeblieben');
} finally {
    alsBenutzer(null);
    foreach ($attrappen as $p) {
        $stoppen($p);
    }
    foreach ($quellen as $id) {
        Datenquellen::loeschen($id);
    }
    Database::execute('DELETE FROM mqtt_zugaenge WHERE praefix LIKE ?', ['%' . $kennung . '%']);
    Database::execute('DELETE FROM schaltvorgaenge WHERE geraet LIKE ?', [$kennung . '%']);
    foreach ($dateien as $f) {
        @unlink($f);
    }
}

ende('mqtt-zugaenge');
