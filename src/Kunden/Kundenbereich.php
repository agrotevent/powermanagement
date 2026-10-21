<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Auth;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Tor und Zugang des Kundenbereichs: wer ist Kundenzugang, wohin darf er,
 * ist sein Kunde aktiv, welche Freigaben wirken.
 *
 * Ein Kundenzugang ist ein Konto mit users.kunde_id – erkannt am Konto, nie
 * über ein Recht („*“ erfüllte jedes). Er hat keine Rolle und keine Rechte
 * (Auth::permissions() = []) und erreicht nur den Kundenbereich: das Tor im
 * Router lässt außer PFAD nur die offenen Wege durch, vor jeder
 * Rechteprüfung – auch Routen ohne Recht (/, /profil) und jede künftige
 * Route sind für Kunden damit von selbst zu.
 *
 * Nicht zu verwechseln mit dem Kundenmodus der Übergabeberichte
 * (App\Berichte\Kundenmodus): dort unterschreibt der Kunde am Gerät des
 * Prüfers, angemeldet ist der Prüfer. Hier meldet sich der Kunde mit seinem
 * eigenen Zugang an.
 *
 * Was ein Kundenzugang an Standorten sieht, kommt nur aus Freigaben an
 * seinem Kunden (freigaben.subject_type = 'kunde') – nie aus Freigaben am
 * Konto oder an Rollen (eine Freigabe an der Rolle „kunde“ gälte sonst für
 * alle Kunden). freigaben() ist dafür das eine Prädikat: für das Tor, für
 * Zugriff und für die Anzeige „Der Kunde sieht derzeit …“ beim Personal.
 */
final class Kundenbereich
{
    public const PFAD = '/kundenbereich';
    /** Wohin ein Kundenzugang mit vorläufigem Kennwort muss. */
    public const KONTO = '/kundenbereich/konto';
    public const NICHT_AKTIV = 'Dieser Kundenzugang ist nicht mehr aktiv.';

    /** Außer dem Kundenbereich offen: anmelden, abmelden, Web-App-Manifest. */
    private const OFFEN = ['/login', '/logout', '/manifest'];

    /** @var array<int,?string> Sperre je Kunde in dieser Anfrage */
    private static array $sperren = [];
    /** @var array<int,list<array<string,mixed>>> wirksame Freigaben je Kunde in dieser Anfrage */
    private static array $freigaben = [];
    /** @var array<int,?array<string,mixed>> Kunden in dieser Anfrage */
    private static array $kunden = [];

    /** Kunde des Kontos ($user, sonst des angemeldeten) – oder null für Personal. */
    public static function kundeId(?array $user = null): ?int
    {
        $user ??= Auth::user();
        $id = (int) ($user['kunde_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    public static function istKundenzugang(?array $user = null): bool
    {
        return self::kundeId($user) !== null;
    }

    /**
     * Liegt der Pfad im Kundenbereich oder ist er einer der offenen Wege?
     *
     * $pfad roh, wie Request::path() ihn liefert – derselbe String, auf dem
     * der Router die Routen sucht. „/kundenbereich/../system/benutzer“ geht
     * hier durch, passt aber auf keine Route (404).
     */
    public static function erlaubt(string $pfad): bool
    {
        return $pfad === self::PFAD || str_starts_with($pfad, self::PFAD . '/') || in_array($pfad, self::OFFEN, true);
    }

    /**
     * Darf dieses Konto jetzt angemeldet sein, soweit es um den Kunden geht?
     * null = ja (Personal immer); sonst NICHT_AKTIV. Den Zeitraum des Kontos
     * selbst prüft Auth::kontoZeitraum – beides zusammen Auth::zugangSperre.
     */
    public static function sperre(array $user, ?int $jetzt = null): ?string
    {
        $kundeId = self::kundeId($user);
        return $kundeId === null ? null : self::kundeSperre($kundeId, $jetzt);
    }

    /**
     * Ist der Kunde aktiv und noch gültig? null = ja, sonst NICHT_AKTIV –
     * ein gelöschter Kunde ebenso. Ohne eigene Uhrzeit je Anfrage gemerkt.
     */
    public static function kundeSperre(int $kundeId, ?int $jetzt = null): ?string
    {
        if ($jetzt === null && array_key_exists($kundeId, self::$sperren)) {
            return self::$sperren[$kundeId];
        }
        $kunde = Database::first('SELECT id, aktiv, gueltig_bis FROM kunden WHERE id = ?', [$kundeId]);
        $bis = Zeit::ts($kunde['gueltig_bis'] ?? null);
        $sperre = $kunde === null || (int) $kunde['aktiv'] !== 1 || ($bis !== null && ($jetzt ?? time()) > $bis)
            ? self::NICHT_AKTIV
            : null;
        if ($jetzt === null) {
            self::$sperren[$kundeId] = $sperre;
        }
        return $sperre;
    }

    /** Der Kunde des angemeldeten Zugangs – nur, solange er aktiv ist; sonst null. */
    public static function kunde(): ?array
    {
        $kundeId = self::kundeId();
        if ($kundeId === null || self::kundeSperre($kundeId) !== null) {
            return null;
        }
        if (!array_key_exists($kundeId, self::$kunden)) {
            self::$kunden[$kundeId] = Database::first('SELECT * FROM kunden WHERE id = ?', [$kundeId]);
        }
        return self::$kunden[$kundeId];
    }

    /**
     * Die jetzt wirksamen Freigaben eines Kunden – DAS Prädikat für den
     * Kundenbereich, für Zugriff (Kundenzugänge) und für die Anzeige beim
     * Personal.
     *
     * Nur Standorte (ziel_art 'site'); „bearbeiten“, „schalten“ und ein Kanal
     * gelten nie, auch wenn sie per SQL gesetzt wären. Fehlt ein Datenende,
     * ist der Datenzeitraum leer (daten_ab = daten_bis) – nie „ohne Grenze“:
     * Zugriff::vereinigen() machte aus einem offenen Fenster die ganze
     * Messgeschichte des Geländes, auch die der anderen Veranstalter.
     * Kunde gesperrt → []. Mit eigener Uhrzeit (Vorschau, Prüfskripte) nicht
     * zwischengespeichert.
     *
     * @return list<array<string,mixed>>
     */
    public static function freigaben(int $kundeId, ?int $jetzt = null): array
    {
        if (self::kundeSperre($kundeId, $jetzt) !== null) {
            return [];
        }
        $eigeneZeit = $jetzt !== null;
        if (!$eigeneZeit && isset(self::$freigaben[$kundeId])) {
            return self::$freigaben[$kundeId];
        }
        $jetzt ??= time();
        $wirksam = [];
        foreach (self::zeilen($kundeId) as $f) {
            if (!Zeit::imFenster($jetzt, $f['gueltig_ab'], $f['gueltig_bis'])) {
                continue;
            }
            $wirksam[] = self::entschaerfen($f, $jetzt);
        }
        return $eigeneZeit ? $wirksam : (self::$freigaben[$kundeId] = $wirksam);
    }

    /**
     * Kunden-Freigaben, die erst noch gelten werden (gueltig_ab in der
     * Zukunft) – für „ab … sichtbar“. Kunde gesperrt → [].
     *
     * @return list<array<string,mixed>>
     */
    public static function kommend(int $kundeId, ?int $jetzt = null): array
    {
        if (self::kundeSperre($kundeId, $jetzt) !== null) {
            return [];
        }
        $jetzt ??= time();
        $kommend = [];
        foreach (self::zeilen($kundeId) as $f) {
            $ab = Zeit::ts($f['gueltig_ab']);
            if ($ab !== null && $ab > $jetzt) {
                $kommend[] = self::entschaerfen($f, $jetzt);
            }
        }
        return $kommend;
    }

    /** Für Prüfskripte und nach Änderungen an Kunden oder Freigaben. */
    public static function vergessen(): void
    {
        self::$sperren = [];
        self::$freigaben = [];
        self::$kunden = [];
    }

    // ------------------------------------------------------------ intern

    /** @return list<array<string,mixed>> alle Kunden-Freigaben auf Standorte */
    private static function zeilen(int $kundeId): array
    {
        return Database::select(
            "SELECT * FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ? AND ziel_art = 'site' ORDER BY id",
            [$kundeId]
        );
    }

    /** Eine Kunden-Freigabe kann nur sehen – und nie Messdaten ohne Grenze. */
    private static function entschaerfen(array $f, int $jetzt): array
    {
        $f['darf_bearbeiten'] = 0;
        $f['darf_steuern'] = 0;
        $f['ziel_kanal'] = '';
        if ($f['daten_ab'] === null || $f['daten_ab'] === '' || $f['daten_bis'] === null || $f['daten_bis'] === '') {
            $f['daten_ab'] = $f['daten_bis'] = ($f['gueltig_ab'] ?? '') !== '' ? $f['gueltig_ab'] : Zeit::iso($jetzt);
        }
        return $f;
    }
}
