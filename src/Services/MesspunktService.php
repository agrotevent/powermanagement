<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Session;

/**
 * Messgeräte in Anlagen.
 *
 * Ein Messpunkt sagt: dieser Kanal dieses Geräts (z. B. Shelly Pro 3EM,
 * Kanal em:0) misst hier – an der Einspeisung der Verteilung, hinter einem
 * RCD oder an einem einzelnen Abgang. Über den Messpunkt findet die Karte
 * ihre Farbe (Auslastung), der Verteilerplan seine Livewerte und die
 * Auswertung ihre Reihen.
 *
 * Die „Auswertung“ ist eine Auswahl von Messpunkten, die in der Sitzung
 * liegt: ein Klick auf ein Messgerät in Karte oder Verteilerplan nimmt es
 * auf, die Statistikseite zeigt alles Aufgenommene nebeneinander.
 */
final class MesspunktService
{
    public const ROLLEN = [
        'einspeisung' => 'Einspeisung',
        'abgang'      => 'Abgang',
        'verbrauch'   => 'Verbraucher',
        'erzeugung'   => 'Erzeugung',
        'batterie'    => 'Batterie',
    ];

    public const SITZUNG = 'auswertung';

    /** @return list<array<string,mixed>> */
    public static function fuerObjekt(int $objektId): array
    {
        return Database::select(
            'SELECT m.*, a.bezeichnung AS anschluss_name, s.bezeichnung AS schutz_name, d.id AS datenquelle_id, d.name AS datenquelle_name
             FROM messpunkte m
             LEFT JOIN anschluesse a ON a.id = m.anschluss_id
             LEFT JOIN schutzorgane s ON s.id = m.schutzorgan_id
             LEFT JOIN datenquellen d ON d.geraet = m.geraet
             WHERE m.objekt_id = ? ORDER BY m.rolle, m.id',
            [$objektId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT m.*, o.name AS objekt_name, o.art AS objekt_art, o.site_id, o.id AS objekt_id,
                    a.bezeichnung AS anschluss_name, s.bezeichnung AS schutz_name
             FROM messpunkte m JOIN objekte o ON o.id = m.objekt_id
             LEFT JOIN anschluesse a ON a.id = m.anschluss_id LEFT JOIN schutzorgane s ON s.id = m.schutzorgan_id
             WHERE m.id = ?',
            [$id]
        );
    }

    /** Lesbarer Name: „Hauptverteiler · Einspeisung (em:0)“. */
    public static function titel(array $m): string
    {
        $teile = [(string) ($m['objekt_name'] ?? '')];
        $bezeichnung = trim((string) ($m['bezeichnung'] ?? ''));
        if ($bezeichnung !== '') {
            $teile[] = $bezeichnung;
        } elseif (!empty($m['anschluss_name'])) {
            $teile[] = 'Abgang ' . $m['anschluss_name'];
        } elseif (!empty($m['schutz_name'])) {
            $teile[] = 'hinter ' . $m['schutz_name'];
        } else {
            $teile[] = self::ROLLEN[$m['rolle']] ?? $m['rolle'];
        }
        return implode(' · ', array_filter($teile));
    }

    public static function speichern(int $objektId, ?int $id, array $daten): array
    {
        $geraet = trim((string) ($daten['geraet'] ?? ''));
        $kanal = trim((string) ($daten['kanal'] ?? ''));
        if ($geraet === '') {
            return ['ok' => false, 'message' => 'Bitte ein Gerät wählen.'];
        }
        $anschluss = (int) ($daten['anschluss_id'] ?? 0);
        if ($anschluss > 0 && Database::first('SELECT id FROM anschluesse WHERE id = ? AND objekt_id = ?', [$anschluss, $objektId]) === null) {
            $anschluss = 0;
        }
        $schutz = (int) ($daten['schutzorgan_id'] ?? 0);
        if ($schutz > 0 && Database::first('SELECT id FROM schutzorgane WHERE id = ? AND objekt_id = ?', [$schutz, $objektId]) === null) {
            $schutz = 0;
        }
        $rolle = isset(self::ROLLEN[$daten['rolle'] ?? '']) ? (string) $daten['rolle'] : ($anschluss > 0 ? 'abgang' : 'einspeisung');
        $felder = [
            'objekt_id'      => $objektId,
            'geraet'         => mb_substr($geraet, 0, 128),
            'kanal'          => mb_substr($kanal, 0, 64),
            'rolle'          => $rolle,
            'bezeichnung'    => mb_substr(trim((string) ($daten['bezeichnung'] ?? '')), 0, 190),
            'anschluss_id'   => $anschluss > 0 ? $anschluss : null,
            'schutzorgan_id' => $schutz > 0 ? $schutz : null,
        ];
        if ($id !== null) {
            if (Database::first('SELECT id FROM messpunkte WHERE id = ? AND objekt_id = ?', [$id, $objektId]) === null) {
                return ['ok' => false, 'message' => 'Diesen Messpunkt gibt es an der Anlage nicht.'];
            }
            Database::update('messpunkte', $id, $felder);
        } else {
            $felder['created_at'] = gmdate('c');
            $id = Database::insert('messpunkte', $felder);
        }
        Zugriff::vergessen();
        Audit::log('messpunkt.speichern', 'objekt', (string) $objektId, ['geraet' => $geraet, 'kanal' => $kanal]);
        return ['ok' => true, 'message' => 'Messgerät zugeordnet.', 'id' => $id];
    }

    public static function loeschen(int $objektId, int $id): array
    {
        if (Database::execute('DELETE FROM messpunkte WHERE id = ? AND objekt_id = ?', [$id, $objektId]) > 0) {
            BereichService::messpunktEntfernt($id);
        }
        self::ausAuswertung($id);
        Zugriff::vergessen();
        Audit::log('messpunkt.loeschen', 'objekt', (string) $objektId, ['messpunkt' => $id]);
        return ['ok' => true, 'message' => 'Messgerät entfernt.'];
    }

    /** Der Messpunkt, der für die ganze Anlage steht (Einspeisung bzw. Erzeugung). */
    public static function haupt(array $messpunkte): ?array
    {
        foreach (['einspeisung', 'erzeugung', 'batterie', 'verbrauch', 'abgang'] as $rolle) {
            foreach ($messpunkte as $m) {
                if ($m['rolle'] === $rolle && $m['anschluss_id'] === null) {
                    return $m;
                }
            }
        }
        return $messpunkte[0] ?? null;
    }

    /**
     * Nennstrom einer Anlage je Phase: eingetragen, sonst aus der Leistung
     * (Generator), sonst aus dem stärksten Eingang, sonst aus dem Abgang.
     */
    public static function nennstrom(array $objekt, array $anschluesse = []): ?float
    {
        if (!empty($objekt['nennstrom_a'])) {
            return (float) $objekt['nennstrom_a'];
        }
        if (!empty($objekt['nennleistung_kva'])) {
            $phasen = (int) ($objekt['phasen'] ?? 3);
            return $phasen === 1
                ? (float) $objekt['nennleistung_kva'] * 1000 / 230
                : (float) $objekt['nennleistung_kva'] * 1000 / (sqrt(3) * 400);
        }
        $eingang = 0.0;
        $abgang = 0.0;
        foreach ($anschluesse as $a) {
            if ($a['richtung'] === 'eingang') {
                $eingang = max($eingang, (float) $a['strom_a']);
            } else {
                $abgang = max($abgang, (float) $a['strom_a']);
            }
        }
        if ($eingang > 0 && $eingang < 400) {
            return $eingang;
        }
        return $objekt['art'] === 'anschluss' && $abgang > 0 && $abgang < 400 ? $abgang : null;
    }

    /**
     * Auslastung aus Livewerten: höchster Phasenstrom durch Nennstrom.
     *
     * @param array{phasen?:array<string,array<string,float>>}|null $live
     */
    public static function auslastung(?array $live, ?float $nennstrom): ?float
    {
        if ($live === null || $nennstrom === null || $nennstrom <= 0) {
            return null;
        }
        $maxStrom = null;
        foreach (['L1', 'L2', 'L3'] as $l) {
            if (isset($live['phasen'][$l]['i'])) {
                $maxStrom = max($maxStrom ?? 0.0, (float) $live['phasen'][$l]['i']);
            }
        }
        if ($maxStrom === null && isset($live['phasen']['sum']['i'])) {
            $maxStrom = (float) $live['phasen']['sum']['i'];
        }
        if ($maxStrom === null && isset($live['phasen']['sum']['p'])) {
            $maxStrom = (float) $live['phasen']['sum']['p'] / (sqrt(3) * 400 * 0.95);
        }
        return $maxStrom === null ? null : round($maxStrom / $nennstrom * 100, 1);
    }

    /**
     * Livewerte für eine Liste von Messpunkten – nur, wo der Benutzer jetzt
     * Daten sehen darf.
     *
     * @param list<array<string,mixed>> $messpunkte
     * @param array<int,array<string,mixed>> $objekte objekt_id => Objekt (für das Datenfenster)
     * @return array<int,array<string,mixed>> messpunkt_id => Livewerte
     */
    public static function live(array $messpunkte, array $objekte): array
    {
        $erlaubt = [];
        foreach ($messpunkte as $m) {
            $objekt = $objekte[(int) $m['objekt_id']] ?? null;
            if ($objekt !== null && Zugriff::liveErlaubt(Zugriff::datenFenster($objekt))) {
                $erlaubt[] = $m;
            }
        }
        $werte = Messdaten::letzte(array_map(static fn ($m) => [(string) $m['geraet'], (string) $m['kanal']], $erlaubt));
        $out = [];
        foreach ($erlaubt as $m) {
            $w = $werte[$m['geraet'] . '|' . $m['kanal']] ?? null;
            if ($w !== null) {
                $out[(int) $m['id']] = $w;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ Auswertung

    /** @return list<int> */
    public static function auswertung(): array
    {
        return array_values(array_map('intval', (array) Session::get(self::SITZUNG, [])));
    }

    /** Aufnehmen bzw. wieder herausnehmen. true = jetzt enthalten. */
    public static function umschalten(int $messpunktId): bool
    {
        $liste = self::auswertung();
        if (in_array($messpunktId, $liste, true)) {
            Session::put(self::SITZUNG, array_values(array_diff($liste, [$messpunktId])));
            return false;
        }
        $liste[] = $messpunktId;
        Session::put(self::SITZUNG, array_slice(array_values(array_unique($liste)), -12));
        return true;
    }

    public static function ausAuswertung(int $messpunktId): void
    {
        Session::put(self::SITZUNG, array_values(array_diff(self::auswertung(), [$messpunktId])));
    }

    public static function auswertungLeeren(): void
    {
        Session::put(self::SITZUNG, []);
    }
}
