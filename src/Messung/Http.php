<?php
declare(strict_types=1);

namespace App\Messung;

/**
 * Kleine HTTP-Anfragen – für InfluxDB, ComAp WebSupervisor, Victron VRM und
 * andere Instanzen (Schnittstelle).
 *
 * curl, wenn vorhanden (Zeitgrenzen, TLS), sonst PHP-Streams. Kein
 * Rahmenwerk: ein paar Gegenstellen rechtfertigen keine Bibliothek.
 */
final class Http
{
    /** Für Prüfskripte: statt ins Netz zu gehen, hier antworten. */
    public static $attrappe = null;

    /** Fehlernummer, wenn die Antwort größer war als `max` (wie CURLE_FILESIZE_EXCEEDED). */
    public const ZU_GROSS = 63;

    /**
     * Optionen:
     *   kopf       Kopfzeilen (Name => Wert)
     *   rumpf      Körper der Anfrage
     *   zeit       Sekunden für die ganze Anfrage (Vorgabe 10)
     *   verbindung Sekunden für den Verbindungsaufbau (Vorgabe min(5, zeit))
     *   max        größte Antwort in Bytes – darüber wird abgebrochen
     *              (nummer = ZU_GROSS), statt alles in den Speicher zu laden
     *
     * `ok` heißt nur: es kam eine Antwort – auch 4xx/5xx. `nummer` ist die
     * curl-Fehlernummer (0 ohne Fehler und bei PHP-Streams), damit der
     * Aufrufer sagen kann, *was* nicht ging (Name unbekannt, abgewiesen …).
     *
     * @param array{kopf?:array<string,string>,rumpf?:string,zeit?:int,verbindung?:int,max?:int} $optionen
     * @return array{ok:bool,status:int,rumpf:string,fehler:string,nummer:int}
     */
    public static function anfrage(string $methode, string $url, array $optionen = []): array
    {
        if (is_callable(self::$attrappe)) {
            $antwort = (self::$attrappe)($methode, $url, $optionen);
            return is_array($antwort) ? $antwort + ['nummer' => 0] : $antwort;
        }
        // Nur HTTP(S): eine Adresse aus den Einstellungen (InfluxDB) soll
        // nicht über file://, gopher:// oder dict:// woanders hingreifen.
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'Nur http:// und https:// sind erlaubt.', 'nummer' => 0];
        }
        $kopf = [];
        foreach ((array) ($optionen['kopf'] ?? []) as $name => $wert) {
            $kopf[] = $name . ': ' . $wert;
        }
        $zeit = max(1, (int) ($optionen['zeit'] ?? 10));
        $verbindung = isset($optionen['verbindung']) ? min(max(1, (int) $optionen['verbindung']), $zeit) : min(5, $zeit);
        $max = isset($optionen['max']) && (int) $optionen['max'] > 0 ? (int) $optionen['max'] : null;
        $zuGross = static fn (int $status): array => [
            'ok' => false, 'status' => $status, 'rumpf' => '', 'nummer' => self::ZU_GROSS,
            'fehler' => 'Die Antwort ist größer als ' . self::groesse((int) $max) . ' – abgebrochen.',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $methode,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $kopf,
                CURLOPT_CONNECTTIMEOUT => $verbindung,
                CURLOPT_TIMEOUT        => $zeit,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            if (isset($optionen['rumpf'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $optionen['rumpf']);
            }
            $abgebrochen = false;
            if ($max !== null) {
                // Mitzählen und abbrechen, sobald es mehr wird – eine
                // angekündigte Länge allein hält keinen Server auf.
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, defined('CURLOPT_XFERINFOFUNCTION') ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION,
                    static function ($_ch, $_gesamt, $geladen) use ($max, &$abgebrochen): int {
                        if ((int) $geladen > $max) {
                            $abgebrochen = true;
                            return 1;
                        }
                        return 0;
                    });
            }
            $rumpf = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $fehler = curl_error($ch);
            $nummer = curl_errno($ch);
            curl_close($ch);
            if ($abgebrochen || ($max !== null && is_string($rumpf) && strlen($rumpf) > $max)) {
                return $zuGross($status);
            }
            if ($rumpf === false) {
                return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => $fehler !== '' ? $fehler : 'keine Antwort', 'nummer' => $nummer];
            }
            return ['ok' => true, 'status' => $status, 'rumpf' => (string) $rumpf, 'fehler' => '', 'nummer' => 0];
        }

        $kontext = stream_context_create(['http' => [
            'method'        => $methode,
            'header'        => implode("\r\n", $kopf),
            'content'       => (string) ($optionen['rumpf'] ?? ''),
            'timeout'       => $zeit,
            'ignore_errors' => true,
        ]]);
        $rumpf = $max !== null ? @file_get_contents($url, false, $kontext, 0, $max + 1) : @file_get_contents($url, false, $kontext);
        if ($rumpf === false) {
            $letzter = error_get_last();
            return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => (string) ($letzter['message'] ?? 'keine Antwort'), 'nummer' => 0];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $zeile) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($max !== null && strlen($rumpf) > $max) {
            return $zuGross($status);
        }
        return ['ok' => true, 'status' => $status, 'rumpf' => $rumpf, 'fehler' => '', 'nummer' => 0];
    }

    private static function groesse(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? str_replace('.', ',', (string) round($bytes / 1024 / 1024, 1)) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
