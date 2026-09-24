<?php
declare(strict_types=1);

/**
 * ComAp WebSupervisor und Victron VRM ohne Netz: Http::$attrappe spielt
 * die Gegenstelle. Geprüft werden Anmeldung mit Token-Zwischenspeicher,
 * neues Token nach 401, Übersetzen der Werte (kW → W), Schaltbefehl mit
 * Schalt-Freigabe und die Fehlermeldungen.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Messung\Abfrage;
use App\Messung\ComapCloud;
use App\Messung\Datenquellen;
use App\Messung\Http;
use App\Messung\Steuerung;
use App\Messung\VictronVrm;
use App\Support\Config;
use App\Support\Database;

Config::set('app.demo', false);
$kennung = 'pm-cloud-' . bin2hex(random_bytes(3));
$quellen = [];
$anfragen = [];
$abgelaufen = false;
$anmeldungen = 0;

Http::$attrappe = static function (string $methode, string $url, array $optionen) use (&$anfragen, &$abgelaufen, &$anmeldungen): array {
    $anfragen[] = [$methode, $url, $optionen];
    $pfad = (string) parse_url($url, PHP_URL_PATH);
    $json = static fn (int $status, array $daten): array => ['ok' => true, 'status' => $status, 'rumpf' => (string) json_encode($daten), 'fehler' => ''];

    if (str_starts_with($url, ComapCloud::BASIS)) {
        if ($pfad === '/identity/application/authenticate') {
            $rumpf = json_decode((string) ($optionen['rumpf'] ?? ''), true);
            if (($optionen['kopf']['Comap-Key'] ?? '') !== 'schluessel' || ($rumpf['secret'] ?? '') !== 'geheim') {
                return $json(401, ['message' => 'unauthorized']);
            }
            $anmeldungen++;
            return $json(200, ['access_token' => 'token-' . $anmeldungen, 'expires_in' => 3600]);
        }
        if ($abgelaufen) {
            $abgelaufen = false;
            return $json(401, ['message' => 'token expired']);
        }
        if (($optionen['kopf']['Authorization'] ?? '') === '') {
            return $json(401, []);
        }
        if (str_ends_with($pfad, '/units')) {
            return $json(200, ['units' => [['name' => 'Aggregat 1', 'unitGuid' => 'abc-123']]]);
        }
        if (str_ends_with($pfad, '/values')) {
            return $json(200, ['values' => [
                ['valueGuid' => 'ee83e7e9-7453-4e64-a5cb-c8c093fb2a2f', 'value' => '85,5', 'unit' => 'kW'],
                ['valueGuid' => '4a2a2694-3049-4a8e-ba5f-cc6feeda9d59', 'value' => 231, 'unit' => 'V'],
                ['valueGuid' => '1a3767fd-72e9-429b-a088-95318804ce8e', 'value' => 50.1, 'unit' => 'Hz'],
                ['valueGuid' => '0dc2739f-0fc9-4a17-bc91-26e5dda19ed8', 'value' => 73, 'unit' => '%'],
                ['valueGuid' => 'BB2D1ADE-740E-488d-853B-6BA970D52E27', 'value' => 'Running', 'unit' => ''],
                ['valueGuid' => 'unbekannt', 'value' => 1, 'unit' => ''],
            ]]);
        }
        if (str_ends_with($pfad, '/command')) {
            return $json(200, ['ok' => true]);
        }
        return $json(404, []);
    }
    if (str_starts_with($url, VictronVrm::BASIS)) {
        if (($optionen['kopf']['X-Authorization'] ?? '') !== 'Token vrm-token') {
            return $json(401, ['success' => false, 'errors' => 'Invalid token']);
        }
        return $json(200, ['success' => true, 'records' => [
            ['dbusServiceType' => 'system', 'dbusPath' => '/Ac/Consumption/L1/Power', 'rawValue' => 1500],
            ['dbusServiceType' => 'system', 'dbusPath' => '/Ac/Grid/L2/Power', 'rawValue' => -200],
            ['dbusServiceType' => 'system', 'dbusPath' => '/Dc/Battery/Soc', 'rawValue' => 81.5],
            ['dbusServiceType' => 'vebus', 'dbusPath' => '/Ac/Out/L1/V', 'rawValue' => 230.4],
            ['dbusServiceType' => 'vebus', 'dbusPath' => '/State', 'rawValue' => 9],
            ['dbusServiceType' => 'genset', 'dbusPath' => '/Ac/L3/Current', 'rawValue' => 12.5],
            ['dbusServiceType' => 'system', 'dbusPath' => '/Dc/Battery/Soc', 'rawValue' => 'n/a'],
        ]]);
    }
    return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'unerwartete Adresse ' . $url];
};

try {
    // ------------------------------------------------ ComAp
    $comap = (int) Datenquellen::speichern(null, ['art' => 'comap_cloud', 'name' => 'Prüf-IG200', 'geraet' => $kennung . '-ig200', 'extern_id' => 'abc-123',
        'intervall_s' => 30, 'aktiv' => 1, 'zugang' => ['comap_key' => 'schluessel', 'client_id' => 'client', 'secret' => 'geheim', 'login_id' => 'pruefer']])['id'];
    $quellen[] = $comap;
    @unlink(APP_ROOT . '/storage/cache/comap-' . $comap . '.json');
    $q = Datenquellen::find($comap);

    gleich(ComapCloud::einheiten($q), [['name' => 'Aggregat 1', 'unitGuid' => 'abc-123']], 'Einheiten der Login-ID');
    $erg = Abfrage::ausfuehren($q);
    pruefe($erg['ok'], 'ComAp-Werte gelesen ' . (string) $erg['fehler']);
    gleich($erg['kanaele']['generator']['phasen']['sum']['p'] ?? null, 85500.0, 'Leistung „85,5 kW“ → 85500 W');
    gleich($erg['kanaele']['generator']['phasen']['L1']['u'] ?? null, 231.0, 'Spannung L1');
    gleich($erg['kanaele']['generator']['phasen']['sum']['f'] ?? null, 50.1, 'Frequenz');
    gleich($erg['kanaele']['motor']['zustand']['tank_prozent'] ?? null, 73.0, 'Tankinhalt');
    gleich($erg['kanaele']['motor']['zustand']['motorzustand'] ?? null, 'Running', 'Text bleibt Text');
    $werteAnfrage = array_values(array_filter($anfragen, static fn ($a) => str_contains($a[1], '/values')))[0] ?? null;
    pruefe($werteAnfrage !== null && str_contains($werteAnfrage[1], '/v1.1/pruefer/units/abc-123/values?valueGuids='), 'Adresse mit Login-ID und unitGuid');
    gleich($anmeldungen, 1, 'eine Anmeldung für beide Abfragen (Token zwischengespeichert)');

    $abgelaufen = true;
    $erg = Abfrage::ausfuehren($q);
    pruefe($erg['ok'], 'nach 401 wird das Token erneuert und die Abfrage wiederholt');
    gleich($anmeldungen, 2, 'genau eine neue Anmeldung');

    // Schalten: ohne Freigabe abgelehnt, als Admin angenommen
    alsBenutzer(null);
    pruefe(!Steuerung::ausfuehren($comap, 'start', 'motor')['ok'], 'ohne Schalt-Freigabe kein Motorstart');
    $admin = (int) Database::scalar("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key = 'admin' ORDER BY ur.user_id LIMIT 1");
    alsBenutzer($admin);
    $erg = Steuerung::ausfuehren($comap, 'modus_auto', 'motor');
    pruefe($erg['ok'] && str_contains($erg['message'], 'angenommen'), 'Betriebsart AUTO: ' . $erg['message']);
    $befehl = array_values(array_filter($anfragen, static fn ($a) => str_ends_with($a[1], '/command')))[0] ?? null;
    gleich(json_decode((string) ($befehl[2]['rumpf'] ?? ''), true), ['command' => 'changeMode', 'mode' => 'AUTO'], 'Rumpf des Befehls');
    pruefe(!Steuerung::ausfuehren($comap, 'selbstzerstoerung', 'motor')['ok'], 'unbekannter Befehl abgelehnt');

    // Falsche Zugangsdaten → verständliche Meldung
    Datenquellen::speichern($comap, array_merge($q, ['zugang' => ['secret' => 'falsch']]));
    @unlink(APP_ROOT . '/storage/cache/comap-' . $comap . '.json');
    $erg = Abfrage::ausfuehren(Datenquellen::find($comap));
    pruefe(!$erg['ok'] && str_contains((string) $erg['fehler'], 'Anmeldung fehlgeschlagen'), 'falsches Secret: ' . (string) $erg['fehler']);

    // ------------------------------------------------ VRM
    $vrm = (int) Datenquellen::speichern(null, ['art' => 'victron_vrm', 'name' => 'Prüf-VRM', 'geraet' => $kennung . '-vrm', 'extern_id' => '123456',
        'intervall_s' => 60, 'aktiv' => 1, 'zugang' => ['token' => 'vrm-token']])['id'];
    $quellen[] = $vrm;
    $erg = Abfrage::ausfuehren(Datenquellen::find($vrm));
    pruefe($erg['ok'], 'VRM gelesen ' . (string) $erg['fehler']);
    gleich($erg['kanaele']['verbrauch']['phasen']['L1']['p'] ?? null, 1500.0, 'Verbrauch L1');
    gleich($erg['kanaele']['netz']['phasen']['L2']['p'] ?? null, -200.0, 'Netz L2 (Einspeisung negativ)');
    gleich($erg['kanaele']['batterie']['zustand']['soc'] ?? null, 81.5, 'SOC – ein Text-Duplikat überschreibt nicht');
    gleich($erg['kanaele']['ac_aus']['phasen']['L1']['u'] ?? null, 230.4, 'VE.Bus-Ausgang U L1');
    gleich($erg['kanaele']['generator']['phasen']['L3']['i'] ?? null, 12.5, 'Generator L3 Strom');
    Datenquellen::speichern($vrm, array_merge(Datenquellen::find($vrm), ['zugang' => ['token' => 'falsch']]));
    $erg = Abfrage::ausfuehren(Datenquellen::find($vrm));
    pruefe(!$erg['ok'] && str_contains((string) $erg['fehler'], '401'), 'falsches VRM-Token: ' . (string) $erg['fehler']);
} finally {
    Http::$attrappe = null;
    alsBenutzer(null);
    foreach ($quellen as $id) {
        @unlink(APP_ROOT . '/storage/cache/comap-' . $id . '.json');
        Datenquellen::loeschen($id);
    }
    Database::execute('DELETE FROM schaltvorgaenge WHERE geraet LIKE ?', [$kennung . '%']);
}

ende('cloud');
