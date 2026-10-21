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
        $r = aufruf($basis, $admin['dose'], 'POST', '/anlagen/' . $tankId . '/einsetzen', ['inventar_id' => $ts, 'einbau' => ['offset_mm' => '25'], 'peilung_prozent' => '55',
            '_token' => $admin['token']]);
        $mpTs = Database::first("SELECT * FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL", [$tankId]);
        $fTs = $mpTs !== null ? json_decode((string) Database::scalar('SELECT rechnung FROM tank_kennlinien WHERE messpunkt_id = ? ORDER BY id DESC LIMIT 1', [(int) $mpTs['id']]), true) : null;
        pruefe($r['status'] === 302 && ($mpTs['geraet'] ?? '') === $kennung . '-uni' && ($fTs['herkunft']['einbau']['offset_mm'] ?? null) == 25,
            'Tanksensor über das Formular eingebaut, Einbaumaß in der Kennlinie');
        $erstePeilung = Database::first("SELECT * FROM tank_ereignisse WHERE objekt_id = ? AND art = 'peilung' ORDER BY id DESC LIMIT 1", [$tankId]);
        gleich([(float) ($erstePeilung['prozent'] ?? 0), (int) ($erstePeilung['messpunkt_id'] ?? 0), array_key_exists('roh', $erstePeilung ?? []) ? $erstePeilung['roh'] : 'x'],
            [55.0, (int) ($mpTs['id'] ?? -1), null],
            'Einsetzen mit Anzeige am Tank: erste Peilung im Protokoll – ohne Rohwert seit dem Einbau nicht kalibriert');
        $seite = aufruf($basis, $admin['dose'], 'GET', '/anlagen/' . $tankId);
        pruefe($seite['status'] === 200 && phpMeldung($seite['text']) === null && str_contains($seite['text'], 'seit Einbau nicht gepeilt') && str_contains($seite['text'], 'Ausbauen'),
            'Tankseite: eingebauter Sensor, ungeprüft, mit „Ausbauen“');
        pruefe(str_contains($seite['text'], 'Peilung 55 % eingetragen') && str_contains($seite['text'], 'sobald er meldet'),
            'Meldung nach dem Einsetzen kommt an, obwohl die Sitzung beim Lesen des Rohwerts frei war');
        pruefe(str_contains($seite['text'], 'action="/anlagen/' . $tankId . '/tank/peilung"') && str_contains($seite['text'], 'action="/anlagen/' . $tankId . '/tank/betankung"')
            && str_contains($seite['text'], 'name="sensor[mm_je_roh]"') && str_contains($seite['text'], 'name="rueckwirkend"') && str_contains($seite['text'], 'id="protokoll"')
            && str_contains($seite['text'], 'action="/anlagen/' . $tankId . '/tank/ereignis/loeschen"') && str_contains($seite['text'], '/assets/js/tank.js'),
            'Tankseite: Vor Ort (Peilung, Betankung), Protokoll mit Löschen, Erweitert mit „rückwirkend“');
        pruefe(str_contains($seite['text'], 'data-quelle="/anlagen/' . $tankId . '/tank/verlauf"') && str_contains($seite['text'], 'Fassungen der Kennlinie')
            && str_contains($seite['text'], 'data-tank="roh"') && str_contains($seite['text'], 'data-live-url="/anlagen/' . $tankId . '/live"')
            && str_contains($seite['text'], 'id="statistik-daten"'), 'Tankseite: Jetzt (live), Verlauf, Fassungen');
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
        $karteTank = json_decode(aufruf($basis, $admin['dose'], 'GET', '/karte/objekt/' . $tankId)['text'], true);
        pruefe(($karteTank['tank']['sensor']['kennung'] ?? '') === $kennung . '-TS' && ($karteTank['tank']['darf']['einsetzen'] ?? false) === true
            && is_int($karteTank['tank']['sensor']['einsatz_id'] ?? null) && array_key_exists('tank', $karteTank['live'] ?? []), 'Karte: Tank-Panel mit Sensor, Ausbau und Livewerten');
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
            && !str_contains($r['text'], '/tank/betankung') && !str_contains($r['text'], 'Erweitert') && !str_contains($r['text'], '/tank/ereignis/loeschen'),
            'Kunde: Tankseite mit Protokoll, ohne Peilung, Betankung, Erweitert und Löschen');
        pruefe(str_contains($r['text'], 'id="versorgung"') && str_contains($r['text'], 'Generator (nicht freigegeben)') && !str_contains($r['text'], htmlspecialchars($kennung . '-gen')),
            'Kunde: Versorgung ohne den Namen des Generators, den er nicht sieht');
        $n = $ereignisse();
        $r = aufruf($basis, $kundeTank['dose'], 'POST', '/anlagen/' . $tankId . '/tank/peilung', ['art' => 'prozent', 'wert' => '60', '_token' => $kundeTank['token']], $json);
        pruefe($r['status'] === 403 && $ereignisse() === $n, 'Kunde: Peilung → 403');
        $r = aufruf($basis, $kundeTank['dose'], 'POST', '/anlagen/' . $tankId . '/tank/ereignis/loeschen', ['ereignis_id' => (int) ($erstePeilung['id'] ?? 0), '_token' => $kundeTank['token']]);
        pruefe($r['status'] === 403 && $ereignisse() === $n, 'Kunde: Löschen → 403');
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$benutzer['zwei']]);
        unset($kundeFreigabe);

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

    // ---------------------------------------------------- Kunde ohne Freigabe
    $kunde = anmelden($basis, $kennung . '-kunde', $kennwort);
    $dosen[] = $kunde['dose'];
    foreach (['/datenquellen', '/system/benutzer', '/system/einstellungen', '/audit', '/stecker', '/standorte', '/anlagen/neu', '/steuerung/protokoll', '/bereiche/neu',
        '/inventar', '/inventar/neu', '/verteiler-abgleich', '/location', '/anlagen/katalog/import'] as $pfad) {
        gleich(aufruf($basis, $kunde['dose'], 'GET', $pfad)['status'], 403, 'Kunde ' . $pfad);
    }
    if (($tankId ?? 0) > 0) {
        $r = aufruf($basis, $kunde['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf');
        pruefe($r['status'] === 404 && (json_decode($r['text'], true)['ok'] ?? true) === false, 'Kunde ohne Freigabe: fremder Tank, Verlauf → 404');
        gleich(aufruf($basis, $kunde['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv')['status'], 404, 'Kunde ohne Freigabe: fremder Tank, CSV → 404');
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
        if (($tankId ?? 0) > 0) {
            gleich(aufruf($basis, $planer['dose'], 'GET', '/anlagen/' . $tankId . '/tank/verlauf')['status'], 403, 'Planer ohne „statistik.view“: Tankverlauf → 403');
            gleich(aufruf($basis, $planer['dose'], 'GET', '/anlagen/' . $tankId . '/tank/csv')['status'], 403, 'Planer ohne „statistik.export“: Tank-CSV → 403');
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
} finally {
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
