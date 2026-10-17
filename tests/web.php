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
use App\Services\BenutzerService;
use App\Services\BereichService;
use App\Services\Eventworx;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;

$port = freierPort();
// Ohne app.debug zeigt der Server PHP-Meldungen nicht in der Seite, er schreibt
// sie auf die Standardfehlerausgabe – die landet hier und wird mitgeprüft.
$serverFehler = (string) tempnam(sys_get_temp_dir(), 'pm-web-fehler-');
hintergrund(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public'], null, $serverFehler);
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
foreach (array_keys(Einstellungen::VORGABEN) as $s) {
    $eventworxVorher[$s] = Settings::get($s);
}
$bSite = 0;
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
        '/system/einstellungen', '/audit', '/anlagen?fest=1', '/anlagen?fest=0', '/anlagen/neu?art=anschluss&fest=1',
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
    array_push($seiten, '/inventar', '/inventar?art=zwischenstecker', '/inventar?suche=PD', '/inventar/neu?art=verteilung', '/inventar/neu?art=zwischenstecker');
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

    // ---------------------------------------------------- Kunde ohne Freigabe
    $kunde = anmelden($basis, $kennung . '-kunde', $kennwort);
    $dosen[] = $kunde['dose'];
    foreach (['/datenquellen', '/system/benutzer', '/system/einstellungen', '/audit', '/stecker', '/standorte', '/anlagen/neu', '/steuerung/protokoll', '/bereiche/neu',
        '/inventar', '/inventar/neu', '/verteiler-abgleich', '/location'] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ' . $pfad);
    }
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
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'site', 'ziel_id' => $werk, 'darf_bearbeiten' => 1]);
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'site', 'ziel_id' => (int) $fremd['site_id']]);
        $planer = anmelden($basis, $kennung . '-planer', $kennwort);
        $dosen[] = $planer['dose'];
        $json = ['Accept: application/json'];
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
    $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/anlegen', ['site_id' => $bSite, 'aufbau_id' => $bAufbau, 'objekte' => [$bVert], '_token' => $admin['token']]);
    $bId = (int) Database::scalar('SELECT id FROM berichte WHERE site_id = ? ORDER BY id DESC LIMIT 1', [$bSite]);
    pruefe($bId > 0 && $r['status'] === 302 && $r['ziel'] === '/berichte/' . $bId, 'Bericht angelegt → Formular ' . $r['ziel']);
    $form = aufruf($basis, $admin['dose'], 'GET', '/berichte/' . $bId);
    pruefe($form['status'] === 200 && str_contains($form['text'], 'data-bericht="' . $bId . '"') && phpMeldung($form['text']) === null, 'Formular des Entwurfs');
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
    /** Eine gültige Unterschrift aus ganzen Zahlen – jedes Mal andere Striche. */
    $striche = static function (): string {
        $versatz = random_int(0, 400);
        $s = [];
        for ($zug = 0; $zug < 2; $zug++) {
            $linie = [];
            for ($x = 1000; $x <= 8600; $x += 380) {
                $linie[] = [$x + $versatz, 2500 + intdiv($x, 380) % 2 * 4000 + $zug * 300];
            }
            $s[] = $linie;
        }
        return json_encode(['s' => $s]);
    };
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
    $unterschreiben = static function (int $id, array $felder = []) use ($basis, &$admin, $json, $striche): array {
        $r = aufruf($basis, $admin['dose'], 'POST', '/berichte/' . $id . '/unterschreiben', $felder + ['version' => BerichtService::find($id)['version'],
            'striche' => $striche(), 'name' => 'Pia Prüferin', 'ort' => 'Musterstadt', 'anfrage' => bin2hex(random_bytes(16)),
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
    $kundeFelder = ['striche' => $striche(), 'name' => 'Kim Kunde', 'funktion' => 'Produktion', 'ort' => 'Musterstadt', 'vorbehalte' => '',
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
    Settings::vergessen();
    foreach ($eventworxVorher as $s => $wert) {
        $wert === null ? Settings::forget($s) : Settings::put($s, $wert);
    }
    Database::execute('DELETE FROM steckertypen WHERE name LIKE ?', [$kennung . '%']);
    foreach (Database::select('SELECT id FROM roles WHERE name LIKE ?', [$kennung . '%']) as $rolle) {
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
        Database::execute("DELETE FROM audit_log WHERE target_type = 'site' AND target_id = ?", [(string) $bSite]);
    }
    foreach ($benutzer as $id) {
        Database::execute('DELETE FROM schaltvorgaenge WHERE user_id = ?', [$id]);
        BenutzerService::loeschen($id);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennung . '%', '%' . $kennung . '%']);
    foreach ($benutzer as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach ($dosen as $d) {
        @unlink($d);
    }
}
@unlink($serverFehler);

ende('web');
