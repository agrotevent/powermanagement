<?php
declare(strict_types=1);

namespace App\Auth\Sso;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\BenutzerService;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Konten und Identitäten der Anmeldung über SSO: Verknüpfung, Auflösung bei
 * der Anmeldung, Rollen aus Gruppen, Zuordnungen und Anmeldeanfragen.
 *
 * Verknüpft wird nur über Aussteller (iss) und Subjekt (sub) – Benutzername
 * und E-Mail-Adresse kann man beim Anmeldedienst ändern, sie verknüpfen nie
 * von selbst. Gelesen wird über `schluessel` = sha256(iss "\n" sub); danach
 * werden Aussteller und Subjekt in PHP exakt verglichen (unter MySQL
 * verglichen `utf8mb4_*_ci`-Spalten sonst ohne Groß/Klein – „AbC“ träfe „abc“).
 *
 * Erstverknüpfung auf drei Wegen: selbst im Profil (weg profil), Freischaltung
 * einer Anmeldeanfrage durch die Verwaltung (freischaltung) oder automatische
 * Anlage (anlage, nur mit `sso.anlegen` und einer zugeordneten Gruppe bzw.
 * einer Kundengruppe mit `sso.kunden`). Die Kommandozeile heißt cli.
 *
 * Gruppen → Rollen als user_roles mit quelle = 'sso' und gueltig_bis = Ende
 * der SSO-Sitzung; jede SSO-Anmeldung setzt sie neu, eine Kennwort-Anmeldung
 * entfernt sie. Freigaben kommen nie aus Gruppen – wer einer ganzen Gruppe
 * etwas zeigen will, setzt die Freigabe an der Rolle. Gruppennamen werden nur
 * als Liste verglichen (in_array strikt), nie als Array-Schlüssel: „123“
 * würde dort zur Zahl.
 */
final class Konten
{
    public const MAX_ZUORDNUNGEN = 200, MAX_ANFRAGEN = 200, ANFRAGE_TAGE = 30, MAX_GRUPPEN_GESPEICHERT = 50;
    /** So viele Gruppen aus einem Token werden höchstens angesehen. */
    public const MAX_GRUPPEN = 1000;
    public const WEGE = ['profil', 'freischaltung', 'anlage', 'cli'];
    public const ARTEN = ['rolle', 'kunde'];

    public const T_KEIN_KONTO = 'Für diese Anmeldung gibt es hier noch kein Konto. Die Verwaltung kann sie unter ‚Anmeldung (SSO)‘ freischalten – bitte Bescheid geben.';
    public const T_NAME = 'Für diese Anmeldung gibt es hier noch kein Konto, und der Benutzername beim Anmeldedienst ist hier schon vergeben oder ungültig. Die Verwaltung kann sie unter ‚Anmeldung (SSO)‘ freischalten – bitte Bescheid geben.';
    public const T_VERKNUEPFT = 'Diese Anmeldung ist schon mit einem anderen Konto verknüpft.';
    public const T_KUNDE_NIE = 'Kundenzugänge werden nie mit einer SSO-Anmeldung verknüpft – sie melden sich mit Benutzername und Kennwort an.';
    public const T_ANFRAGE_KUNDE = 'Diese Anmeldung ist beim Anmeldedienst in einer Kundengruppe – sie wird bei der nächsten Anmeldung selbst ein Kundenzugang, nie ein Konto des Personals. Ist ihr Benutzername hier schon vergeben, ihn beim Anmeldedienst ändern oder hier freimachen.';

    // ------------------------------------------------------------ Identitäten

    public static function schluessel(string $iss, string $sub): string
    {
        return hash('sha256', $iss . "\n" . $sub);
    }

    /** Die Identität zu (iss, sub) – über schluessel gelesen, dann exakt verglichen. */
    public static function identitaetFinden(string $iss, string $sub): ?array
    {
        $i = Database::first('SELECT * FROM sso_identitaeten WHERE schluessel = ?', [self::schluessel($iss, $sub)]);
        if ($i === null || !hash_equals((string) $i['aussteller'], $iss) || !hash_equals((string) $i['subjekt'], $sub)) {
            return null;
        }
        return $i;
    }

    /** Die Identität des Kontos beim jetzigen Aussteller – oder null. */
    public static function identitaet(int $userId): ?array
    {
        $iss = Einstellungen::aussteller();
        if ($iss === '') {
            return null;
        }
        foreach (Database::select('SELECT * FROM sso_identitaeten WHERE user_id = ?', [$userId]) as $i) {
            if (hash_equals((string) $i['aussteller'], $iss)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Die Gruppen aus dem eingestellten Claim, bereinigt: nur Strings, ohne
     * Leerraum außen, 1–150 Zeichen, gültiges UTF-8 ohne Steuerzeichen,
     * höchstens MAX_GRUPPEN angesehen, ohne Doppelte – als Liste.
     *
     * @return list<string>
     */
    public static function gruppen(array $claims): array
    {
        $roh = $claims[Einstellungen::gruppenClaim()] ?? null;
        if (is_string($roh)) {
            $roh = [$roh];
        }
        if (!is_array($roh)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($roh), 0, self::MAX_GRUPPEN) as $g) {
            if (!is_string($g)) {
                continue;
            }
            $g = trim($g);
            if (self::gruppeGueltig($g) && !in_array($g, $out, true)) {
                $out[] = $g;
            }
        }
        return $out;
    }

    /** preferred_username aus geprüften Claims – nur zur Anzeige und als Vorschlag (≤ 190, ohne Steuerzeichen). */
    public static function nameDort(array $claims): string
    {
        return self::text($claims['preferred_username'] ?? null, 190);
    }

    // ------------------------------------------------------------ Auflösen bei der Anmeldung

    /**
     * Gruppe → Rolle: die Rollen der Gruppen mit ihren Rechten. Rollen mit
     * is_system oder verwaltenden Rechten nur mit `sso.admins` – sonst
     * übergangen (in $uebergangen).
     *
     * @param list<string> $gruppen
     * @param list<int>|null $uebergangen
     * @return array<int, list<string>> role_id → Rechte
     */
    public static function zielRollen(array $gruppen, ?array &$uebergangen = null): array
    {
        $uebergangen = [];
        if ($gruppen === []) {
            return [];
        }
        $admins = Einstellungen::schalter(Einstellungen::ADMINS);
        $ziel = [];
        foreach (Database::select(
            "SELECT z.gruppe, z.ziel_id, r.is_system FROM sso_zuordnungen z JOIN roles r ON r.id = z.ziel_id WHERE z.art = 'rolle' ORDER BY z.id"
        ) as $z) {
            $rolle = (int) $z['ziel_id'];
            if (!in_array((string) $z['gruppe'], $gruppen, true) || isset($ziel[$rolle]) || in_array($rolle, $uebergangen, true)) {
                continue;
            }
            $rechte = BenutzerService::rollenRechte($rolle);
            if (!$admins && ((int) $z['is_system'] === 1 || Schutz::verwaltend($rechte))) {
                $uebergangen[] = $rolle;
                continue;
            }
            $ziel[$rolle] = $rechte;
        }
        return $ziel;
    }

    /**
     * Der Kunde aus den Kundengruppen – null ohne `sso.kunden` bzw. ohne
     * Kundenbereich oder ohne Treffer. Zwei Kunden: kunden_mehrere; der
     * Kunde nicht (mehr) aktiv: kunde_inaktiv.
     *
     * @param list<string> $gruppen
     * @throws SsoFehler
     */
    public static function kundeFuer(array $gruppen, string $nameDort = ''): ?array
    {
        if (!Kundenbereich::verfuegbar() || !Einstellungen::schalter(Einstellungen::KUNDEN) || $gruppen === []) {
            return null;
        }
        $ids = [];
        foreach (Database::select("SELECT gruppe, ziel_id FROM sso_zuordnungen WHERE art = 'kunde'") as $z) {
            if (in_array((string) $z['gruppe'], $gruppen, true) && !in_array((int) $z['ziel_id'], $ids, true)) {
                $ids[] = (int) $z['ziel_id'];
            }
        }
        if ($ids === []) {
            return null;
        }
        if (count($ids) > 1) {
            throw new SsoFehler('kunden_mehrere', 'Beim Anmeldedienst ist dieses Konto in den Gruppen mehrerer Kunden – die Anmeldung geht nur mit genau einer.', false, $nameDort);
        }
        $kunde = Kundenbereich::kunde($ids[0]);
        if ($kunde === null) {
            throw new SsoFehler('kunde_inaktiv', 'Der Kunde zu dieser Anmeldung ist nicht (mehr) aktiv.', false, $nameDort);
        }
        return $kunde;
    }

    /**
     * Zweck „anmelden“: das Konto zur Identität finden oder (automatisch)
     * anlegen, die Prüfkette, Rollen aus Gruppen, Profil auffrischen – alles
     * in einer Transaktion. Zwei gleichzeitige Rückrufe derselben neuen
     * Identität: Der zweite scheitert an UNIQUE (schluessel) und findet sie
     * beim einmaligen Wiederholen.
     *
     * @param list<string> $gruppen
     * @return array{user:array, identitaet:array, angelegt:bool, rollen:array{hinzu:list<int>,weg:list<int>}}
     * @throws SsoFehler
     */
    public static function aufloesen(array $claims, array $gruppen): array
    {
        try {
            return Database::transaktion(static fn (): array => self::aufloesenJetzt($claims, $gruppen));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            return Database::transaktion(static fn (): array => self::aufloesenJetzt($claims, $gruppen));
        }
    }

    /**
     * Rollen aus Gruppen setzen (nur Personal): neue SSO-Zeilen bis Ende der
     * Sitzung, bestehende verlängert, nicht mehr zugeordnete gelöscht. Eine
     * Rolle, die das Konto schon von Hand hat, bekommt keine zweite Zeile.
     *
     * @param array<int, list<string>> $zielRollen
     * @return array{hinzu:list<int>, weg:list<int>}
     */
    public static function rollenAbgleichen(int $userId, array $zielRollen): array
    {
        $hand = [];
        $alt = [];
        foreach (Database::select('SELECT role_id, quelle FROM user_roles WHERE user_id = ?', [$userId]) as $z) {
            if ((string) $z['quelle'] === 'sso') {
                $alt[] = (int) $z['role_id'];
            } else {
                $hand[] = (int) $z['role_id'];
            }
        }
        $neu = array_values(array_filter(array_map('intval', array_keys($zielRollen)), static fn (int $r): bool => !in_array($r, $hand, true)));
        $bis = Zeit::iso(Einstellungen::jetzt() + Einstellungen::sitzungDauer());
        $weg = array_values(array_filter($alt, static fn (int $r): bool => !in_array($r, $neu, true)));
        foreach ($weg as $r) {
            Database::execute("DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND quelle = 'sso'", [$userId, $r]);
        }
        $hinzu = [];
        foreach ($neu as $r) {
            if (in_array($r, $alt, true)) {
                Database::execute("UPDATE user_roles SET gueltig_ab = NULL, gueltig_bis = ? WHERE user_id = ? AND role_id = ? AND quelle = 'sso'",
                    [$bis, $userId, $r]);
            } else {
                Database::insert('user_roles', ['user_id' => $userId, 'role_id' => $r, 'gueltig_ab' => null, 'gueltig_bis' => $bis, 'quelle' => 'sso']);
                $hinzu[] = $r;
            }
        }
        if ($hinzu !== [] || $weg !== []) {
            Audit::log('sso.rollen', 'user', (string) $userId, ['user_id' => $userId, 'hinzu' => $hinzu, 'weg' => $weg]);
        }
        Zugriff::vergessen();
        Auth::rechteVergessen();
        return ['hinzu' => $hinzu, 'weg' => $weg];
    }

    /**
     * Ein Konto mit einer Identität verknüpfen – ohne Audit (das tut, wer
     * ruft). Abgelehnt (SsoFehler): die Identität ist schon verknüpft
     * (verknuepft), das Konto hat schon eine Identität dieses Ausstellers
     * (schon_verknuepft), ein Kundenzugang außer bei der Anlage (kunde_lokal),
     * der letzte Notzugang bei „nur SSO“ (notzugang).
     *
     * @param list<string> $gruppen
     * @throws SsoFehler
     */
    public static function verknuepfen(int $userId, string $iss, string $sub, string $weg, string $nameDort, array $gruppen): int
    {
        $user = BenutzerService::find($userId);
        if ($user === null) {
            throw new SsoFehler('konto', 'Dieses Konto gibt es nicht.');
        }
        if (!in_array($weg, self::WEGE, true)) {
            throw new \LogicException('SSO: unbekannter Weg der Verknüpfung');
        }
        if (Einstellungen::ausstellerFehler($iss) !== null || preg_match('/^[\x21-\x7e]{1,255}$/', $sub) !== 1) {
            throw new SsoFehler('sub', 'Aussteller oder Subjekt der Anmeldung taugen nicht.');
        }
        if (Database::scalar('SELECT id FROM sso_identitaeten WHERE schluessel = ?', [self::schluessel($iss, $sub)]) !== false) {
            throw new SsoFehler('verknuepft', self::T_VERKNUEPFT);
        }
        foreach (Database::select('SELECT aussteller FROM sso_identitaeten WHERE user_id = ?', [$userId]) as $i) {
            if (strcasecmp((string) $i['aussteller'], $iss) === 0) {
                throw new SsoFehler('schon_verknuepft', 'Dieses Konto ist schon mit einer Anmeldung bei ' . Einstellungen::name() . ' verknüpft.');
            }
        }
        if ($weg !== 'anlage' && Kundenbereich::istKundenzugang($user)) {
            throw new SsoFehler('kunde_lokal', self::T_KUNDE_NIE);
        }
        if (Schutz::letzterNotzugang($userId)) {
            throw new SsoFehler('notzugang', Schutz::T_LETZTER_NOTZUGANG);
        }
        $von = match ($weg) {
            'anlage' => 'sso',
            'cli'    => 'cli',
            default  => Auth::name(),
        };
        return Database::insert('sso_identitaeten', [
            'user_id'      => $userId,
            'schluessel'   => self::schluessel($iss, $sub),
            'aussteller'   => $iss,
            'subjekt'      => $sub,
            'weg'          => $weg,
            'name_dort'    => mb_substr($nameDort, 0, 190),
            'gruppen'      => self::gruppenJson($gruppen),
            'angelegt_von' => mb_substr($von, 0, 64),
            'angelegt_am'  => Zeit::iso(Einstellungen::jetzt()),
        ]);
    }

    /**
     * Die Verknüpfung lösen: alle Identitäten des Kontos und seine Rollen aus
     * Gruppen weg; laufende SSO-Sitzungen enden damit (Schutz::sitzungGilt).
     * false, wenn es nichts zu lösen gab.
     */
    public static function loesen(int $userId): bool
    {
        $identitaeten = Database::select('SELECT id, weg FROM sso_identitaeten WHERE user_id = ? ORDER BY id', [$userId]);
        if ($identitaeten === []) {
            return false;
        }
        Database::transaktion(static function () use ($userId): void {
            Database::execute('DELETE FROM sso_identitaeten WHERE user_id = ?', [$userId]);
            Database::execute("DELETE FROM user_roles WHERE user_id = ? AND quelle = 'sso'", [$userId]);
        });
        Zugriff::vergessen();
        Auth::rechteVergessen();
        Audit::log('sso.geloest', 'user', (string) $userId, ['user_id' => $userId, 'weg' => (string) $identitaeten[0]['weg']]);
        return true;
    }

    /** Alle SSO-Sitzungen dieses Kontos, die bis jetzt begonnen haben, enden (abgemeldet_am). */
    public static function sitzungenBeenden(int $userId): void
    {
        Database::execute('UPDATE sso_identitaeten SET abgemeldet_am = ? WHERE user_id = ?', [Zeit::iso(Einstellungen::jetzt()), $userId]);
        Audit::log('sso.sitzungen_beendet', 'user', (string) $userId, ['user_id' => $userId]);
    }

    /** @return list<array<string,mixed>> verknüpfte Konten mit Benutzername (für Oberfläche und CLI) */
    public static function verknuepfungen(): array
    {
        return array_map(static function (array $i): array {
            $i['gruppen'] = self::gruppenAus($i['gruppen'] ?? null);
            return $i;
        }, Database::select(
            'SELECT i.*, u.username, u.full_name, u.nur_sso, u.kunde_id FROM sso_identitaeten i JOIN users u ON u.id = i.user_id ORDER BY u.username'
        ));
    }

    // ------------------------------------------------------------ Zuordnungen

    /** @return list<array<string,mixed>> Gruppe → Rolle/Kunde mit dem Namen des Ziels */
    public static function zuordnungen(): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM sso_zuordnungen ORDER BY art, gruppe, id') as $z) {
            $z['ziel_name'] = (string) ($z['art'] === 'rolle'
                ? Database::scalar('SELECT name FROM roles WHERE id = ?', [(int) $z['ziel_id']])
                : Database::scalar('SELECT name FROM kunden WHERE id = ?', [(int) $z['ziel_id']]));
            $out[] = $z;
        }
        return $out;
    }

    /**
     * Die Kundengruppen (Gruppe → Kunde) – alle oder die eines Kunden.
     *
     * @return list<array<string,mixed>>
     */
    public static function kundengruppen(?int $kundeId = null): array
    {
        return array_values(array_filter(self::zuordnungen(), static fn (array $z): bool
            => $z['art'] === 'kunde' && ($kundeId === null || (int) $z['ziel_id'] === $kundeId)));
    }

    /**
     * Ist unter diesen Gruppen eine Kundengruppe, die gerade wirkt (sso.kunden
     * an, Kundenbereich da)? Eine solche Anmeldung wird ein Kundenzugang – nie
     * ein Konto des Personals (die Prüfkette lehnte es als „gemischt“ ab).
     *
     * @param list<string> $gruppen
     */
    public static function kundengruppeIn(array $gruppen): bool
    {
        if ($gruppen === [] || !Kundenbereich::verfuegbar() || !Einstellungen::schalter(Einstellungen::KUNDEN)) {
            return false;
        }
        foreach (self::kundengruppen() as $z) {
            if (in_array((string) $z['gruppe'], $gruppen, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Darf der Angemeldete diese Gruppe dieser Rolle bzw. diesem Kunden
     * zuordnen? null = ja, sonst die Meldung (§6.5). Wer eine Gruppe einer
     * Rolle zuordnet, vergibt die Rolle an jedes Gruppenmitglied – also nur,
     * wer sie auch von Hand vergeben dürfte.
     */
    public static function zuordnungFehler(string $gruppe, string $art, int $zielId): ?string
    {
        $gruppe = trim($gruppe);
        if (!self::gruppeGueltig($gruppe)) {
            return 'Die Gruppe hat 1 bis 150 Zeichen, ohne Steuerzeichen – genau so, wie der Anmeldedienst sie nennt.';
        }
        if (!in_array($art, self::ARTEN, true)) {
            return 'Eine Gruppe wird einer Rolle oder einem Kunden zugeordnet.';
        }
        $alle = Database::select('SELECT gruppe, art, ziel_id FROM sso_zuordnungen');
        if (count($alle) >= self::MAX_ZUORDNUNGEN) {
            return 'Höchstens ' . self::MAX_ZUORDNUNGEN . ' Zuordnungen.';
        }
        foreach ($alle as $z) {
            if ((string) $z['gruppe'] !== $gruppe) {
                continue;
            }
            if ((string) $z['art'] !== $art) {
                return 'Eine Gruppe ist entweder Rollen- oder Kundengruppe, nie beides.';
            }
            if ((int) $z['ziel_id'] === $zielId) {
                return 'Diese Zuordnung gibt es schon.';
            }
            if ($art === 'kunde') {
                return 'Diese Gruppe ist schon einem anderen Kunden zugeordnet.';
            }
        }
        if ($art === 'kunde') {
            if (!Kundenbereich::verfuegbar() || !Einstellungen::schalter(Einstellungen::KUNDEN)) {
                return 'Kundengruppen gibt es erst mit „Kundenzugänge über Gruppen“.';
            }
            if (!Auth::can('kunden.manage')) {
                return 'Gruppen Kunden zuordnen darf nur, wer Kunden pflegen darf.';
            }
            if (Kundenbereich::kunde($zielId) === null) {
                return 'Diesen Kunden gibt es nicht, oder er ist nicht aktiv.';
            }
            // Jedes Gruppenmitglied bekommt einen Zugang dieses Kunden – wie ein
            // Zugang von Hand nur von jemandem, der alles sieht, was der Kunde sieht.
            return Kundenbereich::darfZugangAusgeben($zielId) ? null
                : 'Eine Kundengruppe gibt Zugänge aus – zuordnen darf sie nur, wer selbst alles sieht, was der Kunde sieht (wie beim Zugang von Hand).';
        }
        $rolle = Database::first('SELECT id, name, is_system FROM roles WHERE id = ?', [$zielId]);
        if ($rolle === null) {
            return 'Diese Rolle gibt es nicht.';
        }
        if (!Auth::can('system.users.manage')) {
            return 'Gruppen Rollen zuordnen darf nur, wer Benutzer verwalten darf.';
        }
        $rechte = BenutzerService::rollenRechte($zielId);
        if (!BenutzerService::darfVergeben($rechte)) {
            return 'Die Rolle „' . $rolle['name'] . '“ hat Rechte, die du selbst nicht hast – zuordnen darf sie nur, wer sie alle hat.';
        }
        $admins = Einstellungen::schalter(Einstellungen::ADMINS);
        $alleRechte = in_array('*', Auth::permissions(), true);
        if (((int) $rolle['is_system'] === 1 || in_array('*', $rechte, true)) && !($alleRechte && $admins)) {
            return 'Die Rolle mit allen Rechten ordnet nur zu, wer selbst alle Rechte hat – und erst mit ‚Verwaltung per SSO‘.';
        }
        if (Schutz::verwaltend($rechte) && !$admins) {
            return 'Mitglieder könnten sich mit dieser Rolle nicht anmelden – erst ‚Verwaltung per SSO‘ einschalten.';
        }
        foreach (Database::select("SELECT ziel_art, darf_steuern FROM freigaben WHERE subject_type = 'role' AND subject_id = ?", [$zielId]) as $f) {
            if (((string) $f['ziel_art'] === 'alles' || (int) $f['darf_steuern'] === 1) && !$alleRechte) {
                return 'Die Rolle hat Freigaben auf alles oder mit „schalten“ – einer Gruppe zuordnen darf sie nur die Administration.';
            }
        }
        return null;
    }

    /** @return array{ok:bool,message:string,id?:int,hinweis?:string} */
    public static function zuordnungSpeichern(string $gruppe, string $art, int $zielId): array
    {
        $gruppe = trim($gruppe);
        $fehler = self::zuordnungFehler($gruppe, $art, $zielId);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $id = Database::insert('sso_zuordnungen', ['gruppe' => $gruppe, 'art' => $art, 'ziel_id' => $zielId,
            'created_by' => mb_substr(Auth::name(), 0, 64), 'created_at' => gmdate('c')]);
        Audit::log('sso.zuordnung', 'sso_zuordnung', (string) $id, ['gruppe' => $gruppe, 'art' => $art, 'ziel_id' => $zielId, 'aktion' => 'angelegt']);
        $ergebnis = ['ok' => true, 'message' => 'Gruppe „' . $gruppe . '“ zugeordnet.', 'id' => $id];
        $freigaben = $art === 'rolle'
            ? (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'role' AND subject_id = ?", [$zielId]) : 0;
        if ($freigaben > 0) {
            $ergebnis['hinweis'] = 'Jedes Gruppenmitglied sieht diese ' . $freigaben . ' Freigabe(n) der Rolle.';
        }
        return $ergebnis;
    }

    /**
     * Eine Zuordnung löschen – bei einer Rolle sofort auch alle Rollen aus
     * Gruppen dieser Rolle (wer sie über eine andere Gruppe hat, bekommt sie
     * bei der nächsten Anmeldung wieder), bei einem Kunden die SSO-Sitzungen
     * seiner Kundenzugänge. Eine Rolle löst, wer sie vergeben dürfte; eine
     * Kundengruppe, wer Kunden pflegen darf (weniger Zugang gibt jeder).
     *
     * @return array{ok:bool,message:string,art?:string,ziel_id?:int}
     */
    public static function zuordnungLoeschen(int $id): array
    {
        $z = Database::first('SELECT * FROM sso_zuordnungen WHERE id = ?', [$id]);
        if ($z === null) {
            return ['ok' => false, 'message' => 'Diese Zuordnung gibt es nicht.'];
        }
        $rolle = (string) $z['art'] === 'rolle';
        if ($rolle && (!Auth::can('system.users.manage') || !BenutzerService::darfVergeben(BenutzerService::rollenRechte((int) $z['ziel_id'])))) {
            return ['ok' => false, 'message' => 'Diese Zuordnung darf nur entfernen, wer die Rolle auch vergeben dürfte.'];
        }
        if (!$rolle && !Auth::can('kunden.manage')) {
            return ['ok' => false, 'message' => 'Kundengruppen entfernt nur, wer Kunden pflegen darf.'];
        }
        $rollen = 0;
        $sitzungen = 0;
        Database::transaktion(static function () use ($id, $z, $rolle, &$rollen, &$sitzungen): void {
            Database::delete('sso_zuordnungen', $id);
            if ($rolle) {
                $rollen = Database::execute("DELETE FROM user_roles WHERE role_id = ? AND quelle = 'sso'", [(int) $z['ziel_id']]);
            } else {
                // Wie bei den Rollen sofort: Die SSO-Sitzungen der Kundenzugänge
                // dieses Kunden enden; wer noch in einer seiner Gruppen ist,
                // meldet sich einfach neu an.
                $sitzungen = Database::execute('UPDATE sso_identitaeten SET abgemeldet_am = ? WHERE user_id IN (SELECT id FROM users WHERE kunde_id = ?)',
                    [Zeit::iso(Einstellungen::jetzt()), (int) $z['ziel_id']]);
            }
        });
        Zugriff::vergessen();
        Auth::rechteVergessen();
        Audit::log('sso.zuordnung', 'sso_zuordnung', (string) $id, ['gruppe' => (string) $z['gruppe'], 'art' => (string) $z['art'],
            'ziel_id' => (int) $z['ziel_id'], 'aktion' => 'geloescht'] + ($rolle ? ['sso_rollen' => $rollen] : ['sso_sitzungen' => $sitzungen]));
        return ['ok' => true, 'message' => 'Zuordnung der Gruppe „' . $z['gruppe'] . '“ entfernt.'
            . (!$rolle && $sitzungen > 0 ? ' Die Sitzungen der Kundenzugänge über ' . Einstellungen::name() . ' sind beendet.' : ''),
            'art' => (string) $z['art'], 'ziel_id' => (int) $z['ziel_id']];
    }

    // ------------------------------------------------------------ Anmeldeanfragen

    /**
     * Eine Anmeldeanfrage merken (oder hochzählen) – nur geprüfte Angaben des
     * ID-Tokens. Höchstens MAX_ANFRAGEN; die ältesten gehen. Ohne Audit:
     * unangemeldete Anfragen dürfen das Audit nicht füllen.
     *
     * @param list<string> $gruppen
     */
    public static function anfrageMerken(array $claims, array $gruppen, string $grund): void
    {
        $iss = Einstellungen::aussteller();
        $sub = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        if ($iss === '' || preg_match('/^[\x21-\x7e]{1,255}$/', $sub) !== 1) {
            return;
        }
        $schluessel = self::schluessel($iss, $sub);
        $jetzt = Zeit::iso(Einstellungen::jetzt());
        $email = self::text($claims['email'] ?? null, 190);
        $werte = [
            'name_dort'        => self::nameDort($claims),
            'voller_name'      => self::text($claims['name'] ?? null, 190),
            'email'            => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'email_bestaetigt' => ($claims['email_verified'] ?? null) === true ? 1 : 0,
            'gruppen'          => self::gruppenJson($gruppen),
            'grund'            => mb_substr($grund, 0, 16),
            'letzte_am'        => $jetzt,
        ];
        $aendern = static function () use ($schluessel, $werte): bool {
            $sets = implode(', ', array_map(static fn (string $k): string => $k . ' = ?', array_keys($werte)));
            return Database::execute('UPDATE sso_anfragen SET ' . $sets . ', anzahl = anzahl + 1 WHERE schluessel = ?',
                array_merge(array_values($werte), [$schluessel])) > 0;
        };
        if (!$aendern()) {
            try {
                Database::insert('sso_anfragen', $werte + ['schluessel' => $schluessel, 'aussteller' => $iss, 'subjekt' => $sub,
                    'anzahl' => 1, 'erste_am' => $jetzt]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $aendern();
            }
        }
        $zuViele = (int) Database::scalar('SELECT COUNT(*) FROM sso_anfragen') - self::MAX_ANFRAGEN;
        if ($zuViele > 0) {
            foreach (Database::select('SELECT id FROM sso_anfragen ORDER BY letzte_am, id LIMIT ' . $zuViele) as $alt) {
                Database::delete('sso_anfragen', (int) $alt['id']);
            }
        }
    }

    /**
     * Die offenen Anmeldeanfragen, neueste zuerst – mit einem Vorschlag
     * (nur Vorauswahl, verknüpft wird erst auf Klick): ein unverknüpftes
     * Konto des Personals gleichen Namens (ohne Groß/Klein) oder mit gleicher,
     * beim Anmeldedienst bestätigter E-Mail-Adresse.
     *
     * @return list<array<string,mixed>>
     */
    public static function anfragen(): array
    {
        $konten = Database::select('SELECT id, username, email FROM users WHERE kunde_id IS NULL AND id NOT IN (SELECT user_id FROM sso_identitaeten)');
        $out = [];
        foreach (Database::select('SELECT * FROM sso_anfragen ORDER BY letzte_am DESC, id DESC') as $a) {
            $a['gruppen'] = self::gruppenAus($a['gruppen'] ?? null);
            $a['kundengruppe'] = self::kundengruppeIn($a['gruppen']);
            $a['vorschlag'] = null;
            foreach ($konten as $k) {
                $name = (string) $a['name_dort'] !== '' && strcasecmp((string) $k['username'], (string) $a['name_dort']) === 0;
                $mail = (int) $a['email_bestaetigt'] === 1 && (string) $a['email'] !== '' && strcasecmp((string) $k['email'], (string) $a['email']) === 0;
                if ($name || $mail) {
                    $a['vorschlag'] = (int) $k['id'];
                    break;
                }
            }
            $out[] = $a;
        }
        return $out;
    }

    /**
     * Eine Anfrage an ein bestehendes Konto freischalten (verknüpfen). Aus dem
     * Browser kommen nur die beiden IDs – Aussteller, Subjekt und Name kommen
     * aus der gemerkten Zeile. Die Rollen kommen bei der nächsten Anmeldung.
     *
     * @return array{ok:bool,message:string}
     */
    public static function anfrageFreischalten(int $anfrageId, int $userId): array
    {
        if (!Auth::can('system.users.manage')) {
            return ['ok' => false, 'message' => 'Anmeldeanfragen freischalten darf nur, wer Benutzer verwalten darf.'];
        }
        $a = Database::first('SELECT * FROM sso_anfragen WHERE id = ?', [$anfrageId]);
        if ($a === null) {
            return ['ok' => false, 'message' => 'Diese Anmeldeanfrage gibt es nicht (mehr).'];
        }
        $user = BenutzerService::find($userId);
        if ($user === null) {
            return ['ok' => false, 'message' => 'Dieses Konto gibt es nicht.'];
        }
        if (Kundenbereich::istKundenzugang($user)) {
            return ['ok' => false, 'message' => self::T_KUNDE_NIE];
        }
        if (!BenutzerService::darfVerwalten($userId)) {
            return ['ok' => false, 'message' => 'Dieses Konto hat Rechte, die du selbst nicht hast – verknüpfen darf es nur, wer sie alle hat.'];
        }
        if (!hash_equals(Einstellungen::aussteller(), (string) $a['aussteller'])) {
            return ['ok' => false, 'message' => 'Die Anfrage gehört zu einem anderen Aussteller – bitte verwerfen.'];
        }
        if (self::kundengruppeIn(self::gruppenAus($a['gruppen'] ?? null))) {
            return ['ok' => false, 'message' => self::T_ANFRAGE_KUNDE];
        }
        try {
            Database::transaktion(static function () use ($a, $userId): void {
                self::verknuepfen($userId, (string) $a['aussteller'], (string) $a['subjekt'], 'freischaltung', (string) $a['name_dort'],
                    self::gruppenAus($a['gruppen'] ?? null));
                Database::delete('sso_anfragen', (int) $a['id']);
            });
        } catch (SsoFehler $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        Audit::log('sso.verknuepft', 'user', (string) $userId, ['user_id' => $userId, 'weg' => 'freischaltung', 'anfrage_id' => $anfrageId]);
        return ['ok' => true, 'message' => 'Freigeschaltet – „' . $user['username'] . '“ meldet sich ab jetzt auch über ' . Einstellungen::name() . ' an.'];
    }

    /**
     * Zu einer Anfrage ein neues Konto anlegen (nur SSO, Rollen von Hand,
     * die der Angemeldete vergeben darf) und verknüpfen – alles oder nichts.
     *
     * @param array{username?:mixed, rollen?:mixed, gueltig_bis?:mixed} $daten
     * @return array{ok:bool,message:string,user_id?:int}
     */
    public static function anfrageAnlegen(int $anfrageId, array $daten): array
    {
        if (!Auth::can('system.users.manage')) {
            return ['ok' => false, 'message' => 'Konten anlegen darf nur, wer Benutzer verwalten darf.'];
        }
        $a = Database::first('SELECT * FROM sso_anfragen WHERE id = ?', [$anfrageId]);
        if ($a === null) {
            return ['ok' => false, 'message' => 'Diese Anmeldeanfrage gibt es nicht (mehr).'];
        }
        if (!hash_equals(Einstellungen::aussteller(), (string) $a['aussteller'])) {
            return ['ok' => false, 'message' => 'Die Anfrage gehört zu einem anderen Aussteller – bitte verwerfen.'];
        }
        if (self::kundengruppeIn(self::gruppenAus($a['gruppen'] ?? null))) {
            return ['ok' => false, 'message' => self::T_ANFRAGE_KUNDE];
        }
        $username = is_string($daten['username'] ?? null) ? trim($daten['username']) : '';
        if (!self::nameFrei($username)) {
            return ['ok' => false, 'message' => 'Benutzername: 2–64 Zeichen, Buchstaben, Ziffern und . _ @ -, und noch frei (auch in anderer Schreibweise).'];
        }
        $rollen = [];
        foreach (is_array($daten['rollen'] ?? null) ? $daten['rollen'] : [] as $r) {
            $r = is_int($r) || (is_string($r) && ctype_digit($r)) ? (int) $r : 0;
            $rolle = Database::first('SELECT id, name, is_system FROM roles WHERE id = ?', [$r]);
            if ($rolle === null) {
                return ['ok' => false, 'message' => 'Diese Rolle gibt es nicht.'];
            }
            $rechte = BenutzerService::rollenRechte($r);
            if (!BenutzerService::darfVergeben($rechte)) {
                return ['ok' => false, 'message' => 'Die Rolle „' . $rolle['name'] . '“ hat Rechte, die du selbst nicht hast – vergeben darf sie nur, wer sie alle hat.'];
            }
            if ((Schutz::verwaltend($rechte) || (int) $rolle['is_system'] === 1) && !Einstellungen::schalter(Einstellungen::ADMINS)) {
                return ['ok' => false, 'message' => 'Mit der Rolle „' . $rolle['name'] . '“ könnte sich das Konto nicht über SSO anmelden – erst ‚Verwaltung per SSO‘ einschalten.'];
            }
            $rollen[$r] = ['aktiv' => 1];
        }
        $bis = is_string($daten['gueltig_bis'] ?? null) ? trim($daten['gueltig_bis']) : '';
        $userId = 0;
        try {
            Database::transaktion(static function () use ($a, $username, $rollen, $bis, &$userId): void {
                $r = BenutzerService::speichern(null, [
                    'username'    => $username,
                    'full_name'   => (string) $a['voller_name'],
                    'email'       => (int) $a['email_bestaetigt'] === 1 ? (string) $a['email'] : '',
                    'is_active'   => 1,
                    'nur_sso'     => 1,
                    'gueltig_bis' => $bis,
                    'notizen'     => 'Über eine SSO-Anmeldeanfrage angelegt am ' . Zeit::anzeige(Zeit::iso(Einstellungen::jetzt())) . '.',
                ], $rollen);
                if (!$r['ok']) {
                    throw new SsoFehler('anlegen', $r['message']);
                }
                $userId = (int) $r['id'];
                self::verknuepfen($userId, (string) $a['aussteller'], (string) $a['subjekt'], 'freischaltung', (string) $a['name_dort'],
                    self::gruppenAus($a['gruppen'] ?? null));
                Database::delete('sso_anfragen', (int) $a['id']);
            });
        } catch (SsoFehler $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        Audit::log('sso.anfrage', 'sso_anfrage', (string) $anfrageId, ['anfrage_id' => $anfrageId, 'aktion' => 'angelegt', 'user_id' => $userId]);
        Audit::log('sso.verknuepft', 'user', (string) $userId, ['user_id' => $userId, 'weg' => 'freischaltung', 'anfrage_id' => $anfrageId]);
        return ['ok' => true, 'message' => 'Konto „' . $username . '“ angelegt – es meldet sich nur über ' . Einstellungen::name() . ' an.', 'user_id' => $userId];
    }

    /** @return array{ok:bool,message:string} */
    public static function anfrageVerwerfen(int $anfrageId): array
    {
        if (!Auth::can('system.users.manage')) {
            return ['ok' => false, 'message' => 'Anmeldeanfragen verwerfen darf nur, wer Benutzer verwalten darf.'];
        }
        if (Database::execute('DELETE FROM sso_anfragen WHERE id = ?', [$anfrageId]) === 0) {
            return ['ok' => false, 'message' => 'Diese Anmeldeanfrage gibt es nicht (mehr).'];
        }
        Audit::log('sso.anfrage', 'sso_anfrage', (string) $anfrageId, ['anfrage_id' => $anfrageId, 'aktion' => 'verworfen']);
        return ['ok' => true, 'message' => 'Anmeldeanfrage verworfen.'];
    }

    /**
     * Kommandozeile: alle Identitäten auf einen neuen Aussteller derselben
     * Authentik-Installation umschreiben (schluessel neu). Nur dort, wo das
     * Subjekt installationsweit gilt (hashed user id) – sonst entstünde eine
     * falsche Zuordnung. Gibt die Zahl der umgeschriebenen zurück.
     *
     * Identitäten, die schon beim neuen Aussteller stehen, bleiben. Stieße
     * eine umgeschriebene mit einer dort zusammen (dasselbe Konto oder
     * dasselbe Subjekt), wird nichts umgestellt (SsoFehler) – erst lösen.
     * Offene Anmeldeanfragen ziehen mit um (eine doppelte fällt weg).
     */
    public static function ausstellerUmstellen(string $neu): int
    {
        $fehler = Einstellungen::ausstellerFehler($neu);
        if ($fehler !== null) {
            throw new SsoFehler('aussteller', $fehler);
        }
        $anzahl = 0;
        Database::transaktion(static function () use ($neu, &$anzahl): void {
            $alle = Database::select('SELECT id, user_id, aussteller, subjekt FROM sso_identitaeten ORDER BY id');
            $konten = [];
            $schluessel = [];
            foreach ($alle as $i) {
                if (hash_equals($neu, (string) $i['aussteller'])) {
                    $konten[(int) $i['user_id']] = true;
                    $schluessel[self::schluessel($neu, (string) $i['subjekt'])] = true;
                }
            }
            $umstellen = [];
            $zusammen = 0;
            foreach ($alle as $i) {
                if (hash_equals($neu, (string) $i['aussteller'])) {
                    continue;
                }
                $k = self::schluessel($neu, (string) $i['subjekt']);
                if (isset($konten[(int) $i['user_id']]) || isset($schluessel[$k])) {
                    $zusammen++;
                    continue;
                }
                $konten[(int) $i['user_id']] = true;
                $schluessel[$k] = true;
                $umstellen[(int) $i['id']] = $k;
            }
            if ($zusammen > 0) {
                throw new SsoFehler('aussteller', $zusammen . ' Verknüpfung(en) stießen mit einer beim neuen Aussteller zusammen (dasselbe Konto oder dasselbe Subjekt) – erst lösen (bin/power sso:loesen), dann umstellen.');
            }
            foreach ($umstellen as $id => $k) {
                Database::update('sso_identitaeten', $id, ['aussteller' => $neu, 'schluessel' => $k]);
                $anzahl++;
            }
            foreach (Database::select('SELECT id, aussteller, subjekt FROM sso_anfragen ORDER BY id') as $a) {
                if (hash_equals($neu, (string) $a['aussteller'])) {
                    continue;
                }
                $k = self::schluessel($neu, (string) $a['subjekt']);
                if (Database::first('SELECT id FROM sso_anfragen WHERE schluessel = ?', [$k]) !== null) {
                    Database::delete('sso_anfragen', (int) $a['id']);
                } else {
                    Database::update('sso_anfragen', (int) $a['id'], ['aussteller' => $neu, 'schluessel' => $k]);
                }
            }
        });
        Audit::log('sso.aussteller_umgestellt', 'settings', '', ['aussteller' => $neu, 'anzahl' => $anzahl]);
        return $anzahl;
    }

    /**
     * Für bin/power wartung: Rollen aus Gruppen, die seit mehr als einem Tag
     * abgelaufen sind; Anmeldeanfragen nach ANFRAGE_TAGE; mit
     * `sso.inaktiv_tage` > 0 Konten „nur über SSO“, die sich so lange nicht
     * über SSO angemeldet haben (zuletzt_am, ohne Identität seit created_at),
     * deaktivieren – Audit `sso.konto_deaktiviert`. Das gilt auch, während
     * SSO aus ist: Wer so lange nicht hereinkam, wird nicht wieder aktiv,
     * nur weil SSO wieder angeht.
     *
     * @return list<string> Meldungen (nur, wenn etwas geschah)
     */
    public static function wartung(?int $jetzt = null): array
    {
        $jetzt ??= Einstellungen::jetzt();
        $meldungen = [];
        $rollen = 0;
        foreach (Database::select("SELECT user_id, role_id, gueltig_bis FROM user_roles WHERE quelle = 'sso' AND gueltig_bis IS NOT NULL") as $z) {
            $bis = Zeit::ts((string) $z['gueltig_bis']);
            if ($bis !== null && $bis < $jetzt - 86400) {
                $rollen += Database::execute("DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND quelle = 'sso'", [(int) $z['user_id'], (int) $z['role_id']]);
            }
        }
        $anfragen = 0;
        foreach (Database::select('SELECT id, letzte_am FROM sso_anfragen') as $a) {
            $am = Zeit::ts((string) $a['letzte_am']);
            if ($am === null || $am < $jetzt - self::ANFRAGE_TAGE * 86400) {
                Database::delete('sso_anfragen', (int) $a['id']);
                $anfragen++;
            }
        }
        if ($rollen > 0 || $anfragen > 0) {
            $meldungen[] = "SSO: aufgeräumt {$rollen} abgelaufene Rolle(n) aus Gruppen, {$anfragen} Anmeldeanfrage(n) (älter als " . self::ANFRAGE_TAGE . ' Tage).';
        }
        $tage = Einstellungen::inaktivTage();
        if ($tage > 0) {
            $namen = [];
            foreach (Database::select('SELECT * FROM users WHERE nur_sso = 1 AND is_active = 1 ORDER BY username') as $u) {
                $zuletzt = null;
                foreach (Database::select('SELECT zuletzt_am FROM sso_identitaeten WHERE user_id = ?', [(int) $u['id']]) as $i) {
                    $ts = Zeit::ts($i['zuletzt_am'] === null ? null : (string) $i['zuletzt_am']);
                    $zuletzt = $ts !== null && ($zuletzt === null || $ts > $zuletzt) ? $ts : $zuletzt;
                }
                $zuletzt ??= Zeit::ts((string) $u['created_at']);
                if ($zuletzt !== null && $zuletzt >= $jetzt - $tage * 86400) {
                    continue;
                }
                Database::update('users', (int) $u['id'], ['is_active' => 0]);
                Audit::log('sso.konto_deaktiviert', 'user', (string) $u['id'], ['user_id' => (int) $u['id'], 'tage' => $tage]);
                $namen[] = (string) $u['username'];
            }
            if ($namen !== []) {
                Zugriff::vergessen();
                Auth::rechteVergessen();
                $meldungen[] = 'SSO: ' . count($namen) . ' Konto/Konten „nur über SSO“ ohne Anmeldung seit ' . $tage . ' Tagen deaktiviert: ' . implode(', ', $namen) . '.';
            }
        }
        return $meldungen;
    }

    // ------------------------------------------------------------ intern

    /** @param list<string> $gruppen @return array{user:array, identitaet:array, angelegt:bool, rollen:array} */
    private static function aufloesenJetzt(array $claims, array $gruppen): array
    {
        $iss = Einstellungen::aussteller();
        $sub = (string) $claims['sub'];
        $name = self::nameDort($claims);
        $ident = self::identitaetFinden($iss, $sub);
        $user = $ident !== null ? BenutzerService::find((int) $ident['user_id']) : null;
        if ($ident !== null && $user === null) {
            Database::delete('sso_identitaeten', (int) $ident['id']);   // verwaist
            $ident = null;
        }
        $kunde = self::kundeFuer($gruppen, $name);
        $ziel = self::zielRollen($gruppen, $uebergangen);
        $angelegt = false;
        if ($user === null) {
            if ($kunde !== null && $ziel !== []) {
                throw new SsoFehler('gemischt', Schutz::T_GEMISCHT, false, $name);
            }
            if ($kunde === null && ($ziel === [] || !Einstellungen::schalter(Einstellungen::ANLEGEN))) {
                throw new SsoFehler('kein_konto', self::T_KEIN_KONTO, true, $name);
            }
            // Kein Ersatzname, keine Übernahme: ein vergebener Name wird eine Anfrage.
            if (!self::nameFrei($name)) {
                throw new SsoFehler('name', self::T_NAME, true, $name);
            }
            $felder = [
                'username'  => $name,
                'full_name' => self::text($claims['name'] ?? null, 190),
                'email'     => self::bestaetigteEmail($claims),
                'notizen'   => 'Automatisch über SSO angelegt am ' . Zeit::anzeige(Zeit::iso(Einstellungen::jetzt())) . '.',
            ];
            $userId = $kunde !== null ? Kundenbereich::zugangAnlegen($felder, (int) $kunde['id']) : self::personalAnlegen($felder);
            $identId = self::verknuepfen($userId, $iss, $sub, 'anlage', $name, $gruppen);
            $user = BenutzerService::find($userId);
            $ident = Database::first('SELECT * FROM sso_identitaeten WHERE id = ?', [$identId]);
            $angelegt = true;
        }
        $userId = (int) $user['id'];
        Schutz::pruefkette($user, $claims, $gruppen, $kunde, $ziel);
        $rollen = ['hinzu' => [], 'weg' => []];
        if (!Kundenbereich::istKundenzugang($user)) {
            $rollen = self::rollenAbgleichen($userId, $ziel);
        }
        if ((int) $user['nur_sso'] === 1 && !$angelegt) {
            $profil = [];
            $voll = self::text($claims['name'] ?? null, 190);
            if ($voll !== '') {
                $profil['full_name'] = $voll;
            }
            $email = self::bestaetigteEmail($claims);
            if ($email !== '') {
                $profil['email'] = $email;
            }
            Database::update('users', $userId, $profil);
        }
        Database::update('sso_identitaeten', (int) $ident['id'], [
            'zuletzt_am' => Zeit::iso(Einstellungen::jetzt()),
            'name_dort'  => $name,
            'gruppen'    => self::gruppenJson($gruppen),
        ]);
        Database::execute('DELETE FROM sso_anfragen WHERE schluessel = ?', [self::schluessel($iss, $sub)]);
        foreach ($uebergangen as $r) {
            Audit::log('sso.rolle_uebergangen', 'user', (string) $userId, ['user_id' => $userId, 'role_id' => $r]);
        }
        if ($angelegt) {
            Audit::log('sso.konto_angelegt', 'user', (string) $userId, ['user_id' => $userId, 'rollen' => array_map('intval', array_keys($ziel)),
                'kunde_id' => $kunde !== null ? (int) $kunde['id'] : null]);
        }
        return [
            'user'       => (array) BenutzerService::find($userId),
            'identitaet' => (array) Database::first('SELECT * FROM sso_identitaeten WHERE id = ?', [(int) $ident['id']]),
            'angelegt'   => $angelegt,
            'rollen'     => $rollen,
        ];
    }

    /** Ein Konto des Personals für SSO: ohne nutzbares Kennwort, nur_sso, ohne Rolle (die kommt aus der Gruppe) und ohne Freigabe. */
    private static function personalAnlegen(array $felder): int
    {
        return Database::insert('users', [
            'username'             => $felder['username'],
            'full_name'            => $felder['full_name'],
            'email'                => $felder['email'],
            'password_hash'        => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'is_active'            => 1,
            'must_change_password' => 0,
            'nur_sso'              => 1,
            'notizen'              => $felder['notizen'],
            'created_at'           => gmdate('c'),
        ]);
    }

    /** Taugt der Name als Benutzername und ist er frei – ohne Rücksicht auf Groß/Klein? */
    private static function nameFrei(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $name) === 1
            && Database::first('SELECT id FROM users WHERE LOWER(username) = LOWER(?)', [$name]) === null;
    }

    private static function gruppeGueltig(string $g): bool
    {
        return $g !== '' && preg_match('//u', $g) === 1 && preg_match('/[\x00-\x1F\x7F]/', $g) !== 1 && mb_strlen($g) <= 150;
    }

    /** @param list<string> $gruppen höchstens MAX_GRUPPEN_GESPEICHERT, als JSON-Liste */
    private static function gruppenJson(array $gruppen): string
    {
        return (string) json_encode(array_slice(array_values($gruppen), 0, self::MAX_GRUPPEN_GESPEICHERT), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private static function gruppenAus(mixed $json): array
    {
        $liste = is_string($json) ? json_decode($json, true) : null;
        return is_array($liste) ? array_values(array_filter($liste, 'is_string')) : [];
    }

    /** Ein Text aus Claims: nur Strings, gültiges UTF-8, ohne Steuerzeichen, gekürzt. */
    private static function text(mixed $wert, int $max): string
    {
        if (!is_string($wert) || preg_match('//u', $wert) !== 1) {
            return '';
        }
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $wert)), 0, $max);
    }

    /** Die E-Mail-Adresse nur, wenn der Anmeldedienst sie bestätigt (email_verified === true) und sie gültig aussieht. */
    private static function bestaetigteEmail(array $claims): string
    {
        $email = self::text($claims['email'] ?? null, 190);
        return ($claims['email_verified'] ?? null) === true && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }
}
