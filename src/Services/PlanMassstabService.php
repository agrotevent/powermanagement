<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Support\Audit;
use App\Support\Database;

/**
 * Der Maßstab eines Grundrisses.
 *
 * Zwei Referenzpunkte, direkt in der Karte gesetzt, und die echte Strecke
 * dazwischen in Metern. Daraus ergibt sich die Länge jedes eingezeichneten
 * Kabelzugs – und ob das Kabel aus dem Bestand dafür reicht.
 *
 * Punkte stehen in Prozent des Plans, wie überall im Grundriss. Prozent der
 * Breite und Prozent der Höhe sind aber verschieden lang, sobald der Plan
 * nicht quadratisch ist. Gerechnet wird deshalb in Breiten-Einheiten: x
 * bleibt, wie es ist, y wird mit dem Seitenverhältnis (Höhe durch Breite)
 * gestreckt. Das Verhältnis kommt beim Setzen aus dem Browser, der den Plan
 * gerade anzeigt – bei einem PDF kennt nur er die Seitengröße.
 */
final class PlanMassstabService
{
    /** Kürzer als das (in Prozent der Breite) ist keine brauchbare Referenz. */
    public const MINDESTABSTAND = 0.5;

    /**
     * Der Maßstab einer Etage – oder null, wenn keiner gesetzt ist.
     *
     * @param array<string,mixed> $floor Zeile aus floors
     * @return array{a:array{x:float,y:float},b:array{x:float,y:float},meter:float,verhaeltnis:float,von:string,am:string}|null
     */
    public static function lesen(array $floor): ?array
    {
        foreach (['massstab_ax', 'massstab_ay', 'massstab_bx', 'massstab_by', 'massstab_m', 'massstab_verhaeltnis'] as $feld) {
            if (!isset($floor[$feld]) || $floor[$feld] === null || $floor[$feld] === '') {
                return null;
            }
        }
        if ((float) $floor['massstab_m'] <= 0 || (float) $floor['massstab_verhaeltnis'] <= 0) {
            return null;
        }
        return [
            'a'           => ['x' => (float) $floor['massstab_ax'], 'y' => (float) $floor['massstab_ay']],
            'b'           => ['x' => (float) $floor['massstab_bx'], 'y' => (float) $floor['massstab_by']],
            'meter'       => (float) $floor['massstab_m'],
            'verhaeltnis' => (float) $floor['massstab_verhaeltnis'],
            'von'         => (string) ($floor['massstab_von'] ?? ''),
            'am'          => (string) ($floor['massstab_am'] ?? ''),
        ];
    }

    public static function fuerEtage(int $floorId): ?array
    {
        $floor = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
        return $floor === null ? null : self::lesen($floor);
    }

    /**
     * Eine eingegebene Länge lesen: „12,5", „12.5 m", „1250 cm".
     */
    public static function meterAusText(string $text): ?float
    {
        $text = strtolower(trim(str_replace(',', '.', $text)));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(m|cm|mm)?$/', $text, $treffer) !== 1) {
            return null;
        }
        $wert = (float) $treffer[1];
        return match ($treffer[2] ?? 'm') {
            'cm'    => $wert / 100,
            'mm'    => $wert / 1000,
            default => $wert,
        };
    }

    /**
     * Den Maßstab setzen oder ersetzen.
     *
     * @param array{x:mixed,y:mixed} $a
     * @param array{x:mixed,y:mixed} $b
     * @return array{ok:bool,message:string,massstab:?array}
     */
    public static function speichern(int $floorId, array $a, array $b, float $meter, float $verhaeltnis): array
    {
        $floor = Database::first('SELECT * FROM floors WHERE id = ?', [$floorId]);
        if ($floor === null) {
            return ['ok' => false, 'message' => 'Etage nicht gefunden.', 'massstab' => null];
        }
        $punkte = [];
        foreach ([$a, $b] as $p) {
            $x = (float) ($p['x'] ?? -1);
            $y = (float) ($p['y'] ?? -1);
            if ($x < 0 || $x > 100 || $y < 0 || $y > 100) {
                return ['ok' => false, 'message' => 'Die Referenzpunkte müssen auf dem Plan liegen.', 'massstab' => null];
            }
            $punkte[] = ['x' => $x, 'y' => $y];
        }
        if (!($verhaeltnis > 0.01 && $verhaeltnis < 100)) {
            return ['ok' => false, 'message' => 'Das Seitenverhältnis des Plans ist unbekannt – bitte die Seite neu laden.', 'massstab' => null];
        }
        if (self::abstand($punkte[0], $punkte[1], $verhaeltnis) < self::MINDESTABSTAND) {
            return ['ok' => false, 'message' => 'Die beiden Punkte liegen zu nah beieinander. Je weiter auseinander, desto genauer der Maßstab.', 'massstab' => null];
        }
        if (!($meter > 0 && $meter <= 100000)) {
            return ['ok' => false, 'message' => 'Bitte die echte Strecke zwischen den Punkten in Metern angeben.', 'massstab' => null];
        }

        Database::update('floors', $floorId, [
            'massstab_ax'          => round($punkte[0]['x'], 4),
            'massstab_ay'          => round($punkte[0]['y'], 4),
            'massstab_bx'          => round($punkte[1]['x'], 4),
            'massstab_by'          => round($punkte[1]['y'], 4),
            'massstab_m'           => round($meter, 3),
            'massstab_verhaeltnis' => round($verhaeltnis, 6),
            'massstab_von'         => (string) (Auth::user()['username'] ?? 'system'),
            'massstab_am'          => gmdate('c'),
        ]);
        Audit::log('plan.massstab', 'floor', (string) $floorId, ['meter' => $meter]);

        return [
            'ok'       => true,
            'message'  => 'Maßstab gesetzt: ' . self::text($meter) . ' zwischen den beiden Punkten. Kabellängen werden jetzt angezeigt.',
            'massstab' => self::fuerEtage($floorId),
        ];
    }

    /**
     * Einen Maßstab aus dem Inventar-Abgleich übernehmen – oder entfernen,
     * wenn der Abgleich ausdrücklich keinen mitbringt.
     *
     * @param array<string,mixed>|null $referenz wie aus lesen()
     */
    public static function uebernehmen(int $floorId, ?array $referenz): void
    {
        if ($referenz === null) {
            if (self::fuerEtage($floorId) !== null) {
                self::entfernen($floorId);
            }
            return;
        }
        $a = (array) ($referenz['a'] ?? []);
        $b = (array) ($referenz['b'] ?? []);
        $meter = (float) ($referenz['meter'] ?? 0);
        $verhaeltnis = (float) ($referenz['verhaeltnis'] ?? 0);
        if ($meter <= 0 || $verhaeltnis <= 0 || !isset($a['x'], $a['y'], $b['x'], $b['y'])) {
            return;
        }
        Database::update('floors', $floorId, [
            'massstab_ax' => (float) $a['x'], 'massstab_ay' => (float) $a['y'],
            'massstab_bx' => (float) $b['x'], 'massstab_by' => (float) $b['y'],
            'massstab_m' => $meter, 'massstab_verhaeltnis' => $verhaeltnis,
            'massstab_von' => mb_substr((string) ($referenz['von'] ?? ''), 0, 190),
            'massstab_am' => (string) ($referenz['am'] ?? '') !== '' ? (string) $referenz['am'] : null,
        ]);
    }

    public static function entfernen(int $floorId): void
    {
        Database::update('floors', $floorId, [
            'massstab_ax' => null, 'massstab_ay' => null, 'massstab_bx' => null, 'massstab_by' => null,
            'massstab_m' => null, 'massstab_verhaeltnis' => null, 'massstab_von' => '', 'massstab_am' => null,
        ]);
        Audit::log('plan.massstab_entfernt', 'floor', (string) $floorId);
    }

    /**
     * Abstand zweier Punkte in Breiten-Einheiten (Prozent der Planbreite).
     *
     * @param array{x:float,y:float} $a
     * @param array{x:float,y:float} $b
     */
    public static function abstand(array $a, array $b, float $verhaeltnis): float
    {
        $dx = (float) $b['x'] - (float) $a['x'];
        $dy = ((float) $b['y'] - (float) $a['y']) * $verhaeltnis;
        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Die Länge eines Linienzugs in Metern – null ohne Maßstab.
     *
     * @param array<int,array{0:float,1:float}|array{x:float,y:float}> $punkte
     */
    public static function meter(array $punkte, ?array $massstab): ?float
    {
        if ($massstab === null || count($punkte) < 2) {
            return null;
        }
        $referenz = self::abstand($massstab['a'], $massstab['b'], $massstab['verhaeltnis']);
        if ($referenz <= 0) {
            return null;
        }
        $summe = 0.0;
        $vorher = null;
        foreach ($punkte as $punkt) {
            $p = isset($punkt['x']) ? ['x' => (float) $punkt['x'], 'y' => (float) $punkt['y']]
                : ['x' => (float) $punkt[0], 'y' => (float) $punkt[1]];
            if ($vorher !== null) {
                $summe += self::abstand($vorher, $p, $massstab['verhaeltnis']);
            }
            $vorher = $p;
        }
        return $summe / $referenz * $massstab['meter'];
    }

    /** 23,4 m – eine Nachkommastelle genügt für einen Kabelweg. */
    public static function text(float $meter): string
    {
        return number_format($meter, $meter < 10 ? 2 : 1, ',', '.') . ' m';
    }
}
