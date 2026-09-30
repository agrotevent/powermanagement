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

    /** Die Meldung, wenn ein Konto mehr Rechte hat als der, der es ändern will. */
    private const UEBER_MIR = 'Dieses Konto hat Rechte, die du selbst nicht hast – ändern, sperren und löschen darf es nur, wer sie alle hat.';

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $rollen = (array) ($_POST['rollen'] ?? []);
        $ergebnis = null;
        if ($id !== null && !BenutzerService::darfVerwalten($id)) {
            $ergebnis = ['ok' => false, 'message' => self::UEBER_MIR];
        }
        foreach ($rollen as $rolleId => $zuweisung) {
            if ($ergebnis !== null || empty($zuweisung['aktiv'])) {
                continue;
            }
            $rolle = Database::first('SELECT name FROM roles WHERE id = ?', [(int) $rolleId]);
            if ($rolle === null) {
                $ergebnis = ['ok' => false, 'message' => 'Diese Rolle gibt es nicht.'];
            } elseif (!BenutzerService::darfVergeben(BenutzerService::rollenRechte((int) $rolleId))) {
                $ergebnis = ['ok' => false, 'message' => 'Die Rolle „' . $rolle['name'] . '“ hat Rechte, die du selbst nicht hast – vergeben darf sie nur, wer sie alle hat.'];
            }
        }
        $ergebnis ??= BenutzerService::speichern($id, $_POST, $rollen);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/system/benutzer' : Response::zurueckZiel('/system/benutzer'));
    }

    public function loeschen(Request $request): void
    {
        $ergebnis = BenutzerService::darfVerwalten($request->int('id'))
            ? BenutzerService::loeschen($request->int('id'))
            : ['ok' => false, 'message' => self::UEBER_MIR];
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/benutzer');
    }

    public function entsperren(Request $request): void
    {
        if (!BenutzerService::darfVerwalten($request->int('id'))) {
            Flash::error(self::UEBER_MIR);
        } else {
            BenutzerService::entsperren($request->int('id'));
            Flash::success('Konto entsperrt.');
        }
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

    /** Darf der angemeldete Benutzer die Freigaben dieses Kontos bzw. dieser Rolle ändern? */
    private function darfFreigabenAendern(string $art, int $id): bool
    {
        return $art === 'role'
            ? BenutzerService::darfVergeben(BenutzerService::rollenRechte($id))
            : BenutzerService::darfVerwalten($id);
    }

    public function freigabeSpeichern(Request $request): void
    {
        $art = $request->str('subject_type') === 'role' ? 'role' : 'user';
        $ergebnis = !$this->darfFreigabenAendern($art, $request->int('subject_id'))
            ? ['ok' => false, 'message' => $art === 'role' ? 'Diese Rolle hat Rechte, die du selbst nicht hast.' : self::UEBER_MIR]
            : (!BenutzerService::darfFreigabeVergeben($request->str('ziel_art'), $request->bool('darf_steuern'))
                ? ['ok' => false, 'message' => 'Freigaben auf alles oder mit „schalten“ vergibt nur die Administration, andere nur, wer selbst alle Standorte sieht.']
                : BenutzerService::freigabeSpeichern($_POST));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/freigaben?art=' . ($request->str('subject_type') === 'role' ? 'role' : 'user') . '&id=' . $request->int('subject_id'));
    }

    public function freigabeLoeschen(Request $request): void
    {
        $f = Database::first('SELECT * FROM freigaben WHERE id = ?', [$request->int('id')]);
        $ergebnis = $f !== null && (!$this->darfFreigabenAendern((string) $f['subject_type'], (int) $f['subject_id'])
            || !BenutzerService::darfFreigabeVergeben((string) $f['ziel_art'], (int) $f['darf_steuern'] === 1))
            ? ['ok' => false, 'message' => 'Diese Freigabe darf nur entfernen, wer sie auch vergeben dürfte.']
            : BenutzerService::freigabeLoeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($f !== null ? '/system/freigaben?art=' . $f['subject_type'] . '&id=' . (int) $f['subject_id'] : '/system/benutzer');
    }

    public function rollen(Request $request): void
    {
        $this->view('benutzer/rollen', ['rollen' => BenutzerService::rollen(), 'bearbeiten' => $request->int('id')]);
    }

    public function rolleSpeichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $ergebnis = match (true) {
            $id !== null && !BenutzerService::darfVergeben(BenutzerService::rollenRechte($id))
                => ['ok' => false, 'message' => 'Diese Rolle hat Rechte, die du selbst nicht hast – ändern darf sie nur, wer sie alle hat.'],
            !BenutzerService::darfVergeben($request->arr('rechte'))
                => ['ok' => false, 'message' => 'Rechte vergeben darfst du nur, wenn du sie selbst hast.'],
            default => BenutzerService::rolleSpeichern($id, $request->str('name'), $request->str('description'), $request->arr('rechte')),
        };
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/rollen');
    }

    public function rolleLoeschen(Request $request): void
    {
        $ergebnis = BenutzerService::darfVergeben(BenutzerService::rollenRechte($request->int('id')))
            ? BenutzerService::rolleLoeschen($request->int('id'))
            : ['ok' => false, 'message' => 'Diese Rolle hat Rechte, die du selbst nicht hast – löschen darf sie nur, wer sie alle hat.'];
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/rollen');
    }
}
