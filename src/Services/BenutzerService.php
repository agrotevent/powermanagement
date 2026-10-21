<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Permissions;
use App\Auth\Zugriff;
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
 */
final class BenutzerService
{
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

    /** Darf der angemeldete Benutzer dieses Konto ändern, sperren, löschen oder ihm Freigaben geben? */
    public static function darfVerwalten(int $userId): bool
    {
        return self::darfVergeben(self::benutzerRechte($userId));
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
        Database::delete('roles', $id);
        Audit::log('rolle.loeschen', 'role', (string) $id);
        return ['ok' => true, 'message' => 'Rolle gelöscht.'];
    }

    // ------------------------------------------------------------ Benutzer

    /** @return list<array<string,mixed>> */
    public static function alle(): array
    {
        $benutzer = Database::select('SELECT * FROM users ORDER BY username');
        foreach ($benutzer as &$b) {
            $b['rollen'] = Database::select(
                'SELECT r.id, r.name, ur.gueltig_ab, ur.gueltig_bis FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name',
                [(int) $b['id']]
            );
            $b['freigaben'] = (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [(int) $b['id']]);
            $b['zeitraum_hinweis'] = Auth::kontoZeitraum($b);
        }
        return $benutzer;
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * @param array<int|string,array{aktiv?:mixed,ab?:string,bis?:string}> $rollen role_id => Zuweisung
     */
    public static function speichern(?int $id, array $daten, array $rollen): array
    {
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
        if ($kennwort !== '') {
            $fehler = Auth::validatePassword($kennwort);
            if ($fehler !== null) {
                return ['ok' => false, 'message' => $fehler];
            }
            $felder['password_hash'] = password_hash($kennwort, PASSWORD_DEFAULT);
            $felder['must_change_password'] = !empty($daten['must_change_password']) ? 1 : 0;
        } elseif (!$id) {
            return ['ok' => false, 'message' => 'Ein neues Konto braucht ein Kennwort.'];
        }
        if ($id && $id === Auth::id() && $felder['is_active'] === 0) {
            return ['ok' => false, 'message' => 'Das eigene Konto lässt sich nicht deaktivieren.'];
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
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        foreach ($rollen as $rolleId => $zuweisung) {
            if (empty($zuweisung['aktiv'])) {
                continue;
            }
            Database::insert('user_roles', [
                'user_id'     => $id,
                'role_id'     => (int) $rolleId,
                'gueltig_ab'  => Zeit::ausEingabe((string) ($zuweisung['ab'] ?? '')),
                'gueltig_bis' => Zeit::ausEingabe((string) ($zuweisung['bis'] ?? ''), true),
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
        Audit::log('benutzer.speichern', 'user', (string) $id, ['username' => $username]
            + ($qualiNeu ? ['pruefer_qualifikation' => $felder['pruefer_qualifikation']] : []));
        return ['ok' => true, 'message' => 'Benutzer „' . $username . '“ gespeichert.', 'id' => $id];
    }

    public static function loeschen(int $id): array
    {
        if ($id === Auth::id()) {
            return ['ok' => false, 'message' => 'Das eigene Konto lässt sich nicht löschen.'];
        }
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$id]);
        Database::delete('users', $id);
        Audit::log('benutzer.loeschen', 'user', (string) $id);
        return ['ok' => true, 'message' => 'Benutzer gelöscht.'];
    }

    public static function entsperren(int $id): void
    {
        Database::update('users', $id, ['failed_logins' => 0, 'locked_until' => null]);
        Audit::log('benutzer.entsperren', 'user', (string) $id);
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

    public static function freigabeSpeichern(array $daten): array
    {
        $subjectType = ($daten['subject_type'] ?? '') === 'role' ? 'role' : 'user';
        $subjectId = (int) ($daten['subject_id'] ?? 0);
        $tabelle = $subjectType === 'role' ? 'roles' : 'users';
        if (Database::first('SELECT id FROM ' . $tabelle . ' WHERE id = ?', [$subjectId]) === null) {
            return ['ok' => false, 'message' => 'Benutzer bzw. Rolle nicht gefunden.'];
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
        foreach ([[$ab, $bis, 'Gültigkeit'], [$datenAb, $datenBis, 'Datenzeitraum']] as [$a, $b, $was]) {
            if ($a !== null && $b !== null && Zeit::ts($b) < Zeit::ts($a)) {
                return ['ok' => false, 'message' => $was . ': das Ende liegt vor dem Beginn.'];
            }
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

    public static function freigabeLoeschen(int $id): array
    {
        $f = Database::first('SELECT * FROM freigaben WHERE id = ?', [$id]);
        if ($f === null) {
            return ['ok' => false, 'message' => 'Diese Freigabe gibt es nicht.'];
        }
        Database::delete('freigaben', $id);
        Zugriff::vergessen();
        Audit::log('freigabe.loeschen', (string) $f['subject_type'], (string) $f['subject_id'], ['freigabe' => $id]);
        return ['ok' => true, 'message' => 'Freigabe entfernt.'];
    }
}
