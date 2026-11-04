<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;

/**
 * Prüflinge der Geräteprüfung pflegen: Liste mit Filtern, Formular,
 * Detail mit Prüfungsverlauf, Löschen nur ohne Prüfungen.
 *
 * Vorläufig (Fundament): jede Methode antwortet mit „Noch nicht verfügbar“.
 */
final class PrueflingeController extends Controller
{
    public function index(Request $request): void
    {
        $this->bald('Prüflinge');
    }

    public function neu(Request $request): void
    {
        $this->bald('Neuer Prüfling');
    }

    public function speichern(Request $request): void
    {
        $this->bald('Prüfling speichern');
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): void
    {
        $this->bald('Prüfling');
    }

    /** @param array<string,string> $params */
    public function bearbeiten(Request $request, array $params): void
    {
        $this->bald('Prüfling bearbeiten');
    }

    /** @param array<string,string> $params */
    public function loeschen(Request $request, array $params): void
    {
        $this->bald('Prüfling löschen');
    }

    private function bald(string $seite): void
    {
        $this->view('geraetepruefung/_bald', ['seite' => $seite]);
    }
}
