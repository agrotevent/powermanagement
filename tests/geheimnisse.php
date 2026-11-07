<?php
declare(strict_types=1);

/**
 * Zugangsdaten gelten für ihr Ziel (Settings::BINDUNG) – eigene Instanz.
 *
 * Kennwort am MQTT-Broker, Token und Kennwort der InfluxDB und das Token des
 * Repositories werden beim Setzen an das dann wirksame Ziel gebunden
 * (Schema, Host, Port, Benutzer). Wer nur „Systemeinstellungen ändern“ hat,
 * soll sie nicht abholen können, indem er Host, Port oder Adresse auf einen
 * eigenen Dienst stellt.
 *
 * A – in der Instanz: Wert aus config.php nur, solange die Einstellungen das
 * Ziel nicht wegbewegen (auch Groß/Klein, Port ausgeschrieben, Schrägstrich
 * am Ende); eingetragene nur für ihr Ziel (Host, Port, Benutzer; das
 * Kennwort der InfluxDB auch mit dem Benutzer), Bindung beim Setzen, weg
 * beim Entfernen; ältere Geheimnisse ohne Bindung bindet `bin/power
 * migrate` (bindungenNachholen) – nur einmal; das Token des Repositories:
 * eingetragen nur für seinen Server, aus der Umgebung nur für den aus
 * config.php; Influx::adresseFehler(); Fehlermeldung der InfluxDB ohne rohen
 * Rumpf (nur `error` aus JSON).
 *
 * B – HTTP mit einem Konto, das nur „Systemeinstellungen ändern“ hat, und
 * Fängern statt Broker und InfluxDB (sie schreiben jedes empfangene Byte
 * mit): „Prüfen“ schickt die Geheimnisse an das echte Ziel; nach dem
 * Umstellen von mqtt.host/mqtt.port/influx.url auf einen Fänger erreicht
 * weder das MQTT-CONNECT noch die Anfrage an die InfluxDB ein Geheimnis –
 * aus config.php nicht, eingetragene nicht (das Formular entfernt sie mit
 * Meldung), und auch nicht, wenn das Ziel an der Oberfläche vorbei
 * umgestellt wurde (Hinweis bei „Prüfen“); eine im selben Formular neu
 * eingetragene gilt für das neue Ziel; eine Adresse mit Zugangsdaten, „?“
 * oder anderem Schema speichert nichts.
 */

require __DIR__ . '/hilfen/instanz.php';
// Die Fänger-Ports stehen in config.php der Instanz – vorher wählen und dem
// Lauf in der Instanz über die Umgebung mitgeben.
if (getenv('PM_INSTANZ') !== '1') {
    $frei = static function (): int {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr($name, strrpos($name, ':') + 1);
    };
    putenv('PM_GEHEIM_PORTS=' . implode(',', [$frei(), $frei(), $frei(), $frei()]));
}
[$mqttEcht, $mqttFremd, $influxEcht, $influxFremd] = array_map('intval', explode(',', (string) getenv('PM_GEHEIM_PORTS')) + [0, 0, 0, 0]);
inEigenerInstanz(__FILE__, [
    'mqtt'      => ['host' => '127.0.0.1', 'port' => $mqttEcht, 'username' => 'powermanagement', 'password' => 'DATEI-MQTT-' . bin2hex(random_bytes(6))],
    'influx'    => ['url' => 'http://127.0.0.1:' . $influxEcht, 'token' => 'DATEI-INFLUX-' . bin2hex(random_bytes(6)), 'username' => '', 'password' => '', 'timeout' => 5],
    'inventory' => ['repo_url' => 'https://git.example.test/firma/locations.git'],
]);
require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Http;
use App\Messung\Influx;
use App\Services\BenutzerService;
use App\Services\LocationSync;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;

if (!function_exists('curl_init')) {
    uebersprungen('geheimnisse', 'die PHP-Erweiterung curl fehlt');
}

$kennung = 'pm-geheim-' . bin2hex(random_bytes(3));
$wurzel = dirname(__DIR__);
$ordner = (string) getenv('PM_INSTANZ_ORDNER');
if ($ordner === '' || !is_dir($ordner)) {
    uebersprungen('geheimnisse', 'ohne eigene Instanz');
}
$dateiMqtt = (string) Config::get('mqtt.password');
$dateiInflux = (string) Config::get('influx.token');
$echtUrl = 'http://127.0.0.1:' . $influxEcht;
$fremdUrl = 'http://127.0.0.1:' . $influxFremd;
$rumpfMarke = 'FREMDER-RUMPF-' . $kennung;

/** Alle Zugangsdaten und Ziele aus den Einstellungen entfernen (Ziel = config.php). */
$zuruecksetzen = static function (): void {
    foreach (['mqtt.host', 'mqtt.port', 'mqtt.username', 'influx.url', 'influx.username', 'inventory.repo_url'] as $k) {
        Settings::forget($k);
    }
    foreach (array_keys(Settings::BINDUNG) as $g) {
        Settings::geheimnisSetzen($g, '-');
    }
    Settings::vergessen();
};

// ================================================ A – in der Instanz
try {
    // ---- Wert aus config.php
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), $dateiMqtt, 'MQTT-Kennwort aus config.php für das Ziel aus config.php');
    gleich(Influx::token(), $dateiInflux, 'Influx-Token aus config.php für die Adresse aus config.php');
    Settings::put('mqtt.port', (string) $mqttFremd);
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), '', 'anderer Port in den Einstellungen: kein Kennwort aus config.php');
    Settings::put('mqtt.port', (string) $mqttEcht);
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), $dateiMqtt, 'derselbe Port in den Einstellungen: Kennwort aus config.php gilt');
    Settings::put('mqtt.host', 'broker.example.test');
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), '', 'anderer Host: kein Kennwort aus config.php');
    Settings::put('mqtt.host', '127.0.0.1');
    Settings::put('mqtt.username', 'jemand-anderes');
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), '', 'anderer Benutzer: kein Kennwort aus config.php');
    Settings::forget('mqtt.username');
    gleich((string) Config::get('mqtt.username'), 'powermanagement', 'Einstellung entfernt: Config wieder mit dem Wert aus config.php');
    Settings::put('influx.url', $fremdUrl);
    gleich(Influx::token(), '', 'andere Adresse der InfluxDB: kein Token aus config.php');
    Settings::put('influx.url', 'HTTP://127.0.0.1:' . $influxEcht . '/');
    gleich(Influx::token(), $dateiInflux, 'dieselbe Adresse anders geschrieben (Groß/Klein, Schrägstrich): Token aus config.php gilt');
    Settings::put('influx.url', 'https://127.0.0.1:' . $influxEcht);
    gleich(Influx::token(), '', 'anderes Schema: kein Token aus config.php');
    $zuruecksetzen();

    // ---- eingetragen: gilt für das Ziel beim Setzen
    $kwOben = 'OBEN-MQTT-' . bin2hex(random_bytes(6));
    Settings::geheimnisSetzen(Settings::GEHEIM_MQTT_KENNWORT, $kwOben);
    gleich(Settings::get('mqtt.password_fuer'), 'mqtt://127.0.0.1:' . $mqttEcht . ' powermanagement', 'Bindung beim Setzen: Host, Port, Benutzer');
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), $kwOben, 'eingetragenes Kennwort geht vor config.php');
    foreach ([['mqtt.port', (string) $mqttFremd], ['mqtt.host', 'localhost'], ['mqtt.username', 'shelly']] as [$k, $v]) {
        Settings::put($k, $v);
        gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), '', 'eingetragenes Kennwort nicht für ein anderes Ziel (' . $k . ') – auch nicht das aus config.php');
        pruefe(Settings::geheimnisFremd(Settings::GEHEIM_MQTT_KENNWORT), 'geheimnisFremd meldet es (' . $k . ')');
        Settings::forget($k);
    }
    gleich(Settings::geheimnis(Settings::GEHEIM_MQTT_KENNWORT, 'mqtt.password'), $kwOben, 'Ziel wieder wie beim Setzen: es gilt wieder');
    Settings::geheimnisSetzen(Settings::GEHEIM_MQTT_KENNWORT, '-');
    pruefe(Settings::get('mqtt.password_fuer') === null && Settings::get(Settings::GEHEIM_MQTT_KENNWORT) === null, '„-“ entfernt Kennwort und Bindung');

    Settings::put('influx.url', $echtUrl);
    Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_TOKEN, 'OBEN-TOKEN');
    Settings::put('influx.url', $echtUrl . '/influx');
    gleich(Influx::token(), 'OBEN-TOKEN', 'Token: anderer Pfad auf demselben Server gilt');
    Settings::put('influx.url', $fremdUrl);
    gleich(Influx::token(), '', 'Token: andere Adresse – keins');
    Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_TOKEN, '-');
    Settings::put('influx.url', $echtUrl);
    Settings::put('influx.username', 'leser');
    Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_KENNWORT, 'OBEN-KW');
    gleich(Settings::gebunden(Settings::GEHEIM_INFLUX_KENNWORT), 'OBEN-KW', 'Influx-Kennwort für Adresse und Benutzer');
    Settings::put('influx.username', 'schreiber');
    gleich(Settings::gebunden(Settings::GEHEIM_INFLUX_KENNWORT), '', 'Influx-Kennwort: anderer Benutzer – keins');
    $zuruecksetzen();

    // ---- ältere Geheimnisse ohne Bindung (vor dem Update eingetragen)
    Database::insert('settings', ['setting_key' => Settings::GEHEIM_MQTT_KENNWORT, 'setting_value' => \App\Support\Crypto::encrypt('ALT-MQTT'), 'updated_at' => gmdate('c')]);
    Settings::vergessen();
    gleich(Settings::gebunden(Settings::GEHEIM_MQTT_KENNWORT), '', 'ohne Bindung: nicht herausgegeben');
    gleich(Settings::bindungenNachholen(), [Settings::GEHEIM_MQTT_KENNWORT], 'bindungenNachholen bindet das ältere Kennwort');
    gleich(Settings::gebunden(Settings::GEHEIM_MQTT_KENNWORT), 'ALT-MQTT', '… an das jetzige Ziel');
    gleich(Settings::bindungenNachholen(), [], 'bindungenNachholen: ein zweites Mal nichts');
    Settings::put('mqtt.port', (string) $mqttFremd);
    gleich(Settings::gebunden(Settings::GEHEIM_MQTT_KENNWORT), '', '… und gilt danach nur dafür');
    $zuruecksetzen();

    // ---- Token des Repositories
    $envVorher = getenv(LocationSync::TOKEN_VARIABLE);
    putenv(LocationSync::TOKEN_VARIABLE . '=UMGEBUNG-TOKEN');
    gleich(LocationSync::token(), 'UMGEBUNG-TOKEN', 'Repository: Token aus der Umgebung für den Server aus config.php');
    Settings::put('inventory.repo_url', 'https://git.example.test/anders/locations.git');
    gleich(LocationSync::token(), 'UMGEBUNG-TOKEN', 'Repository: anderer Pfad auf demselben Server – Token aus der Umgebung gilt');
    Settings::put('inventory.repo_url', 'https://boese.example.test/x.git');
    gleich(LocationSync::token(), '', 'Repository: anderer Server – kein Token aus der Umgebung');
    LocationSync::tokenSetzen('EINGETRAGEN-TOKEN');
    gleich(LocationSync::token(), 'EINGETRAGEN-TOKEN', 'Repository: eingetragenes Token für seinen Server');
    Settings::put('inventory.repo_url', 'https://git.example.test/firma/locations.git');
    gleich(LocationSync::token(), 'UMGEBUNG-TOKEN', 'Repository: eingetragenes Token nicht für einen anderen Server (dort gilt das aus der Umgebung)');
    putenv(LocationSync::TOKEN_VARIABLE);
    gleich(LocationSync::token(), '', 'Repository: ohne Umgebung kein Token für den anderen Server');
    if (is_string($envVorher)) {
        putenv(LocationSync::TOKEN_VARIABLE . '=' . $envVorher);
    }
    $zuruecksetzen();

    // ---- Adresse der InfluxDB
    foreach (['' => true, 'http://127.0.0.1:8086' => true, 'https://influx.example.test/pfad' => true, 'ftp://influx.example.test' => false,
        'http://nutzer:kw@influx.example.test' => false, 'http://influx.example.test/?u=a' => false, 'http://influx.example.test/#x' => false,
        'influx.example.test:8086' => false, 'http://' => false, "http://a.test/\n" => false] as $url => $gut) {
        gleich(Influx::adresseFehler((string) $url) === null, $gut, 'Influx::adresseFehler(' . json_encode((string) $url) . ')');
    }

    // ---- Fehlermeldung ohne rohen Rumpf
    Http::$attrappe = static fn (): array => ['ok' => true, 'status' => 502, 'rumpf' => '<html>' . $rumpfMarke . '</html>', 'fehler' => ''];
    $p = Influx::pruefen();
    pruefe(!$p['ok'] && !str_contains($p['message'], $rumpfMarke) && str_contains($p['message'], '502'), 'Fehler der InfluxDB ohne rohen Rumpf: ' . $p['message']);
    Http::$attrappe = static fn (): array => ['ok' => true, 'status' => 401, 'rumpf' => '{"error":"authorization failed"}', 'fehler' => ''];
    $p = Influx::pruefen();
    gleich($p['message'], 'InfluxDB antwortet mit 401: authorization failed', 'Fehler der InfluxDB: `error` aus JSON');
    Http::$attrappe = null;
} finally {
    Http::$attrappe = null;
    $zuruecksetzen();
}

// ================================================ B – HTTP mit Fängern
// Fänger: nehmen Verbindungen an und hängen jedes Byte an eine Datei –
// bevor sie antworten (MQTT: CONNACK „nicht berechtigt“, HTTP: 401 mit
// HTML-Rumpf), damit es schon dort steht, wenn „Prüfen“ zurückkommt.
file_put_contents($ordner . '/faenger.php', <<<'PHP'
<?php
[, $art, $port, $datei, $marke] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $nr, $text);
if ($server === false) {
    exit(1);
}
function fertig(string $art, string $d): bool
{
    if ($art === 'mqtt') {
        $laenge = 0;
        $faktor = 1;
        $i = 1;
        do {
            if (!isset($d[$i])) {
                return false;
            }
            $b = ord($d[$i]);
            $laenge += ($b & 127) * $faktor;
            $faktor *= 128;
            $i++;
        } while (($b & 128) !== 0 && $i < 5);
        return strlen($d) >= $i + $laenge;
    }
    $p = strpos($d, "\r\n\r\n");
    if ($p === false) {
        return false;
    }
    $laenge = preg_match('/^content-length:\s*(\d+)/mi', substr($d, 0, $p), $m) === 1 ? (int) $m[1] : 0;
    return strlen($d) >= $p + 4 + $laenge;
}
for (;;) {
    $c = @stream_socket_accept($server, 30);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 0, 200000);
    $daten = '';
    $bis = microtime(true) + 3;
    while (microtime(true) < $bis && !fertig($art, $daten)) {
        $teil = fread($c, 65536);
        if (is_string($teil) && $teil !== '') {
            $daten .= $teil;
        } elseif (feof($c)) {
            break;
        }
    }
    file_put_contents($datei, $daten . "\n", FILE_APPEND | LOCK_EX);
    if ($art === 'mqtt') {
        fwrite($c, "\x20\x02\x00\x05");
    } else {
        $rumpf = '<html>' . $marke . '</html>';
        fwrite($c, "HTTP/1.1 401 Unauthorized\r\nContent-Type: text/html\r\nContent-Length: " . strlen($rumpf) . "\r\nConnection: close\r\n\r\n" . $rumpf);
    }
    fclose($c);
}
PHP);
$faenger = [];
foreach (['mqttEcht' => ['mqtt', $mqttEcht], 'mqttFremd' => ['mqtt', $mqttFremd], 'influxEcht' => ['http', $influxEcht], 'influxFremd' => ['http', $influxFremd]] as $name => [$art, $p]) {
    $faenger[$name] = $ordner . '/' . $name . '.txt';
    touch($faenger[$name]);
    hintergrund([PHP_BINARY, $ordner . '/faenger.php', $art, (string) $p, $faenger[$name], $rumpfMarke]);
}
/** Was die Fänger seit dem letzten Aufruf empfangen haben – danach geleert. */
$empfangen = static function () use ($faenger): array {
    $aus = [];
    foreach ($faenger as $name => $datei) {
        $aus[$name] = (string) file_get_contents($datei);
        file_put_contents($datei, '');
    }
    return $aus;
};

/** Ein Aufruf mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function anfrage(string $basis, string $dose, string $methode, string $pfad, array $felder = []): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $dose, CURLOPT_COOKIEFILE => $dose,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Expect:']]);
    if ($methode === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    $antwort = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    return ['status' => $status, 'text' => substr($antwort, $kopfLaenge)];
}

function meldungen(string $html): string
{
    preg_match_all('/<div class="flash [a-z]+">([^<]*)<\/div>/', $html, $m);
    return html_entity_decode(implode(' ', $m[1]), ENT_QUOTES, 'UTF-8');
}

$dose = (string) tempnam($ordner, 'dose-');
try {
    $rolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' Einstellungen', '', ['settings.manage'])['id'];
    $kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '!';
    BenutzerService::anlegen($kennung, $kennwort, (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rolle]), false);

    $port = freierPort();
    $basis = 'http://127.0.0.1:' . $port;
    $serverFehler = $ordner . '/server-fehler.log';
    @mkdir($ordner . '/sitzungen', 0700, true);
    hintergrund([PHP_BINARY, '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
    for ($i = 0; $i < 50 && !(erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $mqttFremd) && erreichbar('127.0.0.1', $influxFremd)); $i++) {
        usleep(100000);
    }
    pruefe(erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $mqttEcht) && erreichbar('127.0.0.1', $influxEcht), 'PHP-Server und Fänger laufen');
    $empfangen();

    $seite = anfrage($basis, $dose, 'GET', '/login');
    preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
    anfrage($basis, $dose, 'POST', '/login', ['username' => $kennung, 'password' => $kennwort, '_token' => $m[1] ?? '']);
    $seite = anfrage($basis, $dose, 'GET', '/system/einstellungen');
    gleich($seite['status'], 200, 'nur „Systemeinstellungen ändern“: Einstellungen erreichbar');
    preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
    $token = $m[1] ?? '';
    $pruefen = static function () use ($basis, $dose, $token, $empfangen): array {
        anfrage($basis, $dose, 'POST', '/system/einstellungen/pruefen', ['_token' => $token]);
        $text = meldungen(anfrage($basis, $dose, 'GET', '/system/einstellungen')['text']);
        return [$empfangen(), $text];
    };
    $umstellen = static function (array $zusatz = []) use ($basis, $dose, $token, $mqttFremd, $fremdUrl): string {
        $r = anfrage($basis, $dose, 'POST', '/system/einstellungen/speichern', ['_token' => $token, 'mqtt__host' => '127.0.0.1',
            'mqtt__port' => (string) $mqttFremd, 'influx__url' => $fremdUrl] + $zusatz);
        Settings::vergessen();
        return $r['status'] === 302 ? meldungen(anfrage($basis, $dose, 'GET', '/system/einstellungen')['text']) : 'Status ' . $r['status'];
    };

    // ---- B1: aus config.php
    [$e, $text] = $pruefen();
    pruefe(str_contains($e['mqttEcht'], $dateiMqtt) && str_contains($e['influxEcht'], 'Authorization: Token ' . $dateiInflux),
        'config.php: „Prüfen“ schickt Kennwort und Token an das Ziel aus config.php (die Fänger sehen Geheimnisse)');
    pruefe(str_contains($text, 'InfluxDB antwortet mit 401') && !str_contains($text, $rumpfMarke), 'Meldung der InfluxDB ohne ihren rohen Rumpf: ' . $text);
    $umstellen();
    [$e, $text] = $pruefen();
    pruefe($e['mqttFremd'] !== '' && $e['influxFremd'] !== '', 'umgestellt: „Prüfen“ verbindet sich mit den Fängern');
    pruefe(!str_contains($e['mqttFremd'], $dateiMqtt) && !str_contains($e['influxFremd'], $dateiInflux) && !str_contains($e['influxFremd'], 'Authorization'),
        'umgestellt: weder MQTT-CONNECT noch die Anfrage an die InfluxDB trägt ein Geheimnis aus config.php');
    $zuruecksetzen();

    // ---- B2: eingetragen, umgestellt über das Formular
    $kwOben = 'OBEN-MQTT-' . bin2hex(random_bytes(6));
    $tokenOben = 'OBEN-INFLUX-' . bin2hex(random_bytes(6));
    Settings::geheimnisSetzen(Settings::GEHEIM_MQTT_KENNWORT, $kwOben);
    Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_TOKEN, $tokenOben);
    [$e] = $pruefen();
    pruefe(str_contains($e['mqttEcht'], $kwOben) && str_contains($e['influxEcht'], 'Authorization: Token ' . $tokenOben),
        'eingetragen: „Prüfen“ schickt sie an ihr Ziel');
    $text = $umstellen();
    pruefe(str_contains($text, 'Entfernt, weil für das bisherige Ziel eingetragen') && str_contains($text, 'Token der InfluxDB')
        && str_contains($text, 'Kennwort am MQTT-Broker'), 'Formular: Ziel umgestellt ohne neues Geheimnis → entfernt, mit Meldung: ' . $text);
    pruefe(!Settings::geheimnisHinterlegt(Settings::GEHEIM_MQTT_KENNWORT) && !Settings::geheimnisHinterlegt(Settings::GEHEIM_INFLUX_TOKEN),
        '… und nicht mehr hinterlegt');
    [$e] = $pruefen();
    pruefe($e['mqttFremd'] !== '' && !str_contains($e['mqttFremd'], $kwOben) && !str_contains($e['mqttFremd'], $dateiMqtt)
        && !str_contains($e['influxFremd'], $tokenOben) && !str_contains($e['influxFremd'], 'Authorization'),
        'umgestellt: kein eingetragenes Geheimnis beim Fänger');
    $audit = (string) json_encode(Database::select("SELECT details FROM audit_log WHERE action = 'einstellungen.speichern'"));
    pruefe(!str_contains($audit, $kwOben) && !str_contains($audit, $tokenOben) && str_contains($audit, 'entfernt'), 'Audit nennt nur die Schlüssel');
    $zuruecksetzen();

    // ---- B3: an der Oberfläche vorbei umgestellt (wie bin/power einstellung)
    Settings::geheimnisSetzen(Settings::GEHEIM_MQTT_KENNWORT, $kwOben);
    Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_TOKEN, $tokenOben);
    Settings::put('mqtt.port', (string) $mqttFremd);
    Settings::put('influx.url', $fremdUrl);
    [$e, $text] = $pruefen();
    pruefe($e['mqttFremd'] !== '' && !str_contains($e['mqttFremd'], $kwOben) && !str_contains($e['influxFremd'], $tokenOben)
        && !str_contains($e['influxFremd'], 'Authorization'), 'vorbei umgestellt: kein Geheimnis beim Fänger');
    pruefe(str_contains($text, 'für ein anderes Ziel'), '„Prüfen“ nennt den Grund: ' . $text);
    Settings::vergessen();
    pruefe(Settings::geheimnisHinterlegt(Settings::GEHEIM_MQTT_KENNWORT), '… das Geheimnis bleibt hinterlegt (gilt wieder, wenn das Ziel zurückkommt)');
    $zuruecksetzen();

    // ---- B4: neu eingetragen im selben Formular → gilt für das neue Ziel
    $kwNeu = 'NEU-MQTT-' . bin2hex(random_bytes(6));
    $text = $umstellen(['mqtt_password' => $kwNeu]);
    pruefe(!str_contains($text, 'Kennwort am MQTT-Broker'), 'neues Kennwort im selben Formular: nicht als entfernt gemeldet');
    [$e] = $pruefen();
    pruefe(str_contains($e['mqttFremd'], $kwNeu), 'neues Kennwort im selben Formular gilt für das neue Ziel');
    $zuruecksetzen();

    // ---- B5: Adresse der InfluxDB mit Zugangsdaten, „?“, anderem Schema
    foreach (['http://nutzer:kw@127.0.0.1:' . $influxFremd, $fremdUrl . '/?db=x', 'gopher://127.0.0.1:' . $influxFremd] as $url) {
        $r = anfrage($basis, $dose, 'POST', '/system/einstellungen/speichern', ['_token' => $token, 'influx__url' => $url, 'mqtt__port' => (string) $mqttFremd]);
        $text = meldungen(anfrage($basis, $dose, 'GET', '/system/einstellungen')['text']);
        Settings::vergessen();
        pruefe($r['status'] === 302 && str_contains($text, 'Nichts gespeichert') && Settings::get('influx.url') === null && Settings::get('mqtt.port') === null,
            'Adresse ' . $url . ' abgelehnt, nichts gespeichert: ' . $text);
    }

    $log = (string) @file_get_contents($serverFehler);
    pruefe(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'keine PHP-Meldung im Server');
} finally {
    // Konto und Rolle verschwinden mit der Instanz.
    $zuruecksetzen();
    @unlink($dose);
}

ende('geheimnisse');
