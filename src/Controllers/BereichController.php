<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\BereichService;
use App\Services\MaterialService;
use App\Services\SiteService;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Zeit;
use App\Support\Zeitraum;

/**
 * Bereiche: zusammengerechnete Last und Materialliste.
 *
 * Sehen darf einen Bereich, wer seinen Standort sieht; in die Summe kommen
 * nur Mitglieder, die er sehen darf (mit Hinweis, wenn welche fehlen).
 * Anlegen und ändern darf, wer auf dem Standort Anlagen anlegen darf.
 */
final class BereichController extends Controller
{
    private function bereich(array $params, bool $aendern = false): ?array
    {
        $b = BereichService::find((int) ($params['id'] ?? 0));
        if ($b === null || !BereichService::sichtbar($b)) {
            Response::notFound();
            return null;
        }
        if ($aendern && !Zugriff::darfAufStandortAnlegen((int) $b['site_id'])) {
            Response::forbidden('Diesen Bereich darfst du nicht ändern.');
            return null;
        }
        return $b;
    }

    public function index(Request $request): void
    {
        $bereiche = BereichService::alle();
        $live = [];
        foreach ($bereiche as $b) {
            $live[(int) $b['id']] = BereichService::live($b);
        }
        $this->view('bereiche/index', [
            'bereiche' => $bereiche,
            'live'     => $live,
            'darf'     => Auth::can('anlagen.manage'),
            'scripts'  => ['/assets/js/live.js'],
        ]);
    }

    /** Livewerte aller sichtbaren Bereiche (oder eines) – für die Aktualisierung alle 5 s. */
    public function live(Request $request): void
    {
        // Nur lesen, und das regelmäßig – die Sitzung nicht gesperrt halten.
        Session::freigeben();
        $id = $request->int('id');
        $out = [];
        foreach (BereichService::alle() as $b) {
            if ($id > 0 && (int) $b['id'] !== $id) {
                continue;
            }
            $out[(int) $b['id']] = BereichService::live($b);
        }
        $this->json(['ts' => time(), 'bereiche' => $out]);
    }

    public function neu(Request $request): void
    {
        $standorte = array_values(array_filter(SiteService::all(), static fn ($s) => Zugriff::darfAufStandortAnlegen((int) $s['id'])));
        $siteId = $request->int('standort') ?: (int) ($standorte[0]['id'] ?? 0);
        $this->formular([
            'id' => 0, 'name' => '', 'site_id' => $siteId, 'farbe' => '#0ea5e9', 'grenze_kw' => null, 'grenze_a' => null, 'notizen' => '',
        ], [], $standorte);
    }

    public function bearbeiten(Request $request, array $params): void
    {
        $b = $this->bereich($params, true);
        if ($b === null) {
            return;
        }
        $standorte = array_values(array_filter(SiteService::all(), static fn ($s) => Zugriff::darfAufStandortAnlegen((int) $s['id'])));
        $this->formular($b, BereichService::mitgliedSchluessel((int) $b['id']), $standorte);
    }

    private function formular(array $bereich, array $gewaehlt, array $standorte): void
    {
        $siteId = (int) $bereich['site_id'];
        $objekte = $siteId > 0 ? Zugriff::filtern(AnlagenService::liste(['site_id' => $siteId])) : [];
        // Messgeräte an Abgängen – einzeln wählbar (der Shelly am Abgang eines
        // Foodtrucks). Je Abgang eine Zeile, auch wenn dort nacheinander
        // verschiedene Geräte steckten (Inventar-Einsätze): gezählt wird die Stelle.
        $abgaenge = [];
        $gewaehlt = array_flip($gewaehlt);
        if ($objekte !== []) {
            $ids = implode(',', array_map(static fn ($o) => (int) $o['id'], $objekte));
            $jeStelle = [];
            foreach (Database::select(
                "SELECT m.*, a.bezeichnung AS anschluss_name, a.phase AS anschluss_phase, t.name AS typ_name
                 FROM messpunkte m JOIN anschluesse a ON a.id = m.anschluss_id JOIN steckertypen t ON t.id = a.steckertyp_id
                 WHERE m.objekt_id IN ($ids) ORDER BY a.nr, m.gueltig_bis IS NULL, m.id"
            ) as $m) {
                $jeStelle[(int) $m['anschluss_id']][] = $m;
            }
            foreach ($jeStelle as $stelle) {
                $zeile = $stelle[count($stelle) - 1];   // das jetzige (sonst das letzte) Gerät
                foreach ($stelle as $m) {
                    if (isset($gewaehlt['m:' . (int) $m['id']])) {
                        $gewaehlt['m:' . (int) $zeile['id']] = true;
                    }
                }
                $abgaenge[(int) $zeile['objekt_id']][] = $zeile;
            }
        }
        $this->view('bereiche/form', [
            'bereich'   => $bereich,
            'gewaehlt'  => $gewaehlt,
            'standorte' => $standorte,
            'objekte'   => $objekte,
            'abgaenge'  => $abgaenge,
        ]);
    }

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        if ($id !== null && $this->bereich(['id' => $id], true) === null) {
            return;
        }
        if (!Zugriff::darfAufStandortAnlegen($request->int('site_id') ?: null)) {
            Response::forbidden('Auf diesem Standort darfst du keine Bereiche anlegen.');
            return;
        }
        $ergebnis = BereichService::speichern($id, [
            'name' => $request->str('name'), 'site_id' => $request->int('site_id'), 'farbe' => $request->str('farbe'),
            'grenze_kw' => $request->str('grenze_kw'), 'grenze_a' => $request->str('grenze_a'), 'notizen' => $request->str('notizen'),
            'mitglieder' => $request->arr('mitglieder'),
        ]);
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
            $this->back('/bereiche');
            return;
        }
        Flash::success($ergebnis['message']);
        $this->redirect('/bereiche/' . (int) $ergebnis['id']);
    }

    public function loeschen(Request $request, array $params): void
    {
        $b = $this->bereich($params, true);
        if ($b === null) {
            return;
        }
        $ergebnis = BereichService::loeschen((int) $b['id']);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/bereiche');
    }

    public function show(Request $request, array $params): void
    {
        $b = $this->bereich($params);
        if ($b === null) {
            return;
        }
        [$von, $bis, $vorgabe] = Zeitraum::ausAnfrage($request);
        $aufbau = BereichService::aufbau($b);
        $reiter = $request->str('reiter') === 'material' ? 'material' : 'last';
        $statistik = Auth::can('statistik.view');
        $this->view('bereiche/show', [
            'bereich'    => $b,
            'aufbau'     => $aufbau,
            'live'       => BereichService::live($b, $aufbau),
            'reiter'     => $reiter,
            'von'        => $von,
            'bis'        => $bis,
            'vorgabe'    => $vorgabe,
            'statistik'  => $statistik,
            'kennzahlen' => $statistik && $reiter === 'last' ? BereichService::kennzahlen($b, $von, $bis, $aufbau) : null,
            'energie'    => $statistik && $reiter === 'last' ? BereichService::energieTage($b, $von, $bis, $aufbau) : [],
            'material'   => $reiter === 'material' ? MaterialService::fuerBereich($b, $aufbau) : null,
            'darf'       => Zugriff::darfAufStandortAnlegen((int) $b['site_id']),
            'stylesheets' => ['/assets/vendor/uplot/uPlot.min.css'],
            'scripts'    => ['/assets/vendor/uplot/uPlot.iife.min.js', '/assets/js/live.js', '/assets/js/statistik.js'],
        ]);
    }

    /** Summenverlauf als JSON – im Format von /statistik/daten, damit statistik.js ihn zeichnet. */
    public function daten(Request $request, array $params): void
    {
        $b = $this->bereich($params);
        if ($b === null) {
            return;
        }
        [$von, $bis] = Zeitraum::ausAnfrage($request);
        $feld = $request->str('feld') === 'i' ? 'i' : 'p';
        $fehler = null;
        $reihe = BereichService::reihe($b, $feld, $von, $bis, null, $fehler);
        if ($fehler !== null && !Auth::can('datenquellen.manage')) {
            // Wie in der Statistik: die Meldung der InfluxDB ins Protokoll.
            \App\Support\Logger::info('Bereich ' . (int) $b['id'] . ': ' . $fehler);
            $fehler = 'Die Messdaten ließen sich gerade nicht abfragen.';
        }
        $this->json([
            'ok' => $fehler === null, 'message' => $fehler, 'feld' => $feld, 'einheit' => Messdaten::einheit($feld),
            'name' => Messdaten::feldName($feld), 'zeiten' => $reihe['zeiten'], 'serien' => $reihe['serien'], 'schritt' => $reihe['schritt'],
        ]);
    }

    /** Summenverlauf als CSV (Leistung gesamt und je Phase, Ströme je Phase). */
    public function csv(Request $request, array $params): void
    {
        $b = $this->bereich($params);
        if ($b === null) {
            return;
        }
        [$von, $bis] = Zeitraum::ausAnfrage($request);
        $aufbau = BereichService::aufbau($b);
        $daten = [];
        $spalten = ['zeit'];
        foreach (['p' => 'W', 'i' => 'A'] as $feld => $einheit) {
            $reihe = BereichService::reihe($b, $feld, $von, $bis, $aufbau);
            foreach ($reihe['serien'] as $phase => $werte) {
                $spalte = Messdaten::feldName($feld) . ' ' . ($phase === 'sum' ? 'gesamt' : $phase) . ' [' . $einheit . ']';
                $spalten[] = $spalte;
                foreach ($reihe['zeiten'] as $k => $ms) {
                    if ($werte[$k] !== null) {
                        $daten[$ms][$spalte] = $werte[$k];
                    }
                }
            }
        }
        ksort($daten);
        $zeilen = [implode(';', array_map([StatistikController::class, 'csvFeld'], $spalten))];
        foreach ($daten as $ms => $werte) {
            $zeile = [Zeit::anzeige(Zeit::iso(intdiv($ms, 1000)), 'Y-m-d H:i:s')];
            foreach (array_slice($spalten, 1) as $s) {
                $zeile[] = isset($werte[$s]) ? str_replace('.', ',', (string) round((float) $werte[$s], 3)) : '';
            }
            $zeilen[] = implode(';', $zeile);
        }
        Audit::log('bereich.csv', 'bereich', (string) $b['id'], ['von' => Zeit::iso($von), 'bis' => Zeit::iso($bis)]);
        $name = 'bereich-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $b['name'])) . '-' . date('Ymd-Hi', $von) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $zeilen) . "\r\n";
    }

    /** Materialliste zum Drucken. */
    public function material(Request $request, array $params): void
    {
        $b = $this->bereich($params);
        if ($b === null) {
            return;
        }
        $this->view('material/druck', [
            'titel'    => 'Material · ' . $b['name'],
            'untertitel' => (string) ($b['site_name'] ?? ''),
            'material' => MaterialService::fuerBereich($b),
        ], 'layout/druck');
    }
}
