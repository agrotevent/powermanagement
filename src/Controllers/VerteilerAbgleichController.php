<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\LocationSync;
use App\Services\VerteilerSync;
use App\Support\Flash;
use App\Support\Request;

/**
 * Verteiler-Abgleich: Standard-Verteiler und Inventar über das gemeinsame
 * Repository.
 *
 * Ansehen und Übernehmen: „inventory.pull“ (die Route). Was übernommen wird,
 * ändert den Katalog bzw. das Inventar – dafür braucht es zusätzlich das
 * Recht, das auch beim Bearbeiten von Hand gilt. Hochladen und Entfernen im
 * Repository: „inventory.push“.
 */
final class VerteilerAbgleichController extends Controller
{
    /** @return array{typ:bool,inventar:bool} */
    private function erlaubt(): array
    {
        return [
            // wie beim Bearbeiten eines Standard-Verteilers (AnlagenController)
            'typ'      => Auth::can('stecker.manage') || (Auth::can('anlagen.manage') && Zugriff::alles()),
            'inventar' => Auth::can('inventar.manage'),
        ];
    }

    public function index(Request $request): void
    {
        $dort = null;
        $commit = '';
        $hinweis = '';
        $hindernis = LocationSync::hindernis();
        if ($request->bool('holen') && $hindernis === null) {
            // Nur auf Klick: das kostet einen Zugriff auf das Repository.
            $holen = LocationSync::holen();
            if ($holen['ok']) {
                $dort = VerteilerSync::dort();
                $commit = $holen['commit'];
                $hinweis = trim(LocationSync::zweigHinweis());
            } else {
                Flash::error($holen['message']);
            }
        }
        $this->view('verteiler-abgleich/index', [
            'hindernis' => $hindernis,
            'liste'     => VerteilerSync::liste($dort),
            'geholt'    => $dort !== null,
            'commit'    => $commit,
            'hinweis'   => $hinweis,
            'erlaubt'   => $this->erlaubt(),
            'darfHoch'  => Auth::can('inventory.push'),
            'repo'      => LocationSync::repoUrl(),
            'zweig'     => LocationSync::zweig(),
        ]);
    }

    /** @return list<array{0:string,1:string}>|null null = alle */
    private function auswahl(Request $request): ?array
    {
        if ($request->bool('alle')) {
            return null;
        }
        return [[$request->str('bereich'), $request->str('schluessel')]];
    }

    public function uebernehmen(Request $request): void
    {
        $auswahl = $this->auswahl($request);
        if ($auswahl !== null && VerteilerSync::pfad($auswahl[0][0], $auswahl[0][1]) === null) {
            $this->antwort($request, ['ok' => false, 'message' => 'Unbekannter Verteiler.'], '/verteiler-abgleich?holen=1');
            return;
        }
        $this->antwort($request, VerteilerSync::uebernehmen($auswahl, $this->erlaubt()), '/verteiler-abgleich?holen=1');
    }

    public function hochladen(Request $request): void
    {
        $auswahl = $this->auswahl($request);
        if ($auswahl !== null && VerteilerSync::pfad($auswahl[0][0], $auswahl[0][1]) === null) {
            $this->antwort($request, ['ok' => false, 'message' => 'Unbekannter Verteiler.'], '/verteiler-abgleich?holen=1');
            return;
        }
        $this->antwort($request, VerteilerSync::hochladen($auswahl, Auth::name()), '/verteiler-abgleich?holen=1');
    }

    public function entfernen(Request $request): void
    {
        $this->antwort($request, VerteilerSync::entfernen($request->str('bereich'), $request->str('schluessel'), Auth::name()), '/verteiler-abgleich?holen=1');
    }
}
