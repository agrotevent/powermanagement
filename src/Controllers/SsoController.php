<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Sso\Anbieter;
use App\Auth\Sso\Anmeldung;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\Konten;
use App\Auth\Sso\Schutz;
use App\Auth\Sso\SsoFehler;
use App\Berichte\Kundenmodus;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Anmeldung über SSO (OpenID Connect): Start, Rückruf, Verbinden und Lösen im
 * Profil, Bestätigen (Kundenmodus entsperren, Token anzeigen) und die Seite
 * nach dem Abmelden. Die Arbeit macht
 * App\Auth\Sso\Anmeldung; hier stehen nur Kopfzeilen, Meldungen und
 * Weiterleitungen.
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
     * GET /login/sso/verbinden – im Profil das angemeldete Konto (Sitzung mit
     * Kennwort) mit einer Anmeldung beim Anmeldedienst verbinden. Ein Link
     * (kein Formular, CSP form-action), darum nur von dieser Seite aus
     * (Sec-Fetch-Site, wenn der Browser es schickt) – eine fremde Seite löste
     * sonst die Verknüpfung mit ihrer eigenen Anmeldung dort aus. Verlangt
     * beim Anmeldedienst eine neue Anmeldung (prompt=login, max_age=0).
     */
    public function verbinden(Request $request): void
    {
        $this->ohneSpeichern();
        if (!self::vonDieserSeite()) {
            Response::forbidden('Verbinden geht nur von dieser Seite aus – bitte im Profil auf „Mit … verbinden“ tippen.');
            return;
        }
        if ($request->method() === 'HEAD') {
            $this->redirect('/profil');   // legt keinen Vorgang an
            return;
        }
        if (!Auth::check()) {
            $this->redirect('/login?next=' . rawurlencode('/profil'));
            return;
        }
        $fehler = Anmeldung::verbindenFehler() ?? Anmeldung::gebremst($request->ip());
        if ($fehler !== null) {
            Flash::error($fehler);
            $this->redirect('/profil');
            return;
        }
        try {
            $adresse = Anmeldung::starten('verbinden', '/profil', true, Auth::id());
        } catch (SsoFehler $e) {
            Logger::info('sso', ['schritt' => 'start', 'grund' => $e->pruefung, 'zweck' => 'verbinden']);
            Flash::error($e->getMessage());
            $this->redirect('/profil');
            return;
        }
        self::zumAnbieter($adresse);
    }

    /**
     * GET /login/sso/bestaetigen?zweck=…&zurueck=… – eine heikle Handlung über
     * den Anmeldedienst bestätigen statt mit dem Kennwort (Step-up): den
     * Kundenmodus entsperren (kundenmodus:<Bericht>, auch im Kundenmodus
     * offen – Kundenmodus::erlaubt) oder ein Token der Schnittstelle anzeigen
     * (token:<Zugang>). Wie Verbinden ein Link, nur von dieser Seite aus
     * (Sec-Fetch-Site); beim Anmeldedienst immer eine neue Anmeldung
     * (prompt=login, max_age=0), im Rückruf dieselbe Identität und frisches
     * auth_time. Was erlaubt ist, sagt Anmeldung::bestaetigenFehler.
     */
    public function bestaetigen(Request $request): void
    {
        $this->ohneSpeichern();
        if (!self::vonDieserSeite()) {
            Response::forbidden('Bestätigen geht nur von dieser Seite aus.');
            return;
        }
        $zweck = is_string($_GET['zweck'] ?? null) ? $_GET['zweck'] : '';
        $zurueck = Anmeldung::zielNach($_GET['zurueck'] ?? '') ?? '/';
        $kundenmodus = str_starts_with($zweck, 'kundenmodus:');
        // Ein Fehler führt im Kundenmodus zurück zu dessen Kundenseite (nie zu
        // einer aus der Adresse), sonst dorthin, woher es kam.
        $modus = Kundenmodus::aktiv();
        $fehlerZiel = $modus !== null ? '/berichte/' . $modus['bericht'] . '/kunde' : $zurueck;
        if ($request->method() === 'HEAD') {
            $this->redirect($fehlerZiel);   // legt keinen Vorgang an
            return;
        }
        if (!Auth::check()) {
            $this->redirect('/login?next=' . rawurlencode($zurueck));
            return;
        }
        $fehler = Anmeldung::bestaetigenFehler($zweck)
            ?? ($kundenmodus && Kundenmodus::wartezeit() > 0 ? Anmeldung::T_GEBREMST : null)
            ?? Anmeldung::gebremst($request->ip());
        if ($fehler !== null) {
            Flash::error($fehler);
            $this->redirect($fehlerZiel);
            return;
        }
        try {
            $adresse = Anmeldung::starten($zweck, $zurueck, true, Auth::id());
        } catch (SsoFehler $e) {
            Logger::info('sso', ['schritt' => 'start', 'grund' => $e->pruefung, 'zweck' => explode(':', $zweck, 2)[0]]);
            Flash::error($e->getMessage());
            $this->redirect($fehlerZiel);
            return;
        }
        self::zumAnbieter($adresse);
    }

    /**
     * POST /profil/sso/loesen – die eigene Verknüpfung lösen. Nicht, wer sich
     * nur über SSO anmeldet (ohne Verknüpfung käme er nicht mehr hinein bzw.
     * umginge „nur SSO“ – das löst die Benutzerverwaltung). In einer Sitzung
     * über SSO endet die Sitzung damit: abmelden, mit Kennwort neu anmelden.
     */
    public function profilLoesen(Request $request): void
    {
        $this->ohneSpeichern();
        $user = Auth::user();
        if ($user === null) {
            $this->redirect('/login?next=' . rawurlencode('/profil'));
            return;
        }
        $name = Einstellungen::name();
        if (Schutz::nurSso($user)) {
            Flash::error('Dieses Konto meldet sich nur über ' . $name . ' an – lösen kann die Verknüpfung nur die Benutzerverwaltung.');
            $this->redirect('/profil');
            return;
        }
        $sso = Auth::anmeldeweg() === 'sso';
        if (!Konten::loesen((int) $user['id'])) {
            Flash::info('Dieses Konto ist mit keiner Anmeldung bei ' . $name . ' verknüpft.');
            $this->redirect('/profil');
            return;
        }
        if ($sso) {
            Auth::logout();
            Session::neu();
            Anmeldung::cookieNeuSetzen();
            Flash::info('Verknüpfung gelöst – bitte mit Kennwort anmelden.');
            $this->redirect('/login');
            return;
        }
        Flash::success('Die Verknüpfung mit ' . $name . ' ist gelöst – angemeldet wird ab jetzt mit Kennwort.');
        $this->redirect('/profil');
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

    /**
     * Kam der Link von dieser Seite? Sec-Fetch-Site same-origin oder none
     * (eingetippt, Lesezeichen); ohne die Kopfzeile (ältere Browser) ja.
     */
    private static function vonDieserSeite(): bool
    {
        $seite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        return !is_string($seite) || in_array(strtolower(trim($seite)), ['same-origin', 'none'], true);
    }

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
