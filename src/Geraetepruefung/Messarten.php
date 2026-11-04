<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Berichte\Unterschrift;
use App\Support\Zeit;

/**
 * Wörterbuch und Werkzeuge der Geräteprüfung mit dem BENNING ST 755+.
 *
 * Hier steht an einer Stelle, was Import, Prüflinge, Prüfberichte und PDF
 * gleich verstehen müssen:
 *
 * - **Messarten:** Schritt des Geräts (`SqProcedures.procName`: „RPE“,
 *   „RISO-1“, „Funkt.“ …) → lesbarer Titel ({@see titel()}).
 * - **Grenzwerte:** welche `_gw_`-Spalte der Ergebniszeile (`Ergebnisse`) zu
 *   welchem Schritt gehört, mit Namen und Einheit ({@see grenzwerte()},
 *   {@see GRENZWERTE}). Bewertet wird nie hier – das tut das Prüfgerät.
 * - **Zahlen nur als Text:** REAL-Werte des Geräts tragen float32-Reste
 *   (0.300000011920929); {@see zahl()} macht daraus „0.3“. Gespeichert wird mit
 *   Dezimalpunkt, das Komma setzt erst die Anzeige ({@see anzeige()}).
 * - **Zeiten:** Das Gerät schreibt Ortszeit ohne Zone in mehreren Formaten;
 *   {@see zeitLesen()} macht daraus UTC (ISO 8601), {@see ortsdatum()} und
 *   {@see ortszeit()} wieder Ortszeit, {@see monatePlus()} rechnet Fälligkeiten.
 * - **Norm** des Prüfberichts aus Ablauf und Prüfart ({@see norm()}).
 * - **Gesamtergebnis** aus Schritten, Werten und Sichtprüfung
 *   ({@see ergebnisAbleiten()}) – das Gerät bewertet, hier wird nur abgeleitet.
 * - **Texte** säubern und auf Spaltenlänge kürzen ({@see text()}, {@see absatz()}).
 *
 * Alles ist rein (keine Datenbank, keine Uhr) – bis auf die Zeitzone der
 * Installation als Vorgabe ({@see Zeit::zone()}), die jede Zeitfunktion auch
 * ausdrücklich nimmt.
 */
final class Messarten
{
    /** Hersteller und Typ, wie das Gerät sich in `Ergebnisse_Messgeraet` nennt. */
    public const MESSGERAET = 'BENNING ST 755+';

    /** Schritt des Geräts (procName) → Titel im Prüfbericht. */
    public const TITEL = [
        'RPE'      => 'Schutzleiterwiderstand',
        'RISO-1'   => 'Isolationswiderstand',
        'RISO-2'   => 'Isolationswiderstand Sekundär–PE',
        'RISO-3'   => 'Isolationswiderstand Primär–Sekundär',
        'RISO-4'   => 'Isolationswiderstand berührbare Teile',
        'RISO-IN'  => 'Isolationswiderstand Eingang',
        'RISO-OUT' => 'Isolationswiderstand Ausgang',
        'IPE'      => 'Schutzleiterstrom',
        'IBer.'    => 'Berührungsstrom',
        'Funkt.'   => 'Funktionsprüfung',
        'Kabel'    => 'Leitungsprüfung',
        'Ua'       => 'Ausgangsspannung',
        'PRCD'     => 'PRCD-Prüfung',
    ];

    /**
     * Grenzwerte je Schritt: Spalte der Ergebniszeile (`Ergebnisse`), Name im
     * Bericht, Einheit. NULL oder 0 in der Spalte heißt „kein Grenzwert“.
     * RISO-4 hat keine eigene Spalte – dort steht kein Grenzwert (lieber keiner
     * als ein falscher).
     *
     * @var array<string, list<array{0:string,1:string,2:string}>>
     */
    public const GRENZWERTE = [
        'RPE'      => [['Ergebnisse_RSL_gw_rsl', 'R_PE max', 'Ω']],
        'RISO-1'   => [['Ergebnisse_RISO_gw_riso_prim_pe', 'R_ISO min', 'MΩ'], ['Ergebnisse_RISO_gw_uiso_prim_pe', 'U_ISO', 'V']],
        'RISO-IN'  => [['Ergebnisse_RISO_gw_riso_prim_pe', 'R_ISO min', 'MΩ'], ['Ergebnisse_RISO_gw_uiso_prim_pe', 'U_ISO', 'V']],
        'RISO-OUT' => [['Ergebnisse_RISO_gw_riso_prim_pe', 'R_ISO min', 'MΩ'], ['Ergebnisse_RISO_gw_uiso_prim_pe', 'U_ISO', 'V']],
        'RISO-2'   => [['Ergebnisse_RISO_gw_riso_sek_pe', 'R_ISO min', 'MΩ'], ['Ergebnisse_RISO_gw_uiso_sek_pe', 'U_ISO', 'V']],
        'RISO-3'   => [['Ergebnisse_RISO_gw_riso_prim_sek', 'R_ISO min', 'MΩ'], ['Ergebnisse_RISO_gw_uiso_prim_sek', 'U_ISO', 'V']],
        'IPE'      => [['Ergebnisse_IABL_gw_iabl_rms', 'I_PE max', 'mA']],
        'IBer.'    => [['Ergebnisse_IB_gw_ib_rms', 'I_B max', 'mA']],
        'Funkt.'   => [['Ergebnisse_FM_gw_ifehler', 'I_Ableit max', 'mA']],
        'Kabel'    => [['Ergebnisse_KM_gw_rleitung', 'R_Leitung max', 'Ω']],
        'Ua'       => [['Ergebnisse_Pelv_gw_upelv', 'U_A max', 'V']],
        'PRCD'     => [
            ['Ergebnisse_RCD_gw_iausl_1I', 'Ausl.-Str. max', 'mA'],
            ['Ergebnisse_RCD_gw_tausl_1I', 'Ausl.-Zeit Ix1 max', 'ms'],
            ['Ergebnisse_RCD_gw_tausl_5I', 'Ausl.-Zeit Ix5 max', 'ms'],
            ['Ergebnisse_RCD_gw_tausl_1_2I', 'Ausl.-Zeit Ix1/2 min', 'ms'],
            ['Ergebnisse_RCD_gw_uber', 'Berührungsspg. max', 'V'],
        ],
    ];

    /** Prüfart des Berichts → Text. */
    public const PRUEFARTEN = [
        'wiederholung'   => 'Wiederholungsprüfung',
        'instandsetzung' => 'Prüfung nach Instandsetzung',
    ];

    public const NORM_WIEDERHOLUNG = 'DIN EN 50699 (VDE 0702)';
    public const NORM_INSTANDSETZUNG = 'DIN EN 50678 (VDE 0701)';
    public const NORM_MEDIZIN = 'DIN EN 62353 (VDE 0751-1)';
    public const NORM_SCHWEISSEN = 'DIN EN IEC 60974-4 (VDE 0544-4)';

    /** Format von `geprueft_am` und jeder anderen UTC-Zeit dieses Moduls. */
    public const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Titel eines Schritts; Unbekanntes bleibt der rohe Name des Geräts.
     */
    public static function titel(string $art): string
    {
        return self::TITEL[$art] ?? $art;
    }

    /**
     * Alle Spalten der Ergebniszeile, die {@see grenzwerte()} liest – für eine
     * ausdrückliche Spaltenliste beim Lesen (nie `SELECT *`).
     *
     * @return list<string>
     */
    public static function grenzwertSpalten(): array
    {
        $spalten = [];
        foreach (self::GRENZWERTE as $liste) {
            foreach ($liste as [$spalte]) {
                $spalten[$spalte] = true;
            }
        }
        return array_keys($spalten);
    }

    /**
     * Grenzwerte eines Schritts aus der Ergebniszeile, als Text mit
     * Dezimalpunkt und Einheit („0.3 Ω“). Fehlende Spalten, NULL, 0 und nicht
     * endliche Werte ergeben keinen Eintrag.
     *
     * @param array<string,mixed> $zeile Ergebniszeile (Spaltenname → Wert)
     * @return list<array{name:string,wert:string}>
     */
    public static function grenzwerte(string $art, array $zeile): array
    {
        $aus = [];
        foreach (self::GRENZWERTE[$art] ?? [] as [$spalte, $name, $einheit]) {
            $wert = $zeile[$spalte] ?? null;
            if (!is_int($wert) && !is_float($wert) && !(is_string($wert) && is_numeric($wert))) {
                continue;
            }
            $zahl = (float) $wert;
            if (!is_finite($zahl) || $zahl == 0.0) {
                continue;
            }
            $aus[] = ['name' => $name, 'wert' => self::zahl($zahl) . ' ' . $einheit];
        }
        return $aus;
    }

    /**
     * Eine Zahl des Geräts als Dezimaltext mit Punkt, gerundet auf $stellen
     * signifikante Stellen – float32-Reste verschwinden:
     * 0.300000011920929 → „0.3“, 0.312000006437302 → „0.312“, 100 → „100“,
     * 536.43389892578 → „536.434“. Nie Exponentenschreibweise; nicht endliche
     * Werte → „“.
     */
    public static function zahl(float|int $wert, int $stellen = 6): string
    {
        $wert = (float) $wert;
        if (!is_finite($wert)) {
            return '';
        }
        if ($wert == 0.0) {
            return '0';
        }
        $stellen = max(1, min(15, $stellen));
        $exponent = (int) floor(log10(abs($wert)));
        $nachkomma = $stellen - 1 - $exponent;
        $gerundet = round($wert, $nachkomma);
        // Rundung über eine Zehnerpotenz (999.9996 → 1000): eine Stelle weniger.
        if ($gerundet != 0.0 && (int) floor(log10(abs($gerundet))) > $exponent) {
            $nachkomma--;
            $gerundet = round($wert, $nachkomma);
        }
        $text = number_format($gerundet, max(0, min(30, $nachkomma)), '.', '');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        return $text === '-0' ? '0' : $text;
    }

    /**
     * Eine Eingabe als Dezimaltext mit Punkt („1,5“ → „1.5“, 2 → „2“, „“ → „“);
     * null, wenn es keine nicht negative Dezimalzahl ist. Für Formulare und
     * die Stammdaten der Prüflinge (Leitungslänge, Querschnitt, Nennleistung).
     */
    public static function dezimal(mixed $wert): ?string
    {
        if ($wert === null) {
            return '';
        }
        if (is_float($wert)) {
            return is_finite($wert) && $wert >= 0 ? self::zahl($wert) : null;
        }
        if (is_int($wert)) {
            return $wert >= 0 ? (string) $wert : null;
        }
        if (!is_string($wert)) {
            return null;
        }
        $wert = trim($wert);
        if ($wert === '') {
            return '';
        }
        if (preg_match('/^(\d{1,9})(?:[.,](\d{1,6}))?$/', $wert, $m) !== 1) {
            return null;
        }
        $ganz = ltrim($m[1], '0');
        $ganz = $ganz === '' ? '0' : $ganz;
        $bruch = rtrim($m[2] ?? '', '0');
        return $bruch === '' ? $ganz : $ganz . '.' . $bruch;
    }

    /** Ist $wert eine Dezimalzahl größer 0 („1,5“, „0.75“ – nicht „“, „0“, „0,0“)? */
    public static function positiv(mixed $wert): bool
    {
        $text = self::dezimal($wert);
        return $text !== null && $text !== '' && preg_match('/[1-9]/', $text) === 1;
    }

    /**
     * Für die Anzeige (Ansicht, PDF): Dezimalpunkt zwischen Ziffern → Komma.
     * „0.102 Ω“ → „0,102 Ω“, „>100.0 MΩ“ → „>100,0 MΩ“; anderes bleibt.
     */
    public static function anzeige(string $text): string
    {
        return (string) preg_replace('/(?<=\d)\.(?=\d)/', ',', $text);
    }

    /**
     * Norm des Prüfberichts: Medizinische Geräte („Med.“ im Namen des Ablaufs)
     * nach DIN EN 62353, Schweißgeräte nach DIN EN IEC 60974-4, sonst nach der
     * Prüfart DIN EN 50699 (Wiederholungsprüfung) bzw. DIN EN 50678 (nach
     * Instandsetzung). Eine unbekannte Prüfart gilt als Wiederholungsprüfung.
     */
    public static function norm(string $ablaufName, string $pruefart): string
    {
        if (mb_stripos($ablaufName, 'Med.') !== false) {
            return self::NORM_MEDIZIN;
        }
        if (mb_stripos($ablaufName, 'Schweiß') !== false || mb_stripos($ablaufName, 'Schweiss') !== false) {
            return self::NORM_SCHWEISSEN;
        }
        return $pruefart === 'instandsetzung' ? self::NORM_INSTANDSETZUNG : self::NORM_WIEDERHOLUNG;
    }

    /**
     * Zeit des Geräts (Ortszeit ohne Zone) → UTC als `Y-m-d\TH:i:s\Z`.
     *
     * Formate (Länge 19 bis 27): „2024-04-12 11:56:57“, „2023-02-27T18:38:03.0000000“,
     * „2024-03-27 17:06:35.1234567“ – Trenner Leerzeichen oder `T`, Bruchteile
     * mit 1 bis 7 Stellen (abgeschnitten). „1900-…“ (das „nie“ des Geräts),
     * Jahre vor 1971 oder nach 2100, ungültige Tage und alles andere → null.
     * Eine Zeit in der Stunde der Umstellung auf Winterzeit ist doppelt – PHP
     * nimmt die erste (Sommerzeit); eine in der fehlenden Stunde der Umstellung
     * auf Sommerzeit rückt eine Stunde vor.
     */
    public static function zeitLesen(string $text, ?\DateTimeZone $zone = null): ?string
    {
        $text = trim($text);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,7})?$/', $text, $m) !== 1) {
            return null;
        }
        [$jahr, $monat, $tag, $stunde, $minute, $sekunde] = array_map('intval', array_slice($m, 1, 6));
        if ($jahr < 1971 || $jahr > 2100 || !checkdate($monat, $tag, $jahr) || $stunde > 23 || $minute > 59 || $sekunde > 59) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $jahr, $monat, $tag, $stunde, $minute, $sekunde), $zone ?? Zeit::zone());
        } catch (\Throwable) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format(self::UTC_FORMAT);
    }

    /**
     * UTC-Zeit (ISO 8601) → Ortsdatum `JJJJ-MM-TT` in der Zeitzone der
     * Installation; null bei leer oder ungültig.
     */
    public static function ortsdatum(?string $isoUtc, ?\DateTimeZone $zone = null): ?string
    {
        return self::ortszeit($isoUtc, 'Y-m-d', $zone);
    }

    /**
     * UTC-Zeit (ISO 8601) → Ortszeit im Format $format (Vorgabe wie das Gerät:
     * `Y-m-d H:i:s`); null bei leer oder ungültig.
     */
    public static function ortszeit(?string $isoUtc, string $format = 'Y-m-d H:i:s', ?\DateTimeZone $zone = null): ?string
    {
        $ts = Zeit::ts($isoUtc);
        if ($ts === null) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($zone ?? Zeit::zone())->format($format);
    }

    /**
     * Ortsdatum plus $monate Monate, am Monatsende gekappt
     * (2026-01-31 + 1 → 2026-02-28, 2028-02-29 + 12 → 2029-02-28).
     * Ungültiges Datum → \InvalidArgumentException.
     */
    public static function monatePlus(string $ortsdatum, int $monate): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ortsdatum, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Kein Datum (JJJJ-MM-TT): ' . $ortsdatum);
        }
        $index = (int) $m[1] * 12 + ((int) $m[2] - 1) + $monate;
        $jahr = intdiv($index, 12);
        $monat = $index % 12 + 1;
        $tag = min((int) $m[3], (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $jahr, $monat)))->format('t'));
        return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
    }

    /**
     * Gesamtergebnis einer Prüfung ableiten – bewertet hat das Gerät.
     *
     * Nicht bestanden, sobald ein Schritt `ok = false` hat, ein Wert
     * `gueltig = false`, eine Antwort der Sichtprüfung „nicht_ok“ ist oder das
     * Gerät selbst Messung bzw. Sichtprüfung als nicht bestanden meldet – auch
     * wenn das Gerät insgesamt „bestanden“ sagt. `abweichung` ist true, wenn das
     * Gerät bestanden meldet, abgeleitet aber nicht bestanden herauskommt (der
     * Import nennt das als Hinweis).
     *
     * Sicht: „maengel“ bei einer Antwort „nicht_ok“ oder wenn das Gerät die
     * Sichtprüfung als nicht bestanden meldet, sonst „ok“.
     *
     * @param list<array{ok?:bool,werte?:list<array{gueltig?:bool}>}> $schritte
     * @param list<array{antwort?:string}> $sichtAntworten
     * @return array{ergebnis:string,sicht:string,abweichung:bool}
     */
    public static function ergebnisAbleiten(array $schritte, array $sichtAntworten, bool $geraetMessung, bool $geraetSicht): array
    {
        $fehler = !$geraetMessung || !$geraetSicht;
        $schrittFehler = false;
        foreach ($schritte as $schritt) {
            if (($schritt['ok'] ?? false) !== true) {
                $schrittFehler = true;
            }
            foreach ($schritt['werte'] ?? [] as $wert) {
                if (($wert['gueltig'] ?? false) !== true) {
                    $schrittFehler = true;
                }
            }
        }
        $sichtFehler = !$geraetSicht;
        foreach ($sichtAntworten as $antwort) {
            if (($antwort['antwort'] ?? '') !== 'ok') {
                $sichtFehler = true;
            }
        }
        $nicht = $fehler || $schrittFehler || $sichtFehler;
        return [
            'ergebnis'   => $nicht ? 'nicht_bestanden' : 'bestanden',
            'sicht'      => $sichtFehler ? 'maengel' : 'ok',
            'abweichung' => $nicht && $geraetMessung && $geraetSicht,
        ];
    }

    /**
     * Einzeiliger Text aus dem Gerät oder einem Formular: ungültiges UTF-8
     * ersetzt (mb_scrub), Steuer- und Richtungszeichen raus, Leerraum
     * zusammengefasst, getrimmt, auf $laenge Zeichen gekürzt.
     */
    public static function text(mixed $wert, int $laenge): string
    {
        $s = is_scalar($wert) ? (string) $wert : '';
        return Unterschrift::text(mb_scrub($s, 'UTF-8'), $laenge);
    }

    /** Wie {@see text()}, aber Zeilenumbrüche bleiben (Bemerkungen). */
    public static function absatz(mixed $wert, int $laenge): string
    {
        $s = is_scalar($wert) ? (string) $wert : '';
        return Unterschrift::absatz(mb_scrub($s, 'UTF-8'), $laenge);
    }
}

