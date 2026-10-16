<?php
declare(strict_types=1);

/**
 * Schnittstelle (API), Seite A: was diese Instanz an andere herausgibt.
 *
 * Ein eigener PHP-Server; die Schnittstelle wird von außen aufgerufen wie
 * von MasterAdmin oder einer zweiten PowerManagement-Instanz. Geprüft wird:
 * Token (Form, Ablage, Befugnisse), immer dieselbe 401, keine Sitzung und
 * nur JSON (405/400/413/415, HEAD, Fehler als JSON), der Zuschnitt (nur
 * freigegebene Standorte, nur Feststrom, keine Messpunkte, keine
 * Dosen/Racks/APs, nichts Mobiles), der Stand, die Plandatei nur über die
 * Liste erlaubter Pfade, „zuletzt benutzt“ und dass Token und
 * verschlüsseltes Token weder im Audit noch im Log landen. Dazu die
 * Oberfläche mit Anmeldung und echtem CSRF-Token: anlegen (Token nur in der
 * Antwort, einmal), anzeigen mit Kennwort, widerrufen, löschen, ändern.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\ApiZugang;
use App\Services\AnlagenService;
use App\Services\ApiZugaenge;
use App\Services\BenutzerService;
use App\Services\LeitungService;
use App\Services\MesspunktService;
use App\Services\PlanGeoService;
use App\Services\PlanMassstabService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Crypto;
use App\Support\Database;

if (!function_exists('curl_init')) {
    uebersprungen('schnittstelle', 'die PHP-Erweiterung curl fehlt');
}

$wurzel = dirname(__DIR__);
$port = freierPort();
hintergrund([PHP_BINARY, '-d', 'post_max_size=66M', '-d', 'memory_limit=512M', '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public']);
// Ein zweiter Server mit knappem Speicher: dort endet ein großer Körper in
// einem fatalen Fehler – auch der muss als JSON ankommen.
$portKnapp = freierPort();
hintergrund([PHP_BINARY, '-d', 'post_max_size=66M', '-d', 'memory_limit=24M', '-S', '127.0.0.1:' . $portKnapp, '-t', $wurzel . '/public']);
for ($i = 0; $i < 50 && (!erreichbar('127.0.0.1', $port) || !erreichbar('127.0.0.1', $portKnapp)); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $port)) {
    uebersprungen('schnittstelle', 'eingebauter PHP-Server startet nicht');
}
$basis = 'http://127.0.0.1:' . $port;

/** Alle Antworten der Schnittstelle – am Ende auf Set-Cookie und Location prüfen. */
$GLOBALS['__antworten'] = [];
function a(string $methode, string $pfad, ?string $token, ?string $rumpf = null, array $kopf = [], ?string $basisFrei = null): array
{
    $r = api($basisFrei ?? $GLOBALS['__basis'], $methode, $pfad, $token, $rumpf, $kopf);
    $GLOBALS['__antworten'][] = $methode . ' ' . $pfad . "\n" . $r['kopf'];
    return $r;
}
$GLOBALS['__basis'] = $basis;

/** Ein Aufruf der Oberfläche mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function ui(string $basis, string $dose, string $methode, string $pfad, array $felder = []): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($methode === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    $roh = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $kopfLaenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $kopf = substr($roh, 0, $kopfLaenge);
    preg_match('/^Location:\s*(\S+)/mi', $kopf, $m);
    return ['status' => $status, 'kopf' => $kopf, 'text' => substr($roh, $kopfLaenge), 'ziel' => $m[1] ?? null];
}

function csrf(string $html): string
{
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

/** @return array{dose:string,token:string} */
function uiAnmelden(string $basis, string $name, string $kennwort): array
{
    $dose = tempnam(sys_get_temp_dir(), 'pm-api-');
    $seite = ui($basis, $dose, 'GET', '/login');
    $r = ui($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => csrf($seite['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] !== '/login', 'Anmeldung ' . $name);
    return ['dose' => $dose, 'token' => csrf(ui($basis, $dose, 'GET', '/profil')['text'])];
}

function phpMeldung(string $text): ?string
{
    return preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:.*?(?=<br|$)/s', $text, $m) ? strip_tags($m[0]) : null;
}

$marke = 'pm-api-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '1';
$benutzer = [];
$rollen = [];
$sites = [];
$dosen = [];
$geheim = [];          // Token und token_enc – dürfen nirgends auftauchen
$tmpDateien = [];
$logVorher = @filesize($wurzel . '/storage/logs/app.log') ?: 0;

/** Ein Token als Admin anlegen; merkt sich Token und Verschlüsseltes. */
$anlegen = static function (array $e) use (&$geheim): array {
    $r = ApiZugaenge::anlegen($e);
    if ($r['ok']) {
        $geheim[] = (string) $r['token'];
        $geheim[] = (string) Database::scalar('SELECT token_enc FROM api_tokens WHERE id = ?', [(int) $r['id']]);
    }
    return $r;
};

try {
    // ------------------------------------------------ Bestand
    $benutzer['admin'] = BenutzerService::anlegen($marke . '-admin', $kennwort, 'admin', false);
    $rollen['verwalter'] = (int) BenutzerService::rolleSpeichern(null, $marke . ' verwalter', '', ['api.manage', 'karte.view', 'plans.manage', 'dashboard.view'])['id'];
    $rolleKey = static fn (int $id): string => (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$id]);
    $benutzer['verwalter'] = BenutzerService::anlegen($marke . '-verwalter', $kennwort, $rolleKey($rollen['verwalter']), false);
    foreach (['inaktiv', 'geloescht', 'abgelaufen', 'gebunden'] as $n) {
        $benutzer[$n] = BenutzerService::anlegen($marke . '-' . $n, $kennwort, 'kunde', false);
    }
    alsBenutzer($benutzer['admin']);

    $sites['halle'] = SiteService::saveSite(null, ['name' => $marke . ' Halle', 'address' => 'Halle 1', 'notes' => 'Prüfung']);
    $f1 = SiteService::saveFloor(null, ['site_id' => $sites['halle'], 'name' => 'Erdgeschoss', 'level_no' => 0, 'sort_order' => 0]);
    $f2 = SiteService::saveFloor(null, ['site_id' => $sites['halle'], 'name' => 'Obergeschoss', 'level_no' => 1, 'sort_order' => 1]);
    $sites['fremd'] = SiteService::saveSite(null, ['name' => $marke . ' Fremd']);
    $ff = SiteService::saveFloor(null, ['site_id' => $sites['fremd'], 'name' => 'Halle']);
    $hochladen = static function (int $siteId, int $floorId) use (&$tmpDateien): array {
        $tmp = tempnam(sys_get_temp_dir(), 'pm-api-plan-');
        $tmpDateien[] = $tmp;
        copy(__DIR__ . '/hilfen/location-lager/plaene/grundriss.svg', $tmp);
        return SiteService::uploadPlan($siteId, ['name' => 'grundriss.svg', 'type' => 'image/svg+xml', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], ['floor_ids' => [$floorId]]);
    };
    pruefe($hochladen($sites['halle'], $f1)['ok'] && $hochladen($sites['fremd'], $ff)['ok'], 'Grundrisse hochgeladen');
    PlanMassstabService::speichern($f1, ['x' => 10.0, 'y' => 90.0], ['x' => 60.0, 'y' => 90.0], 120.0, 1.0);
    $kx = 6371008.8 * M_PI / 180 * cos(deg2rad(51.44));
    pruefe(PlanGeoService::speichern($f1, ['x' => 10.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56],
        ['x' => 60.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56 + 100 / $kx], 1.0)['ok'], 'Georeferenz gesetzt');
    // Fremdbestand aus MasterAdmin – geht nie hinaus
    Database::execute('UPDATE floors SET fremdbestand = ? WHERE id = ?', [json_encode(['dosen' => [['schluessel' => 'd1', 'x' => 1, 'y' => 1]],
        'racks' => [['schluessel' => 'r1']], 'aps' => [['schluessel' => 'ap1']]]), $f1]);

    $typ = [];
    foreach (AnlagenService::steckertypen() as $t) {
        $typ[(string) $t['schluessel']] = (int) $t['id'];
    }
    $ha = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => $marke . ' Hausanschluss', 'floor_id' => $f1, 'plan_x' => 10, 'plan_y' => 10])['id'];
    AnlagenService::anschlussAnlegen($ha, 'abgang', $typ['cee32'] ?? reset($typ));
    SchutzService::vorschlagen($ha);
    $abgaenge = Database::select("SELECT id, nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr", [$ha]);
    gleich(array_map(static fn ($r) => (int) $r['nr'], $abgaenge), [1, 2], 'Hausanschluss mit Abgang 1 und 2');
    pruefe(MesspunktService::speichern($ha, null, ['geraet' => $marke . '-mp', 'kanal' => 'em:0', 'anschluss_id' => (int) $abgaenge[0]['id']])['ok'],
        'Messpunkt am Abgang 1');
    $saeule = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => $marke . ' Säule', 'fest' => 1, 'floor_id' => $f1, 'plan_x' => 40, 'plan_y' => 10])['id'];
    $saeuleEin = AnlagenService::anschlussAnlegen($saeule, 'eingang', $typ['cee125'] ?? reset($typ));
    $erd = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $f1, 'name' => $marke . ' Erdkabel', 'von_objekt_id' => $ha,
        'von_anschluss_id' => (int) $abgaenge[0]['id'], 'zu_objekt_id' => $saeule, 'zu_anschluss_id' => $saeuleEin, 'punkte' => [[10, 10], [40, 10]]]);
    pruefe($erd['ok'] && (int) Database::scalar('SELECT fest FROM leitungen WHERE id = ?', [(int) $erd['id']]) === 1, 'festes Kabel zwischen Hausanschluss und Säule');
    $mobil = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $marke . ' Mobilverteiler', 'floor_id' => $f1, 'plan_x' => 50, 'plan_y' => 50])['id'];
    $mobilEin = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' ORDER BY nr LIMIT 1", [$mobil]);
    $mk = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $f1, 'name' => $marke . ' Mobilkabel', 'von_objekt_id' => $ha,
        'von_anschluss_id' => (int) $abgaenge[1]['id'], 'zu_objekt_id' => $mobil, 'zu_anschluss_id' => $mobilEin, 'punkte' => [[10, 10], [50, 50]]]);
    pruefe($mk['ok'] && (int) Database::scalar('SELECT fest FROM leitungen WHERE id = ?', [(int) $mk['id']]) === 0, 'mobiles Kabel');
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $f1, 'name' => $marke . ' Brücke', 'punkte' => [[20, 20], [24, 20]]]);

    // ------------------------------------------------ Token: Form und Ablage
    $voll = $anlegen(['name' => $marke . ' voll', 'befugnisse' => ['plaene.vorschlagen', 'feststrom'], 'sehen' => [$sites['halle']], 'bearbeiten' => [$sites['halle']]]);
    pruefe($voll['ok'], 'Token angelegt: ' . $voll['message']);
    $tVoll = (string) ($voll['token'] ?? '');
    pruefe(preg_match('/^mtma_[A-Za-z0-9_-]{40}$/', $tVoll) === 1, 'Token: mtma_ und 40 Zeichen base64url');
    $zeile = Database::first('SELECT * FROM api_tokens WHERE id = ?', [(int) $voll['id']]);
    pruefe($zeile['token_hash'] === hash('sha256', $tVoll), 'in der Datenbank steht der sha256');
    pruefe(Crypto::decrypt((string) $zeile['token_enc']) === $tVoll, '… und das verschlüsselte Token');
    pruefe(!in_array($tVoll, array_map('strval', $zeile), true), '… nie das Token selbst');
    gleich(ApiZugang::befugnisseAus($zeile), ['plaene.lesen', 'plaene.vorschlagen', 'feststrom'], 'vorschlagen und Feststrom schließen lesen ein');
    gleich(ApiZugang::befugnisseOrdnen(['feststrom']), ['plaene.lesen', 'feststrom'], 'Feststrom allein: dazu lesen');
    gleich(ApiZugang::befugnisseOrdnen(['plaene.schreiben', 'gibt-es-nicht']), ['plaene.lesen', 'plaene.vorschlagen', 'plaene.schreiben'],
        'schreiben schließt vorschlagen und lesen ein, Unbekanntes fällt weg');
    pruefe(!ApiZugaenge::anlegen(['name' => $marke . ' leer', 'befugnisse' => []])['ok'], 'ohne Befugnis kein Token');
    pruefe(!ApiZugaenge::anlegen(['name' => $marke . ' alt', 'befugnisse' => ['plaene.lesen'], 'ablauf' => '2020-01-01'])['ok'], 'Ablauf in der Vergangenheit abgelehnt');
    pruefe(!ApiZugaenge::anlegen(['name' => $marke . ' quatsch', 'befugnisse' => ['plaene.lesen'], 'ablauf' => '2026-13-45'])['ok'], 'kein Datum abgelehnt');
    pruefe(!ApiZugaenge::anlegen(['name' => ' ', 'befugnisse' => ['plaene.lesen']])['ok'], 'ohne Namen kein Token');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name LIKE ?', [$marke . ' %']), 1, 'nichts Abgelehntes gespeichert');

    $lesen = $anlegen(['name' => $marke . ' lesen', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['halle']], 'bearbeiten' => [$sites['halle']],
        'ablauf' => date('Y-m-d', time() + 30 * 86400)]);
    $tLesen = (string) $lesen['token'];
    pruefe(Database::scalar('SELECT expires_at FROM api_tokens WHERE id = ?', [(int) $lesen['id']]) !== null, 'Ablaufdatum gespeichert');
    $tLeer = (string) $anlegen(['name' => $marke . ' ohne Standorte', 'befugnisse' => ['plaene.lesen']])['token'];
    $tFremd = (string) $anlegen(['name' => $marke . ' unbekannt', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['halle']]])['token'];
    Database::execute("UPDATE api_tokens SET befugnisse = '[\"aus-der-zukunft\"]' WHERE name = ?", [$marke . ' unbekannt']);
    $abgelaufen = $anlegen(['name' => $marke . ' abgelaufen', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['halle']], 'ablauf' => date('Y-m-d', time() + 86400)]);
    Database::execute('UPDATE api_tokens SET expires_at = ? WHERE id = ?', [gmdate('c', time() - 3600), (int) $abgelaufen['id']]);
    $widerrufen = $anlegen(['name' => $marke . ' widerrufen', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['halle']]]);
    pruefe(ApiZugaenge::widerrufen((int) $widerrufen['id'])['ok'], 'widerrufen');
    gleich(Database::scalar('SELECT token_enc FROM api_tokens WHERE id = ?', [(int) $widerrufen['id']]), null, 'widerrufen: verschlüsseltes Token gelöscht');
    $gebunden = [];
    foreach (['inaktiv', 'geloescht', 'abgelaufen', 'gebunden'] as $n) {
        $r = $anlegen(['name' => $marke . ' an ' . $n, 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['halle']], 'user_id' => $benutzer[$n]]);
        pruefe($r['ok'], 'an Benutzer gebunden: ' . $r['message']);
        $gebunden[$n] = (string) ($r['token'] ?? '');
    }
    Database::update('users', $benutzer['inaktiv'], ['is_active' => 0]);
    BenutzerService::loeschen($benutzer['geloescht']);
    unset($benutzer['geloescht']);
    Database::update('users', $benutzer['abgelaufen'], ['gueltig_bis' => gmdate('c', time() - 60)]);

    // ------------------------------------------------ 401: immer dieselbe Antwort
    $ohne = a('GET', '/api/v1/info', null);
    gleich($ohne['status'], 401, 'ohne Token: 401');
    pruefe(stripos($ohne['kopf'], 'WWW-Authenticate: Bearer realm="PowerManagement"') !== false, '401 mit WWW-Authenticate');
    gleich($ohne['json'], ['ok' => false, 'message' => 'Kein gültiges Token.'], '401 als JSON');
    $falle = [
        'Bearer ohne Präfix'      => ['Authorization: Bearer ' . substr($tVoll, 5)],
        'nur „Bearer“'            => ['Authorization: Bearer'],
        'Basic statt Bearer'      => ['Authorization: Basic ' . base64_encode('admin:' . $tVoll)],
        'unbekanntes Token'       => ['Authorization: Bearer mtma_' . str_repeat('A', 40)],
        'abgelaufen'              => ['Authorization: Bearer ' . $abgelaufen['token']],
        'widerrufen'              => ['Authorization: Bearer ' . $widerrufen['token']],
        'Benutzer inaktiv'        => ['Authorization: Bearer ' . $gebunden['inaktiv']],
        'Benutzer gelöscht'       => ['Authorization: Bearer ' . $gebunden['geloescht']],
        'Benutzer abgelaufen'     => ['Authorization: Bearer ' . $gebunden['abgelaufen']],
    ];
    foreach ($falle as $text => $kopf) {
        $r = a('GET', '/api/v1/info', null, null, $kopf);
        pruefe($r['status'] === 401 && $r['roh'] === $ohne['roh'] && stripos($r['kopf'], 'WWW-Authenticate: Bearer') !== false,
            '401 wie immer: ' . $text);
    }
    $r = a('GET', '/api/v1/info?token=' . rawurlencode($tVoll), null);
    pruefe($r['status'] === 401 && $r['roh'] === $ohne['roh'], '401: Token in der Adresse zählt nicht');
    gleich(a('GET', '/api/v1/info', $gebunden['gebunden'])['status'], 200, 'gebunden an einen gültigen Benutzer: angenommen');
    $r = a('GET', '/api/v1/x', null);
    pruefe($r['status'] === 401 && $r['roh'] === $ohne['roh'], 'unbekannte Adresse ohne Token: 401');
    $r = a('GET', '/api/v1/x', $tVoll);
    gleich([$r['status'], $r['json']['message'] ?? null], [404, 'Unbekannte Adresse.'], 'unbekannte Adresse mit Token: 404 JSON');
    gleich(a('GET', '/api', null)['status'], 401, '/api selbst verlangt ein Token');

    // ------------------------------------------------ keine Sitzung, nur JSON
    $karte = api($basis, 'GET', '/karte', $tVoll);
    pruefe($karte['status'] === 302 && str_contains($karte['kopf'], 'Location: /login'), 'Bearer-Token auf /karte: Anmeldeseite – keine Hintertür');
    $r = a('PUT', '/api/v1/locations/' . $marke, $tVoll, '{}');
    pruefe($r['status'] === 405 && stripos($r['kopf'], "Allow: GET, HEAD\r\n") !== false && ($r['json']['ok'] ?? true) === false, 'PUT auf eine Location: 405 mit Allow: GET, HEAD');
    $r = a('GET', '/api/v1/locations/' . $marke . '/vorschlaege', $tVoll);
    pruefe($r['status'] === 405 && stripos($r['kopf'], "Allow: POST\r\n") !== false, 'GET auf …/vorschlaege: 405 mit Allow: POST');
    gleich(a('POST', '/api/v1/info', $tVoll, '{}')['status'], 405, 'POST auf /info: 405');
    $r = a('POST', '/api/v1/locations/x/vorschlaege', $tLesen, '{"location":{}}');
    gleich([$r['status'], $r['json']['message'] ?? ''], [403, 'Dem Token fehlt die Befugnis „plaene.vorschlagen“.'], 'Vorschlag ohne „vorschlagen“: 403');
    gleich(a('POST', '/api/v1/locations/x/vorschlaege', $tVoll, 'kein json')['status'], 400, 'Körper kein JSON: 400');
    gleich(a('POST', '/api/v1/locations/x/vorschlaege', $tVoll, '{"notiz":"ohne location"}')['status'], 400, 'JSON ohne location: 400');
    gleich(a('POST', '/api/v1/locations/x/vorschlaege', $tVoll, '{"location":{}}', ['Content-Type: text/plain'])['status'], 415, 'ohne JSON-Inhaltstyp: 415');
    $r = a('POST', '/api/v1/locations/x/vorschlaege', $tVoll, str_repeat('a', 49 * 1024 * 1024));
    pruefe($r['status'] === 413 && ($r['json']['ok'] ?? true) === false, '49 MiB: 413 als JSON');
    $r = a('POST', '/api/v1/locations/x/vorschlaege', $tVoll, '{"location":{"art":"location"}}');
    gleich([$r['status'], $r['json']['ok'] ?? null, array_key_exists('status', (array) $r['json'])], [404, false, false],
        'Vorschlag an eine unbekannte Location: 404 als JSON (ohne internen Status)');
    $r = a('HEAD', '/api/v1/info', $tVoll);
    pruefe($r['status'] === 200 && $r['roh'] === '', 'HEAD /info: 200 ohne Körper');
    $r = a('HEAD', '/api/v1/info', null);
    pruefe($r['status'] === 401 && $r['roh'] === '' && stripos($r['kopf'], 'WWW-Authenticate') !== false, 'HEAD ohne Token: 401 ohne Körper');
    if (erreichbar('127.0.0.1', $portKnapp)) {
        $r = a('POST', '/api/v1/locations/x/vorschlaege', $tVoll, str_repeat('a', 30 * 1024 * 1024), [], 'http://127.0.0.1:' . $portKnapp);
        pruefe($r['status'] === 500 && str_contains($r['kopf'], 'application/json')
            && $r['json'] === ['ok' => false, 'message' => 'Unerwarteter Fehler. Details stehen im Log.'], 'fataler Fehler (Speicher): 500 als JSON, ohne Einzelheiten');
    }

    // ------------------------------------------------ /info
    $info = a('GET', '/api/v1/info', $tVoll)['json'];
    gleich([$info['produkt'] ?? null, $info['api'] ?? null, $info['format'] ?? null, $info['formate']['strom'] ?? null, $info['formate']['location'] ?? null],
        ['powermanagement', 1, 1, 2, 1], '/info: Produkt, API, Formate');
    gleich($info['token']['befugnisse'] ?? null, ['plaene.lesen', 'plaene.vorschlagen', 'feststrom'], '/info: Befugnisse des Tokens');
    pruefe(is_string($info['instanz'] ?? null) && ($info['token']['name'] ?? '') === $marke . ' voll', '/info: Instanz und Name');
    $r = a('GET', '/api/v1/locations', $tFremd);
    gleich([$r['status'], $r['json']['message'] ?? ''], [403, 'Dem Token fehlt die Befugnis „plaene.lesen“.'], 'ohne „lesen“: /locations 403');
    gleich(a('GET', '/api/v1/info', $tFremd)['json']['token']['befugnisse'] ?? null, [], 'unbekannte Befugnisse zählen nicht');

    // ------------------------------------------------ Zuschnitt
    $liste = a('GET', '/api/v1/locations', $tVoll);
    $locs = $liste['json']['locations'] ?? [];
    $halleKey = (string) Database::scalar('SELECT sync_key FROM sites WHERE id = ?', [$sites['halle']]);
    $fremdKey = \App\Services\LocationBundle::schluesselSichern('sites', $sites['fremd'], $marke . ' Fremd');
    gleich(array_column($locs, 'schluessel'), [$halleKey], 'Liste: nur der freigegebene Standort');
    $listeEtagen = $locs[0]['etagen'] ?? [];
    gleich(count($listeEtagen), 2, 'Liste: beide Etagen');
    pruefe(array_filter($listeEtagen, static fn ($e) => $e['dosen'] !== null || $e['racks'] !== null) === [], 'Liste: dosen und racks immer null');
    gleich([$listeEtagen[0]['plan'] ?? null, $listeEtagen[1]['plan'] ?? null], [true, false], 'Liste: plan als ja/nein');
    gleich([$listeEtagen[0]['anlagen'] ?? null, $listeEtagen[0]['leitungen'] ?? null], [2, 1], 'Liste: feste Anlagen und Leitungen je Etage');
    $r = a('GET', '/api/v1/locations', $tLesen);
    $ohneFest = $r['json']['locations'][0]['etagen'][0] ?? [];
    pruefe(array_key_exists('anlagen', $ohneFest) && $ohneFest['anlagen'] === null && $ohneFest['leitungen'] === null, 'Liste ohne Feststrom: anlagen und leitungen null');
    gleich(a('GET', '/api/v1/locations', $tLeer)['json'], ['ok' => true, 'locations' => []], 'Token ohne Standorte: leere Liste');
    $nichtFrei = a('GET', '/api/v1/locations/' . $fremdKey, $tVoll);
    $nichtDa = a('GET', '/api/v1/locations/' . $marke . '-gibt-es-nicht', $tVoll);
    pruefe($nichtFrei['status'] === 404 && $nichtFrei['roh'] === $nichtDa['roh'], 'nicht freigegeben und nicht vorhanden: dieselbe 404');
    gleich(a('GET', '/api/v1/locations/' . $halleKey, $tLeer)['status'], 404, 'ohne Standorte: Detail 404');
    gleich(a('GET', '/api/v1/locations/Gross', $tVoll)['status'], 404, 'Schlüssel mit Großbuchstaben: unbekannte Adresse');

    $detail = a('GET', '/api/v1/locations/' . $halleKey, $tVoll);
    gleich($detail['status'], 200, 'Detail 200');
    $loc = $detail['json']['location'] ?? [];
    $strom = $detail['json']['strom'] ?? [];
    gleich([$loc['format'] ?? null, $loc['art'] ?? null, $loc['name'] ?? null], [1, 'location', $marke . ' Halle'], 'location im Format 1');
    pruefe(!array_key_exists('strom', $loc), 'kein strom innerhalb von location');
    $etagen = $loc['etagen'] ?? [];
    gleich(array_column($etagen, 'name'), ['Erdgeschoss', 'Obergeschoss'], 'Etagen in ihrer Reihenfolge');
    foreach ($etagen as $e) {
        pruefe(!array_key_exists('dosen', $e) && !array_key_exists('racks', $e) && !array_key_exists('aps', $e), 'Etage ' . $e['name'] . ': ohne dosen/racks/aps (trotz Fremdbestand)');
        pruefe(($e['bearbeiten'] ?? null) === true, 'Etage ' . $e['name'] . ': bearbeiten (Freigabe + vorschlagen)');
    }
    pruefe(array_key_exists('referenz', $etagen[0]) && (float) $etagen[0]['referenz']['meter'] === 120.0, 'Erdgeschoss: Maßstab dabei');
    pruefe(!array_key_exists('referenz', $etagen[1]), 'Obergeschoss: ohne Maßstab fehlt referenz (nicht null)');
    $plan = $etagen[0]['plan'] ?? [];
    $f1Key = (string) Database::scalar('SELECT sync_key FROM floors WHERE id = ?', [$f1]);
    pruefe(($plan['datei'] ?? '') === 'plaene/' . $f1Key . '.svg' && preg_match('/^[0-9a-f]{64}$/', (string) ($plan['sha256'] ?? '')) === 1
        && (int) ($plan['bytes'] ?? 0) > 0 && in_array($plan['typ'] ?? '', ['pdf', 'image'], true), 'Plan mit Datei, sha256, bytes, typ');
    pruefe(preg_match('#^plaene/[a-z0-9-]+\.(pdf|png|jpe?g|svg|webp)$#', (string) ($plan['datei'] ?? '')) === 1, 'Plandatei passt zu MasterAdmin');
    pruefe(array_key_exists('plan', $etagen[1] ?? []) && $etagen[1]['plan'] === null, 'Etage ohne Plan: plan null');
    $anlagenNamen = array_column($strom['anlagen'] ?? [], 'name');
    sort($anlagenNamen);
    gleich($anlagenNamen, [$marke . ' Hausanschluss', $marke . ' Säule'], 'Feststrom: nur die festen Anlagen');
    $kabel = array_values(array_filter($strom['leitungen'] ?? [], static fn ($l) => $l['name'] === $marke . ' Erdkabel'))[0] ?? [];
    gleich([$kabel['von_anschluss'] ?? null, $kabel['zu_anschluss'] ?? null], ['abgang:1', 'eingang:1'], 'festes Kabel mit Anschlüssen');
    gleich(count($strom['leitungen'] ?? []), 1, 'nur das feste Kabel');
    pruefe(!str_contains($detail['roh'], 'messpunkte') && !str_contains($detail['roh'], $marke . '-mp'), 'nie Messpunkte');
    foreach (['Mobilverteiler', 'Mobilkabel', 'Brücke'] as $mob) {
        pruefe(!str_contains($detail['roh'], json_encode($marke . ' ' . $mob, JSON_UNESCAPED_UNICODE)) && !str_contains($liste['roh'], $marke . ' ' . $mob), 'nie mobil: ' . $mob);
    }
    pruefe(!array_key_exists('stand', $strom) && !array_key_exists('von', $strom), 'strom ohne stand und von');
    pruefe(isset($strom['georeferenz'][$f1Key]['a']['lat']), 'Georeferenz des Erdgeschosses');
    $schluessel = array_column($strom['anlagen'] ?? [], 'schluessel');
    $sortiert = $schluessel;
    sort($sortiert, SORT_STRING);
    gleich($schluessel, $sortiert, 'Anlagen nach Schlüssel sortiert');
    $dLesen = a('GET', '/api/v1/locations/' . $halleKey, $tLesen)['json'];
    $stromKeys = array_keys($dLesen['strom'] ?? []);
    sort($stromKeys);
    gleich($stromKeys, ['art', 'format', 'georeferenz', 'schluessel'], 'ohne Feststrom: strom nur mit Georeferenz');
    pruefe(($dLesen['location']['etagen'][0]['bearbeiten'] ?? null) === false, 'Freigabe mit bearbeiten, aber nur „lesen“: bearbeiten nein');

    // ------------------------------------------------ Stand
    gleich($locs[0]['stand'] ?? '', $detail['json']['stand'] ?? '-', 'Stand: Liste gleich Detail');
    pruefe(preg_match('/^[0-9a-f]{16}$/', (string) ($detail['json']['stand'] ?? '')) === 1, 'Stand: 16 Hexzeichen');
    usleep(1_100_000);
    $stand = static fn (string $t): string => (string) (a('GET', '/api/v1/locations/' . $halleKey, $t)['json']['stand'] ?? '');
    $s0 = $stand($tVoll);
    gleich($s0, $detail['json']['stand'], 'Stand nach mehr als einer Sekunde unverändert');
    pruefe($stand($tLesen) !== $s0, 'Stand hängt an den Befugnissen');
    Database::execute('UPDATE objekte SET plan_x = 60 WHERE id = ?', [$mobil]);
    gleich($stand($tVoll), $s0, 'mobilen Verteiler verschoben: Stand bleibt');
    Database::execute('UPDATE objekte SET plan_x = 12 WHERE id = ?', [$ha]);
    $s1 = $stand($tVoll);
    pruefe($s1 !== $s0, 'Hausanschluss verschoben: Stand ändert sich');
    Database::execute('UPDATE api_token_standorte SET darf_bearbeiten = 0 WHERE token_id = ?', [(int) $voll['id']]);
    $s2 = $stand($tVoll);
    pruefe($s2 !== $s1, '„bearbeiten“ weggenommen: Stand ändert sich');
    Database::execute('UPDATE api_token_standorte SET darf_bearbeiten = 1 WHERE token_id = ?', [(int) $voll['id']]);
    gleich($stand($tVoll), $s1, '… und zurück');

    // ------------------------------------------------ Plandatei
    $d = a('GET', '/api/v1/locations/' . $halleKey . '/datei?pfad=' . rawurlencode((string) $plan['datei']), $tVoll);
    pruefe($d['status'] === 200 && hash('sha256', $d['roh']) === $plan['sha256'] && strlen($d['roh']) === (int) $plan['bytes'], 'Plandatei: Bytes passen zu sha256 und bytes');
    pruefe(stripos($d['kopf'], 'Content-Type: application/octet-stream') !== false && preg_match('/^Content-Security-Policy:.*sandbox/mi', $d['kopf']) === 1
        && stripos($d['kopf'], 'Content-Disposition: attachment; filename="' . $f1Key . '.svg"') !== false, 'Plandatei: octet-stream, Anhang, CSP sandbox');
    $ffKey = \App\Services\LocationBundle::schluesselSichern('floors', $ff, 'Halle');
    foreach (['../../config/config.php', 'plaene/../x', '', 'plaene/' . $ffKey . '.svg', 'plaene/gibt-es-nicht.svg', 'plaene/' . $f1Key . '.pdf'] as $pfad) {
        $r = a('GET', '/api/v1/locations/' . $halleKey . '/datei?pfad=' . rawurlencode($pfad), $tVoll);
        pruefe($r['status'] === 404 && ($r['json']['ok'] ?? true) === false, 'Plandatei ' . json_encode($pfad) . ': 404');
    }
    gleich(a('GET', '/api/v1/locations/' . $halleKey . '/datei?pfad=%2e%2e%2fconfig%2fconfig.php', $tVoll)['status'], 404, 'Plandatei %2e%2e%2f: 404');
    gleich(a('GET', '/api/v1/locations/' . $halleKey . '/datei', $tVoll)['status'], 404, 'Plandatei ohne pfad: 404');
    gleich(a('GET', '/api/v1/locations/' . $fremdKey . '/datei?pfad=plaene/' . $ffKey . '.svg', $tVoll)['status'], 404, 'Plandatei des fremden Standorts: 404');
    $r = a('HEAD', '/api/v1/locations/' . $halleKey . '/datei?pfad=' . rawurlencode((string) $plan['datei']), $tVoll);
    pruefe($r['status'] === 200 && $r['roh'] === '', 'HEAD auf die Plandatei: ohne Körper');

    // ------------------------------------------------ zuletzt benutzt
    $zuletzt = Database::first('SELECT last_used_at, last_ip FROM api_tokens WHERE id = ?', [(int) $voll['id']]);
    pruefe($zuletzt['last_used_at'] !== null && $zuletzt['last_ip'] === '127.0.0.1', 'zuletzt benutzt: Zeit und Adresse');
    Database::execute('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [gmdate('c', time() - 30), (int) $voll['id']]);
    $vorher = (string) Database::scalar('SELECT last_used_at FROM api_tokens WHERE id = ?', [(int) $voll['id']]);
    a('GET', '/api/v1/info', $tVoll);
    gleich((string) Database::scalar('SELECT last_used_at FROM api_tokens WHERE id = ?', [(int) $voll['id']]), $vorher, 'innerhalb von 60 s nicht neu geschrieben');
    Database::execute('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [gmdate('c', time() - 120), (int) $voll['id']]);
    a('GET', '/api/v1/info', $tVoll);
    pruefe((string) Database::scalar('SELECT last_used_at FROM api_tokens WHERE id = ?', [(int) $voll['id']]) !== gmdate('c', time() - 120)
        && (\App\Support\Zeit::ts((string) Database::scalar('SELECT last_used_at FROM api_tokens WHERE id = ?', [(int) $voll['id']])) ?? 0) >= time() - 5,
        'nach mehr als 60 s neu geschrieben');

    // ------------------------------------------------ keine Sitzung
    $mitCookie = preg_grep('/^Set-Cookie:/mi', $GLOBALS['__antworten']);
    $mitZiel = preg_grep('/^Location:/mi', $GLOBALS['__antworten']);
    gleich([count($mitCookie), count($mitZiel)], [0, 0], 'keine Antwort der Schnittstelle setzt ein Cookie oder leitet weiter (' . count($GLOBALS['__antworten']) . ' Antworten)');

    // ------------------------------------------------ Oberfläche
    alsBenutzer(null);
    $admin = uiAnmelden($basis, $marke . '-admin', $kennwort);
    $verwalter = uiAnmelden($basis, $marke . '-verwalter', $kennwort);
    $dosen[] = $admin['dose'];
    $dosen[] = $verwalter['dose'];

    $seite = ui($basis, $admin['dose'], 'GET', '/system/schnittstelle');
    pruefe($seite['status'] === 200 && phpMeldung($seite['text']) === null, 'Seite Schnittstelle ohne PHP-Meldung');
    pruefe(str_contains($seite['text'], 'name="sehen[]"') && str_contains($seite['text'], 'name="einmal"'), 'mit „Alle Standorte …“: Formular mit Standorten');
    pruefe(str_contains($seite['text'], View_e($marke . ' voll')) && !str_contains($seite['text'], $tVoll), 'Liste zeigt den Zugang, nicht das Token');
    pruefe(str_contains($seite['text'], 'name="kennwort"'), 'Anzeigen nur mit Kennwortfeld');
    preg_match('/name="einmal" value="([0-9a-f]+)"/', $seite['text'], $m);
    $einmal = $m[1] ?? '';
    $ohneAlles = ui($basis, $verwalter['dose'], 'GET', '/system/schnittstelle');
    pruefe($ohneAlles['status'] === 200 && !str_contains($ohneAlles['text'], 'name="sehen[]"') && !str_contains($ohneAlles['text'], 'name="kennwort"'),
        'ohne „Alle Standorte …“: Liste ja, Formular und Anzeigen nein');
    $r = ui($basis, $verwalter['dose'], 'POST', '/system/schnittstelle/anlegen', ['name' => $marke . ' von Hand', 'befugnisse' => ['plaene.lesen'],
        'sehen' => [$sites['halle']], '_token' => $verwalter['token'], 'einmal' => $einmal]);
    gleich($r['status'], 403, 'von Hand gebautes Formular ohne „Alle Standorte …“: 403');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name = ?', [$marke . ' von Hand']), 0, '… und kein Token');
    gleich(ui($basis, $verwalter['dose'], 'GET', '/system/schnittstelle/' . (int) $voll['id'])['status'], 403, 'Zugang ändern ohne „Alle Standorte …“: 403');

    $felder = ['name' => $marke . ' UI', 'notiz' => 'über die Oberfläche', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['halle']],
        'ablauf' => date('Y-m-d', time() + 10 * 86400), 'user_id' => 0, '_token' => $admin['token'], 'einmal' => $einmal];
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/anlegen', $felder);
    preg_match('/id="api-token" value="(mtma_[A-Za-z0-9_-]{40})"/', $r['text'], $m);
    $tUi = $m[1] ?? '';
    pruefe($r['status'] === 200 && $tUi !== '' && $r['ziel'] === null, 'anlegen: 200 mit dem Token in der Antwort, ohne Weiterleitung');
    pruefe(stripos($r['kopf'], 'Cache-Control: no-store') !== false, 'anlegen: nicht zwischengespeichert');
    pruefe(str_contains($r['text'], 'data-kopieren="#api-token"') && str_contains($r['text'], 'data-auswaehlen') && phpMeldung($r['text']) === null,
        'Token zum Markieren und Kopieren');
    $uiId = (int) Database::scalar('SELECT id FROM api_tokens WHERE name = ?', [$marke . ' UI']);
    if ($tUi !== '') {
        $geheim[] = $tUi;
        $geheim[] = (string) Database::scalar('SELECT token_enc FROM api_tokens WHERE id = ?', [$uiId]);
    }
    pruefe((string) Database::scalar('SELECT token_hash FROM api_tokens WHERE id = ?', [$uiId]) === hash('sha256', $tUi), 'das angezeigte Token ist das gespeicherte');
    gleich(a('GET', '/api/v1/locations', $tUi)['json']['locations'][0]['schluessel'] ?? null, $halleKey, 'das neue Token sieht den freigegebenen Standort');
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/anlegen', $felder);
    pruefe($r['status'] === 302 && !str_contains($r['text'], 'mtma_'), 'erneut abgeschickt (Neuladen): kein Token');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name = ?', [$marke . ' UI']), 1, '… und kein zweiter Zugang');
    pruefe(!str_contains(ui($basis, $admin['dose'], 'GET', '/system/schnittstelle')['text'], $tUi), 'die Liste zeigt das Token danach nicht');

    // Anzeigen mit Kennwort
    $zaehle = static fn (string $aktion, int $id): int => (int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target_id = ?', [$aktion, (string) $id]);
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/anzeigen', ['kennwort' => $kennwort, '_token' => $admin['token']]);
    pruefe($r['status'] === 200 && str_contains($r['text'], 'value="' . $tUi . '"') && stripos($r['kopf'], 'no-store') !== false, 'Anzeigen mit Kennwort: dasselbe Token');
    gleich($zaehle('api.token.angezeigt', $uiId), 1, 'Anzeigen: genau ein Eintrag im Audit-Log');
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/anzeigen', ['kennwort' => 'falsch-' . $kennwort, '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && !str_contains($r['text'], $tUi), 'falsches Kennwort: kein Token');
    gleich([$zaehle('api.token.anzeigen_abgelehnt', $uiId), $zaehle('api.token.angezeigt', $uiId)], [1, 1], 'falsches Kennwort: abgelehnt im Audit-Log');
    $r = ui($basis, $verwalter['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/anzeigen', ['kennwort' => $kennwort, '_token' => $verwalter['token']]);
    pruefe($r['status'] === 403 && !str_contains($r['text'], $tUi), 'Anzeigen ohne „Alle Standorte …“: 403');

    // Ändern
    $r = ui($basis, $admin['dose'], 'GET', '/system/schnittstelle/' . $uiId);
    pruefe($r['status'] === 200 && phpMeldung($r['text']) === null && str_contains($r['text'], 'name="bearbeiten[]"'), 'Zugang ändern: Formular');
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/speichern', ['name' => $marke . ' UI geändert', 'befugnisse' => ['plaene.vorschlagen'],
        'sehen' => [$sites['halle']], 'bearbeiten' => [$sites['halle']], 'ablauf' => '', '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/system/schnittstelle/' . $uiId, 'gespeichert');
    $z = ApiZugaenge::find($uiId);
    gleich([$z['name'], $z['befugnisse'], $z['expires_at'], $z['standorte'][0]['bearbeiten'] ?? null],
        [$marke . ' UI geändert', ['plaene.lesen', 'plaene.vorschlagen'], null, true], 'Name, Befugnisse, unbefristet, bearbeiten');
    gleich(a('GET', '/api/v1/locations', $tUi)['json']['locations'][0]['etagen'][0]['bearbeiten'] ?? null, true, 'die Schnittstelle meldet jetzt bearbeiten');

    // Ohne CSRF nichts
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/widerrufen', []);
    pruefe($r['status'] === 302 && Database::scalar('SELECT revoked_at FROM api_tokens WHERE id = ?', [$uiId]) === null, 'Widerrufen ohne CSRF-Token: abgelehnt');
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/anlegen', ['name' => $marke . ' ohne csrf', 'befugnisse' => ['plaene.lesen']]);
    gleich((int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name = ?', [$marke . ' ohne csrf']), 0, 'Anlegen ohne CSRF-Token: abgelehnt');

    // Löschen nur widerrufene; widerrufen löscht das Verschlüsselte
    ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/loeschen', ['_token' => $admin['token']]);
    pruefe(ApiZugaenge::find($uiId) !== null, 'ein gültiger Zugang lässt sich nicht löschen');
    $r = ui($basis, $verwalter['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/widerrufen', ['_token' => $verwalter['token']]);
    pruefe($r['status'] === 302 && Database::scalar('SELECT revoked_at FROM api_tokens WHERE id = ?', [$uiId]) !== null, 'widerrufen darf auch, wer nicht alles sieht');
    gleich(Database::scalar('SELECT token_enc FROM api_tokens WHERE id = ?', [$uiId]), null, 'nach dem Widerrufen: kein verschlüsseltes Token mehr');
    gleich(a('GET', '/api/v1/info', $tUi)['status'], 401, 'widerrufen: die Schnittstelle lehnt ab');
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/anzeigen', ['kennwort' => $kennwort, '_token' => $admin['token']]);
    pruefe($r['status'] === 302 && !str_contains($r['text'], $tUi), 'nach dem Widerrufen: Anzeigen zeigt nichts');
    gleich($zaehle('api.token.angezeigt', $uiId), 1, '… und schreibt kein „angezeigt“');
    ui($basis, $verwalter['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/loeschen', ['_token' => $verwalter['token']]);
    pruefe(ApiZugaenge::find($uiId) !== null, 'löschen ohne „Alle Standorte …“: nein');
    $r = ui($basis, $admin['dose'], 'POST', '/system/schnittstelle/' . $uiId . '/loeschen', ['_token' => $admin['token']]);
    pruefe($r['status'] === 302 && ApiZugaenge::find($uiId) === null && (int) Database::scalar('SELECT COUNT(*) FROM api_token_standorte WHERE token_id = ?', [$uiId]) === 0,
        'ein widerrufener Zugang lässt sich löschen – samt Standorten');
    $r = ui($basis, $admin['dose'], 'GET', '/system/schnittstelle');
    pruefe($r['status'] === 200 && phpMeldung($r['text']) === null, 'Liste mit abgelaufenen, widerrufenen und ruhenden Zugängen ohne PHP-Meldung');
    pruefe(str_contains($r['text'], 'abgelaufen') && str_contains($r['text'], 'widerrufen') && str_contains($r['text'], 'ruht'), 'Zustände in der Liste');

    // ------------------------------------------------ keine Geheimnisse in Audit und Log
    $geheim = array_values(array_filter(array_unique($geheim), static fn (string $g): bool => $g !== ''));
    $audit = implode("\n", array_column(Database::select('SELECT details FROM audit_log WHERE id > (SELECT COALESCE(MAX(id), 0) - 2000 FROM audit_log)'), 'details'));
    clearstatcache();
    $log = (string) @file_get_contents($wurzel . '/storage/logs/app.log', false, null, $logVorher);
    $gefunden = array_filter($geheim, static fn (string $g): bool => str_contains($audit, $g) || str_contains($log, $g));
    gleich(count($gefunden), 0, 'kein Token und kein verschlüsseltes Token in Audit-Log oder app.log (' . count($geheim) . ' geprüft)');
    pruefe(count($geheim) >= 10, 'genug Geheimnisse zum Prüfen gesammelt');
    $eingang = Database::first("SELECT username FROM audit_log WHERE action = 'api.token.anlegen' AND details LIKE ? ORDER BY id DESC LIMIT 1", ['%' . $marke . ' voll%']);
    gleich($eingang['username'] ?? null, $marke . '-admin', 'Audit: angelegt von');
} finally {
    alsBenutzer(null);
    $tokenIds = array_map('intval', array_column(Database::select('SELECT id FROM api_tokens WHERE name LIKE ?', [$marke . '%']), 'id'));
    foreach ($tokenIds as $id) {
        Database::execute('DELETE FROM api_token_standorte WHERE token_id = ?', [$id]);
        Database::execute('DELETE FROM api_tokens WHERE id = ?', [$id]);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_token' AND target_id = ?", [(string) $id]);
    }
    foreach ($sites as $siteId) {
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$siteId]) as $o) {
            AnlagenService::loeschen((int) $o['id']);
        }
        SiteService::deleteSite($siteId);
    }
    Database::execute('DELETE FROM messpunkte WHERE geraet LIKE ?', [$marke . '%']);
    foreach ($benutzer as $id) {
        BenutzerService::loeschen($id);
    }
    foreach ($rollen as $id) {
        BenutzerService::rolleLoeschen($id);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR username LIKE ? OR details LIKE ?', [$marke . '%', 'api:' . $marke . '%', '%' . $marke . '%']);
    foreach ($benutzer as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach (array_merge($dosen, $tmpDateien) as $d) {
        @unlink($d);
    }
}

/** HTML-Escaping wie in den Ansichten. */
function View_e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

ende('schnittstelle');
