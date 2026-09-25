<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Der Auswertungszeitraum aus der Anfrage – Vorgabe (1h, 6h, heute, 24h,
 * 7d, 30d) oder frei (von/bis in Ortszeit). Für Statistik und Bereiche
 * gleich.
 */
final class Zeitraum
{
    /** @return array{0:int,1:int,2:string} [von, bis, Vorgabe] */
    public static function ausAnfrage(Request $request): array
    {
        $vorgabe = $request->str('zeitraum', '24h');
        $jetzt = time();
        $von = Zeit::ts(Zeit::ausEingabe($request->str('von')));
        $bis = Zeit::ts(Zeit::ausEingabe($request->str('bis'), true));
        if ($vorgabe === 'frei' && $von !== null) {
            return [$von, $bis ?? $jetzt, 'frei'];
        }
        $heute = (new \DateTimeImmutable('today', Zeit::zone()))->getTimestamp();
        return match ($vorgabe) {
            '1h'    => [$jetzt - 3600, $jetzt, '1h'],
            '6h'    => [$jetzt - 6 * 3600, $jetzt, '6h'],
            'heute' => [$heute, $jetzt, 'heute'],
            '7d'    => [$jetzt - 7 * 86400, $jetzt, '7d'],
            '30d'   => [$jetzt - 30 * 86400, $jetzt, '30d'],
            default => [$jetzt - 86400, $jetzt, '24h'],
        };
    }
}
