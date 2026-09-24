<?php
declare(strict_types=1);

/**
 * Anlagen, Anschlüsse, Schutzorgane und Kabel.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\LeitungService;
use App\Services\SchutzService;
use App\Services\SiteService;
use App\Support\Database;

AnlagenService::grundbestand();
$typ = [];
foreach (AnlagenService::steckertypen() as $t) {
    $typ[(string) $t['schluessel']] = (int) $t['id'];
}
foreach (['powerlock', 'cee125', 'cee63', 'cee32', 'cee16', 'schuko'] as $k) {
    pruefe(isset($typ[$k]), "Standard-Steckertyp {$k} ist im Katalog");
}

$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Anschlüsse ' . bin2hex(random_bytes(3))]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Fläche']);
$angelegt = [];

try {
    // Vorschlag je Art
    $v = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Prüf-Verteilung', 'floor_id' => $floorId])['id'];
    $angelegt[] = $v;
    $anz = AnlagenService::anzahlen($v);
    gleich($anz['eingang'][$typ['cee63']] ?? 0, 1, 'Vorschlag Verteilung: 1× CEE 63 A Eingang');
    gleich($anz['abgang'][$typ['schuko']] ?? 0, 6, 'Vorschlag Verteilung: 6× Schuko');
    gleich((int) Database::scalar('SELECT site_id FROM objekte WHERE id = ?', [$v]), $siteId, 'Etage bestimmt den Standort');

    // Anzahl frei ändern
    AnlagenService::anzahlSetzen($v, ['abgang' => [$typ['schuko'] => 2, $typ['powerlock'] => 1, $typ['cee16'] => 5]]);
    $anz = AnlagenService::anzahlen($v);
    gleich($anz['abgang'][$typ['schuko']] ?? 0, 2, 'Schuko auf 2 reduziert');
    gleich($anz['abgang'][$typ['powerlock']] ?? 0, 1, 'Powerlock ergänzt');
    gleich($anz['abgang'][$typ['cee16']] ?? 0, 5, 'CEE 16 auf 5 erhöht');
    gleich(count(array_unique(array_column(Database::select('SELECT nr FROM anschluesse WHERE objekt_id = ? AND richtung = ?', [$v, 'abgang']), 'nr'))),
        (int) Database::scalar('SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = ?', [$v, 'abgang']), 'Nummern der Abgänge sind eindeutig');

    // Schutzorgane
    $erg = SchutzService::vorschlagen($v);
    pruefe($erg['ok'], 'Bestückung vorgeschlagen');
    $ohne = (int) Database::scalar("SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND schutzorgan_id IS NULL", [$v]);
    gleich($ohne, 0, 'jeder Abgang hat ein Schutzorgan');
    $alle = SchutzService::nachId($v);
    $schuko = Database::first("SELECT * FROM anschluesse WHERE objekt_id = ? AND steckertyp_id = ? LIMIT 1", [$v, $typ['schuko']]);
    $rcd = SchutzService::rcd((int) $schuko['schutzorgan_id'], $alle);
    pruefe($rcd !== null && (int) $rcd['fehlerstrom_ma'] === 30, 'Schuko liegt hinter einem RCD 30 mA');
    $pl = Database::first("SELECT * FROM anschluesse WHERE objekt_id = ? AND steckertyp_id = ? LIMIT 1", [$v, $typ['powerlock']]);
    $rcdPl = SchutzService::rcd((int) $pl['schutzorgan_id'], $alle);
    pruefe($rcdPl !== null && (int) $rcdPl['fehlerstrom_ma'] === 300, 'Powerlock-Abgang hat eigenen RCD 300 mA');
    $kette = SchutzService::kette((int) $schuko['schutzorgan_id'], $alle);
    pruefe(str_contains($kette, 'LS C16') && str_contains($kette, 'RCD') && str_contains($kette, 'Hauptschalter'), 'Kette lesbar: ' . $kette);
    $liste = SchutzService::liste($v);
    gleich($liste[0]['art'], 'hauptschalter', 'Baum beginnt mit dem Hauptschalter');

    // Kreis wird verhindert
    $haupt = $liste[0];
    $kind = null;
    foreach ($liste as $s) {
        if ((int) $s['vorgeschaltet_id'] === (int) $haupt['id']) {
            $kind = $s;
            break;
        }
    }
    $kreis = SchutzService::speichern($v, (int) $haupt['id'], ['art' => 'hauptschalter', 'vorgeschaltet_id' => (int) $kind['id']]);
    pruefe(!$kreis['ok'], 'Kreis im Schutzbaum wird abgelehnt');

    // Löschen: Kinder rücken nach oben
    SchutzService::loeschen($v, (int) $kind['id']);
    $nach = SchutzService::nachId($v);
    foreach ($nach as $s) {
        pruefe((int) $s['vorgeschaltet_id'] !== (int) $kind['id'], 'kein Schutzorgan hängt am gelöschten');
    }

    // Vorlage → Kopie mit Belegung
    $vorlage = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Prüf-Vorlage', 'ist_vorlage' => 1, 'ohne_anschluesse' => true])['id'];
    $angelegt[] = $vorlage;
    AnlagenService::anzahlSetzen($vorlage, ['eingang' => [$typ['cee32'] => 1], 'abgang' => [$typ['cee16'] => 2, $typ['schuko'] => 3]]);
    SchutzService::vorschlagen($vorlage);
    pruefe(AnlagenService::find($vorlage)['site_id'] === null, 'Vorlage steht auf keinem Standort');
    $platziert = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Aus Vorlage', 'floor_id' => $floorId, 'vorlage_id' => $vorlage])['id'];
    $angelegt[] = $platziert;
    gleich(AnlagenService::kurzform($platziert), AnlagenService::kurzform($vorlage), 'Anschlüsse aus der Vorlage übernommen');
    gleich(count(SchutzService::liste($platziert)), count(SchutzService::liste($vorlage)), 'Schutzorgane aus der Vorlage übernommen');
    $ohne = (int) Database::scalar("SELECT COUNT(*) FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND schutzorgan_id IS NULL", [$platziert]);
    gleich($ohne, 0, 'Zuordnung Abgang → Sicherung übernommen');
    $fremd = (int) Database::scalar('SELECT COUNT(*) FROM anschluesse a JOIN schutzorgane s ON s.id = a.schutzorgan_id WHERE a.objekt_id = ? AND s.objekt_id <> ?', [$platziert, $platziert]);
    gleich($fremd, 0, 'Kopie verweist nur auf eigene Schutzorgane');

    // Kabel
    $abgang = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' AND steckertyp_id = ? ORDER BY nr LIMIT 1", [$v, $typ['cee16']]);
    $eingang = Database::first("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' LIMIT 1", [$platziert]);
    $falsch = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $v, 'von_anschluss_id' => (int) $eingang['id'], 'zu_objekt_id' => $platziert]);
    pruefe(!$falsch['ok'], 'Kabel kann nicht an einem fremden Anschluss beginnen');
    $k = LeitungService::speichern(null, [
        'art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $v, 'von_anschluss_id' => (int) $abgang['id'],
        'zu_objekt_id' => $platziert, 'zu_anschluss_id' => (int) $eingang['id'], 'kabeltyp' => 'H07RN-F', 'adern' => 5,
        'querschnitt_mm2' => '2,5', 'laenge_m' => '50', 'punkte' => [[10, 10], [20, 10]],
    ]);
    pruefe($k['ok'], 'Kabel Abgang → Eingang angelegt');
    $doppelt = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $v, 'von_anschluss_id' => (int) $abgang['id'], 'zu_objekt_id' => $platziert]);
    pruefe(!$doppelt['ok'], 'belegter Abgang wird abgelehnt');
    $l = LeitungService::find((int) $k['id']);
    gleich((float) $l['laenge'], 50.0, 'gemessene Länge gilt');
    gleich($l['belastbarkeit_a'], 30, 'Belastbarkeit 2,5 mm² ≈ 30 A');
    pruefe(array_filter($l['hinweise'], static fn ($h) => str_contains($h, 'verschiedene Stecker')) !== [], 'Stecker CEE 16 → CEE 32 wird bemerkt');
    $speisung = AnlagenService::speisung($platziert);
    gleich((int) ($speisung[0]['von_objekt_id'] ?? 0), $v, 'Speisung wird aus dem Kabel erkannt');

    // Spannungsfall: 32 A, 100 m, 6 mm², 3~ → √3·32·100·0,95/(56·6)/400 ≈ 3,92 %
    nahe(LeitungService::spannungsfall(32, 100, 6), 3.917, 0.01, 'Spannungsfall Drehstrom');
    nahe(LeitungService::spannungsfall(16, 50, 2.5, 1), 4.72, 0.02, 'Spannungsfall Wechselstrom');

    // Anschluss mit Kabel wird beim Verringern nicht entfernt
    AnlagenService::anzahlSetzen($v, ['abgang' => [$typ['cee16'] => 0]]);
    pruefe(Database::first('SELECT id FROM anschluesse WHERE id = ?', [(int) $abgang['id']]) !== null, 'belegter Anschluss bleibt beim Verringern stehen');

    // Löschen einer Anlage lässt das Kabel als Weg stehen
    AnlagenService::loeschen($platziert);
    $angelegt = array_diff($angelegt, [$platziert]);
    $l = LeitungService::find((int) $k['id']);
    pruefe($l !== null && $l['zu_objekt_id'] === null, 'Kabel bleibt, Ende ist gelöst');

    // Stückliste
    LeitungService::speichern(null, ['art' => 'kabelbruecke', 'floor_id' => $floorId, 'modell' => '5-Kanal', 'anzahl' => 3, 'punkte' => [[1, 1], [2, 2]]]);
    $stueck = LeitungService::stueckliste($floorId);
    gleich($stueck['bruecken']['5-Kanal'] ?? 0, 3, 'Stückliste zählt Kabelbrücken');
    $keinWeg = LeitungService::speichern(null, ['art' => 'kabelweg', 'floor_id' => $floorId, 'punkte' => [[1, 1]]]);
    pruefe(!$keinWeg['ok'], 'Kabelweg braucht zwei Punkte');
} finally {
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    SiteService::deleteSite($siteId);
    Database::execute("DELETE FROM audit_log WHERE username = 'system' AND action LIKE 'schutz.%'");
}

ende('anschluesse');
