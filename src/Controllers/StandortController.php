<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Zugriff;
use App\Services\PlanTileService;
use App\Services\SiteService;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Session;

final class StandortController extends Controller
{
    public function index(Request $request): void
    {
        // Was PHP hier wirklich annimmt (höchstens SiteService::MAX_UPLOAD_BYTES) –
        // das Formular nennt es, und der Browser prüft vor dem Hochladen dagegen.
        $grenze = SiteService::uploadGrenze();
        $standorte = Zugriff::standorteFiltern(SiteService::all());
        /*
         * Je Etage mit Plan: wie seine Kacheln gerechnet sind (Maße, Fassung,
         * neu rechnen empfohlen, läuft) – aus kacheln.json, ohne die Datei zu
         * lesen. Den Knopf „Neu rechnen“ bekommt, wer den Standort ändern darf
         * (wie Maßstab und Georeferenz); prüfen tut es der Server noch einmal.
         */
        $kacheln = [];
        $darfNeu = [];
        foreach ($standorte as $s) {
            $darfHier = Zugriff::darfStandortAendern((int) $s['id']);
            foreach ($s['floors'] as $f) {
                if ((string) $f['plan_file'] === '') {
                    continue;
                }
                $kacheln[(int) $f['id']] = PlanTileService::uebersicht($f);
                // Teilen sich Etagen mehrerer Standorte die Pyramide, an jedem „bearbeiten“.
                $darfNeu[(int) $f['id']] = $darfHier && array_filter(PlanTileService::etagenMitPyramide($f),
                    static fn (array $e): bool => !Zugriff::darfStandortAendern($e['site_id'])) === [];
            }
        }
        $this->view('standorte/index', [
            'standorte'    => $standorte,
            'kacheln'      => $kacheln,
            'darfNeu'      => $darfNeu,
            'uploadGrenze' => $grenze,
            'uploadText'   => SiteService::mb($grenze),
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
        if ($id !== null && !Zugriff::darfStandortAendern($id)) {
            Flash::error('Diesen Standort darfst du nicht ändern.');
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
        $id = $request->int('id') ?: null;
        $alt = $id !== null ? SiteService::floor($id) : null;
        // Eine vorhandene Etage nur auf ihrem eigenen Standort – sonst ließe
        // sich über die ID eine fremde Etage umbenennen und herüberziehen.
        if (!Zugriff::darfStandortAendern($siteId) || ($id !== null && ($alt === null || (int) $alt['site_id'] !== $siteId))) {
            Flash::error('Diese Etage darfst du nicht ändern.');
            $this->redirect('/standorte');
            return;
        }
        if ($request->str('name') === '') {
            Flash::error('Bitte einen Namen angeben.');
            $this->redirect('/standorte');
            return;
        }
        SiteService::saveFloor($id, [
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
        if ($etage !== null && Zugriff::darfStandortAendern((int) $etage['site_id'])) {
            SiteService::deleteFloor((int) $etage['id']);
            Flash::success('Etage gelöscht. Kabel und Kabelwege dieser Etage sind mit entfernt.');
        }
        $this->redirect('/standorte');
    }

    public function grundriss(Request $request): void
    {
        $siteId = $request->int('site_id');
        if (!Zugriff::darfStandortAendern($siteId)) {
            Flash::error('Auf diesem Standort darfst du keinen Grundriss hinterlegen.');
            $this->redirect('/standorte');
            return;
        }
        if (!isset($_FILES['plan'])) {
            Flash::error('Bitte eine Datei wählen.');
            $this->redirect('/standorte');
            return;
        }
        $optionen = [
            'floor_ids'   => $request->arr('floor_ids'),
            'neue_etagen' => $request->bool('neue_etagen'),
        ];
        // Kacheln rechnen dauert bis zu SiteService::KACHELZEIT_IM_UPLOAD –
        // so lange warten andere Reiter nicht auf die Sitzung.
        $ergebnis = Session::ohneSperre(static fn (): array => SiteService::uploadPlan($siteId, $_FILES['plan'], $optionen));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/standorte');
    }
}
