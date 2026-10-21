<?php
declare(strict_types=1);

namespace App\Messung;

/**
 * Kleine HTTP-Anfragen – für InfluxDB, ComAp WebSupervisor, Victron VRM
 * und Eventworx.
 *
 * curl, wenn vorhanden (Zeitgrenzen, TLS), sonst PHP-Streams. Kein
 * Rahmenwerk: vier Gegenstellen rechtfertigen keine Bibliothek.
 *
 * Umleitungen werden auf beiden Wegen **nie** verfolgt: Die Kopfzeilen
 * (Tokens wie `S-API-TOKEN` oder `X-Authorization`) gingen sonst an das
 * neue Ziel, wer immer das ist. Ein 3xx kommt als Antwort zurück, der
 * Aufrufer entscheidet.
 */
final class Http
{
    /** Für Prüfskripte: statt ins Netz zu gehen, hier antworten. */
    public static $attrappe = null;

    /** Für Prüfskripte: den Weg über PHP-Streams erzwingen, auch wenn curl da ist. */
    public static bool $ohneCurl = false;

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
            // Ein Umbruch im Wert – etwa in einem eingefügten Token – schriebe
            // sonst eine eigene Kopfzeile in die Anfrage.
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', (string) $name) !== 1 || preg_match('/[\r\n\0]/', (string) $wert) === 1) {
                return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'Ungültige Kopfzeile in der Anfrage.'];
            }
            $kopf[] = $name . ': ' . $wert;
        }
        $zeit = max(1, (int) ($optionen['zeit'] ?? 10));

        if (function_exists('curl_init') && !self::$ohneCurl) {
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

        // PHP-Streams folgen Umleitungen von Haus aus – und nähmen dabei
        // die Kopfzeilen mit. Hier also ausdrücklich nicht.
        $kontext = stream_context_create(['http' => [
            'method'          => $methode,
            'header'          => implode("\r\n", $kopf),
            'content'         => (string) ($optionen['rumpf'] ?? ''),
            'timeout'         => $zeit,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
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
