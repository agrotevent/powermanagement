<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;

/**
 * Übersicht der Geräteprüfung (DGUV V3): fällige und überfällige Prüflinge,
 * offene (nicht unterschriebene) Prüfungen, letzte Importe.
 *
 * Vorläufig (Fundament): jede Methode antwortet mit „Noch nicht verfügbar“.
 */
final class GeraetepruefungController extends Controller
{
    public function index(Request $request): void
    {
        $this->bald('Übersicht');
    }

    private function bald(string $seite): void
    {
        $this->view('geraetepruefung/_bald', ['seite' => $seite]);
    }
}
