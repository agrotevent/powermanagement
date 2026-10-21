<?php
declare(strict_types=1);

/**
 * Anmeldung über SSO von außen – wie im Browser: eigene Instanz (SQLite,
 * eigene config.php), die App als eingebauter PHP-Server (A), die
 * OIDC-Attrappe als zweiter (B, Pfade und Antworten wie Authentik).
 * Weiterleitungen werden von Hand verfolgt, jede „Browser“-Sitzung hat ihre
 * eigene Cookie-Dose.
 *
 * Geprüft wird (§13.3 1–8 und 13):
 *   1. Anmeldeseite ohne und mit SSO: Knopf als Link, kein Formular nach
 *      außen, CSP unverändert, kaputter Zwischenspeicher → Seite geht.
 *   2. Abmelden mit Kennwort → „Abgemeldet.“
 *   3. Ablauf Start → Anbieter → Rückruf → Ziel; no-store, no-referrer,
 *      neue Sitzungs-ID.
 *   4. Derselbe Rückruf zweimal; ein Rückruf aus einer anderen Dose
 *      (Login-CSRF) meldet niemanden an.
 *   5. Abmelden mit SSO: /abgemeldet mit Link zum Abmelden beim Anbieter
 *      (id_token_hint, Rücksprung), einmal; danach prompt=login.
 *   6. Verbinden im Profil: nur von dieser Seite (Sec-Fetch-Site), danach
 *      per SSO angemeldet; eine zweite Verknüpfung derselben Identität geht
 *      nicht; Lösen im Profil.
 *   7. Anmeldeanfrage: unbekannte Person → Anfrage → freischalten → Anmeldung.
 *   8. Einstellungsseite: Rechte, Secret nie im HTML, „Prüfen“, ohne Token
 *      ändert keine neue Route etwas; Lösen und Sitzungen beenden in der
 *      Benutzerverwaltung.
 *  13. Die Seiten ohne PHP-Meldung (im HTML und im Fehlerprotokoll des Servers).
 * Dazu: Fehlertexte des Anbieters und Angaben aus Anfragen erscheinen nur
 * maskiert.
 */

require __DIR__ . '/hilfen/instanz.php';
if (getenv('PM_INSTANZ') !== '1') {
    // Der Port der App steht in app.url (Rückruf-Adresse) – also vor der Instanz wählen.
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $n = (string) stream_socket_get_name($s, false);
    fclose($s);
    // Viele absichtlich gescheiterte Anmeldungen von 127.0.0.1 – die Bremse prüft tests/sso.php.
    inEigenerInstanz(__FILE__, ['app' => ['url' => 'http://127.0.0.1:' . (int) substr($n, strrpos($n, ':') + 1)], 'sso' => ['lokal_http' => true],
        'security' => ['ip_attempts' => 1000]]);
}
require __DIR__ . '/hilfen/pruefung.php';
require_once __DIR__ . '/hilfen/oidc-attrappe.php';

use App\Auth\Sso\Konten;
use App\Services\BenutzerService;
use App\Support\Config;
use App\Support\Database;
use App\Support\Settings;

$wurzel = dirname(__DIR__);
$ordner = (string) getenv('PM_INSTANZ_ORDNER');
$basis = rtrim((string) Config::get('app.url'), '/');
$portA = (int) parse_url($basis, PHP_URL_PORT);
if ($ordner === '' || !is_dir($ordner) || $portA <= 0) {
    uebersprungen('sso-web', 'keine eigene Instanz');
}

// ------------------------------------------------------------ Server
$portB = freierPort();
$idpBasis = 'http://127.0.0.1:' . $portB;
$idp = OidcAttrappe::einrichten($ordner . '/oidc', $idpBasis, [$basis . '/login/sso/rueckruf'], [$basis . '/login']);
hintergrund(['php', '-S', '127.0.0.1:' . $portB, $wurzel . '/tests/hilfen/oidc-attrappe.php'],
    ['OIDC_ATTRAPPE' => $ordner . '/oidc', 'PHP_CLI_SERVER_WORKERS' => '4']);
mkdir($ordner . '/sitzungen', 0700);
$serverFehler = $ordner . '/server-fehler.txt';
touch($serverFehler);
hintergrund(['php', '-d', 'session.save_path=' . $ordner . '/sitzungen', '-d', 'log_errors=1', '-d', 'display_errors=0',
    '-S', '127.0.0.1:' . $portA, '-t', $wurzel . '/public'], ['PHP_CLI_SERVER_WORKERS' => '4'], $serverFehler);
for ($i = 0; $i < 50 && (!erreichbar('127.0.0.1', $portA) || !erreichbar('127.0.0.1', $portB)); $i++) {
    usleep(100_000);
}
if (!erreichbar('127.0.0.1', $portA) || !erreichbar('127.0.0.1', $portB)) {
    uebersprungen('sso-web', 'eingebauter PHP-Server startet nicht');
}

// ------------------------------------------------------------ Hilfen
$dosen = [];
/** Eine neue, leere Cookie-Dose – ein Browser. */
$dose = static function () use (&$dosen, $ordner): string {
    $d = $ordner . '/dose-' . count($dosen) . '-' . bin2hex(random_bytes(3)) . '.txt';
    touch($d);
    $dosen[] = $d;
    return $d;
};

/**
 * Ein Aufruf mit Cookie-Dose; folgt keinen Weiterleitungen. $url absolut oder
 * ein Pfad der App.
 *
 * @return array{status:int, kopf:string, text:string, ziel:string}
 */
function browser(string $url, string $dose, string $methode = 'GET', array $felder = [], array $kopf = []): array
{
    global $basis;
    $c = curl_init(str_starts_with($url, '/') ? $basis . $url : $url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $dose,
        CURLOPT_COOKIEFILE     => $dose,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $kopf,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($methode === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($felder));
    }
    $roh = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $laenge = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $kopfText = substr($roh, 0, $laenge);
    return ['status' => $status, 'kopf' => $kopfText, 'text' => substr($roh, $laenge),
        'ziel' => preg_match('/^Location:\s*(\S+)/mi', $kopfText, $m) === 1 ? $m[1] : ''];
}

function token(string $html): string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/** PHP-Meldungen im ausgelieferten HTML (nur mit display_errors). */
function phpMeldung(string $text): ?string
{
    return preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:.*?(?=<br|$)/s', $text, $m) ? strip_tags($m[0]) : null;
}

/** PHP-Meldungen im Fehlerprotokoll des Servers seit dem letzten Aufruf. @return list<string> */
function serverMeldungen(string $datei): array
{
    static $gelesen = 0;
    clearstatcache(true, $datei);
    $text = (string) @file_get_contents($datei, false, null, $gelesen);
    $gelesen += strlen($text);
    preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error):.*$/m', $text, $m);
    return $m[0];
}

/** Ein Cookie aus der Dose (Netscape-Format) – null, wenn es fehlt. */
function keks(string $dose, string $name): ?string
{
    foreach (file($dose, FILE_IGNORE_NEW_LINES) ?: [] as $zeile) {
        $teile = explode("\t", $zeile);
        if (count($teile) >= 7 && $teile[5] === $name) {
            return $teile[6];
        }
    }
    return null;
}

/** Die Query einer Adresse. */
function query(string $url): array
{
    parse_str((string) parse_url(html_entity_decode($url, ENT_QUOTES), PHP_URL_QUERY), $q);
    return $q;
}

/** Mit Kennwort anmelden; gibt die Dose zurück. */
$kennwortAnmelden = static function (string $name, string $kennwort) use ($dose): string {
    $d = $dose();
    $seite = browser('/login', $d);
    $r = browser('/login', $d, 'POST', ['username' => $name, 'password' => $kennwort, '_token' => token($seite['text'])]);
    pruefe($r['status'] === 302 && !str_starts_with($r['ziel'], '/login'), 'Kennwort-Anmeldung ' . $name . ' → ' . $r['ziel']);
    return $d;
};
/** Ein POST mit dem Token der Seite $seite. */
$senden = static function (string $d, string $pfad, array $felder = [], string $seite = '/profil'): array {
    return browser($pfad, $d, 'POST', $felder + ['_token' => token(browser($seite, $d)['text'])]);
};
/**
 * Anmelden über SSO: Start → Anbieter → Rückruf. Gibt die drei Antworten
 * zurück (null, wo der Weg vorher endete).
 *
 * @return array{0:array, 1:?array, 2:?array}
 */
$ssoAnmelden = static function (string $d, string $next = '/dashboard') use ($idpBasis, $basis): array {
    $start = browser('/login/sso?next=' . rawurlencode($next), $d);
    $auth = str_starts_with($start['ziel'], $idpBasis . '/') ? browser($start['ziel'], $d) : null;
    $rueck = $auth !== null && str_starts_with($auth['ziel'], $basis . '/login/sso/rueckruf?') ? browser($auth['ziel'], $d) : null;
    return [$start, $auth, $rueck];
};
/** Abmelden in der Dose $d. */
$abmelden = static function (string $d) use ($senden): array {
    return $senden($d, '/logout');
};
/** Die Protokolleinträge der Attrappe zu einem Pfad (authorize, token, end-session …). */
$protokoll = static function (string $pfad) use ($idp): array {
    return array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === $pfad));
};
/** Der Zwischenspeicher der Einstellungen gilt nur in diesem Prozess – nach Änderungen über den Server neu lesen. */
$frisch = static function (): void {
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
};
$person = static fn (string $sub, string $name, array $gruppen = []): array => ['sub' => $sub, 'preferred_username' => $name,
    'name' => 'Person ' . $name, 'email' => $name . '@example.com', 'email_verified' => true, 'groups' => $gruppen, 'amr' => ['pwd']];

// ------------------------------------------------------------ Konten
$kennung = 'pm-ssow-' . bin2hex(random_bytes(3));
$pw = 'Pruefung-' . bin2hex(random_bytes(5)) . '!A1';
$admin = BenutzerService::anlegen($kennung . '-admin', $pw, 'admin', false);
// Der Planer hat von Hand nur „Kunde / Veranstalter“ – Stromplanung kommt aus der Gruppe.
$planer = BenutzerService::anlegen($kennung . '-planer', $pw, 'kunde', false);
$verbinder = BenutzerService::anlegen($kennung . '-verbinder', $pw, 'planung', false);
$zweit = BenutzerService::anlegen($kennung . '-zweit', $pw, 'planung', false);
$erika = BenutzerService::anlegen($kennung . '-erika', $pw, 'planung', false);
\App\Auth\Auth::alsBenutzer($admin);
$verwalterRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . ' verwalter', '', ['system.users.manage', 'dashboard.view'])['id'];
\App\Auth\Auth::alsBenutzer(null);
$verwalter = BenutzerService::anlegen($kennung . '-verwalter', $pw, (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$verwalterRolle]), false);
$sessionName = (string) Config::get('security.session_name', 'pm_session');
$client = $idp->client();

// ================================================================ 1./2. ohne SSO
$d = $dose();
$login = browser('/login', $d);
pruefe($login['status'] === 200 && !str_contains($login['text'], 'sso-knopf') && !str_contains($login['text'], '/login/sso'), '1: ohne SSO kein Knopf');
pruefe(preg_match("/^Content-Security-Policy:.*form-action 'self'/mi", $login['kopf']) === 1, "1: CSP mit form-action 'self'");
$csp = preg_match('/^Content-Security-Policy:(.*)$/mi', $login['kopf'], $m) === 1 ? trim(preg_replace('/\'nonce-[^\']+\'/', '', $m[1])) : '';
$adminDose = $kennwortAnmelden($kennung . '-admin', $pw);
$r = $abmelden($adminDose);
pruefe($r['status'] === 302 && $r['ziel'] === '/login' && str_contains(browser('/login', $adminDose)['text'], 'Abgemeldet.'), '2: Abmelden mit Kennwort → „Abgemeldet.“');

// ================================================================ 8. Einstellungsseite
$verwalterDose = $kennwortAnmelden($kennung . '-verwalter', $pw);
gleich(browser('/system/sso', $verwalterDose)['status'], 403, '8: ohne sso.manage → /system/sso 403');
$r = $senden($verwalterDose, '/system/sso/verbindung', ['aussteller' => $idp->aussteller(), 'client_id' => $client['id'], 'client_secret' => $client['secret']]);
pruefe($r['status'] === 403 && (string) Database::scalar("SELECT setting_value FROM settings WHERE setting_key = 'sso.aussteller'") === '', '8: ohne sso.manage → Verbindung speichern 403');

$adminDose = $kennwortAnmelden($kennung . '-admin', $pw);
$seite = browser('/system/sso', $adminDose);
pruefe($seite['status'] === 200 && str_contains($seite['text'], 'Verbindung prüfen') && str_contains($seite['text'], 'Anmeldeanfragen'), '8: /system/sso (Administrator) 200');
pruefe(str_contains($seite['text'], 'value="' . $basis . '/login/sso/rueckruf"') && str_contains($seite['text'], 'data-kopieren="#sso-rueckruf"'),
    '8: Rückruf-Adresse zum Kopieren');
$r = $senden($adminDose, '/system/sso/verbindung', ['aussteller' => $idp->aussteller(), 'client_id' => $client['id'],
    'client_secret' => $client['secret'], 'name' => 'Authentik'], '/system/sso');
gleich([$r['status'], $r['ziel']], [302, '/system/sso'], '8: Verbindung gespeichert');
$seite = browser('/system/sso', $adminDose);
pruefe(!str_contains($seite['text'], $client['secret']) && !str_contains($seite['text'], htmlspecialchars($client['secret'], ENT_QUOTES))
    && !str_contains($seite['text'], rawurlencode($client['secret'])), '8: das Secret steht nicht im HTML');
pruefe(str_contains($seite['text'], '>hinterlegt<') && str_contains($seite['text'], View_e($idp->aussteller())), '8: Secret „hinterlegt“, Aussteller angezeigt');
$frisch();
pruefe((string) Settings::get(Settings::GEHEIM_SSO_SECRET, '') !== '' && !str_contains((string) Settings::get(Settings::GEHEIM_SSO_SECRET, ''), $client['secret']),
    '8: Secret verschlüsselt abgelegt');
$r = $senden($adminDose, '/system/sso/pruefen', [], '/system/sso');
$seite = browser('/system/sso', $adminDose);
pruefe($r['status'] === 302 && str_contains($seite['text'], 'alles in Ordnung') && str_contains($seite['text'], 'RS256') && str_contains($seite['text'], 'rsa-1')
    && str_contains($seite['text'], 'Aussteller: ' . View_e($idp->aussteller())), '8: „Prüfen“ zeigt Issuer, RS256, rsa-1');
pruefe(str_contains($seite['text'], 'Client-Zugang angenommen'), '8: „Prüfen“: Client-Zugang angenommen (Token-Probe)');
$r = $senden($adminDose, '/system/sso/schalten', ['an' => '1'], '/system/sso');
$frisch();
pruefe($r['status'] === 302 && \App\Auth\Sso\Einstellungen::aktiv() && str_contains(browser('/system/sso', $adminDose)['text'], 'ist eingeschaltet'),
    '8: eingeschaltet (nach frischer Prüfung)');
$gruppe = 'pm-planung-' . $kennung;
$planungId = (int) Database::scalar("SELECT id FROM roles WHERE role_key = 'planung'");
$r = $senden($adminDose, '/system/sso/zuordnung', ['gruppe' => $gruppe, 'ziel_id' => (string) $planungId], '/system/sso');
pruefe($r['status'] === 302 && Database::scalar('SELECT ziel_id FROM sso_zuordnungen WHERE gruppe = ?', [$gruppe]) !== false
    && str_contains(browser('/system/sso', $adminDose)['text'], '<code>' . $gruppe . '</code>'), '8: Gruppe → Rolle zugeordnet und in der Liste');
$r = $senden($adminDose, '/system/sso/zuordnung', ['gruppe' => 'pm-alles-' . $kennung, 'ziel_id' => (string) Database::scalar('SELECT id FROM roles WHERE is_system = 1')], '/system/sso');
pruefe(Database::scalar('SELECT id FROM sso_zuordnungen WHERE gruppe = ?', ['pm-alles-' . $kennung]) === false
    && str_contains(browser('/system/sso', $adminDose)['text'], 'ordnet nur zu, wer selbst alle Rechte hat'), '8: Rolle mit allen Rechten ohne „Verwaltung per SSO“ → abgelehnt');
$r = $senden($adminDose, '/system/sso/optionen', ['scopes' => 'openid profile email', 'gruppen_claim' => 'groups', 'pflichtgruppen' => '', 'inaktiv_tage' => '0'], '/system/sso');
$frisch();
pruefe($r['status'] === 302 && !\App\Auth\Sso\Einstellungen::schalter(\App\Auth\Sso\Einstellungen::ANLEGEN), '8: Optionen gespeichert (Anlage aus)');
// Eingaben nur aus dem Formular – was in der Adresse steht, zählt nicht.
$r = $senden($adminDose, '/system/sso/optionen?anlegen=1&scopes=openid&gruppen_claim=roles',
    ['scopes' => 'openid profile email', 'gruppen_claim' => 'groups', 'pflichtgruppen' => '', 'inaktiv_tage' => '0'], '/system/sso');
$frisch();
pruefe($r['status'] === 302 && !\App\Auth\Sso\Einstellungen::schalter(\App\Auth\Sso\Einstellungen::ANLEGEN)
    && \App\Auth\Sso\Einstellungen::scopes() === 'openid profile email' && \App\Auth\Sso\Einstellungen::gruppenClaim() === 'groups',
    '8: Eingaben nur aus dem Formular, nie aus der Adresse');

// Ohne Token ändert keine der neuen Routen etwas.
$zustand = static fn (): array => [
    Database::select("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sso.%' ORDER BY setting_key"),
    Database::select('SELECT * FROM sso_zuordnungen ORDER BY id'), Database::select('SELECT * FROM sso_identitaeten ORDER BY id'),
    Database::select('SELECT * FROM sso_anfragen ORDER BY id'), Database::select('SELECT id, nur_sso, is_active FROM users ORDER BY id'),
];
Konten::anfrageMerken(['sub' => 'ohne-token-' . $kennung, 'preferred_username' => 'x'], [], 'kein_konto');
Konten::verknuepfen($zweit, $idp->aussteller(), 'sub-zweit-alt-' . $kennung, 'cli', 'zweit-alt', []);
$anfrageOhne = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['ohne-token-' . $kennung]);
$zuordnungId = (int) Database::scalar('SELECT id FROM sso_zuordnungen WHERE gruppe = ?', [$gruppe]);
$vorher = $zustand();
$ohne = ['/system/sso/verbindung' => ['aussteller' => 'https://evil.example/', 'client_id' => 'x', 'client_secret' => str_repeat('s', 20)],
    '/system/sso/pruefen' => [], '/system/sso/schalten' => ['an' => '0'], '/system/sso/optionen' => ['anlegen' => '1', 'scopes' => 'openid'],
    '/system/sso/zuordnung' => ['gruppe' => 'pm-ohne-token', 'ziel_id' => (string) $planungId],
    '/system/sso/zuordnung/' . $zuordnungId . '/loeschen' => [], '/system/sso/anfrage/' . $anfrageOhne . '/freischalten' => ['user_id' => (string) $erika],
    '/system/sso/anfrage/' . $anfrageOhne . '/anlegen' => ['username' => $kennung . '-ohne'], '/system/sso/anfrage/' . $anfrageOhne . '/verwerfen' => [],
    '/system/benutzer/' . $zweit . '/sso' => ['aktion' => 'loesen'], '/profil/sso/loesen' => []];
foreach ($ohne as $pfad => $felder) {
    $r = browser($pfad, $adminDose, 'POST', $felder);
    pruefe($r['status'] === 302, '8: ' . $pfad . ' ohne _token → zurück (' . $r['status'] . ')');
}
pruefe($zustand() == $vorher, '8: ohne _token hat keine neue Route etwas geändert');
browser('/system/sso', $adminDose);   // die Meldungen „Sicherheitstoken ungültig“ abholen

// ================================================================ 1. Anmeldeseite mit SSO
$d = $dose();
$login = browser('/login', $d);
pruefe($login['status'] === 200 && preg_match('#<a class="btn btn-primary btn-block sso-knopf" href="/login/sso\?next=">Mit Authentik anmelden</a>#', $login['text']) === 1,
    '1: mit SSO der Knopf als Link auf /login/sso?next=');
pruefe(str_contains(browser('/login?next=' . rawurlencode('/karte?x=1'), $d)['text'], 'href="/login/sso?next=%2Fkarte%3Fx%3D1"'), '1: der Knopf trägt next mit');
preg_match_all('/<form[^>]*\saction="([^"]*)"/i', $login['text'], $m);
pruefe($m[1] !== [] && array_filter($m[1], static fn (string $a): bool => !str_starts_with($a, '/') || str_starts_with($a, '//')) === [], '1: kein Formular nach außen');
pruefe(str_contains($login['text'], 'action="/login"') && str_contains($login['text'], '<div class="oder">oder</div>'), '1: darunter „oder“ und das Kennwortformular');
$csp2 = preg_match('/^Content-Security-Policy:(.*)$/mi', $login['kopf'], $m) === 1 ? trim(preg_replace('/\'nonce-[^\']+\'/', '', $m[1])) : '-';
gleich($csp2, $csp, '1: CSP mit SSO unverändert');
$frisch();
$discovery = (string) Settings::get(\App\Auth\Sso\Einstellungen::DISCOVERY, '');
Settings::put(\App\Auth\Sso\Einstellungen::DISCOVERY, '{kaputt');
$idp->steuern(['discovery_404' => true]);
$r = browser('/login', $d);
pruefe($r['status'] === 200 && str_contains($r['text'], 'action="/login"') && str_contains($r['text'], 'sso-knopf'),
    '1: kaputter Discovery-Zwischenspeicher, Anbieter aus → Anmeldeseite 200 mit Kennwortformular');
$idp->zuruecksetzen();
Settings::put(\App\Auth\Sso\Einstellungen::DISCOVERY, $discovery);

// Escaped: der Fehlertext des Anbieters
$idp->benutzer($person('sub-planer-' . $kennung, 'planer-dort'));
$idp->steuern(['authorize_fehler' => 'access_denied', 'authorize_beschreibung' => '<script>alert("pm")</script> abgelehnt']);
[, , $rueck] = $ssoAnmelden($d);
$idp->zuruecksetzen();
$login = browser('/login', $d);
pruefe($rueck !== null && $rueck['ziel'] === '/login' && str_contains($login['text'], 'Authentik meldet: &lt;script&gt;alert(&quot;pm&quot;)&lt;/script&gt; abgelehnt')
    && !str_contains($login['text'], '<script>alert'), '1: Fehlertext des Anbieters erscheint maskiert');

// ================================================================ 3. Ablauf
Konten::verknuepfen($planer, $idp->aussteller(), 'sub-planer-' . $kennung, 'cli', 'planer-dort', []);
$idp->benutzer($person('sub-planer-' . $kennung, 'planer-dort', [$gruppe]));
$p = $dose();
browser('/login', $p);
$vorherKeks = keks($p, $sessionName);
[$start, $auth, $rueck] = $ssoAnmelden($p, '/dashboard');
pruefe($start['status'] === 302 && str_starts_with($start['ziel'], $idpBasis . '/application/o/authorize/?'), '3: /login/sso → Anbieter (authorize)');
pruefe($auth !== null && $auth['status'] === 302 && str_starts_with($auth['ziel'], $basis . '/login/sso/rueckruf?code='), '3: Anbieter → Rückruf mit code und state');
pruefe($rueck !== null && $rueck['status'] === 302 && $rueck['ziel'] === '/dashboard', '3: Rückruf → /dashboard');
pruefe($rueck !== null && preg_match('/^Cache-Control:\s*no-store/mi', $rueck['kopf']) === 1 && preg_match('/^Referrer-Policy:\s*no-referrer/mi', $rueck['kopf']) === 1,
    '3: Rückruf mit no-store und no-referrer');
pruefe($vorherKeks !== null && keks($p, $sessionName) !== null && keks($p, $sessionName) !== $vorherKeks, '3: neue Sitzungs-ID nach der Anmeldung');
gleich(browser('/dashboard', $p)['status'], 200, '3: /dashboard 200');
$profil = browser('/profil', $p);
pruefe(str_contains($profil['text'], $kennung . '-planer') && str_contains($profil['text'], 'angemeldet über Authentik')
    && str_contains($profil['text'], 'Stromplanung · über Gruppe'), '3: Profil: angemeldet über Authentik, Rolle aus der Gruppe');
gleich(phpMeldung($profil['text']), null, '13: /profil einer SSO-Sitzung ohne PHP-Meldung');
$planerDose = $p;

// ================================================================ 4. Wiederholung und Login-CSRF
$x = $dose();
browser('/login', $x);
$authX = browser(browser('/login/sso?next=/dashboard', $x)['ziel'], $x);
$y = $dose();
browser('/login', $y);
$r = browser($authX['ziel'], $y);
pruefe($r['status'] === 302 && $r['ziel'] === '/login' && browser('/dashboard', $y)['status'] === 302, '4: Rückruf aus Dose X in Dose Y → abgelehnt, Y bleibt abgemeldet');
$r = browser($authX['ziel'], $x);
pruefe($r['status'] === 302 && $r['ziel'] === '/dashboard', '4: … in Dose X selbst geht er');
$abmelden($x);
$r = browser($authX['ziel'], $x);
pruefe($r['status'] === 302 && $r['ziel'] === '/login' && str_contains(browser('/login', $x)['text'], 'abgelaufen oder wurde schon benutzt')
    && browser('/dashboard', $x)['status'] === 302, '4: dieselbe Rückruf-Adresse erneut → /login mit Meldung');

// ================================================================ 5. Abmelden mit SSO
$r = $abmelden($planerDose);
pruefe($r['status'] === 302 && $r['ziel'] === '/abgemeldet' && keks($planerDose, 'pm_sso_neu') === '1', '5: Abmelden einer SSO-Sitzung → /abgemeldet, pm_sso_neu');
$ab = browser('/abgemeldet', $planerDose);
$link = preg_match('#href="(' . preg_quote($idpBasis, '#') . '/application/o/pm-test/end-session/\?[^"]+)"#', $ab['text'], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : '';
pruefe($ab['status'] === 200 && $link !== '' && is_string(query($link)['id_token_hint'] ?? null) && (query($link)['client_id'] ?? null) === $client['id']
    && !isset(query($link)['post_logout_redirect_uri']), '5: /abgemeldet mit Link zum Abmelden beim Anbieter (id_token_hint, client_id, ohne Rücksprung)');
pruefe(preg_match('/^Cache-Control:\s*no-store/mi', $ab['kopf']) === 1 && preg_match('/^Referrer-Policy:\s*no-referrer/mi', $ab['kopf']) === 1
    && str_contains($ab['text'], 'Auch bei Authentik abmelden'), '5: /abgemeldet mit no-store, no-referrer und dem Knopf');
gleich(phpMeldung($ab['text']), null, '13: /abgemeldet ohne PHP-Meldung');
$r = browser($link, $planerDose);
$e = $protokoll('end-session');
pruefe($r['status'] === 200 && end($e)['hint_ok'] === true && end($e)['client_id_ok'] === true, '5: der Link → die Attrappe meldet hint_ok');
$r = browser('/abgemeldet', $planerDose);
pruefe($r['status'] === 302 && $r['ziel'] === '/login', '5: /abgemeldet erneut → /login');
$n = count($protokoll('authorize'));
[, $auth, $rueck] = $ssoAnmelden($planerDose);
$a = $protokoll('authorize');
pruefe(count($a) === $n + 1 && end($a)['prompt'] === 'login' && $rueck !== null && $rueck['ziel'] === '/dashboard', '5: die nächste SSO-Anmeldung mit prompt=login');
pruefe(keks($planerDose, 'pm_sso_neu') === null, '5: nach der Anmeldung ist pm_sso_neu gelöscht');
Settings::put(\App\Auth\Sso\Einstellungen::RUECKKEHR, '1');
$abmelden($planerDose);
$ab = browser('/abgemeldet', $planerDose);
$link = preg_match('#href="(' . preg_quote($idpBasis, '#') . '/application/o/pm-test/end-session/\?[^"]+)"#', $ab['text'], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : '';
pruefe((query($link)['post_logout_redirect_uri'] ?? null) === $basis . '/login', '5: mit „Rücksprung eingetragen“: post_logout_redirect_uri = A/login');
$r = browser($link, $planerDose);
$e = $protokoll('end-session');
pruefe($r['status'] === 302 && $r['ziel'] === $basis . '/login' && end($e)['post_logout_redirect_uri'] === $basis . '/login', '5: der Anbieter springt zurück nach A/login');
Settings::put(\App\Auth\Sso\Einstellungen::RUECKKEHR, '0');

// ================================================================ 6. Verbinden
$v = $kennwortAnmelden($kennung . '-verbinder', $pw);
$profil = browser('/profil', $v);
pruefe(str_contains($profil['text'], 'href="/login/sso/verbinden"') && str_contains($profil['text'], 'nicht mit Authentik verknüpft'), '6: Profil ohne Verknüpfung: Link „verbinden“');
gleich(phpMeldung($profil['text']), null, '13: /profil ohne Identität ohne PHP-Meldung');
$n = count($protokoll('authorize'));
$r = browser('/login/sso/verbinden', $v, 'GET', [], ['Sec-Fetch-Site: cross-site']);
pruefe($r['status'] === 403 && $r['ziel'] === '' && !str_contains($r['text'], 'action="/logout"'), '6: Verbinden mit Sec-Fetch-Site: cross-site → 403');
gleich(browser('/login/sso/verbinden', $dose(), 'GET', [], ['Sec-Fetch-Site: same-origin'])['ziel'], '/login?next=%2Fprofil', '6: Verbinden ohne Anmeldung → /login?next=/profil');
$idp->benutzer($person('sub-verbinder-' . $kennung, 'verbinder-dort'));
$r = browser('/login/sso/verbinden', $v, 'GET', [], ['Sec-Fetch-Site: same-origin']);
$q = query($r['ziel']);
pruefe($r['status'] === 302 && str_starts_with($r['ziel'], $idpBasis . '/application/o/authorize/?') && ($q['prompt'] ?? null) === 'login' && ($q['max_age'] ?? null) === '0',
    '6: Verbinden mit same-origin → Anbieter mit prompt=login und max_age=0');
$auth = browser($r['ziel'], $v);
$rueck = browser($auth['ziel'], $v);
$profil = browser('/profil', $v);
pruefe($rueck['status'] === 302 && $rueck['ziel'] === '/profil' && str_contains($profil['text'], 'Mit Authentik verbunden.') && str_contains($profil['text'], 'Verknüpft seit')
    && str_contains($profil['text'], 'verbinder-dort'), '6: danach zeigt /profil „verbunden“ mit Name dort');
pruefe(!str_contains($profil['text'], 'angemeldet über Authentik') && str_contains($profil['text'], 'Verknüpfung lösen'), '6: die Sitzung bleibt eine Kennwort-Sitzung');
gleich(phpMeldung($profil['text']), null, '13: /profil mit Identität ohne PHP-Meldung');
gleich((string) (Konten::identitaet($verbinder)['weg'] ?? ''), 'profil', '6: Verknüpfung mit Weg „profil“');
$z = $kennwortAnmelden($kennung . '-erika', $pw);
$r = browser('/login/sso/verbinden', $z);
$rueck = browser(browser($r['ziel'], $z)['ziel'], $z);
pruefe($rueck['ziel'] === '/profil' && str_contains(browser('/profil', $z)['text'], 'schon mit einem anderen Konto verknüpft') && Konten::identitaet($erika) === null,
    '6: ein zweites Konto mit derselben Identität → „schon verknüpft“');
$abmelden($z);
$abmelden($v);
$v = $dose();
[, , $rueck] = $ssoAnmelden($v, '/profil');
$profil = browser('/profil', $v);
pruefe($rueck !== null && $rueck['ziel'] === '/profil' && str_contains($profil['text'], $kennung . '-verbinder') && str_contains($profil['text'], 'angemeldet über Authentik'),
    '6: danach meldet sich das Konto per SSO an');
// Lösen im Profil einer SSO-Sitzung: abgemeldet, mit Kennwort neu anmelden.
$r = $senden($v, '/profil/sso/loesen');
pruefe($r['status'] === 302 && $r['ziel'] === '/login' && str_contains(browser('/login', $v)['text'], 'Verknüpfung gelöst – bitte mit Kennwort anmelden.')
    && Konten::identitaet($verbinder) === null && browser('/profil', $v)['status'] === 302, '6: Lösen im Profil (SSO-Sitzung) → abgemeldet, Verknüpfung weg');

// ================================================================ 7. Anmeldeanfrage
$idp->benutzer($person('sub-erika-' . $kennung, $kennung . '-erika', [$gruppe]));
$u = $dose();
[, , $rueck] = $ssoAnmelden($u);
pruefe($rueck !== null && $rueck['ziel'] === '/login' && str_contains(browser('/login', $u)['text'], 'noch kein Konto') && browser('/dashboard', $u)['status'] === 302,
    '7: unbekannte Person → /login mit „noch kein Konto“');
$anfrage = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['sub-erika-' . $kennung]);
// Angaben aus dem Token erscheinen maskiert.
$idp->benutzer($person('sub-boese-' . $kennung, '<img src=x onerror=alert(1)>', ['<b>gruppe</b>']));
$ssoAnmelden($dose());
$seite = browser('/system/sso', $adminDose);
pruefe($anfrage > 0 && str_contains($seite['text'], '/system/sso/anfrage/' . $anfrage . '/freischalten') && str_contains($seite['text'], $kennung . '-erika'),
    '7: der Administrator sieht die Anfrage auf /system/sso');
pruefe(preg_match('#<option value="' . $erika . '" selected>#', $seite['text']) === 1, '7: Vorschlag: das gleichnamige Konto vorausgewählt');
pruefe(str_contains($seite['text'], '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($seite['text'], '<img src=x') && str_contains($seite['text'], '&lt;b&gt;gruppe&lt;/b&gt;'),
    '7: Name und Gruppen einer Anfrage erscheinen maskiert');
$r = $senden($adminDose, '/system/sso/anfrage/' . $anfrage . '/freischalten', ['user_id' => (string) $erika], '/system/sso');
pruefe($r['status'] === 302 && str_starts_with($r['ziel'], '/system/sso') && (string) (Konten::identitaet($erika)['weg'] ?? '') === 'freischaltung'
    && Database::scalar('SELECT id FROM sso_anfragen WHERE id = ?', [$anfrage]) === false, '7: freigeschaltet an das Konto');
$idp->benutzer($person('sub-erika-' . $kennung, $kennung . '-erika', [$gruppe]));
[, , $rueck] = $ssoAnmelden($u);
pruefe($rueck !== null && $rueck['ziel'] === '/dashboard' && str_contains(browser('/profil', $u)['text'], $kennung . '-erika'), '7: die nächste Anmeldung geht');
$boese = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['sub-boese-' . $kennung]);
$r = $senden($adminDose, '/system/sso/anfrage/' . $boese . '/verwerfen', [], '/system/sso');
pruefe(Database::scalar('SELECT id FROM sso_anfragen WHERE id = ?', [$boese]) === false, '7: Anfrage verworfen');

// ================================================================ 8. Benutzerverwaltung: Lösen, Sitzungen beenden
$seite = browser('/system/benutzer', $adminDose);
pruefe($seite['status'] === 200 && str_contains($seite['text'], 'data-label="Anmeldung"') && str_contains($seite['text'], '>SSO</span>'),
    '8: Benutzerliste mit Abzeichen der Anmeldung');
$seite = browser('/system/benutzer?id=' . $planer, $adminDose);
pruefe(str_contains($seite['text'], 'form="sso-konto"') && str_contains($seite['text'], 'id="sso-konto"') && str_contains($seite['text'], 'name="nur_sso_feld"'),
    '8: Formular mit Abschnitt SSO (Lösen, Sitzungen beenden, nur über SSO)');
pruefe(preg_match('#<input type="checkbox" checked disabled> Stromplanung#', $seite['text']) === 1 && str_contains($seite['text'], 'über Gruppe bei Authentik, bis')
    && preg_match('#name="rollen\[\d+\]\[aktiv\]" value="1" checked> Kunde / Veranstalter#', $seite['text']) === 1,
    '8: Rolle aus der Gruppe angehakt und gesperrt („über Gruppe, bis …“), die von Hand anhakbar');
gleich(phpMeldung($seite['text']), null, '13: /system/benutzer?id= (verknüpft) ohne PHP-Meldung');
// Speichern lässt die Rolle aus der Gruppe stehen (das gesperrte Häkchen kommt nicht mit).
preg_match_all('#<input type="checkbox" name="(rollen\[(\d+)\]\[aktiv\])" value="1" checked>#', $seite['text'], $m);
$felder = ['id' => (string) $planer, 'username' => $kennung . '-planer', 'full_name' => '', 'email' => '', 'is_active' => '1', 'nur_sso_feld' => '1', 'notizen' => ''];
foreach ($m[1] as $feld) {
    $felder[$feld] = '1';
}
$r = $senden($adminDose, '/system/benutzer/speichern', $felder, '/system/benutzer?id=' . $planer);
gleich(Database::select("SELECT role_id, quelle FROM user_roles WHERE user_id = ? ORDER BY quelle", [$planer]),
    [['role_id' => (int) Database::scalar("SELECT id FROM roles WHERE role_key = 'kunde'"), 'quelle' => 'hand'], ['role_id' => $planungId, 'quelle' => 'sso']],
    '8: Speichern in der Benutzerverwaltung lässt die Rolle aus der Gruppe stehen');
// Verwalter ohne darfVerwalten gegenüber dem Planer (Rechte, die er nicht hat) → abgelehnt.
$r = $senden($verwalterDose, '/system/benutzer/' . $planer . '/sso', ['aktion' => 'loesen'], '/system/benutzer');
pruefe(Konten::identitaet($planer) !== null && str_contains(browser('/system/benutzer', $verwalterDose)['text'], 'Rechte, die du selbst nicht hast'),
    '8: Lösen ohne darfVerwalten → abgelehnt');
$idp->benutzer($person('sub-planer-' . $kennung, 'planer-dort', [$gruppe]));
$ssoAnmelden($planerDose);
gleich(browser('/dashboard', $planerDose)['status'], 200, '8: Planer per SSO angemeldet');
$r = $senden($adminDose, '/system/benutzer/' . $planer . '/sso', ['aktion' => 'sitzungen'], '/system/benutzer?id=' . $planer);
pruefe($r['status'] === 302 && $r['ziel'] === '/system/benutzer?id=' . $planer && browser('/dashboard', $planerDose)['status'] === 302
    && Konten::identitaet($planer)['abgemeldet_am'] !== null, '8: SSO-Sitzungen beenden → die Sitzung des Planers endet');
$r = $senden($adminDose, '/system/benutzer/' . $planer . '/sso', ['aktion' => 'loesen', 'zurueck' => 'sso'], '/system/sso');
pruefe($r['status'] === 302 && $r['ziel'] === '/system/sso#verknuepft' && Konten::identitaet($planer) === null, '8: Lösen (von /system/sso aus) → Verknüpfung weg');

// ================================================================ 13. ohne PHP-Meldung
foreach ([['/login', $dose()], ['/abgemeldet', $dose()], ['/profil', $adminDose], ['/profil', $u], ['/system/sso', $adminDose], ['/system/benutzer', $adminDose],
    ['/system/benutzer?id=' . $erika, $adminDose], ['/system/benutzer?neu=1', $adminDose]] as [$pfad, $dd]) {
    $r = browser($pfad, $dd);
    pruefe(in_array($r['status'], [200, 302], true) && phpMeldung($r['text']) === null, '13: ' . $pfad . ' ohne PHP-Meldung (' . $r['status'] . ')');
}
$seite = browser('/system/sso', $adminDose);
pruefe(str_contains($seite['text'], $kennung . '-erika') && str_contains($seite['text'], '<code>' . $gruppe . '</code>') && str_contains($seite['text'], '✓ ')
    && str_contains($seite['text'], 'id="verknuepft"'), '13: /system/sso mit Zuordnung, Verknüpfungen und Befund');
gleich(serverMeldungen($serverFehler), [], '13: keine PHP-Meldung im Fehlerprotokoll des Servers');
$fehler = trim((string) file_get_contents($serverFehler));
pruefe(preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', $fehler) !== 1, '13: auch insgesamt keine PHP-Meldung' . ($fehler !== '' ? ': ' . mb_substr($fehler, 0, 300) : ''));

// Geheimnisse: Secret und Tokens nie im Audit, in keiner Sitzungsdatei das Secret.
$audit = json_encode(Database::select('SELECT * FROM audit_log'));
pruefe(!str_contains((string) $audit, $client['secret']) && !str_contains((string) $audit, 'code-'), 'Audit ohne Secret und Code');
$sitzungen = implode("\n", array_map('file_get_contents', glob($ordner . '/sitzungen/sess_*') ?: []));
pruefe(!str_contains($sitzungen, $client['secret']), 'Sitzungsdateien ohne Secret');

/** Wie View::e – hier ohne Abhängigkeit vom Ansichtsordner. */
function View_e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

ende('sso-web');
