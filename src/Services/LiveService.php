<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Zugriff;
use App\Messung\Datenquellen;
use App\Messung\Steuerung;
use App\Support\Config;
use App\Support\Database;

/**
 * Livewerte je Anlage – für Übersicht, Karte und Anlagenseite gleich.
 *
 * Aus den Messpunkten einer Anlage wird eine Zusammenfassung: Leistung,
 * Phasenströme und -spannungen der Einspeisung, Auslastung gegen den
 * Nennstrom, eine Ampelstufe, dazu Zustandswerte (Ladezustand, Tank,
 * Motor) aus den übrigen Messpunkten. Wer für eine Anlage jetzt keine
 * Daten sehen darf (Datenzeitraum vorbei), bekommt „keine Freigabe“ statt
 * Zahlen.
 */
final class LiveService
{
    /** Ab wie vielen Sekunden ohne neuen Wert ein Messgerät als stumm gilt. */
    public const STUMM_NACH = 120;

    public static function grenzeWarnung(): float
    {
        return (float) (Config::get('grenzen.warnung', 80) ?: 80);
    }

    public static function grenzeAlarm(): float
    {
        return (float) (Config::get('grenzen.alarm', 95) ?: 95);
    }

    /**
     * @param list<array<string,mixed>> $objekte (bereits auf Sichtbarkeit gefiltert)
     * @return array<int,array<string,mixed>> objekt_id => Zusammenfassung
     */
    public static function fuerObjekte(array $objekte): array
    {
        if ($objekte === []) {
            return [];
        }
        $nachId = [];
        foreach ($objekte as $o) {
            $nachId[(int) $o['id']] = $o;
        }
        $ids = array_keys($nachId);
        $messpunkte = Database::select(
            'SELECT m.*, a.phase AS anschluss_phase FROM messpunkte m LEFT JOIN anschluesse a ON a.id = m.anschluss_id
             WHERE m.objekt_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND m.gueltig_bis IS NULL ORDER BY m.id',
            $ids
        );
        $live = MesspunktService::live($messpunkte, $nachId);
        $jeObjekt = [];
        foreach ($messpunkte as $m) {
            $jeObjekt[(int) $m['objekt_id']][] = $m;
        }

        // Nennströme brauchen die Eingänge – in einem Rutsch lesen.
        $eingaenge = [];
        foreach (Database::select(
            "SELECT a.objekt_id, a.richtung, t.strom_a FROM anschluesse a JOIN steckertypen t ON t.id = a.steckertyp_id
             WHERE a.objekt_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) as $a) {
            $eingaenge[(int) $a['objekt_id']][] = $a;
        }

        $out = [];
        foreach ($nachId as $id => $objekt) {
            $mps = $jeObjekt[$id] ?? [];
            $out[$id] = self::zusammenfassen($objekt, $mps, $live, $eingaenge[$id] ?? []);
        }
        return $out;
    }

    private static function zusammenfassen(array $objekt, array $mps, array $live, array $anschluesse): array
    {
        $erg = [
            'stufe' => 'keine', 'p' => null, 'i' => [], 'u' => [], 'f' => null, 'auslastung' => null,
            'zustand' => [], 'alter_s' => null, 'messpunkte' => [], 'hinweis' => '', 'phasen' => [],
        ];
        if ($mps === []) {
            $erg['hinweis'] = 'kein Messgerät zugeordnet';
            return $erg;
        }
        // Ein Tank hat keine Leistung – im Tank misst ein Tanksensor den Füllstand.
        if (!AnlagenService::elektrisch((string) $objekt['art'])) {
            $erg['hinweis'] = 'Tanksensor eingebaut';
            return $erg;
        }
        if (!Zugriff::liveErlaubt(Zugriff::datenFenster($objekt))) {
            $erg['stufe'] = 'gesperrt';
            $erg['hinweis'] = 'Livewerte außerhalb des freigegebenen Zeitraums';
            return $erg;
        }
        $jetztMs = time() * 1000;
        foreach ($mps as $m) {
            $w = $live[(int) $m['id']] ?? null;
            $erg['messpunkte'][(int) $m['id']] = self::messpunktKurz($m, $w);
            if ($w === null) {
                continue;
            }
            foreach ($w['zustand'] as $k => $v) {
                $erg['zustand'][$k] ??= $v;
            }
            if ($w['zeit'] !== null) {
                $alter = max(0, intdiv($jetztMs - (int) $w['zeit'], 1000));
                $erg['alter_s'] = $erg['alter_s'] === null ? $alter : min($erg['alter_s'], $alter);
            }
        }
        $haupt = MesspunktService::haupt($mps);
        $w = $haupt !== null ? ($live[(int) $haupt['id']] ?? null) : null;
        if ($haupt !== null && $haupt['anschluss_id'] !== null) {
            // Kein Messgerät an der Einspeisung, aber an Abgängen: dann ist
            // die Anlage die Summe ihrer gemessenen Abgänge. Einphasige
            // Abgänge zählen auf der Phase, auf die sie gelegt sind.
            $w = self::summeDerAbgaenge($mps, $live);
            $erg['hinweis'] = 'Summe der gemessenen Abgänge';
        }
        if ($w === null) {
            $erg['stufe'] = 'stumm';
            $erg['hinweis'] = 'keine aktuellen Messwerte';
            return $erg;
        }
        foreach (['L1', 'L2', 'L3'] as $l) {
            if (isset($w['phasen'][$l]['i'])) {
                $erg['i'][$l] = round((float) $w['phasen'][$l]['i'], 2);
            }
            if (isset($w['phasen'][$l]['u'])) {
                $erg['u'][$l] = round((float) $w['phasen'][$l]['u'], 1);
            }
        }
        $p = $w['phasen']['sum']['p'] ?? null;
        if ($p === null) {
            $summe = null;
            foreach (['L1', 'L2', 'L3'] as $l) {
                if (isset($w['phasen'][$l]['p'])) {
                    $summe = ($summe ?? 0.0) + (float) $w['phasen'][$l]['p'];
                }
            }
            $p = $summe;
        }
        $erg['p'] = $p !== null ? round((float) $p, 1) : null;
        if ($erg['i'] === [] && isset($w['phasen']['sum']['i'])) {
            $erg['i']['sum'] = round((float) $w['phasen']['sum']['i'], 2);
        }
        if ($erg['u'] === [] && isset($w['phasen']['sum']['u'])) {
            $erg['u']['sum'] = round((float) $w['phasen']['sum']['u'], 1);
        }
        $erg['f'] = isset($w['phasen']['sum']['f']) ? round((float) $w['phasen']['sum']['f'], 2) : null;
        $erg['phasen'] = self::phasenDetail($w, $erg['p']);
        $erg['auslastung'] = MesspunktService::auslastung($w, MesspunktService::nennstrom($objekt, $anschluesse));
        $erg['stufe'] = match (true) {
            $erg['alter_s'] !== null && $erg['alter_s'] > self::STUMM_NACH => 'stumm',
            $erg['auslastung'] === null                                    => 'ok',
            $erg['auslastung'] >= self::grenzeAlarm()                      => 'bad',
            $erg['auslastung'] >= self::grenzeWarnung()                    => 'warn',
            default                                                        => 'ok',
        };
        if ($erg['stufe'] === 'stumm') {
            $erg['hinweis'] = 'letzter Messwert vor ' . self::dauer((int) $erg['alter_s']);
        }
        return $erg;
    }

    /**
     * Die Werte je Phase für die Detailansicht: Spannung, Strom, Wirk-,
     * Blind- und Scheinleistung, cos φ, Frequenz – was das Messgerät liefert.
     *
     * @return array<string,array<string,float>> 'L1'|'L2'|'L3'|'N'|'sum' => Feld => Wert
     */
    public static function phasenDetail(array $w, ?float $pGesamt = null): array
    {
        $stellen = ['u' => 1, 'i' => 2, 'p' => 1, 'q' => 1, 's' => 1, 'pf' => 2, 'f' => 2];
        $out = [];
        foreach (['L1', 'L2', 'L3', 'N', 'sum'] as $phase) {
            foreach ($stellen as $feld => $n) {
                $wert = $w['phasen'][$phase][$feld] ?? null;
                if (is_numeric($wert)) {
                    $out[$phase][$feld] = round((float) $wert, $n);
                }
            }
        }
        if ($pGesamt !== null && !isset($out['sum']['p'])) {
            $out['sum']['p'] = $pGesamt;
        }
        return $out;
    }

    /**
     * Livewerte aus den Messgeräten an Abgängen zusammensetzen.
     *
     * @param list<array<string,mixed>> $mps
     * @param array<int,array<string,mixed>> $live
     */
    private static function summeDerAbgaenge(array $mps, array $live): ?array
    {
        $paare = [];
        foreach ($mps as $m) {
            if ($m['anschluss_id'] !== null && isset($live[(int) $m['id']])) {
                $paare[] = [$m, $live[(int) $m['id']]];
            }
        }
        return self::summe($paare);
    }

    /**
     * Livewerte mehrerer Messgeräte addieren – für die Abgänge einer
     * Verteilung ebenso wie für einen Bereich. Dreiphasige Geräte zählen je
     * Phase, einphasige auf der Phase ihres Abgangs (anschluss_phase), sonst
     * nur in der Summe.
     *
     * @param list<array{0:array<string,mixed>,1:array<string,mixed>}> $paare [Messpunkt, Livewert]
     */
    public static function summe(array $paare): ?array
    {
        $summe = ['zeit' => null, 'phasen' => [], 'zustand' => []];
        $gefunden = false;
        foreach ($paare as [$m, $w]) {
            $gefunden = true;
            $summe['zeit'] = max((int) ($summe['zeit'] ?? 0), (int) ($w['zeit'] ?? 0));
            $dreiphasig = isset($w['phasen']['L1']) || isset($w['phasen']['L2']) || isset($w['phasen']['L3']);
            if ($dreiphasig) {
                foreach (['L1', 'L2', 'L3'] as $l) {
                    foreach (['i', 'p'] as $f) {
                        if (isset($w['phasen'][$l][$f])) {
                            $summe['phasen'][$l][$f] = ($summe['phasen'][$l][$f] ?? 0.0) + (float) $w['phasen'][$l][$f];
                        }
                    }
                    if (isset($w['phasen'][$l]['u'])) {
                        $summe['phasen'][$l]['u'] = (float) $w['phasen'][$l]['u'];
                    }
                }
            } else {
                $phase = in_array((string) ($m['anschluss_phase'] ?? ''), ['L1', 'L2', 'L3'], true) ? (string) $m['anschluss_phase'] : 'sum';
                foreach (['i', 'p'] as $f) {
                    if (isset($w['phasen']['sum'][$f])) {
                        $summe['phasen'][$phase][$f] = ($summe['phasen'][$phase][$f] ?? 0.0) + (float) $w['phasen']['sum'][$f];
                    }
                }
                if (isset($w['phasen']['sum']['u']) && $phase !== 'sum') {
                    $summe['phasen'][$phase]['u'] = (float) $w['phasen']['sum']['u'];
                }
            }
            $p = $w['phasen']['sum']['p'] ?? null;
            if ($p === null && $dreiphasig) {
                $p = array_sum(array_map(static fn ($l) => (float) ($w['phasen'][$l]['p'] ?? 0), ['L1', 'L2', 'L3']));
            }
            $summe['phasen']['sum']['p_gesamt'] = ($summe['phasen']['sum']['p_gesamt'] ?? 0.0) + (float) ($p ?? 0);
        }
        if (!$gefunden) {
            return null;
        }
        $summe['phasen']['sum']['p'] = $summe['phasen']['sum']['p_gesamt'];
        unset($summe['phasen']['sum']['p_gesamt']);
        // Ströme ohne bekannte Phase: nicht auf eine Phase raten, aber als
        // Summe mitgeben – die Auslastung rechnet dann über die Leistung.
        if (isset($summe['phasen']['sum']['i']) && (isset($summe['phasen']['L1']) || isset($summe['phasen']['L2']) || isset($summe['phasen']['L3']))) {
            unset($summe['phasen']['sum']['i']);
        }
        return $summe;
    }

    /** Kurzform eines Messpunkts für Listen und Knöpfe. */
    private static function messpunktKurz(array $m, ?array $w): array
    {
        $p = $w['phasen']['sum']['p'] ?? null;
        if ($p === null && $w !== null) {
            foreach (['L1', 'L2', 'L3'] as $l) {
                if (isset($w['phasen'][$l]['p'])) {
                    $p = ($p ?? 0.0) + (float) $w['phasen'][$l]['p'];
                }
            }
        }
        $i = null;
        if ($w !== null) {
            foreach (['L1', 'L2', 'L3', 'sum'] as $l) {
                if (isset($w['phasen'][$l]['i'])) {
                    $i = max($i ?? 0.0, (float) $w['phasen'][$l]['i']);
                }
            }
        }
        return [
            'id'       => (int) $m['id'],
            'geraet'   => (string) $m['geraet'],
            'kanal'    => (string) $m['kanal'],
            'p'        => $p !== null ? round((float) $p, 1) : null,
            'i_max'    => $i !== null ? round($i, 2) : null,
            'schalter' => isset($w['zustand']['schalter']) ? (int) $w['zustand']['schalter'] : null,
            'soc'      => isset($w['zustand']['soc']) ? (float) $w['zustand']['soc'] : null,
            'online'   => $w !== null,
        ];
    }

    public static function dauer(int $sek): string
    {
        return match (true) {
            $sek < 90    => $sek . ' s',
            $sek < 5400  => (int) round($sek / 60) . ' min',
            $sek < 172800 => (int) round($sek / 3600) . ' h',
            default      => (int) round($sek / 86400) . ' Tagen',
        };
    }

    /**
     * Was der Benutzer an diesen Anlagen schalten darf – für Knöpfe.
     *
     * @param list<array<string,mixed>> $objekte
     * @return list<array{objekt_id:int,objekt:string,quelle_id:int,quelle:string,kanal:string,titel:string,befehle:list<array>}>
     */
    public static function schaltbar(array $objekte): array
    {
        if (!\App\Auth\Auth::can('steuern') || $objekte === []) {
            return [];
        }
        $nachId = [];
        foreach ($objekte as $o) {
            // An einem Tank wird nie geschaltet – auch wenn sein Shelly ein Relais hat.
            if (AnlagenService::elektrisch((string) $o['art'])) {
                $nachId[(int) $o['id']] = $o;
            }
        }
        if ($nachId === []) {
            return [];
        }
        $ids = array_keys($nachId);
        $rows = Database::select(
            'SELECT m.*, d.id AS quelle_id FROM messpunkte m JOIN datenquellen d ON d.geraet = m.geraet
             WHERE m.objekt_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND m.gueltig_bis IS NULL ORDER BY m.objekt_id, m.id',
            $ids
        );
        $out = [];
        $gesehen = [];
        foreach ($rows as $m) {
            $quelle = Datenquellen::find((int) $m['quelle_id']);
            $objekt = $nachId[(int) $m['objekt_id']];
            if ($quelle === null || (int) $quelle['aktiv'] !== 1 && $quelle['art'] !== 'shelly') {
                continue;
            }
            $befehle = array_values(array_filter(
                Steuerung::befehle($quelle, [(string) $m['kanal']]),
                static fn ($b) => $quelle['art'] !== 'shelly' || $b['kanal'] === (string) $m['kanal']
            ));
            $schluessel = $quelle['id'] . '|' . ($quelle['art'] === 'shelly' ? $m['kanal'] : '*') . '|' . $m['objekt_id'];
            if ($befehle === [] || isset($gesehen[$schluessel])) {
                continue;
            }
            $kanal = $quelle['art'] === 'shelly' ? (string) $m['kanal'] : '';
            if (!Zugriff::darfSteuern($quelle, $kanal, $objekt)) {
                continue;
            }
            $gesehen[$schluessel] = true;
            $out[] = [
                'objekt_id' => (int) $objekt['id'],
                'objekt'    => (string) $objekt['name'],
                'quelle_id' => (int) $quelle['id'],
                'quelle'    => (string) $quelle['name'],
                'kanal'     => $kanal,
                'messpunkt' => (int) $m['id'],
                'titel'     => MesspunktService::titel($m + ['objekt_name' => $objekt['name']]),
                'befehle'   => $befehle,
            ];
        }
        return $out;
    }
}
