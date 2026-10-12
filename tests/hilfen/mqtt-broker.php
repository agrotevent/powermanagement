<?php
declare(strict_types=1);

/**
 * Prüf-Hilfen für die eigenen MQTT-Zugänge der Shellys.
 *
 * Ein eigener Mosquitto je Prüfung: Temp-Verzeichnis, freier Port, Anmeldung
 * Pflicht, Kennwortdatei mit den Systembenutzern, Grundlage der ACL aus
 * deploy/mosquitto/powermanagement.acl – und die wirksame ACL baut der
 * echte Helfer deploy/mqtt-zugaenge.sh. So prüfen die Skripte genau die
 * Rechte, die eine Installation hat.
 *
 * Nach tests/hilfen/pruefung.php einbinden. Als Skript aufgerufen
 * (`php mqtt-broker.php waechter <json>`) ist diese Datei der Wächter, der in
 * den Prüfungen die systemd-Pfad-Einheit ersetzt.
 */

/** Der Helfer, den auch systemd startet. */
function mqttHelferPfad(): string
{
    return dirname(__DIR__, 2) . '/deploy/mqtt-zugaenge.sh';
}

/** Mosquitto-Hash wie mosquitto_passwd ($7$, 101 Runden). */
function mqttHash(string $kennwort): string
{
    $salz = random_bytes(12);
    return '$7$101$' . base64_encode($salz) . '$' . base64_encode(hash_pbkdf2('sha512', $kennwort, $salz, 101, 64, true));
}

/** Pfad zum Broker-Programm – oder null, wenn Mosquitto fehlt. */
function mqttMosquittoPfad(): ?string
{
    if (class_exists(\App\Support\Programm::class)) {
        $gefunden = \App\Support\Programm::finden('mosquitto', ['-h']);
        if ($gefunden !== null) {
            return $gefunden;
        }
    }
    foreach (['/usr/sbin/mosquitto', '/usr/local/sbin/mosquitto'] as $pfad) {
        if (is_file($pfad) && is_executable($pfad)) {
            return $pfad;
        }
    }
    return null;
}

/**
 * Alles, was beim Ende des Skripts weg muss: erst die Wächter (sie starten
 * sonst den Helfer in ein Verzeichnis, das gerade verschwindet), dann die
 * Broker, dann die Verzeichnisse.
 */
function mqttAufraeumenVormerken(string $art, mixed $wert): void
{
    static $angemeldet = false;
    $GLOBALS['__mqtt_aufraeumen'][$art][] = $wert;
    if ($angemeldet) {
        return;
    }
    $angemeldet = true;
    register_shutdown_function(static function (): void {
        $liste = $GLOBALS['__mqtt_aufraeumen'] ?? [];
        foreach (['waechter', 'broker'] as $art) {
            foreach ($liste[$art] ?? [] as $p) {
                if (is_resource($p)) {
                    proc_terminate($p);
                    proc_close($p);
                }
            }
        }
        foreach ($liste['verzeichnis'] ?? [] as $dir) {
            // Ein Helfer, den ein Wächter noch gestartet hat, hält die Sperre
            // – erst danach löschen.
            $sperre = @fopen($dir . '/zugaenge.lock', 'c');
            if ($sperre !== false) {
                for ($i = 0; $i < 50 && !flock($sperre, LOCK_EX | LOCK_NB); $i++) {
                    usleep(100_000);
                }
                fclose($sperre);
            }
            mqttVerzeichnisLoeschen($dir);
        }
    });
}

function mqttVerzeichnisLoeschen(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($eintraege as $eintrag) {
        $eintrag->isDir() && !$eintrag->isLink() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
    }
    @rmdir($dir);
}

/** Wie oft hat der Broker seine Konfiguration neu geladen (laut Protokoll)? */
function mqttNeuGeladen(array $broker): int
{
    return substr_count((string) @file_get_contents($broker['dir'] . '/mosquitto.log'), 'Reloading config.');
}

/**
 * Eigener Mosquitto für eine Prüfung: Temp-Verzeichnis, freier Port, allow_anonymous false,
 * Kennwortdatei mit den Systembenutzern ($nutzer: name => kennwort, z. B. shelly/nodered/powermanagement),
 * Grundlage = deploy/mosquitto/powermanagement.acl (oder $basisAcl-Inhalt), wirksame ACL vom echten Helfer gebaut,
 * mosquitto.conf mit acl_file = wirksame Datei, `user root` wenn EUID 0. Startet im Hintergrund, wartet bis erreichbar.
 *
 * @param array<string,string> $nutzer
 * @return array{host:string,port:int,pid:int,dir:string,passwd:string,basis:string,wirksam:string,conf:string,
 *               soll:string,ist:string,lock:string,marke:string,mqttdir:string}
 *   mqttdir = Verzeichnis mit soll/ und ist/ (für Config mqtt.zugaenge_verzeichnis)
 *   marke   = Marke „Neuladen steht aus“ (statt /etc/mosquitto/powermanagement-mqtt-zugaenge.neuladen)
 */
function mqttTestbroker(array $nutzer, ?string $basisAcl = null): array
{
    $mosquitto = mqttMosquittoPfad();
    if ($mosquitto === null) {
        throw new RuntimeException('mosquitto ist nicht installiert');
    }
    $dir = sys_get_temp_dir() . '/pm-mqtt-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Temp-Verzeichnis für den Broker ließ sich nicht anlegen');
    }
    mqttAufraeumenVormerken('verzeichnis', $dir);
    mkdir($dir . '/mqtt/soll', 0750, true);
    mkdir($dir . '/mqtt/ist', 0750);

    $broker = [
        'host' => '127.0.0.1',
        'port' => freierPort(),
        'pid' => 0,
        'dir' => $dir,
        'passwd' => $dir . '/powermanagement.passwd',
        'basis' => $dir . '/powermanagement.acl',
        'wirksam' => $dir . '/powermanagement-wirksam.acl',
        'conf' => $dir . '/mosquitto.conf',
        'soll' => $dir . '/mqtt/soll/zugaenge.txt',
        'ist' => $dir . '/mqtt/ist/ergebnis.txt',
        'lock' => $dir . '/zugaenge.lock',
        'marke' => $dir . '/zugaenge.neuladen',
        'mqttdir' => $dir . '/mqtt',
    ];

    $passwd = '';
    foreach ($nutzer as $name => $kennwort) {
        $passwd .= $name . ':' . mqttHash((string) $kennwort) . "\n";
    }
    file_put_contents($broker['passwd'], $passwd);
    chmod($broker['passwd'], 0600);
    $basis = $basisAcl ?? (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/mosquitto/powermanagement.acl');
    file_put_contents($broker['basis'], $basis);
    chmod($broker['basis'], 0600);

    $root = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string) shell_exec('id -u')) === '0';
    $conf = "# Prüf-Broker aus tests/hilfen/mqtt-broker.php\n"
        . ($root ? "user root\n" : '')
        . "per_listener_settings false\n"
        . "listener {$broker['port']} 127.0.0.1\n"
        . "allow_anonymous false\n"
        . "password_file {$broker['passwd']}\n"
        . "acl_file {$broker['wirksam']}\n"
        // Wie deploy/mosquitto/broker.conf: Retained-Nachrichten nur, solange
        // ihr Absender noch schreiben darf.
        . "check_retain_source true\n"
        . "persistence false\n"
        . "log_dest file {$dir}/mosquitto.log\n"
        . "log_type error\nlog_type warning\nlog_type notice\nlog_type information\n";
    file_put_contents($broker['conf'], $conf);

    // Wirksame ACL vom echten Helfer – vor dem Start, ohne Neuladen.
    $erst = mqttHelfer($broker, ['--neu-laden=nein']);
    // Der Broker startet erst jetzt und liest die Dateien so, wie sie sind –
    // nichts steht aus (wie bei der Installation, die danach neu startet).
    @unlink($broker['marke']);
    if (!is_file($broker['wirksam'])) {
        // Grundlage unbrauchbar (so gewollt in einer Prüfung): wie die
        // Installation dann wenigstens die Grundlage nehmen.
        copy($broker['basis'], $broker['wirksam']);
        chmod($broker['wirksam'], 0600);
    }

    $p = hintergrund([$mosquitto, '-c', $broker['conf']]);
    mqttAufraeumenVormerken('broker', $p);
    $broker['pid'] = (int) (proc_get_status($p)['pid'] ?? 0);
    for ($i = 0; $i < 50 && !erreichbar($broker['host'], $broker['port']); $i++) {
        usleep(100_000);
    }
    if (!erreichbar($broker['host'], $broker['port'])) {
        throw new RuntimeException('Prüf-Broker startet nicht: ' . trim((string) @file_get_contents($dir . '/mosquitto.log'))
            . ' / Helfer: ' . trim($erst['fehler']));
    }
    return $broker;
}

/**
 * Helfer mit den Pfaden des Testbrokers (--app-benutzer= --ohne-eigentuemerpruefung --neu-laden=pid:<pid>,
 * Sperrdatei und Neulade-Marke im Temp-Verzeichnis – nie unter /etc/mosquitto).
 * Eine Option in $zusatz ersetzt die gleichnamige Vorgabe. Hat der Helfer
 * etwas geändert und per SIGHUP neu laden lassen, wartet die Funktion, bis
 * der Broker das getan hat.
 *
 * @param list<string> $zusatz
 * @return array{code:int,ausgabe:string,fehler:string}
 */
function mqttHelfer(array $broker, array $zusatz = [], ?string $stdin = null): array
{
    $vorgaben = [
        '--ziel' => $broker['dir'],
        '--app-benutzer' => '',
        '--ohne-eigentuemerpruefung' => null,
        '--neu-laden' => $broker['pid'] > 0 ? 'pid:' . $broker['pid'] : 'nein',
        '--passwd' => $broker['passwd'],
        '--basis' => $broker['basis'],
        '--wirksam' => $broker['wirksam'],
        '--conf' => $broker['conf'],
        '--soll' => $broker['soll'],
        '--ist' => $broker['ist'],
        '--lock' => $broker['lock'],
        '--neuladen-marke' => $broker['marke'] ?? $broker['dir'] . '/zugaenge.neuladen',
    ];
    foreach ($zusatz as $option) {
        unset($vorgaben[explode('=', $option, 2)[0]]);
    }
    $befehl = ['bash', mqttHelferPfad()];
    foreach ($vorgaben as $schluessel => $wert) {
        $befehl[] = $wert === null ? $schluessel : $schluessel . '=' . $wert;
    }
    array_push($befehl, ...$zusatz);
    $neuLaden = $vorgaben['--neu-laden'] ?? null;
    foreach ($zusatz as $option) {
        if (str_starts_with($option, '--neu-laden=')) {
            $neuLaden = substr($option, 12);
        }
    }

    $vorher = mqttNeuGeladen($broker);
    $aus = tempnam(sys_get_temp_dir(), 'pm-helfer-');
    $err = tempnam(sys_get_temp_dir(), 'pm-helfer-');
    $p = proc_open($befehl, [0 => ['pipe', 'r'], 1 => ['file', $aus, 'w'], 2 => ['file', $err, 'w']], $rohre);
    if (!is_resource($p)) {
        @unlink($aus);
        @unlink($err);
        return ['code' => -1, 'ausgabe' => '', 'fehler' => 'Helfer ließ sich nicht starten'];
    }
    if ($stdin !== null) {
        fwrite($rohre[0], $stdin);
    }
    fclose($rohre[0]);
    $code = proc_close($p);
    $ergebnis = ['code' => $code, 'ausgabe' => (string) file_get_contents($aus), 'fehler' => (string) file_get_contents($err)];
    @unlink($aus);
    @unlink($err);

    $zeilen = explode("\n", trim($ergebnis['ausgabe']));
    if (is_string($neuLaden) && str_starts_with($neuLaden, 'pid:') && end($zeilen) === 'geaendert') {
        for ($i = 0; $i < 50 && mqttNeuGeladen($broker) <= $vorher; $i++) {
            usleep(50_000);
        }
        // Die Zeile steht im Protokoll, bevor die neuen Rechte gelten –
        // einen Augenblick mehr.
        usleep(100_000);
    }
    return $ergebnis;
}

/**
 * Hintergrund: beobachtet soll/zugaenge.txt (alle 100 ms, mtime/Inhalt) und ruft bei Änderung
 * mqttHelfer auf – ersetzt die .path-Einheit.
 */
function mqttWaechterStarten(array $broker): void
{
    $p = hintergrund([PHP_BINARY, __FILE__, 'waechter', json_encode($broker, JSON_UNESCAPED_SLASHES)]);
    mqttAufraeumenVormerken('waechter', $p);
}

/** Schleife des Wächters (eigener Prozess). */
function mqttWaechterLauf(array $broker): void
{
    $zustand = static function () use ($broker): string {
        clearstatcache(true, $broker['soll']);
        if (!is_file($broker['soll'])) {
            return 'fehlt';
        }
        return (string) @filemtime($broker['soll']) . ':' . md5((string) @file_get_contents($broker['soll']));
    };
    // Liegt schon ein Soll da, gleich einmal abgleichen.
    $letzter = is_file($broker['soll']) ? '' : 'fehlt';
    // Stirbt die Prüfung ohne Aufräumen (kill -9), endet der Wächter mit ihr.
    $eltern = function_exists('posix_getppid') ? posix_getppid() : null;
    while (is_dir($broker['dir']) && ($eltern === null || posix_getppid() === $eltern)) {
        $jetzt = $zustand();
        if ($jetzt !== $letzter) {
            mqttHelfer($broker);
            $letzter = $jetzt;
        }
        usleep(100_000);
    }
}

/** Verbindung als beliebiger Benutzer (wirft bei Ablehnung wie Mqtt::verbinden). */
function mqttVerbinden(array $broker, string $benutzer, string $kennwort, string $clientId = ''): \App\Messung\Mqtt
{
    $client = new \App\Messung\Mqtt();
    $client->verbinden($broker['host'], (int) $broker['port'], $benutzer, $kennwort,
        $clientId !== '' ? $clientId : 'pm-pruef-' . bin2hex(random_bytes(4)));
    return $client;
}

/** Kommt eine Veröffentlichung von $sender auf $thema bei $empfaenger an? (Abo, Senden QoS 1, kurz lauschen) */
function mqttKommtAn(\App\Messung\Mqtt $sender, \App\Messung\Mqtt $empfaenger, string $thema, float $zeit = 0.6): bool
{
    $nutzlast = 'pm-probe-' . bin2hex(random_bytes(6));
    $empfaenger->abonnieren([$thema]);
    // QoS 1: Mosquitto bestätigt auch eine verworfene Nachricht (MQTT 3.1.1).
    $sender->veroeffentlichen($thema, $nutzlast, 1);
    $angekommen = false;
    $empfaenger->lauschen($zeit, static function (string $t, string $n) use ($thema, $nutzlast, &$angekommen): bool {
        if ($t === $thema && $n === $nutzlast) {
            $angekommen = true;
        }
        return $angekommen;
    });
    return $angekommen;
}

// ------------------------------------------------------------- Wächter
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__
    && ($_SERVER['argv'][1] ?? '') === 'waechter') {
    $daten = json_decode((string) ($_SERVER['argv'][2] ?? ''), true);
    if (!is_array($daten) || !isset($daten['dir'], $daten['soll'])) {
        fwrite(STDERR, "Aufruf: php mqtt-broker.php waechter <broker als JSON>\n");
        exit(1);
    }
    mqttWaechterLauf($daten);
    exit(0);
}
