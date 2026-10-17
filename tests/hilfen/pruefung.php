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

/** Als Benutzer handeln (ohne Sitzung). */
function alsBenutzer(?int $id): void
{
    \App\Auth\Auth::alsBenutzer($id);
}
