<?php
declare(strict_types=1);

/**
 * Geräteprüfung – Import, Vorlage und Export der Datenbank des Prüfgeräts
 * (eigene Instanz, Zeitzone Europe/Berlin; nur die erfundene Datenbank aus
 * BenningMuster).
 *
 * A – Lesen (BenningDatei): Zählungen, Quelle (sha256, DB-Version,
 * Prüfgerät, Firmware), Zeiten in UTC (Winter, Sommer, alle Formate),
 * abgeleitetes Ergebnis je Fall (auch „Gerät sagt bestanden, ein Wert nicht“
 * mit Hinweis), Sichtprüfung, Grenzwerte mit Punkt und Einheit ohne
 * float32-Reste, Werte wie vom Gerät („>100.0 MΩ“, „<0.030 mA“), Stammdaten
 * (Kennung getrimmt, leere Kennung übergangen mit Hinweis, Zahlen als
 * Dezimaltext, nie geprüft, außer Betrieb, Fragen), Prüfabläufe und Fragen,
 * nur Text/Wahrheitswerte/Listen/Ganzzahlen, Fingerabdruck stabil.
 *
 * B – Ablehnen: keine SQLite, leer, zu groß, WAL, beschädigt, Ansicht statt
 * Tabelle, fehlende Tabelle und Spalte, zu viele Zeilen (echt und über
 * BenningDatei::$grenzen); berechnete Spalte (auch in einer unbekannten
 * Tabelle – ohne Speicheranstieg, quick_check läuft nicht), Funktions-DEFAULT,
 * Ausdrucks- und Teilindex, CHECK; Texte auf Spaltenlänge gekürzt; fremder
 * Trigger und unbekannte Tabelle → lesbar, aber keine Vorlage; keine Kopie
 * bleibt in storage/cache.
 *
 * C – Vorlage: geleerte Tabellen ohne Zeilen und ohne sqlite_sequence,
 * Einstellungen (Pruefung, Grenzwerte, Systemdaten, DbInfos) per quote()
 * gleich, Schema Text für Text gleich, Trigger da, Seitengröße 1024, Journal
 * delete, Kopf-Bytes 18/19 = 1, integrity_check, keine Namen, Kennungen,
 * Anschriften im Byte-Strom, zweimal gebaut gleiche Bytes.
 *
 * D – Import: Vorschläge (gleiche Kundennummer, sonst „nicht“), Zuordnung,
 * Prüflinge neu (gleiche Kennung zweimal beim selben Kunden → einer, Stammdaten
 * der jüngsten Prüfung), Rechte (neuer Kunde nur mit „Kunden pflegen“),
 * Sperre gegen Doppelklick, Schreibsperre eines Updates, Übernehmen in einer
 * Transaktion (Kunden angelegt bzw. nur leere Felder ergänzt, Prüflinge,
 * Prüfungen, Fälligkeit, Prüfabläufe, Vorlage, gemerkte Zuordnung, Beleg und
 * Audit nur mit Zahlen), idempotent (dieselbe Datei, Sicherung), eine
 * zusätzliche Prüfung, „hier anders“ nur mit Haken und gesehenem Stand,
 * Inventar-Vorschlag (nie Tanksensor), Vorlage mit denselben Bytes wieder
 * aktiv statt doppelt; am Gerät umbenannt → Prüfung „schon da“ beim
 * bisherigen Prüfling mit Hinweis; Vorschau ablegen/verwerfen/aufräumen.
 *
 * E – Export: ohne Vorlage abgelehnt, Besitzer geprüft, Schema und
 * Einstellungen gleich der Vorlage, Kopf-Bytes, Kunden mit PM-EIGEN /
 * Kundennummer / PM-K<id>, Geräte (ohne gültigen Prüfablauf, ohne
 * Leitungsangaben bzw. mit Intervall 0 und mit einem Ablauf, der hier anders
 * steht als in der Vorlage übergangen, gezählt und gemeldet – nie 0 m/0 mm²/
 * 0 Leiter; außer Betrieb, REAL-Spalten als Zahl, Name des Ablaufs genau wie
 * Pruefung_Name), Hersteller angehängt, Fragen; Beleg und Audit; Vorlage mit
 * Funktions-DEFAULT → Meldung; Re-Import des Exports mit zwei „am Gerät“
 * eingetragenen Prüfungen ordnet Kunden über PM-… und die Kundennummer zu,
 * Prüflinge über die Kennung („schon da“), nur die neuen Prüfungen kommen;
 * zweites Prüfgerät mit anderem Ablauf 1000001 → Hinweis, ohne Vorlage
 * bleibt der Prüfling ohne ihn; Kunde gelöscht → gemerkte Zuordnung weg.
 *
 * F – HTTP (eigener PHP-Server): Seiten 200 ohne PHP-Meldung und ohne
 * Inline-JS, Dateifeld mit Grenze und eigenem Rat (app.js unter Node, ohne
 * den Rat für PDF), Upload → Vorschau, ohne Token nichts,
 * abgeschnittenes Formular nichts, Zuordnung ändern, geänderte Zuordnung
 * ohne Blick auf die Prüflinge nichts, Übernehmen, Export als Download
 * (Kopfzeilen), Prüfabläufe (Norm, Zubehör, fremde ID 404, falsches Zubehör),
 * nur sehen → 403 auf Import und Speichern, Schreibsperre eines Updates.
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['timezone' => 'Europe/Berlin']]);
require __DIR__ . '/hilfen/pruefung.php';

use App\Berichte\Pruefgeraete;
use App\Geraetepruefung\BenningDatei;
use App\Geraetepruefung\BenningMuster;
use App\Geraetepruefung\BenningVorlage;
use App\Geraetepruefung\GeraeteImport;
use App\Geraetepruefung\Prueflinge;
use App\Kunden\KundenService;
use App\Services\BenutzerService;
use App\Services\UpdateService;
use App\Support\Config;
use App\Support\Database;

if (!function_exists('curl_init')) {
    uebersprungen('geraetepruefung-import', 'die PHP-Erweiterung curl fehlt');
}

$kennung = 'pm-gpi-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '1';
$ordner = (string) getenv('PM_INSTANZ_ORDNER');
if ($ordner === '' || !is_dir($ordner)) {
    $ordner = sys_get_temp_dir();
}
$ordner .= '/' . $kennung;
mkdir($ordner, 0700, true);
$cache = APP_ROOT . '/storage/cache';
$cacheVorher = array_flip(glob($cache . '/gp-*') ?: []);
$dosen = [];
$nr = 0;

/** Eine erfundene Gerätedatenbank im eigenen Ordner. */
$muster = static function (array $optionen = [], ?string $name = null) use ($ordner, &$nr): array {
    $nr++;
    return BenningMuster::schreiben($ordner . '/' . ($name ?? 'muster-' . $nr . '.db'), $optionen);
};
/** Eine Kopie zum Verändern (beschreibbar geöffnet). */
$kopie = static function (string $quelle, callable $aendern) use ($ordner, &$nr): string {
    $nr++;
    $ziel = $ordner . '/kopie-' . $nr . '.db';
    copy($quelle, $ziel);
    $pdo = new PDO('sqlite:' . $ziel, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $aendern($pdo);
    $pdo = null;
    return $ziel;
};
/** Bytes in eine Datei im eigenen Ordner. */
$datei = static function (string $bytes) use ($ordner, &$nr): string {
    $nr++;
    $ziel = $ordner . '/datei-' . $nr . '.bin';
    file_put_contents($ziel, $bytes);
    return $ziel;
};
/** Jede Zeile einer Tabelle über quote() – REAL genau, Text und Zahl unterscheidbar. */
$zeilen = static function (PDO $pdo, string $tabelle): array {
    $spalten = array_column($pdo->query('PRAGMA table_info("' . $tabelle . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $ausdruck = implode(" || ',' || ", array_map(static fn (string $s): string => 'quote("' . $s . '")', $spalten));
    return $pdo->query('SELECT ' . $ausdruck . ' FROM "' . $tabelle . '" ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
};
$oeffnen = static fn (string $pfad): PDO => new PDO('sqlite:' . $pfad, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = static fn (PDO $pdo): array => $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll();
/** Floats irgendwo in einem Paket? */
$gleitkomma = static function (mixed $wert) use (&$gleitkomma): int {
    if (is_float($wert)) {
        return 1;
    }
    $n = 0;
    foreach (is_array($wert) ? $wert : [] as $w) {
        $n += $gleitkomma($w);
    }
    return $n;
};
$alle = static fn (string $tabelle): int => (int) Database::scalar('SELECT COUNT(*) FROM ' . $tabelle);
$wartungsDatei = rtrim((string) Config::get('update.verzeichnis'), '/') . '/ist/wartung';
$wartung = static function (bool $an) use ($wartungsDatei): void {
    if ($an) {
        @mkdir(dirname($wartungsDatei), 0775, true);
        file_put_contents($wartungsDatei, UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    } elseif (is_file($wartungsDatei)) {
        unlink($wartungsDatei);
    }
};
$erlaubtAlles = ['neu_kunde' => true, 'kunden_ergaenzen' => true, 'inventar' => true];

// ------------------------------------------------------------ HTTP-Hilfen

/** Ein Aufruf mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function anfrage(string $basis, string $dose, string $methode, string $pfad, array $felder = [], bool $multipart = false): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $dose, CURLOPT_COOKIEFILE => $dose,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Expect:']]);
    if ($methode === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $multipart ? $felder : http_build_query($felder));
    }
    $antwort = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $kopf = substr($antwort, 0, $kopfLaenge);
    preg_match('/^Location:\s*(\S+)/mi', $kopf, $m);
    return ['status' => $status, 'kopf' => $kopf, 'text' => substr($antwort, $kopfLaenge), 'ziel' => $m[1] ?? null];
}

function csrfAus(string $html): string
{
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

/** @return string Cookie-Dose der angemeldeten Sitzung */
function anmeldenAls(string $basis, string $name, string $kennwort, array &$dosen): string
{
    $dose = (string) tempnam(sys_get_temp_dir(), 'pm-gpi-');
    $dosen[] = $dose;
    $seite = anfrage($basis, $dose, 'GET', '/login');
    anfrage($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => csrfAus($seite['text'])]);
    return $dose;
}

function kopfzeile(array $r, string $name): string
{
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $r['kopf'], $m) ? trim($m[1]) : '';
}

function meldungen(string $html): string
{
    preg_match_all('/<div class="flash [a-z]+">([^<]*)<\/div>/', $html, $m);
    return html_entity_decode(implode(' ', $m[1]), ENT_QUOTES, 'UTF-8');
}

/** @return list<string> PHP-Meldungen des Servers seit dem letzten Aufruf */
function meldungenDesServers(string $datei): array
{
    static $gelesen = 0;
    clearstatcache(true, $datei);
    $text = (string) @file_get_contents($datei, false, null, $gelesen);
    $gelesen += strlen($text);
    preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $text, $m);
    return $m[0];
}

try {
    $planung = BenutzerService::anlegen($kennung . '-planung', $kennwort, 'planung', false);
    $rolleSehen = (int) BenutzerService::rolleSpeichern(null, $kennung . ' nur sehen', '', ['geraetepruefung.view'])['id'];
    $sehen = BenutzerService::anlegen($kennung . '-sehen', $kennwort, (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rolleSehen]), false);
    alsBenutzer($planung);

    // ================================================ A – Lesen
    $m1 = $muster();
    $f = $m1['faelle'];
    $r = BenningDatei::lesen($m1['datei'], 'Muster_Backup.001');
    pruefe($r['ok'], 'Muster gelesen');
    gleich($r['zahlen'], ['kunden' => 3, 'geraete' => 18, 'pruefungen' => 18], 'Zählungen (Gerät ohne Kennung samt Prüfung übergangen)');
    gleich([$r['format'], $r['quelle']['sha256'], $r['quelle']['groesse'], $r['quelle']['dateiname'], $r['quelle']['db_version'],
        $r['quelle']['messgeraet'], $r['quelle']['messgeraet_sn'], $r['quelle']['firmware']],
        [1, hash_file('sha256', $m1['datei']), filesize($m1['datei']), 'Muster_Backup.001', '14', 'BENNING ST 755+', BenningMuster::MESSGERAET_SN, '1.17 / 1.08.01'],
        'Quelle: sha256, Größe, Name, DB-Version, Prüfgerät, Firmware');
    gleich($gleitkomma($r), 0, 'nur Text, Wahrheitswerte, Listen und Ganzzahlen');
    $pruefungJe = [];
    foreach ($r['pruefungen'] as $p) {
        $pruefungJe[(string) $p['ref']] = $p;
    }
    $geraetJe = [];
    foreach ($r['geraete'] as $g) {
        $geraetJe[(string) $g['ref']] = $g;
    }
    $falsch = [];
    foreach ($f as $name => $fall) {
        if ($fall['ergebnis'] === null || $name === 'leere_kennung') {
            continue;
        }
        $p = $pruefungJe[(string) $fall['ergebnis']] ?? null;
        if ($p === null || $p['ergebnis'] !== $fall['erwartet']) {
            $falsch[] = $name;
        }
    }
    gleich($falsch, [], 'abgeleitetes Ergebnis je Fall wie erwartet');
    $p = static fn (string $fall): array => $pruefungJe[(string) $f[$fall]['ergebnis']];
    gleich([$p('sk1_ok')['geprueft_am'], $p('sk1_ok')['am_geraet'], $p('sk2_ok')['geprueft_am'], $p('leitung_nachpruefung')['geprueft_am'],
        $p('funkt_fehler')['geprueft_am']],
        ['2026-01-12T08:15:00Z', '2026-01-12 09:15:00', '2026-07-14T12:30:00Z', '2026-03-03T10:20:00Z', '2026-03-04T08:00:00Z'],
        'Zeiten: Ortszeit des Geräts → UTC (Winter, Sommer, mit T und Bruchteilen)');
    $abw = $p('abweichung');
    pruefe($abw['geraet_ergebnis'] === ['messung' => true, 'sicht' => true] && $abw['ergebnis'] === 'nicht_bestanden',
        'Gerät meldet bestanden, ein Wert ungültig → nicht bestanden, geraet_ergebnis bleibt');
    pruefe(count(array_filter($r['hinweise'], static fn (string $h): bool => str_contains($h, 'als bestanden'))) === 1
        && count(array_filter($r['hinweise'], static fn (string $h): bool => str_contains($h, 'ohne Prüflings-ID'))) === 1,
        'Hinweise: Abweichung, Gerät ohne Kennung');
    $sicht = $p('sicht_fehler');
    gleich([$sicht['schritte'], $sicht['sicht'], array_column($sicht['sicht_antworten'], 'antwort'), $sicht['ergebnis']],
        [[], 'maengel', ['nicht_ok', 'nicht_ok'], 'nicht_bestanden'], 'Sichtprüfung nicht in Ordnung: keine Schritte, Mängel');
    gleich([$p('ohne_schritte')['schritte'], $p('ohne_schritte')['ergebnis']], [[], 'bestanden'], 'Sequenz ohne Schritte mit success 1 → bestanden');
    $sk1 = $p('sk1_ok');
    gleich(array_column($sk1['schritte'], 'art'), ['RPE', 'RISO-1', 'Funkt.'], 'Schritte in der Reihenfolge des Geräts');
    gleich($sk1['schritte'][0]['titel'], 'Schutzleiterwiderstand', 'Titel aus Messarten');
    $gw = $sk1['schritte'][0]['grenzwerte'][0] ?? ['name' => '', 'wert' => ''];
    pruefe($gw['name'] === 'R_PE max' && preg_match('/^\d+(\.\d{1,6})? Ω$/', $gw['wert']) === 1 && !str_contains($gw['wert'], '0000'),
        'Grenzwert mit Punkt und Einheit, ohne float32-Rest (' . $gw['wert'] . ')');
    gleich(array_column($sk1['schritte'][1]['grenzwerte'], 'wert'), ['1 MΩ', '500 V'], 'RISO-1: R_ISO und U_ISO');
    $werteText = static fn (array $pr): array => array_merge(...array_map(static fn (array $s): array => array_column($s['werte'], 'wert'), $pr['schritte']));
    pruefe(in_array('>100.0 MΩ', $werteText($sk1), true) && in_array('<0.030 mA', $werteText($p('sk2_ok')), true),
        'Werte wie vom Gerät („>100.0 MΩ“, „<0.030 mA“)');
    gleich([$p('admin')['pruefer'], $p('admin')['auftrag'], $p('admin')['ort'], $p('admin')['bemerkung']],
        ['Admin', 'A-2026-0042', 'Halle 3', 'Stecker erneuert'], 'Prüfer, Auftrag, Ort, Bemerkung');
    $g101 = $geraetJe[(string) $f['sk1_ok']['geraet']];
    gleich([$g101['kennung'], $g101['kunde_ref'], $g101['hersteller'], $g101['typ'], $g101['modell'], $g101['seriennummer'], $g101['abteilung'],
        $g101['gebaeude'], $g101['nennleistung'], $g101['schutzklasse'], $g101['intervall_monate'], $g101['fragen'], $g101['letzte_pruefung'],
        $g101['naechste_pruefung'], $g101['ablauf_geraet_id'], $g101['ausser_betrieb']],
        ['000101', '1', 'Musterwerk', 'NM-1500', 'Pro', 'MW-0001', 'Licht', 'Lager A', '1.6', '1', '12', ['1', '2'], '2026-01-12', '2027-01-12', '1', false],
        'Stammdaten eines Geräts (Zahlen als Dezimaltext, Fragen, Fälligkeit laut Gerät)');
    $g103 = $geraetJe[(string) $f['leitung_ok']['geraet']];
    gleich([$g103['kennung'], $g103['leitungslaenge'], $g103['fragen']], ['000103', '10', []], 'Kennung getrimmt, Leitung 10 m, ohne Fragen');
    $g113 = $geraetJe[(string) $f['ohne_pruefung']['geraet']];
    gleich([$g113['schutzklasse'], $g113['letzte_pruefung'], $g113['naechste_pruefung']], ['3', '', ''], 'nie geprüft: „1900“ → leer, SK III');
    pruefe($geraetJe[(string) $f['ausser_betrieb']['geraet']]['ausser_betrieb'] === true, 'außer Betrieb');
    gleich(array_keys($g101['technik']), array_keys(BenningDatei::TECHNIK), 'technik: die Felder, die hier niemand pflegt');
    gleich([count($r['pruefablaeufe']), count($r['fragen']), $r['vorlage']], [53, 20, ['moeglich' => true, 'grund' => '']],
        'Prüfabläufe (50 ab Werk + 3 am Gerät), 20 Fragen, taugt als Vorlage');
    $ablauf1000002 = array_values(array_filter($r['pruefablaeufe'], static fn (array $a): bool => $a['geraet_id'] === '1000002'))[0] ?? [];
    pruefe(($ablauf1000002['ungueltig'] ?? false) === true && is_string($ablauf1000002['ablauf'] ?? null) && ctype_digit($ablauf1000002['ablauf']),
        'Prüfablauf am Gerät ungültig; Bitmaske als Dezimaltext');
    $r2 = BenningDatei::lesen($m1['datei'], 'x.db');
    gleich(array_column($r2['pruefungen'], 'schluessel'), array_column($r['pruefungen'], 'schluessel'), 'Fingerabdrücke stabil');
    $mGleich = $muster([], 'muster-gleich.db');
    pruefe(hash_file('sha256', $mGleich['datei']) === hash_file('sha256', $m1['datei']), 'Muster: gleicher Startwert, gleiche Bytes');

    // ================================================ B – Ablehnen
    $abgelehnt = static function (string $pfad, string $erwartet, string $text): void {
        $e = BenningDatei::lesen($pfad, 'x.db');
        $alles = $e['message'] . ' ' . implode(' ', $e['befunde'] ?? []);
        pruefe(!$e['ok'] && str_contains($alles, $erwartet), $text . ' (' . $alles . ')');
    };
    $abgelehnt($datei(random_bytes(4096)), 'keine SQLite-Datei', 'keine SQLite-Datei');
    $abgelehnt($datei(''), 'leer', 'leere Datei');
    $abgelehnt($ordner . '/gibt-es-nicht.db', 'fehlt', 'Datei fehlt');
    $gross = $ordner . '/gross.db';
    $h = fopen($gross, 'wb');
    fwrite($h, "SQLite format 3\0");
    ftruncate($h, BenningDatei::MAX_BYTES + 1);
    fclose($h);
    $abgelehnt($gross, 'größer als die Grenze dieser Installation (15 MB, rund 10 000 Prüfungen)', 'zu groß (15 MB + 1 Byte)');
    unlink($gross);
    $wal = $ordner . '/wal.db';
    $bytes = (string) file_get_contents($m1['datei']);
    $bytes[18] = "\x02";
    $bytes[19] = "\x02";
    file_put_contents($wal, $bytes);
    $abgelehnt($wal, 'WAL', 'WAL-Modus');
    $kaputt = (string) file_get_contents($m1['datei']);
    $kaputt = substr($kaputt, 0, 1024 * 4) . str_repeat("\xA5", 1024 * 40) . substr($kaputt, 1024 * 44);
    $abgelehnt($datei($kaputt), 'beschädigt', 'beschädigte Datenbank');
    $abgelehnt($kopie($m1['datei'], static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE Kunden');
        $pdo->exec("CREATE VIEW Kunden AS SELECT 1 AS Kunden_Index, 'x' AS Kunden_Name");
    }), 'Ansicht (view)', 'Ansicht statt Tabelle');
    $abgelehnt($kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('DROP TABLE SqProcValues')), 'Die Tabelle SqProcValues fehlt', 'Pflichttabelle fehlt');
    $abgelehnt($kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('ALTER TABLE Geraete DROP COLUMN Geraete_ID')),
        'fehlt die Spalte Geraete_ID', 'Pflichtspalte fehlt');
    $abgelehnt($kopie($m1['datei'], static function (PDO $pdo): void {
        $st = $pdo->prepare('INSERT INTO Kunden (Kunden_Index, Kunden_Name) VALUES (?, ?)');
        $pdo->exec('BEGIN');
        for ($i = 100; $i < 100 + BenningDatei::GRENZEN['kunden']; $i++) {
            $st->execute([$i, 'Musterkunde ' . $i]);
        }
        $pdo->exec('COMMIT');
    }), 'Kunden: ' . (BenningDatei::GRENZEN['kunden'] + 3), 'mehr als 5 000 Kunden');
    BenningDatei::$grenzen = ['werte' => 100];
    $abgelehnt($m1['datei'], 'Messwerte: ' . $m1['werte'], 'mehr Messwerte als die Grenze');
    BenningDatei::$grenzen = null;
    $lang = $kopie($m1['datei'], static function (PDO $pdo): void {
        $pdo->exec("UPDATE Geraete SET Geraete_Bemerkung = '" . str_repeat('lang ', 200) . "', Geraete_Bezeichnung = '" . str_repeat('B', 300)
            . "' WHERE Geraete_ID = '000101'");
    });
    $rl = BenningDatei::lesen($lang, 'x.db');
    $gl = array_values(array_filter($rl['geraete'], static fn (array $g): bool => $g['kennung'] === '000101'))[0] ?? [];
    gleich([mb_strlen($gl['bemerkung'] ?? ''), mb_strlen($gl['bezeichnung'] ?? '')], [Prueflinge::TEXTE['bemerkung'] - 1, 120],
        'Texte auf die Spaltenlänge gekürzt (ohne Leerzeichen am Ende)');
    $boese = $kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('CREATE TRIGGER boese AFTER INSERT ON Kunden BEGIN DELETE FROM Pruefung; END'));
    $rb = BenningDatei::lesen($boese, 'x.db');
    pruefe($rb['ok'] && $rb['vorlage']['moeglich'] === false && str_contains($rb['vorlage']['grund'], 'boese'),
        'fremder Trigger: lesbar (nur lesend), aber keine Vorlage');
    $gb = GeraeteImport::lesen($boese, 'x.db');
    pruefe($gb['ok'] && $gb['paket']['vorlage_daten'] === null && !BenningVorlage::bereinigen($boese)['ok'], '… und nichts zum Bereinigen');
    $fremd = $kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('CREATE TABLE Notizen (text TEXT)'));
    $rf = BenningDatei::lesen($fremd, 'x.db');
    pruefe($rf['ok'] && !$rf['vorlage']['moeglich'] && str_contains($rf['vorlage']['grund'], 'Notizen'), 'unbekannte Tabelle: keine Vorlage');
    // Nichts, was beim Prüfen rechnet (quick_check rechnet jede berechnete Spalte jeder Zeile – im
    // Speicher von SQLite, an memory_limit vorbei): vor quick_check abgelehnt, ohne Speicheranstieg.
    $rssVorher = (int) (getrusage()['ru_maxrss'] ?? 0);
    $berechnet = $kopie($m1['datei'], static function (PDO $pdo): void {
        // Erst die Zeile, dann die Spalte: INSERT rechnete sie schon hier aus, ALTER TABLE nicht.
        $pdo->exec('CREATE TABLE Notizen (a INTEGER)');
        $pdo->exec('INSERT INTO Notizen (a) VALUES (1)');
        $pdo->exec("ALTER TABLE Notizen ADD COLUMN b TEXT GENERATED ALWAYS AS (printf('%200000000c', 65)) VIRTUAL");
    });
    $abgelehnt($berechnet, 'berechnete Spalte', 'berechnete Spalte in einer unbekannten Tabelle');
    pruefe((int) (getrusage()['ru_maxrss'] ?? 0) - $rssVorher < 50 * 1024, '… ohne messbaren Speicheranstieg (quick_check lief nicht)');
    pruefe(!BenningVorlage::bereinigen($berechnet)['ok'], '… und keine Vorlage daraus');
    $mitDefault = static function (PDO $pdo): void {
        // Kunden neu mit einem Funktions-DEFAULT (beim Einfügen des Exports ausgewertet)
        $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'Kunden'")->fetchColumn();
        $spalten = implode(', ', array_map(static fn (string $s): string => '"' . $s . '"',
            array_column($pdo->query('PRAGMA table_info(Kunden)')->fetchAll(PDO::FETCH_ASSOC), 'name')));
        $pdo->exec('ALTER TABLE Kunden RENAME TO Kunden_alt');
        $pdo->exec((string) preg_replace('/\[Kunden_Index\] INTEGER NOT NULL/', "[Kunden_Index] INTEGER NOT NULL, Notiz TEXT DEFAULT (printf('%10c', 65))", $sql, 1));
        $pdo->exec('INSERT INTO Kunden (' . $spalten . ') SELECT ' . $spalten . ' FROM Kunden_alt');
        $pdo->exec('DROP TABLE Kunden_alt');
    };
    $abgelehnt($kopie($m1['datei'], $mitDefault), 'berechneten Vorgabewert', 'Funktions-DEFAULT in Kunden');
    $abgelehnt($kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('CREATE INDEX idx_ausdruck ON Pruefung (lower(Pruefung_Name))')),
        'über einen Ausdruck', 'Ausdrucksindex in Pruefung');
    $abgelehnt($kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('CREATE INDEX idx_teil ON Pruefung (Pruefung_ID) WHERE Pruefung_ID > 0')),
        'Teil der Zeilen', 'Teilindex in Pruefung');
    $abgelehnt($kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec('CREATE TABLE Pruefregel (a INTEGER CHECK (a > 0))')),
        'Prüfbedingung', 'CHECK in einer Tabelle');
    pruefe(BenningDatei::lesen($m1['datei'], 'x.db')['ok'], 'das Schema des Geräts (DEFAULT 0, Indizes über Spalten) bleibt lesbar');
    $reste = array_values(array_filter(glob($cache . '/gp-*.db*') ?: [], static fn (string $d): bool => !isset($cacheVorher[$d])));
    gleich($reste, [], 'keine Kopie bleibt in storage/cache');

    // ================================================ C – Vorlage
    $v = BenningVorlage::bereinigen($m1['datei']);
    pruefe($v['ok'] && $v['groesse'] <= BenningVorlage::MAX_BYTES && strlen($v['bytes']) === $v['groesse'], 'Vorlage gebaut (' . ($v['groesse'] ?? 0) . ' Bytes)');
    $vDatei = $ordner . '/vorlage.db';
    file_put_contents($vDatei, $v['bytes']);
    $vp = $oeffnen($vDatei);
    $mp = $oeffnen($m1['datei']);
    $leer = [];
    foreach ($schema($vp) as $o) {
        if ($o['type'] === 'table' && BenningVorlage::wirdGeleert($o['name']) && (int) $vp->query('SELECT COUNT(*) FROM "' . $o['name'] . '"')->fetchColumn() > 0) {
            $leer[] = $o['name'];
        }
    }
    gleich($leer, [], 'geleerte Tabellen ohne Zeilen (auch die Kopie Geraete2023…)');
    gleich($vp->query("SELECT name FROM sqlite_sequence WHERE name IN ('Ergebnisse', 'Sequences', 'SqProcedures', 'SqProcValues')")->fetchAll(PDO::FETCH_COLUMN), [],
        'sqlite_sequence der geleerten Tabellen weg');
    pruefe((int) $vp->query("SELECT COUNT(*) FROM sqlite_sequence WHERE name = 'Manufacturers'")->fetchColumn() === 1, 'sqlite_sequence der übrigen bleibt');
    gleich(array_column($schema($vp), 'sql'), array_column($schema($mp), 'sql'), 'Schema Text für Text wie in der Datei des Geräts');
    $gleicheWerte = [];
    foreach (BenningVorlage::EINSTELLUNGEN as $t) {
        $gleicheWerte[$t] = $zeilen($vp, $t) === $zeilen($mp, $t) && $zeilen($vp, $t) !== [];
    }
    gleich($gleicheWerte, array_fill_keys(BenningVorlage::EINSTELLUNGEN, true), 'Einstellungen per quote() unverändert');
    gleich([(int) $vp->query('PRAGMA page_size')->fetchColumn(), $vp->query('PRAGMA journal_mode')->fetchColumn(), ord($v['bytes'][18]), ord($v['bytes'][19]),
        $vp->query('PRAGMA integrity_check')->fetchColumn(), (int) $vp->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'")->fetchColumn()],
        [1024, 'delete', 1, 1, 'ok', 12], 'Seitengröße 1024, Journal delete, Kopf 1/1, integrity_check, die 12 Trigger');
    $namen = ['Beispiel Events', 'Testbühne', 'Musterstraße', BenningMuster::FIRMA, BenningMuster::PRUEFER, 'Nebelmaschine', '000101', 'K-20001',
        'info@muster-vt.example', 'Beispielhausen', 'Stecker erneuert', 'Halle 3'];
    gleich(array_values(array_filter($namen, static fn (string $n): bool => str_contains($v['bytes'], $n))), [],
        'keine Namen, Kennungen, Anschriften im Byte-Strom der Vorlage');
    pruefe(hash_equals($v['einstellungen'], BenningVorlage::einstellungen($mp)), 'Fingerabdruck der Einstellungen wie in der Datei');
    gleich(BenningVorlage::bereinigen($m1['datei'])['sha256'], $v['sha256'], 'zweimal gebaut: dieselben Bytes');
    $vp = null;
    $mp = null;

    // ================================================ D – Import
    pruefe(!BenningVorlage::exportieren(['eigen'], 'x')['ok'], 'Export ohne Vorlage abgelehnt');
    $kK2 = (int) KundenService::speichern(null, ['name' => $kennung . ' Beispiel', 'kundennummer' => 'K-20001', 'aktiv' => 1])['id'];
    Database::update('kunden', $kK2, ['ort' => 'schon gesetzt']);
    KundenService::vergessen();
    $g = GeraeteImport::lesen($m1['datei'], 'Muster.db');
    pruefe($g['ok'] && is_array($g['paket']['vorlage_daten']), 'GeraeteImport::lesen mit Vorlage');
    $paket = $g['paket'];
    $s = GeraeteImport::pruefen($paket, null, $erlaubtAlles);
    $vorschlag = array_column($s['kunden'], 'vorschlag', 'ref');
    gleich($vorschlag, [1 => 'nicht', 2 => 'k' . $kK2, 3 => 'nicht'], 'Vorschläge: gleiche Kundennummer, sonst „nicht übernehmen“');
    gleich([count($s['prueflinge']), $s['zahlen']['geraete_uebergangen'], $s['vorlage']['vorauswahl']], [4, 18 - 4, true],
        'nur der vorgeschlagene Kunde zählt (4 Geräte, 14 nicht zugeordnet); Vorlage vorausgewählt (noch keine)');
    $zuordnung = ['1' => 'eigen', '2' => 'k' . $kK2, '3' => 'neu'];
    $s = GeraeteImport::pruefen($paket, $zuordnung, $erlaubtAlles);
    gleich([count($s['prueflinge']), $s['zahlen']['prueflinge_neu'], $s['zahlen']['pruefungen_neu']], [17, 17, 18], 'Vorschau: 17 Prüflinge neu, 18 Prüfungen neu');
    $z300 = array_values(array_filter($s['prueflinge'], static fn (array $z): bool => $z['kennung'] === '000300'))[0] ?? [];
    pruefe(($z300['bezeichnung'] ?? '') === 'Scheinwerfer' && count($z300['geraete'] ?? []) === 2 && str_contains(implode(' ', $z300['hinweise'] ?? []), '2-mal'),
        'gleiche Kennung zweimal beim selben Kunden: ein Prüfling, Stammdaten der jüngsten Prüfung');
    $ohneRecht = ['neu_kunde' => false, 'kunden_ergaenzen' => false, 'inventar' => false];
    $e = GeraeteImport::uebernehmen($paket, $zuordnung, [], [], true, $ohneRecht);
    pruefe(!$e['ok'] && str_contains($e['message'], 'Kunden pflegt') && $alle('gp_prueflinge') === 0, 'neuer Kunde ohne „Kunden pflegen“ abgelehnt, nichts geschrieben');
    $griff = fopen(GeraeteImport::sperrdatei(), 'c');
    flock($griff, LOCK_EX);
    $e = GeraeteImport::uebernehmen($paket, $zuordnung, [], [], true, $erlaubtAlles);
    flock($griff, LOCK_UN);
    fclose($griff);
    pruefe(!$e['ok'] && str_contains($e['message'], 'schon ein Import') && $alle('gp_prueflinge') === 0, 'zweites Übernehmen zur selben Zeit: Meldung, nichts');
    $wartung(true);
    $e = GeraeteImport::uebernehmen($paket, $zuordnung, [], [], true, $erlaubtAlles);
    $wartung(false);
    pruefe(!$e['ok'] && !empty($e['wartung']) && $alle('gp_prueflinge') === 0 && $alle('gp_importe') === 0, 'Schreibsperre eines Updates: wartung, nichts');
    $auditVorher = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    $e = GeraeteImport::uebernehmen($paket, $zuordnung, [], [], true, $erlaubtAlles);
    pruefe($e['ok'] && empty($e['nichts']), 'übernommen: ' . $e['message']);
    $zz = $e['zahlen'] ?? [];
    gleich([$zz['kunden_neu'] ?? null, $zz['kunden_ergaenzt'] ?? null, $zz['prueflinge_neu'] ?? null, $zz['pruefungen_neu'] ?? null, $zz['vorlage'] ?? null,
        $zz['ablaeufe_neu'] ?? null], [1, 1, 17, 18, 1, 53], 'Zahlen: 1 Kunde neu, 1 ergänzt, 17 Prüflinge, 18 Prüfungen, Vorlage, 53 Prüfabläufe');
    $neuKunde = (int) Database::scalar('SELECT id FROM kunden WHERE name = ?', ['Testbühne Nord e. V.']);
    $kunde2 = Database::first('SELECT * FROM kunden WHERE id = ?', [$kK2]);
    gleich([$kunde2['strasse'], $kunde2['plz'], $kunde2['ort'], $kunde2['email'], $kunde2['ansprechpartner'], $kunde2['ansprechpartner_abteilung'], $kunde2['name']],
        ['Beispielweg 7', '54321', 'schon gesetzt', 'technik@beispiel-events.example', 'Erika Beispiel', 'Technik', $kennung . ' Beispiel'],
        'vorhandener Kunde: nur leere Felder gefüllt, Name und gesetzter Ort bleiben');
    pruefe($neuKunde > 0 && (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge WHERE kunde_id = ?', [$neuKunde]) === 4
        && (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge WHERE kunde_id IS NULL') === 9
        && (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge WHERE kunde_id = ?', [$kK2]) === 4, 'Prüflinge je Besitzer (9 eigen, 4, 4)');
    $pl = static fn (?int $kunde, string $kenn): array => Prueflinge::findEindeutig($kunde, $kenn) ?? [];
    $p101 = $pl(null, '000101');
    $ablauf1 = (int) Database::scalar("SELECT id FROM gp_pruefablaeufe WHERE geraet_id = '1'");
    gleich([$p101['hersteller'], $p101['seriennummer'], $p101['nennleistung'], $p101['letzte_pruefung'], $p101['letztes_ergebnis'],
        $p101['naechste_pruefung'], $p101['pruefablauf_id'], $p101['technik']['fragen'] ?? null, $p101['inventar_id']],
        ['Musterwerk', 'MW-0001', '1.6', '2026-01-12', 'bestanden', '2027-01-12', $ablauf1, '1,2', null], 'Prüfling 000101: Stammdaten, Fälligkeit, Prüfablauf');
    gleich([$pl(null, '000103')['kennung'], $pl(null, '000103')['technik']['fragen'] ?? null], ['000103', ''], 'Kennung getrimmt, ohne Fragen');
    $p104 = $pl($kK2, '000104');
    gleich([$p104['letztes_ergebnis'], $p104['letzte_pruefung'], $p104['naechste_pruefung'], (int) Database::scalar('SELECT COUNT(*) FROM gp_pruefungen WHERE pruefling_id = ?', [$p104['id']])],
        ['bestanden', '2026-03-03', '2027-03-03', 2], 'gescheitert und nachgeprüft: jüngste zählt');
    gleich([$pl($kK2, '000105')['letztes_ergebnis'], $pl($kK2, '000105')['naechste_pruefung']], ['nicht_bestanden', '2026-03-04'], 'nicht bestanden: am Prüftag fällig');
    $p113 = $pl($neuKunde, '000113');
    gleich([$p113['letzte_pruefung'], $p113['naechste_pruefung'], $p113['letztes_ergebnis'], $p113['schutzklasse']], [null, null, '', '3'], 'nie geprüft');
    gleich([$pl(null, '000300')['bezeichnung'], $pl(null, '000300')['seriennummer']], ['Scheinwerfer', 'LB-7'], 'doppelte Kennung: Stammdaten der jüngsten');
    pruefe($pl(null, '000114')['ausser_betrieb'] === true, 'außer Betrieb übernommen');
    gleich([$alle('gp_pruefungen'), $alle('gp_importe'), $alle('gp_vorlagen'), $alle('gp_pruefablaeufe'),
        (int) Database::scalar("SELECT ungueltig FROM gp_pruefablaeufe WHERE geraet_id = '1000002'")], [18, 1, 1, 53, 1], 'Prüfungen, Beleg, Vorlage, Prüfabläufe');
    $ergebnisse = [];
    foreach (Database::select('SELECT p.ergebnis, p.daten, l.kennung FROM gp_pruefungen p JOIN gp_prueflinge l ON l.id = p.pruefling_id') as $zeile) {
        $daten = json_decode((string) $zeile['daten'], true);
        $ergebnisse[] = is_array($daten) && !isset($daten['ref']) && $daten['ergebnis'] === $zeile['ergebnis'];
    }
    pruefe($ergebnisse !== [] && !in_array(false, $ergebnisse, true), 'Normalform in gp_pruefungen.daten (ohne Verweise der Datei)');
    $beleg = Database::first('SELECT * FROM gp_importe ORDER BY id DESC LIMIT 1');
    $belegZahlen = json_decode((string) $beleg['zahlen'], true);
    pruefe($beleg['sha256'] === hash_file('sha256', $m1['datei']) && is_array($belegZahlen)
        && array_filter($belegZahlen, static fn ($w): bool => !is_int($w)) === [], 'Beleg: sha256, nur Zahlen');
    $audit = Database::select('SELECT action, details FROM audit_log WHERE id > ?', [$auditVorher]);
    $auditText = implode(' ', array_column($audit, 'details'));
    pruefe(in_array('geraetepruefung.import', array_column($audit, 'action'), true)
        && !str_contains($auditText, 'Beispiel') && !str_contains($auditText, 'Muster') && !str_contains($auditText, 'Testbühne') && !str_contains($auditText, '000101'),
        'Audit: Import, ohne Namen und Kennungen');
    $gemerkt = Database::select('SELECT kunde_id FROM gp_kunden_zuordnung ORDER BY id');
    gleich(array_map(static fn (array $z): ?int => $z['kunde_id'] === null ? null : (int) $z['kunde_id'], $gemerkt), [null, $kK2, $neuKunde],
        'Zuordnungen gemerkt (eigen, Kundennummer, neuer Kunde)');
    $aktiv = BenningVorlage::aktiv(true);
    pruefe($aktiv !== null && BenningVorlage::bytes($aktiv) === $v['bytes'], 'aktive Vorlage = die bereinigten Bytes');

    // Nach dem Übernehmen ist die Vorschau weg; die nächste schlägt für den neuen Kunden ihn selbst vor.
    $zuordnung = ['1' => 'eigen', '2' => 'k' . $kK2, '3' => 'k' . $neuKunde];
    $e = GeraeteImport::uebernehmen($paket, $zuordnung, [], [], true, $erlaubtAlles);
    pruefe($e['ok'] && !empty($e['nichts']) && $alle('gp_pruefungen') === 18 && $alle('gp_importe') === 1 && $alle('gp_vorlagen') === 1
        && $alle('kunden') === 2, 'dieselbe Datei noch einmal: nichts Neues, kein Beleg');
    $sicherung = $ordner . '/Muster_Backup.001';
    copy($m1['datei'], $sicherung);
    $gs = GeraeteImport::lesen($sicherung, 'Muster_Backup.001');
    $ss = GeraeteImport::pruefen($gs['paket'], null, $erlaubtAlles);
    gleich(array_column($ss['kunden'], 'vorschlag', 'ref'), [1 => 'eigen', 2 => 'k' . $kK2, 3 => 'k' . $neuKunde], 'Sicherung: Vorschläge aus der gemerkten Zuordnung');
    gleich([$ss['zahlen']['prueflinge_gleich'], $ss['zahlen']['pruefungen_neu'], $ss['zahlen']['pruefungen_da'], $ss['vorlage']['gleich'], $ss['vorlage']['vorauswahl']],
        [17, 0, 18, true, false], 'Sicherung: alles schon da, Vorlage gleich (nicht vorausgewählt)');
    pruefe(str_contains((string) ($ss['kunden'][0]['vorschlag_grund'] ?? ''), 'letzten Import'), 'Grund des Vorschlags genannt');
    // Kennung am Gerät geändert: dieselbe Prüfung bleibt „schon da“ am bisherigen Prüfling, nie doppelt.
    $umbenannt = $kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec("UPDATE Geraete SET Geraete_ID = '000101X' WHERE Geraete_ID = '000101'"));
    $su = GeraeteImport::pruefen(GeraeteImport::lesen($umbenannt, 'x.db')['paket'], ['1' => 'eigen', '2' => 'k' . $kK2, '3' => 'k' . $neuKunde], $erlaubtAlles);
    $zu = array_values(array_filter($su['prueflinge'], static fn (array $z): bool => $z['kennung'] === '000101X'))[0] ?? [];
    gleich([$zu['zustand'] ?? null, $zu['pruefungen_neu'] ?? null, $zu['pruefungen_da'] ?? null, $su['zahlen']['pruefungen_neu']], ['neu', 0, 1, 0],
        'am Gerät umbenannt: neuer Prüfling ohne neue Prüfung – die Prüfung ist schon da');
    pruefe(str_contains(implode(' ', $zu['hinweise'] ?? []), 'schon bei Prüfling „000101“ – Kennung am Gerät geändert?'), '… mit Hinweis auf den bisherigen Prüfling');
    $mehr = $ordner . '/mehr.db';
    copy($m1['datei'], $mehr);
    BenningMuster::pruefungAnhaengen($mehr, ['kennung' => '000101', 'datum' => '2026-06-01 10:00:00']);
    $gm = GeraeteImport::lesen($mehr, 'mehr.db');
    $sm = GeraeteImport::pruefen($gm['paket'], $zuordnung, $erlaubtAlles);
    gleich([$sm['zahlen']['prueflinge_gleich'], $sm['zahlen']['pruefungen_neu'], $sm['zahlen']['pruefungen_da']], [17, 1, 18], 'eine Prüfung am Gerät dazu: 1 neu');
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [], [], false, $erlaubtAlles);
    gleich([$e['ok'], $e['zahlen']['pruefungen_neu'] ?? null, $e['zahlen']['vorlage'] ?? null, $pl(null, '000101')['letzte_pruefung'], $pl(null, '000101')['naechste_pruefung']],
        [true, 1, 0, '2026-06-01', '2027-06-01'], 'übernommen: Fälligkeit neu gerechnet');

    // „hier anders“ – nur mit Haken und gesehenem Stand
    $p102 = $pl(null, '000102');
    Prueflinge::aendern($p102['id'], ['bezeichnung' => 'Ladegerät (korrigiert)']);
    $sa = GeraeteImport::pruefen($gm['paket'], $zuordnung, $erlaubtAlles);
    $z102 = array_values(array_filter($sa['prueflinge'], static fn (array $z): bool => $z['kennung'] === '000102'))[0] ?? [];
    gleich([$z102['zustand'] ?? null, $z102['unterschiede'] ?? null, $z102['abweichungen'][0]['hier'] ?? null, $z102['abweichungen'][0]['datei'] ?? null],
        ['anders', ['Bezeichnung'], 'Ladegerät (korrigiert)', 'Ladegerät Akkuschrauber'], 'hier anders: Unterschied mit beiden Werten');
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [], [], false, $erlaubtAlles);
    pruefe($e['ok'] && !empty($e['nichts']) && $pl(null, '000102')['bezeichnung'] === 'Ladegerät (korrigiert)', 'ohne Haken bleibt die Korrektur');
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [$z102['form'] => str_repeat('0', 64)], [], false, $erlaubtAlles);
    pruefe($e['ok'] && $pl(null, '000102')['bezeichnung'] === 'Ladegerät (korrigiert)', 'Haken mit fremdem Stand: bleibt');
    Prueflinge::aendern($p102['id'], ['bezeichnung' => 'Ladegerät (noch einmal korrigiert)']);
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [$z102['form'] => $z102['stand']], [], false, $erlaubtAlles);
    pruefe($e['ok'] && $pl(null, '000102')['bezeichnung'] === 'Ladegerät (noch einmal korrigiert)', 'seit der Vorschau wieder geändert: bleibt (Stand der Vorschau)');
    $sa = GeraeteImport::pruefen($gm['paket'], $zuordnung, $erlaubtAlles);
    $z102 = array_values(array_filter($sa['prueflinge'], static fn (array $z): bool => $z['kennung'] === '000102'))[0] ?? [];
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [$z102['form'] => $z102['stand']], [], false, $erlaubtAlles);
    pruefe($e['ok'] && ($e['zahlen']['prueflinge_ersetzt'] ?? 0) === 1 && $pl(null, '000102')['bezeichnung'] === 'Ladegerät Akkuschrauber',
        'mit Haken und gesehenem Stand: ersetzt');

    // Inventar-Vorschlag
    $inv107 = Database::insert('inventar', ['art' => 'verteilung', 'kennung' => '000107', 'name' => $kennung . ' PRCD', 'created_at' => gmdate('c')]);
    $invTank = Database::insert('inventar', ['art' => 'tanksensor', 'kennung' => '000109', 'name' => $kennung . ' Tank', 'created_at' => gmdate('c')]);
    $si = GeraeteImport::pruefen($gm['paket'], $zuordnung, $erlaubtAlles);
    $vorschlaege = [];
    foreach ($si['prueflinge'] as $z) {
        if ($z['inventar'] !== null) {
            $vorschlaege[$z['kennung']] = $z['inventar']['id'];
        }
    }
    gleich($vorschlaege, ['000107' => $inv107], 'Inventar-Vorschlag über die Kennung – nie ein Tanksensor');
    gleich(array_filter(array_column(GeraeteImport::pruefen($gm['paket'], $zuordnung, $ohneRecht)['prueflinge'], 'inventar')), [],
        'ohne „Datenquellen ansehen“ kein Vorschlag');
    $z107 = array_values(array_filter($si['prueflinge'], static fn (array $z): bool => $z['kennung'] === '000107'))[0];
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [], [$z107['form'] => (string) $invTank], false, $erlaubtAlles);
    pruefe($e['ok'] && $pl(null, '000107')['inventar_id'] === null, 'fremde Inventar-ID im Formular: nicht verknüpft');
    $e = GeraeteImport::uebernehmen($gm['paket'], $zuordnung, [], [$z107['form'] => (string) $inv107], false, $erlaubtAlles);
    pruefe($e['ok'] && ($e['zahlen']['inventar_verknuepft'] ?? 0) === 1 && $pl(null, '000107')['inventar_id'] === $inv107, 'angehakt: verknüpft');
    gleich(array_filter(array_column(GeraeteImport::pruefen($gm['paket'], $zuordnung, $erlaubtAlles)['prueflinge'], 'inventar')), [], 'danach kein Vorschlag mehr');

    // Vorlage: dieselben Bytes werden wieder aktiv, nicht doppelt
    $e = GeraeteImport::uebernehmen($gs['paket'], $zuordnung, [], [], true, $erlaubtAlles);
    pruefe($e['ok'] && !empty($e['nichts']) && $alle('gp_vorlagen') === 1, 'Vorlage mit denselben Bytes: keine zweite Zeile');

    // Vorschau ablegen, verwerfen, aufräumen
    $k1 = GeraeteImport::ablegen(['format' => 1, 'test' => $kennung]);
    pruefe((GeraeteImport::abgelegt($k1)['test'] ?? null) === $kennung && GeraeteImport::abgelegt('../' . $k1) === null && GeraeteImport::abgelegt('x') === null,
        'Vorschau abgelegt, nur über die Kennung (nie ein Pfad)');
    GeraeteImport::verwerfen($k1);
    $k2 = GeraeteImport::ablegen(['format' => 1]);
    touch($cache . '/gp-import-' . $k2 . '.json', time() - 7200);
    pruefe(GeraeteImport::abgelegt($k1) === null && GeraeteImport::abgelegt($k2) === null, 'verworfen bzw. nach einer Stunde weg');
    $k3 = GeraeteImport::ablegen(['format' => 1]);
    touch($cache . '/gp-import-' . $k3 . '.json', time() - 7200);
    pruefe(GeraeteImport::aufraeumen() >= 1 && !is_file($cache . '/gp-import-' . $k3 . '.json'), 'aufraeumen() für die Wartung');
    // Sperrdatei: dieselbe für denselben Pfad, wie auch immer geschrieben; alte fremde räumt die Wartung weg, die eigene bleibt.
    $eigeneSperre = GeraeteImport::sperrdatei();
    $pfadVorher = (string) Config::get('database.path');
    Config::set('database.path', dirname($pfadVorher) . '/../' . basename(dirname($pfadVorher)) . '/' . basename($pfadVorher));
    $andersGeschrieben = GeraeteImport::sperrdatei();
    Config::set('database.path', $pfadVorher);
    gleich($andersGeschrieben, $eigeneSperre, 'Sperrdatei aus dem echten Pfad (…/../… ergibt dieselbe)');
    touch($eigeneSperre, time() - 7200);
    $fremdeSperre = $cache . '/gp-import-' . str_repeat('0', 23) . 'f.lock';
    touch($fremdeSperre, time() - 7200);
    GeraeteImport::aufraeumen();
    pruefe(!is_file($fremdeSperre) && is_file($eigeneSperre), 'alte fremde Sperrdatei weg, die eigene bleibt');

    // ================================================ E – Export
    $ohneAblauf = Prueflinge::anlegen(['kennung' => $kennung . '-OHNE', 'bezeichnung' => 'ohne Ablauf']);
    $ungueltig = Prueflinge::anlegen(['kennung' => $kennung . '-UNG', 'pruefablauf_id' => (int) Database::scalar("SELECT id FROM gp_pruefablaeufe WHERE geraet_id = '1000002'")]);
    $mitHersteller = Prueflinge::anlegen(['kennung' => $kennung . '-NEU', 'bezeichnung' => 'Neuer Strahler', 'hersteller' => 'Neuer Hersteller GmbH',
        'pruefablauf_id' => $ablauf1, 'leitungslaenge' => '2.5', 'querschnitt' => '1.5', 'leiter' => '3', 'schutzklasse' => '1']);
    // Ohne Leitungsangaben bzw. mit Intervall 0: nie mit 0 oder erfundenen Werten in die Datei.
    Prueflinge::anlegen(['kennung' => $kennung . '-LEER', 'pruefablauf_id' => $ablauf1, 'leitungslaenge' => '2.5', 'schutzklasse' => '1']);
    Prueflinge::anlegen(['kennung' => $kennung . '-NULL', 'pruefablauf_id' => $ablauf1, 'leitungslaenge' => '2.5', 'querschnitt' => '1.5', 'leiter' => '3',
        'intervall_monate' => 0, 'schutzklasse' => '1']);
    gleich(array_column(array_filter(BenningVorlage::besitzer(), static fn (array $b): bool => $b['wert'] === 'eigen'), 'ohne_angaben'), [2],
        'Exportseite zählt die Prüflinge ohne Leitungsangaben oder Intervall');
    gleich(BenningVorlage::exportieren([], 'x')['ok'], false, 'Export ohne Besitzer abgelehnt');
    gleich(BenningVorlage::exportieren(['999999'], 'x')['ok'], false, 'Export mit unbekanntem Kunden abgelehnt');
    $auditVorher = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    $x = BenningVorlage::exportieren(['eigen', (string) $kK2, (string) $neuKunde], 'Test Export für Müller');
    pruefe($x['ok'] && $x['dateiname'] === 'Test-Export-f-r-M-ller.db', 'Export geschrieben (' . ($x['dateiname'] ?? '') . ')');
    gleich($x['zahlen'] ?? [], ['kunden' => 3, 'prueflinge' => 18, 'ohne_ablauf' => 2, 'ablauf_abweichend' => 0, 'ohne_angaben' => 2, 'ausser_betrieb' => 1],
        'Zahlen: 18 Prüflinge, 2 ohne gültigen Prüfablauf, 2 ohne Leitungsangaben bzw. Intervall übergangen');
    pruefe(str_contains($x['message'] ?? '', '2 ohne Leitungslänge, Querschnitt, Leiterzahl oder Prüfintervall übergangen'), 'Meldung nennt sie: ' . ($x['message'] ?? ''));
    $xDatei = $ordner . '/export.db';
    file_put_contents($xDatei, $x['bytes']);
    $xp = $oeffnen($xDatei);
    $vp = $oeffnen($vDatei);
    gleich(array_column($schema($xp), 'sql'), array_column($schema($vp), 'sql'), 'Export: Schema Text für Text wie die Vorlage');
    $gleicheWerte = [];
    foreach (BenningVorlage::EINSTELLUNGEN as $t) {
        $gleicheWerte[$t] = $zeilen($xp, $t) === $zeilen($vp, $t);
    }
    gleich($gleicheWerte, array_fill_keys(BenningVorlage::EINSTELLUNGEN, true), 'Export: Pruefung, Grenzwerte, Systemdaten, DbInfos per quote() unverändert');
    gleich([(int) $xp->query('PRAGMA page_size')->fetchColumn(), $xp->query('PRAGMA journal_mode')->fetchColumn(), ord($x['bytes'][18]), ord($x['bytes'][19]),
        $xp->query('PRAGMA integrity_check')->fetchColumn()], [1024, 'delete', 1, 1, 'ok'], 'Export: Seitengröße, Journal, Kopf 1/1, integrity_check');
    gleich($xp->query('SELECT Kunden_Index, Kunden_Name, Kunden_Nummer FROM Kunden ORDER BY Kunden_Index')->fetchAll(PDO::FETCH_NUM),
        [[1, BenningVorlage::EIGEN_NAME, 'PM-EIGEN'], [2, $kennung . ' Beispiel', 'K-20001'], [3, 'Testbühne Nord e. V.', 'PM-K' . $neuKunde]],
        'Kunden: eigener Bestand PM-EIGEN, Kundennummer, PM-K<id>');
    gleich((int) $xp->query('SELECT COUNT(*) FROM Geraete')->fetchColumn(), 18, '18 Geräte');
    $x101 = $xp->query("SELECT Geraete_ID, Geraete_Kunden_Index, Geraete_SK, Geraete_Pruefablauf_Index, Geraete_Pruefintervall, Geraete_Pruefdatum,
        Geraete_Datum_Naechste_Pruefung, typeof(Geraete_Leitungslaenge) AS t, typeof(Geraete_Pruefablauf) AS tm, AusserBetrieb, Geraete_Hersteller, Geraete_VDE
        FROM Geraete WHERE Geraete_ID = '000101'")->fetch();
    gleich($x101, ['Geraete_ID' => '000101', 'Geraete_Kunden_Index' => 1, 'Geraete_SK' => 1, 'Geraete_Pruefablauf_Index' => 1, 'Geraete_Pruefintervall' => 12,
        'Geraete_Pruefdatum' => '2026-06-01 10:00:00', 'Geraete_Datum_Naechste_Pruefung' => '2027-06-01 00:00:00', 't' => 'real', 'tm' => 'integer',
        'AusserBetrieb' => 0, 'Geraete_Hersteller' => 'Musterwerk', 'Geraete_VDE' => 1], 'Gerät 000101 wie im Prüfgerät');
    gleich((int) $xp->query("SELECT AusserBetrieb FROM Geraete WHERE Geraete_ID = '000114'")->fetchColumn(), 1, 'außer Betrieb → AusserBetrieb=1');
    gleich((int) $xp->query("SELECT COUNT(*) FROM Manufacturers WHERE name = 'Musterwerk'")->fetchColumn() . '/'
        . (int) $xp->query("SELECT COUNT(*) FROM Manufacturers WHERE name = 'Neuer Hersteller GmbH'")->fetchColumn(), '1/1', 'Hersteller: vorhandene bleiben, fehlende angehängt');
    $fragen = static fn (string $kenn): array => array_map('intval', $xp->query("SELECT q.QuestionId FROM Geraete2AdminQuestions q JOIN Geraete g ON g.Geraete_Index = q.GeraeteId
        WHERE g.Geraete_ID = " . $xp->quote($kenn) . ' ORDER BY q.QuestionId')->fetchAll(PDO::FETCH_COLUMN));
    gleich([$fragen('000101'), $fragen('000103'), $fragen($kennung . '-NEU')], [[1, 2], [], [1, 2]], 'Fragen: aus dem Gerät, keine, Vorgabe (Stufe 1)');
    gleich((int) $xp->query("SELECT COUNT(*) FROM Geraete WHERE Geraete_ID IN (" . $xp->quote($kennung . '-OHNE') . ', ' . $xp->quote($kennung . '-UNG') . ')')->fetchColumn(),
        0, 'ohne bzw. mit ungültigem Prüfablauf: nicht in der Datei');
    gleich([(int) $xp->query("SELECT COUNT(*) FROM Geraete WHERE Geraete_ID IN (" . $xp->quote($kennung . '-LEER') . ', ' . $xp->quote($kennung . '-NULL') . ')')->fetchColumn(),
        (int) $xp->query('SELECT COUNT(*) FROM Geraete WHERE Geraete_Leitungslaenge <= 0 OR Geraete_Leitungsquerschnitt <= 0 OR Geraete_Anz_Leiter <= 0 OR Geraete_Pruefintervall < 1')->fetchColumn()],
        [0, 0], 'ohne Leitungsangaben bzw. Intervall: nicht in der Datei – kein Gerät mit 0 m, 0 mm², 0 Leitern oder Intervall 0');
    gleich((int) $xp->query("SELECT COUNT(*) FROM Geraete g JOIN Pruefung p ON p.Pruefung_ID = g.Geraete_Pruefablauf_Index WHERE g.Geraete_Pruefablauf_Name <> p.Pruefung_Name")->fetchColumn(),
        0, 'Name des Prüfablaufs je Gerät genau wie in Pruefung');
    gleich((int) $xp->query('SELECT COUNT(*) FROM Ergebnisse')->fetchColumn(), 0, 'keine Prüfungen in der Datei');
    $xp = null;
    $vp = null;
    $audit = Database::select('SELECT action, details FROM audit_log WHERE id > ?', [$auditVorher]);
    pruefe($alle('gp_exporte') === 1 && in_array('geraetepruefung.export', array_column($audit, 'action'), true)
        && !str_contains(implode(' ', array_column($audit, 'details')), 'Beispiel'), 'Beleg und Audit des Exports (nur Zahlen)');
    // Eine gespeicherte Vorlage mit Funktions-DEFAULT (an der Prüfung beim Import vorbei, etwa per SQL): der
    // Export prüft das Schema selbst, bevor er einfügt – Meldung statt Fehlerseite, kein Beleg.
    $boeseVorlage = $kopie($vDatei, $mitDefault);
    BenningVorlage::speichern((string) file_get_contents($boeseVorlage), [], null);
    $xb = BenningVorlage::exportieren(['eigen'], 'x');
    pruefe(!$xb['ok'] && str_contains($xb['message'], 'berechneten Vorgabewert') && $alle('gp_exporte') === 1,
        'Vorlage mit Funktions-DEFAULT: Export abgelehnt (' . $xb['message'] . ')');
    BenningVorlage::speichern($v['bytes'], [], null);
    pruefe(BenningVorlage::bytes((array) BenningVorlage::aktiv(true)) === $v['bytes'], '… die bisherige Vorlage ist wieder aktiv');
    // Ein Prüfablauf, der hier anders steht als in der Vorlage: übergangen und gemeldet, nie mit fremden Schritten.
    Database::execute("UPDATE gp_pruefablaeufe SET name = 'Anders benannt' WHERE geraet_id = '1000001'");
    $xa = BenningVorlage::exportieren(['eigen'], 'x');
    Database::execute("UPDATE gp_pruefablaeufe SET name = '40 Akkuleuchte mit Ladeplatte' WHERE geraet_id = '1000001'");
    pruefe($xa['ok'] && ($xa['zahlen']['ablauf_abweichend'] ?? 0) === 1 && str_contains($xa['message'], 'anders steht als in der Vorlage'),
        'Prüfablauf hier anders als in der Vorlage: Prüfling übergangen und gemeldet');
    $xp = $oeffnen($datei($xa['bytes'] ?? ''));
    gleich((int) $xp->query("SELECT COUNT(*) FROM Geraete WHERE Geraete_ID = '000109'")->fetchColumn(), 0, '… nicht in der Datei');
    $xp = null;

    // Re-Import: zwei Prüfungen „am Gerät“ in den Export
    BenningMuster::pruefungAnhaengen($xDatei, ['kennung' => '000101', 'kunden_nummer' => 'PM-EIGEN', 'datum' => '2026-08-03 09:00:00']);
    BenningMuster::pruefungAnhaengen($xDatei, ['kennung' => '000200', 'kunden_nummer' => 'K-20001', 'datum' => '2026-08-03 09:30:00']);
    $gx = GeraeteImport::lesen($xDatei, 'export.db');
    $sx = GeraeteImport::pruefen($gx['paket'], null, $erlaubtAlles);
    gleich(array_column($sx['kunden'], 'vorschlag', 'ref'), [1 => 'eigen', 2 => 'k' . $kK2, 3 => 'k' . $neuKunde],
        'Re-Import: Kunden über PM-EIGEN, Kundennummer und PM-K<id>');
    $nichtGleich = array_values(array_map(static fn (array $z): string => $z['kennung'] . ':' . $z['zustand'] . ':' . implode(',', $z['unterschiede']),
        array_filter($sx['prueflinge'], static fn (array $z): bool => $z['zustand'] !== 'gleich')));
    gleich([$nichtGleich, $sx['zahlen']['prueflinge_gleich'], $sx['zahlen']['pruefungen_neu']], [[], 18, 2], 'Re-Import: alle 18 Prüflinge schon da, 2 Prüfungen neu');
    $vorherAnzahl = $alle('gp_pruefungen');
    $e = GeraeteImport::uebernehmen($gx['paket'], $sx['zuordnung'], [], [], false, $erlaubtAlles);
    pruefe($e['ok'] && ($e['zahlen']['pruefungen_neu'] ?? 0) === 2 && $alle('gp_pruefungen') === $vorherAnzahl + 2 && $alle('gp_prueflinge') === 22
        && $pl($kK2, '000200')['letzte_pruefung'] === '2026-08-03', 'Re-Import übernommen: an die vorhandenen Prüflinge');

    // Am Gerät angelegter Prüfablauf eines zweiten Prüfgeräts: dieselbe ID 1000001, anderer Name und andere Maske.
    $m3 = $muster(['start' => 7, 'messgeraet_sn' => '500000000999', 'kunden_nummern' => [1 => 'PM-EIGEN']]);
    $zweites = $kopie($m3['datei'], static function (PDO $pdo): void {
        $pdo->exec("UPDATE Pruefung SET Pruefung_Name = '40 Anderer Ablauf', Pruefung_Ablauf = Pruefung_Ablauf + 4 WHERE Pruefung_ID = 1000001");
        $pdo->exec("DELETE FROM Geraete WHERE Geraete_ID <> '000109'");
        $pdo->exec("UPDATE Geraete SET Geraete_ID = '000109B', Geraete_Pruefablauf_Name = '40 Anderer Ablauf' WHERE Geraete_ID = '000109'");
    });
    $g3 = GeraeteImport::lesen($zweites, 'zweites.db');
    $s3 = GeraeteImport::pruefen($g3['paket'], ['1' => 'eigen', '2' => 'nicht', '3' => 'nicht'], $erlaubtAlles);
    $z3 = array_values(array_filter($s3['prueflinge'], static fn (array $z): bool => $z['kennung'] === '000109B'))[0] ?? [];
    pruefe(($z3['ablauf_abweichend'] ?? false) === true && $s3['zahlen']['ablaeufe_abweichend'] === 1
        && str_contains(implode(' ', $s3['hinweise']), 'Prüfablauf 1000001 heißt im Prüfgerät „40 Anderer Ablauf“, hier „40 Akkuleuchte mit Ladeplatte“')
        && str_contains(implode(' ', $z3['hinweise'] ?? []), 'steht hier anders als im Prüfgerät'),
        'zweites Prüfgerät, Ablauf 1000001 mit anderem Inhalt: Hinweis in der Vorschau und am Prüfling – ' . json_encode([$z3['ablauf_abweichend'] ?? null,
            $s3['zahlen']['ablaeufe_abweichend'] ?? null, $s3['hinweise'], $z3['hinweise'] ?? null], JSON_UNESCAPED_UNICODE));
    $e = GeraeteImport::uebernehmen($g3['paket'], ['1' => 'eigen', '2' => 'nicht', '3' => 'nicht'], [], [], false, $erlaubtAlles);
    $ablauf1000001 = Database::first("SELECT id, name FROM gp_pruefablaeufe WHERE geraet_id = '1000001'") ?? [];
    $p109b = $pl(null, '000109B');
    gleich([$e['ok'], $e['zahlen']['prueflinge_ohne_ablauf'] ?? null, array_key_exists('pruefablauf_id', $p109b) ? $p109b['pruefablauf_id'] : 'fehlt', $pl(null, '000109')['pruefablauf_id'] ?? null,
        $ablauf1000001['name'] ?? null], [true, 1, null, (int) ($ablauf1000001['id'] ?? 0), '40 Akkuleuchte mit Ladeplatte'],
        'ohne Vorlage: der neue Prüfling bleibt ohne den Ablauf, der bisherige behält seinen, der Ablauf hier bleibt');

    // Ein Name mit Leerraum am Rand (wie in einer echten Datei): der Export schreibt ihn genau wie Pruefung_Name.
    $randLeer = $kopie($m1['datei'], static fn (PDO $pdo) => $pdo->exec("UPDATE Pruefung SET Pruefung_Name = '40 Akkuleuchte mit Ladeplatte ' WHERE Pruefung_ID = 1000001"));
    $gr = GeraeteImport::lesen($randLeer, 'rand.db');
    $e = GeraeteImport::uebernehmen($gr['paket'], ['1' => 'eigen', '2' => 'k' . $kK2, '3' => 'k' . $neuKunde], [], [], true, $erlaubtAlles);
    $xr = BenningVorlage::exportieren(['eigen'], 'x');
    $xp = $oeffnen($datei($xr['bytes'] ?? ''));
    gleich([$e['ok'], $xr['zahlen']['ablauf_abweichend'] ?? null, $xp->query("SELECT Geraete_Pruefablauf_Name FROM Geraete WHERE Geraete_ID = '000109'")->fetchColumn()],
        [true, 0, '40 Akkuleuchte mit Ladeplatte '], 'Export: Name des Prüfablaufs genau wie Pruefung_Name der Vorlage (mit Leerzeichen am Ende)');
    $xp = null;

    // Kunde löschen: gemerkte Zuordnungen gehen mit.
    $kWeg = (int) KundenService::speichern(null, ['name' => $kennung . ' geht', 'aktiv' => 0])['id'];
    Database::insert('gp_kunden_zuordnung', ['schluessel' => hash('sha256', 'name:' . $kennung . ' geht'), 'kunde_id' => $kWeg, 'created_at' => gmdate('c')]);
    $r = KundenService::loeschen($kWeg, $kennung . ' geht');
    gleich([$r['ok'], (int) Database::scalar('SELECT COUNT(*) FROM gp_kunden_zuordnung WHERE kunde_id = ?', [$kWeg])], [true, 0],
        'Kunde gelöscht: seine gemerkte Zuordnung ist weg');

    // ================================================ F – HTTP
    $port = freierPort();
    $serverFehler = $ordner . '/server.log';
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
    $dose = anmeldenAls($basis, $kennung . '-planung', $kennwort, $dosen);
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe($seite['status'] === 200 && str_contains($seite['text'], 'data-max-bytes="' . BenningDatei::MAX_BYTES . '" data-max-text="15 MB"')
        && preg_match('/data-max-rat="[^"]*richtige Datei[^"]*"/', $seite['text']) === 1 && !str_contains($seite['text'], 'Ein PDF wird')
        && str_contains($seite['text'], 'data-laeuft') && str_contains($seite['text'], 'enctype="multipart/form-data"') && !str_contains($seite['text'], 'onclick='),
        'Importseite: Dateifeld mit Grenze, data-laeuft, kein Inline-JS');
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '' && preg_match('/data-max-rat="([^"]*)"/', $seite['text'], $mr) === 1) {
        $js = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/hilfen/datei-grenze.cjs') . ' '
            . escapeshellarg(dirname(__DIR__) . '/public/assets/js/app.js') . ' ' . BenningDatei::MAX_BYTES . ' ' . escapeshellarg('15 MB') . ' '
            . escapeshellarg(html_entity_decode($mr[1], ENT_QUOTES, 'UTF-8'))), true) ?: [];
        $satz = (string) ($js['ueber']['meldung'] ?? '');
        pruefe(str_contains($satz, 'hochladen lassen sich höchstens 15 MB. Das reicht für rund 10 000 Prüfungen') && !str_contains($satz, 'PDF'),
            'Browser (app.js): Größenmeldung beim Geräte-Import ohne den Rat für PDF – ' . $satz);
    }
    $token = csrfAus($seite['text']);
    // Für die Vorschau: ein Prüfling hier korrigiert, ein Inventarstück mit passender Kennung.
    alsBenutzer($planung);
    Prueflinge::aendern($pl(null, '000101')['id'], ['bemerkung' => 'von Hand korrigiert']);
    $inv112 = Database::insert('inventar', ['art' => 'verteilung', 'kennung' => '000112', 'name' => $kennung . ' Leiste', 'created_at' => gmdate('c')]);
    alsBenutzer(null);
    $m2 = $muster(['start' => 5, 'messgeraet_sn' => '500000000777', 'kunden_nummern' => [1 => 'PM-EIGEN']]);
    $upload = $ordner . '/Neu_Backup.001';
    copy($m2['datei'], $upload);
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/import/vorschau', ['_token' => $token,
        'datei' => new CURLFile($upload, 'application/octet-stream', 'Neu_Backup.001')], true);
    gleich([$r['status'], $r['ziel']], [302, '/geraetepruefung/import'], 'Upload → zurück zur Importseite');
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe($seite['status'] === 200 && str_contains($seite['text'], 'Kunden im Prüfgerät') && str_contains($seite['text'], 'name="zuordnung[1]"')
        && str_contains($seite['text'], 'name="vollstaendig"') && str_contains($seite['text'], 'Gelesen: 3 Kunden') && !str_contains($seite['text'], 'onclick='),
        'Vorschau mit Zuordnung und Meldung');
    pruefe(str_contains(kopfzeile($seite, 'Cache-Control'), 'no-store'), 'Vorschau nicht zwischengespeichert');
    preg_match('/name="kennung" value="([0-9a-f]{32})"/', $seite['text'], $mk);
    alsBenutzer($planung);
    $httpStand = GeraeteImport::pruefen(GeraeteImport::abgelegt($mk[1] ?? '') ?? [], null, GeraeteImport::erlaubt());
    alsBenutzer(null);
    $anders = array_values(array_filter($httpStand['prueflinge'] ?? [], static fn (array $z): bool => $z['zustand'] === 'anders'));
    $mitInventar = array_values(array_filter($httpStand['prueflinge'] ?? [], static fn (array $z): bool => $z['inventar'] !== null));
    pruefe(in_array('000101', array_column($anders, 'kennung'), true)
        && substr_count($seite['text'], 'name="ersetzen[]"') === count($anders) && !preg_match('/name="ersetzen\[\]"[^>]*checked/', $seite['text'])
        && str_contains($seite['text'], 'value="' . ($anders[0]['form'] ?? '-') . ':' . ($anders[0]['stand'] ?? '-') . '"'),
        'Vorschau: je „hier anders“ ein Haken „ersetzen“ mit dem Stand, nicht vorausgewählt');
    pruefe(array_column(array_column($mitInventar, 'inventar'), 'id') === [$inv112]
        && preg_match('/name="inventar\[\]" value="' . ($mitInventar[0]['form'] ?? '-') . ':' . $inv112 . '" data-zeile checked/', $seite['text']) === 1,
        'Vorschau: Inventar-Vorschlag angehakt');
    preg_match('/name="zuordnung_stand" value="([0-9a-f]{64})"/', $seite['text'], $ms);
    $token = csrfAus($seite['text']);
    $felder = ['_token' => $token, 'kennung' => $mk[1] ?? '', 'zuordnung_stand' => $ms[1] ?? '', 'zuordnung' => ['1' => 'eigen', '2' => 'nicht', '3' => 'nicht'],
        'vorlage' => '1', 'vollstaendig' => '1', 'aktion' => 'uebernehmen'];
    $pruefungenVorher = $alle('gp_pruefungen');
    anfrage($basis, $dose, 'POST', '/geraetepruefung/import/uebernehmen', array_diff_key($felder, ['_token' => 1]));
    gleich($alle('gp_pruefungen'), $pruefungenVorher, 'ohne CSRF-Token: nichts übernommen');
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/import/uebernehmen', array_diff_key($felder, ['vollstaendig' => 1]));
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe($alle('gp_pruefungen') === $pruefungenVorher && str_contains(meldungen($seite['text']), 'nicht vollständig'), 'abgeschnittenes Formular: nichts übernommen');
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/import/uebernehmen', $felder);
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe($alle('gp_pruefungen') === $pruefungenVorher && str_contains(meldungen($seite['text']), 'Zuordnung der Kunden hat sich geändert')
        && preg_match('/name="zuordnung\[2\]">\s*<option value="nicht" selected>/', $seite['text']) === 1,
        'Zuordnung geändert, Prüflinge nicht gesehen: nichts, Vorschau mit der neuen Zuordnung');
    preg_match('/name="zuordnung_stand" value="([0-9a-f]{64})"/', $seite['text'], $ms);
    $felder['zuordnung_stand'] = $ms[1] ?? '';
    $felder['_token'] = csrfAus($seite['text']);
    $wartung(true);
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/import/uebernehmen', $felder);
    $wartung(false);
    pruefe($r['status'] === 302 && $alle('gp_pruefungen') === $pruefungenVorher, 'Schreibsperre eines Updates: nichts übernommen');
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    $felder['_token'] = csrfAus($seite['text']);
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/import/uebernehmen', $felder);
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe($r['status'] === 302 && $alle('gp_pruefungen') > $pruefungenVorher && str_contains(meldungen($seite['text']), 'Import übernommen')
        && str_contains($seite['text'], 'Datei hochladen'), 'übernommen: Meldung, wieder die Importseite (' . meldungen($seite['text']) . ')');
    pruefe($pl(null, '000101')['bemerkung'] === 'von Hand korrigiert' && $pl(null, '000112')['inventar_id'] === null,
        'ohne Haken: Korrektur bleibt, Inventar nicht verknüpft');
    // Viele neue Prüflinge: höchstens 300 Zeilen ohne Haken, der Rest gezählt.
    $viele = $muster(['faelle' => false, 'kunden' => 1, 'zusaetzlich' => 330, 'kunden_nummern' => [1 => 'PM-EIGEN'], 'messgeraet_sn' => '500000000888']);
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/import/vorschau', ['_token' => csrfAus($seite['text']),
        'datei' => new CURLFile($viele['datei'], 'application/octet-stream', 'viele.db')], true);
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe($seite['status'] === 200 && str_contains($seite['text'], '… und 30 weitere neue Prüflinge') && substr_count($seite['text'], 'wird angelegt') === 300,
        'Vorschau mit 330 neuen Prüflingen: 300 Zeilen, der Rest gezählt');
    anfrage($basis, $dose, 'POST', '/geraetepruefung/import/verwerfen', ['_token' => csrfAus($seite['text'])]);
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/import');
    pruefe(str_contains($seite['text'], 'Datei hochladen') && !str_contains($seite['text'], 'Kunden im Prüfgerät'), 'Vorschau verworfen');
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/export');
    pruefe($seite['status'] === 200 && str_contains($seite['text'], 'name="besitzer[]"') && !str_contains($seite['text'], 'onclick='), 'Exportseite');
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/export', ['_token' => csrfAus($seite['text']), 'besitzer' => ['eigen'], 'name' => 'Lager "A"']);
    pruefe($r['status'] === 200 && kopfzeile($r, 'Content-Type') === 'application/vnd.sqlite3'
        && kopfzeile($r, 'Content-Disposition') === 'attachment; filename="Lager-A.db"' && str_contains(kopfzeile($r, 'Cache-Control'), 'no-store')
        && kopfzeile($r, 'X-Content-Type-Options') === 'nosniff' && str_starts_with($r['text'], "SQLite format 3\0")
        && (int) kopfzeile($r, 'Content-Length') === strlen($r['text']), 'Export als Download (Kopfzeilen, Bytes)');
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/export', ['besitzer' => ['eigen'], 'name' => 'x']);
    pruefe($r['status'] !== 200 || !str_starts_with($r['text'], 'SQLite'), 'Export ohne Token: keine Datei');
    $seite = anfrage($basis, $dose, 'GET', '/geraetepruefung/pruefablaeufe');
    pruefe($seite['status'] === 200 && str_contains($seite['text'], 'id="ablauf-' . $ablauf1 . '"') && str_contains($seite['text'], 'bearbeiten')
        && !str_contains($seite['text'], 'onclick='), 'Prüfabläufe mit Bearbeiten');
    alsBenutzer($planung);
    $pg = (int) Pruefgeraete::speichern(null, ['fabrikat' => 'BENNING', 'typ' => 'MA 4', 'seriennummer' => $kennung, 'aktiv' => 1])['id'];
    alsBenutzer(null);
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/pruefablaeufe/' . $ablauf1 . '/speichern', ['_token' => csrfAus($seite['text']),
        'norm' => 'DIN EN 50699 (VDE 0702) mit Messadapter', 'zubehoer' => [(string) $pg]]);
    $a1 = BenningVorlage::pruefablauf($ablauf1);
    pruefe($r['status'] === 302 && str_ends_with((string) $r['ziel'], '#ablauf-' . $ablauf1) && $a1['norm'] === 'DIN EN 50699 (VDE 0702) mit Messadapter'
        && $a1['zubehoer'] === [$pg], 'Prüfablauf: Norm und Zubehör gespeichert');
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/pruefablaeufe/' . $ablauf1 . '/speichern', ['_token' => csrfAus($seite['text']),
        'norm' => '', 'zubehoer' => ['999999']]);
    pruefe(BenningVorlage::pruefablauf($ablauf1)['zubehoer'] === [$pg], 'unbekanntes Zubehör: nichts geändert');
    $r = anfrage($basis, $dose, 'POST', '/geraetepruefung/pruefablaeufe/999999/speichern', ['_token' => csrfAus($seite['text']), 'norm' => 'x']);
    gleich($r['status'], 404, 'unbekannter Prüfablauf: 404');
    $doseSehen = anmeldenAls($basis, $kennung . '-sehen', $kennwort, $dosen);
    gleich(anfrage($basis, $doseSehen, 'GET', '/geraetepruefung/import')['status'], 403, 'nur sehen: Import 403');
    gleich(anfrage($basis, $doseSehen, 'GET', '/geraetepruefung/export')['status'], 403, 'nur sehen: Export 403');
    $seite = anfrage($basis, $doseSehen, 'GET', '/geraetepruefung/pruefablaeufe');
    pruefe($seite['status'] === 200 && !str_contains($seite['text'], '/speichern"'), 'nur sehen: Prüfabläufe ohne Bearbeiten');
    gleich(anfrage($basis, $doseSehen, 'POST', '/geraetepruefung/pruefablaeufe/' . $ablauf1 . '/speichern',
        ['_token' => csrfAus($seite['text']), 'norm' => 'x'])['status'], 403, 'nur sehen: Speichern 403');
    gleich(meldungenDesServers($serverFehler), [], 'keine PHP-Meldung im Fehlerprotokoll des Servers');
} finally {
    alsBenutzer(null);
    BenningDatei::$grenzen = null;
    if (isset($wartung)) {
        $wartung(false);
    }
    foreach ($dosen as $d) {
        @unlink($d);
    }
    // Die Instanz (Datenbank) räumt instanz.php weg; hier die Dateien in storage/cache.
    foreach (glob($cache . '/gp-*') ?: [] as $d) {
        if (!isset($cacheVorher[$d]) && !str_ends_with($d, '.lock')) {
            @unlink($d);
        }
    }
    @unlink(GeraeteImport::sperrdatei());
    foreach (glob($ordner . '/*') ?: [] as $d) {
        @unlink($d);
    }
    @rmdir($ordner);
}

ende('geraetepruefung-import');
