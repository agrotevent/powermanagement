<?php
declare(strict_types=1);

/**
 * Ein Shelly als Attrappe am MQTT-Broker.
 *
 *   php tests/hilfen/shelly-attrappe.php <host> <port> <praefix> <protokolldatei>
 *
 * Gen2: beantwortet JSON-RPC auf <praefix>/rpc an <src>/rpc.
 * Gen1: nimmt <praefix>/relay/N/command an.
 * Jeder empfangene Befehl landet als Zeile in der Protokolldatei. Endet
 * nach 30 Sekunden von selbst.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Messung\Mqtt;

[$_, $host, $port, $praefix, $datei] = $argv;
$mqtt = new Mqtt();
$mqtt->verbinden($host, (int) $port, '', '', 'shelly-attrappe-' . getmypid());
$mqtt->abonnieren([$praefix . '/rpc', $praefix . '/relay/+/command']);
file_put_contents($datei, "bereit\n", FILE_APPEND);
$ende = microtime(true) + 30;
while (microtime(true) < $ende) {
    foreach ($mqtt->lauschen(0.5) as $n) {
        file_put_contents($datei, $n['thema'] . ' ' . $n['nachricht'] . "\n", FILE_APPEND);
        if ($n['thema'] === $praefix . '/rpc') {
            $anfrage = json_decode($n['nachricht'], true);
            if (is_array($anfrage) && isset($anfrage['src'])) {
                $mqtt->veroeffentlichen($anfrage['src'] . '/rpc', (string) json_encode([
                    'id' => $anfrage['id'], 'src' => $praefix, 'dst' => $anfrage['src'], 'result' => ['was_on' => true],
                ]));
            }
        }
    }
}
