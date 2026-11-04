<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;

/**
 * Prüfungen der Geräteprüfung und ihre Prüfberichte: Liste und Detail,
 * Unterschreiben (auch gesammelt), Korrektur, Prüfnachweis an einen Kunden,
 * das gespeicherte PDF.
 *
 * Vorläufig (Fundament): jede Methode antwortet mit „Noch nicht verfügbar“.
 */
final class PruefberichteController extends Controller
{
    public function index(Request $request): void
    {
        $this->bald('Prüfungen');
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): void
    {
        $this->bald('Prüfung');
    }

    public function unterschreiben(Request $request): void
    {
        $this->bald('Unterschreiben');
    }

    /** @param array<string,string> $params */
    public function korrektur(Request $request, array $params): void
    {
        $this->bald('Korrektur');
    }

    /** @param array<string,string> $params */
    public function nachweis(Request $request, array $params): void
    {
        $this->bald('Als Prüfnachweis an einen Kunden');
    }

    /** @param array<string,string> $params */
    public function pdf(Request $request, array $params): void
    {
        $this->bald('Prüfbericht (PDF)');
    }

    private function bald(string $seite): void
    {
        $this->view('geraetepruefung/_bald', ['seite' => $seite]);
    }
}
