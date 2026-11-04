<?php
declare(strict_types=1);

/**
 * PDF der Prüfberichte der Geräteprüfung (PruefberichtPdf) – eigene Instanz,
 * schreibt nichts in die Datenbank, nur in einen eigenen Temp-Ordner.
 *
 * Ein von Hand gebauter, eingefrorener Inhalt (nur Text) mit Umlauten, Ω,
 * „>100,0 MΩ“, Sichtprüfung, Prüfgerät mit Kalibrierung und Zubehör:
 * zweimal erzeugt dieselben Bytes (anderes eingefroren_am → andere),
 * /ID aus dem inhalt_hash, CreationDate = eingefroren_am, Dateiname ohne
 * Kundendaten. Mit poppler (pdfinfo, pdffonts, pdftotext, pdfimages wenn da):
 * A4 auf jeder Seite, Titel ohne Kundendaten, zwei eingebettete Teilschriften,
 * keine Bilder (Unterschrift ist ein Pfad), Texte (Kopf, Firma, Auftraggeber,
 * Prüfling, Norm, Messwerte mit Komma, Grenzwerte, Ergebnis groß, nächste
 * Prüfung, Unterschrift mit Name, Qualifikation, Ort und Datum; Kalibrierung
 * und Zubehör „laut Verwaltung, Stand …“; der Firmenname nicht doppelt, wenn
 * die Anschrift mit ihm beginnt), Fuß „Blatt x
 * von y“ mit Kurz-Prüfsumme auf jeder Seite, Tabellenkopf der Messungen auf
 * jeder Seite (80 Schritte), kein .notdef. Dazu: nicht bestanden, Korrektur
 * (Fassung 2 mit Grund), Verantwortung übernommen, ohne Messschritte.
 * Ohne poppler-utils übersprungen.
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['timezone' => 'Europe/Berlin']]);
require __DIR__ . '/hilfen/pruefung.php';

use App\Berichte\Kanon;
use App\Geraetepruefung\PruefberichtPdf;
use App\Support\Programm;

$werkzeug = [];
foreach (['pdfinfo', 'pdffonts', 'pdftotext'] as $name) {
    $pfad = Programm::finden($name, ['-v']) ?? (is_file('/usr/bin/' . $name) ? '/usr/bin/' . $name : null);
    if ($pfad === null) {
        uebersprungen('geraetepruefung-pdf', $name . ' (poppler-utils) fehlt');
    }
    $werkzeug[$name] = $pfad;
}
$pdfimages = Programm::finden('pdfimages', ['-v']) ?? (is_file('/usr/bin/pdfimages') ? '/usr/bin/pdfimages' : null);

$ordner = (string) getenv('PM_INSTANZ_ORDNER');
$tmp = ($ordner !== '' && is_dir($ordner) ? $ordner : sys_get_temp_dir()) . '/gp-pdf-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);

/** Ein Programm ohne Shell: [Ausgabe, Fehlerausgabe, Exitcode]. */
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
$ablegen = static function (string $name, string $bytes) use ($tmp): string {
    file_put_contents($tmp . '/' . $name, $bytes);
    return $tmp . '/' . $name;
};
$text = static function (string $pfad, ?int $seite = null, array $bereich = []) use ($lauf, $werkzeug): string {
    $befehl = [$werkzeug['pdftotext'], '-enc', 'UTF-8'];
    if ($seite !== null) {
        array_push($befehl, '-f', (string) $seite, '-l', (string) $seite);
    }
    foreach ($bereich as $k => $v) {
        array_push($befehl, '-' . $k, (string) $v);
    }
    array_push($befehl, $pfad, '-');
    return (string) $lauf($befehl)[0];
};
$seitenzahl = static function (string $pfad) use ($lauf, $werkzeug): int {
    return preg_match('/^Pages:\s+(\d+)/m', (string) $lauf([$werkzeug['pdfinfo'], $pfad])[0], $m) === 1 ? (int) $m[1] : 0;
};

/** Striche wie aus dem Canvas: ganze Zahlen 0..10000 auf 3:1. */
$striche = [];
for ($zug = 0; $zug < 3; $zug++) {
    $linie = [];
    for ($x = 1000; $x <= 8600; $x += 380) {
        $linie[] = [$x, 2500 + intdiv($x, 380) % 2 * 4000 + $zug * 300];
    }
    $striche[] = $linie;
}

$schritt = static fn (string $art, string $titel, bool $ok, array $werte, array $grenzen): array => [
    'art' => $art, 'titel' => $titel, 'bewertung' => $ok ? 'ok' : 'nicht_ok',
    'werte' => array_map(static fn (array $w): array => ['name' => $w[0], 'wert' => $w[1], 'bewertung' => ($w[2] ?? true) ? 'ok' : 'nicht_ok'], $werte),
    'grenzwerte' => array_map(static fn (array $g): array => ['name' => $g[0], 'wert' => $g[1]], $grenzen),
];
$inhalt = [
    'art' => 'geraetepruefung', 'nummer' => 'GP-2026-00042', 'fassung' => '1',
    'auftragnehmer' => ['name' => 'Muster Eventstrom GmbH', 'zusatz' => 'Elektrotechnik für Veranstaltungen', 'anschrift' => "Muster Eventstrom GmbH\nHauptstraße 1\n12345 Musterstadt",
        'kontakt' => "Tel. 0123 456789\ninfo@example.test", 'bank' => 'IBAN DE00 0000 0000 0000 0000 00', 'register' => 'HRB 12345 Amtsgericht Musterstadt'],
    'auftraggeber' => ['art' => 'kunde', 'name' => 'Kim Kunde Veranstaltungs-AG', 'kundennummer' => 'K-20001', 'anschrift' => "Äußere Straße 12\n54321 Süddorf"],
    'pruefling' => ['kennung' => '000101', 'bezeichnung' => 'Kabeltrommel 50 m', 'hersteller' => 'Brennenstuhl', 'typ' => 'K 50', 'modell' => 'Garant',
        'seriennummer' => 'SN-4711', 'abteilung' => 'Lager', 'gebaeude' => 'Halle 2', 'schutzklasse' => '1', 'leitungslaenge' => '50', 'querschnitt' => '2.5',
        'leiter' => '3', 'nennleistung' => '3500', 'intervall' => '12', 'inventar' => 'KT-50-07'],
    'pruefung' => ['datum' => '2026-03-05', 'uhrzeit' => '10:15', 'am_geraet' => '2026-03-05 10:15:00', 'pruefart' => 'wiederholung',
        'pruefart_text' => 'Wiederholungsprüfung', 'norm' => 'DIN EN 50699 (VDE 0702)', 'ablauf_name' => '1 Geräte SK I', 'auftrag' => 'A-77',
        'ort' => 'Lager Süd', 'bemerkung' => 'Stecker erneuert', 'pruefer_geraet' => 'Erika Mustermann', 'prueffirma' => 'Muster Eventstrom GmbH'],
    'pruefgeraet' => ['bezeichnung' => 'BENNING ST 755+', 'fabrikat' => 'BENNING', 'typ' => 'ST 755+', 'seriennummer' => '500000000001',
        'firmware' => '1.17 / 1.08.01', 'norm' => 'DIN EN 61557 (VDE 0413)', 'kalibriert_bis' => '2027-01-31',
        'kalibrierung_quelle' => 'laut Verwaltung, Stand 05.03.2026'],
    'zubehoer' => [['fabrikat' => 'BENNING', 'typ' => 'MA 4', 'seriennummer' => 'MA-0815', 'kalibriert_bis' => '']],
    'zubehoer_quelle' => 'laut Verwaltung, Stand 05.03.2026',
    'sicht' => ['ergebnis' => 'ok', 'antworten' => [['frage' => '1', 'text' => 'Gehäuse unbeschädigt', 'bewertung' => 'ok'],
        ['frage' => '2', 'text' => 'Leitung und Knickschutz in Ordnung', 'bewertung' => 'ok']]],
    'schritte' => [
        $schritt('RPE', 'Schutzleiterwiderstand', true, [['RPE Widerstand', '0.102 Ω']], [['R_PE max', '0.3 Ω']]),
        $schritt('RISO-1', 'Isolationswiderstand', true, [['RISO', '>100.0 MΩ'], ['UISO', '512 V']], [['R_ISO min', '1 MΩ'], ['U_ISO', '500 V']]),
        $schritt('Funkt.', 'Funktionsprüfung', true, [['IPE', '<0.030 mA'], ['P', '36.4 W']], [['I_Ableit max', '3.5 mA']]),
    ],
    'ergebnis' => 'bestanden', 'naechste_pruefung' => '2027-03-05', 'naechste_pruefung_text' => '05.03.2027 (Prüffrist 12 Monate)',
    'pruefer' => ['name' => 'Erika Mustermann', 'qualifikation' => 'Elektrofachkraft für Veranstaltungstechnik', 'konto' => 'Erika Mustermann',
        'user_id' => '3', 'verantwortlich_uebernommen' => 'nein'],
    'unterschrift' => ['striche_hash' => Kanon::hash(['s' => $striche]), 'name' => 'Erika Mustermann', 'ort' => 'Musterstadt', 'datum' => '2026-03-05'],
    'hinweise' => [],
];
$hash = Kanon::hash($inhalt);
$bericht = ['id' => 42, 'nummer' => 'GP-2026-00042', 'fassung' => 1, 'inhalt' => $inhalt, 'inhalt_hash' => $hash, 'eingefroren_am' => '2026-03-05T11:20:00+00:00'];

try {
    // ------------------------------------------------ Bytes
    $pdf = PruefberichtPdf::erzeugen($bericht, $striche);
    gleich(PruefberichtPdf::erzeugen($bericht, $striche), $pdf, 'zweimal erzeugt: dieselben Bytes');
    pruefe(PruefberichtPdf::erzeugen(['eingefroren_am' => '2026-03-06T11:20:00+00:00'] + $bericht, $striche) !== $pdf, 'anderes eingefroren_am → andere Bytes');
    pruefe(str_starts_with($pdf, '%PDF-') && str_contains($pdf, '/ID [<' . substr($hash, 0, 32) . '>') && str_contains($pdf, '/CreationDate (D:20260305112000Z)'),
        'PDF mit /ID aus dem inhalt_hash und CreationDate = eingefroren_am');
    pruefe(!str_contains($pdf, 'Kim Kunde') && !str_contains($pdf, 'Kabeltrommel'), 'keine Klartexte im Datenstrom');
    gleich(PruefberichtPdf::dateiname($bericht), 'GP-2026-00042.pdf', 'Dateiname nur aus der Nummer');
    gleich(PruefberichtPdf::dateiname(['nummer' => 'GP-2026-00042-K1']), 'GP-2026-00042-K1.pdf', 'Dateiname der Korrektur');
    pruefe(str_starts_with(PruefberichtPdf::ERZEUGER, 'pm-gp-pdf-'), 'eigener Erzeuger');

    // ------------------------------------------------ poppler
    $datei = $ablegen('bericht.pdf', $pdf);
    [$info, $fehler, $rc] = $lauf([$werkzeug['pdfinfo'], $datei]);
    pruefe($rc === 0 && trim($fehler) === '', 'pdfinfo ohne Fehler ' . $fehler);
    pruefe(preg_match('/^Title:\s+Prüfprotokoll GP-2026-00042$/mu', $info) === 1, 'Titel „Prüfprotokoll GP-…“');
    pruefe(!str_contains($info, 'Kim Kunde') && !str_contains($info, '000101'), 'keine Kunden- und Gerätedaten in den Metadaten');
    $seiten = $seitenzahl($datei);
    pruefe($seiten >= 1, 'Seiten: ' . $seiten);
    [$groessen] = $lauf([$werkzeug['pdfinfo'], '-f', '1', '-l', (string) $seiten, $datei]);
    gleich(preg_match_all('/^Page\s+\d+ size:\s+595\.276 x 841\.89 pts/m', $groessen), $seiten, 'jede Seite A4 hoch');
    [$schriften, , $rc] = $lauf([$werkzeug['pdffonts'], $datei]);
    $zeilenSchrift = array_values(array_filter(array_slice(explode("\n", trim($schriften)), 2)));
    pruefe($rc === 0 && count($zeilenSchrift) === 2, 'pdffonts: zwei Schriften');
    foreach ($zeilenSchrift as $z) {
        pruefe(preg_match('/^[A-Z]{6}\+LiberationSans\S*\s+CID TrueType\s+Identity-H\s+yes\s+yes\s+yes\s/', $z) === 1, 'eingebettet, Teilmenge, Unicode: ' . $z);
    }
    if ($pdfimages !== null) {
        [$bilder] = $lauf([$pdfimages, '-list', $datei]);
        gleich(count(array_filter(explode("\n", trim($bilder)))), 2, 'pdfimages: keine Bilder (Unterschrift ist ein Pfad)');
    }
    $alles = $text($datei);
    foreach (['Prüfprotokoll', PruefberichtPdf::UNTERTITEL, 'Muster Eventstrom GmbH', 'Prüfprotokoll Nr. GP-2026-00042 · Fassung 1', 'Kim Kunde Veranstaltungs-AG',
        'Äußere Straße 12, 54321 Süddorf', 'Kundennummer K-20001', '000101', 'Kabeltrommel 50 m', 'Brennenstuhl / K 50 / Garant', 'SN-4711', '50 m · 2,5 mm² · 3 Leiter',
        'Inventar KT-50-07', '05.03.2026, 10:15 Uhr', 'Wiederholungsprüfung', 'DIN EN 50699 (VDE 0702)', '1 Geräte SK I', 'Auftrag A-77 · Lager Süd',
        'BENNING ST 755+, Nr. 500000000001, Firmware 1.17 / 1.08.01', 'kalibriert bis 31.01.2027', 'BENNING MA 4, Nr. MA-0815', 'Gehäuse unbeschädigt',
        'Schutzleiterwiderstand', 'RPE Widerstand: 0,102 Ω', 'R_PE max 0,3 Ω', 'RISO: >100,0 MΩ', 'R_ISO min 1 MΩ', 'IPE: <0,030 mA', 'BESTANDEN',
        'Nächste Prüfung', '05.03.2027 (Prüffrist 12 Monate)', 'Erika Mustermann', 'Elektrofachkraft für Veranstaltungstechnik',
        'Ort, Datum: Musterstadt, 05.03.2026', 'Konto Erika Mustermann (#3)', 'Prüfsumme des eingefrorenen Inhalts (SHA-256): ' . $hash,
        'Blatt ' . $seiten . ' von ' . $seiten] as $soll) {
        pruefe(str_contains($alles, $soll), 'Text: ' . $soll);
    }
    pruefe(!str_contains($alles, 'NICHT BESTANDEN') && !str_contains($alles, 'verantwortlich:'), 'bestanden, nicht übernommen');
    gleich(substr_count(str_replace("\n", ' ', $alles), 'laut Verwaltung, Stand 05.03.2026'), 2, 'Kalibrierung und Zubehör: „laut Verwaltung, Stand …“');
    $fuss1 = $text($datei, 1, ['x' => 0, 'y' => 768, 'W' => 596, 'H' => 74]);
    pruefe(!str_contains($alles, 'Muster Eventstrom GmbH, Hauptstraße') && str_contains($alles, 'Hauptstraße 1, 12345 Musterstadt')
        && substr_count($fuss1, 'Muster Eventstrom GmbH') === 1 && str_contains($fuss1, 'Hauptstraße 1'),
        'Firmenname nicht doppelt, wenn die Anschrift mit ihm beginnt (Auftragnehmer und Fuß)');

    // ------------------------------------------------ viele Schritte: Kopf und Fuß auf jeder Seite
    $viele = $inhalt;
    $viele['schritte'] = [];
    for ($n = 1; $n <= 80; $n++) {
        $viele['schritte'][] = $schritt('RPE', 'Schutzleiterwiderstand ' . $n, $n !== 40, [['RPE Widerstand', '0.' . sprintf('%03d', $n) . ' Ω', $n !== 40]], [['R_PE max', '0.3 Ω']]);
    }
    $vieleHash = Kanon::hash($viele);
    $datei2 = $ablegen('viele.pdf', PruefberichtPdf::erzeugen(['inhalt' => $viele, 'inhalt_hash' => $vieleHash] + $bericht, $striche));
    $seiten2 = $seitenzahl($datei2);
    pruefe($seiten2 >= 3, '80 Schritte: mehrere Seiten (' . $seiten2 . ')');
    $erste = 0;
    for ($p = 1; $p <= $seiten2; $p++) {
        $seite = $text($datei2, $p);
        $fuss = $text($datei2, $p, ['x' => 0, 'y' => 768, 'W' => 596, 'H' => 74]);
        pruefe(str_contains($fuss, 'Blatt ' . $p . ' von ' . $seiten2) && str_contains($fuss, 'Prüfsumme ' . implode(' ', str_split(substr($vieleHash, 0, 16), 4)))
            && str_contains($fuss, 'GP-2026-00042'), 'Seite ' . $p . ': Fuß mit Nummer, Prüfsumme und Blatt');
        pruefe(str_contains($seite, 'Prüfprotokoll Nr. GP-2026-00042'), 'Seite ' . $p . ': Kopf mit Nummer');
        if ($erste === 0 && str_contains($seite, 'Messwert')) {
            $erste = $p;
        }
        if ($erste > 0 && $p >= $erste && str_contains($seite, 'Schutzleiterwiderstand')) {
            pruefe(str_contains($seite, 'Messwert') && str_contains($seite, 'Grenzwert') && str_contains($seite, 'Bewertung'), 'Seite ' . $p . ': Tabellenkopf der Messungen');
        }
    }
    $alle = $text($datei2);
    $gefunden = preg_match_all('/Schutzleiterwiderstand (\d+)/', $alle, $m) > 0 ? array_unique(array_map('intval', $m[1])) : [];
    gleich(count(array_intersect(range(1, 80), $gefunden)), 80, 'alle 80 Schritte im PDF');
    pruefe(str_contains($alle, 'RPE Widerstand: 0,040 Ω (nicht i. O.)') && str_contains($alle, 'nicht i. O.'), 'ungültiger Wert und Schritt erkennbar');

    // ------------------------------------------------ nicht bestanden, übernommen, ohne Schritte, Korrektur
    $nicht = $inhalt;
    $nicht['ergebnis'] = 'nicht_bestanden';
    $nicht['naechste_pruefung'] = '';
    $nicht['naechste_pruefung_text'] = 'vor Wiederinbetriebnahme (nicht bestanden)';
    $nicht['schritte'] = [];
    $nicht['sicht'] = ['ergebnis' => 'maengel', 'antworten' => [['frage' => '2', 'text' => 'Leitung beschädigt', 'bewertung' => 'nicht_ok']]];
    $nicht['pruefer']['verantwortlich_uebernommen'] = 'ja';
    $nicht['pruefung']['pruefer_geraet'] = 'Admin';
    $nicht['hinweise'] = ['Das Prüfgerät ist unter „Prüfgeräte“ nicht eingetragen – Kalibrierung nicht bekannt.'];
    $t = $text($ablegen('nicht.pdf', PruefberichtPdf::erzeugen(['inhalt' => $nicht, 'inhalt_hash' => Kanon::hash($nicht)] + $bericht, $striche)));
    foreach (['NICHT BESTANDEN', 'vor Wiederinbetriebnahme (nicht bestanden)', 'Leitung beschädigt', 'nicht i. O.', 'Keine Messschritte (nur Sichtprüfung).',
        'Prüfer laut Prüfgerät: Admin – verantwortlich: Erika Mustermann', 'Hinweis: Das Prüfgerät ist unter „Prüfgeräte“ nicht eingetragen',
        'erst nach Instandsetzung'] as $soll) {
        pruefe(str_contains($t, $soll), 'nicht bestanden: ' . $soll);
    }
    $korr = $inhalt;
    $korr['nummer'] = 'GP-2026-00042-K1';
    $korr['fassung'] = '2';
    $korr['vorgaenger'] = ['nummer' => 'GP-2026-00042', 'fassung' => '1', 'inhalt_hash' => $hash, 'grund' => 'Seriennummer falsch übernommen'];
    $t = $text($ablegen('korrektur.pdf', PruefberichtPdf::erzeugen(['nummer' => 'GP-2026-00042-K1', 'fassung' => 2, 'inhalt' => $korr,
        'inhalt_hash' => Kanon::hash($korr)] + $bericht, $striche)));
    foreach (['Korrektur (Fassung 2)', 'ersetzt Fassung 1 (GP-2026-00042)', 'Prüfsumme ' . implode(' ', str_split(substr($hash, 0, 16), 4)),
        'Grund: Seriennummer falsch übernommen', 'Prüfprotokoll Nr. GP-2026-00042-K1 · Fassung 2'] as $soll) {
        pruefe(str_contains($t, $soll), 'Korrektur: ' . $soll);
    }

    // ------------------------------------------------ keine .notdef
    $null = 0;
    if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $stroeme) > 0) {
        foreach ($stroeme[1] as $roh) {
            $inhaltStrom = @gzuncompress($roh);
            if (!is_string($inhaltStrom) || !str_contains($inhaltStrom, ' Tj')) {
                continue;
            }
            preg_match_all('/<([0-9A-F]+)> Tj/', $inhaltStrom, $hex);
            foreach ($hex[1] as $h) {
                foreach (str_split($h, 4) as $g) {
                    $null += $g === '0000' ? 1 : 0;
                }
            }
        }
    }
    gleich($null, 0, 'kein Zeichen als .notdef');
} finally {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}

ende('geraetepruefung-pdf');
