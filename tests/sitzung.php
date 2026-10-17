<?php
declare(strict_types=1);

/**
 * Sitzungssperre: Seiten mit Livewerten fragen alle 5 s beim Server nach.
 * PHP sperrt die Sitzungsdatei, solange eine Anfrage sie offen hält. Wartet
 * eine Live-Abfrage auf eine träge InfluxDB und hält dabei die Sitzung,
 * stehen alle weiteren Anfragen desselben Browsers an – auch das Speichern
 * der Anschlüsse, das dann im Timeout endet, ohne je beim Audit anzukommen.
 *
 * Geprüft wird, dass die Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche,
 * Tank live und Tankverlauf) die Sitzung freigeben, bevor sie warten: Speichern und Seitenaufrufe
 * kommen durch, während mehrere langsame Live-Abfragen laufen, und die
 * Live-Abfragen liefern trotzdem ihre Antwort.
 *
 * Eigene Instanz (POWERMANAGEMENT_CONFIG, eigene SQLite-Datenbank mit dem
 * Demo-Gelände, dann ohne Demo-Modus) und eine InfluxDB-Attrappe, die jede
 * Abfrage aufhält. Am Ende wird beides weggeräumt.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Tanks\TankRechnung;

$wurzel = dirname(__DIR__);
$kennung = 'pm-sitzung-' . bin2hex(random_bytes(4));
$ordner = sys_get_temp_dir() . '/' . $kennung;
mkdir($ordner . '/sitzungen', 0700, true);
register_shutdown_function(static function () use ($ordner): void {
    // Nur den eigenen Ordner, nach den Servern (die stoppt hintergrund()).
    $alles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($alles as $eintrag) {
        $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
    }
    @rmdir($ordner);
});

// ------------------------------------------------------------ träge InfluxDB
// Wie lange jede Abfrage dauert, steht in einer Datei – beim Laden der Seite
// soll sie nicht warten, bei den Live-Abfragen schon.
$verzoegerung = $ordner . '/verzoegerung';
file_put_contents($verzoegerung, '0');
file_put_contents($ordner . '/influx.php', '<?php
usleep((int) ((float) @file_get_contents(' . var_export($verzoegerung, true) . ') * 1000000));
header("Content-Type: application/json");
echo json_encode(["results" => [["statement_id" => 0], ["statement_id" => 1], ["statement_id" => 2], ["statement_id" => 3]]]);
');
$influxPort = freierPort();
hintergrund(['php', '-S', '127.0.0.1:' . $influxPort, $ordner . '/influx.php'], ['PHP_CLI_SERVER_WORKERS' => '8']);

// ------------------------------------------------------------ eigene Instanz
$vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
$konfig = static function (bool $demo) use ($vorlage, $ordner, $influxPort): string {
    return '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = ' . var_export($demo, true) . ';
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($ordner . '/datenbank.sqlite', true) . ';
$c["influx"]["url"] = "http://127.0.0.1:' . $influxPort . '";
$c["influx"]["timeout"] = 8;
return $c;
';
};
// Das Demo-Gelände gibt es nur im Demo-Modus – also damit anlegen und die
// Instanz danach ohne Demo-Modus betreiben, damit sie die InfluxDB fragt.
file_put_contents($ordner . '/demo.php', $konfig(true));
file_put_contents($ordner . '/config.php', $konfig(false));
$kennwort = 'Sitzung-Pruefung-2026!';
$befehl = static function (array $argumente, string $datei) use ($wurzel): int {
    $p = proc_open(array_merge(['php', $wurzel . '/bin/power'], $argumente), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $rohre, $wurzel, ['POWERMANAGEMENT_CONFIG' => $datei] + getenv());
    return proc_close($p);
};
gleich($befehl(['install', 'pruefer', $kennwort, '--ohne-wechsel'], $ordner . '/demo.php'), 0, 'zweite Instanz eingerichtet');
gleich($befehl(['demo:seed'], $ordner . '/demo.php'), 0, 'Demo-Gelände in der zweiten Instanz angelegt');

$db = new PDO('sqlite:' . $ordner . '/datenbank.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$abfrage = static function (string $sql, array $werte = []) use ($db): array {
    $s = $db->prepare($sql);
    $s->execute($werte);
    return $s->fetchAll();
};
// Ein Generator mit jetzt gültigem Messpunkt – nur der fragt beim Laden die InfluxDB.
$generator = $abfrage(
    "SELECT o.id, o.floor_id FROM objekte o WHERE o.art = 'generator' AND o.ist_vorlage = 0 AND o.floor_id IS NOT NULL
       AND EXISTS (SELECT 1 FROM messpunkte m WHERE m.objekt_id = o.id AND m.gueltig_bis IS NULL AND m.geraet <> '') ORDER BY o.name LIMIT 1"
)[0] ?? null;
pruefe($generator !== null, 'Generator mit Messpunkt im Demo-Gelände gefunden');
$anschluss = $generator !== null
    ? ($abfrage('SELECT id, bezeichnung FROM anschluesse WHERE objekt_id = ? ORDER BY richtung, nr LIMIT 1', [(int) $generator['id']])[0] ?? null)
    : null;
pruefe($anschluss !== null, 'Generator hat einen Anschluss');
if ($generator === null || $anschluss === null) {
    ende('sitzung');
}
$id = (int) $generator['id'];

$port = freierPort();
hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'],
    ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '8']);
for ($i = 0; $i < 50 && !(erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $influxPort)); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $port) || !erreichbar('127.0.0.1', $influxPort)) {
    uebersprungen('sitzung', 'eingebauter PHP-Server startet nicht');
}
$basis = 'http://127.0.0.1:' . $port;
$dose = $ordner . '/dose.txt';

/** Ein curl-Griff mit der Cookie-Dose; folgt keinen Weiterleitungen. */
function griff(string $url, string $dose, ?array $felder = null): CurlHandle
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Accept: ' . ($felder === null && str_contains($url, '/live') ? 'application/json' : 'text/html')],
    ]);
    if ($felder !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    return $c;
}

function holen(string $url, string $dose, ?array $felder = null): array
{
    $c = griff($url, $dose, $felder);
    $text = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['status' => $status, 'text' => $text];
}

/**
 * Langsame Live-Abfragen anstoßen, dann – während sie warten – eine zweite
 * Anfrage schicken. Gibt die Dauer der zweiten Anfrage und die Antworten der
 * Live-Abfragen zurück.
 *
 * @param list<string> $live
 * @return array{dauer:float,antwort:array{status:int,text:string},live:list<array{status:int,json:mixed}>}
 */
function waehrendLive(array $live, string $url, string $dose, ?array $felder = null): array
{
    $mehrfach = curl_multi_init();
    $griffe = [];
    foreach ($live as $liveUrl) {
        $griffe[] = $g = griff($liveUrl, $dose);
        curl_multi_add_handle($mehrfach, $g);
    }
    // Anlaufen lassen, bis alle beim Server sind und warten.
    $bis = microtime(true) + 0.6;
    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.05);
    } while (microtime(true) < $bis);

    $start = microtime(true);
    $antwort = holen($url, $dose, $felder);
    $dauer = microtime(true) - $start;

    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.1);
    } while ($laufend > 0);
    $ergebnisse = [];
    foreach ($griffe as $g) {
        $ergebnisse[] = ['status' => (int) curl_getinfo($g, CURLINFO_RESPONSE_CODE), 'json' => json_decode((string) curl_multi_getcontent($g), true)];
        curl_multi_remove_handle($mehrfach, $g);
        curl_close($g);
    }
    curl_multi_close($mehrfach);
    return ['dauer' => $dauer, 'antwort' => $antwort, 'live' => $ergebnisse];
}

// ------------------------------------------------------------ anmelden, Seite laden
$seite = holen($basis . '/login', $dose);
preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
$anmeldung = holen($basis . '/login', $dose, ['_token' => $m[1] ?? '', 'username' => 'pruefer', 'password' => $kennwort]);
gleich($anmeldung['status'], 302, 'Anmeldung in der zweiten Instanz');
$seite = holen($basis . '/anlagen/' . $id . '?reiter=anschluesse', $dose);
gleich($seite['status'], 200, 'Anschlüsse des Generators');
preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
$token = $m[1] ?? '';
pruefe($token !== '', 'CSRF-Token auf der Seite');

// ------------------------------------------------------------ Speichern bei langsamer InfluxDB
// Drei Live-Abfragen zu je 3 s: Hielte jede die Sitzung, käme das Speichern
// frühestens nach 9 s an die Reihe.
file_put_contents($verzoegerung, '3');
$liveAnlage = $basis . '/anlagen/' . $id . '/live';
$bezeichnung = 'S' . substr(bin2hex(random_bytes(2)), 0, 3);
$lauf = waehrendLive([$liveAnlage, $liveAnlage, $liveAnlage], $basis . '/anlagen/' . $id . '/anschluesse/details', $dose, [
    '_token'    => $token,
    'anschluss' => [(int) $anschluss['id'] => ['bezeichnung' => $bezeichnung, 'schutzorgan_id' => '0', 'phase' => '', 'notizen' => '']],
]);
gleich($lauf['antwort']['status'], 302, 'Anschlüsse speichern, während die Live-Abfragen warten');
pruefe($lauf['dauer'] < 2.0, sprintf('Speichern wartet nicht auf die Live-Abfragen (%.1f s)', $lauf['dauer']));
gleich($abfrage('SELECT bezeichnung FROM anschluesse WHERE id = ?', [(int) $anschluss['id']])[0]['bezeichnung'] ?? null, $bezeichnung, 'Bezeichnung gespeichert');
pruefe($abfrage("SELECT id FROM audit_log WHERE action = 'anlage.anschluesse_details' AND target_id = ?", [(string) $id]) !== [], 'Audit-Eintrag zum Speichern');
foreach ($lauf['live'] as $nr => $l) {
    pruefe($l['status'] === 200 && is_array($l['json']) && isset($l['json']['objekte']), 'Live-Abfrage ' . ($nr + 1) . ' der Anlage liefert trotzdem ihre Antwort');
}

// ------------------------------------------------------------ die anderen Live-Endpunkte
$etage = (int) $generator['floor_id'];
foreach ([
    'Dashboard' => $basis . '/dashboard/live',
    'Karte'     => $basis . '/karte/' . $etage . '/live',
    'Bereiche'  => $basis . '/bereiche/live',
] as $name => $liveUrl) {
    $lauf = waehrendLive([$liveUrl, $liveUrl], $basis . '/profil', $dose);
    gleich($lauf['antwort']['status'], 200, $name . ': andere Seite während der Live-Abfragen');
    pruefe($lauf['dauer'] < 2.0, sprintf('%s: Live-Abfrage hält die Sitzung nicht (%.1f s)', $name, $lauf['dauer']));
    foreach ($lauf['live'] as $l) {
        pruefe($l['status'] === 200 && is_array($l['json']), $name . ': Live-Abfrage liefert trotzdem ihre Antwort');
    }
}

// ------------------------------------------------------------ Tank: Live und Verlauf
// Ein Tank mit Tanksensor und Kennlinie – direkt in die Datenbank der zweiten Instanz.
$jetztIso = gmdate('c');
$db->prepare("INSERT INTO objekte (art, name, site_id, floor_id, plan_x, plan_y, eigenschaften, created_at) VALUES ('tank', ?, ?, ?, 50, 50, ?, ?)")
    ->execute([$kennung . ' Tank', (int) ($abfrage('SELECT site_id FROM floors WHERE id = ?', [$etage])[0]['site_id'] ?? 0), $etage,
        '{"inhalt_l":3000.0,"liter_je_mm":2.78}', $jetztIso]);
$tank = (int) $db->lastInsertId();
$db->prepare("INSERT INTO messpunkte (objekt_id, geraet, kanal, rolle, bezeichnung, gueltig_ab, created_at) VALUES (?, 'pruef-uni', 'voltmeter:100', 'fuellstand', 'TS', ?, ?)")
    ->execute([$tank, gmdate('c', time() - 86400), $jetztIso]);
$rechnung = TankRechnung::rechnung(['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0, 'bereich_bis' => 100,
    'bereich_einheit' => 'mbar'], ['offset_mm' => 20], ['inhalt_l' => 3000, 'liter_je_mm' => 2.78], [])['rechnung'];
$db->prepare("INSERT INTO tank_kennlinien (messpunkt_id, objekt_id, ab, rechnung, anlass, geprueft, von, created_at) VALUES (?, ?, ?, ?, 'einsatz', 0, 'pruefung', ?)")
    ->execute([(int) $db->lastInsertId(), $tank, gmdate('c', time() - 86400), json_encode($rechnung), $jetztIso]);
foreach ([
    'Tank live'   => $basis . '/anlagen/' . $tank . '/live',
    'Tankverlauf' => $basis . '/anlagen/' . $tank . '/tank/verlauf?zeitraum=24h',
] as $name => $liveUrl) {
    $lauf = waehrendLive([$liveUrl, $liveUrl], $basis . '/profil', $dose);
    gleich($lauf['antwort']['status'], 200, $name . ': andere Seite während der Abfragen');
    pruefe($lauf['dauer'] < 2.0, sprintf('%s: Abfrage hält die Sitzung nicht (%.1f s)', $name, $lauf['dauer']));
    foreach ($lauf['live'] as $l) {
        pruefe($l['status'] === 200 && is_array($l['json']), $name . ': Abfrage liefert trotzdem ihre Antwort');
    }
}

// Die Sitzung bleibt dabei gültig: nach allem noch angemeldet.
file_put_contents($verzoegerung, '0');
gleich(holen($basis . '/profil', $dose)['status'], 200, 'nach den Live-Abfragen noch angemeldet');

ende('sitzung');
