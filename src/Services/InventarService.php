<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Inventar: Verteiler und Zwischenstecker aus dem Lager, mit ihren Shellys.
 *
 * Ein Inventarstück ist ein bestimmtes Gerät (Aufkleber PD63-07). Seine
 * Shellys sind ihm fest zugeordnet – an der Einspeisung, an einzelnen
 * Abgängen oder, beim Zwischenstecker (bis 125 A), im Durchgang. Im Vorfeld
 * angelegt, wird es beim Aufbau in eine geplante Anlage eingesetzt: „der
 * PD63-07 steht jetzt als Foodtruck-Verteiler“. Ab dann messen seine Shellys
 * dort – als Messpunkte der Anlage, gültig für die Dauer des Einsatzes.
 * Nach dem Abbau bleiben die Messpunkte als frühere stehen; Statistik und
 * Bereiche rechnen die Zeit richtig zu, auch wenn der Verteiler längst
 * woanders steht.
 */
final class InventarService
{
    public const ARTEN = [
        'verteilung'      => 'Verteiler',
        'zwischenstecker' => 'Zwischenstecker',
    ];

    // ------------------------------------------------------------ Lesen

    /**
     * @param array{art?:string,frei?:bool,suche?:string} $filter
     * @return list<array<string,mixed>>
     */
    public static function liste(array $filter = []): array
    {
        $sql = 'SELECT i.*, v.name AS vorlage_name, v.modell AS vorlage_modell, t.name AS stecker_name,
                       (SELECT COUNT(*) FROM inventar_messgeraete g WHERE g.inventar_id = i.id) AS messgeraete_zahl
                FROM inventar i LEFT JOIN objekte v ON v.id = i.vorlage_id LEFT JOIN steckertypen t ON t.id = i.steckertyp_id WHERE 1 = 1';
        $werte = [];
        if (isset(self::ARTEN[$filter['art'] ?? ''])) {
            $sql .= ' AND i.art = ?';
            $werte[] = $filter['art'];
        }
        if (($filter['suche'] ?? '') !== '') {
            $sql .= ' AND (i.kennung LIKE ? OR i.name LIKE ? OR i.seriennummer LIKE ?)';
            array_push($werte, '%' . $filter['suche'] . '%', '%' . $filter['suche'] . '%', '%' . $filter['suche'] . '%');
        }
        $rows = Database::select($sql . ' ORDER BY i.art DESC, i.kennung', $werte);
        $einsaetze = [];
        foreach (Database::select(
            'SELECT e.*, o.name AS anlage_name, o.site_id, s.name AS site_name, a.bezeichnung AS anschluss_name
             FROM einsaetze e LEFT JOIN objekte o ON o.id = e.objekt_id LEFT JOIN sites s ON s.id = o.site_id
             LEFT JOIN anschluesse a ON a.id = e.anschluss_id WHERE e.bis IS NULL'
        ) as $e) {
            $einsaetze[(int) $e['inventar_id']] = $e;
        }
        $out = [];
        foreach ($rows as $i) {
            $i['einsatz'] = $einsaetze[(int) $i['id']] ?? null;
            if (!empty($filter['frei']) && ($i['einsatz'] !== null || (int) $i['defekt'] === 1)) {
                continue;
            }
            $out[] = self::aufbereiten($i);
        }
        return $out;
    }

    /**
     * Wo ein Stück gerade steckt, erfährt nur, wer die Anlage sieht – wie auf
     * der Seite des Stücks. Sonst stünden in der Liste Anlagen und Standorte
     * anderer Kunden.
     *
     * @param list<array<string,mixed>> $liste aus liste()
     * @return list<array<string,mixed>>
     */
    public static function fuerBetrachter(array $liste): array
    {
        foreach ($liste as &$i) {
            $e = $i['einsatz'] ?? null;
            if ($e === null) {
                continue;
            }
            $o = AnlagenService::find((int) $e['objekt_id']);
            if ($o === null || !Zugriff::siehtObjekt($o)) {
                $i['einsatz'] = array_merge($e, ['anlage_name' => 'eine nicht freigegebene Anlage', 'objekt_name' => 'eine nicht freigegebene Anlage',
                    'site_name' => '', 'anschluss_name' => null]);
            }
        }
        unset($i);
        return $liste;
    }

    public static function find(int $id): ?array
    {
        $i = Database::first(
            'SELECT i.*, v.name AS vorlage_name, v.modell AS vorlage_modell, t.name AS stecker_name,
                    (SELECT COUNT(*) FROM inventar_messgeraete g WHERE g.inventar_id = i.id) AS messgeraete_zahl
             FROM inventar i LEFT JOIN objekte v ON v.id = i.vorlage_id LEFT JOIN steckertypen t ON t.id = i.steckertyp_id WHERE i.id = ?',
            [$id]
        );
        if ($i === null) {
            return null;
        }
        $i['einsatz'] = self::aktuellerEinsatz($id);
        return self::aufbereiten($i);
    }

    private static function aufbereiten(array $i): array
    {
        $i['art_name'] = self::ARTEN[$i['art']] ?? $i['art'];
        $i['typ'] = $i['art'] === 'verteilung'
            ? (string) ($i['vorlage_modell'] ?: $i['vorlage_name'] ?: 'ohne Typ')
            : (string) ($i['stecker_name'] ?? 'ohne Stecker');
        $i['titel'] = $i['kennung'] . ($i['name'] !== '' ? ' · ' . $i['name'] : '');
        $i['zustand'] = (int) $i['defekt'] === 1 ? 'defekt' : ($i['einsatz'] !== null ? 'im_einsatz' : 'lager');
        return $i;
    }

    /** @return list<array<string,mixed>> */
    public static function messgeraete(int $inventarId): array
    {
        return Database::select('SELECT * FROM inventar_messgeraete WHERE inventar_id = ? ORDER BY id', [$inventarId]);
    }

    /**
     * Wo in diesem Stück ein Shelly sitzen kann.
     *
     * @return array<string,string> stelle => Text
     */
    public static function stellen(array $item): array
    {
        if ($item['art'] === 'zwischenstecker') {
            return ['durchgang' => 'Durchgang'];
        }
        $out = ['einspeisung' => 'Einspeisung'];
        if (!empty($item['vorlage_id'])) {
            foreach (AnlagenService::anschluesse((int) $item['vorlage_id']) as $a) {
                if ($a['richtung'] === 'abgang') {
                    $out['abgang:' . (int) $a['nr']] = 'Abgang ' . $a['bezeichnung'] . ' · ' . $a['typ_name'];
                }
            }
        }
        return $out;
    }

    public static function aktuellerEinsatz(int $inventarId): ?array
    {
        return Database::first(
            'SELECT e.*, o.name AS anlage_name, o.site_id, s.name AS site_name, a.bezeichnung AS anschluss_name
             FROM einsaetze e LEFT JOIN objekte o ON o.id = e.objekt_id LEFT JOIN sites s ON s.id = o.site_id
             LEFT JOIN anschluesse a ON a.id = e.anschluss_id WHERE e.inventar_id = ? AND e.bis IS NULL ORDER BY e.id DESC',
            [$inventarId]
        );
    }

    /** @return list<array<string,mixed>> Alle Einsätze eines Stücks, neueste zuerst */
    public static function geschichte(int $inventarId): array
    {
        return Database::select(
            'SELECT e.*, o.name AS anlage_name, o.site_id, s.name AS site_name, a.bezeichnung AS anschluss_name
             FROM einsaetze e LEFT JOIN objekte o ON o.id = e.objekt_id LEFT JOIN sites s ON s.id = o.site_id
             LEFT JOIN anschluesse a ON a.id = e.anschluss_id WHERE e.inventar_id = ? ORDER BY e.ab DESC, e.id DESC',
            [$inventarId]
        );
    }

    /** @return list<array<string,mixed>> Einsätze an einer Anlage (laufende zuerst) */
    public static function einsaetzeAnObjekt(int $objektId, bool $mitFrueheren = false): array
    {
        return Database::select(
            'SELECT e.*, i.kennung, i.name AS inventar_name, i.art AS inventar_art, a.bezeichnung AS anschluss_name
             FROM einsaetze e JOIN inventar i ON i.id = e.inventar_id LEFT JOIN anschluesse a ON a.id = e.anschluss_id
             WHERE e.objekt_id = ?' . ($mitFrueheren ? '' : ' AND e.bis IS NULL') . ' ORDER BY e.bis IS NOT NULL, e.ab DESC',
            [$objektId]
        );
    }

    // ------------------------------------------------------------ Schreiben

    /**
     * @param array{art?:string,kennung?:string,name?:string,vorlage_id?:mixed,steckertyp_id?:mixed,seriennummer?:string,defekt?:mixed,notizen?:string,
     *              messgeraete?:list<array{geraet?:string,kanal?:string,stelle?:string,bezeichnung?:string}>} $daten
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $alt = $id !== null ? self::find($id) : null;
        if ($id !== null && $alt === null) {
            return ['ok' => false, 'message' => 'Dieses Inventarstück gibt es nicht.'];
        }
        $art = (string) ($daten['art'] ?? ($alt['art'] ?? 'verteilung'));
        if (!isset(self::ARTEN[$art])) {
            return ['ok' => false, 'message' => 'Unbekannte Art.'];
        }
        $kennung = trim((string) ($daten['kennung'] ?? ''));
        if ($kennung === '') {
            return ['ok' => false, 'message' => 'Das Stück braucht eine Inventarnummer (den Aufkleber).'];
        }
        $doppelt = Database::first('SELECT id FROM inventar WHERE LOWER(kennung) = LOWER(?) AND id <> ?', [$kennung, $id ?? 0]);
        if ($doppelt !== null) {
            return ['ok' => false, 'message' => 'Die Inventarnummer „' . $kennung . '“ gibt es schon.'];
        }
        $vorlageId = (int) ($daten['vorlage_id'] ?? 0);
        $steckerId = (int) ($daten['steckertyp_id'] ?? 0);
        if ($art === 'verteilung') {
            if ($vorlageId > 0 && Database::first("SELECT id FROM objekte WHERE id = ? AND ist_vorlage = 1 AND art = 'verteilung'", [$vorlageId]) === null) {
                return ['ok' => false, 'message' => 'Diesen Verteiler-Typ gibt es im Katalog nicht.'];
            }
            $steckerId = 0;
        } else {
            if ($steckerId <= 0 || Database::first('SELECT id FROM steckertypen WHERE id = ?', [$steckerId]) === null) {
                return ['ok' => false, 'message' => 'Bitte den Steckverbinder des Zwischensteckers wählen.'];
            }
            $vorlageId = 0;
        }

        // Shellys: je Kanal einmal, und nicht schon in einem anderen Stück.
        $probe = ['art' => $art, 'vorlage_id' => $vorlageId ?: null];
        $stellen = self::stellen($probe);
        $geraete = [];
        foreach ((array) ($daten['messgeraete'] ?? []) as $g) {
            $geraet = trim((string) ($g['geraet'] ?? ''));
            if ($geraet === '') {
                continue;
            }
            $kanal = trim((string) ($g['kanal'] ?? ''));
            if (!MesspunktService::kennungGueltig($geraet) || ($kanal !== '' && !MesspunktService::kennungGueltig($kanal))) {
                return ['ok' => false, 'message' => 'Gerät und Kanal: keine Leer-, Steuer- oder Sonderzeichen wie + # < > " \'.'];
            }
            $stelle = (string) ($g['stelle'] ?? '');
            if (!isset($stellen[$stelle])) {
                $stelle = $art === 'zwischenstecker' ? 'durchgang' : 'einspeisung';
            }
            $schluessel = $geraet . '|' . $kanal;
            if (isset($geraete[$schluessel])) {
                return ['ok' => false, 'message' => 'Kanal ' . ($kanal ?: '–') . ' von ' . $geraet . ' ist doppelt eingetragen.'];
            }
            $anderes = Database::first(
                'SELECT i.kennung FROM inventar_messgeraete g JOIN inventar i ON i.id = g.inventar_id WHERE g.geraet = ? AND g.kanal = ? AND g.inventar_id <> ?',
                [$geraet, $kanal, $id ?? 0]
            );
            if ($anderes !== null) {
                return ['ok' => false, 'message' => $geraet . ' ' . $kanal . ' steckt schon in ' . $anderes['kennung'] . '.'];
            }
            $geraete[$schluessel] = [
                'geraet' => mb_substr($geraet, 0, 128), 'kanal' => mb_substr($kanal, 0, 64), 'stelle' => $stelle,
                'bezeichnung' => mb_substr(trim((string) ($g['bezeichnung'] ?? '')), 0, 190),
            ];
        }

        $felder = [
            'art' => $art, 'kennung' => mb_substr($kennung, 0, 64), 'name' => mb_substr(trim((string) ($daten['name'] ?? '')), 0, 190),
            'vorlage_id' => $vorlageId ?: null, 'steckertyp_id' => $steckerId ?: null,
            'seriennummer' => mb_substr(trim((string) ($daten['seriennummer'] ?? '')), 0, 190),
            'defekt' => !empty($daten['defekt']) ? 1 : 0, 'notizen' => (string) ($daten['notizen'] ?? ''), 'updated_at' => gmdate('c'),
        ];
        $vorherGeraete = $id !== null ? self::geraeteSchluessel($id) : [];
        if ($id !== null) {
            Database::update('inventar', $id, $felder);
            Audit::log('inventar.update', 'inventar', (string) $id, ['kennung' => $kennung]);
        } else {
            $felder['created_at'] = gmdate('c');
            $id = Database::insert('inventar', $felder);
            Audit::log('inventar.create', 'inventar', (string) $id, ['kennung' => $kennung]);
        }
        Database::execute('DELETE FROM inventar_messgeraete WHERE inventar_id = ?', [$id]);
        foreach ($geraete as $g) {
            Database::insert('inventar_messgeraete', $g + ['inventar_id' => $id, 'created_at' => gmdate('c')]);
        }

        // Steckt das Stück gerade irgendwo und hat sich an den Shellys etwas
        // geändert (etwa einer getauscht), gilt ab jetzt die neue Bestückung.
        $einsatz = self::aktuellerEinsatz($id);
        $hinweis = '';
        if ($einsatz !== null && self::geraeteSchluessel($id) !== $vorherGeraete) {
            $jetzt = gmdate('c');
            Database::execute('UPDATE messpunkte SET gueltig_bis = ? WHERE einsatz_id = ? AND gueltig_bis IS NULL', [$jetzt, (int) $einsatz['id']]);
            $hinweis = ' ' . self::messpunkteAnlegen(self::find($id), $einsatz, $jetzt);
            Zugriff::vergessen();
        }
        return ['ok' => true, 'message' => self::ARTEN[$art] . ' ' . $kennung . ' gespeichert (' . count($geraete) . ' Messkanäle).' . rtrim($hinweis), 'id' => $id];
    }

    /** @return list<string> */
    private static function geraeteSchluessel(int $id): array
    {
        return array_map(static fn ($g) => $g['geraet'] . '|' . $g['kanal'] . '|' . $g['stelle'] . '|' . $g['bezeichnung'], self::messgeraete($id));
    }

    public static function loeschen(int $id): array
    {
        $i = self::find($id);
        if ($i === null) {
            return ['ok' => false, 'message' => 'Dieses Inventarstück gibt es nicht.'];
        }
        if ($i['einsatz'] !== null) {
            return ['ok' => false, 'message' => $i['kennung'] . ' ist gerade eingesetzt (' . $i['einsatz']['anlage_name'] . ') – erst abbauen.'];
        }
        Database::execute('DELETE FROM inventar_messgeraete WHERE inventar_id = ?', [$id]);
        Database::execute('DELETE FROM einsaetze WHERE inventar_id = ?', [$id]);
        BerichtService::inventarEntfernt($id);
        Database::delete('inventar', $id);
        Audit::log('inventar.delete', 'inventar', (string) $id, ['kennung' => $i['kennung']]);
        return ['ok' => true, 'message' => $i['kennung'] . ' gelöscht.'];
    }

    // ------------------------------------------------------------ Einsatz

    /**
     * Ein Stück in eine Anlage einsetzen: „der PD63-07 steht jetzt als
     * Foodtruck-Verteiler“, „der Zwischenstecker ZS-63-02 steckt am Abgang X3
     * des Aggregats“. Steckt das Stück noch woanders, endet der alte Einsatz.
     * Was vorher an derselben Stelle steckte, ebenso.
     *
     * @param bool $typUebernehmen Verteiler: die Anlage auf den Typ des Stücks umstellen, wenn er abweicht
     * @return array{ok:bool,message:string,einsatz_id?:int}
     */
    public static function einsetzen(int $inventarId, int $objektId, ?int $anschlussId = null, bool $typUebernehmen = true): array
    {
        $item = self::find($inventarId);
        $objekt = AnlagenService::find($objektId);
        if ($item === null || $objekt === null || (int) $objekt['ist_vorlage'] === 1) {
            return ['ok' => false, 'message' => 'Inventarstück oder Anlage nicht gefunden.'];
        }
        if ((int) $item['defekt'] === 1) {
            return ['ok' => false, 'message' => $item['kennung'] . ' ist als defekt markiert.'];
        }
        $anschluss = null;
        if ($item['art'] === 'verteilung') {
            if ($objekt['art'] !== 'verteilung') {
                return ['ok' => false, 'message' => 'Ein Verteiler aus dem Inventar gehört in eine Verteilung, nicht in „' . $objekt['art_name'] . '“.'];
            }
            $anschlussId = null;
        } elseif ($anschlussId !== null) {
            $anschluss = Database::first('SELECT a.*, t.name AS typ_name FROM anschluesse a JOIN steckertypen t ON t.id = a.steckertyp_id WHERE a.id = ? AND a.objekt_id = ?', [$anschlussId, $objektId]);
            if ($anschluss === null) {
                return ['ok' => false, 'message' => 'Diesen Anschluss gibt es an der Anlage nicht.'];
            }
        }

        $jetzt = gmdate('c');
        $meldungen = [];
        if ($item['einsatz'] !== null) {
            self::beenden((int) $item['einsatz']['id'], $jetzt);
            $meldungen[] = 'Aus „' . $item['einsatz']['anlage_name'] . '“ abgezogen.';
        }
        // Was bisher an derselben Stelle steckte, ist jetzt draußen.
        foreach (self::einsaetzeAnObjekt($objektId) as $e) {
            $gleicheStelle = $item['art'] === 'verteilung'
                ? $e['inventar_art'] === 'verteilung'
                : $e['inventar_art'] === 'zwischenstecker' && (int) ($e['anschluss_id'] ?? 0) === (int) ($anschlussId ?? 0);
            if ($gleicheStelle) {
                self::beenden((int) $e['id'], $jetzt);
                $meldungen[] = $e['kennung'] . ' ersetzt.';
            }
        }

        if ($item['art'] === 'verteilung') {
            $vorlage = !empty($item['vorlage_id']) ? AnlagenService::find((int) $item['vorlage_id']) : null;
            if ($vorlage !== null && $typUebernehmen && trim((string) $vorlage['modell']) !== trim((string) $objekt['modell'])) {
                $umstellung = AnlagenService::typUebernehmen($objektId, (int) $vorlage['id']);
                $meldungen[] = 'Typ geändert: ' . ($objekt['modell'] ?: 'ohne Typ') . ' → ' . ($vorlage['modell'] ?: $vorlage['name']) . '.';
                if (!$umstellung['ok']) {
                    return $umstellung;
                }
            }
            Database::update('objekte', $objektId, [
                'kennung' => $item['kennung'], 'seriennummer' => $item['seriennummer'], 'updated_at' => $jetzt,
                'status' => $objekt['status'] === 'geplant' ? 'aufgebaut' : $objekt['status'],
            ]);
        } elseif ($anschluss !== null && (int) $anschluss['steckertyp_id'] !== (int) $item['steckertyp_id']) {
            $meldungen[] = 'Achtung: Der Anschluss ist ' . $anschluss['typ_name'] . ', der Zwischenstecker ' . $item['stecker_name'] . '.';
        }

        $einsatzId = Database::insert('einsaetze', [
            'inventar_id' => $inventarId, 'objekt_id' => $objektId, 'anschluss_id' => $anschlussId, 'objekt_name' => $objekt['name'],
            'ab' => $jetzt, 'bis' => null, 'von' => (string) (Auth::user()['username'] ?? 'system'), 'notizen' => '', 'created_at' => $jetzt,
        ]);
        $einsatz = Database::first('SELECT * FROM einsaetze WHERE id = ?', [$einsatzId]);
        $hinweis = self::messpunkteAnlegen($item, $einsatz, $jetzt);
        if ($hinweis !== '') {
            $meldungen[] = $hinweis;
        }
        Zugriff::vergessen();
        Audit::log('inventar.einsatz', 'objekt', (string) $objektId, ['inventar' => $item['kennung'], 'anschluss' => $anschlussId]);
        $wo = '„' . $objekt['name'] . '“' . ($anschluss !== null ? ', ' . ($anschluss['richtung'] === 'abgang' ? 'Abgang ' : 'Eingang ') . $anschluss['bezeichnung'] : '');
        return ['ok' => true, 'message' => trim($item['kennung'] . ' eingesetzt in ' . $wo . '. ' . implode(' ', $meldungen)), 'einsatz_id' => $einsatzId];
    }

    /**
     * Die Shellys des Stücks als Messpunkte der Anlage anlegen, gültig ab $ab.
     *
     * @return string Hinweis, falls ein Abgang an der Anlage fehlt
     */
    private static function messpunkteAnlegen(array $item, array $einsatz, string $ab): string
    {
        $objektId = (int) $einsatz['objekt_id'];
        $anschluesse = [];
        foreach (Database::select('SELECT id, richtung, nr FROM anschluesse WHERE objekt_id = ?', [$objektId]) as $a) {
            $anschluesse[$a['richtung'] . ':' . (int) $a['nr']] = (int) $a['id'];
        }
        $fehlt = 0;
        foreach (self::messgeraete((int) $item['id']) as $g) {
            $anschlussId = null;
            $rolle = 'einspeisung';
            if ($g['stelle'] === 'durchgang') {
                // Zwischenstecker: misst, wo er steckt – am Abgang oder an der Einspeisung.
                $anschlussId = $einsatz['anschluss_id'] !== null ? (int) $einsatz['anschluss_id'] : null;
                $richtung = $anschlussId !== null ? (string) Database::scalar('SELECT richtung FROM anschluesse WHERE id = ?', [$anschlussId]) : 'eingang';
                if ($richtung === 'abgang') {
                    $rolle = 'abgang';
                } else {
                    $anschlussId = null;
                }
            } elseif (str_starts_with($g['stelle'], 'abgang:')) {
                $anschlussId = $anschluesse[$g['stelle']] ?? null;
                if ($anschlussId === null) {
                    $fehlt++;
                    continue;
                }
                $rolle = 'abgang';
            }
            Database::insert('messpunkte', [
                'objekt_id' => $objektId, 'anschluss_id' => $anschlussId, 'schutzorgan_id' => null,
                'geraet' => $g['geraet'], 'kanal' => $g['kanal'], 'rolle' => $rolle,
                'bezeichnung' => $g['bezeichnung'] !== '' ? $g['bezeichnung'] : ($item['art'] === 'zwischenstecker' ? $item['kennung'] : ''),
                'einsatz_id' => (int) $einsatz['id'], 'gueltig_ab' => $ab, 'gueltig_bis' => null, 'created_at' => $ab,
            ]);
        }
        return $fehlt > 0 ? $fehlt . ' Messkanal/-kanäle ohne passenden Abgang an der Anlage – nicht zugeordnet.' : '';
    }

    /** Einen Einsatz beenden (Abbau): Die Shellys messen ab jetzt nicht mehr dort. */
    public static function beenden(int $einsatzId, ?string $bis = null): array
    {
        $e = Database::first('SELECT e.*, i.kennung FROM einsaetze e JOIN inventar i ON i.id = e.inventar_id WHERE e.id = ?', [$einsatzId]);
        if ($e === null || $e['bis'] !== null) {
            return ['ok' => false, 'message' => 'Dieser Einsatz läuft nicht.'];
        }
        $bis ??= gmdate('c');
        Database::update('einsaetze', $einsatzId, ['bis' => $bis]);
        Database::execute('UPDATE messpunkte SET gueltig_bis = ? WHERE einsatz_id = ? AND gueltig_bis IS NULL', [$bis, $einsatzId]);
        Zugriff::vergessen();
        Audit::log('inventar.abbau', 'objekt', (string) $e['objekt_id'], ['inventar' => $e['kennung']]);
        return ['ok' => true, 'message' => $e['kennung'] . ' abgebaut – zurück im Lager.'];
    }

    /** Anlage gelöscht: laufende Einsätze enden (die Messpunkte gehen mit der Anlage). */
    public static function objektEntfernt(int $objektId): void
    {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE objekt_id = ? AND bis IS NULL', [gmdate('c'), $objektId]);
    }

    /** Lesbarer Zeitraum eines Einsatzes. */
    public static function zeitraum(array $einsatz): string
    {
        return Zeit::anzeige((string) $einsatz['ab'], 'd.m.Y H:i') . ' – ' . ($einsatz['bis'] !== null ? Zeit::anzeige((string) $einsatz['bis'], 'd.m.Y H:i') : 'jetzt');
    }
}
