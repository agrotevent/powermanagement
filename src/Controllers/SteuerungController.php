<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Messung\Steuerung;
use App\Support\Request;

final class SteuerungController extends Controller
{
    /**
     * Einen Schaltbefehl ausführen. Die Prüfung (Recht, Sperre, Freigabe
     * für genau dieses Gerät und diesen Kanal) macht Steuerung::ausfuehren –
     * hier wird nichts vorab erlaubt.
     */
    public function schalten(Request $request): void
    {
        $ergebnis = Steuerung::ausfuehren(
            $request->int('quelle'),
            $request->str('befehl'),
            $request->str('kanal'),
            $request->int('objekt') ?: null
        );
        $this->antwort($request, $ergebnis, $_SERVER['HTTP_REFERER'] ?? '/dashboard');
    }

    public function protokoll(Request $request): void
    {
        $this->view('steuerung/protokoll', ['eintraege' => Steuerung::protokollLesen(300)]);
    }
}
