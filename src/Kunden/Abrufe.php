<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Berichte\BerichtService;
use App\Support\Audit;
use App\Support\Database;

/**
 * Wer hat welches Dokument im Kundenbereich geladen (Protokoll-PDF,
 * Prüfnachweis) – damit sich nach einer Fehlzuordnung sagen lässt, ob der
 * falsche Kunde das Dokument wirklich hatte (Art. 33 DSGVO).
 *
 * Drei Spuren, je mit eigener Lebensdauer:
 *   kunden_abrufe       Kunde, Konto, Dokument, Prüfsumme, Zeit – ohne IP;
 *                       geht mit dem Dokument bzw. dem Kunden.
 *   audit_log           kundenbereich.abruf {art, ziel_id} mit IP (400 Tage).
 *   bericht_ereignisse  pdf_kunde im Lebenslauf des Berichts – ohne Namen
 *                       (BerichtService::pdfImKundenbereich).
 * Seitenaufrufe werden nicht festgehalten, nur Downloads.
 */
final class Abrufe
{
    public const ARTEN = ['bericht' => 'Protokoll', 'nachweis' => 'Prüfnachweis'];

    /** Einen Abruf des angemeldeten Kundenzugangs festhalten. */
    public static function merken(int $kundeId, string $art, int $zielId, ?string $sha256): void
    {
        if (!isset(self::ARTEN[$art])) {
            throw new \InvalidArgumentException('Unbekannte Art eines Abrufs.');
        }
        $user = Auth::user();
        Database::insert('kunden_abrufe', [
            'kunde_id' => $kundeId,
            'user_id'  => $user['id'] ?? null,
            'username' => mb_substr((string) ($user['username'] ?? ''), 0, 64),
            'art'      => $art,
            'ziel_id'  => $zielId,
            'sha256'   => is_string($sha256) && preg_match('/^[0-9a-f]{64}$/', $sha256) === 1 ? $sha256 : null,
            'am'       => gmdate('c'),
        ]);
        Audit::log('kundenbereich.abruf', $art, (string) $zielId, ['art' => $art, 'ziel_id' => $zielId]);
        if ($art === 'bericht' && isset($user['id'])) {
            BerichtService::pdfImKundenbereich($zielId, (int) $user['id']);
        }
    }

    /**
     * Die letzten Abrufe eines Kunden, neueste zuerst – mit dem Dokument
     * (Nummer des Berichts bzw. Bezeichnung des Nachweises), soweit es noch da ist.
     *
     * @return list<array<string,mixed>>
     */
    public static function fuerKunde(int $kundeId, int $limit = 100): array
    {
        return Database::select(
            "SELECT a.id, a.user_id, a.username, a.art, a.ziel_id, a.sha256, a.am, b.nummer AS bericht_nummer, b.fassung AS bericht_fassung,
                    n.bezeichnung AS nachweis_bezeichnung
             FROM kunden_abrufe a
             LEFT JOIN berichte b ON a.art = 'bericht' AND b.id = a.ziel_id
             LEFT JOIN pruefnachweise n ON a.art = 'nachweis' AND n.id = a.ziel_id
             WHERE a.kunde_id = ? ORDER BY a.am DESC, a.id DESC LIMIT " . max(1, min(500, $limit)),
            [$kundeId]
        );
    }

    /** Der letzte Abruf eines Dokuments (gleich von welchem Kunden) – oder null. */
    public static function letzter(string $art, int $zielId): ?array
    {
        return Database::first('SELECT id, kunde_id, user_id, username, sha256, am FROM kunden_abrufe WHERE art = ? AND ziel_id = ? ORDER BY am DESC, id DESC LIMIT 1',
            [$art, $zielId]);
    }
}
