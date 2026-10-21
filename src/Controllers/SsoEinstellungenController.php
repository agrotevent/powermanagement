<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Sso\Anbieter;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\Konten;
use App\Auth\Sso\Schutz;
use App\Services\BenutzerService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;

/**
 * System → Anmeldung (SSO): Verbindung zum Anmeldedienst, „Verbindung
 * prüfen“, Ein-/Ausschalten, Optionen, Gruppen → Rollen, Anmeldeanfragen und
 * die verknüpften Konten.
 *
 * Die Route verlangt `sso.manage`. Was darüber hinausgeht, prüfen die
 * Klassen in App\Auth\Sso selbst: Gruppen Rollen zuordnen nur mit
 * `system.users.manage` und nur Rollen, die man selbst vergeben darf
 * (Konten::zuordnungFehler), Anmeldeanfragen freischalten nur an Konten, die
 * man verwalten darf, neue Konten nur mit vergebbaren Rollen. Verknüpfungen
 * lösen und SSO-Sitzungen beenden geht über die Benutzerverwaltung
 * (BenutzerController::sso – `system.users.manage` und darfVerwalten).
 *
 * Eingaben nur aus dem Formular ($_POST), nie aus der Adresse – das Secret
 * schon gar nicht. Es erscheint nie in der Seite (Einstellungen::zustand()
 * nennt nur „hinterlegt“, „fehlt“ oder „gehört zu einer anderen Verbindung“).
 *
 * „Verbindung prüfen“ und „Einschalten“ warten auf den Anmeldedienst
 * (Discovery, Schlüssel, Token-Probe – zusammen bis 24 s) und halten dabei
 * die Sitzung nicht (Controller::ohneSitzungssperre).
 */
final class SsoEinstellungenController extends Controller
{
    private const ZIEL = '/system/sso';

    public function index(Request $request): void
    {
        $admins = Einstellungen::schalter(Einstellungen::ADMINS);
        $rollen = [];
        foreach (BenutzerService::rollen() as $r) {
            $verwaltend = (int) $r['is_system'] === 1 || Schutz::verwaltend($r['permissions']);
            $rollen[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'verwaltend' => $verwaltend,
                // Wählbar nur, was man selbst vergeben darf und womit sich ein Mitglied auch anmelden kann.
                'waehlbar'   => BenutzerService::darfVergeben($r['permissions']) && (!$verwaltend || $admins),
                'freigaben'  => (int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'role' AND subject_id = ?", [(int) $r['id']]),
            ];
        }
        $darfBenutzer = Auth::can('system.users.manage');
        // Freischalten an: Konten des Personals ohne Verknüpfung, die man verwalten darf.
        $konten = [];
        if ($darfBenutzer) {
            foreach (Database::select(
                'SELECT id, username, full_name FROM users WHERE kunde_id IS NULL AND id NOT IN (SELECT user_id FROM sso_identitaeten) ORDER BY username'
            ) as $k) {
                if (BenutzerService::darfVerwalten((int) $k['id'])) {
                    $konten[] = $k;
                }
            }
        }
        $verknuepfungen = array_map(static function (array $v) use ($darfBenutzer): array {
            $v['verwaltbar'] = $darfBenutzer && $v['kunde_id'] === null && BenutzerService::darfVerwalten((int) $v['user_id']);
            $v['anderer_aussteller'] = !hash_equals(Einstellungen::aussteller(), (string) $v['aussteller']);
            return $v;
        }, Konten::verknuepfungen());
        $this->view('sso/einstellungen', [
            'zustand'        => Einstellungen::zustand(),
            'zuordnungen'    => array_values(array_filter(Konten::zuordnungen(), static fn (array $z): bool => $z['art'] === 'rolle')),
            'rollen'         => $rollen,
            'anfragen'       => Konten::anfragen(),
            'konten'         => $konten,
            'verknuepfungen' => $verknuepfungen,
            'darfBenutzer'   => $darfBenutzer,
        ]);
    }

    /** Aussteller, Client-ID, Secret (leer = unverändert, „-“ = entfernen) und Beschriftung. */
    public function verbindung(Request $request): void
    {
        $r = Einstellungen::verbindungSpeichern(self::feld('aussteller'), self::feld('client_id'), self::feld('client_secret'), self::feld('name'));
        if (!$r['ok']) {
            Flash::error($r['message']);
        } elseif (!empty($r['warnung'])) {
            Flash::warning($r['message']);
        } else {
            Flash::success($r['message']);
        }
        $this->redirect(self::ZIEL);
    }

    /** „Verbindung prüfen“: frisch, ohne die Sitzung zu halten; der Befund steht danach auf der Seite. */
    public function pruefen(Request $request): void
    {
        [$p, $angemeldet] = $this->ohneSitzungssperre(static fn (): array => Anbieter::pruefen());
        if (!$angemeldet) {
            $this->redirect('/login');
            return;
        }
        if ($p['ok']) {
            Flash::success('Verbindung geprüft – alles in Ordnung.' . (Einstellungen::schalter(Einstellungen::AKTIV) ? '' : ' Jetzt lässt sich die Anmeldung einschalten.'));
        } else {
            Flash::error('Verbindung geprüft – ' . self::ersterFehler($p['befunde']));
        }
        $this->redirect(self::ZIEL);
    }

    /** Einschalten (prüft frisch, ohne die Sitzung zu halten) bzw. Ausschalten (laufende SSO-Sitzungen enden). */
    public function schalten(Request $request): void
    {
        if (self::feld('an') === '1') {
            [$r, $angemeldet] = $this->ohneSitzungssperre(static fn (): array => Einstellungen::einschalten());
            if (!$angemeldet) {
                $this->redirect('/login');
                return;
            }
        } else {
            $r = Einstellungen::ausschalten();
        }
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL);
    }

    /** Scopes, Gruppen-Claim, Pflichtgruppen, automatische Anlage, Rücksprung, Tage ohne Anmeldung. */
    public function optionen(Request $request): void
    {
        $r = Einstellungen::optionenSpeichern([
            'scopes'            => self::feld('scopes'),
            'gruppen_claim'     => self::feld('gruppen_claim'),
            'pflichtgruppen'    => self::feld('pflichtgruppen'),
            'anlegen'           => self::feld('anlegen') === '1',
            'abmelde_rueckkehr' => self::feld('abmelde_rueckkehr') === '1',
            'inaktiv_tage'      => self::feld('inaktiv_tage'),
        ]);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '#optionen');
    }

    /** Gruppe → Rolle (bzw. mit Schritt 5 → Kunde) – Konten::zuordnungFehler entscheidet. */
    public function zuordnung(Request $request): void
    {
        $art = self::feld('art');
        $r = Konten::zuordnungSpeichern(self::feld('gruppe'), $art === '' ? 'rolle' : $art, self::zahl('ziel_id'));
        if (!$r['ok']) {
            Flash::error($r['message']);
        } else {
            Flash::success($r['message']);
            if (isset($r['hinweis'])) {
                Flash::warning($r['hinweis']);
            }
        }
        $this->redirect(self::ZIEL . '#gruppen');
    }

    public function zuordnungLoeschen(Request $request, array $params): void
    {
        $r = Konten::zuordnungLoeschen(self::id($params));
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '#gruppen');
    }

    /** Eine Anmeldeanfrage an ein bestehendes Konto freischalten – aus dem Browser kommen nur die beiden IDs. */
    public function anfrageFreischalten(Request $request, array $params): void
    {
        $r = Konten::anfrageFreischalten(self::id($params), self::zahl('user_id'));
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '#anfragen');
    }

    /** Zu einer Anmeldeanfrage ein neues Konto anlegen (nur SSO, Rollen von Hand). */
    public function anfrageAnlegen(Request $request, array $params): void
    {
        $rollen = is_array($_POST['rollen'] ?? null) ? array_values(array_filter($_POST['rollen'], 'is_string')) : [];
        $r = Konten::anfrageAnlegen(self::id($params), [
            'username'    => self::feld('username'),
            'rollen'      => $rollen,
            'gueltig_bis' => self::feld('gueltig_bis'),
        ]);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '#anfragen');
    }

    public function anfrageVerwerfen(Request $request, array $params): void
    {
        $r = Konten::anfrageVerwerfen(self::id($params));
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '#anfragen');
    }

    // ------------------------------------------------------------ intern

    /** Ein Feld nur aus dem Formular – ein Array oder ein Wert aus der Adresse zählt als leer. */
    private static function feld(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    }

    private static function zahl(string $name): int
    {
        $wert = trim(self::feld($name));
        return ctype_digit($wert) && strlen($wert) <= 10 ? (int) $wert : 0;
    }

    private static function id(array $params): int
    {
        $id = (string) ($params['id'] ?? '');
        return ctype_digit($id) && strlen($id) <= 10 ? (int) $id : 0;
    }

    /** @param list<array{ok:bool,text:string}> $befunde */
    private static function ersterFehler(array $befunde): string
    {
        foreach ($befunde as $b) {
            if (!$b['ok']) {
                return $b['text'];
            }
        }
        return 'nicht in Ordnung.';
    }
}
