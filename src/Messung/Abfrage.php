<?php
declare(strict_types=1);

namespace App\Messung;

use App\Support\Config;

/**
 * Eine Datenquelle einmal abfragen und das Ergebnis normalisieren.
 *
 * Ergebnis ist eine Liste von Kanälen im selben Format, das Node-RED für
 * Shelly erzeugt (siehe Influx::zeilen()):
 *
 *   ['netz' => ['phasen' => ['L1' => ['p' => 1200.0], …], 'zustand' => […]], …]
 */
final class Abfrage
{
    /**
     * @return array{ok:bool,fehler:?string,kanaele:array<string,array{phasen:array,zustand:array}>}
     */
    public static function ausfuehren(array $quelle): array
    {
        try {
            $kanaele = match ((string) $quelle['art']) {
                'modbus'      => self::modbus($quelle),
                'comap_cloud' => ComapCloud::werte($quelle),
                'victron_vrm' => VictronVrm::werte($quelle),
                default       => [],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'fehler' => $e->getMessage(), 'kanaele' => []];
        }
        return ['ok' => true, 'fehler' => null, 'kanaele' => self::summenErgaenzen($kanaele)];
    }

    /**
     * Ein Modbus-Gerät nach seinem Registerprofil lesen.
     *
     * Benachbarte Register gleicher Unit und Funktion werden zu Blöcken
     * zusammengefasst – ein Victron GX liefert so seine Systemwerte in
     * zwei Anfragen statt in zwanzig.
     */
    public static function modbus(array $quelle, ?ModbusTcp $verbindung = null): array
    {
        $profil = Profile::find((string) $quelle['profil']);
        if ($profil === null) {
            throw new \RuntimeException('Registerprofil „' . $quelle['profil'] . '“ gibt es nicht.');
        }
        $register = array_values(array_filter($profil['register'], static fn ($r) => ($r['adresse'] ?? null) !== null));
        if ($register === []) {
            throw new \RuntimeException('Im Registerprofil ist noch keine Adresse eingetragen.');
        }

        $mb = $verbindung ?? new ModbusTcp((string) $quelle['host'], (int) $quelle['port'], (float) Config::get('sammler.modbus_timeout', 3));
        $bloecke = [];
        foreach ($register as $r) {
            $unit = $r['unit'] ?? null;
            $unit = $unit === null ? (int) $quelle['unit_id'] : (int) $unit;
            $bloecke[$unit . ':' . (int) ($r['funktion'] ?? 3)][] = $r;
        }

        $kanaele = [];
        $fehler = [];
        foreach ($bloecke as $schluessel => $eintraege) {
            [$unit, $funktion] = array_map('intval', explode(':', $schluessel));
            usort($eintraege, static fn ($a, $b) => (int) $a['adresse'] <=> (int) $b['adresse']);
            foreach (self::zusammenfassen($eintraege) as $block) {
                try {
                    $roh = $mb->lesen($unit, $block['start'], $block['anzahl'], $funktion);
                } catch (\RuntimeException $e) {
                    // Ein fehlender Dienst (z. B. kein VE.Bus am GX) soll die
                    // Systemwerte nicht mitreißen.
                    $fehler[] = 'Unit ' . $unit . ' ab ' . $block['start'] . ': ' . $e->getMessage();
                    continue;
                }
                foreach ($block['eintraege'] as $r) {
                    $versatz = (int) $r['adresse'] - $block['start'];
                    $wert = ModbusTcp::dekodieren(array_slice($roh, $versatz, ModbusTcp::breite((string) $r['typ'])), (string) $r['typ'], !empty($r['wort_tausch']));
                    if (!is_float($wert)) {
                        continue;
                    }
                    self::eintragen($kanaele, $r, $wert * (float) ($r['faktor'] ?? 1));
                }
            }
        }
        if ($verbindung === null) {
            $mb->trennen();
        }
        if ($kanaele === [] && $fehler !== []) {
            throw new \RuntimeException(implode('; ', $fehler));
        }
        return $kanaele;
    }

    /**
     * Aufeinanderfolgende Register zu Leseblöcken bündeln (höchstens 100
     * Register, Lücken bis 10 Register werden mitgelesen).
     *
     * @return list<array{start:int,anzahl:int,eintraege:list<array>}>
     */
    private static function zusammenfassen(array $eintraege): array
    {
        $bloecke = [];
        foreach ($eintraege as $r) {
            $adresse = (int) $r['adresse'];
            $ende = $adresse + ModbusTcp::breite((string) $r['typ']);
            $letzter = count($bloecke) - 1;
            if ($letzter >= 0) {
                $b = &$bloecke[$letzter];
                $bEnde = $b['start'] + $b['anzahl'];
                if ($adresse <= $bEnde + 10 && $ende - $b['start'] <= 100) {
                    $b['anzahl'] = max($b['anzahl'], $ende - $b['start']);
                    $b['eintraege'][] = $r;
                    unset($b);
                    continue;
                }
                unset($b);
            }
            $bloecke[] = ['start' => $adresse, 'anzahl' => $ende - $adresse, 'eintraege' => [$r]];
        }
        return $bloecke;
    }

    /** Einen Wert an die richtige Stelle der Kanalstruktur legen. */
    public static function eintragen(array &$kanaele, array $r, float $wert): void
    {
        $kanal = (string) $r['kanal'];
        $feld = (string) $r['feld'];
        $phase = (string) ($r['phase'] ?? '');
        $kanaele[$kanal] ??= ['phasen' => [], 'zustand' => []];
        if ($phase !== '') {
            $kanaele[$kanal]['phasen'][$phase][$feld] = round($wert, 4);
            return;
        }
        $kanaele[$kanal]['zustand'][$feld] = round($wert, 4);
        $werte = (array) ($r['werte'] ?? []);
        $text = $werte[(string) (int) round($wert)] ?? null;
        if ($text !== null) {
            $kanaele[$kanal]['zustand'][$feld . '_text'] = (string) $text;
        }
    }

    /**
     * Wo nur Phasenwerte kommen, die Summe ergänzen (Leistung, Strom nicht –
     * Ströme addieren sich im Drehstromnetz nicht einfach).
     */
    public static function summenErgaenzen(array $kanaele): array
    {
        foreach ($kanaele as $name => $kanal) {
            $phasen = $kanal['phasen'] ?? [];
            if (isset($phasen['sum']['p'])) {
                continue;
            }
            $summe = null;
            foreach (['L1', 'L2', 'L3'] as $l) {
                if (isset($phasen[$l]['p'])) {
                    $summe = ($summe ?? 0.0) + (float) $phasen[$l]['p'];
                }
            }
            if ($summe !== null) {
                $kanaele[$name]['phasen']['sum']['p'] = round($summe, 3);
            }
        }
        return $kanaele;
    }
}
