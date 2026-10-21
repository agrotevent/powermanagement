<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Messung\Messdaten;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Aufbau: der Übergang von der Planung zum echten Aufbau.
 *
 * Beim Start wird der Plan des Standorts festgehalten – was mitgebracht und
 * aufgebaut wird: mobile Anlagen, mobile Kabel, Kabelbrücken,
 * Kraftstoffschläuche und die Materialliste. Vor Ort wird jede Position per Klick bestätigt („wie
 * geplant“), als geändert markiert (mit Notiz, beim Kabel mit der echten
 * Länge) oder als entfallen. Was beim Aufbau neu dazukommt, zählt als
 * zusätzlich. „Aufbau fertig“ hält den Ist-Stand fest; die Veranstaltung
 * läuft, bis sie beendet wird.
 *
 * Der Abgleich vergleicht danach Plan und Ist – Anlagen, Kabellängen,
 * Material – und die gemessene Last mit dem, wofür geplant war. Daraus
 * werden Hinweise für die nächste Planung: kleinerer Verteiler reicht,
 * Catering am Limit, Kabelreserve zu knapp.
 */
final class AufbauService
{
    public const STATUS = [
        'aufbau'  => 'Aufbau läuft',
        'betrieb' => 'in Betrieb',
        'beendet' => 'beendet',
    ];

    public const POSTEN = [
        'offen'       => 'offen',
        'bestaetigt'  => 'wie geplant',
        'geaendert'   => 'geändert',
        'entfallen'   => 'entfallen',
        'zusaetzlich' => 'zusätzlich',
    ];

    /** Ab dieser Abweichung (Meter) gilt eine Anlage als woanders aufgebaut. */
    public const VERSCHOBEN_AB_M = 5.0;

    // ------------------------------------------------------------ Lesen

    /** @return list<array<string,mixed>> sichtbare Aufbauten, laufende zuerst */
    public static function liste(?int $siteId = null): array
    {
        $rows = Database::select(
            "SELECT a.id, a.site_id, a.name, a.status, a.gestartet_am, a.gestartet_von, a.betrieb_ab, a.beendet_am, s.name AS site_name,
                    (SELECT COUNT(*) FROM aufbau_posten p WHERE p.aufbau_id = a.id) AS posten_zahl
             FROM aufbauten a LEFT JOIN sites s ON s.id = a.site_id"
            . ($siteId !== null ? ' WHERE a.site_id = ' . $siteId : '')
            . " ORDER BY a.status = 'beendet', a.gestartet_am DESC"
        );
        return array_values(array_filter($rows, static fn ($a) => Zugriff::siehtStandort((int) $a['site_id'])));
    }

    public static function find(int $id): ?array
    {
        $a = Database::first('SELECT a.*, s.name AS site_name FROM aufbauten a LEFT JOIN sites s ON s.id = a.site_id WHERE a.id = ?', [$id]);
        if ($a === null) {
            return null;
        }
        $a['plan'] = json_decode((string) ($a['plan'] ?? ''), true) ?: ['objekte' => [], 'leitungen' => [], 'material' => null];
        $a['ist'] = json_decode((string) ($a['ist'] ?? ''), true) ?: null;
        return $a;
    }

    /** Der laufende Aufbau eines Standorts (Aufbau oder Betrieb). */
    public static function aktiv(int $siteId): ?array
    {
        $id = Database::scalar("SELECT id FROM aufbauten WHERE site_id = ? AND status <> 'beendet' ORDER BY id DESC LIMIT 1", [$siteId]);
        return $id === false || $id === null ? null : self::find((int) $id);
    }

    /** @return array<string,array<string,mixed>> „o:12“ / „l:34“ => Posten */
    public static function posten(int $aufbauId): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM aufbau_posten WHERE aufbau_id = ?', [$aufbauId]) as $p) {
            $out[($p['art'] === 'objekt' ? 'o:' : 'l:') . (int) $p['ref_id']] = $p;
        }
        return $out;
    }

    /** Leitungsarten, die aufgebaut werden – Kabelwege sind nur Trassen. */
    public const LEITUNGEN = ['kabel', 'kabelbruecke', 'kraftstoff'];

    /**
     * Was auf dem Standort aufgebaut wird: mobile Anlagen, mobile Kabel,
     * Kabelbrücken und Kraftstoffschläuche, mit allem, was Materialliste und
     * Abgleich brauchen.
     *
     * @return array{zeit:string,objekte:list<array<string,mixed>>,leitungen:list<array<string,mixed>>,material:array<string,mixed>}
     */
    public static function momentaufnahme(int $siteId): array
    {
        $objekte = array_values(array_filter(AnlagenService::liste(['site_id' => $siteId]), static fn ($o) => (int) $o['fest'] === 0));
        $leitungen = [];
        foreach (SiteService::floors($siteId) as $f) {
            foreach (LeitungService::fuerEtage((int) $f['id']) as $l) {
                if ((int) $l['fest'] === 0 && in_array($l['art'], self::LEITUNGEN, true)) {
                    $leitungen[] = $l;
                }
            }
        }
        $material = MaterialService::berechnen($objekte, $leitungen);
        return [
            'zeit'      => gmdate('c'),
            'objekte'   => array_map(static fn ($o) => [
                'id' => (int) $o['id'], 'name' => $o['name'], 'art' => $o['art'], 'art_anzeige' => $o['art_anzeige'], 'modell' => (string) $o['modell'],
                'hersteller' => (string) $o['hersteller'], 'kennung' => (string) $o['kennung'], 'fest' => 0,
                'floor_id' => $o['floor_id'] !== null ? (int) $o['floor_id'] : null,
                'plan_x' => $o['plan_x'] !== null ? (float) $o['plan_x'] : null, 'plan_y' => $o['plan_y'] !== null ? (float) $o['plan_y'] : null,
                'nennstrom_a' => $o['nennstrom_a'] !== null ? (float) $o['nennstrom_a'] : null,
            ], $objekte),
            'leitungen' => array_map(static fn ($l) => [
                'id' => (int) $l['id'], 'art' => $l['art'], 'fest' => 0, 'name' => (string) $l['name'], 'floor_id' => (int) $l['floor_id'],
                'von' => $l['von_objekt_id'] !== null ? (int) $l['von_objekt_id'] : null, 'zu' => $l['zu_objekt_id'] !== null ? (int) $l['zu_objekt_id'] : null,
                'von_name' => $l['von_name'], 'zu_name' => $l['zu_name'], 'von_typ' => $l['von_typ'], 'zu_typ' => $l['zu_typ'],
                'von_strom_a' => $l['von_strom_a'], 'zu_strom_a' => $l['zu_strom_a'], 'kabeltyp' => (string) $l['kabeltyp'], 'adern' => $l['adern'],
                'querschnitt_mm2' => $l['querschnitt_mm2'], 'laenge' => $l['laenge'] !== null ? round((float) $l['laenge'], 1) : null,
                'laenge_m' => $l['laenge_m'], 'anzahl' => (int) $l['anzahl'], 'modell' => (string) $l['modell'],
            ], $leitungen),
            'material'  => $material,
        ];
    }

    // ------------------------------------------------------------ Schreiben

    /** @return array{ok:bool,message:string,id?:int} */
    public static function starten(int $siteId, string $name): array
    {
        if (SiteService::find($siteId) === null) {
            return ['ok' => false, 'message' => 'Diesen Standort gibt es nicht.'];
        }
        $laufend = self::aktiv($siteId);
        if ($laufend !== null) {
            return ['ok' => false, 'message' => 'Auf diesem Standort läuft schon „' . $laufend['name'] . '“.'];
        }
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 190) : 'Aufbau ' . Zeit::anzeige(gmdate('c'), 'd.m.Y');
        $plan = self::momentaufnahme($siteId);
        $jetzt = gmdate('c');
        $id = Database::insert('aufbauten', [
            'site_id' => $siteId, 'name' => $name, 'status' => 'aufbau', 'gestartet_am' => $jetzt,
            'gestartet_von' => (string) (Auth::user()['username'] ?? 'system'), 'betrieb_ab' => null, 'beendet_am' => null,
            'plan' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'ist' => null, 'notizen' => '', 'created_at' => $jetzt, 'updated_at' => $jetzt,
        ]);
        Audit::log('aufbau.start', 'site', (string) $siteId, ['aufbau' => $id, 'name' => $name]);
        return ['ok' => true, 'message' => '„' . $name . '“ gestartet: ' . count($plan['objekte']) . ' Anlagen und ' . count($plan['leitungen'])
            . ' Kabel, Kabelbrücken und Schläuche zum Aufbauen.', 'id' => $id];
    }

    /**
     * Eine Position bestätigen, als geändert oder entfallen markieren – oder
     * mit „offen“ zurücknehmen.
     *
     * @return array{ok:bool,message:string}
     */
    public static function postenSetzen(int $aufbauId, string $art, int $refId, string $status, ?float $laengeIst = null, string $notiz = ''): array
    {
        $aufbau = self::find($aufbauId);
        if ($aufbau === null || $aufbau['status'] === 'beendet') {
            return ['ok' => false, 'message' => 'Dieser Aufbau läuft nicht (mehr).'];
        }
        if (!in_array($art, ['objekt', 'leitung'], true) || !in_array($status, ['offen', 'bestaetigt', 'geaendert', 'entfallen'], true)) {
            return ['ok' => false, 'message' => 'Unbekannte Angabe.'];
        }
        $name = self::refName($aufbau, $art, $refId);
        if ($name === null) {
            return ['ok' => false, 'message' => 'Das gehört nicht zu diesem Aufbau.'];
        }
        Database::execute('DELETE FROM aufbau_posten WHERE aufbau_id = ? AND art = ? AND ref_id = ?', [$aufbauId, $art, $refId]);
        if ($status !== 'offen') {
            Database::insert('aufbau_posten', [
                'aufbau_id' => $aufbauId, 'art' => $art, 'ref_id' => $refId, 'status' => $status,
                'laenge_ist' => $art === 'leitung' && $laengeIst !== null && $laengeIst > 0 ? round($laengeIst, 1) : null,
                'notiz' => mb_substr(trim($notiz), 0, 500), 'am' => gmdate('c'), 'von' => (string) (Auth::user()['username'] ?? 'system'),
            ]);
            if ($art === 'objekt' && $status !== 'entfallen') {
                Database::execute("UPDATE objekte SET status = 'aufgebaut', updated_at = ? WHERE id = ? AND status = 'geplant'", [gmdate('c'), $refId]);
            }
        }
        return ['ok' => true, 'message' => '„' . $name . '“: ' . self::POSTEN[$status] . '.'];
    }

    /** Name einer Position – aus dem Plan oder (zusätzlich) vom Standort. */
    private static function refName(array $aufbau, string $art, int $refId): ?string
    {
        foreach ($aufbau['plan'][$art === 'objekt' ? 'objekte' : 'leitungen'] ?? [] as $x) {
            if ((int) $x['id'] === $refId) {
                return $art === 'objekt' ? (string) $x['name'] : self::leitungName($x);
            }
        }
        // Was nach dem Start dazukam – nur Mobiles, Festes wird nicht aufgebaut.
        if ($art === 'objekt') {
            $o = Database::first('SELECT name FROM objekte WHERE id = ? AND site_id = ? AND ist_vorlage = 0 AND fest = 0', [$refId, (int) $aufbau['site_id']]);
            return $o !== null ? (string) $o['name'] : null;
        }
        $l = LeitungService::find($refId);
        return $l !== null && (int) $l['fest'] === 0 && in_array($l['art'], self::LEITUNGEN, true)
            && (int) Database::scalar('SELECT site_id FROM floors WHERE id = ?', [(int) $l['floor_id']]) === (int) $aufbau['site_id']
            ? self::leitungName($l) : null;
    }

    public static function leitungName(array $l): string
    {
        if (trim((string) $l['name']) !== '') {
            return (string) $l['name'];
        }
        if ($l['art'] === 'kabelbruecke') {
            return 'Kabelbrücke' . ($l['modell'] !== '' ? ' ' . $l['modell'] : '');
        }
        if ($l['art'] === 'kraftstoff') {
            return 'Schlauch ' . ($l['von_name'] ?? '?') . ' → ' . ($l['zu_name'] ?? '?');
        }
        return ($l['von_name'] ?? '?') . ' → ' . ($l['zu_name'] ?? 'offenes Ende');
    }

    /** Was in der Liste unter einer Leitung steht: Stecker und Kabeltyp, Elemente, Schlauch mit Kupplung. */
    private static function leitungText(array $l): string
    {
        return match ((string) $l['art']) {
            'kabelbruecke' => $l['anzahl'] . ' Elemente',
            'kraftstoff'   => trim(max(1, (int) $l['anzahl']) . ' × ' . trim((string) $l['kabeltyp'] . ' ' . (string) $l['modell'])),
            default        => trim(($l['von_typ'] ?? $l['zu_typ'] ?? '') . ' ' . $l['kabeltyp']),
        };
    }

    /** „Aufbau fertig“: Ist-Stand festhalten, ab jetzt läuft der Betrieb. */
    public static function fertig(int $id): array
    {
        $a = self::find($id);
        if ($a === null || $a['status'] !== 'aufbau') {
            return ['ok' => false, 'message' => 'Dieser Aufbau läuft nicht.'];
        }
        $offen = self::uebersicht($a)['zaehler']['offen'];
        Database::update('aufbauten', $id, [
            'status' => 'betrieb', 'betrieb_ab' => gmdate('c'), 'updated_at' => gmdate('c'),
            'ist' => json_encode(self::momentaufnahme((int) $a['site_id']), JSON_UNESCAPED_UNICODE),
        ]);
        Audit::log('aufbau.fertig', 'site', (string) $a['site_id'], ['aufbau' => $id]);
        return ['ok' => true, 'message' => 'Aufbau fertig – ab jetzt läuft der Betrieb.' . ($offen > 0 ? ' ' . $offen . ' Position(en) blieben unbestätigt.' : '')];
    }

    /**
     * Veranstaltung beenden. Auf Wunsch werden alle Inventar-Stücke auf dem
     * Standort abgebaut – ihre Shellys messen dann nicht mehr dort.
     */
    public static function beenden(int $id, bool $abbauen): array
    {
        $a = self::find($id);
        if ($a === null || $a['status'] === 'beendet') {
            return ['ok' => false, 'message' => 'Dieser Aufbau läuft nicht.'];
        }
        $jetzt = gmdate('c');
        $felder = ['status' => 'beendet', 'beendet_am' => $jetzt, 'updated_at' => $jetzt];
        if ($a['ist'] === null) {
            $felder['ist'] = json_encode(self::momentaufnahme((int) $a['site_id']), JSON_UNESCAPED_UNICODE);
            $felder['betrieb_ab'] = $jetzt;
        }
        Database::update('aufbauten', $id, $felder);
        $abgebaut = 0;
        if ($abbauen) {
            foreach (Database::select(
                'SELECT e.id FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id WHERE o.site_id = ? AND e.bis IS NULL',
                [(int) $a['site_id']]
            ) as $e) {
                InventarService::beenden((int) $e['id'], $jetzt);
                $abgebaut++;
            }
        }
        Audit::log('aufbau.ende', 'site', (string) $a['site_id'], ['aufbau' => $id, 'abgebaut' => $abgebaut]);
        return ['ok' => true, 'message' => '„' . $a['name'] . '“ beendet.' . ($abgebaut > 0 ? ' ' . $abgebaut . ' Inventar-Stück(e) abgebaut.' : '')];
    }

    public static function loeschen(int $id): array
    {
        $a = self::find($id);
        if ($a === null) {
            return ['ok' => false, 'message' => 'Diesen Aufbau gibt es nicht.'];
        }
        Database::execute('DELETE FROM aufbau_posten WHERE aufbau_id = ?', [$id]);
        BerichtService::aufbauEntfernt($id);
        Database::delete('aufbauten', $id);
        Audit::log('aufbau.delete', 'site', (string) $a['site_id'], ['aufbau' => $id, 'name' => $a['name']]);
        return ['ok' => true, 'message' => '„' . $a['name'] . '“ gelöscht.'];
    }

    public static function standortEntfernt(int $siteId): void
    {
        foreach (Database::select('SELECT id FROM aufbauten WHERE site_id = ?', [$siteId]) as $a) {
            BerichtService::aufbauEntfernt((int) $a['id']);
        }
        Database::execute('DELETE FROM aufbau_posten WHERE aufbau_id IN (SELECT id FROM aufbauten WHERE site_id = ?)', [$siteId]);
        Database::execute('DELETE FROM aufbauten WHERE site_id = ?', [$siteId]);
    }

    // ------------------------------------------------------------ Stand des Aufbaus

    /**
     * Jede Position mit ihrem Stand – geplante aus dem Plan, zusätzliche vom
     * Standort (was nach dem Start dazukam).
     *
     * @return array{eintraege:list<array<string,mixed>>,zaehler:array<string,int>,status:array<string,string>}
     */
    public static function uebersicht(array $aufbau): array
    {
        $posten = self::posten((int) $aufbau['id']);
        $jetzt = $aufbau['ist'] ?? self::momentaufnahme((int) $aufbau['site_id']);
        $eintraege = [];
        $imPlan = ['o' => [], 'l' => []];
        foreach ($aufbau['plan']['objekte'] ?? [] as $o) {
            $imPlan['o'][(int) $o['id']] = true;
            $p = $posten['o:' . (int) $o['id']] ?? null;
            $eintraege[] = ['schluessel' => 'o:' . (int) $o['id'], 'art' => 'objekt', 'id' => (int) $o['id'], 'name' => $o['name'],
                'text' => $o['art_anzeige'] . ($o['modell'] !== '' ? ' · ' . $o['modell'] : ''), 'status' => $p['status'] ?? 'offen',
                'notiz' => (string) ($p['notiz'] ?? ''), 'laenge_plan' => null, 'laenge_ist' => null];
        }
        foreach ($aufbau['plan']['leitungen'] ?? [] as $l) {
            $imPlan['l'][(int) $l['id']] = true;
            $p = $posten['l:' . (int) $l['id']] ?? null;
            $eintraege[] = ['schluessel' => 'l:' . (int) $l['id'], 'art' => 'leitung', 'id' => (int) $l['id'], 'name' => self::leitungName($l),
                'text' => self::leitungText($l), 'leitung' => (string) $l['art'],
                'status' => $p['status'] ?? 'offen', 'notiz' => (string) ($p['notiz'] ?? ''),
                'laenge_plan' => $l['laenge'], 'laenge_ist' => $p['laenge_ist'] ?? null];
        }
        foreach ($jetzt['objekte'] ?? [] as $o) {
            if (!isset($imPlan['o'][(int) $o['id']])) {
                $eintraege[] = ['schluessel' => 'o:' . (int) $o['id'], 'art' => 'objekt', 'id' => (int) $o['id'], 'name' => $o['name'],
                    'text' => $o['art_anzeige'], 'status' => 'zusaetzlich', 'notiz' => '', 'laenge_plan' => null, 'laenge_ist' => null];
            }
        }
        foreach ($jetzt['leitungen'] ?? [] as $l) {
            if (!isset($imPlan['l'][(int) $l['id']])) {
                $eintraege[] = ['schluessel' => 'l:' . (int) $l['id'], 'art' => 'leitung', 'id' => (int) $l['id'], 'name' => self::leitungName($l),
                    'text' => self::leitungText($l), 'leitung' => (string) $l['art'], 'status' => 'zusaetzlich', 'notiz' => '',
                    'laenge_plan' => null, 'laenge_ist' => $l['laenge']];
            }
        }
        $zaehler = array_fill_keys(array_keys(self::POSTEN), 0);
        $status = [];
        foreach ($eintraege as $e) {
            $zaehler[$e['status']]++;
            $status[$e['schluessel']] = $e['status'];
        }
        return ['eintraege' => $eintraege, 'zaehler' => $zaehler, 'status' => $status];
    }

    /** Kurzform für die Karte: Stand je Position und Zähler. */
    public static function kurz(?array $a): ?array
    {
        if ($a === null) {
            return null;
        }
        $u = self::uebersicht($a);
        return ['id' => (int) $a['id'], 'name' => $a['name'], 'status' => $a['status'], 'posten' => $u['status'], 'zaehler' => $u['zaehler'],
            'notizen' => array_column(array_filter($u['eintraege'], static fn ($e) => $e['notiz'] !== ''), 'notiz', 'schluessel'),
            'laengen' => array_column(array_filter($u['eintraege'], static fn ($e) => $e['laenge_ist'] !== null && $e['status'] !== 'zusaetzlich'), 'laenge_ist', 'schluessel')];
    }

    // ------------------------------------------------------------ Abgleich

    /**
     * Plan und Ist nebeneinander – und was daraus für die nächste Planung folgt.
     *
     * @return array<string,mixed>
     */
    public static function abgleich(array $aufbau): array
    {
        $siteId = (int) $aufbau['site_id'];
        $plan = $aufbau['plan'];
        $ist = $aufbau['ist'] ?? self::momentaufnahme($siteId);
        $posten = self::posten((int) $aufbau['id']);
        $istObjekte = [];
        foreach ($ist['objekte'] ?? [] as $o) {
            $istObjekte[(int) $o['id']] = $o;
        }
        $istLeitungen = [];
        foreach ($ist['leitungen'] ?? [] as $l) {
            $istLeitungen[(int) $l['id']] = $l;
        }
        $massstab = [];
        $meter = static function (?int $floor, array $a, array $b) use (&$massstab): ?float {
            if ($floor === null) {
                return null;
            }
            $massstab[$floor] ??= PlanMassstabService::fuerEtage($floor);
            return PlanMassstabService::meter([$a, $b], $massstab[$floor]);
        };

        // Anlagen
        $anlagen = [];
        foreach ($plan['objekte'] ?? [] as $o) {
            $jetzt = $istObjekte[(int) $o['id']] ?? null;
            $p = $posten['o:' . (int) $o['id']] ?? null;
            $abweichungen = [];
            $weg = null;
            if ($jetzt === null) {
                $abweichungen[] = 'gelöscht';
            } else {
                if ($jetzt['modell'] !== $o['modell']) {
                    $abweichungen[] = 'Typ ' . ($o['modell'] ?: '–') . ' → ' . ($jetzt['modell'] ?: '–');
                }
                if ($o['plan_x'] !== null && $jetzt['plan_x'] !== null) {
                    if ($jetzt['floor_id'] !== $o['floor_id']) {
                        $abweichungen[] = 'andere Etage';
                    } else {
                        $weg = $meter($o['floor_id'], ['x' => $o['plan_x'], 'y' => $o['plan_y']], ['x' => $jetzt['plan_x'], 'y' => $jetzt['plan_y']]);
                        if ($weg !== null && $weg >= self::VERSCHOBEN_AB_M) {
                            $abweichungen[] = number_format($weg, 0, ',', '.') . ' m versetzt';
                        }
                    }
                }
            }
            $anlagen[] = ['id' => (int) $o['id'], 'name' => $o['name'], 'plan' => $o['art_anzeige'] . ($o['modell'] !== '' ? ' · ' . $o['modell'] : ''),
                'ist' => $jetzt !== null ? ($jetzt['modell'] ?: $jetzt['art_anzeige']) . ($jetzt['kennung'] !== '' ? ' · ' . $jetzt['kennung'] : '') : '–',
                'status' => $p['status'] ?? 'offen', 'notiz' => (string) ($p['notiz'] ?? ''), 'abweichungen' => $abweichungen, 'weg_m' => $weg];
        }
        foreach ($istObjekte as $id => $o) {
            if (!in_array($id, array_map(static fn ($x) => (int) $x['id'], $plan['objekte'] ?? []), true)) {
                $anlagen[] = ['id' => $id, 'name' => $o['name'], 'plan' => '–', 'ist' => ($o['modell'] ?: $o['art_anzeige']) . ($o['kennung'] !== '' ? ' · ' . $o['kennung'] : ''),
                    'status' => 'zusaetzlich', 'notiz' => '', 'abweichungen' => ['nicht geplant'], 'weg_m' => null];
            }
        }

        // Kabel und Kabelbrücken: Länge im Plan gegen die echte
        $kabel = [];
        $summePlan = 0.0;
        $summeIst = 0.0;
        $istFuerMaterial = [];
        foreach ($plan['leitungen'] ?? [] as $l) {
            $p = $posten['l:' . (int) $l['id']] ?? null;
            $jetzt = $istLeitungen[(int) $l['id']] ?? null;
            $status = $p['status'] ?? 'offen';
            $laengeIst = $status === 'entfallen' ? null : ($p !== null && $p['laenge_ist'] !== null ? (float) $p['laenge_ist'] : ($jetzt['laenge'] ?? null));
            if ($l['art'] === 'kabel' && $l['laenge'] !== null && $laengeIst !== null) {
                $summePlan += (float) $l['laenge'] * max(1, (int) $l['anzahl']);
                $summeIst += (float) $laengeIst * max(1, (int) $l['anzahl']);
            }
            if ($status !== 'entfallen' && ($jetzt !== null || $p !== null)) {
                $istFuerMaterial[] = ['laenge' => $laengeIst, 'laenge_m' => $laengeIst] + ($jetzt ?? $l);
            }
            $kabel[] = ['id' => (int) $l['id'], 'art' => $l['art'], 'name' => self::leitungName($l), 'status' => $status, 'notiz' => (string) ($p['notiz'] ?? ''),
                'laenge_plan' => $l['laenge'], 'laenge_ist' => $laengeIst,
                'differenz' => $l['laenge'] !== null && $laengeIst !== null ? round($laengeIst - (float) $l['laenge'], 1) : null];
        }
        foreach ($istLeitungen as $id => $l) {
            if (!in_array($id, array_map(static fn ($x) => (int) $x['id'], $plan['leitungen'] ?? []), true)) {
                $istFuerMaterial[] = $l;
                $kabel[] = ['id' => $id, 'art' => $l['art'], 'name' => self::leitungName($l), 'status' => 'zusaetzlich', 'notiz' => '',
                    'laenge_plan' => null, 'laenge_ist' => $l['laenge'], 'differenz' => null];
            }
        }
        $abweichungProzent = $summePlan > 0 ? ($summeIst / $summePlan - 1) * 100 : null;
        $reserve = MaterialService::reserve();
        $reserveEmpfehlung = null;
        if ($abweichungProzent !== null && $abweichungProzent > $reserve) {
            $reserveEmpfehlung = (int) (ceil(($abweichungProzent + 3) / 5) * 5);
        }

        // Material: geplant gegen tatsächlich gebraucht
        $istAnlagen = array_values(array_filter($ist['objekte'] ?? [], static function ($o) use ($posten) {
            return ($posten['o:' . (int) $o['id']]['status'] ?? '') !== 'entfallen';
        }));
        $materialIst = MaterialService::berechnen($istAnlagen, $istFuerMaterial);
        $materialPlan = $plan['material'] ?? MaterialService::berechnen($plan['objekte'] ?? [], $plan['leitungen'] ?? []);

        return [
            'anlagen'      => $anlagen,
            'kabel'        => $kabel,
            'zaehler'      => self::uebersicht($aufbau)['zaehler'],
            'meter_plan'   => round($summePlan, 1),
            'meter_ist'    => round($summeIst, 1),
            'abweichung_prozent' => $abweichungProzent !== null ? round($abweichungProzent, 1) : null,
            'reserve'      => $reserve,
            'reserve_empfehlung' => $reserveEmpfehlung,
            'material'     => self::materialVergleich($materialPlan, $materialIst),
            'last'         => self::last($aufbau),
            'bereiche'     => self::bereiche($aufbau),
        ];
    }

    /**
     * Stücke je Kabelsorte: geplant gegen gebraucht.
     *
     * @return list<array{titel:string,plan:array<int,int>,ist:array<int,int>,meter_plan:float,meter_ist:float}>
     */
    private static function materialVergleich(array $plan, array $ist): array
    {
        $gruppen = [];
        foreach (['plan' => $plan, 'ist' => $ist] as $seite => $m) {
            foreach ($m['kabel'] ?? [] as $g) {
                $gruppen[$g['titel']] ??= ['titel' => $g['titel'], 'plan' => [], 'ist' => [], 'meter_plan' => 0.0, 'meter_ist' => 0.0];
                $gruppen[$g['titel']][$seite] = $g['stuecke'];
                $gruppen[$g['titel']]['meter_' . $seite] = (float) $g['meter_stuecke'];
            }
        }
        return array_values($gruppen);
    }

    /** Zeitraum des Betriebs: ab „Aufbau fertig“ (sonst Start) bis Ende (sonst jetzt). @return array{0:int,1:int} */
    public static function betrieb(array $aufbau): array
    {
        $von = Zeit::ts($aufbau['betrieb_ab'] ?? null) ?? Zeit::ts($aufbau['gestartet_am']) ?? time();
        $bis = Zeit::ts($aufbau['beendet_am'] ?? null) ?? time();
        return [$von, max($von, $bis)];
    }

    /**
     * Gemessene Last je Anlage im Betrieb gegen ihren Nennstrom – und was
     * das für die nächste Planung heißt.
     *
     * @return list<array<string,mixed>>
     */
    private static function last(array $aufbau): array
    {
        [$von, $bis] = self::betrieb($aufbau);
        if ($bis - $von < 60) {
            return [];
        }
        $out = [];
        foreach (Zugriff::filtern(AnlagenService::liste(['site_id' => (int) $aufbau['site_id']])) as $o) {
            // Ein Tank hat keinen Nennstrom und keine Last.
            if (!AnlagenService::elektrisch((string) $o['art'])) {
                continue;
            }
            // Das Hauptmessgerät, das im Betrieb steckte (auch ein inzwischen abgebautes).
            $messpunkte = array_values(array_filter(MesspunktService::fuerObjekt((int) $o['id'], true), static function ($m) use ($von, $bis) {
                $ab = Zeit::ts($m['gueltig_ab'] ?? null);
                $ende = Zeit::ts($m['gueltig_bis'] ?? null);
                return ($ab === null || $ab < $bis) && ($ende === null || $ende > $von);
            }));
            $haupt = MesspunktService::haupt($messpunkte);
            if ($haupt === null || $haupt['anschluss_id'] !== null) {
                continue;
            }
            $k = Messdaten::kennzahlen((string) $haupt['geraet'], (string) $haupt['kanal'], $von, $bis, MesspunktService::fenster($haupt, $o));
            $anschluesse = Database::select(
                'SELECT a.richtung, t.strom_a FROM anschluesse a JOIN steckertypen t ON t.id = a.steckertyp_id WHERE a.objekt_id = ?',
                [(int) $o['id']]
            );
            $nenn = MesspunktService::nennstrom($o, $anschluesse);
            $auslastung = $k['i_max'] !== null && $nenn !== null && $nenn > 0 ? round($k['i_max'] / $nenn * 100, 1) : null;
            $out[] = [
                'id' => (int) $o['id'], 'name' => $o['name'], 'art' => $o['art_anzeige'], 'modell' => (string) $o['modell'],
                'p_max' => $k['p_max'], 'i_max' => $k['i_max'], 'energie_wh' => $k['energie_wh'], 'nennstrom' => $nenn,
                'auslastung' => $auslastung, 'empfehlung' => self::empfehlung($o, $auslastung, $k['i_max']),
            ];
        }
        usort($out, static fn ($a, $b) => ($b['auslastung'] ?? -1) <=> ($a['auslastung'] ?? -1));
        return $out;
    }

    /** @return array{stufe:string,text:string} */
    private static function empfehlung(array $objekt, ?float $auslastung, ?float $iMax): array
    {
        if ($auslastung === null) {
            return ['stufe' => '', 'text' => 'kein Nennstrom oder keine Werte'];
        }
        if ($auslastung > 100) {
            return ['stufe' => 'bad', 'text' => 'überlastet – größer planen oder Last verteilen'];
        }
        if ($auslastung >= 85) {
            return ['stufe' => 'warn', 'text' => 'am Limit – mehr Reserve einplanen'];
        }
        if ($auslastung < 25 && $iMax !== null && $objekt['art'] === 'verteilung') {
            // Der kleinste Katalog-Verteiler, der mit 25 % Luft reicht.
            $kleiner = null;
            foreach (AnlagenService::liste(['vorlagen' => true]) as $v) {
                $strom = (float) ($v['nennstrom_a'] ?? 0);
                if ($v['art'] === 'verteilung' && (int) $v['fest'] === 0 && $strom >= $iMax * 1.25 && $strom < (float) $objekt['nennstrom_a']
                    && ($kleiner === null || $strom < (float) $kleiner['nennstrom_a'])) {
                    $kleiner = $v;
                }
            }
            return ['stufe' => 'info', 'text' => 'überdimensioniert' . ($kleiner !== null ? ' – ' . ($kleiner['modell'] ?: $kleiner['name']) . ' reicht' : '')];
        }
        if ($auslastung < 25) {
            return ['stufe' => 'info', 'text' => 'überdimensioniert'];
        }
        return ['stufe' => 'ok', 'text' => 'passt'];
    }

    /** Bereiche des Standorts: höchste gleichzeitige Last im Betrieb gegen ihre Grenze. @return list<array<string,mixed>> */
    private static function bereiche(array $aufbau): array
    {
        [$von, $bis] = self::betrieb($aufbau);
        if ($bis - $von < 60) {
            return [];
        }
        $out = [];
        foreach (BereichService::alle((int) $aufbau['site_id']) as $b) {
            $k = BereichService::kennzahlen($b, $von, $bis);
            $grenze = $b['grenze_kw'] !== null ? (float) $b['grenze_kw'] * 1000 : null;
            $out[] = ['id' => (int) $b['id'], 'name' => $b['name'], 'p_max' => $k['p_max'], 'grenze_w' => $grenze,
                'auslastung' => $grenze !== null && $k['p_max'] !== null ? round($k['p_max'] / $grenze * 100, 1) : null, 'energie_wh' => $k['energie_wh']];
        }
        return $out;
    }
}
