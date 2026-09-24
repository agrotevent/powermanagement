<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Config;
use App\Support\Request;
use App\Support\Settings;

/**
 * Das Web-App-Manifest.
 *
 * Wozu: legt man PowerManagement auf einem iPhone oder iPad auf den
 * Home-Bildschirm, startet Safari es sonst wie eine gewöhnliche Webseite –
 * mit Adressleiste oben und Tableiste unten. Auf einem Tablet, das zum
 * Einzeichnen von Kabeln in der Halle mitläuft, sind das zwei Streifen
 * Arbeitsfläche, die fehlen. Safari lässt sie nur weg, wenn die Seite sich
 * ausdrücklich als eigenständige Web-App zu erkennen gibt; genau das
 * steht hier drin (`display: standalone`).
 *
 * Warum nicht einfach eine Datei unter public/: der Name der Installation
 * ist einstellbar (Settings::OVERRIDABLE). Eine Datei auf der Platte
 * müsste bei jeder Umbenennung neu geschrieben werden – und würde von
 * einem Update wieder überschrieben. Hier wird er bei jedem Abruf gelesen.
 *
 * Kein Login: das Manifest holt der Browser, bevor irgendjemand angemeldet
 * ist. Es steht nichts darin, was nicht ohnehin auf der Anmeldeseite steht.
 */
final class ManifestController extends Controller
{
    public function manifest(Request $request): void
    {
        $name = (string) Config::get('app.name', 'PowerManagement');

        $manifest = [
            'name'             => $name,
            // Unter dem Symbol steht der Kurzname, nicht das Logo-Kürzel.
            'short_name'       => Settings::homeName(),
            'description'      => (string) Config::get('app.subtitle', 'Stromverteilung & Energiedaten'),
            'lang'             => 'de',
            'dir'              => 'ltr',
            // Der eigentliche Zweck: ohne Adress- und Tableiste starten.
            'display'          => 'standalone',
            'orientation'      => 'any',
            'start_url'        => '/dashboard',
            // Ohne scope landet ein Verweis nach außen im Fenster der
            // Web-App und lässt sich dort nicht mehr verlassen.
            'scope'            => '/',
            // Der Streifen hinter der Statusleiste – hell wie die Kopfzeile.
            'theme_color'      => '#ffffff',
            'background_color' => '#f1f5f9',
            'icons'            => [
                [
                    'src'     => '/assets/img/icon-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => '/assets/img/icon-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    // „maskable" erlaubt Android, das Symbol in seine eigene
                    // Form zu schneiden, ohne dass das M angeschnitten wird.
                    'src'     => '/assets/img/icon-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        /*
         * Der eigene Medientyp ist keine Förmlichkeit: nginx kennt die
         * Endung .webmanifest in älteren mime.types nicht und liefert sie
         * als application/octet-stream aus – dann wertet Safari das
         * Manifest nicht aus und startet wieder mit Adressleiste. Dieselbe
         * Falle wie seinerzeit bei den .mjs-Dateien von pdf.js.
         */
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
