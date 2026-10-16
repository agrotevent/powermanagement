<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\PartnerAbgleich;
use App\Services\PartnerService;
use App\Services\SiteService;
use App\Support\Crypto;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Standorte → Andere Instanzen (Seite B): Instanzen eintragen, prüfen,
 * austragen; ihre Locations übernehmen, zuordnen, verknüpfen, lösen und
 * Änderungen zurückschicken.
 *
 * Die Route verlangt „api.partner“. Woran jemand etwas ändern darf
 * (Standort mit „bearbeiten“, Pläne, Anlagen, Kabel, Steckertypen), prüft
 * PartnerAbgleich. Weitergeleitet wird nur auf feste Pfade mit Zahlen-IDs.
 * Was von der Gegenseite kommt – Namen, Meldungen –, geht in den Ansichten
 * nur durch View::e().
 */
final class InstanzenController extends Controller
{
    private const ZIEL = '/instanzen';

    /** Sitzungsschlüssel: Name und Adresse nach einem gescheiterten Eintragen (nie das Token). */
    private const EINGABE = 'instanz_eingabe';

    public function index(Request $request): void
    {
        $eingabe = (array) Session::get(self::EINGABE, []);
        Session::forget(self::EINGABE);
        $this->view('instanzen/index', [
            'partner'   => PartnerService::alle(),
            'eingabe'   => $eingabe + ['name' => '', 'adresse' => ''],
            'hindernis' => Crypto::schluesselFehler(),
        ]);
    }

    public function anlegen(Request $request): void
    {
        $name = $request->str('name');
        $adresse = $request->str('adresse');
        // Nur aus dem Formular – nie aus der Adresse.
        $token = is_string($_POST['token'] ?? null) ? (string) $_POST['token'] : '';
        $r = PartnerService::anlegen($name, $adresse, $token);
        if ($r['ok']) {
            Flash::success($r['message']);
            $this->redirect(self::ZIEL . '/' . (int) $r['id']);
            return;
        }
        Flash::error($r['message']);
        // Meist ist nur die Adresse zu ändern (die IP statt des Namens) –
        // Name und Adresse stehen wieder im Formular, das Token nicht.
        Session::put(self::EINGABE, ['name' => mb_substr($name, 0, 190), 'adresse' => mb_substr($adresse, 0, 255)]);
        $this->redirect(self::ZIEL);
    }

    public function instanz(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $this->view('instanzen/instanz', [
            'partner'   => $partner,
            'locations' => PartnerAbgleich::locations($partner),
            'planen'    => Auth::can('plans.manage'),
        ]);
    }

    public function pruefen(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $token = is_string($_POST['token'] ?? null) ? (string) $_POST['token'] : '';
        $r = PartnerService::pruefen((int) $partner['id'], $token);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . (int) $partner['id']);
    }

    public function loeschen(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $r = PartnerService::loeschen((int) $partner['id']);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL);
    }

    /** Seite „Zuordnen“: Standort hier und je Etage dort die Etage hier wählen. */
    public function zuordnen(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $schluessel = $request->str('schluessel');
        $z = PartnerAbgleich::zuordnung($partner, $schluessel, self::ziel($request->str('ziel')));
        if (!$z['ok']) {
            Flash::error($z['message']);
            $this->redirect(self::ZIEL . '/' . (int) $partner['id']);
            return;
        }
        $this->view('instanzen/zuordnen', ['partner' => $partner] + $z);
    }

    /**
     * Übernehmen oder nur verknüpfen. Aus der Liste ohne Etagenwahl (dann
     * ordnet PartnerAbgleich selbst zu), vom Formular „Zuordnen“ mit Ziel
     * und Etagen.
     */
    public function uebernehmen(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $schluessel = $request->str('schluessel');
        $wahl = $_POST['etagen'] ?? null;
        $etagen = null;
        if (is_array($wahl)) {
            $etagen = [];
            foreach ($wahl as $key => $wert) {
                // Ein Etagenschlüssel „1“ kommt bei PHP als Zahl an.
                if (is_scalar($wert)) {
                    $etagen[(string) $key] = (string) $wert;
                }
            }
        }
        $zielText = is_string($_POST['ziel'] ?? null) ? trim((string) $_POST['ziel']) : '';
        $r = PartnerAbgleich::uebernehmen($partner, $schluessel, self::ziel($zielText), $etagen,
            ($_POST['feststrom'] ?? '') === '1', ($_POST['aktion'] ?? '') === 'verknuepfen');
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $basis = self::ZIEL . '/' . (int) $partner['id'];
        if ($r['ok'] || $etagen === null || preg_match('/^[a-z0-9-]{1,190}$/', $schluessel) !== 1) {
            $this->redirect($basis);
            return;
        }
        // Zurück ins Formular „Zuordnen“ – mit derselben Wahl des Standorts.
        $ziel = self::ziel($zielText);
        $this->redirect($basis . '/zuordnen?schluessel=' . rawurlencode($schluessel) . ($ziel !== null ? '&ziel=' . ($ziel === 0 ? 'neu' : $ziel) : ''));
    }

    public function loesen(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $r = PartnerAbgleich::loesen($request->int('site_id'), (int) $partner['id']);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . (int) $partner['id']);
    }

    /** Hochladen (Gegenseite mit „schreiben“) oder als Vorschlag schicken. */
    public function zurueck(Request $request, array $params): void
    {
        $partner = $this->partner($params);
        if ($partner === null) {
            return;
        }
        $basis = self::ZIEL . '/' . (int) $partner['id'];
        $siteId = $request->int('site_id');
        $site = SiteService::find($siteId);
        if ($site === null || (int) ($site['partner_id'] ?? 0) !== (int) $partner['id']) {
            Flash::error('Dieser Standort ist nicht mit dieser Instanz verknüpft.');
            $this->redirect($basis);
            return;
        }
        if (!Auth::can('plans.manage') || !Zugriff::darfStandortAendern($siteId)) {
            Flash::error('Zurückschicken braucht das Recht „Standorte, Etagen und Grundrisse pflegen“ und eine Freigabe mit „bearbeiten“ an diesem Standort.');
            $this->redirect($basis);
            return;
        }
        $notiz = is_string($_POST['notiz'] ?? null) ? mb_substr(trim((string) $_POST['notiz']), 0, 2000) : '';
        $r = PartnerAbgleich::zurueckschicken($siteId, $notiz, ($_POST['erzwingen'] ?? '') === '1');
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect($basis);
    }

    /** Die Instanz aus der Adresse – sonst 404 (und null). */
    private function partner(array $params): ?array
    {
        $id = (string) ($params['id'] ?? '');
        $partner = ctype_digit($id) ? PartnerService::find((int) $id) : null;
        if ($partner === null) {
            Response::notFound('Diese Instanz ist hier nicht eingetragen.');
        }
        return $partner;
    }

    /** „neu“ → 0, eine Zahl → Standort, sonst automatisch (null). */
    private static function ziel(string $wert): ?int
    {
        return match (true) {
            $wert === 'neu'                       => 0,
            $wert !== '' && ctype_digit($wert)    => (int) $wert,
            default                               => null,
        };
    }
}
