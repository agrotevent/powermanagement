<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\BenutzerService;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
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
            'nachweise'       => (int) Database::scalar("SELECT COUNT(*) FROM pruefnachweise WHERE kunde_id = ? AND status = 'freigegeben'", [$kundeId]),
            'vorgaenge'       => (int) Database::scalar('SELECT COUNT(*) FROM kunden_vorgaenge WHERE kunde_id = ?', [$kundeId]),
            'abrufe'          => (int) Database::scalar('SELECT COUNT(*) FROM kunden_abrufe WHERE kunde_id = ?', [$kundeId]),
        ];
    }

    /**
     * Was der Kunde jetzt sieht, soweit es Locations sind – aus demselben
     * Prädikat wie der Kundenbereich (Kundenbereich::freigaben()).
     *
     * @return array{sperre:?string,locations:list<array<string,mixed>>,kommend:list<array<string,mixed>>,zugaenge_aktiv:int}
     */
    public static function uebersicht(int $kundeId): array
    {
        $mitName = static function (array $f): array {
            $f['site_name'] = (string) (Database::scalar('SELECT name FROM sites WHERE id = ?', [(int) $f['ziel_id']]) ?? '');
            return $f;
        };
        $aktiv = 0;
        foreach (self::zugaenge($kundeId) as $z) {
            $aktiv += in_array($z['zustand'], ['aktiv', 'erstanmeldung', 'link_offen'], true) ? 1 : 0;
        }
        return [
            'sperre'         => Kundenbereich::kundeSperre($kundeId),
            'locations'      => array_map($mitName, Kundenbereich::freigaben($kundeId)),
            'kommend'        => array_map($mitName, Kundenbereich::kommend($kundeId)),
            'zugaenge_aktiv' => $aktiv,
        ];
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
        self::transaktion(static function () use ($id): void {
            foreach (Database::select('SELECT id FROM users WHERE kunde_id = ?', [$id]) as $u) {
                BenutzerService::kontoEntfernen((int) $u['id']);
            }
            Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [$id]);
            Database::execute('DELETE FROM kunden_vorgaenge WHERE kunde_id = ?', [$id]);
            $dateien = array_map('intval', array_column(Database::select(
                'SELECT DISTINCT datei_id FROM pruefnachweise WHERE kunde_id = ? AND datei_id IS NOT NULL', [$id]), 'datei_id'));
            Database::execute('DELETE FROM pruefnachweise WHERE kunde_id = ?', [$id]);
            foreach ($dateien as $datei) {
                // Gleiche Bytes liegen nur einmal da – eine Datei, die noch ein
                // anderer Nachweis nutzt, bleibt.
                Database::execute('DELETE FROM pruefnachweis_dateien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM pruefnachweise WHERE datei_id = ?)', [$datei, $datei]);
            }
            Database::execute('DELETE FROM kunden_abrufe WHERE kunde_id = ?', [$id]);
            // Übergabeberichte bleiben für die Aufbewahrung – nur ohne Kunde.
            Database::execute('UPDATE berichte SET kunde_id = NULL WHERE kunde_id = ?', [$id]);
            Database::delete('kunden', $id);
        });
        self::vergessen();
        Zugriff::vergessen();
        Audit::log('kunden.loeschen', 'kunde', (string) $id, [
            'kunde_id' => $id, 'zugaenge' => $zahlen['zugaenge'], 'nachweise' => $nachweise, 'berichte' => $zahlen['berichte'],
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
