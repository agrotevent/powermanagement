<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Database;

/**
 * Locations als Dateien: location.json (MasterAdmin) und strom.json (hier).
 *
 * location.json hat das Format von MasterAdmin (FORMAT 1) und wird von hier
 * aus nur so weit angefasst, wie es PowerManagement betrifft: Etagen,
 * Grundriss, Maßstab. Dosen, Racks und Accesspoints werden gelesen und als
 * „Fremdbestand“ zur Orientierung auf der Karte gezeigt – gepflegt werden
 * sie in MasterAdmin.
 *
 * strom.json gehört PowerManagement und enthält die **Feststromanschlüsse**
 * der Location – was fest installiert ist und bei jeder Veranstaltung an
 * derselben Stelle steht (Hausanschluss, Energiesäulen, Wandverteiler …):
 *
 *   { format: 2, art: "strom", schluessel, stand, von,
 *     steckertypen: [{schluessel, name, strom_a, phasen, spannung_v, farbe}],
 *     anlagen: [{schluessel, art, fest: true, name, …, etage, x, y,
 *                schutzorgane: [{ref, art, bezeichnung, …, vor}],
 *                anschluesse: [{richtung, stecker, nr, bezeichnung, schutz, phase, notizen}],
 *                messpunkte: [{geraet, kanal, rolle, bezeichnung, anschluss, schutz}]}] }
 *
 * Mobile Anlagen, Kabel, Kabelwege und Kabelbrücken gehören zur
 * Veranstaltung und bleiben in der Installation, die sie plant. Format 1
 * (bis September 2026) enthielt noch alles; davon werden beim Übernehmen nur
 * die Stromanschlüsse gelesen.
 *
 * Anschlüsse werden über „richtung:nr“ angesprochen (abgang:3), Schutzorgane
 * über eine Referenz innerhalb der Anlage – so bleiben die Verweise über
 * Installationen hinweg gültig, obwohl die Datenbank-IDs überall andere sind.
 */
final class LocationBundle
{
    /** Das Format von location.json, das MasterAdmin schreibt und liest. */
    public const FORMAT_LOCATION = 1;

    /** Das Format von strom.json – 2: nur Feststromanschlüsse, keine Leitungen. */
    public const FORMAT_STROM = 2;

    // ------------------------------------------------------------ Schlüssel

    /** Wie in MasterAdmin: aus „Bühne Süd“ wird „buehne-sued“. */
    public static function schluessel(string $name, string $ersatz = 'ohne-namen'): string
    {
        $wert = strtr(mb_strtolower(trim($name)), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $wert = (string) preg_replace('/[^a-z0-9]+/', '-', $wert);
        $wert = trim($wert, '-');
        return $wert !== '' ? substr($wert, 0, 64) : $ersatz;
    }

    /** Schlüssel einer Zeile holen oder einmalig vergeben und festschreiben. */
    public static function schluesselSichern(string $tabelle, int $id, string $name, string $ersatz = ''): string
    {
        if (!in_array($tabelle, ['sites', 'floors', 'objekte', 'leitungen'], true)) {
            throw new \InvalidArgumentException('Unbekannte Tabelle.');
        }
        $zeile = Database::first('SELECT sync_key FROM ' . $tabelle . ' WHERE id = ?', [$id]);
        $vorhanden = (string) ($zeile['sync_key'] ?? '');
        if ($vorhanden !== '') {
            return $vorhanden;
        }
        $basis = self::schluessel($name, $ersatz !== '' ? $ersatz : 'eintrag-' . $id);
        $neu = $basis;
        for ($i = 2; (int) Database::scalar('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE sync_key = ? AND id <> ?', [$neu, $id]) > 0; $i++) {
            $neu = $basis . '-' . $i;
        }
        Database::execute('UPDATE ' . $tabelle . ' SET sync_key = ? WHERE id = ?', [$neu, $id]);
        return $neu;
    }

    // ------------------------------------------------------------ location.json

    /**
     * location.json anwenden: Standort, Etagen, Grundrisse, Maßstab.
     *
     * @param callable(string):?string $datei Pfad im Repository → Pfad auf der Platte
     * @return array{ok:bool,message:string,site_id:int}
     */
    public static function locationAnwenden(array $daten, callable $datei): array
    {
        if ((string) ($daten['art'] ?? '') !== 'location') {
            return ['ok' => false, 'message' => 'Das ist keine Location.', 'site_id' => 0];
        }
        if ((int) ($daten['format'] ?? 0) > self::FORMAT_LOCATION) {
            return ['ok' => false, 'message' => 'Die Location stammt aus einer neueren MasterAdmin-Fassung. Erst PowerManagement aktualisieren.', 'site_id' => 0];
        }
        $schluessel = (string) ($daten['schluessel'] ?? '');
        if ($schluessel === '') {
            return ['ok' => false, 'message' => 'Der Location fehlt der Schlüssel.', 'site_id' => 0];
        }

        $vorhanden = Database::first('SELECT id FROM sites WHERE sync_key = ?', [$schluessel]);
        $siteId = SiteService::saveSite($vorhanden === null ? null : (int) $vorhanden['id'], [
            'name'    => (string) ($daten['name'] ?? $schluessel),
            'address' => (string) ($daten['adresse'] ?? ''),
            'notes'   => (string) ($daten['notizen'] ?? ''),
        ]);
        Database::execute('UPDATE sites SET sync_key = ? WHERE id = ?', [$schluessel, $siteId]);

        $etagen = 0;
        $plaene = 0;
        foreach ((array) ($daten['etagen'] ?? []) as $etage) {
            $etageKey = (string) ($etage['schluessel'] ?? '');
            if ($etageKey === '') {
                continue;
            }
            $alt = Database::first('SELECT * FROM floors WHERE site_id = ? AND sync_key = ?', [$siteId, $etageKey]);
            $etageId = SiteService::saveFloor($alt === null ? null : (int) $alt['id'], [
                'site_id'    => $siteId,
                'name'       => (string) ($etage['name'] ?? $etageKey),
                'level_no'   => (int) ($etage['ebene'] ?? 0),
                'sort_order' => (int) ($etage['reihenfolge'] ?? 0),
                'scale_note' => (string) ($etage['massstab'] ?? ''),
                'notes'      => (string) ($etage['notizen'] ?? ''),
            ]);
            Database::execute('UPDATE floors SET sync_key = ? WHERE id = ?', [$etageKey, $etageId]);
            $etagen++;

            if (self::planUebernehmen($etageId, $etage['plan'] ?? null, $datei, $alt)) {
                $plaene++;
            }
            if (is_array($etage['referenz'] ?? null)) {
                PlanMassstabService::uebernehmen($etageId, $etage['referenz']);
            }
            // Was MasterAdmin auf der Etage führt – nur zur Orientierung.
            $fremd = [
                'dosen' => array_values(array_map(static fn ($d) => [
                    'label' => (string) ($d['label'] ?? ''), 'raum' => (string) ($d['raum'] ?? ''),
                    'x' => (float) ($d['x'] ?? 0), 'y' => (float) ($d['y'] ?? 0),
                ], array_filter((array) ($etage['dosen'] ?? []), static fn ($d) => isset($d['x'], $d['y'])))),
                'racks' => array_values(array_map(static fn ($r) => [
                    'name' => (string) ($r['name'] ?? ''), 'raum' => (string) ($r['raum'] ?? ''),
                    'x' => (float) ($r['x'] ?? 0), 'y' => (float) ($r['y'] ?? 0),
                    'watt' => array_sum(array_map(static fn ($e) => (int) ($e['watt'] ?? 0), (array) ($r['einbauten'] ?? []))),
                ], array_filter((array) ($etage['racks'] ?? []), static fn ($r) => isset($r['x'], $r['y'])))),
                'aps' => array_values(array_map(static fn ($a) => [
                    'name' => (string) ($a['name'] ?? ''), 'x' => (float) ($a['x'] ?? 0), 'y' => (float) ($a['y'] ?? 0),
                ], array_filter((array) ($etage['aps'] ?? []), static fn ($a) => isset($a['x'], $a['y'])))),
            ];
            Database::update('floors', $etageId, ['fremdbestand' => json_encode($fremd, JSON_UNESCAPED_UNICODE)]);
        }

        return [
            'ok'      => true,
            'site_id' => $siteId,
            'message' => sprintf('%s übernommen: %d Etage(n), %d Grundriss(e).', (string) ($daten['name'] ?? $schluessel), $etagen, $plaene),
        ];
    }

    /**
     * Plandatei übernehmen – nur wenn sie sich geändert hat, sonst würden bei
     * jedem Abgleich die Kacheln neu gerechnet.
     */
    private static function planUebernehmen(int $etageId, mixed $plan, callable $datei, ?array $alt): bool
    {
        if (!is_array($plan) || (string) ($plan['datei'] ?? '') === '') {
            return false;
        }
        $quelle = $datei((string) $plan['datei']);
        if ($quelle === null || !is_file($quelle)) {
            return false;
        }
        $endung = strtolower(pathinfo($quelle, PATHINFO_EXTENSION));
        if (!in_array($endung, ['pdf', 'png', 'jpg', 'jpeg', 'svg'], true)) {
            return false;
        }
        $name = 'sync' . $etageId . '_' . substr(hash_file('sha256', $quelle), 0, 16) . '.' . $endung;
        if ($alt !== null && (string) $alt['plan_file'] === $name && (int) $alt['plan_page'] === max(1, (int) ($plan['seite'] ?? 1))) {
            return true;
        }
        $ziel = SiteService::planDir() . '/' . $name;
        if (!is_file($ziel) && !copy($quelle, $ziel)) {
            return false;
        }
        Database::update('floors', $etageId, [
            'plan_file'          => $name,
            'plan_type'          => (string) ($plan['typ'] ?? $endung),
            'plan_page'          => max(1, (int) ($plan['seite'] ?? 1)),
            'plan_original_name' => (string) ($plan['originalname'] ?? $name),
            'plan_uploaded_at'   => gmdate('c'),
            'updated_at'         => gmdate('c'),
        ]);
        $etage = SiteService::floor($etageId);
        if ($etage !== null && PlanTileService::lohnt((string) SiteService::planPath($etage))) {
            PlanTileService::bauen($etage, true, false);
        }
        return true;
    }

    /**
     * location.json für das Hochladen: neu anlegen oder behutsam ergänzen.
     *
     * @param array<string,mixed>|null $vorhanden was im Repository steht
     * @return array{daten:array<string,mixed>,dateien:array<string,string>,geaendert:bool}
     */
    public static function locationFuerRepo(int $siteId, ?array $vorhanden): array
    {
        $site = SiteService::find($siteId);
        $schluessel = self::schluesselSichern('sites', $siteId, (string) $site['name'], 'standort-' . $siteId);
        $daten = $vorhanden ?? [
            'format'     => self::FORMAT_LOCATION,
            'art'        => 'location',
            'schluessel' => $schluessel,
            'name'       => (string) $site['name'],
            'adresse'    => (string) $site['address'],
            'notizen'    => (string) ($site['notes'] ?? ''),
            'etagen'     => [],
        ];
        $geaendert = $vorhanden === null;
        $dateien = [];
        $nachSchluessel = [];
        foreach ((array) $daten['etagen'] as $i => $etage) {
            $nachSchluessel[(string) ($etage['schluessel'] ?? '')] = $i;
        }

        foreach (SiteService::floors($siteId) as $etage) {
            $etageId = (int) $etage['id'];
            $etageKey = self::schluesselSichern('floors', $etageId, (string) $etage['name'], 'etage-' . $etageId);
            $plan = null;
            $pfad = SiteService::planPath($etage);
            if ($pfad !== null && is_file($pfad)) {
                $plan = [
                    'datei'        => 'plaene/' . $etageKey . '.' . strtolower(pathinfo($pfad, PATHINFO_EXTENSION)),
                    'typ'          => (string) $etage['plan_type'],
                    'seite'        => (int) $etage['plan_page'],
                    'originalname' => (string) $etage['plan_original_name'],
                ];
            }
            $referenz = PlanMassstabService::lesen($etage);

            if (!isset($nachSchluessel[$etageKey])) {
                $daten['etagen'][] = [
                    'schluessel'  => $etageKey,
                    'name'        => (string) $etage['name'],
                    'ebene'       => (int) $etage['level_no'],
                    'reihenfolge' => (int) $etage['sort_order'],
                    'massstab'    => (string) $etage['scale_note'],
                    'notizen'     => (string) ($etage['notes'] ?? ''),
                    'plan'        => $plan,
                    'referenz'    => $referenz,
                    'dosen'       => [],
                    'racks'       => [],
                    'aps'         => [],
                ];
                if ($plan !== null) {
                    $dateien[$plan['datei']] = $pfad;
                }
                $geaendert = true;
                continue;
            }
            $i = $nachSchluessel[$etageKey];
            // Ein Plan, der dort fehlt oder den es hier neuer gibt, wandert mit.
            $dortPlan = $daten['etagen'][$i]['plan'] ?? null;
            $hierNeuer = $plan !== null && !empty($etage['plan_uploaded_at']) && !empty($site['synced_at'])
                && strtotime((string) $etage['plan_uploaded_at']) > strtotime((string) $site['synced_at'])
                && !str_starts_with((string) $etage['plan_file'], 'sync');
            if ($plan !== null && (empty($dortPlan['datei']) || $hierNeuer)) {
                $daten['etagen'][$i]['plan'] = $plan;
                $dateien[$plan['datei']] = $pfad;
                $geaendert = true;
            }
            if ($referenz !== null && empty($daten['etagen'][$i]['referenz'])) {
                $daten['etagen'][$i]['referenz'] = $referenz;
                $geaendert = true;
            }
        }
        return ['daten' => $daten, 'dateien' => $dateien, 'geaendert' => $geaendert];
    }

    // ------------------------------------------------------------ strom.json

    /**
     * Die Feststromanschlüsse eines Standorts als strom.json.
     *
     * Nur fest installierte Anlagen mit ihren Anschlüssen, Schutzorganen und
     * Messgeräten. Mobile Anlagen und Leitungen bleiben hier.
     */
    public static function stromLesen(int $siteId): array
    {
        $site = SiteService::find($siteId);
        $siteKey = self::schluesselSichern('sites', $siteId, (string) $site['name'], 'standort-' . $siteId);
        $etagenKeys = [];
        foreach (SiteService::floors($siteId) as $etage) {
            $etagenKeys[(int) $etage['id']] = self::schluesselSichern('floors', (int) $etage['id'], (string) $etage['name'], 'etage-' . $etage['id']);
        }
        $stecker = AnlagenService::steckertypenNachId();
        $benutzteStecker = [];

        $anlagen = [];
        $anschlussRef = [];
        foreach (AnlagenService::liste(['site_id' => $siteId, 'fest' => true]) as $o) {
            $id = (int) $o['id'];
            $key = self::schluesselSichern('objekte', $id, (string) $o['name'], $o['art'] . '-' . $id);

            $schutzRef = [];
            $schutzorgane = [];
            foreach (SchutzService::liste($id) as $n => $s) {
                $schutzRef[(int) $s['id']] = 's' . ($n + 1);
            }
            foreach (SchutzService::liste($id) as $s) {
                $schutzorgane[] = [
                    'ref'            => $schutzRef[(int) $s['id']],
                    'art'            => $s['art'],
                    'bezeichnung'    => $s['bezeichnung'],
                    'nennstrom_a'    => $s['nennstrom_a'] !== null ? (float) $s['nennstrom_a'] : null,
                    'charakteristik' => $s['charakteristik'],
                    'fehlerstrom_ma' => $s['fehlerstrom_ma'] !== null ? (int) $s['fehlerstrom_ma'] : null,
                    'rcd_typ'        => $s['rcd_typ'],
                    'pole'           => (int) $s['pole'],
                    'vor'            => $s['vorgeschaltet_id'] !== null ? ($schutzRef[(int) $s['vorgeschaltet_id']] ?? null) : null,
                    'notizen'        => (string) ($s['notizen'] ?? ''),
                ];
            }
            $anschluesse = [];
            foreach (Database::select('SELECT * FROM anschluesse WHERE objekt_id = ? ORDER BY richtung, nr', [$id]) as $a) {
                $typ = $stecker[(int) $a['steckertyp_id']] ?? null;
                if ($typ === null) {
                    continue;
                }
                $benutzteStecker[(int) $typ['id']] = $typ;
                $anschlussRef[(int) $a['id']] = $a['richtung'] . ':' . (int) $a['nr'];
                $anschluesse[] = [
                    'richtung'    => $a['richtung'],
                    'stecker'     => $typ['schluessel'],
                    'nr'          => (int) $a['nr'],
                    'bezeichnung' => $a['bezeichnung'],
                    'schutz'      => $a['schutzorgan_id'] !== null ? ($schutzRef[(int) $a['schutzorgan_id']] ?? null) : null,
                    'phase'       => $a['phase'],
                    'notizen'     => (string) ($a['notizen'] ?? ''),
                ];
            }
            $messpunkte = [];
            foreach (Database::select('SELECT * FROM messpunkte WHERE objekt_id = ? ORDER BY id', [$id]) as $m) {
                $messpunkte[] = [
                    'geraet'      => $m['geraet'],
                    'kanal'       => $m['kanal'],
                    'rolle'       => $m['rolle'],
                    'bezeichnung' => $m['bezeichnung'],
                    'anschluss'   => $m['anschluss_id'] !== null ? ($anschlussRef[(int) $m['anschluss_id']] ?? null) : null,
                    'schutz'      => $m['schutzorgan_id'] !== null ? ($schutzRef[(int) $m['schutzorgan_id']] ?? null) : null,
                ];
            }
            $anlagen[] = [
                'schluessel'       => $key,
                'art'              => $o['art'],
                'fest'             => true,
                'name'             => $o['name'],
                'kennung'          => $o['kennung'],
                'etage'            => $o['floor_id'] !== null ? ($etagenKeys[(int) $o['floor_id']] ?? null) : null,
                'x'                => $o['plan_x'] !== null ? (float) $o['plan_x'] : null,
                'y'                => $o['plan_y'] !== null ? (float) $o['plan_y'] : null,
                'hersteller'       => $o['hersteller'],
                'modell'           => $o['modell'],
                'seriennummer'     => $o['seriennummer'],
                'nennleistung_kva' => $o['nennleistung_kva'] !== null ? (float) $o['nennleistung_kva'] : null,
                'nennstrom_a'      => $o['nennstrom_a'] !== null ? (float) $o['nennstrom_a'] : null,
                'spannung_v'       => (int) $o['spannung_v'],
                'phasen'           => (int) $o['phasen'],
                'status'           => $o['status'],
                'eigenschaften'    => $o['eigenschaften'],
                'steuerung_gesperrt' => (int) $o['steuerung_gesperrt'] === 1,
                'notizen'          => (string) ($o['notizen'] ?? ''),
                'schutzorgane'     => $schutzorgane,
                'anschluesse'      => $anschluesse,
                'messpunkte'       => $messpunkte,
            ];
        }

        return [
            'format'       => self::FORMAT_STROM,
            'art'          => 'strom',
            'schluessel'   => $siteKey,
            'stand'        => gmdate('c'),
            'von'          => (string) Config::get('app.name', 'PowerManagement'),
            'steckertypen' => array_values(array_map(static fn ($t) => [
                'schluessel' => $t['schluessel'], 'name' => $t['name'], 'strom_a' => (float) $t['strom_a'],
                'phasen' => (int) $t['phasen'], 'spannung_v' => (int) $t['spannung_v'], 'farbe' => $t['farbe'],
            ], $benutzteStecker)),
            'anlagen'      => $anlagen,
        ];
    }

    /**
     * Die Anlagen einer strom.json, die als Feststromanschluss gelten.
     *
     * Ab Format 2 steht nichts anderes darin. Format 1 enthielt noch die
     * ganze Planung; davon zählen die Stromanschlüsse – dieselbe Regel, mit
     * der die Migration 006 vorhandene Anlagen eingeordnet hat.
     *
     * @return list<array<string,mixed>>
     */
    public static function festeAnlagen(array $daten): array
    {
        $format = (int) ($daten['format'] ?? 0);
        return array_values(array_filter(
            (array) ($daten['anlagen'] ?? []),
            static fn ($a) => is_array($a) && ($format >= 2 ? !empty($a['fest']) : ($a['art'] ?? '') === 'anschluss')
        ));
    }

    /**
     * Die Feststromanschlüsse aus strom.json auf einen Standort anwenden.
     *
     * Wie in MasterAdmin wird beim Übernehmen nichts gelöscht, was hier
     * zusätzlich steht – schon gar nicht die mobilen Anlagen und Kabel der
     * laufenden Planung. Ein Feststromanschluss aus der Datei bekommt seine
     * Anschlüsse und Schutzorgane so, wie sie dort stehen; Kabel, die hier
     * an einem seiner Abgänge hängen, bleiben über „richtung:nr“ daran.
     *
     * @return array{ok:bool,message:string}
     */
    public static function stromAnwenden(int $siteId, array $daten): array
    {
        if ((string) ($daten['art'] ?? '') !== 'strom') {
            return ['ok' => false, 'message' => 'strom.json ist ungültig.'];
        }
        if ((int) ($daten['format'] ?? 0) > self::FORMAT_STROM) {
            return ['ok' => false, 'message' => 'Die Feststromanschlüsse stammen aus einer neueren PowerManagement-Fassung.'];
        }
        // Steckertypen, die hier fehlen, anlegen.
        $stecker = [];
        foreach (AnlagenService::steckertypen() as $t) {
            $stecker[(string) $t['schluessel']] = (int) $t['id'];
        }
        foreach ((array) ($daten['steckertypen'] ?? []) as $t) {
            $k = (string) ($t['schluessel'] ?? '');
            if ($k === '' || isset($stecker[$k])) {
                continue;
            }
            $stecker[$k] = Database::insert('steckertypen', [
                'schluessel' => self::schluessel($k, 'stecker'), 'name' => (string) ($t['name'] ?? $k),
                'strom_a' => (float) ($t['strom_a'] ?? 16), 'phasen' => (int) ($t['phasen'] ?? 3) === 1 ? 1 : 3,
                'spannung_v' => (int) ($t['spannung_v'] ?? 400), 'farbe' => (string) ($t['farbe'] ?? '#64748b'),
                'reihenfolge' => 500, 'ist_standard' => 0, 'created_at' => gmdate('c'),
            ]);
        }

        $etagen = [];
        foreach (Database::select('SELECT id, sync_key FROM floors WHERE site_id = ?', [$siteId]) as $e) {
            $etagen[(string) $e['sync_key']] = (int) $e['id'];
        }

        $anlagenZahl = 0;
        foreach (self::festeAnlagen($daten) as $a) {
            $key = (string) ($a['schluessel'] ?? '');
            if ($key === '' || !isset(AnlagenService::ARTEN[$a['art'] ?? ''])) {
                continue;
            }
            $floorId = isset($a['etage']) && $a['etage'] !== null ? ($etagen[(string) $a['etage']] ?? null) : null;
            $vorhanden = Database::first('SELECT id FROM objekte WHERE sync_key = ?', [$key]);
            $ergebnis = AnlagenService::speichern($vorhanden !== null ? (int) $vorhanden['id'] : null, [
                'art' => $a['art'], 'name' => (string) ($a['name'] ?? $key), 'kennung' => (string) ($a['kennung'] ?? ''),
                'site_id' => $siteId, 'floor_id' => $floorId,
                'hersteller' => (string) ($a['hersteller'] ?? ''), 'modell' => (string) ($a['modell'] ?? ''),
                'seriennummer' => (string) ($a['seriennummer'] ?? ''),
                'nennleistung_kva' => $a['nennleistung_kva'] ?? '', 'nennstrom_a' => $a['nennstrom_a'] ?? '',
                'spannung_v' => $a['spannung_v'] ?? 400, 'phasen' => $a['phasen'] ?? 3, 'status' => (string) ($a['status'] ?? 'geplant'),
                'eigenschaften' => (array) ($a['eigenschaften'] ?? []),
                'steuerung_gesperrt' => !empty($a['steuerung_gesperrt']),
                'notizen' => (string) ($a['notizen'] ?? ''),
                'fest' => 1,
                'ohne_anschluesse' => true,
            ]);
            if (!$ergebnis['ok']) {
                continue;
            }
            $id = (int) $ergebnis['id'];
            Database::update('objekte', $id, [
                'sync_key' => $key,
                'plan_x' => $floorId !== null && isset($a['x']) ? (float) $a['x'] : null,
                'plan_y' => $floorId !== null && isset($a['y']) ? (float) $a['y'] : null,
            ]);
            self::belegungErsetzen($id, $a, $stecker);
            $anlagenZahl++;
        }
        \App\Auth\Zugriff::vergessen();
        return ['ok' => true, 'message' => $anlagenZahl === 1 ? '1 Feststromanschluss übernommen.' : $anlagenZahl . ' Feststromanschlüsse übernommen.'];
    }

    /**
     * Schutzorgane, Anschlüsse und Messpunkte einer Anlage aus der Datei
     * setzen. Kabel an dieser Anlage behalten ihren Anschluss über
     * „richtung:nr“.
     *
     * @param array<string,int> $stecker schluessel => id
     * @return array<string,int> „richtung:nr“ => neue anschluss_id
     */
    private static function belegungErsetzen(int $objektId, array $a, array $stecker): array
    {
        $alt = [];
        foreach (Database::select('SELECT id, richtung, nr FROM anschluesse WHERE objekt_id = ?', [$objektId]) as $r) {
            $alt[(int) $r['id']] = $r['richtung'] . ':' . (int) $r['nr'];
        }
        Database::execute('DELETE FROM schutzorgane WHERE objekt_id = ?', [$objektId]);
        Database::execute('DELETE FROM anschluesse WHERE objekt_id = ?', [$objektId]);

        $schutzIds = [];
        foreach ((array) ($a['schutzorgane'] ?? []) as $n => $s) {
            $schutzIds[(string) ($s['ref'] ?? 's' . ($n + 1))] = Database::insert('schutzorgane', [
                'objekt_id' => $objektId, 'art' => isset(SchutzService::ARTEN[$s['art'] ?? '']) ? $s['art'] : 'sonstiges',
                'bezeichnung' => mb_substr((string) ($s['bezeichnung'] ?? ''), 0, 64),
                'nennstrom_a' => isset($s['nennstrom_a']) ? (float) $s['nennstrom_a'] : null,
                'charakteristik' => mb_substr((string) ($s['charakteristik'] ?? ''), 0, 8),
                'fehlerstrom_ma' => isset($s['fehlerstrom_ma']) ? (int) $s['fehlerstrom_ma'] : null,
                'rcd_typ' => mb_substr((string) ($s['rcd_typ'] ?? ''), 0, 8), 'pole' => (int) ($s['pole'] ?? 3),
                'vorgeschaltet_id' => null, 'notizen' => (string) ($s['notizen'] ?? ''), 'reihenfolge' => $n + 1,
                'created_at' => gmdate('c'),
            ]);
        }
        foreach ((array) ($a['schutzorgane'] ?? []) as $n => $s) {
            $ref = (string) ($s['ref'] ?? 's' . ($n + 1));
            if (!empty($s['vor']) && isset($schutzIds[(string) $s['vor']])) {
                Database::update('schutzorgane', $schutzIds[$ref], ['vorgeschaltet_id' => $schutzIds[(string) $s['vor']]]);
            }
        }

        $neu = [];
        foreach ((array) ($a['anschluesse'] ?? []) as $an) {
            $typ = $stecker[(string) ($an['stecker'] ?? '')] ?? null;
            if ($typ === null) {
                continue;
            }
            $richtung = ($an['richtung'] ?? '') === 'eingang' ? 'eingang' : 'abgang';
            $nr = max(1, (int) ($an['nr'] ?? 1));
            $neu[$richtung . ':' . $nr] = Database::insert('anschluesse', [
                'objekt_id' => $objektId, 'richtung' => $richtung, 'steckertyp_id' => $typ, 'nr' => $nr,
                'bezeichnung' => mb_substr((string) ($an['bezeichnung'] ?? ''), 0, 64),
                'schutzorgan_id' => !empty($an['schutz']) ? ($schutzIds[(string) $an['schutz']] ?? null) : null,
                'phase' => mb_substr((string) ($an['phase'] ?? ''), 0, 8), 'notizen' => (string) ($an['notizen'] ?? ''),
                'reihenfolge' => $nr, 'created_at' => gmdate('c'),
            ]);
        }
        // Verweise von Kabeln, die hier nicht aus der Datei kommen, umhängen.
        foreach ($alt as $altId => $ref) {
            $neuId = $neu[$ref] ?? null;
            Database::execute('UPDATE leitungen SET von_anschluss_id = ? WHERE von_anschluss_id = ?', [$neuId, $altId]);
            Database::execute('UPDATE leitungen SET zu_anschluss_id = ? WHERE zu_anschluss_id = ?', [$neuId, $altId]);
        }

        if (array_key_exists('messpunkte', $a)) {
            Database::execute('DELETE FROM messpunkte WHERE objekt_id = ?', [$objektId]);
            foreach ((array) $a['messpunkte'] as $m) {
                if (trim((string) ($m['geraet'] ?? '')) === '') {
                    continue;
                }
                Database::insert('messpunkte', [
                    'objekt_id' => $objektId,
                    'anschluss_id' => !empty($m['anschluss']) ? ($neu[(string) $m['anschluss']] ?? null) : null,
                    'schutzorgan_id' => !empty($m['schutz']) ? ($schutzIds[(string) $m['schutz']] ?? null) : null,
                    'geraet' => mb_substr((string) $m['geraet'], 0, 128), 'kanal' => mb_substr((string) ($m['kanal'] ?? ''), 0, 64),
                    'rolle' => isset(MesspunktService::ROLLEN[$m['rolle'] ?? '']) ? $m['rolle'] : 'einspeisung',
                    'bezeichnung' => mb_substr((string) ($m['bezeichnung'] ?? ''), 0, 190), 'created_at' => gmdate('c'),
                ]);
            }
        }
        return $neu;
    }
}
