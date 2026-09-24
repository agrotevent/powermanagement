<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Messung\Datenquellen;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

final class BenutzerController extends Controller
{
    public function index(Request $request): void
    {
        $bearbeiten = $request->int('id');
        $benutzer = $bearbeiten > 0 ? BenutzerService::find($bearbeiten) : null;
        $zuweisungen = [];
        if ($benutzer !== null) {
            foreach (Database::select('SELECT * FROM user_roles WHERE user_id = ?', [$bearbeiten]) as $z) {
                $zuweisungen[(int) $z['role_id']] = $z;
            }
        }
        $this->view('benutzer/index', [
            'alle'        => BenutzerService::alle(),
            'rollen'      => BenutzerService::rollen(),
            'benutzer'    => $benutzer,
            'neu'         => $request->bool('neu'),
            'zuweisungen' => $zuweisungen,
        ]);
    }

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $ergebnis = BenutzerService::speichern($id, $_POST, (array) ($_POST['rollen'] ?? []));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/system/benutzer' : ($_SERVER['HTTP_REFERER'] ?? '/system/benutzer'));
    }

    public function loeschen(Request $request): void
    {
        $ergebnis = BenutzerService::loeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/benutzer');
    }

    public function entsperren(Request $request): void
    {
        BenutzerService::entsperren($request->int('id'));
        Flash::success('Konto entsperrt.');
        $this->redirect('/system/benutzer');
    }

    public function freigaben(Request $request): void
    {
        $art = $request->str('art') === 'role' ? 'role' : 'user';
        $id = $request->int('id');
        $subjekt = $art === 'role'
            ? Database::first('SELECT id, name AS titel FROM roles WHERE id = ?', [$id])
            : Database::first("SELECT id, username AS titel, full_name, gueltig_ab, gueltig_bis FROM users WHERE id = ?", [$id]);
        if ($subjekt === null) {
            Response::notFound();
            return;
        }
        $this->view('benutzer/freigaben', [
            'art'       => $art,
            'subjekt'   => $subjekt,
            'freigaben' => BenutzerService::freigaben($art, $id),
            'standorte' => SiteService::all(),
            'objekte'   => AnlagenService::liste(),
            'geraete'   => Datenquellen::alle(),
        ]);
    }

    public function freigabeSpeichern(Request $request): void
    {
        $ergebnis = BenutzerService::freigabeSpeichern($_POST);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/freigaben?art=' . ($request->str('subject_type') === 'role' ? 'role' : 'user') . '&id=' . $request->int('subject_id'));
    }

    public function freigabeLoeschen(Request $request): void
    {
        $f = Database::first('SELECT * FROM freigaben WHERE id = ?', [$request->int('id')]);
        $ergebnis = BenutzerService::freigabeLoeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($f !== null ? '/system/freigaben?art=' . $f['subject_type'] . '&id=' . (int) $f['subject_id'] : '/system/benutzer');
    }

    public function rollen(Request $request): void
    {
        $this->view('benutzer/rollen', ['rollen' => BenutzerService::rollen(), 'bearbeiten' => $request->int('id')]);
    }

    public function rolleSpeichern(Request $request): void
    {
        $ergebnis = BenutzerService::rolleSpeichern($request->int('id') ?: null, $request->str('name'), $request->str('description'), $request->arr('rechte'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/rollen');
    }

    public function rolleLoeschen(Request $request): void
    {
        $ergebnis = BenutzerService::rolleLoeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/rollen');
    }
}
