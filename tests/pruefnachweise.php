<?php
declare(strict_types=1);

/**
 * Prüfnachweise (DGUV V3) im Kundenbereich – von Hand hinterlegt.
 *
 * Upload nur PDF (beginnt mit %PDF-, finfo), höchstens 10 MiB, Dateiname
 * bereinigt, gleiche Bytes nur einmal; Angaben (Prüfdatum Pflicht und
 * gültig, nächste Prüfung nicht davor, Ergebnis nur aus der Liste, Mietzeit,
 * Location nur aus den Freigaben des Kunden, Vorgang nur seiner, Inventar
 * nur mit „Datenquellen ansehen“); Sichtbarkeit (nur freigegeben, nur der
 * eigene Kunde, nicht bei beendetem Kunden, nur ausdrücklich genannte
 * Felder); Gültigkeit (abgelaufen, läuft bald ab, gültig, unbekannt;
 * Hinweise zur Mietzeit; tagesgenau in der Zeitzone der Installation, auch
 * über die Zeitumstellung); Aufbewahrung (31.12. plus Jahre, fällige löschen
 * samt Abrufen und unbenutzter Datei, nie eine geteilte; Kommandozeile);
 * Integrität (ein Byte geändert, Datei fehlt, unbenutzte Datei – Befund,
 * nichts ausgeliefert, Kommandozeile mit Exit 1); Inventar löschen.
 *
 * Über HTTP (eigener PHP-Server): Reiter Prüfnachweise, Hochladen als
 * multipart (auch über 2 MB, über 10 MB abgelehnt, kein PDF abgelehnt,
 * ohne CSRF-Token nichts), Bearbeiten mit Fehler (Eingaben bleiben),
 * Freigeben/Zurückziehen, Datei für das Personal als Anhang mit sandbox,
 * Rechte (ansehen ohne hochladen, ohne Kunden-Rechte gar nicht), Löschen;
 * im Kundenbereich die Liste (nur Freigegebenes, Filter je Location) und das
 * PDF (Anhang, neutraler Name, Kopfzeilen, Prüfsumme, Abruf festgehalten).
 *
 * Aus Eventworx (Attrappe bzw. die Eventworx-Demo des Servers): Reiter
 * Vorgänge und „aus Eventworx“ (Rechte, fremde Vorgänge 404, ohne
 * CSRF-Token nichts), Suche mit den Treffern direkt in der Antwort, Rückfrage
 * vor dem Verknüpfen, Kandidaten nur hochgeladene PDFs, Übernahme nur
 * gezeigt ∩ gemerkt ∩ frisch gelesen (sonst ohne Netz abgelehnt), Kunde in
 * Eventworx gewechselt → nichts, schon übernommen (auch bei einem anderen
 * Kunden), höchstens fünf je Durchgang, Herkunft ohne Inhalt, Lösen lässt
 * die Nachweise beim Kunden.
 *
 * Eigene Kunden, Konten, Rollen, Standorte, Inventar und Dateien mit
 * eindeutiger Kennung; am Ende ist alles weg.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Kunden\Kundenbereich;
use App\Kunden\KundenService;
use App\Kunden\KundenSicht;
use App\Kunden\Pruefnachweise;
use App\Messung\Http;
use App\Services\BenutzerService;
use App\Services\Eventworx;
use App\Services\InventarService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;

if (!function_exists('curl_init')) {
    uebersprungen('pruefnachweise', 'die PHP-Erweiterung curl fehlt');
}

$kennung = 'pm-pn-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '1';
$jetzt = time();
$tag = 86400;
$kunden = [];
$personal = [];
$rollen = [];
$sites = [];
$dosen = [];
$inventar = [];
$auditVorher = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log');
$jahreVorher = Settings::get('kundenbereich.nachweise_jahre');
$zoneVorher = (string) Config::get('app.timezone', 'Europe/Berlin');
$konfigVorher = ['app.demo' => Config::get('app.demo'), 'eventworx.basis' => Config::get('eventworx.basis')];
$ewxVorher = [];
foreach ([Eventworx::EINSTELLUNG_HOST, Eventworx::EINSTELLUNG_TOKEN_HOST, Settings::GEHEIM_EVENTWORX_TOKEN] as $s) {
    $ewxVorher[$s] = Settings::get($s);
}
$logDatei = dirname(__DIR__) . '/storage/logs/app.log';
$logVorher = is_file($logDatei) ? (int) filesize($logDatei) : 0;

/** Ein kleines PDF mit Marke – jedes Mal andere Bytes. */
$pdf = static fn (string $name): string => "%PDF-1.4\n% " . $kennung . ' ' . $name . ' ' . bin2hex(random_bytes(4))
    . "\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$iso = static fn (int $ts): string => gmdate('c', $ts);
/** Ortsdatum in n Tagen (negativ: vor n Tagen). */
$tagIn = static fn (int $n): string => (new DateTimeImmutable('now', \App\Support\Zeit::zone()))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
$heute = Pruefnachweise::heute();
$angaben = static fn (array $mehr = []): array => $mehr + ['bezeichnung' => $kennung . ' Verteiler PD63-07', 'inventarnummer' => 'PD63-07',
    'seriennummer' => 'SN-4711', 'norm' => 'DIN EN 50699 (VDE 0702)', 'ergebnis' => '', 'geprueft_am' => $tagIn(-40), 'naechste_pruefung' => $tagIn(325)];

// ------------------------------------------------------------ HTTP-Hilfen

/** Ein Aufruf mit eigener Cookie-Dose; folgt keinen Weiterleitungen. $multipart: Felder (auch CURLFile) als multipart/form-data. */
function anfrage(string $basis, string $dose, string $methode, string $pfad, array $felder = [], bool $multipart = false): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Expect:'],
    ]);
    if ($methode === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $multipart ? $felder : http_build_query($felder));
    }
    $antwort = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $kopfText = substr($antwort, 0, $kopfLaenge);
    preg_match('/^Location:\s*(\S+)/mi', $kopfText, $m);
    return ['status' => $status, 'kopf' => $kopfText, 'text' => substr($antwort, $kopfLaenge), 'ziel' => $m[1] ?? null];
}

function csrfAus(string $html): string
{
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

/** Anmelden; die Cookie-Dose kommt in $dosen. @return array{dose:string,ziel:?string} */
function anmeldenAls(string $basis, string $name, string $kennwort, array &$dosen): array
{
    $dose = (string) tempnam(sys_get_temp_dir(), 'pm-pn-');
    $dosen[] = $dose;
    $seite = anfrage($basis, $dose, 'GET', '/login');
    $r = anfrage($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => csrfAus($seite['text'])]);
    return ['dose' => $dose, 'ziel' => $r['ziel']];
}

function kopfzeile(array $r, string $name): string
{
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $r['kopf'], $m) ? trim($m[1]) : '';
}

/** Die Meldungen (Flash) auf einer Seite. */
function meldungen(string $html): string
{
    preg_match_all('/<div class="flash [a-z]+">([^<]*)<\/div>/', $html, $m);
    return html_entity_decode(implode(' ', $m[1]), ENT_QUOTES, 'UTF-8');
}

/** PHP-Meldungen, die der Server seit dem letzten Aufruf geschrieben hat. @return list<string> */
function meldungenDesServers(string $datei): array
{
    static $gelesen = 0;
    clearstatcache(true, $datei);
    $text = (string) @file_get_contents($datei, false, null, $gelesen);
    $gelesen += strlen($text);
    preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $text, $m);
    return $m[0];
}

/** Eine Datei für einen Upload (multipart). */
function hochladeDatei(string $bytes, string $name, array &$dosen, string $typ = 'application/pdf'): CURLFile
{
    $pfad = (string) tempnam(sys_get_temp_dir(), 'pm-pn-up-');
    $dosen[] = $pfad;
    file_put_contents($pfad, $bytes);
    return new CURLFile($pfad, $typ, $name);
}

/** bin/power aufrufen. @return array{0:int,1:string} Exitcode, Ausgabe */
function power(string ...$argumente): array
{
    $befehl = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/power'], $argumente);
    $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
    $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    return [proc_close($p), (string) $aus];
}

try {
    // ------------------------------------------------ Bestand
    Settings::put('kundenbereich.nachweise_jahre', '3');
    Config::set('app.timezone', 'Europe/Berlin');
    $sites['a'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände A']);
    $sites['b'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände B']);
    $sites['c'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände C']);
    foreach (['k1' => ['Muster Events', 1], 'k2' => ['Stadtmarketing', 1], 'k3' => ['Beendet', 0]] as $k => [$name, $aktiv]) {
        $kunden[$k] = Database::insert('kunden', ['name' => $kennung . ' ' . $name, 'kundennummer' => 'K-' . random_int(10000, 99999), 'aktiv' => $aktiv,
            'created_at' => gmdate('c')]);
    }
    $freigabe = static fn (int $kunde, int $site, int $ab, int $bis): int => Database::insert('freigaben', ['subject_type' => 'kunde', 'subject_id' => $kunde,
        'ziel_art' => 'site', 'ziel_id' => $site, 'ziel_kanal' => '', 'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'gueltig_ab' => $iso($ab), 'gueltig_bis' => $iso($bis),
        'daten_ab' => $iso($ab), 'daten_bis' => $iso($bis), 'notiz' => $kennung, 'created_by' => 'pruefung', 'created_at' => gmdate('c')]);
    $freigabe($kunden['k1'], $sites['a'], $jetzt - 3 * $tag, $jetzt + 7 * $tag);
    $freigabe($kunden['k1'], $sites['c'], $jetzt - 30 * $tag, $jetzt - 20 * $tag);   // abgelaufen – als Location des Nachweises trotzdem wählbar
    $freigabe($kunden['k2'], $sites['b'], $jetzt - 1 * $tag, $jetzt + 5 * $tag);
    $zugang = static fn (string $name, int $kunde): int => Database::insert('users', ['username' => $kennung . '-' . $name, 'full_name' => 'Zugang ' . $name,
        'email' => '', 'password_hash' => password_hash($kennwort, PASSWORD_DEFAULT), 'is_active' => 1, 'must_change_password' => 0, 'kunde_id' => $kunde,
        'created_at' => gmdate('c')]);
    $z1 = $zugang('z1', $kunden['k1']);
    $z2 = $zugang('z2', $kunden['k2']);
    $personal['planung'] = BenutzerService::anlegen($kennung . '-planung', $kennwort, 'planung', false);
    $personal['technik'] = BenutzerService::anlegen($kennung . '-technik', $kennwort, 'technik', false);
    $rollen['ansehen'] = (int) BenutzerService::rolleSpeichern(null, $kennung . ' nur ansehen', '', ['kunden.view'])['id'];
    $rollen['ohneinventar'] = (int) BenutzerService::rolleSpeichern(null, $kennung . ' ohne Inventar', '', ['kunden.view', 'kunden.nachweise'])['id'];
    $rollen['nichts'] = (int) BenutzerService::rolleSpeichern(null, $kennung . ' ohne Kunden', '', ['dashboard.view'])['id'];
    foreach (['ansehen', 'ohneinventar', 'nichts'] as $r) {
        $personal[$r] = BenutzerService::anlegen($kennung . '-' . $r, $kennwort, (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rollen[$r]]), false);
    }
    $vorgang = static fn (int $kunde, string $nummer, ?string $von = null, ?string $bis = null): int => Database::insert('kunden_vorgaenge', ['kunde_id' => $kunde,
        'ewx_host' => $kennung . '.example', 'ewx_id' => bin2hex(random_bytes(12)), 'ewx_art' => 'order', 'ewx_nummer' => $nummer, 'titel' => $kennung,
        'von' => $von, 'bis' => $bis, 'verknuepft_am' => gmdate('c')]);
    $v1 = $vorgang($kunden['k1'], 'AU-' . random_int(1000, 9999), $tagIn(-5), $tagIn(5));
    $v2 = $vorgang($kunden['k2'], 'AU-' . random_int(1000, 9999));
    $inventar['pd'] = Database::insert('inventar', ['art' => 'verteilung', 'kennung' => $kennung . '-PD', 'name' => 'Verteiler', 'seriennummer' => 'INV-SN-1',
        'created_at' => gmdate('c')]);
    // Ein Tanksensor – Kunden sehen keine Tanks, also auch keinen Prüfnachweis dazu.
    $inventar['ts'] = Database::insert('inventar', ['art' => 'tanksensor', 'kennung' => $kennung . '-TS', 'name' => 'Sonde', 'seriennummer' => 'INV-SN-TS',
        'created_at' => gmdate('c')]);
    $nachweis = static fn (int $id): ?array => Database::first('SELECT * FROM pruefnachweise WHERE id = ?', [$id]);
    $dateiZahl = static fn (string $bytes): int => (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweis_dateien WHERE sha256 = ?', [hash('sha256', $bytes)]);
    alsBenutzer($personal['planung']);

    // ------------------------------------------------ Upload
    $bytes1 = $pdf('n1');
    $r = Pruefnachweise::pdfAnlegen($kunden['k1'], $bytes1, '../../' . $kennung . ' Prüf<script>alert(1)</script> "Protokoll".PDF', $angaben(['site_id' => (string) $sites['a']]), false);
    pruefe($r['ok'], 'gültiges PDF hinterlegt: ' . $r['message']);
    $n1 = (int) ($r['id'] ?? 0);
    $z = $nachweis($n1) ?? [];
    gleich([$z['status'] ?? '', $z['quelle'] ?? '', (int) ($z['kunde_id'] ?? 0), (int) ($z['site_id'] ?? 0), $z['geprueft_am'] ?? '', (int) ($z['erstellt_von'] ?? 0), $z['freigegeben_am'] ?? null],
        ['entwurf', 'hand', $kunden['k1'], $sites['a'], $tagIn(-40), $personal['planung'], null], 'Nachweis als Entwurf, Quelle „hand“, Kunde, Location, Prüfdatum, wer');
    pruefe(preg_match('/^[A-Za-z0-9._-]+\.pdf$/', (string) ($z['datei_name'] ?? '')) === 1 && !str_contains((string) $z['datei_name'], '..')
        && !str_contains((string) $z['datei_name'], 'script>'), 'Dateiname bereinigt: ' . ($z['datei_name'] ?? ''));
    $herkunft = json_decode((string) ($z['herkunft'] ?? ''), true);
    gleich([$herkunft['art'] ?? null, $herkunft['name'] ?? null, $herkunft['user_id'] ?? null], ['upload', $z['datei_name'] ?? '', $personal['planung']], 'Herkunft: Upload, Name, wer');
    $d = Database::first('SELECT * FROM pruefnachweis_dateien WHERE id = ?', [(int) ($z['datei_id'] ?? 0)]) ?? [];
    gleich([$d['sha256'] ?? '', (int) ($d['groesse'] ?? 0), base64_decode((string) ($d['daten'] ?? ''), true), $d['art'] ?? ''], [hash('sha256', $bytes1), strlen($bytes1), $bytes1, 'pdf'],
        'Datei Byte für Byte (base64), Prüfsumme, Größe');
    gleich($z['aufbewahren_bis'] ?? '', ((int) substr($tagIn(-40), 0, 4) + 3) . '-12-31', 'aufbewahren bis 31.12. des Prüfjahres + 3');
    $audit = Database::first("SELECT details FROM audit_log WHERE action = 'kunden.nachweis_hochgeladen' AND target_id = ?", [(string) $n1]);
    gleich(json_decode((string) ($audit['details'] ?? '{}'), true), ['kunde_id' => $kunden['k1'], 'nachweis_id' => $n1, 'quelle' => 'hand', 'freigegeben' => false],
        'Audit nur mit IDs (kein Dateiname)');
    $r2 = Pruefnachweise::pdfAnlegen($kunden['k1'], $bytes1, 'zweimal.pdf', $angaben(['bezeichnung' => $kennung . ' Kabeltrommel KT-12']), false);
    pruefe($r2['ok'] && $dateiZahl($bytes1) === 1 && (int) ($nachweis((int) $r2['id'])['datei_id'] ?? 0) === (int) $z['datei_id'],
        'gleiche Bytes zweimal: eine Datei, zwei Nachweise');
    $n2 = (int) $r2['id'];
    foreach ([
        'kein PDF (HTML)'        => '<html>%PDF-1.4</html>',
        'Text'                   => 'Prüfprotokoll ' . $kennung,
        'leer'                   => '',
        'über 10 MiB'            => "%PDF-1.4\n" . str_repeat('0', Pruefnachweise::MAX_BYTES),
    ] as $was => $falsch) {
        $vorher = (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ?', [$kunden['k1']]);
        $r = Pruefnachweise::pdfAnlegen($kunden['k1'], $falsch, 'x.pdf', $angaben(), false);
        pruefe(!$r['ok'] && (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ?', [$kunden['k1']]) === $vorher
            && $dateiZahl($falsch) === 0, 'abgelehnt: ' . $was . ' (' . $r['message'] . ')');
    }
    pruefe(!Pruefnachweise::pdfAnlegen(999999999, $pdf('x'), 'x.pdf', $angaben(), false)['ok'], 'unbekannter Kunde: abgelehnt');
    foreach ([['error' => UPLOAD_ERR_NO_FILE], ['error' => UPLOAD_ERR_INI_SIZE], ['error' => [0], 'tmp_name' => ['/etc/passwd']],
        ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/hostname', 'name' => 'x.pdf', 'size' => 10]] as $datei) {
        pruefe(!Pruefnachweise::pdfHochladen($kunden['k1'], $datei, $angaben(), false)['ok'], 'Upload ohne echte Datei abgelehnt: ' . json_encode($datei));
    }

    // ------------------------------------------------ Angaben
    $falsch = [
        'ohne Bezeichnung'                 => ['bezeichnung' => ' '],
        'ohne Prüfdatum'                   => ['geprueft_am' => ''],
        'Prüfdatum 31.02.2026'             => ['geprueft_am' => '31.02.2026'],
        'Prüfdatum 2026-02-31'             => ['geprueft_am' => '2026-02-31'],
        'Prüfdatum in der Zukunft'         => ['geprueft_am' => $tagIn(1), 'naechste_pruefung' => $tagIn(400)],
        'Prüfdatum 1999'                   => ['geprueft_am' => '1999-05-01', 'naechste_pruefung' => ''],
        'nächste vor dem Prüfdatum'        => ['naechste_pruefung' => $tagIn(-41)],
        'Ergebnis „ok“'                    => ['ergebnis' => 'ok'],
        'Mietzeit verkehrt'                => ['einsatz_von' => $tagIn(5), 'einsatz_bis' => $tagIn(1)],
        'Location eines anderen Kunden'    => ['site_id' => (string) $sites['b']],
        'Location kaputt'                  => ['site_id' => '1x'],
        'Vorgang eines anderen Kunden'     => ['vorgang_id' => (string) $v2],
        'Vorgang kaputt'                   => ['vorgang_id' => 'abc'],
        'Inventar, das es nicht gibt'      => ['inventar_id' => '999999999'],
        'Tanksensor'                       => ['inventar_id' => (string) $inventar['ts']],
        'Hinweis über 500 Zeichen'         => ['hinweis' => str_repeat('x', 501)],
        'Inventarnummer über 80 Zeichen'   => ['inventarnummer' => str_repeat('1', 81)],
    ];
    foreach ($falsch as $was => $mehr) {
        $r = Pruefnachweise::speichern($kunden['k1'], $n2, $mehr + $angaben());
        pruefe(!$r['ok'], 'Angaben abgelehnt: ' . $was . ' (' . $r['message'] . ')');
    }
    gleich($nachweis($n2)['bezeichnung'] ?? '', $kennung . ' Kabeltrommel KT-12', '… und nichts geändert');
    $r = Pruefnachweise::speichern($kunden['k1'], $n2, $angaben(['bezeichnung' => $kennung . ' Kabeltrommel KT-12', 'geprueft_am' => '12.03.2026',
        'naechste_pruefung' => '12.03.2026', 'ergebnis' => 'bestanden', 'site_id' => (string) $sites['c'], 'vorgang_id' => (string) $v1, 'einsatz_von' => '',
        'einsatz_bis' => '', 'inventar_id' => (string) $inventar['pd'], 'inventarnummer' => '', 'seriennummer' => '', 'hinweis' => "Zeile 1\nZeile 2"]));
    $z = $nachweis($n2) ?? [];
    pruefe($r['ok'], 'Angaben gespeichert: ' . $r['message']);
    gleich([$z['geprueft_am'] ?? '', $z['naechste_pruefung'] ?? '', $z['ergebnis'] ?? '', (int) ($z['site_id'] ?? 0), (int) ($z['vorgang_id'] ?? 0),
        $z['einsatz_von'] ?? '', $z['einsatz_bis'] ?? '', (int) ($z['inventar_id'] ?? 0), $z['inventarnummer'] ?? '', $z['seriennummer'] ?? '', $z['hinweis'] ?? '', $z['aufbewahren_bis'] ?? ''],
        ['2026-03-12', '2026-03-12', 'bestanden', $sites['c'], $v1, $tagIn(-5), $tagIn(5), $inventar['pd'], $kennung . '-PD', 'INV-SN-1', 'Zeile 1 Zeile 2', '2029-12-31'],
        'TT.MM.JJJJ gelesen, nächste = Prüfdatum erlaubt, abgelaufene Location des Kunden, Mietzeit aus dem Vorgang, Nummern aus dem Inventar, Aufbewahrung neu');
    $auswahl = array_map(static fn (array $i): int => (int) $i['id'], Pruefnachweise::inventar());
    pruefe(in_array($inventar['pd'], $auswahl, true) && !in_array($inventar['ts'], $auswahl, true), 'Inventar-Auswahl: der Verteiler, kein Tanksensor');
    alsBenutzer($personal['ohneinventar']);
    $r = Pruefnachweise::speichern($kunden['k1'], $n2, $angaben(['bezeichnung' => $kennung . ' Kabeltrommel KT-12', 'geprueft_am' => '2026-03-12', 'inventar_id' => '',
        'site_id' => (string) $sites['c'], 'vorgang_id' => (string) $v1]));
    gleich([$r['ok'], (int) ($nachweis($n2)['inventar_id'] ?? 0)], [true, $inventar['pd']], 'ohne „Datenquellen ansehen“: Inventar bleibt, wie es ist');
    $r = Pruefnachweise::speichern($kunden['k1'], $n2, $angaben(['bezeichnung' => $kennung . ' Kabeltrommel KT-12', 'geprueft_am' => '2026-03-12', 'site_id' => (string) $sites['c']]));
    gleich([$r['ok'], (int) ($nachweis($n2)['vorgang_id'] ?? 0)], [true, $v1], 'ohne Feld „Vorgang“: der Vorgang bleibt');
    gleich(Pruefnachweise::inventar(), [], 'ohne „Datenquellen ansehen“: keine Inventar-Auswahl');
    alsBenutzer($personal['planung']);
    pruefe(!Pruefnachweise::speichern($kunden['k2'], $n2, $angaben())['ok'] && Pruefnachweise::vonKunde($kunden['k2'], $n2) === null,
        'Nachweis über einen anderen Kunden: nicht vorhanden');
    gleich(array_column(Pruefnachweise::standorte($kunden['k1']), 'id'), [$sites['a'], $sites['c']], 'Locations zur Auswahl: die aus den Freigaben des Kunden');

    // ------------------------------------------------ Sichtbarkeit
    $sichtbar = static fn (int $kunde): array => array_column(KundenSicht::nachweise($kunde), 'id');
    gleich([$sichtbar($kunden['k1']), array_column(Pruefnachweise::fuerKunde($kunden['k1'], true), 'id')], [[], []], 'Entwürfe sieht der Kunde nicht');
    $r = Pruefnachweise::status($kunden['k1'], $n1, 'freigegeben');
    $z = $nachweis($n1) ?? [];
    pruefe($r['ok'] && (int) $z['freigegeben_von'] === $personal['planung'] && $z['freigegeben_am'] !== null, 'freigegeben (wer, wann)');
    gleich([$sichtbar($kunden['k1']), $sichtbar($kunden['k2'])], [[$n1], []], 'K1 sieht den freigegebenen Nachweis, K2 nicht');
    pruefe(!Pruefnachweise::status($kunden['k1'], $n1, 'freigegeben')['ok'] && !Pruefnachweise::status($kunden['k1'], $n2, 'zurueckgezogen')['ok']
        && !Pruefnachweise::status($kunden['k1'], $n1, 'entwurf')['ok'] && !Pruefnachweise::status($kunden['k2'], $n1, 'zurueckgezogen')['ok'],
        'kein zweites Freigeben, Zurückziehen nur vom Freigegebenen, kein „entwurf“, nicht über einen anderen Kunden');
    pruefe(Pruefnachweise::status($kunden['k1'], $n1, 'zurueckgezogen')['ok'] && $sichtbar($kunden['k1']) === [], 'zurückgezogen: sofort weg');
    pruefe(Pruefnachweise::status($kunden['k1'], $n1, 'freigegeben')['ok'] && Pruefnachweise::status($kunden['k1'], $n2, 'freigegeben')['ok'], 'wieder freigegeben, n2 auch');
    gleich([array_column(KundenSicht::nachweise($kunden['k1'], $sites['a']), 'id'), array_column(KundenSicht::nachweise($kunden['k1'], $sites['c']), 'id')], [[$n1], [$n2]],
        'je Location nur ihre Nachweise');
    $ort = KundenSicht::location($kunden['k1'], $sites['a']);
    gleich([array_column($ort['nachweise'] ?? [], 'id'), count(KundenSicht::zusammenfassung($kunden['k1'])['nachweise'])], [[$n1], 2],
        'Location A zeigt n1; „sieht derzeit“ zählt beide (n2 an abgelaufener Location bleibt sichtbar)');
    $zeile = KundenSicht::nachweis($kunden['k1'], $n2) ?? [];
    gleich(array_keys($zeile), ['id', 'bezeichnung', 'inventarnummer', 'seriennummer', 'norm', 'ergebnis', 'geprueft_am', 'naechste_pruefung', 'einsatz_von',
        'einsatz_bis', 'hinweis', 'site_id', 'standort', 'quelle', 'gueltigkeit'], 'Kundensicht: nur ausdrücklich genannte Felder');
    $json = json_encode(KundenSicht::nachweise($kunden['k1']), JSON_UNESCAPED_UNICODE);
    pruefe(!str_contains((string) $json, 'herkunft') && !str_contains((string) $json, (string) $nachweis($n1)['datei_name']) && !str_contains((string) $json, 'erstellt')
        && !str_contains((string) $json, 'ewx'), 'Kundensicht ohne Herkunft, Dateiname, Bearbeiter, Eventworx-Kennungen');
    gleich($zeile['quelle'] ?? '', 'von Hand hinterlegt', 'Quelle als Satzteil');
    $pdfKunde = KundenSicht::nachweisPdf($kunden['k1'], $n1);
    gleich([$pdfKunde['bytes'] ?? null, $pdfKunde['sha256'] ?? null, $pdfKunde['name'] ?? null], [$bytes1, hash('sha256', $bytes1), 'Pruefnachweis-PD63-07-' . $tagIn(-40) . '.pdf'],
        'PDF für den Kunden: die Bytes, Prüfsumme, neutraler Name');
    gleich([KundenSicht::nachweisPdf($kunden['k2'], $n1), KundenSicht::nachweis($kunden['k2'], $n1)], [null, null], 'K2 bekommt das PDF von K1 nicht');
    Database::update('kunden', $kunden['k1'], ['aktiv' => 0]);
    KundenService::vergessen();
    gleich([KundenSicht::nachweise($kunden['k1']), KundenSicht::nachweisPdf($kunden['k1'], $n1)], [[], null], 'Kunde beendet: keine Nachweise, kein PDF');
    $personalSicht = Pruefnachweise::fuerPersonal($kunden['k1']);
    pruefe(count($personalSicht) === 2 && array_filter($personalSicht, static fn (array $n): bool => $n['beim_kunden']) === [], 'Personal: beide da, „sieht der Kunde“ nicht');
    Database::update('kunden', $kunden['k1'], ['aktiv' => 1]);
    KundenService::vergessen();
    $personalSicht = Pruefnachweise::fuerPersonal($kunden['k1']);
    gleich(array_column($personalSicht, 'beim_kunden', 'id'), [$n1 => true, $n2 => true], 'Personal: wieder beim Kunden');

    // ------------------------------------------------ Gültigkeit
    $g = static fn (array $n, string $heute): array => Pruefnachweise::gueltigkeit($n + ['naechste_pruefung' => null, 'geprueft_am' => null, 'einsatz_von' => null, 'einsatz_bis' => null], $heute);
    gleich([$g(['naechste_pruefung' => '2026-06-14'], '2026-06-15')['code'], $g(['naechste_pruefung' => '2026-06-15'], '2026-06-15')['code'],
        $g(['naechste_pruefung' => '2026-07-15'], '2026-06-15')['code'], $g(['naechste_pruefung' => '2026-07-16'], '2026-06-15')['code'], $g([], '2026-06-15')['code']],
        ['abgelaufen', 'laeuft_ab', 'laeuft_ab', 'gueltig', 'unbekannt'], 'Gültigkeit: gestern abgelaufen, heute und in 30 Tagen bald, in 31 Tagen gültig, ohne Datum unbekannt');
    gleich([$g(['naechste_pruefung' => '2026-06-15'], '2026-06-15')['text'], $g(['naechste_pruefung' => '2026-06-16'], '2026-06-15')['text'],
        $g(['naechste_pruefung' => '2026-06-14'], '2026-06-15')['text'], $g(['naechste_pruefung' => '2027-01-31'], '2026-06-15')['text']],
        ['nächste Prüfung heute fällig', 'nächste Prüfung am 16.06.2026 (in 1 Tag)', 'Prüffrist abgelaufen am 14.06.2026', 'gültig bis 31.01.2027'], 'Gültigkeit: Texte');
    gleich([$g(['naechste_pruefung' => '2026-04-27'], '2026-03-28')['code'], $g(['naechste_pruefung' => '2026-04-28'], '2026-03-28')['code'],
        $g(['naechste_pruefung' => '2026-11-24'], '2026-10-25')['code'], $g(['naechste_pruefung' => '2026-11-25'], '2026-10-25')['code']],
        ['laeuft_ab', 'gueltig', 'laeuft_ab', 'gueltig'], 'Gültigkeit über die Zeitumstellung: 30 Tage bleiben 30 Tage');
    $miete = ['einsatz_von' => '2026-06-10', 'einsatz_bis' => '2026-06-20'];
    gleich([$g($miete + ['naechste_pruefung' => '2026-06-18', 'geprueft_am' => '2025-06-18'], '2026-06-01')['hinweise'],
        $g($miete + ['naechste_pruefung' => '2026-06-05', 'geprueft_am' => '2025-06-05'], '2026-06-01')['hinweise'],
        $g($miete + ['naechste_pruefung' => '2027-06-12', 'geprueft_am' => '2026-06-12'], '2026-06-15')['hinweise'],
        $g($miete + ['naechste_pruefung' => '2027-06-10', 'geprueft_am' => '2026-06-10'], '2026-06-15')['hinweise'],
        $g($miete + ['naechste_pruefung' => '2026-06-21', 'geprueft_am' => '2025-06-21'], '2026-06-01')['hinweise']],
        [['Die Prüffrist endet in der Mietzeit (18.06.2026).'], ['Die Prüffrist war schon vor Beginn der Mietzeit abgelaufen.'], ['Nach der Übergabe geprüft (am 12.06.2026).'], [], []],
        'Hinweise zur Mietzeit: endet darin, war schon vorher abgelaufen, erst nach der Übergabe geprüft; am Übergabetag und danach keiner');
    gleich([$g(['naechste_pruefung' => '2026-06-14'], '2026-06-15')['symbol'], $g(['naechste_pruefung' => '2026-06-14'], '2026-06-15')['wort']], ['✗', 'abgelaufen'],
        'Symbol und Wort');
    // Mitternacht in Berlin: 23:30 UTC am 1. März ist schon der 2. März, am 1. Juli (Sommerzeit) 22:30 UTC der 2. Juli.
    gleich([Pruefnachweise::heute((int) strtotime('2026-03-01T22:59:59Z')), Pruefnachweise::heute((int) strtotime('2026-03-01T23:00:00Z')),
        Pruefnachweise::heute((int) strtotime('2026-07-01T21:59:59Z')), Pruefnachweise::heute((int) strtotime('2026-07-01T22:00:00Z'))],
        ['2026-03-01', '2026-03-02', '2026-07-01', '2026-07-02'], 'heute: Mitternacht in Europe/Berlin (Winter- und Sommerzeit)');
    $ab = Pruefnachweise::gueltigkeit(['naechste_pruefung' => '2026-03-01', 'geprueft_am' => null, 'einsatz_von' => null, 'einsatz_bis' => null],
        Pruefnachweise::heute((int) strtotime('2026-03-01T23:30:00Z')));
    gleich($ab['code'], 'abgelaufen', 'kurz nach Mitternacht (Ortszeit) ist die Frist von gestern abgelaufen');

    // ------------------------------------------------ Aufbewahrung
    gleich([Pruefnachweise::aufbewahrenBis('2026-07-01'), Pruefnachweise::aufbewahrenBis('2026-12-31'), Pruefnachweise::aufbewahrenBis('2027-01-01')],
        ['2029-12-31', '2029-12-31', '2030-12-31'], 'aufbewahren bis: 31.12. des Prüfjahres + 3');
    Settings::put('kundenbereich.nachweise_jahre', '5');
    gleich(Pruefnachweise::aufbewahrenBis('2026-07-01'), '2031-12-31', 'mit 5 Jahren: 2031-12-31');
    Settings::put('kundenbereich.nachweise_jahre', '3');
    $bytes3 = $pdf('n3');
    $n3 = (int) Pruefnachweise::pdfAnlegen($kunden['k2'], $bytes3, 'n3.pdf', $angaben(['bezeichnung' => $kennung . ' Verlängerung CEE32']), true)['id'];
    $n4 = (int) Pruefnachweise::pdfAnlegen($kunden['k2'], $bytes1, 'geteilt.pdf', $angaben(['bezeichnung' => $kennung . ' geteilte Datei']), true)['id'];
    Database::insert('kunden_abrufe', ['kunde_id' => $kunden['k2'], 'user_id' => $z2, 'username' => $kennung . '-z2', 'art' => 'nachweis', 'ziel_id' => $n3, 'am' => gmdate('c')]);
    $faellig = static fn (): array => array_values(array_intersect(array_column(Pruefnachweise::faellig(), 'id'), [$n1, $n2, $n3, $n4]));
    gleich($faellig(), [], 'nichts fällig');
    pruefe(!Pruefnachweise::aufbewahrungLoeschen($n3) && $nachweis($n3) !== null, 'nicht fälliger Nachweis wird nicht gelöscht');
    Database::execute('UPDATE pruefnachweise SET aufbewahren_bis = ? WHERE id IN (?, ?)', [$tagIn(-1), $n3, $n4]);
    gleich($faellig(), [$n3, $n4], 'Aufbewahrung vorgezogen: n3, n4 fällig');
    $hinweise = Pruefnachweise::wartungHinweise();
    pruefe(count($hinweise) === 1 && str_contains($hinweise[0], 'über der Aufbewahrungsfrist (bin/power kunden:aufbewahrung)'), 'Hinweis für „wartung“: ' . implode(' ', $hinweise));
    pruefe(preg_match("/case 'wartung':.*?Pruefnachweise::wartungHinweise\(\).*?break;/s", (string) file_get_contents(dirname(__DIR__) . '/bin/power')) === 1,
        'bin/power wartung meldet ihn (und löscht nichts)');
    [$code, $aus] = power('kunden:aufbewahrung');
    pruefe($code === 0 && str_contains($aus, 'Nachweis #' . $n3) && str_contains($aus, '--ausfuehren') && $nachweis($n3) !== null
        && !str_contains($aus, $kennung), 'kunden:aufbewahrung zeigt nur (ohne Namen)');
    pruefe(Pruefnachweise::aufbewahrungLoeschen($n3), 'n3 gelöscht');
    gleich([$nachweis($n3), (int) Database::scalar("SELECT COUNT(*) FROM kunden_abrufe WHERE art = 'nachweis' AND ziel_id = ?", [$n3]), $dateiZahl($bytes3)], [null, 0, 0],
        'mit Abrufen und unbenutzter Datei');
    [$code, $aus] = power('kunden:aufbewahrung', '--ausfuehren');
    gleich([$code, $nachweis($n4), $dateiZahl($bytes1), $nachweis($n1) !== null], [0, null, 1, true], 'kunden:aufbewahrung --ausfuehren: n4 weg, die geteilte Datei bleibt (n1, n2)');
    $audit = Database::first("SELECT username, details FROM audit_log WHERE action = 'kunden.nachweis_loeschen' AND target_id = ?", [(string) $n4]) ?? [];
    gleich([$audit['username'] ?? '', json_decode((string) ($audit['details'] ?? '{}'), true)['grund'] ?? ''], ['cli', 'aufbewahrung'], 'Audit: von der Kommandozeile, Grund');

    // ------------------------------------------------ Integrität
    $datei1 = (int) $nachweis($n1)['datei_id'];
    $lose = 0;
    $befunde = static function () use (&$datei1, &$n1, &$n2, &$lose): array {
        $eigene = ['Datei #' . $datei1 . ':', 'Nachweis #' . $n1 . ':', 'Nachweis #' . $n2 . ':', 'Datei #' . $lose . ':'];
        return array_values(array_filter(Pruefnachweise::pruefen(), static fn (string $b): bool
            => array_filter($eigene, static fn (string $e): bool => str_starts_with($b, $e)) !== []));
    };
    gleich($befunde(), [], 'unversehrt: kein Befund');
    $kaputt = $bytes1;
    $kaputt[20] = $kaputt[20] === 'A' ? 'B' : 'A';
    Database::execute('UPDATE pruefnachweis_dateien SET daten = ? WHERE id = ?', [base64_encode($kaputt), $datei1]);
    gleich($befunde(), ['Datei #' . $datei1 . ': Prüfsumme stimmt nicht.'], 'ein Byte geändert: Befund');
    gleich([Pruefnachweise::datei($nachweis($n1)), KundenSicht::nachweisPdf($kunden['k1'], $n1)], [null, null], '… und nichts ausgeliefert');
    clearstatcache(true, $logDatei);
    $zeilen = preg_grep('/Prüfnachweis: gespeicherte Datei weicht/', explode("\n", (string) @file_get_contents($logDatei, false, null, $logVorher))) ?: [];
    pruefe($zeilen !== [] && preg_grep('/' . preg_quote($kennung, '/') . '/', $zeilen) === [], 'Logeintrag nur mit IDs, ohne Inhalt');
    Pruefnachweise::status($kunden['k1'], $n1, 'zurueckgezogen');
    pruefe(!Pruefnachweise::status($kunden['k1'], $n1, 'freigegeben')['ok'], 'beschädigte Datei: nicht freigegeben');
    [$code, $aus] = power('kunden:nachweise-pruefen');
    pruefe($code === 1 && str_contains($aus, 'BEFUND   Datei #' . $datei1 . ': Prüfsumme stimmt nicht.') && !str_contains($aus, $kennung),
        'kunden:nachweise-pruefen: Exit 1, Befund ohne Namen');
    Database::execute('UPDATE pruefnachweis_dateien SET daten = ? WHERE id = ?', [base64_encode($bytes1), $datei1]);
    Database::execute('UPDATE pruefnachweise SET datei_id = ? WHERE id = ?', [999999999, $n2]);
    $lose = Pruefnachweise::dateiSpeichern($pdf('lose'));
    gleich($befunde(), ['Nachweis #' . $n2 . ': Datei #999999999 fehlt.', 'Datei #' . $lose . ': von keinem Nachweis genutzt.'], 'Datei fehlt, Datei ungenutzt: Befunde');
    Database::delete('pruefnachweis_dateien', $lose);
    pruefe(KundenSicht::nachweis($kunden['k1'], $n2) === null && !in_array($n2, $sichtbar($kunden['k1']), true), 'ohne Datei sieht der Kunde den Nachweis nicht');
    Database::execute('UPDATE pruefnachweise SET datei_id = ? WHERE id = ?', [$datei1, $n2]);
    Pruefnachweise::status($kunden['k1'], $n1, 'freigegeben');
    gleich($befunde(), [], 'wiederhergestellt: kein Befund');
    // Entdopplung: dieselben (richtigen) Bytes noch einmal hinterlegt heilen eine beschädigte Zeile –
    // sonst nähme jeder neue Upload die kaputte Zeile, und ein freigegebener Nachweis gäbe 404.
    Database::execute('UPDATE pruefnachweis_dateien SET daten = ? WHERE id = ?', [base64_encode($kaputt), $datei1]);
    $r = Pruefnachweise::pdfAnlegen($kunden['k2'], $bytes1, 'gleich.pdf', $angaben(['site_id' => (string) $sites['b']]), true);
    $nGleich = (int) ($r['id'] ?? 0);
    $zGleich = $nachweis($nGleich) ?? ['id' => 0, 'datei_id' => null, 'datei_name' => ''];
    gleich([$r['ok'], (int) $zGleich['datei_id'], Pruefnachweise::datei($zGleich)['bytes'] ?? null, Pruefnachweise::datei($nachweis($n1))['bytes'] ?? null, $befunde()],
        [true, $datei1, $bytes1, $bytes1, []], 'gleiche Bytes zu einer beschädigten Datei: dieselbe Zeile, mit den frischen Bytes geheilt – auch für n1');
    clearstatcache(true, $logDatei);
    pruefe(preg_grep('/gespeicherte Datei war beschädigt.*"datei_id":' . $datei1 . '/', explode("\n", (string) @file_get_contents($logDatei, false, null, $logVorher))) !== [],
        'Heilung im Protokoll (nur die ID)');
    Pruefnachweise::loeschen($kunden['k2'], $nGleich);

    // ------------------------------------------------ Inventar
    pruefe(InventarService::loeschen($inventar['pd'])['ok'], 'Inventarstück gelöscht');
    $z = $nachweis($n2) ?? ['inventar_id' => 'weg', 'inventarnummer' => ''];
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM inventar WHERE id = ?', [$inventar['pd']]), $z['inventar_id'], $z['inventarnummer']],
        [0, null, 'PD63-07'], 'Inventarstück gelöscht: der Nachweis bleibt, ohne Verweis (die Nummer bleibt)');
    unset($inventar['pd']);

    // ------------------------------------------------ HTTP
    $nK2 = (int) Pruefnachweise::pdfAnlegen($kunden['k2'], $pdf('k2'), 'k2.pdf', $angaben(['bezeichnung' => $kennung . ' Nachweis K2']), true)['id'];
    $port = freierPort();
    $serverFehler = (string) tempnam(sys_get_temp_dir(), 'pm-pn-fehler-');
    $dosen[] = $serverFehler;
    // Größere Uploads als die Vorgabe des eingebauten Servers (2 MB) – wie unter FPM (100 MB).
    hintergrund([PHP_BINARY, '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=20M', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public'],
        ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    if (!erreichbar('127.0.0.1', $port)) {
        throw new RuntimeException('eingebauter PHP-Server startet nicht');
    }
    $basis = 'http://127.0.0.1:' . $port;
    alsBenutzer(null);
    $k1 = $kunden['k1'];
    $reiter = '/kunden/' . $k1 . '?tab=nachweise';
    $planung = anmeldenAls($basis, $kennung . '-planung', $kennwort, $dosen);
    $seite = anfrage($basis, $planung['dose'], 'GET', $reiter);
    pruefe($seite['status'] === 200 && str_contains($seite['text'], $kennung . ' Verteiler PD63-07') && str_contains($seite['text'], 'enctype="multipart/form-data"')
        && str_contains($seite['text'], 'id="nachweis-' . $n1 . '"') && str_contains($seite['text'], 'sieht der Kunde') && !str_contains($seite['text'], 'onclick='),
        'Reiter Prüfnachweise: Liste, Formular zum Hinterlegen');
    pruefe(str_contains($seite['text'], 'Prüfnachweise (2)'), 'Reiter mit der Zahl, die der Kunde sieht');
    // Die Grenze prüft schon der Browser (app.js, data-max-bytes): Über 16 MB
    // käme sonst nicht die Meldung der App, sondern die 413-Seite von nginx.
    pruefe(preg_match('#<input id="nn-datei" type="file" name="datei"[^>]*\sdata-max-bytes="' . Pruefnachweise::MAX_BYTES . '" data-max-text="10 MB">#', $seite['text']) === 1,
        'Dateifeld mit der Grenze für den Browser (10 MB)');
    $token = csrfAus($seite['text']);
    $zahl = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ?', [$k1]);
    $vorher = $zahl();
    $felder = static fn (array $mehr = []): array => $mehr + ['bezeichnung' => $kennung . ' per HTTP', 'inventarnummer' => 'HT-01', 'geprueft_am' => $tagIn(-2),
        'naechste_pruefung' => $tagIn(20), 'ergebnis' => 'bestanden', 'site_id' => (string) $sites['a'], 'einsatz_von' => $tagIn(-1), 'einsatz_bis' => $tagIn(3)];
    // Über 2 MB (die Vorgabe des eingebauten Servers), unter 10 MB.
    $gross = "%PDF-1.4\n% " . $kennung . " gross\n" . str_repeat("% Fuellzeile 0123456789\n", 130000) . "%%EOF\n";
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/hochladen',
        $felder(['_token' => $token, 'freigeben' => '1', 'datei' => hochladeDatei($gross, 'Prüfprotokoll Müller & Söhne <x>.pdf', $dosen)]), true);
    $nh = (int) Database::scalar('SELECT id FROM pruefnachweise WHERE kunde_id = ? AND bezeichnung = ?', [$k1, $kennung . ' per HTTP']);
    $z = $nachweis($nh) ?? [];
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/kunden/' . $k1 . '?tab=nachweise#nachweis-') && $zahl() === $vorher + 1,
        'hochgeladen (' . number_format(strlen($gross) / 1048576, 1) . ' MB) → zurück zum Nachweis');
    gleich([$z['status'] ?? '', $z['datei_name'] ?? '', $z['einsatz_von'] ?? '', $dateiZahl($gross)], ['freigegeben', 'Pruefprotokoll-Mueller-Soehne-x.pdf', $tagIn(-1), 1],
        'gleich freigegeben, Dateiname bereinigt, Mietzeit, Datei gespeichert');
    $vorher = $zahl();
    $zuGross = "%PDF-1.4\n" . str_repeat('0', Pruefnachweise::MAX_BYTES + 10);
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/hochladen',
        $felder(['_token' => $token, 'bezeichnung' => $kennung . ' zu groß', 'datei' => hochladeDatei($zuGross, 'gross.pdf', $dosen)]), true);
    $nachher = anfrage($basis, $planung['dose'], 'GET', $reiter);
    pruefe($zahl() === $vorher && str_contains(meldungen($nachher['text']), 'größer als 10 MB') && str_contains($nachher['text'], 'value="' . $kennung . ' zu groß"'),
        'über 10 MB: abgelehnt, Meldung, Eingaben bleiben im Formular');
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/hochladen',
        $felder(['_token' => $token, 'datei' => hochladeDatei('<html><script>alert(1)</script></html>', 'boese.pdf', $dosen, 'text/html')]), true);
    pruefe($zahl() === $vorher && str_contains(meldungen(anfrage($basis, $planung['dose'], 'GET', $reiter)['text']), 'Nur PDF-Dateien'), 'kein PDF: abgelehnt');
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/hochladen', $felder(['datei' => hochladeDatei($pdf('ohne'), 'ohne.pdf', $dosen)]), true);
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/hochladen', $felder(['_token' => $token]), true);
    gleich($zahl(), $vorher, 'ohne CSRF-Token und ohne Datei: nichts angelegt');
    anfrage($basis, $planung['dose'], 'GET', $reiter);   // Meldungen abholen

    // Bearbeiten (mit Fehler: Eingaben bleiben, Formular offen), Freigeben, Zurückziehen.
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/speichern',
        $felder(['_token' => $token, 'bezeichnung' => $kennung . ' geändert', 'naechste_pruefung' => $tagIn(-10)]));
    $seite = anfrage($basis, $planung['dose'], 'GET', $reiter);
    pruefe($r['ziel'] === '/kunden/' . $k1 . '?tab=nachweise#nachweis-' . $nh && str_contains(meldungen($seite['text']), 'vor dem Prüfdatum')
        && str_contains($seite['text'], 'value="' . $kennung . ' geändert"') && preg_match('/<details class="zugang-bearbeiten" open>/', $seite['text']) === 1
        && ($nachweis($nh)['bezeichnung'] ?? '') === $kennung . ' per HTTP', 'Bearbeiten mit Fehler: nichts geändert, Eingaben im offenen Formular');
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/speichern', $felder(['_token' => $token, 'bezeichnung' => $kennung . ' geändert',
        'hinweis' => $kennung . '-hinweis']));
    gleich([$nachweis($nh)['bezeichnung'] ?? '', $nachweis($nh)['hinweis'] ?? ''], [$kennung . ' geändert', $kennung . '-hinweis'], 'Bearbeiten gespeichert');
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/status', ['_token' => $token, 'status' => 'zurueckgezogen']);
    gleich($nachweis($nh)['status'] ?? '', 'zurueckgezogen', 'zurückgezogen über HTTP');
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/status', ['_token' => $token, 'status' => 'freigegeben']);
    gleich($nachweis($nh)['status'] ?? '', 'freigegeben', 'wieder freigegeben über HTTP');
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/status', ['status' => 'zurueckgezogen']);
    gleich($nachweis($nh)['status'] ?? '', 'freigegeben', 'Status ohne CSRF-Token: unverändert');

    // Die Datei für das Personal: als Anhang, sandbox, Prüfsumme.
    $d = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $k1 . '/nachweise/' . $nh . '/datei');
    gleich([$d['status'], kopfzeile($d, 'Content-Type'), kopfzeile($d, 'Content-Disposition'), kopfzeile($d, 'Content-Security-Policy'), kopfzeile($d, 'Cache-Control'),
        hash('sha256', $d['text'])], [200, 'application/pdf', 'attachment; filename="Pruefprotokoll-Mueller-Soehne-x.pdf"', "default-src 'none'; sandbox", 'private, no-store',
        hash('sha256', $gross)], 'Datei für das Personal: Anhang, sandbox, no-store, die Bytes');
    foreach (['/kunden/' . $k1 . '/nachweise/' . $nK2 . '/datei', '/kunden/' . $kunden['k2'] . '/nachweise/' . $nh . '/datei', '/kunden/' . $k1 . '/nachweise/999999999/datei',
        '/kunden/' . $k1 . '/nachweise/' . $nh . 'x/datei', '/kunden/' . $k1 . '/nachweise/0/datei'] as $pfad) {
        gleich(anfrage($basis, $planung['dose'], 'GET', $pfad)['status'], 404, 'Personal ' . $pfad . ' → 404 (Nachweis eines anderen Kunden, nicht vorhanden, kaputt)');
    }
    gleich(anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k2'] . '/nachweise/' . $nh . '/status', ['_token' => $token, 'status' => 'zurueckgezogen'])['status'], 404,
        'Status über einen anderen Kunden: 404');
    gleich($nachweis($nh)['status'] ?? '', 'freigegeben', '… unverändert');

    // Rechte: nur ansehen; ohne Kunden-Rechte nichts.
    $ansehen = anmeldenAls($basis, $kennung . '-ansehen', $kennwort, $dosen);
    $s = anfrage($basis, $ansehen['dose'], 'GET', $reiter);
    pruefe($s['status'] === 200 && !str_contains($s['text'], 'enctype="multipart/form-data"') && !str_contains($s['text'], '/status"')
        && str_contains($s['text'], '/nachweise/' . $nh . '/datei'), 'nur „kunden.view“: Liste mit PDF, ohne Formulare');
    $at = csrfAus($s['text']);
    gleich([anfrage($basis, $ansehen['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/hochladen', $felder(['_token' => $at, 'datei' => hochladeDatei($pdf('r'), 'r.pdf', $dosen)]), true)['status'],
        anfrage($basis, $ansehen['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/status', ['_token' => $at, 'status' => 'zurueckgezogen'])['status'],
        anfrage($basis, $ansehen['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/loeschen', ['_token' => $at])['status'],
        anfrage($basis, $ansehen['dose'], 'GET', '/kunden/' . $k1 . '/nachweise/' . $nh . '/datei')['status'], $nachweis($nh)['status'] ?? ''],
        [403, 403, 403, 200, 'freigegeben'], 'nur „kunden.view“: hochladen, Status, löschen 403 – Datei 200');
    $nichts = anmeldenAls($basis, $kennung . '-nichts', $kennwort, $dosen);
    gleich([anfrage($basis, $nichts['dose'], 'GET', $reiter)['status'], anfrage($basis, $nichts['dose'], 'GET', '/kunden/' . $k1 . '/nachweise/' . $nh . '/datei')['status']],
        [403, 403], 'ohne Kunden-Rechte: Reiter und Datei 403');
    $ohne = anmeldenAls($basis, $kennung . '-ohneinventar', $kennwort, $dosen);
    $s = anfrage($basis, $ohne['dose'], 'GET', $reiter);
    pruefe($s['status'] === 200 && str_contains($s['text'], 'enctype="multipart/form-data"') && !str_contains($s['text'], 'name="inventar_id"'),
        'ohne „Datenquellen ansehen“: hinterlegen ja, ohne Inventar-Auswahl');
    gleich(anfrage($basis, $planung['dose'], 'GET', '/kundenbereich/pruefnachweise')['status'], 403, 'Personal: /kundenbereich/pruefnachweise 403');
    gleich(meldungenDesServers($serverFehler), [], 'Personal-Seiten ohne PHP-Meldung');

    // Im Kundenbereich: Liste und PDF.
    $kunde = anmeldenAls($basis, $kennung . '-z1', $kennwort, $dosen);
    $liste = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise');
    pruefe($liste['status'] === 200 && str_contains($liste['text'], $kennung . ' geändert') && str_contains($liste['text'], $kennung . ' Verteiler PD63-07')
        && str_contains($liste['text'], 'href="/kundenbereich/pruefnachweise/' . $nh . '/pdf"') && str_contains($liste['text'], 'läuft bald ab')
        && str_contains($liste['text'], $kennung . '-hinweis') && str_contains($liste['text'], 'Ergebnis: bestanden') && str_contains($liste['text'], 'aria-current="page"'),
        'Kundenbereich: Prüfnachweise mit Frist, Hinweis, Ergebnis, PDF; Navigation markiert');
    gleich([kopfzeile($liste, 'Cache-Control'), kopfzeile($liste, 'Referrer-Policy')], ['private, no-store', 'no-referrer'], 'Liste: no-store, no-referrer');
    $durch = array_filter(['Pruefprotokoll-Mueller', 'herkunft', $kennung . '-planung', '/kunden/', 'Entwurf', 'onclick='], static fn (string $m): bool => str_contains($liste['text'], $m));
    gleich(array_values($durch), [], 'Liste ohne Dateinamen, Herkunft, Bearbeiter, Personal-Links, Entwürfe, Inline-JavaScript');
    $gefiltert = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise?location=' . $sites['c']);
    pruefe(str_contains($gefiltert['text'], $kennung . ' Kabeltrommel KT-12') && !str_contains($gefiltert['text'], $kennung . ' geändert')
        && str_contains($gefiltert['text'], 'value="' . $sites['c'] . '" selected'), 'Filter je Location');
    $unsinn = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise?location=' . $sites['b']);
    pruefe($unsinn['status'] === 200 && str_contains($unsinn['text'], $kennung . ' geändert') && !str_contains($unsinn['text'], $kennung . ' Gelände B'),
        'fremde Location im Filter: alle eigenen, kein fremder Name');
    $start = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich');
    pruefe(str_contains($start['text'], 'Ihre Prüfnachweise') && str_contains($start['text'], '1</strong> läuft in den nächsten 30 Tagen ab') && str_contains($start['text'], 'href="/kundenbereich/pruefnachweise"'),
        'Übersicht: Prüfnachweise mit dem, was bald abläuft');
    $ort = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/locations/' . $sites['a']);
    pruefe(str_contains($ort['text'], 'Ihre Prüfnachweise an dieser Location') && str_contains($ort['text'], $kennung . ' geändert')
        && !str_contains($ort['text'], $kennung . ' Kabeltrommel KT-12'), 'Location A: ihre Nachweise, nicht die von C');
    gleich([anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise/' . $nK2 . '/pdf')['status'],
        anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise/' . $nK2 . '/pdf')['text'] === ''], [404, false], 'K1 bekommt das PDF von K2 nicht (404)');
    $abrufe = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM kunden_abrufe WHERE kunde_id = ? AND art = 'nachweis'", [$k1]);
    $vorher = $abrufe();
    $p = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise/' . $nh . '/pdf');
    gleich([$p['status'], kopfzeile($p, 'Content-Type'), kopfzeile($p, 'Content-Disposition'), kopfzeile($p, 'X-Content-Type-Options'), kopfzeile($p, 'Cache-Control'),
        kopfzeile($p, 'Referrer-Policy'), kopfzeile($p, 'Content-Security-Policy'), hash('sha256', $p['text'])],
        [200, 'application/pdf', 'attachment; filename="Pruefnachweis-HT-01-' . $tagIn(-2) . '.pdf"', 'nosniff', 'private, no-store', 'no-referrer', "default-src 'none'; sandbox",
            hash('sha256', $gross)], 'PDF für den Kunden: Anhang, neutraler Name, nosniff, no-store, no-referrer, sandbox, die Bytes');
    $abruf = Database::first("SELECT * FROM kunden_abrufe WHERE kunde_id = ? AND art = 'nachweis' ORDER BY id DESC LIMIT 1", [$k1]) ?? [];
    gleich([$abrufe() - $vorher, (int) ($abruf['ziel_id'] ?? 0), (int) ($abruf['user_id'] ?? 0), $abruf['sha256'] ?? ''], [1, $nh, $z1, hash('sha256', $gross)],
        'Abruf festgehalten: Nachweis, Zugang, Prüfsumme');
    $audit = Database::first("SELECT details FROM audit_log WHERE action = 'kundenbereich.abruf' AND target_type = 'nachweis' AND target_id = ?", [(string) $nh]);
    gleich(json_decode((string) ($audit['details'] ?? '{}'), true), ['art' => 'nachweis', 'ziel_id' => $nh], 'Audit kundenbereich.abruf mit Art und ID');
    $seite = anfrage($basis, $planung['dose'], 'GET', $reiter);
    pruefe(preg_match('/id="nachweis-' . $nh . '".*?data-label="Geladen" class="small">\d{2}\.\d{2}\.\d{4}/s', $seite['text']) === 1, 'Personal sieht „zuletzt geladen“');
    pruefe(str_contains(anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $k1 . '?tab=abrufe')['text'], $kennung . ' geändert'), 'Reiter Abrufe nennt den Nachweis');
    gleich(meldungenDesServers($serverFehler), [], 'Kundenbereich ohne PHP-Meldung');

    // Löschen über HTTP: weg für den Kunden, samt Abrufen; die Datei (nur hier genutzt) mit.
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/' . $nh . '/loeschen', ['_token' => $token]);
    gleich([$nachweis($nh), $dateiZahl($gross), (int) Database::scalar("SELECT COUNT(*) FROM kunden_abrufe WHERE art = 'nachweis' AND ziel_id = ?", [$nh]),
        anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise/' . $nh . '/pdf')['status']], [null, 0, 0, 404], 'gelöscht: Nachweis, Datei, Abrufe; Kunde 404');
    gleich(meldungenDesServers($serverFehler), [], 'Löschen ohne PHP-Meldung');

    // ------------------------------------------------ Vorgänge und Nachweise aus Eventworx über HTTP (Eventworx der Demo)
    $vReiter = '/kunden/' . $k1 . '?tab=vorgaenge';
    $s = anfrage($basis, $planung['dose'], 'GET', $vReiter);
    pruefe($s['status'] === 200 && str_contains($s['text'], 'id="vorgang-' . $v1 . '"') && str_contains($s['text'], 'action="/kunden/' . $k1 . '/eventworx/suche"')
        && str_contains($s['text'], 'action="/kunden/' . $k1 . '/vorgang/' . $v1 . '/loesen"') && str_contains($s['text'], 'action="/kunden/' . $k1 . '/nachweise/eventworx"')
        && str_contains($s['text'], 'Vorgänge (1)') && !str_contains($s['text'], 'onclick='), 'Reiter Vorgänge: Liste, Suche, Lösen, Dateien ansehen');
    $s = anfrage($basis, $planung['dose'], 'GET', $reiter);
    pruefe(str_contains($s['text'], 'id="aus-eventworx"') && str_contains($s['text'], 'name="vorgang" value="' . $v1 . '"'), 'Reiter Prüfnachweise: aus Eventworx je Vorgang');
    $technik = anmeldenAls($basis, $kennung . '-technik', $kennwort, $dosen);
    $s = anfrage($basis, $technik['dose'], 'GET', $vReiter);
    $tt = csrfAus($s['text']);
    pruefe($s['status'] === 200 && !str_contains($s['text'], '/eventworx/suche"') && !str_contains($s['text'], '/loesen"') && str_contains($s['text'], 'PDF-Dateien ansehen'),
        'Elektrotechnik: Vorgänge ansehen und Dateien ansehen, nicht suchen und nicht lösen');
    gleich([anfrage($basis, $technik['dose'], 'POST', '/kunden/' . $k1 . '/eventworx/suche', ['_token' => $tt, 'q' => 'AU-1042'])['status'],
        anfrage($basis, $technik['dose'], 'POST', '/kunden/' . $k1 . '/eventworx/verknuepfen', ['_token' => $tt, 'job_id' => (string) Eventworx::demo()[0]['id']])['status'],
        anfrage($basis, $technik['dose'], 'POST', '/kunden/' . $k1 . '/vorgang/' . $v1 . '/loesen', ['_token' => $tt])['status']], [403, 403, 403],
        'Elektrotechnik: suchen, verknüpfen, lösen 403');
    foreach ([['/kunden/' . $k1 . '/nachweise/eventworx', ['vorgang' => (string) $v2]], ['/kunden/' . $k1 . '/nachweise/eventworx', ['vorgang' => '999999999']],
        ['/kunden/' . $k1 . '/nachweise/eventworx', ['vorgang' => $v1 . 'x']], ['/kunden/' . $kunden['k2'] . '/nachweise/eventworx', ['vorgang' => (string) $v1]],
        ['/kunden/' . $k1 . '/nachweise/eventworx/uebernehmen', ['vorgang' => (string) $v2, 'datei' => ['aaaaaaaaaaaaaaaaaaaaaaaa']]],
        ['/kunden/' . $k1 . '/vorgang/' . $v2 . '/loesen', []], ['/kunden/' . $kunden['k2'] . '/vorgang/' . $v1 . '/loesen', []]] as [$pfad, $f]) {
        gleich(anfrage($basis, $planung['dose'], 'POST', $pfad, ['_token' => $token] + $f)['status'], 404, 'Vorgang eines anderen Kunden bzw. kaputt: ' . $pfad . ' → 404');
    }
    $vorgangDa = static fn (int $v): bool => Database::first('SELECT id FROM kunden_vorgaenge WHERE id = ?', [$v]) !== null;
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/vorgang/' . $v1 . '/loesen', []);
    $ohneToken = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/eventworx/suche', ['q' => 'AU-1042']);
    pruefe($vorgangDa($v1) && $vorgangDa($v2) && !str_contains($ohneToken['text'], 'eventworx-treffer'), 'ohne CSRF-Token: nicht gelöst, nicht gesucht');
    anfrage($basis, $planung['dose'], 'GET', $vReiter);   // Meldungen abholen

    $demoJob = Eventworx::demo()[0];
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/eventworx/suche', ['_token' => $token, 'q' => 'AU-1042']);
    pruefe($r['status'] === 200 && kopfzeile($r, 'Cache-Control') === 'no-store' && str_contains($r['text'], 'id="eventworx-treffer"') && str_contains($r['text'], 'AU-1042')
        && str_contains($r['text'], 'Sommerfestival 2026') && !str_contains($r['text'], 'AN-1050'), 'Demo-Suche: Treffer direkt in der Antwort, no-store, kein Angebot');
    $demoHost = (string) Settings::get(Eventworx::EINSTELLUNG_HOST, '');
    $belegt = Database::first('SELECT k.name FROM kunden_vorgaenge v JOIN kunden k ON k.id = v.kunde_id WHERE v.ewx_host = ? AND v.ewx_id = ?', [$demoHost, $demoJob['id']]);
    $refBelegt = Database::first('SELECT name FROM kunden WHERE ewx_host = ? AND ewx_kunde_ref = ?', [$demoHost, $demoJob['customerRefId']]);
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/eventworx/verknuepfen', ['_token' => $token, 'job_id' => (string) $demoJob['id']]);
    $demoVorgang = static fn (): ?int => ($z = Database::first('SELECT id FROM kunden_vorgaenge WHERE kunde_id = ? AND ewx_id = ?', [$k1, $demoJob['id']])) !== null ? (int) $z['id'] : null;
    if ($belegt !== null || $refBelegt !== null) {
        // Die Demo hat den Auftrag schon einem Demo-Kunden gegeben.
        pruefe($r['status'] === 302 && str_contains(meldungen(anfrage($basis, $planung['dose'], 'GET', $vReiter)['text']), 'schon Kunde')
            && $demoVorgang() === null, 'Demo-Auftrag gehört schon einem anderen Kunden → nicht verknüpft');
    } else {
        pruefe($r['status'] === 200 && str_contains($r['text'], 'id="eventworx-rueckfrage"') && str_contains($r['text'], 'Muster Events GmbH &amp; Co. KG')
            && $demoVorgang() === null, 'Demo: Rückfrage mit dem Namen aus Eventworx, noch nichts verknüpft');
        $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/eventworx/verknuepfen', ['_token' => $token, 'job_id' => (string) $demoJob['id'], 'bestaetigt' => '1']);
        $vDemo = $demoVorgang();
        pruefe($r['status'] === 302 && $vDemo !== null, 'Demo: bestätigt → verknüpft');
        $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/eventworx', ['_token' => $token, 'vorgang' => (string) $vDemo]);
        // Das Sammelprotokoll der Demo (neben ihm liegt ein zweites Prüfprotokoll am Auftrag).
        $sammel = (string) Eventworx::demoDateien()[0]['id'];
        $dateiId = str_contains($r['text'], 'name="datei[]" value="' . $sammel . '"') ? $sammel : '';
        pruefe($r['status'] === 200 && kopfzeile($r, 'Cache-Control') === 'no-store' && str_contains($r['text'], 'DGUV V3 Prüfprotokolle Verteiler und Kabel 2026.pdf')
            && !str_contains($r['text'], 'Auftrag AU-1042.pdf') && $dateiId !== '', 'Demo: Dateien am Auftrag zum Auswählen, ohne den Beleg');
        $zahlE = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ? AND quelle = 'ewx_vorgang'", [$k1]);
        $uebernehmen = static fn (array $f): array => anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/nachweise/eventworx/uebernehmen',
            $f + ['_token' => $token, 'vorgang' => (string) $vDemo, 'datei' => [$dateiId], 'bezeichnung' => '', 'naechste_pruefung' => $tagIn(200), 'freigeben' => '1']);
        $r = $uebernehmen(['geprueft_am' => '']);
        pruefe($r['status'] === 200 && str_contains(meldungen($r['text']), 'Prüfdatum fehlt') && str_contains($r['text'], 'value="' . $dateiId . '" checked') && $zahlE() === 0,
            'Demo: Übernehmen ohne Prüfdatum → dieselbe Liste mit Meldung, Auswahl bleibt, nichts übernommen');
        $r = $uebernehmen(['geprueft_am' => $tagIn(-3)]);
        $ne = (int) Database::scalar("SELECT id FROM pruefnachweise WHERE kunde_id = ? AND quelle = 'ewx_vorgang'", [$k1]);
        pruefe($r['status'] === 302 && $r['ziel'] === '/kunden/' . $k1 . '?tab=nachweise' && $ne > 0 && ($nachweis($ne)['status'] ?? '') === 'freigegeben'
            && ($nachweis($ne)['bezeichnung'] ?? '') === 'DGUV V3 Prüfprotokolle Verteiler und Kabel 2026', 'Demo: übernommen und freigegeben, Bezeichnung aus dem Dateinamen');
        $liste = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise');
        $kp = anfrage($basis, $kunde['dose'], 'GET', '/kundenbereich/pruefnachweise/' . $ne . '/pdf');
        pruefe(str_contains($liste['text'], 'aus Eventworx-Auftrag AU-1042') && $kp['status'] === 200 && str_starts_with($kp['text'], '%PDF-'),
            'Kundenbereich: der Nachweis aus Eventworx mit Quelle, PDF');
        $r = $uebernehmen(['geprueft_am' => $tagIn(-3)]);
        pruefe($zahlE() === 1 && str_contains(meldungen(anfrage($basis, $planung['dose'], 'GET', $reiter)['text']), 'schon übernommen'), 'Demo: dieselbe Datei zweimal → schon übernommen');
    }
    // Lösen über HTTP: Nachweise aus dem Vorgang bleiben, ohne Verweis.
    gleich((int) ($nachweis($n2)['vorgang_id'] ?? 0), $v1, 'vor dem Lösen: Nachweis n2 nennt den Vorgang');
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $k1 . '/vorgang/' . $v1 . '/loesen', ['_token' => $token]);
    pruefe($r['status'] === 302 && !$vorgangDa($v1) && $nachweis($n2) !== null && $nachweis($n2)['vorgang_id'] === null, 'gelöst: Vorgang weg, Nachweis bleibt ohne Verweis');
    $r = Pruefnachweise::speichern($k1, $n2, $angaben(['bezeichnung' => $kennung . ' Kabeltrommel KT-12', 'geprueft_am' => '2026-03-12', 'site_id' => (string) $sites['c']]));
    pruefe($r['ok'], 'Nachweis aus einem gelösten Vorgang lässt sich weiter bearbeiten: ' . $r['message']);
    gleich(meldungenDesServers($serverFehler), [], 'Vorgänge und Eventworx-Seiten ohne PHP-Meldung');

    // ------------------------------------------------ Prüfnachweise aus Eventworx (Dienst, Http-Attrappe)
    alsBenutzer($personal['planung']);
    Config::set('app.demo', false);
    Config::set('eventworx.basis', '');
    $ewxHost = $kennung . '.eventworx.biz';
    $uhr = microtime(true) - 2000.0;
    Eventworx::$uhr = static function () use (&$uhr): float {
        return $uhr;
    };
    Eventworx::$schlafen = static function (float $s) use (&$uhr): void {
        $uhr += $s;
    };
    pruefe(Eventworx::einstellungenSpeichern($ewxHost, 'tok-' . $kennung . '-' . bin2hex(random_bytes(8)))['ok'], 'Eventworx für die Prüfung eingerichtet');
    $jobE = bin2hex(random_bytes(12));
    $refE = bin2hex(random_bytes(12));
    $jobRef = $refE;
    $ewxDateien = [];
    $ewxPfade = [];
    $neueDatei = static function (string $name, array $mehr = [], ?string $bytes = null) use (&$ewxDateien, $jobE, $pdf): string {
        $id = bin2hex(random_bytes(12));
        $ewxDateien[$id] = ['meta' => $mehr + ['id' => $id, 'documentName' => $name, 'contentType' => 'application/pdf', 'timeStamp' => 1783720800000,
            'path' => '/job/documents/P-77', 'ownerDocumentId' => $jobE, 'description' => ''], 'bytes' => $bytes ?? $pdf($name)];
        return $id;
    };
    /** @var ?callable(string):void $waehrend läuft mitten in einer Anfrage an Eventworx (ein zweiter Prozess währenddessen) */
    $waehrend = null;
    Http::$attrappe = static function (string $methode, string $url, array $optionen) use (&$ewxPfade, &$ewxDateien, &$jobRef, &$waehrend, $jobE): array {
        $pfad = substr((string) parse_url($url, PHP_URL_PATH), strlen('/backend/'));
        $ewxPfade[] = $pfad;
        if ($waehrend !== null) {
            ($waehrend)($pfad);
        }
        $json = static fn (array $d): array => ['ok' => true, 'status' => 200, 'fehler' => '', 'rumpf' => (string) json_encode($d)];
        if ($pfad === 'job/' . $jobE) {
            return $json(['success' => true, 'data' => ['id' => $jobE, 'docType' => 'order', 'jobNumber' => 'AU-9901', 'title' => 'Fest']
                + ($jobRef !== null ? ['customerRefId' => $jobRef] : [])]);
        }
        if ($pfad === 'file/list') {
            return $json(['success' => true, 'total' => count($ewxDateien), 'data' => array_values(array_column($ewxDateien, 'meta'))]);
        }
        if (str_starts_with($pfad, 'file/') && isset($ewxDateien[substr($pfad, 5)])) {
            return ['ok' => true, 'status' => 200, 'fehler' => '', 'rumpf' => $ewxDateien[substr($pfad, 5)]['bytes']];
        }
        return ['ok' => true, 'status' => 404, 'fehler' => '', 'rumpf' => '{"success":false,"errorCode":"NOT_FOUND"}'];
    };
    Database::update('kunden', $k1, ['ewx_host' => $ewxHost, 'ewx_kunde_ref' => $refE, 'ewx_kunde_name' => 'Firma']);
    KundenService::vergessen();
    $vE = Database::insert('kunden_vorgaenge', ['kunde_id' => $k1, 'ewx_host' => $ewxHost, 'ewx_id' => $jobE, 'ewx_art' => 'order', 'ewx_nummer' => 'AU-9901',
        'ewx_kunde_ref' => $refE, 'titel' => $kennung, 'von' => $tagIn(-3), 'bis' => $tagIn(3), 'verknuepft_am' => gmdate('c')]);
    $d1 = $neueDatei('Prüfprotokoll Verteiler.pdf');
    $d2 = $neueDatei('Prüfprotokoll Kabel.pdf');
    $neueDatei('Auftrag AU-9901.pdf', ['metaDocType' => 'order']);
    $neueDatei('rechnung.xml', ['contentType' => 'application/xml']);
    $ewxPfade = [];
    $kand = Pruefnachweise::eventworxKandidaten($k1, $vE);
    $ids = array_column($kand['dateien'], 'id');
    sort($ids);
    $soll = [$d1, $d2];
    sort($soll);
    pruefe($kand['ok'] && $ids === $soll && array_column($kand['dateien'], 'schon') === [null, null], 'Kandidaten: nur die hochgeladenen PDFs, noch nicht übernommen');
    gleich($ewxPfade, ['job/' . $jobE, 'file/list'], 'Kandidaten: Vorgang frisch gelesen, dann seine Dateiliste');
    pruefe(Database::scalar('SELECT dokumente_am FROM kunden_vorgaenge WHERE id = ?', [$vE]) !== null, 'Kandidaten: „Dateien zuletzt gelesen“ gesetzt');
    Pruefnachweise::dateienMerken($k1, $vE, $kand['dateien']);
    gleich(array_keys(Pruefnachweise::gemerkteDateien($k1, $vE)[$d1] ?? []), ['name', 'zeit'], 'Merkliste: nur Name und Zeit je Datei');
    $ewxAngaben = static fn (array $mehr = []): array => $mehr + ['bezeichnung' => '', 'geprueft_am' => $tagIn(-5), 'naechste_pruefung' => $tagIn(360), 'norm' => 'DIN EN 50699 (VDE 0702)'];
    $ewxZahl = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM pruefnachweise WHERE quelle = 'ewx_vorgang' AND ewx_host = ?", [$ewxHost]);
    $ewxPfade = [];
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [bin2hex(random_bytes(12))], $ewxAngaben(), false);
    pruefe(!$r['ok'] && $ewxPfade === [] && $ewxZahl() === 0, 'Datei nicht aus der gezeigten Liste → abgelehnt, ohne Eventworx zu fragen');
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [$d1], $ewxAngaben(['geprueft_am' => '']), false);
    pruefe(!$r['ok'] && !empty($r['angaben']) && $ewxPfade === [], 'Angaben falsch → abgelehnt, ohne Eventworx zu fragen');
    $jobRef = bin2hex(random_bytes(12));
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [$d1], $ewxAngaben(), false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'inzwischen zu einem anderen Kunden') && !in_array('file/' . $d1, $ewxPfade, true) && $ewxZahl() === 0,
        'Vorgang gehört in Eventworx inzwischen einem anderen Kunden → nichts geladen, nichts übernommen');
    pruefe(!Pruefnachweise::eventworxKandidaten($k1, $vE)['ok'], '… und keine Kandidaten');
    $jobRef = $refE;
    $ewxPfade = [];
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [$d1], $ewxAngaben(), false);
    $ne = (int) Database::scalar('SELECT id FROM pruefnachweise WHERE ewx_host = ? AND ewx_datei_id = ?', [$ewxHost, $d1]);
    $z = $nachweis($ne) ?? [];
    pruefe($r['ok'] && $ewxPfade === ['job/' . $jobE, 'file/list', 'file/' . $d1], 'übernommen: Vorgang und Liste frisch, dann genau die gewählte Datei: ' . $r['message']);
    gleich([$z['quelle'] ?? '', $z['status'] ?? '', (int) ($z['kunde_id'] ?? 0), (int) ($z['vorgang_id'] ?? 0), $z['bezeichnung'] ?? '', $z['einsatz_von'] ?? '', $z['einsatz_bis'] ?? '',
        $z['datei_name'] ?? '', (int) ($z['erstellt_von'] ?? 0)],
        ['ewx_vorgang', 'entwurf', $k1, $vE, 'Prüfprotokoll Verteiler', $tagIn(-3), $tagIn(3), 'Pruefprotokoll-Verteiler.pdf', $personal['planung']],
        'Nachweis aus Eventworx: Quelle, Entwurf, Vorgang, Bezeichnung aus dem Dateinamen, Mietzeit des Vorgangs, Dateiname bereinigt');
    $herkunft = json_decode((string) ($z['herkunft'] ?? ''), true) ?? [];
    gleich(array_keys($herkunft), ['art', 'host', 'job_id', 'job_nummer', 'datei_id', 'name', 'zeit', 'abgerufen_am', 'abgerufen_von'], 'Herkunft vollständig');
    gleich([$herkunft['host'] ?? '', $herkunft['job_id'] ?? '', $herkunft['job_nummer'] ?? '', $herkunft['datei_id'] ?? '', $herkunft['name'] ?? '', $herkunft['abgerufen_von'] ?? 0],
        [$ewxHost, $jobE, 'AU-9901', $d1, 'Prüfprotokoll Verteiler.pdf', $personal['planung']], 'Herkunft: Mandant, Vorgang, Datei, Name, wer');
    pruefe(strlen((string) $z['herkunft']) < 600 && !str_contains((string) $z['herkunft'], 'PDF-'), 'Herkunft ohne Inhalt');
    gleich(Pruefnachweise::datei($z)['bytes'] ?? null, $ewxDateien[$d1]['bytes'], 'die Bytes aus Eventworx, Byte für Byte');
    gleich(json_decode((string) (Database::first("SELECT details FROM audit_log WHERE action = 'kunden.nachweis_eventworx' AND target_id = ? ORDER BY id DESC LIMIT 1",
        [(string) $k1])['details'] ?? '{}'), true), ['kunde_id' => $k1, 'vorgang_id' => $vE, 'anzahl' => 1, 'freigegeben' => false], 'Audit: nur IDs und Anzahl');
    $ewxPfade = [];
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [$d1], $ewxAngaben(), false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'schon übernommen') && $ewxPfade === [] && $ewxZahl() === 1, 'dieselbe Datei zweimal → schon übernommen, ohne Eventworx zu fragen');
    gleich(array_column(Pruefnachweise::eventworxKandidaten($k1, $vE)['dateien'], 'schon', 'id')[$d1] ?? null, 'dieser', 'Kandidaten: „schon übernommen“');
    // In Eventworx inzwischen weg → nicht geladen; kein PDF → diese Datei nicht, die übrigen schon.
    $weg = $ewxDateien[$d2];
    unset($ewxDateien[$d2]);
    $ewxPfade = [];
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [$d2], $ewxAngaben(), false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'nicht mehr an diesem Vorgang') && !in_array('file/' . $d2, $ewxPfade, true), 'Datei in Eventworx inzwischen weg → nicht geladen');
    $ewxDateien[$d2] = ['meta' => $weg['meta'], 'bytes' => '<html>Anmeldung</html>'];
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, [$d2], $ewxAngaben(), false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'keine PDF-Datei') && $ewxZahl() === 1, 'HTML statt PDF → nicht übernommen');
    $ewxDateien[$d2] = $weg;
    // Sechs gewählt → höchstens fünf; gleiche Bytes nur einmal gespeichert.
    $gleicheBytes = $pdf('gleich');
    $sechs = [];
    for ($i = 1; $i <= 6; $i++) {
        $sechs[] = $neueDatei('Sammel ' . $i . '.pdf', [], $i <= 2 ? $gleicheBytes : null);
    }
    Pruefnachweise::dateienMerken($k1, $vE, Pruefnachweise::eventworxKandidaten($k1, $vE)['dateien']);
    $r = Pruefnachweise::eventworxUebernehmen($k1, $vE, $sechs, $ewxAngaben(['bezeichnung' => $kennung . ' Sammelnachweis']), true);
    pruefe($r['ok'] && count($r['ids']) === Pruefnachweise::EWX_JE_ANFRAGE && str_contains($r['message'], 'Höchstens 5') && $ewxZahl() === 6,
        'sechs gewählt → fünf übernommen, Hinweis auf den Rest: ' . $r['message']);
    gleich((int) Database::scalar('SELECT COUNT(DISTINCT datei_id) FROM pruefnachweise WHERE ewx_datei_id IN (?, ?)', [$sechs[0], $sechs[1]]), 1, 'gleiche Bytes aus zwei Dateien: eine Datei');
    $sicht = array_column(KundenSicht::nachweise($k1), 'quelle', 'id');
    pruefe(($sicht[$r['ids'][0] ?? 0] ?? '') === 'aus Eventworx-Auftrag AU-9901' && !isset($sicht[$ne]), 'freigegeben: der Kunde sieht sie mit Quelle, den Entwurf nicht');
    // Dieselbe Datei für einen anderen Kunden: abgelehnt (der Vorgang zog um).
    KundenService::vorgangLoesen($k1, $vE);
    Database::update('kunden', $k1, ['ewx_host' => '', 'ewx_kunde_ref' => null, 'ewx_kunde_name' => '']);
    Database::update('kunden', $kunden['k2'], ['ewx_host' => $ewxHost, 'ewx_kunde_ref' => $refE, 'ewx_kunde_name' => 'Firma']);
    KundenService::vergessen();
    $zn = $nachweis($ne);
    pruefe($zn !== null && $zn['vorgang_id'] === null, 'Vorgang gelöst: Nachweise aus Eventworx bleiben beim Kunden');
    $vE2 = Database::insert('kunden_vorgaenge', ['kunde_id' => $kunden['k2'], 'ewx_host' => $ewxHost, 'ewx_id' => $jobE, 'ewx_art' => 'order', 'ewx_nummer' => 'AU-9901',
        'ewx_kunde_ref' => $refE, 'titel' => $kennung, 'verknuepft_am' => gmdate('c')]);
    $kand = Pruefnachweise::eventworxKandidaten($kunden['k2'], $vE2);
    gleich(array_column($kand['dateien'], 'schon', 'id')[$d1] ?? null, 'anderer', 'anderer Kunde: Datei „bei einem anderen Kunden übernommen“');
    Pruefnachweise::dateienMerken($kunden['k2'], $vE2, $kand['dateien']);
    $ewxPfade = [];
    $r = Pruefnachweise::eventworxUebernehmen($kunden['k2'], $vE2, [$d1], $ewxAngaben(), false);
    pruefe(!$r['ok'] && str_contains($r['message'], 'anderen Kunden') && $ewxPfade === [] && Pruefnachweise::fuerKunde($kunden['k2'], false) !== []
        && !in_array($d1, array_column(Pruefnachweise::fuerKunde($kunden['k2']), 'ewx_datei_id'), true), 'dieselbe Datei für einen anderen Kunden → abgelehnt');
    pruefe(Pruefnachweise::eventworxUebernehmen($kunden['k2'], $vE, [$d2], $ewxAngaben(), false)['ok'] === false, 'Vorgang eines anderen Kunden → abgelehnt');

    // Während des Wartens auf Eventworx (bis zu Minuten, ohne Sitzungssperre) ändert ein
    // zweiter Prozess etwas: geschrieben wird nur, was danach noch stimmt.
    $nachweisZuDatei = static fn (string $d): int => (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE ewx_host = ? AND ewx_datei_id = ?', [$ewxHost, $d]);
    $d7 = $neueDatei('Waehrenddessen.pdf');
    Pruefnachweise::dateienMerken($kunden['k2'], $vE2, Pruefnachweise::eventworxKandidaten($kunden['k2'], $vE2)['dateien']);
    $waehrend = static function (string $pfad) use ($d7, $vE2): void {
        if ($pfad === 'file/' . $d7) {
            KundenService::vorgangLoesen((int) Database::scalar('SELECT kunde_id FROM kunden_vorgaenge WHERE id = ?', [$vE2]), $vE2);
        }
    };
    $r = Pruefnachweise::eventworxUebernehmen($kunden['k2'], $vE2, [$d7], $ewxAngaben(), true);
    $waehrend = null;
    pruefe(!$r['ok'] && str_contains($r['message'], 'inzwischen nicht mehr') && $nachweisZuDatei($d7) === 0,
        'Vorgang währenddessen gelöst → nichts übernommen, die Datei bleibt für andere frei: ' . $r['message']);
    $vE3 = Database::insert('kunden_vorgaenge', ['kunde_id' => $kunden['k2'], 'ewx_host' => $ewxHost, 'ewx_id' => $jobE, 'ewx_art' => 'order', 'ewx_nummer' => 'AU-9901',
        'ewx_kunde_ref' => $refE, 'titel' => $kennung, 'verknuepft_am' => gmdate('c')]);
    Pruefnachweise::dateienMerken($kunden['k2'], $vE3, Pruefnachweise::eventworxKandidaten($kunden['k2'], $vE3)['dateien']);
    // Ein Datenbankfehler (hier: ein Trigger bricht das Einfügen ab) ist kein „schon übernommen“.
    if (Database::driver() === 'sqlite') {
        Database::pdo()->exec("CREATE TEMP TRIGGER pm_pn_abbruch BEFORE INSERT ON main.pruefnachweise BEGIN SELECT RAISE(ABORT, 'Pruefung'); END");
        try {
            $r = Pruefnachweise::eventworxUebernehmen($kunden['k2'], $vE3, [$d7], $ewxAngaben(), false);
        } finally {
            Database::pdo()->exec('DROP TRIGGER IF EXISTS pm_pn_abbruch');
        }
        clearstatcache(true, $logDatei);
        $log = (string) @file_get_contents($logDatei, false, null, $logVorher);
        pruefe(!$r['ok'] && str_contains($r['message'], 'konnte nicht gespeichert werden') && !str_contains($r['message'], 'schon übernommen') && $nachweisZuDatei($d7) === 0
            && str_contains($log, 'Prüfnachweis aus Eventworx: Datenbankfehler') && !str_contains($log, 'Waehrenddessen'),
            'Datenbankfehler beim Speichern → „nichts übernommen“ (nicht „schon übernommen“), Logeintrag nur mit IDs: ' . $r['message']);
    }
    // Der Kunde wird währenddessen gelöscht (beendet, mit Namen).
    Database::update('kunden', $kunden['k2'], ['aktiv' => 0]);
    KundenService::vergessen();
    $nameK2 = (string) KundenService::find($kunden['k2'])['name'];
    $waehrend = static function (string $pfad) use ($d7, $kunden, $nameK2): void {
        if ($pfad === 'file/' . $d7) {
            KundenService::loeschen($kunden['k2'], $nameK2);
            KundenService::vergessen();
        }
    };
    $r = Pruefnachweise::eventworxUebernehmen($kunden['k2'], $vE3, [$d7], $ewxAngaben(), true);
    $waehrend = null;
    pruefe(!$r['ok'] && str_contains($r['message'], 'inzwischen nicht mehr') && $nachweisZuDatei($d7) === 0 && KundenService::find($kunden['k2']) === null,
        'Kunde währenddessen gelöscht → kein Nachweis ohne Kunden: ' . $r['message']);
    // Verknüpfen genauso: der Vorgang ist wieder frei, der neue Kunde wird beim Lesen des Vorgangs gelöscht.
    $kunden['k9'] = Database::insert('kunden', ['name' => $kennung . ' Kurzlebig', 'kundennummer' => '', 'aktiv' => 0, 'created_at' => gmdate('c')]);
    KundenService::vergessen();
    KundenService::trefferMerken($kunden['k9'], [['id' => $jobE, 'nummer' => 'AU-9901', 'art' => 'order']]);
    $waehrend = static function (string $pfad) use ($jobE, $kunden, $kennung): void {
        if ($pfad === 'job/' . $jobE) {
            KundenService::loeschen($kunden['k9'], $kennung . ' Kurzlebig');
        }
    };
    $r = KundenService::vorgangVerknuepfen($kunden['k9'], $jobE, true, false);
    $waehrend = null;
    pruefe(!$r['ok'] && str_contains($r['message'], 'inzwischen nicht mehr')
        && (int) Database::scalar('SELECT COUNT(*) FROM kunden_vorgaenge WHERE ewx_host = ? AND ewx_id = ?', [$ewxHost, $jobE]) === 0,
        'Verknüpfen, Kunde währenddessen gelöscht → kein Vorgang ohne Kunden: ' . $r['message']);
    $kunden['k10'] = Database::insert('kunden', ['name' => $kennung . ' Neu', 'kundennummer' => '', 'aktiv' => 1, 'created_at' => gmdate('c')]);
    KundenService::vergessen();
    KundenService::trefferMerken($kunden['k10'], [['id' => $jobE, 'nummer' => 'AU-9901', 'art' => 'order']]);
    if (Database::driver() === 'sqlite') {
        Database::pdo()->exec("CREATE TEMP TRIGGER pm_pn_abbruch BEFORE INSERT ON main.kunden_vorgaenge BEGIN SELECT RAISE(ABORT, 'Pruefung'); END");
        try {
            $r = KundenService::vorgangVerknuepfen($kunden['k10'], $jobE, true, false);
        } finally {
            Database::pdo()->exec('DROP TRIGGER IF EXISTS pm_pn_abbruch');
        }
        pruefe(!$r['ok'] && str_contains($r['message'], 'konnte nicht gespeichert werden') && !str_contains($r['message'], 'inzwischen einem Kunden zugeordnet'),
            'Verknüpfen: Datenbankfehler → nicht „inzwischen zugeordnet“: ' . $r['message']);
    }
    $r = KundenService::vorgangVerknuepfen($kunden['k10'], $jobE, true, false);
    pruefe($r['ok'], 'danach: der Vorgang lässt sich mit einem anderen Kunden verknüpfen (nichts sperrt ihn): ' . $r['message']);
    // Bliebe doch etwas ohne Kunden zurück (per SQL), meldet es die Prüfung.
    $waise = Database::insert('kunden_vorgaenge', ['kunde_id' => 999999999, 'ewx_host' => $ewxHost, 'ewx_id' => bin2hex(random_bytes(12)), 'ewx_art' => 'order',
        'ewx_nummer' => 'AU-0', 'ewx_kunde_ref' => '', 'titel' => $kennung, 'verknuepft_am' => gmdate('c')]);
    $waisN = Database::insert('pruefnachweise', ['kunde_id' => 999999999, 'status' => 'entwurf', 'quelle' => 'hand', 'bezeichnung' => $kennung . ' Waise',
        'geprueft_am' => $tagIn(-1), 'created_at' => gmdate('c')]);
    $ohne = array_values(array_filter(Pruefnachweise::pruefen(), static fn (string $b): bool => str_starts_with($b, 'Vorgang #' . $waise . ':') || str_starts_with($b, 'Nachweis #' . $waisN . ':')));
    gleich($ohne, ['Nachweis #' . $waisN . ': ohne Kunde (Kunde #999999999 gibt es nicht).', 'Vorgang #' . $waise . ': ohne Kunde (Kunde #999999999 gibt es nicht).'],
        'Prüfung: Nachweis und Vorgang ohne Kunden sind Befunde');
    Database::delete('kunden_vorgaenge', $waise);
    Database::delete('pruefnachweise', $waisN);
    Http::$attrappe = null;
    Eventworx::$uhr = null;
    Eventworx::$schlafen = null;
    Eventworx::vergessen();
    alsBenutzer(null);

    // Datenschutz: keine Kundennamen und Dateinamen im Audit.
    $treffer = 0;
    foreach (Database::select('SELECT details FROM audit_log WHERE id > ?', [$auditVorher]) as $a) {
        $treffer += str_contains((string) $a['details'], $kennung . ' Muster') || str_contains((string) $a['details'], '.pdf') ? 1 : 0;
    }
    gleich($treffer, 0, 'kein Kundenname und kein Dateiname in audit_log.details');
} finally {
    alsBenutzer(null);
    Http::$attrappe = null;
    Eventworx::$uhr = null;
    Eventworx::$schlafen = null;
    foreach ($konfigVorher as $k => $wert) {
        Config::set($k, $wert);
    }
    foreach ($ewxVorher as $s => $wert) {
        $wert === null ? Settings::forget($s) : Settings::put($s, $wert);
    }
    Config::set('app.timezone', $zoneVorher);
    $jahreVorher === null ? Settings::forget('kundenbereich.nachweise_jahre') : Settings::put('kundenbereich.nachweise_jahre', (string) $jahreVorher);
    // Die eigenen Audit-Einträge – nur über eigene IDs und die Kennung, nie
    // über Zeitfenster und Typ allein (sonst gingen Einträge anderer mit,
    // die währenddessen geschrieben wurden: Anmeldungen, Wartung).
    $eigeneZiele = ['kunde' => $kunden, 'site' => $sites, 'role' => $rollen, 'user' => $personal, 'inventar' => $inventar];
    foreach ($kunden as $id) {
        foreach (Database::select('SELECT id FROM users WHERE kunde_id = ?', [$id]) as $u) {
            $eigeneZiele['user'][] = (int) $u['id'];
        }
    }
    foreach ($kunden as $id) {
        Pruefnachweise::kundeEntfernt($id);
        foreach (['kunden_vorgaenge', 'kunden_abrufe'] as $tabelle) {
            Database::execute('DELETE FROM ' . $tabelle . ' WHERE kunde_id = ?', [$id]);
        }
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$id]);
        foreach (Database::select('SELECT id FROM users WHERE kunde_id = ?', [$id]) as $u) {
            BenutzerService::kontoEntfernen((int) $u['id']);
        }
        Database::delete('kunden', $id);
    }
    foreach ($inventar as $id) {
        Database::delete('inventar', $id);
    }
    foreach ($sites as $id) {
        SiteService::deleteSite($id);
    }
    foreach ($personal as $id) {
        BenutzerService::kontoEntfernen($id);
    }
    foreach ($rollen as $id) {
        BenutzerService::rolleLoeschen($id);
    }
    foreach ($eigeneZiele as $typ => $ids) {
        foreach ($ids as $id) {
            Database::execute('DELETE FROM audit_log WHERE id > ? AND target_type = ? AND target_id = ?', [$auditVorher, $typ, (string) $id]);
        }
    }
    // Nachweise (auch die, die die Prüfung selbst gelöscht hat): über den eigenen Kunden in den Details.
    foreach ($kunden as $id) {
        Database::execute("DELETE FROM audit_log WHERE id > ? AND target_type = 'pruefnachweis' AND (details LIKE ? OR details LIKE ?)",
            [$auditVorher, '%"kunde_id":' . (int) $id . ',%', '%"kunde_id":' . (int) $id . '}%']);
    }
    Database::execute('DELETE FROM audit_log WHERE id > ? AND (username LIKE ? OR details LIKE ?)', [$auditVorher, $kennung . '%', '%' . $kennung . '%']);
    foreach ($dosen as $d) {
        @unlink($d);
    }
}
gleich(array_map(static fn (array $z): string => $z['username'] . ' ' . $z['action'] . ' ' . $z['target_type'] . ' ' . $z['target_id'],
    Database::select('SELECT username, action, target_type, target_id FROM audit_log WHERE id > ? ORDER BY id', [$auditVorher])), [],
    'aufgeräumt: kein Audit-Eintrag dieses Laufs übrig');
gleich((int) Database::scalar('SELECT COUNT(*) FROM kunden WHERE name LIKE ?', [$kennung . '%']) + (int) Database::scalar('SELECT COUNT(*) FROM users WHERE username LIKE ?', [$kennung . '%'])
    + (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE bezeichnung LIKE ?', [$kennung . '%']), 0, 'aufgeräumt: keine Kunden, Konten und Nachweise übrig');

ende('pruefnachweise');
