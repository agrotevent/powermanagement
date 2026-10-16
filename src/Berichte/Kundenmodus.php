<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Support\Session;

/**
 * Kundenmodus: das Tablet des Prüfers liegt beim Kunden.
 *
 * Solange er läuft, sperrt der Router die Sitzung auf genau einen Bericht:
 * Kundenseite, Vorschau-PDF, Kundenunterschrift und Entsperren – sonst nur
 * Abmelden, Anmeldeseite und Manifest. Schalten, Formular, Dashboard und
 * alle anderen Berichte sind zu, auch wenn das Konto sie dürfte.
 *
 * Entsperrt wird mit dem Kennwort des Prüfers (Auth::kennwortBestaetigen).
 * Falsche Versuche zählen nur in der Sitzung – das Konto wird nicht
 * gesperrt, der Prüfer stünde sonst draußen ohne Zugang da. Beim fünften
 * Fehlversuch wird abgemeldet; eine neue Anmeldung schützt die vorhandene
 * Anmelde-Bremse.
 */
final class Kundenmodus
{
    public const SITZUNG = 'kundenmodus';
    public const HOECHSTENS_FEHL = 5;

    /** @return array{bericht:int,seit:int,fehl:int}|null */
    public static function aktiv(): ?array
    {
        $m = Session::get(self::SITZUNG);
        if (!is_array($m) || (int) ($m['bericht'] ?? 0) <= 0) {
            return null;
        }
        return ['bericht' => (int) $m['bericht'], 'seit' => (int) ($m['seit'] ?? 0), 'fehl' => (int) ($m['fehl'] ?? 0)];
    }

    /** Ist dieser Weg im Kundenmodus offen? Nur mit genau der ID des Berichts. */
    public static function erlaubt(string $methode, string $pfad, int $berichtId): bool
    {
        $bericht = '/berichte/' . $berichtId;
        $offen = [
            'GET'  => [$bericht . '/kunde', $bericht . '/pdf', '/login', '/manifest'],
            'POST' => [$bericht . '/kunde/unterschreiben', $bericht . '/kunde/beenden', '/logout'],
        ];
        return in_array($pfad, $offen[strtoupper($methode)] ?? [], true);
    }

    public static function starten(int $berichtId): void
    {
        Session::put(self::SITZUNG, ['bericht' => $berichtId, 'seit' => time(), 'fehl' => 0]);
    }

    public static function beenden(): void
    {
        Session::forget(self::SITZUNG);
    }

    /** Ein falsches Kennwort beim Entsperren – die Zahl der Fehlversuche bisher. */
    public static function fehlversuch(): int
    {
        $m = self::aktiv();
        if ($m === null) {
            return 0;
        }
        $m['fehl']++;
        Session::put(self::SITZUNG, $m);
        return $m['fehl'];
    }
}
