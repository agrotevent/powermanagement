<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

/**
 * Eine Datenbank des Prüfgeräts BENNING ST 755+ sicher lesen und in die
 * **Normalform** übersetzen – ein Paket nur aus Text, Wahrheitswerten,
 * Listen und Ganzzahlen für Zählungen (siehe {@see lesen()}).
 *
 * Die Datei kommt von außen (Upload, SD-Karte des Geräts) und wird so
 * behandelt:
 *
 * - höchstens {@see MAX_BYTES}; die ersten 16 Bytes `SQLite format 3\0`;
 *   Kopf-Bytes 18/19 = 1 (ein WAL-Journal liegt nie bei, die Datei wäre
 *   unvollständig – abgelehnt);
 * - gelesen wird eine eigene Kopie unter `storage/cache/gp-<32 hex>.db`
 *   (0600, ohne Journal daneben), nur lesend geöffnet
 *   (`SQLITE_OPEN_READONLY`, `query_only`, `trusted_schema=OFF`), erst nach
 *   `quick_check`; die Kopie verschwindet im `finally`. Nie über
 *   `Database::pdo()` – das ist die Datenbank der App;
 * - im Schema nur Tabellen, Indizes und Trigger (keine Ansicht, keine
 *   virtuelle Tabelle); die Pflichttabellen mit ihren Pflichtspalten,
 *   abgefragt nur mit ausdrücklichen Spaltenlisten – fehlt eine freiwillige
 *   Spalte, steht dort leer;
 * - gezählt, bevor gelesen wird ({@see GRENZEN}): darüber abgelehnt
 *   („nie ganz in Listen zerlegen – zählen oder deckeln“); Texte gesäubert
 *   und auf die Länge der Spalte hier gekürzt; Zahlen nur als Dezimaltext
 *   mit Punkt ({@see Messarten::zahl()}); Zeiten des Geräts (Ortszeit) als
 *   UTC ({@see Messarten::zeitLesen()}).
 *
 * Bewertet wird nichts: Das Gesamtergebnis einer Prüfung leitet
 * {@see Messarten::ergebnisAbleiten()} aus dem ab, was das Gerät meldet.
 * Ob die Datei als Vorlage für den Export taugt, sagt
 * {@see BenningVorlage::schemaPruefen()} (Teil der Normalform).
 */
final class BenningDatei
{
    /** Größte Datei (eine Sicherung mit gut 1 200 Prüfungen hat 2 MB); 1 MiB unter der Grenze von nginx (16m). */
    public const MAX_BYTES = 15 * 1024 * 1024;

    public const FORMAT = 1;

    /** Höchstmengen je Datei – darüber wird abgelehnt, statt alles zu lesen. */
    public const GRENZEN = [
        'kunden' => 5000, 'geraete' => 50000, 'pruefungen' => 50000, 'schritte' => 500000, 'werte' => 3000000,
        'ablaeufe' => 10000, 'fragen' => 1000, 'hersteller' => 50000, 'schema' => 1000,
    ];

    /**
     * Prüfskripte setzen hier kleinere Grenzen (Schlüssel wie {@see GRENZEN}),
     * damit sich „zu viele Zeilen“ ohne riesige Dateien zeigen lässt.
     *
     * @var array<string,int>|null
     */
    public static ?array $grenzen = null;

    /** Pflichttabellen und ihre Pflichtspalten. */
    private const PFLICHT = [
        'Kunden'       => ['Kunden_Index', 'Kunden_Name'],
        'Geraete'      => ['Geraete_Index', 'Geraete_ID', 'Geraete_Kunden_Index'],
        'Ergebnisse'   => ['Ergebnisse_Index', 'Ergebnisse_Geraete_Index', 'Ergebnisse_Datum'],
        'Sequences'    => ['id', 'testResultId', 'success', 'viewTestOk'],
        'SqProcedures' => ['id', 'sequenceId', 'procName', 'success'],
        'SqProcValues' => ['id', 'sqProcId', 'ident', 'valueStr', 'isValid'],
        'Pruefung'     => ['Pruefung_ID', 'Pruefung_Name'],
    ];

    /** Freiwillige Tabellen: fehlen sie, fehlt nur, was in ihnen stünde. */
    private const FREIWILLIG = [
        'TestResult2Admin2Answers' => ['testResultId', 'questionId', 'answerId'],
        'ViewQuestions'            => ['id', 'Question'],
        'Geraete2AdminQuestions'   => ['GeraeteId', 'QuestionId'],
        'Manufacturers'            => ['id', 'name'],
        'DbInfos'                  => ['ident', 'value'],
    ];

    private const KUNDEN_SPALTEN = ['Kunden_Index', 'Kunden_Name', 'Kunden_Strasse', 'Kunden_PLZ', 'Kunden_Wohnort', 'Kunden_Rufnummer',
        'Kunden_Bemerkung', 'Kunden_Fax', 'Kunden_Email', 'Kunden_Ansprechpartner', 'Ansprechpartner_Abteilung',
        'Ansprechpartner_Rufnummer', 'Ansprechpartner_Email', 'Kunden_Nummer'];

    /** Spalte im Gerät → Feld der Normalform und Länge der Spalte hier (kunden). */
    private const KUNDEN_FELDER = [
        'Kunden_Name' => ['name', 255], 'Kunden_Strasse' => ['strasse', 120], 'Kunden_PLZ' => ['plz', 16],
        'Kunden_Wohnort' => ['ort', 120], 'Kunden_Rufnummer' => ['telefon', 64], 'Kunden_Fax' => ['fax', 64],
        'Kunden_Email' => ['email', 190], 'Kunden_Ansprechpartner' => ['ansprechpartner', 120],
        'Ansprechpartner_Abteilung' => ['ansprechpartner_abteilung', 120], 'Ansprechpartner_Rufnummer' => ['ansprechpartner_telefon', 64],
        'Ansprechpartner_Email' => ['ansprechpartner_email', 190], 'Kunden_Nummer' => ['kundennummer', 64],
    ];

    private const GERAETE_SPALTEN = ['Geraete_Index', 'Geraete_ID', 'Geraete_Kunden_Index', 'Geraete_Bezeichnung', 'Geraete_Bemerkung',
        'Geraete_Abteilung', 'Gebaeude', 'Geraete_Hersteller', 'manufacturerId', 'Geraete_Typ', 'Geraete_Modell', 'Geraete_SN', 'Geraete_SK',
        'Geraete_Pruefablauf', 'Geraete_Pruefablauf_Name', 'Geraete_Pruefablauf_Index', 'Geraete_Pruefintervall', 'Geraete_Leitungslaenge',
        'Geraete_Leitungsquerschnitt', 'Geraete_Anz_Leiter', 'Geraete_Nennleistung', 'AusserBetrieb', 'Geraete_Pruefdatum',
        'Geraete_Datum_Naechste_Pruefung', 'Geraete_VDE', 'Geraete_Sichtpruefung', 'Geraete_Pruefoptionen', 'Geraete_Zusatz_Pruefungen',
        'ThresholdListID', 'Geraete_Private_Anschaffung', 'Geraete_U_Ausgang', 'Geraete_Pruefablauf_2', 'Geraete_EV_Pruefablauf',
        'Geraete_EV_Fehlertest'];

    /** Bitmasken (int64) – als Text gelesen, damit nichts gerundet wird. */
    private const ALS_TEXT = ['Geraete_Pruefablauf', 'Geraete_Pruefablauf_2', 'Geraete_EV_Pruefablauf', 'Geraete_EV_Fehlertest',
        'Pruefung_Ablauf', 'Pruefung_Ablauf_2'];

    /** Felder des Geräts, die hier niemand pflegt (gp_prueflinge.technik) → Spalte. */
    public const TECHNIK = [
        'vde' => 'Geraete_VDE', 'sichtpruefung' => 'Geraete_Sichtpruefung', 'pruefoptionen' => 'Geraete_Pruefoptionen',
        'zusatz_pruefungen' => 'Geraete_Zusatz_Pruefungen', 'threshold_list_id' => 'ThresholdListID',
        'private_anschaffung' => 'Geraete_Private_Anschaffung', 'u_ausgang' => 'Geraete_U_Ausgang',
        'pruefablauf_2' => 'Geraete_Pruefablauf_2', 'ev_pruefablauf' => 'Geraete_EV_Pruefablauf', 'ev_fehlertest' => 'Geraete_EV_Fehlertest',
    ];

    private const ERGEBNIS_SPALTEN = ['Ergebnisse_Index', 'Ergebnisse_Geraete_Index', 'Ergebnisse_Datum', 'Ergebnisse_Pruefablauf_Name',
        'Ergebnisse_Pruefablauf_Index', 'Ergebnisse_Auftragsnummer', 'Ergebnisse_Messgeraet', 'Ergebnisse_SN_Messgerae',
        'Ergebnisse_Softversion_Messgeraet', 'Ergebnisse_Softversion_GUI', 'Ergebnisse_Pruefername', 'Ergebnisse_Prueffirma',
        'Ergebnisse_Ort', 'Ergebnisse_Bemerkung'];

    private const ABLAUF_SPALTEN = ['Pruefung_ID', 'Pruefung_Nr', 'Pruefung_Name', 'Pruefung_Ablauf', 'Pruefung_Ablauf_2', 'Pruefung_SK',
        'Grenzwerte_Index', 'Fest', 'Individuell', 'Ungueltig'];

    /**
     * Die Datei $pfad lesen. $dateiname ist nur für die Anzeige (Name beim
     * Hochladen). Liefert die Normalform:
     *
     * ```
     * ok, message, befunde (nur bei ok=false), format (1),
     * quelle     {sha256, dateiname, groesse (int), db_version, messgeraet, messgeraet_sn, firmware}
     * kunden     [{ref, name, strasse, plz, ort, telefon, fax, email, ansprechpartner, ansprechpartner_abteilung,
     *              ansprechpartner_telefon, ansprechpartner_email, bemerkung, kundennummer, geraete (int), pruefungen (int)}]
     * geraete    [{ref, kunde_ref, kennung, bezeichnung, hersteller, typ, modell, seriennummer, abteilung, gebaeude,
     *              bemerkung, schutzklasse, ablauf_geraet_id, ablauf_name, pruefablauf, intervall_monate, leitungslaenge,
     *              querschnitt, leiter, nennleistung, ausser_betrieb (bool), letzte_pruefung, naechste_pruefung,
     *              fragen [id], technik {vde, sichtpruefung, …}}]
     * pruefungen [Normalform wie in Pruefungen (schluessel, ref, geraet_ref, geprueft_am, …, schritte)]
     * pruefablaeufe [{geraet_id, nr, name, ablauf, ablauf_2, schutzklasse, grenzwerte_index, fest, individuell, ungueltig}]
     * fragen     [{id, text, stufe, geloescht (bool)}]
     * vorlage    {moeglich (bool), grund}
     * zahlen     {kunden, geraete, pruefungen} (int), hinweise [Text]
     * ```
     *
     * @return array<string,mixed>
     */
    public static function lesen(string $pfad, string $dateiname): array
    {
        $dateiname = Messarten::text(basename(str_replace('\\', '/', $dateiname)), 120);
        clearstatcache(true, $pfad);
        if (!is_file($pfad) || !is_readable($pfad)) {
            return self::abgelehnt('Die Datei fehlt.');
        }
        $groesse = (int) filesize($pfad);
        if ($groesse === 0) {
            return self::abgelehnt('Die Datei ist leer.');
        }
        if ($groesse > self::MAX_BYTES) {
            return self::abgelehnt('Die Datei ist größer als ' . self::mb(self::MAX_BYTES) . ' – so groß wird keine Datenbank des Prüfgeräts.');
        }
        $kopf = (string) file_get_contents($pfad, false, null, 0, 100);
        if (strlen($kopf) < 100 || !str_starts_with($kopf, "SQLite format 3\0")) {
            return self::abgelehnt('Das ist keine Datenbank des Prüfgeräts (keine SQLite-Datei). Gebraucht wird die .db-Datei '
                . 'des BENNING ST 755+ oder eine seiner Sicherungen (…_Backup.001).');
        }
        if (ord($kopf[18]) === 2 || ord($kopf[19]) === 2) {
            return self::abgelehnt('Diese Datenbank steht im WAL-Modus – ohne ihre -wal-Datei fehlen ihr Daten. Bitte die Datei so '
                . 'hochladen, wie das Prüfgerät sie schreibt (oder eine Sicherung des Geräts).');
        }
        if (ord($kopf[18]) !== 1 || ord($kopf[19]) !== 1) {
            return self::abgelehnt('Der Kopf dieser SQLite-Datei ist ungültig.');
        }
        $sha = (string) hash_file('sha256', $pfad);
        $kopie = null;
        $pdo = null;
        try {
            $kopie = self::kopieren($pfad);
            $pdo = self::oeffnen($kopie);
            $befunde = [];
            $master = [];
            $tabellen = self::schema($pdo, $befunde, $master);
            if ($befunde !== []) {
                return self::abgelehnt('Der Aufbau passt nicht zu einer Datenbank des BENNING ST 755+.', $befunde);
            }
            return self::auslesen($pdo, $tabellen, $master, [
                'sha256' => $sha, 'dateiname' => $dateiname, 'groesse' => $groesse,
            ]);
        } catch (\DomainException $e) {
            return self::abgelehnt($e->getMessage());
        } catch (\PDOException) {
            return self::abgelehnt('Die Datei ließ sich nicht als Datenbank lesen – sie ist beschädigt oder keine SQLite-Datenbank.');
        } finally {
            $pdo = null;
            if ($kopie !== null) {
                @unlink($kopie);
                @unlink($kopie . '-journal');
            }
        }
    }

    /**
     * Eine fremde SQLite-Datei nur lesend öffnen (eigene Verbindung, nie die
     * der App), nach `quick_check`. Für Vorlage und Prüfskripte ebenso.
     */
    public static function oeffnen(string $pfad): \PDO
    {
        $pdo = new \PDO('sqlite:' . $pfad, null, null, [
            \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
            \PDO::ATTR_ERRMODE           => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA query_only = 1');
        $pdo->exec('PRAGMA trusted_schema = OFF');
        $pdo->exec('PRAGMA cell_size_check = ON');
        $pruefung = $pdo->query('PRAGMA quick_check')->fetchAll(\PDO::FETCH_COLUMN);
        if ($pruefung !== ['ok']) {
            throw new \DomainException('Die Datenbank ist beschädigt (quick_check meldet Fehler) – bitte eine andere Sicherung des Geräts nehmen.');
        }
        return $pdo;
    }

    /**
     * Eine Kopie unter storage/cache (0600, Name mit Zufall) – gelesen wird
     * nie die Datei selbst: Neben ihr könnte ein Journal liegen, das SQLite
     * sonst einspielen wollte.
     */
    public static function kopieren(string $pfad, string $vorsilbe = 'gp-'): string
    {
        $ordner = APP_ROOT . '/storage/cache';
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            throw new \DomainException('storage/cache ist nicht beschreibbar.');
        }
        $ziel = $ordner . '/' . $vorsilbe . bin2hex(random_bytes(16)) . '.db';
        $aus = @fopen($ziel, 'xb');
        if ($aus === false) {
            throw new \DomainException('storage/cache ist nicht beschreibbar.');
        }
        @chmod($ziel, 0600);
        $ein = @fopen($pfad, 'rb');
        $ok = $ein !== false && stream_copy_to_stream($ein, $aus, self::MAX_BYTES + 1) !== false;
        if ($ein !== false) {
            fclose($ein);
        }
        fclose($aus);
        if (!$ok || (int) filesize($ziel) > self::MAX_BYTES) {
            @unlink($ziel);
            throw new \DomainException('Die Datei ließ sich nicht kopieren.');
        }
        return $ziel;
    }

    /** Höchstmenge für $was (Prüfskripte können sie über {@see $grenzen} senken). */
    public static function grenze(string $was): int
    {
        return (int) (self::$grenzen[$was] ?? self::GRENZEN[$was]);
    }

    /**
     * Schema prüfen: nur table|index|trigger, Pflichttabellen als gewöhnliche
     * Tabellen mit ihren Pflichtspalten. Gibt je vorhandener Tabelle (Name wie
     * in den Konstanten) die Menge ihrer Spalten zurück (klein geschrieben →
     * Name), freiwillige nur, wenn sie taugen.
     *
     * @param list<string> $befunde
     * @param list<array<string,mixed>> $master bekommt die Zeilen von sqlite_master
     * @return array<string,array<string,string>>
     */
    private static function schema(\PDO $pdo, array &$befunde, array &$master): array
    {
        $master = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master LIMIT ' . (self::grenze('schema') + 1))->fetchAll();
        if (count($master) > self::grenze('schema')) {
            $befunde[] = 'Mehr als ' . self::grenze('schema') . ' Einträge im Schema.';
            return [];
        }
        $objekte = [];
        foreach ($master as $m) {
            $typ = (string) $m['type'];
            $name = (string) $m['name'];
            if (!in_array($typ, ['table', 'index', 'trigger'], true)) {
                $befunde[] = '„' . Messarten::text($name, 60) . '“ ist ' . ($typ === 'view' ? 'eine Ansicht (view)' : 'vom Typ ' . Messarten::text($typ, 20))
                    . ' – erwartet werden nur Tabellen, Indizes und Trigger.';
                continue;
            }
            if ($typ === 'table') {
                $objekte[strtolower($name)] = $m;
            }
        }
        $tabellen = [];
        foreach ([...array_map(static fn ($s) => [$s, true], array_keys(self::PFLICHT)), ...array_map(static fn ($s) => [$s, false], array_keys(self::FREIWILLIG))] as [$tabelle, $pflicht]) {
            $m = $objekte[strtolower($tabelle)] ?? null;
            $virtuell = $m !== null && preg_match('/^\s*CREATE\s+VIRTUAL\s/i', (string) $m['sql']) === 1;
            if ($m === null || $virtuell) {
                if ($pflicht) {
                    $befunde[] = $m === null ? 'Die Tabelle ' . $tabelle . ' fehlt.' : 'Die Tabelle ' . $tabelle . ' ist eine virtuelle Tabelle.';
                }
                continue;
            }
            $spalten = [];
            foreach ($pdo->query('PRAGMA table_info("' . $tabelle . '")')->fetchAll() as $s) {
                $spalten[strtolower((string) $s['name'])] = (string) $s['name'];
            }
            $fehlen = array_values(array_filter($pflicht ? self::PFLICHT[$tabelle] : self::FREIWILLIG[$tabelle],
                static fn (string $s): bool => !isset($spalten[strtolower($s)])));
            if ($fehlen !== []) {
                if ($pflicht) {
                    $befunde[] = 'In der Tabelle ' . $tabelle . ' fehlt ' . (count($fehlen) === 1 ? 'die Spalte ' : 'die Spalten ') . implode(', ', $fehlen) . '.';
                }
                continue;
            }
            $tabellen[$tabelle] = $spalten;
        }
        return $tabellen;
    }

    /**
     * @param array<string,array<string,string>> $tabellen
     * @param list<array<string,mixed>> $master
     * @param array{sha256:string,dateiname:string,groesse:int} $quelle
     * @return array<string,mixed>
     */
    private static function auslesen(\PDO $pdo, array $tabellen, array $master, array $quelle): array
    {
        $zahl = static fn (string $t): int => (int) $pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
        $zuViel = [];
        foreach ([['Kunden', 'kunden', 'Kunden'], ['Geraete', 'geraete', 'Geräte'], ['Ergebnisse', 'pruefungen', 'Prüfungen'],
            ['Sequences', 'pruefungen', 'Prüfabläufe am Gerät (Sequences)'], ['SqProcedures', 'schritte', 'Prüfschritte'],
            ['SqProcValues', 'werte', 'Messwerte'], ['TestResult2Admin2Answers', 'werte', 'Antworten der Sichtprüfung'],
            ['Geraete2AdminQuestions', 'werte', 'Fragen je Gerät'], ['Pruefung', 'ablaeufe', 'Prüfabläufe'],
            ['ViewQuestions', 'fragen', 'Fragen der Sichtprüfung'], ['Manufacturers', 'hersteller', 'Hersteller']] as [$t, $grenze, $text]) {
            if (isset($tabellen[$t]) && ($n = $zahl($t)) > self::grenze($grenze)) {
                $zuViel[] = $text . ': ' . $n . ' (höchstens ' . self::grenze($grenze) . ')';
            }
        }
        if ($zuViel !== []) {
            throw new \DomainException('Zu viele Zeilen – ' . implode('; ', $zuViel) . '.');
        }
        $hinweise = [];
        $wahl = static fn (string $t, array $spalten): string => self::auswahl($tabellen[$t], $spalten);

        // ------------------------------------------------ Quelle
        $dbVersion = '';
        if (isset($tabellen['DbInfos'])) {
            $dbVersion = self::t($pdo->query('SELECT "value" AS "value" FROM DbInfos WHERE ident = \'DB-Version\' LIMIT 1')->fetchColumn(), 20);
        }
        $letzte = $pdo->query('SELECT ' . $wahl('Ergebnisse', ['Ergebnisse_Messgeraet', 'Ergebnisse_SN_Messgerae', 'Ergebnisse_Softversion_Messgeraet',
            'Ergebnisse_Softversion_GUI']) . ' FROM Ergebnisse ORDER BY Ergebnisse_Index DESC LIMIT 1')->fetch() ?: [];
        $quelle += [
            'db_version'    => $dbVersion,
            'messgeraet'    => self::t($letzte['Ergebnisse_Messgeraet'] ?? '', 80),
            'messgeraet_sn' => self::t($letzte['Ergebnisse_SN_Messgerae'] ?? '', 80),
            'firmware'      => self::firmware($letzte),
        ];

        // ------------------------------------------------ Prüfabläufe und Fragen
        $ablaeufe = [];
        $ablaufSk = [];
        foreach ($pdo->query('SELECT ' . $wahl('Pruefung', self::ABLAUF_SPALTEN) . ' FROM Pruefung ORDER BY Pruefung_ID') as $z) {
            $id = self::ganz($z['Pruefung_ID']);
            if ($id === null) {
                continue;
            }
            $sk = self::ganz($z['Pruefung_SK']);
            $ablaufSk[(string) $id] = $sk !== null && $sk >= 1 && $sk <= 3 ? (string) $sk : '';
            $ablaeufe[] = [
                'geraet_id'        => (string) $id,
                'nr'               => self::ganz($z['Pruefung_Nr']) === null ? '' : (string) self::ganz($z['Pruefung_Nr']),
                'name'             => self::t($z['Pruefung_Name'], 120),
                'ablauf'           => self::maske($z['Pruefung_Ablauf']),
                'ablauf_2'         => self::maske($z['Pruefung_Ablauf_2']),
                'schutzklasse'     => $sk === null ? '' : (string) $sk,
                'grenzwerte_index' => self::ganz($z['Grenzwerte_Index']) === null ? '' : (string) self::ganz($z['Grenzwerte_Index']),
                'fest'             => self::wahr($z['Fest']),
                'individuell'      => self::wahr($z['Individuell']),
                'ungueltig'        => self::wahr($z['Ungueltig']),
            ];
        }
        $fragen = [];
        $fragenText = [];
        if (isset($tabellen['ViewQuestions'])) {
            foreach ($pdo->query('SELECT ' . $wahl('ViewQuestions', ['id', 'Question', 'ViewLevel', 'IsDeleted']) . ' FROM ViewQuestions ORDER BY id') as $z) {
                $id = self::ganz($z['id']);
                if ($id === null) {
                    continue;
                }
                $fragenText[$id] = self::t($z['Question'], 200);
                $fragen[] = ['id' => (string) $id, 'text' => $fragenText[$id], 'stufe' => self::ganz($z['ViewLevel']) === null ? '' : (string) self::ganz($z['ViewLevel']),
                    'geloescht' => self::wahr($z['IsDeleted'])];
            }
        }

        // ------------------------------------------------ Kunden
        $kunden = [];
        foreach ($pdo->query('SELECT ' . $wahl('Kunden', self::KUNDEN_SPALTEN) . ' FROM Kunden ORDER BY Kunden_Index') as $z) {
            $ref = self::ganz($z['Kunden_Index']);
            if ($ref === null) {
                continue;
            }
            $k = ['ref' => (string) $ref];
            foreach (self::KUNDEN_FELDER as $spalte => [$feld, $laenge]) {
                $k[$feld] = self::t($z[$spalte], $laenge);
            }
            $k['bemerkung'] = self::absatz($z['Kunden_Bemerkung'], 500);
            $k['geraete'] = 0;
            $k['pruefungen'] = 0;
            $kunden[(string) $ref] = $k;
        }

        // ------------------------------------------------ Geräte
        $hersteller = [];
        if (isset($tabellen['Manufacturers'])) {
            foreach ($pdo->query('SELECT "id" AS "id", "name" AS "name" FROM Manufacturers') as $z) {
                if (($id = self::ganz($z['id'])) !== null) {
                    $hersteller[$id] = self::t($z['name'], 80);
                }
            }
        }
        $fragenJe = [];
        if (isset($tabellen['Geraete2AdminQuestions'])) {
            foreach ($pdo->query('SELECT "GeraeteId" AS g, "QuestionId" AS q FROM Geraete2AdminQuestions ORDER BY GeraeteId, QuestionId') as $z) {
                if (($g = self::ganz($z['g'])) !== null && ($q = self::ganz($z['q'])) !== null) {
                    $fragenJe[$g][] = (string) $q;
                }
            }
        }
        $geraete = [];
        $ohneKennung = 0;
        $ohneKunde = 0;
        $uebergangen = [];
        $unlesbar = [];
        foreach ($pdo->query('SELECT ' . $wahl('Geraete', self::GERAETE_SPALTEN) . ' FROM Geraete ORDER BY Geraete_Index') as $z) {
            $ref = self::ganz($z['Geraete_Index']);
            if ($ref === null) {
                continue;
            }
            $kennung = Prueflinge::kennung(Prueflinge::kennung(is_float($z['Geraete_ID']) ? Messarten::zahl($z['Geraete_ID']) : $z['Geraete_ID']));
            if ($kennung === '') {
                $ohneKennung++;
                $uebergangen[$ref] = true;
                continue;
            }
            $kunde = self::ganz($z['Geraete_Kunden_Index']);
            if ($kunde === null || !isset($kunden[(string) $kunde])) {
                $ohneKunde++;
                $uebergangen[$ref] = true;
                continue;
            }
            $ablaufId = self::ganz($z['Geraete_Pruefablauf_Index']);
            $sk = self::ganz($z['Geraete_SK']);
            $schutzklasse = $sk !== null && $sk >= 1 && $sk <= 3 ? (string) $sk : ($ablaufSk[(string) $ablaufId] ?? '');
            if ($schutzklasse === '') {
                $schutzklasse = '1';
                $unlesbar['Schutzklasse'] = ($unlesbar['Schutzklasse'] ?? 0) + 1;
            }
            $intervall = self::ganz($z['Geraete_Pruefintervall']);
            if ($intervall === null || $intervall < 0 || $intervall > 600) {
                if ($z['Geraete_Pruefintervall'] !== null) {
                    $unlesbar['Prüfintervall'] = ($unlesbar['Prüfintervall'] ?? 0) + 1;
                }
                $intervall = 12;
            }
            $zahlen = [];
            foreach (['leitungslaenge' => ['Geraete_Leitungslaenge', 16], 'querschnitt' => ['Geraete_Leitungsquerschnitt', 16],
                'leiter' => ['Geraete_Anz_Leiter', 8], 'nennleistung' => ['Geraete_Nennleistung', 16]] as $feld => [$spalte, $laenge]) {
                $text = self::zahlText($z[$spalte], $laenge);
                if ($text === null) {
                    $unlesbar[$feld] = ($unlesbar[$feld] ?? 0) + 1;
                    $text = '';
                }
                $zahlen[$feld] = $text;
            }
            $technik = [];
            foreach (self::TECHNIK as $feld => $spalte) {
                $wert = $z[$spalte];
                $technik[$feld] = match (true) {
                    $wert === null                  => '',
                    $feld === 'u_ausgang'           => self::zahlText($wert, 16) ?? '',
                    in_array($spalte, self::ALS_TEXT, true) => self::maske($wert),
                    default                         => self::ganz($wert) === null ? '' : (string) self::ganz($wert),
                };
            }
            $herstellerText = self::t($z['Geraete_Hersteller'], 80);
            if ($herstellerText === '' && ($hid = self::ganz($z['manufacturerId'])) !== null) {
                $herstellerText = $hersteller[$hid] ?? '';
            }
            $geraete[(string) $ref] = [
                'ref'               => (string) $ref,
                'kunde_ref'         => (string) $kunde,
                'kennung'           => $kennung,
                'bezeichnung'       => self::t($z['Geraete_Bezeichnung'], Prueflinge::TEXTE['bezeichnung']),
                'hersteller'        => $herstellerText,
                'typ'               => self::t($z['Geraete_Typ'], Prueflinge::TEXTE['typ']),
                'modell'            => self::t($z['Geraete_Modell'], Prueflinge::TEXTE['modell']),
                'seriennummer'      => self::t($z['Geraete_SN'], Prueflinge::TEXTE['seriennummer']),
                'abteilung'         => self::t($z['Geraete_Abteilung'], Prueflinge::TEXTE['abteilung']),
                'gebaeude'          => self::t($z['Gebaeude'], Prueflinge::TEXTE['gebaeude']),
                'bemerkung'         => self::absatz($z['Geraete_Bemerkung'], Prueflinge::TEXTE['bemerkung']),
                'schutzklasse'      => $schutzklasse,
                'ablauf_geraet_id'  => $ablaufId === null ? '' : (string) $ablaufId,
                'ablauf_name'       => self::t($z['Geraete_Pruefablauf_Name'], 120),
                'pruefablauf'       => self::maske($z['Geraete_Pruefablauf']),
                'intervall_monate'  => (string) $intervall,
                'leitungslaenge'    => $zahlen['leitungslaenge'],
                'querschnitt'       => $zahlen['querschnitt'],
                'leiter'            => $zahlen['leiter'],
                'nennleistung'      => $zahlen['nennleistung'],
                'ausser_betrieb'    => self::wahr($z['AusserBetrieb']),
                'letzte_pruefung'   => self::ortsdatum($z['Geraete_Pruefdatum']),
                'naechste_pruefung' => self::ortsdatum($z['Geraete_Datum_Naechste_Pruefung']),
                'fragen'            => $fragenJe[$ref] ?? [],
                'technik'           => $technik,
            ];
        }
        if ($ohneKennung > 0) {
            $hinweise[] = $ohneKennung . ($ohneKennung === 1 ? ' Gerät ohne Prüflings-ID (Geraete_ID) übergangen.' : ' Geräte ohne Prüflings-ID (Geraete_ID) übergangen.');
        }
        if ($ohneKunde > 0) {
            $hinweise[] = $ohneKunde . ($ohneKunde === 1 ? ' Gerät ohne Kunden im Prüfgerät übergangen.' : ' Geräte ohne Kunden im Prüfgerät übergangen.');
        }
        foreach ($unlesbar as $feld => $n) {
            $hinweise[] = $feld . ' bei ' . $n . ($n === 1 ? ' Gerät' : ' Geräten') . ' nicht lesbar – ' . ($feld === 'Schutzklasse' ? 'als Schutzklasse I übernommen'
                : ($feld === 'Prüfintervall' ? 'als 12 Monate übernommen' : 'leer gelassen')) . '.';
        }

        // ------------------------------------------------ Prüfungen
        $pruefungen = self::pruefungen($pdo, $tabellen, $geraete, $uebergangen, $fragenText, $hinweise);
        foreach ($geraete as $g) {
            $kunden[$g['kunde_ref']]['geraete']++;
        }
        foreach ($pruefungen as $p) {
            $kunden[$geraete[$p['geraet_ref']]['kunde_ref']]['pruefungen']++;
        }
        $vorlage = BenningVorlage::schemaPruefen($master);
        return [
            'ok'            => true,
            'message'       => '',
            'format'        => self::FORMAT,
            'quelle'        => $quelle,
            'kunden'        => array_values($kunden),
            'geraete'       => array_values($geraete),
            'pruefungen'    => $pruefungen,
            'pruefablaeufe' => $ablaeufe,
            'fragen'        => $fragen,
            'vorlage'       => $vorlage,
            'zahlen'        => ['kunden' => count($kunden), 'geraete' => count($geraete), 'pruefungen' => count($pruefungen)],
            'hinweise'      => $hinweise,
        ];
    }

    /**
     * Die Prüfungen: je Ergebniszeile die lesbare Form des Geräts (Sequences →
     * SqProcedures → SqProcValues) und die Antworten der Sichtprüfung.
     *
     * @param array<string,array<string,string>> $tabellen
     * @param array<string,array<string,mixed>> $geraete
     * @param array<int,true> $uebergangen Geräte, die schon übergangen sind (ihre Prüfungen ebenso)
     * @param array<int,string> $fragenText
     * @param list<string> $hinweise
     * @return list<array<string,mixed>>
     */
    private static function pruefungen(\PDO $pdo, array $tabellen, array $geraete, array $uebergangen, array $fragenText, array &$hinweise): array
    {
        $antworten = [];
        $unbekannteAntwort = 0;
        if (isset($tabellen['TestResult2Admin2Answers'])) {
            foreach ($pdo->query('SELECT "testResultId" AS e, "questionId" AS q, "answerId" AS a FROM TestResult2Admin2Answers ORDER BY testResultId, questionId') as $z) {
                $e = self::ganz($z['e']);
                $q = self::ganz($z['q']);
                if ($e === null || $q === null) {
                    continue;
                }
                $a = self::ganz($z['a']);
                if ($a !== 1 && $a !== 2) {
                    $unbekannteAntwort++;
                }
                // 1 = in Ordnung, 2 = nicht in Ordnung; Unbekanntes zählt nicht als in Ordnung.
                $antworten[$e][] = ['frage' => (string) $q, 'text' => $fragenText[$q] ?? '', 'antwort' => $a === 1 ? 'ok' : 'nicht_ok'];
            }
        }
        $sequenzen = [];
        foreach ($pdo->query('SELECT "id" AS id, "testResultId" AS e, "success" AS ok, "viewTestOk" AS sicht FROM Sequences ORDER BY id') as $z) {
            if (($e = self::ganz($z['e'])) !== null && ($id = self::ganz($z['id'])) !== null) {
                $sequenzen[$e][] = ['id' => $id, 'ok' => self::wahr($z['ok']), 'sicht' => self::wahr($z['sicht'])];
            }
        }
        $schritte = [];
        foreach ($pdo->query('SELECT "id" AS id, "sequenceId" AS s, "procName" AS art, "success" AS ok FROM SqProcedures ORDER BY sequenceId, id') as $z) {
            if (($s = self::ganz($z['s'])) !== null && ($id = self::ganz($z['id'])) !== null) {
                $schritte[$s][] = ['id' => $id, 'art' => self::t($z['art'], 40), 'ok' => self::wahr($z['ok'])];
            }
        }
        $werte = [];
        foreach ($pdo->query('SELECT "sqProcId" AS p, "ident" AS name, "valueStr" AS wert, "isValid" AS gueltig FROM SqProcValues ORDER BY sqProcId, id') as $z) {
            if (($p = self::ganz($z['p'])) !== null) {
                $werte[$p][] = ['name' => self::t($z['name'], 80), 'wert' => self::t($z['wert'], 40), 'gueltig' => self::wahr($z['gueltig'])];
            }
        }

        $spalten = array_merge(self::ERGEBNIS_SPALTEN, Messarten::grenzwertSpalten());
        $aus = [];
        $ohneGeraet = 0;
        $mitGeraet = 0;
        $ohneZeit = 0;
        $ohneSequenz = 0;
        $mehrdeutig = 0;
        $abweichung = 0;
        foreach ($pdo->query('SELECT ' . self::auswahl($tabellen['Ergebnisse'], $spalten) . ' FROM Ergebnisse ORDER BY Ergebnisse_Index') as $z) {
            $ref = self::ganz($z['Ergebnisse_Index']);
            $geraetRef = self::ganz($z['Ergebnisse_Geraete_Index']);
            if ($ref === null) {
                continue;
            }
            $geraet = $geraetRef === null ? null : ($geraete[(string) $geraetRef] ?? null);
            if ($geraet === null) {
                $geraetRef !== null && isset($uebergangen[$geraetRef]) ? $mitGeraet++ : $ohneGeraet++;
                continue;
            }
            $amGeraet = is_string($z['Ergebnisse_Datum']) ? trim($z['Ergebnisse_Datum']) : '';
            $utc = Messarten::zeitLesen($amGeraet);
            if ($utc === null) {
                $ohneZeit++;
                continue;
            }
            $seqs = $sequenzen[$ref] ?? [];
            if (count($seqs) !== 1) {
                $seqs === [] ? $ohneSequenz++ : $mehrdeutig++;
                continue;
            }
            $seq = $seqs[0];
            $liste = [];
            foreach ($schritte[$seq['id']] ?? [] as $s) {
                $liste[] = [
                    'art'        => $s['art'],
                    'titel'      => Messarten::titel($s['art']),
                    'ok'         => $s['ok'],
                    'werte'      => $werte[$s['id']] ?? [],
                    'grenzwerte' => Messarten::grenzwerte($s['art'], $z),
                ];
            }
            $sicht = $antworten[$ref] ?? [];
            $abgeleitet = Messarten::ergebnisAbleiten($liste, $sicht, $seq['ok'], $seq['sicht']);
            if ($abgeleitet['abweichung']) {
                $abweichung++;
            }
            $p = [
                'ref'              => (string) $ref,
                'geraet_ref'       => (string) $geraetRef,
                'geprueft_am'      => $utc,
                'am_geraet'        => Messarten::text($amGeraet, 40),
                'pruefer'          => self::t($z['Ergebnisse_Pruefername'], 80),
                'prueffirma'       => self::t($z['Ergebnisse_Prueffirma'], 120),
                'ort'              => self::t($z['Ergebnisse_Ort'], 120),
                'auftrag'          => self::t($z['Ergebnisse_Auftragsnummer'], 80),
                'bemerkung'        => self::absatz($z['Ergebnisse_Bemerkung'], 500),
                'ablauf_name'      => self::t($z['Ergebnisse_Pruefablauf_Name'], 120),
                'ablauf_geraet_id' => self::ganz($z['Ergebnisse_Pruefablauf_Index']) === null ? '' : (string) self::ganz($z['Ergebnisse_Pruefablauf_Index']),
                'messgeraet'       => self::t($z['Ergebnisse_Messgeraet'], 80),
                'messgeraet_sn'    => self::t($z['Ergebnisse_SN_Messgerae'], 80),
                'firmware'         => self::firmware($z),
                'ergebnis'         => $abgeleitet['ergebnis'],
                'sicht'            => $abgeleitet['sicht'],
                'geraet_ergebnis'  => ['messung' => $seq['ok'], 'sicht' => $seq['sicht']],
                'sicht_antworten'  => $sicht,
                'schritte'         => $liste,
            ];
            $p = ['schluessel' => Pruefungen::schluessel($p, (string) $geraet['kennung'])] + $p;
            $aus[] = $p;
        }
        $mehrzahl = static fn (int $n, string $eins, string $viele): string => $n . ' ' . ($n === 1 ? $eins : $viele);
        if ($mitGeraet > 0) {
            $hinweise[] = 'Mit diesen Geräten übergangen: ' . $mehrzahl($mitGeraet, 'Prüfung', 'Prüfungen') . '.';
        }
        if ($ohneGeraet > 0) {
            $hinweise[] = $mehrzahl($ohneGeraet, 'Prüfung', 'Prüfungen') . ' ohne Gerät im Prüfgerät übergangen.';
        }
        if ($ohneZeit > 0) {
            $hinweise[] = $mehrzahl($ohneZeit, 'Prüfung', 'Prüfungen') . ' ohne lesbare Zeit übergangen.';
        }
        if ($ohneSequenz + $mehrdeutig > 0) {
            $hinweise[] = $mehrzahl($ohneSequenz + $mehrdeutig, 'Prüfung', 'Prüfungen') . ' ohne eindeutigen Ablauf im Gerät (Sequences) übergangen – '
                . 'ohne ihn ist nicht zu sehen, was gemessen wurde.';
        }
        if ($abweichung > 0) {
            $hinweise[] = $mehrzahl($abweichung, 'Prüfung meldet', 'Prüfungen melden') . ' das Gerät als bestanden, obwohl ein Schritt, ein Wert '
                . 'oder eine Antwort der Sichtprüfung nicht in Ordnung ist – sie zählen als nicht bestanden.';
        }
        if ($unbekannteAntwort > 0) {
            $hinweise[] = $mehrzahl($unbekannteAntwort, 'Antwort', 'Antworten') . ' der Sichtprüfung unbekannt – gezählt als „nicht in Ordnung“.';
        }
        return $aus;
    }

    // ------------------------------------------------------------ Hilfen

    /** @return array<string,mixed> */
    private static function abgelehnt(string $meldung, array $befunde = []): array
    {
        return ['ok' => false, 'message' => $meldung, 'befunde' => array_values(array_slice($befunde, 0, 20))];
    }

    /**
     * Ausdrückliche Spaltenliste: vorhandene Spalten mit Alias (Bitmasken als
     * Text), fehlende als NULL.
     *
     * @param array<string,string> $vorhanden klein geschrieben → Name
     * @param list<string> $spalten
     */
    private static function auswahl(array $vorhanden, array $spalten): string
    {
        $teile = [];
        foreach ($spalten as $s) {
            $q = '"' . $s . '"';
            $teile[] = isset($vorhanden[strtolower($s)])
                ? (in_array($s, self::ALS_TEXT, true) ? 'CAST(' . $q . ' AS TEXT) AS ' . $q : $q . ' AS ' . $q)
                : 'NULL AS ' . $q;
        }
        return implode(', ', $teile);
    }

    /**
     * Ein Text des Geräts: gesäubert und auf $laenge gekürzt – zweimal, damit
     * ein gekürzter Text mit Leerzeichen am Ende genauso aussieht wie nach dem
     * Speichern (Prueflinge säubert noch einmal).
     */
    private static function t(mixed $wert, int $laenge): string
    {
        if (is_float($wert)) {
            $wert = Messarten::zahl($wert);
        }
        return Messarten::text(Messarten::text($wert, $laenge), $laenge);
    }

    private static function absatz(mixed $wert, int $laenge): string
    {
        if (is_float($wert)) {
            $wert = Messarten::zahl($wert);
        }
        return Messarten::absatz(Messarten::absatz($wert, $laenge), $laenge);
    }

    private static function ganz(mixed $wert): ?int
    {
        if (is_int($wert)) {
            return $wert;
        }
        if (is_float($wert) && is_finite($wert) && floor($wert) === $wert && abs($wert) < 9.0e15) {
            return (int) $wert;
        }
        if (is_string($wert) && preg_match('/^\s*-?\d{1,18}\s*$/', $wert) === 1) {
            return (int) trim($wert);
        }
        return null;
    }

    /** BIT/BOOL des Geräts: 1, '1', true, 'true'. */
    private static function wahr(mixed $wert): bool
    {
        return $wert === 1 || $wert === true || $wert === 1.0
            || (is_string($wert) && in_array(strtolower(trim($wert)), ['1', 'true'], true));
    }

    /** Bitmaske als Dezimaltext (int64); leer oder unlesbar → '0'. */
    private static function maske(mixed $wert): string
    {
        if (is_int($wert)) {
            return (string) $wert;
        }
        if (is_string($wert) && preg_match('/^-?\d{1,20}$/', trim($wert)) === 1) {
            return ltrim(trim($wert), '+');
        }
        return '0';
    }

    /**
     * Eine Zahl des Geräts (REAL) als Dezimaltext mit Punkt; 0, leer und
     * negativ → '' (das Gerät schreibt 0 für „nicht angegeben“); null, wenn
     * sie sich nicht als Dezimaltext mit höchstens $laenge Zeichen schreiben lässt.
     */
    private static function zahlText(mixed $wert, int $laenge): ?string
    {
        if ($wert === null || $wert === '') {
            return '';
        }
        if (is_string($wert)) {
            $wert = is_numeric(trim($wert)) ? (float) trim($wert) : null;
            if ($wert === null) {
                return null;
            }
        }
        if (!is_int($wert) && !is_float($wert)) {
            return null;
        }
        $f = (float) $wert;
        if (!is_finite($f)) {
            return null;
        }
        if ($f <= 0.0) {
            return '';
        }
        $text = Messarten::dezimal(Messarten::zahl($f));
        return $text === null || strlen($text) > $laenge ? null : $text;
    }

    /** Zeit des Geräts → Ortsdatum JJJJ-MM-TT, „nie“ (1900) und Unlesbares → ''. */
    private static function ortsdatum(mixed $wert): string
    {
        $utc = is_string($wert) ? Messarten::zeitLesen($wert) : null;
        return $utc === null ? '' : (string) Messarten::ortsdatum($utc);
    }

    /** „1.17 / 1.08.01“ aus Software- und Oberflächenstand des Prüfgeräts. */
    private static function firmware(array $zeile): string
    {
        $soft = self::t($zeile['Ergebnisse_Softversion_Messgeraet'] ?? '', 20);
        $gui = self::t($zeile['Ergebnisse_Softversion_GUI'] ?? '', 20);
        return Messarten::text($soft . ($soft !== '' && $gui !== '' ? ' / ' : '') . $gui, 40);
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' MB';
    }
}
