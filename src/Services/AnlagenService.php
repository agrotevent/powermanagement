<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Audit;
use App\Support\Database;

/**
 * Anlagen und ihre Anschlüsse.
 *
 * Vier Arten, eine Tabelle: Stromanschlüsse (Hausanschluss,
 * Baustromverteiler des Netzbetreibers), Generatoren, Wechselrichter und
 * Verteilungen. Was sie unterscheidet, steht in ART und FELDER – die
 * Gemeinsamkeiten (Ort, Anschlüsse, Messpunkte, Kabel) sind dieselben, und
 * nur so lässt sich ein Kabel von einem Generator an eine Verteilung und
 * von dort an die nächste ziehen, ohne je Kombination eigene Wege.
 *
 * Quer dazu steht, ob eine Anlage **fest installiert** ist (`fest`):
 * Feststromanschlüsse – Hausanschluss, Energiesäule, Wandverteiler, fest
 * eingebaute Hallenverteilung – gehören zur Location und gehen beim
 * Location-Abgleich ins gemeinsame Repository. Mobile Anlagen gehören zur
 * Veranstaltung und bleiben hier. Stromanschlüsse sind von Haus aus fest,
 * alles andere mobil; umstellen lässt sich das je Anlage.
 *
 * Anschlüsse werden einzeln geführt, eine Zeile je Buchse bzw. Stecker.
 * Die Oberfläche arbeitet trotzdem mit Stückzahlen je Steckertyp
 * („4 × CEE 16 A, 6 × Schuko“) – anzahlSetzen() übersetzt das und nimmt
 * dabei nie einen Anschluss weg, an dem ein Kabel hängt.
 */
final class AnlagenService
{
    public const ARTEN = [
        'anschluss'      => ['name' => 'Stromanschluss', 'mehrzahl' => 'Stromanschlüsse', 'kuerzel' => 'A', 'farbe' => '#0369a1'],
        'generator'      => ['name' => 'Generator',      'mehrzahl' => 'Generatoren',     'kuerzel' => 'G', 'farbe' => '#b45309'],
        'wechselrichter' => ['name' => 'Wechselrichter', 'mehrzahl' => 'Wechselrichter',  'kuerzel' => 'W', 'farbe' => '#7c3aed'],
        'verteilung'     => ['name' => 'Verteilung',     'mehrzahl' => 'Verteilungen',    'kuerzel' => 'V', 'farbe' => '#15803d'],
    ];

    public const STATUS = [
        'geplant'    => 'geplant',
        'aufgebaut'  => 'aufgebaut',
        'in_betrieb' => 'in Betrieb',
        'abgebaut'   => 'abgebaut',
        'defekt'     => 'defekt',
    ];

    /**
     * Zusätzliche Angaben je Art (Spalte `eigenschaften`, JSON).
     * Schlüssel => [Beschriftung, Typ (text|zahl|auswahl), Einheit/Auswahl]
     *
     * Nur was hier steht, wird gespeichert (eigenschaftenFiltern) – ein
     * Schlüssel, der hier fehlt, ginge beim ersten Bearbeiten verloren. Was
     * ein Katalog-Import mitbringt (Artikelnummer, Gewicht …), steht deshalb
     * auch hier.
     */
    public const FELDER = [
        'anschluss' => [
            'anschlussart'  => ['Art des Anschlusses', 'auswahl', ['Hausanschluss', 'Energiesäule', 'Wandverteiler', 'Wanddose', 'Unterflur / Bodentank',
                'Unterverteilung Gebäude', 'Baustromverteiler', 'Landstrom', 'Sonstiges']],
            'absicherung_a' => ['Vorsicherung', 'zahl', 'A'],
            'zaehlernummer' => ['Zählernummer', 'text', ''],
            'netzbetreiber' => ['Netzbetreiber / Ansprechpartner', 'text', ''],
        ],
        'generator' => [
            'artikelnummer' => ['Artikelnummer', 'text', ''],
            'steuerung'     => ['Steuerung', 'text', ''],
            'kraftstoff'    => ['Kraftstoff', 'auswahl', ['Diesel', 'HVO', 'Gas', 'Benzin']],
            'tank_l'        => ['Tankinhalt', 'zahl', 'l'],
            'verbrauch_l_h' => ['Verbrauch bei Volllast', 'zahl', 'l/h'],
            'schallpegel'   => ['Schallleistungspegel', 'zahl', 'dB(A)'],
            'gewicht_kg'    => ['Gewicht leer', 'zahl', 'kg'],
            'abmessungen'   => ['Abmessungen L × B × H', 'text', 'mm'],
        ],
        'wechselrichter' => [
            'system'         => ['System', 'text', ''],
            'batterie_kwh'   => ['Batteriekapazität', 'zahl', 'kWh'],
            'batterie_typ'   => ['Batterietyp', 'text', ''],
            'pv_kwp'         => ['PV-Leistung', 'zahl', 'kWp'],
            'portal_id'      => ['VRM-Portal-ID', 'text', ''],
        ],
        'verteilung' => [
            'artikelnummer'  => ['Artikelnummer', 'text', ''],
            'gehaeuse'       => ['Gehäuse', 'text', ''],
            'schutzart'      => ['Schutzart', 'text', ''],
            'zuleitung'      => ['Zuleitung', 'text', ''],
            'gewicht_kg'     => ['Gewicht', 'zahl', 'kg'],
            'fi_typ'         => ['FI-Schutz (gesamt)', 'text', ''],
            'pruefung_bis'   => ['Nächste Prüfung (DGUV V3)', 'text', ''],
        ],
    ];

    /**
     * Welche Anschlüsse eine neue Anlage bekommt, bevor jemand etwas
     * anpasst. Nur ein Vorschlag – die Stückzahlen stehen danach frei.
     *
     * @var array<string,array{eingang:array<string,int>,abgang:array<string,int>}>
     */
    public const VORSCHLAG = [
        'anschluss'      => ['eingang' => [], 'abgang' => ['cee125' => 1]],
        'generator'      => ['eingang' => [], 'abgang' => ['powerlock' => 1, 'cee63' => 1, 'cee32' => 1, 'cee16' => 2, 'schuko' => 2]],
        'wechselrichter' => ['eingang' => ['cee32' => 1], 'abgang' => ['cee32' => 1, 'cee16' => 2, 'schuko' => 2]],
        'verteilung'     => ['eingang' => ['cee63' => 1], 'abgang' => ['cee32' => 2, 'cee16' => 4, 'schuko' => 6]],
    ];

    /** Ist eine neue Anlage dieser Art fest installiert, solange niemand etwas anderes sagt? */
    public static function festVorgabe(string $art): bool
    {
        return $art === 'anschluss';
    }

    /** „Feststromanschluss“, „Verteilung (fest)“ oder schlicht die Art. */
    public static function artAnzeige(array $objekt): string
    {
        $name = self::ARTEN[$objekt['art']]['name'] ?? (string) $objekt['art'];
        if ((int) ($objekt['fest'] ?? 0) !== 1) {
            return $name;
        }
        return $objekt['art'] === 'anschluss' ? 'Feststromanschluss' : $name . ' (fest)';
    }

    // ------------------------------------------------------------ Steckertypen

    /**
     * Der Grundbestand des Steckverbinder-Katalogs.
     *
     * Die sechs Typen, die bei jeder Veranstaltung vorkommen, stehen von
     * Anfang an darin. Eigene (CEE 16 A blau, Harting, Socapex …) kommen in
     * der Oberfläche dazu.
     */
    public const STANDARD_STECKER = [
        ['powerlock', 'Powerlock',       400, 3, 400, '#111827'],
        ['cee125',    'CEE 125 A',       125, 3, 400, '#dc2626'],
        ['cee63',     'CEE 63 A',         63, 3, 400, '#dc2626'],
        ['cee32',     'CEE 32 A',         32, 3, 400, '#dc2626'],
        ['cee16',     'CEE 16 A',         16, 3, 400, '#dc2626'],
        ['schuko',    'Schuko',           16, 1, 230, '#475569'],
    ];

    public static function grundbestand(): int
    {
        $neu = 0;
        foreach (self::STANDARD_STECKER as $i => [$schluessel, $name, $strom, $phasen, $spannung, $farbe]) {
            if (Database::first('SELECT id FROM steckertypen WHERE schluessel = ?', [$schluessel]) !== null) {
                continue;
            }
            Database::insert('steckertypen', [
                'schluessel'   => $schluessel,
                'name'         => $name,
                'strom_a'      => $strom,
                'phasen'       => $phasen,
                'spannung_v'   => $spannung,
                'farbe'        => $farbe,
                'reihenfolge'  => ($i + 1) * 10,
                'ist_standard' => 1,
                'created_at'   => gmdate('c'),
            ]);
            $neu++;
        }
        return $neu;
    }

    /** @return list<array<string,mixed>> */
    public static function steckertypen(): array
    {
        return Database::select('SELECT * FROM steckertypen ORDER BY reihenfolge, name');
    }

    /** @return array<int,array<string,mixed>> id => Steckertyp */
    public static function steckertypenNachId(): array
    {
        $out = [];
        foreach (self::steckertypen() as $typ) {
            $out[(int) $typ['id']] = $typ;
        }
        return $out;
    }

    public static function steckertypSpeichern(?int $id, array $daten): array
    {
        $name = trim((string) ($daten['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'Der Steckertyp braucht einen Namen.'];
        }
        $felder = [
            'name'        => mb_substr($name, 0, 190),
            'strom_a'     => max(0.1, (float) str_replace(',', '.', (string) ($daten['strom_a'] ?? 16))),
            'phasen'      => in_array((int) ($daten['phasen'] ?? 3), [1, 3], true) ? (int) $daten['phasen'] : 3,
            'spannung_v'  => max(1, (int) ($daten['spannung_v'] ?? 400)),
            'farbe'       => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($daten['farbe'] ?? '')) === 1 ? (string) $daten['farbe'] : '#64748b',
            'reihenfolge' => (int) ($daten['reihenfolge'] ?? 100),
            'notizen'     => (string) ($daten['notizen'] ?? ''),
        ];
        if ($id) {
            Database::update('steckertypen', $id, $felder);
            Audit::log('stecker.update', 'steckertyp', (string) $id, ['name' => $name]);
            return ['ok' => true, 'message' => 'Steckertyp gespeichert.', 'id' => $id];
        }
        $schluessel = LocationBundle::schluessel($name, 'stecker');
        while (Database::first('SELECT id FROM steckertypen WHERE schluessel = ?', [$schluessel]) !== null) {
            $schluessel .= '-2';
        }
        $felder['schluessel'] = $schluessel;
        $felder['created_at'] = gmdate('c');
        $id = Database::insert('steckertypen', $felder);
        Audit::log('stecker.create', 'steckertyp', (string) $id, ['name' => $name]);
        return ['ok' => true, 'message' => 'Steckertyp angelegt.', 'id' => $id];
    }

    public static function steckertypLoeschen(int $id): array
    {
        $benutzt = (int) Database::scalar('SELECT COUNT(*) FROM anschluesse WHERE steckertyp_id = ?', [$id]);
        if ($benutzt > 0) {
            return ['ok' => false, 'message' => "Der Steckertyp ist an {$benutzt} Anschlüssen eingetragen und bleibt deshalb stehen."];
        }
        Database::delete('steckertypen', $id);
        Audit::log('stecker.delete', 'steckertyp', (string) $id);
        return ['ok' => true, 'message' => 'Steckertyp gelöscht.'];
    }

    // ------------------------------------------------------------ Anlagen

    public static function find(int $id): ?array
    {
        $objekt = Database::first(
            'SELECT o.*, s.name AS site_name, f.name AS floor_name FROM objekte o
             LEFT JOIN sites s ON s.id = o.site_id LEFT JOIN floors f ON f.id = o.floor_id WHERE o.id = ?',
            [$id]
        );
        return $objekt === null ? null : self::aufbereiten($objekt);
    }

    /**
     * @param array{art?:string,site_id?:int,floor_id?:int,vorlagen?:bool,suche?:string,fest?:?bool} $filter
     * @return list<array<string,mixed>>
     */
    public static function liste(array $filter = []): array
    {
        $sql = 'SELECT o.*, s.name AS site_name, f.name AS floor_name FROM objekte o
                LEFT JOIN sites s ON s.id = o.site_id LEFT JOIN floors f ON f.id = o.floor_id WHERE 1=1';
        $params = [];
        $sql .= !empty($filter['vorlagen']) ? ' AND o.ist_vorlage = 1' : ' AND o.ist_vorlage = 0';
        if (!empty($filter['art']) && isset(self::ARTEN[$filter['art']])) {
            $sql .= ' AND o.art = ?';
            $params[] = $filter['art'];
        }
        if (isset($filter['fest'])) {
            $sql .= ' AND o.fest = ?';
            $params[] = $filter['fest'] ? 1 : 0;
        }
        if (!empty($filter['site_id'])) {
            $sql .= ' AND o.site_id = ?';
            $params[] = (int) $filter['site_id'];
        }
        if (!empty($filter['floor_id'])) {
            $sql .= ' AND o.floor_id = ?';
            $params[] = (int) $filter['floor_id'];
        }
        if (!empty($filter['suche'])) {
            $sql .= ' AND (o.name LIKE ? OR o.kennung LIKE ? OR o.modell LIKE ? OR o.seriennummer LIKE ?)';
            $muster = '%' . $filter['suche'] . '%';
            array_push($params, $muster, $muster, $muster, $muster);
        }
        $sql .= ' ORDER BY s.name, o.art, o.name';
        return array_map([self::class, 'aufbereiten'], Database::select($sql, $params));
    }

    private static function aufbereiten(array $objekt): array
    {
        $objekt['eigenschaften'] = json_decode((string) ($objekt['eigenschaften'] ?? ''), true) ?: [];
        $objekt['fest'] = (int) ($objekt['fest'] ?? 0);
        $objekt['art_name'] = self::ARTEN[$objekt['art']]['name'] ?? $objekt['art'];
        $objekt['art_anzeige'] = self::artAnzeige($objekt);
        return $objekt;
    }

    /**
     * Die Eigenschaften, die zu dieser Art gehören – so, wie sie gespeichert
     * werden: Zahlen als Zahl (auch mit Komma), Text gekürzt, Leeres weg.
     *
     * @param array<string,mixed> $roh
     * @return array<string,float|string>
     */
    public static function eigenschaftenFiltern(string $art, array $roh): array
    {
        $eigenschaften = [];
        foreach (self::FELDER[$art] ?? [] as $schluessel => [$_beschriftung, $typ]) {
            $wert = $roh[$schluessel] ?? '';
            $wert = is_scalar($wert) ? trim((string) $wert) : '';
            if ($wert === '') {
                continue;
            }
            $eigenschaften[$schluessel] = $typ === 'zahl' ? (float) str_replace(',', '.', $wert) : mb_substr($wert, 0, 190);
        }
        return $eigenschaften;
    }

    /**
     * Anlage speichern.
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function speichern(?int $id, array $daten): array
    {
        $art = (string) ($daten['art'] ?? '');
        if (!isset(self::ARTEN[$art])) {
            return ['ok' => false, 'message' => 'Unbekannte Art der Anlage.'];
        }
        $name = trim((string) ($daten['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'Die Anlage braucht einen Namen.'];
        }

        $eigenschaften = self::eigenschaftenFiltern($art, (array) ($daten['eigenschaften'] ?? []));

        $siteId  = (int) ($daten['site_id'] ?? 0) ?: null;
        $floorId = (int) ($daten['floor_id'] ?? 0) ?: null;
        if ($floorId !== null) {
            $etage = Database::first('SELECT site_id FROM floors WHERE id = ?', [$floorId]);
            if ($etage === null) {
                $floorId = null;
            } else {
                // Die Etage bestimmt den Standort – nie umgekehrt widersprüchlich.
                $siteId = (int) $etage['site_id'];
            }
        }

        $zahl = static function (mixed $wert): ?float {
            $wert = trim(str_replace(',', '.', (string) $wert));
            return $wert === '' ? null : (float) $wert;
        };

        $felder = [
            'art'              => $art,
            'name'             => mb_substr($name, 0, 190),
            'kennung'          => mb_substr(trim((string) ($daten['kennung'] ?? '')), 0, 64),
            'site_id'          => $siteId,
            'floor_id'         => $floorId,
            'hersteller'       => mb_substr(trim((string) ($daten['hersteller'] ?? '')), 0, 190),
            'modell'           => mb_substr(trim((string) ($daten['modell'] ?? '')), 0, 190),
            'seriennummer'     => mb_substr(trim((string) ($daten['seriennummer'] ?? '')), 0, 190),
            'nennleistung_kva' => $zahl($daten['nennleistung_kva'] ?? ''),
            'nennstrom_a'      => $zahl($daten['nennstrom_a'] ?? ''),
            'spannung_v'       => max(1, (int) ($daten['spannung_v'] ?? 400)),
            'phasen'           => (int) ($daten['phasen'] ?? 3) === 1 ? 1 : 3,
            'status'           => isset(self::STATUS[$daten['status'] ?? '']) ? (string) $daten['status'] : 'geplant',
            'eigenschaften'    => json_encode($eigenschaften, JSON_UNESCAPED_UNICODE),
            'steuerung_gesperrt' => !empty($daten['steuerung_gesperrt']) ? 1 : 0,
            'ist_vorlage'      => !empty($daten['ist_vorlage']) ? 1 : 0,
            'notizen'          => (string) ($daten['notizen'] ?? ''),
            'updated_at'       => gmdate('c'),
        ];
        if ($felder['ist_vorlage'] === 1) {
            // Eine Vorlage steht nirgends – sie ist ein Bauplan.
            $felder['site_id'] = $felder['floor_id'] = null;
        }
        // Eine neue Anlage aus einem Katalog-Typ trägt dessen Typ, wenn nichts
        // anderes gesagt ist – daran erkennt der Aufbau, ob ein Verteiler passt.
        if (!$id && (int) ($daten['vorlage_id'] ?? 0) > 0) {
            $typ = Database::first('SELECT hersteller, modell, eigenschaften FROM objekte WHERE id = ? AND ist_vorlage = 1', [(int) $daten['vorlage_id']]);
            if ($typ !== null && $felder['modell'] === '') {
                $felder['modell'] = (string) $typ['modell'];
                $felder['hersteller'] = $felder['hersteller'] !== '' ? $felder['hersteller'] : (string) $typ['hersteller'];
            }
            // Ebenso die Eigenschaften des Typs (Artikelnummer, Gewicht,
            // Gehäuse …), soweit das Formular nichts anderes sagt.
            if ($typ !== null) {
                $eigenschaften += self::eigenschaftenFiltern($art, (array) (json_decode((string) ($typ['eigenschaften'] ?? ''), true) ?: []));
                $felder['eigenschaften'] = json_encode($eigenschaften, JSON_UNESCAPED_UNICODE);
            }
        }
        // Fest oder mobil: was gesagt wird, gilt. Sonst bleibt es beim
        // Bearbeiten, wie es war, und eine neue Anlage übernimmt es von
        // ihrer Vorlage bzw. der Vorgabe ihrer Art.
        $fest = $daten['fest'] ?? null;
        if ($fest !== null && $fest !== '') {
            $felder['fest'] = !empty($fest) ? 1 : 0;
        } elseif (!$id) {
            $vorlageFest = (int) ($daten['vorlage_id'] ?? 0) > 0
                ? Database::scalar('SELECT fest FROM objekte WHERE id = ? AND ist_vorlage = 1', [(int) $daten['vorlage_id']]) : null;
            $felder['fest'] = $vorlageFest !== null && $vorlageFest !== false ? (int) $vorlageFest : (self::festVorgabe($art) ? 1 : 0);
        }

        if ($id) {
            $alt = self::find($id);
            if ($alt === null) {
                return ['ok' => false, 'message' => 'Diese Anlage gibt es nicht.'];
            }

            // Wandert die Anlage auf eine andere Etage, gilt ihr Punkt auf
            // dem alten Plan dort nichts mehr.
            if ((int) ($alt['floor_id'] ?? 0) !== (int) ($felder['floor_id'] ?? 0)) {
                $felder['plan_x'] = null;
                $felder['plan_y'] = null;
            }
            Database::update('objekte', $id, $felder);
            Audit::log('anlage.update', 'objekt', (string) $id, ['name' => $name]);
            $meldung = self::artAnzeige($felder + ['fest' => $alt['fest']]) . ' gespeichert.';
            // Wird eine Anlage mobil, werden es die fest verlegten Kabel an
            // ihr auch – ein festes Kabel endet nur an Feststromanschlüssen.
            if ($alt['fest'] === 1 && ($felder['fest'] ?? 1) === 0) {
                $mobil = Database::execute('UPDATE leitungen SET fest = 0, updated_at = ? WHERE fest = 1 AND (von_objekt_id = ? OR zu_objekt_id = ?)', [gmdate('c'), $id, $id]);
                if ($mobil > 0) {
                    $meldung .= ' ' . ($mobil === 1 ? 'Das fest verlegte Kabel daran ist' : $mobil . ' fest verlegte Kabel daran sind') . ' jetzt mobil.';
                }
            }
            if ($alt['art'] !== $art) {
                $meldung .= ' Die Art ist jetzt „' . self::ARTEN[$art]['name'] . '“.';
            }
            return ['ok' => true, 'message' => $meldung, 'id' => $id];
        }

        $felder['created_at'] = gmdate('c');
        if (isset($daten['plan_x'], $daten['plan_y']) && $felder['floor_id'] !== null) {
            $felder['plan_x'] = self::klemme((float) $daten['plan_x']);
            $felder['plan_y'] = self::klemme((float) $daten['plan_y']);
        }
        $id = Database::insert('objekte', $felder);
        Audit::log('anlage.create', 'objekt', (string) $id, ['name' => $name, 'art' => $art]);

        // Anschlüsse: aus einer Vorlage oder der Vorschlag je Art.
        $vorlageId = (int) ($daten['vorlage_id'] ?? 0);
        if ($vorlageId > 0 && ($vorlage = self::find($vorlageId)) !== null) {
            self::anschluesseKopieren($vorlageId, $id);
        } elseif (empty($daten['ohne_anschluesse'])) {
            self::vorschlagAnlegen($id, $art);
        }

        return ['ok' => true, 'message' => self::artAnzeige($felder) . ' angelegt.', 'id' => $id];
    }

    public static function loeschen(int $id): array
    {
        $objekt = self::find($id);
        if ($objekt === null) {
            return ['ok' => false, 'message' => 'Diese Anlage gibt es nicht.'];
        }
        // Kabel verlieren ihr Ende an dieser Anlage, bleiben als Kabelweg aber
        // eingezeichnet – sie liegen ja noch dort.
        Database::execute('UPDATE leitungen SET von_objekt_id = NULL, von_anschluss_id = NULL WHERE von_objekt_id = ?', [$id]);
        Database::execute('UPDATE leitungen SET zu_objekt_id = NULL, zu_anschluss_id = NULL WHERE zu_objekt_id = ?', [$id]);
        BereichService::objektEntfernt($id);
        InventarService::objektEntfernt($id);
        Database::execute('DELETE FROM anschluesse WHERE objekt_id = ?', [$id]);
        Database::execute('DELETE FROM schutzorgane WHERE objekt_id = ?', [$id]);
        Database::execute('DELETE FROM messpunkte WHERE objekt_id = ?', [$id]);
        Database::execute("DELETE FROM freigaben WHERE ziel_art = 'objekt' AND ziel_id = ?", [$id]);
        Database::delete('objekte', $id);
        Audit::log('anlage.delete', 'objekt', (string) $id, ['name' => $objekt['name']]);
        return ['ok' => true, 'message' => $objekt['art_name'] . ' „' . $objekt['name'] . '“ gelöscht.'];
    }

    /** Eine Anlage samt Anschlüssen kopieren – etwa die zehnte gleiche Verteilung. */
    public static function kopieren(int $id, string $neuerName = ''): array
    {
        $objekt = self::find($id);
        if ($objekt === null) {
            return ['ok' => false, 'message' => 'Diese Anlage gibt es nicht.'];
        }
        $daten = $objekt;
        $daten['name'] = $neuerName !== '' ? $neuerName : $objekt['name'] . ' (Kopie)';
        $daten['kennung'] = '';
        $daten['seriennummer'] = '';
        $daten['ist_vorlage'] = 0;
        $daten['ohne_anschluesse'] = true;
        $ergebnis = self::speichern(null, $daten);
        if ($ergebnis['ok']) {
            self::anschluesseKopieren($id, (int) $ergebnis['id']);
        }
        return $ergebnis;
    }

    public static function position(int $id, ?int $floorId, ?float $x, ?float $y): array
    {
        $objekt = self::find($id);
        if ($objekt === null) {
            return ['ok' => false, 'message' => 'Diese Anlage gibt es nicht.'];
        }
        if ($floorId === null) {
            Database::update('objekte', $id, ['floor_id' => null, 'plan_x' => null, 'plan_y' => null, 'updated_at' => gmdate('c')]);
            return ['ok' => true, 'message' => 'Aus dem Plan genommen.'];
        }
        $etage = Database::first('SELECT site_id FROM floors WHERE id = ?', [$floorId]);
        if ($etage === null) {
            return ['ok' => false, 'message' => 'Diese Etage gibt es nicht.'];
        }
        Database::update('objekte', $id, [
            'site_id'    => (int) $etage['site_id'],
            'floor_id'   => $floorId,
            'plan_x'     => self::klemme((float) $x),
            'plan_y'     => self::klemme((float) $y),
            'updated_at' => gmdate('c'),
        ]);
        return ['ok' => true, 'message' => 'Position gespeichert.'];
    }

    // ------------------------------------------------------------ Anschlüsse

    /** @return list<array<string,mixed>> Anschlüsse einer Anlage mit Steckertyp und Belegung */
    public static function anschluesse(int $objektId): array
    {
        $rows = Database::select(
            'SELECT a.*, t.name AS typ_name, t.schluessel AS typ_schluessel, t.strom_a, t.phasen AS typ_phasen, t.farbe AS typ_farbe
             FROM anschluesse a JOIN steckertypen t ON t.id = a.steckertyp_id
             WHERE a.objekt_id = ? ORDER BY a.richtung, t.reihenfolge, a.nr',
            [$objektId]
        );
        $belegt = self::belegung($objektId);
        $schutz = SchutzService::nachId($objektId);
        foreach ($rows as &$row) {
            $row['leitung'] = $belegt[(int) $row['id']] ?? null;
            $row['schutz_text'] = $row['schutzorgan_id'] !== null && isset($schutz[(int) $row['schutzorgan_id']])
                ? SchutzService::kette((int) $row['schutzorgan_id'], $schutz)
                : '';
        }
        return $rows;
    }

    /**
     * Welches Kabel an welchem Anschluss hängt.
     *
     * @return array<int,array<string,mixed>> anschluss_id => Leitung mit Gegenstelle
     */
    public static function belegung(int $objektId): array
    {
        $out = [];
        $rows = Database::select(
            "SELECT l.*, ov.name AS von_name, oz.name AS zu_name FROM leitungen l
             LEFT JOIN objekte ov ON ov.id = l.von_objekt_id LEFT JOIN objekte oz ON oz.id = l.zu_objekt_id
             WHERE l.art = 'kabel' AND (l.von_objekt_id = ? OR l.zu_objekt_id = ?)",
            [$objektId, $objektId]
        );
        foreach ($rows as $leitung) {
            if ((int) $leitung['von_objekt_id'] === $objektId && $leitung['von_anschluss_id'] !== null) {
                $out[(int) $leitung['von_anschluss_id']] = $leitung + ['gegenstelle' => $leitung['zu_name'], 'gegenstelle_id' => $leitung['zu_objekt_id']];
            }
            if ((int) $leitung['zu_objekt_id'] === $objektId && $leitung['zu_anschluss_id'] !== null) {
                $out[(int) $leitung['zu_anschluss_id']] = $leitung + ['gegenstelle' => $leitung['von_name'], 'gegenstelle_id' => $leitung['von_objekt_id']];
            }
        }
        return $out;
    }

    /**
     * Stückzahl je Steckertyp und Richtung.
     *
     * @return array{eingang:array<int,int>,abgang:array<int,int>} steckertyp_id => Anzahl
     */
    public static function anzahlen(int $objektId): array
    {
        $out = ['eingang' => [], 'abgang' => []];
        foreach (Database::select(
            'SELECT richtung, steckertyp_id, COUNT(*) AS n FROM anschluesse WHERE objekt_id = ? GROUP BY richtung, steckertyp_id',
            [$objektId]
        ) as $row) {
            $out[$row['richtung'] === 'eingang' ? 'eingang' : 'abgang'][(int) $row['steckertyp_id']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Stückzahlen übernehmen – Anschlüsse anlegen oder wegnehmen.
     *
     * Weggenommen wird von hinten und nur, was frei ist: ein Anschluss, an
     * dem ein Kabel hängt, bleibt stehen, und die Meldung sagt das.
     *
     * @param array{eingang?:array<int|string,int|string>,abgang?:array<int|string,int|string>} $soll
     * @return array{ok:bool,message:string}
     */
    public static function anzahlSetzen(int $objektId, array $soll): array
    {
        $typen = self::steckertypenNachId();
        $ist = self::anzahlen($objektId);
        $belegt = self::belegung($objektId);
        $neu = 0;
        $weg = 0;
        $haengen = [];

        foreach (['eingang', 'abgang'] as $richtung) {
            foreach ((array) ($soll[$richtung] ?? []) as $typId => $anzahl) {
                $typId = (int) $typId;
                if (!isset($typen[$typId])) {
                    continue;
                }
                $anzahl = max(0, min(96, (int) $anzahl));
                $jetzt = $ist[$richtung][$typId] ?? 0;
                for ($i = $jetzt; $i < $anzahl; $i++) {
                    self::anschlussAnlegen($objektId, $richtung, $typId);
                    $neu++;
                }
                if ($anzahl < $jetzt) {
                    $vorhanden = Database::select(
                        'SELECT id, bezeichnung FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr DESC',
                        [$objektId, $richtung, $typId]
                    );
                    $zuViel = $jetzt - $anzahl;
                    foreach ($vorhanden as $anschluss) {
                        if ($zuViel === 0) {
                            break;
                        }
                        if (isset($belegt[(int) $anschluss['id']])) {
                            $haengen[] = $anschluss['bezeichnung'];
                            continue;
                        }
                        Database::delete('anschluesse', (int) $anschluss['id']);
                        Database::execute('UPDATE messpunkte SET anschluss_id = NULL WHERE anschluss_id = ?', [(int) $anschluss['id']]);
                        $zuViel--;
                        $weg++;
                    }
                }
            }
        }

        Audit::log('anlage.anschluesse', 'objekt', (string) $objektId, ['neu' => $neu, 'weg' => $weg]);
        $meldung = sprintf('Anschlüsse übernommen: %d neu, %d entfernt.', $neu, $weg);
        if ($haengen !== []) {
            $meldung .= ' Stehen geblieben, weil ein Kabel daran hängt: ' . implode(', ', $haengen) . '.';
        }
        return ['ok' => true, 'message' => $meldung];
    }

    /** Einen Anschluss anlegen – mit der nächsten freien Nummer und einer Bezeichnung. */
    public static function anschlussAnlegen(int $objektId, string $richtung, int $typId, array $daten = []): int
    {
        $richtung = $richtung === 'eingang' ? 'eingang' : 'abgang';
        $nr = 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(nr), 0) FROM anschluesse WHERE objekt_id = ? AND richtung = ?',
            [$objektId, $richtung]
        );
        $typ = Database::first('SELECT * FROM steckertypen WHERE id = ?', [$typId]);
        $bezeichnung = trim((string) ($daten['bezeichnung'] ?? ''));
        if ($bezeichnung === '') {
            $bezeichnung = ($richtung === 'eingang' ? 'E' : 'X') . $nr;
        }
        return Database::insert('anschluesse', [
            'objekt_id'     => $objektId,
            'richtung'      => $richtung,
            'steckertyp_id' => $typId,
            'nr'            => $nr,
            'bezeichnung'   => mb_substr($bezeichnung, 0, 64),
            'schutzorgan_id'=> isset($daten['schutzorgan_id']) && (int) $daten['schutzorgan_id'] > 0 ? (int) $daten['schutzorgan_id'] : null,
            'phase'         => mb_substr((string) ($daten['phase'] ?? ''), 0, 8),
            'notizen'       => (string) ($daten['notizen'] ?? ''),
            'reihenfolge'   => $nr,
            'created_at'    => gmdate('c'),
        ]);
    }

    /**
     * Einzelne Anschlüsse ändern (Bezeichnung, Schutzorgan, Phase, Notiz).
     *
     * Das Schutzorgan muss zur selben Anlage gehören – ein Abgang in
     * Verteilung A hinter einem RCD in Verteilung B wäre ein Schreibfehler.
     *
     * @param array<int|string,array<string,string>> $zeilen anschluss_id => Felder
     */
    public static function anschluesseAendern(int $objektId, array $zeilen): array
    {
        $geaendert = 0;
        foreach ($zeilen as $id => $felder) {
            $id = (int) $id;
            $anschluss = Database::first('SELECT id FROM anschluesse WHERE id = ? AND objekt_id = ?', [$id, $objektId]);
            if ($anschluss === null || !is_array($felder)) {
                continue;
            }
            $phase = strtoupper(trim((string) ($felder['phase'] ?? '')));
            $schutz = (int) ($felder['schutzorgan_id'] ?? 0);
            if ($schutz > 0 && Database::first('SELECT id FROM schutzorgane WHERE id = ? AND objekt_id = ?', [$schutz, $objektId]) === null) {
                $schutz = 0;
            }
            Database::update('anschluesse', $id, [
                'bezeichnung' => mb_substr(trim((string) ($felder['bezeichnung'] ?? '')), 0, 64),
                'schutzorgan_id' => $schutz > 0 ? $schutz : null,
                'phase'       => in_array($phase, ['L1', 'L2', 'L3'], true) ? $phase : '',
                'notizen'     => (string) ($felder['notizen'] ?? ''),
            ]);
            $geaendert++;
        }
        Audit::log('anlage.anschluesse_details', 'objekt', (string) $objektId, ['zeilen' => $geaendert]);
        return ['ok' => true, 'message' => $geaendert . ' Anschlüsse gespeichert.'];
    }

    public static function vorschlagAnlegen(int $objektId, string $art): void
    {
        $nachSchluessel = [];
        foreach (self::steckertypen() as $typ) {
            $nachSchluessel[(string) $typ['schluessel']] = (int) $typ['id'];
        }
        foreach (['eingang', 'abgang'] as $richtung) {
            foreach (self::VORSCHLAG[$art][$richtung] ?? [] as $schluessel => $anzahl) {
                if (!isset($nachSchluessel[$schluessel])) {
                    continue;
                }
                for ($i = 0; $i < $anzahl; $i++) {
                    self::anschlussAnlegen($objektId, $richtung, $nachSchluessel[$schluessel]);
                }
            }
        }
    }

    /**
     * Anschlüsse und Schutzorgane einer Anlage auf eine andere übertragen –
     * so entsteht aus einem Standard-Verteiler eine platzierte Verteilung
     * mit derselben Belegung.
     */
    public static function anschluesseKopieren(int $vonId, int $nachId): void
    {
        $zuordnung = SchutzService::kopieren($vonId, $nachId);
        foreach (Database::select('SELECT * FROM anschluesse WHERE objekt_id = ? ORDER BY richtung, nr', [$vonId]) as $a) {
            Database::insert('anschluesse', [
                'objekt_id'     => $nachId,
                'richtung'      => $a['richtung'],
                'steckertyp_id' => (int) $a['steckertyp_id'],
                'nr'            => (int) $a['nr'],
                'bezeichnung'   => $a['bezeichnung'],
                'schutzorgan_id'=> $a['schutzorgan_id'] !== null ? ($zuordnung[(int) $a['schutzorgan_id']] ?? null) : null,
                'phase'         => $a['phase'],
                'notizen'       => $a['notizen'],
                'reihenfolge'   => (int) $a['reihenfolge'],
                'created_at'    => gmdate('c'),
            ]);
        }
    }

    /**
     * Eine Anlage auf einen anderen Katalog-Typ umstellen – etwa wenn beim
     * Aufbau statt des geplanten PD 32 ein PD 63 steht. Anschlüsse und
     * Schutzorgane kommen aus dem Katalog; Kabel und Messgeräte bleiben an
     * „ihrem“ Anschluss (gleiche Richtung und Nummer), sonst an der Anlage.
     */
    public static function typUebernehmen(int $objektId, int $vorlageId): array
    {
        $objekt = self::find($objektId);
        $vorlage = self::find($vorlageId);
        if ($objekt === null || $vorlage === null || (int) $vorlage['ist_vorlage'] !== 1) {
            return ['ok' => false, 'message' => 'Anlage oder Katalog-Typ nicht gefunden.'];
        }
        $stelle = [];
        foreach (Database::select('SELECT id, richtung, nr FROM anschluesse WHERE objekt_id = ?', [$objektId]) as $a) {
            $stelle[(int) $a['id']] = $a['richtung'] . ':' . (int) $a['nr'];
        }
        $kabel = [];
        foreach (['von', 'zu'] as $ende) {
            foreach (Database::select("SELECT id, {$ende}_anschluss_id AS a FROM leitungen WHERE {$ende}_objekt_id = ? AND {$ende}_anschluss_id IS NOT NULL", [$objektId]) as $l) {
                $kabel[] = [(int) $l['id'], $ende, $stelle[(int) $l['a']] ?? null];
            }
        }
        $messgeraete = [];
        foreach (Database::select('SELECT id, anschluss_id FROM messpunkte WHERE objekt_id = ? AND anschluss_id IS NOT NULL', [$objektId]) as $m) {
            $messgeraete[(int) $m['id']] = $stelle[(int) $m['anschluss_id']] ?? null;
        }
        Database::execute('UPDATE messpunkte SET schutzorgan_id = NULL WHERE objekt_id = ?', [$objektId]);
        Database::execute('DELETE FROM anschluesse WHERE objekt_id = ?', [$objektId]);
        Database::execute('DELETE FROM schutzorgane WHERE objekt_id = ?', [$objektId]);
        self::anschluesseKopieren($vorlageId, $objektId);
        $neu = [];
        foreach (Database::select('SELECT id, richtung, nr FROM anschluesse WHERE objekt_id = ?', [$objektId]) as $a) {
            $neu[$a['richtung'] . ':' . (int) $a['nr']] = (int) $a['id'];
        }
        foreach ($kabel as [$id, $ende, $alt]) {
            Database::update('leitungen', $id, [$ende . '_anschluss_id' => $alt !== null ? ($neu[$alt] ?? null) : null]);
        }
        foreach ($messgeraete as $id => $alt) {
            Database::update('messpunkte', $id, ['anschluss_id' => $alt !== null ? ($neu[$alt] ?? null) : null]);
        }
        Database::update('objekte', $objektId, [
            'modell' => $vorlage['modell'], 'hersteller' => $vorlage['hersteller'],
            'nennstrom_a' => $vorlage['nennstrom_a'], 'nennleistung_kva' => $vorlage['nennleistung_kva'], 'updated_at' => gmdate('c'),
        ]);
        Audit::log('anlage.typ', 'objekt', (string) $objektId, ['von' => $objekt['modell'], 'nach' => $vorlage['modell']]);
        return ['ok' => true, 'message' => '„' . $objekt['name'] . '“ ist jetzt ' . ($vorlage['modell'] ?: $vorlage['name']) . '.'];
    }

    /**
     * Kurzform der Anschlüsse: „1× CEE 63 A → 2× CEE 32 A, 6× Schuko“.
     */
    public static function kurzform(int $objektId): string
    {
        $typen = self::steckertypenNachId();
        $teile = ['eingang' => [], 'abgang' => []];
        foreach (self::anzahlen($objektId) as $richtung => $je) {
            uksort($je, static fn ($a, $b) => ((int) ($typen[$a]['reihenfolge'] ?? 0)) <=> ((int) ($typen[$b]['reihenfolge'] ?? 0)));
            foreach ($je as $typId => $n) {
                $teile[$richtung][] = $n . '× ' . ($typen[$typId]['name'] ?? '?');
            }
        }
        $ein = implode(', ', $teile['eingang']);
        $ab  = implode(', ', $teile['abgang']);
        if ($ein === '' && $ab === '') {
            return 'keine Anschlüsse dokumentiert';
        }
        return trim(($ein !== '' ? $ein . ' → ' : '') . $ab);
    }

    // ------------------------------------------------------------ Einspeisung

    /**
     * Woher eine Anlage ihren Strom bekommt – die Kette bis zur Quelle.
     *
     * @return list<array<string,mixed>> von der Anlage selbst aufwärts; bricht bei Schleifen ab
     */
    public static function speisung(int $objektId): array
    {
        $kette = [];
        $gesehen = [$objektId => true];
        $aktuell = $objektId;
        for ($i = 0; $i < 30; $i++) {
            $zu = Database::first(
                "SELECT l.*, o.name AS von_name, o.art AS von_art FROM leitungen l
                 JOIN objekte o ON o.id = l.von_objekt_id
                 WHERE l.art = 'kabel' AND l.zu_objekt_id = ? ORDER BY l.id LIMIT 1",
                [$aktuell]
            );
            if ($zu === null || isset($gesehen[(int) $zu['von_objekt_id']])) {
                break;
            }
            $kette[] = $zu;
            $aktuell = (int) $zu['von_objekt_id'];
            $gesehen[$aktuell] = true;
        }
        return $kette;
    }

    /**
     * Der Verteilbaum eines Standorts: Quellen oben, Verteilungen darunter.
     *
     * @return list<array<string,mixed>> Knoten mit 'kinder'
     */
    public static function baum(?int $siteId): array
    {
        $objekte = [];
        foreach (self::liste($siteId !== null ? ['site_id' => $siteId] : []) as $o) {
            $o['kinder'] = [];
            $o['kabel'] = null;
            $objekte[(int) $o['id']] = $o;
        }
        $eltern = [];
        $kabel = [];
        foreach (Database::select("SELECT * FROM leitungen WHERE art = 'kabel' AND von_objekt_id IS NOT NULL AND zu_objekt_id IS NOT NULL ORDER BY id") as $l) {
            $von = (int) $l['von_objekt_id'];
            $zu  = (int) $l['zu_objekt_id'];
            if (!isset($objekte[$von], $objekte[$zu]) || isset($eltern[$zu])) {
                continue;
            }
            $eltern[$zu] = $von;
            $kabel[$zu] = $l;
        }
        $bauen = static function (int $id, array $pfad) use (&$bauen, $objekte, $eltern, $kabel): array {
            $knoten = $objekte[$id];
            $knoten['kabel'] = $kabel[$id] ?? null;
            foreach ($eltern as $kind => $elter) {
                if ($elter === $id && !isset($pfad[$kind])) {
                    $knoten['kinder'][] = $bauen($kind, $pfad + [$kind => true]);
                }
            }
            return $knoten;
        };
        $wurzeln = [];
        foreach ($objekte as $id => $_o) {
            if (!isset($eltern[$id])) {
                $wurzeln[] = $bauen($id, [$id => true]);
            }
        }
        // Quellen zuerst: Anschlüsse und Generatoren vor losen Verteilungen.
        $rang = ['anschluss' => 0, 'generator' => 1, 'wechselrichter' => 2, 'verteilung' => 3];
        usort($wurzeln, static fn ($a, $b) => [$rang[$a['art']] ?? 9, $a['name']] <=> [$rang[$b['art']] ?? 9, $b['name']]);
        return $wurzeln;
    }

    private static function klemme(float $wert): float
    {
        return round(max(0.0, min(100.0, $wert)), 4);
    }
}
