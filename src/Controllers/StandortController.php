<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Zugriff;
use App\Services\SiteService;
use App\Support\Flash;
use App\Support\Request;

final class StandortController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('standorte/index', [
            'standorte' => Zugriff::standorteFiltern(SiteService::all()),
        ]);
    }

    public function speichern(Request $request): void
    {
        $name = $request->str('name');
        if ($name === '') {
            Flash::error('Der Standort braucht einen Namen.');
            $this->back('/standorte');
            return;
        }
        $id = $request->int('id') ?: null;
        if ($id !== null && !Zugriff::siehtStandort($id)) {
            $this->back('/standorte');
            return;
        }
        SiteService::saveSite($id, ['name' => $name, 'address' => $request->str('address'), 'notes' => $request->str('notes')]);
        Flash::success('Standort gespeichert.');
        $this->redirect('/standorte');
    }

    public function loeschen(Request $request): void
    {
        $id = $request->int('id');
        if (!Zugriff::alles()) {
            Flash::error('Standorte löschen darf nur, wer alle Standorte sieht.');
            $this->redirect('/standorte');
            return;
        }
        SiteService::deleteSite($id);
        Flash::success('Standort gelöscht. Die Anlagen darauf bleiben erhalten, stehen aber auf keinem Standort mehr.');
        $this->redirect('/standorte');
    }

    public function etageSpeichern(Request $request): void
    {
        $siteId = $request->int('site_id');
        if (!Zugriff::siehtStandort($siteId) || $request->str('name') === '') {
            Flash::error('Bitte einen Namen angeben.');
            $this->redirect('/standorte');
            return;
        }
        SiteService::saveFloor($request->int('id') ?: null, [
            'site_id'    => $siteId,
            'name'       => $request->str('name'),
            'level_no'   => $request->int('level_no'),
            'sort_order' => $request->int('level_no'),
            'scale_note' => $request->str('scale_note'),
            'notes'      => $request->str('notes'),
        ]);
        Flash::success('Etage gespeichert.');
        $this->redirect('/standorte');
    }

    public function etageLoeschen(Request $request): void
    {
        $etage = SiteService::floor($request->int('id'));
        if ($etage !== null && Zugriff::siehtStandort((int) $etage['site_id'])) {
            SiteService::deleteFloor((int) $etage['id']);
            Flash::success('Etage gelöscht. Kabel und Kabelwege dieser Etage sind mit entfernt.');
        }
        $this->redirect('/standorte');
    }

    public function grundriss(Request $request): void
    {
        $siteId = $request->int('site_id');
        if (!Zugriff::siehtStandort($siteId) || !isset($_FILES['plan'])) {
            Flash::error('Bitte eine Datei wählen.');
            $this->redirect('/standorte');
            return;
        }
        $ergebnis = SiteService::uploadPlan($siteId, $_FILES['plan'], [
            'floor_ids'   => $request->arr('floor_ids'),
            'neue_etagen' => $request->bool('neue_etagen'),
        ]);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/standorte');
    }
}
