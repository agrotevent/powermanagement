<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\MesspunktService;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Zeit;
use App\Support\Zeitraum;

/**
 * Statistik: Verläufe, Energie je Tag, Kennzahlen, CSV.
 *
 * Grundlage ist eine Auswahl von Messpunkten – aus der Auswertung (per
 * Klick auf ein Messgerät in Karte oder Verteilerplan), aus einer Anlage
 * (?anlage=) oder hier angekreuzt. Jede Abfrage läuft durch das
 * Datenfenster des Benutzers (Messdaten::reihe → Zugriff::begrenzen).
 */
final class StatistikController extends Controller
{
    /** Sichtbare Messpunkte, jeweils mit ihrer Anlage. @return array<int,array<string,mixed>> */
    private function sichtbareMesspunkte(): array
    {
        $objekte = [];
        foreach (Zugriff::filtern(AnlagenService::liste()) as $o) {
            $objekte[(int) $o['id']] = $o;
        }
        $out = [];
        foreach ($objekte as $o) {
            foreach (MesspunktService::fuerObjekt((int) $o['id'], true) as $m) {
                $m['objekt_name'] = $o['name'];
                $m['objekt'] = $o;
                $m['titel'] = MesspunktService::titel($m);
                $out[(int) $m['id']] = $m;
            }
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:string} */
    private function zeitraum(Request $request): array
    {
        return Zeitraum::ausAnfrage($request);
    }

    public function index(Request $request): void
    {
        $alle = $this->sichtbareMesspunkte();
        $gewaehlt = array_map('intval', $request->arr('mp'));
        $anlage = $request->int('anlage');
        if ($gewaehlt === [] && $anlage > 0) {
            $gewaehlt = array_keys(array_filter($alle, static fn ($m) => (int) $m['objekt_id'] === $anlage));
        }
        if ($gewaehlt === []) {
            $gewaehlt = MesspunktService::auswertung();
        }
        $gewaehlt = array_values(array_filter($gewaehlt, static fn ($id) => isset($alle[$id])));
        [$von, $bis, $vorgabe] = $this->zeitraum($request);

        $auswahl = [];
        foreach ($gewaehlt as $id) {
            $m = $alle[$id];
            $fenster = MesspunktService::fenster($m, $m['objekt']);
            $auswahl[] = [
                'id'       => $id,
                'titel'    => $m['titel'],
                'geraet'   => $m['geraet'],
                'kanal'    => $m['kanal'],
                'rolle'    => $m['rolle'],
                'objekt'   => $m['objekt'],
                'fenster'  => Zugriff::beschreiben($fenster),
                'teile'    => Zugriff::begrenzen($fenster, $von, min($bis, time())),
                'kennzahlen' => Messdaten::kennzahlen((string) $m['geraet'], (string) $m['kanal'], $von, $bis, $fenster),
                'energie'  => Messdaten::energieTage((string) $m['geraet'], (string) $m['kanal'], $von, $bis, $fenster),
            ];
        }

        $this->view('statistik/index', [
            'alle'        => $alle,
            'auswahl'     => $auswahl,
            'gewaehlt'    => $gewaehlt,
            'von'         => $von,
            'bis'         => $bis,
            'vorgabe'     => $vorgabe,
            'auswertung'  => MesspunktService::auswertung(),
            'stylesheets' => ['/assets/vendor/uplot/uPlot.min.css'],
            'scripts'     => ['/assets/vendor/uplot/uPlot.iife.min.js', '/assets/js/live.js', '/assets/js/statistik.js'],
        ]);
    }

    /** Eine Reihe als JSON für das Diagramm. */
    public function daten(Request $request): void
    {
        $alle = $this->sichtbareMesspunkte();
        $m = $alle[$request->int('mp')] ?? null;
        if ($m === null) {
            $this->json(['ok' => false, 'message' => 'Dieser Messpunkt ist nicht sichtbar.'], 404);
            return;
        }
        [$von, $bis] = $this->zeitraum($request);
        $feld = preg_replace('/[^a-z0-9_]/', '', $request->str('feld', 'p')) ?: 'p';
        $messung = $request->str('messung', 'strom') === 'zustand' ? 'zustand' : 'strom';
        $phasen = $messung === 'strom'
            ? array_values(array_intersect($request->arr('phase') ?: ['sum'], ['L1', 'L2', 'L3', 'N', 'sum']))
            : [];
        $fehler = null;
        $reihe = Messdaten::reihe($messung, (string) $m['geraet'], (string) $m['kanal'], $feld, $phasen, $von, $bis,
            MesspunktService::fenster($m, $m['objekt']), null, $request->str('agg', 'mean'), $fehler);
        if ($fehler !== null && !Auth::can('datenquellen.manage')) {
            // Die Fehlermeldung der InfluxDB zeigt Aufbau und Inhalt der
            // Abfrage – das gehört ins Protokoll, nicht zu jedem Betrachter.
            Logger::info('Statistik: ' . $fehler);
            $fehler = 'Die Messdaten ließen sich gerade nicht abfragen.';
        }
        $this->json([
            'ok'      => $fehler === null,
            'message' => $fehler,
            'feld'    => $feld,
            'einheit' => Messdaten::einheit($feld),
            'name'    => Messdaten::feldName($feld),
            'zeiten'  => $reihe['zeiten'],
            'serien'  => $reihe['serien'],
            'schritt' => $reihe['schritt'],
            'teile'   => $reihe['teile'],
        ]);
    }

    /** CSV der gewählten Messpunkte – Leistung je Phase, im Datenfenster. */
    public function csv(Request $request): void
    {
        $alle = $this->sichtbareMesspunkte();
        [$von, $bis] = $this->zeitraum($request);
        $zeilen = [];
        $spalten = ['zeit'];
        $daten = [];
        foreach (array_map('intval', $request->arr('mp')) as $id) {
            $m = $alle[$id] ?? null;
            if ($m === null) {
                continue;
            }
            $fenster = MesspunktService::fenster($m, $m['objekt']);
            foreach (['p' => ['L1', 'L2', 'L3', 'sum'], 'i' => ['L1', 'L2', 'L3'], 'u' => ['L1', 'L2', 'L3']] as $feld => $phasen) {
                $reihe = Messdaten::reihe('strom', (string) $m['geraet'], (string) $m['kanal'], $feld, $phasen, $von, $bis, $fenster);
                foreach ($reihe['serien'] as $phase => $werte) {
                    $spalte = $m['titel'] . ' ' . $feld . ' ' . $phase . ' [' . Messdaten::einheit($feld) . ']';
                    $spalten[] = $spalte;
                    foreach ($reihe['zeiten'] as $i => $ms) {
                        if ($werte[$i] !== null) {
                            $daten[$ms][$spalte] = $werte[$i];
                        }
                    }
                }
            }
        }
        ksort($daten);
        $spalten = array_values(array_unique($spalten));
        $zeilen[] = implode(';', array_map([self::class, 'csvFeld'], $spalten));
        foreach ($daten as $ms => $werte) {
            $zeile = [Zeit::anzeige(Zeit::iso(intdiv($ms, 1000)), 'Y-m-d H:i:s')];
            foreach (array_slice($spalten, 1) as $s) {
                $zeile[] = isset($werte[$s]) ? str_replace('.', ',', (string) round((float) $werte[$s], 3)) : '';
            }
            $zeilen[] = implode(';', $zeile);
        }
        Audit::log('statistik.csv', 'messpunkte', implode(',', $request->arr('mp')), ['von' => Zeit::iso($von), 'bis' => Zeit::iso($bis)]);
        $name = 'messwerte-' . date('Ymd-Hi', $von) . '-' . date('Ymd-Hi', $bis) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $zeilen) . "\r\n";
    }

    /**
     * Ein Textfeld für CSV. Beginnt es mit = + - @ (oder Tab/CR), liest eine
     * Tabellenkalkulation es als Formel – ein Anlagen- oder Gerätename wie
     * „=HYPERLINK(…)“ würde beim Öffnen ausgeführt. Ein vorangestelltes '
     * macht es wieder zu Text.
     */
    public static function csvFeld(string $wert): string
    {
        if ($wert !== '' && str_contains("=+-@\t\r", $wert[0]) && !is_numeric($wert)) {
            $wert = "'" . $wert;
        }
        return '"' . str_replace('"', '""', $wert) . '"';
    }

    public function umschalten(Request $request): void
    {
        $alle = $this->sichtbareMesspunkte();
        $id = $request->int('mp');
        if (!isset($alle[$id])) {
            $this->json(['ok' => false, 'message' => 'Dieses Messgerät ist nicht sichtbar.'], 404);
            return;
        }
        $drin = MesspunktService::umschalten($id);
        $this->json([
            'ok'        => true,
            'enthalten' => $drin,
            'anzahl'    => count(MesspunktService::auswertung()),
            'message'   => $alle[$id]['titel'] . ($drin ? ' ist in der Auswertung.' : ' ist nicht mehr in der Auswertung.'),
        ]);
    }

    public function leeren(Request $request): void
    {
        MesspunktService::auswertungLeeren();
        $this->redirect('/statistik');
    }
}
