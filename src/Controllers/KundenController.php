<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Kunden\Einrichtung;
use App\Kunden\KundenEinstellungen;
use App\Kunden\KundenService;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\EinmalMarke;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Kunden & Kundenbereich – die Kundenverwaltung des Personals.
 *
 * Ansehen mit „kunden.view“, pflegen mit „kunden.manage“ (prüft die Route).
 * Die Reiter Zugänge und Freigaben sieht nur, wer pflegen darf; Standorte
 * freigeben zusätzlich nur, wer alles sieht (KundenService). Jede Unter-ID
 * wird gegen den Kunden der Adresse geprüft – fremd ist nicht vorhanden.
 *
 * Ein Einrichtungslink oder vorläufiges Kennwort steht genau einmal da:
 * direkt in der Antwort auf das Anlegen (nicht zwischengespeichert), nie in
 * Flash, Sitzung, Weiterleitung oder Log. Gegen doppeltes Absenden trägt das
 * Formular „Neuer Zugang“ eine Einmal-Marke.
 */
final class KundenController extends Controller
{
    private const ZIEL = '/kunden';

    /** Die Reiter einer Kundenseite – und ob sie „kunden.manage“ brauchen. */
    public const REITER = [
        'uebersicht' => ['Übersicht', false],
        'stammdaten' => ['Stammdaten', false],
        'zugaenge'   => ['Zugänge', true],
        'freigaben'  => ['Freigaben', true],
    ];

    /** Zweck der Einmal-Marken (Formular „Neuer Zugang“). */
    private const EINMAL = 'kunden_zugang';

    /** Sitzungsschlüssel: Eingaben nach einem Fehler wieder ins Formular (nie ein Geheimnis). */
    private const EINGABE = 'kunden_eingabe';

    public function index(Request $request): void
    {
        $filter = ['suche' => mb_substr($request->str('suche'), 0, 100), 'aktiv' => in_array($request->str('aktiv'), ['1', '0'], true) ? $request->str('aktiv') : ''];
        $eingabe = $this->eingabe('neu');
        $this->view('kunden/index', [
            'kunden'  => KundenService::alle($filter),
            'filter'  => $filter,
            'eingabe' => $eingabe + ['name' => '', 'kundennummer' => '', 'gueltig_bis' => '', 'notizen' => '', 'aktiv' => 1],
        ]);
    }

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $daten = [
            'name'         => $request->str('name'),
            'kundennummer' => $request->str('kundennummer'),
            'aktiv'        => $request->bool('aktiv') ? 1 : 0,
            'gueltig_bis'  => $request->str('gueltig_bis'),
            'notizen'      => is_string($_POST['notizen'] ?? null) ? $_POST['notizen'] : '',
        ];
        $r = KundenService::speichern($id, $daten);
        if (!$r['ok']) {
            Flash::error($r['message']);
            Session::put(self::EINGABE, ['fuer' => $id === null ? 'neu' : (string) $id, 'form' => 'stammdaten'] + $daten);
            $this->redirect($id === null ? self::ZIEL . '#neuer-kunde' : self::ZIEL . '/' . $id . '?tab=stammdaten');
            return;
        }
        Flash::success($r['message']);
        $this->redirect(self::ZIEL . '/' . (int) $r['id'] . ($id === null ? '?tab=zugaenge' : '?tab=stammdaten'));
    }

    public function show(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $tab = array_key_exists($request->str('tab'), self::REITER) ? $request->str('tab') : 'uebersicht';
        $pflegen = Auth::can('kunden.manage');
        if (self::REITER[$tab][1] && !$pflegen) {
            Response::forbidden('Zugänge und Freigaben eines Kunden sieht nur, wer Kunden pflegen darf.');
            return;
        }
        $eingabe = $this->eingabe((string) $id);
        $daten = [
            'kunde'      => $kunde,
            'tab'        => $tab,
            'reiter'     => array_filter(self::REITER, static fn (array $r): bool => !$r[1] || $pflegen),
            'pflegen'    => $pflegen,
            'zahlen'     => KundenService::zahlen($id),
            'eingabe'    => $eingabe,
        ];
        if ($tab === 'uebersicht') {
            $daten['uebersicht'] = KundenService::uebersicht($id);
        } elseif ($tab === 'zugaenge') {
            $daten['zugaenge'] = KundenService::zugaenge($id);
            $daten['einmal'] = EinmalMarke::ausgeben(self::EINMAL);
            $daten['adresse'] = KundenEinstellungen::adresse();
        } elseif ($tab === 'freigaben') {
            $alles = BenutzerService::darfFreigabeVergeben('site', false);
            $daten['freigaben'] = KundenService::freigaben($id);
            $daten['darfFreigeben'] = $alles;
            $daten['standorte'] = $alles ? SiteService::all() : [];
        }
        $this->view('kunden/kunde', $daten);
    }

    public function loeschen(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $r = KundenService::loeschen((int) $kunde['id'], $request->str('bestaetigung'));
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect($r['ok'] ? self::ZIEL : self::ZIEL . '/' . (int) $kunde['id'] . '?tab=stammdaten#loeschen');
    }

    /** Neuer Zugang – das Geheimnis steht direkt in dieser Antwort (no-store). */
    public function zugangAnlegen(Request $request, array $params): void
    {
        header('Cache-Control: no-store');
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $daten = ['username' => $request->str('username'), 'full_name' => $request->str('full_name'), 'email' => $request->str('email')];
        $weg = $request->str('weg') === 'kennwort' ? 'kennwort' : 'link';
        if (!EinmalMarke::einloesen(self::EINMAL, is_string($_POST['einmal'] ?? null) ? $_POST['einmal'] : '')) {
            Flash::error('Dieser Zugang wurde schon angelegt – das Formular ging ein zweites Mal ab. Einen weiteren Zugang gibt es mit einem neuen Formular.');
            $this->redirect(self::ZIEL . '/' . $id . '?tab=zugaenge');
            return;
        }
        $r = KundenService::zugangAnlegen($id, $daten, $weg);
        if (!$r['ok']) {
            Flash::error($r['message']);
            Session::put(self::EINGABE, ['fuer' => (string) $id, 'form' => 'zugang'] + $daten + ['weg' => $weg]);
            $this->redirect(self::ZIEL . '/' . $id . '?tab=zugaenge#neuer-zugang');
            return;
        }
        $this->einmalig($kunde, $r);
    }

    /** Ein Zugang: aktion = speichern | link | kennwort | entsperren | loeschen. */
    public function zugang(Request $request, array $params): void
    {
        header('Cache-Control: no-store');
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $uid = ctype_digit((string) ($params['uid'] ?? '')) ? (int) $params['uid'] : 0;
        if (KundenService::zugang($id, $uid) === null) {
            Response::notFound('Diesen Zugang gibt es nicht.');
            return;
        }
        $aktion = $request->str('aktion');
        $r = match ($aktion) {
            'speichern'  => KundenService::zugangSpeichern($id, $uid, [
                'full_name' => $request->str('full_name'), 'email' => $request->str('email'),
                'is_active' => $request->bool('is_active'), 'gueltig_bis' => $request->str('gueltig_bis'),
            ]),
            'link'       => KundenService::zugangLink($id, $uid),
            'kennwort'   => KundenService::zugangKennwort($id, $uid),
            'entsperren' => KundenService::zugangEntsperren($id, $uid),
            'loeschen'   => KundenService::zugangLoeschen($id, $uid),
            default      => ['ok' => false, 'message' => 'Unbekannte Aktion.'],
        };
        if ($r['ok'] && in_array($aktion, ['link', 'kennwort'], true)) {
            $this->einmalig($kunde, $r + ['weg' => $aktion]);
            return;
        }
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . $id . '?tab=zugaenge');
    }

    public function freigabeSpeichern(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $daten = [
            'site_id'            => $request->int('site_id'),
            'gueltig_ab'         => $request->str('gueltig_ab'),
            'gueltig_bis'        => $request->str('gueltig_bis'),
            'daten_wie_gueltig'  => $request->bool('daten_wie_gueltig') ? 1 : 0,
            'daten_ab'           => $request->str('daten_ab'),
            'daten_bis'          => $request->str('daten_bis'),
            'notiz'              => $request->str('notiz'),
            'ueberschneidung_ok' => $request->bool('ueberschneidung_ok') ? 1 : 0,
        ];
        $r = KundenService::freigabeSpeichern($id, $daten);
        if (!$r['ok']) {
            Flash::error($r['message']);
            // Die Eingaben (keine Geheimnisse) und die Überschneidungen zurück ins Formular.
            Session::put(self::EINGABE, ['fuer' => (string) $id, 'form' => 'freigabe'] + $daten + ['ueberschneidungen' => $r['ueberschneidungen'] ?? []]);
            $this->redirect(self::ZIEL . '/' . $id . '?tab=freigaben#neue-freigabe');
            return;
        }
        Flash::success($r['message']);
        $this->redirect(self::ZIEL . '/' . $id . '?tab=freigaben');
    }

    public function freigabeLoeschen(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $fid = ctype_digit((string) ($params['fid'] ?? '')) ? (int) $params['fid'] : 0;
        if (KundenService::freigabe($id, $fid) === null) {
            Response::notFound('Diese Freigabe gibt es nicht.');
            return;
        }
        $r = KundenService::freigabeLoeschen($id, $fid);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . $id . '?tab=freigaben');
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * Der Kunde aus der Adresse – nur eine ganze Zahl, sonst und wenn es ihn
     * nicht gibt 404 (die Antwort ist dann gesendet, Rückgabe null).
     *
     * @return array<string,mixed>|null
     */
    private function kunde(array $params): ?array
    {
        $kunde = ctype_digit((string) ($params['id'] ?? '')) ? KundenService::find((int) $params['id']) : null;
        if ($kunde === null) {
            Response::notFound('Diesen Kunden gibt es nicht.');
        }
        return $kunde;
    }

    /**
     * Die Seite mit dem Geheimnis – nur in dieser Antwort, nicht
     * zwischengespeichert, nicht in Sitzung oder Adresse.
     *
     * @param array<string,mixed> $r Rückgabe von zugangAnlegen/zugangLink/zugangKennwort
     */
    private function einmalig(array $kunde, array $r): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        $adresse = KundenEinstellungen::adresse();
        $this->view('kunden/einmalig', [
            'kunde'            => $kunde,
            'ergebnis'         => $r,
            'anmeldung'        => $adresse !== null ? $adresse . '/login' : null,
            'unverschluesselt' => KundenEinstellungen::unverschluesselt($adresse),
        ]);
    }

    /**
     * Eingaben, die nach einem Fehler zurück ins Formular gehören – nur für
     * dieses Formular (neu bzw. Kunden-ID), und nur einmal.
     *
     * @return array<string,mixed>
     */
    private function eingabe(string $fuer): array
    {
        $eingabe = (array) Session::get(self::EINGABE, []);
        if (($eingabe['fuer'] ?? null) !== $fuer) {
            return [];
        }
        Session::forget(self::EINGABE);
        unset($eingabe['fuer']);
        return $eingabe;
    }
}
