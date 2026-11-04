<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Auth\Auth;
use App\Berichte\Einstellungen;
use App\Berichte\Pruefgeraete;
use App\Kunden\KundenService;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Response;
use App\Support\Zeit;

/**
 * Vorlage und Export: aus einer Datenbank des Prüfgeräts wird eine
 * **Vorlage** ohne Kunden-, Geräte- und Prüfdaten, und aus der Vorlage
 * schreibt PowerManagement eine Datenbank für das Prüfgerät mit den
 * Kunden und Prüflingen von hier ({@see exportieren()}).
 *
 * Vorlage ({@see bereinigen()}): Kopie der Datei, in der die Zeilen der
 * Tabellen mit Kunden-, Geräte- und Prüfdaten ({@see LEEREN}) gelöscht sind,
 * dazu ihre Einträge in `sqlite_sequence`; danach `VACUUM` (kein Rest im
 * Byte-Strom) und `integrity_check`. Übrig bleiben die Einstellungen des
 * Geräts – Prüfabläufe (`Pruefung`), Grenzwertsätze, Systemdaten, Fragen der
 * Sichtprüfung, Hersteller – samt Schema und Triggern. Vorher wird das
 * Schema geprüft ({@see schemaPruefen()}): nur Tabellen, Indizes und Trigger
 * mit einfachen Namen, nur bekannte Tabellen, Trigger nur nach dem Muster des
 * Geräts (Zeitstempel in `timestamp`). Die Bytes liegen base64 in
 * `gp_vorlagen` (die Sicherung vor einem Update enthält nur die Datenbank);
 * genau eine ist aktiv.
 *
 * Prüfabläufe ({@see ablaeufeAbgleichen()}) kommen aus `Pruefung` nach
 * `gp_pruefablaeufe` – angelegt werden sie nur am Gerät; nicht mehr
 * vorhandene werden ungültig, nie gelöscht (Prüflinge verweisen darauf).
 * Hier gepflegt werden nur Norm und Zubehör ({@see pruefablaufSpeichern()}).
 *
 * Export: Kopie der aktiven Vorlage unter storage/cache, Kunden, Hersteller,
 * Geräte und ihre Fragen hinein – sonst bleibt alles Byte für Byte (Pruefung,
 * Grenzwerte, Systemdaten, DbInfos). Vorlage und Export öffnen ihre Kopie
 * erst nach {@see BenningDatei::schemaSicher()} (nichts im Schema, was beim
 * Einfügen, VACUUM oder integrity_check rechnet). Ein Prüfling kommt nur mit
 * einem Prüfablauf, der zur Vorlage passt, und mit Leitungsangaben und
 * Prüfintervall in die Datei ({@see schreiben()}). Der eigene Bestand heißt im Gerät
 * `PM-EIGEN`, ein Kunde ohne Kundennummer `PM-K<id>` – daran erkennt der
 * nächste Import die Kunden wieder, die Prüflinge an ihrer Kennung.
 */
final class BenningVorlage
{
    /** Größte Vorlage (gemessen: knapp 200 KB). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Tabellen mit Kunden-, Geräte- und Prüfdaten – in der Vorlage leer. `timestamp` zuletzt (die Trigger schreiben hinein). */
    public const LEEREN = ['Kunden', 'Departments', 'Geraete', 'Geraete2AdminQuestions', 'Geraete2CustomerQuestions', 'Ergebnisse',
        'Ergebnisse_ALT', 'Sequences', 'SqProcedures', 'SqProcValues', 'TestResult2Admin2Answers', 'TestResult2Customer2Answers',
        'DurchgefuehrteSichtPruefung', 'ZuPruefendeSichtPruefung', 'timestamp'];

    /** Alte Kopien bei einer Umstellung am Gerät (`Geraete20230316104315`) und die Statistik von ANALYZE: auch leer. */
    private const LEEREN_MUSTER = '/^(?:(?:Geraete|Ergebnisse|Kunden)\d{8,14}|sqlite_stat\d)$/';

    /** Einstellungen des Geräts – bleiben. */
    public const BEHALTEN = ['DbInfos', 'Systemdaten', 'Pruefung', 'Grenzwerte', 'Thresholds', 'ViewQuestions', 'Pruefung2Questions',
        'SichtPruefung', 'Manufacturers', 'sqlite_sequence'];

    /** Ohne diese Tabellen taugt eine Datei nicht als Vorlage (der Export schreibt hinein oder liest daraus). */
    public const PFLICHT = ['Kunden', 'Geraete', 'Geraete2AdminQuestions', 'Manufacturers', 'Pruefung', 'Grenzwerte', 'DbInfos',
        'Systemdaten', 'ViewQuestions'];

    /** Was zwei Vorlagen unterscheidet (Vergleich über quote() jeder Zeile). */
    public const EINSTELLUNGEN = ['DbInfos', 'Systemdaten', 'Pruefung', 'Grenzwerte'];

    /** Trigger des Geräts: nach einer Änderung einen Zeitstempel in `timestamp` – sonst nichts. */
    private const TRIGGER = '/^CREATE\s+TRIGGER\s+[A-Za-z0-9_]+\s+AFTER\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+'
        . '(?:"[A-Za-z0-9_]+"|`[A-Za-z0-9_]+`|\[[A-Za-z0-9_]+\]|[A-Za-z0-9_]+)\s+BEGIN\s+INSERT\s+OR\s+REPLACE\s+INTO\s+'
        . '(?:"timestamp"|timestamp)\s*\(\s*tablename\s*,\s*lastchange\s*,\s*lastaction\s*\)\s*VALUES\s*\(\s*\'[A-Za-z0-9_]+\'\s*,\s*'
        . 'strftime\s*\(\s*\'%s\'\s*,\s*\'now\'\s*\)\s*,\s*\'(?:Insert|Update|Delete)\'\s*\)\s*;\s*END\s*;?\s*$/i';

    /** Längen der Textspalten im Gerät (nvarchar) – länger schreibt der Export nicht. */
    private const KUNDEN_LAENGEN = [
        'Kunden_Name' => 50, 'Kunden_Strasse' => 50, 'Kunden_PLZ' => 10, 'Kunden_Wohnort' => 50, 'Kunden_Rufnummer' => 30,
        'Kunden_Fax' => 30, 'Kunden_Email' => 50, 'Kunden_Ansprechpartner' => 50, 'Ansprechpartner_Abteilung' => 50,
        'Ansprechpartner_Rufnummer' => 30, 'Ansprechpartner_Email' => 50, 'Kunden_Nummer' => 50,
    ];

    /** Feld eines Prüflings → Spalte im Gerät und ihre Länge. */
    public const GERAETE_TEXTE = [
        'kennung' => ['Geraete_ID', 50], 'bezeichnung' => ['Geraete_Bezeichnung', 50], 'bemerkung' => ['Geraete_Bemerkung', 500],
        'abteilung' => ['Geraete_Abteilung', 50], 'gebaeude' => ['Gebaeude', 80], 'hersteller' => ['Geraete_Hersteller', 50],
        'typ' => ['Geraete_Typ', 50], 'modell' => ['Geraete_Modell', 50], 'seriennummer' => ['Geraete_SN', 50],
    ];

    /** Vorgaben der Felder, die hier niemand pflegt (wenn der Prüfling sie nicht aus dem Gerät kennt). */
    private const TECHNIK_VORGABEN = [
        'vde' => 1, 'sichtpruefung' => 1, 'pruefoptionen' => 0, 'zusatz_pruefungen' => 0, 'private_anschaffung' => 0,
        'pruefablauf_2' => 0, 'threshold_list_id' => null, 'ev_pruefablauf' => null, 'ev_fehlertest' => null,
    ];

    /** Kundennummer des eigenen Bestands im Gerät. */
    public const EIGEN = 'PM-EIGEN';

    /** Text des eigenen Bestands, wenn kein Firmenname eingestellt ist. */
    public const EIGEN_NAME = 'Eigener Bestand';

    private const NIE = '1900-01-01T00:00:00.0000000';

    /** @var array<string,string> sha256 der Vorlage → Hash ihrer Einstellungen */
    private static array $einstellungenCache = [];

    // ------------------------------------------------------------ Schema

    /**
     * Taugt das Schema (Zeilen aus sqlite_master: type, name, tbl_name, sql)
     * als Vorlage? Nur Tabellen, Indizes und Trigger mit Namen aus
     * Buchstaben, Ziffern und _, nur bekannte Tabellen ({@see LEEREN},
     * {@see BEHALTEN}), keine virtuellen, alle aus {@see PFLICHT}, Trigger
     * nur nach dem Muster des Geräts, kein CHECK, keine berechnete Spalte,
     * kein DEFAULT-Ausdruck, kein Teil- oder Ausdrucksindex (wie
     * {@see BenningDatei::schemaSicher()}, hier am Text des Schemas).
     *
     * @param list<array<string,mixed>> $master
     * @return array{moeglich:bool,grund:string}
     */
    public static function schemaPruefen(array $master): array
    {
        $nein = static fn (string $grund): array => ['moeglich' => false, 'grund' => $grund];
        $bekannt = array_change_key_case(array_flip(array_merge(self::LEEREN, self::BEHALTEN)));
        $tabellen = [];
        foreach ($master as $m) {
            $typ = (string) ($m['type'] ?? '');
            $name = (string) ($m['name'] ?? '');
            $sql = (string) ($m['sql'] ?? '');
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
                return $nein('Ein Name im Schema enthält ungewöhnliche Zeichen.');
            }
            if ($typ === 'table') {
                if (preg_match('/^\s*CREATE\s+VIRTUAL\s/i', $sql) === 1) {
                    return $nein('Die Tabelle ' . $name . ' ist eine virtuelle Tabelle.');
                }
                // Wie BenningDatei::schemaSicher() (dort über die PRAGMAs, die vor quick_check laufen):
                // nichts, was beim Einfügen, VACUUM oder integrity_check rechnet.
                if (preg_match('/\bCHECK\s*\(|\bGENERATED\s+ALWAYS\b|\bAS\s*\(|\bDEFAULT\s*\(/i', $sql) === 1) {
                    return $nein('Die Tabelle ' . $name . ' rechnet beim Schreiben (CHECK, berechnete Spalte oder DEFAULT-Ausdruck).');
                }
                if (!isset($bekannt[strtolower($name)]) && preg_match(self::LEEREN_MUSTER, $name) !== 1) {
                    return $nein('Die Tabelle ' . $name . ' ist unbekannt – sie könnte Kunden- oder Prüfdaten enthalten.');
                }
                $tabellen[strtolower($name)] = true;
            } elseif ($typ === 'trigger') {
                if (preg_match(self::TRIGGER, $sql) !== 1) {
                    return $nein('Der Trigger ' . $name . ' tut mehr, als einen Zeitstempel zu schreiben.');
                }
            } elseif ($typ === 'index') {
                if (preg_match('/\bWHERE\b/i', $sql) === 1 || ($sql !== '' && preg_match('/\bON\s+\S+\s*\((?:[^()]|\([^()]*\))*\(/is', $sql) === 1)) {
                    return $nein('Der Index ' . $name . ' gilt nur für einen Teil der Zeilen oder geht über einen Ausdruck.');
                }
            } else {
                return $nein('Im Schema steht „' . $name . '“ vom Typ ' . Messarten::text($typ, 20) . '.');
            }
        }
        foreach (self::PFLICHT as $t) {
            if (!isset($tabellen[strtolower($t)])) {
                return $nein('Die Tabelle ' . $t . ' fehlt.');
            }
        }
        return ['moeglich' => true, 'grund' => ''];
    }

    /** Wird die Tabelle $name in der Vorlage geleert? */
    public static function wirdGeleert(string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', self::LEEREN), true) || preg_match(self::LEEREN_MUSTER, $name) === 1;
    }

    // ------------------------------------------------------------ Vorlage bauen

    /**
     * Aus der Datenbank des Prüfgeräts unter $quelle die Vorlage bauen
     * (siehe Klassenkommentar). Die Datei selbst bleibt unberührt.
     *
     * @return array{ok:bool,grund:string,bytes?:string,sha256?:string,groesse?:int,einstellungen?:string,db_version?:string}
     */
    public static function bereinigen(string $quelle): array
    {
        $nein = static fn (string $grund): array => ['ok' => false, 'grund' => $grund];
        $kopf = is_file($quelle) ? (string) file_get_contents($quelle, false, null, 0, 100) : '';
        if (strlen($kopf) < 100 || !str_starts_with($kopf, "SQLite format 3\0") || ord($kopf[18]) !== 1 || ord($kopf[19]) !== 1) {
            return $nein('Keine Datenbank des Prüfgeräts.');
        }
        $kopie = null;
        $pdo = null;
        try {
            $kopie = BenningDatei::kopieren($quelle, 'gp-vorlage-');
            $pdo = self::schreibbar($kopie);
            $master = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master LIMIT ' . (BenningDatei::grenze('schema') + 1))->fetchAll();
            if (count($master) > BenningDatei::grenze('schema')) {
                return $nein('Zu viele Einträge im Schema.');
            }
            $schema = self::schemaPruefen($master);
            if (!$schema['moeglich']) {
                return $nein($schema['grund']);
            }
            $geleert = [];
            $zuletzt = [];
            foreach ($master as $m) {
                if ((string) $m['type'] !== 'table' || !self::wirdGeleert((string) $m['name'])) {
                    continue;
                }
                if (strtolower((string) $m['name']) === 'timestamp') {
                    $zuletzt[] = (string) $m['name'];
                } else {
                    $geleert[] = (string) $m['name'];
                }
            }
            $pdo->exec('BEGIN');
            foreach ([...$geleert, ...$zuletzt] as $t) {
                $pdo->exec('DELETE FROM "' . $t . '"');
            }
            $hatSequenz = (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn();
            if ($hatSequenz && $geleert !== []) {
                $st = $pdo->prepare('DELETE FROM sqlite_sequence WHERE name = ? COLLATE NOCASE');
                foreach ([...$geleert, ...$zuletzt] as $t) {
                    $st->execute([$t]);
                }
            }
            $pdo->exec('COMMIT');
            $pdo->exec('VACUUM');
            if ($pdo->query('PRAGMA integrity_check')->fetchAll(\PDO::FETCH_COLUMN) !== ['ok']) {
                return $nein('Nach dem Bereinigen meldet integrity_check Fehler.');
            }
            $einstellungen = self::einstellungen($pdo);
            $dbVersion = '';
            try {
                $dbVersion = Messarten::text($pdo->query("SELECT value FROM DbInfos WHERE ident = 'DB-Version' LIMIT 1")->fetchColumn(), 20);
            } catch (\PDOException) {
                $dbVersion = '';
            }
            $pdo = null;
            clearstatcache(true, $kopie);
            if ((int) filesize($kopie) > self::MAX_BYTES) {
                return $nein('Die bereinigte Vorlage ist größer als 2 MB.');
            }
            $bytes = (string) file_get_contents($kopie);
            if (strlen($bytes) < 100 || ord($bytes[18]) !== 1 || ord($bytes[19]) !== 1) {
                return $nein('Der Kopf der bereinigten Vorlage ist ungültig.');
            }
            return ['ok' => true, 'grund' => '', 'bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'groesse' => strlen($bytes),
                'einstellungen' => $einstellungen, 'db_version' => $dbVersion];
        } catch (\DomainException $e) {
            return $nein($e->getMessage());
        } catch (\PDOException) {
            return $nein('Die Datei ließ sich nicht als Vorlage bereinigen.');
        } finally {
            $pdo = null;
            if ($kopie !== null) {
                @unlink($kopie);
                @unlink($kopie . '-journal');
            }
        }
    }

    /**
     * Fingerabdruck der Einstellungen ({@see EINSTELLUNGEN}): jede Zeile über
     * quote() (REAL genau, Text und Blob unterscheidbar), in rowid-Reihenfolge.
     */
    public static function einstellungen(\PDO $pdo): string
    {
        $h = hash_init('sha256');
        foreach (self::EINSTELLUNGEN as $t) {
            $spalten = array_map(static fn (array $s): string => (string) $s['name'], $pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll(\PDO::FETCH_ASSOC));
            hash_update($h, $t . ':' . implode(',', $spalten) . "\n");
            if ($spalten === []) {
                continue;
            }
            $ausdruck = implode(" || ',' || ", array_map(static fn (string $s): string => 'quote("' . str_replace('"', '""', $s) . '")', $spalten));
            foreach ($pdo->query('SELECT ' . $ausdruck . ' FROM "' . $t . '" ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN) as $zeile) {
                hash_update($h, (string) $zeile . "\n");
            }
        }
        return hash_final($h);
    }

    /** Fingerabdruck der Einstellungen aus den Bytes einer Vorlage (je Anfrage gemerkt); null, wenn sie sich nicht lesen lässt. */
    public static function einstellungenAus(string $bytes): ?string
    {
        $sha = hash('sha256', $bytes);
        if (isset(self::$einstellungenCache[$sha])) {
            return self::$einstellungenCache[$sha];
        }
        $pfad = null;
        $pdo = null;
        try {
            $pfad = self::temp('gp-vorlage-', $bytes);
            $pdo = BenningDatei::oeffnen($pfad);
            return self::$einstellungenCache[$sha] = self::einstellungen($pdo);
        } catch (\Throwable) {
            return null;
        } finally {
            $pdo = null;
            if ($pfad !== null) {
                @unlink($pfad);
            }
        }
    }

    // ------------------------------------------------------------ Gespeicherte Vorlagen

    /**
     * Die aktive Vorlage (ohne `daten`, außer mit $mitDaten); null, wenn es
     * noch keine gibt.
     *
     * @return array<string,mixed>|null
     */
    public static function aktiv(bool $mitDaten = false): ?array
    {
        $z = Database::first('SELECT ' . ($mitDaten ? '*' : 'id, sha256, groesse, db_version, messgeraet, messgeraet_sn, import_id, aktiv, created_by, created_at')
            . ' FROM gp_vorlagen WHERE aktiv = 1 ORDER BY id DESC LIMIT 1');
        if ($z === null) {
            return null;
        }
        foreach (['id', 'groesse'] as $k) {
            $z[$k] = (int) $z[$k];
        }
        $z['import_id'] = $z['import_id'] === null ? null : (int) $z['import_id'];
        return $z;
    }

    /** Die Bytes einer gespeicherten Vorlage – nur, wenn sha256 und Größe stimmen. */
    public static function bytes(array $vorlage): ?string
    {
        $bytes = base64_decode((string) ($vorlage['daten'] ?? ''), true);
        if (!is_string($bytes) || strlen($bytes) !== (int) ($vorlage['groesse'] ?? -1) || !hash_equals((string) ($vorlage['sha256'] ?? ''), hash('sha256', $bytes))) {
            return null;
        }
        return $bytes;
    }

    /**
     * Eine bereinigte Vorlage speichern und aktiv setzen (alle anderen
     * inaktiv). Gibt es dieselben Bytes schon, wird jene Zeile wieder aktiv.
     * Ohne eigene Transaktion – läuft in der des Imports.
     *
     * @param array{messgeraet?:string,messgeraet_sn?:string,db_version?:string} $angaben
     * @return array{id:int,neu:bool}
     */
    public static function speichern(string $bytes, array $angaben, ?int $importId): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Vorlage leer oder zu groß.');
        }
        $sha = hash('sha256', $bytes);
        $vorhanden = Database::first('SELECT id FROM gp_vorlagen WHERE sha256 = ? ORDER BY id DESC LIMIT 1', [$sha]);
        Database::execute('UPDATE gp_vorlagen SET aktiv = 0 WHERE aktiv = 1');
        if ($vorhanden !== null) {
            Database::execute('UPDATE gp_vorlagen SET aktiv = 1 WHERE id = ?', [(int) $vorhanden['id']]);
            return ['id' => (int) $vorhanden['id'], 'neu' => false];
        }
        $id = Database::insert('gp_vorlagen', [
            'sha256'        => $sha,
            'groesse'       => strlen($bytes),
            'daten'         => base64_encode($bytes),
            'db_version'    => Messarten::text($angaben['db_version'] ?? '', 20),
            'messgeraet'    => Messarten::text($angaben['messgeraet'] ?? '', 80),
            'messgeraet_sn' => Messarten::text($angaben['messgeraet_sn'] ?? '', 80),
            'import_id'     => $importId,
            'aktiv'         => 1,
            'created_by'    => Auth::id(),
            'created_at'    => gmdate('c'),
        ]);
        return ['id' => $id, 'neu' => true];
    }

    // ------------------------------------------------------------ Prüfabläufe

    /**
     * Prüfabläufe aus der Normalform (`pruefablaeufe`) nach `gp_pruefablaeufe`.
     * Fehlende kommen immer dazu. Nur mit $voll (die Datei wird Vorlage)
     * ändern sich vorhandene, und was nicht mehr in der Datei steht, wird
     * ungültig – nie gelöscht. Norm und Zubehör bleiben, wie sie hier stehen.
     * Ohne eigene Transaktion.
     *
     * @param list<array<string,mixed>> $ablaeufe
     * @return array{neu:int,geaendert:int,entfallen:int}
     */
    public static function ablaeufeAbgleichen(array $ablaeufe, bool $voll): array
    {
        $zahlen = ['neu' => 0, 'geaendert' => 0, 'entfallen' => 0];
        $hier = [];
        foreach (Database::select('SELECT * FROM gp_pruefablaeufe') as $z) {
            $hier[(string) $z['geraet_id']] = $z;
        }
        $inDatei = [];
        foreach ($ablaeufe as $a) {
            $gid = (string) ($a['geraet_id'] ?? '');
            if (preg_match('/^\d{1,18}$/', $gid) !== 1) {
                continue;
            }
            $inDatei[$gid] = true;
            $name = Messarten::text($a['name'] ?? '', 120);
            $felder = [
                'nr'               => Messarten::text($a['nr'] ?? '', 20),
                'name'             => $name !== '' ? $name : 'Ablauf ' . $gid,
                'ablauf'           => self::maskeText($a['ablauf'] ?? '0'),
                'ablauf_2'         => self::maskeText($a['ablauf_2'] ?? '0'),
                'schutzklasse'     => Messarten::text($a['schutzklasse'] ?? '', 4),
                'grenzwerte_index' => Messarten::text($a['grenzwerte_index'] ?? '', 20),
                'fest'             => ($a['fest'] ?? false) === true ? 1 : 0,
                'individuell'      => ($a['individuell'] ?? false) === true ? 1 : 0,
                'ungueltig'        => ($a['ungueltig'] ?? false) === true ? 1 : 0,
            ];
            $z = $hier[$gid] ?? null;
            if ($z === null) {
                Database::insert('gp_pruefablaeufe', ['geraet_id' => $gid] + $felder + ['created_at' => gmdate('c')]);
                $zahlen['neu']++;
                continue;
            }
            if (!$voll) {
                continue;
            }
            $anders = array_filter($felder, static fn ($w, string $k): bool => (string) $z[$k] !== (string) $w, ARRAY_FILTER_USE_BOTH);
            if ($anders !== []) {
                Database::update('gp_pruefablaeufe', (int) $z['id'], $anders + ['updated_at' => gmdate('c')]);
                $zahlen['geaendert']++;
            }
        }
        if ($voll) {
            foreach ($hier as $gid => $z) {
                if (!isset($inDatei[$gid]) && (int) $z['ungueltig'] === 0) {
                    Database::update('gp_pruefablaeufe', (int) $z['id'], ['ungueltig' => 1, 'updated_at' => gmdate('c')]);
                    $zahlen['entfallen']++;
                }
            }
        }
        return $zahlen;
    }

    /**
     * Alle Prüfabläufe für die Seite „Prüfabläufe“: gültige zuerst, nach der
     * ID des Geräts; je Ablauf die Zahl der Prüflinge, die abgeleitete Norm
     * (wenn keine von Hand steht) und das Zubehör (Prüfgeräte).
     *
     * @return list<array<string,mixed>>
     */
    public static function pruefablaeufe(): array
    {
        $geraete = [];
        foreach (Pruefgeraete::liste() as $g) {
            $geraete[(int) $g['id']] = $g;
        }
        $aus = [];
        foreach (Database::select('SELECT a.*, (SELECT COUNT(*) FROM gp_prueflinge p WHERE p.pruefablauf_id = a.id) AS prueflinge
            FROM gp_pruefablaeufe a ORDER BY a.ungueltig, LENGTH(a.geraet_id), a.geraet_id') as $z) {
            $z['id'] = (int) $z['id'];
            $z['prueflinge'] = (int) $z['prueflinge'];
            foreach (['fest', 'individuell', 'ungueltig'] as $k) {
                $z[$k] = (int) $z[$k] === 1;
            }
            $z['zubehoer'] = self::zubehoerIds($z['zubehoer']);
            $z['zubehoer_geraete'] = array_values(array_filter(array_map(static fn (int $id): ?array => $geraete[$id] ?? null, $z['zubehoer'])));
            $z['norm_abgeleitet'] = Messarten::norm((string) $z['name'], 'wiederholung');
            $aus[] = $z;
        }
        return $aus;
    }

    /** @return array<string,mixed>|null ein Prüfablauf (zubehoer als Liste von IDs) */
    public static function pruefablauf(int $id): ?array
    {
        $z = Database::first('SELECT * FROM gp_pruefablaeufe WHERE id = ?', [$id]);
        if ($z === null) {
            return null;
        }
        $z['id'] = (int) $z['id'];
        $z['zubehoer'] = self::zubehoerIds($z['zubehoer']);
        return $z;
    }

    /**
     * Norm (von Hand, höchstens 160 Zeichen; leer = abgeleitet) und Zubehör
     * (Prüfgeräte, die es gibt) eines Prüfablaufs speichern. Ablauf, Name,
     * Grenzwerte bleiben Sache des Geräts.
     *
     * @param array{norm?:mixed,zubehoer?:mixed} $daten
     * @return array{ok:bool,message:string}
     */
    public static function pruefablaufSpeichern(int $id, array $daten): array
    {
        $ablauf = self::pruefablauf($id);
        if ($ablauf === null) {
            return ['ok' => false, 'message' => 'Diesen Prüfablauf gibt es nicht.'];
        }
        $roh = is_scalar($daten['norm'] ?? null) ? (string) $daten['norm'] : '';
        $norm = Messarten::text($roh, 160);
        if (mb_strlen(trim($roh)) > 160) {
            return ['ok' => false, 'message' => 'Die Norm hat höchstens 160 Zeichen.'];
        }
        $ids = [];
        foreach (is_array($daten['zubehoer'] ?? null) ? $daten['zubehoer'] : [] as $wert) {
            if (!is_scalar($wert) || preg_match('/^\d{1,9}$/', (string) $wert) !== 1 || Pruefgeraete::find((int) $wert) === null) {
                return ['ok' => false, 'message' => 'Dieses Prüfgerät gibt es nicht.'];
            }
            $ids[(int) $wert] = (int) $wert;
        }
        ksort($ids);
        Database::update('gp_pruefablaeufe', $id, [
            'norm'       => $norm,
            'zubehoer'   => $ids === [] ? null : json_encode(array_values($ids)),
            'updated_at' => gmdate('c'),
        ]);
        Audit::log('geraetepruefung.pruefablauf', 'gp_pruefablauf', (string) $id, ['zubehoer' => count($ids), 'norm_von_hand' => $norm !== '']);
        return ['ok' => true, 'message' => 'Prüfablauf gespeichert.'];
    }

    // ------------------------------------------------------------ Export

    /**
     * Wem man eine Datei für das Prüfgerät schreiben kann: der eigene Bestand
     * und jeder Kunde, je mit der Zahl seiner Prüflinge und wie viele davon
     * ohne gültigen Prüfablauf übergangen würden.
     *
     * Dazu, wie viele trotz gültigem Prüfablauf ohne Leitungsangaben oder
     * Prüfintervall übergangen würden ({@see angabenVollstaendig()}).
     *
     * @return list<array{wert:string,name:string,kundennummer:string,prueflinge:int,ohne_ablauf:int,ohne_angaben:int}>
     */
    public static function besitzer(): array
    {
        $zahlen = [];
        foreach (Database::select('SELECT p.kunde_id, p.leitungslaenge, p.querschnitt, p.leiter, p.intervall_monate,
                CASE WHEN a.id IS NULL OR a.ungueltig = 1 THEN 1 ELSE 0 END AS ohne
            FROM gp_prueflinge p LEFT JOIN gp_pruefablaeufe a ON a.id = p.pruefablauf_id') as $z) {
            $k = $z['kunde_id'] === null ? 'eigen' : (string) (int) $z['kunde_id'];
            $zahlen[$k] ??= [0, 0, 0];
            $zahlen[$k][0]++;
            if ((int) $z['ohne'] === 1) {
                $zahlen[$k][1]++;
            } elseif (!self::angabenVollstaendig($z)) {
                $zahlen[$k][2]++;
            }
        }
        $aus = [['wert' => 'eigen', 'name' => self::eigenName(), 'kundennummer' => self::EIGEN,
            'prueflinge' => $zahlen['eigen'][0] ?? 0, 'ohne_ablauf' => $zahlen['eigen'][1] ?? 0, 'ohne_angaben' => $zahlen['eigen'][2] ?? 0]];
        foreach (Database::select('SELECT id, name, kundennummer FROM kunden ORDER BY name, id') as $k) {
            $id = (string) (int) $k['id'];
            $aus[] = ['wert' => $id, 'name' => (string) $k['name'], 'kundennummer' => self::kundennummerImGeraet($k),
                'prueflinge' => $zahlen[$id][0] ?? 0, 'ohne_ablauf' => $zahlen[$id][1] ?? 0, 'ohne_angaben' => $zahlen[$id][2] ?? 0];
        }
        return $aus;
    }

    /**
     * Die letzten Exporte (nur Zahlen, wer, wann).
     *
     * @return list<array<string,mixed>>
     */
    public static function letzteExporte(int $anzahl = 5): array
    {
        return Database::select('SELECT e.id, e.kunden, e.prueflinge, e.created_at, u.username FROM gp_exporte e
            LEFT JOIN users u ON u.id = e.created_by ORDER BY e.id DESC LIMIT ' . max(1, min(100, $anzahl)));
    }

    /** Name des eigenen Bestands im Gerät: der Firmenname der Berichte, sonst „Eigener Bestand“. */
    public static function eigenName(): string
    {
        $name = Einstellungen::wert('firma.name');
        return $name !== '' ? $name : self::EIGEN_NAME;
    }

    /**
     * Kundennummer eines Kunden im Gerät: seine eigene, wenn sie dort Platz
     * hat und nicht nach PowerManagement aussieht, sonst `PM-K<id>`.
     *
     * @param array<string,mixed> $kunde
     */
    public static function kundennummerImGeraet(array $kunde): string
    {
        $nummer = trim((string) ($kunde['kundennummer'] ?? ''));
        if ($nummer === '' || mb_strlen($nummer) > self::KUNDEN_LAENGEN['Kunden_Nummer'] || self::reserviert($nummer)) {
            return 'PM-K' . (int) $kunde['id'];
        }
        return $nummer;
    }

    /** `PM-EIGEN` und `PM-K<Zahl>` gehören PowerManagement – nie die Kundennummer eines Kunden. */
    public static function reserviert(string $nummer): bool
    {
        return strcasecmp(trim($nummer), self::EIGEN) === 0 || preg_match('/^PM-K\d+$/i', trim($nummer)) === 1;
    }

    /**
     * Eine Datenbank für das Prüfgerät aus der aktiven Vorlage schreiben.
     * $besitzer: Liste aus 'eigen' und Kunden-IDs. Geschrieben werden die
     * Kunden (eigener Bestand `PM-EIGEN`), fehlende Hersteller und alle
     * Prüflinge der Besitzer mit gültigem Prüfablauf, den die Vorlage kennt
     * (die übrigen werden gezählt und übergangen; außer Betrieb →
     * `AusserBetrieb=1`), dazu ihre Fragen der Sichtprüfung. Nichts sonst
     * ändert sich. Hält den Export in `gp_exporte` fest (nur Zahlen) und im
     * Audit.
     *
     * @param list<mixed> $besitzer
     * @return array{ok:bool,message:string,bytes?:string,dateiname?:string,zahlen?:array<string,int>,id?:int}
     */
    public static function exportieren(array $besitzer, string $name): array
    {
        $liste = [];
        foreach ($besitzer as $b) {
            $b = is_scalar($b) ? trim((string) $b) : '';
            if ($b === 'eigen') {
                $liste['eigen'] = null;
            } elseif (preg_match('/^\d{1,9}$/', $b) === 1) {
                $kunde = KundenService::find((int) $b);
                if ($kunde === null) {
                    return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
                }
                $liste[(string) (int) $b] = $kunde;
            } elseif ($b !== '') {
                return ['ok' => false, 'message' => 'Unbekannte Auswahl.'];
            }
        }
        if ($liste === []) {
            return ['ok' => false, 'message' => 'Bitte den eigenen Bestand oder mindestens einen Kunden wählen.'];
        }
        $vorlage = self::aktiv(true);
        if ($vorlage === null) {
            return ['ok' => false, 'message' => 'Es gibt noch keine Vorlage – erst eine Datenbank des Prüfgeräts importieren und ihre '
                . 'Einstellungen als Vorlage übernehmen.'];
        }
        $bytes = self::bytes($vorlage);
        if ($bytes === null) {
            return ['ok' => false, 'message' => 'Die gespeicherte Vorlage ist beschädigt – bitte eine Datenbank des Prüfgeräts noch einmal als Vorlage übernehmen.'];
        }
        $pfad = null;
        $pdo = null;
        try {
            $pfad = self::temp('gp-export-', $bytes);
            $pdo = self::schreibbar($pfad);
            $master = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master')->fetchAll();
            $schema = self::schemaPruefen($master);
            if (!$schema['moeglich']) {
                return ['ok' => false, 'message' => 'Die Vorlage taugt nicht mehr: ' . $schema['grund']];
            }
            $zahlen = self::schreiben($pdo, $liste);
            if ($pdo->query('PRAGMA integrity_check')->fetchAll(\PDO::FETCH_COLUMN) !== ['ok']) {
                return ['ok' => false, 'message' => 'Die geschriebene Datei ist nicht in Ordnung (integrity_check).'];
            }
            $pdo = null;
            clearstatcache(true, $pfad);
            // Was der Import nicht wieder lesen könnte, kommt nicht hinaus – und nie ungeprüft in den Speicher.
            if ((int) filesize($pfad) > BenningDatei::MAX_BYTES) {
                return ['ok' => false, 'message' => 'Die Datei für das Prüfgerät würde größer als ' . (int) (BenningDatei::MAX_BYTES / 1048576)
                    . ' MB – bitte weniger Besitzer auf einmal wählen.'];
            }
            $aus = (string) file_get_contents($pfad);
            if (strlen($aus) < 100 || ord($aus[18]) !== 1 || ord($aus[19]) !== 1) {
                return ['ok' => false, 'message' => 'Der Kopf der geschriebenen Datei ist ungültig.'];
            }
        } catch (\DomainException $e) {
            // storage/cache nicht beschreibbar oder voll, Vorlage beschädigt – Text für den Benutzer
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\PDOException $e) {
            error_log('Geräteprüfung, Export: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Die Datei für das Prüfgerät ließ sich nicht schreiben.'];
        } finally {
            $pdo = null;
            if ($pfad !== null) {
                @unlink($pfad);
                @unlink($pfad . '-journal');
            }
        }
        $id = Database::insert('gp_exporte', [
            'vorlage_id' => (int) $vorlage['id'],
            'sha256'     => hash('sha256', $aus),
            'kunden'     => $zahlen['kunden'],
            'prueflinge' => $zahlen['prueflinge'],
            'created_by' => Auth::id(),
            'created_at' => gmdate('c'),
        ]);
        Audit::log('geraetepruefung.export', 'gp_export', (string) $id, $zahlen + ['vorlage_id' => (int) $vorlage['id']]);
        $teile = [$zahlen['prueflinge'] . ($zahlen['prueflinge'] === 1 ? ' Prüfling' : ' Prüflinge') . ' für ' . $zahlen['kunden']
            . ' Besitzer'];
        if ($zahlen['ohne_ablauf'] > 0) {
            $teile[] = $zahlen['ohne_ablauf'] . ' ohne gültigen Prüfablauf übergangen';
        }
        if ($zahlen['ablauf_abweichend'] > 0) {
            $teile[] = $zahlen['ablauf_abweichend'] . ' mit einem Prüfablauf, der hier anders steht als in der Vorlage (Name oder Prüfschritte), übergangen'
                . ' – die Datenbank des Prüfgeräts noch einmal mit „als Vorlage übernehmen“ importieren';
        }
        if ($zahlen['ohne_angaben'] > 0) {
            $teile[] = $zahlen['ohne_angaben'] . ' ohne Leitungslänge, Querschnitt, Leiterzahl oder Prüfintervall übergangen'
                . ' – aus ihnen rechnet das Gerät seine Grenzwerte; bitte beim Prüfling eintragen';
        }
        $dateiname = Response::sichererDateiname(trim((string) preg_replace('/\.db$/i', '', trim($name))), 'PowerManagement') . '.db';
        return ['ok' => true, 'message' => 'Datei für das Prüfgerät geschrieben: ' . implode('; ', $teile) . '.', 'bytes' => $aus,
            'dateiname' => $dateiname, 'zahlen' => $zahlen, 'id' => $id];
    }

    /**
     * Kunden, Hersteller, Geräte und Fragen in die offene Kopie der Vorlage.
     *
     * Übergangen (und gezählt) wird ein Prüfling ohne gültigen Prüfablauf
     * (`ohne_ablauf`), mit einem Prüfablauf, dessen Name oder Maske hier
     * anders steht als in der Zeile `Pruefung` der Vorlage (`ablauf_abweichend`
     * – etwa ein am Gerät angelegter Ablauf ≥ 1 000 000 eines anderen
     * Prüfgeräts) und einer ohne Leitungslänge, Querschnitt, Leiterzahl
     * (> 0) oder Prüfintervall (≥ 1 Monat) (`ohne_angaben`): Aus ihnen rechnet
     * das Gerät seine Grenzwerte – nie 0 und nie ein erfundener Ersatz.
     *
     * @param array<string,array<string,mixed>|null> $liste 'eigen' => null, Kunden-ID => Kunde
     * @return array{kunden:int,prueflinge:int,ohne_ablauf:int,ablauf_abweichend:int,ohne_angaben:int,ausser_betrieb:int}
     */
    private static function schreiben(\PDO $pdo, array $liste): array
    {
        $spalten = [];
        foreach (['Kunden', 'Geraete', 'Geraete2AdminQuestions', 'Manufacturers'] as $t) {
            foreach ($pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll() as $s) {
                $spalten[$t][strtolower((string) $s['name'])] = (string) $s['name'];
            }
        }
        $einfuegen = static function (string $tabelle, array $werte) use ($pdo, $spalten): int {
            $werte = array_filter($werte, static fn (string $k): bool => isset($spalten[$tabelle][strtolower($k)]), ARRAY_FILTER_USE_KEY);
            $st = $pdo->prepare('INSERT INTO "' . $tabelle . '" (' . implode(', ', array_map(static fn (string $k): string => '"' . $k . '"', array_keys($werte)))
                . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')');
            $i = 1;
            foreach ($werte as $w) {
                $st->bindValue($i++, $w, match (true) {
                    $w === null => \PDO::PARAM_NULL,
                    is_int($w)  => \PDO::PARAM_INT,
                    default     => \PDO::PARAM_STR,
                });
            }
            $st->execute();
            return (int) $pdo->lastInsertId();
        };
        // Dezimaltext für REAL-Spalten: PDO kennt keinen Gleitkomma-Typ, die REAL-Affinität der
        // Spalte macht aus „1.5“ die Zahl 1.5 (gerechnet wird nie mit float – nichts gerundet).
        // Leer: Nennleistung 0 (so schreibt das Gerät „nicht angegeben“), U_Ausgang NULL. Länge,
        // Querschnitt und Leiter sind nie leer – ohne sie bleibt der Prüfling weg (angabenVollstaendig).
        $real = static fn (string $dezimal, bool $leerNull = false): ?string => $dezimal === '' ? ($leerNull ? null : '0') : $dezimal;
        $jetzt = (new \DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d H:i:s');
        $heute = (new \DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d') . ' 00:00:00';

        // Prüfabläufe der Vorlage: Name genau so, wie das Gerät ihn schreibt, und die Maske.
        $vorlageAblaeufe = [];
        foreach ($pdo->query('SELECT CAST(Pruefung_ID AS TEXT) AS id, Pruefung_Name AS name, CAST(Pruefung_Ablauf AS TEXT) AS ablauf FROM Pruefung')->fetchAll() as $a) {
            $vorlageAblaeufe[(string) $a['id']] = ['name' => is_scalar($a['name']) ? (string) $a['name'] : '', 'ablauf' => self::maskeText($a['ablauf'])];
        }
        $ablaeufe = [];
        foreach (Database::select('SELECT id, geraet_id, name, ablauf, ungueltig FROM gp_pruefablaeufe') as $a) {
            $ablaeufe[(int) $a['id']] = $a;
        }
        $hersteller = [];
        foreach ($pdo->query('SELECT id, name FROM Manufacturers')->fetchAll() as $h) {
            $hersteller[mb_strtolower(trim((string) $h['name']))] ??= (int) $h['id'];
        }
        $fragenVorgabe = [];
        $fragenGibt = [];
        foreach ($pdo->query('SELECT id, ViewLevel, IsDeleted FROM ViewQuestions ORDER BY id')->fetchAll() as $f) {
            $fragenGibt[(int) $f['id']] = true;
            $geloescht = in_array($f['IsDeleted'], [1, '1', true, 'true', 'True'], true);
            if ((int) $f['ViewLevel'] === 1 && !$geloescht) {
                $fragenVorgabe[] = (int) $f['id'];
            }
        }
        $letzte = [];
        foreach (Database::select('SELECT pruefling_id, MAX(geprueft_am) AS am FROM gp_pruefungen GROUP BY pruefling_id') as $z) {
            $letzte[(int) $z['pruefling_id']] = (string) $z['am'];
        }

        $zahlen = ['kunden' => 0, 'prueflinge' => 0, 'ohne_ablauf' => 0, 'ablauf_abweichend' => 0, 'ohne_angaben' => 0, 'ausser_betrieb' => 0];
        $pdo->exec('BEGIN');
        try {
            $kundenIndex = 0;
            $geraeteIndex = 0;
            $stFrage = $pdo->prepare('INSERT INTO Geraete2AdminQuestions (GeraeteId, QuestionId) VALUES (?, ?)');
            foreach ($liste as $wert => $kunde) {
                $kundenIndex++;
                $zeile = $kunde === null
                    ? ['Kunden_Name' => self::eigenName(), 'Kunden_Nummer' => self::EIGEN]
                    : [
                        'Kunden_Name' => (string) $kunde['name'], 'Kunden_Strasse' => (string) ($kunde['strasse'] ?? ''),
                        'Kunden_PLZ' => (string) ($kunde['plz'] ?? ''), 'Kunden_Wohnort' => (string) ($kunde['ort'] ?? ''),
                        'Kunden_Rufnummer' => (string) ($kunde['telefon'] ?? ''), 'Kunden_Fax' => (string) ($kunde['fax'] ?? ''),
                        'Kunden_Email' => (string) ($kunde['email'] ?? ''), 'Kunden_Ansprechpartner' => (string) ($kunde['ansprechpartner'] ?? ''),
                        'Ansprechpartner_Abteilung' => (string) ($kunde['ansprechpartner_abteilung'] ?? ''),
                        'Ansprechpartner_Rufnummer' => (string) ($kunde['ansprechpartner_telefon'] ?? ''),
                        'Ansprechpartner_Email' => (string) ($kunde['ansprechpartner_email'] ?? ''),
                        'Kunden_Nummer' => self::kundennummerImGeraet($kunde),
                    ];
                foreach ($zeile as $k => $v) {
                    $zeile[$k] = self::geraetText($v, self::KUNDEN_LAENGEN[$k]);
                }
                $einfuegen('Kunden', ['Kunden_Index' => $kundenIndex] + $zeile + ['Aenderungsdatum' => $jetzt]);
                $zahlen['kunden']++;

                foreach (Prueflinge::fuerBesitzer($kunde === null ? null : (int) $wert) as $p) {
                    $ablauf = $p['pruefablauf_id'] === null ? null : ($ablaeufe[(int) $p['pruefablauf_id']] ?? null);
                    $inVorlage = $ablauf === null ? null : ($vorlageAblaeufe[(string) $ablauf['geraet_id']] ?? null);
                    if ($ablauf === null || (int) $ablauf['ungueltig'] === 1 || $inVorlage === null) {
                        $zahlen['ohne_ablauf']++;
                        continue;
                    }
                    if (!self::ablaufPasst($ablauf, $inVorlage)) {
                        $zahlen['ablauf_abweichend']++;
                        continue;
                    }
                    if (!self::angabenVollstaendig($p)) {
                        $zahlen['ohne_angaben']++;
                        continue;
                    }
                    $technik = is_array($p['technik']) ? $p['technik'] : [];
                    // Ein Einzelablauf („Individuell“) hat am Gerät seine eigene Bitmaske.
                    $maske = (string) $ablauf['ablauf'];
                    if ((string) ($technik['individuell_id'] ?? '') === (string) $ablauf['geraet_id'] && preg_match('/^-?\d{1,20}$/', (string) ($technik['individuell_ablauf'] ?? '')) === 1) {
                        $maske = (string) $technik['individuell_ablauf'];
                    }
                    $herstellerId = null;
                    $herstellerName = self::geraetText((string) $p['hersteller'], 50);
                    if ($herstellerName !== '') {
                        $schluessel = mb_strtolower($herstellerName);
                        if (!isset($hersteller[$schluessel])) {
                            $hersteller[$schluessel] = $einfuegen('Manufacturers', ['name' => $herstellerName]);
                        }
                        $herstellerId = $hersteller[$schluessel];
                    }
                    $pruefdatum = isset($letzte[$p['id']]) ? (string) Messarten::ortszeit($letzte[$p['id']])
                        : ($p['letzte_pruefung'] !== null && $p['letzte_pruefung'] !== '' ? $p['letzte_pruefung'] . ' 00:00:00' : self::NIE);
                    $naechste = $p['naechste_pruefung'] !== null && $p['naechste_pruefung'] !== '' ? $p['naechste_pruefung'] . ' 00:00:00' : $heute;
                    $werte = ['Geraete_Index' => ++$geraeteIndex, 'Geraete_Kunden_Index' => $kundenIndex];
                    foreach (self::GERAETE_TEXTE as $feld => [$spalte, $laenge]) {
                        $werte[$spalte] = self::geraetText((string) $p[$feld], $laenge);
                    }
                    $werte += [
                        'Geraete_Pruefablauf'             => (int) $maske,
                        'Geraete_Pruefablauf_Name'        => $inVorlage['name'],
                        'Geraete_Pruefablauf_Index'       => (int) $ablauf['geraet_id'],
                        'Geraete_Pruefdatum'              => $pruefdatum,
                        'Geraete_Datum_Naechste_Pruefung' => $naechste,
                        'Geraete_Pruefintervall'          => (int) $p['intervall_monate'],
                        'Geraete_SK'                      => (int) $p['schutzklasse'],
                        'Geraete_Leitungslaenge'          => (string) $p['leitungslaenge'],
                        'Geraete_Leitungsquerschnitt'     => (string) $p['querschnitt'],
                        'Geraete_Anz_Leiter'              => (string) $p['leiter'],
                        'Geraete_Nennleistung'            => $real((string) $p['nennleistung']),
                        'Geraete_U_Ausgang'               => $real((string) ($technik['u_ausgang'] ?? ''), true),
                        'manufacturerId'                  => $herstellerId,
                        'AusserBetrieb'                   => $p['ausser_betrieb'] ? 1 : 0,
                        'Aenderungsdatum'                 => $jetzt,
                    ];
                    foreach (self::TECHNIK_VORGABEN as $feld => $vorgabe) {
                        $roh = (string) ($technik[$feld] ?? '');
                        $werte[BenningDatei::TECHNIK[$feld]] = preg_match('/^-?\d{1,20}$/', $roh) === 1 ? (int) $roh : $vorgabe;
                    }
                    $einfuegen('Geraete', $werte);
                    $fragen = array_key_exists('fragen', $technik)
                        ? array_map('intval', array_filter(explode(',', (string) $technik['fragen']), static fn (string $f): bool => ctype_digit($f)))
                        : $fragenVorgabe;
                    foreach (array_unique($fragen) as $frage) {
                        if (isset($fragenGibt[$frage])) {
                            $stFrage->execute([$geraeteIndex, $frage]);
                        }
                    }
                    $zahlen['prueflinge']++;
                    $zahlen['ausser_betrieb'] += $p['ausser_betrieb'] ? 1 : 0;
                }
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        return $zahlen;
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * Passt ein Prüfablauf von hier (gp_pruefablaeufe) zur Zeile `Pruefung`
     * der Vorlage – gleicher Name (gesäubert wie beim Import) und gleiche
     * Maske? Sonst schriebe der Export einem Prüfling die Schritte eines
     * anderen Ablaufs zu (am Gerät angelegte Abläufe ≥ 1 000 000 vergibt
     * jedes Prüfgerät selbst).
     *
     * @param array<string,mixed> $hier
     * @param array{name:string,ablauf:string} $vorlage
     */
    public static function ablaufPasst(array $hier, array $vorlage): bool
    {
        $name = Messarten::text($vorlage['name'], 120);
        $name = $name !== '' ? $name : 'Ablauf ' . $hier['geraet_id'];
        return $name === (string) $hier['name'] && $vorlage['ablauf'] === self::maskeText($hier['ablauf'] ?? '0');
    }

    /**
     * Hat der Prüfling, was das Gerät für seine Grenzwerte braucht:
     * Leitungslänge, Querschnitt und Leiterzahl größer 0, Prüfintervall
     * mindestens ein Monat?
     *
     * @param array<string,mixed> $p
     */
    public static function angabenVollstaendig(array $p): bool
    {
        foreach (['leitungslaenge', 'querschnitt', 'leiter'] as $feld) {
            if (!Messarten::positiv((string) ($p[$feld] ?? ''))) {
                return false;
            }
        }
        return (int) ($p['intervall_monate'] ?? 0) >= 1;
    }

    /** Eine Kopie zum Schreiben öffnen (nur Kopien unter storage/cache – nie die Datei von außen, nie die App-Datenbank). */
    private static function schreibbar(string $pfad): \PDO
    {
        $pdo = new \PDO('sqlite:' . $pfad, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Die Trigger des Geräts (nur nach dem geprüften Muster) laufen auch so.
        $pdo->exec('PRAGMA trusted_schema = OFF');
        $pdo->exec('PRAGMA cell_size_check = ON');
        // Nichts, was beim Prüfen, Einfügen, VACUUM oder integrity_check rechnet
        // (berechnete Spalten, DEFAULT-Ausdrücke, CHECK, Ausdrucks- und Teilindizes).
        BenningDatei::schemaSicher($pdo);
        if ($pdo->query('PRAGMA quick_check')->fetchAll(\PDO::FETCH_COLUMN) !== ['ok']) {
            throw new \DomainException('Die Datenbank ist beschädigt (quick_check meldet Fehler).');
        }
        return $pdo;
    }

    /** Bytes in eine neue Datei unter storage/cache (0600). */
    private static function temp(string $vorsilbe, string $bytes): string
    {
        $ordner = APP_ROOT . '/storage/cache';
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0775, true);
        }
        $pfad = $ordner . '/' . $vorsilbe . bin2hex(random_bytes(16)) . '.db';
        $h = @fopen($pfad, 'xb');
        if ($h === false) {
            throw new \DomainException('storage/cache ist nicht beschreibbar.');
        }
        @chmod($pfad, 0600);
        $ok = fwrite($h, $bytes) === strlen($bytes);
        fclose($h);
        if (!$ok) {
            @unlink($pfad);
            throw new \DomainException('storage/cache ist voll oder nicht beschreibbar.');
        }
        return $pfad;
    }

    /** Text für eine nvarchar-Spalte des Geräts: höchstens $laenge Zeichen, ohne Leerzeichen am Ende. */
    public static function geraetText(string $text, int $laenge): string
    {
        return rtrim(mb_substr($text, 0, $laenge));
    }

    /** @return list<int> */
    private static function zubehoerIds(mixed $json): array
    {
        $liste = is_string($json) ? json_decode($json, true) : null;
        return is_array($liste) ? array_values(array_filter(array_map('intval', $liste), static fn (int $i): bool => $i > 0)) : [];
    }

    private static function maskeText(mixed $wert): string
    {
        $text = is_scalar($wert) ? trim((string) $wert) : '';
        return preg_match('/^-?\d{1,20}$/', $text) === 1 ? $text : '0';
    }
}
