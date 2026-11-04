<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

/**
 * Eine **erfundene** Datenbank eines Prüfgeräts BENNING ST 755+ – für
 * Prüfskripte und die Demo. Echte Gerätedatenbanken enthalten Kundendaten
 * und gehören nie ins Repository; dieses Muster hat denselben Aufbau.
 *
 * Aufbau: `resources/benning/st755-schema.sql` (Schema einer echten
 * Datenbank, Text für Text wie in sqlite_master, nur Aufbau) und
 * `resources/benning/st755-werksdaten.sql` (Prüfabläufe ab Werk, Fragen der
 * Sichtprüfung, Grenzwertsätze, Systemdaten). Dazu erfundene Kunden, Geräte
 * und Prüfungen in der Form, in der das Gerät schreibt: `Kunden`, `Geraete`
 * (+ `Manufacturers`, `Geraete2AdminQuestions`), je Prüfung eine breite Zeile
 * in `Ergebnisse` (Mess- und Grenzwerte als REAL mit float32-Resten) und die
 * lesbare Form `Sequences` → `SqProcedures` → `SqProcValues` samt Antworten
 * der Sichtprüfung (`TestResult2Admin2Answers`); Seitengröße 1024, Journal
 * „delete“ (Kopf-Bytes 18/19 = 1) wie am Gerät.
 *
 * Die festen **Fälle** ({@see schreiben()}, Schlüssel in `faelle` der
 * Rückgabe):
 *
 * | Fall | Kunde | Kennung | was |
 * |------|-------|---------|-----|
 * | sk1_ok | 1 | 000101 | RPE > RISO-1 > Funkt. (IPE) bestanden, „>100.0 MΩ“, Winterzeit, mit Hersteller (Manufacturers) |
 * | sk2_ok | 1 | 000102 | RISO-1 (LN-Körper) > Funkt. mit IBer. „<0.030 mA“, Sommerzeit, Zeit mit `T` und `.0000000` |
 * | leitung_ok | 1 | „ 000103“ | RISO-1 > Kabel, führendes Leerzeichen in der Kennung, ohne Fragen |
 * | leitung_fehler | 2 | 000104 | Leitungsprüfung gescheitert („>15.000 Ω“ ungültig, Schritt und Sequenz 0) |
 * | leitung_nachpruefung | 2 | 000104 | dasselbe Gerät später bestanden, Zeit mit Leerzeichen und `.1234567` |
 * | funkt_fehler | 2 | 000105 | Funktionsprüfung mit IBer. gescheitert (SK II), Zeit mit `T` und `.2092494` |
 * | sicht_fehler | 2 | 000106 | Sichtprüfung nicht in Ordnung: keine Schritte, Sequenz 0/0, Antworten 2 |
 * | prcd | 1 | 000107 | RPE > RISO-IN > RISO-OUT > IPE > IBer. > PRCD |
 * | ua | 3 | 000108 | RISO-3 > Ua > Funkt. (SK II mit Ausgangsspannung) |
 * | riso_out | 1 | 000109 | RPE > RISO-1 > RISO-OUT > Funkt. > Kabel, Ablauf am Gerät angelegt (1000001) |
 * | admin | 3 | 000110 | Prüfer „Admin“, mit Auftrag, Ort und Bemerkung |
 * | doppelt_k2, doppelt_k3 | 2, 3 | 000200 | dieselbe Kennung bei zwei Kunden |
 * | doppelt_alt, doppelt_neu | 1 | 000300 | dieselbe Kennung zweimal beim selben Kunden |
 * | abweichung | 1 | 000111 | Gerät meldet bestanden, ein Wert aber ungültig → abgeleitet nicht bestanden |
 * | ohne_schritte | 1 | 000112 | Sequenz ohne Schritte mit success 1 (typeId 2) → bestanden |
 * | leere_kennung | 2 | „   “ | Kennung leer nach dem Trimmen (der Import übergeht das Gerät) |
 * | ohne_pruefung | 3 | 000113 | SK III, nie geprüft (Prüfdatum „1900-01-01T00:00:00.0000000“) |
 * | ausser_betrieb | 1 | 000114 | außer Betrieb, letzte Prüfung über ein Jahr alt |
 *
 * Kunde 1 ist die eigene Firma (wie in der Praxis: eigener Bestand), Kunde 2
 * hat die Kundennummer „K-20001“, Kunde 3 keine. Am Gerät angelegt sind die
 * Prüfabläufe 1000000 („Individuell“), 1000001 und 1000002 (ungültig).
 *
 * Alles ist **deterministisch** über den Startwert (`start`): dieselben
 * Optionen ergeben dieselbe Datei, auch Byte für Byte (die Zeitstempel der
 * Trigger in `timestamp` werden auf die Basiszeit gesetzt). Keine Uhr, kein
 * Zufall von außen.
 */
final class BenningMuster
{
    public const SCHEMA = '/resources/benning/st755-schema.sql';
    public const WERKSDATEN = '/resources/benning/st755-werksdaten.sql';

    public const FIRMA = 'Muster Veranstaltungstechnik GmbH';
    public const PRUEFER = 'Erika Mustermann';
    public const MESSGERAET_SN = '500000000001';
    public const FIRMWARE = '1.17';
    public const FIRMWARE_GUI = '1.08.01';

    /** Bitmasken der Schritte (`SqProcedures.bitMask`) wie am Gerät. */
    public const BITS = [
        'RPE' => 4, 'RISO-1' => 16, 'RISO-IN' => 16, 'IPE' => 32, 'IBer.' => 1024, 'Funkt.' => 131072,
        'Kabel' => 524288, 'Ua' => 1048576, 'RISO-2' => 1073741824, 'RISO-3' => 2147483648,
        'PRCD' => 43980465111040, 'RISO-OUT' => 281474976710656, 'RISO-4' => 2251799813685248,
    ];

    /** Pläne der Prüfungen (Schlüssel für `pruefungAnhaengen(['plan' => …])`). */
    public const PLAENE = ['sk1', 'sk2', 'leitung', 'leitung_fehler', 'funkt_fehler', 'sicht_fehler', 'ohne_schritte',
        'prcd', 'ua', 'riso_out', 'abweichung'];

    /** Plan → Pruefung_ID des Ablaufs. */
    private const ABLAUF_JE_PLAN = [
        'sk1' => 1, 'sk2' => 10, 'leitung' => 1006, 'leitung_fehler' => 1006, 'funkt_fehler' => 10, 'sicht_fehler' => 1,
        'ohne_schritte' => 1, 'prcd' => 61, 'ua' => 32, 'riso_out' => 1000001, 'abweichung' => 1,
    ];

    private const BEZEICHNUNGEN = ['Kabeltrommel', 'Scheinwerfer', 'Nebelmaschine', 'Verlängerung Schuko', 'Netzteil',
        'Ladegerät', 'Mehrfachsteckdose', 'Lüfter', 'Bohrmaschine', 'Kühlschrank'];

    private \PDO $pdo;
    private int $zustand;
    private int $schritte = 0;
    private int $werte = 0;
    /** @var array<int,array{name:string,ablauf:string,sk:int}> Pruefung_ID → Ablauf */
    private array $ablaeufe = [];
    private string $pruefer;
    private string $firma;
    private string $sn;

    private function __construct(\PDO $pdo, int $start, array $o)
    {
        $this->pdo = $pdo;
        // xorshift32 aus dem Startwert (nie 0)
        $this->zustand = crc32('benning-muster:' . $start) ?: 0x9E3779B9;
        $this->pruefer = (string) ($o['pruefer'] ?? self::PRUEFER);
        $this->firma = (string) ($o['firma'] ?? self::FIRMA);
        $this->sn = (string) ($o['messgeraet_sn'] ?? self::MESSGERAET_SN);
    }

    /**
     * Die erfundene Datenbank nach $ziel schreiben (die Datei darf noch nicht
     * existieren).
     *
     * Optionen:
     * - `start` (int, Vorgabe 1): Startwert aller erfundenen Messwerte
     * - `faelle` (bool, Vorgabe true): die festen Fälle (siehe Klassenkommentar)
     * - `kunden` (int, Vorgabe 3, mit Fällen mindestens 3): Kunden 4… heißen „Musterkunde n“
     * - `kunden_nummern` (array Kunden_Index → Kundennummer): überschreibt die Vorgaben
     *   (etwa [1 => 'PM-EIGEN'] wie nach einem Export)
     * - `zusaetzlich` (int, Vorgabe 0): weitere Geräte mit bestandenen Prüfungen, verteilt auf die Kunden
     * - `pruefungen_je_geraet` (int, Vorgabe 1): Prüfungen je zusätzlichem Gerät
     * - `datum` (Ortszeit `Y-m-d H:i:s`, Vorgabe 2026-04-01 08:00:00): Beginn der zusätzlichen Prüfungen
     * - `pruefer`, `firma`, `messgeraet_sn`: Angaben in den Prüfungen
     * - `eigene_ablaeufe` (bool, Vorgabe true): am Gerät angelegte Prüfabläufe 1000000–1000002
     *
     * @param array<string,mixed> $optionen
     * @return array{datei:string,kunden:int,geraete:int,pruefungen:int,schritte:int,werte:int,
     *               faelle:array<string,array{kunde:int,geraet:int,kennung:string,ergebnis:?int,erwartet:?string}>}
     */
    public static function schreiben(string $ziel, array $optionen = []): array
    {
        if (file_exists($ziel) || !is_dir(dirname($ziel))) {
            throw new \InvalidArgumentException('BenningMuster: Ziel existiert schon oder Ordner fehlt.');
        }
        $faelle = ($optionen['faelle'] ?? true) !== false;
        $kunden = max($faelle ? 3 : 0, (int) ($optionen['kunden'] ?? 3));
        $zusaetzlich = max(0, (int) ($optionen['zusaetzlich'] ?? 0));
        if ($zusaetzlich > 0 && $kunden === 0) {
            $kunden = 1;
        }
        $pdo = new \PDO('sqlite:' . $ziel, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        try {
            $pdo->exec('PRAGMA page_size = 1024');
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo->exec(self::datei(self::SCHEMA));
            $pdo->exec(self::datei(self::WERKSDATEN));
            $m = new self($pdo, (int) ($optionen['start'] ?? 1), $optionen);
            $pdo->exec('BEGIN');
            if (($optionen['eigene_ablaeufe'] ?? true) !== false) {
                $m->eigeneAblaeufe();
            }
            $m->ablaeufeLaden();
            $m->hersteller();
            $nummern = is_array($optionen['kunden_nummern'] ?? null) ? $optionen['kunden_nummern'] : [];
            for ($k = 1; $k <= $kunden; $k++) {
                $m->kunde($k, isset($nummern[$k]) ? (string) $nummern[$k] : null);
            }
            $ergebnis = ['faelle' => []];
            $geraet = 0;
            if ($faelle) {
                $ergebnis['faelle'] = $m->faelle($geraet);
            }
            $basis = (string) ($optionen['datum'] ?? '2026-04-01 08:00:00');
            $je = max(1, (int) ($optionen['pruefungen_je_geraet'] ?? 1));
            for ($i = 1; $i <= $zusaetzlich; $i++) {
                $plan = ['sk1', 'sk2', 'leitung'][$m->zahl(0, 2)];
                $sk = $plan === 'sk2' ? 2 : 1;
                $g = $m->geraet(++$geraet, 1 + ($i - 1) % $kunden, sprintf('%06d', 100000 + $i),
                    self::BEZEICHNUNGEN[$m->zahl(0, count(self::BEZEICHNUNGEN) - 1)], $sk, self::ABLAUF_JE_PLAN[$plan]);
                $letzte = null;
                for ($n = 0; $n < $je; $n++) {
                    $zeit = (new \DateTimeImmutable($basis, new \DateTimeZone('UTC')))->modify('+' . ($i * 7 + $n * 43201) . ' minutes')->format('Y-m-d H:i:s');
                    $m->pruefungEintragen($g, $plan, $zeit, []);
                    $letzte = $zeit;
                }
                $m->pruefdatumSetzen($g['index'], (string) $letzte);
            }
            $basisTs = (new \DateTimeImmutable($basis, new \DateTimeZone('UTC')))->getTimestamp();
            $pdo->prepare('UPDATE timestamp SET lastchange = ?')->execute([(string) $basisTs]);
            $pdo->exec('COMMIT');
            $zaehlen = static fn (string $t): int => (int) $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
            return [
                'datei'      => $ziel,
                'kunden'     => $zaehlen('Kunden'),
                'geraete'    => $zaehlen('Geraete'),
                'pruefungen' => $zaehlen('Ergebnisse'),
                'schritte'   => $m->schritte,
                'werte'      => $m->werte,
                'faelle'     => $ergebnis['faelle'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            unset($pdo);
            @unlink($ziel);
            throw $e;
        }
    }

    /**
     * „Am Gerät“ eine Prüfung in eine vorhandene Datenbank des Prüfgeräts
     * eintragen (etwa einen Export von PowerManagement) – wie das Gerät: eine
     * Zeile `Ergebnisse`, `Sequences`, `SqProcedures`, `SqProcValues`, die
     * Antworten der Sichtprüfung, dazu Prüfdatum, nächste Prüfung und
     * Änderungsdatum am Gerät.
     *
     * Angaben:
     * - `kennung` (Pflicht): `Geraete_ID` (verglichen getrimmt)
     * - `kunden_nummer` oder `kunden_index`: wenn die Kennung bei mehreren Kunden vorkommt
     * - `datum` (Ortszeit `Y-m-d H:i:s`, Vorgabe 2026-06-01 10:00:00)
     * - `plan` (einer aus {@see PLAENE}; Vorgabe: „leitung“ bei Leitungsabläufen, sonst nach Schutzklasse)
     * - `pruefer`, `firma`, `messgeraet_sn`, `start` (Startwert der Messwerte)
     *
     * Genau ein Gerät muss passen, sonst \InvalidArgumentException.
     *
     * @param array<string,mixed> $angaben
     * @return array{ergebnis:int,geraet:int}
     */
    public static function pruefungAnhaengen(string $datei, array $angaben): array
    {
        if (!is_file($datei)) {
            throw new \InvalidArgumentException('BenningMuster: Datei fehlt.');
        }
        $kennung = trim((string) ($angaben['kennung'] ?? ''));
        if ($kennung === '') {
            throw new \InvalidArgumentException('BenningMuster: Kennung fehlt.');
        }
        $pdo = new \PDO('sqlite:' . $datei, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $sql = 'SELECT g.Geraete_Index, g.Geraete_Kunden_Index, g.Geraete_SK, g.Geraete_Pruefablauf_Index,
                g.Geraete_Pruefablauf_Name, g.Geraete_Pruefablauf, g.Geraete_Pruefintervall, g.Geraete_Leitungslaenge
            FROM Geraete g LEFT JOIN Kunden k ON k.Kunden_Index = g.Geraete_Kunden_Index WHERE TRIM(g.Geraete_ID) = ?';
        $werte = [$kennung];
        if (isset($angaben['kunden_nummer'])) {
            $sql .= ' AND k.Kunden_Nummer = ?';
            $werte[] = (string) $angaben['kunden_nummer'];
        }
        if (isset($angaben['kunden_index'])) {
            $sql .= ' AND g.Geraete_Kunden_Index = ?';
            $werte[] = (int) $angaben['kunden_index'];
        }
        $st = $pdo->prepare($sql);
        $st->execute($werte);
        $treffer = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (count($treffer) !== 1) {
            throw new \InvalidArgumentException('BenningMuster: ' . count($treffer) . ' Geräte mit dieser Kennung.');
        }
        $z = $treffer[0];
        $name = (string) $z['Geraete_Pruefablauf_Name'];
        $plan = (string) ($angaben['plan'] ?? (mb_stripos($name, 'Leitung') !== false ? 'leitung' : ((int) $z['Geraete_SK'] === 2 ? 'sk2' : 'sk1')));
        if (!in_array($plan, self::PLAENE, true)) {
            throw new \InvalidArgumentException('BenningMuster: unbekannter Plan.');
        }
        $datum = (string) ($angaben['datum'] ?? '2026-06-01 10:00:00');
        $m = new self($pdo, (int) ($angaben['start'] ?? 7), $angaben);
        $pdo->exec('BEGIN');
        try {
            $g = [
                'index' => (int) $z['Geraete_Index'], 'sk' => (int) $z['Geraete_SK'],
                'ablauf_id' => (int) $z['Geraete_Pruefablauf_Index'], 'ablauf_name' => $name,
                'ablauf' => (string) $z['Geraete_Pruefablauf'], 'laenge' => (float) ($z['Geraete_Leitungslaenge'] ?? 1.5),
            ];
            $ergebnis = $m->pruefungEintragen($g, $plan, $datum, $angaben);
            $intervall = max(1, (int) ($z['Geraete_Pruefintervall'] ?? 12));
            $naechste = Messarten::monatePlus(substr($datum, 0, 10), $intervall) . ' 00:00:00';
            $pdo->prepare('UPDATE Geraete SET Geraete_Pruefdatum = ?, Geraete_Datum_Naechste_Pruefung = ?, Aenderungsdatum = ? WHERE Geraete_Index = ?')
                ->execute([$datum, $naechste, $datum, $g['index']]);
            $ts = (new \DateTimeImmutable($datum, new \DateTimeZone('UTC')))->getTimestamp();
            $pdo->prepare('UPDATE timestamp SET lastchange = ? WHERE tablename = ?')->execute([(string) $ts, 'Ergebnisse']);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return ['ergebnis' => $ergebnis, 'geraet' => $g['index']];
    }

    /**
     * Die Anweisungen der Schema-Datei so, wie SQLite sie in
     * `sqlite_master.sql` ablegt (ohne Kopfkommentar und Semikolon), in ihrer
     * Reihenfolge – zum Abgleich einer Datenbank mit dem Aufbau des Geräts.
     *
     * @return list<string>
     */
    public static function schemaAnweisungen(): array
    {
        $text = str_replace("\r\n", "\n", self::datei(self::SCHEMA));
        $zeilen = explode("\n", $text);
        while ($zeilen !== [] && str_starts_with($zeilen[0], '--')) {
            array_shift($zeilen);
        }
        $rumpf = rtrim(implode("\n", $zeilen));
        $teile = explode(";\n", $rumpf);
        $letzte = array_pop($teile);
        $teile[] = str_ends_with((string) $letzte, ';') ? substr((string) $letzte, 0, -1) : (string) $letzte;
        return array_values(array_filter($teile, static fn (string $t): bool => trim($t) !== ''));
    }

    // ------------------------------------------------------------ Bestand

    private static function datei(string $relativ): string
    {
        $inhalt = file_get_contents(APP_ROOT . $relativ);
        if ($inhalt === false) {
            throw new \RuntimeException('BenningMuster: ' . $relativ . ' fehlt.');
        }
        return $inhalt;
    }

    /** Am Gerät angelegte Prüfabläufe (erfunden): Individuell, ein eigener, ein ungültiger. */
    private function eigeneAblaeufe(): void
    {
        $st = $this->pdo->prepare('INSERT INTO Pruefung (Pruefung_Name, Pruefung_Ablauf, Ungueltig, Pruefung_SK, Pruefung_ID,
            Pruefung_Nr, Fest, Grenzwerte_Index, Individuell, Pruefung_Ablauf_2) VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?, 0)');
        $bits = static fn (string ...$arten): int => array_sum(array_map(static fn (string $a): int => self::BITS[$a], $arten)) | 3;
        $st->execute(['Individuell', $bits('RPE', 'RISO-1', 'Funkt.'), 0, 1, 1000000, null, 1]);
        $st->execute(['40 Akkuleuchte mit Ladeplatte', $bits('RPE', 'RISO-1', 'RISO-OUT', 'Funkt.', 'Kabel'), 0, 1, 1000001, 40, 0]);
        $st->execute(['41 Alter Ablauf', $bits('RISO-1', 'Kabel'), 1, 1, 1000002, 41, 0]);
    }

    private function ablaeufeLaden(): void
    {
        foreach ($this->pdo->query('SELECT Pruefung_ID, Pruefung_Name, Pruefung_Ablauf, Pruefung_SK FROM Pruefung', \PDO::FETCH_ASSOC) as $z) {
            $this->ablaeufe[(int) $z['Pruefung_ID']] = ['name' => (string) $z['Pruefung_Name'], 'ablauf' => (string) $z['Pruefung_Ablauf'], 'sk' => (int) $z['Pruefung_SK']];
        }
    }

    private function hersteller(): void
    {
        $st = $this->pdo->prepare('INSERT INTO Manufacturers (name) VALUES (?)');
        foreach (['Musterwerk', 'Beispiel Elektro', 'Lichtbau Muster'] as $name) {
            $st->execute([$name]);
        }
    }

    private function kunde(int $index, ?string $nummer): void
    {
        $vorgaben = [
            1 => [self::FIRMA, 'Musterstraße 1', '12345', 'Musterstadt', '01234 567890', 'info@muster-vt.example', 'Max Mustermann', 'Lager', '', '2026-01-05T08:00:00.0000000'],
            2 => ['Beispiel Events AG', 'Beispielweg 7', '54321', 'Beispielhausen', '09876 54321', 'technik@beispiel-events.example', 'Erika Beispiel', 'Technik', 'K-20001', '2026-01-06 09:30:00'],
            3 => ['Testbühne Nord e. V.', null, null, null, null, null, null, null, null, '2026-01-07 10:00:00.1234567'],
        ];
        $v = $vorgaben[$index] ?? ['Musterkunde ' . $index, 'Musterallee ' . $index, '1' . str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'Musterdorf', null, null, null, null, 'K-' . (20000 + $index), '2026-01-08 11:00:00'];
        $this->pdo->prepare('INSERT INTO Kunden (Kunden_Index, Kunden_Name, Kunden_Strasse, Kunden_PLZ, Kunden_Wohnort, Kunden_Rufnummer,
                Kunden_Bemerkung, Kunden_Fax, Kunden_Email, Kunden_Ansprechpartner, Ansprechpartner_Abteilung, Ansprechpartner_Rufnummer,
                Ansprechpartner_Email, Aenderungsdatum, Kunden_Nummer, Kunden_cloud_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)')
            ->execute([$index, $v[0], $v[1], $v[2], $v[3], $v[4], $index === 1 ? 'Eigener Bestand' : null, null, $v[5], $v[6], $v[7], null, null, $v[9], $nummer ?? $v[8]]);
    }

    /**
     * Die festen Fälle (Tabelle im Klassenkommentar).
     *
     * @return array<string,array{kunde:int,geraet:int,kennung:string,ergebnis:?int,erwartet:?string}>
     */
    private function faelle(int &$geraet): array
    {
        $faelle = [];
        $fall = function (string $name, int $kunde, string $kennung, string $bezeichnung, int $sk, string $plan, ?string $zeit,
            array $geraetFelder = [], array $angaben = [], ?string $erwartet = 'bestanden') use (&$faelle, &$geraet): array {
            $g = $this->geraet(++$geraet, $kunde, $kennung, $bezeichnung, $sk, self::ABLAUF_JE_PLAN[$plan], $geraetFelder);
            $ergebnis = $zeit === null ? null : $this->pruefungEintragen($g, $plan, $zeit, $angaben);
            $faelle[$name] = ['kunde' => $kunde, 'geraet' => $g['index'], 'kennung' => $kennung, 'ergebnis' => $ergebnis,
                'erwartet' => $zeit === null ? null : $erwartet];
            return $g;
        };
        $g = $fall('sk1_ok', 1, '000101', 'Nebelmaschine', 1, 'sk1', '2026-01-12 09:15:00', ['hersteller' => 'Musterwerk',
            'typ' => 'NM-1500', 'modell' => 'Pro', 'sn' => 'MW-0001', 'abteilung' => 'Licht', 'gebaeude' => 'Lager A', 'nennleistung' => 1.6]);
        $this->pruefdatumSetzen($g['index'], '2026-01-12 09:15:00');
        $g = $fall('sk2_ok', 1, '000102', 'Ladegerät Akkuschrauber', 2, 'sk2', '2026-07-14T14:30:00.0000000', ['hersteller' => 'Beispiel Elektro']);
        $this->pruefdatumSetzen($g['index'], '2026-07-14T14:30:00.0000000');
        $g = $fall('leitung_ok', 1, ' 000103', 'Verlängerung Schuko 10 m', 1, 'leitung', '2026-03-02 10:00:00', ['laenge' => 10.0, 'fragen' => false]);
        $this->pruefdatumSetzen($g['index'], '2026-03-02 10:00:00');
        $g = $fall('leitung_fehler', 2, '000104', 'Verlängerung Schuko 25 m', 1, 'leitung_fehler', '2026-03-03 08:05:10', ['laenge' => 25.0], [], 'nicht_bestanden');
        $nach = $this->pruefungEintragen($g, 'leitung', '2026-03-03 11:20:00.1234567', []);
        $faelle['leitung_nachpruefung'] = ['kunde' => 2, 'geraet' => $g['index'], 'kennung' => '000104', 'ergebnis' => $nach, 'erwartet' => 'bestanden'];
        $this->pruefdatumSetzen($g['index'], '2026-03-03 11:20:00.1234567');
        $g = $fall('funkt_fehler', 2, '000105', 'Heizlüfter', 2, 'funkt_fehler', '2026-03-04T09:00:00.2092494', [], [], 'nicht_bestanden');
        $this->pruefdatumSetzen($g['index'], '2026-03-04T09:00:00.2092494');
        $g = $fall('sicht_fehler', 2, '000106', 'Kaffeemaschine', 1, 'sicht_fehler', '2026-03-05 13:45:00', [], [], 'nicht_bestanden');
        $this->pruefdatumSetzen($g['index'], '2026-03-05 13:45:00');
        $g = $fall('prcd', 1, '000107', 'PRCD-S+ Adapter', 1, 'prcd', '2026-03-06 07:59:59', ['hersteller' => 'Lichtbau Muster', 'laenge' => 3.0]);
        $this->pruefdatumSetzen($g['index'], '2026-03-06 07:59:59');
        $g = $fall('ua', 3, '000108', 'Netzteil 12 V', 2, 'ua', '2026-03-09 16:00:00', ['u_ausgang' => 12.0]);
        $this->pruefdatumSetzen($g['index'], '2026-03-09 16:00:00');
        $g = $fall('riso_out', 1, '000109', 'Akkuleuchte', 1, 'riso_out', '2026-03-10 12:00:00', ['laenge' => 5.0, 'typ' => 'AL-5', 'modell' => 'Ladeplatte']);
        $this->pruefdatumSetzen($g['index'], '2026-03-10 12:00:00');
        $g = $fall('admin', 3, '000110', 'Tischleuchte', 2, 'sk2', '2026-03-11 10:10:10', [], ['pruefer' => 'Admin',
            'auftrag' => 'A-2026-0042', 'ort' => 'Halle 3', 'bemerkung' => 'Stecker erneuert']);
        $this->pruefdatumSetzen($g['index'], '2026-03-11 10:10:10');
        $g = $fall('doppelt_k2', 2, '000200', 'Lautsprecher aktiv', 1, 'sk1', '2026-03-12 09:00:00');
        $this->pruefdatumSetzen($g['index'], '2026-03-12 09:00:00');
        $g = $fall('doppelt_k3', 3, '000200', 'Mischpult', 2, 'sk2', '2026-03-12 09:30:00');
        $this->pruefdatumSetzen($g['index'], '2026-03-12 09:30:00');
        $g = $fall('doppelt_alt', 1, '000300', 'Scheinwerfer alt', 1, 'sk1', '2026-02-01 09:00:00');
        $this->pruefdatumSetzen($g['index'], '2026-02-01T09:00:00.0000000');
        $g = $fall('doppelt_neu', 1, '000300', 'Scheinwerfer', 1, 'sk1', '2026-03-13 09:00:00', ['sn' => 'LB-7']);
        $this->pruefdatumSetzen($g['index'], '2026-03-13 09:00:00');
        $g = $fall('abweichung', 1, '000111', 'Bohrmaschine', 1, 'abweichung', '2026-03-16 08:00:00', [], [], 'nicht_bestanden');
        $this->pruefdatumSetzen($g['index'], '2026-03-16 08:00:00');
        $g = $fall('ohne_schritte', 1, '000112', 'Steckdosenleiste', 1, 'ohne_schritte', '2026-03-17 08:30:00');
        $this->pruefdatumSetzen($g['index'], '2026-03-17 08:30:00');
        $g = $fall('leere_kennung', 2, '   ', 'Unbeschriftetes Gerät', 1, 'sk1', '2026-03-18 09:00:00');
        $this->pruefdatumSetzen($g['index'], '2026-03-18 09:00:00');
        $fall('ohne_pruefung', 3, '000113', 'Schutzkleinspannungsleuchte', 3, 'sk1', null, ['ablauf_id' => 15]);
        $g = $fall('ausser_betrieb', 1, '000114', 'Alter Strahler', 1, 'sk1', '2025-02-20 10:00:00', ['ausser_betrieb' => true]);
        $this->pruefdatumSetzen($g['index'], '2025-02-20 10:00:00');
        return $faelle;
    }

    /**
     * Ein Gerät anlegen (`Geraete`, Fragen 1 und 2 außer mit 'fragen' => false).
     *
     * @param array<string,mixed> $f hersteller, typ, modell, sn, abteilung, gebaeude, laenge, nennleistung, u_ausgang, ausser_betrieb, fragen, ablauf_id
     * @return array{index:int,sk:int,ablauf_id:int,ablauf_name:string,ablauf:string,laenge:float}
     */
    private function geraet(int $index, int $kunde, string $kennung, string $bezeichnung, int $sk, int $ablaufId, array $f = []): array
    {
        $ablaufId = (int) ($f['ablauf_id'] ?? $ablaufId);
        $ablauf = $this->ablaeufe[$ablaufId] ?? ['name' => '1 Geräte SK I', 'ablauf' => '19005463', 'sk' => 1];
        $hersteller = $f['hersteller'] ?? null;
        $herstellerId = null;
        if (is_string($hersteller)) {
            $st = $this->pdo->prepare('SELECT id FROM Manufacturers WHERE name = ?');
            $st->execute([$hersteller]);
            $herstellerId = $st->fetchColumn() ?: null;
        }
        $laenge = (float) ($f['laenge'] ?? [1.5, 0.5, 2.0, 5.0][$this->zahl(0, 3)]);
        $aenderung = ['2026-01-02 08:00:00', '2026-01-02T08:00:00.0000000', '2026-01-02 08:00:00.1234567', '2026-01-02 08:00:00.123456',
            '2026-01-02 08:00:00.12345'][$index % 5];
        $this->pdo->prepare('INSERT INTO Geraete (Geraete_Index, Geraete_ID, Geraete_Kunden_Index, Geraete_Bezeichnung, Geraete_Bemerkung,
                Geraete_Abteilung, Geraete_Pruefablauf, Geraete_Pruefoptionen, Geraete_Pruefdatum, Geraete_Pruefablauf_Name,
                Geraete_Datum_Naechste_Pruefung, Geraete_Pruefintervall, Geraete_Nennleistung, Geraete_SN, Geraete_Hersteller,
                Aenderungsdatum, Geraete_VDE, Geraete_SK, Geraete_Zusatz_Pruefungen, manufacturerId, Geraete_Sichtpruefung,
                Geraete_Pruefablauf_Index, Geraete_Leitungslaenge, Geraete_Anz_Leiter, Geraete_Leitungsquerschnitt, Geraete_U_Ausgang,
                Gebaeude, AusserBetrieb, Geraete_Typ, Geraete_Modell, Geraete_Private_Anschaffung, Geraete_Pruefablauf_2)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 12, ?, ?, ?, ?, 1, ?, 0, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
            ->execute([$index, $kennung, $kunde, $bezeichnung, '', (string) ($f['abteilung'] ?? ''), (int) $ablauf['ablauf'],
                '1900-01-01T00:00:00.0000000', $ablauf['name'], '1900-01-01T00:00:00.0000000', self::f32((float) ($f['nennleistung'] ?? 0.0)),
                $f['sn'] ?? null, $hersteller, $aenderung, $sk, $herstellerId, $ablaufId, self::f32($laenge),
                self::f32($sk === 2 ? 2.0 : 3.0), self::f32($laenge >= 10 ? 1.5 : [0.75, 1.0, 1.5][$this->zahl(0, 2)]),
                isset($f['u_ausgang']) ? self::f32((float) $f['u_ausgang']) : null, $f['gebaeude'] ?? null,
                ($f['ausser_betrieb'] ?? false) === true ? 1 : 0, $f['typ'] ?? null, $f['modell'] ?? null]);
        if (($f['fragen'] ?? true) !== false) {
            $st = $this->pdo->prepare('INSERT INTO Geraete2AdminQuestions (GeraeteId, QuestionId) VALUES (?, ?)');
            $st->execute([$index, 1]);
            $st->execute([$index, 2]);
        }
        return ['index' => $index, 'sk' => $sk, 'ablauf_id' => $ablaufId, 'ablauf_name' => $ablauf['name'],
            'ablauf' => $ablauf['ablauf'], 'laenge' => $laenge];
    }

    /** Prüfdatum und nächste Prüfung am Gerät (Ortszeit wie das Gerät, nächste um 00:00:00). */
    private function pruefdatumSetzen(int $index, string $pruefdatum): void
    {
        $naechste = Messarten::monatePlus(substr($pruefdatum, 0, 10), 12) . ' 00:00:00';
        $this->pdo->prepare('UPDATE Geraete SET Geraete_Pruefdatum = ?, Geraete_Datum_Naechste_Pruefung = ? WHERE Geraete_Index = ?')
            ->execute([$pruefdatum, $naechste, $index]);
    }

    /**
     * Eine Prüfung eintragen wie das Gerät. Gibt den Ergebnisse_Index zurück.
     *
     * @param array{index:int,sk:int,ablauf_id:int,ablauf_name:string,ablauf:string,laenge:float} $g
     * @param array<string,mixed> $angaben pruefer, firma, auftrag, ort, bemerkung, messgeraet_sn
     */
    private function pruefungEintragen(array $g, string $plan, string $zeit, array $angaben): int
    {
        [$schritte, $spalten, $messung, $sicht, $typ] = $this->plan($plan, $g);
        $bits = 0;
        foreach ($schritte as $s) {
            $bits |= self::BITS[$s['art']];
        }
        $zeile = $spalten + [
            'Ergebnisse_Geraete_Index'      => $g['index'],
            'Ergebnisse_Grenzwerte_Index'   => null,
            'Ergebnisse_Datum'              => $zeit,
            'Ergebnisse_Pruefablauf'        => (int) $g['ablauf'],
            'Ergebnisse_Auftragsnummer'     => (string) ($angaben['auftrag'] ?? ''),
            'Ergebnisse_Messgeraet'         => Messarten::MESSGERAET,
            'Ergebnisse_Pruefername'        => (string) ($angaben['pruefer'] ?? $this->pruefer),
            'Ergebnisse_Prueffirma'         => (string) ($angaben['firma'] ?? $this->firma),
            'Ergebnisse_Zusatzpruefungen'   => 0,
            'Ergebnisse_Ort'                => (string) ($angaben['ort'] ?? ''),
            'Ergebnisse_Bemerkung'          => (string) ($angaben['bemerkung'] ?? ''),
            'Ergebnisse_Pruefablauf_Name'   => $g['ablauf_name'],
            'Ergebnisse_Pruefablauf_Ok'     => 0,
            'Ergebnisse_Softversion_Messgeraet' => self::FIRMWARE,
            'Ergebnisse_Softversion_GUI'    => self::FIRMWARE_GUI,
            'Ergebnisse_Pruefablauf_Index'  => $g['ablauf_id'],
            'Ergebnisse_SN_Messgerae'       => (string) ($angaben['messgeraet_sn'] ?? $this->sn),
            'Ergebnisse_Pruefung'           => 1,
            'Ergebnisse_Pruefablauf_getestet' => $bits | 3,
            'Ergebnisse_Landeskenner'       => 0,
            'Ergebnisse_Pruefablauf_getestet_2' => 2,
        ];
        $spaltenNamen = array_keys($zeile);
        $this->pdo->prepare('INSERT INTO Ergebnisse (' . implode(', ', $spaltenNamen) . ') VALUES (' . implode(', ', array_fill(0, count($zeile), '?')) . ')')
            ->execute(array_values($zeile));
        $ergebnis = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO Sequences (deviceId, typeId, testResultId, startDate, remark, success, viewTestOk) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$g['index'], $typ, $ergebnis, $zeit, '', $messung ? 1 : 0, $sicht ? 1 : 0]);
        $sequenz = (int) $this->pdo->lastInsertId();
        $stSchritt = $this->pdo->prepare('INSERT INTO SqProcedures (sequenceId, procName, bitMask, success, bitMask_2) VALUES (?, ?, ?, ?, 0)');
        $stWert = $this->pdo->prepare('INSERT INTO SqProcValues (sqProcId, ident, valueStr, isValid, value) VALUES (?, ?, ?, ?, ?)');
        foreach ($schritte as $s) {
            $stSchritt->execute([$sequenz, $s['art'], self::BITS[$s['art']], $s['ok'] ? 1 : 0]);
            $schritt = (int) $this->pdo->lastInsertId();
            $this->schritte++;
            foreach ($s['werte'] as [$ident, $text, $gueltig, $wert]) {
                $stWert->execute([$schritt, $ident, $text, $gueltig ? 1 : 0, $wert === null ? null : self::f32($wert)]);
                $this->werte++;
            }
        }
        $antwort = $sicht ? 1 : 2;
        $st = $this->pdo->prepare('INSERT INTO TestResult2Admin2Answers (testResultId, questionId, answerId) VALUES (?, ?, ?)');
        $st->execute([$ergebnis, 1, $antwort]);
        $st->execute([$ergebnis, 2, $antwort]);
        return $ergebnis;
    }

    // ------------------------------------------------------------ Pläne

    /**
     * Schritte, Spalten der Ergebniszeile, Messung bestanden, Sicht bestanden, typeId.
     *
     * @param array{sk:int,laenge:float} $g
     * @return array{0:list<array{art:string,ok:bool,werte:list<array{0:string,1:string,2:bool,3:?float}>}>,1:array<string,mixed>,2:bool,3:bool,4:int}
     */
    private function plan(string $plan, array $g): array
    {
        $laenge = $g['laenge'] > 0 ? $g['laenge'] : 1.5;
        $s = [];
        $sp = [];
        switch ($plan) {
            case 'sk1':
            case 'abweichung':
                $s[] = $this->rpe($sp, $laenge, $plan === 'abweichung');
                $s[] = $this->riso($sp, 'RISO-1', 'RISO-1 LN-PE', false);
                $s[] = $this->funkt($sp, false, false);
                return [$s, $sp, true, true, 1];
            case 'sk2':
                // Berührungsstrom unter der Anzeigegrenze: „<0.030 mA“
                $s[] = $this->riso($sp, 'RISO-1', 'RISO-1 LN-Körper', true);
                $s[] = $this->funkt($sp, true, false, $this->wert(0.005, 0.029, 3));
                return [$s, $sp, true, true, 1];
            case 'leitung':
                $s[] = $this->riso($sp, 'RISO-1', 'RISO-1 LN-PE', false);
                $s[] = $this->kabel($sp, $laenge, false);
                return [$s, $sp, true, true, 1];
            case 'leitung_fehler':
                $s[] = $this->riso($sp, 'RISO-1', 'RISO-1 LN-PE', false);
                $s[] = $this->kabel($sp, $laenge, true);
                return [$s, $sp, false, true, 1];
            case 'funkt_fehler':
                $s[] = $this->riso($sp, 'RISO-1', 'RISO-1 LN-PE', true);
                $s[] = $this->funkt($sp, true, true);
                return [$s, $sp, false, true, 1];
            case 'sicht_fehler':
                return [[], [], false, false, 1];
            case 'ohne_schritte':
                return [[], [], true, true, 2];
            case 'prcd':
                $s[] = $this->rpe($sp, $laenge, false);
                $s[] = $this->riso($sp, 'RISO-IN', 'RISO-IN RISO-IN', false, '0.16 MΩ', 0.16, 397);
                $s[] = $this->riso($sp, 'RISO-OUT', 'RISO-OUT RISO-OUT', false);
                $s[] = $this->strom($sp, 'IPE', $this->wert(0.5, 0.7, 3));
                $s[] = $this->strom($sp, 'IBer.', $this->wert(0.001, 0.01, 3));
                $s[] = $this->prcd($sp);
                return [$s, $sp, true, true, 1];
            case 'ua':
                $s[] = $this->riso($sp, 'RISO-3', 'RISO-3 LN-Sek.', true);
                $s[] = $this->ua($sp);
                $s[] = $this->funkt($sp, true, false);
                return [$s, $sp, true, true, 1];
            case 'riso_out':
                $s[] = $this->rpe($sp, $laenge, false);
                $s[] = $this->riso($sp, 'RISO-1', 'RISO-1 LN-PE', false);
                $s[] = $this->riso($sp, 'RISO-OUT', 'RISO-OUT LN-PE', false);
                $s[] = $this->funkt($sp, false, false);
                $s[] = $this->kabel($sp, $laenge, false);
                return [$s, $sp, true, true, 1];
        }
        throw new \InvalidArgumentException('BenningMuster: unbekannter Plan ' . $plan);
    }

    /** Grenzwert des Schutzleiters: 0,3 Ω bis 5 m, je weitere 7,5 m 0,1 Ω mehr, höchstens 1 Ω. */
    private static function rpeGrenze(float $laenge): float
    {
        return min(1.0, 0.3 + 0.1 * max(0, (int) ceil(($laenge - 5) / 7.5)));
    }

    /** @param array<string,mixed> $sp */
    private function rpe(array &$sp, float $laenge, bool $ungueltig): array
    {
        $grenze = self::rpeGrenze($laenge);
        $r = $this->wert(0.02, $grenze * 0.6, 3);
        $i = $this->wert(0.588, 0.606, 3);
        $u = round($r * $i, 3);
        $sp += ['Ergebnisse_RSL_mw_usl' => self::f32($u), 'Ergebnisse_RSL_mw_isl' => self::f32($i), 'Ergebnisse_RSL_mw_rsl' => self::f32($r),
            'Ergebnisse_RSL_mw_rsl_peak' => self::f32($r * 1.02), 'Ergebnisse_RSL_gw_rsl' => self::f32($grenze),
            'Ergebnisse_RSL_leit_laenge' => self::f32($laenge), 'Ergebnisse_RSL_anz_mess_stellen' => self::f32(1.0)];
        return ['art' => 'RPE', 'ok' => true, 'werte' => [
            ['RPE Widerstand', sprintf('%.3f Ω', $r), !$ungueltig, $r],
            ['Leitungslänge', sprintf('%.1f m', $laenge), true, $laenge],
            ['Prüfspannung', sprintf('%.3f V', $u), true, $u],
            ['Prüfstrom', sprintf('%.3f A', $i), true, $i],
            ['Anzahl RPE Mess.', '1 ', true, 1.0],
        ]];
    }

    /** @param array<string,mixed> $sp */
    private function riso(array &$sp, string $art, string $ident, bool $sk2, ?string $text = null, ?float $wert = null, int $spannung = 0): array
    {
        $u = $spannung > 0 ? $spannung + $this->zahl(0, 1) : 529 + $this->zahl(0, 8);
        $text ??= '>100.0 MΩ';
        $wert ??= 100.0;
        $strom = $text === '>100.0 MΩ' ? 0.0 : $this->wert(2.5, 2.54, 3);
        $sp += ['Ergebnisse_RISO_mw_uiso' => self::f32((float) $u), 'Ergebnisse_RISO_mw_iiso' => self::f32($strom / 1000), 'Ergebnisse_RISO_mw_riso' => self::f32($wert)];
        if ($art === 'RISO-3') {
            $sp += ['Ergebnisse_RISO_mw_riso_peak_prim_sek' => self::f32($wert), 'Ergebnisse_RISO_gw_uiso_prim_sek' => self::f32(500.0),
                'Ergebnisse_RISO_gw_riso_prim_sek' => self::f32(2.0)];
        } else {
            $sp += ['Ergebnisse_RISO_mw_riso_peak_prim_pe' => self::f32($wert), 'Ergebnisse_RISO_gw_uiso_prim_pe' => self::f32(500.0),
                'Ergebnisse_RISO_gw_riso_prim_pe' => self::f32($art === 'RISO-IN' ? 0.14 : ($sk2 ? 2.0 : 1.0))];
        }
        if ($art === 'RISO-OUT') {
            $sp += ['Ergebnisse_RISO_gw_uiso_sek_pe' => self::f32(500.0), 'Ergebnisse_RISO_gw_riso_sek_pe' => self::f32(0.25)];
        }
        return ['art' => $art, 'ok' => true, 'werte' => [
            [$ident, $text, true, $wert],
            ['Prüfspannung', $u . ' VDC', true, (float) $u],
            ['Prüfstrom', sprintf('%.3f mA', $strom), true, $strom],
        ]];
    }

    /** @param array<string,mixed> $sp */
    private function funkt(array &$sp, bool $sk2, bool $fehler, ?float $ableit = null): array
    {
        $grenze = $sk2 ? 0.5 : 3.5;
        $ableit = $fehler ? 0.559 : ($ableit ?? $this->wert(0.03, $grenze * 0.5, 3));
        $ableitText = $ableit < 0.03 ? '<0.030 mA' : sprintf('%.3f mA', $ableit);
        $u = $this->wert(229.0, 238.5, 1);
        $i = $this->wert(0.001, 0.3, 3);
        $p = $this->wert(0.1, 60.0, 1);
        $sp += ['Ergebnisse_FM_mw_u_l1' => self::f32($u), 'Ergebnisse_FM_mw_i_l1' => self::f32($i), 'Ergebnisse_FM_mw_ifehler' => self::f32($ableit),
            'Ergebnisse_FM_mw_p' => self::f32($p), 'Ergebnisse_FM_mw_s' => self::f32($p * 1.4), 'Ergebnisse_FM_gw_ifehler' => self::f32($grenze)];
        return ['art' => 'Funkt.', 'ok' => !$fehler, 'werte' => [
            ['Beurteilung', 'OK', true, null],
            [$sk2 ? 'IBer.' : 'IPE', $ableitText, !$fehler, $ableit],
            ['Eingangsspg.', sprintf('%.1f V', $u), true, $u],
            ['Eingangsstrom', sprintf('%.3f A', $i), true, $i],
            ['Wirkleistung', sprintf('%.1f W', $p), true, $p],
            ['Scheinleistung', sprintf('%.1f VA', $p * 1.4), true, $p * 1.4],
        ]];
    }

    /** @param array<string,mixed> $sp */
    private function kabel(array &$sp, float $laenge, bool $fehler): array
    {
        $grenze = round(0.1 + $laenge * 0.05, 3);
        $l = $this->wert(0.01, $grenze * 0.3, 3);
        $n = $this->wert(0.01, $grenze * 0.3, 3);
        $pe = $this->wert(0.01, $grenze * 0.3, 3);
        $strom = $fehler ? 0.0 : $this->wert(0.588, 0.604, 3);
        $sp += ['Ergebnisse_KM_mw_ileitung' => self::f32($strom), 'Ergebnisse_KM_mw_rleitung' => self::f32($fehler ? 30.0 : $l + $n + $pe),
            'Ergebnisse_KM_leit_laenge' => self::f32($laenge), 'Ergebnisse_KM_gw_rleitung' => self::f32($grenze),
            'Ergebnisse_KM_mw_r_l' => self::f32($l), 'Ergebnisse_KM_mw_r_n' => self::f32($fehler ? 30.0 : $n), 'Ergebnisse_KM_mw_r_pe' => self::f32($pe),
            'Ergebnisse_KM_status' => $fehler ? 26 : 31];
        return ['art' => 'Kabel', 'ok' => !$fehler, 'werte' => [
            ['Leit.-Widerst.', $fehler ? '>15.000 Ω' : sprintf('%.3f Ω', $l + $n + $pe), !$fehler, $fehler ? 30.0 : $l + $n + $pe],
            ['L.-Widerst. L', sprintf('%.3f Ω', $l), true, $l],
            ['L.-Widerst. N', $fehler ? '>15.000 Ω' : sprintf('%.3f Ω', $n), !$fehler, $fehler ? 30.0 : $n],
            ['L.-Widerst. PE', sprintf('%.3f Ω', $pe), true, $pe],
            ['Messstrom', sprintf('%.3f A', $strom), true, $strom],
        ]];
    }

    /** IPE- oder IBer.-Schritt (Ersatz- bzw. Direktverfahren). @param array<string,mixed> $sp */
    private function strom(array &$sp, string $art, float $strom): array
    {
        $u = $this->wert(229.0, 233.0, 1);
        if ($art === 'IPE') {
            $sp += ['Ergebnisse_IABL_mw_iabl_rms' => self::f32($strom), 'Ergebnisse_IABL_mw_uvers' => self::f32($u),
                'Ergebnisse_IABL_gw_iabl_rms' => self::f32(3.5), 'Ergebnisse_IABL_gw_iabl_ac' => self::f32(3.5), 'Ergebnisse_IABL_gw_iabl_dc' => self::f32(3.5)];
        } else {
            $sp += ['Ergebnisse_IB_mw_ib_rms' => self::f32($strom), 'Ergebnisse_IB_mw_uvers' => self::f32($u),
                'Ergebnisse_IB_gw_ib_rms' => self::f32(0.5), 'Ergebnisse_IB_gw_ib_ac' => self::f32(0.5), 'Ergebnisse_IB_gw_ib_dc' => self::f32(0.5)];
        }
        return ['art' => $art, 'ok' => true, 'werte' => [
            [$art, $strom < 0.03 && $art === 'IPE' ? '<0.030 mA' : sprintf('%.3f mA', $strom), true, $strom],
            ['Eingangsspg.', sprintf('%.1f V', $u), true, $u],
        ]];
    }

    /** @param array<string,mixed> $sp */
    private function ua(array &$sp): array
    {
        $u = $this->wert(4.0, 19.5, 1);
        $sp += ['Ergebnisse_Pelv_mw_upelv' => self::f32($u), 'Ergebnisse_Pelv_gw_upelv' => self::f32(25.0), 'Ergebnisse_Pelv_gw_schw_upeak' => self::f32(500.0)];
        return ['art' => 'Ua', 'ok' => true, 'werte' => [['U-Ausg.', sprintf('%.1f V', $u), true, $u]]];
    }

    /** @param array<string,mixed> $sp */
    private function prcd(array &$sp): array
    {
        $i0 = $this->wert(26.0, 27.2, 1);
        $i180 = $this->wert(26.0, 27.2, 1);
        $ub = $this->wert(43.0, 45.5, 1);
        $t1 = (float) $this->zahl(20, 38);
        $t5 = (float) $this->zahl(19, 23);
        $sp += ['Ergebnisse_RCD_mw_iausl_1I_pos' => self::f32($i0), 'Ergebnisse_RCD_mw_iausl_1I_neg' => self::f32($i180),
            'Ergebnisse_RCD_mw_tausl_1I_pos' => self::f32($t1), 'Ergebnisse_RCD_mw_tausl_5I_pos' => self::f32($t5),
            'Ergebnisse_RCD_mw_tausl_1_2I_pos' => self::f32(300.0), 'Ergebnisse_RCD_mw_uber' => self::f32($ub),
            'Ergebnisse_RCD_gw_iausl_1I' => self::f32(30.0), 'Ergebnisse_RCD_gw_tausl_1I' => self::f32(300.0),
            'Ergebnisse_RCD_gw_tausl_5I' => self::f32(40.0), 'Ergebnisse_RCD_gw_tausl_1_2I' => self::f32(300.0),
            'Ergebnisse_RCD_gw_uber' => self::f32(50.0)];
        return ['art' => 'PRCD', 'ok' => true, 'werte' => [
            ['Ausl.-Str. 0°', sprintf('%.1f mA', $i0), true, $i0],
            ['Ausl.-Str. 180°', sprintf('%.1f mA', $i180), true, $i180],
            ['Berührungsspg.', sprintf('%.1f V', $ub), true, $ub],
            ['Ausl.-Zeit Ix1/2', '300.0 ms', true, 300.0],
            ['Ausl.-Zeit Ix1', sprintf('%.1f ms', $t1), true, $t1],
            ['Ausl.-Zeit Ix5', sprintf('%.1f ms', $t5), true, $t5],
            ['Auslösetaste.', 'OK', true, null],
            ['L-getrennt', 'OK', true, null],
            ['N-getrennt', 'OK', true, null],
            ['PE-getrennt', 'OK', true, null],
            ['Prüfsonde', 'OK', true, null],
            ['Durchführung', '1 ', true, 1.0],
        ]];
    }

    // ------------------------------------------------------------ Zahlen

    /** Wie das Gerät speichert: auf float32 gerundet (0.3 → 0.30000001192093). */
    public static function f32(float $wert): float
    {
        return (float) unpack('g', pack('g', $wert))[1];
    }

    /** Ganze Zahl in [$min, $max] aus dem Startwert (xorshift32). */
    private function zahl(int $min, int $max): int
    {
        $x = $this->zustand;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->zustand = $x & 0xFFFFFFFF;
        return $min + $this->zustand % ($max - $min + 1);
    }

    /** Messwert in [$min, $max], auf $stellen Nachkommastellen (so zeigt ihn das Gerät). */
    private function wert(float $min, float $max, int $stellen): float
    {
        $schritte = (int) round(($max - $min) * 10 ** $stellen);
        return round($min + $this->zahl(0, max(0, $schritte)) / 10 ** $stellen, $stellen);
    }
}
