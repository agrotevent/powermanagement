<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Zugriff;
use App\Support\Database;
use App\Support\Logger;

/**
 * Eine Location von außen auf einen Standort hier anwenden – der eine Weg für
 * „Übernehmen“ von einer anderen Instanz (Seite B, Schnittstelle) und für
 * „Vorschlag annehmen“ (Seite A).
 *
 * Anders als beim Location-Abgleich über Git bleibt der Rahmen dieser
 * Instanz stehen:
 *
 *   - Name, Adresse und Notizen eines vorhandenen Standorts, Name, Ebene und
 *     Reihenfolge verknüpfter Etagen bleiben, wie sie hier heißen;
 *   - angefasst werden nur die Etagenpaare, die der Aufrufer nennt: Plan,
 *     Maßstab, Maßstab-Text, Notizen, Georeferenz – ein fehlender Maßstab
 *     oder eine fehlende Georeferenz löscht hier nichts;
 *   - Feststromanschlüsse und feste Kabel werden innerhalb des Standorts
 *     zugeordnet (über partner_schluessel bzw. den sync_key des Standorts),
 *     nie wandert eine Anlage von einem anderen Standort herüber;
 *   - die Schaltsperre wird nie aufgehoben, Messpunkte bleiben, wie sie sind;
 *   - gelöscht wird nichts.
 *
 * Alles oder nichts: die Änderungen laufen in einer Transaktion (Kacheln
 * neuer Pläne entstehen erst danach, damit die Datenbank nicht so lange
 * gesperrt ist). Läuft schon eine Transaktion des Aufrufers, geht alles in
 * dieser – dann entscheidet er über Commit oder Rollback und baut die
 * Kacheln danach selbst (Option „kacheln“: false). Rechte prüft der
 * Aufrufer – hier wird nur angewandt.
 *
 * Steckertypen legt dieser Weg nur an, wenn der Aufrufer es ausdrücklich
 * erlaubt („stecker_anlegen“), und nur solche, die ein Anschluss der Datei
 * wirklich verwendet – eine Liste mit unbenutzten Typen füllt den Katalog
 * hier nicht.
 */
final class LocationUebertrag
{
    /** Was übernommen werden kann. */
    public const TEILE = ['grundriss', 'feststrom'];

    /**
     * @param ?int $siteId  Ziel; null = neuer Standort (nur mit „grundriss“)
     * @param array<string,int> $paare  Etagen-Schlüssel dort → floor_id hier (0 = neue Etage, nur mit neue_etagen)
     * @param callable(string):?string $datei  plan.datei → geprüfte Datei auf der Platte, null = Plan bleibt
     * @param array{teile:list<string>,zuordnung:string,plan_praefix:string,fremdbestand:bool,neue_etagen:bool,stecker_anlegen?:bool,kacheln?:bool,token_id?:int} $optionen
     *        teile ⊆ TEILE; zuordnung 'partner' (B übernimmt) | 'standort' (A nimmt an);
     *        plan_praefix 'sync' | 'vorschlag'; fremdbestand: Dosen/Racks/APs übernehmen;
     *        stecker_anlegen: benutzte, hier fehlende Steckertypen anlegen (Vorgabe nein);
     *        kacheln: false – nicht selbst bauen, sondern in 'kacheln' zurückgeben;
     *        token_id: bei 'standort' das Token, von dem die Location kommt
     * @return array{ok:bool,message:string,site_id:int,etagen:array<string,int>,plaene:int,anlagen:int,leitungen:int,uebergangen:int,kacheln:list<int>,schluessel:array{anlagen:array<string,string>,leitungen:array<string,string>}}
     */
    public static function anwenden(?int $siteId, array $location, ?array $strom, array $paare, callable $datei, array $optionen): array
    {
        $teile = array_values(array_intersect(self::TEILE, (array) ($optionen['teile'] ?? [])));
        $mitGrundriss = in_array('grundriss', $teile, true);
        $zuordnung = ($optionen['zuordnung'] ?? '') === 'standort' ? 'standort' : 'partner';
        $praefix = ($optionen['plan_praefix'] ?? '') === 'vorschlag' ? 'vorschlag' : 'sync';
        $fremdbestand = (bool) ($optionen['fremdbestand'] ?? false);
        $neueEtagen = (bool) ($optionen['neue_etagen'] ?? false) && $mitGrundriss;
        $steckerAnlegen = ($optionen['stecker_anlegen'] ?? false) === true;
        $kachelnSelbst = ($optionen['kacheln'] ?? true) !== false;
        $tokenId = (int) ($optionen['token_id'] ?? 0);
        $fehler = static fn (string $meldung): array => [
            'ok' => false, 'message' => $meldung, 'site_id' => $siteId ?? 0, 'etagen' => [],
            'plaene' => 0, 'anlagen' => 0, 'leitungen' => 0, 'uebergangen' => 0, 'kacheln' => [],
            'schluessel' => ['anlagen' => [], 'leitungen' => []],
        ];

        if ($teile === []) {
            return $fehler('Nichts zum Übernehmen gewählt.');
        }
        if ((string) ($location['art'] ?? '') !== 'location') {
            return $fehler('Das ist keine Location.');
        }
        if ((int) ($location['format'] ?? 0) > LocationBundle::FORMAT_LOCATION) {
            return $fehler('Die Location stammt aus einer neueren Fassung. Erst PowerManagement aktualisieren.');
        }
        $site = $siteId !== null ? SiteService::find($siteId) : null;
        if ($siteId !== null && $site === null) {
            return $fehler('Diesen Standort gibt es hier nicht.');
        }
        if ($site === null && !$mitGrundriss) {
            return $fehler('Ein neuer Standort entsteht nur mit dem Grundriss.');
        }

        // ---------------------------------------------------- Etagenpaare
        $hierEtagen = [];
        if ($siteId !== null) {
            foreach (SiteService::floors($siteId) as $f) {
                $hierEtagen[(int) $f['id']] = $f;
            }
        }
        $paarListe = [];
        $vergeben = [];
        foreach ($paare as $dortKey => $floorId) {
            $dortKey = (string) $dortKey;
            $floorId = (int) $floorId;
            if (!LocationBundle::schluesselFormat($dortKey)) {
                return $fehler('Der Schlüssel einer Etage ist ungültig: ' . mb_substr($dortKey, 0, 40));
            }
            if ($floorId > 0) {
                // Nur Etagen dieses Standorts – sonst bekäme eine fremde Etage den Plan.
                if (!isset($hierEtagen[$floorId])) {
                    return $fehler('Eine gewählte Etage gehört nicht zu diesem Standort.');
                }
                if (isset($vergeben[$floorId])) {
                    return $fehler('Zwei Etagen dort können nicht auf dieselbe Etage hier.');
                }
                $vergeben[$floorId] = true;
            } elseif (!$neueEtagen) {
                continue;
            }
            $paarListe[$dortKey] = $floorId;
        }
        if ($paarListe === []) {
            return $fehler('Keine Etage gewählt.');
        }
        $dortEtagen = [];
        foreach ((array) ($location['etagen'] ?? []) as $e) {
            $k = is_array($e) && is_string($e['schluessel'] ?? null) ? $e['schluessel'] : '';
            if (isset($paarListe[$k]) && !isset($dortEtagen[$k])) {
                $dortEtagen[$k] = $e;
            }
        }
        foreach (array_keys($paarListe) as $k) {
            if (!isset($dortEtagen[(string) $k])) {
                return $fehler('Die Etage „' . $k . '“ steht nicht in der Location.');
            }
        }

        // ---------------------------------------------------- übersetzen und anwenden
        $pdo = Database::pdo();
        $eigeneTransaktion = !$pdo->inTransaction();
        if ($eigeneTransaktion) {
            $pdo->beginTransaction();
        }
        $abbrechen = static function (string $meldung) use ($pdo, $eigeneTransaktion, $fehler): array {
            if ($eigeneTransaktion && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Zugriff::vergessen();
            return $fehler($meldung);
        };
        $etagenIds = [];
        $plaene = 0;
        $kacheln = [];
        $anlagen = $leitungen = $uebergangen = 0;
        $schluessel = ['anlagen' => [], 'leitungen' => []];
        $feststromMeldung = '';
        try {
            // ---------------------------------------------------- übersetzen
            // Erst die Schlüssel der vorhandenen Etagen festschreiben, dann freie
            // für neue suchen – sonst bekäme eine neue den, den eine vorhandene
            // gleich danach erst bekommt.
            $hierKey = [];
            foreach ($paarListe as $k => $id) {
                if ($id > 0) {
                    $hierKey[(string) $k] = LocationBundle::schluesselSichern('floors', $id, (string) $hierEtagen[$id]['name'], 'etage-' . $id);
                }
            }
            foreach ($paarListe as $k => $id) {
                if ($id === 0) {
                    $hierKey[(string) $k] = LocationBundle::freierSchluessel('floors', (string) $k, array_values($hierKey));
                }
            }
            if ($site !== null) {
                $uebersetzt = [
                    'schluessel' => LocationBundle::schluesselSichern('sites', (int) $siteId, (string) $site['name'], 'standort-' . $siteId),
                    'name'       => (string) $site['name'],
                    'adresse'    => (string) $site['address'],
                    'notizen'    => (string) ($site['notes'] ?? ''),
                ];
            } else {
                $name = self::text($location['name'] ?? '', 190);
                $neuerSchluessel = LocationBundle::freierSchluessel('sites', self::text($location['schluessel'] ?? '', 64) ?: $name);
                $uebersetzt = [
                    'schluessel' => $neuerSchluessel,
                    'name'       => $name !== '' ? $name : $neuerSchluessel,
                    'adresse'    => self::text($location['adresse'] ?? '', 255),
                    'notizen'    => self::text($location['notizen'] ?? '', 5000),
                ];
            }
            $uebersetzt = ['format' => LocationBundle::FORMAT_LOCATION, 'art' => 'location'] + $uebersetzt + ['etagen' => []];
            foreach ($dortEtagen as $k => $e) {
                $id = $paarListe[$k];
                if ($id > 0) {
                    $h = $hierEtagen[$id];
                    $etage = [
                        'name'        => (string) $h['name'],
                        'ebene'       => (int) $h['level_no'],
                        'reihenfolge' => (int) $h['sort_order'],
                        'massstab'    => array_key_exists('massstab', $e) ? self::text($e['massstab'], 190) : (string) $h['scale_note'],
                        'notizen'     => array_key_exists('notizen', $e) ? self::text($e['notizen'], 5000) : (string) ($h['notes'] ?? ''),
                    ];
                } else {
                    $name = self::text($e['name'] ?? '', 190);
                    $etage = [
                        'name'        => $name !== '' ? $name : (string) $k,
                        'ebene'       => is_numeric($e['ebene'] ?? null) ? (int) $e['ebene'] : 0,
                        'reihenfolge' => is_numeric($e['reihenfolge'] ?? null) ? (int) $e['reihenfolge'] : 0,
                        'massstab'    => self::text($e['massstab'] ?? '', 190),
                        'notizen'     => self::text($e['notizen'] ?? '', 5000),
                    ];
                }
                $etage = ['schluessel' => $hierKey[$k]] + $etage + ['plan' => is_array($e['plan'] ?? null) ? $e['plan'] : null];
                // Ohne Maßstab dort bleibt der hiesige – nie wird er geleert.
                if (is_array($e['referenz'] ?? null)) {
                    $etage['referenz'] = $e['referenz'];
                }
                if ($fremdbestand) {
                    foreach (['dosen', 'racks', 'aps'] as $f) {
                        if (array_key_exists($f, $e)) {
                            $etage[$f] = $e[$f];
                        }
                    }
                }
                $uebersetzt['etagen'][] = $etage;
            }

            if ($mitGrundriss) {
                $r = LocationBundle::locationAnwenden($uebersetzt, $datei, [
                    'plan_praefix' => $praefix, 'fremdbestand' => $fremdbestand, 'kacheln' => false, 'schluessel_format' => true,
                ]);
                if (!$r['ok']) {
                    return $abbrechen($r['message']);
                }
                if ($siteId !== null && (int) $r['site_id'] !== $siteId) {
                    return $abbrechen('Der Standort ließ sich nicht eindeutig zuordnen – nichts übernommen.');
                }
                $siteId = (int) $r['site_id'];
                foreach ($hierKey as $dort => $hier) {
                    if (!isset($r['etagen'][$hier]) || ($paarListe[$dort] > 0 && (int) $r['etagen'][$hier] !== $paarListe[$dort])) {
                        return $abbrechen('Die Etage „' . $dort . '“ ließ sich nicht eindeutig zuordnen – nichts übernommen.');
                    }
                    $etagenIds[(string) $dort] = (int) $r['etagen'][$hier];
                }
                $plaene = (int) $r['plaene'];
                $kacheln = $r['kacheln'];
                // Georeferenz nur, wo die Gegenseite eine hat – nie leeren.
                $geo = is_array($strom['georeferenz'] ?? null) ? $strom['georeferenz'] : [];
                foreach ($etagenIds as $dort => $id) {
                    if (is_array($geo[$dort] ?? null)) {
                        PlanGeoService::uebernehmen($id, $geo[$dort]);
                    }
                }
            } else {
                foreach ($paarListe as $dort => $id) {
                    $etagenIds[(string) $dort] = $id;
                }
            }

            if (in_array('feststrom', $teile, true) && is_array($strom)
                && (array_key_exists('anlagen', $strom) || array_key_exists('leitungen', $strom))) {
                $s = $strom;
                unset($s['georeferenz']);
                // Nur Steckertypen, die ein Anschluss hier verwendet – was die
                // Liste sonst noch nennt, landet nicht im Katalog.
                $s['steckertypen'] = self::benutzteSteckertypen($s);
                $r = LocationBundle::stromAnwenden((int) $siteId, $s, [
                    'etagen' => $etagenIds, 'zuordnung' => $zuordnung, 'georeferenz_leeren' => false,
                    'sperre_behalten' => true, 'ohne_messpunkte' => true, 'stecker_anlegen' => $steckerAnlegen,
                ] + ($tokenId > 0 ? ['token_id' => $tokenId] : []));
                if (!$r['ok']) {
                    return $abbrechen($r['message']);
                }
                $anlagen = (int) $r['anlagen'];
                $leitungen = (int) $r['leitungen'];
                $uebergangen = (int) $r['uebergangen'];
                $schluessel = $r['schluessel'];
                $feststromMeldung = ' ' . $r['message'];
            }
            if ($eigeneTransaktion) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            Logger::error('Location übertragen: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $abbrechen('Übernehmen ist fehlgeschlagen – es wurde nichts geändert. Details stehen im Log.');
        }
        Zugriff::vergessen();
        if ($kachelnSelbst) {
            LocationBundle::kachelnBauen($kacheln);
        }

        $wo = $site !== null ? (string) $site['name'] : (string) $uebersetzt['name'];
        $meldung = sprintf('„%s“: %d Etage(n), %d Grundriss(e) übernommen.', $wo, count($etagenIds), $plaene) . $feststromMeldung;
        if ($uebergangen > 0) {
            $meldung .= ' ' . $uebergangen . ' Eintrag/Einträge übergangen (Etage nicht gewählt oder unvollständig).';
        }
        return [
            'ok' => true, 'message' => $meldung, 'site_id' => (int) $siteId, 'etagen' => $etagenIds,
            'plaene' => $plaene, 'anlagen' => $anlagen, 'leitungen' => $leitungen, 'uebergangen' => $uebergangen,
            'kacheln' => $kachelnSelbst ? [] : array_values(array_map('intval', $kacheln)), 'schluessel' => $schluessel,
        ];
    }

    /**
     * Die Steckertypen aus $strom, die ein Anschluss eines Feststromanschlusses
     * darin verwendet – gezählt wie in fehlendeSteckertypen() (CEE63 ist cee63).
     *
     * @return list<array<string,mixed>>
     */
    public static function benutzteSteckertypen(array $strom): array
    {
        $roh = $sauber = [];
        foreach (LocationBundle::festeAnlagen($strom) as $a) {
            foreach ((array) ($a['anschluesse'] ?? []) as $an) {
                $k = is_array($an) && is_scalar($an['stecker'] ?? null) ? (string) $an['stecker'] : '';
                if ($k !== '') {
                    $roh[$k] = true;
                    $sauber[LocationBundle::schluessel($k, 'stecker')] = true;
                }
            }
        }
        return array_values(array_filter((array) ($strom['steckertypen'] ?? []), static function (mixed $t) use ($roh, $sauber): bool {
            $k = is_array($t) && is_scalar($t['schluessel'] ?? null) ? (string) $t['schluessel'] : '';
            return $k !== '' && (isset($roh[$k]) || isset($sauber[LocationBundle::schluessel($k, 'stecker')]));
        }));
    }

    /**
     * Steckertypen, die die Feststromanschlüsse in $strom verwenden und die
     * es hier nicht gibt – angelegt werden dürfen sie nur mit dem Recht, den
     * Katalog zu pflegen. Gezählt wird wie beim Anwenden (CEE63 ist cee63).
     *
     * @return list<string> Schlüssel, sortiert
     */
    public static function fehlendeSteckertypen(array $strom): array
    {
        $hier = [];
        foreach (AnlagenService::steckertypen() as $t) {
            $hier[(string) $t['schluessel']] = true;
        }
        $fehlt = [];
        foreach (LocationBundle::festeAnlagen($strom) as $a) {
            foreach ((array) ($a['anschluesse'] ?? []) as $an) {
                $k = is_array($an) && is_scalar($an['stecker'] ?? null) ? (string) $an['stecker'] : '';
                if ($k === '' || isset($hier[$k]) || isset($hier[LocationBundle::schluessel($k, 'stecker')])) {
                    continue;
                }
                $fehlt[$k] = true;
            }
        }
        $liste = array_map('strval', array_keys($fehlt));
        sort($liste);
        return $liste;
    }

    /** Ein Text von außen: nur Zeichenketten und Zahlen, gekürzt. */
    private static function text(mixed $wert, int $laenge): string
    {
        return is_string($wert) || is_int($wert) || is_float($wert) ? mb_substr(trim((string) $wert), 0, $laenge) : '';
    }
}
