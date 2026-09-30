<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Messung\Influx;
use App\Messung\Messdaten;
use App\Messung\Mqtt;
use App\Services\LocationSync;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Settings;

final class EinstellungenController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('system/einstellungen', [
            'werte'   => Settings::all(),
            'geheim'  => [
                'influx'  => Settings::geheimnisHinterlegt(Settings::GEHEIM_INFLUX_TOKEN) || Settings::geheimnisHinterlegt(Settings::GEHEIM_INFLUX_KENNWORT),
                'mqtt'    => Settings::geheimnisHinterlegt(Settings::GEHEIM_MQTT_KENNWORT),
                'repo'    => LocationSync::tokenQuelle(),
            ],
        ]);
    }

    public function speichern(Request $request): void
    {
        $geaendert = [];
        foreach (Settings::OVERRIDABLE as $schluessel => $_beschriftung) {
            if (str_starts_with($schluessel, 'inventory.')) {
                continue; // eigenes Formular mit eigenem Recht
            }
            $feld = str_replace('.', '__', $schluessel);
            if (!array_key_exists($feld, $_POST)) {
                continue;
            }
            $wert = trim((string) $_POST[$feld]);
            if ($wert === '') {
                Settings::forget($schluessel);
            } else {
                Settings::put($schluessel, $wert);
            }
            $geaendert[] = $schluessel;
        }
        Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_TOKEN, $request->str('influx_token'));
        Settings::geheimnisSetzen(Settings::GEHEIM_INFLUX_KENNWORT, $request->str('influx_password'));
        Settings::geheimnisSetzen(Settings::GEHEIM_MQTT_KENNWORT, $request->str('mqtt_password'));
        Audit::log('einstellungen.speichern', 'settings', '', ['schluessel' => $geaendert]);
        Flash::success('Einstellungen gespeichert.');
        $this->redirect('/system/einstellungen');
    }

    public function location(Request $request): void
    {
        $adresse = $request->str('repo_url');
        $fehler = LocationSync::adresseFehler($adresse);
        if ($fehler !== null) {
            Flash::error($fehler);
            $this->redirect('/system/einstellungen');
            return;
        }
        $zweig = $request->str('branch') ?: 'main';
        if (!LocationSync::zweigGueltig($zweig)) {
            Flash::error('Der Zweig darf nur Buchstaben, Ziffern und . _ / - enthalten und nicht mit - beginnen.');
            $this->redirect('/system/einstellungen');
            return;
        }
        $vorher = LocationSync::repoUrl();
        $token = $request->str('token');
        Settings::put('inventory.repo_url', $adresse);
        Settings::put('inventory.branch', $zweig);
        $meldung = 'Location- und Verteiler-Abgleich gespeichert.';
        if ($token !== '') {
            LocationSync::tokenSetzen($token === '-' ? '' : $token);
        } elseif (LocationSync::tokenHinterlegt() && LocationSync::server($vorher) !== LocationSync::server($adresse)) {
            // Ein Token gilt für seinen Server. Zieht die Adresse um, ginge
            // es sonst bei der ersten Abfrage an den neuen – wer immer das ist.
            LocationSync::tokenSetzen('');
            $meldung .= ' Das hinterlegte Token galt für den bisherigen Server und ist entfernt – für den neuen bitte eines eintragen.';
        }
        Audit::log('einstellungen.location', 'settings', '', ['repo' => $adresse]);
        Flash::success($meldung);
        $this->redirect('/system/einstellungen');
    }

    public function pruefen(Request $request): void
    {
        if (Config::isDemo()) {
            Flash::info('Demo-Modus: InfluxDB und MQTT werden nicht gebraucht.');
            $this->redirect('/system/einstellungen');
            return;
        }
        $influx = Influx::pruefen();
        $influx['ok'] ? Flash::success($influx['message']) : Flash::error($influx['message']);
        try {
            $mqtt = Mqtt::ausKonfiguration('pm-einstellungen');
            $mqtt->abonnieren([trim((string) Config::get('mqtt.prefix', 'pm'), '/') . '/status/nodered']);
            $herz = $mqtt->lauschen(2, static fn () => true);
            $mqtt->trennen();
            Flash::success('MQTT-Broker erreichbar.' . ($herz === [] ? ' Von Node-RED kam kein Herzschlag (pm/status/nodered).' : ' Node-RED meldet sich.'));
        } catch (\Throwable $e) {
            Flash::error('MQTT: ' . $e->getMessage());
        }
        if ($influx['ok']) {
            $p = Messdaten::pipeline();
            $p === null
                ? Flash::warning('In der letzten Stunde hat Node-RED nichts in die InfluxDB geschrieben (Messung „pipeline“ leer).')
                : Flash::info('Node-RED schreibt: zuletzt ' . date('d.m.Y H:i:s', intdiv($p['zeit'], 1000)) . ', ' . (int) $p['fehler'] . ' Fehler, ' . (int) $p['puffer'] . ' Zeilen im Puffer.');
        }
        $this->redirect('/system/einstellungen');
    }
}
