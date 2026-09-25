<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Zugriff;
use App\Support\Config;
use App\Support\Database;

/**
 * Materialliste für den Aufbau – für einen Bereich oder eine ganze Etage.
 *
 * Gezählt wird, was mitgebracht werden muss: mobile Anlagen (nach Typ),
 * mobile Kabel in üblichen Stücklängen und Kabelbrücken. Fest installierte
 * Anlagen und fest verlegte Kabel sind vor Ort und stehen nur zur
 * Orientierung dabei.
 *
 * Stückelung: Aus der Länge im Plan (bzw. der eingetragenen Länge) plus
 * Reserve für Durchhang und den Weg zur Dose werden Stücke der üblichen
 * Längen 5, 10, 20, 25 und 50 m – möglichst wenige Stücke (jede Kupplung ist
 * eine Fehlerquelle), ohne viel Überlänge. Für 63 A, 125 A und Powerlock
 * gibt es auch 75 m und 100 m; die sind schwer, teuer und selten im Lager
 * und kommen nur dort zum Einsatz, wo sie mindestens zwei Stücke sparen –
 * praktisch erst bei Strecken ab rund 175 m.
 */
final class MaterialService
{
    /** Übliche Stücklängen in Metern. */
    public const LAENGEN = [50, 25, 20, 10, 5];

    /** Zusätzlich für 63 A, 125 A und Powerlock – nur selten. */
    public const LAENGEN_SELTEN = [100, 75];

    /** So viele Stücke muss ein 75/100-m-Stück mindestens sparen. */
    public const SELTEN_AB_ERSPARNIS = 2;

    /** Überlänge in Metern, die ein zusätzliches Stück (eine Kupplung) aufwiegt. */
    private const UEBERLAENGE_JE_STUECK = 15.0;

    /** Reserve in Prozent auf die Länge im Plan (Einstellung material.reserve). */
    public static function reserve(): float
    {
        $wert = Config::get('material.reserve', 10);
        return max(0.0, min(100.0, is_numeric($wert) ? (float) $wert : 10.0));
    }

    /**
     * Eine Länge in Stücke teilen.
     *
     * @return list<int> Stücklängen, längste zuerst
     */
    public static function stueckeln(float $meter, bool $schwer): array
    {
        $ziel = max(1, (int) ceil($meter - 1e-9));
        $standard = self::bestesSet($ziel, self::LAENGEN);
        if (!$schwer) {
            return $standard;
        }
        $mitSelten = self::bestesSet($ziel, array_merge(self::LAENGEN_SELTEN, self::LAENGEN));
        return count($standard) - count($mitSelten) >= self::SELTEN_AB_ERSPARNIS ? $mitSelten : $standard;
    }

    /**
     * Die günstigste Kombination: Stücke plus Überlänge (15 m Überlänge
     * wiegen ein Stück auf); bei Gleichstand weniger Überlänge.
     *
     * @param list<int> $laengen
     * @return list<int>
     */
    private static function bestesSet(int $ziel, array $laengen): array
    {
        $grenze = $ziel + max($laengen);
        // wenigste[t] = wenigste Stücke, die genau t Meter ergeben
        $wenigste = array_fill(0, $grenze + 1, null);
        $wenigste[0] = [];
        for ($t = 1; $t <= $grenze; $t++) {
            foreach ($laengen as $l) {
                if ($t >= $l && $wenigste[$t - $l] !== null && ($wenigste[$t] === null || count($wenigste[$t - $l]) + 1 < count($wenigste[$t]))) {
                    $wenigste[$t] = array_merge($wenigste[$t - $l], [$l]);
                }
            }
        }
        $bestes = null;
        $besteKosten = INF;
        for ($t = $ziel; $t <= $grenze; $t++) {
            if ($wenigste[$t] === null) {
                continue;
            }
            $kosten = count($wenigste[$t]) + ($t - $ziel) / self::UEBERLAENGE_JE_STUECK;
            if ($kosten < $besteKosten - 1e-9) {
                $bestes = $wenigste[$t];
                $besteKosten = $kosten;
            }
        }
        $bestes ??= [];
        rsort($bestes);
        return $bestes;
    }

    /** Schwere Kabel (63 A, 125 A, Powerlock) dürfen 75/100-m-Stücke bekommen. */
    public static function schwer(?float $steckerStrom, ?string $steckerName, ?float $querschnitt, string $kabeltyp): bool
    {
        if ($steckerStrom !== null && $steckerStrom > 0) {
            return $steckerStrom >= 63 || stripos((string) $steckerName, 'powerlock') !== false;
        }
        return stripos($kabeltyp, 'powerlock') !== false || ($querschnitt !== null && $querschnitt >= 16);
    }

    // ------------------------------------------------------------ Listen

    /** Material einer ganzen Etage (was der Benutzer sehen darf). */
    public static function fuerEtage(int $floorId): array
    {
        $objekte = Zugriff::filtern(AnlagenService::liste(['floor_id' => $floorId]));
        return self::berechnen($objekte, LeitungService::fuerEtage($floorId));
    }

    /**
     * Material eines Bereichs: seine Anlagen, alle Kabel an ihnen (auch das
     * Zuleitungskabel vom Aggregat außerhalb), die Kabel an seinen gemessenen
     * Abgängen und die Kabelbrücken über diesen Kabeln.
     */
    public static function fuerBereich(array $bereich, ?array $aufbau = null): array
    {
        $aufbau ??= BereichService::aufbau($bereich);
        $objekte = [];
        $abgaenge = [];
        foreach ($aufbau as $e) {
            if (!$e['sichtbar']) {
                continue;
            }
            if ($e['typ'] === 'objekt') {
                $objekte[(int) $e['objekt']['id']] = $e['objekt'];
            } elseif ($e['messpunkt']['anschluss_id'] !== null) {
                $abgaenge[(int) $e['messpunkt']['anschluss_id']] = true;
            }
        }
        $kabel = [];
        $bruecken = [];
        foreach (Database::select('SELECT id FROM floors WHERE site_id = ?', [(int) $bereich['site_id']]) as $f) {
            $massstab = PlanMassstabService::fuerEtage((int) $f['id']);
            $etageKabel = [];
            foreach (LeitungService::fuerEtage((int) $f['id']) as $l) {
                if ($l['art'] === 'kabelbruecke') {
                    $bruecken[] = [$l, $massstab];
                    continue;
                }
                if ($l['art'] !== 'kabel') {
                    continue;
                }
                $dran = isset($objekte[(int) $l['von_objekt_id']]) || isset($objekte[(int) $l['zu_objekt_id']])
                    || ($l['von_anschluss_id'] !== null && isset($abgaenge[(int) $l['von_anschluss_id']]));
                if ($dran) {
                    $kabel[(int) $l['id']] = $l;
                    $etageKabel[] = $l;
                }
            }
            // Kabelbrücken dieser Etage, die über einem Kabel des Bereichs liegen.
            foreach ($bruecken as $i => [$b, $m]) {
                if ((int) $b['floor_id'] !== (int) $f['id']) {
                    continue;
                }
                foreach ($etageKabel as $l) {
                    if (self::nahe($b['punkte'], $l['punkte'], $massstab)) {
                        $kabel[(int) $b['id']] = $b;
                        break;
                    }
                }
                unset($bruecken[$i]);
            }
        }
        return self::berechnen(array_values($objekte), array_values($kabel));
    }

    /**
     * Die Liste aus Anlagen und Leitungen.
     *
     * @param list<array<string,mixed>> $objekte  aufbereitet (AnlagenService)
     * @param list<array<string,mixed>> $leitungen aufbereitet (LeitungService)
     * @return array<string,mixed>
     */
    public static function berechnen(array $objekte, array $leitungen): array
    {
        $reserve = self::reserve();
        $anlagen = [];
        $vorhanden = [];
        foreach ($objekte as $o) {
            $typ = trim((string) $o['modell']) !== '' ? (string) $o['modell'] : 'ohne Typangabe';
            $schluessel = $o['art'] . '|' . $typ;
            $ziel = (int) $o['fest'] === 1 ? 'vorhanden' : 'anlagen';
            ${$ziel}[$schluessel] ??= ['titel' => AnlagenService::ARTEN[$o['art']]['name'] . ' · ' . $typ, 'art' => $o['art'], 'anzahl' => 0, 'namen' => []];
            ${$ziel}[$schluessel]['anzahl']++;
            ${$ziel}[$schluessel]['namen'][] = (string) $o['name'];
        }
        ksort($anlagen);
        ksort($vorhanden);

        $gruppen = [];
        $einzeln = [];
        $ohneLaenge = [];
        $festeKabel = 0;
        $bruecken = [];
        foreach ($leitungen as $l) {
            if ($l['art'] === 'kabelbruecke') {
                $modell = trim((string) $l['modell']) !== '' ? (string) $l['modell'] : 'Kabelbrücke';
                $bruecken[$modell] = ($bruecken[$modell] ?? 0) + max(1, (int) $l['anzahl']);
                continue;
            }
            if ($l['art'] !== 'kabel') {
                continue;
            }
            if ((int) $l['fest'] === 1) {
                $festeKabel++;
                continue;
            }
            $stecker = $l['von_typ'] ?? $l['zu_typ'] ?? null;
            $strom = $l['von_strom_a'] ?? $l['zu_strom_a'] ?? null;
            $querschnitt = $l['querschnitt_mm2'] !== null ? (float) $l['querschnitt_mm2'] : null;
            $kabeltyp = (string) $l['kabeltyp'];
            $einleiter = stripos($kabeltyp, 'einleiter') !== false;
            $bezeichnung = trim($kabeltyp . ' ' . ($l['adern'] && !$einleiter ? (int) $l['adern'] . 'G' : '')
                . ($querschnitt !== null ? str_replace('.', ',', (string) $querschnitt) . ' mm²' : ''));
            $titel = ($stecker !== null ? $stecker : 'ohne Stecker') . ($bezeichnung !== '' ? ' · ' . $bezeichnung : '');
            $name = trim((string) $l['name']) !== '' ? (string) $l['name'] : (($l['von_name'] ?? '?') . ' → ' . ($l['zu_name'] ?? 'offenes Ende'));
            if ($l['laenge'] === null) {
                $ohneLaenge[] = ['name' => $name, 'titel' => $titel];
                continue;
            }
            $benoetigt = (float) $l['laenge'] * (1 + $reserve / 100);
            $schwer = self::schwer($strom !== null ? (float) $strom : null, $stecker, $querschnitt, $kabeltyp);
            $stuecke = self::stueckeln($benoetigt, $schwer);
            // Parallel gelegte Kabel und Powerlock-Einleiter (fünf Leiter je Strang) zählen mehrfach.
            $faktor = max(1, (int) $l['anzahl']) * ($einleiter ? max(1, (int) ($l['adern'] ?: 5)) : 1);
            $gruppen[$titel] ??= [
                'titel' => $titel, 'strom' => $strom !== null ? (float) $strom : ($querschnitt ?? 0.0), 'schwer' => $schwer,
                'einleiter' => $einleiter, 'kabel' => 0, 'stuecke' => [], 'meter_plan' => 0.0, 'kupplungen' => 0,
            ];
            $g = &$gruppen[$titel];
            $g['kabel'] += max(1, (int) $l['anzahl']);
            $g['meter_plan'] += (float) $l['laenge'] * max(1, (int) $l['anzahl']);
            $g['kupplungen'] += (count($stuecke) - 1) * $faktor;
            foreach ($stuecke as $s) {
                $g['stuecke'][$s] = ($g['stuecke'][$s] ?? 0) + $faktor;
            }
            unset($g);
            $einzeln[] = [
                'name' => $name, 'titel' => $titel, 'laenge' => round((float) $l['laenge'], 1),
                'benoetigt' => (int) ceil($benoetigt - 1e-9), 'stuecke' => $stuecke, 'faktor' => $faktor,
                'gemessen' => $l['laenge_m'] !== null,
            ];
        }
        foreach ($gruppen as &$g) {
            krsort($g['stuecke']);
            $g['meter_stuecke'] = array_sum(array_map(static fn ($l, $n) => $l * $n, array_keys($g['stuecke']), $g['stuecke']));
        }
        unset($g);
        // Die stärksten Kabel zuerst.
        uasort($gruppen, static fn ($a, $b) => [$b['strom'], $a['titel']] <=> [$a['strom'], $b['titel']]);
        ksort($bruecken);
        usort($einzeln, static fn ($a, $b) => [$a['titel'], $a['name']] <=> [$b['titel'], $b['name']]);

        return [
            'reserve'     => $reserve,
            'anlagen'     => array_values($anlagen),
            'vorhanden'   => array_values($vorhanden),
            'kabel'       => array_values($gruppen),
            'kabel_einzeln' => $einzeln,
            'ohne_laenge' => $ohneLaenge,
            'feste_kabel' => $festeKabel,
            'bruecken'    => $bruecken,
        ];
    }

    /** Liegen zwei Linienzüge nah beieinander (etwa eine Kabelbrücke über einem Kabel)? */
    private static function nahe(array $a, array $b, ?array $massstab): bool
    {
        $v = $massstab['verhaeltnis'] ?? 1.0;
        // Toleranz: 5 m mit Maßstab, sonst 2 % der Planbreite.
        $toleranz = 2.0;
        if ($massstab !== null && $massstab['meter'] > 0) {
            $toleranz = PlanMassstabService::abstand($massstab['a'], $massstab['b'], $v) / $massstab['meter'] * 5.0;
        }
        $punkt = static fn ($p) => [(float) ($p['x'] ?? $p[0]), (float) ($p['y'] ?? $p[1]) * $v];
        $a = array_map($punkt, $a);
        $b = array_map($punkt, $b);
        for ($i = 1, $na = count($a); $i < $na; $i++) {
            for ($j = 1, $nb = count($b); $j < $nb; $j++) {
                if (self::streckenAbstand($a[$i - 1], $a[$i], $b[$j - 1], $b[$j]) <= $toleranz) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function streckenAbstand(array $p1, array $p2, array $q1, array $q2): float
    {
        $schneiden = static function ($a, $b, $c, $d): bool {
            $kreuz = static fn ($o, $p, $q) => ($p[0] - $o[0]) * ($q[1] - $o[1]) - ($p[1] - $o[1]) * ($q[0] - $o[0]);
            $d1 = $kreuz($c, $d, $a);
            $d2 = $kreuz($c, $d, $b);
            $d3 = $kreuz($a, $b, $c);
            $d4 = $kreuz($a, $b, $d);
            return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
        };
        if ($schneiden($p1, $p2, $q1, $q2)) {
            return 0.0;
        }
        $punktStrecke = static function ($p, $a, $b): float {
            $dx = $b[0] - $a[0];
            $dy = $b[1] - $a[1];
            $laenge2 = $dx * $dx + $dy * $dy;
            $t = $laenge2 > 0 ? max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $laenge2)) : 0.0;
            return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
        };
        return min($punktStrecke($p1, $q1, $q2), $punktStrecke($p2, $q1, $q2), $punktStrecke($q1, $p1, $p2), $punktStrecke($q2, $p1, $p2));
    }
}
