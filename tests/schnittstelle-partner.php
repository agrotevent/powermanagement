<?php
declare(strict_types=1);

/**
 * Andere Instanzen (Seite B): eine zweite, echte Instanz übernimmt von dieser.
 *
 * A ist diese Arbeitskopie mit ihrer Konfiguration hinter einem eigenen
 * PHP-Server. B ist dieselbe Arbeitskopie mit eigener Konfiguration und
 * eigener Datenbank (POWERMANAGEMENT_CONFIG) – ihr Code läuft über inB(),
 * ihre Oberfläche hinter einem zweiten PHP-Server. Geprüft wird: eintragen
 * (Token-Form, falsches Token, nur verschlüsselt abgelegt), der erste Abruf
 * (Grundriss mit gleicher Prüfsumme, Maßstab, Georeferenz, Feststrom, keine
 * mobilen Anlagen, keine Messpunkte), ein zweiter Abruf ohne Download, die
 * Zustände (dort/hier/beide geändert, verknüpft – noch nicht übernommen),
 * Zuordnen auf einen vorhandenen Standort (Namen, Schaltsperre, Messpunkte,
 * Einsätze, mobile Kabel und Georeferenz hier bleiben, nichts wandert von
 * einem anderen Standort herüber), die Rechte in B, Lösen, Widerrufen und
 * neues Token, die Meldungen bei Verbindungsfehlern und MasterAdmin als A
 * (Http-Attrappe mit dem Format von dort). Dazu die Oberfläche in B mit
 * Anmeldung und CSRF-Token, und welche Wege zurück sie je nach Befugnis
 * anbietet.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\ApiZugang;
use App\Messung\Http;
use App\Services\AnlagenService;
use App\Services\ApiZugaenge;
use App\Services\BenutzerService;
use App\Services\LeitungService;
use App\Services\LocationBundle;
use App\Services\MesspunktService;
use App\Services\PartnerAbgleich;
use App\Services\PartnerService;
use App\Services\PlanGeoService;
use App\Services\PlanMassstabService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Crypto;
use App\Support\Database;

if (!function_exists('curl_init')) {
    uebersprungen('schnittstelle-partner', 'die PHP-Erweiterung curl fehlt');
}

$wurzel = dirname(__DIR__);
$marke = 'pm-api-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '1';
$tmp = sys_get_temp_dir() . '/' . $marke;
mkdir($tmp, 0700, true);

/** Alle Dateien unter einem Ordner (für das Aufräumen in storage/plans). */
function dateienUnter(string $ordner): array
{
    if (!is_dir($ordner)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $out[] = $f->getPathname();
    }
    return $out;
}
$planDir = SiteService::planDir();
$planeVorher = dateienUnter($planDir);
$logDatei = $wurzel . '/storage/logs/app.log';
$logVorher = is_file($logDatei) ? (int) filesize($logDatei) : 0;

// ---------------------------------------------------- Instanz B
$bConfig = $tmp . '/b.php';
file_put_contents($bConfig, "<?php\n\$c = require " . var_export($wurzel . '/config/config.php', true) . ";\n"
    . "\$c['database'] = ['driver' => 'sqlite', 'path' => " . var_export($tmp . '/b.sqlite', true) . "] + \$c['database'];\n"
    . "\$c['app']['name'] = 'Instanz B';\nreturn \$c;\n");
$GLOBALS['__bConfig'] = $bConfig;
$p = proc_open([PHP_BINARY, $wurzel . '/bin/power', 'migrate'], [1 => ['file', $tmp . '/migrate.txt', 'w'], 2 => ['file', $tmp . '/migrate.txt', 'a']],
    $rohre, $wurzel, ['POWERMANAGEMENT_CONFIG' => $bConfig] + getenv());
$rc = proc_close($p);
if ($rc !== 0) {
    pruefe(false, 'B migriert: ' . (string) @file_get_contents($tmp . '/migrate.txt'));
    ende('schnittstelle-partner');
}

/**
 * Code in B ausführen – als Benutzer $wer (null: niemand). $werte werden als
 * Variablen vorangestellt. In B zählt $__zaehler die Anfragen nach dort
 * (und die Plandateien), die Anfragen selbst gehen unverändert hinaus.
 */
function inBAls(?int $wer, string $code, array $werte = []): mixed
{
    $kopf = "use App\\Auth\\Auth;\nuse App\\Auth\\Zugriff;\nuse App\\Messung\\Http;\nuse App\\Services\\AnlagenService;\n"
        . "use App\\Services\\BenutzerService;\nuse App\\Services\\LeitungService;\nuse App\\Services\\LocationBundle;\n"
        . "use App\\Services\\MesspunktService;\nuse App\\Services\\PartnerAbgleich;\nuse App\\Services\\PartnerService;\n"
        . "use App\\Services\\PlanGeoService;\nuse App\\Services\\PlanMassstabService;\nuse App\\Services\\SchutzService;\n"
        . "use App\\Services\\SiteService;\nuse App\\Support\\Database;\n"
        . "\$__zaehler = ['alle' => 0, 'datei' => 0];\n"
        . "Http::\$attrappe = static function (string \$m, string \$url, array \$o) use (&\$__zaehler): array {\n"
        . "    \$__zaehler['alle']++;\n"
        . "    if (str_contains(\$url, '/datei?')) { \$__zaehler['datei']++; }\n"
        . "    \$selbst = Http::\$attrappe; Http::\$attrappe = null;\n"
        . "    try { return Http::anfrage(\$m, \$url, \$o); } finally { Http::\$attrappe = \$selbst; }\n"
        . "};\n";
    foreach ($werte as $name => $wert) {
        $kopf .= '$' . $name . ' = ' . var_export($wert, true) . ";\n";
    }
    if ($wer !== null) {
        $kopf .= 'Auth::alsBenutzer(' . $wer . ");\n";
    }
    return inB($GLOBALS['__bConfig'], $kopf . $code);
}

// ---------------------------------------------------- Server A und B
$port = freierPort();
hintergrund([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4']);
$portB = freierPort();
hintergrund([PHP_BINARY, '-S', '127.0.0.1:' . $portB, '-t', $wurzel . '/public'], ['POWERMANAGEMENT_CONFIG' => $bConfig, 'PHP_CLI_SERVER_WORKERS' => '2']);
for ($i = 0; $i < 50 && (!erreichbar('127.0.0.1', $port) || !erreichbar('127.0.0.1', $portB)); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $port) || !erreichbar('127.0.0.1', $portB)) {
    uebersprungen('schnittstelle-partner', 'eingebauter PHP-Server startet nicht');
}
$basisA = 'http://127.0.0.1:' . $port;
$basisB = 'http://127.0.0.1:' . $portB;

/** Ein Aufruf einer Oberfläche mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
function ui(string $basis, string $dose, string $methode, string $pfad, array $felder = []): array
{
    $c = curl_init($basis . $pfad);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $dose, CURLOPT_COOKIEFILE => $dose, CURLOPT_TIMEOUT => 60]);
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

function phpMeldung(string $text): ?string
{
    return preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:.*?(?=<br|$)/s', $text, $m) ? strip_tags($m[0]) : null;
}

$benutzer = [];
$sites = [];
$tokenIds = [];
$geheim = [];
$maPartner = null;
$maSite = null;
$dosen = [];

try {
    // ================================================= Bestand in A
    $benutzer['admin'] = BenutzerService::anlegen($marke . '-admin', $kennwort, 'admin', false);
    alsBenutzer($benutzer['admin']);
    $typ = [];
    foreach (AnlagenService::steckertypen() as $t) {
        $typ[(string) $t['schluessel']] = (int) $t['id'];
    }
    $cee32 = $typ['cee32'] ?? reset($typ);

    $sites['halle'] = SiteService::saveSite(null, ['name' => $marke . ' Halle', 'address' => 'Halle 1', 'notes' => 'Prüfung']);
    $f1 = SiteService::saveFloor(null, ['site_id' => $sites['halle'], 'name' => 'Erdgeschoss', 'level_no' => 0, 'sort_order' => 0]);
    $f2 = SiteService::saveFloor(null, ['site_id' => $sites['halle'], 'name' => 'Obergeschoss', 'level_no' => 1, 'sort_order' => 1]);
    $planTmp = tempnam($tmp, 'plan-');
    copy(__DIR__ . '/hilfen/location-lager/plaene/grundriss.svg', $planTmp);
    pruefe(SiteService::uploadPlan($sites['halle'], ['name' => 'grundriss.svg', 'type' => 'image/svg+xml', 'tmp_name' => $planTmp,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($planTmp)], ['floor_ids' => [$f1]])['ok'], 'A: Grundriss hochgeladen');
    PlanMassstabService::speichern($f1, ['x' => 10.0, 'y' => 90.0], ['x' => 60.0, 'y' => 90.0], 120.0, 1.0);
    $kx = 6371008.8 * M_PI / 180 * cos(deg2rad(51.44));
    pruefe(PlanGeoService::speichern($f1, ['x' => 10.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56],
        ['x' => 60.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56 + 100 / $kx], 1.0)['ok'], 'A: Georeferenz gesetzt');
    $planShaA = (string) hash_file('sha256', (string) SiteService::planPath(SiteService::floor($f1)));

    $ha = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => $marke . ' Hausanschluss', 'floor_id' => $f1, 'plan_x' => 10, 'plan_y' => 10])['id'];
    AnlagenService::anschlussAnlegen($ha, 'abgang', $cee32);
    SchutzService::vorschlagen($ha);
    $abgaenge = Database::select("SELECT id, nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr", [$ha]);
    gleich(array_map(static fn ($r) => (int) $r['nr'], $abgaenge), [1, 2], 'A: Hausanschluss mit Abgang 1 und 2');
    pruefe(MesspunktService::speichern($ha, null, ['geraet' => $marke . '-mp', 'kanal' => 'em:0', 'anschluss_id' => (int) $abgaenge[0]['id']])['ok'], 'A: Messpunkt am Hausanschluss');
    $saeule = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => $marke . ' Säule', 'floor_id' => $f1, 'plan_x' => 40, 'plan_y' => 10])['id'];
    $saeuleEin = AnlagenService::anschlussAnlegen($saeule, 'eingang', $typ['cee125'] ?? $cee32);
    $erd = LeitungService::speichern(null, ['art' => 'kabel', 'fest' => 1, 'floor_id' => $f1, 'name' => $marke . ' Erdkabel', 'von_objekt_id' => $ha,
        'von_anschluss_id' => (int) $abgaenge[0]['id'], 'zu_objekt_id' => $saeule, 'zu_anschluss_id' => $saeuleEin, 'punkte' => [[10, 10], [40, 10]]]);
    pruefe($erd['ok'], 'A: festes Kabel Hausanschluss → Säule');
    $mobil = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'fest' => 0, 'name' => $marke . ' Mobilverteiler', 'floor_id' => $f1, 'plan_x' => 50, 'plan_y' => 50])['id'];
    $mobilEin = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' ORDER BY nr LIMIT 1", [$mobil]);
    LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $f1, 'name' => $marke . ' Mobilkabel', 'von_objekt_id' => $ha,
        'von_anschluss_id' => (int) $abgaenge[1]['id'], 'zu_objekt_id' => $mobil, 'zu_anschluss_id' => $mobilEin ?: null, 'punkte' => [[10, 10], [50, 50]]]);
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $f1, 'name' => $marke . ' Brücke', 'punkte' => [[20, 20], [24, 20]]]);

    $sites['buehne'] = SiteService::saveSite(null, ['name' => $marke . ' Bühne']);
    $fFlaeche = SiteService::saveFloor(null, ['site_id' => $sites['buehne'], 'name' => 'Fläche', 'level_no' => 0, 'sort_order' => 0]);
    $fBack = SiteService::saveFloor(null, ['site_id' => $sites['buehne'], 'name' => 'Backstage', 'level_no' => 0, 'sort_order' => 1]);
    PlanGeoService::speichern($fFlaeche, ['x' => 5.0, 'y' => 5.0, 'lat' => 50.1, 'lon' => 8.1], ['x' => 95.0, 'y' => 5.0, 'lat' => 50.1, 'lon' => 8.1 + 200 / $kx], 1.0);
    PlanGeoService::speichern($fBack, ['x' => 5.0, 'y' => 5.0, 'lat' => 48.0, 'lon' => 11.0], ['x' => 95.0, 'y' => 5.0, 'lat' => 48.0, 'lon' => 11.0 + 100 / $kx], 1.0);
    $trafo = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => $marke . ' Trafo', 'floor_id' => $fFlaeche, 'plan_x' => 30, 'plan_y' => 30])['id'];
    AnlagenService::anschlussAnlegen($trafo, 'abgang', $cee32);
    SchutzService::vorschlagen($trafo);

    $halleKey = LocationBundle::schluesselSichern('sites', $sites['halle'], $marke . ' Halle');
    $buehneKey = LocationBundle::schluesselSichern('sites', $sites['buehne'], $marke . ' Bühne');
    $f1Key = LocationBundle::schluesselSichern('floors', $f1, 'Erdgeschoss');
    $f2Key = LocationBundle::schluesselSichern('floors', $f2, 'Obergeschoss');
    $flaecheKey = LocationBundle::schluesselSichern('floors', $fFlaeche, 'Fläche');
    $backKey = LocationBundle::schluesselSichern('floors', $fBack, 'Backstage');
    $trafoKey = LocationBundle::schluesselSichern('objekte', $trafo, $marke . ' Trafo');
    $haKey = LocationBundle::schluesselSichern('objekte', $ha, $marke . ' Hausanschluss');

    $t = ApiZugaenge::anlegen(['name' => $marke . ' für B', 'befugnisse' => ['plaene.lesen', 'feststrom'],
        'sehen' => [$sites['halle'], $sites['buehne']], 'bearbeiten' => [$sites['halle'], $sites['buehne']]]);
    pruefe($t['ok'], 'A: Token für B angelegt: ' . $t['message']);
    $token = (string) ($t['token'] ?? '');
    $tokenIds[] = (int) ($t['id'] ?? 0);
    $geheim[] = $token;
    $standA = static fn (string $key, string $tok): string => (string) (api($basisA, 'GET', '/api/v1/locations/' . $key, $tok)['json']['stand'] ?? '');

    // ================================================= Vertrag: PowerManagement als A für einen MasterAdmin-Client
    $info = api($basisA, 'GET', '/api/v1/info', $token)['json'];
    pruefe(is_string($info['instanz'] ?? null) && array_is_list($info['token']['befugnisse'] ?? null) && ($info['produkt'] ?? '') === 'powermanagement',
        'Vertrag: info.instanz, info.token.befugnisse als Liste, produkt');
    $liste = (array) (api($basisA, 'GET', '/api/v1/locations', $token)['json']['locations'] ?? []);
    $lHalle = array_values(array_filter($liste, static fn ($l) => ($l['schluessel'] ?? '') === $halleKey))[0] ?? [];
    $e0 = $lHalle['etagen'][0] ?? [];
    pruefe(isset($lHalle['name'], $lHalle['stand']) && is_bool($e0['plan'] ?? null) && is_bool($e0['bearbeiten'] ?? null)
        && array_key_exists('dosen', $e0) && $e0['dosen'] === null && array_key_exists('racks', $e0) && $e0['racks'] === null,
        'Vertrag: locations[] mit schluessel/name/stand, Etagen mit plan (bool), bearbeiten, dosen/racks null');
    $detail = api($basisA, 'GET', '/api/v1/locations/' . $halleKey, $token)['json'];
    $plan = $detail['location']['etagen'][0]['plan'] ?? [];
    pruefe(($detail['location']['art'] ?? '') === 'location' && ($detail['location']['format'] ?? 0) === 1
        && preg_match('#^plaene/[a-z0-9-]+\.(pdf|png|jpe?g|svg|webp)$#', (string) ($plan['datei'] ?? '')) === 1
        && ($plan['sha256'] ?? '') === $planShaA && array_key_exists('bearbeiten', $detail['location']['etagen'][0])
        && !array_key_exists('strom', $detail['location']), 'Vertrag: Detail mit art/format, plan.datei wie MasterAdmin, sha256, bearbeiten, kein strom in location');

    // ================================================= B: Konten und eigener Bestand
    $b = inBAls(null, <<<'PHP'
        $admin = BenutzerService::anlegen($marke . '-b-admin', $kennwort, 'admin', false);
        $planer = (int) BenutzerService::rolleSpeichern(null, $marke . ' planer', '', ['api.partner', 'plans.manage', 'karte.view', 'anlagen.view',
            'anlagen.manage', 'leitungen.manage', 'dashboard.view'])['id'];
        $zeichner = (int) BenutzerService::rolleSpeichern(null, $marke . ' zeichner', '', ['api.partner', 'plans.manage', 'karte.view', 'anlagen.view', 'dashboard.view'])['id'];
        $key = static fn (int $id): string => (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$id]);
        $seher = BenutzerService::anlegen($marke . '-b-seher', $kennwort, $key($planer), false);
        $zeichnerId = BenutzerService::anlegen($marke . '-b-zeichner', $kennwort, $key($zeichner), false);
        $kunde = BenutzerService::anlegen($marke . '-b-kunde', $kennwort, 'kunde', false);
        Auth::alsBenutzer($admin);
        // Ein Standort, der zur Bühne dort passt (gleicher Name, andere Schreibung) – mit eigenen Etagen
        $buehne = SiteService::saveSite(null, ['name' => $marke . ' bühne']);
        $flaeche = SiteService::saveFloor(null, ['site_id' => $buehne, 'name' => 'Fläche', 'sort_order' => 0]);
        $back = SiteService::saveFloor(null, ['site_id' => $buehne, 'name' => 'Backstage', 'sort_order' => 1]);
        PlanGeoService::speichern($back, ['x' => 1.0, 'y' => 1.0, 'lat' => 52.5, 'lon' => 13.4], ['x' => 99.0, 'y' => 1.0, 'lat' => 52.5, 'lon' => 13.4015], 1.0);
        // … und ein ganz anderer Standort mit einer Anlage, die dort denselben Schlüssel hat wie der Trafo dort
        $anderswo = SiteService::saveSite(null, ['name' => $marke . ' Anderswo']);
        $aFloor = SiteService::saveFloor(null, ['site_id' => $anderswo, 'name' => 'Hof']);
        $alt = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => $marke . ' alter Trafo', 'floor_id' => $aFloor, 'plan_x' => 1, 'plan_y' => 1])['id'];
        Database::update('objekte', $alt, ['sync_key' => $trafoKey]);
        return compact('admin', 'seher', 'kunde', 'buehne', 'flaeche', 'back', 'anderswo', 'alt') + ['zeichner' => $zeichnerId];
        PHP, ['marke' => $marke, 'kennwort' => $kennwort, 'trafoKey' => $trafoKey]);
    $bAdmin = (int) $b['admin'];

    // ================================================= Eintragen
    $r = inBAls($bAdmin, <<<'PHP'
        $ohne = PartnerService::anlegen('A', $basis, 'abc' . $token);
        $fremd = PartnerService::anlegen('A', $basis, 'mtma_' . str_repeat('x', 40));
        $ok = PartnerService::anlegen('', $basis . '/api/v1/', $token);
        $zeile = $ok['ok'] ? Database::first('SELECT * FROM api_partner WHERE id = ?', [$ok['id']]) : null;
        $ohneEnc = $zeile;
        unset($ohneEnc['token_enc']);
        return [
            'ohne' => $ohne, 'fremd' => $fremd, 'ok' => $ok, 'zeile' => $zeile,
            'zeileOhneToken' => !str_contains((string) json_encode($zeile), $token),
            'auditOhneToken' => !str_contains((string) json_encode(Database::select('SELECT * FROM audit_log')), $token)
                && !str_contains((string) json_encode(Database::select('SELECT * FROM audit_log')), (string) $zeile['token_enc']),
            'find' => PartnerService::find((int) $ok['id']),
            'zahl' => (int) Database::scalar('SELECT COUNT(*) FROM api_partner'),
        ];
        PHP, ['basis' => $basisA, 'token' => $token]);
    pruefe(!$r['ohne']['ok'] && str_contains($r['ohne']['message'], 'mtma_'), 'Token ohne „mtma_“ → Hinweis auf das Präfix: ' . $r['ohne']['message']);
    pruefe(!$r['fremd']['ok'] && str_contains($r['fremd']['message'], 'nicht (mehr) angenommen'), 'falsches Token → „nicht (mehr) angenommen“: ' . $r['fremd']['message']);
    pruefe($r['ok']['ok'], 'richtiges Token → eingetragen: ' . $r['ok']['message']);
    $pid = (int) ($r['ok']['id'] ?? 0);
    gleich([$r['zeile']['base_url'] ?? null, $r['zeile']['remote_produkt'] ?? null, $r['zeile']['remote_name'] ?? null, $r['zahl']],
        [$basisA, 'powermanagement', (string) \App\Support\Config::get('app.name'), 1], 'Adresse ohne /api/v1, Gegenseite PowerManagement, nur eine Zeile');
    gleich(json_decode((string) ($r['zeile']['remote_befugnisse'] ?? ''), true), ['plaene.lesen', 'feststrom'], 'Befugnisse dort gespeichert');
    pruefe($r['zeileOhneToken'] && Crypto::decrypt((string) ($r['zeile']['token_enc'] ?? '')) === $token, 'in B nur das verschlüsselte Token');
    $geheim[] = (string) ($r['zeile']['token_enc'] ?? '');
    pruefe($r['auditOhneToken'], 'B: Token und Verschlüsseltes nicht im Audit');
    pruefe(!array_key_exists('token_enc', (array) $r['find']), 'find() gibt token_enc nicht heraus');

    // ================================================= Erster Abruf
    $r = inBAls($bAdmin, <<<'PHP'
        $p = PartnerService::find($pid);
        $vorher = array_column(PartnerAbgleich::locations($p)['locations'], null, 'schluessel');
        $e1 = PartnerAbgleich::uebernehmen($p, $halle, null, null, true);
        $z1 = $__zaehler;
        $e2 = PartnerAbgleich::uebernehmen($p, $halle, null, null, true);
        $z2 = $__zaehler;
        $nachher = array_column(PartnerAbgleich::locations($p)['locations'], null, 'schluessel');
        $siteId = (int) $e1['site_id'];
        $floors = Database::select('SELECT * FROM floors WHERE site_id = ? ORDER BY sort_order', [$siteId]);
        $objekte = Database::select('SELECT * FROM objekte WHERE site_id = ? ORDER BY id', [$siteId]);
        $ha = array_values(array_filter($objekte, static fn ($o) => str_ends_with((string) $o['name'], 'Hausanschluss')))[0] ?? null;
        $leitungen = Database::select('SELECT l.* FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ?', [$siteId]);
        $kabelAn = null;
        foreach ($leitungen as $l) {
            if ($l['von_anschluss_id'] !== null) {
                $a = Database::first('SELECT objekt_id, richtung, nr FROM anschluesse WHERE id = ?', [(int) $l['von_anschluss_id']]);
                $kabelAn = $a === null ? null : [(int) $a['objekt_id'], $a['richtung'] . ':' . $a['nr']];
            }
        }
        $f1 = $floors[0] ?? [];
        return [
            'vorher' => $vorher, 'e1' => $e1, 'e2' => $e2, 'z1' => $z1, 'z2' => $z2, 'nachher' => $nachher,
            'site' => SiteService::find($siteId), 'floors' => $floors, 'objekte' => $objekte, 'ha' => $ha,
            'abgaenge' => $ha === null ? [] : array_map('intval', array_column(Database::select("SELECT nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr", [(int) $ha['id']]), 'nr')),
            'schutz' => $ha === null ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM schutzorgane WHERE objekt_id = ?', [(int) $ha['id']]),
            'leitungen' => $leitungen, 'kabelAn' => $kabelAn,
            'messpunkte' => (int) Database::scalar('SELECT COUNT(*) FROM messpunkte m JOIN objekte o ON o.id = m.objekt_id WHERE o.site_id = ?', [$siteId]),
            'sha' => $f1 !== [] && SiteService::planPath($f1) !== null ? hash_file('sha256', (string) SiteService::planPath($f1)) : '',
            'massstab' => $f1 !== [] ? PlanMassstabService::lesen($f1) : null,
            'geo' => $f1 !== [] ? PlanGeoService::lesen($f1) : null,
            'audit' => Database::first("SELECT * FROM audit_log WHERE action = 'api.partner.location' ORDER BY id DESC LIMIT 1"),
        ];
        PHP, ['pid' => $pid, 'halle' => $halleKey]);
    gleich([$r['vorher'][$halleKey]['zustand'] ?? null, $r['vorher'][$buehneKey]['zustand'] ?? null], ['neu', 'passt'],
        'vorher: Halle „noch nicht hier“, Bühne „passt zu“');
    gleich([$r['vorher'][$buehneKey]['vorschlag']['id'] ?? null, $r['vorher'][$buehneKey]['vorschlag']['grund'] ?? null], [(int) $b['buehne'], 'name'],
        'Bühne passt zum Standort hier mit gleichem Namen');
    pruefe($r['e1']['ok'] && $r['e1']['neu'], 'erster Abruf mit Feststrom: ' . $r['e1']['message']);
    $bHalle = (int) ($r['e1']['site_id'] ?? 0);
    gleich([$r['site']['name'] ?? null, count($r['floors']), array_column($r['floors'], 'name'), array_column($r['floors'], 'partner_schluessel')],
        [$marke . ' Halle', 2, ['Erdgeschoss', 'Obergeschoss'], [$f1Key, $f2Key]], 'B: Standort mit beiden Etagen, verknüpft je Etage');
    pruefe($r['sha'] === $planShaA, 'B: Grundriss mit derselben Prüfsumme');
    pruefe(($r['massstab']['meter'] ?? null) == 120.0 && $r['geo'] !== null, 'B: Maßstab und Georeferenz da');
    gleich(array_column($r['objekte'], 'name'), [$marke . ' Hausanschluss', $marke . ' Säule'], 'B: nur die Feststromanschlüsse, keine mobilen Anlagen');
    pruefe($r['ha'] !== null && $r['abgaenge'] === [1, 2] && $r['schutz'] > 0 && (string) $r['ha']['partner_schluessel'] === $haKey,
        'B: Hausanschluss mit Abgang 1 und 2 und Schutzorganen, Schlüssel von dort in partner_schluessel');
    gleich([count($r['leitungen']), $r['leitungen'][0]['fest'] ?? null, $r['kabelAn']], [1, 1, [(int) ($r['ha']['id'] ?? 0), 'abgang:1']],
        'B: nur das feste Kabel, am Abgang 1 des Hausanschlusses (keine Brücke, kein mobiles Kabel)');
    gleich($r['messpunkte'], 0, 'B: keine Messpunkte übernommen');
    gleich([$r['site']['partner_id'] ?? null, $r['site']['partner_schluessel'] ?? null, $r['site']['partner_stand'] ?? null],
        [$pid, $halleKey, $standA($halleKey, $token)], 'B: partner_stand ist der Stand von A');
    gleich($r['nachher'][$halleKey]['zustand'] ?? null, 'aktuell', 'nach dem Abruf: aktuell');
    gleich([$r['e2']['ok'], $r['e2']['plaene'], $r['e2']['anlagen'], $r['e2']['leitungen']], [true, $r['e1']['plaene'], $r['e1']['anlagen'], $r['e1']['leitungen']],
        'zweiter Abruf: dieselben Zahlen');
    gleich([$r['z1']['datei'], $r['z2']['datei'] - $r['z1']['datei']], [1, 0], 'erster Abruf lädt den Plan, der zweite nicht');
    pruefe($r['audit'] !== null && !str_contains((string) $r['audit']['details'], $token)
        && (json_decode((string) $r['audit']['details'], true)['schluessel'] ?? '') === $halleKey, 'B: Audit api.partner.location ohne Token');

    // ================================================= Zustände
    $zustand = static fn (string $key): ?string => inBAls($bAdmin, <<<'PHP'
        $l = array_column(PartnerAbgleich::locations(PartnerService::find($pid))['locations'], null, 'schluessel');
        return $l[$key]['zustand'] ?? null;
        PHP, ['pid' => $pid, 'key' => $key]);
    $bHa = (int) ($r['ha']['id'] ?? 0);
    AnlagenService::position($ha, $f1, 22.0, 33.0);
    gleich($zustand($halleKey), 'dort', 'A verschiebt den Hausanschluss → dort geändert');
    $r = inBAls($bAdmin, <<<'PHP'
        $e = PartnerAbgleich::uebernehmen(PartnerService::find($pid), $key, null, null, true);
        return ['e' => $e, 'ha' => AnlagenService::find($bHa)];
        PHP, ['pid' => $pid, 'key' => $halleKey, 'bHa' => $bHa]);
    pruefe($r['e']['ok'] && (float) $r['ha']['plan_x'] === 22.0 && (float) $r['ha']['plan_y'] === 33.0, 'B übernimmt: Hausanschluss an der neuen Stelle');
    gleich($zustand($halleKey), 'aktuell', '… danach aktuell');
    inBAls($bAdmin, 'return AnlagenService::position($bHa, (int) AnlagenService::find($bHa)["floor_id"], 50.0, 50.0);', ['bHa' => $bHa]);
    gleich($zustand($halleKey), 'hier', 'B verschiebt seine Kopie → hier geändert');
    AnlagenService::position($ha, $f1, 25.0, 35.0);
    gleich($zustand($halleKey), 'beide', 'dazu A → beide geändert');
    $r = inBAls($bAdmin, <<<'PHP'
        $e = PartnerAbgleich::uebernehmen(PartnerService::find($pid), $key, null, null, true);
        return ['e' => $e, 'ha' => AnlagenService::find($bHa)];
        PHP, ['pid' => $pid, 'key' => $halleKey, 'bHa' => $bHa]);
    pruefe($r['e']['ok'] && (float) $r['ha']['plan_x'] === 25.0, 'beide geändert → übernehmen: der Stand von dort gilt');
    gleich($zustand($halleKey), 'aktuell', '… danach aktuell');

    // ================================================= Zuordnen auf einen vorhandenen Standort
    $r = inBAls($bAdmin, <<<'PHP'
        $p = PartnerService::find($pid);
        $z = PartnerAbgleich::zuordnung($p, $key, null);
        $bild = static fn (): array => [
            Database::select('SELECT id, name, plan_file, scale_note, notes, geo_ax, geo_a_lat FROM floors WHERE site_id = ? ORDER BY id', [$buehne]),
            (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ?', [$buehne]),
            Database::first('SELECT name, address FROM sites WHERE id = ?', [$buehne]),
        ];
        $vorher = $bild();
        $v = PartnerAbgleich::uebernehmen($p, $key, $buehne, [$flaecheKey => (string) $flaeche, $backKey => ''], true, true);
        $nachV = $bild();
        $zustandV = array_column(PartnerAbgleich::locations($p)['locations'], null, 'schluessel')[$key]['zustand'] ?? null;
        $u = PartnerAbgleich::uebernehmen($p, $key, $buehne, [$flaecheKey => (string) $flaeche, $backKey => ''], true);
        $trafo = Database::first('SELECT * FROM objekte WHERE site_id = ? AND partner_schluessel = ?', [$buehne, $trafoKey]);
        $floors = Database::select('SELECT * FROM floors WHERE site_id = ? ORDER BY id', [$buehne]);
        return [
            'z' => $z, 'vorher' => $vorher, 'v' => $v, 'nachV' => $nachV, 'zustandV' => $zustandV, 'u' => $u, 'trafo' => $trafo,
            'site' => SiteService::find($buehne), 'floors' => $floors,
            'alt' => Database::first('SELECT site_id, sync_key, partner_schluessel FROM objekte WHERE id = ?', [$alt]),
            'geoBack' => PlanGeoService::lesen(Database::first('SELECT * FROM floors WHERE id = ?', [$back])),
            'geoFlaeche' => PlanGeoService::lesen(Database::first('SELECT * FROM floors WHERE id = ?', [$flaeche])),
        ];
        PHP, ['pid' => $pid, 'key' => $buehneKey, 'buehne' => (int) $b['buehne'], 'flaeche' => (int) $b['flaeche'], 'back' => (int) $b['back'],
        'alt' => (int) $b['alt'], 'flaecheKey' => $flaecheKey, 'backKey' => $backKey, 'trafoKey' => $trafoKey]);
    $zEtagen = array_column((array) ($r['z']['etagen'] ?? []), null, 'schluessel');
    pruefe(($r['z']['ok'] ?? false) && (int) $r['z']['ziel'] === (int) $b['buehne'], 'Zuordnen: der passende Standort ist vorgewählt');
    gleich([$zEtagen[$flaecheKey]['ziel']['id'] ?? null, $zEtagen[$flaecheKey]['ziel']['grund'] ?? null, $zEtagen[$backKey]['ziel']['id'] ?? null],
        [(int) $b['flaeche'], 'name', (int) $b['back']], 'Zuordnen: Etagen nach gleichem Namen vorgeschlagen');
    pruefe(($r['z']['feststrom'] ?? false) && in_array((int) $b['buehne'], array_map('intval', array_column((array) $r['z']['standorte'], 'id')), true)
        && !in_array($bHalle, array_map('intval', array_column((array) $r['z']['standorte'], 'id')), true),
        'Zuordnen: Feststrom angeboten; wählbar nur Standorte, die noch mit nichts verknüpft sind');
    pruefe($r['v']['ok'], 'Nur verknüpfen: ' . $r['v']['message']);
    gleich($r['nachV'], $r['vorher'], 'Nur verknüpfen ändert nichts am Bestand');
    gleich($r['zustandV'], 'verknuepft', 'Zustand: verknüpft – noch nicht übernommen');
    pruefe($r['u']['ok'], 'Fläche übernommen, Backstage nicht: ' . $r['u']['message']);
    gleich([$r['site']['name'], (int) $r['site']['partner_id'], $r['site']['partner_schluessel']], [$marke . ' bühne', $pid, $buehneKey], 'Name des Standorts hier bleibt');
    gleich(array_column($r['floors'], 'partner_schluessel'), [$flaecheKey, ''], 'nur die Fläche ist verknüpft');
    gleich($r['alt'], ['site_id' => (int) $b['anderswo'], 'sync_key' => $trafoKey, 'partner_schluessel' => ''],
        'die Anlage mit gleichem Schlüssel auf einem anderen Standort bleibt, wo sie ist');
    pruefe($r['trafo'] !== null && (int) $r['trafo']['floor_id'] === (int) $b['flaeche'], 'der Trafo von dort ist neu auf der Fläche hier');
    gleich([$r['geoBack']['a']['lat'] ?? null, round((float) ($r['geoFlaeche']['a']['lat'] ?? 0), 4)], [52.5, 50.1],
        'Georeferenz der nicht übernommenen Etage bleibt, die der übernommenen kommt von dort');

    // Hier dazu: Schaltsperre, Messpunkt und Einsatz an Abgang 2, ein mobiles Kabel an Abgang 1
    $bTrafo = (int) ($r['trafo']['id'] ?? 0);
    $r = inBAls($bAdmin, <<<'PHP'
        Database::update('objekte', $trafo, ['steuerung_gesperrt' => 1]);
        $ab = array_column(Database::select("SELECT id, nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr", [$trafo]), 'id', 'nr');
        $mp = MesspunktService::speichern($trafo, null, ['geraet' => $marke . '-bmp', 'kanal' => 'em:0', 'anschluss_id' => (int) $ab[2]]);
        $einsatz = Database::insert('einsaetze', ['inventar_id' => 999999999, 'objekt_id' => $trafo, 'anschluss_id' => (int) $ab[2], 'objekt_name' => 'Prüfung',
            'ab' => gmdate('c'), 'created_at' => gmdate('c')]);
        $vt = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'fest' => 0, 'name' => $marke . ' B-Verteiler', 'floor_id' => $flaeche, 'plan_x' => 60, 'plan_y' => 60])['id'];
        $k = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $flaeche, 'name' => $marke . ' B-Kabel', 'von_objekt_id' => $trafo,
            'von_anschluss_id' => (int) $ab[1], 'zu_objekt_id' => $vt, 'punkte' => [[30, 30], [60, 60]]]);
        return ['mp' => (int) $mp['id'], 'einsatz' => $einsatz, 'kabel' => (int) $k['id'], 'ok' => $mp['ok'] && $k['ok']];
        PHP, ['trafo' => $bTrafo, 'flaeche' => (int) $b['flaeche'], 'marke' => $marke]);
    pruefe($r['ok'], 'B: Messpunkt, Einsatz und mobiles Kabel am übernommenen Trafo');
    $hierDazu = $r;
    AnlagenService::position($trafo, $fFlaeche, 44.0, 45.0);
    PlanGeoService::speichern($fBack, ['x' => 5.0, 'y' => 5.0, 'lat' => 47.0, 'lon' => 10.0], ['x' => 95.0, 'y' => 5.0, 'lat' => 47.0, 'lon' => 10.0 + 100 / $kx], 1.0);
    gleich($zustand($buehneKey), 'beide', 'Schaltsperre hier, Trafo dort verschoben → beide geändert');
    $r = inBAls($bAdmin, <<<'PHP'
        $e = PartnerAbgleich::uebernehmen(PartnerService::find($pid), $key, null, null, true);
        $stelle = static function ($id) use ($trafo): ?string {
            $a = $id === null ? null : Database::first('SELECT objekt_id, richtung, nr FROM anschluesse WHERE id = ?', [(int) $id]);
            return $a === null || (int) $a['objekt_id'] !== $trafo ? null : $a['richtung'] . ':' . $a['nr'];
        };
        $k = Database::first('SELECT * FROM leitungen WHERE id = ?', [$kabel]);
        return [
            'e' => $e, 'trafo' => AnlagenService::find($trafo),
            'mp' => Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]),
            'mpStelle' => $stelle(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mp])),
            'einsatzStelle' => $stelle(Database::scalar('SELECT anschluss_id FROM einsaetze WHERE id = ?', [$einsatz])),
            'kabel' => $k, 'kabelStelle' => $k === null ? null : $stelle($k['von_anschluss_id']),
            'geoBack' => PlanGeoService::lesen(Database::first('SELECT * FROM floors WHERE id = ?', [$back])),
            'zustand' => array_column(PartnerAbgleich::locations(PartnerService::find($pid))['locations'], null, 'schluessel')[$key]['zustand'] ?? null,
        ];
        PHP, ['pid' => $pid, 'key' => $buehneKey, 'trafo' => $bTrafo, 'back' => (int) $b['back']] + $hierDazu);
    pruefe($r['e']['ok'], 'erneut übernommen (nur die verknüpfte Etage): ' . $r['e']['message']);
    gleich([(int) $r['trafo']['steuerung_gesperrt'], (float) $r['trafo']['plan_x']], [1, 44.0], 'Schaltsperre hier bleibt, die Lage kommt von dort');
    pruefe($r['mp'] !== null && $r['mpStelle'] === 'abgang:2', 'Messpunkt hier bleibt, an Abgang 2');
    gleich($r['einsatzStelle'], 'abgang:2', 'Einsatz hier zeigt weiter auf Abgang 2');
    pruefe($r['kabel'] !== null && (int) $r['kabel']['fest'] === 0 && $r['kabelStelle'] === 'abgang:1', 'mobiles Kabel hier bleibt, an Abgang 1');
    gleich($r['geoBack']['a']['lat'] ?? null, 52.5, 'Georeferenz der nicht verknüpften Etage bleibt (dort geändert, kommt nicht über die Hintertür)');
    gleich($r['zustand'], 'aktuell', 'danach aktuell');
    $r = inBAls($bAdmin, <<<'PHP'
        $p = PartnerService::find($pid);
        $zahl = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM floors WHERE site_id = ?', [$buehne]);
        $vorher = $zahl();
        return [PartnerAbgleich::uebernehmen($p, $key, $buehne, [$flaecheKey => 'neu'], false), $vorher, $zahl()];
        PHP, ['pid' => $pid, 'key' => $buehneKey, 'buehne' => (int) $b['buehne'], 'flaecheKey' => $flaecheKey]);
    pruefe(!$r[0]['ok'] && str_contains($r[0]['message'], 'schon mit „Fläche“ verknüpft') && $r[1] === $r[2],
        'eine verknüpfte Etage dort bekommt hier keine zweite: ' . $r[0]['message']);

    // ================================================= Rechte in B
    AnlagenService::position($ha, $f1, 70.0, 70.0);
    $r = inBAls($bAdmin, <<<'PHP'
        foreach ([[$seher, 0], [$zeichner, 1]] as [$wer, $bearbeiten]) {
            BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $wer, 'ziel_art' => 'site', 'ziel_id' => $halle, 'darf_bearbeiten' => $bearbeiten]);
        }
        $p = PartnerService::find($pid);
        $bild = static fn (): array => [AnlagenService::find($bHa)['plan_x'], Database::first('SELECT partner_stand, partner_hash, partner_at FROM sites WHERE id = ?', [$halle]),
            PartnerAbgleich::hierStand($halle)];
        $vorher = $bild();
        Auth::alsBenutzer($seher);
        Zugriff::vergessen();
        $s = PartnerAbgleich::uebernehmen($p, $key, null, null, false);
        $sListe = array_column(PartnerAbgleich::locations($p)['locations'], null, 'schluessel')[$key] ?? [];
        Auth::alsBenutzer($zeichner);
        Zugriff::vergessen();
        $zF = PartnerAbgleich::uebernehmen($p, $key, null, null, true);
        $zListe = array_column(PartnerAbgleich::locations($p)['locations'], null, 'schluessel')[$key] ?? [];
        $nachher = $bild();
        Auth::alsBenutzer($admin);
        Zugriff::vergessen();
        $fremd = PartnerAbgleich::uebernehmen($p, $key, $halle, [$f1Key => (string) $flaeche], false);
        $doppelt = PartnerAbgleich::uebernehmen($p, $key, $halle, [$f1Key => (string) $hf1, $f2Key => (string) $hf1], false);
        $neuTrotz = PartnerAbgleich::uebernehmen($p, $key, 0, null, false);
        return compact('vorher', 'nachher', 's', 'zF', 'fremd', 'doppelt', 'neuTrotz') + ['sAendern' => $sListe['aendern'] ?? null,
            'zAendern' => $zListe['aendern'] ?? null, 'zFeststrom' => $zListe['feststrom'] ?? null, 'nachFremd' => $bild()];
        PHP, ['pid' => $pid, 'key' => $halleKey, 'halle' => $bHalle, 'bHa' => $bHa, 'seher' => (int) $b['seher'], 'zeichner' => (int) $b['zeichner'],
        'admin' => $bAdmin, 'flaeche' => (int) $b['flaeche'], 'f1Key' => $f1Key, 'f2Key' => $f2Key,
        'hf1' => (int) inBAls(null, 'return (int) Database::scalar("SELECT id FROM floors WHERE site_id = ? ORDER BY sort_order LIMIT 1", [$s]);', ['s' => $bHalle])]);
    pruefe(!$r['s']['ok'] && str_contains($r['s']['message'], 'bearbeiten') && $r['sAendern'] === false, 'nur „sehen“ am Standort → abgelehnt: ' . $r['s']['message']);
    pruefe(!$r['zF']['ok'] && str_contains($r['zF']['message'], 'Anlagen ändern') && $r['zAendern'] === true && $r['zFeststrom'] === false,
        'Feststrom ohne „Anlagen ändern“ → abgelehnt: ' . $r['zF']['message']);
    gleich($r['nachher'], $r['vorher'], '… und nichts geändert');
    pruefe(!$r['fremd']['ok'] && str_contains($r['fremd']['message'], 'nicht zu diesem Standort'), 'Etage eines anderen Standorts → abgelehnt: ' . $r['fremd']['message']);
    pruefe(!$r['doppelt']['ok'] && str_contains($r['doppelt']['message'], 'Zwei Etagen'), 'zwei Etagen dort auf eine hier → abgelehnt');
    pruefe(!$r['neuTrotz']['ok'] && str_contains($r['neuTrotz']['message'], 'schon mit'), 'verknüpft, aber auf einen neuen Standort → abgelehnt');
    gleich($r['nachFremd'], $r['vorher'], 'abgelehnte Zuordnungen ändern nichts');

    // ================================================= Oberfläche in B
    $dose = tempnam($tmp, 'dose-');
    $dosen[] = $dose;
    $seite = ui($basisB, $dose, 'GET', '/login');
    $an = ui($basisB, $dose, 'POST', '/login', ['username' => $marke . '-b-admin', 'password' => $kennwort, '_token' => csrf($seite['text'])]);
    pruefe($an['status'] === 302 && $an['ziel'] !== '/login', 'B: Anmeldung an der Oberfläche');
    $csrf = csrf(ui($basisB, $dose, 'GET', '/profil')['text']);
    foreach (['/instanzen', '/instanzen/' . $pid, '/instanzen/' . $pid . '/zuordnen?schluessel=' . $halleKey, '/instanzen/' . $pid . '/zuordnen?schluessel=' . $buehneKey] as $pfad) {
        $s = ui($basisB, $dose, 'GET', $pfad);
        pruefe($s['status'] === 200 && phpMeldung($s['text']) === null, 'B: ' . $pfad . ' → ' . $s['status'] . ' ' . (string) phpMeldung($s['text']));
    }
    $s = ui($basisB, $dose, 'GET', '/instanzen/' . $pid);
    pruefe(str_contains($s['text'], htmlspecialchars($marke . ' Halle')) && str_contains($s['text'], 'aktuell') && !str_contains($s['text'], $token),
        'B: Instanzseite zeigt die Locations dort, nie das Token');
    pruefe(!str_contains($s['text'], '/zurueck"'), 'B: ohne „vorschlagen“/„schreiben“ dort kein Weg zurück');
    foreach (['/instanzen/999999999', '/instanzen/abc', '/instanzen/anlegen'] as $pfad) {
        gleich(ui($basisB, $dose, 'GET', $pfad)['status'], 404, 'B: ' . $pfad . ' → 404');
    }
    $vorher = inBAls(null, 'return Database::first("SELECT partner_at, partner_stand FROM sites WHERE id = ?", [$s]);', ['s' => $bHalle]);
    $r = ui($basisB, $dose, 'POST', '/instanzen/' . $pid . '/uebernehmen', ['schluessel' => $halleKey, 'aktion' => 'uebernehmen', 'feststrom' => '1']);
    pruefe($r['status'] === 302 && inBAls(null, 'return Database::first("SELECT partner_at, partner_stand FROM sites WHERE id = ?", [$s]);', ['s' => $bHalle]) === $vorher,
        'B: Übernehmen ohne CSRF-Token → abgelehnt, nichts geändert');
    foreach (['pruefen' => [], 'loeschen' => [], 'loesen' => ['site_id' => $bHalle], 'zurueck' => ['site_id' => $bHalle], 'anlegen' => ['adresse' => $basisA, 'token' => $token]] as $aktion => $felder) {
        $ziel = $aktion === 'anlegen' ? '/instanzen/anlegen' : '/instanzen/' . $pid . '/' . $aktion;
        ui($basisB, $dose, 'POST', $ziel, $felder);
    }
    gleich(inBAls(null, 'return [(int) Database::scalar("SELECT COUNT(*) FROM api_partner"), Database::scalar("SELECT partner_id FROM sites WHERE id = ?", [$s])];', ['s' => $bHalle]),
        [1, $pid], 'B: ohne CSRF-Token weder eingetragen noch ausgetragen noch gelöst');
    $r = ui($basisB, $dose, 'POST', '/instanzen/' . $pid . '/uebernehmen', ['schluessel' => $halleKey, 'aktion' => 'uebernehmen', 'feststrom' => '1', '_token' => $csrf]);
    $nach = inBAls(null, 'return Database::first("SELECT partner_at, partner_stand FROM sites WHERE id = ?", [$s]);', ['s' => $bHalle]);
    pruefe($r['status'] === 302 && $r['ziel'] === '/instanzen/' . $pid && $nach['partner_stand'] === $standA($halleKey, $token),
        'B: Übernehmen über das Formular → zurück zur Instanz, Stand von dort');
    $r = ui($basisB, $dose, 'POST', '/instanzen/' . $pid . '/uebernehmen', ['schluessel' => $halleKey, 'ziel' => (string) $bHalle,
        'etagen' => [$f1Key => (string) $b['flaeche']], 'aktion' => 'uebernehmen', '_token' => $csrf]);
    pruefe($r['status'] === 302 && str_starts_with((string) $r['ziel'], '/instanzen/' . $pid . '/zuordnen?schluessel=' . $halleKey . '&ziel=' . $bHalle),
        'B: abgelehntes Zuordnen → zurück ins Formular: ' . (string) $r['ziel']);
    pruefe(str_contains(ui($basisB, $dose, 'GET', '/instanzen/' . $pid . '/zuordnen?schluessel=' . $halleKey)['text'], 'nicht zu diesem Standort'),
        'B: … mit der Meldung');
    $kundeDose = tempnam($tmp, 'dose-');
    $dosen[] = $kundeDose;
    $seite = ui($basisB, $kundeDose, 'GET', '/login');
    ui($basisB, $kundeDose, 'POST', '/login', ['username' => $marke . '-b-kunde', 'password' => $kennwort, '_token' => csrf($seite['text'])]);
    $kundeCsrf = csrf(ui($basisB, $kundeDose, 'GET', '/profil')['text']);
    foreach (['/instanzen', '/instanzen/' . $pid, '/instanzen/' . $pid . '/zuordnen?schluessel=' . $halleKey] as $pfad) {
        gleich(ui($basisB, $kundeDose, 'GET', $pfad)['status'], 403, 'B: Kunde ohne „api.partner“ ' . $pfad . ' → 403');
    }
    gleich(ui($basisB, $kundeDose, 'POST', '/instanzen/' . $pid . '/loeschen', ['_token' => $kundeCsrf])['status'], 403, 'B: Kunde kann nicht austragen');

    // Wege zurück je nach Befugnis dort: mit „schreiben“ Hochladen (bei „beide
    // geändert“ nur mit Rückfrage und erzwingen), mit nur „vorschlagen“ ein Vorschlag
    $mitBefugnis = static function (array $befugnisse) use ($tokenIds, $sites, $marke, $bAdmin, $pid): void {
        $e = ApiZugaenge::speichern($tokenIds[0], ['name' => $marke . ' für B', 'befugnisse' => $befugnisse,
            'sehen' => [$sites['halle'], $sites['buehne']], 'bearbeiten' => [$sites['halle'], $sites['buehne']]]);
        pruefe($e['ok'], 'A: Befugnisse ' . implode(', ', $befugnisse) . ': ' . $e['message']);
        $pr = inBAls($bAdmin, 'return PartnerService::pruefen($pid);', ['pid' => $pid]);
        pruefe($pr['ok'], 'B: Prüfen holt die neuen Befugnisse: ' . $pr['message']);
    };
    $halleZeile = static function () use ($basisB, $dose, $pid, $marke): string {
        $html = ui($basisB, $dose, 'GET', '/instanzen/' . $pid)['text'];
        return preg_match('#<tr>(?:(?!</tr>).)*' . preg_quote($marke . ' Halle', '#') . '.*?</tr>#s', $html, $m) === 1 ? $m[0] : '';
    };
    $mitBefugnis(['plaene.schreiben', 'feststrom']);
    inBAls($bAdmin, 'return PartnerAbgleich::uebernehmen(PartnerService::find($pid), $key, null, null, true);', ['pid' => $pid, 'key' => $halleKey]);
    inBAls($bAdmin, 'return AnlagenService::position($bHa, (int) AnlagenService::find($bHa)["floor_id"], 11.0, 12.0);', ['bHa' => $bHa]);
    $zeile = $halleZeile();
    pruefe(str_contains($zeile, 'hier geändert') && str_contains($zeile, '>Hochladen</button>') && !str_contains($zeile, 'name="erzwingen"')
        && !str_contains($zeile, 'Als Vorschlag schicken'), 'mit „schreiben“ dort: bei „hier geändert“ Hochladen, ohne Überschreiben');
    pruefe(str_contains($zeile, 'data-confirm="Änderungen hier auf den verknüpften Etagen werden überschrieben'), '… und Übernehmen mit Rückfrage');
    $mitBefugnis(['plaene.vorschlagen', 'feststrom']);
    $zeile = $halleZeile();
    pruefe(str_contains($zeile, 'hier geändert') && str_contains($zeile, 'Als Vorschlag schicken') && !str_contains($zeile, '>Hochladen</button>'),
        'nur „vorschlagen“ dort: als Vorschlag schicken');
    AnlagenService::position($ha, $f1, 71.0, 71.0);
    $mitBefugnis(['plaene.schreiben', 'feststrom']);
    $zeile = $halleZeile();
    pruefe(str_contains($zeile, 'beide geändert') && str_contains($zeile, 'name="erzwingen" value="1"') && str_contains($zeile, 'überschreibt die Änderungen dort'),
        'beide geändert: Hochladen nur mit Rückfrage und „erzwingen“');
    inBAls($bAdmin, 'return PartnerAbgleich::uebernehmen(PartnerService::find($pid), $key, null, null, true);', ['pid' => $pid, 'key' => $halleKey]);
    $zeile = $halleZeile();
    pruefe(str_contains($zeile, '>aktuell<') && !str_contains($zeile, '/zurueck"'), 'aktuell: kein Weg zurück angeboten');
    $mitBefugnis(['plaene.lesen', 'feststrom']);

    // ================================================= Lösen, Widerrufen, neues Token
    $r = inBAls($bAdmin, <<<'PHP'
        $vorher = (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ?', [$halle]);
        $l = PartnerAbgleich::loesen($halle, $pid);
        $nochmal = PartnerAbgleich::loesen($halle, $pid);
        return [
            'l' => $l, 'nochmal' => $nochmal,
            'site' => Database::first('SELECT partner_id, partner_schluessel, partner_stand, partner_hash, partner_at FROM sites WHERE id = ?', [$halle]),
            'floors' => array_column(Database::select('SELECT partner_schluessel FROM floors WHERE site_id = ?', [$halle]), 'partner_schluessel'),
            'objekte' => array_column(Database::select('SELECT partner_schluessel FROM objekte WHERE site_id = ?', [$halle]), 'partner_schluessel'),
            'leitungen' => array_column(Database::select('SELECT l.partner_schluessel FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ?', [$halle]), 'partner_schluessel'),
            'zahl' => [$vorher, (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ?', [$halle])],
            'zustand' => array_column(PartnerAbgleich::locations(PartnerService::find($pid))['locations'], null, 'schluessel')[$key]['zustand'] ?? null,
        ];
        PHP, ['pid' => $pid, 'halle' => $bHalle, 'key' => $halleKey]);
    pruefe($r['l']['ok'] && !$r['nochmal']['ok'], 'Lösen: ' . $r['l']['message']);
    gleich($r['site'], ['partner_id' => null, 'partner_schluessel' => '', 'partner_stand' => '', 'partner_hash' => '', 'partner_at' => null], 'Lösen leert die Verknüpfung am Standort');
    pruefe(array_unique(array_merge($r['floors'], $r['objekte'], $r['leitungen'])) === [''], '… an Etagen, Anlagen und Kabeln');
    pruefe($r['zahl'][0] === $r['zahl'][1] && $r['zahl'][0] === 2, '… die Daten bleiben');
    gleich($r['zustand'], 'passt', 'danach passt die Location wieder zum Standort (gleicher Name)');

    ApiZugaenge::widerrufen($tokenIds[0]);
    $r = inBAls($bAdmin, 'return [PartnerService::pruefen($pid), PartnerService::find($pid)["letzter_fehler"]];', ['pid' => $pid]);
    pruefe(!$r[0]['ok'] && str_contains($r[0]['message'], 'nicht (mehr) angenommen') && str_contains((string) $r[1], 'nicht (mehr) angenommen'),
        'widerrufen in A → Prüfen in B: „nicht (mehr) angenommen“, vermerkt');
    $t2 = ApiZugaenge::anlegen(['name' => $marke . ' für B, neu', 'befugnisse' => ['plaene.lesen', 'feststrom'], 'sehen' => [$sites['halle'], $sites['buehne']]]);
    $tokenIds[] = (int) ($t2['id'] ?? 0);
    $geheim[] = (string) ($t2['token'] ?? '');
    $r = inBAls($bAdmin, <<<'PHP'
        $quatsch = PartnerService::pruefen($pid, 'kein-token');
        $neu = PartnerService::pruefen($pid, $t2);
        return [
            'quatsch' => $quatsch, 'neu' => $neu, 'partner' => PartnerService::find($pid),
            'verknuepft' => Database::scalar('SELECT partner_id FROM sites WHERE id = ?', [$buehne]),
            'audit' => Database::first("SELECT details FROM audit_log WHERE action = 'api.partner.token' ORDER BY id DESC LIMIT 1"),
            'enc' => Database::scalar('SELECT token_enc FROM api_partner WHERE id = ?', [$pid]),
            'liste' => PartnerAbgleich::locations(PartnerService::find($pid))['ok'],
        ];
        PHP, ['pid' => $pid, 't2' => (string) ($t2['token'] ?? ''), 'buehne' => (int) $b['buehne']]);
    pruefe(!$r['quatsch']['ok'] && str_contains($r['quatsch']['message'], 'mtma_'), 'Prüfen mit einem Nicht-Token → Hinweis auf das Präfix');
    pruefe($r['neu']['ok'] && $r['liste'] && (string) $r['partner']['letzter_fehler'] === '', 'Prüfen mit neuem Token: Verbindung steht wieder');
    pruefe(Crypto::decrypt((string) $r['enc']) === (string) ($t2['token'] ?? '') && (int) $r['verknuepft'] === $pid, '… neues Token hinterlegt, Verknüpfungen bleiben');
    $geheim[] = (string) $r['enc'];
    pruefe($r['audit'] !== null && !str_contains((string) $r['audit']['details'], (string) ($t2['token'] ?? '')), 'Audit api.partner.token ohne Token');

    // ================================================= Adressen und Verbindungsfehler
    foreach (['https://u:p@h.example', 'https://@h.example', 'http://h.example/?x', 'http://h.example/#y', 'file:///etc/passwd', 'ftp://h.example',
        'http://', 'h.example', 'http://h .example', "http://h.\nexample", 'http://h.example\\@evil', 'javascript:alert(1)'] as $falsch) {
        pruefe(PartnerService::adresseFehler($falsch) !== null, 'Adresse abgelehnt: ' . json_encode($falsch));
    }
    foreach (['http://10.0.0.20', 'https://strom.example.org/', 'http://[::1]:8080/pm/api/v1'] as $gut) {
        pruefe(PartnerService::adresseFehler($gut) === null, 'Adresse angenommen: ' . $gut);
    }
    gleich([PartnerService::adresse('HTTPS://Strom.Example.org/pm/api/v1/'), PartnerService::adresse('http://h.example/api'), PartnerService::adresse('http://h.example:81/')],
        ['https://strom.example.org/pm', 'http://h.example', 'http://h.example:81'], 'Adresse ohne /api(/v1) und Schrägstrich, Schema und Name klein');
    pruefe(PartnerService::unverschluesselt('http://10.0.0.20') && !PartnerService::unverschluesselt('http://127.0.0.1:8080')
        && !PartnerService::unverschluesselt('https://h.example'), '„unverschlüsselt“ nur für http über ein Netz');
    $frei = freierPort();
    $r = PartnerService::anlegen('x', 'http://127.0.0.1:' . $frei, 'mtma_' . str_repeat('a', 40));
    pruefe(!$r['ok'] && str_contains($r['message'], 'nimmt vom Server hier aus keine Verbindung an') && str_contains($r['message'], ':' . $frei),
        'freier Port → „nimmt … keine Verbindung an“: ' . $r['message']);
    $r = PartnerService::anlegen('x', 'http://pm-test.invalid', 'mtma_' . str_repeat('a', 40));
    pruefe(!$r['ok'] && str_contains($r['message'], 'DNS'), 'unbekannter Name → DNS-Meldung: ' . $r['message']);
    $meldungen = [
        6  => PartnerService::verbindungsfehler(6, 'Could not resolve host: h.example', 'https://h.example/api/v1/info'),
        7  => PartnerService::verbindungsfehler(7, 'Failed to connect', 'http://h.example:8080/api/v1/info'),
        281 => PartnerService::verbindungsfehler(28, 'Resolving timed out after 10001 milliseconds', 'https://h.example/api/v1/info'),
        282 => PartnerService::verbindungsfehler(28, 'Connection timed out after 10001 milliseconds', 'https://h.example/api/v1/info'),
        283 => PartnerService::verbindungsfehler(28, 'Operation timed out after 60000 milliseconds with 0 bytes received', 'https://h.example/api/v1/info'),
        35 => PartnerService::verbindungsfehler(35, 'SSL routines', 'https://h.example/api/v1/info'),
        60 => PartnerService::verbindungsfehler(60, 'SSL certificate problem', 'https://h.example/api/v1/info'),
        99 => PartnerService::verbindungsfehler(99, 'Irgendwas', 'https://h.example/api/v1/info'),
    ];
    gleich(count(array_unique($meldungen)), count($meldungen), 'je Fehlernummer eine eigene Meldung');
    pruefe(str_contains($meldungen[6], 'DNS') && str_contains($meldungen[281], 'DNS-Server') && str_contains($meldungen[282], 'Firewall')
        && str_contains($meldungen[7], '„h.example:8080“') && str_contains($meldungen[35], 'https') && str_contains($meldungen[60], 'update-ca-certificates')
        && str_contains($meldungen[99], 'Irgendwas'), 'Meldungen nennen, wo zu suchen ist');
    pruefe(str_contains($meldungen[283], '60 s') && !str_contains($meldungen[283], 'Firewall'), 'zu langsam: nennt die Zeitgrenze, keine Firewall');
    pruefe(str_contains(PartnerService::verbindungsfehler(28, 'Operation timed out', 'http://h', PartnerService::DATEI_ZEITLIMIT), '180 s'), 'Plandatei: die längere Zeitgrenze');
    pruefe(array_filter($meldungen, static fn (string $m): bool => str_contains($m, 'mtma_')) === [], 'keine Meldung enthält ein Token');

    // Antworten, die keine sind – über die Attrappe
    $antwort = null;
    Http::$attrappe = static function (string $m, string $url, array $o) use (&$antwort): array {
        return $antwort;
    };
    $test = ['base_url' => 'http://ma.example', 'token' => 'mtma_' . str_repeat('t', 40)];
    $antwort = ['ok' => true, 'status' => 200, 'rumpf' => '<html>Anmelden</html>', 'fehler' => ''];
    gleich(PartnerService::anfrage($test, 'GET', '/info')['message'], 'Die Antwort ist kein JSON (HTTP 200) – stimmt die Adresse?', 'HTML statt JSON');
    $antwort = ['ok' => true, 'status' => 429, 'rumpf' => '{"ok":false,"message":"Zu viele Anfragen."}', 'fehler' => ''];
    pruefe(str_contains(PartnerService::anfrage($test, 'GET', '/info')['message'], 'bremst'), '429 → „bremst gerade“');
    $antwort = ['ok' => true, 'status' => 403, 'rumpf' => json_encode(['ok' => false, 'message' => "<script>x</script>\n" . str_repeat('ä', 400)]), 'fehler' => ''];
    $r = PartnerService::anfrage($test, 'GET', '/locations');
    pruefe(!$r['ok'] && $r['status'] === 403 && mb_strlen($r['message']) === 300 && !str_contains($r['message'], "\n"), 'Meldung von dort: ohne Zeilenumbruch, höchstens 300 Zeichen');
    $antwort = ['ok' => false, 'status' => 200, 'rumpf' => '', 'fehler' => 'zu groß', 'nummer' => Http::ZU_GROSS];
    pruefe(str_contains(PartnerService::anfrage($test, 'GET', '/locations')['message'], 'zu groß'), 'zu große Antwort → abgebrochen');
    $antwort = ['ok' => true, 'status' => 200, 'rumpf' => '{"ok":true,"instanz":"X","token":{"name":"x","befugnisse":["dosen"]}}', 'fehler' => ''];
    $r = PartnerService::anlegen('x', 'http://ma.example', $test['token']);
    pruefe(!$r['ok'] && str_contains($r['message'], 'plaene.lesen'), 'ohne „plaene.lesen“ dort wird nichts eingetragen');
    Http::$attrappe = null;

    // ================================================= MasterAdmin als A (Attrappe, hier im Prüfprozess)
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/hilfen/location-lager/location.json'), true);
    $svg = (string) file_get_contents(__DIR__ . '/hilfen/location-lager/plaene/grundriss.svg');
    $maKey = 'lager-' . bin2hex(random_bytes(3));
    $maToken = 'mtma_' . str_repeat('M', 43);
    $mitDosen = true;
    $maDatei = 0;
    $maLocation = static function () use ($fixture, $svg, $maKey, $marke, &$mitDosen): array {
        $loc = $fixture;
        $loc['schluessel'] = $maKey;
        $loc['name'] = $marke . ' MA Lager';
        $loc['etagen'][0]['plan']['sha256'] = hash('sha256', $svg);
        $loc['etagen'][0]['plan']['bytes'] = strlen($svg);
        $loc['etagen'][0]['bearbeiten'] = true;
        if (!$mitDosen) {
            unset($loc['etagen'][0]['dosen']);
            $loc['etagen'][0]['racks'][0]['einbauten'] = [];
        }
        $loc['etagen'][] = ['schluessel' => 'aussen', 'name' => 'Außen', 'ebene' => 0, 'reihenfolge' => 1, 'massstab' => '', 'notizen' => '',
            'plan' => ['datei' => 'plaene/aussen.webp', 'typ' => 'image', 'seite' => 1, 'originalname' => 'a.webp', 'sha256' => str_repeat('0', 64)],
            'referenz' => null, 'bearbeiten' => false, 'dosen' => [], 'racks' => [], 'aps' => []];
        return $loc;
    };
    Http::$attrappe = static function (string $m, string $url, array $o) use ($maKey, $maToken, $maLocation, $svg, &$maDatei): array {
        $json = static fn (int $s, array $d): array => ['ok' => true, 'status' => $s, 'rumpf' => (string) json_encode($d), 'fehler' => ''];
        if (($o['kopf']['Authorization'] ?? '') !== 'Bearer ' . $maToken) {
            return $json(401, ['ok' => false, 'message' => 'Kein gültiges Token.']);
        }
        $pfad = (string) substr($url, strlen('http://masteradmin.test/api/v1'));
        $loc = $maLocation();
        return match (true) {
            $pfad === '/info' => $json(200, ['ok' => true, 'instanz' => 'MasterAdmin Lager', 'api' => 1, 'format' => 1,
                'token' => ['name' => 'PowerManagement', 'befugnisse' => ['plaene.lesen', 'plaene.vorschlagen', 'dosen', 'patchfelder']]]),
            $pfad === '/locations' => $json(200, ['ok' => true, 'locations' => [['schluessel' => $maKey, 'name' => $loc['name'], 'stand' => substr(hash('sha256', json_encode($loc)), 0, 16),
                'etagen' => array_map(static fn ($e) => ['schluessel' => $e['schluessel'], 'name' => $e['name'], 'plan' => true, 'bearbeiten' => $e['bearbeiten'],
                    'dosen' => count((array) ($e['dosen'] ?? [])), 'racks' => count((array) ($e['racks'] ?? []))], $loc['etagen'])]]]),
            $pfad === '/locations/' . $maKey => $json(200, ['ok' => true, 'location' => $loc, 'stand' => substr(hash('sha256', json_encode($loc)), 0, 16),
                'strom' => ['art' => 'strom', 'format' => 2, 'anlagen' => [['schluessel' => 'x', 'art' => 'anschluss', 'fest' => true, 'name' => 'darf nicht kommen']]]]),
            $pfad === '/locations/' . $maKey . '/datei?pfad=plaene%2Fgrundriss.svg' => (function () use ($svg, &$maDatei): array {
                $maDatei++;
                return ['ok' => true, 'status' => 200, 'rumpf' => $svg, 'fehler' => ''];
            })(),
            default => $json(404, ['ok' => false, 'message' => 'Unbekannte Adresse.']),
        };
    };
    $r = PartnerService::anlegen('', 'http://masteradmin.test/', $maToken);
    pruefe($r['ok'], 'MasterAdmin eingetragen: ' . $r['message']);
    $maPartner = (int) ($r['id'] ?? 0);
    $ma = PartnerService::find($maPartner);
    gleich([$ma['remote_produkt'] ?? null, $ma['remote_name'] ?? null, $ma['powermanagement'] ?? null], ['', 'MasterAdmin Lager', false], 'Gegenseite MasterAdmin (ohne „produkt“)');
    $liste = PartnerAbgleich::locations($ma);
    gleich([$liste['ok'], $liste['locations'][0]['zustand'] ?? null, $liste['locations'][0]['feststrom'] ?? null], [true, 'neu', false], 'MasterAdmin: Location noch nicht hier, kein Feststrom');
    $r = PartnerAbgleich::uebernehmen($ma, $maKey, null, null, true);
    pruefe(!$r['ok'] && str_contains($r['message'], 'nur von einer PowerManagement-Instanz'), 'Feststrom von MasterAdmin → abgelehnt');
    $r = PartnerAbgleich::uebernehmen($ma, $maKey, null, null, false);
    pruefe($r['ok'] && str_contains($r['message'], 'webp'), 'von MasterAdmin übernommen, webp übergangen mit Hinweis: ' . $r['message']);
    $maSite = (int) ($r['site_id'] ?? 0);
    $maFloors = Database::select('SELECT * FROM floors WHERE site_id = ? ORDER BY sort_order', [$maSite]);
    $fremd = json_decode((string) ($maFloors[0]['fremdbestand'] ?? ''), true);
    $watt = array_sum(array_map(static fn ($e) => (int) ($e['watt'] ?? 0), (array) ($fixture['etagen'][0]['racks'][0]['einbauten'] ?? [])));
    gleich([count($maFloors), count($fremd['dosen'] ?? []), $fremd['racks'][0]['watt'] ?? null, count($fremd['aps'] ?? [])],
        [2, count($fixture['etagen'][0]['dosen']), $watt, count($fixture['etagen'][0]['aps'] ?? [])], 'Fremdbestand: Dosen, Racks (Watt summiert), APs');
    pruefe(hash_file('sha256', (string) SiteService::planPath($maFloors[0])) === hash('sha256', $svg) && SiteService::planPath($maFloors[1]) === null,
        'Grundriss da, die webp-Etage ohne Plan');
    pruefe(PlanMassstabService::lesen($maFloors[0]) !== null && (float) PlanMassstabService::lesen($maFloors[0])['meter'] === 40.0, 'Maßstab von MasterAdmin übernommen');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE site_id = ?', [$maSite]), 0, 'von MasterAdmin kommt kein strom');
    gleich(PartnerAbgleich::locations($ma)['locations'][0]['zustand'] ?? null, 'aktuell', 'MasterAdmin: danach aktuell');
    $mitDosen = false;
    $r = PartnerAbgleich::uebernehmen($ma, $maKey, null, null, false);
    $fremd2 = json_decode((string) Database::scalar('SELECT fremdbestand FROM floors WHERE id = ?', [(int) $maFloors[0]['id']]), true);
    pruefe($r['ok'] && count($fremd2['dosen'] ?? []) === count($fremd['dosen']) && ($fremd2['racks'][0]['watt'] ?? null) === 0,
        'Antwort ohne „dosen“: die Dosen bleiben, die Racks kommen neu');
    gleich($maDatei, 1, 'der unveränderte Plan wird kein zweites Mal geholt');
    Http::$attrappe = null;
} finally {
    Http::$attrappe = null;
    alsBenutzer(null);
    if ($maPartner !== null && $maPartner > 0) {
        PartnerService::loeschen($maPartner);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_partner' AND target_id = ?", [(string) $maPartner]);
    }
    $geloescht = ['site' => [], 'floor' => []];
    foreach (array_filter(array_merge(array_values($sites), [(int) $maSite])) as $siteId) {
        $geloescht['site'][] = (string) $siteId;
        foreach (Database::select('SELECT id FROM floors WHERE site_id = ?', [$siteId]) as $f) {
            $geloescht['floor'][] = (string) $f['id'];
        }
    }
    if ($maSite !== null && $maSite > 0) {
        SiteService::deleteSite($maSite);
    }
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
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR username LIKE ? OR details LIKE ?', [$marke . '%', 'api:' . $marke . '%', '%' . $marke . '%']);
    foreach ($benutzer as $id) {
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $id]);
    }
    foreach ($geloescht as $art => $ids) {
        foreach ($ids as $id) {
            Database::execute("DELETE FROM audit_log WHERE target_type = ? AND target_id = ? AND action LIKE '%.delete'", [$art, $id]);
        }
    }
    // Was B in storage/plans angelegt hat (gleicher Speicher wie hier)
    $planeJetzt = dateienUnter($planDir);
    foreach (array_diff($planeJetzt, $planeVorher) as $neu) {
        is_dir($neu) ? @rmdir($neu) : @unlink($neu);
    }
    foreach ($dosen as $d) {
        @unlink($d);
    }
    // Das Log ist dasselbe wie in B: kein Token, nichts Verschlüsseltes
    $log = is_file($logDatei) ? (string) file_get_contents($logDatei, false, null, $logVorher) : '';
    pruefe(array_filter($geheim, static fn (string $g): bool => $g !== '' && str_contains($log, $g)) === [], 'weder Token noch Verschlüsseltes im Log');
    foreach (['b.php', 'b.sqlite', 'b.sqlite-wal', 'b.sqlite-shm', 'b.sqlite-journal', 'migrate.txt'] as $f) {
        @unlink($tmp . '/' . $f);
    }
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}

ende('schnittstelle-partner');
