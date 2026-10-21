<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Berichte\Einstellungen;
use App\Kunden\Abrufe;
use App\Kunden\Einrichtung;
use App\Kunden\KundenEinstellungen;
use App\Kunden\Kundenbereich;
use App\Kunden\KundenSicht;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Flash;
use App\Support\Markdown;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Der Kundenbereich – die Seiten, die ein Kundenzugang sieht.
 *
 * Kein Recht an den Routen (Kundenzugänge haben keine); jede Aktion beginnt
 * mit kunde(): angemeldet, Kundenzugang, Kunde aktiv. Personal bekommt 403.
 * Was Kunden sehen, kommt aus Freigaben am Kunden und aus ausdrücklicher
 * Zuordnung – nie aus dem Standort allein: jede ID aus der Adresse geht über
 * KundenSicht gegen den Kunden, fremd und nicht vorhanden geben dieselbe
 * 404. Eventworx wird von hier aus nie gefragt; nichts hier wartet auf
 * andere Dienste – nur Pläne und PDFs geben die Sitzung frei, bevor sie
 * gesendet werden (große Dateien über ein schwaches Mobilnetz).
 *
 * Protokolle sind die Übergabeberichte, die dem Kunden ausdrücklich
 * zugeordnet und abgeschlossen sind; ihr PDF nur in den beim Abschluss
 * gespeicherten Bytes. Prüfnachweise (DGUV V3) sind die freigegebenen
 * Nachweise des Kunden; ihr PDF geht als Datei hinaus (fremdes Dokument).
 * Jeder Download steht in den Abrufen (App\Kunden\Abrufe).
 */
final class KundenbereichController extends Controller
{
    /** Die Hilfeseite – Markdown aus dem eigenen Haus (App\Support\Markdown maskiert trotzdem alles). */
    private const HILFE = '/resources/hilfe/kundenbereich.md';

    public function index(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $this->seite('kundenbereich/index', [
            'kunde'     => $kunde,
            'locations' => KundenSicht::locations($id),
            'kommend'   => KundenSicht::kommend($id),
            'berichte'  => KundenSicht::berichte($id),
            'nachweise' => KundenSicht::nachweise($id),
            'kontakt'   => KundenEinstellungen::kontakt(),
        ]);
    }

    public function locations(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $this->seite('kundenbereich/locations', [
            'kunde'     => $kunde,
            'locations' => KundenSicht::locations($id),
            'kommend'   => KundenSicht::kommend($id),
        ]);
    }

    public function location(Request $request, array $params): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $siteId = self::id($params, 'id');
        $location = $siteId === null ? null : KundenSicht::location((int) $kunde['id'], $siteId);
        if ($location === null) {
            Response::notFound();
            return;
        }
        $this->seite('kundenbereich/location', ['kunde' => $kunde, 'location' => $location]);
    }

    /** Der Plan einer Etage: Bild im Browser, PDF als Datei (SiteService::planAusliefern). */
    public function plan(Request $request, array $params): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $siteId = self::id($params, 'id');
        $floorId = self::id($params, 'etage');
        $etage = $siteId === null || $floorId === null ? null : KundenSicht::etage((int) $kunde['id'], $siteId, $floorId);
        if ($etage === null) {
            Response::notFound();
            return;
        }
        // Ein großer Plan über ein schwaches Mobilnetz dauert – solange soll
        // die Sitzung keine andere Seite desselben Kunden aufhalten.
        Session::freigeben();
        if (!SiteService::planAusliefern($etage, true)) {
            Response::notFound();
        }
    }

    /** Die Protokolle des Kunden – je Nummer die gültige Fassung, frühere darunter. */
    public function protokolle(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $this->seite('kundenbereich/protokolle', ['kunde' => $kunde, 'berichte' => KundenSicht::berichte((int) $kunde['id'])]);
    }

    /** Ein Protokoll zum Lesen – wie im PDF, ohne Lebenslauf, Prüfnachweis der Integrität und Knöpfe des Personals. */
    public function protokoll(Request $request, array $params): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $id = self::id($params, 'id');
        $protokoll = $id === null ? null : KundenSicht::bericht((int) $kunde['id'], $id);
        if ($protokoll === null) {
            Response::notFound();
            return;
        }
        $this->seite('kundenbereich/protokoll', ['kunde' => $kunde] + $protokoll);
    }

    /**
     * Das PDF eines Protokolls: nur die gespeicherten Bytes (nie eine
     * Vorschau), im Browser zum Ansehen; der Abruf wird festgehalten.
     */
    public function protokollPdf(Request $request, array $params): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $id = self::id($params, 'id');
        $pdf = $id === null ? null : KundenSicht::protokollPdf((int) $kunde['id'], $id);
        if ($pdf === null) {
            Response::notFound();
            return;
        }
        Abrufe::merken((int) $kunde['id'], 'bericht', (int) $id, $pdf['sha256']);
        Session::freigeben();
        Response::pdf($pdf['name'], $pdf['bytes']);
    }

    /**
     * Die Prüfnachweise (DGUV V3) der Geräte des Kunden – freigegebene mit
     * Datei, wahlweise nur die einer Location (?location=…, nur eine der
     * Locations aus der Liste selbst; alles andere heißt „alle“).
     */
    public function nachweise(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $alle = KundenSicht::nachweise((int) $kunde['id']);
        $orte = [];
        foreach ($alle as $n) {
            if ($n['site_id'] !== null && $n['standort'] !== '') {
                $orte[$n['site_id']] = $n['standort'];
            }
        }
        natcasesort($orte);
        $roh = $request->str('location');
        $ort = preg_match('/^[1-9]\d{0,17}$/', $roh) === 1 && isset($orte[(int) $roh]) ? (int) $roh : null;
        $this->seite('kundenbereich/nachweise', [
            'kunde'     => $kunde,
            'nachweise' => $ort === null ? $alle : array_values(array_filter($alle, static fn (array $n): bool => $n['site_id'] === $ort)),
            'zahlen'    => KundenSicht::nachweisZahlen($alle),
            'orte'      => $orte,
            'ort'       => $ort,
        ]);
    }

    /**
     * Das PDF eines Prüfnachweises – als Datei (es stammt nicht von uns,
     * also nicht im Browser), unter neutralem Namen; der Abruf wird
     * festgehalten.
     */
    public function nachweisPdf(Request $request, array $params): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $id = self::id($params, 'id');
        $pdf = $id === null ? null : KundenSicht::nachweisPdf((int) $kunde['id'], $id);
        if ($pdf === null) {
            Response::notFound();
            return;
        }
        Abrufe::merken((int) $kunde['id'], 'nachweis', (int) $id, $pdf['sha256']);
        Session::freigeben();
        Response::pdf($pdf['name'], $pdf['bytes'], true);
    }

    public function konto(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $this->seite('kundenbereich/konto', ['kunde' => $kunde, 'konto' => Auth::user(), 'datenschutz' => KundenEinstellungen::datenschutz()]);
    }

    public function hilfe(Request $request): void
    {
        $kunde = $this->kunde($request);
        if ($kunde === null) {
            return;
        }
        $datei = dirname(__DIR__, 2) . self::HILFE;
        $this->seite('kundenbereich/hilfe', [
            'kunde'       => $kunde,
            'hilfe'       => is_file($datei) ? Markdown::toHtml((string) file_get_contents($datei)) : '',
            'datenschutz' => KundenEinstellungen::datenschutz(),
            'kontakt'     => KundenEinstellungen::kontakt(),
        ]);
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

    /**
     * Eine ID aus der Adresse – nur Ziffern (kein „7abc“, kein „-1“, nichts
     * über 18 Stellen); sonst null, und die Seite ist „nicht gefunden“.
     */
    private static function id(array $params, string $schluessel): ?int
    {
        $wert = (string) ($params[$schluessel] ?? '');
        return preg_match('/^[1-9]\d{0,17}$/', $wert) === 1 ? (int) $wert : null;
    }

    /** Eine Seite im Layout des Kundenbereichs – nie zwischengespeichert, kein Referer hinaus. */
    private function seite(string $template, array $daten): void
    {
        $daten['currentUser'] = Auth::user();
        $daten['flashes'] = Flash::pull();
        Response::html(View::render($template, $daten, 'layout/kundenbereich'), 200, Response::KUNDENBEREICH);
    }
}
