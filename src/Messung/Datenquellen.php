<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;

/**
 * Datenquellen: was der Sammler abfragt und was sich schalten lässt.
 *
 *   modbus       Modbus TCP – Victron GX, ComAp-Steuerung, jedes Gerät mit
 *                Registerprofil. Host ist meist eine Adresse hinter einem
 *                WireGuard-Tunnel.
 *   comap_cloud  ComAp WebSupervisor (Cloud-API). Zugang: ComAp-Key,
 *                clientId, secret, Login-ID; extern_id = unitGuid.
 *   victron_vrm  Victron VRM-Portal (Cloud-API). Zugang: Access-Token;
 *                extern_id = idSite der Installation.
 *   shelly       Nur für die Steuerung: Shelly-Messwerte kommen ohnehin über
 *                MQTT. Eingetragen wird ein Shelly, damit er geschaltet
 *                werden kann; extern_id = MQTT-Präfix, profil = gen1|gen2.
 *
 * Zugangsdaten stehen verschlüsselt in `zugang_enc` und werden nie wieder
 * angezeigt – wer sie ändern will, trägt sie neu ein.
 */
final class Datenquellen
{
    public const ARTEN = [
        'modbus'      => 'Modbus TCP (Victron GX, ComAp, …)',
        'comap_cloud' => 'ComAp WebSupervisor (Cloud)',
        'victron_vrm' => 'Victron VRM (Cloud)',
        'shelly'      => 'Shelly (schaltbar über MQTT)',
    ];

    /** Kleinstes Abfrageintervall je Art – Clouds vertragen keine Sekundentakte. */
    public const MIN_INTERVALL = [
        'modbus'      => 1,
        'comap_cloud' => 60,
        'victron_vrm' => 60,
        'shelly'      => 60,
    ];

    /** @return list<array<string,mixed>> */
    public static function alle(?string $art = null): array
    {
        $rows = $art === null
            ? Database::select('SELECT * FROM datenquellen ORDER BY art, name')
            : Database::select('SELECT * FROM datenquellen WHERE art = ? ORDER BY name', [$art]);
        return array_map([self::class, 'aufbereiten'], $rows);
    }

    public static function find(int $id): ?array
    {
        $q = Database::first('SELECT * FROM datenquellen WHERE id = ?', [$id]);
        return $q === null ? null : self::aufbereiten($q);
    }

    public static function nachGeraet(string $geraet): ?array
    {
        $q = Database::first('SELECT * FROM datenquellen WHERE geraet = ?', [$geraet]);
        return $q === null ? null : self::aufbereiten($q);
    }

    private static function aufbereiten(array $q): array
    {
        $q['art_name'] = self::ARTEN[$q['art']] ?? $q['art'];
        $q['zugang_hinterlegt'] = (string) ($q['zugang_enc'] ?? '') !== '';
        unset($q['zugang_enc']);
        return $q;
    }

    /** Die entschlüsselten Zugangsdaten – nur für Sammler und Steuerung. */
    public static function zugang(int $id): array
    {
        $roh = (string) Database::scalar('SELECT zugang_enc FROM datenquellen WHERE id = ?', [$id]);
        if ($roh === '') {
            return [];
        }
        $klar = Crypto::decrypt($roh);
        return is_array($daten = json_decode($klar, true)) ? $daten : [];
    }

    /** Aus einem Namen eine Kennung für die InfluxDB machen. */
    public static function kennung(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = preg_replace('/[^a-z0-9_.-]+/', '-', $text) ?? '';
        return trim(mb_substr($text, 0, 128), '-');
    }

    /**
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $art = (string) ($daten['art'] ?? '');
        if (!isset(self::ARTEN[$art])) {
            return ['ok' => false, 'message' => 'Unbekannte Art der Datenquelle.'];
        }
        $name = trim((string) ($daten['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'Die Datenquelle braucht einen Namen.'];
        }
        $geraet = self::kennung((string) ($daten['geraet'] ?? '') !== '' ? (string) $daten['geraet'] : $name);
        if ($geraet === '') {
            return ['ok' => false, 'message' => 'Die Kennung darf nicht leer sein.'];
        }
        $doppelt = Database::first('SELECT id FROM datenquellen WHERE geraet = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$geraet, $id] : [$geraet]);
        if ($doppelt !== null) {
            return ['ok' => false, 'message' => 'Die Kennung „' . $geraet . '“ ist schon vergeben.'];
        }
        $host = trim((string) ($daten['host'] ?? ''));
        if ($art === 'modbus') {
            if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
                return ['ok' => false, 'message' => 'Für Modbus TCP braucht es eine Adresse (IP oder Name), z. B. die Adresse des GX hinter dem WireGuard-Tunnel.'];
            }
            if (trim((string) ($daten['profil'] ?? '')) === '' || Profile::find((string) $daten['profil']) === null) {
                return ['ok' => false, 'message' => 'Bitte ein Registerprofil wählen.'];
            }
        }
        // Das MQTT-Präfix eines Shelly wird zum Thema, an das geschaltet wird:
        // + und # wären Platzhalter für viele Geräte zugleich.
        if ($art === 'shelly' && preg_match('#^[^\s+\#\x00-\x1f\x7f]*$#', trim((string) ($daten['extern_id'] ?? ''))) !== 1) {
            return ['ok' => false, 'message' => 'Das MQTT-Präfix darf keine Leerzeichen und kein + oder # enthalten.'];
        }
        if (in_array($art, ['comap_cloud', 'victron_vrm', 'shelly'], true) && trim((string) ($daten['extern_id'] ?? '')) === '') {
            return ['ok' => false, 'message' => match ($art) {
                'comap_cloud' => 'Bitte die unitGuid der Steuerung angeben (steht in WebSupervisor bzw. kommt über „Einheiten abrufen“).',
                'victron_vrm' => 'Bitte die Installations-ID (idSite) aus dem VRM-Portal angeben.',
                default       => 'Bitte das MQTT-Präfix des Shelly angeben (z. B. shellypro4pm-a0b1c2d3e4f5).',
            }];
        }
        $intervall = max(self::MIN_INTERVALL[$art], (int) ($daten['intervall_s'] ?? 5));

        $felder = [
            'art'         => $art,
            'name'        => mb_substr($name, 0, 190),
            'geraet'      => $geraet,
            'host'        => mb_substr($host, 0, 190),
            'port'        => max(1, min(65535, (int) ($daten['port'] ?? 502))),
            'unit_id'     => max(0, min(255, (int) ($daten['unit_id'] ?? 1))),
            'profil'      => mb_substr(trim((string) ($daten['profil'] ?? '')), 0, 64),
            'extern_id'   => mb_substr(trim((string) ($daten['extern_id'] ?? '')), 0, 190),
            'intervall_s' => $intervall,
            'aktiv'       => !empty($daten['aktiv']) ? 1 : 0,
            'notizen'     => (string) ($daten['notizen'] ?? ''),
            'updated_at'  => gmdate('c'),
        ];

        // Zugangsdaten nur ersetzen, wenn etwas eingetragen wurde.
        $zugang = array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) ($daten['zugang'] ?? [])
        ), static fn ($v) => $v !== '');
        if ($zugang !== []) {
            $bisher = $id ? self::zugang($id) : [];
            $felder['zugang_enc'] = Crypto::encrypt((string) json_encode(array_merge($bisher, $zugang)));
        }

        if ($id) {
            Database::update('datenquellen', $id, $felder);
            Audit::log('datenquelle.update', 'datenquelle', (string) $id, ['name' => $name, 'zugang_neu' => $zugang !== []]);
            return ['ok' => true, 'message' => 'Datenquelle gespeichert.', 'id' => $id];
        }
        $felder['created_at'] = gmdate('c');
        $id = Database::insert('datenquellen', $felder);
        Audit::log('datenquelle.create', 'datenquelle', (string) $id, ['name' => $name, 'art' => $art]);
        return ['ok' => true, 'message' => 'Datenquelle angelegt.', 'id' => $id];
    }

    public static function loeschen(int $id): array
    {
        $q = self::find($id);
        if ($q === null) {
            return ['ok' => false, 'message' => 'Diese Datenquelle gibt es nicht.'];
        }
        Database::delete('datenquellen', $id);
        Database::execute("DELETE FROM freigaben WHERE ziel_art = 'geraet' AND ziel_id = ?", [$id]);
        Audit::log('datenquelle.delete', 'datenquelle', (string) $id, ['name' => $q['name']]);
        return ['ok' => true, 'message' => 'Datenquelle gelöscht. Messwerte in der InfluxDB bleiben erhalten.'];
    }

    public static function kontakt(int $id, ?string $fehler): void
    {
        $felder = ['letzter_versuch' => gmdate('c'), 'letzter_fehler' => $fehler];
        if ($fehler === null) {
            $felder['letzter_kontakt'] = gmdate('c');
        }
        Database::update('datenquellen', $id, $felder);
    }
}
