<?php
declare(strict_types=1);

/**
 * Gemeinsame Hilfen der Prüfskripte – kein Testrahmen, nur das Nötigste.
 *
 * Jedes Skript: `php tests/<name>.php`, Exit 0 = grün. Wie in MasterAdmin
 * gilt: ein Prüfskript räumt weg, was es anlegt, und wählt nichts über die
 * kleinste ID, sondern über eine Eigenschaft.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$GLOBALS['__pruefungen'] = 0;
$GLOBALS['__fehler'] = [];

function pruefe(bool $bedingung, string $text): void
{
    $GLOBALS['__pruefungen']++;
    if (!$bedingung) {
        $GLOBALS['__fehler'][] = $text;
        fwrite(STDERR, "  ✗ {$text}\n");
    }
}

function gleich(mixed $ist, mixed $soll, string $text): void
{
    pruefe($ist == $soll, $text . ' (ist: ' . var_export($ist, true) . ', soll: ' . var_export($soll, true) . ')');
}

function nahe(?float $ist, float $soll, float $toleranz, string $text): void
{
    pruefe($ist !== null && abs($ist - $soll) <= $toleranz, $text . ' (ist: ' . var_export($ist, true) . ', soll: ' . $soll . ')');
}

/** Am Ende aufrufen: fasst zusammen und setzt den Rückgabewert. */
function ende(string $name): never
{
    $n = $GLOBALS['__pruefungen'];
    $f = count($GLOBALS['__fehler']);
    if ($f > 0) {
        fwrite(STDOUT, "{$name}: {$f} von {$n} Prüfungen fehlgeschlagen\n");
        exit(1);
    }
    fwrite(STDOUT, "{$name}: {$n} Prüfungen bestanden\n");
    exit(0);
}

function uebersprungen(string $name, string $grund): never
{
    fwrite(STDOUT, "{$name}: übersprungen – {$grund}\n");
    exit(0);
}

/** Einen freien Port finden. */
function freierPort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $nr, $text);
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** Ist unter host:port etwas erreichbar? */
function erreichbar(string $host, int $port): bool
{
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, 1);
    if ($s === false) {
        return false;
    }
    fclose($s);
    return true;
}

/**
 * Einen Kindprozess starten; wird beim Beenden des Skripts gestoppt.
 *
 * $umgebung ergänzt die Umgebung dieses Skripts oder überschreibt einzelne
 * Werte – etwa POWERMANAGEMENT_CONFIG für eine zweite Instanz oder
 * PHP_CLI_SERVER_WORKERS, damit der eingebaute Server sich selbst aufrufen kann.
 * $fehlerDatei nimmt die Standardfehlerausgabe auf – beim eingebauten Server
 * stehen dort die PHP-Warnungen (display_errors ist ohne app.debug aus).
 *
 * @param array<string,string>|null $umgebung
 */
function hintergrund(array $befehl, ?array $umgebung = null, ?string $fehlerDatei = null): mixed
{
    $p = proc_open($befehl, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $fehlerDatei ?? '/dev/null', 'a']], $rohre, null,
        $umgebung === null ? null : $umgebung + getenv());
    register_shutdown_function(static function () use ($p): void {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    });
    return $p;
}

/**
 * Eine Anfrage an die Schnittstelle (/api/v1 …) – ohne Sitzung, folgt keinen
 * Weiterleitungen.
 *
 * $token kommt als „Authorization: Bearer …“ mit (null: ohne). Ein $rumpf
 * geht ohne eigene Angabe als application/json hinaus; $kopf sind weitere
 * Zeilen („Content-Type: text/plain“).
 *
 * @param list<string> $kopf
 * @return array{status:int,kopf:string,json:mixed,roh:string}
 */
function api(string $basis, string $methode, string $pfad, ?string $token, ?string $rumpf = null, array $kopf = []): array
{
    if ($token !== null) {
        $kopf[] = 'Authorization: Bearer ' . $token;
    }
    if ($rumpf !== null && preg_grep('/^content-type:/i', $kopf) === []) {
        $kopf[] = 'Content-Type: application/json';
    }
    // Ohne das schickt curl bei großen Körpern „Expect: 100-continue“ und wartet.
    $kopf[] = 'Expect:';
    $c = curl_init(rtrim($basis, '/') . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => strtoupper($methode),
        CURLOPT_HTTPHEADER     => $kopf,
    ]);
    if (strtoupper($methode) === 'HEAD') {
        curl_setopt($c, CURLOPT_NOBODY, true);
    }
    if ($rumpf !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $rumpf);
    }
    $roh = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $koerper = (string) substr($roh, $kopfLaenge);
    return [
        'status' => $status,
        'kopf'   => substr($roh, 0, $kopfLaenge),
        'json'   => json_decode($koerper, true),
        'roh'    => $koerper,
    ];
}

/**
 * PHP-Code in einer zweiten Instanz ausführen und sein Ergebnis holen.
 *
 * Die zweite Instanz ist dieselbe Arbeitskopie mit eigener Konfiguration
 * ($configDatei, über POWERMANAGEMENT_CONFIG) – also eigener Datenbank. $php
 * ist der Inhalt einer PHP-Datei ohne „<?php“ (use-Zeilen erlaubt); er läuft
 * nach dem Bootstrap dort und gibt sein Ergebnis mit `return` zurück, als
 * JSON hierher. Bricht er ab, wird das hier zur Ausnahme mit der Meldung.
 */
function inB(string $configDatei, string $php): mixed
{
    $ordner = sys_get_temp_dir() . '/pm-inb-' . bin2hex(random_bytes(5));
    mkdir($ordner, 0700, true);
    $marke = '--pm-inB-' . bin2hex(random_bytes(5)) . '--';
    try {
        file_put_contents($ordner . '/code.php', "<?php\ndeclare(strict_types=1);\n" . $php . "\n");
        file_put_contents($ordner . '/lauf.php', "<?php\ndeclare(strict_types=1);\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/src/bootstrap.php', true) . ";\n"
            . "\$__wert = require __DIR__ . '/code.php';\n"
            . 'echo ' . var_export("\n" . $marke . "\n", true)
            . " . json_encode(\$__wert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);\n");
        $p = proc_open([PHP_BINARY, $ordner . '/lauf.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $ordner . '/aus.txt', 'w'], 2 => ['file', $ordner . '/fehler.txt', 'w']],
            $rohre, $ordner, ['POWERMANAGEMENT_CONFIG' => $configDatei] + getenv());
        if (!is_resource($p)) {
            throw new RuntimeException('inB: PHP ließ sich nicht starten.');
        }
        $rc = proc_close($p);
        $aus = (string) @file_get_contents($ordner . '/aus.txt');
        $fehler = trim((string) @file_get_contents($ordner . '/fehler.txt'));
        $stelle = strrpos($aus, $marke);
        if ($rc !== 0 || $stelle === false) {
            throw new RuntimeException('inB: ' . ($fehler !== '' ? $fehler : trim($aus)) . ' (Rückgabe ' . $rc . ')');
        }
        return json_decode(substr($aus, $stelle + strlen($marke) + 1), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (['code.php', 'lauf.php', 'aus.txt', 'fehler.txt'] as $datei) {
            @unlink($ordner . '/' . $datei);
        }
        @rmdir($ordner);
    }
}

/** Als Benutzer handeln (ohne Sitzung). */
function alsBenutzer(?int $id): void
{
    \App\Auth\Auth::alsBenutzer($id);
}
