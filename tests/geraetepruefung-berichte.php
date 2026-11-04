<?php
declare(strict_types=1);

/**
 * Geräteprüfung – Prüfberichte (eigene Instanz, Zeitzone Europe/Berlin).
 *
 * A – Unterschreiben: nur mit Recht und Qualifikation (planung, ohne
 * Qualifikation → 403); Auswahl, höchstens 25, Kennung, Name = Konto (Groß/
 * Klein und Leerraum egal), Ort, Prüfart, Striche (422, nichts geschrieben);
 * Prüfer laut Gerät anders → nur mit „Verantwortung übernehmen“ (Bericht nennt
 * beide); einzeln: Nummer GP-<Jahr>-<5 Stellen>, Jahr aus dem Ortsdatum
 * (Silvesternacht), Inhalt nur Text, inhalt_hash = sha256(inhalt) =
 * Kanon::hash, Siegel = HMAC über die Kette, PDF gespeichert und neu erzeugt
 * byte-gleich, Kalibrierung und Zubehör aus „Prüfgeräte“, nächste Prüfung
 * (bestanden: + Intervall, sonst „vor Wiederinbetriebnahme“).
 *
 * B – Idempotenz und Einmaligkeit: dieselbe Kennung noch einmal → Erfolg ohne
 * neue Zeile; dieselbe Kennung mit anderen Strichen oder anderem Konto →
 * 409; dieselben Striche mit neuer Kennung → 422, auch wenn sie bei einem
 * Übergabebericht stehen; schon unterschrieben → Fehler je Prüfung.
 * Sammelunterschrift über 30 Prüfungen in zwei Stücken (25 + 5) mit
 * derselben Kennung: eine Unterschrift, 30 Berichte, laufende Nummern ohne
 * Lücke und doppelte; Neuversuch des ersten Stücks legt nichts an. Eine
 * gelöschte Nummer wird nie wieder vergeben.
 *
 * C – Korrektur: Grund Pflicht, nur von „gültig“, nicht zweimal; die Prüfung
 * ist wieder offen; beim Unterschreiben Fassung 2 (-K1) mit dem jetzigen
 * Stand des Prüflings, Vorgänger ersetzt, Verweis mit dessen Prüfsumme;
 * ersetzte nicht noch einmal korrigierbar.
 *
 * D – Prüfnachweis: bestandener Bericht eines Kundengeräts → Entwurf beim
 * Kunden (Bytes = gespeichertes PDF, Angaben aus dem Inhalt, Herkunft ohne
 * Inhalte), im Kundenbereich erst nach der Freigabe (Schlüssel von
 * nachweisZeile unverändert, Quelle „aus Geräteprüfung“); nicht bestanden →
 * keiner; eigener Bestand nur von Hand an einen Kunden, nie zweimal, ein
 * Kundengerät nie an einen anderen Kunden; eingefrorene Felder nicht
 * änderbar, Hinweis schon; Korrektur zieht einen Entwurf zurück, ein
 * freigegebener bleibt (Hinweis), der alte lässt sich nicht mehr freigeben.
 *
 * E – Integrität: Inhalt, Name, Striche, Schlüssel, PDF-Bytes, Erzeuger,
 * Status, Kunde verändert → Befund, zurückgesetzt → in Ordnung.
 * Kommandozeile: geraetepruefung:pruefen (Exit 0/1, ohne Kundendaten, --pdf,
 * eine Nummer), geraetepruefung:pdf (0640, nie überschreiben, --fassung).
 *
 * F – HTTP (eigener PHP-Server): Liste mit Filtern und Detail ohne
 * PHP-Meldung und ohne Inline-JS, fremde ID 404, Unterschreiben als JSON
 * (ohne Token 419, ohne Recht 403, Update-Sperre 503 mit wartung und
 * Retry-After – nichts geschrieben), PDF mit Kopfzeilen und den gespeicherten
 * Bytes, Korrektur und Prüfnachweis über Formulare, Kundenzugang abgewiesen.
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['timezone' => 'Europe/Berlin']]);
require __DIR__ . '/hilfen/pruefung.php';

use App\Berichte\Kanon;
use App\Geraetepruefung\Messarten;
use App\Geraetepruefung\PruefberichtPdf;
use App\Geraetepruefung\PruefberichtService;
use App\Geraetepruefung\Prueflinge;
use App\Geraetepruefung\Pruefungen;
use App\Kunden\KundenSicht;
use App\Kunden\Pruefnachweise;
use App\Services\BenutzerService;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;

if (!function_exists('curl_init')) {
    uebersprungen('geraetepruefung-berichte', 'die PHP-Erweiterung curl fehlt');
}

$kennung = 'pm-gpb-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6)) . 'A1!';
$ordner = (string) getenv('PM_INSTANZ_ORDNER');
if ($ordner === '' || !is_dir($ordner)) {
    uebersprungen('geraetepruefung-berichte', 'keine eigene Instanz');
}
$wurzel = dirname(__DIR__);
$sn = '59' . random_int(1000000000, 9999999999);
$ablaufId = 'T' . random_int(100000, 999999);
$angelegt = ['users' => [], 'kunden' => [], 'pruefgeraete' => [], 'prueflinge' => [], 'pruefungen' => []];
$fremdeUnterschrift = null;

/** Eine Normalform-Prüfung wie aus BenningDatei::lesen(). */
$normal = static function (string $amGeraet, string $kennungGeraet, array $aenderung = []) use ($sn, $ablaufId): array {
    $p = array_replace([
        'ref' => '1', 'geraet_ref' => '1', 'geprueft_am' => Messarten::zeitLesen($amGeraet), 'am_geraet' => $amGeraet,
        'pruefer' => 'Erika Mustermann', 'prueffirma' => 'Muster GmbH', 'ort' => 'Lager', 'auftrag' => 'A-1', 'bemerkung' => '',
        'ablauf_name' => '1 Geräte SK I', 'ablauf_geraet_id' => $ablaufId, 'messgeraet' => Messarten::MESSGERAET,
        'messgeraet_sn' => $sn, 'firmware' => '1.17 / 1.08.01', 'ergebnis' => 'bestanden', 'sicht' => 'ok',
        'geraet_ergebnis' => ['messung' => true, 'sicht' => true],
        'sicht_antworten' => [['frage' => '1', 'text' => 'Gehäuse unbeschädigt', 'antwort' => 'ok']],
        'schritte' => [['art' => 'RPE', 'titel' => Messarten::titel('RPE'), 'ok' => true,
            'werte' => [['name' => 'RPE Widerstand', 'wert' => '0.102 Ω', 'gueltig' => true]],
            'grenzwerte' => [['name' => 'R_PE max', 'wert' => '0.3 Ω']]]],
    ], $aenderung);
    $p['schluessel'] = Pruefungen::schluessel($p, $kennungGeraet);
    return $p;
};
$pruefung = static function (int $prueflingId, string $amGeraet, array $aenderung = []) use ($normal, &$angelegt): int {
    $kennungGeraet = (string) Prueflinge::find($prueflingId)['kennung'];
    $id = Pruefungen::anlegen($prueflingId, $normal($amGeraet, $kennungGeraet, $aenderung), null)['id'];
    $angelegt['pruefungen'][] = $id;
    return $id;
};
$nichtBestanden = ['ergebnis' => 'nicht_bestanden', 'schritte' => [['art' => 'Kabel', 'titel' => Messarten::titel('Kabel'), 'ok' => false,
    'werte' => [['name' => 'R Leitung', 'wert' => '>15.000 Ω', 'gueltig' => false]], 'grenzwerte' => [['name' => 'R_Leitung max', 'wert' => '1 Ω']]]]];
$anfrage = static fn (): string => 'gp-' . bin2hex(random_bytes(10));
$u = static fn (string $anfrage, ?string $striche = null, array $mehr = []): array => $mehr + ['striche' => $striche ?? unterschriftStriche(),
    'name' => 'Erika Mustermann', 'ort' => 'Musterstadt', 'pruefart' => 'wiederholung', 'uebernehmen' => false, 'anfrage' => $anfrage];
$bericht = static fn (int $pruefungId): ?array => ($id = Database::scalar("SELECT id FROM gp_berichte WHERE pruefung_id = ? ORDER BY fassung DESC LIMIT 1", [$pruefungId])) === false
    ? null : PruefberichtService::find((int) $id);
/** Sind alle Blätter des Inhalts Text? */
$nurText = static function (mixed $w) use (&$nurText): bool {
    if (is_array($w)) {
        foreach ($w as $x) {
            if (!$nurText($x)) {
                return false;
            }
        }
        return true;
    }
    return is_string($w);
};
$zahl = static fn (string $tabelle): int => (int) Database::scalar('SELECT COUNT(*) FROM ' . $tabelle);
$befund = static fn (int $id, string $teil): bool => array_filter(PruefberichtService::integritaet($id)['befunde'],
    static fn (string $b): bool => str_contains($b, $teil)) !== [];

/** bin/power in der Instanz: [Ausgabe, Exitcode]. */
$power = static function (array $argumente) use ($wurzel): array {
    $p = proc_open(array_merge([PHP_BINARY, $wurzel . '/bin/power'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
    $aus = (string) stream_get_contents($rohre[1]) . (string) stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    return [$aus, proc_close($p)];
};

try {
    // ================================================ Vorbereitung
    $konto = static function (string $name, string $rolle, string $voll, string $quali) use ($kennung, $kennwort, &$angelegt): int {
        $id = BenutzerService::anlegen($kennung . '-' . $name, $kennwort, $rolle, false);
        Database::update('users', $id, ['full_name' => $voll, 'pruefer_qualifikation' => $quali]);
        $angelegt['users'][] = $id;
        return $id;
    };
    $technik = $konto('technik', 'technik', 'Erika Mustermann', 'Elektrofachkraft für Veranstaltungstechnik');
    $technik2 = $konto('technik2', 'technik', 'Tom Technik', 'Elektrofachkraft');
    $ohneQuali = $konto('ohne', 'technik', 'Olaf Ohne', '');
    $planung = $konto('planung', 'planung', 'Paula Planung', 'Elektrofachkraft');
    $kundeA = Database::insert('kunden', ['name' => $kennung . ' Kunde A', 'kundennummer' => $kennung . '-A', 'strasse' => 'Äußere Straße 12', 'plz' => '54321',
        'ort' => 'Süddorf', 'created_at' => gmdate('c')]);
    $kundeB = Database::insert('kunden', ['name' => $kennung . ' Kunde B', 'created_at' => gmdate('c')]);
    $angelegt['kunden'] = [$kundeA, $kundeB];
    $st755 = Database::insert('pruefgeraete', ['fabrikat' => 'BENNING', 'typ' => 'ST 755+', 'seriennummer' => $sn, 'kalibriert_bis' => '2027-01-31', 'created_at' => gmdate('c')]);
    $ma4 = Database::insert('pruefgeraete', ['fabrikat' => 'BENNING', 'typ' => 'MA 4', 'seriennummer' => 'MA-' . $sn, 'kalibriert_bis' => '', 'created_at' => gmdate('c')]);
    $angelegt['pruefgeraete'] = [$st755, $ma4];
    Database::insert('gp_pruefablaeufe', ['geraet_id' => $ablaufId, 'name' => '1 Geräte SK I', 'zubehoer' => json_encode([$ma4]), 'created_at' => gmdate('c')]);
    $eigen = Prueflinge::anlegen(['kennung' => $kennung . '-E1', 'bezeichnung' => 'Kabeltrommel 50 m', 'seriennummer' => 'SN-E1', 'intervall_monate' => 12]);
    $sammel = Prueflinge::anlegen(['kennung' => $kennung . '-S1', 'bezeichnung' => 'Verlängerung', 'intervall_monate' => 24]);
    $kundeP = Prueflinge::anlegen(['kunde_id' => $kundeA, 'kennung' => $kennung . '-K1', 'bezeichnung' => 'Scheinwerfer', 'seriennummer' => 'SN-ALT',
        'intervall_monate' => 6]);
    $kundeP2 = Prueflinge::anlegen(['kunde_id' => $kundeA, 'kennung' => $kennung . '-K2', 'bezeichnung' => 'Nebelmaschine', 'intervall_monate' => 12]);
    $angelegt['prueflinge'] = [$eigen, $sammel, $kundeP, $kundeP2];

    $pEigen = $pruefung($eigen, '2026-03-05 10:15:00');
    $pAdmin = $pruefung($eigen, '2026-03-06 09:00:00', ['pruefer' => 'Admin']);
    $pNicht = $pruefung($kundeP2, '2026-03-07 08:00:00', $nichtBestanden);
    $pKunde = $pruefung($kundeP, '2026-03-08 08:00:00');
    $pKunde2 = $pruefung($kundeP2, '2026-03-09 08:00:00');
    $pSilvester = $pruefung($eigen, '2026-01-01 00:30:00');
    $pEigenNicht = $pruefung($eigen, '2026-03-10 08:00:00', $nichtBestanden);
    $pLoesch = $pruefung($eigen, '2026-03-11 08:00:00');
    $pDanach = $pruefung($eigen, '2026-03-12 08:00:00');
    $pHttp = $pruefung($eigen, '2026-03-13 08:00:00');
    $pHttp2 = $pruefung($eigen, '2026-03-14 08:00:00');
    $pSperre = $pruefung($eigen, '2026-03-15 08:00:00');
    $pSammel = [];
    for ($n = 0; $n < 30; $n++) {
        $pSammel[] = $pruefung($sammel, sprintf('2026-04-02 %02d:%02d:00', 8 + intdiv($n, 60), $n % 60));
    }

    // ================================================ A – Rechte und Eingaben
    alsBenutzer($planung);
    gleich(PruefberichtService::unterschreiben([$pEigen], $u($anfrage()))['status'], 403, 'planung (ohne „unterschreiben“) → 403');
    alsBenutzer($ohneQuali);
    gleich(PruefberichtService::unterschreiben([$pEigen], $u($anfrage(), null, ['name' => 'Olaf Ohne']))['status'], 403, 'ohne Qualifikation → 403');
    pruefe(!PruefberichtService::darfUnterschreiben(), '… darfUnterschreiben() false');
    alsBenutzer($technik);
    pruefe(PruefberichtService::darfUnterschreiben(), 'technik mit Qualifikation darf unterschreiben');
    $vorher = $zahl('gp_berichte') . '/' . $zahl('gp_unterschriften');
    foreach ([
        'Auswahl Unsinn'   => [['x'], $u($anfrage())],
        'Auswahl leer'     => [[], $u($anfrage())],
        '26 Prüfungen'     => [range(1, 26), $u($anfrage())],
        'Kennung zu kurz'  => [[$pEigen], $u('kurz')],
        'Name fremd'       => [[$pEigen], $u($anfrage(), null, ['name' => 'Tom Technik'])],
        'Ort fehlt'        => [[$pEigen], $u($anfrage(), null, ['ort' => ' '])],
        'Prüfart'          => [[$pEigen], $u($anfrage(), null, ['pruefart' => 'erstpruefung'])],
        'Striche Unsinn'   => [[$pEigen], $u($anfrage(), '{"s":[[[1.5,2]]]}')],
        'Striche zu klein' => [[$pEigen], $u($anfrage(), '{"s":[[[10,10],[20,20]]]}')],
    ] as $fall => [$ids, $daten]) {
        $e = PruefberichtService::unterschreiben($ids, $daten);
        pruefe(!$e['ok'] && $e['status'] === 422 && $e['unterschrieben'] === [], 'abgelehnt: ' . $fall . ' (' . $e['message'] . ')');
    }
    gleich($zahl('gp_berichte') . '/' . $zahl('gp_unterschriften'), $vorher, '… nichts geschrieben');

    // Prüfer laut Gerät anders: nur mit „Verantwortung übernehmen“
    $e = PruefberichtService::unterschreiben([$pAdmin], $u($anfrage()));
    pruefe($e['status'] === 200 && !$e['ok'] && $e['unterschrieben'] === [] && str_contains($e['fehler'][0]['message'] ?? '', '„Admin“'),
        'Prüfer laut Gerät „Admin“: ohne Übernehmen nicht unterschrieben');
    pruefe($bericht($pAdmin) === null && $zahl('gp_unterschriften') === 0, '… kein Bericht, keine Unterschrift');
    $e = PruefberichtService::unterschreiben([$pAdmin], $u($anfrage(), null, ['uebernehmen' => true]));
    $bAdmin = $bericht($pAdmin);
    pruefe($e['ok'] && $bAdmin !== null && $bAdmin['inhalt']['pruefer']['verantwortlich_uebernommen'] === 'ja'
        && $bAdmin['inhalt']['pruefung']['pruefer_geraet'] === 'Admin', 'mit Übernehmen: Bericht nennt Prüfer laut Gerät und den Verantwortlichen');

    // Einzeln: eigener Bestand, bestanden; Name in anderer Schreibweise
    $sEigen = unterschriftStriche();
    $aEigen = $anfrage();
    $e = PruefberichtService::unterschreiben([$pEigen], $u($aEigen, $sEigen, ['name' => '  erika   MUSTERMANN ']));
    $b = $bericht($pEigen);
    pruefe($e['ok'] && $e['status'] === 200 && $e['unterschrieben'] === [$pEigen] && $b !== null, 'unterschrieben (Name: Groß/Klein und Leerraum egal)');
    pruefe(preg_match('/^GP-2026-\d{5}$/', (string) $b['nummer']) === 1 && $b['jahr'] === 2026 && $b['fassung'] === 1 && $b['status'] === 'gueltig'
        && $b['ergebnis'] === 'bestanden' && $b['kunde_id'] === null && $b['pruefer_id'] === $technik, 'Nummer GP-2026-#####, Fassung 1, gültig, bestanden');
    $zeile = Database::first('SELECT * FROM gp_berichte WHERE id = ?', [$b['id']]);
    pruefe($nurText($b['inhalt']), 'Inhalt nur Text');
    pruefe(hash_equals((string) $zeile['inhalt_hash'], hash('sha256', (string) $zeile['inhalt'])) && hash_equals((string) $zeile['inhalt_hash'], Kanon::hash($b['inhalt']))
        && (string) $zeile['inhalt'] === Kanon::json($b['inhalt']), 'inhalt_hash = sha256(inhalt) = Kanon::hash, gespeichert genau Kanon::json');
    pruefe(hash_equals(hash_hmac('sha256', (string) $zeile['kette_hash'], Crypto::ableiten('pruefbericht-siegel-1')), (string) $zeile['siegel']),
        'Siegel = HMAC über die Kette (Schlüssel aus app.key)');
    $i = $b['inhalt'];
    gleich([$i['auftraggeber']['name'], $i['pruefling']['kennung'], $i['pruefling']['seriennummer'], $i['pruefung']['datum'], $i['pruefung']['uhrzeit'],
        $i['pruefung']['norm'], $i['pruefung']['pruefart'], $i['pruefgeraet']['seriennummer'], $i['pruefgeraet']['kalibriert_bis'], $i['zubehoer'][0]['typ'] ?? '',
        $i['naechste_pruefung'], $i['ergebnis'], $i['pruefer']['name'], $i['pruefer']['qualifikation'], $i['pruefer']['verantwortlich_uebernommen'],
        $i['unterschrift']['ort'], $i['unterschrift']['striche_hash'], $i['hinweise']],
        ['Eigener Bestand', $kennung . '-E1', 'SN-E1', '2026-03-05', '10:15', 'DIN EN 50699 (VDE 0702)', 'wiederholung', $sn, '2027-01-31', 'MA 4',
            '2027-03-05', 'bestanden', 'Erika Mustermann', 'Elektrofachkraft für Veranstaltungstechnik', 'nein', 'Musterstadt',
            Kanon::hash(['s' => json_decode($sEigen, true)['s']]), []],
        'eingefrorener Inhalt: Auftraggeber, Prüfling, Prüfung, Norm, Prüfgerät mit Kalibrierung, Zubehör, nächste Prüfung, Prüfer, Unterschrift');
    gleich([$i['schritte'][0]['titel'], $i['schritte'][0]['werte'][0]['wert'], $i['schritte'][0]['grenzwerte'][0]['wert'], $i['sicht']['antworten'][0]['bewertung']],
        ['Schutzleiterwiderstand', '0.102 Ω', '0.3 Ω', 'ok'], 'Messwerte und Grenzwerte unverändert aus der Prüfung (Punkt), Sichtprüfung');
    $pdf = PruefberichtService::pdfGespeichert($b['id']);
    $pdfZeile = Database::first('SELECT * FROM gp_bericht_pdf WHERE bericht_id = ?', [$b['id']]);
    pruefe($pdf !== null && str_starts_with($pdf, '%PDF-') && hash('sha256', $pdf) === $b['pdf_hash'] && $pdfZeile['erzeuger'] === PruefberichtPdf::ERZEUGER,
        'PDF gespeichert (Prüfsumme = pdf_hash, Erzeuger)');
    gleich(PruefberichtPdf::erzeugen($b, json_decode($sEigen, true)['s']), $pdf, 'neu erzeugt: dieselben Bytes');
    pruefe(str_contains((string) $pdf, '/ID [<' . substr($b['inhalt_hash'], 0, 32) . '>'), 'PDF-ID aus dem inhalt_hash');
    gleich(PruefberichtService::integritaet($b['id'], true), ['ok' => true, 'befunde' => [], 'hinweise' => []], 'Integrität in Ordnung (auch mit neu erzeugtem PDF)');
    $audit = Database::first("SELECT details FROM audit_log WHERE action = 'geraetepruefung.unterschrieben' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $b['id']]);
    pruefe($audit !== null && !str_contains((string) $audit['details'], 'Kabeltrommel') && !str_contains((string) $audit['details'], 'Erika'),
        'Audit nur mit IDs und Nummer');

    // Jahr aus dem Ortsdatum: 00:30 Uhr am Neujahrstag ist in UTC noch das alte Jahr
    pruefe(str_starts_with((string) Messarten::zeitLesen('2026-01-01 00:30:00'), '2025-12-31'), 'Silvesternacht: UTC noch 2025');
    PruefberichtService::unterschreiben([$pSilvester], $u($anfrage()));
    $bS = $bericht($pSilvester);
    pruefe($bS !== null && $bS['jahr'] === 2026 && str_starts_with($bS['nummer'], 'GP-2026-') && $bS['inhalt']['pruefung']['datum'] === '2026-01-01',
        'Jahr der Nummer aus dem Ortsdatum (2026)');

    // ================================================ B – Idempotenz, Einmaligkeit, Sammelunterschrift
    $vorher = [$zahl('gp_berichte'), $zahl('gp_unterschriften'), $zahl('gp_bericht_pdf')];
    $e = PruefberichtService::unterschreiben([$pEigen], $u($aEigen, $sEigen));
    pruefe($e['ok'] && $e['unterschrieben'] === [$pEigen], 'dieselbe Kennung noch einmal: Erfolg');
    gleich([$zahl('gp_berichte'), $zahl('gp_unterschriften'), $zahl('gp_bericht_pdf')], $vorher, '… ohne neue Zeile');
    gleich(PruefberichtService::unterschreiben([$pEigen], $u($aEigen))['status'], 409, 'dieselbe Kennung, andere Striche → 409');
    gleich(PruefberichtService::unterschreiben([$pEigen], $u($aEigen, $sEigen, ['ort' => 'Anderswo']))['status'], 409, 'dieselbe Kennung, anderer Ort → 409');
    alsBenutzer($technik2);
    gleich(PruefberichtService::unterschreiben([$pEigen], $u($aEigen, $sEigen, ['name' => 'Tom Technik']))['status'], 409, 'dieselbe Kennung, anderes Konto → 409');
    alsBenutzer($technik);
    $e = PruefberichtService::unterschreiben([$pDanach], $u($anfrage(), $sEigen));
    pruefe($e['status'] === 422 && str_contains($e['message'], 'gibt es schon') && $bericht($pDanach) === null, 'dieselben Striche, neue Kennung → 422');
    $sFremd = unterschriftStriche();
    $fremdeUnterschrift = Database::insert('bericht_unterschriften', ['bericht_id' => -random_int(100000, 999999), 'rolle' => 'pruefer', 'name' => 'x',
        'striche' => '{}', 'striche_hash' => Kanon::hash(['s' => json_decode($sFremd, true)['s']]), 'inhalt_hash' => str_repeat('0', 64),
        'kette_hash' => str_repeat('0', 64), 'siegel' => str_repeat('0', 64), 'schluessel_id' => 'x', 'unterschrieben_am' => gmdate('c'), 'anfrage' => $anfrage()]);
    $e = PruefberichtService::unterschreiben([$pDanach], $u($anfrage(), $sFremd));
    pruefe($e['status'] === 422 && $bericht($pDanach) === null, 'Striche eines Übergabeberichts → 422');
    $e = PruefberichtService::unterschreiben([$pEigen], $u($anfrage()));
    pruefe($e['status'] === 200 && !$e['ok'] && str_contains($e['fehler'][0]['message'] ?? '', 'Schon unterschrieben'), 'schon unterschrieben (andere Kennung) → Fehler je Prüfung');

    // Sammelunterschrift: 30 Prüfungen, zwei Stücke, eine Kennung
    $aSammel = $anfrage();
    $sSammel = unterschriftStriche();
    $e1 = PruefberichtService::unterschreiben(array_slice($pSammel, 0, 25), $u($aSammel, $sSammel));
    $e2 = PruefberichtService::unterschreiben(array_map('strval', array_slice($pSammel, 25)), $u($aSammel, $sSammel));
    pruefe($e1['ok'] && $e2['ok'] && count($e1['unterschrieben']) === 25 && count($e2['unterschrieben']) === 5, 'Sammelunterschrift: 25 + 5 unterschrieben');
    $sammelZeilen = Database::select('SELECT b.laufnr, b.unterschrift_id, b.nummer FROM gp_berichte b WHERE b.pruefung_id IN (' . implode(',', $pSammel) . ') ORDER BY laufnr');
    $laufnr = array_map(static fn (array $z): int => (int) $z['laufnr'], $sammelZeilen);
    pruefe(count($laufnr) === 30 && count(array_unique($laufnr)) === 30 && end($laufnr) - $laufnr[0] === 29, 'laufende Nummern ohne Lücke und doppelte');
    gleich(count(array_unique(array_column($sammelZeilen, 'unterschrift_id'))), 1, '… alle mit derselben Unterschrift');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM gp_unterschriften WHERE anfrage = ?', [$aSammel]), 1, '… eine Zeile je Durchgang');
    $vorher = $zahl('gp_berichte');
    $e = PruefberichtService::unterschreiben(array_slice($pSammel, 0, 25), $u($aSammel, $sSammel));
    pruefe($e['ok'] && count($e['unterschrieben']) === 25 && $zahl('gp_berichte') === $vorher, 'Neuversuch des ersten Stücks: Erfolg, nichts neu');
    gleich($bericht($pSammel[0])['inhalt']['naechste_pruefung'], '2028-04-02', 'nächste Prüfung mit dem Intervall des Prüflings (24 Monate)');

    // Eine gelöschte Nummer kommt nie wieder
    PruefberichtService::unterschreiben([$pLoesch], $u($anfrage()));
    $bL = $bericht($pLoesch);
    Database::execute('DELETE FROM gp_bericht_pdf WHERE bericht_id = ?', [$bL['id']]);
    Database::execute('DELETE FROM gp_berichte WHERE id = ?', [$bL['id']]);
    PruefberichtService::unterschreiben([$pDanach], $u($anfrage()));
    pruefe($bericht($pDanach)['laufnr'] === $bL['laufnr'] + 1, 'nach dem Löschen der jüngsten Nummer: die nächste, nicht dieselbe');

    // Nicht bestanden (Kundengerät): kein Prüfnachweis, „vor Wiederinbetriebnahme“
    $e = PruefberichtService::unterschreiben([$pNicht], $u($anfrage()));
    $bN = $bericht($pNicht);
    pruefe($e['ok'] && $bN['ergebnis'] === 'nicht_bestanden' && $bN['kunde_id'] === $kundeA && $bN['inhalt']['naechste_pruefung'] === ''
        && str_contains($bN['inhalt']['naechste_pruefung_text'], 'vor Wiederinbetriebnahme'), 'nicht bestanden: keine Frist, vor Wiederinbetriebnahme');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE gp_bericht_id = ?', [$bN['id']]), 0, 'nicht bestanden → kein Prüfnachweis');
    gleich(Pruefnachweise::ausGeraetepruefung($kundeA, $bN['id'])['ok'], false, '… auch nicht von Hand');

    // ================================================ D – Prüfnachweis
    $e = PruefberichtService::unterschreiben([$pKunde, $pKunde2], $u($anfrage()));
    $bK = $bericht($pKunde);
    $bK2 = $bericht($pKunde2);
    pruefe($e['ok'] && $bK['kunde_id'] === $kundeA && $bK['inhalt']['auftraggeber']['name'] === $kennung . ' Kunde A'
        && $bK['inhalt']['auftraggeber']['anschrift'] === "Äußere Straße 12\n54321 Süddorf", 'Kundengerät: Auftraggeber mit Anschrift eingefroren');
    $n = Database::first('SELECT * FROM pruefnachweise WHERE gp_bericht_id = ?', [$bK['id']]);
    pruefe($n !== null && $n['status'] === 'entwurf' && $n['quelle'] === 'geraetepruefung' && (int) $n['kunde_id'] === $kundeA, 'bestanden: Prüfnachweis als Entwurf beim Kunden');
    gleich([$n['bezeichnung'], $n['inventarnummer'], $n['seriennummer'], $n['norm'], $n['ergebnis'], $n['geprueft_am'], $n['naechste_pruefung']],
        ['Scheinwerfer', $kennung . '-K1', 'SN-ALT', 'DIN EN 50699 (VDE 0702)', 'bestanden', '2026-03-08', '2026-09-08'], '… Angaben aus dem Inhalt');
    $nachweis = Pruefnachweise::find((int) $n['id']);
    gleich((Pruefnachweise::datei($nachweis) ?? [])['bytes'] ?? '', PruefberichtService::pdfGespeichert($bK['id']), '… Datei = gespeichertes PDF des Berichts');
    $herkunft = json_decode((string) $n['herkunft'], true);
    gleich(array_keys($herkunft), ['art', 'bericht_id', 'nummer', 'fassung', 'pdf_sha256', 'user_id', 'am'], '… Herkunft nur mit IDs, Nummer und Prüfsumme');
    gleich(KundenSicht::nachweise($kundeA), [], 'im Kundenbereich: nichts vor der Freigabe');
    pruefe(Pruefnachweise::status($kundeA, (int) $n['id'], 'freigegeben')['ok'], 'freigegeben');
    $sicht = KundenSicht::nachweise($kundeA);
    gleich(array_keys($sicht[0] ?? []), ['id', 'bezeichnung', 'inventarnummer', 'seriennummer', 'norm', 'ergebnis', 'geprueft_am', 'naechste_pruefung', 'einsatz_von',
        'einsatz_bis', 'hinweis', 'site_id', 'standort', 'quelle', 'gueltigkeit'], 'im Kundenbereich nach der Freigabe – Schlüssel von nachweisZeile unverändert');
    gleich($sicht[0]['quelle'] ?? '', 'aus Geräteprüfung, Prüfbericht ' . $bK['nummer'], '… Quelle „aus Geräteprüfung“ mit Nummer');
    gleich(Pruefnachweise::ausGeraetepruefung($kundeA, $bK['id'])['message'], 'Dieser Prüfbericht ist schon als Prüfnachweis hinterlegt.', 'nie zweimal');
    gleich(Pruefnachweise::ausGeraetepruefung($kundeB, $bK2['id'])['ok'], false, 'Kundengerät nie an einen anderen Kunden');
    // Eingefrorene Felder nicht änderbar, Hinweis schon
    $roh = Database::first('SELECT * FROM pruefnachweise WHERE id = ?', [(int) $n['id']]);
    $formular = static fn (array $mehr): array => $mehr + array_intersect_key($roh, array_flip(['bezeichnung', 'inventarnummer', 'seriennummer', 'norm', 'ergebnis',
        'geprueft_am', 'naechste_pruefung', 'hinweis']));
    pruefe(!Pruefnachweise::speichern($kundeA, (int) $n['id'], $formular(['bezeichnung' => 'Anders']))['ok'], 'Bezeichnung ändern → abgelehnt');
    pruefe(!Pruefnachweise::speichern($kundeA, (int) $n['id'], $formular(['geprueft_am' => '2026-03-01']))['ok'], 'Prüfdatum ändern → abgelehnt');
    pruefe(Pruefnachweise::speichern($kundeA, (int) $n['id'], $formular(['hinweis' => 'Mit Adapter']))['ok']
        && Database::scalar('SELECT hinweis FROM pruefnachweise WHERE id = ?', [(int) $n['id']]) === 'Mit Adapter', 'Hinweis ändern → gespeichert');
    pruefe(Pruefnachweise::speichern($kundeA, (int) $n['id'], ['hinweis' => 'Nur Hinweis'])['ok'], 'ohne die eingefrorenen Felder im Formular → die gespeicherten');
    // Eigener Bestand: nur von Hand, an jeden Kunden
    gleich((int) Database::scalar('SELECT COUNT(*) FROM pruefnachweise WHERE gp_bericht_id = ?', [$b['id']]), 0, 'eigener Bestand: kein Nachweis von selbst');
    $m = Pruefnachweise::ausGeraetepruefung($kundeB, $b['id'], ['einsatz_von' => '2026-04-01', 'einsatz_bis' => '2026-04-05', 'bezeichnung' => 'überschrieben?']);
    $nE = Database::first('SELECT * FROM pruefnachweise WHERE gp_bericht_id = ?', [$b['id']]);
    pruefe($m['ok'] && $nE['status'] === 'entwurf' && (int) $nE['kunde_id'] === $kundeB && $nE['einsatz_von'] === '2026-04-01' && $nE['bezeichnung'] === 'Kabeltrommel 50 m',
        'eigener Bestand von Hand an Kunde B: Entwurf mit Mietzeit, Bezeichnung aus dem Bericht');
    gleich(Pruefnachweise::ausGeraetepruefung($kundeA, $b['id'])['ok'], false, '… und nicht noch einmal an Kunde A');
    gleich(Pruefnachweise::ausGeraetepruefung($kundeB, $bericht($pEigenNicht)['id'] ?? 0)['ok'], false, 'unbekannter/ nicht unterschriebener Bericht → nichts');

    // ================================================ C – Korrektur
    alsBenutzer($planung);
    gleich(PruefberichtService::korrektur($bK2['id'], 'Grund')['status'], 403, 'Korrektur ohne Recht → 403');
    alsBenutzer($technik);
    gleich(PruefberichtService::korrektur($bK2['id'], 'x')['status'], 422, 'Korrektur ohne Grund → 422');
    $k = PruefberichtService::korrektur($bK2['id'], 'Seriennummer falsch');
    pruefe($k['ok'] && PruefberichtService::find($bK2['id'])['status'] === 'korrektur', 'Korrektur angefordert');
    gleich(PruefberichtService::korrektur($bK2['id'], 'noch einmal')['status'], 409, 'nicht zweimal');
    pruefe(in_array($pKunde2, array_column(PruefberichtService::liste(['status' => 'offen']), 'id'), true)
        && !in_array($pKunde2, array_column(PruefberichtService::liste(['status' => 'unterschrieben']), 'id'), true), 'die Prüfung ist wieder offen');
    $nAlt = Database::first('SELECT * FROM pruefnachweise WHERE gp_bericht_id = ?', [$bK2['id']]);
    pruefe(!Pruefnachweise::status($kundeA, (int) $nAlt['id'], 'freigegeben')['ok'], 'Nachweis eines Berichts mit angeforderter Korrektur lässt sich nicht freigeben');
    Prueflinge::aendern($kundeP2, ['seriennummer' => 'SN-NEU']);
    $e = PruefberichtService::unterschreiben([$pKunde2], $u($anfrage()));
    $bK2neu = $bericht($pKunde2);
    $bK2alt = PruefberichtService::find($bK2['id']);
    pruefe($e['ok'] && $bK2neu['fassung'] === 2 && $bK2neu['nummer'] === $bK2['nummer'] . '-K1' && $bK2neu['laufnr'] === $bK2['laufnr']
        && $bK2neu['vorgaenger_id'] === $bK2['id'] && $bK2neu['grund'] === 'Seriennummer falsch', 'Fassung 2: Nummer -K1, Vorgänger, Grund');
    pruefe($bK2alt['status'] === 'ersetzt' && $bK2alt['ersetzt_durch_id'] === $bK2neu['id'], 'Vorgänger ersetzt');
    gleich([$bK2neu['inhalt']['pruefling']['seriennummer'], $bK2neu['inhalt']['vorgaenger']['inhalt_hash'], $bK2neu['inhalt']['vorgaenger']['grund']],
        ['SN-NEU', $bK2alt['inhalt_hash'], 'Seriennummer falsch'], 'jetziger Stand des Prüflings, Verweis mit der Prüfsumme des Vorgängers');
    gleich(Database::scalar('SELECT status FROM pruefnachweise WHERE id = ?', [(int) $nAlt['id']]), 'zurueckgezogen', 'Entwurf der alten Fassung zurückgezogen');
    gleich(Database::scalar('SELECT status FROM pruefnachweise WHERE gp_bericht_id = ?', [$bK2neu['id']]), 'entwurf', 'neuer Entwurf für die neue Fassung');
    pruefe(PruefberichtService::integritaet($bK2alt['id'])['ok'] && PruefberichtService::integritaet($bK2neu['id'], true)['ok'], 'beide Fassungen in Ordnung');
    gleich(PruefberichtService::korrektur($bK2alt['id'], 'Grund')['status'], 409, 'ersetzte Fassung nicht korrigierbar');
    pruefe(!Pruefnachweise::status($kundeA, (int) $nAlt['id'], 'freigegeben')['ok'], 'Nachweis der ersetzten Fassung lässt sich nicht freigeben');
    // Freigegebener Nachweis bleibt, mit Hinweis
    PruefberichtService::korrektur($bK['id'], 'Bezeichnung ergänzt');
    $e = PruefberichtService::unterschreiben([$pKunde], $u($anfrage()));
    pruefe($e['ok'] && str_contains(implode(' ', $e['hinweise']), 'freigegeben und bleibt es')
        && Database::scalar('SELECT status FROM pruefnachweise WHERE id = ?', [(int) $n['id']]) === 'freigegeben', 'freigegebener Nachweis bleibt – mit Hinweis');

    // ================================================ E – Integrität und Kommandozeile
    $id = $b['id'];
    $orig = Database::first('SELECT * FROM gp_berichte WHERE id = ?', [$id]);
    $origU = Database::first('SELECT * FROM gp_unterschriften WHERE id = ?', [(int) $orig['unterschrift_id']]);
    $origP = Database::first('SELECT * FROM gp_bericht_pdf WHERE bericht_id = ?', [$id]);
    $faelle = [
        ['UPDATE gp_berichte SET inhalt = ? WHERE id = ?', [str_replace('Kabeltrommel', 'Kabeltrommeln', (string) $orig['inhalt']), $id], 'Inhalt verändert', 'Inhalt'],
        ['UPDATE gp_unterschriften SET name = ? WHERE id = ?', ['Jemand Anderes', (int) $origU['id']], 'Kette', 'Name der Unterschrift'],
        ['UPDATE gp_unterschriften SET striche = ? WHERE id = ?', ['{"s":[[[1,1],[2,2]]]}', (int) $origU['id']], 'Striche', 'Striche'],
        ['UPDATE gp_berichte SET schluessel_id = ? WHERE id = ?', ['abcdef01', $id], 'anderem Schlüssel', 'Schlüssel'],
        ['UPDATE gp_berichte SET kunde_id = ? WHERE id = ?', [$kundeB, $id], 'Kette', 'Kunde'],
        ['UPDATE gp_berichte SET status = ? WHERE id = ?', ['ersetzt', $id], 'ersetzt', 'Status'],
        ['UPDATE gp_bericht_pdf SET daten = ? WHERE bericht_id = ?', [base64_encode(substr((string) $pdf, 0, -1) . 'x'), $id], 'PDF verändert', 'PDF-Bytes'],
        ['UPDATE gp_bericht_pdf SET erzeuger = ? WHERE bericht_id = ?', ['pm-gp-pdf-0', $id], 'Siegel ungültig', 'Erzeuger'],
    ];
    foreach ($faelle as [$sql, $werte, $teil, $text]) {
        Database::execute($sql, $werte);
        pruefe($befund($id, $teil), 'verändert → Befund: ' . $text);
        if ($text === 'PDF-Bytes') {
            gleich(PruefberichtService::pdfGespeichert($id), null, '… veränderte Bytes werden nicht ausgeliefert');
            [$aus, $rc] = $power(['geraetepruefung:pruefen']);
            pruefe($rc === 1 && str_contains($aus, 'BEFUND   ' . $b['nummer']) && !str_contains($aus, $kennung), 'Kommandozeile: Befund, Exit 1, ohne Kundendaten');
        }
        Database::update('gp_berichte', $id, ['inhalt' => $orig['inhalt'], 'schluessel_id' => $orig['schluessel_id'], 'kunde_id' => $orig['kunde_id'], 'status' => $orig['status']]);
        Database::update('gp_unterschriften', (int) $origU['id'], ['name' => $origU['name'], 'striche' => $origU['striche']]);
        Database::execute('UPDATE gp_bericht_pdf SET daten = ?, erzeuger = ? WHERE bericht_id = ?', [$origP['daten'], $origP['erzeuger'], $id]);
        pruefe(PruefberichtService::integritaet($id)['ok'], 'zurückgesetzt → in Ordnung (' . $text . ')');
    }
    [$aus, $rc] = $power(['geraetepruefung:pruefen', '--pdf']);
    pruefe($rc === 0 && str_contains($aus, 'OK       ' . $b['nummer'] . ' (Fassung 1, gültig)') && str_contains($aus, '0 mit Befund') && !str_contains($aus, $kennung),
        'Kommandozeile: alle in Ordnung (--pdf), ohne Kundendaten');
    [$aus, $rc] = $power(['geraetepruefung:pruefen', $bK2['nummer'], '--fassung=2']);
    pruefe($rc === 0 && str_contains($aus, $bK2['nummer'] . '-K1 (Fassung 2, gültig)') && str_contains($aus, '1 geprüft'), 'Kommandozeile: eine Nummer mit --fassung');
    [$aus, $rc] = $power(['geraetepruefung:pruefen', 'GP-1999-00001']);
    pruefe($rc === 1 && str_contains($aus, 'gibt es nicht'), 'Kommandozeile: unbekannte Nummer → Exit 1');
    $ziel = $ordner . '/' . $kennung . '.pdf';
    [$aus, $rc] = $power(['geraetepruefung:pdf', $b['nummer'], $ziel]);
    pruefe($rc === 0 && is_file($ziel) && file_get_contents($ziel) === $pdf && (fileperms($ziel) & 0777) === 0640 && str_contains($aus, hash('sha256', (string) $pdf)),
        'geraetepruefung:pdf: die gespeicherten Bytes, 0640, Prüfsumme in der Ausgabe');
    [$aus, $rc] = $power(['geraetepruefung:pdf', $b['nummer'], $ziel]);
    pruefe($rc === 1 && str_contains($aus, 'gibt es schon'), 'geraetepruefung:pdf überschreibt nie');
    @unlink($ziel);

    // ================================================ F – HTTP
    alsBenutzer(null);
    $port = freierPort();
    @mkdir($ordner . '/sitzungen', 0700);
    $serverFehler = $ordner . '/server-fehler.txt';
    touch($serverFehler);
    hintergrund([PHP_BINARY, '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port, '-t', $wurzel . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
    for ($w = 0; $w < 50 && !erreichbar('127.0.0.1', $port); $w++) {
        usleep(100_000);
    }
    if (!erreichbar('127.0.0.1', $port)) {
        throw new RuntimeException('eingebauter PHP-Server startet nicht');
    }
    $basis = 'http://127.0.0.1:' . $port;
    $web = static function (string $dose, string $methode, string $pfad, array $felder = [], array $kopf = []) use ($basis): array {
        $c = curl_init($basis . $pfad);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $dose, CURLOPT_COOKIEFILE => $dose,
            CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => array_merge(['Expect:'], $kopf)]);
        if ($methode === 'POST') {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
        }
        $antwort = (string) curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $laenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
        curl_close($c);
        $kopfText = substr($antwort, 0, $laenge);
        preg_match('/^Location:\s*(\S+)/mi', $kopfText, $m);
        return ['status' => $status, 'kopf' => $kopfText, 'text' => substr($antwort, $laenge), 'ziel' => $m[1] ?? null];
    };
    $kopfzeile = static fn (array $r, string $name): string => preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $r['kopf'], $m) ? trim($m[1]) : '';
    $anmelden = static function (string $name) use ($web, $ordner, $kennwort): string {
        $dose = $ordner . '/dose-' . $name . '-' . bin2hex(random_bytes(3));
        touch($dose);
        $seite = $web($dose, 'GET', '/login');
        preg_match('/name="_token" value="([^"]+)"/', $seite['text'], $m);
        $web($dose, 'POST', '/login', ['username' => $name, 'password' => $kennwort, '_token' => $m[1] ?? '']);
        return $dose;
    };
    $token = static fn (string $html): string => preg_match('/window\.PM_TOKEN = "([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    $phpMeldungen = static function () use ($serverFehler): array {
        preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', (string) file_get_contents($serverFehler), $m);
        return $m[0];
    };
    $json = ['Accept: application/json'];

    $doseT = $anmelden($kennung . '-technik');
    $liste = $web($doseT, 'GET', '/geraetepruefung/pruefungen');
    pruefe($liste['status'] === 200 && str_contains($liste['text'], 'data-gp-auswahl value="' . $pHttp . '"') && str_contains($liste['text'], $kennung . '-E1')
        && str_contains($liste['text'], 'data-gp-unterschreiben') && str_contains($liste['text'], 'data-unterschrift-dialog')
        && str_contains($liste['text'], '/assets/js/geraetepruefung.js'), 'Liste: Auswahl, Unterschreiben, Dialog, Skript');
    pruefe(preg_match('/\son[a-z]+\s*=/i', $liste['text']) !== 1 && preg_match('/<script(?![^>]*\b(src|nonce)=)/i', $liste['text']) !== 1, 'Liste ohne Inline-JS');
    $gefiltert = $web($doseT, 'GET', '/geraetepruefung/pruefungen?status=offen&ergebnis=bestanden&besitzer=eigen&pruefer=' . rawurlencode('Erika Mustermann')
        . '&von=2026-03-01&bis=2026-03-31&suche=' . rawurlencode($kennung . '-E1'));
    pruefe($gefiltert['status'] === 200 && str_contains($gefiltert['text'], 'value="' . $pHttp . '"') && !str_contains($gefiltert['text'], 'value="' . $pSammel[0] . '"')
        && !str_contains($gefiltert['text'], '/geraetepruefung/pruefungen/' . $pEigen . '"'), 'Filter: offen, bestanden, eigener Bestand, Prüfer, Zeitraum, Suche');
    $kundeListe = $web($doseT, 'GET', '/geraetepruefung/pruefungen?besitzer=' . $kundeA . '&status=unterschrieben');
    pruefe($kundeListe['status'] === 200 && str_contains($kundeListe['text'], '/geraetepruefung/pruefungen/' . $pKunde . '"')
        && !str_contains($kundeListe['text'], '/geraetepruefung/pruefungen/' . $pEigen . '"'), 'Filter: Besitzer Kunde, unterschrieben');
    $detail = $web($doseT, 'GET', '/geraetepruefung/pruefungen/' . $pKunde2);
    pruefe($detail['status'] === 200 && str_contains($detail['text'], '0,102 Ω') && str_contains($detail['text'], $bK2['nummer'] . '-K1')
        && str_contains($detail['text'], 'Seriennummer falsch') && str_contains($detail['text'], 'Korrektur anfordern')
        && preg_match('/\son[a-z]+\s*=/i', $detail['text']) !== 1, 'Detail: Messwerte mit Komma, beide Fassungen, Korrektur – ohne Inline-JS');
    $detailE = $web($doseT, 'GET', '/geraetepruefung/pruefungen/' . $pHttp);
    pruefe($detailE['status'] === 200 && str_contains($detailE['text'], 'data-gp-ids="' . $pHttp . '"') && str_contains($detailE['text'], 'Prüfbericht unterschreiben'),
        'Detail einer offenen Prüfung: Knopf zum Unterschreiben');
    gleich($web($doseT, 'GET', '/geraetepruefung/pruefungen/999999999')['status'], 404, 'unbekannte Prüfung → 404');
    gleich($web($doseT, 'GET', '/geraetepruefung/berichte/999999999/pdf')['status'], 404, 'unbekannter Bericht → 404');
    $tok = $token($liste['text']);
    $felder = static fn (array $ids, string $a, array $mehr = []): array => $mehr + ['ids' => json_encode($ids), 'striche' => unterschriftStriche(),
        'name' => 'Erika Mustermann', 'ort' => 'Musterstadt', 'pruefart' => 'instandsetzung', 'uebernehmen' => '0', 'anfrage' => $a];
    $r = $web($doseT, 'POST', '/geraetepruefung/unterschreiben', $felder([$pHttp], $anfrage()), $json);
    pruefe($r['status'] === 419 && (json_decode($r['text'], true)['ok'] ?? null) === false && $bericht($pHttp) === null, 'ohne Token → 419 (JSON), nichts');
    $r = $web($doseT, 'POST', '/geraetepruefung/unterschreiben', $felder([$pHttp], $anfrage(), ['_token' => $tok]), $json);
    $j = json_decode($r['text'], true);
    $bH = $bericht($pHttp);
    pruefe($r['status'] === 200 && ($j['ok'] ?? false) === true && ($j['unterschrieben'] ?? []) === [$pHttp] && $bH !== null
        && $bH['inhalt']['pruefung']['norm'] === 'DIN EN 50678 (VDE 0701)', 'Unterschreiben per HTTP: JSON, Prüfart Instandsetzung → DIN EN 50678');
    $r = $web($doseT, 'POST', '/geraetepruefung/unterschreiben', ['_token' => $tok, 'ids' => '{"a":1}', 'anfrage' => $anfrage()], $json);
    gleich($r['status'], 422, 'ids kein JSON-Liste → 422');
    $pdfAntwort = $web($doseT, 'GET', '/geraetepruefung/berichte/' . $bH['id'] . '/pdf');
    pruefe($pdfAntwort['status'] === 200 && $pdfAntwort['text'] === PruefberichtService::pdfGespeichert($bH['id'])
        && $kopfzeile($pdfAntwort, 'Content-Type') === 'application/pdf' && str_contains($kopfzeile($pdfAntwort, 'Cache-Control'), 'no-store')
        && $kopfzeile($pdfAntwort, 'X-Content-Type-Options') === 'nosniff'
        && $kopfzeile($pdfAntwort, 'Content-Disposition') === 'inline; filename="' . $bH['nummer'] . '.pdf"', 'PDF: gespeicherte Bytes, Kopfzeilen, Name nur die Nummer');
    // Korrektur und Prüfnachweis über die Formulare
    $tok = $token($detailE['text']);
    $r = $web($doseT, 'POST', '/geraetepruefung/berichte/' . $bH['id'] . '/korrektur', ['_token' => $tok, 'grund' => 'Prüfart falsch']);
    pruefe($r['status'] === 302 && $r['ziel'] === '/geraetepruefung/pruefungen/' . $pHttp && PruefberichtService::find($bH['id'])['status'] === 'korrektur',
        'Korrektur über das Formular → zurück zur Prüfung');
    $r = $web($doseT, 'POST', '/geraetepruefung/berichte/' . $bH['id'] . '/korrektur', ['grund' => 'ohne Token']);
    pruefe($r['status'] === 302 && Database::scalar('SELECT korrektur_grund FROM gp_berichte WHERE id = ?', [$bH['id']]) === 'Prüfart falsch', 'ohne Token nichts geändert');
    alsBenutzer($technik);
    PruefberichtService::unterschreiben([$pHttp2], $u($anfrage()));
    alsBenutzer(null);
    $bH2 = $bericht($pHttp2);
    $detail2 = $web($doseT, 'GET', '/geraetepruefung/pruefungen/' . $pHttp2);
    pruefe(str_contains($detail2['text'], 'Als Prüfnachweis an einen Kunden geben') && str_contains($detail2['text'], 'name="kunde_id"'), 'Detail: Prüfnachweis an einen Kunden');
    $r = $web($doseT, 'POST', '/geraetepruefung/berichte/' . $bH2['id'] . '/nachweis', ['_token' => $token($detail2['text']), 'kunde_id' => (string) $kundeB,
        'einsatz_von' => '2026-05-01', 'einsatz_bis' => '2026-05-03']);
    pruefe($r['status'] === 302 && Database::scalar('SELECT status FROM pruefnachweise WHERE gp_bericht_id = ? AND kunde_id = ?', [$bH2['id'], $kundeB]) === 'entwurf',
        'Prüfnachweis über das Formular: Entwurf bei Kunde B');
    // Ohne Recht
    $doseP = $anmelden($kennung . '-planung');
    $seiteP = $web($doseP, 'GET', '/geraetepruefung/pruefungen');
    pruefe($seiteP['status'] === 200 && !str_contains($seiteP['text'], 'data-gp-unterschreiben') && !str_contains($seiteP['text'], 'data-gp-auswahl'),
        'planung sieht die Liste ohne Unterschreiben');
    $r = $web($doseP, 'POST', '/geraetepruefung/unterschreiben', $felder([$pSperre], $anfrage(), ['_token' => $token($seiteP['text'])]), $json);
    pruefe($r['status'] === 403 && $bericht($pSperre) === null, 'planung: Unterschreiben → 403');
    // Update-Sperre
    $upd = (string) Config::get('update.verzeichnis');
    @mkdir($upd . '/ist', 0700, true);
    file_put_contents($upd . '/ist/wartung', \App\Services\UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n");
    try {
        $r = $web($doseT, 'POST', '/geraetepruefung/unterschreiben', $felder([$pSperre], $anfrage(), ['_token' => $tok]), $json);
        pruefe($r['status'] === 503 && (json_decode($r['text'], true)['wartung'] ?? false) === true && $kopfzeile($r, 'Retry-After') === '30'
            && $bericht($pSperre) === null, 'Update-Sperre: 503 mit wartung und Retry-After, nichts unterschrieben');
        gleich($web($doseT, 'GET', '/geraetepruefung/pruefungen')['status'], 200, '… lesen geht');
    } finally {
        @unlink($upd . '/ist/wartung');
    }
    // Kundenzugang
    $zugang = Database::insert('users', ['username' => $kennung . '-zugang', 'full_name' => 'Zugang', 'email' => '', 'password_hash' => password_hash($kennwort, PASSWORD_DEFAULT),
        'is_active' => 1, 'must_change_password' => 0, 'kunde_id' => $kundeA, 'created_at' => gmdate('c')]);
    $angelegt['users'][] = $zugang;
    $doseZ = $anmelden($kennung . '-zugang');
    foreach (['/geraetepruefung/pruefungen', '/geraetepruefung/pruefungen/' . $pKunde, '/geraetepruefung/berichte/' . $bK['id'] . '/pdf'] as $pfad) {
        $r = $web($doseZ, 'GET', $pfad);
        pruefe($r['status'] !== 200 && !str_contains($r['text'], '%PDF-') && !str_contains($r['text'], $kennung . '-K1'), 'Kundenzugang: ' . $pfad . ' abgewiesen (' . $r['status'] . ')');
    }
    gleich($phpMeldungen(), [], 'keine PHP-Meldung im Fehlerprotokoll des Servers');

    // ================================================ G – geraetepruefung.js ohne Browser (Node)
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        $skript = $ordner . '/gp-js.cjs';
        file_put_contents($skript, <<<'JS'
            const fs = require('fs'), vm = require('vm');
            const quelle = fs.readFileSync(process.argv[2], 'utf8');
            const fall = process.argv[3];
            const anfragen = [], meldungen = [], pausen = [], geloescht = [];
            let neu = 0, offen = null;
            const antworten = {
                wartung: [{ status: 200, erg: { ok: true, unterschrieben: [1], fehler: [] } }, { status: 503, erg: { ok: false, wartung: true } },
                    { status: 200, erg: { ok: true, unterschrieben: [2], fehler: [] } }, { status: 200, erg: { ok: true, unterschrieben: [3], fehler: [] } }],
                fehler: [{ status: 200, erg: { ok: true, unterschrieben: [1], fehler: [] } }, { status: 409, erg: { ok: false, message: '<b>Kennung</b>' } }],
            }[fall];
            const knopf = { dataset: {}, addEventListener: (t, f) => { knopf.klick = f; } };
            const dlg = { addEventListener: (t, f) => { if (t === 'close') { dlg.zu = f; } }, hasAttribute: (n) => n === 'open' };
            const felder = { '[data-gp-feld="pruefart"]': { value: 'wiederholung' }, '[data-gp-feld="uebernehmen"]': { checked: true } };
            const bereich = { querySelector: (s) => ({ '[data-gp-unterschreiben]': knopf, '[data-gp-stand]': {}, '[data-gp-fehler]': { firstChild: null, appendChild() {} },
                '[data-unterschrift-dialog]': dlg }[s] || felder[s] || null) };
            const boxen = []; for (let i = 1; i <= 60; i++) { boxen.push({ checked: i !== 7, value: String(i) }); }
            const window = {
                PM_TOKEN: 't', location: { reload: () => { neu++; } },
                setTimeout: (f, ms) => { pausen.push(ms); f(); return 1; }, clearTimeout: () => {},
                PMUnterschrift: {
                    dialog: (el, o) => { offen = o; return { oeffnen() {}, daten: () => '{"s":[[[1000,2000],[9000,8000]]]}', laeuft() {}, schliessen() {},
                        meldung: (t) => { meldungen.push(t); } }; },
                    schluessel: (n) => 'kennung-' + n, schluesselLoeschen: (n) => { geloescht.push(n); },
                },
                PMNetz: { post: (url, fd) => { anfragen.push({ url, ids: JSON.parse(fd.get('ids')), anfrage: fd.get('anfrage'), striche: fd.get('striche'),
                    uebernehmen: fd.get('uebernehmen'), token: fd.get('_token') }); const p = Promise.resolve(antworten.shift() || { status: 0, erg: {} }); p.abbrechen = () => {}; return p; } },
            };
            const document = { querySelector: (s) => s === '[data-gp-bereich]' ? bereich : null, querySelectorAll: (s) => s === 'input[data-gp-auswahl]' ? boxen : [] };
            // Eigenes FormData: das von Node lädt WebAssembly (undici) – unter ulimit -v scheitert das.
            class FormData { constructor() { this.d = {}; } append(k, v) { this.d[k] = String(v); } get(k) { return k in this.d ? this.d[k] : null; } }
            vm.runInNewContext(quelle, { window, document, FormData, Math, JSON, Number, String, Array, Promise });
            knopf.klick();
            offen.senden({ name: 'Erika Mustermann', ort: 'Musterstadt' });
            setTimeout(() => { process.stdout.write(JSON.stringify({ anfragen, meldungen, pausen, neu, geloescht })); }, 200);
            JS);
        $lauf = static fn (string $fall): array => json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($skript) . ' '
            . escapeshellarg($wurzel . '/public/assets/js/geraetepruefung.js') . ' ' . $fall), true) ?? [];
        $w = $lauf('wartung');
        $laengen = array_map(static fn (array $a): int => count($a['ids']), $w['anfragen'] ?? []);
        gleich($laengen, [25, 25, 25, 9], 'JS: 59 ausgewählte Prüfungen in Stücken zu höchstens 25, nach 503 dasselbe Stück noch einmal');
        pruefe(($w['anfragen'][1]['ids'] ?? 1) === ($w['anfragen'][2]['ids'] ?? 2) && !in_array(7, array_merge(...array_column($w['anfragen'] ?? [['ids' => [7]]], 'ids')), true),
            'JS: Wiederholung mit denselben Prüfungen, nicht angehakte bleiben draußen');
        gleich(count(array_unique(array_column($w['anfragen'] ?? [], 'anfrage'))) . '/' . count(array_unique(array_column($w['anfragen'] ?? [], 'striche'))), '1/1',
            'JS: eine Kennung und dieselben Striche für den ganzen Durchgang');
        pruefe(($w['anfragen'][0]['url'] ?? '') === '/geraetepruefung/unterschreiben' && ($w['anfragen'][0]['uebernehmen'] ?? '') === '1' && ($w['anfragen'][0]['token'] ?? '') === 't',
            'JS: Adresse, Token, „Verantwortung übernehmen“');
        gleich([$w['pausen'] ?? null, $w['neu'] ?? null, count($w['geloescht'] ?? [])], [[30000], 1, 1], 'JS: Pause 30 s bei wartung, am Ende neu geladen, Kennung gelöscht');
        $f = $lauf('fehler');
        pruefe(count($f['anfragen'] ?? []) === 2 && ($f['neu'] ?? 1) === 0 && str_contains(implode(' ', $f['meldungen'] ?? []), '<b>Kennung</b>')
            && str_contains(implode(' ', $f['meldungen'] ?? []), 'Schon unterschrieben: 1'), 'JS: 409 hält an (Meldung als Text, nichts neu geladen)');
    }
} finally {
    alsBenutzer(null);
    if ($fremdeUnterschrift !== null) {
        Database::execute('DELETE FROM bericht_unterschriften WHERE id = ?', [$fremdeUnterschrift]);
    }
    if ($angelegt['pruefungen'] !== []) {
        $liste = implode(',', array_map('intval', $angelegt['pruefungen']));
        foreach (Database::select('SELECT id, unterschrift_id FROM gp_berichte WHERE pruefung_id IN (' . $liste . ')') as $z) {
            Database::execute('DELETE FROM gp_bericht_pdf WHERE bericht_id = ?', [(int) $z['id']]);
            foreach (Database::select('SELECT id, datei_id FROM pruefnachweise WHERE gp_bericht_id = ?', [(int) $z['id']]) as $nw) {
                Database::execute('DELETE FROM pruefnachweise WHERE id = ?', [(int) $nw['id']]);
                Database::execute('DELETE FROM pruefnachweis_dateien WHERE id = ? AND NOT EXISTS (SELECT 1 FROM pruefnachweise WHERE datei_id = ?)', [(int) $nw['datei_id'], (int) $nw['datei_id']]);
            }
            Database::execute('DELETE FROM gp_unterschriften WHERE id = ?', [(int) $z['unterschrift_id']]);
            Database::execute('DELETE FROM gp_berichte WHERE id = ?', [(int) $z['id']]);
        }
        Database::execute('DELETE FROM gp_pruefungen WHERE id IN (' . $liste . ')');
    }
    foreach ($angelegt['prueflinge'] as $id) {
        Database::execute('DELETE FROM gp_prueflinge WHERE id = ?', [$id]);
    }
    Database::execute('DELETE FROM gp_pruefablaeufe WHERE geraet_id = ?', [$ablaufId]);
    foreach ($angelegt['pruefgeraete'] as $id) {
        Database::execute('DELETE FROM pruefgeraete WHERE id = ?', [$id]);
    }
    foreach ($angelegt['kunden'] as $id) {
        Database::execute('DELETE FROM pruefnachweise WHERE kunde_id = ?', [$id]);
        Database::execute('DELETE FROM kunden WHERE id = ?', [$id]);
    }
    foreach ($angelegt['users'] as $id) {
        BenutzerService::kontoEntfernen($id);
    }
}

ende('geraetepruefung-berichte');
