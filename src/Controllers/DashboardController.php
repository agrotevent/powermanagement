<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\Datenquellen;
use App\Services\AnlagenService;
use App\Services\LiveService;
use App\Services\SiteService;
use App\Support\Request;

final class DashboardController extends Controller
{
    public function start(Request $request): void
    {
        $this->redirect(Auth::check() ? Auth::landingPath() : '/login');
    }

    public function index(Request $request): void
    {
        $siteId = $request->int('standort');
        $standorte = Zugriff::standorteFiltern(SiteService::all());
        $objekte = Zugriff::filtern(AnlagenService::liste($siteId > 0 ? ['site_id' => $siteId] : []));

        $warnungen = [];
        if (Auth::can('datenquellen.view')) {
            foreach (Datenquellen::alle() as $q) {
                if ((int) $q['aktiv'] === 1 && !empty($q['letzter_fehler']) && $q['art'] !== 'shelly') {
                    $warnungen[] = ['stufe' => 'bad', 'text' => $q['name'] . ': ' . $q['letzter_fehler'], 'link' => '/datenquellen'];
                }
            }
        }

        $live = LiveService::fuerObjekte($objekte);

        /*
         * Gesamteinspeisung: je Zweig des Verteilbaums die oberste Anlage
         * mit Messwert. Ein Netzanschluss ohne eigenes Messgerät zählt so
         * über den Hauptverteiler dahinter – und nichts doppelt.
         */
        $sichtbar = array_flip(array_map(static fn ($o) => (int) $o['id'], $objekte));
        $einspeisung = null;
        $gehen = static function (array $knoten) use (&$gehen, &$einspeisung, $live, $sichtbar): void {
            $p = isset($sichtbar[(int) $knoten['id']]) ? ($live[(int) $knoten['id']]['p'] ?? null) : null;
            if ($p !== null) {
                $einspeisung = ($einspeisung ?? 0.0) + (float) $p;
                return;
            }
            foreach ($knoten['kinder'] as $kind) {
                $gehen($kind);
            }
        };
        foreach (AnlagenService::baum($siteId > 0 ? $siteId : null) as $wurzel) {
            $gehen($wurzel);
        }

        $this->view('dashboard/index', [
            'standorte' => $standorte,
            'siteId'    => $siteId,
            'objekte'   => $objekte,
            'live'      => $live,
            'einspeisung' => $einspeisung,
            'schaltbar' => LiveService::schaltbar($objekte),
            'warnungen' => $warnungen,
            'scripts'   => ['/assets/js/live.js'],
        ]);
    }

    /** Livewerte aller sichtbaren Anlagen (für das Nachladen alle paar Sekunden). */
    public function live(Request $request): void
    {
        $siteId = $request->int('standort');
        $objekte = Zugriff::filtern(AnlagenService::liste($siteId > 0 ? ['site_id' => $siteId] : []));
        $this->json(['ts' => time(), 'objekte' => LiveService::fuerObjekte($objekte)]);
    }
}
