<?php
declare(strict_types=1);

namespace App\Support;

final class Flash
{
    public static function add(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $m): void { self::add('success', $m); }
    public static function error(string $m): void   { self::add('error', $m); }
    public static function warning(string $m): void { self::add('warning', $m); }
    public static function info(string $m): void    { self::add('info', $m); }

    /** @return array<int,array{type:string,message:string}> */
    public static function pull(): array
    {
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $items;
    }
}
