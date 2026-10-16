<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;
use App\Berichte\Kundenmodus;

/** Minimaler Router mit Rechteprüfung pro Route. */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable|array,permission:?string}> */
    private array $routes = [];

    /** Was ein Konto mit vorläufigem Kennwort erreicht. */
    private const OHNE_NEUES_KENNWORT = ['/profil', '/profil/passwort', '/logout', '/login', '/manifest'];

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
                    // Wer JSON erwartet (Autosave eines Berichts, PM.post),
                    // bekommt eine lesbare Antwort statt der Anmeldeseite –
                    // die Eingaben bleiben dann auf dem Gerät.
                    if (self::willJson()) {
                        Response::json(['ok' => false, 'anmelden' => true, 'message' => 'Sitzung abgelaufen – bitte neu anmelden.'], 401);
                        return;
                    }
                    Response::redirect('/login?next=' . rawurlencode($path));
                    return;
                }
                if (!Auth::can($route['permission'])) {
                    Response::forbidden();
                    return;
                }
            }

            // Ein Konto mit vorläufigem Kennwort kommt nur bis zum Profil:
            // erst ein eigenes Kennwort, dann der Rest.
            if (Auth::check() && (int) (Auth::user()['must_change_password'] ?? 0) === 1
                && !in_array($path, self::OHNE_NEUES_KENNWORT, true)) {
                Flash::warning('Bitte zuerst ein neues Passwort vergeben.');
                Response::redirect('/profil');
                return;
            }

            // Kundenmodus: das Gerät liegt beim Kunden. Offen ist nur, was
            // er für diesen einen Bericht braucht – kein Schalten, kein
            // Formular, keine anderen Seiten, auch ohne eigenes Recht der Route.
            $sperre = Auth::check() ? Kundenmodus::aktiv() : null;
            if ($sperre !== null && !Kundenmodus::erlaubt($method, $path, $sperre['bericht'])) {
                if (self::willJson()) {
                    Response::json(['ok' => false, 'kundenmodus' => true, 'message' => 'Kundenmodus aktiv – erst mit dem Kennwort des Prüfers entsperren.'], 423);
                    return;
                }
                Response::redirect('/berichte/' . $sperre['bericht'] . '/kunde');
                return;
            }

            // Das Token nur aus dem Formular – nie aus der Adresse, wo es in
            // Protokollen, im Verlauf und im Referer landen würde.
            $token = $_POST['_token'] ?? null;
            if ($method === 'POST' && !Csrf::check(is_string($token) ? $token : null)) {
                if (self::willJson()) {
                    Response::json(['ok' => false, 'token' => true, 'message' => 'Sicherheitstoken abgelaufen – Seite neu laden.'], 419);
                    return;
                }
                Flash::error('Sicherheitstoken ungültig oder abgelaufen. Bitte erneut versuchen.');
                Response::redirect(Response::zurueckZiel('/'));
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

    /**
     * Erwartet die Anfrage JSON? Nur der Accept-Kopf zählt – nicht
     * X-Requested-With: damit schickt `data-laeuft` in app.js Formulare ab,
     * und die sollen weiter wie bisher mit Meldung und Weiterleitung enden.
     */
    private static function willJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
