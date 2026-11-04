<?php
declare(strict_types=1);

namespace App\Geraetepruefung;

use App\Auth\Auth;
use App\Berichte\Einstellungen;
use App\Berichte\Kanon;
use App\Berichte\Unterschrift;
use App\Kunden\Kundenbereich;
use App\Kunden\KundenService;
use App\Kunden\Pruefnachweise;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Prüfberichte der Geräteprüfung (Tabellen `gp_berichte`,
 * `gp_unterschriften`, `gp_bericht_pdf`, `gp_bericht_nummern`).
 *
 * Eine Prüfung aus dem Prüfgerät ohne gültigen Bericht ist **offen**.
 * Unterschreibt ein Prüfer, entsteht in **einem** Schritt (je Prüfung eine
 * Transaktion) die eingefrorene Fassung: Nummer, Inhalt (nur Text, Kanon),
 * Prüfsumme, Kette und Siegel, das PDF Byte für Byte in der Datenbank. Eine
 * Unterschrift (Striche) deckt einen ganzen Durchgang – die
 * Sammelunterschrift über beliebig viele Prüfungen, die der Browser in Stücken
 * zu {@see JE_ANFRAGE} mit derselben Kennung (`anfrage`) schickt; dieselbe
 * Kennung noch einmal (schwaches Netz, Update) legt nichts doppelt an.
 *
 * Messdaten ändern sich nie (sie stehen unveränderlich in `gp_pruefungen`).
 * Eine **Korrektur** ({@see korrektur()}) macht die Prüfung wieder offen;
 * beim nächsten Unterschreiben entsteht Fassung n+1 (Nummer mit „-K<n>“) mit
 * dem jetzigen Stand von Prüfling und Kunde, die vorige wird „ersetzt“.
 *
 * Bewertet wird nie hier: das Ergebnis kommt aus der Prüfung (vom Gerät,
 * vom Import nur abgeleitet und einheitlich gehalten).
 *
 * Rechte: sehen mit `geraetepruefung.view`, unterschreiben und Korrektur mit
 * `geraetepruefung.unterschreiben`, einem vollen Namen und einer
 * Prüfer-Qualifikation im Konto. Den Prüfnachweis eines bestandenen
 * Kundengeräts legt das Unterschreiben nur an, wenn der Unterzeichner
 * `kunden.nachweise` hat (sonst Hinweis; nachholbar auf der Seite der
 * Prüfung). Eine Korrektur ist gesiegelt ({@see korrektur()}). Kundenzugänge
 * nie (sie erreichen nur Prüfnachweise im Kundenbereich). Im Audit stehen
 * nur IDs und Nummern.
 *
 * Update: Unterschreiben wartet auf nichts außerhalb (kein Netz, kein Gerät)
 * – ein Stück aus höchstens 25 Prüfungen samt PDF ist in Sekunden fertig, weit
 * unter den 150 s, die ein Update auf angemeldete Anfragen wartet. Die
 * Schreibsperre fragt deshalb der Router (POST) und niemand hier noch einmal.
 */
final class PruefberichtService
{
    public const STATUS = [
        'gueltig'   => 'gültig',
        'korrektur' => 'Korrektur angefordert',
        'ersetzt'   => 'ersetzt',
    ];

    /** Kürzel der Nummern: GP-<Jahr>-<laufende Nummer, 5 Stellen>. */
    public const PRAEFIX = 'GP';

    /** Höchstens so viele Prüfungen je Anfrage (der Browser teilt größere Auswahlen). */
    public const JE_ANFRAGE = 25;

    /** Idempotenzschlüssel eines Durchgangs. */
    public const ANFRAGE = '/^[A-Za-z0-9-]{16,64}$/';

    public const NUR_PRUEFER = 'Unterschreiben darf nur, wer „Prüfberichte unterschreiben“ hat und im Konto einen vollen Namen und eine '
        . 'Prüfer-Qualifikation (Benutzerverwaltung).';

    /** HMAC-Schlüssel der Siegel (abgeleitet aus app.key) und Tags der Ketten. */
    private const SIEGEL_ZWECK = 'pruefbericht-siegel-1';
    private const KETTE_TAG = 'PM-GP-1';
    private const PDF_TAG = 'PM-GP-PDF-1';
    private const KORREKTUR_TAG = 'PM-GP-KORREKTUR-1';

    // ------------------------------------------------------------ Rechte

    /** Darf das angemeldete Konto Prüfungen und Berichte sehen? Kundenzugänge nie. */
    public static function sichtbar(): bool
    {
        return !Kundenbereich::istKundenzugang() && Auth::can('geraetepruefung.view');
    }

    /**
     * Darf es unterschreiben (Recht, hinterlegte Qualifikation und voller
     * Name im Konto)? Ohne vollen Namen stünde im Bericht ein frei
     * eingetippter – und ein Name wie der Prüfer laut Gerät übersprünge
     * „Verantwortung übernehmen“.
     */
    public static function darfUnterschreiben(): bool
    {
        return self::sichtbar() && Auth::can('geraetepruefung.unterschreiben') && self::qualifikation() !== '' && self::kontoName() !== '';
    }

    /** Der volle Name des angemeldeten Kontos (leer: keiner eingetragen). */
    private static function kontoName(): string
    {
        return Unterschrift::text(Auth::user()['full_name'] ?? '', 120);
    }

    /** Die Prüfer-Qualifikation des angemeldeten Kontos (leer: keine). */
    public static function qualifikation(): string
    {
        return Unterschrift::text(Auth::user()['pruefer_qualifikation'] ?? '', 120);
    }

    // ------------------------------------------------------------ Lesen

    /**
     * Ein Bericht mit dekodiertem Inhalt (`inhalt` als Array) – oder null.
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $b = $id > 0 ? Database::first('SELECT * FROM gp_berichte WHERE id = ?', [$id]) : null;
        return $b === null ? null : self::zeile($b);
    }

    /**
     * Alle Fassungen der Berichte einer Prüfung, die jüngste zuerst – mit der
     * Unterschrift (Name, Qualifikation, Ort, Zeit, Konto) und dem
     * Prüfnachweis, falls einer daraus entstand.
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerPruefung(int $pruefungId): array
    {
        $out = [];
        foreach (Database::select('SELECT b.*, u.name AS u_name, u.qualifikation AS u_qualifikation, u.ort AS u_ort,
                u.unterschrieben_am AS u_am, u.username AS u_username,
                n.id AS nachweis_id, n.kunde_id AS nachweis_kunde_id, n.status AS nachweis_status
            FROM gp_berichte b
            LEFT JOIN gp_unterschriften u ON u.id = b.unterschrift_id
            LEFT JOIN pruefnachweise n ON n.gp_bericht_id = b.id
            WHERE b.pruefung_id = ? ORDER BY b.fassung DESC, b.id DESC', [$pruefungId]) as $b) {
            $out[] = self::zeile($b);
        }
        return $out;
    }

    /**
     * Prüfungen für die Liste – wie {@see Pruefungen::liste()}, dazu je Zeile
     * `bericht`: die jüngste Fassung (id, nummer, fassung, status) oder null.
     *
     * Filter: `status` ('' alle | offen | unterschrieben), `ergebnis`,
     * `pruefer` (Name laut Gerät), `von`, `bis` (Ortsdatum), `besitzer`
     * ('eigen' oder Kunden-ID), `suche`, `limit` (höchstens 500).
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public static function liste(array $filter): array
    {
        $f = array_intersect_key($filter, array_flip(['ergebnis', 'pruefer', 'von', 'bis', 'besitzer', 'suche']));
        $status = (string) ($filter['status'] ?? '');
        if ($status === 'offen' || $status === 'unterschrieben') {
            $f['offen'] = $status === 'offen';
        }
        $f['limit'] = max(1, min(500, (int) ($filter['limit'] ?? 500)));
        $liste = Pruefungen::liste($f);
        $berichte = [];
        foreach (array_chunk(array_map(static fn (array $p): int => (int) $p['id'], $liste), 400) as $ids) {
            $zeilen = Database::select('SELECT id, pruefung_id, nummer, fassung, status FROM gp_berichte WHERE pruefung_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY fassung', $ids);
            foreach ($zeilen as $z) {
                // aufsteigend nach Fassung: die jüngste bleibt stehen
                $berichte[(int) $z['pruefung_id']] = ['id' => (int) $z['id'], 'nummer' => (string) $z['nummer'],
                    'fassung' => (int) $z['fassung'], 'status' => (string) $z['status']];
            }
        }
        foreach ($liste as &$p) {
            $p['bericht'] = $berichte[(int) $p['id']] ?? null;
        }
        unset($p);
        return $liste;
    }

    /** Namen der Prüfer laut Gerät (für den Filter). @return list<string> */
    public static function prueferNamen(): array
    {
        return array_map('strval', array_column(Database::select(
            "SELECT DISTINCT pruefer FROM gp_pruefungen WHERE pruefer <> '' ORDER BY pruefer LIMIT 200"), 'pruefer'));
    }

    /**
     * Alle Berichte (für die Kommandozeile): id, nummer, fassung, status –
     * ohne Inhalte.
     *
     * @return list<array<string,mixed>>
     */
    public static function alle(): array
    {
        return Database::select('SELECT id, nummer, fassung, status FROM gp_berichte ORDER BY jahr, laufnr, fassung, id');
    }

    /**
     * Ein Bericht über seine Nummer („GP-2026-00042“ oder
     * „GP-2026-00042-K1“); mit $fassung die Fassung zur Grundnummer.
     *
     * @return array<string,mixed>|null
     */
    public static function nachNummer(string $nummer, ?int $fassung = null): ?array
    {
        $nummer = trim($nummer);
        if ($fassung === null) {
            $id = Database::scalar('SELECT id FROM gp_berichte WHERE nummer = ? ORDER BY id DESC LIMIT 1', [$nummer]);
            return $id === false ? null : self::find((int) $id);
        }
        if (preg_match('/^' . self::PRAEFIX . '-(\d{4})-(\d+)(?:-K\d+)?$/', $nummer, $m) !== 1) {
            return null;
        }
        $id = Database::scalar('SELECT id FROM gp_berichte WHERE jahr = ? AND laufnr = ? AND fassung = ?', [(int) $m[1], (int) $m[2], $fassung]);
        return $id === false ? null : self::find((int) $id);
    }

    /**
     * Die gespeicherten Bytes des PDF – nur, wenn Prüfsumme und Größe zur
     * Zeile und zum Bericht passen; sonst null (dann zeigt
     * {@see integritaet()} den Befund).
     */
    public static function pdfGespeichert(int $berichtId): ?string
    {
        $z = Database::first('SELECT p.sha256, p.groesse, p.daten, b.pdf_hash FROM gp_bericht_pdf p JOIN gp_berichte b ON b.id = p.bericht_id
            WHERE p.bericht_id = ?', [$berichtId]);
        if ($z === null) {
            return null;
        }
        $bytes = base64_decode((string) $z['daten'], true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) !== (int) $z['groesse']) {
            return null;
        }
        $sha = hash('sha256', $bytes);
        return hash_equals((string) $z['sha256'], $sha) && hash_equals((string) $z['pdf_hash'], $sha) ? $bytes : null;
    }

    /** Kurzkennung des Siegel-Schlüssels (ändert sich mit app.key). */
    public static function schluesselId(): string
    {
        return substr(hash('sha256', self::siegelSchluessel()), 0, 8);
    }

    // ------------------------------------------------------------ Unterschreiben

    /**
     * Prüfungen unterschreiben – ein Stück eines Durchgangs.
     *
     * $u: `striche` (JSON {"s":…}), `name` (= voller Name des Kontos), `ort`,
     * `pruefart` (wiederholung | instandsetzung), `uebernehmen` (bool: auch
     * Prüfungen, die laut Gerät jemand anderes gemacht hat – der Bericht nennt
     * dann beide), `anfrage` (Kennung des Durchgangs).
     *
     * Erst wird geprüft, was für alle gilt (Recht, Qualifikation, Name, Ort,
     * Prüfart, Striche, Kennung) – scheitert das, ist nichts geschrieben.
     * Dann je Prüfung eine Transaktion; was dort scheitert, steht in `fehler`,
     * die anderen werden trotzdem unterschrieben. Schon mit derselben
     * Kennung unterschrieben zählt als Erfolg.
     *
     * @param list<mixed> $ids Prüfungen (höchstens 25)
     * @param array<string,mixed> $u
     * @return array{ok:bool,status:int,message:string,unterschrieben:list<int>,fehler:list<array{id:int,message:string}>,hinweise?:list<string>,berichte?:array<int,string>}
     */
    public static function unterschreiben(array $ids, array $u): array
    {
        $nein = static fn (int $status, string $text): array => ['ok' => false, 'status' => $status, 'message' => $text, 'unterschrieben' => [], 'fehler' => []];
        if (!self::darfUnterschreiben()) {
            return $nein(403, self::NUR_PRUEFER);
        }
        $liste = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $liste[$id] = true;
            } elseif (is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $liste[(int) $id] = true;
            } else {
                return $nein(422, 'Unbekannte Auswahl – bitte die Seite neu laden.');
            }
        }
        $liste = array_keys($liste);
        if ($liste === []) {
            return $nein(422, 'Bitte zuerst Prüfungen auswählen.');
        }
        if (count($liste) > self::JE_ANFRAGE) {
            return $nein(422, 'Höchstens ' . self::JE_ANFRAGE . ' Prüfungen je Anfrage – der Browser teilt größere Auswahlen selbst.');
        }
        $anfrage = (string) ($u['anfrage'] ?? '');
        if (preg_match(self::ANFRAGE, $anfrage) !== 1) {
            return $nein(422, 'Die Kennung der Unterschrift fehlt – bitte die Seite neu laden.');
        }
        $name = Unterschrift::text($u['name'] ?? '', 120);
        if (mb_strlen($name) < 2) {
            return $nein(422, 'Bitte den Namen in Druckbuchstaben eintragen.');
        }
        // Der Name gehört zum angemeldeten Konto (ohne vollen Namen dort kein
        // Unterschreiben, siehe darfUnterschreiben): wer am Gerät eines anderen
        // unterschreibt, schriebe sich sonst dessen Qualifikation zu. Im Bericht
        // steht er so, wie er im Konto steht.
        $konto = self::kontoName();
        if (self::nameVergleich($name) !== self::nameVergleich($konto)) {
            return $nein(422, 'Der Name passt nicht zum angemeldeten Konto („' . $konto . '“). Wer als Prüfer unterschreibt, meldet sich mit dem eigenen Konto an.');
        }
        $name = $konto;
        $ort = Unterschrift::text($u['ort'] ?? '', 120);
        if (mb_strlen($ort) < 2) {
            return $nein(422, 'Bitte den Ort der Unterschrift eintragen.');
        }
        $pruefart = (string) ($u['pruefart'] ?? '');
        if (!isset(Messarten::PRUEFARTEN[$pruefart])) {
            return $nein(422, 'Bitte die Prüfart wählen (Wiederholungsprüfung oder Prüfung nach Instandsetzung).');
        }
        $p = Unterschrift::pruefen((string) ($u['striche'] ?? ''));
        if (!$p['ok']) {
            return $nein(422, $p['message']);
        }
        $striche = $p['striche'];
        $hash = Kanon::hash(['s' => $striche]);
        $qualifikation = self::qualifikation();

        // Gibt es den Durchgang schon (ein weiteres Stück, ein Neuversuch)?
        // Dann müssen Konto, Striche, Name und Ort dieselben sein.
        $vorhanden = Database::first('SELECT * FROM gp_unterschriften WHERE anfrage = ?', [$anfrage]);
        if ($vorhanden !== null) {
            if ((int) $vorhanden['user_id'] !== Auth::id() || !hash_equals((string) $vorhanden['striche_hash'], $hash)
                || (string) $vorhanden['name'] !== $name || (string) $vorhanden['ort'] !== $ort) {
                return $nein(409, 'Diese Kennung gehört zu einer anderen Unterschrift – bitte noch einmal unterschreiben.');
            }
        } elseif (Unterschrift::stricheBekannt($hash)) {
            return $nein(422, 'Diese Unterschrift gibt es schon – bitte neu unterschreiben.');
        }

        $kontext = [
            'anfrage' => $anfrage, 'name' => $name, 'ort' => $ort, 'qualifikation' => $qualifikation, 'pruefart' => $pruefart,
            'uebernehmen' => ($u['uebernehmen'] ?? false) === true,
            'striche' => $striche, 'striche_hash' => $hash,
            'konto' => $konto,
        ];
        $unterschrieben = [];
        $fehler = [];
        $hinweise = [];
        $nummern = [];
        foreach ($liste as $id) {
            $r = self::einzeln($id, $kontext);
            if (($r['abbruch'] ?? false) === true) {
                // Die Striche gibt es inzwischen (gleichzeitiger zweiter Durchgang): für keine Prüfung mehr.
                return ['ok' => false, 'status' => 422, 'message' => (string) $r['message'], 'unterschrieben' => $unterschrieben,
                    'fehler' => $fehler, 'berichte' => $nummern];
            }
            if ($r['ok']) {
                $unterschrieben[] = $id;
                $nummern[$id] = (string) $r['nummer'];
                array_push($hinweise, ...($r['hinweise'] ?? []));
            } else {
                $fehler[] = ['id' => $id, 'message' => (string) $r['message']];
            }
        }
        $n = count($unterschrieben);
        $text = $n === 0 ? 'Nichts unterschrieben.'
            : ($n === 1 ? '1 Prüfbericht unterschrieben' : $n . ' Prüfberichte unterschrieben') . ' – eingefroren, das PDF ist gespeichert.';
        if ($fehler !== []) {
            $text .= ' ' . count($fehler) . ' nicht: siehe Liste.';
        }
        return ['ok' => $fehler === [], 'status' => 200, 'message' => $text, 'unterschrieben' => $unterschrieben, 'fehler' => $fehler,
            'hinweise' => array_values(array_unique($hinweise)), 'berichte' => $nummern];
    }

    /**
     * Eine Prüfung unterschreiben (eine Transaktion, bei Sperre oder Wettlauf
     * bis zu dreimal).
     *
     * @param array<string,mixed> $k Kontext des Durchgangs
     * @return array{ok:bool,message?:string,nummer?:string,hinweise?:list<string>,abbruch?:bool}
     */
    private static function einzeln(int $id, array $k): array
    {
        $pruefung = Pruefungen::find($id);
        if ($pruefung === null) {
            return ['ok' => false, 'message' => 'Diese Prüfung gibt es nicht.'];
        }
        $geraetPruefer = (string) $pruefung['pruefer'];
        $passt = $geraetPruefer !== '' && self::nameVergleich($geraetPruefer) === self::nameVergleich((string) $k['name']);
        if (!$passt && !$k['uebernehmen']) {
            return ['ok' => false, 'message' => $geraetPruefer !== ''
                ? 'Laut Prüfgerät hat „' . $geraetPruefer . '“ geprüft – unterschreiben nur mit „Verantwortung übernehmen“.'
                : 'Im Prüfgerät steht kein Prüfer – unterschreiben nur mit „Verantwortung übernehmen“.'];
        }
        for ($versuch = 1; ; $versuch++) {
            try {
                $erg = Database::transaktion(static fn (): array => self::einfrieren($id, $k, !$passt));
                break;
            } catch (\DomainException $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            } catch (\PDOException $e) {
                if ($versuch >= 3 || (!self::eindeutigVerletzt($e) && !self::besetzt($e))) {
                    throw $e;
                }
                if (self::besetzt($e)) {
                    usleep(150_000 * $versuch);
                    continue;
                }
                // UNIQUE: dieselben Striche aus einem zweiten Durchgang, dieselbe
                // Prüfung von einem zweiten Gerät oder dieselbe Nummer – nachsehen.
                $eigene = Database::first('SELECT id FROM gp_unterschriften WHERE anfrage = ?', [$k['anfrage']]);
                if ($eigene === null && Unterschrift::stricheBekannt((string) $k['striche_hash'])) {
                    return ['ok' => false, 'abbruch' => true, 'message' => 'Diese Unterschrift gibt es schon – bitte neu unterschreiben.'];
                }
            }
        }
        if (($erg['wiederholt'] ?? false) === true) {
            return ['ok' => true, 'nummer' => (string) $erg['nummer'], 'hinweise' => []];
        }
        $hinweise = $erg['hinweise'];
        Audit::log('geraetepruefung.unterschrieben', 'gp_bericht', (string) $erg['id'],
            ['bericht_id' => $erg['id'], 'pruefung_id' => $id, 'nummer' => $erg['nummer'], 'fassung' => $erg['fassung']]);
        // Ein bestandener Bericht eines Kundengeräts wird Prüfnachweis im
        // Kundenbereich – als Entwurf, sichtbar erst nach der Freigabe von Hand,
        // und nur, wenn der Unterzeichner Prüfnachweise geben darf (Daten eines
        // anderen Moduls nur mit dessen Recht, wie neue Kunden beim Import).
        // Nach dem Einfrieren: gelingt das nicht (Kunde inzwischen gelöscht …),
        // bleibt der Bericht, und der Nachweis lässt sich auf seiner Seite nachholen.
        if ($erg['kunde_id'] !== null && $erg['ergebnis'] === 'bestanden') {
            if (!Auth::can('kunden.nachweise')) {
                $hinweise[] = $erg['nummer'] . ': kein Prüfnachweis für den Kunden angelegt (dazu fehlt das Recht „Prüfnachweise“) – '
                    . 'nachholbar auf der Seite der Prüfung, von jemandem mit diesem Recht.';
            } else {
                $n = Pruefnachweise::ausGeraetepruefung((int) $erg['kunde_id'], (int) $erg['id']);
                if (!$n['ok']) {
                    $hinweise[] = $erg['nummer'] . ': kein Prüfnachweis für den Kunden – ' . $n['message'];
                }
            }
        }
        return ['ok' => true, 'nummer' => (string) $erg['nummer'], 'hinweise' => $hinweise];
    }

    /**
     * In der Transaktion: Fassung bestimmen, Nummer vergeben, Inhalt bauen,
     * Zeile, PDF, Kette und Siegel schreiben, eine angeforderte Korrektur
     * als ersetzt vermerken.
     *
     * @param array<string,mixed> $k
     * @return array<string,mixed>
     */
    private static function einfrieren(int $pruefungId, array $k, bool $uebernommen): array
    {
        // Frisch in der Transaktion gelesen: was zählt, ist der Stand jetzt.
        $p = Pruefungen::find($pruefungId);
        if ($p === null) {
            throw new \DomainException('Diese Prüfung gibt es nicht.');
        }
        $l = Prueflinge::find((int) $p['pruefling_id']);
        if ($l === null) {
            throw new \DomainException('Der Prüfling dieser Prüfung fehlt.');
        }
        $letzte = Database::first('SELECT * FROM gp_berichte WHERE pruefung_id = ? ORDER BY fassung DESC, id DESC LIMIT 1', [$pruefungId]);
        $eigene = Database::first('SELECT * FROM gp_unterschriften WHERE anfrage = ?', [$k['anfrage']]);
        if ($letzte !== null && (string) $letzte['status'] === 'gueltig') {
            if ($eigene !== null && (int) $letzte['unterschrift_id'] === (int) $eigene['id']) {
                return ['wiederholt' => true, 'nummer' => (string) $letzte['nummer']];
            }
            throw new \DomainException('Schon unterschrieben (' . $letzte['nummer'] . ').');
        }
        if ($letzte !== null && (string) $letzte['status'] !== 'korrektur') {
            throw new \DomainException('Die Berichte dieser Prüfung sind uneinheitlich (Fassung ' . (int) $letzte['fassung'] . ' ist „'
                . (self::STATUS[(string) $letzte['status']] ?? (string) $letzte['status']) . '“ ohne Nachfolger) – bitte prüfen lassen.');
        }
        if ($letzte !== null) {
            if (!self::korrekturPruefbar($letzte)) {
                throw new \DomainException('Die Korrektur von ' . $letzte['nummer'] . ' wurde unter einem früheren Schlüssel (app.key) angefordert und lässt sich '
                    . 'nicht mehr bestätigen – bitte die Korrektur neu anfordern.');
            }
            if (!self::korrekturGesiegelt($letzte)) {
                throw new \DomainException('Die Korrektur von ' . $letzte['nummer'] . ' ist nicht gesiegelt (Grund, Konto oder Zeit nachträglich geändert?) '
                    . '– bitte „bin/power geraetepruefung:pruefen“ ausführen lassen.');
            }
            // Ein Neuversuch eines älteren Durchgangs (dieselbe Kennung) deckt keine Fassung, die erst
            // nach seiner Unterschrift zur Korrektur ging – die hat der Prüfer nie gesehen.
            if ($eigene !== null && (Database::scalar('SELECT id FROM gp_berichte WHERE pruefung_id = ? AND unterschrift_id = ?', [$pruefungId, (int) $eigene['id']]) !== false
                || strcmp((string) $eigene['unterschrieben_am'], (string) $letzte['korrektur_am']) < 0)) {
                throw new \DomainException('Für ' . $letzte['nummer'] . ' ist inzwischen eine Korrektur angefordert – bitte neu unterschreiben.');
            }
        }
        $jetzt = gmdate('c');
        if ($eigene === null) {
            $eigeneId = Database::insert('gp_unterschriften', [
                'anfrage'           => $k['anfrage'],
                'user_id'           => (int) Auth::id(),
                'username'          => (string) (Auth::user()['username'] ?? ''),
                'name'              => $k['name'],
                'qualifikation'     => $k['qualifikation'],
                'ort'               => $k['ort'],
                'striche'           => Kanon::json(['s' => $k['striche']]),
                'striche_hash'      => $k['striche_hash'],
                'unterschrieben_am' => $jetzt,
                'created_at'        => $jetzt,
            ]);
            $eigene = Database::first('SELECT * FROM gp_unterschriften WHERE id = ?', [$eigeneId]);
        }
        if ($eigene === null) {
            throw new \LogicException('Unterschrift verschwunden.');
        }

        if ($letzte === null) {
            // Jahr aus dem Ortsdatum der Prüfung (eine Prüfung in der Silvesternacht zählt zum Jahr vor Ort).
            $fassung = 1;
            $jahr = (int) substr((string) Messarten::ortsdatum((string) $p['geprueft_am']), 0, 4);
            $jahr = $jahr > 0 ? $jahr : (int) gmdate('Y');
            [$nummer, $laufnr] = self::nummerVergeben($jahr);
            $vorgaenger = null;
            $grund = '';
        } else {
            $fassung = (int) $letzte['fassung'] + 1;
            $jahr = (int) $letzte['jahr'];
            $laufnr = (int) $letzte['laufnr'];
            $nummer = (string) preg_replace('/-K\d+$/', '', (string) $letzte['nummer']) . '-K' . ($fassung - 1);
            $vorgaenger = $letzte;
            $grund = Unterschrift::absatz((string) $letzte['korrektur_grund'], 500);
        }
        $kunde = $l['kunde_id'] !== null ? KundenService::find((int) $l['kunde_id']) : null;
        if ($l['kunde_id'] !== null && $kunde === null) {
            throw new \DomainException('Den Kunden dieses Prüflings gibt es nicht mehr.');
        }
        $inhalt = self::inhaltBauen($p, $l, $kunde, $k, $eigene, $uebernommen, $fassung, $nummer, $vorgaenger, $grund);
        $json = Kanon::json($inhalt);
        $inhaltHash = hash('sha256', $json);
        $zeile = [
            'pruefung_id'     => $pruefungId,
            'pruefling_id'    => (int) $l['id'],
            'kunde_id'        => $l['kunde_id'],
            'nummer'          => $nummer,
            'jahr'            => $jahr,
            'laufnr'          => $laufnr,
            'fassung'         => $fassung,
            'vorgaenger_id'   => $vorgaenger !== null ? (int) $vorgaenger['id'] : null,
            'grund'           => $grund,
            'status'          => 'gueltig',
            'ergebnis'        => (string) $inhalt['ergebnis'],
            'inhalt'          => $json,
            'inhalt_hash'     => $inhaltHash,
            'unterschrift_id' => (int) $eigene['id'],
            'pruefer_id'      => (int) Auth::id(),
            'kette_hash'      => '',
            'siegel'          => '',
            'schluessel_id'   => self::schluesselId(),
            'pdf_hash'        => '',
            'eingefroren_am'  => $jetzt,
            'created_at'      => $jetzt,
        ];
        $id = Database::insert('gp_berichte', $zeile);
        $zeile['id'] = $id;
        $kette = self::kette($zeile, $eigene);

        $bytes = PruefberichtPdf::erzeugen(['inhalt' => $inhalt] + $zeile, $k['striche']);
        $sha = hash('sha256', $bytes);
        Database::insert('gp_bericht_pdf', [
            'bericht_id'    => $id,
            'sha256'        => $sha,
            'groesse'       => strlen($bytes),
            'daten'         => base64_encode($bytes),
            'erzeuger'      => PruefberichtPdf::ERZEUGER,
            'siegel'        => self::pdfSiegel($id, $nummer, $fassung, $sha, PruefberichtPdf::ERZEUGER),
            'schluessel_id' => self::schluesselId(),
            'erzeugt_am'    => $jetzt,
        ]);
        Database::execute('UPDATE gp_berichte SET kette_hash = ?, siegel = ?, pdf_hash = ? WHERE id = ?',
            [$kette, hash_hmac('sha256', $kette, self::siegelSchluessel()), $sha, $id]);
        if ($vorgaenger !== null) {
            $n = Database::execute("UPDATE gp_berichte SET status = 'ersetzt', ersetzt_durch_id = ? WHERE id = ? AND status = 'korrektur'",
                [$id, (int) $vorgaenger['id']]);
            if ($n !== 1) {
                throw new \DomainException('Die Korrektur wurde inzwischen anders erledigt – bitte die Seite neu laden.');
            }
        }
        $hinweise = [];
        if ($vorgaenger !== null) {
            $r = Pruefnachweise::geraetepruefungErsetzt((int) $vorgaenger['id']);
            if ($r['freigegeben'] > 0) {
                $hinweise[] = $nummer . ': der Prüfnachweis der vorigen Fassung ist beim Kunden freigegeben und bleibt es – '
                    . 'bitte in der Kundenverwaltung zurückziehen, sobald der neue freigegeben ist.';
            }
        }
        return ['id' => $id, 'nummer' => $nummer, 'fassung' => $fassung, 'kunde_id' => $l['kunde_id'], 'ergebnis' => (string) $inhalt['ergebnis'],
            'hinweise' => $hinweise];
    }

    // ------------------------------------------------------------ Korrektur

    /**
     * Eine Korrektur anfordern: die gültige Fassung wird „Korrektur
     * angefordert“, die Prüfung ist wieder offen. Beim nächsten Unterschreiben
     * entsteht Fassung n+1 mit dem jetzigen Stand von Prüfling und Kunde und
     * diesem Grund; bis dahin gilt die bisherige Fassung (ihr PDF bleibt).
     *
     * @return array{ok:bool,status:int,message:string,pruefung_id?:int}
     */
    public static function korrektur(int $berichtId, string $grund): array
    {
        if (!self::darfUnterschreiben()) {
            return ['ok' => false, 'status' => 403, 'message' => self::NUR_PRUEFER];
        }
        $b = self::find($berichtId);
        if ($b === null) {
            return ['ok' => false, 'status' => 404, 'message' => 'Diesen Prüfbericht gibt es nicht.'];
        }
        // Eine Korrektur, die unter einem früheren Schlüssel (app.key) angefordert wurde, lässt sich nicht
        // mehr bestätigen – sie wird mit dem jetzigen Schlüssel neu angefordert.
        $neuAnfordern = $b['status'] === 'korrektur' && !self::korrekturPruefbar($b);
        if ($b['status'] !== 'gueltig' && !$neuAnfordern) {
            return ['ok' => false, 'status' => 409, 'pruefung_id' => (int) $b['pruefung_id'], 'message' => $b['status'] === 'korrektur'
                ? 'Für ' . $b['nummer'] . ' ist schon eine Korrektur angefordert.'
                : $b['nummer'] . ' ist schon ersetzt – korrigiert wird die jüngste Fassung.'];
        }
        $grund = Unterschrift::absatz($grund, 500);
        if (mb_strlen($grund) < 3) {
            return ['ok' => false, 'status' => 422, 'pruefung_id' => (int) $b['pruefung_id'], 'message' => 'Bitte den Grund der Korrektur angeben.'];
        }
        // Gesiegelt: eine per SQL gesetzte Korrektur (Status, Grund) fiele sonst nicht auf – und ihr
        // Grund stünde beim nächsten Unterschreiben eingefroren in Fassung n+1.
        $am = gmdate('c');
        $von = (int) Auth::id();
        $n = Database::execute("UPDATE gp_berichte SET status = 'korrektur', korrektur_grund = ?, korrektur_von = ?, korrektur_am = ?, korrektur_siegel = ?,
            korrektur_schluessel_id = ? WHERE id = ? AND status = ?", [$grund, $von, $am, self::korrekturSiegel($berichtId, (int) $b['fassung'], $grund, $von, $am),
            self::schluesselId(), $berichtId, (string) $b['status']]);
        if ($n !== 1) {
            return ['ok' => false, 'status' => 409, 'pruefung_id' => (int) $b['pruefung_id'], 'message' => 'Der Bericht wurde inzwischen geändert – bitte die Seite neu laden.'];
        }
        Audit::log('geraetepruefung.korrektur', 'gp_bericht', (string) $berichtId,
            ['bericht_id' => $berichtId, 'pruefung_id' => (int) $b['pruefung_id'], 'nummer' => $b['nummer'], 'fassung' => (int) $b['fassung']]);
        return ['ok' => true, 'status' => 200, 'pruefung_id' => (int) $b['pruefung_id'],
            'message' => 'Korrektur für ' . $b['nummer'] . ' angefordert – die Prüfung ist wieder offen. Beim nächsten Unterschreiben entsteht Fassung '
                . ((int) $b['fassung'] + 1) . '.'];
    }

    // ------------------------------------------------------------ Integrität

    /**
     * Einen Bericht nachrechnen: Status zu den Daten, Prüfsumme des Inhalts
     * (und Kopien in den Spalten), Vorgänger, Unterschrift (Striche, Kette,
     * Siegel), gespeichertes PDF (Prüfsumme, Größe, Siegel). Mit
     * $neuErzeugen wird das PDF neu erzeugt und Byte für Byte verglichen: bei
     * gleichem Erzeuger ist eine Abweichung ein Befund, bei einem älteren nur
     * ein Hinweis. Befunde nennen nie Kundendaten.
     *
     * @return array{ok:bool,befunde:list<string>,hinweise:list<string>}
     */
    public static function integritaet(int $id, bool $neuErzeugen = false): array
    {
        $b = Database::first('SELECT * FROM gp_berichte WHERE id = ?', [$id]);
        if ($b === null) {
            return ['ok' => false, 'befunde' => ['Diesen Prüfbericht gibt es nicht.'], 'hinweise' => []];
        }
        $befunde = [];
        $hinweise = [];
        $status = (string) $b['status'];

        // ---------------------------------------------- Status passt zu den Daten?
        $nachfolger = Database::select('SELECT id, vorgaenger_id, status FROM gp_berichte WHERE vorgaenger_id = ?', [$id]);
        if (!isset(self::STATUS[$status])) {
            $befunde[] = 'Unbekannter Status – nachträglich geändert?';
        } elseif ($status === 'ersetzt') {
            $nach = $b['ersetzt_durch_id'] !== null ? Database::first('SELECT id, vorgaenger_id FROM gp_berichte WHERE id = ?', [(int) $b['ersetzt_durch_id']]) : null;
            if ($nach === null || (int) $nach['vorgaenger_id'] !== $id) {
                $befunde[] = 'Als „ersetzt“ geführt, aber ohne die Fassung, die ihn ersetzt.';
            }
        } elseif ($nachfolger !== []) {
            $befunde[] = 'Eine neuere Fassung ersetzt diesen Bericht, er ist aber nicht als „ersetzt“ geführt – Status nachträglich geändert?';
        }
        if ($status === 'korrektur' || $b['korrektur_am'] !== null) {
            if (!self::korrekturPruefbar($b)) {
                $befunde[] = 'Korrektur mit anderem Schlüssel angefordert (' . preg_replace('/[^0-9a-f]/', '', (string) $b['korrektur_schluessel_id'])
                    . '), nicht prüfbar – bitte die Korrektur neu anfordern.';
            } elseif (!self::korrekturGesiegelt($b)) {
                $befunde[] = 'Die angeforderte Korrektur ist nicht gesiegelt oder verändert (Status, Grund, Konto oder Zeit) – nachträglich geändert?';
            }
        }

        // ---------------------------------------------- Inhalt
        try {
            $inhalt = json_decode((string) $b['inhalt'], true, 64, JSON_THROW_ON_ERROR);
            $hash = is_array($inhalt) ? Kanon::hash($inhalt) : '';
        } catch (\Throwable) {
            $inhalt = [];
            $hash = '';
        }
        $inhalt = is_array($inhalt) ? $inhalt : [];
        $inhaltHash = (string) $b['inhalt_hash'];
        if ($inhaltHash === '' || !hash_equals($inhaltHash, $hash) || !hash_equals($inhaltHash, hash('sha256', (string) $b['inhalt']))) {
            $befunde[] = 'Inhalt verändert – die Prüfsumme des eingefrorenen Inhalts stimmt nicht.';
        }
        if ((string) ($inhalt['nummer'] ?? '') !== (string) $b['nummer'] || (string) ($inhalt['fassung'] ?? '') !== (string) (int) $b['fassung']
            || (string) ($inhalt['ergebnis'] ?? '') !== (string) $b['ergebnis']) {
            $befunde[] = 'Nummer, Fassung oder Ergebnis in der Zeile weichen vom eingefrorenen Inhalt ab.';
        }
        if ((int) $b['fassung'] > 1) {
            $v = $b['vorgaenger_id'] !== null ? Database::first('SELECT id, inhalt_hash FROM gp_berichte WHERE id = ?', [(int) $b['vorgaenger_id']]) : null;
            if ($v === null) {
                $befunde[] = 'Der Vorgänger dieser Korrektur fehlt.';
            } elseif (!is_array($inhalt['vorgaenger'] ?? null) || (string) ($inhalt['vorgaenger']['inhalt_hash'] ?? '') !== (string) $v['inhalt_hash']) {
                $befunde[] = 'Der Verweis auf den Vorgänger im eingefrorenen Inhalt passt nicht zu dessen Prüfsumme.';
            }
        }

        // ---------------------------------------------- Unterschrift
        $s = null;
        $u = Database::first('SELECT * FROM gp_unterschriften WHERE id = ?', [(int) $b['unterschrift_id']]);
        if ($u === null) {
            $befunde[] = 'Die Unterschrift des Prüfers fehlt.';
        } else {
            try {
                $s = json_decode((string) $u['striche'], true, 8, JSON_THROW_ON_ERROR);
                $passt = is_array($s) && array_keys($s) === ['s'] && hash_equals((string) $u['striche_hash'], Kanon::hash(['s' => $s['s']]));
            } catch (\Throwable) {
                $passt = false;
            }
            if (!$passt || (string) ($inhalt['unterschrift']['striche_hash'] ?? '') !== (string) $u['striche_hash']) {
                $befunde[] = 'Unterschrift: die Striche wurden verändert.';
            }
            if (!hash_equals((string) $b['kette_hash'], self::kette($b, $u))) {
                $befunde[] = 'Angaben verändert (die Kette stimmt nicht).';
            }
            if ((string) $b['schluessel_id'] !== self::schluesselId()) {
                $befunde[] = 'Siegel mit anderem Schlüssel (' . preg_replace('/[^0-9a-f]/', '', (string) $b['schluessel_id']) . '), nicht prüfbar.';
            } elseif (!hash_equals(hash_hmac('sha256', (string) $b['kette_hash'], self::siegelSchluessel()), (string) $b['siegel'])) {
                $befunde[] = 'Siegel ungültig – Angaben nach der Unterschrift verändert.';
            }
        }

        // ---------------------------------------------- PDF
        $pdf = Database::first('SELECT * FROM gp_bericht_pdf WHERE bericht_id = ?', [$id]);
        if ($pdf === null) {
            $befunde[] = 'Das gespeicherte PDF fehlt.';
        } else {
            $bytes = base64_decode((string) $pdf['daten'], true);
            $sha = $bytes === false ? '' : hash('sha256', $bytes);
            if ($bytes === false || !hash_equals((string) $pdf['sha256'], $sha) || !hash_equals((string) $b['pdf_hash'], $sha) || strlen($bytes) !== (int) $pdf['groesse']) {
                $befunde[] = 'PDF verändert – die Prüfsumme der gespeicherten Datei stimmt nicht.';
            } else {
                $erzeuger = (string) $pdf['erzeuger'];
                $siegelOk = false;
                if ((string) $pdf['schluessel_id'] !== self::schluesselId()) {
                    $befunde[] = 'PDF: Siegel mit anderem Schlüssel (' . preg_replace('/[^0-9a-f]/', '', (string) $pdf['schluessel_id']) . '), nicht prüfbar.';
                } elseif (!hash_equals(self::pdfSiegel($id, (string) $b['nummer'], (int) $b['fassung'], $sha, $erzeuger), (string) $pdf['siegel'])) {
                    $befunde[] = 'PDF: Siegel ungültig – die Datei wurde nach der Unterschrift ausgetauscht.';
                } else {
                    $siegelOk = true;
                }
                if ($neuErzeugen) {
                    try {
                        $neu = PruefberichtPdf::erzeugen(['inhalt' => $inhalt] + $b, is_array($s['s'] ?? null) ? $s['s'] : []);
                    } catch (\Throwable) {
                        $neu = '';
                    }
                    if (!hash_equals($bytes, $neu)) {
                        if ($erzeuger === PruefberichtPdf::ERZEUGER) {
                            $befunde[] = 'PDF: neu erzeugt weicht es vom gespeicherten ab, obwohl der Erzeuger derselbe ist (' . $erzeuger
                                . ') – Inhalt, Unterschrift oder Datei verändert.';
                        } else {
                            $hinweise[] = 'Neu erzeugt weicht das PDF vom gespeicherten ab (erzeugt mit ' . preg_replace('/[^a-z0-9.-]/', '', $erzeuger)
                                . ', jetzt ' . PruefberichtPdf::ERZEUGER . ')' . ($siegelOk ? ' – maßgeblich ist das gespeicherte, sein Siegel stimmt.' : '.');
                        }
                    }
                }
            }
        }
        return ['ok' => $befunde === [], 'befunde' => $befunde, 'hinweise' => $hinweise];
    }

    // ------------------------------------------------------------ intern

    /**
     * Der eingefrorene Inhalt – nur Text (Kanon), mit festen Kopien von
     * allem, was sich später ändern kann: Firma, Kunde, Prüfling,
     * Prüfgerät mit Kalibrierung, Zubehör, Prüfer. Messwerte, Grenzwerte
     * und Bewertung stammen unverändert aus der Prüfung. Kalibrierung und
     * Zubehör tragen ihre Quelle („laut Verwaltung, Stand <Datum der
     * Unterschrift>“ – „Prüfgeräte“ führt keine Geschichte); wurde ein Gerät
     * nach dem Prüftag geändert (`updated_at`), steht ein Hinweis da.
     *
     * @param array<string,mixed> $p Prüfung (Pruefungen::find)
     * @param array<string,mixed> $l Prüfling
     * @param array<string,mixed>|null $kunde
     * @param array<string,mixed> $k Kontext des Durchgangs
     * @param array<string,mixed> $u Zeile aus gp_unterschriften
     * @param array<string,mixed>|null $vorgaenger
     * @return array<string,mixed>
     */
    private static function inhaltBauen(array $p, array $l, ?array $kunde, array $k, array $u, bool $uebernommen, int $fassung,
        string $nummer, ?array $vorgaenger, string $grund): array
    {
        $t = static fn (mixed $w, int $n = 500): string => Unterschrift::text(is_scalar($w) ? (string) $w : '', $n);
        $d = is_array($p['daten'] ?? null) ? $p['daten'] : [];
        $datum = (string) Messarten::ortsdatum((string) $p['geprueft_am']);
        $hinweise = [];

        // Prüfablauf: Norm von Hand und Zubehör (z. B. Messadapter)
        $ablauf = (string) $p['ablauf_geraet_id'] !== ''
            ? Database::first('SELECT norm, zubehoer FROM gp_pruefablaeufe WHERE geraet_id = ?', [(string) $p['ablauf_geraet_id']]) : null;
        $norm = trim((string) ($ablauf['norm'] ?? ''));
        if ($norm === '') {
            $norm = Messarten::norm((string) $p['ablauf_name'], (string) $k['pruefart']);
        }
        // Kalibrierung und Zubehör kennt nur die Verwaltung („Prüfgeräte“), und die hält nur den Stand
        // jetzt (keine Geschichte): eingefroren wird der Stand bei der Unterschrift, so gekennzeichnet,
        // und wurde ein Gerät nach dem Prüftag geändert, steht ein Hinweis da.
        $stand = 'laut Verwaltung, Stand ' . self::datumText((string) Messarten::ortsdatum((string) $u['unterschrieben_am']));
        $geprueftTs = Zeit::ts((string) $p['geprueft_am']);
        $spaeterGeaendert = static fn (array $g): bool => $geprueftTs !== null && ($ts = Zeit::ts((string) ($g['updated_at'] ?? ''))) !== null && $ts > $geprueftTs;
        $zubehoer = [];
        $zIds = is_string($ablauf['zubehoer'] ?? null) ? json_decode((string) $ablauf['zubehoer'], true) : null;
        foreach (is_array($zIds) ? array_slice($zIds, 0, 20) : [] as $zid) {
            $g = is_int($zid) || (is_string($zid) && ctype_digit($zid)) ? Database::first('SELECT * FROM pruefgeraete WHERE id = ?', [(int) $zid]) : null;
            if ($g !== null) {
                $zubehoer[] = self::geraetAbbild($g);
                if ($spaeterGeaendert($g)) {
                    $hinweise[] = 'Die Angaben zum Zubehör „' . $t(trim((string) $g['fabrikat'] . ' ' . (string) $g['typ']), 160) . '“ wurden nach dem Prüftag geändert'
                        . ' – sein Stand am Prüftag (Kalibrierung) ist damit nicht belegt.';
                }
            }
        }

        // Prüfgerät: Angaben aus der Prüfung, Kalibrierung aus „Prüfgeräte“ (gleiche Seriennummer)
        $sn = (string) $p['messgeraet_sn'];
        $pg = $sn !== '' ? Database::first('SELECT * FROM pruefgeraete WHERE seriennummer = ? ORDER BY aktiv DESC, id DESC LIMIT 1', [$sn]) : null;
        $kalibriert = $pg !== null ? (string) ($pg['kalibriert_bis'] ?? '') : '';
        if ($pg === null) {
            $hinweise[] = 'Das Prüfgerät ist unter „Prüfgeräte“ nicht eingetragen – Kalibrierung nicht bekannt.';
        } elseif ($kalibriert === '') {
            $hinweise[] = 'Für das Prüfgerät ist keine Kalibrierung eingetragen.';
        } elseif ($kalibriert < $datum) {
            $hinweise[] = 'Die Kalibrierung des Prüfgeräts war am Prüftag abgelaufen (bis ' . self::datumText($kalibriert) . ').';
        }
        if ($pg !== null && $spaeterGeaendert($pg)) {
            $hinweise[] = 'Die Angaben zum Prüfgerät wurden nach dem Prüftag geändert – die Kalibrierung am Prüftag ist damit nicht belegt'
                . ' (der Bericht nennt den Stand bei der Unterschrift).';
        }

        // Sichtprüfung und Messschritte – unverändert aus der Prüfung, nur als Text
        $antworten = [];
        foreach (is_array($d['sicht_antworten'] ?? null) ? $d['sicht_antworten'] : [] as $a) {
            if (is_array($a)) {
                $antworten[] = ['frage' => $t($a['frage'] ?? '', 20), 'text' => $t($a['text'] ?? ''),
                    'bewertung' => ($a['antwort'] ?? '') === 'ok' ? 'ok' : 'nicht_ok'];
            }
        }
        $schritte = [];
        foreach (is_array($d['schritte'] ?? null) ? $d['schritte'] : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $werte = [];
            foreach (is_array($s['werte'] ?? null) ? $s['werte'] : [] as $w) {
                if (is_array($w)) {
                    $werte[] = ['name' => $t($w['name'] ?? '', 120), 'wert' => $t($w['wert'] ?? '', 120), 'bewertung' => ($w['gueltig'] ?? false) === true ? 'ok' : 'nicht_ok'];
                }
            }
            $grenzen = [];
            foreach (is_array($s['grenzwerte'] ?? null) ? $s['grenzwerte'] : [] as $g) {
                if (is_array($g)) {
                    $grenzen[] = ['name' => $t($g['name'] ?? '', 120), 'wert' => $t($g['wert'] ?? '', 120)];
                }
            }
            $art = $t($s['art'] ?? '', 40);
            $schritte[] = ['art' => $art, 'titel' => $t(($s['titel'] ?? '') !== '' ? $s['titel'] : Messarten::titel($art), 120),
                'bewertung' => ($s['ok'] ?? false) === true ? 'ok' : 'nicht_ok', 'werte' => $werte, 'grenzwerte' => $grenzen];
        }

        $ergebnis = (string) $p['ergebnis'] === 'bestanden' ? 'bestanden' : 'nicht_bestanden';
        $intervall = (int) $l['intervall_monate'];
        if ($ergebnis !== 'bestanden') {
            [$naechste, $naechsteText] = ['', 'vor Wiederinbetriebnahme (nicht bestanden)'];
        } elseif ($intervall > 0 && $datum !== '') {
            $naechste = Messarten::monatePlus($datum, $intervall);
            $naechsteText = self::datumText($naechste) . ' (Prüffrist ' . $intervall . ($intervall === 1 ? ' Monat)' : ' Monate)');
        } else {
            [$naechste, $naechsteText] = ['', 'keine Prüffrist eingetragen'];
        }

        $inventar = $l['inventar_id'] !== null ? Database::scalar('SELECT kennung FROM inventar WHERE id = ?', [(int) $l['inventar_id']]) : false;
        $firma = [];
        foreach (['name', 'zusatz', 'anschrift', 'kontakt', 'bank', 'register'] as $f) {
            $firma[$f] = Unterschrift::absatz(Einstellungen::wert('firma.' . $f), 500);
        }
        $auftraggeber = $kunde === null
            ? ['art' => 'eigen', 'name' => 'Eigener Bestand', 'kundennummer' => '', 'anschrift' => '']
            : ['art' => 'kunde', 'name' => $t($kunde['name'] ?? '', 255), 'kundennummer' => $t($kunde['kundennummer'] ?? '', 64),
                'anschrift' => implode("\n", array_values(array_filter([$t($kunde['strasse'] ?? '', 120),
                    trim($t($kunde['plz'] ?? '', 16) . ' ' . $t($kunde['ort'] ?? '', 120))], static fn (string $z): bool => $z !== '')))];

        $i = [
            'art'           => 'geraetepruefung',
            'nummer'        => $nummer,
            'fassung'       => (string) $fassung,
            'auftragnehmer' => $firma,
            'auftraggeber'  => $auftraggeber,
            'pruefling'     => [
                'kennung'        => $t($l['kennung'], 50),
                'bezeichnung'    => $t($l['bezeichnung'], 120),
                'hersteller'     => $t($l['hersteller'], 80),
                'typ'            => $t($l['typ'], 80),
                'modell'         => $t($l['modell'], 80),
                'seriennummer'   => $t($l['seriennummer'], 80),
                'abteilung'      => $t($l['abteilung'], 80),
                'gebaeude'       => $t($l['gebaeude'], 80),
                'schutzklasse'   => $t($l['schutzklasse'], 4),
                'leitungslaenge' => $t($l['leitungslaenge'], 16),
                'querschnitt'    => $t($l['querschnitt'], 16),
                'leiter'         => $t($l['leiter'], 8),
                'nennleistung'   => $t($l['nennleistung'], 16),
                'intervall'      => (string) $intervall,
                'inventar'       => $inventar !== false && $inventar !== null ? $t($inventar, 80) : '',
            ],
            'pruefung'      => [
                'datum'           => $datum,
                'uhrzeit'         => (string) Messarten::ortszeit((string) $p['geprueft_am'], 'H:i'),
                'am_geraet'       => $t($p['am_geraet'], 40),
                'pruefart'        => (string) $k['pruefart'],
                'pruefart_text'   => Messarten::PRUEFARTEN[(string) $k['pruefart']],
                'norm'            => $t($norm, 160),
                'ablauf_name'     => $t($p['ablauf_name'], 120),
                'auftrag'         => $t($p['auftrag'], 80),
                'ort'             => $t($p['ort'], 120),
                'bemerkung'       => Unterschrift::absatz((string) $p['bemerkung'], 500),
                'pruefer_geraet'  => $t($p['pruefer'], 80),
                'prueffirma'      => $t($p['prueffirma'], 120),
            ],
            'pruefgeraet'   => [
                'bezeichnung'    => $t($p['messgeraet'], 80),
                'fabrikat'       => $pg !== null ? $t($pg['fabrikat'], 80) : '',
                'typ'            => $pg !== null ? $t($pg['typ'], 80) : '',
                'seriennummer'   => $t($sn, 80),
                'firmware'       => $t($p['firmware'], 40),
                'norm'           => $pg !== null ? $t($pg['norm'], 160) : '',
                'kalibriert_bis' => $kalibriert,
                'kalibrierung_quelle' => $pg !== null ? $stand : '',
            ],
            'zubehoer'      => $zubehoer,
            'zubehoer_quelle' => $zubehoer !== [] ? $stand : '',
            'sicht'         => ['ergebnis' => (string) $p['sicht'], 'antworten' => $antworten],
            'schritte'      => $schritte,
            'ergebnis'      => $ergebnis,
            'naechste_pruefung'      => $naechste,
            'naechste_pruefung_text' => $naechsteText,
            'pruefer'       => [
                'name'                      => (string) $u['name'],
                'qualifikation'             => (string) $u['qualifikation'],
                'konto'                     => $t($k['konto'], 190),
                'user_id'                   => (string) (int) $u['user_id'],
                'verantwortlich_uebernommen' => $uebernommen ? 'ja' : 'nein',
            ],
            'unterschrift'  => [
                'striche_hash' => (string) $u['striche_hash'],
                'name'         => (string) $u['name'],
                'ort'          => (string) $u['ort'],
                'datum'        => (string) Messarten::ortsdatum((string) $u['unterschrieben_am']),
            ],
            'hinweise'      => $hinweise,
        ];
        if ($vorgaenger !== null) {
            $i['vorgaenger'] = ['nummer' => (string) $vorgaenger['nummer'], 'fassung' => (string) (int) $vorgaenger['fassung'],
                'inhalt_hash' => (string) $vorgaenger['inhalt_hash'], 'grund' => $grund];
        }
        return $i;
    }

    /** @param array<string,mixed> $g Zeile aus pruefgeraete @return array<string,string> */
    private static function geraetAbbild(array $g): array
    {
        return [
            'fabrikat'       => Unterschrift::text((string) ($g['fabrikat'] ?? ''), 80),
            'typ'            => Unterschrift::text((string) ($g['typ'] ?? ''), 80),
            'seriennummer'   => Unterschrift::text((string) ($g['seriennummer'] ?? ''), 80),
            'kalibriert_bis' => Unterschrift::text((string) ($g['kalibriert_bis'] ?? ''), 10),
        ];
    }

    /**
     * Nummer und laufende Nummer eines Jahres: <GP>-<Jahr>-<laufend>. Die
     * laufende Nummer zählt über gp_bericht_nummern weiter – eine einmal
     * vergebene kommt nie wieder, auch nicht nach dem Löschen. Unter SQLite
     * schließt BEGIN IMMEDIATE den Wettlauf aus, unter MySQL endet er am
     * eindeutigen Index (jahr, laufnr, fassung) bzw. am Primärschlüssel des
     * Zählers und wird wiederholt.
     *
     * @return array{0:string,1:int}
     */
    private static function nummerVergeben(int $jahr): array
    {
        $zaehler = Database::scalar('SELECT laufnr FROM gp_bericht_nummern WHERE jahr = ?', [$jahr]);
        $laufnr = max((int) Database::scalar('SELECT COALESCE(MAX(laufnr), 0) FROM gp_berichte WHERE jahr = ?', [$jahr]), (int) ($zaehler ?: 0)) + 1;
        if ($zaehler === false) {
            Database::insert('gp_bericht_nummern', ['jahr' => $jahr, 'laufnr' => $laufnr]);
        } else {
            Database::execute('UPDATE gp_bericht_nummern SET laufnr = ? WHERE jahr = ?', [$laufnr, $jahr]);
        }
        return [sprintf('%s-%d-%05d', self::PRAEFIX, $jahr, $laufnr), $laufnr];
    }

    /**
     * Die Kette eines Berichts – aus den Zeilen, wie sie in der Datenbank
     * stehen: Bericht (Nummer, Fassung, Vorgänger, Inhalt, Ergebnis, Konto,
     * Zeit) und Unterschrift (Kennung, Konto, Name, Qualifikation, Ort,
     * Striche, Zeit).
     *
     * @param array<string,mixed> $b
     * @param array<string,mixed> $u
     */
    private static function kette(array $b, array $u): string
    {
        $n = static fn (mixed $w): string => $w === null || $w === '' ? '-' : (string) $w;
        return hash('sha256', implode("\n", [
            self::KETTE_TAG, (string) (int) $b['id'], (string) (int) $b['pruefung_id'], (string) (int) $b['pruefling_id'], $n($b['kunde_id']),
            (string) $b['nummer'], (string) (int) $b['jahr'], (string) (int) $b['laufnr'], (string) (int) $b['fassung'], $n($b['vorgaenger_id']),
            $n($b['grund']), (string) $b['ergebnis'], (string) $b['inhalt_hash'], (string) (int) $b['pruefer_id'], (string) $b['eingefroren_am'],
            (string) (int) $u['id'], (string) $u['anfrage'], (string) (int) $u['user_id'], (string) $u['name'], $n($u['qualifikation']),
            (string) $u['ort'], (string) $u['striche_hash'], (string) $u['unterschrieben_am'],
        ]));
    }

    /** Das Siegel des gespeicherten PDF (HMAC über Bericht, Nummer, Fassung, Prüfsumme, Erzeuger). */
    private static function pdfSiegel(int $id, string $nummer, int $fassung, string $sha, string $erzeuger): string
    {
        return hash_hmac('sha256', implode("\n", [self::PDF_TAG, (string) $id, $nummer, (string) $fassung, $sha, $erzeuger]), self::siegelSchluessel());
    }

    private static function siegelSchluessel(): string
    {
        return Crypto::ableiten(self::SIEGEL_ZWECK);
    }

    /** Siegel einer angeforderten Korrektur (HMAC über Bericht, Fassung, Grund, Konto, Zeit). */
    private static function korrekturSiegel(int $id, int $fassung, string $grund, int $von, string $am): string
    {
        return hash_hmac('sha256', implode("\n", [self::KORREKTUR_TAG, (string) $id, (string) $fassung, $grund, (string) $von, $am]),
            self::siegelSchluessel());
    }

    /**
     * Lässt sich das Siegel der Korrektur mit dem jetzigen Schlüssel prüfen? Nur eine Kennung eines
     * anderen Schlüssels (Wechsel von app.key) macht es unprüfbar; ohne Kennung (per SQL gesetzt)
     * wird geprüft – und scheitert als „nicht gesiegelt“.
     */
    private static function korrekturPruefbar(array $b): bool
    {
        $id = (string) ($b['korrektur_schluessel_id'] ?? '');
        return $id === '' || hash_equals(self::schluesselId(), $id);
    }

    /** Ist die Korrektur dieser Zeile vollständig (Grund, Konto, Zeit) und gesiegelt? */
    private static function korrekturGesiegelt(array $b): bool
    {
        $am = (string) ($b['korrektur_am'] ?? '');
        $grund = (string) ($b['korrektur_grund'] ?? '');
        if ($am === '' || $b['korrektur_von'] === null || trim($grund) === '') {
            return false;
        }
        return hash_equals(self::korrekturSiegel((int) $b['id'], (int) $b['fassung'], $grund, (int) $b['korrektur_von'], $am),
            (string) ($b['korrektur_siegel'] ?? ''));
    }

    /** Name zum Vergleich: Groß/klein, Leerzeichen und Umlaut-Schreibweise egal (wie bei den Übergabeberichten). */
    private static function nameVergleich(string $name): string
    {
        $n = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        return strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    private static function eindeutigVerletzt(\PDOException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }

    /** Gerade gesperrt (SQLite BUSY/LOCKED, MySQL Lock-Timeout/Deadlock)? Dann lohnt ein zweiter Versuch. */
    private static function besetzt(\PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return Database::driver() === 'sqlite' ? in_array($code, [5, 6], true) || str_contains($e->getMessage(), 'database is locked')
            : in_array($code, [1205, 1213], true);
    }

    /**
     * JJJJ-MM-TT → TT.MM.JJJJ (sonst unverändert) – Teil des Formats des
     * eingefrorenen Inhalts („nächste Prüfung“, Hinweise). Bewusst eigene
     * Hilfe statt der Anzeigehilfe Pruefnachweise::datum(): Änderte sich die,
     * änderten sich neue Berichte still mit.
     */
    private static function datumText(string $d): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
    }

    /**
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private static function zeile(array $b): array
    {
        foreach (['id', 'pruefung_id', 'pruefling_id', 'jahr', 'laufnr', 'fassung', 'unterschrift_id', 'pruefer_id'] as $f) {
            $b[$f] = (int) $b[$f];
        }
        foreach (['kunde_id', 'vorgaenger_id', 'ersetzt_durch_id', 'korrektur_von', 'nachweis_id', 'nachweis_kunde_id'] as $f) {
            if (array_key_exists($f, $b)) {
                $b[$f] = $b[$f] === null ? null : (int) $b[$f];
            }
        }
        $inhalt = json_decode((string) $b['inhalt'], true);
        $b['inhalt'] = is_array($inhalt) ? $inhalt : [];
        return $b;
    }
}
