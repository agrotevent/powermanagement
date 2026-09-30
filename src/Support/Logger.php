<?php
declare(strict_types=1);

namespace App\Support;

final class Logger
{
    private static ?string $file = null;

    public static function setFile(string $file): void
    {
        self::$file = $file;
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $file = self::$file ?? dirname(__DIR__, 2) . '/storage/logs/app.log';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            // Eine Zeile je Eintrag: Umbrüche aus Fehlermeldungen von Geräten,
            // Clouds oder git fälschten sonst weitere Einträge.
            str_replace(["\r", "\n"], ['\\r', '\\n'], $message),
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void  { self::write('info', $m, $c); }
    public static function error(string $m, array $c = []): void { self::write('error', $m, $c); }
    public static function debug(string $m, array $c = []): void
    {
        if (Config::get('app.debug')) {
            self::write('debug', $m, $c);
        }
    }
}
