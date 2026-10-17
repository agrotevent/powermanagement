<?php
declare(strict_types=1);

namespace App\Tanks;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\PlanGeoService;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Zeit;

/**
 * Tanks: Anlagen der Art „tank“.
 *
 * Ein Tank steht im Plan wie jede Anlage – mit Ort, Freigaben, Aufbau und
 * Abgleichen –, hat aber keine Anschlüsse, keinen Nennstrom und keine
 * Leistung (AnlagenService::elektrisch()). Was ihn ausmacht – Nennvolumen,
 * Kraftstoff, Bauform, Maße, Zulassung –, steht in seinen Eigenschaften
 * (AnlagenService::FELDER['tank']).
 *
 * Hier liegt, was nur Tanks brauchen; die Anlagenseite eines Tanks
 * (anlagen/tank.php) bekommt ihre Daten aus seitenDaten().
 *
 * Gemessen wird mit einem **Tanksensor** aus dem Inventar: Eingesetzt wird
 * er zum Messpunkt der Rolle „fuellstand“ am Tank. Wie aus seinem Rohwert
 * Füllhöhe und Liter werden, steht in **Fassungen** (tank_kennlinien) –
 * je eine vollständige Rechnung (TankRechnung) aus Sensordaten, Einbau und
 * Tank. Für einen Wert zur Zeit t gilt die Fassung mit der größten id unter
 * allen mit ab ≤ t. Fassungen werden nie geändert: Was sich am Sensor, am
 * Shelly oder am Tank ändert, ergibt eine neue ab jetzt; fassungAnlegen()
 * ist die einzige Stelle, die schreibt, und kennt nur INSERT.
 */
final class TankService
{
    /** Wodurch eine Fassung entstand (tank_kennlinien.anlass). */
    public const ANLAESSE = [
        'einsatz'      => 'Einbau',
        'shelly'       => 'Shelly getauscht',
        'sensor'       => 'Sensordaten geändert',
        'tank'         => 'Tankdaten geändert',
        'peilung'      => 'Peilung',
        'betankung'    => 'Betankung',
        'hand'         => 'von Hand',
        'rueckwirkend' => 'von Hand, rückwirkend',
        'zurueck'      => 'Ereignis gelöscht',
    ];

    /**
     * Kurzform für Listen, Karte und Katalog: „3.000 l · Diesel · Quader“ –
     * an der Stelle, an der bei einer elektrischen Anlage die Anschlüsse
     * stehen.
     */
    public static function kurzform(array $objekt): string
    {
        $eigenschaften = $objekt['eigenschaften'] ?? [];
        if (!is_array($eigenschaften)) {
            $eigenschaften = json_decode((string) $eigenschaften, true) ?: [];
        }
        $teile = [];
        $inhalt = $eigenschaften['inhalt_l'] ?? null;
        if (is_numeric($inhalt) && (float) $inhalt > 0) {
            $teile[] = self::liter((float) $inhalt);
        }
        foreach (['kraftstoff', 'bauform'] as $schluessel) {
            $wert = trim((string) ($eigenschaften[$schluessel] ?? ''));
            if ($wert !== '') {
                $teile[] = $wert;
            }
        }
        return $teile === [] ? 'Nennvolumen nicht eingetragen' : implode(' · ', $teile);
    }

    /** „3.000 l“, „1.250,5 l“ – Liter mit Tausenderpunkt. */
    public static function liter(float $liter): string
    {
        return self::zahl(round($liter, 1)) . ' l';
    }

    /** Eine Zahl mit Tausenderpunkt und nur so vielen Stellen, wie sie hat: „3.000“, „2,78“. */
    public static function zahl(float $wert): string
    {
        return TankRechnung::zahl($wert);
    }

    /** Die Beschriftung eines Felds ohne den Hinweis fürs Formular: „Füllgrenze (leer = 95 %)“ → „Füllgrenze“. */
    public static function beschriftung(string $beschriftung): string
    {
        return (string) preg_replace('/\s*\(leer = [^)]*\)$/u', '', $beschriftung);
    }

    /**
     * Was die Seite eines Tanks zeigt: seine Daten, gruppiert wie im
     * Formular, und was der Betrachter damit tun darf.
     *
     * @return array<string,mixed>
     */
    public static function seitenDaten(array $tank): array
    {
        $vorlage = (int) $tank['ist_vorlage'] === 1;
        $eigenschaften = (array) $tank['eigenschaften'];
        $gruppen = [];
        foreach (AnlagenService::feldGruppen((string) $tank['art']) as $titel => $schluessel) {
            $zeilen = [];
            foreach ($schluessel as $k) {
                if (!array_key_exists($k, $eigenschaften)) {
                    continue;
                }
                [$beschriftung, $typ, $zusatz] = AnlagenService::FELDER[(string) $tank['art']][$k];
                $wert = $eigenschaften[$k];
                $zeilen[] = [
                    'beschriftung' => self::beschriftung($beschriftung),
                    'wert'         => is_float($wert) || is_int($wert) ? self::zahl((float) $wert) : (string) $wert,
                    'einheit'      => $typ !== 'auswahl' ? (string) $zusatz : '',
                ];
            }
            if ($zeilen !== []) {
                $gruppen[$titel] = $zeilen;
            }
        }
        $darfAendern = $vorlage ? (Auth::can('stecker.manage') || Zugriff::alles()) : Zugriff::darfBearbeiten($tank);
        // Tanksensor: wer am Tank bearbeiten darf, baut ihn ein und aus.
        $sensor = !$vorlage && $darfAendern ? self::sensor((int) $tank['id']) : null;
        $fassung = $sensor !== null ? self::fassungJetzt((int) $sensor['id']) : null;
        $frei = !$vorlage && $darfAendern && Auth::can('datenquellen.view')
            ? array_values(array_filter(InventarService::fuerBetrachter(InventarService::liste(['art' => 'tanksensor'])),
                static fn (array $i): bool => $i['zustand'] !== 'defekt' && (int) ($i['einsatz']['objekt_id'] ?? 0) !== (int) $tank['id']))
            : [];
        return [
            'objekt'      => $tank,
            'vorlage'     => $vorlage,
            'kurzform'    => self::kurzform($tank),
            'gruppen'     => $gruppen,
            'lage'        => $vorlage ? null : PlanGeoService::fuerObjekt($tank),
            'darfAendern' => $darfAendern,
            'sensor'      => $sensor,
            'fassung'     => $fassung,
            'hinweise'    => $fassung !== null ? TankRechnung::hinweise($fassung['rechnung']) : [],
            'sensorFrei'  => $frei,
        ];
    }

    // ------------------------------------------------------------ Sensor und Fassungen

    /**
     * Der Tanksensor, der jetzt im Tank misst: sein laufender Messpunkt
     * (Rolle „fuellstand“) mit Einsatz und Inventarstück – oder null. Ein
     * neuer Sensor ersetzt den alten; stünden aus älterer Zeit doch zwei da,
     * zählt der erste.
     */
    public static function sensor(int $tankId): ?array
    {
        $m = Database::first(
            "SELECT m.*, e.inventar_id, e.ab AS einsatz_ab, i.kennung, i.name AS inventar_name, i.eigenschaften AS sensordaten
             FROM messpunkte m LEFT JOIN einsaetze e ON e.id = m.einsatz_id LEFT JOIN inventar i ON i.id = e.inventar_id
             WHERE m.objekt_id = ? AND m.rolle = 'fuellstand' AND m.gueltig_bis IS NULL ORDER BY m.id LIMIT 1",
            [$tankId]
        );
        return $m === null ? null : self::sensorAufbereiten($m);
    }

    /** Alle Tanksensoren, die je in diesem Tank gemessen haben – der laufende zuerst, dann die jüngeren. @return list<array<string,mixed>> */
    public static function sensoren(int $tankId): array
    {
        return array_map([self::class, 'sensorAufbereiten'], Database::select(
            "SELECT m.*, e.inventar_id, e.ab AS einsatz_ab, i.kennung, i.name AS inventar_name, i.eigenschaften AS sensordaten
             FROM messpunkte m LEFT JOIN einsaetze e ON e.id = m.einsatz_id LEFT JOIN inventar i ON i.id = e.inventar_id
             WHERE m.objekt_id = ? AND m.rolle = 'fuellstand' ORDER BY m.gueltig_bis IS NOT NULL, m.gueltig_ab DESC, m.id DESC",
            [$tankId]
        ));
    }

    /**
     * Die Fassungen eines Messpunkts, älteste zuerst, mit dekodierter Rechnung.
     *
     * @return list<array{id:int,messpunkt_id:int,objekt_id:int,ab:string,ab_ts:int,rechnung:array,anlass:string,ereignis_id:?int,geprueft:bool,von:string,notizen:string,created_at:string}>
     */
    public static function fassungen(int $messpunktId): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM tank_kennlinien WHERE messpunkt_id = ? ORDER BY id', [$messpunktId]) as $f) {
            $rechnung = json_decode((string) $f['rechnung'], true);
            if (!is_array($rechnung) || TankRechnung::pruefen($rechnung) !== null) {
                continue;    // eine kaputte Zeile rechnet nicht – der Rohwert bleibt
            }
            $out[] = [
                'id' => (int) $f['id'], 'messpunkt_id' => (int) $f['messpunkt_id'], 'objekt_id' => (int) $f['objekt_id'],
                'ab' => (string) $f['ab'], 'ab_ts' => (int) Zeit::ts((string) $f['ab']), 'rechnung' => $rechnung,
                'anlass' => (string) $f['anlass'], 'ereignis_id' => $f['ereignis_id'] !== null ? (int) $f['ereignis_id'] : null,
                'geprueft' => (int) $f['geprueft'] === 1, 'von' => (string) $f['von'], 'notizen' => (string) ($f['notizen'] ?? ''),
                'created_at' => (string) $f['created_at'],
            ];
        }
        return $out;
    }

    /** Die Fassung, die zur Zeit $zeit gilt (Vorgabe jetzt): die größte id unter allen mit ab ≤ $zeit. */
    public static function fassungJetzt(int $messpunktId, ?int $zeit = null): ?array
    {
        $zeit ??= time();
        $gilt = null;
        foreach (self::fassungen($messpunktId) as $f) {
            if ($f['ab_ts'] <= $zeit && ($gilt === null || $f['id'] > $gilt['id'])) {
                $gilt = $f;
            }
        }
        return $gilt;
    }

    /**
     * Eine neue Fassung – die einzige Stelle, die in tank_kennlinien
     * schreibt, und nur mit INSERT. Was sich nicht rechnen lässt
     * (TankRechnung::pruefen), kommt nicht hinein.
     *
     * @param array<string,mixed> $messpunkt id und objekt_id
     * @param string $ab UTC, ISO 8601
     */
    public static function fassungAnlegen(array $messpunkt, array $rechnung, string $anlass, string $ab, bool $geprueft, ?int $ereignisId = null, string $notiz = ''): int
    {
        $fehler = TankRechnung::pruefen($rechnung);
        if ($fehler !== null) {
            throw new \InvalidArgumentException($fehler);
        }
        if (!isset(self::ANLAESSE[$anlass]) || Zeit::ts($ab) === null) {
            throw new \InvalidArgumentException('Anlass oder Beginn der Fassung ist ungültig.');
        }
        $id = Database::insert('tank_kennlinien', [
            'messpunkt_id' => (int) $messpunkt['id'],
            'objekt_id'    => (int) $messpunkt['objekt_id'],
            'ab'           => $ab,
            'rechnung'     => json_encode($rechnung, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'anlass'       => $anlass,
            'ereignis_id'  => $ereignisId,
            'geprueft'     => $geprueft ? 1 : 0,
            'von'          => mb_substr((string) (Auth::user()['username'] ?? 'system'), 0, 64),
            'notizen'      => $notiz,
            'created_at'   => gmdate('c'),
        ]);
        Audit::log('tank.kennlinie', 'objekt', (string) $messpunkt['objekt_id'], [
            'messpunkt' => (int) $messpunkt['id'], 'anlass' => $anlass, 'rueckwirkend' => $anlass === 'rueckwirkend',
        ]);
        return $id;
    }

    /**
     * Ein Tanksensor ist eingesetzt: die erste Fassung ab dem Einbau – aus
     * seinen Sensordaten, dem Einbaumaß und dem Tank. Sie ist ungeprüft,
     * bis gepeilt ist (die Einbautiefe ändert sich mit jedem Einbau).
     *
     * @return string Hinweise für die Meldung
     */
    public static function einsatzKalibrieren(array $messpunkt, array $item, array $tank, array $einbau): string
    {
        $sensor = self::sensordaten($item['eigenschaften'] ?? null);
        if ($sensor === [] || TankRechnung::sensorPruefen($sensor) !== null) {
            return 'Die Sensordaten sind unvollständig – es gibt nur den Rohwert. Am Tanksensor nachtragen.';
        }
        $erg = TankRechnung::rechnung($sensor, $einbau, (array) $tank['eigenschaften'], self::herkunft($item, $tank,
            'Einbau ' . Zeit::anzeige((string) $messpunkt['gueltig_ab'], 'd.m.Y H:i')));
        $fehler = TankRechnung::pruefen($erg['rechnung']);
        if ($fehler !== null) {
            return 'Die Kennlinie lässt sich nicht bilden (' . $fehler . ') – es gibt nur den Rohwert.';
        }
        self::fassungAnlegen($messpunkt, $erg['rechnung'], 'einsatz', (string) $messpunkt['gueltig_ab'], false);
        return trim('Kennlinie ab dem Einbau angelegt – bitte peilen, sobald der Sensor meldet. ' . implode(' ', $erg['hinweise']));
    }

    /**
     * Im eingesetzten Tanksensor steckt ein anderer Shelly (oder ein anderer
     * Kanal): Der neue Messpunkt rechnet ab $ab mit der Fassung weiter, die
     * zuletzt am alten galt. Ungeprüft – ein anderer Shelly misst die
     * Spannung nicht genau gleich.
     */
    public static function geraetGetauscht(int $einsatzId, string $ab): void
    {
        $neu = Database::first("SELECT * FROM messpunkte WHERE einsatz_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL ORDER BY id DESC LIMIT 1", [$einsatzId]);
        $alt = Database::first("SELECT * FROM messpunkte WHERE einsatz_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NOT NULL ORDER BY gueltig_bis DESC, id DESC LIMIT 1", [$einsatzId]);
        if ($neu === null || $alt === null) {
            return;
        }
        $f = self::fassungJetzt((int) $alt['id'], Zeit::ts($ab));
        if ($f !== null) {
            self::fassungAnlegen($neu, $f['rechnung'], 'shelly', $ab, false);
        }
    }

    /**
     * Die Sensordaten eines eingesetzten Tanksensors haben sich geändert
     * (Bürde, Messbereich, Wandler …): ab jetzt eine Fassung mit dem neuen
     * Sensorteil, Einbau und Tank bleiben. Eine frühere Peilung galt dem
     * alten Sensorteil – die neue Fassung ist ungeprüft.
     *
     * @return string Hinweis für die Meldung
     */
    public static function sensorGeaendert(int $inventarId): string
    {
        $item = InventarService::find($inventarId);
        $einsatz = $item['einsatz'] ?? null;
        if ($item === null || $item['art'] !== 'tanksensor' || $einsatz === null) {
            return '';
        }
        $tank = AnlagenService::find((int) $einsatz['objekt_id']);
        $sensor = self::sensordaten($item['eigenschaften'] ?? null);
        if ($tank === null || AnlagenService::elektrisch((string) $tank['art']) || TankRechnung::sensorPruefen($sensor) !== null) {
            return '';
        }
        $jetzt = gmdate('c');
        $angelegt = 0;
        $hinweise = [];
        foreach (Database::select("SELECT * FROM messpunkte WHERE einsatz_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL ORDER BY id", [(int) $einsatz['id']]) as $m) {
            $f = self::fassungJetzt((int) $m['id']);
            $einbau = (array) ($f['rechnung']['herkunft']['einbau'] ?? []);
            $erg = TankRechnung::rechnung($sensor, $einbau, (array) $tank['eigenschaften'], self::herkunft($item, $tank,
                'Sensordaten geändert ' . Zeit::anzeige($jetzt, 'd.m.Y H:i')));
            $r = $erg['rechnung'];
            if ($f !== null) {
                $alt = $f['rechnung'];
                if ([$alt['feld'], $alt['sensor'], $alt['herkunft']['sensor'] ?? null] == [$r['feld'], $r['sensor'], $r['herkunft']['sensor']]) {
                    continue;    // nur der Hinweistext o. Ä. – an der Rechnung ändert sich nichts
                }
                $r = TankRechnung::mitSensor($alt, $r['sensor']);
                $r['feld'] = $erg['rechnung']['feld'];
                $r['herkunft'] = array_merge((array) ($alt['herkunft'] ?? []), [
                    'sensor' => $erg['rechnung']['herkunft']['sensor'], 'text' => $erg['rechnung']['herkunft']['text'],
                ]);
            }
            if (TankRechnung::pruefen($r) !== null) {
                continue;
            }
            self::fassungAnlegen($m, $r, 'sensor', $jetzt, false);
            $angelegt++;
            $hinweise = array_merge($hinweise, TankRechnung::hinweise($r));
        }
        return $angelegt > 0
            ? trim('Kennlinie ab jetzt mit den neuen Sensordaten – eine frühere Peilung gilt dafür nicht, bitte neu peilen. ' . implode(' ', array_unique($hinweise)))
            : '';
    }

    /**
     * Die Eigenschaften eines Tanks haben sich geändert. Geht etwas davon in
     * die Rechnung ein (Nennvolumen, Bauform, Inhalt je mm, Maße, Füllgrenze,
     * Sumpf – oder bei einer hydrostatischen Sonde die Dichte), rechnet der
     * laufende Tanksensor ab jetzt mit einer neuen Fassung. Ein neuer Name
     * oder eine neue Zulassung ändern nichts.
     *
     * @return string Hinweis für die Meldung
     */
    public static function tankGeaendert(int $tankId, array $altEig, array $neuEig): string
    {
        $tank = AnlagenService::find($tankId);
        if ($tank === null || AnlagenService::elektrisch((string) $tank['art']) || (int) $tank['ist_vorlage'] === 1) {
            return '';
        }
        $tankNeu = TankRechnung::tankTeil($neuEig);
        $dichteGeaendert = abs(TankRechnung::dichte($altEig) - TankRechnung::dichte($neuEig)) > 1e-9;
        if (TankRechnung::tankTeil($altEig) == $tankNeu && !$dichteGeaendert) {
            return '';
        }
        $jetzt = gmdate('c');
        $angelegt = 0;
        foreach (Database::select("SELECT m.*, e.inventar_id FROM messpunkte m LEFT JOIN einsaetze e ON e.id = m.einsatz_id
                                   WHERE m.objekt_id = ? AND m.rolle = 'fuellstand' AND m.gueltig_bis IS NULL ORDER BY m.id", [$tankId]) as $m) {
            $f = self::fassungJetzt((int) $m['id']);
            if ($f === null) {
                continue;
            }
            $alt = $f['rechnung'];
            $r = TankRechnung::mitTank($alt, $tankNeu);
            // Was am Liter rechnet (Form, Inhalt je mm, Maße), ist ungeprüft, bis wieder gepeilt ist.
            $geprueft = $f['geprueft'] && self::literGleich($alt['tank'], $tankNeu);
            $item = $m['inventar_id'] !== null ? InventarService::find((int) $m['inventar_id']) : null;
            $sensor = self::sensordaten($item['eigenschaften'] ?? null);
            if ($dichteGeaendert && ($sensor['prinzip'] ?? '') === 'hydrostatisch' && TankRechnung::sensorPruefen($sensor) === null) {
                // Dieselbe Säule drückt bei anderem Kraftstoff anders: Sensorteil neu
                // aus Sensordaten und Einbau – eine frühere Peilung gilt nicht mehr.
                [$sensorTeil] = TankRechnung::sensorTeil($sensor, (array) ($alt['herkunft']['einbau'] ?? []), TankRechnung::dichte($neuEig));
                $r = TankRechnung::mitSensor($r, $sensorTeil);
                $geprueft = false;
            }
            if ($r['sensor'] == $alt['sensor'] && $r['tank'] == $alt['tank']) {
                continue;
            }
            $r['herkunft'] = array_merge((array) ($alt['herkunft'] ?? []), [
                'tank' => [
                    'name'        => mb_substr((string) $tank['name'], 0, 190),
                    'modell'      => mb_substr((string) $tank['modell'], 0, 190),
                    'kraftstoff'  => mb_substr((string) ($neuEig['kraftstoff'] ?? ''), 0, 32),
                    'dichte_kg_l' => round(TankRechnung::dichte($neuEig), 6),
                ],
                'text' => 'Tankdaten geändert ' . Zeit::anzeige($jetzt, 'd.m.Y H:i'),
            ]);
            if (TankRechnung::pruefen($r) !== null) {
                continue;
            }
            self::fassungAnlegen($m, $r, 'tank', $jetzt, $geprueft);
            $angelegt++;
        }
        return $angelegt > 0 ? 'Der Tanksensor rechnet ab jetzt mit den neuen Tankdaten.' : '';
    }

    /** Ein Messpunkt ist gelöscht: seine Fassungen gehen mit, Peilungen und Betankungen bleiben im Protokoll des Tanks. */
    public static function messpunktEntfernt(int $messpunktId): void
    {
        Database::execute('DELETE FROM tank_kennlinien WHERE messpunkt_id = ?', [$messpunktId]);
        Database::execute('UPDATE tank_ereignisse SET messpunkt_id = NULL WHERE messpunkt_id = ?', [$messpunktId]);
    }

    /** Ein Tank ist gelöscht: Fassungen und Ereignisse gehen mit. */
    public static function objektEntfernt(int $tankId): void
    {
        Database::execute('DELETE FROM tank_kennlinien WHERE objekt_id = ?', [$tankId]);
        Database::execute('DELETE FROM tank_ereignisse WHERE objekt_id = ?', [$tankId]);
    }

    /** Sensordaten eines Inventarstücks als Liste von Schlüssel und Wert (aus JSON oder schon dekodiert). @return array<string,mixed> */
    public static function sensordaten(mixed $eigenschaften): array
    {
        if (is_string($eigenschaften)) {
            $eigenschaften = json_decode($eigenschaften, true);
        }
        return is_array($eigenschaften) ? array_filter($eigenschaften, static fn ($w): bool => $w === null || is_scalar($w)) : [];
    }

    private static function sensorAufbereiten(array $m): array
    {
        $m['sensordaten'] = self::sensordaten($m['sensordaten'] ?? null);
        $m['sensor_kurz'] = $m['sensordaten'] !== [] ? TankRechnung::sensorKurz($m['sensordaten']) : 'ohne Sensordaten';
        return $m;
    }

    /** Herkunft einer Fassung: Kennung des Sensors, Name und Modell des Tanks, ein Text. */
    private static function herkunft(array $item, array $tank, string $text): array
    {
        return ['kennung' => (string) ($item['kennung'] ?? ''), 'name' => (string) ($tank['name'] ?? ''), 'modell' => (string) ($tank['modell'] ?? ''), 'text' => $text];
    }

    /** Rechnen zwei Tankteile bei gleicher Füllhöhe dieselben Liter? */
    private static function literGleich(array $a, array $b): bool
    {
        foreach (['form', 'liter_je_mm', 'durchmesser_mm', 'laenge_mm'] as $k) {
            if (($a[$k] ?? null) != ($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
