<?php
declare(strict_types=1);

/**
 * Sicherheit: was die Sicherheitsprüfung gefunden hat, bleibt behoben.
 *
 * Rechte nur vergeben, wer sie hat; Anmeldung ohne Kontoerkennung und mit
 * Bremse je Adresse; Geräte nur zuordnen, wo man bearbeiten darf, und dann
 * ohne fremde Vorgeschichte; Schalt-Freigaben für einen Kanal gelten nur für
 * ihn; am Standort ändern braucht „bearbeiten“; Schlüssel aus dem Repository
 * werden keine Pfade; Namen brechen kein Line Protocol; CSV ohne Formeln
 * (auch die des Tanks, sein Protokoll mit Lieferscheinen und der Verbrauch
 * je Tag); nur http(s); Umleitungen nehmen keine Kopfzeilen (Tokens) mit, auf
 * beiden Wegen von Http; kein öffentlicher Schlüssel; PDF-Werkzeuge hängen
 * nicht; Tankverlauf und Verbrauch je Tag verraten die Abfrage an die
 * InfluxDB nur der Messtechnik; eine Kennlinie von
 * Hand nimmt nur Anker und Steigung als endliche Zahlen; Tankwerte auf der
 * Kommandozeile (tank:liste) nur mit --als und nur, was dieses Konto sieht.
 *
 * Schnittstelle: Befugnisse und Standorte eines Tokens vergibt nur, wer die
 * Rechte dazu hat; ein verfälschtes Token wird nie angezeigt, ein fremdes
 * nur, wer seine Befugnisse und seine Bindung selbst vergeben dürfte; eine
 * Bindung löst nur, wer den Benutzer verwalten darf; die Ansichten kommen
 * ohne Inline-Skript aus; was eine andere Instanz schickt (Meldungen, Namen),
 * kommt nur maskiert auf die Seite, und ein verknüpfter Standort, den man
 * nicht sieht, verrät dort weder Etagen noch Zustand.
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
use App\Controllers\TankController;
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
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use App\Support\Zeit;
use App\Tanks\TankRechnung;
use App\Tanks\TankService;

$k = 'pm-sich-' . bin2hex(random_bytes(3));
$ip = '198.51.100.' . random_int(1, 254);
$benutzer = [];
$rollen = [];
$sites = [];
$quellen = [];
$alterSchluessel = Config::get('app.key');
$umleitung = sys_get_temp_dir() . '/' . $k . '-umleitung';

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
    pruefe(!BenutzerService::darfVergeben(['berichte.view']) && !BenutzerService::darfVergeben(['berichte.pruefen']),
        'Rechte der Übergabeberichte nur, wer sie selbst hat');
    // Texte aus Antworten (Eventworx, Bewertung, Prüfen) nur per textContent – nie als HTML.
    $js = dirname(__DIR__) . '/public/assets/js/';
    foreach (array_merge(glob($js . 'bericht*.js') ?: [], [$js . 'unterschrift.js']) as $datei) {
        $quelle = (string) file_get_contents($datei);
        pruefe(!str_contains($quelle, 'innerHTML') && !str_contains($quelle, 'insertAdjacentHTML') && !str_contains($quelle, 'outerHTML'),
            basename($datei) . ' setzt kein HTML aus Daten');
    }
    // Unterschriften nur als Linie: keine Zeit, kein Druck, keine Neigung im Erfassungscode.
    pruefe(preg_match('/pressure|timeStamp|Date\.now|performance\.now|tiltX|tiltY|twist|tangential/', (string) file_get_contents($js . 'unterschrift.js')) === 0,
        'unterschrift.js liest weder Zeit noch Druck');
    // Abmelden: bericht.js hört vorher (Capture) und schreibt danach nichts mehr zurück – sonst
    // legte pagehide den gerade gelöschten Entwurf mit Kundendaten wieder ab.
    pruefe(preg_match('/document\.addEventListener\(\'submit\', function \(e\) \{[^}]*form\[action="\/logout"\][\s\S]{0,600}?uebernahme = true;[\s\S]{0,200}?\}, true\);/',
        (string) file_get_contents($js . 'bericht.js')) === 1, 'bericht.js: Abmelden im Capture, danach kein lokaler Entwurf mehr');
    // Netz: jede Anfrage der Berichte mit Zeitgrenze (PMNetz, AbortController) – kein fetch ohne.
    $app = (string) file_get_contents($js . 'app.js');
    pruefe(str_contains($app, 'window.PMNetz') && str_contains($app, 'AbortController') && str_contains($app, "BroadcastChannel('pm-kundenmodus')"),
        'app.js: POST mit Zeitgrenze, Sperre anderer Tabs im Kundenmodus');
    foreach (['bericht.js', 'bericht-kunde.js', 'unterschrift.js'] as $datei) {
        pruefe(!str_contains((string) file_get_contents($js . $datei), 'fetch('), $datei . ': kein fetch ohne Zeitgrenze');
    }
    pruefe(str_contains((string) file_get_contents($js . 'bericht-kunde.js'), "BroadcastChannel('pm-kundenmodus')"), 'bericht-kunde.js meldet den Kundenmodus an andere Tabs');
    // Die Fläche selbst: Handballen zuerst, dann Stift; viele Punkte mit großen Koordinaten.
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        $flaeche = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/hilfen/unterschrift-flaeche.cjs') . ' '
            . escapeshellarg($js . 'unterschrift.js')), true) ?? [];
        pruefe(($flaeche['handballen']['striche'] ?? 0) === 1 && ($flaeche['handballen']['punkte'][0] ?? 0) > 20 && ($flaeche['handballen']['gueltig'] ?? false) === true,
            'unterschrift.js: liegt der Handballen zuerst auf, zählt der Stiftstrich (nicht der Handballen) ' . json_encode($flaeche['handballen'] ?? null));
        pruefe(($flaeche['gross']['bytes'] ?? PHP_INT_MAX) <= \App\Berichte\Unterschrift::MAX_BYTES && ($flaeche['gross']['punkte'] ?? PHP_INT_MAX) <= \App\Berichte\Unterschrift::MAX_PUNKTE,
            'unterschrift.js: nimmt nie mehr auf, als der Server annimmt (Punkte und Bytes) ' . json_encode($flaeche['gross'] ?? null));
    }
    $svg = \App\Berichte\Unterschrift::svg([[['"><script>alert(1)</script>', 5], [5000, 'x" onload="y']], [['1e400', [1, 2]]]]);
    pruefe(!str_contains($svg, 'script') && !str_contains($svg, 'onload') && preg_match('/ d="[MQLl0-9 ]*"/', $svg) === 1,
        'Unterschrift::svg aus manipulierten Strichen: nur Zahlen');
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

    // Anzeigen nur, wer das Token mit allen Befugnissen selbst vergeben dürfte:
    // „alle Standorte“ genügt nicht, sonst holte sich jemand ohne „Karte“
    // und „Grundrisse pflegen“ ein Token, das Pläne, Anlagen und Kabel schreibt.
    $rollen['zeiger'] = (int) BenutzerService::rolleSpeichern(null, $k . ' zeiger', '', ['api.manage', 'anlagen.scope.all', 'dashboard.view', 'karte.view'])['id'];
    $benutzer['zeiger'] = BenutzerService::anlegen($k . '-zeiger', 'Pruefung2026!!', $rolleKey($rollen['zeiger']), false);
    alsBenutzer($benutzer['admin']);
    $sites['zeigen'] = SiteService::saveSite(null, ['name' => $k . ' Zeigen']);
    $stark = ApiZugaenge::anlegen(['name' => $k . ' stark', 'befugnisse' => ['plaene.schreiben', 'feststrom'], 'bearbeiten' => [$sites['zeigen']]]);
    $lesend = ApiZugaenge::anlegen(['name' => $k . ' lesend', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['zeigen']]]);
    pruefe($stark['ok'] && $lesend['ok'], 'Admin legt ein schreibendes und ein lesendes Token an');
    alsBenutzer($benutzer['zeiger']);
    $anzeigenVorher = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'api.token.angezeigt' AND target_id = ?", [(string) $stark['id']]);
    $r = ApiZugaenge::klartext((int) $stark['id'], 'Pruefung2026!!');
    pruefe(!$r['ok'] && !isset($r['token']) && str_contains($r['message'], 'nicht vergeben'),
        'alle Standorte, aber ohne „Grundrisse pflegen“/„Anlagen ändern“: ein schreibendes Token wird nicht angezeigt – ' . $r['message']);
    gleich((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'api.token.angezeigt' AND target_id = ?", [(string) $stark['id']]), $anzeigenVorher,
        '… und kein Audit „api.token.angezeigt“');
    $r = ApiZugaenge::klartext((int) $lesend['id'], 'Pruefung2026!!');
    pruefe($r['ok'] && ($r['token'] ?? '') === $lesend['token'], 'ein Token nur mit „lesen“ zeigt er an (er hat „Karte ansehen“)');
    $liste = array_column(ApiZugaenge::alle(), null, 'id');
    pruefe(($liste[(int) $stark['id']]['verwaltbar'] ?? null) === false && ($liste[(int) $lesend['id']]['verwaltbar'] ?? null) === true,
        'Liste: „Anzeigen“ nur beim Token, das er selbst vergeben dürfte');
    alsBenutzer($benutzer['admin']);
    $gebunden = ApiZugaenge::anlegen(['name' => $k . ' gebunden', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['zeigen']], 'user_id' => $benutzer['admin']]);
    alsBenutzer($benutzer['zeiger']);
    pruefe(!ApiZugaenge::klartext((int) $gebunden['id'], 'Pruefung2026!!')['ok'], 'an einen Administrator gebunden: nicht angezeigt');

    // Die Bindung an jemanden, den man nicht verwalten darf, löst man nicht
    $r = ApiZugaenge::speichern((int) $gebunden['id'], ['name' => $k . ' gebunden', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['zeigen']], 'user_id' => 0]);
    pruefe(!$r['ok'] && str_contains($r['message'], 'Bindung') && (int) Database::scalar('SELECT user_id FROM api_tokens WHERE id = ?', [(int) $gebunden['id']]) === $benutzer['admin'],
        'Bindung an einen Administrator lösen: abgelehnt, sie bleibt – ' . $r['message']);
    $r = ApiZugaenge::speichern((int) $gebunden['id'], ['name' => $k . ' gebunden', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['zeigen']], 'user_id' => $benutzer['zeiger']]);
    pruefe(!$r['ok'] && (int) Database::scalar('SELECT user_id FROM api_tokens WHERE id = ?', [(int) $gebunden['id']]) === $benutzer['admin'],
        '… und auch nicht auf sich selbst umbinden');
    $r = ApiZugaenge::speichern((int) $gebunden['id'], ['name' => $k . ' gebunden, umbenannt', 'befugnisse' => ['plaene.lesen'], 'sehen' => [$sites['zeigen']],
        'user_id' => $benutzer['admin']]);
    pruefe($r['ok'] && (string) Database::scalar('SELECT name FROM api_tokens WHERE id = ?', [(int) $gebunden['id']]) === $k . ' gebunden, umbenannt',
        'bleibt die Bindung, lassen sich Name und Rest ändern – ' . $r['message']);
    alsBenutzer(null);
    Database::update('users', $benutzer['admin'], ['is_active' => 0]);
    pruefe(ApiZugang::pruefen('Bearer ' . $gebunden['token'], '127.0.0.1') === null, 'der gebundene Administrator ist gesperrt: das Token ruht');
    Database::update('users', $benutzer['admin'], ['is_active' => 1]);

    // Die Instanzseite verrät nichts über einen verknüpften Standort, den man nicht sieht
    $rollen['partner'] = (int) BenutzerService::rolleSpeichern(null, $k . ' partner', '', ['api.partner', 'karte.view', 'dashboard.view', 'plans.manage'])['id'];
    $benutzer['partner'] = BenutzerService::anlegen($k . '-partner', 'Pruefung2026!!', $rolleKey($rollen['partner']), false);
    $sites['verborgen'] = SiteService::saveSite(null, ['name' => $k . ' Verborgen']);
    $geheimeEtage = SiteService::saveFloor(null, ['site_id' => $sites['verborgen'], 'name' => 'Geheime Etage ' . $k]);
    $partnerId = Database::insert('api_partner', ['name' => $k . ' Gegenseite', 'base_url' => 'http://gegenseite.example', 'token_enc' => 'x',
        'token_prefix' => 'mtma_ggggggg', 'remote_befugnisse' => '["plaene.lesen","plaene.vorschlagen"]', 'created_at' => gmdate('c')]);
    Database::execute("UPDATE sites SET partner_id = ?, partner_schluessel = 'halle-dort', partner_stand = 'abc', partner_hash = 'veraltet' WHERE id = ?",
        [$partnerId, $sites['verborgen']]);
    Database::execute("UPDATE floors SET partner_schluessel = 'eg-dort' WHERE id = ?", [$geheimeEtage]);
    $dortAntwort = ['ok' => true, 'status' => 200, 'fehler' => '', 'rumpf' => json_encode(['ok' => true, 'locations' => [
        ['schluessel' => 'halle-dort', 'name' => 'Halle dort', 'stand' => 'def', 'etagen' => [['schluessel' => 'eg-dort', 'name' => 'EG dort', 'plan' => true, 'bearbeiten' => true]]],
    ]])];
    Http::$attrappe = static function () use (&$dortAntwort): array {
        return $dortAntwort;
    };
    $partnerZeile = ['id' => $partnerId, 'token' => 'mtma_' . str_repeat('g', 40)] + $gegenseite;
    alsBenutzer($benutzer['partner']);
    Zugriff::vergessen();
    pruefe(!Zugriff::siehtStandort($sites['verborgen']), 'der Planer hat keine Freigabe am verknüpften Standort');
    $liste = \App\Services\PartnerAbgleich::locations($partnerZeile);
    $l = $liste['locations'][0] ?? [];
    gleich([$l['sichtbar'] ?? null, $l['zustand'] ?? null, $l['etagen'][0]['ziel'] ?? null, $l['aendern'] ?? null, $l['bearbeitbar'] ?? null],
        [false, 'verborgen', ['grund' => 'verknuepft'], false, 0], 'Location an einem nicht sichtbaren Standort: kein Ziel mit Namen, kein Zustand, nichts zu tun');
    $seite = View::partial('instanzen/instanz', ['partner' => $partnerZeile, 'locations' => $liste, 'planen' => true]);
    pruefe(!str_contains($seite, 'Geheime Etage') && !str_contains($seite, $k . ' Verborgen') && !str_contains($seite, 'hier geändert')
        && !str_contains($seite, 'beide geändert') && !str_contains($seite, 'dort geändert') && str_contains($seite, 'den du nicht siehst'),
        'Instanzseite: weder Etagen- noch Standortname noch Zustand des verborgenen Standorts');
    pruefe(!str_contains($seite, '/zuordnen?') && !str_contains($seite, '/uebernehmen"') && !str_contains($seite, '/loesen"'),
        '… und keine Knöpfe dafür');
    alsBenutzer(null);
    Http::$attrappe = null;
    Database::execute("UPDATE sites SET partner_id = NULL, partner_schluessel = '' WHERE id = ?", [$sites['verborgen']]);
    Database::execute('DELETE FROM api_partner WHERE id = ?', [$partnerId]);

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
    // Eine Umleitung wird nicht verfolgt – sonst ginge das Token (S-API-TOKEN,
    // X-Authorization …) an das neue Ziel. Beide Wege: curl und PHP-Streams.
    mkdir($umleitung, 0700);
    file_put_contents($umleitung . '/index.php', '<?php if ($_SERVER["REQUEST_URI"] === "/ziel") { file_put_contents(__DIR__ . "/gerufen", '
        . '(string) ($_SERVER["HTTP_X_PRUEF_TOKEN"] ?? "-")); echo "ziel"; return; } header("Location: /ziel", true, 302); echo "weiter";');
    $port = freierPort();
    hintergrund(['php', '-S', '127.0.0.1:' . $port, '-t', $umleitung]);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    foreach (['curl' => false, 'PHP-Streams' => true] as $weg => $ohneCurl) {
        Http::$ohneCurl = $ohneCurl;
        @unlink($umleitung . '/gerufen');
        $a = Http::anfrage('GET', 'http://127.0.0.1:' . $port . '/', ['kopf' => ['X-Pruef-Token' => 'geheim-' . $k], 'zeit' => 5]);
        pruefe($a['ok'] && $a['status'] === 302 && $a['rumpf'] === 'weiter' && !is_file($umleitung . '/gerufen'),
            $weg . ': eine Umleitung wird nicht verfolgt, das Token geht nicht ans Ziel (Status ' . $a['status'] . ')');
    }
    Http::$ohneCurl = false;
    pruefe(!Http::anfrage('GET', 'http://127.0.0.1:' . $port . '/', ['kopf' => ['X-Pruef' => "a\r\nX-Eingeschleust: 1"]])['ok']
        && !Http::anfrage('GET', 'http://127.0.0.1:' . $port . '/', ['kopf' => ["X-Pruef: a\r\nB" => '1']])['ok'], 'kein Umbruch in einer Kopfzeile');

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

    // ------------------------------------------------ Tank: CSV ohne Formeln, Fehler der InfluxDB nur für die Messtechnik
    alsBenutzer($benutzer['admin']);
    $tankSite = SiteService::saveSite(null, ['name' => 'Prüfung ' . $k . ' Tank']);
    $sites[] = $tankSite;
    $tankFloor = SiteService::saveFloor(null, ['site_id' => $tankSite, 'name' => 'Wiese']);
    $tank = (int) AnlagenService::speichern(null, ['art' => 'tank', 'name' => $k . ' Tank', 'floor_id' => $tankFloor,
        'eigenschaften' => ['inhalt_l' => '3000', 'kraftstoff' => 'HVO', 'liter_je_mm' => '2,78']])['id'];
    $mp = Database::insert('messpunkte', ['objekt_id' => $tank, 'geraet' => 'shellyplusuni-tank1', 'kanal' => 'voltmeter:100', 'rolle' => 'fuellstand',
        'bezeichnung' => '=SUMME(A1:A9)', 'gueltig_ab' => Zeit::iso(time() - 3 * 3600), 'created_at' => gmdate('c')]);
    $rechnung = TankRechnung::rechnung(['prinzip' => 'hydrostatisch', 'feld' => 'u', 'signal' => '4-20mA', 'buerde_ohm' => 500, 'bereich_von' => 0,
        'bereich_bis' => 100, 'bereich_einheit' => 'mbar'], ['offset_mm' => 20], AnlagenService::find($tank)['eigenschaften'], [])['rechnung'];
    TankService::fassungAnlegen(Database::first('SELECT * FROM messpunkte WHERE id = ?', [$mp]), $rechnung, 'einsatz', Zeit::iso(time() - 3 * 3600), false);
    $aufruf = static function (string $methode, array $get) use ($tank): string {
        $_GET = $get;
        ob_start();
        (new TankController())->$methode(new Request(), ['id' => $tank]);
        return (string) ob_get_clean();
    };
    $demoVorher = Config::get('app.demo');
    Config::set('app.demo', true);
    $csv = $aufruf('csv', ['zeitraum' => '6h']);
    pruefe(str_contains($csv, '"\'=SUMME(A1:A9)"') && !str_contains($csv, ';"=SUMME') && substr_count($csv, "\r\n") > 10,
        'Tank-CSV: der Name des Sensors als Text, keine Formel');
    $e = TankService::betankungSpeichern(AnlagenService::find($tank), ['liter' => '500', 'lieferschein' => '=SUMME(B1:B9)', 'notizen' => '@HYPERLINK("x")', 'kalibrieren' => false]);
    $csv = $aufruf('csv', ['inhalt' => 'protokoll']);
    pruefe($e['ok'] && str_contains($csv, '"\'=SUMME(B1:B9)"') && str_contains($csv, '"\'@HYPERLINK(""x"")"') && !str_contains($csv, ';"=SUMME') && !str_contains($csv, ';"@'),
        'Protokoll-CSV: Lieferschein und Notiz als Text, keine Formel');
    $csv = $aufruf('csv', ['inhalt' => 'tage', 'zeitraum' => '24h']);
    pruefe(str_contains($csv, '"Tag";"Verbrauch [l]"') && str_contains($csv, '=SUMME(B1:B9)') && !str_contains($csv, ';"=') && !str_contains($csv, ';"@'),
        'Tage-CSV: Lieferschein in den Betankungen des Tags, keine Formel');
    // Kennlinie von Hand: nur Anker und Steigung, nur endliche Zahlen
    $fassungenVorher = (int) Database::scalar('SELECT COUNT(*) FROM tank_kennlinien WHERE objekt_id = ?', [$tank]);
    foreach ([['roh_anker' => '2', 'mm_anker' => '20', 'mm_je_roh' => '150', 'roh_max' => '99'], ['roh_anker' => '2', 'mm_anker' => '20', 'mm_je_roh' => '1e999'],
        ['roh_anker' => ['2'], 'mm_anker' => '20', 'mm_je_roh' => '150'], ['roh_anker' => 'NAN', 'mm_anker' => '20', 'mm_je_roh' => '150']] as $hand) {
        $e = TankService::sensorVonHand(AnlagenService::find($tank), ['messpunkt' => $mp, 'sensor' => $hand], false);
        pruefe(!$e['ok'], 'Kennlinie von Hand abgelehnt: ' . json_encode($hand) . ' – ' . $e['message']);
    }
    gleich((int) Database::scalar('SELECT COUNT(*) FROM tank_kennlinien WHERE objekt_id = ?', [$tank]), $fassungenVorher, '… keine Fassung daraus');
    $urlVorher = Config::get('influx.url');
    Config::set('app.demo', false);
    Config::set('influx.url', 'http://127.0.0.1:' . freierPort());
    $antwort = json_decode($aufruf('verlauf', ['zeitraum' => '6h']), true);
    pruefe(($antwort['ok'] ?? true) === false && str_contains((string) ($antwort['message'] ?? ''), 'InfluxDB'), 'Tankverlauf: die Messtechnik sieht den Fehler der InfluxDB');
    $leser = (int) BenutzerService::rolleSpeichern(null, $k . ' leser', '', ['anlagen.view', 'anlagen.scope.all', 'statistik.view'])['id'];
    $rollen['leser'] = $leser;
    $benutzer['leser'] = BenutzerService::anlegen($k . '-leser', 'Pruefung2026!!', $rolleKey($leser), false);
    alsBenutzer($benutzer['leser']);
    $antwort = json_decode($aufruf('verlauf', ['zeitraum' => '6h']), true);
    gleich([$antwort['ok'] ?? null, $antwort['message'] ?? null], [false, 'Die Messdaten ließen sich gerade nicht abfragen.'],
        'Tankverlauf: ohne „datenquellen.manage“ kein Text der InfluxDB (Aufbau der Abfrage)');
    $antwort = json_decode($aufruf('tage', ['zeitraum' => '24h']), true);
    gleich([$antwort['ok'] ?? null, $antwort['message'] ?? null, str_contains(json_encode($antwort), 'InfluxDB')], [false, 'Die Messdaten ließen sich gerade nicht abfragen.', false],
        'Verbrauch je Tag: ohne „datenquellen.manage“ kein Text der InfluxDB');
    Config::set('influx.url', $urlVorher);
    Config::set('app.demo', $demoVorher);
    $_GET = [];
    alsBenutzer(null);

    // ------------------------------------------------ Tankwerte auf der Kommandozeile nur aus Sicht eines Kontos
    $power = static function (array $argumente): array {
        $p = proc_open(array_merge(['php', dirname(__DIR__) . '/bin/power'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, dirname(__DIR__));
        $aus = (string) stream_get_contents($rohre[1]);
        $err = (string) stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);
        return [proc_close($p), $aus, $err];
    };
    [$rc, $aus, $err] = $power(['tank:liste']);
    pruefe($rc !== 0 && !str_contains($aus, $k . ' Tank') && str_contains($err, '--als=<benutzer>'), 'tank:liste ohne --als: Abbruch mit Meldung, kein Tank');
    [$rc, $aus, $err] = $power(['tank:liste', '--als=' . $k . '-klein']);
    pruefe($rc !== 0 && !str_contains($aus, $k . ' Tank') && str_contains($err, 'keine Anlagen'), 'tank:liste als Konto ohne „anlagen.view“: abgelehnt');
    [$rc, $aus] = $power(['tank:liste', '--als=' . $k . '-technik']);
    pruefe($rc === 0 && !str_contains($aus, $k . ' Tank'), 'tank:liste als Technik ohne Freigabe: der Tank fehlt');
    [$rc, $aus] = $power(['tank:liste', '--als=' . $k . '-admin', '--standort=' . $tankSite]);
    pruefe($rc === 0 && str_contains($aus, $k . ' Tank'), 'tank:liste als Administration: der Tank steht da');

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
    Http::$ohneCurl = false;
    foreach (['index.php', 'gerufen'] as $datei) {
        @unlink($umleitung . '/' . $datei);
    }
    @rmdir($umleitung);
    Config::set('app.key', $alterSchluessel);
    Http::$attrappe = null;
    Database::execute('UPDATE users SET is_active = 1 WHERE username LIKE ?', [$k . '%']);
    foreach (Database::select('SELECT id FROM api_partner WHERE name LIKE ?', [$k . '%']) as $p) {
        Database::execute('UPDATE sites SET partner_id = NULL WHERE partner_id = ?', [(int) $p['id']]);
        Database::execute('DELETE FROM api_partner WHERE id = ?', [(int) $p['id']]);
    }
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
