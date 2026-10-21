<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Support\Audit;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Zeit;
use App\Support\Zeitraum;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

/**
 * Tanks: Verlauf, Verbrauch je Tag und CSV des Füllstands, Peilung,
 * Betankung und Kennlinie vor Ort.
 *
 * Das Recht prüft die Route (statistik.view bzw. statistik.export zum
 * Lesen, anlagen.manage zum Eintragen); hier wird jede ID einzeln geprüft:
 * der Tank (sichtbar, kein Katalog-Typ; zum Eintragen mit „bearbeiten“ und
 * nur, solange das Datenfenster jetzt gilt),
 * ein gewählter Messpunkt (Füllstand dieses Tanks), ein Ereignis (gehört
 * zum Tank, im Datenfenster – das prüft TankService). Gelesen wird über
 * TankService, und der liest nur über Messdaten – im Datenfenster. Den
 * Rohwert einer Peilung oder Betankung nimmt der Server, nie der Browser.
 *
 * Sitzung: Verlauf und Tage (JSON) geben sie frei, bevor sie lesen. Alles
 * andere wartet auf die InfluxDB ohne Sitzungssperre (Influx::abfragenAlle()
 * über Session::ohneSperre()) und hat die Sitzung danach wieder offen –
 * Meldungen kommen an, und die CSV prüft vor der Datei noch einmal den
 * Kundenmodus. Eingetragen wird je Tank nur einmal zur Zeit
 * (TankService::gesperrt()): Ohne Sitzungssperre liefe ein Doppelklick
 * sonst zweimal nebeneinander.
 */
final class TankController extends Controller
{
    /**
     * Den Tank aus der Adresse – oder 404 (auch, wenn er nicht sichtbar ist).
     * Beim Ändern braucht es „bearbeiten“ am Tank und jetzt das Datenfenster
     * (TankService::darfEintragen), sonst 403. Abgelehnt wird als JSON, wenn
     * der Aufrufer JSON erwartet ($json oder Accept).
     */
    private function tank(Request $request, array $params, bool $aendern = false, bool $json = false): ?array
    {
        $tank = AnlagenService::find((int) ($params['id'] ?? 0));
        if ($tank === null || AnlagenService::elektrisch((string) $tank['art']) || (int) $tank['ist_vorlage'] === 1 || !Zugriff::siehtObjekt($tank)) {
            $this->ablehnen($request, $json, 404, 'Diesen Tank gibt es nicht.');
            return null;
        }
        if ($aendern && ($nein = TankService::eintragenVerweigert($tank)) !== null) {
            $this->ablehnen($request, $json, 403, $nein['message']);
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

    /**
     * Verbrauch je Tag als JSON für die Tabelle „Tage“ (Tankseite, Statistik):
     * ganze Tage in Ortszeit im Zeitraum, dazu die Betankungen – erkannte
     * ohne Eintrag lassen sich eintragen (darf_eintragen, eintragen).
     */
    public function tage(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, false, true);
        if ($tank === null) {
            return;
        }
        // Liest Tage an Messwerten – die Sitzung nicht halten.
        Session::freigeben();
        $mp = $this->messpunkt($request, $tank);
        if ($mp === false) {
            $this->json(['ok' => false, 'message' => 'Dieser Messpunkt gehört nicht zu diesem Tank.'], 404);
            return;
        }
        [$von, $bis] = Zeitraum::ausAnfrage($request);
        $fehler = null;
        $tage = TankService::tage($tank, $von, $bis, $fehler, $mp);
        $this->json(['ok' => $fehler === null, 'message' => $this->fehlerText($fehler)] + $tage);
    }

    /** CSV: Rohwert, Füllhöhe, Inhalt und Füllstand je Zeitpunkt, im Datenfenster. */
    public function csv(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params);
        if ($tank === null) {
            return;
        }
        // Kein Session::freigeben(): Die InfluxDB gibt sie beim Warten selbst
        // frei und danach wieder – vor der Datei zählt der Kundenmodus, wie
        // er dann ist (Controller::kundenmodusSperrt()).
        if ($request->str('inhalt') === 'protokoll') {
            $this->protokollCsv($tank);
            return;
        }
        $mp = $this->messpunkt($request, $tank);
        if ($mp === false) {
            Response::notFound('Dieser Messpunkt gehört nicht zu diesem Tank.');
            return;
        }
        [$von, $bis] = Zeitraum::ausAnfrage($request);
        if ($request->str('inhalt') === 'tage') {
            $this->tageCsv($tank, $von, $bis, $mp);
            return;
        }
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
        // Die Messwerte kamen aus der InfluxDB, ohne Sitzungssperre – vielleicht
        // liegt das Gerät inzwischen beim Kunden.
        if ($this->kundenmodusSperrt()) {
            return;
        }
        Audit::log('tank.csv', 'objekt', (string) $tank['id'], ['von' => Zeit::iso($von), 'bis' => Zeit::iso($bis), 'messpunkt' => $mp]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tank-' . (int) $tank['id'] . '-' . date('Ymd-Hi', $von) . '-' . date('Ymd-Hi', $bis) . '.csv"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $zeilen) . "\r\n";
    }

    /** CSV des Verbrauchs je Tag: je Tag eine Zeile, die Betankungen des Tags in einer Spalte. */
    private function tageCsv(array $tank, int $von, int $bis, ?int $mp): void
    {
        $fehler = null;
        $d = TankService::tage($tank, $von, $bis, $fehler, $mp);
        if ($fehler !== null) {
            Logger::info('Tank-CSV (Tage): ' . $fehler);
        }
        $zahl = static fn (?float $w, int $n): string => $w === null ? '' : str_replace('.', ',', (string) round($w, $n));
        $jeTag = [];
        foreach ($d['betankungen'] as $b) {
            $jeTag[Zeit::anzeige($b['zeit_iso'], 'Y-m-d')][] = Zeit::anzeige($b['zeit_iso'], 'H:i') . ' ' . $zahl($b['liter'], 0) . ' l ' . $b['quelle_text']
                . ($b['lieferschein'] !== '' ? ' (' . $b['lieferschein'] . ')' : '');
        }
        $zeilen = [implode(';', array_map([StatistikController::class, 'csvFeld'],
            ['Tag', 'Verbrauch [l]', 'Getankt [l]', 'Mittel [l/h]', 'Anfang [l]', 'Ende [l]', 'Stunden mit Messwerten', 'Betankungen', 'Hinweis']))];
        foreach ($d['tage'] as $t) {
            $zeilen[] = implode(';', [
                $t['tag'],
                $zahl($t['verbrauch_l'], 1), $zahl($t['betankt_l'], 1), $zahl($t['l_h'], 2),
                $zahl($t['anfang_l'], 1), $zahl($t['ende_l'], 1), $zahl($t['stunden'], 2),
                StatistikController::csvFeld(implode('; ', $jeTag[$t['tag']] ?? [])),
                StatistikController::csvFeld($t['hinweis']),
            ]);
        }
        if ($this->kundenmodusSperrt()) {
            return;
        }
        Audit::log('tank.csv', 'objekt', (string) $tank['id'], ['tage' => true, 'von' => Zeit::iso($d['von']), 'bis' => Zeit::iso($d['bis']), 'messpunkt' => $mp]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tank-' . (int) $tank['id'] . '-tage-' . date('Ymd', $d['von']) . '-' . date('Ymd', $d['bis']) . '.csv"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $zeilen) . "\r\n";
    }

    /** CSV des Protokolls: Peilungen und Betankungen im Datenfenster, Texte gegen Formeln abgesichert. */
    private function protokollCsv(array $tank): void
    {
        $zahl = static fn (?float $w, int $n): string => $w === null ? '' : str_replace('.', ',', (string) round($w, $n));
        $zeilen = [implode(';', array_map([StatistikController::class, 'csvFeld'],
            ['Zeit', 'Art', 'Inhalt [l]', 'Anzeige [%]', 'Peilstab [mm]', 'Rohwert', 'Rohwert davor', 'Rohwert danach', 'Lieferschein', 'von', 'Notiz']))];
        foreach (array_reverse(TankService::ereignisse($tank, 2000)) as $e) {
            $zeilen[] = implode(';', [
                Zeit::anzeige($e['zeit'], 'Y-m-d H:i:s'),
                StatistikController::csvFeld($e['art'] === 'betankung' ? 'Betankung' : 'Peilung'),
                $zahl($e['liter'], 1), $zahl($e['prozent'], 1), $zahl($e['hoehe_mm'], 0),
                $zahl($e['roh'], 4), $zahl($e['roh_vor'], 4), $zahl($e['roh_nach'], 4),
                StatistikController::csvFeld($e['lieferschein']),
                StatistikController::csvFeld($e['von']),
                StatistikController::csvFeld($e['notizen']),
            ]);
        }
        if ($this->kundenmodusSperrt()) {
            return;
        }
        Audit::log('tank.csv', 'objekt', (string) $tank['id'], ['protokoll' => true]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tank-' . (int) $tank['id'] . '-protokoll.csv"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $zeilen) . "\r\n";
    }

    /** Peilung vor Ort: genau eine Angabe – Anzeige (%), Inhalt (l) oder Peilstab (mm); Zeit jetzt. */
    public function peilung(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, true);
        if ($tank === null) {
            return;
        }
        // Das Formular schickt Art und Wert, das Kartenpanel ebenso; sonst je ein Feld.
        $art = $request->str('art');
        $daten = in_array($art, ['prozent', 'liter', 'hoehe_mm'], true)
            ? [$art => $request->str('wert')]
            : ['prozent' => $request->str('prozent'), 'liter' => $request->str('liter'), 'hoehe_mm' => $request->str('hoehe_mm')];
        $daten['notizen'] = $request->str('notizen');
        // Den Rohwert liest der Server aus der InfluxDB – ohne Sitzungssperre.
        $this->fertig($request, $tank, TankService::gesperrt((int) $tank['id'], static fn (): array => TankService::peilen($tank, $daten)));
    }

    /** Betankung eintragen – und, liegt sie 30 min zurück, gleich auswerten. */
    public function betankung(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, true);
        if ($tank === null) {
            return;
        }
        $daten = [
            'liter' => $request->str('liter'), 'zeit' => $request->str('zeit'), 'lieferschein' => $request->str('lieferschein'),
            'notizen' => $request->str('notizen'),
            // Vorgabe an: Das Formular schickt 0 vor dem Haken, ein Aufruf ohne Feld kalibriert.
            'kalibrieren' => $request->input('kalibrieren') === null || $request->bool('kalibrieren'),
        ];
        $this->fertig($request, $tank, TankService::gesperrt((int) $tank['id'], static fn (): array => TankService::betankungSpeichern($tank, $daten)));
    }

    /** „Kalibrieren aus dieser Betankung“ – ein POST; ein GET schreibt nie. */
    public function betankungAuswerten(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, true);
        if ($tank === null) {
            return;
        }
        $ereignis = $request->int('ereignis_id');
        $trotzdem = $request->bool('trotzdem');
        $this->fertig($request, $tank, TankService::gesperrt((int) $tank['id'], static fn (): array => TankService::betankungAuswerten($tank, $ereignis, $trotzdem)));
    }

    /** Eine Peilung oder Betankung löschen (§6.6: nur, solange danach nicht neu kalibriert wurde). */
    public function ereignisLoeschen(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, true);
        if ($tank === null) {
            return;
        }
        $ereignis = $request->int('ereignis_id');
        $this->fertig($request, $tank, TankService::gesperrt((int) $tank['id'], static fn (): array => TankService::ereignisLoeschen($tank, $ereignis)));
    }

    /** „Erweitert“: Anker und Steigung von Hand, ab jetzt oder rückwirkend für diesen Einbau. */
    public function kennlinie(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, true);
        if ($tank === null) {
            return;
        }
        $daten = [
            'messpunkt' => $request->int('messpunkt'),
            'sensor'    => $_POST['sensor'] ?? null,
            'notizen'   => $request->str('notizen'),
        ];
        $rueckwirkend = $request->bool('rueckwirkend');
        $this->fertig($request, $tank, TankService::gesperrt((int) $tank['id'], static fn (): array => TankService::sensorVonHand($tank, $daten, $rueckwirkend)));
    }

    /** Steigung aus zwei Peilungen setzen – nur mit Haken, nie von selbst. */
    public function steigung(Request $request, array $params): void
    {
        $tank = $this->tank($request, $params, true);
        if ($tank === null) {
            return;
        }
        $ereignis = $request->int('ereignis_id');
        $this->fertig($request, $tank, $request->bool('uebernehmen')
            ? TankService::gesperrt((int) $tank['id'], static fn (): array => TankService::steigungSetzen($tank, $ereignis))
            : ['ok' => false, 'message' => 'Bitte den Haken setzen – die Steigung wird nur auf ausdrücklichen Wunsch übernommen.']);
    }

    /**
     * Antwort nach dem Eintragen: JSON fürs Kartenpanel, sonst Meldung und
     * zurück zur Seite. Die Sitzung ist dann wieder offen (Session::ohneSperre()
     * in Influx::abfragenAlle()); begann inzwischen der Kundenmodus, schreibt
     * Flash::add() nichts, und die Seite danach führt zur Kundenseite.
     *
     * @param array{ok:bool,message:string,warnung?:bool} $ergebnis
     */
    private function fertig(Request $request, array $tank, array $ergebnis): void
    {
        $ok = !empty($ergebnis['ok']);
        $text = (string) $ergebnis['message'];
        if ($request->wantsJson()) {
            $this->json(['ok' => $ok, 'message' => $text] + array_intersect_key($ergebnis, ['id' => 1, 'kalibriert' => 1, 'warnung' => 1, 'trotzdem' => 1]),
                $ok ? 200 : 422);
            return;
        }
        match (true) {
            !$ok                         => Flash::error($text),
            !empty($ergebnis['warnung']) => Flash::warning($text),
            default                      => Flash::success($text),
        };
        $this->redirect(Response::zurueckZiel('/anlagen/' . (int) $tank['id']));
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
