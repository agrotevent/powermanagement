<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Einmal-Marken gegen doppeltes Absenden eines Formulars.
 *
 * Ein Formular, dessen Antwort ein Geheimnis genau einmal zeigt (neues
 * Token, Einrichtungslink, vorläufiges Kennwort), trägt eine Marke aus der
 * Sitzung. Beim Absenden wird sie eingelöst – ein zweites Absenden (Neuladen,
 * Doppelklick) findet sie nicht mehr und legt nichts ein zweites Mal an.
 * Je Zweck höchstens zehn offene Marken (mehrere Reiter).
 */
final class EinmalMarke
{
    private const OFFEN = 10;

    /** Eine neue Marke für ein Formular dieses Zwecks. */
    public static function ausgeben(string $zweck): string
    {
        $marke = bin2hex(random_bytes(16));
        $liste = self::liste($zweck);
        $liste[] = $marke;
        Session::put(self::schluessel($zweck), array_slice($liste, -self::OFFEN));
        return $marke;
    }

    /** Die Marke einlösen – gilt genau einmal. */
    public static function einloesen(string $zweck, string $marke): bool
    {
        $liste = self::liste($zweck);
        foreach ($liste as $i => $offen) {
            if ($marke !== '' && hash_equals($offen, $marke)) {
                unset($liste[$i]);
                Session::put(self::schluessel($zweck), array_values($liste));
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function liste(string $zweck): array
    {
        return array_values(array_filter((array) Session::get(self::schluessel($zweck), []), 'is_string'));
    }

    private static function schluessel(string $zweck): string
    {
        return $zweck . '_einmal';
    }
}
