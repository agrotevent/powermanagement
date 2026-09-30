<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key);
        return in_array($v, ['1', 'on', 'true', 'yes', 1, true], true);
    }

    /** @return array<int,string> */
    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        if (!is_array($v)) {
            return $v === null || $v === '' ? [] : [(string) $v];
        }
        return array_values(array_map(static fn ($x) => (string) $x, $v));
    }

    public function ip(): string
    {
        $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('security.trusted_proxies', []);
        if (in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Von rechts lesen: der Proxy hängt an, was er gesehen hat. Links
            // steht, was der Aufrufer selbst mitgeschickt hat – das zählt nicht.
            $teile = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($teile as $adresse) {
                if (filter_var($adresse, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                if (!in_array($adresse, $trusted, true)) {
                    return $adresse;
                }
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }
}
