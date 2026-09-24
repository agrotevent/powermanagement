<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Zeit;

/**
 * Erfundene, aber plausible Messwerte für den Demo-Modus.
 *
 * Kein Zufall, sondern eine Funktion der Zeit: dieselbe Minute liefert bei
 * jedem Aufruf denselben Wert. So passen Live-Kachel, Diagramm und CSV
 * zusammen, und die Screenshots sehen beim zweiten Mal genauso aus.
 *
 * Die Geräte entsprechen dem, was `bin/power demo:seed` anlegt: ein
 * Festivalgelände mit Einspeisung, Bühne, Catering, Licht (schaltbar),
 * einem Generator mit ComAp-Steuerung und einem Victron-Speicher.
 */
final class Demo
{
    /** geraet => [typ, modell, name, kanäle => [art, Grundlast in W, Spitze in W, Profil]] */
    public const GERAETE = [
        'shellypro3em-einspeisung' => ['shelly', 'SPEM-003CEBEU', 'Einspeisung Hauptverteiler', ['em:0' => ['drehstrom', 9000, 52000, 'gesamt']]],
        'shellypro3em-buehne'      => ['shelly', 'SPEM-003CEBEU', 'Bühne', ['em:0' => ['drehstrom', 1500, 30000, 'abend']]],
        'shellypro3em-catering'    => ['shelly', 'SPEM-003CEBEU', 'Catering', ['em:0' => ['drehstrom', 2500, 16000, 'essen']]],
        'shellypro4pm-licht'       => ['shelly', 'SPSW-104PE16EU', 'Beleuchtung Gelände', [
            'switch:0' => ['einphasig', 150, 1800, 'nacht'],
            'switch:1' => ['einphasig', 150, 1600, 'nacht'],
            'switch:2' => ['einphasig', 80, 900, 'nacht'],
            'switch:3' => ['einphasig', 0, 1200, 'abend'],
        ]],
        'gx-backstage'             => ['victron', 'Cerbo GX', 'Speicher Backstage', [
            'verbrauch' => ['drehstrom', 800, 6500, 'gesamt'],
            'ac_aus'    => ['drehstrom', 800, 6500, 'gesamt'],
            'batterie'  => ['batterie', 0, 0, ''],
            'vebus'     => ['vebus', 0, 0, ''],
        ]],
        'ig200-aggregat1'          => ['comap', 'InteliGen 200', 'Aggregat 1 (100 kVA)', [
            'generator' => ['drehstrom', 12000, 64000, 'gesamt'],
            'motor'     => ['motor', 0, 0, ''],
        ]],
    ];

    private static function zustandDatei(): string
    {
        return dirname(__DIR__, 2) . '/storage/demo/schalter.json';
    }

    /** @return array<string,string> */
    private static function schalterZustand(): array
    {
        $datei = self::zustandDatei();
        return is_file($datei) ? (json_decode((string) file_get_contents($datei), true) ?: []) : [];
    }

    public static function schalten(string $geraet, string $kanal, string $befehl): void
    {
        $zustand = self::schalterZustand();
        $zustand[$geraet . '|' . $kanal] = in_array($befehl, ['aus', 'stop', 'vebus_aus'], true) ? 'aus' : 'ein';
        if (!is_dir(dirname(self::zustandDatei()))) {
            mkdir(dirname(self::zustandDatei()), 0775, true);
        }
        file_put_contents(self::zustandDatei(), json_encode($zustand));
    }

    private static function aus(string $geraet, string $kanal): bool
    {
        return (self::schalterZustand()[$geraet . '|' . $kanal] ?? 'ein') === 'aus';
    }

    /** Pseudozufall aus dem Zeitpunkt: -1 … 1, für dieselbe Eingabe gleich. */
    private static function rauschen(string $saat, int $t): float
    {
        return ((crc32($saat . ':' . $t) % 20001) / 10000.0) - 1.0;
    }

    /** Tagesverlauf 0…1 je Profil. */
    private static function profil(string $profil, int $t): float
    {
        $stunde = (float) (new \DateTimeImmutable('@' . $t))->setTimezone(Zeit::zone())->format('G')
            + (float) (new \DateTimeImmutable('@' . $t))->setTimezone(Zeit::zone())->format('i') / 60;
        $glocke = static fn (float $mitte, float $breite): float => exp(-(($stunde - $mitte) ** 2) / (2 * $breite ** 2));
        return match ($profil) {
            'abend'  => max($glocke(21.0, 2.0), 0.35 * $glocke(16.0, 1.5)),
            'essen'  => max(0.9 * $glocke(12.5, 1.2), $glocke(19.0, 1.5), 0.25 * $glocke(16, 3)),
            'nacht'  => ($stunde >= 19.5 || $stunde < 1.5) ? 1.0 : ($stunde < 6 ? 0.2 : 0.02),
            default  => min(1.0, 0.2 + 0.55 * $glocke(13.0, 3.5) + 0.8 * $glocke(21.0, 2.2)),
        };
    }

    /** Gesamtwirkleistung eines Kanals in W zum Zeitpunkt t. */
    private static function leistung(string $geraet, string $kanal, int $t): float
    {
        $def = self::GERAETE[$geraet][3][$kanal] ?? null;
        if ($def === null || $def[2] <= 0 || self::aus($geraet, $kanal)) {
            return 0.0;
        }
        [, $grund, $spitze, $profil] = $def;
        $minute = intdiv($t, 60);
        $glatt = self::rauschen($geraet . $kanal, $minute) * 0.06 + self::rauschen($geraet . $kanal . 's', intdiv($t, 10)) * 0.03;
        return max(0.0, ($grund + ($spitze - $grund) * self::profil($profil, $t)) * (1 + $glatt));
    }

    private const ANTEIL = ['L1' => 0.36, 'L2' => 0.33, 'L3' => 0.31];

    /** Ein einzelner Wert. */
    public static function wert(string $messung, string $geraet, string $kanal, string $phase, string $feld, int $t): float|string|null
    {
        $def = self::GERAETE[$geraet][3][$kanal] ?? null;
        if ($def === null) {
            return null;
        }
        $art = $def[0];
        if ($messung === 'zustand') {
            return self::zustandsWert($geraet, $kanal, $art, $feld, $t);
        }
        $p = self::leistung($geraet, $kanal, $t);
        $anteil = $art === 'einphasig' ? ($phase === 'sum' ? 1.0 : 0.0) : ($phase === 'sum' ? 1.0 : (self::ANTEIL[$phase] ?? 0.0));
        if ($art === 'einphasig' && $phase !== 'sum') {
            return null;
        }
        $u = 230.0 + 2.2 * sin($t / 1800) + self::rauschen($geraet . $phase . 'u', intdiv($t, 10)) * 0.8
            - ($art === 'drehstrom' ? $p / 20000 : 0);
        $pf = 0.93 + 0.03 * sin($t / 5400);
        return match ($feld) {
            'p'  => round($p * $anteil, 1),
            's'  => round($p * $anteil / $pf, 1),
            'q'  => round($p * $anteil * 0.39, 1),
            'pf' => round($pf, 3),
            'u'  => round($u, 1),
            'i'  => $phase === 'sum' && $art === 'drehstrom'
                ? round($p / (sqrt(3) * 400 * $pf), 2)
                : round($p * $anteil / ($u * $pf), 2),
            'f'  => round(50 + 0.04 * sin($t / 97) + self::rauschen('f', intdiv($t, 10)) * 0.01, 3),
            'e'  => $phase === 'sum' ? round(($def[1] + ($def[2] - $def[1]) * 0.42) * ($t - 1_700_000_000) / 3600, 0) : null,
            default => null,
        };
    }

    private static function zustandsWert(string $geraet, string $kanal, string $art, string $feld, int $t): float|string|null
    {
        if ($art === 'einphasig') {
            return match ($feld) {
                'schalter'   => self::aus($geraet, $kanal) ? 0.0 : 1.0,
                'temperatur' => round(38 + 6 * self::profil('nacht', $t), 1),
                default      => null,
            };
        }
        if ($art === 'batterie') {
            $soc = 55 + 35 * sin(2 * M_PI * (($t % 86400) / 86400) - 1.2);
            $laden = cos(2 * M_PI * (($t % 86400) / 86400) - 1.2) > 0;
            return match ($feld) {
                'soc' => round($soc, 1),
                'u'   => round(51.2 + $soc / 25, 2),
                'i'   => round(($laden ? 1 : -1) * (20 + 15 * self::profil('gesamt', $t)), 1),
                'p'   => round(($laden ? 1 : -1) * (1000 + 800 * self::profil('gesamt', $t)), 0),
                'restlaufzeit_s' => $laden ? null : round($soc * 600, 0),
                default => null,
            };
        }
        if ($art === 'vebus') {
            return match ($feld) {
                'zustand'          => self::aus($geraet, 'vebus') ? 0.0 : 9.0,
                'zustand_text'     => self::aus($geraet, 'vebus') ? 'Aus' : 'Wechselrichten',
                'schalterstellung' => self::aus($geraet, 'vebus') ? 4.0 : 3.0,
                'schalterstellung_text' => self::aus($geraet, 'vebus') ? 'Aus' : 'Ein',
                default            => null,
            };
        }
        if ($art === 'motor') {
            $laeuft = !self::aus($geraet, 'motor');
            $tagesanteil = ($t % 86400) / 86400;
            return match ($feld) {
                'drehzahl'        => $laeuft ? round(1500 + self::rauschen('rpm', intdiv($t, 10)) * 4) : 0.0,
                'tank_prozent'    => round(95 - 70 * $tagesanteil, 1),
                'kuehlmittel_c'   => $laeuft ? round(78 + 6 * self::profil('gesamt', $t), 1) : 30.0,
                'oeldruck_bar'    => $laeuft ? round(4.1 + self::rauschen('oel', intdiv($t, 60)) * 0.1, 2) : 0.0,
                'u_batterie'      => round($laeuft ? 27.6 : 25.1, 1),
                'betriebsstunden' => round(1520 + ($t - 1_750_000_000) / 3600 * 0.7, 1),
                'starts'          => 318.0,
                'nennleistung_w'  => 80000.0,
                'motorzustand'    => $laeuft ? 'Läuft' : 'Bereit',
                'modus'           => 'AUTO',
                default           => null,
            };
        }
        return null;
    }

    /** Welche Zustandsfelder ein Kanal hat. */
    private static function zustandsFelder(string $art): array
    {
        return match ($art) {
            'einphasig' => ['schalter', 'temperatur'],
            'batterie'  => ['soc', 'u', 'i', 'p', 'restlaufzeit_s'],
            'vebus'     => ['zustand', 'zustand_text', 'schalterstellung', 'schalterstellung_text'],
            'motor'     => ['drehzahl', 'tank_prozent', 'kuehlmittel_c', 'oeldruck_bar', 'u_batterie', 'betriebsstunden', 'starts', 'nennleistung_w', 'motorzustand', 'modus'],
            default     => [],
        };
    }

    public static function letzte(array $paare): array
    {
        $t = time();
        $out = [];
        foreach ($paare as [$geraet, $kanal]) {
            $def = self::GERAETE[$geraet][3][$kanal] ?? null;
            if ($def === null) {
                continue;
            }
            $eintrag = ['zeit' => ($t - 2) * 1000, 'phasen' => [], 'zustand' => []];
            $phasen = match ($def[0]) {
                'drehstrom' => ['L1', 'L2', 'L3', 'sum'],
                'einphasig' => ['sum'],
                default     => [],
            };
            foreach ($phasen as $phase) {
                foreach (['u', 'i', 'p', 'pf', 'f', 's', 'e'] as $feld) {
                    $w = self::wert('strom', $geraet, $kanal, $phase, $feld, $t);
                    if ($w !== null) {
                        $eintrag['phasen'][$phase][$feld] = $w;
                    }
                }
            }
            foreach (self::zustandsFelder($def[0]) as $feld) {
                $w = self::zustandsWert($geraet, $kanal, $def[0], $feld, $t);
                if ($w !== null) {
                    $eintrag['zustand'][$feld] = $w;
                }
            }
            $out[$geraet . '|' . $kanal] = $eintrag;
        }
        return $out;
    }

    public static function reihe(string $messung, string $geraet, string $kanal, string $feld, array $phasen, int $von, int $bis, int $schritt, string $agg): array
    {
        $schritt = max($schritt, (int) ceil(($bis - $von) / 4000));
        $out = [];
        $namen = $messung === 'strom' ? ($phasen ?: ['sum']) : [$feld];
        $faktor = match ($agg) { 'max' => 1.07, 'min' => 0.94, default => 1.0 };
        for ($t = (int) (ceil($von / $schritt) * $schritt); $t < $bis; $t += $schritt) {
            foreach ($namen as $name) {
                $w = $messung === 'strom'
                    ? self::wert('strom', $geraet, $kanal, $name, $feld, $t)
                    : self::wert('zustand', $geraet, $kanal, '', $feld, $t);
                if (!is_float($w)) {
                    continue;
                }
                if ($feld === 'u' || $feld === 'f') {
                    $w = $agg === 'max' ? $w + 1.5 : ($agg === 'min' ? $w - 1.8 : $w);
                } else {
                    $w *= $faktor;
                }
                $out[$name][] = [$t * 1000, round($w, 3)];
            }
        }
        return $out;
    }

    public static function energieTage(string $geraet, string $kanal, int $von, int $bis): array
    {
        $out = [];
        $schritt = 900;
        for ($t = (int) (ceil($von / $schritt) * $schritt); $t < $bis; $t += $schritt) {
            $tag = (new \DateTimeImmutable('@' . $t))->setTimezone(Zeit::zone())->format('Y-m-d');
            $p = (float) (self::wert('strom', $geraet, $kanal, 'sum', 'p', $t) ?? 0.0);
            $out[$tag] = ($out[$tag] ?? 0.0) + $p * $schritt / 3600;
        }
        return $out;
    }

    public static function geraete(): array
    {
        $out = [];
        $t = time();
        foreach (self::GERAETE as $geraet => [$typ, $modell, $name, $kanaele]) {
            foreach ($kanaele as $kanal => $_def) {
                $out[] = [
                    'geraet'  => $geraet,
                    'kanal'   => $kanal,
                    'typ'     => $typ,
                    'zuletzt' => ($t - 2) * 1000,
                    'modell'  => $modell,
                    'name'    => $name,
                    'p'       => self::wert('strom', $geraet, $kanal, 'sum', 'p', $t),
                ];
            }
        }
        return $out;
    }
}
