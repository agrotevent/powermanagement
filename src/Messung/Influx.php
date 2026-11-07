<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Config;
use App\Support\Logger;
use App\Support\Session;
use App\Support\Settings;

/**
 * Zugriff auf die InfluxDB über die v1-kompatible Schnittstelle.
 *
 * Warum InfluxQL und nicht Flux: InfluxQL verstehen InfluxDB 1.8, 2.x (über
 * die DBRP-Zuordnung, die das Installationsskript anlegt) und 3.x. Flux gibt
 * es in 3.x nicht mehr. Eine bestehende Grafana-Installation, die heute
 * schon auf dieselbe Datenbank schaut, bleibt davon unberührt.
 *
 *   /query?db=…&q=…&epoch=ms   lesen
 *   /write?db=…&precision=ms   schreiben (nur der Sammler im Modus „influx“)
 *
 * Anmeldung: Token (2.x/3.x, Header „Authorization: Token …“), sonst
 * Benutzer/Kennwort (1.8), sonst ohne.
 */
final class Influx
{
    public static function url(): string
    {
        return rtrim((string) Config::get('influx.url', 'http://127.0.0.1:8086'), '/');
    }

    /**
     * Taugt die Adresse? null = ja (leer: die aus config.php). Sie bekommt
     * Token bzw. Kennwort mit – darum nur http(s) mit Server, ohne
     * Zugangsdaten, Abfrage und Anker. Token und Kennwort gelten nur für die
     * Adresse, für die sie eingetragen sind (Settings::BINDUNG).
     */
    public static function adresseFehler(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return 'Die Adresse der InfluxDB darf keine Leer- oder Steuerzeichen enthalten.';
        }
        $teile = parse_url($url);
        $schema = is_array($teile) ? strtolower((string) ($teile['scheme'] ?? '')) : '';
        if (!in_array($schema, ['http', 'https'], true) || (string) ($teile['host'] ?? '') === '') {
            return 'Die Adresse der InfluxDB beginnt mit http:// oder https:// und nennt den Server, z. B. http://127.0.0.1:8086.';
        }
        if (isset($teile['user']) || isset($teile['pass'])) {
            return 'Zugangsdaten gehören nicht in die Adresse der InfluxDB – Token und Kennwort haben eigene Felder.';
        }
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return 'Die Adresse der InfluxDB enthält weder „?“ noch „#“ – Datenbank und Zugang haben eigene Felder.';
        }
        return null;
    }

    public static function datenbank(): string
    {
        return (string) Config::get('influx.database', 'strom');
    }

    public static function token(): string
    {
        return Settings::geheimnis(Settings::GEHEIM_INFLUX_TOKEN, 'influx.token');
    }

    /** @return array<string,string> */
    private static function kopf(): array
    {
        $token = self::token();
        return $token !== '' ? ['Authorization' => 'Token ' . $token] : [];
    }

    /** @return array<string,string> */
    private static function zugangParameter(): array
    {
        if (self::token() !== '') {
            return [];
        }
        $benutzer = (string) Config::get('influx.username', '');
        if ($benutzer === '') {
            return [];
        }
        return ['u' => $benutzer, 'p' => Settings::geheimnis(Settings::GEHEIM_INFLUX_KENNWORT, 'influx.password')];
    }

    /**
     * Eine InfluxQL-Abfrage (auch mehrere, mit Semikolon getrennt).
     *
     * @return list<array{name:string,tags:array<string,string>,columns:list<string>,values:list<list<mixed>>}>|null
     *         Serien der ersten Anweisung; null bei Fehler
     */
    public static function abfragen(string $influxql, ?string &$fehler = null): ?array
    {
        $alle = self::abfragenAlle($influxql, $fehler);
        return $alle === null ? null : ($alle[0] ?? []);
    }

    /**
     * @return list<list<array<string,mixed>>>|null Serien je Anweisung
     */
    public static function abfragenAlle(string $influxql, ?string &$fehler = null): ?array
    {
        $parameter = array_merge(['db' => self::datenbank(), 'q' => $influxql, 'epoch' => 'ms'], self::zugangParameter());
        $beginn = microtime(true);
        // Bis zu influx.timeout Sekunden – so lange stünden sonst alle
        // anderen Anfragen desselben Browsers an der Sitzung an, auch beim
        // Aufbau einer Seite (Dashboard, Anlage, Auswertung, Bereiche). Wie
        // PartnerService::anfrage() ohne Sitzungssperre; die Live-Abfragen
        // haben sie schon vorher freigegeben, dann ändert das nichts.
        $kopf = self::kopf() + ['Content-Type' => 'application/x-www-form-urlencoded'];
        $antwort = Session::ohneSperre(static fn (): array => Http::anfrage('POST', self::url() . '/query', [
            'kopf'   => $kopf,
            'rumpf'  => http_build_query($parameter),
            'zeit'   => (int) Config::get('influx.timeout', 8),
        ]));
        // Seiten mit Livewerten fragen alle 5 s – eine träge InfluxDB macht
        // sich zuerst dort bemerkbar. Im Log steht sie dann schwarz auf weiß.
        $dauer = microtime(true) - $beginn;
        if ($dauer > 2.0) {
            Logger::info('InfluxDB antwortet langsam', ['sekunden' => round($dauer, 1), 'ok' => $antwort['ok']]);
        }
        if (!$antwort['ok']) {
            $fehler = 'InfluxDB nicht erreichbar: ' . $antwort['fehler'];
            return null;
        }
        $daten = json_decode($antwort['rumpf'], true);
        if ($antwort['status'] >= 300 || !is_array($daten)) {
            $fehler = self::antwortFehler($antwort['status'], $antwort['rumpf']);
            return null;
        }
        if (isset($daten['error'])) {
            $fehler = 'InfluxDB: ' . $daten['error'];
            return null;
        }
        $ergebnis = [];
        foreach ((array) ($daten['results'] ?? []) as $teil) {
            if (isset($teil['error'])) {
                $fehler = 'InfluxDB: ' . $teil['error'];
                $ergebnis[] = [];
                continue;
            }
            $serien = [];
            foreach ((array) ($teil['series'] ?? []) as $serie) {
                $serien[] = [
                    'name'    => (string) ($serie['name'] ?? ''),
                    'tags'    => (array) ($serie['tags'] ?? []),
                    'columns' => (array) ($serie['columns'] ?? []),
                    'values'  => (array) ($serie['values'] ?? []),
                ];
            }
            $ergebnis[] = $serien;
        }
        return $ergebnis;
    }

    /**
     * Zeilen im Line Protocol schreiben (Präzision ms).
     *
     * @param list<string> $zeilen
     */
    public static function schreiben(array $zeilen, ?string &$fehler = null): bool
    {
        if ($zeilen === []) {
            return true;
        }
        $parameter = array_merge(['db' => self::datenbank(), 'precision' => 'ms'], self::zugangParameter());
        $antwort = Http::anfrage('POST', self::url() . '/write?' . http_build_query($parameter), [
            'kopf'  => self::kopf() + ['Content-Type' => 'text/plain; charset=utf-8'],
            'rumpf' => implode("\n", $zeilen),
            'zeit'  => (int) Config::get('influx.timeout', 8),
        ]);
        if (!$antwort['ok']) {
            $fehler = 'InfluxDB nicht erreichbar: ' . $antwort['fehler'];
            return false;
        }
        if ($antwort['status'] >= 300) {
            $fehler = self::antwortFehler($antwort['status'], $antwort['rumpf']);
            return false;
        }
        return true;
    }

    /**
     * Fehlermeldung zu einer Antwort: Status und – nur aus gültigem JSON –
     * der Text unter `error`. Nie der rohe Rumpf: Was immer unter der
     * Adresse antwortet, landet sonst in Meldung und Log.
     */
    private static function antwortFehler(int $status, string $rumpf): string
    {
        $daten = json_decode($rumpf, true);
        $text = is_array($daten) && is_string($daten['error'] ?? null) ? trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $daten['error'])) : '';
        return 'InfluxDB antwortet mit ' . $status . ($text !== '' ? ': ' . mb_substr($text, 0, 200) : '.');
    }

    /** Erreichbar und die Datenbank vorhanden? */
    public static function pruefen(): array
    {
        $fehler = null;
        $serien = self::abfragen('SHOW MEASUREMENTS LIMIT 50', $fehler);
        if ($serien === null) {
            return ['ok' => false, 'message' => (string) $fehler];
        }
        $messungen = [];
        foreach ($serien as $serie) {
            foreach ($serie['values'] as $zeile) {
                $messungen[] = (string) $zeile[0];
            }
        }
        return [
            'ok'      => true,
            'message' => 'InfluxDB erreichbar (' . self::url() . ', Datenbank „' . self::datenbank() . '“). '
                . ($messungen === [] ? 'Noch keine Messwerte.' : 'Messungen: ' . implode(', ', $messungen) . '.'),
        ];
    }

    // ------------------------------------------------------------ Hilfen

    /** Ein Wert in einer InfluxQL-Zeichenkette ('…'). */
    public static function text(string $wert): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $wert) . "'";
    }

    /** Ein Bezeichner ("…"). */
    public static function name(string $wert): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $wert) . '"';
    }

    /** Zeitbedingung in Millisekunden. */
    public static function zeit(int $vonSek, int $bisSek): string
    {
        return sprintf('time >= %dms AND time < %dms', $vonSek * 1000, $bisSek * 1000);
    }

    /**
     * Tag/Feldschlüssel für Line Protocol escapen.
     *
     * Ein Zeilenumbruch beendet im Line Protocol den Punkt – ein Name mit
     * Umbruch schriebe sonst beliebige weitere Punkte (etwa Messwerte eines
     * fremden Geräts). Umbrüche werden wie in Node-RED zu Leerzeichen.
     */
    public static function lpSchluessel(string $wert): string
    {
        return str_replace([',', ' ', '='], ['\\,', '\\ ', '\\='], str_replace(["\r", "\n"], ' ', $wert));
    }

    /**
     * Eine normalisierte Nachricht in Zeilen übersetzen – dieselbe Regel,
     * die Node-RED anwendet: Zahlen immer als Float, Texte als String.
     *
     * @param array{ts?:int,typ?:string,phasen?:array,zustand?:array} $nachricht
     * @return list<string>
     */
    public static function zeilen(string $geraet, string $kanal, array $nachricht): array
    {
        $ts = (int) ($nachricht['ts'] ?? (int) round(microtime(true) * 1000));
        $typ = (string) ($nachricht['typ'] ?? 'modbus');
        $tags = 'geraet=' . self::lpSchluessel($geraet) . ',kanal=' . self::lpSchluessel($kanal);
        $zeilen = [];
        foreach ((array) ($nachricht['phasen'] ?? []) as $phase => $werte) {
            $felder = self::felder((array) $werte);
            if ($felder !== '') {
                $zeilen[] = 'strom,' . $tags . ',phase=' . self::lpSchluessel((string) $phase) . ',typ=' . self::lpSchluessel($typ) . ' ' . $felder . ' ' . $ts;
            }
        }
        $felder = self::felder((array) ($nachricht['zustand'] ?? []));
        if ($felder !== '') {
            $zeilen[] = 'zustand,' . $tags . ',typ=' . self::lpSchluessel($typ) . ' ' . $felder . ' ' . $ts;
        }
        return $zeilen;
    }

    private static function felder(array $werte): string
    {
        $teile = [];
        foreach ($werte as $schluessel => $wert) {
            if (is_bool($wert)) {
                $wert = $wert ? 1.0 : 0.0;
            }
            if (is_int($wert) || is_float($wert)) {
                if (!is_finite((float) $wert)) {
                    continue;
                }
                $zahl = (float) $wert;
                // Immer mit Dezimalpunkt: sonst hält Influx eine 1 für eine
                // Ganzzahl, und die nächste 1.5 scheitert am Feldtyp.
                $text = rtrim(rtrim(sprintf('%.6F', $zahl), '0'), '.');
                if (!str_contains($text, '.')) {
                    $text .= '.0';
                }
                $teile[] = self::lpSchluessel((string) $schluessel) . '=' . $text;
            } elseif (is_string($wert) && $wert !== '') {
                $teile[] = self::lpSchluessel((string) $schluessel) . '="' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '], $wert) . '"';
            }
        }
        return implode(',', $teile);
    }
}
