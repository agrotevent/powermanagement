<?php
declare(strict_types=1);

namespace App\Support;

final class Format
{
    public static function dateTime(?string $iso): string
    {
        if (!$iso) {
            return '–';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
            return $dt->setTimezone(new \DateTimeZone((string) Config::get('app.timezone', 'UTC')))->format('d.m.Y H:i');
        } catch (\Throwable) {
            return (string) $iso;
        }
    }

    public static function ago(?string $iso): string
    {
        if (!$iso) {
            return 'nie';
        }
        try {
            $diff = time() - (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Throwable) {
            return '–';
        }
        if ($diff < 60)    return 'gerade eben';
        if ($diff < 3600)  return intdiv($diff, 60) . ' Min.';
        if ($diff < 86400) return intdiv($diff, 3600) . ' Std.';
        return intdiv($diff, 86400) . ' Tage';
    }

    public static function duration(?string $start, ?string $end): string
    {
        if (!$start) {
            return '–';
        }
        try {
            $s = (new \DateTimeImmutable($start))->getTimestamp();
            $e = $end ? (new \DateTimeImmutable($end))->getTimestamp() : time();
        } catch (\Throwable) {
            return '–';
        }
        $d = max(0, $e - $s);
        return $d < 60 ? $d . ' s' : sprintf('%d:%02d min', intdiv($d, 60), $d % 60);
    }

    /** "1,3,5-8,10" -> [1,3,5,6,7,8,10] – für VLAN-Listen. */
    public static function parseIdList(string $input): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($input)) ?: [] as $chunk) {
            if ($chunk === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $chunk, $m)) {
                $from = (int) $m[1];
                $to   = (int) $m[2];
                if ($from <= $to && ($to - $from) <= 4094) {
                    for ($i = $from; $i <= $to; $i++) {
                        $out[] = $i;
                    }
                }
            } elseif (ctype_digit($chunk)) {
                $out[] = (int) $chunk;
            }
        }
        $out = array_values(array_unique(array_filter($out, static fn ($v) => $v >= 1 && $v <= 4094)));
        sort($out);
        return $out;
    }

    public static function idList(array $ids): string
    {
        return implode(',', $ids);
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}
