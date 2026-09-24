<?php
declare(strict_types=1);

namespace App\Messung;

use App\Auth\Zugriff;
use App\Support\Config;
use App\Support\Zeit;

/**
 * Messwerte lesen – immer durch das Zeitfenster des Benutzers.
 *
 * Die Oberfläche fragt hier und nirgends sonst. Jede Methode bekommt das
 * Datenfenster (Zugriff::datenFenster()) und schneidet den gewünschten
 * Zeitraum darauf zu, **bevor** die InfluxDB gefragt wird. Was außerhalb
 * liegt, wird nicht gelesen – also kann es auch nicht versehentlich in einer
 * Antwort, einem CSV-Export oder einem Diagramm-Tooltip landen.
 *
 * Im Demo-Modus antwortet Demo statt der InfluxDB, mit denselben Formen.
 */
final class Messdaten
{
    /** Felder der Messung „strom“ mit Beschriftung und Einheit. */
    public const FELDER = [
        'p'  => ['Wirkleistung', 'W'],
        'u'  => ['Spannung', 'V'],
        'i'  => ['Strom', 'A'],
        's'  => ['Scheinleistung', 'VA'],
        'q'  => ['Blindleistung', 'var'],
        'pf' => ['Leistungsfaktor', ''],
        'f'  => ['Frequenz', 'Hz'],
        'e'  => ['Energiezähler', 'Wh'],
    ];

    /** Bekannte Zustandsfelder – alles andere erscheint mit seinem Namen. */
    public const ZUSTAND = [
        'soc'             => ['Ladezustand', '%'],
        'u'               => ['Spannung', 'V'],
        'i'               => ['Strom', 'A'],
        'p'               => ['Leistung', 'W'],
        'schalter'        => ['Schalter', ''],
        'temperatur'      => ['Temperatur', '°C'],
        'drehzahl'        => ['Drehzahl', '1/min'],
        'oeldruck_bar'    => ['Öldruck', 'bar'],
        'kuehlmittel_c'   => ['Kühlmittel', '°C'],
        'tank_prozent'    => ['Tank', '%'],
        'u_batterie'      => ['Starterbatterie', 'V'],
        'betriebsstunden' => ['Betriebsstunden', 'h'],
        'starts'          => ['Starts', ''],
        'online'          => ['online', ''],
        'restlaufzeit_s'  => ['Restlaufzeit', 's'],
    ];

    private static function demo(): bool
    {
        return Config::isDemo();
    }

    // ------------------------------------------------------------ Live

    /**
     * Die letzten Werte je Gerät/Kanal.
     *
     * @param list<array{0:string,1:string}> $paare [geraet, kanal]
     * @return array<string,array{zeit:?int,phasen:array<string,array<string,float>>,zustand:array<string,mixed>}> Schlüssel „geraet|kanal“
     */
    public static function letzte(array $paare, ?string &$fehler = null): array
    {
        $paare = array_values(array_unique(array_filter($paare, static fn ($p) => $p[0] !== ''), SORT_REGULAR));
        if ($paare === []) {
            return [];
        }
        if (self::demo()) {
            return Demo::letzte($paare);
        }
        $geraete = array_values(array_unique(array_map(static fn ($p) => $p[0], $paare)));
        $filter = '"geraet" =~ /^(' . implode('|', array_map([self::class, 'regex'], $geraete)) . ')$/';
        $q = 'SELECT last(*) FROM "strom" WHERE time > now() - 15m AND ' . $filter . ' GROUP BY "geraet","kanal","phase";'
           . 'SELECT last(*) FROM "zustand" WHERE time > now() - 15m AND ' . $filter . ' GROUP BY "geraet","kanal";'
           . 'SELECT * FROM "strom" WHERE time > now() - 15m AND ' . $filter . ' GROUP BY "geraet","kanal","phase" ORDER BY time DESC LIMIT 1;'
           . 'SELECT * FROM "zustand" WHERE time > now() - 15m AND ' . $filter . ' GROUP BY "geraet","kanal" ORDER BY time DESC LIMIT 1';
        $alle = Influx::abfragenAlle($q, $fehler);
        if ($alle === null) {
            return [];
        }
        $gesucht = [];
        foreach ($paare as [$g, $k]) {
            $gesucht[$g . '|' . $k] = true;
        }
        $out = [];
        $feld = static fn (string $spalte): string => str_starts_with($spalte, 'last_') ? substr($spalte, 5) : $spalte;
        foreach ([0 => 'strom', 1 => 'zustand'] as $i => $art) {
            foreach ($alle[$i] ?? [] as $serie) {
                $schluessel = ($serie['tags']['geraet'] ?? '') . '|' . ($serie['tags']['kanal'] ?? '');
                if (!isset($gesucht[$schluessel])) {
                    continue;
                }
                $out[$schluessel] ??= ['zeit' => null, 'phasen' => [], 'zustand' => []];
                $zeile = $serie['values'][0] ?? [];
                foreach ($serie['columns'] as $j => $spalte) {
                    if ($spalte === 'time' || !isset($zeile[$j]) || $zeile[$j] === null) {
                        continue;
                    }
                    if ($art === 'strom') {
                        $out[$schluessel]['phasen'][$serie['tags']['phase'] ?? 'sum'][$feld($spalte)] = is_numeric($zeile[$j]) ? (float) $zeile[$j] : $zeile[$j];
                    } else {
                        $out[$schluessel]['zustand'][$feld($spalte)] = is_numeric($zeile[$j]) ? (float) $zeile[$j] : $zeile[$j];
                    }
                }
            }
        }
        // Die echte Zeit des jüngsten Punkts – last(*) über mehrere Felder
        // liefert nur den Anfang des Abfragezeitraums.
        foreach ([2, 3] as $i) {
            foreach ($alle[$i] ?? [] as $serie) {
                $schluessel = ($serie['tags']['geraet'] ?? '') . '|' . ($serie['tags']['kanal'] ?? '');
                if (isset($out[$schluessel]) && isset($serie['values'][0][0])) {
                    $out[$schluessel]['zeit'] = max((int) ($out[$schluessel]['zeit'] ?? 0), (int) $serie['values'][0][0]);
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ Verläufe

    /** Sinnvolle Auflösung für einen Zeitraum (Sekunden je Punkt). */
    public static function schritt(int $von, int $bis): int
    {
        $spanne = max(1, $bis - $von);
        return match (true) {
            $spanne <= 2 * 3600     => 10,
            $spanne <= 12 * 3600    => 30,
            $spanne <= 36 * 3600    => 60,
            $spanne <= 8 * 86400    => 300,
            $spanne <= 35 * 86400   => 1800,
            $spanne <= 190 * 86400  => 3 * 3600,
            default                 => 86400,
        };
    }

    /**
     * Ein Verlauf, zugeschnitten auf das Datenfenster.
     *
     * @param list<string> $phasen  bei „strom“: L1, L2, L3, N, sum; bei „zustand“ leer
     * @param list<array{0:?int,1:?int}>|null $fenster
     * @return array{zeiten:list<int>,serien:array<string,list<?float>>,schritt:int,teile:list<array{0:int,1:int}>}
     */
    public static function reihe(
        string $messung,
        string $geraet,
        string $kanal,
        string $feld,
        array $phasen,
        int $von,
        int $bis,
        ?array $fenster,
        ?int $schritt = null,
        string $agg = 'mean',
        ?string &$fehler = null
    ): array {
        $bis = min($bis, time());
        $schritt ??= self::schritt($von, $bis);
        $teile = Zugriff::begrenzen($fenster, $von, $bis);
        $punkte = [];
        foreach ($teile as [$a, $b]) {
            $roh = self::demo()
                ? Demo::reihe($messung, $geraet, $kanal, $feld, $phasen, $a, $b, $schritt, $agg)
                : self::influxReihe($messung, $geraet, $kanal, $feld, $phasen, $a, $b, $schritt, $agg, $fehler);
            foreach ($roh as $serie => $werte) {
                foreach ($werte as [$ms, $wert]) {
                    $punkte[$serie][$ms] = $wert;
                }
                // Lücke zwischen zwei freigegebenen Zeiträumen: ein leerer
                // Punkt, damit das Diagramm nicht über die Sperre hinweg
                // eine Linie zieht.
                $punkte[$serie][$b * 1000 + 1] = null;
            }
        }
        $zeiten = [];
        foreach ($punkte as $werte) {
            foreach (array_keys($werte) as $ms) {
                $zeiten[$ms] = true;
            }
        }
        $zeiten = array_keys($zeiten);
        sort($zeiten);
        $serien = [];
        foreach ($punkte as $name => $werte) {
            $serien[$name] = array_map(static fn ($ms) => $werte[$ms] ?? null, $zeiten);
        }
        return ['zeiten' => $zeiten, 'serien' => $serien, 'schritt' => $schritt, 'teile' => $teile];
    }

    /** @return array<string,list<array{0:int,1:?float}>> */
    private static function influxReihe(string $messung, string $geraet, string $kanal, string $feld, array $phasen, int $von, int $bis, int $schritt, string $agg, ?string &$fehler): array
    {
        $agg = in_array($agg, ['mean', 'max', 'min', 'last'], true) ? $agg : 'mean';
        $bedingung = '"geraet" = ' . Influx::text($geraet) . ' AND "kanal" = ' . Influx::text($kanal) . ' AND ' . Influx::zeit($von, $bis);
        $gruppe = 'GROUP BY time(' . $schritt . 's)';
        if ($messung === 'strom') {
            $phasen = array_values(array_intersect($phasen, ['L1', 'L2', 'L3', 'N', 'sum'])) ?: ['sum'];
            $bedingung .= ' AND "phase" =~ /^(' . implode('|', $phasen) . ')$/';
            $gruppe .= ', "phase"';
        }
        $q = sprintf('SELECT %s(%s) AS "wert" FROM %s WHERE %s %s fill(none)', $agg, Influx::name($feld), $messung === 'strom' ? '"strom"' : '"zustand"', $bedingung, $gruppe);
        $serien = Influx::abfragen($q, $fehler) ?? [];
        $out = [];
        foreach ($serien as $serie) {
            $name = $messung === 'strom' ? (string) ($serie['tags']['phase'] ?? 'sum') : $feld;
            foreach ($serie['values'] as $zeile) {
                $out[$name][] = [(int) $zeile[0], $zeile[1] === null ? null : (float) $zeile[1]];
            }
        }
        return $out;
    }

    /**
     * Energie je Tag in Wh, zugeschnitten auf das Datenfenster.
     *
     * Bevorzugt aus dem Zählerstand (e, Differenz je Tag) – das ist die
     * Zahl, die auch das Gerät anzeigt. Liegt kein Zählerstand vor (etwa bei
     * Werten aus Modbus ohne Zähler), wird die Leistung integriert.
     *
     * @return array<string,float> 'Y-m-d' => Wh
     */
    public static function energieTage(string $geraet, string $kanal, int $von, int $bis, ?array $fenster, ?string &$fehler = null): array
    {
        $bis = min($bis, time());
        $tage = [];
        foreach (Zugriff::begrenzen($fenster, $von, $bis) as [$a, $b]) {
            $teil = self::demo() ? Demo::energieTage($geraet, $kanal, $a, $b) : self::influxEnergie($geraet, $kanal, $a, $b, $fehler);
            foreach ($teil as $tag => $wh) {
                $tage[$tag] = ($tage[$tag] ?? 0.0) + $wh;
            }
        }
        ksort($tage);
        return $tage;
    }

    /** @return array<string,float> */
    private static function influxEnergie(string $geraet, string $kanal, int $von, int $bis, ?string &$fehler): array
    {
        $zone = (string) Config::get('app.timezone', 'Europe/Berlin');
        $wo = '"geraet" = ' . Influx::text($geraet) . ' AND "kanal" = ' . Influx::text($kanal) . " AND \"phase\" = 'sum' AND " . Influx::zeit($von, $bis);
        $tz = " tz('" . str_replace("'", '', $zone) . "')";
        $q = 'SELECT spread("e") AS "wh" FROM "strom" WHERE ' . $wo . ' GROUP BY time(1d)' . $tz . ';'
           . 'SELECT integral("p", 1h) AS "wh" FROM "strom" WHERE ' . $wo . ' GROUP BY time(1d)' . $tz;
        $alle = Influx::abfragenAlle($q, $fehler) ?? [];
        $ausZaehler = self::tagesWerte($alle[0] ?? []);
        if (array_sum($ausZaehler) > 0) {
            return $ausZaehler;
        }
        return self::tagesWerte($alle[1] ?? []);
    }

    /** @return array<string,float> */
    private static function tagesWerte(array $serien): array
    {
        $out = [];
        foreach ($serien as $serie) {
            foreach ($serie['values'] as $zeile) {
                if ($zeile[1] === null) {
                    continue;
                }
                $tag = (new \DateTimeImmutable('@' . intdiv((int) $zeile[0], 1000)))->setTimezone(Zeit::zone())->format('Y-m-d');
                $out[$tag] = ($out[$tag] ?? 0.0) + max(0.0, (float) $zeile[1]);
            }
        }
        return $out;
    }

    /**
     * Kennzahlen eines Zeitraums: Spitzenleistung, Mittel, Energie, höchster
     * Phasenstrom, Spannungsband.
     *
     * @return array<string,?float>
     */
    public static function kennzahlen(string $geraet, string $kanal, int $von, int $bis, ?array $fenster): array
    {
        $leistung = self::reihe('strom', $geraet, $kanal, 'p', ['sum'], $von, $bis, $fenster, max(60, self::schritt($von, $bis)), 'max');
        $mittel = self::reihe('strom', $geraet, $kanal, 'p', ['sum'], $von, $bis, $fenster, max(60, self::schritt($von, $bis)), 'mean');
        $strom = self::reihe('strom', $geraet, $kanal, 'i', ['L1', 'L2', 'L3'], $von, $bis, $fenster, max(60, self::schritt($von, $bis)), 'max');
        $spannungMin = self::reihe('strom', $geraet, $kanal, 'u', ['L1', 'L2', 'L3'], $von, $bis, $fenster, max(60, self::schritt($von, $bis)), 'min');
        $spannungMax = self::reihe('strom', $geraet, $kanal, 'u', ['L1', 'L2', 'L3'], $von, $bis, $fenster, max(60, self::schritt($von, $bis)), 'max');
        $werte = static function (array $r): array {
            $alle = [];
            foreach ($r['serien'] as $s) {
                foreach ($s as $w) {
                    if ($w !== null) {
                        $alle[] = (float) $w;
                    }
                }
            }
            return $alle;
        };
        $p = $werte($leistung);
        $m = $werte($mittel);
        $i = $werte($strom);
        $umin = $werte($spannungMin);
        $umax = $werte($spannungMax);
        $energie = array_sum(self::energieTage($geraet, $kanal, $von, $bis, $fenster));
        return [
            'p_max'  => $p === [] ? null : max($p),
            'p_mittel' => $m === [] ? null : array_sum($m) / count($m),
            'i_max'  => $i === [] ? null : max($i),
            'u_min'  => $umin === [] ? null : min($umin),
            'u_max'  => $umax === [] ? null : max($umax),
            'energie_wh' => $energie > 0 ? $energie : null,
        ];
    }

    // ------------------------------------------------------------ Geräte

    /**
     * Welche Geräte und Kanäle in der InfluxDB auftauchen – die Shelly-
     * Geräte „entdeckt“ die Oberfläche so, ohne dass jemand sie einträgt.
     *
     * @return list<array{geraet:string,kanal:string,typ:string,zuletzt:?int,modell:string,name:string,p:?float}>
     */
    public static function geraete(?string &$fehler = null): array
    {
        if (self::demo()) {
            return Demo::geraete();
        }
        $q = 'SELECT last("p") FROM "strom" WHERE time > now() - 7d AND "phase" = \'sum\' GROUP BY "geraet","kanal","typ";'
           . 'SELECT count(*) FROM "zustand" WHERE time > now() - 1d GROUP BY "geraet","kanal","typ";'
           . 'SELECT last(*) FROM "geraet" GROUP BY "geraet"';
        $alle = Influx::abfragenAlle($q, $fehler);
        if ($alle === null) {
            return [];
        }
        $info = [];
        foreach ($alle[2] ?? [] as $serie) {
            $zeile = $serie['values'][0] ?? [];
            $spalten = array_flip($serie['columns']);
            $feld = static fn (string $name): string => (string) ($zeile[$spalten['last_' . $name] ?? -1] ?? '');
            $info[(string) ($serie['tags']['geraet'] ?? '')] = [
                'modell'     => $feld('modell'),
                'name'       => $feld('name'),
                // Node-RED merkt sich, unter welchem MQTT-Präfix der Shelly
                // Befehle annimmt (Gen2: <präfix>/rpc, Gen1: shellies/<id>/…).
                'praefix'    => $feld('praefix'),
                'generation' => (int) (float) $feld('generation'),
            ];
        }
        $out = [];
        foreach ([0, 1] as $i) {
            foreach ($alle[$i] ?? [] as $serie) {
                $g = (string) ($serie['tags']['geraet'] ?? '');
                $k = (string) ($serie['tags']['kanal'] ?? '');
                $schluessel = $g . '|' . $k;
                if (isset($out[$schluessel])) {
                    continue;
                }
                $zeile = $serie['values'][0] ?? [];
                $out[$schluessel] = [
                    'geraet'  => $g,
                    'kanal'   => $k,
                    'typ'     => (string) ($serie['tags']['typ'] ?? ''),
                    'zuletzt' => $i === 0 && isset($zeile[0]) ? (int) $zeile[0] : null,
                    'modell'  => $info[$g]['modell'] ?? '',
                    'name'    => $info[$g]['name'] ?? '',
                    'praefix' => $info[$g]['praefix'] ?? '',
                    'generation' => $info[$g]['generation'] ?? 0,
                    'p'       => $i === 0 && isset($zeile[1]) ? (float) $zeile[1] : null,
                ];
            }
        }
        $out = array_values($out);
        usort($out, static fn ($a, $b) => [$a['geraet'], $a['kanal']] <=> [$b['geraet'], $b['kanal']]);
        return $out;
    }

    /** Herzschlag von Node-RED: wann zuletzt geschrieben, wie viele Fehler. */
    public static function pipeline(?string &$fehler = null): ?array
    {
        if (self::demo()) {
            return ['zeit' => time() * 1000, 'geschrieben' => 123456.0, 'fehler' => 0.0, 'puffer' => 0.0];
        }
        $serien = Influx::abfragen('SELECT * FROM "pipeline" WHERE time > now() - 1h ORDER BY time DESC LIMIT 1', $fehler);
        $serie = $serien[0] ?? null;
        if ($serie === null || !isset($serie['values'][0])) {
            return null;
        }
        $zeile = array_combine($serie['columns'], $serie['values'][0]);
        return ['zeit' => (int) $zeile['time'], 'geschrieben' => (float) ($zeile['geschrieben'] ?? 0), 'fehler' => (float) ($zeile['fehler'] ?? 0), 'puffer' => (float) ($zeile['puffer'] ?? 0)];
    }

    private static function regex(string $wert): string
    {
        return preg_quote($wert, '/');
    }

    /** Beschriftung eines Feldes. */
    public static function feldName(string $feld): string
    {
        return self::FELDER[$feld][0] ?? self::ZUSTAND[$feld][0] ?? $feld;
    }

    public static function einheit(string $feld): string
    {
        return self::FELDER[$feld][1] ?? self::ZUSTAND[$feld][1] ?? '';
    }

    /** 12,3 kW, 230 V, 48 % … */
    public static function format(?float $wert, string $einheit, int $stellen = 1): string
    {
        if ($wert === null) {
            return '–';
        }
        if (in_array($einheit, ['W', 'VA', 'var', 'Wh'], true) && abs($wert) >= 1000) {
            $wert /= 1000;
            $einheit = 'k' . $einheit;
            if (abs($wert) >= 1000) {
                $wert /= 1000;
                $einheit = 'M' . substr($einheit, 1);
            }
        }
        return number_format($wert, $stellen, ',', '.') . ($einheit !== '' ? ' ' . $einheit : '');
    }
}
