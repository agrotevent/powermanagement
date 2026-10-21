<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtSchema;
use App\Berichte\BerichtService;
use App\Berichte\Einstellungen;
use App\Berichte\Grenzwerte;
use App\Berichte\Kundenmodus;
use App\Berichte\Pruefgeraete;
use App\Berichte\Vorbelegung;
use App\Kunden\KundenService;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\Eventworx;
use App\Services\EventworxFehler;
use App\Services\SiteService;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Übergabeberichte: Liste, Anlegen, Formular mit Autosave, Eventworx,
 * Unterschriften, Kundenmodus, Korrektur.
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
        if ($zweck === 'bearbeiten' && ($b['status'] !== 'entwurf' || BerichtService::eingefroren($b))) {
            $this->fehler($request, 423, BerichtService::EINGEFROREN, '/berichte/' . $id);
            return null;
        }
        $erlaubt = match ($zweck) {
            'bearbeiten' => BerichtService::darfBearbeiten($b),
            'kunde'      => BerichtService::darfKundenblock($b),
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
        $inhalt = BerichtSchema::vollstaendig($b['inhalt']);
        $bewertung = Grenzwerte::alle($inhalt);
        if ($b['status'] !== 'entwurf' || !BerichtService::darfBearbeiten($b)) {
            $pruefer = BerichtService::darfPruefen($b);
            $this->view('berichte/show', [
                'bericht'       => $b,
                'inhalt'        => $inhalt,
                'bewertung'     => $inhalt['bewertung'] ?? $bewertung,
                'unterschriften' => BerichtService::unterschriften((int) $b['id']),
                'integritaet'   => BerichtService::integritaet((int) $b['id']),
                'ereignisse'    => Auth::can('berichte.manage') ? BerichtService::ereignisse((int) $b['id']) : [],
                'darfLoeschen'  => $b['status'] === 'entwurf' && $b['nummer'] === null && BerichtService::darfBearbeiten($b),
                'darfVerwerfen' => $b['status'] === 'entwurf' && $b['nummer'] !== null && $pruefer,
                'darfKunde'     => BerichtService::darfKundenblock($b),
                'darfOhneKunde' => $b['status'] === 'geprueft' && $pruefer,
                'zurueckziehbar' => $pruefer && BerichtService::zurueckziehbar($b),
                'darfKorrektur' => $b['status'] === 'abgeschlossen' && $pruefer && $b['jahr'] !== null,
                'offeneKorrektur' => in_array($b['status'], ['abgeschlossen', 'ersetzt'], true) ? BerichtService::offeneKorrektur($b) : null,
                'vorgaenger'    => $b['vorgaenger_id'] !== null ? BerichtService::find((int) $b['vorgaenger_id']) : null,
                'pdfHash'       => in_array($b['status'], ['abgeschlossen', 'ersetzt'], true) ? BerichtService::pdfHash((int) $b['id']) : null,
                'ewxLink'       => (string) $b['ewx_id'] !== '' && Auth::can('berichte.eventworx') ? Eventworx::link((string) $b['ewx_art'], (string) $b['ewx_id']) : '',
                // Karte „Kundenbereich“: nur mit „kunden.view“ (sonst null).
                'kundenbereich' => KundenService::berichtKarte($b),
                'scripts'       => ['/assets/js/bericht-pdf.js'],
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
            'darfUnterschreiben' => BerichtService::darfPruefen($b),
            'vorgaenger'    => $b['vorgaenger_id'] !== null ? BerichtService::find((int) $b['vorgaenger_id']) : null,
            'scripts'       => ['/assets/js/unterschrift.js', '/assets/js/bericht.js'],
        ]);
    }

    /**
     * Das PDF: abgeschlossen oder ersetzt die gespeicherten Bytes, sonst
     * eine Vorschau mit Wasserzeichen. Der Abruf der Vorschau eines vom
     * Prüfer unterschriebenen Berichts steht im Lebenslauf – danach lässt
     * sich die Unterschrift nicht mehr zurückziehen (der Kunde kann es
     * gesehen haben). Kopfzeilen von Hand: inline, nicht zwischenspeichern,
     * kein Referrer; keine CSP mit sandbox (die PDF-Ansicht von Chrome
     * verweigert sonst).
     */
    public function pdf(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params);
        if ($b === null) {
            return;
        }
        $pdf = BerichtService::pdf($b);
        if ($b['status'] === 'geprueft') {
            BerichtService::pdfAbgerufen((int) $b['id']);
        }
        Response::pdf($pdf['name'], $pdf['bytes']);
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
    //
    // Beide Wege warten auf Eventworx (bis zu 12 s je Versuch, dazu Drossel
    // und Nachlesen). Sie starten nur auf Knopfdruck, die Suche auch mit
    // Enter – eine Live-Suche beim Tippen gibt es nicht. Nach der Prüfung des
    // Berichts geben sie die Sitzung frei – sonst stünden das Autosave und
    // jede andere Anfrage dieses Browsers so lange an. Danach wird nur noch
    // gelesen; den Verweis trägt Session::nachtragen() ein.

    public function eventworxSuche(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'bearbeiten');
        if ($b === null) {
            return;
        }
        Session::freigeben();
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
        Session::freigeben();
        try {
            $v = Eventworx::vorgang($request->str('vorgang_id'));
        } catch (EventworxFehler $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], $e->fehlercode === 'ID' ? 422 : 502);
            return;
        }
        $am = gmdate('c');
        // Die Vormerkung steht in der Sitzung: dafür kurz wieder öffnen. Ist
        // sie inzwischen beendet (abgemeldet), gehen auch keine Kundendaten hinaus.
        if (!Session::nachtragen(static fn () => BerichtService::eventworxMerken((int) $b['id'], $v['verweis'], $am))) {
            $this->json(['ok' => false, 'anmelden' => true, 'message' => 'Sitzung abgelaufen – bitte neu anmelden.'], 401);
            return;
        }
        $this->json(['ok' => true, 'verweis' => $v['verweis'] + ['am' => $am], 'gruppen' => $v['gruppen']]);
    }

    // ------------------------------------------------------------ Unterschreiben

    /** Prüfer-Unterschrift: version, striche (JSON), name, ort, anfrage, geraet_am, warnungen[] – Antwort JSON. */
    public function unterschreiben(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $this->ergebnis(BerichtService::prueferUnterschreiben($id, $request->int('version', -1), [
            'striche'   => is_string($_POST['striche'] ?? null) ? $_POST['striche'] : '',
            'name'      => $request->str('name'),
            'ort'       => $request->str('ort'),
            'anfrage'   => $request->str('anfrage'),
            'geraet_am' => $request->str('geraet_am'),
            'warnungen' => $request->arr('warnungen'),
        ]));
    }

    public function zurueckziehen(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'pruefen');
        if ($b === null) {
            return;
        }
        $e = BerichtService::zurueckziehen((int) $b['id'], $request->str('grund'));
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect('/berichte/' . (int) $b['id']);
    }

    public function ohneKunde(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'pruefen');
        if ($b === null) {
            return;
        }
        $hash = $request->str('inhalt_hash');
        $e = BerichtService::ohneKunde((int) $b['id'], $request->str('art'), $request->str('grund'), $hash !== '' ? $hash : null);
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect('/berichte/' . (int) $b['id']);
    }

    /** Korrektur als neue Fassung – weiter im Formular der neuen Fassung. */
    public function korrektur(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params, 'pruefen');
        if ($b === null) {
            return;
        }
        $e = BerichtService::korrektur((int) $b['id'], $request->str('grund'));
        $e['ok'] ? Flash::success($e['message']) : Flash::error($e['message']);
        $this->redirect('/berichte/' . (int) ($e['id'] ?? $b['id']));
    }

    // ------------------------------------------------------------ Kundenmodus

    /** „An Kunden übergeben“: Sitzung sperren, weiter zur Kundenseite. */
    public function kundenmodus(Request $request, array $params): void
    {
        $b = $this->bericht($request, $params);
        if ($b === null) {
            return;
        }
        $e = BerichtService::kundenmodusStarten((int) $b['id']);
        if (!$e['ok']) {
            $this->fehler($request, (int) $e['status'], $e['message'], '/berichte/' . (int) $b['id']);
            return;
        }
        $this->redirect('/berichte/' . (int) $b['id'] . '/kunde');
    }

    /**
     * Die Kundenseite: der Bericht zum Lesen, Bestätigungen, Unterschrift –
     * nach dem Absenden „Gerät zurückgeben“ mit dem Kennwortfeld. Eigenes
     * Layout ohne Navigation; ohne aktiven Kundenmodus geht es zur
     * Berichtsseite.
     */
    public function kunde(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $modus = Kundenmodus::aktiv();
        $b = $id > 0 ? BerichtService::find($id) : null;
        if ($modus !== null && $modus['bericht'] === $id && ($b === null || !BerichtService::sichtbar($b))) {
            // Gesperrt auf einen Bericht, den dieses Konto (nicht mehr) sieht:
            // nur das Entsperren – sonst führte jeder Weg ins Leere.
            $this->view('berichte/kunde-gesperrt', ['id' => $id, 'modus' => $modus, 'title' => 'Gerät gesperrt',
                'firma' => Einstellungen::wert('firma.name')], 'layout/kunde');
            return;
        }
        $b = $this->bericht($request, $params);
        if ($b === null) {
            return;
        }
        if ($modus === null || $modus['bericht'] !== (int) $b['id']) {
            Flash::info('Der Kundenmodus ist nicht aktiv.');
            $this->redirect('/berichte/' . (int) $b['id']);
            return;
        }
        $inhalt = BerichtSchema::vollstaendig($b['inhalt']);
        $this->view('berichte/kunde', [
            'bericht'        => $b,
            'inhalt'         => $inhalt,
            'bewertung'      => $inhalt['bewertung'] ?? Grenzwerte::alle($inhalt),
            'unterschriften' => BerichtService::unterschriften((int) $b['id']),
            'modus'          => $modus,
            // Wer den Kundenmodus gestartet hat – nur mit dessen Kennwort geht es weiter.
            'prueferName'    => $modus['name'],
            'firma'          => (string) ($inhalt['auftragnehmer']['name'] ?? Einstellungen::wert('firma.name')),
            // Ist der Bericht einem Kunden mit eigenem Zugang zugeordnet, findet er ihn nach dem Abschluss im Kundenbereich.
            'imKundenbereich' => $b['kunde_id'] !== null && KundenService::aktiveZugaenge((int) $b['kunde_id']) > 0,
            'scripts'        => ['/assets/js/unterschrift.js', '/assets/js/bericht-kunde.js'],
        ], 'layout/kunde');
    }

    /** Kundenunterschrift (nur im Kundenmodus dieses Berichts) – Antwort JSON. */
    public function kundeUnterschreiben(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $modus = Kundenmodus::aktiv();
        if ($modus === null || $modus['bericht'] !== $id) {
            $this->json(['ok' => false, 'message' => 'Der Kundenmodus ist nicht aktiv – bitte den Prüfer fragen.'], 409);
            return;
        }
        $this->ergebnis(BerichtService::kundeUnterschreiben($id, [
            'striche'        => is_string($_POST['striche'] ?? null) ? $_POST['striche'] : '',
            'name'           => $request->str('name'),
            'funktion'       => $request->str('funktion'),
            'ort'            => $request->str('ort'),
            'bestaetigungen' => $request->arr('bestaetigungen'),
            'vorbehalte'     => is_string($_POST['vorbehalte'] ?? null) ? $_POST['vorbehalte'] : '',
            'anfrage'        => $request->str('anfrage'),
            'geraet_am'      => $request->str('geraet_am'),
            'inhalt_hash'    => $request->str('inhalt_hash'),
        ]));
    }

    /**
     * Entsperren mit dem Kennwort dessen, der den Kundenmodus gestartet hat.
     * Ein Fehler zählt nur in der Sitzung (das Konto bleibt offen); ab dem
     * fünften wird das Entsperren gebremst – abgemeldet wird nie, sonst
     * stünde dem Kunden die Anmeldeseite (mit gespeichertem Kennwort) offen.
     */
    public function kundeBeenden(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $modus = Kundenmodus::aktiv();
        if ($modus === null || $modus['bericht'] !== $id) {
            $this->redirect('/berichte/' . $id);
            return;
        }
        $warten = Kundenmodus::wartezeit();
        if ($warten > 0) {
            Flash::error('Zu viele falsche Kennwörter – Entsperren erst in ' . self::dauer($warten) . ' wieder möglich.');
            $this->redirect('/berichte/' . $id . '/kunde');
            return;
        }
        if ($modus['user'] === (int) Auth::id() && Auth::kennwortBestaetigen(is_string($_POST['kennwort'] ?? null) ? $_POST['kennwort'] : '')) {
            BerichtService::ereignis($id, 'kundenmodus_ende');
            Kundenmodus::beenden();
            Session::regenerate();
            Csrf::erneuern();
            Flash::success('Kundenmodus beendet.');
            $this->redirect('/berichte/' . $id);
            return;
        }
        $fehl = Kundenmodus::fehlversuch();
        if ($fehl >= Kundenmodus::HOECHSTENS_FEHL) {
            BerichtService::ereignis($id, 'kundenmodus_gebremst', ['fehlversuche' => (string) $fehl]);
            Flash::error('Zu viele falsche Kennwörter – Entsperren erst in ' . self::dauer(Kundenmodus::wartezeit()) . ' wieder möglich.');
            $this->redirect('/berichte/' . $id . '/kunde');
            return;
        }
        $rest = Kundenmodus::HOECHSTENS_FEHL - $fehl;
        Flash::error('Kennwort falsch – noch ' . $rest . ($rest === 1 ? ' Versuch' : ' Versuche') . ', dann wird das Entsperren gebremst.');
        $this->redirect('/berichte/' . $id . '/kunde');
    }

    private static function dauer(int $sekunden): string
    {
        $min = max(1, (int) ceil($sekunden / 60));
        return $min . ($min === 1 ? ' Minute' : ' Minuten');
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
}
