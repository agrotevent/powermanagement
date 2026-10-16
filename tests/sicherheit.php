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
 * Schnittstelle: Befugnisse und Standorte eines Tokens vergibt nur, wer die
 * Rechte dazu hat; ein verfälschtes Token wird nie angezeigt; die Ansichten
 * kommen ohne Inline-Skript aus; was eine andere Instanz schickt (Meldungen,
 * Namen), kommt nur maskiert auf die Seite.
 *
 * Die Prüfungen über HTTP (Weiterleitungen, Sitzung, Token, Benutzer-
 * verwaltung) stehen in web.php und schnittstelle.php.
 */

require __DIR__ . '/hilfen/pruefung.php';

use App\Auth\ApiZugang;
use App\Auth\Auth;
use App\Auth\Permissions;
use App\Auth\Zugriff;
use App\Controllers\StatistikController;
use App\Messung\Datenquellen;
use App\Messung\Http;
use App\Messung\Influx;
use App\Services\AnlagenService;
use App\Services\ApiZugaenge;
use App\Services\BenutzerService;
use App\Services\LocationBundle;
use App\Services\LocationSync;
use App\Services\MesspunktService;
use App\Services\SiteService;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Response;
use App\Support\View;

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

    // ------------------------------------------------ Schnittstelle: Zugänge vergeben
    foreach (Permissions::standardRollen() as $schluessel => $rolle) {
        if ($schluessel !== 'admin') {
            pruefe(preg_grep('/^api\./', $rolle['permissions']) === [], 'Standardrolle ' . $schluessel . ' ohne api.*');
        }
    }
    gleich((int) Database::scalar("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
        WHERE rp.permission LIKE 'api.%' AND r.role_key IN ('planung', 'technik', 'kunde')"), 0, 'die api.*-Rechte hat nur die Administration (*)');
    $rollen['api'] = (int) BenutzerService::rolleSpeichern(null, $k . ' api', '', ['api.manage', 'karte.view'])['id'];
    $rollen['apialles'] = (int) BenutzerService::rolleSpeichern(null, $k . ' apialles', '',
        ['api.manage', 'karte.view', 'plans.manage', 'anlagen.view', 'anlagen.scope.all'])['id'];
    $benutzer['api'] = BenutzerService::anlegen($k . '-api', 'Pruefung2026!!', $rolleKey($rollen['api']), false);
    $benutzer['apialles'] = BenutzerService::anlegen($k . '-apialles', 'Pruefung2026!!', $rolleKey($rollen['apialles']), false);
    $sites['api'] = SiteService::saveSite(null, ['name' => $k . ' API']);
    alsBenutzer($benutzer['api']);
    $r = ApiZugaenge::anlegen(['name' => $k . ' Zugang', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['api']]]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'Alle Standorte'), 'Standorte für ein Token nur mit „Alle Standorte …“');
    $r = ApiZugaenge::anlegen(['name' => $k . ' Zugang', 'befugnisse' => ['feststrom']]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'feststrom'), 'Feststrom nur, wer Anlagen sehen darf');
    pruefe(!ApiZugaenge::anlegen(['name' => $k . ' Zugang', 'befugnisse' => ['plaene.vorschlagen']])['ok'], 'vorschlagen nur mit „Standorte pflegen“');
    pruefe(!ApiZugaenge::anlegen(['name' => $k . ' Zugang', 'befugnisse' => ['plaene.lesen'], 'user_id' => $benutzer['admin']])['ok'],
        'an ein Konto mit mehr Rechten lässt sich kein Token binden');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM api_tokens WHERE name LIKE ?', [$k . '%']), 0, 'nichts davon gespeichert');
    alsBenutzer($benutzer['apialles']);
    $r = ApiZugaenge::anlegen(['name' => $k . ' schreibt fest', 'befugnisse' => ['plaene.schreiben', 'feststrom'], 'sehen' => [$sites['api']]]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'Feststrom'), 'schreiben mit Feststrom nur mit „Anlagen ändern“ und „Kabel einzeichnen“');
    $schreibt = ApiZugaenge::anlegen(['name' => $k . ' schreibt', 'befugnisse' => ['plaene.schreiben'], 'bearbeiten' => [$sites['api']]]);
    pruefe($schreibt['ok'] && ApiZugaenge::anlegen(['name' => $k . ' schlägt vor', 'befugnisse' => ['plaene.vorschlagen', 'feststrom']])['ok'],
        'schreiben allein und vorschlagen mit Feststrom schon');
    $tokenZeile = ApiZugang::pruefen('Bearer ' . $schreibt['token'], '127.0.0.1');
    pruefe($tokenZeile !== null && !isset($tokenZeile['token_hash']) && !isset($tokenZeile['token_enc']), 'geprüftes Token ohne Hash und Verschlüsseltes');
    pruefe(ApiZugang::bearbeitet($tokenZeile, $sites['api']), 'mit „schreiben“ und Freigabe „bearbeiten“: bearbeiten');
    Database::execute("UPDATE api_tokens SET befugnisse = '[\"plaene.lesen\"]' WHERE id = ?", [(int) $schreibt['id']]);
    pruefe(ApiZugang::sieht(ApiZugang::pruefen('Bearer ' . $schreibt['token'], '127.0.0.1'), $sites['api'])
        && !ApiZugang::bearbeitet(ApiZugang::pruefen('Bearer ' . $schreibt['token'], '127.0.0.1'), $sites['api']), 'nur lesen: sehen ja, bearbeiten nein');
    // Ein verfälschtes token_enc zeigt nichts an
    alsBenutzer($benutzer['admin']);
    $falsch = Crypto::encrypt('mtma_' . str_repeat('B', 40));
    Database::execute('UPDATE api_tokens SET token_enc = ? WHERE id = ?', [$falsch, (int) $schreibt['id']]);
    $r = ApiZugaenge::klartext((int) $schreibt['id'], 'Pruefung2026!!');
    pruefe(!$r['ok'] && !isset($r['token']), 'verfälschtes token_enc (anderes Token): nichts angezeigt');
    Database::execute("UPDATE api_tokens SET token_enc = 'kaputt' WHERE id = ?", [(int) $schreibt['id']]);
    pruefe(!isset(ApiZugaenge::klartext((int) $schreibt['id'], 'Pruefung2026!!')['token']), 'unlesbares token_enc: nichts angezeigt');
    pruefe(!isset(ApiZugaenge::klartext((int) $schreibt['id'], 'falsch')['token']), 'falsches Kennwort: nichts angezeigt');
    alsBenutzer($benutzer['apialles']);
    pruefe(!ApiZugaenge::loeschen((int) $schreibt['id'])['ok'], 'ein gültiger Zugang lässt sich nicht löschen');
    alsBenutzer(null);
    pruefe(ApiZugang::pruefen('Bearer ' . $schreibt['token'] . 'x', '127.0.0.1') === null && ApiZugang::pruefen('Token ' . $schreibt['token'], '127.0.0.1') === null
        && ApiZugang::pruefen('Bearer ' . strtoupper($schreibt['token']), '127.0.0.1') === null, 'nur genau das Token im Kopf „Bearer“');
    // Die Ansichten der Schnittstelle: kein Skript ohne Nonce, keine on…=, kein javascript:
    $ansichten = array_merge(glob(dirname(__DIR__) . '/resources/views/schnittstelle/*.php') ?: [], glob(dirname(__DIR__) . '/resources/views/instanzen/*.php') ?: [],
        glob(dirname(__DIR__) . '/resources/views/vorschlaege/*.php') ?: [], [dirname(__DIR__) . '/resources/views/partials/token-standorte.php']);
    pruefe(count($ansichten) >= 10, 'Ansichten der Schnittstelle, der anderen Instanzen und der Vorschläge gefunden');
    // Eine Vorschlagsdatei nur über ihren Zufallsnamen im eigenen Ordner – auch ein verfälschter Eintrag führt nicht hinaus.
    foreach (['../../config/config.php', '/etc/passwd', 'abc.svg', str_repeat('a', 24) . '.php'] as $falsch) {
        pruefe(\App\Services\VorschlagService::datei(['id' => 1, 'dateien' => ['plaene/x.svg' => ['datei' => $falsch]]], 'plaene/x.svg') === null,
            'Vorschlagsdatei „' . $falsch . '“ wird nicht ausgeliefert');
    }
    foreach ($ansichten as $datei) {
        $text = (string) file_get_contents($datei);
        $name = basename(dirname($datei)) . '/' . basename($datei);
        pruefe(preg_match('/<script(?![^>]*\bnonce=)/i', $text) !== 1, $name . ': kein <script> ohne Nonce');
        pruefe(preg_match('/\son[a-z]+\s*=/i', $text) !== 1 && stripos($text, 'javascript:') === false, $name . ': kein on…= und kein javascript:');
    }

    // Was eine andere Instanz schickt, kommt in der Instanzseite nur maskiert an:
    // ihre Meldung, Namen von Locations und Etagen; ein Schlüssel, der keiner
    // ist, wird übergangen.
    $gegenseite = ['id' => 0, 'name' => $k . ' Gegenseite', 'base_url' => 'http://gegenseite.example', 'token' => 'mtma_' . str_repeat('g', 40),
        'remote_name' => '<b>fett</b>', 'remote_produkt' => '', 'befugnisse' => ['plaene.lesen'], 'unverschluesselt' => true, 'powermanagement' => false,
        'verified_at' => null, 'token_prefix' => 'mtma_ggggggg', 'letzter_fehler' => '<script>alert(2)</script>'];
    $dortAntwort = ['ok' => true, 'status' => 403, 'rumpf' => json_encode(['ok' => false, 'message' => '<script>alert(1)</script>']), 'fehler' => ''];
    Http::$attrappe = static function () use (&$dortAntwort): array {
        return $dortAntwort;
    };
    $seite = View::partial('instanzen/instanz', ['partner' => $gegenseite, 'locations' => \App\Services\PartnerAbgleich::locations($gegenseite), 'planen' => true]);
    pruefe(str_contains($seite, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($seite, '<script>alert(1)'), 'Meldung von dort maskiert');
    pruefe(!str_contains($seite, '<script>alert(2)') && !str_contains($seite, '<b>fett</b>'), 'gespeicherter Fehler und Name von dort maskiert');
    $dortAntwort = ['ok' => true, 'status' => 200, 'fehler' => '', 'rumpf' => json_encode(['ok' => true, 'locations' => [
        ['schluessel' => 'halle', 'name' => '<img src=x onerror=alert(3)>', 'stand' => 'abc', 'etagen' => [['schluessel' => 'eg', 'name' => '<svg onload=alert(4)>', 'plan' => true]]],
        ['schluessel' => '../../etc', 'name' => 'Pfad', 'stand' => 'abc', 'etagen' => []],
    ]])];
    $liste = \App\Services\PartnerAbgleich::locations($gegenseite);
    $seite = View::partial('instanzen/instanz', ['partner' => $gegenseite, 'locations' => $liste, 'planen' => true]);
    pruefe(count($liste['locations']) === 1 && $liste['uebergangen'] === 1, 'Location mit ungültigem Schlüssel übergangen');
    pruefe(str_contains($seite, '&lt;img src=x onerror=alert(3)&gt;') && !str_contains($seite, '<img src=x') && !str_contains($seite, '<svg onload'),
        'Namen von dort maskiert');
    Http::$attrappe = null;

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
    foreach (Database::select('SELECT id FROM api_tokens WHERE name LIKE ?', [$k . '%']) as $t) {
        Database::execute('DELETE FROM api_token_standorte WHERE token_id = ?', [(int) $t['id']]);
        Database::execute('DELETE FROM api_tokens WHERE id = ?', [(int) $t['id']]);
        Database::execute("DELETE FROM audit_log WHERE target_type = 'api_token' AND target_id = ?", [(string) $t['id']]);
    }
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
