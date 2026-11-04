<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Berichte\Kanon;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Prüfungen aus dem Prüfgerät (Tabelle `gp_pruefungen`) – nach dem Import
 * unveränderlich: Sie sind der Beweis, aus dem der Prüfbericht entsteht.
 *
 * Eine Prüfung kommt in der **Normalform** (ein Eintrag aus
 * `BenningDatei::lesen()['pruefungen']`, nur Text, Wahrheitswerte, Listen und
 * Ganzzahlen):
 *
 * ```
 * ['schluessel', 'ref', 'geraet_ref', 'geprueft_am' (UTC, Y-m-d\TH:i:s\Z), 'am_geraet',
 *  'pruefer', 'prueffirma', 'ort', 'auftrag', 'bemerkung', 'ablauf_name', 'ablauf_geraet_id',
 *  'messgeraet', 'messgeraet_sn', 'firmware', 'ergebnis' (bestanden|nicht_bestanden),
 *  'sicht' (''|ok|maengel), 'geraet_ergebnis' => ['messung' => bool, 'sicht' => bool],
 *  'sicht_antworten' => [['frage', 'text', 'antwort' (ok|nicht_ok)]],
 *  'schritte' => [['art', 'titel', 'ok' => bool, 'werte' => [['name', 'wert', 'gueltig' => bool]],
 *                  'grenzwerte' => [['name', 'wert']]]]]
 * ```
 *
 * Wiedererkannt wird eine Prüfung nur über ihren Fingerabdruck
 * ({@see schluessel()}): dieselbe Prüfung aus einer Sicherung und aus der
 * Hauptdatenbank des Geräts ergibt denselben Schlüssel und wird nur einmal
 * angelegt. Rechte prüft diese Klasse nicht – das tun Route und Aufrufer
 * (Import, Prüfskripte). Wer `geraetepruefung.view` hat, sieht alle Prüfungen.
 */
final class Pruefungen
{
    public const ERGEBNISSE = [
        'bestanden'       => 'bestanden',
        'nicht_bestanden' => 'nicht bestanden',
    ];

    public const SICHT = [
        ''        => 'keine Angabe',
        'ok'      => 'in Ordnung',
        'maengel' => 'Mängel',
    ];

    /** Höchstlängen der Textspalten von `gp_pruefungen` (wie in Migration 019). */
    public const LAENGEN = [
        'am_geraet' => 40, 'pruefer' => 80, 'prueffirma' => 120, 'ort' => 120, 'auftrag' => 80,
        'bemerkung' => 500, 'ablauf_name' => 120, 'ablauf_geraet_id' => 20, 'messgeraet' => 80,
        'messgeraet_sn' => 80, 'firmware' => 40,
    ];

    /** Schlüssel der Normalform, die nur innerhalb einer gelesenen Datei gelten – nicht in `daten`. */
    private const NUR_IN_DER_DATEI = ['ref', 'geraet_ref'];

    /**
     * Fingerabdruck einer Prüfung: sha256 über Kanon-JSON von
     * [Seriennummer des Prüfgeräts, Zeit wie im Gerät, Name des Ablaufs,
     * Schritte (Art, ok, Werte mit Name, Wert, gültig), Antworten der
     * Sichtprüfung (Frage, Antwort)].
     *
     * Die Kennung des Prüflings gehört bewusst nicht dazu: Sie lässt sich am
     * Gerät ändern, die Prüfung hängt dort am Gerät, nicht an der Kennung –
     * nach einem Umbenennen bliebe eine schon importierte Prüfung sonst nicht
     * dieselbe und käme doppelt. Ein Prüfgerät misst nie zweimal in derselben
     * Sekunde. Der Text der Fragen auch nicht: Wird eine Frage am Gerät
     * umformuliert, bliebe die Prüfung sonst nicht dieselbe. Titel und
     * Grenzwerte auch nicht – die rechnet PowerManagement selbst.
     *
     * @param array<string,mixed> $pruefung Normalform
     */
    public static function schluessel(array $pruefung): string
    {
        $schritte = [];
        foreach (self::alsListe($pruefung['schritte'] ?? []) as $s) {
            $werte = [];
            foreach (self::alsListe($s['werte'] ?? []) as $w) {
                $werte[] = [(string) ($w['name'] ?? ''), (string) ($w['wert'] ?? ''), ($w['gueltig'] ?? false) === true];
            }
            $schritte[] = [(string) ($s['art'] ?? ''), ($s['ok'] ?? false) === true, $werte];
        }
        $antworten = [];
        foreach (self::alsListe($pruefung['sicht_antworten'] ?? []) as $a) {
            $antworten[] = [(string) ($a['frage'] ?? ''), (string) ($a['antwort'] ?? '')];
        }
        return Kanon::hash([
            (string) ($pruefung['messgeraet_sn'] ?? ''),
            (string) ($pruefung['am_geraet'] ?? ''),
            (string) ($pruefung['ablauf_name'] ?? ''),
            $schritte,
            $antworten,
        ]);
    }

    /**
     * Eine Prüfung anlegen. Gibt es den Schlüssel schon (auch bei einem anderen
     * Prüfling oder durch einen gleichzeitigen zweiten Import), wird nichts
     * geschrieben: `neu = false`, `id` und `pruefling_id` der vorhandenen.
     *
     * Prüft die Form (Schlüssel 64 hex, `geprueft_am` als UTC `Y-m-d\TH:i:s\Z`,
     * Ergebnis, Sicht, nur Text/Wahrheitswerte/Listen/Ganzzahlen – ein
     * Gleitkommawert ist ein Fehler) und kürzt Texte auf die Spaltenlänge.
     * Ein Formfehler wirft \InvalidArgumentException. Läuft gern innerhalb der
     * Transaktion des Imports (Database::transaktion); `Prueflinge::faelligkeitNeu()`
     * ruft der Aufrufer danach selbst.
     *
     * @param array<string,mixed> $pruefung Normalform
     * @return array{id:int,neu:bool,pruefling_id:int}
     */
    public static function anlegen(int $prueflingId, array $pruefung, ?int $importId = null): array
    {
        $schluessel = (string) ($pruefung['schluessel'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $schluessel) !== 1) {
            throw new \InvalidArgumentException('Prüfung ohne gültigen Schlüssel.');
        }
        $vorhanden = self::perSchluessel($schluessel);
        if ($vorhanden !== null) {
            return $vorhanden + ['neu' => false];
        }
        $geprueft = (string) ($pruefung['geprueft_am'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $geprueft) !== 1 || Zeit::ts($geprueft) === null) {
            throw new \InvalidArgumentException('Prüfung ohne gültige Zeit (geprueft_am in UTC, Y-m-d\\TH:i:s\\Z).');
        }
        $ergebnis = (string) ($pruefung['ergebnis'] ?? '');
        if (!isset(self::ERGEBNISSE[$ergebnis])) {
            throw new \InvalidArgumentException('Prüfung ohne gültiges Ergebnis.');
        }
        $sicht = (string) ($pruefung['sicht'] ?? '');
        if (!array_key_exists($sicht, self::SICHT)) {
            throw new \InvalidArgumentException('Prüfung mit unbekannter Sichtprüfung.');
        }
        $daten = array_diff_key($pruefung, array_flip(self::NUR_IN_DER_DATEI));
        try {
            $json = Kanon::json($daten);
        } catch (\InvalidArgumentException|\JsonException $e) {
            throw new \InvalidArgumentException('Prüfung nicht in der Normalform: ' . $e->getMessage(), 0, $e);
        }
        $zeile = [
            'pruefling_id' => $prueflingId,
            'import_id'    => $importId,
            'schluessel'   => $schluessel,
            'geprueft_am'  => $geprueft,
            'ergebnis'     => $ergebnis,
            'sicht'        => $sicht,
            'daten'        => $json,
            'created_at'   => gmdate('c'),
        ];
        foreach (self::LAENGEN as $feld => $laenge) {
            $zeile[$feld] = $feld === 'bemerkung'
                ? Messarten::absatz($pruefung[$feld] ?? '', $laenge)
                : Messarten::text($pruefung[$feld] ?? '', $laenge);
        }
        try {
            $id = Database::insert('gp_pruefungen', $zeile);
        } catch (\PDOException $e) {
            // Ein zweiter Import war schneller (UNIQUE auf schluessel): dieselbe Prüfung.
            $vorhanden = self::perSchluessel($schluessel);
            if ($vorhanden !== null) {
                return $vorhanden + ['neu' => false];
            }
            throw $e;
        }
        return ['id' => $id, 'neu' => true, 'pruefling_id' => $prueflingId];
    }

    /**
     * Eine Prüfung mit entschlüsselter Normalform (`daten` als Array) und den
     * Eckdaten ihres Prüflings (`kennung`, `bezeichnung`, `kunde_id`); null,
     * wenn es sie nicht gibt.
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $zeile = Database::first('SELECT p.*, l.kennung, l.bezeichnung, l.kunde_id
            FROM gp_pruefungen p LEFT JOIN gp_prueflinge l ON l.id = p.pruefling_id WHERE p.id = ?', [$id]);
        if ($zeile === null) {
            return null;
        }
        $daten = json_decode((string) $zeile['daten'], true);
        $zeile['daten'] = is_array($daten) ? $daten : [];
        return self::aufbereiten($zeile);
    }

    /**
     * Prüfungen eines Prüflings, die jüngste zuerst (ohne `daten`).
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerPruefling(int $prueflingId): array
    {
        return self::liste(['pruefling_id' => $prueflingId, 'limit' => 5000]);
    }

    /**
     * Prüfungen nach Filtern, die jüngste zuerst, ohne `daten` (die Normalform
     * lädt erst {@see find()}). Jede Zeile trägt zusätzlich `kennung`,
     * `bezeichnung`, `kunde_id` des Prüflings und `bericht_id` – den gültigen
     * Prüfbericht oder null (dann ist die Prüfung „offen“).
     *
     * Filter (alle freiwillig):
     * - `pruefling_id` (int)
     * - `besitzer`: 'eigen' (eigener Bestand) oder eine Kunden-ID
     * - `ergebnis`: bestanden | nicht_bestanden
     * - `pruefer`: Name laut Gerät (genau)
     * - `von`, `bis`: Ortsdatum JJJJ-MM-TT (ganze Tage in der Zeitzone der Installation)
     * - `offen`: true = ohne gültigen Bericht, false = mit
     * - `suche`: Teil der Kennung, Bezeichnung oder Seriennummer des Prüflings
     * - `limit` (Vorgabe 500, höchstens 5000), `offset`
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public static function liste(array $filter = []): array
    {
        $wo = [];
        $werte = [];
        if (isset($filter['pruefling_id'])) {
            $wo[] = 'p.pruefling_id = ?';
            $werte[] = (int) $filter['pruefling_id'];
        }
        $besitzer = $filter['besitzer'] ?? null;
        if ($besitzer === 'eigen') {
            $wo[] = 'l.kunde_id IS NULL';
        } elseif (is_int($besitzer) || (is_string($besitzer) && ctype_digit($besitzer))) {
            $wo[] = 'l.kunde_id = ?';
            $werte[] = (int) $besitzer;
        }
        if (isset($filter['ergebnis']) && isset(self::ERGEBNISSE[(string) $filter['ergebnis']])) {
            $wo[] = 'p.ergebnis = ?';
            $werte[] = (string) $filter['ergebnis'];
        }
        if (isset($filter['pruefer']) && (string) $filter['pruefer'] !== '') {
            $wo[] = 'p.pruefer = ?';
            $werte[] = (string) $filter['pruefer'];
        }
        foreach (['von' => '>=', 'bis' => '<'] as $feld => $vergleich) {
            $tag = (string) ($filter[$feld] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag) !== 1 || !checkdate((int) substr($tag, 5, 2), (int) substr($tag, 8, 2), (int) substr($tag, 0, 4))) {
                continue;
            }
            // Beginn des Tages (von) bzw. des Folgetags (bis) in Ortszeit, als UTC.
            $start = new \DateTimeImmutable($tag . ' 00:00:00', Zeit::zone());
            if ($feld === 'bis') {
                $start = $start->modify('+1 day');
            }
            $wo[] = 'p.geprueft_am ' . $vergleich . ' ?';
            $werte[] = $start->setTimezone(new \DateTimeZone('UTC'))->format(Messarten::UTC_FORMAT);
        }
        if (array_key_exists('offen', $filter) && $filter['offen'] !== null) {
            $wo[] = ($filter['offen'] ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM gp_berichte b WHERE b.pruefung_id = p.id AND b.status = 'gueltig')";
        }
        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            // „!“ als Fluchtzeichen: unter SQLite und MySQL gleich (ein Backslash nicht).
            $muster = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $suche) . '%';
            $wo[] = "(l.kennung LIKE ? ESCAPE '!' OR l.bezeichnung LIKE ? ESCAPE '!' OR l.seriennummer LIKE ? ESCAPE '!')";
            array_push($werte, $muster, $muster, $muster);
        }
        $limit = max(1, min(5000, (int) ($filter['limit'] ?? 500)));
        $offset = max(0, (int) ($filter['offset'] ?? 0));
        $sql = 'SELECT p.id, p.pruefling_id, p.import_id, p.schluessel, p.geprueft_am, p.am_geraet, p.pruefer, p.prueffirma,
                p.ort, p.auftrag, p.bemerkung, p.ablauf_name, p.ablauf_geraet_id, p.messgeraet, p.messgeraet_sn, p.firmware,
                p.ergebnis, p.sicht, p.created_at, l.kennung, l.bezeichnung, l.kunde_id,
                (SELECT MAX(b.id) FROM gp_berichte b WHERE b.pruefung_id = p.id AND b.status = \'gueltig\') AS bericht_id
            FROM gp_pruefungen p LEFT JOIN gp_prueflinge l ON l.id = p.pruefling_id'
            . ($wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo))
            . ' ORDER BY p.geprueft_am DESC, p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        return array_map([self::class, 'aufbereiten'], Database::select($sql, $werte));
    }

    /**
     * Ein Teil der Normalform als Liste von Arrays (alles andere fällt weg).
     *
     * @return list<array<string,mixed>>
     */
    private static function alsListe(mixed $wert): array
    {
        return is_array($wert) ? array_values(array_filter($wert, 'is_array')) : [];
    }

    /** @return array{id:int,pruefling_id:int}|null */
    private static function perSchluessel(string $schluessel): ?array
    {
        $z = Database::first('SELECT id, pruefling_id FROM gp_pruefungen WHERE schluessel = ?', [$schluessel]);
        return $z === null ? null : ['id' => (int) $z['id'], 'pruefling_id' => (int) $z['pruefling_id']];
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function aufbereiten(array $z): array
    {
        foreach (['id', 'pruefling_id'] as $k) {
            $z[$k] = (int) $z[$k];
        }
        foreach (['import_id', 'kunde_id', 'bericht_id'] as $k) {
            if (array_key_exists($k, $z)) {
                $z[$k] = $z[$k] === null ? null : (int) $z[$k];
            }
        }
        return $z;
    }
}
