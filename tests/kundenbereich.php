<?php
declare(strict_types=1);

/**
 * Kundenbereich: Kundenzugänge, Tor und Rechte; Kundenverwaltung, Zugänge
 * und Einrichtungslink.
 *
 * Ein Kundenzugang (users.kunde_id) hat keine Rechte – auch nicht über eine
 * Rolle, die ihm jemand per SQL gibt –, sieht Standorte nur über Freigaben
 * an seinem Kunden (nur Standort, nur sehen, nie ohne Datengrenze; Konto-
 * und Rollen-Freigaben zählen nicht) und endet mit seinem Kunden. Die
 * Benutzerverwaltung lässt Kundenzugänge in Ruhe.
 *
 * Kundenverwaltung (KundenService, Einrichtung): Kunden anlegen und ändern,
 * Zugänge über vorläufiges Kennwort oder Einrichtungslink (nur der HMAC
 * gespeichert, einmal, mit Ablauf, nur aus app.url), Benutzernamen ohne
 * Rücksicht auf Groß-/Kleinschreibung frei, jede Zugangs- und Freigabe-ID nur
 * am eigenen Kunden, Überschneidung der Datenzeiträume mehrerer Kunden am
 * selben Gelände (Berühren zählt nicht), Löschen nur beendeter Kunden mit
 * Namen, Standort löschen nimmt die Kunden-Freigaben mit.
 *
 * Was ein Kunde sieht (KundenSicht, nur mit der Kunden-ID): Locations nur
 * aus wirksamen Freigaben am Kunden (mehrere Veranstalter auf einem Gelände),
 * kommende nur mit Name und „ab …“, je Location nur Etagen mit Plan und feste
 * Anschlüsse – nie Notizen, Fremdbestand, mobile Anlagen oder Vorlagen –,
 * jede Etage einzeln gegen die Location geprüft.
 *
 * Über HTTP (eigener PHP-Server): das Tor über alle Routen aus
 * public/index.php – GET und POST mit gültigem Token führen zurück in den
 * Kundenbereich, mit JSON 403, und nichts ändert sich –, Fehlerseiten im
 * Kundenlayout, Personal bekommt 403, vorläufiges Kennwort führt ins Konto,
 * Kunde beendet beendet die Sitzung; Seiten des Kundenbereichs (Übersicht,
 * Locations, Location, Konto, Hilfe) mit no-store und ohne Inline-Skript,
 * Pläne (Bild inline, PDF als Anhang, sandbox, neutraler Name), fremde und
 * nicht vorhandene IDs mit derselben 404; Kundenverwaltung mit allen Reitern,
 * Geheimnis nur einmal in der Antwort (no-store, Einmal-Marke), Einrichtungs-
 * link einlösen (CSRF, gleiche 404 für falsch/abgelaufen/verbraucht, nicht
 * in eine offene Sitzung), Überschneidung mit Rückfrage, CSRF und Rechte an
 * jeder neuen Route.
 *
 * Die Prüfung legt eigene Kunden, Zugänge, Standorte und Rollen mit
 * eindeutiger Kennung an und löscht sie am Ende.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Controllers\KundenController;
use App\Kunden\Einrichtung;
use App\Kunden\KundenEinstellungen;
use App\Kunden\Kundenbereich;
use App\Kunden\KundenService;
use App\Kunden\KundenSicht;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Response;
use App\Support\Settings;
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
$appUrlVorher = (string) Config::get('app.url', '');
$dateien = [];

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
    // Etagen: A1 mit Bild-Plan, A2 mit PDF-Plan (Seite 2), A3 ohne Plan, B1 mit Plan auf B.
    // Notizen und Fremdbestand tragen Marken, die der Kundenbereich nie zeigen darf.
    $marken = [$kennung . '-notiz', $kennung . '-etagennotiz', $kennung . '-fremd'];
    $planBytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $pdfBytes = "%PDF-1.4\n% " . $kennung . "\n%%EOF\n";
    $etagen = [];
    $etageAnlegen = static function (int $siteId, string $name, ?string $endung, string $inhalt = '') use ($kennung, $marken, &$etagen): int {
        $id = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => $name, 'notes' => $marken[1]]);
        $felder = ['fremdbestand' => json_encode(['dosen' => [['name' => $marken[2]]], 'racks' => [], 'aps' => []])];
        if ($endung !== null) {
            $datei = 'site' . $siteId . '_' . bin2hex(random_bytes(8)) . '.' . $endung;
            file_put_contents(SiteService::planDir() . '/' . $datei, $inhalt);
            $felder += ['plan_file' => $datei, 'plan_type' => $endung === 'pdf' ? 'pdf' : 'image', 'plan_page' => $endung === 'pdf' ? 2 : 1,
                'plan_original_name' => $kennung . ' Hallenplan "intern".' . $endung];
        }
        Database::update('floors', $id, $felder);
        return $etagen[] = $id;
    };
    $a1 = $etageAnlegen($sites['a'], 'Halle Süd', 'png', $planBytes);
    $a2 = $etageAnlegen($sites['a'], 'Außengelände', 'pdf', $pdfBytes);
    $a3 = $etageAnlegen($sites['a'], 'Keller', null);
    $b1 = $etageAnlegen($sites['b'], 'Halle B', 'png', $planBytes);
    // Feststromanschluss FA (fest) und mobile Verteilung MA auf A, dazu eine feste Vorlage.
    $fa = (int) Database::insert('objekte', ['art' => 'anschluss', 'name' => $kennung . ' Energiesäule FA', 'site_id' => $sites['a'], 'floor_id' => $a1,
        'fest' => 1, 'eigenschaften' => json_encode(['anschlussart' => 'Energiesäule', 'absicherung_a' => 63, 'netzbetreiber' => $kennung . '-netz']),
        'notizen' => $kennung . '-anlagennotiz', 'created_at' => gmdate('c')]);
    \App\Services\AnlagenService::anschlussAnlegen($fa, 'abgang', (int) Database::scalar("SELECT id FROM steckertypen WHERE schluessel = 'cee32'"));
    \App\Services\AnlagenService::anschlussAnlegen($fa, 'abgang', (int) Database::scalar("SELECT id FROM steckertypen WHERE schluessel = 'cee32'"));
    Database::insert('objekte', ['art' => 'verteilung', 'name' => $kennung . ' Mobilverteiler MA', 'site_id' => $sites['a'], 'floor_id' => $a1,
        'fest' => 0, 'created_at' => gmdate('c')]);
    Database::insert('objekte', ['art' => 'anschluss', 'name' => $kennung . ' Vorlage VA', 'site_id' => $sites['a'], 'fest' => 1, 'ist_vorlage' => 1,
        'created_at' => gmdate('c')]);
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
    $personal['technik'] = BenutzerService::anlegen($kennung . '-technik', $kennwort, 'technik', false);
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

    // ------------------------------------------------ KundenSicht: was ein Kunde sieht
    // Ohne angemeldetes Konto – KundenSicht rechnet nur mit der Kunden-ID.
    alsBenutzer(null);
    $orte = static fn (array $l): array => array_map(static fn (array $x): int => $x['site']['id'], $l);
    gleich($orte(KundenSicht::locations($kunden['k1'])), [$sites['a']], 'K1 sieht A (Konto-/Rollen-Freigaben auf B zählen nicht)');
    gleich($orte(KundenSicht::locations($kunden['k2'])), [$sites['b']], 'K2 sieht B – A ist abgelaufen, C kommt erst');
    gleich($orte(KundenSicht::locations($kunden['k2'], $jetzt + 3 * $tag)), [$sites['b'], $sites['c']], 'K2 in drei Tagen: B und C');
    gleich(KundenSicht::locations($kunden['k3']), [], 'beendeter Kunde: keine Location, trotz Freigabe');
    $k2kommend = KundenSicht::kommend($kunden['k2']);
    gleich([count($k2kommend), array_keys($k2kommend[0] ?? []), $k2kommend[0]['name'] ?? ''], [1, ['name', 'ab'], $kennung . ' Gelände C'],
        'kommend: nur Name und „ab …“');
    gleich(Zeit::ts($k2kommend[0]['ab'] ?? null), Zeit::ts(Zeit::ausEingabe($eingabe($jetzt + 2 * $tag))), '… ab dem Beginn der Freigabe');
    $spaeter = $freigabeRoh(['subject_type' => 'kunde', 'subject_id' => $kunden['k1'], 'ziel_art' => 'site', 'ziel_id' => $sites['a'],
        'gueltig_ab' => Zeit::iso($jetzt + 20 * $tag), 'gueltig_bis' => Zeit::iso($jetzt + 25 * $tag)]);
    Kundenbereich::vergessen();
    gleich([KundenSicht::kommend($kunden['k1']), count(KundenSicht::locations($kunden['k1'])[0]['freigaben'] ?? [])], [[], 1],
        'eine spätere Freigabe auf eine schon sichtbare Location: nicht unter „kommend“, nicht doppelt');
    Database::delete('freigaben', $spaeter);
    Kundenbereich::vergessen();
    gleich(KundenSicht::location($kunden['k1'], $sites['b']), null, 'location(K1, B) === null');
    gleich(KundenSicht::location($kunden['k2'], $sites['a']), null, 'location(K2, A) === null – Freigabe abgelaufen');
    gleich(KundenSicht::location($kunden['k1'], 999999999), null, 'location(K1, unbekannt) === null');
    gleich(KundenSicht::location($kunden['k3'], $sites['a']), null, 'location() eines beendeten Kunden === null');
    $la = KundenSicht::location($kunden['k1'], $sites['a']) ?? [];
    gleich(array_map(static fn (array $e): array => [$e['id'], $e['plan'], $e['seite']], $la['etagen'] ?? []), [[$a2, 'pdf', 2], [$a1, 'bild', 1]],
        'Location A: nur Etagen mit Plan (Bild, PDF mit Seite), ohne A3, ohne B1');
    gleich($la['feststrom'] ?? [], [['name' => $kennung . ' Energiesäule FA', 'art' => 'Energiesäule', 'etage' => 'Halle Süd',
        'anschluss' => '2× CEE 32 A', 'vorsicherung' => '63 A']], 'feste Anschlüsse: nur FA – nicht die mobile Verteilung, nicht die Vorlage');
    gleich([$la['freigaben'][0]['veranstaltung'] ?? '', Zeit::ts($la['freigaben'][0]['bis'] ?? null), $la['freigaben'][0]['daten'] ?? ''], ['Sommerfestival',
        Zeit::ts(Zeit::ausEingabe($eingabe($jetzt + 7 * $tag), true)), 'Messdaten vom ' . Zeit::anzeige(Zeit::ausEingabe($eingabe($jetzt - 3 * $tag)))
        . ' bis ' . Zeit::anzeige(Zeit::ausEingabe($eingabe($jetzt + 7 * $tag), true))], 'Freigabe: Veranstaltung, „sichtbar bis“, Messdaten');
    $json = (string) json_encode($la, JSON_UNESCAPED_UNICODE);
    $durch = array_filter(array_merge($marken, [$kennung . '-anlagennotiz', $kennung . '-netz', 'Mobilverteiler', 'Vorlage VA', 'Hallenplan', '"plan_file"']),
        static fn (string $m): bool => str_contains($json, $m));
    gleich(array_values($durch), [], 'location() ohne Notizen, Fremdbestand, Netzbetreiber, mobile Anlagen, Vorlagen, Dateinamen');
    gleich(KundenSicht::location($kunden['k4'], $sites['a'])['freigaben'][0]['daten'] ?? '', 'keine Messdaten freigegeben',
        'Freigabe ohne Datenenden (per SQL): „keine Messdaten freigegeben“');
    gleich((KundenSicht::etage($kunden['k1'], $sites['a'], $a1) ?? [])['id'] ?? null, $a1, 'etage(K1, A, A1): da');
    foreach ([
        'Etage eines anderen Standorts (B1 über A)' => [$kunden['k1'], $sites['a'], $b1],
        'Etage ohne Plan (A3)'                      => [$kunden['k1'], $sites['a'], $a3],
        'Standort nicht freigegeben (B1 über B)'    => [$kunden['k1'], $sites['b'], $b1],
        'Freigabe abgelaufen (K2, A1)'              => [$kunden['k2'], $sites['a'], $a1],
        'unbekannte Etage'                          => [$kunden['k1'], $sites['a'], 999999999],
        'beendeter Kunde'                           => [$kunden['k3'], $sites['a'], $a1],
    ] as $fall => [$kid, $sid, $fid]) {
        gleich(KundenSicht::etage($kid, $sid, $fid), null, 'etage(): null – ' . $fall);
    }
    $zf = KundenSicht::zusammenfassung($kunden['k1']);
    gleich([$zf['gesperrt'], $zf['locations'], $zf['kommend']], [null, KundenSicht::locations($kunden['k1']), []], 'zusammenfassung(K1) = was der Kundenbereich zeigt');
    gleich([KundenSicht::zusammenfassung($kunden['k3'])['gesperrt'], KundenSicht::zusammenfassung($kunden['k3'])['locations']], [Kundenbereich::NICHT_AKTIV, []],
        'zusammenfassung eines beendeten Kunden: gesperrt, nichts sichtbar');

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

    // ------------------------------------------------ Kundenverwaltung: Kunden
    alsBenutzer($personal['planung']);
    $neu = KundenService::speichern(null, ['name' => '  ' . $kennung . "  Einrichtung\x01 GmbH ", 'kundennummer' => 'kb-' . $kennung,
        'aktiv' => 1, 'notizen' => "Nur für uns\nzweite Zeile\x07"]);
    pruefe($neu['ok'], 'Kunde angelegt: ' . $neu['message']);
    $kunden['k5'] = (int) ($neu['id'] ?? 0);
    $k5 = KundenService::find($kunden['k5']) ?? [];
    gleich([$k5['name'] ?? '', $k5['notizen'] ?? '', (int) ($k5['created_by'] ?? 0)], [$kennung . ' Einrichtung GmbH', "Nur für uns\nzweite Zeile",
        $personal['planung']], 'Name ohne Steuerzeichen und doppelte Leerzeichen, Notizen mehrzeilig, angelegt von');
    foreach ([
        'Name zu kurz'                         => ['name' => 'x'],
        'Kundennummer doppelt (Schreibweise)'  => ['name' => $kennung . ' Doppel', 'kundennummer' => strtoupper('kb-' . $kennung)],
        'kein Datum'                           => ['name' => $kennung . ' Datum', 'gueltig_bis' => 'bald'],
        'Notizen zu lang'                      => ['name' => $kennung . ' Lang', 'notizen' => str_repeat('x', 5001)],
    ] as $fall => $daten) {
        pruefe(!KundenService::speichern(null, $daten)['ok'], 'Kunde abgelehnt: ' . $fall);
    }
    gleich((int) Database::scalar('SELECT COUNT(*) FROM kunden WHERE name LIKE ?', [$kennung . ' %']), count($kunden), 'nichts Abgelehntes gespeichert');
    pruefe(KundenService::speichern($kunden['k5'], ['name' => $kennung . ' Einrichtung GmbH', 'kundennummer' => 'kb-' . $kennung, 'aktiv' => 1])['ok'],
        'Kunde ändern: die eigene Kundennummer ist kein Doppel');
    pruefe(!KundenService::speichern(999999999, ['name' => $kennung . ' Fremd'])['ok'], 'Kunde ändern: unbekannte ID abgelehnt');
    $treffer = array_column(KundenService::alle(['suche' => 'EINRICHTUNG gmbh']), 'id');
    pruefe(in_array($kunden['k5'], array_map('intval', $treffer), true), 'Suche ohne Rücksicht auf Groß-/Kleinschreibung');
    $beendet = array_map('intval', array_column(KundenService::alle(['aktiv' => '0']), 'id'));
    pruefe(in_array($kunden['k3'], $beendet, true) && !in_array($kunden['k5'], $beendet, true), 'Filter „beendet“');
    $zeile = array_values(array_filter(KundenService::alle(['suche' => $kennung]), static fn (array $k): bool => (int) $k['id'] === $kunden['k1']))[0] ?? [];
    gleich([(int) ($zeile['zugaenge'] ?? -1), (int) ($zeile['locations_aktiv'] ?? -1)], [2, 1], 'Liste: K1 mit zwei Zugängen und einer Location');

    // ------------------------------------------------ Zugänge anlegen
    gleich(KundenService::zugang($kunden['k1'], $zugaenge['z2']), null, 'zugang(K1, Zugang von K2) === null');
    pruefe(KundenService::zugang($kunden['k1'], $zugaenge['z1']) !== null, 'zugang(K1, z1) gefunden');
    pruefe(!array_key_exists('password_hash', KundenService::zugang($kunden['k1'], $zugaenge['z1']) ?? ['password_hash' => 1])
        && !array_key_exists('einrichtung_hash', KundenService::zugaenge($kunden['k1'])[0] ?? ['einrichtung_hash' => 1]), 'Zugänge ohne Kennwort-Hash und Link-HMAC');
    $vorher = (int) Database::scalar('SELECT COUNT(*) FROM users');
    foreach ([
        'Name in anderer Schreibweise vorhanden' => [$kunden['k5'], ['username' => strtoupper($kennung) . '-Z1'], 'kennwort'],
        'Name eines Personal-Kontos'             => [$kunden['k5'], ['username' => $kennung . '-ADMIN'], 'link'],
        'Leerzeichen im Namen'                   => [$kunden['k5'], ['username' => 'a b'], 'kennwort'],
        'falsche E-Mail'                         => [$kunden['k5'], ['username' => $kennung . '-mail', 'email' => 'kein-at'], 'kennwort'],
        'unbekannter Weg'                        => [$kunden['k5'], ['username' => $kennung . '-weg'], 'sms'],
        'Kunde beendet'                          => [$kunden['k3'], ['username' => $kennung . '-k3neu'], 'kennwort'],
        'Kunde gibt es nicht'                    => [999999999, ['username' => $kennung . '-niemand'], 'kennwort'],
    ] as $fall => [$kid, $daten, $weg]) {
        pruefe(!KundenService::zugangAnlegen($kid, $daten, $weg)['ok'], 'Zugang abgelehnt: ' . $fall);
    }
    gleich((int) Database::scalar('SELECT COUNT(*) FROM users'), $vorher, 'kein abgelehnter Zugang angelegt');

    $mail = $kennung . '-kontakt@example.org';
    $zk = KundenService::zugangAnlegen($kunden['k5'], ['username' => $kennung . '-kw', 'full_name' => 'Erika Muster', 'email' => $mail], 'kennwort');
    pruefe($zk['ok'] && isset($zk['kennwort']) && !isset($zk['link']), 'Zugang mit vorläufigem Kennwort');
    $zugaenge['kw'] = (int) ($zk['user_id'] ?? 0);
    $konto = Database::first('SELECT * FROM users WHERE id = ?', [$zugaenge['kw']]) ?? [];
    gleich([(int) ($konto['kunde_id'] ?? 0), (int) ($konto['must_change_password'] ?? 0), (int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$zugaenge['kw']])],
        [$kunden['k5'], 1, 0], 'neues Konto: kunde_id, vorläufiges Kennwort, keine Rolle');
    pruefe(password_verify((string) ($zk['kennwort'] ?? ''), (string) ($konto['password_hash'] ?? '')) && strlen((string) ($zk['kennwort'] ?? '')) === 16,
        'das vorläufige Kennwort (16 Zeichen) gilt');
    gleich((KundenService::zugang($kunden['k5'], $zugaenge['kw']) ?? [])['zustand'] ?? '', 'erstanmeldung', 'Zustand: vorläufiges Kennwort');

    Config::set('app.url', '');
    $zl = KundenService::zugangAnlegen($kunden['k5'], ['username' => $kennung . '-link', 'email' => 'Link-' . $mail], 'link');
    pruefe($zl['ok'] && array_key_exists('link', $zl) && $zl['link'] === null && preg_match('#^/kundenbereich/einrichten/[A-Za-z0-9_-]{43}$#', (string) ($zl['pfad'] ?? '')) === 1
        && !isset($zl['kennwort']), 'ohne app.url: der Link-Weg liefert nur den Pfad');
    $zugaenge['link'] = (int) ($zl['user_id'] ?? 0);
    $token = substr((string) ($zl['pfad'] ?? ''), strlen(Einrichtung::PFAD));
    $konto = Database::first('SELECT * FROM users WHERE id = ?', [$zugaenge['link']]) ?? [];
    gleich((int) ($konto['must_change_password'] ?? 1), 0, 'Link-Weg: kein vorläufiges Kennwort');
    gleich((string) ($konto['einrichtung_hash'] ?? ''), hash_hmac('sha256', $token, Crypto::ableiten('kunden-einrichtung')), 'gespeichert ist nur der HMAC des Tokens');
    pruefe($konto['einrichtung_hash'] !== hash('sha256', $token) && $konto['einrichtung_hash'] !== $token, '… weder das Token noch sein SHA-256');
    $spalten = implode("\n", array_map(static fn ($w): string => (string) $w, $konto));
    pruefe(!str_contains($spalten, $token), 'das Token steht in keiner Spalte des Kontos');
    $stunden = (Zeit::ts((string) $konto['einrichtung_bis']) - time()) / 3600;
    pruefe($stunden > 71.9 && $stunden <= 72, 'Link gilt 72 Stunden (Vorgabe)');
    gleich((KundenService::zugang($kunden['k5'], $zugaenge['link']) ?? [])['zustand'] ?? '', 'link_offen', 'Zustand: Einrichtungslink offen');
    Config::set('app.url', 'https://kunden.example.test/pm/');
    gleich(Einrichtung::link($token), 'https://kunden.example.test/pm/kundenbereich/einrichten/' . $token, 'mit app.url: der ganze Link');
    foreach (['javascript:alert(1)', 'https://nutzer:geheim@kunden.example.test', 'https://kunden.example.test/?x=1', 'ftp://kunden.example.test', 'kunden.example.test'] as $url) {
        Config::set('app.url', $url);
        gleich(Einrichtung::link($token), null, 'app.url „' . $url . '“ taugt nicht für Links');
    }
    Config::set('app.url', 'http://kunden.example.test');
    pruefe(KundenEinstellungen::unverschluesselt(), 'http:// außerhalb von localhost: unverschlüsselt');
    Config::set('app.url', 'http://localhost:8080');
    pruefe(!KundenEinstellungen::unverschluesselt(), 'http://localhost: nicht als unverschlüsselt gemeldet');
    Config::set('app.url', $appUrlVorher);

    // ------------------------------------------------ Einrichtungslink
    gleich((int) (Einrichtung::finden($token)['id'] ?? 0), $zugaenge['link'], 'finden(): gültiger Link');
    foreach (['44 Zeichen' => $token . 'A', 'mit +' => '+' . substr($token, 1), 'leer' => '', 'fremd' => str_repeat('A', 43)] as $fall => $falsch) {
        gleich(Einrichtung::finden($falsch), null, 'finden(): ' . $fall . ' → null');
    }
    $bisVorher = (string) Database::scalar('SELECT einrichtung_bis FROM users WHERE id = ?', [$zugaenge['link']]);
    Database::update('users', $zugaenge['link'], ['einrichtung_bis' => gmdate('c', time() - 60)]);
    gleich(Einrichtung::finden($token), null, 'finden(): abgelaufen → null');
    Database::update('users', $zugaenge['link'], ['einrichtung_bis' => $bisVorher]);
    Database::update('kunden', $kunden['k5'], ['aktiv' => 0]);
    KundenService::vergessen();
    gleich(Einrichtung::finden($token), null, 'finden(): Kunde beendet → null');
    pruefe(!KundenService::zugangLink($kunden['k5'], $zugaenge['link'])['ok'] && !KundenService::zugangKennwort($kunden['k5'], $zugaenge['link'])['ok'],
        'Kunde beendet: kein neuer Link, kein neues Kennwort');
    Database::update('kunden', $kunden['k5'], ['aktiv' => 1]);
    KundenService::vergessen();
    Database::update('users', $zugaenge['link'], ['is_active' => 0]);
    gleich(Einrichtung::finden($token), null, 'finden(): Zugang deaktiviert → null');
    Database::update('users', $zugaenge['link'], ['is_active' => 1]);

    $neuesKennwort = 'Eingerichtet-' . bin2hex(random_bytes(4)) . '7';
    $r = Einrichtung::einloesen($token, 'kurz1', 'kurz1');
    pruefe(!$r['ok'] && Einrichtung::finden($token) !== null, 'einlösen mit zu kurzem Kennwort: abgelehnt, Link gilt weiter');
    pruefe(!Einrichtung::einloesen($token, $neuesKennwort, $neuesKennwort . 'x')['ok'] && Einrichtung::finden($token) !== null,
        'einlösen mit abweichender Wiederholung: abgelehnt, Link gilt weiter');
    Database::update('users', $zugaenge['link'], ['failed_logins' => 3, 'locked_until' => gmdate('c', time() + 600)]);
    alsBenutzer(null);
    $r = Einrichtung::einloesen($token, $neuesKennwort, $neuesKennwort);
    pruefe($r['ok'], 'einlösen: ' . $r['message']);
    $konto = Database::first('SELECT * FROM users WHERE id = ?', [$zugaenge['link']]) ?? [];
    gleich([$konto['einrichtung_hash'], $konto['einrichtung_bis'], (int) $konto['must_change_password'], (int) $konto['failed_logins'],
        $konto['locked_until']], [null, null, 0, 0, null], 'eingelöst: Link weg, kein vorläufiges Kennwort, Sperre aufgehoben');
    pruefe(!Einrichtung::einloesen($token, $neuesKennwort, $neuesKennwort)['ok'], 'ein zweites Mal einlösen: abgelehnt');
    $audit = Database::first("SELECT * FROM audit_log WHERE action = 'kunden.zugang_eingerichtet' AND target_id = ? ORDER BY id DESC LIMIT 1",
        [(string) $zugaenge['link']]) ?? [];
    gleich([$audit['username'] ?? '', json_decode((string) ($audit['details'] ?? '{}'), true)], ['einrichtung:' . $kennung . '-link', ['user_id' => $zugaenge['link']]],
        'Audit kunden.zugang_eingerichtet: Akteur „einrichtung:<benutzer>“, nur die ID');
    pruefe(password_verify($neuesKennwort, (string) $konto['password_hash']), 'danach gilt das neue Kennwort (Anmeldung: HTTP-Teil)');
    alsBenutzer($personal['planung']);
    gleich((KundenService::zugang($kunden['k5'], $zugaenge['link']) ?? [])['zustand'] ?? '', 'aktiv', 'Zustand: aktiv');

    // Ein neuer Link macht den alten ungültig; einer für ein vorläufiges Kennwort ersetzt das Kennwort.
    $l1 = KundenService::zugangLink($kunden['k5'], $zugaenge['kw']);
    $l2 = KundenService::zugangLink($kunden['k5'], $zugaenge['kw']);
    $t1 = substr((string) ($l1['pfad'] ?? ''), strlen(Einrichtung::PFAD));
    $t2 = substr((string) ($l2['pfad'] ?? ''), strlen(Einrichtung::PFAD));
    pruefe($l1['ok'] && $l2['ok'] && $t1 !== $t2 && Einrichtung::finden($t1) === null && Einrichtung::finden($t2) !== null, 'neuer Link: der vorige gilt nicht mehr');
    pruefe(!password_verify((string) $zk['kennwort'], (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['kw']]))
        && (int) Database::scalar('SELECT must_change_password FROM users WHERE id = ?', [$zugaenge['kw']]) === 0,
        'Link für einen Zugang mit vorläufigem Kennwort: das vorläufige Kennwort gilt nicht mehr');
    pruefe(!KundenService::zugangLink($kunden['k1'], $zugaenge['kw'])['ok'], 'Link für einen Zugang eines anderen Kunden: abgelehnt');
    $kw = KundenService::zugangKennwort($kunden['k5'], $zugaenge['kw']);
    $konto = Database::first('SELECT * FROM users WHERE id = ?', [$zugaenge['kw']]) ?? [];
    pruefe($kw['ok'] && password_verify((string) ($kw['kennwort'] ?? ''), (string) $konto['password_hash']) && (int) $konto['must_change_password'] === 1
        && $konto['einrichtung_hash'] === null && Einrichtung::finden($t2) === null, 'neues vorläufiges Kennwort: gilt, muss geändert werden, Link weg');

    // Abgelaufener Link: die Wartung macht ihn unbrauchbar, der Zustand bleibt sichtbar.
    $zl3 = KundenService::zugangAnlegen($kunden['k5'], ['username' => $kennung . '-alt-link'], 'link');
    $zugaenge['altlink'] = (int) ($zl3['user_id'] ?? 0);
    Database::update('users', $zugaenge['altlink'], ['einrichtung_bis' => gmdate('c', time() - 3600)]);
    gleich((KundenService::zugang($kunden['k5'], $zugaenge['altlink']) ?? [])['zustand'] ?? '', 'link_abgelaufen', 'Zustand: Link abgelaufen');
    pruefe(Einrichtung::aufraeumen() >= 1 && Database::scalar('SELECT einrichtung_hash FROM users WHERE id = ?', [$zugaenge['altlink']]) === null
        && Database::scalar('SELECT einrichtung_bis FROM users WHERE id = ?', [$zugaenge['altlink']]) !== null, 'Wartung: abgelaufener HMAC weg, Ablauf bleibt');
    gleich((KundenService::zugang($kunden['k5'], $zugaenge['altlink']) ?? [])['zustand'] ?? '', 'link_abgelaufen', '… Zustand weiter: Link abgelaufen');
    gleich(Database::scalar('SELECT einrichtung_hash FROM users WHERE id = ?', [$zugaenge['kw']]), null, 'Wartung: nur abgelaufene Links');

    // Ändern, sperren, entsperren, löschen – nur Zugänge dieses Kunden.
    pruefe(KundenService::zugangSpeichern($kunden['k5'], $zugaenge['link'], ['full_name' => 'Max Muster', 'email' => $mail, 'is_active' => 0])['ok']
        && (KundenService::zugang($kunden['k5'], $zugaenge['link']) ?? [])['zustand'] === 'deaktiviert', 'Zugang deaktiviert');
    pruefe(!Auth::attempt($kennung . '-link', $neuesKennwort, '198.51.100.8')['ok'], 'deaktivierter Zugang meldet sich nicht an');
    KundenService::zugangSpeichern($kunden['k5'], $zugaenge['link'], ['full_name' => 'Max Muster', 'email' => $mail, 'is_active' => 1]);
    pruefe(!KundenService::zugangSpeichern($kunden['k5'], $zugaenge['link'], ['email' => 'kaputt'])['ok'], 'Zugang ändern: falsche E-Mail abgelehnt');
    pruefe(!KundenService::zugangSpeichern($kunden['k1'], $zugaenge['link'], ['full_name' => 'Fremd'])['ok']
        && Database::scalar('SELECT full_name FROM users WHERE id = ?', [$zugaenge['link']]) === 'Max Muster', 'Zugang eines anderen Kunden ändern: abgelehnt');
    Database::update('users', $zugaenge['link'], ['failed_logins' => 5, 'locked_until' => gmdate('c', time() + 600)]);
    gleich((KundenService::zugang($kunden['k5'], $zugaenge['link']) ?? [])['zustand'] ?? '', 'gesperrt', 'Zustand: gesperrt');
    pruefe(!KundenService::zugangEntsperren($kunden['k1'], $zugaenge['link'])['ok'], 'Zugang eines anderen Kunden entsperren: abgelehnt');
    pruefe(KundenService::zugangEntsperren($kunden['k5'], $zugaenge['link'])['ok']
        && Database::scalar('SELECT locked_until FROM users WHERE id = ?', [$zugaenge['link']]) === null, 'entsperrt');
    pruefe(!KundenService::zugangLoeschen($kunden['k1'], $zugaenge['altlink'])['ok'] && BenutzerService::find($zugaenge['altlink']) !== null,
        'Zugang eines anderen Kunden löschen: abgelehnt');
    pruefe(KundenService::zugangLoeschen($kunden['k5'], $zugaenge['altlink'])['ok'] && BenutzerService::find($zugaenge['altlink']) === null, 'Zugang gelöscht');

    // ------------------------------------------------ Überschneidung am geteilten Gelände
    $k1Bis = $eingabe($jetzt + 7 * $tag);     // Datenende der Freigabe K1 → A
    $ueber = KundenService::freigabeSpeichern($kunden['k2'], ['site_id' => $sites['a'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1, 'notiz' => 'Herbstmarkt']);
    pruefe(!$ueber['ok'] && in_array($kunden['k1'], array_column($ueber['ueberschneidungen'] ?? [], 'kunde_id'), true),
        'K2 auf A im Zeitraum von K1: abgelehnt, Überschneidung nennt K1');
    pruefe(!in_array($kunden['k4'], array_column($ueber['ueberschneidungen'] ?? [], 'kunde_id'), true), 'Freigabe ohne Datenenden (per SQL) überschneidet nichts');
    $treffer = array_values(array_filter($ueber['ueberschneidungen'] ?? [], static fn (array $u): bool => $u['kunde_id'] === $kunden['k1']))[0] ?? [];
    gleich([$treffer['kunde'] ?? '', $treffer['veranstaltung'] ?? ''], [$kennung . ' Muster Events', 'Sommerfestival'], 'Überschneidung mit Kunde und Veranstaltung');
    $ok = KundenService::freigabeSpeichern($kunden['k2'], ['site_id' => $sites['a'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1, 'ueberschneidung_ok' => 1]);
    pruefe($ok['ok'], 'mit „Überschneidung bewusst“ gespeichert');
    $details = json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'kunden.freigabe_anlegen' AND target_id = ? ORDER BY id DESC LIMIT 1",
        [(string) $kunden['k2']]), true);
    gleich($details['ueberschneidung'] ?? null, true, 'Audit: ueberschneidung true');
    pruefe(KundenService::freigabeLoeschen($kunden['k2'], (int) $ok['id'])['ok'], 'Freigabe entfernt');
    $grenze = KundenService::freigabeSpeichern($kunden['k2'], ['site_id' => $sites['a'], 'gueltig_ab' => $k1Bis,
        'gueltig_bis' => $eingabe($jetzt + 9 * $tag), 'daten_wie_gueltig' => 1]);
    pruefe($grenze['ok'] && !isset($grenze['ueberschneidungen']), 'Grenze berührt (K1 endet, wenn K2 beginnt): keine Überschneidung');
    KundenService::freigabeLoeschen($kunden['k2'], (int) ($grenze['id'] ?? 0));
    $woanders = KundenService::freigabeSpeichern($kunden['k1'], ['site_id' => $sites['c'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1]);
    pruefe($woanders['ok'], 'anderer Standort: keine Überschneidung');
    KundenService::freigabeLoeschen($kunden['k1'], (int) ($woanders['id'] ?? 0));
    gleich(array_column(KundenService::ueberschneidungen($sites['a'], $jetzt - 15 * $tag, $jetzt - 14 * $tag, $kunden['k1']), 'kunde_id'), [$kunden['k2']],
        'auch eine abgelaufene Freigabe zählt (ihre Messdaten gehören dem anderen Kunden)');
    gleich(KundenService::ueberschneidungen($sites['a'], $jetzt - 15 * $tag, $jetzt - 14 * $tag, $kunden['k2']), [], 'die eigenen Freigaben zählen nicht');
    pruefe(!KundenService::freigabeSpeichern($kunden['k5'], ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt)])['ok'], 'über die Kundenverwaltung: ohne Ende abgelehnt');
    gleich(KundenService::freigabeLoeschen($kunden['k1'], (int) $k2b['id'])['ok'], false, 'Freigabe eines anderen Kunden entfernen: abgelehnt');
    pruefe(Database::first('SELECT id FROM freigaben WHERE id = ?', [(int) $k2b['id']]) !== null, '… sie steht noch');
    alsBenutzer($personal['ohnealles']);
    pruefe(!KundenService::freigabeSpeichern($kunden['k5'], ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1])['ok'], 'ohne „Alle Standorte …“: keine Freigabe');
    pruefe(!KundenService::freigabeLoeschen($kunden['k2'], (int) $k2b['id'])['ok'], 'ohne „Alle Standorte …“: keine Freigabe entfernen');
    alsBenutzer($personal['planung']);
    $zustaende = array_column(KundenService::freigaben($kunden['k2']), 'zustand', 'id');
    gleich([$zustaende[(int) $k2a['id']] ?? '', $zustaende[(int) $k2b['id']] ?? '', $zustaende[(int) $k2c['id']] ?? ''], ['abgelaufen', 'wirksam', 'kommend'],
        'Freigaben des Kunden mit Zustand');

    // ------------------------------------------------ Kunde löschen
    $kunden['k6'] = (int) KundenService::speichern(null, ['name' => $kennung . ' Zum Löschen', 'aktiv' => 1])['id'];
    $z6 = KundenService::zugangAnlegen($kunden['k6'], ['username' => $kennung . '-k6', 'email' => $mail], 'kennwort');
    $f6 = KundenService::freigabeSpeichern($kunden['k6'], ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt + 30 * $tag),
        'gueltig_bis' => $eingabe($jetzt + 31 * $tag), 'daten_wie_gueltig' => 1]);
    pruefe($z6['ok'] && $f6['ok'], 'Kunde zum Löschen mit Zugang und Freigabe');
    $dateiAllein = Database::insert('pruefnachweis_dateien', ['sha256' => hash('sha256', $kennung . 'allein'), 'groesse' => 5, 'daten' => base64_encode('%PDF-'), 'created_at' => gmdate('c')]);
    $dateiGeteilt = Database::insert('pruefnachweis_dateien', ['sha256' => hash('sha256', $kennung . 'geteilt'), 'groesse' => 5, 'daten' => base64_encode('%PDF-'), 'created_at' => gmdate('c')]);
    $dateien = [$dateiAllein, $dateiGeteilt];
    $nachweis = static fn (int $kid, int $datei, ?int $site = null): int => Database::insert('pruefnachweise', ['kunde_id' => $kid, 'status' => 'freigegeben',
        'quelle' => 'hand', 'bezeichnung' => $kennung . ' Verteiler', 'datei_id' => $datei, 'site_id' => $site, 'created_at' => gmdate('c')]);
    $nachweis($kunden['k6'], $dateiAllein);
    $nachweis($kunden['k6'], $dateiGeteilt);
    $nachweisK2 = $nachweis($kunden['k2'], $dateiGeteilt);
    Database::insert('kunden_vorgaenge', ['kunde_id' => $kunden['k6'], 'ewx_host' => $kennung . '.example', 'ewx_id' => bin2hex(random_bytes(12)), 'verknuepft_am' => gmdate('c')]);
    Database::insert('kunden_abrufe', ['kunde_id' => $kunden['k6'], 'user_id' => (int) $z6['user_id'], 'art' => 'nachweis', 'ziel_id' => 1, 'am' => gmdate('c')]);
    $bericht = Database::insert('berichte', ['status' => 'abgeschlossen', 'pruefdatum' => gmdate('Y-m-d'), 'inhalt' => '{}', 'titel' => $kennung,
        'erstellt_von' => $personal['admin'], 'created_at' => gmdate('c'), 'kunde_id' => $kunden['k6']]);
    pruefe(!KundenService::loeschen($kunden['k6'], $kennung . ' Zum Löschen')['ok'], 'Löschen: aktiver Kunde abgelehnt');
    KundenService::speichern($kunden['k6'], ['name' => $kennung . ' Zum Löschen', 'aktiv' => 0]);
    pruefe(!KundenService::loeschen($kunden['k6'], $kennung . ' Zum Loeschen')['ok'] && KundenService::find($kunden['k6']) !== null,
        'Löschen: falscher Name abgelehnt');
    $r = KundenService::loeschen($kunden['k6'], ' ' . strtoupper($kennung) . '  zum löschen ');
    pruefe($r['ok'], 'Löschen mit dem Namen (Schreibweise egal): ' . $r['message']);
    gleich([
        KundenService::find($kunden['k6']),
        (int) Database::scalar('SELECT COUNT(*) FROM users WHERE kunde_id = ?', [$kunden['k6']]),
        (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$kunden['k6']]),
        (int) Database::scalar('SELECT COUNT(*) FROM kunden_vorgaenge WHERE kunde_id = ?', [$kunden['k6']]),
        (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ?', [$kunden['k6']]),
        (int) Database::scalar('SELECT COUNT(*) FROM kunden_abrufe WHERE kunde_id = ?', [$kunden['k6']]),
        (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweis_dateien WHERE id = ?', [$dateiAllein]),
    ], [null, 0, 0, 0, 0, 0, 0], 'gelöscht: Kunde, Zugänge, Freigaben, Vorgänge, Nachweise, Abrufe, unbenutzte Datei');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM pruefnachweis_dateien WHERE id = ?', [$dateiGeteilt]), 1, 'eine Datei, die ein anderer Nachweis nutzt, bleibt');
    $b = Database::first('SELECT id, kunde_id FROM berichte WHERE id = ?', [$bericht]);
    pruefe($b !== null && $b['kunde_id'] === null, 'der Übergabebericht bleibt, nur ohne Kunde');
    $details = json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'kunden.loeschen' AND target_id = ?", [(string) $kunden['k6']]), true);
    gleich([$details['zugaenge'] ?? null, $details['nachweise'] ?? null, $details['berichte'] ?? null], [1, 2, 1], 'Audit kunden.loeschen mit Zahlen');

    // ------------------------------------------------ Standort löschen
    $sites['d'] = SiteService::saveSite(null, ['name' => $kennung . ' Gelände D']);
    $fd = KundenService::freigabeSpeichern($kunden['k2'], ['site_id' => $sites['d'], 'gueltig_ab' => $eingabe($jetzt - $tag),
        'gueltig_bis' => $eingabe($jetzt + $tag), 'daten_wie_gueltig' => 1]);
    $nachweisD = $nachweis($kunden['k2'], $dateiGeteilt, $sites['d']);
    $vorgangD = Database::insert('kunden_vorgaenge', ['kunde_id' => $kunden['k2'], 'ewx_host' => $kennung . '.example', 'ewx_id' => bin2hex(random_bytes(12)),
        'site_id' => $sites['d'], 'verknuepft_am' => gmdate('c')]);
    pruefe($fd['ok'], 'Freigabe auf Gelände D');
    SiteService::deleteSite($sites['d']);
    gleich([
        (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'kunde' AND ziel_art = 'site' AND ziel_id = ?", [$sites['d']]),
        Database::scalar('SELECT site_id FROM pruefnachweise WHERE id = ?', [$nachweisD]),
        Database::scalar('SELECT site_id FROM kunden_vorgaenge WHERE id = ?', [$vorgangD]),
        (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE id = ?', [$nachweisD]),
    ], [0, null, null, 1], 'Standort gelöscht: Kunden-Freigaben weg, Nachweis und Vorgang bleiben ohne Standort');
    Database::execute("DELETE FROM audit_log WHERE target_type = 'site' AND target_id = ?", [(string) $sites['d']]);
    unset($sites['d']);
    Database::execute('DELETE FROM pruefnachweise WHERE id IN (?, ?)', [$nachweisD, $nachweisK2]);

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
    $seiten = ['/kundenbereich' => $start];
    foreach (['/kundenbereich/locations', '/kundenbereich/locations/' . $sites['a'], '/kundenbereich/konto', '/kundenbereich/hilfe'] as $pfad) {
        $seiten[$pfad] = anfrage($basis, $z1['dose'], 'GET', $pfad);
    }
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

    // Übersicht, Locations, Location, Hilfe, Konto: was drinsteht – und was nie.
    $plan = static fn (int $site, int $etage): string => '/kundenbereich/locations/' . $site . '/plan/' . $etage;
    pruefe(str_contains($start['text'], $kennung . ' Gelände A (geteilt)') && str_contains($start['text'], 'href="/kundenbereich/locations/' . $sites['a'] . '"')
        && str_contains($start['text'], 'Sommerfestival') && !str_contains($start['text'], $kennung . ' Gelände B'), 'Übersicht: Location A mit Veranstaltung, nicht B');
    pruefe(str_contains($seiten['/kundenbereich/locations']['text'], $kennung . ' Gelände A (geteilt)')
        && str_contains($seiten['/kundenbereich/locations']['text'], 'aria-current="page"') && !str_contains($seiten['/kundenbereich/locations']['text'], $kennung . ' Gelände B'),
        'Locations: A, nicht B, Navigation markiert');
    $ort = $seiten['/kundenbereich/locations/' . $sites['a']]['text'];
    pruefe(str_contains($ort, $kennung . ' Energiesäule FA') && str_contains($ort, 'data-label="Anschlüsse"') && str_contains($ort, 'href="' . $plan($sites['a'], $a1) . '"')
        && str_contains($ort, 'href="' . $plan($sites['a'], $a2) . '" download') && !str_contains($ort, '/plan/' . $a3 . '"') && !str_contains($ort, '/plan/' . $b1 . '"'),
        'Location A: fester Anschluss als Karte (data-label), Bild-Plan ansehen, PDF-Plan herunterladen, keine Etage ohne Plan');
    $durch = array_filter(array_merge($marken, [$kennung . '-anlagennotiz', $kennung . '-netz', 'Mobilverteiler', 'Vorlage VA', 'Hallenplan', '/grundriss/', '/karte']),
        static fn (string $m): bool => str_contains($ort, $m));
    gleich(array_values($durch), [], 'Location-Seite ohne Notizen, Fremdbestand, mobile Anlagen, Vorlagen, Dateinamen, Karte');
    $hilfe = $seiten['/kundenbereich/hilfe']['text'];
    pruefe(str_contains($hilfe, 'DIN EN 50699') && str_contains($hilfe, 'BetrSichV § 14 Abs. 7') && str_contains($hilfe, 'id="datenschutz"')
        && str_contains($hilfe, 'Keine Rechtsberatung') && str_contains($hilfe, 'Art. 6 Abs. 1 lit. b und c DSGVO'), 'Hilfe: Prüfnachweis ≠ Übergabebericht, Datenschutzhinweis, keine Rechtsberatung');
    pruefe(str_contains($seiten['/kundenbereich/konto']['text'], 'Datenschutzhinweis'), 'Konto mit Datenschutzhinweis');

    // Pläne: Bild im Browser, PDF als Datei – nie zwischengespeichert, sandbox, neutraler Name.
    $bild = anfrage($basis, $z1['dose'], 'GET', $plan($sites['a'], $a1));
    gleich([$bild['status'], kopfzeile($bild, 'Content-Type'), kopfzeile($bild, 'Content-Disposition'), kopfzeile($bild, 'Cache-Control'),
        kopfzeile($bild, 'X-Content-Type-Options'), kopfzeile($bild, 'Content-Security-Policy'), kopfzeile($bild, 'Referrer-Policy'), $bild['text'] === $planBytes],
        [200, 'image/png', 'inline; filename="plan-Halle-Sued.png"', 'private, no-store', 'nosniff', "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'no-referrer', true], 'Bild-Plan: inline, no-store, sandbox, Name ohne Umlaut und ohne Originalnamen');
    $pdf = anfrage($basis, $z1['dose'], 'GET', $plan($sites['a'], $a2));
    gleich([$pdf['status'], kopfzeile($pdf, 'Content-Type'), kopfzeile($pdf, 'Content-Disposition'), kopfzeile($pdf, 'Cache-Control'), $pdf['text'] === $pdfBytes],
        [200, 'application/pdf', 'attachment; filename="plan-Aussengelaende.pdf"', 'private, no-store', true], 'PDF-Plan: als Anhang (sandbox sperrt die PDF-Ansicht)');

    // Fremd und nicht vorhanden: dieselbe 404, im Kundenlayout, ohne Navigationsmarke.
    $ohneMarken = static fn (string $html): string => (string) preg_replace('/nonce="[^"]*"|value="[^"]*"/', '', $html);
    $vorlage = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich/gibt-es-nicht');
    $abweichend = [];
    foreach (['/kundenbereich/locations/' . $sites['b'], '/kundenbereich/locations/' . $sites['c'], '/kundenbereich/locations/999999999',
        '/kundenbereich/locations/0', '/kundenbereich/locations/' . $sites['a'] . 'x', '/kundenbereich/locations/-' . $sites['a'],
        '/kundenbereich/locations/0' . $sites['a'], $plan($sites['a'], $b1), $plan($sites['b'], $b1), $plan($sites['a'], $a3), $plan($sites['a'], 999999999),
        $plan($sites['c'], $b1), '/kundenbereich/locations/' . $sites['a'] . '/plan/' . $a1 . 'x', '/kundenbereich/locations/' . $sites['a'] . '/plan'] as $pfad) {
        $r = anfrage($basis, $z1['dose'], 'GET', $pfad);
        if ($r['status'] !== 404 || $ohneMarken($r['text']) !== $ohneMarken($vorlage['text']) || kopfzeile($r, 'Cache-Control') !== 'private, no-store') {
            $abweichend[] = $pfad . ' → ' . $r['status'];
        }
    }
    gleich($abweichend, [], 'IDOR: fremde/abgelaufene/kommende Location, fremde Etage, Etage ohne Plan, kaputte IDs – dieselbe 404');
    pruefe(!str_contains($vorlage['text'], 'aria-current'), '404 markiert keinen Eintrag der Navigation');
    $z2 = anmeldenAls($basis, $kennung . '-z2', $kennwort, $dosen);
    gleich([anfrage($basis, $z2['dose'], 'GET', '/kundenbereich/locations/' . $sites['b'])['status'], anfrage($basis, $z2['dose'], 'GET', '/kundenbereich/locations/' . $sites['a'])['status'],
        anfrage($basis, $z2['dose'], 'GET', $plan($sites['b'], $b1))['status'], anfrage($basis, $z2['dose'], 'GET', $plan($sites['a'], $a1))['status']], [200, 404, 200, 404],
        'K2 (anderer Veranstalter am geteilten Gelände A): B ja, A nicht mehr');
    pruefe(str_contains(anfrage($basis, $z2['dose'], 'GET', '/kundenbereich/locations')['text'], $kennung . ' Gelände C'), 'K2: C unter „Demnächst“');
    gleich(meldungenDesServers($serverFehler), [], 'Seiten und Pläne des Kundenbereichs ohne PHP-Meldung');

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
    foreach (['/kundenbereich/locations', '/kundenbereich/locations/' . $sites['a'], $plan($sites['a'], $a1), '/kundenbereich/hilfe'] as $pfad) {
        $r = anfrage($basis, $z1b['dose'], 'GET', $pfad);
        gleich([$r['status'], $r['ziel']], [302, '/kundenbereich/konto'], 'vorläufiges Kennwort: ' . $pfad . ' → Konto');
    }
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

    // ------------------------------------------------ Kundenverwaltung über HTTP
    $planung = anmeldenAls($basis, $kennung . '-planung', $kennwort, $dosen);
    $liste = anfrage($basis, $planung['dose'], 'GET', '/kunden');
    pruefe($liste['status'] === 200 && str_contains($liste['text'], $kennung . ' Muster Events') && str_contains($liste['text'], 'href="/kunden"'),
        'Stromplanung: Kundenliste, Eintrag in der Navigation');
    pruefe(str_contains(anfrage($basis, $planung['dose'], 'GET', '/kunden?suche=' . rawurlencode('STADTMARKETING'))['text'], $kennung . ' Stadtmarketing')
        && !str_contains(anfrage($basis, $planung['dose'], 'GET', '/kunden?suche=' . rawurlencode('STADTMARKETING'))['text'], $kennung . ' Muster Events'), 'Kundenliste: Suche');
    foreach (array_keys(KundenController::REITER) as $tab) {
        foreach ([$kunden['k1'], $kunden['k3'], $kunden['k5']] as $kid) {
            $r = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kid . '?tab=' . $tab);
            pruefe($r['status'] === 200 && !str_contains($r['text'], 'onclick=') && !str_contains($r['text'], 'javascript:'),
                'Kunde ' . $kid . ' Reiter ' . $tab . ': 200, ohne Inline-JavaScript (' . $r['status'] . ')');
        }
    }
    $uebersicht = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k1']);
    pruefe(str_contains($uebersicht['text'], 'Der Kunde sieht derzeit') && str_contains($uebersicht['text'], $kennung . ' Gelände A (geteilt)')
        && !str_contains($uebersicht['text'], $kennung . ' Gelände B'), 'Übersicht: K1 sieht derzeit A, nicht B (Konto-/Rollen-Freigaben zählen nicht)');
    pruefe(str_contains(anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k3'])['text'], 'Nichts – der Kunde ist beendet'),
        'Übersicht: ein beendeter Kunde sieht nichts');
    foreach (['/kunden/999999999', '/kunden/abc', '/kunden/' . $kunden['k1'] . '?tab=gibt-es-nicht'] as $pfad) {
        $r = anfrage($basis, $planung['dose'], 'GET', $pfad);
        pruefe($pfad === '/kunden/' . $kunden['k1'] . '?tab=gibt-es-nicht' ? $r['status'] === 200 : $r['status'] === 404, 'Kundenseite ' . $pfad . ' → ' . $r['status']);
    }
    gleich(meldungenDesServers($serverFehler), [], 'Kundenverwaltung ohne PHP-Meldung');

    // Ein neuer Zugang: das Kennwort genau einmal, in der Antwort, nicht zwischengespeichert.
    $tabZ = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k1'] . '?tab=zugaenge');
    preg_match('/name="einmal" value="([0-9a-f]{32})"/', $tabZ['text'], $m);
    $einmal = $m[1] ?? '';
    $pToken = csrfAus($tabZ['text']);
    pruefe($einmal !== '' && $pToken !== '', 'Formular „Neuer Zugang“ mit Einmal-Marke');
    $konten = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM users');
    $vorher = $konten();
    $neuerZugang = ['_token' => $pToken, 'einmal' => $einmal, 'username' => $kennung . '-http', 'full_name' => 'Per HTTP', 'email' => '', 'weg' => 'kennwort'];
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k1'] . '/zugang', $neuerZugang);
    preg_match('/id="e-geheim" value="([^"]+)"/', $r['text'], $m);
    $geheim = html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
    $zugaenge['http'] = (int) Database::scalar('SELECT id FROM users WHERE username = ?', [$kennung . '-http']);
    pruefe($r['status'] === 200 && $geheim !== '' && password_verify($geheim, (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['http']])),
        'Zugang angelegt: vorläufiges Kennwort direkt in der Antwort');
    gleich(kopfzeile($r, 'Cache-Control'), 'no-store', 'Einmal-Seite: Cache-Control no-store');
    gleich(anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k1'] . '/zugang', $neuerZugang)['status'], 302, 'dieselbe Einmal-Marke ein zweites Mal: zurück');
    gleich($konten(), $vorher + 1, '… kein zweites Konto');
    $tabZ = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k1'] . '?tab=zugaenge');
    pruefe(!str_contains($tabZ['text'], $geheim) && !str_contains($tabZ['text'], htmlspecialchars($geheim, ENT_QUOTES)) && str_contains($tabZ['text'], $kennung . '-http'),
        'Reiter Zugänge: der neue Zugang, ohne sein Kennwort');
    preg_match('/name="einmal" value="([0-9a-f]{32})"/', $tabZ['text'], $m);
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k1'] . '/zugang', ['_token' => $pToken, 'einmal' => $m[1] ?? '',
        'username' => $kennung . '-httplink', 'weg' => 'link']);
    preg_match('#id="e-geheim" value="(/kundenbereich/einrichten/[A-Za-z0-9_-]{43})"#', $r['text'], $m);
    $pfad = $m[1] ?? '';
    pruefe($r['status'] === 200 && $pfad !== '' && str_contains($r['text'], 'fehlt eine gültige'), 'Link-Weg ohne app.url: nur der Pfad, mit Hinweis');
    $zugaenge['httplink'] = (int) Database::scalar('SELECT id FROM users WHERE username = ?', [$kennung . '-httplink']);
    gleich(meldungenDesServers($serverFehler), [], 'Zugänge anlegen ohne PHP-Meldung');

    // Den Link einlösen – ohne Anmeldung, POST nur mit CSRF-Token, danach zur Anmeldung.
    $gast = (string) tempnam(sys_get_temp_dir(), 'pm-kb-');
    $dosen[] = $gast;
    $formular = anfrage($basis, $gast, 'GET', $pfad);
    pruefe($formular['status'] === 200 && str_contains($formular['text'], 'value="' . $kennung . '-httplink"') && str_contains($formular['text'], 'name="kennwort"')
        && str_contains($formular['text'], 'Datenschutzhinweis'), 'Einrichtungslink: Formular mit Benutzername und Datenschutzhinweis');
    gleich([kopfzeile($formular, 'Cache-Control'), kopfzeile($formular, 'Referrer-Policy')], ['private, no-store', 'no-referrer'], 'Einrichtungslink: no-store, no-referrer');
    pruefe(!str_contains($formular['text'], 'onclick=') && preg_match('/<script\b/i', $formular['text']) !== 1, 'Einrichtungslink: ohne Skript');
    $linkHash = static fn (): ?string => Database::scalar('SELECT einrichtung_hash FROM users WHERE id = ?', [$zugaenge['httplink']]);
    $hashVorher = $linkHash();
    $neu = 'Kunde-' . bin2hex(random_bytes(5)) . '1';
    anfrage($basis, $gast, 'POST', $pfad, ['kennwort' => $neu, 'wiederholung' => $neu]);
    pruefe($linkHash() === $hashVorher && $hashVorher !== null, 'Einrichtungslink ohne CSRF-Token: nichts geändert');
    $r = anfrage($basis, $gast, 'POST', $pfad, ['_token' => csrfAus($formular['text']), 'kennwort' => $neu, 'wiederholung' => $neu]);
    gleich([$r['status'], $r['ziel'], $linkHash()], [302, '/login', null], 'Kennwort festgelegt → Anmeldung, Link verbraucht');
    pruefe(str_contains(anmeldeMeldung($basis, $gast), 'Kennwort festgelegt'), 'Meldung „Kennwort festgelegt – bitte jetzt anmelden.“');
    $verbraucht = anfrage($basis, $gast, 'GET', $pfad);
    gleich($verbraucht['status'], 404, 'derselbe Link noch einmal: 404');
    $fremd = anfrage($basis, $gast, 'GET', Einrichtung::PFAD . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    $ohneMarken = static fn (string $html): string => (string) preg_replace('/nonce="[^"]*"|value="[^"]*"/', '', $html);
    $abgelaufen = KundenService::zugangLink($kunden['k1'], $zugaenge['http']);
    Database::update('users', $zugaenge['http'], ['einrichtung_bis' => gmdate('c', time() - 60)]);
    $abgelaufenSeite = anfrage($basis, $gast, 'GET', (string) $abgelaufen['pfad']);
    gleich([$fremd['status'], $abgelaufenSeite['status'], $ohneMarken($fremd['text']), $ohneMarken($verbraucht['text'])],
        [404, 404, $ohneMarken($abgelaufenSeite['text']), $ohneMarken($abgelaufenSeite['text'])], 'falscher, abgelaufener und verbrauchter Link: dieselbe 404');
    pruefe(!str_contains($abgelaufenSeite['text'], 'name="kennwort"') && str_contains($abgelaufenSeite['text'], htmlspecialchars(Einrichtung::UNGUELTIG)),
        '404 ohne Formular, ein Text für alle Fälle');
    $z = anmeldenAls($basis, $kennung . '-httplink', $neu, $dosen);
    gleich($z['ziel'], '/kundenbereich', 'mit dem festgelegten Kennwort angemeldet → Kundenbereich');
    $neuerLink = KundenService::zugangLink($kunden['k1'], $zugaenge['http']);
    $r = anfrage($basis, $planung['dose'], 'GET', (string) $neuerLink['pfad']);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'bitte zuerst abmelden') && !str_contains($r['text'], 'name="kennwort"'), 'als Personal angemeldet: Hinweis, kein Formular');
    $r = anfrage($basis, $z['dose'], 'POST', (string) $neuerLink['pfad'], ['_token' => csrfAus(anfrage($basis, $z['dose'], 'GET', '/kundenbereich')['text']),
        'kennwort' => $neu . 'x', 'wiederholung' => $neu . 'x']);
    pruefe($r['status'] === 200 && Einrichtung::finden(substr((string) $neuerLink['pfad'], strlen(Einrichtung::PFAD))) !== null,
        'als Kundenzugang angemeldet: der Link setzt nichts');
    gleich(meldungenDesServers($serverFehler), [], 'Einrichtungslink ohne PHP-Meldung');

    // Freigabe mit Überschneidung: erst die Rückfrage, dann bewusst.
    $tabF = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k2'] . '?tab=freigaben');
    $freigaben = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'kunde'");
    $vorher = $freigaben();
    $felder = ['_token' => csrfAus($tabF['text']), 'site_id' => $sites['a'], 'gueltig_ab' => $eingabe($jetzt - $tag), 'gueltig_bis' => $eingabe($jetzt + $tag),
        'daten_wie_gueltig' => 1, 'notiz' => 'Herbstmarkt'];
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k2'] . '/freigabe', $felder);
    $tabF = anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k2'] . '?tab=freigaben');
    pruefe($r['status'] === 302 && $freigaben() === $vorher && str_contains($tabF['text'], 'Überschneidung') && str_contains($tabF['text'], $kennung . ' Muster Events')
        && str_contains($tabF['text'], 'name="ueberschneidung_ok"') && str_contains($tabF['text'], 'value="Herbstmarkt"'), 'Überschneidung: nichts gespeichert, Liste und Häkchen, Eingaben bleiben');
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k2'] . '/freigabe', $felder + ['ueberschneidung_ok' => 1]);
    $fid = (int) Database::scalar("SELECT id FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ? AND notiz = 'Herbstmarkt'", [$kunden['k2']]);
    pruefe($r['status'] === 302 && $freigaben() === $vorher + 1, 'mit Häkchen gespeichert');
    gleich(anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k1'] . '/freigabe/' . $fid . '/loeschen', ['_token' => $felder['_token']])['status'], 404,
        'Freigabe über einen anderen Kunden entfernen: 404');
    anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k2'] . '/freigabe/' . $fid . '/loeschen', ['_token' => $felder['_token']]);
    gleich($freigaben(), $vorher, 'Freigabe entfernt');
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k1'] . '/zugang/' . $zugaenge['z2'], ['_token' => $felder['_token'], 'aktion' => 'loeschen']);
    pruefe($r['status'] === 404 && BenutzerService::find($zugaenge['z2']) !== null, 'Zugang über einen anderen Kunden löschen: 404');
    $r = anfrage($basis, $planung['dose'], 'POST', '/kunden/' . $kunden['k1'] . '/zugang/' . $zugaenge['http'], ['_token' => $felder['_token'], 'aktion' => 'kennwort']);
    preg_match('/id="e-geheim" value="([^"]+)"/', $r['text'], $m);
    pruefe($r['status'] === 200 && kopfzeile($r, 'Cache-Control') === 'no-store'
        && password_verify(html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8'), (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$zugaenge['http']])),
        'neues vorläufiges Kennwort: direkt in der Antwort, no-store');
    gleich(meldungenDesServers($serverFehler), [], 'Freigaben und Zugangsaktionen ohne PHP-Meldung');

    // Ohne CSRF-Token ändert keine der neuen Routen etwas.
    $stand = static fn (): array => [(int) Database::scalar('SELECT COUNT(*) FROM kunden'), $konten(), (int) Database::scalar('SELECT COUNT(*) FROM freigaben'),
        Settings::get('kundenbereich.kontakt'), (string) Database::scalar('SELECT name FROM kunden WHERE id = ?', [$kunden['k5']])];
    $vorher = $stand();
    preg_match('/name="einmal" value="([0-9a-f]{32})"/', anfrage($basis, $planung['dose'], 'GET', '/kunden/' . $kunden['k5'] . '?tab=zugaenge')['text'], $m);
    $adminDose = anmeldenAls($basis, $kennung . '-admin', $kennwort, $dosen)['dose'];
    foreach ([
        [$planung['dose'], '/kunden/speichern', ['name' => $kennung . ' Ohne Token']],
        [$planung['dose'], '/kunden/speichern', ['id' => $kunden['k5'], 'name' => $kennung . ' Umbenannt', 'aktiv' => 1]],
        [$planung['dose'], '/kunden/' . $kunden['k3'] . '/loeschen', ['bestaetigung' => $kennung . ' Beendet']],
        [$planung['dose'], '/kunden/' . $kunden['k5'] . '/zugang', ['einmal' => $m[1] ?? '', 'username' => $kennung . '-ohnetoken', 'weg' => 'kennwort']],
        [$planung['dose'], '/kunden/' . $kunden['k5'] . '/zugang/' . $zugaenge['kw'], ['aktion' => 'loeschen']],
        [$planung['dose'], '/kunden/' . $kunden['k5'] . '/freigabe', ['site_id' => $sites['b'], 'gueltig_ab' => $eingabe($jetzt + 40 * $tag),
            'gueltig_bis' => $eingabe($jetzt + 41 * $tag), 'daten_wie_gueltig' => 1]],
        [$planung['dose'], '/kunden/' . $kunden['k2'] . '/freigabe/' . (int) $k2b['id'] . '/loeschen', []],
        [$adminDose, '/system/einstellungen/kundenbereich', ['kundenbereich__kontakt' => $kennung . ' Ohne Token']],
    ] as [$dose, $ziel, $felder]) {
        gleich(anfrage($basis, $dose, 'POST', $ziel, $felder)['status'], 302, 'ohne CSRF-Token: ' . $ziel . ' → zurück');
    }
    Settings::vergessen();
    gleich($stand(), $vorher, 'ohne CSRF-Token: nichts geändert (Kunden, Konten, Freigaben, Einstellung)');

    // Rechte: ansehen ohne pflegen, ohne Kunden-Rechte gar nicht.
    $technik = anmeldenAls($basis, $kennung . '-technik', $kennwort, $dosen);
    gleich(anfrage($basis, $technik['dose'], 'GET', '/kunden')['status'], 200, 'Elektrotechnik: Kundenliste');
    $r = anfrage($basis, $technik['dose'], 'GET', '/kunden/' . $kunden['k1']);
    pruefe($r['status'] === 200 && !str_contains($r['text'], '?tab=zugaenge') && !str_contains($r['text'], 'action="/kunden/speichern"'), 'Elektrotechnik: Kunde ohne Reiter Zugänge, ohne Formular');
    gleich([anfrage($basis, $technik['dose'], 'GET', '/kunden/' . $kunden['k1'] . '?tab=zugaenge')['status'],
        anfrage($basis, $technik['dose'], 'GET', '/kunden/' . $kunden['k1'] . '?tab=freigaben')['status']], [403, 403], 'Elektrotechnik: Zugänge und Freigaben 403');
    gleich(anfrage($basis, $technik['dose'], 'POST', '/kunden/speichern', ['_token' => csrfAus($r['text']), 'name' => $kennung . ' Technik'])['status'], 403,
        'Elektrotechnik: Kunde anlegen 403');
    gleich(anfrage($basis, $alt['dose'], 'GET', '/kunden')['status'], 403, 'Konto mit Rolle „kunde“: /kunden 403');
    $r = anfrage($basis, anmeldenAls($basis, $kennung . '-ohnealles', $kennwort, $dosen)['dose'], 'GET', '/kunden/' . $kunden['k2'] . '?tab=freigaben');
    pruefe($r['status'] === 200 && str_contains($r['text'], 'darf nur, wer selbst alle Standorte sieht') && !str_contains($r['text'], 'id="neue-freigabe"'),
        'pflegen ohne „Alle Standorte …“: Freigaben nur ansehen');
    gleich(meldungenDesServers($serverFehler), [], 'Rechte der Kundenverwaltung ohne PHP-Meldung');

    // Kunde beendet: die offenen Sitzungen enden bei der nächsten Anfrage – auch mitten im Plan.
    $zweiteSitzung = anmeldenAls($basis, $kennung . '-z1b', 'Neu-' . $kennwort, $dosen);
    gleich(anfrage($basis, $zweiteSitzung['dose'], 'GET', '/kundenbereich/locations/' . $sites['a'])['status'], 200, 'zweiter Zugang von K1: Location A');
    Database::update('kunden', $kunden['k1'], ['aktiv' => 0]);
    $r = anfrage($basis, $z1['dose'], 'GET', '/kundenbereich');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'Kunde beendet: offene Sitzung endet');
    $r = anfrage($basis, $z1['dose'], 'GET', '/system/benutzer');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'Kunde beendet: auch keine Personalseite');
    $r = anfrage($basis, $zweiteSitzung['dose'], 'GET', $plan($sites['a'], $a1));
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login') && $r['text'] !== $planBytes, 'Kunde beendet: der zweite Zugang bekommt keinen Plan mehr');
    $r = anfrage($basis, $zweiteSitzung['dose'], 'GET', '/kundenbereich/locations/' . $sites['a']);
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'Kunde beendet: … und keine Location');
    $versuch = anmeldenAls($basis, $kennung . '-z1', $kennwort, $dosen);
    pruefe($versuch['ziel'] === '/login' && str_contains(anmeldeMeldung($basis, $versuch['dose']), 'nicht mehr aktiv'),
        'Kunde beendet: Anmeldung mit richtigem Kennwort „nicht mehr aktiv“');
    $versuch = anmeldenAls($basis, $kennung . '-z1', 'falsch-' . $kennwort, $dosen);
    gleich(anmeldeMeldung($basis, $versuch['dose']), 'Benutzername oder Passwort falsch.', 'Kunde beendet: falsches Kennwort verrät nichts');
    gleich(meldungenDesServers($serverFehler), [], 'Kunde beendet ohne PHP-Meldung');

    // Datenschutz: Kundennamen nie im Audit.
    $namen = array_filter(array_map(static fn (int $id): string => (string) Database::scalar('SELECT name FROM kunden WHERE id = ?', [$id]), $kunden));
    $namen[] = $kennung . ' Zum Löschen';   // schon gelöscht
    $treffer = 0;
    foreach (Database::select('SELECT details FROM audit_log WHERE id > ?', [$auditVorher]) as $a) {
        foreach ($namen as $n) {
            $treffer += str_contains((string) $a['details'], $n) ? 1 : 0;
        }
    }
    gleich($treffer, 0, 'kein Kundenname in audit_log.details');
    $mailMuster = '%' . $kennung . '-kontakt%';
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE id > ? AND details LIKE ?', [$auditVorher, $mailMuster]),
        (int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE details LIKE ?', [$mailMuster]),
        str_contains((string) @file_get_contents(dirname(__DIR__) . '/storage/logs/app.log'), $kennung . '-kontakt')], [0, 0, false],
        'keine E-Mail-Adresse eines Zugangs in Audit, Bericht-Ereignissen und Log');
    pruefe((int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE id > ? AND (details LIKE ? OR details LIKE ?)', [$auditVorher, '%einrichten/%', '%password%']) === 0,
        'kein Link und kein Kennwort im Audit');
} finally {
    alsBenutzer(null);
    Config::set('app.url', $appUrlVorher);
    // Auch die Kunden, die die Prüfung selbst gelöscht hat (ihr Audit bleibt sonst stehen).
    $kundenIds = array_values(array_unique(array_merge(array_map('intval', $kunden),
        array_map('intval', array_column(Database::select('SELECT id FROM kunden WHERE name LIKE ?', [$kennung . '%']), 'id')))));
    foreach ($kundenIds as $id) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$id]);
        foreach (Database::select('SELECT id FROM users WHERE kunde_id = ?', [$id]) as $u) {
            BenutzerService::kontoEntfernen((int) $u['id']);
            Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $u['id']]);
        }
        foreach (['pruefnachweise', 'kunden_vorgaenge', 'kunden_abrufe'] as $tabelle) {
            Database::execute('DELETE FROM ' . $tabelle . ' WHERE kunde_id = ?', [$id]);
        }
        Database::execute('UPDATE berichte SET kunde_id = NULL WHERE kunde_id = ?', [$id]);
        Database::delete('kunden', $id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'kunde' AND target_id = ?", [(string) $id]);
    }
    foreach ($zugaenge as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach ($dateien as $id) {
        Database::execute('DELETE FROM pruefnachweise WHERE datei_id = ?', [$id]);
        Database::delete('pruefnachweis_dateien', $id);
    }
    Database::execute('DELETE FROM berichte WHERE titel = ?', [$kennung]);
    if (isset($rolleKunde)) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'role' AND subject_id = ? AND notiz = ?", [$rolleKunde, $kennung]);
    }
    Database::execute('DELETE FROM anschluesse WHERE objekt_id IN (SELECT id FROM objekte WHERE name LIKE ?)', [$kennung . '%']);
    Database::execute('DELETE FROM objekte WHERE name LIKE ?', [$kennung . '%']);
    foreach ($sites as $id) {
        SiteService::deleteSite($id);   // samt Etagen und Plandateien
        Database::execute("DELETE FROM audit_log WHERE target_type = 'site' AND target_id = ?", [(string) $id]);
    }
    foreach ($etagen ?? [] as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'floor' AND target_id = ?", [(string) $id]);
    }
    foreach ($personal as $id) {
        BenutzerService::kontoEntfernen($id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach ($rollen as $id) {
        BenutzerService::rolleLoeschen($id);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'role' AND target_id = ?", [(string) $id]);
    }
    Database::execute('DELETE FROM audit_log WHERE id > ? AND (username LIKE ? OR username LIKE ? OR details LIKE ?)',
        [$auditVorher, $kennung . '%', 'einrichtung:' . $kennung . '%', '%' . $kennung . '%']);
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
