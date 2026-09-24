<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Database;

/**
 * ComAp WebSupervisor – Cloud-API v1.1.
 *
 * Anmeldung über ComAp Cloud Identity: mit ComAp-Key (Abonnementschlüssel
 * aus dem Developer-Portal), clientId und secret gibt es ein Bearer-Token,
 * das eine Weile gilt. Danach je Steuerung (unitGuid) die aktuellen Werte:
 *
 *   POST https://api.websupervisor.net/identity/application/authenticate
 *        Header Comap-Key, Rumpf {"clientId":…, "secret":…}
 *   GET  https://api.websupervisor.net/v1.1/{loginId}/units
 *   GET  https://api.websupervisor.net/v1.1/{loginId}/units/{unitGuid}/values?valueGuids=…
 *   POST https://api.websupervisor.net/v1.1/{loginId}/units/{unitGuid}/command
 *        Rumpf {"command": "start" | "stop" | "faultReset" | "changeMode", "mode": …}
 *
 * Die Cloud aktualisiert ihre Werte im Takt der AirGate-Verbindung – das
 * sind keine Livewerte im Sekundentakt. Für „möglichst live“ ist der Weg
 * über Modbus TCP durch den WireGuard-Tunnel der bessere; die Cloud ist der
 * Weg für Aggregate, an die kein Tunnel reicht.
 */
final class ComapCloud
{
    public const BASIS = 'https://api.websupervisor.net';

    /**
     * Bekannte Werte-GUIDs (aus der öffentlichen ComAp-API-Bibliothek) und
     * wohin sie gehören: [kanal, phase ('' = zustand), feld].
     */
    public const WERTE = [
        'EE83E7E9-7453-4e64-A5CB-C8C093FB2A2F' => ['generator', 'sum', 'p'],          // Actual power
        '72D0295A-3E65-11DF-892C-D6A856D89593' => ['motor', '', 'nennleistung_w'],     // Nominal power
        '4a2a2694-3049-4a8e-ba5f-cc6feeda9d59' => ['generator', 'L1', 'u'],
        'e0f2f0ff-0627-4d78-bc78-8973ae8b7cea' => ['generator', 'L2', 'u'],
        '201b5a65-e4b8-49e6-8f47-9144607eaf58' => ['generator', 'L3', 'u'],
        '1a3767fd-72e9-429b-a088-95318804ce8e' => ['generator', 'sum', 'f'],
        'd61be5b5-56fa-4cd8-9fb7-00d2c01627ad' => ['netz', 'sum', 'p'],
        '441ef9cc-fdec-4064-8939-825be28a97b0' => ['netz', 'sum', 'q'],
        '6629f84f-4b79-43c2-ab33-1f27f1f8c42b' => ['motor', '', 'drehzahl'],
        'e707bcd1-2735-4ab7-8a8c-d75fd589052c' => ['motor', '', 'u_batterie'],
        '0dc2739f-0fc9-4a17-bc91-26e5dda19ed8' => ['motor', '', 'tank_prozent'],
        '74200D25-93F2-4328-B1D6-1A08923AA499' => ['motor', '', 'betriebsstunden'],
        '80042f38-a6d4-4767-a730-b573e7a0dae0' => ['motor', '', 'starts'],
        'BB2D1ADE-740E-488d-853B-6BA970D52E27' => ['motor', '', 'motorzustand'],
        'B7E5B9B1-9F5E-45c1-82F4-3A336F1F97FA' => ['motor', '', 'schalterzustand'],
        '6a12aed6-3be0-44c4-9110-9ea33cfe3ccc' => ['motor', '', 'modus'],
        '7EDE9575-FEB5-4540-9153-A3B9CF60DE9F' => ['motor', '', 'alarme'],
        'F0219C1C-1860-4b4d-8E6E-3CEC96279D6F' => ['motor', '', 'verbindung'],
        '4C214D52-3D75-11DF-8AA2-529056D89593' => ['netz', 'sum', 'e'],               // Import kWh
    ];

    /** Befehle der Cloud-API, wie sie in der Oberfläche erscheinen. */
    public const BEFEHLE = [
        'start'      => ['name' => 'Motor starten', 'art' => 'ein', 'rumpf' => ['command' => 'start']],
        'stop'       => ['name' => 'Motor stoppen', 'art' => 'aus', 'rumpf' => ['command' => 'stop']],
        'faultReset' => ['name' => 'Fehler quittieren', 'art' => 'quittieren', 'rumpf' => ['command' => 'faultReset']],
        'modus_auto' => ['name' => 'Betriebsart AUTO', 'art' => 'modus', 'rumpf' => ['command' => 'changeMode', 'mode' => 'AUTO']],
        'modus_man'  => ['name' => 'Betriebsart MAN', 'art' => 'modus', 'rumpf' => ['command' => 'changeMode', 'mode' => 'MAN']],
        'modus_off'  => ['name' => 'Betriebsart OFF', 'art' => 'modus', 'rumpf' => ['command' => 'changeMode', 'mode' => 'OFF']],
    ];

    /** Bearer-Token holen – zwischengespeichert, solange es gilt. */
    public static function token(array $quelle, bool $neu = false): string
    {
        $zugang = Datenquellen::zugang((int) $quelle['id']);
        foreach (['comap_key', 'client_id', 'secret'] as $pflicht) {
            if (trim((string) ($zugang[$pflicht] ?? '')) === '') {
                throw new \RuntimeException('Für die ComAp-Cloud fehlt „' . $pflicht . '“ in den Zugangsdaten.');
            }
        }
        $cache = dirname(__DIR__, 2) . '/storage/cache/comap-' . (int) $quelle['id'] . '.json';
        if (!$neu && is_file($cache)) {
            $t = json_decode((string) file_get_contents($cache), true);
            if (is_array($t) && ($t['bis'] ?? 0) > time() + 60 && ($t['kennung'] ?? '') === hash('sha256', (string) $zugang['client_id'])) {
                return (string) $t['token'];
            }
        }
        $antwort = Http::anfrage('POST', self::BASIS . '/identity/application/authenticate', [
            'kopf'  => ['Content-Type' => 'application/json', 'Comap-Key' => (string) $zugang['comap_key']],
            'rumpf' => (string) json_encode(['clientId' => $zugang['client_id'], 'secret' => $zugang['secret']]),
            'zeit'  => 20,
        ]);
        $daten = json_decode($antwort['rumpf'], true);
        if (!$antwort['ok'] || $antwort['status'] !== 200 || !is_array($daten) || empty($daten['access_token'])) {
            throw new \RuntimeException('ComAp-Anmeldung fehlgeschlagen (' . ($antwort['status'] ?: $antwort['fehler']) . ').');
        }
        if (!is_dir(dirname($cache))) {
            mkdir(dirname($cache), 0770, true);
        }
        file_put_contents($cache, json_encode([
            'token' => $daten['access_token'],
            'bis' => time() + (int) ($daten['expires_in'] ?? 3000),
            'kennung' => hash('sha256', (string) $zugang['client_id']),
        ]), LOCK_EX);
        @chmod($cache, 0600);
        return (string) $daten['access_token'];
    }

    /** @return array{ok:bool,status:int,daten:mixed} */
    private static function aufruf(array $quelle, string $methode, string $pfad, ?array $rumpf = null): array
    {
        $zugang = Datenquellen::zugang((int) $quelle['id']);
        $login = trim((string) ($zugang['login_id'] ?? ''));
        if ($login === '') {
            throw new \RuntimeException('Für die ComAp-Cloud fehlt die Login-ID (WebSupervisor-Benutzername).');
        }
        $url = self::BASIS . '/v1.1/' . rawurlencode($login) . $pfad;
        foreach ([false, true] as $neu) {
            $antwort = Http::anfrage($methode, $url, [
                'kopf' => [
                    'Content-Type'  => 'application/json',
                    'Comap-Key'     => (string) ($zugang['comap_key'] ?? ''),
                    'Authorization' => 'Bearer ' . self::token($quelle, $neu),
                ],
                'rumpf' => $rumpf !== null ? (string) json_encode($rumpf) : null,
                'zeit'  => 20,
            ]);
            if ($antwort['status'] === 401 && !$neu) {
                continue; // Token abgelaufen: einmal neu holen
            }
            if (!$antwort['ok']) {
                throw new \RuntimeException('ComAp-Cloud nicht erreichbar: ' . $antwort['fehler']);
            }
            return ['ok' => $antwort['status'] < 300, 'status' => $antwort['status'], 'daten' => json_decode($antwort['rumpf'], true)];
        }
        throw new \RuntimeException('ComAp-Cloud lehnt die Anmeldung ab.');
    }

    /** @return list<array{name:string,unitGuid:string}> */
    public static function einheiten(array $quelle): array
    {
        $a = self::aufruf($quelle, 'GET', '/units');
        if (!$a['ok']) {
            throw new \RuntimeException('ComAp-Cloud: Einheiten nicht abrufbar (' . $a['status'] . ').');
        }
        return array_map(static fn ($u) => ['name' => (string) ($u['name'] ?? ''), 'unitGuid' => (string) ($u['unitGuid'] ?? '')], (array) ($a['daten']['units'] ?? []));
    }

    public static function werte(array $quelle): array
    {
        $guid = rawurlencode((string) $quelle['extern_id']);
        $a = self::aufruf($quelle, 'GET', '/units/' . $guid . '/values?valueGuids=' . rawurlencode(implode(',', array_keys(self::WERTE))));
        if (!$a['ok']) {
            throw new \RuntimeException('ComAp-Cloud: Werte nicht abrufbar (' . $a['status'] . ').');
        }
        return self::uebersetzen((array) ($a['daten']['values'] ?? []));
    }

    /** Die Antwort der API in Kanäle übersetzen – eigene Funktion, damit sie prüfbar ist. */
    public static function uebersetzen(array $werte): array
    {
        $zuordnung = array_change_key_case(self::WERTE, CASE_LOWER);
        $kanaele = [];
        foreach ($werte as $w) {
            $ziel = $zuordnung[strtolower((string) ($w['valueGuid'] ?? ''))] ?? null;
            if ($ziel === null) {
                continue;
            }
            [$kanal, $phase, $feld] = $ziel;
            $roh = $w['value'] ?? null;
            $kanaele[$kanal] ??= ['phasen' => [], 'zustand' => []];
            $zahl = is_numeric(str_replace(',', '.', (string) $roh)) ? (float) str_replace(',', '.', (string) $roh) : null;
            if ($zahl !== null) {
                $zahl *= match (strtolower(trim((string) ($w['unit'] ?? '')))) {
                    'kw', 'kva', 'kvar', 'kwh' => 1000.0,
                    'mw', 'mwh' => 1000000.0,
                    default => 1.0,
                };
                if ($phase !== '') {
                    $kanaele[$kanal]['phasen'][$phase][$feld] = $zahl;
                } else {
                    $kanaele[$kanal]['zustand'][$feld] = $zahl;
                }
            } elseif ($phase === '' && is_scalar($roh) && (string) $roh !== '') {
                $kanaele[$kanal]['zustand'][$feld] = mb_substr((string) $roh, 0, 250);
            }
        }
        return $kanaele;
    }

    public static function befehl(array $quelle, string $schluessel): array
    {
        $befehl = self::BEFEHLE[$schluessel] ?? null;
        if ($befehl === null) {
            return ['ok' => false, 'message' => 'Unbekannter Befehl.'];
        }
        $a = self::aufruf($quelle, 'POST', '/units/' . rawurlencode((string) $quelle['extern_id']) . '/command', $befehl['rumpf']);
        return $a['ok']
            ? ['ok' => true, 'message' => 'ComAp hat „' . $befehl['name'] . '“ angenommen.']
            : ['ok' => false, 'message' => 'ComAp lehnt „' . $befehl['name'] . '“ ab (' . $a['status'] . ').'];
    }
}
