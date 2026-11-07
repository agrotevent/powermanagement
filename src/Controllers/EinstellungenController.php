<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Berichte\Einstellungen;
use App\Kunden\KundenEinstellungen;
use App\Messung\Influx;
use App\Messung\Messdaten;
use App\Messung\Mqtt;
use App\Services\Eventworx;
use App\Services\LocationSync;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Session;
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
            'eventworx' => Eventworx::zustand(),
            'berichte'  => Einstellungen::alle(),
            'kundenbereich' => KundenEinstellungen::alle() + [
                'adresse'          => KundenEinstellungen::adresse(),
                'adresse_roh'      => trim((string) Config::get('app.url', '')),
                'unverschluesselt' => KundenEinstellungen::unverschluesselt(),
            ],
        ]);
    }

    /**
     * Die Zugangsdaten dieses Formulars: Feld → [Schlüssel, Bezeichnung].
     * Jedes gilt für sein Ziel (Settings::BINDUNG).
     */
    private const GEHEIMNISSE = [
        'influx_token'    => [Settings::GEHEIM_INFLUX_TOKEN, 'das Token der InfluxDB'],
        'influx_password' => [Settings::GEHEIM_INFLUX_KENNWORT, 'das Kennwort der InfluxDB'],
        'mqtt_password'   => [Settings::GEHEIM_MQTT_KENNWORT, 'das Kennwort am MQTT-Broker'],
    ];

    public function speichern(Request $request): void
    {
        // Die Adresse der InfluxDB bekommt Token bzw. Kennwort mitgeschickt.
        if (array_key_exists('influx__url', $_POST)) {
            $fehler = Influx::adresseFehler(trim((string) $_POST['influx__url']));
            if ($fehler !== null) {
                Flash::error($fehler . ' Nichts gespeichert.');
                $this->redirect('/system/einstellungen');
                return;
            }
        }
        $zielVorher = [];
        foreach (self::GEHEIMNISSE as [$schluessel]) {
            $zielVorher[$schluessel] = Settings::ziel($schluessel);
        }
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
        $entfernt = [];
        foreach (self::GEHEIMNISSE as $feld => [$schluessel, $bezeichnung]) {
            $neu = $request->str($feld);
            if ($neu === '' && Settings::geheimnisHinterlegt($schluessel) && Settings::ziel($schluessel) !== $zielVorher[$schluessel]) {
                // Ein Geheimnis gilt für sein Ziel. Zieht die Adresse (oder der
                // Benutzer) um, ginge es sonst an das neue – wer immer das ist.
                Settings::geheimnisSetzen($schluessel, '-');
                $entfernt[$schluessel] = $bezeichnung;
            } else {
                Settings::geheimnisSetzen($schluessel, $neu);
            }
        }
        Audit::log('einstellungen.speichern', 'settings', '', ['schluessel' => $geaendert, 'entfernt' => array_keys($entfernt)]);
        if ($entfernt !== []) {
            Flash::warning('Einstellungen gespeichert. Entfernt, weil für das bisherige Ziel eingetragen: '
                . implode(', ', $entfernt) . ' – für das neue Ziel bitte neu eintragen.');
        } else {
            Flash::success('Einstellungen gespeichert.');
        }
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
        // Das Token aus der Umgebung zieht nie mit um (Settings::UMGEBUNG_REPO) – sagen, statt erst am 401.
        $fremd = LocationSync::umgebungFremdHinweis();
        Audit::log('einstellungen.location', 'settings', '', ['repo' => $adresse]);
        $fremd !== null ? Flash::warning($meldung . ' ' . $fremd) : Flash::success($meldung);
        $this->redirect('/system/einstellungen');
    }

    /**
     * Firma & Übergabeberichte: Firmendaten für Kopf und Fuß, Nummernkürzel,
     * Vorgaben und Texte. Ein ungültiger Wert verwirft die ganze Eingabe.
     * Namen im Formular: „firma.name“ → „firma__name“ (PHP machte aus dem
     * Punkt einen Unterstrich).
     */
    public function firma(Request $request): void
    {
        $daten = [];
        foreach (array_keys(Einstellungen::VORGABEN) as $schluessel) {
            $feld = str_replace('.', '__', $schluessel);
            if (array_key_exists($feld, $_POST)) {
                $daten[$schluessel] = $_POST[$feld];
            }
        }
        $ergebnis = Einstellungen::speichern($daten);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/einstellungen#firma');
    }

    /**
     * Kundenbereich: Datenschutzhinweis, Ansprechpartner, Gültigkeit der
     * Einrichtungslinks, Aufbewahrung der Prüfnachweise. Ein ungültiger Wert
     * verwirft die ganze Eingabe.
     */
    public function kundenbereich(Request $request): void
    {
        $daten = [];
        foreach (array_keys(KundenEinstellungen::VORGABEN) as $schluessel) {
            $feld = str_replace('.', '__', $schluessel);
            if (array_key_exists($feld, $_POST)) {
                $daten[$schluessel] = $_POST[$feld];
            }
        }
        $ergebnis = KundenEinstellungen::speichern($daten);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/einstellungen#kundenbereich');
    }

    /** Eventworx: Mandant und API-Token – das Token gilt nur für den Host, für den es eingetragen wird. */
    public function eventworx(Request $request): void
    {
        $ergebnis = Eventworx::einstellungenSpeichern($request->str('eventworx_adresse'), $request->str('eventworx_token'));
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
        } elseif (!empty($ergebnis['warnung'])) {
            Flash::warning($ergebnis['message']);
        } else {
            Flash::success($ergebnis['message']);
        }
        $this->redirect('/system/einstellungen');
    }

    public function eventworxPruefen(Request $request): void
    {
        // Zwei Anfragen an Eventworx (Drossel, bis 12 s je Versuch) – ohne
        // die Sitzung zu halten; die Meldung kommt danach.
        $ergebnis = Session::ohneSperre(static fn (): array => Eventworx::verbindungTesten());
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/einstellungen');
    }

    public function pruefen(Request $request): void
    {
        if (Config::isDemo()) {
            Flash::info('Demo-Modus: InfluxDB und MQTT werden nicht gebraucht.');
            $this->redirect('/system/einstellungen');
            return;
        }
        // InfluxDB (bis influx.timeout je Abfrage) und Broker (zwei Sekunden
        // lauschen) – ohne die Sitzung zu halten. Die Meldungen erst danach.
        $meldungen = Session::ohneSperre(static function (): array {
            $meldungen = [];
            $influx = Influx::pruefen();
            $meldungen[] = [$influx['ok'] ? 'success' : 'error', $influx['message']];
            try {
                $mqtt = Mqtt::ausKonfiguration('pm-einstellungen');
                $mqtt->abonnieren([trim((string) Config::get('mqtt.prefix', 'pm'), '/') . '/status/nodered']);
                $herz = $mqtt->lauschen(2, static fn () => true);
                $mqtt->trennen();
                $meldungen[] = ['success', 'MQTT-Broker erreichbar.' . ($herz === [] ? ' Von Node-RED kam kein Herzschlag (pm/status/nodered).' : ' Node-RED meldet sich.')];
            } catch (\Throwable $e) {
                $meldungen[] = ['error', 'MQTT: ' . $e->getMessage()];
            }
            if ($influx['ok']) {
                $p = Messdaten::pipeline();
                $meldungen[] = $p === null
                    ? ['warning', 'In der letzten Stunde hat Node-RED nichts in die InfluxDB geschrieben (Messung „pipeline“ leer).']
                    : ['info', 'Node-RED schreibt: zuletzt ' . date('d.m.Y H:i:s', intdiv($p['zeit'], 1000)) . ', ' . (int) $p['fehler'] . ' Fehler, ' . (int) $p['puffer'] . ' Zeilen im Puffer.'];
            }
            return $meldungen;
        });
        // Für ein anderes Ziel eingetragen (etwa per bin/power umgestellt):
        // geht nicht mit – das erklärt eine abgelehnte Anmeldung.
        foreach (self::GEHEIMNISSE as [$schluessel, $bezeichnung]) {
            if (Settings::geheimnisFremd($schluessel)) {
                $meldungen[] = ['warning', 'Hinterlegt ist ' . $bezeichnung . ' für ein anderes Ziel – es wird nicht mitgeschickt. Für das jetzige bitte neu eintragen.'];
            }
        }
        foreach ($meldungen as [$art, $text]) {
            Flash::add($art, $text);
        }
        $this->redirect('/system/einstellungen');
    }
}
