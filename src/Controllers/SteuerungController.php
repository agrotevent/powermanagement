<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Messung\Steuerung;
use App\Support\Request;
use App\Support\Session;

final class SteuerungController extends Controller
{
    /**
     * Einen Schaltbefehl ausführen. Die Prüfung (Recht, Sperre, Freigabe
     * für genau dieses Gerät und diesen Kanal) macht Steuerung::ausfuehren –
     * hier wird nichts vorab erlaubt.
     *
     * Auf die Antwort des Geräts (Shelly bis 5 s, ComAp bis 20 s, Modbus)
     * wird gewartet, ohne die Sitzung zu halten; Meldung oder JSON danach.
     */
    public function schalten(Request $request): void
    {
        $ergebnis = Session::ohneSperre(static fn (): array => Steuerung::ausfuehren(
            $request->int('quelle'),
            $request->str('befehl'),
            $request->str('kanal'),
            $request->int('objekt') ?: null
        ));
        $this->antwort($request, $ergebnis, \App\Support\Response::zurueckZiel('/dashboard'));
    }

    public function protokoll(Request $request): void
    {
        $this->view('steuerung/protokoll', ['eintraege' => Steuerung::protokollLesen(300)]);
    }
}
