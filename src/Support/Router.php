<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;

/** Minimaler Router mit Rechteprüfung pro Route. */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable|array,permission:?string}> */
    private array $routes = [];

    public function get(string $path, array|callable $handler, ?string $permission = null): void
    {
        $this->add('GET', $path, $handler, $permission);
    }

    public function post(string $path, array|callable $handler, ?string $permission = null): void
    {
        $this->add('POST', $path, $handler, $permission);
    }

    private function add(string $method, string $path, array|callable $handler, ?string $permission): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->compile($path),
            'handler'    => $handler,
            'permission' => $permission,
        ];
    }

    private function compile(string $path): string
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', '/' . trim($path, '/'));
        return '#^' . $pattern . '$#';
    }

    public function dispatch(Request $request): void
    {
        $path   = $request->path();
        $method = $request->method();
        $methodMismatch = false;

        // HEAD ist GET ohne Inhalt (RFC 9110). Darauf prüfen Reverse-Proxys
        // und Überwachungen die Erreichbarkeit – ohne diese Zeile antwortet
        // MasterAdmin ihnen mit 404, während der Browser die Seite anzeigt.
        // Den Rumpf verwirft der Webserver selbst.
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $methodMismatch = true;
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($route['permission'] !== null) {
                if (!Auth::check()) {
                    Response::redirect('/login?next=' . rawurlencode($path));
                    return;
                }
                if (!Auth::can($route['permission'])) {
                    Response::forbidden();
                    return;
                }
            }

            if ($method === 'POST' && !Csrf::check($request->str('_token'))) {
                Flash::error('Sicherheitstoken ungültig oder abgelaufen. Bitte erneut versuchen.');
                Response::redirect($_SERVER['HTTP_REFERER'] ?? '/');
                return;
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->$action($request, $params);
                return;
            }
            $handler($request, $params);
            return;
        }

        Response::notFound($methodMismatch ? 'Methode nicht erlaubt.' : 'Seite nicht gefunden.');
    }
}
