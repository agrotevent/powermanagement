<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Berichte\Pruefgeraete;
use App\Geraetepruefung\BenningDatei;
use App\Geraetepruefung\BenningVorlage;
use App\Geraetepruefung\GeraeteImport;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Datenbank des Prüfgeräts importieren (Vorschau, übernehmen, verwerfen),
 * als Datei für das Prüfgerät exportieren und die Prüfabläufe pflegen.
 *
 * Import: Datei hochladen → GeraeteImport::lesen() → Paket unter
 * storage/cache, Kennung in der Sitzung → die Seite /geraetepruefung/import
 * zeigt die Vorschau, solange eine bereitliegt (so bleibt `data-laeuft` auf
 * derselben Seite). Das Formular der Vorschau schickt nur die Auswahl: die
 * Zuordnung der Kunden (`zuordnung[<ref>]`), „ersetzen“ mit dem Stand, den die
 * Vorschau zeigte (`<schlüssel>:<stand>`), angehakte Inventar-Vorschläge,
 * den Haken für die Vorlage – und `zuordnung_stand`: Weicht die geschickte
 * Zuordnung von der ab, für die die Prüflinge gezeigt wurden, wird nichts
 * übernommen, sondern die Vorschau mit der neuen Zuordnung gezeigt. Ein
 * letztes Feld (`vollstaendig`) erkennt ein von max_input_vars
 * abgeschnittenes Formular – dann wird nichts übernommen.
 *
 * Lesen und Übernehmen halten die Sitzung nicht (eine große Datei braucht
 * Sekunden); gegen den Doppelklick sperrt GeraeteImport::gesperrt() ohne zu
 * warten. Update: Jeder POST geht durch UpdateService::wartungsSperre();
 * Übernehmen fragt unmittelbar vor dem Schreiben noch einmal (eine große
 * Datei kann dauern), Lesen schreibt nichts in die Datenbank.
 *
 * Export: POST mit den Besitzern → Datei als Download (application/vnd.sqlite3,
 * private, no-store), vorher noch einmal der Kundenmodus. Im Audit nur Zahlen.
 */
final class GeraeteImportController extends Controller
{
    private const START = '/geraetepruefung/import';

    /** @return array{kennung:string,paket:array<string,mixed>,datei:string}|null die Vorschau dieser Sitzung */
    private function bereit(): ?array
    {
        $v = Session::get(GeraeteImport::SITZUNG);
        if (!is_array($v) || !is_string($v['kennung'] ?? null)) {
            return null;
        }
        $paket = GeraeteImport::abgelegt($v['kennung']);
        if ($paket === null) {
            Session::forget(GeraeteImport::SITZUNG);
            return null;
        }
        return ['kennung' => $v['kennung'], 'paket' => $paket, 'datei' => (string) ($v['datei'] ?? '')];
    }

    public function index(Request $request): void
    {
        $bereit = $this->bereit();
        if ($bereit !== null) {
            $stand = GeraeteImport::pruefen($bereit['paket']);
            if ($stand['ok']) {
                header('Cache-Control: private, no-store');
                $this->view('geraetepruefung/import-vorschau', [
                    'stand'   => $stand,
                    'kennung' => $bereit['kennung'],
                    'datei'   => $bereit['datei'],
                ]);
                return;
            }
            Flash::error((string) $stand['message']);
            GeraeteImport::verwerfen($bereit['kennung']);
            Session::forget(GeraeteImport::SITZUNG);
        }
        $this->view('geraetepruefung/import', [
            'letzte'  => GeraeteImport::letzte(5),
            'vorlage' => BenningVorlage::aktiv(),
        ]);
    }

    public function vorschau(Request $request): void
    {
        $datei = $_FILES['datei'] ?? null;
        $fehler = is_array($datei) && is_int($datei['error'] ?? null) ? $datei['error'] : UPLOAD_ERR_NO_FILE;
        $tmp = is_array($datei) && is_string($datei['tmp_name'] ?? null) ? $datei['tmp_name'] : '';
        if ($fehler !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            Flash::error(match ($fehler) {
                UPLOAD_ERR_NO_FILE                        => 'Bitte die Datenbank des Prüfgeräts wählen.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß (höchstens 15 MB).',
                UPLOAD_ERR_PARTIAL                        => 'Der Upload wurde abgebrochen.',
                default                                   => 'Der Upload hat nicht geklappt (Code ' . $fehler . ').',
            });
            $this->redirect(self::START);
            return;
        }
        if ((int) ($datei['size'] ?? 0) > BenningDatei::MAX_BYTES || (int) filesize($tmp) > BenningDatei::MAX_BYTES) {
            Flash::error('Die Datei ist größer als 15 MB – so groß wird keine Datenbank des Prüfgeräts.');
            $this->redirect(self::START);
            return;
        }
        $name = mb_substr(basename(str_replace('\\', '/', is_string($datei['name'] ?? null) ? $datei['name'] : '')), 0, 120);
        // Lesen dauert bei einer großen Datei Sekunden – ohne die Sitzung zu halten.
        [$gelesen, $noch] = $this->ohneSitzungssperre(static fn (): array => GeraeteImport::lesen($tmp, $name));
        if (!$noch) {
            $this->redirect('/login');
            return;
        }
        if (!$gelesen['ok']) {
            $befunde = (array) ($gelesen['befunde'] ?? []);
            Flash::error(($name !== '' ? $name . ': ' : '') . $gelesen['message'] . ($befunde !== [] ? ' ' . implode(' ', $befunde) : ''));
            $this->redirect(self::START);
            return;
        }
        $alt = Session::get(GeraeteImport::SITZUNG);
        if (is_array($alt) && is_string($alt['kennung'] ?? null)) {
            GeraeteImport::verwerfen($alt['kennung']);
        }
        try {
            $kennung = GeraeteImport::ablegen((array) $gelesen['paket']);
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
            $this->redirect(self::START);
            return;
        }
        Session::put(GeraeteImport::SITZUNG, ['kennung' => $kennung, 'datei' => $name]);
        $z = $gelesen['paket']['zahlen'];
        Flash::info('Gelesen: ' . $z['kunden'] . ' Kunden, ' . $z['geraete'] . ' Geräte, ' . $z['pruefungen'] . ' Prüfungen. Bitte die Zuordnung prüfen.');
        $this->redirect(self::START);
    }

    public function uebernehmen(Request $request): void
    {
        $bereit = $this->bereit();
        if ($bereit === null || !hash_equals($bereit['kennung'], $request->str('kennung'))) {
            Flash::error('Diese Vorschau ist abgelaufen oder nicht mehr die aktuelle – bitte die Datei noch einmal lesen.');
            $this->redirect(self::START);
            return;
        }
        if (!$request->bool('vollstaendig')) {
            // PHP hat das Formular abgeschnitten (max_input_vars) – nie mit einer halben Auswahl übernehmen.
            Flash::error('Das Formular kam nicht vollständig an (zu viele Haken auf einmal) – nichts übernommen. Bitte weniger auswählen '
                . 'und in mehreren Durchgängen übernehmen.');
            $this->redirect(self::START);
            return;
        }
        $paket = $bereit['paket'];
        $zuordnung = GeraeteImport::zuordnungAus($paket, $request->input('zuordnung'));
        $paket['zuordnung'] = $zuordnung;
        if ($request->str('aktion') === 'zuordnen' || !hash_equals(GeraeteImport::zuordnungStand($zuordnung), $request->str('zuordnung_stand'))) {
            GeraeteImport::aktualisieren($bereit['kennung'], $paket);
            $request->str('aktion') === 'zuordnen'
                ? Flash::info('Zuordnung angewandt – unten stehen die Prüflinge dazu.')
                : Flash::warning('Die Zuordnung der Kunden hat sich geändert – nichts übernommen. Unten stehen jetzt die Prüflinge dazu; '
                    . 'bitte prüfen und noch einmal übernehmen.');
            $this->redirect(self::START);
            return;
        }
        $ersetzen = [];
        foreach ($request->arr('ersetzen') as $wert) {
            if (preg_match('/^([0-9a-f]{32}):([0-9a-f]{64})$/', $wert, $m) === 1) {
                $ersetzen[$m[1]] = $m[2];
            }
        }
        $inventar = [];
        foreach ($request->arr('inventar') as $wert) {
            if (preg_match('/^([0-9a-f]{32}):(\d{1,9})$/', $wert, $m) === 1) {
                $inventar[$m[1]] = $m[2];
            }
        }
        $vorlage = $request->bool('vorlage');
        [$ergebnis, $noch] = $this->ohneSitzungssperre(static fn (): array
            => GeraeteImport::uebernehmen($paket, $zuordnung, $ersetzen, $inventar, $vorlage));
        if (!$noch) {
            $this->redirect('/login');
            return;
        }
        if (!$ergebnis['ok']) {
            GeraeteImport::aktualisieren($bereit['kennung'], $paket);
            !empty($ergebnis['wartung']) ? Flash::warning((string) $ergebnis['message']) : Flash::error((string) $ergebnis['message']);
            $this->redirect(self::START);
            return;
        }
        if (!empty($ergebnis['nichts'])) {
            // Nichts geschehen: Die Vorschau bleibt und zeigt den Stand jetzt.
            GeraeteImport::aktualisieren($bereit['kennung'], $paket);
            Flash::warning((string) $ergebnis['message']);
            $this->redirect(self::START);
            return;
        }
        GeraeteImport::verwerfen($bereit['kennung']);
        Session::forget(GeraeteImport::SITZUNG);
        Flash::success((string) $ergebnis['message']);
        $this->redirect(self::START);
    }

    public function verwerfen(Request $request): void
    {
        $bereit = $this->bereit();
        if ($bereit !== null) {
            GeraeteImport::verwerfen($bereit['kennung']);
        }
        Session::forget(GeraeteImport::SITZUNG);
        $this->redirect(self::START);
    }

    public function export(Request $request): void
    {
        $this->view('geraetepruefung/export', [
            'vorlage'  => BenningVorlage::aktiv(),
            'besitzer' => BenningVorlage::besitzer(),
            'exporte'  => BenningVorlage::letzteExporte(5),
            'name'     => 'PowerManagement-' . (new \DateTimeImmutable('now', \App\Support\Zeit::zone()))->format('Y-m-d'),
        ]);
    }

    public function exportieren(Request $request): void
    {
        // Die Datei enthält Kundendaten: vor dem Schreiben (und dem Beleg)
        // noch einmal der Kundenmodus, danach ohne Sitzung hinaus – nie
        // zwischengespeichert.
        if ($this->kundenmodusSperrt()) {
            return;
        }
        $ergebnis = BenningVorlage::exportieren($request->arr('besitzer'), $request->str('name'));
        if (!$ergebnis['ok']) {
            Flash::error((string) $ergebnis['message']);
            $this->redirect('/geraetepruefung/export');
            return;
        }
        Session::freigeben();
        $bytes = (string) $ergebnis['bytes'];
        http_response_code(200);
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="' . Response::sichererDateiname((string) $ergebnis['dateiname'], 'pruefgeraet.db') . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        echo $bytes;
    }

    public function pruefablaeufe(Request $request): void
    {
        $this->view('geraetepruefung/pruefablaeufe', [
            'ablaeufe'     => BenningVorlage::pruefablaeufe(),
            'pruefgeraete' => Pruefgeraete::liste(),
        ]);
    }

    /** @param array<string,string> $params */
    public function pruefablaufSpeichern(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        if ($id <= 0 || BenningVorlage::pruefablauf($id) === null) {
            Response::notFound('Diesen Prüfablauf gibt es nicht.');
            return;
        }
        $zubehoer = $request->input('zubehoer', []);
        $ergebnis = BenningVorlage::pruefablaufSpeichern($id, [
            'norm'     => $request->str('norm'),
            'zubehoer' => is_array($zubehoer) ? array_values($zubehoer) : [],
        ]);
        $this->antwort($request, $ergebnis, '/geraetepruefung/pruefablaeufe#ablauf-' . $id);
    }
}
