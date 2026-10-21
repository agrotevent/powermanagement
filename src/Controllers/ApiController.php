<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiZugang;
use App\Services\ApiBestand;
use App\Services\LocationBundle;
use App\Services\VorschlagService;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Logger;
use App\Support\Request;

/**
 * Die Schnittstelle für andere Instanzen: /api/v1/… – dasselbe Protokoll wie
 * MasterAdmin.
 *
 *   GET  /api/v1/info                                     wer bin ich, was darf das Token
 *   GET  /api/v1/locations                                freigegebene Locations mit Etagen
 *   GET  /api/v1/locations/{schluessel}                   eine Location (location + strom)
 *   GET  /api/v1/locations/{schluessel}/datei?pfad=plaene/…  eine Plandatei
 *   POST /api/v1/locations/{schluessel}/vorschlaege       Änderungen schicken
 *
 * Läuft vor Sitzung und Router (public/index.php): ohne Cookie, ohne
 * CSRF-Token, nie mit Weiterleitung – angemeldet wird jede Anfrage einzeln
 * mit `Authorization: Bearer mtma_…`. Geantwortet wird immer mit JSON, nur
 * die Plandatei kommt als Datei. Jeder Pfad unter /api verlangt zuerst ein
 * gültiges Token; ein ungültiges bekommt immer genau dieselbe Antwort.
 *
 * Was ein Token sieht, kommt nur aus seinen Befugnissen und Standorten
 * (ApiZugang) – angemeldet wird dabei niemand, auch nicht der Benutzer, an
 * den ein Token gebunden ist.
 */
final class ApiController
{
    /** Gesetzt, solange eine Anfrage an die Schnittstelle läuft – dann antworten auch die Fehlerbehandler mit JSON (bootstrap.php). */
    public static bool $aktiv = false;

    public const PRAEFIX = '/api/';

    /** Größter Körper (JSON, Plandateien als base64). nginx lässt 50 MB durch. */
    public const MAX_KOERPER = 48 * 1024 * 1024;

    /** Schlüssel in Adressen – wie Location-Schlüssel, höchstens 190 Zeichen. */
    private const SCHLUESSEL = '[a-z0-9-]{1,190}';

    private bool $ohneKoerper = false;

    public static function zustaendig(string $pfad): bool
    {
        return $pfad === '/api' || str_starts_with($pfad, self::PRAEFIX);
    }

    public function bearbeiten(Request $request): void
    {
        self::$aktiv = true;
        // Auch mit app.debug keine PHP-Meldungen in der Antwort: sie brächen
        // das JSON und verrieten Pfade. Was schiefgeht, steht im Log.
        ini_set('display_errors', '0');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
        header('Vary: Authorization');

        try {
            $this->verteilen($request);
        } catch (\Throwable $e) {
            // Nie Kopf oder Körper ins Log – darin stünde das Token.
            Logger::error('API: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            if (!headers_sent()) {
                header_remove('Content-Disposition');
                header_remove('Content-Length');
                $this->antwort(['ok' => false, 'message' => 'Unerwarteter Fehler. Details stehen im Log.'], 500);
            }
        }
    }

    private function verteilen(Request $request): void
    {
        $methode = $request->method();
        if ($methode === 'HEAD') {
            // HEAD ist GET ohne Körper (RFC 9110) – den schicken wir gar nicht erst.
            $methode = 'GET';
            $this->ohneKoerper = true;
        }

        $token = ApiZugang::pruefen($request->kopf('Authorization'), $request->ip());
        if ($token === null) {
            header('WWW-Authenticate: Bearer realm="PowerManagement"');
            $this->antwort(['ok' => false, 'message' => 'Kein gültiges Token.'], 401);
            return;
        }
        Audit::akteur('api:' . mb_substr((string) $token['name'], 0, 60));

        $pfad = $request->path();
        $k = self::SCHLUESSEL;

        if ($pfad === '/api/v1/info') {
            if ($this->nurGet($methode)) {
                $this->info($token);
            }
            return;
        }
        if ($pfad === '/api/v1/locations') {
            if ($this->nurGet($methode) && $this->darf($token, 'plaene.lesen')) {
                $this->antwort(['ok' => true, 'locations' => ApiBestand::locations($token)]);
            }
            return;
        }
        if (preg_match('#^/api/v1/locations/(' . $k . ')$#', $pfad, $m) === 1) {
            if ($this->nurGet($methode) && $this->darf($token, 'plaene.lesen')) {
                $this->location($token, $m[1]);
            }
            return;
        }
        if (preg_match('#^/api/v1/locations/(' . $k . ')/datei$#', $pfad, $m) === 1) {
            if ($this->nurGet($methode) && $this->darf($token, 'plaene.lesen')) {
                $this->datei($token, $m[1]);
            }
            return;
        }
        if (preg_match('#^/api/v1/locations/(' . $k . ')/vorschlaege$#', $pfad, $m) === 1) {
            if ($methode !== 'POST') {
                $this->nichtErlaubt('POST');
                return;
            }
            if ($this->darf($token, 'plaene.vorschlagen')) {
                $this->vorschlag($request, $token, $m[1]);
            }
            return;
        }
        $this->antwort(['ok' => false, 'message' => 'Unbekannte Adresse.'], 404);
    }

    private function info(array $token): void
    {
        $this->antwort([
            'ok'      => true,
            'instanz' => (string) Config::get('app.name', 'PowerManagement'),
            // Fehlt „produkt“, ist die Gegenseite MasterAdmin.
            'produkt' => 'powermanagement',
            'api'     => 1,
            'format'  => LocationBundle::FORMAT_LOCATION,
            'formate' => ['location' => LocationBundle::FORMAT_LOCATION, 'strom' => LocationBundle::FORMAT_STROM],
            'token'   => [
                'name'       => (string) $token['name'],
                'befugnisse' => array_values((array) $token['befugnisse']),
                'bis'        => $token['expires_at'] !== null && (string) $token['expires_at'] !== '' ? (string) $token['expires_at'] : null,
            ],
        ]);
    }

    private function location(array $token, string $schluessel): void
    {
        $bestand = ApiBestand::location($token, $schluessel);
        if ($bestand === null) {
            $this->antwort(['ok' => false, 'message' => 'Location nicht gefunden.'], 404);
            return;
        }
        $this->antwort(['ok' => true, 'stand' => $bestand['stand'], 'location' => $bestand['location'], 'strom' => $bestand['strom']]);
    }

    /**
     * Eine Plandatei – nur über ihren Namen im zugeschnittenen Bestand
     * (Liste der erlaubten Pfade), nie über einen Pfad auf der Platte.
     */
    private function datei(array $token, string $schluessel): void
    {
        $bestand = ApiBestand::location($token, $schluessel);
        $pfad = is_string($_GET['pfad'] ?? null) ? (string) $_GET['pfad'] : '';
        $datei = $bestand !== null && $pfad !== '' ? ($bestand['dateien'][$pfad] ?? null) : null;
        $groesse = $datei !== null && is_file($datei) ? filesize($datei) : false;
        if ($groesse === false) {
            $this->antwort(['ok' => false, 'message' => 'Datei nicht gefunden.'], 404);
            return;
        }
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) $groesse);
        // Der Name ist ein Schlüssel samt Endung (plaene/<etage>.<endung>) – nichts zum Entschärfen.
        header('Content-Disposition: attachment; filename="' . basename($pfad) . '"');
        if (!$this->ohneKoerper) {
            readfile($datei);
        }
    }

    /**
     * Änderungen einer anderen Instanz entgegennehmen – als Vorschlag oder,
     * mit „plaene.schreiben“ und passendem Stand, direkt.
     *
     * Hier wird nur der Körper geprüft (Art, Größe, JSON); was darin steht,
     * prüft VorschlagService (alles oder nichts) und nennt den Status:
     * 201, 403, 404, 409 (Stand veraltet), 422, 429.
     */
    private function vorschlag(Request $request, array $token, string $schluessel): void
    {
        if (preg_match('#^application/json\s*(;|$)#i', $request->kopf('Content-Type')) !== 1) {
            $this->antwort(['ok' => false, 'message' => 'Erwartet wird Content-Type: application/json.'], 415);
            return;
        }
        $roh = $request->koerper(self::MAX_KOERPER);
        if ($roh === null) {
            $this->antwort(['ok' => false, 'message' => sprintf('Der Körper ist zu groß (höchstens %d MiB).', self::MAX_KOERPER / 1024 / 1024)], 413);
            return;
        }
        try {
            $koerper = json_decode($roh, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $koerper = null;
        }
        if (!is_array($koerper) || !is_array($koerper['location'] ?? null)) {
            $this->antwort(['ok' => false, 'message' => 'Erwartet wird JSON mit „location“ (dazu optional „strom“, „dateien“, „notiz“, „absender“, „basis“).'], 400);
            return;
        }
        unset($roh);
        $ergebnis = VorschlagService::einreichen($token, $schluessel, $koerper);
        $status = (int) ($ergebnis['status'] ?? (!empty($ergebnis['ok']) ? 201 : 422));
        unset($ergebnis['status']);
        $this->antwort($ergebnis, $status);
    }

    /** Nur GET (und HEAD) – sonst 405 mit Allow. */
    private function nurGet(string $methode): bool
    {
        if ($methode === 'GET') {
            return true;
        }
        $this->nichtErlaubt('GET, HEAD');
        return false;
    }

    private function nichtErlaubt(string $erlaubt): void
    {
        header('Allow: ' . $erlaubt);
        $this->antwort(['ok' => false, 'message' => 'Methode nicht erlaubt.'], 405);
    }

    /** Fehlt die Befugnis: 403 – bevor irgendetwas nachgeschlagen wird. */
    private function darf(array $token, string $befugnis): bool
    {
        if (ApiZugang::darf($token, $befugnis)) {
            return true;
        }
        $this->antwort(['ok' => false, 'message' => sprintf('Dem Token fehlt die Befugnis „%s“.', $befugnis)], 403);
        return false;
    }

    private function antwort(array $daten, int $status = 200): void
    {
        $json = (string) json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        if (!$this->ohneKoerper) {
            echo $json;
        }
    }
}
