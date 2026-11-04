<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Geraetepruefung\Prueflinge;
use App\Geraetepruefung\Pruefungen;
use App\Kunden\KundenService;
use App\Services\InventarService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Prüflinge der Geräteprüfung pflegen: Liste mit Filtern (Besitzer, fällig,
 * Suche, außer Betrieb), Formular neu/bearbeiten, Detail mit Prüfungsverlauf
 * und Prüfberichten, Löschen nur ohne Prüfungen.
 *
 * Ansehen mit „geraetepruefung.view“, pflegen mit „geraetepruefung.manage“
 * (prüft die Route). Prüflinge sind kein Standort-Bestand: wer ansehen darf,
 * sieht alle. Eine ID aus der Adresse ist nur eine ganze Zahl, die es gibt –
 * sonst dieselbe 404. Geprüft werden die Eingaben in Prueflinge::speichern();
 * nach einem Fehler stehen sie wieder im Formular (Sitzung, nur für dieses
 * Formular und nur einmal).
 *
 * Nichts hier wartet auf Netz oder Geräte: Die Schreibsperre eines Updates
 * prüft der Router vor jedem POST, eine zweite Abfrage braucht es nicht.
 */
final class PrueflingeController extends Controller
{
    private const ZIEL = '/geraetepruefung/prueflinge';

    /** Sitzungsschlüssel: Eingaben nach einem Fehler wieder ins Formular. */
    private const EINGABE = 'gp_pruefling_eingabe';

    /** Felder des Formulars (Texte und Zahlen kommen als Text, wie eingegeben). */
    private const FELDER = ['kennung', 'kunde_id', 'bezeichnung', 'hersteller', 'typ', 'modell', 'seriennummer', 'abteilung', 'gebaeude',
        'bemerkung', 'schutzklasse', 'pruefablauf_id', 'intervall_monate', 'leitungslaenge', 'querschnitt', 'leiter', 'nennleistung',
        'letzte_pruefung', 'naechste_pruefung', 'inventar_id'];

    public function index(Request $request): void
    {
        $besitzer = $request->str('besitzer');
        $filter = [
            'besitzer' => $besitzer === 'eigen' || ($besitzer !== '' && ctype_digit($besitzer) && strlen($besitzer) <= 10) ? $besitzer : '',
            'faellig'  => array_key_exists($request->str('faellig'), Prueflinge::FILTER_FAELLIG) ? $request->str('faellig') : '',
            'betrieb'  => array_key_exists($request->str('betrieb'), Prueflinge::FILTER_BETRIEB) ? $request->str('betrieb') : '',
            'suche'    => mb_substr($request->str('suche'), 0, 100),
        ];
        $kunden = Prueflinge::kundenMitPrueflingen();
        // Ein Kunde aus der Adresse, der (noch) keine Prüflinge hat, steht trotzdem zur Wahl.
        if ($filter['besitzer'] !== '' && $filter['besitzer'] !== 'eigen' && !in_array((int) $filter['besitzer'], array_column($kunden, 'id'), true)) {
            $k = KundenService::find((int) $filter['besitzer']);
            if ($k !== null) {
                $kunden[] = ['id' => (int) $k['id'], 'name' => (string) $k['name']];
            }
        }
        $this->view('geraetepruefung/prueflinge', [
            'liste'   => Prueflinge::liste($filter + ['limit' => 500]),
            'anzahl'  => Prueflinge::anzahl($filter),
            'filter'  => $filter,
            'kunden'  => $kunden,
            'pflegen' => Auth::can('geraetepruefung.manage'),
        ]);
    }

    public function neu(Request $request): void
    {
        $vorgabe = ['kennung' => '', 'kunde_id' => null, 'inventar_id' => null, 'schutzklasse' => '1', 'intervall_monate' => 12,
            'pruefablauf_id' => null, 'ausser_betrieb' => false];
        // Vorbelegt aus der Adresse: ein Kunde (Link am Kunden) oder ein Inventarstück (Karte am Inventar).
        $kunde = $request->int('kunde');
        if ($kunde > 0 && KundenService::find($kunde) !== null) {
            $vorgabe['kunde_id'] = $kunde;
        }
        $inventar = $request->int('inventar');
        if ($inventar > 0 && $vorgabe['kunde_id'] === null && in_array($inventar, array_column(Prueflinge::inventarAuswahl(), 'id'), true)) {
            $stueck = InventarService::find($inventar);
            if ($stueck !== null) {
                $vorgabe = ['inventar_id' => $inventar, 'kennung' => (string) $stueck['kennung'], 'bezeichnung' => (string) $stueck['name'],
                    'seriennummer' => (string) $stueck['seriennummer']] + $vorgabe;
            }
        }
        $this->formular(null, $vorgabe);
    }

    public function speichern(Request $request): void
    {
        $roh = $request->str('id');
        $id = null;
        if ($roh !== '') {
            if (!ctype_digit($roh) || Prueflinge::find((int) $roh) === null) {
                Response::notFound('Diesen Prüfling gibt es nicht.');
                return;
            }
            $id = (int) $roh;
        }
        $daten = [];
        foreach (self::FELDER as $feld) {
            // Was das Formular nicht schickt (Inventar ohne „Datenquellen ansehen“,
            // Daten bei geprüften Prüflingen), bleibt, wie es ist.
            if (array_key_exists($feld, $_POST)) {
                $daten[$feld] = $feld === 'bemerkung' ? (is_string($_POST[$feld]) ? $_POST[$feld] : '') : $request->str($feld);
            }
        }
        $daten['ausser_betrieb'] = $request->bool('ausser_betrieb');
        $r = Prueflinge::speichern($id, $daten);
        if (!$r['ok']) {
            Flash::error($r['message']);
            Session::put(self::EINGABE, ['fuer' => $id === null ? 'neu' : (string) $id] + $daten);
            $this->redirect($id === null ? self::ZIEL . '/neu' : self::ZIEL . '/' . $id . '/bearbeiten');
            return;
        }
        Flash::success($r['message']);
        $this->redirect(self::ZIEL . '/' . (int) $r['id']);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): void
    {
        $p = $this->pruefling($params);
        if ($p === null) {
            return;
        }
        $id = (int) $p['id'];
        $ablauf = $p['pruefablauf_id'] === null ? null
            : Database::first('SELECT id, geraet_id, nr, name, ungueltig FROM gp_pruefablaeufe WHERE id = ?', [(int) $p['pruefablauf_id']]);
        $inventar = $p['inventar_id'] === null ? null : InventarService::find((int) $p['inventar_id']);
        $this->view('geraetepruefung/pruefling', [
            'p'           => $p,
            'besitzer'    => Prueflinge::besitzerName($p),
            'faelligkeit' => Prueflinge::faelligkeit($p),
            'ablauf'      => $ablauf,
            'inventar'    => $inventar,
            'pruefungen'  => Pruefungen::fuerPruefling($id),
            'berichte'    => Prueflinge::berichte($id),
            'pflegen'     => Auth::can('geraetepruefung.manage'),
        ]);
    }

    /** @param array<string,string> $params */
    public function bearbeiten(Request $request, array $params): void
    {
        $p = $this->pruefling($params);
        if ($p === null) {
            return;
        }
        $this->formular($p, $p);
    }

    /** @param array<string,string> $params */
    public function loeschen(Request $request, array $params): void
    {
        $p = $this->pruefling($params);
        if ($p === null) {
            return;
        }
        $r = Prueflinge::loeschen((int) $p['id']);
        if (!$r['ok']) {
            Flash::error($r['message']);
            $this->redirect(self::ZIEL . '/' . (int) $p['id']);
            return;
        }
        Flash::success($r['message']);
        $this->redirect(self::ZIEL . '?besitzer=' . ($p['kunde_id'] === null ? 'eigen' : (int) $p['kunde_id']));
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * Das Formular – neu ($p null) oder zum Bearbeiten; nach einem Fehler mit
     * den Eingaben von eben.
     *
     * @param array<string,mixed>|null $p
     * @param array<string,mixed> $werte
     */
    private function formular(?array $p, array $werte): void
    {
        $id = $p === null ? null : (int) $p['id'];
        $eingabe = (array) Session::get(self::EINGABE, []);
        if (($eingabe['fuer'] ?? null) === ($id === null ? 'neu' : (string) $id)) {
            Session::forget(self::EINGABE);
            unset($eingabe['fuer']);
            $werte = $eingabe + $werte;
        }
        $kunden = array_map(static fn (array $k): array => ['id' => (int) $k['id'], 'name' => (string) $k['name'], 'kundennummer' => (string) $k['kundennummer']],
            Database::select('SELECT id, name, kundennummer FROM kunden ORDER BY name, id'));
        $this->view('geraetepruefung/pruefling-form', [
            'p'           => $p,
            'werte'       => $werte,
            'kunden'      => $kunden,
            'ablaeufe'    => Prueflinge::ablaeufe($p['pruefablauf_id'] ?? null),
            'inventar'    => Prueflinge::inventarAuswahl($id),
            'darfInventar' => Auth::can('datenquellen.view'),
            'pruefungen'  => $id === null ? 0 : Prueflinge::anzahlPruefungen($id),
            'berichte'    => $id === null ? 0 : Prueflinge::anzahlBerichte($id),
        ]);
    }

    /**
     * Der Prüfling aus der Adresse – nur eine ganze Zahl, sonst und wenn es
     * ihn nicht gibt 404 (die Antwort ist dann gesendet, Rückgabe null).
     *
     * @param array<string,string> $params
     * @return array<string,mixed>|null
     */
    private function pruefling(array $params): ?array
    {
        $roh = (string) ($params['id'] ?? '');
        $p = ctype_digit($roh) && strlen($roh) <= 10 ? Prueflinge::find((int) $roh) : null;
        if ($p === null) {
            Response::notFound('Diesen Prüfling gibt es nicht.');
        }
        return $p;
    }
}
