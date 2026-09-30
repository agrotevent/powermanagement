<?php
declare(strict_types=1);

/**
 * Sicherheit: was die Sicherheitsprüfung gefunden hat, bleibt behoben.
 *
 * Rechte nur vergeben, wer sie hat; Anmeldung ohne Kontoerkennung und mit
 * Bremse je Adresse; Geräte nur zuordnen, wo man bearbeiten darf, und dann
 * ohne fremde Vorgeschichte; Schalt-Freigaben für einen Kanal gelten nur für
 * ihn; am Standort ändern braucht „bearbeiten“; Schlüssel aus dem Repository
 * werden keine Pfade; Namen brechen kein Line Protocol; CSV ohne Formeln;
 * nur http(s); kein öffentlicher Schlüssel; PDF-Werkzeuge hängen nicht.
 *
 * Die Prüfungen über HTTP (Weiterleitungen, Sitzung, Token, Benutzer-
 * verwaltung) stehen in web.php.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\Auth;
use App\Auth\Zugriff;
use App\Controllers\StatistikController;
use App\Messung\Datenquellen;
use App\Messung\Http;
use App\Messung\Influx;
use App\Services\AnlagenService;
use App\Services\BenutzerService;
use App\Services\LocationBundle;
use App\Services\LocationSync;
use App\Services\MesspunktService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Response;

$k = 'pm-sich-' . bin2hex(random_bytes(3));
$ip = '198.51.100.' . random_int(1, 254);
$benutzer = [];
$rollen = [];
$sites = [];
$quellen = [];
$alterSchluessel = Config::get('app.key');

try {
    // ------------------------------------------------ Rechte vergeben
    $rollen['verwalter'] = (int) BenutzerService::rolleSpeichern(null, $k . ' verwalter', '', ['system.users.manage', 'dashboard.view'])['id'];
    $rollen['klein'] = (int) BenutzerService::rolleSpeichern(null, $k . ' klein', '', ['dashboard.view'])['id'];
    $rolleKey = static fn (int $id): string => (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$id]);
    $benutzer['verwalter'] = BenutzerService::anlegen($k . '-verwalter', 'Pruefung2026!!', $rolleKey($rollen['verwalter']), false);
    $benutzer['klein'] = BenutzerService::anlegen($k . '-klein', 'Pruefung2026!!', $rolleKey($rollen['klein']), false);
    $benutzer['admin'] = BenutzerService::anlegen($k . '-admin', 'Pruefung2026!!', 'admin', false);
    $benutzer['technik'] = BenutzerService::anlegen($k . '-technik', 'Pruefung2026!!', 'technik', false);

    alsBenutzer($benutzer['verwalter']);
    pruefe(!BenutzerService::darfVergeben(['*']), 'ohne Administration kein „*“');
    pruefe(!BenutzerService::darfVergeben(['anlagen.scope.all']), 'kein Recht, das man selbst nicht hat');
    pruefe(BenutzerService::darfVergeben(['dashboard.view', 'system.users.manage']), 'eigene Rechte darf man vergeben');
    pruefe(!BenutzerService::darfVerwalten($benutzer['admin']), 'den Administrator verwaltet nur die Administration');
    pruefe(!BenutzerService::darfVerwalten($benutzer['technik']), 'ein Konto mit mehr Rechten nicht');
    pruefe(BenutzerService::darfVerwalten($benutzer['klein']), 'ein Konto mit weniger Rechten schon');
    pruefe(!BenutzerService::darfFreigabeVergeben('alles', false) && !BenutzerService::darfFreigabeVergeben('site', true)
        && !BenutzerService::darfFreigabeVergeben('site', false), 'Freigaben nur, wer selbst alles sieht; „alles“ und „schalten“ nur die Administration');
    alsBenutzer($benutzer['admin']);
    pruefe(BenutzerService::darfVergeben(['*']) && BenutzerService::darfVerwalten($benutzer['technik'])
        && BenutzerService::darfFreigabeVergeben('alles', true), 'die Administration darf alles vergeben');
    alsBenutzer(null);

    // ------------------------------------------------ Anmeldung
    $unbekannt = $k . '-niemand';
    $meldungen = [];
    for ($i = 0; $i < 6; $i++) {
        $meldungen[] = Auth::attempt($unbekannt, 'falsch-' . $i, $ip)['message'];
    }
    pruefe(str_starts_with($meldungen[5], 'Konto gesperrt'), 'auch ein unbekanntes Konto ist nach zu vielen Versuchen „gesperrt“ – die Sperre verrät keine Namen');
    $gesperrt = $k . '-gesperrt';
    $benutzer['gesperrt'] = BenutzerService::anlegen($gesperrt, 'Pruefung2026!!', 'kunde', false);
    $meldungen = [];
    for ($i = 0; $i < 6; $i++) {
        $meldungen[] = Auth::attempt($gesperrt, 'falsch-' . $i, $ip)['message'];
    }
    gleich([$meldungen[0], substr($meldungen[5], 0, 14)], ['Benutzername oder Passwort falsch.', 'Konto gesperrt'], 'bekanntes Konto: dieselben Meldungen');
    Database::update('users', $benutzer['klein'], ['is_active' => 0]);
    gleich(Auth::attempt($k . '-klein', 'falsch', '198.51.100.0')['message'], 'Benutzername oder Passwort falsch.', 'deaktiviert: mit falschem Kennwort nur die allgemeine Meldung');
    Database::update('users', $benutzer['klein'], ['is_active' => 1]);
    for ($i = 0; $i < 20; $i++) {
        Auth::attempt($k . '-kette-' . $i, 'falsch', $ip);
    }
    pruefe(str_starts_with(Auth::attempt($k . '-kette-x', 'falsch', $ip)['message'], 'Zu viele fehlgeschlagene Anmeldungen von dieser Adresse'),
        'viele Fehlversuche von einer Adresse bremsen sie');
    pruefe(Response::sichererPfad('/karte?etage=3') === '/karte?etage=3' && Response::sichererPfad('/\\evil.example') === null
        && Response::sichererPfad("/\t/evil.example") === null && Response::sichererPfad('//evil.example') === null
        && Response::sichererPfad('https://evil.example') === null, 'Weiterleitungen nur auf eigene Pfade');

    // ------------------------------------------------ Geräte zuordnen und schalten
    $sites['a'] = SiteService::saveSite(null, ['name' => $k . ' A']);
    $sites['b'] = SiteService::saveSite(null, ['name' => $k . ' B']);
    $etageA = SiteService::saveFloor(null, ['site_id' => $sites['a'], 'name' => 'Halle']);
    $etageB = SiteService::saveFloor(null, ['site_id' => $sites['b'], 'name' => 'Halle']);
    $oA = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $k . ' eigen', 'floor_id' => $etageA, 'ohne_anschluesse' => true])['id'];
    $oB = (int) AnlagenService::speichern(null, ['art' => 'verteilung', 'name' => $k . ' fremd', 'floor_id' => $etageB, 'ohne_anschluesse' => true])['id'];
    MesspunktService::speichern($oB, null, ['geraet' => $k . '-fremd', 'kanal' => 'switch:0']);
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['technik'], 'ziel_art' => 'objekt', 'ziel_id' => $oA, 'darf_bearbeiten' => 1]);
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['technik'], 'ziel_art' => 'site', 'ziel_id' => $sites['b']]);
    alsBenutzer($benutzer['technik']);
    pruefe(!Zugriff::darfGeraetZuordnen($k . '-fremd'), 'ein Gerät, das an einer fremden Anlage misst, lässt sich nicht der eigenen zuordnen');
    pruefe(Zugriff::darfGeraetZuordnen($k . '-neu'), 'ein freies Gerät schon');
    pruefe(!Zugriff::geraetMitGeschichte(), '… aber ohne seine Vorgeschichte');
    $mp = MesspunktService::speichern($oA, null, ['geraet' => $k . '-neu', 'kanal' => 'em:0'], !Zugriff::geraetMitGeschichte());
    pruefe($mp['ok'] && Database::scalar('SELECT gueltig_ab FROM messpunkte WHERE id = ?', [(int) $mp['id']]) !== null, 'es zählt erst ab jetzt');
    pruefe(!MesspunktService::speichern($oA, null, ['geraet' => "x\ny", 'kanal' => 'em:0'])['ok']
        && !MesspunktService::speichern($oA, null, ['geraet' => 'shelly+', 'kanal' => 'em:0'])['ok'], 'keine Umbrüche und keine MQTT-Platzhalter in Kennungen');

    alsBenutzer(null);
    $quelle = Datenquellen::speichern(null, ['art' => 'shelly', 'name' => $k . ' Shelly', 'geraet' => $k . '-shelly', 'extern_id' => $k . '-shelly']);
    pruefe($quelle['ok'], 'Datenquelle angelegt: ' . $quelle['message']);
    $quellen[] = (int) $quelle['id'];
    pruefe(!Datenquellen::speichern(null, ['art' => 'shelly', 'name' => $k . ' Joker', 'geraet' => $k . '-joker', 'extern_id' => '+'])['ok'], 'MQTT-Präfix ohne + und #');
    BenutzerService::freigabeSpeichern(['subject_type' => 'user', 'subject_id' => $benutzer['technik'], 'ziel_art' => 'geraet',
        'ziel_id_geraet' => (int) $quelle['id'], 'ziel_kanal' => 'switch:1', 'darf_steuern' => 1]);
    alsBenutzer($benutzer['technik']);
    $q = Datenquellen::find((int) $quelle['id']);
    pruefe(Zugriff::darfSteuern($q, 'switch:1'), 'Freigabe für einen Kanal: dieser Kanal');
    pruefe(!Zugriff::darfSteuern($q, 'switch:0'), '… kein anderer');
    pruefe(!Zugriff::darfSteuern($q, ''), '… und kein Befehl ohne Kanal');
    pruefe(Zugriff::siehtGeraet($q), 'sehen darf man das Gerät trotzdem');

    // ------------------------------------------------ Am Standort ändern
    pruefe(!Zugriff::darfStandortAendern($sites['b']), 'einen Standort nur zu sehen erlaubt keine Änderungen daran');
    pruefe(!Zugriff::darfStandortAendern($sites['a']), 'eine Anlage bearbeiten zu dürfen erlaubt keine am ganzen Standort');
    pruefe(Zugriff::bearbeitetObjekt(AnlagenService::find($oA)) && !Zugriff::bearbeitetObjekt(AnlagenService::find($oB)), 'bearbeiten je Anlage');
    alsBenutzer($benutzer['admin']);
    pruefe(Zugriff::darfStandortAendern($sites['b']), 'wer alles sieht, darf überall ändern');
    alsBenutzer(null);

    // ------------------------------------------------ Schlüssel aus dem Repository
    pruefe(LocationBundle::schluesselGueltig('halle-1') && !LocationBundle::schluesselGueltig('../x') && !LocationBundle::schluesselGueltig('Halle')
        && !LocationBundle::schluesselGueltig(''), 'Schlüssel: nur a–z, 0–9, -');
    $datei = static fn (string $p): ?string => null;
    $e = LocationBundle::locationAnwenden(['format' => 1, 'art' => 'location', 'schluessel' => '../../public/x', 'name' => $k], $datei);
    pruefe(!$e['ok'], 'eine Location mit einem Pfad als Schlüssel wird nicht übernommen');
    $e = LocationBundle::locationAnwenden(['format' => 1, 'art' => 'location', 'schluessel' => $k . '-loc', 'name' => $k . ' Loc',
        'etagen' => [['schluessel' => '../../y', 'name' => 'Etage']]], $datei);
    pruefe(!$e['ok'] && Database::first('SELECT id FROM sites WHERE sync_key = ?', [$k . '-loc']) === null, '… auch nicht mit einem Pfad als Etagen-Schlüssel');
    Database::execute("UPDATE sites SET sync_key = '../../boese' WHERE id = ?", [$sites['a']]);
    $neuerSchluessel = LocationBundle::schluesselSichern('sites', $sites['a'], $k . ' A');
    pruefe(LocationBundle::schluesselGueltig($neuerSchluessel), 'ein ungültiger Schlüssel in der Datenbank wird neu vergeben: ' . $neuerSchluessel);
    foreach (['https://github.com/a/b.git' => true, 'git@github.com:a/b.git' => true, 'file:///srv/x.git' => true, 'http://h/x' => false,
        'https://tok@h/x' => false, 'ext::sh -c id' => false, '--upload-pack=x' => false] as $adresse => $gut) {
        gleich(LocationSync::adresseFehler($adresse) === null, $gut, 'Repository-Adresse ' . $adresse);
    }
    pruefe(LocationSync::zweigGueltig('main') && !LocationSync::zweigGueltig('-x') && !LocationSync::zweigGueltig('a..b'), 'Zweignamen');
    $stecker = LocationBundle::steckertypenSichern([['schluessel' => $k . '-farbe', 'name' => $k, 'farbe' => 'red;background:url(//x)']]);
    gleich((string) Database::scalar('SELECT farbe FROM steckertypen WHERE id = ?', [$stecker[$k . '-farbe']]), '#64748b', 'Farbe aus einer Datei nur als #rrggbb');

    // ------------------------------------------------ Namen, CSV, Adressen, Schlüssel, Werkzeuge
    pruefe(!str_contains(Influx::lpSchluessel("a\nstrom,geraet=x wert=1"), "\n"), 'ein Umbruch im Namen schreibt keinen zweiten Punkt');
    gleich(count(Influx::zeilen("dev\nx", 'k', ['ts' => 1, 'zustand' => ['t' => "a\nb"]])), 1, 'eine Nachricht, eine Zeile');
    gleich([StatistikController::csvFeld('=HYPERLINK(1)'), StatistikController::csvFeld('-5.2'), StatistikController::csvFeld('Name')],
        ['"\'=HYPERLINK(1)"', '"-5.2"', '"Name"'], 'CSV: Formeln werden Text, Zahlen bleiben');
    pruefe(!Http::anfrage('GET', 'file:///etc/passwd')['ok'] && !Http::anfrage('GET', 'gopher://127.0.0.1:1/')['ok'], 'Anfragen nur über http(s)');
    $ziel = new ReflectionMethod(\App\Support\Markdown::class, 'ziel');
    gleich([$ziel->invoke(null, 'JavaScript:alert(1)'), $ziel->invoke(null, 'data:text/html,x'), $ziel->invoke(null, 'https://example.org')],
        ['#', '#', 'https://example.org'], 'Links im Handbuch nur zu http(s), mailto und eigenen Seiten');
    Config::set('app.key', 'base64:CHANGEME000000000000000000000000000000000000');
    $geworfen = false;
    try {
        Crypto::encrypt('geheim');
    } catch (RuntimeException) {
        $geworfen = true;
    }
    pruefe($geworfen, 'mit dem Platzhalter als app.key wird nichts verschlüsselt');
    Config::set('app.key', $alterSchluessel);
    gleich(Crypto::decrypt(Crypto::encrypt('geheim')), 'geheim', 'mit eigenem Schlüssel wie gewohnt');

    $starten = new ReflectionMethod(\App\Services\PdfRasterService::class, 'starten');
    $zeit = microtime(true);
    $e = $starten->invoke(null, ['/bin/sh', '-c', 'head -c 3000000 /dev/zero | tr "\\000" x >&2; echo fertig']);
    pruefe($e['ok'] && trim($e['ausgabe']) === 'fertig' && microtime(true) - $zeit < 10, 'viel Ausgabe auf stderr lässt den Aufruf nicht hängen');
    $pdf = tempnam(sys_get_temp_dir(), 'pm-sich-');
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 99999999 >> endobj\n");
    gleich(SiteService::countPdfPages($pdf), SiteService::MAX_PDF_SEITEN, 'die Seitenzahl eines PDFs ist gedeckelt');
    @unlink($pdf);
} finally {
    alsBenutzer(null);
    Config::set('app.key', $alterSchluessel);
    foreach ($sites as $siteId) {
        foreach (Database::select('SELECT id FROM objekte WHERE site_id = ?', [$siteId]) as $o) {
            AnlagenService::loeschen((int) $o['id']);
        }
        SiteService::deleteSite($siteId);
    }
    foreach ($quellen as $id) {
        Datenquellen::loeschen($id);
    }
    Database::execute('DELETE FROM datenquellen WHERE geraet LIKE ?', [$k . '%']);
    Database::execute('DELETE FROM steckertypen WHERE schluessel LIKE ?', [$k . '%']);
    foreach ($benutzer as $id) {
        Database::execute("DELETE FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$id]);
        BenutzerService::loeschen($id);
    }
    foreach ($rollen as $id) {
        BenutzerService::rolleLoeschen($id);
    }
    Database::execute('DELETE FROM audit_log WHERE username LIKE ? OR ip = ? OR details LIKE ?', [$k . '%', $ip, '%' . $k . '%']);
}

ende('sicherheit');
