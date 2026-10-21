<?php
declare(strict_types=1);

/**
 * Kundenbereich: Kundenzugänge, Tor und Rechte.
 *
 * Ein Kundenzugang (users.kunde_id) hat keine Rechte – auch nicht über eine
 * Rolle, die ihm jemand per SQL gibt –, sieht Standorte nur über Freigaben
 * an seinem Kunden (nur Standort, nur sehen, nie ohne Datengrenze; Konto-
 * und Rollen-Freigaben zählen nicht) und endet mit seinem Kunden. Die
 * Benutzerverwaltung lässt Kundenzugänge in Ruhe. Über HTTP (eigener
 * PHP-Server): das Tor über alle Routen aus public/index.php – GET und POST
 * mit gültigem Token führen zurück in den Kundenbereich, mit JSON 403, und
 * nichts ändert sich –, Fehlerseiten im Kundenlayout, Personal bekommt 403,
 * vorläufiges Kennwort führt ins Konto, Kunde beendet beendet die Sitzung.
 *
 * Die Prüfung legt eigene Kunden, Zugänge, Standorte und Rollen mit
 * eindeutiger Kennung an und löscht sie am Ende.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Kunden\Kundenbereich;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Response;
use App\Support\Zeit;

if (!function_exists('curl_init')) {
    uebersprungen('kundenbereich', 'die PHP-Erweiterung curl fehlt');
}

$kennung = 'pm-kb-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '1';
$jetzt = time();
$tag = 86400;
$kunden = [];
$zugaenge = [];
$personal = [];
$rollen = [];
$sites = [];
$dosen = [];
$auditVorher = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log');

/** Ein Kunde – direkt in der Tabelle (die Kundenverwaltung kommt später). */
$kundeAnlegen = static function (string $name, int $aktiv = 1) use ($kennung): int {
    return Database::insert('kunden', ['name' => $kennung . ' ' . $name, 'kundennummer' => 'K-' . random_int(10000, 99999),
        'aktiv' => $aktiv, 'created_at' => gmdate('c')]);
};
/** Ein Kundenzugang: Konto mit kunde_id, ohne Rolle. */
$zugangAnlegen = static function (string $name, int $kundeId, int $mussAendern = 0) use ($kennung, $kennwort): int {
    return Database::insert('users', ['username' => $kennung . '-' . $name, 'full_name' => 'Zugang ' . $name, 'email' => '',
        'password_hash' => password_hash($kennwort, PASSWORD_DEFAULT), 'is_active' => 1, 'must_change_password' => $mussAendern,
        'kunde_id' => $kundeId, 'created_at' => gmdate('c')]);
};
/** Eingabe wie aus dem Formular (datetime-local, Ortszeit). */
$eingabe = static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(Zeit::zone())->format('Y-m-d\TH:i');
/** Eine Freigabe direkt in der Tabelle – so, wie sie per SQL dastünde. */
$freigabeRoh = static function (array $daten): int {
    return Database::insert('freigaben', $daten + ['ziel_kanal' => '', 'darf_bearbeiten' => 0, 'darf_steuern' => 0,
        'gueltig_ab' => null, 'gueltig_bis' => null, 'daten_ab' => null, 'daten_bis' => null, 'notiz' => '',
        'created_by' => 'pruefung', 'created_at' => gmdate('c')]);
};

// ------------------------------------------------------------ HTTP-Hilfen

/** Ein Aufruf mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function anfrage(string $basis, string $dose, string $methode, string $pfad, array $felder = [], array $kopf = [], bool $roh = false): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $kopf,
        CURLOPT_PATH_AS_IS     => $roh,
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

/** Anmelden; die Cookie-Dose kommt in $dosen. @return array{dose:string,ziel:?string} */
function anmeldenAls(string $basis, string $name, string $kennwort, array &$dosen): array
{
    $dose = (string) tempnam(sys_get_temp_dir(), 'pm-kb-');
    $dosen[] = $dose;
    $seite = anfrage($basis, $dose, 'GET', '/login');
    $r = anfrage($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => csrfAus($seite['text'])]);
    return ['dose' => $dose, 'ziel' => $r['ziel']];
}

/** Die Meldung auf der Anmeldeseite (nach einer Weiterleitung dorthin). */
function anmeldeMeldung(string $basis, string $dose): string
{
    $seite = anfrage($basis, $dose, 'GET', '/login')['text'];
    preg_match_all('/<div class="flash [a-z]+">([^<]*)<\/div>/', $seite, $m);
    return html_entity_decode(implode(' ', $m[1]), ENT_QUOTES, 'UTF-8');
}

/** Kopfzeile einer Antwort (klein geschrieben gesucht). */
function kopfzeile(array $r, string $name): string
{
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $r['kopf'], $m) ? trim($m[1]) : '';
}

/**
 * PHP-Meldungen, die der Server seit dem letzten Aufruf auf die
 * Standardfehlerausgabe geschrieben hat.
 *
 * @return list<string>
 */
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
 * Alle Routen aus public/index.php – auch die aus foreach-Schleifen –, {x} → 1.
 *
 * @return list<array{0:string,1:string}> [Methode, Pfad]
 */
function alleRouten(): array
{
    $quelle = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    $routen = [];
    preg_match_all("/\\\$router->(get|post)\\('([^']+)'\\s*,/", $quelle, $m, PREG_SET_ORDER);
    foreach ($m as $treffer) {
        $routen[] = [strtoupper($treffer[1]), $treffer[2]];
    }
    preg_match_all("/foreach \\(\\[([^\\]]+)\\] as \\\$aktion\\) \\{\\s*\\\$router->(get|post)\\('([^']+)' \\. \\\$aktion/", $quelle, $m, PREG_SET_ORDER);
    foreach ($m as $treffer) {
        preg_match_all("/'([^']+)'/", $treffer[1], $aktionen);
        foreach ($aktionen[1] as $aktion) {
            $routen[] = [strtoupper($treffer[2]), $treffer[3] . $aktion];
        }
    }
    return array_map(static fn (array $r): array => [$r[0], (string) preg_replace('/\{\w+\}/', '1', $r[1])], $routen);
}

try {
    // ------------------------------------------------ Bestand
    $sites['a'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände A (geteilt)', 'notes' => $kennung . '-notiz']);
    $sites['b'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände B']);
    $sites['c'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände C']);
    $kunden['k1'] = $kundeAnlegen('Muster Events');
    $kunden['k2'] = $kundeAnlegen('Stadtmarketing');
    $kunden['k3'] = $kundeAnlegen('Beendet', 0);
    $kunden['k4'] = $kundeAnlegen('Ohne Datengrenze');
    $zugaenge['z1'] = $zugangAnlegen('z1', $kunden['k1']);
    $zugaenge['z1b'] = $zugangAnlegen('z1b', $kunden['k1']);
    $zugaenge['z2'] = $zugangAnlegen('z2', $kunden['k2']);
    $zugaenge['z3'] = $zugangAnlegen('z3', $kunden['k3']);
    $zugaenge['z4'] = $zugangAnlegen('z4', $kunden['k4']);

    $personal['admin'] = BenutzerService::anlegen($kennung . '-admin', $kennwort, 'admin', false);
    $personal['planung'] = BenutzerService::anlegen($kennung . '-planung', $kennwort, 'planung', false);
    $personal['alt'] = BenutzerService::anlegen($kennung . '-alt', $kennwort, 'kunde', false);   // Rolle „kunde“ (bisheriger Weg)
    $rollen['ohnealles'] = (int) BenutzerService::rolleSpeichern(null, $kennung . ' ohne alles', '',
        ['kunden.view', 'kunden.manage', 'system.users.manage', 'dashboard.view'])['id'];
    $personal['ohnealles'] = BenutzerService::anlegen($kennung . '-ohnealles', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$rollen['ohnealles']]), false);
    $rolleKunde = (int) Database::scalar("SELECT id FROM roles WHERE role_key = 'kunde'");
    $rolleAdmin = (int) Database::scalar('SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1');

    // ------------------------------------------------ Rechte vergeben: Standardrollen
    alsBenutzer($personal['planung']);
    pruefe(Auth::can('kunden.view') && Auth::can('kunden.manage') && Auth::can('kunden.nachweise'), 'Stromplanung: alle Kunden-Rechte');
    $rechteKunde = BenutzerService::rollenRechte($rolleKunde);
    pruefe(preg_grep('/^kunden\./', $rechteKunde) === [], 'die Rolle „kunde“ hat keine Kunden-Rechte');
    $technik = (int) Database::scalar("SELECT id FROM roles WHERE role_key = 'technik'");
    $rechteTechnik = BenutzerService::rollenRechte($technik);
    pruefe(in_array('kunden.view', $rechteTechnik, true) && in_array('kunden.nachweise', $rechteTechnik, true)
        && !in_array('kunden.manage', $rechteTechnik, true), 'Elektrotechnik: ansehen und Nachweise, nicht pflegen');

    // ------------------------------------------------ Kunden-Freigaben anlegen
    alsBenutzer($personal['admin']);
    $k1a = BenutzerService::freigabeFuerKunde($kunden['k1'], ['site_id' => $sites['a'], 'gueltig_ab' => $eingabe($jetzt - 3 * $tag),
        'gueltig_bis' => $eingabe($jetzt + 7 * $tag), 'daten_wie_gueltig' => 1, 'notiz' => 'Sommerfestival']);
    pruefe($k1a['ok'], 'Kunden-Freigabe K1 → A angelegt: ' . $k1a['message']);
    $k2a = BenutzerService::freigabeFuerKunde($kunden['k2'], ['site_id' => $sites['a'], 'gueltig_ab' => $eingabe($jetzt - 20 * $tag),
        'gueltig_bis' => $eingabe($jetzt - 10 * $tag), 'daten_ab' => $eingabe($jetzt - 20 * $tag), 'daten_bis' => $eingabe($jetzt - 12 * $tag)]);
    pruefe($k2a['ok'], 'Kunden-Freigabe K2 → A (abgelaufen) angelegt');
    $k2b = BenutzerService::freigabeFuerKunde($kunden['k2'], ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + 5 * $tag), 'daten_wie_gueltig' => 1]);
    pruefe($k2b['ok'], 'Kunden-Freigabe K2 → B angelegt');
    $k2c = BenutzerService::freigabeFuerKunde($kunden['k2'], ['site_id' => $sites['c'], 'gueltig_ab' => $eingabe($jetzt + 2 * $tag),
        'gueltig_bis' => $eingabe($jetzt + 9 * $tag), 'daten_wie_gueltig' => 1]);
    pruefe($k2c['ok'], 'Kunden-Freigabe K2 → C (kommend) angelegt');
    $k3a = BenutzerService::freigabeFuerKunde($kunden['k3'], ['site_id' => $sites['a'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1]);
    pruefe($k3a['ok'], 'Freigabe auch für einen beendeten Kunden speicherbar (wirkt nicht)');

    $abgelehnt = [
        'ohne Ende'                     => ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt), 'daten_wie_gueltig' => 1],
        'ohne Datenzeitraum'            => ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt), 'gueltig_bis' => $eingabe($jetzt + $tag)],
        'nur ein Datenende'             => ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt), 'gueltig_bis' => $eingabe($jetzt + $tag),
                                            'daten_ab' => $eingabe($jetzt)],
        '„wie Gültigkeit“ ohne Beginn'  => ['site_id' => $sites['b'], 'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1],
        'Ende vor Beginn'               => ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt + $tag), 'gueltig_bis' => $eingabe($jetzt),
                                            'daten_ab' => $eingabe($jetzt - $tag), 'daten_bis' => $eingabe($jetzt)],
        'kein Datum'                    => ['site_id' => $sites['b'], 'gueltig_ab' => 'gestern', 'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1],
        'Standort gibt es nicht'        => ['site_id' => 999999999, 'gueltig_ab' => $eingabe($jetzt), 'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1],
    ];
    $vorher = (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'kunde'");
    foreach ($abgelehnt as $fall => $daten) {
        pruefe(!BenutzerService::freigabeFuerKunde($kunden['k4'], $daten)['ok'], 'Kunden-Freigabe abgelehnt: ' . $fall);
    }
    pruefe(!BenutzerService::freigabeFuerKunde(999999999, ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1])['ok'], 'Kunden-Freigabe abgelehnt: Kunde gibt es nicht');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'kunde'"), $vorher, 'nichts Abgelehntes gespeichert');

    // Was das Formular sonst schickt, gilt nicht.
    $erzwungen = BenutzerService::freigabeFuerKunde($kunden['k4'], ['site_id' => $sites['b'], 'ziel_art' => 'alles', 'darf_steuern' => 1,
        'darf_bearbeiten' => 1, 'ziel_kanal' => 'switch:0', 'subject_type' => 'user', 'subject_id' => $personal['admin'],
        'gueltig_ab' => $eingabe($jetzt - $tag), 'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1, 'notiz' => "Fest\x01ival"]);
    pruefe($erzwungen['ok'], 'Kunden-Freigabe mit fremden Feldern gespeichert');
    $zeile = Database::first('SELECT * FROM freigaben WHERE id = ?', [(int) ($erzwungen['id'] ?? 0)]) ?? [];
    gleich([$zeile['subject_type'] ?? '', (int) ($zeile['subject_id'] ?? 0), $zeile['ziel_art'] ?? '', (int) ($zeile['ziel_id'] ?? 0),
        (int) ($zeile['darf_bearbeiten'] ?? 1), (int) ($zeile['darf_steuern'] ?? 1), $zeile['ziel_kanal'] ?? '?', $zeile['notiz'] ?? ''],
        ['kunde', $kunden['k4'], 'site', $sites['b'], 0, 0, '', 'Fest ival'], 'gespeichert als Standort, nur sehen, am Kunden');
    pruefe(($zeile['daten_ab'] ?? null) === ($zeile['gueltig_ab'] ?? '') && ($zeile['daten_bis'] ?? null) === ($zeile['gueltig_bis'] ?? ''),
        '„wie Gültigkeit“: Datenzeitraum = Gültigkeit');
    Database::delete('freigaben', (int) ($erzwungen['id'] ?? 0));
    $audit = Database::first("SELECT * FROM audit_log WHERE action = 'kunden.freigabe_anlegen' AND target_id = ? ORDER BY id DESC LIMIT 1",
        [(string) $kunden['k4']]) ?? [];
    $details = json_decode((string) ($audit['details'] ?? '{}'), true);
    gleich([$details['kunde_id'] ?? null, $details['site_id'] ?? null, $details['ueberschneidung'] ?? null],
        [$kunden['k4'], $sites['b'], false], 'Audit kunden.freigabe_anlegen mit IDs');
    pruefe(!str_contains((string) ($audit['details'] ?? ''), $kennung), 'Audit ohne Kundennamen');

    alsBenutzer($personal['ohnealles']);
    pruefe(!BenutzerService::freigabeFuerKunde($kunden['k4'], ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1])['ok'], 'ohne „Alle Standorte …“: keine Kunden-Freigabe');
    alsBenutzer($personal['planung']);
    $planung = BenutzerService::freigabeFuerKunde($kunden['k4'], ['site_id' => $sites['c'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1]);
    pruefe($planung['ok'], 'Stromplanung (sieht alles) darf Standorte für Kunden freigeben');
    Database::delete('freigaben', (int) ($planung['id'] ?? 0));

    // Per SQL: was Kundenzugängen nicht helfen darf.
    $freigabeRoh(['subject_type' => 'user', 'subject_id' => $zugaenge['z1'], 'ziel_art' => 'site', 'ziel_id' => $sites['b']]);
    $freigabeRoh(['subject_type' => 'role', 'subject_id' => $rolleKunde, 'ziel_art' => 'site', 'ziel_id' => $sites['b'],
        'notiz' => $kennung]);
    $freigabeRoh(['subject_type' => 'kunde', 'subject_id' => $kunden['k1'], 'ziel_art' => 'alles']);
    $objekt = (int) Database::insert('objekte', ['art' => 'verteilung', 'name' => $kennung . ' Verteiler B', 'site_id' => $sites['b'],
        'created_at' => gmdate('c')]);
    $freigabeRoh(['subject_type' => 'kunde', 'subject_id' => $kunden['k1'], 'ziel_art' => 'objekt', 'ziel_id' => $objekt]);
    Database::insert('user_roles', ['user_id' => $zugaenge['z1'], 'role_id' => $rolleAdmin, 'gueltig_ab' => null, 'gueltig_bis' => null]);
    Database::insert('user_roles', ['user_id' => $zugaenge['z1'], 'role_id' => $rolleKunde, 'gueltig_ab' => null, 'gueltig_bis' => null]);
    Database::execute('UPDATE freigaben SET darf_bearbeiten = 1, darf_steuern = 1, ziel_kanal = ? WHERE id = ?', ['switch:0', (int) $k1a['id']]);
    $freigabeRoh(['subject_type' => 'kunde', 'subject_id' => $kunden['k4'], 'ziel_art' => 'site', 'ziel_id' => $sites['a'],
        'gueltig_ab' => Zeit::iso($jetzt - $tag), 'gueltig_bis' => Zeit::iso($jetzt + $tag)]);
    $freigabeRoh(['subject_type' => 'user', 'subject_id' => $personal['alt'], 'ziel_art' => 'site', 'ziel_id' => $sites['a']]);

    // ------------------------------------------------ Rechte eines Kundenzugangs
    alsBenutzer($zugaenge['z1']);
    gleich(Auth::permissions(), [], 'Kundenzugang: keine Rechte – auch mit per SQL zugewiesener Administration');
    pruefe(!Auth::can('*') && !Auth::can('dashboard.view') && !Auth::can('kunden.view'), 'Kundenzugang: Auth::can() immer false');
    pruefe(!Zugriff::alles(), 'Kundenzugang sieht nicht alles');
    gleich(Auth::landingPath(), '/kundenbereich', 'Kundenzugang: Startseite ist der Kundenbereich');
    gleich(Kundenbereich::kundeId(), $kunden['k1'], 'kundeId() des angemeldeten Zugangs');
    gleich((string) (Kundenbereich::kunde()['name'] ?? ''), $kennung . ' Muster Events', 'kunde() des angemeldeten Zugangs');
    pruefe(!Zugriff::darfStandortAendern($sites['a']) && !Zugriff::darfAufStandortAnlegen($sites['a']),
        'Kunden-Freigabe mit „bearbeiten“ per SQL: trotzdem nicht ändern');
    pruefe(!Zugriff::darfSteuern(['id' => 1, 'geraet' => $kennung . '-geraet']), 'Kundenzugang schaltet nie');
    // Fehlerseiten (auch der 500-Weg in bootstrap.php) für Kundenzugänge im Kundenlayout.
    [$seite, $imKundenbereich] = Response::fehlerSeite(500, 'Unerwarteter Fehler. Details stehen im Log.');
    pruefe($imKundenbereich && str_contains($seite, 'unerwarteter Fehler') && str_contains($seite, 'Kundenbereich · ' . $kennung . ' Muster Events')
        && !str_contains($seite, 'Details stehen im Log'), 'Fehlerseite 500 eines Kundenzugangs: im Kundenlayout, ohne Einzelheiten');

    // ------------------------------------------------ Freigaben eines Kundenzugangs
    $wirksam = Zugriff::freigaben();
    pruefe($wirksam !== [] && array_filter($wirksam, static fn (array $f): bool => $f['subject_type'] !== 'kunde'
        || (int) $f['subject_id'] !== $kunden['k1']) === [], 'Zugriff::freigaben() für z1: nur Freigaben am Kunden K1');
    gleich(array_map(static fn (array $f): string => $f['ziel_art'] . ':' . $f['ziel_id'], $wirksam), ['site:' . $sites['a']],
        'nur Standorte – „alles“ und Einzelanlagen am Kunden zählen nicht');
    gleich([(int) $wirksam[0]['darf_bearbeiten'], (int) $wirksam[0]['darf_steuern'], $wirksam[0]['ziel_kanal']], [0, 0, ''],
        'bearbeiten, schalten und Kanal einer Kunden-Freigabe gelten nie');
    pruefe(Zugriff::siehtStandort($sites['a']), 'z1 sieht A');
    pruefe(!Zugriff::siehtStandort($sites['b']), 'z1 sieht B nicht – trotz Freigabe am Konto und an der Rolle „kunde“');
    pruefe(!Zugriff::siehtObjekt(['id' => $objekt, 'site_id' => $sites['b']]), 'z1 sieht die Anlage auf B nicht');
    gleich(Zugriff::datenFensterStandort($sites['a']), [[Zeit::ts(Zeit::ausEingabe($eingabe($jetzt - 3 * $tag))),
        Zeit::ts(Zeit::ausEingabe($eingabe($jetzt + 7 * $tag), true))]], 'Datenfenster auf A = das der Kunden-Freigabe');
    alsBenutzer($zugaenge['z1b']);
    gleich(array_column(Zugriff::freigaben(), 'id'), [(int) $k1a['id']], 'z1b (zweiter Zugang von K1) sieht dasselbe');
    alsBenutzer($zugaenge['z2']);
    pruefe(!Zugriff::siehtStandort($sites['a']) && Zugriff::siehtStandort($sites['b']) && !Zugriff::siehtStandort($sites['c']),
        'z2: A abgelaufen, B wirksam, C kommt erst');
    gleich(array_map(static fn (array $f): int => (int) $f['ziel_id'], Kundenbereich::kommend($kunden['k2'])), [$sites['c']], 'kommend: C');
    gleich(array_column(Kundenbereich::freigaben($kunden['k2'], $jetzt + 3 * $tag), 'ziel_id'), [$sites['b'], $sites['c']],
        'mit eigener Uhrzeit: in drei Tagen B und C');
    gleich(array_column(Kundenbereich::freigaben($kunden['k2']), 'ziel_id'), [$sites['b']], '… ohne den Zwischenspeicher zu ändern');
    alsBenutzer($zugaenge['z4']);
    $fenster = Zugriff::datenFensterStandort($sites['a']);
    pruefe($fenster !== null, 'Kunden-Freigabe ohne Datenenden (per SQL): Datenfenster nicht „ohne Grenze“');
    gleich(Zugriff::begrenzen($fenster, $jetzt - 365 * $tag, $jetzt + 365 * $tag), [], '… und kein Zeitraum lässt sich abfragen');
    alsBenutzer($personal['alt']);
    pruefe(Zugriff::siehtStandort($sites['a']) && !Zugriff::siehtStandort($sites['c']), 'Konto mit Rolle „kunde“ (ohne kunde_id): wie bisher über Konto-Freigaben');
    gleich(Auth::landingPath(), '/dashboard', 'Konto mit Rolle „kunde“: Startseite wie bisher');
    pruefe(Response::fehlerSeite(404, 'Seite nicht gefunden.')[1] === false, 'Fehlerseite des Personals: die schlichte');
    alsBenutzer($personal['admin']);
    gleich(array_column(Zugriff::freigaben($zugaenge['z1']), 'id'), [(int) $k1a['id']], 'Personal fragt die Freigaben eines Kundenzugangs: dieselben');

    // ------------------------------------------------ Sperre
    $z3 = BenutzerService::find($zugaenge['z3']) ?? [];
    gleich(Auth::zugangSperre($z3), Kundenbereich::NICHT_AKTIV, 'Kunde inaktiv: Zugang gesperrt');
    gleich(Kundenbereich::freigaben($kunden['k3']), [], 'Kunde inaktiv: keine Freigaben');
    gleich(Auth::zugangSperre(BenutzerService::find($personal['admin']) ?? []), null, 'Personal: keine Kunden-Sperre');
    gleich(Auth::attempt($kennung . '-z3', $kennwort, '198.51.100.7')['message'], Kundenbereich::NICHT_AKTIV,
        'Anmeldung mit richtigem Kennwort: „nicht mehr aktiv“');
    gleich(Auth::attempt($kennung . '-z3', 'falsch-' . $kennwort, '198.51.100.7')['message'], 'Benutzername oder Passwort falsch.',
        'Anmeldung mit falschem Kennwort: nur die allgemeine Meldung');
    $grund = json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'auth.login_failed' AND username = ? ORDER BY id LIMIT 1",
        [$kennung . '-z3']), true);
    gleich($grund['reason'] ?? null, 'Kunde nicht aktiv', 'Audit: Grund „Kunde nicht aktiv“');
    Database::update('kunden', $kunden['k1'], ['gueltig_bis' => gmdate('c', $jetzt - $tag)]);
    Kundenbereich::vergessen();
    gleich(Auth::zugangSperre(BenutzerService::find($zugaenge['z1']) ?? []), Kundenbereich::NICHT_AKTIV, 'Kunde abgelaufen: Zugang gesperrt');
    gleich(Kundenbereich::freigaben($kunden['k1']), [], 'Kunde abgelaufen: keine Freigaben');
    gleich(Kundenbereich::kundeSperre($kunden['k1'], $jetzt - 2 * $tag), null, '… vorgestern galt er noch');
    Database::update('kunden', $kunden['k1'], ['gueltig_bis' => null]);
    Kundenbereich::vergessen();
    gleich(Kundenbereich::kundeSperre(999999999), Kundenbereich::NICHT_AKTIV, 'gelöschter Kunde: gesperrt');

    // ------------------------------------------------ Benutzerverwaltung
    alsBenutzer($personal['admin']);
    $ids = array_map(static fn (array $u): int => (int) $u['id'], BenutzerService::alle());
    pruefe(!in_array($zugaenge['z1'], $ids, true) && in_array($personal['alt'], $ids, true), 'Benutzerliste ohne Kundenzugänge');
    pruefe(BenutzerService::kundenzugaenge() >= 5, 'Zahl der Kundenzugänge');
    pruefe(!BenutzerService::darfVerwalten($zugaenge['z1']), 'Kundenzugang: auch die Administration verwaltet ihn hier nicht');
    $hashVorher = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['z1']]);
    pruefe(!BenutzerService::speichern($zugaenge['z1'], ['username' => $kennung . '-z1', 'password' => 'Neu-' . $kennwort, 'is_active' => 1],
        [$rolleAdmin => ['aktiv' => 1]])['ok'], 'speichern() lehnt Kundenzugänge ab');
    gleich((string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['z1']]), $hashVorher, '… Kennwort unverändert');
    pruefe(!BenutzerService::loeschen($zugaenge['z1'])['ok'] && BenutzerService::find($zugaenge['z1']) !== null, 'loeschen() lehnt Kundenzugänge ab');
    pruefe(!BenutzerService::entsperren($zugaenge['z1'])['ok'], 'entsperren() lehnt Kundenzugänge ab');
    $vorher = (int) Database::scalar('SELECT COUNT(*) FROM freigaben');
    pruefe(!BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $zugaenge['z1'], 'ziel_art' => 'site',
        'ziel_id' => $sites['b']])['ok'], 'keine Freigabe am Konto eines Kundenzugangs');
    pruefe(!BenutzerService::freigabeSpeichern(['subject_type' => 'kunde', 'subject_id' => $kunden['k1'], 'ziel_art' => 'site',
        'ziel_id' => $sites['b']])['ok'], 'freigabeSpeichern() nimmt kein Subjekt „kunde“');
    pruefe(!BenutzerService::freigabeSpeichern(['subject_type' => 'gruppe', 'subject_id' => $personal['alt'], 'ziel_art' => 'site',
        'ziel_id' => $sites['b']])['ok'], 'freigabeSpeichern(): unbekannter Subjekttyp wird nicht zu „user“');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM freigaben'), $vorher, 'nichts davon gespeichert');
    pruefe(!BenutzerService::freigabeLoeschen((int) $k1a['id'])['ok'] && Database::first('SELECT id FROM freigaben WHERE id = ?', [(int) $k1a['id']]) !== null,
        'freigabeLoeschen() entfernt keine Kunden-Freigabe');

    // ------------------------------------------------ HTTP
    $port = freierPort();
    $serverFehler = (string) tempnam(sys_get_temp_dir(), 'pm-kb-fehler-');
    $dosen[] = $serverFehler;
    hintergrund([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    if (!erreichbar('127.0.0.1', $port)) {
        throw new RuntimeException('eingebauter PHP-Server startet nicht');
    }
    $basis = 'http://127.0.0.1:' . $port;
    alsBenutzer(null);

    $z1 = anmeldenAls($basis, $kennung . '-z1', $kennwort, $dosen);
    gleich($z1['ziel'], '/kundenbereich', 'Anmeldung eines Kundenzugangs → Kundenbereich');
    $start = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich');
    gleich($start['status'], 200, 'Kundenbereich: 200');
    pruefe(str_contains($start['text'], $kennung . ' Muster Events'), 'Kundenbereich zeigt den Kunden');
    $token = csrfAus($start['text']);
    pruefe($token !== '', 'Token aus dem Kundenbereich (Abmelden)');
    $seiten = ['/kundenbereich' => $start, '/kundenbereich/konto' => anfrage($basis, $z1['dose'], 'GET', '/kundenbereich/konto')];
    foreach ($seiten as $pfad => $r) {
        gleich($r['status'], 200, $pfad . ': 200');
        gleich(kopfzeile($r, 'Cache-Control'), 'private, no-store', $pfad . ': Cache-Control private, no-store');
        gleich(kopfzeile($r, 'Referrer-Policy'), 'no-referrer', $pfad . ': Referrer-Policy no-referrer');
        pruefe(!str_contains($r['text'], 'onclick=') && !str_contains($r['text'], 'javascript:'), $pfad . ': kein Inline-JavaScript');
        preg_match_all('/<script\b[^>]*>/i', $r['text'], $skripte);
        pruefe(array_filter($skripte[0], static fn (string $t): bool => !str_contains($t, ' src=') && !str_contains($t, ' nonce=')) === [],
            $pfad . ': jedes <script> mit src oder Nonce');
        pruefe(!str_contains($r['text'], 'nav-backdrop') && !str_contains($r['text'], 'href="/profil"')
            && !str_contains($r['text'], '/system/benutzer') && !str_contains($r['text'], 'Datenquellen'), $pfad . ': keine Personal-Navigation');
        pruefe(str_contains($r['text'], 'action="/logout"'), $pfad . ': Abmelden als Formular');
    }
    gleich(meldungenDesServers($serverFehler), [], 'Kundenbereich ohne PHP-Meldung');

    // Das Tor über alle Routen.
    $zaehlen = static fn (): array => array_map(static fn (string $t): int => (int) Database::scalar('SELECT COUNT(*) FROM ' . $t),
        ['schaltvorgaenge' => 'schaltvorgaenge', 'users' => 'users', 'freigaben' => 'freigaben', 'berichte' => 'berichte', 'kunden' => 'kunden']);
    $bestand = $zaehlen();
    $routen = alleRouten();
    pruefe(count($routen) > 150, 'Routen aus public/index.php gelesen: ' . count($routen));
    $offen = 0;
    $falsch = [];
    foreach ($routen as [$methode, $pfad]) {
        if (Kundenbereich::erlaubt($pfad)) {
            $offen++;
            continue;
        }
        $r = anfrage($basis, $z1['dose'], $methode, $pfad, $methode === 'POST' ? ['_token' => $token, 'id' => 1] : []);
        if ($r['status'] !== 302 || $r['ziel'] !== '/kundenbereich') {
            $falsch[] = $methode . ' ' . $pfad . ' → ' . $r['status'] . ' ' . (string) $r['ziel'];
        }
        $j = anfrage($basis, $z1['dose'], $methode, $pfad, $methode === 'POST' ? ['_token' => $token] : [], ['Accept: application/json']);
        if ($j['status'] !== 403 || (json_decode($j['text'], true)['ok'] ?? null) !== false) {
            $falsch[] = $methode . ' ' . $pfad . ' (JSON) → ' . $j['status'];
        }
    }
    gleich($falsch, [], 'Tor: jede Route außerhalb des Kundenbereichs führt zurück (302), mit JSON 403');
    pruefe($offen >= 4, 'offen nur Kundenbereich, Anmelden, Abmelden, Manifest (' . $offen . ' Routen)');
    gleich($zaehlen(), $bestand, 'Tor: nichts geändert (Schaltvorgänge, Konten, Freigaben, Berichte, Kunden)');
    gleich(meldungenDesServers($serverFehler), [], 'Tor ohne PHP-Meldung');
    $r = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich/../system/benutzer', [], [], true);
    pruefe($r['status'] === 404 && !str_contains($r['text'], 'Benutzer &amp; Freigaben') && !str_contains($r['text'], 'Datenquellen'),
        '/kundenbereich/../system/benutzer: 404, keine Personalseite (' . $r['status'] . ')');
    $r = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich/gibt-es-nicht');
    pruefe($r['status'] === 404 && str_contains($r['text'], 'Kundenbereich · ' . $kennung . ' Muster Events')
        && str_contains($r['text'], 'gehört nicht zu Ihrem Zugang') && str_contains($r['text'], 'action="/logout"'),
        'unbekannte Seite im Kundenbereich: 404 im Kundenlayout');
    gleich(kopfzeile($r, 'Cache-Control'), 'private, no-store', '404 im Kundenbereich: private, no-store');
    $r405 = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich/konto/kennwort');
    gleich([$r405['status'], preg_replace('/nonce="[^"]*"|value="[^"]*"/', '', $r405['text'])],
        [404, preg_replace('/nonce="[^"]*"|value="[^"]*"/', '', $r['text'])], 'falsche Methode: dieselbe 404 wie eine unbekannte Seite');
    foreach (['/', '/gibt-es-nicht', '/profil', '/login/../system/benutzer'] as $pfad) {
        $r = anfrage($basis, $z1['dose'], 'GET', $pfad, [], [], true);
        gleich([$r['status'], $r['ziel']], [302, '/kundenbereich'], 'Kundenzugang ' . $pfad . ' → Kundenbereich');
    }
    $r = anfrage($basis, $z1['dose'], 'GET', '/login');
    gleich([$r['status'], $r['ziel']], [302, '/kundenbereich'], 'angemeldet /login → Kundenbereich');
    gleich(meldungenDesServers($serverFehler), [], 'Fehlerseiten ohne PHP-Meldung');

    // Personal.
    $admin = anmeldenAls($basis, $kennung . '-admin', $kennwort, $dosen);
    $r = anfrage($basis, $admin['dose'], 'GET', '/kundenbereich');
    pruefe($r['status'] === 403 && str_contains($r['text'], 'nur für Kundenzugänge'), 'Administration: /kundenbereich 403 „nur für Kundenzugänge“');
    $alt = anmeldenAls($basis, $kennung . '-alt', $kennwort, $dosen);
    gleich($alt['ziel'], '/dashboard', 'Konto mit Rolle „kunde“: Anmeldung wie bisher');
    gleich(anfrage($basis, $alt['dose'], 'GET', '/dashboard')['status'], 200, 'Konto mit Rolle „kunde“: /dashboard 200');
    gleich(anfrage($basis, $alt['dose'], 'GET', '/kundenbereich')['status'], 403, 'Konto mit Rolle „kunde“: /kundenbereich 403');
    $liste = anfrage($basis, $admin['dose'], 'GET', '/system/benutzer');
    pruefe($liste['status'] === 200 && !str_contains($liste['text'], $kennung . '-z1<') && str_contains($liste['text'], 'Kundenzugänge pflegt die Kundenverwaltung'),
        'Benutzerliste: ohne Kundenzugänge, mit Hinweis');
    $bearbeiten = anfrage($basis, $admin['dose'], 'GET', '/system/benutzer?id=' . $zugaenge['z1']);
    pruefe($bearbeiten['status'] === 200 && !str_contains($bearbeiten['text'], 'value="' . $kennung . '-z1"'), 'kein Formular für einen Kundenzugang');
    $f = anfrage($basis, $admin['dose'], 'GET', '/system/freigaben?art=user&id=' . $zugaenge['z1']);
    pruefe($f['status'] === 200 && str_contains($f['text'], 'Das ist ein Kundenzugang') && !str_contains($f['text'], 'action="/system/freigaben/speichern"'),
        'Freigaben eines Kundenzugangs: Hinweis statt Formular');
    $adminToken = csrfAus($liste['text']);
    $vorher = $zaehlen();
    anfrage($basis, $admin['dose'], 'POST', '/system/benutzer/loeschen', ['_token' => $adminToken, 'id' => $zugaenge['z1']]);
    anfrage($basis, $admin['dose'], 'POST', '/system/benutzer/speichern', ['_token' => $adminToken, 'id' => $zugaenge['z1'],
        'username' => $kennung . '-z1', 'password' => 'Neu-' . $kennwort, 'is_active' => 1, 'rollen' => [$rolleAdmin => ['aktiv' => 1]]]);
    anfrage($basis, $admin['dose'], 'POST', '/system/freigaben/speichern', ['_token' => $adminToken, 'subject_type' => 'user',
        'subject_id' => $zugaenge['z1'], 'ziel_art' => 'site', 'ziel_id' => $sites['b']]);
    anfrage($basis, $admin['dose'], 'POST', '/system/freigaben/speichern', ['_token' => $adminToken, 'subject_type' => 'kunde',
        'subject_id' => $kunden['k1'], 'ziel_art' => 'site', 'ziel_id' => $sites['b']]);
    $r = anfrage($basis, $admin['dose'], 'POST', '/system/freigaben/loeschen', ['_token' => $adminToken, 'id' => (int) $k1a['id']]);
    gleich($r['status'], 404, 'Kunden-Freigabe über die Benutzerverwaltung entfernen: 404');
    gleich($zaehlen(), $vorher, 'Benutzerverwaltung: Kundenzugang weder gelöscht noch geändert, keine Freigabe dazu');
    gleich((string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['z1']]), $hashVorher, '… Kennwort unverändert');
    gleich(meldungenDesServers($serverFehler), [], 'Personal-Seiten ohne PHP-Meldung');

    // Vorläufiges Kennwort: erst ins Konto.
    Database::update('users', $zugaenge['z1b'], ['must_change_password' => 1]);
    $z1b = anmeldenAls($basis, $kennung . '-z1b', $kennwort, $dosen);
    gleich($z1b['ziel'], '/kundenbereich/konto', 'vorläufiges Kennwort: Anmeldung → Konto im Kundenbereich');
    $zweite = anmeldenAls($basis, $kennung . '-z1b', $kennwort, $dosen);
    $r = anfrage($basis, $z1b['dose'], 'GET', '/kundenbereich');
    gleich([$r['status'], $r['ziel']], [302, '/kundenbereich/konto'], 'vorläufiges Kennwort: Kundenbereich → Konto');
    $r = anfrage($basis, $z1b['dose'], 'GET', '/profil');
    gleich([$r['status'], $r['ziel']], [302, '/kundenbereich'], 'vorläufiges Kennwort: /profil bleibt zu (Tor)');
    $konto = anfrage($basis, $z1b['dose'], 'GET', '/kundenbereich/konto');
    pruefe($konto['status'] === 200 && str_contains($konto['text'], 'Bitte legen Sie zuerst ein eigenes Kennwort fest.')
        && str_contains($konto['text'], 'autocomplete="current-password"'), 'Konto: Hinweis und Kennwortformular');
    $hash = static fn (): string => (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['z1b']]);
    $hashZ1b = $hash();
    $neu = 'Neu-' . $kennwort;
    anfrage($basis, $z1b['dose'], 'POST', '/kundenbereich/konto/kennwort', ['current_password' => $kennwort, 'new_password' => $neu,
        'new_password_repeat' => $neu]);
    gleich($hash(), $hashZ1b, 'Kennwort ändern ohne CSRF-Token: nichts geändert');
    $r = anfrage($basis, $z1b['dose'], 'POST', '/kundenbereich/konto/kennwort', ['_token' => csrfAus($konto['text']),
        'current_password' => 'falsch', 'new_password' => $neu, 'new_password_repeat' => $neu]);
    pruefe($r['ziel'] === '/kundenbereich/konto' && $hash() === $hashZ1b, 'falsches aktuelles Kennwort: nichts geändert');
    $konto = anfrage($basis, $z1b['dose'], 'GET', '/kundenbereich/konto');
    $r = anfrage($basis, $z1b['dose'], 'POST', '/kundenbereich/konto/kennwort', ['_token' => csrfAus($konto['text']),
        'current_password' => $kennwort, 'new_password' => $neu, 'new_password_repeat' => $neu]);
    gleich([$r['status'], $r['ziel']], [302, '/kundenbereich'], 'Kennwort geändert → Übersicht');
    pruefe($hash() !== $hashZ1b && (int) Database::scalar('SELECT must_change_password FROM users WHERE id = ?', [$zugaenge['z1b']]) === 0,
        'neues Kennwort gespeichert, Pflicht erledigt');
    gleich(anfrage($basis, $z1b['dose'], 'GET', '/kundenbereich')['status'], 200, 'diese Sitzung läuft weiter');
    $r = anfrage($basis, $zweite['dose'], 'GET', '/kundenbereich');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'die zweite Sitzung mit dem alten Kennwort endet');
    gleich(meldungenDesServers($serverFehler), [], 'Kennwortwechsel ohne PHP-Meldung');

    // Abmelden aus dem Kundenbereich.
    $r = anfrage($basis, $z1b['dose'], 'POST', '/logout', ['_token' => csrfAus(anfrage($basis, $z1b['dose'], 'GET', '/kundenbereich')['text'])]);
    gleich([$r['status'], $r['ziel']], [302, '/login'], 'Abmelden aus dem Kundenbereich');
    $r = anfrage($basis, $z1b['dose'], 'GET', '/kundenbereich');
    gleich([$r['status'], $r['ziel']], [302, '/login?next=%2Fkundenbereich'], 'abgemeldet: Kundenbereich → Anmeldung');

    // Kunde beendet: die offene Sitzung endet bei der nächsten Anfrage.
    Database::update('kunden', $kunden['k1'], ['aktiv' => 0]);
    $r = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'Kunde beendet: offene Sitzung endet');
    $r = anfrage($basis, $z1['dose'], 'GET', '/system/benutzer');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'Kunde beendet: auch keine Personalseite');
    $versuch = anmeldenAls($basis, $kennung . '-z1', $kennwort, $dosen);
    pruefe($versuch['ziel'] === '/login' && str_contains(anmeldeMeldung($basis, $versuch['dose']), 'nicht mehr aktiv'),
        'Kunde beendet: Anmeldung mit richtigem Kennwort „nicht mehr aktiv“');
    $versuch = anmeldenAls($basis, $kennung . '-z1', 'falsch-' . $kennwort, $dosen);
    gleich(anmeldeMeldung($basis, $versuch['dose']), 'Benutzername oder Passwort falsch.', 'Kunde beendet: falsches Kennwort verrät nichts');
    gleich(meldungenDesServers($serverFehler), [], 'Kunde beendet ohne PHP-Meldung');

    // Datenschutz: Kundennamen nie im Audit.
    $namen = array_map(static fn (int $id): string => (string) Database::scalar('SELECT name FROM kunden WHERE id = ?', [$id]), $kunden);
    $treffer = 0;
    foreach (Database::select('SELECT details FROM audit_log WHERE id > ?', [$auditVorher]) as $a) {
        foreach ($namen as $n) {
            $treffer += str_contains((string) $a['details'], $n) ? 1 : 0;
        }
    }
    gleich($treffer, 0, 'kein Kundenname in audit_log.details');
} finally {
    alsBenutzer(null);
    $kundenIds = array_map('intval', array_column(Database::select('SELECT id FROM kunden WHERE name LIKE ?', [$kennung . '%']), 'id'));
    foreach ($kundenIds as $id) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$id]);
        foreach (Database::select('SELECT id FROM users WHERE kunde_id = ?', [$id]) as $u) {
            BenutzerService::kontoEntfernen((int) $u['id']);
            Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $u['id']]);
        }
        Database::delete('kunden', $id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'kunde' AND target_id = ?", [(string) $id]);
    }
    if (isset($rolleKunde)) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'role' AND subject_id = ? AND notiz = ?", [$rolleKunde, $kennung]);
    }
    Database::execute('DELETE FROM objekte WHERE name LIKE ?', [$kennung . '%']);
    foreach ($sites as $id) {
        SiteService::deleteSite($id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'site' AND target_id = ?", [(string) $id]);
    }
    foreach ($personal as $id) {
        BenutzerService::kontoEntfernen($id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach ($rollen as $id) {
        BenutzerService::rolleLoeschen($id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'role' AND target_id = ?", [(string) $id]);
    }
    Database::execute('DELETE FROM audit_log WHERE id > ? AND (username LIKE ? OR details LIKE ?)', [$auditVorher, $kennung . '%', '%' . $kennung . '%']);
    foreach ($dosen as $d) {
        @unlink($d);
    }
}
gleich(array_map(static fn (array $z): string => $z['action'] . ' ' . $z['target_type'] . ' ' . $z['target_id'],
    Database::select('SELECT action, target_type, target_id FROM audit_log WHERE id > ? ORDER BY id', [$auditVorher])), [],
    'aufgeräumt: kein Audit-Eintrag dieses Laufs übrig');
gleich((int) Database::scalar('SELECT COUNT(*) FROM kunden WHERE name LIKE ?', [$kennung . '%'])
    + (int) Database::scalar('SELECT COUNT(*) FROM users WHERE username LIKE ?', [$kennung . '%']), 0, 'aufgeräumt: keine Kunden und Konten übrig');

ende('kundenbereich');
