<?php
declare(strict_types=1);

namespace App\Auth\Sso;

use App\Kunden\KundenService;
use App\Kunden\Kundenbereich as Kunden;
use App\Support\Database;

/**
 * Die Hülle der Anmeldung über SSO um den Kundenbereich (016).
 *
 * Alles, was SSO über Kunden wissen muss, geht hier durch – so hängt 017
 * nur an diesen fünf Methoden und nicht an den Klassen des Kundenbereichs.
 * Kundenzugänge per SSO gibt es nur mit dem Schalter `sso.kunden` (Vorgabe
 * aus); ohne ihn melden sich Kundenzugänge wie bisher mit Benutzername und
 * Kennwort an. Ein Kundenzugang aus SSO hat wie jeder andere keine Rolle,
 * keine Rechte und keine Freigabe am Konto – er sieht, was seinem Kunden
 * freigegeben ist.
 *
 * Nicht zu verwechseln mit App\Kunden\Kundenbereich (Tor und Freigaben des
 * Kundenbereichs) – nur der Namensraum trennt die beiden.
 */
final class Kundenbereich
{
    /** Ist der Kundenbereich (016) da? */
    public static function verfuegbar(): bool
    {
        return class_exists(KundenService::class);
    }

    /** Ein Kundenzugang (users.kunde_id) – erkannt am Konto, nie über ein Recht. */
    public static function istKundenzugang(array $user): bool
    {
        return self::verfuegbar() ? Kunden::istKundenzugang($user) : (int) ($user['kunde_id'] ?? 0) > 0;
    }

    /** Ein aktiver, noch gültiger Kunde – sonst null (auch, wenn es ihn nicht gibt). */
    public static function kunde(int $id): ?array
    {
        if (!self::verfuegbar() || $id <= 0 || Kunden::kundeSperre($id) !== null) {
            return null;
        }
        return KundenService::find($id);
    }

    /** @return list<array{id:int,name:string}> aktive Kunden für die Auswahl einer Kundengruppe */
    public static function alle(): array
    {
        if (!self::verfuegbar()) {
            return [];
        }
        $out = [];
        foreach (KundenService::alle(['aktiv' => '1']) as $k) {
            $out[] = ['id' => (int) $k['id'], 'name' => (string) $k['name']];
        }
        return $out;
    }

    /**
     * Einen Kundenzugang für SSO anlegen: ohne Rolle, ohne Freigabe, ohne
     * nutzbares Kennwort (Zufalls-Hash) und mit nur_sso = 1 – also auch ohne
     * Einrichtungslink. Ohne Prüfung und ohne Audit (Konten::aufloesen).
     *
     * @param array{username:string, full_name:string, email:string, notizen:string} $felder
     */
    public static function zugangAnlegen(array $felder, int $kundeId): int
    {
        if (!self::verfuegbar()) {
            throw new \LogicException('SSO: ohne Kundenbereich gibt es keine Kundenzugänge.');
        }
        $id = KundenService::kontoErzeugen($kundeId, $felder['username'], $felder['full_name'], $felder['email'],
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), false);
        Database::update('users', $id, ['nur_sso' => 1, 'notizen' => $felder['notizen']]);
        KundenService::vergessen();
        return $id;
    }
}
