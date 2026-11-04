<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Berichte\Einstellungen;
use App\Geraetepruefung\Prueflinge;
use App\Geraetepruefung\PruefberichtPdf;
use App\Geraetepruefung\PruefberichtService;
use App\Geraetepruefung\Pruefungen;
use App\Kunden\KundenService;
use App\Kunden\Pruefnachweise;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Prüfungen der Geräteprüfung und ihre Prüfberichte: Liste mit Filtern und
 * Auswahl, Detail (Messwerte, Sichtprüfung, alle Fassungen), Unterschreiben
 * (auch gesammelt, JSON – geraetepruefung.js schickt die Auswahl in Stücken
 * zu 25), Korrektur, „Als Prüfnachweis an einen Kunden geben“, das
 * gespeicherte PDF.
 *
 * Die Rechte prüft die Route (sehen geraetepruefung.view, unterschreiben und
 * Korrektur geraetepruefung.unterschreiben, Nachweis kunden.nachweise);
 * jede {id} prüft der Controller selbst – unbekannt ist 404. Kundenzugänge
 * erreichen nichts davon (Tor im Router).
 *
 * Update: nichts hier wartet auf Netz oder Geräte; die Schreibsperre prüft
 * der Router vor jedem POST (UpdateService::wartungsSperre), siehe
 * PruefberichtService.
 */
final class PruefberichteController extends Controller
{
    public function index(Request $request): void
    {
        $filter = [
            'status'   => in_array($request->str('status'), ['offen', 'unterschrieben'], true) ? $request->str('status') : '',
            'ergebnis' => in_array($request->str('ergebnis'), ['bestanden', 'nicht_bestanden'], true) ? $request->str('ergebnis') : '',
            'pruefer'  => mb_substr($request->str('pruefer'), 0, 80),
            'von'      => self::tag($request->str('von')),
            'bis'      => self::tag($request->str('bis')),
            'besitzer' => preg_match('/^(eigen|\d{1,10})$/', $request->str('besitzer')) === 1 ? $request->str('besitzer') : '',
            'suche'    => mb_substr($request->str('suche'), 0, 80),
        ];
        $liste = PruefberichtService::liste(array_filter($filter, static fn (string $w): bool => $w !== ''));
        $this->view('geraetepruefung/pruefungen', [
            'liste'       => $liste,
            'filter'      => $filter,
            'pruefer'     => PruefberichtService::prueferNamen(),
            'kunden'      => Prueflinge::kundenMitPrueflingen(),
            'unterschrift' => self::unterschriftDaten(),
            'scripts'     => ['/assets/js/unterschrift.js', '/assets/js/geraetepruefung.js'],
        ]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $pruefung = $id > 0 ? Pruefungen::find($id) : null;
        if ($pruefung === null) {
            Response::notFound('Diese Prüfung gibt es nicht.');
            return;
        }
        $pruefling = Prueflinge::find((int) $pruefung['pruefling_id']);
        $berichte = PruefberichtService::fuerPruefung($id);
        $aktuell = $berichte[0] ?? null;
        $kunde = $pruefling !== null && $pruefling['kunde_id'] !== null ? KundenService::find((int) $pruefling['kunde_id']) : null;
        // „Als Prüfnachweis an einen Kunden geben“: gültig, bestanden, noch kein Nachweis.
        $nachweisMoeglich = $aktuell !== null && $aktuell['status'] === 'gueltig' && $aktuell['ergebnis'] === 'bestanden'
            && $aktuell['nachweis_id'] === null && Auth::can('kunden.nachweise');
        // Kalibrierung des Prüfgeräts (gleiche Seriennummer unter „Prüfgeräte“) – vor dem Unterschreiben sehen, was der Bericht nennen wird.
        $sn = (string) $pruefung['messgeraet_sn'];
        $pruefgeraet = $sn !== '' ? Database::first('SELECT fabrikat, typ, kalibriert_bis, updated_at FROM pruefgeraete WHERE seriennummer = ? ORDER BY aktiv DESC, id DESC LIMIT 1', [$sn]) : null;
        $this->view('geraetepruefung/pruefung', [
            'pruefung'         => $pruefung,
            'pruefgeraet'      => $pruefgeraet,
            'pruefling'        => $pruefling,
            'kunde'            => $kunde,
            'berichte'         => $berichte,
            'aktuell'          => $aktuell,
            'offen'            => $aktuell === null || $aktuell['status'] === 'korrektur',
            'nachweisMoeglich' => $nachweisMoeglich,
            'nachweisKunden'   => $nachweisMoeglich && $kunde === null
                ? array_map(static fn (array $k): array => ['id' => (int) $k['id'], 'name' => (string) $k['name']], KundenService::alle(['aktiv' => '1'])) : [],
            'unterschrift'     => self::unterschriftDaten(),
            'scripts'          => ['/assets/js/unterschrift.js', '/assets/js/geraetepruefung.js'],
        ]);
    }

    /**
     * Unterschreiben (JSON): `ids` (ein JSON-Feld, höchstens 25), `striche`,
     * `name`, `ort`, `pruefart`, `uebernehmen`, `anfrage`. Antwort
     * {ok, message, unterschrieben, fehler} – 200 auch, wenn einzelne
     * Prüfungen scheitern (sie stehen in `fehler`).
     */
    public function unterschreiben(Request $request): void
    {
        $roh = is_string($_POST['ids'] ?? null) ? $_POST['ids'] : '';
        try {
            $ids = strlen($roh) <= 2000 ? json_decode($roh, true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $ids = null;
        }
        if (!is_array($ids) || !array_is_list($ids)) {
            $this->json(['ok' => false, 'message' => 'Unbekannte Auswahl – bitte die Seite neu laden.', 'unterschrieben' => [], 'fehler' => []], 422);
            return;
        }
        $e = PruefberichtService::unterschreiben($ids, [
            'striche'     => is_string($_POST['striche'] ?? null) ? $_POST['striche'] : '',
            'name'        => $request->str('name'),
            'ort'         => $request->str('ort'),
            'pruefart'    => $request->str('pruefart'),
            'uebernehmen' => $request->bool('uebernehmen'),
            'anfrage'     => $request->str('anfrage'),
        ]);
        $status = (int) $e['status'];
        unset($e['status']);
        $this->json($e, $status);
    }

    /** @param array<string,string> $params */
    public function korrektur(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $b = PruefberichtService::find($id);
        if ($b === null) {
            Response::notFound('Diesen Prüfbericht gibt es nicht.');
            return;
        }
        $e = PruefberichtService::korrektur($id, $request->str('grund'));
        if ($e['status'] === 403) {
            Response::forbidden($e['message']);
            return;
        }
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect('/geraetepruefung/pruefungen/' . (int) $b['pruefung_id']);
    }

    /**
     * „Als Prüfnachweis an einen Kunden geben“: `kunde_id` (beim eigenen
     * Bestand; ein Kundengerät nur an seinen Kunden), Mietzeit und Hinweis
     * freiwillig. Immer als Entwurf.
     *
     * @param array<string,string> $params
     */
    public function nachweis(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $b = PruefberichtService::find($id);
        if ($b === null) {
            Response::notFound('Diesen Prüfbericht gibt es nicht.');
            return;
        }
        if (!PruefberichtService::sichtbar()) {
            Response::forbidden();
            return;
        }
        $kundeId = $b['kunde_id'] ?? ($request->int('kunde_id') > 0 ? $request->int('kunde_id') : 0);
        $ziel = '/geraetepruefung/pruefungen/' . (int) $b['pruefung_id'];
        if ($kundeId <= 0) {
            Flash::error('Bitte den Kunden wählen.');
            $this->redirect($ziel);
            return;
        }
        $e = Pruefnachweise::ausGeraetepruefung((int) $kundeId, $id, [
            'einsatz_von' => $request->str('einsatz_von'),
            'einsatz_bis' => $request->str('einsatz_bis'),
            'hinweis'     => $request->str('hinweis'),
        ]);
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect($ziel);
    }

    /**
     * Das gespeicherte PDF – genau die Bytes von der Unterschrift (Abgleich
     * mit der Prüfsumme); beschädigt gibt es nichts.
     *
     * @param array<string,string> $params
     */
    public function pdf(Request $request, array $params): void
    {
        if ($this->kundenmodusSperrt()) {
            return;
        }
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $b = PruefberichtService::find($id);
        if ($b === null || !PruefberichtService::sichtbar()) {
            Response::notFound('Diesen Prüfbericht gibt es nicht.');
            return;
        }
        $bytes = PruefberichtService::pdfGespeichert($id);
        if ($bytes === null) {
            Response::fehler(500, 'Das gespeicherte PDF dieses Prüfberichts fehlt oder ist beschädigt – bitte „bin/power geraetepruefung:pruefen '
                . $b['nummer'] . '“ ausführen lassen.');
            return;
        }
        Session::freigeben();
        Response::pdf(PruefberichtPdf::dateiname($b), $bytes);
    }

    /**
     * Was der Unterschreiben-Dialog braucht: darf das Konto, Name und
     * Qualifikation, Vorgabe für den Ort.
     *
     * @return array{darf:bool,recht:bool,name:string,qualifikation:string,ort:string}
     */
    private static function unterschriftDaten(): array
    {
        $user = Auth::user() ?? [];
        return [
            'darf'          => PruefberichtService::darfUnterschreiben(),
            'recht'         => Auth::can('geraetepruefung.unterschreiben'),
            'name'          => (string) (($user['full_name'] ?? '') !== '' ? $user['full_name'] : ''),
            'qualifikation' => PruefberichtService::qualifikation(),
            'ort'           => Einstellungen::wert('berichte.ort'),
        ];
    }

    private static function tag(string $wert): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $wert : '';
    }
}
