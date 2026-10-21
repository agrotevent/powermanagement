<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;
use App\Berichte\Kundenmodus;
use App\Kunden\Kundenbereich;

/** Minimaler Router mit Rechteprüfung pro Route. */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable|array,permission:?string}> */
    private array $routes = [];

    /** Was ein Konto mit vorläufigem Kennwort erreicht (Kundenzugänge: nur ihr Konto im Kundenbereich). */
    private const OHNE_NEUES_KENNWORT = ['/profil', '/profil/passwort', '/logout', '/login', '/manifest',
                                         Kundenbereich::KONTO, Kundenbereich::KONTO . '/kennwort'];

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

        // Kundenmodus: das Gerät liegt beim Kunden. Offen ist nur, was er
        // für diesen einen Bericht braucht – kein Schalten, kein Formular,
        // keine anderen Seiten, kein Abmelden. Geprüft vor allem anderen:
        // auch eine unbekannte Adresse, eine verbotene Seite oder eine
        // falsche Methode führt zurück zur Kundenseite statt auf eine
        // Fehlerseite (die früher die ganze Navigation samt „Abmelden“ zeigte).
        // Beginnt er erst, während eine Anfrage ohne Sitzungssperre wartet
        // (InfluxDB, git, Geräte), prüft Controller::kundenmodusSperrt() vor
        // der Seite oder dem Download noch einmal, und Flash::add() schreibt
        // keine Meldung mehr, die dann auf der Kundenseite stünde.
        //
        // Kundenzugänge (Kundenbereich, eigene Anmeldung des Kunden) haben
        // keinen Kundenmodus – starten kann ihn nur, wer Berichte ausfüllt,
        // und Kundenmodus::aktiv() ist für sie immer null. Eine Sperre, die
        // aus einer abgelaufenen Sitzung des Prüfers stammt, gilt für sie
        // also nicht (sie schickte sie sonst im Kreis: Kundenmodus → Bericht,
        // Tor → Kundenbereich) – ebenso wenig die Sperre am Gerät.
        $angemeldet = Auth::check();
        $kundenzugang = $angemeldet && Kundenbereich::istKundenzugang();
        $gesperrt = $kundenzugang ? null : Kundenmodus::sperrt($method, $path);
        if ($gesperrt !== null) {
            if (self::willJson()) {
                Response::json(['ok' => false, 'kundenmodus' => true, 'message' => 'Kundenmodus aktiv – erst mit dem Kennwort des Prüfers entsperren.'], 423);
                return;
            }
            Response::redirect('/berichte/' . $gesperrt . '/kunde');
            return;
        }

        // Kundenbereich-Tor: Kundenzugänge (users.kunde_id) erreichen nur den
        // Kundenbereich und die offenen Wege (anmelden, abmelden, Manifest).
        // Vor der Rechteprüfung – es geht nicht um Rechte („*“ erfüllte
        // jedes), und auch Routen ohne Recht (/, /profil) müssen zu sein.
        // Eine Allowlist vor der Suche nach der Route: jede künftige Route,
        // jede unbekannte Adresse und jede falsche Methode ist für Kunden von
        // selbst zu – auch ein POST läuft nie bis zu seinem Handler.
        if ($kundenzugang && !Kundenbereich::erlaubt($path)) {
            if (self::willJson()) {
                Response::json(['ok' => false, 'message' => 'Nur im Kundenbereich.'], 403);
                return;
            }
            Response::redirect(Kundenbereich::PFAD);
            return;
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
                // Kundenseite und Entsperren auch ohne das Recht: Endet die
                // Rolle, während das Gerät beim Kunden liegt, bliebe es sonst
                // gesperrt – alle anderen Wege führen ja zur Kundenseite.
                if (!Auth::can($route['permission']) && !Kundenmodus::ausweg($method, $path)) {
                    Response::forbidden();
                    return;
                }
            }

            // Ein Konto mit vorläufigem Kennwort kommt nur bis zum Profil –
            // ein Kundenzugang bis zu seinem Konto im Kundenbereich: erst ein
            // eigenes Kennwort, dann der Rest. Nicht im Kundenmodus – das
            // Profil führte zur Kundenseite, die zum Profil, im Kreis, und
            // entsperren ließe sich nie. Erst entsperren (mit dem vorläufigen
            // Kennwort), danach geht es zum Profil. Kundenzugänge haben keinen
            // Kundenmodus (Kundenmodus::aktiv() ist für sie null).
            if (Auth::check() && (int) (Auth::user()['must_change_password'] ?? 0) === 1
                && !in_array($path, self::OHNE_NEUES_KENNWORT, true)
                && ($kundenzugang || Kundenmodus::aktiv() === null)) {
                // Das Konto im Kundenbereich sagt es selbst (ein Hinweis, nicht zwei).
                if (!$kundenzugang) {
                    Flash::warning('Bitte zuerst ein neues Passwort vergeben.');
                }
                Response::redirect($kundenzugang ? Kundenbereich::KONTO : '/profil');
                return;
            }

            /*
             * Größer als post_max_size: PHP verwirft dann den ganzen Körper,
             * das Token mit. Ohne diese Prüfung hieße es unten „Sicherheits-
             * token abgelaufen“, obwohl nur die Datei zu groß war. Geändert
             * wird hier nichts – es gibt ja nichts, was geändert würde.
             */
            $verworfen = $request->koerperVerworfen();
            if ($verworfen !== null) {
                $satz = sprintf('Die Anfrage war mit %s größer, als der Server annimmt (post_max_size %s) – nichts übernommen.',
                    \App\Services\SiteService::mb($verworfen, true), (string) ini_get('post_max_size'));
                if (self::willJson()) {
                    Response::json(['ok' => false, 'message' => $satz], 413);
                    return;
                }
                Flash::error($satz);
                Response::redirect(Response::zurueckZiel('/'));
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
     * X-Requested-With: Formulare mit `data-laeuft` (app.js) gehen per fetch
     * hinaus und sollen wie ein gewöhnliches Formular mit Meldung und
     * Weiterleitung enden. Dieselbe Regel wie Request::wantsJson().
     */
    private static function willJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
