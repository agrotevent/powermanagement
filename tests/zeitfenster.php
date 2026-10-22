<?php
declare(strict_types=1);

/**
 * Rechte mit Zeitfenstern: Freigaben, Datenzeitraum, Rollen auf Zeit,
 * Kontoablauf und Schalt-Freigaben je Gerät und Kanal.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Kunden\Kundenbereich;
use App\Messung\Datenquellen;
use App\Messung\Messdaten;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\SiteService;
use App\Support\Database;
use App\Support\Zeit;

// ------------------------------------------------ reine Rechenregeln
gleich(Zugriff::vereinigen([]), [], 'keine Freigabe → nichts sichtbar');
gleich(Zugriff::vereinigen([[null, null]]), null, 'ganz offene Freigabe → ohne Grenze');
gleich(Zugriff::vereinigen([[100, 200], [150, 300], [500, 600]]), [[100, 300], [500, 600]], 'überlappende Fenster werden vereinigt');
gleich(Zugriff::vereinigen([[100, null], [50, 60]]), [[50, 60], [100, null]], 'offenes Ende bleibt offen');
gleich(Zugriff::begrenzen([[100, 300], [500, 600]], 0, 1000), [[100, 300], [500, 600]], 'Zeitraum wird auf die Fenster zugeschnitten');
gleich(Zugriff::begrenzen([[100, 300]], 200, 250), [[200, 250]], 'Teil im Fenster bleibt');
gleich(Zugriff::begrenzen([[100, 300]], 400, 500), [], 'außerhalb: nichts');
gleich(Zugriff::begrenzen(null, 10, 20), [[10, 20]], 'ohne Grenze: der ganze Zeitraum');
pruefe(Zugriff::liveErlaubt([[time() - 10, time() + 10]]), 'Live im Fenster erlaubt');
pruefe(!Zugriff::liveErlaubt([[time() - 100, time() - 10]]), 'Live nach Ende des Datenzeitraums gesperrt');

// ------------------------------------------------ Testbestand
$jetzt = time();
$siteId = SiteService::saveSite(null, ['name' => 'Prüfung Zeitfenster ' . bin2hex(random_bytes(3))]);
$floorId = SiteService::saveFloor(null, ['site_id' => $siteId, 'name' => 'Fläche']);
$anderer = SiteService::saveSite(null, ['name' => 'Prüfung anderer Standort ' . bin2hex(random_bytes(3))]);
$objekt = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Prüfverteiler', 'floor_id' => $floorId])['id'];
$fremd = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => 'Fremder Verteiler', 'site_id' => $anderer])['id'];
$quelle = (int) Datenquellen::speichern(null, ['art' => 'shelly', 'name' => 'Prüf-Shelly', 'geraet' => 'pruef-shelly-' . bin2hex(random_bytes(3)), 'extern_id' => 'pruef', 'profil' => 'gen2', 'aktiv' => 1])['id'];
$q = Datenquellen::find($quelle);
Database::insert('messpunkte', ['objekt_id' => $objekt, 'geraet' => $q['geraet'], 'kanal' => 'switch:0', 'rolle' => 'abgang', 'bezeichnung' => '', 'created_at' => gmdate('c')]);

$name = 'pruef-' . bin2hex(random_bytes(3));
$userId = BenutzerService::anlegen($name, 'Pruefung2026!!', 'kunde', false);
$o = AnlagenService::find($objekt);
$of = AnlagenService::find($fremd);
$freigabe = static function (array $daten) use ($userId): int {
    return (int) Database::insert('freigaben', $daten + [
        'subject_type' => 'user', 'subject_id' => $userId, 'ziel_kanal' => '', 'darf_bearbeiten' => 0, 'darf_steuern' => 0,
        'gueltig_ab' => null, 'gueltig_bis' => null, 'daten_ab' => null, 'daten_bis' => null, 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c'),
    ]);
};

try {
    alsBenutzer($userId);
    pruefe(!Zugriff::alles(), 'Kunde sieht nicht alles');
    pruefe(!Zugriff::siehtObjekt($o), 'ohne Freigabe unsichtbar');

    // Freigabe in der Vergangenheit: wirkt nicht
    $f1 = $freigabe(['ziel_art' => 'site', 'ziel_id' => $siteId, 'gueltig_ab' => Zeit::iso($jetzt - 10 * 86400), 'gueltig_bis' => Zeit::iso($jetzt - 86400)]);
    Zugriff::vergessen();
    pruefe(!Zugriff::siehtObjekt($o), 'abgelaufene Freigabe wirkt nicht');
    pruefe(Zugriff::siehtObjekt($o) === false && count(Zugriff::freigaben($userId, $jetzt - 2 * 86400)) === 1, 'zu ihrer Zeit hätte sie gewirkt');
    Database::delete('freigaben', $f1);

    // Freigabe jetzt, Datenzeitraum zwei Tage zurück bis morgen
    $f2 = $freigabe(['ziel_art' => 'site', 'ziel_id' => $siteId, 'gueltig_ab' => Zeit::iso($jetzt - 86400), 'gueltig_bis' => Zeit::iso($jetzt + 86400),
        'daten_ab' => Zeit::iso($jetzt - 2 * 86400), 'daten_bis' => Zeit::iso($jetzt + 86400)]);
    Zugriff::vergessen();
    pruefe(Zugriff::siehtObjekt($o), 'wirksame Standortfreigabe macht die Anlage sichtbar');
    pruefe(!Zugriff::siehtObjekt($of), 'anderer Standort bleibt unsichtbar');
    pruefe(Zugriff::siehtStandort($siteId) && !Zugriff::siehtStandort($anderer), 'Standortsicht folgt der Freigabe');
    pruefe(Zugriff::siehtGanzenStandort($siteId) && !Zugriff::siehtGanzenStandort($anderer), 'Standortfreigabe zeigt den ganzen Standort');
    $fenster = Zugriff::datenFenster($o);
    gleich($fenster, [[$jetzt - 2 * 86400, $jetzt + 86400]], 'Datenfenster aus der Freigabe');
    gleich(Zugriff::begrenzen($fenster, $jetzt - 30 * 86400, $jetzt), [[$jetzt - 2 * 86400, $jetzt]], '30-Tage-Abfrage wird auf zwei Tage gekürzt');
    pruefe(!Zugriff::darfBearbeiten($o), 'Kunde darf nicht bearbeiten');
    pruefe(!Zugriff::darfSteuern($q, 'switch:0', $o), 'Kunde darf nicht schalten');

    // Messdaten (Demo-Modus) respektieren das Fenster
    if (\App\Support\Config::isDemo()) {
        $reihe = Messdaten::reihe('strom', 'shellypro3em-buehne', 'em:0', 'p', ['sum'], $jetzt - 10 * 86400, $jetzt, $fenster, 3600);
        $erster = $reihe['zeiten'][0] ?? 0;
        pruefe($erster >= ($jetzt - 2 * 86400) * 1000, 'kein Messwert vor dem Datenzeitraum');
    }

    // Datenzeitraum vorbei → keine Livewerte
    Database::update('freigaben', $f2, ['daten_bis' => Zeit::iso($jetzt - 3600)]);
    Zugriff::vergessen();
    pruefe(!Zugriff::liveErlaubt(Zugriff::datenFenster($o)), 'nach Ende des Datenzeitraums keine Livewerte');
    Database::delete('freigaben', $f2);

    // Einzelgerät: Schalten nur auf Kanal switch:1, nur mit Recht „steuern“
    $f3 = $freigabe(['ziel_art' => 'geraet', 'ziel_id' => $quelle, 'ziel_kanal' => 'switch:1', 'darf_steuern' => 1]);
    Zugriff::vergessen();
    pruefe(!Zugriff::darfSteuern($q, 'switch:1', $o), 'ohne Recht „steuern“ kein Schalten trotz Freigabe');
    $technik = (int) Database::scalar("SELECT id FROM roles WHERE role_key = 'technik'");
    Database::insert('user_roles', ['user_id' => $userId, 'role_id' => $technik, 'gueltig_ab' => null, 'gueltig_bis' => Zeit::iso($jetzt + 3600)]);
    alsBenutzer($userId);
    pruefe(Auth::can('steuern'), 'Rolle Technik (bis in einer Stunde) gibt das Recht');
    pruefe(Zugriff::darfSteuern($q, 'switch:1', $o), 'Kanal switch:1 darf geschaltet werden');
    pruefe(!Zugriff::darfSteuern($q, 'switch:0', $o), 'Kanal switch:0 nicht');
    Database::update('objekte', $objekt, ['steuerung_gesperrt' => 1]);
    Zugriff::vergessen();
    pruefe(!Zugriff::darfSteuern($q, 'switch:1', AnlagenService::find($objekt)), 'gesperrte Anlage: kein Schalten');
    // Die Anlage aus der Anfrage zählt nur, wenn das Gerät dort misst – sonst
    // ließe sich die Sperre mit einer anderen Anlage umgehen …
    pruefe(!Zugriff::darfSteuern($q, 'switch:1', $of), 'Sperre nicht über eine fremde Anlage zu umgehen');
    Database::update('objekte', $objekt, ['steuerung_gesperrt' => 0]);
    Zugriff::vergessen();
    // … und eine Schalt-Freigabe für irgendeine Anlage schaltete jedes Gerät.
    Database::delete('freigaben', $f3);
    $f4 = $freigabe(['ziel_art' => 'objekt', 'ziel_id' => $fremd, 'darf_steuern' => 1]);
    Zugriff::vergessen();
    pruefe(Zugriff::siehtStandort($anderer) && !Zugriff::siehtGanzenStandort($anderer), 'einzeln freigegebene Anlage zeigt den Standort, aber nicht den ganzen');
    pruefe(!Zugriff::darfSteuern($q, 'switch:0', $of), 'Freigabe für eine Anlage schaltet kein Gerät, das dort nicht misst');
    pruefe(!Zugriff::darfSteuern($q, 'switch:0'), 'ohne Anlage: Gerät misst nicht an der freigegebenen');
    Database::delete('freigaben', $f4);
    $f3 = $freigabe(['ziel_art' => 'geraet', 'ziel_id' => $quelle, 'ziel_kanal' => 'switch:1', 'darf_steuern' => 1]);
    Zugriff::vergessen();

    // Rolle abgelaufen → Recht weg
    Database::execute('UPDATE user_roles SET gueltig_bis = ? WHERE user_id = ? AND role_id = ?', [Zeit::iso($jetzt - 60), $userId, $technik]);
    alsBenutzer($userId);
    pruefe(!Auth::can('steuern'), 'abgelaufene Rolle gibt kein Recht mehr');
    Database::delete('freigaben', $f3);

    // Rolle aus einer SSO-Gruppe (quelle = 'sso', gilt bis Ende der SSO-Sitzung):
    // dieselbe Gültigkeit – für das Recht und für Freigaben an der Rolle.
    Database::execute('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $technik]);
    Database::insert('user_roles', ['user_id' => $userId, 'role_id' => $technik, 'gueltig_ab' => null, 'gueltig_bis' => Zeit::iso($jetzt + 3600), 'quelle' => 'sso']);
    $fRolle = (int) Database::insert('freigaben', ['subject_type' => 'role', 'subject_id' => $technik, 'ziel_art' => 'site', 'ziel_id' => $siteId,
        'ziel_kanal' => '', 'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'notiz' => $name, 'created_by' => 'test', 'created_at' => gmdate('c')]);
    alsBenutzer($userId);
    pruefe(Auth::can('steuern') && Zugriff::siehtStandort($siteId), 'Rolle aus einer SSO-Gruppe: Recht und Freigabe an der Rolle gelten');
    Database::execute("UPDATE user_roles SET gueltig_bis = ? WHERE user_id = ? AND quelle = 'sso'", [Zeit::iso($jetzt - 60), $userId]);
    alsBenutzer($userId);
    pruefe(!Auth::can('steuern') && !Zugriff::siehtStandort($siteId), 'Rolle aus einer SSO-Gruppe abgelaufen: weder Recht noch Freigabe an der Rolle');
    Database::delete('freigaben', $fRolle);

    // Kontoablauf
    $user = Database::first('SELECT * FROM users WHERE id = ?', [$userId]);
    pruefe(Auth::kontoZeitraum($user) === null, 'unbefristetes Konto gilt');
    pruefe(Auth::kontoZeitraum(array_merge($user, ['gueltig_bis' => Zeit::iso($jetzt - 1)])) !== null, 'abgelaufenes Konto gilt nicht');
    pruefe(Auth::kontoZeitraum(array_merge($user, ['gueltig_ab' => Zeit::iso($jetzt + 3600)])) !== null, 'künftiges Konto gilt noch nicht');
    Database::update('users', $userId, ['gueltig_bis' => Zeit::iso($jetzt - 1)]);
    $versuch = Auth::attempt($name, 'Pruefung2026!!', '127.0.0.1');
    pruefe(!$versuch['ok'] && str_contains($versuch['message'], 'abgelaufen'), 'Anmeldung nach Ablauf abgelehnt');

    // Kundenzugang (Kundenbereich): Freigabe am Kunden mit Gültigkeit und Datenzeitraum
    $kundeId = Database::insert('kunden', ['name' => 'Prüfung Zeitfenster ' . $name, 'aktiv' => 1, 'created_at' => gmdate('c')]);
    $kundenzugang = Database::insert('users', ['username' => $name . '-kz', 'full_name' => '', 'email' => '',
        'password_hash' => password_hash('Pruefung2026!!', PASSWORD_DEFAULT), 'is_active' => 1, 'kunde_id' => $kundeId, 'created_at' => gmdate('c')]);
    $kf = Database::insert('freigaben', ['subject_type' => 'kunde', 'subject_id' => $kundeId, 'ziel_art' => 'site', 'ziel_id' => $siteId,
        'ziel_kanal' => '', 'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'notiz' => '', 'created_by' => 'test', 'created_at' => gmdate('c'),
        'gueltig_ab' => Zeit::iso($jetzt + 86400), 'gueltig_bis' => Zeit::iso($jetzt + 3 * 86400),
        'daten_ab' => Zeit::iso($jetzt + 86400), 'daten_bis' => Zeit::iso($jetzt + 2 * 86400)]);
    alsBenutzer($kundenzugang);
    pruefe(!Zugriff::siehtStandort($siteId), 'Kunden-Freigabe wirkt nicht vor gueltig_ab');
    gleich(count(Kundenbereich::freigaben($kundeId, $jetzt + 2 * 86400)), 1, 'Vorschau „übermorgen“: wirksam');
    gleich(count(Kundenbereich::freigaben($kundeId, $jetzt + 4 * 86400)), 0, 'Vorschau nach gueltig_bis: nicht mehr');
    pruefe(!Zugriff::siehtStandort($siteId) && Kundenbereich::freigaben($kundeId) === [], 'die Vorschau ändert den Zwischenspeicher nicht');
    gleich(count(Zugriff::freigaben($kundenzugang, $jetzt + 2 * 86400)), 1, 'Zugriff::freigaben() mit eigener Uhrzeit für den Kundenzugang');
    Database::update('freigaben', $kf, ['gueltig_ab' => Zeit::iso($jetzt - 86400), 'daten_ab' => Zeit::iso($jetzt - 86400)]);
    Zugriff::vergessen();
    pruefe(Zugriff::siehtStandort($siteId) && Zugriff::siehtObjekt($o) && !Zugriff::siehtObjekt($of), 'ab gueltig_ab sichtbar: Standort und seine Anlage');
    gleich(Zugriff::datenFensterStandort($siteId), [[$jetzt - 86400, $jetzt + 2 * 86400]], 'datenFensterStandort = Fenster der Kunden-Freigabe');
    gleich(Zugriff::datenFenster($o), [[$jetzt - 86400, $jetzt + 2 * 86400]], 'datenFenster der Anlage ebenso');
    pruefe(!Zugriff::darfBearbeiten($o) && !Zugriff::darfSteuern($q, 'switch:0', $o), 'Kundenzugang: weder bearbeiten noch schalten');
    Database::update('freigaben', $kf, ['gueltig_bis' => Zeit::iso($jetzt - 60)]);
    Zugriff::vergessen();
    pruefe(!Zugriff::siehtStandort($siteId), 'nach gueltig_bis unsichtbar');
    Database::update('freigaben', $kf, ['subject_type' => 'user', 'subject_id' => $userId, 'gueltig_bis' => Zeit::iso($jetzt + 86400)]);
    alsBenutzer($userId);
    pruefe(Zugriff::siehtStandort($siteId) && Zugriff::datenFensterStandort($siteId) === [[$jetzt - 86400, $jetzt + 2 * 86400]],
        'Konto ohne kunde_id: Freigaben am Konto wie bisher');
    Database::delete('freigaben', $kf);
    Database::execute('DELETE FROM users WHERE id = ?', [$kundenzugang]);
    Database::delete('kunden', $kundeId);
    alsBenutzer($userId);

    // Formular-Umrechnung: „bis 24.09.“ heißt bis Tagesende Ortszeit
    $bis = Zeit::ausEingabe('2026-09-24', true);
    gleich(Zeit::anzeige($bis, 'd.m.Y H:i:s'), '24.09.2026 23:59:59', 'Enddatum schließt den Tag ein');
} finally {
    alsBenutzer(null);
    Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$userId]);
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
    Database::execute('DELETE FROM users WHERE id = ?', [$userId]);
    Database::execute("DELETE FROM audit_log WHERE username = ?", [$name]);
    foreach (Database::select('SELECT id FROM kunden WHERE name = ?', ['Prüfung Zeitfenster ' . $name]) as $k) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'kunde' AND subject_id = ?", [(int) $k['id']]);
        Database::execute('DELETE FROM users WHERE kunde_id = ?', [(int) $k['id']]);
        Database::delete('kunden', (int) $k['id']);
    }
    AnlagenService::loeschen($objekt);
    AnlagenService::loeschen($fremd);
    Datenquellen::loeschen($quelle);
    SiteService::deleteSite($siteId);
    SiteService::deleteSite($anderer);
}

ende('zeitfenster');
