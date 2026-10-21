<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Database;
use App\Support\Zeit;

/**
 * Anmelden an der Schnittstelle (/api/v1) und Reichweite eines Tokens.
 *
 * Dasselbe Protokoll wie MasterAdmin: ein Token „mtma_…“ im Kopf
 * `Authorization: Bearer …`. Das Präfix kennzeichnet das Protokoll, nicht
 * das Produkt – der Client von MasterAdmin nimmt kein anderes an.
 *
 * Was ein Token sieht, bestimmen **nur** seine Befugnisse und seine
 * Standorte (api_token_standorte) – nie die Freigaben eines Benutzers in
 * Zugriff. Ist es an einen Benutzer gebunden, schaltet das es nur ab, sobald
 * dieser inaktiv, gelöscht oder außerhalb seines Gültigkeitszeitraums ist
 * (bei einem Kundenzugang auch: sein Kunde beendet).
 * Die Schnittstelle meldet niemanden an.
 *
 * Gespeichert wird das Token als sha256 (darüber wird angemeldet) und mit
 * app.key verschlüsselt, damit es sich mit Kennwort noch einmal anzeigen
 * lässt – im Klartext nie.
 */
final class ApiZugang
{
    /** Präfix jedes Tokens – erkennbar in Logs und für Geheimnis-Scanner. */
    public const PRAEFIX = 'mtma_';

    /** 30 Zufallsbytes base64url = 40 Zeichen, 240 Bit. */
    public const MUSTER = '/^mtma_[A-Za-z0-9_-]{40}$/';

    /**
     * Was ein Token darf. Lesen, Vorschlagen und Schreiben sind getrennt: wer
     * einen Plan nur übernehmen soll, braucht keinen Weg zurück, und wer
     * zurückschickt, ändert hier ohne „schreiben“ nur nach Bestätigung.
     *
     * Nicht angeboten: dosen, dosen.schreiben, patchfelder, geraete.* – das
     * hat PowerManagement nicht, und MasterAdmin riefe dann Adressen auf, die
     * es hier nicht gibt. Vorgemerkt, noch nicht angeboten: feststrom.messpunkte,
     * verteiler.typen, verteiler.inventar, planung.
     */
    public const BEFUGNISSE = [
        'plaene.lesen'       => 'Standorte, Etagen, Grundrisse, Maßstab und Georeferenz lesen und übernehmen',
        'plaene.vorschlagen' => 'Geänderte Grundrisse, Maßstab und Georeferenz als Vorschlag zurückschicken – übernommen wird erst nach Bestätigung hier (nur Standorte mit „bearbeiten“; schließt „lesen“ ein)',
        'plaene.schreiben'   => 'Änderungen an Grundrissen, Maßstab und Georeferenz – mit „Feststrom“ auch an Feststromanschlüssen und festen Kabeln – direkt übernehmen, ohne Bestätigung hier (nur Standorte mit „bearbeiten“; schließt „vorschlagen“ ein)',
        'feststrom'          => 'Feststromanschlüsse mit Schutzorganen und Anschlüssen, fest verlegte Kabel und Kabelwege und ihre Steckertypen – ohne Messgeräte (nur zwischen PowerManagement-Instanzen; mit „vorschlagen“ auch als Vorschlag; schließt „lesen“ ein)',
    ];

    /**
     * Wer eine Befugnis vergibt, braucht diese Rechte selbst
     * (BenutzerService::darfVergeben) – sonst gäbe er einer anderen Instanz
     * mehr, als er hier selbst darf.
     */
    public const BEFUGNIS_RECHT = [
        'plaene.lesen'       => ['karte.view'],
        'plaene.vorschlagen' => ['karte.view', 'plans.manage'],
        'plaene.schreiben'   => ['karte.view', 'plans.manage'],
        'feststrom'          => ['anlagen.view'],
    ];

    /**
     * „schreiben“ zusammen mit „Feststrom“ ändert Anlagen und Kabel hier
     * direkt – das vergibt nur, wer beides selbst ändern darf.
     */
    public const SCHREIBEN_FESTSTROM_RECHT = ['anlagen.manage', 'leitungen.manage'];

    /** Seltener schreiben: „zuletzt benutzt“ nur, wenn älter als das (oder neue Adresse). */
    private const ZULETZT_SEKUNDEN = 60;

    /**
     * Das Token zu einem Authorization-Kopf – oder null.
     *
     * Abgelehnt wird ohne Begründung nach außen (immer dieselbe 401): wer
     * rät, soll nicht erfahren, ob ein Token abgelaufen, widerrufen, an einen
     * gesperrten Benutzer gebunden oder nie da war. Das Token kommt nur aus
     * dem Kopf – nie aus der Adresse (?token=) oder dem Körper.
     *
     * @return array<string,mixed>|null die Zeile ohne token_hash/token_enc, mit 'befugnisse' als Liste
     */
    public static function pruefen(string $kopf, string $ip): ?array
    {
        if (preg_match('/^Bearer\s+(\S{1,200})$/i', trim($kopf), $m) !== 1) {
            return null;
        }
        $token = $m[1];
        if (preg_match(self::MUSTER, $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $zeile = Database::first('SELECT * FROM api_tokens WHERE token_hash = ?', [$hash]);
        if ($zeile === null || !hash_equals((string) $zeile['token_hash'], $hash)) {
            return null;
        }
        if ($zeile['revoked_at'] !== null && (string) $zeile['revoked_at'] !== '') {
            return null;
        }
        $bis = Zeit::ts($zeile['expires_at'] ?? null);
        if ($bis !== null && $bis < time()) {
            return null;
        }
        if ($zeile['user_id'] !== null && !self::benutzerGueltig((int) $zeile['user_id'])) {
            return null;
        }

        // Nicht bei jedem Abruf schreiben – eine Liste mit vielen Standorten
        // fragt sonst für jede Plandatei die Datenbank zum Schreiben an.
        $ip = mb_substr($ip, 0, 64);
        $zuletzt = Zeit::ts($zeile['last_used_at'] ?? null);
        if ($zuletzt === null || time() - $zuletzt >= self::ZULETZT_SEKUNDEN || (string) $zeile['last_ip'] !== $ip) {
            Database::update('api_tokens', (int) $zeile['id'], ['last_used_at' => gmdate('c'), 'last_ip' => $ip]);
            $zeile['last_used_at'] = gmdate('c');
            $zeile['last_ip'] = $ip;
        }
        unset($zeile['token_hash'], $zeile['token_enc']);
        $zeile['befugnisse'] = self::befugnisseAus($zeile);
        return $zeile;
    }

    /**
     * Gilt der Benutzer, an den ein Token gebunden ist, gerade? Gelöscht,
     * inaktiv, außerhalb seines Zeitraums oder ein Kundenzugang, dessen
     * Kunde beendet ist: nein – dieselbe Prüfung wie bei jeder Anfrage
     * (Auth::zugangSperre). An Kundenzugänge bindet ApiZugaenge gar nicht.
     */
    public static function benutzerGueltig(int $userId): bool
    {
        $u = Database::first('SELECT * FROM users WHERE id = ?', [$userId]);
        return $u !== null && (int) $u['is_active'] === 1 && Auth::zugangSperre($u) === null;
    }

    /**
     * Nur bekannte Befugnisse, in der Reihenfolge des Katalogs. „schreiben“
     * schließt „vorschlagen“ ein, „vorschlagen“ und „Feststrom“ schließen
     * „lesen“ ein – wer zurückschickt, muss sehen, worauf er aufsetzt.
     *
     * @param array<int,mixed> $liste
     * @return list<string>
     */
    public static function befugnisseOrdnen(array $liste): array
    {
        $liste = array_values(array_filter($liste, 'is_string'));
        if (in_array('plaene.schreiben', $liste, true)) {
            $liste[] = 'plaene.vorschlagen';
        }
        if (in_array('plaene.vorschlagen', $liste, true) || in_array('feststrom', $liste, true)) {
            $liste[] = 'plaene.lesen';
        }
        return array_values(array_intersect(array_keys(self::BEFUGNISSE), $liste));
    }

    /** @return list<string> die Befugnisse einer Zeile aus api_tokens */
    public static function befugnisseAus(array $zeile): array
    {
        $liste = json_decode((string) ($zeile['befugnisse'] ?? '[]'), true);
        return array_values(array_intersect(array_keys(self::BEFUGNISSE), is_array($liste) ? $liste : []));
    }

    public static function darf(array $token, string $befugnis): bool
    {
        return in_array($befugnis, (array) ($token['befugnisse'] ?? []), true);
    }

    /**
     * Die Standorte des Tokens und ob es dort bearbeiten darf.
     *
     * Bearbeiten heißt: die Freigabe sagt „bearbeiten“ **und** das Token darf
     * vorschlagen oder schreiben – sonst meldet die Schnittstelle die Etagen
     * nicht als bearbeitbar, und die andere Seite bietet nichts an.
     * Gelöschte Standorte zählen nicht (JOIN).
     *
     * @return array<int,bool> siteId => bearbeiten
     */
    public static function standorte(array $token): array
    {
        $aendern = self::darf($token, 'plaene.vorschlagen') || self::darf($token, 'plaene.schreiben');
        $out = [];
        foreach (Database::select(
            'SELECT ts.site_id, ts.darf_bearbeiten FROM api_token_standorte ts
             JOIN sites s ON s.id = ts.site_id WHERE ts.token_id = ? ORDER BY s.name, s.id',
            [(int) ($token['id'] ?? 0)]
        ) as $z) {
            $out[(int) $z['site_id']] = $aendern && (int) $z['darf_bearbeiten'] === 1;
        }
        return $out;
    }

    public static function sieht(array $token, int $siteId): bool
    {
        return array_key_exists($siteId, self::standorte($token));
    }

    public static function bearbeitet(array $token, int $siteId): bool
    {
        return self::standorte($token)[$siteId] ?? false;
    }
}
