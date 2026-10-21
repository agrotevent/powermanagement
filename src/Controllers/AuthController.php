<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Kunden\Kundenbereich;
use App\Services\BenutzerService;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::landingPath());
            return;
        }
        $this->view('auth/login', ['next' => $request->str('next', '')], 'layout/blank');
    }

    public function login(Request $request): void
    {
        $result = Auth::attempt($request->str('username'), (string) $request->input('password', ''), $request->ip());
        if (!$result['ok']) {
            Flash::error($result['message']);
            $this->redirect('/login');
            return;
        }
        if (!empty($result['must_change_password'])) {
            // Kundenzugänge ändern ihr Kennwort im Kundenbereich – das Profil
            // des Personals erreichen sie nicht.
            // Das Konto im Kundenbereich sagt es selbst (ein Hinweis, nicht zwei).
            $kunde = Kundenbereich::istKundenzugang($result['user']);
            if (!$kunde) {
                Flash::warning('Bitte zuerst ein neues Passwort vergeben.');
            }
            $this->redirect($kunde ? Kundenbereich::KONTO : '/profil');
            return;
        }
        // Nur ein Pfad auf dieser Seite – sonst leitete ein präparierter
        // Link nach der echten Anmeldung auf eine fremde Seite weiter.
        $next = Response::sichererPfad($request->str('next', ''));
        if ($next === null || $next === '/login') {
            $next = Auth::landingPath();
        }
        $this->redirect($next);
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Flash::info('Abgemeldet.');
        $this->redirect('/login');
    }

    public function profile(Request $request): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        $this->view('auth/profile', [
            'roles'       => Auth::roles(),
            'permissions' => Auth::permissions(),
            'freigaben'   => array_map(static function (array $f): array {
                $f['ziel_name'] = BenutzerService::zielName($f);
                return $f;
            }, Zugriff::freigaben()),
            'alles'       => Zugriff::alles(),
        ]);
    }

    public function changePassword(Request $request): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        // Andere Sitzungen mit dem alten Kennwort enden (Auth::user()); diese
        // läuft mit neuer Sitzungs-ID und neuem Token weiter.
        $ergebnis = Auth::kennwortAendern(
            (array) Auth::user(),
            (string) $request->input('current_password', ''),
            (string) $request->input('new_password', ''),
            (string) $request->input('new_password_repeat', '')
        );
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
            $this->redirect('/profil');
            return;
        }
        Flash::success($ergebnis['message']);
        $this->redirect(Auth::landingPath());
    }
}
