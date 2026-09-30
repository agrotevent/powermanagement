<?php
declare(strict_types=1);

namespace App\Messung;

/**
 * Kleine HTTP-Anfragen – für InfluxDB, ComAp WebSupervisor und Victron VRM.
 *
 * curl, wenn vorhanden (Zeitgrenzen, TLS), sonst PHP-Streams. Kein
 * Rahmenwerk: drei Gegenstellen rechtfertigen keine Bibliothek.
 */
final class Http
{
    /** Für Prüfskripte: statt ins Netz zu gehen, hier antworten. */
    public static $attrappe = null;

    /**
     * @param array{kopf?:array<string,string>,rumpf?:string,zeit?:int} $optionen
     * @return array{ok:bool,status:int,rumpf:string,fehler:string}
     */
    public static function anfrage(string $methode, string $url, array $optionen = []): array
    {
        if (is_callable(self::$attrappe)) {
            return (self::$attrappe)($methode, $url, $optionen);
        }
        // Nur HTTP(S): eine Adresse aus den Einstellungen (InfluxDB) soll
        // nicht über file://, gopher:// oder dict:// woanders hingreifen.
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'Nur http:// und https:// sind erlaubt.'];
        }
        $kopf = [];
        foreach ((array) ($optionen['kopf'] ?? []) as $name => $wert) {
            $kopf[] = $name . ': ' . $wert;
        }
        $zeit = max(1, (int) ($optionen['zeit'] ?? 10));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $methode,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $kopf,
                CURLOPT_CONNECTTIMEOUT => min(5, $zeit),
                CURLOPT_TIMEOUT        => $zeit,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            if (isset($optionen['rumpf'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $optionen['rumpf']);
            }
            $rumpf = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $fehler = curl_error($ch);
            curl_close($ch);
            if ($rumpf === false) {
                return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => $fehler !== '' ? $fehler : 'keine Antwort'];
            }
            return ['ok' => true, 'status' => $status, 'rumpf' => (string) $rumpf, 'fehler' => ''];
        }

        $kontext = stream_context_create(['http' => [
            'method'        => $methode,
            'header'        => implode("\r\n", $kopf),
            'content'       => (string) ($optionen['rumpf'] ?? ''),
            'timeout'       => $zeit,
            'ignore_errors' => true,
        ]]);
        $rumpf = @file_get_contents($url, false, $kontext);
        if ($rumpf === false) {
            $letzter = error_get_last();
            return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => (string) ($letzter['message'] ?? 'keine Antwort')];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $zeile) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return ['ok' => true, 'status' => $status, 'rumpf' => $rumpf, 'fehler' => ''];
    }
}
