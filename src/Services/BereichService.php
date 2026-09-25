<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Bereiche: Last mehrerer Anlagen und Abgänge zusammengerechnet.
 *
 * Beispiel Foodtruck-Meile: drei Aggregate und mehrere Verteilungen
 * versorgen die Trucks – die Aggregate aber auch Bühne und Licht. Die
 * Aggregate zu addieren wäre falsch. Ein Bereich zählt deshalb seine
 * Mitglieder: ganze Anlagen (deren Einspeisung bzw. die Summe ihrer
 * gemessenen Abgänge) und einzelne Messgeräte an Abgängen (der Shelly am
 * CEE-Abgang, an dem ein Truck hängt).
 *
 * Doppelt gezählt wird nichts: Was hinter einem anderen Mitglied hängt
 * (eine nachgeschaltete Verteilung, ein Abgang einer Mitglieds-Verteilung,
 * die Verteilung hinter einem gemessenen Abgang), steckt in dessen Wert
 * schon drin. Das ergibt sich aus den eingezeichneten Kabeln.
 *
 * Summiert wird zeitgleich: Die Spitze eines Bereichs ist die höchste
 * gleichzeitige Last, nicht die Summe der einzelnen Spitzen.
 */
final class BereichService
{
    // ------------------------------------------------------------ Lesen

    /** Alle Bereiche, die der Benutzer sehen darf. @return list<array<string,mixed>> */
    public static function alle(?int $siteId = null): array
    {
        $rows = Database::select(
            'SELECT b.*, s.name AS site_name,
                    (SELECT COUNT(*) FROM bereich_mitglieder m WHERE m.bereich_id = b.id) AS mitglieder_zahl
             FROM bereiche b LEFT JOIN sites s ON s.id = b.site_id'
            . ($siteId !== null ? ' WHERE b.site_id = ' . $siteId : '') . ' ORDER BY s.name, b.name'
        );
        return array_values(array_filter($rows, [self::class, 'sichtbar']));
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT b.*, s.name AS site_name FROM bereiche b LEFT JOIN sites s ON s.id = b.site_id WHERE b.id = ?', [$id]);
    }

    public static function sichtbar(array $bereich): bool
    {
        return $bereich['site_id'] !== null ? Zugriff::siehtStandort((int) $bereich['site_id']) : Zugriff::alles();
    }

    /** @return list<array<string,mixed>> */
    public static function mitglieder(int $bereichId): array
    {
        return Database::select('SELECT * FROM bereich_mitglieder WHERE bereich_id = ? ORDER BY id', [$bereichId]);
    }

    /** @return list<string> „o:12“ / „m:34“ */
    public static function mitgliedSchluessel(int $bereichId): array
    {
        return array_map(static fn ($m) => $m['objekt_id'] !== null ? 'o:' . (int) $m['objekt_id'] : 'm:' . (int) $m['messpunkt_id'], self::mitglieder($bereichId));
    }

    // ------------------------------------------------------------ Schreiben

    /**
     * @param array{name?:string,site_id?:int|string,farbe?:string,grenze_kw?:mixed,grenze_a?:mixed,notizen?:string,mitglieder?:list<string>} $daten
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $name = trim((string) ($daten['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'Der Bereich braucht einen Namen.'];
        }
        $siteId = (int) ($daten['site_id'] ?? 0);
        if ($siteId <= 0 || Database::first('SELECT id FROM sites WHERE id = ?', [$siteId]) === null) {
            return ['ok' => false, 'message' => 'Bitte einen Standort wählen.'];
        }
        $zahl = static function (mixed $wert): ?float {
            $wert = trim(str_replace(',', '.', (string) $wert));
            return $wert === '' || !is_numeric($wert) || (float) $wert <= 0 ? null : (float) $wert;
        };
        $felder = [
            'site_id'   => $siteId,
            'name'      => mb_substr($name, 0, 190),
            'farbe'     => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($daten['farbe'] ?? '')) === 1 ? (string) $daten['farbe'] : '',
            'grenze_kw' => $zahl($daten['grenze_kw'] ?? ''),
            'grenze_a'  => $zahl($daten['grenze_a'] ?? ''),
            'notizen'   => (string) ($daten['notizen'] ?? ''),
            'updated_at' => gmdate('c'),
        ];

        // Mitglieder: nur Anlagen und Messgeräte dieses Standorts.
        $mitglieder = [];
        foreach ((array) ($daten['mitglieder'] ?? []) as $schluessel) {
            if (preg_match('/^([om]):(\d+)$/', (string) $schluessel, $m) !== 1) {
                continue;
            }
            $zeile = $m[1] === 'o'
                ? Database::first('SELECT id FROM objekte WHERE id = ? AND site_id = ? AND ist_vorlage = 0', [(int) $m[2], $siteId])
                : Database::first('SELECT mp.id FROM messpunkte mp JOIN objekte o ON o.id = mp.objekt_id WHERE mp.id = ? AND o.site_id = ?', [(int) $m[2], $siteId]);
            if ($zeile !== null) {
                $mitglieder[$m[1] . ':' . (int) $m[2]] = [$m[1] === 'o' ? (int) $m[2] : null, $m[1] === 'm' ? (int) $m[2] : null];
            }
        }

        if ($id !== null) {
            if (self::find($id) === null) {
                return ['ok' => false, 'message' => 'Diesen Bereich gibt es nicht.'];
            }
            Database::update('bereiche', $id, $felder);
            Audit::log('bereich.update', 'bereich', (string) $id, ['name' => $name]);
        } else {
            $felder['created_at'] = gmdate('c');
            $id = Database::insert('bereiche', $felder);
            Audit::log('bereich.create', 'bereich', (string) $id, ['name' => $name]);
        }
        Database::execute('DELETE FROM bereich_mitglieder WHERE bereich_id = ?', [$id]);
        foreach ($mitglieder as [$objektId, $messpunktId]) {
            Database::insert('bereich_mitglieder', ['bereich_id' => $id, 'objekt_id' => $objektId, 'messpunkt_id' => $messpunktId, 'created_at' => gmdate('c')]);
        }
        return ['ok' => true, 'message' => 'Bereich „' . $name . '“ gespeichert (' . count($mitglieder) . ' Mitglieder).', 'id' => $id];
    }

    public static function loeschen(int $id): array
    {
        $b = self::find($id);
        if ($b === null) {
            return ['ok' => false, 'message' => 'Diesen Bereich gibt es nicht.'];
        }
        Database::execute('DELETE FROM bereich_mitglieder WHERE bereich_id = ?', [$id]);
        Database::delete('bereiche', $id);
        Audit::log('bereich.delete', 'bereich', (string) $id, ['name' => $b['name']]);
        return ['ok' => true, 'message' => 'Bereich „' . $b['name'] . '“ gelöscht.'];
    }

    /** Aufräumen, wenn eine Anlage (samt Messgeräten) gelöscht wird. */
    public static function objektEntfernt(int $objektId): void
    {
        Database::execute(
            'DELETE FROM bereich_mitglieder WHERE objekt_id = ? OR messpunkt_id IN (SELECT id FROM messpunkte WHERE objekt_id = ?)',
            [$objektId, $objektId]
        );
    }

    public static function messpunktEntfernt(int $messpunktId): void
    {
        Database::execute('DELETE FROM bereich_mitglieder WHERE messpunkt_id = ?', [$messpunktId]);
    }

    public static function standortEntfernt(int $siteId): void
    {
        Database::execute('DELETE FROM bereich_mitglieder WHERE bereich_id IN (SELECT id FROM bereiche WHERE site_id = ?)', [$siteId]);
        Database::execute('DELETE FROM bereiche WHERE site_id = ?', [$siteId]);
    }

    // ------------------------------------------------------------ Aufbau der Summe

    /**
     * Die Mitglieder mit allem, was die Summe braucht.
     *
     * Je Eintrag: welche Messgeräte ihn messen (quellen), ob er die ganze
     * Anlage meint (ganz) oder nur einen Abgang (zweig = die Anlage hinter
     * diesem Abgang), und ob er in einem anderen Mitglied schon enthalten ist.
     *
     * @return list<array<string,mixed>>
     */
    public static function aufbau(array $bereich): array
    {
        $siteId = (int) $bereich['site_id'];
        $objekte = [];
        foreach (AnlagenService::liste(['site_id' => $siteId]) as $o) {
            $objekte[(int) $o['id']] = $o;
        }
        $kinder = [];
        $hinterAbgang = [];
        foreach (Database::select(
            "SELECT l.von_objekt_id, l.zu_objekt_id, l.von_anschluss_id FROM leitungen l JOIN floors f ON f.id = l.floor_id
             WHERE f.site_id = ? AND l.art = 'kabel' AND l.von_objekt_id IS NOT NULL AND l.zu_objekt_id IS NOT NULL",
            [$siteId]
        ) as $l) {
            $kinder[(int) $l['von_objekt_id']][] = (int) $l['zu_objekt_id'];
            if ($l['von_anschluss_id'] !== null) {
                $hinterAbgang[(int) $l['von_anschluss_id']] = (int) $l['zu_objekt_id'];
            }
        }
        $messpunkte = Database::select(
            'SELECT m.*, a.phase AS anschluss_phase FROM messpunkte m LEFT JOIN anschluesse a ON a.id = m.anschluss_id
             JOIN objekte o ON o.id = m.objekt_id WHERE o.site_id = ? ORDER BY m.id',
            [$siteId]
        );
        $jeObjekt = [];
        $nachId = [];
        foreach ($messpunkte as $m) {
            $jeObjekt[(int) $m['objekt_id']][] = $m;
            $nachId[(int) $m['id']] = $m;
        }

        // Was eine ganze Anlage misst: ihr Hauptmessgerät – oder, wenn das an
        // einem Abgang sitzt, alle gemessenen Abgänge.
        $ganzeAnlage = static function (array $aktive): array {
            $haupt = MesspunktService::haupt($aktive);
            if ($haupt === null) {
                return [];
            }
            return $haupt['anschluss_id'] !== null ? array_values(array_filter($aktive, static fn ($m) => $m['anschluss_id'] !== null)) : [$haupt];
        };
        $eintraege = [];
        foreach (self::mitglieder((int) $bereich['id']) as $mg) {
            if ($mg['objekt_id'] !== null) {
                $o = $objekte[(int) $mg['objekt_id']] ?? null;
                if ($o === null) {
                    continue;
                }
                $eigene = $jeObjekt[(int) $o['id']] ?? [];
                $aktive = array_values(array_filter($eigene, static fn ($m) => MesspunktService::aktiv($m)));
                $quellen = $ganzeAnlage($aktive);
                $eintraege[] = [
                    'schluessel' => 'o:' . (int) $o['id'], 'typ' => 'objekt', 'titel' => $o['name'], 'untertitel' => $o['art_anzeige'],
                    'objekt' => $o, 'messpunkt' => null, 'wurzel' => (int) $o['id'], 'ganz' => true, 'zweig' => null,
                    'quellen' => $quellen,
                    'zeitquellen' => self::zeitquellen($eigene, $ganzeAnlage),
                    'hinweis' => $quellen !== [] && $quellen[0]['anschluss_id'] !== null ? 'Summe der gemessenen Abgänge' : '',
                ];
                continue;
            }
            $m = $nachId[(int) $mg['messpunkt_id']] ?? null;
            $o = $m !== null ? ($objekte[(int) $m['objekt_id']] ?? null) : null;
            if ($o === null) {
                continue;
            }
            // Ein Mitglied „Abgang“ meint die Stelle, nicht das eine Gerät: Was
            // dort zu welcher Zeit gesteckt hat (Inventar-Einsätze), zählt.
            $stelle = array_values(array_filter($jeObjekt[(int) $o['id']] ?? [], static fn ($x) => $x['anschluss_id'] === $m['anschluss_id']
                && ($m['anschluss_id'] !== null || $x['rolle'] === $m['rolle'])));
            $aktive = array_values(array_filter($stelle, static fn ($x) => MesspunktService::aktiv($x)));
            $jetzt = $aktive !== [] ? $aktive[count($aktive) - 1] : $m;
            $eintraege[] = [
                'schluessel' => 'm:' . (int) $m['id'], 'typ' => 'messpunkt',
                'titel' => MesspunktService::titel(['gueltig_bis' => null] + $jetzt + ['objekt_name' => $o['name']]), 'untertitel' => $o['name'],
                'objekt' => $o, 'messpunkt' => $jetzt, 'wurzel' => (int) $o['id'],
                // Ein Messgerät an der Einspeisung steht für die ganze Anlage.
                'ganz' => $m['anschluss_id'] === null,
                'zweig' => $m['anschluss_id'] !== null ? ($hinterAbgang[(int) $m['anschluss_id']] ?? null) : null,
                'quellen' => $aktive,
                'zeitquellen' => self::zeitquellen($stelle, static fn (array $a): array => $a),
                'hinweis' => '',
            ];
        }

        // Teilbaum ab einer Anlage (sie selbst eingeschlossen).
        $teilbaum = static function (int $wurzel) use ($kinder): array {
            $gesehen = [$wurzel => true];
            $offen = [$wurzel];
            while ($offen !== []) {
                $x = array_pop($offen);
                foreach ($kinder[$x] ?? [] as $k) {
                    if (!isset($gesehen[$k])) {
                        $gesehen[$k] = true;
                        $offen[] = $k;
                    }
                }
            }
            return $gesehen;
        };
        $deckt = [];
        foreach ($eintraege as $i => $e) {
            $deckt[$i] = $e['ganz'] ? $teilbaum($e['wurzel']) : ($e['zweig'] !== null ? $teilbaum($e['zweig']) : []);
        }
        // $b enthält $a, wenn $a's Anlage im gemessenen Teil von $b liegt.
        $enthaelt = static fn (int $b, int $a): bool => isset($deckt[$b][$eintraege[$a]['wurzel']]);
        foreach ($eintraege as $i => &$a) {
            $a['sichtbar'] = Zugriff::siehtObjekt($a['objekt']);
            $a['enthalten_in'] = null;
            foreach ($eintraege as $j => $b) {
                if ($i === $j || !$enthaelt($j, $i)) {
                    continue;
                }
                // Enthalten sie sich gegenseitig (dieselbe Anlage, zweimal
                // gewählt), zählt die Anlage vor ihrem Messgerät, sonst das
                // zuerst gewählte.
                if ($enthaelt($i, $j)) {
                    $bZuerst = ($b['typ'] === 'objekt' && $a['typ'] === 'messpunkt') || ($b['typ'] === $a['typ'] && $j < $i);
                    if (!$bZuerst) {
                        continue;
                    }
                }
                $a['enthalten_in'] = $b['titel'];
                break;
            }
        }
        unset($a);
        return $eintraege;
    }

    // ------------------------------------------------------------ Livewerte

    /**
     * Livewerte des Bereichs: Summe der zählenden Mitglieder, je Phase.
     *
     * @return array<string,mixed>
     */
    public static function live(array $bereich, ?array $aufbau = null): array
    {
        $aufbau ??= self::aufbau($bereich);
        $quellen = [];
        $objekte = [];
        foreach ($aufbau as $e) {
            if (!$e['sichtbar']) {
                continue;
            }
            $objekte[(int) $e['objekt']['id']] = $e['objekt'];
            foreach ($e['quellen'] as $m) {
                $quellen[(int) $m['id']] = $m;
            }
        }
        $live = MesspunktService::live(array_values($quellen), $objekte);
        $jetztMs = time() * 1000;

        $paare = [];
        $mitglieder = [];
        $zaehler = ['ohne_messung' => 0, 'stumm' => 0, 'verborgen' => 0, 'enthalten' => 0];
        $alter = null;
        foreach ($aufbau as $e) {
            $schluessel = $e['schluessel'];
            if (!$e['sichtbar']) {
                $zaehler['verborgen']++;
                $mitglieder[$schluessel] = ['p' => null, 'status' => 'nicht freigegeben', 'zaehlt' => false];
                continue;
            }
            $eigene = [];
            $eigenesAlter = null;
            foreach ($e['quellen'] as $m) {
                $w = $live[(int) $m['id']] ?? null;
                if ($w !== null) {
                    $eigene[] = [$m, $w];
                    if ($w['zeit'] !== null) {
                        $a = max(0, intdiv($jetztMs - (int) $w['zeit'], 1000));
                        $eigenesAlter = $eigenesAlter === null ? $a : max($eigenesAlter, $a);
                    }
                }
            }
            $summe = $eigene === [] ? null : LiveService::summe($eigene);
            $status = match (true) {
                $e['quellen'] === []                                                  => 'ohne Messgerät',
                $eigene === []                                                        => 'keine Werte',
                $eigenesAlter !== null && $eigenesAlter > LiveService::STUMM_NACH     => 'stumm',
                default                                                               => 'ok',
            };
            $zaehlt = $e['enthalten_in'] === null;
            $mitglieder[$schluessel] = [
                'p' => isset($summe['phasen']['sum']['p']) ? round((float) $summe['phasen']['sum']['p'], 1) : null,
                'status' => $status, 'zaehlt' => $zaehlt,
            ];
            if (!$zaehlt) {
                $zaehler['enthalten']++;
                continue;
            }
            if ($status === 'ohne Messgerät') {
                $zaehler['ohne_messung']++;
            } elseif ($status !== 'ok') {
                $zaehler['stumm']++;
            }
            array_push($paare, ...$eigene);
            if ($eigenesAlter !== null) {
                $alter = $alter === null ? $eigenesAlter : max($alter, $eigenesAlter);
            }
        }

        $summe = $paare === [] ? null : LiveService::summe($paare);
        $p = isset($summe['phasen']['sum']['p']) ? round((float) $summe['phasen']['sum']['p'], 1) : null;
        $i = [];
        $u = [];
        foreach (['L1', 'L2', 'L3', 'sum'] as $l) {
            if (isset($summe['phasen'][$l]['i'])) {
                $i[$l] = round((float) $summe['phasen'][$l]['i'], 2);
            }
            if ($l !== 'sum' && isset($summe['phasen'][$l]['u'])) {
                $u[$l] = round((float) $summe['phasen'][$l]['u'], 1);
            }
        }
        foreach ($mitglieder as &$m) {
            $m['anteil'] = $m['zaehlt'] && $m['p'] !== null && $p !== null && $p > 0 ? round($m['p'] / $p * 100, 1) : null;
        }
        unset($m);

        $auslastung = null;
        if ($p !== null && $bereich['grenze_kw'] !== null && (float) $bereich['grenze_kw'] > 0) {
            $auslastung = $p / ((float) $bereich['grenze_kw'] * 1000) * 100;
        }
        $iMax = array_intersect_key($i, ['L1' => 1, 'L2' => 1, 'L3' => 1]) !== [] ? max(array_intersect_key($i, ['L1' => 1, 'L2' => 1, 'L3' => 1])) : null;
        if ($iMax !== null && $bereich['grenze_a'] !== null && (float) $bereich['grenze_a'] > 0) {
            $auslastung = max($auslastung ?? 0.0, $iMax / (float) $bereich['grenze_a'] * 100);
        }
        $auslastung = $auslastung !== null ? round($auslastung, 1) : null;

        $hinweise = [];
        if ($zaehler['ohne_messung'] > 0) {
            $hinweise[] = $zaehler['ohne_messung'] . ' Mitglied(er) ohne Messgerät – nicht in der Summe.';
        }
        if ($zaehler['stumm'] > 0) {
            $hinweise[] = $zaehler['stumm'] . ' Mitglied(er) ohne aktuelle Werte.';
        }
        if ($zaehler['verborgen'] > 0) {
            $hinweise[] = $zaehler['verborgen'] . ' Mitglied(er) nicht freigegeben – die Summe ist unvollständig.';
        }
        if ($zaehler['enthalten'] > 0) {
            $hinweise[] = $zaehler['enthalten'] . ' Mitglied(er) stecken in einem anderen und zählen nicht doppelt.';
        }

        return [
            'p'          => $p,
            'i'          => $i,
            'u'          => $u,
            'phasen'     => $summe !== null ? LiveService::phasenDetail($summe, $p) : [],
            'auslastung' => $auslastung,
            'alter_s'    => $alter,
            'stufe'      => match (true) {
                $p === null                                          => $paare === [] ? 'keine' : 'stumm',
                $alter !== null && $alter > LiveService::STUMM_NACH  => 'stumm',
                $auslastung === null                                 => 'ok',
                $auslastung >= LiveService::grenzeAlarm()            => 'bad',
                $auslastung >= LiveService::grenzeWarnung()          => 'warn',
                default                                              => 'ok',
            },
            'mitglieder' => $mitglieder,
            'hinweise'   => $hinweise,
            'hinweis'    => implode(' ', $hinweise),
        ];
    }

    // ------------------------------------------------------------ Verläufe

    /**
     * Summenverlauf des Bereichs, zeitgleich addiert.
     *
     * Jedes Messgerät bringt seinen Verlauf im Datenfenster seiner Anlage;
     * gleiche Zeitraster (GROUP BY time) machen die Punkte vergleichbar.
     * Einphasige Messgeräte zählen auf der Phase ihres Abgangs.
     *
     * @return array{zeiten:list<int>,serien:array<string,list<?float>>,schritt:int}
     */
    public static function reihe(array $bereich, string $feld, int $von, int $bis, ?array $aufbau = null, ?string &$fehler = null): array
    {
        $feld = $feld === 'i' ? 'i' : 'p';
        $aufbau ??= self::aufbau($bereich);
        $schritt = Messdaten::schritt($von, min($bis, time()));
        $summen = [];
        foreach (self::zaehlendeQuellen($aufbau) as [$m, , $fenster]) {
            $r = Messdaten::reihe('strom', (string) $m['geraet'], (string) $m['kanal'], $feld, ['L1', 'L2', 'L3', 'sum'], $von, $bis,
                $fenster, $schritt, 'mean', $fehler);
            $phase = in_array((string) ($m['anschluss_phase'] ?? ''), ['L1', 'L2', 'L3'], true) ? (string) $m['anschluss_phase'] : null;
            $hatPhasen = false;
            foreach (['L1', 'L2', 'L3'] as $l) {
                foreach ($r['serien'][$l] ?? [] as $w) {
                    if ($w !== null) {
                        $hatPhasen = true;
                        break 2;
                    }
                }
            }
            foreach ($r['zeiten'] as $k => $ms) {
                $gesamt = $r['serien']['sum'][$k] ?? null;
                if ($hatPhasen) {
                    $teil = null;
                    foreach (['L1', 'L2', 'L3'] as $l) {
                        $w = $r['serien'][$l][$k] ?? null;
                        if ($w !== null) {
                            $summen[$ms][$l] = ($summen[$ms][$l] ?? 0.0) + $w;
                            $teil = ($teil ?? 0.0) + $w;
                        }
                    }
                    if ($feld === 'p') {
                        $gesamt ??= $teil;
                    } else {
                        $gesamt = null; // Ströme verschiedener Phasen addiert man nicht
                    }
                } elseif ($gesamt !== null && ($phase !== null || $feld === 'i')) {
                    $ziel = $phase ?? 'sum';
                    $summen[$ms][$ziel] = ($summen[$ms][$ziel] ?? 0.0) + $gesamt;
                    if ($feld === 'i') {
                        $gesamt = null;
                    }
                }
                if ($gesamt !== null) {
                    $summen[$ms]['sum'] = ($summen[$ms]['sum'] ?? 0.0) + $gesamt;
                }
            }
        }
        ksort($summen);
        $zeiten = array_keys($summen);
        $serien = [];
        foreach (['L1', 'L2', 'L3', 'sum'] as $l) {
            $werte = array_map(static fn ($ms) => isset($summen[$ms][$l]) ? round($summen[$ms][$l], 3) : null, $zeiten);
            if (array_filter($werte, static fn ($w) => $w !== null) !== []) {
                $serien[$l] = $werte;
            }
        }
        return ['zeiten' => $zeiten, 'serien' => $serien, 'schritt' => $schritt];
    }

    /** Energie je Tag in Wh – die Summe der zählenden Messgeräte. @return array<string,float> */
    public static function energieTage(array $bereich, int $von, int $bis, ?array $aufbau = null): array
    {
        $aufbau ??= self::aufbau($bereich);
        $tage = [];
        foreach (self::zaehlendeQuellen($aufbau) as [$m, , $fenster]) {
            foreach (Messdaten::energieTage((string) $m['geraet'], (string) $m['kanal'], $von, $bis, $fenster) as $tag => $wh) {
                $tage[$tag] = ($tage[$tag] ?? 0.0) + $wh;
            }
        }
        ksort($tage);
        return $tage;
    }

    /**
     * Kennzahlen: höchste gleichzeitige Last, Mittel, Energie, höchster
     * Phasenstrom – aus den Summenverläufen.
     *
     * @return array<string,?float>
     */
    public static function kennzahlen(array $bereich, int $von, int $bis, ?array $aufbau = null): array
    {
        $aufbau ??= self::aufbau($bereich);
        $p = self::reihe($bereich, 'p', $von, $bis, $aufbau);
        $i = self::reihe($bereich, 'i', $von, $bis, $aufbau);
        $leistung = array_values(array_filter($p['serien']['sum'] ?? [], static fn ($w) => $w !== null));
        $stroeme = [];
        foreach (['L1', 'L2', 'L3', 'sum'] as $l) {
            foreach ($i['serien'][$l] ?? [] as $w) {
                if ($w !== null) {
                    $stroeme[] = $w;
                }
            }
        }
        $energie = array_sum(self::energieTage($bereich, $von, $bis, $aufbau));
        return [
            'p_max'      => $leistung === [] ? null : max($leistung),
            'p_mittel'   => $leistung === [] ? null : array_sum($leistung) / count($leistung),
            'i_max'      => $stroeme === [] ? null : max($stroeme),
            'energie_wh' => $energie > 0 ? $energie : null,
            'schritt'    => (float) $p['schritt'],
        ];
    }

    /**
     * Messpunkte über die Zeit: Welches Gerät zählte wann? Die Gültigkeiten
     * (Inventar-Einsätze) teilen die Zeit in Abschnitte; in jedem wählt
     * $auswahl aus den dann steckenden Geräten.
     *
     * @param list<array<string,mixed>> $messpunkte
     * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $auswahl
     * @return list<array{m:array<string,mixed>,teile:list<array{0:?int,1:?int}>}>
     */
    private static function zeitquellen(array $messpunkte, callable $auswahl): array
    {
        $punkte = [];
        foreach ($messpunkte as $m) {
            foreach (['gueltig_ab', 'gueltig_bis'] as $f) {
                $t = Zeit::ts($m[$f] ?? null);
                if ($t !== null) {
                    $punkte[$t] = true;
                }
            }
        }
        ksort($punkte);
        $grenzen = array_keys($punkte);
        // Abschnitte: (−∞, t1), [t1, t2) … [tn, +∞) – ohne Grenzen einer.
        $abschnitte = [];
        $vorher = null;
        foreach ($grenzen as $t) {
            $abschnitte[] = [$vorher, $t];
            $vorher = $t;
        }
        $abschnitte[] = [$vorher, null];
        $out = [];
        foreach ($abschnitte as [$lo, $hi]) {
            $stichtag = $lo ?? ($hi !== null ? $hi - 1 : time());
            $aktive = array_values(array_filter($messpunkte, static fn ($m) => MesspunktService::aktiv($m, $stichtag)));
            foreach ($auswahl($aktive) as $m) {
                $id = (int) $m['id'];
                $out[$id] ??= ['m' => $m, 'teile' => []];
                $n = count($out[$id]['teile']);
                if ($n > 0 && $out[$id]['teile'][$n - 1][1] === $lo) {
                    $out[$id]['teile'][$n - 1][1] = $hi;   // schließt an
                } else {
                    $out[$id]['teile'][] = [$lo, $hi];
                }
            }
        }
        return array_values($out);
    }

    /**
     * Die zählenden Messpunkte über die Zeit, je mit dem Datenfenster, in dem
     * sie zählen (Freigabe des Benutzers ∩ Zeit, in der sie zählten).
     *
     * @return list<array{0:array<string,mixed>,1:array<string,mixed>,2:?array}> [Messpunkt, Anlage, Fenster]
     */
    private static function zaehlendeQuellen(array $aufbau): array
    {
        $out = [];
        foreach ($aufbau as $e) {
            if ($e['enthalten_in'] !== null || !$e['sichtbar']) {
                continue;
            }
            $basis = Zugriff::datenFenster($e['objekt']);
            foreach ($e['zeitquellen'] as ['m' => $m, 'teile' => $teile]) {
                $fenster = [];
                foreach ($teile as [$ab, $bis]) {
                    $teil = Zugriff::schneiden($basis, $ab, $bis);
                    if ($teil === null) {
                        $fenster = null;
                        break;
                    }
                    array_push($fenster, ...$teil);
                }
                $out[(int) $m['id']] = [$m, $e['objekt'], $fenster];
            }
        }
        return array_values($out);
    }
}
