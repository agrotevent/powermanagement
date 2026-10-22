<?php
declare(strict_types=1);

/**
 * Abgelaufene Sitzungsdateien werden weggeräumt (Befund 9).
 *
 * Debian schaltet die Müllabfuhr der Sitzungen ab (session.gc_probability = 0
 * in jeder php.ini) und räumt per Cron nur das gemeinsame
 * /var/lib/php/sessions. Der PHP-FPM-Pool legt die Sitzungen aber nach
 * storage/sessions – dort räumte niemand, und jede Anfrage ohne Cookie
 * (Anmeldeseite, Monitoring) ließ eine Datei zurück. Session::start()
 * schaltet die GC deshalb selbst ein; wie lange eine Datei bleibt, folgt
 * idle_timeout und max_session.
 *
 * Geprüft wird:
 *   - die Einstellungen nach Session::start(): bei Debians php.ini, bei
 *     schon eingeschalteter GC (ihr Takt bleibt), für verschiedene Grenzen,
 *     ohne Grenze (nichts wird weggeräumt), in einem gemeinsamen Verzeichnis
 *     (unberührt) und in einem, das es nicht gibt
 *   - mit dem eingebauten Server und dem echten Dateien-Handler (GC bei
 *     jeder Anfrage): eine Datei, älter als die Lebensdauer, verschwindet –
 *     auch wenn sie jünger ist als Debians 1440 s –, eine jüngere bleibt,
 *     auch wenn sie älter ist als idle_timeout; die angemeldete Sitzung
 *     überdauert die GC; ohne Grenze bleibt alles; keine PHP-Meldung
 *
 * Eigene Instanz (POWERMANAGEMENT_CONFIG, eigene SQLite-Datenbank, eigenes
 * Sitzungsverzeichnis) in einem Temp-Ordner, am Ende weggeräumt.
 */

require __DIR__ . '/hilfen/pruefung.php';

$wurzel = dirname(__DIR__);
$kennung = 'pm-sitzung-gc-' . bin2hex(random_bytes(4));
$ordner = sys_get_temp_dir() . '/' . $kennung;
mkdir($ordner . '/sitzungen', 0700, true);
register_shutdown_function(static function () use ($ordner): void {
    // Nur den eigenen Ordner, nach den Servern (die stoppt hintergrund()).
    @chmod($ordner . '/unlesbar', 0700);
    $alles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($alles as $eintrag) {
        $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
    }
    @rmdir($ordner);
});

// ------------------------------------------------------------ eigene Instanz
$vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
/** Eine Konfiguration mit diesen Grenzen (Sekunden; 0 = ohne) – gibt ihren Pfad zurück. */
$konfig = static function (string $name, int $leerlauf, int $hoechstens) use ($vorlage, $ordner): string {
    $datei = $ordner . '/' . $name . '.php';
    file_put_contents($datei, '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = false;
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($ordner . '/datenbank.sqlite', true) . ';
$c["security"]["idle_timeout"] = ' . $leerlauf . ';
$c["security"]["max_session"] = ' . $hoechstens . ';
return $c;
');
    return $datei;
};
$kennwort = 'Sitzung-GC-Pruefung-2026!';
$p = proc_open(['php', $wurzel . '/bin/power', 'install', 'pruefer', $kennwort, '--ohne-wechsel'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre, $wurzel,
    ['POWERMANAGEMENT_CONFIG' => $konfig('einrichten', 3600, 43200)] + getenv());
gleich(proc_close($p), 0, 'eigene Instanz eingerichtet');

// ------------------------------------------------------------ Einstellungen nach Session::start()
// Debians php.ini: GC aus, 1440 s.
$debian = ['gc_probability' => '0', 'gc_divisor' => '1000', 'gc_maxlifetime' => '1440'];

/**
 * Session::start() in einem eigenen Prozess (CLI) mit diesen session.*-Werten
 * und diesem Sitzungsverzeichnis; gibt die Werte danach und die Meldungen zurück.
 *
 * @param array<string,string> $ini
 * @return array{probability:string,divisor:string,lebensdauer:string,meldungen:string}
 */
$nachStart = static function (array $ini, string $konfigDatei, string $verzeichnis) use ($wurzel): array {
    $befehl = ['php', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'session.save_path=' . $verzeichnis];
    foreach ($ini as $k => $v) {
        array_push($befehl, '-d', 'session.' . $k . '=' . $v);
    }
    array_push($befehl, '-r', 'require ' . var_export($wurzel . '/src/bootstrap.php', true) . ';
        \App\Support\Session::start();
        echo json_encode(["probability" => ini_get("session.gc_probability"), "divisor" => ini_get("session.gc_divisor"),
            "lebensdauer" => ini_get("session.gc_maxlifetime")]);');
    $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, $wurzel, ['POWERMANAGEMENT_CONFIG' => $konfigDatei] + getenv());
    $aus = (string) stream_get_contents($rohre[1]);
    $meldungen = (string) stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    proc_close($p);
    $werte = json_decode($aus, true);
    return (is_array($werte) ? $werte : ['probability' => '?', 'divisor' => '?', 'lebensdauer' => '?']) + ['meldungen' => $meldungen];
};
$eigen = $ordner . '/sitzungen';

$w = $nachStart($debian, $konfig('standard', 3600, 43200), $eigen);
gleich([$w['probability'], $w['divisor']], ['1', '1000'], 'Debians php.ini: GC eingeschaltet, jeder tausendste Start');
gleich($w['lebensdauer'], '43200', 'Lebensdauer = max_session (länger als idle_timeout)');
gleich($w['meldungen'], '', 'Session::start() ohne PHP-Meldung');

$w = $nachStart($debian, $konfig('leerlauf-lang', 86400, 43200), $eigen);
gleich($w['lebensdauer'], '86400', 'Lebensdauer = idle_timeout, wenn das die längere Grenze ist');
$w = $nachStart($debian, $konfig('ohne-leerlauf', 0, 7200), $eigen);
gleich($w['lebensdauer'], '7200', 'ohne idle_timeout: Lebensdauer = max_session');
$w = $nachStart($debian, $konfig('ohne-hoechstens', 900, 0), $eigen);
gleich($w['lebensdauer'], '900', 'ohne max_session: Lebensdauer = idle_timeout');

$ohneGrenze = $konfig('ohne-grenze', 0, 0);
$w = $nachStart($debian, $ohneGrenze, $eigen);
gleich($w['probability'], '0', 'ohne jede Grenze: GC bleibt aus');
$w = $nachStart(['gc_probability' => '1', 'gc_divisor' => '1', 'gc_maxlifetime' => '1440'], $ohneGrenze, $eigen);
gleich($w['probability'], '0', 'ohne jede Grenze: auch eine eingeschaltete GC wird abgeschaltet (sie räumte nach 1440 s)');

$w = $nachStart(['gc_probability' => '1', 'gc_divisor' => '50', 'gc_maxlifetime' => '1440'], $konfig('standard', 3600, 43200), $eigen);
gleich([$w['probability'], $w['divisor'], $w['lebensdauer']], ['1', '50', '43200'], 'schon eingeschaltete GC: ihr Takt bleibt, die Lebensdauer folgt der Konfiguration');
// In Anführungszeichen – sonst beginnt „;“ in -d einen Kommentar.
$w = $nachStart($debian, $konfig('standard', 3600, 43200), '"2;' . $eigen . '"');
gleich([$w['probability'], $w['lebensdauer']], ['1', '43200'], 'Sitzungsverzeichnis mit Tiefe („2;/pfad“): der Ordner zählt');

// Ein Verzeichnis, in das jeder schreiben darf (wie /var/lib/php/sessions
// oder /tmp), teilen sich mehrere Anwendungen: dort bleibt alles, wie es ist.
$gemeinsam = $ordner . '/gemeinsam';
mkdir($gemeinsam);
chmod($gemeinsam, 01733);
$w = $nachStart($debian, $konfig('standard', 3600, 43200), $gemeinsam);
gleich([$w['probability'], $w['lebensdauer']], ['0', '1440'], 'gemeinsames Verzeichnis (1733): GC und Lebensdauer unberührt');
$w = $nachStart(['gc_probability' => '1', 'gc_divisor' => '100', 'gc_maxlifetime' => '1440'], $konfig('standard', 3600, 43200), $gemeinsam);
gleich([$w['probability'], $w['divisor'], $w['lebensdauer']], ['1', '100', '1440'], 'gemeinsames Verzeichnis mit eingeschalteter GC: unberührt');
$w = $nachStart($debian, $konfig('standard', 3600, 43200), $ordner . '/gibt-es-nicht');
gleich($w['probability'], '0', 'Sitzungsverzeichnis fehlt: GC bleibt aus');
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    // root darf jedes Verzeichnis lesen – nur ohne root prüfbar.
    $unlesbar = $ordner . '/unlesbar';
    mkdir($unlesbar, 0300);
    $w = $nachStart($debian, $konfig('standard', 3600, 43200), $unlesbar);
    chmod($unlesbar, 0700);
    gleich($w['probability'], '0', 'Sitzungsverzeichnis nicht lesbar: GC bleibt aus (sonst eine Meldung bei jeder GC)');
}

// ------------------------------------------------------------ eingebauter Server, echter Dateien-Handler
// GC bei jeder Anfrage (1/1) – so ist sie hier vorhersagbar; den Takt setzt
// Session::start() nur, wenn sie aus ist (oben geprüft). Lebensdauer: 600 s
// Leerlauf, 900 s höchstens → 900 s.
$fehlerprotokoll = $ordner . '/php-fehler.log';
/** @return string Basisadresse des gestarteten Servers */
$server = static function (string $konfigDatei, string $sitzungen) use ($wurzel, $fehlerprotokoll): string {
    $port = freierPort();
    hintergrund(['php', '-d', 'session.save_path=' . $sitzungen, '-d', 'session.gc_probability=1', '-d', 'session.gc_divisor=1',
        '-d', 'session.gc_maxlifetime=1440', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $fehlerprotokoll,
        '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'],
        ['POWERMANAGEMENT_CONFIG' => $konfigDatei, 'PHP_CLI_SERVER_WORKERS' => '2']);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    return 'http://127.0.0.1:' . $port;
};
$basis = $server($konfig('server', 600, 900), $eigen);
if (!erreichbar('127.0.0.1', (int) substr($basis, strrpos($basis, ':') + 1))) {
    uebersprungen('sitzung-gc', 'eingebauter PHP-Server startet nicht');
}

/** GET bzw. POST mit Cookie-Dose; folgt keinen Weiterleitungen. */
$holen = static function (string $url, string $dose, ?array $felder = null): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $dose, CURLOPT_COOKIEFILE => $dose, CURLOPT_TIMEOUT => 30]);
    if ($felder !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    $text = (string) curl_exec($c);
    $antwort = ['status' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'ziel' => (string) curl_getinfo($c, CURLINFO_REDIRECT_URL), 'text' => $text];
    curl_close($c);
    return $antwort;
};
/** Eine Sitzungsdatei mit diesem Alter (Sekunden) anlegen. */
$alteDatei = static function (string $verzeichnis, string $name, int $alter): string {
    $datei = $verzeichnis . '/' . $name;
    file_put_contents($datei, 'last_activity|i:' . (time() - $alter) . ';');
    touch($datei, time() - $alter);
    clearstatcache();
    return $datei;
};
$id = str_replace('-', '', $kennung);

$alt = $alteDatei($eigen, 'sess_' . $id . 'alt', 1000);       // älter als 900 s, jünger als 1440 s
$jung = $alteDatei($eigen, 'sess_' . $id . 'jung', 800);      // älter als idle_timeout, jünger als 900 s
$fremd = $alteDatei($eigen, $id . '-notiz.txt', 100000);      // keine Sitzung
$vorher = count(glob($eigen . '/sess_*') ?: []);
$seite = $holen($basis . '/login', $ordner . '/dose-ohne.txt');
gleich($seite['status'], 200, 'Anmeldeseite (ohne Cookie)');
clearstatcache();
pruefe(!is_file($alt), 'Sitzungsdatei älter als die Lebensdauer (1000 s > 900 s) weggeräumt – Debians 1440 s gelten nicht');
pruefe(is_file($jung), 'jüngere Sitzungsdatei (800 s) bleibt, auch älter als idle_timeout (600 s)');
pruefe(is_file($fremd), 'andere Dateien im Verzeichnis bleiben');
gleich(count(glob($eigen . '/sess_*') ?: []), $vorher, 'eine weg, eine neue für die Anmeldeseite dazu');

// Angemeldet: die Sitzung überdauert die GC, auch wenn ihre Datei schon eine
// Weile alt ist – Veraltetes daneben verschwindet.
$dose = $ordner . '/dose.txt';
preg_match('/name="_token" value="([^"]+)"/', $holen($basis . '/login', $dose)['text'], $m);
$anmeldung = $holen($basis . '/login', $dose, ['_token' => $m[1] ?? '', 'username' => 'pruefer', 'password' => $kennwort]);
pruefe($anmeldung['status'] === 302 && !str_contains($anmeldung['ziel'], '/login'), 'Anmeldung');
preg_match('/\tpm_session\t([A-Za-z0-9,-]+)\s*$/m', (string) @file_get_contents($dose), $m);
$meine = $eigen . '/sess_' . ($m[1] ?? 'fehlt');
pruefe(is_file($meine), 'Sitzungsdatei der Anmeldung gefunden');
touch($meine, time() - 850);
$alt = $alteDatei($eigen, 'sess_' . $id . 'alt2', 950);
$profil = $holen($basis . '/profil', $dose);
gleich($profil['status'], 200, 'angemeldet geblieben (Profil statt Anmeldung)');
clearstatcache();
pruefe(!is_file($alt), 'daneben eine veraltete Sitzungsdatei weggeräumt');
pruefe(is_file($meine) && filemtime($meine) >= time() - 60, 'die eigene Sitzungsdatei bleibt und ist wieder frisch');

// Ohne jede Grenze räumt auch eine eingeschaltete GC nichts weg.
$ohne = $ordner . '/sitzungen-ohne-grenze';
mkdir($ohne, 0700);
$basisOhne = $server($ohneGrenze, $ohne);
$uralt = $alteDatei($ohne, 'sess_' . $id . 'uralt', 100000);
gleich($holen($basisOhne . '/login', $ordner . '/dose-ohne-grenze.txt')['status'], 200, 'Anmeldeseite (ohne Grenze)');
clearstatcache();
pruefe(is_file($uralt), 'ohne idle_timeout und max_session: auch eine Datei von vor 28 Stunden bleibt');

clearstatcache();
$meldungen = is_file($fehlerprotokoll) ? trim((string) file_get_contents($fehlerprotokoll)) : '';
gleich($meldungen, '', 'keine PHP-Meldung im Fehlerprotokoll der Server');

ende('sitzung-gc');
