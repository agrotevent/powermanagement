<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

/**
 * Inventar: Verteiler, Zwischenstecker und Tanksensoren mit ihren Shellys –
 * anlegen im Vorfeld, einsetzen beim Aufbau, abbauen danach.
 *
 * Ansehen darf, wer Datenquellen sehen darf (Planung, Technik); pflegen,
 * wer „Inventar pflegen“ hat. Einsetzen und Abbauen gehören zur Anlage:
 * dafür braucht es das Recht, diese Anlage zu bearbeiten.
 */
final class InventarController extends Controller
{
    public function index(Request $request): void
    {
        $art = $request->str('art');
        $this->view('inventar/index', [
            'liste'  => InventarService::fuerBetrachter(InventarService::liste(['art' => $art, 'suche' => $request->str('suche')])),
            'art'    => $art,
            'suche'  => $request->str('suche'),
            'darf'   => Auth::can('inventar.manage'),
        ]);
    }

    public function show(Request $request, array $params): void
    {
        $item = InventarService::find((int) ($params['id'] ?? 0));
        if ($item === null) {
            Response::notFound();
            return;
        }
        $geschichte = InventarService::geschichte((int) $item['id']);
        // Wo es stand, zeigen wir nur, wenn der Benutzer die Anlage sieht.
        foreach ($geschichte as &$e) {
            $o = AnlagenService::find((int) $e['objekt_id']);
            $e['sichtbar'] = $o !== null && Zugriff::siehtObjekt($o);
            $e['darf_abbauen'] = $o !== null && $e['bis'] === null && Zugriff::darfBearbeiten($o);
        }
        unset($e);
        $this->view('inventar/show', [
            'item'        => $item,
            'messgeraete' => InventarService::messgeraete((int) $item['id']),
            'stellen'     => InventarService::stellen($item),
            'geschichte'  => $geschichte,
            'darf'        => Auth::can('inventar.manage'),
        ]);
    }

    public function neu(Request $request): void
    {
        $art = isset(InventarService::ARTEN[$request->str('art')]) ? $request->str('art') : 'verteilung';
        // Ein neuer Tanksensor beginnt mit der üblichen Sonde: hydrostatisch,
        // 4–20 mA über einen Wandler 2–10 V. Den Messbereich trägt man ein –
        // er steht auf der Sonde.
        $this->formular(['id' => 0, 'art' => $art, 'kennung' => '', 'name' => '', 'vorlage_id' => null, 'steckertyp_id' => null,
            'seriennummer' => '', 'defekt' => 0, 'notizen' => '', 'eigenschaften' => $art === 'tanksensor' ? TankRechnung::SENSOR_VORGABE : []], []);
    }

    public function bearbeiten(Request $request, array $params): void
    {
        $item = InventarService::find((int) ($params['id'] ?? 0));
        if ($item === null) {
            Response::notFound();
            return;
        }
        $this->formular($item, InventarService::messgeraete((int) $item['id']));
    }

    private function formular(array $item, array $messgeraete): void
    {
        $vorlagen = array_values(array_filter(AnlagenService::liste(['vorlagen' => true]), static fn ($v) => $v['art'] === 'verteilung'));
        // Stellen je Typ – das Formular baut die Auswahl um, wenn der Typ wechselt.
        $stellenJeTyp = ['' => InventarService::stellen(['art' => 'verteilung', 'vorlage_id' => null])];
        foreach ($vorlagen as $v) {
            $stellenJeTyp[(string) $v['id']] = InventarService::stellen(['art' => 'verteilung', 'vorlage_id' => (int) $v['id']]);
        }
        $fehler = null;
        $this->view('inventar/form', [
            'item'         => $item,
            'messgeraete'  => $messgeraete,
            'vorlagen'     => $vorlagen,
            'steckertypen' => AnlagenService::steckertypen(),
            'stellenJeTyp' => $stellenJeTyp,
            'geraete'      => Messdaten::geraete($fehler),
            'scripts'      => ['/assets/js/inventar.js'],
        ]);
    }

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $zeilen = [];
        foreach ($request->arr('geraet') as $i => $geraet) {
            $zeilen[] = [
                'geraet' => (string) $geraet, 'kanal' => (string) ($request->arr('kanal')[$i] ?? ''),
                'stelle' => (string) ($request->arr('stelle')[$i] ?? ''), 'bezeichnung' => (string) ($request->arr('bezeichnung')[$i] ?? ''),
            ];
        }
        // Neue Shellys im Stück: nur solche, die man auch einer Anlage zuordnen
        // dürfte – beim Einsetzen messen sie sonst für die eigene Anlage.
        $bisher = $id !== null ? array_column(InventarService::messgeraete($id), 'geraet') : [];
        foreach ($zeilen as $zeile) {
            $geraet = trim($zeile['geraet']);
            if ($geraet !== '' && !in_array($geraet, $bisher, true) && !Zugriff::darfGeraetZuordnen($geraet)) {
                Flash::error($geraet . ' misst an einer Anlage, die du nicht bearbeiten darfst.');
                $this->back('/inventar');
                return;
            }
        }
        $ergebnis = InventarService::speichern($id, [
            'art' => $request->str('art'), 'kennung' => $request->str('kennung'), 'name' => $request->str('name'),
            'vorlage_id' => $request->int('vorlage_id'), 'steckertyp_id' => $request->int('steckertyp_id'),
            'seriennummer' => $request->str('seriennummer'), 'defekt' => $request->bool('defekt'), 'notizen' => $request->str('notizen'),
            'messgeraete' => $zeilen,
            'eigenschaften' => is_array($_POST['eigenschaften'] ?? null) ? $_POST['eigenschaften'] : [],
        ]);
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
            $this->back('/inventar');
            return;
        }
        Flash::success($ergebnis['message']);
        $this->redirect('/inventar/' . (int) $ergebnis['id']);
    }

    public function loeschen(Request $request, array $params): void
    {
        $ergebnis = InventarService::loeschen((int) ($params['id'] ?? 0));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/inventar' : '/inventar/' . (int) ($params['id'] ?? 0));
    }

    /** Ein Stück in eine Anlage einsetzen (vom Anlagenfenster oder der Anlagenseite). */
    public function einsetzen(Request $request, array $params): void
    {
        $objekt = AnlagenService::find((int) ($params['id'] ?? 0));
        if ($objekt === null || !Zugriff::darfBearbeiten($objekt)) {
            $this->verweigern($request, 'Diese Anlage darfst du nicht bearbeiten.');
            return;
        }
        // Ein Tanksensor rechnet ab jetzt mit einer neuen Kennlinie – das nur
        // im eigenen Datenfenster (wie Peilung und Betankung).
        if (!AnlagenService::elektrisch((string) $objekt['art']) && !TankService::darfEintragen($objekt)) {
            $this->verweigern($request, TankService::AUSSERHALB_FENSTER);
            return;
        }
        // Steckt das Stück gerade woanders, wird es dort abgezogen – das darf
        // nur, wer auch dort bearbeiten darf.
        $bisher = ($item = InventarService::find($request->int('inventar_id'))) !== null && $item['einsatz'] !== null
            ? AnlagenService::find((int) $item['einsatz']['objekt_id']) : null;
        if ($bisher !== null && (int) $bisher['id'] !== (int) $objekt['id'] && !Zugriff::darfBearbeiten($bisher)) {
            $this->verweigern($request, 'Das Stück steckt gerade in einer Anlage, die du nicht bearbeiten darfst.');
            return;
        }
        $anschluss = $request->int('anschluss_id');
        // Tanksensor: wie hoch über dem Boden die Sonde bzw. die Sensorfläche sitzt.
        $einbau = is_array($_POST['einbau'] ?? null) ? $_POST['einbau'] : [];
        $ergebnis = InventarService::einsetzen($request->int('inventar_id'), (int) $objekt['id'], $anschluss > 0 ? $anschluss : null,
            $request->input('typ_uebernehmen') === null || $request->bool('typ_uebernehmen'), $einbau);
        // Eine Peilung gleich beim Einsetzen gibt es nicht: Rohwerte zählen erst
        // ab dem Einbau, und ein Shelly meldet erst nach einer Änderung – also
        // erst einbauen, dann die Sonde einhängen, dann auf der Tankseite peilen.
        $this->antwort($request, $ergebnis, '/anlagen/' . (int) $objekt['id'] . '?reiter=messung');
    }

    /** Einen Einsatz beenden (Abbau). */
    public function beenden(Request $request, array $params): void
    {
        $e = Database::first('SELECT * FROM einsaetze WHERE id = ?', [(int) ($params['id'] ?? 0)]);
        $objekt = $e !== null ? AnlagenService::find((int) $e['objekt_id']) : null;
        if ($e === null || $objekt === null || !Zugriff::darfBearbeiten($objekt)) {
            $this->verweigern($request, 'Diesen Einsatz darfst du nicht beenden.');
            return;
        }
        $this->antwort($request, InventarService::beenden((int) $e['id']), $request->str('zurueck') === 'inventar'
            ? '/inventar/' . (int) $e['inventar_id'] : '/anlagen/' . (int) $objekt['id'] . '?reiter=messung');
    }

    private function verweigern(Request $request, string $text): void
    {
        if ($request->wantsJson()) {
            $this->json(['ok' => false, 'message' => $text], 403);
            return;
        }
        Response::forbidden($text);
    }
}
