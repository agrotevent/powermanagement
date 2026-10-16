<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\ApiZugang;
use App\Support\Database;
use App\Support\Fingerabdruck;

/**
 * Was die Schnittstelle herausgibt – zugeschnitten auf ein Token.
 *
 * Hinaus geht genau das, was auch über Git geht: die Location im Format von
 * MasterAdmin (location, format 1) und daneben strom (format 2) mit der
 * Georeferenz und – nur mit der Befugnis „feststrom“ – den
 * Feststromanschlüssen, fest verlegten Kabeln und Kabelwegen und ihren
 * Steckertypen.
 *
 * Nie hinaus: Messpunkte (sie brächten auf der anderen Seite Schaltrechte
 * mit), mobile Anlagen und Kabel, Kabelbrücken, Bereiche, Aufbau, Einsätze,
 * Inventar, der Fremdbestand (Dosen, Racks, APs von MasterAdmin) – und auf
 * den Etagen weder „dosen“ noch „racks“ noch „aps“: eine leere Liste hieße
 * bei MasterAdmin „hier gibt es keine“ und löschte dort die Dosen. Was fehlt,
 * fehlt als Schlüssel. Ebenso „referenz“, wenn kein Maßstab gesetzt ist –
 * null löschte ihn dort.
 *
 * „stand“ ist ein Fingerabdruck über genau das, was das Token bekommt: er
 * ändert sich mit dem Inhalt, mit den Befugnissen und mit „bearbeiten“, nicht
 * mit der Uhrzeit.
 */
final class ApiBestand
{
    /**
     * Größte Plandatei, die ein Vorschlag mitbringen darf. Als base64 im
     * JSON wird sie um ein Drittel größer; der ganze Körper darf 48 MiB haben.
     */
    public const MAX_DATEI_BYTES = 30 * 1024 * 1024;

    /** Endungen, die als Plandatei hinausgehen (wie MasterAdmin sie annimmt). */
    private const ENDUNGEN = ['pdf', 'png', 'jpg', 'svg'];

    /**
     * Die freigegebenen Standorte – für GET /locations, nach Namen.
     *
     * @return list<array<string,mixed>>
     */
    public static function locations(array $token): array
    {
        $out = [];
        foreach (ApiZugang::standorte($token) as $siteId => $_bearbeiten) {
            $bestand = self::fuerStandort($token, $siteId);
            if ($bestand === null) {
                continue;
            }
            $anlagen = $leitungen = [];
            foreach ((array) ($bestand['strom']['anlagen'] ?? []) as $a) {
                $anlagen[(string) ($a['etage'] ?? '')] = ($anlagen[(string) ($a['etage'] ?? '')] ?? 0) + 1;
            }
            foreach ((array) ($bestand['strom']['leitungen'] ?? []) as $l) {
                $leitungen[(string) ($l['etage'] ?? '')] = ($leitungen[(string) ($l['etage'] ?? '')] ?? 0) + 1;
            }
            $mitFeststrom = isset($bestand['strom']['anlagen']);
            $out[] = [
                'schluessel' => (string) $bestand['location']['schluessel'],
                'name'       => (string) $bestand['location']['name'],
                'stand'      => $bestand['stand'],
                'etagen'     => array_map(static fn (array $e): array => [
                    'schluessel' => (string) $e['schluessel'],
                    'name'       => (string) $e['name'],
                    'plan'       => $e['plan'] !== null,
                    'bearbeiten' => (bool) $e['bearbeiten'],
                    // Dosen und Racks pflegt diese Instanz nicht – null zeigt
                    // MasterAdmin als „nichts dazu“, nicht als „keine“.
                    'dosen'      => null,
                    'racks'      => null,
                    'anlagen'    => $mitFeststrom ? ($anlagen[(string) $e['schluessel']] ?? 0) : null,
                    'leitungen'  => $mitFeststrom ? ($leitungen[(string) $e['schluessel']] ?? 0) : null,
                ], (array) $bestand['location']['etagen']),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['schluessel']] <=> [mb_strtolower($b['name']), $b['schluessel']]);
        return $out;
    }

    /**
     * Eine Location für das Token – oder null, wenn es sie nicht gibt oder
     * das Token sie nicht sieht (für die Gegenseite dasselbe: 404).
     *
     * @return array{location:array<string,mixed>,strom:?array<string,mixed>,dateien:array<string,string>,stand:string,site_id:int}|null
     */
    public static function location(array $token, string $schluessel): ?array
    {
        if (!LocationBundle::schluesselGueltig($schluessel)) {
            return null;
        }
        foreach (Database::select('SELECT id FROM sites WHERE sync_key = ? ORDER BY id', [$schluessel]) as $site) {
            if (ApiZugang::sieht($token, (int) $site['id'])) {
                return self::fuerStandort($token, (int) $site['id']);
            }
        }
        return null;
    }

    /** @return array{location:array<string,mixed>,strom:?array<string,mixed>,dateien:array<string,string>,stand:string,site_id:int}|null */
    private static function fuerStandort(array $token, int $siteId): ?array
    {
        if (!ApiZugang::sieht($token, $siteId) || SiteService::find($siteId) === null) {
            return null;
        }
        $daten = self::zusammenstellen($siteId, ApiZugang::darf($token, 'feststrom'), ApiZugang::bearbeitet($token, $siteId));
        $daten['stand'] = self::stand($daten);
        $daten['site_id'] = $siteId;
        return $daten;
    }

    /**
     * Unzugeschnitten (ohne Token, bearbeiten = false) – für „Änderungen
     * zurückschicken“, das die eigene Seite schickt.
     *
     * @return array{location:array<string,mixed>,strom:array<string,mixed>,dateien:array<string,string>}
     */
    public static function locationDaten(int $siteId, bool $mitFeststrom): array
    {
        return self::zusammenstellen($siteId, $mitFeststrom, false);
    }

    /** Der Fingerabdruck dessen, was hinausgeht – 16 Hexzeichen. */
    public static function stand(array $daten): string
    {
        return substr(Fingerabdruck::von(['location' => $daten['location'] ?? null, 'strom' => $daten['strom'] ?? null]), 0, 16);
    }

    /**
     * @return array{location:array<string,mixed>,strom:array<string,mixed>,dateien:array<string,string>}
     */
    private static function zusammenstellen(int $siteId, bool $mitFeststrom, bool $bearbeiten): array
    {
        $site = SiteService::find($siteId);
        if ($site === null) {
            throw new \RuntimeException('Standort ' . $siteId . ' fehlt.');
        }
        // Dieselben Schlüssel wie im Location-Abgleich über Git – einmal
        // vergeben, dann fest (auch ein Abruf darf sie deshalb vergeben).
        $siteKey = LocationBundle::schluesselSichern('sites', $siteId, (string) $site['name'], 'standort-' . $siteId);

        $etagen = [];
        $dateien = [];
        foreach (SiteService::floors($siteId) as $f) {
            $fid = (int) $f['id'];
            $key = LocationBundle::schluesselSichern('floors', $fid, (string) $f['name'], 'etage-' . $fid);
            $etage = [
                'schluessel'  => $key,
                'name'        => (string) $f['name'],
                'ebene'       => (int) $f['level_no'],
                'reihenfolge' => (int) $f['sort_order'],
                'massstab'    => (string) ($f['scale_note'] ?? ''),
                'notizen'     => (string) ($f['notes'] ?? ''),
                'plan'        => null,
            ];
            $pfad = SiteService::planPath($f);
            $endung = $pfad !== null ? strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) : '';
            $endung = $endung === 'jpeg' ? 'jpg' : $endung;
            $summe = $pfad !== null && in_array($endung, self::ENDUNGEN, true) ? SiteService::planPruefsumme($f) : null;
            if ($summe !== null) {
                $datei = 'plaene/' . $key . '.' . $endung;
                $etage['plan'] = [
                    'datei'        => $datei,
                    'typ'          => $endung === 'pdf' ? 'pdf' : 'image',
                    'seite'        => max(1, (int) ($f['plan_page'] ?? 1)),
                    'originalname' => (string) ($f['plan_original_name'] ?? ''),
                    'sha256'       => $summe['sha256'],
                    'bytes'        => $summe['bytes'],
                ];
                $dateien[$datei] = (string) $pfad;
            }
            // Ohne gesetzten Maßstab fehlt der Schlüssel – null hieße dort
            // „Maßstab entfernen“. Bewusst nicht wirksam(): ein aus der
            // Georeferenz abgeleiteter Maßstab ist keiner, der gesetzt wurde.
            $referenz = PlanMassstabService::lesen($f);
            if ($referenz !== null) {
                $etage['referenz'] = $referenz;
            }
            $etage['bearbeiten'] = $bearbeiten;
            $etagen[] = $etage;
        }
        // Gleich sortiert, auch wo Reihenfolge, Ebene und Name gleich sind –
        // sonst wechselte der Stand je nach Laune der Datenbank.
        usort($etagen, static fn (array $a, array $b): int
            => [$a['reihenfolge'], $a['ebene'], $a['name'], $a['schluessel']] <=> [$b['reihenfolge'], $b['ebene'], $b['name'], $b['schluessel']]);

        $location = [
            'format'     => LocationBundle::FORMAT_LOCATION,
            'art'        => 'location',
            'schluessel' => $siteKey,
            'name'       => (string) $site['name'],
            'adresse'    => (string) ($site['address'] ?? ''),
            'notizen'    => (string) ($site['notes'] ?? ''),
            'etagen'     => $etagen,
        ];

        $strom = LocationBundle::stromLesen($siteId);
        // Zeitpunkt und Absender ändern sich bei jedem Lesen – sie gehören
        // nicht zum Inhalt (und nicht in den Stand).
        unset($strom['stand'], $strom['von']);
        $geo = (array) ($strom['georeferenz'] ?? []);
        ksort($geo);
        if ($mitFeststrom) {
            $anlagen = [];
            foreach ((array) ($strom['anlagen'] ?? []) as $a) {
                unset($a['messpunkte']);
                $anlagen[] = $a;
            }
            $strom['anlagen'] = self::nachSchluessel($anlagen);
            $strom['leitungen'] = self::nachSchluessel((array) ($strom['leitungen'] ?? []));
            $strom['steckertypen'] = self::nachSchluessel((array) ($strom['steckertypen'] ?? []));
        } else {
            unset($strom['anlagen'], $strom['leitungen'], $strom['steckertypen']);
        }
        // Eine leere Georeferenz geht als {} hinaus, nicht als [].
        $strom['georeferenz'] = $geo === [] ? new \stdClass() : $geo;

        return ['location' => $location, 'strom' => $strom, 'dateien' => $dateien];
    }

    /**
     * @param array<int,array<string,mixed>> $liste
     * @return list<array<string,mixed>>
     */
    private static function nachSchluessel(array $liste): array
    {
        $liste = array_values($liste);
        usort($liste, static fn (array $a, array $b): int => strcmp((string) ($a['schluessel'] ?? ''), (string) ($b['schluessel'] ?? '')));
        return $liste;
    }
}
