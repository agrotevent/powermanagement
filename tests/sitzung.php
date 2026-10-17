<?php
declare(strict_types=1);

/**
 * Sitzungssperre: Seiten mit Livewerten fragen alle 5 s beim Server nach.
 * PHP sperrt die Sitzungsdatei, solange eine Anfrage sie offen hält. Wartet
 * eine Live-Abfrage auf eine träge InfluxDB und hält dabei die Sitzung,
 * stehen alle weiteren Anfragen desselben Browsers an – auch das Speichern
 * der Anschlüsse, das dann im Timeout endet, ohne je beim Audit anzukommen.
 *
 * Geprüft wird, dass die Live-Endpunkte (Anlage, Dashboard, Karte, Bereiche)
 * die Sitzung freigeben, bevor sie warten: Speichern und Seitenaufrufe
 * kommen durch, während mehrere langsame Live-Abfragen laufen, und die
 * Live-Abfragen liefern trotzdem ihre Antwort.
 *
 * Dasselbe für die Eventworx-Abfragen des Berichtsformulars (Suche beim
 * Tippen, Vorgang laden): Das Autosave des Berichts kommt durch, während
 * Eventworx trödelt, und der geladene Vorgang ist danach trotzdem in der
 * Sitzung vorgemerkt – das nächste Autosave übernimmt den Verweis.
 *
 * Eigene Instanz (POWERMANAGEMENT_CONFIG, eigene SQLite-Datenbank mit dem
 * Demo-Gelände, dann ohne Demo-Modus) sowie Attrappen für InfluxDB und
 * Eventworx, die jede Abfrage aufhalten. Eventworx hängt über
 * `eventworx.basis` aus der Konfiguration der Instanz an der Attrappe – der
 * einzige Weg zu einer anderen Adresse als https://<mandant>.eventworx.biz.
 * Am Ende wird alles weggeräumt.
 */

require __DIR__ . '/hilfen/pruefung.php';

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

// ------------------------------------------------------------ träges Eventworx
// Antwortet wie der Mandant (`job` als Liste, `job/<id>` als Datensatz) mit
// dem Auftrag aus der Demo, nur mit dem richtigen Token, und schreibt jeden
// Pfad mit – so steht fest, dass wirklich gefragt wurde.
$ewxVerzoegerung = $ordner . '/eventworx-verzoegerung';
file_put_contents($ewxVerzoegerung, '0');
$ewxToken = 'pruefung-' . bin2hex(random_bytes(12));
$ewxJob = App\Services\Eventworx::demo()[0];
file_put_contents($ordner . '/eventworx-job.json', json_encode($ewxJob));
file_put_contents($ordner . '/eventworx.php', '<?php
usleep((int) ((float) @file_get_contents(' . var_export($ewxVerzoegerung, true) . ') * 1000000));
$pfad = (string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH);
file_put_contents(' . var_export($ordner . '/eventworx-pfade', true) . ', $pfad . "\n", FILE_APPEND | LOCK_EX);
header("Content-Type: application/json");
if (($_SERVER["HTTP_S_API_TOKEN"] ?? "") !== ' . var_export($ewxToken, true) . ') {
    http_response_code(401);
    echo json_encode(["success" => false, "errorCode" => "ACCESS_DENIED"]);
    return;
}
$job = json_decode((string) file_get_contents(' . var_export($ordner . '/eventworx-job.json', true) . '), true);
if ($pfad === "/backend/job") {
    echo json_encode(["success" => true, "total" => 1, "data" => [$job]]);
} elseif ($pfad === "/backend/job/" . $job["id"]) {
    echo json_encode(["success" => true, "data" => $job]);
} else {
    http_response_code(404);
    echo json_encode(["success" => false, "errorCode" => "NOT_FOUND"]);
}
');
$ewxPort = freierPort();
hintergrund(['php', '-S', '127.0.0.1:' . $ewxPort, $ordner . '/eventworx.php'], ['PHP_CLI_SERVER_WORKERS' => '8']);
$ewxBasis = 'http://127.0.0.1:' . $ewxPort;

// ------------------------------------------------------------ eigene Instanz
$vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
$konfig = static function (bool $demo) use ($vorlage, $ordner, $influxPort, $ewxBasis): string {
    return '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = ' . var_export($demo, true) . ';
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($ordner . '/datenbank.sqlite', true) . ';
$c["influx"]["url"] = "http://127.0.0.1:' . $influxPort . '";
$c["influx"]["timeout"] = 8;
$c["eventworx"]["basis"] = ' . var_export($ewxBasis, true) . ';
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
$alleDa = static fn (): bool => erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $influxPort) && erreichbar('127.0.0.1', $ewxPort);
for ($i = 0; $i < 50 && !$alleDa(); $i++) {
    usleep(100_000);
}
if (!$alleDa()) {
    uebersprungen('sitzung', 'eingebauter PHP-Server startet nicht');
}
$basis = 'http://127.0.0.1:' . $port;
$dose = $ordner . '/dose.txt';

/**
 * Ein curl-Griff mit der Cookie-Dose; folgt keinen Weiterleitungen. JSON
 * erwarten die Live-Abfragen und die Wege des Berichtsformulars ($json).
 */
function griff(string $url, string $dose, ?array $felder = null, ?bool $json = null): CurlHandle
{
    $json ??= $felder === null && str_contains($url, '/live');
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Accept: ' . ($json ? 'application/json' : 'text/html')],
    ]);
    if ($felder !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    return $c;
}

/** @return array{status:int,text:string,ziel:string} */
function holen(string $url, string $dose, ?array $felder = null, ?bool $json = null): array
{
    $c = griff($url, $dose, $felder, $json);
    $text = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $ziel = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL);
    curl_close($c);
    return ['status' => $status, 'text' => $text, 'ziel' => $ziel];
}

/**
 * Langsame Abfragen anstoßen, dann – während sie warten – eine zweite
 * Anfrage schicken. Gibt die Dauer der zweiten Anfrage und die Antworten der
 * langsamen Abfragen zurück. Eine langsame Abfrage ist eine Adresse (GET)
 * oder [Adresse, Formularfelder] (POST, JSON erwartet).
 *
 * @param list<string|array{0:string,1:array}> $live
 * @return array{dauer:float,antwort:array{status:int,text:string,ziel:string},live:list<array{status:int,json:mixed}>}
 */
function waehrendLive(array $live, string $url, string $dose, ?array $felder = null, ?bool $json = null): array
{
    $mehrfach = curl_multi_init();
    $griffe = [];
    foreach ($live as $liveUrl) {
        $griffe[] = $g = is_array($liveUrl) ? griff($liveUrl[0], $dose, $liveUrl[1], true) : griff($liveUrl, $dose);
        curl_multi_add_handle($mehrfach, $g);
    }
    // Anlaufen lassen, bis alle beim Server sind und warten.
    $bis = microtime(true) + 0.6;
    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.05);
    } while (microtime(true) < $bis);

    $start = microtime(true);
    $antwort = holen($url, $dose, $felder, $json);
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

// ------------------------------------------------------------ Eventworx im Berichtsformular
// Suche beim Tippen und „Vorgang laden“ fragen Eventworx (bis zu 12 s je
// Versuch, dazu Drossel und Nachlesen). Hielten sie die Sitzung, stünde das
// Autosave des Berichts so lange an.
file_put_contents($verzoegerung, '0');
$r = holen($basis . '/system/einstellungen/eventworx', $dose, ['_token' => $token, 'eventworx_adresse' => $ewxBasis, 'eventworx_token' => $ewxToken]);
gleich($r['status'], 302, 'Eventworx der zweiten Instanz auf die Attrappe eingestellt');
$einstellung = array_column($abfrage("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('eventworx.host', 'eventworx.token_host')"), 'setting_value', 'setting_key');
gleich($einstellung, ['eventworx.host' => '127.0.0.1:' . $ewxPort, 'eventworx.token_host' => '127.0.0.1:' . $ewxPort], 'Host und Token-Host der Attrappe (eventworx.basis)');
$r = holen($basis . '/berichte/anlegen', $dose, ['_token' => $token, 'leer' => '1']);
$berichtId = preg_match('#/berichte/(\d+)$#', $r['ziel'], $m) === 1 ? (int) $m[1] : 0;
pruefe($r['status'] === 302 && $berichtId > 0, 'Bericht ohne Standort angelegt');
$stand = static function () use ($abfrage, $berichtId): array {
    $z = $abfrage('SELECT version, inhalt, ewx_id, ewx_nummer FROM berichte WHERE id = ?', [$berichtId])[0] ?? [];
    return ['version' => (int) ($z['version'] ?? 0), 'inhalt' => (string) ($z['inhalt'] ?? '{}'), 'ewx_id' => (string) ($z['ewx_id'] ?? ''), 'ewx_nummer' => (string) ($z['ewx_nummer'] ?? '')];
};
$autosave = static function (string $ewx = '') use ($basis, $berichtId, $token, $stand): array {
    $s = $stand();
    return [$basis . '/berichte/' . $berichtId . '/speichern', ['_token' => $token, 'version' => (string) $s['version'], 'inhalt' => $s['inhalt'], 'ewx' => $ewx]];
};

// Suche und Vorgang zugleich, je 3 s bei Eventworx: Hielte jede die Sitzung,
// käme das Autosave frühestens nach gut 5 s an die Reihe.
file_put_contents($ewxVerzoegerung, '3');
@unlink($ordner . '/eventworx-pfade');
$nummer = (string) $ewxJob['jobNumber'];
[$speichernUrl, $speichernFelder] = $autosave();
$lauf = waehrendLive([
    [$basis . '/berichte/' . $berichtId . '/eventworx/suche', ['_token' => $token, 'q' => $nummer]],
    [$basis . '/berichte/' . $berichtId . '/eventworx/vorgang', ['_token' => $token, 'vorgang_id' => (string) $ewxJob['id']]],
], $speichernUrl, $dose, $speichernFelder, true);
$gespeichert = json_decode($lauf['antwort']['text'], true);
pruefe($lauf['antwort']['status'] === 200 && ($gespeichert['ok'] ?? false) === true, 'Autosave des Berichts, während Eventworx wartet (' . $lauf['antwort']['status'] . ')');
pruefe($lauf['dauer'] < 2.0, sprintf('Autosave wartet nicht auf die Eventworx-Abfragen (%.1f s)', $lauf['dauer']));
[$suche, $vorgang] = $lauf['live'];
pruefe($suche['status'] === 200 && ($suche['json']['treffer'][0]['nummer'] ?? '') === $nummer, 'Eventworx-Suche liefert trotzdem ihre Treffer');
pruefe($vorgang['status'] === 200 && ($vorgang['json']['verweis']['nummer'] ?? '') === $nummer && ($vorgang['json']['gruppen']['kunde'] ?? []) !== [],
    'Eventworx-Vorgang liefert trotzdem Verweis und Gruppen');
$pfade = is_file($ordner . '/eventworx-pfade') ? (file($ordner . '/eventworx-pfade', FILE_IGNORE_NEW_LINES) ?: []) : [];
sort($pfade);
gleich($pfade, ['/backend/job', '/backend/job/' . $ewxJob['id']], 'beide Abfragen gingen an die Eventworx-Attrappe');

// Der Vorgang hat sich trotz freigegebener Sitzung vorgemerkt: Das nächste
// Autosave übernimmt den Verweis (sonst: „in dieser Sitzung nicht geladen“).
file_put_contents($ewxVerzoegerung, '0');
[$speichernUrl, $speichernFelder] = $autosave((string) $ewxJob['id']);
$r = holen($speichernUrl, $dose, $speichernFelder, true);
$j = json_decode($r['text'], true);
pruefe($r['status'] === 200 && ($j['ok'] ?? false) === true && ($j['hinweise'] ?? []) === [], 'Autosave mit dem geladenen Vorgang ohne Hinweis');
$s = $stand();
gleich([$s['ewx_id'], $s['ewx_nummer']], [(string) $ewxJob['id'], $nummer], 'Verweis auf den Vorgang gespeichert – vorgemerkt in der Sitzung');

// Die Sitzung bleibt dabei gültig: nach allem noch angemeldet.
gleich(holen($basis . '/profil', $dose)['status'], 200, 'nach den Live- und Eventworx-Abfragen noch angemeldet');

// Abgemeldet, während Eventworx wartet: Der Vorgang schreibt nicht in eine
// beendete oder neue Sitzung und liefert keine Kundendaten mehr aus.
file_put_contents($ewxVerzoegerung, '2');
$lauf = waehrendLive([[$basis . '/berichte/' . $berichtId . '/eventworx/vorgang', ['_token' => $token, 'vorgang_id' => (string) $ewxJob['id']]]],
    $basis . '/logout', $dose, ['_token' => $token]);
gleich($lauf['antwort']['status'], 302, 'abmelden, während Eventworx wartet');
$nachAbmelden = $lauf['live'][0];
pruefe($nachAbmelden['status'] === 401 && ($nachAbmelden['json']['anmelden'] ?? false) === true && !isset($nachAbmelden['json']['gruppen']),
    'Vorgang nach dem Abmelden: 401 ohne Kundendaten (' . $nachAbmelden['status'] . ')');
gleich(holen($basis . '/profil', $dose)['status'], 302, 'danach abgemeldet');

ende('sitzung');
