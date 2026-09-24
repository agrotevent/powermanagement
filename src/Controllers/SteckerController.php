<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnlagenService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;

final class SteckerController extends Controller
{
    public function index(Request $request): void
    {
        $typen = AnlagenService::steckertypen();
        foreach ($typen as &$t) {
            $t['benutzt'] = (int) Database::scalar('SELECT COUNT(*) FROM anschluesse WHERE steckertyp_id = ?', [(int) $t['id']]);
        }
        $this->view('stecker/index', ['typen' => $typen]);
    }

    public function speichern(Request $request): void
    {
        $ergebnis = AnlagenService::steckertypSpeichern($request->int('id') ?: null, $_POST);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/stecker');
    }

    public function loeschen(Request $request): void
    {
        $ergebnis = AnlagenService::steckertypLoeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/stecker');
    }
}
