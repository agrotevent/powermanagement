<?php
declare(strict_types=1);

namespace App\Messung;

/**
 * Victron VRM – Cloud-API v2.
 *
 *   GET https://vrmapi.victronenergy.com/v2/installations/{idSite}/diagnostics?count=1000
 *       Header X-Authorization: Token <Zugangstoken aus VRM → Einstellungen → Integrationen>
 *
 * Die Diagnose liefert je Wert Dienst (dbusServiceType), Pfad (dbusPath) und
 * Rohwert. Übersetzt wird über den Pfad – er ist über Venus-OS-Fassungen
 * hinweg stabil, die numerischen Codes sind es nicht immer.
 *
 * VRM aktualisiert im Takt des GX-Uploads (Vorgabe: 15 Minuten, einstellbar
 * bis 1 Minute). Livewerte kommen über Modbus TCP am GX; VRM ist der Weg für
 * Anlagen ohne Tunnel.
 */
final class VictronVrm
{
    public const BASIS = 'https://vrmapi.victronenergy.com/v2';

    public static function werte(array $quelle): array
    {
        $zugang = Datenquellen::zugang((int) $quelle['id']);
        $token = trim((string) ($zugang['token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Für das VRM-Portal fehlt das Zugangstoken.');
        }
        $antwort = Http::anfrage('GET', self::BASIS . '/installations/' . rawurlencode((string) $quelle['extern_id']) . '/diagnostics?count=1000', [
            'kopf' => ['X-Authorization' => 'Token ' . $token, 'Accept' => 'application/json'],
            'zeit' => 20,
        ]);
        if (!$antwort['ok']) {
            throw new \RuntimeException('VRM nicht erreichbar: ' . $antwort['fehler']);
        }
        $daten = json_decode($antwort['rumpf'], true);
        if ($antwort['status'] !== 200 || !is_array($daten) || empty($daten['success'])) {
            throw new \RuntimeException('VRM antwortet mit ' . $antwort['status'] . (is_array($daten) && isset($daten['errors']) ? ': ' . json_encode($daten['errors']) : '') . '.');
        }
        return self::uebersetzen((array) ($daten['records'] ?? []));
    }

    /** Diagnose-Einträge in Kanäle übersetzen. */
    public static function uebersetzen(array $eintraege): array
    {
        $kanaele = [];
        $setzen = static function (string $kanal, string $phase, string $feld, mixed $wert) use (&$kanaele): void {
            if (!is_numeric($wert)) {
                return;
            }
            $kanaele[$kanal] ??= ['phasen' => [], 'zustand' => []];
            if ($phase === '') {
                $kanaele[$kanal]['zustand'][$feld] = (float) $wert;
            } else {
                $kanaele[$kanal]['phasen'][$phase][$feld] = (float) $wert;
            }
        };
        $felder = ['V' => 'u', 'I' => 'i', 'P' => 'p', 'F' => 'f'];
        $bereiche = ['Consumption' => 'verbrauch', 'Grid' => 'netz', 'Genset' => 'generator', 'PvOnOutput' => 'pv_ac', 'PvOnGrid' => 'pv_ac_netz'];

        foreach ($eintraege as $e) {
            $dienst = (string) ($e['dbusServiceType'] ?? '');
            $pfad = (string) ($e['dbusPath'] ?? '');
            $wert = $e['rawValue'] ?? null;
            if ($pfad === '') {
                continue;
            }
            if ($dienst === 'system') {
                if (preg_match('#^/Ac/(Consumption|Grid|Genset|PvOnOutput|PvOnGrid)/L([123])/Power$#', $pfad, $m) === 1) {
                    $setzen($bereiche[$m[1]], 'L' . $m[2], 'p', $wert);
                } elseif ($pfad === '/Dc/Battery/Soc') {
                    $setzen('batterie', '', 'soc', $wert);
                } elseif ($pfad === '/Dc/Battery/Voltage') {
                    $setzen('batterie', '', 'u', $wert);
                } elseif ($pfad === '/Dc/Battery/Current') {
                    $setzen('batterie', '', 'i', $wert);
                } elseif ($pfad === '/Dc/Battery/Power') {
                    $setzen('batterie', '', 'p', $wert);
                } elseif ($pfad === '/Dc/Pv/Power') {
                    $setzen('pv_dc', 'sum', 'p', $wert);
                }
            } elseif ($dienst === 'vebus') {
                if (preg_match('#^/Ac/(ActiveIn|Out)/L([123])/([VIPF])$#', $pfad, $m) === 1) {
                    $setzen($m[1] === 'Out' ? 'ac_aus' : 'ac_ein', 'L' . $m[2], $felder[$m[3]], $wert);
                } elseif ($pfad === '/State') {
                    $setzen('vebus', '', 'zustand', $wert);
                } elseif ($pfad === '/Mode') {
                    $setzen('vebus', '', 'schalterstellung', $wert);
                }
            } elseif ($dienst === 'genset' && preg_match('#^/Ac/L([123])/(Voltage|Current|Power)$#', $pfad, $m) === 1) {
                $setzen('generator', 'L' . $m[1], ['Voltage' => 'u', 'Current' => 'i', 'Power' => 'p'][$m[2]], $wert);
            }
        }
        return $kanaele;
    }
}
