<?php
declare(strict_types=1);

namespace App\Tanks;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LiveService;
use App\Services\MesspunktService;
use App\Services\PlanGeoService;
use App\Support\Audit;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
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
 * reihe() den Verlauf je Fassung, tage() den Verbrauch je Kalendertag mit
 * den erkannten Betankungen. In der InfluxDB steht nie ein Liter.
 *
 * Versorgung: Kraftstoffschläuche (Leitungsart „kraftstoff“) verbinden
 * Tanks und Generatoren. Was zusammenhängt, hat einen Vorrat (Σ nutzbar) und
 * einen Verbrauch – aus Kennlinie × Last der Generatoren, wenn jeder davon
 * sichtbar ist und einen Livewert hat, sonst aus dem Gefälle des Pegels. Daraus
 * Restlaufzeit, „leer um“ und „nachbestellen bis“. Ein Generator, den der
 * Betrachter nicht sieht, verrät weder Namen noch Leistung.
 */
final class TankService
{
    /** Wie weit Live zurückschaut: Gen2 meldet einen Analogwert nur bei Änderung. */
    public const LIVE_FENSTER_S = 7 * 86400;

    /** Wie weit das Gefälle des Pegels (Verbrauch ohne Generator mit Livewert) zurückschaut. */
    public const GEFAELLE_FENSTER_S = 6 * 3600;

    /** Eine Betankung wird frühestens 30 min nach dem Tanken ausgewertet – vorher fehlen die Werte danach. */
    public const AUSWERTEN_NACH_S = 1800;

    /** Wie weit eine Betankung zurückliegen darf. */
    public const BETANKUNG_ZURUECK_S = 7 * 86400;

    /** Verbrauch je Tag: höchstens so viele Tage auf einmal (Median je 10 min). */
    public const TAGE_MAX = 92;

    /** … gelesen ab einem Tag davor – für den Wert um Mitternacht. */
    private const TAGE_VORLAUF_S = 86400;

    /** Eintragen nur im Datenfenster (darfEintragen). */
    public const AUSSERHALB_FENSTER = 'Eintragen geht nur, solange deine Freigabe für die Messdaten dieses Tanks gilt.';

    /**
     * Eigenschaften eines Tanks, die seine Kennlinie hier gelernt (Peilung,
     * Betankung) oder von Hand bekommen hat. Eine Location von einer anderen
     * Instanz überschreibt sie an einem Tank hier nicht
     * (LocationBundle::stromAnwenden() mit ohne_messpunkte) – wie die
     * Messpunkte bleiben sie, wie sie sind.
     */
    public const KENNLINIE_HIER = ['liter_je_mm', 'liter_je_mm_herkunft'];

    /** Meldung, wenn am Tank gerade schon eingetragen wird (gesperrt()). */
    public const GERADE_SCHON = 'An diesem Tank wird gerade schon eingetragen – nichts doppelt eingetragen. Gleich die Seite neu laden.';

    /** Woher die Menge einer Betankung im Verbrauch je Tag stammt (TankRechnung::tagesbilanz). */
    public const BETANKUNG_QUELLEN = [
        'erkannt'      => 'erkannt, ohne Eintrag',
        'lieferschein' => 'laut Lieferschein',
        'protokoll'    => 'eingetragen, ohne Messwerte',
        'ohne_sprung'  => 'eingetragen, ohne Sprung in den Messwerten',
    ];

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
                    // Zum Lesen auf 4 Stellen (ein gelernter Inhalt je mm hat 6) – das Formular zeigt alle.
                    'wert'         => is_float($wert) || is_int($wert) ? self::zahl(round((float) $wert, 4)) : (string) $wert,
                    'einheit'      => AnlagenService::einheit($typ, $zusatz, $wert),
                ];
            }
            if ($zeilen !== []) {
                $gruppen[$titel] = $zeilen;
            }
        }
        $darfAendern = $vorlage ? (Auth::can('stecker.manage') || Zugriff::alles()) : Zugriff::darfBearbeiten($tank);
        // Eintragen (peilen, betanken, kalibrieren, einbauen) nur, solange das Datenfenster jetzt gilt.
        $darfEintragen = !$vorlage && self::darfEintragen($tank);
        // Der laufende Tanksensor und seine Kennlinie – sichtbar für jeden, der
        // den Tank sieht; ein- und ausbauen darf, wer am Tank bearbeiten darf.
        $sensor = !$vorlage ? self::sensor((int) $tank['id']) : null;
        $fassung = $sensor !== null ? self::fassungJetzt((int) $sensor['id']) : null;
        $fenster = Zugriff::datenFenster($tank);
        if ($fassung !== null && !self::fassungImFenster($fassung, $sensor, $fenster)) {
            $fassung = null;    // eine Kennlinie von außerhalb des Datenfensters sieht niemand
        }
        $frei = $darfEintragen && Auth::can('datenquellen.view')
            ? array_values(array_filter(InventarService::fuerBetrachter(InventarService::liste(['art' => 'tanksensor'])),
                static fn (array $i): bool => $i['zustand'] !== 'defekt' && (int) ($i['einsatz']['objekt_id'] ?? 0) !== (int) $tank['id']))
            : [];
        $frueher = $vorlage ? [] : array_values(array_filter(self::sensoren((int) $tank['id']),
            static fn (array $m): bool => $m['gueltig_bis'] !== null && self::zeitraumImFenster($m, $fenster)));
        $live = $vorlage ? [] : (LiveService::fuerObjekte([$tank])[(int) $tank['id']] ?? []);
        return [
            'objekt'      => $tank,
            'vorlage'     => $vorlage,
            'kurzform'    => self::kurzform($tank),
            'gruppen'     => $gruppen,
            'lage'        => $vorlage ? null : PlanGeoService::fuerObjekt($tank),
            'darfAendern' => $darfAendern,
            'darfEintragen' => $darfEintragen,
            'sensor'      => $sensor,
            'fassung'     => $fassung,
            'kennlinie'   => self::kennlinieText($fassung, $tank),
            'hinweise'    => $fassung !== null ? TankRechnung::hinweise($fassung['rechnung']) : [],
            'sensorFrei'  => $frei,
            'live'        => $live,
            // Schläuche, Generatoren mit Kennlinie, Gegenprüfung Pegel ↔ Last
            'versorgung'  => $vorlage ? null : self::versorgungSeite($tank, $live),
            'fenster'     => $fenster,
            'fassungen'   => $vorlage ? [] : self::fassungenSichtbar($tank),
            'frueher'     => $frueher,
            // Vor Ort: Protokoll, das Angebot aus zwei Peilungen, „Erweitert“ (rückwirkend nur ohne Datengrenze)
            'ereignisse'  => $vorlage ? [] : self::ereignisse($tank),
            'steigung'    => $darfEintragen ? self::steigungAngebot($tank) : null,
            'rueckwirkend' => $darfEintragen && $fenster === null,
            'nenn_l'      => TankRechnung::zahlLesen($eigenschaften['inhalt_l'] ?? null),
        ];
    }

    /**
     * Die Zeile unter dem Balken: „noch 19 h · nachbestellen bis Mo 02:29“,
     * ohne Restlaufzeit der Verbrauch („Generator steht“) – sonst leer.
     * live.js setzt dieselbe Zeile aus den Livewerten.
     */
    public static function restZeile(array $t): string
    {
        if (is_string($t['restlaufzeit_text'] ?? null)) {
            return $t['restlaufzeit_text'] . (is_string($t['nachbestellen_text'] ?? null) ? ' · nachbestellen bis ' . $t['nachbestellen_text'] : '');
        }
        return (string) ($t['verbrauch_text'] ?? '');
    }

    /**
     * „Betankung 16.10.2026 08:10 · geprüft“, „Einbau … · seit Einbau nicht
     * gepeilt“ – die jetzige Kennlinie im Klartext. Bei einer Betankung die
     * Zeit des Tankens (im Datenfenster), nicht die der Auswertung. Ungeprüft
     * heißt „seit Einbau nicht gepeilt“, solange im Einbau nie gepeilt wurde –
     * eine Betankung prüft den Inhalt je mm, nicht die Einbautiefe.
     */
    public static function kennlinieText(?array $fassung, ?array $tank = null): string
    {
        if ($fassung === null) {
            return 'keine – nur Rohwert';
        }
        $r = $fassung['rechnung'];
        $wann = Zeit::anzeige($fassung['ab'], 'd.m.Y H:i');
        if ($fassung['anlass'] === 'betankung') {
            $e = $tank !== null && $fassung['ereignis_id'] !== null ? self::ereignisFinden($tank, (int) $fassung['ereignis_id'], 'betankung') : null;
            $wann = $e !== null ? Zeit::anzeige((string) $e['zeit'], 'd.m.Y H:i') : 'ausgewertet ' . $wann;
        }
        $teile = [(self::ANLAESSE[$fassung['anlass']] ?? $fassung['anlass']) . ' ' . $wann];
        if (($r['tank']['liter_je_mm'] ?? null) !== null && ($r['sensor']['art'] ?? '') === 'linear') {
            array_unshift($teile, self::zahl(round((float) $r['tank']['liter_je_mm'], 3)) . ' l/mm');
        }
        $teile[] = $fassung['geprueft'] ? 'geprüft' : (self::gepeiltImEinbau($fassung) ? 'ungeprüft – bitte peilen' : 'seit Einbau nicht gepeilt');
        return implode(' · ', $teile);
    }

    /** Gab es in diesem Einbau (auch an einem früheren Shelly desselben Einsatzes) bis zu dieser Fassung eine Peilung? */
    private static function gepeiltImEinbau(array $fassung): bool
    {
        $mp = (int) ($fassung['messpunkt_id'] ?? 0);
        $einsatz = Database::scalar('SELECT einsatz_id FROM messpunkte WHERE id = ?', [$mp]);
        $messpunkte = $einsatz !== null && $einsatz !== false
            ? array_map('intval', array_column(Database::select('SELECT id FROM messpunkte WHERE einsatz_id = ?', [(int) $einsatz]), 'id'))
            : [$mp];
        $messpunkte = $messpunkte !== [] ? $messpunkte : [$mp];
        return (int) Database::scalar("SELECT COUNT(*) FROM tank_kennlinien WHERE anlass = 'peilung' AND id <= ? AND messpunkt_id IN ("
            . implode(',', array_fill(0, count($messpunkte), '?')) . ')', array_merge([(int) $fassung['id']], $messpunkte)) > 0;
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
        $angefragt = [];
        foreach ($tanks as $t) {
            $angefragt[(int) $t['id']] = $t;
        }
        // Versorgung über Kraftstoffschläuche: Ein Tank derselben Versorgung,
        // der nicht gefragt ist, zählt zum Vorrat – nur, wenn der Betrachter
        // ihn sieht.
        $versorgung = self::versorgungen(array_keys($angefragt));
        $alle = $angefragt;
        foreach (array_keys($angefragt) as $id) {
            foreach ($versorgung[$id]['tanks'] ?? [] as $andere) {
                if (!isset($alle[$andere]) && ($o = AnlagenService::find($andere)) !== null && $o['art'] === 'tank'
                    && (int) $o['ist_vorlage'] === 0 && Zugriff::siehtObjekt($o)) {
                    $alle[$andere] = $o;
                }
            }
        }
        $ids = array_keys($alle);
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
        $fertig = [];
        $offen = [];
        $abfragen = [];
        foreach ($alle as $id => $tank) {
            $fenster = Zugriff::datenFenster($tank);
            if (!Zugriff::liveErlaubt($fenster, $jetzt)) {
                $fertig[$id] = self::liveAntwort(['stufe' => 'gesperrt', 'hinweis' => 'Livewerte außerhalb des freigegebenen Zeitraums'], null);
                continue;
            }
            $peilung = self::letztePeilung($tank, $fenster);
            $m = $sensoren[$id] ?? null;
            $eig = (array) $tank['eigenschaften'];
            if ($m === null) {
                $stufe = TankRechnung::stufe(['sensor' => false, 'peilung' => $peilung, 'jetzt' => $jetzt], $grenzen, $eig);
                $fertig[$id] = self::liveAntwort($stufe, ['sensor' => false, 'peilung' => $peilung,
                    'nenn_l' => TankRechnung::zahlLesen($eig['inhalt_l'] ?? null)]);
                continue;
            }
            $fassung = self::fassungJetzt((int) $m['id'], $jetzt);
            $feld = $fassung['rechnung']['feld'] ?? (self::sensordaten($m['sensordaten'] ?? null)['feld'] ?? null);
            // Ab wann gelesen wird: frühestens der Einbau, höchstens 7 Tage, im
            // laufenden Teil des Datenfensters. Das Lebenszeichen gehört zum
            // Gerät und kommt nur beim Verbinden – es zählt ohne die 7 Tage,
            // bleibt aber ebenso im Datenfenster.
            $ab = self::laufendAb(MesspunktService::fenster($m, $tank), $jetzt);
            $abOnline = self::laufendAb($fenster, $jetzt, null);
            if ($ab !== null && $abOnline !== null && is_string($feld) && isset(TankRechnung::ROHFELDER[$feld])) {
                $abfragen[] = ['geraet' => (string) $m['geraet'], 'kanal' => (string) $m['kanal'], 'feld' => $feld, 'ab' => $ab, 'ab_online' => $abOnline];
            }
            $offen[$id] = [$tank, $m, $fassung, is_string($feld) ? $feld : null, $peilung, $ab];
        }
        $werte = Messdaten::tankLetzte($abfragen);
        $mitSensor = [];
        foreach ($offen as $id => [$tank, $m, $fassung, $feld, $peilung, $ab]) {
            $mitSensor[$id] = self::werteMitSensor($tank, $m, $fassung, $feld, $peilung, $werte[$m['geraet'] . '|' . $m['kanal']] ?? null, $jetzt, $ab);
        }
        // Zweiter Schritt: Verbrauch, Vorrat und Restlaufzeit je Versorgung –
        // erst dann die Stufe, sie hängt an der Restlaufzeit.
        self::versorgungRechnen($mitSensor, $versorgung, $generatorLive, $grenzen, $jetzt);
        $out = [];
        foreach (array_keys($angefragt) as $id) {
            if (isset($fertig[$id])) {
                $out[$id] = $fertig[$id];
            } elseif (isset($mitSensor[$id])) {
                $out[$id] = self::liveFertig($mitSensor[$id], $grenzen, $jetzt);
            }
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
        $abOnline = self::laufendAb(Zugriff::datenFenster($tank), $jetzt, null);
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

    /**
     * Ein Tank mit Sensor: Rohwert → Füllhöhe → Liter. Die Stufe folgt erst
     * in liveFertig(), wenn Verbrauch und Restlaufzeit der Versorgung
     * feststehen.
     *
     * @return array{tank:array,m:array,fassung:?array,r:?array,feld:?string,roh:?float,erg:array,alter:?int,t:array<string,mixed>}
     */
    private static function werteMitSensor(array $tank, array $m, ?array $fassung, ?string $feld, ?array $peilung, ?array $w, int $jetzt, ?int $ab = null): array
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
            'kennlinie'     => self::kennlinieText($fassung, $tank),
            'fehler'        => $erg['fehler'],
            'ueber_grenze'  => $erg['ueber_grenze'],
            'schritt_l'     => $r !== null ? $rund(TankRechnung::schrittLiter($r), 2) : null,
            // Verbrauch und Restlaufzeit: aus der Versorgung (versorgungRechnen)
            'verbrauch_l_h' => null, 'verbrauch_quelle' => null, 'verbrauch_geschaetzt' => false, 'verbrauch_text' => '',
            'vorrat_l' => null, 'vorrat_tanks' => 0, 'vorrat_vollstaendig' => false,
            'restlaufzeit_h' => null, 'restlaufzeit_text' => null, 'leer_um' => null, 'leer_um_text' => null,
            'nachbestellen_bis' => null, 'nachbestellen_text' => null,
            'generatoren'   => [],
            'intern_l'      => null,
            'peilung'       => $peilung,
            // Ohne Wert: seit dem Einbau oder nur in den letzten 7 Tagen (bzw. im freigegebenen Zeitraum) nicht?
            'ohne_wert'     => self::ohneWertText($m, $ab, $jetzt),
        ];
        return ['tank' => $tank, 'm' => $m, 'fassung' => $fassung, 'r' => $r, 'feld' => $feld, 'roh' => $roh, 'erg' => $erg, 'alter' => $alter, 't' => $t];
    }

    /** Stufe, Hinweis und die Antwort in der Form von LiveService für einen Tank mit Sensor. */
    private static function liveFertig(array $x, array $grenzen, int $jetzt): array
    {
        $t = $x['t'];
        $r = $x['r'];
        $stufe = TankRechnung::stufe($t + ['alter_s' => $x['alter'], 'grenze_prozent' => $r['tank']['grenze_prozent'] ?? null, 'jetzt' => $jetzt],
            $grenzen, (array) $x['tank']['eigenschaften']);
        if ($x['feld'] === null) {
            $stufe = ['stufe' => 'stumm', 'hinweis' => 'Sensordaten unvollständig – am Tanksensor nachtragen, sonst lässt sich nichts lesen'];
        } elseif ($r === null && $stufe['stufe'] === 'ok') {
            unset($stufe['kurz']);
            $stufe['hinweis'] = 'Rohwert ' . ($x['roh'] !== null ? TankRechnung::zahl(round($x['roh'], 3)) . ($t['roh_einheit'] !== '' ? ' ' . $t['roh_einheit'] : '') : '–')
                . ' – keine Kennlinie: Sensordaten am Tanksensor nachtragen';
        }
        $antwort = self::liveAntwort($stufe, $t);
        // Kachel und Panel zeigen Restlaufzeit und Verbrauch in eigener Zeile – dort der Hinweis ohne sie.
        $antwort['tank']['hinweis_kurz'] = $stufe['kurz'] ?? $stufe['hinweis'];
        $antwort['alter_s'] = $x['alter'];
        if ($x['erg']['fehler'] === null || $x['erg']['liter'] !== null) {
            $antwort['zustand'] = array_filter(['tank_prozent' => $t['prozent'], 'tank_liter' => $t['liter']], static fn ($v) => $v !== null);
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
     * Beginn des laufenden Teils eines Fensters in den letzten 7 Tagen (bzw.
     * $zurueck Sekunden, null = ohne Grenze) – oder null, wenn jetzt nichts
     * freigegeben ist.
     *
     * @param list<array{0:?int,1:?int}>|null $fenster
     */
    private static function laufendAb(?array $fenster, int $jetzt, ?int $zurueck = self::LIVE_FENSTER_S): ?int
    {
        foreach (Zugriff::begrenzen($fenster, $zurueck !== null ? $jetzt - $zurueck : 0, $jetzt) as [$a, $b]) {
            if ($b >= $jetzt) {
                return (int) $a;
            }
        }
        return null;
    }

    /**
     * Was „stumm“ sagt, wenn kein Wert kam: seit dem Einbau nicht, in den
     * letzten 7 Tagen nicht (so weit schaut Live zurück) oder im
     * freigegebenen Zeitraum nicht.
     */
    private static function ohneWertText(array $m, ?int $ab, int $jetzt): string
    {
        $einbau = Zeit::ts($m['gueltig_ab'] ?? null);
        return match (true) {
            $ab === null || ($einbau !== null && $ab <= $einbau) => 'noch kein Wert seit dem Einbau',
            $ab <= $jetzt - self::LIVE_FENSTER_S                 => 'kein Wert in den letzten 7 Tagen',
            default                                              => 'kein Wert im freigegebenen Zeitraum',
        };
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

    // ------------------------------------------------------------ Versorgung (Kraftstoffschlauch)

    /**
     * Die Generatoren an den Kraftstoffschläuchen je Tank – ohne Rechteprüfung
     * (wer was sieht, entscheiden live() und versorgungSeite()).
     *
     * @param list<int> $tankIds
     * @return array<int,list<array<string,mixed>>> tank_id => Generatoren (AnlagenService::find)
     */
    public static function verbraucher(array $tankIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $tankIds)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $gesehen = [];
        foreach (Database::select(
            "SELECT DISTINCT l.von_objekt_id AS tank, l.zu_objekt_id AS generator FROM leitungen l JOIN objekte o ON o.id = l.zu_objekt_id
             WHERE l.art = 'kraftstoff' AND o.art = 'generator' AND l.von_objekt_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY l.zu_objekt_id',
            $ids
        ) as $r) {
            $g = $gesehen[(int) $r['generator']] ??= AnlagenService::find((int) $r['generator']);
            if ($g !== null) {
                $out[(int) $r['tank']][] = $g;
            }
        }
        return $out;
    }

    /**
     * Die Versorgung eines Tanks: alle Tanks und Generatoren, die über
     * Kraftstoffschläuche zusammenhängen (ein Generator an zwei Tanks, zwei
     * Generatoren an einem Tank …). Vorrat, Verbrauch und Restlaufzeit
     * gelten für alle ihre Tanks gleich. Ohne Rechteprüfung.
     *
     * @return array{tanks:list<int>,generatoren:list<int>}
     */
    public static function versorgung(int $tankId): array
    {
        return self::versorgungen([$tankId])[$tankId];
    }

    /**
     * @param list<int> $tankIds
     * @return array<int,array{tanks:list<int>,generatoren:list<int>}> für jeden gefragten Tank (und die übrigen Tanks seiner Versorgung)
     */
    private static function versorgungen(array $tankIds): array
    {
        $nachbarn = [];
        $art = [];
        foreach (Database::select(
            "SELECT l.von_objekt_id AS von, l.zu_objekt_id AS zu, ov.art AS von_art, oz.art AS zu_art FROM leitungen l
             JOIN objekte ov ON ov.id = l.von_objekt_id JOIN objekte oz ON oz.id = l.zu_objekt_id WHERE l.art = 'kraftstoff'"
        ) as $l) {
            $von = (int) $l['von'];
            $zu = (int) $l['zu'];
            $nachbarn[$von][$zu] = true;
            $nachbarn[$zu][$von] = true;
            $art[$von] = (string) $l['von_art'];
            $art[$zu] = (string) $l['zu_art'];
        }
        $out = [];
        foreach ($tankIds as $id) {
            $id = (int) $id;
            if (isset($out[$id])) {
                continue;
            }
            $gesehen = [$id => true];
            $offen = [$id];
            while ($offen !== [] && count($gesehen) < 500) {
                foreach (array_keys($nachbarn[array_pop($offen)] ?? []) as $y) {
                    if (!isset($gesehen[$y])) {
                        $gesehen[$y] = true;
                        $offen[] = $y;
                    }
                }
            }
            $k = ['tanks' => [], 'generatoren' => []];
            $alle = array_keys($gesehen);
            sort($alle);
            foreach ($alle as $y) {
                if ($y === $id || ($art[$y] ?? '') === 'tank') {
                    $k['tanks'][] = $y;
                } elseif (($art[$y] ?? '') === 'generator') {
                    $k['generatoren'][] = $y;
                }
            }
            foreach ($k['tanks'] as $t) {
                $out[$t] = $k;
            }
        }
        return $out;
    }

    /**
     * Die Generatoren einer Versorgung jetzt: Läuft er, wie viel verbraucht
     * er (Kennlinie × Last, ohne Kennlinie Volllast – geschätzt)? Nur, wen
     * der Betrachter sieht und jetzt live sehen darf – die übrigen zählen als
     * unbekannt, ohne Name und Leistung.
     *
     * Läuft: P ≥ 2 % · kVA · 800 W (ohne kVA 100 W), Drehzahl > 0 oder
     * Motorzustand „Läuft“. Läuft er ohne bekannte Leistung, gilt der kleinste
     * Punkt der Kennlinie.
     *
     * @param list<int> $ids
     * @param array<int,array<string,mixed>> $live Livewerte, die schon da sind (LiveService, erster Durchgang)
     * @return array<int,array{sichtbar:bool,id?:int,name?:string,laeuft?:?bool,l_h?:?float,geschaetzt?:bool,intern_l?:?float}>
     */
    private static function generatorWerte(array $ids, array $live): array
    {
        $objekte = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $g = AnlagenService::find($id);
            $objekte[$id] = $g !== null && $g['art'] === 'generator' && Zugriff::siehtObjekt($g) && Zugriff::liveErlaubt(Zugriff::datenFenster($g)) ? $g : null;
        }
        $fehlen = array_values(array_filter($objekte, static fn (?array $g): bool => $g !== null && !isset($live[(int) $g['id']])));
        if ($fehlen !== []) {
            $live += LiveService::fuerObjekte($fehlen);
        }
        $out = [];
        foreach ($objekte as $id => $g) {
            if ($g === null) {
                $out[$id] = ['sichtbar' => false];
                continue;
            }
            $w = $live[$id] ?? null;
            $z = (array) ($w['zustand'] ?? []);
            $p = is_numeric($w['p'] ?? null) ? (float) $w['p'] : null;
            $drehzahl = is_numeric($z['drehzahl'] ?? null) ? (float) $z['drehzahl'] : null;
            $motor = is_string($z['motorzustand'] ?? null) ? (string) $z['motorzustand'] : null;
            $hatWert = $w !== null && !in_array((string) ($w['stufe'] ?? ''), ['keine', 'gesperrt', 'stumm'], true)
                && ($p !== null || $drehzahl !== null || $motor !== null);
            $kva = TankRechnung::zahlLesen($g['nennleistung_kva'] ?? null);
            $laeuft = !$hatWert ? null
                : (($p !== null && $p >= ($kva !== null && $kva > 0 ? 0.02 * $kva * 800 : 100.0)) || ($drehzahl ?? 0.0) > 0 || $motor === 'Läuft');
            $eig = (array) $g['eigenschaften'];
            $verbrauch = match ($laeuft) {
                true    => TankRechnung::verbrauch(TankRechnung::kennlinieLesen((string) ($eig['verbrauch_kennlinie'] ?? '')),
                    TankRechnung::zahlLesen($eig['verbrauch_l_h'] ?? null), $p !== null ? TankRechnung::last($p, $kva) : null),
                false   => ['l_h' => 0.0, 'geschaetzt' => false],
                default => null,
            };
            // Ein interner Tank (ComAp meldet tank_prozent) zählt nicht zum Vorrat – der 3-Wege-Hahn schaltet intern oder extern.
            $internL = TankRechnung::zahlLesen($eig['tank_l'] ?? null);
            $out[$id] = [
                'sichtbar'   => true,
                'id'         => $id,
                'name'       => (string) $g['name'],
                'laeuft'     => $laeuft,
                'l_h'        => $verbrauch['l_h'] ?? null,
                'geschaetzt' => (bool) ($verbrauch['geschaetzt'] ?? false),
                'intern_l'   => $internL !== null && is_numeric($z['tank_prozent'] ?? null) ? round($internL * (float) $z['tank_prozent'] / 100, 0) : null,
            ];
        }
        return $out;
    }

    /**
     * Verbrauch, Vorrat und Restlaufzeit je Versorgung in die Werte der Tanks
     * schreiben. Verbrauch aus der Last, wenn jeder Generator der Versorgung
     * sichtbar ist, einen Livewert und eine Verbrauchsangabe hat – sonst aus
     * dem Pegel (Gefälle der letzten 6 h, nur bei Bedarf gelesen).
     *
     * @param array<int,array<string,mixed>> $tanks werteMitSensor() je Tank
     * @param array<int,array{tanks:list<int>,generatoren:list<int>}> $versorgung
     */
    private static function versorgungRechnen(array &$tanks, array $versorgung, array $generatorLive, array $grenzen, int $jetzt): void
    {
        if ($tanks === []) {
            return;
        }
        $genIds = [];
        foreach (array_keys($tanks) as $id) {
            foreach ($versorgung[$id]['generatoren'] ?? [] as $g) {
                $genIds[$g] = true;
            }
        }
        $gen = $genIds !== [] ? self::generatorWerte(array_keys($genIds), $generatorLive) : [];
        $komponenten = [];
        $pegel = [];
        foreach (array_keys($tanks) as $id) {
            $k = $versorgung[$id] ?? ['tanks' => [$id], 'generatoren' => []];
            $schluessel = implode(',', $k['tanks']);
            if (isset($komponenten[$schluessel])) {
                continue;
            }
            $g = array_map(static fn (int $gid): array => $gen[$gid] ?? ['sichtbar' => false], $k['generatoren']);
            $ausLast = $g !== [] && array_filter($g, static fn (array $x): bool => !$x['sichtbar'] || $x['l_h'] === null) === [];
            $komponenten[$schluessel] = $k + ['g' => $g, 'last' => $ausLast];
            if (!$ausLast) {
                foreach ($k['tanks'] as $tid) {
                    if (isset($tanks[$tid])) {
                        $pegel[$tid] = $tanks[$tid];
                    }
                }
            }
        }
        $gefaelle = $pegel !== [] ? self::pegelJe($pegel, $jetzt) : [];
        $vorlaufH = TankRechnung::grenzen($grenzen)['tank_vorlauf_h'];
        foreach ($komponenten as $k) {
            $vorrat = null;
            $gezaehlt = 0;
            foreach ($k['tanks'] as $tid) {
                $n = $tanks[$tid]['t']['nutzbar_l'] ?? null;
                if ($n !== null && ($tanks[$tid]['t']['fehler'] ?? null) === null) {
                    $vorrat = ($vorrat ?? 0.0) + max(0.0, (float) $n);
                    $gezaehlt++;
                }
            }
            $sichtbar = array_values(array_filter($k['g'], static fn (array $x): bool => $x['sichtbar']));
            $intern = array_filter(array_column($sichtbar, 'intern_l'), static fn ($v): bool => $v !== null);
            if ($k['last']) {
                $lh = (float) array_sum(array_column($k['g'], 'l_h'));
                $quelle = 'last';
                $geschaetzt = in_array(true, array_column($k['g'], 'geschaetzt'), true);
                $text = $lh <= 0 ? (count($k['g']) === 1 ? 'Generator steht' : 'Generatoren stehen')
                    : self::zahl(round($lh, 1)) . ' l/h aus der Last' . ($geschaetzt ? ' (geschätzt)' : '');
            } else {
                $werte = array_values(array_filter(array_map(static fn (int $tid): ?float => $gefaelle[$tid] ?? null, $k['tanks']), static fn ($v): bool => $v !== null));
                $lh = $werte !== [] ? (float) array_sum($werte) : null;
                $quelle = $lh !== null ? 'pegel' : null;
                $geschaetzt = false;
                $text = $lh !== null ? '≈ ' . self::zahl(round($lh, 1)) . ' l/h aus dem Pegel (6 h)' : '';
            }
            $rest = $lh !== null && $lh > 0 && $vorrat !== null ? $vorrat / $lh : null;
            $leer = $rest !== null ? $jetzt + (int) round($rest * 3600) : null;
            $bis = $leer !== null ? $leer - (int) round($vorlaufH * 3600) : null;
            foreach ($k['tanks'] as $tid) {
                if (!isset($tanks[$tid])) {
                    continue;
                }
                $tanks[$tid]['t'] = array_merge($tanks[$tid]['t'], [
                    'verbrauch_l_h'        => $lh !== null ? round($lh, 2) : null,
                    'verbrauch_quelle'     => $quelle,
                    'verbrauch_geschaetzt' => $geschaetzt,
                    'verbrauch_text'       => $text,
                    'vorrat_l'             => $vorrat !== null ? round($vorrat, 1) : null,
                    'vorrat_tanks'         => $gezaehlt,
                    'vorrat_vollstaendig'  => $gezaehlt === count($k['tanks']),
                    'restlaufzeit_h'       => $rest !== null ? round($rest, 2) : null,
                    'restlaufzeit_text'    => $rest !== null ? TankRechnung::restText($rest) : null,
                    'leer_um'              => $leer !== null ? Zeit::iso($leer) : null,
                    'leer_um_text'         => $leer !== null ? TankRechnung::wann($leer, $jetzt) : null,
                    'nachbestellen_bis'    => $bis !== null ? Zeit::iso($bis) : null,
                    'nachbestellen_text'   => $bis !== null ? TankRechnung::wann($bis, $jetzt) : null,
                    // nur sichtbare Generatoren, ohne Leistung
                    'generatoren'          => array_map(static fn (array $x): array => ['id' => $x['id'], 'name' => $x['name'], 'laeuft' => $x['laeuft'] === true], $sichtbar),
                    'intern_l'             => $intern !== [] ? (float) array_sum($intern) : null,
                ]);
            }
        }
    }

    /**
     * Verbrauch aus dem Pegel je Tank (l/h, TankRechnung::pegelVerbrauch):
     * Rohwerte der letzten 6 h seit dem Einbau, im Datenfenster, Median je
     * 10 Minuten, je Punkt mit der Fassung, die damals galt.
     *
     * @param array<int,array<string,mixed>> $tanks werteMitSensor() je Tank
     * @return array<int,?float>
     */
    private static function pegelJe(array $tanks, int $jetzt): array
    {
        $abfragen = [];
        foreach ($tanks as $id => $x) {
            if ($x['r'] === null || $x['feld'] === null || ($x['t']['liter'] ?? null) === null) {
                continue;    // ohne Liter kein Verbrauch
            }
            $m = $x['m'];
            $abfragen[$id] = ['geraet' => (string) $m['geraet'], 'kanal' => (string) $m['kanal'], 'feld' => (string) $x['feld'],
                'ab' => max($jetzt - self::GEFAELLE_FENSTER_S, Zeit::ts($m['gueltig_ab'] ?? null) ?? 0), 'fenster' => MesspunktService::fenster($m, $x['tank'])];
        }
        if ($abfragen === []) {
            return [];
        }
        $fehler = null;
        $roh = Messdaten::tankGefaelle(array_values($abfragen), $fehler);
        if ($fehler !== null) {
            Logger::info('Tank-Gefälle: ' . $fehler);
        }
        $out = [];
        foreach ($abfragen as $id => $a) {
            $f = array_map(static fn (array $x): array => ['id' => $x['id'], 'ab' => $x['ab_ts'], 'rechnung' => $x['rechnung']], self::fassungen((int) $tanks[$id]['m']['id']));
            $abschnitte = TankRechnung::abschnitte($f, (int) $a['ab'], $jetzt + 1);
            $liter = [];
            foreach ($roh[$a['geraet'] . '|' . $a['kanal']] ?? [] as [$ms, $w]) {
                $s = intdiv((int) $ms, 1000);
                foreach ($abschnitte as [$von, $bis, $r]) {
                    if ($s >= $von && $s < $bis) {
                        $liter[] = [(int) $ms, TankRechnung::umrechnen($r, (float) $w)['liter']];
                        break;
                    }
                }
            }
            $out[$id] = TankRechnung::pegelVerbrauch($liter, $tanks[$id]['t']['schritt_l'], $tanks[$id]['t']['nenn_l']);
        }
        return $out;
    }

    /**
     * Was die Tankseite unter „Versorgung“ zeigt: die Schläuche des Tanks mit
     * dem Generator am anderen Ende, die Generatoren der Versorgung mit ihrer
     * Kennlinie und die Gegenprüfung – passt das Gefälle des Pegels (6 h) zum
     * Verbrauch aus der Last? Generatoren, die der Betrachter nicht sieht,
     * stehen ohne Namen da.
     *
     * @param array<string,mixed> $live Zusammenfassung des Tanks (live())
     * @return array{schlaeuche:list<array<string,mixed>>,generatoren:list<array<string,mixed>>,verborgen:int,tanks:int,gemessen_l_h:?float,gegenpruefung:?string}
     */
    public static function versorgungSeite(array $tank, array $live): array
    {
        $id = (int) $tank['id'];
        $sieht = static function (?int $objektId): ?array {
            $o = $objektId !== null ? AnlagenService::find($objektId) : null;
            return $o !== null && Zugriff::siehtObjekt($o) ? $o : null;
        };
        $schlaeuche = [];
        foreach (Database::select("SELECT id FROM leitungen WHERE art = 'kraftstoff' AND von_objekt_id = ? ORDER BY id", [$id]) as $row) {
            $l = LeitungService::find((int) $row['id']);
            if ($l === null) {
                continue;
            }
            $g = $sieht($l['zu_objekt_id'] !== null ? (int) $l['zu_objekt_id'] : null);
            $schlaeuche[] = [
                'id' => (int) $l['id'], 'name' => (string) $l['name'], 'typ' => (string) $l['kabeltyp'], 'kupplung' => (string) $l['modell'],
                'anzahl' => (int) $l['anzahl'], 'laenge' => $l['laenge'] !== null ? round((float) $l['laenge'], 1) : null, 'fest' => (int) $l['fest'] === 1,
                'generator' => $g !== null ? (string) $g['name'] : null, 'generator_id' => $g !== null ? (int) $g['id'] : null,
                'hinweise' => $l['hinweise'],
            ];
        }
        $v = self::versorgung($id);
        $laeuft = array_column((array) ($live['tank']['generatoren'] ?? []), 'laeuft', 'id');
        $generatoren = [];
        $verborgen = 0;
        foreach ($v['generatoren'] as $gid) {
            $g = $sieht($gid);
            if ($g === null) {
                $verborgen++;
                continue;
            }
            $eig = (array) $g['eigenschaften'];
            $kennlinie = trim((string) ($eig['verbrauch_kennlinie'] ?? ''));
            $vollast = TankRechnung::zahlLesen($eig['verbrauch_l_h'] ?? null);
            $generatoren[] = [
                'id' => $gid, 'name' => (string) $g['name'], 'modell' => trim($g['hersteller'] . ' ' . $g['modell']),
                'laeuft' => $laeuft[$gid] ?? null,
                'kennlinie' => TankRechnung::kennlinieLesen($kennlinie) !== [] ? $kennlinie . ' (l/h je Last)'
                    : ($vollast !== null ? 'ohne Kennlinie – Volllastverbrauch ' . self::zahl($vollast) . ' l/h angenommen' : 'kein Verbrauch eingetragen – Restlaufzeit aus dem Pegel'),
            ];
        }
        // Gegenprüfung (nur hier, nicht bei jeder Liveabfrage): das Gefälle des
        // Pegels neben dem Verbrauch aus der Last – bei einem Tank je Versorgung.
        $t = (array) ($live['tank'] ?? []);
        $gemessen = null;
        $hinweis = null;
        $m = !empty($t['sensor']) ? self::sensor($id) : null;
        $fassung = $m !== null ? self::fassungJetzt((int) $m['id']) : null;
        if ($m !== null && $fassung !== null && ($t['verbrauch_quelle'] ?? null) === 'last' && count($v['tanks']) === 1) {
            $gemessen = self::pegelJe([$id => ['tank' => $tank, 'm' => $m, 'r' => $fassung['rechnung'], 'feld' => (string) $fassung['rechnung']['feld'], 't' => $t]], time())[$id] ?? null;
            $erwartet = (float) ($t['verbrauch_l_h'] ?? 0);
            $schritt = (float) ($t['schritt_l'] ?? 0);
            if ($gemessen !== null && $erwartet > 0 && abs($gemessen - $erwartet) > 0.5 * $erwartet
                && abs($gemessen - $erwartet) * self::GEFAELLE_FENSTER_S / 3600 > 3 * $schritt) {
                $hinweis = 'Verbrauch passt nicht zur Last (Pegel ≈ ' . self::zahl(round($gemessen, 1)) . ' l/h, erwartet ' . self::zahl(round($erwartet, 1))
                    . ' l/h) – saugt der Generator intern?';
            }
        }
        return ['schlaeuche' => $schlaeuche, 'generatoren' => $generatoren, 'verborgen' => $verborgen, 'tanks' => count($v['tanks']),
            'gemessen_l_h' => $gemessen !== null ? round($gemessen, 1) : null, 'gegenpruefung' => $hinweis];
    }

    /**
     * Was die Generatoren der Versorgung zwischen $von und $bis verbraucht
     * haben – zum Abziehen bei einer Betankung. Aus der mittleren Leistung
     * (Messgerät des Generators, im Datenfenster) über Kennlinie × Last. Null
     * mit Grund, wenn es sich nicht sagen lässt: mehrere Tanks an einem
     * Generator, ein Generator nicht sichtbar, ohne Messwerte oder ohne
     * Verbrauchsangabe. Ohne Schlauch: 0.
     *
     * @return array{liter:?float,text:string}
     */
    private static function verbrauchZwischen(array $tank, int $von, int $bis): array
    {
        $v = self::versorgung((int) $tank['id']);
        if ($v['generatoren'] === []) {
            return ['liter' => 0.0, 'text' => ''];
        }
        if (count($v['tanks']) > 1) {
            return ['liter' => null, 'text' => 'mehrere Tanks an einem Generator'];
        }
        $summe = 0.0;
        $geschaetzt = false;
        foreach ($v['generatoren'] as $gid) {
            $g = AnlagenService::find($gid);
            if ($g === null || !Zugriff::siehtObjekt($g)) {
                return ['liter' => null, 'text' => 'ein Generator ist für dich nicht freigegeben'];
            }
            $messpunkte = array_values(array_filter(MesspunktService::fuerObjekt($gid, true), static function (array $m) use ($von, $bis): bool {
                $ab = Zeit::ts($m['gueltig_ab'] ?? null);
                $ende = Zeit::ts($m['gueltig_bis'] ?? null);
                return ($ab === null || $ab < $bis) && ($ende === null || $ende > $von);
            }));
            $haupt = MesspunktService::haupt($messpunkte);
            $fehler = null;
            $reihe = $haupt !== null ? Messdaten::reihe('strom', (string) $haupt['geraet'], (string) $haupt['kanal'], 'p', ['sum'], $von, $bis,
                MesspunktService::fenster($haupt, $g), 60, 'mean', $fehler) : ['serien' => []];
            $werte = array_values(array_filter($reihe['serien']['sum'] ?? [], static fn ($w): bool => $w !== null));
            if ($werte === []) {
                return ['liter' => null, 'text' => '„' . $g['name'] . '“ ohne Messwerte um diese Zeit'];
            }
            $p = array_sum($werte) / count($werte);
            $kva = TankRechnung::zahlLesen($g['nennleistung_kva'] ?? null);
            if ($p < ($kva !== null && $kva > 0 ? 0.02 * $kva * 800 : 100.0)) {
                continue;    // stand
            }
            $eig = (array) $g['eigenschaften'];
            $vb = TankRechnung::verbrauch(TankRechnung::kennlinieLesen((string) ($eig['verbrauch_kennlinie'] ?? '')),
                TankRechnung::zahlLesen($eig['verbrauch_l_h'] ?? null), TankRechnung::last($p, $kva));
            if ($vb === null) {
                return ['liter' => null, 'text' => '„' . $g['name'] . '“ ohne Verbrauchsangabe'];
            }
            $summe += $vb['l_h'] * ($bis - $von) / 3600;
            $geschaetzt = $geschaetzt || $vb['geschaetzt'];
        }
        return ['liter' => round($summe, 1), 'text' => $geschaetzt ? 'geschätzt' : 'aus der Last'];
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
     * @param ?int $schritt Sekunden je Punkt (Median); ohne passend zum Zeitraum wie im Diagramm
     * @return array{zeiten:list<int>,punkte:list<?array{roh:float,rechnung:?array,feld:string,messpunkt:int,fassung:?int,kennung:string}>,schritt:int,teile:list<array{0:int,1:int}>,felder:list<string>}
     */
    public static function rohwerte(array $tank, ?int $messpunktId, int $von, int $bis, ?string &$fehler = null, ?int $schritt = null): array
    {
        $bis = min($bis, time());
        $leer = ['zeiten' => [], 'punkte' => [], 'schritt' => $schritt ?? Messdaten::schritt($von, max($von + 1, $bis)), 'teile' => [], 'felder' => []];
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
            foreach (TankRechnung::abschnitte($f, $ma, $mb) as [$a, $b, $r, $fid]) {
                if ($a > $bisher) {
                    $segmente[] = [$bisher, $a, null, $vorgabe, $m, null];   // vor der ersten Fassung: nur der Rohwert
                }
                $segmente[] = [$a, $b, $r, (string) $r['feld'], $m, $fid];
                $bisher = $b;
            }
            if ($bisher < $mb) {
                $segmente[] = [$bisher, $mb, null, $vorgabe, $m, null];
            }
        }
        usort($segmente, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $punkte = [];
        $felder = [];
        $anfaenge = [];
        foreach ($segmente as [$a, $b, $r, $feld, $m, $fid]) {
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
                $punkte[$ms] = ['roh' => (float) $w, 'rechnung' => $r, 'feld' => $feld, 'messpunkt' => (int) $m['id'], 'fassung' => $fid,
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

    // ------------------------------------------------------------ Verbrauch je Tag

    /**
     * Verbrauch je Tag im Zeitraum – ganze Tage in Ortszeit: Anfang +
     * getankt − Ende (TankRechnung::tagesbilanz), aus der Literreihe aller
     * Tanksensoren des Tanks (oder nur $messpunktId), Median je 10 Minuten,
     * je Punkt mit der Fassung, die damals galt; nur im Datenfenster. Gelesen
     * wird ab einem Tag vor dem ersten: Ein Shelly meldet nur bei Änderung,
     * der Wert um Mitternacht liegt zwischen zwei Meldungen.
     *
     * Dazu die Betankungen im Zeitraum – erkannt (ein Sprung in den
     * Messwerten), laut Lieferschein (ein Eintrag ersetzt die Menge des
     * Sprungs) oder nur eingetragen. Einen erkannten Sprung ohne Eintrag darf
     * eintragen, wer am Tank bearbeiten darf, höchstens 7 Tage zurück.
     *
     * @return array{tage:list<array<string,mixed>>,betankungen:list<array<string,mixed>>,summe:array<string,mixed>,kalibriert:bool,schwelle_l:float,schwelle_text:string,hinweis:string,darf_eintragen:bool,von:int,bis:int}
     */
    public static function tage(array $tank, int $von, int $bis, ?string &$fehler = null, ?int $messpunktId = null): array
    {
        $zone = Zeit::zone();
        $jetzt = time();
        $bis = min($bis, $jetzt);
        $tagBeginn = static fn (int $ts): int => (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->setTime(0, 0)->getTimestamp();
        $von = $tagBeginn(min($von, $bis));
        $hinweise = [];
        if ($bis - $von > self::TAGE_MAX * 86400) {
            $von = $tagBeginn($bis - (self::TAGE_MAX - 1) * 86400);
            $hinweise[] = 'Gezeigt werden die letzten ' . self::TAGE_MAX . ' Tage.';
        }
        $roh = self::rohwerte($tank, $messpunktId, $von - self::TAGE_VORLAUF_S, $bis, $fehler, 600);
        $reihe = [];
        $schrittL = null;
        $kalibriert = false;
        $vorher = null;
        $literVon = static function (?array $p): ?float {
            if ($p === null || $p['rechnung'] === null) {
                return null;
            }
            // Ein Sensorfehler oder ein unplausibler Wert ist eine Lücke, kein Inhalt.
            $erg = TankRechnung::umrechnen($p['rechnung'], $p['roh']);
            return $erg['fehler'] === null ? $erg['liter'] : null;
        };
        foreach ($roh['punkte'] as $i => $p) {
            $liter = $literVon($p);
            $ms = $roh['zeiten'][$i];
            // Wo die Kennlinie (oder der Sensor) wechselt, springt die
            // Literreihe um die Korrektur – das ist weder Verbrauch noch
            // Betankung. Dort wird getrennt: Bei neuer Fassung desselben
            // Sensors beginnt der neue Teil mit dem letzten Rohwert, neu
            // gerechnet; bei einem anderen Sensor nach einer Lücke.
            if ($p !== null && $vorher !== null && $liter !== null && ($vorher[1]['messpunkt'] !== $p['messpunkt'] || $vorher[1]['fassung'] !== $p['fassung'])) {
                $reihe[] = [$vorher[0], null];
                if ($vorher[1]['messpunkt'] === $p['messpunkt'] && $vorher[1]['feld'] === $p['feld']) {
                    $neu = $literVon(['roh' => $vorher[1]['roh']] + $p);
                    if ($neu !== null) {
                        $reihe[] = [$vorher[0], $neu];
                    }
                }
            }
            if ($liter !== null) {
                $s = TankRechnung::schrittLiter($p['rechnung']);
                $schrittL = $s !== null && ($schrittL === null || $s > $schrittL) ? $s : $schrittL;
                $kalibriert = true;
                $vorher = [$ms, $p];
            } else {
                $vorher = null;
            }
            $reihe[] = [$ms, $liter];
        }
        $erfasst = [];
        foreach (self::ereignisse($tank, 2000) as $e) {
            $ts = Zeit::ts($e['zeit']);
            if ($e['art'] === 'betankung' && $e['liter'] !== null && $ts !== null && $ts >= $von - self::TAGE_VORLAUF_S && $ts <= $bis + TankRechnung::LIEFERSCHEIN_S) {
                $erfasst[] = ['zeit' => $ts, 'liter' => (float) $e['liter'], 'id' => $e['id'], 'lieferschein' => $e['lieferschein']];
            }
        }
        $schwelle = TankRechnung::betankungSchwelle(TankRechnung::zahlLesen(((array) $tank['eigenschaften'])['inhalt_l'] ?? null), $schrittL);
        $bilanz = TankRechnung::tagesbilanz($reihe, $erfasst, $schwelle, $zone, $schrittL, $von, $bis);

        $literText = static fn (?float $l): string => $l === null ? '–' : self::zahl(round($l)) . ' l';
        $wochentag = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $tage = [];
        $summe = ['verbrauch_l' => 0.0, 'betankt_l' => 0.0, 'stunden' => 0.0];
        foreach ($bilanz['tage'] as $tag => $d) {
            $dt = new \DateTimeImmutable($tag, $zone);
            $tage[] = ['tag' => $tag, 'text' => $wochentag[(int) $dt->format('w')] . ' ' . $dt->format('d.m.')] + $d + [
                'verbrauch_text' => $literText($d['verbrauch_l']),
                'betankt_text'   => $d['betankt_l'] > 0 ? $literText($d['betankt_l']) : '–',
                'l_h_text'       => $d['l_h'] !== null ? self::zahl(round($d['l_h'], 1)) : '–',
            ];
            if ($d['verbrauch_l'] !== null) {
                $summe['verbrauch_l'] += $d['verbrauch_l'];
                $summe['stunden'] += $d['stunden'];
            }
            $summe['betankt_l'] += $d['betankt_l'];
        }
        $summe['l_h'] = $summe['stunden'] > 0 ? round($summe['verbrauch_l'] / $summe['stunden'], 2) : null;
        $summe['text'] = $tage === [] ? '' : 'Zusammen ' . $literText($summe['verbrauch_l']) . ' verbraucht, ' . $literText($summe['betankt_l']) . ' getankt'
            . ($summe['l_h'] !== null ? ' · Ø ' . self::zahl(round($summe['l_h'], 1)) . ' l/h' : '');
        $darf = self::darfEintragen($tank);
        $betankungen = [];
        foreach ($bilanz['betankungen'] as $x) {
            $betankungen[] = $x + [
                'zeit_iso'        => Zeit::iso($x['zeit']),
                'zeit_text'       => Zeit::anzeige(Zeit::iso($x['zeit']), 'd.m. H:i'),
                'liter_text'      => $literText($x['liter']),
                'erkannt_text'    => $x['erkannt_l'] !== null ? $literText($x['erkannt_l']) : '',
                'quelle_text'     => self::BETANKUNG_QUELLEN[$x['quelle']] ?? $x['quelle'],
                // Ein Sprung ohne Eintrag lässt sich eintragen – wie jede Betankung höchstens 7 Tage zurück.
                'eintragen'       => $darf && $x['quelle'] === 'erkannt' && $x['zeit'] >= $jetzt - self::BETANKUNG_ZURUECK_S + 300 && $x['zeit'] <= $jetzt,
                'eintragen_zeit'  => Zeit::fuerEingabe(Zeit::iso($x['zeit'])),
                'eintragen_liter' => (string) (int) round($x['liter']),
            ];
        }
        if (!$kalibriert) {
            $hinweise[] = $roh['zeiten'] === [] ? 'Keine Messwerte im Zeitraum.'
                : 'Liter gibt es erst mit bekanntem Inhalt je mm – peilen oder eine Betankung eintragen.';
        }
        return [
            'tage'           => $tage,
            'betankungen'    => $betankungen,
            'summe'          => $summe,
            'kalibriert'     => $kalibriert,
            'schwelle_l'     => round($schwelle, 1),
            'schwelle_text'  => $literText($schwelle),
            'hinweis'        => implode(' ', $hinweise),
            'darf_eintragen' => $darf,
            'von'            => $von,
            'bis'            => $bis,
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
        // Welche Ereignisse kalibriert haben, und ob ihre Fassung noch die
        // jüngste ihres Messpunkts ist – nur dann lassen sie sich löschen.
        $juengste = [];
        $eigene = [];
        foreach (Database::select('SELECT id, messpunkt_id, ereignis_id FROM tank_kennlinien WHERE objekt_id = ? ORDER BY id', [(int) $tank['id']]) as $f) {
            $juengste[(int) $f['messpunkt_id']] = (int) $f['id'];
            if ($f['ereignis_id'] !== null) {
                $eigene[(int) $f['ereignis_id']][(int) $f['messpunkt_id']] = (int) $f['id'];
            }
        }
        $einheiten = [];
        $jetzt = time();
        $out = [];
        foreach (Database::select('SELECT * FROM tank_ereignisse WHERE objekt_id = ? ORDER BY zeit DESC, id DESC LIMIT 2000', [(int) $tank['id']]) as $e) {
            $ts = Zeit::ts((string) $e['zeit']);
            if ($ts === null || !self::zeitImFenster($ts, $fenster)) {
                continue;
            }
            $zahl = static fn (mixed $x): ?float => $x !== null ? (float) $x : null;
            $mp = $e['messpunkt_id'] !== null ? (int) $e['messpunkt_id'] : null;
            if ($mp !== null && !array_key_exists($mp, $einheiten)) {
                $feld = self::fassungJetzt($mp, $ts)['rechnung']['feld'] ?? null;
                $einheiten[$mp] = is_string($feld) ? (TankRechnung::ROHFELDER[$feld][1] ?? '') : '';
            }
            $fassungen = $eigene[(int) $e['id']] ?? [];
            $ausgewertet = $e['roh_vor'] !== null && $e['roh_nach'] !== null;
            $eintrag = [
                'id' => (int) $e['id'], 'art' => (string) $e['art'], 'zeit' => (string) $e['zeit'],
                'liter' => $zahl($e['liter']), 'prozent' => $zahl($e['prozent']), 'hoehe_mm' => $zahl($e['hoehe_mm']), 'roh' => $zahl($e['roh']),
                'roh_vor' => $zahl($e['roh_vor']), 'roh_nach' => $zahl($e['roh_nach']), 'roh_einheit' => $mp !== null ? $einheiten[$mp] : '',
                'lieferschein' => (string) $e['lieferschein'], 'von' => (string) $e['von'], 'notizen' => (string) ($e['notizen'] ?? ''),
                'messpunkt_id' => $mp,
                // hat eine Fassung ergeben (kalibriert)
                'fassung' => $fassungen !== [],
                'loeschbar' => array_filter($fassungen, static fn (int $id, int $m): bool => ($juengste[$m] ?? 0) !== $id, ARRAY_FILTER_USE_BOTH) === [],
                'ausgewertet' => $ausgewertet,
                // Betankung mit Sensor, noch nicht ausgewertet: ab wann (30 min nach dem Tanken)
                'auswerten_ab' => $e['art'] === 'betankung' && $mp !== null && !$ausgewertet ? Zeit::iso($ts + self::AUSWERTEN_NACH_S) : null,
                'auswerten_jetzt' => $e['art'] === 'betankung' && $mp !== null && !$ausgewertet && $jetzt >= $ts + self::AUSWERTEN_NACH_S,
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
        $eintragen = self::darfEintragen($tank);
        $sensor = self::sensor((int) $tank['id']);
        return [
            'sensor'     => $sensor === null ? null : [
                'kennung'   => (string) ($sensor['kennung'] ?? $sensor['bezeichnung']),
                'kurz'      => (string) $sensor['sensor_kurz'],
                'einsatz_id' => $darf && $sensor['einsatz_id'] !== null ? (int) $sensor['einsatz_id'] : null,
            ],
            // Für die Peilung in %: ohne Nennvolumen geht nur Liter oder Peilstab.
            'nenn_l'     => TankRechnung::zahlLesen(((array) $tank['eigenschaften'])['inhalt_l'] ?? null),
            'ereignisse' => array_map(static fn (array $e): array => ['art' => $e['art'], 'zeit' => Zeit::anzeige($e['zeit'], 'd.m. H:i'), 'text' => $e['text']],
                self::ereignisse($tank, 3)),
            'darf'       => ['peilen' => $eintragen, 'betanken' => $eintragen, 'einsetzen' => $eintragen && Auth::can('datenquellen.view')],
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

    /**
     * Darf der Betrachter am Tank eintragen – peilen, betanken, kalibrieren,
     * löschen, einen Sensor einbauen? „Anlagen ändern“ und *bearbeiten* am
     * Tank, und jetzt im Datenfenster: Was ab jetzt gilt (ein Ereignis, eine
     * Fassung), änderte sonst Werte, die er selbst nicht sehen darf.
     */
    public static function darfEintragen(array $tank): bool
    {
        return Zugriff::darfBearbeiten($tank) && Zugriff::liveErlaubt(Zugriff::datenFenster($tank));
    }

    /** Warum der Betrachter am Tank nichts eintragen darf – oder null. @return ?array{ok:false,message:string} */
    public static function eintragenVerweigert(array $tank): ?array
    {
        return match (true) {
            !Zugriff::darfBearbeiten($tank) => ['ok' => false, 'message' => 'An diesem Tank darfst du nichts eintragen.'],
            !self::darfEintragen($tank)     => ['ok' => false, 'message' => self::AUSSERHALB_FENSTER],
            default                         => null,
        };
    }

    /**
     * $arbeit unter der Sperre dieses Tanks: Peilen, Betanken, Auswerten,
     * Löschen und Kennlinie laufen je Tank nur einmal zur Zeit. Sie warten
     * auf die InfluxDB ohne Sitzungssperre (Influx::abfragenAlle()) – ein
     * Doppelklick oder ein zweiter Reiter liefe sonst nebeneinander und
     * trüge zweimal ein, legte zwei Fassungen an oder wertete eine Betankung
     * zweimal aus.
     *
     * Nicht blockierend (wie PartnerAbgleich::gesperrt()): Wer die Sperre
     * nicht bekommt, hört mit einer Meldung auf. Warten ginge nicht – die
     * zweite Anfrage hielte dabei die Sitzung, und die erste bekäme sie nach
     * der InfluxDB nie wieder. Die Datei in storage/cache gilt für diese
     * Datenbank; wer freigibt, löscht sie vorher, wer sie danach noch über
     * den alten Eintrag sperrt, merkt das am Inode.
     *
     * @param callable():array{ok:bool,message:string} $arbeit
     * @return array{ok:bool,message:string}
     */
    public static function gesperrt(int $tankId, callable $arbeit): array
    {
        $datenbank = [];
        foreach (['driver', 'path', 'host', 'port', 'database'] as $k) {
            $datenbank[$k] = Config::get('database.' . $k);
        }
        $pfad = dirname(__DIR__, 2) . '/storage/cache/tank-' . substr(hash('sha256', json_encode($datenbank) . '|' . $tankId), 0, 40) . '.sperre';
        if (!is_dir(dirname($pfad))) {
            @mkdir(dirname($pfad), 0775, true);
        }
        $griff = @fopen($pfad, 'c');
        if ($griff === false) {
            Logger::error('Tank: Sperrdatei ließ sich nicht anlegen: ' . $pfad);
            return ['ok' => false, 'message' => 'storage/cache ist nicht beschreibbar – nichts eingetragen.'];
        }
        $frei = flock($griff, LOCK_EX | LOCK_NB);
        if ($frei) {
            // Noch dieselbe Datei? Sonst hat sie der Vorgänger schon gelöscht.
            clearstatcache(true, $pfad);
            $jetzt = @stat($pfad);
            $meine = fstat($griff);
            $frei = $jetzt !== false && $meine !== false && $jetzt['ino'] === $meine['ino'] && $jetzt['dev'] === $meine['dev'];
            if (!$frei) {
                flock($griff, LOCK_UN);
            }
        }
        if (!$frei) {
            fclose($griff);
            return ['ok' => false, 'message' => self::GERADE_SCHON];
        }
        try {
            return $arbeit();
        } finally {
            @unlink($pfad);
            flock($griff, LOCK_UN);
            fclose($griff);
        }
    }

    // ------------------------------------------------------------ Kalibrieren vor Ort

    /**
     * Peilung vor Ort: genau eine Angabe – Anzeige am Tank (prozent), Inhalt
     * (liter) oder Peilstab (hoehe_mm). Zeit ist jetzt; den Rohwert nimmt der
     * Server (rohJetzt), nie der Browser.
     *
     * Die Peilung steht immer im Protokoll. Mit Rohwert und Kennlinie wird sie
     * zur Fassung „peilung“ (geprüft): Der Peilstab setzt den Anker, die
     * Steigung bleibt; Anzeige oder Inhalt setzen ihn auf die Höhe dieses
     * Inhalts – oder bestimmen, solange er unbekannt ist, den Inhalt je mm des
     * Tanks (der dann in den Eigenschaften des Tanks steht).
     *
     * @param array<string,mixed> $daten liter | prozent | hoehe_mm, notizen
     * @return array{ok:bool,message:string,id?:int,kalibriert?:bool,warnung?:bool}
     */
    public static function peilen(array $tank, array $daten): array
    {
        if (($nein = self::eintragenVerweigert($tank)) !== null) {
            return $nein;
        }
        $eig = (array) $tank['eigenschaften'];
        $nenn = TankRechnung::zahlLesen($eig['inhalt_l'] ?? null);
        $nenn = $nenn !== null && $nenn > 0 ? $nenn : null;
        $angaben = array_filter(array_intersect_key($daten, ['liter' => 1, 'prozent' => 1, 'hoehe_mm' => 1]),
            static fn (mixed $w): bool => is_scalar($w) && trim((string) $w) !== '');
        if (count($angaben) !== 1) {
            return ['ok' => false, 'message' => 'Bitte genau eine Angabe: Anzeige (%), Inhalt (l) oder Peilstab (mm).'];
        }
        $art = (string) array_key_first($angaben);
        $wert = TankRechnung::zahlLesen((string) $angaben[$art]);
        if ($wert === null || $wert < 0) {
            return ['ok' => false, 'message' => 'Die Peilung ist keine Zahl ab 0.'];
        }
        if ($art === 'prozent' && $nenn === null) {
            return ['ok' => false, 'message' => 'Für eine Anzeige in % fehlt das Nennvolumen des Tanks – Inhalt in Litern oder Peilstab eintragen.'];
        }
        if ($wert > match ($art) { 'prozent' => 110.0, 'liter' => $nenn !== null ? 1.1 * $nenn : 100000.0, default => 5000.0 }) {
            return ['ok' => false, 'message' => 'Die Peilung ist größer als der Tank.'];
        }

        $jetzt = gmdate('c');
        $m = self::sensor((int) $tank['id']);
        $roh = $m !== null ? self::rohJetzt($tank, $m) : null;
        $ereignis = [
            'objekt_id'    => (int) $tank['id'],
            'messpunkt_id' => $m !== null ? (int) $m['id'] : null,
            'art'          => 'peilung',
            'zeit'         => $jetzt,
            // Bei einer Anzeige in % steht der Inhalt daneben – er folgt aus dem Nennvolumen.
            'liter'        => match ($art) { 'liter' => round($wert, 3), 'prozent' => round($wert / 100 * (float) $nenn, 3), default => null },
            'prozent'      => $art === 'prozent' ? round($wert, 3) : null,
            'hoehe_mm'     => $art === 'hoehe_mm' ? round($wert, 3) : null,
            'roh'          => $roh !== null ? round($roh['roh'], 6) : null,
            'lieferschein' => '',
            'von'          => self::wer(),
            'notizen'      => self::notiz($daten['notizen'] ?? null),
            'created_at'   => $jetzt,
        ];
        $id = Database::insert('tank_ereignisse', $ereignis);
        Audit::log('tank.peilung', 'objekt', (string) $tank['id'], [$art => round($wert, 3)]);
        $angabe = self::angabeText($art, $wert);
        $was = ($art === 'hoehe_mm' ? 'Peilstab ' : 'Peilung ') . $angabe;
        $antwort = static fn (string $text, bool $kalibriert, bool $warnung = false): array
            => ['ok' => true, 'message' => $text, 'id' => $id, 'kalibriert' => $kalibriert, 'warnung' => $warnung];

        if ($m === null) {
            return $antwort($was . ' eingetragen.', false);
        }
        if ($roh === null) {
            return $antwort($was . ' eingetragen – der Sensor meldet keinen gültigen Wert, kalibriert wird nicht. '
                . 'Bitte noch einmal peilen, sobald er meldet.', false, true);
        }
        $f = self::fassungJetzt((int) $m['id']);
        if ($f === null) {
            return $antwort($was . ' eingetragen – ohne Kennlinie (Sensordaten unvollständig) wird nicht kalibriert.', false, true);
        }
        $r = $f['rechnung'];
        $rohText = self::rohText($roh, $r['feld']);
        // Ein Rohwert, den die Kennlinie als Fehler wertet (Leitungsbruch,
        // Totzone …), kalibriert nichts – die Peilung bleibt im Protokoll.
        $jetztWert = TankRechnung::umrechnen($r, $roh['roh']);
        if ($jetztWert['fehler'] !== null && $jetztWert['hoehe_mm'] === null && $jetztWert['liter'] === null && $r['sensor']['art'] === 'linear') {
            return $antwort($was . ' eingetragen. ' . $jetztWert['fehler'] . ' (' . $rohText . ') – Peilung nur protokolliert.', false, true);
        }
        $erg = TankRechnung::peilen($r, $roh['roh'], [$art => $wert]);
        if (isset($erg['fehler'])) {
            return $antwort($was . ' eingetragen. ' . $erg['fehler'], false, true);
        }
        if (!isset($erg['rechnung'])) {
            return $antwort($was . ' eingetragen. ' . (string) ($erg['hinweis'] ?? ''), false);
        }
        $neu = $erg['rechnung'];
        $neu['herkunft']['text'] = 'Peilung ' . Zeit::anzeige($jetzt, 'd.m.Y H:i') . ': ' . $angabe . ' bei ' . $rohText;
        self::fassungAnlegen($m, $neu, 'peilung', $jetzt, true, $id);
        $text = $was . ' eingetragen und kalibriert (' . $rohText . ').';
        if (isset($erg['liter_je_mm'])) {
            // Was die Peilung über den Tank lernt, gehört zum Tank – auch der nächste Sensor rechnet damit.
            AnlagenService::eigenschaftSetzen((int) $tank['id'], [
                'liter_je_mm'          => $erg['liter_je_mm'],
                'liter_je_mm_herkunft' => 'Peilung ' . Zeit::anzeige($jetzt, 'd.m.Y H:i') . ' (' . $angabe . ')',
            ], true);
            $text .= ' Inhalt je mm: ' . self::zahl(round($erg['liter_je_mm'], 3)) . ' l.';
        }
        if (self::steigungAngebot(AnlagenService::find((int) $tank['id']) ?? $tank) !== null) {
            $text .= ' Mit einer früheren Peilung lässt sich jetzt auch die Steigung setzen (Protokoll auf der Tankseite).';
        }
        return $antwort($text, true);
    }

    /**
     * Betankung eintragen: geliefert in Litern (laut Lieferschein), Ende des
     * Tankens (leer = jetzt, höchstens 7 Tage zurück), Lieferschein, Notiz.
     * Liegt das Ende mindestens 30 min zurück und soll gleich kalibriert
     * werden (Vorgabe), folgt betankungAuswerten(); sonst zeigt das Protokoll
     * den Knopf dafür.
     *
     * @param array<string,mixed> $daten liter, zeit, lieferschein, notizen, kalibrieren
     * @return array{ok:bool,message:string,id?:int,kalibriert?:bool,warnung?:bool}
     */
    public static function betankungSpeichern(array $tank, array $daten): array
    {
        if (($nein = self::eintragenVerweigert($tank)) !== null) {
            return $nein;
        }
        $nenn = TankRechnung::zahlLesen(((array) $tank['eigenschaften'])['inhalt_l'] ?? null);
        $nenn = $nenn !== null && $nenn > 0 ? $nenn : null;
        $liter = TankRechnung::zahlLesen(is_scalar($daten['liter'] ?? null) ? (string) $daten['liter'] : null);
        if ($liter === null || $liter <= 0) {
            return ['ok' => false, 'message' => 'Bitte die gelieferte Menge in Litern eintragen.'];
        }
        if ($liter > ($nenn !== null ? 1.5 * $nenn : 100000.0)) {
            return ['ok' => false, 'message' => $nenn !== null
                ? 'Mehr als das Anderthalbfache des Nennvolumens (' . self::liter($nenn) . ') – bitte die Menge prüfen.'
                : 'Mehr als 100.000 l – bitte die Menge prüfen.'];
        }
        $jetzt = time();
        $ts = self::zeitLesen($daten['zeit'] ?? null, $jetzt);
        if ($ts === null) {
            return ['ok' => false, 'message' => 'Das Ende des Tankens ist nicht lesbar.'];
        }
        if ($ts > $jetzt + 60) {
            return ['ok' => false, 'message' => 'Das Ende des Tankens liegt in der Zukunft.'];
        }
        if ($ts < $jetzt - self::BETANKUNG_ZURUECK_S) {
            return ['ok' => false, 'message' => 'Eine Betankung lässt sich höchstens 7 Tage zurück eintragen.'];
        }
        $ts = min($ts, $jetzt);
        // Nur im eigenen Datenfenster: Eine Betankung davor sähe man selbst
        // nicht, sie zählte aber für andere (Verbrauch je Tag, Protokoll).
        if (!self::zeitImFenster($ts, Zugriff::datenFenster($tank))) {
            return ['ok' => false, 'message' => 'Das Ende des Tankens liegt außerhalb deines freigegebenen Zeitraums.'];
        }
        $lieferschein = trim(is_scalar($daten['lieferschein'] ?? null) ? (string) $daten['lieferschein'] : '');
        if (mb_strlen($lieferschein) > 64) {
            return ['ok' => false, 'message' => 'Die Nummer des Lieferscheins hat höchstens 64 Zeichen.'];
        }
        $m = self::sensorZu((int) $tank['id'], $ts);
        $id = Database::insert('tank_ereignisse', [
            'objekt_id'    => (int) $tank['id'],
            'messpunkt_id' => $m !== null ? (int) $m['id'] : null,
            'art'          => 'betankung',
            'zeit'         => Zeit::iso($ts),
            'liter'        => round($liter, 3),
            'prozent'      => null, 'hoehe_mm' => null, 'roh' => null, 'roh_vor' => null, 'roh_nach' => null,
            'lieferschein' => $lieferschein,
            'von'          => self::wer(),
            'notizen'      => self::notiz($daten['notizen'] ?? null),
            'created_at'   => gmdate('c'),
        ]);
        Audit::log('tank.betankung', 'objekt', (string) $tank['id'], ['liter' => round($liter, 3), 'lieferschein' => $lieferschein]);
        $text = 'Betankung ' . self::liter($liter) . ' (Ende ' . Zeit::anzeige(Zeit::iso($ts), 'd.m. H:i') . ') eingetragen.';
        if ($m === null) {
            return ['ok' => true, 'message' => $text . ' Ohne Tanksensor steht sie nur im Protokoll.', 'id' => $id, 'kalibriert' => false];
        }
        if (array_key_exists('kalibrieren', $daten) && !$daten['kalibrieren']) {
            return ['ok' => true, 'message' => $text . ' Kalibrieren lässt sich daraus im Protokoll.', 'id' => $id, 'kalibriert' => false];
        }
        if ($jetzt < $ts + self::AUSWERTEN_NACH_S) {
            return ['ok' => true, 'message' => $text . ' Kalibrieren geht ab ' . self::uhrzeit($ts + self::AUSWERTEN_NACH_S, $jetzt)
                . ' – dann liegen Messwerte nach dem Tanken vor (Knopf im Protokoll).', 'id' => $id, 'kalibriert' => false];
        }
        $aus = self::betankungAuswerten($tank, $id, false);
        return ['ok' => true, 'message' => $text . ' ' . $aus['message'], 'id' => $id, 'kalibriert' => !empty($aus['kalibriert']), 'warnung' => !$aus['ok']];
    }

    /**
     * Kalibrieren aus einer Betankung: Im Fenster [Ende − 3 h, Ende + 1 h]
     * sucht TankRechnung::anstieg() den Sprung in den Rohwerten des Sensors,
     * der damals im Tank war (Median je Minute, im Datenfenster). Was ein
     * Generator am Schlauch währenddessen verbrauchte, geht von der
     * gelieferten Menge ab (verbrauchZwischen). Gelernt wird
     * der **Inhalt je mm des Tanks**: Er geht in die Eigenschaften des Tanks
     * und – als Fassung „betankung“ ab jetzt – in die Kennlinie des Sensors,
     * der jetzt misst; deren Sensorteil bleibt. Weicht der Wert um mehr als
     * 25 % vom bisherigen ab, nur mit $trotzdem.
     *
     * @return array{ok:bool,message:string,kalibriert?:bool,trotzdem?:bool}
     */
    public static function betankungAuswerten(array $tank, int $ereignisId, bool $trotzdem): array
    {
        if (($nein = self::eintragenVerweigert($tank)) !== null) {
            return $nein;
        }
        $e = self::ereignisFinden($tank, $ereignisId, 'betankung');
        if ($e === null) {
            return ['ok' => false, 'message' => 'Diese Betankung gibt es an diesem Tank nicht.'];
        }
        if ($e['roh_vor'] !== null && $e['roh_nach'] !== null) {
            return ['ok' => false, 'message' => 'Diese Betankung ist schon ausgewertet.'];
        }
        $ts = (int) Zeit::ts((string) $e['zeit']);
        $jetzt = time();
        if ($jetzt < $ts + self::AUSWERTEN_NACH_S) {
            return ['ok' => false, 'message' => 'Kalibrieren geht ab ' . self::uhrzeit($ts + self::AUSWERTEN_NACH_S, $jetzt) . ' – dann liegen Messwerte nach dem Tanken vor.'];
        }
        $m = $e['messpunkt_id'] !== null
            ? Database::first("SELECT * FROM messpunkte WHERE id = ? AND objekt_id = ? AND rolle = 'fuellstand'", [(int) $e['messpunkt_id'], (int) $tank['id']])
            : null;
        if ($m === null) {
            return ['ok' => false, 'message' => 'Zur Zeit der Betankung war kein Tanksensor im Tank – sie steht nur im Protokoll.'];
        }
        $damals = self::fassungJetzt((int) $m['id'], $ts);
        if ($damals === null) {
            return ['ok' => false, 'message' => 'Zur Zeit der Betankung galt keine Kennlinie – aus den Rohwerten allein lässt sich nichts lernen.'];
        }
        // Die Füllhöhe rechnet der Sensorteil von damals; verglichen wird mit dem
        // Inhalt je mm, den der Tank jetzt kennt.
        $r = TankRechnung::mitTank($damals['rechnung'], TankRechnung::tankTeil((array) $tank['eigenschaften']));
        $fehler = null;
        $reihe = Messdaten::reihe('zustand', (string) $m['geraet'], (string) $m['kanal'], (string) $r['feld'], [], $ts - 3 * 3600, $ts + 3600,
            MesspunktService::fenster($m, $tank), 60, 'median', $fehler);
        if ($fehler !== null) {
            Logger::info('Tank-Betankung auswerten: ' . $fehler);
            return ['ok' => false, 'message' => 'Die Messdaten ließen sich gerade nicht abfragen – bitte später noch einmal.'];
        }
        $punkte = [];
        foreach ($reihe['zeiten'] as $i => $ms) {
            $w = $reihe['serien'][$r['feld']][$i] ?? null;
            if ($w !== null) {
                $punkte[] = [(int) $ms, (float) $w];
            }
        }
        $sprung = TankRechnung::anstieg($punkte, $r);
        if ($sprung === null) {
            return ['ok' => false, 'message' => 'Kein deutlicher Anstieg um diese Zeit in den Messwerten – stimmt das Ende des Tankens?'];
        }
        // Was die Generatoren am Schlauch zwischen den beiden Medianen (je etwa
        // 10 min vor und nach dem Sprung) verbraucht haben, kam nie im Tank an.
        $verbrauch = self::verbrauchZwischen($tank, intdiv($sprung['t_vor'], 1000) - 600, intdiv($sprung['t_nach'], 1000) + 600);
        $erg = TankRechnung::betanken($r, $sprung['roh_vor'], $sprung['roh_nach'], (float) $e['liter'] - (float) ($verbrauch['liter'] ?? 0.0), $trotzdem);
        if (isset($erg['fehler'])) {
            return ['ok' => false, 'message' => $erg['fehler'], 'trotzdem' => isset($erg['abweichung']) && !isset($erg['rechnung'])];
        }
        // Rohwerte davor und danach ins Protokoll – die Betankung ist damit ausgewertet.
        Database::execute('UPDATE tank_ereignisse SET roh_vor = ?, roh_nach = ? WHERE id = ?',
            [round($sprung['roh_vor'], 6), round($sprung['roh_nach'], 6), $ereignisId]);
        $einheit = TankRechnung::ROHFELDER[$r['feld']][1] ?? '';
        $sprungText = 'Sprung ' . self::zahl(round($sprung['roh_vor'], 3)) . ' → ' . self::zahl(round($sprung['roh_nach'], 3)) . ($einheit !== '' ? ' ' . $einheit : '');
        if (!isset($erg['liter_je_mm'])) {
            return ['ok' => true, 'message' => 'Ausgewertet (' . $sprungText . '): ' . (string) ($erg['hinweis'] ?? ''), 'kalibriert' => false];
        }
        $wann = Zeit::anzeige((string) $e['zeit'], 'd.m.Y H:i');
        AnlagenService::eigenschaftSetzen((int) $tank['id'], [
            'liter_je_mm'          => $erg['liter_je_mm'],
            'liter_je_mm_herkunft' => 'Betankung ' . $wann . ' (' . self::liter((float) $e['liter']) . ')',
        ], true);
        // Der Sensor, der jetzt misst, rechnet ab jetzt mit dem neuen Inhalt je mm.
        $tankNeu = AnlagenService::find((int) $tank['id']) ?? $tank;
        $lauf = self::sensor((int) $tank['id']);
        $f = $lauf !== null ? self::fassungJetzt((int) $lauf['id']) : null;
        if ($f !== null) {
            $neu = TankRechnung::mitTank($f['rechnung'], TankRechnung::tankTeil((array) $tankNeu['eigenschaften']));
            $neu['herkunft']['text'] = 'Betankung ' . $wann . ', ' . self::liter((float) $e['liter']) . ' laut Lieferschein'
                . ((string) $e['lieferschein'] !== '' ? ' ' . $e['lieferschein'] : '');
            if (TankRechnung::pruefen($neu) === null) {
                // Die Betankung lernt den Inhalt je mm, nicht die Einbautiefe –
                // „geprüft“ bleibt, wie es war (nach dem Einbau: erst peilen).
                self::fassungAnlegen($lauf, $neu, 'betankung', gmdate('c'), $f['geprueft'], $ereignisId);
            }
        }
        $abweichung = $erg['abweichung'] ?? null;
        return ['ok' => true, 'kalibriert' => true, 'message' => 'Kalibriert: ' . self::zahl(round($erg['liter_je_mm'], 3)) . ' l je mm ('
            . $sprungText . ($abweichung !== null ? ', ' . ($abweichung > 0 ? '+' : '') . self::zahl($abweichung) . ' % gegenüber bisher' : '') . ').'
            . match (true) {
                $verbrauch['liter'] === null => ' Der Verbrauch während des Tankens ist nicht abgezogen (' . $verbrauch['text'] . ').',
                $verbrauch['liter'] > 0      => ' Abgezogen: ' . self::liter($verbrauch['liter']) . ' Verbrauch während des Tankens (' . $verbrauch['text'] . ').',
                default                      => '',
            }];
    }

    /**
     * Eine Peilung oder Betankung löschen.
     *
     * Ohne eigene Fassung ist sie einfach weg. Hat sie kalibriert und ist ihre
     * Fassung noch die jüngste ihres Messpunkts, gilt ab deren Beginn eine
     * Fassung „zurueck“ – eine Kopie der vorigen –, und der Inhalt je mm des
     * Tanks geht auf den Stand davor. Wurde danach schon neu kalibriert, wird
     * abgelehnt: Die Regel „größte id gilt“ überdeckte sonst Späteres.
     *
     * @return array{ok:bool,message:string}
     */
    public static function ereignisLoeschen(array $tank, int $ereignisId): array
    {
        if (($nein = self::eintragenVerweigert($tank)) !== null) {
            return $nein;
        }
        $e = self::ereignisFinden($tank, $ereignisId);
        if ($e === null) {
            return ['ok' => false, 'message' => 'Diesen Eintrag gibt es an diesem Tank nicht.'];
        }
        $eintrag = ['art' => (string) $e['art'], 'liter' => $e['liter'] !== null ? (float) $e['liter'] : null, 'prozent' => $e['prozent'] !== null ? (float) $e['prozent'] : null,
            'hoehe_mm' => $e['hoehe_mm'] !== null ? (float) $e['hoehe_mm'] : null, 'lieferschein' => (string) $e['lieferschein']];
        $was = self::ereignisText($eintrag) . ' vom ' . Zeit::anzeige((string) $e['zeit'], 'd.m.Y H:i');
        // Erst alles prüfen, dann schreiben.
        $plan = [];
        $eigene = [];
        foreach (Database::select('SELECT id, messpunkt_id FROM tank_kennlinien WHERE ereignis_id = ? AND objekt_id = ? ORDER BY id', [$ereignisId, (int) $tank['id']]) as $f) {
            $eigene[(int) $f['messpunkt_id']][] = (int) $f['id'];
        }
        foreach ($eigene as $mp => $ids) {
            if ((int) Database::scalar('SELECT MAX(id) FROM tank_kennlinien WHERE messpunkt_id = ?', [$mp]) !== max($ids)) {
                return ['ok' => false, 'message' => 'Danach wurde schon neu kalibriert – über „Erweitert“ korrigieren.'];
            }
            $messpunkt = Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]);
            if ($messpunkt === null) {
                continue;
            }
            // Die vorige gilt wieder: die mit der größten id vor der ersten dieses Ereignisses.
            $alle = array_column(self::fassungen($mp), null, 'id');
            $vorige = null;
            foreach ($alle as $fid => $f) {
                if ($fid < min($ids)) {
                    $vorige = $f;
                }
            }
            $erste = $alle[min($ids)] ?? null;
            $letzte = $alle[max($ids)] ?? null;
            if ($vorige === null || $erste === null || $letzte === null) {
                return ['ok' => false, 'message' => 'Vor dieser Kalibrierung galt keine Kennlinie – über „Erweitert“ korrigieren.'];
            }
            $plan[] = [$messpunkt, $vorige, $erste['ab'], $letzte];
        }
        foreach ($plan as [$messpunkt, $vorige, $ab, $letzte]) {
            $r = $vorige['rechnung'];
            $r['herkunft']['text'] = 'Zurück auf den Stand vor ' . $was . ' (gelöscht)';
            if (TankRechnung::pruefen($r) !== null) {
                $r = $vorige['rechnung'];
            }
            // Die Kopie stammt aus demselben Ereignis wie die vorige – so lässt
            // sich auch jenes später noch löschen.
            self::fassungAnlegen($messpunkt, $r, 'zurueck', $ab, $vorige['geprueft'], $vorige['ereignis_id'], '');
            // Hat die Kalibrierung den Inhalt je mm des Tanks gelernt, gilt wieder der vorige.
            $vorher = $vorige['rechnung']['tank']['liter_je_mm'] ?? null;
            if (($letzte['rechnung']['tank']['liter_je_mm'] ?? null) != $vorher) {
                AnlagenService::eigenschaftSetzen((int) $tank['id'], [
                    'liter_je_mm'          => $vorher,
                    'liter_je_mm_herkunft' => $vorher !== null ? 'wie vor ' . mb_substr($was, 0, 150) : null,
                ], true);
            }
        }
        Database::execute('DELETE FROM tank_ereignisse WHERE id = ?', [$ereignisId]);
        Audit::log('tank.ereignis_geloescht', 'objekt', (string) $tank['id'], ['art' => (string) $e['art']]);
        return ['ok' => true, 'message' => $was . ' gelöscht.' . ($plan !== [] ? ' Die Kennlinie rechnet wieder wie davor.' : '')];
    }

    /**
     * „Erweitert“: Anker und Steigung des laufenden Tanksensors von Hand – ab
     * jetzt (Fassung „hand“) oder, nur mit unbegrenztem Datenfenster,
     * rückwirkend für diesen Einbau (Fassung „rueckwirkend“ ab dem Einbau,
     * im Audit vermerkt). Nur roh_anker, mm_anker und mm_je_roh; was sonst
     * kommt, wird abgelehnt, ebenso alles, was pruefen() nicht besteht.
     *
     * @param array<string,mixed> $daten messpunkt (der laufende), sensor {roh_anker, mm_anker, mm_je_roh}, notizen
     * @return array{ok:bool,message:string}
     */
    public static function sensorVonHand(array $tank, array $daten, bool $rueckwirkend): array
    {
        if (($nein = self::eintragenVerweigert($tank)) !== null) {
            return $nein;
        }
        $m = self::sensor((int) $tank['id']);
        if ($m === null) {
            return ['ok' => false, 'message' => 'In diesem Tank misst gerade kein Tanksensor.'];
        }
        if ((int) ($daten['messpunkt'] ?? 0) !== (int) $m['id']) {
            return ['ok' => false, 'message' => 'Der Tanksensor hat inzwischen gewechselt – bitte die Seite neu laden.'];
        }
        if ($rueckwirkend && Zugriff::datenFenster($tank) !== null) {
            return ['ok' => false, 'message' => 'Rückwirkend ändern darf nur, wer alle Messdaten des Tanks sehen darf.'];
        }
        $f = self::fassungJetzt((int) $m['id']);
        if ($f === null) {
            return ['ok' => false, 'message' => 'Ohne Kennlinie gibt es nichts zu korrigieren – erst die Sensordaten am Tanksensor nachtragen.'];
        }
        if (!in_array($f['rechnung']['sensor']['art'], ['linear', 'roh'], true)) {
            return ['ok' => false, 'message' => 'Das Gerät liefert den Füllstand selbst – Anker und Steigung gibt es dafür nicht.'];
        }
        $eingabe = $daten['sensor'] ?? null;
        if (!is_array($eingabe) || array_diff(array_keys($eingabe), ['roh_anker', 'mm_anker', 'mm_je_roh']) !== []) {
            return ['ok' => false, 'message' => 'Von Hand gehen nur Anker (Rohwert und Füllhöhe) und Steigung.'];
        }
        $werte = [];
        foreach (['roh_anker' => 1e6, 'mm_anker' => 10000.0, 'mm_je_roh' => 1e6] as $k => $grenze) {
            $w = TankRechnung::zahlLesen(is_scalar($eingabe[$k] ?? null) ? (string) $eingabe[$k] : null);
            if ($w === null || abs($w) > $grenze) {
                return ['ok' => false, 'message' => 'Anker und Steigung: bitte endliche Zahlen in sinnvoller Größe eintragen.'];
            }
            $werte[$k] = round($w, 6);
        }
        if ($werte['mm_je_roh'] == 0.0) {
            return ['ok' => false, 'message' => 'Die Steigung ist nicht 0.'];
        }
        if ($werte['mm_anker'] < -1000) {
            return ['ok' => false, 'message' => 'Die Füllhöhe am Anker liegt weit unter dem Boden.'];
        }
        $r = $f['rechnung'];
        $r['sensor'] = array_merge($r['sensor'], ['art' => 'linear'], $werte);
        $jetzt = gmdate('c');
        $r['herkunft']['text'] = 'Von Hand ' . Zeit::anzeige($jetzt, 'd.m.Y H:i') . ($rueckwirkend ? ', rückwirkend für diesen Einbau' : '');
        $fehler = TankRechnung::pruefen($r);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        if ($r['sensor'] == $f['rechnung']['sensor'] && !$rueckwirkend) {
            return ['ok' => true, 'message' => 'Anker und Steigung sind unverändert – keine neue Fassung.'];
        }
        $ab = $rueckwirkend ? (string) $m['gueltig_ab'] : $jetzt;
        self::fassungAnlegen($m, $r, $rueckwirkend ? 'rueckwirkend' : 'hand', $ab, false, null, self::notiz($daten['notizen'] ?? null));
        return ['ok' => true, 'message' => $rueckwirkend
            ? 'Kennlinie von Hand gesetzt – rückwirkend ab dem Einbau (' . Zeit::anzeige($ab, 'd.m.Y H:i') . '). Ungeprüft, bis gepeilt ist.'
            : 'Kennlinie von Hand gesetzt – ab jetzt, ungeprüft, bis gepeilt ist.'];
    }

    /**
     * Zwei Peilungen im selben Einbau, mindestens 300 mm Füllhöhe
     * auseinander: das Angebot „Steigung aus beiden Peilungen setzen“ – nach
     * der zweiten, nie automatisch (TankRechnung::zweiPeilungen). Es gilt
     * nur, solange die zweite Peilung die jüngste Kalibrierung ist. Zählen
     * nur Peilungen mit Rohwert: mit dem Peilstab, oder mit Inhalt bzw.
     * Anzeige, wenn der Inhalt je mm schon vorher bekannt war (sonst hätte
     * diese Peilung ihn erst bestimmt).
     *
     * @return ?array{ereignis:int,alt:array{roh:float,hoehe_mm:float,zeit:string},neu:array{roh:float,hoehe_mm:float,zeit:string},mm_je_roh:float,bisher:float,abweichung:?float,warnung:?string,einheit:string,rechnung:array}
     */
    public static function steigungAngebot(array $tank): ?array
    {
        $m = self::sensor((int) $tank['id']);
        $f = $m !== null ? self::fassungJetzt((int) $m['id']) : null;
        if ($f === null || $f['anlass'] !== 'peilung' || $f['ereignis_id'] === null || $f['rechnung']['sensor']['art'] !== 'linear') {
            return null;
        }
        $neuE = self::ereignisFinden($tank, (int) $f['ereignis_id'], 'peilung');
        if ($neuE === null || $neuE['roh'] === null || (int) ($neuE['messpunkt_id'] ?? 0) !== (int) $m['id']) {
            return null;
        }
        $alle = self::fassungen((int) $m['id']);
        $neu = self::peilPunkt($neuE, $alle, $f['rechnung']['tank']);
        if ($neu === null) {
            return null;
        }
        $neuTs = (int) Zeit::ts((string) $neuE['zeit']);
        $einbau = (int) Zeit::ts((string) $m['gueltig_ab']);
        $fenster = Zugriff::datenFenster($tank);
        $sensor = self::sensordaten($m['sensordaten'] ?? null);
        $bezug = null;
        if (TankRechnung::sensorPruefen($sensor) === null) {
            [$teil] = TankRechnung::sensorTeil($sensor, (array) ($f['rechnung']['herkunft']['einbau'] ?? []), TankRechnung::dichte((array) $tank['eigenschaften']));
            $bezug = $teil['art'] === 'linear' ? (float) $teil['mm_je_roh'] : null;
        }
        foreach (Database::select("SELECT * FROM tank_ereignisse WHERE objekt_id = ? AND messpunkt_id = ? AND art = 'peilung' AND roh IS NOT NULL AND id <> ?
                                   ORDER BY zeit DESC, id DESC LIMIT 50", [(int) $tank['id'], (int) $m['id'], (int) $neuE['id']]) as $altE) {
            $ts = Zeit::ts((string) $altE['zeit']);
            if ($ts === null || $ts > $neuTs || $ts < $einbau || !self::zeitImFenster($ts, $fenster)) {
                continue;
            }
            // Derselbe Aufbau: gleiche Sensordaten, gleicher Einbau, gleiche Dichte – sonst passen die Rohwerte nicht zusammen.
            $damals = self::fassungJetzt((int) $m['id'], $ts);
            if ($damals === null || !self::gleicherAufbau($damals['rechnung'], $f['rechnung'])) {
                continue;
            }
            $alt = self::peilPunkt($altE, $alle, $f['rechnung']['tank']);
            if ($alt === null) {
                continue;
            }
            $zp = TankRechnung::zweiPeilungen($f['rechnung'], $alt, $neu, $bezug);
            if ($zp === null || isset($zp['fehler'])) {
                continue;
            }
            if (abs((float) $zp['mm_je_roh'] - (float) $f['rechnung']['sensor']['mm_je_roh']) < 1e-6) {
                return null;    // schon gesetzt
            }
            return [
                'ereignis'   => (int) $neuE['id'],
                'alt'        => $alt + ['zeit' => (string) $altE['zeit']],
                'neu'        => $neu + ['zeit' => (string) $neuE['zeit']],
                'mm_je_roh'  => (float) $zp['mm_je_roh'],
                'bisher'     => (float) $f['rechnung']['sensor']['mm_je_roh'],
                'abweichung' => $zp['abweichung'],
                'warnung'    => $zp['warnung'],
                'einheit'    => TankRechnung::ROHFELDER[$f['rechnung']['feld']][1] ?? '',
                'rechnung'   => $zp['rechnung'],
            ];
        }
        return null;
    }

    /**
     * Das Angebot übernehmen: Fassung „peilung“ ab jetzt mit der Steigung aus
     * beiden Peilungen und dem Anker der jüngeren, geprüft.
     *
     * @return array{ok:bool,message:string}
     */
    public static function steigungSetzen(array $tank, int $ereignisId): array
    {
        if (($nein = self::eintragenVerweigert($tank)) !== null) {
            return $nein;
        }
        $a = self::steigungAngebot($tank);
        $m = self::sensor((int) $tank['id']);
        if ($a === null || $m === null || $a['ereignis'] !== $ereignisId) {
            return ['ok' => false, 'message' => 'Dafür gibt es gerade kein Angebot – inzwischen wurde neu gepeilt oder kalibriert.'];
        }
        $r = $a['rechnung'];
        $r['herkunft']['text'] = 'Steigung aus den Peilungen ' . Zeit::anzeige($a['alt']['zeit'], 'd.m.Y H:i') . ' und ' . Zeit::anzeige($a['neu']['zeit'], 'd.m.Y H:i');
        self::fassungAnlegen($m, $r, 'peilung', gmdate('c'), true, $ereignisId);
        return ['ok' => true, 'warnung' => $a['warnung'] !== null, 'message' => 'Steigung gesetzt: ' . self::zahl(round($a['mm_je_roh'], 2)) . ' mm je '
            . ($a['einheit'] !== '' ? $a['einheit'] : 'Rohwert') . ' (bisher ' . self::zahl(round($a['bisher'], 2)) . ').' . ($a['warnung'] !== null ? ' ' . $a['warnung'] : '')];
    }

    /** Ein Ereignis des Tanks – nur, wenn es im Datenfenster liegt. */
    private static function ereignisFinden(array $tank, int $id, ?string $art = null): ?array
    {
        $e = Database::first('SELECT * FROM tank_ereignisse WHERE id = ? AND objekt_id = ?', [$id, (int) $tank['id']]);
        $ts = $e !== null ? Zeit::ts((string) $e['zeit']) : null;
        if ($e === null || $ts === null || ($art !== null && $e['art'] !== $art) || !self::zeitImFenster($ts, Zugriff::datenFenster($tank))) {
            return null;
        }
        return $e;
    }

    /** Der Füllstand-Messpunkt, der zur Zeit $ts im Tank gemessen hat (der erste nach id). */
    private static function sensorZu(int $tankId, int $ts): ?array
    {
        foreach (Database::select("SELECT * FROM messpunkte WHERE objekt_id = ? AND rolle = 'fuellstand' ORDER BY id", [$tankId]) as $m) {
            $ab = Zeit::ts($m['gueltig_ab'] ?? null);
            $bis = Zeit::ts($m['gueltig_bis'] ?? null);
            if (($ab === null || $ab <= $ts) && ($bis === null || $bis > $ts)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Rohwert und Füllhöhe einer Peilung für zwei Peilungen – oder null. Der
     * Peilstab zählt immer; Inhalt oder Anzeige nur, wenn vor dieser Peilung
     * der Inhalt je mm schon bekannt war.
     *
     * @param list<array<string,mixed>> $fassungen des Messpunkts
     * @return ?array{roh:float,hoehe_mm:float}
     */
    private static function peilPunkt(array $e, array $fassungen, array $tankTeil): ?array
    {
        if ($e['hoehe_mm'] !== null) {
            return ['roh' => (float) $e['roh'], 'hoehe_mm' => (float) $e['hoehe_mm']];
        }
        if ($e['liter'] === null) {
            return null;
        }
        $ts = (int) Zeit::ts((string) $e['zeit']);
        $vorher = null;
        foreach ($fassungen as $f) {
            if ($f['ab_ts'] <= $ts && $f['ereignis_id'] !== (int) $e['id'] && ($vorher === null || $f['id'] > $vorher['id'])) {
                $vorher = $f;
            }
        }
        if ($vorher === null || !TankRechnung::kalibriert($vorher['rechnung']) || ($vorher['rechnung']['tank']['form'] ?? '') === 'unbekannt') {
            return null;
        }
        $h = TankRechnung::hoeheAusLiter($tankTeil, (float) $e['liter']);
        return $h !== null ? ['roh' => (float) $e['roh'], 'hoehe_mm' => $h] : null;
    }

    /** Messen zwei Fassungen mit demselben Aufbau – Feld, Sensordaten, Einbau, Dichte? */
    private static function gleicherAufbau(array $a, array $b): bool
    {
        $aufbau = static fn (array $r): array => [$r['feld'] ?? null, $r['herkunft']['sensor'] ?? null, $r['herkunft']['einbau'] ?? null,
            $r['herkunft']['tank']['dichte_kg_l'] ?? null];
        return $aufbau($a) == $aufbau($b);
    }

    /**
     * Ende des Tankens aus dem Formular: leer = jetzt, „08:10“ = heute (lag
     * die Uhrzeit heute noch nicht, gestern), sonst Datum und Uhrzeit in
     * Ortszeit. Null, wenn nicht lesbar.
     */
    private static function zeitLesen(mixed $wert, int $jetzt): ?int
    {
        $text = is_scalar($wert) ? trim((string) $wert) : '';
        if ($text === '' || $text === 'jetzt') {
            return $jetzt;
        }
        if (preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $text, $t) === 1) {
            $heute = (new \DateTimeImmutable('@' . $jetzt))->setTimezone(Zeit::zone())->setTime((int) $t[1], (int) $t[2]);
            return $heute->getTimestamp() > $jetzt + 60 ? $heute->modify('-1 day')->getTimestamp() : $heute->getTimestamp();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $text) !== 1) {
            return null;
        }
        return Zeit::ts(Zeit::ausEingabe($text));
    }

    /** „14:02“ heute, sonst „15.10. 14:02“ – in Ortszeit. */
    private static function uhrzeit(int $ts, int $jetzt): string
    {
        $zone = Zeit::zone();
        $tag = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Y-m-d');
        return Zeit::anzeige(Zeit::iso($ts), $tag($ts) === $tag($jetzt) ? 'H:i' : 'd.m. H:i');
    }

    /** „Rohwert 7,5 V von 14:02“. */
    private static function rohText(array $roh, string $feld): string
    {
        $einheit = TankRechnung::ROHFELDER[$feld][1] ?? '';
        return 'Rohwert ' . self::zahl(round((float) $roh['roh'], 3)) . ($einheit !== '' ? ' ' . $einheit : '')
            . ' von ' . self::uhrzeit(intdiv((int) $roh['zeit'], 1000), time());
    }

    /** „60 %“, „1.800 l“, „650 mm“. */
    private static function angabeText(string $art, float $wert): string
    {
        return match ($art) {
            'prozent' => self::zahl(round($wert, 1)) . ' %',
            'liter'   => self::liter($wert),
            default   => self::zahl(round($wert)) . ' mm',
        };
    }

    private static function wer(): string
    {
        return mb_substr((string) (Auth::user()['username'] ?? 'system'), 0, 64);
    }

    private static function notiz(mixed $text): string
    {
        return mb_substr(trim(is_scalar($text) ? (string) $text : ''), 0, 500);
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
        // Die Reihenfolge vor Ort: erst einbauen (jetzt), dann die Sonde einhängen, dann peilen – Rohwerte zählen erst ab dem Einbau.
        return trim('Kennlinie ab dem Einbau angelegt. Jetzt die Sonde einhängen und peilen, sobald der Sensor meldet. ' . implode(' ', $erg['hinweise']));
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
     * Verglichen wird, was die alten und die neuen Sensordaten mit diesem
     * Einbau rechnen – nicht die jetzige Fassung, die eine Peilung schon
     * verschoben hat. Ändert sich daran nichts (nur der Hinweistext), gibt es
     * keine neue Fassung; ändert sich nur der Meldeschritt, rechnet die
     * jetzige Fassung mit ihm weiter – Anker, Steigung und „geprüft“ bleiben.
     *
     * @param array<string,mixed> $vorher die Sensordaten vor dem Speichern
     * @return string Hinweis für die Meldung
     */
    public static function sensorGeaendert(int $inventarId, array $vorher = []): string
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
        $nurSchritt = 0;
        $hinweise = [];
        $vorher = self::sensordaten($vorher);
        foreach (Database::select("SELECT * FROM messpunkte WHERE einsatz_id = ? AND rolle = 'fuellstand' AND gueltig_bis IS NULL ORDER BY id", [(int) $einsatz['id']]) as $m) {
            $f = self::fassungJetzt((int) $m['id']);
            $einbau = (array) ($f['rechnung']['herkunft']['einbau'] ?? []);
            $erg = TankRechnung::rechnung($sensor, $einbau, (array) $tank['eigenschaften'], self::herkunft($item, $tank,
                'Sensordaten geändert ' . Zeit::anzeige($jetzt, 'd.m.Y H:i')));
            $r = $erg['rechnung'];
            if ($f !== null) {
                $alt = $f['rechnung'];
                // Was rechneten die alten Sensordaten mit diesem Einbau? (Ohne
                // gültige alte Sensordaten: der Vergleich mit der Fassung.)
                $bisher = $vorher !== [] && TankRechnung::sensorPruefen($vorher) === null
                    ? TankRechnung::rechnung($vorher, $einbau, (array) $tank['eigenschaften'], self::herkunft($item, $tank, ''))['rechnung'] : $alt;
                $aufbau = static fn (array $x): array => [$x['feld'], array_diff_key($x['sensor'], ['schritt_roh' => 1]),
                    array_diff_key((array) ($x['herkunft']['sensor'] ?? []), ['kennung' => 1])];
                if ($aufbau($bisher) == $aufbau($r)) {
                    if ((float) $bisher['sensor']['schritt_roh'] === (float) $r['sensor']['schritt_roh']
                        || (float) $alt['sensor']['schritt_roh'] === (float) $r['sensor']['schritt_roh']) {
                        continue;    // nur der Hinweistext o. Ä. – an der Rechnung ändert sich nichts
                    }
                    // Nur der Meldeschritt (report_thr): die jetzige Fassung mit ihm – Anker, Steigung und „geprüft“ bleiben.
                    $neu = $alt;
                    $neu['sensor']['schritt_roh'] = $r['sensor']['schritt_roh'];
                    $neu['herkunft']['text'] = 'Meldeschritt geändert ' . Zeit::anzeige($jetzt, 'd.m.Y H:i');
                    if (TankRechnung::pruefen($neu) === null) {
                        self::fassungAnlegen($m, $neu, 'sensor', $jetzt, $f['geprueft'], $f['ereignis_id']);
                        $nurSchritt++;
                    }
                    continue;
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
        return match (true) {
            $angelegt > 0   => trim('Kennlinie ab jetzt mit den neuen Sensordaten – eine frühere Peilung gilt dafür nicht, bitte neu peilen. '
                . implode(' ', array_unique($hinweise))),
            $nurSchritt > 0 => 'Die Kennlinie rechnet ab jetzt mit dem neuen Meldeschritt – Anker und Steigung bleiben.',
            default         => '',
        };
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
