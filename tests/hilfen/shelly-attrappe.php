<?php
declare(strict_types=1);

/**
 * Ein Shelly als Attrappe am MQTT-Broker.
 *
 *   php tests/hilfen/shelly-attrappe.php <host> <port> <praefix> <protokolldatei> [<benutzer> <kennwortdatei>]
 *
 * Gen2 (Präfix ohne „shellies/“): beantwortet JSON-RPC auf <praefix>/rpc an
 * <src>/rpc – Shelly.GetDeviceInfo, Sys.GetStatus (uptime seit dem Start,
 * restart_required), MQTT.GetConfig, MQTT.SetConfig (merkt sich Benutzer
 * und Kennwort in <protokolldatei>.konfig, wirksam erst beim nächsten Start
 * – wie ein echter Shelly) und Switch.Set.
 * Gen1 (Präfix shellies/<ID>): nimmt <praefix>/relay/N/command an und
 * schickt auf „update“ (<praefix>/command) seinen Zustand.
 *
 * Mit Benutzer meldet sich die Attrappe an; das Kennwort steht in der
 * Kennwortdatei (nicht in der Prozessliste). Jeder empfangene Befehl landet
 * als Zeile in der Protokolldatei. Endet nach ATTRAPPE_SEKUNDEN (Vorgabe 30)
 * von selbst. ATTRAPPE_FALSCHES_DST=1: antwortet mit falschem dst.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Messung\Mqtt;

[$_, $host, $port, $praefix, $datei] = $argv;
$benutzer = (string) ($argv[5] ?? '');
$kennwort = isset($argv[6]) ? trim((string) @file_get_contents($argv[6])) : '';
$gen1 = str_starts_with($praefix, 'shellies/');
$start = microtime(true);
$neustartNoetig = false;
$konfigDatei = $datei . '.konfig';
$gespeichert = is_file($konfigDatei) ? (array) json_decode((string) file_get_contents($konfigDatei), true) : [];
$gespeicherterBenutzer = (string) ($gespeichert['user'] ?? $benutzer);

$mqtt = new Mqtt();
try {
    $mqtt->verbinden($host, (int) $port, $benutzer, $kennwort, 'shelly-attrappe-' . getmypid());
    $mqtt->abonnieren($gen1
        ? [$praefix . '/relay/+/command', $praefix . '/command', 'shellies/command']
        : [$praefix . '/rpc']);
} catch (\RuntimeException $e) {
    file_put_contents($datei, 'abgelehnt ' . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}
file_put_contents($datei, "bereit\n", FILE_APPEND);
$ende = $start + (float) (getenv('ATTRAPPE_SEKUNDEN') ?: 30);
$teile = explode('/', $praefix);
$id = end($teile);
while (microtime(true) < $ende && $mqtt->verbunden()) {
    try {
        $nachrichten = $mqtt->lauschen(0.5);
    } catch (\RuntimeException) {
        break;
    }
    foreach ($nachrichten as $n) {
        file_put_contents($datei, $n['thema'] . ' ' . $n['nachricht'] . "\n", FILE_APPEND);
        if ($gen1) {
            if ($n['thema'] === $praefix . '/command' && $n['nachricht'] === 'update') {
                $mqtt->veroeffentlichen($praefix . '/relay/0', 'on');
                $mqtt->veroeffentlichen($praefix . '/relay/0/power', '12.5');
            }
            continue;
        }
        if ($n['thema'] !== $praefix . '/rpc') {
            continue;
        }
        $anfrage = json_decode($n['nachricht'], true);
        if (!is_array($anfrage) || !isset($anfrage['src'], $anfrage['method'])) {
            continue;
        }
        $params = (array) ($anfrage['params'] ?? []);
        $antwort = match ((string) $anfrage['method']) {
            'Shelly.GetDeviceInfo' => ['result' => ['id' => $id, 'gen' => 2, 'model' => 'SPSW-201XE16EU', 'ver' => '1.4.4', 'app' => 'Pro1PM']],
            'Sys.GetStatus'        => ['result' => ['uptime' => (int) (microtime(true) - $start), 'restart_required' => $neustartNoetig]],
            'MQTT.GetConfig'       => ['result' => ['enable' => true, 'server' => $host . ':' . $port, 'client_id' => $id,
                'user' => $gespeicherterBenutzer, 'topic_prefix' => $praefix, 'rpc_ntf' => true, 'status_ntf' => true]],
            'MQTT.SetConfig'       => (static function () use ($params, $konfigDatei, &$gespeicherterBenutzer, &$neustartNoetig): array {
                $konfig = (array) ($params['config'] ?? []);
                $bisher = is_file($konfigDatei) ? (array) json_decode((string) file_get_contents($konfigDatei), true) : [];
                file_put_contents($konfigDatei, (string) json_encode(array_merge($bisher, $konfig)));
                if (isset($konfig['user'])) {
                    $gespeicherterBenutzer = (string) $konfig['user'];
                }
                $neustartNoetig = true;
                return ['result' => ['restart_required' => true]];
            })(),
            'Switch.Set'           => ['result' => ['was_on' => true]],
            default                => ['error' => ['code' => 404, 'message' => 'No handler for ' . $anfrage['method']]],
        };
        $dst = getenv('ATTRAPPE_FALSCHES_DST') === '1' ? 'jemand-anderes' : $anfrage['src'];
        try {
            $mqtt->veroeffentlichen($anfrage['src'] . '/rpc', (string) json_encode(['id' => $anfrage['id'], 'src' => $id, 'dst' => $dst] + $antwort));
        } catch (\RuntimeException) {
            break 2;
        }
    }
}
