<?php
declare(strict_types=1);

namespace App\Tanks;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\LiveService;
use App\Services\MesspunktService;
use App\Services\PlanGeoService;
use App\Support\Audit;
use App\Support\Config;
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
 *
 * Gelesen wird beim Anzeigen: live() holt den letzten Rohwert seit dem
 * Einbau (Messdaten::tankLetzte – höchstens 7 Tage, nur im Datenfenster),
 * reihe() den Verlauf je Fassung. In der InfluxDB steht nie ein Liter.
 */
final class TankService
{
    /** Wie weit Live zurückschaut: Gen2 meldet einen Analogwert nur bei Änderung. */
    public const LIVE_FENSTER_S = 7 * 86400;

    /** Größen eines Verlaufs: Beschriftung und Einheit (Rohwert: Einheit des Felds). */
    public const GROESSEN = [
        'liter'    => ['Inhalt', 'l'],
        'prozent'  => ['Füllstand', '%'],
        'hoehe_mm' => ['Füllhöhe', 'mm'],
        'roh'      => ['Rohwert', ''],
    ];

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
        // Der laufende Tanksensor und seine Kennlinie – sichtbar für jeden, der
        // den Tank sieht; ein- und ausbauen darf, wer am Tank bearbeiten darf.
        $sensor = !$vorlage ? self::sensor((int) $tank['id']) : null;
        $fassung = $sensor !== null ? self::fassungJetzt((int) $sensor['id']) : null;
        $fenster = Zugriff::datenFenster($tank);
        if ($fassung !== null && !self::fassungImFenster($fassung, $sensor, $fenster)) {
            $fassung = null;    // eine Kennlinie von außerhalb des Datenfensters sieht niemand
        }
        $frei = !$vorlage && $darfAendern && Auth::can('datenquellen.view')
            ? array_values(array_filter(InventarService::fuerBetrachter(InventarService::liste(['art' => 'tanksensor'])),
                static fn (array $i): bool => $i['zustand'] !== 'defekt' && (int) ($i['einsatz']['objekt_id'] ?? 0) !== (int) $tank['id']))
            : [];
        $frueher = $vorlage ? [] : array_values(array_filter(self::sensoren((int) $tank['id']),
            static fn (array $m): bool => $m['gueltig_bis'] !== null && self::zeitraumImFenster($m, $fenster)));
        return [
            'objekt'      => $tank,
            'vorlage'     => $vorlage,
            'kurzform'    => self::kurzform($tank),
            'gruppen'     => $gruppen,
            'lage'        => $vorlage ? null : PlanGeoService::fuerObjekt($tank),
            'darfAendern' => $darfAendern,
            'sensor'      => $sensor,
            'fassung'     => $fassung,
            'kennlinie'   => self::kennlinieText($fassung),
            'hinweise'    => $fassung !== null ? TankRechnung::hinweise($fassung['rechnung']) : [],
            'sensorFrei'  => $frei,
            'live'        => $vorlage ? [] : (LiveService::fuerObjekte([$tank])[(int) $tank['id']] ?? []),
            'fenster'     => $fenster,
            'fassungen'   => $vorlage ? [] : self::fassungenSichtbar($tank),
            'frueher'     => $frueher,
        ];
    }

    /** „Betankung 16.10.2026 08:10 · geprüft“, „Einbau … · seit Einbau nicht gepeilt“ – die jetzige Kennlinie im Klartext. */
    public static function kennlinieText(?array $fassung): string
    {
        if ($fassung === null) {
            return 'keine – nur Rohwert';
        }
        $r = $fassung['rechnung'];
        $teile = [(self::ANLAESSE[$fassung['anlass']] ?? $fassung['anlass']) . ' ' . Zeit::anzeige($fassung['ab'], 'd.m.Y H:i')];
        if (($r['tank']['liter_je_mm'] ?? null) !== null && ($r['sensor']['art'] ?? '') === 'linear') {
            array_unshift($teile, self::zahl((float) $r['tank']['liter_je_mm']) . ' l/mm');
        }
        $teile[] = $fassung['geprueft'] ? 'geprüft' : ($fassung['anlass'] === 'einsatz' ? 'seit Einbau nicht gepeilt' : 'ungeprüft – bitte peilen');
        return implode(' · ', $teile);
    }

    // ------------------------------------------------------------ Live

    /**
     * Livewerte der Tanks – für Übersicht, Anlagenliste, Karte und
     * Tankseite in der Form von LiveService (stufe, hinweis, p, i, u …,
     * damit Zähler und Kacheln weiter zählen), dazu „tank“ mit Füllstand,
     * Rohwert und Alter.
     *
     * Gelesen wird der letzte Rohwert seit dem Einbau, höchstens 7 Tage
     * zurück und nur im Datenfenster – nie ein Wert von vor dem Einbau.
     * Umgerechnet wird mit der Fassung, die jetzt gilt: Ein Shelly meldet nur
     * bei Änderung, sein letzter Wert gilt also bis jetzt. Wer den Tank jetzt
     * nicht live sehen darf, bekommt „gesperrt“ und keine Zahl.
     *
     * @param list<array<string,mixed>> $tanks sichtbare Tanks
     * @param array<int,array<string,mixed>> $generatorLive Livewerte der elektrischen Anlagen (LiveService) –
     *        für Verbrauch und Restlaufzeit am Kraftstoffschlauch; ohne Schlauch ohne Wirkung
     * @return array<int,array<string,mixed>> tank_id => Zusammenfassung
     */
    public static function live(array $tanks, array $generatorLive = []): array
    {
        if ($tanks === []) {
            return [];
        }
        $jetzt = time();
        $ids = array_map(static fn (array $t): int => (int) $t['id'], $tanks);
        $sensoren = [];
        foreach (Database::select(
            "SELECT m.*, i.kennung, i.eigenschaften AS sensordaten FROM messpunkte m
             LEFT JOIN einsaetze e ON e.id = m.einsatz_id LEFT JOIN inventar i ON i.id = e.inventar_id
             WHERE m.rolle = 'fuellstand' AND m.gueltig_bis IS NULL AND m.objekt_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY m.id',
            $ids
        ) as $m) {
            $sensoren[(int) $m['objekt_id']] ??= $m;    // ein neuer ersetzt den alten – stehen doch zwei da, zählt der erste
        }
        $grenzen = self::grenzen();
        $out = [];
        $offen = [];
        $abfragen = [];
        foreach ($tanks as $tank) {
            $id = (int) $tank['id'];
            $fenster = Zugriff::datenFenster($tank);
            if (!Zugriff::liveErlaubt($fenster, $jetzt)) {
                $out[$id] = self::liveAntwort(['stufe' => 'gesperrt', 'hinweis' => 'Livewerte außerhalb des freigegebenen Zeitraums'], null);
                continue;
            }
            $peilung = self::letztePeilung($tank, $fenster);
            $m = $sensoren[$id] ?? null;
            $eig = (array) $tank['eigenschaften'];
            if ($m === null) {
                $stufe = TankRechnung::stufe(['sensor' => false, 'peilung' => $peilung, 'jetzt' => $jetzt], $grenzen, $eig);
                $out[$id] = self::liveAntwort($stufe, ['sensor' => false, 'peilung' => $peilung,
                    'nenn_l' => TankRechnung::zahlLesen($eig['inhalt_l'] ?? null)]);
                continue;
            }
            $fassung = self::fassungJetzt((int) $m['id'], $jetzt);
            $feld = $fassung['rechnung']['feld'] ?? (self::sensordaten($m['sensordaten'] ?? null)['feld'] ?? null);
            // Ab wann gelesen wird: frühestens der Einbau, höchstens 7 Tage, im
            // laufenden Teil des Datenfensters. Das Lebenszeichen gehört zum
            // Gerät, bleibt aber ebenso im Datenfenster.
            $ab = self::laufendAb(MesspunktService::fenster($m, $tank), $jetzt);
            $abOnline = self::laufendAb($fenster, $jetzt);
            if ($ab !== null && $abOnline !== null && is_string($feld) && isset(TankRechnung::ROHFELDER[$feld])) {
                $abfragen[] = ['geraet' => (string) $m['geraet'], 'kanal' => (string) $m['kanal'], 'feld' => $feld, 'ab' => $ab, 'ab_online' => $abOnline];
            }
            $offen[$id] = [$tank, $m, $fassung, is_string($feld) ? $feld : null, $peilung];
        }
        $werte = Messdaten::tankLetzte($abfragen);
        foreach ($offen as $id => [$tank, $m, $fassung, $feld, $peilung]) {
            $out[$id] = self::liveMitSensor($tank, $m, $fassung, $feld, $peilung, $werte[$m['geraet'] . '|' . $m['kanal']] ?? null, $grenzen, $jetzt);
        }
        return $out;
    }

    /**
     * Der letzte gültige Rohwert eines Tanksensors – für Peilung und
     * Betankung, die ihn nie vom Browser nehmen: seit dem Einbau, ohne
     * Sensorfehler, höchstens 7 Tage alt, Gerät online oder unbekannt.
     *
     * @return ?array{roh:float,zeit:int,feld:string,online:?bool} zeit in ms
     */
    public static function rohJetzt(array $tank, array $messpunkt): ?array
    {
        $jetzt = time();
        $fassung = self::fassungJetzt((int) $messpunkt['id'], $jetzt);
        $item = $messpunkt['einsatz_id'] !== null
            ? Database::first('SELECT i.eigenschaften FROM einsaetze e JOIN inventar i ON i.id = e.inventar_id WHERE e.id = ?', [(int) $messpunkt['einsatz_id']])
            : null;
        $feld = $fassung['rechnung']['feld'] ?? (self::sensordaten($item['eigenschaften'] ?? null)['feld'] ?? null);
        $ab = self::laufendAb(MesspunktService::fenster($messpunkt, $tank), $jetzt);
        $abOnline = self::laufendAb(Zugriff::datenFenster($tank), $jetzt);
        if (!is_string($feld) || !isset(TankRechnung::ROHFELDER[$feld]) || $ab === null || $abOnline === null) {
            return null;
        }
        $w = Messdaten::tankLetzte([['geraet' => (string) $messpunkt['geraet'], 'kanal' => (string) $messpunkt['kanal'], 'feld' => $feld,
            'ab' => $ab, 'ab_online' => $abOnline]])[$messpunkt['geraet'] . '|' . $messpunkt['kanal']] ?? null;
        if ($w === null || $w['roh'] === null || $w['zeit'] === null || self::fehlerAktiv($w) || $w['online'] === false) {
            return null;
        }
        return ['roh' => (float) $w['roh'], 'zeit' => (int) $w['zeit'], 'feld' => $feld, 'online' => $w['online']];
    }

    /** Grenzen der Stufen aus den Einstellungen (grenzen.tank_*); Vorgaben in TankRechnung::GRENZEN. @return array<string,mixed> */
    public static function grenzen(): array
    {
        $g = [];
        foreach (array_keys(TankRechnung::GRENZEN) as $k) {
            $g[$k] = Config::get('grenzen.' . $k);
        }
        return $g;
    }

    /** Ein Tank mit Sensor: Rohwert → Füllhöhe → Liter, Stufe und Hinweis. */
    private static function liveMitSensor(array $tank, array $m, ?array $fassung, ?string $feld, ?array $peilung, ?array $w, array $grenzen, int $jetzt): array
    {
        $r = $fassung['rechnung'] ?? null;
        $roh = $w['roh'] ?? null;
        $zeit = $w['zeit'] ?? null;
        $fehler = $w !== null && self::fehlerAktiv($w);
        $erg = $r !== null ? TankRechnung::umrechnen($r, $roh, $fehler)
            : ['hoehe_mm' => null, 'liter' => null, 'prozent' => null, 'nutzbar_l' => null, 'fehler' => $fehler ? 'Sensorfehler laut Gerät' : null, 'ueber_grenze' => false];
        $alter = $zeit !== null ? max(0, $jetzt - intdiv((int) $zeit, 1000)) : null;
        $rund = static fn (?float $x, int $n = 1): ?float => $x !== null ? round($x, $n) : null;
        $t = [
            'sensor'        => true,
            'kennung'       => (string) ($m['kennung'] ?? '') !== '' ? (string) $m['kennung'] : (string) $m['bezeichnung'],
            'messpunkt'     => (int) $m['id'],
            'roh'           => $roh,
            'roh_feld'      => $feld,
            'roh_einheit'   => $feld !== null ? (TankRechnung::ROHFELDER[$feld][1] ?? '') : '',
            'zeit'          => $zeit,
            'stand'         => $alter !== null ? TankRechnung::alterText($alter) : null,
            'online'        => $w['online'] ?? null,
            'hoehe_mm'      => $rund($erg['hoehe_mm']),
            'liter'         => $rund($erg['liter']),
            'prozent'       => $rund($erg['prozent']),
            'nutzbar_l'     => $rund($erg['nutzbar_l']),
            'nenn_l'        => $r['tank']['nenn_l'] ?? TankRechnung::zahlLesen(((array) $tank['eigenschaften'])['inhalt_l'] ?? null),
            'kalibriert'    => $r !== null && TankRechnung::kalibriert($r),
            'geprueft'      => (bool) ($fassung['geprueft'] ?? false),
            'kennlinie'     => self::kennlinieText($fassung),
            'fehler'        => $erg['fehler'],
            'ueber_grenze'  => $erg['ueber_grenze'],
            'schritt_l'     => $r !== null ? $rund(TankRechnung::schrittLiter($r), 2) : null,
            // Verbrauch und Restlaufzeit: nur über einen Kraftstoffschlauch zu einem sichtbaren Generator.
            'verbrauch_l_h' => null, 'verbrauch_quelle' => null, 'restlaufzeit_h' => null, 'leer_um' => null, 'nachbestellen_bis' => null,
            'generatoren'   => [],
            'peilung'       => $peilung,
        ];
        $stufe = TankRechnung::stufe($t + ['alter_s' => $alter, 'grenze_prozent' => $r['tank']['grenze_prozent'] ?? null, 'jetzt' => $jetzt],
            $grenzen, (array) $tank['eigenschaften']);
        if ($feld === null) {
            $stufe = ['stufe' => 'stumm', 'hinweis' => 'Sensordaten unvollständig – am Tanksensor nachtragen, sonst lässt sich nichts lesen'];
        } elseif ($r === null && $stufe['stufe'] === 'ok') {
            $stufe['hinweis'] = 'Rohwert ' . ($roh !== null ? TankRechnung::zahl(round($roh, 3)) . ($t['roh_einheit'] !== '' ? ' ' . $t['roh_einheit'] : '') : '–')
                . ' – keine Kennlinie: Sensordaten am Tanksensor nachtragen';
        }
        $antwort = self::liveAntwort($stufe, $t);
        $antwort['alter_s'] = $alter;
        if ($erg['fehler'] === null || $erg['liter'] !== null) {
            $antwort['zustand'] = array_filter(['tank_prozent' => $t['prozent'], 'tank_liter' => $t['liter']], static fn ($x) => $x !== null);
        }
        return $antwort;
    }

    /** Eine Antwort in der Form von LiveService (ohne Leistung) mit dem Tankteil. */
    private static function liveAntwort(array $stufe, ?array $tank): array
    {
        return [
            'stufe' => $stufe['stufe'], 'hinweis' => $stufe['hinweis'],
            'p' => null, 'i' => [], 'u' => [], 'f' => null, 'auslastung' => null, 'phasen' => [], 'messpunkte' => [],
            'alter_s' => null, 'zustand' => [], 'tank' => $tank,
        ];
    }

    /** Ein Sensorfehler gilt, wenn er die jüngste Meldung ist (sensorfehler kommt mit jeder). */
    private static function fehlerAktiv(array $w): bool
    {
        return !empty($w['fehler']) && ($w['zeit'] === null || (int) ($w['fehler_zeit'] ?? 0) >= (int) $w['zeit']);
    }

    /**
     * Beginn des laufenden Teils eines Fensters in den letzten 7 Tagen – oder
     * null, wenn jetzt nichts freigegeben ist.
     *
     * @param list<array{0:?int,1:?int}>|null $fenster
     */
    private static function laufendAb(?array $fenster, int $jetzt): ?int
    {
        foreach (Zugriff::begrenzen($fenster, $jetzt - self::LIVE_FENSTER_S, $jetzt) as [$a, $b]) {
            if ($b >= $jetzt) {
                return (int) $a;
            }
        }
        return null;
    }

    /** Die letzte Peilung im Datenfenster: {zeit, liter, prozent, hoehe_mm} oder null. */
    private static function letztePeilung(array $tank, ?array $fenster): ?array
    {
        foreach (Database::select("SELECT zeit, liter, prozent, hoehe_mm FROM tank_ereignisse WHERE objekt_id = ? AND art = 'peilung'
                                   ORDER BY zeit DESC, id DESC LIMIT 50", [(int) $tank['id']]) as $e) {
            $ts = Zeit::ts((string) $e['zeit']);
            if ($ts !== null && self::zeitImFenster($ts, $fenster)) {
                return [
                    'zeit'     => (string) $e['zeit'],
                    'liter'    => $e['liter'] !== null ? (float) $e['liter'] : null,
                    'prozent'  => $e['prozent'] !== null ? (float) $e['prozent'] : null,
                    'hoehe_mm' => $e['hoehe_mm'] !== null ? (float) $e['hoehe_mm'] : null,
                ];
            }
        }
        return null;
    }

    // ------------------------------------------------------------ Verlauf

    /**
     * Verlauf eines Tanks im Format von /statistik/daten (zeiten, serien,
     * einheit, name, schritt, teile), dazu „marken“ – Peilungen und
     * Betankungen im Zeitraum, nur im Datenfenster.
     *
     * Es zählen alle Tanksensoren, die je im Tank gemessen haben, oder nur
     * $messpunktId. Je Fassung ein Abschnitt (TankRechnung::abschnitte): Jeder
     * Punkt rechnet mit der Fassung, die zu seiner Zeit galt. Erst
     * aggregiert (Median), dann umgerechnet – beide Stufen sind monoton.
     * Außerhalb einer Fassung gibt es nur den Rohwert.
     *
     * @param string $groesse liter | prozent | hoehe_mm | roh
     * @return array{zeiten:list<int>,serien:array<string,list<?float>>,einheit:string,name:string,groesse:string,schritt:int,teile:list<array{0:int,1:int}>,marken:list<array{zeit:int,art:string,liter:?float}>}
     */
    public static function reihe(array $tank, ?int $messpunktId, int $von, int $bis, string $groesse, ?string &$fehler = null): array
    {
        $groesse = isset(self::GROESSEN[$groesse]) ? $groesse : 'liter';
        $roh = self::rohwerte($tank, $messpunktId, $von, $bis, $fehler);
        [$name, $einheit] = self::GROESSEN[$groesse];
        if ($groesse === 'roh') {
            $felder = $roh['felder'];
            $einheit = count($felder) === 1 ? (TankRechnung::ROHFELDER[$felder[0]][1] ?? '') : '';
            $name = count($felder) === 1 ? 'Rohwert (' . (TankRechnung::ROHFELDER[$felder[0]][0] ?? $felder[0]) . ')' : 'Rohwert';
        }
        $werte = [];
        foreach ($roh['punkte'] as $p) {
            if ($p === null) {
                $werte[] = null;
            } elseif ($groesse === 'roh') {
                $werte[] = round($p['roh'], 4);
            } else {
                $erg = $p['rechnung'] !== null ? TankRechnung::umrechnen($p['rechnung'], $p['roh']) : null;
                $w = $erg[$groesse] ?? null;
                $werte[] = $w !== null ? round((float) $w, $groesse === 'prozent' ? 2 : 1) : null;
            }
        }
        $marken = [];
        foreach (self::ereignisse($tank, 200) as $e) {
            $ts = Zeit::ts($e['zeit']);
            if ($ts !== null && $ts >= $von && $ts <= $bis) {
                $marken[] = ['zeit' => $ts * 1000, 'art' => $e['art'], 'liter' => $e['liter']];
            }
        }
        return [
            'zeiten'  => $roh['zeiten'],
            'serien'  => $roh['zeiten'] === [] ? [] : [$groesse => $werte],
            'einheit' => $einheit,
            'name'    => $name,
            'groesse' => $groesse,
            'schritt' => $roh['schritt'],
            'teile'   => $roh['teile'],
            'marken'  => array_reverse($marken),
        ];
    }

    /**
     * Die Rohwerte eines Tanks im Zeitraum, je Punkt mit der Fassung, die zu
     * seiner Zeit galt – Grundlage für reihe() und die CSV. Lücken (Zeiten
     * ohne Freigabe zwischen zwei Teilen) sind null.
     *
     * @return array{zeiten:list<int>,punkte:list<?array{roh:float,rechnung:?array,feld:string,messpunkt:int,kennung:string}>,schritt:int,teile:list<array{0:int,1:int}>,felder:list<string>}
     */
    public static function rohwerte(array $tank, ?int $messpunktId, int $von, int $bis, ?string &$fehler = null): array
    {
        $bis = min($bis, time());
        $leer = ['zeiten' => [], 'punkte' => [], 'schritt' => Messdaten::schritt($von, max($von + 1, $bis)), 'teile' => [], 'felder' => []];
        if ($bis <= $von) {
            return $leer;
        }
        $tankFenster = Zugriff::datenFenster($tank);
        $schritt = $leer['schritt'];
        $segmente = [];
        foreach (self::sensoren((int) $tank['id']) as $m) {
            if ($messpunktId !== null && (int) $m['id'] !== $messpunktId) {
                continue;
            }
            $ma = max($von, Zeit::ts($m['gueltig_ab'] ?? null) ?? $von);
            $mb = min($bis, Zeit::ts($m['gueltig_bis'] ?? null) ?? $bis);
            if ($ma >= $mb) {
                continue;
            }
            $f = array_map(static fn (array $x): array => ['id' => $x['id'], 'ab' => $x['ab_ts'], 'rechnung' => $x['rechnung']], self::fassungen((int) $m['id']));
            $vorgabe = (string) ($m['sensordaten']['feld'] ?? ($f !== [] ? $f[count($f) - 1]['rechnung']['feld'] : 'u'));
            $vorgabe = isset(TankRechnung::ROHFELDER[$vorgabe]) ? $vorgabe : 'u';
            $bisher = $ma;
            foreach (TankRechnung::abschnitte($f, $ma, $mb) as [$a, $b, $r]) {
                if ($a > $bisher) {
                    $segmente[] = [$bisher, $a, null, $vorgabe, $m];   // vor der ersten Fassung: nur der Rohwert
                }
                $segmente[] = [$a, $b, $r, (string) $r['feld'], $m];
                $bisher = $b;
            }
            if ($bisher < $mb) {
                $segmente[] = [$bisher, $mb, null, $vorgabe, $m];
            }
        }
        usort($segmente, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $punkte = [];
        $felder = [];
        $anfaenge = [];
        foreach ($segmente as [$a, $b, $r, $feld, $m]) {
            $anfaenge[$a] = true;
            $reihe = Messdaten::reihe('zustand', (string) $m['geraet'], (string) $m['kanal'], $feld, [], $a, $b,
                MesspunktService::fenster($m, $tank), $schritt, 'median', $fehler);
            $werte = $reihe['serien'][$feld] ?? [];
            foreach ($reihe['zeiten'] as $j => $ms) {
                $w = $werte[$j] ?? null;
                if ($w === null) {
                    $punkte[$ms] ??= null;
                    continue;
                }
                $felder[$feld] = true;
                $punkte[$ms] = ['roh' => (float) $w, 'rechnung' => $r, 'feld' => $feld, 'messpunkt' => (int) $m['id'],
                    'kennung' => (string) ($m['kennung'] ?? '') !== '' ? (string) $m['kennung'] : (string) $m['bezeichnung']];
            }
        }
        // Messdaten::reihe setzt ans Ende jedes Teils eine Lücke. Wo nur die
        // Fassung (oder der Shelly) wechselt und das Datenfenster weitergeht,
        // ist keine – die Kurve läuft durch.
        foreach (array_keys($anfaenge) as $x) {
            $ms = $x * 1000 + 1;
            if (array_key_exists($ms, $punkte) && $punkte[$ms] === null && Zugriff::begrenzen($tankFenster, $x - 1, $x + 1) === [[$x - 1, $x + 1]]) {
                unset($punkte[$ms]);
            }
        }
        ksort($punkte);
        return [
            'zeiten'  => array_keys($punkte),
            'punkte'  => array_values($punkte),
            'schritt' => $schritt,
            'teile'   => Zugriff::begrenzen($tankFenster, $von, $bis),
            'felder'  => array_keys($felder),
        ];
    }

    /**
     * Die Fassungen aller Tanksensoren dieses Tanks, die ins Datenfenster
     * reichen – jüngste zuerst, mit dem Sensor, an dem sie hängen.
     *
     * @return list<array<string,mixed>>
     */
    public static function fassungenSichtbar(array $tank): array
    {
        $fenster = Zugriff::datenFenster($tank);
        $out = [];
        foreach (self::sensoren((int) $tank['id']) as $m) {
            $alle = self::fassungen((int) $m['id']);
            foreach ($alle as $f) {
                if (self::fassungImFenster($f, $m, $fenster, $alle)) {
                    $out[] = $f + ['kennung' => (string) ($m['kennung'] ?? '') !== '' ? (string) $m['kennung'] : (string) $m['bezeichnung']];
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['ab_ts'], $b['id']] <=> [$a['ab_ts'], $a['id']]);
        return $out;
    }

    /**
     * Peilungen und Betankungen des Tanks, jüngste zuerst – nur im
     * Datenfenster.
     *
     * @return list<array{id:int,art:string,zeit:string,liter:?float,prozent:?float,hoehe_mm:?float,roh:?float,lieferschein:string,von:string,notizen:string,messpunkt_id:?int,text:string}>
     */
    public static function ereignisse(array $tank, int $grenze = 50): array
    {
        $fenster = Zugriff::datenFenster($tank);
        $out = [];
        foreach (Database::select('SELECT * FROM tank_ereignisse WHERE objekt_id = ? ORDER BY zeit DESC, id DESC LIMIT 2000', [(int) $tank['id']]) as $e) {
            $ts = Zeit::ts((string) $e['zeit']);
            if ($ts === null || !self::zeitImFenster($ts, $fenster)) {
                continue;
            }
            $zahl = static fn (mixed $x): ?float => $x !== null ? (float) $x : null;
            $eintrag = [
                'id' => (int) $e['id'], 'art' => (string) $e['art'], 'zeit' => (string) $e['zeit'],
                'liter' => $zahl($e['liter']), 'prozent' => $zahl($e['prozent']), 'hoehe_mm' => $zahl($e['hoehe_mm']), 'roh' => $zahl($e['roh']),
                'lieferschein' => (string) $e['lieferschein'], 'von' => (string) $e['von'], 'notizen' => (string) ($e['notizen'] ?? ''),
                'messpunkt_id' => $e['messpunkt_id'] !== null ? (int) $e['messpunkt_id'] : null,
            ];
            $out[] = $eintrag + ['text' => self::ereignisText($eintrag)];
            if (count($out) >= $grenze) {
                break;
            }
        }
        return $out;
    }

    /** „Peilung 60 %“, „Peilstab 650 mm“, „Betankung 2.000 l (DEMO-0815)“. */
    public static function ereignisText(array $e): string
    {
        if ($e['art'] === 'betankung') {
            return 'Betankung ' . ($e['liter'] !== null ? self::liter((float) $e['liter']) : '') . ($e['lieferschein'] !== '' ? ' (' . $e['lieferschein'] . ')' : '');
        }
        $teile = [];
        if ($e['prozent'] !== null) {
            $teile[] = self::zahl(round((float) $e['prozent'], 1)) . ' %';
        }
        if ($e['liter'] !== null) {
            $teile[] = self::liter((float) $e['liter']);
        }
        if ($e['hoehe_mm'] !== null) {
            return 'Peilstab ' . self::zahl(round((float) $e['hoehe_mm'])) . ' mm';
        }
        return trim('Peilung ' . implode(' · ', $teile));
    }

    /** Was das Kartenpanel eines Tanks zusätzlich braucht: Sensor, letzte Ereignisse, was der Betrachter darf. */
    public static function panelDaten(array $tank): array
    {
        $darf = Zugriff::darfBearbeiten($tank);
        $sensor = self::sensor((int) $tank['id']);
        return [
            'sensor'     => $sensor === null ? null : [
                'kennung'   => (string) ($sensor['kennung'] ?? $sensor['bezeichnung']),
                'kurz'      => (string) $sensor['sensor_kurz'],
                'einsatz_id' => $darf && $sensor['einsatz_id'] !== null ? (int) $sensor['einsatz_id'] : null,
            ],
            'ereignisse' => array_map(static fn (array $e): array => ['art' => $e['art'], 'zeit' => Zeit::anzeige($e['zeit'], 'd.m. H:i'), 'text' => $e['text']],
                self::ereignisse($tank, 3)),
            'darf'       => ['peilen' => $darf, 'betanken' => $darf, 'einsetzen' => $darf && Auth::can('datenquellen.view')],
        ];
    }

    /**
     * Gilt eine Fassung irgendwann im Datenfenster – war sie dort die
     * geltende? Eine, die nie galt (gleich überholt), sieht nur, wer alles
     * sehen darf.
     *
     * @param list<array<string,mixed>>|null $alle die Fassungen des Messpunkts (sonst gelesen)
     */
    private static function fassungImFenster(array $fassung, array $messpunkt, ?array $fenster, ?array $alle = null): bool
    {
        if ($fenster === null) {
            return true;
        }
        $von = Zeit::ts($messpunkt['gueltig_ab'] ?? null) ?? $fassung['ab_ts'];
        $bis = Zeit::ts($messpunkt['gueltig_bis'] ?? null) ?? time() + 1;
        $alle = array_map(static fn (array $x): array => ['id' => $x['id'], 'ab' => $x['ab_ts'], 'rechnung' => $x['rechnung']],
            $alle ?? self::fassungen((int) $messpunkt['id']));
        foreach (TankRechnung::abschnitte($alle, min($von, $bis), $bis) as [$a, $b, , $id]) {
            if ($id === (int) $fassung['id'] && Zugriff::begrenzen($fenster, $a, $b) !== []) {
                return true;
            }
        }
        return false;
    }

    /** Liegt die Einbauzeit eines (früheren) Sensors im Datenfenster? */
    private static function zeitraumImFenster(array $messpunkt, ?array $fenster): bool
    {
        $von = Zeit::ts($messpunkt['gueltig_ab'] ?? null) ?? 0;
        $bis = Zeit::ts($messpunkt['gueltig_bis'] ?? null) ?? time() + 1;
        return $fenster === null || ($von < $bis && Zugriff::begrenzen($fenster, $von, $bis) !== []);
    }

    private static function zeitImFenster(int $ts, ?array $fenster): bool
    {
        return Zugriff::begrenzen($fenster, $ts, $ts + 1) !== [];
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
