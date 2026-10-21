<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Services\UpdateAutomatik;
use App\Services\UpdateService;
use App\Support\Audit;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Session;

/**
 * System → Update: Stand, Änderungen, Aktualisieren mit Fortschritt,
 * automatische Updates.
 *
 * Nur mit „system.update“ (prüft die Route). Die Seite holt nichts und
 * startet nichts: Suchen und Aktualisieren legen einen Auftrag für den
 * root-Helfer (UpdateService::anfordern), den Rest liest sie aus
 * storage/update/ist. Weiter geht es nur auf feste Pfade.
 */
final class UpdateController extends Controller
{
    private const ZIEL = '/system/update';

    public function index(Request $request): void
    {
        UpdateService::vermerken();
        UpdateService::vergessen();
        $jetzt = time();
        $zustand = UpdateService::zustand($jetzt);
        $stand = $zustand['stand'];
        $ziel = $stand['verfuegbar']['commit'] ?? null;
        $this->view('system/update', [
            'zustand'      => $zustand,
            'stand'        => $stand,
            'installiert'  => UpdateService::installiert(),
            'verlauf'      => UpdateService::verlauf(30),
            'protokoll'    => UpdateService::protokollDatei(),
            'hindernisse'  => in_array($zustand['zustand'], ['demo', 'nicht_eingerichtet'], true) ? [] : UpdateAutomatik::hindernisse($jetzt),
            'ziel'         => $ziel,
            'sperreSuchen' => UpdateService::sperrgrund('pruefen'),
            'sperreStarten' => UpdateService::sperrgrund('aktualisieren', $ziel),
            'dauerZuletzt' => UpdateService::dauerZuletzt(),
            'automatik'    => UpdateAutomatik::anzeige($jetzt, $zustand),
            'scripts'      => ['/assets/js/update.js'],
        ]);
    }

    /** Automatische Updates einstellen: Modus, Fenster, Karenz, Pause. */
    public function automatik(Request $request): void
    {
        $ziel = self::ZIEL . '#automatik';
        if (UpdateService::zustand()['zustand'] === 'demo') {
            Flash::error('Im Demo-Modus werden keine Updates eingestellt.');
            $this->redirect($ziel);
            return;
        }
        $erg = UpdateAutomatik::speichern(
            $request->str('modus'),
            $request->arr('tage'),
            $request->str('beginn'),
            $request->int('dauer', -1),
            $request->int('karenz', -1),
            $request->str('pause_bis')
        );
        if ($erg['ok']) {
            Audit::log('system.update.automatik', 'update', '', $erg['werte'] ?? []);
        }
        $this->antwort($request, $erg, $ziel);
    }

    /** Für update.js: nur lesen, ohne die Sitzung zu halten (alle 2 s abgefragt). */
    public function stand(Request $request): void
    {
        Session::freigeben();
        header('Cache-Control: no-store');
        $vorher = $request->str('stand');
        $this->json(UpdateService::statusJson(null, preg_match('/^[0-9]{1,12}-[0-9]{1,12}$/', $vorher) === 1 ? $vorher : null));
    }

    public function suchen(Request $request): void
    {
        $erg = $this->anfordern('pruefen', null, false);
        if ($erg['ok']) {
            Audit::log('system.update.anfordern', 'update', (string) $erg['kennung'], ['aktion' => 'pruefen', 'kennung' => $erg['kennung'], 'ausloeser' => 'hand']);
        }
        $this->antwort($request, $erg, self::ZIEL . '#fortschritt');
    }

    public function starten(Request $request): void
    {
        $ziel = $request->str('ziel');
        if (preg_match('/^[0-9a-f]{40}$/', $ziel) !== 1) {
            $this->antwort($request, ['ok' => false, 'message' => 'Welcher Stand eingespielt werden soll, fehlt – bitte die Seite neu laden.'], self::ZIEL);
            return;
        }
        $trotz = $request->bool('trotz_betrieb');
        $erg = $this->anfordern('aktualisieren', $ziel, $trotz);
        if (!empty($erg['bestaetigen']) && !$request->wantsJson()) {
            Flash::warning((string) $erg['message']);
            $this->redirect(self::ZIEL . '#aktualisieren');
            return;
        }
        if ($erg['ok']) {
            Audit::log('system.update.anfordern', 'update', (string) $erg['kennung'], [
                'aktion' => 'aktualisieren', 'kennung' => $erg['kennung'], 'ziel7' => substr($ziel, 0, 7), 'ausloeser' => 'hand',
                'trotz_betrieb' => $trotz, 'hindernisse' => array_column($erg['hindernisse'] ?? [], 'art'),
            ]);
        }
        $this->antwort($request, $erg, self::ZIEL . '#fortschritt');
    }

    /** @return array{ok:bool, message:string, kennung?:string, bestaetigen?:bool, hindernisse?:list<array{art:string,text:string}>} */
    private function anfordern(string $aktion, ?string $ziel, bool $trotz): array
    {
        try {
            return UpdateService::anfordern($aktion, $ziel, 'hand', Auth::name(), $trotz);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
