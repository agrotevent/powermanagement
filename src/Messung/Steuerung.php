<?php
declare(strict_types=1);

namespace App\Messung;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;

/**
 * Schalten: Shelly ein/aus, Victron Modus/Relais, ComAp Start/Stopp.
 *
 * Jeder Befehl geht über ausfuehren() und damit durch dieselben drei
 * Prüfungen (Recht `steuern`, keine Sperre an der Anlage, Schalt-Freigabe
 * für Gerät bzw. Kanal) und landet im Protokoll – auch der gescheiterte.
 * Eine zweite Tür, die an der Prüfung vorbeiführt, gibt es nicht.
 */
final class Steuerung
{
    /**
     * Was sich an einer Datenquelle schalten lässt.
     *
     * @param list<string> $kanaele bekannte Kanäle (für Shelly aus der InfluxDB)
     * @return list<array{schluessel:string,name:string,kanal:string,art:string}>
     */
    public static function befehle(array $quelle, array $kanaele = []): array
    {
        switch ((string) $quelle['art']) {
            case 'shelly':
                $schalter = array_values(array_filter($kanaele, static fn ($k) => preg_match('/^(switch|relay):\d+$/', $k) === 1));
                if ($schalter === []) {
                    $schalter = [(string) $quelle['profil'] === 'gen1' ? 'relay:0' : 'switch:0'];
                }
                $out = [];
                foreach ($schalter as $kanal) {
                    $out[] = ['schluessel' => 'ein', 'name' => 'Ein', 'kanal' => $kanal, 'art' => 'ein'];
                    $out[] = ['schluessel' => 'aus', 'name' => 'Aus', 'kanal' => $kanal, 'art' => 'aus'];
                }
                return $out;
            case 'modbus':
                $profil = Profile::find((string) $quelle['profil']);
                $out = [];
                foreach ($profil['befehle'] ?? [] as $b) {
                    if (!empty($b['aktiv']) && ($b['adresse'] ?? null) !== null) {
                        $out[] = ['schluessel' => (string) $b['schluessel'], 'name' => (string) $b['name'], 'kanal' => (string) ($b['kanal'] ?? ''), 'art' => (string) ($b['art'] ?? '')];
                    }
                }
                return $out;
            case 'comap_cloud':
                $out = [];
                foreach (ComapCloud::BEFEHLE as $schluessel => $b) {
                    $out[] = ['schluessel' => $schluessel, 'name' => $b['name'], 'kanal' => 'motor', 'art' => $b['art']];
                }
                return $out;
            default:
                return [];
        }
    }

    /**
     * Einen Befehl ausführen – nach Prüfung, mit Protokoll.
     *
     * @return array{ok:bool,message:string}
     */
    public static function ausfuehren(int $quelleId, string $befehl, string $kanal, ?int $objektId = null): array
    {
        $quelle = Datenquellen::find($quelleId);
        if ($quelle === null) {
            return ['ok' => false, 'message' => 'Diese Datenquelle gibt es nicht.'];
        }
        $objekt = $objektId !== null ? \App\Services\AnlagenService::find($objektId) : null;
        if (!Zugriff::darfSteuern($quelle, $kanal, $objekt)) {
            self::protokoll($quelle, $objektId, $befehl, $kanal, false, 'abgelehnt: keine Schalt-Freigabe');
            return ['ok' => false, 'message' => 'Für dieses Gerät fehlt die Schalt-Freigabe – oder die Steuerung der Anlage ist gesperrt.'];
        }
        $bekannt = false;
        foreach (self::befehle($quelle, [$kanal]) as $b) {
            if ($b['schluessel'] === $befehl && ($b['kanal'] === $kanal || $quelle['art'] !== 'shelly')) {
                $bekannt = true;
                break;
            }
        }
        if (!$bekannt) {
            return ['ok' => false, 'message' => 'Diesen Befehl kennt das Gerät nicht.'];
        }

        try {
            if (Config::isDemo()) {
                $ergebnis = ['ok' => true, 'message' => 'Demo-Modus: „' . $befehl . '“ an ' . $quelle['name'] . ' wäre jetzt gesendet worden.'];
                Demo::schalten((string) $quelle['geraet'], $kanal, $befehl);
            } else {
                $ergebnis = match ((string) $quelle['art']) {
                    'shelly'      => self::shelly($quelle, $befehl, $kanal),
                    'modbus'      => self::modbus($quelle, $befehl),
                    'comap_cloud' => ComapCloud::befehl($quelle, $befehl),
                    default       => ['ok' => false, 'message' => 'Diese Datenquelle lässt sich nicht schalten.'],
                };
            }
        } catch (\Throwable $e) {
            $ergebnis = ['ok' => false, 'message' => 'Schalten fehlgeschlagen: ' . $e->getMessage()];
        }

        self::protokoll($quelle, $objektId, $befehl, $kanal, $ergebnis['ok'], $ergebnis['message']);
        return $ergebnis;
    }

    /**
     * Shelly über MQTT schalten.
     *
     * Gen2+: JSON-RPC an <präfix>/rpc, die Antwort kommt an <src>/rpc – so
     * lässt sich melden, ob der Shelly den Befehl angenommen hat, statt nur
     * „gesendet“. Gen1 kennt keine Antwort; dort genügt das Absenden.
     */
    private static function shelly(array $quelle, string $befehl, string $kanal): array
    {
        $praefix = trim((string) $quelle['extern_id'], '/');
        $an = $befehl === 'ein';
        if (preg_match('/^(switch|relay):(\d+)$/', $kanal, $m) !== 1) {
            return ['ok' => false, 'message' => 'Unbekannter Schaltkanal.'];
        }
        $nr = (int) $m[2];
        $mqtt = Mqtt::ausKonfiguration('pm-steuerung-' . bin2hex(random_bytes(3)));
        try {
            if ((string) $quelle['profil'] === 'gen1') {
                $mqtt->veroeffentlichen($praefix . '/relay/' . $nr . '/command', $an ? 'on' : 'off', 1);
                return ['ok' => true, 'message' => $quelle['name'] . ': „' . ($an ? 'Ein' : 'Aus') . '“ gesendet (Gen1 bestätigt nicht – der Zustand erscheint mit dem nächsten Messwert).'];
            }
            $src = 'pm-steuerung-' . bin2hex(random_bytes(4));
            $id = random_int(1, 1_000_000);
            $mqtt->abonnieren([$src . '/rpc']);
            $mqtt->veroeffentlichen($praefix . '/rpc', (string) json_encode([
                'id' => $id, 'src' => $src, 'method' => 'Switch.Set', 'params' => ['id' => $nr, 'on' => $an],
            ]), 1);
            $antwort = null;
            $mqtt->lauschen(5, static function (string $thema, string $nachricht) use ($id, &$antwort): bool {
                $daten = json_decode($nachricht, true);
                if (is_array($daten) && (int) ($daten['id'] ?? 0) === $id) {
                    $antwort = $daten;
                    return true;
                }
                return false;
            });
            if ($antwort === null) {
                return ['ok' => false, 'message' => $quelle['name'] . ' hat nicht geantwortet. Ist das Gerät online und das MQTT-Präfix richtig?'];
            }
            if (isset($antwort['error'])) {
                return ['ok' => false, 'message' => $quelle['name'] . ' meldet: ' . ($antwort['error']['message'] ?? 'Fehler')];
            }
            return ['ok' => true, 'message' => $quelle['name'] . ' (' . $kanal . ') ist jetzt ' . ($an ? 'eingeschaltet' : 'ausgeschaltet') . '.'];
        } finally {
            $mqtt->trennen();
        }
    }

    private static function modbus(array $quelle, string $befehl): array
    {
        $profil = Profile::find((string) $quelle['profil']);
        $eintrag = null;
        foreach ($profil['befehle'] ?? [] as $b) {
            if ((string) $b['schluessel'] === $befehl && !empty($b['aktiv'])) {
                $eintrag = $b;
            }
        }
        if ($eintrag === null || ($eintrag['adresse'] ?? null) === null) {
            return ['ok' => false, 'message' => 'Der Befehl ist im Registerprofil nicht freigeschaltet.'];
        }
        $unit = $eintrag['unit'] ?? null;
        $unit = $unit === null ? (int) $quelle['unit_id'] : (int) $unit;
        $mb = new ModbusTcp((string) $quelle['host'], (int) $quelle['port'], (float) Config::get('sammler.modbus_timeout', 3));
        try {
            if ((int) ($eintrag['funktion'] ?? 6) === 16) {
                $mb->schreibenMehrere($unit, (int) $eintrag['adresse'], array_map('intval', (array) ($eintrag['werte'] ?? [])));
            } else {
                $mb->schreibenEinzeln($unit, (int) $eintrag['adresse'], (int) ($eintrag['wert'] ?? 0));
            }
        } finally {
            $mb->trennen();
        }
        return ['ok' => true, 'message' => $quelle['name'] . ': „' . $eintrag['name'] . '“ geschrieben.'];
    }

    private static function protokoll(array $quelle, ?int $objektId, string $befehl, string $kanal, bool $ok, string $meldung): void
    {
        Database::insert('schaltvorgaenge', [
            'objekt_id'      => $objektId,
            'datenquelle_id' => (int) $quelle['id'],
            'geraet'         => (string) $quelle['geraet'],
            'befehl'         => mb_substr($befehl, 0, 64),
            'kanal'          => mb_substr($kanal, 0, 64),
            'ergebnis'       => $ok ? 'ok' : 'fehler',
            'meldung'        => mb_substr($meldung, 0, 1000),
            'user_id'        => Auth::id(),
            'username'       => Auth::name(),
            'created_at'     => gmdate('c'),
        ]);
        Audit::log('steuerung.' . ($ok ? 'ok' : 'fehler'), 'datenquelle', (string) $quelle['id'], [
            'befehl' => $befehl, 'kanal' => $kanal, 'objekt' => $objektId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function protokollLesen(int $limit = 100, ?int $objektId = null): array
    {
        $sql = 'SELECT s.*, o.name AS objekt_name, d.name AS quelle_name FROM schaltvorgaenge s
                LEFT JOIN objekte o ON o.id = s.objekt_id LEFT JOIN datenquellen d ON d.id = s.datenquelle_id';
        $params = [];
        if ($objektId !== null) {
            $sql .= ' WHERE s.objekt_id = ?';
            $params[] = $objektId;
        }
        return Database::select($sql . ' ORDER BY s.id DESC LIMIT ' . max(1, min(500, $limit)), $params);
    }
}
