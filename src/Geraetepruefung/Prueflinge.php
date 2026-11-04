<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Auth\Auth;
use App\Kunden\KundenService;
use App\Kunden\Pruefnachweise;
use App\Support\Audit;
use App\Support\Database;

/**
 * Prüflinge der Geräteprüfung (Tabelle `gp_prueflinge`).
 *
 * Ein Prüfling gehört einem **Besitzer**: dem eigenen Bestand (`kunde_id`
 * NULL, optional mit einem Inventarstück verknüpft) oder einem Kunden
 * (`kunde_id`). Seine Kennung (Prüflings-ID bzw. Barcode am Gerät,
 * `Geraete_ID`) ist je Besitzer eindeutig, ohne Groß- und Kleinschreibung –
 * dieselbe Kennung darf bei zwei Kunden vorkommen (wie im Gerät).
 *
 * Zwei Schichten:
 *
 * - **Datenschicht ohne Rechteprüfung** für Import und Prüfskripte:
 *   {@see anlegen()} und {@see aendern()} säubern und kürzen Texte auf die
 *   Spaltenlänge und halten die Form ein (Kennung, Schutzklasse, Ergebnis,
 *   Daten, Zahlen als Dezimaltext). Ob Kunde, Inventarstück und Prüfablauf
 *   passen, prüft der Aufrufer. Formfehler werfen \InvalidArgumentException.
 * - **Pflege aus der Oberfläche:** {@see speichern()} prüft jede Eingabe mit
 *   eigener Meldung – Kennung Pflicht und je Besitzer eindeutig, der Kunde
 *   existiert, ein Inventarstück nur beim eigenen Bestand, nie ein
 *   Tanksensor (Kunden sehen nie Tanks), nie zweimal verknüpft und nur mit
 *   „Datenquellen ansehen“ wählbar, ein Prüfablauf nur, wenn er am Gerät
 *   gültig ist, Zahlen als Dezimaltext (Komma erlaubt) – und schreibt das
 *   Audit (nur IDs). {@see loeschen()} löscht nur ohne Prüfungen (die sind
 *   der Beweis hinter den Prüfberichten); sonst bleibt „außer Betrieb“.
 *   Das Recht `geraetepruefung.manage` prüft die Route.
 *
 * Fälligkeit: {@see faelligkeitNeu()} rechnet `letzte_pruefung`,
 * `letztes_ergebnis` und `naechste_pruefung` aus den Prüfungen hier neu –
 * nach jedem Import und nach jeder Änderung des Intervalls. Ohne Prüfungen
 * hier dürfen beide Daten von Hand stehen (Angaben aus dem Gerät oder
 * einer Prüfung anderswo). {@see faelligkeit()} zeigt sie wie die
 * Gültigkeit der Prüfnachweise: Symbol und Wort, nie nur eine Farbe.
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

    /** So heißt der eigene Bestand in Listen, Filtern und Formularen. */
    public const EIGEN = 'Eigener Bestand';

    /**
     * Fälligkeit eines Prüflings: Code => [Symbol, Wort] – immer beides, nie
     * nur eine Farbe (wie Pruefnachweise::GUELTIGKEIT, dieselben Codes und
     * dieselbe Frist von Pruefnachweise::BALD_TAGE Tagen).
     */
    public const FAELLIGKEIT = [
        'gueltig'        => ['✓', 'nicht fällig'],
        'laeuft_ab'      => ['!', 'bald fällig'],
        'abgelaufen'     => ['✗', 'überfällig'],
        'unbekannt'      => ['?', 'ohne Termin'],
        'ausser_betrieb' => ['–', 'außer Betrieb'],
    ];

    /** Filter der Liste: fällig → Beschriftung. */
    public const FILTER_FAELLIG = [
        ''             => 'alle',
        'faellig'      => 'fällig (überfällig oder in 30 Tagen)',
        'ueberfaellig' => 'überfällig',
        'ohne'         => 'ohne Termin',
    ];

    /** Filter der Liste: Betrieb → Beschriftung (Vorgabe: nur in Betrieb). */
    public const FILTER_BETRIEB = [
        ''       => 'in Betrieb',
        'ausser' => 'außer Betrieb',
        'alle'   => 'alle',
    ];

    /** Beschriftungen der Zahlenfelder (Meldungen, Formular). */
    public const ZAHLEN_NAMEN = [
        'leitungslaenge' => 'Leitungslänge (m)',
        'querschnitt'    => 'Querschnitt (mm²)',
        'leiter'         => 'Anzahl der Leiter',
        'nennleistung'   => 'Nennleistung (W)',
    ];

    /** Beschriftungen der Textfelder (Meldungen, Formular). */
    public const TEXTE_NAMEN = [
        'bezeichnung' => 'Bezeichnung', 'hersteller' => 'Hersteller', 'typ' => 'Typ', 'modell' => 'Modell',
        'seriennummer' => 'Seriennummer', 'abteilung' => 'Abteilung', 'gebaeude' => 'Gebäude', 'bemerkung' => 'Bemerkung',
    ];

    public const INTERVALL_HOECHSTENS = 600;

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

    // ------------------------------------------------------------ Pflege (Oberfläche)

    /**
     * Einen Prüfling aus dem Formular anlegen ($id null) oder ändern – mit
     * Prüfung jeder Eingabe und eigener Meldung (siehe Klassenkommentar) und
     * Audit nur mit IDs (nie Kennung, Bezeichnung oder Seriennummer: das
     * können Daten eines Kunden sein). Das Recht prüft die Route.
     *
     * - `kunde_id`: leer = eigener Bestand, sonst ein Kunde, den es gibt. Den
     *   Besitzer wechseln geht nur, solange es zum Prüfling keinen Prüfbericht
     *   gibt (der nennt den bisherigen).
     * - `inventar_id` (nur mit „Datenquellen ansehen“, sonst bleibt der
     *   bisherige Wert): nur beim eigenen Bestand, kein Tanksensor, nicht
     *   schon mit einem anderen Prüfling verknüpft.
     * - `pruefablauf_id`: ein Prüfablauf aus der Vorlage; ein am Gerät
     *   ungültiger nur, wenn er schon eingetragen war.
     * - Zahlen (Leitungslänge, Querschnitt, Leiter, Nennleistung) als
     *   Dezimaltext, Komma erlaubt; Intervall 0 bis 600 Monate.
     * - `letzte_pruefung`, `naechste_pruefung` (JJJJ-MM-TT oder TT.MM.JJJJ)
     *   nur, solange es hier keine Prüfung gibt – sonst rechnet sie
     *   {@see faelligkeitNeu()}. Ohne nächste Prüfung, aber mit letzter und
     *   Intervall ergibt sie sich daraus.
     *
     * Fehlende Felder behalten beim Ändern ihren Wert (beim Anlegen die
     * Vorgaben der Tabelle); `technik` und das letzte Ergebnis kommen nur
     * aus dem Gerät.
     *
     * @param array<string,mixed> $daten
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $vorher = null;
        if ($id !== null) {
            $vorher = self::find($id);
            if ($vorher === null) {
                return ['ok' => false, 'message' => 'Diesen Prüfling gibt es nicht.'];
            }
        }
        $wert = static fn (string $feld): mixed => array_key_exists($feld, $daten) ? $daten[$feld] : ($vorher[$feld] ?? null);

        // Kennung (Pflicht, getrimmt, höchstens 50 Zeichen – nicht still gekürzt)
        $kennung = Messarten::text($wert('kennung'), 10000);
        if ($kennung === '') {
            return ['ok' => false, 'message' => 'Der Prüfling braucht eine Kennung (Prüflings-ID bzw. Barcode am Prüfgerät).'];
        }
        if (mb_strlen($kennung) > self::KENNUNG_LAENGE) {
            return ['ok' => false, 'message' => 'Die Kennung hat höchstens ' . self::KENNUNG_LAENGE . ' Zeichen.'];
        }

        // Besitzer
        $kundeId = self::idLesen($wert('kunde_id'));
        if ($kundeId === false) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        if ($kundeId !== null && KundenService::find($kundeId) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }

        // Inventarstück: wählen nur, wer das Inventar sieht – sonst bleibt es.
        $inventarId = $vorher['inventar_id'] ?? null;
        if (array_key_exists('inventar_id', $daten) && Auth::can('datenquellen.view')) {
            $inventarId = self::idLesen($daten['inventar_id']);
            if ($inventarId === false) {
                return ['ok' => false, 'message' => 'Dieses Inventarstück gibt es nicht.'];
            }
        }
        if ($kundeId !== null && $inventarId !== null) {
            return ['ok' => false, 'message' => 'Ein Inventarstück lässt sich nur mit einem Prüfling des eigenen Bestands verknüpfen.'];
        }

        // Prüfablauf
        $ablaufId = $vorher['pruefablauf_id'] ?? null;
        if (array_key_exists('pruefablauf_id', $daten)) {
            $neu = self::idLesen($daten['pruefablauf_id']);
            if ($neu === false) {
                return ['ok' => false, 'message' => 'Diesen Prüfablauf gibt es nicht.'];
            }
            if ($neu !== null && $neu !== $ablaufId) {
                $a = Database::first('SELECT id, ungueltig FROM gp_pruefablaeufe WHERE id = ?', [$neu]);
                if ($a === null) {
                    return ['ok' => false, 'message' => 'Diesen Prüfablauf gibt es nicht.'];
                }
                if ((int) $a['ungueltig'] === 1) {
                    return ['ok' => false, 'message' => 'Dieser Prüfablauf ist am Prüfgerät ungültig oder nicht mehr in der Vorlage – bitte einen anderen wählen.'];
                }
            }
            $ablaufId = $neu;
        }

        $zeile = ['kennung' => $kennung, 'kunde_id' => $kundeId, 'inventar_id' => $inventarId, 'pruefablauf_id' => $ablaufId];

        // Texte – zu lang wird abgelehnt, nicht still gekürzt
        foreach (self::TEXTE as $feld => $laenge) {
            $text = $feld === 'bemerkung' ? Messarten::absatz($wert($feld) ?? '', 10000) : Messarten::text($wert($feld) ?? '', 10000);
            if (mb_strlen($text) > $laenge) {
                return ['ok' => false, 'message' => '„' . self::TEXTE_NAMEN[$feld] . '“ hat höchstens ' . $laenge . ' Zeichen.'];
            }
            $zeile[$feld] = $text;
        }

        // Schutzklasse, Intervall, Zahlen
        $sk = (string) ($wert('schutzklasse') ?? '1');
        if (!array_key_exists($sk, self::SCHUTZKLASSEN)) {
            return ['ok' => false, 'message' => 'Die Schutzklasse ist I, II oder III.'];
        }
        $zeile['schutzklasse'] = $sk;
        $intervall = $wert('intervall_monate') ?? 12;
        $intervall = is_string($intervall) ? trim($intervall) : $intervall;
        if (is_string($intervall) && preg_match('/^\d{1,3}$/', $intervall) === 1) {
            $intervall = (int) $intervall;
        }
        if (!is_int($intervall) || $intervall < 0 || $intervall > self::INTERVALL_HOECHSTENS) {
            return ['ok' => false, 'message' => 'Das Prüfintervall ist eine ganze Zahl von 0 bis ' . self::INTERVALL_HOECHSTENS . ' Monaten (0 = keine Wiederholung).'];
        }
        $zeile['intervall_monate'] = $intervall;
        foreach (self::ZAHLEN as $feld => $laenge) {
            $roh = $wert($feld);
            $text = Messarten::dezimal($roh);
            if ($text === null || strlen($text) > $laenge) {
                return ['ok' => false, 'message' => '„' . self::ZAHLEN_NAMEN[$feld] . '“ ist keine Zahl (etwa „1,5“; leer lassen geht auch).'];
            }
            $zeile[$feld] = $text;
        }
        $aus = $wert('ausser_betrieb');
        $zeile['ausser_betrieb'] = $aus === true || $aus === 1 || $aus === '1' || $aus === 'on';

        // Daten von Hand – nur ohne Prüfungen hier
        $pruefungen = $id === null ? 0 : self::anzahlPruefungen($id);
        if ($pruefungen === 0) {
            $tage = [];
            foreach (['letzte_pruefung' => 'Letzte Prüfung', 'naechste_pruefung' => 'Nächste Prüfung'] as $feld => $name) {
                $roh = $wert($feld);
                $tag = self::tagLesen($roh);
                if ($tag === false) {
                    return ['ok' => false, 'message' => '„' . $name . '“: kein gültiges Datum (TT.MM.JJJJ).'];
                }
                $tage[$feld] = $tag;
            }
            // Geprüft wird nur, was sich ändert – Angaben aus dem Gerät bleiben, wie sie sind.
            $geaendert = $tage['letzte_pruefung'] !== ($vorher['letzte_pruefung'] ?? null) || $tage['naechste_pruefung'] !== ($vorher['naechste_pruefung'] ?? null);
            if ($geaendert && $tage['letzte_pruefung'] !== null && $tage['letzte_pruefung'] > Pruefnachweise::heute()) {
                return ['ok' => false, 'message' => 'Die letzte Prüfung liegt nicht in der Zukunft.'];
            }
            if ($tage['naechste_pruefung'] === null && $tage['letzte_pruefung'] !== null && $intervall > 0) {
                $tage['naechste_pruefung'] = Messarten::monatePlus($tage['letzte_pruefung'], $intervall);
            }
            if ($geaendert && $tage['letzte_pruefung'] !== null && $tage['naechste_pruefung'] !== null && $tage['naechste_pruefung'] < $tage['letzte_pruefung']) {
                return ['ok' => false, 'message' => 'Die nächste Prüfung liegt nicht vor der letzten.'];
            }
            $zeile += $tage;
        }

        try {
            $id = Database::transaktion(static function () use ($id, $vorher, $zeile, $kennung, $kundeId, $inventarId): int {
                // Erst hier geprüft (unter SQLite mit der Schreibsperre der Transaktion):
                // zwei gleichzeitige Formulare legen nichts doppelt an.
                $gleich = self::findEindeutig($kundeId, $kennung);
                if ($gleich !== null && $gleich['id'] !== $id) {
                    throw new \DomainException('Die Kennung „' . $kennung . '“ gibt es ' . ($kundeId === null ? 'im eigenen Bestand' : 'bei diesem Kunden') . ' schon.');
                }
                if ($vorher !== null && $vorher['kunde_id'] !== $kundeId && self::anzahlBerichte((int) $vorher['id']) > 0) {
                    throw new \DomainException('Den Besitzer gibt es nicht mehr zu ändern: Zu diesem Prüfling gibt es schon Prüfberichte, und die nennen den bisherigen.');
                }
                if ($inventarId !== null && $inventarId !== ($vorher['inventar_id'] ?? null)) {
                    $stueck = Database::first('SELECT id, art FROM inventar WHERE id = ?', [$inventarId]);
                    if ($stueck === null) {
                        throw new \DomainException('Dieses Inventarstück gibt es nicht.');
                    }
                    if ((string) $stueck['art'] === 'tanksensor') {
                        throw new \DomainException('Ein Tanksensor ist kein Prüfling der Geräteprüfung.');
                    }
                    $belegt = Database::first('SELECT id, kennung FROM gp_prueflinge WHERE inventar_id = ? AND id <> ?', [$inventarId, $id ?? 0]);
                    if ($belegt !== null) {
                        throw new \DomainException('Dieses Inventarstück ist schon mit dem Prüfling „' . (string) $belegt['kennung'] . '“ verknüpft.');
                    }
                }
                if ($id === null) {
                    return self::anlegen($zeile);
                }
                self::aendern($id, $zeile);
                return $id;
            });
        } catch (\DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\PDOException $e) {
            // Der eindeutige Index war schneller (gleichzeitig angelegt – oder
            // unter MySQL eine Kennung, die sich nur im Akzent unterscheidet).
            if (self::eindeutigVerletzt($e)) {
                return ['ok' => false, 'message' => 'Die Kennung „' . $kennung . '“ (oder eine, die sich nur in Groß-/Kleinschreibung oder Akzenten unterscheidet) gibt es beim Besitzer schon.'];
            }
            throw $e;
        }
        if ($pruefungen > 0) {
            self::faelligkeitNeu($id);
        }
        $details = ['pruefling_id' => $id, 'kunde_id' => $kundeId];
        if ($vorher === null) {
            $details['neu'] = true;
        } elseif ($vorher['kunde_id'] !== $kundeId) {
            $details['vorher_kunde_id'] = $vorher['kunde_id'];
        }
        if ($inventarId !== ($vorher['inventar_id'] ?? null)) {
            $details['inventar_id'] = $inventarId;
        }
        Audit::log('geraetepruefung.pruefling', 'gp_pruefling', (string) $id, $details);
        return ['ok' => true, 'message' => 'Prüfling „' . $kennung . '“ gespeichert.', 'id' => $id];
    }

    /**
     * Einen Prüfling löschen – nur ohne Prüfungen (und damit ohne Berichte):
     * Prüfungen sind der Beweis hinter den Prüfberichten und bleiben. Ein
     * geprüfter Prüfling wird stattdessen „außer Betrieb“ gesetzt.
     *
     * @return array{ok:bool,message:string}
     */
    public static function loeschen(int $id): array
    {
        $p = self::find($id);
        if ($p === null) {
            return ['ok' => false, 'message' => 'Diesen Prüfling gibt es nicht.'];
        }
        try {
            Database::transaktion(static function () use ($id): void {
                if (self::anzahlPruefungen($id) > 0 || self::anzahlBerichte($id) > 0) {
                    throw new \DomainException('Ein Prüfling mit Prüfungen bleibt (Nachweis, Aufbewahrung der Prüfberichte) – stattdessen „außer Betrieb“ setzen.');
                }
                Database::delete('gp_prueflinge', $id);
            });
        } catch (\DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        Audit::log('geraetepruefung.pruefling_geloescht', 'gp_pruefling', (string) $id, ['pruefling_id' => $id, 'kunde_id' => $p['kunde_id']]);
        return ['ok' => true, 'message' => 'Prüfling „' . (string) $p['kennung'] . '“ gelöscht.'];
    }

    /**
     * Haken aus InventarService::loeschen(): Prüflinge des Stücks bleiben, nur
     * ohne Verweis (wie Prüfnachweise und Übergabeberichte). Gibt die Zahl der
     * gelösten Verknüpfungen zurück.
     */
    public static function inventarEntfernt(int $inventarId): int
    {
        return Database::execute('UPDATE gp_prueflinge SET inventar_id = NULL, updated_at = ? WHERE inventar_id = ?', [gmdate('c'), $inventarId]);
    }

    /**
     * Hat der Kunde Prüflinge? Dann lässt er sich nicht löschen
     * (KundenService::loeschen): Prüflinge mit Prüfungen bleiben, und zum
     * eigenen Bestand dürfen sie nicht werden.
     */
    public static function kundeHatPrueflinge(int $kundeId): bool
    {
        return self::anzahlBeiKunde($kundeId) > 0;
    }

    /** Wie viele Prüflinge der Kunde hat (auch außer Betrieb). */
    public static function anzahlBeiKunde(int $kundeId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge WHERE kunde_id = ?', [$kundeId]);
    }

    /** Wie viele Prüfungen es zum Prüfling gibt. */
    public static function anzahlPruefungen(int $id): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM gp_pruefungen WHERE pruefling_id = ?', [$id]);
    }

    /** Wie viele Prüfberichte (alle Fassungen) es zum Prüfling gibt. */
    public static function anzahlBerichte(int $id): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM gp_berichte WHERE pruefling_id = ?', [$id]);
    }

    /**
     * Prüflinge nach Filtern, je Zeile zusätzlich `kunde_name`,
     * `inventar_kennung`, `ablauf_name`, `ablauf_ungueltig`, `pruefungen`
     * (Zahl) und `faelligkeit` ({@see faelligkeit()}).
     *
     * Filter (alle freiwillig):
     * - `besitzer`: 'eigen' oder eine Kunden-ID
     * - `faellig`: 'faellig' (überfällig oder in Pruefnachweise::BALD_TAGE
     *   Tagen fällig), 'ueberfaellig', 'ohne' (ohne Termin) – ein nicht
     *   bestandener Prüfling ist immer überfällig (vor Wiederinbetriebnahme)
     * - `betrieb`: '' nur in Betrieb (Vorgabe), 'ausser', 'alle'
     * - `suche`: Teil der Kennung, Bezeichnung oder Seriennummer
     * - `inventar_id` (int)
     * - `limit` (Vorgabe 500, höchstens 5000), `offset`
     * - `heute` (JJJJ-MM-TT, für Prüfskripte; sonst heute in der Zeitzone der Installation)
     *
     * Sortiert nach Kennung, mit Filter „fällig“ nach dem Termin.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public static function liste(array $filter = []): array
    {
        [$wo, $werte] = self::bedingungen($filter);
        $limit = max(1, min(5000, (int) ($filter['limit'] ?? 500)));
        $offset = max(0, (int) ($filter['offset'] ?? 0));
        $nachTermin = in_array((string) ($filter['faellig'] ?? ''), ['faellig', 'ueberfaellig'], true);
        $sql = 'SELECT l.*, k.name AS kunde_name, i.kennung AS inventar_kennung, a.name AS ablauf_name, a.ungueltig AS ablauf_ungueltig,
                (SELECT COUNT(*) FROM gp_pruefungen p WHERE p.pruefling_id = l.id) AS pruefungen
            FROM gp_prueflinge l
            LEFT JOIN kunden k ON k.id = l.kunde_id
            LEFT JOIN inventar i ON i.id = l.inventar_id
            LEFT JOIN gp_pruefablaeufe a ON a.id = l.pruefablauf_id'
            . ($wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo))
            . ($nachTermin ? ' ORDER BY CASE WHEN l.naechste_pruefung IS NULL THEN 1 ELSE 0 END, l.naechste_pruefung, l.kennung, l.id' : ' ORDER BY l.kennung, l.id')
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $heute = self::heute($filter);
        $out = [];
        foreach (Database::select($sql, $werte) as $z) {
            $z = self::aufbereiten($z);
            $z['pruefungen'] = (int) $z['pruefungen'];
            $z['ablauf_ungueltig'] = $z['ablauf_ungueltig'] !== null && (int) $z['ablauf_ungueltig'] === 1;
            $z['besitzer'] = self::besitzerName($z);
            $z['faelligkeit'] = self::faelligkeit($z, $heute);
            $out[] = $z;
        }
        return $out;
    }

    /**
     * Wie viele Prüflinge zu den Filtern von {@see liste()} passen (ohne Limit).
     *
     * @param array<string,mixed> $filter
     */
    public static function anzahl(array $filter = []): int
    {
        [$wo, $werte] = self::bedingungen($filter);
        return (int) Database::scalar('SELECT COUNT(*) FROM gp_prueflinge l' . ($wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo)), $werte);
    }

    /**
     * Zahlen für die Übersicht: in Betrieb, davon überfällig, bald fällig
     * (in Pruefnachweise::BALD_TAGE Tagen, nicht überfällig), ohne Termin;
     * außer Betrieb.
     *
     * @return array{in_betrieb:int,ueberfaellig:int,bald:int,ohne_termin:int,ausser_betrieb:int}
     */
    public static function zahlen(?string $heute = null): array
    {
        $heute ??= Pruefnachweise::heute();
        $bald = self::tagePlus($heute, Pruefnachweise::BALD_TAGE);
        $z = Database::first("SELECT
                SUM(CASE WHEN ausser_betrieb = 0 THEN 1 ELSE 0 END) AS in_betrieb,
                SUM(CASE WHEN ausser_betrieb = 0 AND (letztes_ergebnis = 'nicht_bestanden' OR naechste_pruefung < ?) THEN 1 ELSE 0 END) AS ueberfaellig,
                SUM(CASE WHEN ausser_betrieb = 0 AND letztes_ergebnis <> 'nicht_bestanden' AND naechste_pruefung >= ? AND naechste_pruefung <= ? THEN 1 ELSE 0 END) AS bald,
                SUM(CASE WHEN ausser_betrieb = 0 AND letztes_ergebnis <> 'nicht_bestanden' AND naechste_pruefung IS NULL THEN 1 ELSE 0 END) AS ohne_termin,
                SUM(CASE WHEN ausser_betrieb = 1 THEN 1 ELSE 0 END) AS ausser_betrieb
            FROM gp_prueflinge", [$heute, $heute, $bald]) ?? [];
        return [
            'in_betrieb'     => (int) ($z['in_betrieb'] ?? 0),
            'ueberfaellig'   => (int) ($z['ueberfaellig'] ?? 0),
            'bald'           => (int) ($z['bald'] ?? 0),
            'ohne_termin'    => (int) ($z['ohne_termin'] ?? 0),
            'ausser_betrieb' => (int) ($z['ausser_betrieb'] ?? 0),
        ];
    }

    /**
     * Fälligkeit zum Zeigen – dieselbe Rechnung wie die Gültigkeit der
     * Prüfnachweise (Pruefnachweise::gueltigkeit: tagesgenau in der Zeitzone
     * der Installation, bald = in 30 Tagen), mit Symbol **und** Wort. Nicht
     * bestanden heißt überfällig (vor Wiederinbetriebnahme), außer Betrieb
     * zählt nicht.
     *
     * @param array<string,mixed> $p Prüfling (naechste_pruefung, letztes_ergebnis, ausser_betrieb)
     * @return array{code:string,symbol:string,wort:string,text:string,klasse:string}
     */
    public static function faelligkeit(array $p, ?string $heute = null): array
    {
        $naechste = is_string($p['naechste_pruefung'] ?? null) ? (string) $p['naechste_pruefung'] : '';
        if (!empty($p['ausser_betrieb'])) {
            $code = 'ausser_betrieb';
            $text = 'außer Betrieb – wird nicht geprüft';
        } elseif ((string) ($p['letztes_ergebnis'] ?? '') === 'nicht_bestanden') {
            $code = 'abgelaufen';
            $text = 'nicht bestanden – erst nach Instandsetzung und neuer Prüfung wieder benutzen';
        } else {
            $g = Pruefnachweise::gueltigkeit(['naechste_pruefung' => $naechste], $heute);
            $code = $g['code'];
            $text = match ($code) {
                'abgelaufen' => 'fällig seit ' . Pruefnachweise::datum($naechste),
                'gueltig'    => 'nächste Prüfung am ' . Pruefnachweise::datum($naechste),
                'unbekannt'  => 'kein Termin für die nächste Prüfung',
                default      => $g['text'],
            };
        }
        $klasse = ['gueltig' => 'ok', 'laeuft_ab' => 'warn', 'abgelaufen' => 'bad'][$code] ?? '';
        return ['code' => $code, 'symbol' => self::FAELLIGKEIT[$code][0], 'wort' => self::FAELLIGKEIT[$code][1], 'text' => $text, 'klasse' => $klasse];
    }

    /** Der Besitzer zum Zeigen: „Eigener Bestand“ bzw. der Name des Kunden. */
    public static function besitzerName(array $p): string
    {
        if (($p['kunde_id'] ?? null) === null) {
            return self::EIGEN;
        }
        $name = (string) ($p['kunde_name'] ?? (KundenService::find((int) $p['kunde_id'])['name'] ?? ''));
        return $name !== '' ? $name : 'Kunde ' . (int) $p['kunde_id'] . ' (gelöscht)';
    }

    /**
     * Der Prüfling, der mit dem Inventarstück verknüpft ist (für die Karte
     * „DGUV V3“ am Inventar) – mit `faelligkeit`; null ohne Verknüpfung.
     *
     * @return array<string,mixed>|null
     */
    public static function fuerInventar(int $inventarId): ?array
    {
        $z = self::liste(['inventar_id' => $inventarId, 'betrieb' => 'alle', 'limit' => 1]);
        return $z[0] ?? null;
    }

    /**
     * Die Prüfberichte eines Prüflings (alle Fassungen), der jüngste zuerst –
     * nur Eckdaten, nie der Inhalt.
     *
     * @return list<array{id:int,pruefung_id:int,nummer:string,fassung:int,status:string,ergebnis:string,eingefroren_am:string}>
     */
    public static function berichte(int $prueflingId): array
    {
        $out = [];
        foreach (Database::select('SELECT id, pruefung_id, nummer, fassung, status, ergebnis, eingefroren_am FROM gp_berichte
            WHERE pruefling_id = ? ORDER BY jahr DESC, laufnr DESC, fassung DESC, id DESC', [$prueflingId]) as $b) {
            $out[] = ['id' => (int) $b['id'], 'pruefung_id' => (int) $b['pruefung_id'], 'nummer' => (string) $b['nummer'], 'fassung' => (int) $b['fassung'],
                'status' => (string) $b['status'], 'ergebnis' => (string) $b['ergebnis'], 'eingefroren_am' => (string) $b['eingefroren_am']];
        }
        return $out;
    }

    /**
     * Prüfabläufe zur Auswahl im Formular: die am Gerät gültigen, dazu der
     * schon eingetragene (auch wenn er inzwischen ungültig ist), nach Nummer.
     *
     * @return list<array{id:int,geraet_id:string,nr:string,name:string,schutzklasse:string,ungueltig:bool}>
     */
    public static function ablaeufe(?int $aktuell = null): array
    {
        $out = [];
        foreach (Database::select('SELECT id, geraet_id, nr, name, schutzklasse, ungueltig FROM gp_pruefablaeufe WHERE ungueltig = 0 OR id = ?', [$aktuell ?? 0]) as $a) {
            $out[] = ['id' => (int) $a['id'], 'geraet_id' => (string) $a['geraet_id'], 'nr' => (string) $a['nr'], 'name' => (string) $a['name'],
                'schutzklasse' => (string) $a['schutzklasse'], 'ungueltig' => (int) $a['ungueltig'] === 1];
        }
        usort($out, static fn (array $x, array $y): int => strnatcmp($x['nr'], $y['nr']) ?: strnatcasecmp($x['name'], $y['name']) ?: $x['id'] <=> $y['id']);
        return $out;
    }

    /**
     * Inventarstücke zur Auswahl (nur mit „Datenquellen ansehen“, sonst
     * leer): kein Tanksensor, nicht schon mit einem anderen Prüfling
     * verknüpft – das eigene bleibt wählbar.
     *
     * @return list<array{id:int,kennung:string,name:string}>
     */
    public static function inventarAuswahl(?int $prueflingId = null): array
    {
        if (!Auth::can('datenquellen.view')) {
            return [];
        }
        return array_map(static fn (array $i): array => ['id' => (int) $i['id'], 'kennung' => (string) $i['kennung'], 'name' => (string) $i['name']],
            Database::select("SELECT i.id, i.kennung, i.name FROM inventar i WHERE i.art <> 'tanksensor'
                AND NOT EXISTS (SELECT 1 FROM gp_prueflinge l WHERE l.inventar_id = i.id AND l.id <> ?) ORDER BY i.kennung, i.id", [$prueflingId ?? 0]));
    }

    /**
     * Kunden, die Prüflinge haben (Filter der Liste), nach Name.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function kundenMitPrueflingen(): array
    {
        return array_map(static fn (array $k): array => ['id' => (int) $k['id'], 'name' => (string) $k['name']],
            Database::select('SELECT k.id, k.name FROM kunden k WHERE EXISTS (SELECT 1 FROM gp_prueflinge l WHERE l.kunde_id = k.id) ORDER BY k.name, k.id'));
    }

    /**
     * Bedingungen der Liste aus den Filtern.
     *
     * @param array<string,mixed> $filter
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function bedingungen(array $filter): array
    {
        $wo = [];
        $werte = [];
        $besitzer = $filter['besitzer'] ?? null;
        if ($besitzer === 'eigen') {
            $wo[] = 'l.kunde_id IS NULL';
        } elseif (is_int($besitzer) || (is_string($besitzer) && ctype_digit($besitzer) && $besitzer !== '')) {
            $wo[] = 'l.kunde_id = ?';
            $werte[] = (int) $besitzer;
        }
        $betrieb = (string) ($filter['betrieb'] ?? '');
        if ($betrieb === '') {
            $wo[] = 'l.ausser_betrieb = 0';
        } elseif ($betrieb === 'ausser') {
            $wo[] = 'l.ausser_betrieb = 1';
        }
        $heute = self::heute($filter);
        switch ((string) ($filter['faellig'] ?? '')) {
            case 'faellig':
                $wo[] = "(l.letztes_ergebnis = 'nicht_bestanden' OR l.naechste_pruefung <= ?)";
                $werte[] = self::tagePlus($heute, Pruefnachweise::BALD_TAGE);
                break;
            case 'ueberfaellig':
                $wo[] = "(l.letztes_ergebnis = 'nicht_bestanden' OR l.naechste_pruefung < ?)";
                $werte[] = $heute;
                break;
            case 'ohne':
                $wo[] = "l.naechste_pruefung IS NULL AND l.letztes_ergebnis <> 'nicht_bestanden'";
                break;
        }
        if (isset($filter['inventar_id'])) {
            $wo[] = 'l.inventar_id = ?';
            $werte[] = (int) $filter['inventar_id'];
        }
        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            // „!“ als Fluchtzeichen: unter SQLite und MySQL gleich (ein Backslash nicht).
            $muster = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($suche, 0, 100)) . '%';
            $wo[] = "(l.kennung LIKE ? ESCAPE '!' OR l.bezeichnung LIKE ? ESCAPE '!' OR l.seriennummer LIKE ? ESCAPE '!')";
            array_push($werte, $muster, $muster, $muster);
        }
        return [$wo, $werte];
    }

    /** @param array<string,mixed> $filter */
    private static function heute(array $filter): string
    {
        $heute = (string) ($filter['heute'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $heute) === 1 ? $heute : Pruefnachweise::heute();
    }

    /** JJJJ-MM-TT plus $tage Tage (in UTC gerechnet – ohne Zeitumstellung). */
    private static function tagePlus(string $tag, int $tage): string
    {
        return (new \DateTimeImmutable($tag, new \DateTimeZone('UTC')))->modify('+' . $tage . ' days')->format('Y-m-d');
    }

    /**
     * Eine ID aus dem Formular: null für leer, false für Unsinn.
     */
    private static function idLesen(mixed $wert): int|null|false
    {
        if ($wert === null || $wert === '' || $wert === 0 || $wert === '0') {
            return null;
        }
        if (is_int($wert)) {
            return $wert > 0 ? $wert : false;
        }
        if (is_string($wert) && preg_match('/^\d{1,10}$/', trim($wert)) === 1) {
            return (int) trim($wert);
        }
        return false;
    }

    /**
     * Ein Tag aus dem Formular (JJJJ-MM-TT oder TT.MM.JJJJ, Jahre 1990 bis
     * 2100): null für leer, false für Unsinn.
     */
    private static function tagLesen(mixed $wert): string|null|false
    {
        if ($wert === null) {
            return null;
        }
        if (!is_string($wert)) {
            return false;
        }
        $wert = trim($wert);
        if ($wert === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m) === 1) {
            [$j, $mo, $t] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $wert, $m) === 1) {
            [$j, $mo, $t] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return false;
        }
        if ($j < 1990 || $j > 2100 || !checkdate($mo, $t, $j)) {
            return false;
        }
        return sprintf('%04d-%02d-%02d', $j, $mo, $t);
    }

    /** Verletzt der Fehler einen eindeutigen Index? (SQLite und MySQL) */
    private static function eindeutigVerletzt(\PDOException $e): bool
    {
        $info = $e->errorInfo ?? [];
        return ($info[0] ?? '') === '23000' || (int) ($info[1] ?? 0) === 1062 || (int) ($info[1] ?? 0) === 19
            || stripos($e->getMessage(), 'UNIQUE') !== false;
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
