<?php
declare(strict_types=1);

/**
 * Anlagen, Anschlüsse, Schutzorgane und Kabel.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Services\AnlagenService;
use App\Services\InventarService;
use App\Services\LeitungService;
use App\Services\LocationBundle;
use App\Services\MesspunktService;
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
$inventarId = null;

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

    // Fest installiert oder mobil
    $fest = static fn (int $id): int => (int) AnlagenService::find($id)['fest'];
    $ha = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Prüf-Hausanschluss', 'floor_id' => $floorId])['id'];
    $mob = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Prüf-Mobil', 'floor_id' => $floorId])['id'];
    $bau = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Prüf-Baustrom', 'fest' => '0', 'floor_id' => $floorId])['id'];
    array_push($angelegt, $ha, $mob, $bau);
    gleich([$fest($ha), $fest($mob), $fest($bau)], [1, 0, 0], 'Vorgabe: Stromanschluss fest, Verteilung mobil, ausdrücklich mobil bleibt mobil');
    gleich(AnlagenService::find($ha)['art_anzeige'], 'Feststromanschluss', 'Anzeige „Feststromanschluss“');
    AnlagenService::speichern($ha, ['art' => 'anschluss', 'name' => 'Prüf-Hausanschluss 2', 'floor_id' => $floorId]);
    gleich($fest($ha), 1, 'Bearbeiten ohne Angabe lässt „fest“ stehen');
    AnlagenService::speichern($mob, ['art' => 'verteilung', 'name' => 'Prüf-Hallenverteiler', 'fest' => '1', 'floor_id' => $floorId]);
    gleich([$fest($mob), AnlagenService::find($mob)['art_anzeige']], [1, 'Verteilung (fest)'], 'Verteilung lässt sich als fest einbauen');
    $festTyp = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Prüf-Energiesäule', 'ist_vorlage' => 1, 'fest' => 1])['id'];
    $mobilTyp = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Prüf-Baustromkasten', 'ist_vorlage' => 1, 'fest' => 0])['id'];
    $ausFest = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Säule 1', 'vorlage_id' => $festTyp, 'floor_id' => $floorId])['id'];
    $ausMobil = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Kasten 1', 'vorlage_id' => $mobilTyp, 'floor_id' => $floorId])['id'];
    array_push($angelegt, $festTyp, $mobilTyp, $ausFest, $ausMobil);
    gleich([$fest($ausFest), $fest($ausMobil)], [1, 0], 'aus dem Katalog: „fest“ kommt von der Vorlage');
    $kopie = (int) AnlagenService::kopieren($ausFest)['id'];
    $angelegt[] = $kopie;
    gleich($fest($kopie), 1, 'Kopie bleibt fest');
    $festeHier = array_column(AnlagenService::liste(['site_id' => $siteId, 'fest' => true]), 'name');
    $mobileHier = array_column(AnlagenService::liste(['site_id' => $siteId, 'fest' => false]), 'name');
    pruefe(in_array('Säule 1', $festeHier, true) && !in_array('Kasten 1', $festeHier, true) && in_array('Kasten 1', $mobileHier, true), 'Liste filtert nach fest und mobil');

    // Fest verlegte Kabel: nur zwischen Feststromanschlüssen
    $lFest = static fn (int $id): int => (int) Database::scalar('SELECT fest FROM leitungen WHERE id = ?', [$id]);
    $erd = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $ha, 'zu_objekt_id' => $ausFest,
        'kabeltyp' => 'NYY-J', 'querschnitt_mm2' => 35, 'punkte' => [[1, 1], [9, 9]]]);
    $mobKabel = LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $ausFest, 'zu_objekt_id' => $ausMobil, 'punkte' => [[9, 9], [20, 20]]]);
    pruefe($erd['ok'] && $mobKabel['ok'], 'Kabel angelegt');
    gleich([$lFest((int) $erd['id']), $lFest((int) $mobKabel['id'])], [1, 0], 'Vorgabe: zwischen Feststromanschlüssen fest, zu einer mobilen Anlage mobil');
    $falsch = LeitungService::speichern((int) $mobKabel['id'], ['art' => 'kabel', 'fest' => '1', 'floor_id' => $floorId, 'von_objekt_id' => $ausFest, 'zu_objekt_id' => $ausMobil]);
    pruefe(!$falsch['ok'] && str_contains($falsch['message'], 'Kasten 1'), 'festes Kabel an mobiler Anlage wird abgelehnt: ' . $falsch['message']);
    LeitungService::speichern((int) $erd['id'], ['art' => 'kabel', 'floor_id' => $floorId, 'von_objekt_id' => $ha, 'zu_objekt_id' => $ausFest, 'name' => 'Erdkabel', 'punkte' => [[1, 1], [9, 9]]]);
    gleich($lFest((int) $erd['id']), 1, 'Bearbeiten ohne Angabe lässt „fest“ stehen');
    $weg = LeitungService::speichern(null, ['art' => 'kabelweg', 'fest' => '1', 'floor_id' => $floorId, 'punkte' => [[1, 1], [2, 2]]]);
    $bruecke = LeitungService::speichern(null, ['art' => 'kabelbruecke', 'fest' => '1', 'floor_id' => $floorId, 'punkte' => [[3, 3], [4, 4]]]);
    gleich([$lFest((int) $weg['id']), $lFest((int) $bruecke['id'])], [1, 0], 'Kabelweg kann fest sein, Kabelbrücke bleibt mobil');

    // Alles bleibt veränderbar: Kabelenden umstecken und lösen
    $saeule2 = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Säule 2', 'floor_id' => $floorId])['id'];
    $angelegt[] = $saeule2;
    $erd2 = (int) LeitungService::speichern(null, ['art' => 'kabel', 'floor_id' => $floorId, 'name' => 'Erdkabel 2', 'kabeltyp' => 'NYY-J',
        'von_objekt_id' => $ha, 'zu_objekt_id' => $saeule2, 'punkte' => [[1, 1], [5, 5], [30, 30]]])['id'];
    gleich($lFest($erd2), 1, 'zweites Erdkabel fest');
    $um = LeitungService::endeSetzen($erd2, 'zu', $ausMobil, [[1, 1], [5, 5], [40, 40]]);
    $nach = LeitungService::find($erd2);
    pruefe($um['ok'] && (int) $nach['zu_objekt_id'] === $ausMobil && $nach['zu_anschluss_id'] === null && str_contains($um['message'], 'angeschlossen'),
        'Kabelende auf eine andere Anlage umgesteckt: ' . $um['message']);
    pruefe($nach['fest'] === 0 && str_contains($um['message'], 'mobil'), 'festes Kabel an mobiler Anlage wird mobil statt abgelehnt');
    gleich([$nach['kabeltyp'], $nach['name'], count($nach['punkte'])], ['NYY-J', 'Erdkabel 2', 3], 'Umstecken lässt Kabeltyp, Name und Verlauf stehen');
    $los = LeitungService::endeSetzen($erd2, 'zu', null, [[1, 1], [5, 5], [50, 50]]);
    pruefe($los['ok'] && LeitungService::find($erd2)['zu_objekt_id'] === null, 'Kabelende gelöst: ' . $los['message']);
    $einKasten = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'eingang' ORDER BY nr LIMIT 1", [$ausMobil]);
    if ($einKasten > 0) {
        LeitungService::endeSetzen($erd2, 'zu', $ausMobil, [[1, 1], [40, 40]]);
        LeitungService::speichern($erd2, ['zu_anschluss_id' => $einKasten] + LeitungService::find($erd2) + ['punkte' => [[1, 1], [40, 40]]]);
        LeitungService::endeSetzen($erd2, 'zu', $ausMobil, [[1, 1], [41, 41]]);
        gleich((int) LeitungService::find($erd2)['zu_anschluss_id'], $einKasten, 'an derselben Anlage bleibt der Anschluss stecken');
    }
    pruefe(!LeitungService::endeSetzen((int) $weg['id'], 'von', $ha, [[1, 1], [2, 2]])['ok'], 'ein Kabelweg hat keine Enden zum Umstecken');

    // Eine Anlage mit festem Kabel auf mobil stellen: geht, das Kabel wird mit mobil
    $mobil = AnlagenService::speichern($ausFest, ['art' => 'anschluss', 'name' => 'Säule 1', 'fest' => '0', 'floor_id' => $floorId]);
    pruefe($mobil['ok'] && $fest($ausFest) === 0 && str_contains($mobil['message'], 'jetzt mobil'), 'Anlage mit festem Kabel auf mobil gestellt: ' . $mobil['message']);
    gleich($lFest((int) $erd['id']), 0, '… das feste Kabel daran ist jetzt mobil');

    // Kraftstoffschlauch: ohne Anschlüsse und Querschnitt, nicht in Belegung, Speisung, Verteilbaum und Spannungsfall
    $tankA = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => 'Prüf-Tank A', 'floor_id' => $floorId])['id'];
    $tankB = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => 'Prüf-Tank B', 'floor_id' => $floorId])['id'];
    $genA = (int) AnlagenService::speichern(null, ['art' => 'generator', 'name' => 'Prüf-Aggregat', 'floor_id' => $floorId])['id'];
    array_push($angelegt, $tankA, $tankB, $genA);
    $genAb = (int) Database::scalar("SELECT id FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr LIMIT 1", [$genA]);
    $s = LeitungService::speichern(null, ['art' => 'kraftstoff', 'floor_id' => $floorId, 'von_objekt_id' => $tankA, 'zu_objekt_id' => $genA,
        'von_anschluss_id' => $genAb, 'zu_anschluss_id' => $genAb, 'querschnitt_mm2' => 16, 'adern' => 5, 'laenge_m' => 500, 'anzahl' => 1, 'punkte' => [[1, 1], [5, 5]]]);
    $schlauch = LeitungService::find((int) ($s['id'] ?? 0));
    pruefe($s['ok'] && $schlauch['von_anschluss_id'] === null && $schlauch['zu_anschluss_id'] === null && $schlauch['querschnitt_mm2'] === null && $schlauch['adern'] === null,
        'Schlauch ohne Anschluss-IDs, Querschnitt und Adern – auch wenn sie mitkommen');
    pruefe(!array_filter($schlauch['hinweise'], static fn ($h) => str_contains($h, 'Spannungsfall') || str_contains($h, 'Querschnitt')), 'kein Spannungsfall am Schlauch (500 m)');
    pruefe(in_array(true, array_map(static fn ($h) => str_contains($h, 'Rücklauf fehlt?'), $schlauch['hinweise']), true), 'nur Vorlauf: „Rücklauf fehlt?“');
    gleich([AnlagenService::belegung($genA), AnlagenService::speisung($genA), AnlagenService::speisung($tankA)], [[], [], []], 'Schlauch nicht in Belegung und Speisung');
    $knoten = [];
    $sammeln = static function (array $liste) use (&$sammeln, &$knoten): void {
        foreach ($liste as $k) {
            $knoten[(int) $k['id']] = $k['kabel']['art'] ?? null;
            $sammeln($k['kinder']);
        }
    };
    $sammeln(AnlagenService::baum($siteId));
    pruefe(array_key_exists($genA, $knoten) && $knoten[$genA] === null && !array_key_exists($tankA, $knoten), 'Verteilbaum: Aggregat ohne Schlauch als Zuleitung, Tank nicht darin');
    gleich(array_column(AnlagenService::anschluesse($genA), 'leitung'), array_fill(0, count(AnlagenService::anschluesse($genA)), null), 'kein Anschluss des Aggregats belegt');
    $s2 = LeitungService::speichern(null, ['art' => 'kraftstoff', 'floor_id' => $floorId, 'von_objekt_id' => $tankB, 'zu_objekt_id' => $genA, 'punkte' => [[2, 2], [5, 5]]]);
    pruefe($s2['ok'] && array_filter(LeitungService::find((int) $s2['id'])['hinweise'], static fn ($h) => str_contains($h, 'Rücklauf muss in denselben Tank')) !== [],
        'Aggregat an zwei Tanks: „Rücklauf muss in denselben Tank“');
    pruefe(!LeitungService::speichern(null, ['art' => 'kraftstoff', 'floor_id' => $floorId, 'von_objekt_id' => $tankA, 'zu_objekt_id' => $ausMobil, 'punkte' => [[1, 1], [2, 2]]])['ok'],
        'Schlauch Tank → Verteilung abgelehnt');
    AnlagenService::loeschen($genA);
    $angelegt = array_values(array_diff($angelegt, [$genA]));
    $offen = LeitungService::find((int) $s['id']);
    pruefe($offen !== null && $offen['zu_objekt_id'] === null && in_array('Ein Ende ist offen – den Schlauch an Tank und Generator ziehen.', $offen['hinweise'], true),
        'Aggregat gelöscht: der Schlauch bleibt mit offenem Ende und Hinweis');
    Database::execute("DELETE FROM leitungen WHERE art = 'kraftstoff' AND id IN (?, ?)", [(int) $s['id'], (int) $s2['id']]);

    // Art ändern: Anschlüsse bleiben, Angaben der alten Art fallen weg
    $vorher = AnlagenService::anzahlen($ausMobil);
    Database::update('objekte', $ausMobil, ['eigenschaften' => json_encode(['anschlussart' => 'Baustromverteiler'])]);
    $art = AnlagenService::speichern($ausMobil, ['art' => 'verteilung', 'name' => 'Kasten 1', 'floor_id' => $floorId, 'eigenschaften' => ['schutzart' => 'IP54']]);
    $k1 = AnlagenService::find($ausMobil);
    pruefe($art['ok'] && $k1['art'] === 'verteilung' && str_contains($art['message'], 'Verteilung'), 'Art geändert: ' . $art['message']);
    gleich([AnlagenService::anzahlen($ausMobil), $k1['eigenschaften']], [$vorher, ['schutzart' => 'IP54']], 'Anschlüsse bleiben, Angaben passen zur neuen Art');

    // Belegung aus einer Datei ersetzen (Location- und Verteiler-Abgleich,
    // Schnittstelle): Anschlüsse und Schutzorgane entstehen neu – was hier
    // daran hängt, zeigt danach auf den neuen an derselben Stelle.
    $bel = (int) AnlagenService::speichern(null, ['art' => 'anschluss', 'name' => 'Prüf-Belegung', 'fest' => 1, 'floor_id' => $floorId, 'ohne_anschluesse' => true])['id'];
    $angelegt[] = $bel;
    AnlagenService::anzahlSetzen($bel, ['eingang' => [$typ['cee63'] => 1], 'abgang' => [$typ['cee32'] => 2, $typ['cee16'] => 2]]);
    SchutzService::vorschlagen($bel);
    $benutzt = [];
    $belegung = LocationBundle::belegungLesen($bel, AnlagenService::steckertypenNachId(), $benutzt);
    $abg = Database::select("SELECT id, nr, schutzorgan_id FROM anschluesse WHERE objekt_id = ? AND richtung = 'abgang' ORDER BY nr", [$bel]);
    pruefe(count($abg) === 4 && $abg[0]['schutzorgan_id'] !== null, 'Prüfanlage mit vier abgesicherten Abgängen');
    $anRef = static function (mixed $id) use ($bel): ?string {
        if ($id === null) {
            return null;
        }
        $r = Database::first('SELECT richtung, nr FROM anschluesse WHERE id = ? AND objekt_id = ?', [(int) $id, $bel]);
        return $r === null ? 'zeigt ins Leere' : $r['richtung'] . ':' . (int) $r['nr'];
    };
    $schutzRef = static function (mixed $id) use ($bel): ?string {
        if ($id === null) {
            return null;
        }
        foreach (SchutzService::liste($bel) as $n => $s) {
            if ((int) $s['id'] === (int) $id) {
                return 's' . ($n + 1);
            }
        }
        return 'zeigt ins Leere';
    };
    $geraet = 'shellypro3em-belegung-' . bin2hex(random_bytes(3));
    $mJetzt = (int) MesspunktService::speichern($bel, null, ['geraet' => $geraet, 'kanal' => 'em:0',
        'anschluss_id' => (int) $abg[0]['id'], 'schutzorgan_id' => (int) $abg[0]['schutzorgan_id']])['id'];
    $mFrueher = Database::insert('messpunkte', ['objekt_id' => $bel, 'anschluss_id' => (int) $abg[1]['id'], 'schutzorgan_id' => null,
        'geraet' => $geraet . '-alt', 'kanal' => 'em:0', 'rolle' => 'abgang', 'bezeichnung' => '',
        'gueltig_ab' => '2026-01-01T00:00:00+00:00', 'gueltig_bis' => '2026-02-01T00:00:00+00:00', 'created_at' => gmdate('c')]);
    $mWeg = (int) MesspunktService::speichern($bel, null, ['geraet' => $geraet . '-weg', 'kanal' => 'em:0', 'anschluss_id' => (int) $abg[3]['id']])['id'];
    $inventarId = (int) InventarService::speichern(null, ['art' => 'zwischenstecker', 'kennung' => 'PRUEF-BEL-' . bin2hex(random_bytes(3)), 'steckertyp_id' => $typ['cee16'],
        'messgeraete' => [['geraet' => $geraet . '-zs', 'kanal' => 'em:0', 'stelle' => 'durchgang']]])['id'];
    $einsatz = InventarService::einsetzen($inventarId, $bel, (int) $abg[2]['id']);
    pruefe($einsatz['ok'], 'Zwischenstecker an Abgang ' . (int) $abg[2]['nr'] . ' eingesetzt: ' . $einsatz['message']);
    $mEinsatz = (int) Database::scalar('SELECT id FROM messpunkte WHERE objekt_id = ? AND einsatz_id = ?', [$bel, (int) ($einsatz['einsatz_id'] ?? 0)]);
    $vorher = [
        'jetzt'   => [$anRef(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mJetzt])), $schutzRef(Database::scalar('SELECT schutzorgan_id FROM messpunkte WHERE id = ?', [$mJetzt]))],
        'frueher' => $anRef(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mFrueher])),
        'einsatz' => $anRef(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mEinsatz])),
        'eintrag' => $anRef(Database::scalar('SELECT anschluss_id FROM einsaetze WHERE id = ?', [(int) ($einsatz['einsatz_id'] ?? 0)])),
    ];
    pruefe($mEinsatz > 0 && $vorher['einsatz'] === 'abgang:' . (int) $abg[2]['nr'] && $vorher['jetzt'][1] !== null, 'vorher: Messpunkte und Einsatz an ihren Abgängen');
    $alteIds = array_map(static fn ($r) => (int) $r['id'], $abg);

    // Die Datei ohne den vierten Abgang, Messpunkte nennt sie nicht (bleiben hier)
    $ohneVierten = array_values(array_filter($belegung['anschluesse'], static fn ($x) => !($x['richtung'] === 'abgang' && (int) $x['nr'] === (int) $abg[3]['nr'])));
    $neu = LocationBundle::belegungErsetzen($bel, ['schutzorgane' => $belegung['schutzorgane'], 'anschluesse' => $ohneVierten],
        LocationBundle::steckertypenSichern(LocationBundle::steckertypenFuerDatei($benutzt)));
    pruefe(array_intersect($alteIds, array_values($neu)) === [] && count($neu) === count($ohneVierten), 'Anschlüsse neu angelegt');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte WHERE objekt_id = ?', [$bel]), 4, 'Messpunkte bleiben (Datei ohne Messpunkte)');
    $nachher = [
        'jetzt'   => [$anRef(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mJetzt])), $schutzRef(Database::scalar('SELECT schutzorgan_id FROM messpunkte WHERE id = ?', [$mJetzt]))],
        'frueher' => $anRef(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mFrueher])),
        'einsatz' => $anRef(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mEinsatz])),
        'eintrag' => $anRef(Database::scalar('SELECT anschluss_id FROM einsaetze WHERE id = ?', [(int) ($einsatz['einsatz_id'] ?? 0)])),
    ];
    gleich($nachher['jetzt'], $vorher['jetzt'], 'Messpunkt: derselbe Abgang und dasselbe Schutzorgan (sN)');
    gleich($nachher['frueher'], $vorher['frueher'], 'früherer Messpunkt: derselbe Abgang');
    gleich($nachher['einsatz'], $vorher['einsatz'], 'Messpunkt des Zwischensteckers: derselbe Abgang');
    gleich($nachher['eintrag'], $vorher['eintrag'], 'Einsatz: derselbe Abgang');
    pruefe(Database::scalar('SELECT anschluss_id FROM messpunkte WHERE id = ?', [$mWeg]) === null, 'Messpunkt am entfallenen Abgang: Verweis leer statt ins Leere');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte m LEFT JOIN anschluesse a ON a.id = m.anschluss_id
        WHERE m.objekt_id = ? AND m.anschluss_id IS NOT NULL AND a.id IS NULL', [$bel]), 0, 'kein Messpunkt zeigt auf einen gelöschten Anschluss');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM messpunkte m LEFT JOIN schutzorgane s ON s.id = m.schutzorgan_id
        WHERE m.objekt_id = ? AND m.schutzorgan_id IS NOT NULL AND s.id IS NULL', [$bel]), 0, 'kein Messpunkt zeigt auf ein gelöschtes Schutzorgan');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM einsaetze e LEFT JOIN anschluesse a ON a.id = e.anschluss_id
        WHERE e.objekt_id = ? AND e.anschluss_id IS NOT NULL AND a.id IS NULL', [$bel]), 0, 'kein Einsatz zeigt auf einen gelöschten Anschluss');
} finally {
    if ($inventarId !== null) {
        foreach (Database::select('SELECT id FROM einsaetze WHERE inventar_id = ? AND bis IS NULL', [$inventarId]) as $e) {
            InventarService::beenden((int) $e['id']);
        }
        InventarService::loeschen($inventarId);
    }
    foreach ($angelegt as $id) {
        AnlagenService::loeschen((int) $id);
    }
    SiteService::deleteSite($siteId);
    Database::execute("DELETE FROM audit_log WHERE username = 'system' AND action LIKE 'schutz.%'");
}

ende('anschluesse');
