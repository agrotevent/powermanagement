<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ApiZugang;
use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\ApiZugaenge;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Zeit;

/**
 * System → Schnittstelle (API): Zugänge (Tokens), die diese Instanz an
 * andere Instanzen ausgibt.
 *
 * Ansehen und widerrufen: „api.manage“. Anlegen, ändern, anzeigen und
 * löschen zusätzlich nur mit „Alle Standorte, Anlagen und Messdaten“ – was
 * ein Token sieht, regeln nicht die eigenen Freigaben, also gibt es nur frei,
 * wer ohnehin alles sieht.
 *
 * Ein neues Token steht genau einmal da: direkt in der Antwort auf das
 * Anlegen (nicht zwischengespeichert), nie in Flash, Sitzung oder
 * Weiterleitung. Gegen doppeltes Absenden (Neuladen) trägt das Formular
 * eine Einmal-Marke aus der Sitzung.
 */
final class SchnittstelleController extends Controller
{
    private const ZIEL = '/system/schnittstelle';

    /** Sitzungsschlüssel der ausgegebenen Einmal-Marken (je Formular eine). */
    private const EINMAL = 'api_einmal';

    /** Sitzungsschlüssel: Eingaben nach einem Fehler wieder ins Formular (nie ein Token). */
    private const EINGABE = 'api_eingabe';

    public function index(Request $request): void
    {
        $alles = Zugriff::alles();
        $eingabe = (array) Session::get(self::EINGABE, []);
        Session::forget(self::EINGABE);
        $this->view('schnittstelle/index', [
            'tokens'     => ApiZugaenge::alle(),
            'befugnisse' => ApiZugang::BEFUGNISSE,
            'vergebbar'  => self::vergebbar(),
            'darf'       => $alles,
            'standorte'  => $alles ? SiteService::all() : [],
            'benutzer'   => $alles ? self::bindbar() : [],
            'adresse'    => self::adresse(),
            'einmal'     => $alles ? self::einmalAusgeben() : '',
            'eingabe'    => $eingabe + [
                'name'       => '',
                'notiz'      => '',
                'befugnisse' => ['plaene.lesen'],
                'user_id'    => 0,
                'ablauf'     => Zeit::anzeige(gmdate('c', time() + ApiZugaenge::ABLAUF_TAGE * 86400), 'Y-m-d'),
                'sehen'      => [],
                'bearbeiten' => [],
            ],
        ]);
    }

    public function anlegen(Request $request): void
    {
        header('Cache-Control: no-store');
        if (!$this->alles()) {
            return;
        }
        $e = self::eingabe($request);
        if (!self::einmalEinloesen((string) ($_POST['einmal'] ?? ''))) {
            Flash::error('Dieser Zugang wurde schon angelegt – das Formular ging ein zweites Mal ab. Ein weiteres Token gibt es mit einem neuen Formular.');
            $this->redirect(self::ZIEL);
            return;
        }
        $r = ApiZugaenge::anlegen($e);
        if (!$r['ok']) {
            Flash::error($r['message']);
            Session::put(self::EINGABE, $e);
            $this->redirect(self::ZIEL . '#neuer-zugang');
            return;
        }
        $this->einmalig((int) $r['id'], (string) $r['token'], (string) $r['message'], false);
    }

    public function zugang(Request $request, array $params): void
    {
        if (!$this->alles()) {
            return;
        }
        $token = ctype_digit((string) $params['id']) ? ApiZugaenge::find((int) $params['id']) : null;
        if ($token === null) {
            Response::notFound('Diesen Zugang gibt es nicht.');
            return;
        }
        $sehen = $bearbeiten = [];
        foreach ($token['standorte'] as $s) {
            $sehen[] = $s['id'];
            if ($s['bearbeiten']) {
                $bearbeiten[] = $s['id'];
            }
        }
        $eingabe = (array) Session::get(self::EINGABE, []);
        Session::forget(self::EINGABE);
        if ((int) ($eingabe['id'] ?? 0) !== (int) $token['id']) {
            $eingabe = [];
        }
        $this->view('schnittstelle/zugang', [
            'token'      => $token,
            'befugnisse' => ApiZugang::BEFUGNISSE,
            'vergebbar'  => self::vergebbar(),
            'standorte'  => SiteService::all(),
            'benutzer'   => self::bindbar((int) ($token['user_id'] ?? 0)),
            'eingabe'    => $eingabe + [
                'name'       => (string) $token['name'],
                'notiz'      => (string) $token['notiz'],
                'befugnisse' => $token['befugnisse'],
                'user_id'    => (int) ($token['user_id'] ?? 0),
                'ablauf'     => $token['expires_at'] !== null ? Zeit::anzeige((string) $token['expires_at'], 'Y-m-d') : '',
                'sehen'      => $sehen,
                'bearbeiten' => $bearbeiten,
            ],
        ]);
    }

    public function speichern(Request $request, array $params): void
    {
        if (!$this->alles()) {
            return;
        }
        $id = (int) $params['id'];
        $e = self::eingabe($request);
        $r = ApiZugaenge::speichern($id, $e);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        if (!$r['ok']) {
            Session::put(self::EINGABE, $e + ['id' => $id]);
        }
        $this->redirect(ApiZugaenge::find($id) !== null ? self::ZIEL . '/' . $id : self::ZIEL);
    }

    /** Mit dem eigenen Kennwort noch einmal anzeigen – direkt in der Antwort. */
    public function anzeigen(Request $request, array $params): void
    {
        header('Cache-Control: no-store');
        if (!$this->alles()) {
            return;
        }
        $id = (int) $params['id'];
        // Das Kennwort roh: Leerzeichen am Rand gehören dazu.
        $kennwort = is_string($_POST['kennwort'] ?? null) ? (string) $_POST['kennwort'] : '';
        $r = ApiZugaenge::klartext($id, $kennwort);
        if (!$r['ok']) {
            Flash::error($r['message']);
            $this->redirect(self::ZIEL);
            return;
        }
        $this->einmalig($id, (string) $r['token'], 'Zugang „' . (string) $r['name'] . '“', true);
    }

    public function widerrufen(Request $request, array $params): void
    {
        $this->antwort($request, ApiZugaenge::widerrufen((int) $params['id']), self::ZIEL);
    }

    public function loeschen(Request $request, array $params): void
    {
        if ($this->alles()) {
            $this->antwort($request, ApiZugaenge::loeschen((int) $params['id']), self::ZIEL);
        }
    }

    // ------------------------------------------------------------ Hilfen

    /** Ändern nur mit allen Standorten – sonst 403 mit Begründung. */
    private function alles(): bool
    {
        if (Zugriff::alles()) {
            return true;
        }
        Response::forbidden('Zugänge anlegen, ändern, anzeigen und löschen darf nur, wer alle Standorte sieht '
            . '(Recht „Alle Standorte, Anlagen und Messdaten“) – was ein Token sieht, regeln nicht die eigenen Freigaben.');
        return false;
    }

    /** Die Seite mit dem Token – nur in dieser Antwort, nicht zwischengespeichert. */
    private function einmalig(int $id, string $token, string $meldung, bool $erneut): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        $zugang = ApiZugaenge::find($id);
        $this->view('schnittstelle/einmalig', [
            'token'      => $token,
            'meldung'    => $meldung,
            'erneut'     => $erneut,
            'zugang'     => $zugang,
            'adresse'    => self::adresse() ?? self::adresseAusAnfrage(),
            'vermutet'   => self::adresse() === null,
        ]);
    }

    /** @return array<string,mixed> die Formularfelder für ApiZugaenge */
    private static function eingabe(Request $request): array
    {
        return [
            'name'       => $request->str('name'),
            'notiz'      => $request->str('notiz'),
            'befugnisse' => $request->arr('befugnisse'),
            'user_id'    => $request->int('user_id'),
            'ablauf'     => $request->str('ablauf'),
            'sehen'      => $request->arr('sehen'),
            'bearbeiten' => $request->arr('bearbeiten'),
        ];
    }

    /** @return array<string,bool> Befugnis => darf der Angemeldete sie vergeben */
    private static function vergebbar(): array
    {
        $out = [];
        foreach (ApiZugang::BEFUGNIS_RECHT as $befugnis => $rechte) {
            $out[$befugnis] = BenutzerService::darfVergeben($rechte);
        }
        return $out;
    }

    /**
     * An wen sich ein Token binden lässt: an sich selbst und an jeden, den
     * man verwalten darf (dazu ein schon gebundener, damit er sichtbar bleibt).
     *
     * @return list<array{id:int,username:string,aktiv:bool}>
     */
    private static function bindbar(int $schonGebunden = 0): array
    {
        $out = [];
        foreach (BenutzerService::alle() as $u) {
            $id = (int) $u['id'];
            if ($id === Auth::id() || $id === $schonGebunden || BenutzerService::darfVerwalten($id)) {
                $out[] = ['id' => $id, 'username' => (string) $u['username'], 'aktiv' => (int) $u['is_active'] === 1];
            }
        }
        return $out;
    }

    /** Die Adresse der Schnittstelle aus app.url – oder null, wenn sie nicht gesetzt ist. */
    private static function adresse(): ?string
    {
        $url = trim((string) Config::get('app.url', ''));
        return $url === '' ? null : rtrim($url, '/') . '/api/v1';
    }

    /** Ohne app.url: so, wie diese Seite gerade aufgerufen wurde (nur zur Anzeige). */
    private static function adresseAusAnfrage(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_match('/^[A-Za-z0-9.:\[\]-]{1,255}$/', $host) === 1 ? $host : 'localhost';
        return (Session::isHttps() ? 'https' : 'http') . '://' . $host . '/api/v1';
    }

    /** Eine Einmal-Marke für ein Formular „Neuer Zugang“ ausgeben (höchstens zehn offen). */
    private static function einmalAusgeben(): string
    {
        $marke = bin2hex(random_bytes(16));
        $liste = array_values(array_filter((array) Session::get(self::EINMAL, []), 'is_string'));
        $liste[] = $marke;
        Session::put(self::EINMAL, array_slice($liste, -10));
        return $marke;
    }

    /** Die Marke einlösen – gilt genau einmal. */
    private static function einmalEinloesen(string $marke): bool
    {
        $liste = array_values(array_filter((array) Session::get(self::EINMAL, []), 'is_string'));
        foreach ($liste as $i => $offen) {
            if ($marke !== '' && hash_equals($offen, $marke)) {
                unset($liste[$i]);
                Session::put(self::EINMAL, array_values($liste));
                return true;
            }
        }
        return false;
    }
}
