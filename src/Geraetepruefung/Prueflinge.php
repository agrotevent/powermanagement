<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Auth\Auth;
use App\Support\Database;

/**
 * Prüflinge der Geräteprüfung (Tabelle `gp_prueflinge`) – Grundteil.
 *
 * Ein Prüfling gehört einem **Besitzer**: dem eigenen Bestand (`kunde_id`
 * NULL, optional mit einem Inventarstück verknüpft) oder einem Kunden
 * (`kunde_id`). Seine Kennung (Prüflings-ID bzw. Barcode am Gerät,
 * `Geraete_ID`) ist je Besitzer eindeutig, ohne Groß- und Kleinschreibung –
 * dieselbe Kennung darf bei zwei Kunden vorkommen (wie im Gerät).
 *
 * Diese Klasse ist die Datenschicht **ohne Rechteprüfung** für Import,
 * Oberfläche und Prüfskripte: {@see anlegen()} und {@see aendern()} säubern
 * und kürzen Texte auf die Spaltenlänge und halten die Form ein (Kennung,
 * Schutzklasse, Ergebnis, Daten, Zahlen als Dezimaltext). Ob jemand das darf,
 * ob Kunde, Inventarstück (nie ein Tanksensor, nie doppelt) und Prüfablauf
 * passen, prüft der Aufrufer – mit eigenen Meldungen. Formfehler werfen
 * \InvalidArgumentException.
 *
 * Fälligkeit: {@see faelligkeitNeu()} rechnet `letzte_pruefung`,
 * `letztes_ergebnis` und `naechste_pruefung` aus den Prüfungen hier neu –
 * nach jedem Import und nach jeder Änderung des Intervalls.
 */
final class Prueflinge
{
    public const SCHUTZKLASSEN = [
        '1' => 'Schutzklasse I',
        '2' => 'Schutzklasse II',
        '3' => 'Schutzklasse III',
    ];

    public const ERGEBNISSE = [
        ''                => 'noch nicht geprüft',
        'bestanden'       => 'bestanden',
        'nicht_bestanden' => 'nicht bestanden',
    ];

    /** Textspalten → Höchstlänge (wie in Migration 019). */
    public const TEXTE = [
        'bezeichnung' => 120, 'hersteller' => 80, 'typ' => 80, 'modell' => 80, 'seriennummer' => 80,
        'abteilung' => 80, 'gebaeude' => 80, 'bemerkung' => 500,
    ];

    /** Zahlen als Dezimaltext mit Punkt → Höchstlänge. */
    public const ZAHLEN = ['leitungslaenge' => 16, 'querschnitt' => 16, 'leiter' => 8, 'nennleistung' => 16];

    public const KENNUNG_LAENGE = 50;

    /**
     * Alle Spalten, die {@see anlegen()} und {@see aendern()} schreiben
     * (`eindeutig` rechnet die Klasse selbst).
     */
    public const FELDER = [
        'kunde_id', 'inventar_id', 'kennung', 'bezeichnung', 'hersteller', 'typ', 'modell', 'seriennummer',
        'abteilung', 'gebaeude', 'bemerkung', 'schutzklasse', 'pruefablauf_id', 'intervall_monate',
        'leitungslaenge', 'querschnitt', 'leiter', 'nennleistung', 'ausser_betrieb', 'letzte_pruefung',
        'letztes_ergebnis', 'naechste_pruefung', 'technik', 'created_by',
    ];

    /**
     * Die Kennung, wie sie gespeichert wird: gesäubert, getrimmt (führende
     * Leerzeichen kommen im Gerät vor), höchstens 50 Zeichen.
     */
    public static function kennung(mixed $kennung): string
    {
        return Messarten::text($kennung, self::KENNUNG_LAENGE);
    }

    /**
     * Eindeutiger Schlüssel einer Kennung beim Besitzer: 'e:' (eigener Bestand)
     * bzw. 'k<kunde_id>:' plus die kleingeschriebene Kennung. Immer über diese
     * Funktion bilden, nie von Hand.
     *
     * Unter MySQL vergleicht die Spalte ohne Akzente (utf8mb4-Sortierung):
     * {@see findEindeutig()} prüft deshalb in PHP Byte für Byte nach, und zwei
     * Kennungen, die sich nur im Akzent unterscheiden, scheitern dort beim
     * Anlegen am eindeutigen Index – laut, nie still zusammengelegt.
     */
    public static function eindeutig(?int $kundeId, string $kennung): string
    {
        return ($kundeId === null ? 'e:' : 'k' . $kundeId . ':') . mb_strtolower(self::kennung($kennung), 'UTF-8');
    }

    /**
     * Ein Prüfling (Spalten wie in der Tabelle, `technik` als Array, Zahlen als
     * int bzw. bool); null, wenn es ihn nicht gibt.
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $z = Database::first('SELECT * FROM gp_prueflinge WHERE id = ?', [$id]);
        return $z === null ? null : self::aufbereiten($z);
    }

    /**
     * Der Prüfling mit dieser Kennung beim Besitzer (ohne Groß/Klein); null,
     * wenn es keinen gibt.
     *
     * @return array<string,mixed>|null
     */
    public static function findEindeutig(?int $kundeId, string $kennung): ?array
    {
        $schluessel = self::eindeutig($kundeId, $kennung);
        foreach (Database::select('SELECT * FROM gp_prueflinge WHERE eindeutig = ?', [$schluessel]) as $z) {
            if (hash_equals((string) $z['eindeutig'], $schluessel)) {
                return self::aufbereiten($z);
            }
        }
        return null;
    }

    /**
     * Prüflinge eines Besitzers (null = eigener Bestand), nach Kennung.
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerBesitzer(?int $kundeId, bool $mitAusserBetrieb = true): array
    {
        $sql = 'SELECT * FROM gp_prueflinge WHERE ' . ($kundeId === null ? 'kunde_id IS NULL' : 'kunde_id = ?')
            . ($mitAusserBetrieb ? '' : ' AND ausser_betrieb = 0') . ' ORDER BY kennung, id';
        return array_map([self::class, 'aufbereiten'], Database::select($sql, $kundeId === null ? [] : [$kundeId]));
    }

    /**
     * Einen Prüfling anlegen – ohne Rechteprüfung (siehe Klassenkommentar).
     * Pflicht ist nur `kennung` (nicht leer nach dem Säubern); fehlende Felder
     * bekommen die Vorgaben der Tabelle, `created_by` das angemeldete Konto.
     * Gibt es die Kennung beim Besitzer schon, wirft die Datenbank (eindeutiger
     * Index) – vorher mit {@see findEindeutig()} fragen.
     *
     * @param array<string,mixed> $daten Spalten aus {@see FELDER}
     */
    public static function anlegen(array $daten): int
    {
        $zeile = self::zeile($daten, null);
        if (!isset($zeile['kennung'])) {
            throw new \InvalidArgumentException('Prüfling ohne Kennung.');
        }
        $zeile['kunde_id'] ??= null;
        $zeile['eindeutig'] = self::eindeutig($zeile['kunde_id'], $zeile['kennung']);
        $zeile['created_by'] ??= Auth::id();
        $zeile['created_at'] = gmdate('c');
        return Database::insert('gp_prueflinge', $zeile);
    }

    /**
     * Felder eines Prüflings ändern – ohne Rechteprüfung. Nur die übergebenen
     * Spalten aus {@see FELDER} werden geschrieben; ändern sich Besitzer oder
     * Kennung, wird `eindeutig` neu gebildet. Unbekannter Prüfling →
     * \InvalidArgumentException. Ändert sich das Intervall, danach
     * {@see faelligkeitNeu()} rufen.
     *
     * @param array<string,mixed> $daten
     */
    public static function aendern(int $id, array $daten): void
    {
        $vorher = self::find($id);
        if ($vorher === null) {
            throw new \InvalidArgumentException('Prüfling ' . $id . ' gibt es nicht.');
        }
        $zeile = self::zeile($daten, $vorher);
        unset($zeile['created_by']);
        if ($zeile === []) {
            return;
        }
        if (array_key_exists('kunde_id', $zeile) || array_key_exists('kennung', $zeile)) {
            $kunde = array_key_exists('kunde_id', $zeile) ? $zeile['kunde_id'] : $vorher['kunde_id'];
            $zeile['eindeutig'] = self::eindeutig($kunde, $zeile['kennung'] ?? (string) $vorher['kennung']);
        }
        $zeile['updated_at'] = gmdate('c');
        Database::update('gp_prueflinge', $id, $zeile);
    }

    /**
     * Fälligkeit aus den Prüfungen hier neu rechnen: letzte Prüfung (jüngste
     * nach `geprueft_am`) als Ortsdatum, ihr Ergebnis und die nächste Prüfung –
     * bestanden: Prüftag + `intervall_monate` (am Monatsende gekappt; ohne
     * Intervall keine), nicht bestanden: am Prüftag fällig (vor
     * Wiederinbetriebnahme). Ohne Prüfungen hier bleibt alles, wie es ist
     * (Angaben aus dem Gerät oder von Hand). Gibt die neuen Werte zurück, null
     * ohne Prüfung oder ohne Prüfling.
     *
     * @return array{letzte_pruefung:string,letztes_ergebnis:string,naechste_pruefung:?string}|null
     */
    public static function faelligkeitNeu(int $id): ?array
    {
        $p = Database::first('SELECT intervall_monate FROM gp_prueflinge WHERE id = ?', [$id]);
        if ($p === null) {
            return null;
        }
        $letzte = Database::first('SELECT geprueft_am, ergebnis FROM gp_pruefungen WHERE pruefling_id = ?
            ORDER BY geprueft_am DESC, id DESC LIMIT 1', [$id]);
        $tag = $letzte === null ? null : Messarten::ortsdatum((string) $letzte['geprueft_am']);
        if ($letzte === null || $tag === null) {
            return null;
        }
        $ergebnis = (string) $letzte['ergebnis'] === 'bestanden' ? 'bestanden' : 'nicht_bestanden';
        $intervall = (int) $p['intervall_monate'];
        $naechste = match (true) {
            $ergebnis !== 'bestanden' => $tag,
            $intervall > 0            => Messarten::monatePlus($tag, $intervall),
            default                   => null,
        };
        $werte = ['letzte_pruefung' => $tag, 'letztes_ergebnis' => $ergebnis, 'naechste_pruefung' => $naechste];
        Database::update('gp_prueflinge', $id, $werte + ['updated_at' => gmdate('c')]);
        return $werte;
    }

    /**
     * Daten säubern und in Spalten übersetzen; nur übergebene Felder.
     *
     * @param array<string,mixed> $daten
     * @param array<string,mixed>|null $vorher
     * @return array<string,mixed>
     */
    private static function zeile(array $daten, ?array $vorher): array
    {
        $z = [];
        foreach ($daten as $feld => $wert) {
            if (!in_array($feld, self::FELDER, true)) {
                continue;
            }
            $z[$feld] = match (true) {
                $feld === 'kennung'                    => self::kennung($wert),
                isset(self::TEXTE[$feld])              => $feld === 'bemerkung' ? Messarten::absatz($wert, self::TEXTE[$feld]) : Messarten::text($wert, self::TEXTE[$feld]),
                isset(self::ZAHLEN[$feld])             => self::dezimal($feld, $wert),
                in_array($feld, ['kunde_id', 'inventar_id', 'pruefablauf_id', 'created_by'], true) => self::id($feld, $wert),
                $feld === 'intervall_monate'           => self::ganzzahl($feld, $wert, 0, 600),
                $feld === 'ausser_betrieb'             => $wert === true || $wert === 1 || $wert === '1' ? 1 : 0,
                $feld === 'schutzklasse'               => self::auswahl($feld, (string) $wert, self::SCHUTZKLASSEN),
                $feld === 'letztes_ergebnis'           => self::auswahl($feld, (string) $wert, self::ERGEBNISSE),
                in_array($feld, ['letzte_pruefung', 'naechste_pruefung'], true) => self::datum($feld, $wert),
                $feld === 'technik'                    => self::technik($wert),
            };
        }
        if (array_key_exists('kennung', $z) && $z['kennung'] === '') {
            throw new \InvalidArgumentException('Prüfling ohne Kennung.');
        }
        $kunde = array_key_exists('kunde_id', $z) ? $z['kunde_id'] : ($vorher['kunde_id'] ?? null);
        $inventar = array_key_exists('inventar_id', $z) ? $z['inventar_id'] : ($vorher['inventar_id'] ?? null);
        if ($kunde !== null && $inventar !== null) {
            throw new \InvalidArgumentException('Ein Inventarstück gehört nur zum eigenen Bestand.');
        }
        return $z;
    }

    private static function dezimal(string $feld, mixed $wert): string
    {
        $text = Messarten::dezimal($wert);
        if ($text === null || strlen($text) > self::ZAHLEN[$feld]) {
            throw new \InvalidArgumentException('Keine Zahl für ' . $feld . '.');
        }
        return $text;
    }

    private static function id(string $feld, mixed $wert): ?int
    {
        if ($wert === null || $wert === '' || $wert === 0 || $wert === '0') {
            return null;
        }
        if (is_int($wert) && $wert > 0) {
            return $wert;
        }
        if (is_string($wert) && ctype_digit($wert)) {
            return (int) $wert;
        }
        throw new \InvalidArgumentException('Keine ID für ' . $feld . '.');
    }

    private static function ganzzahl(string $feld, mixed $wert, int $min, int $max): int
    {
        if (is_string($wert) && preg_match('/^\d{1,4}$/', trim($wert)) === 1) {
            $wert = (int) trim($wert);
        }
        if (!is_int($wert) || $wert < $min || $wert > $max) {
            throw new \InvalidArgumentException('Keine ganze Zahl von ' . $min . ' bis ' . $max . ' für ' . $feld . '.');
        }
        return $wert;
    }

    /** @param array<string,string> $erlaubt */
    private static function auswahl(string $feld, string $wert, array $erlaubt): string
    {
        if (!array_key_exists($wert, $erlaubt)) {
            throw new \InvalidArgumentException('Unbekannter Wert für ' . $feld . '.');
        }
        return $wert;
    }

    private static function datum(string $feld, mixed $wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $wert = (string) $wert;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Kein Datum (JJJJ-MM-TT) für ' . $feld . '.');
        }
        return $wert;
    }

    /** Felder des Geräts, die hier niemand pflegt: nur Text, Wahrheitswerte, Ganzzahlen. */
    private static function technik(mixed $wert): ?string
    {
        if ($wert === null || $wert === [] || $wert === '') {
            return null;
        }
        if (!is_array($wert)) {
            throw new \InvalidArgumentException('technik ist eine Liste von Feldern.');
        }
        $sauber = [];
        foreach ($wert as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z0-9_]{1,40}$/', $k) !== 1) {
                throw new \InvalidArgumentException('Unbekanntes Feld in technik.');
            }
            if (!is_string($v) && !is_bool($v) && !is_int($v) && $v !== null) {
                throw new \InvalidArgumentException('technik enthält nur Text, Wahrheitswerte und ganze Zahlen.');
            }
            $sauber[$k] = is_string($v) ? Messarten::text($v, 80) : $v;
        }
        ksort($sauber);
        return json_encode($sauber, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function aufbereiten(array $z): array
    {
        $z['id'] = (int) $z['id'];
        foreach (['kunde_id', 'inventar_id', 'pruefablauf_id', 'created_by'] as $k) {
            $z[$k] = $z[$k] === null ? null : (int) $z[$k];
        }
        $z['intervall_monate'] = (int) $z['intervall_monate'];
        $z['ausser_betrieb'] = (int) $z['ausser_betrieb'] === 1;
        $technik = $z['technik'] === null ? null : json_decode((string) $z['technik'], true);
        $z['technik'] = is_array($technik) ? $technik : [];
        return $z;
    }
}
