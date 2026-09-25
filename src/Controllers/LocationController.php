<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\LocationSync;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;

final class LocationController extends Controller
{
    public function index(Request $request): void
    {
        $liste = null;
        if ($request->bool('holen')) {
            // Nur auf Klick: das kostet einen Zugriff auf das Repository.
            $liste = LocationSync::locationsImRepo();
            if (!$liste['ok']) {
                Flash::error($liste['message']);
            }
        }
        // Je Standort: wie viel beim Hochladen übertragen wird und wie viel hier bleibt.
        $anzahl = [];
        foreach (Database::select('SELECT site_id, fest, COUNT(*) AS n FROM objekte WHERE ist_vorlage = 0 AND site_id IS NOT NULL GROUP BY site_id, fest') as $z) {
            $anzahl[(int) $z['site_id']][(int) $z['fest'] === 1 ? 'fest' : 'mobil'] = (int) $z['n'];
        }
        foreach (Database::select('SELECT f.site_id, l.fest, COUNT(*) AS n FROM leitungen l JOIN floors f ON f.id = l.floor_id GROUP BY f.site_id, l.fest') as $z) {
            $anzahl[(int) $z['site_id']][(int) $z['fest'] === 1 ? 'fest_leitungen' : 'mobil_leitungen'] = (int) $z['n'];
        }
        $this->view('location/index', [
            'hindernis' => LocationSync::hindernis(),
            'liste'     => $liste,
            'anzahl'    => $anzahl,
            'standorte' => Zugriff::standorteFiltern(SiteService::all()),
            'darfHoch'  => Auth::can('inventory.push'),
            'repo'      => LocationSync::repoUrl(),
            'zweig'     => LocationSync::zweig(),
        ]);
    }

    public function uebernehmen(Request $request): void
    {
        $ergebnis = LocationSync::uebernehmen($request->str('schluessel'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/location?holen=1');
    }

    public function hochladen(Request $request): void
    {
        $siteId = $request->int('site_id');
        if (!Zugriff::siehtStandort($siteId)) {
            Flash::error('Dieser Standort ist nicht freigegeben.');
            $this->redirect('/location');
            return;
        }
        $ergebnis = LocationSync::locationHochladen($siteId, Auth::name());
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/location');
    }
}
