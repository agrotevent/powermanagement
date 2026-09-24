<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Database;
use App\Support\Zeit;

/**
 * Wer sieht welche Anlage, welche Messdaten – und wann.
 *
 * Grundsatz: Ohne `anlagen.scope.all` sieht ein Benutzer nur, was ihm über
 * eine **Freigabe** zugeteilt ist – direkt oder über eine seiner Rollen.
 * Jede Freigabe hat zwei Zeitfenster:
 *
 *   Gültigkeit     Die Freigabe wirkt nur zwischen gueltig_ab und
 *                  gueltig_bis. Außerhalb ist sie, als gäbe es sie nicht –
 *                  die Anlage verschwindet aus Liste, Karte und Statistik.
 *
 *   Datenzeitraum  Innerhalb der Gültigkeit sind nur Messwerte zwischen
 *                  daten_ab und daten_bis zu sehen. Jede Abfrage an die
 *                  InfluxDB wird darauf zugeschnitten (begrenzen()), nicht
 *                  erst die Anzeige – was nicht abgefragt wird, kann auch
 *                  nicht durchsickern.
 *
 * Mehrere Freigaben auf dieselbe Anlage ergänzen sich: die Datenzeiträume
 * werden vereinigt, „bearbeiten“ und „schalten“ genügt in einer davon.
 *
 * Schalten hängt zusätzlich am Recht `steuern` und lässt sich bis auf ein
 * einzelnes Gerät (Datenquelle) und dort einen einzelnen Kanal vergeben.
 */
final class Zugriff
{
    /** @var array<int,array<int,array<string,mixed>>> je Benutzer die wirksamen Freigaben */
    private static array $cache = [];

    /** @var array<int,list<array<string,mixed>>> Messpunkte je Gerät (Kennung → Objekt-IDs) */
    private static ?array $geraeteObjekte = null;

    public static function vergessen(): void
    {
        self::$cache = [];
        self::$geraeteObjekte = null;
    }

    /** Sieht der angemeldete Benutzer alles, ohne Zeitgrenze? */
    public static function alles(): bool
    {
        return Auth::can('anlagen.scope.all');
    }

    /**
     * Die jetzt wirksamen Freigaben eines Benutzers – eigene und die seiner
     * jetzt gültigen Rollen.
     *
     * @return list<array<string,mixed>>
     */
    public static function freigaben(?int $userId = null, ?int $jetzt = null): array
    {
        $userId ??= Auth::id();
        if ($userId === null) {
            return [];
        }
        // Mit eigener Uhrzeit (Prüfskripte, Vorschau „wie sieht er es am …“)
        // wird nicht zwischengespeichert – sonst gälte die Vorschau weiter.
        $eigeneZeit = $jetzt !== null;
        $jetzt ??= time();
        if ($eigeneZeit || !isset(self::$cache[$userId])) {
            $rollen = [];
            foreach (Database::select('SELECT role_id, gueltig_ab, gueltig_bis FROM user_roles WHERE user_id = ?', [$userId]) as $zuweisung) {
                if (Zeit::imFenster($jetzt, $zuweisung['gueltig_ab'], $zuweisung['gueltig_bis'])) {
                    $rollen[] = (int) $zuweisung['role_id'];
                }
            }
            $sql = "SELECT * FROM freigaben WHERE (subject_type = 'user' AND subject_id = ?)";
            $params = [$userId];
            if ($rollen !== []) {
                $sql .= " OR (subject_type = 'role' AND subject_id IN (" . implode(',', array_fill(0, count($rollen), '?')) . '))';
                $params = array_merge($params, $rollen);
            }
            $wirksam = [];
            foreach (Database::select($sql, $params) as $freigabe) {
                if (Zeit::imFenster($jetzt, $freigabe['gueltig_ab'], $freigabe['gueltig_bis'])) {
                    $wirksam[] = $freigabe;
                }
            }
            if ($eigeneZeit) {
                return $wirksam;
            }
            self::$cache[$userId] = $wirksam;
        }
        return self::$cache[$userId];
    }

    // ------------------------------------------------------------ Abdeckung

    /** Deckt die Freigabe diese Anlage ab? (Gerätefreigaben tun das nicht.) */
    private static function decktObjekt(array $freigabe, array $objekt): bool
    {
        return match ((string) $freigabe['ziel_art']) {
            'alles'  => true,
            'site'   => $objekt['site_id'] !== null && (int) $freigabe['ziel_id'] === (int) $objekt['site_id'],
            'objekt' => (int) $freigabe['ziel_id'] === (int) $objekt['id'],
            default  => false,
        };
    }

    /** Deckt die Freigabe dieses Gerät (Datenquelle) ab – ggf. nur einen Kanal? */
    private static function decktGeraet(array $freigabe, int $datenquelleId, string $kanal = ''): bool
    {
        if ((string) $freigabe['ziel_art'] === 'alles') {
            return true;
        }
        if ((string) $freigabe['ziel_art'] !== 'geraet' || (int) $freigabe['ziel_id'] !== $datenquelleId) {
            return false;
        }
        $nurKanal = (string) ($freigabe['ziel_kanal'] ?? '');
        return $nurKanal === '' || $kanal === '' || $nurKanal === $kanal;
    }

    // ------------------------------------------------------------ Sehen

    public static function siehtObjekt(array $objekt): bool
    {
        if (self::alles()) {
            return true;
        }
        foreach (self::freigaben() as $freigabe) {
            if (self::decktObjekt($freigabe, $objekt)) {
                return true;
            }
        }
        return false;
    }

    public static function siehtStandort(int $siteId): bool
    {
        if (self::alles()) {
            return true;
        }
        foreach (self::freigaben() as $freigabe) {
            $art = (string) $freigabe['ziel_art'];
            if ($art === 'alles' || ($art === 'site' && (int) $freigabe['ziel_id'] === $siteId)) {
                return true;
            }
        }
        // Wer nur eine einzelne Anlage sieht, sieht auch den Standort, auf
        // dem sie steht – sonst fände er sie auf der Karte nicht.
        return in_array($siteId, self::standorteEinzelnerObjekte(), true);
    }

    /** @return list<int> Standorte, auf denen einzeln freigegebene Anlagen stehen */
    private static function standorteEinzelnerObjekte(): array
    {
        $ids = [];
        foreach (self::freigaben() as $freigabe) {
            if ((string) $freigabe['ziel_art'] === 'objekt') {
                $ids[] = (int) $freigabe['ziel_id'];
            }
        }
        if ($ids === []) {
            return [];
        }
        $rows = Database::select(
            'SELECT DISTINCT site_id FROM objekte WHERE site_id IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        return array_map(static fn ($r) => (int) $r['site_id'], $rows);
    }

    /**
     * Anlagen auf die sichtbaren einschränken.
     *
     * @param list<array<string,mixed>> $objekte
     * @return list<array<string,mixed>>
     */
    public static function filtern(array $objekte): array
    {
        if (self::alles()) {
            return $objekte;
        }
        return array_values(array_filter($objekte, static fn (array $o): bool => self::siehtObjekt($o)));
    }

    /**
     * Standorte auf die sichtbaren einschränken.
     *
     * @param list<array<string,mixed>> $sites
     * @return list<array<string,mixed>>
     */
    public static function standorteFiltern(array $sites): array
    {
        if (self::alles()) {
            return $sites;
        }
        return array_values(array_filter($sites, static fn (array $s): bool => self::siehtStandort((int) $s['id'])));
    }

    /** Sieht der Benutzer dieses Gerät (Datenquelle) – direkt oder über eine Anlage? */
    public static function siehtGeraet(array $datenquelle): bool
    {
        if (self::alles() || Auth::can('datenquellen.view')) {
            return true;
        }
        foreach (self::freigaben() as $freigabe) {
            if (self::decktGeraet($freigabe, (int) $datenquelle['id'])) {
                return true;
            }
        }
        foreach (self::objekteZuGeraet((string) $datenquelle['geraet']) as $objekt) {
            if (self::siehtObjekt($objekt)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------ Ändern

    public static function darfBearbeiten(array $objekt): bool
    {
        if (!Auth::can('anlagen.manage')) {
            return false;
        }
        if (self::alles()) {
            return true;
        }
        foreach (self::freigaben() as $freigabe) {
            if ((int) $freigabe['darf_bearbeiten'] === 1 && self::decktObjekt($freigabe, $objekt)) {
                return true;
            }
        }
        return false;
    }

    /** Neue Anlagen auf einem Standort anlegen? */
    public static function darfAufStandortAnlegen(?int $siteId): bool
    {
        if (!Auth::can('anlagen.manage')) {
            return false;
        }
        if (self::alles()) {
            return true;
        }
        foreach (self::freigaben() as $freigabe) {
            if ((int) $freigabe['darf_bearbeiten'] !== 1) {
                continue;
            }
            $art = (string) $freigabe['ziel_art'];
            if ($art === 'alles' || ($art === 'site' && $siteId !== null && (int) $freigabe['ziel_id'] === $siteId)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------ Schalten

    /**
     * Darf der Benutzer diesen Kanal dieses Geräts schalten?
     *
     * Drei Bedingungen, alle nötig:
     *   1. das Recht `steuern`,
     *   2. an der Anlage ist die Steuerung nicht gesperrt,
     *   3. eine wirksame Freigabe mit „schalten“ – für alles, den Standort,
     *      die Anlage oder genau dieses Gerät (und ggf. diesen Kanal).
     *
     * `anlagen.scope.all` ersetzt nur die dritte Bedingung, nicht die
     * anderen: auch wer alles sieht, schaltet nur mit dem Recht dazu.
     *
     * @param array<string,mixed>|null $objekt  die Anlage, an der das Gerät hängt (falls bekannt)
     */
    public static function darfSteuern(array $datenquelle, string $kanal = '', ?array $objekt = null): bool
    {
        if (!Auth::can('steuern')) {
            return false;
        }
        $objekte = $objekt !== null ? [$objekt] : self::objekteZuGeraet((string) $datenquelle['geraet']);
        foreach ($objekte as $o) {
            if ((int) ($o['steuerung_gesperrt'] ?? 0) === 1) {
                return false;
            }
        }
        if (self::alles()) {
            return true;
        }
        foreach (self::freigaben() as $freigabe) {
            if ((int) $freigabe['darf_steuern'] !== 1) {
                continue;
            }
            if (self::decktGeraet($freigabe, (int) $datenquelle['id'], $kanal)) {
                return true;
            }
            foreach ($objekte as $o) {
                if (self::decktObjekt($freigabe, $o)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------ Datenzeitraum

    /**
     * Welche Messdaten dieser Anlage sichtbar sind.
     *
     * @return list<array{0:?int,1:?int}>|null  null = ohne Grenze; [] = nichts;
     *                                          sonst vereinigte Intervalle (Unix-Zeit, offen = null)
     */
    public static function datenFenster(array $objekt): ?array
    {
        if (self::alles()) {
            return null;
        }
        $fenster = [];
        foreach (self::freigaben() as $freigabe) {
            if (self::decktObjekt($freigabe, $objekt)) {
                $fenster[] = [Zeit::ts($freigabe['daten_ab']), Zeit::ts($freigabe['daten_bis'])];
            }
        }
        return self::vereinigen($fenster);
    }

    /**
     * Sichtbare Messdaten eines Geräts – direkt freigegeben oder über
     * eine Anlage, an der es misst.
     *
     * @return list<array{0:?int,1:?int}>|null
     */
    public static function datenFensterGeraet(array $datenquelle): ?array
    {
        if (self::alles() || Auth::can('datenquellen.manage')) {
            return null;
        }
        $fenster = [];
        foreach (self::freigaben() as $freigabe) {
            if (self::decktGeraet($freigabe, (int) ($datenquelle['id'] ?? 0))) {
                $fenster[] = [Zeit::ts($freigabe['daten_ab']), Zeit::ts($freigabe['daten_bis'])];
            }
        }
        foreach (self::objekteZuGeraet((string) $datenquelle['geraet']) as $objekt) {
            $objektFenster = self::datenFenster($objekt);
            if ($objektFenster === null) {
                return null;
            }
            array_push($fenster, ...$objektFenster);
        }
        return self::vereinigen($fenster);
    }

    /**
     * Intervalle vereinigen. Ein ganz offenes Intervall heißt „ohne Grenze“.
     *
     * @param list<array{0:?int,1:?int}> $fenster
     * @return list<array{0:?int,1:?int}>|null
     */
    public static function vereinigen(array $fenster): ?array
    {
        if ($fenster === []) {
            return [];
        }
        foreach ($fenster as [$a, $b]) {
            if ($a === null && $b === null) {
                return null;
            }
        }
        usort($fenster, static fn ($x, $y) => ($x[0] ?? PHP_INT_MIN) <=> ($y[0] ?? PHP_INT_MIN));
        $ergebnis = [];
        foreach ($fenster as [$a, $b]) {
            if ($b !== null && $a !== null && $b < $a) {
                continue; // verdrehtes Fenster: zeigt nichts
            }
            $letztes = count($ergebnis) - 1;
            if ($letztes >= 0) {
                $ende = $ergebnis[$letztes][1];
                if ($ende === null || $a === null || $a <= $ende) {
                    $ergebnis[$letztes][1] = ($ende === null || $b === null) ? null : max($ende, $b);
                    continue;
                }
            }
            $ergebnis[] = [$a, $b];
        }
        return $ergebnis;
    }

    /**
     * Einen gewünschten Zeitraum auf die sichtbaren Fenster zuschneiden.
     *
     * @param list<array{0:?int,1:?int}>|null $fenster
     * @return list<array{0:int,1:int}> Teilzeiträume, die abgefragt werden dürfen
     */
    public static function begrenzen(?array $fenster, int $von, int $bis): array
    {
        if ($bis <= $von) {
            return [];
        }
        if ($fenster === null) {
            return [[$von, $bis]];
        }
        $teile = [];
        foreach ($fenster as [$a, $b]) {
            $lo = max($von, $a ?? $von);
            $hi = min($bis, $b ?? $bis);
            if ($lo < $hi) {
                $teile[] = [$lo, $hi];
            }
        }
        return $teile;
    }

    /** Darf der Benutzer Live-Werte sehen – liegt „jetzt“ im Datenzeitraum? */
    public static function liveErlaubt(?array $fenster, ?int $jetzt = null): bool
    {
        if ($fenster === null) {
            return true;
        }
        $jetzt ??= time();
        foreach ($fenster as [$a, $b]) {
            if (($a === null || $jetzt >= $a) && ($b === null || $jetzt <= $b)) {
                return true;
            }
        }
        return false;
    }

    /** Lesbare Beschreibung eines Fensters – für Hinweise über Diagrammen. */
    public static function beschreiben(?array $fenster): string
    {
        if ($fenster === null) {
            return 'ohne Zeitgrenze';
        }
        if ($fenster === []) {
            return 'keine Messdaten freigegeben';
        }
        $teile = [];
        foreach ($fenster as [$a, $b]) {
            $teile[] = ($a === null ? 'von Anfang an' : 'ab ' . Zeit::anzeige(Zeit::iso($a)))
                . ($b === null ? '' : ' bis ' . Zeit::anzeige(Zeit::iso($b)));
        }
        return implode('; ', $teile);
    }

    // ------------------------------------------------------------ Hilfen

    /** @return list<array<string,mixed>> Anlagen, an denen dieses Gerät misst */
    public static function objekteZuGeraet(string $geraet): array
    {
        if (self::$geraeteObjekte === null) {
            self::$geraeteObjekte = [];
            $rows = Database::select(
                'SELECT m.geraet, o.* FROM messpunkte m JOIN objekte o ON o.id = m.objekt_id'
            );
            foreach ($rows as $row) {
                $kennung = (string) $row['geraet'];
                unset($row['geraet']);
                self::$geraeteObjekte[$kennung][(int) $row['id']] = $row;
            }
        }
        return array_values(self::$geraeteObjekte[$geraet] ?? []);
    }
}
