<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\VorschlagService;
use App\Support\Flash;
use App\Support\Request;
use App\Support\Response;

/**
 * Standorte → Änderungsvorschläge (Seite A): was andere Instanzen über die
 * Schnittstelle zurückgeschickt haben – ansehen, je Etage annehmen oder
 * ablehnen. Direkt Geschriebenes („plaene.schreiben“) steht hier als
 * angenommen, zur Nachvollziehbarkeit.
 *
 * Die Route verlangt „api.vorschlaege“. Ein Vorschlag zeigt ganze Etagen –
 * sehen nur, wer den ganzen Standort sieht (ist er gelöscht: nur mit
 * „alles“). Annehmen braucht dazu „Standorte pflegen“ und eine Freigabe mit
 * „bearbeiten“, Feststrom „Anlagen ändern“ und „Kabel einzeichnen“, neue
 * Steckertypen die Pflege der Steckverbinder (VorschlagService::annehmen).
 * Alles, was von der Gegenseite kommt, geht in den Ansichten nur durch
 * View::e(); eine mitgeschickte Plandatei nur als Download.
 */
final class VorschlaegeController extends Controller
{
    private const ZIEL = '/vorschlaege';

    public function index(Request $request): void
    {
        $this->view('vorschlaege/index', ['vorschlaege' => VorschlagService::alle()]);
    }

    public function vorschlag(Request $request, array $params): void
    {
        $v = $this->vorschlagAus($params);
        if ($v === null) {
            return;
        }
        $siteId = $v['site_id'] !== null && $v['site_name'] !== null ? (int) $v['site_id'] : 0;
        $offen = $v['status'] === VorschlagService::OFFEN;
        $this->view('vorschlaege/vorschlag', [
            'v'            => $v,
            'unterschiede' => $offen ? VorschlagService::unterschiede($v) : null,
            'darf'         => [
                'grundriss' => $siteId > 0 && Auth::can('plans.manage') && Zugriff::darfStandortAendern($siteId),
                'feststrom' => $siteId > 0 && Auth::can('anlagen.manage') && Auth::can('leitungen.manage') && Zugriff::darfAufStandortAnlegen($siteId),
                'stecker'   => Auth::can('stecker.manage'),
                'ablehnen'  => $siteId > 0 ? Zugriff::darfStandortAendern($siteId) : Zugriff::alles(),
            ],
        ]);
    }

    /** Eine mitgeschickte Plandatei – nur als Download, nie im Browser dargestellt. */
    public function datei(Request $request, array $params): void
    {
        $v = $this->vorschlagAus($params);
        if ($v === null) {
            return;
        }
        $pfad = is_string($_GET['pfad'] ?? null) ? (string) $_GET['pfad'] : '';
        $datei = $pfad !== '' ? VorschlagService::datei($v, $pfad) : null;
        $groesse = $datei !== null ? filesize($datei) : false;
        if ($groesse === false) {
            Response::notFound('Diese Datei gehört nicht (mehr) zum Vorschlag.');
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) $groesse);
        // Der Name ist ein Schlüssel samt Endung (plaene/<name>.<endung>, geprüft beim Eingang).
        header('Content-Disposition: attachment; filename="vorschlag-' . (int) $v['id'] . '-' . basename($pfad) . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: no-store');
        readfile($datei);
    }

    public function annehmen(Request $request, array $params): void
    {
        $v = $this->vorschlagAus($params);
        if ($v === null) {
            return;
        }
        $r = VorschlagService::annehmen((int) $v['id'], self::liste('etagen'), self::liste('teile'));
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . (int) $v['id']);
    }

    public function ablehnen(Request $request, array $params): void
    {
        $v = $this->vorschlagAus($params);
        if ($v === null) {
            return;
        }
        $grund = is_string($_POST['grund'] ?? null) ? (string) $_POST['grund'] : '';
        $r = VorschlagService::ablehnen((int) $v['id'], $grund);
        $r['ok'] ? Flash::success($r['message']) : Flash::error($r['message']);
        $this->redirect(self::ZIEL . '/' . (int) $v['id']);
    }

    /** Der Vorschlag aus der Adresse – wenn ihn der Benutzer sehen darf, sonst 404 (und null). */
    private function vorschlagAus(array $params): ?array
    {
        $id = (string) ($params['id'] ?? '');
        $v = ctype_digit($id) && strlen($id) < 12 ? VorschlagService::find((int) $id) : null;
        if ($v === null || !VorschlagService::sichtbar($v)) {
            Response::notFound('Diesen Vorschlag gibt es nicht.');
            return null;
        }
        return $v;
    }

    /** @return list<string> eine Liste aus dem Formular (nur Text) */
    private static function liste(string $feld): array
    {
        $wert = $_POST[$feld] ?? [];
        return is_array($wert) ? array_values(array_map('strval', array_filter($wert, 'is_scalar'))) : [];
    }
}
