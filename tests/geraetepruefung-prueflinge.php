<?php
declare(strict_types=1);

/**
 * Geräteprüfung – Prüflinge, Kunden, Inventar, Übersicht (eigene Instanz,
 * Zeitzone Europe/Berlin, eigener PHP-Server für die Web-Teile).
 *
 * A – Prueflinge::speichern(): Kennung Pflicht, getrimmt, höchstens 50
 * Zeichen (nicht still gekürzt), je Besitzer eindeutig ohne Groß/Klein, bei
 * einem anderen Besitzer erlaubt; Kunde muss es geben; Inventarstück nur
 * beim eigenen Bestand, nie ein Tanksensor, nie zweimal, nur mit
 * „Datenquellen ansehen“ (ohne bleibt es); Prüfablauf nur ein gültiger (ein
 * schon eingetragener ungültiger bleibt); Zahlen als Dezimaltext (Komma
 * erlaubt, Unsinn und Negatives abgelehnt), Intervall 0–600, mit Prüfablauf
 * Länge, Querschnitt und Leiter Pflicht (> 0) und Intervall ab 1, Schutzklasse,
 * Textlängen; Daten von Hand nur ohne Prüfungen (nicht in der Zukunft,
 * nächste nicht vor der letzten, leer = letzte + Intervall), mit Prüfungen
 * gerechnet (neues Intervall → neue Fälligkeit); Besitzer wechseln nur ohne
 * Prüfbericht; Audit nur mit IDs.
 *
 * B – liste()/anzahl()/zahlen(): Filter Besitzer, fällig (überfällig, in 30
 * Tagen, ohne Termin; nicht bestanden = überfällig), Betrieb, Suche (auch
 * „%“ und „_“ wörtlich), Sortierung nach Termin, Grenze; faelligkeit() mit
 * Symbol und Wort wie die Gültigkeit der Prüfnachweise; Zahlen der Übersicht
 * passen zu den Filtern.
 *
 * C – loeschen(): mit Prüfungen abgelehnt (außer Betrieb), ohne gelöscht, Audit.
 *
 * D – Inventar: löschen eines Stücks lässt den Prüfling (ohne Verweis),
 * fuerInventar(), Auswahl ohne Tanksensor und ohne schon verknüpfte Stücke.
 *
 * E – Kunden: Anschrift und Kontakt gesäubert (Steuer- und Richtungszeichen),
 * zu lang und keine E-Mail abgelehnt, ohne die Felder bleiben sie, leeren
 * geht; nie im Audit, nie in KundenSicht; zahlen()['prueflinge']; Löschen
 * mit Prüflingen abgelehnt, nach dem Entfernen erlaubt.
 *
 * F – HTTP: Übersicht (Zahlen, fällig, offene Prüfungen, letzter Import nur
 * mit Zahlen), Liste mit Filtern, Formular (Vorbelegung aus Kunde und
 * Inventar), Detail mit Prüfungsverlauf und Berichten, unverändert speichern
 * ändert nichts (Zahlen mit Komma), ohne CSRF-Token nichts, Fehler behält
 * die Eingaben, fremde und kaputte IDs 404, Löschen; nur ansehen: Liste und
 * Detail, kein Formular (403); ohne Recht 403; Kundenzugang nie; Kunden:
 * Kontaktfelder speichern, Link „Prüflinge dieses Kunden“ nur mit
 * geraetepruefung.view, Löschkarte; Inventar: Karte „DGUV V3“ nur mit
 * geraetepruefung.view, nie am Tanksensor; keine PHP-Meldung im Server,
 * kein Inline-JS.
 *
 * G – Ansichten ohne Inline-JS, jede Zelle mit data-label, jedes
 * POST-Formular mit CSRF-Feld.
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['timezone' => 'Europe/Berlin']]);
require __DIR__ . '/hilfen/pruefung.php';

use App\Geraetepruefung\Messarten;
use App\Geraetepruefung\Prueflinge;
use App\Geraetepruefung\Pruefungen;
use App\Kunden\KundenService;
use App\Kunden\KundenSicht;
use App\Kunden\Pruefnachweise;
use App\Services\BenutzerService;
use App\Services\InventarService;
use App\Support\Database;

if (!function_exists('curl_init')) {
    uebersprungen('geraetepruefung-prueflinge', 'die PHP-Erweiterung curl fehlt');
}

$kennung = 'pm-gp-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '!';
$wurzel = dirname(__DIR__);
$ordner = (string) getenv('PM_INSTANZ_ORDNER');
if ($ordner === '' || !is_dir($ordner)) {
    $ordner = sys_get_temp_dir();
}
$dosen = [];
$heute = Pruefnachweise::heute();
$tagIn = static fn (int $tage): string => (new DateTimeImmutable($heute, new DateTimeZone('UTC')))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->format('Y-m-d');

// ------------------------------------------------------------ HTTP-Hilfen

/** Ein Aufruf mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function anfrage(string $basis, string $dose, string $methode, string $pfad, array $felder = []): array
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
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
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

/** Anmelden; die Cookie-Dose kommt in $dosen. */
function anmeldenAls(string $basis, string $name, string $kennwort, array &$dosen): string
{
    $dose = (string) tempnam(sys_get_temp_dir(), 'pm-gp-');
    $dosen[] = $dose;
    $seite = anfrage($basis, $dose, 'GET', '/login');
    anfrage($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => csrfAus($seite['text'])]);
    return $dose;
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

/**
 * Die Felder eines Formulars so, wie der Browser sie schickte: Eingaben mit
 * Wert, angehakte Kästchen, gewählte Optionen (sonst die erste), Textfelder;
 * deaktivierte Felder nicht.
 *
 * @return array<string,string>
 */
function formularFelder(string $html, string $aktion): array
{
    if (preg_match('/<form[^>]*action="' . preg_quote($aktion, '/') . '"[^>]*>(.*?)<\/form>/s', $html, $f) !== 1) {
        return [];
    }
    $form = $f[1];
    $felder = [];
    $wert = static fn (string $tag, string $attr): ?string => preg_match('/\s' . $attr . '="([^"]*)"/', $tag, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    preg_match_all('/<input\b[^>]*>/s', $form, $inputs);
    foreach ($inputs[0] as $tag) {
        $name = $wert($tag, 'name');
        if ($name === null || str_contains($tag, ' disabled')) {
            continue;
        }
        $typ = $wert($tag, 'type') ?? 'text';
        if (in_array($typ, ['checkbox', 'radio'], true) && !str_contains($tag, ' checked')) {
            continue;
        }
        $felder[$name] = $wert($tag, 'value') ?? ($typ === 'checkbox' ? 'on' : '');
    }
    preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/s', $form, $selects, PREG_SET_ORDER);
    foreach ($selects as [$_, $attr, $optionen]) {
        $name = $wert($attr, 'name');
        if ($name === null || str_contains($attr, ' disabled')) {
            continue;
        }
        preg_match_all('/<option\b([^>]*)>/s', $optionen, $opts);
        $gewaehlt = null;
        foreach ($opts[1] as $o) {
            if (str_contains($o, ' selected')) {
                $gewaehlt = $wert($o, 'value') ?? '';
                break;
            }
            $gewaehlt ??= $wert($o, 'value') ?? '';   // ohne Auswahl die erste Option
        }
        $felder[$name] = $gewaehlt ?? '';
    }
    preg_match_all('/<textarea\b([^>]*)>(.*?)<\/textarea>/s', $form, $areas, PREG_SET_ORDER);
    foreach ($areas as [$_, $attr, $inhalt]) {
        $name = $wert($attr, 'name');
        if ($name !== null) {
            $felder[$name] = html_entity_decode($inhalt, ENT_QUOTES, 'UTF-8');
        }
    }
    return $felder;
}

/** Kein Inline-JavaScript in einer Antwort. */
function ohneInlineJs(string $html): bool
{
    return preg_match('/\son[a-z]+\s*=\s*["\']/i', $html) !== 1 && stripos($html, 'javascript:') === false;
}

/** Eine Prüfung in Normalform (wie aus BenningDatei::lesen()) – nur Text, Wahrheitswerte, Listen. */
$normal = static function (string $geprueftAm, string $kennungGeraet, string $ergebnis = 'bestanden') use ($kennung): array {
    $p = [
        'ref' => '1', 'geraet_ref' => '1', 'geprueft_am' => $geprueftAm, 'am_geraet' => $geprueftAm . ' ' . $kennung,
        'pruefer' => 'Erika Mustermann', 'prueffirma' => 'Muster GmbH', 'ort' => '', 'auftrag' => '', 'bemerkung' => '',
        'ablauf_name' => '1 Geräte SK I', 'ablauf_geraet_id' => '1', 'messgeraet' => Messarten::MESSGERAET,
        'messgeraet_sn' => '500000000001', 'firmware' => '1.17 / 1.08.01', 'ergebnis' => $ergebnis, 'sicht' => 'ok',
        'geraet_ergebnis' => ['messung' => $ergebnis === 'bestanden', 'sicht' => true],
        'sicht_antworten' => [['frage' => '1', 'text' => 'Sichtkontrolle', 'antwort' => 'ok']],
        'schritte' => [['art' => 'RPE', 'titel' => Messarten::titel('RPE'), 'ok' => $ergebnis === 'bestanden',
            'werte' => [['name' => 'RPE Widerstand', 'wert' => '0.102 Ω', 'gueltig' => $ergebnis === 'bestanden']],
            'grenzwerte' => [['name' => 'R_PE max', 'wert' => '0.3 Ω']]]],
    ];
    $p['schluessel'] = Pruefungen::schluessel($p);
    return $p;
};
$utc = static fn (string $tag, string $uhr = '10:00:00'): string => (new DateTimeImmutable($tag . ' ' . $uhr, new DateTimeZone('Europe/Berlin')))
    ->setTimezone(new DateTimeZone('UTC'))->format(Messarten::UTC_FORMAT);
$audit = static fn (string $aktion, int $id): array => array_map(static fn (array $z): array => json_decode((string) $z['details'], true) ?: [],
    Database::select('SELECT details FROM audit_log WHERE action = ? AND target_id = ? ORDER BY id', [$aktion, (string) $id]));
$zeile = static fn (int $id): array => Database::first('SELECT * FROM gp_prueflinge WHERE id = ?', [$id]) ?? [];
/** Ein Feld einer Zeile – auch NULL, „fehlt“ nur, wenn es die Spalte (oder Zeile) nicht gibt. */
$feld = static fn (array $z, string $k): mixed => array_key_exists($k, $z) ? $z[$k] : 'fehlt';
$anzahlPl = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge');

try {
    // ================================================ Bestand
    $kunden = [];
    foreach (['a' => 'Muster Events', 'b' => 'Stadtfest', 'c' => 'Zum Löschen'] as $k => $name) {
        $kunden[$k] = Database::insert('kunden', ['name' => $kennung . ' ' . $name, 'kundennummer' => 'K-' . strtoupper($k) . random_int(1000, 9999),
            'aktiv' => $k === 'c' ? 0 : 1, 'created_at' => gmdate('c')]);
    }
    $inventar = [];
    foreach (['pd' => 'verteilung', 'pd2' => 'verteilung', 'pd3' => 'verteilung', 'ts' => 'tanksensor'] as $k => $art) {
        $inventar[$k] = Database::insert('inventar', ['art' => $art, 'kennung' => $kennung . '-' . strtoupper($k), 'name' => 'Stück ' . $k,
            'seriennummer' => 'INV-' . strtoupper($k), 'created_at' => gmdate('c')]);
    }
    $ablauf = [];
    foreach (['a' => ['1', '1 Geräte SK I', 0], 'b' => ['2', '2 Geräte SK II', 0], 'c' => ['1000002', 'Alter Ablauf', 1]] as $k => [$gid, $name, $ungueltig]) {
        $ablauf[$k] = Database::insert('gp_pruefablaeufe', ['geraet_id' => $kennung . '-' . $gid, 'nr' => $gid, 'name' => $name,
            'ungueltig' => $ungueltig, 'created_at' => gmdate('c')]);
    }
    $personal = [];
    $personal['planung'] = BenutzerService::anlegen($kennung . '-planung', $kennwort, 'planung', false);
    $rollen = [
        'sehen'      => ['geraetepruefung.view'],
        'pflegen'    => ['geraetepruefung.view', 'geraetepruefung.manage'],
        'nichts'     => ['dashboard.view'],
        'kunden'     => ['kunden.view', 'kunden.manage'],
        'inventar'   => ['datenquellen.view'],
    ];
    foreach ($rollen as $r => $rechte) {
        $rid = (int) BenutzerService::rolleSpeichern(null, $kennung . ' ' . $r, '', $rechte)['id'];
        $personal[$r] = BenutzerService::anlegen($kennung . '-' . $r, $kennwort, (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rid]), false);
    }
    $kundenzugang = Database::insert('users', ['username' => $kennung . '-kunde', 'full_name' => 'Zugang', 'email' => '',
        'password_hash' => password_hash($kennwort, PASSWORD_DEFAULT), 'is_active' => 1, 'must_change_password' => 0, 'kunde_id' => $kunden['a'],
        'created_at' => gmdate('c')]);
    alsBenutzer($personal['planung']);

    // ================================================ A – speichern
    $basisDaten = static fn (array $mehr = []): array => $mehr + ['kennung' => '', 'kunde_id' => '', 'bezeichnung' => 'Kabeltrommel 25 m',
        'hersteller' => 'Muster', 'typ' => 'KT-25', 'modell' => '', 'seriennummer' => 'SN-1', 'abteilung' => '', 'gebaeude' => '', 'bemerkung' => '',
        'schutzklasse' => '1', 'pruefablauf_id' => '', 'intervall_monate' => '12', 'leitungslaenge' => '', 'querschnitt' => '', 'leiter' => '',
        'nennleistung' => '', 'ausser_betrieb' => false];
    $r = Prueflinge::speichern(null, $basisDaten(['kennung' => '  ' . $kennung . '-001 ', 'inventar_id' => (string) $inventar['pd'],
        'pruefablauf_id' => (string) $ablauf['a'], 'leitungslaenge' => '1,5', 'querschnitt' => '2,50', 'leiter' => '3', 'nennleistung' => '2000',
        'intervall_monate' => ' 24 ', 'bemerkung' => "Zeile 1\nZeile 2\x07"]));
    pruefe($r['ok'], 'Prüfling angelegt: ' . $r['message']);
    $p1 = (int) ($r['id'] ?? 0);
    $z = $zeile($p1);
    gleich([$z['kennung'] ?? '', $z['eindeutig'] ?? '', $feld($z, 'kunde_id'), (int) ($z['inventar_id'] ?? 0), (int) ($z['pruefablauf_id'] ?? 0),
        $z['leitungslaenge'] ?? '', $z['querschnitt'] ?? '', $z['leiter'] ?? '', $z['nennleistung'] ?? '', (int) ($z['intervall_monate'] ?? 0),
        (int) ($z['created_by'] ?? 0)],
        [$kennung . '-001', 'e:' . $kennung . '-001', null, $inventar['pd'], $ablauf['a'], '1.5', '2.5', '3', '2000', 24, $personal['planung']],
        'gespeichert: Kennung getrimmt, eindeutig, eigener Bestand, Inventar, Ablauf, Zahlen mit Punkt, Intervall, wer');
    pruefe(str_contains((string) ($z['bemerkung'] ?? ''), "Zeile 1\nZeile 2") && !str_contains((string) ($z['bemerkung'] ?? ''), "\x07"),
        'Bemerkung: Zeilen bleiben, Steuerzeichen raus');
    $vorher = $anzahlPl();
    $r = Prueflinge::speichern(null, $basisDaten(['kennung' => strtoupper($kennung) . '-001']));
    pruefe(!$r['ok'] && str_contains($r['message'], 'schon') && $anzahlPl() === $vorher, 'dieselbe Kennung (groß) im eigenen Bestand abgelehnt: ' . $r['message']);
    $r = Prueflinge::speichern(null, $basisDaten(['kennung' => $kennung . '-001', 'kunde_id' => (string) $kunden['a']]));
    pruefe($r['ok'], 'dieselbe Kennung bei einem Kunden erlaubt');
    $p1a = (int) ($r['id'] ?? 0);
    gleich($zeile($p1a)['eindeutig'] ?? '', 'k' . $kunden['a'] . ':' . $kennung . '-001', '… eindeutig beim Kunden');
    $abgelehnt = [
        'ohne Kennung'                      => ['kennung' => '   '],
        'Kennung über 50 Zeichen'           => ['kennung' => str_repeat('x', 51)],
        'Kunde, den es nicht gibt'          => ['kennung' => $kennung . '-x', 'kunde_id' => '999999999'],
        'Kunde kaputt'                      => ['kennung' => $kennung . '-x', 'kunde_id' => '1x'],
        'Inventar beim Kunden'              => ['kennung' => $kennung . '-x', 'kunde_id' => (string) $kunden['a'], 'inventar_id' => (string) $inventar['pd2']],
        'Tanksensor'                        => ['kennung' => $kennung . '-x', 'inventar_id' => (string) $inventar['ts']],
        'Inventar schon verknüpft'          => ['kennung' => $kennung . '-x', 'inventar_id' => (string) $inventar['pd']],
        'Inventar, das es nicht gibt'       => ['kennung' => $kennung . '-x', 'inventar_id' => '999999999'],
        'Inventar kaputt'                   => ['kennung' => $kennung . '-x', 'inventar_id' => 'abc'],
        'Prüfablauf ungültig'               => ['kennung' => $kennung . '-x', 'pruefablauf_id' => (string) $ablauf['c']],
        'Prüfablauf, den es nicht gibt'     => ['kennung' => $kennung . '-x', 'pruefablauf_id' => '999999999'],
        'Leitungslänge „abc“'               => ['kennung' => $kennung . '-x', 'leitungslaenge' => 'abc'],
        'Leitungslänge negativ'             => ['kennung' => $kennung . '-x', 'leitungslaenge' => '-1'],
        'Querschnitt „1.2.3“'               => ['kennung' => $kennung . '-x', 'querschnitt' => '1.2.3'],
        'Leiter zu lang'                    => ['kennung' => $kennung . '-x', 'leiter' => '123456789'],
        'Intervall 601'                     => ['kennung' => $kennung . '-x', 'intervall_monate' => '601'],
        'Intervall negativ'                 => ['kennung' => $kennung . '-x', 'intervall_monate' => '-1'],
        'Intervall „x“'                     => ['kennung' => $kennung . '-x', 'intervall_monate' => 'x'],
        'Prüfablauf ohne Leitungslänge'     => ['kennung' => $kennung . '-x', 'pruefablauf_id' => (string) $ablauf['a'], 'querschnitt' => '1,5', 'leiter' => '3'],
        'Prüfablauf, Querschnitt 0'         => ['kennung' => $kennung . '-x', 'pruefablauf_id' => (string) $ablauf['a'], 'leitungslaenge' => '5', 'querschnitt' => '0,0', 'leiter' => '3'],
        'Prüfablauf ohne Leiterzahl'        => ['kennung' => $kennung . '-x', 'pruefablauf_id' => (string) $ablauf['a'], 'leitungslaenge' => '5', 'querschnitt' => '1,5'],
        'Prüfablauf, Intervall 0'           => ['kennung' => $kennung . '-x', 'pruefablauf_id' => (string) $ablauf['a'], 'leitungslaenge' => '5', 'querschnitt' => '1,5',
            'leiter' => '3', 'intervall_monate' => '0'],
        'Schutzklasse 4'                    => ['kennung' => $kennung . '-x', 'schutzklasse' => '4'],
        'Bezeichnung über 120 Zeichen'      => ['kennung' => $kennung . '-x', 'bezeichnung' => str_repeat('b', 121)],
        'Bemerkung über 500 Zeichen'        => ['kennung' => $kennung . '-x', 'bemerkung' => str_repeat('b', 501)],
        'letzte Prüfung in der Zukunft'     => ['kennung' => $kennung . '-x', 'letzte_pruefung' => $tagIn(1)],
        'letzte Prüfung 31.02.'             => ['kennung' => $kennung . '-x', 'letzte_pruefung' => '31.02.2026'],
        'nächste vor der letzten'           => ['kennung' => $kennung . '-x', 'letzte_pruefung' => $tagIn(-10), 'naechste_pruefung' => $tagIn(-11)],
    ];
    foreach ($abgelehnt as $was => $mehr) {
        $vorher = $anzahlPl();
        $r = Prueflinge::speichern(null, $basisDaten($mehr));
        pruefe(!$r['ok'] && $anzahlPl() === $vorher, 'abgelehnt: ' . $was . ' (' . $r['message'] . ')');
    }
    $r = Prueflinge::speichern(null, $basisDaten(['kennung' => $kennung . '-hand', 'letzte_pruefung' => Pruefnachweise::datum($tagIn(-100)),
        'naechste_pruefung' => '', 'intervall_monate' => '12']));
    $pHand = (int) ($r['id'] ?? 0);
    gleich([$r['ok'], $zeile($pHand)['letzte_pruefung'] ?? null, $zeile($pHand)['naechste_pruefung'] ?? null, $zeile($pHand)['letztes_ergebnis'] ?? null],
        [true, $tagIn(-100), Messarten::monatePlus($tagIn(-100), 12), ''], 'Daten von Hand (TT.MM.JJJJ), nächste = letzte + Intervall, kein Ergebnis');
    $r = Prueflinge::speichern($pHand, ['naechste_pruefung' => $tagIn(5)]);
    gleich([$r['ok'], $zeile($pHand)['naechste_pruefung'] ?? null, $zeile($pHand)['bezeichnung'] ?? null], [true, $tagIn(5), 'Kabeltrommel 25 m'],
        'nur die nächste Prüfung geändert – fehlende Felder bleiben');
    pruefe(!Prueflinge::speichern(999999999, $basisDaten(['kennung' => $kennung . '-y']))['ok'], 'ändern: unbekannte ID abgelehnt');
    $details = $audit('geraetepruefung.pruefling', $p1);
    gleich($details[0] ?? null, ['pruefling_id' => $p1, 'kunde_id' => null, 'neu' => true, 'inventar_id' => $inventar['pd']], 'Audit beim Anlegen: nur IDs');
    $alleDetails = json_encode(Database::select("SELECT details FROM audit_log WHERE action LIKE 'geraetepruefung.%'"), JSON_UNESCAPED_UNICODE);
    pruefe(!str_contains((string) $alleDetails, $kennung . '-001') && !str_contains((string) $alleDetails, 'Kabeltrommel') && !str_contains((string) $alleDetails, 'SN-1'),
        'Audit ohne Kennung, Bezeichnung, Seriennummer');

    // Inventar nur mit „Datenquellen ansehen“
    alsBenutzer($personal['pflegen']);
    gleich(Prueflinge::inventarAuswahl(), [], 'ohne „Datenquellen ansehen“: keine Inventar-Auswahl');
    $r = Prueflinge::speichern($p1, ['inventar_id' => '']);
    gleich([$r['ok'], (int) ($zeile($p1)['inventar_id'] ?? 0)], [true, $inventar['pd']], 'ohne „Datenquellen ansehen“: Inventar bleibt, wie es ist');
    $r = Prueflinge::speichern(null, $basisDaten(['kennung' => $kennung . '-ohneinv', 'inventar_id' => (string) $inventar['pd2']]));
    gleich([$r['ok'], $feld($zeile((int) ($r['id'] ?? 0)), 'inventar_id')], [true, null], 'ohne „Datenquellen ansehen“: kein Inventar beim Anlegen');
    $pOhneInv = (int) ($r['id'] ?? 0);
    alsBenutzer($personal['planung']);
    $auswahl = array_column(Prueflinge::inventarAuswahl(), 'id');
    pruefe(!in_array($inventar['pd'], $auswahl, true) && in_array($inventar['pd2'], $auswahl, true) && !in_array($inventar['ts'], $auswahl, true),
        'Inventar-Auswahl: ohne Tanksensor, ohne schon verknüpfte Stücke');
    pruefe(in_array($inventar['pd'], array_column(Prueflinge::inventarAuswahl($p1), 'id'), true), '… das eigene Stück bleibt wählbar');

    // Ein schon eingetragener ungültiger Prüfablauf bleibt
    Prueflinge::aendern($pOhneInv, ['pruefablauf_id' => $ablauf['c']]);
    $r = Prueflinge::speichern($pOhneInv, $basisDaten(['kennung' => $kennung . '-ohneinv', 'pruefablauf_id' => (string) $ablauf['c'], 'bezeichnung' => 'neu',
        'leitungslaenge' => '5', 'querschnitt' => '1,5', 'leiter' => '3']));
    gleich([$r['ok'], (int) ($zeile($pOhneInv)['pruefablauf_id'] ?? 0)], [true, $ablauf['c']], 'eingetragener ungültiger Prüfablauf bleibt beim Speichern');
    $ablaufIds = array_column(Prueflinge::ablaeufe($ablauf['c']), 'id');
    pruefe(in_array($ablauf['c'], $ablaufIds, true) && !in_array($ablauf['c'], array_column(Prueflinge::ablaeufe(), 'id'), true)
        && in_array($ablauf['a'], $ablaufIds, true), 'Prüfabläufe zur Auswahl: gültige, der eingetragene ungültige nur bei ihm');

    // Mit Prüfungen: Daten gerechnet, Intervall wirkt
    $pr1 = Pruefungen::anlegen($p1, $normal($utc($tagIn(-30)), $kennung . '-001'));
    Prueflinge::faelligkeitNeu($p1);
    $r = Prueflinge::speichern($p1, ['letzte_pruefung' => $tagIn(-1), 'naechste_pruefung' => $tagIn(900), 'intervall_monate' => '6']);
    gleich([$r['ok'], $zeile($p1)['letzte_pruefung'] ?? null, $zeile($p1)['naechste_pruefung'] ?? null, $zeile($p1)['letztes_ergebnis'] ?? null],
        [true, $tagIn(-30), Messarten::monatePlus($tagIn(-30), 6), 'bestanden'], 'mit Prüfung: Daten gerechnet, neues Intervall → neue Fälligkeit');

    // Besitzer wechseln: mit Prüfung erlaubt, mit Prüfbericht nicht
    $r = Prueflinge::speichern($p1a, ['kunde_id' => (string) $kunden['b']]);
    gleich([$r['ok'], (int) ($zeile($p1a)['kunde_id'] ?? 0), $zeile($p1a)['eindeutig'] ?? ''], [true, $kunden['b'], 'k' . $kunden['b'] . ':' . $kennung . '-001'],
        'Besitzer gewechselt (ohne Bericht), eindeutig neu');
    pruefe(($audit('geraetepruefung.pruefling', $p1a)[1]['vorher_kunde_id'] ?? null) === $kunden['a'], '… Audit mit dem vorigen Kunden (ID)');
    $berichtId = Database::insert('gp_berichte', ['pruefung_id' => $pr1['id'], 'pruefling_id' => $p1, 'kunde_id' => null, 'nummer' => 'GP-2026-' . random_int(10000, 99999),
        'jahr' => 2026, 'laufnr' => random_int(10000, 99999), 'ergebnis' => 'bestanden', 'inhalt' => '{}', 'inhalt_hash' => str_repeat('a', 64),
        'unterschrift_id' => 1, 'pruefer_id' => $personal['planung'], 'kette_hash' => str_repeat('b', 64), 'siegel' => str_repeat('c', 64),
        'schluessel_id' => 'abcd1234', 'pdf_hash' => str_repeat('d', 64), 'eingefroren_am' => gmdate('c'), 'created_at' => gmdate('c')]);
    $r = Prueflinge::speichern($p1, ['kunde_id' => (string) $kunden['a']]);
    pruefe(!$r['ok'] && $zeile($p1)['kunde_id'] === null, 'mit Prüfbericht: Besitzer bleibt (' . $r['message'] . ')');
    pruefe(Prueflinge::speichern($p1, ['bezeichnung' => 'Kabeltrommel 50 m'])['ok'], '… andere Felder lassen sich ändern');

    // ================================================ B – Liste und Fälligkeit
    $faell = [];
    foreach ([
        'ueber'  => ['naechste_pruefung' => $tagIn(-1)],
        'heute'  => ['naechste_pruefung' => $heute],
        'in30'   => ['naechste_pruefung' => $tagIn(30)],
        'in31'   => ['naechste_pruefung' => $tagIn(31)],
        'ohne'   => [],
        'nicht'  => ['letzte_pruefung' => $tagIn(-10), 'naechste_pruefung' => $tagIn(-10), 'letztes_ergebnis' => 'nicht_bestanden'],
        'aus'    => ['naechste_pruefung' => $tagIn(-5), 'ausser_betrieb' => true],
    ] as $k => $mehr) {
        $faell[$k] = Prueflinge::anlegen(['kennung' => $kennung . '-F-' . $k, 'kunde_id' => $k === 'in30' ? $kunden['a'] : null] + $mehr);
    }
    $liste = static fn (array $f): array => array_values(array_intersect(array_column(Prueflinge::liste($f + ['suche' => $kennung . '-F-', 'heute' => $heute]), 'id'), $faell));
    $ids = static fn (string ...$k): array => array_map(static fn (string $x): int => $faell[$x], $k);
    gleich($liste(['faellig' => 'ueberfaellig']), $ids('nicht', 'ueber'), 'überfällig: Frist abgelaufen oder nicht bestanden, nach Termin (nicht außer Betrieb, nicht heute)');
    gleich($liste(['faellig' => 'faellig']), $ids('nicht', 'ueber', 'heute', 'in30'), 'fällig: dazu heute und in 30 Tagen, nicht in 31');
    gleich($liste(['faellig' => 'ohne']), $ids('ohne'), 'ohne Termin');
    gleich($liste(['betrieb' => 'ausser']), $ids('aus'), 'außer Betrieb');
    gleich(count($liste(['betrieb' => 'alle'])), 7, 'alle: auch außer Betrieb');
    gleich(count($liste([])), 6, 'Vorgabe: nur in Betrieb');
    gleich($liste(['besitzer' => (string) $kunden['a']]), $ids('in30'), 'Besitzer: ein Kunde');
    gleich(count($liste(['besitzer' => 'eigen'])), 5, 'Besitzer: eigener Bestand');
    $f = static fn (string $k): array => Prueflinge::faelligkeit(Prueflinge::find($faell[$k]) ?? [], $heute);
    gleich([$f('ueber')['code'], $f('ueber')['symbol'], $f('ueber')['wort']], ['abgelaufen', '✗', 'überfällig'], 'Fälligkeit überfällig: Symbol und Wort');
    gleich([$f('heute')['code'], $f('heute')['symbol'], $f('heute')['wort'], str_contains($f('heute')['text'], 'heute')], ['laeuft_ab', '!', 'bald fällig', true], 'heute fällig');
    gleich([$f('in30')['code'], $f('in31')['code'], $f('ohne')['code'], $f('nicht')['code'], $f('aus')['code']],
        ['laeuft_ab', 'gueltig', 'unbekannt', 'abgelaufen', 'ausser_betrieb'], 'in 30 bald, in 31 nicht fällig, ohne Termin, nicht bestanden überfällig, außer Betrieb');
    gleich(Pruefnachweise::gueltigkeit(['naechste_pruefung' => $tagIn(30)], $heute)['code'], $f('in30')['code'], '… dieselbe Rechnung wie bei den Prüfnachweisen');
    pruefe(str_contains($f('nicht')['text'], 'nicht bestanden'), 'nicht bestanden: Hinweis „vor Wiederinbetriebnahme“');
    $zahlen = Prueflinge::zahlen($heute);
    gleich([$zahlen['ueberfaellig'], $zahlen['bald'], $zahlen['ohne_termin'], $zahlen['ausser_betrieb'], $zahlen['in_betrieb']],
        [Prueflinge::anzahl(['faellig' => 'ueberfaellig', 'heute' => $heute]),
         Prueflinge::anzahl(['faellig' => 'faellig', 'heute' => $heute]) - Prueflinge::anzahl(['faellig' => 'ueberfaellig', 'heute' => $heute]),
         Prueflinge::anzahl(['faellig' => 'ohne', 'heute' => $heute]), Prueflinge::anzahl(['betrieb' => 'ausser']), Prueflinge::anzahl([])],
        'Zahlen der Übersicht passen zu den Filtern');
    $prozent = Prueflinge::anlegen(['kennung' => $kennung . '-100%_x']);
    gleich(array_column(Prueflinge::liste(['suche' => '100%_']), 'id'), [$prozent], 'Suche: „%“ und „_“ wörtlich');
    gleich(array_column(Prueflinge::liste(['suche' => strtoupper($kennung) . '-f-UEBER']), 'id'), [$faell['ueber']], 'Suche ohne Groß/Klein');
    pruefe(count(Prueflinge::liste(['suche' => $kennung, 'betrieb' => 'alle', 'limit' => 2])) === 2 && Prueflinge::anzahl(['suche' => $kennung, 'betrieb' => 'alle']) > 2,
        'Grenze der Liste, Anzahl ohne Grenze');
    $z1 = Prueflinge::liste(['suche' => $kennung . '-001', 'besitzer' => 'eigen'])[0] ?? [];
    gleich([$z1['inventar_kennung'] ?? '', $z1['ablauf_name'] ?? '', $z1['pruefungen'] ?? 0, $z1['besitzer'] ?? ''],
        [$kennung . '-PD', '1 Geräte SK I', 1, Prueflinge::EIGEN], 'Zeile der Liste: Inventar, Ablauf, Zahl der Prüfungen, Besitzer');

    // ================================================ C – löschen
    $r = Prueflinge::loeschen($p1);
    pruefe(!$r['ok'] && Prueflinge::find($p1) !== null && str_contains($r['message'], 'außer Betrieb'), 'mit Prüfungen nicht gelöscht: ' . $r['message']);
    $r = Prueflinge::loeschen($faell['ohne']);
    pruefe($r['ok'] && Prueflinge::find($faell['ohne']) === null, 'ohne Prüfungen gelöscht');
    gleich($audit('geraetepruefung.pruefling_geloescht', $faell['ohne']), [['pruefling_id' => $faell['ohne'], 'kunde_id' => null]], '… Audit nur mit IDs');
    pruefe(!Prueflinge::loeschen(999999999)['ok'], 'löschen: unbekannte ID abgelehnt');

    // ================================================ D – Inventar
    $r = Prueflinge::speichern($pOhneInv, ['inventar_id' => (string) $inventar['pd2']]);
    $gp = Prueflinge::fuerInventar($inventar['pd2']);
    gleich([$r['ok'], (int) ($gp['id'] ?? 0), isset($gp['faelligkeit']['wort'])], [true, $pOhneInv, true], 'fuerInventar: der verknüpfte Prüfling mit Fälligkeit');
    gleich(Prueflinge::fuerInventar($inventar['pd3']), null, '… keiner ohne Verknüpfung');
    $r = InventarService::loeschen($inventar['pd2']);
    gleich([$r['ok'], Prueflinge::find($pOhneInv) !== null, $feld($zeile($pOhneInv), 'inventar_id')], [true, true, null], 'Inventar gelöscht: Prüfling bleibt, ohne Verweis');
    unset($inventar['pd2']);

    // ================================================ E – Kunden
    $kontakt = ['strasse' => "Muster\x01straße \u{202E} 1", 'plz' => ' 12345 ', 'ort' => 'Musterstadt', 'telefon' => '+49 30 1234567', 'fax' => '',
        'email' => 'info@example.test', 'ansprechpartner' => 'Max  Muster', 'ansprechpartner_abteilung' => 'Technik', 'ansprechpartner_telefon' => '0171 000000',
        'ansprechpartner_email' => 'max@example.test'];
    $r = KundenService::speichern(null, ['name' => $kennung . ' Kontakt GmbH', 'aktiv' => 1] + $kontakt);
    pruefe($r['ok'], 'Kunde mit Anschrift und Kontakt angelegt: ' . $r['message']);
    $kk = (int) ($r['id'] ?? 0);
    $kunden['k'] = $kk;
    $k = Database::first('SELECT * FROM kunden WHERE id = ?', [$kk]) ?? [];
    gleich([$k['strasse'] ?? '', $k['plz'] ?? '', $k['ansprechpartner'] ?? '', $k['email'] ?? '', $k['fax'] ?? 'x'],
        ['Muster straße 1', '12345', 'Max Muster', 'info@example.test', ''], 'Kontakt gesäubert: Steuer- und Richtungszeichen raus, Leerraum zusammengefasst');
    foreach ([
        'PLZ über 16 Zeichen'         => ['plz' => str_repeat('1', 17)],
        'Straße über 120 Zeichen'     => ['strasse' => str_repeat('s', 121)],
        'E-Mail ohne @'               => ['email' => 'info.example.test'],
        'E-Mail mit Leerraum'         => ['ansprechpartner_email' => 'max muster@example.test'],
        'Telefon über 64 Zeichen'     => ['telefon' => str_repeat('1', 65)],
    ] as $was => $mehr) {
        $r = KundenService::speichern($kk, ['name' => $kennung . ' Kontakt GmbH', 'aktiv' => 1] + $mehr + $kontakt);
        pruefe(!$r['ok'] && (Database::first('SELECT strasse FROM kunden WHERE id = ?', [$kk])['strasse'] ?? '') === 'Muster straße 1',
            'Kontakt abgelehnt: ' . $was . ' (' . $r['message'] . ')');
    }
    pruefe(KundenService::speichern($kk, ['name' => $kennung . ' Kontakt GmbH', 'aktiv' => 1])['ok']
        && (Database::first('SELECT strasse, email FROM kunden WHERE id = ?', [$kk]) ?? []) === ['strasse' => 'Muster straße 1', 'email' => 'info@example.test'],
        'ohne die Kontaktfelder: sie bleiben');
    pruefe(KundenService::speichern($kk, ['name' => $kennung . ' Kontakt GmbH', 'aktiv' => 1, 'fax' => '030 99'] + $kontakt)['ok']
        && KundenService::speichern($kk, ['name' => $kennung . ' Kontakt GmbH', 'aktiv' => 1, 'fax' => ''] + $kontakt)['ok']
        && (Database::first('SELECT fax FROM kunden WHERE id = ?', [$kk])['fax'] ?? 'x') === '', 'leeren geht');
    $auditKunde = json_encode(Database::select("SELECT details FROM audit_log WHERE action = 'kunden.speichern' AND target_id = ?", [(string) $kk]), JSON_UNESCAPED_UNICODE);
    pruefe(!str_contains((string) $auditKunde, 'straße') && !str_contains((string) $auditKunde, 'example.test') && !str_contains((string) $auditKunde, '1234567')
        && !str_contains((string) $auditKunde, 'Muster'), 'Kontakt nie im Audit');
    $sicht = json_encode([KundenSicht::zusammenfassung($kk), KundenSicht::nachweise($kk)], JSON_UNESCAPED_UNICODE);
    pruefe(!str_contains((string) $sicht, 'straße') && !str_contains((string) $sicht, 'example.test') && !str_contains((string) $sicht, 'Musterstadt'),
        'Kontakt nicht in KundenSicht');
    $quelle = (string) file_get_contents($wurzel . '/src/Kunden/KundenSicht.php');
    pruefe(array_filter(array_keys(KundenService::KONTAKT), static fn (string $f): bool => preg_match('/\b' . $f . '\b/', $quelle) === 1) === [],
        'KundenSicht nennt keines der Kontaktfelder');
    // Löschen mit Prüflingen abgelehnt
    $pc = Prueflinge::anlegen(['kennung' => $kennung . '-C1', 'kunde_id' => $kunden['c']]);
    gleich([KundenService::zahlen($kunden['c'])['prueflinge'], Prueflinge::kundeHatPrueflinge($kunden['c']), Prueflinge::kundeHatPrueflinge($kunden['b'])],
        [1, true, true], 'zahlen()[prueflinge], kundeHatPrueflinge()');
    $r = KundenService::loeschen($kunden['c'], $kennung . ' Zum Löschen');
    pruefe(!$r['ok'] && KundenService::find($kunden['c']) !== null && str_contains($r['message'], 'Prüfling'), 'Kunde mit Prüfling nicht gelöscht: ' . $r['message']);
    pruefe(Prueflinge::loeschen($pc)['ok'] && KundenService::loeschen($kunden['c'], $kennung . ' Zum Löschen')['ok'], 'Prüfling entfernt → Kunde gelöscht');
    unset($kunden['c']);
    KundenService::vergessen();

    // ================================================ F – HTTP
    $port = freierPort();
    $basis = 'http://127.0.0.1:' . $port;
    $serverFehler = $ordner . '/server-fehler.log';
    @mkdir($ordner . '/sitzungen', 0700, true);
    hintergrund([PHP_BINARY, '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    pruefe(erreichbar('127.0.0.1', $port), 'eigener PHP-Server läuft');
    Database::insert('gp_importe', ['sha256' => hash('sha256', $kennung), 'dateiname' => $kennung . '-geheim.db', 'groesse' => 1000,
        'messgeraet' => Messarten::MESSGERAET, 'messgeraet_sn' => '500000000001',
        'zahlen' => json_encode(['pruefungen_neu' => 3, 'kunden_ergaenzt' => 0, 'ablaeufe_neu' => 53, 'vorlage' => 1, 'unbekannt_neu' => 7, 'name' => $kennung . '-text']),
        'created_at' => gmdate('c')]);
    $pr2 = Pruefungen::anlegen($pOhneInv, $normal($utc($tagIn(-3)), $kennung . '-ohneinv', 'nicht_bestanden'));
    Prueflinge::faelligkeitNeu($pOhneInv);

    $planung = anmeldenAls($basis, $kennung . '-planung', $kennwort, $dosen);
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung');
    gleich($s['status'], 200, 'Übersicht: 200');
    pruefe(str_contains($s['text'], '/geraetepruefung/pruefungen/' . $pr2['id']) && !str_contains($s['text'], 'href="/geraetepruefung/pruefungen/' . $pr1['id'] . '"'),
        'Übersicht: offene Prüfung da, die mit gültigem Bericht nicht');
    pruefe(str_contains($s['text'], '3 Prüfungen neu · 53 Prüfabläufe neu · Einstellungen als Vorlage übernommen') && !str_contains($s['text'], $kennung . '-text')
        && !str_contains($s['text'], $kennung . '-geheim'), 'Übersicht: letzter Import nur mit Zahlen (kein Dateiname, kein Text)');
    pruefe(!str_contains($s['text'], 'kunden ergaenzt') && !str_contains($s['text'], '0 Kunden') && !str_contains($s['text'], 'unbekannt')
        && !str_contains($s['text'], '1 vorlage'), 'Übersicht: nur Zahlen über 0, mit deutschen Beschriftungen, nie rohe Schlüssel');
    pruefe(str_contains($s['text'], $kennung . '-F-ueber') && str_contains($s['text'], '/geraetepruefung/import'), 'Übersicht: Fälliges, Weg zum Import');
    pruefe(ohneInlineJs($s['text']), 'Übersicht ohne Inline-JS');
    foreach (['/geraetepruefung/prueflinge', '/geraetepruefung/prueflinge?faellig=faellig', '/geraetepruefung/prueflinge?besitzer=eigen&betrieb=alle&suche=' . urlencode($kennung),
        '/geraetepruefung/prueflinge?besitzer=' . $kunden['a'], '/geraetepruefung/prueflinge?faellig=ohne&betrieb=ausser', '/geraetepruefung/prueflinge?besitzer=x%27&faellig=%3Cb%3E'] as $pfad) {
        $s = anfrage($basis, $planung, 'GET', $pfad);
        pruefe($s['status'] === 200 && ohneInlineJs($s['text']) && str_contains($s['text'], 'tabelle-karten'), 'Liste ' . $pfad . ': 200');
    }
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge?faellig=ueberfaellig&suche=' . urlencode($kennung . '-F-'));
    pruefe(str_contains($s['text'], $kennung . '-F-ueber') && str_contains($s['text'], $kennung . '-F-nicht') && !str_contains($s['text'], $kennung . '-F-in31')
        && str_contains($s['text'], '✗') && str_contains($s['text'], 'überfällig'), 'Liste überfällig: die richtigen, mit Symbol und Wort');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu?kunde=' . $kunden['a']);
    pruefe($s['status'] === 200 && preg_match('/<option value="' . $kunden['a'] . '" selected>/', $s['text']) === 1 && csrfAus($s['text']) !== '',
        'Formular neu: Kunde aus der Adresse vorgewählt, CSRF-Feld');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu?inventar=' . $inventar['pd3']);
    pruefe(str_contains($s['text'], 'value="' . $kennung . '-PD3"') && preg_match('/<option value="' . $inventar['pd3'] . '" selected>/', $s['text']) === 1,
        'Formular neu: Inventarstück vorbelegt (Kennung, Auswahl)');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu?inventar=' . $inventar['ts']);
    pruefe(!str_contains($s['text'], 'value="' . $kennung . '-TS"'), 'Formular neu: kein Tanksensor als Vorbelegung');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/' . $p1);
    pruefe($s['status'] === 200 && str_contains($s['text'], '/geraetepruefung/pruefungen/' . $pr1['id']) && str_contains($s['text'], '/geraetepruefung/berichte/' . $berichtId . '/pdf')
        && str_contains($s['text'], 'Bearbeiten') && !str_contains($s['text'], '/loeschen"'), 'Detail: Prüfung, Bericht, Bearbeiten, kein Löschen (hat Prüfungen)');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/' . $p1 . '/bearbeiten');
    pruefe($s['status'] === 200 && str_contains($s['text'], 'value="1,5"') && str_contains($s['text'], 'value="2,5"') && str_contains($s['text'], ' disabled'),
        'Bearbeiten: Zahlen mit Komma, Besitzer gesperrt (Bericht)');
    $felder = formularFelder($s['text'], '/geraetepruefung/prueflinge/speichern');
    $vorher = $zeile($p1);
    $r = anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', $felder);
    $nachher = $zeile($p1);
    unset($vorher['updated_at'], $nachher['updated_at']);
    gleich([$r['status'], $r['ziel'], $nachher], [302, '/geraetepruefung/prueflinge/' . $p1, $vorher], 'unverändert gespeichert: nichts geändert');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/' . $pHand . '/bearbeiten');
    $felder = formularFelder($s['text'], '/geraetepruefung/prueflinge/speichern');
    $vorher = $zeile($pHand);
    anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', $felder);
    $nachher = $zeile($pHand);
    unset($vorher['updated_at'], $nachher['updated_at']);
    gleich($nachher, $vorher, 'unverändert gespeichert (Daten von Hand): nichts geändert');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/' . $pHand);
    pruefe(str_contains($s['text'], 'Ergebnis nicht bekannt') && !str_contains($s['text'], 'noch nicht geprüft'),
        'Detail: letzte Prüfung von Hand ohne Ergebnis → „Ergebnis nicht bekannt“, nicht „noch nicht geprüft“');
    // Neu anlegen über das Formular
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu');
    $felder = ['_token' => csrfAus($s['text']), 'kennung' => $kennung . '-web', 'kunde_id' => (string) $kunden['b'], 'bezeichnung' => 'Leuchte <b>',
        'schutzklasse' => '2', 'pruefablauf_id' => (string) $ablauf['b'], 'intervall_monate' => '12', 'leitungslaenge' => '3,25', 'querschnitt' => '0,75',
        'leiter' => '2', 'bemerkung' => ''];
    $vorher = $anzahlPl();
    $r = anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', array_diff_key($felder, ['_token' => 1]));
    pruefe($anzahlPl() === $vorher && $r['status'] !== 200, 'ohne CSRF-Token: nichts angelegt');
    $r = anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', $felder);
    $web = Prueflinge::findEindeutig($kunden['b'], $kennung . '-web');
    pruefe($r['status'] === 302 && $web !== null && $r['ziel'] === '/geraetepruefung/prueflinge/' . $web['id'] && $web['leitungslaenge'] === '3.25'
        && $web['bezeichnung'] === 'Leuchte <b>', 'über das Formular angelegt, Weiterleitung aufs Detail');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/' . (int) ($web['id'] ?? 0));
    pruefe(str_contains($s['text'], 'Leuchte &lt;b&gt;') && !str_contains($s['text'], 'Leuchte <b>') && str_contains(meldungen($s['text']), 'gespeichert'),
        'Detail: Text maskiert, Meldung');
    $r = anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', ['bezeichnung' => 'Eingabe bleibt ' . $kennung, 'kennung' => $kennung . '-WEB'] + $felder);
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu');
    pruefe($r['ziel'] === '/geraetepruefung/prueflinge/neu' && str_contains(meldungen($s['text']), 'schon') && str_contains($s['text'], 'Eingabe bleibt ' . $kennung),
        'doppelte Kennung: Meldung, Eingaben bleiben');
    $s = anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu');
    pruefe(!str_contains($s['text'], 'Eingabe bleibt ' . $kennung), '… nur einmal');
    foreach (['/geraetepruefung/prueflinge/999999999', '/geraetepruefung/prueflinge/abc', '/geraetepruefung/prueflinge/999999999/bearbeiten', '/geraetepruefung/prueflinge/1x/bearbeiten'] as $pfad) {
        gleich(anfrage($basis, $planung, 'GET', $pfad)['status'], 404, 'fremd/kaputt: ' . $pfad . ' → 404');
    }
    $token = csrfAus(anfrage($basis, $planung, 'GET', '/geraetepruefung/prueflinge/neu')['text']);
    gleich(anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', ['_token' => $token, 'id' => '999999999', 'kennung' => 'x'])['status'], 404,
        'speichern mit fremder ID → 404');
    gleich(anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/speichern', ['_token' => $token, 'id' => 'abc', 'kennung' => 'x'])['status'], 404,
        'speichern mit kaputter ID → 404');
    gleich(anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/999999999/loeschen', ['_token' => $token])['status'], 404, 'löschen mit fremder ID → 404');
    $r = anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/' . $p1 . '/loeschen', ['_token' => $token]);
    pruefe($r['ziel'] === '/geraetepruefung/prueflinge/' . $p1 && Prueflinge::find($p1) !== null, 'löschen mit Prüfungen über HTTP: abgelehnt');
    $r = anfrage($basis, $planung, 'POST', '/geraetepruefung/prueflinge/' . (int) $web['id'] . '/loeschen', ['_token' => $token]);
    pruefe($r['status'] === 302 && Prueflinge::find((int) $web['id']) === null, 'löschen ohne Prüfungen über HTTP');
    // Kunden: Kontaktfelder und Link
    $s = anfrage($basis, $planung, 'GET', '/kunden/' . $kunden['a'] . '?tab=stammdaten');
    pruefe($s['status'] === 200 && str_contains($s['text'], 'name="strasse"') && str_contains($s['text'], 'name="ansprechpartner_email"')
        && str_contains($s['text'], '/geraetepruefung/prueflinge?besitzer=' . $kunden['a']) && ohneInlineJs($s['text']), 'Stammdaten: Kontaktfelder, Link „Prüflinge dieses Kunden“');
    $felder = formularFelder($s['text'], '/kunden/speichern');
    $r = anfrage($basis, $planung, 'POST', '/kunden/speichern', ['strasse' => 'Hauptstraße 5', 'ort' => 'Musterstadt', 'email' => 'kontakt@example.test'] + $felder);
    $k = Database::first('SELECT name, strasse, ort, email, kundennummer FROM kunden WHERE id = ?', [$kunden['a']]) ?? [];
    gleich([$r['status'], $k['strasse'] ?? '', $k['ort'] ?? '', $k['email'] ?? '', $k['name'] ?? ''],
        [302, 'Hauptstraße 5', 'Musterstadt', 'kontakt@example.test', $kennung . ' Muster Events'], 'Stammdaten mit Kontakt über das Formular gespeichert');
    $s = anfrage($basis, $planung, 'GET', '/kunden/' . $kunden['a'] . '?tab=stammdaten');
    pruefe(str_contains($s['text'], 'value="Hauptstraße 5"'), '… und wieder im Formular');
    $s = anfrage($basis, $planung, 'GET', '/kunden');
    pruefe($s['status'] === 200 && str_contains($s['text'], 'Anschrift und Kontakt') && str_contains($s['text'], 'name="ansprechpartner"'), 'Neuer Kunde: Kontaktfelder');
    Database::update('kunden', $kunden['b'], ['aktiv' => 0]);
    $s = anfrage($basis, $planung, 'GET', '/kunden/' . $kunden['b'] . '?tab=stammdaten');
    pruefe(str_contains($s['text'], 'Prüfling') && !str_contains($s['text'], 'Endgültig löschen'), 'beendeter Kunde mit Prüflingen: Hinweis statt Löschknopf');
    Database::update('kunden', $kunden['b'], ['aktiv' => 1]);
    // Inventar: Karte „DGUV V3“
    $s = anfrage($basis, $planung, 'GET', '/inventar/' . $inventar['pd']);
    pruefe($s['status'] === 200 && str_contains($s['text'], 'DGUV V3') && str_contains($s['text'], '/geraetepruefung/prueflinge/' . $p1), 'Inventar: Karte „DGUV V3“ mit Prüfling');
    $s = anfrage($basis, $planung, 'GET', '/inventar/' . $inventar['pd3']);
    pruefe(str_contains($s['text'], 'DGUV V3') && str_contains($s['text'], '/geraetepruefung/prueflinge/neu?inventar=' . $inventar['pd3']), 'Inventar ohne Prüfling: „Als Prüfling anlegen“');
    $s = anfrage($basis, $planung, 'GET', '/inventar/' . $inventar['ts']);
    pruefe($s['status'] === 200 && !str_contains($s['text'], 'DGUV V3'), 'Tanksensor: keine Karte „DGUV V3“');

    // Nur ansehen
    $sehen = anmeldenAls($basis, $kennung . '-sehen', $kennwort, $dosen);
    $s = anfrage($basis, $sehen, 'GET', '/geraetepruefung/prueflinge/' . $p1);
    pruefe($s['status'] === 200 && !str_contains($s['text'], '/bearbeiten"'), 'nur ansehen: Detail ohne Bearbeiten');
    gleich(anfrage($basis, $sehen, 'GET', '/geraetepruefung/prueflinge')['status'], 200, 'nur ansehen: Liste');
    gleich([anfrage($basis, $sehen, 'GET', '/geraetepruefung/prueflinge/neu')['status'], anfrage($basis, $sehen, 'GET', '/geraetepruefung/prueflinge/' . $p1 . '/bearbeiten')['status']],
        [403, 403], 'nur ansehen: Formulare 403');
    $tokenSehen = csrfAus(anfrage($basis, $sehen, 'GET', '/geraetepruefung')['text']);
    $vorher = $anzahlPl();
    gleich([anfrage($basis, $sehen, 'POST', '/geraetepruefung/prueflinge/speichern', ['_token' => $tokenSehen, 'kennung' => $kennung . '-sehen'])['status'],
        anfrage($basis, $sehen, 'POST', '/geraetepruefung/prueflinge/' . $pHand . '/loeschen', ['_token' => $tokenSehen])['status']], [403, 403],
        'nur ansehen: speichern und löschen 403');
    pruefe($anzahlPl() === $vorher && Prueflinge::find($pHand) !== null, '… nichts geändert');
    // Ohne Recht, Kundenzugang
    $nichts = anmeldenAls($basis, $kennung . '-nichts', $kennwort, $dosen);
    gleich([anfrage($basis, $nichts, 'GET', '/geraetepruefung')['status'], anfrage($basis, $nichts, 'GET', '/geraetepruefung/prueflinge')['status']], [403, 403], 'ohne Recht: 403');
    pruefe(!str_contains(anfrage($basis, $nichts, 'GET', '/')['text'], 'href="/geraetepruefung"'), 'ohne Recht: kein Eintrag in der Navigation');
    $kunde = anmeldenAls($basis, $kennung . '-kunde', $kennwort, $dosen);
    $r = anfrage($basis, $kunde, 'GET', '/geraetepruefung/prueflinge/' . $p1);
    pruefe($r['status'] !== 200 && !str_contains($r['text'], $kennung . '-001'), 'Kundenzugang: kein Prüfling (' . $r['status'] . ')');
    // Kunden ohne Geräteprüfung: kein Link; Inventar ohne Geräteprüfung: keine Karte
    $kundenDose = anmeldenAls($basis, $kennung . '-kunden', $kennwort, $dosen);
    $s = anfrage($basis, $kundenDose, 'GET', '/kunden/' . $kunden['a'] . '?tab=stammdaten');
    pruefe($s['status'] === 200 && str_contains($s['text'], 'name="strasse"') && !str_contains($s['text'], '/geraetepruefung/prueflinge'),
        'ohne geraetepruefung.view: Kontaktfelder ja, Link auf die Prüflinge nein');
    $inv = anmeldenAls($basis, $kennung . '-inventar', $kennwort, $dosen);
    $s = anfrage($basis, $inv, 'GET', '/inventar/' . $inventar['pd']);
    pruefe($s['status'] === 200 && !str_contains($s['text'], 'DGUV V3'), 'Inventar ohne geraetepruefung.view: keine Karte');
    gleich(meldungenDesServers($serverFehler), [], 'keine PHP-Meldung im Server');

    // ================================================ G – Ansichten
    $ansichten = array_merge(glob($wurzel . '/resources/views/geraetepruefung/{index,prueflinge,pruefling,pruefling-form,_pruefling*}.php', GLOB_BRACE) ?: [],
        [$wurzel . '/resources/views/kunden/index.php', $wurzel . '/resources/views/kunden/_stammdaten.php', $wurzel . '/resources/views/inventar/show.php']);
    pruefe(count($ansichten) >= 9, 'Ansichten gefunden: ' . count($ansichten));
    foreach ($ansichten as $datei) {
        $text = (string) file_get_contents($datei);
        $name = basename(dirname($datei)) . '/' . basename($datei);
        pruefe(preg_match('/<script\b/i', $text) !== 1 && preg_match('/\son[a-z]+\s*=/i', $text) !== 1 && stripos($text, 'javascript:') === false, $name . ': kein Inline-JS');
        preg_match_all('/<form\b[^>]*method="post"[^>]*>(.*?)<\/form>/si', $text, $formulare);
        foreach ($formulare[1] as $inhalt) {
            pruefe(str_contains($inhalt, 'Csrf::field()'), $name . ': POST-Formular mit CSRF-Feld');
        }
        if (str_contains($datei, '/geraetepruefung/')) {
            preg_match_all('/<td\b[^>]*>/i', $text, $zellen);
            pruefe(array_filter($zellen[0], static fn (string $td): bool => !str_contains($td, 'data-label=') && !str_contains($td, 'colspan=')) === [],
                $name . ': jede Zelle mit data-label');
        }
    }
} finally {
    alsBenutzer(null);
    foreach ($dosen as $d) {
        @unlink($d);
    }
}

ende('geraetepruefung-prueflinge');
