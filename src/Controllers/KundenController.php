<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Sso\Einstellungen as SsoEinstellungen;
use App\Auth\Sso\Konten as SsoKonten;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Kunden\Abrufe;
use App\Kunden\Einrichtung;
use App\Kunden\KundenEinstellungen;
use App\Kunden\KundenService;
use App\Kunden\Pruefnachweise;
use App\Services\BenutzerService;
use App\Services\Eventworx;
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
 *
 * Übergabeberichte gibt eine Person einem Kunden (berichtZuordnen, am
 * Bericht oder im Reiter Protokolle) – nie automatisch; weicht der Name im
 * Bericht ab, erst nach einer Rückfrage. Prüfnachweise (Reiter
 * Prüfnachweise) hinterlegt und gibt frei, wer „kunden.nachweise“ hat
 * (PruefnachweiseController).
 *
 * Eventworx-Vorgänge (Reiter Vorgänge): suchen und verknüpfen mit
 * „kunden.manage“ und „berichte.eventworx“ (die Suche zeigt alle Vorgänge
 * des Mandanten). Beides wartet auf Eventworx und hält dabei die Sitzung
 * nicht (ohneSitzungssperre). Die Treffer stehen direkt in der Antwort;
 * die Sitzung merkt sich nur ihre Kennungen, Nummern und Arten.
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
        'vorgaenge'  => ['Vorgänge', false],
        'protokolle' => ['Protokolle', false],
        'nachweise'  => ['Prüfnachweise', false],
        'abrufe'     => ['Abrufe', true],
    ];

    /** Zweck der Einmal-Marken (Formular „Neuer Zugang“). */
    private const EINMAL = 'kunden_zugang';

    /** Sitzungsschlüssel: Eingaben nach einem Fehler wieder ins Formular (nie ein Geheimnis; auch PruefnachweiseController). */
    public const EINGABE = 'kunden_eingabe';

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
        $this->seite($kunde, $request->str('tab'));
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

    /** Ein Zugang: aktion = speichern | link | kennwort | entsperren | sso_sitzungen | loeschen. */
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
            'sso_sitzungen' => KundenService::zugangSsoSitzungen($id, $uid),
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

    // ------------------------------------------------------------ Vorgänge (Eventworx)

    /**
     * Aufträge und Lieferscheine in Eventworx suchen (q). Die Treffer stehen
     * direkt in dieser Antwort (nicht zwischengespeichert); gemerkt werden
     * nur Kennung, Nummer und Art – Verknüpfen nimmt nur diese.
     */
    public function eventworxSuche(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $text = mb_substr($request->str('q'), 0, 100);
        [$r, $angemeldet] = $this->ohneSitzungssperre(static fn (): array => KundenService::eventworxSuchen($id, $text));
        if (!$angemeldet) {
            $this->abgemeldet();
            return;
        }
        if (!$r['ok']) {
            Flash::error($r['message']);
            $this->redirect(self::ZIEL . '/' . $id . '?tab=vorgaenge#eventworx-suche');
            return;
        }
        KundenService::trefferMerken($id, $r['treffer']);
        header('Cache-Control: no-store');
        $this->seite($kunde, 'vorgaenge', ['suche' => ['text' => $text, 'treffer' => $r['treffer'], 'message' => $r['message']]]);
    }

    /**
     * Einen Treffer der Suche verknüpfen: job_id (nur aus den gemerkten
     * Treffern), bestaetigt, stammdaten. Braucht es eine Bestätigung, steht
     * die Rückfrage – mit dem Namen aus Eventworx – direkt in der Antwort.
     */
    public function eventworxVerknuepfen(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $jobId = $request->str('job_id');
        $bestaetigt = $request->bool('bestaetigt');
        $stammdaten = $request->bool('stammdaten');
        [$r, $angemeldet] = $this->ohneSitzungssperre(static fn (): array => KundenService::vorgangVerknuepfen($id, $jobId, $bestaetigt, $stammdaten));
        if (!$angemeldet) {
            $this->abgemeldet();
            return;
        }
        if (!empty($r['bestaetigen'])) {
            header('Cache-Control: no-store');
            $treffer = KundenService::treffer($id)[$jobId] ?? ['nummer' => '', 'art' => ''];
            $this->seite(KundenService::find($id) ?? $kunde, 'vorgaenge', ['rueckfrage' => ['job_id' => $jobId, 'text' => $r['message'],
                'nummer' => $treffer['nummer'], 'stammdaten' => $stammdaten]]);
            return;
        }
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . $id . '?tab=vorgaenge');
    }

    public function vorgangLoesen(Request $request, array $params): void
    {
        $kunde = $this->kunde($params);
        if ($kunde === null) {
            return;
        }
        $id = (int) $kunde['id'];
        $vid = ctype_digit((string) ($params['vid'] ?? '')) ? (int) $params['vid'] : 0;
        if (KundenService::vorgang($id, $vid) === null) {
            Response::notFound('Diesen Vorgang gibt es nicht.');
            return;
        }
        $r = KundenService::vorgangLoesen($id, $vid);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . $id . '?tab=vorgaenge');
    }

    /**
     * Bericht ↔ Kunde: kunde_id (leer = aus dem Kundenbereich nehmen),
     * bestaetigt (bei abweichendem Namen), zurueck (Kunden-ID: zurück zum
     * Reiter Protokolle dieses Kunden; sonst zum Bericht). Der Bericht muss
     * für den Fragenden sichtbar sein (BerichtService::sichtbar).
     */
    public function berichtZuordnen(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $b = $id > 0 ? BerichtService::find($id) : null;
        if ($b === null || !BerichtService::sichtbar($b)) {
            Response::notFound('Diesen Bericht gibt es nicht.');
            return;
        }
        $zurueckRoh = $request->str('zurueck');
        $zurueck = ctype_digit($zurueckRoh) && KundenService::find((int) $zurueckRoh) !== null ? (int) $zurueckRoh : null;
        $ziel = $zurueck !== null ? self::ZIEL . '/' . $zurueck . '?tab=protokolle' : '/berichte/' . $id . '#kundenbereich';
        $roh = $request->str('kunde_id');
        if ($roh !== '' && !ctype_digit($roh)) {
            Flash::error('Bitte einen Kunden wählen.');
            $this->redirect($ziel);
            return;
        }
        $kundeId = $roh !== '' && (int) $roh > 0 ? (int) $roh : null;
        $r = BerichtService::kundeZuordnen($id, $kundeId, $request->bool('bestaetigt'));
        if (!empty($r['bestaetigen']) && $kundeId !== null) {
            KundenService::rueckfrageMerken($id, $kundeId, $r['message'], $zurueck);
            Flash::warning($r['message']);
        } else {
            $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        }
        $this->redirect($ziel);
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * Eine Kundenseite mit einem Reiter (Liste geprüft; ohne „kunden.manage“
     * nur die Reiter ohne Zugänge, Freigaben, Abrufe). $extra kommt dazu –
     * Treffer einer Suche, eine Rückfrage –, nur für diese Antwort.
     *
     * @param array<string,mixed> $kunde
     * @param array<string,mixed> $extra
     */
    private function seite(array $kunde, string $tab, array $extra = []): void
    {
        $id = (int) $kunde['id'];
        $tab = array_key_exists($tab, self::REITER) ? $tab : 'uebersicht';
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
            $daten['darfAusgeben'] = KundenService::darfZugangAusgeben($id);
            $daten['einmal'] = EinmalMarke::ausgeben(self::EINMAL);
            $daten['adresse'] = KundenEinstellungen::adresse();
            // Kundengruppen der Anmeldung über SSO (017): sehen mit „Kunden pflegen“,
            // ändern nur mit „SSO einrichten“ (die Routen unter /system/sso).
            $gruppen = SsoKonten::kundengruppen($id);
            $daten['sso'] = [
                'zeigen'  => $gruppen !== [] || SsoEinstellungen::eingerichtet() || array_filter(array_column($daten['zugaenge'], 'sso')) !== [],
                'name'    => SsoEinstellungen::name(),
                'an'      => SsoEinstellungen::aktiv() && SsoEinstellungen::schalter(SsoEinstellungen::KUNDEN),
                'kunden'  => SsoEinstellungen::schalter(SsoEinstellungen::KUNDEN),
                'gruppen' => $gruppen,
                'pflegen' => Auth::can('sso.manage'),
            ];
        } elseif ($tab === 'freigaben') {
            $alles = BenutzerService::darfFreigabeVergeben('site', false);
            $daten['freigaben'] = KundenService::freigaben($id);
            $daten['darfFreigeben'] = $alles;
            $daten['standorte'] = $alles ? SiteService::all() : [];
        } elseif ($tab === 'vorgaenge') {
            $daten['vorgaenge'] = KundenService::vorgaenge($id);
            $daten['darfSuchen'] = $pflegen && Auth::can('berichte.eventworx');
            $daten['darfNachweise'] = Auth::can('kunden.nachweise');
            $daten['eingerichtet'] = Eventworx::eingerichtet();
            $daten['links'] = Auth::can('berichte.eventworx');
            $daten['suche'] = $extra['suche'] ?? null;
            $daten['rueckfrage'] = $extra['rueckfrage'] ?? null;
        } elseif ($tab === 'protokolle') {
            $daten['protokolle'] = KundenService::protokolle($id);
            $daten['rueckfrage'] = $pflegen ? KundenService::rueckfrage(null, $id) : null;
        } elseif ($tab === 'nachweise') {
            $darf = Auth::can('kunden.nachweise');
            $daten['nachweise'] = Pruefnachweise::fuerPersonal($id);
            $daten['darfNachweise'] = $darf;
            $daten['standorte'] = $darf ? Pruefnachweise::standorte($id) : [];
            $daten['vorgaenge'] = $darf ? Pruefnachweise::vorgaenge($id) : [];
            $daten['inventar'] = $darf ? Pruefnachweise::inventar() : [];
            $daten['eingerichtet'] = Eventworx::eingerichtet();
        } elseif ($tab === 'abrufe') {
            $daten['abrufe'] = Abrufe::fuerKunde($id);
        }
        $this->view('kunden/kunde', $daten);
    }

    /** Während des Wartens abgemeldet (oder Kennwort geändert): zur Anmeldung, ohne etwas zu zeigen. */
    private function abgemeldet(): void
    {
        Flash::warning('Sitzung beendet – bitte neu anmelden.');
        $this->redirect('/login');
    }

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
