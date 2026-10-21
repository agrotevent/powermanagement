<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Permissions;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\Schutz;
use App\Auth\Zugriff;
use App\Kunden\Kundenbereich;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Benutzer, Rollen und Freigaben.
 *
 * Drei Stellschrauben, alle mit Zeit:
 *   1. das Konto selbst (gueltig_ab/gueltig_bis),
 *   2. jede Rollenzuweisung (Rolle „Kunde“ nur für die Veranstaltungswoche),
 *   3. jede Freigabe (was, wann gültig, welcher Datenzeitraum).
 *
 * Kundenzugänge (users.kunde_id) gehören nicht hierher: sie stehen nicht in
 * der Liste, lassen sich hier weder ändern noch sperren, entsperren oder
 * löschen, bekommen keine Freigaben am Konto – das alles macht die
 * Kundenverwaltung. Ihre Standorte kommen aus Freigaben am Kunden
 * (freigabeFuerKunde, subject_type 'kunde').
 */
final class BenutzerService
{
    /** Die Meldung, wenn hier jemand einen Kundenzugang anfassen will. */
    public const KUNDENZUGANG = 'Kundenzugänge pflegt die Kundenverwaltung.';

    public const ZIELE = [
        'alles'  => 'Alle Standorte und Anlagen',
        'site'   => 'Standort',
        'objekt' => 'Einzelne Anlage',
        'geraet' => 'Einzelnes Gerät (Datenquelle)',
    ];

    // ------------------------------------------------------------ Wer darf was vergeben

    /**
     * Darf der angemeldete Benutzer diese Rechte vergeben?
     *
     * Nur, was er selbst hat – „*“ (Administration) nur, wer selbst „*“ hat.
     * Sonst wäre „Benutzer verwalten“ eine Hintertür zu allem: sich selbst die
     * Administratorrolle geben, eine Rolle mit allen Rechten anlegen oder das
     * Kennwort des Administrators setzen und sich als er anmelden.
     *
     * @param list<string> $rechte
     */
    public static function darfVergeben(array $rechte): bool
    {
        $eigene = Auth::permissions();
        if (in_array('*', $eigene, true)) {
            return true;
        }
        foreach ($rechte as $recht) {
            if ($recht === '*' || !in_array($recht, $eigene, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> Rechte einer Rolle */
    public static function rollenRechte(int $rolleId): array
    {
        return array_column(Database::select('SELECT permission FROM role_permissions WHERE role_id = ?', [$rolleId]), 'permission');
    }

    /**
     * Rechte eines Kontos aus allen zugewiesenen Rollen – auch denen, die erst
     * später gelten: wer morgen Administrator ist, ist heute schon nicht mehr
     * von jemandem mit weniger Rechten zu verwalten.
     *
     * @return list<string>
     */
    public static function benutzerRechte(int $userId): array
    {
        return array_values(array_unique(array_column(Database::select(
            'SELECT rp.permission FROM role_permissions rp JOIN user_roles ur ON ur.role_id = rp.role_id WHERE ur.user_id = ?',
            [$userId]
        ), 'permission')));
    }

    /**
     * Darf der angemeldete Benutzer dieses Konto ändern, sperren, löschen oder
     * ihm Freigaben geben? Einen Kundenzugang nie – auch nicht die
     * Administration: ein Konto ohne Rechte wäre sonst für jeden mit „Benutzer
     * verwalten“ zu haben (Kennwort setzen, Freigaben am Konto).
     */
    public static function darfVerwalten(int $userId): bool
    {
        return !self::istKundenzugang($userId) && self::darfVergeben(self::benutzerRechte($userId));
    }

    /** Ist dieses Konto ein Kundenzugang (users.kunde_id)? */
    public static function istKundenzugang(int $userId): bool
    {
        $konto = Database::first('SELECT id, kunde_id FROM users WHERE id = ?', [$userId]);
        return $konto !== null && Kundenbereich::istKundenzugang($konto);
    }

    /**
     * Darf der angemeldete Benutzer diese Freigabe vergeben (oder entfernen)?
     *
     * Freigaben reichen so weit wie ein Recht: „alles“ oder „schalten“ gibt
     * nur, wer die Administration hat; eine Freigabe auf Standort, Anlage oder
     * Gerät nur, wer selbst alles sieht („Alle Standorte …“). Wer weniger
     * sieht, könnte sonst sich oder anderen mehr geben, als er selbst hat.
     */
    public static function darfFreigabeVergeben(string $zielArt, bool $steuern): bool
    {
        if (in_array('*', Auth::permissions(), true)) {
            return true;
        }
        return $zielArt !== 'alles' && !$steuern && Zugriff::alles();
    }

    // ------------------------------------------------------------ Rollen

    public static function standardRollenAnlegen(): int
    {
        $neu = 0;
        foreach (Permissions::standardRollen() as $schluessel => $rolle) {
            $vorhanden = Database::first('SELECT id FROM roles WHERE role_key = ?', [$schluessel]);
            if ($vorhanden !== null) {
                continue;
            }
            $id = Database::insert('roles', [
                'role_key' => $schluessel, 'name' => $rolle['name'], 'description' => $rolle['description'],
                'is_system' => $schluessel === 'admin' ? 1 : 0, 'created_at' => gmdate('c'),
            ]);
            foreach ($rolle['permissions'] as $recht) {
                Database::insert('role_permissions', ['role_id' => $id, 'permission' => $recht]);
            }
            $neu++;
        }
        return $neu;
    }

    /** @return list<array<string,mixed>> */
    public static function rollen(): array
    {
        $rollen = Database::select('SELECT * FROM roles ORDER BY is_system DESC, name');
        foreach ($rollen as &$r) {
            $r['permissions'] = array_column(Database::select('SELECT permission FROM role_permissions WHERE role_id = ?', [(int) $r['id']]), 'permission');
            $r['benutzer'] = (int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [(int) $r['id']]);
        }
        return $rollen;
    }

    public static function rolleSpeichern(?int $id, string $name, string $beschreibung, array $rechte): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Die Rolle braucht einen Namen.'];
        }
        $rechte = array_values(array_filter($rechte, static fn ($r) => $r === '*' || Permissions::exists((string) $r)));
        if ($id) {
            $rolle = Database::first('SELECT * FROM roles WHERE id = ?', [$id]);
            if ($rolle === null) {
                return ['ok' => false, 'message' => 'Diese Rolle gibt es nicht.'];
            }
            if ((int) $rolle['is_system'] === 1) {
                // Die Administratorrolle darf alles – wer ihr Rechte nimmt,
                // sperrt sich womöglich selbst aus.
                $rechte = ['*'];
            }
            Database::update('roles', $id, ['name' => mb_substr($name, 0, 190), 'description' => $beschreibung]);
        } else {
            $schluessel = LocationBundle::schluessel($name, 'rolle');
            while (Database::first('SELECT id FROM roles WHERE role_key = ?', [$schluessel]) !== null) {
                $schluessel .= '-2';
            }
            $id = Database::insert('roles', [
                'role_key' => $schluessel, 'name' => mb_substr($name, 0, 190), 'description' => $beschreibung,
                'is_system' => 0, 'created_at' => gmdate('c'),
            ]);
        }
        Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
        foreach (array_unique($rechte) as $recht) {
            Database::insert('role_permissions', ['role_id' => $id, 'permission' => $recht]);
        }
        Audit::log('rolle.speichern', 'role', (string) $id, ['rechte' => $rechte]);
        return ['ok' => true, 'message' => 'Rolle „' . $name . '“ gespeichert.', 'id' => $id];
    }

    public static function rolleLoeschen(int $id): array
    {
        $rolle = Database::first('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($rolle === null || (int) $rolle['is_system'] === 1) {
            return ['ok' => false, 'message' => 'Diese Rolle lässt sich nicht löschen.'];
        }
        Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
        Database::execute('DELETE FROM user_roles WHERE role_id = ?', [$id]);
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'role' AND subject_id = ?", [$id]);
        // Gruppen beim Anmeldedienst, die auf diese Rolle zeigten (SSO).
        Database::execute("DELETE FROM sso_zuordnungen WHERE art = 'rolle' AND ziel_id = ?", [$id]);
        Database::delete('roles', $id);
        Audit::log('rolle.loeschen', 'role', (string) $id);
        return ['ok' => true, 'message' => 'Rolle gelöscht.'];
    }

    // ------------------------------------------------------------ Benutzer

    /** @return list<array<string,mixed>> die Konten des Personals – ohne Kundenzugänge */
    public static function alle(): array
    {
        $benutzer = Database::select('SELECT * FROM users WHERE kunde_id IS NULL ORDER BY username');
        $sso = [];
        foreach (Database::select('SELECT user_id, aussteller, weg, name_dort, zuletzt_am FROM sso_identitaeten ORDER BY id') as $i) {
            $sso[(int) $i['user_id']] ??= $i;
        }
        foreach ($benutzer as &$b) {
            // quelle: hand (hier vergeben) oder sso (aus einer Gruppe beim Anmeldedienst, bis Ende jener Sitzung)
            $b['rollen'] = Database::select(
                'SELECT r.id, r.name, ur.gueltig_ab, ur.gueltig_bis, ur.quelle FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name',
                [(int) $b['id']]
            );
            $b['freigaben'] = (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [(int) $b['id']]);
            $b['zeitraum_hinweis'] = Auth::kontoZeitraum($b);
            // Verknüpfung mit dem Anmeldedienst (Weg, Name dort, zuletzt) – ohne iss/sub.
            $i = $sso[(int) $b['id']] ?? null;
            $b['sso'] = $i === null ? null : ['weg' => (string) $i['weg'], 'name_dort' => (string) $i['name_dort'], 'zuletzt_am' => $i['zuletzt_am'],
                'anderer_aussteller' => !hash_equals(Einstellungen::aussteller(), (string) $i['aussteller'])];
        }
        return $benutzer;
    }

    /** Wie viele Kundenzugänge es gibt (die Liste oben zeigt sie nicht). */
    public static function kundenzugaenge(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM users WHERE kunde_id IS NOT NULL');
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * Ein Konto des Personals anlegen oder ändern – mit seinen Rollen von Hand.
     *
     * Rollen aus Gruppen (user_roles.quelle = 'sso') bleiben stehen; eine
     * hier angehakte Rolle ersetzt die Zeile aus der Gruppe. „Nur über SSO“
     * (nur_sso, im Formular mit dem Feld nur_sso_feld; fehlt beides, bleibt
     * der bisherige Wert): ein neues Konto dann ohne Kennwort, ein
     * eingetragenes Kennwort gilt nicht; ausschalten nur mit neuem Kennwort;
     * das eigene Konto nur in einer SSO-Sitzung einschalten. Den letzten
     * Notzugang (bei „nur SSO“) nimmt keine Änderung.
     *
     * @param array<int|string,array{aktiv?:mixed,ab?:string,bis?:string}> $rollen role_id => Zuweisung
     */
    public static function speichern(?int $id, array $daten, array $rollen): array
    {
        if ($id && self::istKundenzugang($id)) {
            return ['ok' => false, 'message' => self::KUNDENZUGANG];
        }
        $vorher = $id ? self::find($id) : null;
        if ($id && $vorher === null) {
            return ['ok' => false, 'message' => 'Diesen Benutzer gibt es nicht.'];
        }
        $username = trim((string) ($daten['username'] ?? ''));
        if ($username === '' || preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $username) !== 1) {
            return ['ok' => false, 'message' => 'Benutzername: 2–64 Zeichen, Buchstaben, Ziffern und . _ @ -.'];
        }
        $doppelt = Database::first('SELECT id FROM users WHERE username = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$username, $id] : [$username]);
        if ($doppelt !== null) {
            return ['ok' => false, 'message' => 'Den Benutzernamen gibt es schon.'];
        }
        $ab = Zeit::ausEingabe((string) ($daten['gueltig_ab'] ?? ''));
        $bis = Zeit::ausEingabe((string) ($daten['gueltig_bis'] ?? ''), true);
        if ($ab !== null && $bis !== null && Zeit::ts($bis) < Zeit::ts($ab)) {
            return ['ok' => false, 'message' => 'Das Konto endet vor seinem Beginn.'];
        }
        $felder = [
            'username'   => $username,
            'full_name'  => mb_substr(trim((string) ($daten['full_name'] ?? '')), 0, 190),
            'email'      => mb_substr(trim((string) ($daten['email'] ?? '')), 0, 190),
            'is_active'  => !empty($daten['is_active']) ? 1 : 0,
            'gueltig_ab' => $ab,
            'gueltig_bis'=> $bis,
            'notizen'    => (string) ($daten['notizen'] ?? ''),
            'updated_at' => gmdate('c'),
        ];
        // Ohne Qualifikation keine Prüfer-Unterschrift unter Übergabeberichten.
        // Gepflegt wird sie nur hier, in der Benutzerverwaltung.
        if (array_key_exists('pruefer_qualifikation', $daten)) {
            $felder['pruefer_qualifikation'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $daten['pruefer_qualifikation'])), 0, 120);
        }
        $kennwort = (string) ($daten['password'] ?? '');
        $hinweise = [];
        $nurSsoVorher = $vorher !== null && (int) ($vorher['nur_sso'] ?? 0) === 1;
        $nurSso = array_key_exists('nur_sso', $daten) || !empty($daten['nur_sso_feld']) ? !empty($daten['nur_sso']) : $nurSsoVorher;
        if ($nurSso) {
            if ($kennwort !== '') {
                $hinweise[] = 'Das eingetragene Kennwort gilt nicht – das Konto meldet sich nur über ' . Einstellungen::name() . ' an.';
                $kennwort = '';
            }
            if (!$nurSsoVorher && $id !== null && $id === Auth::id() && Auth::anmeldeweg() !== 'sso') {
                return ['ok' => false, 'message' => 'Das eigene Konto stellst du nur in einer Sitzung über ' . Einstellungen::name()
                    . ' auf „nur über SSO“ – sonst sperrst du dich womöglich aus.'];
            }
        } elseif ($nurSsoVorher && $kennwort === '') {
            return ['ok' => false, 'message' => 'Ohne „nur über SSO“ braucht das Konto ein neues Kennwort.'];
        }
        $felder['nur_sso'] = $nurSso ? 1 : 0;
        if ($kennwort !== '') {
            $fehler = Auth::validatePassword($kennwort);
            if ($fehler !== null) {
                return ['ok' => false, 'message' => $fehler];
            }
            $felder['password_hash'] = password_hash($kennwort, PASSWORD_DEFAULT);
            $felder['must_change_password'] = !empty($daten['must_change_password']) ? 1 : 0;
        } elseif (!$id && $nurSso) {
            // Niemand kennt dieses Kennwort – angemeldet wird nur über SSO.
            $felder['password_hash'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $felder['must_change_password'] = 0;
        } elseif (!$id) {
            return ['ok' => false, 'message' => 'Ein neues Konto braucht ein Kennwort.'];
        }
        if ($id && $id === Auth::id() && $felder['is_active'] === 0) {
            return ['ok' => false, 'message' => 'Das eigene Konto lässt sich nicht deaktivieren.'];
        }
        // Nur SSO: den letzten Notzugang nicht deaktivieren, nicht auf nur_sso
        // stellen, ihm nicht den Zeitraum und nicht „*“ nehmen.
        if ($id && Schutz::letzterNotzugang($id)) {
            $danach = array_merge($vorher, $felder);
            $stern = false;
            foreach ($rollen as $rolleId => $zuweisung) {
                if (is_array($zuweisung) && !empty($zuweisung['aktiv']) && in_array('*', self::rollenRechte((int) $rolleId), true)
                    && Zeit::imFenster(time(), Zeit::ausEingabe((string) ($zuweisung['ab'] ?? '')), Zeit::ausEingabe((string) ($zuweisung['bis'] ?? ''), true))) {
                    $stern = true;
                }
            }
            if ((int) $danach['is_active'] !== 1 || (int) $danach['nur_sso'] === 1 || Auth::zugangSperre($danach) !== null || !$stern) {
                return ['ok' => false, 'message' => Schutz::T_LETZTER_NOTZUGANG];
            }
        }

        // Wer wen zum Prüfer gemacht hat, steht im Audit.
        $qualiNeu = array_key_exists('pruefer_qualifikation', $felder)
            && (!$id || (string) Database::scalar('SELECT pruefer_qualifikation FROM users WHERE id = ?', [$id]) !== $felder['pruefer_qualifikation']);
        if ($id) {
            Database::update('users', $id, $felder);
        } else {
            $felder['created_at'] = gmdate('c');
            $id = Database::insert('users', $felder);
        }

        // Rollen mit Zeitraum.
        $eigeneAdminrolle = $id === Auth::id()
            ? Database::first("SELECT ur.id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_system = 1", [$id])
            : null;
        // Die Rollen von Hand neu; Rollen aus Gruppen (SSO) bleiben – außer
        // eine davon ist hier angehakt, dann gilt sie ab jetzt von Hand.
        $angehakt = [];
        foreach ($rollen as $rolleId => $zuweisung) {
            if (is_array($zuweisung) && !empty($zuweisung['aktiv'])) {
                $angehakt[(int) $rolleId] = $zuweisung;
            }
        }
        Database::execute("DELETE FROM user_roles WHERE user_id = ? AND (quelle = 'hand'"
            . ($angehakt === [] ? ')' : ' OR role_id IN (' . implode(',', array_fill(0, count($angehakt), '?')) . '))'),
            array_merge([$id], array_keys($angehakt)));
        foreach ($angehakt as $rolleId => $zuweisung) {
            Database::insert('user_roles', [
                'user_id'     => $id,
                'role_id'     => $rolleId,
                'gueltig_ab'  => Zeit::ausEingabe((string) ($zuweisung['ab'] ?? '')),
                'gueltig_bis' => Zeit::ausEingabe((string) ($zuweisung['bis'] ?? ''), true),
                'quelle'      => 'hand',
            ]);
        }
        if ($eigeneAdminrolle !== null && Database::first(
            'SELECT ur.id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_system = 1',
            [$id]
        ) === null) {
            // Sich selbst die Administration nehmen: lieber nicht.
            $admin = Database::first('SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1');
            Database::insert('user_roles', ['user_id' => $id, 'role_id' => (int) $admin['id'], 'gueltig_ab' => null, 'gueltig_bis' => null]);
        }
        Zugriff::vergessen();
        Auth::rechteVergessen();
        Audit::log('benutzer.speichern', 'user', (string) $id, ['username' => $username]
            + ($qualiNeu ? ['pruefer_qualifikation' => $felder['pruefer_qualifikation']] : [])
            + ($nurSso !== $nurSsoVorher ? ['nur_sso' => $nurSso] : []));
        return ['ok' => true, 'message' => implode(' ', array_merge(['Benutzer „' . $username . '“ gespeichert.'], $hinweise)), 'id' => $id];
    }

    public static function loeschen(int $id): array
    {
        if ($id === Auth::id()) {
            return ['ok' => false, 'message' => 'Das eigene Konto lässt sich nicht löschen.'];
        }
        if (self::istKundenzugang($id)) {
            return ['ok' => false, 'message' => self::KUNDENZUGANG];
        }
        if (Schutz::letzterNotzugang($id)) {
            return ['ok' => false, 'message' => Schutz::T_LETZTER_NOTZUGANG];
        }
        self::kontoEntfernen($id);
        Audit::log('benutzer.loeschen', 'user', (string) $id);
        return ['ok' => true, 'message' => 'Benutzer gelöscht.'];
    }

    /**
     * Ein Konto mit seinen Rollenzuweisungen, Freigaben am Konto, Sperren
     * des Kundenmodus am Gerät und Verknüpfungen mit dem Anmeldedienst (SSO)
     * entfernen – ohne Prüfung und ohne Audit: das tut, wer ruft (loeschen()
     * hier, die Kundenverwaltung für Kundenzugänge).
     */
    public static function kontoEntfernen(int $id): void
    {
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        Database::execute('DELETE FROM sso_identitaeten WHERE user_id = ?', [$id]);
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$id]);
        // Eine Sperre des Kundenmodus am Gerät gälte für dieses Konto nie
        // mehr – sie hielte dort nur andere Konten vom eigenen Kundenmodus ab
        // (Kundenmodus::fremdAmGeraet()).
        Database::execute('DELETE FROM kundenmodus_geraete WHERE user_id = ?', [$id]);
        Database::delete('users', $id);
        Zugriff::vergessen();
    }

    /** @return array{ok:bool,message:string} */
    public static function entsperren(int $id): array
    {
        if (self::istKundenzugang($id)) {
            return ['ok' => false, 'message' => self::KUNDENZUGANG];
        }
        Database::update('users', $id, ['failed_logins' => 0, 'locked_until' => null]);
        Audit::log('benutzer.entsperren', 'user', (string) $id);
        return ['ok' => true, 'message' => 'Konto entsperrt.'];
    }

    public static function anlegen(string $username, string $kennwort, string $rolleSchluessel, bool $mussAendern = true): int
    {
        $id = Database::insert('users', [
            'username' => $username, 'full_name' => '', 'email' => '',
            'password_hash' => password_hash($kennwort, PASSWORD_DEFAULT),
            'is_active' => 1, 'must_change_password' => $mussAendern ? 1 : 0, 'created_at' => gmdate('c'),
        ]);
        $rolle = Database::first('SELECT id FROM roles WHERE role_key = ?', [$rolleSchluessel]);
        if ($rolle !== null) {
            Database::insert('user_roles', ['user_id' => $id, 'role_id' => (int) $rolle['id'], 'gueltig_ab' => null, 'gueltig_bis' => null]);
        }
        return $id;
    }

    // ------------------------------------------------------------ Freigaben

    /** @return list<array<string,mixed>> */
    public static function freigaben(string $subjectType, int $subjectId): array
    {
        $rows = Database::select(
            'SELECT * FROM freigaben WHERE subject_type = ? AND subject_id = ? ORDER BY id',
            [$subjectType, $subjectId]
        );
        $jetzt = time();
        foreach ($rows as &$f) {
            $f['ziel_name'] = self::zielName($f);
            $f['wirksam'] = Zeit::imFenster($jetzt, $f['gueltig_ab'], $f['gueltig_bis']);
            $f['zustand'] = match (true) {
                $f['wirksam']                                                  => 'aktiv',
                Zeit::ts($f['gueltig_ab']) !== null && Zeit::ts($f['gueltig_ab']) > $jetzt => 'kommt',
                default                                                        => 'abgelaufen',
            };
        }
        return $rows;
    }

    public static function zielName(array $f): string
    {
        return match ((string) $f['ziel_art']) {
            'alles'  => 'alle Standorte und Anlagen',
            'site'   => 'Standort ' . ((string) Database::scalar('SELECT name FROM sites WHERE id = ?', [(int) $f['ziel_id']]) ?: '#' . $f['ziel_id']),
            'objekt' => 'Anlage ' . ((string) Database::scalar('SELECT name FROM objekte WHERE id = ?', [(int) $f['ziel_id']]) ?: '#' . $f['ziel_id']),
            'geraet' => 'Gerät ' . ((string) Database::scalar('SELECT name FROM datenquellen WHERE id = ?', [(int) $f['ziel_id']]) ?: '#' . $f['ziel_id'])
                . ((string) ($f['ziel_kanal'] ?? '') !== '' ? ' (Kanal ' . $f['ziel_kanal'] . ')' : ''),
            default  => '?',
        };
    }

    /**
     * Eine Freigabe an einem Benutzer oder einer Rolle – genau diese beiden.
     * Freigaben an Kunden legt nur freigabeFuerKunde() an; ein Kundenzugang
     * bekommt keine Freigabe am Konto (sie zählte für ihn ohnehin nicht).
     */
    public static function freigabeSpeichern(array $daten): array
    {
        $subjectType = (string) ($daten['subject_type'] ?? '');
        if (!in_array($subjectType, ['user', 'role'], true)) {
            return ['ok' => false, 'message' => 'Freigaben gelten für einen Benutzer oder eine Rolle.'];
        }
        $subjectId = (int) ($daten['subject_id'] ?? 0);
        $tabelle = $subjectType === 'role' ? 'roles' : 'users';
        if (Database::first('SELECT id FROM ' . $tabelle . ' WHERE id = ?', [$subjectId]) === null) {
            return ['ok' => false, 'message' => 'Benutzer bzw. Rolle nicht gefunden.'];
        }
        if ($subjectType === 'user' && self::istKundenzugang($subjectId)) {
            return ['ok' => false, 'message' => 'Kundenzugänge sehen nur, was ihrem Kunden freigegeben ist – Freigaben dafür in der Kundenverwaltung.'];
        }
        $zielArt = (string) ($daten['ziel_art'] ?? '');
        if (!isset(self::ZIELE[$zielArt])) {
            return ['ok' => false, 'message' => 'Bitte wählen, wofür die Freigabe gilt.'];
        }
        // Das Formular hat je Zielart eine eigene Auswahl (ziel_id_site …);
        // ziel_id setzt das Skript – ohne Skript gilt die passende Auswahl.
        $zielId = $zielArt === 'alles' ? null : (int) (($daten['ziel_id'] ?? '') !== '' ? $daten['ziel_id'] : ($daten['ziel_id_' . $zielArt] ?? 0));
        $tabelleZiel = ['site' => 'sites', 'objekt' => 'objekte', 'geraet' => 'datenquellen'][$zielArt] ?? null;
        if ($tabelleZiel !== null && ($zielId === null || Database::first('SELECT id FROM ' . $tabelleZiel . ' WHERE id = ?', [$zielId]) === null)) {
            return ['ok' => false, 'message' => 'Das Ziel der Freigabe gibt es nicht.'];
        }
        $ab = Zeit::ausEingabe((string) ($daten['gueltig_ab'] ?? ''));
        $bis = Zeit::ausEingabe((string) ($daten['gueltig_bis'] ?? ''), true);
        $datenAb = Zeit::ausEingabe((string) ($daten['daten_ab'] ?? ''));
        $datenBis = Zeit::ausEingabe((string) ($daten['daten_bis'] ?? ''), true);
        if (!empty($daten['daten_wie_gueltig'])) {
            // Der häufigste Fall: sehen darf man genau die Daten aus der
            // Zeit, in der die Freigabe gilt – die Veranstaltung.
            $datenAb = $ab;
            $datenBis = $bis;
        }
        $fehler = self::zeitraeumePruefen($ab, $bis, $datenAb, $datenBis);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $id = Database::insert('freigaben', [
            'subject_type'    => $subjectType,
            'subject_id'      => $subjectId,
            'ziel_art'        => $zielArt,
            'ziel_id'         => $zielId,
            'ziel_kanal'      => $zielArt === 'geraet' ? mb_substr(trim((string) ($daten['ziel_kanal'] ?? '')), 0, 64) : '',
            'darf_bearbeiten' => !empty($daten['darf_bearbeiten']) ? 1 : 0,
            'darf_steuern'    => !empty($daten['darf_steuern']) ? 1 : 0,
            'gueltig_ab'      => $ab,
            'gueltig_bis'     => $bis,
            'daten_ab'        => $datenAb,
            'daten_bis'       => $datenBis,
            'notiz'           => mb_substr(trim((string) ($daten['notiz'] ?? '')), 0, 255),
            'created_by'      => Auth::name(),
            'created_at'      => gmdate('c'),
        ]);
        Zugriff::vergessen();
        Audit::log('freigabe.anlegen', $subjectType, (string) $subjectId, ['freigabe' => $id, 'ziel' => $zielArt . ':' . $zielId]);
        return ['ok' => true, 'message' => 'Freigabe angelegt.', 'id' => $id];
    }

    /**
     * Eine Freigabe für einen Kunden: nur ein Standort, nur sehen, immer mit
     * Ende und mit Datenzeitraum (beide Enden).
     *
     * Ein Gelände hat mehrere Veranstalter. Ohne Ende sähe der Kunde den
     * Standort auch beim nächsten, ohne Datengrenze dessen Messwerte. Was das
     * Formular sonst schickt (Ziel „alles“, „bearbeiten“, „schalten“, Kanal),
     * gilt nicht. Vergeben darf sie nur, wer selbst alle Standorte sieht
     * (darfFreigabeVergeben). Ob sie sich mit der eines anderen Kunden am
     * selben Standort überschneidet, prüft vorher die Kundenverwaltung;
     * $ueberschneidung hält fest, dass sie es bewusst trotzdem tut (Audit).
     *
     * @param array<string,mixed> $daten site_id (oder ziel_id), gueltig_ab, gueltig_bis,
     *        daten_ab, daten_bis oder daten_wie_gueltig, notiz (Veranstaltung, für den Kunden sichtbar)
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function freigabeFuerKunde(int $kundeId, array $daten, bool $ueberschneidung = false): array
    {
        if (!self::darfFreigabeVergeben('site', false)) {
            return ['ok' => false, 'message' => 'Standorte für Kunden freigeben darf nur, wer selbst alle Standorte sieht („Alle Standorte, Anlagen und Messdaten“).'];
        }
        if (Database::first('SELECT id FROM kunden WHERE id = ?', [$kundeId]) === null) {
            return ['ok' => false, 'message' => 'Diesen Kunden gibt es nicht.'];
        }
        $f = self::kundenFreigabeLesen($daten);
        if (!$f['ok']) {
            return ['ok' => false, 'message' => $f['message']];
        }
        $id = Database::insert('freigaben', [
            'subject_type'    => 'kunde',
            'subject_id'      => $kundeId,
            'ziel_art'        => 'site',
            'ziel_id'         => $f['site_id'],
            'ziel_kanal'      => '',
            'darf_bearbeiten' => 0,
            'darf_steuern'    => 0,
            'gueltig_ab'      => $f['gueltig_ab'],
            'gueltig_bis'     => $f['gueltig_bis'],
            'daten_ab'        => $f['daten_ab'],
            'daten_bis'       => $f['daten_bis'],
            'notiz'           => $f['notiz'],
            'created_by'      => mb_substr(Auth::name(), 0, 64),
            'created_at'      => gmdate('c'),
        ]);
        Zugriff::vergessen();
        Kundenbereich::vergessen();
        Audit::log('kunden.freigabe_anlegen', 'kunde', (string) $kundeId, [
            'kunde_id' => $kundeId, 'freigabe_id' => $id, 'site_id' => $f['site_id'], 'gueltig_bis' => $f['gueltig_bis'], 'ueberschneidung' => $ueberschneidung,
        ]);
        return ['ok' => true, 'message' => 'Freigabe angelegt.', 'id' => $id];
    }

    /**
     * Die Eingaben einer Freigabe für einen Kunden lesen und prüfen, ohne zu
     * speichern: Standort, Ende, Datenzeitraum (beide Enden), Veranstaltung.
     * Die Kundenverwaltung prüft damit vor dem Speichern, ob sich der
     * Datenzeitraum mit dem eines anderen Kunden am selben Standort
     * überschneidet (KundenService::freigabeSpeichern).
     *
     * @param array<string,mixed> $daten wie freigabeFuerKunde()
     * @return array{ok:bool,message:string,site_id?:int,gueltig_ab?:?string,gueltig_bis?:string,daten_ab?:string,daten_bis?:string,notiz?:string}
     */
    public static function kundenFreigabeLesen(array $daten): array
    {
        $siteId = (int) (($daten['site_id'] ?? '') !== '' ? $daten['site_id'] : ($daten['ziel_id'] ?? 0));
        if ($siteId <= 0 || Database::first('SELECT id FROM sites WHERE id = ?', [$siteId]) === null) {
            return ['ok' => false, 'message' => 'Diesen Standort gibt es nicht.'];
        }
        $zeiten = [];
        foreach (['gueltig_ab' => false, 'gueltig_bis' => true, 'daten_ab' => false, 'daten_bis' => true] as $feld => $ende) {
            $eingabe = is_scalar($daten[$feld] ?? null) ? trim((string) $daten[$feld]) : '';
            $zeiten[$feld] = Zeit::ausEingabe($eingabe, $ende);
            if ($eingabe !== '' && $zeiten[$feld] === null) {
                return ['ok' => false, 'message' => 'Kein gültiger Zeitpunkt: „' . mb_substr($eingabe, 0, 40) . '“.'];
            }
        }
        ['gueltig_ab' => $ab, 'gueltig_bis' => $bis, 'daten_ab' => $datenAb, 'daten_bis' => $datenBis] = $zeiten;
        if ($bis === null) {
            return ['ok' => false, 'message' => 'Eine Freigabe für einen Kunden braucht ein Ende – sonst sähe er den Standort auch beim nächsten Veranstalter.'];
        }
        if (!empty($daten['daten_wie_gueltig'])) {
            $datenAb = $ab;
            $datenBis = $bis;
        }
        if ($datenAb === null || $datenBis === null) {
            return ['ok' => false, 'message' => 'Der Datenzeitraum braucht Beginn und Ende'
                . (!empty($daten['daten_wie_gueltig']) ? ' – für „wie die Gültigkeit“ also auch einen Beginn der Freigabe.' : '.')];
        }
        $fehler = self::zeitraeumePruefen($ab, $bis, $datenAb, $datenBis);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $notiz = is_scalar($daten['notiz'] ?? null) ? (string) $daten['notiz'] : '';
        return [
            'ok'          => true,
            'message'     => '',
            'site_id'     => $siteId,
            'gueltig_ab'  => $ab,
            'gueltig_bis' => $bis,
            'daten_ab'    => $datenAb,
            'daten_bis'   => $datenBis,
            'notiz'       => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $notiz)), 0, 255),
        ];
    }

    /** Endet ein Zeitraum vor seinem Beginn? Die Meldung dazu, sonst null. */
    private static function zeitraeumePruefen(?string $ab, ?string $bis, ?string $datenAb, ?string $datenBis): ?string
    {
        foreach ([[$ab, $bis, 'Gültigkeit'], [$datenAb, $datenBis, 'Datenzeitraum']] as [$a, $b, $was]) {
            if ($a !== null && $b !== null && Zeit::ts($b) < Zeit::ts($a)) {
                return $was . ': das Ende liegt vor dem Beginn.';
            }
        }
        return null;
    }

    /** Eine Freigabe an einem Benutzer oder einer Rolle entfernen (Kunden-Freigaben: Kundenverwaltung). */
    public static function freigabeLoeschen(int $id): array
    {
        $f = Database::first("SELECT * FROM freigaben WHERE id = ? AND subject_type IN ('user', 'role')", [$id]);
        if ($f === null) {
            return ['ok' => false, 'message' => 'Diese Freigabe gibt es nicht.'];
        }
        Database::delete('freigaben', $id);
        Zugriff::vergessen();
        Audit::log('freigabe.loeschen', (string) $f['subject_type'], (string) $f['subject_id'], ['freigabe' => $id]);
        return ['ok' => true, 'message' => 'Freigabe entfernt.'];
    }
}
