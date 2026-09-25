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
hintergrund(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public']);
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
        '/stecker', '/standorte', '/location', '/statistik', '/statistik?zeitraum=7d', '/statistik?zeitraum=frei&von=2026-01-01&bis=2026-01-02',
        '/datenquellen', '/datenquellen/profile', '/steuerung/protokoll', '/system/benutzer', '/system/freigaben?art=user&id=' . $benutzer['kunde'],
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

    // ---------------------------------------------------- Kunde ohne Freigabe
    $kunde = anmelden($basis, $kennung . '-kunde', $kennwort);
    $dosen[] = $kunde['dose'];
    foreach (['/datenquellen', '/system/benutzer', '/system/einstellungen', '/audit', '/stecker', '/standorte', '/anlagen/neu', '/steuerung/protokoll', '/bereiche/neu',
        '/inventar', '/inventar/neu'] as $pfad) {
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
} finally {
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
