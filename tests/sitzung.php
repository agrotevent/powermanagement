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
 * bekommt keine Kundendaten mehr. Und für die Kundenverwaltung (Eventworx
 * suchen, Vorgang verknüpfen, Dateien am Vorgang): andere Seiten kommen
 * durch, Treffer und Dateiliste sind danach gemerkt, nach dem Abmelden gibt
 * es keine Dateiliste.
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
 * Eigene Instanz (POWERMANAGEMENT_CONFIG, eigene SQLite-Datenbank mit dem
 * Demo-Gelände, dann ohne Demo-Modus) sowie Attrappen für InfluxDB,
 * Eventworx und eine andere Instanz (/api/v1), die jede Abfrage aufhalten.
 * Eventworx hängt über `eventworx.basis` aus der Konfiguration der Instanz
 * an der Attrappe – der einzige Weg zu einer anderen Adresse als
 * https://<mandant>.eventworx.biz. Am Ende wird alles weggeräumt.
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
// Antwortet wie der Mandant (`job` als Liste, `job/<id>` als Datensatz, am
// Auftrag eine hochgeladene PDF-Datei) mit dem Auftrag aus der Demo, nur mit
// dem richtigen Token, und schreibt jeden Pfad mit – so steht fest, dass
// wirklich gefragt wurde.
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
} elseif ($pfad === "/backend/file/list") {
    echo json_encode(["success" => true, "total" => 1, "data" => [["id" => "6650a1f2c9e77a1b000000aa", "documentName" => "Pruefprotokoll Sitzung.pdf",
        "contentType" => "application/pdf", "path" => "/job/documents/P-1", "ownerDocumentId" => $job["id"]]]]);
} elseif ($pfad === "/backend/file/6650a1f2c9e77a1b000000aa") {
    header("Content-Type: application/pdf");
    echo "%PDF-1.4\n% Sitzung\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
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
// PHP-Meldungen der Instanz (etwa beim Wiederöffnen der Sitzung) in eine eigene Datei.
$phpFehler = $ordner . '/php-fehler.log';
hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'error_log=' . $phpFehler,
    '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'],
    ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '8']);
$alleDa = static fn (): bool => erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $influxPort) && erreichbar('127.0.0.1', $ewxPort);
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

// ------------------------------------------------------------ Eventworx in der Kundenverwaltung
// Suche, Verknüpfen und die Dateien am Vorgang warten auf Eventworx – aus
// einem Formular, die Seite kommt erst danach. Hielten sie die Sitzung,
// stünde jede andere Seite so lange an. Nach dem Warten merken sie sich
// Treffer bzw. Dateiliste in der wieder geöffneten Sitzung: Verknüpfen und
// Übernehmen nehmen nur, was dort steht – gehen sie, hat das Merken geklappt.
preg_match('/name="_token" value="([^"]+)"/', holen($basis . '/profil')['text'], $m);
$token = $m[1] ?? '';
$abfrage("INSERT INTO kunden (name, kundennummer, aktiv, created_at) VALUES (?, '', 1, ?)", ['Sitzung Kunde ' . $kennung, gmdate('c')]);
$kundeId = (int) ($abfrage('SELECT id FROM kunden WHERE name = ?', ['Sitzung Kunde ' . $kennung])[0]['id'] ?? 0);
pruefe($kundeId > 0, 'Kunde in der zweiten Instanz angelegt');
@unlink($ordner . '/eventworx-pfade');
file_put_contents($ewxVerzoegerung, '3');
$lauf = waehrendLangsam([[$basis . '/kunden/' . $kundeId . '/eventworx/suche', ['_token' => $token, 'q' => $nummer]]], $basis . '/profil');
gleich($lauf['antwort']['status'], 200, 'Kundenverwaltung: andere Seite, während die Eventworx-Suche wartet');
pruefe($lauf['dauer'] < 2.0, sprintf('Eventworx-Suche der Kundenverwaltung hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
$suche = $lauf['langsam'][0];
pruefe($suche['status'] === 200 && str_contains($suche['text'], 'id="eventworx-treffer"') && str_contains($suche['text'], $nummer), 'Suche liefert trotzdem ihre Treffer');
pruefe(!sitzungsCookie($suche['kopf']), 'Suche: die wieder geöffnete Sitzung schickt kein zweites Sitzungscookie');
$lauf = waehrendLangsam([[$basis . '/kunden/' . $kundeId . '/eventworx/verknuepfen', ['_token' => $token, 'job_id' => (string) $ewxJob['id'], 'bestaetigt' => '1']]],
    $basis . '/profil');
pruefe($lauf['dauer'] < 2.0, sprintf('Verknüpfen hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
$vorgangId = (int) ($abfrage('SELECT id FROM kunden_vorgaenge WHERE kunde_id = ? AND ewx_id = ?', [$kundeId, (string) $ewxJob['id']])[0]['id'] ?? 0);
pruefe($lauf['langsam'][0]['status'] === 302 && $vorgangId > 0, 'Vorgang verknüpft – die Treffer der Suche waren nach dem Warten gemerkt');
$lauf = waehrendLangsam([[$basis . '/kunden/' . $kundeId . '/nachweise/eventworx', ['_token' => $token, 'vorgang' => (string) $vorgangId]]], $basis . '/profil');
pruefe($lauf['dauer'] < 2.0, sprintf('Dateien am Vorgang: hält die Sitzung nicht (%.1f s)', $lauf['dauer']));
pruefe($lauf['langsam'][0]['status'] === 200 && str_contains($lauf['langsam'][0]['text'], 'Pruefprotokoll Sitzung.pdf'), 'Dateien am Vorgang trotzdem gezeigt');
file_put_contents($ewxVerzoegerung, '0');
$r = holen($basis . '/kunden/' . $kundeId . '/nachweise/eventworx/uebernehmen', ['_token' => $token, 'vorgang' => (string) $vorgangId,
    'datei' => ['6650a1f2c9e77a1b000000aa'], 'geprueft_am' => gmdate('Y-m-d', time() - 2 * 86400)]);
$uebernommen = $abfrage("SELECT id FROM pruefnachweise WHERE kunde_id = ? AND quelle = 'ewx_vorgang' AND ewx_datei_id = ?", [$kundeId, '6650a1f2c9e77a1b000000aa']);
pruefe($r['status'] === 302 && count($uebernommen) === 1, 'Datei übernommen – die Dateiliste war nach dem Warten gemerkt');
$pfade = is_file($ordner . '/eventworx-pfade') ? (file($ordner . '/eventworx-pfade', FILE_IGNORE_NEW_LINES) ?: []) : [];
pruefe(in_array('/backend/file/list', $pfade, true) && in_array('/backend/file/6650a1f2c9e77a1b000000aa', $pfade, true), 'Liste und Datei gingen an die Eventworx-Attrappe');
// Abgemeldet, während die Dateien gelesen werden: keine Dateiliste, keine neue Sitzung.
file_put_contents($ewxVerzoegerung, '2');
$lauf = waehrendLangsam([[$basis . '/kunden/' . $kundeId . '/nachweise/eventworx', ['_token' => $token, 'vorgang' => (string) $vorgangId]]],
    $basis . '/logout', ['_token' => $token]);
$nachAbmelden = $lauf['langsam'][0];
pruefe($lauf['antwort']['status'] === 302 && $nachAbmelden['status'] === 302 && !str_contains($nachAbmelden['text'], 'Pruefprotokoll Sitzung.pdf')
    && !sitzungsCookie($nachAbmelden['kopf']), 'Dateien am Vorgang nach dem Abmelden: Weiterleitung ohne Dateiliste, kein neues Sitzungscookie');
gleich(count(glob($ordner . '/sitzungen/sess_*') ?: []), 0, 'nach dem Abmelden legt die Kundenverwaltung keine Sitzung an');
file_put_contents($ewxVerzoegerung, '0');
gleich($anmelden($kennwort), 302, 'wieder angemeldet');

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

foreach ($abfrage('SELECT f.plan_file FROM floors f JOIN sites s ON s.id = f.site_id WHERE s.partner_id = ?', [$partnerId]) as $f) {
    $plan = $wurzel . '/storage/plans/' . basename((string) $f['plan_file']);
    if ((string) $f['plan_file'] !== '' && !in_array($plan, $planeVorher, true)) {
        @unlink($plan);
    }
}

$meldungen = trim((string) @file_get_contents($phpFehler));
pruefe($meldungen === '', 'keine PHP-Meldung der Instanz' . ($meldungen !== '' ? ': ' . mb_substr($meldungen, 0, 300) : ''));

ende('sitzung');
