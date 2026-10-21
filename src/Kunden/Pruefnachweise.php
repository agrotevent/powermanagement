<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Response;
use App\Support\Zeit;

/**
 * Prüfnachweise (DGUV V3) ortsveränderlicher Betriebsmittel – Verteiler,
 * Kabeltrommeln, Verlängerungen, die ein Kunde gemietet hat. Nicht der
 * Übergabebericht der Anlage (der steht in berichte): eine Geräteprüfung
 * nach DGUV Vorschrift 3 / DIN EN 50699 ersetzt keine Prüfung der Anlage
 * vor Ort und umgekehrt.
 *
 * Ein Nachweis gehört genau einem Kunden (pruefnachweise.kunde_id) – nie
 * einem Standort: ein Gelände hat mehrere Veranstalter. Der Kunde sieht ihn
 * erst, wenn eine Person ihn freigibt (status = 'freigegeben'), und nur mit
 * seiner Datei. Die Daten zu Prüfung und Frist trägt eine Person ein – das
 * PDF wird nicht ausgewertet, ein Ergebnis gibt es nur, wenn es jemand aus
 * dem Protokoll übernimmt.
 *
 * Dateien liegen Byte für Byte in der Datenbank (base64, wie bericht_pdf –
 * die Sicherung vor einem Update enthält nur config.php und die
 * Datenbank), höchstens 10 MiB (MariaDB: max_allowed_packet 16 MiB), gleiche
 * Bytes nur einmal (sha256). Beim Ausliefern wird die Prüfsumme
 * nachgerechnet; weicht sie ab, gibt es nichts (und einen Logeintrag ohne
 * Inhalt).
 *
 * Aufbewahrung im Kundenbereich: bis 31.12. des Jahres der Prüfung plus
 * kundenbereich.nachweise_jahre (Vorgabe 3). Gelöscht wird nur mit
 * `bin/power kunden:aufbewahrung --ausfuehren`; „wartung“ meldet nur.
 *
 * Rechte prüft die Route (kunden.nachweise, ansehen kunden.view); jede
 * Nachweis-ID wird hier gegen den Kunden geprüft – fremd ist nicht
 * vorhanden. Im Audit stehen nur IDs, nie Dateinamen oder Kundennamen.
 */
final class Pruefnachweise
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const STATUS = ['entwurf' => 'Entwurf', 'freigegeben' => 'freigegeben', 'zurueckgezogen' => 'zurückgezogen'];

    public const QUELLEN = ['hand' => 'von Hand hinterlegt', 'ewx_vorgang' => 'aus Eventworx'];

    /** '' = nicht angegeben: ein Ergebnis gibt es nur, wenn es im Protokoll steht. */
    public const ERGEBNISSE = ['' => 'nicht angegeben', 'bestanden' => 'bestanden', 'maengel' => 'mit Mängeln'];

    /** Vorschläge für das Feld „Norm“ (frei, höchstens 120 Zeichen). */
    public const NORMEN = ['DIN EN 50699 (VDE 0702)', 'DIN EN 50678 (VDE 0701)', 'DGUV Vorschrift 3'];

    /** Ab wie vielen Tagen bis zur nächsten Prüfung „läuft bald ab“. */
    public const BALD_TAGE = 30;

    /** Gültigkeit: Code => [Symbol, Wort] – immer beides, nie nur eine Farbe. */
    public const GUELTIGKEIT = [
        'gueltig'    => ['✓', 'gültig'],
        'laeuft_ab'  => ['!', 'läuft bald ab'],
        'abgelaufen' => ['✗', 'abgelaufen'],
        'unbekannt'  => ['?', 'unbekannt'],
    ];

    /** Die Felder eines Nachweises samt Datei, Location und Vorgang – nie die Bytes. */
    private const FELDER = 'n.*, d.sha256 AS datei_sha256, d.groesse AS datei_groesse, s.name AS standort_name,
        v.ewx_nummer AS vorgang_nummer, v.ewx_art AS vorgang_art, v.titel AS vorgang_titel';

    private const VON = 'pruefnachweise n
        LEFT JOIN pruefnachweis_dateien d ON d.id = n.datei_id
        LEFT JOIN sites s ON s.id = n.site_id
        LEFT JOIN kunden_vorgaenge v ON v.id = n.vorgang_id AND v.kunde_id = n.kunde_id';

    // ------------------------------------------------------------ Lesen

    /**
     * Die Nachweise eines Kunden, neueste Prüfung zuerst. Mit
     * $nurFreigegeben nur, was der Kunde sieht: freigegeben und mit
     * vorhandener Datei. Mit $siteId nur die an dieser Location.
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerKunde(int $kundeId, bool $nurFreigegeben = false, ?int $siteId = null): array
    {
        $sql = 'SELECT ' . self::FELDER . ' FROM ' . self::VON . ' WHERE n.kunde_id = ?';
        $werte = [$kundeId];
        if ($nurFreigegeben) {
            $sql .= " AND n.status = 'freigegeben' AND n.datei_id IS NOT NULL AND d.id IS NOT NULL";
        }
        if ($siteId !== null) {
            $sql .= ' AND n.site_id = ?';
            $werte[] = $siteId;
        }
        return Database::select($sql . ' ORDER BY n.geprueft_am DESC, n.bezeichnung, n.id DESC', $werte);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return $id > 0 ? Database::first('SELECT ' . self::FELDER . ' FROM ' . self::VON . ' WHERE n.id = ?', [$id]) : null;
    }

    /** Ein Nachweis dieses Kunden – ein fremder ist „nicht vorhanden“. */
    public static function vonKunde(int $kundeId, int $id): ?array
    {
        $n = self::find($id);
        return $n !== null && (int) $n['kunde_id'] === $kundeId ? $n : null;
    }

    /**
     * Reiter Prüfnachweise beim Personal: alle Nachweise des Kunden (jeder
     * Status) mit Gültigkeit, ob der Kunde ihn jetzt sieht, und wann er ihn
     * zuletzt geladen hat.
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerPersonal(int $kundeId): array
    {
        $gesperrt = Kundenbereich::kundeSperre($kundeId) !== null;
        $out = [];
        foreach (self::fuerKunde($kundeId) as $n) {
            $n['gueltigkeit'] = self::gueltigkeit($n);
            $n['beim_kunden'] = !$gesperrt && self::sichtbar($n);
            $n['abruf'] = Abrufe::letzter('nachweis', (int) $n['id']);
            $out[] = $n;
        }
        return $out;
    }

    /** Sähe der Kunde diesen Nachweis (ohne die Frage, ob der Kunde selbst aktiv ist)? */
    public static function sichtbar(array $n): bool
    {
        return (string) $n['status'] === 'freigegeben' && $n['datei_id'] !== null && ($n['datei_sha256'] ?? null) !== null;
    }

    // ------------------------------------------------------------ Hochladen, Angaben

    /**
     * Ein hochgeladenes PDF ($_FILES-Eintrag) als Nachweis hinterlegen.
     * Nur ein echter Upload (is_uploaded_file), höchstens MAX_BYTES; den
     * Rest prüft pdfAnlegen().
     *
     * @param array<string,mixed> $datei
     * @param array<string,mixed> $angaben
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function pdfHochladen(int $kundeId, array $datei, array $angaben, bool $freigeben): array
    {
        // Ein einzelner Upload – „datei[]“ (mehrere) gibt Listen statt Zahlen.
        $fehler = is_int($datei['error'] ?? null) ? $datei['error'] : (isset($datei['error']) ? -1 : UPLOAD_ERR_NO_FILE);
        if ($fehler !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => match ($fehler) {
                UPLOAD_ERR_NO_FILE                        => 'Bitte die PDF-Datei des Nachweises wählen.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist größer, als der Server annimmt (upload_max_filesize) – höchstens 10 MB.',
                UPLOAD_ERR_PARTIAL                        => 'Der Upload wurde abgebrochen.',
                default                                   => 'Der Upload hat nicht geklappt (Code ' . $fehler . ').',
            }];
        }
        $tmp = is_string($datei['tmp_name'] ?? null) ? $datei['tmp_name'] : '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'Der Upload hat nicht geklappt.'];
        }
        $groesse = (int) ($datei['size'] ?? 0);
        if ($groesse > self::MAX_BYTES || (int) filesize($tmp) > self::MAX_BYTES) {
            return ['ok' => false, 'message' => self::zuGross()];
        }
        $bytes = (string) file_get_contents($tmp);
        return self::pdfAnlegen($kundeId, $bytes, is_string($datei['name'] ?? null) ? $datei['name'] : '', $angaben, $freigeben);
    }

    /**
     * Einen Nachweis mit seinem PDF anlegen (quelle 'hand'): die Bytes müssen
     * ein PDF sein (beginnen mit %PDF-, finfo application/pdf), höchstens
     * MAX_BYTES; die Angaben prüft angaben(). Der Dateiname wird bereinigt
     * (nur A–Z, a–z, 0–9, Punkt, Strich, Unterstrich) – der Kunde bekommt ihn
     * nie, nur einen neutralen (kundenDateiname()).
     *
     * @param array<string,mixed> $angaben
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function pdfAnlegen(int $kundeId, string $bytes, string $name, array $angaben, bool $freigeben): array
    {
        if (KundenService::find($kundeId) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        $pdf = self::pdfPruefen($bytes);
        if ($pdf !== null) {
            return ['ok' => false, 'message' => $pdf];
        }
        $a = self::angaben($kundeId, $angaben, null);
        if (!$a['ok']) {
            return $a;
        }
        $dateiName = self::dateiname($name);
        $jetzt = gmdate('c');
        $id = self::transaktion(static function () use ($kundeId, $bytes, $a, $dateiName, $freigeben, $jetzt): int {
            return Database::insert('pruefnachweise', $a['felder'] + [
                'kunde_id'        => $kundeId,
                'status'          => $freigeben ? 'freigegeben' : 'entwurf',
                'quelle'          => 'hand',
                'datei_id'        => self::dateiSpeichern($bytes),
                'datei_name'      => $dateiName,
                // Woher genau – nie Inhalte: der (bereinigte) Name, wer, wann.
                'herkunft'        => json_encode(['art' => 'upload', 'name' => $dateiName, 'user_id' => Auth::id(), 'am' => $jetzt], JSON_UNESCAPED_UNICODE),
                'erstellt_von'    => Auth::id(),
                'freigegeben_von' => $freigeben ? Auth::id() : null,
                'freigegeben_am'  => $freigeben ? $jetzt : null,
                'created_at'      => $jetzt,
            ]);
        });
        Audit::log('kunden.nachweis_hochgeladen', 'pruefnachweis', (string) $id,
            ['kunde_id' => $kundeId, 'nachweis_id' => $id, 'quelle' => 'hand', 'freigegeben' => $freigeben]);
        return ['ok' => true, 'message' => $freigeben ? 'Prüfnachweis hinterlegt und für den Kunden freigegeben.'
            : 'Prüfnachweis als Entwurf hinterlegt – der Kunde sieht ihn erst nach der Freigabe.', 'id' => $id];
    }

    /**
     * Die Angaben eines Nachweises korrigieren (die Datei bleibt; eine andere
     * Datei ist ein neuer Nachweis). Ein freigegebener bleibt freigegeben.
     *
     * @param array<string,mixed> $angaben
     * @return array{ok:bool,message:string}
     */
    public static function speichern(int $kundeId, int $id, array $angaben): array
    {
        $n = self::vonKunde($kundeId, $id);
        if ($n === null) {
            return ['ok' => false, 'message' => 'Diesen Prüfnachweis gibt es nicht.'];
        }
        $a = self::angaben($kundeId, $angaben, $n);
        if (!$a['ok']) {
            return $a;
        }
        Database::update('pruefnachweise', $id, $a['felder'] + ['updated_at' => gmdate('c')]);
        Audit::log('kunden.nachweis_speichern', 'pruefnachweis', (string) $id, ['kunde_id' => $kundeId, 'nachweis_id' => $id]);
        return ['ok' => true, 'message' => 'Angaben des Prüfnachweises gespeichert.'];
    }

    /**
     * Freigeben (der Kunde sieht ihn sofort) oder zurückziehen (sofort weg).
     * Freigegeben wird nur mit unversehrter Datei.
     *
     * @return array{ok:bool,message:string}
     */
    public static function status(int $kundeId, int $id, string $status): array
    {
        $n = self::vonKunde($kundeId, $id);
        if ($n === null) {
            return ['ok' => false, 'message' => 'Diesen Prüfnachweis gibt es nicht.'];
        }
        $alt = (string) $n['status'];
        if ($status === 'freigegeben') {
            if ($alt === 'freigegeben') {
                return ['ok' => false, 'message' => 'Der Prüfnachweis ist schon freigegeben.'];
            }
            if (self::datei($n) === null) {
                return ['ok' => false, 'message' => 'Die Datei des Nachweises fehlt oder ist beschädigt – so wird er nicht freigegeben.'];
            }
            if ((string) ($n['geprueft_am'] ?? '') === '' || trim((string) $n['bezeichnung']) === '') {
                return ['ok' => false, 'message' => 'Erst Bezeichnung und Prüfdatum eintragen.'];
            }
            Database::update('pruefnachweise', $id, ['status' => 'freigegeben', 'freigegeben_von' => Auth::id(), 'freigegeben_am' => gmdate('c'), 'updated_at' => gmdate('c')]);
            $text = 'Prüfnachweis freigegeben – der Kunde sieht ihn jetzt.';
        } elseif ($status === 'zurueckgezogen') {
            if ($alt !== 'freigegeben') {
                return ['ok' => false, 'message' => 'Zurückziehen lässt sich nur ein freigegebener Prüfnachweis.'];
            }
            Database::update('pruefnachweise', $id, ['status' => 'zurueckgezogen', 'updated_at' => gmdate('c')]);
            $text = 'Prüfnachweis zurückgezogen – der Kunde sieht ihn nicht mehr.';
        } else {
            return ['ok' => false, 'message' => 'Unbekannter Status.'];
        }
        Audit::log('kunden.nachweis_status', 'pruefnachweis', (string) $id, ['kunde_id' => $kundeId, 'nachweis_id' => $id, 'status' => $status]);
        return ['ok' => true, 'message' => $text];
    }

    /**
     * Einen Nachweis löschen – mit seinen Abrufen und der Datei, wenn sie
     * sonst niemand nutzt.
     *
     * @return array{ok:bool,message:string}
     */
    public static function loeschen(int $kundeId, int $id): array
    {
        $n = self::vonKunde($kundeId, $id);
        if ($n === null) {
            return ['ok' => false, 'message' => 'Diesen Prüfnachweis gibt es nicht.'];
        }
        self::transaktion(static fn () => self::zeileLoeschen($n));
        Audit::log('kunden.nachweis_loeschen', 'pruefnachweis', (string) $id, ['kunde_id' => $kundeId, 'nachweis_id' => $id]);
        return ['ok' => true, 'message' => 'Prüfnachweis gelöscht.'];
    }

    /**
     * Die Angaben aus dem Formular prüfen – für Anlegen und Korrigieren.
     *
     * bezeichnung (Pflicht), inventarnummer/seriennummer (≤ 80), norm (≤ 120),
     * ergebnis ('' | bestanden | maengel), geprueft_am (Pflicht, nicht in der
     * Zukunft), naechste_pruefung (≥ geprueft_am), einsatz_von ≤ einsatz_bis
     * (Mietzeit; leer mit Vorgang: dessen Mietzeit), site_id (nur eine
     * Location aus den Freigaben dieses Kunden), vorgang_id (nur dieses
     * Kunden), inventar_id (nur mit „Datenquellen ansehen“; füllt leere
     * Nummern aus dem Inventar), hinweis für den Kunden (≤ 500). Daten als
     * JJJJ-MM-TT oder TT.MM.JJJJ.
     *
     * @param array<string,mixed> $angaben
     * @param array<string,mixed>|null $vorher der Nachweis beim Korrigieren
     * @return array{ok:bool,message:string,felder?:array<string,mixed>}
     */
    public static function angaben(int $kundeId, array $angaben, ?array $vorher): array
    {
        $text = static function (string $feld, int $max) use ($angaben): string {
            $wert = is_scalar($angaben[$feld] ?? null) ? (string) $angaben[$feld] : '';
            return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $wert))), 0, $max + 1);
        };
        $fehler = static fn (string $m): array => ['ok' => false, 'message' => $m];
        $bezeichnung = $text('bezeichnung', 255);
        if (mb_strlen($bezeichnung) < 2 || mb_strlen($bezeichnung) > 255) {
            return $fehler('Bitte das Gerät bezeichnen (2 bis 255 Zeichen) – bei einer Sammel-PDF etwa „Sammelnachweis 32 Geräte“.');
        }
        $felder = ['bezeichnung' => $bezeichnung];
        foreach (['inventarnummer' => 80, 'seriennummer' => 80, 'norm' => 120] as $feld => $max) {
            $felder[$feld] = $text($feld, $max);
            if (mb_strlen($felder[$feld]) > $max) {
                return $fehler(match ($feld) {
                    'inventarnummer' => 'Die Inventarnummer hat höchstens 80 Zeichen.',
                    'seriennummer'   => 'Die Seriennummer hat höchstens 80 Zeichen.',
                    default          => 'Die Norm hat höchstens 120 Zeichen.',
                });
            }
        }
        $ergebnis = is_string($angaben['ergebnis'] ?? null) ? $angaben['ergebnis'] : '';
        if (!array_key_exists($ergebnis, self::ERGEBNISSE)) {
            return $fehler('Ergebnis nur „bestanden“, „mit Mängeln“ oder leer (wenn das Protokoll keins nennt).');
        }
        $felder['ergebnis'] = $ergebnis;

        $daten = [];
        foreach (['geprueft_am' => 'Prüfdatum', 'naechste_pruefung' => 'nächste Prüfung', 'einsatz_von' => 'Mietzeit von', 'einsatz_bis' => 'Mietzeit bis'] as $feld => $name) {
            $roh = $text($feld, 20);
            $daten[$feld] = self::datumLesen($roh);
            if ($roh !== '' && $daten[$feld] === null) {
                return $fehler('Kein gültiges Datum bei „' . $name . '“: „' . mb_substr($roh, 0, 20) . '“.');
            }
        }
        if ($daten['geprueft_am'] === null) {
            return $fehler('Das Prüfdatum fehlt – es steht im Prüfprotokoll.');
        }
        if ($daten['geprueft_am'] > self::heute()) {
            return $fehler('Das Prüfdatum liegt in der Zukunft.');
        }
        if ($daten['naechste_pruefung'] !== null && $daten['naechste_pruefung'] < $daten['geprueft_am']) {
            return $fehler('Die nächste Prüfung liegt vor dem Prüfdatum.');
        }

        // Location: nur eine aus den Freigaben dieses Kunden (auch abgelaufene
        // und kommende) – oder die, die schon eingetragen ist.
        $siteRoh = is_scalar($angaben['site_id'] ?? null) ? trim((string) $angaben['site_id']) : '';
        if ($siteRoh !== '' && !ctype_digit($siteRoh)) {
            return $fehler('Diese Location ist dem Kunden nicht freigegeben.');
        }
        $site = $siteRoh !== '' ? (int) $siteRoh : 0;
        $erlaubt = array_map(static fn (array $s): int => (int) $s['id'], self::standorte($kundeId));
        if ($site > 0 && !in_array($site, $erlaubt, true) && $site !== (int) ($vorher['site_id'] ?? 0)) {
            return $fehler('Diese Location ist dem Kunden nicht freigegeben.');
        }
        $felder['site_id'] = $site > 0 ? $site : null;

        // Ohne Feld (der Kunde hat keine Vorgänge) bleibt, was eingetragen ist.
        $vorgangRoh = !array_key_exists('vorgang_id', $angaben) ? (string) ($vorher['vorgang_id'] ?? '')
            : (is_scalar($angaben['vorgang_id']) ? (string) $angaben['vorgang_id'] : '');
        $vorgang = null;
        if ($vorgangRoh !== '' && $vorgangRoh !== '0') {
            $vorgang = ctype_digit($vorgangRoh)
                ? Database::first('SELECT id, von, bis FROM kunden_vorgaenge WHERE id = ? AND kunde_id = ?', [(int) $vorgangRoh, $kundeId]) : null;
            if ($vorgang === null) {
                return $fehler('Diesen Eventworx-Vorgang hat der Kunde nicht.');
            }
        }
        $felder['vorgang_id'] = $vorgang !== null ? (int) $vorgang['id'] : null;
        // Ohne eigene Mietzeit gilt die des Vorgangs.
        if ($vorgang !== null && $daten['einsatz_von'] === null && $daten['einsatz_bis'] === null) {
            $daten['einsatz_von'] = self::datumLesen((string) ($vorgang['von'] ?? ''));
            $daten['einsatz_bis'] = self::datumLesen((string) ($vorgang['bis'] ?? ''));
        }
        if ($daten['einsatz_von'] !== null && $daten['einsatz_bis'] !== null && $daten['einsatz_von'] > $daten['einsatz_bis']) {
            return $fehler('Die Mietzeit endet vor ihrem Beginn.');
        }

        // Inventar sieht nur, wer Datenquellen ansehen darf; sonst bleibt, was eingetragen ist.
        $felder['inventar_id'] = $vorher !== null && $vorher['inventar_id'] !== null ? (int) $vorher['inventar_id'] : null;
        if (Auth::can('datenquellen.view') && array_key_exists('inventar_id', $angaben)) {
            $invRoh = is_scalar($angaben['inventar_id']) ? (string) $angaben['inventar_id'] : '';
            $inventar = null;
            if ($invRoh !== '' && $invRoh !== '0') {
                $inventar = ctype_digit($invRoh) ? Database::first('SELECT id, kennung, seriennummer FROM inventar WHERE id = ?', [(int) $invRoh]) : null;
                if ($inventar === null) {
                    return $fehler('Dieses Inventarstück gibt es nicht.');
                }
            }
            $felder['inventar_id'] = $inventar !== null ? (int) $inventar['id'] : null;
            if ($inventar !== null) {
                $felder['inventarnummer'] = $felder['inventarnummer'] !== '' ? $felder['inventarnummer'] : mb_substr((string) $inventar['kennung'], 0, 80);
                $felder['seriennummer'] = $felder['seriennummer'] !== '' ? $felder['seriennummer'] : mb_substr((string) $inventar['seriennummer'], 0, 80);
            }
        }

        $hinweis = $text('hinweis', 500);
        if (mb_strlen($hinweis) > 500) {
            return $fehler('Der Hinweis für den Kunden hat höchstens 500 Zeichen.');
        }
        $felder['hinweis'] = $hinweis;
        return ['ok' => true, 'message' => '', 'felder' => $felder + $daten + ['aufbewahren_bis' => self::aufbewahrenBis($daten['geprueft_am'])]];
    }

    // ------------------------------------------------------------ Dateien

    /**
     * Die Bytes einer Datei speichern – gleiche Bytes nur einmal (sha256).
     * @return int ID in pruefnachweis_dateien
     */
    public static function dateiSpeichern(string $bytes): int
    {
        $sha = hash('sha256', $bytes);
        $id = Database::scalar('SELECT id FROM pruefnachweis_dateien WHERE sha256 = ?', [$sha]);
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
        try {
            return Database::insert('pruefnachweis_dateien', ['sha256' => $sha, 'art' => 'pdf', 'groesse' => strlen($bytes),
                'daten' => base64_encode($bytes), 'created_at' => gmdate('c')]);
        } catch (\PDOException $e) {
            // Dieselben Bytes kamen eben aus einem zweiten Prozess (UNIQUE sha256).
            $id = Database::scalar('SELECT id FROM pruefnachweis_dateien WHERE sha256 = ?', [$sha]);
            if ($id === false || $id === null) {
                throw $e;
            }
            return (int) $id;
        }
    }

    /**
     * Die Datei eines Nachweises – Prüfsumme und Größe nachgerechnet. Weicht
     * etwas ab, gibt es nichts (Logeintrag nur mit IDs): sonst bekäme jemand
     * etwas anderes als das, was hinterlegt wurde.
     *
     * @return array{name:string,bytes:string,sha256:string}|null
     */
    public static function datei(array $nachweis): ?array
    {
        if ($nachweis['datei_id'] === null) {
            return null;
        }
        $d = Database::first('SELECT id, sha256, groesse, daten FROM pruefnachweis_dateien WHERE id = ?', [(int) $nachweis['datei_id']]);
        if ($d === null) {
            return null;
        }
        $bytes = base64_decode((string) $d['daten'], true);
        if (!is_string($bytes) || strlen($bytes) !== (int) $d['groesse'] || !hash_equals((string) $d['sha256'], hash('sha256', $bytes))) {
            Logger::error('Prüfnachweis: gespeicherte Datei weicht von ihrer Prüfsumme ab – nicht ausgeliefert',
                ['nachweis_id' => (int) $nachweis['id'], 'datei_id' => (int) $d['id']]);
            return null;
        }
        return ['name' => (string) $nachweis['datei_name'] !== '' ? (string) $nachweis['datei_name'] : 'pruefnachweis.pdf', 'bytes' => $bytes, 'sha256' => (string) $d['sha256']];
    }

    /**
     * Der Dateiname für den Kunden – neutral, nur ASCII, nie der Name der
     * hochgeladenen Datei (der kann Interna oder andere Kunden nennen).
     */
    public static function kundenDateiname(array $n): string
    {
        $nummer = (string) $n['inventarnummer'] !== '' ? (string) $n['inventarnummer'] : ((string) $n['seriennummer'] !== '' ? (string) $n['seriennummer'] : (string) (int) $n['id']);
        $name = strtr('Pruefnachweis-' . $nummer . ((string) ($n['geprueft_am'] ?? '') !== '' ? '-' . $n['geprueft_am'] : '') . '.pdf',
            ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        return Response::sichererDateiname($name, 'Pruefnachweis.pdf');
    }

    /** Ein hochgeladener Dateiname, bereinigt: ohne Pfad, nur [A-Za-z0-9._-], endet auf .pdf. */
    public static function dateiname(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $name = (string) preg_replace('/\.pdf$/i', '', $name);
        return substr(Response::sichererDateiname($name, 'pruefnachweis'), 0, 180) . '.pdf';
    }

    // ------------------------------------------------------------ Gültigkeit

    /**
     * Wie steht es um die Prüffrist – tagesgenau in der Zeitzone der
     * Installation, mit Blick auf die Mietzeit.
     *
     *   abgelaufen  nächste Prüfung vor heute
     *   laeuft_ab   nächste Prüfung heute bis in BALD_TAGE Tagen
     *   gueltig     später
     *   unbekannt   kein Datum der nächsten Prüfung
     *
     * hinweise: Frist endet in der Mietzeit (einsatz_von ≤ nächste ≤
     * einsatz_bis), Frist schon vor der Mietzeit abgelaufen, erst nach der
     * Übergabe geprüft (geprueft_am > einsatz_von).
     *
     * @return array{code:string,symbol:string,wort:string,text:string,hinweise:list<string>}
     */
    public static function gueltigkeit(array $n, ?string $heute = null): array
    {
        $heute ??= self::heute();
        $naechste = self::datumLesen((string) ($n['naechste_pruefung'] ?? ''));
        $geprueft = self::datumLesen((string) ($n['geprueft_am'] ?? ''));
        $von = self::datumLesen((string) ($n['einsatz_von'] ?? ''));
        $bis = self::datumLesen((string) ($n['einsatz_bis'] ?? ''));
        if ($naechste === null) {
            $code = 'unbekannt';
            $text = 'nächste Prüfung nicht angegeben';
        } else {
            // In UTC gerechnet: über eine Zeitumstellung hinweg hätte ein Tag sonst 23 oder 25 Stunden.
            $utc = new \DateTimeZone('UTC');
            $tage = (int) (new \DateTimeImmutable($heute, $utc))->diff(new \DateTimeImmutable($naechste, $utc))->format('%r%a');
            [$code, $text] = match (true) {
                $tage < 0                => ['abgelaufen', 'Prüffrist abgelaufen am ' . self::datum($naechste)],
                $tage === 0              => ['laeuft_ab', 'nächste Prüfung heute fällig'],
                $tage <= self::BALD_TAGE => ['laeuft_ab', 'nächste Prüfung am ' . self::datum($naechste) . ' (in ' . $tage . ($tage === 1 ? ' Tag)' : ' Tagen)')],
                default                  => ['gueltig', 'gültig bis ' . self::datum($naechste)],
            };
        }
        $hinweise = [];
        if ($naechste !== null && $von !== null && $naechste < $von) {
            $hinweise[] = 'Die Prüffrist war schon vor Beginn der Mietzeit abgelaufen.';
        } elseif ($naechste !== null && $von !== null && $bis !== null && $naechste <= $bis) {
            $hinweise[] = 'Die Prüffrist endet in der Mietzeit (' . self::datum($naechste) . ').';
        }
        if ($geprueft !== null && $von !== null && $geprueft > $von) {
            $hinweise[] = 'Nach der Übergabe geprüft (am ' . self::datum($geprueft) . ').';
        }
        return ['code' => $code, 'symbol' => self::GUELTIGKEIT[$code][0], 'wort' => self::GUELTIGKEIT[$code][1], 'text' => $text, 'hinweise' => $hinweise];
    }

    /** Das heutige Datum in der Zeitzone der Installation (JJJJ-MM-TT). */
    public static function heute(?int $jetzt = null): string
    {
        return (new \DateTimeImmutable('@' . ($jetzt ?? time())))->setTimezone(Zeit::zone())->format('Y-m-d');
    }

    /** JJJJ-MM-TT → TT.MM.JJJJ (sonst unverändert). */
    public static function datum(?string $d): string
    {
        return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : (string) $d;
    }

    // ------------------------------------------------------------ Aufbewahrung

    /** Bis wann ein Nachweis im Kundenbereich bleibt: 31.12. des Prüfjahres plus kundenbereich.nachweise_jahre. */
    public static function aufbewahrenBis(?string $geprueftAm): string
    {
        $datum = self::datumLesen((string) $geprueftAm) ?? self::heute();
        $jahre = (int) KundenEinstellungen::wert('kundenbereich.nachweise_jahre');
        return ((int) substr($datum, 0, 4) + max(1, $jahre)) . '-12-31';
    }

    /**
     * Nachweise über der Aufbewahrungsfrist (aufbewahren_bis vor heute).
     *
     * @return list<array{id:int,kunde_id:int,status:string,geprueft_am:?string,aufbewahren_bis:string}>
     */
    public static function faellig(?string $heute = null): array
    {
        $heute ??= self::heute();
        $out = [];
        foreach (Database::select('SELECT id, kunde_id, status, geprueft_am, aufbewahren_bis FROM pruefnachweise
            WHERE aufbewahren_bis IS NOT NULL AND aufbewahren_bis < ? ORDER BY aufbewahren_bis, id', [$heute]) as $r) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['aufbewahren_bis']) === 1) {
                $out[] = ['id' => (int) $r['id'], 'kunde_id' => (int) $r['kunde_id'], 'status' => (string) $r['status'],
                    'geprueft_am' => $r['geprueft_am'] !== null ? (string) $r['geprueft_am'] : null, 'aufbewahren_bis' => (string) $r['aufbewahren_bis']];
            }
        }
        return $out;
    }

    /** Einen fälligen Nachweis löschen (samt Abrufen, Datei wenn unbenutzt) – nur, wenn er wirklich fällig ist. */
    public static function aufbewahrungLoeschen(int $id): bool
    {
        if (!in_array($id, array_column(self::faellig(), 'id'), true)) {
            return false;
        }
        $n = self::find($id);
        if ($n === null) {
            return false;
        }
        self::transaktion(static fn () => self::zeileLoeschen($n));
        Audit::log('kunden.nachweis_loeschen', 'pruefnachweis', (string) $id, ['kunde_id' => (int) $n['kunde_id'], 'nachweis_id' => $id, 'grund' => 'aufbewahrung']);
        return true;
    }

    /** Für `wartung`: nur melden, gelöscht wird mit kunden:aufbewahrung. @return list<string> */
    public static function wartungHinweise(): array
    {
        $n = count(self::faellig());
        return $n > 0 ? [$n . ($n === 1 ? ' Prüfnachweis' : ' Prüfnachweise') . ' über der Aufbewahrungsfrist (bin/power kunden:aufbewahrung).'] : [];
    }

    // ------------------------------------------------------------ Haken

    /** Ein Inventarstück wird gelöscht: Nachweise bleiben, nur ohne Verweis. */
    public static function inventarEntfernt(int $inventarId): void
    {
        Database::execute('UPDATE pruefnachweise SET inventar_id = NULL WHERE inventar_id = ?', [$inventarId]);
    }

    /**
     * Ein Kunde wird gelöscht: seine Nachweise mit ihren Abrufen und den
     * Dateien, die sonst niemand nutzt. Läuft in der Transaktion des Aufrufers.
     *
     * @return int Zahl der gelöschten Nachweise
     */
    public static function kundeEntfernt(int $kundeId): int
    {
        $n = 0;
        foreach (Database::select('SELECT id, kunde_id, datei_id FROM pruefnachweise WHERE kunde_id = ?', [$kundeId]) as $zeile) {
            self::zeileLoeschen($zeile);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------ Prüfen

    /**
     * Nachrechnen: jede Datei (base64, Größe, Prüfsumme), jeder Verweis auf
     * eine Datei, Dateien ohne Nachweis, freigegebene Nachweise ohne Datei.
     * Befunde nur mit IDs – nie Namen.
     *
     * @return list<string>
     */
    public static function pruefen(): array
    {
        $befunde = [];
        foreach (Database::select('SELECT id FROM pruefnachweis_dateien ORDER BY id') as $z) {
            $d = Database::first('SELECT id, sha256, groesse, daten FROM pruefnachweis_dateien WHERE id = ?', [(int) $z['id']]);
            if ($d === null) {
                continue;
            }
            $bytes = base64_decode((string) $d['daten'], true);
            if (!is_string($bytes)) {
                $befunde[] = 'Datei #' . (int) $d['id'] . ': kein gültiges base64.';
            } elseif (strlen($bytes) !== (int) $d['groesse']) {
                $befunde[] = 'Datei #' . (int) $d['id'] . ': Größe stimmt nicht (' . strlen($bytes) . ' statt ' . (int) $d['groesse'] . ' Bytes).';
            } elseif (!hash_equals((string) $d['sha256'], hash('sha256', $bytes))) {
                $befunde[] = 'Datei #' . (int) $d['id'] . ': Prüfsumme stimmt nicht.';
            }
            unset($d, $bytes);
        }
        foreach (Database::select('SELECT n.id, n.datei_id FROM pruefnachweise n LEFT JOIN pruefnachweis_dateien d ON d.id = n.datei_id
            WHERE n.datei_id IS NOT NULL AND d.id IS NULL ORDER BY n.id') as $z) {
            $befunde[] = 'Nachweis #' . (int) $z['id'] . ': Datei #' . (int) $z['datei_id'] . ' fehlt.';
        }
        foreach (Database::select("SELECT id FROM pruefnachweise WHERE status = 'freigegeben' AND datei_id IS NULL ORDER BY id") as $z) {
            $befunde[] = 'Nachweis #' . (int) $z['id'] . ': freigegeben, aber ohne Datei.';
        }
        foreach (Database::select('SELECT d.id FROM pruefnachweis_dateien d WHERE NOT EXISTS (SELECT 1 FROM pruefnachweise n WHERE n.datei_id = d.id) ORDER BY d.id') as $z) {
            $befunde[] = 'Datei #' . (int) $z['id'] . ': von keinem Nachweis genutzt.';
        }
        return $befunde;
    }

    // ------------------------------------------------------------ Formular

    /**
     * Die Locations, die zu einem Nachweis dieses Kunden passen: die seiner
     * Freigaben (auch abgelaufene und kommende).
     *
     * @return list<array{id:int,name:string}>
     */
    public static function standorte(int $kundeId): array
    {
        return array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['name']], Database::select(
            "SELECT DISTINCT s.id, s.name FROM freigaben f JOIN sites s ON s.id = f.ziel_id
             WHERE f.subject_type = 'kunde' AND f.subject_id = ? AND f.ziel_art = 'site' ORDER BY s.name, s.id", [$kundeId]));
    }

    /** @return list<array<string,mixed>> die Eventworx-Vorgänge des Kunden (zur Auswahl) */
    public static function vorgaenge(int $kundeId): array
    {
        return Database::select('SELECT id, ewx_nummer, ewx_art, titel, von, bis FROM kunden_vorgaenge WHERE kunde_id = ? ORDER BY von DESC, id DESC', [$kundeId]);
    }

    /** @return list<array<string,mixed>> Inventar zur Auswahl – nur mit „Datenquellen ansehen“ */
    public static function inventar(): array
    {
        return Auth::can('datenquellen.view') ? Database::select('SELECT id, kennung, name FROM inventar ORDER BY kennung, id') : [];
    }

    /** Woher ein Nachweis stammt – als Satzteil für Kunde und Personal. */
    public static function quelleText(array $n): string
    {
        if ((string) $n['quelle'] !== 'ewx_vorgang') {
            return self::QUELLEN['hand'];
        }
        $herkunft = json_decode((string) ($n['herkunft'] ?? ''), true);
        $nummer = (string) ($n['vorgang_nummer'] ?? '') !== '' ? (string) $n['vorgang_nummer'] : (string) (is_array($herkunft) ? ($herkunft['job_nummer'] ?? '') : '');
        $art = match ((string) ($n['vorgang_art'] ?? '')) {
            'order'        => 'Auftrag',
            'deliverynote' => 'Lieferschein',
            default        => 'Vorgang',
        };
        return 'aus Eventworx-' . $art . ($nummer !== '' ? ' ' . $nummer : '');
    }

    // ------------------------------------------------------------ intern

    /** Warum diese Bytes kein Nachweis-PDF sind – oder null. */
    private static function pdfPruefen(string $bytes): ?string
    {
        if ($bytes === '') {
            return 'Die Datei ist leer.';
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return self::zuGross();
        }
        if (!str_starts_with($bytes, '%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'application/pdf') {
            return 'Nur PDF-Dateien – diese Datei ist keine.';
        }
        return null;
    }

    private static function zuGross(): string
    {
        return 'Die Datei ist größer als 10 MB. Bitte kleiner speichern (etwa ohne eingebettete Bilder) oder in mehrere Nachweise teilen.';
    }

    /** JJJJ-MM-TT oder TT.MM.JJJJ → JJJJ-MM-TT (2000–2100), sonst null. */
    private static function datumLesen(string $t): ?string
    {
        $t = trim($t);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) === 1) {
            [$j, $mo, $ta] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $t, $m) === 1) {
            [$j, $mo, $ta] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return null;
        }
        return $j >= 2000 && $j <= 2100 && checkdate($mo, $ta, $j) ? sprintf('%04d-%02d-%02d', $j, $mo, $ta) : null;
    }

    /** Eine Zeile mit ihren Abrufen löschen, die Datei nur, wenn sie sonst niemand nutzt. */
    private static function zeileLoeschen(array $n): void
    {
        $id = (int) $n['id'];
        Database::execute("DELETE FROM kunden_abrufe WHERE art = 'nachweis' AND ziel_id = ?", [$id]);
        Database::execute('DELETE FROM pruefnachweise WHERE id = ?', [$id]);
        if ($n['datei_id'] !== null) {
            $datei = (int) $n['datei_id'];
            Database::execute('DELETE FROM pruefnachweis_dateien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM pruefnachweise WHERE datei_id = ?)', [$datei, $datei]);
        }
    }

    /**
     * Alles oder nichts – unter SQLite mit BEGIN IMMEDIATE (die Schreibsperre
     * gleich am Anfang); in einer laufenden Transaktion (Kunde löschen) nur mit.
     */
    private static function transaktion(callable $arbeit): mixed
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            return $arbeit();
        }
        $sqlite = Database::driver() === 'sqlite';
        $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
        try {
            $ergebnis = $arbeit();
            $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
            return $ergebnis;
        } catch (\Throwable $e) {
            try {
                $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (\Throwable) {
                // schon zurückgerollt
            }
            throw $e;
        }
    }
}
