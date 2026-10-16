<?php
declare(strict_types=1);

/**
 * Änderungen von außen (Seite A): Vorschläge und direktes Schreiben.
 *
 * Ein eigener PHP-Server; die Schnittstelle wird von außen aufgerufen wie
 * von einer anderen Instanz, angenommen und abgelehnt wird hier im Prozess
 * (als Benutzer mit verschiedenen Rechten) und über die Oberfläche mit
 * Anmeldung und CSRF-Token. Geprüft wird:
 *
 *   - jede Ablehnung beim Eingang (Befugnis, nur „sehen“, Schlüssel, Format,
 *     unbekannte Etage, Anlage ohne Etage, Feststrom ohne Befugnis, Dateien:
 *     außerhalb von plaene/, ohne Plan, kaputtes base64, falsche Prüfsumme,
 *     PHP als .svg, webp, zu groß; zu viele offene) – und dass danach nichts
 *     gespeichert und nichts geändert ist;
 *   - ein Körper in der Form von MasterAdmin (aps, dosen, racks, referenz:
 *     null, Datei unter dem eigenen Etagenschlüssel), Ersetzen durch einen
 *     neueren Vorschlag, Unterschiede je Etage (neue Plandatei, Maßstab,
 *     Umbenennung nur zur Information, Feststrom + ~ −, neuer Steckertyp);
 *   - Annehmen nur mit Rechten; danach bleiben Namen, Schaltsperre,
 *     Messpunkt (mit gültigem Anschluss), Georeferenz der nicht gewählten
 *     Etage, eine im Vorschlag fehlende Anlage; referenz: null löscht keinen
 *     Maßstab, ein Plan ohne Datei bleibt; Ablehnen;
 *   - „plaene.schreiben“: mit passendem Stand sofort übernommen, mit
 *     veraltetem 409, mit „erzwingen“ übernommen, ohne Stand ein Vorschlag,
 *     nur „sehen“ 403, unbekannter Steckertyp 422 – mit denselben
 *     Schutzregeln;
 *   - die Oberfläche (Liste, Vorschlag, Datei nur als Download, CSRF, wer
 *     was sieht) und das Aufräumen (bin/power wartung).
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\ApiZugaenge;
use App\Services\BenutzerService;
use App\Services\LeitungService;
use App\Services\LocationBundle;
use App\Services\MesspunktService;
use App\Services\PartnerAbgleich;
use App\Services\PlanGeoService;
use App\Services\PlanMassstabService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Services\VorschlagService;
use App\Support\Database;
use App\Support\Fingerabdruck;

if (!function_exists('curl_init')) {
    uebersprungen('schnittstelle-vorschlaege', 'die PHP-Erweiterung curl fehlt');
}

$wurzel = dirname(__DIR__);
$port = freierPort();
hintergrund([PHP_BINARY, '-d', 'post_max_size=66M', '-d', 'memory_limit=512M', '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public']);
for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $port)) {
    uebersprungen('schnittstelle-vorschlaege', 'eingebauter PHP-Server startet nicht');
}
$basis = 'http://127.0.0.1:' . $port;

/** Ein Aufruf der Oberfläche mit eigener Cookie-Dose; folgt keinen Weiterleitungen. */
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

/** @return array{dose:string,token:string} */
function anmelden(string $basis, string $name, string $kennwort, string $tmp): array
{
    $dose = (string) tempnam($tmp, 'dose-');
    $seite = ui($basis, $dose, 'GET', '/login');
    $r = ui($basis, $dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => csrf($seite['text'])]);
    pruefe($r['status'] === 302 && $r['ziel'] !== '/login', 'Anmeldung ' . $name);
    return ['dose' => $dose, 'token' => csrf(ui($basis, $dose, 'GET', '/profil')['text'])];
}

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

/** Einen Ordner mit allem darin löschen – nur unter $unter. */
function ordnerWeg(string $ordner, string $unter): void
{
    $echt = realpath($ordner);
    $grenze = realpath($unter);
    if ($echt === false || $grenze === false || !str_starts_with($echt, $grenze . '/')) {
        return;
    }
    foreach (dateienUnter($echt) as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($echt);
}

$marke = 'pm-api-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . '1';
$tmp = sys_get_temp_dir() . '/' . $marke;
mkdir($tmp, 0700, true);
$ablage = $wurzel . '/storage/vorschlaege';
$ablageVorher = is_dir($ablage) ? array_values(array_diff(scandir($ablage) ?: [], ['.', '..'])) : [];
$ablageJetzt = static fn (): array => is_dir($ablage) ? array_values(array_diff(scandir($ablage) ?: [], ['.', '..'])) : [];
$planDir = SiteService::planDir();
$planeVorher = dateienUnter($planDir);
$logDatei = $wurzel . '/storage/logs/app.log';
$logVorher = is_file($logDatei) ? (int) filesize($logDatei) : 0;
$stecker = 'pm-neu-' . bin2hex(random_bytes(3));
$json = static fn (mixed $d): string => (string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

$benutzer = [];
$rollen = [];
$sites = [];
$tokenIds = [];
$geheim = [];
$dosen = [];

try {
    // ================================================= Bestand in A
    $benutzer['admin'] = BenutzerService::anlegen($marke . '-admin', $kennwort, 'admin', false);
    alsBenutzer($benutzer['admin']);
    $rolle = static function (string $name, array $rechte) use ($marke, &$rollen): string {
        $id = (int) BenutzerService::rolleSpeichern(null, $marke . ' ' . $name, '', $rechte)['id'];
        $rollen[] = $id;
        return (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$id]);
    };
    $grund = ['api.vorschlaege', 'karte.view', 'dashboard.view', 'anlagen.view'];
    $benutzer['leser'] = BenutzerService::anlegen($marke . '-leser', $kennwort, $rolle('leser', $grund), false);
    $benutzer['planer'] = BenutzerService::anlegen($marke . '-planer', $kennwort, $rolle('planer', [...$grund, 'plans.manage', 'anlagen.manage']), false);
    $benutzer['elektro'] = BenutzerService::anlegen($marke . '-elektro', $kennwort,
        $rolle('elektro', [...$grund, 'plans.manage', 'anlagen.manage', 'leitungen.manage']), false);
    $benutzer['fremder'] = BenutzerService::anlegen($marke . '-fremder', $kennwort, $rolle('fremder', $grund), false);

    $typ = [];
    foreach (AnlagenService::steckertypen() as $t) {
        $typ[(string) $t['schluessel']] = (int) $t['id'];
    }
    $cee32 = $typ['cee32'] ?? reset($typ);

    $sites['halle'] = SiteService::saveSite(null, ['name' => $marke . ' Halle', 'address' => 'Halle 1', 'notes' => 'Prüfung']);
    $f1 = SiteService::saveFloor(null, ['site_id' => $sites['halle'], 'name' => 'Erdgeschoss', 'level_no' => 0, 'sort_order' => 0]);
    $f2 = SiteService::saveFloor(null, ['site_id' => $sites['halle'], 'name' => 'Obergeschoss', 'level_no' => 1, 'sort_order' => 1]);
    $svg = (string) file_get_contents(__DIR__ . '/hilfen/location-lager/plaene/grundriss.svg');
    $planTmp = (string) tempnam($tmp, 'plan-');
    copy(__DIR__ . '/hilfen/location-lager/plaene/grundriss.svg', $planTmp);
    pruefe(SiteService::uploadPlan($sites['halle'], ['name' => 'grundriss.svg', 'type' => 'image/svg+xml', 'tmp_name' => $planTmp,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($planTmp)], ['floor_ids' => [$f1]])['ok'], 'A: Grundriss hochgeladen');
    PlanMassstabService::speichern($f1, ['x' => 10.0, 'y' => 90.0], ['x' => 60.0, 'y' => 90.0], 120.0, 1.0);
    $kx = 6371008.8 * M_PI / 180 * cos(deg2rad(51.44));
    PlanGeoService::speichern($f1, ['x' => 10.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56], ['x' => 60.0, 'y' => 90.0, 'lat' => 51.44, 'lon' => 7.56 + 100 / $kx], 1.0);
    PlanGeoService::speichern($f2, ['x' => 5.0, 'y' => 5.0, 'lat' => 48.0, 'lon' => 11.0], ['x' => 95.0, 'y' => 5.0, 'lat' => 48.0, 'lon' => 11.0 + 100 / $kx], 1.0);
    $planShaA = (string) hash_file('sha256', (string) SiteService::planPath(SiteService::floor($f1)));

    $ha = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => $marke . ' Hausanschluss', 'floor_id' => $f1, 'plan_x' => 10, 'plan_y' => 10])['id'];
    AnlagenService::anschlussAnlegen($ha, 'abgang', $cee32);
    SchutzService::vorschlagen($ha);
    Database::update('objekte', $ha, ['steuerung_gesperrt' => 1]);
    $abgaenge = Database::select("SELECT id, nr FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr", [$ha]);
    $mp = MesspunktService::speichern($ha, null, ['geraet' => $marke . '-mp', 'kanal' => 'em:0', 'anschluss_id' => (int) $abgaenge[0]['id']]);
    pruefe($mp['ok'] && count($abgaenge) === 2, 'A: Hausanschluss mit zwei Abgängen, Schaltsperre und Messpunkt an Abgang 1');
    $mpId = (int) $mp['id'];
    $mpAb = (string) Database::scalar('SELECT gueltig_ab FROM messpunkte WHERE id = ?', [$mpId]);
    $saeule = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'fest' => 1, 'name' => $marke . ' Säule', 'floor_id' => $f1, 'plan_x' => 40, 'plan_y' => 10])['id'];
    $saeuleEin = AnlagenService::anschlussAnlegen($saeule, 'eingang', $typ['cee125'] ?? $cee32);
    $wand = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'fest' => 1, 'name' => $marke . ' Wandverteiler', 'floor_id' => $f1, 'plan_x' => 70, 'plan_y' => 70])['id'];
    $kabel = LeitungService::speichern(null, ['art' => 'kabel', 'fest' => 1, 'floor_id' => $f1, 'name' => $marke . ' Erdkabel', 'von_objekt_id' => $ha,
        'von_anschluss_id' => (int) $abgaenge[0]['id'], 'zu_objekt_id' => $saeule, 'zu_anschluss_id' => $saeuleEin, 'punkte' => [[10, 10], [40, 10]]]);
    pruefe($kabel['ok'], 'A: festes Kabel Hausanschluss → Säule');
    $kabelId = (int) $kabel['id'];

    $sites['lager'] = SiteService::saveSite(null, ['name' => $marke . ' Lager']);
    SiteService::saveFloor(null, ['site_id' => $sites['lager'], 'name' => 'Halle']);
    $sites['fremd'] = SiteService::saveSite(null, ['name' => $marke . ' Fremd']);
    SiteService::saveFloor(null, ['site_id' => $sites['fremd'], 'name' => 'Halle']);

    foreach ([['leser', 0], ['planer', 1], ['elektro', 1]] as [$wer, $bearbeiten]) {
        BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer[$wer], 'ziel_art' => 'site', 'ziel_id' => $sites['halle'], 'darf_bearbeiten' => $bearbeiten]);
    }

    $halleKey = LocationBundle::schluesselSichern('sites', $sites['halle'], $marke . ' Halle');
    $lagerKey = LocationBundle::schluesselSichern('sites', $sites['lager'], $marke . ' Lager');
    $fremdKey = LocationBundle::schluesselSichern('sites', $sites['fremd'], $marke . ' Fremd');
    $f1Key = LocationBundle::schluesselSichern('floors', $f1, 'Erdgeschoss');
    $f2Key = LocationBundle::schluesselSichern('floors', $f2, 'Obergeschoss');

    $token = static function (string $name, array $befugnisse, array $sehen, array $bearbeiten) use ($marke, &$tokenIds, &$geheim): array {
        $t = ApiZugaenge::anlegen(['name' => $marke . ' ' . $name, 'befugnisse' => $befugnisse, 'sehen' => $sehen, 'bearbeiten' => $bearbeiten]);
        pruefe($t['ok'], 'Token ' . $name . ': ' . $t['message']);
        $tokenIds[$name] = (int) ($t['id'] ?? 0);
        $geheim[] = (string) ($t['token'] ?? '');
        return ['token' => (string) ($t['token'] ?? ''), 'id' => (int) ($t['id'] ?? 0), 'name' => $marke . ' ' . $name];
    };
    $tVor = $token('vorschlagen', ['plaene.vorschlagen', 'feststrom'], [$sites['halle'], $sites['lager']], [$sites['halle']]);
    $tLesen = $token('lesen', ['plaene.lesen'], [$sites['halle']], [$sites['halle']]);
    $tOhneFest = $token('ohne-feststrom', ['plaene.vorschlagen'], [$sites['halle']], [$sites['halle']]);
    $tMa = $token('masteradmin', ['plaene.vorschlagen'], [$sites['halle']], [$sites['halle']]);
    $tSchreib = $token('schreiben', ['plaene.schreiben', 'feststrom'], [$sites['halle'], $sites['lager']], [$sites['halle']]);
    alsBenutzer(null);

    $detail = static fn (array $tok, ?string $key = null): array => (array) api($basis, 'GET', '/api/v1/locations/' . ($key ?? $halleKey), $tok['token'])['json'];
    $senden = static fn (array $tok, array $body, ?string $key = null): array
        => api($basis, 'POST', '/api/v1/locations/' . ($key ?? $halleKey) . '/vorschlaege', $tok['token'], $json($body));
    $index = static function (array $liste, string $key): int {
        foreach ($liste as $i => $e) {
            if (($e['schluessel'] ?? null) === $key) {
                return (int) $i;
            }
        }
        return -1;
    };
    $zeilen = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM api_vorschlaege WHERE token_id IN (' . implode(',', array_map('intval', $tokenIds)) . ')');
    /** Was A ausmacht – vor und nach dem, was nichts ändern darf. */
    $bildA = static function () use ($sites, $ha): string {
        return Fingerabdruck::von([
            Database::select('SELECT id, name, level_no, sort_order, plan_file, plan_page, scale_note, notes, massstab_m, massstab_ax, geo_ax, geo_a_lat FROM floors WHERE site_id = ? ORDER BY id', [$sites['halle']]),
            Database::select('SELECT id, name, floor_id, plan_x, plan_y, steuerung_gesperrt, sync_key FROM objekte WHERE site_id = ? ORDER BY id', [$sites['halle']]),
            Database::select('SELECT l.id, l.punkte, l.von_anschluss_id FROM leitungen l JOIN floors f ON f.id = l.floor_id WHERE f.site_id = ? ORDER BY l.id', [$sites['halle']]),
            Database::select('SELECT id, anschluss_id, gueltig_ab, gueltig_bis FROM messpunkte WHERE objekt_id = ? ORDER BY id', [$ha]),
            Database::scalar('SELECT COUNT(*) FROM steckertypen'),
        ]);
    };

    $d = $detail($tVor);
    pruefe(($d['ok'] ?? false) && isset($d['strom']['anlagen']), 'A: Detail mit Feststrom für das Token „vorschlagen“');
    $grundKoerper = ['location' => $d['location'], 'strom' => $d['strom'], 'notiz' => "Prüfung\nzweite Zeile", 'absender' => 'Instanz B', 'basis' => $d['stand']];
    $iEg = $index($grundKoerper['location']['etagen'], $f1Key);
    $iHa = $index($grundKoerper['strom']['anlagen'], (string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [$ha]));
    pruefe($iEg >= 0 && $iHa >= 0, 'A: Erdgeschoss und Hausanschluss im Detail gefunden');

    // ================================================= Ablehnungen beim Eingang
    $vorher = $bildA();
    $ablageStart = $ablageJetzt();
    $nichtsDa = static function (string $was) use ($zeilen, $bildA, $vorher, $ablageJetzt, $ablageStart): void {
        pruefe($zeilen() === 0 && $bildA() === $vorher && $ablageJetzt() === $ablageStart, $was . ': nichts gespeichert, nichts geändert');
    };
    $r = $senden($tLesen, $grundKoerper);
    gleich([$r['status'], $r['json']['message'] ?? ''], [403, 'Dem Token fehlt die Befugnis „plaene.vorschlagen“.'], 'ohne „vorschlagen“: 403');
    $lager = $detail($tVor, $lagerKey);
    $r = $senden($tVor, ['location' => $lager['location'] ?? []], $lagerKey);
    gleich([$r['status'], $r['json']['message'] ?? ''], [403, 'Dieser Standort ist für das Token nur zum Lesen freigegeben.'], 'Standort nur „sehen“: 403');
    $fremd = $senden($tVor, ['location' => ['art' => 'location', 'format' => 1, 'schluessel' => $fremdKey, 'etagen' => []]], $fremdKey);
    $nichtDa = $senden($tVor, ['location' => ['art' => 'location']], 'gibt-es-nicht-' . $marke);
    pruefe($fremd['status'] === 404 && $fremd['roh'] === $nichtDa['roh'], 'nicht freigegebener Standort: dieselbe 404 wie ein unbekannter');
    $b = $grundKoerper;
    $b['location']['schluessel'] = 'anders';
    $r = $senden($tVor, $b);
    pruefe($r['status'] === 422 && str_contains((string) ($r['json']['message'] ?? ''), 'passt nicht zur Adresse'), 'Schlüssel ≠ Adresse: 422');
    $b = $grundKoerper;
    $b['location']['format'] = 2;
    gleich($senden($tVor, $b)['status'], 422, 'location.format 2: 422');
    $b = $grundKoerper;
    $b['location']['etagen'][] = ['schluessel' => 'gibt-es-nicht', 'name' => 'Neu'];
    $r = $senden($tVor, $b);
    pruefe($r['status'] === 422 && str_contains((string) ($r['json']['message'] ?? ''), 'neue Etagen legt diese Instanz selbst an'), 'unbekannte Etage: 422 – ' . ($r['json']['message'] ?? ''));
    $b = $grundKoerper;
    $b['strom']['anlagen'][$iHa]['etage'] = null;
    $r = $senden($tVor, $b);
    pruefe($r['status'] === 422 && str_contains((string) ($r['json']['message'] ?? ''), 'auf keiner Etage'), 'Anlage ohne Etage: 422');
    $b = $grundKoerper;
    $b['strom']['anlagen'][$iHa]['fest'] = false;
    gleich($senden($tVor, $b)['status'], 422, 'mobile Anlage: 422');
    $b = $grundKoerper;
    $b['strom']['leitungen'][] = ['schluessel' => 'bruecke-' . $marke, 'art' => 'kabelbruecke', 'fest' => true, 'etage' => $f1Key, 'punkte' => []];
    gleich($senden($tVor, $b)['status'], 422, 'Kabelbrücke: 422');
    $b = $grundKoerper;
    $b['strom']['anlagen'][$iHa]['name'] = ['kein' => 'Text'];
    gleich($senden($tVor, $b)['status'], 422, 'Anlage mit verschachteltem Namen: 422');
    $r = $senden($tOhneFest, $grundKoerper);
    pruefe($r['status'] === 403 && str_contains((string) ($r['json']['message'] ?? ''), 'feststrom'), 'Feststrom ohne Befugnis „feststrom“: 403');

    $svg2 = str_replace('</svg>', '<!-- ' . $marke . ' zwei --></svg>', $svg);
    $svg3 = str_replace('</svg>', '<!-- ' . $marke . ' drei --></svg>', $svg);
    $mitDatei = static function (string $datei, string $sha, array $dateien) use ($grundKoerper, $iEg): array {
        $b = $grundKoerper;
        $b['location']['etagen'][$iEg]['plan']['datei'] = $datei;
        $b['location']['etagen'][$iEg]['plan']['sha256'] = $sha;
        $b['dateien'] = $dateien;
        return $b;
    };
    $php = "<?php echo 'x'; ?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>";
    foreach ([
        'Datei außerhalb von plaene/'  => $mitDatei('plaene/eg.svg', hash('sha256', $svg2), ['x/eg.svg' => base64_encode($svg2)]),
        'Datei ohne Plan'               => $mitDatei('plaene/eg.svg', hash('sha256', $svg2), ['plaene/anders.svg' => base64_encode($svg2)]),
        'kaputtes base64'               => $mitDatei('plaene/eg.svg', hash('sha256', $svg2), ['plaene/eg.svg' => '***kein base64***']),
        'falsche Prüfsumme'             => $mitDatei('plaene/eg.svg', hash('sha256', $svg2), ['plaene/eg.svg' => base64_encode($svg3)]),
        'PHP als .svg'                  => $mitDatei('plaene/eg.svg', hash('sha256', $php), ['plaene/eg.svg' => base64_encode($php)]),
        'webp'                          => $mitDatei('plaene/eg.webp', hash('sha256', 'webp'), ['plaene/eg.webp' => base64_encode('webp')]),
    ] as $was => $b) {
        $r = $senden($tVor, $b);
        pruefe($r['status'] === 422 && ($r['json']['ok'] ?? true) === false, $was . ': 422 – ' . ($r['json']['message'] ?? $r['status']));
    }
    $gross = $mitDatei('plaene/eg.png', str_repeat('a', 64), ['plaene/eg.png' => str_repeat('A', 41 * 1024 * 1024)]);
    $r = $senden($tVor, $gross);
    pruefe($r['status'] === 422 && str_contains((string) ($r['json']['message'] ?? ''), 'zu groß'), 'Datei über 30 MiB: 422 – ' . ($r['json']['message'] ?? $r['status']));
    unset($gross);
    // Zehn offene (anderer Standorte) – der elfte wird abgewiesen
    for ($i = 0; $i < VorschlagService::MAX_OFFEN; $i++) {
        Database::insert('api_vorschlaege', ['token_id' => $tVor['id'], 'token_name' => $tVor['name'], 'absender' => 'Füllung', 'site_id' => null,
            'site_key' => 'fuellung', 'daten' => '{}', 'status' => 'offen', 'created_at' => gmdate('c')]);
    }
    $r = $senden($tVor, $grundKoerper);
    gleich($r['status'], 429, 'elfter offener Vorschlag: 429');
    Database::execute("DELETE FROM api_vorschlaege WHERE token_id = ? AND site_key = 'fuellung'", [$tVor['id']]);
    $nichtsDa('nach allen Ablehnungen');

    // ================================================= Form von MasterAdmin
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/hilfen/location-lager/location.json'), true);
    $svgMa = str_replace('</svg>', '<!-- ' . $marke . ' MasterAdmin --></svg>', $svg);
    $ma = $fixture;
    $ma['schluessel'] = $halleKey;
    $ma['etagen'][0]['schluessel'] = $f1Key;
    $ma['etagen'][0]['plan']['sha256'] = hash('sha256', $svgMa);
    $ma['etagen'][0]['referenz'] = null;
    $ma['etagen'][0]['aps'] = [['name' => 'AP 1', 'x' => 1, 'y' => 1]];
    $r = $senden($tMa, ['location' => $ma, 'dateien' => ['plaene/grundriss.svg' => base64_encode($svgMa)], 'notiz' => 'von MasterAdmin', 'absender' => 'MasterAdmin Lager']);
    pruefe($r['status'] === 201 && ($r['json']['ok'] ?? false) && ($r['json']['uebernommen'] ?? null) === false && is_int($r['json']['id'] ?? null),
        'Körper in der Form von MasterAdmin: 201 – ' . ($r['json']['message'] ?? $r['status']));
    $maId1 = (int) ($r['json']['id'] ?? 0);
    $v = VorschlagService::find($maId1);
    $e0 = $v['daten']['location']['etagen'][0] ?? [];
    pruefe($v !== null && $v['status'] === 'offen' && !array_key_exists('aps', $e0) && !array_key_exists('dosen', $e0) && !array_key_exists('racks', $e0)
        && !array_key_exists('referenz', $e0), 'gespeichert ohne aps, dosen, racks und ohne referenz: null');
    $meta = $v['dateien']['plaene/grundriss.svg'] ?? [];
    pruefe(preg_match('/^[0-9a-f]{24}\.svg$/', (string) ($meta['datei'] ?? '')) === 1 && is_file(VorschlagService::ordner($maId1) . '/' . ($meta['datei'] ?? ''))
        && hash_file('sha256', VorschlagService::ordner($maId1) . '/' . ($meta['datei'] ?? 'x')) === hash('sha256', $svgMa), 'Datei mit Zufallsnamen und Endung abgelegt');
    gleich([$v['absender'], $v['basis_stand'], $v['token_name']], ['MasterAdmin Lager', '', $tMa['name']], 'Absender aus dem Körper, kein Stand, Zugang');
    $audit = Database::first("SELECT * FROM audit_log WHERE action = 'api.vorschlag.eingang' AND target_id = ?", [(string) $maId1]);
    gleich($audit['username'] ?? null, 'api:' . $tMa['name'], 'Audit api.vorschlag.eingang mit dem Token als Handelndem');
    $ma2 = $ma;
    unset($ma2['etagen'][0]['aps']);
    $r = $senden($tMa, ['location' => $ma2, 'notiz' => 'zweiter Versuch', 'absender' => 'MasterAdmin Lager']);
    $maId2 = (int) ($r['json']['id'] ?? 0);
    $v1 = VorschlagService::find($maId1);
    pruefe($r['status'] === 201 && $v1['status'] === 'ersetzt' && (int) ($v1['entscheidung']['ersetzt_durch'] ?? 0) === $maId2 && !is_dir(VorschlagService::ordner($maId1)),
        'ein neuerer Vorschlag desselben Tokens ersetzt den älteren und räumt seinen Ordner');

    // ================================================= der Vorschlag mit allem
    $b = $grundKoerper;
    $haKey = (string) $b['strom']['anlagen'][$iHa]['schluessel'];
    $saeuleKey = (string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [$saeule]);
    $wandKey = (string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [$wand]);
    $b['location']['etagen'][$iEg]['name'] = 'Erdgeschoss dort';
    $b['location']['etagen'][$iEg]['plan']['datei'] = 'plaene/' . $f1Key . '.svg';
    $b['location']['etagen'][$iEg]['plan']['sha256'] = hash('sha256', $svg2);
    $b['location']['etagen'][$iEg]['referenz']['meter'] = 99.0;
    $b['location']['etagen'][$iEg]['massstab'] = '1:200';
    $b['strom']['georeferenz'][$f2Key]['a']['lat'] = 47.5;
    $b['strom']['georeferenz'][$f2Key]['b']['lat'] = 47.5;
    $b['strom']['anlagen'][$iHa]['x'] = 55.0;
    $b['strom']['anlagen'][$iHa]['steuerung_gesperrt'] = false;
    $iWand = $index($b['strom']['anlagen'], $wandKey);
    array_splice($b['strom']['anlagen'], $iWand, 1);
    $neu = $b['strom']['anlagen'][$index($b['strom']['anlagen'], $saeuleKey)];
    $neu['schluessel'] = $marke . '-neue-saeule';
    $neu['name'] = $marke . ' Neue Säule';
    $neu['x'] = 80.0;
    $neu['anschluesse'] = [['richtung' => 'eingang', 'nr' => 1, 'stecker' => $stecker, 'bezeichnung' => '', 'schutz' => null, 'phase' => '', 'notizen' => '']];
    $b['strom']['anlagen'][] = $neu;
    $b['strom']['steckertypen'][] = ['schluessel' => $stecker, 'name' => 'Prüfstecker', 'strom_a' => 63, 'phasen' => 3, 'spannung_v' => 400, 'farbe' => '#123456'];
    $b['strom']['leitungen'][0]['punkte'] = [[10.0, 10.0], [25.0, 15.0], [40.0, 10.0]];
    $b['dateien'] = ['plaene/' . $f1Key . '.svg' => base64_encode($svg2)];
    $r = $senden($tVor, $b);
    pruefe($r['status'] === 201 && ($r['json']['uebernommen'] ?? null) === false, 'Vorschlag mit Plan, Maßstab, Georeferenz und Feststrom: 201 – ' . ($r['json']['message'] ?? $r['status']));
    $vid = (int) ($r['json']['id'] ?? 0);
    gleich($bildA(), $vorher, 'vor dem Annehmen: hier ist nichts geändert');
    pruefe(hash_file('sha256', (string) SiteService::planPath(SiteService::floor($f1))) === $planShaA, '… auch der Grundriss nicht');

    alsBenutzer($benutzer['admin']);
    $v = VorschlagService::find($vid);
    $u = VorschlagService::unterschiede($v);
    $eg = array_column($u['etagen'], null, 'schluessel')[$f1Key] ?? [];
    $og = array_column($u['etagen'], null, 'schluessel')[$f2Key] ?? [];
    gleich([$eg['plan'] ?? null, $eg['plan_datei'] ?? null, $eg['massstab'] ?? null, $eg['massstab_text'] ?? null, $eg['geaendert'] ?? null],
        ['neu', 'plaene/' . $f1Key . '.svg', true, true, true], 'Unterschiede: neue Plandatei, Maßstab und Maßstab-Text geändert');
    pruefe(str_contains(implode(' ', (array) ($eg['info'] ?? [])), 'heißt dort „Erdgeschoss dort“ – der Name hier bleibt'), 'Umbenennung nur zur Information');
    gleich([$eg['anlagen']['neu'] ?? null, $eg['anlagen']['geaendert'] ?? null, $eg['anlagen']['fehlt'] ?? null, $eg['leitungen']['geaendert'] ?? null],
        [[$marke . ' Neue Säule'], [$marke . ' Hausanschluss'], [$marke . ' Wandverteiler'], [$marke . ' Erdkabel']], 'Feststrom: + neu, ~ geändert, − fehlt (die unveränderte Säule zählt nicht)');
    gleich([$og['georeferenz'] ?? null, $og['plan'] ?? null, $og['geaendert'] ?? null], ['geaendert', 'ohne', true], 'Obergeschoss: nur die Georeferenz geändert');
    gleich([$u['stecker_neu'], $u['hinweise']], [[$stecker], []], 'neuer Steckertyp; Stand passt, kein Hinweis');

    // ================================================= Annehmen nur mit Rechten
    foreach ([['leser', 'darfst du nichts ändern'], ['planer', 'Kabel einzeichnen'], ['elektro', $stecker]] as [$wer, $text]) {
        alsBenutzer($benutzer[$wer]);
        Zugriff::vergessen();
        $a = VorschlagService::annehmen($vid, [$f1Key], ['grundriss', 'feststrom']);
        pruefe(!$a['ok'] && str_contains($a['message'], $text), $wer . ' darf nicht annehmen: ' . $a['message']);
    }
    gleich([$bildA(), VorschlagService::find($vid)['status']], [$vorher, 'offen'], 'abgelehntes Annehmen ändert nichts');
    alsBenutzer($benutzer['admin']);
    Zugriff::vergessen();
    gleich(VorschlagService::annehmen($vid, [], ['grundriss'])['ok'], false, 'ohne Etage: nichts angenommen');
    $a = VorschlagService::annehmen($vid, [$f1Key], ['grundriss', 'feststrom']);
    pruefe($a['ok'], 'Admin nimmt das Erdgeschoss an: ' . $a['message']);
    $floor1 = SiteService::floor($f1);
    $floor2 = SiteService::floor($f2);
    $haJetzt = AnlagenService::find($ha);
    $mpJetzt = Database::first('SELECT m.*, a.objekt_id AS a_objekt, a.richtung, a.nr FROM messpunkte m LEFT JOIN anschluesse a ON a.id = m.anschluss_id WHERE m.id = ?', [$mpId]);
    $neueSaeule = Database::first('SELECT * FROM objekte WHERE site_id = ? AND name = ?', [$sites['halle'], $marke . ' Neue Säule']);
    gleich([SiteService::find($sites['halle'])['name'], $floor1['name'], (int) $floor1['sort_order'], $floor2['name']],
        [$marke . ' Halle', 'Erdgeschoss', 0, 'Obergeschoss'], 'Namen und Reihenfolge hier bleiben');
    pruefe(str_starts_with((string) $floor1['plan_file'], 'vorschlag') && hash_file('sha256', (string) SiteService::planPath($floor1)) === hash('sha256', $svg2),
        'der Grundriss kommt aus dem Vorschlag (vorschlag…)');
    gleich([(float) PlanMassstabService::lesen($floor1)['meter'], (string) $floor1['scale_note']], [99.0, '1:200'], 'Maßstab und Maßstab-Text aus dem Vorschlag');
    gleich(round((float) PlanGeoService::lesen($floor2)['a']['lat'], 4), 48.0, 'Georeferenz der nicht gewählten Etage bleibt');
    gleich([(float) $haJetzt['plan_x'], (int) $haJetzt['steuerung_gesperrt']], [55.0, 1], 'Hausanschluss verschoben, die Schaltsperre hier bleibt');
    pruefe($mpJetzt !== null && (string) $mpJetzt['gueltig_ab'] === $mpAb && $mpJetzt['gueltig_bis'] === null && (int) $mpJetzt['a_objekt'] === $ha
        && $mpJetzt['richtung'] . ':' . $mpJetzt['nr'] === 'abgang:1', 'Messpunkt bleibt mit gültigem Anschluss (abgang:1) und gleichem gueltig_ab');
    pruefe(AnlagenService::find($wand) !== null && (int) AnlagenService::find($wand)['site_id'] === $sites['halle'], 'die im Vorschlag fehlende Anlage bleibt');
    pruefe($neueSaeule !== null && (int) Database::scalar('SELECT COUNT(*) FROM steckertypen WHERE schluessel = ?', [$stecker]) === 1,
        'neue Säule angelegt, ihr Steckertyp im Katalog');
    gleich(array_map(static fn ($p) => [$p['x'], $p['y']], LeitungService::find($kabelId)['punkte']), [[10.0, 10.0], [25.0, 15.0], [40.0, 10.0]], 'Kabelweg aus dem Vorschlag');
    $v = VorschlagService::find($vid);
    gleich([$v['status'], $v['entscheidung'], is_dir(VorschlagService::ordner($vid))], ['angenommen', ['etagen' => [$f1Key], 'teile' => ['grundriss', 'feststrom']], false],
        'angenommen, Entscheidung vermerkt, Ordner geräumt');
    pruefe(Database::first("SELECT id FROM audit_log WHERE action = 'api.vorschlag.angenommen' AND target_id = ?", [(string) $vid]) !== null, 'Audit api.vorschlag.angenommen');
    gleich(VorschlagService::annehmen($vid, [$f1Key], ['grundriss'])['ok'], false, 'ein angenommener Vorschlag lässt sich nicht noch einmal annehmen');

    // MasterAdmin-Vorschlag ohne Datei, referenz: null
    $u = VorschlagService::unterschiede(VorschlagService::find($maId2));
    $eg = array_column($u['etagen'], null, 'schluessel')[$f1Key] ?? [];
    pruefe(($eg['plan'] ?? null) === 'fehlt' && ($eg['massstab'] ?? null) === false && str_contains(implode(' ', $u['hinweise']), 'keinen Stand mitgeschickt'),
        'Unterschiede: Plan geändert, Datei fehlt; kein Maßstab; Hinweis ohne Stand');
    $planVorher = (string) SiteService::floor($f1)['plan_file'];
    $a = VorschlagService::annehmen($maId2, [$f1Key], ['grundriss', 'feststrom']);
    $floor1 = SiteService::floor($f1);
    pruefe($a['ok'] && (string) $floor1['plan_file'] === $planVorher && (float) PlanMassstabService::lesen($floor1)['meter'] === 99.0,
        'MasterAdmin-Vorschlag angenommen: der Plan ohne Datei bleibt, referenz: null löscht keinen Maßstab – ' . $a['message']);

    // ================================================= Ablehnen über die Oberfläche
    $b = $mitDatei('plaene/eg.svg', hash('sha256', $svg3), ['plaene/eg.svg' => base64_encode($svg3)]);
    unset($b['strom']);
    $r = $senden($tVor, $b);
    $rid = (int) ($r['json']['id'] ?? 0);
    pruefe($r['status'] === 201 && is_dir(VorschlagService::ordner($rid)), 'weiterer Vorschlag mit Datei eingegangen');
    $u = VorschlagService::unterschiede(VorschlagService::find($rid));
    pruefe(str_contains(implode(' ', $u['hinweise']), 'Hier hat sich seit dem Abruf etwas geändert'), 'Hinweis: Stand veraltet (hier inzwischen angenommen)');
    alsBenutzer(null);
    $admin = anmelden($basis, $marke . '-admin', $kennwort, $tmp);
    $dosen[] = $admin['dose'];
    foreach (['/vorschlaege', '/vorschlaege/' . $rid, '/vorschlaege/' . $vid, '/vorschlaege/' . $maId1] as $pfad) {
        $s = ui($basis, $admin['dose'], 'GET', $pfad);
        pruefe($s['status'] === 200 && phpMeldung($s['text']) === null, 'Oberfläche ' . $pfad . ' → ' . $s['status'] . ' ' . (string) phpMeldung($s['text']));
    }
    $s = ui($basis, $admin['dose'], 'GET', '/vorschlaege/' . $rid);
    pruefe(str_contains($s['text'], 'name="etagen[]" value="' . $f1Key . '"') && str_contains($s['text'], 'Annehmen') && str_contains($s['text'], 'Prüfung' . "\n" . 'zweite Zeile'),
        'Vorschlagsseite: Etagen zum Ankreuzen, Annehmen, Notiz');
    $dl = ui($basis, $admin['dose'], 'GET', '/vorschlaege/' . $rid . '/datei?pfad=' . rawurlencode('plaene/eg.svg'));
    pruefe($dl['status'] === 200 && $dl['text'] === $svg3 && stripos($dl['kopf'], 'Content-Type: application/octet-stream') !== false
        && stripos($dl['kopf'], 'attachment') !== false && stripos($dl['kopf'], 'sandbox') !== false, 'Plandatei nur als Download (octet-stream, attachment, sandbox)');
    foreach (['../../config/config.php', 'plaene/../x', '', 'plaene/anders.svg', VorschlagService::find($rid)['dateien']['plaene/eg.svg']['datei'] ?? 'x'] as $falsch) {
        gleich(ui($basis, $admin['dose'], 'GET', '/vorschlaege/' . $rid . '/datei?pfad=' . rawurlencode($falsch))['status'], 404, 'Datei „' . $falsch . '“ → 404');
    }
    gleich(ui($basis, $admin['dose'], 'GET', '/vorschlaege/999999999')['status'], 404, 'unbekannter Vorschlag → 404');
    $r = ui($basis, $admin['dose'], 'POST', '/vorschlaege/' . $rid . '/ablehnen', ['grund' => 'ohne CSRF']);
    $r2 = ui($basis, $admin['dose'], 'POST', '/vorschlaege/' . $rid . '/annehmen', ['etagen' => [$f1Key], 'teile' => ['grundriss']]);
    pruefe($r['status'] === 302 && $r2['status'] === 302 && VorschlagService::find($rid)['status'] === 'offen', 'ohne CSRF-Token weder abgelehnt noch angenommen');
    $r = ui($basis, $admin['dose'], 'POST', '/vorschlaege/' . $rid . '/ablehnen', ['grund' => 'passt so nicht', '_token' => $admin['token']]);
    $v = VorschlagService::find($rid);
    pruefe($r['status'] === 302 && $r['ziel'] === '/vorschlaege/' . $rid && $v['status'] === 'abgelehnt' && ($v['entscheidung']['grund'] ?? '') === 'passt so nicht'
        && !is_dir(VorschlagService::ordner($rid)), 'Ablehnen: Zustand, Grund, Ordner geräumt');
    pruefe(Database::first("SELECT id FROM audit_log WHERE action = 'api.vorschlag.abgelehnt' AND target_id = ?", [(string) $rid]) !== null, 'Audit api.vorschlag.abgelehnt');
    gleich((string) SiteService::floor($f1)['plan_file'], $planVorher, 'abgelehnt: der Grundriss hier bleibt');

    // Wer was sieht
    $leser = anmelden($basis, $marke . '-leser', $kennwort, $tmp);
    $fremder = anmelden($basis, $marke . '-fremder', $kennwort, $tmp);
    $dosen[] = $leser['dose'];
    $dosen[] = $fremder['dose'];
    $s = ui($basis, $leser['dose'], 'GET', '/vorschlaege/' . $vid);
    pruefe($s['status'] === 200 && str_contains(ui($basis, $leser['dose'], 'GET', '/vorschlaege')['text'], '/vorschlaege/' . $vid . '"'),
        'mit „sehen“ am Standort: Vorschlag sichtbar');
    gleich(ui($basis, $fremder['dose'], 'GET', '/vorschlaege/' . $vid)['status'], 404, 'ohne Freigabe am Standort: 404');
    pruefe(!str_contains(ui($basis, $fremder['dose'], 'GET', '/vorschlaege')['text'], '/vorschlaege/' . $vid . '"'), '… und nicht in der Liste');

    // ================================================= plaene.schreiben: direkt
    $vorDirekt = $zeilen();
    $d = $detail($tSchreib);
    $s0 = (string) ($d['stand'] ?? '');
    $iEgS = $index($d['location']['etagen'], $f1Key);
    $iHaS = $index($d['strom']['anlagen'], $haKey);
    $direkt = ['location' => $d['location'], 'strom' => $d['strom'], 'absender' => 'Instanz B', 'basis' => $s0];
    $direkt['location']['etagen'][$iEgS]['name'] = 'umbenannt dort';
    $direkt['location']['etagen'][$iEgS]['massstab'] = '1:300';
    unset($direkt['location']['etagen'][$iEgS]['referenz'], $direkt['strom']['georeferenz']);
    $direkt['strom']['anlagen'][$iHaS]['x'] = 66.0;
    $direkt['strom']['anlagen'][$iHaS]['steuerung_gesperrt'] = false;
    $geoVorher = PlanGeoService::alsDaten(PlanGeoService::lesen(SiteService::floor($f1)));
    $r = $senden($tSchreib, $direkt);
    $neuerStand = (string) ($r['json']['stand'] ?? '');
    pruefe($r['status'] === 201 && ($r['json']['uebernommen'] ?? null) === true && ($r['json']['message'] ?? '') === 'Übernommen.'
        && preg_match('/^[0-9a-f]{16}$/', $neuerStand) === 1 && $neuerStand !== $s0, 'schreiben mit passendem Stand: sofort übernommen, neuer Stand – ' . ($r['json']['message'] ?? $r['status']));
    gleich((string) ($detail($tSchreib)['stand'] ?? ''), $neuerStand, '… der neue Stand ist der, den ein Abruf jetzt liefert');
    $did = (int) ($r['json']['id'] ?? 0);
    $v = VorschlagService::find($did);
    gleich([$v['status'] ?? null, $v['entschieden_von'] ?? null, $v['entscheidung']['direkt'] ?? null, $v['entscheidung']['teile'] ?? null],
        ['angenommen', 'api:' . $tSchreib['name'], true, ['grundriss', 'feststrom']], 'Zeile: angenommen, vom Token, direkt');
    $haJetzt = AnlagenService::find($ha);
    $floor1 = SiteService::floor($f1);
    $mpJetzt = Database::first('SELECT m.*, a.objekt_id AS a_objekt, a.richtung, a.nr FROM messpunkte m LEFT JOIN anschluesse a ON a.id = m.anschluss_id WHERE m.id = ?', [$mpId]);
    gleich([(float) $haJetzt['plan_x'], (int) $haJetzt['steuerung_gesperrt'], $floor1['name'], $floor1['scale_note']], [66.0, 1, 'Erdgeschoss', '1:300'],
        'direkt: verschoben und Maßstab-Text; Name und Schaltsperre hier bleiben');
    pruefe((float) PlanMassstabService::lesen($floor1)['meter'] === 99.0 && PlanGeoService::alsDaten(PlanGeoService::lesen($floor1)) == $geoVorher,
        'direkt: ohne referenz und Georeferenz bleiben Maßstab und Georeferenz');
    pruefe($mpJetzt !== null && (int) $mpJetzt['a_objekt'] === $ha && $mpJetzt['richtung'] . ':' . $mpJetzt['nr'] === 'abgang:1' && (string) $mpJetzt['gueltig_ab'] === $mpAb,
        'direkt: der Messpunkt bleibt am Abgang 1');
    $audit = Database::first("SELECT * FROM audit_log WHERE action = 'api.schreiben' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $sites['halle']]);
    $details = json_decode((string) ($audit['details'] ?? ''), true);
    pruefe(($audit['username'] ?? '') === 'api:' . $tSchreib['name'] && ($details['location'] ?? '') === $halleKey && ($details['token'] ?? 0) === $tSchreib['id']
        && ($details['teile'] ?? []) === ['grundriss', 'feststrom'], 'Audit api.schreiben mit dem Token als Handelndem');
    pruefe(array_filter($ablageJetzt(), static fn (string $n): bool => str_starts_with($n, 'direkt-')) === [], 'direkt: keine Dateien liegen geblieben');

    $vorher = $bildA();
    $zahl = $zeilen();
    $direkt['strom']['anlagen'][$iHaS]['x'] = 67.0;
    $r = $senden($tSchreib, $direkt);
    gleich([$r['status'], $r['json']['message'] ?? '', $r['json']['stand'] ?? ''],
        [409, 'Hier hat sich seit dem Abruf etwas geändert – erst übernehmen oder ausdrücklich überschreiben.', $neuerStand], 'veralteter Stand: 409 mit dem aktuellen');
    pruefe($bildA() === $vorher && $zeilen() === $zahl, '409: nichts gespeichert, nichts geändert');
    $r = $senden($tSchreib, $direkt + ['erzwingen' => true]);
    pruefe($r['status'] === 201 && ($r['json']['uebernommen'] ?? null) === true && (float) AnlagenService::find($ha)['plan_x'] === 67.0, 'mit „erzwingen“: übernommen');
    $ohneBasis = $direkt;
    unset($ohneBasis['basis']);
    $ohneBasis['strom']['anlagen'][$iHaS]['x'] = 68.0;
    $r = $senden($tSchreib, $ohneBasis);
    $oid = (int) ($r['json']['id'] ?? 0);
    pruefe($r['status'] === 201 && ($r['json']['uebernommen'] ?? null) === false && VorschlagService::find($oid)['status'] === 'offen'
        && (float) AnlagenService::find($ha)['plan_x'] === 67.0, 'ohne Stand: ein offener Vorschlag, nichts direkt geändert');
    $lagerS = $detail($tSchreib, $lagerKey);
    $r = $senden($tSchreib, ['location' => $lagerS['location'] ?? [], 'basis' => (string) ($lagerS['stand'] ?? '')], $lagerKey);
    gleich($r['status'], 403, 'schreiben auf einen Standort nur zum Sehen: 403');
    $vorher = $bildA();
    $zahl = $zeilen();
    $d = $detail($tSchreib);
    $unbekannt = ['location' => $d['location'], 'strom' => $d['strom'], 'basis' => (string) $d['stand']];
    $unbekannt['strom']['anlagen'][$iHaS]['anschluesse'][0]['stecker'] = 'pm-gibts-nicht-' . $marke;
    $r = $senden($tSchreib, $unbekannt);
    pruefe($r['status'] === 422 && ($r['json']['stecker'] ?? []) === ['pm-gibts-nicht-' . $marke] && $bildA() === $vorher && $zeilen() === $zahl,
        'unbekannter Steckertyp beim Schreiben: 422 mit dem Namen, nichts gespeichert – ' . ($r['json']['message'] ?? ''));
    pruefe($zeilen() > $vorDirekt, 'direkt Geschriebenes steht in der Liste der Vorschläge');

    // ================================================= Aufräumen (bin/power wartung)
    alsBenutzer($benutzer['admin']);
    $altId = Database::insert('api_vorschlaege', ['token_id' => $tVor['id'], 'token_name' => $tVor['name'], 'site_id' => $sites['halle'], 'site_key' => $halleKey,
        'daten' => '{}', 'status' => 'abgelehnt', 'created_at' => gmdate('c', time() - 410 * 86400), 'entschieden_at' => gmdate('c', time() - 401 * 86400)]);
    $altOffen = Database::insert('api_vorschlaege', ['token_id' => $tVor['id'], 'token_name' => $tVor['name'], 'site_id' => null, 'site_key' => 'alt-offen',
        'daten' => '{}', 'status' => 'offen', 'created_at' => gmdate('c', time() - 410 * 86400)]);
    $verwaist = $ablage . '/' . (900000000 + random_int(1, 99999999));
    mkdir($verwaist, 0700);
    touch($verwaist . '/x.svg');
    mkdir($ablage . '/' . $altOffen, 0700);
    mkdir($ablage . '/' . $marke . '-alt.neu', 0700);
    touch($ablage . '/' . $marke . '-alt.neu', time() - 2 * 86400);
    mkdir($ablage . '/' . $marke . '-frisch.neu', 0700);
    $meldung = VorschlagService::wartung();
    pruefe(Database::first('SELECT id FROM api_vorschlaege WHERE id = ?', [$altId]) === null && Database::first('SELECT id FROM api_vorschlaege WHERE id = ?', [$altOffen]) !== null,
        'wartung: entschiedene nach 400 Tagen gelöscht, offene bleiben');
    pruefe(!is_dir($verwaist) && is_dir($ablage . '/' . $altOffen) && !is_dir($ablage . '/' . $marke . '-alt.neu') && is_dir($ablage . '/' . $marke . '-frisch.neu'),
        'wartung: verwaiste Ordner und alte *.neu weg, offene und frische bleiben – ' . implode(' ', $meldung));
    ordnerWeg($ablage . '/' . $marke . '-frisch.neu', $ablage);
    ordnerWeg($ablage . '/' . $altOffen, $ablage);
    foreach (['pm-partner-' . bin2hex(random_bytes(6)) => true, 'pm-partner-' . bin2hex(random_bytes(6)) . '-frisch' => false, 'pm-partner-anders' => true] as $name => $alt) {
        mkdir($tmp . '/' . $name, 0700);
        touch($tmp . '/' . $name . '/plan.svg');
        if ($alt) {
            touch($tmp . '/' . $name, time() - 2 * 86400);
        }
    }
    $frisch = 'pm-partner-' . bin2hex(random_bytes(6));
    mkdir($tmp . '/' . $frisch, 0700);
    $meldung = PartnerAbgleich::wartung($tmp);
    $uebrig = array_values(array_filter(scandir($tmp) ?: [], static fn (string $n): bool => str_starts_with($n, 'pm-partner-')));
    sort($uebrig);
    pruefe(count($uebrig) === 3 && in_array($frisch, $uebrig, true) && in_array('pm-partner-anders', $uebrig, true) && str_contains($meldung[0], '1 '),
        'wartung: nur alte Temp-Ordner mit dem Namen von dort – ' . implode(' ', $meldung));
    foreach ($uebrig as $n) {
        @unlink($tmp . '/' . $n . '/plan.svg');
        @rmdir($tmp . '/' . $n);
    }
    // bin/power wartung nennt beides (mit eigenem Temp-Ordner)
    $p = proc_open([PHP_BINARY, $wurzel . '/bin/power', 'wartung'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, $wurzel, ['TMPDIR' => $tmp] + getenv());
    $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
    proc_close($p);
    pruefe(str_contains((string) $aus, 'Vorschläge:') && str_contains((string) $aus, 'Andere Instanzen:'), 'bin/power wartung räumt Vorschläge und Temp-Ordner auf');
} finally {
    alsBenutzer(null);
    $ids = $tokenIds === [] ? [] : array_map('intval', array_column(Database::select('SELECT id FROM api_vorschlaege WHERE token_id IN ('
        . implode(',', array_map('intval', $tokenIds)) . ')'), 'id'));
    foreach ($ids as $id) {
        ordnerWeg($ablage . '/' . $id, $ablage);
        ordnerWeg($ablage . '/' . $id . '.neu', $ablage);
        Database::execute('DELETE FROM api_vorschlaege WHERE id = ?', [$id]);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_vorschlag' AND target_id = ?", [(string) $id]);
    }
    foreach ($tokenIds as $id) {
        Database::execute('DELETE FROM api_token_standorte WHERE token_id = ?', [$id]);
        Database::execute('DELETE FROM api_tokens WHERE id = ?', [$id]);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_token' AND target_id = ?", [(string) $id]);
    }
    $geloescht = ['site' => [], 'floor' => []];
    foreach ($sites as $siteId) {
        $geloescht['site'][] = (string) $siteId;
        foreach (Database::select('SELECT id FROM floors WHERE site_id = ?', [$siteId]) as $f) {
            $geloescht['floor'][] = (string) $f['id'];
        }
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$siteId]) as $o) {
            AnlagenService::loeschen((int) $o['id']);
        }
        SiteService::deleteSite($siteId);
    }
    Database::execute('DELETE FROM messpunkte WHERE geraet LIKE ?', [$marke . '%']);
    Database::execute('DELETE FROM steckertypen WHERE schluessel = ?', [$stecker]);
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
    foreach ($geloescht as $art => $liste) {
        foreach ($liste as $id) {
            Database::execute("DELETE FROM audit_log WHERE target_type = ? AND target_id = ?", [$art, $id]);
        }
    }
    foreach (array_diff(dateienUnter($planDir), $planeVorher) as $neu) {
        is_dir($neu) ? @rmdir($neu) : @unlink($neu);
    }
    foreach ($dosen as $d) {
        @unlink($d);
    }
    $log = is_file($logDatei) ? (string) file_get_contents($logDatei, false, null, $logVorher) : '';
    pruefe(array_filter($geheim, static fn (string $g): bool => $g !== '' && str_contains($log, $g)) === [], 'kein Token im Log');
    pruefe(array_values(array_diff($ablageJetzt(), $ablageVorher)) === [], 'storage/vorschlaege wie vorher');
    foreach (dateienUnter($tmp) as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($tmp);
}

ende('schnittstelle-vorschlaege');
