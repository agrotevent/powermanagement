<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Messung\Abfrage;
use App\Messung\ComapCloud;
use App\Messung\Datenquellen;
use App\Messung\Messdaten;
use App\Messung\Profile;
use App\Messung\Sammler;
use App\Support\Config;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class DatenquellenController extends Controller
{
    public function index(Request $request): void
    {
        $fehler = null;
        $geraete = Messdaten::geraete($fehler);
        // Welche Geräte sind schon einer Anlage zugeordnet?
        $zugeordnet = [];
        foreach (Database::select('SELECT m.geraet, m.kanal, o.id, o.name FROM messpunkte m JOIN objekte o ON o.id = m.objekt_id WHERE m.gueltig_bis IS NULL') as $z) {
            $zugeordnet[$z['geraet'] . '|' . $z['kanal']][] = ['id' => (int) $z['id'], 'name' => $z['name']];
        }
        $this->view('datenquellen/index', [
            'quellen'    => Datenquellen::alle(),
            'geraete'    => $geraete,
            'fehler'     => $fehler,
            'zugeordnet' => $zugeordnet,
            'profile'    => Profile::alle(),
            'bearbeiten' => $request->int('bearbeiten') > 0 ? Datenquellen::find($request->int('bearbeiten')) : null,
            'neuArt'     => $request->str('neu'),
            'vorlage'    => ['geraet' => $request->str('geraet'), 'name' => $request->str('name'),
                'praefix' => $request->str('praefix'), 'profil' => $request->str('profil') === 'gen1' ? 'gen1' : 'gen2'],
            'pipeline'   => Messdaten::pipeline(),
            'ziel'       => Sammler::ziel(),
        ]);
    }

    public function speichern(Request $request): void
    {
        $ergebnis = Datenquellen::speichern($request->int('id') ?: null, $_POST);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/datenquellen' : \App\Support\Response::zurueckZiel('/datenquellen'));
    }

    public function loeschen(Request $request): void
    {
        $ergebnis = Datenquellen::loeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/datenquellen');
    }

    /** Einmal abfragen und zeigen, was zurückkommt – ohne zu speichern. */
    public function testen(Request $request): void
    {
        $quelle = Datenquellen::find($request->int('id'));
        if ($quelle === null) {
            Response::notFound();
            return;
        }
        if (Config::isDemo()) {
            Flash::info('Demo-Modus: es wird kein Gerät abgefragt.');
            $this->redirect('/datenquellen');
            return;
        }
        // Modbus bis sammler.modbus_timeout je Block, VRM und ComAp bis 20 s
        // je Anfrage – ohne die Sitzung zu halten; die Meldung kommt danach.
        $ergebnis = Session::ohneSperre(static fn (): array => Abfrage::ausfuehren($quelle));
        Datenquellen::kontakt((int) $quelle['id'], $ergebnis['ok'] ? null : (string) $ergebnis['fehler']);
        if (!$ergebnis['ok']) {
            Flash::error($quelle['name'] . ': ' . $ergebnis['fehler']);
        } else {
            $teile = [];
            foreach ($ergebnis['kanaele'] as $kanal => $werte) {
                $zahlen = [];
                foreach ($werte['phasen'] as $phase => $felder) {
                    foreach ($felder as $f => $w) {
                        $zahlen[] = $phase . ' ' . $f . '=' . round((float) $w, 2);
                    }
                }
                foreach ($werte['zustand'] as $f => $w) {
                    $zahlen[] = $f . '=' . (is_numeric($w) ? round((float) $w, 2) : $w);
                }
                $teile[] = $kanal . ': ' . implode(', ', array_slice($zahlen, 0, 8)) . (count($zahlen) > 8 ? ' …' : '');
            }
            Flash::success($quelle['name'] . ' antwortet. ' . implode(' | ', $teile));
        }
        $this->redirect('/datenquellen');
    }

    public function comapEinheiten(Request $request): void
    {
        $quelle = Datenquellen::find($request->int('id'));
        if ($quelle === null || $quelle['art'] !== 'comap_cloud') {
            Response::notFound();
            return;
        }
        try {
            // Anmeldung und Abfrage bei ComAp – ohne die Sitzung zu halten.
            $einheiten = Session::ohneSperre(static fn (): array => ComapCloud::einheiten($quelle));
            Flash::success('Einheiten in WebSupervisor: ' . implode(' · ', array_map(static fn ($e) => $e['name'] . ' = ' . $e['unitGuid'], $einheiten)));
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/datenquellen?bearbeiten=' . (int) $quelle['id']);
    }

    public function profile(Request $request): void
    {
        $this->view('datenquellen/profile', ['profile' => Profile::alle()]);
    }

    public function profil(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        $profil = $id > 0 ? Profile::findId($id) : null;
        if ($id > 0 && $profil === null) {
            Response::notFound();
            return;
        }
        $this->view('datenquellen/profil', [
            'profil' => $profil ?? ['id' => 0, 'name' => '', 'hersteller' => '', 'beschreibung' => '', 'register' => [], 'befehle' => [], 'ist_mitgeliefert' => 0, 'schluessel' => ''],
            'darf'   => Auth::can('datenquellen.manage'),
        ]);
    }

    public function profilSpeichern(Request $request): void
    {
        $ergebnis = Profile::speichern($request->int('id') ?: null, $_POST);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/datenquellen/profile/' . (int) $ergebnis['id'] : \App\Support\Response::zurueckZiel('/datenquellen/profile'));
    }

    public function profilLoeschen(Request $request): void
    {
        $ergebnis = Profile::loeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/datenquellen/profile');
    }
}
