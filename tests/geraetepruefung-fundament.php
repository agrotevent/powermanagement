<?php
declare(strict_types=1);

/**
 * Geräteprüfung – Fundament (eigene Instanz, Zeitzone Europe/Berlin).
 *
 * A – Migration 019: angewandt, Tabellen und Spalten (auch die neuen
 * Kundenfelder mit Vorgabe '' und pruefnachweise.gp_bericht_id), eindeutige
 * Indizes (eindeutig, schluessel, Prüfabläufe, Unterschriften, Nachweis je
 * Bericht); MySQL-Form durch Lesen: jede Tabelle mit {{ENGINE}}, indizierte
 * Textspalten höchstens 190 Zeichen, keine Fremdschlüssel, kein ;--split in
 * Kommentaren, nur bekannte Platzhalter.
 *
 * B – Rechte: Gruppe „Geräteprüfung“ (nicht „System“) mit view, manage,
 * unterschreiben; Standardrollen planung (view, manage), technik (alle drei),
 * kunde nichts; die INSERTs der Migration geben vorhandenen Rollen genau das.
 *
 * C – Messarten: zahl() ohne float32-Reste, dezimal(), anzeige() mit Komma,
 * zeitLesen() für alle Formate des Geräts in Europe/Berlin (Winter, Sommer,
 * Umstellungen, Bruchteile mit 5 bis 7 Stellen, „1900“ = nie, Unsinn → null),
 * ortsdatum(), ortszeit(), monatePlus() (Monatsende), norm(), titel(),
 * grenzwerte() (NULL/0 ohne Eintrag, RISO-4 ohne), ergebnisAbleiten(), text().
 *
 * D – BenningMuster: gültige SQLite-Datei mit dem Schema Text für Text
 * (sqlite_master = st755-schema.sql), Seitengröße 1024, Journal delete,
 * Kopf-Bytes 18/19 = 1, integrity_check; Werksdaten (50 Werksabläufe, 20
 * Fragen, Grenzwertsätze, DB-Version 14), drei erfundene Abläufe am Gerät,
 * alle festen Fälle (Ergebnis aus Schritten, Werten und Sichtprüfung wie
 * erwartet, „>100.0 MΩ“, „<0.030 mA“, „>15.000 Ω“, Prüfer „Admin“, gleiche
 * Kennung bei zwei Kunden und zweimal beim selben, führendes Leerzeichen,
 * leere Kennung, nie geprüft, außer Betrieb, Sequenz ohne Schritte),
 * Zeiten in allen Formaten (alle lesbar), deterministisch (gleicher Startwert
 * = gleiche Bytes, anderer = andere), zusätzliche Geräte, pruefungAnhaengen()
 * (Ergebnisse, Sequences, Schritte, Antworten, Prüfdatum; mehrdeutige
 * Kennung abgelehnt, mit Kundennummer eindeutig), Ziel vorhanden → abgelehnt.
 *
 * E – Pruefungen: schluessel() (Fragetext zählt nicht, Werte und Kennung schon,
 * Kennung getrimmt), anlegen() idempotent (zweimal → neu=false, derselbe
 * Schlüssel bei anderem Prüfling → der vorhandene), Formfehler (Schlüssel,
 * Zeit, Ergebnis, Gleitkomma), find() ohne Datei-Verweise, liste() mit Filtern,
 * fuerPruefling().
 *
 * F – Prueflinge: eindeutig() je Besitzer ohne Groß/Klein, anlegen() (Kennung
 * getrimmt, Texte gekürzt, Zahlen als Dezimaltext, Pflicht Kennung, Inventar
 * nur beim eigenen Bestand), dieselbe Kennung beim selben Besitzer abgelehnt,
 * bei einem anderen erlaubt, findEindeutig(), aendern() (eindeutig neu),
 * fuerBesitzer(), faelligkeitNeu() (bestanden → + Intervall, nicht bestanden →
 * am Prüftag, ohne Intervall keine, ohne Prüfungen unverändert).
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['timezone' => 'Europe/Berlin']]);
require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Permissions;
use App\Berichte\Kanon;
use App\Geraetepruefung\BenningMuster;
use App\Geraetepruefung\Messarten;
use App\Geraetepruefung\Pruefungen;
use App\Geraetepruefung\Prueflinge;
use App\Support\Database;

$kennung = 'pm-gp-' . bin2hex(random_bytes(3));
$ordner = (string) getenv('PM_INSTANZ_ORDNER');
if ($ordner === '' || !is_dir($ordner)) {
    $ordner = sys_get_temp_dir();
}
$berlin = new DateTimeZone('Europe/Berlin');
$dateien = [];
$angelegt = ['kunden' => [], 'prueflinge' => [], 'pruefungen' => []];

/** Eine Normalform-Prüfung wie aus BenningDatei::lesen() (nur Text, Wahrheitswerte, Listen). */
$normal = static function (string $amGeraet, string $kennungGeraet, array $aenderung = []): array {
    $p = array_replace([
        'ref' => '7', 'geraet_ref' => '3', 'geprueft_am' => Messarten::zeitLesen($amGeraet), 'am_geraet' => $amGeraet,
        'pruefer' => 'Erika Mustermann', 'prueffirma' => 'Muster GmbH', 'ort' => '', 'auftrag' => '', 'bemerkung' => '',
        'ablauf_name' => '1 Geräte SK I', 'ablauf_geraet_id' => '1', 'messgeraet' => Messarten::MESSGERAET,
        'messgeraet_sn' => '500000000001', 'firmware' => '1.17 / 1.08.01', 'ergebnis' => 'bestanden', 'sicht' => 'ok',
        'geraet_ergebnis' => ['messung' => true, 'sicht' => true],
        'sicht_antworten' => [['frage' => '1', 'text' => 'Sichtkontrolle', 'antwort' => 'ok']],
        'schritte' => [['art' => 'RPE', 'titel' => Messarten::titel('RPE'), 'ok' => true,
            'werte' => [['name' => 'RPE Widerstand', 'wert' => '0.102 Ω', 'gueltig' => true]],
            'grenzwerte' => [['name' => 'R_PE max', 'wert' => '0.3 Ω']]]],
    ], $aenderung);
    $p['schluessel'] = Pruefungen::schluessel($p, $kennungGeraet);
    return $p;
};

try {
    // ================================================ A – Migration
    $angewandt = array_column(Database::select('SELECT name FROM migrations'), 'name');
    pruefe(in_array('019_geraetepruefung.sql', $angewandt, true), 'Migration 019 angewandt');
    $spalten = static fn (string $t): array => array_column(Database::select('PRAGMA table_info(' . $t . ')'), 'name');
    $tabellen = ['gp_pruefablaeufe', 'gp_prueflinge', 'gp_importe', 'gp_pruefungen', 'gp_vorlagen', 'gp_exporte',
        'gp_kunden_zuordnung', 'gp_berichte', 'gp_bericht_nummern', 'gp_unterschriften', 'gp_bericht_pdf'];
    foreach ($tabellen as $t) {
        pruefe($spalten($t) !== [], 'Tabelle ' . $t . ' angelegt');
    }
    gleich(array_values(array_diff(['strasse', 'plz', 'ort', 'telefon', 'fax', 'email', 'ansprechpartner',
        'ansprechpartner_abteilung', 'ansprechpartner_telefon', 'ansprechpartner_email'], $spalten('kunden'))), [], 'kunden: neue Felder');
    pruefe(in_array('gp_bericht_id', $spalten('pruefnachweise'), true), 'pruefnachweise.gp_bericht_id');
    pruefe(in_array('eindeutig', $spalten('gp_prueflinge'), true) && in_array('technik', $spalten('gp_prueflinge'), true)
        && in_array('naechste_pruefung', $spalten('gp_prueflinge'), true), 'gp_prueflinge: eindeutig, technik, naechste_pruefung');
    pruefe(in_array('schluessel', $spalten('gp_pruefungen'), true) && in_array('daten', $spalten('gp_pruefungen'), true), 'gp_pruefungen: schluessel, daten');
    // Ein Kunde nur mit Name (wie viele Prüfskripte ihn anlegen): die neuen Felder sind leer.
    $kundeA = Database::insert('kunden', ['name' => $kennung . ' Kunde A', 'created_at' => gmdate('c')]);
    $kundeB = Database::insert('kunden', ['name' => $kennung . ' Kunde B', 'kundennummer' => $kennung . '-B', 'created_at' => gmdate('c')]);
    $angelegt['kunden'] = [$kundeA, $kundeB];
    $k = Database::first('SELECT strasse, plz, ort, telefon, fax, email, ansprechpartner, ansprechpartner_abteilung,
        ansprechpartner_telefon, ansprechpartner_email FROM kunden WHERE id = ?', [$kundeA]);
    gleich(array_unique(array_values((array) $k)), [''], 'neue Kundenfelder: Vorgabe leer');
    $eindeutigeIndizes = [];
    foreach ($tabellen + [99 => 'pruefnachweise'] as $t) {
        foreach (Database::select('PRAGMA index_list(' . $t . ')') as $ix) {
            if ((int) $ix['unique'] === 1 && !str_starts_with((string) $ix['name'], 'sqlite_autoindex')) {
                $eindeutigeIndizes[] = $t . ':' . implode(',', array_column(Database::select('PRAGMA index_info(' . $ix['name'] . ')'), 'name'));
            }
        }
    }
    sort($eindeutigeIndizes);
    gleich(array_values(array_intersect(['gp_bericht_pdf:bericht_id', 'gp_berichte:jahr,laufnr,fassung', 'gp_berichte:pruefung_id,fassung',
        'gp_kunden_zuordnung:schluessel', 'gp_pruefablaeufe:geraet_id', 'gp_prueflinge:eindeutig', 'gp_pruefungen:schluessel',
        'gp_unterschriften:anfrage', 'gp_unterschriften:striche_hash', 'pruefnachweise:gp_bericht_id'], $eindeutigeIndizes)),
        ['gp_bericht_pdf:bericht_id', 'gp_berichte:jahr,laufnr,fassung', 'gp_berichte:pruefung_id,fassung', 'gp_kunden_zuordnung:schluessel',
        'gp_pruefablaeufe:geraet_id', 'gp_prueflinge:eindeutig', 'gp_pruefungen:schluessel', 'gp_unterschriften:anfrage',
        'gp_unterschriften:striche_hash', 'pruefnachweise:gp_bericht_id'], 'eindeutige Indizes');

    // MySQL-Form durch Lesen der Datei
    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/019_geraetepruefung.sql');
    $anweisungen = array_values(array_filter(array_map('trim', explode(';--split', $sql))));
    $kommentarSplit = false;
    foreach (explode("\n", $sql) as $zeile) {
        if (str_starts_with(ltrim($zeile), '--') && str_contains($zeile, ';--split')) {
            $kommentarSplit = true;
        }
    }
    pruefe(!$kommentarSplit, 'MySQL-Form: kein ;--split in Kommentaren');
    preg_match_all('/\{\{([A-Z]+)\}\}/', $sql, $platzhalter);
    gleich(array_values(array_diff(array_unique($platzhalter[1]), ['PK', 'TEXT', 'JSON', 'TS', 'BOOL', 'BIGINT', 'ENGINE'])), [], 'MySQL-Form: nur bekannte Platzhalter');
    pruefe(stripos($sql, 'FOREIGN KEY') === false && stripos($sql, 'REFERENCES') === false && stripos($sql, 'AUTOINCREMENT') === false,
        'MySQL-Form: keine Fremdschlüssel, kein AUTOINCREMENT von Hand');
    $definitionen = [];
    $ohneEngine = [];
    foreach ($anweisungen as $a) {
        $ohneKommentar = trim((string) preg_replace('/^--.*$/m', '', $a));
        if (preg_match('/^CREATE TABLE (\w+) \((.*)\)(.*)$/s', $ohneKommentar, $m) === 1) {
            if (trim($m[3]) !== '{{ENGINE}}') {
                $ohneEngine[] = $m[1];
            }
            foreach (explode("\n", $m[2]) as $spalte) {
                if (preg_match('/^\s*(\w+)\s+(.+?),?\s*$/', $spalte, $s) === 1) {
                    $definitionen[$m[1]][$s[1]] = $s[2];
                }
            }
        }
    }
    gleich($ohneEngine, [], 'MySQL-Form: jede Tabelle mit {{ENGINE}}');
    $definitionen['pruefnachweise']['gp_bericht_id'] = 'INT NULL';
    $zuLang = [];
    $indizes = 0;
    foreach ($anweisungen as $a) {
        $ohneKommentar = trim((string) preg_replace('/^--.*$/m', '', $a));
        if (preg_match('/^CREATE (?:UNIQUE )?INDEX (\w+) ON (\w+) \(([^)]+)\)$/', $ohneKommentar, $m) !== 1) {
            continue;
        }
        $indizes++;
        foreach (array_map('trim', explode(',', $m[3])) as $spalte) {
            $typ = (string) ($definitionen[$m[2]][$spalte] ?? '?');
            if (preg_match('/^(?:VAR)?CHAR\((\d+)\)/', $typ, $l) === 1 ? (int) $l[1] > 190 : !preg_match('/^INT\b/', $typ)) {
                $zuLang[] = $m[1] . ':' . $spalte . ' ' . $typ;
            }
        }
    }
    gleich($indizes, 18, 'MySQL-Form: alle 18 Indizes gelesen');
    gleich($zuLang, [], 'MySQL-Form: indizierte Spalten nur INT oder (VAR)CHAR bis 190');

    // ================================================ B – Rechte
    $gp = ['geraetepruefung.view', 'geraetepruefung.manage', 'geraetepruefung.unterschreiben'];
    gleich(array_keys(Permissions::ALL['Geräteprüfung'] ?? []), $gp, 'Rechte-Gruppe „Geräteprüfung“');
    gleich(array_values(array_intersect($gp, array_keys(Permissions::ALL['System']))), [], '… nicht unter „System“');
    pruefe(Permissions::exists('geraetepruefung.view') && Permissions::exists('geraetepruefung.unterschreiben'), 'Permissions::exists');
    $rollen = Permissions::standardRollen();
    $nurGp = static fn (array $rechte): array => array_values(array_filter($rechte, static fn (string $r): bool => str_starts_with($r, 'geraetepruefung.')));
    gleich($nurGp($rollen['planung']['permissions']), ['geraetepruefung.view', 'geraetepruefung.manage'], 'Standardrolle planung: view, manage');
    gleich($nurGp($rollen['technik']['permissions']), $gp, 'Standardrolle technik: view, manage, unterschreiben');
    gleich($nurGp($rollen['kunde']['permissions']), [], 'Standardrolle kunde: nichts');
    gleich($rollen['admin']['permissions'], ['*'], 'Administration: alles');
    // Die INSERTs der Migration (für Installationen mit vorhandenen Rollen):
    // erst die Rechte wegnehmen, dann genau diese Anweisungen ausführen.
    $rollenIds = [];
    foreach (['planung', 'technik', 'kunde'] as $r) {
        $rollenIds[$r] = (int) Database::scalar('SELECT id FROM roles WHERE role_key = ?', [$r]);
    }
    pruefe(!in_array(0, $rollenIds, true), 'Standardrollen in der Instanz vorhanden');
    $rechteDerRollen = static function () use ($rollenIds): array {
        $aus = [];
        foreach ($rollenIds as $r => $id) {
            $aus[$r] = array_column(Database::select("SELECT permission FROM role_permissions WHERE role_id = ? AND permission LIKE 'geraetepruefung.%' ORDER BY permission", [$id]), 'permission');
        }
        return $aus;
    };
    $soll = ['planung' => ['geraetepruefung.manage', 'geraetepruefung.view'],
        'technik' => ['geraetepruefung.manage', 'geraetepruefung.unterschreiben', 'geraetepruefung.view'], 'kunde' => []];
    gleich($rechteDerRollen(), $soll, 'Rechte der Rollen nach der Einrichtung');
    Database::execute("DELETE FROM role_permissions WHERE permission LIKE 'geraetepruefung.%'");
    $inserts = array_values(array_filter($anweisungen, static fn (string $a): bool => str_contains($a, 'INSERT INTO role_permissions')));
    gleich(count($inserts), 3, 'drei Rechte-INSERTs in der Migration');
    foreach ($inserts as $a) {
        Database::execute(trim((string) preg_replace('/^--.*$/m', '', $a)));
    }
    gleich($rechteDerRollen(), $soll, 'Migration gibt vorhandenen Rollen genau diese Rechte');

    // ================================================ C – Messarten
    foreach ([[0.300000011920929, '0.3'], [0.312000006437302, '0.312'], [100, '100'], [100.0, '100'], [536.43389892578, '536.434'],
        [0.51300001144409, '0.513'], [-4.468244878808E-5, '-0.0000446824'], [999.9996, '1000'], [0.0, '0'], [-0.0, '0'],
        [3.5, '3.5'], [0.1 + 0.2, '0.3'], [1899.703125, '1899.7'], [30, '30']] as [$ein, $aus]) {
        gleich(Messarten::zahl($ein), $aus, 'zahl(' . var_export($ein, true) . ')');
    }
    gleich(Messarten::zahl(0.30000001192093, 2), '0.3', 'zahl mit 2 Stellen');
    gleich(Messarten::zahl(INF), '', 'zahl(INF) leer');
    gleich(Messarten::zahl(BenningMuster::f32(0.3)), '0.3', 'zahl(f32(0.3))');
    pruefe(BenningMuster::f32(0.3) !== 0.3, 'f32 erzeugt float32-Reste');
    foreach ([['1,5', '1.5'], ['1.50', '1.5'], ['007', '7'], ['0,75', '0.75'], [2, '2'], [1.6000000238419, '1.6'], ['', ''], [null, ''],
        ['abc', null], ['-1', null], ['1,2,3', null], ['1e3', null]] as [$ein, $aus]) {
        gleich(Messarten::dezimal($ein), $aus, 'dezimal(' . var_export($ein, true) . ')');
    }
    gleich(Messarten::anzeige('>100.0 MΩ'), '>100,0 MΩ', 'anzeige mit Komma');
    gleich(Messarten::anzeige('0.102 Ω / Ver. 1.17'), '0,102 Ω / Ver. 1,17', 'anzeige: nur Punkt zwischen Ziffern');
    gleich(Messarten::anzeige('Ausl.-Str.'), 'Ausl.-Str.', 'anzeige lässt Punkte ohne Ziffern');
    // Zeiten des Geräts (Ortszeit ohne Zone) → UTC, alle Formate aus dem Befund
    $zeiten = [
        '2024-04-12 11:56:57'          => '2024-04-12T09:56:57Z', // Sommerzeit
        '2023-02-27T18:38:03.0000000'  => '2023-02-27T17:38:03Z', // Winterzeit, T, 7 Stellen
        '2024-03-06T22:14:41.2092494'  => '2024-03-06T21:14:41Z',
        '2024-03-27 17:06:35.1234567'  => '2024-03-27T16:06:35Z', // Leerzeichen, 7 Stellen
        '2026-01-02 08:00:00.123456'   => '2026-01-02T07:00:00Z', // 6 Stellen
        '2026-01-02 08:00:00.12345'    => '2026-01-02T07:00:00Z', // 5 Stellen
        '2024-03-31 01:59:59'          => '2024-03-31T00:59:59Z', // vor der Umstellung
        '2024-03-31 03:00:00'          => '2024-03-31T01:00:00Z', // nach der Umstellung
        '2024-03-31 02:30:00'          => '2024-03-31T01:30:00Z', // fehlende Stunde rückt vor
        '2024-10-27 01:59:59'          => '2024-10-26T23:59:59Z', // noch Sommerzeit
        '2024-10-27 03:00:00'          => '2024-10-27T02:00:00Z', // Winterzeit
        '2024-12-31 23:30:00'          => '2024-12-31T22:30:00Z',
        ' 2024-07-01 12:00:00 '        => '2024-07-01T10:00:00Z', // Leerraum außen
    ];
    foreach ($zeiten as $ein => $aus) {
        gleich(Messarten::zeitLesen($ein, $berlin), $aus, 'zeitLesen(' . $ein . ')');
    }
    gleich(Messarten::zeitLesen('2024-04-12 11:56:57'), '2024-04-12T09:56:57Z', 'zeitLesen mit der Zone der Installation');
    foreach (['1900-01-01T00:00:00.0000000', '1900-01-01 00:00:00', '', '2024-02-30 10:00:00', '2024-04-12', '2024-04-12 25:00:00',
        '2024-04-12 11:56:57.12345678', 'kaputt', '2024-04-12T11:56:57Z', '12.04.2024 11:56', '2101-01-01 00:00:00'] as $falsch) {
        gleich(Messarten::zeitLesen($falsch, $berlin), null, 'zeitLesen(' . var_export($falsch, true) . ') → null');
    }
    gleich(Messarten::ortsdatum('2026-03-31T22:30:00Z'), '2026-04-01', 'ortsdatum über Mitternacht (Sommerzeit)');
    gleich(Messarten::ortsdatum('2026-01-31T22:30:00Z'), '2026-01-31', 'ortsdatum Winterzeit');
    gleich(Messarten::ortsdatum(''), null, 'ortsdatum leer → null');
    gleich(Messarten::ortszeit('2024-04-12T09:56:57Z'), '2024-04-12 11:56:57', 'ortszeit wie das Gerät');
    gleich(Messarten::zeitLesen((string) Messarten::ortszeit('2026-07-14T12:30:00Z')), '2026-07-14T12:30:00Z', 'ortszeit ↔ zeitLesen');
    foreach ([['2026-01-31', 1, '2026-02-28'], ['2028-02-29', 12, '2029-02-28'], ['2026-11-15', 14, '2028-01-15'],
        ['2026-03-02', 12, '2027-03-02'], ['2026-03-31', 6, '2026-09-30'], ['2026-05-10', 0, '2026-05-10']] as [$tag, $monate, $soll]) {
        gleich(Messarten::monatePlus($tag, $monate), $soll, 'monatePlus(' . $tag . ', ' . $monate . ')');
    }
    $geworfen = false;
    try {
        Messarten::monatePlus('2026-02-30', 1);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'monatePlus: ungültiges Datum wirft');
    gleich(Messarten::norm('1 Med. Geräte SK I', 'wiederholung'), 'DIN EN 62353 (VDE 0751-1)', 'norm: Medizin');
    gleich(Messarten::norm('1 Schweißgerät SK I 1-Phasig', 'instandsetzung'), 'DIN EN IEC 60974-4 (VDE 0544-4)', 'norm: Schweißgerät');
    gleich(Messarten::norm('1 Geräte SK I', 'wiederholung'), 'DIN EN 50699 (VDE 0702)', 'norm: Wiederholungsprüfung');
    gleich(Messarten::norm('16 Leitung Schuko ohne Sonde', 'instandsetzung'), 'DIN EN 50678 (VDE 0701)', 'norm: nach Instandsetzung');
    gleich(Messarten::norm('1 Geräte SK I', 'unbekannt'), 'DIN EN 50699 (VDE 0702)', 'norm: unbekannte Prüfart = Wiederholung');
    gleich([Messarten::titel('RPE'), Messarten::titel('IBer.'), Messarten::titel('RISO-3'), Messarten::titel('XYZ')],
        ['Schutzleiterwiderstand', 'Berührungsstrom', 'Isolationswiderstand Primär–Sekundär', 'XYZ'], 'titel, Unbekanntes roh');
    $gw = ['Ergebnisse_RSL_gw_rsl' => 0.30000001192093, 'Ergebnisse_RISO_gw_riso_prim_pe' => 1.0, 'Ergebnisse_RISO_gw_uiso_prim_pe' => 500.0,
        'Ergebnisse_KM_gw_rleitung' => null, 'Ergebnisse_IB_gw_ib_rms' => 0.0, 'Ergebnisse_RCD_gw_iausl_1I' => 30.0, 'Ergebnisse_RCD_gw_uber' => '50'];
    gleich(Messarten::grenzwerte('RPE', $gw), [['name' => 'R_PE max', 'wert' => '0.3 Ω']], 'grenzwerte RPE ohne float32-Rest');
    gleich(Messarten::grenzwerte('RISO-1', $gw), [['name' => 'R_ISO min', 'wert' => '1 MΩ'], ['name' => 'U_ISO', 'wert' => '500 V']], 'grenzwerte RISO-1 mit U_ISO');
    gleich(Messarten::grenzwerte('RISO-OUT', $gw), Messarten::grenzwerte('RISO-1', $gw), 'RISO-OUT wie RISO-1');
    gleich([Messarten::grenzwerte('Kabel', $gw), Messarten::grenzwerte('IBer.', $gw), Messarten::grenzwerte('RISO-4', $gw), Messarten::grenzwerte('XYZ', $gw)],
        [[], [], [], []], 'NULL und 0 ohne Grenzwert, RISO-4 und Unbekanntes ohne');
    gleich(Messarten::grenzwerte('PRCD', $gw), [['name' => 'Ausl.-Str. max', 'wert' => '30 mA'], ['name' => 'Berührungsspg. max', 'wert' => '50 V']], 'grenzwerte PRCD');
    pruefe(in_array('Ergebnisse_RCD_gw_tausl_1_2I', Messarten::grenzwertSpalten(), true) && count(Messarten::grenzwertSpalten()) === count(array_unique(Messarten::grenzwertSpalten())),
        'grenzwertSpalten vollständig und ohne Doppel');
    $ok = ['ok' => true, 'werte' => [['gueltig' => true]]];
    gleich(Messarten::ergebnisAbleiten([$ok], [['antwort' => 'ok']], true, true), ['ergebnis' => 'bestanden', 'sicht' => 'ok', 'abweichung' => false], 'abgeleitet: bestanden');
    gleich(Messarten::ergebnisAbleiten([['ok' => true, 'werte' => [['gueltig' => false]]]], [], true, true),
        ['ergebnis' => 'nicht_bestanden', 'sicht' => 'ok', 'abweichung' => true], 'ein ungültiger Wert → nicht bestanden, Abweichung');
    gleich(Messarten::ergebnisAbleiten([['ok' => false, 'werte' => []]], [], false, true),
        ['ergebnis' => 'nicht_bestanden', 'sicht' => 'ok', 'abweichung' => false], 'Schritt nicht in Ordnung → nicht bestanden');
    gleich(Messarten::ergebnisAbleiten([$ok], [['antwort' => 'nicht_ok']], true, true),
        ['ergebnis' => 'nicht_bestanden', 'sicht' => 'maengel', 'abweichung' => true], 'Sichtprüfung „nicht in Ordnung“ → Mängel');
    gleich(Messarten::ergebnisAbleiten([], [], false, false), ['ergebnis' => 'nicht_bestanden', 'sicht' => 'maengel', 'abweichung' => false], 'Gerät: Sicht nicht bestanden');
    gleich(Messarten::ergebnisAbleiten([], [], true, true)['ergebnis'], 'bestanden', 'ohne Schritte, Gerät bestanden → bestanden');
    gleich(Messarten::text("  A\x01B\u{202E}C\n D  ", 120), 'A B C D', 'text: Steuer- und Richtungszeichen raus, Leerraum zusammen');
    gleich(Messarten::text("Ger\xE4t", 120), 'Ger?t', 'text: ungültiges UTF-8 ersetzt (mb_scrub)');
    gleich(mb_strlen(Messarten::text(str_repeat('ä', 300), 50)), 50, 'text: gekürzt');
    gleich(Messarten::absatz("Zeile 1\r\nZeile 2", 500), "Zeile 1\nZeile 2", 'absatz behält Zeilen');

    // ================================================ D – BenningMuster
    $muster = $ordner . '/' . $kennung . '-muster.db';
    $dateien[] = $muster;
    $r = BenningMuster::schreiben($muster);
    pruefe(is_file($muster), 'Muster geschrieben');
    $p = new PDO('sqlite:' . $muster, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $eins = static function (string $q, array $w = []) use (&$p): mixed {
        $st = $p->prepare($q);
        $st->execute($w);
        return $st->fetchColumn();
    };
    $alle = static function (string $q, array $w = []) use (&$p): array {
        $st = $p->prepare($q);
        $st->execute($w);
        return $st->fetchAll();
    };
    gleich(substr((string) file_get_contents($muster, false, null, 0, 16), 0, 16), "SQLite format 3\0", 'SQLite-Datei');
    $kopf = (string) file_get_contents($muster, false, null, 0, 20);
    gleich([ord($kopf[18]), ord($kopf[19])], [1, 1], 'Kopf-Bytes 18/19 = 1 (kein WAL)');
    gleich([(int) $eins('PRAGMA page_size'), (string) $eins('PRAGMA journal_mode'), (string) $eins('PRAGMA integrity_check')],
        [1024, 'delete', 'ok'], 'Seitengröße 1024, Journal delete, integrity_check');
    $master = array_column($alle("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name <> 'sqlite_sequence' ORDER BY rowid"), 'sql');
    $schema = BenningMuster::schemaAnweisungen();
    gleich(count($master), count($schema), 'sqlite_master: so viele Anweisungen wie die Schema-Datei');
    pruefe($master === $schema, 'sqlite_master = st755-schema.sql (Text für Text, in Reihenfolge)');
    gleich(count($schema), 39, 'Schema: 39 Anweisungen');
    pruefe(count(array_filter($alle("SELECT type FROM sqlite_master"), static fn (array $z): bool => $z['type'] === 'view')) === 0, 'keine Views');
    // Werksdaten
    $werks = (string) file_get_contents(dirname(__DIR__) . '/resources/benning/st755-werksdaten.sql');
    gleich((int) $eins('SELECT COUNT(*) FROM Pruefung WHERE Fest = 1'), substr_count($werks, 'INSERT INTO "Pruefung"'), 'Werksabläufe wie in der Datei');
    gleich((int) $eins('SELECT COUNT(*) FROM Pruefung WHERE Fest = 1'), 50, '50 Werksabläufe');
    gleich((int) $eins('SELECT COUNT(*) FROM ViewQuestions'), 20, '20 Fragen der Sichtprüfung');
    gleich((int) $eins('SELECT COUNT(*) FROM ViewQuestions WHERE ViewLevel = 1 AND IsDeleted = 0'), 2, 'Fragen der Stufe 1: zwei');
    gleich((string) $eins("SELECT value FROM DbInfos WHERE ident = 'DB-Version'"), '14', 'DB-Version 14');
    gleich((int) $eins('SELECT COUNT(*) FROM Grenzwerte'), substr_count($werks, 'INSERT INTO "Grenzwerte"'), 'Grenzwertsätze wie in der Datei');
    gleich((int) $eins('SELECT COUNT(*) FROM Systemdaten'), 1, 'Systemdaten: eine Zeile');
    preg_match_all('/^INSERT INTO "(\w+)"/m', $werks, $werksTabellen);
    gleich(array_values(array_diff(array_unique($werksTabellen[1]), ['DbInfos', 'Systemdaten', 'Pruefung', 'Grenzwerte', 'ViewQuestions'])), [],
        'Werksdaten nur in DbInfos, Systemdaten, Pruefung, Grenzwerte, ViewQuestions (keine Kunden, Geräte, Prüfungen)');
    gleich((int) $eins('SELECT COUNT(*) FROM Pruefung WHERE Pruefung_ID < 1000000 AND (Fest IS NOT 1 OR Individuell IS NOT 0)'), 0, 'Werksdaten: nur Werksabläufe');
    gleich(array_column($alle('SELECT Pruefung_ID FROM Pruefung WHERE Pruefung_ID >= 1000000 ORDER BY 1'), 'Pruefung_ID'), [1000000, 1000001, 1000002], 'Abläufe am Gerät: drei erfundene');
    gleich([(int) $eins('SELECT Individuell FROM Pruefung WHERE Pruefung_ID = 1000000'), (int) $eins('SELECT Ungueltig FROM Pruefung WHERE Pruefung_ID = 1000002')], [1, 1], '… Individuell und ungültig');
    // Zählungen
    gleich([$r['kunden'], $r['geraete'], $r['pruefungen']], [3, 19, 19], 'Zählung: 3 Kunden, 19 Geräte, 19 Prüfungen');
    gleich([$r['schritte'], $r['werte']], [(int) $eins('SELECT COUNT(*) FROM SqProcedures'), (int) $eins('SELECT COUNT(*) FROM SqProcValues')], 'Zählung der Schritte und Werte');
    gleich((int) $eins('SELECT COUNT(*) FROM Sequences'), 19, 'je Ergebnis genau eine Sequenz');
    gleich((int) $eins('SELECT COUNT(*) FROM Sequences s JOIN Ergebnisse e ON e.Ergebnisse_Index = s.testResultId
        WHERE s.startDate = e.Ergebnisse_Datum AND s.deviceId = e.Ergebnisse_Geraete_Index'), 19, 'Sequenz: startDate und deviceId wie die Ergebniszeile');
    gleich((int) $eins('SELECT COUNT(*) FROM TestResult2Admin2Answers'), 38, 'je Prüfung zwei Antworten der Sichtprüfung');
    gleich((string) $eins('SELECT DISTINCT Ergebnisse_Messgeraet FROM Ergebnisse'), 'BENNING ST 755+', 'Messgerät');
    // Die Fälle: Ergebnis wie erwartet, abgeleitet aus Schritten, Werten und Antworten.
    $erwarteteFaelle = ['sk1_ok', 'sk2_ok', 'leitung_ok', 'leitung_fehler', 'leitung_nachpruefung', 'funkt_fehler', 'sicht_fehler', 'prcd',
        'ua', 'riso_out', 'admin', 'doppelt_k2', 'doppelt_k3', 'doppelt_alt', 'doppelt_neu', 'abweichung', 'ohne_schritte', 'leere_kennung',
        'ohne_pruefung', 'ausser_betrieb'];
    gleich(array_keys($r['faelle']), $erwarteteFaelle, 'alle festen Fälle');
    $lesen = static function (int $ergebnis) use ($alle): array {
        $seq = $alle('SELECT id, success, viewTestOk, typeId FROM Sequences WHERE testResultId = ?', [$ergebnis])[0];
        $schritte = [];
        foreach ($alle('SELECT id, procName, success FROM SqProcedures WHERE sequenceId = ? ORDER BY id', [$seq['id']]) as $s) {
            $werte = array_map(static fn (array $w): array => ['name' => $w['ident'], 'wert' => trim((string) $w['valueStr']), 'gueltig' => (int) $w['isValid'] === 1],
                $alle('SELECT ident, valueStr, isValid FROM SqProcValues WHERE sqProcId = ? ORDER BY id', [$s['id']]));
            $schritte[] = ['art' => $s['procName'], 'ok' => (int) $s['success'] === 1, 'werte' => $werte];
        }
        $antworten = array_map(static fn (array $a): array => ['frage' => (string) $a['questionId'], 'antwort' => (int) $a['answerId'] === 1 ? 'ok' : 'nicht_ok'],
            $alle('SELECT questionId, answerId FROM TestResult2Admin2Answers WHERE testResultId = ? ORDER BY questionId', [$ergebnis]));
        return ['schritte' => $schritte, 'antworten' => $antworten, 'seq' => $seq,
            'abgeleitet' => Messarten::ergebnisAbleiten($schritte, $antworten, (int) $seq['success'] === 1, (int) $seq['viewTestOk'] === 1)];
    };
    $falsch = [];
    foreach ($r['faelle'] as $name => $f) {
        if ($f['ergebnis'] === null) {
            continue;
        }
        $ab = $lesen($f['ergebnis'])['abgeleitet'];
        if ($ab['ergebnis'] !== $f['erwartet']) {
            $falsch[] = $name . ': ' . $ab['ergebnis'];
        }
    }
    gleich($falsch, [], 'jeder Fall mit dem erwarteten Ergebnis');
    $folge = static fn (array $l): string => implode(' > ', array_column($l['schritte'], 'art'));
    gleich($folge($lesen($r['faelle']['sk1_ok']['ergebnis'])), 'RPE > RISO-1 > Funkt.', 'sk1: RPE > RISO-1 > Funkt.');
    gleich($folge($lesen($r['faelle']['sk2_ok']['ergebnis'])), 'RISO-1 > Funkt.', 'sk2: RISO-1 > Funkt.');
    gleich($folge($lesen($r['faelle']['leitung_ok']['ergebnis'])), 'RISO-1 > Kabel', 'Leitung: RISO-1 > Kabel');
    gleich($folge($lesen($r['faelle']['prcd']['ergebnis'])), 'RPE > RISO-IN > RISO-OUT > IPE > IBer. > PRCD', 'PRCD-Folge');
    gleich($folge($lesen($r['faelle']['ua']['ergebnis'])), 'RISO-3 > Ua > Funkt.', 'Ua-Folge');
    gleich($folge($lesen($r['faelle']['riso_out']['ergebnis'])), 'RPE > RISO-1 > RISO-OUT > Funkt. > Kabel', 'RISO-OUT-Folge');
    $sicht = $lesen($r['faelle']['sicht_fehler']['ergebnis']);
    gleich([$sicht['schritte'], (int) $sicht['seq']['success'], (int) $sicht['seq']['viewTestOk'], $sicht['abgeleitet']['sicht']], [[], 0, 0, 'maengel'],
        'Sichtprüfung nicht in Ordnung: ohne Schritte, Sequenz 0/0, Mängel');
    $ohne = $lesen($r['faelle']['ohne_schritte']['ergebnis']);
    gleich([$ohne['schritte'], (int) $ohne['seq']['success'], (int) $ohne['seq']['typeId']], [[], 1, 2], 'Sequenz ohne Schritte mit success 1, typeId 2');
    $abw = $lesen($r['faelle']['abweichung']['ergebnis']);
    gleich([(int) $abw['seq']['success'], $abw['abgeleitet']['abweichung']], [1, true], 'Abweichung: Gerät meldet bestanden, abgeleitet nicht');
    $leitFehler = $lesen($r['faelle']['leitung_fehler']['ergebnis']);
    $kabel = $leitFehler['schritte'][1];
    pruefe($kabel['art'] === 'Kabel' && $kabel['ok'] === false && in_array(['name' => 'Leit.-Widerst.', 'wert' => '>15.000 Ω', 'gueltig' => false], $kabel['werte'], true),
        'gescheiterte Leitungsprüfung mit „>15.000 Ω“ ungültig');
    $funkt = $lesen($r['faelle']['funkt_fehler']['ergebnis'])['schritte'][1];
    pruefe($funkt['art'] === 'Funkt.' && $funkt['ok'] === false && in_array(['name' => 'IBer.', 'wert' => '0.559 mA', 'gueltig' => false], $funkt['werte'], true),
        'gescheiterte Funktionsprüfung mit IBer.');
    $texte = array_column($alle('SELECT DISTINCT valueStr FROM SqProcValues'), 'valueStr');
    pruefe(in_array('>100.0 MΩ', $texte, true) && in_array('<0.030 mA', $texte, true) && in_array('1 ', $texte, true) && in_array('OK', $texte, true),
        'Werte „>100.0 MΩ“, „<0.030 mA“, „1 “ und „OK“ wie am Gerät');
    pruefe(abs((float) $eins('SELECT Ergebnisse_RSL_gw_rsl FROM Ergebnisse WHERE Ergebnisse_Index = ?', [$r['faelle']['sk1_ok']['ergebnis']]) - 0.3) > 1e-12
        && Messarten::zahl((float) $eins('SELECT Ergebnisse_RSL_gw_rsl FROM Ergebnisse WHERE Ergebnisse_Index = ?', [$r['faelle']['sk1_ok']['ergebnis']])) === '0.3',
        'Grenzwerte als REAL mit float32-Resten, zahl() macht „0.3“');
    gleich((string) $eins('SELECT Ergebnisse_Pruefername FROM Ergebnisse WHERE Ergebnisse_Index = ?', [$r['faelle']['admin']['ergebnis']]), 'Admin', 'Prüfer „Admin“');
    gleich($alle('SELECT Ergebnisse_Auftragsnummer a, Ergebnisse_Ort o, Ergebnisse_Bemerkung b FROM Ergebnisse WHERE Ergebnisse_Index = ?', [$r['faelle']['admin']['ergebnis']])[0],
        ['a' => 'A-2026-0042', 'o' => 'Halle 3', 'b' => 'Stecker erneuert'], 'Auftrag, Ort, Bemerkung');
    $doppelt = $alle("SELECT Geraete_Kunden_Index k FROM Geraete WHERE Geraete_ID = '000200' ORDER BY 1");
    gleich(array_column($doppelt, 'k'), [2, 3], 'dieselbe Kennung bei zwei Kunden');
    gleich((int) $eins("SELECT COUNT(*) FROM Geraete WHERE Geraete_ID = '000300' AND Geraete_Kunden_Index = 1"), 2, 'dieselbe Kennung zweimal beim selben Kunden');
    gleich((string) $eins('SELECT Geraete_ID FROM Geraete WHERE Geraete_Index = ?', [$r['faelle']['leitung_ok']['geraet']]), ' 000103', 'führendes Leerzeichen in der Kennung');
    gleich((int) $eins('SELECT COUNT(*) FROM Geraete2AdminQuestions WHERE GeraeteId = ?', [$r['faelle']['leitung_ok']['geraet']]), 0, '… und ohne Fragen');
    gleich((int) $eins('SELECT COUNT(*) FROM Geraete2AdminQuestions WHERE GeraeteId = ?', [$r['faelle']['sk1_ok']['geraet']]), 2, 'sonst Fragen 1 und 2');
    gleich((string) $eins("SELECT TRIM(Geraete_ID) FROM Geraete WHERE Geraete_Index = ?", [$r['faelle']['leere_kennung']['geraet']]), '', 'leere Kennung');
    gleich($alle('SELECT Geraete_Pruefdatum d, Geraete_SK sk FROM Geraete WHERE Geraete_Index = ?', [$r['faelle']['ohne_pruefung']['geraet']])[0],
        ['d' => '1900-01-01T00:00:00.0000000', 'sk' => 3], 'nie geprüft (1900), Schutzklasse III');
    gleich((int) $eins('SELECT AusserBetrieb FROM Geraete WHERE Geraete_Index = ?', [$r['faelle']['ausser_betrieb']['geraet']]), 1, 'außer Betrieb');
    gleich((string) $eins('SELECT Kunden_Nummer FROM Kunden WHERE Kunden_Index = 2'), 'K-20001', 'Kunde 2 mit Kundennummer');
    gleich($eins('SELECT Kunden_Nummer FROM Kunden WHERE Kunden_Index = 3'), null, 'Kunde 3 ohne');
    gleich((int) $eins('SELECT COUNT(*) FROM Geraete WHERE manufacturerId IS NOT NULL AND manufacturerId IN (SELECT id FROM Manufacturers)'), 3, 'Hersteller über Manufacturers');
    gleich((string) $eins('SELECT Geraete_Datum_Naechste_Pruefung FROM Geraete WHERE Geraete_Index = ?', [$r['faelle']['sk1_ok']['geraet']]), '2027-01-12 00:00:00', 'nächste Prüfung + 12 Monate, 00:00:00');
    // Zeiten in allen Formaten – und alle lesbar
    $form = static fn (string $t): string => (string) preg_replace('/\d/', '9', $t);
    $formate = array_unique(array_map($form, array_column($alle('SELECT Ergebnisse_Datum d FROM Ergebnisse'), 'd')));
    sort($formate);
    gleich($formate, ['9999-99-99 99:99:99', '9999-99-99 99:99:99.9999999', '9999-99-99T99:99:99.9999999'], 'Prüfzeiten in allen Formaten');
    $aenderung = array_unique(array_map($form, array_column($alle('SELECT Aenderungsdatum d FROM Geraete'), 'd')));
    gleich(count($aenderung), 5, 'Änderungsdatum der Geräte in fünf Formaten (Bruchteile 0, 5, 6, 7 Stellen, T)');
    $unlesbar = [];
    foreach ($alle("SELECT Ergebnisse_Datum d FROM Ergebnisse UNION ALL SELECT Geraete_Pruefdatum FROM Geraete UNION ALL SELECT Aenderungsdatum FROM Geraete
        UNION ALL SELECT Geraete_Datum_Naechste_Pruefung FROM Geraete UNION ALL SELECT Aenderungsdatum FROM Kunden") as $z) {
        if (!str_starts_with((string) $z['d'], '1900') && Messarten::zeitLesen((string) $z['d']) === null) {
            $unlesbar[] = $z['d'];
        }
    }
    gleich($unlesbar, [], 'jede Zeit des Musters lesbar');
    gleich(Messarten::zeitLesen((string) $eins('SELECT Ergebnisse_Datum FROM Ergebnisse WHERE Ergebnisse_Index = ?', [$r['faelle']['sk2_ok']['ergebnis']])),
        '2026-07-14T12:30:00Z', 'Sommerzeit-Fall in UTC');
    $p = null;
    // Deterministisch
    $zweite = $ordner . '/' . $kennung . '-muster2.db';
    $dritte = $ordner . '/' . $kennung . '-muster3.db';
    $dateien[] = $zweite;
    $dateien[] = $dritte;
    BenningMuster::schreiben($zweite);
    BenningMuster::schreiben($dritte, ['start' => 2]);
    pruefe(hash_file('sha256', $muster) === hash_file('sha256', $zweite), 'gleicher Startwert → gleiche Bytes');
    pruefe(hash_file('sha256', $muster) !== hash_file('sha256', $dritte), 'anderer Startwert → andere Messwerte');
    $geworfen = false;
    try {
        BenningMuster::schreiben($zweite);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen && hash_file('sha256', $muster) === hash_file('sha256', $zweite), 'vorhandenes Ziel → abgelehnt, nichts überschrieben');
    // Ohne Fälle, mit zusätzlichen Geräten und eigener Kundennummer
    $mehr = $ordner . '/' . $kennung . '-mehr.db';
    $dateien[] = $mehr;
    $rm = BenningMuster::schreiben($mehr, ['faelle' => false, 'kunden' => 4, 'zusaetzlich' => 9, 'pruefungen_je_geraet' => 2,
        'kunden_nummern' => [1 => 'PM-EIGEN', 4 => 'PM-K7'], 'eigene_ablaeufe' => false]);
    gleich([$rm['kunden'], $rm['geraete'], $rm['pruefungen'], $rm['faelle']], [4, 9, 18, []], 'zusätzliche Geräte: 9 Geräte, 18 Prüfungen, keine Fälle');
    $pm = new PDO('sqlite:' . $mehr, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    gleich([(string) $pm->query('SELECT Kunden_Nummer FROM Kunden WHERE Kunden_Index = 1')->fetchColumn(),
        (string) $pm->query('SELECT Kunden_Nummer FROM Kunden WHERE Kunden_Index = 4')->fetchColumn(),
        (int) $pm->query('SELECT COUNT(*) FROM Pruefung WHERE Pruefung_ID >= 1000000')->fetchColumn(),
        (int) $pm->query('SELECT COUNT(DISTINCT Geraete_Kunden_Index) FROM Geraete')->fetchColumn()], ['PM-EIGEN', 'PM-K7', 0, 4],
        'Kundennummern wie verlangt, ohne eigene Abläufe, auf alle Kunden verteilt');
    $pm = null;
    // pruefungAnhaengen: „am Gerät“ eine Prüfung dazu
    $vorher = hash_file('sha256', $zweite);
    $neu = BenningMuster::pruefungAnhaengen($zweite, ['kennung' => '000101', 'datum' => '2027-01-10 08:30:00']);
    pruefe($neu['ergebnis'] === 20 && $neu['geraet'] === $r['faelle']['sk1_ok']['geraet'], 'pruefungAnhaengen: neue Ergebniszeile am richtigen Gerät');
    $p2 = new PDO('sqlite:' . $zweite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $z = $p2->query('SELECT Geraete_Pruefdatum, Geraete_Datum_Naechste_Pruefung, Aenderungsdatum FROM Geraete WHERE Geraete_Index = ' . $neu['geraet'])->fetch();
    gleich($z, ['Geraete_Pruefdatum' => '2027-01-10 08:30:00', 'Geraete_Datum_Naechste_Pruefung' => '2028-01-10 00:00:00', 'Aenderungsdatum' => '2027-01-10 08:30:00'],
        'Prüfdatum, nächste Prüfung und Änderung am Gerät');
    $seq = $p2->query('SELECT id, success, startDate FROM Sequences WHERE testResultId = ' . $neu['ergebnis'])->fetch();
    gleich([(int) $seq['success'], $seq['startDate']], [1, '2027-01-10 08:30:00'], 'Sequenz der neuen Prüfung');
    pruefe((int) $p2->query('SELECT COUNT(*) FROM SqProcedures WHERE sequenceId = ' . (int) $seq['id'])->fetchColumn() === 3
        && (int) $p2->query('SELECT COUNT(*) FROM TestResult2Admin2Answers WHERE testResultId = ' . $neu['ergebnis'])->fetchColumn() === 2,
        'Schritte (SK I) und Antworten der neuen Prüfung');
    gleich((string) $p2->query("SELECT lastchange FROM timestamp WHERE tablename = 'Ergebnisse'")->fetchColumn(),
        (string) (new DateTimeImmutable('2027-01-10 08:30:00', new DateTimeZone('UTC')))->getTimestamp(), 'Trigger-Zeitstempel ohne Uhr');
    $p2 = null;
    pruefe($vorher !== hash_file('sha256', $zweite), 'Datei geändert');
    $geworfen = false;
    try {
        BenningMuster::pruefungAnhaengen($zweite, ['kennung' => '000200']);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'pruefungAnhaengen: mehrdeutige Kennung abgelehnt');
    $neu2 = BenningMuster::pruefungAnhaengen($zweite, ['kennung' => '000200', 'kunden_nummer' => 'K-20001', 'plan' => 'leitung_fehler', 'datum' => '2027-02-01 09:00:00']);
    gleich($neu2['geraet'], $r['faelle']['doppelt_k2']['geraet'], 'mit Kundennummer eindeutig');
    $p2 = new PDO('sqlite:' . $zweite, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    gleich((int) $p2->query('SELECT success FROM Sequences WHERE testResultId = ' . $neu2['ergebnis'])->fetchColumn(), 0, 'Plan „leitung_fehler“ angehängt');
    gleich((string) $p2->query('PRAGMA integrity_check')->fetchColumn(), 'ok', 'integrity_check nach dem Anhängen');
    $p2 = null;
    $geworfen = false;
    try {
        BenningMuster::pruefungAnhaengen($zweite, ['kennung' => '999999']);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'pruefungAnhaengen: unbekannte Kennung abgelehnt');

    // ================================================ F – Prueflinge (Grundteil)
    gleich(Prueflinge::eindeutig(null, '  AbC-1 '), 'e:abc-1', 'eindeutig: eigener Bestand, getrimmt, klein');
    gleich(Prueflinge::eindeutig(42, 'ÄBC'), 'k42:äbc', 'eindeutig: Kunde, Umlaut klein');
    $eigen = Prueflinge::anlegen(['kennung' => '  ' . $kennung . '-E1 ', 'bezeichnung' => str_repeat('Kabeltrommel ', 20),
        'leitungslaenge' => '25,0', 'querschnitt' => 1.5, 'leiter' => '3', 'schutzklasse' => '1', 'intervall_monate' => 12,
        'technik' => ['vde' => 1, 'sichtpruefung' => true, 'pruefoptionen' => 0]]);
    $angelegt['prueflinge'][] = $eigen;
    $e = Prueflinge::find($eigen);
    gleich([$e['kennung'], $e['eindeutig'], mb_strlen($e['bezeichnung']), $e['leitungslaenge'], $e['querschnitt'], $e['leiter'], $e['kunde_id'], $e['ausser_betrieb']],
        [$kennung . '-E1', 'e:' . $kennung . '-e1', 120, '25', '1.5', '3', null, false], 'anlegen: getrimmt, gekürzt, Zahlen als Dezimaltext');
    gleich($e['technik'], ['pruefoptionen' => 0, 'sichtpruefung' => true, 'vde' => 1], 'technik als Felder');
    $doppelt = false;
    try {
        Prueflinge::anlegen(['kennung' => strtoupper($kennung) . '-E1']);
    } catch (PDOException) {
        $doppelt = true;
    }
    pruefe($doppelt, 'dieselbe Kennung (andere Schreibweise) beim selben Besitzer → abgelehnt');
    $kundeP = Prueflinge::anlegen(['kunde_id' => $kundeA, 'kennung' => $kennung . '-E1', 'bezeichnung' => 'Mischpult', 'schutzklasse' => '2']);
    $angelegt['prueflinge'][] = $kundeP;
    pruefe($kundeP > 0 && Prueflinge::find($kundeP)['eindeutig'] === 'k' . $kundeA . ':' . $kennung . '-e1', 'dieselbe Kennung bei einem Kunden erlaubt');
    gleich(Prueflinge::findEindeutig(null, strtoupper($kennung) . '-E1')['id'] ?? null, $eigen, 'findEindeutig ohne Groß/Klein (eigen)');
    gleich(Prueflinge::findEindeutig($kundeA, $kennung . '-e1')['id'] ?? null, $kundeP, 'findEindeutig beim Kunden');
    gleich(Prueflinge::findEindeutig($kundeB, $kennung . '-E1'), null, 'findEindeutig bei anderem Kunden: keiner');
    foreach ([['kennung' => '   '], ['kennung' => 'x', 'schutzklasse' => '4'], ['kennung' => 'x', 'letztes_ergebnis' => 'gut'],
        ['kennung' => 'x', 'letzte_pruefung' => '2026-02-30'], ['kennung' => 'x', 'leitungslaenge' => 'lang'], ['kennung' => 'x', 'intervall_monate' => -1],
        ['kennung' => 'x', 'kunde_id' => $kundeA, 'inventar_id' => 5], ['kennung' => 'x', 'technik' => ['a' => 1.5]], ['bezeichnung' => 'ohne Kennung']] as $falsch) {
        $geworfen = false;
        try {
            $angelegt['prueflinge'][] = Prueflinge::anlegen($falsch);
        } catch (InvalidArgumentException) {
            $geworfen = true;
        }
        pruefe($geworfen, 'anlegen abgelehnt: ' . json_encode($falsch));
    }
    $geworfen = false;
    try {
        Prueflinge::aendern($kundeP, ['inventar_id' => 5]);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'aendern: Inventarstück an einem Kundengerät abgelehnt');
    Prueflinge::aendern($kundeP, ['kennung' => $kennung . '-K1 ', 'bemerkung' => "Zeile 1\nZeile 2", 'ausser_betrieb' => true, 'nennleistung' => '2000']);
    $kp = Prueflinge::find($kundeP);
    gleich([$kp['kennung'], $kp['eindeutig'], $kp['bemerkung'], $kp['ausser_betrieb'], $kp['nennleistung'], $kp['bezeichnung']],
        [$kennung . '-K1', 'k' . $kundeA . ':' . $kennung . '-k1', "Zeile 1\nZeile 2", true, '2000', 'Mischpult'], 'aendern: eindeutig neu, nur übergebene Felder');
    Prueflinge::aendern($kundeP, ['kunde_id' => $kundeB]);
    gleich(Prueflinge::find($kundeP)['eindeutig'], 'k' . $kundeB . ':' . $kennung . '-k1', 'aendern: anderer Besitzer → eindeutig neu');
    Prueflinge::aendern($kundeP, ['kunde_id' => $kundeA]);
    $geworfen = false;
    try {
        Prueflinge::aendern(PHP_INT_MAX, ['bezeichnung' => 'x']);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'aendern: unbekannter Prüfling wirft');
    gleich(array_column(array_filter(Prueflinge::fuerBesitzer($kundeA), static fn (array $z): bool => str_starts_with($z['kennung'], $kennung)), 'id'), [$kundeP], 'fuerBesitzer(Kunde)');
    gleich(array_values(array_filter(array_column(Prueflinge::fuerBesitzer($kundeA, false), 'id'), static fn (int $id): bool => $id === $kundeP)), [], 'fuerBesitzer ohne außer Betrieb');
    pruefe(in_array($eigen, array_column(Prueflinge::fuerBesitzer(null), 'id'), true), 'fuerBesitzer(eigen)');

    // ================================================ E – Pruefungen
    $p1 = $normal('2026-03-02 10:00:00', ' ' . $kennung . '-E1');
    gleich($p1['schluessel'], Pruefungen::schluessel($p1, $kennung . '-E1'), 'schluessel: Kennung getrimmt');
    $andererText = $p1;
    $andererText['sicht_antworten'][0]['text'] = 'umformuliert';
    $andererText['schritte'][0]['titel'] = 'anders';
    $andererText['ref'] = '99';
    gleich(Pruefungen::schluessel($andererText, $kennung . '-E1'), $p1['schluessel'], 'schluessel: Fragetext, Titel und ref zählen nicht');
    $andererWert = $p1;
    $andererWert['schritte'][0]['werte'][0]['wert'] = '0.103 Ω';
    pruefe(Pruefungen::schluessel($andererWert, $kennung . '-E1') !== $p1['schluessel'], 'schluessel: ein anderer Wert zählt');
    pruefe(Pruefungen::schluessel($p1, $kennung . '-E2') !== $p1['schluessel'], 'schluessel: die Kennung zählt');
    $a1 = Pruefungen::anlegen($eigen, $p1, null);
    $angelegt['pruefungen'][] = $a1['id'];
    gleich([$a1['neu'], $a1['pruefling_id']], [true, $eigen], 'anlegen: neu');
    $a2 = Pruefungen::anlegen($eigen, $p1, null);
    gleich($a2, ['id' => $a1['id'], 'pruefling_id' => $eigen, 'neu' => false], 'anlegen zweimal → neu=false, dieselbe');
    $a3 = Pruefungen::anlegen($kundeP, $p1, 5);
    gleich($a3, ['id' => $a1['id'], 'pruefling_id' => $eigen, 'neu' => false], 'derselbe Schlüssel bei anderem Prüfling → die vorhandene');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM gp_pruefungen WHERE schluessel = ?', [$p1['schluessel']]), 1, 'nur eine Zeile');
    $f = Pruefungen::find($a1['id']);
    gleich([$f['geprueft_am'], $f['am_geraet'], $f['ergebnis'], $f['sicht'], $f['kennung'], $f['kunde_id'], $f['import_id']],
        ['2026-03-02T09:00:00Z', '2026-03-02 10:00:00', 'bestanden', 'ok', $kennung . '-E1', null, null], 'find: Spalten');
    pruefe(!isset($f['daten']['ref']) && !isset($f['daten']['geraet_ref']) && $f['daten']['schritte'][0]['werte'][0]['wert'] === '0.102 Ω', 'find: daten ohne ref/geraet_ref');
    gleich((string) Database::scalar('SELECT daten FROM gp_pruefungen WHERE id = ?', [$a1['id']]),
        Kanon::json(array_diff_key($p1, ['ref' => 1, 'geraet_ref' => 1])), 'daten = Kanon-JSON der Normalform');
    foreach ([['schluessel' => 'kaputt'], ['geprueft_am' => '2026-03-02 09:00:00'], ['ergebnis' => 'gut'], ['sicht' => 'prima'],
        ['bemerkung' => 1.5]] as $aenderung) {
        $kaputt = array_replace($p1, $aenderung);
        if (!isset($aenderung['schluessel'])) {
            $kaputt['schluessel'] = hash('sha256', $kennung . json_encode($aenderung));
        }
        $geworfen = false;
        try {
            Pruefungen::anlegen($eigen, $kaputt);
        } catch (InvalidArgumentException) {
            $geworfen = true;
        }
        pruefe($geworfen, 'anlegen abgelehnt: ' . json_encode($aenderung));
    }
    $lang = $normal('2026-03-05 09:00:00', $kennung . '-E1', ['ort' => str_repeat('Ort ', 60), 'bemerkung' => "Eins\nZwei", 'pruefer' => "Max\x07 Muster"]);
    $a4 = Pruefungen::anlegen($eigen, $lang, null);
    $angelegt['pruefungen'][] = $a4['id'];
    $z4 = Pruefungen::find($a4['id']);
    gleich([mb_strlen($z4['ort']), $z4['bemerkung'], $z4['pruefer']], [120, "Eins\nZwei", 'Max Muster'], 'Texte gesäubert und gekürzt');
    $p5 = $normal('2026-03-04 11:00:00', $kennung . '-K1', ['ergebnis' => 'nicht_bestanden', 'sicht' => 'maengel', 'pruefer' => 'Admin']);
    $a5 = Pruefungen::anlegen($kundeP, $p5, null);
    $angelegt['pruefungen'][] = $a5['id'];
    gleich(array_column(Pruefungen::fuerPruefling($eigen), 'id'), [$a4['id'], $a1['id']], 'fuerPruefling: jüngste zuerst');
    $ids = static fn (array $filter): array => array_values(array_filter(array_column(Pruefungen::liste($filter), 'id'),
        static fn (int $id): bool => in_array($id, [$a1['id'], $a4['id'], $a5['id']], true)));
    gleich($ids(['besitzer' => 'eigen']), [$a4['id'], $a1['id']], 'liste: eigener Bestand');
    gleich($ids(['besitzer' => $kundeA]), [$a5['id']], 'liste: Kunde');
    gleich($ids(['ergebnis' => 'nicht_bestanden']), [$a5['id']], 'liste: Ergebnis');
    gleich($ids(['pruefer' => 'Admin']), [$a5['id']], 'liste: Prüfer laut Gerät');
    gleich($ids(['von' => '2026-03-04', 'bis' => '2026-03-04']), [$a5['id']], 'liste: Zeitraum (Ortsdatum)');
    gleich($ids(['von' => '2026-03-02', 'bis' => '2026-03-02']), [$a1['id']], 'liste: ein Tag');
    gleich($ids(['offen' => true]), [$a4['id'], $a5['id'], $a1['id']], 'liste: offen (ohne Bericht)');
    gleich($ids(['offen' => false]), [], 'liste: mit Bericht – keine');
    gleich($ids(['suche' => $kennung . '-k']), [$a5['id']], 'liste: Suche nach Kennung');
    gleich($ids(['suche' => '%']), [], 'liste: % in der Suche ist kein Platzhalter');
    pruefe(array_key_exists('bericht_id', Pruefungen::liste(['pruefling_id' => $eigen])[0]) && Pruefungen::liste(['pruefling_id' => $eigen])[0]['bericht_id'] === null,
        'liste: bericht_id (null = offen)');
    pruefe(!array_key_exists('daten', Pruefungen::liste(['pruefling_id' => $eigen])[0]), 'liste ohne daten');
    gleich(count(Pruefungen::liste(['limit' => 1])), 1, 'liste: limit');

    // Fälligkeit
    gleich(Prueflinge::faelligkeitNeu($eigen), ['letzte_pruefung' => '2026-03-05', 'letztes_ergebnis' => 'bestanden', 'naechste_pruefung' => '2027-03-05'],
        'faelligkeitNeu: bestanden → Prüftag + 12 Monate');
    $e = Prueflinge::find($eigen);
    gleich([$e['letzte_pruefung'], $e['letztes_ergebnis'], $e['naechste_pruefung']], ['2026-03-05', 'bestanden', '2027-03-05'], '… gespeichert');
    gleich(Prueflinge::faelligkeitNeu($kundeP), ['letzte_pruefung' => '2026-03-04', 'letztes_ergebnis' => 'nicht_bestanden', 'naechste_pruefung' => '2026-03-04'],
        'faelligkeitNeu: nicht bestanden → am Prüftag fällig');
    // Eine spätere, bestandene Prüfung gegen Mitternacht (UTC am Vortag) – Ortsdatum zählt.
    $p6 = $normal('2026-03-31 00:30:00', $kennung . '-K1');
    $a6 = Pruefungen::anlegen($kundeP, $p6, null);
    $angelegt['pruefungen'][] = $a6['id'];
    Prueflinge::aendern($kundeP, ['intervall_monate' => 6]);
    gleich(Prueflinge::faelligkeitNeu($kundeP), ['letzte_pruefung' => '2026-03-31', 'letztes_ergebnis' => 'bestanden', 'naechste_pruefung' => '2026-09-30'],
        'faelligkeitNeu: jüngste zählt, Ortsdatum, Monatsende gekappt');
    Prueflinge::aendern($kundeP, ['intervall_monate' => 0]);
    gleich(Prueflinge::faelligkeitNeu($kundeP)['naechste_pruefung'], null, 'faelligkeitNeu: ohne Intervall keine nächste Prüfung');
    $ohnePruefung = Prueflinge::anlegen(['kennung' => $kennung . '-E2', 'letzte_pruefung' => '2025-05-01', 'letztes_ergebnis' => 'bestanden',
        'naechste_pruefung' => '2026-05-01']);
    $angelegt['prueflinge'][] = $ohnePruefung;
    gleich(Prueflinge::faelligkeitNeu($ohnePruefung), null, 'faelligkeitNeu ohne Prüfungen → null');
    $o = Prueflinge::find($ohnePruefung);
    gleich([$o['letzte_pruefung'], $o['letztes_ergebnis'], $o['naechste_pruefung']], ['2025-05-01', 'bestanden', '2026-05-01'], '… Angaben bleiben');
    gleich(Prueflinge::faelligkeitNeu(PHP_INT_MAX), null, 'faelligkeitNeu für unbekannten Prüfling → null');
} finally {
    alsBenutzer(null);
    foreach ($angelegt['pruefungen'] as $id) {
        Database::execute('DELETE FROM gp_pruefungen WHERE id = ?', [$id]);
    }
    foreach ($angelegt['prueflinge'] as $id) {
        Database::execute('DELETE FROM gp_prueflinge WHERE id = ?', [$id]);
    }
    foreach ($angelegt['kunden'] as $id) {
        Database::execute('DELETE FROM kunden WHERE id = ?', [$id]);
    }
    foreach ($dateien as $datei) {
        if (is_file($datei)) {
            unlink($datei);
        }
    }
}

ende('geraetepruefung-fundament');
