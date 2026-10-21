<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\MaterialService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LiveService;
use App\Services\MesspunktService;
use App\Services\PlanGeoService;
use App\Services\PlanMassstabService;
use App\Services\PlanTileService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

/**
 * Die Karte: Grundriss (wie in MasterAdmin) mit Anlagen, Kabeln,
 * Kabelwegen, Kabelbrücken und Kraftstoffschläuchen (Tank → Generator).
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

    /**
     * Darf der Benutzer eine Leitung auf diesem Standort anlegen, ändern oder
     * löschen? Das Recht „Kabel zeichnen“ prüft die Route; dazu braucht es
     * eine Freigabe mit „bearbeiten“ – für den Standort oder für eine Anlage
     * an einem der Kabelenden. Den Standort nur zu sehen genügt nicht.
     *
     * @param list<mixed> $enden Anlagen-IDs an den Enden
     */
    private function darfLeitung(int $siteId, array $enden): bool
    {
        if (Zugriff::darfStandortAendern($siteId)) {
            return true;
        }
        foreach ($enden as $id) {
            if ($id !== null && (int) $id > 0 && ($o = AnlagenService::find((int) $id)) !== null && Zugriff::bearbeitetObjekt($o)) {
                return true;
            }
        }
        return false;
    }

    /** Die Leitung und ihr Standort – oder null, wenn sie nicht sichtbar ist. */
    private function leitungMitStandort(int $id): ?array
    {
        $l = LeitungService::find($id);
        if ($l === null || $l['floor_id'] === null) {
            return null;
        }
        $siteId = (int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']]);
        return Zugriff::siehtStandort($siteId) ? $l + ['site_id' => $siteId] : null;
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
            'massstab'     => $etage !== null ? PlanMassstabService::wirksam($etage) : null,
            'geo'          => $etage !== null ? PlanGeoService::lesen($etage) : null,
            'aufbau'       => $siteId !== null ? AufbauService::aktiv($siteId) : null,
            'modus'        => $request->str('modus'),
            'vorlagen'     => AnlagenService::liste(['vorlagen' => true]),
            'ungesetzt'    => $siteId !== null ? array_values(array_filter(
                Zugriff::filtern(AnlagenService::liste(['site_id' => $siteId])),
                static fn ($o) => $o['plan_x'] === null
            )) : [],
            'darfAnlegen'  => $siteId !== null && Zugriff::darfAufStandortAnlegen($siteId),
            'darfLeitung'  => Auth::can('leitungen.manage') && $siteId !== null && (Zugriff::darfStandortAendern($siteId)
                || array_filter(AnlagenService::liste(['site_id' => $siteId]), static fn ($o) => Zugriff::bearbeitetObjekt($o)) !== []),
            'darfMassstab' => Auth::can('plans.manage') && $siteId !== null && Zugriff::darfStandortAendern($siteId),
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
            foreach (Database::select('SELECT * FROM messpunkte WHERE objekt_id IN (' . implode(',', $ids) . ') AND gueltig_bis IS NULL ORDER BY id') as $m) {
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
            $elektrisch = AnlagenService::elektrisch((string) $o['art']);
            $liste[] = [
                'id'        => (int) $o['id'],
                'art'       => $o['art'],
                'kuerzel'   => $art['kuerzel'],
                'farbe'     => $art['farbe'],
                'fest'      => $o['fest'] === 1,
                'elektrisch' => $elektrisch,
                // Tanks: Nennvolumen und ob ein Tanksensor misst (Pegel im Zeichen).
                'tank'      => $elektrisch ? null : [
                    'nenn_l' => TankRechnung::zahlLesen($o['eigenschaften']['inhalt_l'] ?? null),
                    'sensor' => array_filter($mpJe[(int) $o['id']] ?? [], static fn ($m) => $m['rolle'] === 'fuellstand') !== [],
                ],
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
            'massstab'  => PlanMassstabService::wirksam($etage),
            'geo'       => PlanGeoService::lesen($etage),
            'aufbau'    => AufbauService::kurz(AufbauService::aktiv((int) $etage['site_id'])),
        ]);
    }

    /**
     * Anlagen und Leitungen der Etage als GeoJSON (WGS84) – für QGIS, Google
     * Earth oder die Geländeplanung. Nur mit Georeferenz, nur Sichtbares.
     */
    public function geojson(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        $geo = PlanGeoService::lesen($etage);
        if ($geo === null) {
            $this->json(['ok' => false, 'message' => 'Diese Etage hat keine Georeferenz.'], 404);
            return;
        }
        $punkt = static function (float $x, float $y) use ($geo): array {
            $g = PlanGeoService::zuGeo($geo, $x, $y);
            return [$g['lon'], $g['lat']];
        };
        $features = [];
        $sichtbar = [];
        foreach (Zugriff::filtern(AnlagenService::liste(['floor_id' => (int) $etage['id']])) as $o) {
            $sichtbar[(int) $o['id']] = $o['name'];
            if ($o['plan_x'] === null) {
                continue;
            }
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => $punkt((float) $o['plan_x'], (float) $o['plan_y'])],
                'properties' => ['typ' => 'anlage', 'id' => (int) $o['id'], 'name' => $o['name'], 'art' => $o['art_anzeige'], 'fest' => $o['fest'] === 1,
                    'kennung' => $o['kennung'], 'modell' => trim($o['hersteller'] . ' ' . $o['modell'])]];
        }
        foreach (LeitungService::fuerEtage((int) $etage['id']) as $l) {
            if (count($l['punkte']) < 2) {
                continue;
            }
            $ende = static fn (?int $id): ?string => $id === null ? null : ($sichtbar[$id] ?? 'nicht freigegeben');
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'LineString',
                    'coordinates' => array_map(static fn ($p) => $punkt((float) $p['x'], (float) $p['y']), $l['punkte'])],
                'properties' => ['typ' => $l['art'], 'id' => (int) $l['id'], 'name' => $l['name'], 'fest' => $l['fest'] === 1,
                    'von' => $ende($l['von_objekt_id'] !== null ? (int) $l['von_objekt_id'] : null),
                    'zu' => $ende($l['zu_objekt_id'] !== null ? (int) $l['zu_objekt_id'] : null),
                    'kabeltyp' => $l['kabeltyp'], 'querschnitt_mm2' => $l['querschnitt_mm2'] !== null ? (float) $l['querschnitt_mm2'] : null,
                    'laenge_m' => $l['laenge'] !== null ? round((float) $l['laenge'], 1) : null, 'anzahl' => (int) $l['anzahl'], 'modell' => $l['modell']]];
        }
        $name = preg_replace('/[^a-z0-9]+/', '-', strtolower($etage['site_name'] . '-' . $etage['name'])) ?: 'etage';
        header('Content-Type: application/geo+json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . trim($name, '-') . '.geojson"');
        echo json_encode(['type' => 'FeatureCollection', 'name' => $etage['site_name'] . ' / ' . $etage['name'], 'features' => $features],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function live(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        // Nur lesen, und das regelmäßig – die Sitzung nicht gesperrt halten.
        Session::freigeben();
        $objekte = Zugriff::filtern(AnlagenService::liste(['floor_id' => (int) $etage['id']]));
        $this->json(['ts' => time(), 'objekte' => LiveService::fuerObjekte($objekte)]);
    }

    /** Materialliste der ganzen Etage zum Drucken. */
    public function material(Request $request, array $params): void
    {
        $etage = $this->etage((int) $params['id']);
        if ($etage === null) {
            return;
        }
        $this->view('material/druck', [
            'titel'      => 'Material · ' . $etage['site_name'] . ' / ' . $etage['name'],
            'untertitel' => 'ganze Etage',
            'material'   => MaterialService::fuerEtage((int) $etage['id']),
        ], 'layout/druck');
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
            'elektrisch'  => AnlagenService::elektrisch((string) $objekt['art']),
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
            // Tank: Sensor, letzte Peilungen und Betankungen, was der Betrachter darf.
            'tank'        => AnlagenService::elektrisch((string) $objekt['art']) ? null : TankService::panelDaten($objekt),
            'schaltbar'   => LiveService::schaltbar([$objekt]),
            'darf'        => Zugriff::darfBearbeiten($objekt),
            'darf_statistik' => Auth::can('statistik.view'),
            'geo'         => ($lage = PlanGeoService::fuerObjekt($objekt)) !== null ? $lage + ['link' => PlanGeoService::kartenLink($lage['lat'], $lage['lon'])] : null,
            'inventar'    => array_map(static fn ($e) => ['kennung' => $e['kennung'], 'art' => $e['inventar_art'], 'anschluss' => $e['anschluss_name']],
                InventarService::einsaetzeAnObjekt($id)),
            // Was sich hier einsetzen ließe – für den Aufbau-Modus.
            'inventar_frei' => Zugriff::darfBearbeiten($objekt) && Auth::can('datenquellen.view') ? array_values(array_map(static fn ($i) => [
                'id' => (int) $i['id'], 'kennung' => $i['kennung'], 'typ' => $i['typ'], 'art' => $i['art'],
                // Tanksensor: das Messprinzip entscheidet, welches Einbaumaß gefragt ist.
                'prinzip' => $i['art'] === 'tanksensor' ? (string) ($i['eigenschaften']['prinzip'] ?? '') : null,
                'passend' => $i['art'] === 'verteilung' && trim((string) $i['vorlage_modell']) === trim((string) $objekt['modell']),
                'woanders' => $i['einsatz'] !== null ? (string) ($i['einsatz']['anlage_name'] ?? $i['einsatz']['objekt_name']) : null,
            ], array_filter(InventarService::fuerBetrachter(InventarService::liste()), static fn ($i) => $i['zustand'] !== 'defekt' && (int) ($i['einsatz']['objekt_id'] ?? 0) !== $id
                // Zwischenstecker an jede elektrische Anlage, Verteiler nur in eine Verteilung, Tanksensoren nur in einen Tank.
                && match ($i['art']) {
                    'zwischenstecker' => AnlagenService::elektrisch((string) $objekt['art']),
                    'tanksensor'      => $objekt['art'] === 'tank',
                    default           => $objekt['art'] === 'verteilung',
                }))) : [],
        ]);
    }

    public function leitung(Request $request, array $params): void
    {
        $l = LeitungService::find((int) $params['id']);
        if ($l === null || ($l['floor_id'] !== null && !Zugriff::siehtStandort((int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']])))) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung ist nicht sichtbar.'], 404);
            return;
        }
        // Anschlüsse der Enden für die Auswahl beim Bearbeiten – nur an
        // Anlagen, die der Betrachter sieht (wie auf der Karte selbst).
        $enden = [];
        $sieht = [];
        foreach (['von' => 'abgang', 'zu' => 'eingang'] as $ende => $richtung) {
            $objektId = $l[$ende . '_objekt_id'];
            $endObjekt = $objektId !== null ? AnlagenService::find((int) $objektId) : null;
            $sieht[$ende] = $objektId === null || ($endObjekt !== null && Zugriff::siehtObjekt($endObjekt));
            if (!$sieht[$ende]) {
                $l[$ende . '_name'] = 'nicht freigegeben';
                $l[$ende . '_anschluss'] = null;
                $l[$ende . '_anschluss_id'] = null;
            }
            // Ein Kraftstoffschlauch hat keine Anschlüsse – nur Tank und Generator.
            $enden[$ende] = $objektId === null || !$sieht[$ende] || $l['art'] === 'kraftstoff' ? [] : array_values(array_map(static fn ($a) => [
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
        ], 'enden' => $enden, 'darf' => Auth::can('leitungen.manage')
            && $this->darfLeitung((int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']]), [$l['von_objekt_id'], $l['zu_objekt_id']])]);
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
        // Auf einen anderen Standort nur, wo man auch Anlagen anlegen darf –
        // sonst ließe sich eine Anlage in einen fremden Plan schieben.
        if ($etage === null || !Zugriff::siehtStandort((int) $etage['site_id'])
            || ((int) $etage['site_id'] !== (int) ($objekt['site_id'] ?? 0) && !Zugriff::darfAufStandortAnlegen((int) $etage['site_id']))) {
            $this->json(['ok' => false, 'message' => 'Auf diese Etage darfst du die Anlage nicht verschieben.'], 403);
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
        $alt = null;
        if ($id !== null) {
            $alt = LeitungService::find($id);
            if ($alt === null || (int) $alt['floor_id'] !== (int) $etage['id']) {
                $this->json(['ok' => false, 'message' => 'Diese Leitung gibt es auf der Etage nicht.'], 404);
                return;
            }
        }
        // Eine vorhandene Leitung ändert, wer an ihren bisherigen Enden
        // bearbeiten darf; eine neue, wer es an einem ihrer neuen Enden darf.
        $enden = $alt !== null ? [$alt['von_objekt_id'], $alt['zu_objekt_id']] : [$request->int('von_objekt_id'), $request->int('zu_objekt_id')];
        if (!$this->darfLeitung((int) $etage['site_id'], $enden)) {
            $this->json(['ok' => false, 'message' => 'Hier darfst du keine Kabel zeichnen oder ändern.'], 403);
            return;
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
        $l = $this->leitungMitStandort($request->int('id'));
        if ($l === null || !$this->darfLeitung((int) $l['site_id'], [$l['von_objekt_id'], $l['zu_objekt_id']])) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung darfst du nicht ändern.'], 403);
            return;
        }
        $this->json(LeitungService::punkteSetzen((int) $l['id'], json_decode((string) $request->input('punkte', '[]'), true)));
    }

    /** Ein Kabelende auf eine andere Anlage umstecken oder lösen (Endpunkt gezogen). */
    public function leitungEnde(Request $request): void
    {
        $l = $this->leitungMitStandort($request->int('id'));
        if ($l === null || !$this->darfLeitung((int) $l['site_id'], [$l['von_objekt_id'], $l['zu_objekt_id']])) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung darfst du nicht ändern.'], 403);
            return;
        }
        $objektId = $request->int('objekt_id') ?: null;
        if ($objektId !== null) {
            $objekt = AnlagenService::find($objektId);
            if ($objekt === null || !Zugriff::siehtObjekt($objekt) || (int) $objekt['floor_id'] !== (int) $l['floor_id']) {
                $this->json(['ok' => false, 'message' => 'An diese Anlage lässt sich das Kabel nicht anschließen.'], 403);
                return;
            }
        }
        $this->json(LeitungService::endeSetzen((int) $l['id'], $request->str('ende'), $objektId, json_decode((string) $request->input('punkte', '[]'), true)));
    }

    public function leitungLoeschen(Request $request): void
    {
        $l = $this->leitungMitStandort($request->int('id'));
        if ($l === null || !$this->darfLeitung((int) $l['site_id'], [$l['von_objekt_id'], $l['zu_objekt_id']])) {
            $this->json(['ok' => false, 'message' => 'Diese Leitung darfst du nicht löschen.'], 403);
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
        if (!Zugriff::darfStandortAendern((int) $etage['site_id'])) {
            $this->json(['ok' => false, 'message' => 'Den Maßstab dieses Standorts darfst du nicht ändern.'], 403);
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

    /** Georeferenz setzen oder entfernen: zwei Planpunkte mit Breite/Länge. */
    public function georeferenz(Request $request): void
    {
        $etage = $this->etage($request->int('floor_id'));
        if ($etage === null) {
            return;
        }
        if (!Zugriff::darfStandortAendern((int) $etage['site_id'])) {
            $this->json(['ok' => false, 'message' => 'Die Georeferenz dieses Standorts darfst du nicht ändern.'], 403);
            return;
        }
        if ($request->bool('entfernen')) {
            PlanGeoService::entfernen((int) $etage['id']);
            $this->json(['ok' => true, 'message' => 'Georeferenz entfernt.']);
            return;
        }
        $punkte = [];
        foreach (['a' => 'Punkt 1', 'b' => 'Punkt 2'] as $k => $name) {
            $lage = PlanGeoService::koordinatenAusText($request->str($k . '_geo'));
            if ($lage === null) {
                $this->json(['ok' => false, 'message' => $name . ': Koordinaten nicht lesbar. Beispiel: 51.23456, 7.12345']);
                return;
            }
            $punkte[$k] = ['x' => (float) $request->input($k . 'x'), 'y' => (float) $request->input($k . 'y')] + $lage;
        }
        $this->json(PlanGeoService::speichern((int) $etage['id'], $punkte['a'], $punkte['b'], (float) $request->input('verhaeltnis')));
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
