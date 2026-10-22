<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;

/**
 * Verteiler-Abgleich: Standard-Verteiler und Inventar über das gemeinsame
 * Repository – dasselbe wie beim Location-Abgleich, mit derselben
 * Arbeitskopie, demselben Zweig und demselben Token (LocationSync).
 *
 * Anders als eine Location, die meist nur eine Installation pflegt, werden
 * Verteiler oft an mehreren Stellen geändert: das Lager tauscht einen Shelly,
 * die Planung benennt einen Typ um. Deshalb wird je Verteiler verglichen, und
 * zwar dreifach – hier, dort und der Stand beim letzten Abgleich:
 *
 *   aktuell        hier und dort gleich
 *   neu            nur im Repository
 *   nur_hier       nur hier, noch nie abgeglichen
 *   dort_neuer     dort geändert, hier nicht       → übernehmen
 *   hier_neuer     hier geändert, dort nicht       → hochladen
 *   beide          auf beiden Seiten geändert      → entscheiden, welcher gilt
 *   dort_entfernt  hier abgeglichen, dort entfernt
 *
 * „Alle übernehmen“ und „Alle hochladen“ nehmen nur die eindeutigen Fälle
 * und überschreiben nie eine Änderung der anderen Seite. Wo beide Seiten
 * geändert haben, entscheidet ein Klick je Verteiler.
 *
 * Gelöscht wird beim Übernehmen nichts. Ein Verteiler, der ausgemustert ist,
 * wird ausdrücklich aus dem Repository entfernt; hier bleibt er, bis ihn
 * jemand löscht.
 */
final class VerteilerSync
{
    /** Status je Verteiler: Text und Farbe des Abzeichens. */
    public const STATUS = [
        'aktuell'       => ['aktuell', 'ok'],
        'neu'           => ['nur im Repository', 'info'],
        'nur_hier'      => ['nur hier', ''],
        'dort_neuer'    => ['dort neuer', 'warn'],
        'hier_neuer'    => ['hier geändert', 'info'],
        'beide'         => ['hier und dort geändert', 'bad'],
        'dort_entfernt' => ['dort entfernt', ''],
        'fehler'        => ['Datei unlesbar', 'bad'],
        // ohne Blick ins Repository
        'nie'           => ['noch nicht abgeglichen', ''],
        'geaendert'     => ['seit dem Abgleich geändert', 'info'],
        'unveraendert'  => ['abgeglichen', 'ok'],
    ];

    /** Was „Alle übernehmen“ anfasst. */
    public const ALLE_UEBERNEHMEN = ['neu', 'dort_neuer'];

    /** Was „Alle hochladen“ anfasst. */
    public const ALLE_HOCHLADEN = ['nur_hier', 'hier_neuer'];

    /** Pfad einer Datei im Repository – oder null, wenn Bereich oder Schlüssel nicht taugen. */
    public static function pfad(string $bereich, string $schluessel): ?string
    {
        // Der Schlüssel kommt aus der Oberfläche oder aus fremden Dateinamen –
        // er darf kein Pfad sein.
        if (!isset(VerteilerBundle::BEREICHE[$bereich]) || $schluessel === '' || LocationBundle::schluessel($schluessel, '') !== $schluessel) {
            return null;
        }
        return VerteilerBundle::BEREICHE[$bereich]['ordner'] . '/' . $schluessel . '.json';
    }

    // ------------------------------------------------------------ Lesen

    /**
     * Die Verteiler in der Arbeitskopie – Stand des letzten Holens.
     *
     * @return array{typ:array<string,array{daten:?array,fehler:?string,hash:string}>,inventar:array<string,array{daten:?array,fehler:?string,hash:string}>}
     */
    public static function dort(): array
    {
        $out = ['typ' => [], 'inventar' => []];
        foreach (VerteilerBundle::BEREICHE as $bereich => $b) {
            foreach (glob(LocationSync::arbeitsKopie() . '/' . $b['ordner'] . '/*.json') ?: [] as $datei) {
                $schluessel = basename($datei, '.json');
                if (self::pfad($bereich, $schluessel) === null) {
                    continue;
                }
                $daten = json_decode((string) file_get_contents($datei), true);
                $fehler = VerteilerBundle::fehler($bereich, $daten);
                $out[$bereich][$schluessel] = [
                    'daten'  => $fehler === null ? $daten : null,
                    'fehler' => $fehler,
                    'hash'   => $fehler === null ? VerteilerBundle::fingerabdruck($daten) : '',
                ];
            }
        }
        return $out;
    }

    /**
     * Hier und dort nebeneinander, mit Status je Verteiler.
     *
     * Ohne `$dort` (das Repository wurde nicht abgefragt) sagt der Status
     * nur, ob sich hier seit dem letzten Abgleich etwas geändert hat.
     *
     * @param array<string,array<string,array<string,mixed>>>|null $dort aus dort()
     * @param array<string,array<string,array<string,mixed>>>|null $hier aus VerteilerBundle::hier()
     * @return array{typ:list<array<string,mixed>>,inventar:list<array<string,mixed>>,uebernehmen:int,hochladen:int}
     */
    public static function liste(?array $dort, ?array $hier = null): array
    {
        $hier ??= VerteilerBundle::hier();
        $out = ['typ' => [], 'inventar' => [], 'uebernehmen' => 0, 'hochladen' => 0];
        $typNamen = [];
        foreach ($dort['typ'] ?? [] as $k => $d) {
            $typNamen[(string) $k] = self::titel('typ', $d['daten'], (string) $k);
        }
        foreach ($hier['typ'] as $k => $h) {
            $typNamen[(string) $k] = $h['titel'];
        }
        foreach (array_keys(VerteilerBundle::BEREICHE) as $bereich) {
            $alle = array_unique(array_map('strval', array_merge(array_keys($hier[$bereich]), $dort !== null ? array_keys($dort[$bereich]) : [])));
            foreach ($alle as $k) {
                $h = $hier[$bereich][$k] ?? null;
                $d = $dort[$bereich][$k] ?? null;
                $status = self::status($h, $d, $dort !== null);
                if ($status === 'aktuell' && $h['synced_hash'] !== $h['hash']) {
                    // Gleich, aber nie als abgeglichen vermerkt (etwa hier und
                    // dort von Hand gleich angelegt): ab jetzt ist das der
                    // gemeinsame Stand, sonst hielte die nächste Änderung
                    // hier beide Seiten für geändert.
                    self::festhalten($bereich, (int) $h['id'], $h['hash']);
                }
                $daten = $h['daten'] ?? $d['daten'] ?? null;
                $out[$bereich][] = [
                    'bereich'      => $bereich,
                    'schluessel'   => $k,
                    'id'           => $h['id'] ?? 0,
                    'titel'        => $h['titel'] ?? self::titel($bereich, $d['daten'] ?? null, $k),
                    'beschreibung' => $daten !== null ? VerteilerBundle::kurzbeschreibung($daten, $typNamen) : '',
                    'status'       => $status,
                    'unterschiede' => in_array($status, ['dort_neuer', 'hier_neuer', 'beide'], true)
                        ? VerteilerBundle::unterschiede($h['daten'] ?? null, $d['daten'] ?? null) : [],
                    'fehler'       => $d['fehler'] ?? null,
                    'dort_stand'   => (string) ($d['daten']['stand'] ?? ''),
                    'dort_von'     => (string) ($d['daten']['von'] ?? ''),
                    'synced_at'    => (string) ($h['synced_at'] ?? ''),
                ];
                if (in_array($status, self::ALLE_UEBERNEHMEN, true)) {
                    $out['uebernehmen']++;
                }
                if (in_array($status, self::ALLE_HOCHLADEN, true)) {
                    $out['hochladen']++;
                }
            }
            usort($out[$bereich], static fn (array $a, array $b): int => strnatcasecmp($a['titel'], $b['titel']));
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $h hier
     * @param array<string,mixed>|null $d dort
     */
    private static function status(?array $h, ?array $d, bool $mitDort): string
    {
        if (!$mitDort) {
            if ($h['synced_hash'] === '') {
                return 'nie';
            }
            return $h['hash'] === $h['synced_hash'] ? 'unveraendert' : 'geaendert';
        }
        if ($h === null) {
            return $d['fehler'] !== null ? 'fehler' : 'neu';
        }
        if ($d === null) {
            return $h['synced_hash'] === '' ? 'nur_hier' : 'dort_entfernt';
        }
        if ($d['fehler'] !== null) {
            return 'fehler';
        }
        if ($h['hash'] === $d['hash']) {
            return 'aktuell';
        }
        if ($d['hash'] === $h['synced_hash']) {
            return 'hier_neuer';
        }
        if ($h['hash'] === $h['synced_hash']) {
            return 'dort_neuer';
        }
        return 'beide';
    }

    private static function titel(string $bereich, ?array $daten, string $ersatz): string
    {
        if ($daten === null) {
            return $ersatz;
        }
        $inhalt = VerteilerBundle::inhalt($daten);
        $titel = (string) ($bereich === 'typ' ? ($inhalt['name'] ?? '') : ($inhalt['kennung'] ?? ''));
        return $titel !== '' ? $titel : $ersatz;
    }

    /** Den gemeinsamen Stand eines Verteilers vermerken. */
    private static function festhalten(string $bereich, int $id, string $hash): void
    {
        Database::execute(
            'UPDATE ' . ($bereich === 'typ' ? 'objekte' : 'inventar') . ' SET synced_hash = ?, synced_at = ? WHERE id = ?',
            [$hash, gmdate('c'), $id]
        );
    }

    /**
     * @param list<array{0:string,1:string}> $auswahl
     * @return list<array{0:string,1:string}> ohne Doppelte, Standard-Verteiler zuerst
     */
    private static function ordnen(array $auswahl): array
    {
        $out = [];
        foreach ($auswahl as [$bereich, $schluessel]) {
            if (isset(VerteilerBundle::BEREICHE[$bereich])) {
                $out[$bereich . '/' . $schluessel] = [$bereich, (string) $schluessel];
            }
        }
        // Typen zuerst: Inventarstücke verweisen auf sie.
        uasort($out, static fn (array $a, array $b): int => ($a[0] === 'typ' ? 0 : 1) <=> ($b[0] === 'typ' ? 0 : 1));
        return array_values($out);
    }

    /** @return list<array{0:string,1:string}> alle Verteiler mit einem dieser Status */
    private static function mitStatus(array $liste, array $status): array
    {
        $out = [];
        foreach (array_keys(VerteilerBundle::BEREICHE) as $bereich) {
            foreach ($liste[$bereich] as $e) {
                if (in_array($e['status'], $status, true)) {
                    $out[] = [$bereich, $e['schluessel']];
                }
            }
        }
        return $out;
    }

    private static function zahlText(int $typen, int $stuecke): string
    {
        $teile = [];
        if ($typen > 0) {
            $teile[] = $typen . ' Standard-Verteiler';
        }
        if ($stuecke > 0) {
            $teile[] = $stuecke === 1 ? '1 Inventarstück' : $stuecke . ' Inventarstücke';
        }
        return implode(' und ', $teile);
    }

    // ------------------------------------------------------------ Übernehmen

    /**
     * Verteiler aus dem Repository übernehmen.
     *
     * Ein Stück, dessen Typ es hier nicht gibt, bringt ihn aus dem
     * Repository mit. Wer den Katalog oder das Inventar nicht pflegen darf,
     * übernimmt dort nichts.
     *
     * Unter der Sperre der Arbeitskopie (LocationSync::gesperrt()): Ein
     * zweites Übernehmen – Doppelklick, zweiter Reiter – spielte sonst
     * dieselben Stücke noch einmal ein, während beide auf das Repository
     * warten.
     *
     * @param list<array{0:string,1:string}>|null $auswahl [bereich, schlüssel]; null = alles, was dort neu oder neuer ist
     * @param array{typ:bool,inventar:bool} $erlaubt
     * @return array{ok:bool,message:string}
     */
    public static function uebernehmen(?array $auswahl, array $erlaubt): array
    {
        return LocationSync::gesperrt(static fn (): array => self::uebernehmenGesperrt($auswahl, $erlaubt));
    }

    /** uebernehmen() unter der Sperre der Arbeitskopie – vom Holen bis zum Eintragen. */
    private static function uebernehmenGesperrt(?array $auswahl, array $erlaubt): array
    {
        $holen = LocationSync::holen();
        if (!$holen['ok']) {
            return ['ok' => false, 'message' => $holen['message']];
        }
        // Während des Holens begann ein Update? Dann nichts (LocationSync::updateBegonnen()).
        if (($sperre = LocationSync::updateBegonnen(UpdateService::NICHTS_UEBERNOMMEN)) !== null) {
            return $sperre;
        }
        $dort = self::dort();
        if ($auswahl === null) {
            $auswahl = self::mitStatus(self::liste($dort), self::ALLE_UEBERNEHMEN);
            if ($auswahl === []) {
                return ['ok' => true, 'message' => 'Im Repository ist nichts neuer als hier.'];
            }
        }

        $zahl = ['typ' => 0, 'inventar' => 0];
        $probleme = [];
        $hinweise = [];
        $gesperrt = ['typ' => 0, 'inventar' => 0];
        $typEinspielen = static function (string $schluessel) use ($dort, &$zahl, &$probleme): ?int {
            $d = $dort['typ'][$schluessel] ?? null;
            if ($d === null || $d['daten'] === null) {
                $probleme[] = $schluessel . ': ' . ($d['fehler'] ?? 'steht nicht im Repository.');
                return null;
            }
            $e = VerteilerBundle::typAnwenden($schluessel, $d['daten']);
            if (!$e['ok']) {
                $probleme[] = $e['message'];
                return null;
            }
            self::festhalten('typ', (int) $e['id'], $d['hash']);
            $zahl['typ']++;
            return (int) $e['id'];
        };
        // Der Typ eines Stücks: hier vorhanden – oder aus dem Repository mitgebracht.
        $typ = static function (string $schluessel) use ($dort, $erlaubt, $typEinspielen): ?int {
            $id = Database::scalar('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$schluessel]);
            if ($id !== null && $id !== false) {
                return (int) $id;
            }
            return $erlaubt['typ'] && isset($dort['typ'][$schluessel]) ? $typEinspielen($schluessel) : null;
        };

        foreach (self::ordnen($auswahl) as [$bereich, $schluessel]) {
            if (empty($erlaubt[$bereich])) {
                $gesperrt[$bereich]++;
                continue;
            }
            if ($bereich === 'typ') {
                $typEinspielen($schluessel);
                continue;
            }
            $d = $dort['inventar'][$schluessel] ?? null;
            if ($d === null || $d['daten'] === null) {
                $probleme[] = $schluessel . ': ' . ($d['fehler'] ?? 'steht nicht im Repository.');
                continue;
            }
            $e = VerteilerBundle::stueckAnwenden($schluessel, $d['daten'], $typ);
            if (!$e['ok']) {
                $probleme[] = $e['message'];
                continue;
            }
            self::festhalten('inventar', (int) $e['id'], $d['hash']);
            $zahl['inventar']++;
            if ($e['hinweis'] !== '') {
                $hinweise[] = $e['hinweis'];
            }
        }

        if ($zahl['typ'] + $zahl['inventar'] > 0) {
            Audit::log('verteiler.uebernehmen', 'repository', $holen['commit'], ['typen' => $zahl['typ'], 'inventar' => $zahl['inventar']]);
        }
        $meldung = $zahl['typ'] + $zahl['inventar'] > 0
            ? self::zahlText($zahl['typ'], $zahl['inventar']) . ' übernommen.'
            : 'Nichts übernommen.';
        if ($gesperrt['typ'] > 0) {
            $meldung .= ' ' . $gesperrt['typ'] . ' Standard-Verteiler übergangen – den Katalog pflegen darfst du nicht.';
        }
        if ($gesperrt['inventar'] > 0) {
            $meldung .= ' ' . $gesperrt['inventar'] . ' Inventarstück(e) übergangen – das Inventar pflegen darfst du nicht.';
        }
        if ($hinweise !== []) {
            $meldung .= ' ' . implode(' ', $hinweise);
        }
        if ($probleme !== []) {
            $meldung .= ' Nicht gelungen: ' . implode(' · ', $probleme);
        }
        return ['ok' => $probleme === [] && $zahl['typ'] + $zahl['inventar'] > 0, 'message' => $meldung];
    }

    // ------------------------------------------------------------ Hochladen

    /**
     * Verteiler ins Repository hochladen – eine Datei je Verteiler, alles in
     * einem Commit.
     *
     * Ein Stück nimmt seinen Typ mit, wenn der dort noch fehlt. Was dort
     * schon genauso steht, wird nicht neu geschrieben (sonst entstünde ein
     * Commit, in dem sich nur der Zeitstempel ändert).
     *
     * Unter der Sperre der Arbeitskopie (LocationSync::gesperrt()): vom
     * Holen über das Schreiben der Dateien bis zum Hochladen. Sonst setzte
     * ein zweiter Abgleich die Arbeitskopie zurück, während dieser noch
     * nicht eingecheckt hat.
     *
     * @param list<array{0:string,1:string}>|null $auswahl null = alles, was nur hier steht oder hier geändert wurde
     * @return array{ok:bool,message:string}
     */
    public static function hochladen(?array $auswahl, string $wer): array
    {
        return LocationSync::gesperrt(static fn (): array => self::hochladenGesperrt($auswahl, $wer));
    }

    /** hochladen() unter der Sperre der Arbeitskopie. */
    private static function hochladenGesperrt(?array $auswahl, string $wer): array
    {
        $holen = LocationSync::holen();
        if (!$holen['ok']) {
            return ['ok' => false, 'message' => $holen['message']];
        }
        if (($sperre = LocationSync::updateBegonnen(UpdateService::NICHTS_HOCHGELADEN)) !== null) {
            return $sperre;
        }
        $dort = self::dort();
        $hier = VerteilerBundle::hier();
        if ($auswahl === null) {
            $auswahl = self::mitStatus(self::liste($dort, $hier), self::ALLE_HOCHLADEN);
            if ($auswahl === []) {
                return ['ok' => true, 'message' => 'Nichts hochzuladen – was hier steht, steht so auch im Repository.'];
            }
        }
        foreach ($auswahl as [$bereich, $schluessel]) {
            $typ = $bereich === 'inventar' ? ($hier['inventar'][$schluessel]['daten']['stueck']['typ'] ?? null) : null;
            if (is_string($typ) && !isset($dort['typ'][$typ]) && isset($hier['typ'][$typ])) {
                $auswahl[] = ['typ', $typ];
            }
        }

        $kopie = LocationSync::arbeitsKopie();
        $pfade = [];
        $geschrieben = [];
        $vermerken = [];
        $probleme = [];
        $zahl = ['typ' => 0, 'inventar' => 0];
        foreach (self::ordnen($auswahl) as [$bereich, $schluessel]) {
            $h = $hier[$bereich][$schluessel] ?? null;
            $pfad = self::pfad($bereich, $schluessel);
            if ($h === null || $pfad === null) {
                $probleme[] = $schluessel . ' gibt es hier nicht.';
                continue;
            }
            $vermerken[] = [$bereich, $h];
            if (($dort[$bereich][$schluessel]['hash'] ?? '') === $h['hash']) {
                continue;
            }
            if (!is_dir(dirname($kopie . '/' . $pfad)) && !mkdir(dirname($kopie . '/' . $pfad), 0775, true) && !is_dir(dirname($kopie . '/' . $pfad))) {
                return ['ok' => false, 'message' => 'Der Ordner ' . dirname($pfad) . ' ließ sich in der Arbeitskopie nicht anlegen.'];
            }
            file_put_contents($kopie . '/' . $pfad, LocationSync::json($h['daten']));
            $pfade[] = $pfad;
            $geschrieben[] = ($bereich === 'typ' ? 'Standard-Verteiler ' : 'Verteiler ') . $h['titel'];
            $zahl[$bereich]++;
        }

        $wo = '';
        if ($pfade !== []) {
            $geruest = LocationSync::grundgeruestAnlegen();
            $name = (string) Config::get('app.name', 'PowerManagement');
            $nachricht = (count($geschrieben) === 1 ? $geschrieben[0] : 'Verteiler: ' . self::zahlText($zahl['typ'], $zahl['inventar'])) . ' aus ' . $name;
            $ergebnis = LocationSync::abschliessen(array_merge($pfade, $geruest), $pfade[0], $nachricht, 'Hochgeladen von ' . $wer);
            if (!$ergebnis['ok']) {
                return ['ok' => false, 'message' => $ergebnis['message']];
            }
            // Mehrere Dateien: der Ordner, nicht die erste davon.
            $wo = ' ' . (count($pfade) === 1 ? $ergebnis['wo'] : str_replace($pfade[0], 'verteiler/', $ergebnis['wo']));
        }
        // Geschoben ist es – die Vermerke hier nur ohne Update, das inzwischen begann.
        if (($sperre = LocationSync::updateBegonnen(UpdateService::VERMERK_FEHLT_VERTEILER)) !== null) {
            Logger::info('Verteiler hochgeladen, während ein Update begann – Vermerke und Audit fehlen.', ['typen' => $zahl['typ'], 'inventar' => $zahl['inventar']]);
            return ['ok' => true, 'wartung' => true, 'message' => ($pfade !== [] ? self::zahlText($zahl['typ'], $zahl['inventar']) . ' hochgeladen.' . $wo . ' ' : '')
                . $sperre['message']];
        }
        foreach ($vermerken as [$bereich, $h]) {
            self::festhalten($bereich, (int) $h['id'], $h['hash']);
        }
        if ($pfade === []) {
            return ['ok' => $probleme === [], 'message' => 'Im Repository stand schon derselbe Stand – nichts zu tun.'
                . ($probleme !== [] ? ' ' . implode(' ', $probleme) : '')];
        }
        Audit::log('verteiler.hochladen', 'repository', LocationSync::kopfCommit(), ['typen' => $zahl['typ'], 'inventar' => $zahl['inventar']]);
        return [
            'ok'      => $probleme === [],
            'message' => self::zahlText($zahl['typ'], $zahl['inventar']) . ' hochgeladen.' . $wo
                . ($probleme !== [] ? ' ' . implode(' ', $probleme) : '') . LocationSync::zweigHinweis(),
        ];
    }

    // ------------------------------------------------------------ Entfernen

    /**
     * Einen Verteiler aus dem Repository nehmen – etwa ein ausgemustertes
     * Stück. Hier bleibt er stehen; die anderen Installationen sehen ihn
     * danach als „nur hier“ bzw. „dort entfernt“ und entscheiden selbst.
     *
     * Unter der Sperre der Arbeitskopie (LocationSync::gesperrt()).
     *
     * @return array{ok:bool,message:string}
     */
    public static function entfernen(string $bereich, string $schluessel, string $wer): array
    {
        return LocationSync::gesperrt(static fn (): array => self::entfernenGesperrt($bereich, $schluessel, $wer));
    }

    /** entfernen() unter der Sperre der Arbeitskopie. */
    private static function entfernenGesperrt(string $bereich, string $schluessel, string $wer): array
    {
        $pfad = self::pfad($bereich, $schluessel);
        if ($pfad === null) {
            return ['ok' => false, 'message' => 'Unbekannter Verteiler.'];
        }
        $holen = LocationSync::holen();
        if (!$holen['ok']) {
            return ['ok' => false, 'message' => $holen['message']];
        }
        if (($sperre = LocationSync::updateBegonnen(UpdateService::NICHTS_HOCHGELADEN)) !== null) {
            return $sperre;
        }
        $datei = LocationSync::arbeitsKopie() . '/' . $pfad;
        if (!is_file($datei)) {
            return ['ok' => false, 'message' => 'Dieser Verteiler steht nicht (mehr) im Repository.'];
        }
        $daten = json_decode((string) file_get_contents($datei), true);
        $titel = self::titel($bereich, is_array($daten) && VerteilerBundle::fehler($bereich, $daten) === null ? $daten : null, $schluessel);
        unlink($datei);
        $ergebnis = LocationSync::abschliessen([$pfad], null,
            ($bereich === 'typ' ? 'Standard-Verteiler ' : 'Verteiler ') . $titel . ' entfernt', 'Entfernt von ' . $wer);
        if (!$ergebnis['ok']) {
            return ['ok' => false, 'message' => $ergebnis['message']];
        }
        // Entfernt ist es dort; hier gibt es nur den Eintrag im Audit – nicht während eines Updates.
        if (UpdateService::wartungAktiv()) {
            Logger::info('Verteiler aus dem Repository entfernt, während ein Update begann – ohne Audit.', ['bereich' => $bereich, 'schluessel' => $schluessel]);
            return ['ok' => true, 'wartung' => true, 'message' => '„' . $titel . '“ ist aus dem Repository entfernt. Hier bleibt es stehen. ' . $ergebnis['wo']];
        }
        Audit::log('verteiler.entfernen', 'repository', LocationSync::kopfCommit(), ['bereich' => $bereich, 'schluessel' => $schluessel]);
        return ['ok' => true, 'message' => '„' . $titel . '“ ist aus dem Repository entfernt. Hier bleibt es stehen. ' . $ergebnis['wo']];
    }
}
