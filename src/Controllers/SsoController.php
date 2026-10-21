<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Sso\Anbieter;
use App\Auth\Sso\Anmeldung;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\SsoFehler;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Anmeldung über SSO (OpenID Connect): Start, Rückruf und die Seite nach dem
 * Abmelden. Die Arbeit macht App\Auth\Sso\Anmeldung; hier stehen nur
 * Kopfzeilen, Meldungen und Weiterleitungen.
 *
 * Jede Antwort: Cache-Control no-store und Referrer-Policy no-referrer – die
 * Adresse des Rückrufs trägt Code und state, die Seite nach dem Abmelden
 * einen Link mit dem ID-Token als Hinweis.
 *
 * Die eine Weiterleitung nach außen ist die zum Anmelde-Endpunkt des
 * Anbieters: Ihr Ziel kommt nur aus der geprüften Discovery (https, gleiche
 * Herkunft wie der Aussteller), nie aus der Anfrage. Alle anderen Ziele sind
 * eigene Pfade (Anmeldung::zielNach, Response::sichererPfad).
 */
final class SsoController extends Controller
{
    /** GET /login/sso?next=… – auch als Kachel im Portal des Anmeldedienstes (ohne Sitzung, ohne Formular). */
    public function start(Request $request): void
    {
        $this->ohneSpeichern();
        if ($request->method() === 'HEAD') {
            $this->redirect('/login');   // Prüfungen von außen legen keinen Vorgang an
            return;
        }
        $ziel = Anmeldung::zielNach($_GET['next'] ?? '');
        if (Auth::check()) {
            $this->redirect($ziel ?? Auth::landingPath());
            return;
        }
        if (!Einstellungen::aktiv()) {
            Flash::error(Anmeldung::T_NICHT_EINGERICHTET);
            $this->redirect('/login');
            return;
        }
        $gebremst = Anmeldung::gebremst($request->ip());
        if ($gebremst !== null) {
            Flash::error($gebremst);
            $this->redirect('/login');
            return;
        }
        try {
            $adresse = Anmeldung::starten('anmelden', $ziel, ($_COOKIE[Anmeldung::COOKIE_NEU] ?? '') === '1');
        } catch (SsoFehler $e) {
            Logger::info('sso', ['schritt' => 'start', 'grund' => $e->pruefung]);
            Flash::error($e->getMessage());
            $this->redirect('/login');
            return;
        }
        self::zumAnbieter($adresse);
    }

    /** GET /login/sso/rueckruf?code=…&state=… – die Prüfkette steht in Anmeldung::rueckruf(). */
    public function rueckruf(Request $request): void
    {
        $this->ohneSpeichern();
        if ($request->method() === 'HEAD') {
            $this->redirect('/login');   // HEAD verbraucht keinen Vorgang
            return;
        }
        $r = Anmeldung::rueckruf($_GET, $request->ip());
        if (!$r['ok']) {
            Flash::error($r['meldung']);
        } elseif ($r['meldung'] !== '') {
            Flash::success($r['meldung']);
        }
        $this->redirect(Response::sichererPfad($r['ziel']) ?? '/login');
    }

    /**
     * GET /abgemeldet – nach dem Abmelden einer SSO-Sitzung, einmal: der Link
     * zum Abmelden beim Anmeldedienst (ein Link, keine Weiterleitung – wer
     * dort angemeldet bleiben will, bleibt es). Neu geladen, ohne Hinweis
     * oder angemeldet geht es zur Anmeldung bzw. zur ersten Seite.
     */
    public function abgemeldet(Request $request): void
    {
        $this->ohneSpeichern();
        if (Auth::check()) {
            $this->redirect(Auth::landingPath());
            return;
        }
        $a = Session::get('sso_abgemeldet');
        Session::forget('sso_abgemeldet');
        $adresse = is_array($a) && is_string($a['hinweis'] ?? null) && (int) ($a['bis'] ?? 0) > time()
            ? Anbieter::abmeldeAdresse($a['hinweis']) : null;
        if ($adresse === null) {
            if (is_array($a)) {
                Flash::info('Abgemeldet.');
            }
            $this->redirect('/login');
            return;
        }
        Response::html(View::render('auth/abgemeldet', [
            'flashes' => Flash::pull(),
            'name'    => Einstellungen::name(),
            'adresse' => $adresse,
            'title'   => 'Abgemeldet',
        ], 'layout/blank'), 200, self::KOPF);
    }

    private const KOPF = ['Cache-Control: no-store', 'Referrer-Policy: no-referrer'];

    private function ohneSpeichern(): void
    {
        foreach (self::KOPF as $kopf) {
            header($kopf);
        }
    }

    /**
     * Die Weiterleitung zum Anmelde-Endpunkt – die einzige nach außen. Das
     * Ziel hat Anmeldung::starten() aus der geprüften Discovery gebaut.
     */
    private static function zumAnbieter(string $adresse): void
    {
        header('Location: ' . $adresse, true, 302);
    }
}
