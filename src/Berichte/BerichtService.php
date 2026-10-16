<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\SiteService;
use App\Support\Audit;
use App\Support\Database;
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

    private const QUELLEN = ['anschluss', 'generator', 'wechselrichter'];

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
        $sql = 'SELECT b.id, b.nummer, b.jahr, b.laufnr, b.fassung, b.vorgaenger_id, b.status, b.version, b.site_id, b.aufbau_id,
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
     * Wer alles sieht: ja. Ohne Standort nur der Ersteller (mit „ausfüllen“).
     * Wer am Standort ändern darf und Berichte ausfüllt: alle, auch Entwürfe.
     * Sonst nur abgeschlossene (und ersetzte), und nur mit einer Freigabe für
     * den Standort, deren Datenzeitraum den Prüftag berührt – ein Kunde sieht
     * so den Bericht seiner Veranstaltung, nicht den der Woche davor.
     */
    public static function sichtbar(array $b): bool
    {
        if (!Auth::can('berichte.view') && !Auth::can('berichte.manage')) {
            return false;
        }
        if (Zugriff::alles()) {
            return true;
        }
        $siteId = $b['site_id'] !== null ? (int) $b['site_id'] : null;
        if ($siteId === null) {
            return Auth::can('berichte.manage') && (int) $b['erstellt_von'] === Auth::id();
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

    /** Ausfüllen: nur Entwürfe, nur mit „ausfüllen“ und „bearbeiten“ für den Standort. */
    public static function darfBearbeiten(array $b): bool
    {
        return Auth::can('berichte.manage') && (string) $b['status'] === 'entwurf' && self::standortRegel($b);
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
        return (int) $b['erstellt_von'] === Auth::id() || Zugriff::alles();
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
     * Eingaben eines anderen Tablets. $ewxVorgang: Kennung eines in dieser
     * Sitzung geladenen Eventworx-Vorgangs, „-“ entfernt den Verweis.
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
        if ($version !== (int) $b['version']) {
            return self::konflikt($b);
        }

        $bereinigt = BerichtSchema::bereinigen($roh, $b['inhalt']);
        $inhalt = $bereinigt['inhalt'];
        $hinweise = $bereinigt['hinweise'];

        // Prüfgeräte: nur vorhandene und aktive.
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

        $felder = BerichtSchema::listenfelder($inhalt);
        if ($b['site_id'] !== null && (string) ($b['site_name'] ?? '') !== '') {
            $felder['standort_name'] = mb_substr((string) $b['site_name'], 0, 190);
        }

        // Eventworx-Verweis nur aus dem, was in dieser Sitzung zu diesem Bericht geladen wurde.
        $ewx = trim($ewxVorgang);
        $ewxNeu = null;
        if ($ewx === '-') {
            $felder += ['ewx_host' => '', 'ewx_id' => '', 'ewx_art' => '', 'ewx_nummer' => '', 'ewx_am' => null];
        } elseif ($ewx !== '') {
            $geladen = self::eventworxGeladen($id)[$ewx] ?? null;
            if ($geladen === null) {
                $hinweise[] = 'Der Eventworx-Vorgang wurde in dieser Sitzung nicht geladen – Verweis nicht übernommen.';
            } else {
                $ewxNeu = $geladen;
                $felder += ['ewx_host' => $geladen['host'], 'ewx_id' => $ewx, 'ewx_art' => $geladen['art'], 'ewx_nummer' => $geladen['nummer'], 'ewx_am' => $geladen['am']];
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
            $n = Database::execute('UPDATE berichte SET ' . implode(', ', $sets) . ", version = version + 1 WHERE id = ? AND version = ? AND status = 'entwurf'", $werte);
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
            return $aktuell !== null && $aktuell['status'] !== 'entwurf'
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
                "UPDATE berichte SET inhalt = ?, version = version + 1, geaendert_von = ?, updated_at = ? WHERE id = ? AND version = ? AND status = 'entwurf'",
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
            $verweis = ['id' => (string) $q['ewx_id'], 'art' => (string) $q['ewx_art'], 'nummer' => (string) $q['ewx_nummer'], 'host' => (string) $q['ewx_host']];
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
     * vormerken – nur Kennung, Nummer, Art, Host und Zeit, keine Kundendaten.
     * Das Autosave übernimmt den Verweis nur, wenn er hier steht.
     *
     * @param array{id?:string,nummer?:string,art?:string,host?:string} $verweis
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
        ];
        $alle[$berichtId] = array_slice($je, -self::EWX_HOECHSTENS, self::EWX_HOECHSTENS, true);
        Session::put(self::EWX_SITZUNG, $alle);
    }

    /** @return array<string,array{nummer:string,art:string,host:string,am:string}> */
    public static function eventworxGeladen(int $berichtId): array
    {
        $alle = Session::get(self::EWX_SITZUNG, []);
        $je = is_array($alle) && is_array($alle[$berichtId] ?? null) ? $alle[$berichtId] : [];
        $out = [];
        foreach ($je as $jobId => $v) {
            if (is_array($v)) {
                $out[(string) $jobId] = ['nummer' => (string) ($v['nummer'] ?? ''), 'art' => (string) ($v['art'] ?? ''),
                    'host' => (string) ($v['host'] ?? ''), 'am' => (string) ($v['am'] ?? '')];
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
        $erdungGemessen = !$leer($i['messung']['erdung']['w'] ?? '') || !$leer($i['messung']['iso_bus']['w'] ?? '');
        if (($gemessen || $erdungGemessen) && $i['messung']['geraete'] === []) {
            $f('messung.geraete', 'Messwerte ohne Prüfgerät – bitte das Messgerät angeben.');
        }

        // ---------------------------------------------- Ergebnis
        $nio = 0;
        foreach (['besichtigung', 'erprobung'] as $teil) {
            $nio += count(array_filter($i['punkte'][$teil] ?? [], static fn ($z): bool => $z === 'nio'));
        }
        $alleBehoben = $i['maengel'] !== [] && array_filter($i['maengel'], static fn (array $m): bool => trim((string) ($m['behoben'] ?? '')) === '') === [];
        $entspricht = (string) $i['ergebnis']['entspricht'];
        if ($entspricht === '') {
            $f('ergebnis.entspricht', 'Prüfergebnis fehlt: entspricht die Anlage den Regeln der Elektrotechnik (ja oder nein)?');
        } elseif ($entspricht === 'ja' && ($mangel > 0 || $nio > 0) && !$alleBehoben) {
            $f('ergebnis.entspricht', '„Ja“ trotz Mängeln – Mängel als behoben eintragen oder „Nein“ wählen.');
        } elseif ($entspricht === 'nein' && $leer($i['ergebnis']['instand_bis']) && $i['maengel'] === []) {
            $f('ergebnis.instand_bis', 'Bei „Nein“ bitte eine Frist zur Instandsetzung oder die Mängel angeben.');
        }
        if ((string) $i['ergebnis']['errichter'] === '1' && $entspricht !== 'ja') {
            $f('ergebnis.errichter', 'Die Errichterbestätigung setzt „entspricht: ja“ voraus.');
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
        $punkteOffen = 0;
        $nio = 0;
        foreach (['besichtigung' => Katalog::besichtigung(), 'erprobung' => Katalog::erprobung()] as $teil => $katalog) {
            $antworten = $inhalt['punkte'][$teil] ?? [];
            foreach ($katalog as $schluessel => $punkt) {
                if ($punkt['kurz'] && !isset($antworten[$schluessel])) {
                    $punkteOffen++;
                }
            }
            $nio += count(array_filter($antworten, static fn ($z): bool => $z === 'nio'));
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

    // ------------------------------------------------------------ Verwerfen, Löschen

    /**
     * Ein Entwurf mit Nummer (die Prüfer-Unterschrift wurde zurückgezogen)
     * wird nicht gelöscht, sondern verworfen: so ist die Lücke in der
     * Nummernfolge erklärt.
     *
     * @return array{ok:bool,message:string}
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
        self::transaktion(static function () use ($id, $b, $grund): void {
            Database::execute("UPDATE berichte SET status = 'verworfen', aufbewahren_bis = ?, geaendert_von = ?, updated_at = ? WHERE id = ? AND status = 'entwurf'",
                [self::aufbewahrenBis((string) $b['pruefdatum']), Auth::id(), gmdate('c'), $id]);
            self::ereignis($id, 'verworfen', ['grund' => $grund]);
        });
        Audit::log('bericht.verworfen', 'bericht', (string) $id, ['nummer' => $b['nummer']]);
        return ['ok' => true, 'message' => $b['nummer'] . ' verworfen.'];
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

    // ------------------------------------------------------------ Lösch-Haken

    /**
     * Ein Standort wird gelöscht: Entwürfe ohne Nummer gehen mit, alle
     * anderen Berichte bleiben (mit dem Namen des Standorts in
     * `standort_name`) – sichtbar dann nur noch mit „Alle Standorte“.
     */
    public static function standortEntfernt(int $siteId): void
    {
        foreach (Database::select("SELECT id FROM berichte WHERE site_id = ? AND status = 'entwurf' AND nummer IS NULL", [$siteId]) as $row) {
            self::alleZeilenLoeschen((int) $row['id']);
        }
        $name = (string) (Database::scalar('SELECT name FROM sites WHERE id = ?', [$siteId]) ?: '');
        if ($name !== '') {
            Database::execute("UPDATE berichte SET standort_name = ? WHERE site_id = ? AND standort_name = ''", [mb_substr($name, 0, 190), $siteId]);
        }
        Database::execute('UPDATE berichte SET site_id = NULL WHERE site_id = ?', [$siteId]);
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
     * Berichte über der Aufbewahrungsfrist (abgeschlossen, ersetzt, verworfen).
     *
     * @return list<array<string,mixed>>
     */
    public static function faellig(): array
    {
        $heute = (new \DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d');
        return Database::select(
            "SELECT id, nummer, fassung, status, pruefdatum, aufbewahren_bis FROM berichte
             WHERE status IN ('abgeschlossen', 'ersetzt', 'verworfen') AND aufbewahren_bis IS NOT NULL AND aufbewahren_bis < ? ORDER BY aufbewahren_bis, id",
            [$heute]
        );
    }

    /** Einen Bericht nach Ablauf der Frist endgültig löschen – samt Unterschriften, PDF, Objekten und Ereignissen. */
    public static function aufbewahrungLoeschen(int $id): void
    {
        $nummer = Database::scalar('SELECT nummer FROM berichte WHERE id = ?', [$id]);
        self::transaktion(static fn () => self::alleZeilenLoeschen($id));
        Audit::log('bericht.geloescht', 'bericht', (string) $id, ['nummer' => is_string($nummer) ? $nummer : null]);
    }

    /** 31.12. des Jahres „Prüfdatum + Aufbewahrung“. */
    public static function aufbewahrenBis(string $pruefdatum): string
    {
        $jahr = preg_match('/^(\d{4})-/', $pruefdatum, $m) === 1 ? (int) $m[1] : (int) gmdate('Y');
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
            if ($b['status'] !== 'entwurf') {
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
     * Standard-Verteiler, auf diesem Standort, sichtbar. Einspeisungen zuerst.
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
            "SELECT b.id, b.nummer, b.fassung, b.status, b.site_id, b.erstellt_von, b.pruefdatum, b.abgeschlossen_am, b.titel FROM berichte b
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
        foreach (['bericht_unterschriften', 'bericht_pdf', 'bericht_objekte', 'bericht_ereignisse'] as $tabelle) {
            Database::execute("DELETE FROM {$tabelle} WHERE bericht_id = ?", [$id]);
        }
        Database::delete('berichte', $id);
    }

    /** Zahlen aus der Datenbank als int (SQLite liefert Text). */
    private static function zeile(array $b): array
    {
        foreach (['id', 'fassung', 'version', 'erstellt_von'] as $spalte) {
            if (array_key_exists($spalte, $b)) {
                $b[$spalte] = (int) $b[$spalte];
            }
        }
        foreach (['site_id', 'aufbau_id', 'jahr', 'laufnr', 'vorgaenger_id', 'ersetzt_durch_id', 'pruefer_id', 'geaendert_von'] as $spalte) {
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

    /** Eine Arbeit in einer Transaktion (in einer laufenden: ohne eigene). */
    private static function transaktion(callable $arbeit): mixed
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            return $arbeit();
        }
        $pdo->beginTransaction();
        try {
            $ergebnis = $arbeit();
            $pdo->commit();
            return $ergebnis;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
