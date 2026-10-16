<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LiveService;
use App\Services\MesspunktService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

final class AnlagenController extends Controller
{
    /** Anlage laden und prüfen, ob der Benutzer sie sehen (bzw. ändern) darf. */
    private function anlage(array $params, bool $aendern = false): ?array
    {
        $objekt = AnlagenService::find((int) ($params['id'] ?? 0));
        if ($objekt === null) {
            Response::notFound('Diese Anlage gibt es nicht.');
            return null;
        }
        // Standard-Verteiler (Vorlagen) stehen nirgends – sie sieht, wer Anlagen sehen darf.
        $sichtbar = (int) $objekt['ist_vorlage'] === 1 || Zugriff::siehtObjekt($objekt);
        if (!$sichtbar) {
            Response::forbidden('Diese Anlage ist für dich nicht freigegeben.');
            return null;
        }
        if ($aendern && !((int) $objekt['ist_vorlage'] === 1 ? Auth::can('anlagen.manage') && Zugriff::alles() || Auth::can('stecker.manage') : Zugriff::darfBearbeiten($objekt))) {
            Response::forbidden('Diese Anlage darfst du nicht ändern.');
            return null;
        }
        return $objekt;
    }

    public function index(Request $request): void
    {
        $fest = $request->str('fest');
        $filter = [
            'art'     => $request->str('art'),
            'site_id' => $request->int('standort'),
            'suche'   => $request->str('suche'),
            'fest'    => $fest === '1' ? true : ($fest === '0' ? false : null),
        ];
        $objekte = Zugriff::filtern(AnlagenService::liste($filter));
        foreach ($objekte as &$o) {
            $o['kurzform'] = AnlagenService::kurzform((int) $o['id']);
        }
        unset($o);
        $this->view('anlagen/index', [
            'objekte'   => $objekte,
            'live'      => LiveService::fuerObjekte($objekte),
            'filter'    => $filter,
            'standorte' => Zugriff::standorteFiltern(SiteService::all()),
            'scripts'   => ['/assets/js/live.js'],
        ]);
    }

    public function katalog(Request $request): void
    {
        $vorlagen = AnlagenService::liste(['vorlagen' => true]);
        foreach ($vorlagen as &$v) {
            $v['kurzform'] = AnlagenService::kurzform((int) $v['id']);
            $v['schutz'] = count(SchutzService::liste((int) $v['id']));
        }
        unset($v);
        $this->view('anlagen/katalog', ['vorlagen' => $vorlagen]);
    }

    public function baum(Request $request): void
    {
        $siteId = $request->int('standort') ?: null;
        $standorte = Zugriff::standorteFiltern(SiteService::all());
        if ($siteId === null && $standorte !== []) {
            $siteId = (int) $standorte[0]['id'];
        }
        $baum = $siteId !== null && Zugriff::siehtStandort($siteId) ? AnlagenService::baum($siteId) : [];
        $objekte = Zugriff::filtern(AnlagenService::liste($siteId !== null ? ['site_id' => $siteId] : []));
        $this->view('anlagen/baum', [
            'baum'      => $baum,
            'sichtbar'  => array_column($objekte, 'id'),
            'live'      => LiveService::fuerObjekte($objekte),
            'standorte' => $standorte,
            'siteId'    => $siteId,
            'scripts'   => ['/assets/js/live.js'],
        ]);
    }

    public function neu(Request $request): void
    {
        $art = $request->str('art', 'verteilung');
        $art = isset(AnlagenService::ARTEN[$art]) ? $art : 'verteilung';
        $vorlage = $request->bool('vorlage');
        $fest = $request->str('fest');
        $this->view('anlagen/form', [
            'objekt' => [
                'id' => 0, 'art' => $art, 'name' => '', 'kennung' => '',
                'fest' => $fest !== '' ? (int) ($fest === '1') : (int) AnlagenService::festVorgabe($art),
                'site_id' => $request->int('standort') ?: null, 'floor_id' => null, 'hersteller' => '', 'modell' => '',
                'seriennummer' => '', 'nennleistung_kva' => null, 'nennstrom_a' => null, 'spannung_v' => 400, 'phasen' => 3,
                'status' => 'geplant', 'eigenschaften' => [], 'steuerung_gesperrt' => 0, 'ist_vorlage' => $vorlage ? 1 : 0, 'notizen' => '',
            ],
            'standorte' => Zugriff::standorteFiltern(SiteService::all()),
            'vorlagen'  => AnlagenService::liste(['vorlagen' => true]),
        ]);
    }

    public function bearbeiten(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        // Art umstellen: das Formular lädt mit ?art= neu, gespeichert wird erst mit „Speichern“.
        $art = $request->str('art');
        $artNeu = isset(AnlagenService::ARTEN[$art]) && $art !== $objekt['art'] ? $art : null;
        if ($artNeu !== null) {
            $objekt['art'] = $artNeu;
        }
        $this->view('anlagen/form', [
            'objekt'    => $objekt,
            'artNeu'    => $artNeu,
            'standorte' => Zugriff::standorteFiltern(SiteService::all()),
            'vorlagen'  => [],
        ]);
    }

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $daten = $_POST;
        // Wohin die Anlage kommt – wie im Service: die Etage bestimmt den
        // Standort. Geprüft wird dieser, nicht der aus dem Formular; sonst
        // landete eine Anlage mit eigenem Standort und fremder Etage dort.
        $zielStandort = (int) ($daten['site_id'] ?? 0) ?: null;
        if ((int) ($daten['floor_id'] ?? 0) > 0 && ($zielEtage = SiteService::floor((int) $daten['floor_id'])) !== null) {
            $zielStandort = (int) $zielEtage['site_id'];
        }
        if ($id !== null) {
            $objekt = $this->anlage(['id' => $id], true);
            if ($objekt === null) {
                return;
            }
            // Eine Anlage bleibt Anlage, ein Katalog-Typ bleibt Katalog-Typ:
            // sonst würde eine eigene Anlage zum Typ, den alle sehen, oder ein
            // Typ zur Anlage auf einem beliebigen Standort.
            $daten['ist_vorlage'] = (int) $objekt['ist_vorlage'];
            $bisher = $objekt['site_id'] !== null ? (int) $objekt['site_id'] : null;
            if ((int) $objekt['ist_vorlage'] === 0 && $zielStandort !== $bisher && !Zugriff::darfAufStandortAnlegen($zielStandort)) {
                Response::forbidden('Auf diesen Standort darfst du die Anlage nicht verlegen.');
                return;
            }
        } elseif (!empty($daten['ist_vorlage'])) {
            if (!Auth::can('stecker.manage') && !Zugriff::alles()) {
                Response::forbidden('Standard-Verteiler pflegt, wer den Steckverbinder-Katalog pflegen darf.');
                return;
            }
        } elseif (!Zugriff::darfAufStandortAnlegen($zielStandort)) {
            Response::forbidden('Auf diesem Standort darfst du keine Anlagen anlegen.');
            return;
        }
        $ergebnis = AnlagenService::speichern($id, $daten);
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
            $this->back('/anlagen');
            return;
        }
        Flash::success($ergebnis['message']);
        $this->redirect('/anlagen/' . (int) $ergebnis['id']);
    }

    public function show(Request $request, array $params): void
    {
        $objekt = $this->anlage($params);
        if ($objekt === null) {
            return;
        }
        $id = (int) $objekt['id'];
        $messpunkte = MesspunktService::fuerObjekt($id);
        $live = LiveService::fuerObjekte([$objekt]);
        $fenster = Zugriff::datenFenster($objekt);
        $kennzahlen = null;
        $haupt = MesspunktService::haupt($messpunkte);
        if ($haupt !== null && Auth::can('statistik.view') && (int) $objekt['ist_vorlage'] === 0) {
            $kennzahlen = Messdaten::kennzahlen((string) $haupt['geraet'], (string) $haupt['kanal'], time() - 86400, time(), MesspunktService::fenster($haupt, $objekt));
        }
        $this->view('anlagen/show', [
            'objekt'       => $objekt,
            'anschluesse'  => AnlagenService::anschluesse($id),
            'anzahlen'     => AnlagenService::anzahlen($id),
            'steckertypen' => AnlagenService::steckertypen(),
            'schutz'       => SchutzService::liste($id),
            'messpunkte'   => $messpunkte,
            'kabel'        => LeitungService::anObjekt($id),
            'speisung'     => AnlagenService::speisung($id),
            'live'         => $live[$id] ?? [],
            'schaltbar'    => LiveService::schaltbar([$objekt]),
            'geraete'      => Auth::can('datenquellen.view') || Zugriff::darfBearbeiten($objekt) ? Messdaten::geraete() : [],
            'darfAendern'  => (int) $objekt['ist_vorlage'] === 1 ? (Auth::can('stecker.manage') || Zugriff::alles()) : Zugriff::darfBearbeiten($objekt),
            'fenster'      => $fenster,
            'kennzahlen'   => $kennzahlen,
            'einsaetze'    => (int) $objekt['ist_vorlage'] === 1 ? [] : InventarService::einsaetzeAnObjekt($id, true),
            'inventar'     => (int) $objekt['ist_vorlage'] === 0 && Zugriff::darfBearbeiten($objekt) && Auth::can('datenquellen.view')
                ? array_values(array_filter(InventarService::fuerBetrachter(InventarService::liste()), static fn ($i) => $i['zustand'] !== 'defekt' && (int) ($i['einsatz']['objekt_id'] ?? 0) !== $id))
                : [],
            'auswertung'   => MesspunktService::auswertung(),
            'reiter'       => $request->str('reiter', 'uebersicht'),
            'letztePruefung' => (int) $objekt['ist_vorlage'] === 0 && Auth::can('berichte.view') ? BerichtService::letzteAnObjekt($id) : null,
            'berichtNeu'   => (int) $objekt['ist_vorlage'] === 0 && $objekt['site_id'] !== null && Auth::can('berichte.manage')
                && Zugriff::darfStandortAendern((int) $objekt['site_id']),
            'scripts'      => ['/assets/js/live.js'],
        ]);
    }

    public function druck(Request $request, array $params): void
    {
        $objekt = $this->anlage($params);
        if ($objekt === null) {
            return;
        }
        $id = (int) $objekt['id'];
        $this->view('anlagen/druck', [
            'objekt'      => $objekt,
            'anschluesse' => AnlagenService::anschluesse($id),
            'schutz'      => SchutzService::liste($id),
            'messpunkte'  => MesspunktService::fuerObjekt($id),
            'speisung'    => AnlagenService::speisung($id),
            'kabel'       => LeitungService::anObjekt($id),
        ], 'layout/druck');
    }

    public function live(Request $request, array $params): void
    {
        $objekt = $this->anlage($params);
        if ($objekt === null) {
            return;
        }
        $this->json(['ts' => time(), 'objekte' => LiveService::fuerObjekte([$objekt])]);
    }

    public function loeschen(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = AnlagenService::loeschen((int) $objekt['id']);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect((int) $objekt['ist_vorlage'] === 1 ? '/anlagen/katalog' : '/anlagen');
    }

    public function kopieren(Request $request, array $params): void
    {
        $objekt = $this->anlage($params);
        if ($objekt === null) {
            return;
        }
        if (!Zugriff::darfAufStandortAnlegen($objekt['site_id'] !== null ? (int) $objekt['site_id'] : null) && !Zugriff::alles()) {
            Response::forbidden('Hier darfst du keine Anlage anlegen.');
            return;
        }
        $ergebnis = AnlagenService::kopieren((int) $objekt['id'], $request->str('name'));
        $ergebnis['ok'] ? Flash::success('Kopie angelegt.') : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/anlagen/' . (int) $ergebnis['id'] . '/bearbeiten' : '/anlagen/' . (int) $objekt['id']);
    }

    public function anschluesseAnzahl(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = AnlagenService::anzahlSetzen((int) $objekt['id'], [
            'eingang' => (array) ($_POST['eingang'] ?? []),
            'abgang'  => (array) ($_POST['abgang'] ?? []),
        ]);
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=anschluesse');
    }

    public function anschluesseDetails(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = AnlagenService::anschluesseAendern((int) $objekt['id'], (array) ($_POST['anschluss'] ?? []));
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=anschluesse');
    }

    public function schutzSpeichern(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = SchutzService::speichern((int) $objekt['id'], $request->int('schutz_id') ?: null, $_POST);
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=schutz');
    }

    public function schutzLoeschen(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = SchutzService::loeschen((int) $objekt['id'], $request->int('schutz_id'));
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=schutz');
    }

    public function schutzVorschlag(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = SchutzService::vorschlagen((int) $objekt['id']);
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=schutz');
    }

    public function messpunktSpeichern(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $daten = $_POST;
        // „geraet|kanal“ aus der Auswahlliste entdeckter Geräte
        if (!empty($daten['geraet_kanal']) && str_contains((string) $daten['geraet_kanal'], '|')) {
            [$daten['geraet'], $daten['kanal']] = explode('|', (string) $daten['geraet_kanal'], 2);
        }
        $geraet = trim((string) ($daten['geraet'] ?? ''));
        if ($geraet !== '' && !Zugriff::darfGeraetZuordnen($geraet)) {
            $this->antwort($request, ['ok' => false, 'message' => 'Dieses Gerät misst an einer Anlage, die du nicht bearbeiten darfst.'],
                '/anlagen/' . (int) $objekt['id'] . '?reiter=messung');
            return;
        }
        $ergebnis = MesspunktService::speichern((int) $objekt['id'], $request->int('messpunkt_id') ?: null, $daten, !Zugriff::geraetMitGeschichte());
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=messung');
    }

    public function messpunktLoeschen(Request $request, array $params): void
    {
        $objekt = $this->anlage($params, true);
        if ($objekt === null) {
            return;
        }
        $ergebnis = MesspunktService::loeschen((int) $objekt['id'], $request->int('messpunkt_id'));
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=messung');
    }
}
