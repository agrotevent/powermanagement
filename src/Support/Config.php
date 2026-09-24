<?php
declare(strict_types=1);

namespace App\Support;

/** Zugriff auf config/config.php mit Punkt-Notation. */
final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Konfiguration fehlt: {$file} (config.example.php kopieren)");
        }
        self::$data = require $file;
    }

    public static function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $ref = &self::$data;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function all(): array
    {
        return self::$data;
    }

    public static function isDemo(): bool
    {
        return (bool) self::get('app.demo', false);
    }
}
