<?php
declare(strict_types=1);

/**
 * Kacheln einer Etage in einem eigenen Prozess bauen und messen: Zeit,
 * PHP-Speicher und die Spitze des ganzen Prozesses (VmHWM – dort zählt
 * auch, was gd außerhalb von memory_limit hält). Ausgabe als JSON.
 *
 * Aufruf: php -d memory_limit=512M tests/hilfen/kacheln-messen.php <etage-id>
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Services\PlanTileService;
use App\Services\SiteService;

$etage = SiteService::floor((int) ($argv[1] ?? 0));
if ($etage === null) {
    fwrite(STDOUT, json_encode(['ok' => false, 'message' => 'Etage fehlt']));
    exit(1);
}
$start = microtime(true);
$ergebnis = PlanTileService::bauen($etage, true);
preg_match('/VmHWM:\s+(\d+)/', (string) @file_get_contents('/proc/self/status'), $m);
fwrite(STDOUT, (string) json_encode([
    'ok'        => $ergebnis['ok'],
    'message'   => $ergebnis['message'],
    'meta'      => $ergebnis['meta'],
    'sekunden'  => round(microtime(true) - $start, 1),
    'php_mb'    => round(memory_get_peak_usage(true) / 1048576),
    // ohne /proc (kein Linux) bleibt es bei null
    'prozess_mb' => isset($m[1]) ? round(((int) $m[1]) / 1024) : null,
]));
