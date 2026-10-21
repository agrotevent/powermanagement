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
 * Dasselbe für die Eventworx-Abfragen des Berichtsformulars (Suche auf
 * Knopfdruck oder mit Enter, Vorgang laden): Das Autosave des Berichts kommt
 * durch, während Eventworx trödelt, und der geladene Vorgang ist danach
 * trotzdem in der Sitzung vorgemerkt (Session::nachtragen()) – das nächste
 * Autosave übernimmt den Verweis. Wer sich in der Zwischenzeit abmeldet,
 * bekommt keine Kundendaten mehr.
 *
 * Und für Anfragen an andere Instanzen (bis 60 s, Plandateien bis 180 s,
 * Session::ohneSperre()): Die Seite einer Instanz und „Prüfen“ warten auf
 * eine träge Gegenseite, eine andere Seite und ein Formular aus einem
 * zweiten Reiter kommen währenddessen durch. Danach ist die Sitzung wieder
 * offen – die Meldung, die „Prüfen“ erst nach der Antwort von dort schreibt,
 * kommt an, und was der zweite Reiter in der Zwischenzeit geschrieben hat,
 * bleibt.
 *
 * Und was daraus folgt: Zweimal „Übernehmen“ (Doppelklick) laufen jetzt
 * nebeneinander – die Sperre der Location lässt nur eins durch, das zweite
 * hört sofort mit einer Meldung auf, es gibt einen Standort. Ändert der
 * zweite Reiter das Kennwort oder meldet sich ab, während „Prüfen“ wartet,
 * legt „Prüfen“ danach keine neue, leere Sitzung an: angemeldet bleibt, wer
 * das Kennwort geändert hat, abgemeldet, wer sich abgemeldet hat. Beide
 * Wege öffnen die Sitzung wieder, ohne ein zweites Sitzungscookie zu
 * schicken.
 *
 * Und für alles andere, das auf etwas draußen wartet: Das Dashboard fragt
 * beim Laden die InfluxDB, Eventworx „Verbindung testen“, „Prüfen“ in den
 * Einstellungen (InfluxDB, Broker), „Jetzt abfragen“ einer VRM-Datenquelle,
 * „Einheiten abrufen“ bei ComAp, Schalten eines Shelly und die MQTT-Zugänge
 * (Übertragen, Übersicht) warten auf Cloud, Broker oder Gerät, „Repository
 * abfragen“ im Location-Abgleich auf git. Eine andere Seite kommt jeweils
 * währenddessen durch, die Meldung danach an. Im Location-Abgleich laufen
 * zwei Klicks damit nebeneinander: Die Sperre der Arbeitskopie lässt nur
 * einen durch, der zweite hört sofort mit einer Meldung auf – auch ein
 * „Hochladen“, während der Verteiler-Abgleich das Repository abfragt.
 *
 * Eigene Instanz (POWERMANAGEMENT_CONFIG, eigene SQLite-Datenbank mit dem
 * Demo-Gelände, dann ohne Demo-Modus) sowie Attrappen für InfluxDB,
 * Eventworx und eine andere Instanz (/api/v1), die jede Abfrage aufhalten.
 * Eventworx hängt über `eventworx.basis` aus der Konfiguration der Instanz
 * an der Attrappe – der einzige Weg zu einer anderen Adresse als
 * https://<mandant>.eventworx.biz. VRM und ComAp haben feste Adressen; nur
 * der Server der Instanz leitet sie über auto_prepend_file (Http::$attrappe)
 * an eine träge Attrappe um. Der Broker der Instanz nimmt Verbindungen an
 * und schweigt; ihr git ist ein Wrapper vorn im PATH, der vor jeder Anfrage
 * an ein file://-Repository wartet. Die Arbeitskopie in storage/inventar
 * liegt währenddessen beiseite. Am Ende wird alles weggeräumt.
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
if ($pfad === "/backend/check/ping") {
    echo json_encode(["success" => true, "data" => "pong"]);
} elseif ($pfad === "/backend/job") {
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

// ------------------------------------------------------------ träge Clouds
// Victron VRM (Diagnose) und ComAp WebSupervisor (Anmeldung, Einheiten) in
// einer Attrappe, die wie die anderen so lange wartet, wie in ihrer Datei
// steht. Die Adressen der Clouds sind fest (VictronVrm::BASIS,
// ComapCloud::BASIS); nur der Server der zweiten Instanz leitet sie über
// auto_prepend_file (Http::$attrappe, wie in tests/cloud.php) hierher um –
// am Code ändert das nichts, alles andere geht unverändert hinaus.
$cloudVerzoegerung = $ordner . '/cloud-verzoegerung';
file_put_contents($cloudVerzoegerung, '0');
$vrmToken = 'vrm-' . bin2hex(random_bytes(8));
$comapEinheit = 'Träges Aggregat ' . $kennung;
file_put_contents($ordner . '/cloud.php', '<?php
usleep((int) ((float) @file_get_contents(' . var_export($cloudVerzoegerung, true) . ') * 1000000));
$pfad = (string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH);
file_put_contents(' . var_export($ordner . '/cloud-pfade', true) . ', $pfad . "\n", FILE_APPEND | LOCK_EX);
header("Content-Type: application/json");
if (preg_match("#^/vrm/installations/[^/]+/diagnostics$#", $pfad) === 1) {
    if (($_SERVER["HTTP_X_AUTHORIZATION"] ?? "") !== ' . var_export('Token ' . $vrmToken, true) . ') {
        http_response_code(401);
        echo json_encode(["success" => false, "errors" => "Invalid token"]);
        return;
    }
    echo json_encode(["success" => true, "records" => [["dbusServiceType" => "system", "dbusPath" => "/Ac/Consumption/L1/Power", "rawValue" => 1234]]]);
} elseif ($pfad === "/comap/identity/application/authenticate") {
    echo json_encode(["access_token" => "comap-pruefung", "expires_in" => 3600]);
} elseif (preg_match("#^/comap/v1\\.1/[^/]+/units$#", $pfad) === 1 && ($_SERVER["HTTP_AUTHORIZATION"] ?? "") === "Bearer comap-pruefung") {
    echo json_encode(["units" => [["name" => ' . var_export($comapEinheit, true) . ', "unitGuid" => "guid-pruefung"]]], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo "{}";
}
');
$cloudPort = freierPort();
hintergrund(['php', '-S', '127.0.0.1:' . $cloudPort, $ordner . '/cloud.php'], ['PHP_CLI_SERVER_WORKERS' => '4']);
$umleiten = [App\Messung\VictronVrm::BASIS => 'http://127.0.0.1:' . $cloudPort . '/vrm', App\Messung\ComapCloud::BASIS => 'http://127.0.0.1:' . $cloudPort . '/comap'];
file_put_contents($ordner . '/cloud-umleitung.php', '<?php
// tests/sitzung.php: VRM und ComAp dieser Instanz an die träge Attrappe.
require ' . var_export($wurzel . '/src/Messung/Http.php', true) . ';
\App\Messung\Http::$attrappe = static function (string $methode, string $url, array $optionen): array {
    foreach (' . var_export($umleiten, true) . ' as $echt => $hier) {
        if (str_starts_with($url, $echt . "/")) {
            $url = $hier . substr($url, strlen($echt));
        }
    }
    $selbst = \App\Messung\Http::$attrappe;
    \App\Messung\Http::$attrappe = null;
    try {
        return \App\Messung\Http::anfrage($methode, $url, $optionen);
    } finally {
        \App\Messung\Http::$attrappe = $selbst;
    }
};
');

// ------------------------------------------------------------ stummer Broker
// Nimmt Verbindungen an und schweigt: Wer schaltet, MQTT-Zugänge überträgt
// oder den Broker prüft, wartet auf die Antwort zur Anmeldung (5 s) – wie
// bei einem Broker oder Gerät hinter einer hängenden Leitung.
file_put_contents($ordner . '/mqtt-stumm.php', '<?php
$server = stream_socket_server("tcp://127.0.0.1:" . (int) $argv[1], $nr, $text);
$offen = [];
while (true) {
    $verbindung = @stream_socket_accept($server, 60);
    if ($verbindung !== false) {
        $offen[] = $verbindung;
    }
}
');
$mqttPort = freierPort();
hintergrund(['php', $ordner . '/mqtt-stumm.php', (string) $mqttPort]);
// Der Broker-Abgleich der Instanz ist „eingerichtet“ (Ergebnis des Helfers),
// damit die MQTT-Zugänge bis zum Broker kommen.
mkdir($ordner . '/mqtt/ist', 0700, true);
mkdir($ordner . '/mqtt/soll', 0700, true);
file_put_contents($ordner . '/mqtt/ist/ergebnis.txt', "# powermanagement mqtt-zugaenge-ergebnis 1\nlauf 1\nzeit " . time()
    . "\nstatus ok\nacl_wirksam ja\nbasis_muster ja\nsperre_moeglich ja\n");

// ------------------------------------------------------------ träges Repository
// git der Instanz ist ein Wrapper vorn im PATH ihres Servers: Was das
// Repository fragt (clone, fetch, ls-remote, push), wartet so lange, wie in
// der Datei steht – ein Repository hinter einer langsamen Leitung. Alles
// andere geht sofort an das echte git.
$echtesGit = App\Support\Programm::finden('git');
$gitVerzoegerung = $ordner . '/git-verzoegerung';
file_put_contents($gitVerzoegerung, '0');
mkdir($ordner . '/bin', 0700, true);
if ($echtesGit !== null) {
    file_put_contents($ordner . '/bin/git', "#!/bin/sh\ncase \"\$1\" in\n    clone|fetch|ls-remote|push) sleep \"\$(cat " . escapeshellarg($gitVerzoegerung) . ")\" ;;\nesac\nexec "
        . escapeshellarg($echtesGit) . " \"\$@\"\n");
    chmod($ordner . '/bin/git', 0700);
}

// ------------------------------------------------------------ eigene Instanz
$vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
$konfig = static function (bool $demo) use ($vorlage, $ordner, $influxPort, $ewxBasis, $mqttPort): string {
    return '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = ' . var_export($demo, true) . ';
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($ordner . '/datenbank.sqlite', true) . ';
$c["influx"]["url"] = "http://127.0.0.1:' . $influxPort . '";
$c["influx"]["timeout"] = 8;
$c["eventworx"]["basis"] = ' . var_export($ewxBasis, true) . ';
$c["mqtt"]["host"] = "127.0.0.1";
$c["mqtt"]["port"] = ' . $mqttPort . ';
$c["mqtt"]["zugaenge_verzeichnis"] = ' . var_export($ordner . '/mqtt', true) . ';
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
// PHP-Meldungen der Instanz (etwa beim Wiederöffnen der Sitzung) in eine eigene Datei.
$phpFehler = $ordner . '/php-fehler.log';
hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'error_log=' . $phpFehler,
    '-d', 'auto_prepend_file=' . $ordner . '/cloud-umleitung.php', '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'],
    ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '8', 'PATH' => $ordner . '/bin:' . (string) getenv('PATH')]);
$alleDa = static fn (): bool => erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $influxPort) && erreichbar('127.0.0.1', $ewxPort)
    && erreichbar('127.0.0.1', $cloudPort) && erreichbar('127.0.0.1', $mqttPort);
for ($i = 0; $i < 50 && !$alleDa(); $i++) {
    usleep(100_000);
}
if (!$alleDa()) {
    uebersprungen('sitzung', 'eingebauter PHP-Server startet nicht');
}
$basis = 'http://127.0.0.1:' . $port;

/**
 * Die Cookies aller Anfragen – wie im Browser eine Ablage für alle Reiter:
 * Es gilt, was zuletzt per Set-Cookie kam. Eine Cookie-Datei je Griff
 * schriebe beim Schließen ihren Stand zurück; die langsame Anfrage, die
 * zuletzt fertig wird, überschriebe so das Cookie aus dem zweiten Reiter,
 * auch ohne eigenes Set-Cookie.
 */
function cookies(): CurlShareHandle
{
    static $ablage = null;
    if ($ablage === null) {
        $ablage = curl_share_init();
        curl_share_setopt($ablage, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }
    return $ablage;
}

/**
 * Ein curl-Griff mit den gemeinsamen Cookies; folgt keinen Weiterleitungen.
 * JSON erwarten die Live-Abfragen und die Wege des Berichtsformulars
 * ($json). Die Kopfzeilen der Antwort landen in $kopf.
 */
function griff(string $url, ?array $felder = null, ?bool $json = null, ?string &$kopf = null): CurlHandle
{
    $json ??= $felder === null && str_contains($url, '/live');
    $kopf = '';
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SHARE          => cookies(),
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Accept: ' . ($json ? 'application/json' : 'text/html')],
        CURLOPT_HEADERFUNCTION => static function ($c, string $zeile) use (&$kopf): int {
            $kopf .= $zeile;
            return strlen($zeile);
        },
    ]);
    if ($felder !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    return $c;
}

/** @return array{status:int,text:string,ziel:string,kopf:string} */
function holen(string $url, ?array $felder = null, ?bool $json = null): array
{
    $c = griff($url, $felder, $json, $kopf);
    $text = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $ziel = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL);
    curl_close($c);
    return ['status' => $status, 'text' => $text, 'ziel' => $ziel, 'kopf' => (string) $kopf];
}

/** Setzt die Antwort ein Sitzungscookie? */
function sitzungsCookie(string $kopf): bool
{
    return preg_match('/^set-cookie:\s*' . preg_quote((string) App\Support\Config::get('security.session_name', 'pm_session'), '/') . '=/mi', $kopf) === 1;
}

/**
 * Langsame Anfragen anstoßen (Live-Abfragen, Eventworx, Seiten, die auf eine
 * andere Instanz warten), dann – während sie warten – eine zweite Anfrage
 * schicken. Gibt die Dauer der zweiten Anfrage und die Antworten der
 * langsamen zurück. Eine langsame Anfrage ist eine Adresse (GET) oder
 * [Adresse, Formularfelder] (POST) bzw. [Adresse, Formularfelder, true]
 * (POST, JSON erwartet).
 *
 * @param list<string|array{0:string,1:array<string,mixed>,2?:bool}> $langsam
 * @return array{dauer:float,antwort:array{status:int,text:string,ziel:string,kopf:string},langsam:list<array{status:int,text:string,json:mixed,kopf:string}>}
 */
function waehrendLangsam(array $langsam, string $url, ?array $felder = null, ?bool $json = null): array
{
    $mehrfach = curl_multi_init();
    $griffe = [];
    $koepfe = [];
    foreach ($langsam as $nr => $anfrage) {
        $koepfe[$nr] = '';
        $griffe[$nr] = $g = is_array($anfrage) ? griff($anfrage[0], $anfrage[1], $anfrage[2] ?? null, $koepfe[$nr]) : griff($anfrage, null, null, $koepfe[$nr]);
        curl_multi_add_handle($mehrfach, $g);
    }
    // Anlaufen lassen, bis alle beim Server sind und warten.
    $bis = microtime(true) + 0.6;
    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.05);
    } while (microtime(true) < $bis);

    $start = microtime(true);
    $antwort = holen($url, $felder, $json);
    $dauer = microtime(true) - $start;

    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.1);
    } while ($laufend > 0);
    $ergebnisse = [];
    foreach ($griffe as $nr => $g) {
        $text = (string) curl_multi_getcontent($g);
        $ergebnisse[] = ['status' => (int) curl_getinfo($g, CURLINFO_RESPONSE_CODE), 'text' => $text, 'json' => json_decode($text, true), 'kopf' => $koepfe[$nr]];
        curl_multi_remove_handle($mehrfach, $g);
        curl_close($g);
    }
    curl_multi_close($mehrfach);
    return ['dauer' => $dauer, 'antwort' => $antwort, 'langsam' => $ergebnisse];
}

// ------------------------------------------------------------ anmelden, Seite laden
/** Über die Anmeldeseite anmelden; gibt den Status der Anmeldung zurück. */
$anmelden = static function (string $kennwort) use ($basis): int {
    $seite = holen($basis . '/login');
    preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
    return holen($basis . '/login', ['_token' => $m[1] ?? '', 'username' => 'pruefer', 'password' => $kennwort])['status'];
};
gleich($anmelden($kennwort), 302, 'Anmeldung in der zweiten Instanz');
$seite = holen($basis . '/anlagen/' . $id . '?reiter=anschluesse');
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
$lauf = waehrendLangsam([$liveAnlage, $liveAnlage, $liveAnlage], $basis . '/anlagen/' . $id . '/anschluesse/details', [
    '_token'    => $token,
    'anschluss' => [(int) $anschluss['id'] => ['bezeichnung' => $bezeichnung, 'schutzorgan_id' => '0', 'phase' => '', 'notizen' => '']],
]);
gleich($lauf['antwort']['status'], 302, 'Anschlüsse speichern, während die Live-Abfragen warten');
pruefe($lauf['dauer'] < 2.0, sprintf('Speichern wartet nicht auf die Live-Abfragen (%.1f s)', $lauf['dauer']));
gleich($abfrage('SELECT bezeichnung FROM anschluesse WHERE id = ?', [(int) $anschluss['id']])[0]['bezeichnung'] ?? null, $bezeichnung, 'Bezeichnung gespeichert');
pruefe($abfrage("SELECT id FROM audit_log WHERE action = 'anlage.anschluesse_details' AND target_id = ?", [(string) $id]) !== [], 'Audit-Eintrag zum Speichern');
foreach ($lauf['langsam'] as $nr => $l) {
    pruefe($l['status'] === 200 && is_array($l['json']) && isset($l['json']['objekte']), 'Live-Abfrage ' . ($nr + 1) . ' der Anlage liefert trotzdem ihre Antwort');
}

// ------------------------------------------------------------ die anderen Live-Endpunkte
$etage = (int) $generator['floor_id'];
foreach ([
    'Dashboard' => $basis . '/dashboard/live',
    'Karte'     => $basis . '/karte/' . $etage . '/live',
    'Bereiche'  => $basis . '/bereiche/live',
] as $name => $liveUrl) {
    $lauf = waehrendLangsam([$liveUrl, $liveUrl], $basis . '/profil');
    gleich($lauf['antwort']['status'], 200, $name . ': andere Seite während der Live-Abfragen');
    pruefe($lauf['dauer'] < 2.0, sprintf('%s: Live-Abfrage hält die Sitzung nicht (%.1f s)', $name, $lauf['dauer']));
    foreach ($lauf['langsam'] as $l) {
        pruefe($l['status'] === 200 && is_array($l['json']), $name . ': Live-Abfrage liefert trotzdem ihre Antwort');
    }
}

// Die Sitzung bleibt dabei gültig: nach allem noch angemeldet.
file_put_contents($verzoegerung, '0');
gleich(holen($basis . '/profil')['status'], 200, 'nach den Live-Abfragen noch angemeldet');

// ------------------------------------------------------------ Eventworx im Berichtsformular
// Die Suche (Knopf oder Enter) und „Vorgang laden“ fragen Eventworx (bis zu
// 12 s je Versuch, dazu Drossel und Nachlesen). Hielten sie die Sitzung,
// stünde das Autosave des Berichts so lange an.
file_put_contents($verzoegerung, '0');
$r = holen($basis . '/system/einstellungen/eventworx', ['_token' => $token, 'eventworx_adresse' => $ewxBasis, 'eventworx_token' => $ewxToken]);
gleich($r['status'], 302, 'Eventworx der zweiten Instanz auf die Attrappe eingestellt');
$einstellung = array_column($abfrage("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('eventworx.host', 'eventworx.token_host')"), 'setting_value', 'setting_key');
gleich($einstellung, ['eventworx.host' => '127.0.0.1:' . $ewxPort, 'eventworx.token_host' => '127.0.0.1:' . $ewxPort], 'Host und Token-Host der Attrappe (eventworx.basis)');
$r = holen($basis . '/berichte/anlegen', ['_token' => $token, 'leer' => '1']);
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
$lauf = waehrendLangsam([
    [$basis . '/berichte/' . $berichtId . '/eventworx/suche', ['_token' => $token, 'q' => $nummer], true],
    [$basis . '/berichte/' . $berichtId . '/eventworx/vorgang', ['_token' => $token, 'vorgang_id' => (string) $ewxJob['id']], true],
], $speichernUrl, $speichernFelder, true);
$gespeichert = json_decode($lauf['antwort']['text'], true);
pruefe($lauf['antwort']['status'] === 200 && ($gespeichert['ok'] ?? false) === true, 'Autosave des Berichts, während Eventworx wartet (' . $lauf['antwort']['status'] . ')');
pruefe($lauf['dauer'] < 2.0, sprintf('Autosave wartet nicht auf die Eventworx-Abfragen (%.1f s)', $lauf['dauer']));
[$suche, $vorgang] = $lauf['langsam'];
pruefe($suche['status'] === 200 && ($suche['json']['treffer'][0]['nummer'] ?? '') === $nummer, 'Eventworx-Suche liefert trotzdem ihre Treffer');
pruefe($vorgang['status'] === 200 && ($vorgang['json']['verweis']['nummer'] ?? '') === $nummer && ($vorgang['json']['gruppen']['kunde'] ?? []) !== [],
    'Eventworx-Vorgang liefert trotzdem Verweis und Gruppen');
pruefe(!sitzungsCookie($vorgang['kopf']), 'Eventworx-Vorgang: die kurz wieder geöffnete Sitzung schickt kein zweites Sitzungscookie');
$pfade = is_file($ordner . '/eventworx-pfade') ? (file($ordner . '/eventworx-pfade', FILE_IGNORE_NEW_LINES) ?: []) : [];
sort($pfade);
gleich($pfade, ['/backend/job', '/backend/job/' . $ewxJob['id']], 'beide Abfragen gingen an die Eventworx-Attrappe');

// Der Vorgang hat sich trotz freigegebener Sitzung vorgemerkt: Das nächste
// Autosave übernimmt den Verweis (sonst: „in dieser Sitzung nicht geladen“).
file_put_contents($ewxVerzoegerung, '0');
[$speichernUrl, $speichernFelder] = $autosave((string) $ewxJob['id']);
$r = holen($speichernUrl, $speichernFelder, true);
$j = json_decode($r['text'], true);
pruefe($r['status'] === 200 && ($j['ok'] ?? false) === true && ($j['hinweise'] ?? []) === [], 'Autosave mit dem geladenen Vorgang ohne Hinweis');
$s = $stand();
gleich([$s['ewx_id'], $s['ewx_nummer']], [(string) $ewxJob['id'], $nummer], 'Verweis auf den Vorgang gespeichert – vorgemerkt in der Sitzung');

// Die Sitzung bleibt dabei gültig: nach allem noch angemeldet.
gleich(holen($basis . '/profil')['status'], 200, 'nach den Live- und Eventworx-Abfragen noch angemeldet');

// Abgemeldet, während Eventworx wartet: Der Vorgang schreibt nicht in eine
// beendete oder neue Sitzung und liefert keine Kundendaten mehr aus.
file_put_contents($ewxVerzoegerung, '2');
$lauf = waehrendLangsam([[$basis . '/berichte/' . $berichtId . '/eventworx/vorgang', ['_token' => $token, 'vorgang_id' => (string) $ewxJob['id']], true]],
    $basis . '/logout', ['_token' => $token]);
gleich($lauf['antwort']['status'], 302, 'abmelden, während Eventworx wartet');
$nachAbmelden = $lauf['langsam'][0];
pruefe($nachAbmelden['status'] === 401 && ($nachAbmelden['json']['anmelden'] ?? false) === true && !isset($nachAbmelden['json']['gruppen']),
    'Vorgang nach dem Abmelden: 401 ohne Kundendaten (' . $nachAbmelden['status'] . ')');
pruefe(!sitzungsCookie($nachAbmelden['kopf']), 'Vorgang nach dem Abmelden: kein Cookie für eine neue, leere Sitzung');
gleich(count(glob($ordner . '/sitzungen/sess_*') ?: []), 0, 'nach dem Abmelden legt der Vorgang keine Sitzung an');
gleich(holen($basis . '/profil')['status'], 302, 'danach abgemeldet');

// Für die Anfragen an andere Instanzen wieder anmelden.
gleich($anmelden($kennwort), 302, 'nach dem Abmelden wieder angemeldet');

// ------------------------------------------------------------ träge andere Instanz
// Eine Gegenseite mit /api/v1/info und /api/v1/locations, die – wie die
// InfluxDB oben – so lange wartet, wie in ihrer Datei steht: eine andere
// Instanz hinter einer langsamen Leitung.
$partnerVerzoegerung = $ordner . '/partner-verzoegerung';
file_put_contents($partnerVerzoegerung, '0');
$partnerToken = 'mtma_' . bin2hex(random_bytes(20));
$partnerLocation = 'Träge Halle ' . $kennung;
// Mit Grundriss: Übernehmen wartet zweimal auf dort (Location, dann Datei).
$partnerPlan = $wurzel . '/tests/hilfen/location-lager/plaene/grundriss.svg';
file_put_contents($ordner . '/partner.php', '<?php
usleep((int) ((float) @file_get_contents(' . var_export($partnerVerzoegerung, true) . ') * 1000000));
header("Content-Type: application/json");
if (($_SERVER["HTTP_AUTHORIZATION"] ?? "") !== ' . var_export('Bearer ' . $partnerToken, true) . ') {
    http_response_code(401);
    echo json_encode(["ok" => false, "message" => "Token falsch"]);
    return;
}
$pfad = parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($pfad === "/api/v1/locations/traege-halle/datei" && ($_GET["pfad"] ?? "") === "plaene/eg.svg") {
    header("Content-Type: image/svg+xml");
    readfile(' . var_export($partnerPlan, true) . ');
    return;
}
$antwort = match ($pfad) {
    "/api/v1/info"      => ["ok" => true, "instanz" => "Träge Gegenseite", "produkt" => "powermanagement", "token" => ["befugnisse" => ["plaene.lesen"]]],
    "/api/v1/locations" => ["ok" => true, "locations" => [["schluessel" => "traege-halle", "name" => ' . var_export($partnerLocation, true) . ', "stand" => "1a2b",
        "etagen" => [["schluessel" => "eg", "name" => "Erdgeschoss", "plan" => true]]]]],
    "/api/v1/locations/traege-halle" => ["ok" => true, "stand" => "1a2b", "location" => ["format" => 1, "art" => "location",
        "schluessel" => "traege-halle", "name" => ' . var_export($partnerLocation, true) . ', "adresse" => "", "notizen" => "",
        "etagen" => [["schluessel" => "eg", "name" => "Erdgeschoss", "ebene" => 0, "reihenfolge" => 0, "massstab" => "", "notizen" => "",
            "plan" => ["datei" => "plaene/eg.svg", "typ" => "image", "seite" => 1, "originalname" => "eg.svg", "sha256" => ' . var_export(hash_file('sha256', $partnerPlan), true) . ']]]]],
    default             => null,
};
if ($antwort === null) {
    http_response_code(404);
    $antwort = ["ok" => false, "message" => "Gibt es hier nicht."];
}
echo json_encode($antwort, JSON_UNESCAPED_UNICODE);
');
$partnerPort = freierPort();
hintergrund(['php', '-S', '127.0.0.1:' . $partnerPort, $ordner . '/partner.php'], ['PHP_CLI_SERVER_WORKERS' => '4']);
for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $partnerPort); $i++) {
    usleep(100_000);
}
pruefe(erreichbar('127.0.0.1', $partnerPort), 'Attrappe der anderen Instanz läuft');
$angelegt = inB($ordner . '/config.php', 'return \\App\\Services\\PartnerService::anlegen("Träge Gegenseite", '
    . var_export('http://127.0.0.1:' . $partnerPort, true) . ', ' . var_export($partnerToken, true) . ');');
pruefe(($angelegt['ok'] ?? false) === true, 'andere Instanz eingetragen' . (($angelegt['ok'] ?? false) === true ? '' : ': ' . (string) ($angelegt['message'] ?? '')));
$partnerId = (int) ($angelegt['id'] ?? 0);
$instanzSeite = $basis . '/instanzen/' . $partnerId;

// ------------------------------------------------------------ Seite der Instanz wartet auf dort
// Die Seite holt die Liste der Locations von dort – 3 s. Hielte sie dabei
// die Sitzung, käme die andere Seite erst danach an die Reihe.
file_put_contents($partnerVerzoegerung, '3');
$lauf = waehrendLangsam([$instanzSeite], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'andere Seite, während die Seite der Instanz auf dort wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('Seite der Instanz hält die Sitzung nicht, während sie auf dort wartet (%.1f s)', $lauf['dauer']));
$instanz = $lauf['langsam'][0];
gleich($instanz['status'], 200, 'Seite der Instanz nach der Antwort von dort');
pruefe(str_contains($instanz['text'], $partnerLocation), 'Seite der Instanz zeigt die Location von dort');
preg_match('/name="_token" value="([^"]+)"/', $instanz['text'], $m);
$token = $m[1] ?? '';
pruefe($token !== '', 'Seite der Instanz mit CSRF-Token (Sitzung wieder offen)');

// ------------------------------------------------------------ „Prüfen“ wartet auf dort
// Währenddessen trägt ein zweiter Reiter eine Instanz mit falschem Token ein:
// Meldung und Eingabe landen in der Sitzung, nach dort geht nichts. Die
// Meldung von „Prüfen“ entsteht erst nach der Antwort von dort.
$geprueftVorher = (string) ($abfrage('SELECT verified_at FROM api_partner WHERE id = ?', [$partnerId])[0]['verified_at'] ?? '');
$zweiterReiter = 'Zweiter Reiter ' . $kennung;
$lauf = waehrendLangsam([[$instanzSeite . '/pruefen', ['_token' => $token]]], $basis . '/instanzen/anlegen',
    ['_token' => $token, 'name' => $zweiterReiter, 'adresse' => 'http://127.0.0.1:9', 'token' => 'kein-token']);
gleich($lauf['antwort']['status'], 302, 'zweiter Reiter: Eintragen, während „Prüfen“ auf dort wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('„Prüfen“ hält die Sitzung nicht, während es auf dort wartet (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, '„Prüfen“ leitet nach der Antwort von dort weiter');
pruefe(!sitzungsCookie($lauf['langsam'][0]['kopf']), '„Prüfen“: die wieder geöffnete Sitzung schickt kein zweites Sitzungscookie');
pruefe((string) ($abfrage('SELECT verified_at FROM api_partner WHERE id = ?', [$partnerId])[0]['verified_at'] ?? '') !== $geprueftVorher, '„Prüfen“ hat dort nachgefragt');

file_put_contents($partnerVerzoegerung, '0');
$danach = holen($instanzSeite);
gleich($danach['status'], 200, 'Seite der Instanz nach „Prüfen“');
pruefe(str_contains($danach['text'], 'Die Verbindung steht'), 'Meldung von „Prüfen“ nach der Antwort von dort kommt an');
pruefe(str_contains($danach['text'], 'Das ist kein Token'), 'Meldung des zweiten Reiters aus der Wartezeit bleibt erhalten');
pruefe(str_contains(holen($basis . '/instanzen')['text'], $zweiterReiter), 'Eingabe des zweiten Reiters aus der Wartezeit bleibt erhalten');
gleich(holen($basis . '/profil')['status'], 200, 'nach den Anfragen an die andere Instanz noch angemeldet');

// ------------------------------------------------------------ Doppelklick auf „Übernehmen“
// Beide Anfragen warten auf dort (Location 2 s, Grundriss 2 s), ohne die
// Sitzung zu halten – also nebeneinander. Ohne Sperre der Location sähen
// beide „noch nicht hier“ und legten je einen Standort an. Die zweite
// wartet nicht auf die Sperre (sie hielte dabei die Sitzung, die erste käme
// nie zurück), sie hört auf.
// Der Grundriss landet in storage/plans – derselbe Speicher wie hier; am
// Ende geht nur weg, was es vorher nicht gab.
$planeVorher = glob($wurzel . '/storage/plans/*') ?: [];
file_put_contents($partnerVerzoegerung, '2');
$uebernehmen = [$instanzSeite . '/uebernehmen', ['_token' => $token, 'schluessel' => 'traege-halle', 'aktion' => 'uebernehmen']];
$lauf = waehrendLangsam([$uebernehmen], $uebernehmen[0], $uebernehmen[1]);
gleich($lauf['antwort']['status'], 302, 'Doppelklick: das zweite „Übernehmen“ leitet weiter');
pruefe($lauf['dauer'] < 1.5, sprintf('Doppelklick: das zweite „Übernehmen“ wartet nicht auf die Sperre (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, 'Doppelklick: das erste „Übernehmen“ leitet nach der Antwort von dort weiter');
file_put_contents($partnerVerzoegerung, '0');
$angelegte = $abfrage("SELECT id FROM sites WHERE partner_id = ? AND partner_schluessel = 'traege-halle'", [$partnerId]);
gleich(count($angelegte), 1, 'Doppelklick auf „Übernehmen“ legt einen Standort an, nicht zwei');
gleich(count($abfrage('SELECT id FROM sites WHERE name = ?', [$partnerLocation])), 1, 'kein zweiter Standort mit dem Namen von dort');
$danach = holen($instanzSeite);
pruefe(str_contains($danach['text'], 'wird gerade schon übernommen'), 'Doppelklick: das zweite „Übernehmen“ meldet, dass es schon läuft');
pruefe(str_contains($danach['text'], '1 Etage(n), 1 Grundriss(e) übernommen'), 'Doppelklick: das erste „Übernehmen“ meldet, was es übernommen hat');
$sperrDatei = (string) inB($ordner . '/config.php', 'return (new ReflectionMethod(\\App\\Services\\PartnerAbgleich::class, "sperrDatei"))->invoke(null, '
    . $partnerId . ', "traege-halle");');
pruefe(str_ends_with($sperrDatei, '.sperre') && !file_exists($sperrDatei), 'Sperrdatei nach dem Übernehmen wieder weg');
// Danach geht es wieder: auf denselben Standort, ohne Meldung „schon“.
$nochmal = holen($uebernehmen[0], $uebernehmen[1]);
gleich($nochmal['status'], 302, 'noch einmal „Übernehmen“, nachdem das erste fertig ist');
gleich(count($abfrage("SELECT id FROM sites WHERE partner_id = ? AND partner_schluessel = 'traege-halle'", [$partnerId])), 1,
    '„Übernehmen“ danach geht auf denselben Standort');
pruefe(!str_contains(holen($instanzSeite)['text'], 'wird gerade schon übernommen'), '„Übernehmen“ danach ist nicht gesperrt');

// ------------------------------------------------------------ Kennwort ändern, während „Prüfen“ wartet
// Der Kennwortwechsel im zweiten Reiter vergibt eine neue Sitzungs-ID und
// löscht die alte. „Prüfen“ fände danach seine Sitzung nicht mehr; legte es
// eine neue, leere an, überschriebe ihr Cookie das neue – abgemeldet, und
// „Passwort geändert.“ wäre weg.
$neuesKennwort = 'Sitzung-Neu-2026!' . bin2hex(random_bytes(3));
file_put_contents($partnerVerzoegerung, '3');
$lauf = waehrendLangsam([[$instanzSeite . '/pruefen', ['_token' => $token]]], $basis . '/profil/passwort',
    ['_token' => $token, 'current_password' => $kennwort, 'new_password' => $neuesKennwort, 'new_password_repeat' => $neuesKennwort]);
gleich($lauf['antwort']['status'], 302, 'zweiter Reiter: Kennwort ändern, während „Prüfen“ auf dort wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('Kennwort ändern wartet nicht auf „Prüfen“ (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, '„Prüfen“ leitet nach dem Kennwortwechsel weiter');
pruefe(sitzungsCookie($lauf['antwort']['kopf']) && !sitzungsCookie($lauf['langsam'][0]['kopf']),
    'Kennwortwechsel setzt das neue Sitzungscookie, „Prüfen“ danach keins');
file_put_contents($partnerVerzoegerung, '0');
$profil = holen($basis . '/profil');
gleich($profil['status'], 200, 'nach dem Kennwortwechsel im zweiten Reiter noch angemeldet');
pruefe(str_contains($profil['text'], 'Passwort geändert'), 'Meldung des Kennwortwechsels kommt an');
gleich(count(glob($ordner . '/sitzungen/sess_*') ?: []), 1, 'nach dem Kennwortwechsel genau eine Sitzung (keine leere dazu)');
preg_match('/name="_token" value="([^"]+)"/', $profil['text'], $m);
$token = $m[1] ?? '';

// ------------------------------------------------------------ Abmelden, während „Prüfen“ wartet
// Danach legt „Prüfen“ keine anonyme Sitzung an: seine Meldung stünde sonst
// auf der Anmeldeseite und überdauerte die nächste Anmeldung.
file_put_contents($partnerVerzoegerung, '3');
$lauf = waehrendLangsam([[$instanzSeite . '/pruefen', ['_token' => $token]]], $basis . '/logout', ['_token' => $token]);
gleich($lauf['antwort']['status'], 302, 'zweiter Reiter: abmelden, während „Prüfen“ auf dort wartet');
gleich($lauf['langsam'][0]['status'], 302, '„Prüfen“ leitet nach dem Abmelden weiter');
pruefe(!sitzungsCookie($lauf['langsam'][0]['kopf']), '„Prüfen“ nach dem Abmelden: kein Cookie für eine neue, leere Sitzung');
file_put_contents($partnerVerzoegerung, '0');
gleich(count(glob($ordner . '/sitzungen/sess_*') ?: []), 0, 'nach dem Abmelden legt „Prüfen“ keine neue Sitzung an');
$anmeldeseite = holen($basis . '/login');
gleich($anmeldeseite['status'], 200, 'Anmeldeseite nach dem Abmelden');
pruefe(!str_contains($anmeldeseite['text'], 'Die Verbindung steht'), 'Meldung von „Prüfen“ landet nicht auf der Anmeldeseite');
gleich(holen($basis . '/profil')['status'], 302, 'nach dem Abmelden abgemeldet');

// ------------------------------------------------------------ wieder anmelden
// Für Seiten, Geräte, Clouds und das Repository – mit dem neuen Kennwort.
gleich($anmelden($neuesKennwort), 302, 'nach dem Abmelden wieder angemeldet (neues Kennwort)');
preg_match('/name="_token" value="([^"]+)"/', holen($basis . '/profil')['text'], $m);
$token = $m[1] ?? '';
pruefe($token !== '', 'CSRF-Token nach der neuen Anmeldung');

// ------------------------------------------------------------ Seite fragt beim Laden die InfluxDB
// Das Dashboard holt seine Livewerte schon beim Aufbau der Seite – bis zu
// influx.timeout. Das lief bisher mit gesperrter Sitzung; jetzt gibt
// Influx::abfragenAlle() sie frei, solange es wartet.
file_put_contents($verzoegerung, '3');
$lauf = waehrendLangsam([$basis . '/dashboard'], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'Dashboard: andere Seite, während das Dashboard auf die InfluxDB wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('Dashboard: hält die Sitzung nicht, während es auf die InfluxDB wartet (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 200, 'Dashboard nach der Antwort der InfluxDB');
pruefe(str_contains($lauf['langsam'][0]['text'], 'name="_token"'), 'Dashboard mit CSRF-Token (Sitzung wieder offen)');
file_put_contents($verzoegerung, '0');

// ------------------------------------------------------------ Eventworx „Verbindung testen“
// check/ping und eine Seite der Aufträge, je 2 s. Die Meldung entsteht erst
// nach der Antwort von dort.
file_put_contents($ewxVerzoegerung, '2');
@unlink($ordner . '/eventworx-pfade');
$lauf = waehrendLangsam([[$basis . '/system/einstellungen/eventworx/pruefen', ['_token' => $token]]], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'Eventworx: andere Seite, während „Verbindung testen“ wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('Eventworx: „Verbindung testen“ hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, 'Eventworx: „Verbindung testen“ leitet nach der Antwort weiter');
pruefe(!sitzungsCookie($lauf['langsam'][0]['kopf']), 'Eventworx: „Verbindung testen“ schickt kein zweites Sitzungscookie');
file_put_contents($ewxVerzoegerung, '0');
$pfade = is_file($ordner . '/eventworx-pfade') ? (file($ordner . '/eventworx-pfade', FILE_IGNORE_NEW_LINES) ?: []) : [];
sort($pfade);
gleich($pfade, ['/backend/check/ping', '/backend/job'], 'Eventworx: „Verbindung testen“ fragte die Attrappe');
pruefe(str_contains(holen($basis . '/system/einstellungen')['text'], 'Verbunden mit 127.0.0.1:' . $ewxPort), 'Eventworx: Meldung von „Verbindung testen“ kommt an');

// ------------------------------------------------------------ „Prüfen“: InfluxDB und Broker
// Der Broker schweigt: „Prüfen“ wartet 5 s auf die Antwort zur Anmeldung.
$lauf = waehrendLangsam([[$basis . '/system/einstellungen/pruefen', ['_token' => $token]]], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'Einstellungen: andere Seite, während „Prüfen“ auf den Broker wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('Einstellungen: „Prüfen“ hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, 'Einstellungen: „Prüfen“ leitet nach dem Warten weiter');
$seite = holen($basis . '/system/einstellungen')['text'];
pruefe(str_contains($seite, 'InfluxDB erreichbar') && str_contains($seite, 'MQTT-Broker antwortet nicht auf die Anmeldung'),
    'Einstellungen: Meldungen von „Prüfen“ zu InfluxDB und Broker kommen an');

// ------------------------------------------------------------ Clouds: VRM „Jetzt abfragen“, ComAp „Einheiten abrufen“
$vrmName = 'Träges VRM ' . $kennung;
$comapName = 'Träges ComAp ' . $kennung;
gleich(holen($basis . '/datenquellen/speichern', ['_token' => $token, 'art' => 'victron_vrm', 'name' => $vrmName, 'extern_id' => '424242',
    'zugang' => ['token' => $vrmToken]])['status'], 302, 'VRM-Datenquelle angelegt');
gleich(holen($basis . '/datenquellen/speichern', ['_token' => $token, 'art' => 'comap_cloud', 'name' => $comapName, 'extern_id' => 'guid-pruefung',
    'zugang' => ['comap_key' => 'schluessel', 'client_id' => 'client-' . $kennung, 'secret' => 'geheim', 'login_id' => 'pruefer']])['status'], 302, 'ComAp-Datenquelle angelegt');
$quelleId = static fn (string $name): int => (int) ($abfrage('SELECT id FROM datenquellen WHERE name = ?', [$name])[0]['id'] ?? 0);
$vrmId = $quelleId($vrmName);
$comapId = $quelleId($comapName);
pruefe($vrmId > 0 && $comapId > 0, 'beide Cloud-Datenquellen in der Datenbank der Instanz');
// Das ComAp-Token legt die Instanz in storage/cache ab – derselbe Ordner wie hier.
$comapCache = $wurzel . '/storage/cache/comap-' . $comapId . '.json';
$comapCacheVorher = is_file($comapCache) ? (string) file_get_contents($comapCache) : null;
register_shutdown_function(static function () use ($comapCache, $comapCacheVorher): void {
    $comapCacheVorher === null ? @unlink($comapCache) : file_put_contents($comapCache, $comapCacheVorher);
});

@unlink($ordner . '/cloud-pfade');
file_put_contents($cloudVerzoegerung, '3');
$lauf = waehrendLangsam([[$basis . '/datenquellen/testen', ['_token' => $token, 'id' => (string) $vrmId]]], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'VRM: andere Seite, während „Jetzt abfragen“ auf VRM wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('VRM: „Jetzt abfragen“ hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, 'VRM: „Jetzt abfragen“ leitet nach der Antwort weiter');
pruefe(!sitzungsCookie($lauf['langsam'][0]['kopf']), 'VRM: „Jetzt abfragen“ schickt kein zweites Sitzungscookie');
$seite = holen($basis . '/datenquellen')['text'];
pruefe(str_contains($seite, $vrmName . ' antwortet.') && str_contains($seite, 'p=1234'), 'VRM: Meldung mit den Werten von dort kommt an');

file_put_contents($cloudVerzoegerung, '2');
$lauf = waehrendLangsam([[$basis . '/datenquellen/comap-einheiten', ['_token' => $token, 'id' => (string) $comapId]]], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'ComAp: andere Seite, während „Einheiten abrufen“ auf ComAp wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('ComAp: „Einheiten abrufen“ hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, 'ComAp: „Einheiten abrufen“ leitet nach der Antwort weiter');
file_put_contents($cloudVerzoegerung, '0');
pruefe(str_contains(holen($basis . '/datenquellen?bearbeiten=' . $comapId)['text'], 'Einheiten in WebSupervisor: ' . $comapEinheit . ' = guid-pruefung'),
    'ComAp: Meldung mit den Einheiten von dort kommt an');
$pfade = is_file($ordner . '/cloud-pfade') ? (file($ordner . '/cloud-pfade', FILE_IGNORE_NEW_LINES) ?: []) : [];
gleich($pfade, ['/vrm/installations/424242/diagnostics', '/comap/identity/application/authenticate', '/comap/v1.1/pruefer/units'],
    'VRM und ComAp gingen an die Attrappe');

// ------------------------------------------------------------ Schalten wartet auf den Broker
// Ein Shelly (Gen2) aus dem Demo-Gelände; der Broker schweigt 5 s.
$shelly = $abfrage("SELECT id FROM datenquellen WHERE art = 'shelly' AND profil <> 'gen1' ORDER BY name LIMIT 1")[0] ?? null;
pruefe($shelly !== null, 'Shelly (Gen2) im Demo-Gelände');
if ($shelly !== null) {
    $lauf = waehrendLangsam([[$basis . '/steuerung/schalten', ['_token' => $token, 'quelle' => (string) $shelly['id'], 'befehl' => 'ein', 'kanal' => 'switch:0'], true]],
        $basis . '/profil');
    gleich($lauf['antwort']['status'], 200, 'Schalten: andere Seite, während das Schalten auf den Broker wartet');
    pruefe($lauf['dauer'] < 1.5, sprintf('Schalten: hält die Sitzung nicht, während es auf den Broker wartet (%.1f s)', $lauf['dauer']));
    $schalten = $lauf['langsam'][0];
    pruefe($schalten['status'] === 422 && str_contains((string) ($schalten['json']['message'] ?? ''), 'MQTT-Broker antwortet nicht auf die Anmeldung'),
        'Schalten: meldet nach dem Warten, dass der Broker nicht antwortet (' . $schalten['status'] . ')');
    $vorgang = $abfrage('SELECT v.ergebnis, u.username FROM schaltvorgaenge v LEFT JOIN users u ON u.id = v.user_id WHERE v.datenquelle_id = ? ORDER BY v.id DESC LIMIT 1',
        [(int) $shelly['id']])[0] ?? [];
    gleich([$vorgang['ergebnis'] ?? null, $vorgang['username'] ?? null], ['fehler', 'pruefer'], 'Schalten: im Protokoll, mit dem Benutzer der Sitzung');
}

// ------------------------------------------------------------ MQTT-Zugänge warten auf Broker und Gerät
// Ein Gen2-Zugang zum Übertragen und ein bestätigter (dann fragt die
// Übersicht den Broker nach der Schutzliste).
$zugaenge = [];
foreach (['uebertragen' => 'angelegt', 'eigen' => 'eigen'] as $zweck => $zustand) {
    $praefix = 'sitzung' . $zweck . '-' . bin2hex(random_bytes(3));
    $jetzt = gmdate('c');
    $db->prepare("INSERT INTO mqtt_zugaenge (benutzer, praefix, geraet, generation, kennwort_hash, zustand, neustart_noetig, kennwort_am, meldung, created_at, updated_at)
        VALUES (?, ?, ?, 2, 'pruefung', ?, 0, ?, '', ?, ?)")->execute([$praefix, $praefix, $praefix, $zustand, $jetzt, $jetzt, $jetzt]);
    $zugaenge[$zweck] = (int) $db->lastInsertId();
}
$lauf = waehrendLangsam([[$basis . '/datenquellen/mqtt-zugaenge/uebertragen', ['_token' => $token, 'id' => (string) $zugaenge['uebertragen']]]], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'MQTT-Zugänge: andere Seite, während „Übertragen“ auf den Broker wartet');
pruefe($lauf['dauer'] < 1.5, sprintf('MQTT-Zugänge: „Übertragen“ hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 302, 'MQTT-Zugänge: „Übertragen“ leitet nach dem Warten weiter');
$lauf = waehrendLangsam([$basis . '/datenquellen/mqtt-zugaenge'], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'MQTT-Zugänge: andere Seite, während die Übersicht nach der Schutzliste fragt');
pruefe($lauf['dauer'] < 1.5, sprintf('MQTT-Zugänge: die Übersicht hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
gleich($lauf['langsam'][0]['status'], 200, 'MQTT-Zugänge: Übersicht nach dem Warten');
// Die Meldung von „Übertragen“ zeigt die erste Seite danach – hier die andere.
pruefe(str_contains($lauf['antwort']['text'] . $lauf['langsam'][0]['text'], 'MQTT-Broker antwortet nicht auf die Anmeldung'),
    'MQTT-Zugänge: Meldung von „Übertragen“ nach dem Warten auf den Broker kommt an');

// ------------------------------------------------------------ träges Repository: Location- und Verteiler-Abgleich
// Ein Repository auf dieser Maschine (file://) mit einer Location; der
// git-Wrapper der Instanz lässt jede Anfrage dorthin 1,5 s warten.
$gitLocation = 'sitzung-' . bin2hex(random_bytes(3));
if ($echtesGit === null) {
    fwrite(STDOUT, "sitzung: Location- und Verteiler-Abgleich übersprungen – git fehlt\n");
} else {
    // Die Arbeitskopie (storage/inventar) gilt für jede Instanz dieses
    // Codes – die der Installation also beiseitelegen und danach zurück.
    $inventar = $wurzel . '/storage/inventar';
    $inventarBeiseite = is_dir($inventar) ? $inventar . '.pruefung-' . bin2hex(random_bytes(3)) : null;
    if ($inventarBeiseite !== null) {
        rename($inventar, $inventarBeiseite);
    }
    register_shutdown_function(static function () use ($inventar, $inventarBeiseite): void {
        if (is_dir($inventar)) {
            $alles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inventar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($alles as $eintrag) {
                $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
            }
            @rmdir($inventar);
        }
        if ($inventarBeiseite !== null) {
            rename($inventarBeiseite, $inventar);
        }
    });
    $gitHier = static function (string ...$argumente) use ($echtesGit): string {
        exec(escapeshellarg($echtesGit) . ' -c user.name=Pruefung -c user.email=pruefung@local '
            . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $aus, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('git ' . implode(' ', $argumente) . ': ' . implode("\n", $aus));
        }
        return implode("\n", $aus);
    };
    $fern = $ordner . '/fern.git';
    $masteradmin = $ordner . '/masteradmin';
    $gitName = 'Träges Lager ' . $kennung;
    $lager = json_decode((string) file_get_contents($wurzel . '/tests/hilfen/location-lager/location.json'), true);
    $lager['schluessel'] = $gitLocation;
    $lager['name'] = $gitName;
    mkdir($masteradmin . '/locations/' . $gitLocation . '/plaene', 0700, true);
    file_put_contents($masteradmin . '/locations/' . $gitLocation . '/location.json', json_encode($lager, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    copy($wurzel . '/tests/hilfen/location-lager/plaene/grundriss.svg', $masteradmin . '/locations/' . $gitLocation . '/plaene/grundriss.svg');
    $gitHier('init', '-q', '--bare', '-b', 'main', $fern);
    $gitHier('init', '-q', '-b', 'main', $masteradmin);
    $gitHier('-C', $masteradmin, 'add', '-A');
    $gitHier('-C', $masteradmin, 'commit', '-q', '-m', 'Location aus MasterAdmin');
    $gitHier('-C', $masteradmin, 'push', '-q', $fern, 'main');
    $imRepo = static fn (): string => $gitHier('--git-dir=' . $fern, 'ls-tree', '-r', '--name-only', 'main');
    $r = holen($basis . '/system/einstellungen/location', ['_token' => $token, 'repo_url' => 'file://' . $fern, 'branch' => 'main', 'token' => '']);
    gleich($r['status'], 302, 'Repository der Instanz eingestellt (file://, träge über den git-Wrapper)');

    // „Repository abfragen“: klonen, nachsehen, holen – jedes Mal 1,5 s.
    file_put_contents($gitVerzoegerung, '1.5');
    $lauf = waehrendLangsam([$basis . '/location?holen=1'], $basis . '/profil');
    gleich($lauf['antwort']['status'], 200, 'Location-Abgleich: andere Seite, während „Repository abfragen“ wartet');
    pruefe($lauf['dauer'] < 1.5, sprintf('Location-Abgleich: „Repository abfragen“ hält die Sitzung nicht, während git wartet (%.1f s)', $lauf['dauer']));
    gleich($lauf['langsam'][0]['status'], 200, 'Location-Abgleich nach der Antwort des Repositories');
    pruefe(str_contains($lauf['langsam'][0]['text'], $gitName), 'Location-Abgleich zeigt die Location aus dem Repository');

    // Doppelklick auf „Übernehmen“: Beide warten auf das Repository, ohne
    // die Sitzung zu halten – also nebeneinander. Die Sperre der
    // Arbeitskopie lässt nur eins durch; das zweite hört sofort auf.
    $uebernehmenGit = [$basis . '/location/uebernehmen', ['_token' => $token, 'schluessel' => $gitLocation]];
    $lauf = waehrendLangsam([$uebernehmenGit], $uebernehmenGit[0], $uebernehmenGit[1]);
    gleich($lauf['antwort']['status'], 302, 'Location-Doppelklick: das zweite „Übernehmen“ leitet weiter');
    pruefe($lauf['dauer'] < 1.5, sprintf('Location-Doppelklick: das zweite „Übernehmen“ wartet weder auf die Sitzung noch auf die Sperre (%.1f s)', $lauf['dauer']));
    gleich($lauf['langsam'][0]['status'], 302, 'Location-Doppelklick: das erste „Übernehmen“ leitet nach der Antwort des Repositories weiter');
    gleich(count($abfrage('SELECT id FROM sites WHERE sync_key = ?', [$gitLocation])), 1, 'Location-Doppelklick: ein Standort, nicht zwei');
    $danach = holen($basis . '/location')['text'];
    pruefe(str_contains($danach, 'wird gerade schon abgeglichen'), 'Location-Doppelklick: das zweite „Übernehmen“ meldet, dass schon abgeglichen wird');
    pruefe(str_contains($danach, $gitName . ' übernommen'), 'Location-Doppelklick: das erste „Übernehmen“ meldet, was es übernommen hat');
    $gitSite = (int) ($abfrage('SELECT id FROM sites WHERE sync_key = ?', [$gitLocation])[0]['id'] ?? 0);

    // Während der Verteiler-Abgleich das Repository abfragt, hält er die
    // Arbeitskopie: „Hochladen“ im zweiten Reiter hört auf, statt sie unter
    // ihm zurückzusetzen, und wartet dabei auch nicht auf die Sitzung.
    $lauf = waehrendLangsam([$basis . '/verteiler-abgleich?holen=1'], $basis . '/location/hochladen', ['_token' => $token, 'site_id' => (string) $gitSite]);
    gleich($lauf['antwort']['status'], 302, 'Verteiler-Abgleich fragt: „Hochladen“ im zweiten Reiter leitet weiter');
    pruefe($lauf['dauer'] < 1.5, sprintf('Verteiler-Abgleich fragt: „Hochladen“ wartet weder auf die Sitzung noch auf die Sperre (%.1f s)', $lauf['dauer']));
    gleich($lauf['langsam'][0]['status'], 200, 'Verteiler-Abgleich nach der Antwort des Repositories');
    pruefe(!str_contains($imRepo(), 'locations/' . $gitLocation . '/strom.json'), 'Verteiler-Abgleich fragt: „Hochladen“ hat nichts ins Repository geschrieben');
    pruefe(str_contains($lauf['langsam'][0]['text'] . holen($basis . '/location')['text'], 'wird gerade schon abgeglichen'),
        'Verteiler-Abgleich fragt: „Hochladen“ meldet, dass schon abgeglichen wird');

    // Danach ist nichts mehr gesperrt: „Hochladen“ kommt im Repository an.
    file_put_contents($gitVerzoegerung, '0');
    gleich(holen($basis . '/location/hochladen', ['_token' => $token, 'site_id' => (string) $gitSite])['status'], 302, '„Hochladen“, nachdem der Abgleich fertig ist');
    pruefe(str_contains($imRepo(), 'locations/' . $gitLocation . '/strom.json'), '„Hochladen“ danach ist nicht gesperrt und kommt im Repository an');
    pruefe(!str_contains(holen($basis . '/location')['text'], 'wird gerade schon abgeglichen'), '„Hochladen“ danach meldet kein „schon“');
}
gleich(holen($basis . '/profil')['status'], 200, 'nach Seiten, Geräten, Clouds und Repository noch angemeldet');

// Grundrisse der übernommenen Standorte (andere Instanz, Repository) aus storage/plans.
foreach ($abfrage('SELECT f.plan_file FROM floors f JOIN sites s ON s.id = f.site_id WHERE s.partner_id = ? OR s.sync_key = ?', [$partnerId, $gitLocation]) as $f) {
    $plan = $wurzel . '/storage/plans/' . basename((string) $f['plan_file']);
    if ((string) $f['plan_file'] !== '' && !in_array($plan, $planeVorher, true)) {
        @unlink($plan);
    }
}

$meldungen = trim((string) @file_get_contents($phpFehler));
pruefe($meldungen === '', 'keine PHP-Meldung der Instanz' . ($meldungen !== '' ? ': ' . mb_substr($meldungen, 0, 300) : ''));

ende('sitzung');
