<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtSchema;
use App\Berichte\BerichtService;
use App\Berichte\Einstellungen;
use App\Berichte\Grenzwerte;
use App\Berichte\Pruefgeraete;
use App\Berichte\Vorbelegung;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\Eventworx;
use App\Services\EventworxFehler;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

/**
 * Übergabeberichte: Liste, Anlegen, Formular mit Autosave, Eventworx.
 *
 * Das Recht prüft die Route; woran jemand arbeiten darf, BerichtService
 * (sichtbar, darfBearbeiten …). Ein unsichtbarer Bericht ist für den
 * Fragenden nicht da (404) – auch ein Entwurf auf einem Standort, den er
 * nur sieht: Entwürfe zeigt §8.3 nur denen, die dort ändern dürfen.
 *
 * Die Schreibwege des Formulars antworten immer JSON mit dem HTTP-Status,
 * den der Service nennt (409 Konflikt, 422 unlesbar, 423 unterschrieben);
 * Eventworx-Fehler kommen als 502 mit der deutschen Meldung.
 */
final class BerichtController extends Controller
{
    /**
     * Der Bericht aus der Adresse – oder null, wenn die Antwort schon raus ist.
     * 404, wenn unsichtbar; 423, wenn er nicht mehr ausgefüllt werden kann;
     * 403, wenn der Zweck nicht erlaubt ist.
     */
    private function bericht(Request $request, array $params, string $zweck = ''): ?array
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $b = $id > 0 ? BerichtService::find($id) : null;
        if ($b === null || !BerichtService::sichtbar($b)) {
            $this->fehler($request, 404, 'Diesen Bericht gibt es nicht.');
            return null;
        }
        if ($zweck === 'bearbeiten' && $b['status'] !== 'entwurf') {
            $this->fehler($request, 423, BerichtService::EINGEFROREN, '/berichte/' . $id);
            return null;
        }
        $erlaubt = match ($zweck) {
            'bearbeiten' => BerichtService::darfBearbeiten($b),
            'pruefen'    => BerichtService::darfPruefen($b),
            default      => true,
        };
        if (!$erlaubt) {
            $this->fehler($request, 403, $zweck === 'pruefen'
                ? 'Als Prüfer handeln darf nur, wer „als Prüfer unterschreiben“ hat, eine hinterlegte Qualifikation und „bearbeiten“ für den Standort.'
                : 'Für diesen Bericht fehlt eine Freigabe mit „bearbeiten“.', '/berichte/' . $id);
            return null;
        }
        return $b;
    }

    private function fehler(Request $request, int $status, string $meldung, string $ziel = '/berichte'): void
    {
        if ($request->wantsJson()) {
            $this->json(['ok' => false, 'message' => $meldung], $status);
            return;
        }
        if ($status === 404) {
            Response::notFound($meldung);
        } elseif ($status === 403) {
            Response::forbidden($meldung);
        } else {
            Flash::error($meldung);
            $this->redirect($ziel);
        }
    }

    /** Antwort eines Service-Aufrufs mit seinem Status als JSON (ohne den Status selbst). */
    private function ergebnis(array $e): void
    {
        $status = (int) ($e['status'] ?? (!empty($e['ok']) ? 200 : 422));
        unset($e['status']);
        $this->json($e, $status);
    }

    // ------------------------------------------------------------ Liste

    public function index(Request $request): void
    {
        $filter = [
            'status'  => $request->str('status'),
            'site_id' => $request->int('site'),
            'jahr'    => $request->str('jahr'),
            'suche'   => $request->str('suche'),
        ];
        $liste = BerichtService::liste($filter);
        // Jahre nur aus dem, was der Benutzer sieht – nicht aus allen Berichten.
        $sichtbar = $filter['jahr'] !== '' ? BerichtService::liste(['jahr' => ''] + $filter) : $liste;
        $jahre = array_values(array_unique(array_map(static fn (array $b): int => (int) substr((string) $b['pruefdatum'], 0, 4), $sichtbar)));
        rsort($jahre);
        $this->view('berichte/index', [
            'liste'     => $liste,
            'filter'    => $filter,
            'standorte' => Zugriff::standorteFiltern(SiteService::all()),
            'jahre'     => array_values(array_filter($jahre, static fn (int $j): bool => $j > 0)),
        ]);
    }

    // ------------------------------------------------------------ Anlegen

    /**
     * Standort → Anlagen als Häkchen-Kacheln → anlegen. Aus dem Aufbau sind
     * alle mobilen Anlagen vorausgewählt, von einer Anlage aus sie und was
     * sie speist.
     */
    public function neu(Request $request): void
    {
        $aufbau = $request->int('aufbau') > 0 ? AufbauService::find($request->int('aufbau')) : null;
        $anlage = $request->int('anlage') > 0 ? AnlagenService::find($request->int('anlage')) : null;
        if ($anlage !== null && ((int) $anlage['ist_vorlage'] === 1 || $anlage['site_id'] === null || !Zugriff::siehtObjekt($anlage))) {
            $anlage = null;
        }
        $siteId = $aufbau !== null ? (int) $aufbau['site_id'] : ($anlage !== null ? (int) $anlage['site_id'] : ($request->int('site') ?: null));
        $standorte = array_values(array_filter(SiteService::all(), static fn (array $s): bool => Zugriff::darfStandortAendern((int) $s['id'])));
        $site = $siteId !== null ? SiteService::find($siteId) : null;
        if ($site !== null && !Zugriff::darfStandortAendern($siteId)) {
            Response::forbidden('Für diesen Standort fehlt eine Freigabe mit „bearbeiten“ – Berichte legt an, wer dort ändern darf.');
            return;
        }

        $kacheln = [];
        $aufbauten = [];
        if ($site !== null) {
            $aufbau ??= AufbauService::aktiv((int) $site['id']);
            $aufbauten = AufbauService::liste((int) $site['id']);
            $unter = [];
            $sammeln = static function (array $knoten, int $tiefe, bool $darunter) use (&$sammeln, &$kacheln, &$unter, $anlage): void {
                $darunter = $darunter || ($anlage !== null && (int) $knoten['id'] === (int) $anlage['id']);
                if (Zugriff::siehtObjekt($knoten)) {
                    $kacheln[] = ['objekt' => $knoten, 'tiefe' => $tiefe];
                    if ($darunter) {
                        $unter[(int) $knoten['id']] = true;
                    }
                }
                foreach ($knoten['kinder'] as $kind) {
                    $sammeln($kind, $tiefe + 1, $darunter);
                }
            };
            foreach (AnlagenService::baum((int) $site['id']) as $wurzel) {
                $sammeln($wurzel, 0, false);
            }
            foreach ($kacheln as &$k) {
                $k['gewaehlt'] = $anlage !== null ? isset($unter[(int) $k['objekt']['id']]) : ($aufbau !== null && (int) $k['objekt']['fest'] === 0);
            }
            unset($k);
        }
        $this->view('berichte/neu', [
            'site'      => $site,
            'standorte' => $standorte,
            'aufbau'    => $aufbau,
            'aufbauten' => $aufbauten,
            'anlage'    => $anlage,
            'kacheln'   => $kacheln,
        ]);
    }

    public function anlegen(Request $request): void
    {
        if ($request->bool('leer')) {
            $e = BerichtService::anlegen(null, null, []);
        } else {
            $siteId = $request->int('site_id') ?: null;
            $aufbauId = $request->int('aufbau_id') ?: null;
            $e = BerichtService::anlegen($siteId, $aufbauId, $request->arr('objekte'));
        }
        if (!$e['ok']) {
            Flash::error($e['message']);
            $this->redirect('/berichte/neu' . ($request->int('site_id') > 0 ? '?site=' . $request->int('site_id') : ''));
            return;
        }
        Flash::success($e['message'] . ' Eingaben werden laufend gespeichert.');
        $this->redirect('/berichte/' . (int) $e['id']);
    }

    // ------------------------------------------------------------ Ansehen und ausfüllen

    public function show(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params);
        if ($b === null) {
            return;
        }
        $inhalt = self::vollstaendig($b['inhalt']);
        $bewertung = Grenzwerte::alle($inhalt);
        if ($b['status'] !== 'entwurf' || !BerichtService::darfBearbeiten($b)) {
            $this->view('berichte/show', [
                'bericht'    => $b,
                'inhalt'     => $inhalt,
                'bewertung'  => $inhalt['bewertung'] ?? $bewertung,
                'ereignisse' => Auth::can('berichte.manage') ? BerichtService::ereignisse((int) $b['id']) : [],
                'darfLoeschen'  => $b['status'] === 'entwurf' && $b['nummer'] === null && BerichtService::darfBearbeiten($b),
                'darfVerwerfen' => $b['status'] === 'entwurf' && $b['nummer'] !== null && BerichtService::darfPruefen($b),
            ]);
            return;
        }

        $objektIds = array_map('intval', array_column(
            Database::select('SELECT objekt_id FROM bericht_objekte WHERE bericht_id = ? AND objekt_id IS NOT NULL', [(int) $b['id']]), 'objekt_id'));
        $geraete = [];
        foreach (Pruefgeraete::liste() as $g) {
            if ((int) $g['aktiv'] === 1 || in_array((string) $g['id'], $inhalt['messung']['geraete'], true)) {
                $geraete[] = $g;
            }
        }
        $anlagen = [];
        if ($b['site_id'] !== null) {
            foreach (AnlagenService::liste(['site_id' => (int) $b['site_id']]) as $o) {
                if (Zugriff::siehtObjekt($o)) {
                    $anlagen[] = $o + ['im_bericht' => in_array((int) $o['id'], $objektIds, true)];
                }
            }
        }
        $user = Auth::user() ?? [];
        $this->view('berichte/formular', [
            'bericht'       => $b,
            'inhalt'        => $inhalt,
            'bewertung'     => $bewertung,
            'abschnitte'    => BerichtService::abschnitte($inhalt, $bewertung),
            'hinweise'      => Vorbelegung::hinweise($inhalt, $b['site_id'], $b['aufbau_id']),
            'netzVorschlag' => Vorbelegung::netzVorschlag($objektIds),
            'geraete'       => $geraete,
            'anlagen'       => $anlagen,
            'quellen'       => BerichtService::kundenQuellen($b),
            'eventworx'     => [
                'recht'        => Auth::can('berichte.eventworx'),
                'eingerichtet' => Eventworx::eingerichtet(),
                'link'         => (string) $b['ewx_id'] !== '' ? Eventworx::link((string) $b['ewx_art'], (string) $b['ewx_id']) : '',
            ],
            'pruefer'       => [
                'name'          => trim((string) (($user['full_name'] ?? '') !== '' ? $user['full_name'] : ($user['username'] ?? ''))),
                'qualifikation' => trim((string) ($user['pruefer_qualifikation'] ?? '')),
                'recht'         => Auth::can('berichte.pruefen'),
            ],
            'texte'         => ['errichter' => Einstellungen::wert('berichte.text_errichter')],
            'firmaFehlt'    => Einstellungen::wert('firma.name') === '',
            'darfVerwerfen' => $b['nummer'] !== null && BerichtService::darfPruefen($b),
            'scripts'       => ['/assets/js/bericht.js'],
        ]);
    }

    /** Autosave: `version`, `inhalt` (JSON), optional `ewx` – Antwort mit Bewertung. */
    public function speichern(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $json = is_string($_POST['inhalt'] ?? null) ? $_POST['inhalt'] : '';
        $ewx = is_string($_POST['ewx'] ?? null) ? $_POST['ewx'] : '';
        $this->ergebnis(BerichtService::speichern($id, $request->int('version', -1), $json, $ewx));
    }

    /** „Zeilen ergänzen“ für neue Abgänge der gewählten Anlagen. */
    public function kreise(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $this->ergebnis(BerichtService::kreiseVorbelegen($id, $request->int('version', -1), $request->arr('objekte')));
    }

    /** Kunde aus einem früheren Bericht desselben Standorts (nur zum Eintragen; gespeichert wird beim nächsten Autosave). */
    public function kundeAus(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $this->ergebnis(BerichtService::kundeAusBericht($id, $request->int('quelle')));
    }

    /** Was vor der Prüfer-Unterschrift noch fehlt – aus dem gespeicherten Stand. */
    public function pruefen(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'bearbeiten');
        if ($b === null) {
            return;
        }
        $e = BerichtService::abschlussPruefen($b);
        $offen = array_filter($e['warnungen'], static fn (array $w): bool => !$w['bestaetigt']);
        $this->json(['ok' => true, 'version' => (int) $b['version'], 'fehler' => $e['fehler'], 'warnungen' => $e['warnungen'],
            'bereit' => $e['fehler'] === [] && $offen === []]);
    }

    // ------------------------------------------------------------ Eventworx (nur lesen)

    public function eventworxSuche(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'bearbeiten');
        if ($b === null) {
            return;
        }
        try {
            $this->json(['ok' => true, 'treffer' => Eventworx::suchen($request->str('q'))]);
        } catch (EventworxFehler $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], $e->fehlercode === 'SUCHTEXT' ? 422 : 502);
        }
    }

    /** Einen Vorgang lesen; der Verweis (ohne Kundendaten) wird für das nächste Autosave vorgemerkt. */
    public function eventworxVorgang(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'bearbeiten');
        if ($b === null) {
            return;
        }
        try {
            $v = Eventworx::vorgang($request->str('vorgang_id'));
        } catch (EventworxFehler $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], $e->fehlercode === 'ID' ? 422 : 502);
            return;
        }
        $am = gmdate('c');
        BerichtService::eventworxMerken((int) $b['id'], $v['verweis'], $am);
        $this->json(['ok' => true, 'verweis' => $v['verweis'] + ['am' => $am], 'gruppen' => $v['gruppen']]);
    }

    // ------------------------------------------------------------ Verwerfen, Löschen

    public function verwerfen(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'pruefen');
        if ($b === null) {
            return;
        }
        $e = BerichtService::verwerfen((int) $b['id'], $request->str('grund'));
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect('/berichte/' . (int) $b['id']);
    }

    public function loeschen(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params);
        if ($b === null) {
            return;
        }
        $e = BerichtService::loeschen((int) $b['id']);
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect($e['ok'] ? '/berichte' : '/berichte/' . (int) $b['id']);
    }

    /** Der gespeicherte Inhalt mit allen Schlüsseln des Schemas (Listen bleiben, wie sie sind). */
    public static function vollstaendig(array $inhalt): array
    {
        $voll = array_replace_recursive(BerichtSchema::leer(), $inhalt);
        foreach (['kreise', 'maengel'] as $liste) {
            $voll[$liste] = is_array($inhalt[$liste] ?? null) ? array_values($inhalt[$liste]) : [];
        }
        foreach (['normen'] as $l) {
            $voll['grundlage'][$l] = is_array($inhalt['grundlage'][$l] ?? null) ? array_values($inhalt['grundlage'][$l]) : [];
        }
        $voll['messung']['geraete'] = is_array($inhalt['messung']['geraete'] ?? null) ? array_values(array_map('strval', $inhalt['messung']['geraete'])) : [];
        $voll['punkte'] = ['besichtigung' => (array) ($inhalt['punkte']['besichtigung'] ?? []), 'erprobung' => (array) ($inhalt['punkte']['erprobung'] ?? [])];
        $voll['abschluss']['warnungen_bestaetigt'] = is_array($inhalt['abschluss']['warnungen_bestaetigt'] ?? null) ? $inhalt['abschluss']['warnungen_bestaetigt'] : [];
        foreach ($voll['kreise'] as $i => $k) {
            $voll['kreise'][$i] = array_replace_recursive(BerichtSchema::kreisLeer(), is_array($k) ? $k : []);
            $voll['kreise'][$i]['abgeleitet'] = is_array($k['abgeleitet'] ?? null) ? array_values($k['abgeleitet']) : [];
        }
        return $voll;
    }
}
