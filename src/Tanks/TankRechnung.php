<?php
declare(strict_types=1);

namespace App\Tanks;

use App\Support\View;
use App\Support\Zeit;

/**
 * Rechnung eines Tanksensors: Rohwert → Füllhöhe → Liter → Prozent.
 *
 * Rein – ohne Datenbank und ohne Messdaten. In die InfluxDB kommen nur
 * Rohwerte (Spannung, Analogeingang, Strom, Abstand …, siehe
 * docs/datenquellen.md); was sie im Tank bedeuten, hängt am Sensor, an seinem
 * Einbau und am Tank. Das alles steckt in einer **Fassung**
 * (tank_kennlinien.rechnung), die sich selbst trägt:
 *
 *   {"v":1, "feld":"u",
 *    "sensor":{"art":"linear","roh_anker":2.0,"mm_anker":20.0,"mm_je_roh":152.6497,
 *              "roh_min":1.8,"roh_max":10.5,"mm_max":null,"signal":"4-20mA","schritt_roh":0.05},
 *    "tank":{"form":"quader","liter_je_mm":2.78,"durchmesser_mm":null,"laenge_mm":null,
 *            "nenn_l":3000.0,"grenze_prozent":95.0,"sumpf_l":150.0},
 *    "herkunft":{"sensor":{…},"einbau":{…},"tank":{…},"text":"…"}}
 *
 * Sensorteil: Alles, was ein Messprinzip aus dem Rohwert macht, ist affin
 * – h = mm_anker + mm_je_roh · (roh − roh_anker). Eine Peilung verschiebt
 * den Anker, zwei Peilungen weit auseinander setzen die Steigung. Außerhalb
 * roh_min … roh_max (4–20 mA: 3,6 bzw. 21 mA nach NAMUR NE 43) ist der Wert
 * ein **Sensorfehler, nie „leer“**; über mm_max (Totzone eines
 * Ultraschallsensors) ebenso.
 *
 * Tankteil: Quader und stehender Zylinder haben Liter je mm, der liegende
 * Zylinder rechnet mit dem Kreissegment. Ohne Liter je mm (Form
 * „unbekannt“) gibt es nur die Füllhöhe – geschätzt wird nichts; die erste
 * Peilung oder Betankung bestimmt den Wert.
 */
final class TankRechnung
{
    public const PRINZIPIEN = [
        'hydrostatisch' => 'Hydrostatische Sonde',
        'ultraschall'   => 'Ultraschall von oben',
        'schwimmer'     => 'Schwimmer / Reedkette',
        'direkt'        => 'Gerät liefert den Füllstand',
    ];

    public const SIGNALE = [
        '4-20mA'         => '4–20 mA (an einer Bürde)',
        '4-20mA-wandler' => '4–20 mA über Spannungswandler',
        '0-10V'          => '0–10 V',
        'direkt'         => 'Wert direkt',
    ];

    /** Rohwerte in der Messung „zustand“: Beschriftung und Einheit. */
    public const ROHFELDER = [
        'u'             => ['Spannung', 'V'],
        'eingang'       => ['Analogeingang', '%'],
        'x'             => ['Wert laut Gerät', ''],
        'i_ma'          => ['Strom', 'mA'],
        'abstand_mm'    => ['Abstand', 'mm'],
        'fuellhoehe_mm' => ['Füllhöhe', 'mm'],
        'tank_prozent'  => ['Füllstand', '%'],
        'tank_liter'    => ['Inhalt', 'l'],
    ];

    /** Einheit des Messbereichs → Text. */
    public const BEREICHSEINHEITEN = [
        'mbar'      => 'mbar',
        'mWS'       => 'm Wassersäule',
        'mm_wasser' => 'mm Wassersäule',
        'mm'        => 'mm',
    ];

    /** Was ein Gerät liefert, das den Füllstand schon kennt. */
    public const DIREKT_EINHEITEN = ['prozent' => '%', 'liter' => 'l', 'mm' => 'mm'];

    public const BAUFORMEN = ['Quader' => 'quader', 'Zylinder stehend' => 'zylinder_stehend', 'Zylinder liegend' => 'zylinder_liegend'];

    /** kg/l bei 15 °C. */
    public const DICHTE = ['Diesel' => 0.835, 'HVO' => 0.78, 'Heizöl EL' => 0.845];
    public const DICHTE_VORGABE = 0.835;
    public const FUELLGRENZE = 95.0;
    public const SUMPF_ANTEIL = 0.05;

    /** Kleinster Meldeschritt je Feld (report_thr am Gerät, docs/datenquellen.md). */
    public const SCHRITT = ['u' => 0.05, 'eingang' => 1.0, 'x' => 1.0, 'i_ma' => 0.1, 'abstand_mm' => 20.0,
                            'fuellhoehe_mm' => 10.0, 'tank_prozent' => 1.0, 'tank_liter' => 10.0];

    /**
     * Vorgabe für einen neuen Tanksensor: hydrostatische 4–20-mA-Sonde über
     * einen Wandler im Modus „0–20 mA → 0–10 V“ (4 mA = 2 V – ein
     * Leitungsbruch bleibt so erkennbar). Der Messbereich steht auf der Sonde
     * und hat keine Vorgabe.
     */
    public const SENSOR_VORGABE = ['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA-wandler',
                                   'u_bei_4ma' => 2.0, 'u_bei_20ma' => 10.0, 'bereich_von' => 0.0, 'bereich_einheit' => 'mWS'];

    /** Endwerte eines Wandlers „0/4–20 mA → 0–x V“ (Steckbrücken am Modul). */
    public const WANDLER_ENDWERTE = [3.3, 5.0, 10.0];

    /** Grenzen der Stufen (Einstellungen grenzen.<schlüssel>), Tank-Eigenschaften gehen vor. */
    public const GRENZEN = ['tank_warnung' => 25.0, 'tank_alarm' => 10.0, 'tank_rest_warnung_h' => 12.0,
                            'tank_rest_alarm_h' => 4.0, 'tank_stumm_h' => 24.0, 'tank_vorlauf_h' => 24.0];

    /** 1 mbar entspricht 10,197 mm Wassersäule. */
    private const MM_WASSER_JE = ['mbar' => 10.197, 'mWS' => 1000.0, 'mm_wasser' => 1.0];

    private const SENSOR_SCHLUESSEL = ['prinzip', 'feld', 'signal', 'buerde_ohm', 'u_bei_4ma', 'u_bei_20ma', 'bereich_von', 'bereich_bis',
                                       'bereich_einheit', 'direkt_einheit', 'totzone_mm', 'schritt_roh', 'hinweis'];
    private const SENSOR_ZAHLEN = ['buerde_ohm' => 'Bürde', 'u_bei_4ma' => 'Spannung bei 4 mA', 'u_bei_20ma' => 'Spannung bei 20 mA',
                                   'bereich_von' => 'Messbereich von', 'bereich_bis' => 'Messbereich bis', 'totzone_mm' => 'Totzone',
                                   'schritt_roh' => 'Meldeschritt'];
    private const TEIL_SENSOR = ['art', 'roh_anker', 'mm_anker', 'mm_je_roh', 'roh_min', 'roh_max', 'mm_max', 'signal', 'schritt_roh'];
    private const TEIL_TANK = ['form', 'liter_je_mm', 'durchmesser_mm', 'laenge_mm', 'nenn_l', 'grenze_prozent', 'sumpf_l'];
    private const FORMEN = ['quader', 'zylinder_stehend', 'zylinder_liegend', 'unbekannt'];

    public const HINWEIS_LEITUNGSBRUCH = 'Leitungsbruch wird so nicht erkannt – der Tank zeigt dann leer. Wandler auf 0–20 mA stellen.';
    public const HINWEIS_0_10V = '0–10 V hat keinen Nullpunkt-Schutz: Ein Leitungsbruch sieht aus wie ein leerer Tank. 4–20 mA empfohlen.';
    public const HINWEIS_NUR_ROH = 'Einbaumaß fehlt – nur Rohwert.';
    public const HINWEIS_INHALT = 'Inhalt je mm unbekannt – peilen oder eine Betankung eintragen.';

    // ------------------------------------------------------------ Sensordaten

    /** Fehlertext oder null – die Regeln für die Sensordaten eines Tanksensors. */
    public static function sensorPruefen(array $e): ?string
    {
        $prinzip = (string) ($e['prinzip'] ?? '');
        $feld = (string) ($e['feld'] ?? '');
        $signal = (string) ($e['signal'] ?? '');
        if (!isset(self::PRINZIPIEN[$prinzip])) {
            return 'Bitte das Messprinzip wählen.';
        }
        if (!isset(self::ROHFELDER[$feld])) {
            return 'Bitte wählen, welcher Wert des Geräts gilt.';
        }
        if (!isset(self::SIGNALE[$signal])) {
            return 'Bitte das Signal wählen.';
        }
        foreach (self::SENSOR_ZAHLEN as $k => $name) {
            if (!self::leer($e[$k] ?? null) && self::zahlLesen($e[$k]) === null) {
                return $name . ': bitte eine Zahl eintragen.';
            }
        }
        $z = static fn (string $k): ?float => self::zahlLesen($e[$k] ?? null);

        $fertig = in_array($feld, ['tank_prozent', 'tank_liter', 'x'], true);
        if ($fertig && $prinzip !== 'direkt') {
            return 'Dieser Wert ist schon ein Füllstand – bitte „' . self::PRINZIPIEN['direkt'] . '“ wählen.';
        }
        if ($prinzip === 'direkt' && !$fertig && $feld !== 'fuellhoehe_mm') {
            return 'Ein fertiger Füllstand kommt als Füllstand (%), Inhalt (l), Füllhöhe (mm) oder Wert laut Gerät.';
        }
        if ($feld === 'abstand_mm' && $prinzip !== 'ultraschall') {
            return 'Einen Abstand misst ein Sensor von oben – bitte „' . self::PRINZIPIEN['ultraschall'] . '“ wählen.';
        }
        $direkt = $prinzip === 'direkt' || in_array($feld, ['abstand_mm', 'fuellhoehe_mm'], true);
        if ($direkt && $signal !== 'direkt') {
            return 'Dieser Wert kommt fertig vom Gerät – bitte das Signal „Wert direkt“ wählen.';
        }
        if (!$direkt && $signal === 'direkt') {
            return '„Wert direkt“ passt nur zu Abstand, Füllhöhe oder einem Gerät, das den Füllstand liefert.';
        }

        if ($prinzip === 'direkt') {
            $einheit = (string) ($e['direkt_einheit'] ?? '');
            if (!isset(self::DIREKT_EINHEITEN[$einheit])) {
                return 'Bitte angeben, ob das Gerät %, Liter oder mm liefert.';
            }
            $passend = ['tank_prozent' => 'prozent', 'tank_liter' => 'liter', 'fuellhoehe_mm' => 'mm'][$feld] ?? $einheit;
            if ($einheit !== $passend) {
                return self::ROHFELDER[$feld][0] . ' kommt in ' . self::DIREKT_EINHEITEN[$passend] . '.';
            }
        }

        if ($signal === '4-20mA') {
            if (!in_array($feld, ['u', 'eingang', 'i_ma'], true)) {
                return '4–20 mA kommt als Spannung, Analogeingang oder Strom.';
            }
            if ($feld !== 'i_ma') {
                $r = $z('buerde_ohm');
                if ($r === null) {
                    return 'Bitte die Bürde eintragen (Widerstand am Analogeingang, z. B. 499 Ω).';
                }
                if ($r < 50 || $r > 1000) {
                    return 'Die Bürde liegt zwischen 50 und 1000 Ω.';
                }
            }
        }
        if ($signal === '4-20mA-wandler') {
            if ($feld !== 'u') {
                return 'Ein Spannungswandler gibt eine Spannung aus – bitte „' . self::ROHFELDER['u'][0] . '“ wählen.';
            }
            $u4 = $z('u_bei_4ma');
            $u20 = $z('u_bei_20ma');
            if ($u4 === null || $u20 === null) {
                return 'Bitte die Spannung bei 4 mA und bei 20 mA eintragen – so, wie der Shelly sie meldet.';
            }
            if ($u4 < 0 || $u20 > 30 || $u20 - $u4 < 0.5) {
                return 'Die Spannung bei 20 mA liegt mindestens 0,5 V über der bei 4 mA (0–30 V).';
            }
        }
        if ($signal === '0-10V' && !in_array($feld, ['u', 'eingang'], true)) {
            return '0–10 V kommt als Spannung oder Analogeingang.';
        }

        if ($signal !== 'direkt') {
            $von = $z('bereich_von') ?? 0.0;
            $bis = $z('bereich_bis');
            if ($bis === null) {
                return 'Bitte den Messbereich eintragen – er steht auf dem Sensor.';
            }
            if ($von < 0 || $bis <= $von) {
                return 'Der Messbereich endet über seinem Anfang (Werte ab 0).';
            }
            $einheit = (string) ($e['bereich_einheit'] ?? '');
            $erlaubt = $prinzip === 'hydrostatisch' ? ['mbar', 'mWS', 'mm_wasser'] : ['mm'];
            if (!in_array($einheit, $erlaubt, true)) {
                return $prinzip === 'hydrostatisch'
                    ? 'Eine hydrostatische Sonde misst in mbar, m oder mm Wassersäule.'
                    : 'Ultraschall und Schwimmer messen in mm.';
            }
            $hoechstens = ['mbar' => 10000.0, 'mWS' => 100.0, 'mm_wasser' => 100000.0, 'mm' => 100000.0][$einheit];
            if ($bis > $hoechstens) {
                return 'Der Messbereich ist unplausibel groß.';
            }
        }
        $totzone = $z('totzone_mm');
        if ($totzone !== null && ($totzone < 0 || $totzone > 5000)) {
            return 'Die Totzone liegt zwischen 0 und 5000 mm.';
        }
        $schritt = $z('schritt_roh');
        if ($schritt !== null && $schritt <= 0) {
            return 'Der Meldeschritt ist größer als 0.';
        }
        if (mb_strlen(trim((string) ($e['hinweis'] ?? ''))) > 190) {
            return 'Der Hinweis ist zu lang (höchstens 190 Zeichen).';
        }
        return null;
    }

    /**
     * Sensordaten zum Speichern: immer dieselben Schlüssel in derselben
     * Reihenfolge, Zahlen als Float auf 6 Stellen, was zum Signal nicht passt
     * leer – so bleibt der JSON-Text (und der Fingerabdruck im
     * Verteiler-Abgleich) stabil.
     */
    public static function sensorNormal(array $e): array
    {
        $z = static fn (string $k): ?float => ($w = self::zahlLesen($e[$k] ?? null)) !== null ? round($w, 6) : null;
        $prinzip = (string) ($e['prinzip'] ?? '');
        $feld = (string) ($e['feld'] ?? '');
        $signal = (string) ($e['signal'] ?? '');
        $schritt = $z('schritt_roh');
        $mitBereich = $signal !== 'direkt';
        $einheit = (string) ($e['bereich_einheit'] ?? '');
        $direktEinheit = (string) ($e['direkt_einheit'] ?? '');
        return [
            'prinzip'         => $prinzip,
            'feld'            => $feld,
            'signal'          => $signal,
            'buerde_ohm'      => $signal === '4-20mA' && in_array($feld, ['u', 'eingang'], true) ? $z('buerde_ohm') : null,
            'u_bei_4ma'       => $signal === '4-20mA-wandler' ? $z('u_bei_4ma') : null,
            'u_bei_20ma'      => $signal === '4-20mA-wandler' ? $z('u_bei_20ma') : null,
            'bereich_von'     => $mitBereich ? ($z('bereich_von') ?? 0.0) : null,
            'bereich_bis'     => $mitBereich ? $z('bereich_bis') : null,
            'bereich_einheit' => $mitBereich && isset(self::BEREICHSEINHEITEN[$einheit]) ? $einheit : null,
            'direkt_einheit'  => $prinzip === 'direkt' && isset(self::DIREKT_EINHEITEN[$direktEinheit]) ? $direktEinheit : null,
            'totzone_mm'      => $prinzip === 'ultraschall' ? $z('totzone_mm') : null,
            'schritt_roh'     => $schritt !== null && $schritt > 0 ? $schritt : (self::SCHRITT[$feld] ?? 1.0),
            'hinweis'         => mb_substr(trim((string) ($e['hinweis'] ?? '')), 0, 190),
        ];
    }

    /** „Hydrostatisch 0–100 mbar, 4–20 mA an 500 Ω“ – für Inventarliste und Tankseite. */
    public static function sensorKurz(array $e): string
    {
        $s = self::sensorNormal($e);
        if ($s['prinzip'] === 'direkt') {
            return 'Füllstand vom Gerät' . ($s['direkt_einheit'] !== null ? ' in ' . self::DIREKT_EINHEITEN[$s['direkt_einheit']] : '');
        }
        $name = ['hydrostatisch' => 'Hydrostatisch', 'ultraschall' => 'Ultraschall', 'schwimmer' => 'Schwimmer'][$s['prinzip']] ?? 'Tanksensor';
        if ($s['signal'] === 'direkt') {
            return $name . ', ' . self::ROHFELDER[$s['feld']][0] . ' in mm';
        }
        $einheit = ['mbar' => 'mbar', 'mWS' => 'mWS', 'mm_wasser' => 'mm WS', 'mm' => 'mm'][$s['bereich_einheit'] ?? ''] ?? '';
        $text = $name;
        if ($s['bereich_bis'] !== null) {
            $text .= ' ' . self::zahl((float) $s['bereich_von']) . '–' . self::zahl((float) $s['bereich_bis']) . ($einheit !== '' ? ' ' . $einheit : '');
        }
        $text .= ', ' . match ($s['signal']) {
            '4-20mA'         => '4–20 mA' . ($s['buerde_ohm'] !== null ? ' an ' . self::zahl((float) $s['buerde_ohm']) . ' Ω' : ''),
            '4-20mA-wandler' => '4–20 mA über Wandler ' . self::zahl((float) $s['u_bei_4ma']) . '–' . self::zahl((float) $s['u_bei_20ma']) . ' V',
            '0-10V'          => '0–10 V',
            default          => self::SIGNALE[$s['signal']] ?? $s['signal'],
        };
        return $text;
    }

    /**
     * Spannung bei 4 mA für einen Wandler im Modus „0–20 mA → 0–x V“: ein
     * Fünftel des Endwerts (10 V → 2,0 V, 5 V → 1,0 V, 3,3 V → 0,66 V). Nur
     * ein Vorschlag – eingetragen wird, was der Shelly bei 4 mA meldet.
     */
    public static function wandlerVorschlag(float $uBei20mA): float
    {
        return round($uBei20mA / 5, 2);
    }

    /** Welcher Rohwert eines Kanals wohl gemeint ist: voltmeter/adc → u, input → eingang, BTHome → abstand_mm. */
    public static function feldVorschlag(string $kanal): string
    {
        return match (true) {
            (bool) preg_match('/^(voltmeter|adc):\d+$/', $kanal) => 'u',
            (bool) preg_match('/^input:\d+$/', $kanal)           => 'eingang',
            str_starts_with($kanal, 'bthome')                    => 'abstand_mm',
            default                                              => 'u',
        };
    }

    /**
     * Einbaumaß prüfen: Sondenspitze bzw. Schwimmer über dem Innenboden
     * (offset_mm), bei Ultraschall die Sensorfläche über dem Boden
     * (d_leer_mm, sonst nur Rohwert).
     */
    public static function einbauPruefen(array $einbau): ?string
    {
        foreach (['offset_mm' => 'Einbautiefe', 'd_leer_mm' => 'Höhe der Sensorfläche'] as $k => $name) {
            if (self::leer($einbau[$k] ?? null)) {
                continue;
            }
            $w = self::zahlLesen($einbau[$k]);
            if ($w === null) {
                return $name . ': bitte eine Zahl eintragen.';
            }
            if ($w < 0 || $w > ($k === 'offset_mm' ? 2000 : 10000)) {
                return $name . ' ist unplausibel.';
            }
        }
        return null;
    }

    /** Einbau mit Vorgaben: Sondenspitze 20 mm über dem Boden, ein direkt gelieferter Wert ohne Versatz. */
    public static function einbauNormal(array $einbau, array $sensor): array
    {
        $s = self::sensorNormal($sensor);
        $vorgabe = $s['prinzip'] === 'direkt' || $s['feld'] === 'fuellhoehe_mm' || $s['prinzip'] === 'ultraschall' ? 0.0 : 20.0;
        $offset = self::zahlLesen($einbau['offset_mm'] ?? null);
        $dLeer = self::zahlLesen($einbau['d_leer_mm'] ?? null);
        return [
            'offset_mm' => round($offset ?? $vorgabe, 6),
            'd_leer_mm' => $s['prinzip'] === 'ultraschall' && $dLeer !== null && $dLeer > 0 ? round($dLeer, 6) : null,
        ];
    }

    /** Dichte des Kraftstoffs in kg/l: eingetragen, sonst nach Kraftstoff, sonst Diesel. */
    public static function dichte(array $tankEigenschaften): float
    {
        $d = self::zahlLesen($tankEigenschaften['dichte_kg_l'] ?? null);
        if ($d !== null && $d >= 0.5 && $d <= 1.2) {
            return $d;
        }
        return self::DICHTE[(string) ($tankEigenschaften['kraftstoff'] ?? '')] ?? self::DICHTE_VORGABE;
    }

    // ------------------------------------------------------------ Fassung bauen

    /**
     * Sensorteil aus Sensordaten, Einbau und Dichte.
     *
     * Anteil f am Messbereich aus dem Rohwert (4–20 mA: Strom an der Bürde,
     * über den Wandler oder direkt; 0–10 V), daraus die Messgröße q im
     * Messbereich und die Höhe über dem Innenboden. Alles ist affin, also
     * genügen die Punkte f = 0 und f = 1.
     *
     * @return array{0:array<string,mixed>,1:list<string>} Sensorteil, Hinweise
     */
    public static function sensorTeil(array $sensor, array $einbau, float $dichte): array
    {
        $s = self::sensorNormal($sensor);
        $e = self::einbauNormal($einbau, $s);
        $hinweise = [];
        $teil = ['art' => 'roh', 'roh_anker' => null, 'mm_anker' => null, 'mm_je_roh' => null, 'roh_min' => null, 'roh_max' => null,
                 'mm_max' => null, 'signal' => str_starts_with($s['signal'], '4-20mA') ? '4-20mA' : $s['signal'], 'schritt_roh' => $s['schritt_roh']];
        $linear = static function (float $rohAnker, float $mmAnker, float $mmJeRoh) use (&$teil): void {
            $teil = ['art' => 'linear', 'roh_anker' => $rohAnker, 'mm_anker' => $mmAnker, 'mm_je_roh' => $mmJeRoh] + $teil;
        };

        if ($s['prinzip'] === 'direkt') {
            if ($s['direkt_einheit'] === 'mm') {
                $linear(0.0, $e['offset_mm'], 1.0);
            } elseif ($s['direkt_einheit'] !== null) {
                $teil['art'] = $s['direkt_einheit'];    // prozent | liter
            }
            return [self::gerundet($teil), $hinweise];
        }
        $dLeer = $e['d_leer_mm'];
        $totzone = $s['totzone_mm'];
        if ($s['signal'] === 'direkt') {
            if ($s['feld'] === 'fuellhoehe_mm') {
                $linear(0.0, $e['offset_mm'], 1.0);
            } elseif ($dLeer !== null) {
                // Abstand von der Sensorfläche nach unten (BLE, ESPHome …).
                $linear(0.0, $dLeer, -1.0);
                $teil['roh_min'] = 0.0;
                $teil['roh_max'] = $dLeer + 50;
                $teil['mm_max'] = $totzone !== null ? $dLeer - $totzone : null;
            } else {
                $hinweise[] = self::HINWEIS_NUR_ROH;
            }
            return [self::gerundet($teil), $hinweise];
        }

        // Rohwert bei 4 und 20 mA bzw. 0 und 100 %, dazu die Grenzen.
        $feld = $s['feld'];
        if ($s['signal'] === '4-20mA') {
            $je = match ($feld) {
                'u'       => (float) $s['buerde_ohm'] / 1000,    // V je mA
                'eingang' => (float) $s['buerde_ohm'] / 100,     // % (von 10 V) je mA
                default   => 1.0,
            };
            [$r0, $r1, $rMin, $rMax] = [4 * $je, 20 * $je, 3.6 * $je, 21 * $je];
        } elseif ($s['signal'] === '4-20mA-wandler') {
            $u4 = (float) $s['u_bei_4ma'];
            $u20 = (float) $s['u_bei_20ma'];
            $je = ($u20 - $u4) / 16;
            [$r0, $r1, $rMin, $rMax] = [$u4, $u20, $u4 - 0.4 * $je, $u4 + 17 * $je];
            if ($u4 < 0.5) {
                $hinweise[] = self::HINWEIS_LEITUNGSBRUCH;
            }
        } else {
            [$r0, $r1, $rMin, $rMax] = $feld === 'eingang' ? [0.0, 100.0, -2.0, 105.0] : [0.0, 10.0, -0.2, 10.5];
            $hinweise[] = self::HINWEIS_0_10V;
        }
        $teil['roh_min'] = $rMin;
        $teil['roh_max'] = $rMax;
        if (abs($r1 - $r0) < 1e-9) {
            return [self::gerundet($teil), $hinweise];    // unvollständige Sensordaten – sensorPruefen lässt das nicht durch
        }

        $q0 = (float) $s['bereich_von'];
        $q1 = (float) $s['bereich_bis'];
        $hoehe = match ($s['prinzip']) {
            'hydrostatisch' => static fn (float $q): float => $e['offset_mm'] + $q * self::MM_WASSER_JE[$s['bereich_einheit'] ?? 'mbar'] / $dichte,
            'schwimmer'     => static fn (float $q): float => $e['offset_mm'] + $q,
            default         => $dLeer !== null ? static fn (float $q): float => $dLeer - $q : null,
        };
        if ($hoehe === null) {
            $hinweise[] = self::HINWEIS_NUR_ROH;
            return [self::gerundet($teil), $hinweise];
        }
        $h0 = $hoehe($q0);
        $linear($r0, $h0, ($hoehe($q1) - $h0) / ($r1 - $r0));
        if ($s['prinzip'] === 'ultraschall' && $totzone !== null) {
            $teil['mm_max'] = $dLeer - $totzone;
        }
        return [self::gerundet($teil), $hinweise];
    }

    /** Tankteil aus den Eigenschaften des Tanks. */
    public static function tankTeil(array $tankEigenschaften): array
    {
        $z = static fn (string $k): ?float => ($w = self::zahlLesen($tankEigenschaften[$k] ?? null)) !== null && $w > 0 ? $w : null;
        $bauform = self::BAUFORMEN[(string) ($tankEigenschaften['bauform'] ?? '')] ?? 'quader';
        $literJeMm = $z('liter_je_mm');
        $d = $z('durchmesser_mm');
        $l = $z('laenge_innen_mm');
        $nenn = $z('inhalt_l');
        $grenze = $z('fuellgrenze_prozent');
        $sumpf = self::zahlLesen($tankEigenschaften['sumpf_l'] ?? null);
        $teil = [
            'form'           => $bauform,
            'liter_je_mm'    => null,
            'durchmesser_mm' => $bauform !== 'quader' ? $d : null,
            'laenge_mm'      => $bauform === 'zylinder_liegend' ? $l : null,
            'nenn_l'         => $nenn,
            'grenze_prozent' => $grenze !== null && $grenze <= 110 ? $grenze : self::FUELLGRENZE,
            'sumpf_l'        => $sumpf !== null && $sumpf >= 0 ? $sumpf : ($nenn !== null ? self::SUMPF_ANTEIL * $nenn : null),
        ];
        if ($bauform === 'zylinder_stehend') {
            // Gelernt (Peilung, Betankung) oder eingetragen geht vor dem Durchmesser.
            $teil['liter_je_mm'] = $literJeMm ?? ($d !== null ? M_PI * ($d / 2) ** 2 / 1e6 : null);
        } elseif ($bauform === 'quader') {
            $teil['liter_je_mm'] = $literJeMm;
        }
        if ($bauform !== 'zylinder_liegend' && $teil['liter_je_mm'] === null) {
            $teil['form'] = 'unbekannt';
        }
        return self::gerundet($teil);
    }

    /**
     * Eine ganze Fassung: Sensorteil, Tankteil und Herkunft.
     *
     * $herkunft: kennung (des Sensors), name und modell (des Tanks), text.
     *
     * @return array{rechnung:array,hinweise:list<string>}
     */
    public static function rechnung(array $sensor, array $einbau, array $tankEigenschaften, array $herkunft): array
    {
        $s = self::sensorNormal($sensor);
        $e = self::einbauNormal($einbau, $s);
        $dichte = self::dichte($tankEigenschaften);
        [$sensorTeil, $hinweise] = self::sensorTeil($s, $e, $dichte);
        $r = [
            'v'        => 1,
            'feld'     => $s['feld'],
            'sensor'   => $sensorTeil,
            'tank'     => self::tankTeil($tankEigenschaften),
            'herkunft' => [
                'sensor' => array_filter([
                    'kennung'    => mb_substr((string) ($herkunft['kennung'] ?? ''), 0, 64),
                    'prinzip'    => $s['prinzip'],
                    'signal'     => $s['signal'],
                    'buerde_ohm' => $s['buerde_ohm'],
                    'u_bei_4ma'  => $s['u_bei_4ma'],
                    'u_bei_20ma' => $s['u_bei_20ma'],
                    'bereich'    => $s['bereich_bis'] !== null ? [$s['bereich_von'], $s['bereich_bis'], $s['bereich_einheit']] : null,
                ], static fn ($w) => $w !== null),
                'einbau' => array_filter($e, static fn ($w) => $w !== null),
                'tank'   => [
                    'name'        => mb_substr((string) ($herkunft['name'] ?? ''), 0, 190),
                    'modell'      => mb_substr((string) ($herkunft['modell'] ?? ''), 0, 190),
                    'kraftstoff'  => mb_substr((string) ($tankEigenschaften['kraftstoff'] ?? ''), 0, 32),
                    'dichte_kg_l' => round($dichte, 6),
                ],
                'text'   => mb_substr((string) ($herkunft['text'] ?? ''), 0, 500),
            ],
        ];
        if ($s['prinzip'] === 'hydrostatisch' && self::leer($tankEigenschaften['kraftstoff'] ?? null) && self::leer($tankEigenschaften['dichte_kg_l'] ?? null)) {
            $hinweise[] = 'Kraftstoff nicht eingetragen – mit Diesel (0,835 kg/l) gerechnet.';
        }
        $tankHoehe = self::tankHoehe($tankEigenschaften);
        $messbereich = $tankHoehe !== null ? self::messbereichHinweis($s, $tankHoehe, $dichte) : null;
        if ($messbereich !== null) {
            $hinweise[] = $messbereich;
        }
        return ['rechnung' => $r, 'hinweise' => array_values(array_unique(array_merge($hinweise, self::hinweise($r))))];
    }

    /** Tankteil ersetzen, Sensorteil bleibt (Tankdaten geändert). */
    public static function mitTank(array $r, array $tankTeil): array
    {
        $r['tank'] = $tankTeil;
        return $r;
    }

    /** Sensorteil ersetzen, Tankteil bleibt (Sensordaten im Einsatz geändert). */
    public static function mitSensor(array $r, array $sensorTeil): array
    {
        $r['sensor'] = $sensorTeil;
        return $r;
    }

    /**
     * Was an einer Fassung zu beachten ist – für Tankseite und Panel, aus der
     * Fassung selbst (sie trägt ihre Herkunft mit).
     *
     * @return list<string>
     */
    public static function hinweise(array $r): array
    {
        $h = [];
        $herkunft = (array) ($r['herkunft']['sensor'] ?? []);
        if (($herkunft['signal'] ?? '') === '4-20mA-wandler' && is_numeric($herkunft['u_bei_4ma'] ?? null) && (float) $herkunft['u_bei_4ma'] < 0.5) {
            $h[] = self::HINWEIS_LEITUNGSBRUCH;
        }
        if (($herkunft['signal'] ?? '') === '0-10V') {
            $h[] = self::HINWEIS_0_10V;
        }
        if (($r['sensor']['art'] ?? '') === 'roh') {
            $h[] = self::HINWEIS_NUR_ROH;
        }
        $tank = (array) ($r['tank'] ?? []);
        if (($tank['form'] ?? '') === 'unbekannt' && ($r['sensor']['art'] ?? '') === 'linear') {
            $h[] = self::HINWEIS_INHALT;
        }
        if (($tank['form'] ?? '') === 'zylinder_liegend' && (($tank['durchmesser_mm'] ?? null) === null || ($tank['laenge_mm'] ?? null) === null)) {
            $h[] = 'Liegender Zylinder: Innendurchmesser und Innenlänge fehlen – nur Füllhöhe.';
        }
        if (($tank['nenn_l'] ?? null) === null) {
            $h[] = 'Nennvolumen fehlt – kein Füllstand in %.';
        }
        return $h;
    }

    /**
     * Wie viel ihres Messbereichs eine hydrostatische Sonde in diesem Tank
     * nutzt (in Prozent, ganzzahlig) – oder null.
     */
    public static function messbereichAnteil(array $sensor, float $tankHoeheMm, float $dichte): ?int
    {
        $s = self::sensorNormal($sensor);
        if ($s['prinzip'] !== 'hydrostatisch' || $s['bereich_bis'] === null || !isset(self::MM_WASSER_JE[$s['bereich_einheit'] ?? '']) || $tankHoeheMm <= 0) {
            return null;
        }
        $bereichMm = ((float) $s['bereich_bis'] - (float) $s['bereich_von']) * self::MM_WASSER_JE[$s['bereich_einheit']] / $dichte;
        return $bereichMm > 0 ? (int) round(100 * $tankHoeheMm / $bereichMm) : null;
    }

    /** Hinweis, wenn der Tank weniger als 40 % des Messbereichs ausfüllt – nie sperrend. */
    public static function messbereichHinweis(array $sensor, float $tankHoeheMm, float $dichte): ?string
    {
        $anteil = self::messbereichAnteil($sensor, $tankHoeheMm, $dichte);
        if ($anteil === null || $anteil >= 40) {
            return null;
        }
        return 'Die Sonde nutzt nur etwa ' . $anteil . ' % ihres Messbereichs – die Genauigkeit (in % vom Messbereich) wirkt '
            . 'entsprechend stärker. Eine Sonde mit kleinerem Messbereich misst genauer.';
    }

    /**
     * Höhe des Tanks, soweit bekannt: Durchmesser des liegenden Zylinders,
     * sonst das H aus „L × B × H“ (Außenmaß – eine obere Schranke).
     */
    public static function tankHoehe(array $tankEigenschaften): ?float
    {
        if (($tankEigenschaften['bauform'] ?? '') === 'Zylinder liegend') {
            $d = self::zahlLesen($tankEigenschaften['durchmesser_mm'] ?? null);
            return $d !== null && $d > 0 ? $d : null;
        }
        $text = (string) ($tankEigenschaften['abmessungen'] ?? '');
        if (preg_match_all('/\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?/', $text, $m) < 3) {
            return null;
        }
        $h = (float) str_replace(['.', ','], ['', '.'], $m[0][2]);
        return $h > 0 ? $h : null;
    }

    /**
     * Ist eine Fassung in sich stimmig? Fehlertext oder null.
     *
     * Genau die bekannten Schlüssel, nur endliche Zahlen, Steigung ≠ 0,
     * Liter je mm und Nennvolumen > 0 – so geht auch eine Kennlinie „von
     * Hand“ nur durch, wenn sie sich rechnen lässt.
     */
    public static function pruefen(mixed $r): ?string
    {
        if (!is_array($r)) {
            return 'Die Kennlinie ist kein Objekt.';
        }
        if (array_diff(array_keys($r), ['v', 'feld', 'sensor', 'tank', 'herkunft']) !== [] || !isset($r['v'], $r['feld'], $r['sensor'], $r['tank'])) {
            return 'Die Kennlinie hat unbekannte oder fehlende Teile.';
        }
        if ($r['v'] !== 1 || !is_string($r['feld']) || !isset(self::ROHFELDER[$r['feld']])) {
            return 'Fassung oder Feld der Kennlinie sind unbekannt.';
        }
        $s = $r['sensor'];
        $t = $r['tank'];
        if (!is_array($s) || !is_array($t) || self::schluesselFalsch($s, self::TEIL_SENSOR) || self::schluesselFalsch($t, self::TEIL_TANK)) {
            return 'Sensor- oder Tankteil haben unbekannte oder fehlende Schlüssel.';
        }
        foreach (['roh_anker', 'mm_anker', 'mm_je_roh', 'roh_min', 'roh_max', 'mm_max', 'schritt_roh'] as $k) {
            if (!self::zahlOderNull($s[$k])) {
                return 'Sensorteil: „' . $k . '“ ist keine endliche Zahl.';
            }
        }
        foreach (['liter_je_mm', 'durchmesser_mm', 'laenge_mm', 'nenn_l', 'grenze_prozent', 'sumpf_l'] as $k) {
            if (!self::zahlOderNull($t[$k])) {
                return 'Tankteil: „' . $k . '“ ist keine endliche Zahl.';
            }
        }
        if (!in_array($s['art'], ['linear', 'prozent', 'liter', 'roh'], true) || !in_array($s['signal'], ['4-20mA', '0-10V', 'direkt'], true)) {
            return 'Sensorteil: Art oder Signal unbekannt.';
        }
        if ($s['art'] === 'linear' && ($s['roh_anker'] === null || $s['mm_anker'] === null || $s['mm_je_roh'] === null || (float) $s['mm_je_roh'] == 0.0)) {
            return 'Sensorteil: Anker und Steigung (≠ 0) fehlen.';
        }
        if ($s['roh_min'] !== null && $s['roh_max'] !== null && (float) $s['roh_min'] >= (float) $s['roh_max']) {
            return 'Sensorteil: Die untere Grenze liegt über der oberen.';
        }
        if ($s['schritt_roh'] === null || (float) $s['schritt_roh'] <= 0) {
            return 'Sensorteil: Der Meldeschritt ist größer als 0.';
        }
        if (!in_array($t['form'], self::FORMEN, true)) {
            return 'Tankteil: Bauform unbekannt.';
        }
        if (in_array($t['form'], ['quader', 'zylinder_stehend'], true) && $t['liter_je_mm'] === null) {
            return 'Tankteil: Liter je mm fehlen.';
        }
        foreach (['liter_je_mm' => 'Liter je mm', 'nenn_l' => 'Nennvolumen', 'durchmesser_mm' => 'Durchmesser', 'laenge_mm' => 'Länge', 'grenze_prozent' => 'Füllgrenze'] as $k => $name) {
            if ($t[$k] !== null && (float) $t[$k] <= 0) {
                return 'Tankteil: ' . $name . ' muss größer als 0 sein.';
            }
        }
        if ($t['sumpf_l'] !== null && (float) $t['sumpf_l'] < 0) {
            return 'Tankteil: Der Sumpf ist nicht negativ.';
        }
        if (isset($r['herkunft'])) {
            $herkunft = $r['herkunft'];
            if (!is_array($herkunft) || array_diff(array_keys($herkunft), ['sensor', 'einbau', 'tank', 'text']) !== [] || !self::herkunftOk($herkunft, 0)) {
                return 'Die Herkunft der Kennlinie ist ungültig.';
            }
        }
        if (json_encode($r) === false) {
            return 'Die Kennlinie lässt sich nicht speichern.';
        }
        return null;
    }

    // ------------------------------------------------------------ Rechnen

    /** Füllhöhe in mm über dem Innenboden zu einem Rohwert – ohne Prüfung der Grenzen. */
    public static function hoehe(array $r, float $roh): ?float
    {
        $s = $r['sensor'];
        return match ($s['art']) {
            'linear'  => (float) $s['mm_anker'] + (float) $s['mm_je_roh'] * ($roh - (float) $s['roh_anker']),
            'liter'   => self::hoeheAusLiter($r['tank'], $roh),
            'prozent' => $r['tank']['nenn_l'] !== null ? self::hoeheAusLiter($r['tank'], $roh / 100 * (float) $r['tank']['nenn_l']) : null,
            default   => null,
        };
    }

    /** Liter bei einer Füllhöhe – null, wenn der Tank sie nicht hergibt (Form unbekannt). */
    public static function liter(array $tankTeil, float $hoeheMm): ?float
    {
        $h = max(0.0, $hoeheMm);
        if (in_array($tankTeil['form'], ['quader', 'zylinder_stehend'], true) && $tankTeil['liter_je_mm'] !== null) {
            return $h * (float) $tankTeil['liter_je_mm'];
        }
        if ($tankTeil['form'] === 'zylinder_liegend' && $tankTeil['durchmesser_mm'] !== null && $tankTeil['laenge_mm'] !== null) {
            $d = (float) $tankTeil['durchmesser_mm'];
            $r = $d / 2;
            $h = min($h, $d);
            $segment = $r * $r * acos(($r - $h) / $r) - ($r - $h) * sqrt(max(0.0, 2 * $r * $h - $h * $h));
            return (float) $tankTeil['laenge_mm'] * $segment / 1e6;
        }
        return null;
    }

    /** Füllhöhe zu einem Inhalt; beim liegenden Zylinder durch Bisektion. */
    public static function hoeheAusLiter(array $tankTeil, float $liter): ?float
    {
        if (in_array($tankTeil['form'], ['quader', 'zylinder_stehend'], true) && $tankTeil['liter_je_mm'] !== null) {
            return max(0.0, $liter) / (float) $tankTeil['liter_je_mm'];
        }
        if ($tankTeil['form'] !== 'zylinder_liegend' || self::liter($tankTeil, 0.0) === null) {
            return null;
        }
        $unten = 0.0;
        $oben = (float) $tankTeil['durchmesser_mm'];
        if ($liter >= (float) self::liter($tankTeil, $oben)) {
            return $oben;
        }
        for ($i = 0; $i < 60 && $oben - $unten > 1e-6; $i++) {
            $mitte = ($unten + $oben) / 2;
            if ((float) self::liter($tankTeil, $mitte) < $liter) {
                $unten = $mitte;
            } else {
                $oben = $mitte;
            }
        }
        return ($unten + $oben) / 2;
    }

    /** Lassen sich mit dieser Fassung Liter rechnen? */
    public static function kalibriert(array $r): bool
    {
        return match ($r['sensor']['art']) {
            'liter'   => true,
            'prozent' => $r['tank']['nenn_l'] !== null,
            'linear'  => self::liter($r['tank'], 0.0) !== null,
            default   => false,
        };
    }

    /**
     * Ein Rohwert in Tankwerten.
     *
     * Ein Rohwert außerhalb der Grenzen, ein Sensorfehler des Geräts oder
     * ein Wert in der Totzone ergibt `fehler` und keine Werte – nie 0 l.
     * Über 105 % des Nennvolumens und unter dem Boden ist der Wert
     * unplausibel; die Werte bleiben dann sichtbar. Prozent werden nicht
     * gekappt.
     *
     * @return array{hoehe_mm:?float,liter:?float,prozent:?float,nutzbar_l:?float,fehler:?string,ueber_grenze:bool}
     */
    public static function umrechnen(array $r, ?float $roh, bool $sensorfehler = false): array
    {
        $erg = ['hoehe_mm' => null, 'liter' => null, 'prozent' => null, 'nutzbar_l' => null, 'fehler' => null, 'ueber_grenze' => false];
        if ($sensorfehler) {
            $erg['fehler'] = 'Sensorfehler laut Gerät';
            return $erg;
        }
        if ($roh === null || !is_finite($roh)) {
            return $erg;
        }
        $erg['fehler'] = self::rohFehler($r, $roh);
        if ($erg['fehler'] !== null) {
            return $erg;
        }
        $s = $r['sensor'];
        $t = $r['tank'];
        $nenn = $t['nenn_l'] !== null ? (float) $t['nenn_l'] : null;
        $liter = null;
        $hoehe = null;
        if ($s['art'] === 'linear') {
            $hoehe = (float) self::hoehe($r, $roh);
            if ($s['mm_max'] !== null && $hoehe > (float) $s['mm_max'] + 1e-9) {
                $erg['fehler'] = 'Zu voll oder Sensor zu tief (Totzone)';
                return $erg;
            }
            if ($hoehe < -50) {
                $erg['hoehe_mm'] = round($hoehe, 2);
                $erg['fehler'] = 'Füllhöhe unter dem Boden – Einbaumaß oder Kennlinie prüfen';
                return $erg;
            }
            $liter = self::liter($t, $hoehe);
        } elseif ($s['art'] === 'prozent') {
            $liter = $nenn !== null ? $roh / 100 * $nenn : null;
            $erg['prozent'] = round($roh, 3);
        } elseif ($s['art'] === 'liter') {
            $liter = $roh;
        } else {
            return $erg;
        }
        if (($s['art'] === 'prozent' && $roh < -2) || ($s['art'] === 'liter' && $nenn !== null && $roh < -0.02 * $nenn)) {
            $erg['prozent'] = null;
            $erg['fehler'] = 'Wert unplausibel (unter 0)';
            return $erg;
        }
        if ($erg['prozent'] !== null) {
            $erg['prozent'] = max(0.0, $erg['prozent']);
        }
        if ($liter !== null) {
            $liter = max(0.0, $liter);
            $hoehe ??= self::hoeheAusLiter($t, $liter);
            $erg['liter'] = round($liter, 2);
            $erg['nutzbar_l'] = round(max(0.0, $liter - (float) ($t['sumpf_l'] ?? 0)), 2);
            if ($nenn !== null) {
                $erg['prozent'] = round($liter / $nenn * 100, 3);
            }
        }
        $erg['hoehe_mm'] = $hoehe !== null ? round(max(0.0, $hoehe), 2) : null;
        if ($erg['prozent'] !== null) {
            $erg['ueber_grenze'] = $erg['prozent'] > (float) $t['grenze_prozent'];
            if ($erg['prozent'] > 105) {
                $erg['fehler'] = 'Wert unplausibel (' . self::zahl(round($erg['prozent'])) . ' % des Nennvolumens)';
            }
        }
        return $erg;
    }

    /** Liter je Meldeschritt des Sensors (Zylinder in halber Höhe) – die Auflösung im Tank. */
    public static function schrittLiter(array $r): ?float
    {
        $s = $r['sensor'];
        $t = $r['tank'];
        $schritt = (float) $s['schritt_roh'];
        if ($s['art'] === 'liter') {
            return $schritt;
        }
        if ($s['art'] === 'prozent') {
            return $t['nenn_l'] !== null ? $schritt / 100 * (float) $t['nenn_l'] : null;
        }
        if ($s['art'] !== 'linear') {
            return null;
        }
        $literJeMm = match (true) {
            $t['liter_je_mm'] !== null => (float) $t['liter_je_mm'],
            $t['form'] === 'zylinder_liegend' && $t['durchmesser_mm'] !== null && $t['laenge_mm'] !== null
                => (float) $t['durchmesser_mm'] * (float) $t['laenge_mm'] / 1e6,
            default => null,
        };
        return $literJeMm !== null ? $schritt * abs((float) $s['mm_je_roh']) * $literJeMm : null;
    }

    // ------------------------------------------------------------ Kalibrieren

    /**
     * Peilung: genau eine Angabe – Inhalt in Litern, Anzeige in Prozent oder
     * Peilstab in mm – zum Rohwert, den der Server zu dieser Zeit hat.
     *
     * - Peilstab: neuer Anker, die Steigung bleibt.
     * - Liter/% bei unbekanntem Inhalt je mm: bestimmt Liter je mm (ab 100 mm
     *   Füllhöhe).
     * - Liter/% bei bekannter Form: neuer Anker auf der Höhe dieses Inhalts.
     * - Direkt gelieferter Füllstand: nur die Abweichung.
     *
     * @return array{rechnung?:array,liter_je_mm?:float,abweichung?:float,hinweis?:string,fehler?:string}
     */
    public static function peilen(array $r, float $roh, array $peilung): array
    {
        $angaben = array_filter(['liter', 'prozent', 'hoehe_mm'], static fn (string $k): bool => !self::leer($peilung[$k] ?? null));
        if (count($angaben) !== 1) {
            return ['fehler' => 'Bitte genau eine Angabe: Inhalt (l), Anzeige (%) oder Peilstab (mm).'];
        }
        $art = (string) reset($angaben);
        $wert = self::zahlLesen($peilung[$art]);
        $t = $r['tank'];
        $nenn = $t['nenn_l'] !== null ? (float) $t['nenn_l'] : null;
        if ($wert === null || $wert < 0) {
            return ['fehler' => 'Die Peilung ist keine Zahl ab 0.'];
        }
        if ($art === 'prozent' && $nenn === null) {
            return ['fehler' => 'Für eine Anzeige in % fehlt das Nennvolumen des Tanks.'];
        }
        if (($art === 'prozent' && $wert > 110) || ($art === 'liter' && $wert > ($nenn !== null ? 1.1 * $nenn : 100000)) || ($art === 'hoehe_mm' && $wert > 5000)) {
            return ['fehler' => 'Die Peilung ist größer als der Tank.'];
        }
        $fehler = self::rohFehler($r, $roh);
        if ($fehler !== null) {
            return ['fehler' => $fehler . ' – Peilung nur protokolliert.'];
        }
        $s = $r['sensor'];
        $liter = match ($art) {
            'liter'   => $wert,
            'prozent' => $wert / 100 * (float) $nenn,
            default   => null,
        };

        if (in_array($s['art'], ['prozent', 'liter'], true)) {
            $jetzt = self::umrechnen($r, $roh);
            $gepeilt = $liter ?? ($art === 'hoehe_mm' ? self::liter($t, $wert) : null);
            if ($jetzt['liter'] === null || $gepeilt === null || $gepeilt <= 0) {
                return ['hinweis' => 'Das Gerät liefert den Füllstand selbst – Peilung nur protokolliert.'];
            }
            $abweichung = round(($jetzt['liter'] - $gepeilt) / $gepeilt * 100, 1);
            return ['abweichung' => $abweichung, 'hinweis' => 'Das Gerät liefert den Füllstand selbst: ' . self::zahl(round($jetzt['liter']))
                . ' l, gepeilt ' . self::zahl(round($gepeilt)) . ' l (' . ($abweichung > 0 ? '+' : '') . self::zahl($abweichung) . ' %).'];
        }
        if ($s['art'] !== 'linear') {
            return ['fehler' => 'Ohne Einbaumaß gibt es nur den Rohwert – Peilung nur protokolliert.'];
        }
        if ($liter !== null && $t['form'] === 'unbekannt') {
            $h = (float) self::hoehe($r, $roh);
            if ($h < 100) {
                return ['fehler' => 'Zu wenig drin, um den Inhalt je mm zu bestimmen – bei höherem Stand peilen.'];
            }
            $literJeMm = $liter / $h;
            if ($literJeMm < 0.1 || $literJeMm > 30) {
                return ['fehler' => 'Das ergäbe ' . self::zahl(round($literJeMm, 2)) . ' l je mm – unplausibel. Peilung und Sensor prüfen.'];
            }
            $r['tank'] = ['form' => 'quader', 'liter_je_mm' => round($literJeMm, 6)] + $t;
            return ['rechnung' => $r, 'liter_je_mm' => round($literJeMm, 6)];
        }
        if ($liter !== null) {
            $h = self::hoeheAusLiter($t, $liter);
            if ($h === null) {
                return ['fehler' => 'Für einen liegenden Zylinder fehlen Innendurchmesser und Innenlänge.'];
            }
        } else {
            $h = $wert;
        }
        return ['rechnung' => self::anker($r, $roh, $h)];
    }

    /**
     * Zwei Peilungen mit dem Peilstab im selben Einbau, mindestens 300 mm
     * auseinander: Steigung aus beiden, Anker auf der jüngeren. Weicht sie um
     * mehr als 15 % von der aus den Sensordaten ab, kommt eine Warnung –
     * übernehmen bleibt möglich. Unter 300 mm gibt es kein Angebot (null).
     *
     * @param array{roh:float,hoehe_mm:float} $alt
     * @param array{roh:float,hoehe_mm:float} $neu
     * @return ?array{rechnung?:array,mm_je_roh?:float,abweichung?:?float,warnung?:?string,fehler?:string}
     */
    public static function zweiPeilungen(array $r, array $alt, array $neu, ?float $mmJeRohSensordaten): ?array
    {
        $dh = (float) $neu['hoehe_mm'] - (float) $alt['hoehe_mm'];
        if ($r['sensor']['art'] !== 'linear' || abs($dh) < 300) {
            return null;
        }
        $droh = (float) $neu['roh'] - (float) $alt['roh'];
        if (abs($droh) < 1e-9) {
            return ['fehler' => 'Der Rohwert hat sich zwischen beiden Peilungen nicht geändert – Sensor prüfen.'];
        }
        $steigung = $dh / $droh;
        $abweichung = $mmJeRohSensordaten !== null && $mmJeRohSensordaten != 0.0
            ? round(($steigung - $mmJeRohSensordaten) / $mmJeRohSensordaten * 100, 1) : null;
        $r = self::anker($r, (float) $neu['roh'], (float) $neu['hoehe_mm']);
        $r['sensor']['mm_je_roh'] = round($steigung, 6);
        return [
            'rechnung'   => $r,
            'mm_je_roh'  => round($steigung, 6),
            'abweichung' => $abweichung,
            'warnung'    => $abweichung !== null && abs($abweichung) > 15
                ? 'Die Steigung weicht um ' . self::zahl(abs($abweichung)) . ' % von den Sensordaten ab – Messbereich, Dichte oder Wandler prüfen.' : null,
        ];
    }

    /**
     * Betankung: geliefert (Liter, Verbrauch im Fenster schon abgezogen)
     * zwischen zwei Rohwerten – ergibt Liter je mm des Tanks. Beim liegenden
     * Zylinder nur die Abweichung der Kennlinie.
     *
     * @return array{rechnung?:array,liter_je_mm?:float,abweichung?:?float,hinweis?:string,fehler?:string}
     */
    public static function betanken(array $r, float $rohVor, float $rohNach, float $liter, bool $trotzdem = false): array
    {
        $s = $r['sensor'];
        $t = $r['tank'];
        if ($s['art'] !== 'linear') {
            return ['fehler' => 'Aus einer Betankung kalibrieren geht nur mit der Füllhöhe, nicht mit einem fertig gelieferten Füllstand.'];
        }
        if ($liter <= 0) {
            return ['fehler' => 'Die gelieferte Menge ist größer als 0.'];
        }
        $fehler = self::rohFehler($r, $rohVor) ?? self::rohFehler($r, $rohNach);
        if ($fehler !== null) {
            return ['fehler' => $fehler];
        }
        $dh = (float) self::hoehe($r, $rohNach) - (float) self::hoehe($r, $rohVor);
        if (abs($rohNach - $rohVor) < 10 * (float) $s['schritt_roh'] - 1e-9 || $dh <= 0) {
            return ['fehler' => 'Kein deutlicher Anstieg um diese Zeit in den Messwerten.'];
        }
        if ($t['form'] === 'zylinder_liegend') {
            $vor = self::liter($t, (float) self::hoehe($r, $rohVor));
            $nach = self::liter($t, (float) self::hoehe($r, $rohNach));
            if ($vor === null || $nach === null) {
                return ['fehler' => 'Für einen liegenden Zylinder fehlen Innendurchmesser und Innenlänge.'];
            }
            $abweichung = round(($nach - $vor - $liter) / $liter * 100, 1);
            return ['abweichung' => $abweichung, 'hinweis' => 'Die Kennlinie liegt ' . self::zahl(abs($abweichung)) . ' % daneben.'];
        }
        $literJeMm = $liter / $dh;
        if ($literJeMm < 0.1 || $literJeMm > 30) {
            return ['fehler' => 'Das ergäbe ' . self::zahl(round($literJeMm, 2)) . ' l je mm – unplausibel. Menge und Zeit der Betankung prüfen.'];
        }
        $alt = $t['form'] !== 'unbekannt' && $t['liter_je_mm'] !== null ? (float) $t['liter_je_mm'] : null;
        $abweichung = $alt !== null ? round(($literJeMm - $alt) / $alt * 100, 1) : null;
        if ($abweichung !== null && abs($abweichung) > 25 && !$trotzdem) {
            return ['fehler' => 'Das ergäbe ' . self::zahl(round($literJeMm, 2)) . ' l je mm – ' . self::zahl(abs($abweichung)) . ' % '
                . ($abweichung > 0 ? 'mehr' : 'weniger') . ' als bisher (' . self::zahl($alt) . '). Trotzdem übernehmen?', 'abweichung' => $abweichung];
        }
        $r['tank'] = ['form' => $t['form'] === 'zylinder_stehend' ? 'zylinder_stehend' : 'quader', 'liter_je_mm' => round($literJeMm, 6)] + $t;
        return ['rechnung' => $r, 'liter_je_mm' => round($literJeMm, 6), 'abweichung' => $abweichung];
    }

    /**
     * Der Sprung einer Betankung in Rohpunkten [ms, roh]: der größte Anstieg
     * der Füllhöhe innerhalb von $fensterS (bei Ultraschall fällt dabei der
     * Rohwert), davor und danach je der Median über 20 Minuten. Null, wenn
     * der Sprung keine 10 Meldeschritte hat.
     *
     * @param list<array{0:int,1:?float}> $punkte
     * @return ?array{t_vor:int,roh_vor:float,t_nach:int,roh_nach:float}
     */
    public static function anstieg(array $punkte, array $r, int $fensterS = 3600): ?array
    {
        $p = array_values(array_filter($punkte, static fn ($x): bool => is_array($x) && isset($x[0], $x[1]) && is_numeric($x[1])));
        usort($p, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $richtung = ($r['sensor']['art'] ?? '') === 'linear' && (float) $r['sensor']['mm_je_roh'] < 0 ? -1.0 : 1.0;
        $n = count($p);
        $best = null;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n && $p[$j][0] - $p[$i][0] <= $fensterS * 1000; $j++) {
                $anstieg = $richtung * ((float) $p[$j][1] - (float) $p[$i][1]);
                $dauer = $p[$j][0] - $p[$i][0];
                if ($best === null || $anstieg > $best[0] || ($anstieg === $best[0] && $dauer < $best[3])) {
                    $best = [$anstieg, $i, $j, $dauer];
                }
            }
        }
        if ($best === null || $best[0] <= 0) {
            return null;
        }
        [, $i, $j] = $best;
        $tVor = (int) $p[$i][0];
        $tNach = (int) $p[$j][0];
        $median = static function (int $von, int $bis) use ($p): float {
            $werte = [];
            foreach ($p as [$t, $w]) {
                if ($t >= $von && $t <= $bis) {
                    $werte[] = (float) $w;
                }
            }
            return self::median($werte);
        };
        $vor = $median($tVor - 1_200_000, $tVor);
        $nach = $median($tNach, $tNach + 1_200_000);
        if (abs($nach - $vor) < 10 * (float) $r['sensor']['schritt_roh'] - 1e-9 || $richtung * ($nach - $vor) <= 0) {
            return null;
        }
        return ['t_vor' => $tVor, 'roh_vor' => $vor, 't_nach' => $tNach, 'roh_nach' => $nach];
    }

    /**
     * Welche Fassung wann gilt: Grenzen an allen `ab`, je Grenze die größte
     * id unter allen mit ab ≤ Grenze, gleiche Nachbarn zusammengelegt. Vor
     * der ersten Fassung gilt nichts.
     *
     * @param list<array{id:int,ab:int,rechnung:array}> $f
     * @return list<array{0:int,1:int,2:array,3:int}> [von, bis (ohne), Rechnung, id]
     */
    public static function abschnitte(array $f, int $von, int $bis): array
    {
        if ($von >= $bis || $f === []) {
            return [];
        }
        $grenzen = [$von];
        foreach ($f as $k) {
            if ((int) $k['ab'] > $von && (int) $k['ab'] < $bis) {
                $grenzen[] = (int) $k['ab'];
            }
        }
        $grenzen = array_values(array_unique($grenzen));
        sort($grenzen);
        $aus = [];
        foreach ($grenzen as $n => $g) {
            $ende = $grenzen[$n + 1] ?? $bis;
            $gilt = null;
            foreach ($f as $k) {
                if ((int) $k['ab'] <= $g && ($gilt === null || (int) $k['id'] > (int) $gilt['id'])) {
                    $gilt = $k;
                }
            }
            if ($gilt === null) {
                continue;
            }
            $letzter = count($aus) - 1;
            if ($letzter >= 0 && $aus[$letzter][3] === (int) $gilt['id'] && $aus[$letzter][1] === $g) {
                $aus[$letzter][1] = $ende;
            } else {
                $aus[] = [$g, $ende, $gilt['rechnung'], (int) $gilt['id']];
            }
        }
        return $aus;
    }

    // ------------------------------------------------------------ Verbrauch

    /**
     * Verbrauch je Last aus dem Text der Strombibel: „100% 22L / 70% 16L /
     * 50% 12L“ → [[50, 12], [70, 16], [100, 22]]. Eine Zahl ohne % gilt als
     * Volllast.
     *
     * @return list<array{0:float,1:float}>
     */
    public static function kennlinieLesen(string $text): array
    {
        $punkte = [];
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*%\s*[:=]?\s*(\d+(?:[.,]\d+)?)/u', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $treffer) {
                $last = (float) str_replace(',', '.', $treffer[1]);
                $lh = (float) str_replace(',', '.', $treffer[2]);
                if ($last > 0 && $last <= 150 && $lh <= 1000) {
                    $punkte[(string) $last] = [$last, $lh];
                }
            }
        } elseif (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(?:l(?:\/h)?)?\s*$/iu', $text, $m) === 1) {
            $punkte['100'] = [100.0, (float) str_replace(',', '.', $m[1])];
        }
        $punkte = array_values($punkte);
        usort($punkte, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return $punkte;
    }

    /**
     * Erwarteter Verbrauch in l/h bei einer Last in %: zwischen den Punkten
     * linear, unter dem kleinsten dessen Wert, über dem größten
     * fortgeschrieben. Ohne Kennlinie gilt der Volllastverbrauch (bei
     * Teillast zu hoch, also auf der sicheren Seite) – `geschaetzt`.
     *
     * @param list<array{0:float,1:float}> $kennlinie
     * @return ?array{l_h:float,geschaetzt:bool}
     */
    public static function verbrauch(array $kennlinie, ?float $vollastLh, ?float $lastProzent): ?array
    {
        if ($kennlinie === []) {
            return $vollastLh !== null && $vollastLh > 0 ? ['l_h' => $vollastLh, 'geschaetzt' => true] : null;
        }
        if ($lastProzent === null) {
            return ['l_h' => (float) $kennlinie[0][1], 'geschaetzt' => true];
        }
        $n = count($kennlinie);
        if ($lastProzent <= $kennlinie[0][0] || $n === 1) {
            return ['l_h' => (float) ($lastProzent <= $kennlinie[0][0] ? $kennlinie[0][1] : $kennlinie[$n - 1][1]), 'geschaetzt' => false];
        }
        for ($i = 1; $i < $n; $i++) {
            if ($lastProzent <= $kennlinie[$i][0] || $i === $n - 1) {
                [$x0, $y0] = $kennlinie[$i - 1];
                [$x1, $y1] = $kennlinie[$i];
                return ['l_h' => round($y0 + ($y1 - $y0) * ($lastProzent - $x0) / ($x1 - $x0), 3), 'geschaetzt' => false];
            }
        }
        return null;
    }

    /** Last in % der Nennleistung (cos φ 0,8): P / (kVA · 800 W) · 100. */
    public static function last(?float $pW, ?float $kva): ?float
    {
        if ($pW === null || $kva === null || $kva <= 0) {
            return null;
        }
        return round(max(0.0, $pW) / ($kva * 800) * 100, 3);
    }

    /**
     * Verbrauch aus dem Pegel: Ausgleichsgerade durch [ms, Liter], in l/h,
     * positiv = Verbrauch. Null bei weniger als drei Punkten oder unter
     * 10 Minuten.
     *
     * @param list<array{0:int,1:?float}> $punkte
     */
    public static function gefaelle(array $punkte): ?float
    {
        $p = array_values(array_filter($punkte, static fn ($x): bool => is_array($x) && isset($x[0], $x[1]) && is_numeric($x[1])));
        $n = count($p);
        if ($n < 3) {
            return null;
        }
        $t0 = (float) $p[0][0];
        $sx = $sy = $sxx = $sxy = 0.0;
        $min = $max = (float) $p[0][0];
        foreach ($p as [$t, $w]) {
            $x = ((float) $t - $t0) / 3_600_000;   // Stunden
            $sx += $x;
            $sy += (float) $w;
            $sxx += $x * $x;
            $sxy += $x * (float) $w;
            $min = min($min, (float) $t);
            $max = max($max, (float) $t);
        }
        $nenner = $n * $sxx - $sx * $sx;
        if ($max - $min < 600_000 || abs($nenner) < 1e-12) {
            return null;
        }
        return round(-($n * $sxy - $sx * $sy) / $nenner, 3);
    }

    // ------------------------------------------------------------ Stufen

    /**
     * Stufe eines Tanks – die erste zutreffende Zeile gilt:
     * gesperrt · keine (kein Sensor) · bad (Sensorfehler) · bad (Alarm,
     * Restlaufzeit, nichts entnehmbar) · stumm · warn (meldet nicht, obwohl
     * ein Generator läuft) · warn (Warnung, Restlaufzeit, Füllgrenze,
     * nachbestellen) · ok. Ein Sensorfehler heißt nie „leer“; ein alter,
     * schon kritischer Wert bleibt rot.
     *
     * $t: die Werte aus TankService::live (sensor, alter_s, zeit (ms),
     * online, fehler, prozent, nutzbar_l, liter, hoehe_mm, ueber_grenze,
     * grenze_prozent, restlaufzeit_h, nachbestellen_bis, verbrauch_l_h,
     * schritt_l, generatoren, peilung, gesperrt, jetzt).
     * $grenzen: Einstellungen (GRENZEN), $tank: Eigenschaften des Tanks
     * (warnung_prozent, alarm_prozent gehen vor).
     *
     * @return array{stufe:string,hinweis:string}
     */
    public static function stufe(array $t, array $grenzen, array $tank = []): array
    {
        $g = self::grenzen($grenzen, $tank);
        $jetzt = (int) ($t['jetzt'] ?? time());
        if (!empty($t['gesperrt'])) {
            return ['stufe' => 'gesperrt', 'hinweis' => 'Livewerte außerhalb des freigegebenen Zeitraums'];
        }
        if (empty($t['sensor'])) {
            return ['stufe' => 'keine', 'hinweis' => self::peilungText($t['peilung'] ?? null, $jetzt)];
        }
        if (($t['fehler'] ?? null) !== null && $t['fehler'] !== '') {
            return ['stufe' => 'bad', 'hinweis' => (string) $t['fehler']];
        }
        $prozent = isset($t['prozent']) ? (float) $t['prozent'] : null;
        $rest = isset($t['restlaufzeit_h']) ? (float) $t['restlaufzeit_h'] : null;
        $nutzbar = isset($t['nutzbar_l']) ? (float) $t['nutzbar_l'] : null;
        $alter = isset($t['alter_s']) ? (int) $t['alter_s'] : null;
        $online = $t['online'] ?? null;

        $rot = [];
        if ($nutzbar !== null && $nutzbar <= 0) {
            $rot[] = 'nichts mehr entnehmbar – sofort nachtanken';
        } elseif ($rest !== null && $rest <= $g['tank_rest_alarm_h']) {
            $rot[] = self::restText($rest) . ' – sofort nachtanken';
        } elseif ($prozent !== null && $prozent <= $g['tank_alarm']) {
            $rot[] = 'nur noch ' . self::zahl(round($prozent)) . ' % – nachtanken';
        }
        if ($rot !== []) {
            return ['stufe' => 'bad', 'hinweis' => implode(' · ', $rot) . self::standText($t, $jetzt, ' · ')];
        }

        if ($alter === null || ($t['zeit'] ?? null) === null) {
            return ['stufe' => 'stumm', 'hinweis' => 'noch kein Wert seit dem Einbau' . ($online === false ? ', Gerät offline' : '')];
        }
        if ($online === false || ($alter > $g['tank_stumm_h'] * 3600 && $online !== true)) {
            return ['stufe' => 'stumm', 'hinweis' => 'letzter Wert ' . self::alterText($alter) . ($online === false ? ', Gerät offline' : '')];
        }

        $literJeStunde = isset($t['verbrauch_l_h']) ? (float) $t['verbrauch_l_h'] : null;
        $schrittL = isset($t['schritt_l']) ? (float) $t['schritt_l'] : null;
        $laufend = array_values(array_filter((array) ($t['generatoren'] ?? []), static fn ($x): bool => is_array($x) && !empty($x['laeuft'])));
        if ($laufend !== [] && $literJeStunde !== null && $literJeStunde > 0 && $schrittL !== null
            && $alter > max(3600.0, 3 * $schrittL / $literJeStunde * 3600)) {
            return ['stufe' => 'warn', 'hinweis' => 'meldet nicht, obwohl ' . (string) ($laufend[0]['name'] ?? 'ein Generator') . ' läuft'
                . self::standText($t, $jetzt, ' · ')];
        }

        $gelb = [];
        $bis = isset($t['nachbestellen_bis']) ? Zeit::ts((string) $t['nachbestellen_bis']) : null;
        if ($bis !== null && $bis <= $jetzt) {
            $gelb[] = 'nachbestellen – Lieferzeit reicht nicht mehr';
        } elseif ($rest !== null && $rest <= $g['tank_rest_warnung_h']) {
            $gelb[] = self::restText($rest) . ($bis !== null ? ' – nachbestellen bis ' . self::wann($bis, $jetzt) : '');
        }
        if ($prozent !== null && $prozent <= $g['tank_warnung']) {
            $gelb[] = 'nur noch ' . self::zahl(round($prozent)) . ' %';
        }
        $grenze = (float) ($t['grenze_prozent'] ?? self::FUELLGRENZE);
        if (!empty($t['ueber_grenze']) || ($prozent !== null && $prozent > $grenze)) {
            $gelb[] = 'über der Füllgrenze ' . self::zahl($grenze) . ' %';
        }
        if ($gelb !== []) {
            return ['stufe' => 'warn', 'hinweis' => implode(' · ', $gelb) . self::standText($t, $jetzt, ' · ')];
        }

        $text = self::standText($t, $jetzt, '');
        if (($t['liter'] ?? null) === null && $prozent === null) {
            if (isset($t['hoehe_mm'])) {
                $text = 'Füllhöhe ' . self::zahl(round((float) $t['hoehe_mm'])) . ' mm – Inhalt unbekannt: peilen · ' . $text;
            } elseif (isset($t['roh'])) {
                $text = 'Rohwert ' . self::zahl(round((float) $t['roh'], 3)) . (($t['roh_einheit'] ?? '') !== '' ? ' ' . $t['roh_einheit'] : '')
                    . ' – ' . self::HINWEIS_NUR_ROH . ' ' . $text;
            }
        } elseif ($rest !== null) {
            $text .= ' · ' . self::restText($rest);
        }
        return ['stufe' => 'ok', 'hinweis' => $text];
    }

    /** Grenzen: Einstellungen, darüber Warnung/Alarm des Tanks. @return array<string,float> */
    public static function grenzen(array $einstellungen, array $tank = []): array
    {
        $g = [];
        foreach (self::GRENZEN as $k => $vorgabe) {
            $w = self::zahlLesen($einstellungen[$k] ?? null);
            $g[$k] = $w !== null && $w >= 0 ? $w : $vorgabe;
        }
        foreach (['warnung_prozent' => 'tank_warnung', 'alarm_prozent' => 'tank_alarm'] as $eigen => $k) {
            $w = self::zahlLesen($tank[$eigen] ?? null);
            if ($w !== null && $w >= 0 && $w <= 100) {
                $g[$k] = $w;
            }
        }
        return $g;
    }

    /** „gerade eben“, „vor 37 min“, „vor 3 h“, „vor 1 Tag“, „vor 2 Tagen“. */
    public static function alterText(int $sek): string
    {
        return match (true) {
            $sek < 90       => 'gerade eben',
            $sek < 5400     => 'vor ' . (int) round($sek / 60) . ' min',
            $sek < 86400    => 'vor ' . (int) round($sek / 3600) . ' h',
            default         => ($tage = (int) round($sek / 86400)) === 1 ? 'vor 1 Tag' : 'vor ' . $tage . ' Tagen',
        };
    }

    /** „noch 40 min“, „noch 3,5 h“, „noch 19 h“, „noch 6 Tage“. */
    public static function restText(float $stunden): string
    {
        return match (true) {
            $stunden < 1    => 'noch ' . (int) round(max(0.0, $stunden) * 60) . ' min',
            $stunden < 10   => 'noch ' . self::zahl(round($stunden, 1)) . ' h',
            $stunden < 48   => 'noch ' . (int) round($stunden) . ' h',
            default         => 'noch ' . (int) round($stunden / 24) . ' Tage',
        };
    }

    /** Eine Zahl mit Tausenderpunkt und nur so vielen Stellen, wie sie hat: „3.000“, „2,78“. */
    public static function zahl(float $wert): string
    {
        $text = View::zahlFeld($wert);
        $komma = strpos($text, ',');
        return number_format($wert, $komma === false ? 0 : strlen($text) - $komma - 1, ',', '.');
    }

    /** Text eines Werts als Zahl (Komma oder Punkt) – null, wenn leer, keine Zahl oder nicht endlich. */
    public static function zahlLesen(mixed $wert): ?float
    {
        if (is_int($wert) || is_float($wert)) {
            return is_finite((float) $wert) ? (float) $wert : null;
        }
        if (!is_string($wert)) {
            return null;
        }
        $text = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim($wert));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        $zahl = (float) $text;
        return is_finite($zahl) ? $zahl : null;
    }

    // ------------------------------------------------------------ intern

    /** Liegt der Rohwert außerhalb der Grenzen des Sensors? Dann ein Sensorfehler. */
    private static function rohFehler(array $r, float $roh): ?string
    {
        $s = $r['sensor'];
        $min = $s['roh_min'];
        $max = $s['roh_max'];
        $ma = $s['signal'] === '4-20mA';
        if ($min !== null && $roh < (float) $min - 1e-9) {
            return match (true) {
                $ma                       => 'Sensorfehler: Signal unter 3,6 mA (Leitungsbruch?)',
                $s['signal'] === '0-10V'  => 'Sensorfehler: Signal unter 0 V',
                default                   => 'Sensorfehler: Rohwert unter dem Messbereich',
            };
        }
        if ($max !== null && $roh > (float) $max + 1e-9) {
            return match (true) {
                $ma                       => 'Sensorfehler: Signal über 21 mA (Kurzschluss?)',
                $s['signal'] === '0-10V'  => 'Sensorfehler: Signal über 10,5 V',
                $s['art'] === 'linear' && (float) $s['mm_je_roh'] < 0 => 'Sensorfehler: Abstand größer als der Tank (Sensor verrutscht?)',
                default                   => 'Sensorfehler: Rohwert über dem Messbereich',
            };
        }
        return null;
    }

    /** Neuer Anker (roh, h), Steigung bleibt; die Totzone wandert mit. */
    private static function anker(array $r, float $roh, float $hoehe): array
    {
        $vorher = (float) self::hoehe($r, $roh);
        $r['sensor']['roh_anker'] = round($roh, 6);
        $r['sensor']['mm_anker'] = round($hoehe, 6);
        if ($r['sensor']['mm_max'] !== null) {
            $r['sensor']['mm_max'] = round((float) $r['sensor']['mm_max'] + $hoehe - $vorher, 6);
        }
        return $r;
    }

    /** „Stand 14:02 (vor 3 h)“ bzw. „Stand 15.10. 08:02 (vor 1 Tag)“. */
    private static function standText(array $t, int $jetzt, string $davor): string
    {
        if (!isset($t['zeit'], $t['alter_s'])) {
            return '';
        }
        return $davor . 'Stand ' . self::wann(intdiv((int) $t['zeit'], 1000), $jetzt) . ' (' . self::alterText((int) $t['alter_s']) . ')';
    }

    /** Uhrzeit in Ortszeit; an einem anderen Tag mit Datum, in der nächsten Woche mit Wochentag. */
    private static function wann(int $ts, int $jetzt): string
    {
        $zone = Zeit::zone();
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($zone);
        $heute = (new \DateTimeImmutable('@' . $jetzt))->setTimezone($zone);
        if ($dt->format('Y-m-d') === $heute->format('Y-m-d')) {
            return $dt->format('H:i');
        }
        if ($ts > $jetzt && $ts - $jetzt < 6 * 86400) {
            return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) $dt->format('w')] . ' ' . $dt->format('H:i');
        }
        return $dt->format('d.m. H:i');
    }

    /** „kein Tanksensor – Peilung 08:15: 60 % (≈ 1.800 l), vor 3 h“ bzw. „kein Tanksensor eingesetzt“. */
    private static function peilungText(mixed $peilung, int $jetzt): string
    {
        $ts = is_array($peilung) ? Zeit::ts((string) ($peilung['zeit'] ?? '')) : null;
        if ($ts === null) {
            return 'kein Tanksensor eingesetzt';
        }
        $teile = [];
        if (isset($peilung['prozent'])) {
            $teile[] = self::zahl(round((float) $peilung['prozent'])) . ' %';
        }
        if (isset($peilung['liter'])) {
            $teile[] = (isset($peilung['prozent']) ? '(≈ ' : '') . self::zahl(round((float) $peilung['liter'])) . ' l' . (isset($peilung['prozent']) ? ')' : '');
        }
        if ($teile === [] && isset($peilung['hoehe_mm'])) {
            $teile[] = self::zahl(round((float) $peilung['hoehe_mm'])) . ' mm';
        }
        return 'kein Tanksensor – Peilung ' . self::wann($ts, $jetzt) . ($teile !== [] ? ': ' . implode(' ', $teile) : '')
            . ', ' . self::alterText(max(0, $jetzt - $ts));
    }

    private static function median(array $werte): float
    {
        sort($werte);
        $n = count($werte);
        return $n % 2 === 1 ? (float) $werte[intdiv($n, 2)] : ((float) $werte[$n / 2 - 1] + (float) $werte[$n / 2]) / 2;
    }

    private static function leer(mixed $wert): bool
    {
        return $wert === null || (is_string($wert) && trim($wert) === '');
    }

    private static function zahlOderNull(mixed $wert): bool
    {
        return $wert === null || ((is_int($wert) || is_float($wert)) && is_finite((float) $wert));
    }

    /** Genau diese Schlüssel? */
    private static function schluesselFalsch(array $teil, array $schluessel): bool
    {
        $ist = array_keys($teil);
        sort($ist);
        sort($schluessel);
        return $ist !== $schluessel;
    }

    /** Herkunft: nur kurze Texte, endliche Zahlen und flache Listen, höchstens zwei Ebenen. */
    private static function herkunftOk(array $teil, int $tiefe): bool
    {
        if ($tiefe > 2 || count($teil) > 20) {
            return false;
        }
        foreach ($teil as $k => $w) {
            if (is_string($k) && preg_match('/^[a-z0-9_]{1,32}$/', $k) !== 1) {
                return false;
            }
            $ok = match (true) {
                $w === null, is_bool($w)        => true,
                is_int($w), is_float($w)        => is_finite((float) $w),
                is_string($w)                   => mb_strlen($w) <= 500,
                is_array($w)                    => self::herkunftOk($w, $tiefe + 1),
                default                         => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** Zahlen auf 6 Stellen – stabiler JSON-Text. */
    private static function gerundet(array $teil): array
    {
        foreach ($teil as $k => $w) {
            if (is_float($w)) {
                $teil[$k] = round($w, 6);
            }
        }
        return $teil;
    }
}
