<?php
declare(strict_types=1);

namespace App\Kunden;

use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Was ein Kunde sieht – für den Kundenbereich und für die Anzeige „Der Kunde
 * sieht derzeit …“ beim Personal (später auch die Kundenansicht).
 *
 * Jede Methode nimmt die Kunden-ID ausdrücklich: kein angemeldetes Konto,
 * kein globaler Zustand, der in andere Pfade sickert. Locations kommen nur
 * aus den wirksamen Freigaben am Kunden (Kundenbereich::freigaben() – DAS
 * Prädikat; ein beendeter Kunde hat keine). Jede weitere ID (Etage) wird
 * einzeln gegen die Location geprüft; fremd und nicht vorhanden sind
 * dasselbe: null, im Kundenbereich 404.
 *
 * Herausgegeben werden nur ausdrücklich genannte Felder – nie sites.notes,
 * floors.notes, floors.fremdbestand, kunden.notizen, mobile Anlagen,
 * Kabel, Messgeräte oder Einsätze. Eventworx wird von hier aus nie gefragt.
 */
final class KundenSicht
{
    /**
     * Die Locations, die der Kunde jetzt sieht – je Standort einmal, mit den
     * Freigaben dorthin (Veranstaltung, sichtbar bis, Messdaten). Nach Name.
     *
     * @return list<array{site:array{id:int,name:string,address:string},freigaben:list<array{veranstaltung:string,ab:?string,bis:?string,daten_ab:?string,daten_bis:?string,daten:string}>}>
     */
    public static function locations(int $kundeId, ?int $jetzt = null): array
    {
        $jeStandort = [];
        foreach (Kundenbereich::freigaben($kundeId, $jetzt) as $f) {
            $jeStandort[(int) $f['ziel_id']][] = self::freigabe($f);
        }
        $out = [];
        foreach ($jeStandort as $siteId => $freigaben) {
            $site = self::standort($siteId);
            if ($site === null) {
                continue;   // Standort gelöscht – seine Freigaben gehen mit (KundenService::standortEntfernt)
            }
            usort($freigaben, static fn (array $a, array $b): int => strcmp((string) $a['bis'], (string) $b['bis']));
            $out[] = ['site' => $site, 'freigaben' => $freigaben];
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['site']['name'], $b['site']['name']) ?: $a['site']['id'] <=> $b['site']['id']);
        return $out;
    }

    /**
     * Locations, die der Kunde erst noch sehen wird – nur Name und „ab …“
     * (früheste Freigabe je Standort). Was er jetzt schon sieht, steht hier
     * nicht noch einmal.
     *
     * @return list<array{name:string,ab:string}>
     */
    public static function kommend(int $kundeId, ?int $jetzt = null): array
    {
        $sichtbar = array_map(static fn (array $f): int => (int) $f['ziel_id'], Kundenbereich::freigaben($kundeId, $jetzt));
        $ab = [];
        foreach (Kundenbereich::kommend($kundeId, $jetzt) as $f) {
            $siteId = (int) $f['ziel_id'];
            if (in_array($siteId, $sichtbar, true)) {
                continue;
            }
            $ts = (int) Zeit::ts((string) $f['gueltig_ab']);
            $ab[$siteId] = isset($ab[$siteId]) ? min($ab[$siteId], $ts) : $ts;
        }
        asort($ab);
        $out = [];
        foreach ($ab as $siteId => $ts) {
            $site = self::standort($siteId);
            if ($site !== null) {
                $out[] = ['name' => $site['name'], 'ab' => Zeit::iso($ts)];
            }
        }
        return $out;
    }

    /**
     * Eine Location, wie der Kunde sie sieht – oder null (nicht freigegeben,
     * nicht vorhanden, Kunde beendet: dasselbe).
     *
     * etagen: nur Etagen mit Plan; feststrom: nur fest installierte Anlagen
     * dieses Standorts (keine Vorlagen, nie mobile).
     *
     * @return array{site:array{id:int,name:string,address:string},freigaben:list<array<string,mixed>>,etagen:list<array{id:int,name:string,plan:string,seite:int}>,feststrom:list<array{name:string,art:string,etage:string,anschluss:string,vorsicherung:string}>}|null
     */
    public static function location(int $kundeId, int $siteId, ?int $jetzt = null): ?array
    {
        $eintrag = null;
        foreach (self::locations($kundeId, $jetzt) as $l) {
            if ($l['site']['id'] === $siteId) {
                $eintrag = $l;
                break;
            }
        }
        if ($eintrag === null) {
            return null;
        }
        $etagen = [];
        $etagenNamen = [];
        foreach (self::etagenZeilen($siteId) as $f) {
            $etagenNamen[(int) $f['id']] = (string) $f['name'];
            $art = self::planArt($f);
            if ($art !== '') {
                $etagen[] = ['id' => (int) $f['id'], 'name' => (string) $f['name'], 'plan' => $art,
                    'seite' => $art === 'pdf' ? max(1, (int) $f['plan_page']) : 1];
            }
        }
        $feststrom = [];
        foreach (AnlagenService::liste(['site_id' => $siteId, 'fest' => true]) as $o) {
            $e = $o['eigenschaften'];
            $art = $o['art'] === 'anschluss' && is_string($e['anschlussart'] ?? null) && $e['anschlussart'] !== ''
                ? (string) $e['anschlussart'] : (string) $o['art_anzeige'];
            $vorsicherung = is_numeric($e['absicherung_a'] ?? null) && (float) $e['absicherung_a'] > 0
                ? rtrim(rtrim(number_format((float) $e['absicherung_a'], 1, ',', ''), '0'), ',') . ' A' : '';
            $feststrom[] = [
                'name'         => (string) $o['name'],
                'art'          => $art,
                // Nur eine Etage dieses Standorts – nie ein Name von anderswo.
                'etage'        => $etagenNamen[(int) ($o['floor_id'] ?? 0)] ?? '',
                'anschluss'    => AnlagenService::kurzform((int) $o['id']),
                'vorsicherung' => $vorsicherung,
            ];
        }
        return $eintrag + ['etagen' => $etagen, 'feststrom' => $feststrom];
    }

    /**
     * Eine Etage mit Plan – nur, wenn sie zu genau dieser Location gehört,
     * die Location für den Kunden sichtbar ist und ein Plan vorliegt. Nur die
     * Felder, die die Auslieferung braucht (SiteService::planAusliefern).
     *
     * @return array{id:int,site_id:int,name:string,plan_file:string,plan_type:string,plan_page:int,plan_original_name:string}|null
     */
    public static function etage(int $kundeId, int $siteId, int $floorId, ?int $jetzt = null): ?array
    {
        $f = Database::first('SELECT id, site_id, name, plan_file, plan_type, plan_page, plan_original_name FROM floors WHERE id = ?', [$floorId]);
        if ($f === null || (int) $f['site_id'] !== $siteId || self::planArt($f) === '') {
            return null;
        }
        if (!in_array($siteId, array_map(static fn (array $l): int => $l['site']['id'], self::locations($kundeId, $jetzt)), true)) {
            return null;
        }
        return ['id' => (int) $f['id'], 'site_id' => (int) $f['site_id'], 'name' => (string) $f['name'], 'plan_file' => (string) $f['plan_file'],
            'plan_type' => (string) $f['plan_type'], 'plan_page' => (int) $f['plan_page'], 'plan_original_name' => (string) $f['plan_original_name']];
    }

    /**
     * Für das Personal: „Der Kunde sieht derzeit …“ – aus denselben
     * Prädikaten wie der Kundenbereich. gesperrt: null oder der Grund
     * (Kunde beendet oder abgelaufen; dann sieht er nichts).
     *
     * @return array{gesperrt:?string,locations:list<array<string,mixed>>,kommend:list<array{name:string,ab:string}>}
     */
    public static function zusammenfassung(int $kundeId, ?int $jetzt = null): array
    {
        return [
            'gesperrt'  => Kundenbereich::kundeSperre($kundeId, $jetzt),
            'locations' => self::locations($kundeId, $jetzt),
            'kommend'   => self::kommend($kundeId, $jetzt),
        ];
    }

    // ------------------------------------------------------------ intern

    /** @return array{id:int,name:string,address:string}|null nur Name und Anschrift – nie die Notizen */
    private static function standort(int $siteId): ?array
    {
        $s = Database::first('SELECT id, name, address FROM sites WHERE id = ?', [$siteId]);
        return $s === null ? null : ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'address' => (string) $s['address']];
    }

    /** @return list<array<string,mixed>> Etagen des Standorts – ohne Notizen und Fremdbestand */
    private static function etagenZeilen(int $siteId): array
    {
        return Database::select('SELECT id, name, plan_file, plan_page FROM floors WHERE site_id = ? ORDER BY sort_order, level_no, name, id', [$siteId]);
    }

    /** 'pdf', 'bild' – oder '' ohne (vorhandenen) Plan. */
    private static function planArt(array $etage): string
    {
        if (SiteService::planPath($etage) === null) {
            return '';
        }
        return SiteService::planMime($etage) === 'application/pdf' ? 'pdf' : 'bild';
    }

    /**
     * Eine wirksame Kunden-Freigabe, wie der Kunde sie lesen darf: die
     * Veranstaltung (notiz), wie lange sichtbar, welche Messdaten. Ein
     * leerer Datenzeitraum (Kundenbereich::freigaben) heißt „keine“.
     */
    private static function freigabe(array $f): array
    {
        $a = Zeit::ts($f['daten_ab'] ?? null);
        $b = Zeit::ts($f['daten_bis'] ?? null);
        $leer = $a === null || $b === null || $a >= $b;
        return [
            'veranstaltung' => (string) ($f['notiz'] ?? ''),
            'ab'            => $f['gueltig_ab'] ?? null,
            'bis'           => $f['gueltig_bis'] ?? null,
            'daten_ab'      => $leer ? null : $f['daten_ab'],
            'daten_bis'     => $leer ? null : $f['daten_bis'],
            // Als ganzer Satzteil, so in den Ansichten: „Messdaten vom … bis …“ bzw. „keine Messdaten freigegeben“.
            'daten'         => $leer ? Zugriff::beschreiben([])
                : 'Messdaten vom ' . Zeit::anzeige((string) $f['daten_ab']) . ' bis ' . Zeit::anzeige((string) $f['daten_bis']),
        ];
    }
}
