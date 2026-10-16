<?php
declare(strict_types=1);

/**
 * PDF der Übergabeberichte: eigener Schreiber (App\Pdf), Schriften,
 * Layout (BerichtPdf), geprüft mit poppler (pdfinfo, pdffonts, pdftotext,
 * pdfimages) und – falls vorhanden – qpdf.
 *
 * Ein Muster-Bericht mit 80 Stromkreisen, Umlauten, Ω, IΔn, ≤ und zwei
 * Unterschriften; dazu Vorschau, ohne Kunde, Korrektur und 300 Kreise.
 * Schreibt nichts in die Datenbank, nur in einen eigenen Temp-Ordner.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Berichte\BerichtPdf;
use App\Berichte\BerichtSchema;
use App\Berichte\Einstellungen;
use App\Berichte\Grenzwerte;
use App\Berichte\Katalog;
use App\Pdf\Schreiber;
use App\Pdf\TrueType;
use App\Support\Programm;
use App\Support\Zeit;

$werkzeug = [];
foreach (['pdfinfo', 'pdffonts', 'pdftotext', 'pdfimages'] as $name) {
    $pfad = Programm::finden($name, ['-v']) ?? (is_file('/usr/bin/' . $name) ? '/usr/bin/' . $name : null);
    if ($pfad === null) {
        uebersprungen('bericht-pdf', $name . ' (poppler-utils) fehlt');
    }
    $werkzeug[$name] = $pfad;
}
$qpdf = Programm::finden('qpdf') ?? (is_file('/usr/bin/qpdf') ? '/usr/bin/qpdf' : null);

$kennung = 'pm-pdf-' . bin2hex(random_bytes(3));
$tmp = sys_get_temp_dir() . '/' . $kennung;
mkdir($tmp, 0700);
$dateien = [];

/** Ein Programm ohne Shell starten: [Ausgabe, Fehlerausgabe, Exitcode]. */
$lauf = static function (array $befehl): array {
    $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
    if (!is_resource($p)) {
        return ['', 'nicht gestartet', 127];
    }
    $aus = (string) stream_get_contents($rohre[1]);
    $fehler = (string) stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    return [$aus, $fehler, proc_close($p)];
};
$ablegen = static function (string $name, string $bytes) use ($tmp, &$dateien): string {
    $pfad = $tmp . '/' . $name;
    file_put_contents($pfad, $bytes);
    $dateien[] = $pfad;
    return $pfad;
};
$text = static function (string $pfad, ?int $seite = null, array $bereich = []) use ($lauf, $werkzeug): array {
    $befehl = [$werkzeug['pdftotext'], '-enc', 'UTF-8'];
    if ($seite !== null) {
        array_push($befehl, '-f', (string) $seite, '-l', (string) $seite);
    }
    foreach ($bereich as $k => $v) {
        array_push($befehl, '-' . $k, (string) $v);
    }
    array_push($befehl, $pfad, '-');
    [$aus, $fehler, $rc] = $lauf($befehl);
    return [$aus, trim($fehler), $rc];
};
$seitenzahl = static function (string $pfad) use ($lauf, $werkzeug): int {
    [$aus] = $lauf([$werkzeug['pdfinfo'], $pfad]);
    return preg_match('/^Pages:\s+(\d+)/m', $aus, $m) === 1 ? (int) $m[1] : 0;
};

/** Striche wie aus dem Canvas: ganze Zahlen 0..10000 auf 3:1. */
$striche = static function (int $versatz): array {
    $s = [];
    for ($zug = 0; $zug < 3; $zug++) {
        $linie = [];
        for ($x = 800; $x <= 9200; $x += 300) {
            $linie[] = [$x, (int) (5000 + 2500 * sin(($x + $versatz) / 700) - $zug * 900)];
        }
        $s[] = $linie;
    }
    return $s;
};

/** Ein eingefrorener Muster-Bericht wie nach inhaltBauen(). */
$muster = static function (int $anzahl, array $aend = []) use ($striche): array {
    $i = BerichtSchema::leer();
    $i['kopf']['veranstaltung'] = 'Sommerfest Müller & Söhne – Bühne Süd';
    $i['kopf']['zeitraum'] = '14.–16.08.2026';
    $i['kopf']['auftrag_nr'] = 'AU-1042';
    $i['kopf']['pruefdatum'] = '2026-08-14';
    $i['kopf']['ort'] = 'Musterstadt';
    $i['kopf']['kunde'] = ['firma' => 'Muster <script>(a)\\b GmbH', 'anschrift' => "Äußere Straße 12\n12345 Musterstadt", 'kundennummer' => 'K-77',
        'ansprechpartner' => 'Erika Musterfrau', 'telefon' => '0123 456789', 'email' => 'erika@example.org', 'quelle' => 'freitext'];
    $i['kopf']['anlage'] = ['bezeichnung' => 'Generator 100 kVA und Verteiler „Bühne“', 'details' => 'Inventar PD-12', 'aufstellort' => 'Nordwiese, hinter der Bühne'];
    $i['kopf']['pruefer'] = ['user_id' => '3', 'name' => 'Tom Technik', 'qualifikation' => 'Elektrofachkraft für Veranstaltungstechnik'];
    $i['kopf']['qualifikation'] = 'Elektrofachkraft für Veranstaltungstechnik';
    $i['netz'] = ['einspeisung' => 'generator', 'spannung' => '400', 'frequenz' => '50', 'form' => 'TN-S', 'erder' => 'Staberder', 'u_ln' => '231', 'drehfeld' => 'rechts'];
    $i['grundlage'] = ['normen' => ['0100-600', '0100-711', 'dguv-v3'], 'sonstige' => '', 'grund' => 'aufbau'];
    foreach (['besichtigung' => Katalog::besichtigung(), 'erprobung' => Katalog::erprobung()] as $teil => $katalog) {
        foreach ($katalog as $s => $p) {
            if ($p['kurz']) {
                $i['punkte'][$teil][$s] = 'io';
            }
        }
    }
    $i['punkte']['besichtigung']['verlegung'] = 'nio';
    $i['punkte']['besichtigung']['warnschilder'] = 'entf';
    $i['punkt_bemerkung']['verlegung'] = 'Kabel über Fluchtweg ohne Kabelbrücke 😀';
    $i['messung']['erdung'] = ['op' => '', 'w' => '12.4', 'methode' => 'Zangenmessung', 'basis' => '', 'grenze' => ''];
    $i['messgeraete'] = [['id' => '1', 'fabrikat' => 'Fluke', 'typ' => '1664 FC', 'seriennummer' => 'DEMO-0001', 'norm' => 'DIN EN 61557 (VDE 0413)', 'kalibriert_bis' => '2027-03-31']];
    $i['grenzwerte'] = Grenzwerte::parameter();
    $i['grenzwerte']['zs_faktor'] = '1.0';
    $arten = [['Schuko', '3', '2.5', 'C', '16'], ['CEE 16 A', '5', '2.5', 'C', '16'], ['CEE 32 A', '5', '6', 'C', '32']];
    for ($n = 1; $n <= $anzahl; $n++) {
        $k = BerichtSchema::kreisLeer();
        $k['z'] = sprintf('k%07d', $n);
        $a = $arten[$n % 3];
        $k['nr'] = (string) $n;
        $k['ort'] = 'Bühne X' . $n . ' · ' . $a[0];
        $k['kabel'] = 'H07RN-F ' . $a[1] . 'G' . str_replace('.', ',', $a[2]) . ', 25 m → Catering';
        $k['leitung'] = ['art' => $a[0], 'leiter' => $a[1], 'querschnitt' => $a[2]];
        $k['ueb'] = ['art' => 'LS', 'char' => $a[3], 'in' => $a[4], 'grenze_hand' => ''];
        $k['rcd'] = ['bez' => 'F' . (1 + intdiv($n, 6)), 'in' => '40', 'typ' => 'A', 'idn' => '30', 's' => '0'];
        $k['w']['zs'] = ['op' => '', 'w' => $n === 7 ? '2.0' : '0.' . (20 + $n % 7 * 11)];
        $k['w']['riso'] = ['op' => '>', 'w' => '550'];
        $k['w']['idelta'] = ['op' => '', 'w' => (string) (18 + $n % 9)];
        $k['w']['ta'] = ['op' => '', 'w' => (string) (14 + $n % 14)];
        $k['w']['ub'] = ['op' => '', 'w' => '0.8'];
        $k['status'] = 'gemessen';
        if ($n === 4) {
            $k['rcd_von'] = 'k0000003';
            $k['w']['idelta'] = $k['w']['ta'] = $k['w']['ub'] = ['op' => '', 'w' => ''];
        }
        if ($n === 9) {
            $k['status'] = 'nicht_gemessen';
            $k['grund'] = 'Abgang nicht belegt';
        }
        if ($n === 10) {
            $k['pruefer'] = 'io';
            $k['begruendung'] = 'Messfehler, nachgemessen';
        }
        $i['kreise'][] = $k;
    }
    $i['maengel'] = [['text' => 'Kabelbrücke am Fluchtweg fehlt', 'kreis' => 'k0000002', 'frist' => '2026-08-14', 'behoben' => '']];
    $i['bemerkungen'] = 'Ω Δ ≤ ≥ µ ² – „Anführung“';
    $i['ergebnis'] = ['entspricht' => 'ja', 'maengelfrei' => 'nein', 'instand_bis' => '2026-08-14', 'plakette' => 'ja', 'plakette_nr' => 'P-123',
        'naechste' => 'bei jedem Neuaufbau', 'errichter' => '1'];
    $i['auftragnehmer'] = ['name' => 'Muster Eventstrom GmbH', 'zusatz' => 'Mobile Stromversorgung',
        'anschrift' => "Muster Eventstrom GmbH\nMusterstraße 1\n12345 Musterstadt", 'kontakt' => "Tel. 0123 4567-0\ninfo@example.org",
        'bank' => "Musterbank\nIBAN DE00 0000 0000 0000 0000 00", 'register' => "Amtsgericht Musterstadt HRB 0000\nGeschäftsführung: Max Muster"];
    $text = static fn (array $p): string => $p['text'];
    $i['labels'] = ['besichtigung' => array_map($text, Katalog::besichtigung()), 'erprobung' => array_map($text, Katalog::erprobung()),
        'normen' => Katalog::normen(), 'gruende' => Katalog::gruende(), 'einspeisungen' => Katalog::einspeisungen(),
        'bestaetigungen' => array_map($text, Katalog::bestaetigungen())];
    $i['texte'] = ['kunde' => Einstellungen::VORGABEN['berichte.text_kunde'],
        'einweisung' => str_replace('{telefon}', '0170 1234567', Einstellungen::VORGABEN['berichte.text_einweisung']),
        'errichter' => Einstellungen::VORGABEN['berichte.text_errichter'], 'datenschutz' => '', 'bereitschaft' => '0170 1234567'];
    $i['nummer'] = 'UB-2026-0042';
    $i['fassung'] = '1';
    $i = array_replace_recursive($i, $aend);
    $i['bewertung'] = Grenzwerte::alle($i);
    $bericht = ['id' => 42, 'nummer' => 'UB-2026-0042', 'fassung' => 1, 'status' => 'abgeschlossen', 'inhalt' => $i,
        'inhalt_hash' => hash('sha256', 'muster'), 'abgeschlossen_am' => '2026-08-14T10:20:00+00:00', 'eingefroren_am' => '2026-08-14T10:02:00+00:00',
        'korrektur_grund' => ''];
    $unterschriften = [
        'pruefer' => ['rolle' => 'pruefer', 'art' => 'gezeichnet', 'name' => 'Tom Technik', 'funktion' => 'Elektrofachkraft für Veranstaltungstechnik',
            'ort' => 'Musterstadt', 'striche' => $striche(0), 'angaben' => [], 'unterschrieben_am' => '2026-08-14T10:02:00+00:00',
            'siegel' => hash('sha256', 'siegel-p'), 'schluessel_id' => 'ab12cd34', 'username' => 'technik'],
        'kunde' => ['rolle' => 'kunde', 'art' => 'gezeichnet', 'name' => 'Kim Kunde', 'funktion' => 'Produktionsleitung', 'ort' => 'Musterstadt',
            'striche' => $striche(900), 'angaben' => ['bestaetigungen' => ['erhalten', 'eingewiesen'], 'vorbehalte' => 'Kabelbrücke folgt.'],
            'unterschrieben_am' => '2026-08-14T10:20:00+00:00', 'siegel' => hash('sha256', 'siegel-k'), 'schluessel_id' => 'ab12cd34', 'username' => 'technik'],
    ];
    return [$bericht, $unterschriften];
};

try {
    // ================================================== Schrift und Teilmenge
    $schrift = TrueType::laden(APP_ROOT . '/resources/fonts/LiberationSans-Regular.ttf');
    pruefe(is_file(APP_ROOT . '/resources/fonts/LiberationSans-Bold.ttf') && str_contains((string) file_get_contents(APP_ROOT . '/resources/fonts/LICENSE'), 'SIL OPEN FONT LICENSE Version 1.1'),
        'Schriften und Lizenz (SIL OFL 1.1) im Repository');
    foreach (['Ω', 'Δ', '≤', '≥', '²', 'µ', 'ä', 'ß', '€', '„', '–', '¹', '⁹'] as $z) {
        pruefe($schrift->glyphe(mb_ord($z)) > 0, 'Glyphe vorhanden: ' . $z);
    }
    gleich($schrift->glyphe(0x1F600), 0, 'Emoji hat keine Glyphe (wird „?“)');
    $glyphen = [$schrift->glyphe(ord('A')), $schrift->glyphe(mb_ord('Ä')), $schrift->glyphe(mb_ord('Ω'))];
    $teil = $schrift->teilmenge($glyphen);
    $summe = 0;
    foreach (unpack('N*', $teil . str_repeat("\0", (4 - strlen($teil) % 4) % 4)) ?: [] as $wort) {
        $summe = ($summe + $wort) & 0xFFFFFFFF;
    }
    gleich($summe, 0xB1B0AFBA, 'Teilmenge: Prüfsumme der Datei stimmt (checkSumAdjustment)');
    pruefe(strlen($teil) < 60000, 'Teilmenge klein (' . strlen($teil) . ' Byte statt ' . filesize(APP_ROOT . '/resources/fonts/LiberationSans-Regular.ttf') . ')');
    $teilDatei = $ablegen('teil.ttf', $teil);
    $wieder = new TrueType($teilDatei);
    pruefe($wieder->glyphe(ord('A')) === $glyphen[0] && $wieder->breite($glyphen[2]) === $schrift->breite($glyphen[2]), 'Teilmenge lesbar: Glyphen-IDs und Breiten unverändert');

    $s = new Schreiber();
    gleich($s->breite('', 9), 0.0, 'Breite eines leeren Textes');
    nahe($s->breite('MMMM', 10), 4 * 833 / 1000 * 10 / (72 / 25.4), 0.01, 'Breite aus hmtx (M = 833/1000 em)');
    $zeilen = $s->umbruch('Ein sehr langes Wort: Donaudampfschifffahrtsgesellschaftskapitänsmützenabzeichen und weiter', 30, 9);
    pruefe(count($zeilen) >= 3 && max(array_map(static fn (string $z): float => $s->breite($z, 9), $zeilen)) <= 30.0, 'Umbruch: keine Zeile breiter als die Spalte, langes Wort geteilt');
    gleich($s->umbruch("a\n\nb", 50, 9), ['a', '', 'b'], 'Umbruch: Absätze bleiben');
    pruefe((static function (): bool {
        try {
            (new Schreiber())->ausgabe('2026-01-01T00:00:00Z', 'nicht-hex');
            return false;
        } catch (\InvalidArgumentException) {
            return true;
        }
    })(), 'Dokument-ID nur aus 32 Hexzeichen');

    // ================================================== Muster: 80 Kreise, zwei Unterschriften
    [$bericht, $unterschriften] = $muster(80);
    $t0 = hrtime(true);
    $pdf = BerichtPdf::erzeugen($bericht, $unterschriften, false);
    $dauer = (hrtime(true) - $t0) / 1e6;
    $datei = $ablegen('muster.pdf', $pdf);
    pruefe(str_starts_with($pdf, '%PDF-1.4') && str_ends_with($pdf, "%%EOF\n"), 'Kopf %PDF- und Ende %%EOF');
    pruefe(strlen($pdf) < 200000, 'Größe ' . strlen($pdf) . ' Byte');
    pruefe($dauer < 3000, sprintf('erzeugt in %.0f ms', $dauer));
    gleich(BerichtPdf::erzeugen($bericht, $unterschriften, false), $pdf, 'zweimal erzeugt: dieselben Bytes');
    pruefe(BerichtPdf::erzeugen(['abgeschlossen_am' => '2026-08-15T10:20:00+00:00'] + $bericht, $unterschriften, false) !== $pdf, 'anderes Abschlussdatum: andere Bytes');
    pruefe(!str_contains($pdf, '<script>') && !str_contains($pdf, 'Kim Kunde') && !str_contains($pdf, 'Muster <'), 'keine Klartexte im Datenstrom (Glyphen in Hex, komprimiert)');
    pruefe(str_contains($pdf, '/ID [<' . substr($bericht['inhalt_hash'], 0, 32) . '>') && str_contains($pdf, '/CreationDate (D:20260814102000Z)'),
        'ID aus dem inhalt_hash, CreationDate = Abschluss');

    [$info, $fehler, $rc] = $lauf([$werkzeug['pdfinfo'], $datei]);
    pruefe($rc === 0 && $fehler === '', 'pdfinfo ohne Fehler ' . $fehler);
    $seiten = $seitenzahl($datei);
    pruefe($seiten >= 3, 'mindestens 3 Seiten (' . $seiten . ')');
    pruefe(preg_match('/^Title:\s+Übergabebericht UB-2026-0042$/mu', $info) === 1, 'Titel „Übergabebericht UB-…“');
    pruefe(!str_contains($info, 'Muster <script>') && !str_contains($info, 'Kim Kunde'), 'keine Kundendaten in den Metadaten');
    [$groessen] = $lauf([$werkzeug['pdfinfo'], '-f', '1', '-l', (string) $seiten, $datei]);
    gleich(preg_match_all('/^Page\s+\d+ size:\s+595\.276 x 841\.89 pts/m', $groessen), $seiten, 'jede Seite A4 hoch (595,28 × 841,89 pt)');

    [$schriften, $fehler, $rc] = $lauf([$werkzeug['pdffonts'], $datei]);
    $zeilenSchrift = array_values(array_filter(array_slice(explode("\n", trim($schriften)), 2)));
    pruefe($rc === 0 && count($zeilenSchrift) === 2, 'pdffonts: zwei Schriften (normal, fett)');
    foreach ($zeilenSchrift as $z) {
        pruefe(preg_match('/^[A-Z]{6}\+LiberationSans\S*\s+CID TrueType\s+Identity-H\s+yes\s+yes\s+yes\s/', $z) === 1, 'eingebettet, Teilmenge, Unicode: ' . $z);
    }

    [$bilder] = $lauf([$werkzeug['pdfimages'], '-list', $datei]);
    gleich(count(array_filter(explode("\n", trim($bilder)))), 2, 'pdfimages: keine Bilder (Unterschrift ist ein Pfad)');

    [$alles, $fehler, $rc] = $text($datei);
    pruefe($rc === 0 && $fehler === '', 'pdftotext ohne Fehler ' . $fehler);
    foreach (['Übergabebericht', 'Elektrische Anlage', 'Messprotokoll', 'Ω', 'IΔn', '≤ 1,44', 'UB-2026-0042', 'Muster <script>(a)\\b GmbH', 'Muster Eventstrom GmbH',
        'Sommerfest Müller & Söhne – Bühne Süd', 'Äußere Straße 12', 'Elektrofachkraft für Veranstaltungstechnik', '12,4 Ω (Grenzwert ≤ 50 Ω',
        'Messgeräte nach DIN EN 61557 (VDE 0413)', 'Fluke 1664 FC, Nr. DEMO-0001, kalibriert bis 31.03.2027', 'RCD-Werte gemessen an Stromkreis 3',
        'Abschaltung durch RCD', 'vom Prüfer abweichend bewertet: Messfehler, nachgemessen', 'nicht gemessen: Abgang nicht belegt',
        'Angewandte Grenzwerte und Prüfnachweis', 'Prüfsumme des eingefrorenen Inhalts (SHA-256): ' . $bericht['inhalt_hash'],
        'Ohne Rechenwert', 'Kabelbrücke am Fluchtweg fehlt', 'Errichterbestätigung', 'Übergabe an den Kunden', 'Kim Kunde', 'Vorbehalte des Kunden: Kabelbrücke folgt.',
        'Ort, Datum: Musterstadt, ' . Zeit::anzeige('2026-08-14T10:20:00+00:00'), 'Die elektrische Anlage entspricht den anerkannten Regeln der Elektrotechnik',
        'Blatt ' . $seiten . ' von ' . $seiten, 'Ω Δ ≤ ≥ µ ² – „Anführung“', 'Einzelwerte im Messprotokoll, Blatt'] as $soll) {
        pruefe(str_contains($alles, $soll), 'Text: ' . $soll);
    }
    pruefe(str_contains($alles, 'Kabel über Fluchtweg ohne Kabelbrücke ?') && !str_contains($alles, '😀'), 'Emoji wird „?“');
    $erstes = 0;
    for ($p = 1; $p <= $seiten; $p++) {
        [$seite] = $text($datei, $p);
        [$fuss] = $text($datei, $p, ['x' => 0, 'y' => 768, 'W' => 596, 'H' => 74]);
        pruefe(str_contains($fuss, 'Muster Eventstrom GmbH') && str_contains($fuss, 'Blatt ' . $p . ' von ' . $seiten) && str_contains($fuss, 'Prüfsumme ' . implode(' ', str_split(substr($bericht['inhalt_hash'], 0, 16), 4))),
            'Seite ' . $p . ': Fuß mit Firma, Prüfsumme und Blatt');
        if (str_contains($seite, 'Messprotokoll') && $erstes === 0 && str_contains($seite, 'Ort/Anlagenteil')) {
            $erstes = $p;
        }
        if ($erstes > 0 && $p >= $erstes) {
            pruefe(str_contains($seite, 'Ort/Anlagenteil') && str_contains($seite, 'IΔn') && str_contains($seite, 'Leitung'), 'Seite ' . $p . ': Tabellenkopf wiederholt');
        }
    }
    pruefe($erstes >= 2, 'Messprotokoll ab Blatt ' . $erstes);
    pruefe(str_contains($alles, 'Einzelwerte im Messprotokoll, Blatt ' . $erstes . '–' . $seiten), 'Verweis auf die Blätter des Messprotokolls');
    $nrGesehen = [];
    if (preg_match_all('/^\s*(\d{1,2})\s*$/m', $alles, $m) > 0) {
        $nrGesehen = array_map('intval', $m[1]);
    }
    pruefe(count(array_intersect(range(1, 80), $nrGesehen)) === 80, 'alle 80 Stromkreise im Messprotokoll');

    // Keine .notdef: kein Text mit Glyphe 0
    $null = 0;
    if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $stroeme) > 0) {
        foreach ($stroeme[1] as $roh) {
            $inhalt = @gzuncompress($roh);
            if (!is_string($inhalt) || !str_contains($inhalt, ' Tj')) {
                continue;
            }
            preg_match_all('/<([0-9A-F]+)> Tj/', $inhalt, $hex);
            foreach ($hex[1] as $h) {
                foreach (str_split($h, 4) as $g) {
                    $null += $g === '0000' ? 1 : 0;
                }
            }
        }
    }
    gleich($null, 0, 'kein Zeichen als .notdef');

    if ($qpdf !== null) {
        [, , $rc] = $lauf([$qpdf, '--check', $datei]);
        gleich($rc, 0, 'qpdf --check');
    }

    // ================================================== Vorschau, ohne Kunde, Korrektur
    $vorschau = $bericht;
    $vorschau['status'] = 'geprueft';
    $vorschau['abgeschlossen_am'] = null;
    $vDatei = $ablegen('vorschau.pdf', BerichtPdf::erzeugen($vorschau, ['pruefer' => $unterschriften['pruefer']], true));
    [$vText] = $text($vDatei);
    pruefe(str_contains($vText, 'VORSCHAU – Kundenbestätigung fehlt') && str_contains($vText, 'Unterschrift, Name, Funktion') && !str_contains($vText, 'Kim Kunde'),
        'Vorschau: „VORSCHAU“, leerer Kundenblock');
    $entwurf = $bericht;
    $entwurf['status'] = 'entwurf';
    $entwurf['nummer'] = null;
    $entwurf['inhalt_hash'] = null;
    $entwurf['updated_at'] = '2026-08-14T09:00:00+00:00';
    unset($entwurf['inhalt']['kopf']['pruefer']);
    $ePdf = BerichtPdf::erzeugen($entwurf, [], true);
    $eDatei = $ablegen('entwurf.pdf', $ePdf);
    [$eText] = $text($eDatei);
    pruefe(str_contains($eText, 'VORSCHAU – nicht abgeschlossen') && str_contains($eText, 'Entwurf #42') && str_contains($eText, 'noch nicht eingefroren')
        && !str_contains($eText, 'Prüfsumme des eingefrorenen'), 'Vorschau eines Entwurfs: Wasserzeichen, „Entwurf #42“, keine Prüfsumme');
    gleich(BerichtPdf::erzeugen($entwurf, [], true), $ePdf, 'Vorschau reproduzierbar');
    gleich([BerichtPdf::dateiname($bericht), BerichtPdf::dateiname($entwurf), BerichtPdf::dateiname(['nummer' => 'UB-2026-0042-K1'])],
        ['UB-2026-0042.pdf', 'Entwurf-42.pdf', 'UB-2026-0042-K1.pdf'], 'Dateinamen ohne Kundennamen');

    $ohne = $unterschriften;
    $ohne['kunde'] = ['art' => 'verweigert', 'name' => '', 'funktion' => '', 'ort' => '', 'striche' => [], 'angaben' => ['grund' => 'Kunde lehnt ab'],
        'unterschrieben_am' => '2026-08-14T10:20:00+00:00', 'siegel' => hash('sha256', 'siegel-o'), 'schluessel_id' => 'ab12cd34', 'username' => 'technik'];
    [$oText] = $text($ablegen('ohne.pdf', BerichtPdf::erzeugen($bericht, $ohne, false)));
    $oText = (string) preg_replace('/\s+/u', ' ', $oText);
    pruefe(str_contains($oText, 'Ohne Unterschrift des Kunden: Unterschrift verweigert – Kunde lehnt ab') && str_contains($oText, 'Siegel Kunde (ohne Unterschrift)'),
        'ohne Kunde: Grund statt Unterschrift');

    [$korr, $korrU] = $muster(5, ['vorgaenger' => ['nummer' => 'UB-2026-0042', 'fassung' => '1', 'inhalt_hash' => hash('sha256', 'alt'),
        'abgeschlossen_am' => '2026-08-14T10:20:00+00:00', 'grund' => 'Querschnitt nachgetragen'], 'nummer' => 'UB-2026-0042-K1', 'fassung' => '2']);
    $korr['nummer'] = 'UB-2026-0042-K1';
    $korr['fassung'] = 2;
    [$kText] = $text($ablegen('korrektur.pdf', BerichtPdf::erzeugen($korr, $korrU, false)));
    $kText = (string) preg_replace('/\s+/u', ' ', $kText);
    pruefe(str_contains($kText, 'Korrektur (Fassung 2)') && str_contains($kText, 'ersetzt Fassung 1 vom 14.08.2026 (UB-2026-0042), Prüfsumme ' . implode(' ', str_split(substr(hash('sha256', 'alt'), 0, 16), 4)))
        && str_contains($kText, 'Grund: Querschnitt nachgetragen') && str_contains($kText, 'UB-2026-0042-K1'), 'Korrektur: Titelzusatz und „ersetzt Fassung 1 vom …“');

    // ================================================== 300 Kreise, kein Kreis
    [$gross, $grossU] = $muster(300);
    $vorher = memory_get_peak_usage();
    $t0 = hrtime(true);
    $grossPdf = BerichtPdf::erzeugen($gross, $grossU, false);
    $dauer = (hrtime(true) - $t0) / 1e6;
    $grossDatei = $ablegen('gross.pdf', $grossPdf);
    $grossSeiten = $seitenzahl($grossDatei);
    [$gText, $gFehler] = $text($grossDatei);
    pruefe($grossSeiten >= 10 && $gFehler === '' && str_contains($gText, 'Blatt ' . $grossSeiten . ' von ' . $grossSeiten), '300 Kreise: ' . $grossSeiten . ' Seiten, lesbar');
    pruefe($dauer < 10000 && memory_get_peak_usage() < 64 * 1048576, sprintf('300 Kreise: %.0f ms, Spitze %.1f MB (vorher %.1f MB)', $dauer, memory_get_peak_usage() / 1048576, $vorher / 1048576));
    [$leer, $leerU] = $muster(0);
    [$lText] = $text($ablegen('leer.pdf', BerichtPdf::erzeugen($leer, $leerU, false)));
    pruefe(str_contains($lText, 'Keine Stromkreise eingetragen.'), 'ohne Stromkreise: Hinweis in der Tabelle');
} finally {
    foreach ($dateien as $d) {
        if (is_file($d)) {
            unlink($d);
        }
    }
    if (is_dir($tmp)) {
        rmdir($tmp);
    }
}

ende('bericht-pdf');
