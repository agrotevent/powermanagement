<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\Konten;
use App\Auth\Sso\Schutz;
use App\Kunden\Kundenbereich;
use App\Messung\Datenquellen;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

/**
 * Benutzer, Rollen und Freigaben des Personals. Kundenzugänge (users.kunde_id)
 * erscheinen hier nicht und lassen sich hier nicht ändern – das macht die
 * Kundenverwaltung.
 */
final class BenutzerController extends Controller
{
    public function index(Request $request): void
    {
        $bearbeiten = $request->int('id');
        $benutzer = $bearbeiten > 0 ? BenutzerService::find($bearbeiten) : null;
        if ($benutzer !== null && Kundenbereich::istKundenzugang($benutzer)) {
            Flash::info(BenutzerService::KUNDENZUGANG);
            $benutzer = null;
        }
        $zuweisungen = [];
        if ($benutzer !== null) {
            foreach (Database::select('SELECT * FROM user_roles WHERE user_id = ?', [$bearbeiten]) as $z) {
                $zuweisungen[(int) $z['role_id']] = $z;
            }
        }
        // Anmeldung über SSO: Abzeichen in der Liste, Abschnitt im Formular –
        // der Abschnitt auch ohne Einrichtung, wenn das Konto schon verknüpft
        // ist oder nur über SSO darf (sonst ließe es sich nicht mehr lösen).
        $nurSsoGlobal = Einstellungen::schalter(Einstellungen::NUR_SSO) && Einstellungen::aktiv();
        $identitaet = $benutzer !== null
            ? Database::first('SELECT weg, name_dort, zuletzt_am, angelegt_am, aussteller FROM sso_identitaeten WHERE user_id = ? ORDER BY id LIMIT 1', [$bearbeiten])
            : null;
        $this->view('benutzer/index', [
            'alle'        => BenutzerService::alle(),
            'kundenzugaenge' => BenutzerService::kundenzugaenge(),
            'rollen'      => BenutzerService::rollen(),
            'benutzer'    => $benutzer,
            'neu'         => $request->bool('neu'),
            'zuweisungen' => $zuweisungen,
            'sso'         => [
                'eingerichtet' => Einstellungen::eingerichtet(),
                'aktiv'        => Einstellungen::aktiv(),
                'name'         => Einstellungen::name(),
                'nur_sso'      => $nurSsoGlobal,
                // Notzugang ist abgeleitet und heißt nur bei „nur SSO“ so.
                'notzugaenge'  => $nurSsoGlobal ? array_map('intval', array_column(Schutz::notzugaenge(), 'id')) : [],
                'anfragen'     => Auth::can('sso.manage') ? (int) Database::scalar('SELECT COUNT(*) FROM sso_anfragen') : 0,
            ],
            'identitaet'  => $identitaet === null ? null : $identitaet + [
                'anderer_aussteller' => !hash_equals(Einstellungen::aussteller(), (string) $identitaet['aussteller']),
            ],
        ]);
    }

    /** Die Meldung, wenn ein Konto mehr Rechte hat als der, der es ändern will. */
    private const UEBER_MIR = 'Dieses Konto hat Rechte, die du selbst nicht hast – ändern, sperren und löschen darf es nur, wer sie alle hat.';

    public function speichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $rollen = (array) ($_POST['rollen'] ?? []);
        $ergebnis = null;
        if ($id !== null && BenutzerService::istKundenzugang($id)) {
            $ergebnis = ['ok' => false, 'message' => BenutzerService::KUNDENZUGANG];
        } elseif ($id !== null && !BenutzerService::darfVerwalten($id)) {
            $ergebnis = ['ok' => false, 'message' => self::UEBER_MIR];
        }
        foreach ($rollen as $rolleId => $zuweisung) {
            if ($ergebnis !== null || empty($zuweisung['aktiv'])) {
                continue;
            }
            $rolle = Database::first('SELECT name FROM roles WHERE id = ?', [(int) $rolleId]);
            if ($rolle === null) {
                $ergebnis = ['ok' => false, 'message' => 'Diese Rolle gibt es nicht.'];
            } elseif (!BenutzerService::darfVergeben(BenutzerService::rollenRechte((int) $rolleId))) {
                $ergebnis = ['ok' => false, 'message' => 'Die Rolle „' . $rolle['name'] . '“ hat Rechte, die du selbst nicht hast – vergeben darf sie nur, wer sie alle hat.'];
            }
        }
        $ergebnis ??= BenutzerService::speichern($id, $_POST, $rollen);
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ergebnis['ok'] ? '/system/benutzer' : Response::zurueckZiel('/system/benutzer'));
    }

    public function loeschen(Request $request): void
    {
        $id = $request->int('id');
        $ergebnis = match (true) {
            BenutzerService::istKundenzugang($id) => ['ok' => false, 'message' => BenutzerService::KUNDENZUGANG],
            !BenutzerService::darfVerwalten($id)  => ['ok' => false, 'message' => self::UEBER_MIR],
            default                               => BenutzerService::loeschen($id),
        };
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/benutzer');
    }

    /**
     * POST /system/benutzer/{id}/sso – Anmeldung über SSO an einem Konto des
     * Personals: aktion=loesen (Verknüpfung lösen; seine SSO-Sitzungen und
     * Rollen aus Gruppen enden) oder aktion=sitzungen (nur die laufenden
     * SSO-Sitzungen beenden). Nur, wer das Konto verwalten darf
     * (darfVerwalten); Kundenzugänge pflegt die Kundenverwaltung. zurueck=sso
     * führt zurück zu System → Anmeldung (SSO), sonst zum Konto.
     */
    public function sso(Request $request, array $params): void
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $aktion = is_string($_POST['aktion'] ?? null) ? $_POST['aktion'] : '';
        $ziel = ($_POST['zurueck'] ?? null) === 'sso' ? '/system/sso#verknuepft' : '/system/benutzer?id=' . $id;
        $konto = BenutzerService::find($id);
        $name = Einstellungen::name();
        $ergebnis = match (true) {
            $konto === null                       => ['ok' => false, 'message' => 'Diesen Benutzer gibt es nicht.'],
            Kundenbereich::istKundenzugang($konto) => ['ok' => false, 'message' => BenutzerService::KUNDENZUGANG],
            !BenutzerService::darfVerwalten($id)  => ['ok' => false, 'message' => self::UEBER_MIR],
            Database::scalar('SELECT id FROM sso_identitaeten WHERE user_id = ?', [$id]) === false
                => ['ok' => false, 'message' => 'Dieses Konto ist mit keiner Anmeldung bei ' . $name . ' verknüpft.'],
            $aktion === 'loesen' => Konten::loesen($id)
                ? ['ok' => true, 'message' => 'Verknüpfung von „' . $konto['username'] . '“ gelöst; seine Sitzungen über ' . $name . ' sind beendet.'
                    . ((int) $konto['nur_sso'] === 1 ? ' Das Konto meldet sich nur über SSO an – ohne Verknüpfung erst wieder mit einem Kennwort (Häkchen „nur über SSO“ aus).' : '')]
                : ['ok' => false, 'message' => 'Es gab nichts zu lösen.'],
            $aktion === 'sitzungen' => (static function () use ($id, $konto, $name): array {
                Konten::sitzungenBeenden($id);
                return ['ok' => true, 'message' => 'Die Sitzungen von „' . $konto['username'] . '“ über ' . $name . ' sind beendet.'];
            })(),
            default => ['ok' => false, 'message' => 'Unbekannte Aktion.'],
        };
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($ziel);
    }

    public function entsperren(Request $request): void
    {
        $id = $request->int('id');
        $ergebnis = match (true) {
            BenutzerService::istKundenzugang($id) => ['ok' => false, 'message' => BenutzerService::KUNDENZUGANG],
            !BenutzerService::darfVerwalten($id)  => ['ok' => false, 'message' => self::UEBER_MIR],
            default                               => BenutzerService::entsperren($id),
        };
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/benutzer');
    }

    public function freigaben(Request $request): void
    {
        $art = $request->str('art') === 'role' ? 'role' : 'user';
        $id = $request->int('id');
        $subjekt = $art === 'role'
            ? Database::first('SELECT id, name AS titel FROM roles WHERE id = ?', [$id])
            : Database::first("SELECT id, username AS titel, full_name, gueltig_ab, gueltig_bis, kunde_id FROM users WHERE id = ?", [$id]);
        if ($subjekt === null) {
            Response::notFound();
            return;
        }
        $this->view('benutzer/freigaben', [
            'art'       => $art,
            'subjekt'   => $subjekt,
            // Ein Kundenzugang sieht nur Freigaben an seinem Kunden: hier nur ein Hinweis, kein Formular.
            'kundenzugang' => $art === 'user' && Kundenbereich::istKundenzugang($subjekt),
            'freigaben' => BenutzerService::freigaben($art, $id),
            'standorte' => SiteService::all(),
            'objekte'   => AnlagenService::liste(),
            'geraete'   => Datenquellen::alle(),
        ]);
    }

    /**
     * Darf der angemeldete Benutzer die Freigaben dieses Kontos bzw. dieser
     * Rolle ändern? Nur Benutzer und Rollen – Kunden-Freigaben und
     * Kundenzugänge pflegt die Kundenverwaltung.
     */
    private function darfFreigabenAendern(string $art, int $id): bool
    {
        return match ($art) {
            'role'  => BenutzerService::darfVergeben(BenutzerService::rollenRechte($id)),
            'user'  => !BenutzerService::istKundenzugang($id) && BenutzerService::darfVerwalten($id),
            default => false,
        };
    }

    public function freigabeSpeichern(Request $request): void
    {
        $art = $request->str('subject_type');
        $ergebnis = match (true) {
            !in_array($art, ['user', 'role'], true)
                => ['ok' => false, 'message' => 'Freigaben gelten für einen Benutzer oder eine Rolle.'],
            $art === 'user' && BenutzerService::istKundenzugang($request->int('subject_id'))
                => ['ok' => false, 'message' => BenutzerService::KUNDENZUGANG],
            !$this->darfFreigabenAendern($art, $request->int('subject_id'))
                => ['ok' => false, 'message' => $art === 'role' ? 'Diese Rolle hat Rechte, die du selbst nicht hast.' : self::UEBER_MIR],
            !BenutzerService::darfFreigabeVergeben($request->str('ziel_art'), $request->bool('darf_steuern'))
                => ['ok' => false, 'message' => 'Freigaben auf alles oder mit „schalten“ vergibt nur die Administration, andere nur, wer selbst alle Standorte sieht.'],
            default => BenutzerService::freigabeSpeichern($_POST),
        };
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/freigaben?art=' . ($art === 'role' ? 'role' : 'user') . '&id=' . $request->int('subject_id'));
    }

    public function freigabeLoeschen(Request $request): void
    {
        $f = Database::first('SELECT * FROM freigaben WHERE id = ?', [$request->int('id')]);
        // Nur Freigaben an Benutzern und Rollen – eine Kunden-Freigabe gibt es hier nicht.
        if ($f !== null && !in_array((string) $f['subject_type'], ['user', 'role'], true)) {
            Response::notFound();
            return;
        }
        $ergebnis = $f !== null && (!$this->darfFreigabenAendern((string) $f['subject_type'], (int) $f['subject_id'])
            || !BenutzerService::darfFreigabeVergeben((string) $f['ziel_art'], (int) $f['darf_steuern'] === 1))
            ? ['ok' => false, 'message' => 'Diese Freigabe darf nur entfernen, wer sie auch vergeben dürfte.']
            : BenutzerService::freigabeLoeschen($request->int('id'));
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect($f !== null ? '/system/freigaben?art=' . $f['subject_type'] . '&id=' . (int) $f['subject_id'] : '/system/benutzer');
    }

    public function rollen(Request $request): void
    {
        $this->view('benutzer/rollen', ['rollen' => BenutzerService::rollen(), 'bearbeiten' => $request->int('id')]);
    }

    public function rolleSpeichern(Request $request): void
    {
        $id = $request->int('id') ?: null;
        $ergebnis = match (true) {
            $id !== null && !BenutzerService::darfVergeben(BenutzerService::rollenRechte($id))
                => ['ok' => false, 'message' => 'Diese Rolle hat Rechte, die du selbst nicht hast – ändern darf sie nur, wer sie alle hat.'],
            !BenutzerService::darfVergeben($request->arr('rechte'))
                => ['ok' => false, 'message' => 'Rechte vergeben darfst du nur, wenn du sie selbst hast.'],
            default => BenutzerService::rolleSpeichern($id, $request->str('name'), $request->str('description'), $request->arr('rechte')),
        };
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/rollen');
    }

    public function rolleLoeschen(Request $request): void
    {
        $ergebnis = BenutzerService::darfVergeben(BenutzerService::rollenRechte($request->int('id')))
            ? BenutzerService::rolleLoeschen($request->int('id'))
            : ['ok' => false, 'message' => 'Diese Rolle hat Rechte, die du selbst nicht hast – löschen darf sie nur, wer sie alle hat.'];
        $ergebnis['ok'] ? Flash::success($ergebnis['message']) : Flash::error($ergebnis['message']);
        $this->redirect('/system/rollen');
    }
}
