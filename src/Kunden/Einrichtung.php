<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Einmal-Link zum Festlegen des Kennworts eines Kundenzugangs.
 *
 * Es gibt keinen Mailversand: der Link steht genau einmal in der Antwort, mit
 * der das Personal ihn erzeugt (KundenController), und wird von dort
 * weitergegeben. Gespeichert wird nur ein HMAC des Tokens (Schlüssel aus
 * app.key abgeleitet) mit seinem Ablauf – nie das Token, nie der Link. Wer
 * die Datenbank liest, kann damit nichts einlösen.
 *
 * Ein Link gilt einmal und höchstens kundenbereich.einrichtung_stunden
 * (Vorgabe 72); ein neuer macht den alten ungültig. Eingelöst wird er nur für
 * einen aktiven Kundenzugang eines aktiven Kunden. Danach meldet sich der
 * Kunde selbst an – kein Auto-Login.
 *
 * Die Adresse kommt nur aus app.url, nie aus dem Host-Kopf der Anfrage (sonst
 * könnte ein manipulierter Kopf den Link auf eine fremde Seite lenken).
 */
final class Einrichtung
{
    public const STUNDEN = 72;
    public const PFAD = '/kundenbereich/einrichten/';

    /** Ein Text für jeden Fall, in dem ein Link nicht (mehr) taugt. */
    public const UNGUELTIG = 'Dieser Link ist ungültig, abgelaufen oder schon benutzt.';

    /**
     * Einen neuen Link für dieses Konto: 32 Zufallsbytes als base64url (43
     * Zeichen). Gespeichert werden nur hash() und Ablauf; ein vorher
     * erzeugter Link gilt damit nicht mehr.
     *
     * @return array{token:string,bis:string}
     */
    public static function erzeugen(int $userId): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $bis = Zeit::iso(time() + 3600 * KundenEinstellungen::einrichtungStunden());
        Database::update('users', $userId, ['einrichtung_hash' => self::hash($token), 'einrichtung_bis' => $bis, 'updated_at' => gmdate('c')]);
        return ['token' => $token, 'bis' => $bis];
    }

    /**
     * Das Konto zu einem Link – oder null: falsches Format (ohne Abfrage),
     * unbekannt, abgelaufen, kein Kundenzugang, Konto deaktiviert, Konto nur
     * über SSO (nur_sso: dort gälte ein Kennwort nie), Konto außerhalb seiner
     * Zeit oder Kunde nicht aktiv.
     *
     * @return array<string,mixed>|null
     */
    public static function finden(string $token, ?int $jetzt = null): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $konto = Database::first('SELECT * FROM users WHERE einrichtung_hash = ?', [self::hash($token)]);
        if ($konto === null || !Kundenbereich::istKundenzugang($konto) || (int) $konto['is_active'] !== 1 || (int) ($konto['nur_sso'] ?? 0) === 1) {
            return null;
        }
        $bis = Zeit::ts($konto['einrichtung_bis'] ?? null);
        if ($bis === null || ($jetzt ?? time()) > $bis || Auth::zugangSperre($konto, $jetzt) !== null) {
            return null;
        }
        return $konto;
    }

    /**
     * Den Link einlösen: Kennwort festlegen (Regeln wie überall), Link weg,
     * kein vorläufiges Kennwort mehr, Sperre nach Fehlversuchen aufgehoben.
     * Gilt genau einmal – auch wenn zwei Anfragen gleichzeitig kommen
     * (die Änderung setzt den unveränderten Hash voraus). Ein Fehler beim
     * Kennwort lässt den Link gültig.
     *
     * @return array{ok:bool,message:string,username?:string}
     */
    public static function einloesen(string $token, string $kennwort, string $wiederholung): array
    {
        $konto = self::finden($token);
        if ($konto === null) {
            return ['ok' => false, 'message' => self::UNGUELTIG];
        }
        if ($kennwort !== $wiederholung) {
            return ['ok' => false, 'message' => 'Die beiden Kennwörter stimmen nicht überein.'];
        }
        $fehler = Auth::validatePassword($kennwort);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => str_replace('Passwort', 'Kennwort', $fehler)];
        }
        $geaendert = Database::execute(
            'UPDATE users SET password_hash = ?, einrichtung_hash = NULL, einrichtung_bis = NULL, must_change_password = 0,
                failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ? AND einrichtung_hash = ?',
            [password_hash($kennwort, PASSWORD_DEFAULT), gmdate('c'), (int) $konto['id'], self::hash($token)]
        );
        if ($geaendert !== 1) {
            return ['ok' => false, 'message' => self::UNGUELTIG];
        }
        // Niemand ist angemeldet: im Audit steht, über welchen Zugang es ging.
        Audit::akteur('einrichtung:' . (string) $konto['username']);
        try {
            Audit::log('kunden.zugang_eingerichtet', 'user', (string) $konto['id'], ['user_id' => (int) $konto['id']]);
        } finally {
            Audit::akteur(null);
        }
        return ['ok' => true, 'message' => 'Kennwort festgelegt – bitte jetzt anmelden.', 'username' => (string) $konto['username']];
    }

    /** Der vollständige Link aus app.url – oder null, wenn app.url fehlt oder nicht taugt. */
    public static function link(string $token): ?string
    {
        $adresse = KundenEinstellungen::adresse();
        return $adresse === null ? null : $adresse . self::pfad($token);
    }

    /** Der Pfad des Links (ohne Adresse) – für die Anzeige, wenn app.url fehlt. */
    public static function pfad(string $token): string
    {
        return self::PFAD . $token;
    }

    /** HMAC-SHA256 des Tokens mit einem eigenen, aus app.key abgeleiteten Schlüssel. */
    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, Crypto::ableiten('kunden-einrichtung'));
    }

    /**
     * Für die Wartung: abgelaufene Links unbrauchbar machen (der HMAC fliegt
     * raus). Der Ablauf bleibt stehen – an ihm sieht die Kundenverwaltung,
     * dass ein Zugang nie eingerichtet wurde („Link abgelaufen“).
     */
    public static function aufraeumen(?int $jetzt = null): int
    {
        return Database::execute(
            'UPDATE users SET einrichtung_hash = NULL WHERE einrichtung_hash IS NOT NULL AND einrichtung_bis < ?',
            [Zeit::iso($jetzt ?? time())]
        );
    }
}
