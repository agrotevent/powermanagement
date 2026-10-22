<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\ApiZugang;
use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Zugänge zur Schnittstelle pflegen: Tokens, die diese Instanz an andere
 * Instanzen ausgibt (Seite A).
 *
 * Rechte vergibt nur, wer sie hat: Standorte gibt nur frei, wer selbst alles
 * sieht (Zugriff::alles), jede Befugnis nur, wer die Rechte dahinter hat
 * (ApiZugang::BEFUGNIS_RECHT), und binden lässt sich ein Token nur an sich
 * selbst oder an jemanden, den man verwalten darf – lösen oder umbinden
 * ebenso nur, wer den bisher gebundenen verwalten darf.
 *
 * Das Token selbst geht genau zweimal hinaus: in der Antwort auf „Anlegen“
 * und auf „Anzeigen“ (mit Kennwort). Anzeigen darf es nur, wer es mit allen
 * seinen Befugnissen und seiner Bindung auch selbst anlegen dürfte
 * (darfVerwaltenToken) – sonst bekäme er eine fremde Befugnis in die Hand.
 * Nie in Audit, Log, Flash oder Sitzung.
 */
final class ApiZugaenge
{
    /** Wie lange ein neues Token ohne eigene Angabe gilt – das Formular schlägt es vor. */
    public const ABLAUF_TAGE = 365;

    /**
     * Alle Zugänge für die Liste – ohne Geheimnisse.
     *
     * @return list<array<string,mixed>> mit befugnisse (Liste), abrufbar, standorte, zustand, user_name
     */
    public static function alle(): array
    {
        $zeilen = Database::select(
            'SELECT t.*, u.username AS user_name FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id
             ORDER BY CASE WHEN t.revoked_at IS NULL THEN 0 ELSE 1 END, t.created_at DESC, t.id DESC'
        );
        // Wer nicht alles sieht, sieht in der Liste auch nur die Namen der
        // Standorte, die er selbst sieht – die übrigen nur als Anzahl.
        $alles = Zugriff::alles();
        return array_map(static function (array $z) use ($alles): array {
            $z = self::aufbereiten($z);
            $z['standorte_weitere'] = 0;
            if (!$alles) {
                $sichtbar = array_values(array_filter($z['standorte'], static fn (array $s): bool => Zugriff::siehtStandort((int) $s['id'])));
                $z['standorte_weitere'] = count($z['standorte']) - count($sichtbar);
                $z['standorte'] = $sichtbar;
            }
            return $z;
        }, $zeilen);
    }

    /** Ein Zugang ohne Geheimnisse – oder null. */
    public static function find(int $id): ?array
    {
        $z = Database::first(
            'SELECT t.*, u.username AS user_name FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?',
            [$id]
        );
        return $z === null ? null : self::aufbereiten($z);
    }

    /** @param array<string,mixed> $z Zeile aus api_tokens */
    private static function aufbereiten(array $z): array
    {
        // Ob es sich noch einmal anzeigen lässt – das Token selbst bleibt
        // verschlüsselt, bis jemand mit Kennwort „Anzeigen“ drückt.
        $z['abrufbar'] = (string) ($z['token_enc'] ?? '') !== '' && $z['revoked_at'] === null;
        unset($z['token_enc'], $z['token_hash']);
        $z['befugnisse'] = ApiZugang::befugnisseAus($z);
        // Ob der Angemeldete es anzeigen dürfte (Knopf „Anzeigen“).
        $z['verwaltbar'] = self::darfVerwaltenToken($z);
        $z['standorte'] = self::standorte((int) $z['id']);
        $z['zustand'] = self::zustand($z);
        return $z;
    }

    /**
     * aktiv | abgelaufen | widerrufen | ruht (der gebundene Benutzer ist
     * inaktiv, gelöscht oder außerhalb seines Zeitraums).
     */
    public static function zustand(array $z): string
    {
        if ($z['revoked_at'] !== null && (string) $z['revoked_at'] !== '') {
            return 'widerrufen';
        }
        $bis = Zeit::ts($z['expires_at'] ?? null);
        if ($bis !== null && $bis < time()) {
            return 'abgelaufen';
        }
        if ($z['user_id'] !== null && !ApiZugang::benutzerGueltig((int) $z['user_id'])) {
            return 'ruht';
        }
        return 'aktiv';
    }

    /**
     * Die Standorte eines Tokens für Liste und Formular.
     *
     * @return list<array{id:int,name:string,bearbeiten:bool}>
     */
    public static function standorte(int $tokenId): array
    {
        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['site_id'],
            'name'       => (string) $r['name'],
            'bearbeiten' => (int) $r['darf_bearbeiten'] === 1,
        ], Database::select(
            'SELECT ts.site_id, ts.darf_bearbeiten, s.name FROM api_token_standorte ts
             JOIN sites s ON s.id = ts.site_id WHERE ts.token_id = ? ORDER BY s.name, s.id',
            [$tokenId]
        ));
    }

    /**
     * Darf der Angemeldete dieses Token in die Hand bekommen (anzeigen)? Nur,
     * wer es genau so selbst anlegen dürfte: alle Standorte sehen, jede seiner
     * Befugnisse vergeben (mit „schreiben“ und „Feststrom“ auch Anlagen und
     * Kabel ändern) und – ist es gebunden – den Benutzer verwalten.
     *
     * @param array<string,mixed> $zeile aus api_tokens (befugnisse als JSON oder Liste, user_id)
     */
    public static function darfVerwaltenToken(array $zeile): bool
    {
        if (Auth::user() === null || !Zugriff::alles()) {
            return false;
        }
        $befugnisse = is_array($zeile['befugnisse'] ?? null) ? ApiZugang::befugnisseOrdnen($zeile['befugnisse']) : ApiZugang::befugnisseAus($zeile);
        foreach ($befugnisse as $b) {
            if (!BenutzerService::darfVergeben(ApiZugang::BEFUGNIS_RECHT[$b] ?? ['*'])) {
                return false;
            }
        }
        if (in_array('plaene.schreiben', $befugnisse, true) && in_array('feststrom', $befugnisse, true)
            && !BenutzerService::darfVergeben(ApiZugang::SCHREIBEN_FESTSTROM_RECHT)) {
            return false;
        }
        $userId = ($zeile['user_id'] ?? null) !== null ? (int) $zeile['user_id'] : null;
        return $userId === null || $userId === Auth::id() || BenutzerService::darfVerwalten($userId);
    }

    /**
     * Ein Token anlegen.
     *
     * @param array{name?:string,notiz?:string,befugnisse?:list<string>,user_id?:?int,ablauf?:string,sehen?:list<int|string>,bearbeiten?:list<int|string>} $e
     *        ablauf: Datum (JJJJ-MM-TT, Ortszeit, gilt bis Tagesende) oder '' für unbefristet
     * @return array{ok:bool,message:string,id?:int,token?:string} `token` nur hier im Klartext
     */
    public static function anlegen(array $e): array
    {
        $daten = self::eingabePruefen($e);
        if (isset($daten['fehler'])) {
            return ['ok' => false, 'message' => $daten['fehler']];
        }
        $schluessel = Crypto::schluesselFehler();
        if ($schluessel !== null) {
            return ['ok' => false, 'message' => $schluessel];
        }

        // 30 Zufallsbytes → 40 Zeichen base64url, ohne Füllzeichen.
        $token = ApiZugang::PRAEFIX . rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        try {
            $verschluesselt = Crypto::encrypt($token);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Das Token ließ sich nicht verschlüsseln (app.key prüfen).'];
        }
        $id = Database::insert('api_tokens', [
            'name'         => $daten['name'],
            'notiz'        => $daten['notiz'],
            'token_prefix' => substr($token, 0, 12),
            'token_hash'   => hash('sha256', $token),
            'token_enc'    => $verschluesselt,
            'befugnisse'   => json_encode($daten['befugnisse']),
            'user_id'      => $daten['user_id'],
            'created_by'   => mb_substr(Auth::name(), 0, 64),
            'created_at'   => gmdate('c'),
            'expires_at'   => $daten['bis'],
        ]);
        self::standorteSetzen($id, $daten['standorte']);
        Audit::log('api.token.anlegen', 'api_token', (string) $id, self::auditDetails($daten));

        return ['ok' => true, 'message' => 'Zugang „' . $daten['name'] . '“ angelegt.', 'id' => $id, 'token' => $token];
    }

    /**
     * Name, Notiz, Befugnisse, Standorte, Bindung und Ablauf ändern – mit
     * denselben Prüfungen wie beim Anlegen. Das Token bleibt dasselbe.
     */
    public static function speichern(int $id, array $e): array
    {
        $alt = Database::first('SELECT id, revoked_at, user_id FROM api_tokens WHERE id = ?', [$id]);
        if ($alt === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if ($alt['revoked_at'] !== null) {
            return ['ok' => false, 'message' => 'Ein widerrufener Zugang lässt sich nicht mehr ändern – bei Bedarf einen neuen anlegen.'];
        }
        $daten = self::eingabePruefen($e, $alt['user_id'] !== null ? (int) $alt['user_id'] : null);
        if (isset($daten['fehler'])) {
            return ['ok' => false, 'message' => $daten['fehler']];
        }
        Database::update('api_tokens', $id, [
            'name'       => $daten['name'],
            'notiz'      => $daten['notiz'],
            'befugnisse' => json_encode($daten['befugnisse']),
            'user_id'    => $daten['user_id'],
            'expires_at' => $daten['bis'],
            'updated_at' => gmdate('c'),
        ]);
        self::standorteSetzen($id, $daten['standorte']);
        Audit::log('api.token.speichern', 'api_token', (string) $id, self::auditDetails($daten));
        return ['ok' => true, 'message' => 'Zugang „' . $daten['name'] . '“ gespeichert.', 'id' => $id];
    }

    /**
     * Eingaben prüfen und vereinheitlichen – mit den Rechten des Angemeldeten.
     *
     * Die Bindung: an einen Benutzer binden nur, wer ihn verwalten darf (oder
     * sich selbst); eine bestehende Bindung lösen oder auf jemand anderen
     * legen ebenso nur, wer den bisher gebundenen verwalten darf – sonst
     * schaltete er ein Token wieder ein, das mit diesem Benutzer ruht. Bleibt
     * sie, wie sie war, wird sie nicht neu geprüft (Name, Notiz und Ablauf
     * bleiben änderbar).
     *
     * @param int|null $bisher der bisher gebundene Benutzer (beim Speichern)
     * @return array{fehler?:string,name:string,notiz:string,befugnisse:list<string>,user_id:?int,bis:?string,standorte:array<int,bool>}
     */
    private static function eingabePruefen(array $e, ?int $bisher = null): array
    {
        $fehler = static fn (string $m): array => ['fehler' => $m];

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($e['name'] ?? '')));
        if ($name === '') {
            return $fehler('Der Zugang braucht einen Namen – woran man später erkennt, wer damit arbeitet.');
        }
        $notiz = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', (string) ($e['notiz'] ?? '')));

        $befugnisse = ApiZugang::befugnisseOrdnen((array) ($e['befugnisse'] ?? []));
        if ($befugnisse === []) {
            return $fehler('Ohne Befugnis kann der Zugang nichts – mindestens eine anhaken.');
        }
        foreach ($befugnisse as $b) {
            if (!BenutzerService::darfVergeben(ApiZugang::BEFUGNIS_RECHT[$b] ?? ['*'])) {
                return $fehler(sprintf('Die Befugnis „%s“ vergibt nur, wer selbst %s hat.', $b, self::rechteText(ApiZugang::BEFUGNIS_RECHT[$b] ?? [])));
            }
        }
        if (in_array('plaene.schreiben', $befugnisse, true) && in_array('feststrom', $befugnisse, true)
            && !BenutzerService::darfVergeben(ApiZugang::SCHREIBEN_FESTSTROM_RECHT)) {
            return $fehler('„Schreiben“ zusammen mit „Feststrom“ ändert hier Anlagen und Kabel direkt – das vergibt nur, wer selbst '
                . self::rechteText(ApiZugang::SCHREIBEN_FESTSTROM_RECHT) . ' hat.');
        }

        $userId = (int) ($e['user_id'] ?? 0);
        $userId = $userId > 0 ? $userId : null;
        // Kundenzugänge (Kundenbereich) haben mit der Schnittstelle nichts zu
        // tun – kein Token hängt an ihnen, auch kein bestehendes bleibt es.
        if ($userId !== null && BenutzerService::istKundenzugang($userId)) {
            return $fehler('Tokens lassen sich nicht an Kundenzugänge binden.');
        }
        if ($userId !== null && $userId !== $bisher) {
            if (BenutzerService::find($userId) === null) {
                return $fehler('Diesen Benutzer gibt es nicht.');
            }
            if ($userId !== Auth::id() && !BenutzerService::darfVerwalten($userId)) {
                return $fehler('An diesen Benutzer lässt sich der Zugang nicht binden – er hat Rechte, die du selbst nicht hast.');
            }
        }
        // Eine Bindung an einen Kundenzugang (nur von Hand in der Datenbank
        // denkbar) darf jeder lösen, der den Zugang hier ändern darf.
        if ($bisher !== null && $userId !== $bisher && $bisher !== Auth::id() && !BenutzerService::istKundenzugang($bisher)
            && !BenutzerService::darfVerwalten($bisher)) {
            $name = (string) (BenutzerService::find($bisher)['username'] ?? 'ein gelöschtes Konto');
            return $fehler(sprintf('Die Bindung an „%s“ lösen oder ändern darf nur, wer diesen Benutzer verwalten darf.', $name));
        }

        $bis = null;
        $ablauf = trim((string) ($e['ablauf'] ?? ''));
        if ($ablauf !== '') {
            $bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ablauf) === 1 ? Zeit::ausEingabe($ablauf, true) : null;
            if ($bis === null || (Zeit::ts($bis) ?? 0) < time()) {
                return $fehler('Das Ablaufdatum liegt in der Vergangenheit oder ist kein Datum.');
            }
        }

        // Bearbeiten schließt Sehen ein. Nur Standorte, die es gibt.
        $vorhanden = array_map('intval', array_column(Database::select('SELECT id FROM sites'), 'id'));
        $ids = static fn (mixed $liste): array => array_values(array_intersect(
            array_unique(array_filter(array_map('intval', (array) $liste), static fn (int $i): bool => $i > 0)),
            $vorhanden
        ));
        $bearbeiten = $ids($e['bearbeiten'] ?? []);
        $standorte = [];
        foreach (array_merge($ids($e['sehen'] ?? []), $bearbeiten) as $siteId) {
            $standorte[$siteId] = in_array($siteId, $bearbeiten, true);
        }
        // Was ein Token sieht, regeln nicht die eigenen Freigaben – deshalb
        // gibt Standorte nur frei, wer ohnehin alles sieht.
        if ($standorte !== [] && !Zugriff::alles()) {
            return $fehler('Standorte für einen Zugang freigeben braucht das Recht „Alle Standorte, Anlagen und Messdaten“.');
        }

        return [
            'name'       => mb_substr($name, 0, 190),
            'notiz'      => mb_substr($notiz, 0, 500),
            'befugnisse' => $befugnisse,
            'user_id'    => $userId,
            'bis'        => $bis,
            'standorte'  => $standorte,
        ];
    }

    /** @param list<string> $rechte */
    private static function rechteText(array $rechte): string
    {
        return implode(' und ', array_map(static fn (string $r): string => '„' . \App\Auth\Permissions::label($r) . '“', $rechte));
    }

    /** @param array<int,bool> $standorte siteId => bearbeiten */
    private static function standorteSetzen(int $tokenId, array $standorte): void
    {
        Database::execute('DELETE FROM api_token_standorte WHERE token_id = ?', [$tokenId]);
        foreach ($standorte as $siteId => $bearbeiten) {
            Database::insert('api_token_standorte', [
                'token_id'        => $tokenId,
                'site_id'         => (int) $siteId,
                'darf_bearbeiten' => $bearbeiten ? 1 : 0,
                'created_by'      => mb_substr(Auth::name(), 0, 64),
                'created_at'      => gmdate('c'),
            ]);
        }
    }

    private static function auditDetails(array $daten): array
    {
        return [
            'name'       => $daten['name'],
            'befugnisse' => $daten['befugnisse'],
            'standorte'  => count($daten['standorte']),
            'bearbeiten' => count(array_filter($daten['standorte'])),
            'gebunden'   => $daten['user_id'] !== null,
            'bis'        => $daten['bis'],
        ];
    }

    /**
     * Das Token noch einmal im Klartext – nur bestätigt und nur für jemanden,
     * der alles sieht. Bestätigt wird mit dem eigenen Kennwort oder, ohne
     * Kennwort ($kennwort = ''), über eine frische Anmeldung beim
     * Anmeldedienst für genau diesen Zugang (Auth::bestaetigen, Zweck
     * token:<id> – einmalig, höchstens fünf Minuten alt). Ein Konto „nur über
     * SSO“ bestätigt nur so. Jeder Versuch steht im Audit-Log (ohne das
     * Token).
     *
     * @return array{ok:bool,message:string,token?:string,name?:string}
     */
    public static function klartext(int $id, #[\SensitiveParameter] string $kennwort): array
    {
        $user = Auth::user();
        if ($user === null || !Zugriff::alles()) {
            return ['ok' => false, 'message' => 'Ein Token anzeigen darf nur, wer alle Standorte sieht.'];
        }
        $z = Database::first('SELECT id, name, token_hash, token_enc, revoked_at, befugnisse, user_id FROM api_tokens WHERE id = ?', [$id]);
        if ($z === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        // Wer das Token sieht, hat seine Befugnisse in der Hand – also nur,
        // wer sie (und die Bindung) selbst vergeben dürfte.
        if (!self::darfVerwaltenToken($z)) {
            return ['ok' => false, 'message' => 'Dieses Token trägt Befugnisse oder eine Bindung, die du selbst nicht vergeben dürftest – anzeigen darf es nur, wer sie hat.'];
        }
        if (!Auth::bestaetigen('token:' . $id, $kennwort)) {
            Audit::log('api.token.anzeigen_abgelehnt', 'api_token', (string) $id, ['name' => (string) $z['name']]);
            $sso = \App\Auth\Sso\Einstellungen::name();
            return ['ok' => false, 'message' => match (true) {
                $kennwort === ''                    => 'Nicht bestätigt (oder die Bestätigung über ' . $sso . ' ist abgelaufen) – das Token wird nicht angezeigt.',
                \App\Auth\Sso\Schutz::nurSso($user) => 'Dieses Konto bestätigt nur über ' . $sso . ' – das Token wird nicht angezeigt.',
                default                             => 'Das Kennwort stimmt nicht – das Token wird nicht angezeigt.',
            }];
        }
        if ($z['revoked_at'] !== null || (string) ($z['token_enc'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Dieses Token lässt sich nicht mehr anzeigen – es ist widerrufen. Bei Bedarf einen neuen Zugang anlegen.'];
        }
        try {
            $token = Crypto::decrypt((string) $z['token_enc']);
        } catch (\Throwable) {
            $token = '';
        }
        // Passt der Klartext nicht zum Fingerabdruck (anderer app.key,
        // verfälschter Eintrag), ist er wertlos – lieber nichts zeigen.
        if ($token === '' || !hash_equals((string) $z['token_hash'], hash('sha256', $token))) {
            return ['ok' => false, 'message' => 'Das gespeicherte Token passt nicht mehr (anderer app.key?) – bitte einen neuen Zugang anlegen.'];
        }
        Audit::log('api.token.angezeigt', 'api_token', (string) $id, ['name' => (string) $z['name']]);
        return ['ok' => true, 'message' => 'Token angezeigt.', 'token' => $token, 'name' => (string) $z['name']];
    }

    /**
     * Widerrufen: ab sofort abgelehnt, und das verschlüsselte Token wird
     * gelöscht – es lässt sich danach auch nicht mehr anzeigen.
     */
    public static function widerrufen(int $id): array
    {
        $z = Database::first('SELECT id, name, revoked_at FROM api_tokens WHERE id = ?', [$id]);
        if ($z === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if ($z['revoked_at'] !== null) {
            return ['ok' => false, 'message' => 'Dieser Zugang ist schon widerrufen.'];
        }
        Database::update('api_tokens', $id, [
            'revoked_at' => gmdate('c'),
            'revoked_by' => mb_substr(Auth::name(), 0, 64),
            'token_enc'  => null,
            'updated_at' => gmdate('c'),
        ]);
        Audit::log('api.token.widerrufen', 'api_token', (string) $id, ['name' => (string) $z['name']]);
        return ['ok' => true, 'message' => 'Zugang „' . $z['name'] . '“ widerrufen – das Token wird ab sofort abgelehnt.'];
    }

    /**
     * Löschen – nur widerrufene oder abgelaufene Zugänge; ein gültiges Token
     * verschwindet nicht stillschweigend aus der Liste. Eingegangene
     * Vorschläge bleiben (mit dem Namen des Tokens).
     */
    public static function loeschen(int $id): array
    {
        $z = Database::first('SELECT * FROM api_tokens WHERE id = ?', [$id]);
        if ($z === null) {
            return ['ok' => false, 'message' => 'Diesen Zugang gibt es nicht.'];
        }
        if (!in_array(self::zustand($z), ['widerrufen', 'abgelaufen'], true)) {
            return ['ok' => false, 'message' => 'Löschen lassen sich nur widerrufene oder abgelaufene Zugänge – erst widerrufen.'];
        }
        Database::execute('DELETE FROM api_token_standorte WHERE token_id = ?', [$id]);
        Database::execute('DELETE FROM api_zuordnungen WHERE token_id = ?', [$id]);
        Database::delete('api_tokens', $id);
        Audit::log('api.token.loeschen', 'api_token', (string) $id, ['name' => (string) $z['name']]);
        return ['ok' => true, 'message' => 'Zugang „' . $z['name'] . '“ gelöscht.'];
    }
}
