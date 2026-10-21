<?php
declare(strict_types=1);

namespace App\Services;

use App\Messung\Http;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Logger;
use App\Support\Settings;
use App\Support\Zeit;

/**
 * Eventworx – Kundendaten für Übergabeberichte, **nur lesend**.
 *
 * Gelesen werden Aufträge, Angebote und Lieferscheine (`GET job`, Suche) und
 * ein einzelner Vorgang (`GET job/<id>`); daraus kommen Veranstaltung, Kunde,
 * Ansprechpartner, Ort und Zeitraum. Für die Prüfnachweise des
 * Kundenbereichs dazu die Liste der Dateien an einem Vorgang (`GET
 * file/list`) und einzelne Dateien daraus (`GET file/<ObjectId>`). Sonst
 * nichts:
 *
 * - **nur GET und nur die Pfade aus PFADE.** Das API-Token hat in Eventworx
 *   weitreichende Rechte, und Eventworx löscht Dateien per GET
 *   (`file/delete/…`) – ein Pfad außerhalb der Liste wird abgelehnt, bevor
 *   irgendetwas ins Netz geht. Dateien nur mit einer ObjectId, die
 *   `dokumente()` in diesem Prozess aus der Liste eben dieses Vorgangs
 *   gelesen hat; eine Liste, in der auch nur eine Datei eines anderen
 *   Vorgangs steht, wird ganz verworfen (Eventworx übergeht unbekannte
 *   Filter still).
 * - **Das Token gilt für seinen Host.** Es wird verschlüsselt abgelegt und
 *   zusammen mit dem Host gemerkt, für den es eingetragen wurde
 *   (`eventworx.token_host`). Zieht die Adresse um, ohne dass ein neues Token
 *   kommt, wird es entfernt; eine Anfrage an einen anderen Host als diesen
 *   geht nie hinaus. Umleitungen verfolgt `Http` nicht.
 * - **Datenminimierung.** Positionen, Beträge, Notizen, Rechnungsseite und
 *   Bankdaten verlassen diese Klasse nie; `abbilden()` gibt nur Klartext für
 *   die Felder des Berichts heraus.
 * - **Nichts von Eventworx ins Log.** Protokolliert wird je Anfrage das
 *   Pfadmuster (`job/<id>`, nicht die ID), Status, Fehlercode und Dauer –
 *   kein Suchtext, kein Rumpf, kein Token.
 * - **Höchstens zwei Anfragen je Sekunde** über alle PHP-Prozesse
 *   (`takt()`), kein Neuversuch – auch nicht bei 429.
 *
 * Abgeleitet aus dem Lese-Client der SetnetzBuchhaltung (`eventworx.py`);
 * deren Erfahrungen mit dem echten Mandanten stecken in `einordnen()`
 * (200 mit `success:false`, 200 mit `data:null`, HTML statt JSON).
 */
final class Eventworx
{
    /** Ein Mandant: genau ein Name unter eventworx.biz, nur https, Port 443. */
    public const HOST = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.eventworx\.biz$/';
    /**
     * Die einzigen Pfade unter /backend/ – und nur mit GET. Dateien nur mit
     * ObjectId: `file/delete/<id>` (löscht per GET), `file/download` und
     * Ähnliches passen nie.
     */
    private const PFADE = '#^(check/ping|job|job/[A-Za-z0-9]{8,40}|file/list|file/[0-9a-f]{24})$#';
    private const ID = '/^[A-Za-z0-9]{8,40}$/';
    /** Eine ObjectId (24 Hexzeichen, klein) – so nennt Eventworx Kunden (customerRefId) und Dateien. */
    public const OID = '/^[0-9a-f]{24}$/';
    private const ARTEN_SUCHE = ['order', 'offer'];
    private const ARTEN_VORGANG = ['order', 'offer', 'deliverynote'];
    /** Größte Datei, die übernommen wird (wie ein Prüfnachweis von Hand; MariaDB: max_allowed_packet 16 MiB). */
    public const MAX_DATEI = 10 * 1024 * 1024;
    /** Ordner der Dokumente am Vorgang; Uploads von Benutzern liegen darunter (/job/documents/<Projekt>). */
    private const DATEI_PFAD = '/job/documents';
    /**
     * Filterform für file/list – nach der Lese-Probe (P5a/P5b, docs/eventworx.md)
     * festlegen. true: {path, ownerDocumentId} wie die SetnetzBuchhaltung;
     * false: nur ownerDocumentId. Beide sind sicher, weil jede Liste hier
     * gegen den Vorgang geprüft wird – offen ist nur, ob der Filter mit
     * path die Uploads in Unterordnern liefert. VERIFY (Probe V3)
     */
    private const DATEI_FILTER_MIT_PFAD = true;
    private const DATEIEN_JE_LAUF = 200;
    /** Sekunden für einen Download (bis 10 MB). */
    private const ZEIT_DATEI = 30;
    public const ART_NAMEN = ['order' => 'Auftrag', 'offer' => 'Angebot', 'deliverynote' => 'Lieferschein'];
    /** Nur vollständige Entitäten – „&amp“ ohne Semikolon bleibt, wie es ist. */
    private const ENTITAET = '/&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/';
    private const FEHLERCODE = '/^[A-Z][A-Z0-9_]{0,63}$/';
    /** Steuerzeichen und Richtungszeichen (die einen Namen im PDF umdrehen könnten). */
    private const STEUERZEICHEN = '/[\p{Cc}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u';
    /** Sekunden zwischen zwei Anfragen. */
    private const TAKT = 0.5;
    /** Wer länger warten müsste, bekommt eine Meldung statt einer hängenden Seite. */
    private const HOECHSTENS_WARTEN = 2.0;
    private const ZEIT = 12;

    public const EINSTELLUNG_HOST = 'eventworx.host';
    public const EINSTELLUNG_TOKEN_HOST = 'eventworx.token_host';

    private const NICHT_EINGERICHTET = 'Eventworx ist nicht eingerichtet (Systemeinstellungen).';
    private const ADRESSE_HINWEIS = 'Erlaubt ist nur die Adresse des eigenen Eventworx-Mandanten: „firma“, „firma.eventworx.biz“ oder https://firma.eventworx.biz.';
    private const UNERWARTET = 'Eventworx: unerwartete Antwort.';
    private const NICHT_VORHANDEN = 'Vorgang in Eventworx nicht (mehr) vorhanden.';
    private const DATEI_ABGELEHNT = 'Eventworx lehnt den Dateizugriff ab – die Dateischnittstelle muss evtl. für das Token freigeschaltet werden.';

    /** Für Prüfskripte: Uhr (Sekunden als float) und Schlafen ersetzen. */
    public static ?\Closure $uhr = null;
    public static ?\Closure $schlafen = null;

    /**
     * Was dokumente() in DIESEM Prozess gelesen hat: Datei-ID → Vorgang und
     * Name. Nur diese Dateien lädt datei() – eine ID aus einer Anfrage, einer
     * Sitzung oder einer anderen Liste nie.
     *
     * @var array<string,array{job:string,name:string,roh:string}>
     */
    private static array $gelesen = [];

    // ------------------------------------------------------------ Adresse und Token

    /**
     * „firma“, „firma.eventworx.biz“ oder ein eingefügter Link aus der Web-App
     * → „firma.eventworx.biz“. Sonst EventworxFehler.
     *
     * Eine abweichende Basis (Testserver, eigene Installation) gilt nur, wenn
     * die Eingabe genau `eventworx.basis` aus config/config.php ist – nie aus
     * der Oberfläche allein. Dann ist der Host dessen Name samt Port.
     */
    public static function host(string $eingabe): string
    {
        $roh = trim($eingabe);
        if ($roh === '') {
            throw new EventworxFehler('Bitte die Eventworx-Adresse angeben („firma“ oder „firma.eventworx.biz“).');
        }
        $extra = self::extraBasis();
        if ($extra !== '' && rtrim($roh, '/') === $extra && self::autoritaet($extra) !== '') {
            return self::autoritaet($extra);
        }
        if (preg_match('/[\x00-\x20\x7f]/', $roh) === 1) {
            throw new EventworxFehler(self::ADRESSE_HINWEIS);
        }
        if (!str_contains($roh, '://')) {
            $erstes = explode('/', $roh, 2)[0];
            $roh = 'https://' . (str_contains($erstes, '.') ? $roh : $erstes . '.eventworx.biz');
        }
        $teile = parse_url($roh);
        if (!is_array($teile)) {
            throw new EventworxFehler(self::ADRESSE_HINWEIS);
        }
        $host = strtolower((string) ($teile['host'] ?? ''));
        $port = $teile['port'] ?? null;
        if (strtolower((string) ($teile['scheme'] ?? '')) !== 'https' || isset($teile['user']) || isset($teile['pass'])
            || ($port !== null && (int) $port !== 443) || preg_match(self::HOST, $host) !== 1) {
            throw new EventworxFehler(self::ADRESSE_HINWEIS);
        }
        return $host;
    }

    /** https://<host> – oder `eventworx.basis`, wenn der Host genau dazu gehört. '' = keine gültige Adresse. */
    public static function basis(): string
    {
        $host = (string) Settings::get(self::EINSTELLUNG_HOST, '');
        if ($host === '') {
            return '';
        }
        $extra = self::extraBasis();
        if ($extra !== '' && self::autoritaet($extra) === $host) {
            return $extra;
        }
        return preg_match(self::HOST, $host) === 1 ? 'https://' . $host : '';
    }

    /** Adresse gültig, Token hinterlegt und für genau diesen Host eingetragen (Demo: immer). */
    public static function eingerichtet(): bool
    {
        if (Config::isDemo()) {
            return true;
        }
        $host = (string) Settings::get(self::EINSTELLUNG_HOST, '');
        return self::basis() !== ''
            && Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN)
            && (string) Settings::get(self::EINSTELLUNG_TOKEN_HOST, '') === $host;
    }

    /**
     * Für die Einstellungen: was eingetragen ist – ohne das Token.
     *
     * @return array{host:string,adresse:string,token:bool,token_host:string,eingerichtet:bool,demo:bool}
     */
    public static function zustand(): array
    {
        $host = (string) Settings::get(self::EINSTELLUNG_HOST, '');
        $extra = self::extraBasis();
        return [
            'host'         => $host,
            // Ins Formular: so, dass unverändertes Speichern wieder dieselbe Adresse ergibt.
            'adresse'      => $extra !== '' && $host !== '' && self::autoritaet($extra) === $host ? $extra : $host,
            'token'        => Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN),
            'token_host'   => (string) Settings::get(self::EINSTELLUNG_TOKEN_HOST, ''),
            'eingerichtet' => !Config::isDemo() && self::eingerichtet(),
            'demo'         => Config::isDemo(),
        ];
    }

    /**
     * Adresse und Token aus dem Formular übernehmen.
     *
     * Token leer: bleibt – außer es gehört zu einem anderen Host, dann wird es
     * entfernt. „-“ entfernt es. Adresse leer: Anbindung ganz entfernen.
     *
     * @return array{ok:bool,message:string,warnung?:bool}
     */
    public static function einstellungenSpeichern(string $adresse, string $token): array
    {
        $adresse = trim($adresse);
        $token = trim($token);
        $hatteToken = Settings::geheimnisHinterlegt(Settings::GEHEIM_EVENTWORX_TOKEN);

        if ($adresse === '') {
            Settings::forget(self::EINSTELLUNG_HOST);
            self::tokenEntfernen();
            Audit::log('einstellungen.eventworx', 'settings', '', ['host' => '', 'token' => $hatteToken ? 'entfernt' : 'unverändert']);
            return ['ok' => true, 'message' => 'Eventworx-Anbindung entfernt – Kundendaten werden als Freitext eingetragen.'];
        }
        try {
            $host = self::host($adresse);
        } catch (EventworxFehler $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($token !== '' && $token !== '-') {
            // Es geht als Kopfzeile hinaus: sichtbare ASCII-Zeichen, ohne Leerzeichen.
            if (preg_match('/^[\x21-\x7E]{8,512}$/', $token) !== 1) {
                return ['ok' => false, 'message' => 'Das API-Token besteht aus 8 bis 512 sichtbaren Zeichen ohne Leerzeichen – bitte so einfügen, wie Eventworx es anzeigt.'];
            }
            $schluessel = Crypto::schluesselFehler();
            if ($schluessel !== null) {
                return ['ok' => false, 'message' => $schluessel];
            }
        }

        Settings::put(self::EINSTELLUNG_HOST, $host);
        $stand = 'unverändert';
        $warnung = false;
        $meldung = 'Eventworx gespeichert (' . $host . ').';
        if ($token === '-') {
            $stand = $hatteToken ? 'entfernt' : 'unverändert';
            self::tokenEntfernen();
            $meldung = 'Eventworx gespeichert, das API-Token ist entfernt.';
        } elseif ($token !== '') {
            Settings::geheimnisSetzen(Settings::GEHEIM_EVENTWORX_TOKEN, $token);
            Settings::put(self::EINSTELLUNG_TOKEN_HOST, $host);
            $stand = 'gesetzt';
            $meldung = 'Eventworx gespeichert, das API-Token gilt für ' . $host . '.';
        } elseif ($hatteToken && (string) Settings::get(self::EINSTELLUNG_TOKEN_HOST, '') !== $host) {
            // Ein Token gilt für seinen Mandanten. Sonst ginge es bei der
            // ersten Abfrage an die neue Adresse – wer immer das ist.
            self::tokenEntfernen();
            $stand = 'entfernt';
            $warnung = true;
            $meldung = 'Eventworx gespeichert. Für eine andere Eventworx-Adresse bitte das API-Token neu eingeben.';
        }
        Audit::log('einstellungen.eventworx', 'settings', '', ['host' => $host, 'token' => $stand]);
        return ['ok' => true, 'message' => $meldung, 'warnung' => $warnung];
    }

    // ------------------------------------------------------------ Abfragen

    /**
     * `check/ping`, dann `job?limit=1`: erst ein numerisches `total` zeigt,
     * dass das Token gilt (ping antwortet womöglich auch ohne).
     *
     * @return array{ok:bool,message:string}
     */
    public static function verbindungTesten(): array
    {
        if (Config::isDemo()) {
            return ['ok' => true, 'message' => 'Demo-Modus: Eventworx wird nicht gefragt, die Suche liefert Beispielvorgänge.'];
        }
        if (!self::eingerichtet()) {
            return ['ok' => false, 'message' => self::NICHT_EINGERICHTET];
        }
        try {
            self::holen('check/ping');
            $liste = self::holen('job', [
                'start'  => 0,
                'limit'  => 1,
                'page'   => 1,
                'filter' => self::json([['property' => 'subType', 'operator' => 'in', 'value' => self::ARTEN_SUCHE]]),
            ]);
            $anzahl = $liste['total'] ?? null;
            if (!is_int($anzahl) && !(is_string($anzahl) && ctype_digit($anzahl))) {
                throw new EventworxFehler(self::UNERWARTET, 200, 'OHNE_TOTAL');
            }
            return ['ok' => true, 'message' => 'Verbunden mit ' . Settings::get(self::EINSTELLUNG_HOST, '') . ' – '
                . number_format((int) $anzahl, 0, ',', '.') . ' Aufträge/Angebote lesbar.'];
        } catch (EventworxFehler $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Vorgänge nach Nummer oder Text – Aufträge und Angebote (Bericht) oder
     * die Arten aus $arten (Kundenverwaltung: Aufträge und Lieferscheine).
     * Ob Eventworx mit `query` auch in Titel und Kunde sucht, ist am echten
     * Mandanten nicht belegt – die Nummer ist der sichere Weg; genaue Treffer
     * stehen oben.
     *
     * @param list<string> $arten Teilmenge von order, offer, deliverynote
     * @return list<array{id:string,art:string,art_name:string,nummer:string,projekt:string,titel:string,von:?string,bis:?string,genau:bool}>
     */
    public static function suchen(string $text, array $arten = self::ARTEN_SUCHE): array
    {
        $arten = array_values(array_intersect(self::ARTEN_VORGANG, $arten));
        if ($arten === []) {
            throw new \LogicException('Eventworx: keine Vorgangsart für die Suche.');
        }
        $text = self::suchtext($text);
        $antwort = self::holen('job', [
            'start'  => 0,
            'limit'  => 20,
            'page'   => 1,
            'query'  => $text,
            'sort'   => self::json([['property' => 'lastModification', 'direction' => 'DESC']]),
            'filter' => self::json([
                ['property' => 'subType', 'operator' => 'in', 'value' => $arten],
                ['property' => 'deleted', 'operator' => '=', 'value' => 'false'],
                ['property' => 'archived', 'operator' => '=', 'value' => 'false'],
            ]),
        ]);
        $klein = mb_strtolower($text);
        $treffer = [];
        foreach ($antwort['data'] as $job) {
            if (!is_array($job) || !is_string($job['id'] ?? null) || preg_match(self::ID, $job['id']) !== 1 || isset($treffer[$job['id']])) {
                continue;
            }
            $art = self::art($job);
            $nummer = self::nummer($job);
            if (!in_array($art, $arten, true) || $nummer === '') {
                continue; // eine andere Art (Rechnung, Wareneingang …), oder noch nie gespeichert (TMP_…)
            }
            [$von, $bis] = self::hauptzeit($job);
            $treffer[$job['id']] = [
                'id'       => $job['id'],
                'art'      => $art,
                'art_name' => self::ART_NAMEN[$art],
                'nummer'   => $nummer,
                'projekt'  => self::text($job['projectNumber'] ?? '', 64),
                'titel'    => self::text($job['title'] ?? ''),
                'von'      => $von,
                'bis'      => $bis,
                'genau'    => mb_strtolower($nummer) === $klein || mb_strtolower(self::text($job['jobNumber'] ?? '', 64)) === $klein,
            ];
        }
        $treffer = array_values($treffer);
        usort($treffer, static fn (array $a, array $b): int => (int) $b['genau'] <=> (int) $a['genau']);
        return $treffer;
    }

    /**
     * Einen Vorgang lesen und für den Bericht aufbereiten.
     *
     * @return array{verweis:array{id:string,art:string,art_name:string,nummer:string,host:string,link:string},gruppen:array{veranstaltung:array,kunde:array,ansprechpartner:array,ort:array,zeitraum:array}}
     */
    public static function vorgang(string $id): array
    {
        $id = trim($id);
        if (preg_match(self::ID, $id) !== 1) {
            throw new EventworxFehler('Unbekannte Vorgangskennung – bitte den Vorgang über die Suche wählen.', 0, 'ID');
        }
        $job = self::holen('job/' . $id)['data'];
        if (($job['id'] ?? null) !== $id || !in_array(self::art($job), self::ARTEN_VORGANG, true) || self::nummer($job) === '') {
            throw new EventworxFehler(self::UNERWARTET, 200, 'ANDERER_VORGANG');
        }
        return self::abbilden($job);
    }

    /**
     * @internal Reine Abbildung eines `job/<id>` auf die Gruppen des Berichts
     * (direkt prüfbar). Nur Klartext; was hier nicht steht, geht nicht hinaus.
     */
    public static function abbilden(array $job): array
    {
        $id = is_string($job['id'] ?? null) ? $job['id'] : '';
        $art = self::art($job);
        $nummer = self::nummer($job);
        $kunde = is_array($job['customer'] ?? null) ? $job['customer'] : [];
        $kontakt = is_array($job['contact'] ?? null) ? $job['contact'] : [];

        // Kunde ist selbst der Veranstaltungsort, oder es gibt einen eigenen.
        if (self::wahr($job['customerIsLocation'] ?? null)) {
            $ortName = self::name($kunde);
            $ortAnschrift = self::anschrift($job['postalAddress'] ?? null);
        } else {
            $ortName = self::name($job['locationContact'] ?? null);
            $ortAnschrift = self::anschrift($job['locationAddress'] ?? null);
        }

        [$von, $bis] = self::hauptzeit($job);
        $aufbau = self::zeit($job['setupDate'] ?? null);
        $abbau = self::zeit($job['tearDownDate'] ?? null);

        return [
            'verweis' => [
                'id'       => $id,
                'art'      => $art,
                'art_name' => self::ART_NAMEN[$art] ?? '',
                'nummer'   => $nummer,
                'host'     => (string) Settings::get(self::EINSTELLUNG_HOST, ''),
                'link'     => self::link($art, $id),
                // Kennung des Kunden in Eventworx – nur zum Vergleich (Kundenbereich), nie ins PDF.
                'kunde_ref' => self::kundeRef($job),
            ],
            'gruppen' => [
                'veranstaltung' => [
                    'veranstaltung' => self::text($job['title'] ?? ''),
                    'auftrag_nr'    => $nummer,
                    'projekt_nr'    => self::text($job['projectNumber'] ?? '', 64),
                ],
                'kunde' => [
                    'firma'        => self::name($kunde),
                    'anschrift'    => self::anschrift($job['postalAddress'] ?? null),
                    'kundennummer' => self::text($kunde['customerId'] ?? ($job['customerId'] ?? ''), 64),
                ],
                'ansprechpartner' => [
                    'ansprechpartner'     => self::person($kontakt, true),
                    'telefon'             => self::kommunikation($kontakt, ['tel_business', 'mobile'],
                        static fn (string $v): bool => preg_match('#^[0-9 +()/-]{3,40}$#', $v) === 1),
                    'email'               => self::kommunikation($kontakt, ['email_business'],
                        static fn (string $v): bool => strlen($v) <= 254 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false),
                    'projektleiter_kunde' => self::person($kontakt, false),
                ],
                'ort' => [
                    'aufstellort'             => trim($ortName . "\n" . $ortAnschrift),
                    'anlagenverantwortlicher' => self::person($job['locationContactPerson'] ?? null, false),
                ],
                'zeitraum' => [
                    'zeitraum' => self::zeitraumText($aufbau, $von, $bis, $abbau),
                    'aufbau'   => $aufbau,
                    'von'      => $von,
                    'bis'      => $bis,
                    'abbau'    => $abbau,
                ],
            ],
        ];
    }

    /**
     * Die Kennung des Kunden eines Vorgangs (customerRefId) – nur als
     * ObjectId; alles andere (eine Kundennummer wie „K-10023“, Großbuchstaben,
     * ein Objekt) ergibt ''. Sie dient nur dem Vergleich: Gehört ein Vorgang
     * zum selben Kunden wie ein anderer? Angezeigt wird sie nirgends.
     */
    public static function kundeRef(array $job): string
    {
        $ref = $job['customerRefId'] ?? null;
        return is_string($ref) && preg_match(self::OID, $ref) === 1 ? $ref : '';
    }

    // ------------------------------------------------------------ Dateien am Vorgang

    /**
     * Die PDF-Dateien, die Benutzer in Eventworx an einem Vorgang hochgeladen
     * haben (Prüfprotokolle etwa) – nur Metadaten, nie Inhalte. Erzeugte
     * Belege (Auftrag, Rechnung, Lieferschein: mit metaDocType) gehören nicht
     * dazu, ebenso wenig Dateien außerhalb von DATEI_PFAD, Nicht-PDF (XML der
     * E-Rechnung) und Einträge ohne ObjectId.
     *
     * Eventworx übergeht unbekannte Filter still und liefert dann Dateien
     * ganz anderer Vorgänge – womöglich anderer Kunden. Gehört deshalb auch
     * nur EINE Datei der Antwort nicht zu diesem Vorgang (ownerDocumentId ≠
     * $jobId, auch: fehlt), wird die ganze Liste verworfen (FILTER).
     *
     * Was hier geliefert wird, merkt sich dieser Prozess für datei().
     *
     * @return array{dateien:list<array{id:string,name:string,zeit:?string,beschreibung:string}>, mehr:bool}
     */
    public static function dokumente(string $jobId): array
    {
        if (preg_match(self::OID, $jobId) !== 1) {
            throw new EventworxFehler('Unbekannte Vorgangskennung – Dateien gibt es nur für Vorgänge mit Eventworx-Kennung (ObjectId).', 0, 'ID');
        }
        $filter = self::DATEI_FILTER_MIT_PFAD ? ['path' => self::DATEI_PFAD, 'ownerDocumentId' => $jobId] : ['ownerDocumentId' => $jobId];
        $antwort = self::holen('file/list', [
            'page'   => 1,
            'start'  => 0,
            'limit'  => self::DATEIEN_JE_LAUF,
            'filter' => self::json([['property' => 'meta', 'operator' => '=', 'value' => $filter]]),
        ]);
        $liste = $antwort['data'];
        foreach ($liste as $f) {
            if (!is_array($f)) {
                self::vergessenFuer($jobId);
                throw new EventworxFehler(self::UNERWARTET, 200, 'UNERWARTET');
            }
            if (($f['ownerDocumentId'] ?? null) !== $jobId) {
                self::vergessenFuer($jobId);
                throw new EventworxFehler('Eventworx hat die Dateiliste nicht auf den Vorgang beschränkt – nichts übernommen.', 200, 'FILTER');
            }
        }
        $total = $antwort['total'] ?? null;
        $total = is_int($total) || (is_string($total) && ctype_digit($total)) ? (int) $total : null;
        $mehr = count($liste) > self::DATEIEN_JE_LAUF || ($total !== null && $total > min(count($liste), self::DATEIEN_JE_LAUF));
        $dateien = [];
        foreach (array_slice($liste, 0, self::DATEIEN_JE_LAUF) as $f) {
            $id = is_string($f['id'] ?? null) ? $f['id'] : '';
            $art = $f['metaDocType'] ?? null;
            $pfad = $f['path'] ?? null;
            if (preg_match(self::OID, $id) !== 1 || isset($dateien[$id])) {
                continue;
            }
            if ($art !== null && $art !== '') {
                continue; // ein erzeugter Beleg (Auftrag, Rechnung, Mahnung …)
            }
            if (!is_string($pfad) || ($pfad !== self::DATEI_PFAD && !str_starts_with($pfad, self::DATEI_PFAD . '/'))) {
                continue;
            }
            $roh = is_string($f['documentName'] ?? null) ? $f['documentName'] : (is_string($f['name'] ?? null) ? $f['name'] : '');
            $name = self::text($roh, 190);
            $typ = is_string($f['contentType'] ?? null) ? strtolower(trim($f['contentType'])) : '';
            // Mit Inhaltstyp zählt der, sonst die Endung; der Download prüft %PDF- ohnehin.
            if ($typ !== '' ? !str_contains($typ, 'pdf') : !str_ends_with(strtolower($name), '.pdf')) {
                continue;
            }
            $dateien[$id] = [
                'id'           => $id,
                'name'         => $name !== '' ? $name : 'Dokument.pdf',
                'zeit'         => self::zeit($f['timeStamp'] ?? null) ?? self::zeit($f['creationDate'] ?? null) ?? self::zeit($f['documentDate'] ?? null),
                'beschreibung' => self::text($f['description'] ?? '', 255),
            ];
            self::$gelesen[$id] = ['job' => $jobId, 'name' => $dateien[$id]['name'],
                'roh' => mb_substr(trim((string) preg_replace(self::STEUERZEICHEN, '', mb_scrub($roh, 'UTF-8'))), 0, 255)];
        }
        $dateien = array_values($dateien);
        usort($dateien, static fn (array $a, array $b): int => [(string) $b['zeit'], $a['name']] <=> [(string) $a['zeit'], $b['name']]);
        return ['dateien' => $dateien, 'mehr' => $mehr];
    }

    /**
     * Die Bytes einer Datei, die dokumente() in diesem Prozess geliefert hat
     * – eine andere ID ist ein Programmfehler (\LogicException, bevor
     * irgendetwas ins Netz geht). Nur ein PDF (beginnt mit %PDF-), höchstens
     * MAX_DATEI; eine HTML-Anmeldeseite statt der Datei wird abgelehnt.
     */
    public static function datei(string $dateiId): string
    {
        $gelesen = self::$gelesen[$dateiId] ?? null;
        if ($gelesen === null || preg_match(self::OID, $dateiId) !== 1) {
            throw new \LogicException('Eventworx: Datei nicht aus einer Liste dieses Laufs.');
        }
        return self::holenDatei('file/' . $dateiId, ['filename' => $gelesen['roh'] !== '' ? $gelesen['roh'] : $gelesen['name']]);
    }

    /** Für Prüfskripte (und einen neuen Lauf): was dokumente() gelesen hat, vergessen. */
    public static function vergessen(): void
    {
        self::$gelesen = [];
    }

    /**
     * @internal Nur für EventworxProbe: eine GET-Anfrage auf einen Pfad aus
     * EventworxProbe::PFADE (jeder andere: \LogicException vor dem Netz),
     * ausgewertet ohne Ausnahme für HTTP-Fehler – die Probe entscheidet
     * selbst, was ein Fehler heißt. Inhalte gehen nicht ins Log.
     *
     * @return array{ok:bool,status:int,json:bool,code:string,daten:?array}
     */
    public static function probeLesen(string $pfad, array $parameter = []): array
    {
        $muster = self::pfadPruefen($pfad, EventworxProbe::PFADE);
        if (Config::isDemo()) {
            throw new EventworxFehler('Demo-Modus: die Lese-Probe fragt kein Eventworx.', 0, 'DEMO');
        }
        ['antwort' => $antwort, 'ms' => $ms] = self::anfrage($pfad, $parameter, ['zeit' => self::ZEIT], EventworxProbe::PFADE);
        $status = (int) ($antwort['status'] ?? 0);
        $daten = json_decode((string) ($antwort['rumpf'] ?? ''), true, 512);
        $code = is_array($daten) && is_string($daten['errorCode'] ?? null) && preg_match(self::FEHLERCODE, $daten['errorCode']) === 1 ? $daten['errorCode'] : '';
        Logger::info('eventworx', ['pfad' => $muster, 'status' => $status, 'code' => $code, 'ms' => $ms]);
        return ['ok' => !empty($antwort['ok']), 'status' => $status, 'json' => is_array($daten), 'code' => $code, 'daten' => is_array($daten) ? $daten : null];
    }

    /** Link in die Web-App (nur zum Anzeigen, nie ins PDF); '' ohne gültige Adresse. */
    public static function link(string $art, string $id): string
    {
        $basis = self::basis();
        if ($basis === '' || !in_array($art, self::ARTEN_VORGANG, true) || preg_match(self::ID, $id) !== 1) {
            return '';
        }
        return $basis . '/eventworx/#job/edit/' . $art . '/' . $id;
    }

    /** Eventworx speichert Namen so, wie die Web-App sie maskiert („Muster &amp; Söhne“). */
    public static function dekodieren(string $text): string
    {
        return (string) preg_replace_callback(self::ENTITAET,
            static fn (array $m): string => html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $text);
    }

    /** Millisekunden (Zahl oder Ziffernfolge) oder ISO → ISO in UTC; sonst null. */
    public static function zeit(mixed $wert): ?string
    {
        if (is_int($wert) || is_float($wert)) {
            $ms = (int) $wert;
            return $ms > 0 ? gmdate('c', intdiv($ms, 1000)) : null;
        }
        if (!is_string($wert)) {
            return null;
        }
        $wert = trim($wert);
        if (preg_match('/^\d{9,15}$/', $wert) === 1) {
            return gmdate('c', intdiv((int) $wert, 1000));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $wert) !== 1) {
            return null;
        }
        try {
            $zeit = new \DateTimeImmutable($wert, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        return $zeit->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }

    /**
     * Rohdaten der Demo (Form wie `job/<id>`): ein Auftrag, ein Angebot. Im
     * Demo-Modus antwortet `holen()` daraus und geht nie ins Netz.
     *
     * @return list<array<string,mixed>>
     */
    public static function demo(): array
    {
        // Mitternacht Ortszeit, in Millisekunden – so liefert Eventworx Tage.
        $ms = static fn (int $tage): int => (new \DateTimeImmutable('today', Zeit::zone()))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->getTimestamp() * 1000;
        return [
            [
                'id' => '64f0c0de00000000000a1042', 'docType' => 'order', 'docSubType' => 'order', 'status' => 'confirmed',
                'jobNumber' => 'AU-1042', 'projectNumber' => 'P-2026-017', 'title' => 'Sommerfestival 2026',
                'customerId' => 'K-10023', 'customerRefId' => '65c3716b43f4c400000a0001',
                'customer' => ['isCompany' => true, 'companyName' => 'Muster Events GmbH &amp; Co. KG', 'customerId' => 'K-10023'],
                'postalAddress' => ['type' => 'postal', 'street' => 'Musterstraße 1', 'addressextension' => '', 'zipcode' => '12345', 'city' => 'Musterstadt', 'country' => 'de'],
                'contact' => ['personSalutation' => 'Frau', 'personFirstName' => 'Erika', 'personLastName' => 'Musterfrau', 'personDepartment' => 'Produktion',
                    'communications' => [['typeName' => 'tel_business', 'value' => '+49 30 1234567'], ['typeName' => 'email_business', 'value' => 'erika.musterfrau@example.org']]],
                'customerIsLocation' => false,
                'locationContact' => ['isLocation' => true, 'locationName' => 'Festivalgelände Nordwiese'],
                'locationAddress' => ['street' => 'Nordwiese 1', 'zipcode' => '12345', 'city' => 'Musterstadt', 'country' => 'de'],
                'locationContactPerson' => ['personFirstName' => 'Max', 'personLastName' => 'Mustermann'],
                'setupDate' => $ms(-4), 'startDate' => $ms(-2), 'endDate' => $ms(3), 'tearDownDate' => $ms(4),
                'lastModification' => $ms(-1),
            ],
            [
                'id' => '64f0c0de00000000000a1050', 'docType' => 'offer', 'docSubType' => 'offer', 'status' => 'open',
                'jobNumber' => 'AN-1050', 'variantNumber' => 'AN-1050-01', 'projectNumber' => 'P-2026-021', 'title' => 'Herbstmarkt Musterstadt',
                'customerId' => 'K-10031', 'customerRefId' => '65c3716b43f4c400000a0002',
                'customer' => ['isCompany' => true, 'companyName' => 'Stadtmarketing Musterstadt e.&#8239;V.', 'customerId' => 'K-10031'],
                'postalAddress' => ['type' => 'postal', 'street' => 'Rathausplatz 2', 'zipcode' => '12345', 'city' => 'Musterstadt', 'country' => 'de'],
                'contact' => ['personSalutation' => 'Herr', 'personFirstName' => 'Max', 'personLastName' => 'Mustermann',
                    'communications' => [['typeName' => 'mobile', 'value' => '0170 1234567']]],
                'customerIsLocation' => true,
                'rentStartDate' => $ms(30), 'rentEndDate' => $ms(32),
                'lastModification' => $ms(-3),
            ],
        ];
    }

    /**
     * Dateien der Demo am Auftrag AU-1042 (Form wie `file/list`): zwei
     * hochgeladene Prüfprotokolle (ohne metaDocType) – das Sammelprotokoll
     * von vor sechs Tagen übernimmt die Demo (DemoSeed), das von gestern
     * bleibt zum Ausprobieren – und der erzeugte Beleg des Auftrags
     * (metaDocType order), den dokumente() nie zeigt.
     *
     * @return list<array<string,mixed>>
     */
    public static function demoDateien(): array
    {
        $job = self::demo()[0];
        $ms = static fn (int $tage): int => (new \DateTimeImmutable('today', Zeit::zone()))->modify($tage . ' days')->getTimestamp() * 1000;
        return [
            ['id' => '6650a1f2c9e77a1b00000001', 'documentName' => 'DGUV V3 Prüfprotokolle Verteiler und Kabel 2026.pdf', 'contentType' => 'application/pdf',
                'timeStamp' => $ms(-6), 'creationDate' => $ms(-6), 'description' => '', 'owner' => 'demo', 'path' => '/job/documents/' . $job['projectNumber'],
                'ownerDocumentId' => $job['id'], 'projectNumber' => $job['projectNumber']],
            ['id' => '6650a1f2c9e77a1b00000002', 'documentName' => 'Auftrag ' . $job['jobNumber'] . '.pdf', 'contentType' => 'application/pdf',
                'timeStamp' => $ms(-6), 'creationDate' => $ms(-6), 'description' => '', 'owner' => 'demo', 'path' => self::DATEI_PFAD,
                'ownerDocumentId' => $job['id'], 'metaDocType' => 'order', 'projectNumber' => $job['projectNumber']],
            ['id' => '6650a1f2c9e77a1b00000003', 'documentName' => 'Prüfprotokoll Kabeltrommeln Nachlieferung.pdf', 'contentType' => 'application/pdf',
                'timeStamp' => $ms(-1), 'creationDate' => $ms(-1), 'description' => '', 'owner' => 'demo', 'path' => '/job/documents/' . $job['projectNumber'],
                'ownerDocumentId' => $job['id'], 'projectNumber' => $job['projectNumber']],
        ];
    }

    /** Die Datei der Demo: ein einseitiges PDF mit erfundenen Werten – zweimal erzeugt, dieselben Bytes. */
    private static function demoDatei(string $id): string
    {
        $datei = null;
        foreach (self::demoDateien() as $f) {
            $datei = $f['id'] === $id ? $f : $datei;
        }
        if ($datei === null) {
            throw new EventworxFehler('Die Datei gibt es in Eventworx nicht (mehr).', 404, 'NOT_FOUND');
        }
        $pdf = new \App\Pdf\Schreiber(['Title' => 'DEMO – Prüfprotokoll', 'Author' => 'PowerManagement (Demo)', 'Subject' => 'Erfundene Werte']);
        $pdf->seite();
        $pdf->text(20, 30, 'DEMO – Prüfprotokoll, erfundene Werte', 16, true);
        $pdf->text(20, 42, 'Wiederholungsprüfung ortsveränderlicher Betriebsmittel (DGUV Vorschrift 3, DIN EN 50699)', 10);
        // Andere Geräte als die Prüfnachweise von Hand im Demo-Kundenbereich (DemoSeed).
        $zeilen = match ($id) {
            '6650a1f2c9e77a1b00000001' => [['Unterverteiler PD32-15', 'bestanden'], ['Verlängerung CEE 63 A, 25 m (V63-25-01)', 'bestanden'],
                ['Verlängerung CEE 16 A, 10 m (V16-10-07)', 'bestanden']],
            '6650a1f2c9e77a1b00000003' => [['Kabeltrommel CEE 32 A, 50 m (KT-31)', 'bestanden'], ['Kabeltrommel Schuko, 25 m (KT-44)', 'bestanden']],
            default                    => [],
        };
        foreach ($zeilen as $i => [$geraet, $ergebnis]) {
            $pdf->text(20, 58 + $i * 8, $geraet, 10);
            $pdf->text(120, 58 + $i * 8, $ergebnis, 10);
        }
        $pdf->text(20, 100, 'Dieses Dokument ist ein Beispiel der Demo und belegt keine Prüfung.', 9, false, 0.4);
        return $pdf->ausgabe('2026-01-01T00:00:00+00:00', md5('demo-' . $id));
    }

    // ------------------------------------------------------------ Netz

    /**
     * Eine Anfrage an `/backend/<pfad>`; Rückgabe ist die entschlüsselte
     * JSON-Antwort (mit `data`, bei Listen `total`). Jede Abweichung wird zur
     * EventworxFehler-Meldung; ein unerlaubter Pfad ist ein Programmfehler.
     */
    private static function holen(string $pfad, array $parameter = [], int $zeit = self::ZEIT): array
    {
        $muster = self::pfadPruefen($pfad, self::PFADE);
        if (Config::isDemo()) {
            return self::demoAntwort($pfad, $parameter);
        }
        for ($versuch = 1; ; $versuch++) {
            ['antwort' => $antwort, 'ms' => $ms] = self::anfrage($pfad, $parameter, ['zeit' => $zeit], self::PFADE);
            $protokoll = ['pfad' => $muster, 'status' => (int) ($antwort['status'] ?? 0), 'code' => '', 'ms' => $ms];
            try {
                $daten = self::einordnen($antwort, $muster);
            } catch (EventworxFehler $e) {
                Logger::info('eventworx', array_replace($protokoll, ['code' => $e->fehlercode]));
                throw $e;
            }
            Logger::info('eventworx', $protokoll);
            // Der echte Mandant meldet einen fehlenden Datensatz als 200 mit
            // data:null – aber so auch einmal einen, der gerade gespeichert
            // wird. Also einmal nachlesen (der Takt sorgt für die halbe Sekunde).
            if ($muster === 'job/<id>' && ($daten['data'] ?? null) === null) {
                if ($versuch < 2) {
                    continue;
                }
                throw new EventworxFehler(self::NICHT_VORHANDEN, 404, 'NOT_FOUND');
            }
            return $daten;
        }
    }

    /**
     * Eine Datei (`file/<ObjectId>`) als Bytes – nur ein PDF, höchstens
     * MAX_DATEI (Http bricht darüber ab, statt alles zu laden), kein
     * Neuversuch. Ins Log nur `file/<id>`, Status, Code, Dauer.
     */
    private static function holenDatei(string $pfad, array $parameter): string
    {
        $muster = self::pfadPruefen($pfad, self::PFADE);
        if ($muster !== 'file/<id>') {
            throw new \LogicException('Eventworx: nur Dateien als Bytes.');
        }
        if (Config::isDemo()) {
            return self::demoDatei(substr($pfad, 5));
        }
        ['antwort' => $antwort, 'ms' => $ms] = self::anfrage($pfad, $parameter, ['zeit' => self::ZEIT_DATEI, 'accept' => '*/*', 'max' => self::MAX_DATEI], self::PFADE);
        $protokoll = ['pfad' => $muster, 'status' => (int) ($antwort['status'] ?? 0), 'code' => '', 'ms' => $ms];
        try {
            $bytes = self::dateiEinordnen($antwort);
        } catch (EventworxFehler $e) {
            Logger::info('eventworx', array_replace($protokoll, ['code' => $e->fehlercode]));
            throw $e;
        }
        Logger::info('eventworx', $protokoll);
        return $bytes;
    }

    /**
     * Der gemeinsame Teil jeder Anfrage: der Pfad gegen die Liste (noch
     * einmal, vor jedem Netzzugriff), das Token nur an den Host, für den es
     * eingetragen wurde, der Takt, nur GET. $liste ist nur PFADE oder
     * EventworxProbe::PFADE.
     *
     * @param array{zeit?:int,accept?:string,max?:int} $optionen
     * @return array{antwort:array{ok:bool,status:int,rumpf:string,fehler:string,nummer:int},ms:int}
     */
    private static function anfrage(string $pfad, array $parameter, array $optionen, string $liste): array
    {
        self::pfadPruefen($pfad, $liste);
        $basis = self::basis();
        $token = self::token();
        if (!self::eingerichtet() || $basis === '' || $token === '') {
            throw new EventworxFehler(self::NICHT_EINGERICHTET);
        }
        $url = $basis . '/backend/' . $pfad . ($parameter === [] ? '' : '?' . http_build_query($parameter, '', '&', PHP_QUERY_RFC3986));
        // Das Token geht nur an den Host, für den es eingetragen wurde.
        if (self::autoritaet($url) !== (string) Settings::get(self::EINSTELLUNG_TOKEN_HOST, '')) {
            throw new EventworxFehler(self::NICHT_EINGERICHTET);
        }
        self::takt();
        $beginn = self::jetzt();
        $http = [
            'kopf' => ['S-API-TOKEN' => $token, 'Accept' => $optionen['accept'] ?? 'application/json', 'User-Agent' => 'PowerManagement'],
            'zeit' => (int) ($optionen['zeit'] ?? self::ZEIT),
        ];
        if (isset($optionen['max'])) {
            $http['max'] = (int) $optionen['max'];
        }
        $antwort = Http::anfrage('GET', $url, $http);
        return ['antwort' => $antwort, 'ms' => (int) round((self::jetzt() - $beginn) * 1000)];
    }

    /**
     * Steht der Pfad in der Liste? Sonst \LogicException – bevor etwas ins
     * Netz geht. Rückgabe: das Pfadmuster fürs Log (`job/<id>`, `file/<id>`,
     * `stock/<id>` – nie die Kennung).
     */
    private static function pfadPruefen(string $pfad, string $liste): string
    {
        if (!in_array($liste, [self::PFADE, EventworxProbe::PFADE], true) || preg_match($liste, $pfad) !== 1) {
            throw new \LogicException('Eventworx: Pfad nicht in der Liste.');
        }
        return (string) preg_replace('#^([a-z]+)/[A-Za-z0-9]{8,40}$#', '$1/<id>', $pfad);
    }

    /** Was dokumente() zu diesem Vorgang gelesen hat, vergessen (die Liste ist verworfen). */
    private static function vergessenFuer(string $jobId): void
    {
        self::$gelesen = array_filter(self::$gelesen, static fn (array $g): bool => $g['job'] !== $jobId);
    }

    /** Die Antwort auf einen Download einordnen: zurück kommen nur die Bytes eines PDF. */
    private static function dateiEinordnen(array $antwort): string
    {
        $status = (int) ($antwort['status'] ?? 0);
        if ((int) ($antwort['nummer'] ?? 0) === Http::ZU_GROSS) {
            throw new EventworxFehler('Die Datei ist größer als 10 MB – bitte von Hand hochladen.', $status, 'ZU_GROSS');
        }
        if (empty($antwort['ok'])) {
            throw new EventworxFehler('Eventworx nicht erreichbar – bitte später noch einmal oder den Nachweis von Hand hochladen.', 0, 'NETZ');
        }
        if ($status >= 300 && $status < 400) {
            throw new EventworxFehler('Eventworx leitet um – stimmt die Adresse?', $status, 'UMLEITUNG');
        }
        $rumpf = (string) ($antwort['rumpf'] ?? '');
        if ($status === 200 && str_starts_with($rumpf, '%PDF-')) {
            if (strlen($rumpf) > self::MAX_DATEI) {
                throw new EventworxFehler('Die Datei ist größer als 10 MB – bitte von Hand hochladen.', $status, 'ZU_GROSS');
            }
            return $rumpf;
        }
        $daten = strlen($rumpf) <= 65536 ? json_decode($rumpf, true, 64) : null;
        $code = is_array($daten) && is_string($daten['errorCode'] ?? null) && preg_match(self::FEHLERCODE, $daten['errorCode']) === 1 ? $daten['errorCode'] : '';
        if ($status === 401 || $status === 403 || $code === 'ACCESS_DENIED') {
            throw new EventworxFehler(self::DATEI_ABGELEHNT, $status, 'ACCESS_DENIED');
        }
        if ($status === 429) {
            throw new EventworxFehler('Eventworx: zu viele Anfragen – bitte kurz warten.', $status, 'ZU_VIELE');
        }
        if ($status >= 500) {
            throw new EventworxFehler('Eventworx meldet einen Serverfehler (HTTP ' . $status . ').', $status, $code !== '' ? $code : 'SERVER');
        }
        if ($status === 404 || $code === 'NOT_FOUND') {
            throw new EventworxFehler('Die Datei gibt es in Eventworx nicht (mehr).', $status, 'NOT_FOUND');
        }
        if ($code !== '') {
            throw new EventworxFehler('Eventworx: ' . $code, $status, $code);
        }
        // Eine HTML-Anmeldeseite, ein Bild, Text …
        throw new EventworxFehler('Eventworx lieferte keine PDF-Datei.', $status, 'KEIN_PDF');
    }

    /** Eine Antwort einordnen: zurück kommt nur, was nach Erfolg aussieht. */
    private static function einordnen(array $antwort, string $muster): array
    {
        $dateien = $muster === 'file/list';
        if (empty($antwort['ok'])) {
            throw new EventworxFehler($dateien ? 'Eventworx nicht erreichbar – bitte später noch einmal oder den Nachweis von Hand hochladen.'
                : 'Eventworx nicht erreichbar – Kundendaten bitte als Freitext eintragen.', 0, 'NETZ');
        }
        $status = (int) ($antwort['status'] ?? 0);
        if ($status >= 300 && $status < 400) {
            throw new EventworxFehler('Eventworx leitet um – stimmt die Adresse?', $status, 'UMLEITUNG');
        }
        $daten = json_decode((string) ($antwort['rumpf'] ?? ''), true, 64);
        $json = is_array($daten);
        $code = $json && is_string($daten['errorCode'] ?? null) && preg_match(self::FEHLERCODE, $daten['errorCode']) === 1 ? $daten['errorCode'] : '';

        if ($status === 401 || $status === 403 || $code === 'ACCESS_DENIED') {
            throw new EventworxFehler($dateien ? self::DATEI_ABGELEHNT : 'Eventworx lehnt den Zugriff ab – API-Token oder REST-Modul prüfen.', $status, 'ACCESS_DENIED');
        }
        if ($status === 429) {
            throw new EventworxFehler('Eventworx: zu viele Anfragen – bitte kurz warten.', $status, 'ZU_VIELE');
        }
        if ($status >= 500) {
            throw new EventworxFehler('Eventworx meldet einen Serverfehler (HTTP ' . $status . ').', $status, $code !== '' ? $code : 'SERVER');
        }
        if (!$json) {
            throw new EventworxFehler('Eventworx antwortet nicht mit JSON – stimmt die Adresse?', $status, 'NO_JSON');
        }
        if ($muster === 'job/<id>' && ($status === 404 || $code === 'NOT_FOUND')) {
            throw new EventworxFehler(self::NICHT_VORHANDEN, $status, 'NOT_FOUND');
        }
        if (($daten['success'] ?? null) === false) {
            throw new EventworxFehler($code !== '' ? 'Eventworx: ' . $code : 'Eventworx: Fehler', $status, $code !== '' ? $code : 'ANDERER');
        }
        if ($status !== 200 || ($daten['success'] ?? null) !== true) {
            throw new EventworxFehler(self::UNERWARTET, $status, 'UNERWARTET');
        }
        $inhalt = $daten['data'] ?? null;
        $passt = match ($muster) {
            'job', 'file/list' => is_array($inhalt) && array_is_list($inhalt),
            'job/<id>' => $inhalt === null || (is_array($inhalt) && $inhalt !== [] && !array_is_list($inhalt)),
            default    => true,
        };
        if (!$passt) {
            throw new EventworxFehler(self::UNERWARTET, $status, 'UNERWARTET');
        }
        return $daten;
    }

    /**
     * Höchstens eine Anfrage je TAKT über alle PHP-Prozesse: Jede Anfrage
     * reserviert unter der Sperre ihren Zeitpunkt und wartet dann außerhalb
     * davon. Wäre sie erst in mehr als zwei Sekunden dran, wartet sie nicht.
     */
    private static function takt(): void
    {
        $datei = dirname(__DIR__, 2) . '/storage/cache/eventworx.lock';
        if (!is_dir(dirname($datei))) {
            @mkdir(dirname($datei), 0775, true);
        }
        $griff = @fopen($datei, 'c+');
        if ($griff === false) {
            Logger::error('eventworx: Drossel ohne Sperrdatei', ['datei' => 'storage/cache/eventworx.lock']);
            return;
        }
        try {
            flock($griff, LOCK_EX);
            $jetzt = self::jetzt();
            $letzter = (float) trim((string) stream_get_contents($griff, -1, 0));
            if ($letzter > $jetzt + self::HOECHSTENS_WARTEN + self::TAKT) {
                $letzter = 0.0; // die Uhr wurde zurückgestellt
            }
            $dran = max($jetzt, $letzter + self::TAKT);
            if ($dran - $jetzt > self::HOECHSTENS_WARTEN) {
                throw new EventworxFehler('Eventworx: gerade viele Anfragen – bitte gleich noch einmal.', 0, 'DROSSEL');
            }
            ftruncate($griff, 0);
            rewind($griff);
            fwrite($griff, sprintf('%.6F', $dran));
            fflush($griff);
        } finally {
            flock($griff, LOCK_UN);
            fclose($griff);
        }
        if ($dran > $jetzt) {
            self::$schlafen !== null ? (self::$schlafen)($dran - $jetzt) : usleep((int) round(($dran - $jetzt) * 1_000_000));
        }
    }

    /** Im Demo-Modus: dieselben Antwortformen wie Eventworx, aus demo(). */
    private static function demoAntwort(string $pfad, array $parameter): array
    {
        if ($pfad === 'check/ping') {
            return ['success' => true, 'message' => 'Service is running.'];
        }
        if ($pfad === 'file/list') {
            $filter = json_decode((string) ($parameter['filter'] ?? ''), true);
            $owner = is_array($filter) ? ($filter[0]['value']['ownerDocumentId'] ?? null) : null;
            $dateien = array_values(array_filter(self::demoDateien(), static fn (array $f): bool => $f['ownerDocumentId'] === $owner));
            return ['success' => true, 'total' => count($dateien), 'data' => $dateien];
        }
        if ($pfad === 'job') {
            $suche = mb_strtolower((string) ($parameter['query'] ?? ''));
            $treffer = array_values(array_filter(self::demo(), static function (array $job) use ($suche): bool {
                $text = mb_strtolower(implode(' ', [$job['jobNumber'], $job['variantNumber'] ?? '', $job['projectNumber'], $job['title'], self::name($job['customer'])]));
                return $suche === '' || str_contains($text, $suche);
            }));
            return ['success' => true, 'total' => count($treffer), 'data' => $treffer];
        }
        foreach (self::demo() as $job) {
            if ('job/' . $job['id'] === $pfad) {
                return ['success' => true, 'data' => $job];
            }
        }
        throw new EventworxFehler(self::NICHT_VORHANDEN, 404, 'NOT_FOUND');
    }

    // ------------------------------------------------------------ Hilfen

    /** Der Suchtext: ohne Steuerzeichen, Leerraum zusammengezogen, 2 bis 64 Zeichen. */
    private static function suchtext(string $text): string
    {
        $text = (string) preg_replace('/[\s\p{Z}]+/u', ' ', mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace(self::STEUERZEICHEN, '', $text);
        $text = trim(mb_substr(trim((string) preg_replace('/ {2,}/', ' ', $text)), 0, 64));
        if (mb_strlen($text) < 2) {
            throw new EventworxFehler('Bitte mindestens zwei Zeichen eingeben – am sichersten die Auftrags- oder Angebotsnummer.', 0, 'SUCHTEXT');
        }
        return $text;
    }

    private static function token(): string
    {
        $abgelegt = (string) Settings::get(Settings::GEHEIM_EVENTWORX_TOKEN, '');
        if ($abgelegt === '') {
            return '';
        }
        try {
            return Crypto::decrypt($abgelegt);
        } catch (\Throwable) {
            return ''; // ohne gültigen app.key kein Token
        }
    }

    private static function tokenEntfernen(): void
    {
        Settings::geheimnisSetzen(Settings::GEHEIM_EVENTWORX_TOKEN, '-');
        Settings::forget(self::EINSTELLUNG_TOKEN_HOST);
    }

    private static function extraBasis(): string
    {
        return rtrim(trim((string) Config::get('eventworx.basis', '')), '/');
    }

    /** Host (klein) samt Port, wenn einer angegeben ist; '' ohne Host. */
    private static function autoritaet(string $url): string
    {
        $teile = parse_url($url);
        if (!is_array($teile) || !isset($teile['host']) || $teile['host'] === '') {
            return '';
        }
        return strtolower($teile['host']) . (isset($teile['port']) ? ':' . $teile['port'] : '');
    }

    /** @internal Die Uhr (Sekunden als float) – auch für das Zeitbudget der Lese-Probe. */
    public static function jetzt(): float
    {
        return self::$uhr !== null ? (float) (self::$uhr)() : microtime(true);
    }

    private static function json(array $wert): string
    {
        return (string) json_encode($wert, JSON_UNESCAPED_SLASHES);
    }

    /** order, offer oder deliverynote – Wareneingang (Lieferschein „purchase“) zählt nicht. */
    private static function art(array $job): string
    {
        $art = is_string($job['docType'] ?? null) ? strtolower($job['docType']) : '';
        if ($art === 'deliverynote' && strtolower((string) (is_string($job['docSubType'] ?? null) ? $job['docSubType'] : '')) === 'purchase') {
            return '';
        }
        return $art;
    }

    /** Belegnummer; Angebote mit Variante (AN-1001-01). '' für nie gespeicherte (TMP_…). */
    private static function nummer(array $job): string
    {
        $nummer = self::text($job['jobNumber'] ?? '', 64);
        if ($nummer === '' || str_starts_with(strtoupper($nummer), 'TMP_')) {
            return '';
        }
        if (self::art($job) === 'offer') {
            $variante = self::text($job['variantNumber'] ?? '', 64);
            if ($variante !== '' && !str_starts_with(strtoupper($variante), 'TMP_')) {
                return $variante;
            }
            $nr = $job['variant'] ?? null;
            if ((is_int($nr) || (is_string($nr) && ctype_digit($nr))) && (int) $nr > 0) {
                return mb_substr(sprintf('%s-%02d', $nummer, (int) $nr), 0, 64);
            }
        }
        return $nummer;
    }

    /** Text aus Eventworx: dekodiert, ohne Steuerzeichen, Leerraum zusammengezogen, gekürzt. */
    private static function text(mixed $wert, int $hoechstens = 255): string
    {
        if (!is_string($wert) && !is_int($wert)) {
            return '';
        }
        $text = self::dekodieren(mb_scrub((string) $wert, 'UTF-8'));
        $text = (string) preg_replace(self::STEUERZEICHEN, ' ', str_replace(["\r", "\n", "\t"], ' ', $text));
        $text = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        return trim(mb_substr(trim($text), 0, $hoechstens));
    }

    private static function wahr(mixed $wert): bool
    {
        return $wert === true || $wert === 1 || $wert === '1' || $wert === 'true';
    }

    /** Name eines Kontakts: Person → Vor- und Nachname, Ort → Ortsname, sonst Firma; dann die übrigen. */
    private static function name(mixed $kontakt): string
    {
        if (!is_array($kontakt)) {
            return '';
        }
        $person = trim(self::text($kontakt['personFirstName'] ?? '') . ' ' . self::text($kontakt['personLastName'] ?? ''));
        if (self::wahr($kontakt['isPerson'] ?? null)) {
            $name = $person !== '' ? $person : self::text($kontakt['personNickName'] ?? '');
        } elseif (self::wahr($kontakt['isLocation'] ?? null)) {
            $name = self::text($kontakt['locationName'] ?? '');
        } else {
            $name = self::text($kontakt['companyName'] ?? '');
        }
        foreach ([self::text($kontakt['companyName'] ?? ''), $person, self::text($kontakt['locationName'] ?? '')] as $ersatz) {
            $name = $name !== '' ? $name : $ersatz;
        }
        return mb_substr($name, 0, 255);
    }

    /** Eine Person: mit Anrede und Abteilung als Ansprechpartner, sonst Titel und Name. */
    private static function person(mixed $kontakt, bool $ausfuehrlich): string
    {
        if (!is_array($kontakt)) {
            return '';
        }
        $anreden = ['herr' => 'Herr', 'frau' => 'Frau', 'mr' => 'Herr', 'mrs' => 'Frau', 'ms' => 'Frau', 'male' => 'Herr', 'female' => 'Frau'];
        $teile = [
            self::text($kontakt['personTitle'] ?? '', 40),
            self::text($kontakt['personFirstName'] ?? ''),
            self::text($kontakt['personLastName'] ?? ''),
        ];
        $name = implode(' ', array_filter($teile, static fn (string $t): bool => $t !== ''));
        $mitPerson = self::text($kontakt['personFirstName'] ?? '') !== '' || self::text($kontakt['personLastName'] ?? '') !== '';
        if (!$mitPerson) {
            $name = self::name($kontakt);
        }
        if (!$ausfuehrlich || $name === '') {
            return mb_substr($name, 0, 255);
        }
        $anrede = $mitPerson ? ($anreden[mb_strtolower(self::text($kontakt['personSalutation'] ?? '', 20))] ?? '') : '';
        $abteilung = self::text($kontakt['personDepartment'] ?? '', 80);
        return mb_substr(trim($anrede . ' ' . $name) . ($abteilung !== '' ? ', ' . $abteilung : ''), 0, 255);
    }

    /** Anschrift mehrzeilig: Straße, Zusatz, PLZ Ort, Land nur außerhalb Deutschlands. */
    private static function anschrift(mixed $adresse): string
    {
        if (!is_array($adresse)) {
            return '';
        }
        $land = self::text($adresse['country'] ?? '', 40);
        $zeilen = [
            self::text($adresse['street'] ?? ''),
            self::text($adresse['addressextension'] ?? ''),
            trim(self::text($adresse['zipcode'] ?? '', 16) . ' ' . self::text($adresse['city'] ?? '')),
            strtolower($land) === 'de' ? '' : (strlen($land) === 2 ? strtoupper($land) : $land),
        ];
        return implode("\n", array_filter($zeilen, static fn (string $z): bool => $z !== ''));
    }

    /** Erster gültiger Wert der Kommunikationsdaten, Typen in dieser Reihenfolge. */
    private static function kommunikation(array $kontakt, array $typen, \Closure $gueltig): string
    {
        $eintraege = is_array($kontakt['communications'] ?? null) ? $kontakt['communications'] : [];
        foreach ($typen as $typ) {
            foreach ($eintraege as $eintrag) {
                if (!is_array($eintrag) || ($eintrag['typeName'] ?? null) !== $typ) {
                    continue;
                }
                $wert = self::text($eintrag['value'] ?? '', 254);
                if ($wert !== '' && $gueltig($wert)) {
                    return $wert;
                }
            }
        }
        return '';
    }

    /** Zeitraum des Vorgangs: startDate/endDate, sonst die Mietzeit. @return array{0:?string,1:?string} */
    private static function hauptzeit(array $job): array
    {
        $von = self::zeit($job['startDate'] ?? null);
        $bis = self::zeit($job['endDate'] ?? null);
        if ($von === null && $bis === null) {
            $von = self::zeit($job['rentStartDate'] ?? null);
            $bis = self::zeit($job['rentEndDate'] ?? null);
        }
        return [$von, $bis];
    }

    /** „Aufbau 10.07.2026 · Veranstaltung 11.07.2026 – 13.07.2026 · Abbau 14.07.2026“ in Ortszeit. */
    private static function zeitraumText(?string $aufbau, ?string $von, ?string $bis, ?string $abbau): string
    {
        $tag = static fn (?string $iso): string => $iso === null ? '' : Zeit::anzeige($iso, 'd.m.Y');
        $haupt = $von !== null && $bis !== null && $tag($von) !== $tag($bis) ? $tag($von) . ' – ' . $tag($bis) : $tag($von ?? $bis);
        $teile = [];
        if ($aufbau !== null) {
            $teile[] = 'Aufbau ' . $tag($aufbau);
        }
        if ($haupt !== '') {
            $teile[] = ($aufbau !== null || $abbau !== null ? 'Veranstaltung ' : '') . $haupt;
        }
        if ($abbau !== null) {
            $teile[] = 'Abbau ' . $tag($abbau);
        }
        return implode(' · ', $teile);
    }
}
