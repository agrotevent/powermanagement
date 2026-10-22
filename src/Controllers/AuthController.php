<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Sso\Anmeldung;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\Schutz;
use App\Auth\Zugriff;
use App\Kunden\Kundenbereich;
use App\Services\BenutzerService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::landingPath());
            return;
        }
        // Mit SSO oben der Knopf (ein Link – CSP form-action), bei „nur SSO“
        // das Kennwortformular eingeklappt (Kundenzugänge, Notzugang) – offen
        // mit ?lokal=1 oder wenn eine Meldung kommt (etwa nach einem falschen
        // Kennwort). Liest nur Einstellungen, nie das Netz.
        $sso = Einstellungen::knopf();
        $this->view('auth/login', [
            'next'   => $request->str('next', ''),
            'sso'    => $sso,
            'nurSso' => $sso !== null && Einstellungen::schalter(Einstellungen::NUR_SSO),
            'lokal'  => ($_GET['lokal'] ?? null) === '1' || !empty($_SESSION['flash']),
        ], 'layout/blank');
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

    /**
     * Abmelden – hier. Danach eine neue, leere Sitzung: In der zerstörten
     * ginge „Abgemeldet.“ verloren. War es eine Sitzung über SSO – oder hat
     * sich in dieser Sitzung jemand beim Anmeldedienst angemeldet (Bestätigen,
     * Verbinden; Anmeldung::BEIM_ANBIETER) –, verlangt die nächste
     * SSO-Anmeldung in diesem Browser eine neue Anmeldung beim Anmeldedienst
     * (geteilte Tablets), und /abgemeldet bietet einmal den Link zum
     * Abmelden dort an – wenn er es anbietet.
     */
    public function logout(Request $request): void
    {
        $sso = Auth::anmeldeweg() === 'sso' || Session::get(Anmeldung::BEIM_ANBIETER) === true;
        $hinweis = $sso ? Session::get(Anmeldung::HINWEIS) : null;
        Auth::logout();
        Session::neu();
        if ($sso) {
            Anmeldung::cookieNeuSetzen();
        }
        if (is_string($hinweis) && $hinweis !== '') {
            Session::put('sso_abgemeldet', ['hinweis' => $hinweis, 'bis' => time() + 600]);
            $this->redirect('/abgemeldet');
            return;
        }
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
            'sso'         => self::ssoProfil((array) Auth::user()),
        ]);
    }

    /**
     * Die Karte „Anmeldung über …“ im Profil – null, wenn SSO aus ist und
     * das Konto keine Verknüpfung hat. Nur Anzeige: Name dort, Weg, zuletzt,
     * Gruppen der letzten Anmeldung (keine iss/sub).
     *
     * @return array<string,mixed>|null
     */
    private static function ssoProfil(array $user): ?array
    {
        $aktiv = Einstellungen::aktiv();
        $i = Database::first('SELECT * FROM sso_identitaeten WHERE user_id = ? ORDER BY id LIMIT 1', [(int) $user['id']]);
        if (!$aktiv && $i === null) {
            return null;
        }
        $gruppen = $i !== null ? json_decode((string) ($i['gruppen'] ?? '[]'), true) : [];
        $weg = Auth::anmeldeweg();
        return [
            'name'          => Einstellungen::name(),
            'aktiv'         => $aktiv,
            'identitaet'    => $i === null ? null : [
                'weg'                => (string) $i['weg'],
                'name_dort'          => (string) $i['name_dort'],
                'angelegt_am'        => $i['angelegt_am'],
                'zuletzt_am'         => $i['zuletzt_am'],
                'gruppen'            => is_array($gruppen) ? array_values(array_filter($gruppen, 'is_string')) : [],
                'anderer_aussteller' => !hash_equals(Einstellungen::aussteller(), (string) $i['aussteller']),
            ],
            'weg'           => $weg,
            'seit'          => $weg === 'sso' ? (int) Session::get('angemeldet', 0) : null,
            'nur_sso'       => Schutz::nurSso($user),
            'verbinden'     => Anmeldung::verbindenFehler() === null,
            // Kennwort eben bestätigt: der Link zum Anmeldedienst (sonst erst das Formular).
            'verbinden_bereit' => Anmeldung::verbindenBestaetigt(),
            // Verwaltungskonten: anmelden über SSO erst mit „Verwaltung per SSO“.
            'verwaltung'    => Schutz::darfPerSso($user) === 'verwaltung',
        ];
    }

    public function changePassword(Request $request): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        // Wer sich nur über SSO anmeldet, hat hier kein Kennwort, das gälte.
        if (Schutz::nurSso((array) Auth::user())) {
            Flash::error('Dieses Konto meldet sich nur über ' . Einstellungen::name() . ' an.');
            $this->redirect('/profil');
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
