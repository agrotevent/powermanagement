<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Kunden\KundenService;
use App\Kunden\Pruefnachweise;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Prüfnachweise (DGUV V3) eines Kunden – Reiter „Prüfnachweise“ der
 * Kundenverwaltung (die Seite selbst baut KundenController::show).
 *
 * Hochladen, korrigieren, freigeben/zurückziehen und löschen mit
 * „kunden.nachweise“, die Datei ansehen mit „kunden.view“ (prüft die Route).
 * Jede {nid} wird gegen den Kunden {id} geprüft – fremd ist nicht vorhanden.
 * Der Kunde sieht einen Nachweis erst, wenn eine Person ihn freigibt.
 */
final class PruefnachweiseController extends Controller
{
    private const ZIEL = '/kunden/';

    /** Die Angaben, die das Formular schickt – Felder, die es nicht schickt, bleiben weg. */
    private const FELDER = ['bezeichnung', 'inventarnummer', 'seriennummer', 'norm', 'ergebnis', 'geprueft_am', 'naechste_pruefung',
        'einsatz_von', 'einsatz_bis', 'hinweis', 'site_id', 'vorgang_id', 'inventar_id'];

    /** Neuer Nachweis: PDF (Pflicht, höchstens 10 MB) und Angaben; „freigeben“ zeigt ihn dem Kunden sofort. */
    public function hochladen(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $angaben = $this->angaben($request);
        $freigeben = $request->bool('freigeben');
        $r = Pruefnachweise::pdfHochladen($id, is_array($_FILES['datei'] ?? null) ? $_FILES['datei'] : [], $angaben, $freigeben);
        if (!$r['ok']) {
            Flash::error($r['message']);
            // Die Angaben zurück ins Formular – die Datei wählt man neu.
            Session::put(KundenController::EINGABE, ['fuer' => (string) $id, 'form' => 'nachweis'] + $angaben + ['freigeben' => $freigeben ? 1 : 0]);
            $this->redirect(self::ZIEL . $id . '?tab=nachweise#neuer-nachweis');
            return;
        }
        Flash::success($r['message']);
        $this->redirect(self::ZIEL . $id . '?tab=nachweise#nachweis-' . (int) $r['id']);
    }

    /** Angaben korrigieren (die Datei bleibt). */
    public function speichern(Request $request, array $params): void
    {
        [$kunde, $n] = $this->nachweis($params);
        if ($n === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $nid = (int) $n['id'];
        $angaben = $this->angaben($request);
        $r = Pruefnachweise::speichern($id, $nid, $angaben);
        if (!$r['ok']) {
            Flash::error($r['message']);
            Session::put(KundenController::EINGABE, ['fuer' => (string) $id, 'form' => 'nachweis-' . $nid] + $angaben);
        } else {
            Flash::success($r['message']);
        }
        $this->redirect(self::ZIEL . $id . '?tab=nachweise#nachweis-' . $nid);
    }

    /** status = freigegeben | zurueckgezogen. */
    public function status(Request $request, array $params): void
    {
        [$kunde, $n] = $this->nachweis($params);
        if ($n === null) {
            return;
        }
        $r = Pruefnachweise::status((int) $kunde['id'], (int) $n['id'], $request->str('status'));
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . (int) $kunde['id'] . '?tab=nachweise#nachweis-' . (int) $n['id']);
    }

    public function loeschen(Request $request, array $params): void
    {
        [$kunde, $n] = $this->nachweis($params);
        if ($n === null) {
            return;
        }
        $r = Pruefnachweise::loeschen((int) $kunde['id'], (int) $n['id']);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . (int) $kunde['id'] . '?tab=nachweise');
    }

    /**
     * Die hinterlegte Datei – als Anhang (ein fremdes PDF), Prüfsumme
     * nachgerechnet. Personal-Abrufe zählen nicht zu den Abrufen des Kunden.
     */
    public function datei(Request $request, array $params): void
    {
        [, $n] = $this->nachweis($params);
        if ($n === null) {
            return;
        }
        $datei = Pruefnachweise::datei($n);
        if ($datei === null) {
            Response::notFound('Die Datei dieses Nachweises fehlt oder ist beschädigt (bin/power kunden:nachweise-pruefen).');
            return;
        }
        Session::freigeben();
        Response::pdf($datei['name'], $datei['bytes'], true);
    }

    // ------------------------------------------------------------ Hilfen

    /** @return array<string,string> */
    private function angaben(Request $request): array
    {
        $out = [];
        foreach (self::FELDER as $feld) {
            if (array_key_exists($feld, $_POST)) {
                $out[$feld] = $request->str($feld);
            }
        }
        return $out;
    }

    /**
     * Kunde und Nachweis aus der Adresse – sonst 404 (dann ist die Antwort
     * gesendet und der Nachweis null).
     *
     * @return array{0:?array<string,mixed>,1:?array<string,mixed>}
     */
    private function nachweis(array $params): array
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return [null, null];
        }
        $nid = (string) ($params['nid'] ?? '');
        $n = ctype_digit($nid) ? Pruefnachweise::vonKunde((int) $kunde['id'], (int) $nid) : null;
        if ($n === null) {
            Response::notFound('Diesen Prüfnachweis gibt es nicht.');
        }
        return [$kunde, $n];
    }

    /** @return array<string,mixed>|null */
    private function kunde(array $params): ?array
    {
        $kunde = ctype_digit((string) ($params['id'] ?? '')) ? KundenService::find((int) $params['id']) : null;
        if ($kunde === null) {
            Response::notFound('Diesen Kunden gibt es nicht.');
        }
        return $kunde;
    }
}
