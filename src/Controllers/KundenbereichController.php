<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Kunden\Kundenbereich;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Der Kundenbereich – die Seiten, die ein Kundenzugang sieht.
 *
 * Kein Recht an den Routen (Kundenzugänge haben keine); jede Aktion beginnt
 * mit kunde(): angemeldet, Kundenzugang, Kunde aktiv. Personal bekommt 403.
 * Was Kunden sehen, kommt aus Freigaben am Kunden und aus ausdrücklicher
 * Zuordnung – nie aus dem Standort allein. Eventworx wird von hier aus nie
 * gefragt.
 */
final class KundenbereichController extends Controller
{
    public function index(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $this->seite('kundenbereich/index', ['kunde' => $kunde]);
    }

    public function konto(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $this->seite('kundenbereich/konto', ['kunde' => $kunde, 'konto' => Auth::user()]);
    }

    /** Eigenes Kennwort ändern – derselbe Weg wie im Profil (Auth::kennwortAendern). */
    public function kennwort(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $ergebnis = Auth::kennwortAendern(
            (array) Auth::user(),
            (string) $request->input('current_password', ''),
            (string) $request->input('new_password', ''),
            (string) $request->input('new_password_repeat', '')
        );
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
            $this->redirect(Kundenbereich::KONTO);
            return;
        }
        Flash::success('Ihr Kennwort ist geändert.');
        $this->redirect(Kundenbereich::PFAD);
    }

    // ------------------------------------------------------------ intern

    /**
     * Der Kunde des angemeldeten Zugangs – oder null, dann ist die Antwort
     * schon gesendet: ohne Anmeldung (auch: Kunde inzwischen beendet, dann
     * meldet Auth::user() ab) zur Anmeldung, Personal 403.
     *
     * @return array<string,mixed>|null
     */
    private function kunde(Request $request): ?array
    {
        if (!Auth::check()) {
            $this->redirect('/login?next=' . rawurlencode($request->path()));
            return null;
        }
        if (!Kundenbereich::istKundenzugang()) {
            Response::forbidden('Der Kundenbereich ist nur für Kundenzugänge. Was ein Kunde sieht, zeigt die Kundenverwaltung.');
            return null;
        }
        $kunde = Kundenbereich::kunde();
        if ($kunde === null) {
            Response::notFound();
            return null;
        }
        return $kunde;
    }

    /** Eine Seite im Layout des Kundenbereichs – nie zwischengespeichert, kein Referer hinaus. */
    private function seite(string $template, array $daten): void
    {
        $daten['currentUser'] = Auth::user();
        $daten['flashes'] = Flash::pull();
        Response::html(View::render($template, $daten, 'layout/kundenbereich'), 200, Response::KUNDENBEREICH);
    }
}
