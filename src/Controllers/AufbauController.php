<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\LeitungService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

/**
 * Aufbau-Modus und Abgleich Planung ↔ Umsetzung.
 *
 * Sehen darf einen Aufbau, wer den Standort sieht. Starten, fertig melden
 * und beenden darf, wer auf dem Standort Anlagen anlegen darf. Eine Anlage
 * bestätigen darf, wer sie bearbeiten darf; ein Kabel, wer Kabel zeichnen
 * darf.
 */
final class AufbauController extends Controller
{
    private function aufbau(array $params, bool $steuern = false): ?array
    {
        $a = AufbauService::find((int) ($params['id'] ?? 0));
        if ($a === null || !Zugriff::siehtStandort((int) $a['site_id'])) {
            Response::notFound();
            return null;
        }
        if ($steuern && !Zugriff::darfAufStandortAnlegen((int) $a['site_id'])) {
            Response::forbidden('Diesen Aufbau darfst du nicht steuern.');
            return null;
        }
        return $a;
    }

    /** Die Übergabeberichte des Aufbaus für die Karte in Aufbau und Abgleich. */
    private function berichte(array $a): array
    {
        if (!Auth::can('berichte.view')) {
            return ['berichte' => null];
        }
        $am = Database::scalar('SELECT MAX(am) FROM aufbau_posten WHERE aufbau_id = ?', [(int) $a['id']]);
        return [
            'berichte'        => BerichtService::liste(['aufbau_id' => (int) $a['id']]),
            'berichtNeu'      => Auth::can('berichte.manage') && Zugriff::darfStandortAendern((int) $a['site_id']),
            'aufbauGeaendert' => is_string($am) ? $am : null,
        ];
    }

    public function index(Request $request): void
    {
        $standorte = array_values(array_filter(SiteService::all(), static fn ($s) => Zugriff::darfAufStandortAnlegen((int) $s['id'])));
        $liste = AufbauService::liste();
        $fortschritt = [];
        foreach ($liste as $a) {
            if ($a['status'] === 'aufbau') {
                $fortschritt[(int) $a['id']] = AufbauService::uebersicht(AufbauService::find((int) $a['id']))['zaehler'];
            }
        }
        $this->view('aufbau/index', [
            'liste'       => $liste,
            'fortschritt' => $fortschritt,
            'standorte'   => array_values(array_filter($standorte, static fn ($s) => AufbauService::aktiv((int) $s['id']) === null)),
        ]);
    }

    public function starten(Request $request): void
    {
        $siteId = $request->int('site_id');
        if (!Zugriff::darfAufStandortAnlegen($siteId ?: null)) {
            Response::forbidden('Auf diesem Standort darfst du keinen Aufbau starten.');
            return;
        }
        $ergebnis = AufbauService::starten($siteId, $request->str('name'));
        $this->antwort($request, $ergebnis, $ergebnis['ok'] ? '/aufbau/' . (int) $ergebnis['id'] : '/aufbau');
    }

    public function show(Request $request, array $params): void
    {
        $a = $this->aufbau($params);
        if ($a === null) {
            return;
        }
        $etage = null;
        foreach (SiteService::floors((int) $a['site_id']) as $f) {
            $etage ??= (int) $f['id'];
        }
        $this->view('aufbau/show', [
            'aufbau'     => $a,
            'uebersicht' => AufbauService::uebersicht($a),
            'filter'     => $request->str('zeigen'),
            'etage'      => $etage,
            'darf'       => Zugriff::darfAufStandortAnlegen((int) $a['site_id']),
            'darfKabel'  => Auth::can('leitungen.manage'),
        ] + $this->berichte($a));
    }

    /** Eine Position bestätigen, ändern, entfallen lassen oder zurücknehmen. */
    public function posten(Request $request, array $params): void
    {
        $a = $this->aufbau($params);
        if ($a === null) {
            return;
        }
        $art = $request->str('art') === 'leitung' ? 'leitung' : 'objekt';
        $refId = $request->int('ref_id');
        // Ein Kabel wie auf der Karte: mit „Kabel zeichnen“ und „bearbeiten“
        // am Standort oder an einer Anlage an einem seiner Enden.
        $l = $art === 'leitung' ? LeitungService::find($refId) : null;
        $darf = $art === 'leitung'
            ? Auth::can('leitungen.manage') && (Zugriff::darfStandortAendern((int) $a['site_id'])
                || array_filter([$l['von_objekt_id'] ?? null, $l['zu_objekt_id'] ?? null], static fn ($id) => $id !== null
                    && ($o = AnlagenService::find((int) $id)) !== null && Zugriff::bearbeitetObjekt($o)) !== [])
            : (($o = AnlagenService::find($refId)) !== null ? Zugriff::darfBearbeiten($o) : Zugriff::darfAufStandortAnlegen((int) $a['site_id']));
        if (!$darf) {
            if ($request->wantsJson()) {
                $this->json(['ok' => false, 'message' => 'Das darfst du nicht bestätigen.'], 403);
                return;
            }
            Response::forbidden('Das darfst du nicht bestätigen.');
            return;
        }
        $laenge = trim(str_replace(',', '.', $request->str('laenge_ist')));
        $ergebnis = AufbauService::postenSetzen((int) $a['id'], $art, $refId, $request->str('status'),
            $laenge !== '' && is_numeric($laenge) ? (float) $laenge : null, $request->str('notiz'));
        if ($request->wantsJson() && $ergebnis['ok']) {
            $ergebnis['aufbau'] = AufbauService::kurz(AufbauService::find((int) $a['id']));
        }
        $this->antwort($request, $ergebnis, '/aufbau/' . (int) $a['id'] . ($request->str('zeigen') !== '' ? '?zeigen=' . rawurlencode($request->str('zeigen')) : ''));
    }

    public function fertig(Request $request, array $params): void
    {
        $a = $this->aufbau($params, true);
        if ($a !== null) {
            $this->antwort($request, AufbauService::fertig((int) $a['id']), '/aufbau/' . (int) $a['id']);
        }
    }

    public function beenden(Request $request, array $params): void
    {
        $a = $this->aufbau($params, true);
        if ($a !== null) {
            $this->antwort($request, AufbauService::beenden((int) $a['id'], $request->bool('abbauen')), '/aufbau/' . (int) $a['id'] . '/abgleich');
        }
    }

    public function loeschen(Request $request, array $params): void
    {
        $a = $this->aufbau($params, true);
        if ($a !== null) {
            $this->antwort($request, AufbauService::loeschen((int) $a['id']), '/aufbau');
        }
    }

    public function abgleich(Request $request, array $params): void
    {
        $a = $this->aufbau($params);
        if ($a === null) {
            return;
        }
        $this->view('aufbau/abgleich', [
            'aufbau'   => $a,
            'abgleich' => AufbauService::abgleich($a),
            'betrieb'  => AufbauService::betrieb($a),
        ] + $this->berichte($a));
    }

    public function druck(Request $request, array $params): void
    {
        $a = $this->aufbau($params);
        if ($a === null) {
            return;
        }
        $this->view('aufbau/druck', [
            'aufbau'   => $a,
            'abgleich' => AufbauService::abgleich($a),
            'betrieb'  => AufbauService::betrieb($a),
        ], 'layout/druck');
    }
}
