<?php
declare(strict_types=1);

namespace App\Auth\Sso;

use App\Auth\Auth;
use App\Services\BenutzerService;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Die Schranken der Anmeldung über SSO – für die Anmeldung selbst, jede
 * Anfrage und die Kennwort-Anmeldung.
 *
 * Wer den Anmeldedienst beherrscht, beherrscht die verknüpften Konten.
 * Darum gilt:
 *   - Verwaltungskonten (S8: eines der Rechte in VERWALTEND) melden sich per
 *     SSO nur mit `sso.admins` an, auf Wunsch nur mit zweitem Faktor
 *     (`sso.mfa_verwaltung`, amr ∩ {mfa, sc}).
 *   - Kundenzugänge nur mit `sso.kunden` und nur, wenn SSO sie angelegt hat
 *     (nur_sso) – lokale Kundenzugänge nie.
 *   - `locked_until` ist die Bremse der Kennwort-Anmeldung und gilt hier
 *     nicht: Wer Kennwörter auf einen Namen sprüht, sperrte sonst dessen
 *     SSO-Anmeldung.
 * Die Rechte kommen immer aus Auth::rechteVon() bzw. den Rollen – nie aus
 * Auth::can(), das hinge an der Sitzung, die hier erst geprüft wird.
 *
 * „Nur SSO“: ein Konto mit users.nur_sso meldet sich nur über SSO an,
 * mit `sso.nur_sso` dazu jedes verknüpfte Konto. Der Notzugang ist
 * abgeleitet (aktiv, „*“, nicht verknüpft, nicht nur_sso) und bleibt immer
 * bei Benutzername und Kennwort.
 */
final class Schutz
{
    public const VERWALTEND = ['*', 'system.users.manage', 'settings.manage', 'sso.manage', 'api.manage'];
    public const MFA = ['mfa', 'sc'];

    public const T_DEAKTIVIERT = 'Dieses Konto ist deaktiviert.';
    public const T_KUNDE_LOKAL = 'Kundenzugänge melden sich mit Benutzername und Kennwort an.';
    public const T_KUNDEN_AUS = 'Kundenzugänge über den Anmeldedienst sind hier ausgeschaltet – bitte bei der Verwaltung melden.';
    public const T_KUNDENGRUPPE = 'Die Gruppe des Kunden fehlt beim Anmeldedienst – bitte bei der Verwaltung melden.';
    public const T_KUNDE_ABWEICHEND = 'Beim Anmeldedienst gehört dieses Konto zu einem anderen Kunden – bitte bei der Verwaltung melden.';
    public const T_GEMISCHT = 'Kunden- und Personalgruppen zugleich – die Anmeldung über SSO geht nur mit einer der beiden.';
    public const T_PFLICHTGRUPPE = 'Für PowerManagement fehlt die Gruppe beim Anmeldedienst.';
    public const T_VERWALTUNG = 'Verwaltungskonten melden sich mit Kennwort an – SSO dafür ist nicht freigegeben.';
    public const T_MFA = 'Für Verwaltungskonten verlangt PowerManagement eine Anmeldung mit zweitem Faktor.';
    public const T_LETZTER_NOTZUGANG = 'Das ist der letzte Notzugang – erst einen weiteren anlegen oder ‚nur SSO‘ ausschalten.';

    /** Hat jemand mit diesen Rechten Verwaltungsrechte (S8)? */
    public static function verwaltend(array $rechte): bool
    {
        foreach ($rechte as $recht) {
            if (in_array($recht, self::VERWALTEND, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Darf dieses Konto jetzt per SSO angemeldet sein bzw. bestätigen? null =
     * ja, sonst der Prüfcode: verwaltung | kunden_aus | kunde_lokal.
     */
    public static function darfPerSso(array $user): ?string
    {
        if (Kundenbereich::istKundenzugang($user)) {
            // Wie in der Prüfkette: ein lokaler Kundenzugang nie, einer aus SSO nur mit sso.kunden.
            if ((int) ($user['nur_sso'] ?? 0) !== 1) {
                return 'kunde_lokal';
            }
            return Kundenbereich::verfuegbar() && Einstellungen::schalter(Einstellungen::KUNDEN) ? null : 'kunden_aus';
        }
        if (self::verwaltend(Auth::rechteVon((int) $user['id'])) && !Einstellungen::schalter(Einstellungen::ADMINS)) {
            return 'verwaltung';
        }
        return null;
    }

    /**
     * Die Prüfkette am Konto vor dem Schreiben (§6.3) – für jedes Konto, auch
     * ein eben angelegtes. Die Rechte für die Schranke der Verwaltung kommen
     * aus den Rollen von Hand (auch künftigen oder abgelaufenen – wer morgen
     * Administrator ist, ist es für diese Schranke schon heute) und den
     * Zielrollen aus den Gruppen.
     *
     * @param array<int,list<string>> $zielRollen
     * @throws SsoFehler
     */
    public static function pruefkette(array $user, array $claims, array $gruppen, ?array $kunde, array $zielRollen): void
    {
        $name = Konten::nameDort($claims);
        $fehler = static fn (string $code, string $text, array $vermerke = []): SsoFehler
            => new SsoFehler($code, $text, false, $name, $vermerke);
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            throw $fehler('deaktiviert', self::T_DEAKTIVIERT);
        }
        $sperre = Auth::zugangSperre($user);
        if ($sperre !== null) {
            throw $fehler('zeitraum', $sperre);
        }
        if (Kundenbereich::istKundenzugang($user)) {
            if ((int) ($user['nur_sso'] ?? 0) !== 1) {
                throw $fehler('kunde_lokal', self::T_KUNDE_LOKAL);
            }
            if (!Kundenbereich::verfuegbar() || !Einstellungen::schalter(Einstellungen::KUNDEN)) {
                // Ein Zugang aus SSO hat kein Kennwort – „mit Kennwort anmelden“ hülfe ihm nicht.
                throw $fehler('kunden_aus', self::T_KUNDEN_AUS);
            }
            if ($kunde === null) {
                throw $fehler('kundengruppe', self::T_KUNDENGRUPPE);
            }
            if ((int) $kunde['id'] !== (int) $user['kunde_id']) {
                // kunde_id ändert sich nie von selbst – das Personal löscht den
                // Zugang, die nächste Anmeldung legt ihn beim neuen Kunden an.
                throw $fehler('kunde_abweichend', self::T_KUNDE_ABWEICHEND, [['sso.kunde_abweichend', 'user', (string) $user['id'],
                    ['user_id' => (int) $user['id'], 'kunde_id' => (int) $user['kunde_id'], 'gruppen_kunde_id' => (int) $kunde['id']]]]);
            }
            if ($zielRollen !== []) {
                throw $fehler('gemischt', self::T_GEMISCHT);
            }
            return;
        }
        if ($kunde !== null) {
            // Personal in einer Kundengruppe: entweder Kunde oder Personal.
            throw $fehler('gemischt', self::T_GEMISCHT);
        }
        $pflicht = Einstellungen::pflichtgruppen();
        if ($pflicht !== [] && array_values(array_filter($pflicht, static fn (string $g): bool => in_array($g, $gruppen, true))) === []) {
            throw $fehler('pflichtgruppe', self::T_PFLICHTGRUPPE);
        }
        $rechte = self::handRechte((int) $user['id']);
        foreach ($zielRollen as $r) {
            $rechte = array_merge($rechte, $r);
        }
        if (self::verwaltend($rechte) || self::hatSystemrolle((int) $user['id'])) {
            if (!Einstellungen::schalter(Einstellungen::ADMINS)) {
                throw $fehler('verwaltung', self::T_VERWALTUNG);
            }
            if (Einstellungen::schalter(Einstellungen::MFA) && !self::mitZweitemFaktor($claims)) {
                throw $fehler('mfa', self::T_MFA);
            }
        }
    }

    /** Hat die Anmeldung beim Anmeldedienst einen zweiten Faktor (amr ∩ MFA)? */
    public static function mitZweitemFaktor(array $claims): bool
    {
        $amr = $claims['amr'] ?? null;
        return is_array($amr) && array_is_list($amr) && array_intersect(array_filter($amr, 'is_string'), self::MFA) !== [];
    }

    /**
     * Gilt die SSO-Sitzung noch (§6.10)? SSO an, dieselbe Konfiguration
     * (Marke), nicht älter als sitzungDauer(), Identität noch da und beim
     * jetzigen Aussteller, nicht „Sitzungen beendet“ und die Schranken
     * erfüllt – auch die MFA-Pflicht, wenn sie erst nach der Anmeldung
     * eingeschaltet wurde.
     *
     * @param array<string,mixed> $sso die Angaben der Sitzung (ident, marke, auth_time, name, mfa)
     */
    public static function sitzungGilt(array $user, array $sso, int $angemeldet): bool
    {
        if (!Einstellungen::aktiv() || !hash_equals(Einstellungen::marke(), (string) ($sso['marke'] ?? ''))) {
            return false;
        }
        if (Einstellungen::jetzt() - $angemeldet > Einstellungen::sitzungDauer()) {
            return false;
        }
        $i = Database::first('SELECT * FROM sso_identitaeten WHERE id = ? AND user_id = ?', [(int) ($sso['ident'] ?? 0), (int) $user['id']]);
        if ($i === null || !hash_equals(Einstellungen::aussteller(), (string) $i['aussteller'])) {
            return false;
        }
        if ($i['abgemeldet_am'] !== null && (int) Zeit::ts((string) $i['abgemeldet_am']) >= $angemeldet) {
            return false;
        }
        if (self::darfPerSso($user) !== null) {
            return false;
        }
        if (Einstellungen::schalter(Einstellungen::MFA) && empty($sso['mfa']) && !Kundenbereich::istKundenzugang($user)
            && self::verwaltend(Auth::rechteVon((int) $user['id']))) {
            return false;
        }
        return true;
    }

    /** Meldet sich dieses Konto nur über SSO an? users.nur_sso ∨ (sso.nur_sso ∧ aktiv ∧ verknüpft). */
    public static function nurSso(array $user): bool
    {
        if ((int) ($user['nur_sso'] ?? 0) === 1) {
            return true;
        }
        return Einstellungen::schalter(Einstellungen::NUR_SSO) && Einstellungen::aktiv() && Konten::identitaet((int) $user['id']) !== null;
    }

    /**
     * Darf sich dieses Konto mit Kennwort anmelden? null = ja, sonst die
     * Meldung. Hängt nur an Einstellungen, nie am Netz: Fällt der
     * Anmeldedienst aus, öffnet das nicht still die Kennwort-Anmeldung
     * (Abhilfe: Notzugang oder `bin/power sso:aus`).
     */
    public static function kennwortVerboten(array $user): ?string
    {
        if ((int) ($user['nur_sso'] ?? 0) === 1) {
            return 'Dieses Konto meldet sich nur über ' . Einstellungen::name() . ' an.';
        }
        if (Einstellungen::schalter(Einstellungen::NUR_SSO) && Einstellungen::aktiv() && Konten::identitaet((int) $user['id']) !== null) {
            return 'Bitte über ' . Einstellungen::name() . ' anmelden – mit Kennwort geht es für verknüpfte Konten nicht mehr.';
        }
        return null;
    }

    /**
     * Ist dieses Konto ein wirksamer Notzugang? Aktiv, im Zeitraum, nicht
     * nur_sso, kein Kundenzugang, mit keiner Identität verknüpft (gleich
     * welcher Aussteller) und mit „*“ aus einer jetzt gültigen Rolle.
     */
    public static function notzugangWirksam(array $user): bool
    {
        if ((int) ($user['is_active'] ?? 0) !== 1 || (int) ($user['nur_sso'] ?? 0) === 1 || Kundenbereich::istKundenzugang($user)
            || Auth::zugangSperre($user) !== null) {
            return false;
        }
        if (Database::scalar('SELECT id FROM sso_identitaeten WHERE user_id = ?', [(int) $user['id']]) !== false) {
            return false;
        }
        return in_array('*', Auth::rechteVon((int) $user['id']), true);
    }

    /** @return list<array<string,mixed>> alle wirksamen Notzugänge */
    public static function notzugaenge(): array
    {
        $konten = Database::select(
            'SELECT * FROM users WHERE kunde_id IS NULL AND is_active = 1 AND nur_sso = 0
             AND id NOT IN (SELECT user_id FROM sso_identitaeten) ORDER BY username'
        );
        return array_values(array_filter($konten, [self::class, 'notzugangWirksam']));
    }

    /**
     * Nähme eine Änderung an diesem Konto (verknüpfen, deaktivieren, löschen,
     * „*“ nehmen, nur_sso setzen) den letzten Notzugang, während „nur SSO“
     * eingeschaltet ist?
     */
    public static function letzterNotzugang(int $userId): bool
    {
        if (!Einstellungen::schalter(Einstellungen::NUR_SSO)) {
            return false;
        }
        $user = BenutzerService::find($userId);
        if ($user === null || !self::notzugangWirksam($user)) {
            return false;
        }
        foreach (self::notzugaenge() as $n) {
            if ((int) $n['id'] !== $userId) {
                return false;
            }
        }
        return true;
    }

    /**
     * Die Vorbedingungen für „nur SSO“ (§6.12) als Checkliste – ohne das
     * Eintippen: alle Rechte, SSO an und zuletzt erfolgreich geprüft, diese
     * Sitzung über SSO (es geht also für dieses Konto), „Verwaltung per SSO“
     * an ($admins: der Wert, der gespeichert werden soll) und mindestens ein
     * Notzugang. `fehlt` ist die Meldung, wenn der Punkt nicht erfüllt ist.
     *
     * @return list<array{ok:bool,text:string,fehlt:string}>
     */
    public static function nurSsoVorbedingungen(?bool $admins = null): array
    {
        $admins ??= Einstellungen::schalter(Einstellungen::ADMINS);
        $name = Einstellungen::name();
        $notzugaenge = count(self::notzugaenge());
        return [
            ['ok' => Auth::can('*'), 'text' => 'Du hast alle Rechte.',
                'fehlt' => 'nur wer alle Rechte hat, schaltet „nur SSO“ ein.'],
            ['ok' => Einstellungen::aktiv() && Einstellungen::letztePruefungOk(), 'text' => 'Die Anmeldung über ' . $name . ' ist an, die letzte Prüfung der Verbindung war erfolgreich.',
                'fehlt' => 'erst die Anmeldung über ' . $name . ' einschalten und „Verbindung prüfen“ ohne Befund durchlaufen.'],
            ['ok' => Auth::anmeldeweg() === 'sso', 'text' => 'Diese Sitzung ist über ' . $name . ' angemeldet – für dein Konto geht es also.',
                'fehlt' => 'erst über ' . $name . ' anmelden – so ist sicher, dass es für dein Konto geht.'],
            ['ok' => $admins, 'text' => '„Verwaltung per SSO“ ist an.',
                'fehlt' => 'erst „Verwaltung per SSO“ einschalten.'],
            ['ok' => $notzugaenge > 0, 'text' => $notzugaenge > 0 ? 'Notzugang vorhanden (' . $notzugaenge . ').' : 'Notzugang vorhanden.',
                'fehlt' => 'es gibt keinen Notzugang – erst ein aktives Konto mit allen Rechten ohne Verknüpfung anlegen (bin/power user:add notfall admin) und das Kennwort sicher verwahren.'],
        ];
    }

    /**
     * Was „nur SSO“ ändert (Warnliste der Schutz-Karte): Konten mit
     * Verwaltungsrechten ohne Verknüpfung, die kein Notzugang sind – sie
     * melden sich weiter mit Kennwort an –, und die Zahl der verknüpften
     * Konten (jetziger Aussteller, aktiv, nicht ohnehin nur_sso), deren
     * Kennwort-Sitzungen mit „nur SSO“ bei der nächsten Anfrage enden.
     *
     * @return array{verwaltend:list<string>, verknuepft:int}
     */
    public static function nurSsoWarnungen(): array
    {
        $verwaltend = [];
        foreach (Database::select(
            'SELECT * FROM users WHERE kunde_id IS NULL AND is_active = 1 AND nur_sso = 0
             AND id NOT IN (SELECT user_id FROM sso_identitaeten) ORDER BY username'
        ) as $u) {
            if (self::verwaltend(Auth::rechteVon((int) $u['id'])) && !self::notzugangWirksam($u)) {
                $verwaltend[] = (string) $u['username'];
            }
        }
        $aussteller = Einstellungen::aussteller();
        $verknuepft = 0;
        foreach (Database::select(
            'SELECT i.aussteller FROM sso_identitaeten i JOIN users u ON u.id = i.user_id WHERE u.is_active = 1 AND u.nur_sso = 0'
        ) as $i) {
            if ($aussteller !== '' && hash_equals($aussteller, (string) $i['aussteller'])) {
                $verknuepft++;
            }
        }
        return ['verwaltend' => $verwaltend, 'verknuepft' => $verknuepft];
    }

    /**
     * Rollen, die nur mit „Verwaltung per SSO“ aus Gruppen kommen dürfen:
     * Systemrolle oder eines der Rechte in VERWALTEND.
     *
     * @return list<int>
     */
    public static function verwaltendeRollen(): array
    {
        return array_map('intval', array_column(Database::select(
            'SELECT id FROM roles WHERE is_system = 1 OR id IN (SELECT role_id FROM role_permissions WHERE permission IN ('
            . implode(',', array_fill(0, count(self::VERWALTEND), '?')) . ')) ORDER BY id',
            self::VERWALTEND
        ), 'id'));
    }

    // ------------------------------------------------------------ intern

    /** @return list<string> Rechte aller Rollen von Hand, gleich welcher Zeitraum */
    private static function handRechte(int $userId): array
    {
        return array_values(array_unique(array_column(Database::select(
            "SELECT rp.permission FROM role_permissions rp JOIN user_roles ur ON ur.role_id = rp.role_id WHERE ur.user_id = ? AND ur.quelle = 'hand'",
            [$userId]
        ), 'permission')));
    }

    private static function hatSystemrolle(int $userId): bool
    {
        return Database::scalar(
            "SELECT ur.id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND ur.quelle = 'hand' AND r.is_system = 1",
            [$userId]
        ) !== false;
    }
}
