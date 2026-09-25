<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Database;

/**
 * Kabel, Kabelwege und Kabelbrücken auf der Karte.
 *
 *   kabel         Verbindung zwischen zwei Anlagen, Abgang → Eingang, mit
 *                 Kabeltyp, Querschnitt und Länge. Aus den Kabeln ergibt
 *                 sich der Verteilbaum (wer speist wen).
 *   kabelweg      Eine Trasse ohne feste Enden – etwa die geplante Route
 *                 über das Gelände, bevor klar ist, welche Kabel hineinkommen.
 *   kabelbruecke  Ein Überfahrschutz, gezeichnet als kurze Strecke quer zum
 *                 Weg, mit Modell und Anzahl der Elemente.
 *
 * Kabel und Kabelwege sind entweder **fest verlegt** (`fest`: Erdkabel vom
 * Hausanschluss zur Energiesäule, Trasse in der Halle – gehören zur Location
 * und gehen beim Location-Abgleich mit) oder mobil (für jede Veranstaltung
 * neu gelegt). Ein festes Kabel endet nur an Feststromanschlüssen;
 * Kabelbrücken sind immer mobil.
 *
 * Punkte stehen wie überall im Plan in Prozent (x, y); die Länge rechnet
 * PlanMassstabService aus dem Maßstab der Etage, solange niemand eine
 * gemessene Länge einträgt.
 */
final class LeitungService
{
    public const ARTEN = [
        'kabel'        => 'Kabel',
        'kabelweg'     => 'Kabelweg',
        'kabelbruecke' => 'Kabelbrücke',
    ];

    /** Übliche Kabeltypen – Vorschläge im Formular, keine Grenze. */
    public const KABELTYPEN = ['H07RN-F', 'H07BQ-F', 'NSHXAFÖ', 'NYY-J', 'Powerlock-Einleiter', 'Titanex'];

    /** Übliche Querschnitte in mm². */
    public const QUERSCHNITTE = [1.5, 2.5, 4, 6, 10, 16, 25, 35, 50, 70, 95, 120, 150, 185, 240];

    /**
     * Richtwerte der Strombelastbarkeit von Gummischlauchleitungen
     * (drei belastete Adern, frei in Luft, 30 °C, nach DIN VDE 0298-4).
     * Ein Planungshinweis – Häufung, Trommel und Sonne mindern das weiter.
     */
    public const BELASTBARKEIT = [
        '1.5' => 23, '2.5' => 30, '4' => 41, '6' => 53, '10' => 74, '16' => 99, '25' => 131,
        '35' => 162, '50' => 202, '70' => 250, '95' => 301, '120' => 352, '150' => 404,
        '185' => 461, '240' => 540,
    ];

    /** Elektrische Leitfähigkeit von Kupfer in m/(Ω·mm²). */
    public const KAPPA_CU = 56.0;

    public const BRUECKEN = ['1-Kanal', '2-Kanal', '3-Kanal', '4-Kanal', '5-Kanal', 'Defender Midi', 'Defender Mega', 'Gummi-Kabelschutz'];

    /** Mehr Stützpunkte hat kein vernünftiger Kabelweg. */
    public const MAX_PUNKTE = 300;

    public static function find(int $id): ?array
    {
        $l = Database::first(
            'SELECT l.*, ov.name AS von_name, oz.name AS zu_name,
                    av.bezeichnung AS von_anschluss, az.bezeichnung AS zu_anschluss,
                    tv.name AS von_typ, tz.name AS zu_typ, tv.strom_a AS von_strom_a, tz.strom_a AS zu_strom_a
             FROM leitungen l
             LEFT JOIN objekte ov ON ov.id = l.von_objekt_id LEFT JOIN objekte oz ON oz.id = l.zu_objekt_id
             LEFT JOIN anschluesse av ON av.id = l.von_anschluss_id LEFT JOIN anschluesse az ON az.id = l.zu_anschluss_id
             LEFT JOIN steckertypen tv ON tv.id = av.steckertyp_id LEFT JOIN steckertypen tz ON tz.id = az.steckertyp_id
             WHERE l.id = ?',
            [$id]
        );
        return $l === null ? null : self::aufbereiten($l);
    }

    /** @return list<array<string,mixed>> */
    public static function fuerEtage(int $floorId): array
    {
        $rows = Database::select(
            'SELECT l.*, ov.name AS von_name, oz.name AS zu_name,
                    av.bezeichnung AS von_anschluss, az.bezeichnung AS zu_anschluss,
                    tv.name AS von_typ, tz.name AS zu_typ, tv.strom_a AS von_strom_a, tz.strom_a AS zu_strom_a
             FROM leitungen l
             LEFT JOIN objekte ov ON ov.id = l.von_objekt_id LEFT JOIN objekte oz ON oz.id = l.zu_objekt_id
             LEFT JOIN anschluesse av ON av.id = l.von_anschluss_id LEFT JOIN anschluesse az ON az.id = l.zu_anschluss_id
             LEFT JOIN steckertypen tv ON tv.id = av.steckertyp_id LEFT JOIN steckertypen tz ON tz.id = az.steckertyp_id
             WHERE l.floor_id = ? ORDER BY l.art, l.id',
            [$floorId]
        );
        $massstab = PlanMassstabService::fuerEtage($floorId);
        return array_map(static fn (array $l): array => self::aufbereiten($l, $massstab), $rows);
    }

    /** @return list<array<string,mixed>> Kabel an einer Anlage (beide Richtungen) */
    public static function anObjekt(int $objektId): array
    {
        $rows = Database::select(
            "SELECT id FROM leitungen WHERE art = 'kabel' AND (von_objekt_id = ? OR zu_objekt_id = ?) ORDER BY id",
            [$objektId, $objektId]
        );
        return array_values(array_filter(array_map(static fn ($r) => self::find((int) $r['id']), $rows)));
    }

    private static function aufbereiten(array $l, ?array $massstab = null): array
    {
        $l['punkte'] = self::punkteLesen((string) ($l['punkte'] ?? ''));
        $l['fest'] = (int) ($l['fest'] ?? 0);
        if ($massstab === null && $l['floor_id'] !== null) {
            $massstab = PlanMassstabService::fuerEtage((int) $l['floor_id']);
        }
        $l['laenge_plan_m'] = PlanMassstabService::meter($l['punkte'], $massstab);
        $l['laenge'] = $l['laenge_m'] !== null ? (float) $l['laenge_m'] : $l['laenge_plan_m'];
        $l['belastbarkeit_a'] = self::belastbarkeit($l['querschnitt_mm2'] !== null ? (float) $l['querschnitt_mm2'] : null);
        $l['hinweise'] = self::hinweise($l);
        return $l;
    }

    public static function belastbarkeit(?float $querschnitt): ?int
    {
        if ($querschnitt === null || $querschnitt <= 0) {
            return null;
        }
        $schluessel = rtrim(rtrim(number_format($querschnitt, 1, '.', ''), '0'), '.');
        return self::BELASTBARKEIT[$schluessel] ?? null;
    }

    /**
     * Spannungsfall in Prozent.
     *
     * Drehstrom: ΔU = √3 · I · L · cos φ / (κ · A), bezogen auf 400 V.
     * Wechselstrom: ΔU = 2 · I · L · cos φ / (κ · A), bezogen auf 230 V.
     * Blindanteil der Leitung vernachlässigt – für Querschnitte bis 35 mm²
     * und Veranstaltungslängen die übliche Näherung.
     */
    public static function spannungsfall(float $strom, float $laenge, float $querschnitt, int $phasen = 3, float $cosPhi = 0.95): ?float
    {
        if ($strom <= 0 || $laenge <= 0 || $querschnitt <= 0) {
            return null;
        }
        if ($phasen === 1) {
            return (2 * $strom * $laenge * $cosPhi) / (self::KAPPA_CU * $querschnitt) / 230 * 100;
        }
        return (sqrt(3) * $strom * $laenge * $cosPhi) / (self::KAPPA_CU * $querschnitt) / 400 * 100;
    }

    /**
     * Was an einem Kabel auffällt – ohne Messwerte, nur aus der Planung.
     *
     * @return list<string>
     */
    public static function hinweise(array $l): array
    {
        if ((string) $l['art'] !== 'kabel') {
            return [];
        }
        $out = [];
        $stromStecker = max((float) ($l['von_strom_a'] ?? 0), (float) ($l['zu_strom_a'] ?? 0));
        $belastbar = $l['belastbarkeit_a'] ?? null;
        if ($belastbar !== null && $stromStecker > 0 && $stromStecker > $belastbar && $stromStecker < 400) {
            $out[] = sprintf('Querschnitt %s mm² trägt rund %d A, der Stecker ist für %d A ausgelegt.',
                str_replace('.', ',', (string) (float) $l['querschnitt_mm2']), $belastbar, (int) $stromStecker);
        }
        if (!empty($l['von_typ']) && !empty($l['zu_typ']) && $l['von_typ'] !== $l['zu_typ']) {
            $out[] = 'Abgang (' . $l['von_typ'] . ') und Eingang (' . $l['zu_typ'] . ') haben verschiedene Stecker – Adapter nötig.';
        }
        $laenge = $l['laenge'] ?? null;
        if ($laenge !== null && $l['querschnitt_mm2'] !== null && $stromStecker > 0 && $stromStecker < 400) {
            $du = self::spannungsfall($stromStecker, (float) $laenge, (float) $l['querschnitt_mm2']);
            $grenze = (float) (\App\Support\Config::get('grenzen.spannungsfall', 3) ?: 3);
            if ($du !== null && $du > $grenze) {
                $out[] = sprintf('Spannungsfall bei Nennstrom %d A: %s %% (Grenze %s %%).',
                    (int) $stromStecker, number_format($du, 1, ',', ''), number_format($grenze, 1, ',', ''));
            }
        }
        return $out;
    }

    /**
     * Leitung speichern.
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $art = (string) ($daten['art'] ?? 'kabel');
        if (!isset(self::ARTEN[$art])) {
            return ['ok' => false, 'message' => 'Unbekannte Art der Leitung.'];
        }
        $punkte = self::punkteBereinigen($daten['punkte'] ?? []);
        $floorId = (int) ($daten['floor_id'] ?? 0) ?: null;
        if ($floorId !== null && Database::first('SELECT id FROM floors WHERE id = ?', [$floorId]) === null) {
            return ['ok' => false, 'message' => 'Diese Etage gibt es nicht.'];
        }
        if ($art !== 'kabel' && count($punkte) < 2) {
            return ['ok' => false, 'message' => 'Ein ' . self::ARTEN[$art] . ' braucht mindestens zwei Punkte.'];
        }

        $felder = [
            'art'             => $art,
            'floor_id'        => $floorId,
            'name'            => mb_substr(trim((string) ($daten['name'] ?? '')), 0, 190),
            'kabeltyp'        => mb_substr(trim((string) ($daten['kabeltyp'] ?? '')), 0, 64),
            'adern'           => ($daten['adern'] ?? '') === '' ? null : max(1, (int) $daten['adern']),
            'querschnitt_mm2' => self::zahl($daten['querschnitt_mm2'] ?? ''),
            'laenge_m'        => self::zahl($daten['laenge_m'] ?? ''),
            'anzahl'          => max(1, (int) ($daten['anzahl'] ?? 1)),
            'modell'          => mb_substr(trim((string) ($daten['modell'] ?? '')), 0, 190),
            'punkte'          => json_encode($punkte),
            'farbe'           => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($daten['farbe'] ?? '')) === 1 ? (string) $daten['farbe'] : '',
            'notizen'         => (string) ($daten['notizen'] ?? ''),
            'von_objekt_id'   => null,
            'von_anschluss_id'=> null,
            'zu_objekt_id'    => null,
            'zu_anschluss_id' => null,
            'updated_at'      => gmdate('c'),
        ];

        if ($art === 'kabel') {
            $pruefung = self::endenPruefen($id, $daten);
            if (!$pruefung['ok']) {
                return $pruefung;
            }
            $felder = array_merge($felder, $pruefung['felder']);
            if ($felder['von_objekt_id'] === null && $felder['zu_objekt_id'] === null && count($punkte) < 2) {
                return ['ok' => false, 'message' => 'Ein Kabel braucht zwei Enden oder einen eingezeichneten Weg.'];
            }
        }

        $alt = $id !== null ? Database::first('SELECT id, fest FROM leitungen WHERE id = ?', [$id]) : null;
        if ($id !== null && $alt === null) {
            return ['ok' => false, 'message' => 'Diese Leitung gibt es nicht.'];
        }
        // Fest oder mobil: was gesagt wird, gilt; beim Bearbeiten ohne Angabe
        // bleibt es, wie es war. Ein neues Kabel zwischen zwei
        // Feststromanschlüssen ist fest verlegt, alles andere mobil.
        $festAngabe = $daten['fest'] ?? null;
        $ausdruecklich = $festAngabe !== null && $festAngabe !== '';
        if ($art === 'kabelbruecke') {
            $felder['fest'] = 0;
        } elseif ($ausdruecklich) {
            $felder['fest'] = !empty($festAngabe) ? 1 : 0;
        } elseif ($alt !== null) {
            $felder['fest'] = (int) $alt['fest'];
        } else {
            $felder['fest'] = $art === 'kabel' && $felder['von_objekt_id'] !== null && $felder['zu_objekt_id'] !== null
                && self::endeFest($felder['von_objekt_id']) && self::endeFest($felder['zu_objekt_id']) ? 1 : 0;
        }
        // Ein festes Kabel endet nur an Feststromanschlüssen. Wer es
        // ausdrücklich fest haben will, bekommt den Grund gesagt; wird ein
        // festes Kabel an eine mobile Anlage umgesteckt, wird es mobil.
        $hinweis = '';
        if ($felder['fest'] === 1 && $art === 'kabel') {
            foreach ([$felder['von_objekt_id'], $felder['zu_objekt_id']] as $objektId) {
                if ($objektId !== null && !self::endeFest($objektId)) {
                    $name = (string) Database::scalar('SELECT name FROM objekte WHERE id = ?', [$objektId]);
                    if ($ausdruecklich) {
                        return ['ok' => false, 'message' => 'Ein fest verlegtes Kabel endet nur an Feststromanschlüssen – „' . $name . '“ ist mobil.'];
                    }
                    $felder['fest'] = 0;
                    $hinweis = ' Es ist jetzt mobil, weil „' . $name . '“ mobil ist.';
                }
            }
        }

        if ($id !== null) {
            Database::update('leitungen', $id, $felder);
            Audit::log('leitung.update', 'leitung', (string) $id, ['art' => $art]);
            return ['ok' => true, 'message' => self::ARTEN[$art] . ' gespeichert.' . $hinweis, 'id' => $id];
        }
        $felder['created_at'] = gmdate('c');
        $id = Database::insert('leitungen', $felder);
        Audit::log('leitung.create', 'leitung', (string) $id, ['art' => $art]);
        return ['ok' => true, 'message' => self::ARTEN[$art] . ' eingezeichnet.', 'id' => $id];
    }

    /**
     * Ein Kabelende umstecken oder lösen – beim Ziehen des Endpunkts auf der
     * Karte. An einer anderen Anlage ist der Anschluss erst offen und wird im
     * Kabelfenster gewählt; bleibt die Anlage dieselbe, bleibt er stecken.
     *
     * @param ?int $objektId neue Anlage am Ende, null = offenes Ende
     */
    public static function endeSetzen(int $id, string $ende, ?int $objektId, mixed $punkte): array
    {
        $l = self::find($id);
        if ($l === null || $l['art'] !== 'kabel') {
            return ['ok' => false, 'message' => 'Nur ein Kabel hat Enden zum Umstecken.'];
        }
        if (!in_array($ende, ['von', 'zu'], true)) {
            return ['ok' => false, 'message' => 'Unbekanntes Kabelende.'];
        }
        $daten = self::alsDaten($l);
        $daten['punkte'] = $punkte;
        $bisher = $l[$ende . '_objekt_id'] !== null ? (int) $l[$ende . '_objekt_id'] : null;
        if ($objektId !== $bisher) {
            $daten[$ende . '_objekt_id'] = $objektId;
            $daten[$ende . '_anschluss_id'] = null;
        }
        $ergebnis = self::speichern($id, $daten);
        if (!$ergebnis['ok'] || $objektId === $bisher) {
            return $ergebnis;
        }
        if ($objektId === null) {
            return ['ok' => true, 'id' => $id, 'message' => 'Kabelende gelöst – zum Anschließen auf eine Anlage ziehen.'];
        }
        $hinweis = str_contains($ergebnis['message'], 'jetzt mobil') ? ' Es ist jetzt mobil.' : '';
        $name = (string) Database::scalar('SELECT name FROM objekte WHERE id = ?', [$objektId]);
        return ['ok' => true, 'id' => $id, 'message' => 'Kabel an „' . $name . '“ angeschlossen – den '
            . ($ende === 'von' ? 'Abgang' : 'Eingang') . ' im Kabelfenster wählen.' . $hinweis];
    }

    /** Eine gespeicherte Leitung als Eingabe für speichern() – ohne „fest“, das bleibt, wie es ist. */
    private static function alsDaten(array $l): array
    {
        return [
            'art' => $l['art'], 'floor_id' => $l['floor_id'], 'name' => $l['name'], 'kabeltyp' => $l['kabeltyp'],
            'adern' => $l['adern'] ?? '', 'querschnitt_mm2' => $l['querschnitt_mm2'] ?? '', 'laenge_m' => $l['laenge_m'] ?? '',
            'anzahl' => $l['anzahl'], 'modell' => $l['modell'], 'punkte' => $l['punkte'], 'farbe' => $l['farbe'], 'notizen' => $l['notizen'],
            'von_objekt_id' => $l['von_objekt_id'], 'von_anschluss_id' => $l['von_anschluss_id'],
            'zu_objekt_id' => $l['zu_objekt_id'], 'zu_anschluss_id' => $l['zu_anschluss_id'],
        ];
    }

    /** Ist die Anlage an diesem Kabelende fest installiert? */
    private static function endeFest(int $objektId): bool
    {
        return (int) Database::scalar('SELECT fest FROM objekte WHERE id = ?', [$objektId]) === 1;
    }

    /**
     * Enden eines Kabels prüfen: Abgang an der einen, Eingang an der
     * anderen Anlage, und beide frei.
     *
     * @return array{ok:bool,message:string,felder:array<string,?int>}
     */
    private static function endenPruefen(?int $id, array $daten): array
    {
        $felder = ['von_objekt_id' => null, 'von_anschluss_id' => null, 'zu_objekt_id' => null, 'zu_anschluss_id' => null];
        $fehler = static fn (string $m): array => ['ok' => false, 'message' => $m, 'felder' => []];

        foreach (['von' => 'abgang', 'zu' => 'eingang'] as $ende => $richtung) {
            $objektId = (int) ($daten[$ende . '_objekt_id'] ?? 0);
            if ($objektId <= 0) {
                continue;
            }
            if (Database::first('SELECT id FROM objekte WHERE id = ?', [$objektId]) === null) {
                return $fehler('Die Anlage am Kabelende gibt es nicht.');
            }
            $felder[$ende . '_objekt_id'] = $objektId;
            $anschlussId = (int) ($daten[$ende . '_anschluss_id'] ?? 0);
            if ($anschlussId <= 0) {
                continue;
            }
            $anschluss = Database::first('SELECT * FROM anschluesse WHERE id = ? AND objekt_id = ?', [$anschlussId, $objektId]);
            if ($anschluss === null) {
                return $fehler('Der gewählte Anschluss gehört nicht zu der Anlage.');
            }
            if ($anschluss['richtung'] !== $richtung) {
                return $fehler($ende === 'von'
                    ? 'Ein Kabel beginnt an einem Abgang, nicht an einem Eingang.'
                    : 'Ein Kabel endet an einem Eingang, nicht an einem Abgang.');
            }
            $belegt = Database::first(
                "SELECT id FROM leitungen WHERE art = 'kabel' AND (von_anschluss_id = ? OR zu_anschluss_id = ?)" . ($id !== null ? ' AND id <> ?' : ''),
                $id !== null ? [$anschlussId, $anschlussId, $id] : [$anschlussId, $anschlussId]
            );
            if ($belegt !== null) {
                return $fehler('Am Anschluss ' . $anschluss['bezeichnung'] . ' hängt schon ein Kabel.');
            }
            $felder[$ende . '_anschluss_id'] = $anschlussId;
        }
        if ($felder['von_objekt_id'] !== null && $felder['von_objekt_id'] === $felder['zu_objekt_id']) {
            return $fehler('Ein Kabel verbindet zwei verschiedene Anlagen.');
        }
        return ['ok' => true, 'message' => '', 'felder' => $felder];
    }

    public static function loeschen(int $id): array
    {
        $l = self::find($id);
        if ($l === null) {
            return ['ok' => false, 'message' => 'Diese Leitung gibt es nicht.'];
        }
        Database::delete('leitungen', $id);
        Audit::log('leitung.delete', 'leitung', (string) $id, ['art' => $l['art']]);
        return ['ok' => true, 'message' => self::ARTEN[$l['art']] . ' entfernt.'];
    }

    /** Nur den Verlauf ändern (Ziehen in der Karte). */
    public static function punkteSetzen(int $id, mixed $punkte): array
    {
        $punkte = self::punkteBereinigen($punkte);
        Database::update('leitungen', $id, ['punkte' => json_encode($punkte), 'updated_at' => gmdate('c')]);
        return ['ok' => true, 'message' => 'Verlauf gespeichert.'];
    }

    /** @return list<array{x:float,y:float}> */
    public static function punkteLesen(string $json): array
    {
        return self::punkteBereinigen(json_decode($json, true));
    }

    /** @return list<array{x:float,y:float}> */
    public static function punkteBereinigen(mixed $roh): array
    {
        if (is_string($roh)) {
            $roh = json_decode($roh, true);
        }
        if (!is_array($roh)) {
            return [];
        }
        $out = [];
        foreach (array_slice($roh, 0, self::MAX_PUNKTE) as $p) {
            if (is_array($p) && isset($p['x'], $p['y'])) {
                $x = (float) $p['x'];
                $y = (float) $p['y'];
            } elseif (is_array($p) && isset($p[0], $p[1])) {
                $x = (float) $p[0];
                $y = (float) $p[1];
            } else {
                continue;
            }
            if (!is_finite($x) || !is_finite($y)) {
                continue;
            }
            $out[] = ['x' => round(max(0, min(100, $x)), 4), 'y' => round(max(0, min(100, $y)), 4)];
        }
        return $out;
    }

    /** Summen einer Etage: Kabelmeter je Querschnitt, Brückenelemente. */
    public static function stueckliste(int $floorId): array
    {
        $kabel = [];
        $bruecken = [];
        $ohneLaenge = 0;
        foreach (self::fuerEtage($floorId) as $l) {
            if ($l['art'] === 'kabel') {
                $schluessel = trim(($l['kabeltyp'] ?: 'Kabel') . ' ' . ($l['adern'] ? $l['adern'] . 'G' : '')
                    . ($l['querschnitt_mm2'] !== null ? str_replace('.', ',', (string) (float) $l['querschnitt_mm2']) : ''));
                $kabel[$schluessel] ??= ['anzahl' => 0, 'meter' => 0.0];
                $kabel[$schluessel]['anzahl']++;
                if ($l['laenge'] !== null) {
                    $kabel[$schluessel]['meter'] += (float) $l['laenge'];
                } else {
                    $ohneLaenge++;
                }
            } elseif ($l['art'] === 'kabelbruecke') {
                $schluessel = $l['modell'] !== '' ? $l['modell'] : 'Kabelbrücke';
                $bruecken[$schluessel] = ($bruecken[$schluessel] ?? 0) + (int) $l['anzahl'];
            }
        }
        ksort($kabel);
        ksort($bruecken);
        return ['kabel' => $kabel, 'bruecken' => $bruecken, 'ohne_laenge' => $ohneLaenge];
    }

    private static function zahl(mixed $wert): ?float
    {
        $wert = trim(str_replace(',', '.', (string) $wert));
        return $wert === '' || !is_numeric($wert) ? null : (float) $wert;
    }
}
