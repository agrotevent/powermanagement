<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Support\Audit;
use App\Support\Database;

/**
 * Katalog-Import: viele Standard-Verteiler, Aggregate und Tanks aus einer Datei.
 *
 * Ein Paket bündelt Dateien im Format des Verteiler-Abgleichs
 * (VerteilerBundle, `verteiler-typ`) – je Typ Nennwerte, Eigenschaften,
 * Schutzorgane, Anschlüsse und die Steckertypen, die er benutzt (ein Tank
 * nur Eigenschaften):
 *
 *   { format: 1, art: "verteiler-typen", quelle: "BSS Strombibel (Stand 18.03.2025)",
 *     typen: [ {format: 1, art: "verteiler-typ", schluessel, typ: {…}, steckertypen: […]}, … ] }
 *
 * Eine einzelne `verteiler-typ`-Datei gilt als Paket mit einem Typ.
 * Mitgelieferte Pakete liegen unter resources/kataloge/*.json.
 *
 * Erst wird gelesen und verglichen (lesen): je Typ, ob er hier neu ist,
 * genauso schon dasteht, hier anders dasteht – etwa weil jemand einen Fehler
 * der Vorlage von Hand korrigiert hat – oder ob die Datei für ihn nicht
 * taugt. Übernommen wird nur, was ausgewählt ist (uebernehmen): neue Typen
 * und ausdrücklich zum Überschreiben gewählte. **Ein Import überschreibt nie
 * still eine Korrektur.**
 *
 * Danach sind die Typen gewöhnliche Katalog-Einträge: bearbeitbar wie jeder
 * andere, kopierbar, löschbar. Wiedererkannt werden sie beim nächsten Import
 * an ihrem Schlüssel (sync_key) – demselben, über den auch der
 * Verteiler-Abgleich sie findet.
 */
final class KatalogImport
{
    public const FORMAT = 1;
    public const ART = 'verteiler-typen';
    public const HOECHSTENS = 500;
    public const GROESSE = 5 * 1024 * 1024;

    /** So lange liegt eine Vorschau bereit (Sekunden). */
    public const VORSCHAU_DAUER = 3600;

    /** Zustand => [Anzeige, Klasse des Abzeichens] */
    public const ZUSTAENDE = [
        'neu'    => ['neu', 'info'],
        'gleich' => ['schon da', 'ok'],
        'anders' => ['hier anders', 'warn'],
        'fehler' => ['Fehler', 'bad'],
    ];

    /** Höchstens so viele Anschlüsse bzw. Schutzorgane je Typ – mehr hat kein Verteiler. */
    public const HOECHSTENS_JE_TYP = 400;

    /** Felder des Typs, die einzeln verglichen werden – in dieser Reihenfolge. */
    private const EINFACH = ['art', 'fest', 'name', 'hersteller', 'modell', 'nennleistung_kva', 'nennstrom_a',
        'spannung_v', 'phasen', 'steuerung_gesperrt', 'notizen'];

    // ------------------------------------------------------------ Rechte

    /**
     * Was der angemeldete Benutzer darf: den Katalog importieren
     * („Anlagen ändern“ und alle Standorte) und neue Steckertypen anlegen.
     *
     * @return array{katalog:bool,stecker:bool}
     */
    public static function erlaubt(): array
    {
        return [
            'katalog' => Auth::can('anlagen.manage') && Zugriff::alles(),
            'stecker' => Auth::can('stecker.manage'),
        ];
    }

    // ------------------------------------------------------------ Lesen

    /**
     * Ein Paket lesen und mit dem Katalog hier vergleichen.
     *
     * @return array{ok:bool,message:string,paket:?array,quelle:string,typen:list<array<string,mixed>>,
     *               steckertypen:list<array{schluessel:string,name:string,typen:list<string>,strom_a:float,phasen:int,spannung_v:int}>,
     *               zahlen:array<string,int>}
     */
    public static function lesen(string $json): array
    {
        if (strlen($json) > self::GROESSE) {
            return self::abgelehnt('Die Datei ist größer als 5 MB.');
        }
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }
        if (str_starts_with($json, '%PDF')) {
            return self::abgelehnt('Das ist ein PDF. Importiert wird ein Katalog-Paket (JSON), wie es aus einem Dokument wie der Strombibel erstellt wird.');
        }
        if (trim($json) === '') {
            return self::abgelehnt('Die Datei ist leer.');
        }
        try {
            $daten = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::abgelehnt('Die Datei ist kein gültiges JSON – erwartet wird ein Katalog-Paket („verteiler-typen“).');
        }
        return self::pruefen($daten);
    }

    /**
     * Wie lesen(), für ein schon entschlüsseltes Paket – etwa das aus der
     * Vorschau, das vor dem Übernehmen noch einmal verglichen wird.
     */
    public static function pruefen(mixed $daten): array
    {
        if (!is_array($daten) || ($daten !== [] && array_is_list($daten))) {
            return self::abgelehnt('Die Datei enthält kein Katalog-Paket.');
        }
        $art = $daten['art'] ?? null;
        if ($art === VerteilerBundle::BEREICHE['typ']['art']) {
            // Eine einzelne Datei, etwa aus verteiler/typen/ des Repositorys.
            $daten = ['format' => self::FORMAT, 'art' => self::ART,
                'quelle' => is_string($daten['von'] ?? null) ? $daten['von'] : '', 'typen' => [$daten]];
        } elseif ($art !== self::ART) {
            return self::abgelehnt('Die Datei ist kein Katalog-Paket: „art“ ist '
                . (is_scalar($art) ? '„' . mb_substr((string) $art, 0, 40) . '“' : 'nicht angegeben')
                . ', erwartet wird „' . self::ART . '“ (oder eine einzelne „verteiler-typ“-Datei).');
        }
        $format = $daten['format'] ?? null;
        if (!is_int($format) || $format < 1) {
            return self::abgelehnt('Dem Paket fehlt die Angabe „format“ (1).');
        }
        if ($format > self::FORMAT) {
            return self::abgelehnt('Das Paket hat Format ' . $format . ' – diese PowerManagement-Fassung kennt Format ' . self::FORMAT
                . '. Erst hier aktualisieren.');
        }
        $typen = $daten['typen'] ?? null;
        if (!is_array($typen) || !array_is_list($typen) || $typen === []) {
            return self::abgelehnt('Das Paket enthält keine Typen („typen“ ist leer).');
        }
        if (count($typen) > self::HOECHSTENS) {
            return self::abgelehnt('Das Paket enthält ' . count($typen) . ' Typen – höchstens ' . self::HOECHSTENS . ' auf einmal.');
        }
        $gesehen = [];
        foreach ($typen as $t) {
            $k = is_array($t) && is_string($t['schluessel'] ?? null) ? $t['schluessel'] : '';
            if ($k === '') {
                continue;
            }
            if (isset($gesehen[$k])) {
                return self::abgelehnt('Der Schlüssel „' . mb_substr($k, 0, 64) . '“ steht mehrfach im Paket – jeder Typ braucht einen eigenen.');
            }
            $gesehen[$k] = true;
        }
        $quelle = is_string($daten['quelle'] ?? null) ? mb_substr(trim($daten['quelle']), 0, 190) : '';
        $paket = ['format' => self::FORMAT, 'art' => self::ART, 'quelle' => $quelle, 'typen' => $typen];

        $hier = [];
        foreach (AnlagenService::steckertypen() as $s) {
            $hier[(string) $s['schluessel']] = (string) $s['name'];
        }
        $zeilen = [];
        $zahlen = array_fill_keys(array_keys(self::ZUSTAENDE), 0);
        $stecker = [];
        foreach ($typen as $n => $t) {
            $zeile = self::typVergleichen($t, $n, $hier);
            $zeilen[] = $zeile;
            $zahlen[$zeile['zustand']]++;
            if ($zeile['zustand'] === 'fehler') {
                continue;
            }
            foreach ($zeile['stecker_neu'] as $k => $name) {
                $stecker[$k] ??= ['schluessel' => $k, 'name' => $name, 'typen' => []] + $zeile['stecker_angaben'][$k];
                $stecker[$k]['typen'][] = $zeile['name'];
            }
        }
        return ['ok' => true, 'message' => '', 'paket' => $paket, 'quelle' => $quelle, 'typen' => $zeilen,
            'steckertypen' => array_values($stecker), 'zahlen' => $zahlen];
    }

    /** @return array{ok:false,message:string,paket:null,quelle:string,typen:array,steckertypen:array,zahlen:array<string,int>} */
    private static function abgelehnt(string $meldung): array
    {
        return ['ok' => false, 'message' => $meldung, 'paket' => null, 'quelle' => '', 'typen' => [], 'steckertypen' => [],
            'zahlen' => array_fill_keys(array_keys(self::ZUSTAENDE), 0)];
    }

    /**
     * Einen Typ der Datei prüfen und mit dem Katalog hier vergleichen.
     *
     * @param array<string,string> $hier Steckertypen hier: schluessel => Name
     * @return array<string,mixed>
     */
    private static function typVergleichen(mixed $t, int $n, array $hier): array
    {
        $zeile = ['schluessel' => '', 'name' => 'Eintrag ' . ($n + 1), 'art' => '', 'art_name' => '', 'zustand' => 'fehler',
            'unterschiede' => [], 'abweichungen' => [], 'kurz' => '', 'fehler' => null, 'hinweise' => [], 'id' => null,
            'stand' => '', 'stecker_neu' => [], 'stecker_angaben' => []];
        if (!is_array($t)) {
            $zeile['fehler'] = 'Der Eintrag ist kein Typ.';
            return $zeile;
        }
        $inhalt = VerteilerBundle::inhalt($t);
        $zeile['schluessel'] = is_string($t['schluessel'] ?? null) ? $t['schluessel'] : '';
        if (is_string($inhalt['name'] ?? null) && trim($inhalt['name']) !== '') {
            $zeile['name'] = mb_substr(trim($inhalt['name']), 0, 190);
        }
        $art = is_string($inhalt['art'] ?? null) ? $inhalt['art'] : '';
        $zeile['art'] = $art;
        $zeile['art_name'] = AnlagenService::ARTEN[$art]['name'] ?? $art;

        $zeile['fehler'] = VerteilerBundle::fehler('typ', $t) ?? self::fehlerImTyp($t, $hier);
        if ($zeile['fehler'] !== null) {
            return $zeile;
        }
        if (is_array($inhalt['messpunkte'] ?? null) && $inhalt['messpunkte'] !== []) {
            $zeile['hinweise'][] = 'Messpunkte gehören zu einer aufgestellten Anlage, nicht zum Katalog-Typ – sie bleiben weg.';
        }
        // Verglichen wird, was übernommen würde (wie in uebernehmen()).
        $t = self::bereinigt($t);
        $inhalt = VerteilerBundle::inhalt($t);
        // Welche Steckertypen beim Übernehmen dazukämen – wie
        // LocationBundle::steckertypenSichern sie anlegt.
        $beschrieben = (array) self::beschriebeneStecker($t);

        // Die Kurzbeschreibung nennt Stecker, die die Datei nicht beschreibt,
        // mit ihrem Namen hier („Schuko“ statt „schuko“).
        $mitNamen = $t;
        foreach ((array) ($inhalt['anschluesse'] ?? []) as $a) {
            if (!isset($beschrieben[(string) $a['stecker']]) && isset($hier[(string) $a['stecker']])) {
                $mitNamen['steckertypen'][] = ['schluessel' => (string) $a['stecker'], 'name' => $hier[(string) $a['stecker']]];
            }
        }
        $zeile['kurz'] = VerteilerBundle::kurzbeschreibung($mitNamen);
        foreach ((array) ($inhalt['anschluesse'] ?? []) as $a) {
            $s = (string) $a['stecker'];
            $sauber = LocationBundle::schluessel($s, 'stecker');
            if (!isset($hier[$s]) && !isset($hier[$sauber]) && !isset($zeile['stecker_neu'][$sauber])) {
                $b = $beschrieben[$s];
                $zeile['stecker_neu'][$sauber] = mb_substr(trim((string) $b['name']), 0, 190);
                $zeile['stecker_angaben'][$sauber] = ['strom_a' => (float) $b['strom_a'], 'phasen' => (int) $b['phasen'] === 1 ? 1 : 3,
                    'spannung_v' => (int) $b['spannung_v']];
            }
        }
        $fremd = array_diff(array_keys((array) ($inhalt['eigenschaften'] ?? [])), array_keys(AnlagenService::FELDER[$art]));
        if ($fremd !== []) {
            $zeile['hinweise'][] = 'Nicht vorgesehen für ' . AnlagenService::ARTEN[$art]['mehrzahl'] . ', bleibt weg: '
                . implode(', ', array_map(static fn ($k) => '„' . mb_substr((string) $k, 0, 40) . '“', $fremd)) . '.';
        }

        // Gefunden wird wie in VerteilerBundle::typAnwenden: über den
        // Schlüssel, sonst über Art und Namen bei einem Typ ohne Schlüssel.
        $vorhanden = Database::first('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$zeile['schluessel']])
            ?? Database::first("SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = '' AND art = ? AND LOWER(name) = LOWER(?)",
                [$art, (string) $inhalt['name']]);
        if ($vorhanden === null) {
            $zeile['zustand'] = 'neu';
            $gleichnamig = Database::first('SELECT sync_key FROM objekte WHERE ist_vorlage = 1 AND art = ? AND LOWER(name) = LOWER(?)',
                [$art, (string) $inhalt['name']]);
            if ($gleichnamig !== null) {
                $zeile['hinweise'][] = 'Einen Typ dieses Namens gibt es hier schon (Schlüssel „' . $gleichnamig['sync_key']
                    . '“) – übernommen steht er zweimal im Katalog.';
            }
            return $zeile;
        }
        $zeile['id'] = (int) $vorhanden['id'];
        $hierInhalt = VerteilerBundle::typInhalt($zeile['id']);
        $kanon = self::kanon($inhalt, $hier);
        $a = ['art' => VerteilerBundle::BEREICHE['typ']['art'], 'typ' => $hierInhalt];
        $b = ['art' => VerteilerBundle::BEREICHE['typ']['art'], 'typ' => $kanon];
        // Der Stand hier, wie die Vorschau ihn zeigt – überschrieben wird nur
        // dieser (uebernehmen(), $gesehen), keine spätere Korrektur.
        $zeile['stand'] = VerteilerBundle::fingerabdruck($a);
        if ($zeile['stand'] === VerteilerBundle::fingerabdruck($b)) {
            $zeile['zustand'] = 'gleich';
            return $zeile;
        }
        $zeile['zustand'] = 'anders';
        $zeile['unterschiede'] = VerteilerBundle::unterschiede($a, $b);
        $namen = $hier;
        foreach ($beschrieben as $k => $s) {
            $namen[LocationBundle::schluessel((string) $k, 'stecker')] ??= (string) ($s['name'] ?? $k);
        }
        $zeile['abweichungen'] = self::abweichungen((array) $hierInhalt, $kanon, $namen);
        return $zeile;
    }

    /**
     * Taugt der Inhalt eines Typs zum Übernehmen? Sonst der Grund.
     *
     * Strenger als typAnwenden selbst: was dort still wegfiele oder anders
     * ankäme (ein Abgang an einem Schutzorgan, das es nicht gibt, eine
     * Sicherung „rcd“ als „RCD“, ein Stecker ohne Beschreibung), wird hier
     * gemeldet – die Datei stammt oft aus einem abgeschriebenen Dokument.
     *
     * @param array<string,string> $hier
     */
    private static function fehlerImTyp(array $t, array $hier): ?string
    {
        $schluessel = $t['schluessel'] ?? null;
        if (!is_string($schluessel) || $schluessel === '') {
            return 'Der Typ hat keinen Schlüssel („schluessel“).';
        }
        if (!LocationBundle::schluesselGueltig($schluessel)) {
            return 'Der Schlüssel „' . mb_substr($schluessel, 0, 64) . '“ ist ungültig – erlaubt sind a–z, 0–9 und Bindestriche.';
        }
        $i = VerteilerBundle::inhalt($t);
        $art = $i['art'] ?? null;
        if (!is_string($art) || !isset(AnlagenService::ARTEN[$art])) {
            return 'Unbekannte Art „' . (is_scalar($art) ? mb_substr((string) $art, 0, 40) : '') . '“ – möglich sind '
                . implode(', ', array_keys(AnlagenService::ARTEN)) . '.';
        }
        if (!is_string($i['name'] ?? null) || trim($i['name']) === '') {
            return 'Der Typ hat keinen Namen.';
        }
        foreach (['hersteller' => 'Hersteller', 'modell' => 'Modell', 'notizen' => 'Notizen'] as $feld => $name) {
            if (isset($i[$feld]) && !is_string($i[$feld]) && !is_int($i[$feld]) && !is_float($i[$feld])) {
                return '„' . $name . '“ muss Text sein.';
            }
        }
        foreach (['nennleistung_kva' => 'Nennleistung', 'nennstrom_a' => 'Nennstrom'] as $feld => $name) {
            if (isset($i[$feld]) && $i[$feld] !== '' && !self::zahl($i[$feld], true)) {
                return '„' . $name . '“ ist keine Zahl.';
            }
        }
        // Leer hieße hier nicht „unbekannt“, sondern 1 V (AnlagenService::speichern).
        if (isset($i['spannung_v']) && (!self::zahl($i['spannung_v'], true) || (float) str_replace(',', '.', trim((string) $i['spannung_v'])) < 1)) {
            return '„Spannung“ muss eine Zahl in Volt sein (etwa 400 oder 230) – oder ganz fehlen, dann gilt 400.';
        }
        if (isset($i['phasen']) && !in_array((int) (self::zahl($i['phasen'], false) ? $i['phasen'] : 0), [1, 3], true)) {
            return '„Phasen“ muss 1 oder 3 sein.';
        }

        $eigenschaften = $i['eigenschaften'] ?? [];
        if (!is_array($eigenschaften) || ($eigenschaften !== [] && array_is_list($eigenschaften))) {
            return '„eigenschaften“ muss ein Objekt sein.';
        }
        foreach ($eigenschaften as $k => $w) {
            $feld = AnlagenService::FELDER[$art][$k] ?? null;
            if ($w !== null && !is_scalar($w)) {
                return 'Eigenschaft „' . ($feld[0] ?? $k) . '“ muss Text oder eine Zahl sein.';
            }
            if ($feld !== null && $feld[1] === 'zahl' && $w !== null && trim((string) $w) !== '' && !self::zahl($w, true)) {
                return 'Eigenschaft „' . $feld[0] . '“ ist keine Zahl („' . mb_substr((string) $w, 0, 40) . '“).';
            }
            // Eine Auswahl nur mit einem ihrer Werte – das Formular zeigte einen
            // anderen nicht an, und das nächste Speichern verlöre ihn.
            if ($feld !== null && $feld[1] === 'auswahl' && $w !== null && trim((string) $w) !== '' && self::option($feld[2], $w) === null) {
                return 'Eigenschaft „' . $feld[0] . '“: „' . mb_substr((string) $w, 0, 40) . '“ gibt es nicht – möglich sind '
                    . implode(', ', $feld[2]) . '.';
            }
        }

        // Schutzorgane: eindeutige Verweise, Vorschaltung ohne Kreis
        $schutz = $i['schutzorgane'] ?? [];
        if (!is_array($schutz) || !array_is_list($schutz)) {
            return '„schutzorgane“ muss eine Liste sein.';
        }
        // Ein Tank hat weder Anschlüsse noch Schutzorgane – still fielen sie
        // beim Übernehmen weg, und der Typ stünde danach „hier anders“ da.
        if (!AnlagenService::elektrisch($art) && ($schutz !== [] || (is_array($i['anschluesse'] ?? null) && $i['anschluesse'] !== []))) {
            return 'Ein ' . AnlagenService::ARTEN[$art]['name'] . ' hat keine Anschlüsse und keine Schutzorgane – in der Datei stehen welche.';
        }
        if (count($schutz) > self::HOECHSTENS_JE_TYP) {
            return 'Mehr als ' . self::HOECHSTENS_JE_TYP . ' Schutzorgane.';
        }
        $refs = [];
        foreach ($schutz as $n => $s) {
            if (!is_array($s)) {
                return 'Schutzorgan ' . ($n + 1) . ' ist kein Objekt.';
            }
            $ref = self::ref($s, $n);
            $wer = 'Schutzorgan ' . (is_string($s['bezeichnung'] ?? null) && $s['bezeichnung'] !== '' ? '„' . $s['bezeichnung'] . '“' : $n + 1);
            if ($ref === null) {
                return $wer . ': „ref“ muss Text sein.';
            }
            if (isset($refs[$ref])) {
                return $wer . ': der Verweis „' . $ref . '“ steht zweimal.';
            }
            $refs[$ref] = $n;
            if (!is_string($s['art'] ?? null) || !isset(SchutzService::ARTEN[$s['art']])) {
                return $wer . ': unbekannte Art „' . (is_scalar($s['art'] ?? null) ? (string) $s['art'] : '') . '“ – möglich sind '
                    . implode(', ', array_keys(SchutzService::ARTEN)) . '.';
            }
            foreach (['nennstrom_a' => 'Nennstrom', 'fehlerstrom_ma' => 'Fehlerstrom'] as $feld => $name) {
                if (isset($s[$feld]) && !self::zahl($s[$feld], false)) {
                    return $wer . ': „' . $name . '“ ist keine Zahl.';
                }
            }
            if (isset($s['pole']) && !in_array(self::ganzzahl($s['pole']), [1, 2, 3, 4], true)) {
                return $wer . ': „pole“ muss 1 bis 4 sein.';
            }
            foreach (['bezeichnung', 'charakteristik', 'rcd_typ', 'notizen'] as $feld) {
                if (isset($s[$feld]) && !is_string($s[$feld])) {
                    return $wer . ': „' . $feld . '“ muss Text sein.';
                }
            }
        }
        foreach ($schutz as $n => $s) {
            if (!empty($s['vor']) && !isset($refs[(string) $s['vor']])) {
                return 'Schutzorgan ' . ($n + 1) . ': das vorgeschaltete „' . mb_substr((string) $s['vor'], 0, 40) . '“ gibt es in der Datei nicht.';
            }
        }
        foreach (array_keys($schutz) as $start) {
            $gesehen = [];
            for ($n = $start; $n !== null; $n = !empty($schutz[$n]['vor']) ? $refs[(string) $schutz[$n]['vor']] : null) {
                if (isset($gesehen[$n])) {
                    return 'Die Schutzorgane bilden einen Kreis (Vorschaltung über „vor“).';
                }
                $gesehen[$n] = true;
            }
        }

        // Anschlüsse: Richtung, Nummer einmal je Richtung, Stecker auflösbar
        $anschluesse = $i['anschluesse'] ?? [];
        if (!is_array($anschluesse) || !array_is_list($anschluesse)) {
            return '„anschluesse“ muss eine Liste sein.';
        }
        if (count($anschluesse) > self::HOECHSTENS_JE_TYP) {
            return 'Mehr als ' . self::HOECHSTENS_JE_TYP . ' Anschlüsse.';
        }
        $beschrieben = self::beschriebeneStecker($t);
        if ($beschrieben === null) {
            return '„steckertypen“ muss eine Liste von Steckertypen mit „schluessel“ sein.';
        }
        $stellen = [];
        $fehlen = [];
        foreach ($anschluesse as $n => $a) {
            if (!is_array($a)) {
                return 'Anschluss ' . ($n + 1) . ' ist kein Objekt.';
            }
            $richtung = $a['richtung'] ?? null;
            if ($richtung !== 'eingang' && $richtung !== 'abgang') {
                return 'Anschluss ' . ($n + 1) . ': „richtung“ muss „eingang“ oder „abgang“ sein.';
            }
            $nr = self::ganzzahl($a['nr'] ?? null);
            if ($nr === null || $nr < 1) {
                return 'Anschluss ' . ($n + 1) . ' (' . $richtung . '): „nr“ muss eine ganze Zahl ab 1 sein.';
            }
            $stelle = $richtung . ' ' . $nr;
            if (isset($stellen[$stelle])) {
                return 'Anschluss ' . $stelle . ' steht zweimal in der Datei.';
            }
            $stellen[$stelle] = true;
            if (!is_string($a['stecker'] ?? null) || $a['stecker'] === '') {
                return 'Anschluss ' . $stelle . ': kein Steckertyp („stecker“).';
            }
            if (!isset($hier[$a['stecker']]) && !isset($beschrieben[$a['stecker']])) {
                $fehlen[$a['stecker']] = true;
            }
            if (!empty($a['schutz']) && !isset($refs[(string) $a['schutz']])) {
                return 'Anschluss ' . $stelle . ': das Schutzorgan „' . mb_substr((string) $a['schutz'], 0, 40) . '“ gibt es in der Datei nicht.';
            }
            foreach (['bezeichnung', 'phase', 'notizen'] as $feld) {
                if (isset($a[$feld]) && !is_string($a[$feld])) {
                    return 'Anschluss ' . $stelle . ': „' . $feld . '“ muss Text sein.';
                }
            }
        }
        if ($fehlen !== []) {
            $namen = array_map(static fn ($k) => '„' . mb_substr((string) $k, 0, 40) . '“', array_keys($fehlen));
            return (count($namen) === 1 ? 'Steckertyp ' . $namen[0] . ' fehlt' : 'Steckertypen ' . implode(', ', $namen) . ' fehlen')
                . ' – weder hier vorhanden noch in der Datei beschrieben („steckertypen“).';
        }
        // Ein Steckertyp, der neu angelegt würde, braucht seine Werte – ein
        // fehlender Nennstrom würde sonst still 16 A, ein unlesbarer 0 A, und
        // damit rechnen Kabel, Schutzorgane und Auslastung.
        foreach ($anschluesse as $a) {
            $s = (string) $a['stecker'];
            if (isset($hier[$s]) || isset($hier[LocationBundle::schluessel($s, 'stecker')])) {
                continue;
            }
            $fehler = self::fehlerImStecker($beschrieben[$s]);
            if ($fehler !== null) {
                return 'Steckertyp „' . mb_substr($s, 0, 40) . '“ (neu): ' . $fehler;
            }
        }
        return null;
    }

    /** Taugt die Beschreibung eines neuen Steckertyps? Sonst der Grund. */
    private static function fehlerImStecker(array $s): ?string
    {
        if (!is_string($s['name'] ?? null) || trim($s['name']) === '') {
            return 'kein Name („name“).';
        }
        // Ohne Komma: LocationBundle::steckertypenSichern liest die Zahl unverändert.
        if (!isset($s['strom_a']) || !self::zahl($s['strom_a'], false) || (float) $s['strom_a'] <= 0) {
            return '„strom_a“ muss der Nennstrom in A sein (eine Zahl über 0).';
        }
        if (!isset($s['phasen']) || !self::zahl($s['phasen'], false) || !in_array((float) $s['phasen'], [1.0, 3.0], true)) {
            return '„phasen“ muss 1 oder 3 sein.';
        }
        if (!isset($s['spannung_v']) || !self::zahl($s['spannung_v'], false) || (float) $s['spannung_v'] < 1) {
            return '„spannung_v“ muss die Spannung in V sein (etwa 400 oder 230).';
        }
        return null;
    }

    /** Die Option einer Auswahl, auch in anderer Schreibweise („diesel“ → „Diesel“) – sonst null. */
    private static function option(array $optionen, mixed $wert): ?string
    {
        $wert = mb_strtolower(trim((string) $wert));
        foreach ($optionen as $o) {
            if (mb_strtolower((string) $o) === $wert) {
                return (string) $o;
            }
        }
        return null;
    }

    /**
     * Ein Typ der Datei so, wie er übernommen wird: ohne Messpunkte (die
     * gehören nie zu einem Katalog-Typ), Auswahl-Eigenschaften in der
     * Schreibweise der Auswahl, nur die Steckertypen, die er benutzt – keine
     * unbenutzten Einträge im Steckverbinder-Katalog. Nur für einen Typ, den
     * fehlerImTyp() für gut befunden hat.
     */
    private static function bereinigt(array $datei): array
    {
        $inhalt = VerteilerBundle::BEREICHE['typ']['inhalt'];
        $t = (array) $datei[$inhalt];
        unset($t['messpunkte']);
        foreach (AnlagenService::FELDER[(string) $t['art']] ?? [] as $k => $feld) {
            $w = $t['eigenschaften'][$k] ?? null;
            if ($feld[1] === 'auswahl' && is_scalar($w) && trim((string) $w) !== '') {
                $t['eigenschaften'][$k] = self::option($feld[2], $w) ?? $w;
            }
        }
        $datei[$inhalt] = $t;
        $benutzt = array_flip(array_map(static fn ($a) => (string) $a['stecker'], (array) ($t['anschluesse'] ?? [])));
        $datei['steckertypen'] = array_values(array_filter((array) ($datei['steckertypen'] ?? []),
            static fn ($s) => isset($benutzt[(string) $s['schluessel']])));
        return $datei;
    }

    /**
     * Die Steckertypen, die die Datei eines Typs beschreibt.
     *
     * @return array<string,array<string,mixed>>|null schluessel => Beschreibung; null = keine gültige Liste
     */
    private static function beschriebeneStecker(array $t): ?array
    {
        $liste = $t['steckertypen'] ?? [];
        if (!is_array($liste) || !array_is_list($liste)) {
            return null;
        }
        $out = [];
        foreach ($liste as $s) {
            if (!is_array($s) || !is_string($s['schluessel'] ?? null) || $s['schluessel'] === ''
                || (isset($s['name']) && !is_string($s['name']))) {
                return null;
            }
            $out[$s['schluessel']] ??= $s;
        }
        return $out;
    }

    /** Der Verweis eines Schutzorgans, wie LocationBundle::belegungErsetzen ihn liest; null = unbrauchbar. */
    private static function ref(array $s, int $n): ?string
    {
        $ref = $s['ref'] ?? 's' . ($n + 1);
        return is_string($ref) || is_int($ref) ? (string) $ref : null;
    }

    /** Eine ganze Zahl (auch 3.0 aus einer Datei) – sonst null. */
    private static function ganzzahl(mixed $w): ?int
    {
        if (is_int($w)) {
            return $w;
        }
        return is_float($w) && floor($w) === $w && abs($w) < 1e9 ? (int) $w : null;
    }

    /** Ist das eine Zahl? Mit $komma auch „2,5“ als Text (wie im Formular). */
    private static function zahl(mixed $w, bool $komma): bool
    {
        if (is_int($w) || is_float($w)) {
            return true;
        }
        if (!is_string($w)) {
            return false;
        }
        return is_numeric($komma ? str_replace(',', '.', trim($w)) : trim($w));
    }

    /**
     * Wie der Typ nach dem Übernehmen hier dastünde – im Format von
     * VerteilerBundle::typInhalt. So vergleicht der Fingerabdruck, was
     * ankäme, nicht, wie die Datei es schreibt: „63“ als Text, Schutzorgane
     * in anderer Reihenfolge, eigene Verweise („rcd1“ statt „s2“) oder ein
     * Steckerschlüssel in Großbuchstaben ergäben sonst gleich nach dem
     * Import „hier anders“.
     *
     * Die Regeln sind die von AnlagenService::speichern,
     * LocationBundle::belegungErsetzen und SchutzService::liste; die
     * Prüfung (tests/katalog-import.php) liest nach dem Import zurück.
     *
     * @param array<string,string> $hier Steckertypen hier
     */
    private static function kanon(array $i, array $hier): array
    {
        $art = (string) $i['art'];
        $text = static fn (mixed $w): string => mb_substr(trim((string) ($w ?? '')), 0, 190);
        $zahl = static function (mixed $w): ?float {
            $w = trim(str_replace(',', '.', (string) ($w ?? '')));
            return $w === '' ? null : (float) $w;
        };

        // Schutzorgane in der Reihenfolge des Baums, Verweise neu gezählt
        $schutz = array_values((array) ($i['schutzorgane'] ?? []));
        $refs = [];
        foreach ($schutz as $n => $s) {
            $refs[(string) self::ref($s, $n)] = $n;
        }
        $vor = [];
        $kinder = [];
        foreach ($schutz as $n => $s) {
            $vor[$n] = !empty($s['vor']) && isset($refs[(string) $s['vor']]) ? $refs[(string) $s['vor']] : null;
            $kinder[$vor[$n] ?? -1][] = $n;
        }
        $reihe = [];
        $gehen = static function (int $elter) use (&$gehen, &$reihe, $kinder): void {
            foreach ($kinder[$elter] ?? [] as $n) {
                $reihe[] = $n;
                $gehen($n);
            }
        };
        $gehen(-1);
        $neuRef = [];
        foreach ($reihe as $stelle => $n) {
            $neuRef[$n] = 's' . ($stelle + 1);
        }
        $schutzorgane = [];
        foreach ($reihe as $n) {
            $s = $schutz[$n];
            $schutzorgane[] = [
                'ref'            => $neuRef[$n],
                'art'            => (string) $s['art'],
                'bezeichnung'    => mb_substr((string) ($s['bezeichnung'] ?? ''), 0, 64),
                'nennstrom_a'    => isset($s['nennstrom_a']) ? (float) $s['nennstrom_a'] : null,
                'charakteristik' => mb_substr((string) ($s['charakteristik'] ?? ''), 0, 8),
                'fehlerstrom_ma' => isset($s['fehlerstrom_ma']) ? (int) $s['fehlerstrom_ma'] : null,
                'rcd_typ'        => mb_substr((string) ($s['rcd_typ'] ?? ''), 0, 8),
                'pole'           => (int) ($s['pole'] ?? 3),
                'vor'            => $vor[$n] !== null ? $neuRef[$vor[$n]] : null,
                'notizen'        => (string) ($s['notizen'] ?? ''),
            ];
        }

        $anschluesse = [];
        foreach ((array) ($i['anschluesse'] ?? []) as $a) {
            $stecker = (string) $a['stecker'];
            $anschluesse[] = [
                'richtung'    => $a['richtung'] === 'eingang' ? 'eingang' : 'abgang',
                'stecker'     => isset($hier[$stecker]) ? $stecker : LocationBundle::schluessel($stecker, 'stecker'),
                'nr'          => (int) $a['nr'],
                'bezeichnung' => mb_substr((string) ($a['bezeichnung'] ?? ''), 0, 64),
                'schutz'      => !empty($a['schutz']) ? $neuRef[$refs[(string) $a['schutz']]] : null,
                'phase'       => mb_substr((string) ($a['phase'] ?? ''), 0, 8),
                'notizen'     => (string) ($a['notizen'] ?? ''),
            ];
        }

        // Ein Tank hat feste elektrische Werte (wie in AnlagenService::speichern).
        $elektrisch = AnlagenService::elektrisch($art);
        return [
            'art'                => $art,
            'fest'               => !empty($i['fest']),
            'name'               => $text($i['name']),
            'hersteller'         => $text($i['hersteller'] ?? ''),
            'modell'             => $text($i['modell'] ?? ''),
            'nennleistung_kva'   => $elektrisch ? $zahl($i['nennleistung_kva'] ?? '') : null,
            'nennstrom_a'        => $elektrisch ? $zahl($i['nennstrom_a'] ?? '') : null,
            'spannung_v'         => $elektrisch ? max(1, (int) ($i['spannung_v'] ?? 400)) : 400,
            'phasen'             => $elektrisch && (int) ($i['phasen'] ?? 3) === 1 ? 1 : 3,
            'eigenschaften'      => AnlagenService::eigenschaftenFiltern($art, (array) ($i['eigenschaften'] ?? [])),
            'steuerung_gesperrt' => $elektrisch && !empty($i['steuerung_gesperrt']),
            'notizen'            => (string) ($i['notizen'] ?? ''),
            'schutzorgane'       => $schutzorgane,
            'anschluesse'        => $anschluesse,
        ];
    }

    /**
     * Was genau anders ist – zum Aufklappen in der Vorschau.
     *
     * @param array<string,string> $steckerNamen schluessel => Name
     * @return list<array{feld:string,hier:string,datei:string}>
     */
    private static function abweichungen(array $hier, array $datei, array $steckerNamen): array
    {
        $out = [];
        $gleich = static fn (mixed $a, mixed $b): bool => json_encode($a) === json_encode($b);
        foreach (self::EINFACH as $feld) {
            if (!$gleich($hier[$feld] ?? null, $datei[$feld] ?? null)) {
                $out[] = ['feld' => VerteilerBundle::FELDNAMEN[$feld] ?? $feld,
                    'hier' => self::wert($feld, $hier[$feld] ?? null), 'datei' => self::wert($feld, $datei[$feld] ?? null)];
            }
        }
        $felder = AnlagenService::FELDER[(string) ($datei['art'] ?? '')] ?? [];
        $eh = (array) ($hier['eigenschaften'] ?? []);
        $ed = (array) ($datei['eigenschaften'] ?? []);
        foreach (array_unique(array_merge(array_keys($felder), array_keys($eh), array_keys($ed))) as $k) {
            if (!$gleich($eh[$k] ?? null, $ed[$k] ?? null)) {
                $einheit = static fn (mixed $w): string => isset($felder[$k]) ? AnlagenService::einheit((string) $felder[$k][1], $felder[$k][2], $w) : '';
                $out[] = ['feld' => $felder[$k][0] ?? (string) $k,
                    'hier' => self::wert('', $eh[$k] ?? null, $einheit($eh[$k] ?? null)), 'datei' => self::wert('', $ed[$k] ?? null, $einheit($ed[$k] ?? null))];
            }
        }

        $sh = (array) ($hier['schutzorgane'] ?? []);
        $sd = (array) ($datei['schutzorgane'] ?? []);
        // Verweise „s3“ als Bezeichnung des Schutzorgans („Q3“) zeigen
        $schutzNamen = static fn (array $liste): array => array_map(static fn ($s) => (string) $s['bezeichnung'] !== '' ? (string) $s['bezeichnung'] : (string) $s['ref'],
            array_column($liste, null, 'ref'));
        $nh = $schutzNamen($sh);
        $nd = $schutzNamen($sd);
        if (!$gleich($sh, $sd)) {
            $text = static fn (?array $s, array $namen): string => $s === null ? '–'
                : SchutzService::text($s) . ($s['vor'] !== null ? ' hinter ' . ($namen[$s['vor']] ?? $s['vor']) : '');
            $zeilen = [];
            for ($n = 0; $n < max(count($sh), count($sd)); $n++) {
                if (!$gleich($sh[$n] ?? null, $sd[$n] ?? null)) {
                    $zeilen[] = ['feld' => 'Schutzorgan ' . ($n + 1), 'hier' => $text($sh[$n] ?? null, $nh), 'datei' => $text($sd[$n] ?? null, $nd)];
                }
            }
            if (count($sh) !== count($sd)) {
                $out[] = ['feld' => 'Schutzorgane', 'hier' => count($sh) . ' Stück', 'datei' => count($sd) . ' Stück'];
            }
            array_push($out, ...self::gekuerzt($zeilen, 'Schutzorgane'));
        }

        $stelle = static fn (array $liste): array => array_column(array_map(static fn ($a) => $a + ['k' => $a['richtung'] . ' ' . $a['nr']], $liste), null, 'k');
        $ah = $stelle((array) ($hier['anschluesse'] ?? []));
        $ad = $stelle((array) ($datei['anschluesse'] ?? []));
        $sortiert = static function (array $x): array {
            ksort($x);
            return $x;
        };
        if (!$gleich($sortiert($ah), $sortiert($ad))) {
            // Die Übersicht nur, wenn sich die Bestückung unterscheidet – sonst
            // stünde zweimal dasselbe da.
            $kurzHier = self::anschlussKurz($ah, $steckerNamen);
            $kurzDatei = self::anschlussKurz($ad, $steckerNamen);
            if ($kurzHier !== $kurzDatei) {
                $out[] = ['feld' => 'Anschlüsse', 'hier' => $kurzHier, 'datei' => $kurzDatei];
            }
            $text = static function (?array $a, array $namen) use ($steckerNamen): string {
                if ($a === null) {
                    return '–';
                }
                $teile = [$steckerNamen[$a['stecker']] ?? $a['stecker'], $a['bezeichnung'],
                    $a['schutz'] !== null ? 'an ' . ($namen[$a['schutz']] ?? $a['schutz']) : '', $a['phase'], $a['notizen']];
                return implode(' · ', array_filter($teile, static fn ($t) => (string) $t !== ''));
            };
            $zeilen = [];
            $alle = array_unique(array_merge(array_keys($ah), array_keys($ad)));
            sort($alle, SORT_NATURAL);
            foreach ($alle as $k) {
                unset($ah[$k]['k'], $ad[$k]['k']);
                if (!$gleich($ah[$k] ?? null, $ad[$k] ?? null)) {
                    $zeilen[] = ['feld' => ucfirst((string) $k), 'hier' => $text($ah[$k] ?? null, $nh), 'datei' => $text($ad[$k] ?? null, $nd)];
                }
            }
            array_push($out, ...self::gekuerzt($zeilen, 'Anschlüsse'));
        }
        return $out;
    }

    /** @return list<array{feld:string,hier:string,datei:string}> höchstens acht, dann ein Rest */
    private static function gekuerzt(array $zeilen, string $was): array
    {
        if (count($zeilen) <= 8) {
            return $zeilen;
        }
        $rest = count($zeilen) - 7;
        return [...array_slice($zeilen, 0, 7), ['feld' => '…', 'hier' => 'und ' . $rest . ' weitere ' . $was, 'datei' => '']];
    }

    /** „1× CEE 63 A → 2× CEE 32 A, 6× Schuko“ */
    private static function anschlussKurz(array $anschluesse, array $steckerNamen): string
    {
        $zahl = ['eingang' => [], 'abgang' => []];
        foreach ($anschluesse as $a) {
            $name = $steckerNamen[$a['stecker']] ?? $a['stecker'];
            $zahl[$a['richtung']][$name] = ($zahl[$a['richtung']][$name] ?? 0) + 1;
        }
        $teil = static fn (array $je): string => implode(', ', array_map(static fn ($n, $name) => $n . '× ' . $name, $je, array_keys($je)));
        $ein = $teil($zahl['eingang']);
        $ab = $teil($zahl['abgang']);
        return $ein === '' && $ab === '' ? 'keine' : trim(($ein !== '' ? $ein . ' → ' : '') . $ab);
    }

    private static function wert(string $feld, mixed $w, string $einheit = ''): string
    {
        if ($w === null || $w === '') {
            return '–';
        }
        if (is_bool($w)) {
            return match ($feld) {
                'fest'               => $w ? 'fest' : 'mobil',
                'steuerung_gesperrt' => $w ? 'gesperrt' : 'frei',
                default              => $w ? 'ja' : 'nein',
            };
        }
        if ($feld === 'art') {
            return AnlagenService::ARTEN[(string) $w]['name'] ?? (string) $w;
        }
        if (is_float($w) || is_int($w)) {
            $w = rtrim(rtrim(number_format((float) $w, 3, ',', ''), '0'), ',');
        }
        $w = (string) $w;
        if (mb_strlen($w) > 80) {
            $w = mb_substr($w, 0, 79) . '…';
        }
        return $w . ($einheit !== '' ? ' ' . $einheit : '');
    }

    // ------------------------------------------------------------ Übernehmen

    /**
     * Ausgewählte Typen übernehmen – alles oder nichts.
     *
     * Neu angelegt wird, was in $neu steht und hier fehlt; ersetzt nur, was
     * in $ueberschreiben steht und hier anders dasteht. Was hier inzwischen
     * anders ist als beim Lesen (ein „neuer“ Typ, den es jetzt doch gibt),
     * bleibt stehen. Neue Steckertypen legt nur an, wer den
     * Steckverbinder-Katalog pflegen darf – sonst geschieht nichts.
     *
     * Mit $gesehen (aus der Vorschau) gilt die Auswahl nur für den Stand, den
     * die Vorschau gezeigt hat: wer „überschreiben“ angehakt hat, ersetzt nur
     * diesen Stand, keine Korrektur, die jemand danach gespeichert hat; ein
     * „neuer“ Typ, den es inzwischen gibt, und ein überschriebener, den
     * jemand inzwischen gelöscht hat, bleiben ebenso. Ohne $gesehen
     * (Kommandozeile – sie liest und übernimmt in einem Zug) zählt der Stand
     * beim Übernehmen.
     *
     * @param list<string> $neu Schlüssel der neuen Typen, die angelegt werden
     * @param list<string> $ueberschreiben Schlüssel der hier anderen Typen, die ersetzt werden
     * @param array{katalog:bool,stecker:bool}|null $erlaubt null = Rechte des angemeldeten Benutzers
     * @param array<string,string>|null $gesehen Schlüssel => Stand hier (Zeile „stand“ aus lesen()), wie die Vorschau ihn zeigte
     * @return array{ok:bool,message:string,neu:list<string>,ueberschrieben:list<string>,uebergangen:list<string>,
     *               veraendert:list<string>,gleich:list<string>,steckertypen:list<string>,ids:array<int,string>}
     *         ids: ID => neu|ueberschrieben; veraendert: gewählt, aber seit der Vorschau hier geändert
     */
    public static function uebernehmen(array $paket, array $neu, array $ueberschreiben, ?array $erlaubt = null, ?array $gesehen = null): array
    {
        $ergebnis = ['ok' => false, 'message' => '', 'neu' => [], 'ueberschrieben' => [], 'uebergangen' => [], 'veraendert' => [],
            'gleich' => [], 'steckertypen' => [], 'ids' => []];
        $erlaubt ??= self::erlaubt();
        if (empty($erlaubt['katalog'])) {
            return ['message' => 'Den Katalog importieren darf, wer Anlagen ändern darf und alle Standorte sieht.'] + $ergebnis;
        }
        $stand = self::pruefen($paket);
        if (!$stand['ok']) {
            return ['message' => $stand['message']] + $ergebnis;
        }
        $neu = array_flip(array_map('strval', $neu));
        $ueberschreiben = array_flip(array_map('strval', $ueberschreiben));
        $anwenden = [];
        $wieGesehen = static fn (string $k, string $jetzt): bool => $gesehen === null
            || (is_string($gesehen[$k] ?? null) && hash_equals($gesehen[$k], $jetzt));
        foreach ($stand['typen'] as $n => $z) {
            $k = (string) $z['schluessel'];
            $gewaehlt = isset($neu[$k]) || isset($ueberschreiben[$k]);
            if ($z['zustand'] === 'neu' && (isset($neu[$k]) || (isset($ueberschreiben[$k]) && $gesehen === null))) {
                $anwenden[$n] = 'neu';
            } elseif ($z['zustand'] === 'anders' && isset($ueberschreiben[$k]) && $wieGesehen($k, (string) $z['stand'])) {
                $anwenden[$n] = 'ueberschrieben';
            } elseif ($z['zustand'] === 'gleich') {
                $ergebnis['gleich'][] = $z['name'];
            } elseif ($gewaehlt && $gesehen !== null && $z['zustand'] !== 'fehler') {
                $ergebnis['veraendert'][] = $z['name'];
            } else {
                $ergebnis['uebergangen'][] = $z['name'];
            }
        }

        $stecker = [];
        $brauchen = [];
        foreach (array_keys($anwenden) as $n) {
            $stecker += $stand['typen'][$n]['stecker_neu'];
            if ($stand['typen'][$n]['stecker_neu'] !== []) {
                $brauchen[] = $stand['typen'][$n]['name'];
            }
        }
        if ($stecker !== [] && empty($erlaubt['stecker'])) {
            return ['message' => 'Nichts übernommen: ' . (count($stecker) === 1 ? 'den Steckertyp ' : 'die Steckertypen ')
                . implode(', ', array_map(static fn ($s) => '„' . $s . '“', $stecker)) . ' gibt es hier noch nicht, und anlegen darf '
                . (count($stecker) === 1 ? 'ihn' : 'sie') . ' nur, wer den Steckverbinder-Katalog pflegt (gebraucht von '
                . self::namen($brauchen) . ').'] + $ergebnis;
        }
        if ($anwenden === []) {
            $rest = self::rest($ergebnis);
            return ['ok' => true, 'message' => 'Nichts übernommen' . ($rest !== [] ? ' – ' . implode('; ', $rest) : '') . '.'] + $ergebnis;
        }

        $pdo = Database::pdo();
        $eigene = !$pdo->inTransaction();
        $eigene ? $pdo->beginTransaction() : Database::execute('SAVEPOINT katalog_import');
        try {
            foreach ($anwenden as $n => $wie) {
                $z = $stand['typen'][$n];
                $e = VerteilerBundle::typAnwenden((string) $z['schluessel'], self::bereinigt($stand['paket']['typen'][$n]));
                if (!$e['ok']) {
                    throw new \DomainException('„' . $z['name'] . '“: ' . $e['message']);
                }
                $ergebnis[$wie][] = $z['name'];
                $ergebnis['ids'][(int) $e['id']] = $wie;
            }
            $ergebnis['steckertypen'] = array_values($stecker);
            Audit::log('katalog.import', 'katalog', '', [
                'quelle'         => $stand['quelle'],
                'neu'            => $ergebnis['neu'],
                'ueberschrieben' => $ergebnis['ueberschrieben'],
                'uebergangen'    => array_merge($ergebnis['uebergangen'], $ergebnis['veraendert']),
                'steckertypen'   => $ergebnis['steckertypen'],
            ]);
            $eigene ? $pdo->commit() : Database::execute('RELEASE SAVEPOINT katalog_import');
        } catch (\Throwable $f) {
            if ($eigene) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                Database::execute('ROLLBACK TO SAVEPOINT katalog_import');
                Database::execute('RELEASE SAVEPOINT katalog_import');
            }
            if (!$f instanceof \DomainException) {
                error_log('Katalog-Import: ' . $f->getMessage());
            }
            return ['message' => 'Nichts übernommen – ' . ($f instanceof \DomainException ? $f->getMessage() : 'beim Speichern ging etwas schief.')]
                + array_merge($ergebnis, ['neu' => [], 'ueberschrieben' => [], 'steckertypen' => [], 'ids' => []]);
        }

        $teile = [];
        if ($ergebnis['neu'] !== []) {
            $teile[] = count($ergebnis['neu']) . ' neu angelegt (' . self::namen($ergebnis['neu']) . ')';
        }
        if ($ergebnis['ueberschrieben'] !== []) {
            $teile[] = count($ergebnis['ueberschrieben']) . ' überschrieben (' . self::namen($ergebnis['ueberschrieben']) . ')';
        }
        $meldung = 'Katalog importiert: ' . implode('; ', array_merge($teile, self::rest($ergebnis))) . '.';
        if ($ergebnis['steckertypen'] !== []) {
            $meldung .= ' Neue Steckertypen: ' . implode(', ', $ergebnis['steckertypen']) . '.';
        }
        return ['ok' => true, 'message' => $meldung] + $ergebnis;
    }

    /** @return list<string> „3 schon genauso da“, „2 übergangen (A, B)“, „1 seit der Vorschau hier geändert, bleibt (C)“ */
    private static function rest(array $ergebnis): array
    {
        $out = [];
        if ($ergebnis['gleich'] !== []) {
            $out[] = count($ergebnis['gleich']) . ' schon genauso da';
        }
        if ($ergebnis['uebergangen'] !== []) {
            $out[] = count($ergebnis['uebergangen']) . ' übergangen (' . self::namen($ergebnis['uebergangen']) . ')';
        }
        if ($ergebnis['veraendert'] !== []) {
            $out[] = count($ergebnis['veraendert']) . ' seit der Vorschau hier geändert, '
                . (count($ergebnis['veraendert']) === 1 ? 'bleibt' : 'bleiben') . ' (' . self::namen($ergebnis['veraendert']) . ')';
        }
        return $out;
    }

    /** Namen für eine Meldung – höchstens zwölf, dann „und N weitere“. */
    public static function namen(array $namen, int $hoechstens = 12): string
    {
        $namen = array_values($namen);
        if (count($namen) <= $hoechstens) {
            return implode(', ', $namen);
        }
        return implode(', ', array_slice($namen, 0, $hoechstens)) . ' und ' . (count($namen) - $hoechstens) . ' weitere';
    }

    // ------------------------------------------------------------ Mitgelieferte Kataloge

    public static function ordner(): string
    {
        return APP_ROOT . '/resources/kataloge';
    }

    /**
     * Die mitgelieferten Pakete (resources/kataloge/*.json).
     *
     * @return list<array{datei:string,quelle:string,typen:int,fehler:?string}>
     */
    public static function mitgeliefert(): array
    {
        $out = [];
        foreach (glob(self::ordner() . '/*.json') ?: [] as $pfad) {
            $datei = basename($pfad);
            if (preg_match('/^[a-z0-9][a-z0-9._-]*\.json$/', $datei) !== 1 || !is_file($pfad)) {
                continue;
            }
            $daten = filesize($pfad) <= self::GROESSE ? json_decode((string) file_get_contents($pfad), true) : null;
            $typen = is_array($daten) && is_array($daten['typen'] ?? null) ? count($daten['typen']) : 0;
            $out[] = [
                'datei'  => $datei,
                'quelle' => is_array($daten) && is_string($daten['quelle'] ?? null) && trim($daten['quelle']) !== '' ? trim($daten['quelle']) : $datei,
                'typen'  => $typen,
                'fehler' => is_array($daten) && ($daten['art'] ?? '') === self::ART ? null : 'kein Katalog-Paket',
            ];
        }
        return $out;
    }

    /** Inhalt eines mitgelieferten Pakets – nur über seinen Namen aus der Liste, nie über einen Pfad. */
    public static function mitgeliefertLesen(string $datei): ?string
    {
        foreach (self::mitgeliefert() as $m) {
            if ($m['datei'] === $datei) {
                $inhalt = file_get_contents(self::ordner() . '/' . $m['datei']);
                return $inhalt === false ? null : $inhalt;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ Vorschau ablegen

    private static function ablage(string $kennung): ?string
    {
        return preg_match('/^[0-9a-f]{32}$/', $kennung) === 1 ? APP_ROOT . '/storage/cache/katalog-import-' . $kennung . '.json' : null;
    }

    /** Das gelesene Paket bis zum Übernehmen ablegen. @return string Kennung */
    public static function ablegen(array $paket): string
    {
        $ordner = APP_ROOT . '/storage/cache';
        if (!is_dir($ordner)) {
            mkdir($ordner, 0775, true);
        }
        $kennung = bin2hex(random_bytes(16));
        file_put_contents((string) self::ablage($kennung),
            json_encode($paket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX);
        return $kennung;
    }

    /** Das abgelegte Paket – oder null, wenn es keins (mehr) gibt oder es älter als eine Stunde ist. */
    public static function abgelegt(string $kennung): ?array
    {
        $pfad = self::ablage($kennung);
        if ($pfad === null || !is_file($pfad)) {
            return null;
        }
        if (time() - (int) filemtime($pfad) > self::VORSCHAU_DAUER) {
            @unlink($pfad);
            return null;
        }
        $paket = json_decode((string) file_get_contents($pfad), true);
        return is_array($paket) ? $paket : null;
    }

    public static function verwerfen(string $kennung): void
    {
        $pfad = self::ablage($kennung);
        if ($pfad !== null && is_file($pfad)) {
            @unlink($pfad);
        }
    }

    /** Abgelaufene Vorschauen wegräumen (bin/power wartung). @return int wie viele */
    public static function aufraeumen(?int $jetzt = null): int
    {
        $jetzt ??= time();
        $weg = 0;
        foreach (glob(APP_ROOT . '/storage/cache/katalog-import-*.json') ?: [] as $pfad) {
            if ($jetzt - (int) filemtime($pfad) > self::VORSCHAU_DAUER && @unlink($pfad)) {
                $weg++;
            }
        }
        return $weg;
    }
}
