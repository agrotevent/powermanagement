<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Berichte\BerichtService;
use App\Support\Audit;
use App\Support\Database;

/**
 * Wer hat welches Dokument im Kundenbereich geladen oder angesehen
 * (Protokoll-PDF, Leseansicht eines Protokolls, Prüfnachweis) – damit sich
 * nach einer Fehlzuordnung sagen lässt, ob der falsche Kunde das Dokument
 * wirklich hatte (Art. 33 DSGVO). Die Leseansicht zeigt den ganzen Bericht
 * (Ansprechpartner, Anschrift, Unterschriften) – sie zählt wie ein Download.
 *
 * Drei Spuren, je mit eigener Lebensdauer:
 *   kunden_abrufe       Kunde, Konto, Dokument, Prüfsumme, Zeit – ohne IP;
 *                       geht mit dem Dokument bzw. dem Kunden.
 *   audit_log           kundenbereich.abruf {art, ziel_id} mit IP (400 Tage).
 *   bericht_ereignisse  pdf_kunde bzw. ansicht_kunde im Lebenslauf des
 *                       Berichts – ohne Namen (BerichtService::imKundenbereich).
 * Andere Seitenaufrufe (Listen, Locations) werden nicht festgehalten. Eine
 * Ansicht desselben Protokolls durch denselben Zugang höchstens einmal je
 * Stunde – ein Neuladen ist kein neuer Abruf.
 */
final class Abrufe
{
    public const ARTEN = ['bericht' => 'Protokoll (PDF)', 'ansicht' => 'Protokoll angesehen', 'nachweis' => 'Prüfnachweis (PDF)'];

    /** Die Arten, die einen Übergabebericht meinen (ziel_id = berichte.id). */
    public const BERICHT = ['bericht', 'ansicht'];

    /** So lange zählt eine weitere Ansicht desselben Protokolls durch denselben Zugang nicht neu. */
    private const ANSICHT_SEKUNDEN = 3600;

    /** Einen Abruf des angemeldeten Kundenzugangs festhalten. */
    public static function merken(int $kundeId, string $art, int $zielId, ?string $sha256): void
    {
        if (!isset(self::ARTEN[$art])) {
            throw new \InvalidArgumentException('Unbekannte Art eines Abrufs.');
        }
        $user = Auth::user();
        if ($art === 'ansicht' && Database::first('SELECT id FROM kunden_abrufe WHERE art = ? AND ziel_id = ? AND kunde_id = ? AND user_id = ? AND am >= ?',
            ['ansicht', $zielId, $kundeId, (int) ($user['id'] ?? 0), gmdate('c', time() - self::ANSICHT_SEKUNDEN)]) !== null) {
            return;
        }
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
        if (in_array($art, self::BERICHT, true) && isset($user['id'])) {
            BerichtService::imKundenbereich($zielId, (int) $user['id'], $art === 'bericht');
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
             LEFT JOIN berichte b ON a.art IN ('bericht', 'ansicht') AND b.id = a.ziel_id
             LEFT JOIN pruefnachweise n ON a.art = 'nachweis' AND n.id = a.ziel_id
             WHERE a.kunde_id = ? ORDER BY a.am DESC, a.id DESC LIMIT " . max(1, min(500, $limit)),
            [$kundeId]
        );
    }

    /**
     * Der letzte Abruf eines Dokuments (gleich von welchem Kunden) – oder
     * null. 'bericht' meint beide Arten eines Protokolls: PDF und Ansicht
     * (mit art in der Zeile).
     */
    public static function letzter(string $art, int $zielId): ?array
    {
        $arten = $art === 'bericht' ? self::BERICHT : [$art];
        return Database::first('SELECT id, kunde_id, user_id, username, art, sha256, am FROM kunden_abrufe WHERE art IN ('
            . implode(', ', array_fill(0, count($arten), '?')) . ') AND ziel_id = ? ORDER BY am DESC, id DESC LIMIT 1',
            [...$arten, $zielId]);
    }
}
