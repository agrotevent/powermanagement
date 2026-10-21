<?php
declare(strict_types=1);

/**
 * Update in der Anwendung (System → Update, bin/power update:*).
 *
 * Eigene Instanz: Konfiguration über POWERMANAGEMENT_CONFIG, eigene SQLite,
 * eigenes storage/update (update.verzeichnis) und ein eigenes .git
 * (update.git_verzeichnis) im Temp-Ordner, eigener PHP-Server. Den
 * root-Helfer spielt das Skript selbst: es schreibt storage/update/ist so,
 * wie deploy/update-helfer.sh es tut, und liest die Aufträge aus soll/.
 *
 * Geprüft: Auftrag (Format, umbenennen, 0640, einer zugleich, Abstand beim
 * Suchen, Ziel passt zum Stand, Betrieb nur mit „trotzdem“, Besitzer);
 * Parser (Müll, Größe, Kopf, Pflichtfelder, Zusatzzeilen, kaputtes UTF-8,
 * „-“ in abgelehnten Läufen); jeder Zustand über die Zeit; installierter
 * Stand ohne git-Prozess; Schreibsperre (503/Flash, An- und Abmelden, Puls);
 * Rechte; Status-JSON ohne Sitzungssperre; Abzeichen; Audit idempotent;
 * Kommandozeile (stand, suchen, starten --warten mit Fortschritt, Exit-Codes,
 * Wiederanlauf nach einem Absturz, als root abgelehnt); update.js ohne HTML
 * aus Daten. Am Ende wird alles weggeräumt.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Services\BenutzerService;
use App\Services\UpdateAutomatik;
use App\Services\UpdateService;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;

$alsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

$wurzel = dirname(__DIR__);
$kennung = 'pm-update-' . bin2hex(random_bytes(4));
$ordner = sys_get_temp_dir() . '/' . $kennung;
mkdir($ordner . '/sitzungen', 0700, true);
$upd = $ordner . '/update';
$git = $ordner . '/git';
foreach ([$upd . '/soll', $upd . '/ist', $git . '/refs/heads'] as $d) {
    mkdir($d, 0750, true);
}
chmod($upd, 0750);
register_shutdown_function(static function () use ($ordner): void {
    if (!str_contains($ordner, 'pm-update-')) {
        return;
    }
    exec('chown -R ' . (function_exists('posix_geteuid') ? posix_geteuid() : 0) . ' ' . escapeshellarg($ordner) . ' 2>/dev/null; chmod -R u+rwX '
        . escapeshellarg($ordner) . ' 2>/dev/null; rm -rf ' . escapeshellarg($ordner));
});

// Keine PHP-Meldung im eigenen Prozess – jede wird zum Befund.
$meldungen = [];
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile) use (&$meldungen): bool {
    if ((error_reporting() & $nr) === 0) {
        return true;   // mit @ unterdrückt
    }
    $meldungen[] = "{$text} ({$datei}:{$zeile})";
    return true;
});

// ------------------------------------------------------------ Instanz
$vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
$konfig = static function (bool $demo, string $verzeichnis) use ($vorlage, $ordner, $git): string {
    return '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = ' . var_export($demo, true) . ';
$c["app"]["debug"] = true;
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($ordner . '/datenbank.sqlite', true) . ';
$c["update"]["verzeichnis"] = ' . var_export($verzeichnis, true) . ';
$c["update"]["git_verzeichnis"] = ' . var_export($git, true) . ';
return $c;
';
};
file_put_contents($ordner . '/config.php', $konfig(false, $upd));
file_put_contents($ordner . '/demo.php', $konfig(true, $upd));
file_put_contents($ordner . '/langsam.php', $konfig(false, 'langsam://' . $upd));
file_put_contents($ordner . '/leer.php', $konfig(false, $ordner . '/gibt-es-nicht'));

/** bin/power der Instanz; gibt [rc, Ausgabe] zurück. */
$power = static function (array $argumente, string $datei = 'config.php', ?string $stdin = null) use ($wurzel, $ordner): array {
    $p = proc_open(array_merge(['php', $wurzel . '/bin/power'], $argumente), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $rohre, $wurzel, ['POWERMANAGEMENT_CONFIG' => $ordner . '/' . $datei] + getenv());
    fwrite($rohre[0], (string) $stdin);
    fclose($rohre[0]);
    $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    return [proc_close($p), $aus];
};
$kennwort = 'Update-Pruefung-2026!';
[$rc] = $power(['install', $kennung . '-erst', $kennwort, '--ohne-wechsel']);
gleich($rc, 0, 'eigene Instanz eingerichtet');

// Dieser Prozess arbeitet ab hier auf der Instanz.
Config::set('app.demo', false);
Config::set('database.path', $ordner . '/datenbank.sqlite');
Config::set('update.verzeichnis', $upd);
Config::set('update.git_verzeichnis', $git);
Database::trennen();
Settings::vergessen();
Auth::alsBenutzer(null);
$admin = BenutzerService::anlegen($kennung . '-admin', $kennwort, 'admin', false);
BenutzerService::anlegen($kennung . '-technik', $kennwort, 'technik', false);
BenutzerService::anlegen($kennung . '-kunde', $kennwort, 'kunde', false);

// ------------------------------------------------------------ Dateien wie der Helfer
$sha = static fn (string $name): string => sha1($kennung . $name);
$I = $sha('installiert');
$K1 = $sha('kandidat');
$V = $sha('verfuegbar');
$T = time();

$schreiben = static function (string $name, ?string $inhalt) use ($upd): void {
    $pfad = $upd . '/ist/' . $name;
    if ($inhalt === null) {
        @unlink($pfad);
    } else {
        file_put_contents($pfad, $inhalt);
    }
    UpdateService::vergessen();
};
$helferText = static fn (int $zeit, string $oberflaeche = 'ja', string $automatik = 'ja', string $offen = 'nein'): string =>
    UpdateService::KOPF_HELFER . "\nzeit {$zeit}\nfassung {$I}\noberflaeche {$oberflaeche}\nautomatik {$automatik}\noffen {$offen}\npfad ok\n";
$standText = static function (int $erzeugt, array $mehr = []) use ($I, $K1, $V): string {
    $f = $mehr + [
        'abruf' => 'ok', 'abruf_zeit' => (string) ($erzeugt - 10), 'zweig' => 'main', 'quelle' => 'https://github.com/agrotevent/powermanagement',
        'installiert' => $I . ' 1792100000', 'installiert_betreff' => 'Stand vorher', 'verfuegbar' => $V . ' 1792166000', 'neu' => '2',
        'linear' => 'ja', 'lokal_geaendert' => 'nein', 'migrationen' => 'ja', 'einrichtung' => 'nein', 'flows' => 'nein',
    ];
    $text = UpdateService::KOPF_STAND . "\nerzeugt {$erzeugt}\n";
    foreach ($f as $k => $v) {
        $text .= $k . ' ' . $v . "\n";
    }
    return $text . "ziel_kandidat {$V} 1792166000\nziel_kandidat {$K1} 1792160000\n"
        . "commit {$V} 1792166000 Neue Seite <script>alert(1)</script> für Übergaben\n"
        . "commit {$K1} 1792160000 Kabel \x01kürzer\n"
        . 'hinweis ' . substr($V, 0, 7) . " Nach dem Update einmal neu anmelden\nweitere 0\n";
};
$laufText = static function (array $f): string {
    $f += [
        'kennung' => str_repeat('a', 32), 'aktion' => 'aktualisieren', 'ausloeser' => 'hand', 'von' => 'admin', 'zustand' => 'laeuft',
        'ergebnis' => '-', 'grund' => '-', 'schritt' => '6 12 Migrationen ausführen', 'beginn' => (string) (time() - 30),
        'puls' => (string) time(), 'ende' => '-', 'alt' => '-', 'neu' => '-', 'ziel' => '-', 'sicherung' => '-', 'pruefung' => '-',
    ];
    $text = UpdateService::KOPF_LAUF . "\n";
    foreach ($f as $k => $v) {
        foreach ((array) $v as $wert) {
            $text .= $k . ' ' . $wert . "\n";
        }
    }
    return $text . "invocation -\nprotokoll -\n";
};
$verlaufText = static fn (array $zeilen): string => UpdateService::KOPF_VERLAUF . "\n" . implode('', array_map(static fn ($z) => 'eintrag ' . $z . "\n", $zeilen));
$protokollText = static fn (string $k, string $text): string => UpdateService::KOPF_PROTOKOLL . "\nkennung {$k}\n\n" . $text;
$gitHead = static function (string $head, array $refs = [], string $gepackt = '') use ($git): void {
    exec('rm -rf ' . escapeshellarg($git) . ' && mkdir -p ' . escapeshellarg($git . '/refs/heads'));
    file_put_contents($git . '/HEAD', $head . "\n");
    foreach ($refs as $ref => $wert) {
        @mkdir(dirname($git . '/' . $ref), 0755, true);
        file_put_contents($git . '/' . $ref, $wert . "\n");
    }
    if ($gepackt !== '') {
        file_put_contents($git . '/packed-refs', $gepackt);
    }
    UpdateService::vergessen();
};
$auftragWeg = static function () use ($upd): void {
    @unlink($upd . '/soll/auftrag');
    @unlink($upd . '/.letzter');
    UpdateService::vergessen();
};
$eingerichtet = static function () use ($schreiben, $helferText, $standText, $gitHead, $auftragWeg, $I): void {
    $auftragWeg();
    foreach (['lauf', 'wartung', 'verlauf', 'protokoll'] as $n) {
        $schreiben($n, null);
    }
    $schreiben('helfer', $helferText(time()));
    $schreiben('stand', $standText(time()));
    $gitHead('ref: refs/heads/main', ['refs/heads/main' => $I]);
};

try {
    // ------------------------------------------------------------ ohne storage/update
    Config::set('update.verzeichnis', $ordner . '/gibt-es-nicht');
    Config::set('update.git_verzeichnis', $ordner . '/kein-git');
    UpdateService::vergessen();
    gleich(UpdateService::zustand()['zustand'], 'nicht_eingerichtet', 'ohne storage/update: nicht eingerichtet');
    gleich(UpdateService::statusJson()['zustand'], 'nicht_eingerichtet', 'ohne storage/update: Status-JSON');
    gleich(UpdateService::verlauf(), [], 'ohne storage/update: kein Verlauf');
    gleich(UpdateService::protokoll(), '', 'ohne storage/update: kein Protokoll');
    gleich(UpdateService::navHinweis(), null, 'ohne storage/update und ohne git: kein Abzeichen');
    pruefe(str_starts_with(UpdateService::kurzStand(), 'nicht eingerichtet'), 'ohne storage/update: kurzStand');
    gleich(UpdateService::wartungAktiv(), false, 'ohne storage/update: keine Schreibsperre');
    $r = UpdateService::anfordern('pruefen', null, 'hand', 'pruefer');
    pruefe(!$r['ok'] && str_contains($r['message'], 'nicht eingerichtet'), 'ohne storage/update: kein Auftrag');
    gleich(UpdateService::installiert()['commit'], null, 'ohne storage/update und ohne git: installiert unbekannt');
    Config::set('update.git_verzeichnis', $git);
    UpdateService::vergessen();
    gleich(UpdateService::navHinweis(), ['stufe' => 'warn', 'text' => 'Update-Funktion nicht eingerichtet'], 'nicht eingerichtet, Installation per git: Abzeichen gelb');
    Config::set('update.verzeichnis', $upd);
    gleich($meldungen, [], 'ohne storage/update: keine PHP-Meldung');

    // ------------------------------------------------------------ Parser
    $eingerichtet();
    $h = UpdateService::helfer();
    pruefe($h !== null && $h['oberflaeche'] && $h['automatik'] && $h['offen'] === 'nein' && $h['fassung'] === $I, 'helfer: gelesen');
    $schreiben('helfer', "Müll\nzeit 1\n");
    gleich(UpdateService::helfer(), null, 'helfer: Müll → null');
    $schreiben('helfer', str_replace('update-helfer 1', 'update-helfer 2', $helferText(time())));
    gleich(UpdateService::helfer(), null, 'helfer: falscher Kopf → null');
    $schreiben('helfer', $helferText(time()) . str_repeat("neu x\n", 800));
    gleich(UpdateService::helfer(), null, 'helfer: zu groß → null');
    $schreiben('helfer', UpdateService::KOPF_HELFER . "\nfassung {$I}\n");
    gleich(UpdateService::helfer(), null, 'helfer: ohne zeit → null');
    $schreiben('helfer', $helferText(time(), 'nein', 'nein') . "kuenftig etwas neues\n");
    $h = UpdateService::helfer();
    pruefe($h !== null && !$h['oberflaeche'] && !$h['automatik'], 'helfer: Zusatzzeilen einer neueren Fassung werden überlesen');

    $schreiben('lauf', $laufText(['schritt' => "6 12 Migrationen ausf\xc3\xbchren \xff\xfe kaputt", 'warnung' => ['kacheln', 'pruefung']]));
    $l = UpdateService::lauf();
    pruefe($l !== null && mb_check_encoding($l['schritt_name'], 'UTF-8') && str_starts_with($l['schritt_name'], 'Migrationen ausführen'), 'lauf: UTF-8 im Schritt, kaputte Bytes bereinigt');
    pruefe($l !== null && $l['schritt'] === 6 && $l['gesamt'] === 12 && $l['warnungen'] === ['kacheln', 'pruefung'], 'lauf: Schritt und Warnungen');
    $schreiben('lauf', $laufText(['aktion' => '-', 'ausloeser' => '-', 'von' => '-', 'ziel' => '-', 'zustand' => 'fehler', 'ergebnis' => 'abgelehnt', 'grund' => 'kopf', 'schritt' => '0 12 -']));
    $l = UpdateService::lauf();
    pruefe($l !== null && $l['aktion'] === null && $l['ausloeser'] === null && $l['von'] === null && $l['ziel'] === null
        && $l['ergebnis'] === 'abgelehnt' && $l['grund'] === 'kopf' && $l['schritt_name'] === '', 'lauf: abgelehnter Lauf mit „-“');
    $schreiben('lauf', str_replace('kennung ' . str_repeat('a', 32), 'kennung kurz', $laufText([])));
    gleich(UpdateService::lauf(), null, 'lauf: ohne gültige Kennung → null');
    $schreiben('lauf', str_replace('zustand laeuft', '', $laufText([])));
    gleich(UpdateService::lauf(), null, 'lauf: ohne Zustand → null');
    $schreiben('lauf', null);

    $s = UpdateService::stand();
    pruefe($s !== null && $s['neu'] === 2 && $s['linear'] && $s['migrationen'] && $s['zweig'] === 'main' && count($s['kandidaten']) === 2
        && count($s['commits']) === 2 && $s['hinweise'][0]['kurz'] === substr($V, 0, 7), 'stand: alle Felder');
    pruefe(($s['commits'][0]['betreff'] ?? '') === 'Neue Seite <script>alert(1)</script> für Übergaben', 'stand: Betreff als Text (escaped wird bei der Ausgabe)');
    pruefe(($s['commits'][1]['betreff'] ?? '') === 'Kabel kürzer', 'stand: Steuerzeichen im Betreff entfernt');
    $schreiben('stand', $standText(time(), ['quelle' => 'https://x-access-token:geheim@github.com/a/b']));
    gleich(UpdateService::stand()['quelle'], null, 'stand: Quelle mit Zugangsdaten verworfen');
    $schreiben('stand', $standText(time()) . str_repeat(str_repeat('x', 1000) . "\n", 300));
    gleich(UpdateService::stand(), null, 'stand: zu groß → null');
    $schreiben('stand', $standText(time()));

    $schreiben('verlauf', $verlaufText([
        ($T - 900) . ' ' . ($T - 800) . " - konsole root aktualisieren eingespielt - {$I} {$K1} vor-update-20261016-040312.tar.gz",
        'kaputt',
        ($T - 700) . ' ' . ($T - 690) . ' ' . str_repeat('b', 32) . " hand admin pruefen geprueft - {$K1} - -",
        ($T - 600) . ' ' . ($T - 400) . ' ' . str_repeat('c', 32) . " hand admin aktualisieren zurueckgerollt migration {$K1} - -",
    ]));
    $v = UpdateService::verlauf();
    pruefe(count($v) === 2 && $v[0]['ergebnis'] === 'zurueckgerollt' && $v[0]['dauer'] === 200 && $v[1]['sicherung'] === 'vor-update-20261016-040312.tar.gz',
        'verlauf: neueste zuerst, ohne Suchen und ohne kaputte Zeilen');
    $schreiben('protokoll', $protokollText(str_repeat('c', 32), "==> Stand prüfen\n\x1b[1;34mFarbe\x1b[0m\nRemote: https://x-access-token:ghp_PRUEFUNG0123456789abcdef@github.com/x/y\n\xff Ende\n"));
    $p = UpdateService::protokollDatei();
    pruefe($p !== null && $p['kennung'] === str_repeat('c', 32) && !str_contains($p['text'], "\x1b") && !str_contains($p['text'], 'ghp_PRUEFUNG')
        && !str_contains($p['text'], 'x-access-token:') && mb_check_encoding($p['text'], 'UTF-8') && str_contains($p['text'], 'Stand prüfen'),
        'protokoll: ohne ESC, ohne Zugangsdaten, UTF-8');

    // ------------------------------------------------------------ Zustand über die Zeit
    $eingerichtet();
    $jetzt = time();
    gleich(UpdateService::zustand($jetzt)['zustand'], 'bereit', 'Zustand: bereit');
    file_put_contents($upd . '/soll/auftrag', UpdateService::auftragText(str_repeat('d', 32), 'pruefen', 'hand', 'admin', $jetzt, null));
    UpdateService::vergessen();
    $z = UpdateService::zustand($jetzt);
    pruefe($z['zustand'] === 'angefordert' && !$z['haengt'], 'Zustand: angefordert');
    touch($upd . '/soll/auftrag', $jetzt - 200);
    UpdateService::vergessen();
    pruefe(UpdateService::zustand($jetzt)['haengt'], 'Zustand: Auftrag hängt nach 120 s');
    unlink($upd . '/soll/auftrag');
    $schreiben('lauf', $laufText(['puls' => (string) ($jetzt - 5)]));
    $z = UpdateService::zustand($jetzt);
    pruefe($z['zustand'] === 'laeuft' && !$z['keine_rueckmeldung'], 'Zustand: läuft');
    $schreiben('lauf', $laufText(['puls' => (string) ($jetzt - 100)]));
    $z = UpdateService::zustand($jetzt);
    pruefe($z['zustand'] === 'laeuft' && $z['keine_rueckmeldung'], 'Zustand: läuft, keine Rückmeldung nach 90 s');
    $schreiben('lauf', $laufText(['puls' => (string) ($jetzt - 2000)]));
    $z = UpdateService::zustand($jetzt);
    pruefe($z['zustand'] === 'fehler' && $z['verschwunden'], 'Zustand: nach 30 min ohne Puls verschwunden (wie Fehler)');
    $schreiben('lauf', $laufText(['zustand' => 'fertig', 'ergebnis' => 'eingespielt', 'ende' => (string) $jetzt]));
    gleich(UpdateService::zustand($jetzt)['zustand'], 'fertig', 'Zustand: fertig');
    $schreiben('lauf', $laufText(['zustand' => 'fehler', 'ergebnis' => 'zurueckgerollt', 'grund' => 'migration', 'ende' => (string) $jetzt]));
    gleich(UpdateService::zustand($jetzt)['zustand'], 'fehler', 'Zustand: fehler');
    $schreiben('helfer', $helferText($jetzt - 3000));
    pruefe(UpdateService::zustand($jetzt)['still'], 'Zustand: Helfer still nach 45 min');
    $schreiben('helfer', $helferText($jetzt, 'nein', 'nein'));
    $z = UpdateService::zustand($jetzt);
    pruefe($z['abgeschaltet'] && $z['automatik_root'], 'Zustand: Oberfläche und Automatik root-seitig abgeschaltet');
    $schreiben('helfer', $helferText($jetzt));
    $schreiben('lauf', null);
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . ($jetzt - 60) . "\nkennung -\n");
    pruefe(UpdateService::zustand($jetzt)['wartung'], 'Schreibsperre: frisch wirksam');
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . ($jetzt - 4000) . "\nkennung -\n");
    pruefe(!UpdateService::wartungAktiv($jetzt), 'Schreibsperre: älter als 60 min ohne Puls wirkungslos');
    $schreiben('lauf', $laufText(['puls' => (string) ($jetzt - 20)]));
    pruefe(UpdateService::wartungAktiv($jetzt), 'Schreibsperre: älter als 60 min, aber der Lauf pulst – wirksam');
    $schreiben('wartung', null);
    $schreiben('lauf', null);

    // ------------------------------------------------------------ installierter Stand ohne git-Prozess
    $gitHead('ref: refs/heads/main', ['refs/heads/main' => $I]);
    $i = UpdateService::installiert();
    pruefe($i['commit'] === $I && $i['zweig'] === 'main' && $i['betreff'] === 'Stand vorher' && $i['zeit'] === 1792100000, 'installiert: .git/HEAD + loser Ref, Betreff aus ist/stand');
    $gitHead('ref: refs/heads/main', [], "# pack-refs with: peeled fully-peeled sorted\n{$K1} refs/heads/main\n");
    $i = UpdateService::installiert();
    pruefe($i['commit'] === $K1 && $i['betreff'] === 'Kabel kürzer', 'installiert: aus packed-refs; Betreff aus der Commit-Liste');
    $gitHead($V);
    $i = UpdateService::installiert();
    pruefe($i['commit'] === $V && $i['zweig'] === null, 'installiert: abgetrennter HEAD');
    $gitHead('ref: refs/heads/../../etc/passwd');
    $schreiben('stand', null);
    gleich(UpdateService::installiert()['commit'], null, 'installiert: Ref ../../etc/passwd ergibt nichts');
    // Arbeitskopie mit .git als Datei (git worktree)
    exec('rm -rf ' . escapeshellarg($git));
    @mkdir($ordner . '/haupt.git/worktrees/x', 0755, true);
    @mkdir($ordner . '/haupt.git/refs/heads', 0755, true);
    file_put_contents($ordner . '/haupt.git/worktrees/x/HEAD', "ref: refs/heads/zweig\n");
    file_put_contents($ordner . '/haupt.git/worktrees/x/commondir', "../..\n");
    file_put_contents($ordner . '/haupt.git/refs/heads/zweig', $K1 . "\n");
    file_put_contents($git, 'gitdir: ' . $ordner . "/haupt.git/worktrees/x\n");
    UpdateService::vergessen();
    $i = UpdateService::installiert();
    pruefe($i['commit'] === $K1 && $i['zweig'] === 'zweig', 'installiert: git worktree (.git als Datei, commondir)');
    unlink($git);
    $schreiben('stand', $standText(time()));
    UpdateService::vergessen();
    gleich(UpdateService::installiert()['commit'], $I, 'installiert: ohne .git aus ist/stand');

    // ------------------------------------------------------------ Auftrag
    $eingerichtet();
    $regel = '/\A# powermanagement update-auftrag 1\nkennung [0-9a-f]{32}\naktion pruefen\nausloeser hand\nvon pruefer\nzeit [0-9]{10}\n\z/';
    $r = UpdateService::anfordern('pruefen', null, 'hand', 'pruefer');
    pruefe($r['ok'] && preg_match('/^[0-9a-f]{32}$/', (string) ($r['kennung'] ?? '')) === 1, 'Auftrag pruefen gestellt');
    $text = (string) @file_get_contents($upd . '/soll/auftrag');
    pruefe(preg_match($regel, $text) === 1 && str_contains($text, 'kennung ' . ($r['kennung'] ?? '-')), 'Auftrag: Format genau nach §4.2');
    gleich(substr(sprintf('%o', fileperms($upd . '/soll/auftrag')), -4), '0640', 'Auftrag: Modus 0640');
    gleich(array_values(array_diff(scandir($upd . '/soll'), ['.', '..'])), ['auftrag'], 'Auftrag: in soll/ nur der Auftrag (keine Temp-Datei)');
    gleich(glob($upd . '/.auftrag-*'), [], 'Auftrag: keine Temp-Datei übrig');
    $r2 = UpdateService::anfordern('pruefen', null, 'hand', 'pruefer');
    pruefe(!$r2['ok'] && str_contains($r2['message'], 'wartet schon'), 'kein zweiter offener Auftrag');
    unlink($upd . '/soll/auftrag');
    $r2 = UpdateService::anfordern('pruefen', null, 'hand', 'pruefer');
    pruefe(!$r2['ok'] && str_contains($r2['message'], 'Eben erst gesucht'), 'Suchen höchstens alle 30 s');
    touch($upd . '/.letzter', time() - 40);
    $r2 = UpdateService::anfordern('pruefen', null, 'hand', 'Max Müller <x>');
    pruefe($r2['ok'] && str_contains((string) file_get_contents($upd . '/soll/auftrag'), "\nvon MaxMllerx\n"), 'nach 30 s wieder; „von“ bereinigt');
    gleich(UpdateService::vonBereinigen(''), 'unbekannt', 'leeres „von“ → unbekannt');
    $auftragWeg();

    $r = UpdateService::anfordern('aktualisieren', $V, 'hand', 'pruefer');
    pruefe($r['ok'] && str_ends_with((string) file_get_contents($upd . '/soll/auftrag'), "ziel {$V}\n"), 'Auftrag aktualisieren auf den verfügbaren Stand');
    $auftragWeg();
    $r = UpdateService::anfordern('aktualisieren', $K1, 'hand', 'pruefer');
    pruefe($r['ok'], 'Auftrag aktualisieren auf einen Kandidaten');
    $auftragWeg();
    $r = UpdateService::anfordern('aktualisieren', $sha('fremd'), 'hand', 'pruefer');
    pruefe(!$r['ok'] && !is_file($upd . '/soll/auftrag'), 'Ziel außerhalb des Stands abgelehnt');
    $r = UpdateService::anfordern('aktualisieren', $I, 'hand', 'pruefer');
    pruefe(!$r['ok'] && str_contains($r['message'], 'schon installiert'), 'Ziel = installiert abgelehnt');
    $r = UpdateService::anfordern('aktualisieren', null, 'hand', 'pruefer');
    pruefe(!$r['ok'], 'aktualisieren ohne Ziel abgelehnt');
    $r = UpdateService::anfordern('zuruecknehmen', null, 'hand', 'pruefer');
    pruefe(!$r['ok'], 'unbekannte Aktion abgelehnt');
    foreach ([['lokal_geaendert' => 'ja'], ['linear' => 'nein'], ['zweig' => '-']] as $mehr) {
        $schreiben('stand', $standText(time(), $mehr));
        $r = UpdateService::anfordern('aktualisieren', $V, 'hand', 'pruefer');
        pruefe(!$r['ok'] && !is_file($upd . '/soll/auftrag'), 'aktualisieren abgelehnt bei ' . json_encode($mehr));
    }
    $schreiben('stand', $standText(time()));
    $schreiben('lauf', $laufText([]));
    pruefe(!UpdateService::anfordern('pruefen', null, 'hand', 'p')['ok'], 'abgelehnt, solange ein Lauf läuft');
    $schreiben('lauf', null);
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    pruefe(!UpdateService::anfordern('pruefen', null, 'hand', 'p')['ok'], 'abgelehnt während der Schreibsperre');
    $schreiben('wartung', null);
    $schreiben('helfer', $helferText(time(), 'nein'));
    pruefe(!UpdateService::anfordern('pruefen', null, 'hand', 'p')['ok'], 'abgelehnt bei OBERFLAECHE=nein');
    $schreiben('helfer', $helferText(time(), 'ja', 'ja', 'aufgegeben'));
    pruefe(!UpdateService::anfordern('pruefen', null, 'hand', 'p')['ok'], 'abgelehnt bei „offen aufgegeben“');
    $schreiben('helfer', $helferText(time(), 'ja', 'ja', 'ja'));
    pruefe(!UpdateService::anfordern('aktualisieren', $V, 'hand', 'p')['ok'], 'aktualisieren abgelehnt, solange ein unterbrochenes Update offen ist');
    $schreiben('helfer', $helferText(time() - 3000));
    pruefe(!UpdateService::anfordern('pruefen', null, 'hand', 'p')['ok'], 'abgelehnt bei stillem Helfer');
    $schreiben('helfer', $helferText(time()));
    Config::set('app.demo', true);
    pruefe(!UpdateService::anfordern('pruefen', null, 'hand', 'p')['ok'], 'abgelehnt im Demo-Modus');
    Config::set('app.demo', false);
    $auftragWeg();

    // Betrieb: Aufbau oder Schaltvorgang – nur mit „trotzdem“
    $site = Database::insert('sites', ['name' => $kennung . ' Halle', 'created_at' => gmdate('c')]);
    $aufbau = Database::insert('aufbauten', ['site_id' => $site, 'name' => $kennung . ' Messe', 'status' => 'aufbau', 'gestartet_am' => gmdate('c'), 'created_at' => gmdate('c')]);
    $r = UpdateService::anfordern('aktualisieren', $V, 'hand', 'pruefer');
    pruefe(!$r['ok'] && !empty($r['bestaetigen']) && ($r['hindernisse'][0]['art'] ?? '') === 'aufbau' && !is_file($upd . '/soll/auftrag'),
        'Aufbau läuft: Rückfrage statt Auftrag');
    pruefe(str_contains($r['hindernisse'][0]['text'] ?? '', $kennung . ' Messe') && str_contains($r['hindernisse'][0]['text'] ?? '', 'Halle'), 'Hindernis nennt Aufbau und Standort');
    pruefe(UpdateService::anfordern('pruefen', null, 'hand', 'pruefer')['ok'], 'Suchen geht auch während eines Aufbaus');
    $auftragWeg();
    $r = UpdateService::anfordern('aktualisieren', $V, 'hand', 'pruefer', true);
    pruefe($r['ok'] && ($r['hindernisse'][0]['art'] ?? '') === 'aufbau', 'Aufbau läuft: mit „trotzdem“ gestellt, Hindernisse für das Audit');
    $auftragWeg();
    Database::execute("UPDATE aufbauten SET status = 'betrieb' WHERE id = ?", [$aufbau]);
    gleich(array_column(UpdateAutomatik::hindernisse(time()), 'art'), ['aufbau'], 'Hindernis: Betrieb zählt wie Aufbau');
    Database::execute("UPDATE aufbauten SET status = 'beendet' WHERE id = ?", [$aufbau]);
    gleich(UpdateAutomatik::hindernisse(time()), [], 'Hindernis: beendeter Aufbau zählt nicht');
    Database::insert('schaltvorgaenge', ['geraet' => $kennung, 'befehl' => 'aus', 'ergebnis' => 'ok', 'username' => 'p', 'created_at' => gmdate('c', time() - 600)]);
    gleich(array_column(UpdateAutomatik::hindernisse(time()), 'art'), ['geschaltet'], 'Hindernis: geschaltet vor 10 min');
    Database::execute('UPDATE schaltvorgaenge SET created_at = ? WHERE geraet = ?', [gmdate('c', time() - 2400), $kennung]);
    gleich(UpdateAutomatik::hindernisse(time()), [], 'Hindernis: geschaltet vor 40 min zählt nicht');

    // Besitzer: storage/update gehört jemand anderem (root ruft bin/power auf)
    if ($alsRoot) {
        chown($upd, 65534);
        clearstatcache();
        $geworfen = null;
        try {
            UpdateService::anfordern('pruefen', null, 'hand', 'pruefer');
        } catch (\RuntimeException $e) {
            $geworfen = $e->getMessage();
        }
        pruefe($geworfen !== null && str_contains($geworfen, 'runuser -u www-data'), 'fremder Besitzer: Ausnahme mit Hinweis auf runuser');
        pruefe(!is_file($upd . '/soll/auftrag'), 'fremder Besitzer: kein Auftrag');
        [$rc, $aus] = $power(['update:suchen']);
        pruefe($rc === 1 && str_contains($aus, 'runuser -u'), 'als root: update:suchen abgelehnt (' . trim($aus) . ')');
        [$rc, $aus] = $power(['update:stand']);
        pruefe($rc === 0 && str_contains($aus, 'Update-Funktion: eingerichtet'), 'als root: update:stand liest');
        chown($upd, 0);
        clearstatcache();
    } else {
        fwrite(STDOUT, "  (Besitzerprüfung und „als root abgelehnt“ übersprungen – nur als root)\n");
    }

    // auftragText: was der Helfer ablehnte, baut PHP gar nicht erst
    foreach ([
        'wert'     => [str_repeat('A', 32), 'pruefen', 'hand', 'p', time(), null],
        'verboten' => [str_repeat('a', 32), 'pruefen', 'hand', 'p', time(), $V],
        'fehlt'    => [str_repeat('a', 32), 'aktualisieren', 'hand', 'p', time(), null],
    ] as $code => $args) {
        $kam = null;
        try {
            UpdateService::auftragText(...$args);
        } catch (\InvalidArgumentException $e) {
            $kam = $e->getMessage();
        }
        gleich($kam, $code, 'auftragText lehnt ab: ' . $code);
    }

    // ------------------------------------------------------------ Abzeichen
    $eingerichtet();
    $n = UpdateService::navHinweis();
    pruefe(($n['stufe'] ?? '') === 'primary' && ($n['zahl'] ?? 0) === 2, 'Abzeichen: Zahl der Änderungen');
    $schreiben('verlauf', $verlaufText([(time() - 600) . ' ' . (time() - 500) . ' ' . str_repeat('c', 32) . " hand admin aktualisieren zurueckgerollt migration {$I} - -"]));
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'bad', 'Abzeichen: zurückgerollt in den letzten 7 Tagen → rot');
    $schreiben('verlauf', $verlaufText([
        (time() - 600) . ' ' . (time() - 500) . ' ' . str_repeat('c', 32) . " hand admin aktualisieren zurueckgerollt migration {$I} - -",
        (time() - 300) . ' ' . (time() - 200) . " - konsole root aktualisieren eingespielt - {$I} {$K1} -",
    ]));
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'primary', 'Abzeichen: nach späterem Erfolg nicht mehr rot');
    $schreiben('verlauf', $verlaufText([(time() - 9 * 86400) . ' ' . (time() - 9 * 86400 + 60) . ' ' . str_repeat('c', 32) . " hand admin aktualisieren zurueckgerollt migration {$I} - -"]));
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'primary', 'Abzeichen: Zurückrollen älter als 7 Tage nicht mehr rot');
    $schreiben('helfer', $helferText(time(), 'ja', 'ja', 'aufgegeben'));
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'bad', 'Abzeichen: „offen aufgegeben“ → rot');
    $schreiben('helfer', $helferText(time() - 3000));
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'warn', 'Abzeichen: Dienst still → gelb');
    $schreiben('helfer', $helferText(time()));
    $schreiben('stand', $standText(time(), ['abruf' => 'abruf', 'neu' => '0', 'verfuegbar' => '- -']));
    gleich(UpdateService::navHinweis(), null, 'Abzeichen: eine fehlgeschlagene Suche ergibt keines');
    Config::set('app.demo', true);
    $schreiben('stand', $standText(time()));
    gleich(UpdateService::navHinweis(), null, 'Abzeichen: nie im Demo-Modus');
    Config::set('app.demo', false);
    $schreiben('verlauf', null);

    // ------------------------------------------------------------ Audit idempotent
    $eingerichtet();
    $audit = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.update.ergebnis'");
    $zeilen = [
        (time() - 900) . ' ' . (time() - 800) . " - konsole root aktualisieren eingespielt - {$I} {$K1} -",
        (time() - 600) . ' ' . (time() - 400) . ' ' . str_repeat('c', 32) . " hand admin aktualisieren zurueckgerollt migration {$K1} - -",
    ];
    $schreiben('verlauf', $verlaufText($zeilen));
    $vorher = $audit();
    gleich(UpdateService::vermerken(), 2, 'vermerken: zwei Verlaufszeilen ins Audit');
    gleich(UpdateService::vermerken(), 0, 'vermerken: zweimal ergibt nichts Neues');
    gleich($audit() - $vorher, 2, 'vermerken: genau zwei Einträge');
    $eintrag = Database::first("SELECT * FROM audit_log WHERE action = 'system.update.ergebnis' ORDER BY id DESC LIMIT 1");
    $details = json_decode((string) ($eintrag['details'] ?? ''), true);
    pruefe(($eintrag['username'] ?? '') === 'system' && ($details['ergebnis'] ?? '') === 'zurueckgerollt' && ($details['ausloeser'] ?? '') === 'hand'
        && ($details['von'] ?? '') === 'admin' && ($details['alt7'] ?? '') === substr($K1, 0, 7), 'vermerken: als system, mit Auslöser, Ergebnis, alt7');
    $zeilen[] = (time() - 100) . ' ' . (time() - 50) . " - konsole root aktualisieren aktuell - {$I} - -";
    $schreiben('verlauf', $verlaufText($zeilen));
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    gleich(UpdateService::vermerken(), 0, 'vermerken: während der Schreibsperre nichts');
    $schreiben('wartung', null);
    gleich(UpdateService::vermerken(), 1, 'vermerken: danach die neue Zeile');

    // ------------------------------------------------------------ Kommandozeile
    $eingerichtet();
    [$rc, $aus] = $power(['update:stand']);
    pruefe($rc === 0 && preg_match('/^Update-Funktion: eingerichtet/m', $aus) === 1 && str_contains($aus, 'Installiert:     ' . substr($I, 0, 7))
        && str_contains($aus, '2 Änderungen bis ' . substr($V, 0, 7)), 'update:stand: eingerichtet, installiert, verfügbar');
    [$rc, $aus] = $power(['update:stand'], 'leer.php');
    pruefe($rc === 0 && str_contains($aus, 'Update-Funktion: nicht eingerichtet'), 'update:stand: nicht eingerichtet');
    [$rc, $aus] = $power(['pruefen'], 'leer.php');
    pruefe(str_contains($aus, 'Update:     nicht eingerichtet'), 'bin/power pruefen: Zeile „Update:“');
    [$rc, $aus] = $power(['update:suchen']);
    pruefe($rc === 0 && is_file($upd . '/soll/auftrag') && str_contains((string) file_get_contents($upd . '/soll/auftrag'), "ausloeser konsole\n"),
        'update:suchen: Auftrag mit Auslöser konsole');
    $auftragWeg();

    /**
     * Den Helfer spielen: auf den Auftrag warten, dann ist/lauf schreiben wie
     * deploy/update-helfer.sh. $folge: nacheinander [sekunden, felder] (ein
     * Lauf mit der Kennung des Auftrags) oder eine Funktion (bekommt die Kennung).
     */
    $helferSpielen = static function (array $folge) use ($upd, $schreiben, $laufText): ?string {
        $bis = microtime(true) + 20;
        while (!is_file($upd . '/soll/auftrag') && microtime(true) < $bis) {
            usleep(50_000);
        }
        $text = (string) @file_get_contents($upd . '/soll/auftrag');
        @unlink($upd . '/soll/auftrag');
        if (preg_match('/^kennung ([0-9a-f]{32})$/m', $text, $m) !== 1) {
            return null;
        }
        $beginn = time();
        foreach ($folge as $schritt) {
            if (is_callable($schritt)) {
                $schritt($m[1]);
                continue;
            }
            [$warten, $felder] = $schritt;
            usleep((int) ($warten * 1_000_000));
            $schreiben('lauf', $laufText($felder + ['kennung' => $m[1], 'beginn' => (string) $beginn, 'puls' => (string) time()]));
        }
        return $m[1];
    };
    $hintergrundPower = static function (array $argumente) use ($wurzel, $ordner): array {
        $aus = $ordner . '/power-' . bin2hex(random_bytes(3)) . '.txt';
        $p = proc_open(array_merge(['php', $wurzel . '/bin/power'], $argumente), [0 => ['file', '/dev/null', 'r'], 1 => ['file', $aus, 'w'], 2 => ['file', $aus, 'a']],
            $rohre, $wurzel, ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php'] + getenv());
        return ['p' => $p, 'aus' => $aus];
    };
    $ende = static function (array $h): array {
        $bis = microtime(true) + 30;
        while (($s = proc_get_status($h['p']))['running'] && microtime(true) < $bis) {
            usleep(100_000);
        }
        if ($s['running']) {
            proc_terminate($h['p'], 9);
        }
        proc_close($h['p']);
        return [$s['running'] ? -1 : $s['exitcode'], (string) @file_get_contents($h['aus'])];
    };

    // update:starten --warten: Fortschritt, Ergebnis, Exit 0
    $schreiben('stand', $standText(time()));
    $h = $hintergrundPower(['update:starten', '--warten']);
    $k = $helferSpielen([
        [0.3, ['schritt' => '1 12 Stand prüfen']],
        [1.2, ['schritt' => '6 12 Migrationen ausführen']],
        static fn () => $schreiben('stand', $standText(time() + 1, ['neu' => '0'])),
        [1.2, ['zustand' => 'fertig', 'ergebnis' => 'eingespielt', 'alt' => $I, 'neu' => $V, 'ende' => (string) time(), 'schritt' => '12 12 Fertig']],
    ]);
    [$rc, $aus] = $ende($h);
    pruefe($k !== null, 'update:starten: Auftrag gestellt');
    pruefe($rc === 0 && str_contains($aus, '[6/12] Migrationen ausführen') && str_contains($aus, 'Ergebnis: Eingespielt'), 'update:starten --warten: Schritte und Ergebnis, Exit 0 (' . $rc . ')');
    pruefe(preg_match('/^\d\d:\d\d:\d\d  \[1\/12\] Stand prüfen$/m', $aus) === 1, 'update:starten --warten: Schritte mit Uhrzeit');

    // zurückgerollt: Exit 2 und die letzten Protokollzeilen
    $schreiben('stand', $standText(time()));
    $schreiben('lauf', null);
    $h = $hintergrundPower(['update:starten', '--warten']);
    $k = $helferSpielen([
        [0.3, ['schritt' => '6 12 Migrationen ausführen']],
        static function (string $k) use ($schreiben, $protokollText, $standText): void {
            $schreiben('protokoll', $protokollText($k, "==> Migrationen ausführen\nFehler: SELEKT kaputt\n==> Ergebnis: zurueckgerollt (migration)\n"));
            $schreiben('stand', $standText(time() + 1));
        },
        [0.2, ['zustand' => 'fehler', 'ergebnis' => 'zurueckgerollt', 'grund' => 'migration', 'alt' => $I, 'ende' => (string) time()]],
    ]);
    [$rc, $aus] = $ende($h);
    pruefe($rc === 2 && str_contains($aus, 'Zurückgerollt') && str_contains($aus, '| Fehler: SELEKT kaputt'), 'update:starten --warten: zurückgerollt → Exit 2 mit Protokoll (' . $rc . ')');

    // Das Update stirbt (keine Ergebnisdatei, Marke steht): der Helfer rollt gleich unter eigener Kennung zurück
    $schreiben('stand', $standText(time()));
    $schreiben('lauf', null);
    $h = $hintergrundPower(['update:starten', '--warten']);
    $helferSpielen([
        [0.3, ['schritt' => '8 12 Dienste und Konfiguration abgleichen']],
        [1.0, ['zustand' => 'fehler', 'ergebnis' => 'abgebrochen', 'grund' => 'unterbrochen', 'ende' => (string) time()]],
        static function () use ($schreiben, $laufText, $helferText, $standText, $I): void {
            $schreiben('helfer', $helferText(time(), 'ja', 'ja', 'ja'));
            usleep(800_000);
            $w = str_repeat('e', 32);
            $schreiben('lauf', $laufText(['kennung' => $w, 'aktion' => 'wiederaufnehmen', 'ausloeser' => 'system', 'von' => 'system', 'schritt' => '0 12 Zurückrollen', 'beginn' => (string) time()]));
            usleep(1_200_000);
            $schreiben('stand', $standText(time() + 1));
            $schreiben('lauf', $laufText(['kennung' => $w, 'aktion' => 'wiederaufnehmen', 'ausloeser' => 'system', 'von' => 'system', 'zustand' => 'fehler',
                'ergebnis' => 'zurueckgerollt', 'grund' => 'unterbrochen', 'alt' => $I, 'ende' => (string) time()]));
            $schreiben('helfer', $helferText(time()));
        },
    ]);
    [$rc, $aus] = $ende($h);
    pruefe($rc === 2 && str_contains($aus, 'rollt zurück') && str_contains($aus, 'unterbrochen'), 'update:starten --warten folgt dem Wiederanlauf: Exit 2 (' . $rc . ')');

    // abgelehnt: Exit 1
    $schreiben('stand', $standText(time()));
    $schreiben('lauf', null);
    $h = $hintergrundPower(['update:starten', '--warten']);
    $helferSpielen([static fn () => $schreiben('stand', $standText(time() + 1)),
        [0.2, ['zustand' => 'fehler', 'ergebnis' => 'abgelehnt', 'grund' => 'zu_oft', 'ende' => (string) time()]]]);
    [$rc, $aus] = $ende($h);
    pruefe($rc === 1 && str_contains($aus, 'Abgelehnt') && str_contains($aus, 'Zu kurz nach dem letzten Update'), 'update:starten --warten: abgelehnt → Exit 1 (' . $rc . ')');

    // Betrieb: ohne --trotz-betrieb kein Auftrag
    $schreiben('lauf', null);
    $schreiben('stand', $standText(time()));
    Database::execute("UPDATE aufbauten SET status = 'aufbau' WHERE id = ?", [$aufbau]);
    [$rc, $aus] = $power(['update:starten']);
    pruefe($rc === 1 && str_contains($aus, '--trotz-betrieb') && str_contains($aus, $kennung . ' Messe') && !is_file($upd . '/soll/auftrag'),
        'update:starten während eines Aufbaus: abgelehnt mit Hinweis');
    [$rc, $aus] = $power(['update:starten', '--trotz-betrieb']);
    pruefe($rc === 0 && is_file($upd . '/soll/auftrag'), 'update:starten --trotz-betrieb: Auftrag gestellt');
    $auftragWeg();
    Database::execute("UPDATE aufbauten SET status = 'beendet' WHERE id = ?", [$aufbau]);
    // Stand zu alt: erst suchen
    $schreiben('stand', $standText(time() - 600, ['abruf_zeit' => (string) (time() - 600)]));
    $h = $hintergrundPower(['update:starten']);
    $k = $helferSpielen([static fn () => $schreiben('stand', $standText(time() + 1)),
        [0.2, ['aktion' => 'pruefen', 'zustand' => 'fertig', 'ergebnis' => 'geprueft', 'ende' => (string) time(), 'schritt' => '0 12 -']]]);
    $bis = microtime(true) + 15;
    while (!is_file($upd . '/soll/auftrag') && microtime(true) < $bis) {
        usleep(50_000);
    }
    $zweiter = (string) @file_get_contents($upd . '/soll/auftrag');
    [$rc, $aus] = $ende($h);
    pruefe($k !== null && str_contains($aus, 'Erst nach neuen Ständen suchen') && str_contains($zweiter, "aktion aktualisieren\n") && str_contains($zweiter, "ziel {$V}\n"),
        'update:starten mit altem Stand: erst suchen, dann aktualisieren auf den verfügbaren Stand');
    $auftragWeg();

    // ------------------------------------------------------------ HTTP
    $port = freierPort();
    hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'],
        ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '8']);
    $demoPort = freierPort();
    hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-S', '127.0.0.1:' . $demoPort, '-t', $wurzel . '/public'],
        ['POWERMANAGEMENT_CONFIG' => $ordner . '/demo.php']);
    // Eine zweite Instanz liest storage/update über eine Attrappe, die ist/lauf langsam öffnet.
    file_put_contents($ordner . '/verzoegerung', '0');
    file_put_contents($ordner . '/attrappe.php', '<?php
final class LangsamAttrappe
{
    public $context;
    private $h;
    private static function pfad(string $url): string { return substr($url, strlen("langsam://")); }
    private static function bremsen(string $pfad): void
    {
        if (basename($pfad) === "lauf") {
            usleep((int) ((float) @file_get_contents(' . var_export($ordner . '/verzoegerung', true) . ') * 1000000));
        }
    }
    public function url_stat(string $url, int $flags): array|false
    {
        $p = self::pfad($url);
        return ($flags & STREAM_URL_STAT_LINK) ? @lstat($p) : @stat($p);
    }
    public function stream_open(string $url, string $mode, int $options, ?string &$opened): bool
    {
        $p = self::pfad($url);
        self::bremsen($p);
        $this->h = @fopen($p, $mode);
        return $this->h !== false;
    }
    public function stream_read(int $n): string|false { return fread($this->h, $n); }
    public function stream_write(string $d): int|false { return fwrite($this->h, $d); }
    public function stream_eof(): bool { return feof($this->h); }
    public function stream_stat(): array|false { return fstat($this->h); }
    public function stream_seek(int $o, int $w): bool { return fseek($this->h, $o, $w) === 0; }
    public function stream_tell(): int|false { return ftell($this->h); }
    public function stream_close(): void { fclose($this->h); }
    public function stream_lock(int $op): bool { return flock($this->h, $op); }
    public function stream_set_option(int $o, int $a, ?int $b): bool { return false; }
}
stream_wrapper_register("langsam", LangsamAttrappe::class);
');
    $langsamPort = freierPort();
    hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'auto_prepend_file=' . $ordner . '/attrappe.php',
        '-S', '127.0.0.1:' . $langsamPort, '-t', $wurzel . '/public'],
        ['POWERMANAGEMENT_CONFIG' => $ordner . '/langsam.php', 'PHP_CLI_SERVER_WORKERS' => '8']);
    for ($i = 0; $i < 50 && !(erreichbar('127.0.0.1', $port) && erreichbar('127.0.0.1', $demoPort) && erreichbar('127.0.0.1', $langsamPort)); $i++) {
        usleep(100_000);
    }
    if (!erreichbar('127.0.0.1', $port)) {
        throw new \RuntimeException('eingebauter PHP-Server startet nicht');
    }

    /** Ein Aufruf mit Cookie-Dose; folgt keinen Weiterleitungen. */
    $aufruf = static function (int $port, string $dose, string $methode, string $pfad, array $felder = [], array $kopf = []): array {
        $c = curl_init('http://127.0.0.1:' . $port . $pfad);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $dose, CURLOPT_COOKIEFILE => $dose,
            CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $kopf]);
        if ($methode === 'POST') {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
        }
        $roh = (string) curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $laenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
        curl_close($c);
        $kopfText = substr($roh, 0, $laenge);
        preg_match('/^Location:\s*(\S+)/mi', $kopfText, $m);
        return ['status' => $status, 'kopf' => $kopfText, 'text' => substr($roh, $laenge), 'ziel' => $m[1] ?? null];
    };
    $token = static fn (string $html): string => preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    $anmelden = static function (int $port, string $name) use ($aufruf, $token, $ordner, $kennwort): array {
        $dose = $ordner . '/dose-' . $name . '-' . $port;
        $seite = $aufruf($port, $dose, 'GET', '/login');
        $r = $aufruf($port, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => $token($seite['text'])]);
        pruefe($r['status'] === 302 && $r['ziel'] !== '/login', 'Anmeldung ' . $name);
        return ['dose' => $dose, 'token' => $token($aufruf($port, $dose, 'GET', '/profil')['text'])];
    };
    $phpMeldung = static fn (string $text): ?string => preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:.*?(?=<br|$)/s', $text, $m) ? strip_tags($m[0]) : null;

    $eingerichtet();
    $schreiben('protokoll', $protokollText(str_repeat('c', 32), "==> Stand prüfen\nRemote: https://x-access-token:ghp_PRUEFUNG0123456789abcdef@github.com/x/y\n"));
    $schreiben('verlauf', $verlaufText([(time() - 900) . ' ' . (time() - 800) . " - konsole root aktualisieren eingespielt - {$K1} {$I} -"]));
    $a = $anmelden($port, $kennung . '-admin');
    $t = $anmelden($port, $kennung . '-technik');
    $kd = $anmelden($port, $kennung . '-kunde');

    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe($r['status'] === 200 && $phpMeldung($r['text']) === null, 'Seite: 200 ohne PHP-Meldung (' . $r['status'] . ' ' . (string) $phpMeldung($r['text']) . ')');
    pruefe(str_contains($r['text'], substr($I, 0, 7)) && str_contains($r['text'], 'Jetzt suchen') && str_contains($r['text'], 'Jetzt aktualisieren'), 'Seite: Stand, Suchen, Aktualisieren');
    pruefe(str_contains($r['text'], 'Neue Seite &lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($r['text'], '<script>alert(1)'), 'Seite: Betreff escaped');
    pruefe(str_contains($r['text'], 'https://github.com/agrotevent/powermanagement/commit/' . $V), 'Seite: Link zum Commit aus geprüften Teilen');
    pruefe(str_contains($r['text'], 'data-update-seite') && str_contains($r['text'], 'data-installiert="' . $I . '"') && str_contains($r['text'], '/assets/js/update.js'), 'Seite: Wurzel für update.js');
    pruefe(str_contains($r['text'], 'value="' . $V . '"'), 'Seite: verstecktes Ziel = verfügbarer Stand');
    pruefe(str_contains($r['text'], 'Nach dem Update einmal neu anmelden'), 'Seite: Hinweis aus dem Trailer');
    pruefe(str_contains($r['text'], 'class="nav-hinweis primary"') && str_contains($r['text'], 'class="update-zeichen primary"'), 'Abzeichen in Navigation und Kopfleiste für Administration');
    pruefe(!str_contains($r['text'], 'ghp_PRUEFUNG'), 'Seite: kein Token aus dem Protokoll');
    $audit = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.update.ergebnis'");
    $aufruf($port, $a['dose'], 'GET', '/system/update');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.update.ergebnis'"), $audit, 'Seite: vermerken ist idempotent');
    $r = $aufruf($port, $t['dose'], 'GET', '/dashboard');
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'nav-hinweis') && !str_contains($r['text'], 'update-zeichen'), 'kein Abzeichen ohne system.update');

    $r = $aufruf($port, $a['dose'], 'GET', '/system/update/stand', [], ['Accept: application/json']);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 200 && ($j['format'] ?? 0) === 1 && ($j['zustand'] ?? '') === 'bereit' && ($j['installiert'] ?? '') === $I, 'stand: 200 JSON, format 1');
    pruefe(stripos($r['kopf'], 'Cache-Control: no-store') !== false, 'stand: Cache-Control no-store');
    pruefe(!str_contains($r['text'], '/etc/') && !str_contains($r['text'], 'ghp_PRUEFUNG') && !str_contains($r['text'], 'x-access-token:'), 'stand: ohne /etc/ und ohne Token');
    pruefe(isset($j['protokoll']['zeilen']), 'stand: Protokollzeilen');
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update/stand?stand=' . rawurlencode((string) ($j['protokoll']['stand'] ?? '')), [], ['Accept: application/json']);
    $j2 = json_decode($r['text'], true);
    pruefe(isset($j2['protokoll']) && !isset($j2['protokoll']['zeilen']), 'stand: ohne Zeilen, wenn sich nichts geändert hat');
    $schreiben('lauf', $laufText(['kennung' => str_repeat('f', 32)]));
    $j = json_decode($aufruf($port, $a['dose'], 'GET', '/system/update/stand', [], ['Accept: application/json'])['text'], true);
    pruefe(($j['zustand'] ?? '') === 'laeuft' && ($j['lauf']['schritt'] ?? 0) === 6 && ($j['lauf']['schritt_name'] ?? '') === 'Migrationen ausführen'
        && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) ($j['lauf']['beginn'] ?? '')) === 1, 'stand: laufendes Update mit Schritt und Beginn (ISO, UTC)');
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(str_contains($r['text'], '<noscript><meta http-equiv="refresh" content="5"></noscript>') && str_contains($r['text'], 'disabled'), 'Seite: läuft – ohne JS alle 5 s neu, Knöpfe aus');
    $schreiben('lauf', null);

    // Rechte: Kunde und Technik nicht; ohne Token kein Auftrag
    foreach (['Technik' => $t, 'Kunde' => $kd] as $wer => $s) {
        foreach (['/system/update', '/system/update/stand'] as $pfad) {
            gleich($aufruf($port, $s['dose'], 'GET', $pfad)['status'], 403, $wer . ': GET ' . $pfad . ' → 403');
        }
        foreach (['/system/update/suchen', '/system/update/starten'] as $pfad) {
            gleich($aufruf($port, $s['dose'], 'POST', $pfad, ['_token' => $s['token'], 'ziel' => $V])['status'], 403, $wer . ': POST ' . $pfad . ' → 403');
        }
    }
    pruefe(!is_file($upd . '/soll/auftrag'), 'ohne Recht kein Auftrag');
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/suchen', []);
    pruefe($r['status'] === 302 && !is_file($upd . '/soll/auftrag'), 'ohne Token kein Auftrag');

    // Suchen und Starten über die Formulare
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/suchen', ['_token' => $a['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/system/update#fortschritt' && is_file($upd . '/soll/auftrag'), 'Suchen: Auftrag, weiter zum Fortschritt');
    pruefe((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.update.anfordern' AND username = ?", [$kennung . '-admin']) === 1, 'Suchen: Audit system.update.anfordern');
    $j = json_decode($aufruf($port, $a['dose'], 'GET', '/system/update/stand', [], ['Accept: application/json'])['text'], true);
    gleich($j['zustand'] ?? '', 'angefordert', 'stand: angefordert');
    $auftragWeg();
    Database::execute("UPDATE aufbauten SET status = 'aufbau' WHERE id = ?", [$aufbau]);
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/starten', ['_token' => $a['token'], 'ziel' => $V]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/system/update#aktualisieren' && !is_file($upd . '/soll/auftrag'), 'Starten während eines Aufbaus: Rückfrage');
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(str_contains($r['text'], 'Trotzdem jetzt aktualisieren') && str_contains($r['text'], $kennung . ' Messe'), 'Seite: Hindernis und „Trotzdem jetzt aktualisieren“');
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/starten', ['_token' => $a['token'], 'ziel' => $V, 'trotz_betrieb' => '1']);
    pruefe($r['status'] === 302 && str_contains((string) @file_get_contents($upd . '/soll/auftrag'), "ziel {$V}\n"), 'Starten mit „trotzdem“: Auftrag');
    $det = json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'system.update.anfordern' ORDER BY id DESC LIMIT 1"), true);
    pruefe(($det['trotz_betrieb'] ?? false) === true && ($det['hindernisse'] ?? []) === ['aufbau'] && ($det['ziel7'] ?? '') === substr($V, 0, 7), 'Starten: Audit mit trotz_betrieb und Hindernissen');
    $auftragWeg();
    Database::execute("UPDATE aufbauten SET status = 'beendet' WHERE id = ?", [$aufbau]);
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/starten', ['_token' => $a['token'], 'ziel' => 'kaputt']);
    pruefe($r['status'] === 302 && !is_file($upd . '/soll/auftrag'), 'Starten mit ungültigem Ziel: kein Auftrag');

    // Schreibsperre
    $untertitel = Settings::get('app.subtitle');
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    $r = $aufruf($port, $a['dose'], 'POST', '/system/einstellungen/speichern', ['_token' => $a['token'], 'app__subtitle' => 'Gesperrt ' . $kennung], ['Accept: application/json']);
    $j = json_decode(substr($r['text'], 0), true);
    pruefe($r['status'] === 503 && ($j['wartung'] ?? false) === true && preg_match('/^Retry-After: 30\r?$/mi', $r['kopf']) === 1, 'Schreibsperre: JSON → 503 mit Retry-After 30');
    Settings::vergessen();
    gleich(Settings::get('app.subtitle'), $untertitel, 'Schreibsperre: Einstellung unverändert');
    $r = $aufruf($port, $a['dose'], 'POST', '/system/einstellungen/speichern', ['_token' => $a['token'], 'app__subtitle' => 'Gesperrt ' . $kennung], ['X-Requested-With: XMLHttpRequest']);
    pruefe($r['status'] === 302, 'Schreibsperre: Formular (X-Requested-With, ohne Accept) → Weiterleitung');
    pruefe(str_contains($aufruf($port, $a['dose'], 'GET', '/dashboard')['text'], 'Gerade läuft ein Update'), 'Schreibsperre: Meldung nach der Weiterleitung');
    Settings::vergessen();
    gleich(Settings::get('app.subtitle'), $untertitel, 'Schreibsperre: auch über das Formular unverändert');
    gleich($aufruf($port, $a['dose'], 'GET', '/system/einstellungen')['status'], 200, 'Schreibsperre: Lesen geht');
    $leer = $ordner . '/dose-leer';
    $seite = $aufruf($port, $leer, 'GET', '/login');
    $r = $aufruf($port, $leer, 'POST', '/login', ['username' => $kennung . '-kunde', 'password' => $kennwort, '_token' => $token($seite['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] !== '/login', 'Schreibsperre: Anmelden geht');
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . (time() - 4000) . "\nkennung -\n");
    $r = $aufruf($port, $a['dose'], 'POST', '/system/einstellungen/speichern', ['_token' => $a['token'], 'app__subtitle' => 'Frei ' . $kennung], ['Accept: application/json']);
    Settings::vergessen();
    pruefe($r['status'] === 302 && Settings::get('app.subtitle') === 'Frei ' . $kennung, 'Schreibsperre älter als 60 min ohne Puls: wirkungslos');
    $schreiben('lauf', $laufText(['puls' => (string) time()]));
    $r = $aufruf($port, $a['dose'], 'POST', '/system/einstellungen/speichern', ['_token' => $a['token'], 'app__subtitle' => 'Wieder ' . $kennung], ['Accept: application/json']);
    pruefe($r['status'] === 503, 'Schreibsperre älter als 60 min, Lauf pulst: wirksam');
    $schreiben('wartung', null);
    $schreiben('lauf', null);
    $untertitel === null ? Settings::forget('app.subtitle') : Settings::put('app.subtitle', (string) $untertitel);

    // Demo-Modus: Seite mit Hinweis, kein Abzeichen
    $d = $anmelden($demoPort, $kennung . '-admin');
    $r = $aufruf($demoPort, $d['dose'], 'GET', '/system/update');
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Demo-Modus') && $phpMeldung($r['text']) === null, 'Demo: Seite mit „Demo-Modus“');
    pruefe(!str_contains($r['text'], 'nav-hinweis') && !str_contains($r['text'], 'update-zeichen'), 'Demo: kein Abzeichen');

    // Status-Abfrage hält die Sitzung nicht: ein POST desselben Benutzers wartet nicht
    if (erreichbar('127.0.0.1', $langsamPort)) {
        $l = $anmelden($langsamPort, $kennung . '-admin');
        $schreiben('lauf', $laufText(['kennung' => str_repeat('9', 32)]));
        file_put_contents($ordner . '/verzoegerung', '3');
        $messen = static function (string $langsamerPfad) use ($langsamPort, $l): array {
            $mehr = curl_multi_init();
            $g = curl_init('http://127.0.0.1:' . $langsamPort . $langsamerPfad);
            curl_setopt_array($g, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $l['dose'], CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            curl_multi_add_handle($mehr, $g);
            $bis = microtime(true) + 0.6;
            do {
                curl_multi_exec($mehr, $laufend);
                curl_multi_select($mehr, 0.05);
            } while (microtime(true) < $bis);
            $start = microtime(true);
            $c = curl_init('http://127.0.0.1:' . $langsamPort . '/auswertung/leeren');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $l['dose'], CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['_token' => $l['token']])]);
            curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_close($c);
            $dauer = microtime(true) - $start;
            do {
                curl_multi_exec($mehr, $laufend);
                curl_multi_select($mehr, 0.1);
            } while ($laufend > 0);
            $antwort = ['status' => (int) curl_getinfo($g, CURLINFO_RESPONSE_CODE), 'json' => json_decode((string) curl_multi_getcontent($g), true)];
            curl_multi_remove_handle($mehr, $g);
            curl_close($g);
            curl_multi_close($mehr);
            return ['dauer' => $dauer, 'status' => $status, 'langsam' => $antwort];
        };
        $m = $messen('/system/update/stand');
        pruefe($m['status'] === 302 && $m['dauer'] < 2.0, sprintf('stand gibt die Sitzung frei: POST wartet nicht (%.1f s)', $m['dauer']));
        pruefe($m['langsam']['status'] === 200 && ($m['langsam']['json']['zustand'] ?? '') === 'laeuft', 'stand liefert trotzdem seine Antwort');
        // Gegenprobe: die Seite selbst hält die Sitzung, solange sie liest – dann wartet der POST.
        $m = $messen('/system/update');
        pruefe($m['dauer'] >= 2.0, sprintf('Gegenprobe: die Seite hält die Sitzung, der POST wartet (%.1f s)', $m['dauer']));
        file_put_contents($ordner . '/verzoegerung', '0');
        $schreiben('lauf', null);
    } else {
        pruefe(false, 'zweiter PHP-Server (Attrappe) startet');
    }
} finally {
    restore_error_handler();
    Database::execute('DELETE FROM audit_log WHERE username LIKE ?', [$kennung . '%']);
}

gleich($meldungen, [], 'keine PHP-Meldung im Prüfskript');

// ------------------------------------------------------------ update.js
$js = (string) file_get_contents($wurzel . '/public/assets/js/update.js');
foreach (['innerHTML', 'insertAdjacentHTML', 'outerHTML', 'eval(', 'document.write'] as $verboten) {
    pruefe(!str_contains($js, $verboten), 'update.js ohne ' . $verboten);
}

ende('update');
