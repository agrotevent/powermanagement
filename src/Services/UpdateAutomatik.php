<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Wann ein Update den Betrieb stört.
 *
 * Ein Update startet die Dienste neu: Messung und Schalten sind 1–3 Minuten
 * unterbrochen, die Shellys kurz getrennt. Während eines Aufbaus oder kurz
 * nach einem Schaltvorgang fragt die Oberfläche deshalb nach („Trotzdem
 * jetzt aktualisieren“), auf der Konsole heißt das --trotz-betrieb. Über alle
 * Standorte – ein Update trifft jeden.
 */
final class UpdateAutomatik
{
    /** Geschaltet in den letzten so vielen Sekunden: Betrieb. */
    public const SCHALTEN_ABSTAND = 1800;

    /**
     * @return list<array{art:string, text:string}> aufbau (jeder Aufbau, der nicht beendet ist), geschaltet
     */
    public static function hindernisse(int $jetzt): array
    {
        $liste = [];
        try {
            $aufbauten = Database::select(
                "SELECT a.name, a.status, s.name AS standort FROM aufbauten a LEFT JOIN sites s ON s.id = a.site_id
                  WHERE a.status <> 'beendet' ORDER BY a.gestartet_am, a.id"
            );
            foreach ($aufbauten as $a) {
                $liste[] = ['art' => 'aufbau', 'text' => sprintf(
                    '%s „%s“%s läuft.',
                    $a['status'] === 'betrieb' ? 'Betrieb' : 'Aufbau',
                    (string) $a['name'],
                    (string) ($a['standort'] ?? '') !== '' ? ' (Standort ' . $a['standort'] . ')' : ''
                )];
            }
            $geschaltet = (int) Database::scalar('SELECT COUNT(*) FROM schaltvorgaenge WHERE created_at >= ?', [gmdate('c', $jetzt - self::SCHALTEN_ABSTAND)]);
            if ($geschaltet > 0) {
                $liste[] = ['art' => 'geschaltet', 'text' => 'In den letzten 30 Minuten wurde geschaltet ('
                    . ($geschaltet === 1 ? 'ein Schaltvorgang' : $geschaltet . ' Schaltvorgänge') . ').'];
            }
        } catch (\Throwable) {
            // Tabellen fehlen (vor der Migration) – dann gibt es auch keinen Betrieb.
        }
        return $liste;
    }
}
