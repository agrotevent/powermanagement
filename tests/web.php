<?php
declare(strict_types=1);

/**
 * Die Oberfläche von außen: ein eigener PHP-Server, echte Anmeldung mit
 * Sitzung und CSRF-Token, jede Seite einmal aufgerufen.
 *
 * Geprüft wird, dass alle Seiten ohne PHP-Warnung ausliefern, dass die
 * Rechte greifen (403 statt Seite, fremde Anlagen unsichtbar) und dass
 * Schalten ohne Recht bzw. ohne Schalt-Freigabe für das Gerät abgelehnt
 * wird. Die Prüfung legt eigene Benutzer an und löscht sie wieder.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Berichte\BerichtService;
use App\Berichte\Einstellungen;
use App\Berichte\Kundenmodus;
use App\Services\BenutzerService;
use App\Services\BereichService;
use App\Services\Eventworx;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;

$port = freierPort();
// Mehrere Arbeiter: eine Seite, die die eigene Schnittstelle aufruft (eine
// andere Instanz über die Loopback-Adresse), wartete sonst auf sich selbst.
// Ohne app.debug zeigt der Server PHP-Meldungen nicht in der Seite, er schreibt
// sie auf die Standardfehlerausgabe – die landet hier und wird mitgeprüft.
$serverFehler = (string) tempnam(sys_get_temp_dir(), 'pm-web-fehler-');
hintergrund(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $port)) {
    uebersprungen('web', 'eingebauter PHP-Server startet nicht');
}
$basis = 'http://127.0.0.1:' . $port;

/** Ein Aufruf mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function aufruf(string $basis, string $dose, string $methode, string $pfad, array $felder = [], array $kopf = []): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $kopf,
    ]);
    if ($methode === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    $roh = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $kopfText = substr($roh, 0, $kopfLaenge);
    preg_match('/^Location:\s*(\S+)/mi', $kopfText, $m);
    return ['status' => $status, 'kopf' => $kopfText, 'text' => substr($roh, $kopfLaenge), 'ziel' => $m[1] ?? null];
}

/** Eine Datei hochladen (multipart/form-data) – sonst wie aufruf(). */
function hochladen(string $basis, string $dose, string $pfad, array $felder, string $feld, string $inhalt, string $name): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $felder + [$feld => new CURLStringFile($inhalt, $name, 'application/json')],
    ]);
    $roh = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    preg_match('/^Location:\s*(\S+)/mi', substr($roh, 0, $kopfLaenge), $m);
    return ['status' => $status, 'text' => substr($roh, $kopfLaenge), 'ziel' => $m[1] ?? null];
}

function token(string $html): string
{
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

/** @return array{dose: string, token: string} */
function anmelden(string $basis, string $name, string $kennwort): array
{
    $dose = tempnam(sys_get_temp_dir(), 'pm-web-');
    $seite = aufruf($basis, $dose, 'GET', '/login');
    $antwort = aufruf($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => token($seite['text'])]);
    pruefe($antwort['status'] === 302 && $antwort['ziel'] !== '/login', 'Anmeldung ' . $name . ' → ' . (string) $antwort['ziel']);
    $profil = aufruf($basis, $dose, 'GET', '/profil');
    return ['dose' => $dose, 'token' => token($profil['text'])];
}

/** PHP-Meldungen im ausgelieferten HTML (nur mit display_errors, also app.debug). */
function phpMeldung(string $text): ?string
{
    return preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:.*?(?=<br|$)/s', $text, $m) ? strip_tags($m[0]) : null;
}

/**
 * PHP-Meldungen, die der Server seit dem letzten Aufruf auf die
 * Standardfehlerausgabe geschrieben hat – dort stehen sie ohne app.debug.
 *
 * @return list<string>
 */
function serverMeldungen(string $datei): array
{
    static $gelesen = 0;
    clearstatcache(true, $datei);
    $text = (string) @file_get_contents($datei, false, null, $gelesen);
    $gelesen += strlen($text);
    preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $text, $m);
    return $m[0];
}

$kennung = 'pm-web-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6));
$benutzer = [];
$dosen = [];
$eventworxVorher = [];
foreach ([Eventworx::EINSTELLUNG_HOST, Eventworx::EINSTELLUNG_TOKEN_HOST, Settings::GEHEIM_EVENTWORX_TOKEN] as $s) {
    $eventworxVorher[$s] = Settings::get($s);
}
foreach (array_merge(array_keys(Einstellungen::VORGABEN), array_keys(\App\Kunden\KundenEinstellungen::VORGABEN)) as $s) {
    $eventworxVorher[$s] = Settings::get($s);
}
$bSite = 0;
$planSite = 0;
// Audit-Einträge dieses Laufs zu eigenen Rollen, Standorten, Etagen und Anlagen
// tragen die Kennung nicht im Text – am Ende über ihr Ziel weg.
$auditVorher = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log');
$auditZiele = [];
// Nummernzähler der Übergabeberichte: die Prüfung vergibt Nummern im laufenden Jahr – am Ende zurück.
$nummernVorher = array_column(Database::select('SELECT jahr, laufnr FROM bericht_nummern'), 'laufnr', 'jahr');

try {
    $benutzer['admin'] = BenutzerService::anlegen($kennung . '-admin', $kennwort, 'admin', false);
    $benutzer['kunde'] = BenutzerService::anlegen($kennung . '-kunde', $kennwort, 'kunde', false);
    $benutzer['technik'] = BenutzerService::anlegen($kennung . '-technik', $kennwort, 'technik', false);
    $benutzer['zwei'] = BenutzerService::anlegen($kennung . '-zwei', $kennwort, 'kunde', false);
    $benutzer['vorlaeufig'] = BenutzerService::anlegen($kennung . '-vorlaeufig', $kennwort, 'kunde', true);
    // Eine Rolle nur mit „Benutzer verwalten“ – ohne Administration.
    $verwalterRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' verwalter', '', ['system.users.manage', 'dashboard.view'])['id'];
    $benutzer['verwalter'] = BenutzerService::anlegen($kennung . '-verwalter', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$verwalterRolle]), false);
    $kleinRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' klein', '', ['dashboard.view'])['id'];
    // Planer: ändert auf einem eigenen Standort, sieht einen fremden nur.
    $planerRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' planer', '', ['anlagen.view', 'anlagen.manage', 'karte.view', 'plans.manage', 'leitungen.manage'])['id'];
    $benutzer['planer'] = BenutzerService::anlegen($kennung . '-planer', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$planerRolle]), false);
    $benutzer['klein'] = BenutzerService::anlegen($kennung . '-klein', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$kleinRolle]), false);
    // MQTT-Zugänge verwalten, aber nicht alle Geräte sehen.
    $mqttRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' mqtt', '', ['datenquellen.view', 'mqtt.zugaenge'])['id'];
    $benutzer['mqtt'] = BenutzerService::anlegen($kennung . '-mqtt', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$mqttRolle]), false);
    // Katalog importieren, aber keine Steckertypen anlegen.
    $importRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' import', '', ['anlagen.view', 'anlagen.manage', 'anlagen.scope.all'])['id'];
    $benutzer['import'] = BenutzerService::anlegen($kennung . '-import', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$importRolle]), false);

    // ---------------------------------------------------- ohne Anmeldung
    $leer = tempnam(sys_get_temp_dir(), 'pm-web-');
    $dosen[] = $leer;
    $r = aufruf($basis, $leer, 'GET', '/dashboard');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'ohne Anmeldung → Anmeldeseite');
    $r = aufruf($basis, $leer, 'GET', '/login');
    pruefe($r['status'] === 200 && token($r['text']) !== '', 'Anmeldeseite mit CSRF-Token');
    $r = aufruf($basis, $leer, 'POST', '/login', ['username' => $kennung . '-admin', 'password' => 'falsch', '_token' => token($r['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/login', 'falsches Kennwort bleibt auf der Anmeldeseite');
    $r = aufruf($basis, $leer, 'GET', '/manifest');
    pruefe($r['status'] === 200 && json_decode($r['text'], true) !== null, 'Web-App-Manifest');
    foreach (['/assets/css/strom.css', '/assets/js/live.js', '/assets/js/module/karte.js', '/assets/vendor/uplot/uPlot.iife.min.js'] as $datei) {
        gleich(aufruf($basis, $leer, 'GET', $datei)['status'], 200, 'Datei ' . $datei);
    }
    serverMeldungen($serverFehler);
    $r = aufruf($basis, $leer, 'GET', '/gibt-es-nicht');
    pruefe($r['status'] === 404 && substr_count($r['text'], '<!DOCTYPE') === 1 && !str_contains($r['text'], 'action="/logout"') && !str_contains($r['text'], 'href="/profil"'),
        'ohne Anmeldung: 404 als eigenes Dokument, ohne Navigation und Abmelden');
    gleich(serverMeldungen($serverFehler), [], 'ohne Anmeldung: 404 ohne PHP-Meldung');

    // ---------------------------------------------------- Admin: jede Seite
    $admin = anmelden($basis, $kennung . '-admin', $kennwort);
    $dosen[] = $admin['dose'];
    $anlage = (int) Database::scalar('SELECT id FROM objekte WHERE ist_vorlage = 0 ORDER BY id LIMIT 1');
    $etage = (int) Database::scalar('SELECT id FROM floors ORDER BY id LIMIT 1');
    $leitung = (int) Database::scalar('SELECT id FROM leitungen ORDER BY id LIMIT 1');
    $profil = (int) Database::scalar('SELECT id FROM registerprofile ORDER BY id LIMIT 1');

    $seiten = ['/dashboard', '/dashboard/live', '/profil', '/karte', '/anlagen', '/anlagen/baum', '/anlagen/katalog', '/anlagen/neu',
        '/stecker', '/standorte', '/location', '/verteiler-abgleich', '/statistik', '/statistik?zeitraum=7d', '/statistik?zeitraum=frei&von=2026-01-01&bis=2026-01-02',
        '/datenquellen', '/datenquellen/profile', '/datenquellen/mqtt-zugaenge', '/datenquellen/mqtt-zugaenge?filter=eigen', '/steuerung/protokoll', '/system/benutzer', '/system/freigaben?art=user&id=' . $benutzer['kunde'],
        '/system/freigaben?art=role&id=' . (int) Database::scalar("SELECT id FROM roles WHERE role_key = 'kunde'"), '/system/rollen',
        '/system/einstellungen', '/system/sso', '/audit', '/anlagen?fest=1', '/anlagen?fest=0', '/anlagen/neu?art=anschluss&fest=1',
        '/anlagen/neu?art=anschluss&vorlage=1&fest=1'];
    // Jede Anlage und jeder Standard-Verteiler mit allen Reitern – Aggregat,
    // Speicher und Verteilung mit Messgeräten zeigen jeweils anderes.
    foreach (array_map('intval', array_column(Database::select('SELECT id FROM objekte ORDER BY id LIMIT 30'), 'id')) as $id) {
        foreach (['', '/druck', '/live', '/bearbeiten', '?reiter=anschluesse', '?reiter=schutz', '?reiter=messung', '?reiter=kabel'] as $zusatz) {
            $seiten[] = '/anlagen/' . $id . $zusatz;
        }
        $seiten[] = '/karte/objekt/' . $id;
    }
    if ($etage > 0) {
        array_push($seiten, '/karte?etage=' . $etage, '/karte/' . $etage . '/stand', '/karte/' . $etage . '/live');
    }
    if ($leitung > 0) {
        $seiten[] = '/karte/leitung/' . $leitung;
    }
    if ($profil > 0) {
        $seiten[] = '/datenquellen/profile/' . $profil;
    }
    // Aufbau: Übersicht, jeder Aufbau mit Filtern, Abgleich, Druck, Karte im Aufbau-Modus
    $seiten[] = '/aufbau';
    foreach (array_map('intval', array_column(Database::select('SELECT id FROM aufbauten ORDER BY id LIMIT 3'), 'id')) as $id) {
        foreach (['', '?zeigen=offen', '?zeigen=geaendert', '?zeigen=zusaetzlich', '/abgleich', '/druck'] as $zusatz) {
            $seiten[] = '/aufbau/' . $id . $zusatz;
        }
    }
    if ($etage > 0) {
        $seiten[] = '/karte?etage=' . $etage . '&modus=aufbau';
    }
    // Inventar: Liste, Formulare, jedes Stück
    array_push($seiten, '/inventar', '/inventar?art=zwischenstecker', '/inventar?art=tanksensor', '/inventar?suche=PD', '/inventar/neu?art=verteilung',
        '/inventar/neu?art=zwischenstecker', '/inventar/neu?art=tanksensor');
    foreach (array_map('intval', array_column(Database::select('SELECT id FROM inventar ORDER BY id LIMIT 10'), 'id')) as $id) {
        array_push($seiten, '/inventar/' . $id, '/inventar/' . $id . '/bearbeiten');
    }
    // Bereiche: Übersicht, Formular, jede Seite eines Bereichs, Materialliste
    array_push($seiten, '/bereiche', '/bereiche/live', '/bereiche/neu');
    if ($etage > 0) {
        $seiten[] = '/karte/' . $etage . '/material';
    }
    foreach (array_map('intval', array_column(Database::select('SELECT id FROM bereiche ORDER BY id LIMIT 5'), 'id')) as $id) {
        foreach (['', '?reiter=material', '?zeitraum=7d', '/bearbeiten', '/material', '/daten?feld=p', '/daten?feld=i', '/bereiche/live?id=' . $id] as $zusatz) {
            $seiten[] = str_starts_with($zusatz, '/bereiche') ? $zusatz : '/bereiche/' . $id . $zusatz;
        }
    }
    foreach ($seiten as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200, 'Admin ' . $pfad . ' → ' . $r['status']);
        $meldung = phpMeldung($r['text']);
        pruefe($meldung === null, 'Admin ' . $pfad . ' ohne PHP-Meldung ' . (string) $meldung);
    }
    $csv = aufruf($basis, $admin['dose'], 'GET', '/statistik/csv?zeitraum=24h');
    pruefe($csv['status'] === 200 && str_contains($csv['kopf'], 'text/csv'), 'CSV-Export');
    $r = aufruf($basis, $admin['dose'], 'GET', '/gibt-es-nicht');
    pruefe($r['status'] === 404 && substr_count($r['text'], '<!DOCTYPE') === 1 && !str_contains($r['text'], 'action="/logout"'), 'unbekannte Adresse → 404, Fehlerseite ohne Layout');
    gleich(serverMeldungen($serverFehler), [], 'Admin: alle Seiten ohne PHP-Meldung im Fehlerprotokoll des Servers');
    gleich(aufruf($basis, $admin['dose'], 'GET', '/system/freigaben?id=0')['status'], 404, 'Freigaben ohne Benutzer → 404');

    // Plandatei des Personals: dieselben Kopfzeilen wie immer, seit sie über
    // SiteService::planAusliefern() läuft (das auch der Kundenbereich nutzt).
    $planSite = \App\Services\SiteService::saveSite(null, ['name' => $kennung . ' Plan']);
    $auditZiele[] = ['site', $planSite];
    $planEtage = \App\Services\SiteService::saveFloor(null, ['site_id' => $planSite, 'name' => $kennung . ' EG']);
    $planOhne = \App\Services\SiteService::saveFloor(null, ['site_id' => $planSite, 'name' => $kennung . ' OG']);
    array_push($auditZiele, ['floor', $planEtage], ['floor', $planOhne]);
    $planBytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $planDatei = 'site' . $planSite . '_' . bin2hex(random_bytes(8)) . '.png';
    file_put_contents(\App\Services\SiteService::planDir() . '/' . $planDatei, $planBytes);
    Database::update('floors', $planEtage, ['plan_file' => $planDatei, 'plan_type' => 'image', 'plan_original_name' => 'Hallenplan EG.png']);
    $r = aufruf($basis, $admin['dose'], 'GET', '/grundriss/' . $planEtage . '/datei');
    $kopf = static fn (array $r, string $name): string => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $r['kopf'], $m) === 1 ? trim($m[1]) : '';
    gleich([$r['status'], $kopf($r, 'Content-Type'), $kopf($r, 'Content-Disposition'), $kopf($r, 'Content-Length'), $kopf($r, 'X-Content-Type-Options'),
        $kopf($r, 'Cache-Control'), $kopf($r, 'Content-Security-Policy'), $kopf($r, 'Referrer-Policy'), $r['text'] === $planBytes],
        [200, 'image/png', 'inline; filename="Hallenplan EG.png"', (string) strlen($planBytes), 'nosniff', 'private, max-age=600',
            "default-src 'none'; style-src 'unsafe-inline'; sandbox", 'no-referrer', true], 'Plandatei des Personals: Kopfzeilen und Inhalt unverändert');
    $r = aufruf($basis, $admin['dose'], 'GET', '/grundriss/' . $planOhne . '/datei');
    gleich([$r['status'], $r['text']], [404, 'kein Grundriss hinterlegt'], 'Etage ohne Plan: 404 wie bisher');
    gleich(aufruf($basis, $admin['dose'], 'GET', '/grundriss/999999999/datei')['status'], 404, 'Plandatei einer unbekannten Etage: 404');

    // Eventworx-Karte: Token verschlüsselt, an den Host gebunden, nie in der Seite
    $ewxToken = 'tok-' . $kennung . '-' . bin2hex(random_bytes(6));
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/eventworx', ['eventworx_adresse' => $kennung, 'eventworx_token' => $ewxToken, '_token' => $admin['token']]);
    Settings::vergessen();
    pruefe($r['status'] === 302 && Settings::get(Eventworx::EINSTELLUNG_TOKEN_HOST) === $kennung . '.eventworx.biz'
        && !str_contains((string) Settings::get(Settings::GEHEIM_EVENTWORX_TOKEN), $ewxToken), 'Eventworx: Mandant und Token (verschlüsselt) gespeichert');
    $r = aufruf($basis, $admin['dose'], 'GET', '/system/einstellungen');
    pruefe($r['status'] === 200 && str_contains($r['text'], 'hinterlegt für ' . $kennung . '.eventworx.biz') && !str_contains($r['text'], $ewxToken)
        && phpMeldung($r['text']) === null, 'Eventworx-Karte zeigt den Host, nie das Token');
    pruefe(!str_contains($r['text'], 'onclick=') && !str_contains($r['text'], 'javascript:'), 'Einstellungen ohne Inline-JavaScript');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/eventworx/pruefen', ['_token' => $admin['token']]);
    gleich($r['status'], 302, 'Eventworx: Verbindung prüfen (Demo: ohne Netz)');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/eventworx', ['eventworx_adresse' => 'https://evil.example', 'eventworx_token' => '', '_token' => $admin['token']]);
    Settings::vergessen();
    gleich(Settings::get(Eventworx::EINSTELLUNG_HOST), $kennung . '.eventworx.biz', 'Eventworx: fremde Adresse abgelehnt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/eventworx', ['eventworx_adresse' => $kennung . '-neu', 'eventworx_token' => '', '_token' => $admin['token']]);
    Settings::vergessen();
    pruefe(!Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN) && Settings::get(Eventworx::EINSTELLUNG_TOKEN_HOST) === null,
        'Eventworx: neue Adresse ohne neues Token entfernt das alte');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/eventworx', ['eventworx_adresse' => $kennung, 'eventworx_token' => $ewxToken]);
    Settings::vergessen();
    pruefe($r['status'] === 302 && !Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN), 'Eventworx ohne CSRF-Token → abgelehnt');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE ?', ['%' . $ewxToken . '%']), 0, 'Eventworx-Token nicht im Audit');

    // Kunden & Kundenbereich: Liste, jeder Reiter, Karte in den Einstellungen; ohne „kunden.view“ 403.
    $kundeId = (int) (\App\Kunden\KundenService::speichern(null, ['name' => $kennung . ' Kunde', 'aktiv' => 1])['id'] ?? 0);
    $auditZiele[] = ['kunde', $kundeId];
    $kundenSeiten = ['/kunden', '/kunden?suche=' . $kennung, '/kunden?aktiv=0', '/kunden/' . $kundeId];
    foreach (array_keys(\App\Controllers\KundenController::REITER) as $tab) {
        $kundenSeiten[] = '/kunden/' . $kundeId . '?tab=' . $tab;
    }
    foreach ($kundenSeiten as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && !str_contains($r['text'], 'onclick=') && !str_contains($r['text'], 'javascript:'),
            'Admin ' . $pfad . ' → ' . $r['status'] . ', ohne PHP-Meldung und Inline-JavaScript');
    }
    pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/kunden')['text'], $kennung . ' Kunde'), 'Kundenliste zeigt den Kunden');
    $r = aufruf($basis, $admin['dose'], 'GET', '/system/einstellungen');
    pruefe(str_contains($r['text'], 'id="kundenbereich"') && str_contains($r['text'], 'app.url'), 'Einstellungen: Karte „Kundenbereich“ mit dem Stand von app.url');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/kundenbereich', ['_token' => $admin['token'], 'kundenbereich__kontakt' => $kennung . ' Tel. 0',
        'kundenbereich__einrichtung_stunden' => '48', 'kundenbereich__nachweise_jahre' => '5']);
    Settings::vergessen();
    gleich([$r['status'], Settings::get('kundenbereich.kontakt'), Settings::get('kundenbereich.einrichtung_stunden'), Settings::get('kundenbereich.nachweise_jahre')],
        [302, $kennung . ' Tel. 0', '48', '5'], 'Einstellungen des Kundenbereichs gespeichert');
    aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/kundenbereich', ['_token' => $admin['token'], 'kundenbereich__kontakt' => 'anders',
        'kundenbereich__einrichtung_stunden' => '500']);
    Settings::vergessen();
    gleich([Settings::get('kundenbereich.kontakt'), Settings::get('kundenbereich.einrichtung_stunden')], [$kennung . ' Tel. 0', '48'],
        'ungültige Gültigkeit (500 Stunden): nichts gespeichert');
    $klein = anmelden($basis, $kennung . '-klein', $kennwort);
    $dosen[] = $klein['dose'];
    gleich([aufruf($basis, $klein['dose'], 'GET', '/kunden')['status'], aufruf($basis, $klein['dose'], 'GET', '/kunden/' . $kundeId)['status']], [403, 403],
        'ohne „kunden.view“: Kundenverwaltung 403');
    gleich(serverMeldungen($serverFehler), [], 'Kundenverwaltung ohne PHP-Meldung im Fehlerprotokoll des Servers');

    // Livewerte der Karte: je Phase Spannung, Strom, Leistung (Detail beim Antippen des Werts)
    if ($etage > 0) {
        $liveKarte = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . $etage . '/live')['text'], true);
        $mitPhasen = array_filter($liveKarte['objekte'] ?? [], static fn ($l) => isset($l['phasen']['L1']['u'], $l['phasen']['L1']['i']) || isset($l['phasen']['sum']['p']));
        pruefe($mitPhasen !== [], 'Karte: Livewerte mit Werten je Phase für die Detailansicht');
    }

    // Auf der Karte setzen: Feststromanschluss, aus einem festen Katalog-Typ, mobil
    if ($etage > 0) {
        $json = ['Accept: application/json'];
        $setzen = static fn (array $felder): array => json_decode(aufruf($basis, $admin['dose'], 'POST', '/karte/objekt/setzen',
            $felder + ['floor_id' => $etage, 'x' => 50, 'y' => 50, '_token' => $admin['token']], $json)['text'], true) ?? [];
        $fest = $setzen(['art' => 'anschluss', 'fest' => 1, 'name' => $kennung . '-fest']);
        $mobil = $setzen(['art' => 'verteilung', 'fest' => 0, 'name' => $kennung . '-mobil']);
        pruefe(($fest['ok'] ?? false) && ($mobil['ok'] ?? false), 'Karte: Feststromanschluss und mobile Verteilung gesetzt');
        gleich((int) Database::scalar('SELECT fest FROM objekte WHERE id = ?', [(int) ($fest['id'] ?? 0)]), 1, 'Karte: Feststromanschluss ist fest');
        gleich((int) Database::scalar('SELECT fest FROM objekte WHERE id = ?', [(int) ($mobil['id'] ?? 0)]), 0, 'Karte: Verteilung ist mobil');
        $festTyp = (int) Database::scalar('SELECT id FROM objekte WHERE ist_vorlage = 1 AND fest = 1 ORDER BY id LIMIT 1');
        if ($festTyp > 0) {
            $ausTyp = $setzen(['vorlage_id' => $festTyp, 'name' => $kennung . '-typ']);
            gleich((int) Database::scalar('SELECT fest FROM objekte WHERE id = ?', [(int) ($ausTyp['id'] ?? 0)]), 1, 'Karte: aus festem Katalog-Typ wird ein Feststromanschluss');
        }
        // Kabel über die Karte: zwischen zwei Feststromanschlüssen fest, zu einer mobilen Anlage nicht erlaubt
        $kabel = static fn (array $felder): array => json_decode(aufruf($basis, $admin['dose'], 'POST', '/karte/leitung/speichern',
            $felder + ['art' => 'kabel', 'floor_id' => $etage, 'punkte' => '[[50,50],[52,52]]', '_token' => $admin['token']], $json)['text'], true) ?? [];
        $zweiterFest = $setzen(['art' => 'anschluss', 'fest' => 1, 'name' => $kennung . '-fest2']);
        $erd = $kabel(['von_objekt_id' => (int) ($fest['id'] ?? 0), 'zu_objekt_id' => (int) ($zweiterFest['id'] ?? 0), 'name' => $kennung . '-erdkabel']);
        pruefe(($erd['ok'] ?? false) && (int) Database::scalar('SELECT fest FROM leitungen WHERE id = ?', [(int) ($erd['id'] ?? 0)]) === 1, 'Karte: Kabel zwischen Feststromanschlüssen ist fest verlegt');
        $falsch = $kabel(['von_objekt_id' => (int) ($fest['id'] ?? 0), 'zu_objekt_id' => (int) ($mobil['id'] ?? 0), 'fest' => 1, 'name' => $kennung . '-falsch']);
        pruefe(($falsch['ok'] ?? true) === false, 'Karte: festes Kabel an mobiler Anlage abgelehnt');
        $stand = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . $etage . '/stand')['text'], true);
        $jeId = array_column($stand['objekte'] ?? [], 'fest', 'id');
        pruefe(($jeId[(int) ($fest['id'] ?? 0)] ?? null) === true && ($jeId[(int) ($mobil['id'] ?? 0)] ?? null) === false, 'Karte: Stand meldet fest und mobil');
        $leitungFest = array_column($stand['leitungen'] ?? [], 'fest', 'id');
        gleich($leitungFest[(int) ($erd['id'] ?? 0)] ?? null, true, 'Karte: Stand meldet das feste Kabel');
        // Kabelende auf der Karte umstecken: auf die mobile Verteilung – das Kabel wird mobil
        $um = json_decode(aufruf($basis, $admin['dose'], 'POST', '/karte/leitung/ende', ['id' => (int) ($erd['id'] ?? 0), 'ende' => 'zu',
            'objekt_id' => (int) ($mobil['id'] ?? 0), 'punkte' => '[[50,50],[51,51]]', '_token' => $admin['token']], $json)['text'], true) ?? [];
        $nachher = Database::first('SELECT zu_objekt_id, fest FROM leitungen WHERE id = ?', [(int) ($erd['id'] ?? 0)]);
        pruefe(($um['ok'] ?? false) && (int) $nachher['zu_objekt_id'] === (int) ($mobil['id'] ?? 0) && (int) $nachher['fest'] === 0,
            'Karte: Kabelende umgesteckt, festes Kabel an mobiler Anlage wird mobil');
        $art = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . (int) ($mobil['id'] ?? 0) . '/bearbeiten?art=generator');
        pruefe($art['status'] === 200 && str_contains($art['text'], 'Wird beim Speichern zu „Generator“') && phpMeldung($art['text']) === null, 'Art im Formular umstellbar');
        Database::execute('DELETE FROM leitungen WHERE name LIKE ?', [$kennung . '%']);

        // Tanks: im Plan setzen, eigene Seite ohne Anschlüsse, Formular ohne elektrische Felder, Art bleibt
        $tank = $setzen(['art' => 'tank', 'fest' => 0, 'name' => $kennung . '-tank']);
        $tankId = (int) ($tank['id'] ?? 0);
        pruefe(($tank['ok'] ?? false) && (int) Database::scalar('SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ?', [$tankId]) === 0, 'Karte: Tank gesetzt, ohne Anschlüsse');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/speichern', ['id' => $tankId, 'art' => 'tank', 'name' => $kennung . '-tank', 'floor_id' => $etage,
            'status' => 'geplant', 'nennleistung_kva' => '80', 'eigenschaften' => ['inhalt_l' => '3000', 'kraftstoff' => 'Diesel', 'liter_je_mm' => '2,78'], '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/' . $tankId && Database::scalar('SELECT nennleistung_kva FROM objekte WHERE id = ?', [$tankId]) === null,
            'Tank per Formular gespeichert, ohne Nennleistung → ' . $r['ziel']);
        foreach (['', '?reiter=anschluesse', '/bearbeiten', '/druck', '/live'] as $zusatz) {
            $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . $zusatz);
            pruefe($r['status'] === 200 && phpMeldung($r['text']) === null, 'Tank /anlagen/' . $tankId . $zusatz . ' → ' . $r['status'] . ' ' . (string) phpMeldung($r['text']));
        }
        foreach (['/anlagen?art=tank', '/anlagen/neu?art=tank', '/anlagen/neu?art=tank&vorlage=1', '/karte/objekt/' . $tankId] as $pfad) {
            $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
            pruefe($r['status'] === 200 && phpMeldung($r['text']) === null, 'Admin ' . $pfad . ' → ' . $r['status']);
        }
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId)['text'];
        pruefe(str_contains($seite, '3.000 l · Diesel') && str_contains($seite, '2,78') && !str_contains($seite, 'Verteilerplan') && !str_contains($seite, '?reiter=anschluesse'),
            'Tankseite: Daten mit Inhalt, ohne Verteilerplan und Anschluss-Reiter');
        $form = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/bearbeiten?art=generator')['text'];
        pruefe(!str_contains($form, 'name="nennleistung_kva"') && !str_contains($form, 'name="steuerung_gesperrt"') && str_contains($form, '<legend>Kennlinie</legend>')
            && !str_contains($form, '<option value="generator"') && !str_contains($form, 'Wird beim Speichern zu'), 'Tank-Formular: ohne elektrische Felder, in Gruppen, Art bleibt Tank');
        pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/anlagen/neu?art=tank')['text'], 'Neuer Tank'), 'Formular „Neuer Tank“');
        $mobilForm = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . (int) ($mobil['id'] ?? 0) . '/bearbeiten?art=tank')['text'];
        pruefe(!str_contains($mobilForm, '<option value="tank"') && !str_contains($mobilForm, 'Wird beim Speichern zu „Tank“'), 'eine Verteilung lässt sich nicht zum Tank umstellen');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/speichern', ['id' => $tankId, 'art' => 'generator', 'name' => $kennung . '-tank', 'floor_id' => $etage, '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && (string) Database::scalar('SELECT art FROM objekte WHERE id = ?', [$tankId]) === 'tank', 'Tank → Generator über das Formular abgelehnt');
        $r = json_decode(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/anschluesse', ['abgang' => [(int) Database::scalar("SELECT id FROM steckertypen WHERE schluessel = 'cee16'") => 2],
            '_token' => $admin['token']], $json)['text'], true);
        pruefe(($r['ok'] ?? true) === false && (int) Database::scalar('SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ?', [$tankId]) === 0, 'Anschlüsse am Tank abgelehnt');
        $r = $kabel(['von_objekt_id' => (int) ($mobil['id'] ?? 0), 'zu_objekt_id' => $tankId, 'name' => $kennung . '-zum-tank']);
        pruefe(($r['ok'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'Tank'), 'Karte: Kabel zum Tank abgelehnt – ' . (string) ($r['message'] ?? ''));
        $stand = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . $etage . '/stand')['text'], true);
        $imPlan = array_column($stand['objekte'] ?? [], null, 'id');
        pruefe(($imPlan[$tankId]['elektrisch'] ?? null) === false && str_contains((string) ($imPlan[$tankId]['kurz'] ?? ''), '3.000 l'), 'Karte: Stand kennt den Tank mit Inhalt');
        $katalog = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog')['text'];
        pruefe(str_contains($katalog, 'href="/anlagen/neu?art=tank&amp;vorlage=1"'), 'Katalog: Knopf „+ Tank-Typ“');
        pruefe(str_contains($katalog, 'href="/anlagen?art=tank"'), 'Navigation: Tanks');
        $siteTank = (int) Database::scalar('SELECT site_id FROM objekte WHERE id = ?', [$tankId]);
        $bereichForm = aufruf($basis, $admin['dose'], 'GET', '/bereiche/neu?standort=' . $siteTank)['text'];
        pruefe(str_contains($bereichForm, htmlspecialchars($kennung . '-mobil')) && !str_contains($bereichForm, htmlspecialchars($kennung . '-tank')), 'Bereich: Tank nicht wählbar');

        // Kraftstoffschlauch über die Karte: Tank ↔ Generator (nicht zur Verteilung), Stand, Fenster, Tankseite, Material
        $genWeb = (int) ($setzen(['art' => 'generator', 'fest' => 0, 'name' => $kennung . '-gen'])['id'] ?? 0);
        $schlauch = static fn (array $felder): array => json_decode(aufruf($basis, $admin['dose'], 'POST', '/karte/leitung/speichern',
            $felder + ['art' => 'kraftstoff', 'floor_id' => $etage, 'punkte' => '[[50,50],[51,51]]', '_token' => $admin['token']], $json)['text'], true) ?? [];
        $r = $schlauch(['von_objekt_id' => $tankId, 'zu_objekt_id' => (int) ($mobil['id'] ?? 0), 'name' => $kennung . '-schlauch-falsch']);
        pruefe(($r['ok'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'kein Generator'), 'Karte: Schlauch Tank → Verteilung abgelehnt – ' . (string) ($r['message'] ?? ''));
        $r = $schlauch(['von_objekt_id' => $genWeb, 'zu_objekt_id' => $tankId, 'name' => $kennung . '-schlauch', 'kabeltyp' => 'DN 10 (3/8″)', 'anzahl' => '2',
            'modell' => '', 'laenge_m' => '', 'fest' => '0', 'notizen' => '']);
        $schlauchId = (int) ($r['id'] ?? 0);
        pruefe(($r['ok'] ?? false) && (int) Database::scalar('SELECT von_objekt_id FROM leitungen WHERE id = ?', [$schlauchId]) === $tankId,
            'Karte: Schlauch vom Generator zum Tank gezogen – „von“ ist der Tank');
        $stand = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . $etage . '/stand')['text'], true);
        $l = array_column($stand['leitungen'] ?? [], null, 'id')[$schlauchId] ?? [];
        gleich([$l['art'] ?? null, $l['von'] ?? null, $l['zu'] ?? null, $l['modell'] ?? null, $l['anzahl'] ?? null], ['kraftstoff', $tankId, $genWeb, 'HK-A3', 2],
            'Karte: Stand mit dem Schlauch (Kupplung HK-A3, Vor- und Rücklauf)');
        $fenster = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/leitung/' . $schlauchId)['text'], true);
        pruefe(($fenster['ok'] ?? false) && ($fenster['leitung']['art'] ?? '') === 'kraftstoff' && ($fenster['enden'] ?? null) === ['von' => [], 'zu' => []] && ($fenster['darf'] ?? false),
            'Karte: Schlauchfenster ohne Anschlüsse');
        $r = json_decode(aufruf($basis, $admin['dose'], 'POST', '/karte/leitung/ende', ['id' => $schlauchId, 'ende' => 'zu', 'objekt_id' => 0, 'punkte' => '[[50,50],[52,52]]',
            '_token' => $admin['token']], $json)['text'], true) ?? [];
        pruefe(($r['ok'] ?? true) === false && (int) Database::scalar('SELECT zu_objekt_id FROM leitungen WHERE id = ?', [$schlauchId]) === $genWeb, 'Karte: Schlauchende lässt sich nicht lösen');
        $karteSeite = aufruf($basis, $admin['dose'], 'GET', '/karte?etage=' . $etage)['text'];
        pruefe(str_contains($karteSeite, 'data-modus="schlauch"') && str_contains($karteSeite, 'data-ebene="kraftstoff"') && str_contains($karteSeite, '"schlauchtypen"'),
            'Karte: Werkzeug „Schlauch“, Ebene und Schlauchtypen');
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId);
        pruefe($seite['status'] === 200 && phpMeldung($seite['text']) === null && str_contains($seite['text'], 'id="versorgung"') && str_contains($seite['text'], htmlspecialchars($kennung . '-gen'))
            && str_contains($seite['text'], 'data-tank="rest"'), 'Tankseite: Versorgung mit dem Generator am Schlauch');
        $material = aufruf($basis, $admin['dose'], 'GET', '/karte/' . $etage . '/material');
        pruefe($material['status'] === 200 && phpMeldung($material['text']) === null && str_contains($material['text'], 'Kraftstoff') && str_contains($material['text'], 'DN 10 (3/8″)')
            && str_contains($material['text'], 'HK-A3'), 'Material der Etage: Kraftstoff mit Schlauch und Kupplungen');
        $genSeite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $genWeb);
        pruefe($genSeite['status'] === 200 && phpMeldung($genSeite['text']) === null, 'Generator am Schlauch: Seite ohne PHP-Meldung');

        // Tanksensor: über das Formular anlegen, in den Tank einbauen (mit Einbaumaß), ausbauen
        $neuTs = aufruf($basis, $admin['dose'], 'GET', '/inventar/neu?art=tanksensor');
        pruefe($neuTs['status'] === 200 && phpMeldung($neuTs['text']) === null && str_contains($neuTs['text'], 'Neuer Tanksensor')
            && str_contains($neuTs['text'], 'name="eigenschaften[prinzip]"') && str_contains($neuTs['text'], 'value="4-20mA-wandler" selected')
            && !str_contains($neuTs['text'], 'name="steckertyp_id"') && !str_contains($neuTs['text'], 'data-zeile-dazu'),
            'Formular „Neuer Tanksensor“: Sensordaten mit Vorgabe Wandler, ohne Stecker und ohne weitere Shellys');
        $r = aufruf($basis, $admin['dose'], 'POST', '/inventar/speichern', ['id' => '', 'art' => 'tanksensor', 'kennung' => $kennung . '-TS',
            'geraet[]' => $kennung . '-uni', 'kanal[]' => 'voltmeter:100', 'stelle[]' => 'fuellstand', 'bezeichnung[]' => '',
            'eigenschaften' => ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA-wandler', 'u_bei_4ma' => '2,0', 'u_bei_20ma' => '10',
                'bereich_von' => '0', 'bereich_bis' => '2', 'bereich_einheit' => 'mWS', 'schritt_roh' => ''], '_token' => $admin['token']]);
        $ts = (int) Database::scalar('SELECT id FROM inventar WHERE kennung = ?', [$kennung . '-TS']);
        pruefe($r['status'] === 302 && $r['ziel'] === '/inventar/' . $ts, 'Tanksensor per Formular angelegt → ' . $r['ziel']);
        foreach (['/inventar/' . $ts => 'Hydrostatisch 0–2 mWS', '/inventar?art=tanksensor' => 'Hydrostatisch 0–2 mWS', '/inventar/' . $ts . '/bearbeiten' => 'value="mWS" selected'] as $pfad => $text) {
            $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
            pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], $text), 'Admin ' . $pfad . ' → ' . $r['status'] . ' mit „' . $text . '“');
        }
        $karteTank = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/objekt/' . $tankId)['text'], true);
        $karteMobil = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/objekt/' . (int) ($mobil['id'] ?? 0))['text'], true);
        pruefe(in_array($ts, array_column($karteTank['inventar_frei'] ?? [], 'id'), true) && array_diff(array_column($karteTank['inventar_frei'] ?? [], 'art'), ['tanksensor']) === []
            && !in_array($ts, array_column($karteMobil['inventar_frei'] ?? [], 'id'), true), 'Karte: am Tank nur Tanksensoren zum Einsetzen, an der Verteilung keine');
        $verteilerSeite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . (int) ($mobil['id'] ?? 0) . '?reiter=messung')['text'];
        pruefe(!str_contains($verteilerSeite, $kennung . '-TS'), 'Verteilung bietet keinen Tanksensor zum Einsetzen an');
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId)['text'];
        pruefe(str_contains($seite, 'Tanksensor einbauen') && str_contains($seite, $kennung . '-TS') && str_contains($seite, 'name="einbau[offset_mm]"'), 'Tankseite: Tanksensor einbauen');
        $peilungenVorher = (int) Database::scalar("SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ? AND art = 'peilung'", [$tankId]);
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/einsetzen', ['inventar_id' => $ts, 'einbau' => ['offset_mm' => '25'], 'peilung_prozent' => '55',
            '_token' => $admin['token']]);
        $mpTs = Database::first("SELECT * FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL", [$tankId]);
        $fTs = $mpTs !== null ? json_decode((string) Database::scalar('SELECT rechnung FROM tank_kennlinien WHERE messpunkt_id = ? ORDER BY id DESC LIMIT 1', [(int) $mpTs['id']]), true) : null;
        pruefe($r['status'] === 302 && ($mpTs['geraet'] ?? '') === $kennung . '-uni' && ($fTs['herkunft']['einbau']['offset_mm'] ?? null) == 25,
            'Tanksensor über das Formular eingebaut, Einbaumaß in der Kennlinie');
        // Eine Peilung beim Einsetzen gibt es nicht: Rohwerte zählen erst ab dem Einbau – erst einbauen, dann die Sonde einhängen, dann peilen.
        gleich((int) Database::scalar("SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ? AND art = 'peilung'", [$tankId]), $peilungenVorher,
            'Einsetzen trägt keine Peilung ein (auch nicht mit einem alten Feld „peilung_prozent“)');
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId);
        pruefe($seite['status'] === 200 && phpMeldung($seite['text']) === null && str_contains($seite['text'], 'seit Einbau nicht gepeilt') && str_contains($seite['text'], 'Ausbauen'),
            'Tankseite: eingebauter Sensor, ungeprüft, mit „Ausbauen“');
        pruefe(str_contains($seite['text'], 'Jetzt die Sonde einhängen und peilen, sobald der Sensor meldet') && !str_contains($seite['text'], 'name="peilung_prozent"'),
            'Meldung nach dem Einsetzen nennt die Reihenfolge; das Einbauformular fragt keine Anzeige mehr ab');
        // Gleich nach dem Einbau peilen: Der Sensor meldet noch nicht – die Peilung steht nur im Protokoll.
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'prozent', 'wert' => '55', '_token' => $admin['token']], $json);
        $erste = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && ($erste['ok'] ?? false) === true && empty($erste['kalibriert']) && str_contains((string) ($erste['message'] ?? ''), 'sobald er meldet'),
            'Peilung gleich nach dem Einbau: im Protokoll, kalibriert wird erst mit einem Wert seit dem Einbau – ' . (string) ($erste['message'] ?? ''));
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId);
        pruefe(str_contains($seite['text'], 'action="/anlagen/' . $tankId . '/tank/peilung"') && str_contains($seite['text'], 'action="/anlagen/' . $tankId . '/tank/betankung"')
            && str_contains($seite['text'], 'name="sensor[mm_je_roh]"') && str_contains($seite['text'], 'name="rueckwirkend"') && str_contains($seite['text'], 'id="protokoll"')
            && str_contains($seite['text'], 'action="/anlagen/' . $tankId . '/tank/ereignis/loeschen"') && str_contains($seite['text'], '/assets/js/tank.js'),
            'Tankseite: Vor Ort (Peilung, Betankung), Protokoll mit Löschen, Erweitert mit „rückwirkend“');
        pruefe(str_contains($seite['text'], 'data-quelle="/anlagen/' . $tankId . '/tank/verlauf"') && str_contains($seite['text'], 'Fassungen der Kennlinie')
            && str_contains($seite['text'], 'data-tank="roh"') && str_contains($seite['text'], 'data-live-url="/anlagen/' . $tankId . '/live"')
            && str_contains($seite['text'], 'id="statistik-daten"'), 'Tankseite: Jetzt (live), Verlauf, Fassungen');
        pruefe(str_contains($seite['text'], 'data-tank-tage="/anlagen/' . $tankId . '/tank/tage?') && str_contains($seite['text'], '<template data-tage-vorlage>')
            && str_contains($seite['text'], '/tank/csv?zeitraum=24h&amp;groesse=liter&amp;inhalt=tage'), 'Tankseite: Verbrauch je Tag mit Vorlage „Als Betankung eintragen“ und CSV');
        foreach (['?zeitraum=7d&groesse=roh', '?zeitraum=frei&von=2026-01-01&bis=2026-01-02&groesse=prozent', '?groesse=unsinn'] as $zusatz) {
            $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . $zusatz);
            pruefe($r['status'] === 200 && phpMeldung($r['text']) === null, 'Tankseite ' . $zusatz . ' → ' . $r['status']);
        }
        $live = json_decode(aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/live')['text'], true);
        $lt = $live['objekte'][$tankId] ?? [];
        pruefe(($lt['tank']['sensor'] ?? null) === true && ($lt['tank']['messpunkt'] ?? 0) === (int) ($mpTs['id'] ?? -1) && ($lt['stufe'] ?? '') === 'stumm'
            && str_contains((string) ($lt['hinweis'] ?? ''), 'seit dem Einbau'), 'Tank live: Sensor eingebaut, noch kein Wert seit dem Einbau – ' . (string) ($lt['hinweis'] ?? ''));
        gleich([$lt['tank']['generatoren'] ?? null, array_key_exists('restlaufzeit_h', $lt['tank'] ?? []) ? $lt['tank']['restlaufzeit_h'] : 'fehlt'],
            [[['id' => $genWeb, 'name' => $kennung . '-gen', 'laeuft' => false]], null], 'Tank live: Generator am Schlauch (ohne Messgerät), ohne Restlaufzeit');
        // Eingebaut vor einem Augenblick – im Zeitraum der Statistik läge sonst nichts.
        Database::execute('UPDATE messpunkte SET gueltig_ab = ? WHERE id = ?', [gmdate('c', time() - 600), (int) ($mpTs['id'] ?? 0)]);
        $r = aufruf($basis, $admin['dose'], 'GET', '/statistik?mp[]=' . (int) ($mpTs['id'] ?? 0));
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], 'data-quelle="/anlagen/' . $tankId . '/tank/verlauf?mp=' . (int) ($mpTs['id'] ?? 0) . '"')
            && !str_contains($r['text'], 'Spitzenleistung') && str_contains($r['text'], 'Tankseite'), 'Statistik: Tanksensor mit dem Verlauf des Tanks, ohne Leistungskennzahlen');
        pruefe(str_contains($r['text'], 'data-tank-tage="/anlagen/' . $tankId . '/tank/tage?mp=' . (int) ($mpTs['id'] ?? 0) . '&amp;zeitraum=24h"')
            && !str_contains($r['text'], 'data-tage-vorlage') && str_contains($r['text'], '/assets/js/tank.js'), 'Statistik: Verbrauch je Tag des Tanks, ohne Eintragen');
        $karteTank = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/objekt/' . $tankId)['text'], true);
        pruefe(($karteTank['tank']['sensor']['kennung'] ?? '') === $kennung . '-TS' && ($karteTank['tank']['darf']['einsetzen'] ?? false) === true
            && is_int($karteTank['tank']['sensor']['einsatz_id'] ?? null) && array_key_exists('tank', $karteTank['live'] ?? []), 'Karte: Tank-Panel mit Sensor, Ausbau und Livewerten');
        // Den Messpunkt eines Tanksensors löscht niemand – er geht nur über „Ausbauen“, sonst nähme er alle Fassungen mit.
        $nFassungen = (int) Database::scalar('SELECT COUNT(*) FROM tank_kennlinien WHERE messpunkt_id = ?', [(int) ($mpTs['id'] ?? 0)]);
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/messpunkt/loeschen', ['messpunkt_id' => (int) ($mpTs['id'] ?? 0), '_token' => $admin['token']], $json);
        pruefe($r['status'] === 422 && Database::scalar('SELECT id FROM messpunkte WHERE id = ?', [(int) ($mpTs['id'] ?? 0)]) !== false && $nFassungen > 0
            && (int) Database::scalar('SELECT COUNT(*) FROM tank_kennlinien WHERE messpunkt_id = ?', [(int) ($mpTs['id'] ?? 0)]) === $nFassungen,
            'Messpunkt eines Tanksensors löschen abgelehnt (' . $r['status'] . '): Messpunkt und Fassungen bleiben');
        // Ein Kunde im Kartenpanel: den Sensor sieht er, Knöpfe bekommt er keine.
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['zwei'], 'ziel_art' => 'objekt', 'ziel_id' => $tankId]);
        $kundePanel = anmelden($basis, $kennung . '-zwei', $kennwort);
        $dosen[] = $kundePanel['dose'];
        $p = json_decode(aufruf($basis, $kundePanel['dose'], 'GET', '/karte/objekt/' . $tankId)['text'], true);
        gleich([$p['tank']['darf'] ?? null, $p['tank']['sensor']['kennung'] ?? null,
            array_key_exists('einsatz_id', (array) ($p['tank']['sensor'] ?? [])) ? $p['tank']['sensor']['einsatz_id'] : 'fehlt', $p['darf'] ?? null],
            [['peilen' => false, 'betanken' => false, 'einsetzen' => false], $kennung . '-TS', null, false],
            'Kunde im Kartenpanel: Sensor sichtbar – ohne Peilen, Betanken, Einsetzen und Ausbauen');
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$benutzer['zwei']]);
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/messpunkt', ['geraet' => $kennung . '-hand', 'kanal' => 'voltmeter:100', '_token' => $admin['token']], $json);
        pruefe(Database::scalar('SELECT id FROM messpunkte WHERE geraet = ?', [$kennung . '-hand']) === false, 'kein Messpunkt von Hand am Tank (' . $r['status'] . ')');
        $r = aufruf($basis, $admin['dose'], 'POST', '/einsatz/' . (int) ($mpTs['einsatz_id'] ?? 0) . '/beenden', ['_token' => $admin['token']]);
        pruefe($r['status'] === 302 && Database::scalar("SELECT id FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL", [$tankId]) === false,
            'Tanksensor ausgebaut');

        // Peilung und Betankung vor Ort: Formular, Kartenpanel (JSON), CSRF, Löschen, Protokoll-CSV
        $ereignisse = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankId]);
        $n = $ereignisse();
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'liter', 'wert' => '1500', '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/' . $tankId && $ereignisse() === $n + 1, 'Peilung per Formular → ' . $r['ziel']);
        pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId)['text'], 'Peilung 1.500 l eingetragen'), '… mit Meldung auf der Tankseite');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'prozent', 'wert' => '60', '_token' => $admin['token']], $json);
        $antwort = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && ($antwort['ok'] ?? false) === true && str_contains((string) ($antwort['message'] ?? ''), '60 %') && $ereignisse() === $n + 2, 'Peilung aus dem Kartenpanel (JSON)');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'prozent', 'wert' => '60', '_token' => 'falsch'], $json);
        pruefe($r['status'] !== 200 && $ereignisse() === $n + 2, 'Peilung ohne gültiges Token: nichts eingetragen (' . $r['status'] . ')');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'prozent', 'wert' => '250', '_token' => $admin['token']], $json);
        pruefe($r['status'] === 422 && (json_decode($r['text'], true)['ok'] ?? true) === false && $ereignisse() === $n + 2, 'Peilung über dem Tank abgelehnt (422)');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/betankung', ['liter' => '1000', 'zeit' => '', 'lieferschein' => '=SUMME(A1:A9)',
            'kalibrieren' => '1', '_token' => $admin['token']], $json);
        $antwort = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && ($antwort['ok'] ?? false) === true && is_int($antwort['id'] ?? null) && str_contains((string) ($antwort['message'] ?? ''), 'nur im Protokoll'),
            'Betankung ohne Sensor im Tank: nur im Protokoll – ' . (string) ($antwort['message'] ?? ''));
        $betankung = (int) ($antwort['id'] ?? 0);
        gleich(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/betankung', ['liter' => '0', '_token' => $admin['token']], $json)['status'], 422, 'Betankung ohne Menge → 422');
        gleich(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/betankung/auswerten', ['ereignis_id' => $betankung, '_token' => $admin['token']], $json)['status'], 422,
            'Betankung ohne Sensor lässt sich nicht auswerten');
        gleich(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/kennlinie', ['messpunkt' => $mpTs['id'] ?? 0, 'sensor' => ['roh_anker' => 2, 'mm_anker' => 20, 'mm_je_roh' => 100],
            '_token' => $admin['token']], $json)['status'], 422, 'Kennlinie von Hand ohne laufenden Sensor → 422');
        gleich(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/steigung', ['ereignis_id' => $betankung, '_token' => $admin['token']], $json)['status'], 422,
            'Steigung ohne Haken → 422');
        $csv = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv?inhalt=protokoll');
        pruefe($csv['status'] === 200 && str_contains($csv['kopf'], 'text/csv') && str_contains($csv['text'], '"Zeit";"Art";"Inhalt [l]"') && str_contains($csv['text'], '"Betankung";1000;')
            && str_contains($csv['text'], '"\'=SUMME(A1:A9)"'), 'Protokoll als CSV, der Lieferschein als Text');
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/ereignis/loeschen', ['ereignis_id' => $betankung, '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && Database::scalar('SELECT id FROM tank_ereignisse WHERE id = ?', [$betankung]) === false, 'Betankung gelöscht (Formular)');
        gleich(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . (int) ($mobil['id'] ?? 0) . '/tank/peilung', ['art' => 'prozent', 'wert' => '60', '_token' => $admin['token']], $json)['status'],
            404, 'Peilung an einer Verteilung → 404');
        $karteTank = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/objekt/' . $tankId)['text'], true);
        pruefe(($karteTank['tank']['nenn_l'] ?? null) == 3000 && ($karteTank['tank']['darf']['peilen'] ?? false) === true && count($karteTank['tank']['ereignisse'] ?? []) === 3,
            'Kartenpanel: Nennvolumen, Peilen erlaubt, letzte drei Einträge');
        // Ein Kunde mit Freigabe sieht Tank und Protokoll, trägt aber nichts ein
        $kundeFreigabe = BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['zwei'], 'ziel_art' => 'objekt', 'ziel_id' => $tankId]);
        $kundeTank = anmelden($basis, $kennung . '-zwei', $kennwort);
        $dosen[] = $kundeTank['dose'];
        $r = aufruf($basis, $kundeTank['dose'], 'GET', '/anlagen/' . $tankId);
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], 'id="protokoll"') && !str_contains($r['text'], '/tank/peilung')
            && !str_contains($r['text'], '/tank/betankung') && !str_contains($r['text'], 'Erweitert') && !str_contains($r['text'], '/tank/ereignis/loeschen')
            && !str_contains($r['text'], 'data-tage-vorlage'),
            'Kunde: Tankseite mit Protokoll, ohne Peilung, Betankung, Erweitert, Löschen und „Als Betankung eintragen“');
        pruefe(str_contains($r['text'], 'id="versorgung"') && str_contains($r['text'], 'Generator (nicht freigegeben)') && !str_contains($r['text'], htmlspecialchars($kennung . '-gen')),
            'Kunde: Versorgung ohne den Namen des Generators, den er nicht sieht');
        $n = $ereignisse();
        $r = aufruf($basis, $kundeTank['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'prozent', 'wert' => '60', '_token' => $kundeTank['token']], $json);
        pruefe($r['status'] === 403 && $ereignisse() === $n, 'Kunde: Peilung → 403');
        $eineEreignis = (int) Database::scalar('SELECT id FROM tank_ereignisse WHERE objekt_id = ? ORDER BY id LIMIT 1', [$tankId]);
        $r = aufruf($basis, $kundeTank['dose'], 'POST', '/anlagen/' . $tankId . '/tank/ereignis/loeschen', ['ereignis_id' => $eineEreignis, '_token' => $kundeTank['token']]);
        pruefe($eineEreignis > 0 && $r['status'] === 403 && $ereignisse() === $n, 'Kunde: Löschen → 403');
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$benutzer['zwei']]);
        unset($kundeFreigabe);

        // Technik mit „bearbeiten“, dessen Datenfenster vor einer Stunde endete: sieht den Tank, trägt aber nichts mehr ein –
        // was ab jetzt gälte, könnte er selbst nicht sehen.
        Database::insert('freigaben', ['subject_type' => 'user', 'subject_id' => $benutzer['technik'], 'ziel_art' => 'objekt', 'ziel_id' => $tankId,
            'darf_bearbeiten' => 1, 'darf_steuern' => 0, 'gueltig_ab' => null, 'gueltig_bis' => null,
            'daten_ab' => gmdate('c', time() - 86400), 'daten_bis' => gmdate('c', time() - 3600), 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c')]);
        $technikTank = anmelden($basis, $kennung . '-technik', $kennwort);
        $dosen[] = $technikTank['dose'];
        $r = aufruf($basis, $technikTank['dose'], 'GET', '/anlagen/' . $tankId);
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && !str_contains($r['text'], '/tank/peilung') && !str_contains($r['text'], '/tank/betankung'),
            'Technik nach dem Ende des Datenfensters: Tankseite ohne Peilung und Betankung');
        $n = $ereignisse();
        foreach (['/tank/peilung' => ['art' => 'liter', 'wert' => '1000'], '/tank/betankung' => ['liter' => '100'], '/tank/kennlinie' => ['messpunkt' => 0],
            '/tank/ereignis/loeschen' => ['ereignis_id' => $eineEreignis]] as $pfad => $felder) {
            $r = aufruf($basis, $technikTank['dose'], 'POST', '/anlagen/' . $tankId . $pfad, $felder + ['_token' => $technikTank['token']], $json);
            pruefe($r['status'] === 403 && str_contains((string) (json_decode($r['text'], true)['message'] ?? ''), 'Freigabe für die Messdaten'),
                'Technik nach dem Ende des Datenfensters: ' . $pfad . ' → ' . $r['status']);
        }
        $r = aufruf($basis, $technikTank['dose'], 'POST', '/anlagen/' . $tankId . '/einsetzen', ['inventar_id' => $ts, '_token' => $technikTank['token']], $json);
        gleich([$r['status'], $ereignisse(), Database::scalar("SELECT id FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL", [$tankId])],
            [403, $n, false], '… auch keinen Tanksensor einsetzen, nichts eingetragen');
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$benutzer['technik']]);

        // Füllstand: Live, Verlauf, CSV, Statistik, Karte, Übersicht, Einstellungen
        $mpAlt = (int) ($mpTs['id'] ?? 0);
        $live = json_decode(aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/live')['text'], true);
        pruefe(($live['objekte'][$tankId]['stufe'] ?? '') === 'keine' && ($live['objekte'][$tankId]['tank']['sensor'] ?? null) === false, 'Tank live nach dem Ausbau: kein Sensor');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf?zeitraum=7d&feld=roh');
        $v = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && str_contains($r['kopf'], 'application/json') && ($v['ok'] ?? false) === true && ($v['feld'] ?? '') === 'roh'
            && isset($v['zeiten'], $v['serien'], $v['marken'], $v['teile'], $v['schritt']), 'Tankverlauf als JSON (früherer Sensor zählt mit)');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf?mp=' . $mpAlt . '&groesse=liter');
        pruefe($r['status'] === 200 && (json_decode($r['text'], true)['feld'] ?? '') === 'liter', 'Tankverlauf eines Sensors');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf?mp=' . ($mpAlt + 999999));
        pruefe($r['status'] === 404 && (json_decode($r['text'], true)['ok'] ?? true) === false, 'Tankverlauf: fremder Messpunkt → 404 (JSON)');
        gleich(aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . (int) ($mobil['id'] ?? 0) . '/tank/verlauf')['status'], 404, 'Tankverlauf einer Verteilung → 404');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/tage?zeitraum=7d');
        $v = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['kopf'], 'application/json') && ($v['ok'] ?? false) === true
            && isset($v['tage'], $v['betankungen'], $v['summe'], $v['schwelle_text']) && ($v['darf_eintragen'] ?? null) === true, 'Verbrauch je Tag als JSON');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/tage?mp=' . ($mpAlt + 999999));
        pruefe($r['status'] === 404 && (json_decode($r['text'], true)['ok'] ?? true) === false, 'Verbrauch je Tag: fremder Messpunkt → 404 (JSON)');
        gleich(aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . (int) ($mobil['id'] ?? 0) . '/tank/tage')['status'], 404, 'Verbrauch je Tag einer Verteilung → 404');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv?inhalt=tage&zeitraum=frei&von=2026-01-01&bis=2026-01-03');
        pruefe($r['status'] === 200 && str_contains($r['kopf'], 'text/csv') && str_contains($r['text'], '"Tag";"Verbrauch [l]";"Getankt [l]";"Mittel [l/h]"')
            && str_contains($r['kopf'], 'tage-20260101'), 'Verbrauch je Tag als CSV');
        $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv?zeitraum=24h&mp=' . $mpAlt);
        pruefe($r['status'] === 200 && str_contains($r['kopf'], 'text/csv') && str_contains($r['text'], '"Zeit";"Sensor";"Rohwert') && str_contains($r['text'], '"Inhalt [l]";"Füllstand [%]"'),
            'Tank-CSV mit Kopfzeile');
        pruefe((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'tank.csv' AND target_id = ?", [(string) $tankId]) > 0, 'Tank-CSV steht im Audit');
        $csv = aufruf($basis, $admin['dose'], 'GET', '/statistik/csv?zeitraum=24h&mp[]=' . $mpAlt)['text'];
        pruefe(!str_contains($csv, $kennung . '-TS'), 'Statistik-CSV übergeht den Tanksensor');
        $stand = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . $etage . '/stand')['text'], true);
        $imPlan = array_column($stand['objekte'] ?? [], null, 'id');
        gleich($imPlan[$tankId]['tank'] ?? null, ['nenn_l' => 3000.0, 'sensor' => false], 'Karte: Stand mit Nennvolumen und Sensor des Tanks');
        pruefe(array_key_exists('tank', $imPlan[(int) ($mobil['id'] ?? 0)] ?? []) && $imPlan[(int) ($mobil['id'] ?? 0)]['tank'] === null, 'Karte: eine Verteilung hat keinen Tankteil');
        pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/dashboard')['text'], 'data-art="tank"'), 'Übersicht: Tank-Kachel');
        pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/anlagen?art=tank')['text'], 'data-tank="menge"'), 'Anlagenliste: Inhalt des Tanks statt Leistung');
        $einst = aufruf($basis, $admin['dose'], 'GET', '/system/einstellungen')['text'];
        pruefe(str_contains($einst, 'name="grenzen__tank_warnung"') && str_contains($einst, 'name="grenzen__tank_stumm_h"'), 'Einstellungen: Grenzen der Tanks');
        $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/speichern', ['grenzen__tank_warnung' => '30', '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && Database::scalar("SELECT setting_value FROM settings WHERE setting_key = 'grenzen.tank_warnung'") === '30', 'Einstellungen: Tank-Warngrenze gespeichert');
        aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/speichern', ['grenzen__tank_warnung' => '', '_token' => $admin['token']]);
        \App\Support\Settings::forget('grenzen.tank_warnung');
    }

    // Georeferenz: GeoJSON, Koordinaten im Anlagenfenster, Speichern prüft
    $geoEtage = (int) Database::scalar('SELECT id FROM floors WHERE geo_a_lat IS NOT NULL ORDER BY id LIMIT 1');
    if ($geoEtage > 0) {
        $r = aufruf($basis, $admin['dose'], 'GET', '/karte/' . $geoEtage . '/geojson');
        $geojson = json_decode($r['text'], true);
        pruefe($r['status'] === 200 && str_contains($r['kopf'], 'application/geo+json') && ($geojson['type'] ?? '') === 'FeatureCollection', 'GeoJSON der Etage');
        $typen = array_count_values(array_map(static fn ($f) => $f['geometry']['type'], $geojson['features'] ?? []));
        pruefe(($typen['Point'] ?? 0) > 0 && ($typen['LineString'] ?? 0) > 0, 'GeoJSON: Anlagen als Punkte, Leitungen als Linien');
        $koord = $geojson['features'][0]['geometry']['coordinates'] ?? [0, 0];
        pruefe(abs($koord[0]) <= 180 && abs($koord[1]) <= 90 && abs($koord[1]) > 1, 'GeoJSON: Länge, Breite in Grad');
        $stand = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . $geoEtage . '/stand')['text'], true);
        pruefe(isset($stand['geo']['a']['lat'], $stand['geo']['b']['lon']), 'Stand der Karte enthält die Georeferenz');
        $mitLage = (int) Database::scalar('SELECT id FROM objekte WHERE floor_id = ? AND plan_x IS NOT NULL ORDER BY id LIMIT 1', [$geoEtage]);
        $o = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/objekt/' . $mitLage)['text'], true);
        pruefe(isset($o['geo']['lat'], $o['geo']['link']) && str_contains($o['geo']['link'], 'openstreetmap.org'), 'Anlagenfenster mit Lage und Kartenlink');
        $vorher = Database::first('SELECT geo_a_lat, geo_b_lat FROM floors WHERE id = ?', [$geoEtage]);
        $r = json_decode(aufruf($basis, $admin['dose'], 'POST', '/karte/georeferenz', ['floor_id' => $geoEtage, 'ax' => 10, 'ay' => 10, 'bx' => 50, 'by' => 50,
            'a_geo' => 'irgendwo', 'b_geo' => '51.44, 7.56', 'verhaeltnis' => 0.625, '_token' => $admin['token']], ['Accept: application/json'])['text'], true);
        pruefe(($r['ok'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'Punkt 1'), 'unlesbare Koordinaten abgelehnt');
        gleich(Database::first('SELECT geo_a_lat, geo_b_lat FROM floors WHERE id = ?', [$geoEtage]), $vorher, 'Georeferenz unverändert');
    }

    // Inventar: anlegen, einsetzen, abbauen, löschen – über die Formulare
    $verteilung = (int) Database::scalar("SELECT id FROM objekte WHERE art = 'verteilung' AND ist_vorlage = 0 AND site_id IS NOT NULL ORDER BY id LIMIT 1");
    if ($verteilung > 0) {
        $r = aufruf($basis, $admin['dose'], 'POST', '/inventar/speichern', ['id' => '', 'art' => 'zwischenstecker', 'kennung' => $kennung . '-ZS',
            'steckertyp_id' => (int) Database::scalar("SELECT id FROM steckertypen WHERE schluessel = 'cee32'"),
            'geraet[]' => $kennung . '-shelly', 'kanal[]' => 'em:0', 'stelle[]' => 'durchgang', 'bezeichnung[]' => '', '_token' => $admin['token']]);
        $stueck = (int) Database::scalar('SELECT id FROM inventar WHERE kennung = ?', [$kennung . '-ZS']);
        pruefe($r['status'] === 302 && $r['ziel'] === '/inventar/' . $stueck, 'Zwischenstecker per Formular angelegt → ' . $r['ziel']);
        $r = json_decode(aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $verteilung . '/einsetzen', ['inventar_id' => $stueck, 'anschluss_id' => 0,
            '_token' => $admin['token']], ['Accept: application/json'])['text'], true);
        pruefe(($r['ok'] ?? false) === true, 'eingesetzt: ' . ($r['message'] ?? ''));
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $verteilung . '?reiter=messung');
        pruefe(str_contains($seite['text'], $kennung . '-ZS') && str_contains($seite['text'], 'Abbauen') && phpMeldung($seite['text']) === null, 'Anlage zeigt den Einsatz mit „Abbauen“');
        $liste = aufruf($basis, $admin['dose'], 'GET', '/inventar');
        pruefe(str_contains($liste['text'], $kennung . '-ZS') && str_contains($liste['text'], 'im Einsatz'), 'Inventarliste: im Einsatz');
        $einsatz = (int) Database::scalar('SELECT id FROM einsaetze WHERE inventar_id = ? AND bis IS NULL', [$stueck]);
        $r = aufruf($basis, $admin['dose'], 'POST', '/einsatz/' . $einsatz . '/beenden', ['zurueck' => 'inventar', '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && $r['ziel'] === '/inventar/' . $stueck && Database::scalar('SELECT bis FROM einsaetze WHERE id = ?', [$einsatz]) !== null, 'abgebaut');
        $r = aufruf($basis, $admin['dose'], 'POST', '/inventar/' . $stueck . '/loeschen', ['_token' => $admin['token']]);
        pruefe($r['status'] === 302 && Database::scalar('SELECT id FROM inventar WHERE id = ?', [$stueck]) === false, 'Stück gelöscht');
    }

    // Aufbau: Position über die Karte bestätigen (JSON) und zurücknehmen
    $aufbau = Database::first("SELECT * FROM aufbauten WHERE status <> 'beendet' ORDER BY id LIMIT 1");
    if ($aufbau !== null) {
        $stand = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/' . (int) Database::scalar('SELECT id FROM floors WHERE site_id = ? ORDER BY id LIMIT 1', [(int) $aufbau['site_id']]) . '/stand')['text'], true);
        pruefe(isset($stand['aufbau']['posten'], $stand['aufbau']['zaehler']), 'Stand der Karte enthält den laufenden Aufbau');
        $posten = Database::first("SELECT * FROM aufbau_posten WHERE aufbau_id = ? AND art = 'objekt' ORDER BY id LIMIT 1", [(int) $aufbau['id']]);
        if ($posten !== null) {
            $r = json_decode(aufruf($basis, $admin['dose'], 'POST', '/aufbau/' . (int) $aufbau['id'] . '/posten', ['art' => 'objekt', 'ref_id' => (int) $posten['ref_id'],
                'status' => 'geaendert', 'notiz' => $kennung, '_token' => $admin['token']], ['Accept: application/json'])['text'], true);
            pruefe(($r['ok'] ?? false) && ($r['aufbau']['posten']['o:' . (int) $posten['ref_id']] ?? '') === 'geaendert', 'Position per Karte geändert');
            $r = aufruf($basis, $admin['dose'], 'POST', '/aufbau/' . (int) $aufbau['id'] . '/posten', ['art' => 'objekt', 'ref_id' => (int) $posten['ref_id'],
                'status' => $posten['status'], 'notiz' => $posten['notiz'], 'laenge_ist' => '', 'zeigen' => 'offen', '_token' => $admin['token']]);
            pruefe($r['status'] === 302 && $r['ziel'] === '/aufbau/' . (int) $aufbau['id'] . '?zeigen=offen', 'Formular: zurück zur Liste mit Filter');
            gleich(Database::scalar('SELECT status FROM aufbau_posten WHERE aufbau_id = ? AND art = ? AND ref_id = ?', [(int) $aufbau['id'], 'objekt', (int) $posten['ref_id']]),
                $posten['status'], 'Stand wiederhergestellt');
        }
        $r = aufruf($basis, $admin['dose'], 'POST', '/aufbau/starten', ['site_id' => (int) $aufbau['site_id'], 'name' => $kennung, '_token' => $admin['token']]);
        pruefe($r['status'] === 302 && $r['ziel'] === '/aufbau' && Database::scalar('SELECT id FROM aufbauten WHERE name = ?', [$kennung]) === false,
            'zweiter Aufbau auf demselben Standort abgelehnt');
    }

    // Bereich anlegen, ansehen, exportieren, löschen
    $site = (int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [$etage]);
    $mitglied = (int) Database::scalar('SELECT id FROM objekte WHERE site_id = ? AND ist_vorlage = 0 ORDER BY id LIMIT 1', [$site]);
    if ($site > 0 && $mitglied > 0) {
        $r = aufruf($basis, $admin['dose'], 'POST', '/bereiche/speichern', ['id' => '', 'site_id' => $site, 'name' => $kennung . '-bereich',
            'farbe' => '#123456', 'grenze_kw' => '50', 'grenze_a' => '', 'notizen' => '', 'mitglieder[]' => 'o:' . $mitglied, '_token' => $admin['token']]);
        $bereichId = (int) Database::scalar('SELECT id FROM bereiche WHERE name = ?', [$kennung . '-bereich']);
        pruefe($r['status'] === 302 && $r['ziel'] === '/bereiche/' . $bereichId, 'Bereich per Formular angelegt → ' . $r['status'] . ' ' . $r['ziel']);
        gleich(BereichService::mitgliedSchluessel($bereichId), ['o:' . $mitglied], 'Mitglied aus dem Formular gespeichert');
        $seite = aufruf($basis, $admin['dose'], 'GET', '/bereiche/' . $bereichId);
        pruefe($seite['status'] === 200 && str_contains($seite['text'], 'data-mitglied="o:' . $mitglied . '"') && phpMeldung($seite['text']) === null, 'Bereichsseite zeigt das Mitglied');
        $live = json_decode(aufruf($basis, $admin['dose'], 'GET', '/bereiche/live?id=' . $bereichId)['text'], true);
        pruefe(isset($live['bereiche'][$bereichId]['mitglieder']['o:' . $mitglied]) && count($live['bereiche']) === 1, 'Livewerte nur des gefragten Bereichs');
        $daten = json_decode(aufruf($basis, $admin['dose'], 'GET', '/bereiche/' . $bereichId . '/daten?feld=p&zeitraum=6h')['text'], true);
        pruefe(is_array($daten) && ($daten['feld'] ?? '') === 'p' && isset($daten['zeiten'], $daten['serien']), 'Summenverlauf als JSON');
        $csv = aufruf($basis, $admin['dose'], 'GET', '/bereiche/' . $bereichId . '/csv?zeitraum=6h');
        pruefe($csv['status'] === 200 && str_contains($csv['kopf'], 'text/csv') && str_contains($csv['text'], 'zeit'), 'Bereich als CSV');
        $druck = aufruf($basis, $admin['dose'], 'GET', '/bereiche/' . $bereichId . '/material');
        pruefe($druck['status'] === 200 && str_contains($druck['text'], 'Material') && phpMeldung($druck['text']) === null, 'Materialliste zum Drucken');
        $r = aufruf($basis, $admin['dose'], 'POST', '/bereiche/' . $bereichId . '/loeschen', ['_token' => $admin['token']]);
        pruefe($r['status'] === 302 && BereichService::find($bereichId) === null, 'Bereich gelöscht');
        gleich(aufruf($basis, $admin['dose'], 'GET', '/bereiche/' . $bereichId)['status'], 404, 'gelöschter Bereich → 404');
    }

    // Katalog importieren: Seite, Vorschau nach Upload, Übernehmen nur mit
    // CSRF-Token und nur das Angehakte, Eigenschaften im Formular, kein JSON
    $importTyp = static fn (string $schluessel, string $name, array $eigenschaften, string $abgang = 'schuko', array $stecker = []): array => ['format' => 1, 'art' => 'verteiler-typ',
        'schluessel' => $schluessel, 'typ' => ['art' => 'verteilung', 'fest' => false, 'name' => $name, 'modell' => 'Web', 'eigenschaften' => $eigenschaften,
            'schutzorgane' => [['ref' => 's1', 'art' => 'hauptschalter', 'bezeichnung' => 'Q1', 'nennstrom_a' => 32, 'pole' => 3]],
            'anschluesse' => [['richtung' => 'eingang', 'stecker' => 'cee32', 'nr' => 1], ['richtung' => 'abgang', 'stecker' => $abgang, 'nr' => 1, 'schutz' => 's1']]],
        'steckertypen' => $stecker];
    // Gewicht mit drei Nachkommastellen: das Formular darf es nicht runden.
    $importPaket = (string) json_encode(['format' => 1, 'art' => 'verteiler-typen', 'quelle' => $kennung . ' Webkatalog', 'typen' => [
        $importTyp($kennung . '-web-a', $kennung . ' Web A', ['artikelnummer' => 'W-1', 'gewicht_kg' => 12.125, 'zuleitung' => '2,0 m 5G2,5']),
        $importTyp($kennung . '-web-b', $kennung . ' Web B', [])]], JSON_UNESCAPED_UNICODE);
    $importId = static fn (string $schluessel): int => (int) Database::scalar('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$schluessel]);
    pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog')['text'], 'href="/anlagen/katalog/import"'), 'Katalog: Knopf „Importieren …“');
    $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import');
    pruefe($seite['status'] === 200 && str_contains($seite['text'], 'enctype="multipart/form-data"') && phpMeldung($seite['text']) === null, 'Import-Seite');
    $r = hochladen($basis, $admin['dose'], '/anlagen/katalog/import/vorschau', ['_token' => $admin['token']], 'datei', $importPaket, 'web.json');
    pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/katalog/import?vorschau=1', 'Upload → Vorschau (' . $r['status'] . ' ' . $r['ziel'] . ')');
    $vorschau = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import?vorschau=1');
    preg_match('/name="kennung" value="([0-9a-f]{32})"/', $vorschau['text'], $m);
    $importKennung = $m[1] ?? '';
    pruefe($vorschau['status'] === 200 && $importKennung !== '' && substr_count($vorschau['text'], 'name="neu[]"') === 2
        && preg_match_all('/name="neu\[\]"[^>]*\bchecked\b/', $vorschau['text']) === 2
        && str_contains($vorschau['text'], 'data-label="Zustand"') && str_contains($vorschau['text'], $kennung . ' Webkatalog')
        && str_contains($vorschau['text'], 'data-auswahl-an=') && phpMeldung($vorschau['text']) === null, 'Vorschau: zwei neue Typen, vorausgewählt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $importKennung, 'neu' => [$kennung . '-web-a']]);
    pruefe($r['status'] === 302 && $importId($kennung . '-web-a') === 0, 'Übernehmen ohne CSRF-Token: nichts angelegt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => str_repeat('a', 32), 'neu' => [$kennung . '-web-a'], '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/katalog/import' && $importId($kennung . '-web-a') === 0, 'fremde Vorschau-Kennung: nichts angelegt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $importKennung, '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/katalog/import?vorschau=1', 'nichts angehakt: zurück zur Vorschau');
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $importKennung, 'neu' => [$kennung . '-web-a'], '_token' => $admin['token']]);
    $webA = $importId($kennung . '-web-a');
    pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/katalog' && $webA > 0 && $importId($kennung . '-web-b') === 0, 'übernommen: nur der angehakte Typ');
    $katalog = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog');
    pruefe(str_contains($katalog['text'], '1 neu angelegt (' . $kennung . ' Web A)') && str_contains($katalog['text'], '>importiert</span>')
        && phpMeldung($katalog['text']) === null, 'Katalog: Meldung mit Namen, Eintrag markiert');
    pruefe(aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $importKennung, 'neu' => [$kennung . '-web-b'],
        '_token' => $admin['token']])['ziel'] === '/anlagen/katalog/import' && $importId($kennung . '-web-b') === 0, 'Vorschau nach dem Übernehmen verbraucht');
    $form = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $webA . '/bearbeiten');
    pruefe(str_contains($form['text'], 'name="eigenschaften[artikelnummer]" value="W-1"') && str_contains($form['text'], 'name="eigenschaften[gewicht_kg]" value="12,125"')
        && str_contains($form['text'], 'Gewicht (kg)'), 'Formular zeigt die importierten Eigenschaften, ungerundet');
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/speichern', ['id' => $webA, 'art' => 'verteilung', 'name' => $kennung . ' Web A (korrigiert)', 'modell' => 'Web',
        'spannung_v' => 400, 'phasen' => 3, 'status' => 'geplant', 'fest' => 0, 'eigenschaften' => ['artikelnummer' => 'W-1', 'gewicht_kg' => '13', 'zuleitung' => '2,0 m 5G2,5'],
        '_token' => $admin['token']]);
    $gespeichert = \App\Services\AnlagenService::find($webA);
    gleich([$r['status'], $gespeichert['name'], (int) $gespeichert['ist_vorlage'], $gespeichert['eigenschaften']],
        [302, $kennung . ' Web A (korrigiert)', 1, ['artikelnummer' => 'W-1', 'zuleitung' => '2,0 m 5G2,5', 'gewicht_kg' => 13.0]], 'importierter Typ per Formular korrigiert, Eigenschaften bleiben');
    $r = hochladen($basis, $admin['dose'], '/anlagen/katalog/import/vorschau', ['_token' => $admin['token']], 'datei', $importPaket, 'web.json');
    $vorschau = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import?vorschau=1');
    pruefe(substr_count($vorschau['text'], 'name="ueberschreiben[]"') === 1 && substr_count($vorschau['text'], 'name="neu[]"') === 1
        && str_contains($vorschau['text'], 'Unterschiede: Name, Eigenschaften') && !preg_match('/name="ueberschreiben\[\]"[^>]*checked/', $vorschau['text']),
        'erneut: korrigierter Typ „hier anders“, nicht vorausgewählt');
    // „überschreiben“ gilt dem Stand, den die Vorschau zeigt: wer danach
    // korrigiert, behält seine Korrektur, und die Vorschau bleibt offen.
    $ueberWert = static function (string $html) use ($kennung): string {
        preg_match('/name="ueberschreiben\[\]" value="([^"]+)"/', $html, $m);
        return html_entity_decode($m[1] ?? '');
    };
    $kennungAus = static function (string $html): string {
        preg_match('/name="kennung" value="([0-9a-f]{32})"/', $html, $m);
        return $m[1] ?? '';
    };
    $wert = $ueberWert($vorschau['text']);
    pruefe(preg_match('/^' . preg_quote($kennung . '-web-a', '/') . ':[0-9a-f]{64}$/', $wert) === 1, 'überschreiben trägt den Stand der Vorschau: ' . $wert);
    Database::execute('UPDATE objekte SET notizen = ? WHERE id = ?', ['nach der Vorschau korrigiert', $webA]);
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $kennungAus($vorschau['text']), 'ueberschreiben' => [$wert],
        '_token' => $admin['token']]);
    $vorschau = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import?vorschau=1');
    pruefe($r['ziel'] === '/anlagen/katalog/import?vorschau=1' && (string) Database::scalar('SELECT notizen FROM objekte WHERE id = ?', [$webA]) === 'nach der Vorschau korrigiert'
        && str_contains($vorschau['text'], 'class="flash warning"') && str_contains($vorschau['text'], 'seit der Vorschau hier geändert, bleibt (' . $kennung . ' Web A)')
        && str_contains($vorschau['text'], 'name="ueberschreiben[]"'), 'Korrektur nach der Vorschau bleibt; Warnung, Vorschau bleibt offen');
    pruefe($ueberWert($vorschau['text']) !== $wert, 'die Vorschau zeigt den Stand von jetzt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $kennungAus($vorschau['text']),
        'ueberschreiben' => [$ueberWert($vorschau['text'])], '_token' => $admin['token']]);
    pruefe($r['ziel'] === '/anlagen/katalog' && (string) Database::scalar('SELECT notizen FROM objekte WHERE id = ?', [$webA]) === ''
        && (string) Database::scalar('SELECT name FROM objekte WHERE id = ?', [$webA]) === $kennung . ' Web A', 'mit dem Stand von jetzt überschrieben');
    $r = hochladen($basis, $admin['dose'], '/anlagen/katalog/import/vorschau', ['_token' => $admin['token']], 'datei', $importPaket, 'web.json');
    $vorschau = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import?vorschau=1');
    aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/verwerfen', ['_token' => $admin['token']]);
    pruefe(!str_contains(aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import')['text'], 'Zur Vorschau'), 'Vorschau verworfen');
    $r = hochladen($basis, $admin['dose'], '/anlagen/katalog/import/vorschau', ['_token' => $admin['token']], 'datei', 'das ist kein JSON', 'kaputt.json');
    $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import');
    pruefe($r['status'] === 302 && $r['ziel'] === '/anlagen/katalog/import' && str_contains($seite['text'], 'kaputt.json: Die Datei ist kein gültiges JSON'), 'Upload kein JSON → Meldung');
    $r = hochladen($basis, $admin['dose'], '/anlagen/katalog/import/vorschau', [], 'datei', $importPaket, 'web.json');
    pruefe($r['status'] === 302 && !str_contains((string) $r['ziel'], 'vorschau=1'), 'Upload ohne CSRF-Token: keine Vorschau');
    aufruf($basis, $admin['dose'], 'POST', '/anlagen/katalog/import/vorschau', ['mitgeliefert' => '../../config/config.php', '_token' => $admin['token']]);
    pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/anlagen/katalog/import')['text'], 'Diesen mitgelieferten Katalog gibt es nicht'), 'mitgelieferter Katalog nur aus der Liste, kein Pfad');

    // Ohne „Steckverbinder-Katalog pflegen“: ein Typ mit neuem Stecker steht
    // ohne Haken da und sagt, warum; die übrigen lassen sich übernehmen.
    $importeur = anmelden($basis, $kennung . '-import', $kennwort);
    $dosen[] = $importeur['dose'];
    $steckerPaket = (string) json_encode(['format' => 1, 'art' => 'verteiler-typen', 'quelle' => $kennung . ' Steckerkatalog', 'typen' => [
        $importTyp($kennung . '-web-c', $kennung . ' Web C', [], $kennung . '-harting',
            [['schluessel' => $kennung . '-harting', 'name' => $kennung . ' Harting 16 A', 'strom_a' => 16, 'phasen' => 3, 'spannung_v' => 400]]),
        $importTyp($kennung . '-web-d', $kennung . ' Web D', [])]], JSON_UNESCAPED_UNICODE);
    hochladen($basis, $importeur['dose'], '/anlagen/katalog/import/vorschau', ['_token' => $importeur['token']], 'datei', $steckerPaket, 'stecker.json');
    $vorschau = aufruf($basis, $importeur['dose'], 'GET', '/anlagen/katalog/import?vorschau=1');
    pruefe($vorschau['status'] === 200 && substr_count($vorschau['text'], 'name="neu[]"') === 1
        && str_contains($vorschau['text'], 'value="' . $kennung . '-web-d" data-zustand="neu" checked')
        && str_contains($vorschau['text'], 'disabled data-zustand="neu-stecker"')
        && str_contains($vorschau['text'], 'braucht den neuen Steckertyp „' . $kennung . ' Harting 16 A“')
        && str_contains($vorschau['text'], $kennung . ' Harting 16 A (16 A, 3-phasig, 400 V) für ' . $kennung . ' Web C')
        && phpMeldung($vorschau['text']) === null, 'ohne Stecker-Recht: Typ mit neuem Stecker ohne Haken, mit Grund; Hinweis nennt Werte und Typ');
    $r = aufruf($basis, $importeur['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $kennungAus($vorschau['text']),
        'neu' => [$kennung . '-web-c', $kennung . '-web-d'], '_token' => $importeur['token']]);
    $vorschau = aufruf($basis, $importeur['dose'], 'GET', '/anlagen/katalog/import?vorschau=1');
    pruefe($r['ziel'] === '/anlagen/katalog/import?vorschau=1' && $importId($kennung . '-web-d') === 0
        && str_contains($vorschau['text'], 'gebraucht von ' . $kennung . ' Web C'), 'trotzdem angehakt: nichts übernommen, die Meldung nennt den Typ');
    $r = aufruf($basis, $importeur['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $kennungAus($vorschau['text']),
        'neu' => [$kennung . '-web-d'], '_token' => $importeur['token']]);
    pruefe($r['ziel'] === '/anlagen/katalog' && $importId($kennung . '-web-d') > 0 && $importId($kennung . '-web-c') === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM steckertypen WHERE schluessel = ?', [$kennung . '-harting']) === 0, 'die übrigen übernommen, kein Steckertyp angelegt');

    // POST ohne gültiges Token wird nicht ausgeführt
    $vorher = (int) Database::scalar('SELECT COUNT(*) FROM steckertypen');
    $r = aufruf($basis, $admin['dose'], 'POST', '/stecker/speichern', ['name' => $kennung, '_token' => 'falsch']);
    pruefe($r['status'] === 302, 'POST mit falschem Token → Weiterleitung');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM steckertypen'), $vorher, 'POST mit falschem Token ändert nichts');
    // Wer JSON erwartet (Autosave, PM.post), bekommt 419 bzw. 401 als JSON statt Weiterleitung.
    $r = aufruf($basis, $admin['dose'], 'POST', '/stecker/speichern', ['name' => $kennung, '_token' => 'falsch'], ['Accept: application/json']);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 419 && ($j['token'] ?? false) === true && ($j['ok'] ?? true) === false && str_contains((string) ($j['message'] ?? ''), 'Seite neu laden'),
        'falsches Token mit Accept JSON → 419 JSON');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM steckertypen'), $vorher, '419 ändert nichts');
    $r = aufruf($basis, $leer, 'POST', '/stecker/speichern', ['name' => $kennung], ['Accept: application/json']);
    $j = json_decode($r['text'], true);
    pruefe($r['status'] === 401 && ($j['anmelden'] ?? false) === true && str_contains((string) ($j['message'] ?? ''), 'neu anmelden'), 'ohne Anmeldung mit Accept JSON → 401 JSON');
    $r = aufruf($basis, $leer, 'GET', '/dashboard/live', [], ['Accept: application/json']);
    gleich($r['status'], 401, 'Livewerte ohne Anmeldung → 401');
    $r = aufruf($basis, $leer, 'POST', '/stecker/speichern', ['name' => $kennung], ['X-Requested-With: XMLHttpRequest']);
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'nur X-Requested-With → wie bisher Weiterleitung');

    // ---------------------------------------------------- Schnittstelle (API)
    alsBenutzer($benutzer['admin']);
    $zugang = \App\Services\ApiZugaenge::anlegen(['name' => $kennung . ' Zugang', 'befugnisse' => ['plaene.lesen']]);
    alsBenutzer(null);
    pruefe($zugang['ok'], 'Zugang für die Schnittstelle angelegt');
    $zugangId = (int) ($zugang['id'] ?? 0);
    foreach (['/system/schnittstelle', '/system/schnittstelle/' . $zugangId] as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200, 'Admin ' . $pfad . ' → ' . $r['status']);
        pruefe(phpMeldung($r['text']) === null, 'Admin ' . $pfad . ' ohne PHP-Meldung ' . (string) phpMeldung($r['text']));
    }
    gleich(aufruf($basis, $admin['dose'], 'GET', '/system/schnittstelle/999999999')['status'], 404, 'unbekannter Zugang → 404');
    gleich(aufruf($basis, $admin['dose'], 'GET', '/system/schnittstelle/anlegen')['status'], 404, '/system/schnittstelle/anlegen per GET → 404');
    $tokensVorher = (int) Database::scalar('SELECT COUNT(*) FROM api_tokens');
    foreach ([
        '/system/schnittstelle/anlegen' => ['name' => $kennung . ' ohne csrf', 'befugnisse' => ['plaene.lesen']],
        '/system/schnittstelle/' . $zugangId . '/speichern' => ['name' => $kennung . ' gekapert', 'befugnisse' => ['plaene.lesen']],
        '/system/schnittstelle/' . $zugangId . '/anzeigen' => ['kennwort' => $kennwort],
        '/system/schnittstelle/' . $zugangId . '/widerrufen' => [],
        '/system/schnittstelle/' . $zugangId . '/loeschen' => [],
    ] as $pfad => $felder) {
        $r = aufruf($basis, $admin['dose'], 'POST', $pfad, $felder);
        pruefe($r['status'] === 302 && !str_contains($r['text'], 'mtma_'), 'Schnittstelle ' . $pfad . ' ohne CSRF-Token → abgelehnt');
    }
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM api_tokens'), Database::first('SELECT name, revoked_at FROM api_tokens WHERE id = ?', [$zugangId])],
        [$tokensVorher, ['name' => $kennung . ' Zugang', 'revoked_at' => null]], 'ohne CSRF-Token ändert sich an den Zugängen nichts');
    // Die Ablehnung ohne CSRF-Token verbraucht die Einmal-Marke nicht: dieselbe
    // Marke mit Token legt den Zugang an – ohne Token entsteht keiner.
    $seite = aufruf($basis, $admin['dose'], 'GET', '/system/schnittstelle');
    preg_match('/name="einmal" value="([0-9a-f]+)"/', $seite['text'], $m);
    $einmal = $m[1] ?? '';
    $felder = ['name' => $kennung . ' mit Marke', 'befugnisse' => ['plaene.lesen'], 'einmal' => $einmal];
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/schnittstelle/anlegen', $felder);
    pruefe($einmal !== '' && $r['status'] === 302 && !str_contains($r['text'], 'mtma_')
        && (int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name = ?', [$kennung . ' mit Marke']) === 0,
        'anlegen mit gültiger Einmal-Marke, aber ohne CSRF-Token → kein Zugang');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/schnittstelle/anlegen', $felder + ['_token' => $admin['token']]);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'mtma_') && (int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name = ?', [$kennung . ' mit Marke']) === 1,
        '… mit CSRF-Token legt dieselbe Marke ihn an');
    alsBenutzer($benutzer['admin']);
    $widerrufen = \App\Services\ApiZugaenge::anlegen(['name' => $kennung . ' widerrufen', 'befugnisse' => ['plaene.lesen']]);
    \App\Services\ApiZugaenge::widerrufen((int) $widerrufen['id']);
    alsBenutzer(null);
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . (int) $widerrufen['id'] . '/loeschen', []);
    pruefe($r['status'] === 302 && Database::first('SELECT id FROM api_tokens WHERE id = ?', [(int) $widerrufen['id']]) !== null,
        'einen widerrufenen Zugang ohne CSRF-Token löschen → er bleibt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . (int) $widerrufen['id'] . '/loeschen', ['_token' => $admin['token']]);
    pruefe($r['status'] === 302 && Database::first('SELECT id FROM api_tokens WHERE id = ?', [(int) $widerrufen['id']]) === null, '… mit CSRF-Token gelöscht');

    // Die Liste der Zugänge nennt nur Standorte, die man selbst sieht
    alsBenutzer($benutzer['admin']);
    $sichtbar = \App\Services\SiteService::saveSite(null, ['name' => $kennung . ' Sichtbar']);
    $unsichtbarName = $kennung . ' Unsichtbar ' . bin2hex(random_bytes(3));
    $unsichtbar = \App\Services\SiteService::saveSite(null, ['name' => $unsichtbarName]);
    $listenRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' apiliste', '', ['api.manage', 'karte.view', 'dashboard.view'])['id'];
    $benutzer['apiliste'] = BenutzerService::anlegen($kennung . '-apiliste', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$listenRolle]), false);
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['apiliste'], 'ziel_art' => 'site', 'ziel_id' => $sichtbar]);
    $zweiOrte = \App\Services\ApiZugaenge::anlegen(['name' => $kennung . ' zwei Orte', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sichtbar, $unsichtbar]]);
    alsBenutzer(null);
    pruefe($zweiOrte['ok'], 'Zugang für zwei Standorte angelegt');
    $apiliste = anmelden($basis, $kennung . '-apiliste', $kennwort);
    $dosen[] = $apiliste['dose'];
    $r = aufruf($basis, $apiliste['dose'], 'GET', '/system/schnittstelle');
    pruefe($r['status'] === 200 && str_contains($r['text'], htmlspecialchars($kennung . ' Sichtbar')) && !str_contains($r['text'], $unsichtbarName)
        && str_contains($r['text'], '1 weiterer, den du nicht siehst'), 'api.manage mit Freigabe nur für einen Standort: der andere nur als Anzahl');
    $r = aufruf($basis, $admin['dose'], 'GET', '/system/schnittstelle');
    pruefe(str_contains($r['text'], $unsichtbarName), '… der Admin sieht beide Namen');
    $r = aufruf($basis, $leer, 'GET', '/api/v1/info');
    pruefe($r['status'] === 401 && (json_decode($r['text'], true)['ok'] ?? true) === false && $r['ziel'] === null
        && stripos($r['kopf'], 'application/json') !== false && stripos($r['kopf'], 'Set-Cookie') === false, '/api/v1/info ohne Token → 401 JSON, keine Anmeldeseite, kein Cookie');
    $r = aufruf($basis, $admin['dose'], 'GET', '/api/v1/info');
    gleich($r['status'], 401, '/api/v1/info mit Sitzung, aber ohne Token → 401 (die Sitzung zählt dort nicht)');

    // ---------------------------------------------------- Andere Instanzen
    // Diese Instanz als ihre eigene Gegenseite (über die Loopback-Adresse) –
    // mit einem eigenen Standort, damit kein Bestand einen Schlüssel bekommt.
    alsBenutzer($benutzer['admin']);
    $instanzSite = \App\Services\SiteService::saveSite(null, ['name' => $kennung . ' Instanz <b>']);
    \App\Services\SiteService::saveFloor(null, ['site_id' => $instanzSite, 'name' => 'Halle']);
    $selbstZugang = \App\Services\ApiZugaenge::anlegen(['name' => $kennung . ' selbst', 'befugnisse' => ['plaene.lesen', 'feststrom'], 'sehen' => [$instanzSite]]);
    $selbst = \App\Services\PartnerService::anlegen($kennung . ' selbst', $basis . '/api/v1', (string) ($selbstZugang['token'] ?? ''));
    alsBenutzer(null);
    pruefe($selbst['ok'], 'diese Instanz als andere eingetragen: ' . $selbst['message']);
    $selbstId = (int) ($selbst['id'] ?? 0);
    $instanzKey = \App\Services\LocationBundle::schluesselSichern('sites', $instanzSite, $kennung . ' Instanz');
    foreach (['/instanzen', '/instanzen/' . $selbstId, '/instanzen/' . $selbstId . '/zuordnen?schluessel=' . rawurlencode($instanzKey)] as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200, 'Admin ' . $pfad . ' → ' . $r['status']);
        pruefe(phpMeldung($r['text']) === null, 'Admin ' . $pfad . ' ohne PHP-Meldung ' . (string) phpMeldung($r['text']));
    }
    $r = aufruf($basis, $admin['dose'], 'GET', '/instanzen/' . $selbstId);
    pruefe(str_contains($r['text'], htmlspecialchars($kennung . ' Instanz <b>')) && str_contains($r['text'], 'passt zu') && !str_contains($r['text'], (string) ($selbstZugang['token'] ?? 'x')),
        'Instanzseite: die Location dort (maskiert), „passt zu“ (gleicher Schlüssel), kein Token');
    foreach (['/instanzen/999999999', '/instanzen/x', '/instanzen/' . $selbstId . '/zuordnen?schluessel=gibt-es-nicht'] as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe(in_array($r['status'], [302, 404], true) && phpMeldung($r['text']) === null, 'Admin ' . $pfad . ' → ' . $r['status']);
    }
    $vorher = [(int) Database::scalar('SELECT COUNT(*) FROM api_partner'), Database::first('SELECT partner_id FROM sites WHERE id = ?', [$instanzSite])];
    foreach (['/instanzen/anlegen' => ['adresse' => $basis, 'token' => (string) ($selbstZugang['token'] ?? '')], '/instanzen/' . $selbstId . '/pruefen' => [],
        '/instanzen/' . $selbstId . '/loeschen' => [], '/instanzen/' . $selbstId . '/uebernehmen' => ['schluessel' => $instanzKey, 'aktion' => 'verknuepfen',
        'ziel' => (string) $instanzSite, 'etagen' => []], '/instanzen/' . $selbstId . '/loesen' => ['site_id' => $instanzSite],
        '/instanzen/' . $selbstId . '/zurueck' => ['site_id' => $instanzSite]] as $pfad => $felder) {
        $r = aufruf($basis, $admin['dose'], 'POST', $pfad, $felder);
        pruefe($r['status'] === 302 && $r['ziel'] !== '/instanzen/' . $selbstId, 'Andere Instanzen ' . $pfad . ' ohne CSRF-Token → abgelehnt');
    }
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM api_partner'), Database::first('SELECT partner_id FROM sites WHERE id = ?', [$instanzSite])], $vorher,
        'ohne CSRF-Token weder eingetragen noch ausgetragen noch verknüpft');

    // ---------------------------------------------------- Änderungsvorschläge
    // Ein Vorschlag, wie ihn die Schnittstelle ablegt – mit Text, der maskiert ankommen muss.
    $instanzEtage = (int) Database::scalar('SELECT id FROM floors WHERE site_id = ? ORDER BY id LIMIT 1', [$instanzSite]);
    $instanzEtageKey = \App\Services\LocationBundle::schluesselSichern('floors', $instanzEtage, 'Halle');
    $vorschlagId = Database::insert('api_vorschlaege', [
        'token_id' => $zugangId, 'token_name' => $kennung . ' Zugang', 'absender' => $kennung . ' <i>Absender</i>', 'site_id' => $instanzSite,
        'site_key' => $instanzKey, 'daten' => json_encode(['location' => ['format' => 1, 'art' => 'location', 'schluessel' => $instanzKey, 'name' => 'dort',
            'etagen' => [['schluessel' => $instanzEtageKey, 'name' => '<script>x</script>', 'ebene' => 0, 'reihenfolge' => 0, 'massstab' => '1:250', 'plan' => null]]]]),
        'dateien' => '{}', 'notiz' => '<b>Notiz</b>', 'status' => 'offen', 'created_at' => gmdate('c'),
    ]);
    foreach (['/vorschlaege', '/vorschlaege/' . $vorschlagId] as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200, 'Admin ' . $pfad . ' → ' . $r['status']);
        pruefe(phpMeldung($r['text']) === null, 'Admin ' . $pfad . ' ohne PHP-Meldung ' . (string) phpMeldung($r['text']));
        pruefe(!str_contains($r['text'], '<i>Absender</i>') && !str_contains($r['text'], '<b>Notiz</b>') && !str_contains($r['text'], '<script>x'),
            'Admin ' . $pfad . ': Text von dort maskiert');
    }
    gleich(aufruf($basis, $admin['dose'], 'GET', '/vorschlaege/999999999')['status'], 404, 'unbekannter Vorschlag → 404');
    foreach (['/vorschlaege/' . $vorschlagId . '/annehmen' => ['etagen' => [$instanzEtageKey], 'teile' => ['grundriss']],
        '/vorschlaege/' . $vorschlagId . '/ablehnen' => ['grund' => 'ohne CSRF']] as $pfad => $felder) {
        $r = aufruf($basis, $admin['dose'], 'POST', $pfad, $felder);
        pruefe($r['status'] === 302 && $r['ziel'] !== '/vorschlaege/' . $vorschlagId, 'Vorschläge ' . $pfad . ' ohne CSRF-Token → abgelehnt');
    }
    gleich([Database::scalar('SELECT status FROM api_vorschlaege WHERE id = ?', [$vorschlagId]), Database::scalar('SELECT scale_note FROM floors WHERE id = ?', [$instanzEtage])],
        ['offen', ''], 'ohne CSRF-Token weder angenommen noch abgelehnt');

    // ---------------------------------------------------- Kunde ohne Freigabe
    $kunde = anmelden($basis, $kennung . '-kunde', $kennwort);
    $dosen[] = $kunde['dose'];
    foreach (['/datenquellen', '/system/benutzer', '/system/einstellungen', '/audit', '/stecker', '/standorte', '/anlagen/neu', '/steuerung/protokoll', '/bereiche/neu',
        '/inventar', '/inventar/neu', '/verteiler-abgleich', '/location', '/anlagen/katalog/import', '/system/schnittstelle', '/system/schnittstelle/' . $zugangId] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ' . $pfad);
    }
    if (($tankId ?? 0) > 0) {
        $r = aufruf($basis, $kunde['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf');
        pruefe($r['status'] === 404 && (json_decode($r['text'], true)['ok'] ?? true) === false, 'Kunde ohne Freigabe: fremder Tank, Verlauf → 404');
        gleich(aufruf($basis, $kunde['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv')['status'], 404, 'Kunde ohne Freigabe: fremder Tank, CSV → 404');
        $r = aufruf($basis, $kunde['dose'], 'GET', '/anlagen/' . $tankId . '/tank/tage');
        pruefe($r['status'] === 404 && (json_decode($r['text'], true)['ok'] ?? true) === false, 'Kunde ohne Freigabe: fremder Tank, Verbrauch je Tag → 404');
    }
    // Ohne „api.manage“ nichts an den Zugängen – auch nicht widerrufen
    foreach (['anlegen' => ['name' => $kennung . ' vom Kunden', 'befugnisse' => ['plaene.lesen']], $zugangId . '/widerrufen' => [],
        $zugangId . '/anzeigen' => ['kennwort' => $kennwort], $zugangId . '/speichern' => ['name' => $kennung . ' vom Kunden']] as $aktion => $felder) {
        $r = aufruf($basis, $kunde['dose'], 'POST', '/system/schnittstelle/' . $aktion, $felder + ['_token' => $kunde['token']]);
        pruefe($r['status'] === 403 && !str_contains($r['text'], 'mtma_'), 'Kunde: Schnittstelle ' . $aktion . ' → 403');
    }
    gleich(Database::first('SELECT name, revoked_at FROM api_tokens WHERE id = ?', [$zugangId]), ['name' => $kennung . ' Zugang', 'revoked_at' => null],
        'der Zugang bleibt, wie er war');
    // Ohne „api.partner“ keine anderen Instanzen
    foreach (['/instanzen', '/instanzen/' . $selbstId, '/instanzen/' . $selbstId . '/zuordnen?schluessel=' . rawurlencode($instanzKey)] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ' . $pfad);
    }
    foreach (['anlegen' => ['adresse' => $basis, 'token' => 'mtma_' . str_repeat('k', 40)], $selbstId . '/loeschen' => [], $selbstId . '/pruefen' => [],
        $selbstId . '/uebernehmen' => ['schluessel' => $instanzKey], $selbstId . '/loesen' => ['site_id' => $instanzSite],
        $selbstId . '/zurueck' => ['site_id' => $instanzSite, 'erzwingen' => '1']] as $aktion => $felder) {
        gleich(aufruf($basis, $kunde['dose'], 'POST', '/instanzen/' . $aktion, $felder + ['_token' => $kunde['token']])['status'], 403, 'Kunde: Instanzen ' . $aktion . ' → 403');
    }
    pruefe(\App\Services\PartnerService::find($selbstId) !== null, 'die Instanz bleibt eingetragen');
    // Ohne „api.vorschlaege“ keine Änderungsvorschläge
    foreach (['/vorschlaege', '/vorschlaege/' . $vorschlagId, '/vorschlaege/' . $vorschlagId . '/datei?pfad=plaene%2Fx.svg'] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ' . $pfad);
    }
    foreach (['annehmen' => ['etagen' => [$instanzEtageKey], 'teile' => ['grundriss']], 'ablehnen' => ['grund' => 'vom Kunden']] as $aktion => $felder) {
        gleich(aufruf($basis, $kunde['dose'], 'POST', '/vorschlaege/' . $vorschlagId . '/' . $aktion, $felder + ['_token' => $kunde['token']])['status'], 403,
            'Kunde: Vorschlag ' . $aktion . ' → 403');
    }
    gleich(Database::scalar('SELECT status FROM api_vorschlaege WHERE id = ?', [$vorschlagId]), 'offen', 'der Vorschlag bleibt offen');
    if ($etage > 0) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', '/karte/' . $etage . '/geojson')['status'], 403, 'Kunde ohne Freigabe: kein GeoJSON');
        $r = aufruf($basis, $kunde['dose'], 'POST', '/karte/georeferenz', ['floor_id' => $etage, 'entfernen' => 1, '_token' => $kunde['token']], ['Accept: application/json']);
        gleich($r['status'], 403, 'Kunde darf die Georeferenz nicht ändern');
    }
    $stueck = (int) Database::scalar('SELECT id FROM inventar ORDER BY id LIMIT 1');
    if ($stueck > 0 && $anlage > 0) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', '/inventar/' . $stueck)['status'], 403, 'Kunde sieht kein Inventarstück');
        $r = aufruf($basis, $kunde['dose'], 'POST', '/anlagen/' . $anlage . '/einsetzen', ['inventar_id' => $stueck, '_token' => $kunde['token']], ['Accept: application/json']);
        gleich($r['status'], 403, 'Kunde darf nichts einsetzen');
        $einsatz = (int) Database::scalar('SELECT id FROM einsaetze WHERE bis IS NULL ORDER BY id LIMIT 1');
        if ($einsatz > 0) {
            $r = aufruf($basis, $kunde['dose'], 'POST', '/einsatz/' . $einsatz . '/beenden', ['_token' => $kunde['token']], ['Accept: application/json']);
            pruefe($r['status'] === 403 && Database::scalar('SELECT bis FROM einsaetze WHERE id = ?', [$einsatz]) === null, 'Kunde darf nichts abbauen');
        }
    }
    $aufbauId = (int) Database::scalar('SELECT id FROM aufbauten ORDER BY id LIMIT 1');
    if ($aufbauId > 0) {
        $r = aufruf($basis, $kunde['dose'], 'GET', '/aufbau');
        pruefe($r['status'] === 200 && !str_contains($r['text'], '/aufbau/' . $aufbauId . '"'), 'Kunde sieht fremden Aufbau nicht in der Liste');
        foreach (['', '/abgleich', '/druck'] as $zusatz) {
            gleich(aufruf($basis, $kunde['dose'], 'GET', '/aufbau/' . $aufbauId . $zusatz)['status'], 404, 'Kunde /aufbau/…' . $zusatz);
        }
        foreach (['/posten', '/fertig', '/beenden', '/loeschen'] as $zusatz) {
            $r = aufruf($basis, $kunde['dose'], 'POST', '/aufbau/' . $aufbauId . $zusatz, ['art' => 'objekt', 'ref_id' => 1, 'status' => 'entfallen', '_token' => $kunde['token']]);
            gleich($r['status'], 403, 'Kunde darf nicht ' . $zusatz);
        }
    }
    // Bereiche eines fremden Standorts: nicht in der Liste, nicht aufrufbar
    $fremderBereich = (int) Database::scalar('SELECT id FROM bereiche ORDER BY id LIMIT 1');
    if ($fremderBereich > 0) {
        $r = aufruf($basis, $kunde['dose'], 'GET', '/bereiche');
        pruefe($r['status'] === 200 && !str_contains($r['text'], 'data-bereich-id="' . $fremderBereich . '"'), 'Kunde sieht fremden Bereich nicht in der Liste');
        foreach (['', '/material', '/daten?feld=p'] as $zusatz) {
            $r = aufruf($basis, $kunde['dose'], 'GET', '/bereiche/' . $fremderBereich . $zusatz);
            pruefe(in_array($r['status'], [403, 404], true), 'Kunde /bereiche/…' . $zusatz . ' → ' . $r['status']);
        }
        $live = json_decode(aufruf($basis, $kunde['dose'], 'GET', '/bereiche/live')['text'], true);
        gleich($live['bereiche'] ?? null, [], 'Kunde bekommt keine Livewerte fremder Bereiche');
    }
    foreach (['/dashboard', '/anlagen', '/karte', '/statistik'] as $pfad) {
        $r = aufruf($basis, $kunde['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null, 'Kunde ' . $pfad . ' → ' . $r['status']);
    }
    if ($anlage > 0) {
        $r = aufruf($basis, $kunde['dose'], 'GET', '/anlagen/' . $anlage);
        pruefe(in_array($r['status'], [403, 404], true), 'Kunde ohne Freigabe sieht keine Anlage → ' . $r['status']);
        $name = (string) Database::scalar('SELECT name FROM objekte WHERE id = ?', [$anlage]);
        pruefe(!str_contains(aufruf($basis, $kunde['dose'], 'GET', '/anlagen')['text'], htmlspecialchars($name)), 'Anlagenliste des Kunden ist leer');
    }
    $r = aufruf($basis, $kunde['dose'], 'POST', '/steuerung/schalten', ['quelle' => 1, 'befehl' => 'aus', 'kanal' => 'switch:0', '_token' => $kunde['token']],
        ['Accept: application/json']);
    gleich($r['status'], 403, 'Kunde darf nicht schalten');

    // ---------------------------------------------------- Technik ohne Schalt-Freigabe
    $technik = anmelden($basis, $kennung . '-technik', $kennwort);
    $dosen[] = $technik['dose'];
    $quelle = (int) Database::scalar("SELECT id FROM datenquellen WHERE art = 'shelly' ORDER BY id LIMIT 1");
    if ($quelle > 0) {
        $vorher = (int) Database::scalar("SELECT COUNT(*) FROM schaltvorgaenge WHERE ergebnis = 'ok'");
        $r = aufruf($basis, $technik['dose'], 'POST', '/steuerung/schalten', ['quelle' => $quelle, 'befehl' => 'aus', 'kanal' => 'switch:0', '_token' => $technik['token']],
            ['Accept: application/json']);
        $json = json_decode($r['text'], true);
        pruefe($r['status'] === 422 && ($json['ok'] ?? true) === false, 'Recht „steuern“ ohne Schalt-Freigabe für das Gerät → abgelehnt');
        gleich((int) Database::scalar("SELECT COUNT(*) FROM schaltvorgaenge WHERE ergebnis = 'ok'"), $vorher, 'nichts geschaltet');
    }
    // Katalog importieren: „Anlagen ändern“ ohne alle Standorte genügt nicht
    gleich(aufruf($basis, $technik['dose'], 'GET', '/anlagen/katalog/import')['status'], 403, 'Technik: Katalog-Import → 403');
    pruefe(!str_contains(aufruf($basis, $technik['dose'], 'GET', '/anlagen/katalog')['text'], '/anlagen/katalog/import'), 'Technik: kein Knopf „Importieren …“');
    $r = hochladen($basis, $technik['dose'], '/anlagen/katalog/import/vorschau', ['_token' => $technik['token']], 'datei', $importPaket, 'web.json');
    gleich($r['status'], 403, 'Technik: Vorschau → 403');
    $r = aufruf($basis, $technik['dose'], 'POST', '/anlagen/katalog/import/uebernehmen', ['kennung' => $importKennung, 'neu' => [$kennung . '-web-b'], '_token' => $technik['token']]);
    pruefe($r['status'] === 403 && $importId($kennung . '-web-b') === 0, 'Technik: Übernehmen → 403');
    // Verteiler-Abgleich: ohne „inventory.pull“/„inventory.push“ weder ansehen noch übernehmen, hochladen, entfernen
    gleich(aufruf($basis, $technik['dose'], 'GET', '/verteiler-abgleich')['status'], 403, 'Technik sieht den Verteiler-Abgleich nicht');
    foreach (['uebernehmen', 'hochladen', 'entfernen'] as $aktion) {
        $r = aufruf($basis, $technik['dose'], 'POST', '/verteiler-abgleich/' . $aktion, ['alle' => 1, 'bereich' => 'inventar', 'schluessel' => 'x', '_token' => $technik['token']]);
        gleich($r['status'], 403, 'Technik darf im Verteiler-Abgleich nicht ' . $aktion);
    }
    // Eigene MQTT-Zugänge: ändern nur mit „mqtt.zugaenge“ UND allen Geräten
    $zugaengeVorher = (int) Database::scalar('SELECT COUNT(*) FROM mqtt_zugaenge');
    foreach (['einrichten' => ['praefix' => 'shellypro4pm-' . $kennung, 'generation' => 2], 'pruefen' => ['id' => 1], 'erneuern' => ['id' => 1], 'entfernen' => ['id' => 1], 'uebertragen' => ['id' => 1]] as $aktion => $felder) {
        $r = aufruf($basis, $technik['dose'], 'POST', '/datenquellen/mqtt-zugaenge/' . $aktion, $felder + ['_token' => $technik['token']]);
        gleich($r['status'], 403, 'Technik darf MQTT-Zugänge nicht ' . $aktion);
    }
    $mqttNutzer = anmelden($basis, $kennung . '-mqtt', $kennwort);
    $dosen[] = $mqttNutzer['dose'];
    $r = aufruf($basis, $mqttNutzer['dose'], 'GET', '/datenquellen/mqtt-zugaenge');
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'Eigenen Zugang einrichten</button>'), 'mit „mqtt.zugaenge“ ohne alle Geräte: Liste ja, Knöpfe nein');
    $r = aufruf($basis, $mqttNutzer['dose'], 'POST', '/datenquellen/mqtt-zugaenge/einrichten', ['praefix' => 'shellypro4pm-' . $kennung, 'generation' => 2, '_token' => $mqttNutzer['token']]);
    gleich($r['status'], 403, '„mqtt.zugaenge“ ohne „Alle Standorte …“ → 403');
    $r = aufruf($basis, $admin['dose'], 'POST', '/datenquellen/mqtt-zugaenge/einrichten', ['praefix' => 'shellypro4pm-' . $kennung, 'generation' => 2]);
    pruefe($r['status'] === 302 && Database::scalar('SELECT COUNT(*) FROM mqtt_zugaenge') == $zugaengeVorher, 'MQTT-Zugang einrichten ohne CSRF-Token → abgelehnt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/datenquellen/mqtt-zugaenge/einrichten', ['praefix' => 'shellypro4pm-' . $kennung, 'generation' => 2, '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && stripos($r['kopf'], 'no-store') !== false, 'Admin im Demo-Modus: Weiterleitung, nicht zwischengespeichert');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM mqtt_zugaenge'), $zugaengeVorher, 'im Demo-Modus wird kein Zugang angelegt');
    $r = aufruf($basis, $admin['dose'], 'GET', '/datenquellen/mqtt-zugaenge');
    pruefe(!str_contains($r['text'], '$7$'), 'kein Kennwort-Hash in der Seite');

    // Die Repository-Adresse (und damit, wohin das Token geht) nur mit „Einstellungen ändern“
    $r = aufruf($basis, $technik['dose'], 'POST', '/system/einstellungen/location', ['repo_url' => 'https://evil.example/x.git', '_token' => $technik['token']]);
    gleich($r['status'], 403, 'Repository-Adresse nur mit „Einstellungen ändern“');
    $r = aufruf($basis, $technik['dose'], 'POST', '/system/einstellungen/eventworx', ['eventworx_adresse' => 'firma', 'eventworx_token' => 'tok-' . $kennung, '_token' => $technik['token']]);
    gleich($r['status'], 403, 'Eventworx-Adresse und -Token nur mit „Einstellungen ändern“');
    gleich(aufruf($basis, $technik['dose'], 'POST', '/system/einstellungen/eventworx/pruefen', ['_token' => $technik['token']])['status'], 403,
        'Eventworx-Verbindungstest nur mit „Einstellungen ändern“');

    // ---------------------------------------------------- Anmeldung und Sitzung
    // Nach der Anmeldung nur auf eigene Pfade – „/\\evil“ machen Browser zu „//evil“
    $dose = tempnam(sys_get_temp_dir(), 'pm-web-');
    $dosen[] = $dose;
    foreach (['/\\evil.example', "/\t/evil.example", '//evil.example', 'https://evil.example'] as $next) {
        $seite = aufruf($basis, $dose, 'GET', '/login');
        $r = aufruf($basis, $dose, 'POST', '/login', ['username' => $kennung . '-zwei', 'password' => $kennwort, 'next' => $next, '_token' => token($seite['text'])]);
        pruefe($r['status'] === 302 && preg_match('#^/[A-Za-z]#', (string) $r['ziel']) === 1, 'next=' . json_encode($next) . ' führt nicht hinaus: ' . $r['ziel']);
        aufruf($basis, $dose, 'POST', '/logout', ['_token' => token(aufruf($basis, $dose, 'GET', '/profil')['text'])]);
    }
    // Anmeldung über SSO, solange sie aus ist: Start und Rückruf führen zur
    // Anmeldung mit Meldung (no-store, no-referrer), HEAD verbraucht nichts,
    // /abgemeldet ohne Hinweis führt zur Anmeldung. „Abgemeldet.“ kommt an
    // (neue Sitzung nach dem Abmelden).
    $ohneSso = tempnam(sys_get_temp_dir(), 'pm-web-');
    $dosen[] = $ohneSso;
    $r = aufruf($basis, $ohneSso, 'GET', '/login/sso?next=/karte');
    pruefe($r['status'] === 302 && $r['ziel'] === '/login' && preg_match('/^Cache-Control:\s*no-store/mi', $r['kopf']) === 1
        && preg_match('/^Referrer-Policy:\s*no-referrer/mi', $r['kopf']) === 1, 'SSO aus: /login/sso → /login, no-store, no-referrer');
    pruefe(str_contains(aufruf($basis, $ohneSso, 'GET', '/login')['text'], 'nicht eingerichtet'), 'SSO aus: Meldung „nicht eingerichtet“');
    $r = aufruf($basis, $ohneSso, 'GET', '/login/sso/rueckruf?state=' . str_repeat('a', 43) . '&code=x');
    pruefe($r['status'] === 302 && $r['ziel'] === '/login' && str_contains(aufruf($basis, $ohneSso, 'GET', '/login')['text'], 'abgelaufen oder wurde schon benutzt'),
        'Rückruf ohne Vorgang → /login mit Meldung');
    $c = curl_init($basis . '/login/sso/rueckruf?state=' . str_repeat('b', 43));
    curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    $kopfHead = (string) curl_exec($c);
    pruefe((int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 302 && preg_match('#^Location:\s*/login\s*$#mi', $kopfHead) === 1, 'HEAD auf den Rückruf → /login');
    curl_close($c);
    $r = aufruf($basis, $ohneSso, 'GET', '/abgemeldet');
    pruefe($r['status'] === 302 && $r['ziel'] === '/login', '/abgemeldet ohne Sitzung → /login');
    $r = aufruf($basis, $ohneSso, 'GET', '/login/sso/verbinden');
    pruefe($r['status'] === 302 && $r['ziel'] === '/login?next=%2Fprofil' && preg_match('/^Cache-Control:\s*no-store/mi', $r['kopf']) === 1,
        '/login/sso/verbinden ohne Anmeldung → /login?next=/profil');
    gleich(aufruf($basis, $ohneSso, 'GET', '/login/sso/verbinden', [], ['Sec-Fetch-Site: cross-site'])['status'], 403, '/login/sso/verbinden von fremder Seite → 403');
    $r = aufruf($basis, $ohneSso, 'GET', '/system/sso');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), '/system/sso ohne Anmeldung → Anmeldeseite');
    $loginSeite = aufruf($basis, $ohneSso, 'GET', '/login');
    pruefe(!str_contains($loginSeite['text'], 'sso-knopf') && !str_contains($loginSeite['text'], '<details class="lokal"'), 'SSO aus: Anmeldeseite ohne Knopf, Formular offen');
    $seite = aufruf($basis, $ohneSso, 'GET', '/login');
    aufruf($basis, $ohneSso, 'POST', '/login', ['username' => $kennung . '-zwei', 'password' => $kennwort, '_token' => token($seite['text'])]);
    // SSO aus: Verbinden führt mit Meldung zurück ins Profil, das Profil zeigt keine SSO-Karte.
    $r = aufruf($basis, $ohneSso, 'GET', '/login/sso/verbinden');
    pruefe($r['status'] === 302 && $r['ziel'] === '/profil' && str_contains(aufruf($basis, $ohneSso, 'GET', '/profil')['text'], 'nicht eingerichtet'),
        'SSO aus: Verbinden → /profil mit Meldung');
    $r = aufruf($basis, $ohneSso, 'GET', '/profil');
    pruefe(!str_contains($r['text'], 'id="sso"') && str_contains($r['text'], 'Passwort ändern'), 'SSO aus: Profil ohne SSO-Karte, mit Kennwort-Karte');
    $r = aufruf($basis, $ohneSso, 'POST', '/profil/sso/loesen', ['_token' => token($r['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/profil' && str_contains(aufruf($basis, $ohneSso, 'GET', '/profil')['text'], 'mit keiner Anmeldung'),
        'Lösen ohne Verknüpfung → /profil mit Meldung');
    $r = aufruf($basis, $ohneSso, 'POST', '/logout', ['_token' => token(aufruf($basis, $ohneSso, 'GET', '/profil')['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/login' && preg_match('/^Set-Cookie:\s*pm_sso_neu=/mi', $r['kopf']) !== 1,
        'Abmelden (Kennwort) → /login, ohne pm_sso_neu');
    $seite = aufruf($basis, $ohneSso, 'GET', '/login');
    pruefe(str_contains($seite['text'], 'Abgemeldet.'), '„Abgemeldet.“ steht nach dem Abmelden auf der Anmeldeseite');
    pruefe(aufruf($basis, $ohneSso, 'GET', '/profil')['status'] === 302, 'nach dem Abmelden nicht mehr angemeldet');

    // Das Token zählt nur aus dem Formular; ein fremder Referer wird nicht angesteuert
    $vorher = (int) Database::scalar('SELECT COUNT(*) FROM steckertypen');
    $r = aufruf($basis, $admin['dose'], 'POST', '/stecker/speichern?_token=' . rawurlencode($admin['token']), ['name' => $kennung . '-stecker'], ['Referer: https://evil.example/x']);
    pruefe($r['status'] === 302 && !str_contains((string) $r['ziel'], 'evil'), 'Token aus der Adresse zählt nicht, fremder Referer wird nicht angesteuert: ' . $r['ziel']);
    gleich((int) Database::scalar('SELECT COUNT(*) FROM steckertypen'), $vorher, 'nichts gespeichert');
    // Kennwort geändert: die andere Sitzung endet, diese läuft weiter
    $sitzungA = anmelden($basis, $kennung . '-zwei', $kennwort);
    $sitzungB = anmelden($basis, $kennung . '-zwei', $kennwort);
    $dosen[] = $sitzungA['dose'];
    $dosen[] = $sitzungB['dose'];
    $neu = 'Geaendert-' . bin2hex(random_bytes(6)) . '1';
    $r = aufruf($basis, $sitzungA['dose'], 'POST', '/profil/passwort', ['current_password' => $kennwort, 'new_password' => $neu, 'new_password_repeat' => $neu, '_token' => $sitzungA['token']]);
    pruefe($r['status'] === 302 && !str_starts_with((string) $r['ziel'], '/login'), 'Kennwort geändert');
    gleich(aufruf($basis, $sitzungA['dose'], 'GET', '/profil')['status'], 200, 'die Sitzung, die es geändert hat, läuft weiter');
    $r = aufruf($basis, $sitzungB['dose'], 'GET', '/dashboard');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'die andere Sitzung mit dem alten Kennwort ist beendet');
    // Mit vorläufigem Kennwort geht es nur zum Profil
    $vorlaeufig = anmelden($basis, $kennung . '-vorlaeufig', $kennwort);
    $dosen[] = $vorlaeufig['dose'];
    $r = aufruf($basis, $vorlaeufig['dose'], 'GET', '/dashboard');
    pruefe($r['status'] === 302 && $r['ziel'] === '/profil', 'vorläufiges Kennwort: erst ein eigenes, dann der Rest');

    // ---------------------------------------------------- Benutzer verwalten ohne Administration
    $verwalter = anmelden($basis, $kennung . '-verwalter', $kennwort);
    $dosen[] = $verwalter['dose'];
    // Anmeldung über SSO einrichten nur mit sso.manage – auch nicht mit „Benutzer verwalten“.
    gleich(aufruf($basis, $verwalter['dose'], 'GET', '/system/sso')['status'], 403, 'Verwalter ohne sso.manage: /system/sso → 403');
    $vorherSso = Database::select("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sso.%' ORDER BY setting_key");
    $r = aufruf($basis, $verwalter['dose'], 'POST', '/system/sso/optionen', ['anlegen' => '1', 'scopes' => 'openid', '_token' => $verwalter['token']]);
    pruefe($r['status'] === 403 && Database::select("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sso.%' ORDER BY setting_key") == $vorherSso,
        'Verwalter ohne sso.manage: Optionen speichern → 403, nichts geändert');
    $r = aufruf($basis, $verwalter['dose'], 'GET', '/system/benutzer');
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'href="/system/sso'), 'Verwalter ohne sso.manage: kein Verweis auf Anmeldung (SSO)');
    $adminRolle = (int) Database::scalar('SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/benutzer/speichern', ['id' => $benutzer['verwalter'], 'username' => $kennung . '-verwalter', 'is_active' => 1,
        'rollen' => [$adminRolle => ['aktiv' => 1], $verwalterRolle => ['aktiv' => 1]], '_token' => $verwalter['token']]);
    pruefe(Database::first('SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?', [$benutzer['verwalter'], $adminRolle]) === null,
        'Benutzerverwaltung gibt sich nicht die Administratorrolle');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/rollen/speichern', ['name' => $kennung . ' alles', 'rechte' => ['*'], '_token' => $verwalter['token']]);
    pruefe(Database::first('SELECT id FROM roles WHERE name = ?', [$kennung . ' alles']) === null, 'keine Rolle mit allen Rechten');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/rollen/speichern', ['id' => $verwalterRolle, 'name' => $kennung . ' verwalter', 'rechte' => ['system.users.manage', 'dashboard.view', 'anlagen.scope.all'], '_token' => $verwalter['token']]);
    pruefe(!in_array('anlagen.scope.all', BenutzerService::rollenRechte($verwalterRolle), true), 'die eigene Rolle bekommt keine Rechte dazu');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/benutzer/speichern', ['id' => $benutzer['admin'], 'username' => $kennung . '-admin', 'is_active' => 1,
        'password' => 'Uebernahme-2026-x', 'rollen' => [$adminRolle => ['aktiv' => 1]], '_token' => $verwalter['token']]);
    pruefe(password_verify($kennwort, (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$benutzer['admin']])), 'das Kennwort des Administrators bleibt');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/freigaben/speichern', ['subject_type' => 'user', 'subject_id' => $benutzer['verwalter'], 'ziel_art' => 'alles', 'darf_steuern' => 1, '_token' => $verwalter['token']]);
    gleich((int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$benutzer['verwalter']]), 0, 'keine Freigabe auf alles für sich selbst');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/benutzer/speichern', ['id' => $benutzer['zwei'], 'username' => $kennung . '-zwei', 'full_name' => 'Geändert', 'is_active' => 1,
        'rollen' => [], '_token' => $verwalter['token']]);
    gleich((string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$benutzer['zwei']]), '', 'ein Konto mit Rechten, die der Verwalter nicht hat (Kunde: Statistik), bleibt unverändert');
    aufruf($basis, $verwalter['dose'], 'POST', '/system/benutzer/speichern', ['id' => $benutzer['klein'], 'username' => $kennung . '-klein', 'full_name' => 'Geändert', 'is_active' => 1,
        'rollen' => [$kleinRolle => ['aktiv' => 1]], '_token' => $verwalter['token']]);
    gleich((string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$benutzer['klein']]), 'Geändert', 'ein Konto mit weniger Rechten lässt sich verwalten');

    // ---------------------------------------------------- Ändern nur, wo „bearbeiten“ freigegeben ist
    $fremd = Database::first('SELECT l.id AS leitung, l.floor_id, f.site_id, f.name AS etage FROM leitungen l JOIN floors f ON f.id = l.floor_id ORDER BY l.id LIMIT 1');
    if ($fremd !== null) {
        $werk = \App\Services\SiteService::saveSite(null, ['name' => $kennung . ' Werk']);
        $werkEtage = \App\Services\SiteService::saveFloor(null, ['site_id' => $werk, 'name' => 'Halle']);
        $eigen = (int) \App\Services\AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' eigen', 'floor_id' => $werkEtage,
            'plan_x' => 10, 'plan_y' => 10, 'ohne_anschluesse' => true])['id'];
        array_push($auditZiele, ['site', $werk], ['floor', $werkEtage], ['objekt', $eigen]);
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'site', 'ziel_id' => $werk, 'darf_bearbeiten' => 1]);
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'site', 'ziel_id' => (int) $fremd['site_id']]);
        $planer = anmelden($basis, $kennung . '-planer', $kennwort);
        $dosen[] = $planer['dose'];
        if (($tankId ?? 0) > 0) {
            gleich(aufruf($basis, $planer['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf')['status'], 403, 'Planer ohne „statistik.view“: Tankverlauf → 403');
            gleich(aufruf($basis, $planer['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv')['status'], 403, 'Planer ohne „statistik.export“: Tank-CSV → 403');
            gleich(aufruf($basis, $planer['dose'], 'GET', '/anlagen/' . $tankId . '/tank/tage')['status'], 403, 'Planer ohne „statistik.view“: Verbrauch je Tag → 403');
        }
        $json = ['Accept: application/json'];
        // Ein Tank auf dem nur sichtbaren Standort: ansehen ja, peilen und betanken nein
        $fremderTank = (int) \App\Services\AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennung . ' fremder Tank', 'floor_id' => (int) $fremd['floor_id'],
            'plan_x' => 12, 'plan_y' => 12, 'eigenschaften' => ['inhalt_l' => '1000']])['id'];
        $r = aufruf($basis, $planer['dose'], 'GET', '/anlagen/' . $fremderTank);
        pruefe($r['status'] === 200 && !str_contains($r['text'], '/tank/peilung') && !str_contains($r['text'], '/tank/betankung'), 'Planer: fremder Tank sichtbar, ohne Peilung und Betankung');
        foreach (['/tank/peilung' => ['art' => 'prozent', 'wert' => '50'], '/tank/betankung' => ['liter' => '100'], '/tank/kennlinie' => ['messpunkt' => 0]] as $pfad => $felder) {
            $r = aufruf($basis, $planer['dose'], 'POST', '/anlagen/' . $fremderTank . $pfad, $felder + ['_token' => $planer['token']], $json);
            pruefe($r['status'] === 403 && (json_decode($r['text'], true)['ok'] ?? true) === false, 'Planer ohne „bearbeiten“: ' . $pfad . ' → 403 (JSON)');
        }
        gleich((int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$fremderTank]), 0, '… nichts eingetragen');
        // Leitungen nur zwischen Anlagen auf der Etage – nicht über den eigenen Plan zwischen zwei fremden Anlagen
        $fremdGen = (int) \App\Services\AnlagenService::speichern(null, ['art' => 'generator', 'name' => $kennung . ' fremder Generator', 'floor_id' => (int) $fremd['floor_id'],
            'plan_x' => 14, 'plan_y' => 14, 'ohne_anschluesse' => true])['id'];
        $fremdeV = (int) \App\Services\AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' fremde Verteilung', 'floor_id' => (int) $fremd['floor_id'],
            'plan_x' => 16, 'plan_y' => 16, 'ohne_anschluesse' => true])['id'];
        $leitungen = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM leitungen');
        $nL = $leitungen();
        foreach ([['art' => 'kraftstoff', 'von_objekt_id' => $fremderTank, 'zu_objekt_id' => $fremdGen], ['art' => 'kabel', 'von_objekt_id' => $fremdeV, 'zu_objekt_id' => $fremdGen]] as $felder) {
            $r = aufruf($basis, $planer['dose'], 'POST', '/karte/leitung/speichern', $felder + ['floor_id' => $werkEtage, 'punkte' => '[[10,10],[12,12]]', 'name' => $kennung . ' quer',
                '_token' => $planer['token']], $json);
            gleich($r['status'], 403, 'Planer: ' . $felder['art'] . ' auf der eigenen Etage zwischen zwei Anlagen eines fremden Standorts → 403');
        }
        gleich($leitungen(), $nL, '… nichts eingezeichnet');
        // Ein Schlauch braucht „bearbeiten“ an beiden Enden – er ändert Vorrat und Restlaufzeit aller Tanks am Generator.
        $schlauchPlaner = static fn () => aufruf($basis, $planer['dose'], 'POST', '/karte/leitung/speichern', ['art' => 'kraftstoff', 'von_objekt_id' => $fremderTank,
            'zu_objekt_id' => $fremdGen, 'floor_id' => (int) $fremd['floor_id'], 'punkte' => '[[12,12],[14,14]]', 'name' => $kennung . ' schlauch', '_token' => $planer['token']], $json);
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'objekt', 'ziel_id' => $fremderTank, 'darf_bearbeiten' => 1]);
        $r = $schlauchPlaner();
        pruefe($r['status'] === 403 && $leitungen() === $nL && \App\Tanks\TankService::versorgung($fremderTank)['generatoren'] === [],
            'Planer: Schlauch vom eigenen Tank zum nur sichtbaren Generator → 403, die Versorgung bleibt');
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'objekt', 'ziel_id' => $fremdGen, 'darf_bearbeiten' => 1]);
        $r = $schlauchPlaner();
        pruefe($r['status'] === 200 && \App\Tanks\TankService::versorgung($fremderTank)['generatoren'] === [$fremdGen], 'mit „bearbeiten“ an Tank und Generator: Schlauch gezogen');
        \App\Services\AnlagenService::loeschen($fremderTank);
        aufruf($basis, $planer['dose'], 'POST', '/standorte/etage', ['site_id' => $werk, 'id' => (int) $fremd['floor_id'], 'name' => $kennung . ' gekapert', '_token' => $planer['token']]);
        gleich(Database::first('SELECT name, site_id FROM floors WHERE id = ?', [(int) $fremd['floor_id']]), ['name' => $fremd['etage'], 'site_id' => $fremd['site_id']],
            'eine fremde Etage lässt sich über ihre ID nicht umbenennen oder herüberziehen');
        $r = aufruf($basis, $planer['dose'], 'POST', '/karte/massstab', ['floor_id' => (int) $fremd['floor_id'], 'entfernen' => 1, '_token' => $planer['token']], $json);
        gleich($r['status'], 403, 'Maßstab eines nur sichtbaren Standorts: nicht änderbar');
        $r = aufruf($basis, $planer['dose'], 'POST', '/karte/leitung/loeschen', ['id' => (int) $fremd['leitung'], '_token' => $planer['token']], $json);
        pruefe($r['status'] === 403 && Database::first('SELECT id FROM leitungen WHERE id = ?', [(int) $fremd['leitung']]) !== null,
            'ein Kabel auf einem nur sichtbaren Standort lässt sich nicht löschen');
        aufruf($basis, $planer['dose'], 'POST', '/anlagen/speichern', ['art' => 'verteilung', 'name' => $kennung . ' eingeschleust', 'site_id' => $werk,
            'floor_id' => (int) $fremd['floor_id'], '_token' => $planer['token']]);
        pruefe(Database::first('SELECT id FROM objekte WHERE name = ?', [$kennung . ' eingeschleust']) === null,
            'eine neue Anlage landet nicht über eine fremde Etage auf einem fremden Standort');
        $r = aufruf($basis, $planer['dose'], 'POST', '/karte/objekt/verschieben', ['id' => $eigen, 'floor_id' => (int) $fremd['floor_id'], 'x' => 50, 'y' => 50, '_token' => $planer['token']], $json);
        pruefe($r['status'] === 403 && (int) Database::scalar('SELECT site_id FROM objekte WHERE id = ?', [$eigen]) === $werk,
            'die eigene Anlage lässt sich nicht in einen fremden Plan schieben');
        $r = aufruf($basis, $planer['dose'], 'POST', '/karte/objekt/verschieben', ['id' => $eigen, 'floor_id' => $werkEtage, 'x' => 20, 'y' => 30, '_token' => $planer['token']], $json);
        pruefe($r['status'] === 200 && (float) Database::scalar('SELECT plan_x FROM objekte WHERE id = ?', [$eigen]) === 20.0, 'auf dem eigenen Standort schon');
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$werk]) as $o) {
            \App\Services\AnlagenService::loeschen((int) $o['id']);
        }
        \App\Services\SiteService::deleteSite($werk);
    }

    // ---------------------------------------------------- Übergabeberichte: Seiten, Autosave, Eventworx, Rechte
    $json = ['Accept: application/json'];
    $typen = [];
    foreach (\App\Services\AnlagenService::steckertypen() as $t) {
        $typen[(string) $t['schluessel']] = (int) $t['id'];
    }
    $bSite = \App\Services\SiteService::saveSite(null, ['name' => $kennung . ' Wiese', 'address' => 'Nordwiese 1, 12345 Musterstadt']);
    $bEtage = \App\Services\SiteService::saveFloor(null, ['site_id' => $bSite, 'name' => 'Wiese']);
    $bVert = (int) \App\Services\AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $kennung . ' Bühne', 'site_id' => $bSite,
        'floor_id' => $bEtage, 'ohne_anschluesse' => true])['id'];
    // Ein Tank auf demselben Standort gehört in keinen Übergabebericht.
    $bTank = (int) \App\Services\AnlagenService::speichern(null, ['art' => 'tank', 'name' => $kennung . ' Bühnentank', 'site_id' => $bSite,
        'floor_id' => $bEtage, 'eigenschaften' => ['inhalt_l' => '1000']])['id'];
    array_push($auditZiele, ['site', $bSite], ['floor', $bEtage], ['objekt', $bVert], ['objekt', $bTank]);
    \App\Services\AnlagenService::anzahlSetzen($bVert, ['eingang' => [$typen['cee63'] => 1], 'abgang' => [$typen['cee32'] => 1, $typen['schuko'] => 2]]);
    \App\Services\SchutzService::vorschlagen($bVert);
    $bAufbau = (int) \App\Services\AufbauService::starten($bSite, $kennung . ' Sommerfest')['id'];
    $rolleId = static fn (string $name, array $rechte): int => (int) BenutzerService::rolleSpeichern(null, $kennung . ' ' . $name, '', $rechte)['id'];
    $rolleKey = static fn (int $id): string => (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$id]);
    $bPlanerRolle = $rolleId('berichtplaner', ['berichte.view', 'berichte.manage', 'berichte.eventworx', 'anlagen.view']);
    $benutzer['bplaner'] = BenutzerService::anlegen($kennung . '-bplaner', $kennwort, $rolleKey($bPlanerRolle), false);
    $benutzer['bkunde'] = BenutzerService::anlegen($kennung . '-bkunde', $kennwort, $rolleKey($rolleId('berichtkunde', ['berichte.view', 'anlagen.view'])), false);
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['bplaner'], 'ziel_art' => 'site', 'ziel_id' => $bSite]);
    $gestern = (new DateTimeImmutable('-1 day', \App\Support\Zeit::zone()))->format('Y-m-d');
    $morgen = (new DateTimeImmutable('+1 day', \App\Support\Zeit::zone()))->format('Y-m-d');
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['bkunde'], 'ziel_art' => 'site', 'ziel_id' => $bSite,
        'daten_ab' => $gestern, 'daten_bis' => $morgen]);

    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/neu?aufbau=' . $bAufbau);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'value="' . $bVert . '" checked') && phpMeldung($r['text']) === null,
        'Neuer Bericht aus dem Aufbau: mobile Anlagen vorausgewählt');
    pruefe(!str_contains($r['text'], 'value="' . $bTank . '"'), 'Neuer Bericht: der Tank steht nicht zur Wahl');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/anlegen', ['site_id' => $bSite, 'objekte' => [$bVert, $bTank], '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/berichte/neu')
        && (int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE site_id = ?', [$bSite]) === 0
        && str_contains(aufruf($basis, $admin['dose'], 'GET', (string) $r['ziel'])['text'], 'ist ein Tank'), 'Bericht mit Tank abgelehnt, mit Meldung');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/anlegen', ['site_id' => $bSite, 'aufbau_id' => $bAufbau, 'objekte' => [$bVert], '_token' => $admin['token']]);
    $bId = (int) Database::scalar('SELECT id FROM berichte WHERE site_id = ? ORDER BY id DESC LIMIT 1', [$bSite]);
    pruefe($bId > 0 && $r['status'] === 302 && $r['ziel'] === '/berichte/' . $bId, 'Bericht angelegt → Formular ' . $r['ziel']);
    $form = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId);
    pruefe($form['status'] === 200 && str_contains($form['text'], 'data-bericht="' . $bId . '"') && phpMeldung($form['text']) === null, 'Formular des Entwurfs');
    pruefe(str_contains($form['text'], $kennung . ' Bühne') && !str_contains($form['text'], $kennung . ' Bühnentank'), 'Formular: Anlagen zum Ergänzen ohne den Tank');
    gleich(substr_count($form['text'], 'data-zeile="k'), 3, 'je Abgang ein Stromkreis im Formular');
    pruefe(str_contains($form['text'], 'data-feld="kopf.kunde.firma"') && str_contains($form['text'], 'id="kreis-vorlage"'), 'Felder mit data-feld, Vorlage für neue Zeilen');
    pruefe(str_contains($form['text'], 'href="/berichte/' . $bId . '/pdf"'), 'Formular: Link zur PDF-Vorschau');
    foreach (['/berichte', '/berichte?status=entwurf&suche=' . rawurlencode($kennung), '/berichte/neu', '/berichte/neu?site=' . $bSite,
        '/berichte/neu?anlage=' . $bVert, '/berichte/pruefgeraete', '/berichte/pruefgeraete?neu=1', '/aufbau/' . $bAufbau, '/aufbau/' . $bAufbau . '/abgleich'] as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 200 && phpMeldung($r['text']) === null, 'Admin ' . $pfad . ' → ' . $r['status']);
        pruefe(!str_contains($r['text'], 'onclick=') && !str_contains($r['text'], 'javascript:'), $pfad . ' ohne Inline-JavaScript');
        preg_match_all('/<script\b([^>]*)>/', $r['text'], $skripte);
        pruefe(array_filter($skripte[1], static fn (string $a): bool => !str_contains($a, 'src=') && !str_contains($a, 'nonce=')) === [], $pfad . ': jedes Inline-<script> mit Nonce');
    }
    pruefe(!str_contains($form['text'], 'onclick=') && !str_contains($form['text'], 'javascript:'), 'Formular ohne Inline-JavaScript');
    $r = aufruf($basis, $admin['dose'], 'GET', '/aufbau/' . $bAufbau);
    pruefe(str_contains($r['text'], 'Übergabeberichte (1)') && str_contains($r['text'], 'href="/berichte/neu?aufbau=' . $bAufbau . '"'), 'Aufbau: Karte Übergabeberichte mit Knopf');
    $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $bVert);
    pruefe(str_contains($r['text'], 'href="/berichte/neu?anlage=' . $bVert . '"') && str_contains($r['text'], 'Letzte Prüfung'), 'Anlage: Knopf Übergabebericht, Zeile „Letzte Prüfung“');

    // Autosave: 200 mit Bewertung, 409 bei veralteter Version, 422 bei Unlesbarem
    $b = BerichtService::find($bId);
    $inhalt = $b['inhalt'];
    $z = (string) $inhalt['kreise'][0]['z'];
    $inhalt['kopf']['kunde']['firma'] = $kennung . ' Kunde GmbH';
    $inhalt['unbekannt'] = 'weg';
    $inhalt['kreise'][0]['w']['zs'] = ['op' => '', 'w' => '0,35'];
    $inhalt['kreise'][0]['objekt_id'] = '999999';
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'], 'inhalt' => json_encode($inhalt), '_token' => $admin['token']], $json);
    $j = json_decode($r['text'], true) ?? [];
    pruefe($r['status'] === 200 && ($j['ok'] ?? false) && ($j['version'] ?? 0) === (int) $b['version'] + 1 && isset($j['bewertung']['kreise'][$z]['auto'], $j['abschnitte']['messung']),
        'Autosave: 200 mit Version, Bewertung je Stromkreis und Stand je Abschnitt');
    $nachher = BerichtService::find($bId);
    pruefe($nachher['inhalt']['kopf']['kunde']['firma'] === $kennung . ' Kunde GmbH' && !isset($nachher['inhalt']['unbekannt'])
        && $nachher['inhalt']['kreise'][0]['w']['zs']['w'] === '0.35' && $nachher['inhalt']['kreise'][0]['objekt_id'] === (string) $bVert,
        'gespeichert: Kunde, Messwert als Dezimaltext; unbekannte Schlüssel und IDs aus dem Browser verworfen');
    // Dieselbe Eingabe noch einmal mit dem alten Stand (die Antwort ging verloren): gespeichert, kein Konflikt.
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'], 'inhalt' => json_encode($inhalt), '_token' => $admin['token']], $json);
    $j = json_decode($r['text'], true) ?? [];
    pruefe($r['status'] === 200 && ($j['version'] ?? 0) === (int) $b['version'] + 1 && (int) Database::scalar('SELECT version FROM berichte WHERE id = ?', [$bId]) === (int) $b['version'] + 1,
        'Autosave doppelt (Antwort verloren): 200 mit dem aktuellen Stand, nichts doppelt');
    $anders = $inhalt;
    $anders['kopf']['ort'] = 'Anderswo';
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'], 'inhalt' => json_encode($anders), '_token' => $admin['token']], $json);
    $j = json_decode($r['text'], true) ?? [];
    pruefe($r['status'] === 409 && ($j['konflikt'] ?? false) === true && (int) Database::scalar('SELECT version FROM berichte WHERE id = ?', [$bId]) === (int) $b['version'] + 1,
        'veraltete Version mit anderem Inhalt → 409, nichts geändert');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'] + 1, 'inhalt' => '{kaputt', '_token' => $admin['token']], $json);
    gleich($r['status'], 422, 'unlesbares JSON → 422');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'] + 1, 'inhalt' => str_repeat('x', 600000), '_token' => $admin['token']], $json);
    gleich($r['status'], 422, 'über 512 KB → 422');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/kreise', ['version' => $b['version'] + 1, 'objekte' => [$bVert], '_token' => $admin['token']], $json);
    $j = json_decode($r['text'], true) ?? [];
    pruefe($r['status'] === 200 && ($j['neu'] ?? -1) === 0, 'Zeilen ergänzen: alle Abgänge haben schon eine Zeile');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/pruefen', ['_token' => $admin['token']], $json);
    $j = json_decode($r['text'], true) ?? [];
    pruefe($r['status'] === 200 && ($j['bereit'] ?? true) === false && in_array('netz.form', array_column($j['fehler'] ?? [], 'feld'), true),
        'Prüfen: Fehler mit Feld (Netzform fehlt), nicht bereit');

    // Eventworx (Demo, ohne Netz): suchen, Vorgang laden, Verweis nur aus der Sitzung
    if (Config::isDemo()) {
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/eventworx/suche', ['q' => 'AU-1042', '_token' => $admin['token']], $json);
        $j = json_decode($r['text'], true) ?? [];
        $vorgang = (string) ($j['treffer'][0]['id'] ?? '');
        pruefe($r['status'] === 200 && ($j['treffer'][0]['nummer'] ?? '') === 'AU-1042', 'Eventworx-Suche nach der Nummer');
        gleich(aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/eventworx/suche', ['q' => 'A', '_token' => $admin['token']], $json)['status'], 422, 'Suchtext zu kurz → 422');
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/eventworx/vorgang', ['vorgang_id' => $vorgang, '_token' => $admin['token']], $json);
        $j = json_decode($r['text'], true) ?? [];
        pruefe($r['status'] === 200 && ($j['gruppen']['kunde']['firma'] ?? '') !== '' && ($j['verweis']['nummer'] ?? '') === 'AU-1042', 'Eventworx-Vorgang mit Gruppen');
        $b = BerichtService::find($bId);
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'], 'inhalt' => json_encode($b['inhalt']),
            'ewx' => $vorgang, '_token' => $admin['token']], $json);
        pruefe($r['status'] === 200 && Database::scalar('SELECT ewx_nummer FROM berichte WHERE id = ?', [$bId]) === 'AU-1042', 'Autosave übernimmt den geladenen Vorgang');
        $b = BerichtService::find($bId);
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => $b['version'], 'inhalt' => json_encode($b['inhalt']),
            'ewx' => 'abcdefgh12345678', '_token' => $admin['token']], $json);
        $j = json_decode($r['text'], true) ?? [];
        pruefe($r['status'] === 200 && str_contains(implode(' ', $j['hinweise'] ?? []), 'nicht geladen')
            && Database::scalar('SELECT ewx_id FROM berichte WHERE id = ?', [$bId]) === $vorgang, 'nicht geladener Vorgang → Hinweis, Verweis bleibt');
    }

    // Zweiter Bericht; der erste gilt als abgeschlossen (Unterschrift und PDF kommen später)
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/anlegen', ['site_id' => $bSite, 'objekte' => [$bVert], '_token' => $admin['token']]);
    $b2Id = (int) Database::scalar('SELECT id FROM berichte WHERE site_id = ? ORDER BY id DESC LIMIT 1', [$bSite]);
    pruefe($b2Id > $bId, 'zweiter Bericht angelegt');
    Database::update('berichte', $bId, ['status' => 'abgeschlossen', 'nummer' => 'T-' . $kennung, 'abgeschlossen_am' => gmdate('c'), 'eingefroren_am' => gmdate('c')]);
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Messprotokoll') && !str_contains($r['text'], 'data-bericht=') && phpMeldung($r['text']) === null,
        'abgeschlossener Bericht: Leseansicht statt Formular');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $bId . '/speichern', ['version' => 1, 'inhalt' => '{}', '_token' => $admin['token']], $json);
    gleich($r['status'], 423, 'abgeschlossen: Speichern → 423');
    /** Kopfzeilen und Rumpf einer PDF-Antwort prüfen. */
    $pdfAntwort = static function (array $r, string $datei): bool {
        return $r['status'] === 200 && str_starts_with($r['text'], '%PDF-') && str_ends_with($r['text'], "%%EOF\n")
            && preg_match('/^Content-Type: application\/pdf\r?$/mi', $r['kopf']) === 1
            && preg_match('/^Content-Disposition: inline; filename="' . preg_quote($datei, '/') . '"\r?$/mi', $r['kopf']) === 1
            && preg_match('/^X-Content-Type-Options: nosniff\r?$/mi', $r['kopf']) === 1
            && preg_match('/^Cache-Control: private, no-store\r?$/mi', $r['kopf']) === 1
            && preg_match('/^Referrer-Policy: no-referrer\r?$/mi', $r['kopf']) === 1
            && stripos($r['kopf'], 'sandbox') === false;
    };
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId . '/pdf');
    pruefe($pdfAntwort($r, 'T-' . $kennung . '.pdf'), 'PDF: 200, application/pdf, inline mit Nummer, nosniff, no-store, kein Referrer, keine Sandbox-CSP');
    pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId)['text'], 'kein PDF gespeichert'), 'abgeschlossen ohne gespeichertes PDF: Hinweis');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId);
    pruefe(str_contains($r['text'], 'id="kundenbereich"') && str_contains($r['text'], 'Keinem Kunden zugeordnet') && str_contains($r['text'], 'action="/berichte/' . $bId . '/kundenbereich"'),
        'abgeschlossener Bericht: Karte „Kundenbereich“ mit Zuordnung (Administration)');
    $r = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $bVert);
    pruefe(str_contains($r['text'], 'T-' . $kennung), 'Anlage: „Letzte Prüfung“ nennt den abgeschlossenen Bericht');
    pruefe($pdfAntwort(aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b2Id . '/pdf'), 'Entwurf-' . $b2Id . '.pdf'), 'Entwurf: PDF-Vorschau');
    gleich(aufruf($basis, $admin['dose'], 'GET', '/berichte/999999999/pdf')['status'], 404, 'PDF eines unbekannten Berichts → 404');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b2Id . '/kunde-aus', ['quelle' => $bId, '_token' => $admin['token']], $json);
    $j = json_decode($r['text'], true) ?? [];
    pruefe($r['status'] === 200 && ($j['kunde']['firma'] ?? '') === $kennung . ' Kunde GmbH' && ($j['kunde']['quelle'] ?? '') === 'bericht',
        'Kunde aus früherem Bericht desselben Standorts');

    // Prüfgeräte: anlegen über das Formular, abgelaufene Kalibrierung sichtbar
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/pruefgeraete/speichern', ['fabrikat' => $kennung, 'typ' => 'IT 1', 'seriennummer' => 'SN-1',
        'kalibriert_bis' => '2020-01-31', 'aktiv' => 1, '_token' => $admin['token']]);
    $geraet = (int) Database::scalar('SELECT id FROM pruefgeraete WHERE fabrikat = ?', [$kennung]);
    pruefe($r['status'] === 302 && $geraet > 0, 'Prüfgerät angelegt');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/pruefgeraete');
    pruefe(str_contains($r['text'], $kennung) && str_contains($r['text'], 'abgelaufen'), 'Prüfgeräte: Liste mit abgelaufener Kalibrierung');
    gleich(aufruf($basis, $admin['dose'], 'GET', '/berichte/pruefgeraete?id=' . $geraet)['status'], 200, 'Prüfgerät bearbeiten');

    // Firma & Übergabeberichte: gespeichert, Bank nicht im Audit; ungültiges Kürzel verwirft alles
    $bank = 'IBAN DE00 ' . $kennung;
    $r = aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/firma', ['firma__name' => $kennung . ' Strom GmbH', 'firma__bank' => $bank,
        'berichte__praefix' => 'tb', 'berichte__normen' => ['', '0100-600'], '_token' => $admin['token']]);
    Settings::vergessen();
    pruefe($r['status'] === 302 && Settings::get('firma.name') === $kennung . ' Strom GmbH' && Settings::get('berichte.praefix') === 'TB'
        && Settings::get('berichte.normen') === '0100-600', 'Firma & Übergabeberichte gespeichert');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE ?', ['%' . $bank . '%']), 0, 'Bankverbindung nicht im Audit');
    aufruf($basis, $admin['dose'], 'POST', '/system/einstellungen/firma', ['firma__name' => 'Anders', 'berichte__praefix' => 'U1', '_token' => $admin['token']]);
    Settings::vergessen();
    gleich(Settings::get('firma.name'), $kennung . ' Strom GmbH', 'ungültiges Kürzel → nichts gespeichert');
    $r = aufruf($basis, $admin['dose'], 'GET', '/system/einstellungen');
    pruefe(str_contains($r['text'], 'id="firma"') && str_contains($r['text'], $kennung . ' Strom GmbH') && phpMeldung($r['text']) === null, 'Einstellungen: Karte Firma & Übergabeberichte');

    // Prüfer-Qualifikation pflegt die Benutzerverwaltung; im Profil nur lesbar
    $quali = 'Elektrofachkraft ' . $kennung;
    aufruf($basis, $admin['dose'], 'POST', '/system/benutzer/speichern', ['id' => $benutzer['bplaner'], 'username' => $kennung . '-bplaner', 'is_active' => 1,
        'pruefer_qualifikation' => $quali, 'rollen' => [$bPlanerRolle => ['aktiv' => 1]], '_token' => $admin['token']]);
    gleich(Database::scalar('SELECT pruefer_qualifikation FROM users WHERE id = ?', [$benutzer['bplaner']]), $quali, 'Qualifikation in der Benutzerverwaltung gesetzt');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'benutzer.speichern' AND details LIKE ?", ['%' . $quali . '%']), 1,
        'wer die Qualifikation gesetzt hat, steht im Audit');
    $r = aufruf($basis, $admin['dose'], 'GET', '/system/benutzer?id=' . $benutzer['bplaner']);
    pruefe(str_contains($r['text'], 'name="pruefer_qualifikation"') && str_contains($r['text'], $quali), 'Benutzerverwaltung zeigt das Feld');

    // Router: Autosave ohne Anmeldung → 401 JSON, mit falschem Token → 419 JSON
    $r = aufruf($basis, $leer, 'POST', '/berichte/' . $b2Id . '/speichern', ['version' => 1, 'inhalt' => '{}'], $json);
    pruefe($r['status'] === 401 && (json_decode($r['text'], true)['anmelden'] ?? false) === true, 'Autosave ohne Anmeldung → 401 JSON');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b2Id . '/speichern', ['version' => 1, 'inhalt' => '{}', '_token' => 'falsch'], $json);
    pruefe($r['status'] === 419 && (json_decode($r['text'], true)['token'] ?? false) === true, 'Autosave mit falschem Token → 419 JSON');
    $r = aufruf($basis, $leer, 'POST', '/berichte/' . $b2Id . '/speichern', ['version' => 1, 'inhalt' => '{}']);
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'ohne Accept → wie bisher Weiterleitung');

    // Kunde ohne „Übergabeberichte ansehen“
    foreach (['/berichte', '/berichte/' . $bId, '/berichte/' . $bId . '/pdf', '/berichte/pruefgeraete', '/berichte/neu'] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ohne Recht ' . $pfad);
    }
    gleich(aufruf($basis, $kunde['dose'], 'POST', '/berichte/' . $b2Id . '/speichern', ['version' => 1, 'inhalt' => '{}', '_token' => $kunde['token']], $json)['status'], 403,
        'Kunde ohne Recht: Speichern → 403');
    gleich(aufruf($basis, $kunde['dose'], 'POST', '/berichte/pruefgeraete/speichern', ['fabrikat' => $kennung . '-x', 'typ' => 'x', '_token' => $kunde['token']])['status'], 403,
        'Kunde ohne Recht: kein Prüfgerät');

    // Kunde mit „ansehen“ und Freigabe für den Standort mit Datenzeitraum: nur Abgeschlossenes im Zeitraum
    $bkunde = anmelden($basis, $kennung . '-bkunde', $kennwort);
    $dosen[] = $bkunde['dose'];
    $r = aufruf($basis, $bkunde['dose'], 'GET', '/berichte/' . $bId);
    pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && !str_contains($r['text'], 'data-bericht='), 'Kunde sieht den abgeschlossenen Bericht (nur lesen)');
    pruefe(!str_contains($r['text'], 'id="kundenbereich"'), 'ohne „kunden.view“: keine Karte „Kundenbereich“');
    gleich(aufruf($basis, $bkunde['dose'], 'GET', '/berichte/' . $b2Id)['status'], 404, 'Kunde sieht keinen Entwurf');
    pruefe($pdfAntwort(aufruf($basis, $bkunde['dose'], 'GET', '/berichte/' . $bId . '/pdf'), 'T-' . $kennung . '.pdf'), 'Kunde lädt das PDF des abgeschlossenen Berichts');
    gleich(aufruf($basis, $bkunde['dose'], 'GET', '/berichte/' . $b2Id . '/pdf')['status'], 404, 'Kunde: kein PDF eines Entwurfs');
    $r = aufruf($basis, $bkunde['dose'], 'GET', '/berichte');
    pruefe($r['status'] === 200 && str_contains($r['text'], 'href="/berichte/' . $bId . '"') && !str_contains($r['text'], 'href="/berichte/' . $b2Id . '"'),
        'Liste des Kunden: abgeschlossen ja, Entwurf nein');
    gleich(aufruf($basis, $bkunde['dose'], 'GET', '/berichte/neu')['status'], 403, 'Kunde: kein neuer Bericht');
    foreach (['/speichern' => ['version' => 1, 'inhalt' => '{}'], '/eventworx/suche' => ['q' => 'AU-1042'], '/kreise' => ['version' => 1], '/loeschen' => []] as $weg => $felder) {
        gleich(aufruf($basis, $bkunde['dose'], 'POST', '/berichte/' . $b2Id . $weg, $felder + ['_token' => $bkunde['token']], $json)['status'], 403, 'Kunde ' . $weg . ' → 403');
    }
    $r = aufruf($basis, $bkunde['dose'], 'GET', '/berichte/pruefgeraete');
    pruefe($r['status'] === 200 && !str_contains($r['text'], 'pruefgeraete?id='), 'Kunde sieht Prüfgeräte, ohne „Bearbeiten“');

    // Planer mit „ausfüllen“, aber nur „sehen“ am Standort: kein Entwurf, kein neuer Bericht
    $bplaner = anmelden($basis, $kennung . '-bplaner', $kennwort);
    $dosen[] = $bplaner['dose'];
    $versionVorher = (int) Database::scalar('SELECT version FROM berichte WHERE id = ?', [$b2Id]);
    $r = aufruf($basis, $bplaner['dose'], 'POST', '/berichte/' . $b2Id . '/speichern', ['version' => $versionVorher, 'inhalt' => '{}', '_token' => $bplaner['token']], $json);
    pruefe(in_array($r['status'], [403, 404], true) && (int) Database::scalar('SELECT version FROM berichte WHERE id = ?', [$b2Id]) === $versionVorher,
        'Planer ohne „bearbeiten“: Speichern abgelehnt (' . $r['status'] . '), nichts geändert');
    $r = aufruf($basis, $bplaner['dose'], 'POST', '/berichte/' . $b2Id . '/kreise', ['version' => $versionVorher, 'objekte' => [$bVert], '_token' => $bplaner['token']], $json);
    pruefe(in_array($r['status'], [403, 404], true), 'Planer ohne „bearbeiten“: Zeilen ergänzen abgelehnt (' . $r['status'] . ')');
    gleich(aufruf($basis, $bplaner['dose'], 'GET', '/berichte/neu?site=' . $bSite)['status'], 403, 'Planer ohne „bearbeiten“: kein neuer Bericht für den Standort');
    $anzahl = (int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE site_id = ?', [$bSite]);
    aufruf($basis, $bplaner['dose'], 'POST', '/berichte/anlegen', ['site_id' => $bSite, 'objekte' => [$bVert], '_token' => $bplaner['token']]);
    gleich((int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE site_id = ?', [$bSite]), $anzahl, 'Planer ohne „bearbeiten“: Anlegen abgelehnt');
    $r = aufruf($basis, $bplaner['dose'], 'GET', '/profil');
    pruefe(str_contains($r['text'], $quali) && !str_contains($r['text'], 'name="pruefer_qualifikation"'), 'Profil zeigt die Qualifikation nur an');

    // Leerer Bericht ohne Standort; Entwurf löschen
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/anlegen', ['leer' => 1, '_token' => $admin['token']]);
    $leerId = (int) Database::scalar('SELECT id FROM berichte WHERE site_id IS NULL AND erstellt_von = ? ORDER BY id DESC LIMIT 1', [$benutzer['admin']]);
    pruefe($leerId > 0 && $r['ziel'] === '/berichte/' . $leerId && aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $leerId)['status'] === 200, 'leerer Bericht ohne Standort');
    gleich(aufruf($basis, $bplaner['dose'], 'GET', '/berichte/' . $leerId)['status'], 404, 'fremder Bericht ohne Standort unsichtbar');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $leerId . '/loeschen', ['_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte' && BerichtService::find($leerId) === null, 'Entwurf gelöscht');

    // ---------------------------------------------------- Übergabeberichte: Unterschriften, Kundenmodus, Korrektur
    Database::update('users', $benutzer['admin'], ['pruefer_qualifikation' => 'Elektrofachkraft ' . $kennung, 'full_name' => 'Pia Prüferin']);
    /** Einen Bericht so ausfüllen, dass nur noch die Warnung zur Kalibrierung offen ist; Antwort von „Prüfen“. */
    $ausfuellen = static function (int $id) use ($basis, &$admin, $json, $kennung, $geraet): array {
        $b = BerichtService::find($id);
        $i = $b['inhalt'];
        $i['kopf']['kunde']['firma'] = $kennung . ' Kunde GmbH';
        $i['kopf']['ort'] = 'Musterstadt';
        $i['kopf']['anlage'] = ['bezeichnung' => 'Verteiler Bühne', 'details' => '', 'aufstellort' => 'Wiese'];
        $i['netz']['form'] = 'TN-S';
        $i['grundlage'] = ['normen' => ['0100-600'], 'sonstige' => '', 'grund' => 'aufbau'];
        $i['messung']['geraete'] = [(string) $geraet];
        foreach ($i['kreise'] as $n => $k) {
            $i['kreise'][$n]['leitung']['querschnitt'] = '2.5';
            $i['kreise'][$n]['status'] = 'gemessen';
            foreach (['zs' => ['', '0.4'], 'riso' => ['>', '550'], 'idelta' => ['', '22'], 'ta' => ['', '25'], 'ub' => ['', '0.8']] as $feld => [$op, $w]) {
                $i['kreise'][$n]['w'][$feld] = ['op' => $op, 'w' => $w];
            }
        }
        foreach (['besichtigung' => \App\Berichte\Katalog::besichtigung(), 'erprobung' => \App\Berichte\Katalog::erprobung()] as $teil => $katalog) {
            foreach ($katalog as $schluessel => $punkt) {
                if ($punkt['kurz']) {
                    $i['punkte'][$teil][$schluessel] = 'io';
                }
            }
        }
        $i['ergebnis']['entspricht'] = 'ja';
        aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $id . '/speichern', ['version' => $b['version'], 'inhalt' => json_encode($i), '_token' => $admin['token']], $json);
        return json_decode(aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $id . '/pruefen', ['_token' => $admin['token']], $json)['text'], true) ?? [];
    };
    $neuerBericht = static function () use ($basis, &$admin, $bSite, $bVert): int {
        aufruf($basis, $admin['dose'], 'POST', '/berichte/anlegen', ['site_id' => $bSite, 'objekte' => [$bVert], '_token' => $admin['token']]);
        return (int) Database::scalar('SELECT id FROM berichte WHERE site_id = ? ORDER BY id DESC LIMIT 1', [$bSite]);
    };
    $unterschreiben = static function (int $id, array $felder = []) use ($basis, &$admin, $json): array {
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $id . '/unterschreiben', $felder + ['version' => BerichtService::find($id)['version'],
            'striche' => unterschriftStriche(), 'name' => 'Pia Prüferin', 'ort' => 'Musterstadt', 'anfrage' => bin2hex(random_bytes(16)),
            'geraet_am' => gmdate('c'), '_token' => $admin['token']], $json);
        return ['status' => $r['status'], 'j' => json_decode($r['text'], true) ?? []];
    };
    $ohneSkript = static function (string $pfad, string $html): void {
        pruefe(!str_contains($html, 'onclick=') && !str_contains($html, 'javascript:'), $pfad . ' ohne Inline-JavaScript');
        preg_match_all('/<script\b([^>]*)>/', $html, $skripte);
        pruefe(array_filter($skripte[1], static fn (string $a): bool => !str_contains($a, 'src=') && !str_contains($a, 'nonce=')) === [], $pfad . ': jedes Inline-<script> mit Nonce');
    };

    $b3 = $neuerBericht();
    $p = $ausfuellen($b3);
    $kalibrierung = 'kalibrierung:' . $geraet;
    $warnungen = array_column($p['warnungen'] ?? [], 'code');
    pruefe(($p['fehler'] ?? null) === [] && in_array($kalibrierung, $warnungen, true) && ($p['bereit'] ?? true) === false,
        'Prüfen: keine Fehler, Warnung Kalibrierung offen ' . json_encode($p['fehler'] ?? null, JSON_UNESCAPED_UNICODE));
    $form = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3);
    pruefe(str_contains($form['text'], 'data-unterschrift-auf') && str_contains($form['text'], 'data-unterschrift-dialog') && str_contains($form['text'], '/assets/js/unterschrift.js'),
        'Formular: „Als Prüfer unterschreiben“ mit Dialog');
    $u = $unterschreiben($b3);
    pruefe($u['status'] === 422 && in_array($kalibrierung, array_column($u['j']['warnungen'] ?? [], 'code'), true), 'Unterschrift mit unbestätigter Warnung → 422');
    $u = $unterschreiben($b3, ['warnungen' => $warnungen, 'striche' => '{"s":[[[1000,1000,0.5],[9000,9000,0.5]]]}']);
    pruefe($u['status'] === 422 && BerichtService::find($b3)['status'] === 'entwurf', 'Striche mit Druckwerten → 422');
    $u = $unterschreiben($b3, ['warnungen' => $warnungen]);
    $b = BerichtService::find($b3);
    pruefe($u['status'] === 200 && ($u['j']['ok'] ?? false) && $b['status'] === 'geprueft' && (string) $b['nummer'] === (string) ($u['j']['nummer'] ?? '-')
        && preg_match('/^TB-\d{4}-\d{4}$/', (string) $b['nummer']) === 1, 'Prüfer unterschreibt → eingefroren mit Nummer ' . $b['nummer']);
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3);
    pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], 'action="/berichte/' . $b3 . '/kundenmodus"')
        && str_contains($r['text'], 'Prüfnachweis') && str_contains($r['text'], '✓ Integrität geprüft') && str_contains($r['text'], '<svg class="unterschrift-svg"')
        && str_contains($r['text'], 'action="/berichte/' . $b3 . '/zurueckziehen"'), 'vom Prüfer unterschrieben: Übergabe, Prüfnachweis, Unterschrift als SVG');
    $ohneSkript('/berichte/' . $b3 . ' (unterschrieben)', $r['text']);
    pruefe(str_contains($r['text'], 'href="/berichte/' . $b3 . '/pdf"') && str_contains($r['text'], 'Vorschau ansehen') && !str_contains($r['text'], 'data-teilen='),
        'unterschrieben: Link zur PDF-Vorschau, noch kein Teilen');
    pruefe($pdfAntwort(aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3 . '/pdf'), $b['nummer'] . '.pdf'), 'unterschrieben: PDF-Vorschau');
    pruefe(in_array('pdf_abgerufen', array_column(BerichtService::ereignisse($b3), 'ereignis'), true)
        && !str_contains(aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3)['text'], 'action="/berichte/' . $b3 . '/zurueckziehen"'),
        'Vorschau abgerufen: im Lebenslauf, Zurückziehen nicht mehr angeboten');
    pruefe(BerichtService::pdfHash($b3) === null, 'Vorschau wird nicht gespeichert');
    gleich(aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/speichern', ['version' => $b['version'], 'inhalt' => '{}', '_token' => $admin['token']], $json)['status'],
        423, 'unterschrieben: Speichern → 423');

    // Kundenmodus: alles andere gesperrt, Kunde unterschreibt, Prüfer entsperrt
    $schaltVorher = (int) Database::scalar('SELECT COUNT(*) FROM schaltvorgaenge WHERE user_id = ?', [$benutzer['admin']]);
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kundenmodus', ['_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde', 'An Kunden übergeben → Kundenseite');
    $kundeSeite = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3 . '/kunde');
    pruefe($kundeSeite['status'] === 200 && phpMeldung($kundeSeite['text']) === null && str_contains($kundeSeite['text'], 'data-kunde="' . $b3 . '"')
        && !str_contains($kundeSeite['text'], 'class="sidebar') && str_contains($kundeSeite['text'], '/assets/js/bericht-kunde.js')
        && str_contains($kundeSeite['text'], 'es wird nicht der prüftechnische Inhalt bestätigt'), 'Kundenseite ohne Navigation, mit Bestätigung und Unterschrift');
    $ohneSkript('/berichte/' . $b3 . '/kunde', $kundeSeite['text']);
    pruefe(str_contains($kundeSeite['text'], 'href="/berichte/' . $b3 . '/pdf"'), 'Kundenseite: „Als PDF ansehen“');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3 . '/pdf');
    pruefe($r['status'] === 200 && str_starts_with($r['text'], '%PDF-'), 'Kundenmodus: PDF dieses Berichts offen');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId . '/pdf');
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde', 'Kundenmodus: PDF eines anderen Berichts gesperrt');
    foreach (['/dashboard', '/berichte', '/berichte/' . $b2Id, '/system/einstellungen', '/profil'] as $pfad) {
        $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
        pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde', 'Kundenmodus: ' . $pfad . ' → Kundenseite');
    }
    $r = aufruf($basis, $admin['dose'], 'POST', '/steuerung/schalten', ['quelle' => 1, 'befehl' => 'aus', 'kanal' => 'switch:0', '_token' => $admin['token']], $json);
    pruefe($r['status'] === 423 && (json_decode($r['text'], true)['kundenmodus'] ?? false) === true, 'Kundenmodus: Schalten mit JSON → 423');
    $r = aufruf($basis, $admin['dose'], 'POST', '/steuerung/schalten', ['quelle' => 1, 'befehl' => 'aus', 'kanal' => 'switch:0', '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde', 'Kundenmodus: Schalten → Kundenseite');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM schaltvorgaenge WHERE user_id = ?', [$benutzer['admin']]), $schaltVorher, 'Kundenmodus: nichts geschaltet');
    gleich(aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b2Id . '/speichern', ['version' => 1, 'inhalt' => '{}', '_token' => $admin['token']], $json)['status'],
        423, 'Kundenmodus: Autosave eines anderen Berichts → 423');
    // Auch, was es nicht gibt oder das Konto nicht darf, führt zur Kundenseite –
    // keine Fehlerseite mit Navigation, Abmelden und Token.
    foreach ([['GET', '/gibt-es-nicht'], ['GET', '/audit'], ['GET', '/system/benutzer'], ['GET', '/berichte/' . $b3 . '/kunde/beenden'], ['GET', '/login']] as [$m, $pfad]) {
        $r = aufruf($basis, $admin['dose'], $m, $pfad);
        pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde' && !str_contains($r['text'], 'action="/logout"'), 'Kundenmodus: ' . $m . ' ' . $pfad . ' → Kundenseite');
    }
    // Ebenso die Seiten der Schnittstelle (Zugänge, andere Instanzen, Vorschläge):
    // Das Konto darf sie, das Gerät beim Kunden nicht – auch nicht absenden.
    $tokensVorher = (int) Database::scalar('SELECT COUNT(*) FROM api_tokens');
    $partnerVorher = Database::first('SELECT verified_at FROM api_partner WHERE id = ?', [$selbstId]);
    foreach ([['GET', '/system/schnittstelle', []], ['GET', '/system/schnittstelle/' . $zugangId, []], ['GET', '/instanzen', []],
        ['GET', '/instanzen/' . $selbstId, []], ['GET', '/vorschlaege', []], ['GET', '/vorschlaege/' . $vorschlagId, []],
        ['POST', '/system/schnittstelle/anlegen', ['name' => $kennung . ' im Kundenmodus', 'befugnisse' => ['plaene.lesen'], '_token' => $admin['token']]],
        ['POST', '/system/schnittstelle/' . $zugangId . '/anzeigen', ['kennwort' => $kennwort, '_token' => $admin['token']]],
        ['POST', '/instanzen/' . $selbstId . '/pruefen', ['_token' => $admin['token']]],
        ['POST', '/vorschlaege/' . $vorschlagId . '/ablehnen', ['grund' => 'im Kundenmodus', '_token' => $admin['token']]]] as [$m, $pfad, $felder]) {
        $r = aufruf($basis, $admin['dose'], $m, $pfad, $felder);
        pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde' && !str_contains($r['text'], 'mtma_'), 'Kundenmodus: ' . $m . ' ' . $pfad . ' → Kundenseite');
    }
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM api_tokens'), Database::scalar('SELECT status FROM api_vorschlaege WHERE id = ?', [$vorschlagId]),
        Database::first('SELECT verified_at FROM api_partner WHERE id = ?', [$selbstId])], [$tokensVorher, 'offen', $partnerVorher],
        'Kundenmodus: kein Zugang angelegt, kein Vorschlag abgelehnt, keine Instanz geprüft');
    // Ebenso der Tank: Seite, Verlauf, Tage, CSV, Peilung und Betankung.
    if (($tankId ?? 0) > 0) {
        $ereignisseVorher = (int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankId]);
        foreach (['/anlagen/' . $tankId, '/anlagen/' . $tankId . '/tank/csv', '/anlagen/' . $tankId . '/tank/csv?inhalt=protokoll',
            '/anlagen/' . $tankId . '/tank/csv?inhalt=tage'] as $pfad) {
            $r = aufruf($basis, $admin['dose'], 'GET', $pfad);
            pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde' && !str_contains($r['text'], 'Füllhöhe'), 'Kundenmodus: GET ' . $pfad . ' → Kundenseite');
        }
        foreach (['/anlagen/' . $tankId . '/tank/verlauf', '/anlagen/' . $tankId . '/tank/tage', '/anlagen/' . $tankId . '/live'] as $pfad) {
            $r = aufruf($basis, $admin['dose'], 'GET', $pfad, [], $json);
            pruefe($r['status'] === 423 && (json_decode($r['text'], true)['kundenmodus'] ?? false) === true, 'Kundenmodus: ' . $pfad . ' mit JSON → 423');
        }
        foreach (['peilung' => ['art' => 'liter', 'wert' => '100'], 'betankung' => ['liter' => '100']] as $aktion => $felder) {
            $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/' . $aktion, $felder + ['_token' => $admin['token']]);
            pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde', 'Kundenmodus: ' . $aktion . ' am Tank → Kundenseite');
            $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/tank/' . $aktion, $felder + ['_token' => $admin['token']], $json);
            gleich($r['status'], 423, 'Kundenmodus: ' . $aktion . ' am Tank (Kartenpanel, JSON) → 423');
        }
        gleich((int) Database::scalar('SELECT COUNT(*) FROM tank_ereignisse WHERE objekt_id = ?', [$tankId]), $ereignisseVorher, 'Kundenmodus: am Tank nichts eingetragen');
    }
    // /api/v1 hängt nicht an der Sitzung: mit Token antwortet sie wie sonst,
    // ohne Token mit 401 – nie mit der Kundenseite.
    $r = aufruf($basis, $admin['dose'], 'GET', '/api/v1/info', [], ['Authorization: Bearer ' . (string) ($selbstZugang['token'] ?? '')]);
    pruefe($r['status'] === 200 && (json_decode($r['text'], true)['ok'] ?? false) === true && $r['ziel'] === null, 'Kundenmodus: /api/v1/info mit Token wie sonst');
    $r = aufruf($basis, $admin['dose'], 'GET', '/api/v1/info');
    pruefe($r['status'] === 401 && $r['ziel'] === null && stripos($r['kopf'], 'application/json') !== false, 'Kundenmodus: /api/v1/info ohne Token → 401 JSON, keine Kundenseite');
    pruefe(!str_contains($kundeSeite['text'], 'action="/logout"'), 'Kundenseite ohne Abmelden');
    $r = aufruf($basis, $admin['dose'], 'POST', '/logout', ['_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde' && aufruf($basis, $admin['dose'], 'GET', '/dashboard')['ziel'] === '/berichte/' . $b3 . '/kunde',
        'Kundenmodus: Abmelden gesperrt – die Sitzung bleibt gesperrt');
    pruefe(str_contains((string) @file_get_contents($admin['dose']), "\tpm_kundenmodus\t"), 'Kundenmodus: Gerätecookie gesetzt');
    // Dasselbe Gerät, neue Sitzung (Ablauf, Inaktivität) und neue Anmeldung mit dem gespeicherten Kennwort: wieder gesperrt.
    $geraet = (string) tempnam(sys_get_temp_dir(), 'pm-web-');
    $dosen[] = $geraet;
    file_put_contents($geraet, "# Netscape HTTP Cookie File\n" . implode("\n", array_filter(explode("\n", (string) file_get_contents($admin['dose'])),
        static fn (string $z): bool => str_contains($z, "\tpm_kundenmodus\t"))) . "\n");
    $seite = aufruf($basis, $geraet, 'GET', '/login');
    aufruf($basis, $geraet, 'POST', '/login', ['username' => $kennung . '-admin', 'password' => $kennwort, '_token' => token($seite['text'])]);
    $r = aufruf($basis, $geraet, 'GET', '/dashboard');
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde', 'Kundenmodus am Gerät: nach neuer Anmeldung in diesem Browser weiter gesperrt');
    // Ein anderes Konto im selben Browser: Endet die Sitzung des Prüfers, ohne
    // zerstört zu werden (die Verwaltung setzt sein Kennwort zurück), bleiben
    // ihre Daten samt Sperre stehen, bis sich jemand anmeldet (ein anderes
    // Konto bekommt eine geleerte Sitzung, Auth::attempt()). Wer sich dann
    // anmeldete, erbte sonst die fremde Sperre: kein
    // Abmelden, Kundenseite und Entsperren ohne das Recht (Kundenmodus::ausweg()),
    // und seine Fehlversuche bremsten das Entsperren für den Prüfer. Die
    // Sperre gilt nur für das Konto, das sie gestartet hat – am Gerät gilt sie
    // weiter, sobald es sich wieder anmeldet.
    $hashVorher = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$benutzer['admin']]);
    Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('Zurueckgesetzt-' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT), $benutzer['admin']]);
    $ereignisseVorher = (int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ?', [$b3]);
    $fehlVorher = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.kundenmodus_fehlversuch' AND user_id = ?", [$benutzer['bkunde']]);
    $geraetVorher = Database::select('SELECT id, bericht_id, user_id FROM kundenmodus_geraete WHERE user_id = ? ORDER BY id', [$benutzer['admin']]);
    $r = aufruf($basis, $geraet, 'GET', '/dashboard');
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/login'), 'Kennwort des Prüfers zurückgesetzt: seine Sitzung im gesperrten Browser ist zu Ende');
    $seite = aufruf($basis, $geraet, 'GET', '/login');
    aufruf($basis, $geraet, 'POST', '/login', ['username' => $kennung . '-bkunde', 'password' => $kennwort, '_token' => token($seite['text'])]);
    $aStart = aufruf($basis, $geraet, 'GET', '/anlagen');
    $aListe = aufruf($basis, $geraet, 'GET', '/berichte');
    pruefe($aStart['status'] === 200 && $aStart['ziel'] === null && $aListe['status'] === 200 && $aListe['ziel'] === null,
        'anderes Konto im selben Browser: nicht gesperrt (' . $aStart['status'] . ' ' . (string) $aStart['ziel'] . ')');
    $aKunde = aufruf($basis, $geraet, 'GET', '/berichte/' . $b3 . '/kunde');
    // Das Token der Seite, die das Konto bekommt – auch der Kundenseite, wenn sie käme.
    $aToken = token($aStart['text']) ?: token($aKunde['text']);
    $aBeenden = [];
    for ($n = 1; $n <= Kundenmodus::HOECHSTENS_FEHL; $n++) {
        $aBeenden[] = aufruf($basis, $geraet, 'POST', '/berichte/' . $b3 . '/kunde/beenden', ['kennwort' => $kennwort, '_token' => $aToken])['status'];
    }
    pruefe($aKunde['status'] === 403 && !str_contains($aKunde['text'], 'kunde/beenden') && $aBeenden === array_fill(0, Kundenmodus::HOECHSTENS_FEHL, 403),
        'anderes Konto: Kundenseite und Entsperren des fremden Kundenmodus → 403 wie ohne Recht (' . $aKunde['status'] . ', ' . implode('/', $aBeenden) . ')');
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ?', [$b3]),
        (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.kundenmodus_fehlversuch' AND user_id = ?", [$benutzer['bkunde']]),
        Database::select('SELECT id, bericht_id, user_id FROM kundenmodus_geraete WHERE user_id = ? ORDER BY id', [$benutzer['admin']])],
        [$ereignisseVorher, $fehlVorher, $geraetVorher], 'anderes Konto: kein Ereignis am Bericht, kein Fehlversuch, die Sperre am Gerät bleibt');
    $r = aufruf($basis, $geraet, 'POST', '/logout', ['_token' => $aToken]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/login', 'anderes Konto: Abmelden geht (' . (string) $r['ziel'] . ')');
    // Ein Konto, das selbst übergeben darf („berichte.manage“, „bearbeiten“ am
    // Standort), startete hier sonst seinen eigenen Kundenmodus: Das löschte
    // die Sperre des Prüfers am Gerät und ersetzte das Cookie – nach dem
    // eigenen Entsperren und Abmelden war der Prüfer bei seiner nächsten
    // Anmeldung nicht mehr gesperrt, und am Bericht stand „kundenmodus_start“
    // (kein Zurückziehen mehr). Ebenso, wenn vom Prüfer nur das Gerätecookie
    // übrig ist (Sitzung abgelaufen). Solange die fremde Sperre gilt, startet
    // an diesem Gerät kein anderer Kundenmodus.
    $planerFreigabe = ['user', $benutzer['bplaner'], 'site', $bSite];
    Database::execute('UPDATE freigaben SET darf_bearbeiten = 1 WHERE subject_type = ? AND subject_id = ? AND ziel_art = ? AND ziel_id = ?', $planerFreigabe);
    $nurGeraet = (string) tempnam(sys_get_temp_dir(), 'pm-web-');
    $dosen[] = $nurGeraet;
    file_put_contents($nurGeraet, "# Netscape HTTP Cookie File\n" . implode("\n", array_filter(explode("\n", (string) file_get_contents($geraet)),
        static fn (string $z): bool => str_contains($z, "\tpm_kundenmodus\t"))) . "\n");
    $geraeteB3 = static fn (): array => Database::select('SELECT id, bericht_id, user_id, seit FROM kundenmodus_geraete WHERE bericht_id = ? ORDER BY id', [$b3]);
    $geraetecookie = static fn (string $dose): array => array_values(array_filter(explode("\n", (string) @file_get_contents($dose)),
        static fn (string $z): bool => str_contains($z, "\tpm_kundenmodus\t")));
    $vorher = [$geraeteB3(), (int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ?', [$b3]),
        (int) Database::scalar('SELECT version FROM berichte WHERE id = ?', [$b3])];
    foreach (['nach Kennwort-Rücksetzung' => $geraet, 'nur mit dem Gerätecookie' => $nurGeraet] as $fall => $dose) {
        $cookieVorher = $geraetecookie($dose);
        $seite = aufruf($basis, $dose, 'GET', '/login');
        aufruf($basis, $dose, 'POST', '/login', ['username' => $kennung . '-bplaner', 'password' => $kennwort, '_token' => token($seite['text'])]);
        $pSeite = aufruf($basis, $dose, 'GET', '/berichte/' . $b3);
        $pStart = aufruf($basis, $dose, 'POST', '/berichte/' . $b3 . '/kundenmodus', ['_token' => token($pSeite['text'])]);
        $pDanach = aufruf($basis, $dose, 'GET', '/berichte/' . $b3);
        pruefe($cookieVorher !== [] && $pSeite['status'] === 200 && str_contains($pSeite['text'], 'action="/berichte/' . $b3 . '/kundenmodus"') && $pStart['status'] === 302
            && $pStart['ziel'] === '/berichte/' . $b3 && $pDanach['status'] === 200 && str_contains($pDanach['text'], 'eines anderen Kontos gesperrt'),
            'Planer mit „bearbeiten“ (' . $fall . '): kein eigener Kundenmodus über die Sperre des Prüfers – ' . $pStart['status'] . ' ' . (string) $pStart['ziel']);
        gleich([$geraeteB3(), (int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ?', [$b3]),
            (int) Database::scalar('SELECT version FROM berichte WHERE id = ?', [$b3]), $geraetecookie($dose)], [...$vorher, $cookieVorher],
            'Planer (' . $fall . '): die Sperre des Prüfers am Gerät und das Cookie bleiben, kein Ereignis, keine neue Version');
        $r = aufruf($basis, $dose, 'POST', '/logout', ['_token' => token($pDanach['text'])]);
        pruefe($r['status'] === 302 && $r['ziel'] === '/login', 'Planer (' . $fall . '): Abmelden geht (' . (string) $r['ziel'] . ')');
    }
    Database::execute('UPDATE freigaben SET darf_bearbeiten = 0 WHERE subject_type = ? AND subject_id = ? AND ziel_art = ? AND ziel_id = ?', $planerFreigabe);
    Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hashVorher, $benutzer['admin']]);
    $seite = aufruf($basis, $geraet, 'GET', '/login');
    aufruf($basis, $geraet, 'POST', '/login', ['username' => $kennung . '-admin', 'password' => $kennwort, '_token' => token($seite['text'])]);
    $r = aufruf($basis, $geraet, 'GET', '/dashboard');
    $kSeite = aufruf($basis, $geraet, 'GET', '/berichte/' . $b3 . '/kunde');
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde' && $kSeite['status'] === 200 && str_contains($kSeite['text'], 'data-kunde="' . $b3 . '"')
        && !str_contains($kSeite['text'], 'Entsperren erst in'), 'danach der Prüfer im selben Browser: wieder gesperrt, auf seiner Kundenseite, ungebremst');
    // Vorläufiges Kennwort („muss ändern“ – der Prüfer hatte seins vergessen,
    // das Gerät ist gesperrt): Das Profil führte zur Kundenseite, die zum
    // Profil, im Kreis, und entsperren ließ sich nie. Die Kundenseite kommt,
    // das Entsperren landet beim Kennwort, nicht beim Profil.
    Database::execute('UPDATE users SET must_change_password = 1 WHERE id = ?', [$benutzer['admin']]);
    $kProfil = aufruf($basis, $geraet, 'GET', '/profil');
    $kSeite = aufruf($basis, $geraet, 'GET', '/berichte/' . $b3 . '/kunde');
    $kAntwort = aufruf($basis, $geraet, 'POST', '/berichte/' . $b3 . '/kunde/beenden', ['kennwort' => 'falsch', '_token' => token($kSeite['text'])]);
    $kDanach = aufruf($basis, $geraet, 'GET', '/berichte/' . $b3 . '/kunde');
    Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [$benutzer['admin']]);
    pruefe($kProfil['ziel'] === '/berichte/' . $b3 . '/kunde' && $kSeite['status'] === 200 && str_contains($kSeite['text'], 'action="/berichte/' . $b3 . '/kunde/beenden"'),
        'Kundenmodus mit vorläufigem Kennwort: Profil → Kundenseite, und die kommt (kein Kreis über /profil) – ' . $kSeite['status'] . ' ' . (string) $kSeite['ziel']);
    pruefe($kAntwort['status'] === 302 && $kAntwort['ziel'] === '/berichte/' . $b3 . '/kunde' && str_contains($kDanach['text'], 'Kennwort falsch'),
        'Kundenmodus mit vorläufigem Kennwort: Entsperren prüft das Kennwort, statt zum Profil zu führen – ' . (string) $kAntwort['ziel']);
    // Die Rolle endet, während das Gerät beim Kunden liegt: Kundenseite und
    // Entsperren gaben 403, alles andere führte zur Kundenseite – eine
    // Sackgasse. Jetzt zeigt die Kundenseite nur das Entsperren, und das
    // Kennwort dessen, der übergeben hat, entsperrt.
    $rollen = Database::select('SELECT id, gueltig_bis FROM user_roles WHERE user_id = ?', [$benutzer['admin']]);
    Database::execute('UPDATE user_roles SET gueltig_bis = ? WHERE user_id = ?', [gmdate('c', time() - 60), $benutzer['admin']]);
    $kSeite = aufruf($basis, $geraet, 'GET', '/berichte/' . $b3 . '/kunde');
    $kPdf = aufruf($basis, $geraet, 'GET', '/berichte/' . $b3 . '/pdf');
    $kAntwort = aufruf($basis, $geraet, 'POST', '/berichte/' . $b3 . '/kunde/beenden', ['kennwort' => $kennwort, '_token' => token($kSeite['text'])]);
    $kDanach = aufruf($basis, $geraet, 'GET', (string) $kAntwort['ziel']);
    foreach ($rollen as $z) {
        Database::execute('UPDATE user_roles SET gueltig_bis = ? WHERE id = ?', [$z['gueltig_bis'], $z['id']]);
    }
    pruefe($rollen !== [] && $kSeite['status'] === 200 && str_contains($kSeite['text'], 'Gerät gesperrt') && str_contains($kSeite['text'], 'action="/berichte/' . $b3 . '/kunde/beenden"')
        && !str_contains($kSeite['text'], 'data-kunde="') && !str_contains($kSeite['text'], 'class="sidebar'),
        'Kundenmodus, Rolle abgelaufen: die Kundenseite zeigt nur das Entsperren statt 403 (' . $kSeite['status'] . ')');
    gleich($kPdf['status'], 403, 'Kundenmodus, Rolle abgelaufen: das PDF bleibt beim Recht der Route');
    // Zurück zum Bericht ginge nicht (403 ohne „berichte.view“): Es geht zur
    // ersten Seite, die das Konto sehen darf – ohne Rolle das Profil.
    pruefe($kAntwort['status'] === 302 && $kAntwort['ziel'] === '/profil' && $kDanach['status'] === 200 && str_contains($kDanach['text'], 'Kundenmodus beendet'),
        'Kundenmodus, Rolle abgelaufen: mit dem Kennwort entsperrt, danach das Profil statt 403 (' . $kAntwort['status'] . ' ' . (string) $kAntwort['ziel'] . ' → ' . $kDanach['status'] . ')');
    pruefe(aufruf($basis, $admin['dose'], 'GET', '/dashboard')['ziel'] === '/berichte/' . $b3 . '/kunde', 'Kundenmodus: die Sitzung, die übergeben hat, bleibt gesperrt');
    $kundeFelder = ['striche' => unterschriftStriche(), 'name' => 'Kim Kunde', 'funktion' => 'Produktion', 'ort' => 'Musterstadt', 'vorbehalte' => '',
        'anfrage' => bin2hex(random_bytes(16)), 'geraet_am' => gmdate('c'), '_token' => $admin['token'], 'inhalt_hash' => (string) BerichtService::find($b3)['inhalt_hash']];
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kunde/unterschreiben', ['inhalt_hash' => str_repeat('0', 64)] + $kundeFelder + ['bestaetigungen' => ['erhalten']], $json);
    pruefe($r['status'] === 409 && BerichtService::find($b3)['status'] === 'geprueft', 'Kunde hat einen anderen Stand gesehen → 409, nichts gespeichert');
    pruefe(str_contains($kundeSeite['text'], 'data-inhalt-hash="' . BerichtService::find($b3)['inhalt_hash'] . '"'), 'Kundenseite trägt den angezeigten Stand');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kunde/unterschreiben', $kundeFelder + ['bestaetigungen' => ['eingewiesen']], $json);
    pruefe($r['status'] === 422 && BerichtService::find($b3)['status'] === 'geprueft', 'Kunde ohne „Bericht erhalten“ → 422');
    $mit = $kundeFelder + ['bestaetigungen' => ['erhalten', 'uebernommen']];
    $r1 = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kunde/unterschreiben', $mit, $json);
    $r2 = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kunde/unterschreiben', $mit, $json);
    pruefe($r1['status'] === 200 && $r2['status'] === 200 && BerichtService::find($b3)['status'] === 'abgeschlossen'
        && (int) Database::scalar("SELECT COUNT(*) FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'kunde'", [$b3]) === 1,
        'Kundenunterschrift doppelt gesendet → eine Zeile, abgeschlossen');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3 . '/kunde');
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Bitte geben Sie das Gerät an') && str_contains($r['text'], 'Pia Prüferin')
        && str_contains($r['text'], 'action="/berichte/' . $b3 . '/kunde/beenden"'), 'danach: „Gerät zurückgeben“ mit Kennwortfeld');
    pruefe(!str_contains($r['text'], 'in Ihrem Kundenbereich'), 'ohne Zuordnung kein Hinweis auf den Kundenbereich');
    // Einem Kunden mit eigenem Zugang zugeordnet: der Hinweis, dass der Bericht dort bereitsteht.
    Database::update('berichte', $b3, ['kunde_id' => $kundeId]);
    $kbZugang = Database::insert('users', ['username' => $kennung . '-kb-zugang', 'full_name' => '', 'email' => '', 'password_hash' => password_hash($kennwort, PASSWORD_DEFAULT),
        'is_active' => 1, 'kunde_id' => $kundeId, 'created_at' => gmdate('c')]);
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3 . '/kunde');
    pruefe(str_contains($r['text'], 'Der Bericht steht ab sofort in Ihrem Kundenbereich bereit.'), 'zugeordnet, Kunde mit Zugang: „steht ab sofort in Ihrem Kundenbereich bereit“');
    Database::delete('users', $kbZugang);
    Database::update('berichte', $b3, ['kunde_id' => null]);
    $fehlVorher = (int) Database::scalar('SELECT failed_logins FROM users WHERE id = ?', [$benutzer['admin']]);
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kunde/beenden', ['kennwort' => 'falsch', '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3 . '/kunde'
        && aufruf($basis, $admin['dose'], 'GET', '/dashboard')['ziel'] === '/berichte/' . $b3 . '/kunde', 'falsches Kennwort: Sperre bleibt');
    pruefe(Database::scalar('SELECT locked_until FROM users WHERE id = ?', [$benutzer['admin']]) === null
        && (int) Database::scalar('SELECT failed_logins FROM users WHERE id = ?', [$benutzer['admin']]) === $fehlVorher, 'falsches Kennwort: Konto nicht gesperrt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/kunde/beenden', ['kennwort' => $kennwort, '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b3, 'richtiges Kennwort → zurück zum Bericht');
    $r = aufruf($basis, $admin['dose'], 'GET', '/dashboard');
    pruefe($r['status'] === 200, 'entsperrt: Dashboard wieder offen');
    $alterToken = $admin['token'];
    $admin['token'] = token($r['text']);
    pruefe($admin['token'] !== '' && $admin['token'] !== $alterToken, 'entsperrt: neues CSRF-Token');
    $ereignisse = array_column(BerichtService::ereignisse($b3), 'ereignis');
    pruefe(in_array('kundenmodus_start', $ereignisse, true) && in_array('kunde_unterschrieben', $ereignisse, true) && in_array('kundenmodus_ende', $ereignisse, true),
        'Ereignisse: Kundenmodus, Kundenunterschrift, Ende');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3);
    pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], 'Kim Kunde') && substr_count($r['text'], '<svg class="unterschrift-svg"') === 2
        && str_contains($r['text'], 'action="/berichte/' . $b3 . '/korrektur"'), 'abgeschlossen: beide Unterschriften, Korrektur möglich');
    $ohneSkript('/berichte/' . $b3 . ' (abgeschlossen)', $r['text']);
    $pdfB3 = (string) BerichtService::pdfHash($b3);
    pruefe($pdfB3 !== '' && $pdfB3 === (string) BerichtService::find($b3)['pdf_hash'] && str_contains($r['text'], $pdfB3) && str_contains($r['text'], 'data-teilen="/berichte/' . $b3 . '/pdf"')
        && str_contains($r['text'], '/assets/js/bericht-pdf.js') && str_contains($r['text'], 'download="' . BerichtService::find($b3)['nummer'] . '.pdf"'),
        'abgeschlossen: PDF ansehen, herunterladen, teilen, Prüfsumme der Datei');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3 . '/pdf');
    pruefe($pdfAntwort($r, BerichtService::find($b3)['nummer'] . '.pdf') && hash('sha256', $r['text']) === $pdfB3, 'abgeschlossen: ausgeliefert werden die gespeicherten Bytes');
    $r2 = aufruf($basis, $bkunde['dose'], 'GET', '/berichte/' . $b3 . '/pdf');
    pruefe($r2['status'] === 200 && $r2['text'] === $r['text'], 'Kunde mit Freigabe bekommt dieselbe Datei');
    $r = aufruf($basis, $bkunde['dose'], 'GET', '/berichte/' . $b3);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'Kim Kunde') && !str_contains($r['text'], '/korrektur"') && !str_contains($r['text'], 'Verlauf'),
        'Kunde mit Freigabe sieht den Bericht mit Unterschriften, ohne Knöpfe und Verlauf');
    gleich(aufruf($basis, $bkunde['dose'], 'POST', '/berichte/' . $b3 . '/korrektur', ['grund' => 'xyz', '_token' => $bkunde['token']])['status'], 403, 'Kunde: keine Korrektur');

    // Fünf falsche Kennwörter: abgemeldet, das Konto bleibt offen; ohne Kunde abschließen; zurückziehen
    $b4 = $neuerBericht();
    $warnungen = array_column($ausfuellen($b4)['warnungen'] ?? [], 'code');
    pruefe($unterschreiben($b4, ['warnungen' => $warnungen])['status'] === 200, 'zweiter Bericht unterschrieben');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b4 . '/zurueckziehen', ['grund' => 'Ort falsch', '_token' => $admin['token']]);
    $b = BerichtService::find($b4);
    pruefe($r['status'] === 302 && $b['status'] === 'entwurf' && $b['nummer'] !== null, 'zurückgezogen: Entwurf mit Nummer');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b4);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'data-bericht="' . $b4 . '"') && str_contains($r['text'], 'action="/berichte/' . $b4 . '/verwerfen"')
        && !str_contains($r['text'], 'action="/berichte/' . $b4 . '/loeschen"'), 'zurückgezogen: wieder Formular, verwerfen statt löschen');
    pruefe($unterschreiben($b4, ['warnungen' => $warnungen])['status'] === 200 && BerichtService::find($b4)['nummer'] === $b['nummer'], 'neu unterschrieben, gleiche Nummer');
    aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b4 . '/kundenmodus', ['_token' => $admin['token']]);
    gleich(aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b4 . '/ohne-kunde', ['art' => 'abwesend', 'grund' => 'xyz', '_token' => $admin['token']])['ziel'],
        '/berichte/' . $b4 . '/kunde', 'Kundenmodus: „ohne Kunde“ gesperrt');
    for ($n = 1; $n <= 5; $n++) {
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b4 . '/kunde/beenden', ['kennwort' => 'falsch-' . $n, '_token' => $admin['token']]);
    }
    pruefe($r['status'] === 302 && $r['ziel'] === '/berichte/' . $b4 . '/kunde' && aufruf($basis, $admin['dose'], 'GET', '/dashboard')['ziel'] === '/berichte/' . $b4 . '/kunde',
        'fünf falsche Kennwörter → nicht abgemeldet, weiter gesperrt');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b4 . '/kunde/beenden', ['kennwort' => $kennwort, '_token' => $admin['token']]);
    pruefe($r['ziel'] === '/berichte/' . $b4 . '/kunde' && aufruf($basis, $admin['dose'], 'GET', '/dashboard')['ziel'] === '/berichte/' . $b4 . '/kunde',
        'in der Wartezeit entsperrt auch das richtige Kennwort nicht');
    pruefe(str_contains(aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b4 . '/kunde')['text'], 'Entsperren erst in'), 'Kundenseite nennt die Wartezeit');
    pruefe(Database::scalar('SELECT locked_until FROM users WHERE id = ?', [$benutzer['admin']]) === null, 'Konto nach fünf Fehlversuchen nicht gesperrt');
    pruefe(in_array('kundenmodus_gebremst', array_column(BerichtService::ereignisse($b4), 'ereignis'), true), 'Ereignis „kundenmodus_gebremst“');
    // Ein anderes Gerät (ohne Gerätecookie) ist nicht gesperrt.
    $admin = anmelden($basis, $kennung . '-admin', $kennwort);
    $dosen[] = $admin['dose'];
    gleich(aufruf($basis, $admin['dose'], 'GET', '/dashboard')['status'], 200, 'anderes Gerät, neu angemeldet: ohne Kundenmodus');
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b4 . '/ohne-kunde', ['art' => 'verweigert', 'grund' => 'Kunde verweigert die Unterschrift', '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && BerichtService::find($b4)['status'] === 'abgeschlossen', 'ohne Kunde abgeschlossen');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b4);
    pruefe(phpMeldung($r['text']) === null && str_contains($r['text'], 'Ohne Unterschrift des Kunden') && str_contains($r['text'], 'Kunde verweigert die Unterschrift'),
        'Leseansicht: ohne Kunde mit Grund');
    pruefe(BerichtService::pdfHash($b4) !== null && str_contains($r['text'], (string) BerichtService::pdfHash($b4)), 'ohne Kunde: PDF gespeichert und auf der Seite');

    // Korrektur: neue Fassung im Formular, der Vorgänger gilt weiter
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $b3 . '/korrektur', ['grund' => 'Querschnitt nachgetragen', '_token' => $admin['token']]);
    $k = (int) Database::scalar('SELECT id FROM berichte WHERE vorgaenger_id = ?', [$b3]);
    pruefe($k > 0 && $r['status'] === 302 && $r['ziel'] === '/berichte/' . $k && BerichtService::find($b3)['status'] === 'abgeschlossen', 'Korrektur angelegt → Formular der neuen Fassung');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $k);
    pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], 'Korrektur (Fassung 2)') && str_contains($r['text'], 'Querschnitt nachgetragen'),
        'Formular der Korrektur nennt Fassung und Grund');
    $r = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $b3);
    pruefe(str_contains($r['text'], 'Eine Korrektur ist in Arbeit') && !str_contains($r['text'], 'action="/berichte/' . $b3 . '/korrektur"'), 'Vorgänger: Hinweis auf die offene Korrektur');
    gleich(serverMeldungen($serverFehler), [], 'keine PHP-Meldung im Fehlerprotokoll des Servers');
} finally {
    foreach (Database::select('SELECT id FROM api_partner WHERE name LIKE ?', [$kennung . '%']) as $p) {
        \App\Services\PartnerService::loeschen((int) $p['id']);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_partner' AND target_id = ?", [(string) $p['id']]);
    }
    foreach (Database::select('SELECT id FROM sites WHERE name LIKE ?', [$kennung . '%']) as $s) {
        $auditZiele[] = ['site', (int) $s['id']];
        foreach (['floor' => 'floors', 'objekt' => 'objekte'] as $typ => $tabelle) {
            foreach (Database::select("SELECT id FROM {$tabelle} WHERE site_id = ?", [(int) $s['id']]) as $z) {
                $auditZiele[] = [$typ, (int) $z['id']];
            }
        }
        \App\Services\SiteService::deleteSite((int) $s['id']);
    }
    foreach (Database::select('SELECT id FROM api_tokens WHERE name LIKE ?', [$kennung . '%']) as $t) {
        foreach (Database::select('SELECT id FROM api_vorschlaege WHERE token_id = ?', [(int) $t['id']]) as $v) {
            Database::execute('DELETE FROM api_vorschlaege WHERE id = ?', [(int) $v['id']]);
            Database::execute("DELETE FROM audit_log WHERE target_type = 'api_vorschlag' AND target_id = ?", [(string) $v['id']]);
        }
        Database::execute('DELETE FROM api_token_standorte WHERE token_id = ?', [(int) $t['id']]);
        Database::execute('DELETE FROM api_tokens WHERE id = ?', [(int) $t['id']]);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_token' AND target_id = ?", [(string) $t['id']]);
    }
    Settings::vergessen();
    foreach ($eventworxVorher as $s => $wert) {
        $wert === null ? Settings::forget($s) : Settings::put($s, $wert);
    }
    Database::execute('DELETE FROM steckertypen WHERE name LIKE ?', [$kennung . '%']);
    Database::execute('DELETE FROM kunden WHERE name LIKE ?', [$kennung . '%']);
    foreach (Database::select('SELECT id FROM roles WHERE name LIKE ?', [$kennung . '%']) as $rolle) {
        $auditZiele[] = ['role', (int) $rolle['id']];
        BenutzerService::rolleLoeschen((int) $rolle['id']);
    }
    Database::execute('DELETE FROM leitungen WHERE name LIKE ?', [$kennung . '%']);
    foreach (Database::select('SELECT id FROM inventar WHERE kennung LIKE ?', [$kennung . '%']) as $i) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), (int) $i['id']]);
        Database::execute('UPDATE messpunkte SET gueltig_bis = ? WHERE geraet LIKE ? AND gueltig_bis IS NULL', [gmdate('c'), $kennung . '%']);
        \App\Services\InventarService::loeschen((int) $i['id']);
    }
    Database::execute('DELETE FROM messpunkte WHERE geraet LIKE ?', [$kennung . '%']);
    foreach (Database::select('SELECT id FROM bereiche WHERE name LIKE ?', [$kennung . '%']) as $b) {
        BereichService::loeschen((int) $b['id']);
    }
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ?', [$kennung . '%']) as $o) {
        $auditZiele[] = ['objekt', (int) $o['id']];
        \App\Services\AnlagenService::loeschen((int) $o['id']);
    }
    foreach ($benutzer as $id) {
        foreach (Database::select('SELECT id FROM berichte WHERE erstellt_von = ?', [$id]) as $b) {
            foreach (['bericht_unterschriften', 'bericht_pdf', 'bericht_objekte', 'bericht_ereignisse', 'kundenmodus_geraete'] as $tabelle) {
                Database::execute("DELETE FROM {$tabelle} WHERE bericht_id = ?", [(int) $b['id']]);
            }
            Database::delete('berichte', (int) $b['id']);
        }
        Database::execute('DELETE FROM kundenmodus_geraete WHERE user_id = ?', [$id]);
    }
    foreach (Database::select('SELECT jahr, laufnr FROM bericht_nummern') as $z) {
        if (!array_key_exists($z['jahr'], $nummernVorher)) {
            Database::execute('DELETE FROM bericht_nummern WHERE jahr = ?', [$z['jahr']]);
        } elseif ((int) $nummernVorher[$z['jahr']] !== (int) $z['laufnr']) {
            Database::execute('UPDATE bericht_nummern SET laufnr = ? WHERE jahr = ?', [(int) $nummernVorher[$z['jahr']], $z['jahr']]);
        }
    }
    Database::execute('DELETE FROM pruefgeraete WHERE fabrikat LIKE ?', [$kennung . '%']);
    if ($bSite > 0) {
        \App\Services\SiteService::deleteSite($bSite);
    }
    if ($planSite > 0) {
        \App\Services\SiteService::deleteSite($planSite);   // samt Plandatei
    }
    foreach ($benutzer as $id) {
        Database::execute('DELETE FROM schaltvorgaenge WHERE user_id = ?', [$id]);
        BenutzerService::loeschen($id);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennung . '%', '%' . $kennung . '%']);
    foreach ($benutzer as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach ($auditZiele as [$typ, $id]) {
        Database::execute('DELETE FROM audit_log WHERE id > ? AND target_type = ? AND target_id = ?', [$auditVorher, $typ, (string) $id]);
    }
    foreach ($dosen as $d) {
        @unlink($d);
    }
}
@unlink($serverFehler);
gleich(array_map(static fn (array $z): string => $z['action'] . ' ' . $z['target_type'] . ' ' . $z['target_id'],
    Database::select('SELECT action, target_type, target_id FROM audit_log WHERE id > ? ORDER BY id', [$auditVorher])), [], 'aufgeräumt: kein Audit-Eintrag dieses Laufs übrig');

ende('web');
