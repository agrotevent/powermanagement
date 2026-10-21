<?php
declare(strict_types=1);

/**
 * Update-Helfer deploy/update-helfer.sh – ohne root und ohne systemd.
 *
 * Echtes Bare-Repository mit Klon als --ziel: 60 neue Commits mit
 * Steuerzeichen, Umlauten und <script> in Betreffen, Trailer
 * „Update-Hinweis:“, ein zusammengeführter Seitenzweig, eine Migration und
 * eine Änderung in deploy/. Statt update-debian.sh läuft die Attrappe
 * tests/hilfen/update-attrappe.sh (je Fall eine Kopie mit Datei „fall“).
 *
 * Geprüft: Lebenszeichen und Stand ohne Auftrag; Suchen (Felder aus git,
 * Drossel, .git/index unverändert); Kandidaten auch von einem Seitenzweig aus;
 * nicht linear; Aktualisieren mit jedem Ergebnis (Lauf, Verlauf, Kennung,
 * Zeiten, gescheiterte Stände, Protokoll); argv und Umgebung; Ablehnungen
 * (Kopf, Zeichen, Größe, Form, Schlüssel, Werte, Zeit, Kennung, update.conf,
 * Abstände, gescheiterte Stände, Verweis, FIFO, Ordner); Sperre; Signale als
 * TERM; Wiederanlauf; Nachlauf; Protokoll bereinigt und gekürzt; keine
 * Geheimnisse; --auftrag-pruefen und der Abgleich mit dem Auftrag, den die
 * Anwendung baut (UpdateService::auftragText). Als root zusätzlich: Prüfung des Codes
 * (root-eigen, keine Schreibrechte für andere, keine Verweise) und der Weg
 * über setpriv als App-Benutzer.
 */

require __DIR__ . '/hilfen/pruefung.php';

foreach (['bash', 'git', 'flock', 'timeout', 'tail', 'mkfifo'] as $programm) {
    if (trim((string) shell_exec('command -v ' . escapeshellarg($programm) . ' 2>/dev/null')) === '') {
        uebersprungen('update-helfer', $programm . ' fehlt');
    }
}

// Läuft das Prüfskript selbst unter systemd (etwa als GitHub-Runner), erbte der
// Helfer dessen INVOCATION_ID und hielte sich für einen Lauf des Dienstes. Hier
// läuft er ohne systemd; wer eine Kennung braucht, gibt sie ausdrücklich mit.
putenv('INVOCATION_ID');
unset($_ENV['INVOCATION_ID'], $_SERVER['INVOCATION_ID']);

const KOPF = "# powermanagement update-auftrag 1\n";
define('HELFER', dirname(__DIR__) . '/deploy/update-helfer.sh');
define('ATTRAPPE', __DIR__ . '/hilfen/update-attrappe.sh');
define('ALS_ROOT', function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string) shell_exec('id -u')) === '0');

umask(022);
$kennung = 'pm-update-helfer-' . bin2hex(random_bytes(4));
$basis = sys_get_temp_dir() . '/' . $kennung;
mkdir($basis, 0755);
$hintergrund = [];
register_shutdown_function(static function () use ($basis, &$hintergrund): void {
    foreach ($hintergrund as $p) {
        if (is_resource($p)) {
            proc_terminate($p, 9);
            proc_close($p);
        }
    }
    if (is_dir($basis) && str_contains($basis, 'pm-update-helfer-')) {
        exec('chmod -R u+rwX ' . escapeshellarg($basis) . ' 2>/dev/null; rm -rf ' . escapeshellarg($basis));
    }
});

$d = [
    'soll' => "$basis/storage/update/soll", 'ist' => "$basis/storage/update/ist", 'zustand' => "$basis/zustand",
    'protokolle' => "$basis/protokolle", 'run' => "$basis/run", 'etc' => "$basis/etc",
    'repo' => "$basis/repo.git", 'quelle' => "$basis/quelle", 'ziel' => "$basis/ziel", 'faelle' => "$basis/faelle",
];
foreach (['soll', 'ist', 'zustand', 'protokolle', 'run', 'etc', 'faelle'] as $k) {
    mkdir($d[$k], 0755, true);
}
chmod($d['zustand'], 0700);
chmod($d['protokolle'], 0700);
chmod($d['run'], 0755);
$sperre = $d['etc'] . '/update-helfer.lock';
$usperre = $d['etc'] . '/update.lock';
$conf = $d['etc'] . '/update.conf';

// ------------------------------------------------------------- Hilfen
/** Befehl ohne Shell; Rückgabe [code, ausgabe, fehler, dauer]. */
function ausfuehren(array $befehl, ?string $stdin = null, array $umgebung = []): array
{
    $aus = tempnam(sys_get_temp_dir(), 'pm-uh-');
    $err = tempnam(sys_get_temp_dir(), 'pm-uh-');
    $start = microtime(true);
    $p = proc_open($befehl, [0 => ['pipe', 'r'], 1 => ['file', $aus, 'w'], 2 => ['file', $err, 'w']], $rohre, null,
        $umgebung === [] ? null : $umgebung + getenv());
    if (!is_resource($p)) {
        return ['code' => -1, 'aus' => '', 'err' => 'Start fehlgeschlagen', 'dauer' => 0.0];
    }
    if ($stdin !== null) {
        fwrite($rohre[0], $stdin);
    }
    fclose($rohre[0]);
    $code = proc_close($p);
    $r = ['code' => $code, 'aus' => (string) file_get_contents($aus), 'err' => (string) file_get_contents($err),
        'dauer' => microtime(true) - $start];
    @unlink($aus);
    @unlink($err);
    return $r;
}

/** git mit fester Identität und ohne fremde Konfiguration; wirft bei Fehler. */
function git(string $dir, string ...$args): string
{
    $umgebung = ['GIT_AUTHOR_NAME' => 'Prüfung', 'GIT_AUTHOR_EMAIL' => 'pruefung@example.invalid',
        'GIT_COMMITTER_NAME' => 'Prüfung', 'GIT_COMMITTER_EMAIL' => 'pruefung@example.invalid',
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0'];
    $r = ausfuehren(['git', '-c', 'safe.directory=*', '-c', 'init.defaultBranch=main', '-c', 'commit.gpgsign=false',
        '-C', $dir, ...$args], null, $umgebung);
    if ($r['code'] !== 0) {
        throw new RuntimeException('git ' . implode(' ', $args) . ': ' . $r['err']);
    }
    return rtrim($r['aus'], "\n");
}

/** Datei im Format „schlüssel wert“ lesen; Listen für mehrfache Schlüssel. */
function lesen(string $pfad): array
{
    $listen = ['warnung', 'ziel_kandidat', 'commit', 'hinweis', 'gescheitert', 'eintrag', 'arg', 'env'];
    $e = ['_kopf' => null, '_roh' => null];
    foreach ($listen as $l) {
        $e[$l] = [];
    }
    clearstatcache(true, $pfad);
    if (!is_file($pfad)) {
        return $e;
    }
    $roh = (string) file_get_contents($pfad);
    $e['_roh'] = $roh;
    $zeilen = explode("\n", $roh);
    $e['_kopf'] = $zeilen[0] ?? '';
    foreach (array_slice($zeilen, 1) as $z) {
        if ($z === '') {
            continue;
        }
        [$k, $v] = array_pad(explode(' ', $z, 2), 2, '');
        if (in_array($k, $listen, true)) {
            $e[$k][] = $v;
        } elseif (!array_key_exists($k, $e)) {
            $e[$k] = $v;
        }
    }
    return $e;
}

function modus(string $pfad): string
{
    clearstatcache(true, $pfad);
    return is_file($pfad) ? sprintf('%o', fileperms($pfad) & 0777) : '-';
}

function warten(callable $bedingung, float $sekunden): bool
{
    $ende = microtime(true) + $sekunden;
    do {
        if ($bedingung()) {
            return true;
        }
        usleep(50_000);
    } while (microtime(true) < $ende);
    return (bool) $bedingung();
}

$T = time();
$ausgaben = '';
$fallNr = 0;

/** Neuer Fall: Kopie der Attrappe mit Datei „fall“; gibt den Pfad des Skripts zurück. */
$fall = static function (array $werte = []) use ($d, &$fallNr): string {
    $fallNr++;
    $dir = $d['faelle'] . '/' . $fallNr;
    mkdir($dir, 0755);
    copy(ATTRAPPE, $dir . '/update-debian.sh');
    $text = '';
    foreach ($werte as $k => $v) {
        $text .= $k . ' ' . $v . "\n";
    }
    file_put_contents($dir . '/fall', $text);
    return $dir . '/update-debian.sh';
};
$fallDir = static fn (string $skript): string => dirname($skript);
$aufgerufen = static fn (string $skript): bool => is_file(dirname($skript) . '/aufruf');

/** Optionen des Helfers für diesen Test (Zusätze ersetzen gleichnamige). */
$optionen = static function (array $zusatz) use ($d, $sperre, $usperre, $conf, &$T): array {
    $vorgaben = [
        '--ziel' => $d['ziel'], '--app-benutzer' => '', '--ohne-eigentuemerpruefung' => null, '--ohne-systemd' => null,
        '--soll' => $d['soll'], '--ist' => $d['ist'], '--zustand' => $d['zustand'], '--protokolle' => $d['protokolle'],
        '--sperre' => $sperre, '--update-sperre' => $usperre, '--update-conf' => $conf, '--arbeit-basis' => $d['run'],
        '--jetzt' => (string) $T,
    ];
    $extra = [];
    foreach ($zusatz as $schluessel => $wert) {
        if (is_int($schluessel)) {
            $extra[] = $wert;
            continue;
        }
        if ($wert === false) {
            unset($vorgaben[$schluessel]);
        } else {
            $vorgaben[$schluessel] = $wert;
        }
    }
    $befehl = ['bash', HELFER];
    foreach ($vorgaben as $k => $v) {
        $befehl[] = $v === null ? $k : $k . '=' . $v;
    }
    return [...$befehl, ...$extra];
};
$helfer = static function (array $zusatz = [], array $umgebung = [], ?string $stdin = null) use ($optionen, &$ausgaben): array {
    $r = ausfuehren($optionen($zusatz), $stdin, $umgebung);
    $ausgaben .= $r['aus'] . $r['err'];
    return $r;
};
$helferHintergrund = static function (array $zusatz = []) use ($optionen, &$hintergrund, $basis): array {
    $aus = $basis . '/hintergrund-' . bin2hex(random_bytes(3)) . '.txt';
    $p = proc_open($optionen($zusatz), [0 => ['file', '/dev/null', 'r'], 1 => ['file', $aus, 'w'], 2 => ['file', $aus, 'a']], $rohre);
    $hintergrund[] = $p;
    return ['p' => $p, 'aus' => $aus];
};
$sperreHalten = static function (string $datei) use (&$hintergrund): mixed {
    // -o: der Befehl erbt die Sperre nicht – endet flock, ist sie frei.
    $p = proc_open(['flock', '-o', $datei, 'sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w']], $rohre);
    $hintergrund[] = $p;
    warten(static fn (): bool => ausfuehren(['flock', '-n', $datei, 'true'])['code'] !== 0, 5);
    return $p;
};
$sperreLoesen = static function (mixed $p): void {
    if (is_resource($p)) {
        proc_terminate($p, 15);
        proc_close($p);
    }
};

$roh = static function (string $text) use ($d): void {
    file_put_contents($d['soll'] . '/.auftrag-neu', $text);
    rename($d['soll'] . '/.auftrag-neu', $d['soll'] . '/auftrag');
};
$auftragText = static function (string $aktion, ?string $ziel = null, string $ausloeser = 'hand', ?int $zeit = null,
    ?string $k = null) use (&$T): array {
    $k ??= bin2hex(random_bytes(16));
    $text = KOPF . "kennung {$k}\naktion {$aktion}\nausloeser {$ausloeser}\nvon pruefung\nzeit " . ($zeit ?? $T) . "\n"
        . ($ziel !== null ? "ziel {$ziel}\n" : '');
    return [$k, $text];
};
$auftrag = static function (string $aktion, ?string $ziel = null, string $ausloeser = 'hand', ?int $zeit = null,
    ?string $k = null) use ($auftragText, $roh): string {
    [$k, $text] = $auftragText($aktion, $ziel, $ausloeser, $zeit, $k);
    $roh($text);
    return $k;
};
$auftragWeg = static function () use ($d): bool {
    clearstatcache();
    return !file_exists($d['soll'] . '/auftrag') && !is_link($d['soll'] . '/auftrag')
        && glob($d['soll'] . '/.uebernommen-*') === [];
};
$lauf = static fn (): array => lesen($d['ist'] . '/lauf');
$stand = static fn (): array => lesen($d['ist'] . '/stand');

// ------------------------------------------------------------- Repository
// B0 – M1 … M30 – X (Merge des Seitenzweigs S1–S3 ab M10) – M31 … M57:
// 60 Commits ohne Merges seit B0.
$sha = [];
git($basis, 'init', '-q', '--bare', '-b', 'main', $d['repo']);
git($basis, 'init', '-q', '-b', 'main', $d['quelle']);
$schreiben = static function (string $pfad, string $inhalt) use ($d): void {
    $voll = $d['quelle'] . '/' . $pfad;
    if (!is_dir(dirname($voll))) {
        mkdir(dirname($voll), 0755, true);
    }
    file_put_contents($voll, $inhalt);
};
$einchecken = static function (string $name, string $nachricht) use ($d, $basis, &$sha): string {
    $datei = $basis . '/nachricht.txt';
    file_put_contents($datei, $nachricht);
    git($d['quelle'], 'add', '-A');
    git($d['quelle'], 'commit', '-q', '--allow-empty', '--cleanup=verbatim', '-F', $datei);
    return $sha[$name] = git($d['quelle'], 'rev-parse', 'HEAD');
};
$schreiben('README.md', "PowerManagement\n");
$schreiben('bin/power', "#!/usr/bin/env php\n<?php\n");
$schreiben('deploy/x.conf', "# Vorlage\n");
$schreiben('deploy/node-red/flows.json', "[]\n");
$schreiben('database/migrations/001_basis.sql', "CREATE TABLE a (id INTEGER);\n");
$einchecken('B0', "Grundstand\n");
git($d['quelle'], 'remote', 'add', 'origin', $d['repo']);
git($d['quelle'], 'push', '-q', 'origin', 'main');
git($basis, 'clone', '-q', $d['repo'], $d['ziel']);

$langerBetreff = str_repeat('Größenänderung ', 25);   // > 200 Byte, Umlaute
for ($i = 1; $i <= 57; $i++) {
    if ($i === 31) {
        // Seitenzweig ab M10 zusammenführen
        git($d['quelle'], 'checkout', '-q', '-b', 'seite', $sha['M10']);
        for ($j = 1; $j <= 3; $j++) {
            $schreiben("seite-{$j}.txt", "S{$j}\n");
            $einchecken("S{$j}", "Seitenzweig S{$j}\n");
        }
        git($d['quelle'], 'checkout', '-q', 'main');
        $datei = $basis . '/nachricht.txt';
        file_put_contents($datei, "Merge pull request #1 aus seite\n\nUpdate-Hinweis: Seitenzweig eingespielt\n");
        git($d['quelle'], 'merge', '-q', '--no-ff', '-F', $datei, 'seite');
        $sha['X'] = git($d['quelle'], 'rev-parse', 'HEAD');
    }
    $schreiben("datei-{$i}.txt", "M{$i}\n");
    $nachricht = match ($i) {
        55 => "Farbe \033[31mrot\033[0m, Glocke \x07 und Tab\tim Betreff\n",
        54 => $langerBetreff . "\n",
        53 => "Betreff mit <script>alert(1)</script> & Ümläuten\n",
        4 => "Flows angepasst\n\nLängere Beschreibung.\n\nUpdate-Hinweis: Nach dem Update die Flows im Editor ansehen.\n",
        default => "Änderung M{$i}\n",
    };
    if ($i === 5) {
        $schreiben('database/migrations/002_neu.sql', "CREATE TABLE b (id INTEGER);\n");
    }
    if ($i === 6) {
        $schreiben('deploy/x.conf', "# Vorlage, neu\n");
    }
    $einchecken("M{$i}", $nachricht);
}
git($d['quelle'], 'push', '-q', 'origin', 'main');

try {
    // --------------------------------------------- Aufruf
    $r = $helfer(['--unbekannt=1']);
    gleich($r['code'], 1, 'unbekannte Option → Exit 1');
    $r = $helfer(['--ziel' => 'relativ']);
    gleich($r['code'], 1, '--ziel muss absolut sein');
    $r = $helfer(['--app-benutzer' => false]);
    gleich($r['code'], 1, '--app-benutzer fehlt → Exit 1');
    $r = $helfer(['--soll=/x', '--soll=/y']);
    gleich($r['code'], 1, 'eine Option zweimal → Exit 1');
    $r = $helfer(['--nachlauf', '--auftrag-pruefen']);
    gleich($r['code'], 1, '--nachlauf und --auftrag-pruefen zusammen → Exit 1');

    // --------------------------------------------- 1 · ohne Auftrag
    $nie = $fall(['ergebnis' => 'eingespielt']);
    $r = $helfer(['--update-skript' => $nie]);
    gleich($r['code'], 0, 'ohne Auftrag: Exit 0');
    $h = lesen($d['ist'] . '/helfer');
    gleich($h['_kopf'], '# powermanagement update-helfer 1', 'ist/helfer: Kopf');
    gleich($h['zeit'] ?? null, (string) $T, 'ist/helfer: zeit');
    gleich($h['fassung'] ?? null, $sha['B0'], 'ist/helfer: fassung = HEAD');
    gleich([$h['oberflaeche'] ?? null, $h['automatik'] ?? null, $h['offen'] ?? null, $h['pfad'] ?? null],
        ['ja', 'ja', 'nein', 'ok'], 'ist/helfer: oberflaeche, automatik, offen, pfad');
    $s = $stand();
    gleich($s['_kopf'], '# powermanagement update-stand 1', 'ist/stand: Kopf');
    gleich(explode(' ', $s['installiert'] ?? '')[0], $sha['B0'], 'ist/stand: installiert = HEAD');
    gleich($s['abruf'] ?? null, 'nie', 'ist/stand: noch nie geholt');
    gleich($s['neu'] ?? null, '0', 'ist/stand: ohne Abruf nichts Neues');
    pruefe(!$aufgerufen($nie), 'ohne Auftrag wird update-debian.sh nicht aufgerufen');
    foreach (['helfer', 'stand', 'verlauf'] as $datei) {
        gleich(modus($d['ist'] . '/' . $datei), '640', "ist/{$datei}: 640");
    }

    // --------------------------------------------- 2 · Suchen
    // Index veralten lassen: ein normales „git status“ schriebe ihn neu.
    touch($d['ziel'] . '/README.md', time() + 5);
    clearstatcache();
    $indexVorher = md5_file($d['ziel'] . '/.git/index') . ' ' . filemtime($d['ziel'] . '/.git/index');
    $suchen = $fall(['ergebnis' => 'geprueft', 'ziel' => $d['ziel']]);
    $T += 10;
    $k = $auftrag('pruefen');
    $r = $helfer(['--update-skript' => $suchen]);
    gleich($r['code'], 0, 'Suchen: Exit 0');
    pruefe($auftragWeg(), 'Suchen: Auftrag weg');
    $l = $lauf();
    gleich([$l['kennung'] ?? null, $l['aktion'] ?? null, $l['zustand'] ?? null, $l['ergebnis'] ?? null, $l['grund'] ?? null],
        [$k, 'pruefen', 'fertig', 'geprueft', '-'], 'Suchen: Lauf fertig geprueft');
    gleich([$l['ausloeser'] ?? null, $l['von'] ?? null], ['hand', 'pruefung'], 'Suchen: Auslöser und von');
    $a = lesen($fallDir($suchen) . '/aufruf');
    gleich($a['arg'][0] ?? null, '--pruefen', 'Suchen: erstes Argument --pruefen');
    gleich(count($a['arg']), 3, 'Suchen: argv genau --pruefen, --ergebnis-datei, --fortschritt-datei');
    $s = $stand();
    gleich($s['neu'] ?? null, '60', 'Stand: 60 neue Commits (ohne Merges)');
    gleich($s['weitere'] ?? null, '10', 'Stand: weitere 10 über die 50 gezeigten hinaus');
    gleich(count($s['commit']), 50, 'Stand: 50 Commits');
    gleich([$s['linear'] ?? null, $s['migrationen'] ?? null, $s['einrichtung'] ?? null, $s['flows'] ?? null,
        $s['lokal_geaendert'] ?? null], ['ja', 'ja', 'ja', 'nein', 'nein'], 'Stand: linear, migrationen, einrichtung, flows, lokal');
    gleich([$s['abruf'] ?? null, $s['zweig'] ?? null, $s['quelle'] ?? null], ['ok', 'main', '-'],
        'Stand: abruf ok, zweig main, quelle - (keine GitHub-Adresse)');
    gleich(explode(' ', $s['verfuegbar'] ?? '')[0], $sha['M57'], 'Stand: verfuegbar = Spitze');
    gleich(explode(' ', $s['commit'][0] ?? '')[0], $sha['M57'], 'Stand: neueste Commits zuerst');
    $betreffe = array_map(static fn (string $c): string => (string) (explode(' ', $c, 3)[2] ?? ''), $s['commit']);
    $sauber = true;
    foreach ($betreffe as $b) {
        if (preg_match('/[\x00-\x1f\x7f]/', $b) || strlen($b) > 200 || !mb_check_encoding($b, 'UTF-8')) {
            $sauber = false;
        }
    }
    $alleBetreffe = implode("\n", $betreffe);
    pruefe($sauber, 'Stand: Betreffe ohne Steuerzeichen, höchstens 200 Byte, gültiges UTF-8');
    pruefe(str_contains($alleBetreffe, '<script>alert(1)</script> & Ümläuten'), 'Stand: <script> bleibt Text (PHP gibt es nur escaped aus)');
    pruefe(str_contains($alleBetreffe, 'Farbe [31mrot[0m, Glocke  und Tabim Betreff'), 'Stand: ESC, Glocke und Tab entfernt');
    pruefe(str_contains($alleBetreffe, 'Größenänderung Größenänderung'), 'Stand: Umlaute erhalten (langer Betreff gekürzt)');
    pruefe(in_array(substr($sha['M4'], 0, 7) . ' Nach dem Update die Flows im Editor ansehen.', $s['hinweis'], true),
        'Stand: Trailer Update-Hinweis');
    pruefe(in_array(substr($sha['X'], 0, 7) . ' Seitenzweig eingespielt', $s['hinweis'], true), 'Stand: Hinweis auch aus einem Merge');
    gleich(count($s['ziel_kandidat']), 58, 'Stand: 58 Kandidaten auf der Erst-Eltern-Linie');
    clearstatcache();
    gleich(md5_file($d['ziel'] . '/.git/index') . ' ' . filemtime($d['ziel'] . '/.git/index'), $indexVorher,
        '.git/index unverändert (--no-optional-locks)');
    git($d['ziel'], 'status', '--porcelain');
    pruefe(md5_file($d['ziel'] . '/.git/index') !== explode(' ', $indexVorher)[0],
        'Gegenprobe: ein gewöhnliches „git status“ hätte den Index neu geschrieben');
    foreach (['lauf', 'stand', 'protokoll'] as $datei) {
        gleich(modus($d['ist'] . '/' . $datei), '640', "ist/{$datei}: 640");
    }
    gleich(modus($d['zustand'] . '/lauf'), '600', 'root-Kopie des Laufs: 600');

    // Zweites Suchen binnen 60 s: gedrosselt, Stand aus den Refs
    $gedrosselt = $fall(['ergebnis' => 'geprueft', 'ziel' => $d['ziel']]);
    $T += 30;
    $k2 = $auftrag('pruefen');
    $r = $helfer(['--update-skript' => $gedrosselt]);
    pruefe(!$aufgerufen($gedrosselt), 'zweites Suchen binnen 60 s: kein Abruf');
    gleich([$lauf()['kennung'] ?? null, $lauf()['zustand'] ?? null, $lauf()['ergebnis'] ?? null], [$k2, 'fertig', 'geprueft'],
        'zweites Suchen binnen 60 s: fertig geprueft');
    pruefe($auftragWeg(), 'zweites Suchen: Auftrag weg');

    // --------------------------------------------- 3 · Kandidaten von einem Seitenzweig aus
    git($d['ziel'], 'reset', '-q', '--hard', $sha['S2']);
    $helfer(['--update-skript' => $nie]);
    $s = $stand();
    $kandidaten = array_map(static fn (string $z): string => explode(' ', $z)[0], $s['ziel_kandidat']);
    gleich($s['linear'] ?? null, 'ja', 'von S2 aus: linear');
    gleich(count($kandidaten), 28, 'von S2 aus: 28 Kandidaten (X und M31 … M57)');
    pruefe(in_array($sha['X'], $kandidaten, true), 'von S2 aus: der Merge X ist Kandidat');
    $alleNachfahren = true;
    foreach ($kandidaten as $kand) {
        if (ausfuehren(['git', '-C', $d['ziel'], 'merge-base', '--is-ancestor', $sha['S2'], $kand])['code'] !== 0) {
            $alleNachfahren = false;
        }
    }
    pruefe($alleNachfahren, 'von S2 aus: jeder Kandidat ist Nachfahre des installierten Stands');
    $verboten = [$sha['S1'], $sha['S2'], $sha['S3'], $sha['M11'], $sha['M30']];
    pruefe(array_intersect($verboten, $kandidaten) === [], 'von S2 aus: weder Seitenzweig noch M11 … M30 als Kandidat');
    git($d['ziel'], 'reset', '-q', '--hard', $sha['B0']);
    pruefe(!$aufgerufen($nie), 'Stand ohne Auftrag ruft update-debian.sh nicht auf');

    // --------------------------------------------- 4 · nicht linear (Force-Push)
    git($d['quelle'], 'checkout', '-q', '-b', 'umgeschrieben', $sha['M20']);
    $schreiben('neu.txt', "N1\n");
    $einchecken('N1', "Umgeschriebener Verlauf\n");
    git($d['quelle'], 'push', '-q', '-f', 'origin', 'umgeschrieben:main');
    git($d['ziel'], 'reset', '-q', '--hard', $sha['M40']);
    $T += 61;
    $auftrag('pruefen');
    $helfer(['--update-skript' => $fall(['ergebnis' => 'geprueft', 'ziel' => $d['ziel']])]);
    $s = $stand();
    gleich([$s['linear'] ?? null, count($s['commit']), count($s['ziel_kandidat']), $s['weitere'] ?? null],
        ['nein', 0, 0, '0'], 'nach Force-Push: linear nein, keine Commits, keine Kandidaten');
    gleich(explode(' ', $s['verfuegbar'] ?? '')[0], $sha['N1'], 'nach Force-Push: verfuegbar = neue Spitze');
    git($d['quelle'], 'checkout', '-q', 'main');
    git($d['quelle'], 'push', '-q', '-f', 'origin', 'main:main');
    git($d['ziel'], 'fetch', '-q', 'origin');
    git($d['ziel'], 'reset', '-q', '--hard', $sha['B0']);

    // --------------------------------------------- 5 · Aktualisieren
    $faelle = [
        'eingespielt' => [['ergebnis' => 'eingespielt', 'alt' => $sha['B0'], 'neu' => $sha['M57'],
            'sicherung' => 'vor-update-20261016-040312.tar.gz', 'pruefung' => 'ok', 'rc' => 0], 'fertig', 'eingespielt', '-'],
        'aktuell' => [['ergebnis' => 'aktuell', 'alt' => $sha['B0'], 'rc' => 0], 'fertig', 'aktuell', '-'],
        'mit_warnung' => [['ergebnis' => 'eingespielt_mit_warnung', 'neu' => $sha['M57'], 'warnung' => 'kacheln',
            'pruefung' => 'warnung', 'rc' => 0], 'fertig', 'eingespielt_mit_warnung', '-'],
        'besetzt' => [['ergebnis' => 'nicht_begonnen', 'grund' => 'besetzt', 'rc' => 1], 'fehler', 'nicht_begonnen', 'besetzt'],
        'migration' => [['ergebnis' => 'zurueckgerollt', 'grund' => 'migration', 'rc' => 2], 'fehler', 'zurueckgerollt', 'migration'],
        'unvollstaendig' => [['ergebnis' => 'unvollstaendig', 'grund' => 'pruefung', 'rc' => 3], 'fehler', 'unvollstaendig', 'pruefung'],
        'absturz' => [['rc' => 137], 'fehler', 'abgebrochen', 'unterbrochen'],
        'kaputt' => [['ergebnis' => 'erfunden', 'rc' => 0], 'fehler', 'abgebrochen', 'unterbrochen'],
    ];
    $kennungEingespielt = '';
    $skriptEingespielt = '';
    $zielJeFall = ['eingespielt' => 'M57', 'aktuell' => 'M56', 'mit_warnung' => 'M55', 'besetzt' => 'M54', 'migration' => 'M50',
        'unvollstaendig' => 'M53', 'absturz' => 'M52', 'kaputt' => 'M51'];
    foreach ($faelle as $name => [$werte, $zustand, $ergebnis, $grund]) {
        $T += 121;
        $ziel = $sha[$zielJeFall[$name]];
        $skript = $fall($werte + ['verlauf' => $d['zustand'] . '/verlauf']);
        $k = $auftrag('aktualisieren', $ziel);
        $r = $helfer(['--update-skript' => $skript]);
        gleich($r['code'], 0, "{$name}: Helfer endet mit 0");
        pruefe($auftragWeg(), "{$name}: Auftrag weg");
        $l = $lauf();
        gleich([$l['kennung'] ?? null, $l['aktion'] ?? null, $l['zustand'] ?? null, $l['ergebnis'] ?? null, $l['grund'] ?? null],
            [$k, 'aktualisieren', $zustand, $ergebnis, $grund], "{$name}: Lauf {$zustand} {$ergebnis} {$grund}");
        gleich([$l['ziel'] ?? null, $l['beginn'] ?? null, $l['ende'] ?? null], [$ziel, (string) $T, (string) $T],
            "{$name}: Ziel, Beginn, Ende");
        pruefe(preg_match('/^[0-9]{8}-[0-9]{6}-' . substr($k, 0, 8) . '\.log$/', $l['protokoll'] ?? '') === 1
            && is_file($d['protokolle'] . '/' . ($l['protokoll'] ?? '-')), "{$name}: Protokoll unter dem Namen aus dem Lauf");
        gleich(modus($d['protokolle'] . '/' . ($l['protokoll'] ?? '-')), '600', "{$name}: Protokoll nur für root (600)");
        $p = (string) @file_get_contents($d['ist'] . '/protokoll');
        pruefe(str_starts_with($p, "# powermanagement update-protokoll 1\nkennung {$k}\n\n") && str_contains($p, 'Grüße aus der Attrappe'),
            "{$name}: ist/protokoll mit Kopf, Kennung und Ausgabe");
        pruefe(str_contains((string) @file_get_contents($d['zustand'] . '/kennungen'), $k . ' '), "{$name}: Kennung gemerkt");
        pruefe(str_contains((string) @file_get_contents($d['zustand'] . '/zeiten'), 'aktualisieren ' . $T), "{$name}: Zeit gemerkt");
        if (isset($werte['ergebnis'])) {
            $v = lesen($d['ist'] . '/verlauf');
            pruefe(str_contains(implode("\n", $v['eintrag']), ' ' . $k . ' hand pruefung aktualisieren '),
                "{$name}: ist/verlauf spiegelt den Verlauf");
        }
        if ($name === 'eingespielt') {
            $kennungEingespielt = $k;
            $skriptEingespielt = $skript;
            gleich([$l['neu'] ?? null, $l['sicherung'] ?? null, $l['pruefung'] ?? null, $l['alt'] ?? null],
                [$sha['M57'], 'vor-update-20261016-040312.tar.gz', 'ok', $sha['B0']], 'eingespielt: neu, Sicherung, Prüfung, alt');
            gleich($l['invocation'] ?? null, '-', 'eingespielt: invocation - (ohne systemd)');
        }
        if ($name === 'mit_warnung') {
            gleich($l['warnung'], ['kacheln'], 'mit Warnung: warnung kacheln');
        }
        if ($name === 'migration') {
            pruefe(preg_match('/^' . $ziel . ' [0-9]+ migration$/m', (string) @file_get_contents($d['zustand'] . '/gescheitert')) === 1,
                'zurückgerollt (migration): Ziel als gescheitert vermerkt');
            pruefe(in_array($ziel, $stand()['gescheitert'], true), 'zurückgerollt: ist/stand nennt den gescheiterten Stand');
        }
        if ($name === 'unvollstaendig') {
            pruefe(str_contains((string) @file_get_contents($d['zustand'] . '/gescheitert'), $ziel . ' '),
                'unvollständig (pruefung): Ziel als gescheitert vermerkt');
        }
        if (in_array($name, ['besetzt', 'absturz', 'kaputt', 'eingespielt'], true)) {
            pruefe(!str_contains((string) @file_get_contents($d['zustand'] . '/gescheitert'), $ziel . ' '),
                "{$name}: zählt nicht als gescheitert");
        }
    }

    // --------------------------------------------- 6 · argv und Umgebung
    $a = lesen($fallDir($skriptEingespielt) . '/aufruf');
    $argv = $a['arg'];
    gleich(count($argv), 3, 'argv: genau drei Elemente');
    gleich($argv[0] ?? null, '--bis=' . $sha['M57'], 'argv: --bis=<ziel>');
    pruefe(preg_match('#^--ergebnis-datei=' . preg_quote($d['run'], '#') . '/powermanagement-update\.[A-Za-z0-9]{6}/ergebnis$#',
        $argv[1] ?? '') === 1, 'argv: --ergebnis-datei im Arbeitsordner des Laufs');
    pruefe(preg_match('#^--fortschritt-datei=' . preg_quote($d['run'], '#') . '/powermanagement-update\.[A-Za-z0-9]{6}/fortschritt$#',
        $argv[2] ?? '') === 1, 'argv: --fortschritt-datei im Arbeitsordner des Laufs');
    pruefe(!str_contains(implode(' ', $argv), $kennungEingespielt), 'argv: die Kennung steht in keinem Argument');
    foreach (['--zweig', '--erzwingen', '--flows-ersetzen', '--behalten', '--ziel', '--git-zugang'] as $nichtDa) {
        pruefe(!str_contains(implode(' ', $argv), $nichtDa), "argv: kein {$nichtDa}");
    }
    gleich($a['umask'] ?? null, '0022', 'Umgebung: umask 0022');
    $umgebung = [];
    foreach ($a['env'] as $zeile) {
        [$n, $w] = array_pad(explode('=', $zeile, 2), 2, '');
        $umgebung[$n] = $w;
    }
    $erlaubt = ['PATH', 'HOME', 'LANG', 'NO_COLOR', 'DEBIAN_FRONTEND', 'APT_LISTCHANGES_FRONTEND', 'NEEDRESTART_MODE',
        'NEEDRESTART_SUSPEND', 'INVOCATION_ID', 'PM_UPDATE_AUSLOESER', 'PM_UPDATE_KENNUNG', 'PM_UPDATE_VON',
        'PWD', 'SHLVL', '_'];
    gleich(array_values(array_diff(array_keys($umgebung), $erlaubt)), [], 'Umgebung: nur die Liste (env -i)');
    gleich([$umgebung['HOME'] ?? null, $umgebung['LANG'] ?? null, $umgebung['NO_COLOR'] ?? null,
        $umgebung['PATH'] ?? null], ['/root', 'C.UTF-8', '1', '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'],
        'Umgebung: HOME, LANG, NO_COLOR, PATH');
    gleich([$umgebung['PM_UPDATE_AUSLOESER'] ?? null, $umgebung['PM_UPDATE_KENNUNG'] ?? null, $umgebung['PM_UPDATE_VON'] ?? null],
        ['hand', $kennungEingespielt, 'pruefung'], 'Umgebung: Auslöser, Kennung, von');
    gleich($a['modus'] ?? null, 'update', 'Attrappe: als Update aufgerufen');

    // --------------------------------------------- 7 · Ablehnungen
    $T += 121;
    $ablehnungenBauen = static function () use ($auftragText, $sha, &$T, &$kennungEingespielt): array {
        [$kG, $tG] = $auftragText('pruefen');
        [, $tA] = $auftragText('aktualisieren', $sha['M57']);
        return [$kG, $tG, [
            'falscher Kopf' => [str_replace('update-auftrag 1', 'update-auftrag 2', $tG), 'kopf'],
            'Kopf fehlt' => [substr($tG, strlen(KOPF)), 'kopf'],
            'CR' => [str_replace("\n", "\r\n", $tG), 'zeichen'],
            'NUL' => [str_replace('von pruefung', "von pr\0uefung", $tG), 'zeichen'],
            'Byte über 0x7E' => [str_replace('von pruefung', 'von prüfung', $tG), 'zeichen'],
            '1025 Byte' => [$tG . str_repeat('x', 1025 - strlen($tG)), 'groesse'],
            'letzte Zeile ohne LF' => [rtrim($tG, "\n"), 'format'],
            'Leerzeile' => [str_replace("\naktion", "\n\naktion", $tG), 'format'],
            'leer' => ['', 'format'],
            'Zeile ohne Wert' => [$tG . "zeit\n", 'format'],
            'unbekannter Schlüssel' => [$tG . "modus schnell\n", 'schluessel'],
            'doppelter Schlüssel' => [$tG . "aktion pruefen\n", 'doppelt_schluessel'],
            'ziel bei pruefen' => [$tG . 'ziel ' . $sha['M57'] . "\n", 'verboten'],
            'ziel fehlt' => [preg_replace('/^ziel .*\n/m', '', $tA), 'fehlt'],
            'kennung fehlt' => [preg_replace('/^kennung .*\n/m', '', $tG), 'fehlt'],
            'Aktion zuruecknehmen' => [str_replace('aktion pruefen', 'aktion zuruecknehmen', $tG), 'wert'],
            'Kennung zu kurz' => [str_replace($kG, substr($kG, 0, 31), $tG), 'wert'],
            'Kennung mit Großbuchstaben' => [str_replace($kG, strtoupper($kG), $tG), 'wert'],
            'ziel kurz' => [str_replace($sha['M57'], substr($sha['M57'], 0, 7), $tA), 'wert'],
            'Auslöser system' => [str_replace('ausloeser hand', 'ausloeser system', $tG), 'wert'],
            'zeit zu alt' => [$auftragText('pruefen', null, 'hand', $T - 1801)[1], 'veraltet'],
            'Automatik: zeit zu alt' => [$auftragText('pruefen', null, 'automatik', $T - 601)[1], 'veraltet'],
            'zeit in der Zukunft' => [$auftragText('pruefen', null, 'konsole', $T + 301)[1], 'zukunft'],
            'doppelte Kennung' => [$auftragText('pruefen', null, 'hand', null, $kennungEingespielt)[1], 'doppelt'],
        ]];
    };
    [$kG, $tG, $ablehnungen] = $ablehnungenBauen();
    foreach ($ablehnungen as $name => [$text, $code]) {
        $skript = $fall(['ergebnis' => 'geprueft']);
        $roh($text);
        $r = $helfer(['--update-skript' => $skript]);
        $l = $lauf();
        gleich([$r['code'], $l['zustand'] ?? null, $l['ergebnis'] ?? null, $l['grund'] ?? null], [0, 'fehler', 'abgelehnt', $code],
            "abgelehnt ({$name}): fehler abgelehnt {$code}");
        pruefe(!$aufgerufen($skript), "abgelehnt ({$name}): update-debian.sh nicht aufgerufen");
        pruefe($auftragWeg(), "abgelehnt ({$name}): Auftrag weg");
    }
    // Die Kennung eines abgelehnten Auftrags steht im Lauf (die Anwendung findet ihre Ablehnung).
    $roh(str_replace('aktion pruefen', 'aktion zuruecknehmen', $tG));
    $helfer(['--update-skript' => $nie]);
    gleich($lauf()['kennung'] ?? null, $kG, 'abgelehnt: der Lauf trägt die Kennung des Auftrags');
    gleich($lauf()['aktion'] ?? null, '-', 'abgelehnt: ungültige Aktion erscheint als -');

    // update.conf
    file_put_contents($conf, "# von Hand\nOBERFLAECHE=nein\n");
    foreach (['pruefen', 'aktualisieren'] as $aktion) {
        $skript = $fall(['ergebnis' => 'geprueft']);
        $auftrag($aktion, $aktion === 'aktualisieren' ? $sha['M57'] : null);
        $helfer(['--update-skript' => $skript]);
        gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['abgelehnt', 'abgeschaltet'], "OBERFLAECHE=nein: {$aktion} abgelehnt");
        pruefe(!$aufgerufen($skript), "OBERFLAECHE=nein: {$aktion} nicht ausgeführt");
    }
    gleich(lesen($d['ist'] . '/helfer')['oberflaeche'] ?? null, 'nein', 'OBERFLAECHE=nein: ist/helfer oberflaeche nein');
    file_put_contents($conf, "AUTOMATIK = \"nein\"\n");
    $skript = $fall(['ergebnis' => 'eingespielt']);
    $auftrag('aktualisieren', $sha['M57'], 'automatik');
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['abgelehnt', 'abgeschaltet'], 'AUTOMATIK=nein: automatisches Aktualisieren abgelehnt');
    gleich([lesen($d['ist'] . '/helfer')['oberflaeche'] ?? null, lesen($d['ist'] . '/helfer')['automatik'] ?? null], ['ja', 'nein'],
        'AUTOMATIK=nein: ist/helfer');
    $T += 61;
    $skript = $fall(['ergebnis' => 'geprueft', 'ziel' => $d['ziel']]);
    $auftrag('pruefen', null, 'automatik');
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['zustand'] ?? null, $lauf()['ergebnis'] ?? null], ['fertig', 'geprueft'], 'AUTOMATIK=nein: automatisches Suchen erlaubt');
    file_put_contents($conf, "OBERFLAECHE=vielleicht\n");
    $skript = $fall(['ergebnis' => 'geprueft']);
    $T += 61;
    $auftrag('pruefen');
    $helfer(['--update-skript' => $skript]);
    gleich($lauf()['grund'] ?? null, 'abgeschaltet', 'update.conf mit ungültigem Wert: gilt als nein');
    // Was nicht genau so aussieht, schaltet beides ab – nie still übergangen.
    foreach ([
        'kleingeschrieben'      => "oberflaeche=nein\n",
        'Umlaut'                => "OBERFLÄCHE=nein\n",
        'export davor'          => "export OBERFLAECHE=nein\n",
        'Doppelpunkt'           => "OBERFLAECHE: nein\n",
        'hinter 4 KiB'          => str_repeat("# Kommentar, der lang ist\n", 200) . "OBERFLAECHE=nein\n",
        'nein, dann ja'         => "OBERFLAECHE=nein\nOBERFLAECHE=ja\n",
    ] as $name => $inhalt) {
        file_put_contents($conf, $inhalt);
        $skript = $fall(['ergebnis' => 'geprueft']);
        $T += 61;
        $auftrag('pruefen');
        $r = $helfer(['--update-skript' => $skript]);
        gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null, $aufgerufen($skript)], ['abgelehnt', 'abgeschaltet', false],
            "update.conf ({$name}): abgelehnt, nicht ausgeführt");
        gleich(lesen($d['ist'] . '/helfer')['oberflaeche'] ?? null, 'nein', "update.conf ({$name}): ist/helfer oberflaeche nein");
    }
    gleich(lesen($d['ist'] . '/helfer')['automatik'] ?? null, 'ja', 'update.conf: „nein, dann ja“ betrifft nur OBERFLAECHE');
    file_put_contents($conf, "export OBERFLAECHE=ja\n");
    $helfer(['--update-skript' => $nie]);
    gleich(lesen($d['ist'] . '/helfer')['automatik'] ?? null, 'nein', 'update.conf mit unbekannter Zeile: auch AUTOMATIK nein');
    file_put_contents($conf, "# Kommentar\n\n  OBERFLAECHE = ja  \r\nAUTOMATIK=\"ja\"\n");
    $skript = $fall(['ergebnis' => 'geprueft']);
    $T += 61;
    $auftrag('pruefen');
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['ergebnis'] ?? null, $aufgerufen($skript)], ['geprueft', true], 'update.conf mit Kommentar, Leerzeile, CRLF und „ja“: erlaubt');
    unlink($conf);

    // Abstände und gescheiterte Stände
    $T += 121;
    $skript = $fall(['ergebnis' => 'eingespielt']);
    $auftrag('aktualisieren', $sha['M50'], 'automatik');
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['abgelehnt', 'gescheitert_vorher'],
        'Automatik mit gescheitertem Ziel: abgelehnt gescheitert_vorher');
    $skript = $fall(['ergebnis' => 'eingespielt']);
    $auftrag('aktualisieren', $sha['M57'], 'automatik');
    $helfer(['--update-skript' => $skript]);
    gleich($lauf()['ergebnis'] ?? null, 'eingespielt', 'Automatik: erste Aktualisierung läuft');
    $T += 60;
    $skript = $fall(['ergebnis' => 'eingespielt']);
    $auftrag('aktualisieren', $sha['M57']);
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['grund'] ?? null, $aufgerufen($skript)], ['zu_oft', false], 'Aktualisieren binnen 120 s: zu_oft');
    $T += 121;
    $skript = $fall(['ergebnis' => 'eingespielt']);
    $auftrag('aktualisieren', $sha['M57'], 'automatik');
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['grund'] ?? null, $aufgerufen($skript)], ['zu_oft', false], 'zweite automatische Aktualisierung binnen 20 h: zu_oft');
    $skript = $fall(['ergebnis' => 'eingespielt']);
    $auftrag('aktualisieren', $sha['M57']);
    $helfer(['--update-skript' => $skript]);
    gleich($lauf()['ergebnis'] ?? null, 'eingespielt', 'von Hand gilt die 20-h-Grenze nicht');
    // Hand mit gescheitertem Ziel: erlaubt (bewusst erneut versuchen)
    $T += 121;
    $skript = $fall(['ergebnis' => 'zurueckgerollt', 'grund' => 'migration', 'rc' => 2]);
    $auftrag('aktualisieren', $sha['M50']);
    $helfer(['--update-skript' => $skript]);
    pruefe($aufgerufen($skript), 'von Hand darf ein gescheiterter Stand erneut versucht werden');

    // Verweis, FIFO, Ordner
    $fremd = $basis . '/fremd-auftrag';
    file_put_contents($fremd, $auftragText('pruefen')[1]);
    $fremdVorher = (string) file_get_contents($fremd);
    $skript = $fall(['ergebnis' => 'geprueft']);
    symlink($fremd, $d['soll'] . '/auftrag');
    $r = $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['abgelehnt', 'keine_datei'], 'Verweis: abgelehnt keine_datei');
    pruefe(!$aufgerufen($skript) && $auftragWeg(), 'Verweis: nicht ausgeführt, Verweis weg');
    gleich(is_file($fremd) ? (string) file_get_contents($fremd) : null, $fremdVorher, 'Verweis: Ziel unberührt');
    ausfuehren(['mkfifo', $d['soll'] . '/auftrag']);
    $skript = $fall(['ergebnis' => 'geprueft']);
    $r = $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['abgelehnt', 'keine_datei'], 'FIFO: abgelehnt keine_datei');
    pruefe($r['dauer'] < 15, 'FIFO: Lauf endet in weniger als 15 s (' . round($r['dauer'], 1) . ' s)');
    pruefe(!$aufgerufen($skript) && $auftragWeg(), 'FIFO: nicht ausgeführt, weg');
    mkdir($d['soll'] . '/auftrag');
    file_put_contents($d['soll'] . '/auftrag/inhalt', $auftragText('pruefen')[1]);
    $skript = $fall(['ergebnis' => 'geprueft']);
    $helfer(['--update-skript' => $skript]);
    gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['abgelehnt', 'keine_datei'], 'Ordner: abgelehnt keine_datei');
    pruefe(!$aufgerufen($skript) && $auftragWeg(), 'Ordner: nicht ausgeführt, weg');
    // soll/ fehlt: kein Auftrag, kein abgelehnter Lauf, ist/helfer meldet es
    $kVorher = $lauf()['kennung'] ?? null;
    rename($d['soll'], $d['soll'] . '.weg');
    $helfer(['--update-skript' => $nie]);
    gleich(lesen($d['ist'] . '/helfer')['pfad'] ?? null, 'soll_ungueltig', 'soll/ fehlt: ist/helfer pfad soll_ungueltig');
    gleich($lauf()['kennung'] ?? null, $kVorher, 'soll/ fehlt: kein neuer Lauf');
    rename($d['soll'] . '.weg', $d['soll']);
    symlink($basis . '/faelle', $d['soll'] . '.verweis');
    rename($d['soll'], $d['soll'] . '.weg');
    rename($d['soll'] . '.verweis', $d['soll']);
    $helfer(['--update-skript' => $nie]);
    gleich(lesen($d['ist'] . '/helfer')['pfad'] ?? null, 'soll_ungueltig', 'soll/ ist ein Verweis: pfad soll_ungueltig');
    unlink($d['soll']);
    rename($d['soll'] . '.weg', $d['soll']);
    $helfer(['--update-skript' => $nie]);
    gleich(lesen($d['ist'] . '/helfer')['pfad'] ?? null, 'ok', 'soll/ wieder da: pfad ok');

    // ist/wartung wird mit Zeitlimit gelesen: eine FIFO, die zwischen Prüfung
    // und Öffnen untergeschoben wird, hält den Helfer nicht auf. Nachgestellt
    // mit einem head, das bei ist/wartung nie endet.
    $stubs = $basis . '/stubs';
    mkdir($stubs, 0755);
    $echtesHead = trim((string) shell_exec('command -v head'));
    file_put_contents($stubs . '/head', "#!/bin/sh\nfor a in \"\$@\"; do case \"\$a\" in */wartung) exec sleep 300 ;; esac; done\nexec {$echtesHead} \"\$@\"\n");
    chmod($stubs . '/head', 0755);
    $mitStubs = ['PATH' => $stubs . ':' . getenv('PATH')];
    file_put_contents($d['ist'] . '/wartung', "# powermanagement update-wartung 1\nseit " . ($T - 3600) . "\nkennung -\n");
    $r = ausfuehren(['timeout', '-s', 'KILL', '60', ...$optionen(['--update-skript' => $nie])], null, $mitStubs);
    pruefe($r['code'] === 0 && $r['dauer'] < 30, 'ist/wartung hängt beim Lesen: der Helfer endet trotzdem (' . round($r['dauer'], 1) . ' s, Exit ' . $r['code'] . ')');
    $helfer(['--update-skript' => $nie]);
    pruefe(!file_exists($d['ist'] . '/wartung'), 'ist/wartung ohne Marke, älter als 10 min: aufgehoben');

    // Ein Sammler, den ein abgebrochenes Update angehalten zurückließ (Notiz
    // ohne Marke, update.lock frei), startet der Helfer wieder – mit
    // systemctl-Attrappe statt --ohne-systemd.
    $systemctlLog = $basis . '/systemctl.log';
    file_put_contents($stubs . '/systemctl', "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($systemctlLog) . "\n"
        . "[ \"\$1\" = is-failed ] && exit \"\${PM_PRUEFUNG_FAILED:-1}\"\nexit 0\n");
    chmod($stubs . '/systemctl', 0755);
    $mitSystemd = static fn (array $umgebung = []): array => ausfuehren($optionen(['--update-skript' => $nie, '--ohne-systemd' => false]), null,
        $umgebung + $mitStubs);
    $notiz = $d['zustand'] . '/sammler-angehalten';
    touch($notiz);
    $halter = $sperreHalten($usperre);
    @unlink($systemctlLog);
    $mitSystemd();
    pruefe(is_file($notiz) && !str_contains((string) @file_get_contents($systemctlLog), 'start powermanagement-sammler'),
        'Sammler angehalten, update.lock belegt: nichts gestartet, Notiz bleibt');
    $sperreLoesen($halter);
    @unlink($systemctlLog);
    $r = $mitSystemd();
    pruefe(!is_file($notiz) && str_contains((string) @file_get_contents($systemctlLog), 'start powermanagement-sammler.service'),
        'Sammler angehalten, update.lock frei, keine Marke: gestartet, Notiz weg');
    pruefe(str_contains($r['aus'], 'wieder gestartet'), 'Sammler angehalten: der Helfer sagt es');
    // Steht die Marke, startet ihn das Zurückrollen – nicht der Helfer davor.
    touch($notiz);
    file_put_contents($d['zustand'] . '/offen', "# powermanagement update-offen 1\nphase stufe1\nzeit {$T}\n");
    @unlink($systemctlLog);
    ausfuehren($optionen(['--update-skript' => $nie, '--ohne-systemd' => false, '--nachlauf']), null, $mitStubs);
    pruefe(is_file($notiz) && !str_contains((string) @file_get_contents($systemctlLog), 'start powermanagement-sammler'),
        'Sammler angehalten, Marke steht: der Nachlauf startet ihn nicht');
    unlink($d['zustand'] . '/offen');
    @unlink($d['zustand'] . '/wiederaufnahme');
    ausfuehren($optionen(['--update-skript' => $nie, '--ohne-systemd' => false, '--nachlauf']), null, $mitStubs);
    pruefe(!is_file($notiz), 'Sammler angehalten, keine Marke: auch der Nachlauf startet ihn');
    // Die Pfad-Einheit wird geheilt, wenn soll/ in Ordnung ist – nie im Nachlauf.
    @unlink($systemctlLog);
    ausfuehren($optionen(['--update-skript' => $nie, '--ohne-systemd' => false, '--nachlauf']), null, ['PM_PRUEFUNG_FAILED' => '0'] + $mitStubs);
    pruefe(!str_contains((string) @file_get_contents($systemctlLog), 'restart powermanagement-update.path'), 'Pfad-Einheit ausgefallen: der Nachlauf heilt nicht');
    $r = $mitSystemd(['PM_PRUEFUNG_FAILED' => '0']);
    pruefe(str_contains((string) @file_get_contents($systemctlLog), 'restart powermanagement-update.path') && str_contains($r['aus'], 'wieder gestartet'),
        'Pfad-Einheit ausgefallen, soll/ in Ordnung: der Lauf heilt sie');

    // --------------------------------------------- 8 · Sperre
    $halter = $sperreHalten($sperre);
    $skript = $fall(['ergebnis' => 'geprueft']);
    $T += 61;
    $auftrag('pruefen');
    $r = $helfer(['--update-skript' => $skript]);
    gleich($r['code'], 0, 'Sperre belegt: Exit 0');
    pruefe($r['dauer'] < 15, 'Sperre belegt: endet nach kurzem Warten (' . round($r['dauer'], 1) . ' s)');
    pruefe(is_file($d['soll'] . '/auftrag') && !$aufgerufen($skript), 'Sperre belegt: Auftrag bleibt liegen, nichts ausgeführt');
    $sperreLoesen($halter);
    $r = $helfer(['--update-skript' => $skript]);
    pruefe($aufgerufen($skript) && $auftragWeg(), 'Sperre frei: der liegende Auftrag wird abgeholt');

    // --------------------------------------------- 9 · Signale
    foreach (['INT' => 2, 'TERM' => 15, 'HUP' => 1] as $name => $nummer) {
        $T += 121;
        $skript = $fall(['lang' => 'ja', 'ergebnis' => 'eingespielt']);
        $k = $auftrag('aktualisieren', $sha['M57']);
        $h = $helferHintergrund(['--update-skript' => $skript]);
        $laeuft = warten(static fn (): bool => is_file($fallDir($skript) . '/laeuft'), 15);
        pruefe($laeuft, "SIG{$name}: das Update läuft");
        $start = microtime(true);
        proc_terminate($h['p'], $nummer);
        $fertig = warten(static fn (): bool => !(proc_get_status($h['p'])['running'] ?? false), 10);
        $dauer = microtime(true) - $start;
        pruefe($fertig && $dauer < 10, "SIG{$name}: Helfer endet in weniger als 10 s (" . round($dauer, 1) . ' s)');
        gleich(trim((string) @file_get_contents($fallDir($skript) . '/signal')), 'TERM', "SIG{$name}: das Update erhält TERM");
        $l = $lauf();
        gleich([$l['kennung'] ?? null, $l['zustand'] ?? null, $l['ergebnis'] ?? null, $l['grund'] ?? null],
            [$k, 'fehler', 'zurueckgerollt', 'abgebrochen'], "SIG{$name}: Lauf fehler zurueckgerollt abgebrochen");
        pruefe(!str_contains((string) @file_get_contents($d['zustand'] . '/gescheitert'), $sha['M57'] . ' '),
            "SIG{$name}: ein Abbruch zählt nicht als gescheitert");
        $ausgaben .= (string) @file_get_contents($h['aus']);
    }
    // Lange Läufe: Puls und Fortschritt während des Laufs
    $T += 121;
    $skript = $fall(['lang' => 'ja', 'ergebnis' => 'eingespielt']);
    $k = $auftrag('aktualisieren', $sha['M57']);
    $h = $helferHintergrund(['--update-skript' => $skript]);
    warten(static fn (): bool => is_file($fallDir($skript) . '/laeuft'), 15);
    $mitPuls = warten(static fn (): bool => str_contains((string) @file_get_contents($d['ist'] . '/lauf'), "schritt 6 12 Migrationen ausführen\n"), 6);
    pruefe($mitPuls, 'während des Laufs: ist/lauf zeigt den Schritt aus dem Fortschritt');
    gleich($lauf()['zustand'] ?? null, 'laeuft', 'während des Laufs: zustand laeuft');
    gleich(lesen($d['zustand'] . '/lauf')['zustand'] ?? null, 'laeuft', 'während des Laufs: root-Kopie laeuft');
    $protokollDa = warten(static fn (): bool => str_contains((string) @file_get_contents($d['ist'] . '/protokoll'), "kennung {$k}\n"), 6);
    pruefe($protokollDa, 'während des Laufs: ist/protokoll wird nachgeführt');
    proc_terminate($h['p'], 15);
    warten(static fn (): bool => !(proc_get_status($h['p'])['running'] ?? false), 10);

    // --------------------------------------------- 10 · Wiederanlauf
    $marke = $d['zustand'] . '/offen';
    $alt = $d['zustand'] . '/alt';
    mkdir($alt, 0700);
    $markeSchreiben = static function (string $altSkript, int $zeit, string $kopf = '# powermanagement update-offen 1') use ($marke, $sha): void {
        file_put_contents($marke, $kopf . "\nphase stufe2\nzeit {$zeit}\nkennung -\nausloeser hand\nvon admin\n"
            . "alt {$sha['B0']}\nalt_zweig main\nzweig main\nneu {$sha['M57']}\nalt_skript {$altSkript}\nmigriert ja\n");
        chmod($marke, 0600);
    };
    $altFall = static function (array $werte) use ($alt, $marke, $d): string {
        copy(ATTRAPPE, $alt . '/update-debian.sh');
        @unlink($alt . '/aufruf');
        $text = '';
        foreach ($werte + ['ergebnis' => 'zurueckgerollt', 'grund' => 'unterbrochen', 'rc' => 2, 'marke_loeschen' => $marke,
            'verlauf' => $d['zustand'] . '/verlauf'] as $kk => $v) {
            $text .= $kk . ' ' . $v . "\n";
        }
        file_put_contents($alt . '/fall', $text);
        return $alt . '/update-debian.sh';
    };
    $markenZeit = $T - 600;
    $altSkript = $altFall([]);
    $markeSchreiben($altSkript, $markenZeit);
    $neues = $fall(['ergebnis' => 'eingespielt']);
    $T += 121;
    $k = $auftrag('aktualisieren', $sha['M57']);
    $r = $helfer(['--update-skript' => $neues]);
    $a = lesen($alt . '/aufruf');
    gleich($a['arg'][0] ?? null, '--wiederaufnehmen', 'Wiederanlauf: alte Fassung mit --wiederaufnehmen');
    gleich(count($a['arg']), 3, 'Wiederanlauf: dazu nur Ergebnis- und Fortschrittsdatei');
    pruefe(in_array('PM_UPDATE_AUSLOESER=system', $a['env'], true) && in_array('PM_UPDATE_VON=system', $a['env'], true),
        'Wiederanlauf: Auslöser system');
    pruefe(!$aufgerufen($neues), 'Wiederanlauf: das neue Skript läuft nicht');
    gleich([$lauf()['kennung'] ?? null, $lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], [$k, 'abgelehnt', 'wiederhergestellt'],
        'Wiederanlauf: ein Aktualisieren im selben Lauf wird abgelehnt (wiederhergestellt)');
    pruefe(!file_exists($marke) && !file_exists($d['zustand'] . '/wiederaufnahme'), 'Wiederanlauf: Marke und Zähler weg');
    gleich(lesen($d['ist'] . '/helfer')['offen'] ?? null, 'nein', 'Wiederanlauf: ist/helfer offen nein');
    $v = lesen($d['ist'] . '/verlauf');
    pruefe(str_contains((string) end($v['eintrag']), ' system system aktualisieren zurueckgerollt unterbrochen '),
        'Wiederanlauf: Verlauf zeigt das Zurückrollen');
    $wk = explode(' ', (string) end($v['eintrag']))[2] ?? '';
    pruefe(preg_match('/^[0-9a-f]{32}$/', $wk) === 1 && glob($d['protokolle'] . '/*-' . substr($wk, 0, 8) . '.log') !== [],
        'Wiederanlauf: eigenes Protokoll mit eigener Kennung');

    // update.lock belegt: kein Wiederaufnehmen (ein Update von der Konsole läuft)
    $altSkript = $altFall([]);
    $markeSchreiben($altSkript, $markenZeit);
    $halter = $sperreHalten($usperre);
    $helfer(['--update-skript' => $nie]);
    pruefe(!is_file($alt . '/aufruf'), 'update.lock belegt: kein Wiederaufnehmen');
    pruefe(!file_exists($d['zustand'] . '/wiederaufnahme'), 'update.lock belegt: Zähler unverändert');
    gleich(lesen($d['ist'] . '/helfer')['offen'] ?? null, 'ja', 'update.lock belegt: ist/helfer offen ja');
    $sperreLoesen($halter);

    // Zurückrollen scheitert (unvollständig, Marke bleibt): zweiter Versuch beim nächsten Wecken, dann aufgegeben
    $altSkript = $altFall(['ergebnis' => 'unvollstaendig', 'rc' => 3, 'marke_loeschen' => '']);
    $helfer(['--update-skript' => $nie]);
    pruefe(is_file($alt . '/aufruf'), 'Versuch 1: Wiederaufnehmen');
    gleich(trim((string) @file_get_contents($d['zustand'] . '/wiederaufnahme')), $markenZeit . ' 1', 'Versuch 1: Zähler 1');
    gleich([$lauf()['aktion'] ?? null, $lauf()['ergebnis'] ?? null], ['wiederaufnehmen', 'unvollstaendig'], 'Versuch 1: Lauf unvollstaendig');
    $altSkript = $altFall(['ergebnis' => 'unvollstaendig', 'rc' => 3, 'marke_loeschen' => '']);
    $helfer(['--update-skript' => $nie]);
    pruefe(is_file($alt . '/aufruf'), 'Versuch 2: Wiederaufnehmen');
    gleich(lesen($d['ist'] . '/helfer')['offen'] ?? null, 'aufgegeben', 'nach zwei Versuchen: ist/helfer offen aufgegeben');
    $altSkript = $altFall([]);
    $helfer(['--update-skript' => $nie]);
    pruefe(!is_file($alt . '/aufruf'), 'nach zwei Versuchen: keine Ausführung mehr');
    $l = $lauf();
    gleich([$l['aktion'] ?? null, $l['zustand'] ?? null, $l['ergebnis'] ?? null, $l['grund'] ?? null],
        ['wiederaufnehmen', 'fehler', 'unvollstaendig', 'unterbrochen'], 'aufgegeben: Lauf fehler unvollstaendig unterbrochen');
    $kAufgegeben = $l['kennung'] ?? '';
    $helfer(['--update-skript' => $nie]);
    gleich($lauf()['kennung'] ?? null, $kAufgegeben, 'aufgegeben: wird nur einmal gemeldet');
    $T += 121;
    $auftrag('aktualisieren', $sha['M57']);
    $helfer(['--update-skript' => $nie]);
    gleich($lauf()['grund'] ?? null, 'offen', 'Marke steht: Aktualisieren abgelehnt (offen)');
    $T += 61;
    $skript = $fall(['ergebnis' => 'geprueft']);
    $auftrag('pruefen');
    $helfer(['--update-skript' => $skript]);
    pruefe($aufgerufen($skript), 'Marke steht: Suchen geht trotzdem');
    unlink($marke);
    @unlink($d['zustand'] . '/wiederaufnahme');

    // alt_skript mit fremdem Pfad, ohne Schnittstelle, Marke mit falschem Kopf: die Fassung aus deploy/
    $fremdDir = $basis . '/fremd';
    mkdir($fremdDir, 0755);
    copy(ATTRAPPE, $fremdDir . '/update-debian.sh');
    file_put_contents($fremdDir . '/fall', "ergebnis zurueckgerollt\n");
    $ohneSchnittstelle = $alt . '/ohne.sh';
    file_put_contents($ohneSchnittstelle, "#!/bin/bash\necho alt > " . escapeshellarg($alt . '/ohne-aufgerufen') . "\n");
    foreach (['fremder Pfad' => [$fremdDir . '/update-debian.sh', null], 'ohne Schnittstelle' => [$ohneSchnittstelle, null],
        'Pfad mit ..' => [$alt . '/../alt/update-debian.sh', null], 'falscher Kopf' => [$alt . '/update-debian.sh', '# powermanagement update-offen 9']] as $name => [$pfad, $kopf]) {
        $altFall([]);
        $markeSchreiben($pfad, $markenZeit + 1, $kopf ?? '# powermanagement update-offen 1');
        $ersatz = $fall(['ergebnis' => 'zurueckgerollt', 'grund' => 'unterbrochen', 'rc' => 2, 'marke_loeschen' => $marke]);
        $helfer(['--update-skript' => $ersatz]);
        pruefe($aufgerufen($ersatz) && !is_file($fremdDir . '/aufruf') && !is_file($alt . '/ohne-aufgerufen') && !is_file($alt . '/aufruf'),
            "alt_skript ({$name}): nicht genommen, sondern deploy/update-debian.sh");
        @unlink($marke);
        @unlink($d['zustand'] . '/wiederaufnahme');
    }

    // Absturz ohne Ergebnis, die Marke steht (wie nach Stufe 1): im selben Lauf zurückrollen
    $altFall([]);
    $T += 121;
    $markeSchreiben($alt . '/update-debian.sh', $T);
    rename($marke, $basis . '/marke-vorlage');
    $absturz = $fall(['rc' => 137, 'marke_vorlage' => $basis . '/marke-vorlage', 'marke_ziel' => $marke]);
    $auftrag('aktualisieren', $sha['M57']);
    $helfer(['--update-skript' => $absturz]);
    pruefe($aufgerufen($absturz) && is_file($alt . '/aufruf'), 'Absturz mit Marke: im selben Lauf wiederaufgenommen');
    gleich([$lauf()['aktion'] ?? null, $lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null],
        ['wiederaufnehmen', 'zurueckgerollt', 'unterbrochen'], 'Absturz mit Marke: zurueckgerollt unterbrochen');
    pruefe(!file_exists($marke), 'Absturz mit Marke: Marke weg');

    // --------------------------------------------- 11 · Nachlauf
    $rootLauf = static function (array $felder) use ($d): void {
        $text = "# powermanagement update-lauf 1\n";
        foreach ($felder as $kk => $v) {
            $text .= $kk . ' ' . $v . "\n";
        }
        file_put_contents($d['zustand'] . '/lauf', $text);
    };
    $kN = bin2hex(random_bytes(16));
    $inv = bin2hex(random_bytes(16));
    $rootLauf(['kennung' => $kN, 'aktion' => 'aktualisieren', 'ausloeser' => 'hand', 'von' => 'admin', 'zustand' => 'laeuft',
        'ergebnis' => '-', 'grund' => '-', 'schritt' => '9 12 Dienste starten', 'beginn' => (string) ($T - 3700), 'puls' => (string) $T,
        'ende' => '-', 'alt' => $sha['B0'], 'neu' => '-', 'ziel' => $sha['M45'], 'sicherung' => '-', 'pruefung' => '-',
        'invocation' => $inv, 'protokoll' => '-']);
    $r = $helfer(['--nachlauf'], ['SERVICE_RESULT' => 'timeout', 'INVOCATION_ID' => $inv]);
    gleich($r['code'], 0, 'Nachlauf: Exit 0');
    $l = $lauf();
    gleich([$l['kennung'] ?? null, $l['zustand'] ?? null, $l['ergebnis'] ?? null, $l['grund'] ?? null],
        [$kN, 'fehler', 'abgebrochen', 'zeitueberschreitung'], 'Nachlauf mit SERVICE_RESULT=timeout: fehler zeitueberschreitung');
    gleich($l['schritt'] ?? null, '9 12 Dienste starten', 'Nachlauf: Schritt bleibt');
    pruefe(preg_match('/^' . $sha['M45'] . ' [0-9]+ zeitueberschreitung$/m', (string) @file_get_contents($d['zustand'] . '/gescheitert')) === 1,
        'Nachlauf: Zeitüberschreitung vermerkt den Stand als gescheitert');
    gleich(lesen($d['zustand'] . '/lauf')['zustand'] ?? null, 'fehler', 'Nachlauf: auch die root-Kopie');
    $rootLauf(['kennung' => $kN, 'aktion' => 'pruefen', 'zustand' => 'laeuft']);   // Lauf-Datei eines älteren Helfers
    $helfer(['--nachlauf']);
    gleich([$lauf()['zustand'] ?? null, $lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['fehler', 'abgebrochen', 'helfer'],
        'Nachlauf ohne Zeitlimit (ältere Lauf-Datei): fehler helfer');
    $rootLauf(['kennung' => $kN, 'aktion' => 'pruefen', 'zustand' => 'laeuft']);
    $halter = $sperreHalten($usperre);
    $vorher = (string) @file_get_contents($d['ist'] . '/lauf');
    $helfer(['--nachlauf']);
    gleich((string) @file_get_contents($d['ist'] . '/lauf'), $vorher, 'Nachlauf bei belegter update.lock: nichts nachgetragen');
    $sperreLoesen($halter);
    $rootLauf(['kennung' => $kN, 'aktion' => 'aktualisieren', 'zustand' => 'fehler', 'ergebnis' => 'zurueckgerollt',
        'grund' => 'abgebrochen', 'ziel' => $sha['M44'], 'beginn' => (string) $T, 'invocation' => $inv]);
    $helfer(['--nachlauf'], ['SERVICE_RESULT' => 'timeout', 'INVOCATION_ID' => $inv]);
    gleich([$lauf()['ergebnis'] ?? null, $lauf()['grund'] ?? null], ['zurueckgerollt', 'zeitueberschreitung'],
        'Nachlauf nach sauberem Zurückrollen im Zeitlimit: Grund zeitueberschreitung');
    pruefe(str_contains((string) @file_get_contents($d['zustand'] . '/gescheitert'), $sha['M44'] . ' '),
        'Nachlauf: auch dann gilt der Stand als gescheitert');
    $rootLauf(['kennung' => $kN, 'aktion' => 'aktualisieren', 'zustand' => 'fertig', 'ergebnis' => 'eingespielt', 'grund' => '-']);
    $vorher = (string) @file_get_contents($d['ist'] . '/lauf');
    $helfer(['--nachlauf'], ['SERVICE_RESULT' => 'success']);
    gleich((string) @file_get_contents($d['ist'] . '/lauf'), $vorher, 'Nachlauf nach einem fertigen Lauf: nichts');

    // --------------------------------------------- 12 · Protokoll, Kürzen
    $verlauf = '';
    for ($i = 0; $i < 150; $i++) {
        $verlauf .= sprintf("eintrag %d %d - konsole root aktualisieren eingespielt - %s %s -\n", 1700000000 + $i, 1700000060 + $i,
            $sha['B0'], $sha['M1']);
    }
    file_put_contents($d['zustand'] . '/verlauf', $verlauf . "kaputt \033[31m\n");
    $kennungen = '';
    for ($i = 0; $i < 250; $i++) {
        $kennungen .= bin2hex(random_bytes(16)) . ' ' . (1700000000 + $i) . "\n";
    }
    file_put_contents($d['zustand'] . '/kennungen', $kennungen);
    foreach (glob($d['protokolle'] . '/*.log') as $alteDatei) {
        unlink($alteDatei);
    }
    for ($i = 0; $i < 35; $i++) {
        file_put_contents(sprintf('%s/20200101-0000%02d-aaaaaaaa.log', $d['protokolle'], $i), "alt\n");
    }
    $T += 121;
    $gross = $fall(['gross' => 'ja', 'ergebnis' => 'eingespielt', 'verlauf' => $d['zustand'] . '/verlauf']);
    $k = $auftrag('aktualisieren', $sha['M57']);
    $r = $helfer(['--update-skript' => $gross]);
    gleich([$lauf()['kennung'] ?? null, $lauf()['ergebnis'] ?? null], [$k, 'eingespielt'], '2 MiB Ausgabe: eingespielt');
    pruefe(is_file($fallDir($gross) . '/fertig'), '2 MiB Ausgabe: die Attrappe lief zu Ende (kein SIGPIPE)');
    $p = (string) @file_get_contents($d['ist'] . '/protokoll');
    $kopfLaenge = strlen("# powermanagement update-protokoll 1\nkennung {$k}\n\n");
    pruefe(strlen($p) <= 65536 + $kopfLaenge, 'ist/protokoll: höchstens 64 KiB plus Kopf (' . strlen($p) . ' Byte)');
    pruefe(str_contains($p, '==> Ergebnis: eingespielt'), 'ist/protokoll: das Ende des Laufs steht drin');
    $r2 = $helfer(['--update-skript' => $nie]);   // ohne Auftrag
    $klein = $fall(['ergebnis' => 'eingespielt']);
    $T += 121;
    $k = $auftrag('aktualisieren', $sha['M57']);
    $r = $helfer(['--update-skript' => $klein]);
    $p = (string) @file_get_contents($d['ist'] . '/protokoll');
    pruefe(!str_contains($p, "\033"), 'ist/protokoll: keine ESC-Bytes');
    pruefe(!str_contains($p, "\r") && !str_contains($p, "\001"), 'ist/protokoll: kein CR, keine Steuerzeichen');
    pruefe(!str_contains($p, 'ghp_PRUEFUNG') && !str_contains($p, 'x-access-token:'), 'ist/protokoll: Token und Zugangsdaten geschwärzt');
    pruefe(str_contains($p, 'https://***@github.com/x/y'), 'ist/protokoll: Adresse ohne Benutzerteil');
    pruefe(str_contains($p, 'Grüße aus der Attrappe: Änderungen, Übergabe, ß'), 'ist/protokoll: Umlaute erhalten');
    pruefe(str_contains($p, '==> Stand prüfen') && str_contains($p, "Zeile mit Wagenrücklauf und Steuerzeichen  Ende"),
        'ist/protokoll: Text ohne Farben und Steuerzeichen');
    pruefe(!str_contains($r['aus'], 'ghp_PRUEFUNG') && !str_contains($r['aus'], "\033") && str_contains($r['aus'], 'Grüße aus der Attrappe'),
        'Journal (Ausgabe des Helfers): bereinigt, mit dem Text des Updates');
    pruefe(str_contains($r['aus'], 'Auftrag ' . substr($k, 0, 8) . ' aktualisieren: eingespielt'), 'Journal: Zeile zum Auftrag');
    $v = lesen($d['ist'] . '/verlauf');
    gleich(count($v['eintrag']), 100, 'ist/verlauf: die letzten 100 Einträge');
    pruefe(!str_contains((string) $v['_roh'], "\033") && !str_contains((string) $v['_roh'], 'kaputt'), 'ist/verlauf: nur gültige Zeilen');
    $kz = array_values(array_filter(explode("\n", (string) file_get_contents($d['zustand'] . '/kennungen'))));
    gleich(count($kz), 200, 'Kennungen: die letzten 200');
    gleich(explode(' ', (string) end($kz))[0], $k, 'Kennungen: die neueste zuletzt');
    $logs = glob($d['protokolle'] . '/*.log');
    gleich(count($logs), 30, 'Protokolle: die letzten 30');
    pruefe(!is_file($d['protokolle'] . '/20200101-000000-aaaaaaaa.log'), 'Protokolle: die ältesten entfernt');
    foreach (['helfer', 'stand', 'lauf', 'verlauf', 'protokoll'] as $datei) {
        gleich(modus($d['ist'] . '/' . $datei), '640', "ist/{$datei}: 640");
    }
    pruefe(glob($d['ist'] . '/.neu.*') === [] && glob($d['run'] . '/powermanagement-update.*') === [],
        'keine Temp-Dateien übrig (ist/, Arbeitsordner)');
    // Arbeitsordner eines abgestürzten Laufs: weg, sobald update.lock frei ist
    mkdir($d['run'] . '/powermanagement-update.Ab12Cd', 0700);
    file_put_contents($d['run'] . '/powermanagement-update.Ab12Cd/ergebnis', "alt\n");
    mkdir($d['run'] . '/fremder-ordner', 0700);
    $halter = $sperreHalten($usperre);
    $helfer(['--update-skript' => $nie]);
    pruefe(is_dir($d['run'] . '/powermanagement-update.Ab12Cd'), 'Arbeitsordner eines früheren Laufs bleibt, solange update.lock belegt ist');
    $sperreLoesen($halter);
    $helfer(['--update-skript' => $nie]);
    pruefe(!file_exists($d['run'] . '/powermanagement-update.Ab12Cd'), 'Arbeitsordner eines früheren Laufs wird aufgeräumt');
    pruefe(is_dir($d['run'] . '/fremder-ordner'), 'andere Ordner im Arbeitsbereich bleiben');

    // --------------------------------------------- 13 · keine Geheimnisse
    $geheim = 'ghp_GEHEIM' . bin2hex(random_bytes(10));
    $geheimConf = 'GITHUB_TOKEN=github_pat_GEHEIM' . bin2hex(random_bytes(10));
    file_put_contents($d['etc'] . '/git.conf', $geheimConf . "\n");
    git($d['ziel'], 'remote', 'set-url', 'origin', 'https://x-access-token:' . $geheim . '@github.com/agrotevent/powermanagement');
    $r = $helfer(['--update-skript' => $nie]);
    gleich($stand()['quelle'] ?? null, '-', 'Remote mit Zugangsdaten: quelle -');
    git($d['ziel'], 'remote', 'set-url', 'origin', 'https://github.com/agrotevent/powermanagement.git');
    $helfer(['--update-skript' => $nie]);
    gleich($stand()['quelle'] ?? null, 'https://github.com/agrotevent/powermanagement', 'GitHub-Adresse: quelle ohne .git');
    git($d['ziel'], 'remote', 'set-url', 'origin', $d['repo']);
    $istInhalt = '';
    foreach (glob($d['ist'] . '/*') as $datei) {
        $istInhalt .= (string) file_get_contents($datei);
    }
    pruefe(!str_contains($istInhalt . $ausgaben, $geheim), 'kein Token aus der Remote-Adresse in ist/ und in Ausgaben');
    pruefe(!str_contains($istInhalt . $ausgaben, substr($geheimConf, 13)), 'kein Token aus git.conf in ist/ und in Ausgaben');
    pruefe(!str_contains($istInhalt . $ausgaben, 'ghp_PRUEFUNG0123456789abcdef'), 'das Token der Attrappe steht nirgends');
    pruefe(!str_contains($ausgaben, 'unerwarteter Fehler'), 'kein Lauf endete mit einem unerwarteten Fehler (ERR-Trap)');

    // --------------------------------------------- 14 · --auftrag-pruefen (Abgleich mit PHP)
    $zustandVorher = array_map('basename', glob($d['zustand'] . '/*'));
    sort($zustandVorher);
    $nurPruefen = static fn (string $text): string => trim(ausfuehren(['bash', HELFER, '--auftrag-pruefen', '--jetzt=' . $T], $text)['aus']);
    foreach (['hand', 'automatik', 'konsole'] as $ausloeser) {
        gleich($nurPruefen($auftragText('pruefen', null, $ausloeser)[1]), 'ok pruefen', "--auftrag-pruefen: pruefen ({$ausloeser})");
        gleich($nurPruefen($auftragText('aktualisieren', $sha['M57'], $ausloeser)[1]), 'ok aktualisieren',
            "--auftrag-pruefen: aktualisieren ({$ausloeser})");
    }
    $verdorben = 0;
    [, , $ablehnungen] = $ablehnungenBauen();
    foreach ($ablehnungen as $name => [$text, $code]) {
        if ($code === 'doppelt') {
            continue;   // Zustand – den sieht --auftrag-pruefen nicht
        }
        $verdorben++;
        gleich($nurPruefen($text), 'abgelehnt ' . $code, "--auftrag-pruefen ({$name}): abgelehnt {$code}");
    }
    pruefe($verdorben >= 12, '--auftrag-pruefen: mindestens zwölf verdorbene Varianten');

    // Die Anwendung baut den Auftrag (UpdateService::auftragText) – der Helfer
    // nimmt jeden an, den sie baut. Was sie gar nicht erst baut, lehnt er mit
    // demselben Code ab, den sie meldet.
    $roherAuftrag = static fn (string $k, string $aktion, string $ausloeser, string $von, string $zeit, ?string $ziel): string
        => KOPF . "kennung {$k}\naktion {$aktion}\nausloeser {$ausloeser}\nvon {$von}\nzeit {$zeit}\n" . ($ziel !== null ? "ziel {$ziel}\n" : '');
    foreach (['hand', 'automatik', 'konsole'] as $ausloeser) {
        foreach (['pruefen' => null, 'aktualisieren' => $sha['M57']] as $aktion => $ziel) {
            $k = bin2hex(random_bytes(16));
            $text = \App\Services\UpdateService::auftragText($k, $aktion, $ausloeser, 'pruefung', $T, $ziel);
            gleich($text, $roherAuftrag($k, $aktion, $ausloeser, 'pruefung', (string) $T, $ziel), "PHP-Auftrag {$aktion} ({$ausloeser}): Format wie erwartet");
            gleich($nurPruefen($text), 'ok ' . $aktion, "PHP-Auftrag {$aktion} ({$ausloeser}): der Helfer nimmt ihn an");
        }
    }
    $phpK = bin2hex(random_bytes(16));
    $phpVerdorben = [
        'Kennung zu kurz'          => [substr($phpK, 0, 31), 'pruefen', 'hand', 'pruefung', $T, null],
        'Kennung zu lang'          => [$phpK . 'a', 'pruefen', 'hand', 'pruefung', $T, null],
        'Kennung mit Großbuchstaben' => [strtoupper($phpK), 'pruefen', 'hand', 'pruefung', $T, null],
        'Aktion zuruecknehmen'     => [$phpK, 'zuruecknehmen', 'hand', 'pruefung', $T, null],
        'Auslöser system'          => [$phpK, 'pruefen', 'system', 'pruefung', $T, null],
        'Von mit Leerzeichen'      => [$phpK, 'pruefen', 'hand', 'a b', $T, null],
        'Von zu lang'              => [$phpK, 'pruefen', 'hand', str_repeat('a', 65), $T, null],
        'Zeit zu kurz'             => [$phpK, 'pruefen', 'hand', 'pruefung', 123, null],
        'Ziel zu kurz'             => [$phpK, 'aktualisieren', 'hand', 'pruefung', $T, substr($sha['M57'], 0, 39)],
        'Ziel mit Großbuchstaben'  => [$phpK, 'aktualisieren', 'hand', 'pruefung', $T, strtoupper($sha['M57'])],
        'Ziel bei pruefen'         => [$phpK, 'pruefen', 'hand', 'pruefung', $T, $sha['M57']],
        'Ziel fehlt'               => [$phpK, 'aktualisieren', 'hand', 'pruefung', $T, null],
    ];
    foreach ($phpVerdorben as $name => [$k, $aktion, $ausloeser, $von, $zeit, $ziel]) {
        $phpCode = null;
        try {
            \App\Services\UpdateService::auftragText($k, $aktion, $ausloeser, $von, $zeit, $ziel);
        } catch (\InvalidArgumentException $e) {
            $phpCode = $e->getMessage();
        }
        pruefe($phpCode !== null, "PHP baut keinen Auftrag ({$name})");
        gleich($nurPruefen($roherAuftrag($k, $aktion, $ausloeser, $von, (string) $zeit, $ziel)), 'abgelehnt ' . $phpCode,
            "PHP und Helfer lehnen gleich ab ({$name}: {$phpCode})");
    }
    gleich($nurPruefen($auftragText('pruefen', null, 'hand', null, $kennungEingespielt)[1]), 'ok pruefen',
        '--auftrag-pruefen: bekannte Kennung (Zustand) spielt keine Rolle');
    $zustandNachher = array_map('basename', glob($d['zustand'] . '/*'));
    sort($zustandNachher);
    gleich($zustandNachher, $zustandVorher, '--auftrag-pruefen: der Zustand bleibt unberührt');

    // --------------------------------------------- 15 · nur als root: Code-Prüfung und setpriv
    if (!ALS_ROOT) {
        fwrite(STDOUT, "  (Prüfung des Codes und setpriv übersprungen – nur als root)\n");
    } else {
        $mitPruefung = static fn (array $zusatz = []): array => $helfer(['--ohne-eigentuemerpruefung' => false, '--update-skript' => $nie] + $zusatz);
        $r = $mitPruefung();
        gleich($r['code'], 0, 'root: Code root-eigen → Exit 0');
        $faelleRoot = [
            'Datei in deploy/ für die Gruppe schreibbar' => [fn () => chmod($d['ziel'] . '/deploy/x.conf', 0664), fn () => chmod($d['ziel'] . '/deploy/x.conf', 0644)],
            'Verweis in deploy/' => [fn () => symlink('/etc/passwd', $d['ziel'] . '/deploy/verweis'), fn () => unlink($d['ziel'] . '/deploy/verweis')],
            'Hook nicht root-eigen' => [fn () => (file_put_contents($d['ziel'] . '/.git/hooks/post-merge', "#!/bin/sh\n") !== false)
                && chown($d['ziel'] . '/.git/hooks/post-merge', 'nobody'), fn () => unlink($d['ziel'] . '/.git/hooks/post-merge')],
            '.git/config nicht root-eigen' => [fn () => chown($d['ziel'] . '/.git/config', 'nobody'), fn () => chown($d['ziel'] . '/.git/config', 'root')],
            'bin/power für andere schreibbar' => [fn () => chmod($d['ziel'] . '/bin/power', 0646), fn () => chmod($d['ziel'] . '/bin/power', 0644)],
            'update-debian.sh nicht root-eigen' => [fn () => chown($nie, 'nobody'), fn () => chown($nie, 'root')],
        ];
        foreach ($faelleRoot as $name => [$vorher, $nachher]) {
            $vorher();
            $r = $mitPruefung();
            gleich($r['code'], 1, "root: {$name} → Exit 1");
            pruefe(str_contains($r['err'], 'root-eigen'), "root: {$name} → Meldung nennt es");
            $nachher();
        }
        file_put_contents($conf, "OBERFLAECHE=ja\n");
        chown($conf, 'nobody');
        $T += 61;
        $auftrag('pruefen');
        $mitPruefung();
        gleich($lauf()['grund'] ?? null, 'abgeschaltet', 'root: update.conf nicht root-eigen → abgeschaltet');
        unlink($conf);

        // Als App-Benutzer: soll/ und ist/ gehören nobody
        $soll2 = $basis . '/app/soll';
        $ist2 = $basis . '/app/ist';
        mkdir($soll2, 0750, true);
        mkdir($ist2, 0750, true);
        chmod($basis . '/app', 0755);
        foreach ([$soll2, $ist2] as $dir) {
            chown($dir, 'nobody');
            chgrp($dir, 'nogroup');
        }
        $app = static fn (string $skript): array => $helfer(['--app-benutzer' => 'nobody', '--soll' => $soll2, '--ist' => $ist2,
            '--update-skript' => $skript]);
        $schreibenAlsApp = static function (string $text) use ($soll2): void {
            file_put_contents($soll2 . '/.neu', $text);
            chown($soll2 . '/.neu', 'nobody');
            rename($soll2 . '/.neu', $soll2 . '/auftrag');
        };
        $T += 61;
        [$kApp, $tApp] = $auftragText('pruefen');
        $schreibenAlsApp($tApp);
        $skript = $fall(['ergebnis' => 'geprueft']);
        $r = $app($skript);
        $lApp = lesen($ist2 . '/lauf');
        gleich([$r['code'], $lApp['kennung'] ?? null, $lApp['ergebnis'] ?? null], [0, $kApp, 'geprueft'], 'setpriv: Auftrag als App-Benutzer übernommen');
        foreach (['lauf', 'stand', 'helfer', 'verlauf', 'protokoll'] as $datei) {
            clearstatcache();
            gleich([is_file("{$ist2}/{$datei}") ? posix_getpwuid(fileowner("{$ist2}/{$datei}"))['name'] : null, modus("{$ist2}/{$datei}")],
                ['nobody', '640'], "setpriv: ist/{$datei} gehört dem App-Benutzer, 640");
        }
        $geheimDatei = $basis . '/nur-root';
        file_put_contents($geheimDatei, $auftragText('pruefen')[1]);
        chmod($geheimDatei, 0600);
        symlink($geheimDatei, $soll2 . '/auftrag');
        $app($nie);
        gleich(lesen($ist2 . '/lauf')['grund'] ?? null, 'keine_datei', 'setpriv: Verweis auf eine Datei nur für root → keine_datei');
        pruefe(is_file($geheimDatei), 'setpriv: Ziel des Verweises unberührt');
        copy($geheimDatei, $soll2 . '/auftrag');
        chmod($soll2 . '/auftrag', 0600);   // root gehört sie, der App-Benutzer kann sie nicht lesen
        $app($nie);
        gleich(lesen($ist2 . '/lauf')['grund'] ?? null, 'keine_datei', 'setpriv: Datei, die der App-Benutzer nicht lesen darf → keine_datei');
        pruefe(!file_exists($soll2 . '/auftrag'), 'setpriv: auch sie ist danach weg');
        // soll/, in dem der App-Benutzer nichts wegräumen kann: der Auftrag bliebe
        // liegen, PathExists sähe ihn weiter – dann nie die Pfad-Einheit heilen.
        $appMitSystemd = static fn (): array => ausfuehren($optionen(['--app-benutzer' => 'nobody', '--soll' => $soll2, '--ist' => $ist2,
            '--update-skript' => $nie, '--ohne-systemd' => false]), null, ['PM_PRUEFUNG_FAILED' => '0'] + $mitStubs);
        foreach (['555' => 0555, '000' => 0000] as $name => $modus) {
            $T += 61;
            $schreibenAlsApp($auftragText('pruefen')[1]);
            chmod($soll2, $modus);
            @unlink($systemctlLog);
            $appMitSystemd();
            $hApp = lesen($ist2 . '/helfer');
            gleich($hApp['pfad'] ?? null, 'soll_ungueltig', "setpriv: soll/ {$name} → pfad soll_ungueltig");
            pruefe(!str_contains((string) @file_get_contents($systemctlLog), 'restart powermanagement-update.path'),
                "setpriv: soll/ {$name} → Pfad-Einheit nicht geheilt");
            chmod($soll2, 0750);
            @unlink($soll2 . '/auftrag');
        }
        @unlink($systemctlLog);
        $appMitSystemd();
        gleich(lesen($ist2 . '/helfer')['pfad'] ?? null, 'repariert', 'setpriv: soll/ wieder beschreibbar → pfad repariert');
        pruefe(str_contains((string) @file_get_contents($systemctlLog), 'restart powermanagement-update.path'), 'setpriv: soll/ in Ordnung → Pfad-Einheit geheilt');
        clearstatcache();
        gleich(fileowner($d['zustand'] . '/lauf'), 0, 'setpriv: Zustand bleibt root');
    }
} catch (Throwable $e) {
    pruefe(false, 'Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

ende('update-helfer');
