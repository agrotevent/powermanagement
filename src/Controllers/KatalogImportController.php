<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\KatalogImport;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Katalog importieren: Datei hochladen oder mitgelieferten Katalog wählen,
 * Vorschau mit Zustand je Typ, Ausgewählte übernehmen.
 *
 * Die Route verlangt „Anlagen ändern“; importieren darf, wer dazu alle
 * Standorte sieht (wie beim Bearbeiten eines Katalog-Typs). Neue
 * Steckertypen prüft KatalogImport::uebernehmen().
 *
 * Das gelesene Paket liegt bis zum Übernehmen unter storage/cache, die
 * Kennung steht in der Sitzung – das Formular schickt nur die Auswahl.
 */
final class KatalogImportController extends Controller
{
    private const SITZUNG = 'katalog_import';
    private const START = '/anlagen/katalog/import';
    private const VORSCHAU = '/anlagen/katalog/import?vorschau=1';

    private function darf(): bool
    {
        if (!KatalogImport::erlaubt()['katalog']) {
            Response::forbidden('Den Katalog importieren darf, wer Anlagen ändern darf und alle Standorte sieht.');
            return false;
        }
        return true;
    }

    /** @return array{kennung:string,paket:array,datei:string}|null die Vorschau dieser Sitzung */
    private function bereit(): ?array
    {
        $v = Session::get(self::SITZUNG);
        if (!is_array($v) || !is_string($v['kennung'] ?? null)) {
            return null;
        }
        $paket = KatalogImport::abgelegt($v['kennung']);
        if ($paket === null) {
            Session::forget(self::SITZUNG);
            return null;
        }
        return ['kennung' => $v['kennung'], 'paket' => $paket, 'datei' => (string) ($v['datei'] ?? '')];
    }

    public function index(Request $request): void
    {
        if (!$this->darf()) {
            return;
        }
        $bereit = $this->bereit();
        if ($bereit !== null && $request->bool('vorschau')) {
            $stand = KatalogImport::pruefen($bereit['paket']);
            if ($stand['ok']) {
                $this->view('anlagen/katalog-vorschau', [
                    'stand'   => $stand,
                    'kennung' => $bereit['kennung'],
                    'datei'   => $bereit['datei'],
                    'erlaubt' => KatalogImport::erlaubt(),
                ]);
                return;
            }
            Flash::error($stand['message']);
        }
        $this->view('anlagen/katalog-import', [
            'mitgeliefert' => KatalogImport::mitgeliefert(),
            'bereit'       => $bereit !== null ? ['datei' => $bereit['datei'], 'quelle' => (string) ($bereit['paket']['quelle'] ?? ''),
                'typen' => count((array) ($bereit['paket']['typen'] ?? []))] : null,
        ]);
    }

    public function vorschau(Request $request): void
    {
        if (!$this->darf()) {
            return;
        }
        $mitgeliefert = $request->str('mitgeliefert');
        if ($mitgeliefert !== '') {
            // Nur ein Name aus der Liste – nie ein Pfad aus der Anfrage.
            $json = KatalogImport::mitgeliefertLesen($mitgeliefert);
            if ($json === null) {
                Flash::error('Diesen mitgelieferten Katalog gibt es nicht.');
                $this->redirect(self::START);
                return;
            }
            $datei = $mitgeliefert;
        } else {
            $hochgeladen = $_FILES['datei'] ?? null;
            $fehler = is_array($hochgeladen) ? (int) ($hochgeladen['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($fehler !== UPLOAD_ERR_OK || !is_uploaded_file((string) $hochgeladen['tmp_name'])) {
                Flash::error(match ($fehler) {
                    UPLOAD_ERR_NO_FILE                       => 'Bitte eine Datei wählen.',
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß (höchstens 5 MB).',
                    UPLOAD_ERR_PARTIAL                       => 'Der Upload wurde abgebrochen.',
                    default                                  => 'Der Upload hat nicht geklappt (Code ' . $fehler . ').',
                });
                $this->redirect(self::START);
                return;
            }
            if ((int) $hochgeladen['size'] > KatalogImport::GROESSE) {
                Flash::error('Die Datei ist größer als 5 MB.');
                $this->redirect(self::START);
                return;
            }
            $json = (string) file_get_contents((string) $hochgeladen['tmp_name']);
            $datei = mb_substr(basename(str_replace('\\', '/', (string) $hochgeladen['name'])), 0, 120);
        }

        $stand = KatalogImport::lesen($json);
        if (!$stand['ok']) {
            Flash::error(($datei !== '' ? $datei . ': ' : '') . $stand['message']);
            $this->redirect(self::START);
            return;
        }
        $alt = Session::get(self::SITZUNG);
        if (is_array($alt) && is_string($alt['kennung'] ?? null)) {
            KatalogImport::verwerfen($alt['kennung']);
        }
        Session::put(self::SITZUNG, ['kennung' => KatalogImport::ablegen((array) $stand['paket']), 'datei' => $datei]);
        $this->redirect(self::VORSCHAU);
    }

    public function uebernehmen(Request $request): void
    {
        if (!$this->darf()) {
            return;
        }
        $bereit = $this->bereit();
        if ($bereit === null || !hash_equals($bereit['kennung'], $request->str('kennung'))) {
            Flash::error('Diese Vorschau ist abgelaufen oder nicht mehr die aktuelle – bitte die Datei noch einmal lesen.');
            $this->redirect(self::START);
            return;
        }
        $neu = $request->arr('neu');
        // „überschreiben“ trägt den Stand, den die Vorschau gezeigt hat
        // („schluessel:stand“) – ersetzt wird nur dieser, keine Korrektur, die
        // jemand seitdem gespeichert hat. In einem Feld je Haken, damit auch
        // 500 Typen unter max_input_vars bleiben.
        $ueberschreiben = [];
        $gesehen = [];
        foreach ($request->arr('ueberschreiben') as $wert) {
            [$schluessel, $stand] = array_pad(explode(':', $wert, 2), 2, '');
            $ueberschreiben[] = $schluessel;
            $gesehen[$schluessel] = $stand;
        }
        if ($neu === [] && $ueberschreiben === []) {
            Flash::warning('Nichts ausgewählt – angehakt wird, was übernommen werden soll.');
            $this->redirect(self::VORSCHAU);
            return;
        }
        $ergebnis = KatalogImport::uebernehmen($bereit['paket'], $neu, $ueberschreiben, null, $gesehen);
        if (!$ergebnis['ok']) {
            Flash::error($ergebnis['message']);
            $this->redirect(self::VORSCHAU);
            return;
        }
        if ($ergebnis['neu'] === [] && $ergebnis['ueberschrieben'] === []) {
            // Nichts geschehen (etwa weil hier inzwischen jemand anderes
            // übernommen oder korrigiert hat): die Vorschau bleibt und zeigt
            // den Stand jetzt – dort lässt sich neu wählen.
            Flash::warning($ergebnis['message']);
            $this->redirect(self::VORSCHAU);
            return;
        }
        KatalogImport::verwerfen($bereit['kennung']);
        Session::forget(self::SITZUNG);
        // Der Katalog markiert beim nächsten Aufruf, was eben dazukam.
        Session::put('katalog_importiert', $ergebnis['ids']);
        Flash::success($ergebnis['message']);
        $this->redirect('/anlagen/katalog');
    }

    public function verwerfen(Request $request): void
    {
        if (!$this->darf()) {
            return;
        }
        $bereit = $this->bereit();
        if ($bereit !== null) {
            KatalogImport::verwerfen($bereit['kennung']);
        }
        Session::forget(self::SITZUNG);
        $this->redirect(self::START);
    }
}
