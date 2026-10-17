<?php
declare(strict_types=1);

namespace App\Berichte;

use App\Support\Audit;
use App\Support\Database;

/**
 * Prüf- und Messgeräte der Firma (Installationstester, Erdungsmesser) –
 * nicht die Messgeräte im Inventar (Shellys).
 *
 * Ein Bericht nennt die Geräte, mit denen gemessen wurde; beim Einfrieren
 * kommt ein Abbild hinein (Fabrikat, Typ, Nummer, Norm, kalibriert bis).
 * Gelöscht wird ein Gerät nicht, nur stillgelegt (`aktiv = 0`) – sonst
 * wüsste ein Entwurf nicht mehr, womit gemessen wurde.
 */
final class Pruefgeraete
{
    public const NORM = 'DIN EN 61557 (VDE 0413)';

    /** @return list<array<string,mixed>> */
    public static function liste(bool $nurAktive = false): array
    {
        return Database::select('SELECT * FROM pruefgeraete' . ($nurAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY aktiv DESC, fabrikat, typ, seriennummer');
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM pruefgeraete WHERE id = ?', [$id]);
    }

    /**
     * @param array{fabrikat?:string,typ?:string,seriennummer?:string,norm?:string,kalibriert_bis?:string,aktiv?:mixed,notizen?:string} $daten
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $text = static fn (string $feld, int $n): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($daten[$feld] ?? ''))), 0, $n);
        $felder = [
            'fabrikat'       => $text('fabrikat', 80),
            'typ'            => $text('typ', 80),
            'seriennummer'   => $text('seriennummer', 80),
            'norm'           => $text('norm', 160),
            'kalibriert_bis' => trim((string) ($daten['kalibriert_bis'] ?? '')),
            'aktiv'          => !empty($daten['aktiv']) ? 1 : 0,
            'notizen'        => mb_substr(trim((string) ($daten['notizen'] ?? '')), 0, 2000),
        ];
        if ($felder['fabrikat'] === '' || $felder['typ'] === '') {
            return ['ok' => false, 'message' => 'Bitte Fabrikat und Typ angeben.'];
        }
        if ($felder['norm'] === '') {
            $felder['norm'] = self::NORM;
        }
        if ($felder['kalibriert_bis'] !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $felder['kalibriert_bis'], $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return ['ok' => false, 'message' => '„Kalibriert bis“ bitte als Datum angeben.'];
            }
        }
        $name = $felder['fabrikat'] . ' ' . $felder['typ'];
        if ($id !== null) {
            if (self::find($id) === null) {
                return ['ok' => false, 'message' => 'Dieses Prüfgerät gibt es nicht.'];
            }
            Database::update('pruefgeraete', $id, $felder + ['updated_at' => gmdate('c')]);
            Audit::log('pruefgeraet.update', 'pruefgeraet', (string) $id, ['name' => $name]);
            return ['ok' => true, 'message' => $name . ' gespeichert.', 'id' => $id];
        }
        $id = Database::insert('pruefgeraete', $felder + ['created_at' => gmdate('c')]);
        Audit::log('pruefgeraet.create', 'pruefgeraet', (string) $id, ['name' => $name]);
        return ['ok' => true, 'message' => $name . ' angelegt.', 'id' => $id];
    }

    /** Ist die Kalibrierung am Prüfdatum abgelaufen? Ohne Datum: nein (nicht bekannt). */
    public static function abgelaufen(array $g, string $datum): bool
    {
        $bis = (string) ($g['kalibriert_bis'] ?? '');
        return $bis !== '' && $datum !== '' && $bis < $datum;
    }

    /** „Fluke 1664 FC, Nr. 123, kalibriert bis 31.12.2026“ */
    public static function text(array $g): string
    {
        $teile = [trim($g['fabrikat'] . ' ' . $g['typ'])];
        if ((string) $g['seriennummer'] !== '') {
            $teile[] = 'Nr. ' . $g['seriennummer'];
        }
        if ((string) $g['kalibriert_bis'] !== '') {
            $teile[] = 'kalibriert bis ' . date('d.m.Y', (int) strtotime($g['kalibriert_bis'] . ' 12:00:00'));
        }
        return implode(', ', $teile);
    }
}
