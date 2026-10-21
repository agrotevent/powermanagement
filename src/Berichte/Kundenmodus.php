<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Auth\Auth;
use App\Support\Config;
use App\Support\Database;
use App\Support\Session;

/**
 * Kundenmodus: das Tablet des Prüfers liegt beim Kunden.
 *
 * Solange er läuft, sperrt der Router die Sitzung auf genau einen Bericht:
 * Kundenseite, Vorschau-PDF, Kundenunterschrift und Entsperren – sonst nur
 * das Manifest. Schalten, Formular, Dashboard, alle anderen Berichte, auch
 * Fehlerseiten und Abmelden sind zu, auch wenn das Konto sie dürfte.
 *
 * Die Sperre hängt zusätzlich am Gerät: ein HttpOnly-Cookie mit einem
 * Zufallswert, dessen Hash in kundenmodus_geraete steht. Endet die Sitzung
 * (Ablauf, Inaktivität) und meldet sich jemand in diesem Browser wieder mit
 * dem Konto an – etwa mit dem im Browser gespeicherten Kennwort –, gilt die
 * Sperre gleich wieder. Auf einem anderen Gerät gilt sie nicht.
 *
 * Entsperrt wird mit dem Kennwort dessen, der den Kundenmodus gestartet hat
 * (Auth::kennwortBestaetigen). Falsche Versuche zählen nur in der Sitzung –
 * das Konto wird nicht gesperrt, der Prüfer stünde sonst draußen ohne
 * Zugang da. Ab dem fünften Fehlversuch wird das Entsperren gebremst (eine
 * Minute, dann jeweils doppelt so lange, höchstens 15 Minuten); abgemeldet
 * wird nicht – sonst stünde dem Kunden die Anmeldeseite offen.
 */
final class Kundenmodus
{
    public const SITZUNG = 'kundenmodus';
    public const COOKIE = 'pm_kundenmodus';
    public const HOECHSTENS_FEHL = 5;

    private const WARTEN_AB = 60;
    private const WARTEN_HOECHSTENS = 900;
    /** So lange gilt eine Sperre am Gerät, wenn niemand entsperrt. */
    private const GERAET_TAGE = 7;

    /** @return array{bericht:int,seit:int,fehl:int,bis:int,user:int,name:string,geraet:int}|null */
    public static function aktiv(): ?array
    {
        $m = Session::get(self::SITZUNG);
        if (is_array($m) && (int) ($m['bericht'] ?? 0) > 0) {
            return self::form($m);
        }
        // Keine Sperre in der Sitzung – vielleicht aber am Gerät (neu angemeldet).
        $z = self::geraet();
        if ($z === null) {
            return null;
        }
        $user = Auth::user() ?? [];
        $m = ['bericht' => (int) $z['bericht_id'], 'seit' => time(), 'fehl' => 0, 'bis' => 0, 'user' => (int) $z['user_id'],
            'name' => trim((string) ($user['full_name'] ?? '')), 'geraet' => (int) $z['id']];
        Session::put(self::SITZUNG, $m);
        return self::form($m);
    }

    /** Ist dieser Weg im Kundenmodus offen? Nur mit genau der ID des Berichts. */
    public static function erlaubt(string $methode, string $pfad, int $berichtId): bool
    {
        $bericht = '/berichte/' . $berichtId;
        $offen = [
            'GET'  => [$bericht . '/kunde', $bericht . '/pdf', '/manifest'],
            'POST' => [$bericht . '/kunde/unterschreiben', $bericht . '/kunde/beenden'],
        ];
        return in_array($pfad, $offen[strtoupper($methode)] ?? [], true);
    }

    /** Sperren – in der Sitzung und am Gerät (Cookie). */
    public static function starten(int $berichtId): void
    {
        $user = Auth::user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        self::geraetLoeschen();
        $token = bin2hex(random_bytes(32));
        $geraet = Database::insert('kundenmodus_geraete', [
            'token_hash' => hash('sha256', $token),
            'bericht_id' => $berichtId,
            'user_id'    => $userId,
            'seit'       => gmdate('c'),
        ]);
        self::cookie($token, time() + self::GERAET_TAGE * 86400);
        Session::put(self::SITZUNG, ['bericht' => $berichtId, 'seit' => time(), 'fehl' => 0, 'bis' => 0, 'user' => $userId,
            'name' => trim((string) ($user['full_name'] ?? '')), 'geraet' => $geraet]);
    }

    /** Entsperren – Sitzung und Gerät. */
    public static function beenden(): void
    {
        $m = Session::get(self::SITZUNG);
        if (is_array($m) && (int) ($m['geraet'] ?? 0) > 0) {
            Database::execute('DELETE FROM kundenmodus_geraete WHERE id = ?', [(int) $m['geraet']]);
        }
        self::geraetLoeschen();
        Session::forget(self::SITZUNG);
    }

    /**
     * Ein falsches Kennwort beim Entsperren – die Zahl der Fehlversuche
     * bisher. Ab HOECHSTENS_FEHL beginnt die Wartezeit.
     */
    public static function fehlversuch(): int
    {
        $m = self::aktiv();
        if ($m === null) {
            return 0;
        }
        $m['fehl']++;
        if ($m['fehl'] >= self::HOECHSTENS_FEHL) {
            $m['bis'] = time() + min(self::WARTEN_HOECHSTENS, self::WARTEN_AB * 2 ** min(10, $m['fehl'] - self::HOECHSTENS_FEHL));
        }
        Session::put(self::SITZUNG, $m);
        return $m['fehl'];
    }

    /** Sekunden, bis wieder ein Kennwort angenommen wird (0 = jetzt). */
    public static function wartezeit(): int
    {
        $m = self::aktiv();
        return $m === null ? 0 : max(0, $m['bis'] - time());
    }

    // ------------------------------------------------------------ intern

    private static function form(array $m): array
    {
        return ['bericht' => (int) $m['bericht'], 'seit' => (int) ($m['seit'] ?? 0), 'fehl' => (int) ($m['fehl'] ?? 0),
            'bis' => (int) ($m['bis'] ?? 0), 'user' => (int) ($m['user'] ?? 0), 'name' => (string) ($m['name'] ?? ''),
            'geraet' => (int) ($m['geraet'] ?? 0)];
    }

    /**
     * Die Sperre an diesem Gerät für das angemeldete Konto – nur, wenn der
     * Bericht noch da ist und die Sperre nicht älter als GERAET_TAGE.
     *
     * @return array{id:int|string,bericht_id:int|string,user_id:int|string}|null
     */
    private static function geraet(): ?array
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        $userId = Auth::id();
        if ($userId === null || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $z = Database::first(
            'SELECT g.id, g.bericht_id, g.user_id FROM kundenmodus_geraete g JOIN berichte b ON b.id = g.bericht_id
             WHERE g.token_hash = ? AND g.user_id = ? AND g.seit > ?',
            [hash('sha256', $token), $userId, gmdate('c', time() - self::GERAET_TAGE * 86400)]
        );
        if ($z === null && Database::scalar('SELECT id FROM kundenmodus_geraete WHERE token_hash = ?', [hash('sha256', $token)]) === false) {
            // Entsperrt oder abgelaufen: das Cookie hat ausgedient.
            self::cookie('', time() - 42000);
        }
        return $z;
    }

    /** Die Sperre, auf die das Cookie dieses Geräts zeigt, und das Cookie selbst entfernen. */
    private static function geraetLoeschen(): void
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
            Database::execute('DELETE FROM kundenmodus_geraete WHERE token_hash = ?', [hash('sha256', $token)]);
        }
        if (isset($_COOKIE[self::COOKIE])) {
            self::cookie('', time() - 42000);
            unset($_COOKIE[self::COOKIE]);
        }
    }

    private static function cookie(string $wert, int $ablauf): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $sicher = Config::get('security.cookie_secure');
        setcookie(self::COOKIE, $wert, [
            'expires'  => $ablauf,
            'path'     => '/',
            'secure'   => $sicher === null ? Session::isHttps() : (bool) $sicher,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
