<?php
declare(strict_types=1);

/**
 * Übergabeberichte (Service-Ebene): Vorbelegung, Grenzwerte, Speichern,
 * Abschluss-Prüfung, Sichtbarkeit, Unterschriften (Einfrieren, Nummer,
 * Zurückziehen, Kunde, ohne Kunde), Kundenmodus, Integrität, Korrektur,
 * PDF (Ablage beim Abschluss, Vorschau, Neuerzeugung), Lösch-Abläufe und
 * Datenschutz. Das Layout des PDF prüft tests/bericht-pdf.php.
 *
 * Für Vorbelegung, Sichtbarkeit und Lösch-Abläufe entstehen abgeschlossene
 * Berichte der Kürze halber per SQL (Status, Nummer, Zeit); den echten Weg
 * über beide Unterschriften prüft der Abschnitt „Einfrieren“.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Berichte\BerichtPdf;
use App\Berichte\BerichtSchema;
use App\Berichte\BerichtService;
use App\Berichte\Einstellungen;
use App\Berichte\Grenzwerte;
use App\Berichte\Kanon;
use App\Berichte\Kundenmodus;
use App\Berichte\Pruefgeraete;
use App\Berichte\Unterschrift;
use App\Berichte\Vorbelegung;
use App\Services\AnlagenService;
use App\Services\AufbauService;
use App\Services\BenutzerService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Settings;
use App\Support\Zeit;

AnlagenService::grundbestand();
$typ = [];
foreach (AnlagenService::steckertypen() as $t) {
    $typ[(string) $t['schluessel']] = (int) $t['id'];
}
$kennung = 'pm-ber-' . bin2hex(random_bytes(3));
$kennwort = 'Pruefung-' . bin2hex(random_bytes(6));
$heute = (new DateTimeImmutable('now', Zeit::zone()))->format('Y-m-d');
$einstellungenVorher = [];
foreach (array_keys(Einstellungen::VORGABEN) as $s) {
    $einstellungenVorher[$s] = Settings::get($s);
}
$benutzer = [];
$rollen = [];
$sites = [];
$anlagen = [];
$inventar = [];

/** Einen Bericht als abgeschlossen ausgeben (bis Unterschrift und PDF eigene Prüfungen haben). */
$abschliessen = static function (int $id, string $nummer, ?string $pruefdatum = null): void {
    $felder = ['status' => 'abgeschlossen', 'nummer' => $nummer, 'abgeschlossen_am' => gmdate('c'), 'eingefroren_am' => gmdate('c')];
    if ($pruefdatum !== null) {
        $felder['pruefdatum'] = $pruefdatum;
    }
    Database::update('berichte', $id, $felder);
};
/** Inhalt als Browser-JSON speichern. */
$speichern = static function (int $id, array $inhalt, string $ewx = ''): array {
    $b = BerichtService::find($id);
    return BerichtService::speichern($id, (int) $b['version'], json_encode($inhalt, JSON_UNESCAPED_UNICODE), $ewx);
};
/** Alle Punkte der Kurzliste (Besichtigung, Erprobung) auf i.O. */
$kurzlisteIo = static function (): array {
    $punkte = ['besichtigung' => [], 'erprobung' => []];
    foreach (['besichtigung' => App\Berichte\Katalog::besichtigung(), 'erprobung' => App\Berichte\Katalog::erprobung()] as $teil => $katalog) {
        foreach ($katalog as $schluessel => $punkt) {
            if ($punkt['kurz']) {
                $punkte[$teil][$schluessel] = 'io';
            }
        }
    }
    return $punkte;
};
/** Ein Jahr ohne Berichte und ohne Nummernzähler ab $ab – die Prüfung löscht seinen Zähler am Ende wieder. */
$freiesJahr = static function (int $ab): int {
    while ((int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE jahr = ? OR pruefdatum LIKE ?', [$ab, $ab . '-%']) > 0
        || Database::scalar('SELECT laufnr FROM bericht_nummern WHERE jahr = ?', [$ab]) !== false) {
        $ab++;
    }
    return $ab;
};
$testJahre = [];
$kreisNach = static function (array $inhalt, string $feld, string $wert): ?array {
    foreach ($inhalt['kreise'] as $k) {
        if ((string) $k[$feld] === $wert) {
            return $k;
        }
    }
    return null;
};

try {
    // ================================================== Aufbau des Testfalls
    $benutzer['admin'] = BenutzerService::anlegen($kennung . '-admin', $kennwort, 'admin', false);
    alsBenutzer($benutzer['admin']);
    $rolle = static function (string $name, array $rechte) use (&$rollen, $kennung): string {
        $id = (int) BenutzerService::rolleSpeichern(null, $kennung . ' ' . $name, '', $rechte)['id'];
        $rollen[] = $id;
        return (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$id]);
    };
    $benutzer['technik'] = BenutzerService::anlegen($kennung . '-technik', $kennwort, 'technik', false);
    $benutzer['planer'] = BenutzerService::anlegen($kennung . '-planer', $kennwort, $rolle('planer', ['berichte.view', 'berichte.manage', 'anlagen.view']), false);
    $benutzer['kunde'] = BenutzerService::anlegen($kennung . '-kunde', $kennwort, $rolle('kunde', ['berichte.view', 'anlagen.view']), false);
    $benutzer['kunde2'] = BenutzerService::anlegen($kennung . '-kunde2', $kennwort, $rolle('kunde2', ['berichte.view', 'anlagen.view']), false);
    Database::update('users', $benutzer['technik'], ['pruefer_qualifikation' => 'Elektrofachkraft für Veranstaltungstechnik', 'full_name' => 'Tom Technik']);

    $siteId = SiteService::saveSite(null, ['name' => 'Prüfung Bericht ' . $kennung, 'address' => 'Nordwiese 1, 12345 Musterstadt']);
    $sites[] = $siteId;
    $floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Wiese']);
    $fremdSite = SiteService::saveSite(null, ['name' => 'Prüfung fremd ' . $kennung]);
    $sites[] = $fremdSite;

    $neu = static function (array $d) use (&$anlagen): int {
        $e = AnlagenService::speichern(null, $d);
        pruefe($e['ok'], 'Anlage angelegt: ' . $e['message']);
        $anlagen[] = (int) $e['id'];
        return (int) $e['id'];
    };
    $verteiler = static function (string $name, int $site, ?int $floor = null) use ($neu, $typ): int {
        $id = $neu(['art' => 'verteilung', 'name' => $name, 'site_id' => $site, 'floor_id' => $floor, 'ohne_anschluesse' => true]);
        AnlagenService::anzahlSetzen($id, ['eingang' => [$typ['cee63'] => 1], 'abgang' => [$typ['cee32'] => 2, $typ['schuko'] => 2]]);
        SchutzService::vorschlagen($id);
        return $id;
    };
    $gen = $neu(['art' => 'generator', 'name' => $kennung . ' Aggregat', 'floor_id' => $floorId, 'hersteller' => 'Muster', 'modell' => 'MG 100',
        'nennleistung_kva' => 100, 'spannung_v' => 400, 'kennung' => 'G-' . $kennung]);
    $vt = $verteiler($kennung . ' Bühne', $siteId, $floorId);
    $fremdV = $verteiler($kennung . ' fremd', $fremdSite);
    $genCee63 = (int) Database::scalar('SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = ? AND steckertyp_id = ? ORDER BY nr LIMIT 1', [$gen, 'abgang', $typ['cee63']]);
    $vtEingang = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang'", [$vt]);
    $kabel = LeitungService::speichern(null, ['art' => 'kabel', 'name' => $kennung . ' G-V', 'floor_id' => $floorId, 'von_objekt_id' => $gen,
        'von_anschluss_id' => $genCee63, 'zu_objekt_id' => $vt, 'zu_anschluss_id' => $vtEingang, 'kabeltyp' => 'H07RN-F', 'adern' => 5,
        'querschnitt_mm2' => 16, 'laenge_m' => 25, 'punkte' => [[10.0, 10.0], [20.0, 10.0]]]);
    pruefe($kabel['ok'], 'Kabel Generator → Verteiler: ' . $kabel['message']);
    $stueck = (int) InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $kennung . '-PD', 'seriennummer' => 'SN-' . $kennung])['id'];
    $inventar[] = $stueck;
    pruefe(InventarService::einsetzen($stueck, $vt, null, false)['ok'], 'Inventarstück in den Verteiler eingesetzt');
    $aufbau = AufbauService::starten($siteId, $kennung . ' Sommerfest');
    pruefe($aufbau['ok'], 'Aufbau gestartet');
    $aufbauId = (int) $aufbau['id'];
    $fremdAufbau = (int) AufbauService::starten($fremdSite, $kennung . ' fremd')['id'];

    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['technik'], 'ziel_art' => 'site', 'ziel_id' => $siteId, 'darf_bearbeiten' => 1]);
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['planer'], 'ziel_art' => 'site', 'ziel_id' => $siteId]);
    $gestern = (new DateTimeImmutable('-1 day', Zeit::zone()))->format('Y-m-d');
    $morgen = (new DateTimeImmutable('+1 day', Zeit::zone()))->format('Y-m-d');
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['kunde'], 'ziel_art' => 'site', 'ziel_id' => $siteId,
        'daten_ab' => $gestern, 'daten_bis' => $morgen]);
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['kunde2'], 'ziel_art' => 'objekt', 'ziel_id' => $vt]);

    Einstellungen::speichern(['firma.name' => '', 'berichte.zs_faktor' => '1.0', 'berichte.ul' => '50', 'berichte.riso_u' => '500',
        'berichte.normen' => '0100-600,0100-711,dguv-v3', 'berichte.aufbewahrung_jahre' => '10']);

    // ================================================== Kanon
    gleich(Kanon::json(['b' => '1', 'a' => ['y' => '2', 'x' => '3'], 'l' => ['z', 'a']]), '{"a":{"x":"3","y":"2"},"b":"1","l":["z","a"]}',
        'Kanon: Objekte sortiert, Listen in Reihenfolge');
    gleich(Kanon::json(['t' => 'Ω ≤ 1,44 / IΔn']), '{"t":"Ω ≤ 1,44 / IΔn"}', 'Kanon: Unicode und Schrägstrich unverändert');
    $geworfen = false;
    try {
        Kanon::json(['w' => 0.42]);
    } catch (InvalidArgumentException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'Kanon: Gleitkommazahl wird abgelehnt');
    gleich(Kanon::hash(['a' => '1']), hash('sha256', '{"a":"1"}'), 'Kanon: Hash = sha256 der Schreibweise');
    pruefe(strlen(Crypto::ableiten('bericht-siegel-1')) === 32 && Crypto::ableiten('bericht-siegel-1') === Crypto::ableiten('bericht-siegel-1')
        && Crypto::ableiten('bericht-siegel-1') !== Crypto::ableiten('anderer-zweck'), 'Crypto::ableiten: 32 Byte, fest je Zweck, verschieden je Zweck');

    // ================================================== Überstromschutz in der Kette
    $schutz = SchutzService::nachId($vt);
    $abgangX1 = (int) Database::scalar("SELECT schutzorgan_id FROM anschluesse WHERE objekt_id = ? AND bezeichnung = 'X1'", [$vt]);
    $rcdId = (int) (SchutzService::rcd($abgangX1, $schutz)['id'] ?? 0);
    gleich(SchutzService::ueberstrom($abgangX1, $schutz)['art'] ?? null, 'ls', 'Überstromschutz des Abgangs: das LS');
    gleich(SchutzService::ueberstrom($rcdId, $schutz), null, 'direkt am RCD: kein Überstromschutz');

    // ================================================== Grenzwerte
    $p = Grenzwerte::parameter();
    $p['zs_faktor'] = '1.0';
    $p['ul'] = '50';
    nahe(Grenzwerte::zsMax('C', '16', $p), 1.4375, 1e-9, 'Zs_max C16');
    nahe(Grenzwerte::zsMax('B', '16', $p), 2.875, 1e-9, 'Zs_max B16');
    nahe(Grenzwerte::zsMax('K', '16', $p), 1.0268, 1e-4, 'Zs_max K16 (14·In)');
    nahe(Grenzwerte::zsMax('D', '10', $p), 1.15, 1e-9, 'Zs_max D10');
    nahe(Grenzwerte::zsMax('C', '16', ['zs_faktor' => '0.67'] + $p), 0.963, 1e-3, 'Zs_max C16 mit Faktor 0,67');
    gleich(Grenzwerte::zsMax('gG', '63', $p), null, 'gG: kein Rechenwert');
    gleich(Grenzwerte::messwert('0,42'), ['op' => '', 'w' => '0.42'], 'Messwert „0,42“');
    gleich(Grenzwerte::messwert('> 550'), ['op' => '>', 'w' => '550'], 'Messwert „> 550“');
    gleich(Grenzwerte::messwert('≥1'), ['op' => '>', 'w' => '1'], 'Messwert „≥1“');
    gleich(Grenzwerte::messwert('≤0,1'), ['op' => '<', 'w' => '0.1'], 'Messwert „≤0,1“');
    gleich(Grenzwerte::messwert('abc'), null, 'Messwert „abc“ → null');
    gleich(Grenzwerte::messwert(',5'), ['op' => '', 'w' => '0.5'], 'Messwert „,5“');

    $muster = static function (array $aend = []): array {
        $k = BerichtSchema::kreisLeer();
        $k['nr'] = '1';
        $k['ueb'] = ['art' => 'LS', 'char' => 'C', 'in' => '16', 'grenze_hand' => ''];
        $k['rcd'] = ['bez' => 'F1', 'in' => '40', 'typ' => 'A', 'idn' => '30', 's' => '0'];
        $k['leitung'] = ['art' => 'Schuko', 'leiter' => '3', 'querschnitt' => '2.5'];
        $k['w']['zs'] = ['op' => '', 'w' => '0.5'];
        $k['w']['riso'] = ['op' => '>', 'w' => '550'];
        $k['w']['idelta'] = ['op' => '', 'w' => '22'];
        $k['w']['ta'] = ['op' => '', 'w' => '25'];
        $k['w']['ub'] = ['op' => '', 'w' => '0.8'];
        $k['status'] = 'gemessen';
        return array_replace_recursive($k, $aend);
    };
    $tn = ['form' => 'TN-S'];
    $messung = ['riso_u' => '500'];
    $bew = static fn (array $k, array $netz = ['form' => 'TN-S'], array $nachZ = [], array $m = ['riso_u' => '500']): array => Grenzwerte::kreis($k, $nachZ, $netz, $p, $m);
    $codes = static fn (array $e): array => array_column($e['meldungen'], 'code');

    $e = $bew($muster());
    gleich([$e['auto'], $e['abschaltung']], ['ok', 'LS'], 'gute Zeile: ok, Abschaltung durch LS');
    gleich($e['grenzen']['zs_max'] ?? null, '1.4375', 'Grenze Zs ungerundet im Ergebnis');
    $e = $bew($muster(['w' => ['zs' => ['w' => '2.0']]]));
    pruefe($e['auto'] === 'hinweis' && $e['abschaltung'] === 'RCD' && in_array('zs_rcd', $codes($e), true), 'Zs 2,0 an C16 mit RCD 30 mA → Hinweis, Abschaltung durch RCD');
    // Ohne RCD: Verteilerkreis (keine RCD-Pflicht), keine RCD-Werte.
    $ohneRcd = ['art' => 'verteiler', 'rcd' => ['bez' => '', 'in' => '', 'typ' => '', 'idn' => ''],
        'w' => ['idelta' => ['w' => ''], 'ta' => ['w' => ''], 'ub' => ['w' => '']]];
    $e = $bew($muster(array_replace_recursive($ohneRcd, ['w' => ['zs' => ['w' => '2.0']]])));
    pruefe($e['auto'] === 'mangel' && in_array('zs_hoch', $codes($e), true), 'Zs 2,0 an C16 ohne RCD → Mangel');
    $e = $bew($muster(array_replace_recursive($ohneRcd, ['w' => ['zs' => ['w' => ''], 'ik' => ['w' => '150']]])));
    pruefe($e['auto'] === 'mangel' && ($e['grenzen']['ik_min'] ?? '') === '160', 'Ik 150 A an C16 → Mangel (Grenze 160 A)');
    $e = $bew($muster(array_replace_recursive($ohneRcd, ['w' => ['zs' => ['w' => ''], 'ik' => ['w' => '200']]])));
    gleich($e['auto'], 'ok', 'Ik 200 A an C16 → ok');
    $e = $bew($muster(array_replace_recursive($ohneRcd, ['ueb' => ['art' => 'NH', 'char' => 'gG', 'in' => '63']])));
    pruefe($e['auto'] === 'manuell' && in_array('zs_manuell', $codes($e), true), 'NH ohne Grenzwert von Hand → manuell');
    $e = $bew($muster(array_replace_recursive($ohneRcd, ['ueb' => ['art' => 'NH', 'char' => 'gG', 'in' => '63', 'grenze_hand' => '0.6'], 'leitung' => ['querschnitt' => '16']])));
    gleich($e['auto'], 'ok', 'NH mit Grenzwert von Hand → ok');
    $e = $bew($muster(['ueb' => ['art' => 'NH', 'char' => 'gG', 'in' => '63']]));
    pruefe($e['auto'] === 'hinweis' && $e['abschaltung'] === 'RCD', 'NH ohne Rechenwert, aber RCD: Abschaltung durch RCD');
    $e = $bew($muster(['w' => ['riso' => ['op' => '', 'w' => '0.8']]]));
    pruefe($e['auto'] === 'mangel' && in_array('riso_klein', $codes($e), true), 'R_iso 0,8 MΩ bei 500 V → Mangel');
    // 250 V / 0,5 MΩ gilt nur für SELV/PELV (DIN VDE 0100-600 Tab. 6.1); bei 230/400 V mit reduzierter Messspannung 1 MΩ (6.4.3.3).
    $e = $bew($muster(['w' => ['riso' => ['op' => '', 'w' => '0.6'], 'riso_u' => '250']]));
    pruefe($e['auto'] === 'mangel' && in_array('riso_klein', $codes($e), true) && ($e['grenzen']['riso_min'] ?? '') === '1', 'R_iso 0,6 MΩ bei 250 V → Mangel (Grenze 1 MΩ)');
    $e = $bew($muster(['w' => ['riso' => ['op' => '', 'w' => '1.2'], 'riso_u' => '250']]));
    pruefe($e['auto'] === 'ok' && in_array('riso_250', $codes($e), true), 'R_iso 1,2 MΩ bei 250 V → ok, mit Info „reduzierte Messspannung“');
    gleich([Grenzwerte::parameter()['version'], Grenzwerte::parameter()['riso']['250']], ['2', '1.0'], 'Grenzwerte Fassung 2: 250 V → 1,0 MΩ');
    $e = $bew($muster(), $tn, [], ['riso_u' => '']);
    pruefe($e['auto'] === 'fehlt' && in_array('riso_u_fehlt', $codes($e), true), 'R_iso ohne Messspannung → fehlt');
    foreach (['15' => 'mangel', '22' => 'ok', '31' => 'mangel'] as $wert => $soll) {
        gleich($bew($muster(['w' => ['idelta' => ['w' => (string) $wert]]]))['auto'], $soll, 'IΔ ' . $wert . ' mA bei 30 mA');
    }
    gleich($bew($muster(['w' => ['ta' => ['w' => '280']]]))['auto'], 'ok', 't_A 280 ms → ok');
    gleich($bew($muster(['w' => ['ta' => ['w' => '320']]]))['auto'], 'mangel', 't_A 320 ms → Mangel');
    gleich($bew($muster(['rcd' => ['s' => '1'], 'w' => ['ta' => ['w' => '200']]]))['auto'], 'ok', 'Typ S: t_A 200 ms → ok');
    gleich($bew($muster(['rcd' => ['s' => '1'], 'w' => ['ta' => ['w' => '100']]]))['auto'], 'mangel', 'Typ S: t_A 100 ms → Mangel');
    gleich($bew($muster(['w' => ['ub' => ['w' => '51']]]))['auto'], 'mangel', 'U_B 51 V → Mangel');
    $e = $bew($muster(['rcd' => ['idn' => '300']]));
    pruefe($e['auto'] === 'mangel' && in_array('rcd_pflicht', $codes($e), true), 'Endstromkreis 16 A ohne 30-mA-RCD → Mangel');
    gleich($bew($muster(['drehfeld' => 'links']))['auto'], 'mangel', 'Linksdrehfeld → Mangel');
    $e = $bew($muster(['w' => ['zs' => ['w' => '']]]), ['form' => 'TT']);
    pruefe($e['auto'] === 'ok' && !in_array('zs_fehlt', $codes($e), true), 'TT mit RCD: Zs je Zeile entfällt');
    $e = $bew($muster(['w' => ['zs' => ['w' => '']]]), ['form' => 'IT']);
    gleich($e['auto'], 'ok', 'IT mit RCD: Zs entfällt');
    // Ohne RCD gilt auch im TT-System Zs · Ia ≤ U0 (DIN VDE 0100-410 411.5.4): C63 mit 25 Ω schaltet nicht ab.
    $c63 = array_replace_recursive($ohneRcd, ['ueb' => ['char' => 'C', 'in' => '63'], 'leitung' => ['querschnitt' => '16']]);
    $e = $bew($muster(array_replace_recursive($c63, ['w' => ['zs' => ['w' => '25']]])), ['form' => 'TT']);
    pruefe($e['auto'] === 'mangel' && in_array('zs_hoch', $codes($e), true), 'TT ohne RCD: Zs 25 Ω an C63 → Mangel');
    $e = $bew($muster(array_replace_recursive($c63, ['w' => ['zs' => ['w' => '']]])), ['form' => 'TT']);
    pruefe($e['auto'] === 'fehlt' && in_array('zs_fehlt', $codes($e), true), 'TT ohne RCD: Zs ist Pflicht');
    $e = $bew($muster(array_replace_recursive($c63, ['w' => ['zs' => ['w' => '25']]])), ['form' => 'IT']);
    pruefe($e['auto'] === 'manuell' && in_array('it_zweitfehler', $codes($e), true), 'IT ohne RCD: Zweitfehler selbst bewerten');
    // TN-C: ein RCD ist dort unwirksam (DIN VDE 0100-410 411.4.5).
    $e = $bew($muster(['w' => ['zs' => ['w' => '3.2']]]), ['form' => 'TN-C']);
    pruefe($e['auto'] === 'mangel' && $e['abschaltung'] !== 'RCD' && in_array('rcd_tnc', $codes($e), true) && in_array('rcd_pflicht', $codes($e), true)
        && !in_array('zs_rcd', $codes($e), true), 'TN-C: RCD weder Abschaltung noch Zusatzschutz');
    pruefe(in_array('netz_tnc', array_column(Grenzwerte::netz(['form' => 'TN-C'], [], $p)['meldungen'], 'code'), true), 'TN-C: Hinweis „Netzform prüfen“');
    pruefe(in_array('it_imd', array_column(Grenzwerte::netz(['form' => 'IT'], [], $p)['meldungen'], 'code'), true), 'IT: Hinweis Isolationsüberwachung');
    $tt = static fn (string $w): string => Grenzwerte::erdung(['erdung' => ['op' => '', 'w' => $w, 'basis' => '']], ['form' => 'TT'], [$muster()], $p)['auto'];
    gleich([$tt('1700'), $tt('1600')], ['mangel', 'ok'], 'TT: Erdung 1700 Ω bei 30 mA → Mangel, 1600 Ω → ok');
    $gen50 = Grenzwerte::erdung(['erdung' => ['op' => '', 'w' => '12.4', 'basis' => '']], ['form' => 'TN-S', 'einspeisung' => 'generator'], [], $p);
    gleich([$gen50['auto'], $gen50['basis'], $gen50['grenze']], ['ok', 'generator', '50'], 'Generator: Erdung 12,4 Ω gegen 50 Ω');
    $itGen = Grenzwerte::erdung(['erdung' => ['op' => '', 'w' => '80', 'basis' => '']], ['form' => 'IT', 'einspeisung' => 'generator'], [], $p);
    gleich([$itGen['auto'], $itGen['basis']], ['entf', 'keine'], 'IT mit Generator: 80 Ω ohne Bewertung, kein Mangel nach „Generator im TN-System“');
    $itGen = Grenzwerte::erdung(['erdung' => ['op' => '', 'w' => '12', 'basis' => 'generator']], ['form' => 'IT', 'einspeisung' => 'generator'], [], $p);
    pruefe($itGen['auto'] === 'hinweis' && in_array('erdung_bezug', array_column($itGen['meldungen'], 'code'), true), 'IT, Bezug „Generator“ von Hand → Hinweis „gilt für TN“');
    $bezug = $muster();
    $bezug['z'] = 'kbezug01';
    $ohne = $muster(['w' => ['idelta' => ['w' => ''], 'ta' => ['w' => ''], 'ub' => ['w' => '']]]);
    $ohne['z'] = 'kohne001';
    $e = $bew(array_replace($ohne, ['rcd_von' => 'kbezug01']), $tn, ['kbezug01' => $bezug]);
    pruefe($e['auto'] === 'ok' && in_array('rcd_von', $codes($e), true), 'rcd_von übernimmt die RCD-Werte der Bezugszeile');
    $e = $bew($ohne);
    pruefe($e['auto'] === 'fehlt' && in_array('rcd_fehlt', $codes($e), true), 'ohne RCD-Werte und ohne Bezug → fehlt');
    $e = $bew(array_replace($ohne, ['rcd_von' => 'kbezug01']), $tn, ['kbezug01' => $ohne]);
    pruefe($e['auto'] === 'fehlt' && in_array('rcd_von_leer', $codes($e), true), 'Bezugszeile ohne RCD-Werte → fehlt');
    // Werte eines anderen RCD gelten nicht (DIN VDE 0100-600 6.4.3.7): jeder RCD wird gemessen.
    $f2 = $muster(['rcd' => ['bez' => 'F2'], 'w' => ['idelta' => ['w' => '20'], 'ta' => ['w' => '30']]]);
    $f2['z'] = 'kbezugf2';
    $e = $bew(array_replace_recursive($ohne, ['rcd_von' => 'kbezugf2', 'rcd' => ['bez' => 'F3']]), $tn, ['kbezugf2' => $f2]);
    pruefe($e['auto'] === 'fehlt' && in_array('rcd_von_anders', $codes($e), true) && !in_array('rcd_von', $codes($e), true), 'rcd_von an anderem RCD (F2 statt F3) → fehlt, Werte nicht übernommen');
    $e = $bew(array_replace_recursive($ohne, ['rcd_von' => 'kbezug01', 'rcd' => ['idn' => '300'], 'art' => 'verteiler']), $tn, ['kbezug01' => $bezug]);
    pruefe($e['auto'] === 'fehlt' && in_array('rcd_von_anders', $codes($e), true), 'rcd_von mit anderem IΔn (300 statt 30 mA) → fehlt');
    $e = $bew(array_replace_recursive($ohne, ['rcd_von' => 'kbezug01', 'rcd' => ['bez' => '']]), $tn, ['kbezug01' => $bezug]);
    pruefe($e['auto'] === 'hinweis' && in_array('rcd_von_unbelegt', $codes($e), true), 'rcd_von ohne Bezeichnung → Hinweis „gleicher RCD nicht belegt“');
    $fremd = $bezug;
    $fremd['objekt_id'] = '424242';
    $e = $bew(array_replace($ohne, ['rcd_von' => 'kbezug01', 'objekt_id' => '434343']), $tn, ['kbezug01' => $fremd]);
    pruefe(in_array('rcd_von_anders', $codes($e), true), 'rcd_von an einer anderen Anlage → anderer RCD');
    gleich($bew(BerichtSchema::kreisLeer())['auto'], '', 'Zeile ohne Wert ist offen');
    gleich($bew($muster(['status' => 'nicht_gemessen', 'grund' => 'nicht zugänglich']))['auto'], 'entf', 'nicht gemessen mit Grund → entf');
    gleich($bew($muster(['status' => 'nicht_gemessen']))['auto'], 'fehlt', 'nicht gemessen ohne Grund → fehlt');
    gleich($bew($muster(['status' => 'nicht_gemessen', 'grund' => 'x', 'rcd' => ['idn' => '300']]))['auto'], 'mangel', 'nicht gemessen, aber ohne 30-mA-RCD → Mangel');
    $e = $bew($muster(['w' => ['zs' => ['w' => 'abc']]]));
    pruefe($e['auto'] === 'fehlt' && in_array('unlesbar_zs', $codes($e), true), 'unlesbarer Wert → fehlt');
    $e = $bew($muster(['ueb' => ['in' => '32'], 'leitung' => ['querschnitt' => '1.5'], 'w' => ['zs' => ['w' => '0.3']]]));
    pruefe(in_array('querschnitt', $codes($e), true), 'Querschnitt 1,5 mm² an 32 A → Hinweis');
    gleich([Grenzwerte::netz(['u_ln' => '250'], [], $p)['auto'], Grenzwerte::netz(['u_ln' => '255'], [], $p)['auto']], ['ok', 'hinweis'],
        'Netzspannung 250 V im Band, 255 V → Hinweis');

    // ================================================== Vorbelegung und Anlegen
    alsBenutzer($benutzer['technik']);
    $e = BerichtService::anlegen(null, $aufbauId, [$vt, $gen]);
    pruefe($e['ok'], 'Bericht aus dem Aufbau angelegt: ' . $e['message']);
    $id = (int) ($e['id'] ?? 0);
    $b = BerichtService::find($id);
    $i = $b['inhalt'];
    $abgaengeGen = (int) Database::scalar("SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang'", [$gen]);
    gleich(count($i['kreise']), $abgaengeGen + 4, 'je Abgang von Generator und Verteiler eine Zeile');
    gleich((string) $i['kreise'][0]['objekt_id'], (string) $gen, 'Einspeisung zuerst');
    gleich([$b['status'], $b['version'], $b['site_id'], $b['aufbau_id'], $b['nummer']], ['entwurf', 1, $siteId, $aufbauId, null], 'Entwurf ohne Nummer');
    $x1 = null;
    foreach ($i['kreise'] as $k) {
        if ((string) $k['objekt_id'] === (string) $vt && $k['abgang'] === 'X1') {
            $x1 = $k;
        }
    }
    pruefe($x1 !== null, 'Verteiler: Zeile für X1');
    gleich($x1['leitung'], ['art' => 'CEE 32 A', 'leiter' => '5', 'querschnitt' => ''], 'CEE 32: Art, Leiter 5, Querschnitt leer');
    pruefe(in_array('leitung.leiter', $x1['abgeleitet'], true), 'Leiterzahl als „aus Plan“ markiert');
    gleich([$x1['ueb']['art'], $x1['ueb']['char'], $x1['ueb']['in']], ['LS', 'C', '32'], 'Überstromschutz LS C32');
    gleich([$x1['rcd']['bez'], $x1['rcd']['in'], $x1['rcd']['typ'], $x1['rcd']['idn']], ['F1', '40', 'A', '30'], 'RCD F1 40 A Typ A 30 mA');
    gleich((string) $x1['inventar_id'], (string) $stueck, 'Inventarstück an der Zeile');
    pruefe(str_contains($x1['ort'], 'X1 · CEE 32 A · F1'), 'Ort/Anlagenteil: ' . $x1['ort']);
    $schuko = $kreisNach(['kreise' => array_values(array_filter($i['kreise'], static fn ($k) => (string) $k['objekt_id'] === (string) $vt))], 'abgang', 'X3');
    gleich($schuko['leitung']['leiter'] ?? null, '3', 'Schuko: Leiter 3');
    $genX = $kreisNach($i, 'anschluss_id', (string) $genCee63);
    pruefe($genX !== null && $genX['art'] === 'verteiler' && str_contains($genX['kabel'], 'H07RN-F 5G16, 25 m → ' . $kennung . ' Bühne'),
        'Abgang mit Kabel zum Verteiler: ' . ($genX['kabel'] ?? '?'));
    pruefe($genX !== null && $genX['leitung']['leiter'] === '5' && !in_array('leitung.leiter', $genX['abgeleitet'], true), 'Leiter aus dem Kabel – nicht abgeleitet');
    $werte = [];
    foreach ($i['kreise'] as $k) {
        foreach (Grenzwerte::MESSWERTE as $f) {
            $werte[] = $k['w'][$f]['w'];
        }
    }
    gleich(array_unique($werte), [''], 'Messwerte nie vorbelegt');
    gleich($i['kopf']['veranstaltung'], $kennung . ' Sommerfest', 'Veranstaltung = Name des Aufbaus');
    gleich([$i['netz']['spannung'], $i['netz']['form'], $i['netz']['einspeisung'], $i['grundlage']['grund']], ['400', '', 'generator', 'aufbau'],
        'Netz 400 V, Netzform leer, Einspeisung Generator, Grund Aufbau');
    gleich($i['kopf']['ort'], 'Musterstadt', 'Ort aus der Adresse');
    pruefe(str_contains($i['kopf']['anlage']['bezeichnung'], 'Muster MG 100, 100 kVA'), 'Bezeichnung der Anlage: ' . $i['kopf']['anlage']['bezeichnung']);
    pruefe(str_contains($i['kopf']['anlage']['details'], $kennung . '-PD') && str_contains($i['kopf']['anlage']['details'], 'SN-' . $kennung), 'Details nennen das Inventarstück');
    pruefe(str_starts_with($i['kopf']['anlage']['aufstellort'], 'Prüfung Bericht ' . $kennung), 'Aufstellort aus dem Standort');
    gleich([$i['kopf']['qualifikation'], $i['kopf']['projektleiter_montage']], ['Elektrofachkraft für Veranstaltungstechnik', 'Tom Technik'], 'Prüfer aus dem Konto');
    gleich([$i['grenzwerte']['k']['C'] ?? null, $i['grenzwerte']['zs_faktor'] ?? null], ['10', '1.0'], 'Grenzwerte beim Anlegen festgehalten');
    gleich($i['grundlage']['normen'], ['0100-600', '0100-711', 'dguv-v3'], 'Normen aus den Einstellungen');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ?', [$id]), 2, 'bericht_objekte: Generator und Verteiler');
    gleich((int) Database::scalar('SELECT inventar_id FROM bericht_objekte WHERE bericht_id = ? AND objekt_id = ?', [$id, $vt]), $stueck, 'bericht_objekte merkt das Inventarstück');
    gleich(array_column(BerichtService::ereignisse($id), 'ereignis'), ['angelegt'], 'Ereignis „angelegt“');
    $hinweise = Vorbelegung::hinweise($i, $siteId, $aufbauId);
    pruefe(isset($hinweise['dokumentation']) && !isset($hinweise['rcd30']), 'Hinweis am Prüfpunkt: Aufbau offen; alle Kreise ≤ 32 A mit 30-mA-RCD');
    $ohne30 = $i;
    $ohne30['kreise'][] = $muster(['rcd' => ['idn' => '300'], 'nr' => '77']);
    pruefe(isset(Vorbelegung::hinweise($ohne30, $siteId, null)['rcd30']), 'Hinweis am Prüfpunkt: Kreis ohne 30-mA-RCD');

    $r = BerichtService::kreiseVorbelegen($id, 1, [$vt]);
    pruefe($r['ok'] && $r['neu'] === 0 && count(BerichtService::find($id)['inhalt']['kreise']) === count($i['kreise']), 'Zeilen ergänzen legt keine Doppelten an');
    AnlagenService::anzahlSetzen($vt, ['abgang' => [$typ['schuko'] => 3]]);
    $r = BerichtService::kreiseVorbelegen($id, (int) BerichtService::find($id)['version'], [$vt]);
    $i2 = BerichtService::find($id)['inhalt'];
    pruefe($r['ok'] && $r['neu'] === 1 && end($i2['kreise'])['abgang'] === 'X5' && end($i2['kreise'])['nr'] === (string) (count($i['kreise']) + 1),
        'neuer Abgang → eine Zeile mehr, laufende Nummer: ' . $r['message']);
    gleich(BerichtService::kreiseVorbelegen($id, 1, [$vt])['status'], 409, 'Zeilen ergänzen mit altem Stand → 409');
    gleich(BerichtService::kreiseVorbelegen($id, (int) BerichtService::find($id)['version'], [$fremdV])['status'], 403, 'Verteiler eines anderen Standorts → abgelehnt');
    pruefe(!BerichtService::anlegen($siteId, null, [$fremdV])['ok'], 'Anlegen mit fremdem Verteiler abgelehnt');
    pruefe(!BerichtService::anlegen($siteId, $fremdAufbau, [])['ok'], 'Aufbau eines anderen Standorts abgelehnt');
    pruefe(!BerichtService::anlegen($fremdSite, null, [])['ok'], 'Standort ohne „bearbeiten“ abgelehnt');
    pruefe(!BerichtService::anlegen(null, null, [$vt])['ok'], 'ohne Standort keine Anlagen');
    $leer = BerichtService::anlegen(null, null, []);
    pruefe($leer['ok'], 'leerer Bericht ohne Standort');
    $leerId = (int) $leer['id'];
    gleich([BerichtService::find($leerId)['site_id'], BerichtService::find($leerId)['inhalt']['kreise']], [null, []], 'ohne Standort, ohne Kreise');
    alsBenutzer($benutzer['planer']);
    pruefe(!BerichtService::anlegen($siteId, null, [$vt])['ok'], 'Planer ohne „bearbeiten“ legt keinen Bericht an');
    alsBenutzer($benutzer['kunde']);
    pruefe(!BerichtService::anlegen($siteId, null, [])['ok'], 'Kunde ohne „ausfüllen“ legt keinen Bericht an');

    // ================================================== Speichern
    alsBenutzer($benutzer['technik']);
    $b = BerichtService::find($id);
    $v = (int) $b['version'];
    $kundeName = 'Musterfilm ' . $kennung . ' GmbH';
    $kundeMail = $kennung . '@example.org';
    $roh = $b['inhalt'];
    $roh['kopf']['kunde']['firma'] = $kundeName;
    $roh['kopf']['kunde']['email'] = $kundeMail;
    $roh['kopf']['qualifikation'] = 'selbst ernannt';
    $roh['boese'] = 'weg damit';
    $roh['grenzwerte']['k']['C'] = '1';
    $erste = $roh['kreise'][0];
    $roh['kreise'][0]['objekt_id'] = (string) $fremdV;
    $roh['kreise'][0]['anschluss_id'] = '999999';
    $roh['kreise'][0]['unbekannt'] = 'x';
    foreach ($roh['kreise'] as $n => $k) {
        if ($k['z'] === $x1['z']) {
            $roh['kreise'][$n]['leitung']['querschnitt'] = 4.0;
            $roh['kreise'][$n]['w']['zs'] = ['op' => '', 'w' => 0.42];
            $roh['kreise'][$n]['leitung']['leiter'] = '4';
            $roh['kreise'][$n]['rcd_von'] = 'kgibtsnicht';
        }
    }
    $roh['kreise'][] = ['z' => 'X!', 'nr' => '99', 'objekt_id' => (string) $vt, 'ort' => 'Neue Zeile ' . $kennung];
    $r = BerichtService::speichern($id, $v, json_encode($roh, JSON_UNESCAPED_UNICODE));
    pruefe($r['ok'] && $r['version'] === $v + 1, 'gespeichert, Version + 1: ' . $r['message']);
    pruefe(isset($r['bewertung']['kreise'][$x1['z']], $r['abschnitte']['messung']), 'Antwort mit Bewertung und Abschnitten');
    $b = BerichtService::find($id);
    $s = $b['inhalt'];
    pruefe(!array_key_exists('boese', $s) && !array_key_exists('unbekannt', $s['kreise'][0]), 'unbekannte Schlüssel verschwinden');
    gleich([$s['kreise'][0]['objekt_id'], $s['kreise'][0]['anschluss_id']], [$erste['objekt_id'], $erste['anschluss_id']], 'IDs aus dem gespeicherten Stand, nicht aus dem Browser');
    gleich($s['kopf']['qualifikation'], 'Elektrofachkraft für Veranstaltungstechnik', 'Qualifikation nicht vom Browser');
    gleich($s['grenzwerte']['k']['C'], '10', 'Grenzwerte nicht vom Browser');
    $sx1 = $kreisNach($s, 'z', $x1['z']);
    gleich([$sx1['leitung']['querschnitt'], $sx1['w']['zs']['w'], $sx1['status']], ['4', '0.42', 'gemessen'], 'Gleitkomma aus dem Browser als Dezimaltext');
    gleich([$sx1['abgeleitet'], $sx1['rcd_von']], [[], ''], 'geänderter Wert verliert „aus Plan“, unbekannter Bezug fällt weg');
    $neueZeile = end($s['kreise']);
    pruefe(preg_match(BerichtSchema::ZEILE, $neueZeile['z']) === 1 && $neueZeile['objekt_id'] === '' && in_array('Zeile ' . count($s['kreise']) . ' hat einen neuen Zeilenschlüssel bekommen.', $r['hinweise'], true),
        'Zeile aus dem Browser: neuer Schlüssel, keine IDs');
    $geworfen = false;
    try {
        Kanon::json($s);
    } catch (Throwable) {
        $geworfen = true;
    }
    pruefe(!$geworfen, 'gespeicherter Inhalt ohne Gleitkomma (Kanon wirft nicht)');
    gleich([$b['kunde_name'], $b['standort_name'], $b['veranstaltung']], [$kundeName, 'Prüfung Bericht ' . $kennung, $kennung . ' Sommerfest'], 'Spalten der Liste');
    $vorher = Database::scalar('SELECT inhalt FROM berichte WHERE id = ?', [$id]);
    $anders = $roh;
    $anders['kopf']['ort'] = 'Anderswo ' . $kennung;
    $r = BerichtService::speichern($id, $v, json_encode($anders));
    pruefe(!$r['ok'] && $r['status'] === 409 && $r['konflikt'] === true && $r['version'] === $v + 1 && $r['geaendert_von'] === $kennung . '-technik', 'veraltete Version → 409 mit Stand');
    gleich(Database::scalar('SELECT inhalt FROM berichte WHERE id = ?', [$id]), $vorher, '409 ändert nichts');
    // Die Antwort auf ein Speichern ging verloren: derselbe Inhalt mit dem alten Stand ist kein Konflikt.
    $r = BerichtService::speichern($id, $v, json_encode($s, JSON_UNESCAPED_UNICODE));
    pruefe($r['ok'] && $r['status'] === 200 && ($r['wiederholt'] ?? false) === true && $r['version'] === $v + 1 && isset($r['bewertung'])
        && Database::scalar('SELECT inhalt FROM berichte WHERE id = ?', [$id]) === $vorher && (int) BerichtService::find($id)['version'] === $v + 1,
        'gleicher Inhalt mit altem Stand vom selben Benutzer → 200 mit dem aktuellen Stand, nichts doppelt');
    alsBenutzer($benutzer['admin']);
    gleich(BerichtService::speichern($id, $v, json_encode($s, JSON_UNESCAPED_UNICODE))['status'], 409, 'gleicher Inhalt mit altem Stand von einem anderen Benutzer → 409');
    alsBenutzer($benutzer['technik']);
    gleich(BerichtService::speichern($id, $v + 1, str_repeat(' ', BerichtSchema::MAX_BYTES + 1))['status'], 422, 'über 512 KB → 422');
    gleich(BerichtService::speichern($id, $v + 1, '{kaputt')['status'], 422, 'unlesbares JSON → 422');
    // abgeleitet bleibt, wenn nichts angefasst wird
    $x3 = $kreisNach($s, 'abgang', 'X3');
    gleich($x3['abgeleitet'] ?? null, ['leitung.leiter'], '„aus Plan“ bleibt ohne Änderung');

    // 80 Zeilen kommen vollständig an
    $viele = BerichtSchema::leer();
    for ($n = 1; $n <= 80; $n++) {
        $k = BerichtSchema::kreisLeer();
        $k['nr'] = (string) $n;
        $k['ort'] = 'Kreis ' . $n . ' ' . $kennung;
        $k['w']['zs'] = ['op' => '', 'w' => '0,' . str_pad((string) $n, 2, '0', STR_PAD_LEFT)];
        $viele['kreise'][] = $k;
    }
    $r = $speichern($leerId, $viele);
    $gespeichert = BerichtService::find($leerId)['inhalt']['kreise'];
    pruefe($r['ok'] && count($gespeichert) === 80 && $gespeichert[79]['w']['zs']['w'] === '0.80' && count($r['bewertung']['kreise']) === 80, '80 Zeilen vollständig gespeichert');
    $sehrViele = $viele;
    $sehrViele['kreise'] = array_merge($viele['kreise'], array_map(static function (array $k): array {
        $k['z'] = BerichtSchema::neueZeile();
        return $k;
    }, array_fill(0, 230, BerichtSchema::kreisLeer())));
    $r = BerichtSchema::bereinigen($sehrViele, []);
    pruefe(count($r['inhalt']['kreise']) === BerichtSchema::MAX_KREISE && $r['hinweise'] !== [], 'höchstens 300 Kreise, mit Hinweis');

    // Bereinigung im Einzelnen
    $r = BerichtSchema::bereinigen(['kopf' => ['pruefdatum' => '31.02.2026', 'kunde' => ['firma' => "A\u{202E}B\nC", 'quelle' => 'hacker']],
        'netz' => ['spannung' => '230 V', 'form' => 'tn-s'], 'grundlage' => ['normen' => ['0100-600', 'xyz', '0100-600']],
        'punkte' => ['besichtigung' => ['auswahl' => 'io', 'maengelfrei' => 'kaputt', 'gibtsnicht' => 'io']], 'ergebnis' => ['errichter' => true]],
        ['kopf' => ['pruefdatum' => '2026-05-01']]);
    $ri = $r['inhalt'];
    gleich([$ri['kopf']['pruefdatum'], $ri['kopf']['kunde']['firma'], $ri['kopf']['kunde']['quelle']], ['2026-05-01', 'AB C', 'freitext'],
        'Datum geprüft, Steuer- und Richtungszeichen weg, Auswahl begrenzt');
    gleich([$ri['netz']['spannung'], $ri['netz']['form'], $ri['grundlage']['normen'], $ri['punkte']['besichtigung'], $ri['ergebnis']['errichter']],
        ['230', 'TN-S', ['0100-600'], ['auswahl' => 'io'], '1'], 'Zahl ohne Einheit, Netzform, Normen, Prüfpunkte, Wahrheitswert');

    // Prüfgeräte: nur aktive
    alsBenutzer($benutzer['admin']);
    pruefe(!Pruefgeraete::speichern(null, ['fabrikat' => '', 'typ' => 'x'])['ok'], 'Prüfgerät ohne Fabrikat abgelehnt');
    pruefe(!Pruefgeraete::speichern(null, ['fabrikat' => $kennung, 'typ' => 'x', 'kalibriert_bis' => '2026-13-01'])['ok'], 'Prüfgerät mit unmöglichem Datum abgelehnt');
    $aktiv = (int) Pruefgeraete::speichern(null, ['fabrikat' => $kennung . ' Fluke', 'typ' => '1664 FC', 'seriennummer' => 'DEMO-1', 'kalibriert_bis' => $morgen, 'aktiv' => 1])['id'];
    $alt = (int) Pruefgeraete::speichern(null, ['fabrikat' => $kennung . ' Alt', 'typ' => 'X', 'kalibriert_bis' => $gestern, 'aktiv' => 1])['id'];
    $still = (int) Pruefgeraete::speichern(null, ['fabrikat' => $kennung . ' Still', 'typ' => 'Y', 'aktiv' => 0])['id'];
    gleich(Pruefgeraete::find($aktiv)['norm'], Pruefgeraete::NORM, 'Norm vorbelegt');
    pruefe(Pruefgeraete::abgelaufen(Pruefgeraete::find($alt), $heute) && !Pruefgeraete::abgelaufen(Pruefgeraete::find($aktiv), $heute), 'Kalibrierung abgelaufen erkannt');
    alsBenutzer($benutzer['technik']);
    $roh = BerichtService::find($id)['inhalt'];
    $roh['messung']['geraete'] = [(string) $aktiv, (string) $still, 'abc'];
    $r = $speichern($id, $roh);
    pruefe($r['ok'] && BerichtService::find($id)['inhalt']['messung']['geraete'] === [(string) $aktiv] && $r['hinweise'] !== [], 'inaktives Prüfgerät verworfen, mit Hinweis');

    // ================================================== Abschluss-Prüfung
    $felder = static fn (array $erg): array => array_column($erg['fehler'], 'feld');
    $erg = BerichtService::abschlussPruefen(BerichtService::find($leerId));
    foreach (['kopf.kunde.firma', 'firma', 'netz.form', 'grundlage.grund', 'ergebnis.entspricht', 'kopf.anlage.aufstellort'] as $feld) {
        pruefe(in_array($feld, $felder($erg), true), 'Abschluss-Prüfung meldet ' . $feld);
    }
    pruefe(!in_array('kopf.qualifikation', $felder($erg), true), 'Prüfer mit Qualifikation');
    pruefe(in_array('messung.geraete', $felder($erg), true), 'Messwerte ohne Gerät → Fehler');
    alsBenutzer($benutzer['planer']);
    pruefe(in_array('kopf.qualifikation', $felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), true), 'Prüfer ohne Qualifikation → Fehler');
    alsBenutzer($benutzer['technik']);
    Settings::put('firma.name', 'Muster Eventstrom ' . $kennung);

    $fertig = BerichtSchema::leer();
    $fertig['kopf']['kunde']['firma'] = $kundeName;
    $fertig['kopf']['ort'] = 'Musterstadt';
    $fertig['kopf']['anlage'] = ['bezeichnung' => 'Aggregat', 'details' => '', 'aufstellort' => 'Wiese'];
    $fertig['netz']['form'] = 'TN-S';
    $fertig['grundlage'] = ['normen' => ['0100-600'], 'sonstige' => '', 'grund' => 'aufbau'];
    $fertig['messung']['geraete'] = [(string) $aktiv];
    $gut = $muster();
    $gut['z'] = 'kgut0001';
    $schlecht = $muster(['art' => 'verteiler', 'rcd' => ['idn' => ''], 'w' => ['zs' => ['w' => '3.0']]]);
    $schlecht['z'] = 'kschlech';
    $schlecht['nr'] = '2';
    $fertig['kreise'] = [$gut, $schlecht];
    $fertig['ergebnis']['entspricht'] = 'ja';
    $speichern($leerId, $fertig);
    $erg = BerichtService::abschlussPruefen(BerichtService::find($leerId));
    gleich($felder($erg), ['ergebnis.entspricht', 'punkte'], '„ja“ bei einer Mangel-Zeile und offener Kurzliste → Fehler');
    $fertig['punkte'] = $kurzlisteIo();
    $fertig['punkte']['besichtigung']['zus_potentialausgleich'] = 'entf';
    // Ein als behoben eingetragener Mangel an einem anderen Kreis entschuldigt den Mangel an Kreis 2 nicht.
    $fertig['maengel'] = [['text' => 'Kabelbrücke fehlte', 'kreis' => 'kgut0001', 'frist' => '', 'behoben' => $heute]];
    $speichern($leerId, $fertig);
    $erg = BerichtService::abschlussPruefen(BerichtService::find($leerId));
    gleich($felder($erg), ['ergebnis.entspricht'], '„ja“ bei einer Mangel-Zeile → Fehler, auch wenn ein anderer Eintrag behoben ist (Kurzliste mit „entf.“ beantwortet)');
    $fertig['maengel'] = [];
    $fertig['ergebnis']['maengelfrei'] = 'ja';
    $speichern($leerId, $fertig);
    pruefe(in_array('ergebnis.maengelfrei', $felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), true), '„Mängelfrei: Ja“ mit Mangel-Zeile → Fehler');
    $fertig['ergebnis']['maengelfrei'] = '';
    unset($fertig['punkte']['erprobung']['funktion_anlage']);
    $fertig['ergebnis']['entspricht'] = 'nein';
    $fertig['ergebnis']['instand_bis'] = $morgen;
    $speichern($leerId, $fertig);
    $erg = BerichtService::abschlussPruefen(BerichtService::find($leerId));
    pruefe(!in_array('punkte', $felder($erg), true) && in_array('punkte_offen', array_column($erg['warnungen'], 'code'), true), '„nein“ mit offener Kurzliste → nur Warnung');
    $fertig['punkte'] = $kurzlisteIo();
    $fertig['ergebnis']['instand_bis'] = '';
    $fertig['ergebnis']['entspricht'] = 'ja';
    $speichern($leerId, $fertig);
    $fertig['kreise'][1]['pruefer'] = 'io';
    $speichern($leerId, $fertig);
    gleich($felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), ['kreise.kschlech.begruendung'], 'Übersteuerung ohne Begründung → Fehler');
    $fertig['kreise'][1]['begruendung'] = 'Zs am Kabelende gemessen, Kreis durch Vorsicherung geschützt';
    $speichern($leerId, $fertig);
    gleich($felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), [], 'mit Begründung keine Fehler mehr');
    // Erdung ohne berechenbaren Grenzwert (TT, kein RCD im Bericht) blockiert die Unterschrift.
    $tt = $fertig;
    $tt['netz']['form'] = 'TT';
    $tt['kreise'] = [$schlecht];
    $tt['kreise'][0]['pruefer'] = 'auto';
    $tt['messung']['erdung'] = ['op' => '', 'w' => '120', 'methode' => '', 'basis' => '', 'grenze' => ''];
    $speichern($leerId, $tt);
    pruefe(in_array('messung.erdung', $felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), true), 'Erdung „selbst bewerten“ (TT ohne RCD) → Fehler');
    // Prüfdatum: unplausibel → Fehler, alt oder in der Zukunft → Warnung
    foreach (['9995-01-01' => 'fehler', '1999-12-31' => 'fehler', (new DateTimeImmutable('-40 days', Zeit::zone()))->format('Y-m-d') => 'pruefdatum_alt',
        $morgen => 'pruefdatum_zukunft'] as $datum => $soll) {
        $mitDatum = $fertig;
        $mitDatum['kopf']['pruefdatum'] = $datum;
        $speichern($leerId, $mitDatum);
        $erg = BerichtService::abschlussPruefen(BerichtService::find($leerId));
        pruefe($soll === 'fehler' ? in_array('kopf.pruefdatum', $felder($erg), true) : in_array($soll, array_column($erg['warnungen'], 'code'), true),
            'Prüfdatum ' . $datum . ' → ' . $soll);
    }
    $speichern($leerId, $fertig);
    $fertig['kreise'][1]['pruefer'] = 'auto';
    $fertig['ergebnis']['entspricht'] = 'nein';
    $speichern($leerId, $fertig);
    gleich($felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), ['ergebnis.instand_bis'], '„nein“ ohne Frist und ohne Mangel → Fehler');
    $fertig['ergebnis']['instand_bis'] = $morgen;
    $fertig['ergebnis']['errichter'] = '1';
    $speichern($leerId, $fertig);
    gleich($felder(BerichtService::abschlussPruefen(BerichtService::find($leerId))), ['ergebnis.errichter'], 'Errichterbestätigung bei „nein“ → Fehler');
    $fertig['ergebnis']['errichter'] = '0';
    $fertig['messung']['geraete'] = [(string) $alt];
    $speichern($leerId, $fertig);
    $erg = BerichtService::abschlussPruefen(BerichtService::find($leerId));
    $kal = array_values(array_filter($erg['warnungen'], static fn ($w) => $w['code'] === 'kalibrierung:' . $alt))[0] ?? null;
    pruefe($erg['fehler'] === [] && $kal !== null && $kal['bestaetigt'] === false, 'Warnung „Kalibrierung abgelaufen“, noch nicht bestätigt');
    $fertig['abschluss']['warnungen_bestaetigt'] = ['kalibrierung:' . $alt];
    $speichern($leerId, $fertig);
    $kal = array_values(array_filter(BerichtService::abschlussPruefen(BerichtService::find($leerId))['warnungen'], static fn ($w) => $w['code'] === 'kalibrierung:' . $alt))[0] ?? null;
    pruefe($kal !== null && $kal['bestaetigt'] === true, 'Warnung bestätigt');
    Settings::put('firma.name', '');

    // ================================================== Kunde aus früherem Bericht, Eventworx-Verweis
    $quelle = (int) BerichtService::anlegen($siteId, null, [])['id'];
    $qi = BerichtService::find($quelle)['inhalt'];
    $qi['kopf']['kunde']['firma'] = 'Vorkunde ' . $kennung;
    $qi['kopf']['projektleiter_kunde'] = 'Erika Musterfrau';
    $speichern($quelle, $qi);
    pruefe(!BerichtService::kundeAusBericht($id, $quelle)['ok'], 'Entwurf taugt nicht als Quelle');
    $abschliessen($quelle, $kennung . '-Q');
    Database::update('berichte', $quelle, ['ewx_host' => 'firma.eventworx.biz', 'ewx_id' => '64f0c0de00000000000a1042', 'ewx_art' => 'order', 'ewx_nummer' => 'AU-1042', 'ewx_am' => gmdate('c')]);
    $_SESSION = [];
    $r = BerichtService::kundeAusBericht($id, $quelle);
    pruefe($r['ok'] && $r['kunde']['firma'] === 'Vorkunde ' . $kennung && $r['kunde']['quelle'] === 'bericht' && $r['projektleiter_kunde'] === 'Erika Musterfrau'
        && ($r['eventworx']['nummer'] ?? '') === 'AU-1042', 'Kundendaten aus früherem Bericht');
    gleich(array_keys(BerichtService::eventworxGeladen($id)), ['64f0c0de00000000000a1042'], 'Eventworx-Verweis in der Sitzung vorgemerkt');
    pruefe(!array_key_exists('firma', BerichtService::eventworxGeladen($id)['64f0c0de00000000000a1042']), 'Sitzung ohne Kundendaten');
    $r = $speichern($id, BerichtService::find($id)['inhalt'], '64f0c0de00000000000a1042');
    $b = BerichtService::find($id);
    pruefe($r['ok'] && $b['ewx_nummer'] === 'AU-1042' && $b['ewx_host'] === 'firma.eventworx.biz', 'Autosave übernimmt den vorgemerkten Verweis');
    pruefe(in_array('eventworx_uebernommen', array_column(BerichtService::ereignisse($id), 'ereignis'), true), 'Ereignis „eventworx_uebernommen“');
    $r = $speichern($id, $b['inhalt'], 'AAAAAAAAAAAAAAAAAAAA');
    pruefe($r['ok'] && BerichtService::find($id)['ewx_nummer'] === 'AU-1042' && $r['hinweise'] !== [], 'nicht geladener Vorgang wird nicht übernommen');
    $speichern($id, $b['inhalt'], '-');
    gleich(BerichtService::find($id)['ewx_id'], '', '„-“ entfernt den Verweis');
    $fremdBericht = (int) BerichtService::anlegen(null, null, [])['id'];
    $abschliessen($fremdBericht, $kennung . '-F');
    pruefe(!BerichtService::kundeAusBericht($id, $fremdBericht)['ok'], 'Quelle von einem anderen Standort abgelehnt');
    pruefe(count(BerichtService::kundenQuellen(BerichtService::find($id))) === 1, 'Liste der Quellen am Standort');

    // ================================================== zuletzt: Querschnitt aus dem letzten Bericht
    $roh = BerichtService::find($id)['inhalt'];
    $abschliessen($id, $kennung . '-1');
    $vt2 = $verteiler($kennung . ' Catering', $siteId, $floorId);
    pruefe(InventarService::einsetzen($stueck, $vt2, null, false)['ok'], 'dasselbe Inventarstück in einen anderen Verteiler');
    gleich(Vorbelegung::zuletzt($stueck, 0, 'X1'), ['leiter' => '4', 'querschnitt' => '4'], 'zuletzt: über Inventarstück und Abgang');
    $zweit = BerichtService::anlegen($siteId, null, [$vt2]);
    $zx1 = $kreisNach(BerichtService::find((int) $zweit['id'])['inhalt'], 'abgang', 'X1');
    gleich([$zx1['leitung']['querschnitt'] ?? null, $zx1['leitung']['leiter'] ?? null, $zx1['abgeleitet'] ?? null], ['4', '4', []], 'neuer Bericht: X1 hat Querschnitt 4 aus Bericht 1');
    gleich(Vorbelegung::netzVorschlag([$vt]), null, 'ohne Netzform im alten Bericht kein Vorschlag');
    $mitNetz = BerichtService::find($id)['inhalt'];
    $mitNetz['netz']['form'] = 'TN-S';
    Database::update('berichte', $id, ['inhalt' => Kanon::json($mitNetz)]);
    gleich(Vorbelegung::netzVorschlag([$vt])['form'] ?? null, 'TN-S', 'Netzform des letzten Berichts als Vorschlag');
    gleich(BerichtService::letzteAnObjekt($vt)['id'] ?? null, $id, 'letzte Prüfung an der Anlage');
    gleich(BerichtService::letzteAnInventar($stueck)['id'] ?? null, $id, 'letzte Prüfung am Inventarstück');

    // ================================================== eingefroren: 423
    gleich(BerichtService::speichern($id, (int) BerichtService::find($id)['version'], json_encode($roh))['status'], 423, 'abgeschlossener Bericht: speichern → 423');
    gleich(BerichtService::kreiseVorbelegen($id, (int) BerichtService::find($id)['version'], [$vt])['status'], 423, 'abgeschlossener Bericht: Zeilen ergänzen → 423');
    pruefe(!BerichtService::darfBearbeiten(BerichtService::find($id)), 'abgeschlossener Bericht: nicht bearbeitbar');

    // ================================================== Sichtbarkeit
    $draussen = (int) BerichtService::anlegen($siteId, null, [])['id'];
    $abschliessen($draussen, $kennung . '-2020', '2020-06-15');
    $entwurf = (int) $zweit['id'];
    alsBenutzer($benutzer['kunde']);
    pruefe(BerichtService::sichtbar(BerichtService::find($id)), 'Kunde mit Freigabe und Datenfenster sieht den abgeschlossenen Bericht');
    pruefe(!BerichtService::sichtbar(BerichtService::find($entwurf)), 'Kunde sieht keinen Entwurf');
    pruefe(!BerichtService::sichtbar(BerichtService::find($draussen)), 'Kunde sieht keinen Bericht außerhalb seines Datenfensters');
    $liste = array_column(BerichtService::liste(['site_id' => $siteId]), 'id');
    pruefe(in_array($id, $liste, true) && !in_array($entwurf, $liste, true) && !in_array($draussen, $liste, true), 'Liste des Kunden gefiltert');
    pruefe(!BerichtService::darfBearbeiten(BerichtService::find($entwurf)), 'Kunde bearbeitet nicht');
    pruefe(!BerichtService::sichtbar(BerichtService::find($leerId)), 'Kunde sieht keinen Bericht ohne Standort');
    alsBenutzer($benutzer['kunde2']);
    pruefe(!BerichtService::sichtbar(BerichtService::find($id)), 'Freigabe nur für eine Anlage → kein Bericht');
    alsBenutzer($benutzer['planer']);
    pruefe(BerichtService::sichtbar(BerichtService::find($id)) && BerichtService::sichtbar(BerichtService::find($draussen)), 'Planer mit Standort-Freigabe ohne Datenfenster sieht abgeschlossene');
    pruefe(!BerichtService::sichtbar(BerichtService::find($entwurf)) && !BerichtService::darfBearbeiten(BerichtService::find($entwurf)), 'Planer ohne „bearbeiten“: Entwurf weder sichtbar noch bearbeitbar');
    pruefe(!BerichtService::sichtbar(BerichtService::find($leerId)), 'Bericht ohne Standort: anderer Benutzer sieht ihn nicht');
    gleich(BerichtService::speichern($leerId, 1, '{}')['status'], 404, 'fremder Bericht ohne Standort: speichern → 404');
    alsBenutzer($benutzer['admin']);
    pruefe(BerichtService::sichtbar(BerichtService::find($leerId)) && BerichtService::darfBearbeiten(BerichtService::find($leerId)), 'alle Standorte: sieht und bearbeitet den Bericht ohne Standort');
    alsBenutzer($benutzer['technik']);
    pruefe(BerichtService::sichtbar(BerichtService::find($leerId)) && BerichtService::darfBearbeiten(BerichtService::find($leerId)), 'Ersteller sieht seinen Bericht ohne Standort');
    pruefe(BerichtService::sichtbar(BerichtService::find($entwurf)) && BerichtService::darfBearbeiten(BerichtService::find($entwurf)), 'Technik mit „bearbeiten“ sieht und bearbeitet Entwürfe');
    pruefe(BerichtService::darfPruefen(BerichtService::find($entwurf)), 'Technik mit Qualifikation darf prüfen');
    Database::update('users', $benutzer['technik'], ['pruefer_qualifikation' => '']);
    alsBenutzer($benutzer['technik']);
    pruefe(!BerichtService::darfPruefen(BerichtService::find($entwurf)), 'ohne Qualifikation kein Prüfen');
    Database::update('users', $benutzer['technik'], ['pruefer_qualifikation' => 'Elektrofachkraft für Veranstaltungstechnik']);
    alsBenutzer($benutzer['technik']);
    pruefe(Zugriff::imFenster(Zugriff::datenFensterStandort($siteId), 0, 1), 'datenFensterStandort: Technik ohne Datengrenze');

    // ================================================== Löschen und Verwerfen
    $weg = (int) BerichtService::anlegen($siteId, null, [$vt2])['id'];
    $r = BerichtService::loeschen($weg);
    pruefe($r['ok'] && BerichtService::find($weg) === null && (int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ?', [$weg]) === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ?', [$weg]) === 0, 'Entwurf ohne Nummer gelöscht – samt Objekten und Ereignissen');
    pruefe(!BerichtService::loeschen($id)['ok'], 'abgeschlossener Bericht wird nicht gelöscht');
    $mitNummer = (int) BerichtService::anlegen($siteId, null, [])['id'];
    Database::update('berichte', $mitNummer, ['nummer' => $kennung . '-N']);
    pruefe(!BerichtService::loeschen($mitNummer)['ok'], 'Entwurf mit Nummer wird nicht gelöscht');
    pruefe(!BerichtService::verwerfen($mitNummer, '')['ok'], 'Verwerfen nur mit Grund');
    $r = BerichtService::verwerfen($mitNummer, 'Prüfung abgebrochen');
    $vb = BerichtService::find($mitNummer);
    pruefe($r['ok'] && $vb['status'] === 'verworfen' && $vb['aufbewahren_bis'] === sprintf('%04d-12-31', (int) substr($heute, 0, 4) + 10), 'verworfen, Aufbewahrung bis 31.12.(Jahr + 10)');
    pruefe(!BerichtService::verwerfen($entwurf, 'x y z')['ok'], 'Entwurf ohne Nummer wird nicht verworfen');

    // ================================================== Aufbewahrung
    Database::update('berichte', $mitNummer, ['aufbewahren_bis' => '10005-12-31']);
    pruefe(!in_array($mitNummer, array_map('intval', array_column(BerichtService::faellig(), 'id')), true), 'Frist „10005-12-31“ (kein JJJJ-MM-TT) ist nicht fällig');
    gleich(BerichtService::aufbewahrenBis('9995-01-01', gmdate('c')) <= '9999-12-31' && strlen(BerichtService::aufbewahrenBis('9995-01-01', gmdate('c'))) === 10, true,
        'aufbewahrenBis bleibt JJJJ-MM-TT');
    Database::update('berichte', $mitNummer, ['aufbewahren_bis' => '2000-12-31']);
    pruefe(in_array($mitNummer, array_map('intval', array_column(BerichtService::faellig(), 'id')), true), 'fällig nach vorgezogener Frist');
    BerichtService::aufbewahrungLoeschen($mitNummer);
    pruefe(BerichtService::find($mitNummer) === null && (int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE bericht_id = ?', [$mitNummer]) === 0,
        'nach der Frist gelöscht – samt Ereignissen');

    // ================================================== Lösch-Abläufe
    alsBenutzer($benutzer['admin']);
    $loeschSite = SiteService::saveSite(null, ['name' => 'Prüfung Löschen ' . $kennung]);
    $sites[] = $loeschSite;
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['technik'], 'ziel_art' => 'site', 'ziel_id' => $loeschSite, 'darf_bearbeiten' => 1]);
    $lv = $verteiler($kennung . ' Lösch', $loeschSite);
    $lStueck = (int) InventarService::speichern(null, ['art' => 'verteilung', 'kennung' => $kennung . '-LD'])['id'];
    $inventar[] = $lStueck;
    InventarService::einsetzen($lStueck, $lv, null, false);
    $la = (int) AufbauService::starten($loeschSite, $kennung . ' Lösch')['id'];
    alsBenutzer($benutzer['technik']);
    $d1 = (int) BerichtService::anlegen(null, $la, [$lv])['id'];
    $d2 = (int) BerichtService::anlegen(null, $la, [$lv])['id'];
    $abschliessen($d2, $kennung . '-D2');
    alsBenutzer($benutzer['admin']);
    AufbauService::loeschen($la);
    gleich([BerichtService::find($d1)['aufbau_id'], BerichtService::find($d2)['aufbau_id']], [null, null], 'Aufbau gelöscht → aufbau_id NULL');
    AnlagenService::loeschen($lv);
    gleich(Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ? AND objekt_id IS NULL', [$d2]), 1, 'Anlage gelöscht → bericht_objekte.objekt_id NULL');
    pruefe(InventarService::loeschen($lStueck)['ok'], 'Inventarstück gelöscht');
    gleich(Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ? AND inventar_id IS NOT NULL', [$d2]), 0, 'Inventar gelöscht → inventar_id NULL');
    pruefe(BerichtService::find($d2)['inhalt']['kreise'] !== [], 'der Inhalt nennt die Anlage weiter');
    SiteService::deleteSite($loeschSite);
    $d2b = BerichtService::find($d2);
    pruefe(BerichtService::find($d1) === null && (int) Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ?', [$d1]) === 0, 'Standort gelöscht → Entwurf ohne Nummer weg');
    gleich([$d2b['site_id'], $d2b['standort_name'], $d2b['status'], $d2b['standort_entfernt']], [null, 'Prüfung Löschen ' . $kennung, 'abgeschlossen', 1],
        'abgeschlossener Bericht bleibt mit Namen des Standorts, als „Standort gelöscht“ vermerkt');
    pruefe(BerichtService::sichtbar($d2b), 'ohne Standort sichtbar mit „alle Standorte“');
    pruefe(in_array('standort_entfernt', array_column(BerichtService::ereignisse($d2), 'ereignis'), true), 'Lebenslauf: Standort entfernt');
    // Der Ersteller (Prüfer, Freigabe für den gelöschten Standort): keine Hintertür über „ohne Standort“.
    alsBenutzer($benutzer['technik']);
    $_SESSION = [];
    pruefe(!BerichtService::sichtbar(BerichtService::find($d2)) && !in_array($d2, array_column(BerichtService::liste(), 'id'), true)
        && !BerichtService::darfPruefen(BerichtService::find($d2)), 'Standort gelöscht: der Ersteller sieht den Bericht nicht mehr und handelt nicht als Prüfer');
    gleich([BerichtService::korrektur($d2, 'Hintertür')['status'], BerichtService::ohneKunde($d2, 'abwesend', 'Hintertür')['status'],
        BerichtService::kundenmodusStarten($d2)['status']], [404, 404, 404], 'Standort gelöscht: Korrektur, ohne Kunde, Kundenmodus → 404');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE vorgaenger_id = ?', [$d2]), 0, 'keine Fassung 2 angelegt');
    pruefe(BerichtService::sichtbar(BerichtService::find($leerId)), 'ein Bericht, der nie einen Standort hatte, bleibt für den Ersteller sichtbar');
    // Ohne Standort gilt Regel 2: der Ersteller (mit „ausfüllen“) und „alle Standorte“.
    alsBenutzer($benutzer['planer']);
    pruefe(!BerichtService::sichtbar(BerichtService::find($d2)), 'ohne Standort für andere nicht mehr sichtbar');
    alsBenutzer($benutzer['kunde']);
    pruefe(!BerichtService::sichtbar(BerichtService::find($d2)), 'ohne Standort für den Kunden nicht mehr sichtbar');

    // ================================================== Einstellungen
    alsBenutzer($benutzer['admin']);
    pruefe(Einstellungen::speichern(['berichte.zs_faktor' => '0,67', 'firma.bank' => 'IBAN ' . $kennung])['ok'], 'Einstellungen gespeichert');
    gleich([Einstellungen::wert('berichte.zs_faktor'), Grenzwerte::parameter()['zs_faktor']], ['0.67', '0.67'], 'Faktor 0,67 in den Parametern');
    pruefe(!Einstellungen::speichern(['berichte.praefix' => 'ub1'])['ok'] && Einstellungen::wert('berichte.praefix') !== 'UB1', 'Kürzel nur aus Großbuchstaben');
    pruefe(!Einstellungen::speichern(['berichte.zs_faktor' => '0.5'])['ok'], 'Faktor nur 1,0, 0,8 oder 0,67');
    Einstellungen::speichern(['berichte.normen' => ['0100-711', 'xyz']]);
    gleich(Einstellungen::normen(), ['0100-711'], 'Normen nur aus dem Katalog');
    Settings::put('berichte.ul', '42');
    gleich(Einstellungen::wert('berichte.ul'), '50', 'ungültiger Wert in der Datenbank ergibt die Vorgabe');
    pruefe((int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE ?', ['%IBAN ' . $kennung . '%']) === 0, 'Bankdaten nicht im Audit');

    // ================================================== Unterschrift: nur Striche aus ganzen Zahlen
    /** Eine gültige Unterschrift – jedes Mal andere Striche (Kopierschutz). */
    $striche = static function (): string {
        $versatz = random_int(0, 400);
        $s = [];
        for ($zug = 0; $zug < 3; $zug++) {
            $linie = [];
            for ($x = 1000; $x <= 8600; $x += 380) {
                $linie[] = [$x + $versatz, 2500 + intdiv($x, 380) % 2 * 4000 + $zug * 300];
            }
            $s[] = $linie;
        }
        return json_encode(['s' => $s]);
    };
    $abgelehnt = static fn (string $json): bool => !Unterschrift::pruefen($json)['ok'];
    pruefe($abgelehnt(''), 'Unterschrift leer → abgelehnt');
    pruefe($abgelehnt('{"s":[]}'), 'keine Striche → abgelehnt');
    pruefe($abgelehnt('{"s":[[[5000,5000]]]}'), 'ein Punkt → abgelehnt');
    pruefe($abgelehnt('{"s":[[[1000,1000],[1600,1300],[1900,1200]]]}'), 'Rahmen zu klein → abgelehnt');
    gleich(Unterschrift::pruefen('{"s":[[[1000,1000],[1600,1300]]]}')['message'], Unterschrift::ZU_KLEIN, 'Meldung „zu klein oder leer“');
    /**
     * Eine gültige, große Unterschrift mit genau $n Punkten: Zickzack über die
     * Fläche, Abstand ≥ 20; $stellen = 4 nimmt vierstellige Koordinaten (mehr Bytes).
     */
    $grosse = static function (int $n, int $stellen = 3): string {
        $linie = [];
        $min = $stellen === 4 ? 1000 : 100;
        $max = $stellen === 4 ? 9999 : 999;
        for ($i = 0; $i < $n; $i++) {
            $x = $min + ($i * 20) % ($max - $min);
            $linie[] = [$x, $i % 2 === 0 ? $min : $max];
        }
        $linie[0] = [9999, 9999];   // Rahmen über die ganze Fläche
        return json_encode(['s' => array_chunk($linie, 150)]);
    };
    $ok6000 = $grosse(Unterschrift::MAX_PUNKTE);
    pruefe(Unterschrift::pruefen($ok6000)['ok'] && strlen($ok6000) < Unterschrift::MAX_BYTES, 'Gegenprobe: gültige Unterschrift mit 6000 Punkten unter 64 KB → angenommen');
    $zuViele = $grosse(Unterschrift::MAX_PUNKTE + 1);
    pruefe(strlen($zuViele) < Unterschrift::MAX_BYTES && Unterschrift::grossGenug(Unterschrift::pruefen($ok6000)['striche'] ?? [])
        && Unterschrift::pruefen($zuViele)['message'] === 'Zu viele Punkte – bitte löschen und neu unterschreiben.', 'gültige Unterschrift mit 6001 Punkten (unter 64 KB) → „Zu viele Punkte“');
    $zuGross = $grosse(Unterschrift::MAX_PUNKTE - 200, 4);
    pruefe(strlen($zuGross) > Unterschrift::MAX_BYTES && Unterschrift::pruefen($zuGross)['message'] === 'Die Unterschrift ist zu groß – bitte löschen und neu unterschreiben.',
        'gültige Unterschrift mit weniger als 6000 Punkten, aber über 64 KB → „zu groß“');
    pruefe($abgelehnt('{"s":[[[1000.5,1000],[9000,9000]]]}'), 'Gleitkommazahl → abgelehnt');
    pruefe($abgelehnt('{"s":[[[1000,1000],[9000,9000]]],"t":[1,2]}'), 'Schlüssel „t“ → abgelehnt');
    pruefe($abgelehnt('{"s":[[[1000,1000,1],[9000,9000,1]]]}'), 'Druck als dritter Wert → abgelehnt');
    pruefe($abgelehnt('{"s":[[{"x":1000,"y":1000,"p":0.5}]]}'), 'Punkt als Objekt mit „p“ → abgelehnt');
    pruefe($abgelehnt('{"s":[[["1000",1000],[9000,9000]]]}'), 'String im Punkt → abgelehnt');
    pruefe($abgelehnt('{"s":[[[1000,1000],[99999999999999999999,9000]]]}'), 'riesige Zahl → abgelehnt');
    pruefe(str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/js/unterschrift.js'), 'MAX_BYTES = ' . Unterschrift::MAX_BYTES),
        'unterschrift.js begrenzt die Aufnahme auch an der Bytegrenze des Servers');
    $gross = Unterschrift::pruefen('{"s":[[[500,1000],[12000,6000],[3000,-200],[9000,9000]]]}');
    pruefe($gross['ok'] && $gross['striche'][0][1] === [10000, 6000] && $gross['striche'][0][2] === [3000, 0], 'gültige Unterschrift; Werte auf 0..10000 begrenzt');
    $svg = Unterschrift::svg([[['<script>', '1e9'], [5000, 'onload=x'], [-5, 20000]], 'kaputt', [['"/><a', 7]]]);
    pruefe(preg_match('/^<svg [^<>]*><path d="[MQLl0-9 ]*" [^<>]*\/><\/svg>$/', $svg) === 1 && !str_contains($svg, 'script') && !str_contains($svg, 'onload'),
        'SVG aus manipulierten Strichen: nur Zahlen im Pfad');

    // ================================================== Kundenmodus: offene Wege, Kennwort
    pruefe(Kundenmodus::erlaubt('GET', '/berichte/41/kunde', 41) && Kundenmodus::erlaubt('POST', '/berichte/41/kunde/unterschreiben', 41)
        && Kundenmodus::erlaubt('POST', '/berichte/41/kunde/beenden', 41) && Kundenmodus::erlaubt('GET', '/berichte/41/pdf', 41),
        'Kundenmodus: Kundenseite, PDF, Unterschrift, Entsperren offen');
    pruefe(!Kundenmodus::erlaubt('POST', '/logout', 41) && !Kundenmodus::erlaubt('GET', '/login', 41) && !Kundenmodus::erlaubt('POST', '/login', 41),
        'Kundenmodus: Abmelden und Anmeldeseite zu (sonst entsperrte ein gespeichertes Kennwort das Gerät)');
    pruefe(!Kundenmodus::erlaubt('GET', '/berichte/42/kunde', 41) && !Kundenmodus::erlaubt('GET', '/berichte/41', 41) && !Kundenmodus::erlaubt('GET', '/dashboard', 41)
        && !Kundenmodus::erlaubt('POST', '/steuerung/schalten', 41) && !Kundenmodus::erlaubt('POST', '/berichte/41/speichern', 41)
        && !Kundenmodus::erlaubt('GET', '/berichte/41/kunde/beenden', 41) && !Kundenmodus::erlaubt('POST', '/berichte/041/kunde/beenden', 41),
        'Kundenmodus: anderer Bericht, Formular, Dashboard und Schalten zu');
    alsBenutzer($benutzer['technik']);
    $fehlVorher = (int) Database::scalar('SELECT failed_logins FROM users WHERE id = ?', [$benutzer['technik']]);
    pruefe(Auth::kennwortBestaetigen($kennwort) && !Auth::kennwortBestaetigen('falsch') && !Auth::kennwortBestaetigen(''), 'Kennwort bestätigen: richtig ja, falsch und leer nein');
    pruefe((int) Database::scalar('SELECT failed_logins FROM users WHERE id = ?', [$benutzer['technik']]) === $fehlVorher
        && Database::scalar('SELECT locked_until FROM users WHERE id = ?', [$benutzer['technik']]) === null, 'Fehlversuch zählt nicht gegen das Konto');
    pruefe((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.kundenmodus_fehlversuch' AND target_id = ?", [(string) $benutzer['technik']]) === 2,
        'Fehlversuche stehen im Audit');

    // ================================================== Einfrieren und Nummer
    Settings::put('firma.name', 'Muster Eventstrom ' . $kennung);
    $praefix = 'T' . implode('', array_map(static fn (): string => chr(random_int(65, 90)), range(1, 5)));
    Settings::put('berichte.praefix', $praefix);
    // Ein Jahr ohne Berichte (und ohne Nummernzähler): dann beginnt die laufende Nummer bei 1.
    $jahrU = $freiesJahr(2001);
    $testJahre[] = $jahrU;
    $bereit = BerichtSchema::leer();
    $bereit['kopf']['kunde'] = ['firma' => $kundeName, 'email' => $kundeMail] + $bereit['kopf']['kunde'];
    $bereit['kopf']['pruefdatum'] = $jahrU . '-06-15';
    $bereit['kopf']['ort'] = 'Musterstadt';
    $bereit['kopf']['anlage'] = ['bezeichnung' => 'Verteiler Bühne', 'details' => '', 'aufstellort' => 'Wiese'];
    $bereit['netz']['form'] = 'TN-S';
    $bereit['grundlage'] = ['normen' => ['0100-600', '0100-711'], 'sonstige' => '', 'grund' => 'aufbau'];
    $bereit['messung']['geraete'] = [(string) $aktiv];
    $kreisU = $muster();
    $kreisU['z'] = 'kunter01';
    $kreisU['leitung']['querschnitt'] = '';   // ergibt die Warnung „ohne Querschnitt“
    $bereit['kreise'] = [$kreisU];
    $bereit['punkte'] = $kurzlisteIo();
    $bereit['ergebnis']['entspricht'] = 'ja';
    $bereitAnlegen = static function () use ($siteId, $vt, $bereit, $speichern): int {
        $id = (int) BerichtService::anlegen($siteId, null, [$vt])['id'];
        pruefe($speichern($id, $bereit)['ok'], 'Bericht zum Unterschreiben gespeichert');
        return $id;
    };
    $anfrage = static fn (): string => bin2hex(random_bytes(16));
    /** Der Stand, den die Kundenseite anzeigt – geht mit der Kundenunterschrift mit. */
    $mitHash = static fn (int $id): array => ['inhalt_hash' => (string) BerichtService::find($id)['inhalt_hash']];
    $version = static fn (int $id): int => (int) BerichtService::find($id)['version'];
    $zeilen = static fn (int $id): int => (int) Database::scalar('SELECT COUNT(*) FROM bericht_unterschriften WHERE bericht_id = ?', [$id]);

    $u1 = $bereitAnlegen();
    $prueferDaten = ['striche' => $striche(), 'name' => 'Tom Technik', 'ort' => 'Musterstadt', 'anfrage' => $anfrage(), 'geraet_am' => '2026-10-16T09:58:12.123Z'];
    $r = BerichtService::prueferUnterschreiben($u1, $version($u1), $prueferDaten);
    pruefe(!$r['ok'] && $r['status'] === 422 && ($r['fehler'] ?? null) === [] && in_array('querschnitt_leer', array_column($r['warnungen'] ?? [], 'code'), true),
        'Warnungen nicht bestätigt → 422 mit der Liste');
    $codes = array_column($r['warnungen'] ?? [], 'code');
    $prueferDaten['warnungen'] = $codes;
    gleich(BerichtService::prueferUnterschreiben($u1, $version($u1), ['name' => 'T'] + $prueferDaten)['status'], 422, 'Name mit einem Zeichen → 422');
    $r = BerichtService::prueferUnterschreiben($u1, $version($u1), ['name' => 'MAX MEISTER'] + $prueferDaten);
    pruefe($r['status'] === 422 && str_contains($r['message'], 'Tom Technik'), 'Name eines anderen als des angemeldeten Kontos → 422');
    gleich(BerichtService::prueferUnterschreiben($u1, $version($u1), ['striche' => '{"s":[[[1,1],[2,2]]]}'] + $prueferDaten)['status'], 422, 'zu kleine Unterschrift → 422');
    gleich(BerichtService::prueferUnterschreiben($u1, $version($u1), ['anfrage' => 'kurz'] + $prueferDaten)['status'], 422, 'ohne gültigen Idempotenzschlüssel → 422');
    gleich(BerichtService::prueferUnterschreiben($u1, $version($u1) - 1, $prueferDaten)['status'], 409, 'veraltete Version → 409');
    alsBenutzer($benutzer['planer']);
    gleich(BerichtService::prueferUnterschreiben($u1, $version($u1), $prueferDaten)['status'], 404, 'Planer ohne „bearbeiten“: Entwurf nicht da (404)');
    alsBenutzer($benutzer['admin']);
    gleich(BerichtService::prueferUnterschreiben($u1, $version($u1), $prueferDaten)['status'], 403, 'ohne Prüfer-Qualifikation → 403');
    alsBenutzer($benutzer['technik']);
    $vorFehler = BerichtService::find($u1);
    $ohneKunde = $bereit;
    $ohneKunde['kopf']['kunde']['firma'] = '';
    $speichern($u1, $ohneKunde);
    $r = BerichtService::prueferUnterschreiben($u1, $version($u1), $prueferDaten);
    pruefe(!$r['ok'] && $r['status'] === 422 && in_array('kopf.kunde.firma', array_column($r['fehler'], 'feld'), true), 'Fehler der Abschluss-Prüfung → 422 mit Feld');
    $speichern($u1, $bereit);
    gleich($zeilen($u1), 0, 'abgelehnte Unterschriften: keine Zeile');

    $r = BerichtService::prueferUnterschreiben($u1, $version($u1), $prueferDaten);
    $b1 = BerichtService::find($u1);
    $nummer1 = sprintf('%s-%d-0001', $praefix, $jahrU);
    pruefe($r['ok'] && $r['status'] === 200 && $b1['status'] === 'geprueft' && $b1['nummer'] === $nummer1 && $r['nummer'] === $nummer1, 'Prüfer unterschreibt: ' . $r['message']);
    gleich([$b1['jahr'], $b1['laufnr'], $b1['fassung'], $b1['pruefer_id']], [$jahrU, 1, 1, $benutzer['technik']], 'Jahr, laufende Nummer, Fassung, Prüfer');
    gleich($b1['inhalt_hash'], Kanon::hash($b1['inhalt']), 'inhalt_hash = Kanon::hash(inhalt) nachgerechnet');
    gleich($b1['inhalt_hash'], hash('sha256', (string) Database::scalar('SELECT inhalt FROM berichte WHERE id = ?', [$u1])), 'gespeicherter Inhalt ist die kanonische Schreibweise');
    $fest = $b1['inhalt'];
    pruefe(($fest['nummer'] ?? '') === $nummer1 && ($fest['fassung'] ?? '') === '1' && ($fest['auftragnehmer']['name'] ?? '') === 'Muster Eventstrom ' . $kennung
        && ($fest['kopf']['pruefer']['name'] ?? '') === 'Tom Technik' && ($fest['kopf']['pruefer']['qualifikation'] ?? '') === 'Elektrofachkraft für Veranstaltungstechnik'
        && ($fest['kopf']['pruefer']['konto'] ?? '') === 'Tom Technik' && ($fest['messgeraete'][0]['seriennummer'] ?? '') === 'DEMO-1' && isset($fest['labels']['besichtigung']['auswahl'], $fest['labels']['normen']['0100-711'], $fest['texte']['kunde'])
        && ($fest['bewertung']['kreise']['kunter01']['auto'] ?? '') === 'ok', 'feste Kopien: Nummer, Firma, Prüfer, Messgeräte, Texte, Bewertung');
    pruefe(array_diff($codes, $fest['abschluss']['warnungen_bestaetigt']) === [], 'bestätigte Warnungen gehören zum eingefrorenen Inhalt');
    $pz = BerichtService::unterschriften($u1)['pruefer'] ?? [];
    pruefe(($pz['name'] ?? '') === 'Tom Technik' && $pz['funktion'] === 'Elektrofachkraft für Veranstaltungstechnik' && $pz['inhalt_hash'] === $b1['inhalt_hash']
        && $pz['vorher_hash'] === null && $pz['geraet_am'] === '2026-10-16T09:58:12+00:00' && $pz['schluessel_id'] === BerichtService::schluesselId()
        && $pz['siegel'] === hash_hmac('sha256', $pz['kette_hash'], Crypto::ableiten('bericht-siegel-1')), 'Unterschriftszeile: Angaben, Kette, Siegel mit abgeleitetem Schlüssel');
    $gespeicherteStriche = json_decode((string) Database::scalar("SELECT striche FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'pruefer'", [$u1]), true);
    pruefe(array_keys($gespeicherteStriche) === ['s'] && $pz['striche_hash'] === Kanon::hash($gespeicherteStriche), 'gespeichert nur „s“ – keine Zeit, kein Druck');
    gleich(BerichtService::integritaet($u1), ['ok' => true, 'eingefroren' => true, 'befunde' => []], 'Integrität nach der Prüfer-Unterschrift');
    gleich(BerichtService::speichern($u1, $version($u1), json_encode($bereit))['status'], 423, 'eingefroren: speichern → 423');
    gleich(BerichtService::kreiseVorbelegen($u1, $version($u1), [$vt])['status'], 423, 'eingefroren: Zeilen ergänzen → 423');
    pruefe(!BerichtService::darfBearbeiten($b1) && BerichtService::kundeAusBericht($u1, $quelle)['status'] === 423, 'eingefroren: nicht bearbeitbar, kein Kunde aus früherem Bericht');
    $r = BerichtService::prueferUnterschreiben($u1, $version($u1), $prueferDaten);
    pruefe($r['ok'] && $r['status'] === 200 && $r['unterschrift'] === (int) $pz['id'] && $zeilen($u1) === 1, 'gleiche Anfrage noch einmal → dieselbe Zeile');
    $r = BerichtService::prueferUnterschreiben($u1, $version($u1), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten);
    pruefe(!$r['ok'] && $r['status'] === 409 && $zeilen($u1) === 1, 'zweite Prüfer-Unterschrift → 409');

    $u2 = $bereitAnlegen();
    $r = BerichtService::prueferUnterschreiben($u2, $version($u2), ['anfrage' => $prueferDaten['anfrage']] + $prueferDaten);
    pruefe(!$r['ok'] && $r['status'] === 409, 'dieselbe Anfrage an einem anderen Bericht → 409');
    $r = BerichtService::prueferUnterschreiben($u2, $version($u2), ['anfrage' => $anfrage()] + $prueferDaten);
    pruefe(!$r['ok'] && $r['status'] === 422 && str_contains($r['message'], 'neu unterschreiben'), 'gleiche Striche in einem anderen Bericht → abgelehnt');
    $r = BerichtService::prueferUnterschreiben($u2, $version($u2), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten);
    $nummer2 = sprintf('%s-%d-0002', $praefix, $jahrU);
    pruefe($r['ok'] && BerichtService::find($u2)['nummer'] === $nummer2, 'zweiter Bericht: laufende Nummer 0002');

    // ================================================== Zurückziehen
    $b2 = BerichtService::find($u2);
    $kette2 = (string) Database::scalar("SELECT kette_hash FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'pruefer'", [$u2]);
    pruefe(BerichtService::zurueckziehbar($b2) && !BerichtService::zurueckziehen($u2, '')['ok'], 'Zurückziehen nur mit Grund');
    alsBenutzer($benutzer['admin']);
    gleich(BerichtService::zurueckziehen($u2, 'falsch eingetragen')['status'], 403, 'Zurückziehen ohne Qualifikation → 403');
    alsBenutzer($benutzer['technik']);
    $r = BerichtService::zurueckziehen($u2, 'Messwert falsch eingetragen');
    $b2n = BerichtService::find($u2);
    pruefe($r['ok'] && $b2n['status'] === 'entwurf' && $b2n['nummer'] === $nummer2 && $b2n['inhalt_hash'] === null && $b2n['eingefroren_am'] === null
        && !isset($b2n['inhalt']['labels'], $b2n['inhalt']['nummer'], $b2n['inhalt']['kopf']['pruefer']) && $zeilen($u2) === 0, 'zurückgezogen: Entwurf, Nummer bleibt, feste Kopien weg');
    $ereignis = array_values(array_filter(BerichtService::ereignisse($u2), static fn (array $e): bool => $e['ereignis'] === 'zurueckgezogen'))[0] ?? [];
    gleich([$ereignis['details']['inhalt_hash'] ?? '', $ereignis['details']['kette_hash'] ?? ''], [$b2['inhalt_hash'], $kette2], 'Ereignis hält alten Hash und alte Kette');
    pruefe(BerichtService::speichern($u2, $version($u2), json_encode($bereit))['ok'], 'zurückgezogen: wieder speicherbar');
    pruefe(!BerichtService::loeschen($u2)['ok'], 'Entwurf mit Nummer wird nicht gelöscht');
    $r = BerichtService::prueferUnterschreiben($u2, $version($u2), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten);
    pruefe($r['ok'] && BerichtService::find($u2)['nummer'] === $nummer2, 'neu unterschrieben: dieselbe Nummer');
    $_SESSION = [];
    $vorKunde = $version($u2);
    pruefe(BerichtService::kundenmodusStarten($u2)['ok'] && (Kundenmodus::aktiv()['bericht'] ?? 0) === $u2 && $version($u2) === $vorKunde + 1,
        'Kundenmodus gestartet, Version + 1 (ein gleichzeitiges Zurückziehen mit altem Stand scheitert)');
    pruefe((Kundenmodus::aktiv()['user'] ?? 0) === $benutzer['technik'] && (Kundenmodus::aktiv()['name'] ?? '') === 'Tom Technik'
        && (int) Database::scalar('SELECT COUNT(*) FROM kundenmodus_geraete WHERE bericht_id = ? AND user_id = ?', [$u2, $benutzer['technik']]) === 1,
        'Kundenmodus: wer ihn gestartet hat, steht in der Sitzung und am Gerät');
    Kundenmodus::beenden();
    gleich((int) Database::scalar('SELECT COUNT(*) FROM kundenmodus_geraete WHERE bericht_id = ?', [$u2]), 0, 'Kundenmodus beendet: Sperre am Gerät weg');
    pruefe(!BerichtService::zurueckziehbar(BerichtService::find($u2)) && BerichtService::zurueckziehen($u2, 'zu spät')['status'] === 409, 'nach dem Kundenmodus kein Zurückziehen');
    $u3 = $bereitAnlegen();
    $r = BerichtService::prueferUnterschreiben($u3, $version($u3), ['anfrage' => $anfrage(), 'striche' => $striche(), 'name' => ' TOM   TECHNIK '] + $prueferDaten);
    pruefe($r['ok'], 'Name in Druckbuchstaben (Groß/klein, Leerzeichen egal) passt zum Konto: ' . $r['message']);
    gleich(BerichtService::find($u3)['nummer'], sprintf('%s-%d-0003', $praefix, $jahrU), 'dritter Bericht: 0003');
    BerichtService::ereignis($u3, 'pdf_abgerufen', ['stufe' => 'vorschau']);
    gleich(BerichtService::zurueckziehen($u3, 'zu spät')['status'], 409, 'nach Abruf des PDF kein Zurückziehen');

    // ================================================== PDF-Vorschau
    $vEntwurf = BerichtService::pdf(BerichtService::find($bereitAnlegen()));
    pruefe(!$vEntwurf['gespeichert'] && str_starts_with($vEntwurf['bytes'], '%PDF-') && preg_match('/^Entwurf-\d+\.pdf$/', $vEntwurf['name']) === 1,
        'Vorschau eines Entwurfs: erzeugt, nicht gespeichert, „Entwurf-<id>.pdf“');
    $b3 = BerichtService::find($u3);
    $v3 = BerichtService::pdf($b3);
    pruefe(!$v3['gespeichert'] && $v3['name'] === $b3['nummer'] . '.pdf' && BerichtService::pdfHash($u3) === null && $b3['pdf_hash'] === null,
        'Vorschau nach der Prüfer-Unterschrift: nicht gespeichert');
    gleich(BerichtService::pdf($b3)['bytes'], $v3['bytes'], 'Vorschau ist reproduzierbar (feste Zeit und ID)');

    // ================================================== Gleichzeitig: ein anderes Gerät hält die Datenbank
    if (App\Support\Database::driver() === 'sqlite') {
        $uSperre = $bereitAnlegen();
        $signal = tempnam(sys_get_temp_dir(), 'pm-sperre-');
        file_put_contents($signal, '');
        $sperre = proc_open([PHP_BINARY, '-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode = WAL"); $p->exec("BEGIN IMMEDIATE");'
            . ' file_put_contents($argv[2], "1"); usleep(1500000); $p->exec("COMMIT");', (string) App\Support\Config::get('database.path'), $signal],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre);
        for ($t = 0; $t < 100 && file_get_contents($signal) !== '1'; $t++) {
            usleep(50_000);
        }
        $start = microtime(true);
        $r = BerichtService::prueferUnterschreiben($uSperre, $version($uSperre), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten);
        $gewartet = microtime(true) - $start;
        proc_close($sperre);
        @unlink($signal);
        pruefe($r['ok'] && $r['status'] === 200 && $gewartet > 0.8, sprintf('Schreibsperre eines zweiten Prozesses: gewartet (%.1f s), dann unterschrieben: %s', $gewartet, $r['message']));
    }

    // ================================================== Kunde
    $vorbehalte = 'Vorbehalt ' . $kennung . ': Kabelbrücke an Bühne fehlt';
    $unterzeichner = 'Kim Kunde ' . $kennung;
    $kundeDaten = ['striche' => $striche(), 'name' => $unterzeichner, 'funktion' => 'Produktionsleitung', 'ort' => 'Musterstadt',
        'bestaetigungen' => ['erhalten', 'eingewiesen', 'gibtsnicht'], 'vorbehalte' => $vorbehalte, 'anfrage' => $anfrage(), 'geraet_am' => 'gestern'];
    $u4 = $bereitAnlegen();
    pruefe(BerichtService::kundeUnterschreiben($u4, $mitHash($u4) + $kundeDaten)['status'] === 409 && !BerichtService::kundenmodusStarten($u4)['ok'], 'Kunde vor dem Prüfer → abgelehnt');
    pruefe(BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + ['bestaetigungen' => ['eingewiesen']] + $kundeDaten)['status'] === 422, 'ohne „Bericht erhalten“ → abgelehnt');
    pruefe(BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + ['name' => ' '] + $kundeDaten)['status'] === 422, 'Kunde ohne Namen → abgelehnt');
    pruefe(BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + ['striche' => $prueferDaten['striche']] + $kundeDaten)['status'] === 422, 'Kunde mit den Strichen des Prüfers → abgelehnt');
    alsBenutzer($benutzer['planer']);
    gleich(BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + $kundeDaten)['status'], 404, 'Planer ohne „bearbeiten“: kein Kundenblock');
    alsBenutzer($benutzer['technik']);
    gleich(BerichtService::kundeUnterschreiben($u1, ['inhalt_hash' => str_repeat('0', 64)] + $kundeDaten)['status'], 409, 'Kunde hat einen anderen Stand gesehen → 409');
    gleich(BerichtService::kundeUnterschreiben($u1, $kundeDaten)['status'], 409, 'Kunde ohne gesehenen Stand → 409');
    alsBenutzer($benutzer['technik']);
    gleich($zeilen($u1), 1, 'abgelehnte Kundenunterschriften: keine Zeile');
    $r = BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + $kundeDaten);
    $b1 = BerichtService::find($u1);
    pruefe($r['ok'] && $b1['status'] === 'abgeschlossen' && $b1['abgeschlossen_am'] !== null, 'Kunde unterschreibt → abgeschlossen');
    // Prüfdatum 2001, abgeschlossen heute: die Frist läuft ab dem späteren der beiden Jahre.
    $jahrHeute = (int) (new DateTimeImmutable('now', Zeit::zone()))->format('Y');
    gleich($b1['aufbewahren_bis'], sprintf('%04d-12-31', $jahrHeute + 10), 'aufbewahren bis 31.12.(Jahr des Abschlusses + 10), nicht ab dem alten Prüfdatum');
    pruefe(!in_array($u1, array_map('intval', array_column(BerichtService::faellig(), 'id')), true), 'Bericht mit altem Prüfdatum ist nicht gleich nach dem Abschluss fällig');
    $pdfZeile = Database::first('SELECT * FROM bericht_pdf WHERE bericht_id = ?', [$u1]) ?? [];
    $pdfBytes = (string) base64_decode((string) ($pdfZeile['daten'] ?? ''), true);
    pruefe(str_starts_with($pdfBytes, '%PDF-') && str_ends_with($pdfBytes, "%%EOF\n"), 'Abschluss: PDF gespeichert (%PDF- … %%EOF)');
    pruefe(($pdfZeile['sha256'] ?? '') === hash('sha256', $pdfBytes) && $b1['pdf_hash'] === $pdfZeile['sha256'] && (int) $pdfZeile['groesse'] === strlen($pdfBytes)
        && $pdfZeile['erzeuger'] === BerichtPdf::ERZEUGER && $pdfZeile['erzeugt_am'] === $b1['abgeschlossen_am'], 'PDF: sha256 = pdf_hash, Größe, Erzeuger, Zeit des Abschlusses');
    $geliefert = BerichtService::pdf($b1);
    pruefe($geliefert['gespeichert'] && $geliefert['bytes'] === $pdfBytes && $geliefert['name'] === $nummer1 . '.pdf' && $geliefert['sha256'] === $b1['pdf_hash'],
        'pdf(): abgeschlossen → die gespeicherten Bytes, Dateiname nur mit Nummer');
    gleich(BerichtService::pdfHash($u1), $b1['pdf_hash'], 'pdfHash() für die Berichtsseite');
    pruefe(str_contains($pdfBytes, '/CreationDate (D:' . gmdate('YmdHis', (int) Zeit::ts($b1['abgeschlossen_am'])) . 'Z)')
        && str_contains($pdfBytes, '/ID [<' . substr((string) $b1['inhalt_hash'], 0, 32) . '>'), 'PDF: CreationDate = Abschluss, ID aus dem inhalt_hash');
    gleich(BerichtPdf::erzeugen($b1, BerichtService::unterschriften($u1), false), $pdfBytes, 'PDF aus dem eingefrorenen Inhalt neu erzeugt: dieselben Bytes');
    $alle = BerichtService::unterschriften($u1);
    $kz = $alle['kunde'] ?? [];
    pruefe(($kz['vorher_hash'] ?? '') === $alle['pruefer']['kette_hash'] && $kz['inhalt_hash'] === $b1['inhalt_hash'] && $kz['art'] === 'gezeichnet'
        && $kz['angaben'] === ['bestaetigungen' => ['erhalten', 'eingewiesen'], 'vorbehalte' => $vorbehalte] && $kz['geraet_am'] === null,
        'Kundenzeile kettet an die des Prüfers; nur bekannte Bestätigungen');
    $r = BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + $kundeDaten);
    pruefe($r['ok'] && $r['unterschrift'] === (int) $kz['id'] && $zeilen($u1) === 2, 'Kundenunterschrift doppelt gesendet → eine Zeile');
    $r = BerichtService::kundeUnterschreiben($u1, $mitHash($u1) + ['anfrage' => $anfrage(), 'striche' => $striche()] + $kundeDaten);
    pruefe(!$r['ok'] && $r['status'] === 409 && $zeilen($u1) === 2, 'zweite Kundenunterschrift → abgelehnt');
    gleich(BerichtService::integritaet($u1), ['ok' => true, 'eingefroren' => true, 'befunde' => []], 'Integrität nach dem Abschluss');
    pruefe(!BerichtService::ohneKunde($u2, 'abwesend', '')['ok'] && BerichtService::ohneKunde($u2, 'egal', 'Grund genug')['status'] === 422, 'ohne Kunde: Grund und Art sind Pflicht');
    alsBenutzer($benutzer['admin']);
    gleich(BerichtService::ohneKunde($u2, 'abwesend', 'Kunde nicht vor Ort')['status'], 403, 'ohne Kunde nur mit Qualifikation');
    alsBenutzer($benutzer['technik']);
    $r = BerichtService::ohneKunde($u2, 'abwesend', 'Kunde nicht vor Ort, Bericht per E-Mail');
    $k2 = BerichtService::unterschriften($u2)['kunde'] ?? [];
    pruefe($r['ok'] && BerichtService::find($u2)['status'] === 'abgeschlossen' && ($k2['art'] ?? '') === 'abwesend' && $k2['striche'] === []
        && $k2['angaben'] === ['grund' => 'Kunde nicht vor Ort, Bericht per E-Mail'], 'ohne Kunde abgeschlossen: Art und Grund im Kundenblock');
    gleich(BerichtService::integritaet($u2)['ok'], true, 'Integrität ohne Kunde');
    pruefe(BerichtService::pdfHash($u2) !== null && BerichtService::pdfHash($u2) === BerichtService::find($u2)['pdf_hash'], 'ohne Kunde: PDF gespeichert');
    gleich(BerichtService::ohneKunde($u2, 'verweigert', 'noch einmal')['status'], 409, 'ohne Kunde nur einmal');

    // ================================================== Integrität
    $roherInhalt = (string) Database::scalar('SELECT inhalt FROM berichte WHERE id = ?', [$u1]);
    $anders = json_decode($roherInhalt, true);
    $anders['kreise'][0]['w']['zs']['w'] = '0.2';
    Database::execute('UPDATE berichte SET inhalt = ? WHERE id = ?', [Kanon::json($anders), $u1]);
    $i = BerichtService::integritaet($u1);
    pruefe(!$i['ok'] && str_contains(implode(' ', $i['befunde']), 'Inhalt verändert'), 'Inhalt per SQL geändert → Befund „Inhalt verändert“');
    Database::execute('UPDATE berichte SET inhalt = ? WHERE id = ?', [$roherInhalt, $u1]);
    Database::execute("UPDATE bericht_unterschriften SET name = ? WHERE bericht_id = ? AND rolle = 'kunde'", ['Jemand anderes', $u1]);
    $i = BerichtService::integritaet($u1);
    pruefe(!$i['ok'] && str_contains(implode(' ', $i['befunde']), 'Siegel ungültig'), 'Name der Unterschrift geändert → Befund Siegel');
    Database::execute("UPDATE bericht_unterschriften SET name = ? WHERE bericht_id = ? AND rolle = 'kunde'", [$unterzeichner, $u1]);
    $roheStriche = (string) Database::scalar("SELECT striche FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'pruefer'", [$u1]);
    Database::execute("UPDATE bericht_unterschriften SET striche = ? WHERE bericht_id = ? AND rolle = 'pruefer'", [str_replace('[1', '[2', $roheStriche), $u1]);
    pruefe(str_contains(implode(' ', BerichtService::integritaet($u1)['befunde']), 'Striche wurden verändert'), 'Striche geändert → Befund');
    Database::execute("UPDATE bericht_unterschriften SET striche = ? WHERE bericht_id = ? AND rolle = 'pruefer'", [$roheStriche, $u1]);
    $schluesselVorher = (string) Database::scalar("SELECT schluessel_id FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'kunde'", [$u1]);
    Database::execute("UPDATE bericht_unterschriften SET schluessel_id = 'abcdef12' WHERE bericht_id = ? AND rolle = 'kunde'", [$u1]);
    $i = BerichtService::integritaet($u1);
    pruefe(str_contains(implode(' ', $i['befunde']), 'anderem Schlüssel') && !str_contains(implode(' ', $i['befunde']), 'Siegel ungültig'), 'anderer Schlüssel: nicht prüfbar statt verfälscht');
    Database::execute("UPDATE bericht_unterschriften SET schluessel_id = ? WHERE bericht_id = ? AND rolle = 'kunde'", [$schluesselVorher, $u1]);
    gleich(BerichtService::integritaet($u1)['ok'], true, 'wiederhergestellt: Integrität ok');
    $rohPdf = (string) Database::scalar('SELECT daten FROM bericht_pdf WHERE bericht_id = ?', [$u1]);
    $anderesPdf = (string) base64_decode($rohPdf, true);
    $anderesPdf[300] = $anderesPdf[300] === 'x' ? 'y' : 'x';
    Database::execute('UPDATE bericht_pdf SET daten = ? WHERE bericht_id = ?', [base64_encode($anderesPdf), $u1]);
    $i = BerichtService::integritaet($u1);
    pruefe(!$i['ok'] && str_contains(implode(' ', $i['befunde']), 'PDF verändert'), 'ein PDF-Byte geändert → Befund PDF');
    Database::execute('UPDATE bericht_pdf SET daten = ? WHERE bericht_id = ?', [$rohPdf, $u1]);
    gleich(BerichtService::integritaet($u1, true), ['ok' => true, 'eingefroren' => true, 'befunde' => [], 'hinweise' => []],
        'mit Neuerzeugung: dieselben Bytes, kein Hinweis');
    pruefe(($pdfZeile['siegel'] ?? '') !== '' && $pdfZeile['schluessel_id'] === BerichtService::schluesselId(), 'PDF beim Abschluss gesiegelt');
    // Ein stimmig ausgetauschtes PDF (Daten, Prüfsumme, Größe und pdf_hash passend): das Siegel deckt es auf.
    $tausch = '%PDF-1.4 anders';
    $tauschSha = hash('sha256', $tausch);
    Database::execute('UPDATE bericht_pdf SET daten = ?, sha256 = ?, groesse = ? WHERE bericht_id = ?', [base64_encode($tausch), $tauschSha, strlen($tausch), $u1]);
    Database::execute('UPDATE berichte SET pdf_hash = ? WHERE id = ?', [$tauschSha, $u1]);
    $i = BerichtService::integritaet($u1, true);
    pruefe(!$i['ok'] && str_contains(implode(' ', $i['befunde']), 'PDF: Siegel ungültig') && str_contains(implode(' ', $i['befunde']), 'beim Abschluss festgehaltenen'),
        'stimmig ausgetauschtes PDF → Befund (Siegel, Prüfsumme im Lebenslauf)');
    // Nur ein älterer Erzeuger mit gültigem Siegel macht eine abweichende Neuerzeugung zum bloßen Hinweis.
    $kundenKette = (string) Database::scalar("SELECT kette_hash FROM bericht_unterschriften WHERE bericht_id = ? AND rolle = 'kunde'", [$u1]);
    $altSiegel = hash_hmac('sha256', implode("\n", ['PM-UB-PDF-1', (string) $u1, $nummer1, '1', $kundenKette, $tauschSha, 'pm-pdf-0']), Crypto::ableiten('bericht-siegel-1'));
    Database::execute('UPDATE bericht_pdf SET erzeuger = ?, siegel = ? WHERE bericht_id = ?', ['pm-pdf-0', $altSiegel, $u1]);
    $ereignisId = (int) Database::scalar("SELECT id FROM bericht_ereignisse WHERE bericht_id = ? AND ereignis = 'kunde_unterschrieben'", [$u1]);
    $ereignisVorher = (string) Database::scalar('SELECT details FROM bericht_ereignisse WHERE id = ?', [$ereignisId]);
    Database::execute('UPDATE bericht_ereignisse SET details = ? WHERE id = ?', [str_replace((string) $pdfZeile['sha256'], $tauschSha, $ereignisVorher), $ereignisId]);
    $i = BerichtService::integritaet($u1, true);
    pruefe($i['ok'] && count($i['hinweise']) === 1 && str_contains($i['hinweise'][0], 'pm-pdf-0') && str_contains($i['hinweise'][0], 'Siegel stimmt'),
        'älterer Erzeuger, gültiges Siegel: abweichende Neuerzeugung nur ein Hinweis');
    Database::execute('UPDATE bericht_pdf SET erzeuger = ? WHERE bericht_id = ?', [BerichtPdf::ERZEUGER, $u1]);
    $i = BerichtService::integritaet($u1, true);
    pruefe(!$i['ok'] && str_contains(implode(' ', $i['befunde']), 'obwohl der Erzeuger derselbe ist'), 'gleicher Erzeuger, abweichende Neuerzeugung → Befund');
    Database::execute('UPDATE bericht_ereignisse SET details = ? WHERE id = ?', [$ereignisVorher, $ereignisId]);
    Database::execute('UPDATE bericht_pdf SET daten = ?, sha256 = ?, groesse = ?, erzeuger = ?, siegel = ? WHERE bericht_id = ?',
        [$rohPdf, $pdfZeile['sha256'], $pdfZeile['groesse'], $pdfZeile['erzeuger'], $pdfZeile['siegel'], $u1]);
    Database::execute('UPDATE berichte SET pdf_hash = ? WHERE id = ?', [$pdfZeile['sha256'], $u1]);
    gleich(BerichtService::integritaet($u1, true)['ok'], true, 'PDF zurück: Integrität ok');
    $pdfU2 = Database::first('SELECT * FROM bericht_pdf WHERE bericht_id = ?', [$u2]);
    Database::execute('DELETE FROM bericht_pdf WHERE bericht_id = ?', [$u2]);
    pruefe(str_contains(implode(' ', BerichtService::integritaet($u2)['befunde']), 'PDF fehlt'), 'gespeichertes PDF gelöscht → Befund');
    $neuU2 = BerichtService::pdf(BerichtService::find($u2));
    pruefe(!$neuU2['gespeichert'] && $neuU2['sha256'] === BerichtService::find($u2)['pdf_hash'] && $neuU2['sha256'] === $pdfU2['sha256'],
        'ohne gespeichertes PDF: Neuerzeugung mit derselben Prüfsumme');
    unset($pdfU2['id']);
    Database::insert('bericht_pdf', $pdfU2);
    gleich(BerichtService::integritaet($u2)['ok'], true, 'PDF wieder abgelegt: Integrität ok');

    // Status per SQL geändert: eingefroren ist, was die Daten sagen (Unterschrift, Prüfsumme, PDF)
    foreach (['entwurf', 'verworfen', 'ersetzt', 'geprueft'] as $falsch) {
        Database::execute('UPDATE berichte SET status = ? WHERE id = ?', [$falsch, $u1]);
        $i = BerichtService::integritaet($u1);
        pruefe(!$i['ok'] && $i['eingefroren'], 'Status per SQL auf „' . $falsch . '“ → Befund: ' . implode(' ', $i['befunde']));
    }
    Database::execute("UPDATE berichte SET status = 'entwurf' WHERE id = ?", [$u1]);
    $bx = BerichtService::find($u1);
    pruefe(!BerichtService::darfBearbeiten($bx) && BerichtService::speichern($u1, (int) $bx['version'], json_encode($bereit))['status'] === 423
        && BerichtService::kreiseVorbelegen($u1, (int) $bx['version'], [$vt])['status'] === 423 && BerichtService::find($u1)['inhalt_hash'] === $b1['inhalt_hash'],
        'Status „entwurf“ per SQL: nicht bearbeitbar, Autosave und Zeilen ergänzen → 423');
    pruefe(in_array($u1, array_column(BerichtService::eingefrorene(), 'id'), true), 'eingefrorene(): nach den Daten, nicht nach dem Status');
    $r = BerichtService::verwerfen($u1, 'per SQL zurückgesetzt');
    pruefe(!$r['ok'] && ($r['status'] ?? 0) === 409 && !in_array('verworfen', array_column(BerichtService::ereignisse($u1), 'ereignis'), true),
        'verwerfen trifft nichts → 409, kein „verworfen“ im Lebenslauf');
    Database::execute("UPDATE berichte SET status = 'abgeschlossen' WHERE id = ?", [$u1]);
    gleich(BerichtService::integritaet($u1)['ok'], true, 'Status zurück: Integrität ok');

    // ================================================== Feste Kopie
    Settings::put('firma.name', 'Ganz andere Firma ' . $kennung);
    $b1 = BerichtService::find($u1);
    pruefe($b1['inhalt']['auftragnehmer']['name'] === 'Muster Eventstrom ' . $kennung && BerichtService::integritaet($u1)['ok'], 'Firmendaten später geändert: der Bericht bleibt, wie er war');
    pruefe(BerichtService::pdf($b1)['bytes'] === $pdfBytes && BerichtPdf::erzeugen($b1, BerichtService::unterschriften($u1), false) === $pdfBytes
        && BerichtService::integritaet($u1, true)['hinweise'] === [], 'Firmendaten später geändert: pdf() und die Neuerzeugung ergeben dieselben Bytes');

    // ================================================== Korrektur
    alsBenutzer($benutzer['planer']);
    gleich(BerichtService::korrektur($u1, 'Querschnitt nachgetragen')['status'], 403, 'Korrektur ohne Prüfer-Recht → 403');
    alsBenutzer($benutzer['technik']);
    pruefe(!BerichtService::korrektur($u1, '')['ok'], 'Korrektur nur mit Grund');
    gleich(BerichtService::korrektur($u3, 'zu früh')['status'], 409, 'Korrektur nur für abgeschlossene Berichte');
    $r = BerichtService::korrektur($u1, 'Querschnitt nachgetragen');
    $k1 = (int) ($r['id'] ?? 0);
    $kb = BerichtService::find($k1);
    pruefe($r['ok'] && $kb['fassung'] === 2 && $kb['vorgaenger_id'] === $u1 && $kb['status'] === 'entwurf' && $kb['nummer'] === null
        && [$kb['jahr'], $kb['laufnr']] === [$jahrU, 1] && $kb['korrektur_grund'] === 'Querschnitt nachgetragen', 'Korrektur: Fassung 2 zur selben Nummer');
    pruefe(!isset($kb['inhalt']['labels'], $kb['inhalt']['nummer'], $kb['inhalt']['kopf']['pruefer']) && $kb['inhalt']['kreise'][0]['z'] === 'kunter01'
        && $kb['inhalt']['abschluss']['warnungen_bestaetigt'] === [], 'Korrektur: Inhalt ohne feste Kopien, Warnungen neu zu bestätigen');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ?', [$k1]), (int) Database::scalar('SELECT COUNT(*) FROM bericht_objekte WHERE bericht_id = ?', [$u1]),
        'Korrektur: dieselben Anlagen');
    gleich(BerichtService::find($u1)['status'], 'abgeschlossen', 'Vorgänger bleibt abgeschlossen, solange die Korrektur offen ist');
    gleich(BerichtService::korrektur($u1, 'noch eine')['status'], 409, 'zweite offene Korrektur → abgelehnt');
    $korr = $kb['inhalt'];
    $korr['kreise'][0]['leitung']['querschnitt'] = '4';
    pruefe($speichern($k1, $korr)['ok'], 'Korrektur gespeichert');
    $r = BerichtService::prueferUnterschreiben($k1, $version($k1), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten);
    $kb = BerichtService::find($k1);
    pruefe($r['ok'] && $kb['nummer'] === $nummer1 . '-K1' && ($kb['inhalt']['vorgaenger']['nummer'] ?? '') === $nummer1
        && ($kb['inhalt']['vorgaenger']['inhalt_hash'] ?? '') === BerichtService::find($u1)['inhalt_hash'] && ($kb['inhalt']['vorgaenger']['grund'] ?? '') === 'Querschnitt nachgetragen',
        'Korrektur unterschrieben: Nummer …-K1, Vorgänger im Inhalt');
    gleich([BerichtService::find($u1)['status'], BerichtService::korrektur($u1, 'noch eine')['status']], ['abgeschlossen', 409], 'unterschriebene Korrektur: Vorgänger noch gültig, keine zweite');
    $pdfVorher = Database::select('SELECT * FROM bericht_pdf WHERE bericht_id = ?', [$u1]);
    $hashVorher = BerichtService::find($u1)['inhalt_hash'];
    pruefe(BerichtService::kundeUnterschreiben($k1, $mitHash($k1) + ['anfrage' => $anfrage(), 'striche' => $striche()] + $kundeDaten)['ok'], 'Kunde unterschreibt die Korrektur');
    $alt = BerichtService::find($u1);
    pruefe(BerichtService::find($k1)['status'] === 'abgeschlossen' && $alt['status'] === 'ersetzt' && $alt['ersetzt_durch_id'] === $k1, 'Korrektur abgeschlossen → Vorgänger ersetzt');
    pruefe($alt['inhalt_hash'] === $hashVorher && Database::select('SELECT * FROM bericht_pdf WHERE bericht_id = ?', [$u1]) === $pdfVorher
        && BerichtService::integritaet($u1)['ok'], 'ersetzter Bericht unverändert (Inhalt, PDF, Integrität)');
    gleich(BerichtService::korrektur($u1, 'am alten')['status'], 409, 'ersetzter Bericht: Korrektur nur an der neuesten Fassung');
    $r = BerichtService::korrektur($k1, 'zweite Korrektur');
    pruefe($r['ok'] && BerichtService::find((int) $r['id'])['fassung'] === 3, 'Korrektur der Korrektur: Fassung 3');
    pruefe(BerichtService::loeschen((int) $r['id'])['ok'], 'offene Korrektur ohne Nummer lässt sich verwerfen (löschen)');
    pruefe(in_array('ersetzt', array_column(BerichtService::ereignisse($u1), 'ereignis'), true)
        && in_array('korrektur_angelegt', array_column(BerichtService::ereignisse($u1), 'ereignis'), true), 'Ereignisse am Vorgänger: Korrektur angelegt, ersetzt');
    Database::update('berichte', $k1, ['vorgaenger_id' => 999999999]);
    pruefe(str_contains(implode(' ', BerichtService::integritaet($k1)['befunde']), 'Vorgänger dieser Korrektur fehlt'), 'Korrektur, deren Vorgänger fehlt → Befund');
    Database::update('berichte', $k1, ['vorgaenger_id' => $u1]);
    gleich(BerichtService::integritaet($k1)['ok'], true, 'Vorgänger zurück: Integrität ok');

    // ================================================== Aufbewahrung: ganze Nummern, keine Nummer zweimal
    $jahrA = $freiesJahr($jahrU + 1);
    $testJahre[] = $jahrA;
    $altesDatum = $bereit;
    $altesDatum['kopf']['pruefdatum'] = $jahrA . '-03-02';
    $unterschreibenAlt = static function () use ($siteId, $vt, $altesDatum, $speichern, $version, $anfrage, $striche, $prueferDaten): int {
        $id = (int) BerichtService::anlegen($siteId, null, [$vt])['id'];
        $speichern($id, $altesDatum);
        $r = BerichtService::prueferUnterschreiben($id, $version($id), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten);
        pruefe($r['ok'], 'Bericht mit altem Prüfdatum unterschrieben (Warnung bestätigt): ' . $r['message']);
        return $id;
    };
    $pa = $unterschreibenAlt();
    pruefe(BerichtService::ohneKunde($pa, 'abwesend', 'Kunde nicht vor Ort')['ok'], 'abgeschlossen ohne Kunde');
    $pab = BerichtService::find($pa);
    gleich([$pab['nummer'], $pab['aufbewahren_bis']], [sprintf('%s-%d-0001', $praefix, $jahrA), sprintf('%04d-12-31', $jahrHeute + 10)],
        'Prüfdatum ' . $jahrA . ': Nummer im Jahr des Prüfdatums, Frist ab dem Abschluss');
    $r = BerichtService::korrektur($pa, 'Ort nachgetragen');
    $ka = (int) ($r['id'] ?? 0);
    pruefe(BerichtService::prueferUnterschreiben($ka, $version($ka), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten)['ok']
        && BerichtService::ohneKunde($ka, 'abwesend', 'Kunde nicht vor Ort')['ok'], 'Korrektur mit dem alten Prüfdatum unterschrieben und abgeschlossen');
    gleich(BerichtService::find($ka)['aufbewahren_bis'], sprintf('%04d-12-31', $jahrHeute + 10), 'Korrektur: Frist ab dem Abschluss');
    $faelligIds = static fn (): array => array_map('intval', array_column(BerichtService::faellig(), 'id'));
    Database::update('berichte', $pa, ['aufbewahren_bis' => '2000-12-31']);
    pruefe(!in_array($pa, $faelligIds(), true), 'nur der Vorgänger abgelaufen: die Nummer ist nicht fällig');
    gleich(BerichtService::aufbewahrungLoeschen($pa), [], 'aufbewahrungLoeschen lehnt ab, solange nicht die ganze Nummer fällig ist');
    $kb2 = (int) (BerichtService::korrektur($ka, 'noch eine Korrektur')['id'] ?? 0);
    Database::update('berichte', $ka, ['aufbewahren_bis' => '2000-12-31']);
    pruefe($kb2 > 0 && !in_array($pa, $faelligIds(), true) && !in_array($ka, $faelligIds(), true), 'offene Korrektur (Entwurf): die Nummer ist nicht fällig');
    Database::update('berichte', $kb2, ['vorgaenger_id' => 999999999]);
    gleich(BerichtService::prueferUnterschreiben($kb2, $version($kb2), ['anfrage' => $anfrage(), 'striche' => $striche()] + $prueferDaten)['status'], 409,
        'Vorgänger fehlt → die Korrektur wird nicht ohne Verweis eingefroren (409)');
    gleich(BerichtService::find($kb2)['status'], 'entwurf', 'nichts eingefroren');
    Database::update('berichte', $kb2, ['vorgaenger_id' => $ka]);
    pruefe(BerichtService::loeschen($kb2)['ok'], 'offene Korrektur gelöscht');
    pruefe(in_array($pa, $faelligIds(), true) && in_array($ka, $faelligIds(), true), 'jetzt ist die ganze Nummer fällig');
    $weg = BerichtService::aufbewahrungLoeschen($pa);
    sort($weg);
    gleich([$weg, BerichtService::find($pa), BerichtService::find($ka)], [[$pa, $ka], null, null], 'Aufbewahrung löscht alle Fassungen einer Nummer gemeinsam');
    $pn = $unterschreibenAlt();
    gleich([BerichtService::find($pn)['laufnr'], BerichtService::find($pn)['nummer']], [2, sprintf('%s-%d-0002', $praefix, $jahrA)],
        'eine gelöschte Nummer wird nicht wieder vergeben');

    // ================================================== Kommandozeile und Wartung
    /** bin/power mit diesen Argumenten: [Exitcode, Ausgabe (stdout und stderr)] */
    $cli = static function (string ...$argumente): array {
        $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/power'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
        $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);
        return [proc_close($p), (string) $aus];
    };
    $ohneKundendaten = static function (string $aus) use ($kundeName, $kundeMail, $vorbehalte, $unterzeichner): bool {
        foreach ([$kundeName, $kundeMail, $vorbehalte, $unterzeichner] as $geheim) {
            if (str_contains($aus, $geheim)) {
                return false;
            }
        }
        return true;
    };
    [$code, $aus] = $cli('berichte:pruefen', $nummer1);
    pruefe($code === 0 && str_contains($aus, 'OK') && str_contains($aus, $nummer1 . ' (Fassung 1, ersetzt)') && $ohneKundendaten($aus), 'CLI berichte:pruefen <nummer>: OK, ohne Kundendaten');
    [$code, $aus] = $cli('berichte:pruefen', $nummer1, '--fassung', '2', '--pdf');
    pruefe($code === 0 && str_contains($aus, $nummer1 . '-K1 (Fassung 2, abgeschlossen)') && !str_contains($aus, 'Hinweis'), 'CLI berichte:pruefen --fassung 2 --pdf: Korrektur, neu erzeugt gleich');
    gleich($cli('berichte:pruefen', $nummer1, '--fassung=9')[0], 1, 'CLI: unbekannte Fassung → Exit 1');
    $rohK1 = (string) Database::scalar('SELECT daten FROM bericht_pdf WHERE bericht_id = ?', [$k1]);
    $kaputt = (string) base64_decode($rohK1, true);
    $kaputt[200] = $kaputt[200] === 'x' ? 'y' : 'x';
    Database::execute('UPDATE bericht_pdf SET daten = ? WHERE bericht_id = ?', [base64_encode($kaputt), $k1]);
    [$code, $aus] = $cli('berichte:pruefen', $nummer1 . '-K1');
    pruefe($code === 1 && str_contains($aus, 'BEFUND') && str_contains($aus, 'PDF verändert'), 'CLI: ein PDF-Byte geändert → Befund, Exit 1');
    Database::execute('UPDATE bericht_pdf SET daten = ? WHERE bericht_id = ?', [$rohK1, $k1]);
    [$code, $aus] = $cli('berichte:pruefen');
    pruefe(str_contains($aus, $nummer1 . ' (Fassung 1') && str_contains($aus, $nummer1 . '-K1 (Fassung 2') && str_contains($aus, ' geprüft, ') && $ohneKundendaten($aus),
        'CLI berichte:pruefen ohne Nummer: alle unterschriebenen, ohne Kundendaten');
    Database::execute("UPDATE berichte SET status = 'entwurf' WHERE id = ?", [$k1]);
    [$code, $aus] = $cli('berichte:pruefen', $nummer1 . '-K1');
    pruefe($code === 1 && str_contains($aus, 'BEFUND') && str_contains($aus, 'Status'), 'CLI: Status per SQL auf „entwurf“ → Befund, Exit 1');
    [$code, $aus] = $cli('berichte:pruefen');
    pruefe($code === 1 && str_contains($aus, $nummer1 . '-K1 (Fassung 2, Entwurf)'), 'CLI ohne Nummer: auch der zurückgesetzte Bericht wird geprüft');
    Database::execute("UPDATE berichte SET status = 'abgeschlossen' WHERE id = ?", [$k1]);

    $zielOrdner = sys_get_temp_dir() . '/' . $kennung . '-pdf';
    mkdir($zielOrdner, 0700);
    try {
        $ziel = $zielOrdner . '/bericht.pdf';
        [$code, $aus] = $cli('berichte:pdf', $nummer1, $ziel);
        pruefe($code === 0 && is_file($ziel) && file_get_contents($ziel) === $pdfBytes && str_contains($aus, hash('sha256', $pdfBytes)), 'CLI berichte:pdf: genau die gespeicherten Bytes');
        file_put_contents($ziel, 'vorher');
        [$code, $aus] = $cli('berichte:pdf', $nummer1, $ziel);
        pruefe($code === 1 && str_contains($aus, 'gibt es schon') && file_get_contents($ziel) === 'vorher', 'CLI berichte:pdf überschreibt keine vorhandene Datei');
        [$code, $aus] = $cli('berichte:pdf', $nummer1, $zielOrdner . '/k1.pdf', '--fassung=2');
        pruefe($code === 0 && file_get_contents($zielOrdner . '/k1.pdf') === base64_decode($rohK1, true), 'CLI berichte:pdf --fassung=2: das PDF der Korrektur');
        [$code, $aus] = $cli('berichte:pdf', (string) BerichtService::find($u3)['nummer'], $zielOrdner . '/u3.pdf');
        pruefe($code === 1 && str_contains($aus, 'kein PDF gespeichert') && !is_file($zielOrdner . '/u3.pdf'), 'CLI berichte:pdf: ohne Abschluss kein PDF');
        gleich($cli('berichte:pdf', $praefix . '-1999-9999', $zielOrdner . '/x.pdf')[0], 1, 'CLI berichte:pdf: unbekannte Nummer → Exit 1');
    } finally {
        array_map('unlink', glob($zielOrdner . '/*') ?: []);
        rmdir($zielOrdner);
    }

    $entwurfAlt = (int) BerichtService::anlegen($siteId, null, [])['id'];
    Database::update('berichte', $entwurfAlt, ['updated_at' => gmdate('c', time() - 200 * 86400)]);
    // Ganze Nummer: Fassung 1 (ersetzt) und die Korrektur K1.
    Database::update('berichte', $u1, ['aufbewahren_bis' => '2000-12-31']);
    Database::update('berichte', $k1, ['aufbewahren_bis' => '2000-12-31']);
    $meldungen = implode(' ', BerichtService::wartungHinweise());
    pruefe(preg_match('/\d+ Übergabeberichte? über der Aufbewahrungsfrist \(bin\/power berichte:aufbewahrung\)/u', $meldungen) === 1
        && preg_match('/\d+ Entw(urf|ürfe) eines Übergabeberichts seit 180 Tagen unverändert/u', $meldungen) === 1, 'Wartung meldet fällige Berichte und liegende Entwürfe: ' . $meldungen);
    pruefe(BerichtService::find($u1) !== null && BerichtService::find($entwurfAlt) !== null, 'Wartung löscht keine Berichte');
    [$code, $aus] = $cli('berichte:aufbewahrung');
    pruefe($code === 0 && str_contains($aus, $nummer1) && str_contains($aus, '--ausfuehren') && BerichtService::find($u1) !== null && $ohneKundendaten($aus),
        'CLI berichte:aufbewahrung zeigt nur, löscht ohne --ausfuehren nichts');
    // Löschen nur, wenn ausschließlich eigene Berichte fällig sind – fremde
    // Daten bleiben unangetastet.
    $eigene = array_map('intval', array_column(Database::select(
        'SELECT id FROM berichte WHERE erstellt_von IN (' . implode(',', array_map('intval', $benutzer)) . ')'
    ), 'id'));
    $faellig = array_map('intval', array_column(BerichtService::faellig(), 'id'));
    if (in_array($u1, $faellig, true) && array_diff($faellig, $eigene) === []) {
        [$code, $aus] = $cli('berichte:aufbewahrung', '--ausfuehren');
        $rest = (int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE id IN (' . implode(',', $faellig) . ')')
            + (int) Database::scalar('SELECT COUNT(*) FROM bericht_pdf WHERE bericht_id IN (' . implode(',', $faellig) . ')');
        pruefe($code === 0 && $rest === 0 && str_contains($aus, count($faellig) . ' gelöscht')
            && (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'bericht.geloescht' AND details LIKE ?", ['%"' . $nummer1 . '"%']) === 1,
            'CLI berichte:aufbewahrung --ausfuehren löscht samt PDF, Audit mit Nummer');
    }
    if (App\Support\Config::isDemo()) {
        [$code, $aus] = $cli('eventworx:pruefen');
        pruefe($code === 0 && str_contains($aus, 'Demo-Modus'), 'CLI eventworx:pruefen im Demo-Modus');
    }

    // ================================================== Vorgaben und Leseansicht
    $textKunde = Einstellungen::VORGABEN['berichte.text_kunde'];
    pruefe(str_contains($textKunde, 'Erhalt des Berichts') && !str_contains($textKunde, 'übernommen zu haben'),
        'Kundentext bestätigt nur den Erhalt – Übernahme und Einweisung sind eigene, freiwillige Häkchen');
    pruefe(str_contains(BerichtSchema::leer()['ergebnis']['naechste'], 'spätestens nach 1 Monat') && mb_strlen(BerichtSchema::NAECHSTE) <= 255,
        'Nächster Prüftermin: Monatsfrist der RCD bei längerem Betrieb (DGUV V3 DA Tab. 1A)');
    $ansicht = $bereit;
    $ka1 = $muster(['w' => ['rpe' => ['op' => '', 'w' => '0.3']]]);
    $ka1['z'] = 'kansich1';
    $ka2 = $muster(['nr' => '2', 'rcd_von' => 'kansich1', 'w' => ['idelta' => ['w' => ''], 'ta' => ['w' => ''], 'ub' => ['w' => '']]]);
    $ka2['z'] = 'kansich2';
    $ansicht['kreise'] = [$ka1, $ka2];
    $ansicht = BerichtSchema::vollstaendig($ansicht);
    $html = App\Support\View::partial('berichte/_ansicht', ['bericht' => ['status' => 'entwurf'], 'inhalt' => $ansicht, 'bewertung' => Grenzwerte::alle($ansicht), 'unterschriften' => []]);
    pruefe(str_contains($html, 'an Kreis 1 gemessen') && str_contains($html, 'data-label="R_PE Ω"') && str_contains($html, '0,3'),
        'Leseansicht (auch im Kundenmodus): „an Kreis 1 gemessen“ statt leerer Zellen, Spalte R_PE');

    // ================================================== Datenschutz
    foreach ([$kundeName, $kundeMail, $vorbehalte, $unterzeichner] as $geheim) {
        gleich((int) Database::scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE ?', ['%' . $geheim . '%']), 0, 'nicht im Audit: ' . $geheim);
        gleich((int) Database::scalar('SELECT COUNT(*) FROM bericht_ereignisse WHERE details LIKE ?', ['%' . $geheim . '%']), 0, 'nicht in den Ereignissen: ' . $geheim);
    }
    $gespeichertAm = array_column(array_filter(BerichtService::ereignisse($leerId), static fn ($e) => $e['ereignis'] === 'gespeichert'), 'am');
    gleich(count($gespeichertAm), 1, 'höchstens ein „gespeichert“ je zehn Minuten');
} finally {
    alsBenutzer(null);
    $_SESSION = [];
    $ids = $benutzer === [] ? [] : array_map('intval', array_column(Database::select(
        'SELECT id FROM berichte WHERE erstellt_von IN (' . implode(',', array_map('intval', $benutzer)) . ')'
    ), 'id'));
    foreach ($ids as $bid) {
        foreach (['bericht_unterschriften', 'bericht_pdf', 'bericht_objekte', 'bericht_ereignisse', 'kundenmodus_geraete'] as $tabelle) {
            Database::execute("DELETE FROM {$tabelle} WHERE bericht_id = ?", [$bid]);
        }
        Database::delete('berichte', $bid);
    }
    foreach ($benutzer as $uid) {
        Database::execute('DELETE FROM kundenmodus_geraete WHERE user_id = ?', [$uid]);
    }
    // Die Nummernzähler der Jahre, die nur diese Prüfung benutzt hat.
    foreach ($testJahre as $jahr) {
        Database::execute('DELETE FROM bericht_nummern WHERE jahr = ?', [$jahr]);
    }
    Database::execute('DELETE FROM pruefgeraete WHERE fabrikat LIKE ?', [$kennung . '%']);
    foreach ($inventar as $iid) {
        Database::execute('UPDATE einsaetze SET bis = ? WHERE inventar_id = ? AND bis IS NULL', [gmdate('c'), $iid]);
        InventarService::loeschen($iid);
    }
    Database::execute('DELETE FROM leitungen WHERE name LIKE ?', [$kennung . '%']);
    foreach ($anlagen as $aid) {
        AnlagenService::loeschen($aid);
    }
    foreach ($sites as $sid) {
        if (SiteService::find($sid) !== null) {
            SiteService::deleteSite($sid);
        }
    }
    foreach ($benutzer as $uid) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$uid]);
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$uid]);
        Database::execute('DELETE FROM users WHERE id = ?', [$uid]);
    }
    foreach ($rollen as $rid) {
        Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$rid]);
        Database::execute('DELETE FROM roles WHERE id = ?', [$rid]);
    }
    foreach ($einstellungenVorher as $s => $w) {
        $w === null ? Settings::forget($s) : Settings::put($s, $w);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR details LIKE ?', [$kennung . '%', '%' . $kennung . '%']);
    if (isset($praefix)) {
        // Aus der Kommandozeile (Benutzer „system“): erkennbar an der Nummer mit dem eigenen Kürzel.
        Database::execute("DELETE FROM audit_log WHERE action LIKE 'bericht.%' AND details LIKE ?", ['%"' . $praefix . '-%']);
    }
    gleich((int) Database::scalar('SELECT COUNT(*) FROM berichte WHERE titel LIKE ? OR kunde_name LIKE ? OR nummer LIKE ?', ['%' . $kennung . '%', '%' . $kennung . '%', $kennung . '%']), 0,
        'aufgeräumt: keine Berichte der Prüfung');
}

ende('berichte');
