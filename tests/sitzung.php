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
 * Dasselbe für Anfragen an andere Instanzen (bis 60 s, Plandateien bis
 * 180 s): Die Seite einer Instanz und „Prüfen“ warten auf eine träge
 * Gegenseite, eine andere Seite und ein Formular aus einem zweiten Reiter
 * kommen währenddessen durch. Danach ist die Sitzung wieder offen – die
 * Meldung, die „Prüfen“ erst nach der Antwort von dort schreibt, kommt an,
 * und was der zweite Reiter in der Zwischenzeit geschrieben hat, bleibt.
 *
 * Und was daraus folgt: Zweimal „Übernehmen“ (Doppelklick) laufen jetzt
 * nebeneinander – die Sperre der Location lässt nur eins durch, das zweite
 * hört sofort mit einer Meldung auf, es gibt einen Standort. Ändert der
 * zweite Reiter das Kennwort oder meldet sich ab, während „Prüfen“ wartet,
 * legt „Prüfen“ danach keine neue, leere Sitzung an: angemeldet bleibt, wer
 * das Kennwort geändert hat, abgemeldet, wer sich abgemeldet hat.
 *
 * Eigene Instanz (POWERMANAGEMENT_CONFIG, eigene SQLite-Datenbank mit dem
 * Demo-Gelände, dann ohne Demo-Modus), eine InfluxDB-Attrappe und eine
 * Attrappe der Gegenseite (/api/v1), die jede Anfrage aufhalten. Am Ende
 * wird alles weggeräumt.
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
// PHP-Meldungen der Instanz (etwa beim Wiederöffnen der Sitzung) in eine eigene Datei.
$phpFehler = $ordner . '/php-fehler.log';
hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'error_log=' . $phpFehler,
    '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'],
    ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '8']);
for ($i = 0; $i < 50 && !(erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $influxPort)); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $port) || !erreichbar('127.0.0.1', $influxPort)) {
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

/** Ein curl-Griff mit den gemeinsamen Cookies; folgt keinen Weiterleitungen. */
function griff(string $url, ?array $felder = null): CurlHandle
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SHARE          => cookies(),
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Accept: ' . ($felder === null && str_contains($url, '/live') ? 'application/json' : 'text/html')],
    ]);
    if ($felder !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    return $c;
}

function holen(string $url, ?array $felder = null): array
{
    $c = griff($url, $felder);
    $text = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['status' => $status, 'text' => $text];
}

/**
 * Langsame Anfragen anstoßen (Live-Abfragen, Seiten, die auf eine andere
 * Instanz warten), dann – während sie warten – eine zweite Anfrage
 * schicken. Gibt die Dauer der zweiten Anfrage und die Antworten der
 * langsamen zurück.
 *
 * @param list<string|array{0:string,1:array<string,mixed>}> $langsam Adresse oder [Adresse, Formularfelder] (POST)
 * @return array{dauer:float,antwort:array{status:int,text:string},langsam:list<array{status:int,text:string,json:mixed}>}
 */
function waehrendLangsam(array $langsam, string $url, ?array $felder = null): array
{
    $mehrfach = curl_multi_init();
    $griffe = [];
    foreach ($langsam as $anfrage) {
        $griffe[] = $g = is_array($anfrage) ? griff($anfrage[0], $anfrage[1]) : griff($anfrage);
        curl_multi_add_handle($mehrfach, $g);
    }
    // Anlaufen lassen, bis alle beim Server sind und warten.
    $bis = microtime(true) + 0.6;
    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.05);
    } while (microtime(true) < $bis);

    $start = microtime(true);
    $antwort = holen($url, $felder);
    $dauer = microtime(true) - $start;

    do {
        curl_multi_exec($mehrfach, $laufend);
        curl_multi_select($mehrfach, 0.1);
    } while ($laufend > 0);
    $ergebnisse = [];
    foreach ($griffe as $g) {
        $text = (string) curl_multi_getcontent($g);
        $ergebnisse[] = ['status' => (int) curl_getinfo($g, CURLINFO_RESPONSE_CODE), 'text' => $text, 'json' => json_decode($text, true)];
        curl_multi_remove_handle($mehrfach, $g);
        curl_close($g);
    }
    curl_multi_close($mehrfach);
    return ['dauer' => $dauer, 'antwort' => $antwort, 'langsam' => $ergebnisse];
}

// ------------------------------------------------------------ anmelden, Seite laden
$seite = holen($basis . '/login');
preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
$anmeldung = holen($basis . '/login', ['_token' => $m[1] ?? '', 'username' => 'pruefer', 'password' => $kennwort]);
gleich($anmeldung['status'], 302, 'Anmeldung in der zweiten Instanz');
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
