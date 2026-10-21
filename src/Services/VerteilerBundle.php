<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Database;
use App\Support\Fingerabdruck;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

/**
 * Verteiler als Dateien – für den Abgleich über das gemeinsame Repository.
 *
 * Zwei Arten, eine Datei je Verteiler:
 *
 *   verteiler/typen/<schlüssel>.json      ein Standard-Verteiler aus dem
 *                                          Katalog mit Nennwerten,
 *                                          Anschlüssen und Schutzorganen
 *   verteiler/inventar/<schlüssel>.json   ein Stück aus dem Inventar
 *                                          (Verteiler, Zwischenstecker oder
 *                                          Tanksensor) mit seinen Shellys
 *
 *   { format: 1, art: "verteiler-typ", schluessel, stand, von,
 *     typ: {art, fest, name, hersteller, modell, nennleistung_kva, nennstrom_a,
 *           spannung_v, phasen, eigenschaften, steuerung_gesperrt, notizen,
 *           schutzorgane: [{ref, art, …, vor}], anschluesse: [{richtung, stecker, nr, …}]},
 *     steckertypen: [{schluessel, name, strom_a, phasen, spannung_v, farbe}] }
 *
 *   { format: 1, art: "verteiler-inventar", schluessel, stand, von,
 *     stueck: {art, kennung, name, typ (Schlüssel des Standard-Verteilers),
 *              stecker (Zwischenstecker), seriennummer, defekt, notizen,
 *              messgeraete: [{geraet, kanal, stelle, bezeichnung}],
 *              eigenschaften (nur Tanksensor: Sensordaten)},
 *     steckertypen: […] }
 *
 * Eine Datei je Verteiler, damit die Geschichte im Repository zeigt, wann an
 * welchem Stück etwas geändert wurde, und damit zwei Installationen, die an
 * verschiedenen Stücken arbeiten, sich nicht in die Quere kommen.
 *
 * Nicht in den Dateien steht, wo ein Stück gerade eingesetzt ist, und welche
 * Anlagen aus einem Typ entstanden sind: das gehört zur Veranstaltung und
 * bleibt in der Installation, die sie plant. Ebenso die Kennlinien und
 * Peilungen eines Tanksensors – sie gehören zu seinem Einbau.
 *
 * Ob sich etwas geändert hat, sagt ein Fingerabdruck des Inhalts (`typ`
 * bzw. `stueck`, ohne Stand und Absender). Beim Abgleich wird er je
 * Verteiler festgehalten (synced_hash); so lässt sich getrennt sagen, ob
 * sich seitdem hier, dort oder auf beiden Seiten etwas geändert hat.
 */
final class VerteilerBundle
{
    /** Das Format der Dateien. */
    public const FORMAT = 1;

    /** Die beiden Arten: Ordner im Repository, Art in der Datei, Schlüssel des Inhalts. */
    public const BEREICHE = [
        'typ'      => ['ordner' => 'verteiler/typen',    'art' => 'verteiler-typ',      'inhalt' => 'typ',    'name' => 'Standard-Verteiler'],
        'inventar' => ['ordner' => 'verteiler/inventar', 'art' => 'verteiler-inventar', 'inhalt' => 'stueck', 'name' => 'Inventar'],
    ];

    /** Wie die Felder heißen – für „was ist anders“ (auch im Katalog-Import). */
    public const FELDNAMEN = [
        'art' => 'Art', 'fest' => 'fest/mobil', 'name' => 'Name', 'hersteller' => 'Hersteller', 'modell' => 'Modell',
        'nennleistung_kva' => 'Nennleistung', 'nennstrom_a' => 'Nennstrom', 'spannung_v' => 'Spannung', 'phasen' => 'Phasen',
        'eigenschaften' => 'Eigenschaften', 'steuerung_gesperrt' => 'Schaltsperre', 'notizen' => 'Notizen',
        'schutzorgane' => 'Schutzorgane', 'anschluesse' => 'Anschlüsse', 'kennung' => 'Inventarnummer', 'typ' => 'Typ',
        'stecker' => 'Steckverbinder', 'seriennummer' => 'Seriennummer', 'defekt' => 'defekt', 'messgeraete' => 'Shellys',
    ];

    // ------------------------------------------------------------ Schlüssel

    /**
     * Schlüssel eines Standard-Verteilers – einmal vergeben, dann fest.
     *
     * Eindeutig unter den Katalog-Typen, nicht unter allen Anlagen: dieselbe
     * „Energiesäule“ als Typ und als Feststromanschluss sind zwei Dinge, und
     * zwei Installationen sollen für denselben Typnamen denselben Schlüssel
     * finden.
     */
    public static function typSchluessel(int $vorlageId): string
    {
        $zeile = Database::first('SELECT name, sync_key FROM objekte WHERE id = ? AND ist_vorlage = 1', [$vorlageId]);
        if ($zeile === null) {
            return '';
        }
        if ((string) $zeile['sync_key'] !== '') {
            return (string) $zeile['sync_key'];
        }
        $basis = LocationBundle::schluessel((string) $zeile['name'], 'typ-' . $vorlageId);
        $neu = $basis;
        for ($i = 2; Database::first('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ? AND id <> ?', [$neu, $vorlageId]) !== null; $i++) {
            $neu = $basis . '-' . $i;
        }
        Database::execute('UPDATE objekte SET sync_key = ? WHERE id = ?', [$neu, $vorlageId]);
        return $neu;
    }

    /** Schlüssel eines Inventarstücks – aus seiner Inventarnummer, einmal vergeben. */
    public static function stueckSchluessel(int $inventarId): string
    {
        $zeile = Database::first('SELECT kennung, sync_key FROM inventar WHERE id = ?', [$inventarId]);
        if ($zeile === null) {
            return '';
        }
        if ((string) $zeile['sync_key'] !== '') {
            return (string) $zeile['sync_key'];
        }
        $basis = LocationBundle::schluessel((string) $zeile['kennung'], 'stueck-' . $inventarId);
        $neu = $basis;
        for ($i = 2; Database::first('SELECT id FROM inventar WHERE sync_key = ? AND id <> ?', [$neu, $inventarId]) !== null; $i++) {
            $neu = $basis . '-' . $i;
        }
        Database::execute('UPDATE inventar SET sync_key = ? WHERE id = ?', [$neu, $inventarId]);
        return $neu;
    }

    // ------------------------------------------------------------ Lesen

    /** Ein Standard-Verteiler als Datei – oder null, wenn es ihn nicht gibt. */
    public static function typLesen(int $vorlageId): ?array
    {
        $benutzt = [];
        $typ = self::typInhalt($vorlageId, $benutzt);
        if ($typ === null) {
            return null;
        }
        return self::kopf('typ', self::typSchluessel($vorlageId)) + [
            'typ'          => $typ,
            'steckertypen' => LocationBundle::steckertypenFuerDatei($benutzt),
        ];
    }

    /**
     * Nur der Inhalt eines Standard-Verteilers (`typ` der Datei) – ohne Kopf
     * und ohne einen Schlüssel zu vergeben. Zum Vergleichen, ohne dabei
     * etwas zu ändern (der Katalog-Import findet einen Typ ohne Schlüssel
     * über seinen Namen; ein dabei vergebener Schlüssel ließe ihn doppelt
     * anlegen).
     *
     * @param array<int,array<string,mixed>> $benutzt sammelt die vorkommenden Steckertypen
     */
    public static function typInhalt(int $vorlageId, array &$benutzt = []): ?array
    {
        $v = AnlagenService::find($vorlageId);
        if ($v === null || (int) $v['ist_vorlage'] !== 1) {
            return null;
        }
        $belegung = LocationBundle::belegungLesen($vorlageId, AnlagenService::steckertypenNachId(), $benutzt);
        return [
            'art'                => (string) $v['art'],
            'fest'               => (int) $v['fest'] === 1,
            'name'               => (string) $v['name'],
            'hersteller'         => (string) $v['hersteller'],
            'modell'             => (string) $v['modell'],
            'nennleistung_kva'   => $v['nennleistung_kva'] !== null ? (float) $v['nennleistung_kva'] : null,
            'nennstrom_a'        => $v['nennstrom_a'] !== null ? (float) $v['nennstrom_a'] : null,
            'spannung_v'         => (int) $v['spannung_v'],
            'phasen'             => (int) $v['phasen'],
            'eigenschaften'      => $v['eigenschaften'],
            'steuerung_gesperrt' => (int) $v['steuerung_gesperrt'] === 1,
            'notizen'            => (string) ($v['notizen'] ?? ''),
            'schutzorgane'       => $belegung['schutzorgane'],
            'anschluesse'        => $belegung['anschluesse'],
        ];
    }

    /** Ein Inventarstück als Datei – oder null. */
    public static function stueckLesen(int $inventarId): ?array
    {
        $i = Database::first('SELECT * FROM inventar WHERE id = ?', [$inventarId]);
        if ($i === null) {
            return null;
        }
        $stecker = AnlagenService::steckertypenNachId();
        $typ = $i['steckertyp_id'] !== null ? ($stecker[(int) $i['steckertyp_id']] ?? null) : null;
        $vorlage = $i['vorlage_id'] !== null ? self::typSchluessel((int) $i['vorlage_id']) : '';
        // Sensordaten nur, wo es welche gibt: Verteiler und Zwischenstecker
        // behalten so ihren Fingerabdruck aus der Zeit vor den Tanksensoren.
        $eigenschaften = TankService::sensordaten($i['eigenschaften'] ?? null);
        $stueck = [
            'stueck' => [
                'art'          => (string) $i['art'],
                'kennung'      => (string) $i['kennung'],
                'name'         => (string) $i['name'],
                'typ'          => $vorlage !== '' ? $vorlage : null,
                'stecker'      => $typ !== null ? (string) $typ['schluessel'] : null,
                'seriennummer' => (string) $i['seriennummer'],
                'defekt'       => (int) $i['defekt'] === 1,
                'notizen'      => (string) ($i['notizen'] ?? ''),
                'messgeraete'  => array_map(static fn ($g) => [
                    'geraet'      => (string) $g['geraet'],
                    'kanal'       => (string) $g['kanal'],
                    'stelle'      => (string) $g['stelle'],
                    'bezeichnung' => (string) $g['bezeichnung'],
                ], InventarService::messgeraete($inventarId)),
            ],
            'steckertypen' => LocationBundle::steckertypenFuerDatei($typ !== null ? [(int) $typ['id'] => $typ] : []),
        ];
        if ($eigenschaften !== []) {
            $stueck['stueck']['eigenschaften'] = $eigenschaften;
        }
        return self::kopf('inventar', self::stueckSchluessel($inventarId)) + $stueck;
    }

    /** @return array{format:int,art:string,schluessel:string,stand:string,von:string} */
    private static function kopf(string $bereich, string $schluessel): array
    {
        return [
            'format'     => self::FORMAT,
            'art'        => self::BEREICHE[$bereich]['art'],
            'schluessel' => $schluessel,
            'stand'      => gmdate('c'),
            'von'        => (string) Config::get('app.name', 'PowerManagement'),
        ];
    }

    /**
     * Alle Verteiler dieser Installation als Dateien.
     *
     * @return array{typ:array<string,array<string,mixed>>,inventar:array<string,array<string,mixed>>}
     *         je Bereich: schlüssel => [id, titel, daten, hash, synced_hash, synced_at]
     */
    public static function hier(): array
    {
        $out = ['typ' => [], 'inventar' => []];
        foreach (Database::select('SELECT id, name, synced_hash, synced_at FROM objekte WHERE ist_vorlage = 1 ORDER BY name') as $v) {
            $daten = self::typLesen((int) $v['id']);
            if ($daten === null) {
                continue;
            }
            $out['typ'][$daten['schluessel']] = [
                'id' => (int) $v['id'], 'titel' => (string) $v['name'], 'daten' => $daten, 'hash' => self::fingerabdruck($daten),
                'synced_hash' => (string) $v['synced_hash'], 'synced_at' => (string) ($v['synced_at'] ?? ''),
            ];
        }
        foreach (Database::select('SELECT id, kennung, name, synced_hash, synced_at FROM inventar ORDER BY kennung') as $i) {
            $daten = self::stueckLesen((int) $i['id']);
            if ($daten === null) {
                continue;
            }
            $out['inventar'][$daten['schluessel']] = [
                'id' => (int) $i['id'], 'titel' => (string) $i['kennung'], 'daten' => $daten, 'hash' => self::fingerabdruck($daten),
                'synced_hash' => (string) $i['synced_hash'], 'synced_at' => (string) ($i['synced_at'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------ Vergleichen

    /** Der Inhalt einer Datei – ohne Kopf, Stand und Absender. */
    public static function inhalt(array $daten): array
    {
        foreach (self::BEREICHE as $b) {
            if (($daten['art'] ?? '') === $b['art']) {
                return is_array($daten[$b['inhalt']] ?? null) ? $daten[$b['inhalt']] : [];
            }
        }
        return [];
    }

    /**
     * Fingerabdruck des Inhalts.
     *
     * Schlüssel in Objekten zählen nicht in ihrer Reihenfolge (eine von Hand
     * umsortierte Datei ist dieselbe), ebenso wenig die Reihenfolge der
     * Anschlüsse – die werden über „richtung:nr“ angesprochen. Die übrigen
     * Listen schon (Schutzorgane verweisen über ihre Stelle aufeinander).
     */
    public static function fingerabdruck(array $daten): string
    {
        return hash('sha256', (string) json_encode(self::geordnet(self::normiert(self::inhalt($daten))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Anschlüsse in der Reihenfolge, in der sie hier gelesen werden: Richtung, dann Nummer. */
    private static function normiert(array $inhalt): array
    {
        if (isset($inhalt['anschluesse']) && is_array($inhalt['anschluesse'])) {
            usort($inhalt['anschluesse'], static fn ($a, $b): int => [(string) ($a['richtung'] ?? ''), (int) ($a['nr'] ?? 0)]
                <=> [(string) ($b['richtung'] ?? ''), (int) ($b['nr'] ?? 0)]);
        }
        return $inhalt;
    }

    /** Maps nach Schlüssel sortiert, Listen wie sie sind – siehe Fingerabdruck. */
    private static function geordnet(mixed $wert): mixed
    {
        return Fingerabdruck::geordnet($wert);
    }

    /**
     * Was sich zwischen zwei Ständen unterscheidet – als Feldnamen.
     *
     * @return list<string>
     */
    public static function unterschiede(?array $a, ?array $b): array
    {
        if ($a === null || $b === null) {
            return [];
        }
        $x = self::normiert(self::inhalt($a));
        $y = self::normiert(self::inhalt($b));
        $out = [];
        foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $feld) {
            if (json_encode(self::geordnet($x[$feld] ?? null)) !== json_encode(self::geordnet($y[$feld] ?? null))) {
                $out[] = self::FELDNAMEN[$feld] ?? (string) $feld;
            }
        }
        return $out;
    }

    /**
     * Eine Zeile für die Übersicht: was steckt in dem Verteiler?
     *
     * @param array<string,string> $typNamen Schlüssel eines Standard-Verteilers => Name
     */
    public static function kurzbeschreibung(array $daten, array $typNamen = []): string
    {
        $inhalt = self::inhalt($daten);
        if (($daten['art'] ?? '') === self::BEREICHE['typ']['art']) {
            $zahl = [];
            foreach ((array) ($inhalt['anschluesse'] ?? []) as $a) {
                if (($a['richtung'] ?? '') === 'abgang') {
                    $zahl[(string) ($a['stecker'] ?? '')] = ($zahl[(string) ($a['stecker'] ?? '')] ?? 0) + 1;
                }
            }
            $namen = [];
            foreach ((array) ($daten['steckertypen'] ?? []) as $t) {
                $namen[(string) ($t['schluessel'] ?? '')] = (string) ($t['name'] ?? '');
            }
            $teile = [];
            foreach ($zahl as $k => $n) {
                $teile[] = $n . ' × ' . ($namen[$k] ?? $k);
            }
            $art = AnlagenService::ARTEN[$inhalt['art'] ?? '']['name'] ?? (string) ($inhalt['art'] ?? '');
            $modell = is_scalar($inhalt['modell'] ?? null) && (string) $inhalt['modell'] !== '' ? ' · ' . $inhalt['modell'] : '';
            if (is_string($inhalt['art'] ?? null) && !AnlagenService::elektrisch($inhalt['art'])) {
                // Ein Tank: „Tank · QUADRO-AG 3000 · 3.000 l · Diesel · Quader“ statt Anschlüssen
                $eig = is_array($inhalt['eigenschaften'] ?? null) ? $inhalt['eigenschaften'] : [];
                return $art . $modell . ' · ' . TankService::kurzform(['eigenschaften' => $eig]);
            }
            return trim($art . $modell
                . ($teile !== [] ? ' · ' . implode(', ', $teile) : '')
                . ' · ' . count((array) ($inhalt['schutzorgane'] ?? [])) . ' Schutzorgane');
        }
        $shellys = count((array) ($inhalt['messgeraete'] ?? []));
        $typ = '';
        if (($inhalt['art'] ?? '') === 'tanksensor' && is_array($inhalt['eigenschaften'] ?? null) && $inhalt['eigenschaften'] !== []) {
            $typ = TankRechnung::sensorKurz(TankService::sensordaten($inhalt['eigenschaften']));
        } elseif (is_string($inhalt['typ'] ?? null) && $inhalt['typ'] !== '') {
            $typ = $typNamen[$inhalt['typ']] ?? $inhalt['typ'];
        } elseif (is_string($inhalt['stecker'] ?? null)) {
            $typ = $inhalt['stecker'];
            foreach ((array) ($daten['steckertypen'] ?? []) as $t) {
                if (($t['schluessel'] ?? '') === $inhalt['stecker']) {
                    $typ = (string) ($t['name'] ?? $typ);
                }
            }
        }
        return (InventarService::ARTEN[$inhalt['art'] ?? ''] ?? (string) ($inhalt['art'] ?? ''))
            . ($typ !== '' ? ' ' . $typ : '')
            . (($inhalt['name'] ?? '') !== '' ? ' · ' . $inhalt['name'] : '')
            . ' · ' . ($shellys === 1 ? '1 Messkanal' : $shellys . ' Messkanäle')
            . (!empty($inhalt['defekt']) ? ' · defekt' : '');
    }

    /** Ist die Datei eine gültige Datei dieses Bereichs? Sonst der Grund. */
    public static function fehler(string $bereich, mixed $daten): ?string
    {
        if (!is_array($daten) || ($daten['art'] ?? '') !== (self::BEREICHE[$bereich]['art'] ?? '')) {
            return 'Die Datei ist kein ' . (self::BEREICHE[$bereich]['name'] ?? 'Verteiler') . '.';
        }
        if ((int) ($daten['format'] ?? 0) > self::FORMAT) {
            return 'Die Datei stammt aus einer neueren PowerManagement-Fassung – erst hier aktualisieren.';
        }
        if (self::inhalt($daten) === []) {
            return 'Der Datei fehlt der Inhalt.';
        }
        return null;
    }

    // ------------------------------------------------------------ Anwenden

    /**
     * Einen Standard-Verteiler aus einer Datei übernehmen.
     *
     * Gefunden wird er über seinen Schlüssel, sonst über Art und Namen (ein
     * Typ, der hier und dort unabhängig angelegt wurde, wird nicht doppelt).
     * Anschlüsse und Schutzorgane stehen danach so da wie in der Datei.
     * Anlagen, die früher aus dem Typ entstanden sind, bleiben, wie sie sind
     * – sie sind Kopien und werden einzeln angepasst. Messpunkte einer Datei
     * bleiben weg (siehe unten).
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function typAnwenden(string $schluessel, array $daten): array
    {
        $fehler = self::fehler('typ', $daten);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $t = self::inhalt($daten);
        if (!isset(AnlagenService::ARTEN[$t['art'] ?? ''])) {
            return ['ok' => false, 'message' => 'Unbekannte Art „' . (string) ($t['art'] ?? '') . '“.'];
        }
        $vorhanden = Database::first('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$schluessel])
            ?? Database::first("SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = '' AND art = ? AND LOWER(name) = LOWER(?)", [(string) $t['art'], (string) ($t['name'] ?? '')]);
        $ergebnis = AnlagenService::speichern($vorhanden !== null ? (int) $vorhanden['id'] : null, [
            'art' => (string) $t['art'], 'name' => (string) ($t['name'] ?? $schluessel), 'ist_vorlage' => 1,
            'hersteller' => (string) ($t['hersteller'] ?? ''), 'modell' => (string) ($t['modell'] ?? ''),
            'nennleistung_kva' => $t['nennleistung_kva'] ?? '', 'nennstrom_a' => $t['nennstrom_a'] ?? '',
            'spannung_v' => $t['spannung_v'] ?? 400, 'phasen' => $t['phasen'] ?? 3,
            'eigenschaften' => (array) ($t['eigenschaften'] ?? []), 'steuerung_gesperrt' => !empty($t['steuerung_gesperrt']),
            'notizen' => (string) ($t['notizen'] ?? ''), 'fest' => !empty($t['fest']) ? 1 : 0, 'ohne_anschluesse' => true,
        ]);
        if (!$ergebnis['ok']) {
            return $ergebnis;
        }
        $id = (int) $ergebnis['id'];
        // Ein Katalog-Typ misst nichts: Messpunkte gehören zu einer Anlage, die
        // irgendwo steht, und entstehen nur über den MesspunktService (mit
        // Gültigkeit und Audit). Ein Messpunkt aus einer Datei am Typ zählte
        // das Gerät still als zugeordnet und verschöbe die Rechte daran
        // (Zugriff::objekteZuGeraet).
        LocationBundle::belegungErsetzen($id, array_diff_key($t, ['messpunkte' => true]),
            LocationBundle::steckertypenSichern((array) ($daten['steckertypen'] ?? [])));
        Database::execute('UPDATE objekte SET sync_key = ? WHERE id = ?', [$schluessel, $id]);
        return ['ok' => true, 'message' => 'Standard-Verteiler „' . (string) ($t['name'] ?? $schluessel) . '“ übernommen.', 'id' => $id];
    }

    /**
     * Ein Inventarstück aus einer Datei übernehmen.
     *
     * Gefunden wird es über seinen Schlüssel, sonst über die Inventarnummer.
     * Steckt es gerade irgendwo und haben sich seine Shellys geändert,
     * messen ab jetzt die neuen dort (wie beim Bearbeiten von Hand).
     *
     * @param callable(string):?int $typ Schlüssel des Standard-Verteilers → ID hier (holt ihn nötigenfalls)
     * @return array{ok:bool,message:string,id?:int,hinweis:string}
     */
    public static function stueckAnwenden(string $schluessel, array $daten, callable $typ): array
    {
        $fehler = self::fehler('inventar', $daten);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler, 'hinweis' => ''];
        }
        $s = self::inhalt($daten);
        $kennung = trim((string) ($s['kennung'] ?? ''));
        $vorhanden = Database::first('SELECT id FROM inventar WHERE sync_key = ?', [$schluessel])
            ?? ($kennung !== '' ? Database::first("SELECT id FROM inventar WHERE sync_key = '' AND LOWER(kennung) = LOWER(?)", [$kennung]) : null);
        $hinweis = '';
        $vorlageId = null;
        if (($s['art'] ?? '') === 'verteilung' && is_string($s['typ'] ?? null) && $s['typ'] !== '') {
            $vorlageId = $typ($s['typ']);
            if ($vorlageId === null) {
                $hinweis = $kennung . ': Den Typ „' . $s['typ'] . '“ gibt es hier nicht – das Stück steht ohne Typ da.';
            }
        }
        $stecker = LocationBundle::steckertypenSichern((array) ($daten['steckertypen'] ?? []));
        $ergebnis = InventarService::speichern($vorhanden !== null ? (int) $vorhanden['id'] : null, [
            'art' => (string) ($s['art'] ?? 'verteilung'), 'kennung' => $kennung, 'name' => (string) ($s['name'] ?? ''),
            'vorlage_id' => $vorlageId ?? 0,
            'steckertyp_id' => is_string($s['stecker'] ?? null) ? ($stecker[$s['stecker']] ?? 0) : 0,
            'seriennummer' => (string) ($s['seriennummer'] ?? ''), 'defekt' => !empty($s['defekt']),
            'notizen' => (string) ($s['notizen'] ?? ''), 'messgeraete' => array_values((array) ($s['messgeraete'] ?? [])),
            'eigenschaften' => is_array($s['eigenschaften'] ?? null) ? $s['eigenschaften'] : [],
        ]);
        if (!$ergebnis['ok']) {
            return ['ok' => false, 'message' => $kennung . ': ' . $ergebnis['message'], 'hinweis' => ''];
        }
        $id = (int) $ergebnis['id'];
        Database::execute('UPDATE inventar SET sync_key = ? WHERE id = ?', [$schluessel, $id]);
        return ['ok' => true, 'message' => $ergebnis['message'], 'id' => $id, 'hinweis' => $hinweis];
    }
}
