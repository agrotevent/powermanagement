<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Kunden\Kundenbereich;
use App\Kunden\KundenService;
use App\Services\Eventworx;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\SiteService;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Session;
use App\Support\Zeit;

/**
 * Übergabeberichte „Elektrische Anlage“ – Lebenslauf und Rechte.
 *
 * Ein Bericht entsteht als Entwurf (vorbelegt aus Plan, Schutzorganen,
 * Kabeln und Inventar), wird am Tablet ausgefüllt und gespeichert, vom
 * Prüfer unterschrieben (dann ist der Inhalt eingefroren und die Nummer
 * vergeben) und vom Kunden bestätigt. Geändert wird ein Bericht nur über
 * diese Klasse; nach der Prüfer-Unterschrift nur noch als neue Fassung.
 *
 * Wer was sieht (sichtbar), wer ausfüllt (darfBearbeiten), wer den Kunden
 * unterschreiben lässt (darfKundenblock) und wer als Prüfer unterschreibt
 * (darfPruefen), steht hier – die Route prüft nur das Recht. Jede ID aus
 * der Anfrage wird einzeln geprüft; die IDs der Stromkreise kommen nie
 * vom Browser (BerichtSchema).
 *
 * Die Ereignisse (bericht_ereignisse) sind der Lebenslauf eines Berichts,
 * ohne Kundendaten und ohne IP. Anders als das Audit-Log räumt die Wartung
 * sie nicht auf; sie gehen erst mit dem Bericht.
 */
final class BerichtService
{
    public const STATUS = [
        'entwurf'       => 'Entwurf',
        'geprueft'      => 'vom Prüfer unterschrieben',
        'abgeschlossen' => 'abgeschlossen',
        'ersetzt'       => 'ersetzt',
        'verworfen'     => 'verworfen',
    ];

    public const EINGEFROREN = 'Der Bericht ist unterschrieben – Änderungen nur als Korrektur.';

    /** Sitzung: welche Eventworx-Vorgänge zu welchem Bericht geladen wurden (ohne Kundendaten). */
    public const EWX_SITZUNG = 'ewx_geladen';
    private const EWX_HOECHSTENS = 5;

    /** Höchstens ein Ereignis „gespeichert“ je Benutzer und zehn Minuten. */
    private const GESPEICHERT_TAKT = 600;

    /** Ab wann die Wartung einen unveränderten Entwurf meldet. */
    private const ENTWURF_LIEGT_TAGE = 180;

    private const QUELLEN = ['anschluss', 'generator', 'wechselrichter'];

    /** Was beim Einfrieren dazukommt – und beim Zurückziehen und in der Korrektur wieder geht (dazu kopf.pruefer, kopf.eventworx). */
    private const FESTE_KOPIEN = ['nummer', 'fassung', 'vorgaenger', 'auftragnehmer', 'messgeraete', 'labels', 'texte', 'bewertung'];

    /** Idempotenzschlüssel des Geräts (crypto.randomUUID oder 32 Hexzeichen). */
    private const ANFRAGE = '/^[A-Za-z0-9-]{16,64}$/';

    /** Zweck des aus app.key abgeleiteten Siegel-Schlüssels (Crypto::ableiten). */
    private const SIEGEL_ZWECK = 'bericht-siegel-1';

    public const OHNE_KUNDE = ['abwesend' => 'Kunde nicht anwesend', 'verweigert' => 'Unterschrift verweigert'];

    private const NUR_PRUEFER = 'Als Prüfer handelt nur, wer „als Prüfer unterschreiben“ hat, eine hinterlegte Qualifikation und „bearbeiten“ für den Standort.';

    // ------------------------------------------------------------ Lesen

    /** Ein Bericht mit dekodiertem Inhalt. */
    public static function find(int $id): ?array
    {
        $b = Database::first(
            'SELECT b.*, s.name AS site_name, u.username AS geaendert_von_name FROM berichte b
             LEFT JOIN sites s ON s.id = b.site_id LEFT JOIN users u ON u.id = b.geaendert_von WHERE b.id = ?',
            [$id]
        );
        if ($b === null) {
            return null;
        }
        $b = self::zeile($b);
        $b['inhalt'] = json_decode((string) $b['inhalt'], true) ?: [];
        return $b;
    }

    /**
     * Die sichtbaren Berichte, neueste Prüfung zuerst – ohne Inhalt.
     *
     * @param array{status?:string,site_id?:int|string,aufbau_id?:int|string,jahr?:int|string,suche?:string} $filter
     * @return list<array<string,mixed>>
     */
    public static function liste(array $filter = []): array
    {
        $sql = 'SELECT b.id, b.nummer, b.jahr, b.laufnr, b.fassung, b.vorgaenger_id, b.status, b.version, b.site_id, b.aufbau_id, b.standort_entfernt,
                       b.standort_name, b.titel, b.veranstaltung, b.kunde_name, b.pruefdatum, b.ewx_nummer, b.erstellt_von,
                       b.eingefroren_am, b.abgeschlossen_am, b.created_at, b.updated_at, s.name AS site_name
                FROM berichte b LEFT JOIN sites s ON s.id = b.site_id WHERE 1 = 1';
        $werte = [];
        if (isset(self::STATUS[(string) ($filter['status'] ?? '')])) {
            $sql .= ' AND b.status = ?';
            $werte[] = (string) $filter['status'];
        }
        if ((int) ($filter['site_id'] ?? 0) > 0) {
            $sql .= ' AND b.site_id = ?';
            $werte[] = (int) $filter['site_id'];
        }
        if ((int) ($filter['aufbau_id'] ?? 0) > 0) {
            $sql .= ' AND b.aufbau_id = ?';
            $werte[] = (int) $filter['aufbau_id'];
        }
        if (preg_match('/^\d{4}$/', (string) ($filter['jahr'] ?? '')) === 1) {
            $sql .= ' AND b.pruefdatum LIKE ?';
            $werte[] = $filter['jahr'] . '-%';
        }
        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            $sql .= ' AND (b.nummer LIKE ? OR b.titel LIKE ? OR b.veranstaltung LIKE ? OR b.kunde_name LIKE ? OR b.standort_name LIKE ?)';
            $muster = '%' . mb_substr($suche, 0, 64) . '%';
            array_push($werte, $muster, $muster, $muster, $muster, $muster);
        }
        $sql .= ' ORDER BY b.pruefdatum DESC, b.id DESC';
        $out = [];
        foreach (Database::select($sql, $werte) as $b) {
            $b = self::zeile($b);
            if (self::sichtbar($b)) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> Lebenslauf, ältestes zuerst */
    public static function ereignisse(int $id): array
    {
        return array_map(static function (array $e): array {
            $e['details'] = json_decode((string) ($e['details'] ?? ''), true) ?: [];
            return $e;
        }, Database::select('SELECT * FROM bericht_ereignisse WHERE bericht_id = ? ORDER BY id', [$id]));
    }

    // ------------------------------------------------------------ Rechte

    /**
     * Sieht der angemeldete Benutzer diesen Bericht?
     *
     * Wer alles sieht: ja. Ohne Standort nur der Ersteller (mit „ausfüllen“) –
     * war der Standort da und wurde gelöscht, nicht einmal der: sonst lebte
     * eine längst entzogene Freigabe mit dem Löschen wieder auf.
     * Wer am Standort ändern darf und Berichte ausfüllt: alle, auch Entwürfe.
     * Sonst nur abgeschlossene (und ersetzte), und nur mit einer Freigabe für
     * den Standort, deren Datenzeitraum den Prüftag berührt – ein Konto mit
     * der Rolle „kunde“ sieht so den Bericht seiner Veranstaltung, nicht den
     * der Woche davor.
     *
     * Kundenzugänge (Kundenbereich, users.kunde_id) nie: sie sehen Berichte
     * nur über die ausdrückliche Zuordnung (sichtbarFuerKunde) – nie über
     * Standort und Datenfenster, denn ein Gelände hat mehrere Veranstalter.
     * Ohne Rechte wären sie hier ohnehin draußen; die Regel steht trotzdem
     * zuerst, damit kein späteres Recht und keine Freigabe sie öffnet.
     */
    public static function sichtbar(array $b): bool
    {
        if (Kundenbereich::istKundenzugang()) {
            return false;
        }
        if (!Auth::can('berichte.view') && !Auth::can('berichte.manage')) {
            return false;
        }
        if (Zugriff::alles()) {
            return true;
        }
        $siteId = $b['site_id'] !== null ? (int) $b['site_id'] : null;
        if ($siteId === null) {
            return !self::standortGeloescht($b) && Auth::can('berichte.manage') && (int) $b['erstellt_von'] === Auth::id();
        }
        if (Auth::can('berichte.manage') && Zugriff::darfStandortAendern($siteId)) {
            return true;
        }
        if (!in_array((string) $b['status'], ['abgeschlossen', 'ersetzt'], true)) {
            return false;
        }
        $fenster = Zugriff::datenFensterStandort($siteId);
        if ($fenster === []) {
            return false;
        }
        [$von, $bis] = self::tag((string) $b['pruefdatum']);
        return $von !== null && Zugriff::imFenster($fenster, $von, $bis);
    }

    /**
     * Ausfüllen: nur Entwürfe, nur mit „ausfüllen“ und „bearbeiten“ für den
     * Standort – und nie, was nach den Daten eingefroren ist (Unterschrift,
     * Prüfsumme oder PDF da), ganz gleich, was in der Spalte status steht.
     */
    public static function darfBearbeiten(array $b): bool
    {
        return Auth::can('berichte.manage') && (string) $b['status'] === 'entwurf' && self::standortRegel($b) && !self::eingefroren($b);
    }

    /**
     * Ist der Bericht eingefroren? Nach dem Status – und nach den Daten:
     * eine Unterschriftszeile, ein inhalt_hash oder ein gespeichertes PDF.
     * So wird ein unterschriebener Bericht nicht wieder änderbar, nur weil
     * jemand per SQL den Status auf „entwurf“ zurückgesetzt hat.
     */
    public static function eingefroren(array $b): bool
    {
        if (in_array((string) $b['status'], ['geprueft', 'abgeschlossen', 'ersetzt'], true)) {
            return true;
        }
        $id = (int) $b['id'];
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM berichte b WHERE b.id = ? AND (b.inhalt_hash IS NOT NULL
                OR EXISTS (SELECT 1 FROM bericht_unterschriften u WHERE u.bericht_id = b.id)
                OR EXISTS (SELECT 1 FROM bericht_pdf p WHERE p.bericht_id = b.id))',
            [$id]
        ) > 0;
    }

    /** Kundenunterschrift einholen: wie Ausfüllen, aber nach der Prüfer-Unterschrift. */
    public static function darfKundenblock(array $b): bool
    {
        return Auth::can('berichte.manage') && (string) $b['status'] === 'geprueft' && self::standortRegel($b);
    }

    /** Als Prüfer handeln: das Recht, eine hinterlegte Qualifikation und der Standort. */
    public static function darfPruefen(array $b): bool
    {
        return Auth::can('berichte.pruefen') && self::qualifikation() !== '' && self::standortRegel($b);
    }

    private static function standortRegel(array $b): bool
    {
        if ($b['site_id'] !== null) {
            return Zugriff::darfStandortAendern((int) $b['site_id']);
        }
        if (self::standortGeloescht($b)) {
            return Zugriff::alles();
        }
        return (int) $b['erstellt_von'] === Auth::id() || Zugriff::alles();
    }

    /** Hatte der Bericht einen Standort, der inzwischen gelöscht ist? */
    private static function standortGeloescht(array $b): bool
    {
        if (array_key_exists('standort_entfernt', $b)) {
            return (int) $b['standort_entfernt'] === 1;
        }
        return (int) Database::scalar('SELECT standort_entfernt FROM berichte WHERE id = ?', [(int) $b['id']]) === 1;
    }

    private static function qualifikation(): string
    {
        return trim((string) (Auth::user()['pruefer_qualifikation'] ?? ''));
    }

    // ------------------------------------------------------------ Anlegen

    /**
     * Einen Entwurf anlegen – vorbelegt aus dem Aufbau, den Anlagen, ihren
     * Schutzorganen und Kabeln. Ohne Standort ein leerer Bericht (Freitext).
     *
     * @param list<int|string> $objektIds in der Reihenfolge der Auswahl
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function anlegen(?int $siteId, ?int $aufbauId, array $objektIds): array
    {
        if (!Auth::can('berichte.manage')) {
            return ['ok' => false, 'message' => 'Für Übergabeberichte fehlt das Recht „ausfüllen“.'];
        }
        $aufbau = null;
        if ($aufbauId !== null) {
            $aufbau = AufbauService::find($aufbauId);
            if ($aufbau === null) {
                return ['ok' => false, 'message' => 'Diesen Aufbau gibt es nicht.'];
            }
            if ($siteId === null) {
                $siteId = (int) $aufbau['site_id'];
            } elseif ((int) $aufbau['site_id'] !== $siteId) {
                return ['ok' => false, 'message' => 'Der Aufbau gehört zu einem anderen Standort.'];
            }
        }
        $site = null;
        if ($siteId !== null) {
            $site = SiteService::find($siteId);
            if ($site === null) {
                return ['ok' => false, 'message' => 'Diesen Standort gibt es nicht.'];
            }
            if (!Zugriff::darfStandortAendern($siteId)) {
                return ['ok' => false, 'message' => 'Für diesen Standort fehlt eine Freigabe mit „bearbeiten“.'];
            }
        }
        $objekte = self::objektePruefen($siteId, $objektIds);
        if (is_string($objekte)) {
            return ['ok' => false, 'message' => $objekte];
        }

        $inhalt = array_replace(BerichtSchema::leer(), Vorbelegung::kopf($site, $aufbau, $objekte));
        $inhalt['grenzwerte'] = Grenzwerte::parameter();
        foreach ($objekte as $o) {
            $neu = Vorbelegung::kreise((int) $o['id'], $inhalt['kreise'], count($inhalt['kreise']) + 1);
            array_push($inhalt['kreise'], ...$neu);
        }
        $inhalt['kreise'] = array_slice($inhalt['kreise'], 0, BerichtSchema::MAX_KREISE);
        $felder = BerichtSchema::listenfelder($inhalt);
        if ($site !== null) {
            $felder['standort_name'] = mb_substr((string) $site['name'], 0, 190);
        }

        $id = self::transaktion(static function () use ($inhalt, $felder, $siteId, $aufbauId, $objekte): int {
            $jetzt = gmdate('c');
            $id = Database::insert('berichte', $felder + [
                'status'      => 'entwurf',
                'version'     => 1,
                'fassung'     => 1,
                'site_id'     => $siteId,
                'aufbau_id'   => $aufbauId,
                'inhalt'      => Kanon::json($inhalt),
                'erstellt_von' => (int) Auth::id(),
                'geaendert_von' => Auth::id(),
                'created_at'  => $jetzt,
                'updated_at'  => $jetzt,
            ]);
            self::objekteAbgleichen($id, $inhalt, array_map(static fn (array $o): int => (int) $o['id'], $objekte));
            self::ereignis($id, 'angelegt', ['anlagen' => count($objekte), 'kreise' => count($inhalt['kreise']), 'aufbau' => $aufbauId !== null]);
            return $id;
        });
        Audit::log('bericht.create', 'bericht', (string) $id, ['site' => $siteId, 'aufbau' => $aufbauId]);
        return ['ok' => true, 'message' => 'Entwurf #' . $id . ' angelegt.', 'id' => $id];
    }

    // ------------------------------------------------------------ Speichern

    /**
     * Den Inhalt eines Entwurfs speichern (Autosave).
     *
     * $version ist der Stand, auf dem der Browser gearbeitet hat; passt er
     * nicht mehr, ändert sich nichts (409) – kein stilles Überschreiben der
     * Eingaben eines anderen Tablets. Ausnahme: derselbe Benutzer schickt
     * genau den Inhalt noch einmal, der schon gespeichert ist (die Antwort
     * auf sein Speichern ging unterwegs verloren) – das ist kein Konflikt,
     * sondern gespeichert (200 mit dem aktuellen Stand). $ewxVorgang:
     * Kennung eines in dieser Sitzung geladenen Eventworx-Vorgangs, „-“
     * entfernt den Verweis.
     *
     * @return array{ok:bool,message:string,status:int,version?:int,gespeichert_am?:string,bewertung?:array,hinweise?:list<string>,abschnitte?:array,konflikt?:bool}
     */
    public static function speichern(int $id, int $version, string $json, string $ewxVorgang = ''): array
    {
        $b = self::find($id);
        if (($fehler = self::zugang($b, 'bearbeiten')) !== null) {
            return $fehler;
        }
        if (strlen($json) > BerichtSchema::MAX_BYTES) {
            return ['ok' => false, 'status' => 422, 'message' => 'Der Bericht ist zu groß (höchstens ' . intdiv(BerichtSchema::MAX_BYTES, 1024) . ' KB).'];
        }
        try {
            $roh = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $roh = null;
        }
        if (!is_array($roh)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Der Inhalt ist nicht lesbar.'];
        }
        [$inhalt, $hinweise] = self::aufbereiten($roh, $b);
        if ($version !== (int) $b['version']) {
            return self::schonGespeichert($b, $version, $inhalt, $hinweise, trim($ewxVorgang)) ?? self::konflikt($b);
        }

        $felder = BerichtSchema::listenfelder($inhalt);
        if ($b['site_id'] !== null && (string) ($b['site_name'] ?? '') !== '') {
            $felder['standort_name'] = mb_substr((string) $b['site_name'], 0, 190);
        }

        // Eventworx-Verweis nur aus dem, was in dieser Sitzung zu diesem Bericht geladen wurde.
        $ewx = trim($ewxVorgang);
        $ewxNeu = null;
        if ($ewx === '-') {
            $felder += ['ewx_host' => '', 'ewx_id' => '', 'ewx_art' => '', 'ewx_nummer' => '', 'ewx_am' => null, 'ewx_kunde_ref' => ''];
        } elseif ($ewx !== '') {
            $geladen = self::eventworxGeladen($id)[$ewx] ?? null;
            if ($geladen === null) {
                $hinweise[] = 'Der Eventworx-Vorgang wurde in dieser Sitzung nicht geladen – Verweis nicht übernommen.';
            } else {
                $ewxNeu = $geladen;
                // Die Kunden-Kennung (nur zum Vorschlag im Kundenbereich) kommt mit dem Verweis – nie für sich.
                $felder += ['ewx_host' => $geladen['host'], 'ewx_id' => $ewx, 'ewx_art' => $geladen['art'], 'ewx_nummer' => $geladen['nummer'], 'ewx_am' => $geladen['am'],
                    'ewx_kunde_ref' => $geladen['kunde_ref']];
            }
        }

        $jetzt = gmdate('c');
        $ergebnis = self::transaktion(static function () use ($id, $version, $inhalt, $felder, $jetzt, $ewx, $ewxNeu, $b): ?int {
            $sets = [];
            $werte = [];
            foreach ($felder + ['inhalt' => Kanon::json($inhalt), 'geaendert_von' => Auth::id(), 'updated_at' => $jetzt] as $spalte => $wert) {
                $sets[] = $spalte . ' = ?';
                $werte[] = $wert;
            }
            array_push($werte, $id, $version);
            $n = Database::execute('UPDATE berichte SET ' . implode(', ', $sets) . ", version = version + 1 WHERE id = ? AND version = ? AND status = 'entwurf'"
                . ' AND inhalt_hash IS NULL AND NOT EXISTS (SELECT 1 FROM bericht_unterschriften u WHERE u.bericht_id = berichte.id)', $werte);
            if ($n !== 1) {
                return null;
            }
            self::objekteAbgleichen($id, $inhalt);
            if ($ewxNeu !== null && $ewx !== (string) $b['ewx_id']) {
                self::ereignis($id, 'eventworx_uebernommen', ['nummer' => $ewxNeu['nummer']]);
            } elseif ($ewx === '-' && (string) $b['ewx_id'] !== '') {
                self::ereignis($id, 'eventworx_entfernt', ['nummer' => (string) $b['ewx_nummer']]);
            }
            $letztes = Database::scalar(
                "SELECT am FROM bericht_ereignisse WHERE bericht_id = ? AND ereignis = 'gespeichert' AND user_id = ? ORDER BY id DESC LIMIT 1",
                [$id, Auth::id()]
            );
            if (!is_string($letztes) || (Zeit::ts($letztes) ?? 0) < time() - self::GESPEICHERT_TAKT) {
                self::ereignis($id, 'gespeichert');
            }
            return $version + 1;
        });
        if ($ergebnis === null) {
            $aktuell = self::find($id);
            return $aktuell !== null && ($aktuell['status'] !== 'entwurf' || self::eingefroren($aktuell))
                ? ['ok' => false, 'status' => 423, 'message' => self::EINGEFROREN]
                : self::konflikt($aktuell ?? $b);
        }
        $bewertung = Grenzwerte::alle($inhalt);
        return [
            'ok'             => true,
            'status'         => 200,
            'message'        => 'Gespeichert.',
            'version'        => $ergebnis,
            'gespeichert_am' => $jetzt,
            'bewertung'      => $bewertung,
            'hinweise'       => array_values(array_unique($hinweise)),
            'abschnitte'     => self::abschnitte($inhalt, $bewertung),
        ];
    }

    /**
     * Rohen Inhalt aus dem Browser aufbereiten: Schema (BerichtSchema) und
     * Prüfgeräte (nur vorhandene und aktive).
     *
     * @return array{0:array,1:list<string>} Inhalt und Hinweise
     */
    private static function aufbereiten(array $roh, array $b): array
    {
        $bereinigt = BerichtSchema::bereinigen($roh, $b['inhalt']);
        $inhalt = $bereinigt['inhalt'];
        $hinweise = $bereinigt['hinweise'];
        $geraete = [];
        foreach ($inhalt['messung']['geraete'] as $gid) {
            $g = Pruefgeraete::find((int) $gid);
            if ($g === null || (int) $g['aktiv'] !== 1) {
                $hinweise[] = 'Ein Prüfgerät ist nicht (mehr) aktiv und wurde aus dem Bericht genommen.';
                continue;
            }
            $geraete[] = (string) (int) $g['id'];
        }
        $inhalt['messung']['geraete'] = $geraete;
        return [$inhalt, $hinweise];
    }

    /**
     * Ein Speichern mit altem Stand, das in Wahrheit schon angekommen ist:
     * derselbe Benutzer, genau der gespeicherte Inhalt, kein neuer
     * Eventworx-Verweis. Dann 200 mit dem aktuellen Stand – sonst null
     * (Konflikt).
     */
    private static function schonGespeichert(array $b, int $version, array $inhalt, array $hinweise, string $ewx): ?array
    {
        if ($version >= (int) $b['version'] || (int) ($b['geaendert_von'] ?? 0) !== (int) Auth::id()) {
            return null;
        }
        $ewxGleich = $ewx === '' || ($ewx === '-' && (string) $b['ewx_id'] === '') || $ewx === (string) $b['ewx_id'];
        $gespeichert = (string) Database::scalar('SELECT inhalt FROM berichte WHERE id = ?', [(int) $b['id']]);
        if (!$ewxGleich || !hash_equals($gespeichert, Kanon::json($inhalt))) {
            return null;
        }
        $bewertung = Grenzwerte::alle($inhalt);
        return [
            'ok'             => true,
            'status'         => 200,
            'message'        => 'Gespeichert.',
            'wiederholt'     => true,
            'version'        => (int) $b['version'],
            'gespeichert_am' => (string) ($b['updated_at'] ?? gmdate('c')),
            'bewertung'      => $bewertung,
            'hinweise'       => array_values(array_unique($hinweise)),
            'abschnitte'     => self::abschnitte($inhalt, $bewertung),
        ];
    }

    /**
     * „Zeilen ergänzen“: für neue Abgänge der Anlagen Zeilen anlegen –
     * vorhandene bleiben, wie sie sind.
     *
     * @param list<int|string> $objektIds
     * @return array{ok:bool,message:string,status:int,version?:int,neu?:int}
     */
    public static function kreiseVorbelegen(int $id, int $version, array $objektIds): array
    {
        $b = self::find($id);
        if (($fehler = self::zugang($b, 'bearbeiten')) !== null) {
            return $fehler;
        }
        if ($b['site_id'] === null) {
            return ['ok' => false, 'status' => 422, 'message' => 'Ein Bericht ohne Standort hat keine Anlagen.'];
        }
        if ($version !== (int) $b['version']) {
            return self::konflikt($b);
        }
        $objekte = self::objektePruefen((int) $b['site_id'], $objektIds);
        if (is_string($objekte)) {
            return ['ok' => false, 'status' => 403, 'message' => $objekte];
        }
        if ($objekte === []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte mindestens eine Anlage wählen.'];
        }
        $inhalt = array_replace_recursive(BerichtSchema::leer(), $b['inhalt']);
        $inhalt['kreise'] = $b['inhalt']['kreise'] ?? [];
        $nr = 0;
        foreach ($inhalt['kreise'] as $k) {
            $nr = max($nr, (int) ($k['nr'] ?? 0));
        }
        $neu = 0;
        foreach ($objekte as $o) {
            $zeilen = Vorbelegung::kreise((int) $o['id'], $inhalt['kreise'], $nr + 1);
            $platz = BerichtSchema::MAX_KREISE - count($inhalt['kreise']);
            $zeilen = array_slice($zeilen, 0, max(0, $platz));
            array_push($inhalt['kreise'], ...$zeilen);
            $nr += count($zeilen);
            $neu += count($zeilen);
        }
        $ok = self::transaktion(static function () use ($id, $version, $inhalt, $objekte): bool {
            $n = Database::execute(
                "UPDATE berichte SET inhalt = ?, version = version + 1, geaendert_von = ?, updated_at = ? WHERE id = ? AND version = ? AND status = 'entwurf'
                 AND inhalt_hash IS NULL AND NOT EXISTS (SELECT 1 FROM bericht_unterschriften u WHERE u.bericht_id = berichte.id)",
                [Kanon::json($inhalt), Auth::id(), gmdate('c'), $id, $version]
            );
            if ($n !== 1) {
                return false;
            }
            self::objekteAbgleichen($id, $inhalt, array_map(static fn (array $o): int => (int) $o['id'], $objekte));
            return true;
        });
        if (!$ok) {
            return self::konflikt(self::find($id) ?? $b);
        }
        return ['ok' => true, 'status' => 200, 'version' => $version + 1, 'neu' => $neu,
            'message' => $neu === 0 ? 'Keine neuen Abgänge – alle haben schon eine Zeile.' : $neu . ' Zeile(n) ergänzt.'];
    }

    /**
     * Kundendaten aus einem früheren Bericht desselben Standorts – nur Kunde,
     * Projektleiter des Kunden und der Eventworx-Verweis. Geschrieben wird
     * nichts: der Browser trägt die Felder ein, das nächste Autosave speichert
     * sie (und mit `ewx` den Verweis, der dafür in der Sitzung vorgemerkt wird).
     *
     * @return array{ok:bool,message:string,status:int,kunde?:array,projektleiter_kunde?:string,eventworx?:?array}
     */
    public static function kundeAusBericht(int $id, int $quelleId): array
    {
        $b = self::find($id);
        if (($fehler = self::zugang($b, 'bearbeiten')) !== null) {
            return $fehler;
        }
        $q = self::find($quelleId);
        if ($q === null || $q['id'] === $b['id'] || !self::sichtbar($q) || $q['status'] !== 'abgeschlossen' || $q['site_id'] !== $b['site_id']) {
            return ['ok' => false, 'status' => 404, 'message' => 'Diesen Bericht gibt es hier nicht.'];
        }
        $kunde = array_replace(BerichtSchema::leer()['kopf']['kunde'], is_array($q['inhalt']['kopf']['kunde'] ?? null) ? $q['inhalt']['kopf']['kunde'] : []);
        $kunde['quelle'] = 'bericht';
        $verweis = null;
        if ((string) $q['ewx_id'] !== '') {
            $verweis = ['id' => (string) $q['ewx_id'], 'art' => (string) $q['ewx_art'], 'nummer' => (string) $q['ewx_nummer'], 'host' => (string) $q['ewx_host'],
                'kunde_ref' => (string) $q['ewx_kunde_ref']];
            self::eventworxMerken($id, $verweis, $q['ewx_am'] !== null ? (string) $q['ewx_am'] : null);
        }
        return [
            'ok'                  => true,
            'status'              => 200,
            'message'             => 'Kundendaten aus ' . ($q['nummer'] ?? ('Bericht #' . $q['id'])) . ' – bitte prüfen.',
            'kunde'               => $kunde,
            'projektleiter_kunde' => (string) ($q['inhalt']['kopf']['projektleiter_kunde'] ?? ''),
            'eventworx'           => $verweis,
        ];
    }

    /**
     * Abgeschlossene Berichte, aus denen sich die Kundendaten übernehmen
     * lassen: zuerst desselben Aufbaus, dann des Standorts.
     *
     * @return list<array<string,mixed>>
     */
    public static function kundenQuellen(array $b): array
    {
        if ($b['site_id'] === null) {
            return [];
        }
        $liste = array_values(array_filter(self::liste(['site_id' => (int) $b['site_id'], 'status' => 'abgeschlossen']),
            static fn (array $q): bool => $q['id'] !== $b['id'] && $q['kunde_name'] !== ''));
        usort($liste, static fn (array $x, array $y): int => [(int) ($y['aufbau_id'] === $b['aufbau_id'] && $b['aufbau_id'] !== null), $y['pruefdatum']]
            <=> [(int) ($x['aufbau_id'] === $b['aufbau_id'] && $b['aufbau_id'] !== null), $x['pruefdatum']]);
        return array_slice($liste, 0, 20);
    }

    // ------------------------------------------------------------ Eventworx (Sitzung)

    /**
     * Einen in dieser Sitzung geladenen Eventworx-Vorgang zu einem Bericht
     * vormerken – nur Kennung, Nummer, Art, Host, Zeit und die Kennung des
     * Kunden in Eventworx (nur als ObjectId), keine Kundendaten. Das Autosave
     * übernimmt den Verweis nur, wenn er hier steht.
     *
     * @param array{id?:string,nummer?:string,art?:string,host?:string,kunde_ref?:string} $verweis
     */
    public static function eventworxMerken(int $berichtId, array $verweis, ?string $am = null): void
    {
        $jobId = (string) ($verweis['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9]{8,40}$/', $jobId) !== 1) {
            return;
        }
        $alle = Session::get(self::EWX_SITZUNG, []);
        $alle = is_array($alle) ? $alle : [];
        $je = is_array($alle[$berichtId] ?? null) ? $alle[$berichtId] : [];
        unset($je[$jobId]);
        $je[$jobId] = [
            'nummer' => mb_substr((string) ($verweis['nummer'] ?? ''), 0, 64),
            'art'    => mb_substr((string) ($verweis['art'] ?? ''), 0, 24),
            'host'   => mb_substr((string) ($verweis['host'] ?? ''), 0, 190),
            'am'     => $am ?? gmdate('c'),
            'kunde_ref' => self::kundeRef($verweis['kunde_ref'] ?? ''),
        ];
        $alle[$berichtId] = array_slice($je, -self::EWX_HOECHSTENS, self::EWX_HOECHSTENS, true);
        Session::put(self::EWX_SITZUNG, $alle);
    }

    /** @return array<string,array{nummer:string,art:string,host:string,am:string,kunde_ref:string}> */
    public static function eventworxGeladen(int $berichtId): array
    {
        $alle = Session::get(self::EWX_SITZUNG, []);
        $je = is_array($alle) && is_array($alle[$berichtId] ?? null) ? $alle[$berichtId] : [];
        $out = [];
        foreach ($je as $jobId => $v) {
            if (is_array($v)) {
                $out[(string) $jobId] = ['nummer' => (string) ($v['nummer'] ?? ''), 'art' => (string) ($v['art'] ?? ''),
                    'host' => (string) ($v['host'] ?? ''), 'am' => (string) ($v['am'] ?? ''), 'kunde_ref' => self::kundeRef($v['kunde_ref'] ?? '')];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ Abschluss-Prüfung

    /**
     * Was vor der Prüfer-Unterschrift fehlt (Fehler, blockieren) und was der
     * Prüfer bestätigen muss (Warnungen). Die Felder sind Pfade im Inhalt,
     * damit das Formular zum Feld springen kann.
     *
     * @return array{fehler:list<array{feld:string,text:string}>, warnungen:list<array{code:string,text:string,bestaetigt:bool}>}
     */
    public static function abschlussPruefen(array $b): array
    {
        $i = array_replace_recursive(BerichtSchema::leer(), $b['inhalt']);
        $i['kreise'] = $b['inhalt']['kreise'] ?? [];
        $i['maengel'] = $b['inhalt']['maengel'] ?? [];
        $fehler = [];
        $warnungen = [];
        $f = static function (string $feld, string $text) use (&$fehler): void {
            $fehler[] = ['feld' => $feld, 'text' => $text];
        };
        $bestaetigt = is_array($i['abschluss']['warnungen_bestaetigt'] ?? null) ? $i['abschluss']['warnungen_bestaetigt'] : [];
        $w = static function (string $code, string $text) use (&$warnungen, $bestaetigt): void {
            $warnungen[] = ['code' => $code, 'text' => $text, 'bestaetigt' => in_array($code, $bestaetigt, true)];
        };
        $leer = static fn (mixed $v): bool => trim((string) $v) === '';
        $kopf = $i['kopf'];

        if ($leer($kopf['kunde']['firma'])) {
            $f('kopf.kunde.firma', 'Kunde (Firma oder Name) fehlt.');
        }
        if (Einstellungen::wert('firma.name') === '') {
            $f('firma', 'Firmendaten fehlen – bitte in den Systemeinstellungen eintragen.');
        }
        if ($leer($kopf['anlage']['bezeichnung'])) {
            $f('kopf.anlage.bezeichnung', 'Bezeichnung der Anlage fehlt.');
        }
        if ($leer($kopf['anlage']['aufstellort'])) {
            $f('kopf.anlage.aufstellort', 'Aufstellort fehlt.');
        }
        if ($leer($kopf['pruefdatum'])) {
            $f('kopf.pruefdatum', 'Prüfdatum fehlt.');
        } else {
            // Das Prüfdatum bestimmt Nummer und Jahr – ein Tippfehler im Jahr fiele sonst erst im PDF auf.
            $pd = (string) $kopf['pruefdatum'];
            $heute = new \DateTimeImmutable('today', Zeit::zone());
            $tag = preg_match('/^(\d{4})-\d{2}-\d{2}$/', $pd, $pm) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $pd, Zeit::zone()) : false;
            if ($tag === false || (int) $pm[1] < 2000 || (int) $pm[1] > (int) $heute->format('Y') + 1) {
                $f('kopf.pruefdatum', 'Prüfdatum unplausibel (' . $pd . ') – bitte das Jahr prüfen.');
            } elseif ($tag > $heute) {
                $w('pruefdatum_zukunft', 'Das Prüfdatum ' . $tag->format('d.m.Y') . ' liegt in der Zukunft.');
            } elseif ($tag < $heute->modify('-30 days')) {
                $w('pruefdatum_alt', 'Das Prüfdatum ' . $tag->format('d.m.Y') . ' liegt mehr als 30 Tage zurück.');
            }
        }
        if ($leer($kopf['ort'])) {
            $f('kopf.ort', 'Ort der Prüfung fehlt.');
        }
        if ($leer($i['netz']['form'])) {
            $f('netz.form', 'Netzform fehlt.');
        }
        if ($leer($i['netz']['spannung'])) {
            $f('netz.spannung', 'Netzspannung fehlt.');
        }
        if ($i['grundlage']['normen'] === [] && $leer($i['grundlage']['sonstige'])) {
            $f('grundlage.normen', 'Prüfgrundlage fehlt.');
        }
        if ($leer($i['grundlage']['grund'])) {
            $f('grundlage.grund', 'Grund der Prüfung fehlt.');
        }
        if (self::qualifikation() === '') {
            $f('kopf.qualifikation', 'Für dieses Konto ist keine Prüfer-Qualifikation hinterlegt (Benutzerverwaltung).');
        }
        if ((int) ($b['fassung'] ?? 1) > 1 && $leer($b['korrektur_grund'] ?? '')) {
            $f('korrektur_grund', 'Grund der Korrektur fehlt.');
        }

        // ---------------------------------------------- Stromkreise
        $bewertung = Grenzwerte::alle($i);
        $mangel = 0;
        $hinweis = 0;
        $ohneQuerschnitt = 0;
        $gemessen = false;
        if ($i['kreise'] === [] && $leer($i['bemerkungen'])) {
            $f('kreise', 'Keine Stromkreise – bei einer reinen Sichtprüfung bitte unter Bemerkungen begründen.');
        }
        foreach ($i['kreise'] as $k) {
            $z = (string) $k['z'];
            $nr = 'Kreis ' . ($k['nr'] !== '' ? $k['nr'] : '?');
            $bw = $bewertung['kreise'][$z] ?? ['auto' => '', 'meldungen' => []];
            $auto = (string) $bw['auto'];
            $pruefer = (string) ($k['pruefer'] ?? 'auto');
            if (($k['status'] ?? '') === 'gemessen') {
                $gemessen = true;
            }
            if ($auto === '') {
                $f('kreise.' . $z, $nr . ': noch nichts gemessen – Werte eintragen oder „nicht gemessen“ mit Grund.');
            } elseif ($auto === 'fehlt') {
                $erste = array_values(array_filter($bw['meldungen'], static fn (array $m): bool => $m['stufe'] === 'fehlt'))[0]['text'] ?? 'Wert fehlt.';
                $f('kreise.' . $z, $nr . ': ' . $erste);
            }
            if ($pruefer === 'io' && in_array($auto, ['mangel', 'manuell'], true) && $leer($k['begruendung'] ?? '')) {
                $f('kreise.' . $z . '.begruendung', $nr . ': abweichende Bewertung bitte begründen.');
            }
            if ($auto === 'manuell' && $pruefer === 'auto') {
                $f('kreise.' . $z . '.pruefer', $nr . ': bitte selbst bewerten (i.O. oder n.i.O.).');
            }
            if ($pruefer === 'nio' || ($pruefer === 'auto' && $auto === 'mangel')) {
                $mangel++;
            }
            if ($auto === 'hinweis') {
                $hinweis++;
            }
            if (($k['status'] ?? '') === 'gemessen' && $leer($k['leitung']['querschnitt'] ?? '')) {
                $ohneQuerschnitt++;
            }
        }
        foreach (['erdung', 'netz'] as $teil) {
            if (($bewertung[$teil]['auto'] ?? '') === 'mangel') {
                $mangel++;
            }
        }
        if (($bewertung['erdung']['auto'] ?? '') === 'manuell') {
            $f('messung.erdung', 'Erdungswiderstand ohne berechenbaren Grenzwert – Bezug wählen (eigener Grenzwert oder „ohne Bewertung“).');
        }
        $erdungGemessen = !$leer($i['messung']['erdung']['w'] ?? '') || !$leer($i['messung']['iso_bus']['w'] ?? '');
        if (($gemessen || $erdungGemessen) && $i['messung']['geraete'] === []) {
            $f('messung.geraete', 'Messwerte ohne Prüfgerät – bitte das Messgerät angeben.');
        }

        // ---------------------------------------------- Ergebnis
        $nio = 0;
        foreach (['besichtigung', 'erprobung'] as $teil) {
            $nio += count(array_filter($i['punkte'][$teil] ?? [], static fn ($z): bool => $z === 'nio'));
        }
        $kurzOffen = self::kurzlisteOffen($i['punkte']);
        $offeneMaengel = count(array_filter($i['maengel'], static fn (array $m): bool => trim((string) ($m['behoben'] ?? '')) === ''));
        $entspricht = (string) $i['ergebnis']['entspricht'];
        if ($entspricht === '') {
            $f('ergebnis.entspricht', 'Prüfergebnis fehlt: entspricht die Anlage den Regeln der Elektrotechnik (ja oder nein)?');
        } elseif ($entspricht === 'ja' && ($mangel > 0 || $nio > 0)) {
            // Ein als behoben eingetragener Mangel belegt nichts, solange der
            // Messwert oder Prüfpunkt selbst noch den Mangel zeigt (DIN VDE
            // 0100-600 6.4.3.1: nach der Beseitigung wird die Prüfung wiederholt).
            $f('ergebnis.entspricht', '„Ja“ trotz Mängeln – nach der Behebung neu messen bzw. den Prüfpunkt neu bewerten, oder „Nein“ wählen.');
        } elseif ($entspricht === 'nein' && $leer($i['ergebnis']['instand_bis']) && $i['maengel'] === []) {
            $f('ergebnis.instand_bis', 'Bei „Nein“ bitte eine Frist zur Instandsetzung oder die Mängel angeben.');
        }
        if ($entspricht === 'ja' && $kurzOffen > 0) {
            // Erstprüfung = Besichtigen, Erproben, Messen (DIN VDE 0100-600 6.4.1).
            $f('punkte', $kurzOffen . ($kurzOffen === 1 ? ' Punkt' : ' Punkte') . ' der Kurzliste nicht beantwortet – i.O., n.i.O. oder entf. wählen.');
        }
        if ((string) $i['ergebnis']['errichter'] === '1' && $entspricht !== 'ja') {
            $f('ergebnis.errichter', 'Die Errichterbestätigung setzt „entspricht: ja“ voraus.');
        }
        if ((string) $i['ergebnis']['maengelfrei'] === 'ja'
            && ($mangel > 0 || $nio > 0 || $offeneMaengel > 0 || !$leer($i['ergebnis']['instand_bis']) || $entspricht === 'nein')) {
            $f('ergebnis.maengelfrei', '„Mängelfrei: Ja“ widerspricht den eingetragenen Mängeln.');
        }

        // ---------------------------------------------- Warnungen
        foreach ($i['messung']['geraete'] as $gid) {
            $g = Pruefgeraete::find((int) $gid);
            if ($g !== null && Pruefgeraete::abgelaufen($g, (string) $kopf['pruefdatum'])) {
                $w('kalibrierung:' . (int) $g['id'], 'Kalibrierung abgelaufen: ' . Pruefgeraete::text($g) . '.');
            }
        }
        if ($leer($i['messung']['erdung']['w'] ?? '') && in_array($bewertung['erdung']['basis'] ?? '', ['generator', 'tt'], true)) {
            $w('erdung_fehlt', 'Erdungswiderstand fehlt (' . ($bewertung['erdung']['bezug'] ?? '') . ').');
        }
        if ($hinweis > 0) {
            $w('hinweise', $hinweis . ' Stromkreis(e) mit Hinweis.');
        }
        if ($entspricht !== 'ja' && $kurzOffen > 0) {
            $w('punkte_offen', $kurzOffen . ($kurzOffen === 1 ? ' Punkt' : ' Punkte') . ' der Kurzliste nicht beantwortet.');
        }
        if ($ohneQuerschnitt > 0) {
            $w('querschnitt_leer', $ohneQuerschnitt . ' gemessene(r) Stromkreis(e) ohne Querschnitt.');
        }
        if ($b['aufbau_id'] !== null) {
            $aufbau = AufbauService::find((int) $b['aufbau_id']);
            if ($aufbau !== null) {
                $offen = (int) (AufbauService::uebersicht($aufbau)['zaehler']['offen'] ?? 0);
                if ($offen > 0) {
                    $w('aufbau_offen', 'Aufbau „' . $aufbau['name'] . '“: ' . $offen . ' Position(en) noch offen.');
                }
            }
        }
        return ['fehler' => $fehler, 'warnungen' => $warnungen];
    }

    /**
     * Stand je Abschnitt für die Leiste im Formular: offene Punkte und Mängel.
     *
     * @return array<string,array{offen:int,mangel:int}>
     */
    public static function abschnitte(array $inhalt, ?array $bewertung = null): array
    {
        $bewertung ??= Grenzwerte::alle($inhalt);
        $leer = static fn (mixed $v): bool => trim((string) $v) === '';
        $kopf = $inhalt['kopf'] ?? [];
        $netz = $inhalt['netz'] ?? [];
        $kopfOffen = count(array_filter([$kopf['kunde']['firma'] ?? '', $kopf['pruefdatum'] ?? '', $kopf['ort'] ?? ''], $leer));
        $anlageOffen = count(array_filter([$kopf['anlage']['bezeichnung'] ?? '', $kopf['anlage']['aufstellort'] ?? '', $netz['form'] ?? '',
            $netz['spannung'] ?? '', $inhalt['grundlage']['grund'] ?? ''], $leer))
            + (($inhalt['grundlage']['normen'] ?? []) === [] && $leer($inhalt['grundlage']['sonstige'] ?? '') ? 1 : 0);
        $punkteOffen = self::kurzlisteOffen(is_array($inhalt['punkte'] ?? null) ? $inhalt['punkte'] : []);
        $nio = 0;
        foreach (['besichtigung', 'erprobung'] as $teil) {
            $nio += count(array_filter((array) ($inhalt['punkte'][$teil] ?? []), static fn ($z): bool => $z === 'nio'));
        }
        $messOffen = 0;
        $messMangel = ($bewertung['erdung']['auto'] ?? '') === 'mangel' ? 1 : 0;
        foreach ($bewertung['kreise'] as $bw) {
            if (in_array($bw['auto'], ['', 'fehlt', 'manuell'], true)) {
                $messOffen++;
            }
            if ($bw['auto'] === 'mangel') {
                $messMangel++;
            }
        }
        return [
            'kopf'     => ['offen' => $kopfOffen, 'mangel' => 0],
            'anlage'   => ['offen' => $anlageOffen, 'mangel' => ($bewertung['netz']['auto'] ?? '') === 'mangel' ? 1 : 0],
            'punkte'   => ['offen' => $punkteOffen, 'mangel' => $nio],
            'messung'  => ['offen' => $messOffen, 'mangel' => $messMangel],
            'ergebnis' => ['offen' => $leer($inhalt['ergebnis']['entspricht'] ?? '') ? 1 : 0, 'mangel' => 0],
        ];
    }

    /** Wie viele Punkte der Kurzliste (Besichtigung, Erprobung) sind unbeantwortet? „entf.“ zählt als beantwortet. */
    private static function kurzlisteOffen(array $punkte): int
    {
        $offen = 0;
        foreach (['besichtigung' => Katalog::besichtigung(), 'erprobung' => Katalog::erprobung()] as $teil => $katalog) {
            $antworten = is_array($punkte[$teil] ?? null) ? $punkte[$teil] : [];
            foreach ($katalog as $schluessel => $punkt) {
                if ($punkt['kurz'] && trim((string) ($antworten[$schluessel] ?? '')) === '') {
                    $offen++;
                }
            }
        }
        return $offen;
    }

    // ------------------------------------------------------------ Unterschriften

    /**
     * Die Unterschriften eines Berichts je Rolle (pruefer, kunde) –
     * Striche als Liste, Angaben als Array.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function unterschriften(int $id): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM bericht_unterschriften WHERE bericht_id = ? ORDER BY id', [$id]) as $u) {
            $u['striche'] = is_string($u['striche']) ? ((json_decode($u['striche'], true)['s'] ?? null) ?: []) : [];
            $u['angaben'] = is_string($u['angaben']) ? (json_decode($u['angaben'], true) ?: []) : [];
            $out[(string) $u['rolle']] = $u;
        }
        return $out;
    }

    /**
     * Als Prüfer unterschreiben. Geprüft wird der gespeicherte Stand
     * (abschlussPruefen – ohne Fehler, Warnungen bestätigt), dann in einer
     * Transaktion: Nummer vergeben (falls noch keine), den Inhalt mit den
     * festen Kopien einfrieren (inhalt_hash) und die erste Unterschrift der
     * Kette anlegen. Danach ändert sich der Inhalt nur noch als Korrektur.
     *
     * $u: striche (JSON), name, ort, anfrage (Idempotenzschlüssel des
     * Geräts), geraet_am (nur zur Information), warnungen (im Dialog
     * bestätigte Codes). Dieselbe Anfrage noch einmal (schwaches Netz)
     * ergibt dieselbe Unterschrift.
     *
     * @return array{ok:bool,message:string,status:int,nummer?:string,unterschrift?:int,fehler?:list<array>,warnungen?:list<array>,konflikt?:bool}
     */
    public static function prueferUnterschreiben(int $id, int $version, array $u): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        $anfrage = (string) ($u['anfrage'] ?? '');
        if (preg_match(self::ANFRAGE, $anfrage) !== 1) {
            return ['ok' => false, 'status' => 422, 'message' => 'Die Kennung der Unterschrift fehlt – bitte die Seite neu laden.'];
        }
        if (($wieder = self::wiederholung($id, 'pruefer', $anfrage)) !== null) {
            return $wieder;
        }
        if ($b['status'] !== 'entwurf' || self::eingefroren($b)) {
            return self::unterschriftDa($id, 'pruefer')
                ? ['ok' => false, 'status' => 409, 'message' => 'Der Bericht ist schon vom Prüfer unterschrieben.']
                : ['ok' => false, 'status' => 423, 'message' => $b['status'] === 'verworfen' ? 'Der Bericht ist verworfen.' : self::EINGEFROREN];
        }
        if (!self::darfPruefen($b)) {
            return ['ok' => false, 'status' => 403, 'message' => self::NUR_PRUEFER];
        }
        if ($version !== (int) $b['version']) {
            return self::konflikt($b);
        }
        if (($fehlt = self::vorgaengerFehlt($b)) !== null) {
            return $fehlt;
        }

        // Im Dialog bestätigte Warnungen gehören zum eingefrorenen Inhalt.
        $b['inhalt']['abschluss']['warnungen_bestaetigt'] = BerichtSchema::codes(array_merge(
            (array) ($b['inhalt']['abschluss']['warnungen_bestaetigt'] ?? []),
            is_array($u['warnungen'] ?? null) ? $u['warnungen'] : []
        ));
        $pruefung = self::abschlussPruefen($b);
        if ($pruefung['fehler'] !== []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Vor der Unterschrift fehlt noch etwas – siehe „Prüfen“.',
                'fehler' => $pruefung['fehler'], 'warnungen' => $pruefung['warnungen']];
        }
        if (array_filter($pruefung['warnungen'], static fn (array $w): bool => !$w['bestaetigt']) !== []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte die Warnungen ansehen und abhaken.', 'fehler' => [], 'warnungen' => $pruefung['warnungen']];
        }
        $name = Unterschrift::text($u['name'] ?? '');
        $ort = Unterschrift::text($u['ort'] ?? '');
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte den Namen in Druckbuchstaben eintragen.'];
        }
        // Der Name gehört zum angemeldeten Konto: wer am Gerät eines anderen
        // unterschreibt, schriebe sich sonst dessen Qualifikation zu. Ohne
        // vollen Namen im Konto bleibt das Feld frei (sonst stünde nur der
        // Benutzername da).
        $konto = trim((string) (Auth::user()['full_name'] ?? ''));
        if ($konto !== '' && self::nameVergleich($name) !== self::nameVergleich($konto)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Der Name passt nicht zum angemeldeten Konto („' . $konto
                . '“). Wer als Prüfer unterschreibt, meldet sich mit dem eigenen Konto an.'];
        }
        if (mb_strlen($ort) < 2) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte den Ort der Unterschrift eintragen.'];
        }
        $striche = self::strichePruefen((string) ($u['striche'] ?? ''));
        if (!$striche['ok']) {
            return $striche;
        }
        $qualifikation = self::qualifikation();
        $geraetAm = self::geraetAm($u['geraet_am'] ?? null);
        $kontoName = $konto !== '' ? $konto : (string) (Auth::user()['username'] ?? '');

        for ($versuch = 1; ; $versuch++) {
            try {
                $erg = self::transaktion(static function () use ($b, $id, $version, $name, $ort, $qualifikation, $striche, $anfrage, $geraetAm, $kontoName): ?array {
                    $jetzt = gmdate('c');
                    [$nummer, $jahr, $laufnr] = $b['nummer'] !== null
                        ? [(string) $b['nummer'], (int) $b['jahr'], (int) $b['laufnr']]
                        : self::nummerVergeben($b);
                    $b['nummer'] = $nummer;
                    $inhalt = self::inhaltBauen($b, ['user_id' => (string) Auth::id(), 'name' => $name, 'qualifikation' => $qualifikation, 'konto' => $kontoName], true);
                    $json = Kanon::json($inhalt);
                    $inhaltHash = hash('sha256', $json);
                    $n = Database::execute(
                        "UPDATE berichte SET status = 'geprueft', nummer = ?, jahr = ?, laufnr = ?, inhalt = ?, inhalt_hash = ?, pruefer_id = ?,
                         eingefroren_am = ?, version = version + 1, geaendert_von = ?, updated_at = ? WHERE id = ? AND version = ? AND status = 'entwurf'",
                        [$nummer, $jahr, $laufnr, $json, $inhaltHash, Auth::id(), $jetzt, Auth::id(), $jetzt, $id, $version]
                    );
                    if ($n !== 1) {
                        return null;
                    }
                    $uid = self::unterschriftAnlegen($id, $nummer, (int) $b['fassung'], [
                        'rolle' => 'pruefer', 'art' => 'gezeichnet', 'name' => $name, 'funktion' => $qualifikation, 'ort' => $ort,
                        'striche' => $striche['striche'], 'striche_hash' => $striche['hash'], 'inhalt_hash' => $inhaltHash,
                        'am' => $jetzt, 'geraet_am' => $geraetAm, 'anfrage' => $anfrage,
                    ]);
                    self::ereignis($id, 'pruefer_unterschrieben', ['nummer' => $nummer, 'inhalt_hash' => $inhaltHash]);
                    return ['nummer' => $nummer, 'unterschrift' => $uid];
                });
                break;
            } catch (\DomainException) {
                return self::vorgaengerFehlt($b) ?? self::konflikt(self::find($id) ?? $b);
            } catch (\PDOException $e) {
                if ($versuch >= 3 || (!self::eindeutigVerletzt($e) && !self::besetzt($e))) {
                    throw $e;
                }
                if (self::besetzt($e)) {
                    // Die Datenbank ist gerade gesperrt (zweites Tablet unterschreibt): kurz warten, noch einmal.
                    usleep(150_000 * $versuch);
                    continue;
                }
                // Dieselbe Anfrage von einem zweiten Versuch, ein anderer
                // Prüfer – oder zwei Tablets haben dieselbe Nummer gezogen:
                // dann einfach noch einmal.
                if (($wieder = self::wiederholung($id, 'pruefer', $anfrage)) !== null) {
                    return $wieder;
                }
                if (self::unterschriftDa($id, 'pruefer')) {
                    return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht ist schon vom Prüfer unterschrieben.'];
                }
            }
        }
        if ($erg === null) {
            return self::konflikt(self::find($id) ?? $b);
        }
        Audit::log('bericht.unterschrieben', 'bericht', (string) $id, ['nummer' => $erg['nummer'], 'rolle' => 'pruefer']);
        return ['ok' => true, 'status' => 200, 'message' => $erg['nummer'] . ' unterschrieben – der Bericht ist eingefroren.',
            'nummer' => $erg['nummer'], 'unterschrift' => $erg['unterschrift']];
    }

    /**
     * Darf die Prüfer-Unterschrift zurückgezogen werden? Nur solange der
     * Bericht nicht beim Kunden war: keine Kundenzeile, und seit der
     * Unterschrift weder Kundenmodus noch ein abgerufenes PDF.
     */
    public static function zurueckziehbar(array $b): bool
    {
        if ((string) $b['status'] !== 'geprueft' || self::unterschriftDa((int) $b['id'], 'kunde')) {
            return false;
        }
        $seit = (int) Database::scalar(
            "SELECT COALESCE(MAX(id), 0) FROM bericht_ereignisse WHERE bericht_id = ? AND ereignis = 'pruefer_unterschrieben'",
            [(int) $b['id']]
        );
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ? AND id > ? AND ereignis IN ('kundenmodus_start', 'pdf_abgerufen')",
            [(int) $b['id'], $seit]
        ) === 0;
    }

    /**
     * Die Prüfer-Unterschrift zurückziehen: der Bericht wird wieder Entwurf,
     * die festen Kopien und der Hash gehen, die Nummer bleibt (sie war
     * vergeben). Das Ereignis hält den alten Hash und die alte Kette fest.
     *
     * @return array{ok:bool,message:string,status:int}
     */
    public static function zurueckziehen(int $id, string $grund): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        if ($b['status'] !== 'geprueft') {
            return ['ok' => false, 'status' => 409, 'message' => 'Zurückziehen geht nur nach der Prüfer-Unterschrift und vor dem Abschluss.'];
        }
        if (!self::darfPruefen($b)) {
            return ['ok' => false, 'status' => 403, 'message' => self::NUR_PRUEFER];
        }
        $grund = Unterschrift::text($grund, 500);
        if (mb_strlen($grund) < 3) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte einen Grund angeben.'];
        }
        $beimKunden = ['ok' => false, 'status' => 409, 'message' => 'Der Bericht war schon beim Kunden (Kundenmodus oder PDF) – jetzt bitte abschließen und als Korrektur ändern.'];
        if (!self::zurueckziehbar($b)) {
            return $beimKunden;
        }
        $inhalt = self::ohneFesteKopien($b['inhalt']);
        try {
            $ok = self::transaktion(static function () use ($id, $b, $grund, $inhalt): bool {
                $kette = (string) (Database::scalar("SELECT kette_hash FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'pruefer'", [$id]) ?: '');
                // version: Kundenmodus und PDF-Abruf zählen sie hoch – was
                // seit dem Lesen dazukam, lässt dieses UPDATE ins Leere laufen.
                $n = Database::execute(
                    "UPDATE berichte SET status = 'entwurf', inhalt = ?, inhalt_hash = NULL, eingefroren_am = NULL, pruefer_id = NULL,
                     version = version + 1, geaendert_von = ?, updated_at = ? WHERE id = ? AND status = 'geprueft' AND version = ?",
                    [Kanon::json($inhalt), Auth::id(), gmdate('c'), $id, (int) $b['version']]
                );
                if ($n !== 1) {
                    return false;
                }
                // Und noch einmal in der Transaktion: war der Bericht inzwischen beim Kunden?
                if (!self::zurueckziehbar(['status' => 'geprueft', 'id' => $id])) {
                    throw new \DomainException('beim Kunden');
                }
                Database::execute("DELETE FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'pruefer'", [$id]);
                self::ereignis($id, 'zurueckgezogen', ['grund' => $grund, 'inhalt_hash' => (string) $b['inhalt_hash'], 'kette_hash' => $kette]);
                return true;
            });
        } catch (\DomainException) {
            return $beimKunden;
        }
        if (!$ok) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht wurde inzwischen geändert (etwa an den Kunden gegeben) – bitte neu laden.'];
        }
        Audit::log('bericht.zurueckgezogen', 'bericht', (string) $id, ['nummer' => $b['nummer']]);
        return ['ok' => true, 'status' => 200, 'message' => 'Unterschrift zurückgezogen – ' . $b['nummer'] . ' ist wieder ein Entwurf, die Nummer bleibt.'];
    }

    /**
     * Den Kundenmodus starten: das Gerät wird für alles außer der
     * Kundenseite dieses Berichts gesperrt (Router), bis der Prüfer sein
     * Kennwort eingibt. Ab jetzt lässt sich die Unterschrift nicht mehr
     * zurückziehen – der Kunde hat den Bericht gesehen. Nicht an einem
     * Gerät, das noch für den Kundenmodus eines anderen Kontos gesperrt ist.
     *
     * @return array{ok:bool,message:string,status:int}
     */
    public static function kundenmodusStarten(int $id): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        if ($b['status'] !== 'geprueft') {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Kunde unterschreibt nach dem Prüfer – und nur einmal.'];
        }
        if (!self::darfKundenblock($b)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Für diesen Bericht fehlt eine Freigabe mit „bearbeiten“.'];
        }
        if (Kundenmodus::fremdAmGeraet()) {
            // Sonst nähme der neue Kundenmodus dem Gerät die Sperre des
            // anderen Kontos (Kundenmodus::starten() ersetzt das Cookie), und
            // am Bericht stünde „kundenmodus_start“ – kein Zurückziehen mehr.
            return ['ok' => false, 'status' => 409,
                'message' => 'Dieses Gerät ist noch für den Kundenmodus eines anderen Kontos gesperrt – erst mit jenem Konto anmelden und entsperren.'];
        }
        if (!self::beimKundenVermerken($id, 'kundenmodus_start')) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht wurde inzwischen geändert – bitte neu laden.'];
        }
        Kundenmodus::starten($id);
        return ['ok' => true, 'status' => 200, 'message' => 'Kundenmodus gestartet.'];
    }

    /**
     * Die Vorschau eines vom Prüfer unterschriebenen Berichts wurde
     * abgerufen – der Kunde kann sie gesehen haben (danach kein Zurückziehen).
     */
    public static function pdfAbgerufen(int $id): void
    {
        self::beimKundenVermerken($id, 'pdf_abgerufen', ['stufe' => 'vorschau']);
    }

    /**
     * Kundenmodus oder PDF-Abruf festhalten: Ereignis und version + 1 in
     * einer Transaktion. Ein gleichzeitiges Zurückziehen (prüft die version)
     * scheitert dann, statt einen Bericht zurückzuziehen, den der Kunde
     * schon sieht.
     */
    private static function beimKundenVermerken(int $id, string $ereignis, array $details = []): bool
    {
        return self::transaktion(static function () use ($id, $ereignis, $details): bool {
            $n = Database::execute("UPDATE berichte SET version = version + 1 WHERE id = ? AND status = 'geprueft'", [$id]);
            if ($n !== 1) {
                return false;
            }
            self::ereignis($id, $ereignis, $details);
            return true;
        });
    }

    /**
     * Die Unterschrift des Kunden (am Gerät des Prüfers). Bestätigt wird die
     * Übergabe des Dokuments, nicht der prüftechnische Inhalt; „Bericht
     * erhalten“ ist Pflicht. Die Zeile kettet an die des Prüfers; danach ist
     * der Bericht abgeschlossen (bei einer Korrektur ist der Vorgänger dann
     * ersetzt).
     *
     * $u: striche (JSON), name, funktion, ort, bestaetigungen (Liste),
     * vorbehalte, anfrage, geraet_am, inhalt_hash (der Stand, den der Kunde
     * auf seiner Seite gesehen hat – weicht er ab, wird nichts gespeichert).
     *
     * @return array{ok:bool,message:string,status:int,unterschrift?:int}
     */
    public static function kundeUnterschreiben(int $id, array $u): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        $anfrage = (string) ($u['anfrage'] ?? '');
        if (preg_match(self::ANFRAGE, $anfrage) !== 1) {
            return ['ok' => false, 'status' => 422, 'message' => 'Die Kennung der Unterschrift fehlt – bitte die Seite neu laden.'];
        }
        if (($wieder = self::wiederholung($id, 'kunde', $anfrage)) !== null) {
            return $wieder;
        }
        if (($fehler = self::kundenblockOffen($b)) !== null) {
            return $fehler;
        }
        if (!self::darfKundenblock($b)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Für diesen Bericht fehlt eine Freigabe mit „bearbeiten“.'];
        }
        if (!hash_equals((string) $b['inhalt_hash'], (string) ($u['inhalt_hash'] ?? ''))) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht wurde inzwischen geändert – bitte neu laden und noch einmal lesen.'];
        }
        $erlaubt = array_keys(Katalog::bestaetigungen());
        $bestaetigungen = array_values(array_intersect($erlaubt, is_array($u['bestaetigungen'] ?? null) ? array_map('strval', $u['bestaetigungen']) : []));
        foreach (Katalog::bestaetigungen() as $schluessel => $bst) {
            if ($bst['pflicht'] && !in_array($schluessel, $bestaetigungen, true)) {
                return ['ok' => false, 'status' => 422, 'message' => 'Bitte „' . $bst['text'] . '“ bestätigen.'];
            }
        }
        $name = Unterschrift::text($u['name'] ?? '');
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte den Namen in Druckbuchstaben eintragen.'];
        }
        $striche = self::strichePruefen((string) ($u['striche'] ?? ''));
        if (!$striche['ok']) {
            return $striche;
        }
        $angaben = ['bestaetigungen' => $bestaetigungen, 'vorbehalte' => Unterschrift::absatz($u['vorbehalte'] ?? '', 2000)];
        $erg = self::kundenblockAnlegen($b, [
            'art' => 'gezeichnet', 'name' => $name, 'funktion' => Unterschrift::text($u['funktion'] ?? ''), 'ort' => Unterschrift::text($u['ort'] ?? ''),
            'striche' => $striche['striche'], 'striche_hash' => $striche['hash'], 'angaben' => $angaben,
            'geraet_am' => self::geraetAm($u['geraet_am'] ?? null), 'anfrage' => $anfrage,
        ], ['bestaetigungen' => $bestaetigungen]);
        if ($erg['ok']) {
            Audit::log('bericht.abgeschlossen', 'bericht', (string) $id, ['nummer' => $b['nummer'], 'kunde' => 'gezeichnet']);
        }
        return $erg;
    }

    /**
     * Ohne Unterschrift des Kunden abschließen – er ist nicht da oder
     * verweigert sie. Der Grund ist Pflicht und steht im Kundenblock (nicht
     * im Lebenslauf); das PDF druckt ihn an der Stelle der Unterschrift.
     *
     * @return array{ok:bool,message:string,status:int}
     */
    public static function ohneKunde(int $id, string $art, string $grund, ?string $inhaltHash = null): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        if (!isset(self::OHNE_KUNDE[$art])) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte wählen: Kunde nicht anwesend oder Unterschrift verweigert.'];
        }
        if (($fehler = self::kundenblockOffen($b)) !== null) {
            return $fehler;
        }
        if (!self::darfPruefen($b)) {
            return ['ok' => false, 'status' => 403, 'message' => self::NUR_PRUEFER];
        }
        if ($inhaltHash !== null && !hash_equals((string) $b['inhalt_hash'], $inhaltHash)) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht wurde inzwischen geändert – bitte neu laden.'];
        }
        $grund = Unterschrift::text($grund, 500);
        if (mb_strlen($grund) < 3) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte einen Grund angeben.'];
        }
        $erg = self::kundenblockAnlegen($b, [
            'art' => $art, 'name' => '', 'funktion' => '', 'ort' => '', 'striche' => null, 'striche_hash' => null,
            'angaben' => ['grund' => $grund], 'geraet_am' => null, 'anfrage' => bin2hex(random_bytes(16)),
        ], ['art' => $art]);
        if ($erg['ok']) {
            Audit::log('bericht.abgeschlossen', 'bericht', (string) $id, ['nummer' => $b['nummer'], 'kunde' => $art]);
            $erg['message'] = $b['nummer'] . ' ohne Unterschrift des Kunden abgeschlossen (' . self::OHNE_KUNDE[$art] . ').';
        }
        return $erg;
    }

    /**
     * Eine Korrektur anlegen: eine neue Fassung mit gleicher Nummer
     * (jahr/laufnr), Inhalt ohne die festen Kopien, gleichen Verweisen und
     * Anlagen. Der Vorgänger bleibt abgeschlossen, bis die Korrektur
     * abgeschlossen ist – dann heißt er „ersetzt“, sein PDF bleibt.
     *
     * @return array{ok:bool,message:string,status:int,id?:int}
     */
    public static function korrektur(int $id, string $grund): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        if ($b['status'] !== 'abgeschlossen') {
            return ['ok' => false, 'status' => 409, 'message' => $b['status'] === 'ersetzt'
                ? 'Dieser Bericht ist schon ersetzt – korrigiert wird die neueste Fassung.'
                : 'Eine Korrektur gibt es nur für einen abgeschlossenen Bericht.'];
        }
        if (!self::darfPruefen($b)) {
            return ['ok' => false, 'status' => 403, 'message' => self::NUR_PRUEFER];
        }
        if ($b['jahr'] === null || $b['laufnr'] === null) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht hat keine laufende Nummer – eine Korrektur geht so nicht.'];
        }
        $grund = Unterschrift::text($grund, 500);
        if (mb_strlen($grund) < 3) {
            return ['ok' => false, 'status' => 422, 'message' => 'Bitte den Grund der Korrektur angeben.'];
        }
        $offen = self::offeneKorrektur($b);
        if ($offen !== null) {
            return ['ok' => false, 'status' => 409, 'message' => 'Es gibt schon eine offene Korrektur (Fassung ' . $offen['fassung'] . ').', 'id' => (int) $offen['id']];
        }
        $fassung = (int) Database::scalar('SELECT MAX(fassung) FROM berichte WHERE jahr = ? AND laufnr = ?', [$b['jahr'], $b['laufnr']]) + 1;
        $inhalt = self::ohneFesteKopien($b['inhalt']);
        $inhalt['abschluss']['warnungen_bestaetigt'] = [];
        try {
            $neu = self::transaktion(static function () use ($b, $id, $fassung, $grund, $inhalt): int {
                $jetzt = gmdate('c');
                // Gleiche Verweise – auch die Zuordnung zum Kunden (Kundenbereich): sie gilt für die ganze Nummer.
                $spalten = ['jahr', 'laufnr', 'site_id', 'aufbau_id', 'standort_name', 'titel', 'veranstaltung', 'kunde_name', 'pruefdatum',
                    'ewx_host', 'ewx_id', 'ewx_art', 'ewx_nummer', 'ewx_am', 'ewx_kunde_ref', 'kunde_id'];
                $neu = Database::insert('berichte', array_intersect_key($b, array_flip($spalten)) + [
                    'nummer'          => null,
                    'fassung'         => $fassung,
                    'vorgaenger_id'   => $id,
                    'korrektur_grund' => $grund,
                    'status'          => 'entwurf',
                    'version'         => 1,
                    'inhalt'          => Kanon::json($inhalt),
                    'erstellt_von'    => (int) Auth::id(),
                    'geaendert_von'   => Auth::id(),
                    'created_at'      => $jetzt,
                    'updated_at'      => $jetzt,
                ]);
                foreach (Database::select('SELECT objekt_id, inventar_id FROM bericht_objekte WHERE bericht_id = ?', [$id]) as $o) {
                    Database::insert('bericht_objekte', ['bericht_id' => $neu, 'objekt_id' => $o['objekt_id'], 'inventar_id' => $o['inventar_id']]);
                }
                self::ereignis($neu, 'angelegt', ['korrektur_von' => (string) $b['nummer'], 'fassung' => (string) $fassung]);
                self::ereignis($id, 'korrektur_angelegt', ['fassung' => (string) $fassung]);
                return $neu;
            });
        } catch (\PDOException $e) {
            if (!self::eindeutigVerletzt($e)) {
                throw $e;
            }
            return ['ok' => false, 'status' => 409, 'message' => 'Es gibt schon eine offene Korrektur – bitte neu laden.'];
        }
        Audit::log('bericht.korrektur', 'bericht', (string) $neu, ['nummer' => $b['nummer'], 'fassung' => $fassung]);
        return ['ok' => true, 'status' => 200, 'id' => $neu,
            'message' => 'Korrektur zu ' . $b['nummer'] . ' angelegt (Fassung ' . $fassung . ') – bitte ändern und neu unterschreiben.'];
    }

    /** Die offene Korrektur (Entwurf oder vom Prüfer unterschrieben) zu derselben Nummer – oder null. */
    public static function offeneKorrektur(array $b): ?array
    {
        if ($b['jahr'] === null || $b['laufnr'] === null) {
            return null;
        }
        $r = Database::first(
            "SELECT id, fassung, status FROM berichte WHERE jahr = ? AND laufnr = ? AND fassung > 1 AND id <> ? AND status IN ('entwurf', 'geprueft')
             ORDER BY fassung DESC LIMIT 1",
            [(int) $b['jahr'], (int) $b['laufnr'], (int) $b['id']]
        );
        return $r === null ? null : self::zeile($r);
    }

    // ------------------------------------------------------------ Integrität

    /**
     * Ist ein eingefrorener Bericht noch, wie er unterschrieben wurde?
     * Gerechnet wird alles nach: Prüfsumme des Inhalts, je Unterschrift
     * Striche, Kette und Siegel, dazu Prüfsumme und Siegel des gespeicherten
     * PDF. Ein Siegel mit einem anderen Schlüssel (app.key gewechselt) ist
     * nicht prüfbar – das ist ein Befund, aber kein „verfälscht“.
     *
     * Eingefroren ist ein Bericht nach den Daten, nicht nach der Spalte
     * status: Gibt es eine Unterschriftszeile, einen inhalt_hash oder ein
     * PDF, wird geprüft – und ob der Status dazu passt (ein per SQL auf
     * „entwurf“ gesetzter, unterschriebener Bericht ist ein Befund).
     *
     * Mit $neuErzeugen wird das PDF aus dem eingefrorenen Inhalt noch einmal
     * erzeugt und Byte für Byte verglichen. Weicht es ab und stammt das
     * gespeicherte vom selben Erzeuger (BerichtPdf::ERZEUGER), ist das ein
     * Befund; bei einem älteren Erzeuger nur ein Hinweis – maßgeblich ist
     * dann das gespeicherte, wenn sein Siegel stimmt.
     *
     * @return array{ok:bool, eingefroren:bool, befunde:list<string>, hinweise?:list<string>}
     */
    public static function integritaet(int $id, bool $neuErzeugen = false): array
    {
        $b = Database::first('SELECT * FROM berichte WHERE id = ?', [$id]);
        if ($b === null) {
            return ['ok' => false, 'eingefroren' => false, 'befunde' => ['Diesen Bericht gibt es nicht.']];
        }
        $b = self::zeile($b);
        $zeilen = [];
        foreach (Database::select('SELECT * FROM bericht_unterschriften WHERE bericht_id = ? ORDER BY id', [$id]) as $z) {
            $zeilen[(string) $z['rolle']] = $z;
        }
        $pdf = Database::first('SELECT * FROM bericht_pdf WHERE bericht_id = ?', [$id]);
        $status = (string) $b['status'];
        $statusText = self::STATUS[$status] ?? $status;
        $inhaltHash = (string) ($b['inhalt_hash'] ?? '');
        if (!in_array($status, ['geprueft', 'abgeschlossen', 'ersetzt'], true) && $zeilen === [] && $pdf === null && $inhaltHash === '') {
            return ['ok' => true, 'eingefroren' => false, 'befunde' => []];
        }
        $befunde = [];

        // ---------------------------------------------- Status passt zu den Daten?
        if (isset($zeilen['pruefer']) && !in_array($status, ['geprueft', 'abgeschlossen', 'ersetzt'], true)) {
            $befunde[] = 'Status „' . $statusText . '“ passt nicht zur Unterschrift des Prüfers – Status nachträglich geändert?';
        }
        if ((isset($zeilen['kunde']) || $pdf !== null) && !in_array($status, ['abgeschlossen', 'ersetzt'], true)) {
            $befunde[] = 'Status „' . $statusText . '“ passt nicht zum Kundenblock bzw. zum gespeicherten PDF – Status nachträglich geändert?';
        }
        if ($status === 'ersetzt') {
            $nach = $b['ersetzt_durch_id'] !== null ? Database::first('SELECT status, vorgaenger_id FROM berichte WHERE id = ?', [(int) $b['ersetzt_durch_id']]) : null;
            if ($nach === null || (int) $nach['vorgaenger_id'] !== $id || !in_array((string) $nach['status'], ['abgeschlossen', 'ersetzt'], true)) {
                $befunde[] = 'Als „ersetzt“ geführt, aber ohne abgeschlossene Korrektur, die ihn ersetzt.';
            }
        } elseif ((int) Database::scalar("SELECT COUNT(*) FROM berichte WHERE vorgaenger_id = ? AND status IN ('abgeschlossen', 'ersetzt')", [$id]) > 0) {
            $befunde[] = 'Eine Korrektur dieses Berichts ist abgeschlossen, er selbst aber nicht als „ersetzt“ geführt.';
        }

        // ---------------------------------------------- Inhalt
        try {
            $inhalt = json_decode((string) $b['inhalt'], true, 64, JSON_THROW_ON_ERROR);
            $hash = Kanon::hash($inhalt);
        } catch (\Throwable) {
            $inhalt = [];
            $hash = '';
        }
        if ($inhaltHash === '' || !hash_equals($inhaltHash, $hash)) {
            $befunde[] = 'Inhalt verändert – die Prüfsumme des eingefrorenen Inhalts stimmt nicht.';
        }
        if ((int) $b['fassung'] > 1) {
            if (!is_array($inhalt['vorgaenger'] ?? null)) {
                $befunde[] = 'Korrektur ohne Verweis auf den Vorgänger im eingefrorenen Inhalt.';
            }
            if ($b['vorgaenger_id'] === null || Database::scalar('SELECT id FROM berichte WHERE id = ?', [(int) $b['vorgaenger_id']]) === false) {
                $befunde[] = 'Der Vorgänger dieser Korrektur fehlt.';
            }
        }

        // ---------------------------------------------- Unterschriften
        if (!isset($zeilen['pruefer'])) {
            $befunde[] = 'Die Unterschrift des Prüfers fehlt.';
        }
        if (in_array($status, ['abgeschlossen', 'ersetzt'], true) && !isset($zeilen['kunde'])) {
            $befunde[] = 'Der Kundenblock fehlt.';
        }
        $schluesselId = self::schluesselId();
        foreach ($zeilen as $rolle => $z) {
            $wer = $rolle === 'pruefer' ? 'Unterschrift des Prüfers' : 'Kundenblock';
            if (!hash_equals($inhaltHash, (string) $z['inhalt_hash'])) {
                $befunde[] = $wer . ': gehört zu einem anderen Inhalt.';
            }
            if ($z['striche'] !== null || $z['striche_hash'] !== null) {
                try {
                    $s = json_decode((string) $z['striche'], true, 8, JSON_THROW_ON_ERROR);
                    $passt = is_array($s) && array_keys($s) === ['s'] && hash_equals((string) $z['striche_hash'], Kanon::hash(['s' => $s['s']]));
                } catch (\Throwable) {
                    $passt = false;
                }
                if (!$passt) {
                    $befunde[] = $wer . ': die Striche wurden verändert.';
                }
            }
            $vorher = $rolle === 'kunde' ? (string) ($zeilen['pruefer']['kette_hash'] ?? '') : '';
            if ((string) ($z['vorher_hash'] ?? '') !== $vorher) {
                $befunde[] = $wer . ': die Kette zur Unterschrift des Prüfers ist unterbrochen.';
            }
            try {
                $kette = self::kette($z, (string) $b['nummer'], (int) $b['fassung']);
            } catch (\Throwable) {
                $kette = '';
            }
            if (!hash_equals((string) $z['kette_hash'], $kette)) {
                $befunde[] = $wer . ': Angaben verändert (die Kette stimmt nicht).';
            }
            if ((string) $z['schluessel_id'] !== $schluesselId) {
                $befunde[] = $wer . ': Siegel mit anderem Schlüssel (' . preg_replace('/[^0-9a-f]/', '', (string) $z['schluessel_id']) . '), nicht prüfbar.';
            } elseif (!hash_equals(hash_hmac('sha256', $kette, self::siegelSchluessel()), (string) $z['siegel'])) {
                $befunde[] = $wer . ': Siegel ungültig – Angaben nach der Unterschrift verändert.';
            }
        }

        // ---------------------------------------------- PDF
        $hinweise = [];
        if ($pdf !== null) {
            $bytes = base64_decode((string) $pdf['daten'], true);
            $sha = $bytes === false ? '' : hash('sha256', $bytes);
            if ($bytes === false || !hash_equals((string) $pdf['sha256'], $sha) || (string) ($b['pdf_hash'] ?? '') !== $sha || strlen($bytes) !== (int) $pdf['groesse']) {
                $befunde[] = 'PDF verändert – die Prüfsumme der gespeicherten Datei stimmt nicht.';
            } else {
                $siegelOk = false;
                $erzeuger = (string) $pdf['erzeuger'];
                if ((string) ($pdf['siegel'] ?? '') === '') {
                    $befunde[] = 'Das gespeicherte PDF hat kein Siegel.';
                } elseif ((string) $pdf['schluessel_id'] !== $schluesselId) {
                    $befunde[] = 'PDF: Siegel mit anderem Schlüssel (' . preg_replace('/[^0-9a-f]/', '', (string) $pdf['schluessel_id']) . '), nicht prüfbar.';
                } elseif (!hash_equals(self::pdfSiegel($id, (string) $b['nummer'], (int) $b['fassung'], (string) ($zeilen['kunde']['kette_hash'] ?? ''), $sha, $erzeuger),
                    (string) $pdf['siegel'])) {
                    $befunde[] = 'PDF: Siegel ungültig – die Datei wurde nach dem Abschluss ausgetauscht.';
                } else {
                    $siegelOk = true;
                }
                $beimAbschluss = self::pdfBeimAbschluss($id);
                if ($beimAbschluss !== null && !hash_equals($beimAbschluss, $sha)) {
                    $befunde[] = 'PDF: die Prüfsumme weicht von der beim Abschluss festgehaltenen ab.';
                }
                if ($neuErzeugen) {
                    try {
                        $neu = BerichtPdf::erzeugen((array) self::find($id), self::unterschriften($id), false);
                    } catch (\Throwable) {
                        $neu = '';
                    }
                    if (!hash_equals($bytes, $neu)) {
                        if ($erzeuger === BerichtPdf::ERZEUGER) {
                            $befunde[] = 'PDF: neu erzeugt weicht es vom gespeicherten ab, obwohl der Erzeuger derselbe ist (' . $erzeuger
                                . ') – Inhalt, Unterschriften oder Datei verändert.';
                        } else {
                            $hinweise[] = 'Neu erzeugt weicht das PDF vom gespeicherten ab (erzeugt mit ' . preg_replace('/[^a-z0-9.-]/', '', $erzeuger)
                                . ', jetzt ' . BerichtPdf::ERZEUGER . ')' . ($siegelOk ? ' – maßgeblich ist das gespeicherte, sein Siegel stimmt.' : '.');
                        }
                    }
                }
            }
        } elseif ($b['pdf_hash'] !== null || in_array($status, ['abgeschlossen', 'ersetzt'], true)) {
            $befunde[] = 'Das gespeicherte PDF fehlt.';
        }
        $out = ['ok' => $befunde === [], 'eingefroren' => true, 'befunde' => $befunde];
        return $neuErzeugen ? $out + ['hinweise' => $hinweise] : $out;
    }

    /** Die Prüfsumme des PDF, wie sie beim Abschluss im Lebenslauf festgehalten wurde – oder null. */
    private static function pdfBeimAbschluss(int $id): ?string
    {
        $d = Database::scalar(
            "SELECT details FROM bericht_ereignisse WHERE bericht_id = ? AND ereignis IN ('kunde_unterschrieben', 'ohne_kunde') ORDER BY id DESC LIMIT 1",
            [$id]
        );
        $details = is_string($d) ? json_decode($d, true) : null;
        return is_array($details) && is_string($details['pdf_sha256'] ?? null) ? $details['pdf_sha256'] : null;
    }

    // ------------------------------------------------------------ PDF

    /**
     * Das PDF zum Ansehen und Herunterladen. Abgeschlossen oder ersetzt: die
     * gespeicherten Bytes – genau das, was der Kunde bekommen hat. Vorher
     * eine Vorschau, jedes Mal neu erzeugt und nicht gespeichert; ein
     * Entwurf bekommt dafür die festen Kopien wie beim Einfrieren (ohne
     * Prüfer), ein unterschriebener Bericht hat sie schon.
     *
     * @return array{name:string, bytes:string, gespeichert:bool, sha256:string}
     */
    public static function pdf(array $b): array
    {
        $id = (int) $b['id'];
        $name = BerichtPdf::dateiname($b);
        if (in_array((string) $b['status'], ['abgeschlossen', 'ersetzt'], true)) {
            $bytes = self::pdfGespeichert($id);
            if ($bytes !== null) {
                return ['name' => $name, 'bytes' => $bytes, 'gespeichert' => true, 'sha256' => hash('sha256', $bytes)];
            }
            // Ohne gespeichertes PDF (Integritätsbefund): neu aus dem eingefrorenen Inhalt.
            $bytes = BerichtPdf::erzeugen($b, self::unterschriften($id), false);
            return ['name' => $name, 'bytes' => $bytes, 'gespeichert' => false, 'sha256' => hash('sha256', $bytes)];
        }
        if ((string) $b['status'] !== 'geprueft') {
            $b['inhalt'] = self::inhaltBauen($b, ['user_id' => '', 'name' => '', 'qualifikation' => '']);
        }
        $bytes = BerichtPdf::erzeugen($b, self::unterschriften($id), true);
        return ['name' => $name, 'bytes' => $bytes, 'gespeichert' => false, 'sha256' => hash('sha256', $bytes)];
    }

    /** Die gespeicherten Bytes des PDF (abgeschlossen oder ersetzt) – oder null. */
    public static function pdfGespeichert(int $id): ?string
    {
        $z = Database::first('SELECT daten FROM bericht_pdf WHERE bericht_id = ?', [$id]);
        $bytes = $z !== null ? base64_decode((string) $z['daten'], true) : false;
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** Prüfsumme des gespeicherten PDF (SHA-256) – oder null. */
    public static function pdfHash(int $id): ?string
    {
        $sha = Database::scalar('SELECT sha256 FROM bericht_pdf WHERE bericht_id = ?', [$id]);
        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /** Kurzkennung des Siegel-Schlüssels (ändert sich mit app.key). */
    public static function schluesselId(): string
    {
        return substr(hash('sha256', self::siegelSchluessel()), 0, 8);
    }

    // ------------------------------------------------------------ Verwerfen, Löschen

    /**
     * Ein Entwurf mit Nummer (die Prüfer-Unterschrift wurde zurückgezogen)
     * wird nicht gelöscht, sondern verworfen: so ist die Lücke in der
     * Nummernfolge erklärt.
     *
     * @return array{ok:bool,message:string,status?:int}
     */
    public static function verwerfen(int $id, string $grund): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return ['ok' => false, 'message' => 'Diesen Bericht gibt es nicht.'];
        }
        if ($b['status'] !== 'entwurf' || $b['nummer'] === null) {
            return ['ok' => false, 'message' => 'Verworfen wird nur ein Entwurf mit Nummer; einen Entwurf ohne Nummer bitte löschen.'];
        }
        if (!self::darfPruefen($b)) {
            return ['ok' => false, 'message' => 'Verwerfen darf nur ein Prüfer mit Freigabe „bearbeiten“ für den Standort.'];
        }
        $grund = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $grund)), 0, 500);
        if (mb_strlen($grund) < 3) {
            return ['ok' => false, 'message' => 'Bitte einen Grund angeben.'];
        }
        $ok = self::transaktion(static function () use ($id, $b, $grund): bool {
            // Nur, was in diesem Moment noch ein Entwurf ist – unterschreibt
            // gleichzeitig jemand, trifft das UPDATE nichts.
            $jetzt = gmdate('c');
            $n = Database::execute(
                "UPDATE berichte SET status = 'verworfen', aufbewahren_bis = ?, geaendert_von = ?, updated_at = ? WHERE id = ? AND status = 'entwurf'
                 AND nummer IS NOT NULL AND inhalt_hash IS NULL AND NOT EXISTS (SELECT 1 FROM bericht_unterschriften u WHERE u.bericht_id = berichte.id)",
                [self::aufbewahrenBis((string) $b['pruefdatum'], $jetzt), Auth::id(), $jetzt, $id]
            );
            if ($n !== 1) {
                return false;
            }
            self::ereignis($id, 'verworfen', ['grund' => $grund]);
            return true;
        });
        if (!$ok) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht wurde inzwischen geändert (etwa unterschrieben) – bitte neu laden.'];
        }
        Audit::log('bericht.verworfen', 'bericht', (string) $id, ['nummer' => $b['nummer']]);
        return ['ok' => true, 'status' => 200, 'message' => $b['nummer'] . ' verworfen.'];
    }

    /** Nur ein Entwurf ohne Nummer lässt sich löschen – samt Objekten und Ereignissen. */
    public static function loeschen(int $id): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return ['ok' => false, 'message' => 'Diesen Bericht gibt es nicht.'];
        }
        if ($b['status'] !== 'entwurf') {
            return ['ok' => false, 'message' => 'Nur Entwürfe lassen sich löschen.'];
        }
        if ($b['nummer'] !== null) {
            return ['ok' => false, 'message' => 'Ein Entwurf mit Nummer wird nicht gelöscht, nur verworfen – so bleibt die Lücke in der Nummernfolge erklärt.'];
        }
        if (!self::darfBearbeiten($b)) {
            return ['ok' => false, 'message' => 'Für diesen Bericht fehlt eine Freigabe mit „bearbeiten“.'];
        }
        self::transaktion(static fn () => self::alleZeilenLoeschen($id));
        Audit::log('bericht.geloescht', 'bericht', (string) $id, ['id' => $id]);
        return ['ok' => true, 'message' => 'Entwurf #' . $id . ' gelöscht.'];
    }

    // ------------------------------------------------------------ Ereignisse, letzte Prüfung

    public static function ereignis(int $id, string $ereignis, array $details = []): void
    {
        $user = Auth::user();
        Database::insert('bericht_ereignisse', [
            'bericht_id' => $id,
            'ereignis'   => mb_substr($ereignis, 0, 32),
            'am'         => gmdate('c'),
            'user_id'    => $user['id'] ?? null,
            'username'   => (string) ($user['username'] ?? 'system'),
            'details'    => $details === [] ? null : Kanon::json($details),
        ]);
    }

    /** Der letzte abgeschlossene (sichtbare) Bericht, der diese Anlage abdeckt. */
    public static function letzteAnObjekt(int $objektId): ?array
    {
        return self::letzte('bo.objekt_id = ?', [$objektId]);
    }

    /** Der letzte abgeschlossene (sichtbare) Bericht, in dem dieses Inventarstück geprüft wurde. */
    public static function letzteAnInventar(int $inventarId): ?array
    {
        return self::letzte('bo.inventar_id = ?', [$inventarId]);
    }

    // ------------------------------------------------------------ Kundenbereich

    /**
     * Den Bericht einem Kunden des Kundenbereichs geben – oder ihn dort
     * herausnehmen ($kundeId null, wirkt sofort). Gilt für die ganze Nummer
     * (alle Fassungen, jahr/laufnr), ein Entwurf ohne Nummer nur für sich.
     * Die Spalte liegt außerhalb von inhalt und inhalt_hash: auch ein
     * eingefrorener Bericht lässt sich so zuordnen und korrigieren, ohne dass
     * sich Inhalt, Siegel oder PDF ändern. Der Kunde sieht ihn erst
     * abgeschlossen (sichtbarFuerKunde); zugeordnet wird nie automatisch.
     *
     * Passt der Kunde im Bericht nicht zum gewählten (KundenService::namePasst),
     * erst nach ausdrücklicher Bestätigung: eine Fehlzuordnung gäbe einem
     * Veranstalter das Protokoll eines anderen. Ereignisse und Audit nur mit IDs.
     *
     * @return array{ok:bool,message:string,status:int,bestaetigen?:bool}
     */
    public static function kundeZuordnen(int $id, ?int $kundeId, bool $bestaetigt = false): array
    {
        $b = self::find($id);
        if ($b === null || !self::sichtbar($b)) {
            return self::nichtDa();
        }
        if (!Auth::can('kunden.manage')) {
            return ['ok' => false, 'status' => 403, 'message' => 'Berichte ordnet zu, wer Kunden pflegen darf.'];
        }
        if ($b['status'] === 'verworfen') {
            return ['ok' => false, 'status' => 409, 'message' => 'Ein verworfener Bericht kommt nicht in den Kundenbereich.'];
        }
        $kunde = null;
        if ($kundeId !== null) {
            $kunde = KundenService::find($kundeId);
            if ($kunde === null) {
                return ['ok' => false, 'status' => 404, 'message' => 'Diesen Kunden gibt es nicht.'];
            }
        }
        $zeilen = $b['jahr'] !== null && $b['laufnr'] !== null
            ? Database::select('SELECT id, kunde_id FROM berichte WHERE jahr = ? AND laufnr = ? ORDER BY fassung', [$b['jahr'], $b['laufnr']])
            : [['id' => $id, 'kunde_id' => $b['kunde_id']]];
        $aendern = array_values(array_filter($zeilen, static fn (array $z): bool => ($z['kunde_id'] !== null ? (int) $z['kunde_id'] : null) !== $kundeId));
        if ($aendern === []) {
            return ['ok' => true, 'status' => 200, 'message' => $kunde === null ? 'Der Bericht ist keinem Kunden zugeordnet.' : 'Der Bericht ist diesem Kunden schon zugeordnet.'];
        }
        if ($kunde !== null && !$bestaetigt && !KundenService::namePasst((string) $b['kunde_name'], (string) $kunde['name'])) {
            $imBericht = trim((string) $b['kunde_name']);
            return ['ok' => false, 'status' => 409, 'bestaetigen' => true,
                'message' => ($imBericht === '' ? 'Im Bericht steht kein Kunde' : 'Im Bericht steht „' . $imBericht . '“') . ', zugeordnet werden soll „'
                    . $kunde['name'] . '“. Bitte bestätigen, dass es derselbe Kunde ist.'];
        }
        $alt = $b['kunde_id'];
        self::transaktion(static function () use ($aendern, $kundeId): void {
            foreach ($aendern as $z) {
                $vorher = $z['kunde_id'] !== null ? (int) $z['kunde_id'] : null;
                Database::execute('UPDATE berichte SET kunde_id = ? WHERE id = ?', [$kundeId, (int) $z['id']]);
                self::ereignis((int) $z['id'], $kundeId === null ? 'kunde_entfernt' : 'kunde_zugeordnet',
                    ['kunde_id' => (string) ($kundeId ?? $vorher)] + ($kundeId !== null && $vorher !== null ? ['vorher' => (string) $vorher] : []));
            }
        });
        KundenService::vergessen();
        Audit::log('bericht.kunde', 'bericht', (string) $id, ['bericht_id' => $id, 'alt' => $alt, 'neu' => $kundeId]);
        if ($kunde === null) {
            return ['ok' => true, 'status' => 200, 'message' => 'Der Bericht steht nicht mehr im Kundenbereich.'];
        }
        return ['ok' => true, 'status' => 200, 'message' => 'Dem Kunden „' . $kunde['name'] . '“ zugeordnet – '
            . (in_array($b['status'], ['abgeschlossen', 'ersetzt'], true) ? 'er sieht den Bericht jetzt im Kundenbereich.' : 'im Kundenbereich erscheint er nach dem Abschluss.')];
    }

    /**
     * Die Berichte, die ein Kunde im Kundenbereich sieht – ohne Inhalt,
     * neueste Prüfung zuerst: nur ihm ausdrücklich zugeordnete (kunde_id),
     * nur abgeschlossene und ersetzte, mit der Prüfsumme des gespeicherten
     * PDF (oder null). Unabhängig von Standort, Freigaben und Prüfdatum: ein
     * Gelände hat mehrere Veranstalter, und das Dokument überdauert die
     * Location-Freigabe. Ob der Kunde noch aktiv ist, prüft KundenSicht.
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerKunde(int $kundeId, ?int $siteId = null): array
    {
        $sql = "SELECT b.id, b.nummer, b.jahr, b.laufnr, b.fassung, b.vorgaenger_id, b.ersetzt_durch_id, b.status, b.site_id, b.standort_name,
                       b.titel, b.veranstaltung, b.pruefdatum, b.abgeschlossen_am, b.kunde_id, p.sha256 AS pdf_sha256
                FROM berichte b LEFT JOIN bericht_pdf p ON p.bericht_id = b.id
                WHERE b.kunde_id = ? AND b.status IN ('abgeschlossen', 'ersetzt')";
        $werte = [$kundeId];
        if ($siteId !== null) {
            $sql .= ' AND b.site_id = ?';
            $werte[] = $siteId;
        }
        $out = [];
        foreach (Database::select($sql . ' ORDER BY b.pruefdatum DESC, b.id DESC', $werte) as $b) {
            $b = self::zeile($b);
            if (self::sichtbarFuerKunde($b, $kundeId)) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /**
     * Sieht dieser Kunde (Kundenbereich) den Bericht? Genau dann, wenn er
     * ihm zugeordnet und abgeschlossen oder ersetzt ist – nie über Standort
     * und Datenfenster (§3 Sichtbarkeit). Das PDF dazu nur, wenn es
     * gespeichert ist, nie eine Vorschau.
     */
    public static function sichtbarFuerKunde(array $b, int $kundeId): bool
    {
        return $kundeId > 0 && ($b['kunde_id'] ?? null) !== null && (int) $b['kunde_id'] === $kundeId
            && in_array((string) ($b['status'] ?? ''), ['abgeschlossen', 'ersetzt'], true);
    }

    /**
     * Der Kunde hat das PDF im Kundenbereich geladen ($pdf) bzw. den Bericht
     * dort angesehen – im Lebenslauf ohne seinen Namen (der Lebenslauf bleibt
     * so lange wie der Bericht), nur mit der Konto-ID; wer genau, steht in
     * kunden_abrufe (geht mit dem Kunden).
     */
    public static function imKundenbereich(int $id, int $userId, bool $pdf): void
    {
        Database::insert('bericht_ereignisse', [
            'bericht_id' => $id,
            'ereignis'   => $pdf ? 'pdf_kunde' : 'ansicht_kunde',
            'am'         => gmdate('c'),
            'user_id'    => $userId,
            'username'   => 'kundenbereich',
            'details'    => Kanon::json(['user_id' => (string) $userId]),
        ]);
    }

    // ------------------------------------------------------------ Lösch-Haken

    /**
     * Ein Kunde des Kundenbereichs wird gelöscht: seine Berichte bleiben
     * (Aufbewahrung), nur ohne Zuordnung – im Lebenslauf mit der ID.
     */
    public static function kundeEntfernt(int $kundeId): void
    {
        foreach (Database::select('SELECT id FROM berichte WHERE kunde_id = ?', [$kundeId]) as $row) {
            self::ereignis((int) $row['id'], 'kunde_entfernt', ['kunde_id' => (string) $kundeId]);
        }
        Database::execute('UPDATE berichte SET kunde_id = NULL WHERE kunde_id = ?', [$kundeId]);
    }

    /**
     * Ein Standort wird gelöscht: Entwürfe ohne Nummer gehen mit, alle
     * anderen Berichte bleiben (mit dem Namen des Standorts in
     * `standort_name`) – sichtbar dann nur noch mit „Alle Standorte“, auch
     * nicht mehr für den Ersteller (standort_entfernt).
     */
    public static function standortEntfernt(int $siteId): void
    {
        foreach (Database::select("SELECT id FROM berichte WHERE site_id = ? AND status = 'entwurf' AND nummer IS NULL AND inhalt_hash IS NULL", [$siteId]) as $row) {
            if (!self::eingefroren(['id' => (int) $row['id'], 'status' => 'entwurf'])) {
                self::alleZeilenLoeschen((int) $row['id']);
            }
        }
        $name = (string) (Database::scalar('SELECT name FROM sites WHERE id = ?', [$siteId]) ?: '');
        if ($name !== '') {
            Database::execute("UPDATE berichte SET standort_name = ? WHERE site_id = ? AND standort_name = ''", [mb_substr($name, 0, 190), $siteId]);
        }
        foreach (Database::select('SELECT id FROM berichte WHERE site_id = ?', [$siteId]) as $row) {
            self::ereignis((int) $row['id'], 'standort_entfernt', ['standort' => (string) $siteId]);
        }
        Database::execute('UPDATE berichte SET site_id = NULL, standort_entfernt = 1 WHERE site_id = ?', [$siteId]);
    }

    public static function aufbauEntfernt(int $aufbauId): void
    {
        Database::execute('UPDATE berichte SET aufbau_id = NULL WHERE aufbau_id = ?', [$aufbauId]);
    }

    /** Die Anlage ist weg, der Bericht bleibt – sein Inhalt nennt sie weiter. */
    public static function objektEntfernt(int $objektId): void
    {
        Database::execute('UPDATE bericht_objekte SET objekt_id = NULL WHERE objekt_id = ?', [$objektId]);
    }

    public static function inventarEntfernt(int $inventarId): void
    {
        Database::execute('UPDATE bericht_objekte SET inventar_id = NULL WHERE inventar_id = ?', [$inventarId]);
    }

    // ------------------------------------------------------------ Aufbewahrung

    /**
     * Berichte über der Aufbewahrungsfrist (abgeschlossen, ersetzt,
     * verworfen) – nur ganze Nummernfamilien (alle Fassungen einer Nummer):
     * solange eine Fassung noch offen ist (Entwurf, vom Prüfer
     * unterschrieben) oder ihre Frist nicht abgelaufen ist, bleibt die ganze
     * Familie. Sonst zeigte eine Korrektur ins Leere. Nur Fristen der Form
     * JJJJ-MM-TT zählen.
     *
     * @return list<array<string,mixed>>
     */
    public static function faellig(): array
    {
        $heute = (new \DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d');
        $abgelaufen = static fn (array $r): bool => in_array((string) $r['status'], ['abgeschlossen', 'ersetzt', 'verworfen'], true)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['aufbewahren_bis'] ?? '')) === 1 && (string) $r['aufbewahren_bis'] < $heute;
        $out = [];
        $familien = [];
        foreach (Database::select(
            "SELECT id, nummer, jahr, laufnr, fassung, status, pruefdatum, aufbewahren_bis FROM berichte
             WHERE status IN ('abgeschlossen', 'ersetzt', 'verworfen') AND aufbewahren_bis IS NOT NULL AND aufbewahren_bis < ? ORDER BY aufbewahren_bis, id",
            [$heute]
        ) as $r) {
            if (!$abgelaufen($r)) {
                continue;
            }
            if ($r['jahr'] === null || $r['laufnr'] === null) {
                $out[] = $r;
                continue;
            }
            $schluessel = (int) $r['jahr'] . '-' . (int) $r['laufnr'];
            if (!isset($familien[$schluessel])) {
                $familie = Database::select('SELECT id, status, aufbewahren_bis FROM berichte WHERE jahr = ? AND laufnr = ?', [(int) $r['jahr'], (int) $r['laufnr']]);
                $familien[$schluessel] = array_filter($familie, static fn (array $f): bool => !$abgelaufen($f)) === [];
            }
            if ($familien[$schluessel]) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Einen Bericht nach Ablauf der Frist endgültig löschen – samt
     * Unterschriften, PDF, Objekten und Ereignissen, und mit ihm alle
     * Fassungen seiner Nummer (in einer Transaktion). Nur, was faellig()
     * nennt; sonst wird nichts gelöscht.
     *
     * @return list<int> die gelöschten Berichte (leer: nicht fällig)
     */
    public static function aufbewahrungLoeschen(int $id): array
    {
        $faellig = array_map(static fn (array $r): int => (int) $r['id'], self::faellig());
        if (!in_array($id, $faellig, true)) {
            return [];
        }
        $b = Database::first('SELECT jahr, laufnr FROM berichte WHERE id = ?', [$id]);
        $ids = $b !== null && $b['jahr'] !== null && $b['laufnr'] !== null
            ? array_map(static fn (array $r): int => (int) $r['id'], Database::select('SELECT id FROM berichte WHERE jahr = ? AND laufnr = ? ORDER BY fassung DESC', [(int) $b['jahr'], (int) $b['laufnr']]))
            : [$id];
        if (array_diff($ids, $faellig) !== []) {
            return [];
        }
        $nummern = [];
        foreach ($ids as $bid) {
            $nummern[$bid] = Database::scalar('SELECT nummer FROM berichte WHERE id = ?', [$bid]);
        }
        self::transaktion(static function () use ($ids): void {
            foreach ($ids as $bid) {
                self::alleZeilenLoeschen($bid);
            }
        });
        foreach ($ids as $bid) {
            Audit::log('bericht.geloescht', 'bericht', (string) $bid, ['nummer' => is_string($nummern[$bid]) ? $nummern[$bid] : null]);
        }
        return $ids;
    }

    /**
     * Was die tägliche Wartung zu den Berichten meldet – sie löscht keine:
     * Berichte über der Aufbewahrungsfrist und lange liegende Entwürfe.
     *
     * @return list<string>
     */
    public static function wartungHinweise(): array
    {
        $out = [];
        $n = count(self::faellig());
        if ($n > 0) {
            $out[] = $n . ($n === 1 ? ' Übergabebericht' : ' Übergabeberichte') . ' über der Aufbewahrungsfrist (bin/power berichte:aufbewahrung).';
        }
        $m = (int) Database::scalar(
            "SELECT COUNT(*) FROM berichte WHERE status = 'entwurf' AND COALESCE(updated_at, created_at) < ?",
            [gmdate('c', time() - self::ENTWURF_LIEGT_TAGE * 86400)]
        );
        if ($m > 0) {
            $out[] = $m . ($m === 1 ? ' Entwurf' : ' Entwürfe') . ' eines Übergabeberichts seit ' . self::ENTWURF_LIEGT_TAGE . ' Tagen unverändert.';
        }
        return $out;
    }

    // ------------------------------------------------------------ Kommandozeile

    /**
     * Ein Bericht über seine Nummer (`UB-2026-0042`, eine Korrektur
     * `UB-2026-0042-K1`); mit $fassung eine andere Fassung derselben Nummer.
     */
    public static function nachNummer(string $nummer, ?int $fassung = null): ?array
    {
        $b = Database::first('SELECT id, jahr, laufnr, fassung FROM berichte WHERE nummer = ? ORDER BY fassung LIMIT 1', [trim($nummer)]);
        if ($b !== null && $fassung !== null && $fassung !== (int) $b['fassung']) {
            $b = $b['jahr'] === null || $b['laufnr'] === null ? null
                : Database::first('SELECT id FROM berichte WHERE jahr = ? AND laufnr = ? AND fassung = ?', [(int) $b['jahr'], (int) $b['laufnr'], $fassung]);
        }
        return $b === null ? null : self::find((int) $b['id']);
    }

    /**
     * Alle eingefrorenen Berichte (vom Prüfer unterschrieben, abgeschlossen,
     * ersetzt) – nur Nummer, Fassung und Status, ohne Inhalt.
     *
     * @return list<array{id:int,nummer:string,fassung:int,status:string}>
     */
    public static function eingefrorene(): array
    {
        return array_map(static fn (array $b): array => ['id' => (int) $b['id'], 'nummer' => (string) $b['nummer'], 'fassung' => (int) $b['fassung'], 'status' => (string) $b['status']],
            Database::select("SELECT b.id, b.nummer, b.fassung, b.status FROM berichte b WHERE b.status IN ('geprueft', 'abgeschlossen', 'ersetzt')
                OR b.inhalt_hash IS NOT NULL OR EXISTS (SELECT 1 FROM bericht_unterschriften u WHERE u.bericht_id = b.id)
                OR EXISTS (SELECT 1 FROM bericht_pdf p WHERE p.bericht_id = b.id) ORDER BY b.jahr, b.laufnr, b.fassung, b.id"));
    }

    /**
     * 31.12. des Jahres „Aufbewahrung + das spätere von Prüfdatum und
     * Abschluss“ (Jahr in der Zeitzone der Installation). Die Fristen, auf
     * die sich die zehn Jahre stützen, beginnen mit Übergabe bzw. Absendung –
     * ein vertipptes oder altes Prüfdatum macht einen frischen Bericht so
     * nicht gleich löschfällig.
     */
    public static function aufbewahrenBis(string $pruefdatum, ?string $abgeschlossenAm = null): string
    {
        $pruefjahr = preg_match('/^(\d{4})-\d{2}-\d{2}$/', $pruefdatum, $m) === 1 ? (int) $m[1] : 0;
        $ts = $abgeschlossenAm !== null ? Zeit::ts($abgeschlossenAm) : null;
        $abschlussjahr = (int) (new \DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(Zeit::zone())->format('Y');
        $jahr = min(9999 - 30, max($pruefjahr, $abschlussjahr));
        return sprintf('%04d-12-31', $jahr + (int) Einstellungen::wert('berichte.aufbewahrung_jahre'));
    }

    // ------------------------------------------------------------ intern

    /** Zugang zu einem Bericht für einen Zweck – null = in Ordnung, sonst die Antwort. */
    private static function zugang(?array $b, string $zweck): ?array
    {
        if ($b === null || !self::sichtbar($b)) {
            return ['ok' => false, 'status' => 404, 'message' => 'Diesen Bericht gibt es nicht.'];
        }
        if ($zweck === 'bearbeiten') {
            if ($b['status'] !== 'entwurf' || self::eingefroren($b)) {
                return ['ok' => false, 'status' => 423, 'message' => $b['status'] === 'verworfen' ? 'Der Bericht ist verworfen.' : self::EINGEFROREN];
            }
            if (!self::darfBearbeiten($b)) {
                return ['ok' => false, 'status' => 403, 'message' => 'Für diesen Bericht fehlt eine Freigabe mit „bearbeiten“.'];
            }
        }
        return null;
    }

    private static function konflikt(array $b): array
    {
        return [
            'ok'            => false,
            'status'        => 409,
            'konflikt'      => true,
            'message'       => 'Auf einem anderen Gerät geändert – bitte neu laden.',
            'version'       => (int) $b['version'],
            'geaendert_von' => (string) ($b['geaendert_von_name'] ?? ''),
            'geaendert_am'  => (string) ($b['updated_at'] ?? ''),
        ];
    }

    /**
     * Die Anlagen aus der Anfrage, jede einzeln geprüft: vorhanden, kein
     * Standard-Verteiler, auf diesem Standort, sichtbar, elektrisch (kein
     * Tank). Einspeisungen zuerst.
     *
     * @param list<int|string> $ids
     * @return list<array<string,mixed>>|string  Anlagen oder die Meldung
     */
    private static function objektePruefen(?int $siteId, array $ids): array|string
    {
        $out = [];
        $gesehen = [];
        foreach ($ids as $roh) {
            $id = is_int($roh) || (is_string($roh) && ctype_digit($roh)) ? (int) $roh : 0;
            if ($id <= 0 || isset($gesehen[$id])) {
                continue;
            }
            $gesehen[$id] = true;
            if ($siteId === null) {
                return 'Ein Bericht ohne Standort hat keine Anlagen.';
            }
            $o = AnlagenService::find($id);
            if ($o === null || (int) $o['ist_vorlage'] === 1 || $o['site_id'] === null || (int) $o['site_id'] !== $siteId || !Zugriff::siehtObjekt($o)) {
                return 'Eine der gewählten Anlagen gehört nicht zu diesem Standort.';
            }
            // Geprüft wird die elektrische Anlage – ein Tank hat weder
            // Stromkreise noch Schutzorgane.
            if (!AnlagenService::elektrisch((string) $o['art'])) {
                return '„' . $o['name'] . '“ ist ein Tank – ein Übergabebericht gilt der elektrischen Anlage.';
            }
            $out[] = $o;
        }
        usort($out, static fn (array $a, array $b): int => (int) !in_array($a['art'], self::QUELLEN, true) <=> (int) !in_array($b['art'], self::QUELLEN, true));
        return $out;
    }

    /**
     * bericht_objekte ergänzen: die gewählten Anlagen und die der Stromkreise,
     * je mit dem Inventarstück, das dort gerade im Einsatz ist. Entfernt wird
     * nichts – was einmal gewählt war, gehört zum Bericht.
     *
     * @param list<int> $gewaehlt
     */
    private static function objekteAbgleichen(int $berichtId, array $inhalt, array $gewaehlt = []): void
    {
        $vorhanden = [];
        foreach (Database::select('SELECT objekt_id FROM bericht_objekte WHERE bericht_id = ? AND objekt_id IS NOT NULL', [$berichtId]) as $r) {
            $vorhanden[(int) $r['objekt_id']] = true;
        }
        $soll = [];
        foreach ($gewaehlt as $oid) {
            $soll[$oid] = null;
        }
        foreach ($inhalt['kreise'] ?? [] as $k) {
            $oid = (int) ($k['objekt_id'] ?? 0);
            if ($oid > 0 && !array_key_exists($oid, $soll)) {
                $soll[$oid] = (int) ($k['inventar_id'] ?? 0) ?: null;
            }
        }
        foreach ($soll as $oid => $inventarId) {
            if (isset($vorhanden[$oid]) || Database::scalar('SELECT id FROM objekte WHERE id = ?', [$oid]) === false) {
                continue;
            }
            if ($inventarId === null) {
                $einsatz = Vorbelegung::einsatz($oid);
                $inventarId = $einsatz !== null ? (int) $einsatz['inventar_id'] : null;
            }
            Database::insert('bericht_objekte', ['bericht_id' => $berichtId, 'objekt_id' => $oid, 'inventar_id' => $inventarId]);
        }
    }

    private static function letzte(string $bedingung, array $werte): ?array
    {
        $rows = Database::select(
            "SELECT b.id, b.nummer, b.fassung, b.status, b.site_id, b.standort_entfernt, b.erstellt_von, b.pruefdatum, b.abgeschlossen_am, b.titel FROM berichte b
             WHERE b.status = 'abgeschlossen' AND b.id IN (SELECT bo.bericht_id FROM bericht_objekte bo WHERE {$bedingung})
             ORDER BY b.abgeschlossen_am DESC, b.id DESC LIMIT 20",
            $werte
        );
        foreach ($rows as $b) {
            $b = self::zeile($b);
            if (self::sichtbar($b)) {
                return $b;
            }
        }
        return null;
    }

    private static function alleZeilenLoeschen(int $id): void
    {
        foreach (['bericht_unterschriften', 'bericht_pdf', 'bericht_objekte', 'bericht_ereignisse', 'kundenmodus_geraete'] as $tabelle) {
            Database::execute("DELETE FROM {$tabelle} WHERE bericht_id = ?", [$id]);
        }
        // Die Abrufe im Kundenbereich gehen mit dem Dokument (Audit-Einträge bleiben ihre 400 Tage).
        Database::execute("DELETE FROM kunden_abrufe WHERE art IN ('bericht', 'ansicht') AND ziel_id = ?", [$id]);
        Database::delete('berichte', $id);
    }

    // ------------------------------------------------------------ intern: Unterschriften

    private static function nichtDa(): array
    {
        return ['ok' => false, 'status' => 404, 'message' => 'Diesen Bericht gibt es nicht.'];
    }

    /** Die Kennung eines Kunden in Eventworx – nur eine ObjectId, sonst ''. */
    private static function kundeRef(mixed $wert): string
    {
        return is_string($wert) && preg_match(Eventworx::OID, $wert) === 1 ? $wert : '';
    }

    private static function unterschriftDa(int $id, string $rolle): bool
    {
        return Database::scalar('SELECT id FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = ?', [$id, $rolle]) !== false;
    }

    /**
     * Dieselbe Anfrage noch einmal (die Antwort ging bei schwachem Netz
     * verloren): dieselbe Unterschrift, kein zweiter Eintrag. Dieselbe
     * Kennung in einem anderen Zusammenhang ist ein Fehler (409).
     */
    private static function wiederholung(int $id, string $rolle, string $anfrage): ?array
    {
        $z = Database::first('SELECT id, bericht_id, rolle FROM bericht_unterschriften WHERE anfrage = ?', [$anfrage]);
        if ($z === null) {
            return null;
        }
        if ((int) $z['bericht_id'] !== $id || (string) $z['rolle'] !== $rolle) {
            return ['ok' => false, 'status' => 409, 'message' => 'Diese Kennung gehört zu einer anderen Unterschrift – bitte die Seite neu laden.'];
        }
        $nummer = (string) (Database::scalar('SELECT nummer FROM berichte WHERE id = ?', [$id]) ?: '');
        return ['ok' => true, 'status' => 200, 'wiederholt' => true, 'nummer' => $nummer, 'unterschrift' => (int) $z['id'],
            'message' => ($nummer !== '' ? $nummer . ': ' : '') . 'schon unterschrieben.'];
    }

    /** Striche prüfen (Unterschrift::pruefen) und gegen Kopien: dieselben Striche gibt es nur einmal. */
    private static function strichePruefen(string $json): array
    {
        $p = Unterschrift::pruefen($json);
        if (!$p['ok']) {
            return ['ok' => false, 'status' => 422, 'message' => $p['message']];
        }
        $hash = Kanon::hash(['s' => $p['striche']]);
        if (Database::scalar('SELECT id FROM bericht_unterschriften WHERE striche_hash = ?', [$hash]) !== false) {
            return ['ok' => false, 'status' => 422, 'message' => 'Diese Unterschrift gibt es schon – bitte neu unterschreiben.'];
        }
        return ['ok' => true, 'striche' => $p['striche'], 'hash' => $hash];
    }

    /** Die Uhr des Geräts – nur zur Information, nur als ISO-Zeit. */
    private static function geraetAm(mixed $wert): ?string
    {
        if (!is_string($wert) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/', $wert) !== 1) {
            return null;
        }
        $ts = Zeit::ts($wert);
        return $ts === null ? null : gmdate('c', $ts);
    }

    private static function eindeutigVerletzt(\PDOException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }

    /**
     * Gerade gesperrt? SQLite: SQLITE_BUSY (5) / SQLITE_LOCKED (6);
     * MySQL: Lock wait timeout (1205) / Deadlock (1213). Dann lohnt ein
     * zweiter Versuch – es ist nichts gespeichert.
     */
    private static function besetzt(\PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return Database::driver() === 'sqlite' ? in_array($code, [5, 6], true) || str_contains($e->getMessage(), 'database is locked')
            : in_array($code, [1205, 1213], true);
    }

    /** Name zum Vergleich: Groß/klein, Leerzeichen und Umlaut-Schreibweise egal. */
    private static function nameVergleich(string $name): string
    {
        $n = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        return strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    /** Eine Korrektur, deren Vorgänger fehlt, wird nicht eingefroren (409) – sonst null. */
    private static function vorgaengerFehlt(array $b): ?array
    {
        if ((int) $b['fassung'] <= 1) {
            return null;
        }
        if ($b['vorgaenger_id'] === null || Database::scalar('SELECT id FROM berichte WHERE id = ?', [(int) $b['vorgaenger_id']]) === false) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Vorgänger dieser Korrektur fehlt (etwa nach Ablauf der Aufbewahrungsfrist gelöscht) – so lässt sie sich nicht einfrieren.'];
        }
        return null;
    }

    private static function kundenblockOffen(array $b): ?array
    {
        if (self::unterschriftDa((int) $b['id'], 'kunde')) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Kundenblock ist schon ausgefüllt.'];
        }
        if ($b['status'] !== 'geprueft') {
            return ['ok' => false, 'status' => 409, 'message' => $b['status'] === 'entwurf'
                ? 'Erst unterschreibt der Prüfer, dann der Kunde.' : 'Der Bericht ist nicht (mehr) zur Unterschrift offen.'];
        }
        return null;
    }

    /**
     * Den Kundenblock anlegen und den Bericht abschließen – in einer
     * Transaktion. Bei einer Korrektur ist der Vorgänger danach ersetzt.
     */
    private static function kundenblockAnlegen(array $b, array $z, array $details): array
    {
        $id = (int) $b['id'];
        for ($versuch = 1; ; $versuch++) {
            try {
                return self::kundenblockVersuch($b, $z, $details);
            } catch (\PDOException $e) {
                if ($versuch >= 3 || !self::besetzt($e)) {
                    throw $e;
                }
                // Gesperrt (zweites Gerät schreibt gerade): kurz warten, noch einmal.
                usleep(150_000 * $versuch);
                $neu = self::find($id);
                if ($neu === null) {
                    return self::nichtDa();
                }
                $b = $neu;
            }
        }
    }

    private static function kundenblockVersuch(array $b, array $z, array $details): array
    {
        $id = (int) $b['id'];
        try {
            $uid = self::transaktion(static function () use ($b, $id, $z, $details): ?int {
                $pruefer = Database::first("SELECT kette_hash FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'pruefer'", [$id]);
                if ($pruefer === null) {
                    return null;
                }
                $jetzt = gmdate('c');
                // Nur der Stand, den der Kunde gesehen hat (inhalt_hash) – nach
                // einem Zurückziehen und neuer Unterschrift wäre es ein anderer.
                $n = Database::execute(
                    "UPDATE berichte SET status = 'abgeschlossen', abgeschlossen_am = ?, aufbewahren_bis = ?, version = version + 1,
                     geaendert_von = ?, updated_at = ? WHERE id = ? AND status = 'geprueft' AND inhalt_hash = ?",
                    [$jetzt, self::aufbewahrenBis((string) $b['pruefdatum'], $jetzt), Auth::id(), $jetzt, $id, (string) $b['inhalt_hash']]
                );
                if ($n !== 1) {
                    return null;
                }
                $uid = self::unterschriftAnlegen($id, (string) $b['nummer'], (int) $b['fassung'], $z + [
                    'rolle' => 'kunde', 'inhalt_hash' => (string) $b['inhalt_hash'], 'vorher_hash' => (string) $pruefer['kette_hash'], 'am' => $jetzt,
                ]);
                // Das übergebene PDF – in derselben Transaktion: ohne PDF kein Abschluss.
                $pdf = self::pdfAblegen($id, $jetzt);
                if ((int) $b['fassung'] > 1 && $b['vorgaenger_id'] !== null) {
                    $ersetzt = Database::execute(
                        "UPDATE berichte SET status = 'ersetzt', ersetzt_durch_id = ?, updated_at = ? WHERE id = ? AND status = 'abgeschlossen'",
                        [$id, $jetzt, (int) $b['vorgaenger_id']]
                    );
                    if ($ersetzt === 1) {
                        self::ereignis((int) $b['vorgaenger_id'], 'ersetzt', ['durch' => (string) $b['nummer'], 'fassung' => (string) $b['fassung']]);
                    }
                }
                self::ereignis($id, $z['art'] === 'gezeichnet' ? 'kunde_unterschrieben' : 'ohne_kunde', $details + ['pdf_sha256' => $pdf]);
                return $uid;
            });
        } catch (\PDOException $e) {
            if (!self::eindeutigVerletzt($e)) {
                throw $e;
            }
            return self::wiederholung($id, 'kunde', (string) $z['anfrage'])
                ?? ['ok' => false, 'status' => 409, 'message' => 'Der Kundenblock ist schon ausgefüllt.'];
        } catch (\RuntimeException | \LogicException $e) {
            // PDF nicht erzeugt (Schrift fehlt o. Ä.): nichts gespeichert, ein Neuversuch geht.
            Logger::error('Übergabebericht: PDF nicht erzeugt', ['bericht' => $id, 'fehler' => $e->getMessage()]);
            return ['ok' => false, 'status' => 500, 'message' => 'Das PDF ließ sich nicht erzeugen – nichts gespeichert, bitte noch einmal versuchen.'];
        }
        if ($uid === null) {
            return ['ok' => false, 'status' => 409, 'message' => 'Der Bericht wurde inzwischen geändert – bitte neu laden.'];
        }
        return ['ok' => true, 'status' => 200, 'unterschrift' => $uid, 'message' => $b['nummer'] . ' abgeschlossen – vielen Dank.'];
    }

    /**
     * Das PDF beim Abschluss erzeugen und ablegen (base64 in bericht_pdf,
     * SHA-256 in berichte.pdf_hash) – mit Siegel über Bericht, Nummer,
     * Fassung, die Kette der Kundenzeile, Prüfsumme und Erzeuger. Läuft in
     * der Transaktion des Abschlusses; CreationDate ist abgeschlossen_am,
     * die ID kommt aus dem inhalt_hash – neu erzeugt ergeben sich dieselben
     * Bytes.
     *
     * @return string SHA-256 des PDF
     */
    private static function pdfAblegen(int $id, string $am): string
    {
        $b = self::find($id);
        if ($b === null) {
            throw new \LogicException('Bericht verschwunden.');
        }
        $unterschriften = self::unterschriften($id);
        $bytes = BerichtPdf::erzeugen($b, $unterschriften, false);
        $sha = hash('sha256', $bytes);
        Database::insert('bericht_pdf', [
            'bericht_id'    => $id,
            'sha256'        => $sha,
            'groesse'       => strlen($bytes),
            'daten'         => base64_encode($bytes),
            'erzeuger'      => BerichtPdf::ERZEUGER,
            'erzeugt_am'    => $am,
            'siegel'        => self::pdfSiegel($id, (string) $b['nummer'], (int) $b['fassung'], (string) ($unterschriften['kunde']['kette_hash'] ?? ''),
                $sha, BerichtPdf::ERZEUGER),
            'schluessel_id' => self::schluesselId(),
        ]);
        Database::execute('UPDATE berichte SET pdf_hash = ? WHERE id = ?', [$sha, $id]);
        return $sha;
    }

    /** Das Siegel des gespeicherten PDF (HMAC, Schlüssel wie bei den Unterschriften). */
    private static function pdfSiegel(int $id, string $nummer, int $fassung, string $kundenKette, string $sha, string $erzeuger): string
    {
        return hash_hmac('sha256', implode("\n", ['PM-UB-PDF-1', (string) $id, $nummer, (string) $fassung, $kundenKette !== '' ? $kundenKette : '-', $sha, $erzeuger]),
            self::siegelSchluessel());
    }

    /**
     * Eine Unterschrift anlegen: Kette über Inhalt, vorige Unterschrift,
     * Striche, Angaben, Zeit und Konto; das Siegel ist ein HMAC darüber.
     *
     * @param array{rolle:string,art:string,name:string,funktion:string,ort:string,striche:?array,striche_hash:?string,angaben?:array,
     *              inhalt_hash:string,vorher_hash?:string,am:string,geraet_am:?string,anfrage:string} $z
     */
    private static function unterschriftAnlegen(int $berichtId, string $nummer, int $fassung, array $z): int
    {
        $user = Auth::user();
        $angaben = $z['angaben'] ?? [];
        $zeile = [
            'bericht_id'        => $berichtId,
            'rolle'             => $z['rolle'],
            'art'               => $z['art'],
            'name'              => $z['name'],
            'funktion'          => $z['funktion'],
            'ort'               => $z['ort'],
            'striche'           => $z['striche'] !== null ? Kanon::json(['s' => $z['striche']]) : null,
            'striche_hash'      => $z['striche_hash'],
            'angaben'           => $angaben !== [] ? Kanon::json($angaben) : null,
            'inhalt_hash'       => $z['inhalt_hash'],
            'vorher_hash'       => ($z['vorher_hash'] ?? '') !== '' ? $z['vorher_hash'] : null,
            'schluessel_id'     => self::schluesselId(),
            'user_id'           => $user['id'] ?? null,
            'username'          => (string) ($user['username'] ?? 'system'),
            'unterschrieben_am' => $z['am'],
            'geraet_am'         => $z['geraet_am'],
            'anfrage'           => $z['anfrage'],
        ];
        $zeile['kette_hash'] = self::kette($zeile, $nummer, $fassung);
        $zeile['siegel'] = hash_hmac('sha256', $zeile['kette_hash'], self::siegelSchluessel());
        return Database::insert('bericht_unterschriften', $zeile);
    }

    /** Die Kette einer Unterschrift – aus der Zeile, wie sie in der Datenbank steht. */
    private static function kette(array $z, string $nummer, int $fassung): string
    {
        $angaben = is_string($z['angaben'] ?? null) ? json_decode($z['angaben'], true, 16, JSON_THROW_ON_ERROR) : [];
        return hash('sha256', implode("\n", [
            'PM-UB-1', (string) $z['rolle'], (string) $z['art'], (string) $z['bericht_id'], $nummer, (string) $fassung,
            (string) $z['inhalt_hash'], (string) ($z['vorher_hash'] ?? '') ?: '-', (string) ($z['striche_hash'] ?? '') ?: '-',
            (string) $z['name'], (string) $z['funktion'], (string) $z['ort'], Kanon::json(is_array($angaben) ? $angaben : []),
            (string) $z['unterschrieben_am'], (string) ($z['user_id'] ?? ''),
        ]));
    }

    private static function siegelSchluessel(): string
    {
        return Crypto::ableiten(self::SIEGEL_ZWECK);
    }

    /**
     * Nummer, Jahr und laufende Nummer für die erste Prüfer-Unterschrift:
     * <Kürzel>-<Jahr des Prüfdatums>-<laufend>, bei einer Korrektur die
     * Nummer des Vorgängers mit -K<n>. Die laufende Nummer zählt über
     * bericht_nummern weiter – eine einmal vergebene Nummer kommt nie
     * wieder, auch nicht nach dem Löschen nach Ablauf der Frist. Unter
     * SQLite schließt BEGIN IMMEDIATE den Wettlauf zweier Tablets aus, unter
     * MySQL endet er am UNIQUE-Index (jahr, laufnr, fassung) bzw. am
     * Primärschlüssel des Zählers und wird wiederholt.
     *
     * @return array{0:string,1:int,2:int}
     */
    private static function nummerVergeben(array $b): array
    {
        $fassung = (int) $b['fassung'];
        if ($fassung > 1 && $b['jahr'] !== null && $b['laufnr'] !== null) {
            $basis = $b['vorgaenger_id'] !== null ? (string) (Database::scalar('SELECT nummer FROM berichte WHERE id = ?', [(int) $b['vorgaenger_id']]) ?: '') : '';
            $basis = (string) preg_replace('/-K\d+$/', '', $basis);
            if ($basis === '') {
                $basis = sprintf('%s-%d-%04d', Einstellungen::wert('berichte.praefix'), (int) $b['jahr'], (int) $b['laufnr']);
            }
            return [$basis . '-K' . ($fassung - 1), (int) $b['jahr'], (int) $b['laufnr']];
        }
        $jahr = preg_match('/^(\d{4})-/', (string) $b['pruefdatum'], $m) === 1 ? (int) $m[1] : (int) gmdate('Y');
        $zaehler = Database::scalar('SELECT laufnr FROM bericht_nummern WHERE jahr = ?', [$jahr]);
        $laufnr = max((int) Database::scalar('SELECT COALESCE(MAX(laufnr), 0) FROM berichte WHERE jahr = ?', [$jahr]), (int) ($zaehler ?: 0)) + 1;
        if ($zaehler === false) {
            Database::insert('bericht_nummern', ['jahr' => $jahr, 'laufnr' => $laufnr]);
        } else {
            Database::execute('UPDATE bericht_nummern SET laufnr = ? WHERE jahr = ?', [$laufnr, $jahr]);
        }
        return [sprintf('%s-%d-%04d', Einstellungen::wert('berichte.praefix'), $jahr, $laufnr), $jahr, $laufnr];
    }

    /**
     * Der eingefrorene Inhalt: der gespeicherte Stand mit festen Kopien von
     * allem, was sich später ändern könnte – Prüfer, Firma, Messgeräte,
     * Texte des Katalogs und der Einstellungen, die Bewertung. Ein alter
     * Bericht sieht so nach jeder Änderung an Code oder Einstellungen aus
     * wie am Tag der Unterschrift.
     *
     * $vorgaengerPflicht: beim Einfrieren einer Korrektur muss der Vorgänger
     * da sein – sonst \DomainException statt einer Fassung ohne Verweis.
     *
     * @param array{user_id:string,name:string,qualifikation:string,konto?:string} $pruefer
     */
    private static function inhaltBauen(array $b, array $pruefer, bool $vorgaengerPflicht = false): array
    {
        $i = self::ohneFesteKopien(BerichtSchema::vollstaendig($b['inhalt']));
        $i['bewertung'] = Grenzwerte::alle($i);
        $fassung = (int) $b['fassung'];
        $i['nummer'] = (string) $b['nummer'];
        $i['fassung'] = (string) $fassung;
        if ($fassung > 1 && $b['vorgaenger_id'] !== null) {
            $v = Database::first('SELECT nummer, fassung, inhalt_hash, abgeschlossen_am FROM berichte WHERE id = ?', [(int) $b['vorgaenger_id']]);
            if ($v !== null) {
                $i['vorgaenger'] = ['nummer' => (string) $v['nummer'], 'fassung' => (string) (int) $v['fassung'], 'inhalt_hash' => (string) $v['inhalt_hash'],
                    'abgeschlossen_am' => (string) ($v['abgeschlossen_am'] ?? ''), 'grund' => (string) $b['korrektur_grund']];
            } elseif ($vorgaengerPflicht) {
                throw new \DomainException('Vorgänger fehlt');
            }
        } elseif ($fassung > 1 && $vorgaengerPflicht) {
            throw new \DomainException('Vorgänger fehlt');
        }
        $i['kopf']['qualifikation'] = $pruefer['qualifikation'];
        $i['kopf']['pruefer'] = $pruefer;
        if ((string) $b['ewx_id'] !== '') {
            $i['kopf']['eventworx'] = ['host' => (string) $b['ewx_host'], 'id' => (string) $b['ewx_id'], 'art' => (string) $b['ewx_art'],
                'nummer' => (string) $b['ewx_nummer'], 'abgerufen_am' => (string) ($b['ewx_am'] ?? '')];
        }
        $i['auftragnehmer'] = [];
        foreach (['name', 'zusatz', 'anschrift', 'kontakt', 'bank', 'register'] as $feld) {
            $i['auftragnehmer'][$feld] = Einstellungen::wert('firma.' . $feld);
        }
        $i['messgeraete'] = [];
        foreach ($i['messung']['geraete'] as $gid) {
            $g = Pruefgeraete::find((int) $gid);
            if ($g !== null) {
                $i['messgeraete'][] = ['id' => (string) (int) $g['id'], 'fabrikat' => (string) $g['fabrikat'], 'typ' => (string) $g['typ'],
                    'seriennummer' => (string) $g['seriennummer'], 'norm' => (string) $g['norm'], 'kalibriert_bis' => (string) $g['kalibriert_bis']];
            }
        }
        $text = static fn (array $p): string => (string) $p['text'];
        $i['labels'] = [
            'besichtigung'   => array_map($text, Katalog::besichtigung()),
            'erprobung'      => array_map($text, Katalog::erprobung()),
            'normen'         => Katalog::normen(),
            'gruende'        => Katalog::gruende(),
            'einspeisungen'  => Katalog::einspeisungen(),
            'bestaetigungen' => array_map($text, Katalog::bestaetigungen()),
        ];
        $bereitschaft = trim((string) $i['kopf']['bereitschaft']) !== '' ? (string) $i['kopf']['bereitschaft'] : Einstellungen::wert('berichte.bereitschaft');
        $i['texte'] = [
            'kunde'        => Einstellungen::wert('berichte.text_kunde'),
            'einweisung'   => str_replace('{telefon}', $bereitschaft !== '' ? $bereitschaft : '–', Einstellungen::wert('berichte.text_einweisung')),
            'errichter'    => Einstellungen::wert('berichte.text_errichter'),
            'datenschutz'  => Einstellungen::wert('berichte.datenschutz'),
            'bereitschaft' => $bereitschaft,
        ];
        return $i;
    }

    /** Der Inhalt ohne die festen Kopien des Einfrierens – nach dem Zurückziehen und für eine Korrektur. */
    private static function ohneFesteKopien(array $inhalt): array
    {
        foreach (self::FESTE_KOPIEN as $schluessel) {
            unset($inhalt[$schluessel]);
        }
        unset($inhalt['kopf']['pruefer'], $inhalt['kopf']['eventworx']);
        return $inhalt;
    }

    /** Zahlen aus der Datenbank als int (SQLite liefert Text). */
    private static function zeile(array $b): array
    {
        foreach (['id', 'fassung', 'version', 'erstellt_von', 'standort_entfernt'] as $spalte) {
            if (array_key_exists($spalte, $b)) {
                $b[$spalte] = (int) $b[$spalte];
            }
        }
        foreach (['site_id', 'aufbau_id', 'jahr', 'laufnr', 'vorgaenger_id', 'ersetzt_durch_id', 'pruefer_id', 'geaendert_von', 'kunde_id'] as $spalte) {
            if (array_key_exists($spalte, $b)) {
                $b[$spalte] = $b[$spalte] !== null ? (int) $b[$spalte] : null;
            }
        }
        return $b;
    }

    /** @return array{0:?int,1:int} Beginn und Ende des Prüftags in der Zeitzone der Installation */
    private static function tag(string $datum): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
            return [null, 0];
        }
        try {
            $beginn = new \DateTimeImmutable($datum . ' 00:00:00', Zeit::zone());
        } catch (\Throwable) {
            return [null, 0];
        }
        return [$beginn->getTimestamp(), $beginn->modify('+1 day')->getTimestamp()];
    }

    private static int $tiefe = 0;

    /**
     * Eine Arbeit in einer Transaktion (in einer laufenden: ohne eigene).
     *
     * Unter SQLite mit BEGIN IMMEDIATE: die Schreibsperre gleich am Anfang.
     * Eine aufgeschobene Transaktion, die erst liest und dann schreibt,
     * scheitert sofort mit „database is locked“ – SQLite ruft den
     * Busy-Handler nicht auf, wenn eine Verbindung mit offenem Lesestand
     * aufstufen will. So wartet busy_timeout schon beim BEGIN, und Lesen und
     * Schreiben sehen denselben Stand.
     */
    private static function transaktion(callable $arbeit): mixed
    {
        $pdo = Database::pdo();
        if (self::$tiefe > 0 || $pdo->inTransaction()) {
            return $arbeit();
        }
        $sqlite = Database::driver() === 'sqlite';
        $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
        self::$tiefe++;
        try {
            $ergebnis = $arbeit();
            $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
            return $ergebnis;
        } catch (\Throwable $e) {
            try {
                $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (\Throwable) {
                // schon zurückgerollt (etwa nach SQLITE_BUSY beim COMMIT)
            }
            throw $e;
        } finally {
            self::$tiefe--;
        }
    }
}
