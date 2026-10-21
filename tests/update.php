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
 * Automatik (Einstellungen, Fenster an normalen Tagen, über Mitternacht und
 * an den Tagen der Zeitumstellung, Versatz, Karenz, gescheiterte Ziele,
 * Aufbau und Schaltvorgänge, Pause, einmal je Tag, Zeitplan mit Auftrag,
 * Einstellungen und Audit, stiller Zeitplan, lange verschoben, Formular und
 * Schreibsperre); Kommandozeile (stand, suchen, starten --warten mit
 * Fortschritt, Exit-Codes, Wiederanlauf nach einem Absturz, automatik,
 * zeitplan, als root abgelehnt); update.js ohne HTML aus Daten. Am Ende wird
 * alles weggeräumt.
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
        if ($name === 'lauf') {
            // Kein Lauf – und kein Auftrag, zu dem der Helfer den Lauf noch schreibt.
            @unlink($upd . '/.angefordert');
        }
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
    @unlink($upd . '/.angefordert');
    UpdateService::vergessen();
};
$eingerichtet = static function () use ($schreiben, $helferText, $standText, $gitHead, $auftragWeg, $I, $upd): void {
    $auftragWeg();
    foreach (['lauf', 'wartung', 'verlauf', 'protokoll'] as $n) {
        $schreiben($n, null);
    }
    $schreiben('helfer', $helferText(time()));
    $schreiben('stand', $standText(time()));
    $gitHead('ref: refs/heads/main', ['refs/heads/main' => $I]);
    // Lebenszeichen des Zeitplans – wie auf einem gesunden System.
    touch($upd . '/zeitplan');
};
/** Automatische Updates auf die Vorgaben zurück. */
$automatikZurueck = static function (): void {
    foreach ([UpdateAutomatik::MODUS, UpdateAutomatik::TAGE, UpdateAutomatik::BEGINN, UpdateAutomatik::DAUER, UpdateAutomatik::KARENZ,
              UpdateAutomatik::PAUSE, UpdateAutomatik::VERSATZ, UpdateAutomatik::LETZTER, UpdateAutomatik::VERSCHOBEN] as $k) {
        Settings::forget($k);
    }
    Settings::vergessen();
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
    // Lücke: der Helfer hat soll/auftrag schon weggeräumt (er prüft ihn noch),
    // ist/lauf zeigt den vorigen Lauf – der ist nicht das Ergebnis des Auftrags.
    $vorher = $laufText(['zustand' => 'fehler', 'ergebnis' => 'zurueckgerollt', 'grund' => 'migration',
        'beginn' => (string) (time() - 600), 'ende' => (string) (time() - 300)]);
    $schreiben('lauf', $vorher);
    $r = UpdateService::anfordern('pruefen', null, 'hand', 'admin');
    pruefe($r['ok'], 'Lücke: Auftrag gestellt');
    unlink($upd . '/soll/auftrag');
    UpdateService::vergessen();
    $z = UpdateService::zustand();
    pruefe($z['zustand'] === 'angefordert' && !$z['haengt'], 'Lücke: Auftrag übernommen, Lauf noch nicht geschrieben → angefordert, nicht „zurückgerollt“');
    pruefe(UpdateService::statusJson()['aktiv'] === true, 'Lücke: Status-JSON aktiv – update.js fragt weiter');
    $schreiben('lauf', $laufText(['kennung' => (string) $r['kennung'], 'aktion' => 'pruefen', 'beginn' => (string) time(), 'puls' => (string) time()]));
    gleich(UpdateService::zustand()['zustand'], 'laeuft', 'Lücke: dann der Lauf zum Auftrag');
    $schreiben('lauf', $laufText(['kennung' => (string) $r['kennung'], 'aktion' => 'pruefen', 'zustand' => 'fehler', 'ergebnis' => 'abgelehnt',
        'grund' => 'doppelt', 'beginn' => (string) time(), 'ende' => (string) time()]));
    gleich(UpdateService::zustand()['zustand'], 'fehler', 'Lücke: eine Ablehnung mit dieser Kennung beendet sie');
    $schreiben('lauf', $laufText(['kennung' => str_repeat('e', 32), 'aktion' => 'wiederaufnehmen', 'zustand' => 'fertig', 'ergebnis' => 'aktuell',
        'beginn' => (string) (time() + 2), 'ende' => (string) (time() + 3)]));
    gleich(UpdateService::zustand()['zustand'], 'fertig', 'Lücke: ein späterer Lauf mit anderer Kennung (Wiederanlauf) zählt gleich');
    $schreiben('lauf', $vorher);
    file_put_contents($upd . '/.angefordert', $r['kennung'] . ' ' . (time() - UpdateService::HAENGT - 5) . "\n");
    UpdateService::vergessen();
    gleich(UpdateService::zustand()['zustand'], 'fehler', 'Lücke: nach 2 min ohne Lauf zählt wieder der vorige Lauf');
    $auftragWeg();
    $schreiben('lauf', $laufText(['zustand' => 'fehler', 'ergebnis' => 'zurueckgerollt', 'grund' => 'migration', 'ende' => (string) $jetzt]));
    $schreiben('helfer', $helferText($jetzt - 3000));
    pruefe(UpdateService::zustand($jetzt)['still'], 'Zustand: Helfer still nach 45 min');
    // Das Lebenszeichen kommt zu Beginn und am Ende eines Laufs – ein Update
    // über 45 min, das pulst, ist kein stiller Dienst.
    $schreiben('lauf', $laufText(['beginn' => (string) ($jetzt - 2900), 'puls' => (string) ($jetzt - 1)]));
    $z = UpdateService::zustand($jetzt);
    pruefe($z['zustand'] === 'laeuft' && !$z['still'], 'Zustand: Update über 45 min, das pulst – Dienst nicht still');
    pruefe(!str_contains((string) UpdateService::sperrgrund('pruefen', null, $jetzt), 'meldet sich nicht'), 'Sperrgrund: langes Update mit Puls – kein „meldet sich nicht“');
    pruefe((UpdateService::navHinweis($jetzt)['text'] ?? '') !== 'Der Update-Dienst meldet sich nicht', 'Abzeichen: langes Update mit Puls – kein „meldet sich nicht“');
    $schreiben('lauf', $laufText(['beginn' => (string) ($jetzt - 2900), 'puls' => (string) ($jetzt - 200)]));
    pruefe(UpdateService::zustand($jetzt)['still'], 'Zustand: langes Update ohne Puls – Dienst still');
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
    // Der Helfer übernimmt den Auftrag (soll/auftrag weg) und schreibt den Lauf dazu.
    unlink($upd . '/soll/auftrag');
    $schreiben('lauf', $laufText(['kennung' => (string) $r['kennung'], 'aktion' => 'pruefen', 'zustand' => 'fertig', 'ergebnis' => 'geprueft',
        'beginn' => (string) time(), 'ende' => (string) time()]));
    $r2 = UpdateService::anfordern('pruefen', null, 'hand', 'pruefer');
    pruefe(!$r2['ok'] && str_contains($r2['message'], 'Eben erst gesucht'), 'Suchen höchstens alle 30 s');
    touch($upd . '/.letzter', time() - 40);
    $r2 = UpdateService::anfordern('pruefen', null, 'hand', 'Max Müller <x>');
    pruefe($r2['ok'] && str_contains((string) file_get_contents($upd . '/soll/auftrag'), "\nvon MaxMllerx\n"), 'nach 30 s wieder; „von“ bereinigt');
    gleich(UpdateService::vonBereinigen(''), 'unbekannt', 'leeres „von“ → unbekannt');
    $schreiben('lauf', null);
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
        foreach ([['update:automatik', 'aus'], ['update:zeitplan']] as $befehl) {
            [$rc, $aus] = $power($befehl);
            pruefe($rc === 1 && str_contains($aus, 'runuser -u'), 'als root: ' . $befehl[0] . ' abgelehnt');
        }
        Settings::vergessen();
        gleich(Settings::get(UpdateAutomatik::MODUS), null, 'als root: Modus nicht gespeichert');
        [$rc, $aus] = $power(['update:stand']);
        pruefe($rc === 0 && str_contains($aus, 'Update-Funktion: eingerichtet'), 'als root: update:stand liest');
        chown($upd, 0);
        clearstatcache();
        // storage/update als Verweis auf einen Ordner von root (etwa /tmp): root bleibt fremd
        pruefe(!UpdateService::fremderRoot(), 'als root: storage/update gehört root → nicht fremd');
        $rootZiel = $ordner . '/root-ziel';
        mkdir($rootZiel, 0755);
        rename($upd, $upd . '.echt');
        symlink($rootZiel, $upd);
        clearstatcache();
        pruefe(UpdateService::fremderRoot(), 'als root: storage/update ist ein Verweis auf einen Ordner von root → fremd');
        foreach ([['update:automatik', 'aus'], ['update:zeitplan'], ['update:suchen']] as $befehl) {
            [$rc, $aus] = $power($befehl);
            pruefe($rc === 1 && str_contains($aus, 'runuser -u'), 'als root mit Verweis: ' . $befehl[0] . ' abgelehnt');
        }
        gleich(array_values(array_diff(scandir($rootZiel), ['.', '..'])), [], 'als root mit Verweis: hinter dem Verweis nichts angelegt');
        Settings::vergessen();
        gleich(Settings::get(UpdateAutomatik::MODUS), null, 'als root mit Verweis: Modus nicht gespeichert');
        unlink($upd);
        rename($upd . '.echt', $upd);
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

    // ------------------------------------------------------------ Automatik: Einstellungen
    $eingerichtet();
    $automatikZurueck();
    Config::set('app.timezone', 'Europe/Berlin');
    $tz = new \DateTimeZone('Europe/Berlin');
    $ts = static fn (string $s): int => (new \DateTimeImmutable($s, $tz))->getTimestamp();
    $tagIn = static fn (int $tage): string => (new \DateTimeImmutable('now', $tz))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->format('Y-m-d');
    $e = UpdateAutomatik::einstellungen();
    gleich([$e['modus'], $e['tage'], $e['beginn'], $e['dauer'], $e['karenz'], $e['pause_bis']], ['suchen', [1, 2, 3, 4, 5, 6, 7], '04:00', 120, 24, null],
        'Automatik: Vorgaben (nur suchen, täglich 04:00, 2 Std., Karenz 24 Std.)');
    foreach ([
        'unbekannter Modus'      => ['bald', [1], '04:00', 120, 24, null],
        'Wochentag 8'            => ['einspielen', [8], '04:00', 120, 24, null],
        'Wochentag als Text'     => ['einspielen', ['1; DROP'], '04:00', 120, 24, null],
        'einspielen ohne Tag'    => ['einspielen', [], '04:00', 120, 24, null],
        'Beginn 24:00'           => ['suchen', [1], '24:00', 120, 24, null],
        'Beginn 4:00'            => ['suchen', [1], '4:00', 120, 24, null],
        'Dauer 90'               => ['suchen', [1], '04:00', 90, 24, null],
        'Karenz 12'              => ['suchen', [1], '04:00', 120, 12, null],
        'Pause in der Vergangenheit' => ['suchen', [1], '04:00', 120, 24, $tagIn(-1)],
        'Pause über 90 Tage'     => ['suchen', [1], '04:00', 120, 24, $tagIn(91)],
        'Pause kein Datum'       => ['suchen', [1], '04:00', 120, 24, '1.1.2027'],
    ] as $fall => $args) {
        $r = UpdateAutomatik::speichern(...$args);
        pruefe(!$r['ok'] && $r['message'] !== '', 'speichern lehnt ab: ' . $fall);
    }
    Settings::vergessen();
    gleich(Settings::get(UpdateAutomatik::MODUS), null, 'abgelehnt: nichts gespeichert');
    $r = UpdateAutomatik::speichern('einspielen', ['5', '1', '3', '3'], '05:30', 60, 0, $tagIn(2));
    $e = UpdateAutomatik::einstellungen();
    pruefe($r['ok'] && $e['modus'] === 'einspielen' && $e['tage'] === [1, 3, 5] && $e['beginn'] === '05:30' && $e['dauer'] === 60 && $e['karenz'] === 0,
        'speichern: Modus, Wochentage (sortiert, einmal), Beginn, Dauer, Karenz');
    gleich($e['pause_bis'], $ts($tagIn(2) . ' 23:59:59'), 'speichern: „Pausieren bis“ = Ende des Tages in der Zeitzone der Installation');
    pruefe(str_ends_with((string) Settings::get(UpdateAutomatik::PAUSE), '+00:00'), 'speichern: Pause als ISO 8601 in UTC');
    gleich(UpdateAutomatik::speichern('einspielen', [1], '05:30', 60, 0, $tagIn(90))['ok'], true, 'speichern: Pause genau 90 Tage voraus geht');
    Settings::put(UpdateAutomatik::VERSCHOBEN, gmdate('c', time() - 3600));
    $r = UpdateAutomatik::speichern('suchen', [], '04:00', 120, 24, '');
    $e = UpdateAutomatik::einstellungen();
    pruefe($r['ok'] && $e['tage'] === [] && $e['pause_bis'] === null && UpdateAutomatik::verschobenSeit() === null,
        'speichern: suchen ohne Tage geht, leere Pause entfernt sie, „verschoben seit“ fällt weg');
    gleich(UpdateAutomatik::tageText([1, 2, 3, 4, 5]), 'Mo–Fr', 'Wochentage als Text: Mo–Fr');
    gleich(UpdateAutomatik::tageText([1, 3, 6]), 'Mo, Mi, Sa', 'Wochentage als Text: einzeln');

    // ------------------------------------------------------------ Automatik: Fenster (Europe/Berlin)
    $e = ['modus' => 'einspielen', 'tage' => [1, 2, 3, 4, 5, 6, 7], 'beginn' => '04:00', 'dauer' => 120, 'karenz' => 24, 'pause_bis' => null, 'versatz' => 0];
    $f = UpdateAutomatik::fenster($ts('2026-10-21 04:10'), $e);
    pruefe($f !== null && $f['tag'] === '2026-10-21' && $f['beginn'] === $ts('2026-10-21 04:00') && $f['ende'] === $ts('2026-10-21 06:00')
        && $f['ab'] === $ts('2026-10-21 04:00') && $f['bis'] === $ts('2026-10-21 05:45'), 'Fenster: normaler Tag 04:00–06:00, einspielen bis 05:45');
    gleich(UpdateAutomatik::fenster($ts('2026-10-21 03:59'), $e), null, 'Fenster: vor dem Beginn zu');
    gleich(UpdateAutomatik::fenster($ts('2026-10-21 05:45'), $e), null, 'Fenster: 15 min vor dem Ende zu');
    pruefe(UpdateAutomatik::fenster($ts('2026-10-21 05:44'), $e) !== null, 'Fenster: bis kurz davor offen');
    gleich(UpdateAutomatik::fenster($ts('2026-10-21 04:10'), ['versatz' => 20] + $e), null, 'Fenster: Versatz 20 min – um 04:10 noch zu');
    gleich(UpdateAutomatik::fenster($ts('2026-10-21 04:20'), ['versatz' => 20] + $e)['ab'] ?? null, $ts('2026-10-21 04:20'), 'Fenster: Versatz 20 min – ab 04:20');
    gleich(UpdateAutomatik::fenster($ts('2026-10-21 04:20'), ['dauer' => 60, 'versatz' => 200] + $e)['ab'] ?? null, $ts('2026-10-21 04:14'),
        'Fenster: Versatz größer als das verkürzte Fenster – läuft herum (200 mod 31)');
    gleich(UpdateAutomatik::fenster($ts('2026-10-21 04:10'), ['tage' => [1]] + $e), null, 'Fenster: Mittwoch, nur montags – zu');
    gleich(UpdateAutomatik::fenster($ts('2026-10-19 04:10'), ['tage' => [1]] + $e)['tag'] ?? null, '2026-10-19', 'Fenster: Montag, nur montags – offen');
    $nacht = ['beginn' => '23:30'] + $e;
    gleich(UpdateAutomatik::fenster($ts('2026-10-22 00:30'), ['tage' => [3]] + $nacht)['tag'] ?? null, '2026-10-21', 'Fenster über Mitternacht gehört zum Starttag');
    gleich(UpdateAutomatik::fenster($ts('2026-10-22 00:30'), ['tage' => [4]] + $nacht), null, 'Fenster über Mitternacht: Starttag nicht gewählt – zu');
    // Sommerzeit: 29.03.2026 02:00 → 03:00
    $f = UpdateAutomatik::fenster($ts('2026-03-29 04:30'), $e);
    pruefe($f !== null && $f['beginn'] === strtotime('2026-03-29T02:00:00Z') && $f['bis'] - $f['beginn'] === 105 * 60, 'Fenster am 29.03.2026: 04:00 Sommerzeit');
    $f = UpdateAutomatik::fenster($ts('2026-03-29 03:40'), ['beginn' => '02:30'] + $e);
    pruefe($f !== null && $f['tag'] === '2026-03-29' && $f['beginn'] === strtotime('2026-03-29T01:30:00Z') && $f['bis'] - $f['beginn'] === 105 * 60,
        'Fenster am 29.03.2026, Beginn 02:30 (gibt es nicht): PHP schiebt auf 03:30, Dauer bleibt');
    // Winterzeit: 25.10.2026 03:00 → 02:00
    $f = UpdateAutomatik::fenster(strtotime('2026-10-25T03:30:00Z'), $e);
    pruefe($f !== null && $f['beginn'] === strtotime('2026-10-25T03:00:00Z') && $f['ende'] === strtotime('2026-10-25T05:00:00Z'), 'Fenster am 25.10.2026: 04:00 Winterzeit');
    $f = UpdateAutomatik::fenster(strtotime('2026-10-25T01:45:00Z'), ['beginn' => '02:30'] + $e);
    pruefe($f !== null && $f['tag'] === '2026-10-25' && $f['bis'] - $f['beginn'] === 105 * 60, 'Fenster am 25.10.2026, Beginn 02:30 (doppelt): ein Fenster, echte Dauer');
    $f = UpdateAutomatik::fenster(strtotime('2026-10-25T00:30:00Z'), ['beginn' => '01:30'] + $e);
    pruefe($f !== null && $f['ende'] - $f['beginn'] === 7200, 'Fenster am 25.10.2026 über die Umstellung: zwei echte Stunden');
    // Nächstes Fenster
    gleich(UpdateAutomatik::naechstesFenster($ts('2026-10-21 04:30'), $e)['tag'] ?? null, '2026-10-21', 'nächstes Fenster: das offene');
    gleich(UpdateAutomatik::naechstesFenster($ts('2026-10-21 06:00'), $e)['tag'] ?? null, '2026-10-22', 'nächstes Fenster: morgen');
    gleich(UpdateAutomatik::naechstesFenster($ts('2026-10-21 06:00'), ['tage' => [1]] + $e)['tag'] ?? null, '2026-10-26', 'nächstes Fenster: nächster Montag');
    gleich(UpdateAutomatik::naechstesFenster($ts('2026-10-21 06:00'), ['pause_bis' => $ts('2026-10-23 23:59:59')] + $e)['tag'] ?? null, '2026-10-24',
        'nächstes Fenster: nach der Pause');
    gleich(UpdateAutomatik::naechstesFenster($ts('2026-10-21 06:00'), ['tage' => []] + $e), null, 'nächstes Fenster: ohne Wochentag keines');
    gleich(UpdateAutomatik::terminText(UpdateAutomatik::naechstesFenster($ts('2026-10-21 06:00'), $e)), 'Do 22.10. 04:00–06:00', 'Termin als Text');

    // ------------------------------------------------------------ Automatik: entscheiden (rein)
    $J = $ts('2026-10-21 04:10');
    $basis = UpdateService::stand();
    $standAm = static fn (int $jetzt, array $mehr = []): array => array_replace($basis, [
        'abruf_zeit' => $jetzt - 600, 'neu' => 2, 'linear' => true, 'lokal_geaendert' => false, 'zweig' => 'main', 'migrationen' => false,
        'verfuegbar' => ['commit' => $V, 'zeit' => $jetzt - 3600],
        'kandidaten' => [['commit' => $V, 'zeit' => $jetzt - 3600], ['commit' => $K1, 'zeit' => $jetzt - 30 * 3600]], 'gescheitert' => [],
    ], $mehr);
    $lage = static fn (int $jetzt, array $mehr = [], array $einst = []): array => array_replace([
        'einstellungen' => array_replace($e, $einst), 'zustand' => 'bereit', 'aktiv' => false, 'wartung' => false, 'abgeschaltet' => false,
        'automatik_root' => false, 'offen' => 'nein', 'stand' => $standAm($jetzt), 'letzte_suche' => null, 'letzter' => null,
        'hindernisse' => [], 'treiber' => 'sqlite',
    ], $mehr);
    $ent = static fn (array $mehr = [], array $einst = [], ?int $jetzt = null): array =>
        UpdateAutomatik::entscheiden($jetzt ?? $J, $lage($jetzt ?? $J, $mehr, $einst));
    $kurzE = static fn (array $r): string => ($r['aktion'] ?? '-') . ' ' . $r['grund'] . ($r['ziel'] !== null ? ' ' . substr($r['ziel'], 0, 7) : '');
    $v7 = substr($V, 0, 7);
    $k7 = substr($K1, 0, 7);
    gleich($kurzE($ent([], ['karenz' => 0])), "aktualisieren einspielen {$v7}", 'entscheiden: im Fenster, ohne Karenz → der neueste Stand');
    gleich($ent([], ['karenz' => 0])['tag'], '2026-10-21', 'entscheiden: Tag des Fensters');
    gleich($kurzE($ent()), "aktualisieren einspielen {$k7}", 'entscheiden: Karenz 24 Std. → der neueste Kandidat, der älter ist');
    gleich($kurzE($ent([], ['karenz' => 72])), '- verschoben:karenz', 'entscheiden: alle Kandidaten jünger als die Karenz → verschoben');
    gleich($kurzE($ent(['stand' => $standAm($J, ['gescheitert' => [$V]])], ['karenz' => 0])), "aktualisieren einspielen {$k7}", 'entscheiden: gescheitertes Ziel übersprungen');
    gleich($kurzE($ent(['stand' => $standAm($J, ['gescheitert' => [$V, $K1]])], ['karenz' => 0])), '- verschoben:gescheitert', 'entscheiden: nur gescheiterte Ziele → verschoben');
    gleich($kurzE($ent(['stand' => $standAm($J, ['kandidaten' => []])], ['karenz' => 0])), "aktualisieren einspielen {$v7}", 'entscheiden: ohne Kandidatenliste der verfügbare Stand');
    foreach ([
        'demo'               => [['zustand' => 'demo'], [], '- demo'],
        'nicht eingerichtet' => [['zustand' => 'nicht_eingerichtet'], [], '- nicht_eingerichtet'],
        'Schreibsperre'      => [['wartung' => true], [], '- wartung'],
        'Lauf aktiv'         => [['aktiv' => true], [], '- laeuft'],
        'Modus aus'          => [[], ['modus' => 'aus'], '- aus'],
        'OBERFLAECHE=nein'   => [['abgeschaltet' => true], [], '- abgeschaltet'],
        'nur suchen'         => [[], ['modus' => 'suchen'], '- nur_suchen'],
        'kein Stand'         => [['stand' => null], [], 'pruefen suchen'],
        'Stand 7 Std. alt'   => [['stand' => $standAm($J, ['abruf_zeit' => $J - 7 * 3600])], ['modus' => 'suchen'], 'pruefen suchen'],
        'nie gesucht'        => [['stand' => $standAm($J, ['abruf_zeit' => null])], ['modus' => 'suchen'], 'pruefen suchen'],
        'eben vergeblich gesucht' => [['stand' => $standAm($J, ['abruf_zeit' => $J - 7 * 3600]), 'letzte_suche' => $J - 600], ['modus' => 'suchen'], '- suche_wartet'],
        'vor 40 min vergeblich gesucht' => [['stand' => $standAm($J, ['abruf_zeit' => $J - 7 * 3600]), 'letzte_suche' => $J - 2400], ['modus' => 'suchen'], 'pruefen suchen'],
        'Versatz verlängert die 6 Std.' => [['stand' => $standAm($J, ['abruf_zeit' => $J - 6 * 3600 - 600])], ['modus' => 'suchen', 'versatz' => 30], '- nur_suchen'],
        'pausiert'           => [[], ['pause_bis' => $J + 3600], '- verschoben:pausiert'],
        'Pause vorbei'       => [[], ['pause_bis' => $J - 1, 'karenz' => 0], "aktualisieren einspielen {$v7}"],
        'heute angefordert'  => [['letzter' => ['tag' => '2026-10-21', 'ergebnis' => 'angefordert']], [], '- erledigt'],
        'heute nichts Neues' => [['letzter' => ['tag' => '2026-10-21', 'ergebnis' => 'nichts_neu']], [], '- erledigt'],
        'heute verschoben'   => [['letzter' => ['tag' => '2026-10-21', 'ergebnis' => 'verschoben']], ['karenz' => 0], "aktualisieren einspielen {$v7}"],
        'gestern angefordert' => [['letzter' => ['tag' => '2026-10-20', 'ergebnis' => 'angefordert']], ['karenz' => 0], "aktualisieren einspielen {$v7}"],
        'Stand im Fenster 40 min alt' => [['stand' => $standAm($J, ['abruf_zeit' => $J - 2400])], [], 'pruefen suchen_vorher'],
        'Stand im Fenster alt, eben gesucht' => [['stand' => $standAm($J, ['abruf_zeit' => $J - 2400]), 'letzte_suche' => $J - 300], [], '- suche_wartet'],
        'nichts neu'         => [['stand' => $standAm($J, ['neu' => 0])], [], '- nichts_neu'],
        'nicht linear'       => [['stand' => $standAm($J, ['linear' => false])], [], '- verschoben:nicht_linear'],
        'lokal geändert'     => [['stand' => $standAm($J, ['lokal_geaendert' => true])], [], '- verschoben:lokal_geaendert'],
        'kein Zweig'         => [['stand' => $standAm($J, ['zweig' => null])], [], '- verschoben:kein_zweig'],
        'AUTOMATIK=nein'     => [['automatik_root' => true], [], '- verschoben:abgeschaltet'],
        'unterbrochenes Update offen' => [['offen' => 'ja'], [], '- verschoben:offen'],
        'Aufbau'             => [['hindernisse' => [['art' => 'aufbau', 'text' => 'Aufbau „Messe“ läuft.']]], ['karenz' => 0], "- verschoben:aufbau {$v7}"],
        'geschaltet'         => [['hindernisse' => [['art' => 'geschaltet', 'text' => 'geschaltet']]], ['karenz' => 0], "- verschoben:geschaltet {$v7}"],
        'MySQL mit Migration' => [['treiber' => 'mysql', 'stand' => $standAm($J, ['migrationen' => true])], ['karenz' => 0], "- verschoben:treiber {$v7}"],
        'MySQL ohne Migration' => [['treiber' => 'mysql'], ['karenz' => 0], "aktualisieren einspielen {$v7}"],
        'außerhalb'          => [[], [], '- ausserhalb', $ts('2026-10-21 07:00')],
    ] as $fall => $daten) {
        [$mehr, $einst, $erwartet] = $daten;
        $jetzt = $daten[3] ?? null;
        if (isset($mehr['letzter'])) {
            $mehr['letzter'] += ['zeit' => $J, 'grund' => '', 'ziel' => null, 'text' => '', 'gemeldet' => []];
        }
        gleich($kurzE($ent($mehr, $einst, $jetzt)), $erwartet, 'entscheiden: ' . $fall);
    }
    gleich($ent(['hindernisse' => [['art' => 'aufbau', 'text' => 'Aufbau „Messe“ läuft.']]])['text'], 'Aufbau „Messe“ läuft.', 'entscheiden: Aufbau mit Namen');
    foreach ([
        'Sommerzeit 29.03.2026'   => $ts('2026-03-29 04:30'),
        'Winterzeit 25.10.2026'   => strtotime('2026-10-25T03:30:00Z'),
    ] as $fall => $jetzt) {
        gleich($kurzE(UpdateAutomatik::entscheiden($jetzt, $lage($jetzt, [], ['karenz' => 0]))), "aktualisieren einspielen {$v7}", 'entscheiden: ' . $fall);
    }
    $jetzt = $ts('2026-10-22 00:30');
    $r = UpdateAutomatik::entscheiden($jetzt, $lage($jetzt, [], ['karenz' => 0, 'beginn' => '23:30', 'tage' => [3]]));
    pruefe($r['aktion'] === 'aktualisieren' && $r['tag'] === '2026-10-21', 'entscheiden: über Mitternacht, Tag = Starttag');
    $r = UpdateAutomatik::entscheiden($jetzt, $lage($jetzt, ['letzter' => ['tag' => '2026-10-21', 'ergebnis' => 'angefordert', 'zeit' => $J, 'grund' => '', 'ziel' => $V, 'text' => '', 'gemeldet' => []]],
        ['karenz' => 0, 'beginn' => '23:30', 'tage' => [3]]));
    gleich($r['grund'], 'erledigt', 'entscheiden: über Mitternacht, schon am Starttag angefordert → erledigt');

    // ------------------------------------------------------------ Automatik: Zeitplan (Dateien, Einstellungen, Audit)
    $eingerichtet();
    $automatikZurueck();
    $jetztLokal = (new \DateTimeImmutable('now', $tz))->format('H:i');
    $zaehle = static fn (string $aktion): int => (int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$aktion]);
    gleich(UpdateAutomatik::speichern('einspielen', [1, 2, 3, 4, 5, 6, 7], $jetztLokal, 60, 0, '', 0)['ok'], true, 'Zeitplan: Fenster ab jetzt eingestellt');
    $automatisch = $zaehle('system.update.automatisch');
    touch($upd . '/zeitplan', time() - 600);
    $r = UpdateAutomatik::zeitplan();
    $text = (string) @file_get_contents($upd . '/soll/auftrag');
    pruefe($r['aktion'] === 'aktualisieren' && $r['ziel'] === $V && str_contains($text, "aktion aktualisieren\nausloeser automatik\nvon zeitplan\n")
        && str_ends_with($text, "ziel {$V}\n"), 'Zeitplan: im Fenster ein Auftrag aktualisieren (automatik, zeitplan) auf den verfügbaren Stand');
    pruefe(str_contains($r['meldung'], 'Update auf ' . $v7 . ' angefordert'), 'Zeitplan: meldet, was er tat');
    $l = UpdateAutomatik::letzter();
    pruefe(($l['ergebnis'] ?? '') === 'angefordert' && ($l['ziel'] ?? '') === $V && ($l['tag'] ?? '') === (new \DateTimeImmutable('now', $tz))->format('Y-m-d'),
        'Zeitplan: auto_letzter angefordert, Tag des Fensters');
    gleich($zaehle('system.update.automatisch') - $automatisch, 1, 'Zeitplan: Audit system.update.automatisch');
    pruefe((UpdateAutomatik::zeitplanAlter() ?? 999) <= 5, 'Zeitplan: Lebenszeichen storage/update/zeitplan');
    pruefe(UpdateAutomatik::einstellungen()['versatz'] === 0, 'Zeitplan: Versatz wie eingestellt');
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['aktion'] === null && $r['grund'] === 'laeuft' && (string) @file_get_contents($upd . '/soll/auftrag') === $text, 'Zeitplan: Auftrag offen → nichts');
    $auftragWeg();
    $schreiben('lauf', $laufText(['zustand' => 'fertig', 'ergebnis' => 'eingespielt', 'ende' => (string) time(), 'ausloeser' => 'automatik', 'von' => 'zeitplan']));
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['aktion'] === null && $r['grund'] === 'erledigt' && !is_file($upd . '/soll/auftrag') && $r['meldung'] === '', 'Zeitplan: höchstens ein Lauf je Tag');
    // Ein Tag später, Aufbau läuft: verschoben, ins Audit einmal je Tag und Grund
    $l = UpdateAutomatik::letzter();
    Settings::put(UpdateAutomatik::LETZTER, (string) json_encode(['tag' => '2000-01-01'] + (array) $l));
    Database::execute("UPDATE aufbauten SET status = 'aufbau' WHERE id = ?", [$aufbau]);
    $verschoben = $zaehle('system.update.verschoben');
    $r = UpdateAutomatik::zeitplan();
    $l = UpdateAutomatik::letzter();
    pruefe($r['aktion'] === null && $r['grund'] === 'verschoben:aufbau' && !is_file($upd . '/soll/auftrag'), 'Zeitplan: Aufbau läuft → kein Auftrag, verschoben');
    pruefe(($l['ergebnis'] ?? '') === 'verschoben' && ($l['grund'] ?? '') === 'aufbau' && str_contains($l['text'] ?? '', $kennung . ' Messe'), 'Zeitplan: auto_letzter verschoben aufbau mit Namen');
    pruefe(UpdateAutomatik::verschobenSeit() !== null && str_contains($r['meldung'], $kennung . ' Messe'), 'Zeitplan: „verschoben seit“ gesetzt, Meldung nennt den Aufbau');
    gleich($zaehle('system.update.verschoben') - $verschoben, 1, 'Zeitplan: Audit system.update.verschoben');
    $seit = Settings::get(UpdateAutomatik::VERSCHOBEN);
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['grund'] === 'verschoben:aufbau' && $r['meldung'] === '' && Settings::get(UpdateAutomatik::VERSCHOBEN) === $seit, 'Zeitplan: zweimal verschoben – nichts Neues zu melden, „seit“ bleibt');
    gleich($zaehle('system.update.verschoben') - $verschoben, 1, 'Zeitplan: Audit höchstens einmal je Tag und Grund');
    Database::execute("UPDATE aufbauten SET status = 'beendet' WHERE id = ?", [$aufbau]);
    $schreiben('lauf', null);
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['aktion'] === 'aktualisieren' && is_file($upd . '/soll/auftrag') && UpdateAutomatik::verschobenSeit() === null, 'Zeitplan: Aufbau beendet → eingespielt, „seit“ gelöscht');
    $auftragWeg();
    // Schreibsperre, Demo, nicht eingerichtet: nichts schreiben, nicht einmal das Lebenszeichen
    Settings::put(UpdateAutomatik::LETZTER, (string) json_encode(['tag' => '2000-01-01', 'ergebnis' => 'angefordert']));
    touch($upd . '/zeitplan', time() - 600);
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    $r = UpdateAutomatik::zeitplan();
    clearstatcache();
    pruefe($r['grund'] === 'wartung' && !is_file($upd . '/soll/auftrag') && filemtime($upd . '/zeitplan') <= time() - 590, 'Zeitplan: während der Schreibsperre nichts, kein Lebenszeichen');
    $schreiben('wartung', null);
    Config::set('app.demo', true);
    gleich(UpdateAutomatik::zeitplan()['grund'], 'demo', 'Zeitplan: Demo-Modus – nichts');
    Config::set('app.demo', false);
    Config::set('update.verzeichnis', $ordner . '/gibt-es-nicht');
    gleich(UpdateAutomatik::zeitplan()['grund'], 'nicht_eingerichtet', 'Zeitplan: nicht eingerichtet – nichts');
    Config::set('update.verzeichnis', $upd);
    pruefe(!is_file($upd . '/soll/auftrag'), 'Zeitplan: bis hier kein Auftrag');
    // Nur suchen: alter Stand → suchen, ohne Netz nicht alle fünf Minuten
    UpdateAutomatik::speichern('suchen', [1, 2, 3, 4, 5, 6, 7], '04:00', 120, 24, '');
    $schreiben('stand', $standText(time(), ['abruf_zeit' => (string) (time() - 8 * 3600)]));
    $r = UpdateAutomatik::zeitplan();
    $text = (string) @file_get_contents($upd . '/soll/auftrag');
    pruefe($r['aktion'] === 'pruefen' && str_contains($text, "aktion pruefen\nausloeser automatik\nvon zeitplan\n") && !str_contains($text, 'ziel '),
        'Zeitplan, nur suchen: Stand älter als 6 Std. → Auftrag pruefen');
    @unlink($upd . '/soll/auftrag');
    @unlink($upd . '/.angefordert');
    UpdateService::vergessen();
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['aktion'] === null && $r['grund'] === 'suche_wartet' && !is_file($upd . '/soll/auftrag'), 'Zeitplan: Suche schlug fehl – nicht gleich wieder');
    $schreiben('stand', $standText(time()));
    gleich(UpdateAutomatik::zeitplan()['grund'], 'nur_suchen', 'Zeitplan, nur suchen: frischer Stand – nichts');
    UpdateAutomatik::speichern('aus', [1], '04:00', 120, 24, '');
    $schreiben('stand', $standText(time(), ['abruf_zeit' => (string) (time() - 8 * 3600)]));
    $auftragWeg();
    gleich(UpdateAutomatik::zeitplan()['grund'], 'aus', 'Zeitplan, aus: auch ein alter Stand wird nicht gesucht');
    pruefe(!is_file($upd . '/soll/auftrag'), 'Zeitplan, aus: kein Auftrag');

    // ------------------------------------------------------------ Automatik: Abzeichen
    $eingerichtet();
    $automatikZurueck();
    pruefe((UpdateService::navHinweis()['stufe'] ?? '') === 'primary', 'Abzeichen: Zeitplan lebt – Zahl');
    touch($upd . '/zeitplan', time() - 1000);
    UpdateService::vergessen();
    gleich(UpdateService::navHinweis(), ['stufe' => 'warn', 'text' => 'Der Update-Zeitplan läuft nicht'], 'Abzeichen: Zeitplan still seit 15 min → gelb');
    // Während der Schreibsperre setzt der Zeitplan aus – danach zählt die Zeit ab dem Ende des Laufs.
    $schreiben('lauf', $laufText(['zustand' => 'fertig', 'ergebnis' => 'eingespielt', 'beginn' => (string) (time() - 1500), 'ende' => (string) (time() - 60)]));
    pruefe(!UpdateAutomatik::zeitplanStill(), 'Zeitplan: kurz nach einem Update über 15 min nicht „still“');
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'primary', 'Abzeichen: kurz nach einem langen Update kein „Zeitplan läuft nicht“');
    $schreiben('lauf', $laufText(['zustand' => 'fertig', 'ergebnis' => 'eingespielt', 'beginn' => (string) (time() - 2500), 'ende' => (string) (time() - 1000)]));
    pruefe(UpdateAutomatik::zeitplanStill(), 'Zeitplan: 15 min nach dem Ende des Laufs ohne Lebenszeichen → still');
    $schreiben('lauf', null);
    UpdateAutomatik::speichern('aus', [], '04:00', 120, 24, '');
    UpdateService::vergessen();
    pruefe((UpdateService::navHinweis()['stufe'] ?? '') === 'primary', 'Abzeichen: Modus aus – ein stiller Zeitplan zählt nicht');
    $automatikZurueck();
    unlink($upd . '/zeitplan');
    touch($upd . '/soll', time() - 1000);
    UpdateService::vergessen();
    gleich(UpdateService::navHinweis()['stufe'] ?? '', 'warn', 'Abzeichen: Zeitplan nie gelaufen, seit der Einrichtung 15 min → gelb');
    touch($upd . '/soll');
    UpdateService::vergessen();
    pruefe((UpdateService::navHinweis()['stufe'] ?? '') === 'primary', 'Abzeichen: Zeitplan nie gelaufen, eben eingerichtet → noch nicht');
    touch($upd . '/zeitplan');
    UpdateAutomatik::speichern('einspielen', [1, 2, 3, 4, 5, 6, 7], '04:00', 120, 24, '');
    Settings::put(UpdateAutomatik::VERSCHOBEN, gmdate('c', time() - 15 * 86400));
    UpdateService::vergessen();
    gleich(UpdateService::navHinweis(), ['stufe' => 'warn', 'text' => 'Automatische Updates werden seit über 14 Tagen verschoben'], 'Abzeichen: seit 15 Tagen verschoben → gelb');
    Settings::put(UpdateAutomatik::VERSCHOBEN, gmdate('c', time() - 13 * 86400));
    UpdateService::vergessen();
    pruefe((UpdateService::navHinweis()['stufe'] ?? '') === 'primary', 'Abzeichen: seit 13 Tagen verschoben – noch nicht');
    // Eine gewollte Pause ist kein „verschoben“: kein Abzeichen, und der Zeitplan setzt „seit“ zurück statt es zu setzen.
    $in30 = (new \DateTimeImmutable('now', $tz))->modify('+30 days')->format('Y-m-d');
    gleich(UpdateAutomatik::speichern('einspielen', [1, 2, 3, 4, 5, 6, 7], (new \DateTimeImmutable('now', $tz))->format('H:i'), 60, 0, $in30, 0)['ok'],
        true, 'Pause: 30 Tage eingestellt');
    Settings::put(UpdateAutomatik::VERSCHOBEN, gmdate('c', time() - 15 * 86400));
    UpdateService::vergessen();
    pruefe(!UpdateAutomatik::langeVerschoben() && (UpdateService::navHinweis()['text'] ?? '') !== 'Automatische Updates werden seit über 14 Tagen verschoben',
        'Abzeichen: pausiert – nicht „seit über 14 Tagen verschoben“');
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['grund'] === 'verschoben:pausiert' && UpdateAutomatik::verschobenSeit() === null, 'Zeitplan: pausiert → „verschoben seit“ zurückgesetzt');
    $r = UpdateAutomatik::zeitplan();
    pruefe($r['grund'] === 'verschoben:pausiert' && UpdateAutomatik::verschobenSeit() === null, 'Zeitplan: pausiert – auch beim nächsten Takt nicht gesetzt');
    pruefe(!is_file($upd . '/soll/auftrag'), 'Zeitplan: pausiert – kein Auftrag');
    $automatikZurueck();
    UpdateService::vergessen();

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

    // update:automatik / update:zeitplan
    $automatikZurueck();
    $automatikAudit = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.update.automatik'");
    foreach ([
        'Wochentag 8'         => ['update:automatik', 'einspielen', '--tage=8'],
        'Dauer keine Zahl'    => ['update:automatik', 'suchen', '--dauer=zwei'],
        'unbekannte Option'   => ['update:automatik', 'suchen', '--ziel=x'],
        'zwei Modi'           => ['update:automatik', 'suchen', 'aus'],
        'Option ohne Wert'    => ['update:automatik', 'suchen', '--beginn'],
        'Optionen ohne Modus' => ['update:automatik', '--tage=1'],
    ] as $fall => $argumente) {
        [$rc, $aus] = $power($argumente);
        pruefe($rc === 1 && str_contains($aus, 'Fehler:'), 'update:automatik lehnt ab: ' . $fall);
    }
    Settings::vergessen();
    gleich(Settings::get(UpdateAutomatik::MODUS), null, 'update:automatik: abgelehnt – nichts gespeichert');
    [$rc, $aus] = $power(['update:automatik', 'einspielen', '--tage=1, 2,3', '--beginn=05:00', '--dauer=60', '--karenz=0', '--versatz=0', '--pause-bis=' . $tagIn(3)]);
    Settings::vergessen();
    $e = UpdateAutomatik::einstellungen();
    pruefe($rc === 0 && str_contains($aus, 'automatisch einspielen') && $e['modus'] === 'einspielen' && $e['tage'] === [1, 2, 3] && $e['beginn'] === '05:00'
        && $e['dauer'] === 60 && $e['karenz'] === 0 && $e['versatz'] === 0 && $e['pause_bis'] === $ts($tagIn(3) . ' 23:59:59'),
        'update:automatik einspielen mit allen Optionen');
    [$rc, $aus] = $power(['update:automatik', 'einspielen', '--karenz=6']);
    Settings::vergessen();
    $e = UpdateAutomatik::einstellungen();
    pruefe($rc === 0 && $e['karenz'] === 6 && $e['tage'] === [1, 2, 3] && $e['beginn'] === '05:00' && $e['pause_bis'] !== null, 'update:automatik: nicht genannte Werte bleiben');
    [$rc, $aus] = $power(['update:automatik', 'einspielen', '--pause-bis=']);
    Settings::vergessen();
    pruefe($rc === 0 && UpdateAutomatik::einstellungen()['pause_bis'] === null, 'update:automatik --pause-bis= hebt die Pause auf');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.update.automatik'") - $automatikAudit, 3, 'update:automatik: Audit je Speichern');
    [$rc, $aus] = $power(['update:automatik']);
    pruefe($rc === 0 && str_contains($aus, 'Modus:           automatisch einspielen') && str_contains($aus, 'Fenster:         Mo–Mi ab 05:00 für 1 Std., Karenz 6 Std.'),
        'update:automatik ohne Modus zeigt die Einstellungen');
    [$rc, $aus] = $power(['update:stand']);
    pruefe($rc === 0 && preg_match('/^Automatik:       automatisch einspielen \(Mo–Mi ab 05:00 für 1 Std\., Karenz 6 Std\.\), nächstes Fenster /m', $aus) === 1,
        'update:stand: Zeile „Automatik:“');
    $power(['update:automatik', 'suchen']);
    [$rc, $aus] = $power(['update:stand']);
    pruefe(str_contains($aus, "Automatik:       nur suchen und anzeigen\n"), 'update:stand: Automatik nur suchen');
    [$rc, $aus] = $power(['update:zeitplan', '--gespraechig']);
    pruefe($rc === 0 && str_contains($aus, 'Nichts zu tun (nur_suchen)') && !is_file($upd . '/soll/auftrag'), 'update:zeitplan: frischer Stand, nur suchen – nichts zu tun');
    [$rc, $aus] = $power(['update:zeitplan']);
    gleich([$rc, trim($aus)], [0, ''], 'update:zeitplan ohne --gespraechig: still, wenn nichts geschah');
    [$rc, $aus] = $power(['update:zeitplan'], 'demo.php');
    gleich($rc, 0, 'update:zeitplan im Demo-Modus: Exit 0');
    [$rc, $aus] = $power(['update:automatik', 'einspielen'], 'demo.php');
    pruefe($rc === 1 && str_contains($aus, 'Demo-Modus'), 'update:automatik im Demo-Modus abgelehnt');
    $automatikZurueck();

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
        foreach (['/system/update/suchen', '/system/update/starten', '/system/update/automatik'] as $pfad) {
            gleich($aufruf($port, $s['dose'], 'POST', $pfad, ['_token' => $s['token'], 'ziel' => $V, 'modus' => 'einspielen', 'tage' => ['1'],
                'beginn' => '04:00', 'dauer' => '120', 'karenz' => '0'])['status'], 403, $wer . ': POST ' . $pfad . ' → 403');
        }
    }
    pruefe(!is_file($upd . '/soll/auftrag'), 'ohne Recht kein Auftrag');
    Settings::vergessen();
    gleich(Settings::get(UpdateAutomatik::MODUS), null, 'ohne Recht keine Einstellung');
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

    // Automatische Updates über das Formular
    $automatikZurueck();
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(str_contains($r['text'], 'id="automatik"') && str_contains($r['text'], 'name="modus" value="einspielen"') && str_contains($r['text'], 'name="tage[]" value="7"')
        && str_contains($r['text'], 'type="time" name="beginn" value="04:00"') && str_contains($r['text'], 'name="pause_bis"') && $phpMeldung($r['text']) === null,
        'Seite: Karte „Automatische Updates“ mit Modus, Wochentagen, Beginn und Pause');
    pruefe(preg_match('/name="modus" value="suchen" checked/', $r['text']) === 1, 'Seite: Vorgabe „nur suchen und anzeigen“');
    $felder = ['_token' => $a['token'], 'modus' => 'einspielen', 'tage' => ['1', '2', '3', '4', '5'], 'beginn' => '04:30', 'dauer' => '120', 'karenz' => '24', 'pause_bis' => ''];
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/automatik', $felder);
    Settings::vergessen();
    $e = UpdateAutomatik::einstellungen();
    pruefe($r['status'] === 302 && $r['ziel'] === '/system/update#automatik' && $e['modus'] === 'einspielen' && $e['tage'] === [1, 2, 3, 4, 5] && $e['beginn'] === '04:30',
        'Automatik über das Formular gespeichert, zurück zur Karte');
    $det = json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'system.update.automatik' AND username = ? ORDER BY id DESC LIMIT 1",
        [$kennung . '-admin']), true);
    pruefe(($det['modus'] ?? '') === 'einspielen' && ($det['tage'] ?? '') === '1,2,3,4,5' && ($det['beginn'] ?? '') === '04:30' && ($det['dauer'] ?? 0) === 120
        && ($det['karenz'] ?? -1) === 24 && array_key_exists('pause_bis', (array) $det), 'Automatik: Audit system.update.automatik mit allen Werten');
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/automatik', ['tage' => []] + $felder);
    Settings::vergessen();
    pruefe($r['status'] === 302 && UpdateAutomatik::einstellungen()['tage'] === [1, 2, 3, 4, 5], 'Automatik: ohne Wochentag abgelehnt, nichts geändert');
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(str_contains($r['text'], 'mindestens einen Wochentag'), 'Automatik: Meldung zur Ablehnung');
    pruefe(str_contains($r['text'], 'Nächstes Fenster') && preg_match('/name="modus" value="einspielen" checked/', $r['text']) === 1, 'Seite: nächstes Fenster, einspielen gewählt');
    Settings::put(UpdateAutomatik::VERSCHOBEN, gmdate('c', time() - 15 * 86400));
    Settings::put(UpdateAutomatik::LETZTER, (string) json_encode(['tag' => date('Y-m-d'), 'zeit' => time(), 'ergebnis' => 'verschoben', 'grund' => 'aufbau',
        'ziel' => $V, 'text' => 'Aufbau „' . $kennung . ' Messe“ läuft.', 'gemeldet' => ['aufbau']]));
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(str_contains($r['text'], 'Automatische Updates werden seit 15 Tagen verschoben: Aufbau „' . $kennung . ' Messe“ läuft.')
        && str_contains($r['text'], 'class="nav-hinweis warn"'), 'Seite: Banner und Abzeichen nach 14 Tagen Verschieben');
    $automatikZurueck();
    $schreiben('helfer', $helferText(time(), 'ja', 'nein'));
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(preg_match('/name="modus" value="einspielen" disabled/', $r['text']) === 1 && str_contains($r['text'], 'AUTOMATIK=nein'), 'Seite: AUTOMATIK=nein – einspielen gesperrt, mit Hinweis');
    $schreiben('helfer', $helferText(time()));
    touch($upd . '/zeitplan', time() - 1000);
    $r = $aufruf($port, $a['dose'], 'GET', '/system/update');
    pruefe(str_contains($r['text'], 'Der Zeitplan läuft nicht') && str_contains($r['text'], 'systemctl status powermanagement-update-zeitplan.timer'), 'Seite: stiller Zeitplan mit Befehl');
    touch($upd . '/zeitplan');

    // Schreibsperre – an POST /system/update/automatik: die Einstellung bleibt, wie sie war
    $automatikZurueck();
    $sperrFelder = ['_token' => $a['token'], 'modus' => 'aus', 'tage' => ['1'], 'beginn' => '04:00', 'dauer' => '120', 'karenz' => '24', 'pause_bis' => ''];
    $modus = static function (): ?string {
        Settings::vergessen();
        return Settings::get(UpdateAutomatik::MODUS);
    };
    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/automatik', $sperrFelder, ['Accept: application/json']);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 503 && ($j['wartung'] ?? false) === true && preg_match('/^Retry-After: 30\r?$/mi', $r['kopf']) === 1, 'Schreibsperre: JSON → 503 mit Retry-After 30');
    gleich($modus(), null, 'Schreibsperre: Einstellung unverändert');
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/automatik', $sperrFelder, ['X-Requested-With: XMLHttpRequest']);
    pruefe($r['status'] === 302, 'Schreibsperre: Formular (X-Requested-With, ohne Accept) → Weiterleitung');
    pruefe(str_contains($aufruf($port, $a['dose'], 'GET', '/dashboard')['text'], 'Gerade läuft ein Update'), 'Schreibsperre: Meldung nach der Weiterleitung');
    gleich($modus(), null, 'Schreibsperre: auch über das Formular unverändert');
    gleich($aufruf($port, $a['dose'], 'GET', '/system/update')['status'], 200, 'Schreibsperre: Lesen geht');
    $leer = $ordner . '/dose-leer';
    $seite = $aufruf($port, $leer, 'GET', '/login');
    $r = $aufruf($port, $leer, 'POST', '/login', ['username' => $kennung . '-kunde', 'password' => $kennwort, '_token' => $token($seite['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] !== '/login', 'Schreibsperre: Anmelden geht');

    // Kundenmodus während der Schreibsperre: Was er sperrt, beantwortet weiter
    // der Router (423 bzw. zur Kundenseite) – nur seine offenen Wege bekommen 503.
    $km = $ordner . '/dose-kundenmodus';
    $seite = $aufruf($port, $km, 'GET', '/login');
    $r = $aufruf($port, $km, 'POST', '/login', ['username' => $kennung . '-admin', 'password' => $kennwort, '_token' => $token($seite['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] !== '/login', 'Kundenmodus: Anmeldung in eigener Dose');
    $kmBericht = Database::insert('berichte', ['titel' => $kennung . ' Kundenmodus', 'pruefdatum' => date('Y-m-d'), 'inhalt' => '{}',
        'erstellt_von' => $admin, 'created_at' => gmdate('c')]);
    $kmToken = $token($aufruf($port, $km, 'GET', '/profil')['text']);
    $geraetToken = bin2hex(random_bytes(32));
    $kmGeraet = Database::insert('kundenmodus_geraete', ['token_hash' => hash('sha256', $geraetToken), 'bericht_id' => $kmBericht, 'user_id' => $admin, 'seit' => gmdate('c')]);
    // Die Sperre am Gerät (Cookie), wie nach „Kundenmodus starten“ und neuer Anmeldung.
    file_put_contents($km, "127.0.0.1\tFALSE\t/\tFALSE\t0\tpm_kundenmodus\t{$geraetToken}\n", FILE_APPEND);
    $r = $aufruf($port, $km, 'POST', '/system/update/automatik', ['_token' => $kmToken] + $sperrFelder, ['Accept: application/json']);
    pruefe($r['status'] === 423 && (json_decode($r['text'], true)['kundenmodus'] ?? false) === true, 'Kundenmodus + Schreibsperre: gesperrter Weg (JSON) → 423, nicht 503 (' . $r['status'] . ')');
    $r = $aufruf($port, $km, 'POST', '/system/update/automatik', ['_token' => $kmToken] + $sperrFelder);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $kmBericht . '/kunde', 'Kundenmodus + Schreibsperre: gesperrter Weg (Formular) → Kundenseite');
    $r = $aufruf($port, $km, 'GET', '/system/update/stand', [], ['Accept: application/json']);
    gleich($r['status'], 423, 'Kundenmodus: stand → 423 (update.js lädt dann neu)');
    $r = $aufruf($port, $km, 'POST', '/berichte/' . $kmBericht . '/kunde/unterschreiben', ['_token' => $kmToken], ['Accept: application/json']);
    pruefe($r['status'] === 503 && (json_decode($r['text'], true)['wartung'] ?? false) === true && preg_match('/^Retry-After: 30\r?$/mi', $r['kopf']) === 1,
        'Kundenmodus + Schreibsperre: offener Weg (Unterschrift) → 503 mit Retry-After');
    gleich($modus(), null, 'Kundenmodus + Schreibsperre: Einstellung unverändert');
    Database::execute('DELETE FROM kundenmodus_geraete WHERE id = ?', [$kmGeraet]);
    Database::execute('DELETE FROM berichte WHERE id = ?', [$kmBericht]);

    $schreiben('wartung', UpdateService::KOPF_WARTUNG . "\nseit " . (time() - 4000) . "\nkennung -\n");
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/automatik', $sperrFelder, ['Accept: application/json']);
    pruefe($r['status'] === 200 && (json_decode($r['text'], true)['ok'] ?? false) === true && $modus() === 'aus', 'Schreibsperre älter als 60 min ohne Puls: wirkungslos');
    $schreiben('lauf', $laufText(['puls' => (string) time()]));
    $r = $aufruf($port, $a['dose'], 'POST', '/system/update/automatik', ['modus' => 'suchen'] + $sperrFelder, ['Accept: application/json']);
    pruefe($r['status'] === 503 && $modus() === 'aus', 'Schreibsperre älter als 60 min, Lauf pulst: wirksam');
    $schreiben('wartung', null);
    $schreiben('lauf', null);
    $automatikZurueck();

    // Demo-Modus: Seite mit Hinweis, kein Abzeichen
    $d = $anmelden($demoPort, $kennung . '-admin');
    $r = $aufruf($demoPort, $d['dose'], 'GET', '/system/update');
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Demo-Modus') && $phpMeldung($r['text']) === null, 'Demo: Seite mit „Demo-Modus“');
    pruefe(!str_contains($r['text'], 'nav-hinweis') && !str_contains($r['text'], 'update-zeichen'), 'Demo: kein Abzeichen');
    pruefe(preg_match('/<fieldset disabled>\s*<legend>Modus<\/legend>/', $r['text']) === 1, 'Demo: Automatik nur zum Ansehen');
    $r = $aufruf($demoPort, $d['dose'], 'POST', '/system/update/automatik', ['_token' => $d['token'], 'modus' => 'aus', 'tage' => ['1'], 'beginn' => '04:00',
        'dauer' => '120', 'karenz' => '24']);
    pruefe($r['status'] === 302 && $modus() === null, 'Demo: Automatik lässt sich nicht speichern');

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
