<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\LeitungService;
use App\Services\LiveService;
use App\Services\MesspunktService;
use App\Services\PlanMassstabService;
use App\Services\PlanTileService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;

/**
 * Die Karte: Grundriss (wie in MasterAdmin) mit Anlagen, Kabeln,
 * Kabelwegen und Kabelbrücken.
 *
 * Gelesen wird über JSON (stand, live, objekt, leitung), geschrieben über
 * kleine POSTs – die Karte lädt sich dabei nicht neu, und auf dem Tablet
 * bleibt der Ausschnitt, in dem man gerade arbeitet.
 */
final class KarteController extends Controller
{
    /** Etage laden und prüfen, ob ihr Standort sichtbar ist. */
    private function etage(int $id): ?array
    {
        $etage = SiteService::floor($id);
        if ($etage === null) {
            Response::notFound('Diese Etage gibt es nicht.');
            return null;
        }
        if (!Zugriff::siehtStandort((int) $etage['site_id'])) {
            Response::forbidden('Dieser Standort ist für dich nicht freigegeben.');
            return null;
        }
        return $etage;
    }

    public function index(Request $request): void
    {
        $standorte = Zugriff::standorteFiltern(SiteService::all());
        $etageId = $request->int('etage');
        $etage = null;
        if ($etageId > 0) {
            $etage = SiteService::floor($etageId);
            if ($etage !== null && !Zugriff::siehtStandort((int) $etage['site_id'])) {
                $etage = null;
            }
        }
        if ($etage === null) {
            foreach ($standorte as $s) {
                $erste = SiteService::ersteMitPlan($s['floors']) ?? ($s['floors'][0] ?? null);
                if ($erste !== null) {
                    $etage = SiteService::floor((int) $erste['id']);
                    break;
                }
            }
        }
        $quelle = $etage !== null ? PlanTileService::quelle($etage) : null;
        $siteId = $etage !== null ? (int) $etage['site_id'] : null;

        $this->view('karte/index', [
            'standorte'    => $standorte,
            'etage'        => $etage,
            'plan'         => $quelle,
            'massstab'     => $etage !== null ? PlanMassstabService::lesen($etage) : null,
            'vorlagen'     => AnlagenService::liste(['vorlagen' => true]),
            'ungesetzt'    => $siteId !== null ? array_values(array_filter(
                Zugriff::filtern(AnlagenService::liste(['site_id' => $siteId])),
                static fn ($o) => $o['plan_x'] === null
            )) : [],
            'darfAnlegen'  => $siteId !== null && Zugriff::darfAufStandortAnlegen($siteId),
            'darfLeitung'  => Auth::can('leitungen.manage'),
            'darfMassstab' => Auth::can('plans.manage'),
            'fokus'        => $request->int('anlage'),
            'stylesheets'  => ['/assets/vendor/leaflet/leaflet.css'],
            'scripts'      => ['/assets/vendor/leaflet/leaflet.js', '/assets/js/live.js', '/assets/js/module/karte.js'],
        ]);
    }

    /** Alles, was auf der Etage liegt – als JSON. */
    public function stand(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        $floorId = (int) $etage['id'];
        $objekte = Zugriff::filtern(AnlagenService::liste(['floor_id' => $floorId]));
        $sichtbar = [];
        $mpJe = [];
        if ($objekte !== []) {
            $ids = array_map(static fn ($o) => (int) $o['id'], $objekte);
            foreach (Database::select('SELECT * FROM messpunkte WHERE objekt_id IN (' . implode(',', $ids) . ') ORDER BY id') as $m) {
                $mpJe[(int) $m['objekt_id']][] = $m;
            }
        }
        $auswertung = MesspunktService::auswertung();
        $liste = [];
        foreach ($objekte as $o) {
            $sichtbar[(int) $o['id']] = true;
            if ($o['plan_x'] === null) {
                continue;
            }
            $art = AnlagenService::ARTEN[$o['art']];
            $liste[] = [
                'id'        => (int) $o['id'],
                'art'       => $o['art'],
                'kuerzel'   => $art['kuerzel'],
                'farbe'     => $art['farbe'],
                'fest'      => $o['fest'] === 1,
                'art_name'  => $o['art_anzeige'],
                'name'      => $o['name'],
                'kennung'   => $o['kennung'],
                'status'    => $o['status'],
                'x'         => (float) $o['plan_x'],
                'y'         => (float) $o['plan_y'],
                'kurz'      => AnlagenService::kurzform((int) $o['id']),
                'darf'      => Zugriff::darfBearbeiten($o),
                'messgeraete' => array_map(static fn ($m) => [
                    'id' => (int) $m['id'],
                    'titel' => MesspunktService::titel($m + ['objekt_name' => $o['name']]),
                    'in_auswertung' => in_array((int) $m['id'], $auswertung, true),
                ], $mpJe[(int) $o['id']] ?? []),
            ];
        }
        $leitungen = [];
        foreach (LeitungService::fuerEtage($floorId) as $l) {
            $vonSichtbar = $l['von_objekt_id'] === null || isset($sichtbar[(int) $l['von_objekt_id']]);
            $zuSichtbar = $l['zu_objekt_id'] === null || isset($sichtbar[(int) $l['zu_objekt_id']]);
            $leitungen[] = [
                'id'        => (int) $l['id'],
                'art'       => $l['art'],
                'fest'      => $l['fest'] === 1,
                'name'      => $l['name'],
                'von'       => $l['von_objekt_id'] !== null ? (int) $l['von_objekt_id'] : null,
                'zu'        => $l['zu_objekt_id'] !== null ? (int) $l['zu_objekt_id'] : null,
                'von_name'  => $vonSichtbar ? $l['von_name'] : 'nicht freigegeben',
                'zu_name'   => $zuSichtbar ? $l['zu_name'] : 'nicht freigegeben',
                'kabeltyp'  => $l['kabeltyp'],
                'querschnitt' => $l['querschnitt_mm2'] !== null ? (float) $l['querschnitt_mm2'] : null,
                'laenge'    => $l['laenge'] !== null ? round((float) $l['laenge'], 1) : null,
                'anzahl'    => (int) $l['anzahl'],
                'modell'    => $l['modell'],
                'farbe'     => $l['farbe'],
                'punkte'    => array_map(static fn ($p) => [$p['x'], $p['y']], $l['punkte']),
                'hinweise'  => $l['hinweise'],
            ];
        }
        $fremd = json_decode((string) ($etage['fremdbestand'] ?? ''), true) ?: ['dosen' => [], 'racks' => [], 'aps' => []];
        $this->json([
            'etage'     => $floorId,
            'objekte'   => $liste,
            'leitungen' => $leitungen,
            'fremd'     => $fremd,
            'massstab'  => PlanMassstabService::lesen($etage),
        ]);
    }

    public function live(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        $objekte = Zugriff::filtern(AnlagenService::liste(['floor_id' => (int) $etage['id']]));
        $this->json(['ts' => time(), 'objekte' => LiveService::fuerObjekte($objekte)]);
    }

    /** Die Seitenleiste zu einer Anlage. */
    public function objekt(Request $request, array $params): void
    {
        $objekt = AnlagenService::find((int) $params['id']);
        if ($objekt === null || !Zugriff::siehtObjekt($objekt)) {
            $this->json(['ok' => false, 'message' => 'Diese Anlage ist nicht sichtbar.'], 404);
            return;
        }
        $id = (int) $objekt['id'];
        $auswertung = MesspunktService::auswertung();
        $anschluesse = array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'richtung' => $a['richtung'], 'bezeichnung' => $a['bezeichnung'],
            'typ' => $a['typ_name'], 'farbe' => $a['typ_farbe'], 'schutz' => $a['schutz_text'], 'phase' => $a['phase'],
            'belegt' => $a['leitung'] !== null ? (string) $a['leitung']['gegenstelle'] : null,
        ], AnlagenService::anschluesse($id));
        $this->json([
            'ok'          => true,
            'id'          => $id,
            'name'        => $objekt['name'],
            'art'         => $objekt['art'],
            'art_name'    => $objekt['art_anzeige'],
            'fest'        => $objekt['fest'] === 1,
            'kennung'     => $objekt['kennung'],
            'modell'      => trim($objekt['hersteller'] . ' ' . $objekt['modell']),
            'status'      => AnlagenService::STATUS[$objekt['status']] ?? $objekt['status'],
            'kurz'        => AnlagenService::kurzform($id),
            'anschluesse' => $anschluesse,
            'messgeraete' => array_map(static fn ($m) => [
                'id' => (int) $m['id'],
                'titel' => MesspunktService::titel($m + ['objekt_name' => $objekt['name']]),
                'geraet' => $m['geraet'], 'kanal' => $m['kanal'],
                'in_auswertung' => in_array((int) $m['id'], $auswertung, true),
            ], MesspunktService::fuerObjekt($id)),
            'live'        => LiveService::fuerObjekte([$objekt])[$id] ?? null,
            'schaltbar'   => LiveService::schaltbar([$objekt]),
            'darf'        => Zugriff::darfBearbeiten($objekt),
            'darf_statistik' => Auth::can('statistik.view'),
        ]);
    }

    public function leitung(Request $request, array $params): void
    {
        $l = LeitungService::find((int) $params['id']);
        if ($l === null || ($l['floor_id'] !== null && !Zugriff::siehtStandort((int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']])))) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung ist nicht sichtbar.'], 404);
            return;
        }
        // Anschlüsse der Enden für die Auswahl beim Bearbeiten.
        $enden = [];
        foreach (['von' => 'abgang', 'zu' => 'eingang'] as $ende => $richtung) {
            $objektId = $l[$ende . '_objekt_id'];
            $enden[$ende] = $objektId === null ? [] : array_values(array_map(static fn ($a) => [
                'id' => (int) $a['id'], 'text' => $a['bezeichnung'] . ' · ' . $a['typ_name'] . ($a['leitung'] !== null ? ' (belegt)' : ''),
                'frei' => $a['leitung'] === null,
            ], array_filter(AnlagenService::anschluesse((int) $objektId), static fn ($a) => $a['richtung'] === $richtung)));
        }
        $this->json(['ok' => true, 'leitung' => [
            'id' => (int) $l['id'], 'art' => $l['art'], 'fest' => $l['fest'] === 1, 'name' => $l['name'],
            'von' => $l['von_objekt_id'], 'zu' => $l['zu_objekt_id'], 'von_name' => $l['von_name'], 'zu_name' => $l['zu_name'],
            'von_anschluss_id' => $l['von_anschluss_id'], 'zu_anschluss_id' => $l['zu_anschluss_id'],
            'von_anschluss' => $l['von_anschluss'], 'zu_anschluss' => $l['zu_anschluss'],
            'kabeltyp' => $l['kabeltyp'], 'adern' => $l['adern'], 'querschnitt' => $l['querschnitt_mm2'],
            'laenge_m' => $l['laenge_m'], 'laenge_plan' => $l['laenge_plan_m'] !== null ? round((float) $l['laenge_plan_m'], 1) : null,
            'belastbarkeit' => $l['belastbarkeit_a'], 'anzahl' => (int) $l['anzahl'], 'modell' => $l['modell'],
            'notizen' => (string) ($l['notizen'] ?? ''), 'hinweise' => $l['hinweise'],
        ], 'enden' => $enden, 'darf' => Auth::can('leitungen.manage')]);
    }

    // ------------------------------------------------------------ Schreiben

    public function objektSetzen(Request $request): void
    {
        $etage = SiteService::floor($request->int('floor_id'));
        if ($etage === null || !Zugriff::darfAufStandortAnlegen((int) $etage['site_id'])) {
            $this->json(['ok' => false, 'message' => 'Auf dieser Etage darfst du keine Anlagen setzen.'], 403);
            return;
        }
        $vorhanden = $request->int('objekt_id');
        if ($vorhanden > 0) {
            // Eine vorhandene, noch nicht gesetzte Anlage in den Plan setzen.
            $objekt = AnlagenService::find($vorhanden);
            if ($objekt === null || !Zugriff::darfBearbeiten($objekt)) {
                $this->json(['ok' => false, 'message' => 'Diese Anlage darfst du nicht setzen.'], 403);
                return;
            }
            $this->json(AnlagenService::position($vorhanden, (int) $etage['id'], (float) $request->input('x'), (float) $request->input('y')) + ['id' => $vorhanden]);
            return;
        }
        $vorlageId = $request->int('vorlage_id');
        $daten = [
            'art'      => $request->str('art', 'verteilung'),
            // Fest (Feststromanschluss der Location) oder mobil; ohne Angabe
            // entscheidet die Art.
            'fest'     => $request->str('fest'),
            'name'     => $request->str('name'),
            'floor_id' => (int) $etage['id'],
            'plan_x'   => (float) $request->input('x'),
            'plan_y'   => (float) $request->input('y'),
            'status'   => 'geplant',
        ];
        if ($vorlageId > 0 && ($vorlage = AnlagenService::find($vorlageId)) !== null && (int) $vorlage['ist_vorlage'] === 1) {
            // Aus dem Katalog: alle Angaben übernehmen, Anschlüsse und
            // Schutzorgane kopiert AnlagenService::speichern().
            $daten = array_merge($vorlage, $daten, [
                'art' => $vorlage['art'], 'ist_vorlage' => 0, 'vorlage_id' => $vorlageId, 'kennung' => '', 'seriennummer' => '',
                'name' => $daten['name'] !== '' ? $daten['name'] : $vorlage['name'],
            ]);
        }
        $this->json(AnlagenService::speichern(null, $daten));
    }

    public function objektVerschieben(Request $request): void
    {
        $objekt = AnlagenService::find($request->int('id'));
        if ($objekt === null || !Zugriff::darfBearbeiten($objekt)) {
            $this->json(['ok' => false, 'message' => 'Diese Anlage darfst du nicht verschieben.'], 403);
            return;
        }
        $floorId = $request->int('floor_id') ?: (int) $objekt['floor_id'];
        $etage = SiteService::floor($floorId);
        if ($etage === null || !Zugriff::siehtStandort((int) $etage['site_id'])) {
            $this->json(['ok' => false, 'message' => 'Diese Etage ist nicht freigegeben.'], 403);
            return;
        }
        $this->json(AnlagenService::position((int) $objekt['id'], $floorId, (float) $request->input('x'), (float) $request->input('y')));
    }

    public function objektEntfernen(Request $request): void
    {
        $objekt = AnlagenService::find($request->int('id'));
        if ($objekt === null || !Zugriff::darfBearbeiten($objekt)) {
            $this->json(['ok' => false, 'message' => 'Diese Anlage darfst du nicht ändern.'], 403);
            return;
        }
        $this->json(AnlagenService::position((int) $objekt['id'], null, null, null));
    }

    /** Leitung anlegen oder ändern – die Enden müssen sichtbar sein. */
    public function leitungSpeichern(Request $request): void
    {
        $etage = SiteService::floor($request->int('floor_id'));
        if ($etage === null || !Zugriff::siehtStandort((int) $etage['site_id'])) {
            $this->json(['ok' => false, 'message' => 'Auf dieser Etage darfst du nicht zeichnen.'], 403);
            return;
        }
        $id = $request->int('id') ?: null;
        if ($id !== null) {
            $alt = LeitungService::find($id);
            if ($alt === null || (int) $alt['floor_id'] !== (int) $etage['id']) {
                $this->json(['ok' => false, 'message' => 'Diese Leitung gibt es auf der Etage nicht.'], 404);
                return;
            }
        }
        foreach (['von_objekt_id', 'zu_objekt_id'] as $feld) {
            $objektId = $request->int($feld);
            if ($objektId > 0) {
                $objekt = AnlagenService::find($objektId);
                if ($objekt === null || !Zugriff::siehtObjekt($objekt)) {
                    $this->json(['ok' => false, 'message' => 'Ein Kabelende liegt an einer nicht freigegebenen Anlage.'], 403);
                    return;
                }
            }
        }
        $daten = $_POST;
        $daten['floor_id'] = (int) $etage['id'];
        $daten['punkte'] = json_decode((string) ($daten['punkte'] ?? '[]'), true) ?: [];
        if ($id !== null && !isset($_POST['punkte'])) {
            $daten['punkte'] = LeitungService::find($id)['punkte'];
        }
        $this->json(LeitungService::speichern($id, $daten));
    }

    public function leitungVerlauf(Request $request): void
    {
        $l = LeitungService::find($request->int('id'));
        if ($l === null || $l['floor_id'] === null || !Zugriff::siehtStandort((int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']]))) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung ist nicht sichtbar.'], 403);
            return;
        }
        $this->json(LeitungService::punkteSetzen((int) $l['id'], json_decode((string) $request->input('punkte', '[]'), true)));
    }

    public function leitungLoeschen(Request $request): void
    {
        $l = LeitungService::find($request->int('id'));
        if ($l === null || $l['floor_id'] === null || !Zugriff::siehtStandort((int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']]))) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung ist nicht sichtbar.'], 403);
            return;
        }
        $this->json(LeitungService::loeschen((int) $l['id']));
    }

    public function massstab(Request $request): void
    {
        $etage = $this->etage($request->int('floor_id'));
        if ($etage === null) {
            return;
        }
        if ($request->bool('entfernen')) {
            PlanMassstabService::entfernen((int) $etage['id']);
            $this->json(['ok' => true, 'message' => 'Maßstab entfernt.']);
            return;
        }
        $meter = PlanMassstabService::meterAusText($request->str('meter'));
        $this->json(PlanMassstabService::speichern(
            (int) $etage['id'],
            ['x' => (float) $request->input('ax'), 'y' => (float) $request->input('ay')],
            ['x' => (float) $request->input('bx'), 'y' => (float) $request->input('by')],
            (float) ($meter ?? 0),
            (float) $request->input('verhaeltnis')
        ));
    }

    // ------------------------------------------------------------ Plandatei

    public function planDatei(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        $pfad = SiteService::planPath($etage);
        if ($pfad === null) {
            http_response_code(404);
            echo 'kein Grundriss hinterlegt';
            return;
        }
        header('Content-Type: ' . SiteService::planMime($etage));
        header('Content-Length: ' . (string) filesize($pfad));
        header('Content-Disposition: inline; filename="' . basename((string) $etage['plan_original_name'] ?: 'grundriss') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        // Ein Grundriss ist ein Bild und sonst nichts – ein SVG mit Skript
        // soll beim direkten Aufruf nichts ausführen dürfen.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Referrer-Policy: no-referrer');
        readfile($pfad);
    }

    public function planKachel(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        $kachel = PlanTileService::kachel($etage, (int) $params['z'], (int) $params['x'], (int) $params['y']);
        if ($kachel === null) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $kachel['mime']);
        header('Content-Length: ' . (string) filesize($kachel['pfad']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400, immutable');
        readfile($kachel['pfad']);
    }
}
