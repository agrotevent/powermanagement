<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Berichte\Pruefgeraete;
use App\Support\Flash;
use App\Support\Request;

/**
 * Prüf- und Messgeräte für die Übergabeberichte (Installationstester,
 * Erdungsmesser). Ansehen mit „Übergabeberichte ansehen“, pflegen mit
 * „ausfüllen“. Gelöscht wird nicht, nur stillgelegt.
 */
final class PruefgeraeteController extends Controller
{
    public function index(Request $request): void
    {
        $darf = Auth::can('berichte.manage');
        $bearbeiten = $darf && $request->int('id') > 0 ? Pruefgeraete::find($request->int('id')) : null;
        $this->view('berichte/pruefgeraete', [
            'liste'  => Pruefgeraete::liste(),
            'darf'   => $darf,
            'geraet' => $bearbeiten ?? ($darf && $request->bool('neu')
                ? ['id' => 0, 'fabrikat' => '', 'typ' => '', 'seriennummer' => '', 'norm' => Pruefgeraete::NORM, 'kalibriert_bis' => '', 'aktiv' => 1, 'notizen' => '']
                : null),
            'heute'  => (new \DateTimeImmutable('now', \App\Support\Zeit::zone()))->format('Y-m-d'),
        ]);
    }

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $e = Pruefgeraete::speichern($id, [
            'fabrikat'       => $request->str('fabrikat'),
            'typ'            => $request->str('typ'),
            'seriennummer'   => $request->str('seriennummer'),
            'norm'           => $request->str('norm'),
            'kalibriert_bis' => $request->str('kalibriert_bis'),
            'aktiv'          => $request->bool('aktiv'),
            'notizen'        => $request->str('notizen'),
        ]);
        if (!$e['ok']) {
            Flash::error($e['message']);
            $this->redirect('/berichte/pruefgeraete?' . ($id !== null ? 'id=' . $id : 'neu=1'));
            return;
        }
        Flash::success($e['message']);
        $this->redirect('/berichte/pruefgeraete');
    }
}
