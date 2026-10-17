<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Support\Audit;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Zeit;
use App\Support\Zeitraum;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

/**
 * Tanks: Verlauf und CSV des Füllstands.
 *
 * Das Recht prüft die Route (statistik.view bzw. statistik.export); hier
 * wird jede ID einzeln geprüft: der Tank (sichtbar, kein Katalog-Typ) und
 * ein gewählter Messpunkt (Füllstand dieses Tanks). Gelesen wird über
 * TankService, und der liest nur über Messdaten – im Datenfenster.
 */
final class TankController extends Controller
{
    /**
     * Den Tank aus der Adresse – oder 404 (auch, wenn er nicht sichtbar ist).
     * Beim Ändern braucht es „bearbeiten“ am Tank, sonst 403. Abgelehnt wird
     * als JSON, wenn der Aufrufer JSON erwartet ($json oder Accept).
     */
    private function tank(Request $request, array $params, bool $aendern = false, bool $json = false): ?array
    {
        $tank = AnlagenService::find((int) ($params['id'] ?? 0));
        if ($tank === null || AnlagenService::elektrisch((string) $tank['art']) || (int) $tank['ist_vorlage'] === 1 || !Zugriff::siehtObjekt($tank)) {
            $this->ablehnen($request, $json, 404, 'Diesen Tank gibt es nicht.');
            return null;
        }
        if ($aendern && !Zugriff::darfBearbeiten($tank)) {
            $this->ablehnen($request, $json, 403, 'An diesem Tank darfst du nichts eintragen.');
            return null;
        }
        return $tank;
    }

    /** Der gewählte Füllstand-Messpunkt (?mp=) – null für alle; false, wenn er nicht zu diesem Tank gehört. */
    private function messpunkt(Request $request, array $tank): int|null|false
    {
        $mp = $request->int('mp');
        if ($mp <= 0) {
            return null;
        }
        return in_array($mp, array_map(static fn (array $m): int => (int) $m['id'], TankService::sensoren((int) $tank['id'])), true) ? $mp : false;
    }

    /** Verlauf als JSON für das Diagramm – Format wie /statistik/daten, dazu „marken“. */
    public function verlauf(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, false, true);
        if ($tank === null) {
            return;
        }
        // Nur lesen, und die InfluxDB kann warten lassen – die Sitzung nicht halten.
        Session::freigeben();
        $mp = $this->messpunkt($request, $tank);
        if ($mp === false) {
            $this->json(['ok' => false, 'message' => 'Dieser Messpunkt gehört nicht zu diesem Tank.'], 404);
            return;
        }
        [$von, $bis] = Zeitraum::ausAnfrage($request);
        // statistik.js schickt die Größe als „feld“.
        $groesse = $request->str('groesse') !== '' ? $request->str('groesse') : $request->str('feld', 'liter');
        $fehler = null;
        $reihe = TankService::reihe($tank, $mp, $von, $bis, $groesse, $fehler);
        $this->json([
            'ok'      => $fehler === null,
            'message' => $this->fehlerText($fehler),
            'feld'    => $reihe['groesse'],
            'einheit' => $reihe['einheit'],
            'name'    => $reihe['name'],
            'zeiten'  => $reihe['zeiten'],
            'serien'  => $reihe['serien'],
            'schritt' => $reihe['schritt'],
            'teile'   => $reihe['teile'],
            'marken'  => $reihe['marken'],
        ]);
    }

    /** CSV: Rohwert, Füllhöhe, Inhalt und Füllstand je Zeitpunkt, im Datenfenster. */
    public function csv(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params);
        if ($tank === null) {
            return;
        }
        Session::freigeben();
        $mp = $this->messpunkt($request, $tank);
        if ($mp === false) {
            Response::notFound('Dieser Messpunkt gehört nicht zu diesem Tank.');
            return;
        }
        [$von, $bis] = Zeitraum::ausAnfrage($request);
        $fehler = null;
        $roh = TankService::rohwerte($tank, $mp, $von, $bis, $fehler);
        if ($fehler !== null) {
            Logger::info('Tank-CSV: ' . $fehler);
        }
        $einheit = count($roh['felder']) === 1 ? (TankRechnung::ROHFELDER[$roh['felder'][0]][1] ?? '') : '';
        $zeilen = [implode(';', array_map([StatistikController::class, 'csvFeld'],
            ['Zeit', 'Sensor', 'Rohwert' . ($einheit !== '' ? ' [' . $einheit . ']' : ''), 'Füllhöhe [mm]', 'Inhalt [l]', 'Füllstand [%]']))];
        $zahl = static fn (?float $w, int $n): string => $w === null ? '' : str_replace('.', ',', (string) round($w, $n));
        foreach ($roh['punkte'] as $i => $p) {
            if ($p === null) {
                continue;
            }
            $erg = $p['rechnung'] !== null ? TankRechnung::umrechnen($p['rechnung'], $p['roh']) : null;
            $zeilen[] = implode(';', [
                Zeit::anzeige(Zeit::iso(intdiv($roh['zeiten'][$i], 1000)), 'Y-m-d H:i:s'),
                StatistikController::csvFeld($p['kennung']),
                $zahl($p['roh'], 4),
                $zahl($erg['hoehe_mm'] ?? null, 1),
                $zahl($erg['liter'] ?? null, 1),
                $zahl($erg['prozent'] ?? null, 2),
            ]);
        }
        Audit::log('tank.csv', 'objekt', (string) $tank['id'], ['von' => Zeit::iso($von), 'bis' => Zeit::iso($bis), 'messpunkt' => $mp]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tank-' . (int) $tank['id'] . '-' . date('Ymd-Hi', $von) . '-' . date('Ymd-Hi', $bis) . '.csv"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $zeilen) . "\r\n";
    }

    /** Die Meldung der InfluxDB zeigt Aufbau und Inhalt der Abfrage – im Klartext nur für die Messtechnik. */
    private function fehlerText(?string $fehler): ?string
    {
        if ($fehler === null || Auth::can('datenquellen.manage')) {
            return $fehler;
        }
        Logger::info('Tankverlauf: ' . $fehler);
        return 'Die Messdaten ließen sich gerade nicht abfragen.';
    }

    private function ablehnen(Request $request, bool $json, int $status, string $text): void
    {
        if ($json || $request->wantsJson()) {
            $this->json(['ok' => false, 'message' => $text], $status);
            return;
        }
        $status === 404 ? Response::notFound($text) : Response::forbidden($text);
    }
}
