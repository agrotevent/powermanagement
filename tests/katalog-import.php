<?php
declare(strict_types=1);

/**
 * Katalog-Import: Standard-Verteiler und Aggregate aus einem Paket.
 *
 * Geprüft wird das Format (kein JSON, falsche Art, Format 2, leere Liste,
 * doppelter Schlüssel, unbekannte Art, Stecker ohne Beschreibung … – je eine
 * klare Meldung, nichts angelegt), der Ablauf (neu → übernommen mit
 * Anschlüssen, Schutzorganen samt Vorschaltung, Eigenschaften und
 * Steckertypen → beim nächsten Lesen „schon da“), dass importierte Typen
 * bearbeitbar bleiben und ein erneuter Import die Korrektur nur auf
 * ausdrücklichen Wunsch ersetzt, die Rechte, „alles oder nichts“, die
 * abgelegte Vorschau und die Kommandozeile in einer eigenen Instanz – dort
 * auch jedes mitgelieferte Paket unter resources/kataloge.
 *
 * Eigene Daten tragen eine zufällige Kennung im Namen und Schlüssel und
 * werden am Ende gelöscht; Steckertypen nur, wenn die Prüfung sie angelegt hat.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\KatalogImport;
use App\Services\SchutzService;
use App\Services\VerteilerBundle;
use App\Support\Database;

AnlagenService::grundbestand();
$k = 'pk' . bin2hex(random_bytes(3));
$K = strtoupper($k);
$steckerVorher = array_column(AnlagenService::steckertypen(), 'schluessel');
$benutzer = [];
$rollen = [];
$kennwort = 'Katalog-Pruefung-' . bin2hex(random_bytes(4));
$ordner = sys_get_temp_dir() . '/pm-katalog-' . $k;
mkdir($ordner, 0700, true);
register_shutdown_function(static function () use ($ordner): void {
    // Nur den eigenen Ordner.
    foreach (glob($ordner . '/*') ?: [] as $datei) {
        @unlink($datei);
    }
    @rmdir($ordner);
});

/** Eine Datei im Format verteiler-typ. */
$typ = static function (string $schluessel, array $typ, array $stecker = []): array {
    return ['format' => 1, 'art' => 'verteiler-typ', 'schluessel' => $schluessel, 'stand' => gmdate('c'), 'von' => 'Prüfung',
        'typ' => $typ + ['fest' => false, 'hersteller' => '', 'modell' => '', 'nennleistung_kva' => null, 'nennstrom_a' => null,
            'spannung_v' => 400, 'phasen' => 3, 'eigenschaften' => [], 'steuerung_gesperrt' => false, 'notizen' => '',
            'schutzorgane' => [], 'anschluesse' => []],
        'steckertypen' => $stecker];
};
$paket = static fn (array $typen, string $quelle = 'Prüfkatalog'): array => ['format' => 1, 'art' => 'verteiler-typen', 'quelle' => $quelle, 'typen' => $typen];
$json = static fn (mixed $x): string => (string) json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$angelegt = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE name LIKE ? OR sync_key LIKE ?', [$k . '%', $k . '%']);
$zeile = static function (array $stand, string $schluessel): array {
    foreach ($stand['typen'] as $t) {
        if ($t['schluessel'] === $schluessel) {
            return $t;
        }
    }
    return ['zustand' => 'fehlt', 'fehler' => null, 'unterschiede' => [], 'abweichungen' => [], 'hinweise' => []];
};
$zustaende = static fn (array $stand): array => array_column($stand['typen'], 'zustand', 'schluessel');
$abgang = static fn (string $stecker, int $nr, ?string $schutz = null, string $bezeichnung = ''): array
    => ['richtung' => 'abgang', 'stecker' => $stecker, 'nr' => $nr, 'bezeichnung' => $bezeichnung !== '' ? $bezeichnung : 'X' . $nr,
        'schutz' => $schutz, 'phase' => '', 'notizen' => ''];

// Drei eigene Typen: eine Verteilung mit neuem Stecker, Schutzorganen in
// fremder Reihenfolge mit eigenen Verweisen und einem Stecker in
// Großbuchstaben, ein Generator mit Zahlen als Text, ein Schukoverteiler mit
// einer Eigenschaft, die es hier nicht gibt.
$harting = $k . '-harting';
$t1 = $typ($k . '-pd63', [
    'art' => 'verteilung', 'name' => $k . ' PD 63/32', 'hersteller' => 'Prüfbau', 'modell' => 'PD 63/32', 'nennstrom_a' => 63,
    'eigenschaften' => ['artikelnummer' => 'BSS-' . $K . '-1', 'gehaeuse' => 'Gummi, gelb', 'schutzart' => 'IP44',
        'gewicht_kg' => '38,5', 'zuleitung' => '2,0 m 5G16'],
    'schutzorgane' => [
        ['ref' => 'ls1', 'art' => 'ls', 'bezeichnung' => 'Q2', 'nennstrom_a' => 32, 'charakteristik' => 'C', 'pole' => 3, 'vor' => 'rcd1'],
        ['ref' => 'ls2', 'art' => 'ls', 'bezeichnung' => 'Q3', 'nennstrom_a' => 16, 'charakteristik' => 'B', 'pole' => 1, 'vor' => 'rcd1'],
        ['ref' => 'rcd1', 'art' => 'rcd', 'bezeichnung' => 'F1', 'nennstrom_a' => 63, 'fehlerstrom_ma' => 30, 'rcd_typ' => 'A', 'pole' => 4, 'vor' => 'hs'],
        ['ref' => 'hs', 'art' => 'hauptschalter', 'bezeichnung' => 'Q1', 'nennstrom_a' => 63, 'pole' => 3],
    ],
    'anschluesse' => [
        ['richtung' => 'eingang', 'stecker' => 'cee63', 'nr' => 1, 'bezeichnung' => 'E1', 'schutz' => null, 'phase' => '', 'notizen' => ''],
        $abgang('CEE32', 1, 'ls1'), $abgang('CEE32', 2, 'ls1'), $abgang('schuko', 3, 'ls2'), $abgang($harting, 4, 'rcd1'),
    ],
], [
    ['schluessel' => 'CEE32', 'name' => 'CEE 32 A', 'strom_a' => 32, 'phasen' => 3, 'spannung_v' => 400, 'farbe' => '#dc2626'],
    ['schluessel' => $harting, 'name' => $K . ' Harting 16 A', 'strom_a' => 16, 'phasen' => 3, 'spannung_v' => 400, 'farbe' => '#7c3aed'],
    ['schluessel' => $k . '-unbenutzt', 'name' => $K . ' unbenutzt', 'strom_a' => 16, 'phasen' => 1, 'spannung_v' => 230, 'farbe' => '#000000'],
]);
$t2 = $typ($k . '-sg60', [
    'art' => 'generator', 'name' => $k . ' SG 60', 'hersteller' => 'Prüfbau', 'modell' => 'SG 60', 'nennleistung_kva' => '60', 'phasen' => '3',
    'eigenschaften' => ['artikelnummer' => 'BSS-' . $K . '-2', 'gewicht_kg' => 1200, 'abmessungen' => '3200 × 1100 × 1800',
        'kraftstoff' => 'Diesel', 'tank_l' => 120],
    'schutzorgane' => [
        ['art' => 'hauptschalter', 'bezeichnung' => 'Q1', 'nennstrom_a' => 100, 'pole' => 3],
        ['art' => 'rcd', 'bezeichnung' => 'F1', 'nennstrom_a' => 63, 'fehlerstrom_ma' => 300, 'rcd_typ' => 'B', 'pole' => 4, 'vor' => 's1'],
    ],
    'anschluesse' => [$abgang('powerlock', 1, 's1'), $abgang('cee63', 2, 's2'), $abgang('cee32', 3, 's2')],
]);
$t3 = $typ($k . '-sv16', [
    'art' => 'verteilung', 'name' => $k . ' SV 16', 'nennstrom_a' => 16, 'eigenschaften' => ['gewicht_kg' => 6, 'farbe' => 'gelb'],
    'anschluesse' => [['richtung' => 'eingang', 'stecker' => 'cee16', 'nr' => 1, 'bezeichnung' => 'E1'],
        $abgang('schuko', 1), $abgang('schuko', 2), $abgang('schuko', 3)],
]);
$drei = $paket([$t1, $t2, $t3]);
$schluessel = [$k . '-pd63', $k . '-sg60', $k . '-sv16'];

try {
    $planung = BenutzerService::anlegen($k . '-planung', $kennwort, 'planung', false);
    $technik = BenutzerService::anlegen($k . '-technik', $kennwort, 'technik', false);
    $rollen[] = $ohneSteckerRolle = (int) BenutzerService::rolleSpeichern(null, $k . ' ohne Stecker', '', ['anlagen.view', 'anlagen.manage', 'anlagen.scope.all'])['id'];
    $ohneStecker = BenutzerService::anlegen($k . '-ohne-stecker', $kennwort,
        (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$ohneSteckerRolle]), false);
    $benutzer = [$planung, $technik, $ohneStecker];

    // ---------------------------------------------------------------- Format
    $abgelehnt = static function (string $roh, string $erwartet, string $text) use ($angelegt): void {
        $l = KatalogImport::lesen($roh);
        pruefe(!$l['ok'] && str_contains($l['message'], $erwartet), $text . ': ' . $l['message']);
        gleich($l['typen'], [], $text . ': keine Typen');
        gleich($angelegt(), 0, $text . ': nichts angelegt');
    };
    $abgelehnt('{"format": 1, "art": "verteiler-typen", "typen": [', 'kein gültiges JSON', 'kein JSON');
    $abgelehnt('', 'leer', 'leere Datei');
    $abgelehnt("%PDF-1.7\n…", 'PDF', 'ein PDF statt des Pakets');
    $abgelehnt($json(['format' => 1, 'art' => 'strom', 'typen' => [$t1]]), 'kein Katalog-Paket', 'falsches „art“');
    $abgelehnt($json([$t1, $t2]), 'kein Katalog-Paket', 'eine bloße Liste');
    $abgelehnt($json(['format' => 2] + $drei), 'Format 2', 'Format 2');
    $abgelehnt($json(['format' => 'eins'] + $drei), 'format', 'Format kein Zahlwert');
    $abgelehnt($json($paket([])), 'keine Typen', 'leere Typenliste');
    $abgelehnt($json(['format' => 1, 'art' => 'verteiler-typen']), 'keine Typen', 'ohne Typenliste');
    $abgelehnt($json($paket([$t1, $t2, $t1])), 'mehrfach', 'doppelter Schlüssel');
    $abgelehnt($json($paket(array_fill(0, 501, $t3))), 'höchstens 500', 'mehr als 500 Typen');

    // Fehler eines einzelnen Typs: das Paket ist lesbar, der Typ taugt nicht.
    $einzeln = static function (array $t, string $erwartet, string $text) use ($json, $paket, $angelegt, $zeile, $k): void {
        $l = KatalogImport::lesen($json($paket([$t])));
        $z = $zeile($l, (string) ($t['schluessel'] ?? ''));
        pruefe($l['ok'] && $z['zustand'] === 'fehler' && str_contains((string) $z['fehler'], $erwartet), $text . ': ' . (string) ($z['fehler'] ?? $l['message']));
        $u = KatalogImport::uebernehmen((array) $l['paket'], [(string) ($t['schluessel'] ?? '')], [(string) ($t['schluessel'] ?? '')], ['katalog' => true, 'stecker' => true]);
        pruefe($u['ok'] && $u['neu'] === [] && $u['ueberschrieben'] === [], $text . ': nichts übernommen (' . $u['message'] . ')');
        gleich($angelegt(), 0, $text . ': nichts angelegt');
    };
    $kaputt = static function (array $t, callable $aendern): array {
        $aendern($t);
        return $t;
    };
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['art'] = 'trafo'; }), 'Unbekannte Art „trafo“', 'unbekannte Art');
    $einzeln($kaputt($t1, static function (array &$t) use ($harting): void {
        $t['steckertypen'] = array_values(array_filter($t['steckertypen'], static fn ($s) => $s['schluessel'] !== $harting));
    }), '„' . $harting . '“ fehlt', 'Stecker ohne Beschreibung');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['anschluesse'][1]['stecker'] = 'cee32'; $t['typ']['anschluesse'][1]['nr'] = 2; }),
        'abgang 2 steht zweimal', 'Anschluss doppelt');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['anschluesse'][1]['schutz'] = 'gibtsnicht'; }),
        '„gibtsnicht“ gibt es in der Datei nicht', 'Abgang an unbekanntem Schutzorgan');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['schutzorgane'][3]['vor'] = 'ls1'; }), 'Kreis', 'Vorschaltung im Kreis');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['schutzorgane'][0]['art'] = 'RCD'; }), 'unbekannte Art „RCD“', 'Schutzorgan mit unbekannter Art');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['eigenschaften']['gewicht_kg'] = 'schwer'; }), '„Gewicht“ ist keine Zahl', 'Gewicht als Text');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['name'] = ' '; }), 'keinen Namen', 'ohne Namen');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['schluessel'] = '../' . $t['schluessel']; }), 'ungültig', 'Schlüssel als Pfad');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['format'] = 2; }), 'neueren', 'Typ aus einer neueren Fassung');
    $einzeln($kaputt($t1, static function (array &$t): void { $t['typ']['anschluesse'][0]['richtung'] = 'rein'; }), '„richtung“', 'unbekannte Richtung');
    $einzeln($kaputt($t3, static function (array &$t) use ($abgang): void {
        $t['typ']['anschluesse'] = array_map(static fn ($n) => $abgang('schuko', $n), range(1, KatalogImport::HOECHSTENS_JE_TYP + 1));
    }), 'Mehr als ' . KatalogImport::HOECHSTENS_JE_TYP . ' Anschlüsse', 'zu viele Anschlüsse');

    // ---------------------------------------------------------------- Ablauf: neu → übernehmen → schon da
    $l = KatalogImport::lesen($json($drei));
    pruefe($l['ok'], 'Paket mit drei Typen gelesen');
    gleich($l['quelle'], 'Prüfkatalog', 'Quelle des Pakets');
    gleich($zustaende($l), array_fill_keys($schluessel, 'neu'), 'alle drei neu');
    gleich($l['zahlen'], ['neu' => 3, 'gleich' => 0, 'anders' => 0, 'fehler' => 0], 'Zusammenfassung');
    gleich(array_column($l['steckertypen'], 'name', 'schluessel'), [$harting => $K . ' Harting 16 A'], 'nur der benutzte neue Steckertyp würde angelegt');
    pruefe(str_contains($zeile($l, $k . '-pd63')['kurz'], $K . ' Harting 16 A') && str_contains($zeile($l, $k . '-pd63')['kurz'], '4 Schutzorgane'),
        'Kurzbeschreibung mit Anschlüssen und Schutzorganen: ' . $zeile($l, $k . '-pd63')['kurz']);
    pruefe(str_contains(implode(' ', $zeile($l, $k . '-sv16')['hinweise']), '„farbe“'), 'Hinweis: Eigenschaft, die es hier nicht gibt, bleibt weg');
    gleich($angelegt(), 0, 'Lesen legt nichts an');

    alsBenutzer($planung);
    $u = KatalogImport::uebernehmen((array) $l['paket'], $schluessel, []);
    pruefe($u['ok'], 'übernommen: ' . $u['message']);
    gleich($u['neu'], [$k . ' PD 63/32', $k . ' SG 60', $k . ' SV 16'], 'drei neu angelegt');
    gleich($u['steckertypen'], [$K . ' Harting 16 A'], 'ein Steckertyp angelegt');
    pruefe(str_contains($u['message'], $k . ' PD 63/32') && str_contains($u['message'], '3 neu angelegt'), 'Meldung mit Zahl und Namen');

    $id = static fn (string $s): int => (int) Database::scalar('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$s]);
    [$id1, $id2, $id3] = array_map($id, $schluessel);
    pruefe($id1 > 0 && $id2 > 0 && $id3 > 0, 'die Typen stehen mit ihrem Schlüssel im Katalog');
    gleich(array_map('intval', array_keys($u['ids'])), [$id1, $id2, $id3], 'IDs der angelegten Typen');
    $v1 = AnlagenService::find($id1);
    gleich([(int) $v1['ist_vorlage'], $v1['art'], $v1['site_id'], (float) $v1['nennstrom_a']], [1, 'verteilung', null, 63.0], 'Katalog-Typ ohne Standort, Nennstrom');
    gleich($v1['eigenschaften'], ['artikelnummer' => 'BSS-' . $K . '-1', 'gehaeuse' => 'Gummi, gelb', 'schutzart' => 'IP44', 'zuleitung' => '2,0 m 5G16', 'gewicht_kg' => 38.5],
        'Eigenschaften: Artikelnummer, Gehäuse, Schutzart, Zuleitung, Gewicht (Komma als Zahl)');
    $v2 = AnlagenService::find($id2);
    gleich([$v2['art'], (float) $v2['nennleistung_kva'], (int) $v2['phasen']], ['generator', 60.0, 3], 'Generator mit Nennleistung aus Text');
    gleich($v2['eigenschaften'], ['artikelnummer' => 'BSS-' . $K . '-2', 'kraftstoff' => 'Diesel', 'tank_l' => 120.0, 'gewicht_kg' => 1200.0, 'abmessungen' => '3200 × 1100 × 1800'],
        'Generator: Artikelnummer, Gewicht leer, Abmessungen, Kraftstoff, Tank');
    gleich(AnlagenService::find($id3)['eigenschaften'], ['gewicht_kg' => 6.0], 'unbekannte Eigenschaft bleibt weg');
    gleich(AnlagenService::kurzform($id1), '1× CEE 63 A → 2× CEE 32 A, 1× Schuko, 1× ' . $K . ' Harting 16 A', 'Anschlüsse der Verteilung');
    $hartingId = (int) Database::scalar('SELECT id FROM steckertypen WHERE schluessel = ?', [$harting]);
    pruefe($hartingId > 0 && (string) Database::scalar('SELECT name FROM steckertypen WHERE id = ?', [$hartingId]) === $K . ' Harting 16 A', 'neuer Steckertyp angelegt');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM steckertypen WHERE schluessel = ?', [$k . '-unbenutzt']), 0, 'unbenutzter Steckertyp der Datei nicht angelegt');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM steckertypen WHERE LOWER(schluessel) = 'cee32'"), 1, '„CEE32“ der Datei ist der vorhandene CEE 32 A');

    // Vorschaltung: Q1 (HS) ← F1 (RCD) ← Q2/Q3 (LS), Abgänge an „ihrem“ Schutzorgan
    $schutz = array_column(SchutzService::liste($id1), null, 'bezeichnung');
    gleich(array_keys($schutz), ['Q1', 'F1', 'Q2', 'Q3'], 'Schutzorgane in Baumreihenfolge');
    gleich([$schutz['Q1']['vorgeschaltet_id'], (int) $schutz['F1']['vorgeschaltet_id'], (int) $schutz['Q2']['vorgeschaltet_id'], (int) $schutz['Q3']['vorgeschaltet_id']],
        [null, (int) $schutz['Q1']['id'], (int) $schutz['F1']['id'], (int) $schutz['F1']['id']], 'Vorschaltung HS → RCD → LS');
    gleich([(int) $schutz['F1']['fehlerstrom_ma'], $schutz['F1']['rcd_typ'], $schutz['Q2']['charakteristik'], (float) $schutz['Q3']['nennstrom_a']], [30, 'A', 'C', 16.0], 'Werte der Schutzorgane');
    $anschluss = static fn (int $objekt, string $richtung, int $nr): ?array
        => Database::first('SELECT * FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND nr = ?', [$objekt, $richtung, $nr]);
    gleich([(int) $anschluss($id1, 'abgang', 1)['schutzorgan_id'], (int) $anschluss($id1, 'abgang', 3)['schutzorgan_id'], (int) $anschluss($id1, 'abgang', 4)['schutzorgan_id']],
        [(int) $schutz['Q2']['id'], (int) $schutz['Q3']['id'], (int) $schutz['F1']['id']], 'Abgänge hängen an LS bzw. RCD');
    gleich((int) $anschluss($id1, 'abgang', 4)['steckertyp_id'], $hartingId, 'Abgang mit dem neuen Steckertyp');
    $schutz2 = array_column(SchutzService::liste($id2), null, 'bezeichnung');
    gleich((int) $schutz2['F1']['vorgeschaltet_id'], (int) $schutz2['Q1']['id'], 'Generator: RCD hinter dem Hauptschalter (Verweise ohne „ref“)');

    $audit = Database::first("SELECT * FROM audit_log WHERE action = 'katalog.import' AND details LIKE ? ORDER BY id DESC LIMIT 1", ['%' . $k . ' PD 63/32%']);
    $details = json_decode((string) ($audit['details'] ?? ''), true) ?: [];
    gleich([$audit['username'] ?? null, $details['quelle'] ?? null, $details['neu'] ?? null, $details['ueberschrieben'] ?? null, $details['steckertypen'] ?? null],
        [$k . '-planung', 'Prüfkatalog', $u['neu'], [], [$K . ' Harting 16 A']], 'Audit: Quelle, Namen, Steckertypen');
    pruefe(!str_contains((string) ($audit['details'] ?? ''), 'anschluesse') && !str_contains((string) ($audit['details'] ?? ''), 'BSS-'), 'Audit ohne Dateiinhalte');

    $l = KatalogImport::lesen($json($drei));
    gleich($zustaende($l), array_fill_keys($schluessel, 'gleich'), 'erneut gelesen: alle schon da');
    gleich($l['steckertypen'], [], 'kein Steckertyp mehr neu');
    $u = KatalogImport::uebernehmen((array) $l['paket'], $schluessel, []);
    pruefe($u['ok'] && $u['neu'] === [] && count($u['gleich']) === 3 && str_contains($u['message'], 'Nichts übernommen'), 'nichts zu tun: ' . $u['message']);

    // Eine einzelne verteiler-typ-Datei ist ein Paket mit einem Typ – auch die,
    // die der Verteiler-Abgleich schreibt.
    $l = KatalogImport::lesen($json(VerteilerBundle::typLesen($id1)));
    pruefe($l['ok'] && count($l['typen']) === 1 && $l['typen'][0]['zustand'] === 'gleich', 'einzelne Datei aus typLesen: schon da');
    $l = KatalogImport::lesen($json(['schluessel' => $k . '-einzeln'] + $t3));
    pruefe($l['ok'] && $zustaende($l) === [$k . '-einzeln' => 'neu'] && $l['quelle'] === 'Prüfung', 'einzelne Datei mit neuem Schlüssel: neu, Quelle aus „von“');

    // ---------------------------------------------------------------- Aus dem Katalog anlegen, kopieren
    $buehne = AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $k . ' Bühne', 'vorlage_id' => $id1, 'eigenschaften' => ['schutzart' => 'IP67']]);
    $b = AnlagenService::find((int) $buehne['id']);
    gleich($b['eigenschaften'], ['schutzart' => 'IP67', 'artikelnummer' => 'BSS-' . $K . '-1', 'gehaeuse' => 'Gummi, gelb', 'zuleitung' => '2,0 m 5G16', 'gewicht_kg' => 38.5],
        'aus dem Katalog angelegt: Eigenschaften des Typs, eigene Angabe geht vor');
    gleich([AnlagenService::kurzform((int) $buehne['id']), count(SchutzService::liste((int) $buehne['id']))], [AnlagenService::kurzform($id1), 4], 'mit Anschlüssen und Schutzorganen des Typs');
    $kopie = AnlagenService::kopieren((int) $buehne['id'], $k . ' Bühne 2');
    gleich(AnlagenService::find((int) $kopie['id'])['eigenschaften'], $b['eigenschaften'], 'Kopie behält die Eigenschaften');

    // ---------------------------------------------------------------- Bearbeitbar wie jeder Typ
    // Wie das Formular: alle Felder der Art, Name und Gewicht korrigiert
    $form = AnlagenService::find($id1);
    $form['name'] = $k . ' PD 63/32 (korrigiert)';
    $form['eigenschaften']['gewicht_kg'] = '41';
    $form['ist_vorlage'] = 1;
    pruefe(AnlagenService::speichern($id1, $form)['ok'], 'importierten Typ gespeichert (Name, Gewicht)');
    $v1 = AnlagenService::find($id1);
    gleich([$v1['name'], $v1['eigenschaften']['gewicht_kg'], $v1['eigenschaften']['artikelnummer'], $v1['eigenschaften']['zuleitung'], $v1['eigenschaften']['gehaeuse']],
        [$k . ' PD 63/32 (korrigiert)', 41.0, 'BSS-' . $K . '-1', '2,0 m 5G16', 'Gummi, gelb'], 'Korrektur gespeichert, übrige Eigenschaften bleiben');
    pruefe(AnlagenService::anschluesseAendern($id1, [(int) $anschluss($id1, 'abgang', 1)['id'] => ['bezeichnung' => 'Bühne links',
        'schutzorgan_id' => (int) $schutz['Q2']['id'], 'phase' => '', 'notizen' => '']])['ok'], 'Anschluss umbenannt');
    pruefe(SchutzService::speichern($id1, (int) $schutz['Q3']['id'], ['art' => 'ls', 'bezeichnung' => 'Q3', 'nennstrom_a' => '13', 'charakteristik' => 'B',
        'pole' => 1, 'vorgeschaltet_id' => (int) $schutz['F1']['id']])['ok'], 'Schutzorgan geändert');
    pruefe(AnlagenService::anzahlSetzen($id1, ['abgang' => [(int) Database::scalar("SELECT id FROM steckertypen WHERE schluessel = 'schuko'") => 2]])['ok'], 'Schuko-Abgang dazu');
    gleich($anschluss($id1, 'abgang', 1)['bezeichnung'], 'Bühne links', 'Bezeichnung bleibt geändert');

    $l = KatalogImport::lesen($json($drei));
    $z1 = $zeile($l, $k . '-pd63');
    gleich($zustaende($l), [$k . '-pd63' => 'anders', $k . '-sg60' => 'gleich', $k . '-sv16' => 'gleich'], 'nach der Korrektur: „hier anders“');
    gleich($z1['unterschiede'], ['Name', 'Eigenschaften', 'Schutzorgane', 'Anschlüsse'], 'Unterschiede je Feld');
    $abw = array_column($z1['abweichungen'], null, 'feld');
    gleich([$abw['Name']['hier'] ?? '', $abw['Name']['datei'] ?? ''], [$k . ' PD 63/32 (korrigiert)', $k . ' PD 63/32'], 'Abweichung Name: hier und Datei');
    gleich([$abw['Gewicht']['hier'] ?? '', $abw['Gewicht']['datei'] ?? ''], ['41 kg', '38,5 kg'], 'Abweichung Gewicht mit Einheit');
    pruefe(str_contains(($abw['Abgang 1']['hier'] ?? '') . '|' . ($abw['Abgang 1']['datei'] ?? ''), 'Bühne links · an Q2|CEE 32 A · X1 · an Q2'), 'Abweichung je Anschluss');
    pruefe(isset($abw['Abgang 5']) && $abw['Abgang 5']['datei'] === '–', 'zusätzlicher Abgang nur hier');

    // Ohne Auswahl zum Überschreiben bleibt die Korrektur – auch wenn er als „neu“ gewählt ist.
    $u = KatalogImport::uebernehmen((array) $l['paket'], $schluessel, []);
    pruefe($u['ok'] && $u['ueberschrieben'] === [] && $u['uebergangen'] === [$k . ' PD 63/32'], 'ohne Auswahl übergangen: ' . $u['message']);
    gleich([AnlagenService::find($id1)['name'], $anschluss($id1, 'abgang', 1)['bezeichnung'], (float) Database::scalar('SELECT nennstrom_a FROM schutzorgane WHERE id = ?', [(int) $schutz['Q3']['id']])],
        [$k . ' PD 63/32 (korrigiert)', 'Bühne links', 13.0], 'Korrektur bleibt');
    $u = KatalogImport::uebernehmen((array) $l['paket'], [], [$k . '-pd63']);
    pruefe($u['ok'] && $u['ueberschrieben'] === [$k . ' PD 63/32'] && $u['neu'] === [], 'ausdrücklich überschrieben: ' . $u['message']);
    gleich((int) Database::scalar('SELECT id FROM objekte WHERE ist_vorlage = 1 AND sync_key = ?', [$k . '-pd63']), $id1, 'derselbe Eintrag, ersetzt');
    $v1 = AnlagenService::find($id1);
    gleich([$v1['name'], $v1['eigenschaften']['gewicht_kg'], AnlagenService::kurzform($id1), $anschluss($id1, 'abgang', 1)['bezeichnung']],
        [$k . ' PD 63/32', 38.5, '1× CEE 63 A → 2× CEE 32 A, 1× Schuko, 1× ' . $K . ' Harting 16 A', 'X1'], 'Stand der Datei');
    gleich($zustaende(KatalogImport::lesen($json($drei))), array_fill_keys($schluessel, 'gleich'), 'danach wieder schon da');
    gleich(AnlagenService::find((int) $buehne['id'])['name'], $k . ' Bühne', 'Anlagen aus dem Typ bleiben, wie sie sind');

    // Gelöscht, dann wieder neu
    AnlagenService::loeschen($id3);
    gleich($zeile(KatalogImport::lesen($json($drei)), $k . '-sv16')['zustand'], 'neu', 'gelöschter Typ ist beim nächsten Import wieder neu');

    // Ein Typ ohne Schlüssel gleichen Namens wird gefunden (wie im Verteiler-Abgleich), nicht doppelt angelegt.
    $hand = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $k . ' SV 16', 'ist_vorlage' => 1, 'ohne_anschluesse' => true])['id'];
    $l = KatalogImport::lesen($json($drei));
    gleich([$zeile($l, $k . '-sv16')['zustand'], $zeile($l, $k . '-sv16')['id']], ['anders', $hand], 'von Hand angelegter Typ gleichen Namens: „hier anders“');
    gleich((string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [$hand]), '', 'Lesen vergibt keinen Schlüssel');
    KatalogImport::uebernehmen((array) $l['paket'], [], [$k . '-sv16']);
    gleich([(int) Database::scalar('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1 AND name = ?', [$k . ' SV 16']),
        (string) Database::scalar('SELECT sync_key FROM objekte WHERE id = ?', [$hand])], [1, $k . '-sv16'], 'überschrieben statt doppelt, Schlüssel jetzt fest');
    $id3 = $hand;

    // ---------------------------------------------------------------- Rechte
    $neuerTyp = $typ($k . '-pd32', ['art' => 'verteilung', 'name' => $k . ' PD 32', 'anschluesse' => [$abgang('cee16', 1)]]);
    $mitStecker = $typ($k . '-socapex', ['art' => 'verteilung', 'name' => $k . ' Socapex', 'anschluesse' => [$abgang($k . '-socapex', 1)]],
        [['schluessel' => $k . '-socapex', 'name' => $K . ' Socapex 19-pol.', 'strom_a' => 16, 'phasen' => 3, 'spannung_v' => 400, 'farbe' => '#0f766e']]);
    $rechte = $paket([$neuerTyp, $mitStecker]);
    $beide = [$k . '-pd32', $k . '-socapex'];
    alsBenutzer(null);
    $u = KatalogImport::uebernehmen($rechte, $beide, []);
    pruefe(!$u['ok'] && str_contains($u['message'], 'alle Standorte'), 'ohne Anmeldung abgelehnt');
    alsBenutzer($technik);
    gleich(KatalogImport::erlaubt(), ['katalog' => false, 'stecker' => false], 'Technik: Anlagen ändern, aber nicht alle Standorte');
    $u = KatalogImport::uebernehmen($rechte, $beide, []);
    pruefe(!$u['ok'] && str_contains($u['message'], 'alle Standorte'), 'ohne alles() abgelehnt: ' . $u['message']);
    alsBenutzer($ohneStecker);
    gleich(KatalogImport::erlaubt(), ['katalog' => true, 'stecker' => false], 'ohne „stecker.manage“');
    $u = KatalogImport::uebernehmen($rechte, $beide, []);
    pruefe(!$u['ok'] && str_contains($u['message'], $K . ' Socapex 19-pol.') && str_contains($u['message'], 'Steckverbinder-Katalog'), 'neuer Steckertyp ohne Recht abgelehnt: ' . $u['message']);
    gleich([$id($k . '-pd32'), $id($k . '-socapex'), (int) Database::scalar('SELECT COUNT(*) FROM steckertypen WHERE schluessel = ?', [$k . '-socapex'])], [0, 0, 0],
        'nichts übernommen – auch der Typ ohne neuen Stecker nicht');
    $u = KatalogImport::uebernehmen($rechte, [$k . '-pd32'], []);
    pruefe($u['ok'] && $u['neu'] === [$k . ' PD 32'] && $u['uebergangen'] === [$k . ' Socapex'], 'ohne den neuen Stecker übernommen: ' . $u['message']);
    alsBenutzer($planung);
    $u = KatalogImport::uebernehmen($rechte, $beide, []);
    pruefe($u['ok'] && $u['neu'] === [$k . ' Socapex'] && $u['steckertypen'] === [$K . ' Socapex 19-pol.'], 'mit „stecker.manage“: Typ und Steckertyp angelegt');

    // ---------------------------------------------------------------- Alles oder nichts
    if (Database::driver() === 'sqlite') {
        $a = $typ($k . '-ganz-a', ['art' => 'verteilung', 'name' => $k . ' ganz A']);
        $bb = $typ($k . '-ganz-b', ['art' => 'verteilung', 'name' => $k . ' ganz B']);
        Database::pdo()->exec("CREATE TEMP TRIGGER pruefung_{$k} BEFORE INSERT ON objekte WHEN NEW.name = '{$k} ganz B' BEGIN SELECT RAISE(ABORT, 'Prüfung'); END");
        $vorherAudit = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'katalog.import'");
        $log = ini_set('error_log', '/dev/null');   // die erwartete Fehlermeldung nicht ausgeben
        $u = KatalogImport::uebernehmen($paket([$a, $bb]), [$k . '-ganz-a', $k . '-ganz-b'], []);
        ini_set('error_log', (string) $log);
        Database::pdo()->exec("DROP TRIGGER pruefung_{$k}");
        pruefe(!$u['ok'] && str_contains($u['message'], 'Nichts übernommen') && !str_contains($u['message'], 'RAISE'), 'Fehler beim Speichern: ' . $u['message']);
        gleich([$id($k . '-ganz-a'), (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'katalog.import'")], [0, $vorherAudit],
            'zurückgerollt: auch der erste Typ fehlt, kein Audit-Eintrag');
        pruefe(!Database::pdo()->inTransaction(), 'keine offene Transaktion');
    }

    // ---------------------------------------------------------------- Vorschau ablegen
    $kennung = KatalogImport::ablegen($drei);
    pruefe(preg_match('/^[0-9a-f]{32}$/', $kennung) === 1, 'Kennung der Vorschau');
    gleich(KatalogImport::abgelegt($kennung), $drei, 'abgelegtes Paket wieder gelesen');
    gleich([KatalogImport::abgelegt('../config'), KatalogImport::abgelegt(str_repeat('0', 32))], [null, null], 'fremde Kennungen: nichts');
    $pfad = APP_ROOT . '/storage/cache/katalog-import-' . $kennung . '.json';
    touch($pfad, time() - KatalogImport::VORSCHAU_DAUER - 10);
    gleich(KatalogImport::abgelegt($kennung), null, 'nach einer Stunde verfallen');
    $alt = KatalogImport::ablegen($drei);
    touch(APP_ROOT . '/storage/cache/katalog-import-' . $alt . '.json', time() - KatalogImport::VORSCHAU_DAUER - 10);
    $frisch = KatalogImport::ablegen($drei);
    pruefe(KatalogImport::aufraeumen() >= 1 && !is_file(APP_ROOT . '/storage/cache/katalog-import-' . $alt . '.json'), 'Wartung räumt alte Vorschauen weg');
    pruefe(KatalogImport::abgelegt($frisch) !== null, 'frische Vorschau bleibt');
    KatalogImport::verwerfen($frisch);
    gleich(KatalogImport::abgelegt($frisch), null, 'verworfen');

    // ---------------------------------------------------------------- Kommandozeile in eigener Instanz
    $wurzel = dirname(__DIR__);
    $vorlage = is_file($wurzel . '/config/config.php') ? $wurzel . '/config/config.php' : $wurzel . '/config/config.example.php';
    file_put_contents($ordner . '/config.php', '<?php
$c = require ' . var_export($vorlage, true) . ';
$c["app"]["demo"] = true;
$c["database"]["driver"] = "sqlite";
$c["database"]["path"] = ' . var_export($ordner . '/datenbank.sqlite', true) . ';
return $c;
');
    $power = static function (array $argumente) use ($wurzel, $ordner): array {
        $p = proc_open(array_merge(['php', $wurzel . '/bin/power'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $rohre, $wurzel, ['POWERMANAGEMENT_CONFIG' => $ordner . '/config.php'] + getenv());
        $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);
        return [proc_close($p), (string) $aus];
    };
    [$rc] = $power(['install', 'pruefer', $kennwort, '--ohne-wechsel']);
    gleich($rc, 0, 'eigene Instanz eingerichtet');
    $db = new PDO('sqlite:' . $ordner . '/datenbank.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $dort = static function (string $sql, array $werte = []) use ($db): mixed {
        $s = $db->prepare($sql);
        $s->execute($werte);
        return $s->fetchColumn();
    };
    file_put_contents($ordner . '/paket.json', $json($drei));
    [$rc, $aus] = $power(['katalog:import', $ordner . '/paket.json', '--pruefen']);
    pruefe($rc === 0 && substr_count($aus, '  neu ') === 3 && str_contains($aus, 'nichts geändert'), 'CLI --pruefen: je Typ eine Zeile');
    gleich((int) $dort('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1'), 0, 'CLI --pruefen legt nichts an');
    [$rc, $aus] = $power(['katalog:import', $ordner . '/paket.json']);
    pruefe($rc === 0 && str_contains($aus, '3 neu angelegt'), 'CLI: angelegt – ' . trim($aus));
    gleich((int) $dort('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1 AND sync_key LIKE ?', [$k . '-%']), 3, 'CLI: drei Typen in der Instanz');
    gleich($dort("SELECT username FROM audit_log WHERE action = 'katalog.import' ORDER BY id DESC LIMIT 1"), 'cli', 'Audit: Akteur „cli“');
    $db->prepare('UPDATE objekte SET name = ? WHERE sync_key = ?')->execute([$k . ' SG 60 (geprüft)', $k . '-sg60']);
    [$rc, $aus] = $power(['katalog:import', $ordner . '/paket.json']);
    pruefe($rc === 0 && str_contains($aus, '  anders  ' . $k . ' SG 60') && str_contains($aus, '--ueberschreiben'), 'CLI: hier anders, bleibt');
    gleich($dort('SELECT name FROM objekte WHERE sync_key = ?', [$k . '-sg60']), $k . ' SG 60 (geprüft)', 'CLI ohne --ueberschreiben: Korrektur bleibt');
    [$rc, $aus] = $power(['katalog:import', $ordner . '/paket.json', '--ueberschreiben']);
    pruefe($rc === 0 && str_contains($aus, '1 überschrieben'), 'CLI --ueberschreiben');
    gleich($dort('SELECT name FROM objekte WHERE sync_key = ?', [$k . '-sg60']), $k . ' SG 60', 'CLI: ersetzt');
    file_put_contents($ordner . '/kaputt.json', '{"format": 2, "art": "verteiler-typen", "typen": []}');
    [$rc, $aus] = $power(['katalog:import', $ordner . '/kaputt.json']);
    pruefe($rc === 1 && str_contains($aus, 'Format 2'), 'CLI: Fehler mit Meldung und Rückgabewert 1');

    // Jede mitgelieferte Datei: gültiges Paket, Schlüssel eindeutig, jeder
    // Stecker auflösbar, Import in die eigene Instanz legt alle Typen an.
    $mitgeliefert = glob($wurzel . '/resources/kataloge/*.json') ?: [];
    pruefe(count(KatalogImport::mitgeliefert()) === count($mitgeliefert), 'Liste der mitgelieferten Kataloge');
    foreach ($mitgeliefert as $datei) {
        $name = basename($datei);
        $daten = json_decode((string) file_get_contents($datei), true);
        pruefe(is_array($daten) && ($daten['art'] ?? '') === KatalogImport::ART && is_string($daten['quelle'] ?? null) && $daten['quelle'] !== '', $name . ': Paket mit Quelle');
        $schl = array_map(static fn ($t) => (string) ($t['schluessel'] ?? ''), (array) ($daten['typen'] ?? []));
        gleich(count(array_unique($schl)), count($schl), $name . ': Schlüssel eindeutig');
        // Gelesen gegen einen leeren Katalog – nur mit dem Grundbestand an Steckern
        [$rc, $aus] = $power(['katalog:import', $datei, '--pruefen']);
        pruefe($rc === 0 && !str_contains($aus, '  fehler '), $name . ': jeder Typ taugt, jeder Stecker auflösbar' . ($rc !== 0 || str_contains($aus, '  fehler ') ? "\n" . $aus : ''));
        $vorher = (int) $dort('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1');
        [$rc, $aus] = $power(['katalog:import', $datei]);
        $neu = (int) $dort('SELECT COUNT(*) FROM objekte WHERE ist_vorlage = 1') - $vorher;
        pruefe($rc === 0 && $neu === count($schl), $name . ': Import legt alle ' . count($schl) . ' Typen an (' . $neu . ')');
        [$rc, $aus] = $power(['katalog:import', $datei, '--pruefen']);
        pruefe($rc === 0 && substr_count($aus, '  gleich ') === count($schl), $name . ': danach jeder Typ „schon da“');
    }
} finally {
    alsBenutzer(null);
    foreach (Database::select('SELECT id FROM objekte WHERE name LIKE ? OR sync_key LIKE ?', [$k . '%', $k . '-%']) as $o) {
        AnlagenService::loeschen((int) $o['id']);
    }
    foreach (Database::select('SELECT id, schluessel FROM steckertypen WHERE schluessel LIKE ?', [$k . '-%']) as $s) {
        if (!in_array($s['schluessel'], $steckerVorher, true)) {
            Database::delete('steckertypen', (int) $s['id']);
        }
    }
    foreach ($benutzer as $b) {
        BenutzerService::loeschen($b);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'user' AND target_id = ?", [(string) $b]);
    }
    foreach ($rollen as $r) {
        BenutzerService::rolleLoeschen($r);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$k . '%', '%' . $k . '%']);
    foreach (glob(APP_ROOT . '/storage/cache/katalog-import-*.json') ?: [] as $datei) {
        // nur die eigenen (Paket mit der Kennung)
        if (str_contains((string) file_get_contents($datei), $k . '-pd63')) {
            @unlink($datei);
        }
    }
}

ende('katalog-import');
