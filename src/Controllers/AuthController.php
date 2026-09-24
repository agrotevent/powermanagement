<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\BenutzerService;
use App\Support\Audit;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;

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
            Flash::warning('Bitte zuerst ein neues Passwort vergeben.');
            $this->redirect('/profil');
            return;
        }
        $next = $request->str('next', '');
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || $next === '/login') {
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
        $current = (string) $request->input('current_password', '');
        $new     = (string) $request->input('new_password', '');
        $repeat  = (string) $request->input('new_password_repeat', '');
        $user    = Auth::user();

        if (!password_verify($current, (string) $user['password_hash'])) {
            Flash::error('Das aktuelle Passwort stimmt nicht.');
            $this->redirect('/profil');
            return;
        }
        if ($new !== $repeat) {
            Flash::error('Die neuen Passwörter stimmen nicht überein.');
            $this->redirect('/profil');
            return;
        }
        if ($error = Auth::validatePassword($new)) {
            Flash::error($error);
            $this->redirect('/profil');
            return;
        }
        Database::update('users', (int) $user['id'], [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'updated_at' => gmdate('c'),
        ]);
        Audit::log('auth.passwort', 'user', (string) $user['id']);
        Csrf::token();
        Flash::success('Passwort geändert.');
        $this->redirect(Auth::landingPath());
    }
}
