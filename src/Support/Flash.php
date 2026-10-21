<?php
declare(strict_types=1);

namespace App\Support;

use App\Berichte\Kundenmodus;

final class Flash
{
    /**
     * Eine Meldung für die nächste Seite. Nicht, wenn der Kundenmodus diese
     * Anfrage sperrt: Er begann, während sie ohne Sitzungssperre wartete
     * (Session::ohneSperre() – InfluxDB, Broker, Cloud, git, eine andere
     * Instanz), und die nächste Seite ist die Kundenseite. Dort stünden
     * sonst Adressen, Einheiten und Fehler aus einem anderen Reiter.
     */
    public static function add(string $type, string $message): void
    {
        $request = new Request();
        if (Kundenmodus::sperrt($request->method(), $request->path()) !== null) {
            return;
        }
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
