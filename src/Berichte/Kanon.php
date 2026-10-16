<?php
declare(strict_types=1);

namespace App\Berichte;

/**
 * Eine feste Schreibweise für JSON – Grundlage jedes Hashes eines Berichts.
 *
 * Objekte werden nach Schlüsseln sortiert (als Text), Listen behalten ihre
 * Reihenfolge, Unicode und Schrägstriche bleiben, wie sie sind. Gleitkomma
 * ist verboten: 0.1 sähe je nach PHP-Fassung und `serialize_precision`
 * anders aus, und der Hash eines unterschriebenen Berichts hinge daran.
 * Zahlen stehen im Bericht deshalb als Dezimaltext („0.42“).
 */
final class Kanon
{
    public static function json(mixed $wert): string
    {
        return json_encode(self::ordnen($wert), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function hash(mixed $wert): string
    {
        return hash('sha256', self::json($wert));
    }

    private static function ordnen(mixed $wert, int $tiefe = 0): mixed
    {
        if ($tiefe > 32) {
            throw new \InvalidArgumentException('Zu tief verschachtelt.');
        }
        if (is_float($wert)) {
            throw new \InvalidArgumentException('Gleitkommazahl im Bericht – Zahlen gehören als Dezimaltext hinein.');
        }
        if (is_object($wert)) {
            throw new \InvalidArgumentException('Objekte gehören als Array in den Bericht.');
        }
        if (!is_array($wert)) {
            return $wert;
        }
        if (array_is_list($wert)) {
            return array_map(static fn (mixed $w): mixed => self::ordnen($w, $tiefe + 1), $wert);
        }
        $out = [];
        foreach ($wert as $k => $w) {
            $out[(string) $k] = self::ordnen($w, $tiefe + 1);
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
