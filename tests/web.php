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

use App\Services\BenutzerService;
use App\Services\BereichService;
use App\Support\Database;

$port = freierPort();
// Mehrere Arbeiter: eine Seite, die die eigene Schnittstelle aufruft (eine
// andere Instanz über die Loopback-Adresse), wartete sonst auf sich selbst.
hintergrund(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4']);
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

/** PHP-Meldungen im ausgelieferten HTML (display_errors des eingebauten Servers). */
function phpMeldung(string $text): ?string
{
    return preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:.*?(?=<br|$)/s', $text, $m) ? strip_tags($m[0]) : null;
}

$kennung = 'pm-web-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6));
$benutzer = [];
$dosen = [];

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
    gleich(aufruf($basis, $admin['dose'], 'GET', '/gibt-es-nicht')['status'], 404, 'unbekannte Adresse → 404');
    gleich(aufruf($basis, $admin['dose'], 'GET', '/system/freigaben?id=0')['status'], 404, 'Freigaben ohne Benutzer → 404');

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
        '/inventar', '/inventar/neu', '/verteiler-abgleich', '/location', '/system/schnittstelle', '/system/schnittstelle/' . $zugangId] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ' . $pfad);
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
} finally {
    foreach (Database::select('SELECT id FROM api_partner WHERE name LIKE ?', [$kennung . '%']) as $p) {
        \App\Services\PartnerService::loeschen((int) $p['id']);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_partner' AND target_id = ?", [(string) $p['id']]);
    }
    foreach (Database::select('SELECT id FROM sites WHERE name LIKE ?', [$kennung . '%']) as $s) {
        $etagen = array_column(Database::select('SELECT id FROM floors WHERE site_id = ?', [(int) $s['id']]), 'id');
        \App\Services\SiteService::deleteSite((int) $s['id']);
        Database::execute("DELETE FROM audit_log WHERE action = 'site.delete' AND target_id = ?", [(string) $s['id']]);
        foreach ($etagen as $etage) {
            Database::execute("DELETE FROM audit_log WHERE action = 'floor.delete' AND target_id = ?", [(string) $etage]);
        }
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

ende('web');
