<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Berichte\Einstellungen;
use App\Kunden\Einrichtung;
use App\Kunden\KundenEinstellungen;
use App\Kunden\Kundenbereich;
use App\Support\Config;
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

    // ------------------------------------------------------------ Einrichtungslink

    /**
     * Einrichtungslink öffnen – ohne Anmeldung, das Token ist die
     * Berechtigung (einmal, mit Ablauf). Für jeden ungültigen Link dieselbe
     * 404-Seite. Wer angemeldet ist, bekommt nur einen Hinweis: ein Link soll
     * nie in eine offene Sitzung hinein ein Kennwort setzen.
     */
    public function einrichten(Request $request, array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        if (Auth::check()) {
            $this->einrichtenSeite('angemeldet', null, $token, 200);
            return;
        }
        $konto = Einrichtung::finden($token);
        $konto === null
            ? $this->einrichtenSeite('ungueltig', null, $token, 404)
            : $this->einrichtenSeite('formular', $konto, $token, 200);
    }

    /** Kennwort festlegen (POST mit CSRF-Token) – danach zur Anmeldung, kein Auto-Login. */
    public function einrichtenSpeichern(Request $request, array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        if (Auth::check()) {
            $this->einrichtenSeite('angemeldet', null, $token, 200);
            return;
        }
        if (Einrichtung::finden($token) === null) {
            $this->einrichtenSeite('ungueltig', null, $token, 404);
            return;
        }
        // Kennwörter roh: Leerzeichen am Rand gehören dazu.
        $r = Einrichtung::einloesen(
            $token,
            is_string($_POST['kennwort'] ?? null) ? $_POST['kennwort'] : '',
            is_string($_POST['wiederholung'] ?? null) ? $_POST['wiederholung'] : ''
        );
        if (!$r['ok']) {
            Flash::error($r['message']);
            // finden() hat das Format geprüft – es ist dieselbe Adresse.
            $this->redirect(Einrichtung::pfad($token));
            return;
        }
        Flash::success($r['message']);
        $this->redirect('/login');
    }

    /** Die Seite zum Einrichtungslink im Layout „blank“ – nie zwischengespeichert, kein Referer hinaus. */
    private function einrichtenSeite(string $zustand, ?array $konto, string $token, int $status): void
    {
        $firma = Einstellungen::wert('firma.name');
        $html = View::render('kundenbereich/einrichten', [
            'zustand'     => $zustand,
            'konto'       => $konto,
            'aktion'      => $zustand === 'formular' ? Einrichtung::pfad($token) : '',
            'datenschutz' => KundenEinstellungen::datenschutz(),
            'absender'    => $firma !== '' ? $firma : (string) Config::get('app.name', 'PowerManagement'),
            'flashes'     => Flash::pull(),
        ], 'layout/blank');
        Response::html($html, $status, Response::KUNDENBEREICH);
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
