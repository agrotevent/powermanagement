<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Services\BenutzerService;
use App\Services\Eventworx;
use App\Services\EventworxFehler;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Session;
use App\Support\Settings;
use App\Support\Zeit;

/**
 * Kunden, ihre Zugänge und ihre Freigaben – für das Personal (Kunden &
 * Kundenbereich).
 *
 * Jede Unter-ID (Zugang, Freigabe) wird gegen den Kunden geprüft: ein Zugang
 * eines anderen Kunden ist hier „nicht vorhanden“. Rechte prüft die Route
 * (kunden.view / kunden.manage); Standorte freigeben zusätzlich nur, wer
 * alles sieht (BenutzerService::freigabeFuerKunde).
 *
 * Im Audit stehen nur IDs – nie Kundennamen, E-Mail-Adressen, Kennwörter
 * oder Links.
 */
final class KundenService
{
    /** Zustände eines Zugangs, in dieser Rangfolge (der erste zutreffende gilt). */
    public const ZUSTAENDE = [
        'deaktiviert'     => 'deaktiviert',
        'gesperrt'        => 'gesperrt (Fehlversuche)',
        'abgelaufen'      => 'außerhalb seiner Zeit',
        'link_offen'      => 'Einrichtungslink offen',
        'link_abgelaufen' => 'Einrichtungslink abgelaufen',
        'erstanmeldung'   => 'vorläufiges Kennwort',
        'aktiv'           => 'aktiv',
    ];

    /** Die Felder eines Zugangs, die das Personal sieht – nie Kennwort-Hash oder Link-HMAC. */
    private const ZUGANG_FELDER = 'id, username, full_name, email, is_active, must_change_password, gueltig_ab, gueltig_bis,
        failed_logins, locked_until, last_login_at, einrichtung_bis, (einrichtung_hash IS NOT NULL) AS link_hinterlegt,
        kunde_id, created_at, updated_at';

    /** @var array<int,?array<string,mixed>> */
    private static array $cache = [];

    /**
     * Was beim Vergleich zweier Kundennamen nicht zählt: Rechtsformen und
     * Bindewörter („Muster Events GmbH & Co. KG“ ist „Muster Events“).
     */
    private const NAME_FUELLWOERTER = ['gmbh', 'mbh', 'ggmbh', 'ag', 'kg', 'kgaa', 'ug', 'ohg', 'gbr', 'partg', 'se', 'eg', 'ev', 'ek', 'ltd', 'llc', 'inc',
        'co', 'und', 'haftungsbeschränkt', 'gemeinnützige'];

    /** Sitzung: Rückfrage nach einer Zuordnung mit abweichendem Namen (nur IDs und der Satz dazu). */
    private const RUECKFRAGE = 'kunden_rueckfrage';

    /** Sitzung: Treffer der Eventworx-Suche je Kunde – nur Kennung, Nummer, Art (keine Kundendaten). */
    public const EWX_TREFFER = 'ewx_kunde_treffer';
    private const EWX_TREFFER_HOECHSTENS = 20;

    /** Was mit einem Kunden verknüpft wird: Aufträge und Lieferscheine (ein Angebot ist noch keine Lieferung). */
    public const EWX_ARTEN = ['order', 'deliverynote'];

    // ------------------------------------------------------------ Kunden

    /**
     * Alle Kunden mit Zahlen für die Liste.
     *
     * @param array{suche?:string,aktiv?:string} $filter aktiv: '1' aktiv, '0' beendet, sonst alle
     * @return list<array<string,mixed>>
     */
    public static function alle(array $filter = []): array
    {
        // Gesucht wird in PHP: LOWER() kennt unter SQLite nur ASCII – „Müller“ fände „MÜLLER“ sonst nicht.
        $suche = trim((string) ($filter['suche'] ?? ''));
        $kunden = array_values(array_filter(Database::select('SELECT * FROM kunden ORDER BY name, id'), static fn (array $k): bool
            => $suche === '' || mb_stripos((string) $k['name'], $suche) !== false || mb_stripos((string) $k['kundennummer'], $suche) !== false));
        $aktiv = (string) ($filter['aktiv'] ?? '');
        $out = [];
        foreach ($kunden as $k) {
            $k['sperre'] = Kundenbereich::kundeSperre((int) $k['id']);
            if (($aktiv === '1' && $k['sperre'] !== null) || ($aktiv === '0' && $k['sperre'] === null)) {
                continue;
            }
            $out[] = $k + self::zahlen((int) $k['id']);
        }
        return $out;
    }

    /** @return array<string,mixed>|null ein Kunde (je Anfrage gemerkt) */
    public static function find(int $id): ?array
    {
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = $id > 0 ? Database::first('SELECT * FROM kunden WHERE id = ?', [$id]) : null;
        }
        return self::$cache[$id];
    }

    /**
     * Zahlen eines Kunden: Zugänge, jetzt sichtbare Locations (dasselbe
     * Prädikat wie im Kundenbereich), kommende, Freigaben, Protokolle,
     * freigegebene Prüfnachweise.
     *
     * @return array{zugaenge:int,locations_aktiv:int,kommend:int,freigaben:int,berichte:int,nachweise:int,vorgaenge:int,abrufe:int}
     */
    public static function zahlen(int $kundeId): array
    {
        return [
            'zugaenge'        => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE kunde_id = ?', [$kundeId]),
            'locations_aktiv' => count(array_unique(array_map(static fn (array $f): int => (int) $f['ziel_id'], Kundenbereich::freigaben($kundeId)))),
            'kommend'         => count(Kundenbereich::kommend($kundeId)),
            'freigaben'       => (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$kundeId]),
            'berichte'        => (int) Database::scalar("SELECT COUNT(*) FROM berichte WHERE kunde_id = ? AND status IN ('abgeschlossen', 'ersetzt')", [$kundeId]),
            // Wie im Kundenbereich: freigegeben und mit Datei.
            'nachweise'       => (int) Database::scalar("SELECT COUNT(*) FROM pruefnachweise n JOIN pruefnachweis_dateien d ON d.id = n.datei_id
                WHERE n.kunde_id = ? AND n.status = 'freigegeben'", [$kundeId]),
            'vorgaenge'       => (int) Database::scalar('SELECT COUNT(*) FROM kunden_vorgaenge WHERE kunde_id = ?', [$kundeId]),
            'abrufe'          => (int) Database::scalar('SELECT COUNT(*) FROM kunden_abrufe WHERE kunde_id = ?', [$kundeId]),
        ];
    }

    /**
     * Reiter Übersicht: „Der Kunde sieht derzeit …“ – genau das, was der
     * Kundenbereich ihm zeigt (KundenSicht::zusammenfassung(), dieselben
     * Prädikate) –, dazu, wie viele seiner Zugänge sich anmelden können.
     *
     * @return array{gesperrt:?string,locations:list<array<string,mixed>>,kommend:list<array{name:string,ab:string}>,berichte:list<array<string,mixed>>,nachweise:list<array<string,mixed>>,zugaenge_aktiv:int}
     */
    public static function uebersicht(int $kundeId): array
    {
        return KundenSicht::zusammenfassung($kundeId) + ['zugaenge_aktiv' => self::aktiveZugaenge($kundeId)];
    }

    /**
     * Wie viele Zugänge des Kunden sich jetzt anmelden können (oder es mit
     * Einrichtungslink bzw. vorläufigem Kennwort gleich können) – 0, wenn
     * der Kunde beendet ist.
     */
    public static function aktiveZugaenge(int $kundeId): int
    {
        if (Kundenbereich::kundeSperre($kundeId) !== null) {
            return 0;
        }
        $aktiv = 0;
        foreach (self::zugaenge($kundeId) as $z) {
            $aktiv += in_array($z['zustand'], ['aktiv', 'erstanmeldung', 'link_offen'], true) ? 1 : 0;
        }
        return $aktiv;
    }

    /**
     * Einen Kunden anlegen oder ändern. Name 2–255 (Pflicht), Kundennummer
     * ≤ 64 (frei, aber nicht doppelt), aktiv, Zugang endet am, Notizen
     * (≤ 5000, nur für das Personal). Wird der Kunde beendet (inaktiv oder
     * abgelaufen), enden die Sitzungen seiner Zugänge bei ihrer nächsten
     * Anfrage (Auth::zugangSperre).
     *
     * @param array<string,mixed> $daten name, kundennummer, aktiv, gueltig_bis, notizen
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        if ($id !== null && self::find($id) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        $text = static fn (string $feld): string => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($daten[$feld] ?? null) ? (string) $daten[$feld] : ''));
        $name = (string) preg_replace('/\s+/u', ' ', $text('name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
            return ['ok' => false, 'message' => 'Der Kunde braucht einen Namen (2 bis 255 Zeichen).'];
        }
        $nummer = $text('kundennummer');
        if (mb_strlen($nummer) > 64) {
            return ['ok' => false, 'message' => 'Die Kundennummer hat höchstens 64 Zeichen.'];
        }
        foreach ($nummer === '' ? [] : Database::select("SELECT id, kundennummer FROM kunden WHERE kundennummer <> ''") as $andere) {
            if ((int) $andere['id'] !== $id && mb_strtolower((string) $andere['kundennummer']) === mb_strtolower($nummer)) {
                return ['ok' => false, 'message' => 'Diese Kundennummer hat schon ein anderer Kunde.'];
            }
        }
        $bisEingabe = $text('gueltig_bis');
        $bis = Zeit::ausEingabe($bisEingabe, true);
        if ($bisEingabe !== '' && $bis === null) {
            return ['ok' => false, 'message' => 'Kein gültiges Datum: „' . mb_substr($bisEingabe, 0, 40) . '“.'];
        }
        $notizen = str_replace(["\r\n", "\r"], "\n", is_scalar($daten['notizen'] ?? null) ? (string) $daten['notizen'] : '');
        $notizen = trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $notizen));
        if (mb_strlen($notizen) > 5000) {
            return ['ok' => false, 'message' => 'Die Notizen haben höchstens 5000 Zeichen.'];
        }
        $felder = [
            'name'         => $name,
            'kundennummer' => $nummer,
            'aktiv'        => !empty($daten['aktiv']) ? 1 : 0,
            'gueltig_bis'  => $bis,
            'notizen'      => $notizen,
            'updated_at'   => gmdate('c'),
        ];
        if ($id !== null) {
            $vorher = self::find($id);
            Database::update('kunden', $id, $felder);
            $details = ['kunde_id' => $id];
            if ((int) $vorher['aktiv'] !== $felder['aktiv']) {
                $details['aktiv'] = $felder['aktiv'];
            }
        } else {
            $id = Database::insert('kunden', $felder + ['created_by' => Auth::id(), 'created_at' => gmdate('c')]);
            $details = ['kunde_id' => $id, 'neu' => true];
        }
        self::vergessen();
        Audit::log('kunden.speichern', 'kunde', (string) $id, $details);
        return ['ok' => true, 'message' => 'Kunde gespeichert.', 'id' => $id];
    }

    /**
     * Einen Kunden ganz löschen – nur einen beendeten (inaktiv), und nur mit
     * seinem Namen als Bestätigung. Weg sind danach: seine Zugänge, seine
     * Freigaben, seine Eventworx-Vorgänge, seine Prüfnachweise (mit den
     * Dateien, die sonst niemand nutzt) und die Abrufe. Übergabeberichte
     * bleiben (Aufbewahrung), nur ohne Zuordnung zum Kunden.
     *
     * @return array{ok:bool,message:string}
     */
    public static function loeschen(int $id, string $bestaetigung): array
    {
        $kunde = self::find($id);
        if ($kunde === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        if ((int) $kunde['aktiv'] === 1) {
            return ['ok' => false, 'message' => 'Löschen lässt sich nur ein beendeter Kunde – erst „aktiv“ ausschalten und speichern.'];
        }
        $gleich = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        if ($gleich($bestaetigung) === '' || $gleich($bestaetigung) !== $gleich((string) $kunde['name'])) {
            return ['ok' => false, 'message' => 'Zum Löschen bitte den Namen des Kunden genau eintragen.'];
        }
        $zahlen = self::zahlen($id);
        $nachweise = (int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ?', [$id]);
        // Alle zugeordneten Berichte (auch Entwürfe) verlieren die Zuordnung.
        $berichte = (int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE kunde_id = ?', [$id]);
        self::transaktion(static function () use ($id): void {
            foreach (Database::select('SELECT id FROM users WHERE kunde_id = ?', [$id]) as $u) {
                BenutzerService::kontoEntfernen((int) $u['id']);
            }
            Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$id]);
            Database::execute('DELETE FROM kunden_vorgaenge WHERE kunde_id = ?', [$id]);
            // Nachweise mit ihren Abrufen; gleiche Bytes liegen nur einmal da –
            // eine Datei, die noch ein anderer Nachweis nutzt, bleibt.
            Pruefnachweise::kundeEntfernt($id);
            Database::execute('DELETE FROM kunden_abrufe WHERE kunde_id = ?', [$id]);
            // Übergabeberichte bleiben für die Aufbewahrung – nur ohne Kunde.
            BerichtService::kundeEntfernt($id);
            Database::delete('kunden', $id);
        });
        self::vergessen();
        Zugriff::vergessen();
        Audit::log('kunden.loeschen', 'kunde', (string) $id, [
            'kunde_id' => $id, 'zugaenge' => $zahlen['zugaenge'], 'nachweise' => $nachweise, 'berichte' => $berichte,
        ]);
        return ['ok' => true, 'message' => 'Kunde gelöscht.'];
    }

    // ------------------------------------------------------------ Zugänge

    /**
     * Die Zugänge eines Kunden – ohne Kennwort-Hash und Link-HMAC, mit Zustand.
     *
     * @return list<array<string,mixed>>
     */
    public static function zugaenge(int $kundeId): array
    {
        $zugaenge = Database::select('SELECT ' . self::ZUGANG_FELDER . ' FROM users WHERE kunde_id = ? ORDER BY username', [$kundeId]);
        return array_map([self::class, 'mitZustand'], $zugaenge);
    }

    /**
     * Ein Zugang – nur, wenn er zu diesem Kunden gehört; sonst null (ein
     * Zugang eines anderen Kunden ist hier nicht vorhanden).
     *
     * @return array<string,mixed>|null
     */
    public static function zugang(int $kundeId, int $userId): ?array
    {
        $z = Database::first('SELECT ' . self::ZUGANG_FELDER . ' FROM users WHERE id = ? AND kunde_id = ?', [$userId, $kundeId]);
        return $z === null ? null : self::mitZustand($z);
    }

    /**
     * Einen Zugang anlegen – über einen Einrichtungslink (Vorgabe) oder ein
     * vorläufiges Kennwort. Das Geheimnis steht nur in dieser Rückgabe; der
     * Aufrufer zeigt es genau einmal (nie in Flash, Sitzung, Adresse, Log).
     *
     * Der Benutzername muss frei sein, ohne Rücksicht auf Groß- und
     * Kleinschreibung („Muster“ neben „muster“ wäre eine Verwechslung, die
     * jemand ausnutzt). Keine Rolle, keine Freigaben am Konto.
     *
     * @param array<string,mixed> $daten username, full_name, email
     * @param string $weg link | kennwort
     * @return array{ok:bool,message:string,user_id?:int,username?:string,weg?:string,link?:?string,pfad?:string,kennwort?:string,bis?:string}
     */
    public static function zugangAnlegen(int $kundeId, array $daten, string $weg): array
    {
        if (self::find($kundeId) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        if (Kundenbereich::kundeSperre($kundeId) !== null) {
            return ['ok' => false, 'message' => 'Der Kunde ist nicht aktiv – Zugänge erst, wenn er wieder aktiv ist.'];
        }
        if (!in_array($weg, ['link', 'kennwort'], true)) {
            return ['ok' => false, 'message' => 'Bitte wählen: Einrichtungslink oder vorläufiges Kennwort.'];
        }
        $wert = static fn (string $feld): string => trim(is_scalar($daten[$feld] ?? null) ? (string) $daten[$feld] : '');
        $username = $wert('username');
        if (preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $username) !== 1) {
            return ['ok' => false, 'message' => 'Benutzername: 2–64 Zeichen, Buchstaben, Ziffern und . _ @ -.'];
        }
        if (Database::first('SELECT id FROM users WHERE LOWER(username) = ?', [strtolower($username)]) !== null) {
            return ['ok' => false, 'message' => 'Diesen Benutzernamen gibt es schon (auch in anderer Schreibweise).'];
        }
        $pruefung = self::personPruefen($wert('full_name'), $wert('email'));
        if ($pruefung !== null) {
            return ['ok' => false, 'message' => $pruefung];
        }
        // Beim Link-Weg kennt niemand das Kennwort – bis der Kunde eines festlegt.
        $kennwort = $weg === 'kennwort' ? Crypto::randomPassword(16) : null;
        $id = self::kontoErzeugen($kundeId, $username, $wert('full_name'), $wert('email'),
            password_hash($kennwort ?? bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $kennwort !== null);
        $ergebnis = ['ok' => true, 'message' => 'Zugang „' . $username . '“ angelegt.', 'user_id' => $id, 'username' => $username, 'weg' => $weg];
        if ($kennwort !== null) {
            $ergebnis['kennwort'] = $kennwort;
        } else {
            $link = Einrichtung::erzeugen($id);
            $ergebnis += ['link' => Einrichtung::link($link['token']), 'pfad' => Einrichtung::pfad($link['token']), 'bis' => $link['bis']];
        }
        Audit::log('kunden.zugang_anlegen', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'user_id' => $id, 'weg' => $weg]);
        return $ergebnis;
    }

    /**
     * Ein Kundenzugang – ein Konto mit kunde_id, ohne Rollen und ohne
     * Freigaben am Konto. Ohne Prüfung und ohne Audit (das tut, wer ruft).
     *
     * @internal zugangAnlegen(); 017 (SSO) legt darüber SSO-Konten an.
     */
    public static function kontoErzeugen(int $kundeId, string $username, string $name, string $email, string $kennwortHash, bool $mussAendern): int
    {
        return Database::insert('users', [
            'username'             => $username,
            'full_name'            => mb_substr($name, 0, 190),
            'email'                => mb_substr($email, 0, 190),
            'password_hash'        => $kennwortHash,
            'is_active'            => 1,
            'must_change_password' => $mussAendern ? 1 : 0,
            'kunde_id'             => $kundeId,
            'created_at'           => gmdate('c'),
        ]);
    }

    /**
     * Name, E-Mail, aktiv und Ende eines Zugangs ändern. Deaktiviert oder
     * abgelaufen endet seine Sitzung bei der nächsten Anfrage.
     *
     * @param array<string,mixed> $daten full_name, email, is_active, gueltig_bis
     * @return array{ok:bool,message:string}
     */
    public static function zugangSpeichern(int $kundeId, int $userId, array $daten): array
    {
        $z = self::zugang($kundeId, $userId);
        if ($z === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        $wert = static fn (string $feld): string => trim(is_scalar($daten[$feld] ?? null) ? (string) $daten[$feld] : '');
        $pruefung = self::personPruefen($wert('full_name'), $wert('email'));
        if ($pruefung !== null) {
            return ['ok' => false, 'message' => $pruefung];
        }
        $bis = Zeit::ausEingabe($wert('gueltig_bis'), true);
        if ($wert('gueltig_bis') !== '' && $bis === null) {
            return ['ok' => false, 'message' => 'Kein gültiges Datum: „' . mb_substr($wert('gueltig_bis'), 0, 40) . '“.'];
        }
        Database::update('users', $userId, [
            'full_name'   => mb_substr($wert('full_name'), 0, 190),
            'email'       => mb_substr($wert('email'), 0, 190),
            'is_active'   => !empty($daten['is_active']) ? 1 : 0,
            'gueltig_bis' => $bis,
            'updated_at'  => gmdate('c'),
        ]);
        Audit::log('kunden.zugang_speichern', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'user_id' => $userId]);
        return ['ok' => true, 'message' => 'Zugang gespeichert.'];
    }

    /**
     * Einen neuen Einrichtungslink – der alte gilt damit nicht mehr. Hat der
     * Zugang noch ein vorläufiges Kennwort, gilt auch das nicht mehr (der Link
     * ersetzt es); ein eigenes Kennwort des Kunden bleibt, bis er den Link
     * einlöst.
     *
     * @return array{ok:bool,message:string,username?:string,link?:?string,pfad?:string,bis?:string}
     */
    public static function zugangLink(int $kundeId, int $userId): array
    {
        $z = self::zugang($kundeId, $userId);
        $fehler = self::zugangFehler($kundeId, $z);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        if ((int) $z['must_change_password'] === 1) {
            Database::update('users', $userId, ['password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'must_change_password' => 0]);
        }
        $link = Einrichtung::erzeugen($userId);
        Audit::log('kunden.zugang_link', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'user_id' => $userId]);
        return ['ok' => true, 'message' => 'Neuer Einrichtungslink für „' . $z['username'] . '“ – ein früherer gilt nicht mehr.',
            'username' => (string) $z['username'], 'link' => Einrichtung::link($link['token']), 'pfad' => Einrichtung::pfad($link['token']), 'bis' => $link['bis']];
    }

    /**
     * Ein neues vorläufiges Kennwort – bei der nächsten Anmeldung muss der
     * Kunde ein eigenes festlegen. Ein offener Einrichtungslink gilt nicht
     * mehr; offene Sitzungen mit dem alten Kennwort enden.
     *
     * @return array{ok:bool,message:string,username?:string,kennwort?:string}
     */
    public static function zugangKennwort(int $kundeId, int $userId): array
    {
        $z = self::zugang($kundeId, $userId);
        $fehler = self::zugangFehler($kundeId, $z);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $kennwort = Crypto::randomPassword(16);
        Database::update('users', $userId, [
            'password_hash'        => password_hash($kennwort, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'einrichtung_hash'     => null,
            'einrichtung_bis'      => null,
            'failed_logins'        => 0,
            'locked_until'         => null,
            'updated_at'           => gmdate('c'),
        ]);
        Audit::log('kunden.zugang_kennwort', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'user_id' => $userId]);
        return ['ok' => true, 'message' => 'Neues vorläufiges Kennwort für „' . $z['username'] . '“.', 'username' => (string) $z['username'], 'kennwort' => $kennwort];
    }

    /** @return array{ok:bool,message:string} */
    public static function zugangEntsperren(int $kundeId, int $userId): array
    {
        if (self::zugang($kundeId, $userId) === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        Database::update('users', $userId, ['failed_logins' => 0, 'locked_until' => null]);
        Audit::log('kunden.zugang_entsperren', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'user_id' => $userId]);
        return ['ok' => true, 'message' => 'Zugang entsperrt.'];
    }

    /** @return array{ok:bool,message:string} */
    public static function zugangLoeschen(int $kundeId, int $userId): array
    {
        if (self::zugang($kundeId, $userId) === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        BenutzerService::kontoEntfernen($userId);
        Audit::log('kunden.zugang_loeschen', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'user_id' => $userId]);
        return ['ok' => true, 'message' => 'Zugang gelöscht.'];
    }

    // ------------------------------------------------------------ Freigaben

    /**
     * Alle Freigaben eines Kunden – auch künftige und abgelaufene –, mit
     * Zustand kommend | wirksam | abgelaufen und dem Namen des Standorts.
     *
     * @return list<array<string,mixed>>
     */
    public static function freigaben(int $kundeId, ?int $jetzt = null): array
    {
        $jetzt ??= time();
        $liste = Database::select(
            "SELECT f.*, s.name AS site_name FROM freigaben f LEFT JOIN sites s ON s.id = f.ziel_id AND f.ziel_art = 'site'
             WHERE f.subject_type = 'kunde' AND f.subject_id = ? ORDER BY f.gueltig_bis DESC, f.id DESC",
            [$kundeId]
        );
        foreach ($liste as &$f) {
            $ab = Zeit::ts($f['gueltig_ab']);
            $f['zustand'] = match (true) {
                $f['ziel_art'] !== 'site'                   => 'unwirksam',   // per SQL – wirkt für Kunden nie
                Zeit::imFenster($jetzt, $f['gueltig_ab'], $f['gueltig_bis']) => 'wirksam',
                $ab !== null && $ab > $jetzt                => 'kommend',
                default                                     => 'abgelaufen',
            };
        }
        return $liste;
    }

    /**
     * Eine Freigabe für den Kunden anlegen (BenutzerService::freigabeFuerKunde).
     *
     * Vorher: überschneidet sich der Datenzeitraum am selben Standort mit dem
     * eines anderen Kunden, wird nichts gespeichert – die Rückgabe nennt die
     * Überschneidungen. Mit $daten['ueberschneidung_ok'] (bewusst so gewollt)
     * wird gespeichert, und das Audit hält es fest.
     *
     * @param array<string,mixed> $daten site_id, gueltig_ab, gueltig_bis, daten_ab, daten_bis,
     *        daten_wie_gueltig, notiz (Veranstaltung), ueberschneidung_ok
     * @return array{ok:bool,message:string,id?:int,ueberschneidungen?:list<array<string,mixed>>}
     */
    public static function freigabeSpeichern(int $kundeId, array $daten): array
    {
        if (self::find($kundeId) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        if (!BenutzerService::darfFreigabeVergeben('site', false)) {
            return ['ok' => false, 'message' => 'Standorte für Kunden freigeben darf nur, wer selbst alle Standorte sieht („Alle Standorte, Anlagen und Messdaten“).'];
        }
        $f = BenutzerService::kundenFreigabeLesen($daten);
        if (!$f['ok']) {
            return ['ok' => false, 'message' => $f['message']];
        }
        $ueber = self::ueberschneidungen((int) $f['site_id'], (int) Zeit::ts($f['daten_ab']), (int) Zeit::ts($f['daten_bis']), $kundeId);
        if ($ueber !== [] && empty($daten['ueberschneidung_ok'])) {
            return ['ok' => false, 'ueberschneidungen' => $ueber, 'message' => 'Der Datenzeitraum überschneidet sich an diesem Standort mit dem '
                . (count($ueber) === 1 ? 'eines anderen Kunden' : 'anderer Kunden') . ' – der Kunde sähe deren Messdaten. Nichts gespeichert.'];
        }
        return BenutzerService::freigabeFuerKunde($kundeId, $daten, $ueber !== []);
    }

    /** @return array<string,mixed>|null eine Freigabe dieses Kunden – sonst null */
    public static function freigabe(int $kundeId, int $freigabeId): ?array
    {
        return Database::first("SELECT * FROM freigaben WHERE id = ? AND subject_type = 'kunde' AND subject_id = ?", [$freigabeId, $kundeId]);
    }

    /** @return array{ok:bool,message:string} */
    public static function freigabeLoeschen(int $kundeId, int $freigabeId): array
    {
        $f = self::freigabe($kundeId, $freigabeId);
        if ($f === null) {
            return ['ok' => false, 'message' => 'Diese Freigabe gibt es nicht.'];
        }
        if (!BenutzerService::darfFreigabeVergeben('site', false)) {
            return ['ok' => false, 'message' => 'Freigaben für Kunden entfernt nur, wer selbst alle Standorte sieht.'];
        }
        Database::delete('freigaben', $freigabeId);
        Zugriff::vergessen();
        Kundenbereich::vergessen();
        Audit::log('kunden.freigabe_loeschen', 'kunde', (string) $kundeId, [
            'kunde_id' => $kundeId, 'freigabe_id' => $freigabeId, 'site_id' => (int) $f['ziel_id'], 'gueltig_bis' => $f['gueltig_bis'],
        ]);
        return ['ok' => true, 'message' => 'Freigabe entfernt – der Kunde sieht den Standort darüber nicht mehr.'];
    }

    /**
     * Freigaben ANDERER Kunden am Standort, deren Datenzeitraum sich mit
     * [ab, bis) schneidet. Berühren zählt nicht (endet die eine um 12:00 und
     * beginnt die andere um 12:00, sieht keiner Daten des anderen). Auch
     * abgelaufene zählen: deren Messdaten gehören weiter dem anderen Kunden.
     * Eine Freigabe ohne Datenende (nur per SQL) hat ein leeres Fenster.
     *
     * @return list<array{freigabe_id:int,kunde_id:int,kunde:string,veranstaltung:string,daten_ab:string,daten_bis:string}>
     */
    public static function ueberschneidungen(int $siteId, int $datenAb, int $datenBis, int $ohneKundeId): array
    {
        $treffer = [];
        $zeilen = Database::select(
            "SELECT f.id, f.subject_id, f.daten_ab, f.daten_bis, f.notiz, k.name FROM freigaben f JOIN kunden k ON k.id = f.subject_id
             WHERE f.subject_type = 'kunde' AND f.ziel_art = 'site' AND f.ziel_id = ? AND f.subject_id <> ? ORDER BY f.daten_ab, f.id",
            [$siteId, $ohneKundeId]
        );
        foreach ($zeilen as $f) {
            $ab = Zeit::ts($f['daten_ab']);
            $bis = Zeit::ts($f['daten_bis']);
            if ($ab === null || $bis === null || !($ab < $datenBis && $datenAb < $bis)) {
                continue;
            }
            $treffer[] = ['freigabe_id' => (int) $f['id'], 'kunde_id' => (int) $f['subject_id'], 'kunde' => (string) $f['name'],
                'veranstaltung' => (string) $f['notiz'], 'daten_ab' => (string) $f['daten_ab'], 'daten_bis' => (string) $f['daten_bis']];
        }
        return $treffer;
    }

    /**
     * Ein Standort wird gelöscht: Freigaben von Kunden darauf gehen mit;
     * Vorgänge und Prüfnachweise bleiben beim Kunden, nur ohne Standort.
     */
    public static function standortEntfernt(int $siteId): void
    {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND ziel_art = 'site' AND ziel_id = ?", [$siteId]);
        Database::execute('UPDATE kunden_vorgaenge SET site_id = NULL WHERE site_id = ?', [$siteId]);
        Database::execute('UPDATE pruefnachweise SET site_id = NULL WHERE site_id = ?', [$siteId]);
        Zugriff::vergessen();
        Kundenbereich::vergessen();
    }

    // ------------------------------------------------------------ Vorgänge (Eventworx)

    /**
     * Die mit dem Kunden verknüpften Eventworx-Vorgänge (Aufträge,
     * Lieferscheine) – Quelle für Prüfnachweise am Vorgang und Vorschlag für
     * die Zuordnung von Übergabeberichten. Dazu, wie viele Nachweise aus dem
     * Vorgang stammen.
     *
     * @return list<array<string,mixed>>
     */
    public static function vorgaenge(int $kundeId): array
    {
        return Database::select('SELECT v.*, s.name AS standort_name,
                (SELECT COUNT(*) FROM pruefnachweise n WHERE n.vorgang_id = v.id AND n.kunde_id = v.kunde_id) AS nachweise
            FROM kunden_vorgaenge v LEFT JOIN sites s ON s.id = v.site_id
            WHERE v.kunde_id = ? ORDER BY v.von DESC, v.id DESC', [$kundeId]);
    }

    /** Ein Vorgang dieses Kunden – einer eines anderen ist „nicht vorhanden“. @return array<string,mixed>|null */
    public static function vorgang(int $kundeId, int $vorgangId): ?array
    {
        return $vorgangId > 0 ? Database::first('SELECT * FROM kunden_vorgaenge WHERE id = ? AND kunde_id = ?', [$vorgangId, $kundeId]) : null;
    }

    /**
     * Aufträge und Lieferscheine in Eventworx suchen (wartet auf Eventworx –
     * der Aufrufer gibt die Sitzung vorher frei). Jeder Treffer sagt, ob er
     * schon mit diesem oder einem anderen Kunden verknüpft ist. Die Treffer
     * merkt sich danach trefferMerken() – nur Kennung, Nummer, Art.
     *
     * Braucht „berichte.eventworx“: die Suche zeigt alle Vorgänge des
     * Mandanten, nicht nur die dieses Kunden.
     *
     * @return array{ok:bool,message:string,treffer:list<array<string,mixed>>}
     */
    public static function eventworxSuchen(int $kundeId, string $text): array
    {
        if (self::find($kundeId) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.', 'treffer' => []];
        }
        if (!Auth::can('berichte.eventworx')) {
            return ['ok' => false, 'message' => 'Eventworx durchsuchen darf nur, wer das Recht „Kundendaten aus Eventworx suchen und übernehmen“ hat.', 'treffer' => []];
        }
        if (!Eventworx::eingerichtet()) {
            return ['ok' => false, 'message' => 'Eventworx ist nicht eingerichtet (Systemeinstellungen).', 'treffer' => []];
        }
        try {
            $treffer = Eventworx::suchen($text, self::EWX_ARTEN);
        } catch (EventworxFehler $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'treffer' => []];
        }
        $host = (string) Settings::get(Eventworx::EINSTELLUNG_HOST, '');
        foreach ($treffer as &$t) {
            $v = Database::first('SELECT v.kunde_id, k.name FROM kunden_vorgaenge v JOIN kunden k ON k.id = v.kunde_id WHERE v.ewx_host = ? AND v.ewx_id = ?',
                [$host, (string) $t['id']]);
            $t['verknuepft'] = $v === null ? null : ((int) $v['kunde_id'] === $kundeId ? 'dieser' : (string) $v['name']);
        }
        unset($t);
        return ['ok' => true, 'message' => $treffer === [] ? 'Kein Auftrag und kein Lieferschein gefunden – am sichersten mit der Nummer suchen.' : '',
            'treffer' => $treffer];
    }

    /**
     * Die Treffer einer Suche für diesen Kunden merken (nach dem Warten, mit
     * offener Sitzung): höchstens 20, nur Kennung, Nummer und Art. Nur diese
     * Kennungen nimmt vorgangVerknuepfen() an.
     *
     * @param list<array<string,mixed>> $treffer
     */
    public static function trefferMerken(int $kundeId, array $treffer): void
    {
        $alle = Session::get(self::EWX_TREFFER, []);
        $alle = is_array($alle) ? $alle : [];
        $je = [];
        foreach (array_slice($treffer, 0, self::EWX_TREFFER_HOECHSTENS) as $t) {
            $id = (string) ($t['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9]{8,40}$/', $id) === 1) {
                $je[$id] = ['nummer' => mb_substr((string) ($t['nummer'] ?? ''), 0, 64), 'art' => mb_substr((string) ($t['art'] ?? ''), 0, 24)];
            }
        }
        $alle[$kundeId] = $je;
        Session::put(self::EWX_TREFFER, $alle);
    }

    /** @return array<string,array{nummer:string,art:string}> die gemerkten Treffer dieses Kunden */
    public static function treffer(int $kundeId): array
    {
        $alle = Session::get(self::EWX_TREFFER, []);
        return is_array($alle) && is_array($alle[$kundeId] ?? null) ? $alle[$kundeId] : [];
    }

    /**
     * Einen Eventworx-Vorgang mit dem Kunden verknüpfen (wartet auf Eventworx
     * – der Aufrufer gibt die Sitzung vorher frei; geschrieben wird nur in
     * die Datenbank). Die Kennung nur aus den gemerkten Treffern; der
     * Vorgang wird frisch gelesen.
     *
     * Kundenprüfung über die Kennung des Kunden in Eventworx (customerRefId):
     *   Kunde ohne Kennung, Vorgang mit  → nur bestätigt; der Kunde bekommt sie
     *   gleiche Kennung                  → verknüpft
     *   andere Kennung                   → abgelehnt
     *   Vorgang ohne Kennung             → nur bestätigt
     * Eine Kennung gehört höchstens einem Kunden, ein Vorgang auch. Mit
     * $stammdaten kommt eine fehlende Kundennummer aus Eventworx.
     *
     * @return array{ok:bool,message:string,bestaetigen?:bool,id?:int}
     */
    public static function vorgangVerknuepfen(int $kundeId, string $jobId, bool $bestaetigt, bool $stammdaten): array
    {
        $kunde = self::find($kundeId);
        if ($kunde === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        if (!Auth::can('berichte.eventworx')) {
            return ['ok' => false, 'message' => 'Vorgänge verknüpfen darf nur, wer das Recht „Kundendaten aus Eventworx suchen und übernehmen“ hat.'];
        }
        if (!array_key_exists($jobId, self::treffer($kundeId))) {
            return ['ok' => false, 'message' => 'Bitte den Vorgang über die Suche wählen.'];
        }
        try {
            $v = Eventworx::vorgang($jobId);
        } catch (EventworxFehler $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $verweis = $v['verweis'];
        if (!in_array($verweis['art'], self::EWX_ARTEN, true)) {
            return ['ok' => false, 'message' => 'Nur Aufträge und Lieferscheine – ein Angebot ist noch keine Lieferung.'];
        }
        $host = (string) $verweis['host'];
        $nummer = (string) $verweis['nummer'];
        $jobRef = (string) $verweis['kunde_ref'];
        $firma = (string) $v['gruppen']['kunde']['firma'];
        $vorhanden = Database::first('SELECT v.id, v.kunde_id, k.name FROM kunden_vorgaenge v JOIN kunden k ON k.id = v.kunde_id WHERE v.ewx_host = ? AND v.ewx_id = ?', [$host, $jobId]);
        if ($vorhanden !== null) {
            return ['ok' => false, 'message' => (int) $vorhanden['kunde_id'] === $kundeId ? 'Der Vorgang ' . $nummer . ' ist schon mit diesem Kunden verknüpft.'
                : 'Der Vorgang ' . $nummer . ' ist schon Kunde „' . $vorhanden['name'] . '“ zugeordnet.'];
        }
        $kundeRef = (string) ($kunde['ewx_kunde_ref'] ?? '');
        if ($kundeRef !== '' && (string) $kunde['ewx_host'] !== $host) {
            return ['ok' => false, 'message' => 'Der Kunde ist mit einem Kunden eines anderen Eventworx-Mandanten verknüpft – nichts verknüpft.'];
        }
        $refSetzen = false;
        if ($jobRef !== '' && $kundeRef !== '' && $jobRef !== $kundeRef) {
            return ['ok' => false, 'message' => 'Der Vorgang gehört in Eventworx zu einem anderen Kunden.'];
        }
        if ($jobRef !== '' && $kundeRef === '') {
            $anderer = Database::first('SELECT id, name FROM kunden WHERE ewx_host = ? AND ewx_kunde_ref = ? AND id <> ?', [$host, $jobRef, $kundeId]);
            if ($anderer !== null) {
                return ['ok' => false, 'message' => 'Dieser Kunde in Eventworx („' . $firma . '“) ist schon Kunde „' . $anderer['name'] . '“ zugeordnet.'];
            }
            if (!$bestaetigt) {
                return ['ok' => false, 'bestaetigen' => true, 'message' => 'Eventworx nennt als Kunden des Vorgangs ' . $nummer . ' „' . ($firma !== '' ? $firma : 'ohne Namen')
                    . '“. Ist das dieser Kunde? Dann gehören auch alle weiteren Vorgänge dieses Eventworx-Kunden hierher – und keine anderen.'];
            }
            $refSetzen = true;
        }
        if ($jobRef === '' && !$bestaetigt) {
            return ['ok' => false, 'bestaetigen' => true, 'message' => 'Eventworx nennt beim Vorgang ' . $nummer . ' keine Kunden-Kennung; als Kunde steht dort „'
                . ($firma !== '' ? $firma : 'nichts') . '“. Gehört der Vorgang zu diesem Kunden?'];
        }
        $tag = static fn (?string $iso): ?string => $iso === null ? null : Zeit::anzeige($iso, 'Y-m-d');
        $zeitraum = $v['gruppen']['zeitraum'];
        $kundennummer = mb_substr((string) $v['gruppen']['kunde']['kundennummer'], 0, 64);
        try {
            $id = 0;
            self::transaktion(static function () use (&$id, $kundeId, $kunde, $host, $jobId, $verweis, $nummer, $jobRef, $v, $tag, $zeitraum, $refSetzen, $firma, $stammdaten, $kundennummer): void {
                $id = Database::insert('kunden_vorgaenge', [
                    'kunde_id'       => $kundeId,
                    'ewx_host'       => $host,
                    'ewx_id'         => $jobId,
                    'ewx_art'        => (string) $verweis['art'],
                    'ewx_nummer'     => $nummer,
                    'ewx_kunde_ref'  => $jobRef,
                    'titel'          => mb_substr((string) $v['gruppen']['veranstaltung']['veranstaltung'], 0, 255),
                    'von'            => $tag($zeitraum['von'] ?? null) ?? $tag($zeitraum['bis'] ?? null),
                    'bis'            => $tag($zeitraum['bis'] ?? null) ?? $tag($zeitraum['von'] ?? null),
                    'verknuepft_von' => Auth::id(),
                    'verknuepft_am'  => gmdate('c'),
                ]);
                $felder = [];
                if ($refSetzen) {
                    $felder += ['ewx_host' => $host, 'ewx_kunde_ref' => $jobRef, 'ewx_kunde_name' => mb_substr($firma, 0, 255)];
                }
                if ($stammdaten && (string) $kunde['kundennummer'] === '' && $kundennummer !== '') {
                    $doppelt = false;
                    foreach (Database::select("SELECT id, kundennummer FROM kunden WHERE kundennummer <> '' AND id <> ?", [$kundeId]) as $andere) {
                        $doppelt = $doppelt || mb_strtolower((string) $andere['kundennummer']) === mb_strtolower($kundennummer);
                    }
                    if (!$doppelt) {
                        $felder['kundennummer'] = $kundennummer;
                    }
                }
                if ($felder !== []) {
                    Database::update('kunden', $kundeId, $felder + ['updated_at' => gmdate('c')]);
                }
            });
        } catch (\PDOException) {
            // Ein zweiter Prozess war schneller (UNIQUE Vorgang bzw. Eventworx-Kunde).
            return ['ok' => false, 'message' => 'Der Vorgang oder dieser Eventworx-Kunde ist inzwischen einem Kunden zugeordnet – bitte die Seite neu laden.'];
        }
        self::vergessen();
        Audit::log('kunden.vorgang_verknuepft', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'vorgang_id' => $id, 'ewx_nummer' => $nummer]
            + ($refSetzen ? ['kunde_ref' => 'gesetzt'] : []));
        return ['ok' => true, 'message' => 'Vorgang ' . $nummer . ' verknüpft.', 'id' => $id];
    }

    /**
     * Die Verknüpfung lösen. Prüfnachweise aus dem Vorgang bleiben beim
     * Kunden (mit ihrer Herkunft), nur ohne Verweis. Mit dem letzten Vorgang
     * geht auch die Eventworx-Kennung des Kunden – sie kam mit dem ersten.
     *
     * @return array{ok:bool,message:string}
     */
    public static function vorgangLoesen(int $kundeId, int $vorgangId): array
    {
        $v = self::vorgang($kundeId, $vorgangId);
        if ($v === null) {
            return ['ok' => false, 'message' => 'Diesen Vorgang hat der Kunde nicht.'];
        }
        $refWeg = false;
        self::transaktion(static function () use ($kundeId, $vorgangId, &$refWeg): void {
            Database::execute('UPDATE pruefnachweise SET vorgang_id = NULL WHERE vorgang_id = ?', [$vorgangId]);
            Database::delete('kunden_vorgaenge', $vorgangId);
            if ((int) Database::scalar('SELECT COUNT(*) FROM kunden_vorgaenge WHERE kunde_id = ?', [$kundeId]) === 0
                && (string) (self::find($kundeId)['ewx_kunde_ref'] ?? '') !== '') {
                Database::update('kunden', $kundeId, ['ewx_host' => '', 'ewx_kunde_ref' => null, 'ewx_kunde_name' => '', 'updated_at' => gmdate('c')]);
                $refWeg = true;
            }
        });
        self::vergessen();
        Audit::log('kunden.vorgang_geloest', 'kunde', (string) $kundeId, ['kunde_id' => $kundeId, 'vorgang_id' => $vorgangId,
            'ewx_nummer' => (string) $v['ewx_nummer']] + ($refWeg ? ['kunde_ref' => 'entfernt'] : []));
        return ['ok' => true, 'message' => 'Vorgang ' . $v['ewx_nummer'] . ' gelöst.' . ($refWeg ? ' Es war der letzte – die Verknüpfung mit dem Kunden in Eventworx ist damit auch weg.' : '')];
    }

    // ------------------------------------------------------------ Protokolle (Übergabeberichte)

    /**
     * Reiter Protokolle: die dem Kunden zugeordneten Übergabeberichte (jeder
     * Stand außer verworfen – ob der Kunde ihn sieht, steht dabei) und
     * Vorschläge: noch keinem Kunden zugeordnete Berichte, deren
     * Eventworx-Vorgang mit diesem Kunden verknüpft ist oder deren
     * Eventworx-Kunde seiner ist (vorschlagFuerBericht, nie automatisch).
     * Vorschläge nur, soweit der Fragende die Berichte selbst sieht.
     *
     * @return array{zugeordnet:list<array<string,mixed>>,vorschlaege:list<array<string,mixed>>}
     */
    public static function protokolle(int $kundeId): array
    {
        $kunde = self::find($kundeId);
        if ($kunde === null) {
            return ['zugeordnet' => [], 'vorschlaege' => []];
        }
        $felder = 'b.id, b.nummer, b.jahr, b.laufnr, b.fassung, b.status, b.site_id, b.standort_entfernt, b.erstellt_von, b.standort_name, b.titel,
            b.veranstaltung, b.kunde_name, b.pruefdatum, b.abgeschlossen_am, b.kunde_id, b.ewx_nummer, p.sha256 AS pdf_sha256';
        $gesperrt = Kundenbereich::kundeSperre($kundeId) !== null;
        $zugeordnet = [];
        foreach (Database::select("SELECT {$felder} FROM berichte b LEFT JOIN bericht_pdf p ON p.bericht_id = b.id
            WHERE b.kunde_id = ? AND b.status <> 'verworfen' ORDER BY b.pruefdatum DESC, b.id DESC", [$kundeId]) as $b) {
            $b['kunde_id'] = (int) $b['kunde_id'];
            $b['sichtbar'] = BerichtService::sichtbar($b);
            $b['beim_kunden'] = !$gesperrt && BerichtService::sichtbarFuerKunde($b, $kundeId) && $b['pdf_sha256'] !== null;
            $b['abruf'] = Abrufe::letzter('bericht', (int) $b['id']);
            $zugeordnet[] = $b;
        }
        $ref = (string) ($kunde['ewx_kunde_ref'] ?? '');
        $vorschlaege = [];
        foreach (Database::select("SELECT {$felder}, b.ewx_host, b.ewx_id, b.ewx_kunde_ref FROM berichte b LEFT JOIN bericht_pdf p ON p.bericht_id = b.id
            WHERE b.kunde_id IS NULL AND b.status <> 'verworfen' AND (
                (b.ewx_id <> '' AND EXISTS (SELECT 1 FROM kunden_vorgaenge v WHERE v.kunde_id = ? AND v.ewx_host = b.ewx_host AND v.ewx_id = b.ewx_id))
                OR (? <> '' AND b.ewx_kunde_ref = ? AND b.ewx_host = ?))
            ORDER BY b.pruefdatum DESC, b.id DESC LIMIT 50", [$kundeId, $ref, $ref, (string) $kunde['ewx_host']]) as $b) {
            $vorschlag = self::vorschlagFuerBericht($b);
            if (BerichtService::sichtbar($b) && $vorschlag !== null && (int) $vorschlag['kunde']['id'] === $kundeId) {
                $vorschlaege[] = $b + ['grund' => $vorschlag['grund']];
            }
        }
        return ['zugeordnet' => $zugeordnet, 'vorschlaege' => $vorschlaege];
    }

    /**
     * Ein Vorschlag, welchem Kunden ein Bericht gehört – nie mehr als ein
     * Vorschlag; zugeordnet wird nur von einer Person (kundeZuordnen).
     * Zuerst: der Eventworx-Vorgang des Berichts ist mit einem Kunden
     * verknüpft; sonst: Eventworx nennt beim Vorgang denselben Kunden
     * (ewx_kunde_ref). Nie über den Standort oder den Namen allein.
     *
     * @return array{kunde:array<string,mixed>,grund:string}|null
     */
    public static function vorschlagFuerBericht(array $bericht): ?array
    {
        $host = (string) ($bericht['ewx_host'] ?? '');
        $ewxId = (string) ($bericht['ewx_id'] ?? '');
        $nummer = (string) ($bericht['ewx_nummer'] ?? '');
        if ($ewxId !== '') {
            $v = Database::first('SELECT kunde_id, ewx_nummer FROM kunden_vorgaenge WHERE ewx_host = ? AND ewx_id = ?', [$host, $ewxId]);
            $kunde = $v !== null ? self::find((int) $v['kunde_id']) : null;
            if ($kunde !== null) {
                return ['kunde' => $kunde, 'grund' => 'Der Eventworx-Vorgang ' . ($nummer !== '' ? $nummer : (string) $v['ewx_nummer']) . ' ist mit diesem Kunden verknüpft.'];
            }
        }
        $ref = (string) ($bericht['ewx_kunde_ref'] ?? '');
        if ($ref !== '' && $host !== '') {
            $k = Database::first('SELECT id FROM kunden WHERE ewx_host = ? AND ewx_kunde_ref = ?', [$host, $ref]);
            $kunde = $k !== null ? self::find((int) $k['id']) : null;
            if ($kunde !== null) {
                return ['kunde' => $kunde, 'grund' => 'Eventworx nennt beim Vorgang' . ($nummer !== '' ? ' ' . $nummer : '') . ' denselben Kunden.'];
            }
        }
        return null;
    }

    /**
     * Ist der Kunde im Bericht derselbe wie der gewählte? Klein, ohne
     * Satzzeichen, Rechtsformen und Bindewörter; dann muss der eine Name im
     * anderen stehen – als ganze Wörter („Muster Events GmbH & Co. KG“ passt
     * zu „muster events“, „Musterfilm Produktion GmbH“ nicht). Leer passt nie.
     * Nur für die Rückfrage vor einer Zuordnung, nie für einen Vorschlag.
     */
    public static function namePasst(string $imBericht, string $kunde): bool
    {
        $a = self::nameKern($imBericht);
        $b = self::nameKern($kunde);
        if ($a === '' || $b === '') {
            return false;
        }
        return str_contains(' ' . $a . ' ', ' ' . $b . ' ') || str_contains(' ' . $b . ' ', ' ' . $a . ' ');
    }

    /**
     * Für die Karte „Kundenbereich“ am Bericht (nur mit „kunden.view“): wem
     * er gehört, ob der Kunde ihn jetzt sieht, wann er zuletzt geladen
     * wurde; mit „kunden.manage“ die Kundenauswahl und ein Vorschlag.
     * null = keine Karte (ohne Recht, verworfener Bericht).
     *
     * @return array<string,mixed>|null
     */
    public static function berichtKarte(array $bericht): ?array
    {
        if (!Auth::can('kunden.view') || (string) $bericht['status'] === 'verworfen') {
            return null;
        }
        $id = (int) $bericht['id'];
        $kunde = $bericht['kunde_id'] !== null ? self::find((int) $bericht['kunde_id']) : null;
        $kundeId = $kunde !== null ? (int) $kunde['id'] : 0;
        $pflegen = Auth::can('kunden.manage');
        $sperre = $kunde !== null ? Kundenbereich::kundeSperre($kundeId) : null;
        $abruf = Abrufe::letzter('bericht', $id);
        // Hat ihn zuletzt ein anderer Kunde geladen (vor einer Umzuordnung), steht das dabei.
        $anderer = $abruf !== null && (int) $abruf['kunde_id'] !== $kundeId ? self::find((int) $abruf['kunde_id']) : null;
        return [
            'kunde'      => $kunde,
            'gesperrt'   => $sperre !== null,
            'zugaenge'   => $kunde !== null ? self::aktiveZugaenge($kundeId) : 0,
            'beim_kunden' => $kunde !== null && $sperre === null && BerichtService::sichtbarFuerKunde($bericht, $kundeId) && BerichtService::pdfHash($id) !== null,
            'abruf'      => $abruf,
            'abruf_anderer' => $anderer !== null ? (string) $anderer['name'] : null,
            'pflegen'    => $pflegen,
            'kunden'     => $pflegen ? Database::select('SELECT id, name, kundennummer, aktiv FROM kunden ORDER BY name, id') : [],
            'vorschlag'  => $kunde === null ? self::vorschlagFuerBericht($bericht) : null,
            'rueckfrage' => $pflegen ? self::rueckfrage($id) : null,
        ];
    }

    /**
     * Rückfrage merken: die Zuordnung braucht eine Bestätigung (Namen weichen
     * ab). Nur IDs und der Satz dazu, für genau eine Seite.
     */
    public static function rueckfrageMerken(int $berichtId, int $kundeId, string $text, ?int $zurueck): void
    {
        Session::put(self::RUECKFRAGE, ['bericht' => $berichtId, 'kunde' => $kundeId, 'text' => mb_substr($text, 0, 600), 'zurueck' => $zurueck]);
    }

    /**
     * Die gemerkte Rückfrage zu diesem Bericht (oder, mit $berichtId null,
     * zu einem Bericht dieses Kunden: Reiter Protokolle) – einmal, dann weg.
     *
     * @return array{bericht:int,kunde:int,text:string,zurueck:?int}|null
     */
    public static function rueckfrage(?int $berichtId, ?int $zurueck = null): ?array
    {
        $r = Session::get(self::RUECKFRAGE);
        if (!is_array($r) || ($berichtId !== null ? (int) ($r['bericht'] ?? 0) !== $berichtId : (int) ($r['zurueck'] ?? 0) !== (int) $zurueck)) {
            return null;
        }
        Session::forget(self::RUECKFRAGE);
        return ['bericht' => (int) $r['bericht'], 'kunde' => (int) $r['kunde'], 'text' => (string) $r['text'],
            'zurueck' => isset($r['zurueck']) ? (int) $r['zurueck'] : null];
    }

    /** Für Prüfskripte und nach Änderungen. */
    public static function vergessen(): void
    {
        self::$cache = [];
        Kundenbereich::vergessen();
    }

    // ------------------------------------------------------------ intern

    /** @param array<string,mixed> $z */
    private static function mitZustand(array $z): array
    {
        $jetzt = time();
        $gesperrt = !empty($z['locked_until']) && (int) Zeit::ts((string) $z['locked_until']) > $jetzt;
        $linkBis = Zeit::ts($z['einrichtung_bis'] ?? null);
        $z['link_hinterlegt'] = (int) ($z['link_hinterlegt'] ?? 0) === 1;
        $z['zeitraum'] = Auth::kontoZeitraum($z, $jetzt);
        $z['zustand'] = match (true) {
            (int) $z['is_active'] !== 1                           => 'deaktiviert',
            $gesperrt                                             => 'gesperrt',
            $z['zeitraum'] !== null                               => 'abgelaufen',
            $z['link_hinterlegt'] && $linkBis !== null && $linkBis > $jetzt => 'link_offen',
            $linkBis !== null                                     => 'link_abgelaufen',
            (int) $z['must_change_password'] === 1                => 'erstanmeldung',
            default                                               => 'aktiv',
        };
        $z['gesperrt'] = $gesperrt;
        return $z;
    }

    /** Warum für diesen Zugang kein Link und kein Kennwort ausgegeben wird – oder null. */
    private static function zugangFehler(int $kundeId, ?array $z): ?string
    {
        return match (true) {
            $z === null                                    => 'Diesen Zugang gibt es nicht.',
            Kundenbereich::kundeSperre($kundeId) !== null  => 'Der Kunde ist nicht aktiv – erst wieder aktivieren.',
            (int) $z['is_active'] !== 1                    => 'Der Zugang ist deaktiviert – erst wieder aktivieren.',
            default                                        => null,
        };
    }

    /** Ein Kundenname für den Vergleich: klein, ohne Satzzeichen, Rechtsformen und Bindewörter. */
    private static function nameKern(string $name): string
    {
        $name = mb_strtolower(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // „e. V.“, „e. K.“, „e. G.“ als ein Wort, bevor die Satzzeichen fallen (auch mit schmalem Leerzeichen).
        $name = (string) preg_replace('/(?<![\p{L}\p{N}])e[\s\p{Z}]*\.[\s\p{Z}]*([vkg])(?![\p{L}\p{N}])\.?/u', ' e$1 ', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
        return implode(' ', array_filter(explode(' ', $name), static fn (string $w): bool => $w !== '' && !in_array($w, self::NAME_FUELLWOERTER, true)));
    }

    private static function personPruefen(string $name, string $email): ?string
    {
        if (mb_strlen($name) > 190 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            return 'Der Name hat höchstens 190 Zeichen, ohne Steuerzeichen.';
        }
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return 'Die E-Mail-Adresse sieht nicht gültig aus.';
        }
        return null;
    }

    /**
     * Alles oder nichts – unter SQLite mit BEGIN IMMEDIATE (die Schreibsperre
     * gleich am Anfang, wie bei den Übergabeberichten).
     */
    private static function transaktion(callable $arbeit): void
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            $arbeit();
            return;
        }
        $sqlite = Database::driver() === 'sqlite';
        $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
        try {
            $arbeit();
            $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
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
