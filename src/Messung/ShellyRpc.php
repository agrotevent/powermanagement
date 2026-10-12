<?php
declare(strict_types=1);

namespace App\Messung;

/**
 * JSON-RPC an Shellys der Generation 2+ über MQTT.
 *
 * Anfrage an <präfix>/rpc, Antwort an <src>/rpc. Für Geräte mit eigenem
 * MQTT-Zugang liegt src im Baum des Geräts (<präfix>/pm-…): nur dorthin darf
 * der eigene Zugang schreiben, und genau diesen Baum sperrt der Broker für
 * den gemeinsamen Zugang „shelly“ – eine Antwort kann dann nur vom Gerät
 * selbst kommen.
 *
 * Über MQTT prüft ein Shelly keine Anmeldung: wer an <präfix>/rpc schreiben
 * darf, darf alles. Deshalb gehen nur die Methoden dieser Liste hinaus, und
 * MQTT.SetConfig nur mit Benutzer und Kennwort. Schalten (Switch.Set) kommt
 * ausschließlich aus Steuerung::ausfuehren().
 */
final class ShellyRpc
{
    public const METHODEN = ['Shelly.GetDeviceInfo', 'Sys.GetStatus', 'MQTT.GetConfig', 'MQTT.SetConfig', 'Switch.Set'];

    public const ZWECKE = ['steuerung', 'konto'];

    /** Absender: "<präfix>/pm-<zweck>-<8hex>" (im Baum) oder "pm-<zweck>-<8hex>". */
    public static function quelle(string $praefix, string $zweck, bool $imBaum): string
    {
        if (!in_array($zweck, self::ZWECKE, true)) {
            throw new \InvalidArgumentException('Unbekannter Zweck.');
        }
        $name = 'pm-' . $zweck . '-' . bin2hex(random_bytes(4));
        return $imBaum ? trim($praefix, '/') . '/' . $name : $name;
    }

    /**
     * Eine Methode aufrufen und auf die Antwort warten.
     *
     * Gilt nur eine Antwort mit Thema "<quelle>/rpc", ohne „method“, mit der
     * eigenen id und dst = quelle. Meldungen enthalten nie Parameter oder
     * Nutzdaten – bei MQTT.SetConfig stünde dort ein Kennwort.
     *
     * @return array{ok:bool,result?:array,error?:array,message:string}
     */
    public static function aufrufen(Mqtt $mqtt, string $praefix, string $quelle, string $methode, array $params = [], float $zeit = 5.0, int $qos = 1): array
    {
        $praefix = trim($praefix, '/');
        if (!in_array($methode, self::METHODEN, true)) {
            return ['ok' => false, 'message' => 'Diese Methode geht nicht an Shellys.'];
        }
        if ($methode === 'MQTT.SetConfig' && !self::nurZugangsdaten($params)) {
            return ['ok' => false, 'message' => 'MQTT.SetConfig nur mit Benutzer und Kennwort.'];
        }
        if (preg_match('#^(.+/)?pm-(steuerung|konto)-[0-9a-f]{8}$#', $quelle) !== 1
            || preg_match('#[+\#\x00-\x20\x7f]#', $praefix . $quelle) === 1 || $praefix === '') {
            return ['ok' => false, 'message' => 'Ungültiger Absender oder Präfix.'];
        }
        $id = random_int(1_000_000, 2_000_000_000);
        $anfrage = ['id' => $id, 'src' => $quelle, 'method' => $methode];
        if ($params !== []) {
            $anfrage['params'] = $params;
        }
        $mqtt->abonnieren([$quelle . '/rpc']);
        $mqtt->veroeffentlichen($praefix . '/rpc', (string) json_encode($anfrage, JSON_UNESCAPED_SLASHES), $qos);
        $antwort = null;
        $mqtt->lauschen($zeit, static function (string $thema, string $nachricht) use ($quelle, $id, &$antwort): bool {
            if ($thema !== $quelle . '/rpc') {
                return false;
            }
            $daten = json_decode($nachricht, true);
            if (!is_array($daten) || array_key_exists('method', $daten)
                || ($daten['id'] ?? null) !== $id || ($daten['dst'] ?? null) !== $quelle) {
                return false;
            }
            $antwort = $daten;
            return true;
        });
        if ($antwort === null) {
            return ['ok' => false, 'message' => 'keine Antwort'];
        }
        if (isset($antwort['error'])) {
            $fehler = is_array($antwort['error']) ? $antwort['error'] : ['message' => 'Fehler'];
            return ['ok' => false, 'error' => $fehler, 'message' => 'Gerät meldet: ' . mb_substr((string) ($fehler['message'] ?? 'Fehler'), 0, 200)];
        }
        return ['ok' => true, 'result' => is_array($antwort['result'] ?? null) ? $antwort['result'] : [], 'message' => ''];
    }

    private static function nurZugangsdaten(array $params): bool
    {
        if (array_keys($params) !== ['config'] || !is_array($params['config']) || $params['config'] === []) {
            return false;
        }
        foreach ($params['config'] as $schluessel => $wert) {
            if (!in_array($schluessel, ['user', 'pass'], true) || !is_string($wert)) {
                return false;
            }
        }
        return true;
    }
}
