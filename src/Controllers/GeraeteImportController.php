<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;

/**
 * Datenbank des Prüfgeräts importieren (Vorschau, übernehmen, verwerfen),
 * als Datei für das Prüfgerät exportieren und die Prüfabläufe pflegen.
 *
 * Vorläufig (Fundament): jede Methode antwortet mit „Noch nicht verfügbar“.
 */
final class GeraeteImportController extends Controller
{
    public function index(Request $request): void
    {
        $this->bald('Import');
    }

    public function vorschau(Request $request): void
    {
        $this->bald('Import: Vorschau');
    }

    public function uebernehmen(Request $request): void
    {
        $this->bald('Import: übernehmen');
    }

    public function verwerfen(Request $request): void
    {
        $this->bald('Import: verwerfen');
    }

    public function export(Request $request): void
    {
        $this->bald('Export');
    }

    public function exportieren(Request $request): void
    {
        $this->bald('Export');
    }

    public function pruefablaeufe(Request $request): void
    {
        $this->bald('Prüfabläufe');
    }

    /** @param array<string,string> $params */
    public function pruefablaufSpeichern(Request $request, array $params): void
    {
        $this->bald('Prüfablauf speichern');
    }

    private function bald(string $seite): void
    {
        $this->view('geraetepruefung/_bald', ['seite' => $seite]);
    }
}
