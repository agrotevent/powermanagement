<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\MqttZugaenge;
use App\Support\Config;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Eigene MQTT-Zugänge der Shellys – Übersicht und Umstellung Gerät für Gerät.
 *
 * Ansehen: „datenquellen.view“ (sieht ohnehin alle Geräte). Ändern:
 * „mqtt.zugaenge“ und dazu alle Geräte ohne Freigabe-Grenze
 * (anlagen.scope.all) – ein Zugang am Broker gilt für das ganze Gerät, und
 * die Umstellung sperrt den gemeinsamen Zugang darauf.
 */
final class MqttZugaengeController extends Controller
{
    private const ZIEL = '/datenquellen/mqtt-zugaenge';

    public function index(Request $request): void
    {
        $fehler = null;
        $filter = $request->str('filter');
        $liste = MqttZugaenge::uebersicht($fehler);
        $zaehler = ['gemeinsam' => 0, 'wartet' => 0, 'eigen' => 0, 'nicht' => 0];
        foreach ($liste as $z) {
            $zaehler[self::gruppe($z)]++;
        }
        if (isset($zaehler[$filter])) {
            $liste = array_values(array_filter($liste, static fn (array $z): bool => self::gruppe($z) === $filter));
        } else {
            $filter = '';
        }
        // Bestätigt Node-RED die Schutzliste? Nur fragen, wenn es etwas zu
        // schützen gibt – das kostet einen Blick auf den Broker (bis 1,5 s,
        // ohne die Sitzung zu halten).
        $schutz = null;
        if (!Config::isDemo() && $zaehler['eigen'] > 0) {
            $schutz = Session::ohneSperre(static fn (): ?array => MqttZugaenge::schutzStatus());
        }
        $this->view('mqtt-zugaenge/index', [
            'schutz'    => $schutz,
            'liste'     => $liste,
            'zaehler'   => $zaehler,
            'filter'    => $filter,
            'fehler'    => $fehler,
            'ergebnis'  => MqttZugaenge::ergebnis(),
            'grund'     => MqttZugaenge::verfuegbar(),
            'hindernis' => Config::isDemo() ? null : MqttZugaenge::sperreHindernis(),
            'darf'      => Auth::can('mqtt.zugaenge') && Zugriff::alles(),
        ]);
    }

    private static function gruppe(array $z): string
    {
        return match (true) {
            $z['zustand'] === 'eigen'     => 'eigen',
            $z['zugang'] !== null         => 'wartet',
            !$z['umstellbar']             => 'nicht',
            default                       => 'gemeinsam',
        };
    }

    /** Ändern nur mit allen Geräten – sonst 403. */
    private function erlaubt(): bool
    {
        header('Cache-Control: no-store');
        if (!Zugriff::alles()) {
            Response::forbidden();
            return false;
        }
        return true;
    }

    public function einrichten(Request $request): void
    {
        if (!$this->erlaubt()) {
            return;
        }
        $this->ergebnis($request, static fn (): array => MqttZugaenge::einrichten($request->str('praefix'), $request->int('generation') === 1 ? 1 : 2, $request->bool('von_hand')));
    }

    public function uebertragen(Request $request): void
    {
        if ($this->erlaubt()) {
            $this->ergebnis($request, static fn (): array => MqttZugaenge::uebertragen($request->int('id')));
        }
    }

    public function pruefen(Request $request): void
    {
        if ($this->erlaubt()) {
            $this->ergebnis($request, static fn (): array => MqttZugaenge::pruefen($request->int('id'), $request->bool('erzwingen')));
        }
    }

    public function erneuern(Request $request): void
    {
        if ($this->erlaubt()) {
            $this->ergebnis($request, static fn (): array => MqttZugaenge::kennwortErneuern($request->int('id')));
        }
    }

    public function entfernen(Request $request): void
    {
        if ($this->erlaubt()) {
            $this->ergebnis($request, static fn (): array => MqttZugaenge::entfernen($request->int('id'), $request->bool('erzwingen')));
        }
    }

    /**
     * $ablauf ausführen und sein Ergebnis zeigen.
     *
     * Die Abläufe warten auf Gerät und Broker – eine Prüfung bis zu einer
     * halben Minute. Das geschieht ohne Sitzungssperre (Session::ohneSperre()),
     * sonst stünden alle anderen Anfragen desselben Browsers so lange an.
     * Zwei Abläufe nebeneinander (Doppelklick, zweiter Reiter) gab es schon
     * zwischen zwei Bedienern; dafür ändert jeder einen Zugang nur aus dem
     * Zustand, in dem er ihn gelesen hat (setzen()), und ein Kennwort gilt
     * erst, wenn der Broker es bei der Kontoprobe annimmt.
     *
     * Ein Kennwort (Gen1) wird genau einmal gezeigt: direkt in der Antwort,
     * nie in Flash, Sitzung oder Weiterleitung.
     *
     * @param callable():array<string,mixed> $ablauf
     */
    private function ergebnis(Request $request, callable $ablauf): void
    {
        $ergebnis = Session::ohneSperre($ablauf);
        if (isset($ergebnis['kennwort'])) {
            $this->view('mqtt-zugaenge/einmalig', [
                'benutzer' => (string) ($ergebnis['benutzer'] ?? ''),
                'kennwort' => (string) $ergebnis['kennwort'],
                'meldung'  => (string) $ergebnis['message'],
                'generation' => (int) ($ergebnis['generation'] ?? 1),
            ]);
            return;
        }
        unset($ergebnis['kennwort']);
        $this->antwort($request, $ergebnis, self::ZIEL);
    }
}
