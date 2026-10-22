<?php
declare(strict_types=1);

/**
 * Anmeldung über SSO (OpenID Connect, Authentik) – im Prozess gegen die
 * OIDC-Attrappe (Http::$attrappe), in einer eigenen Instanz (eigene SQLite-
 * Datenbank, die sso.*-Einstellungen der Arbeitskopie bleiben unberührt).
 *
 * A. ID-Token und Schlüssel (rein): RS256/ES256 (auch R/S mit führenden
 *    Nullen), alg-Verwechslung (none, HS256 mit dem öffentlichen Schlüssel,
 *    RS512, RS256 mit EC-Schlüssel), Format (crit, enc, Teile, Base64, Tiefe,
 *    Länge), Signatur, iss/aud/azp/exp/iat/nbf/nonce/sub/auth_time, JWKs.
 * B. Discovery (Felder, Ablehnungen, Zwischenspeicher 24 h), Schlüsselwechsel
 *    (JWKS frühestens alle 300 s neu), „Verbindung prüfen“ mit Token-Probe
 *    (Basic mit Sonderzeichen-Secret, Post), keine Geheimnisse im Stack-Trace.
 * C. Einstellungen: Secret nur verschlüsselt und an die Verbindung gebunden,
 *    Marke, Eingaben, Einschalten nur nach frischer Prüfung, Ausschalten,
 *    der Knopf wirft nie. Dazu: Secret weder im Audit noch im Log.
 * D. Start: Parameter (redirect_uri nie aus Host), höchstens fünf Vorgänge,
 *    prompt/max_age, Ziel nach der Anmeldung.
 * E. Rückruf: state einmalig und ohne Audit, Marke, iss, angemeldete
 *    Sitzung, SSO aus / neue Marke / „Verwaltung per SSO“ aus, während der
 *    Rückruf wartet (nichts geschrieben), Fehler des Anbieters,
 *    Token-Endpunkt (basic/post), ID-Token, Schlüsselwechsel RSA → EC
 *    (Discovery gedrosselt neu), Userinfo, Bremse je Adresse.
 * F. Verknüpfung nur über iss+sub (auch Groß/Klein), Anmeldeanfragen
 *    (merken, freischalten, anlegen, verwerfen, höchstens 200), automatische
 *    Anlage (nur mit zugeordneter Gruppe, Name frei).
 * G. Prüfkette: deaktiviert, Zeitraum, locked_until gilt nicht,
 *    Pflichtgruppe, Verwaltung nur mit sso.admins, MFA.
 * H. Rollen aus Gruppen: hinzu/weg, keine zweite Zeile, Benutzerverwaltung,
 *    Ablauf (auch Rollen-Freigaben), Kennwort-Anmeldung, Zuordnung löschen,
 *    *-Rolle übergangen, zuordnungFehler, Gruppe „123“, dieselbe Gruppe in
 *    anderer Schreibweise, eine neue Verbindung nur mit den Rechten aller
 *    Zuordnungen.
 * I. Sitzung: sitzungGilt (Marke, Sitzungen beendet, Dauer, aus, sso.admins,
 *    Lösen), kennwortVerboten, „nur SSO“ und Notzugang.
 * J. Schutz speichern: nur mit allen Rechten; „nur SSO“ nur mit SSO-Sitzung,
 *    Verwaltung per SSO, Notzugang, erfolgreicher Prüfung und Eintippen;
 *    MFA-Pflicht nicht aus einer SSO-Sitzung ohne zweiten Faktor; Kennwort-
 *    Anmeldung danach (verknüpft abgelehnt, Notzugang laut, Kunde normal);
 *    der letzte Notzugang bleibt; sso.admins aus nimmt verwaltende Rollen
 *    aus Gruppen; der letzte (dauerhafte) Notzugang auch nicht auf Termin;
 *    bin/power sso:aus --nur-sso.
 * K. Bestätigen (Step-up): token:<id> einmal, 300 s, nur dieser Zweck und
 *    dieses Konto; andere Person, nicht neu angemeldet, Verwaltung ohne
 *    sso.admins (W7), MFA-Pflicht; danach „beim Anmeldedienst angemeldet“
 *    (fürs Abmelden); Auth::bestaetigen mit Kennwort bzw. nie bei nur_sso;
 *    Kundenmodus entsperren (Fehlversuche zählen, Bremse vor dem Netz).
 * L. Kunden über Gruppen (nur mit Kundenbereich): sso.kunden und
 *    Kundengruppen nur mit kunden.manage und nur für Kunden, deren Zugänge man
 *    ausgeben dürfte; aus → kein Zugang; an → Kundenzugang (kunde_id, nur_sso,
 *    keine Rolle, keine Rechte, landet im Kundenbereich); zwei Kunden,
 *    gemischt, Kunde inaktiv, andere bzw. fehlende Kundengruppe; lokale
 *    Kundenzugänge nie verknüpft; Anfragen mit Kundengruppe nie Personal;
 *    sso.kunden aus und Gruppe entfernen beenden die SSO-Sitzungen; die Haken
 *    im Kundenbereich (Einrichtungslink, Kennwort, Name, Liste, Löschen).
 * M. Keine Geheimnisse (Code, Access-/ID-Token) in Sitzung, Audit und Log.
 * N. Kommandozeile (Kindprozesse in der Instanz, die Attrappe über
 *    auto_prepend_file): sso:zustand ohne Secret, sso:pruefen (Exit 1 bei ✗),
 *    sso:einrichten nur über die Standardeingabe (nie als Argument),
 *    sso:an/aus, sso:verknuepfungen (Subjekt gekürzt), sso:aussteller-
 *    umstellen (nur mit --ja, Zusammenstoß → nichts, danach geht die
 *    Anmeldung), user:password bei „nur SSO“, pruefen mit SSO-Zeile,
 *    sso:loesen; Wartung (abgelaufene Rollen aus Gruppen, alte Anfragen,
 *    sso.inaktiv_tage – im Prozess, siehe dort); verbinden_ueber und
 *    ca_datei aus config.php bis Http, mit echtem curl gegen einen TLS-Server
 *    (openssl s_server): Verbindung über 127.0.0.1, Zertifikat und Name
 *    geprüft, ohne curl abgelehnt.
 * O. Während eines Updates (Schreibsperre der Instanz): Meldungen je Zweck;
 *    ein Rückruf, der während der Sperre ankommt, schreibt nichts und löst
 *    den Code nicht ein (Vorgang verbraucht); beginnt das Update während des
 *    Wartens, war er angemeldet und fragt danach noch einmal – kein Konto
 *    (Anlage), keine Anfrage, kein Fehlschlag, nichts verknüpft, Kundenmodus
 *    bleibt ohne Fehlversuch; Einschalten bleibt aus. Migration 017 nach der
 *    Sicherung auf einer Datenbank wie auf main, Zurückrollen
 *    (db_zuruecklegen), noch einmal migrieren; das Secret übersteht Update
 *    und Zurückrollen.
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['url' => 'https://pm.example.test']]);
require __DIR__ . '/hilfen/pruefung.php';
require __DIR__ . '/hilfen/oidc-attrappe.php';

use App\Auth\Auth;
use App\Auth\Sso\Anbieter;
use App\Auth\Sso\Anmeldung;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\IdToken;
use App\Auth\Sso\Jwk;
use App\Auth\Sso\Konten;
use App\Auth\Sso\Kundenbereich as SsoKunden;
use App\Auth\Sso\Schutz;
use App\Auth\Sso\SsoFehler;
use App\Auth\Zugriff;
use App\Berichte\BerichtService;
use App\Berichte\Kundenmodus;
use App\Kunden\Einrichtung;
use App\Kunden\KundenService;
use App\Messung\Http;
use App\Services\BenutzerService;
use App\Services\UpdateService;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Settings;
use App\Support\Zeit;

$instanzOrdner = (string) getenv('PM_INSTANZ_ORDNER');
if (getenv('PM_INSTANZ') !== '1' || $instanzOrdner === '' || !is_dir($instanzOrdner)) {
    pruefe(false, 'läuft in einer eigenen Instanz');
    ende('sso');
}
pruefe(str_starts_with((string) Config::get('database.path'), $instanzOrdner), 'eigene Datenbank der Instanz');

$kennung = 'pm-sso-' . bin2hex(random_bytes(3));
$logDatei = APP_ROOT . '/storage/logs/app.log';
clearstatcache();
$logVorher = is_file($logDatei) ? (int) filesize($logDatei) : 0;

$uhr = time();
Einstellungen::$uhr = static function () use (&$uhr): int {
    return $uhr;
};
OidcAttrappe::$uhr = Einstellungen::$uhr;
$idp = OidcAttrappe::einrichten($instanzOrdner . '/oidc', 'https://idp.example.test', ['https://pm.example.test/login/sso/rueckruf'],
    ['https://pm.example.test/login']);
Http::$attrappe = $idp->http();
$iss = $idp->aussteller();
$client = $idp->client();
gleich($iss, 'https://idp.example.test/application/o/pm-test/', 'Aussteller der Attrappe');

/** Der Prüfcode einer SsoFehler-Ausnahme oder „ok“. */
$code = static function (callable $f): string {
    try {
        $f();
        return 'ok';
    } catch (SsoFehler $e) {
        return $e->pruefung;
    }
};
/** Die Meldung einer SsoFehler-Ausnahme oder ''. */
$meldung = static function (callable $f): string {
    try {
        $f();
        return '';
    } catch (SsoFehler $e) {
        return $e->getMessage();
    }
};
/** Die Zahl der Anfragen an einen Pfad der Attrappe. */
$anfragen = static function (string $pfad) use ($idp): int {
    return count(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === $pfad));
};

// ================================================================ A. ID-Token und Schlüssel (rein)
$jwkRsa = Jwk::schluessel($idp->jwk('rsa'));
$jwkEc = Jwk::schluessel($idp->jwk('ec'));
pruefe(is_array($jwkRsa) && $jwkRsa['kid'] === 'rsa-1' && $jwkRsa['kty'] === 'RSA' && $jwkRsa['alg'] === 'RS256', 'JWK RSA 2048 (mit x5c wie Authentik) gelesen');
pruefe(is_array($jwkEc) && $jwkEc['kid'] === 'ec-1' && $jwkEc['kty'] === 'EC', 'JWK EC P-256 gelesen');
$bekannt = array_filter([$jwkRsa, $jwkEc]);
// Absichtlich nur über die kid – so prüft IdToken selbst, ob kty zu alg passt.
$nachKid = static function (string $kid, string $kty) use ($bekannt): ?array {
    foreach ($bekannt as $s) {
        if ($s['kid'] === $kid) {
            return $s;
        }
    }
    return null;
};
$nonce = 'n-' . $kennung;
$sub = 'sub-' . $kennung;
$soll = static fn (array $extra = []): array => $extra + ['aussteller' => $iss, 'client_id' => $client['id'], 'algs' => ['RS256', 'ES256'],
    'nonce' => $nonce, 'jetzt' => $uhr, 'auth_nach' => null];
$claims = static fn (array $extra = []): array => array_filter($extra + ['iss' => $iss, 'sub' => $sub, 'aud' => $client['id'], 'exp' => $uhr + 300,
    'iat' => $uhr, 'nonce' => $nonce, 'auth_time' => $uhr - 30, 'preferred_username' => 'erika'], static fn (mixed $v): bool => $v !== null);
$pruef = static function (string $jwt, array $sollExtra = [], ?Closure $schluessel = null) use ($code, $soll, $nachKid): string {
    return $code(static fn () => IdToken::pruefen($jwt, $soll($sollExtra), $schluessel ?? $nachKid));
};

// A.1 gültig
$c = IdToken::pruefen($idp->idToken($claims()), $soll(), $nachKid);
pruefe(($c['sub'] ?? null) === $sub && ($c['preferred_username'] ?? null) === 'erika', 'RS256 gültig, Claims zurück');
gleich($pruef($idp->idToken($claims(), [], 'ec')), 'ok', 'ES256 gültig');
$gesehen = ['null' => false, 'hoch' => false];
$alleGut = true;
$derGleich = true;
$ecPrivat = openssl_pkey_get_private((string) file_get_contents($instanzOrdner . '/oidc/ec.pem'));
for ($i = 0; $i < 400 && !($gesehen['null'] && $gesehen['hoch']); $i++) {
    $t = $idp->idToken($claims(['jti' => 'v' . $i]), [], 'ec');
    $sig = IdToken::b64(explode('.', $t)[2]);
    [$r, $s] = [substr($sig, 0, 32), substr($sig, 32)];
    $gesehen['null'] = $gesehen['null'] || $r[0] === "\0" || $s[0] === "\0";
    $gesehen['hoch'] = $gesehen['hoch'] || ord($r[0]) > 0x7f || ord($s[0]) > 0x7f;
    $alleGut = $alleGut && $pruef($t) === 'ok';
    openssl_sign('probe-' . $i, $der, $ecPrivat, OPENSSL_ALGO_SHA256);
    $derGleich = $derGleich && Jwk::es256Der(OidcAttrappe::derZuRs($der)) === $der;
}
pruefe($alleGut, 'ES256 gültig, auch mit führenden Null-Bytes bzw. gesetztem obersten Bit in R oder S (' . $i . ' Versuche, gesehen: ' . json_encode($gesehen) . ')');
pruefe($derGleich, 'ES256: R‖S → DER entspricht genau dem DER von openssl_sign');

// A.2 alg
gleich($pruef($idp->idToken($claims(), [], 'none')), 'alg', 'alg none → alg');
$t = $idp->idToken($claims(), [], 'none');
gleich($pruef(substr($t, 0, strrpos($t, '.') + 1)), 'format', 'alg none ohne Signatur-Teil → format');
$hmac = $idp->idToken($claims(), [], 'hmac:' . $jwkRsa['pem']);
gleich($pruef($hmac), 'alg', 'HS256 mit dem öffentlichen RSA-Schlüssel als Geheimnis → alg');
gleich($pruef($hmac, ['algs' => ['HS256', 'RS256']]), 'alg', 'HS256 auch dann nicht, wenn die Discovery es nennt');
gleich($pruef($idp->idToken($claims(), ['alg' => 'RS512'])), 'alg', 'RS512 → alg');
gleich($pruef($idp->idToken($claims(), ['alg' => null])), 'alg', 'ohne alg → alg');
gleich($pruef($idp->idToken($claims(), [], 'ec'), ['algs' => ['RS256']]), 'alg', 'ES256, wenn die Discovery nur RS256 nennt → alg');
gleich($pruef($idp->idToken($claims(), ['kid' => 'ec-1'], 'rsa')), 'kid', 'RS256 unter der kid des EC-Schlüssels → kid');
gleich($pruef($idp->idToken($claims(), ['kid' => 'rsa-1'], 'ec')), 'kid', 'ES256 unter der kid des RSA-Schlüssels → kid');
// Auch wenn der Schlüssel kein alg nennt: kty muss zu alg passen.
gleich($pruef($idp->idToken($claims(), ['kid' => 'ec-1'], 'rsa'), [], static fn (string $kid, string $kty): array => ['alg' => ''] + $jwkEc), 'kid',
    'RS256 mit EC-Schlüssel ohne alg → kid');
gleich($pruef($idp->idToken($claims(), ['kid' => 'rsa-1'], 'ec'), [], static fn (string $kid, string $kty): array => ['alg' => ''] + $jwkRsa), 'kid',
    'ES256 mit RSA-Schlüssel ohne alg → kid');
gleich($pruef($idp->idToken($claims()), [], static fn (string $kid, string $kty): array => ['alg' => 'RS384'] + $jwkRsa), 'kid', 'Schlüssel mit alg RS384 → kid');
pruefe(Jwk::schluessel(['alg' => 'RS384'] + $idp->jwk('rsa')) === null, 'JWK mit alg RS384 ist kein Signaturschlüssel für RS256');
gleich($pruef($idp->idToken($claims(), ['kid' => 'unbekannt'])), 'kid', 'unbekannte kid → kid');
gleich($pruef($idp->idToken($claims(), ['kid' => ['rsa-1']])), 'format', 'kid als Liste → format');

// A.3 Format
gleich($pruef($idp->idToken($claims(), ['crit' => ['exp']])), 'format', 'crit → format');
gleich($pruef($idp->idToken($claims(), ['enc' => 'A256GCM'])), 'format', 'enc → format');
$gut = $idp->idToken($claims());
gleich($pruef($gut . '.' . OidcAttrappe::b64('x') . '.' . OidcAttrappe::b64('y')), 'format', 'fünf Teile (JWE) → format');
gleich($pruef(substr($gut, 0, strrpos($gut, '.'))), 'format', 'zwei Teile → format');
$teile = explode('.', $gut);
foreach (['=' => $teile[2] . '=', '+' => '+' . substr($teile[2], 1), '/' => '/' . substr($teile[2], 1)] as $zeichen => $sigTeil) {
    gleich($pruef($teile[0] . '.' . $teile[1] . '.' . $sigTeil), 'format', 'Base64 mit „' . $zeichen . '“ → format');
}
$letztes = substr($teile[2], -1);
$nichtKanonisch = substr($teile[2], 0, -1) . (strpos('AQgw', $letztes) !== false ? chr(ord($letztes) + 1) : $letztes);
if ($nichtKanonisch !== $teile[2]) {
    gleich($pruef($teile[0] . '.' . $teile[1] . '.' . $nichtKanonisch), 'format', 'Base64 nicht kanonisch (Restbits gesetzt) → format');
}
gleich($pruef($idp->signieren('["RS256"]', json_encode($claims()))), 'format', 'Kopf als JSON-Liste → format');
$tief = static function (int $ebenen) use ($claims): string {
    $innen = '1';
    for ($j = 0; $j < $ebenen; $j++) {
        $innen = '{"a":' . $innen . '}';
    }
    return substr(json_encode($claims()), 0, -1) . ',"tief":' . $innen . '}';
};
pruefe(json_decode($tief(15), true, 17) !== null && json_decode($tief(15), true, 16) === null, 'Prüfling: Claims mit Tiefe 17');
gleich($pruef($idp->signieren('{"alg":"RS256","kid":"rsa-1"}', $tief(15))), 'format', 'Claims tiefer als 16 Ebenen → format');
gleich($pruef($idp->signieren('{"alg":"RS256","kid":"rsa-1"}', $tief(14))), 'ok', 'Claims mit 16 Ebenen → gültig');
$lang = $idp->idToken($claims(['fuellung' => str_repeat('a', IdToken::MAX_LAENGE)]));
pruefe(strlen($lang) > IdToken::MAX_LAENGE, 'Prüfling: Token über 16 KiB');
gleich($pruef($lang), 'format', 'Token über 16 KiB → format');
gleich($pruef(' ' . $gut), 'format', 'Leerzeichen vor dem Token → format');

// A.4 Signatur
$sig = IdToken::b64($teile[2]);
$sig[10] = chr(ord($sig[10]) ^ 0x04);
gleich($pruef($teile[0] . '.' . $teile[1] . '.' . OidcAttrappe::b64($sig)), 'signatur', 'ein Bit der Signatur gekippt → signatur');
$ecTeile = explode('.', $idp->idToken($claims(), [], 'ec'));
gleich($pruef($ecTeile[0] . '.' . $ecTeile[1] . '.' . OidcAttrappe::b64(substr(IdToken::b64($ecTeile[2]), 0, 63))), 'signatur', 'ES256 mit 63 Byte → signatur');
gleich($pruef($ecTeile[0] . '.' . $ecTeile[1] . '.' . OidcAttrappe::b64(str_repeat("\xff", 64))), 'signatur', 'ES256 mit unsinnigem R‖S → signatur');
gleich($pruef($teile[0] . '.' . $teile[1] . '.' . OidcAttrappe::b64('kurz')), 'signatur', 'RSA-Signatur falscher Länge → signatur');
gleich($pruef($gut, [], static fn (string $kid, string $kty): array => ['pem' => "-----BEGIN PUBLIC KEY-----\nkaputt\n-----END PUBLIC KEY-----\n"] + $jwkRsa),
    'signatur', 'kaputtes PEM → signatur');
// Ein EC-PEM unter kty RSA: openssl_verify gibt −1 (nicht 0) – auch das ist ungültig.
pruefe(openssl_verify($teile[0] . '.' . $teile[1], IdToken::b64($teile[2]), openssl_pkey_get_public($jwkEc['pem']), OPENSSL_ALGO_SHA256) === -1,
    'Prüfling: openssl_verify gibt hier −1');
gleich($pruef($gut, [], static fn (string $kid, string $kty): array => ['kty' => 'RSA', 'alg' => '', 'kid' => 'rsa-1', 'pem' => $jwkEc['pem']]),
    'signatur', 'openssl_verify = −1 → signatur (nur === 1 gilt)');
$claimsFremd = explode('.', $idp->idToken($claims(['sub' => 'jemand-anders'])))[1];
gleich($pruef($teile[0] . '.' . $claimsFremd . '.' . $teile[2]), 'signatur', 'Claims getauscht, Signatur alt → signatur');

// A.5 iss, aud, azp
gleich($pruef($idp->idToken($claims(['iss' => rtrim($iss, '/')]))), 'iss', 'iss ohne Schluss-/ → iss');
pruefe(str_contains($meldung(static fn () => IdToken::pruefen($idp->idToken($claims(['iss' => 'https://idp.example.test/'])), $soll(), $nachKid)), 'Proxy'),
    'Meldung zu iss nennt den Proxy');
gleich($pruef($idp->idToken($claims(['aud' => [$client['id']]]))), 'ok', 'aud als Liste [client] → gültig');
gleich($pruef($idp->idToken($claims(['aud' => [$client['id'], 'fremd']]))), 'aud', 'aud [client, fremd] → aud');
$ohneAud = $claims();
unset($ohneAud['aud']);
gleich($pruef($idp->idToken($ohneAud)), 'aud', 'ohne aud → aud');
gleich($pruef($idp->idToken($claims(['aud' => 'fremd']))), 'aud', 'aud fremd → aud');
gleich($pruef($idp->idToken($claims(['azp' => $client['id']]))), 'ok', 'azp = client → gültig');
gleich($pruef($idp->idToken($claims(['azp' => 'fremd']))), 'azp', 'azp fremd → azp');

// A.6 Zeiten
gleich($pruef($idp->idToken($claims(['exp' => $uhr - 61]))), 'exp', 'exp jetzt−61 → exp');
gleich($pruef($idp->idToken($claims(['exp' => $uhr - 59]))), 'ok', 'exp jetzt−59 → gültig (60 s Spiel)');
gleich($pruef($idp->idToken($claims(['exp' => $uhr + 300.5]))), 'ok', 'exp als Float → gültig');
gleich($pruef($idp->idToken($claims(['exp' => (string) ($uhr + 300)]))), 'exp', 'exp als String → exp');
gleich($pruef($idp->idToken($claims(['iat' => $uhr + 61]))), 'iat', 'iat jetzt+61 → iat');
gleich($pruef($idp->idToken($claims(['iat' => $uhr - 601]))), 'iat', 'iat älter als 10 min → iat');
gleich($pruef($idp->idToken($claims(['nbf' => $uhr + 61]))), 'nbf', 'nbf jetzt+61 → nbf');
gleich($pruef($idp->idToken($claims(['nbf' => $uhr + 30]))), 'ok', 'nbf jetzt+30 → gültig');
pruefe(str_contains($meldung(static fn () => IdToken::pruefen($idp->idToken($claims(['exp' => $uhr - 600])), $soll(), $nachKid)), 'Uhr'),
    'Meldung zu exp nennt die Uhr');

// A.7 nonce, sub
gleich($pruef($idp->idToken($claims(['nonce' => 'falsch']))), 'nonce', 'nonce falsch → nonce');
$ohneNonce = $claims();
unset($ohneNonce['nonce']);
gleich($pruef($idp->idToken($ohneNonce)), 'nonce', 'ohne nonce → nonce');
gleich($pruef($idp->idToken($claims(['sub' => 'mit leerzeichen']))), 'sub', 'sub mit Leerzeichen → sub');
gleich($pruef($idp->idToken($claims(['sub' => '']))), 'sub', 'sub leer → sub');
gleich($pruef($idp->idToken($claims(['sub' => str_repeat('s', 256)]))), 'sub', 'sub mit 256 Zeichen → sub');
gleich($pruef($idp->idToken($claims(['sub' => 42]))), 'sub', 'sub als Zahl → sub');

// A.8 auth_time (verbinden/bestätigen)
$ohneAuth = $claims();
unset($ohneAuth['auth_time']);
gleich($pruef($idp->idToken($ohneAuth), ['auth_nach' => $uhr - 60]), 'nicht_neu', 'auth_nach: ohne auth_time → nicht_neu');
gleich($pruef($idp->idToken($claims(['auth_time' => $uhr - 61])), ['auth_nach' => $uhr - 60]), 'nicht_neu', 'auth_nach: auth_time älter → nicht_neu');
gleich($pruef($idp->idToken($claims(['auth_time' => $uhr - 60])), ['auth_nach' => $uhr - 60]), 'ok', 'auth_nach: auth_time gleich → gültig');
gleich($pruef($idp->idToken($ohneAuth)), 'ok', 'ohne auth_nach ist auth_time egal');

// A.9 JWKs
$neu = static fn (array $param): array => openssl_pkey_get_details(openssl_pkey_new($param));
pruefe(Jwk::schluessel($idp->jwk('rsa-1024')) === null && str_contains((string) Jwk::grund($idp->jwk('rsa-1024')), '1024'), 'RSA 1024 → null (mit Grund)');
$p384 = $neu(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'])['ec'];
pruefe(Jwk::schluessel(['kty' => 'EC', 'crv' => 'P-384', 'kid' => 'p384', 'x' => OidcAttrappe::b64($p384['x']), 'y' => OidcAttrappe::b64($p384['y'])]) === null,
    'EC P-384 → null');
pruefe(Jwk::schluessel(['use' => 'enc'] + $idp->jwk('rsa')) === null && str_contains((string) Jwk::grund(['use' => 'enc'] + $idp->jwk('rsa')), 'enc'), 'use=enc → null');
$ec = $idp->jwk('ec');
pruefe(Jwk::schluessel(['x' => OidcAttrappe::b64(substr(IdToken::b64($ec['x']), 1))] + $ec) === null, 'EC mit x aus 31 Byte → null');
pruefe(Jwk::schluessel(['x' => OidcAttrappe::b64(random_bytes(32)), 'y' => OidcAttrappe::b64(random_bytes(32))] + $ec) === null, 'EC-Punkt nicht auf der Kurve → null');
$nurX5c = $idp->jwk('rsa');
unset($nurX5c['n'], $nurX5c['e']);
pruefe(Jwk::schluessel($nurX5c) === null, 'JWK nur mit x5c → null (x5c wird nie gelesen)');
pruefe(Jwk::schluessel(['alg' => 'ES256'] + $idp->jwk('rsa')) === null && Jwk::schluessel(['kty' => 'oct', 'k' => 'AAAA']) === null, 'RSA mit alg ES256, oct → null');
pruefe(Jwk::schluessel(['e' => OidcAttrappe::b64("\x01")] + $idp->jwk('rsa')) === null && Jwk::schluessel(['e' => OidcAttrappe::b64("\x01\x00")] + $idp->jwk('rsa')) === null,
    'RSA mit e = 1 bzw. geradem e → null');
pruefe(Jwk::schluessel(['n' => $idp->jwk('rsa')['n'] . '='] + $idp->jwk('rsa')) === null, 'n mit „=“ → null');
$ohneAlg = $idp->jwk('ec');
unset($ohneAlg['alg'], $ohneAlg['use']);
pruefe(is_array(Jwk::schluessel($ohneAlg)) && Jwk::schluessel($ohneAlg)['alg'] === '', 'EC ohne alg und use → brauchbar (alg leer)');
try {
    Jwk::es256Der(str_repeat("\x01", 65));
    pruefe(false, 'es256Der mit 65 Byte wirft');
} catch (SsoFehler $e) {
    gleich($e->pruefung, 'signatur', 'es256Der mit 65 Byte → signatur');
}

// ================================================================ B. Discovery, JWKS, Probe
$r = Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');
pruefe($r['ok'] && empty($r['warnung']), 'Verbindung mit Secret gespeichert: ' . $r['message']);
gleich(Einstellungen::secret(), $client['secret'], 'Secret (mit %+:/~) unverändert zurück');

// B.1
$d = Anbieter::discovery(true);
gleich($d['algs'], ['RS256'], 'Discovery: algs = [RS256]');
gleich($d['auth_methode'], 'basic', 'Discovery: client_secret_basic angeboten → basic');
pruefe($d['s256'] === true && $d['token_endpoint'] === 'https://idp.example.test/application/o/token/'
    && $d['jwks_uri'] === $iss . 'jwks/' && $d['end_session_endpoint'] === $iss . 'end-session/'
    && $d['userinfo_endpoint'] === 'https://idp.example.test/application/o/userinfo/', 'Discovery: Endpunkte und S256 übernommen');
$gespeichert = (string) Settings::get(Einstellungen::DISCOVERY);
pruefe($gespeichert !== '' && !str_contains($gespeichert, 'introspection'), 'Zwischenspeicher nur mit den benötigten Feldern');

// B.2 Ablehnungen – der Zwischenspeicher bleibt dabei unverändert
$faelle = [
    'issuer abweichend'               => [['discovery' => ['issuer' => $iss . 'anders/']], 'Proxy'],
    'Token-Endpunkt auf anderem Host' => [['discovery' => ['token_endpoint' => 'https://evil.example/application/o/token/']], 'token_endpoint'],
    'Token-Endpunkt auf anderem Port' => [['discovery' => ['token_endpoint' => 'https://idp.example.test:8443/application/o/token/']], 'token_endpoint'],
    'http-Endpunkt'                   => [['discovery' => ['jwks_uri' => 'http://idp.example.test/application/o/pm-test/jwks/']], 'jwks_uri'],
    'Userinfo auf anderem Host'       => [['discovery' => ['userinfo_endpoint' => 'https://evil.example/userinfo/']], 'userinfo_endpoint'],
    'ohne jwks_uri'                   => [['discovery' => ['jwks_uri' => null]], 'jwks_uri'],
    'ohne token_endpoint'             => [['discovery' => ['token_endpoint' => null]], 'token_endpoint'],
    'ohne authorization_endpoint'     => [['discovery' => ['authorization_endpoint' => null]], 'authorization_endpoint'],
    'algs = [HS256]'                  => [['discovery' => ['id_token_signing_alg_values_supported' => ['HS256']]], 'Signaturschlüssel'],
    'algs = [RS512]'                  => [['discovery' => ['id_token_signing_alg_values_supported' => ['RS512']]], 'RS512'],
    'PKCE ohne S256'                  => [['discovery' => ['code_challenge_methods_supported' => ['plain']]], 'S256'],
    'ohne Code-Flow'                  => [['discovery' => ['response_types_supported' => ['id_token']]], 'Code-Flow'],
    'ohne Grant authorization_code'   => [['discovery' => ['grant_types_supported' => ['implicit']]], 'Authorization Code'],
    'nur private_key_jwt'             => [['discovery' => ['token_endpoint_auth_methods_supported' => ['private_key_jwt']]], 'client_secret'],
    'Antwort über 64 KiB'             => [['gross' => 'discovery'], '64 KB'],
    '302'                             => [['umleiten' => 'discovery'], 'leitet um'],
    '404'                             => [['discovery_404' => true], 'Issuer-Modus'],
];
foreach ($faelle as $was => [$steuern, $text]) {
    $idp->zuruecksetzen();
    $idp->steuern($steuern);
    try {
        Anbieter::discovery(true);
        pruefe(false, 'Discovery abgelehnt: ' . $was);
    } catch (SsoFehler $e) {
        pruefe($e->pruefung === 'discovery' && str_contains($e->getMessage(), $text), 'Discovery abgelehnt: ' . $was . ' (' . $e->pruefung . ': ' . $e->getMessage() . ')');
    }
}
$idp->zuruecksetzen();
gleich((string) Settings::get(Einstellungen::DISCOVERY), $gespeichert, 'nach den Ablehnungen: Zwischenspeicher unverändert');
// nicht erreichbar (anderer Host): Meldung ohne Adresse mit Query
Http::$attrappe = static fn (string $m, string $u, array $o): array => ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'Could not resolve host', 'nummer' => 6];
pruefe(str_contains($meldung(static fn () => Anbieter::discovery(true)), 'nicht erreichbar'), 'Discovery nicht erreichbar → klare Meldung');
Http::$attrappe = $idp->http();

// B.3
$idp->steuern(['discovery' => ['id_token_signing_alg_values_supported' => ['ES256', 'RS256'], 'token_endpoint_auth_methods_supported' => ['client_secret_post'],
    'code_challenge_methods_supported' => null]]);
$d = Anbieter::discovery(true);
gleich($d['algs'], ['RS256', 'ES256'], '["ES256","RS256"] → beide Algorithmen');
gleich($d['auth_methode'], 'post', 'nur client_secret_post → post');
pruefe($d['s256'] === null, 'ohne code_challenge_methods_supported: kein Fehler (nur Befund)');
$idp->zuruecksetzen();

// B.4 Zwischenspeicher
Anbieter::discovery(true);
$n = $anfragen('discovery');
$uhr += 3600;
Anbieter::discovery();
gleich($anfragen('discovery'), $n, 'zweiter Abruf innerhalb 24 h ohne HTTP');
$uhr += Anbieter::CACHE - 3600 + 1;
Anbieter::discovery();
gleich($anfragen('discovery'), $n + 1, 'nach 24 h + 1 s neu geholt');
Settings::put(Einstellungen::DISCOVERY, '{kaputt');
Anbieter::discovery();
gleich($anfragen('discovery'), $n + 2, 'kaputter Zwischenspeicher → neu geholt');
pruefe(is_array(json_decode((string) Settings::get(Einstellungen::DISCOVERY), true)), 'und wieder gespeichert');

// B.6 Verbindung prüfen (vor B.5: legt den JWKS-Zwischenspeicher an)
$p = Anbieter::pruefen();
$texte = implode(' | ', array_column($p['befunde'], 'text'));
pruefe($p['ok'], 'pruefen(): alles gut – ' . $texte);
pruefe(str_contains($texte, $iss) && str_contains($texte, 'RS256') && str_contains($texte, 'rsa-1') && str_contains($texte, 'Client-Zugang angenommen'),
    'pruefen() nennt Issuer, RS256, rsa-1 und „Client-Zugang angenommen“');
pruefe(str_contains($texte, 'https://pm.example.test/login/sso/rueckruf'), 'pruefen() nennt die Rückruf-Adresse aus app.url');
$probe = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'token'));
$probe = end($probe);
pruefe(is_array($probe) && $probe['client_auth'] === 'basic' && $probe['client_ok'] === true && $probe['grant_type'] === 'authorization_code'
    && $probe['redirect_uri'] === 'https://pm.example.test/login/sso/rueckruf',
    'Probe mit Basic und dem Sonderzeichen-Secret kommt bei der Attrappe richtig an ' . json_encode($probe));
$kids = array_column(json_decode((string) Settings::get(Einstellungen::JWKS), true) ?: [], 'kid');
gleich($kids, ['rsa-1', 'ec-1'], 'JWKS-Zwischenspeicher nach der Prüfung');
pruefe((int) Settings::get(Einstellungen::GEPRUEFT_AM) === $uhr && str_contains((string) Settings::get(Einstellungen::BEFUND), 'Client-Zugang'), 'Befund gespeichert');

$r = Einstellungen::verbindungSpeichern($iss, $client['id'], 'falsch-' . str_repeat('x', 20), 'Authentik');
pruefe($r['ok'], 'falsches Secret eingetragen');
$jwksVorher = (string) Settings::get(Einstellungen::JWKS);
$p = Anbieter::pruefen();
$fehlschlag = array_values(array_filter($p['befunde'], static fn (array $b): bool => !$b['ok']));
pruefe(!$p['ok'] && count($fehlschlag) === 1 && str_contains($fehlschlag[0]['text'], 'Client-ID oder Secret'), 'pruefen() mit falschem Secret: ✗ „Client-ID oder Secret“');
gleich((string) Settings::get(Einstellungen::JWKS), $jwksVorher, 'fehlgeschlagene Prüfung ersetzt die Zwischenspeicher nicht');
Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');
$idp->steuern(['discovery' => ['token_endpoint_auth_methods_supported' => ['client_secret_post']]]);
$p = Anbieter::pruefen();
$probe = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'token'));
$probe = end($probe);
pruefe($p['ok'] && $probe['client_auth'] === 'post' && $probe['client_ok'] === true, 'nur client_secret_post: Probe mit Client-ID/Secret im Rumpf');
$idp->steuern(['discovery' => ['id_token_signing_alg_values_supported' => ['ES256']]]);
$p = Anbieter::pruefen();
$texte = implode(' | ', array_column($p['befunde'], 'text'));
pruefe($p['ok'] && str_contains($texte, '1 Signaturschlüssel: ec-1'), 'nur ES256: nur der EC-Schlüssel zählt – ' . $texte);
$idp->zuruecksetzen();
Http::$attrappe = static function (string $m, string $u, array $o) use ($idp): array {
    $a = ($idp->http())($m, $u, $o);
    if (str_ends_with($u, '/jwks/')) {
        $a['rumpf'] = json_encode(['keys' => [['use' => 'enc'] + $idp->jwk('rsa'), $idp->jwk('rsa-1024')]]);
    }
    return $a;
};
$p = Anbieter::pruefen();
$texte = implode(' | ', array_column($p['befunde'], 'text'));
pruefe(!$p['ok'] && str_contains($texte, 'use=enc') && str_contains($texte, '1024') && str_contains($texte, 'Kein brauchbarer Signaturschlüssel'),
    'pruefen() nennt übergangene Schlüssel mit Grund und fehlt einer: ✗ – ' . $texte);
Http::$attrappe = $idp->http();
pruefe(Anbieter::pruefen()['ok'], 'pruefen() wieder gut');

// B.5 Schlüsselwechsel
$soll5 = static fn (): array => ['aussteller' => $iss, 'client_id' => $client['id'], 'algs' => ['RS256', 'ES256'], 'nonce' => $nonce, 'jetzt' => $uhr, 'auth_nach' => null];
$claims5 = static fn (): array => ['iss' => $iss, 'sub' => $sub, 'aud' => $client['id'], 'exp' => $uhr + 300, 'iat' => $uhr, 'nonce' => $nonce];
$n = $anfragen('jwks');
gleich($code(static fn () => IdToken::pruefen($idp->idToken($claims5()), $soll5(), Anbieter::schluessel(...))), 'ok', 'bekannte kid aus dem Zwischenspeicher');
gleich($anfragen('jwks'), $n, 'ohne Abruf des JWKS');
$idp->steuern(['schluessel_wechseln' => true]);
gleich($code(static fn () => IdToken::pruefen($idp->idToken($claims5(), [], 'rsa-neu'), $soll5(), Anbieter::schluessel(...))), 'ok',
    'Schlüsselwechsel: Token mit neuer kid ist gültig');
gleich($anfragen('jwks'), $n + 1, 'dafür das JWKS genau einmal neu geladen');
gleich($code(static fn () => IdToken::pruefen($idp->idToken($claims5(), [], 'ec-neu'), $soll5(), Anbieter::schluessel(...))), 'ok',
    'der zweite neue Schlüssel steht schon im neuen Stand');
$uhr += 100;
gleich($code(static fn () => IdToken::pruefen($idp->idToken($claims5(), ['kid' => 'erfunden-1']), $soll5(), Anbieter::schluessel(...))), 'kid',
    'zweite unbekannte kid innerhalb 300 s → kid');
gleich($anfragen('jwks'), $n + 1, 'und kein weiterer Abruf');
$uhr += Anbieter::JWKS_NEU_FRUEHESTENS;
gleich($code(static fn () => IdToken::pruefen($idp->idToken($claims5(), ['kid' => 'erfunden-2']), $soll5(), Anbieter::schluessel(...))), 'kid',
    'unbekannte kid nach 300 s → kid');
gleich($anfragen('jwks'), $n + 2, 'dann wieder ein Abruf');
gleich($code(static fn () => IdToken::pruefen($idp->idToken($claims5(), ['kid' => null], 'rsa-neu'), $soll5(), Anbieter::schluessel(...))), 'ok',
    'ohne kid: der einzige RSA-Schlüssel');
$idp->zuruecksetzen();
$uhr += Anbieter::JWKS_NEU_FRUEHESTENS;
pruefe(Anbieter::pruefen()['ok'], 'nach dem Zurückwechseln wieder geprüft');

// Die Attrappe selbst: Code-Flow wie Authentik (Grundlage für den Rückruf)
$verifier = OidcAttrappe::b64(random_bytes(32));
$rueckruf = Einstellungen::rueckrufAdresse();
$idp->benutzer(['sub' => $sub, 'preferred_username' => 'erika', 'name' => 'Erika Beispiel', 'email' => 'erika@example.com',
    'email_verified' => false, 'groups' => ['pm-personal', '123'], 'amr' => ['pwd']]);
$authorize = static fn (array $extra = []): array => $idp->bearbeiten('GET', '/application/o/authorize/', $extra + ['response_type' => 'code',
    'client_id' => $client['id'], 'redirect_uri' => $rueckruf, 'scope' => 'openid profile email', 'state' => 'st-' . $kennung, 'nonce' => $nonce,
    'code_challenge' => OidcAttrappe::b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'response_mode' => 'query'], [], []);
$a = $authorize();
parse_str((string) parse_url($a['kopf']['Location'] ?? '', PHP_URL_QUERY), $zurueck);
pruefe($a['status'] === 302 && str_starts_with($a['kopf']['Location'], $rueckruf . '?') && ($zurueck['state'] ?? null) === 'st-' . $kennung
    && is_string($zurueck['code'] ?? null), 'Attrappe: authorize → 302 zur Rückruf-Adresse mit code und state');
$t = Anbieter::codeEinloesen($zurueck['code'], $verifier);
$c = IdToken::pruefen($t['id_token'], ['aussteller' => $iss, 'client_id' => $client['id'], 'algs' => Anbieter::discovery()['algs'], 'nonce' => $nonce,
    'jetzt' => Einstellungen::jetzt(), 'auth_nach' => null], Anbieter::schluessel(...));
pruefe($c['sub'] === $sub && $c['groups'] === ['pm-personal', '123'] && $c['amr'] === ['pwd'] && $c['email_verified'] === false,
    'Attrappe: Code eingelöst, ID-Token gültig, Claims wie Authentik');
$letzter = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'token'));
$letzter = end($letzter);
pruefe($letzter['pkce_ok'] === true && $letzter['client_auth'] === 'basic' && $letzter['client_ok'] === true, 'Attrappe meldet pkce_ok, basic, client_ok');
gleich($code(static fn () => Anbieter::codeEinloesen($zurueck['code'], $verifier)), 'token', 'Attrappe: derselbe Code ein zweites Mal → token');
gleich(Anbieter::userinfo($t['access_token'])['preferred_username'] ?? null, 'erika', 'Attrappe: Userinfo mit dem Access-Token');
$a = $authorize();
parse_str((string) parse_url($a['kopf']['Location'], PHP_URL_QUERY), $zurueck);
gleich($code(static fn () => Anbieter::codeEinloesen($zurueck['code'], $verifier . 'x')), 'token', 'Attrappe: falscher PKCE-Verifier → token');
$a = $authorize(['prompt' => 'login', 'max_age' => '0']);
$letzter = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'authorize'));
pruefe(end($letzter)['prompt'] === 'login' && end($letzter)['max_age'] === '0', 'Attrappe protokolliert prompt und max_age');
$a = $authorize(['redirect_uri' => 'https://evil.example/']);
pruefe($a['status'] === 400 && !isset($a['kopf']['Location']), 'Attrappe: eine andere als die eingetragene Rückruf-Adresse → 400 ohne Weiterleitung');
$e = $idp->bearbeiten('GET', '/application/o/' . OidcAttrappe::SLUG . '/end-session/', ['id_token_hint' => $t['id_token'], 'client_id' => $client['id']], [], []);
$letzter = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'end-session'));
pruefe($e['status'] === 200 && end($letzter)['hint_ok'] === true, 'Attrappe: end-session prüft id_token_hint');
$e = $idp->bearbeiten('GET', '/application/o/' . OidcAttrappe::SLUG . '/end-session/', ['id_token_hint' => $hmac], [], []);
$letzter = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'end-session'));
pruefe(end($letzter)['hint_ok'] === false, 'Attrappe: end-session mit fremdem Hinweis → hint_ok falsch');

// Code einlösen: keine Geheimnisse im Stack-Trace (#[\SensitiveParameter]), auch mit Argumenten in Traces
$ignorieren = ini_get('zend.exception_ignore_args');
ini_set('zend.exception_ignore_args', '0');
$geheimCode = 'code-' . $kennung . '-geheim';
$geheimVerifier = 'verifier-' . $kennung . '-' . str_repeat('v', 20);
// Die Attrappe selbst gehört nicht zur Anwendung – ihr Argument ist darum auch markiert.
Http::$attrappe = static function (string $m, string $u, #[\SensitiveParameter] array $o): array {
    throw new RuntimeException('Attrappe kaputt');
};
/** Steht eines der Geheimnisse in $wert (Strings, Listen, Schlüssel)? SensitiveParameterValue bleibt verdeckt. */
$enthaelt = static function (mixed $wert, array $geheim) use (&$enthaelt): bool {
    if (is_string($wert)) {
        foreach ($geheim as $g) {
            if (str_contains($wert, $g)) {
                return true;
            }
        }
        return false;
    }
    if (is_array($wert)) {
        foreach ($wert as $k => $v) {
            if ($enthaelt((string) $k, $geheim) || $enthaelt($v, $geheim)) {
                return true;
            }
        }
    }
    return false;
};
$basic = base64_encode(rawurlencode($client['id']) . ':' . rawurlencode($client['secret']));
try {
    Anbieter::codeEinloesen($geheimCode, $geheimVerifier);
    pruefe(false, 'codeEinloesen wirft, wenn die Attrappe wirft');
} catch (Throwable $e) {
    $spur = $e->getTrace();
    $einloesen = array_values(array_filter($spur, static fn (array $f): bool => ($f['function'] ?? '') === 'codeEinloesen'));
    pruefe($einloesen !== [] && ($einloesen[0]['args'][0] ?? null) instanceof SensitiveParameterValue
        && ($einloesen[0]['args'][1] ?? null) instanceof SensitiveParameterValue, 'Stack-Trace: Code und Verifier von codeEinloesen verdeckt');
    pruefe(array_filter($spur, static fn (array $f): bool => ($f['args'] ?? []) !== []) !== [], 'Stack-Trace mit Argumenten (zend.exception_ignore_args aus)');
    pruefe(!$enthaelt(array_column($spur, 'args'), [$geheimCode, $geheimVerifier, $client['secret'], rawurlencode($client['secret']), $basic]),
        'Stack-Trace aus codeEinloesen ohne Code, Verifier, Secret und Basic-Kopf (auch nicht in Http::anfrage)');
    pruefe(!str_contains((string) $e, $basic) && !str_contains((string) $e, substr($geheimCode, 0, 15)), '… auch nicht als Text');
}
ini_set('zend.exception_ignore_args', (string) $ignorieren);
Http::$attrappe = $idp->http();
$t = $code(static fn () => Anbieter::codeEinloesen($geheimCode, $geheimVerifier));
gleich($t, 'token', 'unbekannter Code → token');
pruefe(str_contains($meldung(static fn () => Anbieter::codeEinloesen($geheimCode, $geheimVerifier)), 'nicht angenommen'), 'invalid_grant → „nicht angenommen“');
$idp->steuern(['token_fehler' => 'access_denied']);
pruefe(str_contains($meldung(static fn () => Anbieter::codeEinloesen($geheimCode, $geheimVerifier)), 'lehnt ab (access_denied)'), 'anderer Fehler → „lehnt ab (…)“');
$idp->zuruecksetzen();
Http::$attrappe = static fn (string $m, string $u, array $o): array => str_ends_with($u, '/token/')
    ? ['ok' => true, 'status' => 400, 'rumpf' => json_encode(['error' => "<script>\nx"]), 'fehler' => '', 'nummer' => 0]
    : ($idp->http())($m, $u, $o);
pruefe(str_contains($meldung(static fn () => Anbieter::codeEinloesen($geheimCode, $geheimVerifier)), '(unbekannt)'), 'error mit fremden Zeichen → „unbekannt“');
Http::$attrappe = $idp->http();
pruefe(Anbieter::abmeldeAdresse('a.b.c') === $iss . 'end-session/?id_token_hint=a.b.c&client_id=' . rawurlencode($client['id']),
    'Abmelde-Adresse aus dem Zwischenspeicher, ohne Rücksprung');
Settings::put(Einstellungen::RUECKKEHR, '1');
pruefe(str_ends_with((string) Anbieter::abmeldeAdresse('a.b.c'), '&post_logout_redirect_uri=' . rawurlencode('https://pm.example.test/login')),
    'mit sso.abmelde_rueckkehr: post_logout_redirect_uri = app.url/login');
Settings::put(Einstellungen::RUECKKEHR, '0');
pruefe(Anbieter::abmeldeAdresse("a.b.c\r\nx") === null, 'Abmelde-Adresse nur mit einem Token-förmigen Hinweis');

// ================================================================ C. Einstellungen
// C.1 Secret
$abgelegt = (string) Settings::get(Settings::GEHEIM_SSO_SECRET);
pruefe($abgelegt !== '' && $abgelegt !== $client['secret'] && !str_contains($abgelegt, $client['secret']), 'Secret in settings nicht im Klartext');
gleich(Crypto::decrypt($abgelegt), $client['secret'], 'Crypto::decrypt liefert das Secret zurück');
$zustand = Einstellungen::zustand();
pruefe(!str_contains((string) json_encode($zustand), $client['secret']) && !str_contains((string) json_encode($zustand), $abgelegt)
    && $zustand['secret'] === 'hinterlegt', 'zustand() ohne Secret, „hinterlegt“');
pruefe($zustand['rueckruf'] === 'https://pm.example.test/login/sso/rueckruf' && $zustand['kids'] === ['rsa-1', 'ec-1'] && $zustand['algs'] === ['RS256'],
    'zustand(): Rückruf, kids, algs');
foreach (Database::select("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sso.%'") as $zeile) {
    pruefe(!str_contains((string) $zeile['setting_value'], $client['secret']), 'settings ' . $zeile['setting_key'] . ' ohne Klartext-Secret');
}
pruefe(!array_filter(array_keys(Settings::OVERRIDABLE), static fn (string $k): bool => str_starts_with($k, 'sso.') || $k === 'app.url'),
    'Settings::OVERRIDABLE ohne sso.* und app.url');

// C.2 Marke und Bindung an die Verbindung
$m0 = Einstellungen::marke();
pruefe(preg_match('/^[0-9a-f]{16}$/', $m0) === 1, 'Marke: 16 Hex');
$r = Einstellungen::verbindungSpeichern($iss, $client['id'], '', 'Authentik');
pruefe($r['ok'] && Einstellungen::marke() === $m0 && Einstellungen::secret() === $client['secret'], 'unverändert gespeichert: Marke und Secret bleiben');
$r = Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'] . 'X', 'Authentik');
$m1 = Einstellungen::marke();
pruefe($r['ok'] && $m1 !== $m0, 'nur das Secret neu → Marke neu');
$r = Einstellungen::verbindungSpeichern($iss, $client['id'] . '-zwei', '', 'Authentik');
pruefe($r['ok'] && !empty($r['warnung']) && str_contains($r['message'], 'Secret neu eingeben') && Einstellungen::marke() !== $m1
    && Einstellungen::secretZustand() === 'fehlt' && Settings::get(Einstellungen::DISCOVERY) === null && Settings::get(Einstellungen::BEFUND) === null,
    'neue Client-ID ohne Secret → Secret entfernt, Warnung, Marke neu, Zwischenspeicher und Befund weg');
Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');
$m2 = Einstellungen::marke();
$r = Einstellungen::verbindungSpeichern('https://idp.example.test/application/o/anders/', $client['id'], '', 'Authentik');
pruefe($r['ok'] && !empty($r['warnung']) && Einstellungen::secretZustand() === 'fehlt' && Einstellungen::marke() !== $m2, 'neuer Issuer ohne Secret → Secret entfernt + Warnung');
Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');
Settings::put(Einstellungen::SECRET_FUER, hash('sha256', 'https://anders.example/' . "\n" . $client['id']));
pruefe(Einstellungen::secret() === '' && Einstellungen::secretZustand() === 'andere_verbindung' && !Einstellungen::eingerichtet(),
    'Secret für eine andere Verbindung gilt nicht');
Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');
$r = Einstellungen::verbindungSpeichern($iss, $client['id'], '-', 'Authentik');
pruefe($r['ok'] && Einstellungen::secretZustand() === 'fehlt' && Settings::get(Einstellungen::SECRET_FUER) === null, '„-“ entfernt das Secret');
Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');

// C.3 Eingaben
$abgelehnt = [
    'Client-ID mit \r\n'           => [$iss, "pm\r\nX-Kopf: 1", ''],
    'Client-ID mit Leerzeichen'    => [$iss, 'pm test', ''],
    'http-Issuer ohne lokal_http'  => ['http://idp.example.test/application/o/pm-test/', $client['id'], ''],
    'http auf 127.0.0.1 ohne lokal_http' => ['http://127.0.0.1:9/application/o/pm-test/', $client['id'], ''],
    'Issuer mit Query'             => [$iss . '?a=1', $client['id'], ''],
    'Issuer mit Benutzer'          => ['https://u:p@idp.example.test/application/o/pm-test/', $client['id'], ''],
    'Issuer mit Fragment'          => [$iss . '#x', $client['id'], ''],
    'Issuer HTTPS groß'            => ['HTTPS://idp.example.test/application/o/pm-test/', $client['id'], ''],
    'Issuer mit Leerzeichen'       => ['https://idp.example.test/application/o/pm test/', $client['id'], ''],
    'Issuer über 255 Zeichen'      => ['https://idp.example.test/' . str_repeat('a', 240) . '/', $client['id'], ''],
    'Secret zu kurz'               => [$iss, $client['id'], 'kurz-15-zeichen'],
    'Secret mit Leerzeichen'       => [$iss, $client['id'], 'mit leerzeichen 1234567890'],
];
foreach ($abgelehnt as $was => [$a, $c, $s]) {
    $r = Einstellungen::verbindungSpeichern($a, $c, $s, 'Authentik');
    pruefe(!$r['ok'], 'abgelehnt: ' . $was . ' (' . $r['message'] . ')');
}
pruefe(!Einstellungen::verbindungSpeichern($iss, $client['id'], '', str_repeat('n', 41))['ok']
    && !Einstellungen::verbindungSpeichern($iss, $client['id'], '', "Auth\nentik")['ok'], 'Beschriftung: höchstens 40 Zeichen, ohne Steuerzeichen');
gleich(Einstellungen::aussteller(), $iss, 'nach den Ablehnungen: Aussteller unverändert');
gleich(Einstellungen::secret(), $client['secret'], 'nach den Ablehnungen: Secret unverändert');
$r = Einstellungen::verbindungSpeichern('https://idp.example.test/application/o/pm-test', $client['id'], '', 'Authentik');
pruefe($r['ok'] && !empty($r['warnung']) && str_contains($r['message'], '„/“'), 'Warnung bei /application/o/… ohne „/“ am Ende');
Einstellungen::verbindungSpeichern($iss, $client['id'], $client['secret'], 'Authentik');
Config::set('sso.lokal_http', true);
pruefe(Einstellungen::ausstellerFehler('http://127.0.0.1:9/application/o/pm-test/') === null
    && Einstellungen::ausstellerFehler('http://localhost:9/x/') === null
    && Einstellungen::ausstellerFehler('http://idp.example.test/x/') !== null, 'lokal_http: http nur auf Loopback');
Config::set('sso.lokal_http', false);
gleich(Einstellungen::name(), 'Authentik', 'Beschriftung');
pruefe(Einstellungen::verbindungSpeichern($iss, $client['id'], '', '')['ok'] && Einstellungen::name() === 'Authentik', 'leere Beschriftung → Authentik');
$r = Einstellungen::optionenSpeichern(['scopes' => 'openid profile email entitlements', 'gruppen_claim' => 'entitlements',
    'pflichtgruppen' => "pm-personal\r\n\r\n 123 \npm-personal", 'anlegen' => true, 'abmelde_rueckkehr' => '1', 'inaktiv_tage' => '90']);
pruefe($r['ok'] && Einstellungen::scopes() === 'openid profile email entitlements' && Einstellungen::gruppenClaim() === 'entitlements'
    && Einstellungen::pflichtgruppen() === ['pm-personal', '123'] && Einstellungen::schalter(Einstellungen::ANLEGEN)
    && Einstellungen::schalter(Einstellungen::RUECKKEHR) && Einstellungen::inaktivTage() === 90, 'Optionen gespeichert (Gruppe „123“ bleibt ein String)');
foreach (['scopes ohne openid' => ['scopes' => 'profile email'], 'offline_access' => ['scopes' => 'openid offline_access'],
    'neun Scopes' => ['scopes' => 'openid a b c d e f g h'], 'Scope mit Sonderzeichen' => ['scopes' => 'openid pro"file'],
    'unbekannter Gruppen-Claim' => ['gruppen_claim' => 'memberOf'], '21 Pflichtgruppen' => ['pflichtgruppen' => implode("\n", range(1, 21))],
    'Pflichtgruppe mit 151 Zeichen' => ['pflichtgruppen' => str_repeat('g', 151)], 'Tage negativ' => ['inaktiv_tage' => '-1'],
    'Tage zu viele' => ['inaktiv_tage' => '9999']] as $was => $o) {
    pruefe(!Einstellungen::optionenSpeichern($o)['ok'], 'Option abgelehnt: ' . $was);
}
gleich(Einstellungen::scopes(), 'openid profile email entitlements', 'nach den Ablehnungen: Optionen unverändert');
Einstellungen::optionenSpeichern(['scopes' => Einstellungen::VORGABE_SCOPES, 'gruppen_claim' => 'groups', 'pflichtgruppen' => '', 'anlegen' => false,
    'abmelde_rueckkehr' => false, 'inaktiv_tage' => '0']);

// C.4 Einschalten
pruefe(!Einstellungen::aktiv() && Einstellungen::knopf() === null, 'vor dem Einschalten: aus, kein Knopf');
Config::set('app.url', '');
$r = Einstellungen::einschalten();
pruefe(!$r['ok'] && str_contains($r['message'], 'app.url'), 'einschalten() ohne app.url → abgelehnt');
pruefe(Einstellungen::rueckrufAdresse() === '' && !Einstellungen::eingerichtet(), 'ohne app.url keine Rückruf-Adresse');
Config::set('app.url', 'https://pm.example.test/unterordner');
pruefe(!Einstellungen::einschalten()['ok'], 'einschalten() mit app.url mit Pfad → abgelehnt');
Config::set('app.url', 'https://pm.example.test?x=1');
pruefe(Einstellungen::appUrlFehler() !== null, 'app.url mit Query taugt nicht');
Config::set('app.url', 'http://pm.example.test');
pruefe(Einstellungen::appUrlFehler() !== null, 'app.url mit http taugt nicht');
Config::set('app.url', 'https://pm.example.test/');
pruefe(Einstellungen::appUrlFehler() === null && Einstellungen::rueckrufAdresse() === 'https://pm.example.test/login/sso/rueckruf', 'app.url mit „/“ am Ende geht');
Config::set('app.url', 'https://pm.example.test');
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
gleich(Einstellungen::rueckrufAdresse(), 'https://pm.example.test/login/sso/rueckruf', 'Rückruf-Adresse nie aus Host/X-Forwarded-Host');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST']);
$idp->steuern(['discovery_404' => true]);
$r = Einstellungen::einschalten();
pruefe(!$r['ok'] && str_contains($r['message'], 'Issuer-Modus') && Settings::get(Einstellungen::AKTIV) !== '1', 'einschalten() bei fehlschlagender Prüfung → abgelehnt, bleibt aus');
$idp->zuruecksetzen();
$r = Einstellungen::einschalten();
pruefe($r['ok'] && Einstellungen::aktiv() && Einstellungen::knopf() === 'Authentik', 'einschalten() nach erfolgreicher Prüfung → an, Knopf „Authentik“');

// C.5 Ausschalten
$rolle = static fn (string $schluessel): int => (int) Database::scalar('SELECT id FROM roles WHERE role_key = ?', [$schluessel]);
$konto = BenutzerService::anlegen($kennung . '-konto', 'Pruefung-' . bin2hex(random_bytes(4)) . '!A1', 'kunde', false);
Database::insert('user_roles', ['user_id' => $konto, 'role_id' => $rolle('planung'), 'gueltig_bis' => gmdate('c', $uhr + 3600), 'quelle' => 'sso']);
gleich((int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$konto]), 2, 'Konto mit Hand- und SSO-Rolle');
gleich((string) Database::scalar('SELECT quelle FROM user_roles WHERE user_id = ? AND role_id = ?', [$konto, $rolle('kunde')]), 'hand', 'Vorgabe der Spalte quelle: hand');
gleich((int) Database::scalar('SELECT nur_sso FROM users WHERE id = ?', [$konto]), 0, 'Vorgabe der Spalte nur_sso: 0');
Settings::put(Einstellungen::NUR_SSO, '1');
$r = Einstellungen::ausschalten(true);
pruefe($r['ok'] && !Einstellungen::schalter(Einstellungen::NUR_SSO) && Einstellungen::aktiv()
    && (int) Database::scalar("SELECT COUNT(*) FROM user_roles WHERE quelle = 'sso'") === 1, 'ausschalten(nur „nur SSO“): SSO bleibt an, Rollen bleiben');
Settings::put(Einstellungen::NUR_SSO, '1');
$m = Einstellungen::marke();
$r = Einstellungen::ausschalten();
pruefe($r['ok'] && !Einstellungen::aktiv() && Einstellungen::marke() !== $m && !Einstellungen::schalter(Einstellungen::NUR_SSO),
    'ausschalten(): aus, Marke neu, nur_sso 0');
gleich((int) Database::scalar("SELECT COUNT(*) FROM user_roles WHERE quelle = 'sso'"), 0, 'ausschalten(): SSO-Rollenzeilen weg');
gleich((int) Database::scalar("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND quelle = 'hand'", [$konto]), 1, 'die Hand-Rolle bleibt');

// C.6 Der Knopf wirft nie
Einstellungen::einschalten();
Settings::put(Einstellungen::DISCOVERY, '{kaputt');
Settings::put(Einstellungen::JWKS, '[{"kid":');
Settings::put(Einstellungen::BEFUND, 'kein json');
try {
    pruefe(Einstellungen::knopf() === 'Authentik', 'knopf() mit kaputtem Discovery-Zwischenspeicher');
    $z = Einstellungen::zustand();
    pruefe($z['algs'] === [] && $z['kids'] === [] && $z['befund'] === [], 'zustand() mit kaputten Zwischenspeichern: leer');
    pruefe(Anbieter::abmeldeAdresse('a.b.c') === null, 'abmeldeAdresse() mit kaputtem Zwischenspeicher: null');
    $schluessel = Config::get('app.key');
    Config::set('app.key', 'base64:CHANGEME000000000000000000000000000000000000');
    pruefe(Einstellungen::knopf() === null && !Einstellungen::aktiv() && Einstellungen::secretZustand() === 'unlesbar', 'ohne gültigen app.key: kein Knopf, aus, Secret „unlesbar“');
    Config::set('app.key', $schluessel);
} catch (Throwable $e) {
    pruefe(false, 'knopf()/zustand() werfen nie: ' . $e->getMessage());
}
Einstellungen::ausschalten();

// Database::transaktion: alles oder nichts, verschachtelt
try {
    Database::transaktion(static function () use ($kennung): void {
        Database::insert('settings', ['setting_key' => $kennung . '.a', 'setting_value' => '1', 'updated_at' => gmdate('c')]);
        Database::transaktion(static function () use ($kennung): void {
            Database::insert('settings', ['setting_key' => $kennung . '.b', 'setting_value' => '1', 'updated_at' => gmdate('c')]);
        });
        throw new RuntimeException('zurück');
    });
} catch (RuntimeException) {
}
gleich((int) Database::scalar('SELECT COUNT(*) FROM settings WHERE setting_key LIKE ?', [$kennung . '.%']), 0, 'Database::transaktion rollt bei einer Ausnahme alles zurück');
gleich(Database::transaktion(static fn (): int => 7), 7, 'Database::transaktion gibt das Ergebnis zurück');
pruefe(!Database::pdo()->inTransaction(), 'danach keine offene Transaktion');

// ================================================================ D. Start
// Ab hier geht die Uhr der Prüfung wieder mit der echten: Sitzungen merken
// sich ihren Beginn mit time() (B hat die Uhr um mehr als einen Tag vorgestellt).
$uhr = time();
Http::$attrappe = $idp->http();
$idp->zuruecksetzen();
$ip = '198.51.100.7';
// Viele absichtlich gescheiterte Vorgänge von dieser Adresse – die Bremse prüft E.6 eigens.
Config::set('security.ip_attempts', 100000);
$pw = 'Pruefung-' . bin2hex(random_bytes(4)) . '!A1';
/** Die Query einer Adresse als Liste. */
$abfrage = static function (string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    return $q;
};
/** Abgemeldet, leere Sitzung (im Prozess ist $_SESSION die Sitzung). */
$abmelden = static function (): void {
    $_SESSION = [];
    Auth::vergessen();
    Zugriff::vergessen();
};
/** Wer bei der Attrappe angemeldet ist – Vorgaben wie Authentik; null in $extra entfernt eine Angabe. */
$person = static fn (string $subjekt, string $name, array $gruppen = [], array $extra = []): array => array_filter($extra + ['sub' => $subjekt,
    'preferred_username' => $name, 'name' => 'Person ' . $name, 'email' => $name . '@example.com', 'email_verified' => true,
    'groups' => $gruppen, 'amr' => ['pwd']], static fn (mixed $v): bool => $v !== null);
/**
 * Ein ganzer Ablauf im Prozess: starten → Authorize der Attrappe → Rückruf.
 * $benutzer null: wer zuletzt bei der Attrappe angemeldet war. $rueckruf
 * ändert die Query des Rückrufs vor dem Aufruf.
 */
$ablauf = static function (?array $benutzer = null, array $steuern = [], string $zweck = 'anmelden', ?int $userId = null,
    ?Closure $rueckruf = null, ?string $ziel = null, ?string $vonIp = null) use ($idp, $abfrage, $ip): array {
    if ($benutzer !== null) {
        $idp->benutzer($benutzer);
    }
    $idp->zuruecksetzen();
    if ($steuern !== []) {
        $idp->steuern($steuern);
    }
    $url = Anmeldung::starten($zweck, $ziel, false, $userId);
    $a = $idp->bearbeiten('GET', (string) parse_url($url, PHP_URL_PATH), $abfrage($url), [], []);
    $zurueck = $abfrage((string) ($a['kopf']['Location'] ?? ''));
    if ($rueckruf !== null) {
        $zurueck = $rueckruf($zurueck);
    }
    $r = Anmeldung::rueckruf($zurueck, $vonIp ?? $ip);
    $idp->zuruecksetzen();
    return $r + ['_zurueck' => $zurueck];
};
$fehlschlaege = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.sso_fehlgeschlagen'");
$letzterFehlschlag = static fn (): array => (array) json_decode((string) Database::scalar(
    "SELECT details FROM audit_log WHERE action = 'auth.sso_fehlgeschlagen' ORDER BY id DESC LIMIT 1"), true);

$abmelden();
$r = $code(static fn () => Anmeldung::starten('anmelden', null, false));
gleich($r, 'einrichtung', 'Start bei SSO aus → einrichtung');
pruefe(Einstellungen::einschalten()['ok'], 'für D–M: SSO eingeschaltet');

// D.1 Die Adresse zum Anmeldedienst
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
$url = Anmeldung::starten('anmelden', '/karte', false);
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST']);
$q = $abfrage($url);
$v = $_SESSION[Anmeldung::SITZUNG][$q['state'] ?? ''] ?? [];
pruefe(str_starts_with($url, 'https://idp.example.test/application/o/authorize/?'), 'Start: der Authorize-Endpunkt aus der Discovery');
$schluesselListe = array_keys($q);
sort($schluesselListe);
gleich($schluesselListe, ['client_id', 'code_challenge', 'code_challenge_method', 'nonce', 'redirect_uri', 'response_mode', 'response_type', 'scope', 'state'],
    'Start: genau diese Parameter');
pruefe($q['response_type'] === 'code' && $q['client_id'] === $client['id'] && $q['scope'] === 'openid profile email'
    && $q['code_challenge_method'] === 'S256' && $q['response_mode'] === 'query', 'Start: code, Client-ID, Scopes, S256, query');
gleich($q['redirect_uri'], 'https://pm.example.test/login/sso/rueckruf', 'Start: redirect_uri = app.url + Pfad, auch bei Host evil.example');
pruefe(preg_match('/^[A-Za-z0-9_-]{43}$/', $q['state']) === 1 && preg_match('/^[A-Za-z0-9_-]{43}$/', $q['nonce']) === 1, 'Start: state und nonce je 43 Zeichen');
pruefe(($v['nonce'] ?? null) === $q['nonce'] && ($v['ziel'] ?? null) === '/karte' && ($v['zweck'] ?? null) === 'anmelden'
    && ($v['marke'] ?? null) === Einstellungen::marke(), 'Start: Vorgang in der Sitzung (nonce, Ziel, Zweck, Marke)');
gleich($q['code_challenge'], OidcAttrappe::b64(hash('sha256', (string) ($v['verifier'] ?? ''), true)), 'Start: code_challenge = b64url(sha256(verifier))');
pruefe(!str_contains($url, $client['secret']) && !str_contains($url, rawurlencode($client['secret'])) && !str_contains($url, (string) ($v['verifier'] ?? '-')),
    'Start: kein Secret und kein Verifier in der Adresse');
pruefe(!isset($q['prompt']) && !isset($q['max_age']), 'Start: anmelden ohne prompt und max_age');

// D.2 Höchstens fünf Vorgänge – der 6. verdrängt den ältesten
$abmelden();
$states = [];
for ($i = 0; $i < 6; $i++) {
    $states[] = $abfrage(Anmeldung::starten('anmelden', null, false))['state'];
}
$offen = $_SESSION[Anmeldung::SITZUNG];
pruefe(count($offen) === 5 && !isset($offen[$states[0]]) && isset($offen[$states[1]], $offen[$states[5]]), 'der 6. Vorgang verdrängt den ältesten');

// D.3 prompt und max_age
$q = $abfrage(Anmeldung::starten('anmelden', null, true));
pruefe(($q['prompt'] ?? null) === 'login' && !isset($q['max_age']), 'nach dem Abmelden in diesem Browser: prompt=login');
foreach (['verbinden', 'token:5', 'kundenmodus:7'] as $zweck) {
    $q = $abfrage(Anmeldung::starten($zweck, '/profil', false, 1));
    pruefe(($q['prompt'] ?? null) === 'login' && ($q['max_age'] ?? null) === '0', $zweck . ': prompt=login und max_age=0');
}
try {
    Anmeldung::starten('alles', null, false);
    pruefe(false, 'unbekannter Zweck → LogicException');
} catch (LogicException) {
    pruefe(true, 'unbekannter Zweck → LogicException');
}
$n = $anfragen('discovery');
Settings::put(Einstellungen::DISCOVERY_AM, (string) ($uhr - Anbieter::CACHE - 1));
Anmeldung::starten('anmelden', null, false);
gleich($anfragen('discovery'), $n + 1, 'Start mit veraltetem Discovery-Zwischenspeicher holt sie neu');
Anmeldung::starten('anmelden', null, false);
gleich($anfragen('discovery'), $n + 1, '… danach aus dem Zwischenspeicher');

// D.4 Ziel nach der Anmeldung
foreach (['//evil.example', '/\\evil.example', 'https://evil.example', '/login/sso', '/login', '/login?next=/karte', '/logout', '/abgemeldet',
    "/karte\n", ' /karte', ''] as $ziel) {
    pruefe(Anmeldung::zielNach($ziel) === null, 'zielNach(' . json_encode($ziel) . ') → null');
}
pruefe(Anmeldung::zielNach(['/karte']) === null && Anmeldung::zielNach(null) === null, 'zielNach: Array/null → null');
gleich(Anmeldung::zielNach('/karte?x=1'), '/karte?x=1', 'zielNach: /karte?x=1 bleibt');

// Konten für E–M
alsBenutzer(null);
$admin = BenutzerService::anlegen($kennung . '-admin', $pw, 'admin', false);
$planer = BenutzerService::anlegen($kennung . '-planer', $pw, 'planung', false);
$subPlaner = 'sub-planer-' . $kennung;
Konten::verknuepfen($planer, $iss, $subPlaner, 'cli', 'planer-dort', []);
alsBenutzer($admin);
$rolleA = (int) BenutzerService::rolleSpeichern(null, $kennung . '-protokoll', 'Prüfung', ['steuern.protokoll'])['id'];
$rolleVerwalter = (int) BenutzerService::rolleSpeichern(null, $kennung . '-verwalter', 'Prüfung', ['system.users.manage'])['id'];
$verwalter = BenutzerService::anlegen($kennung . '-verwalter', $pw, 'kunde', false);
Database::insert('user_roles', ['user_id' => $verwalter, 'role_id' => $rolleVerwalter, 'gueltig_ab' => null, 'gueltig_bis' => null]);
$gruppeA = 'pm-protokoll-' . $kennung;
pruefe(Konten::zuordnungSpeichern($gruppeA, 'rolle', $rolleA)['ok'], 'Zuordnung Gruppe → Rolle (als Administrator)');
$zuordnungA = (int) Database::scalar('SELECT id FROM sso_zuordnungen WHERE gruppe = ?', [$gruppeA]);
$abmelden();

// ================================================================ E. Rückruf
// E.1 state
$vorher = $fehlschlaege();
$n = $anfragen('token');
$r = Anmeldung::rueckruf(['state' => str_repeat('a', 43), 'code' => 'x'], $ip);
pruefe(!$r['ok'] && $r['code'] === 'state' && $r['ziel'] === '/login' && $r['meldung'] === Anmeldung::T_STATE, 'unbekannter state → state, zur Anmeldung');
gleich($fehlschlaege(), $vorher, 'unbekannter state: kein Audit-Eintrag');
gleich($anfragen('token'), $n, 'unbekannter state: keine Token-Anfrage');
gleich(Anmeldung::rueckruf(['state' => [$states[5]], 'code' => 'x'], $ip)['code'], 'state', 'state als Array zählt als fehlend');
$r = $ablauf($person($subPlaner, 'planer-dort'));
pruefe($r['ok'] && Auth::id() === $planer && Auth::anmeldeweg() === 'sso', 'Rückruf: verknüpftes Konto angemeldet, Weg sso');
$abmelden();
gleich(Anmeldung::rueckruf($r['_zurueck'], $ip)['code'], 'state', 'derselbe Rückruf ein zweites Mal → state');
pruefe(Auth::id() === null, '… und niemand angemeldet');
$url = Anmeldung::starten('anmelden', null, false);
$a = $idp->bearbeiten('GET', '/application/o/authorize/', $abfrage($url), [], []);
$uhr += Anmeldung::VORGANG_SEKUNDEN + 1;
gleich(Anmeldung::rueckruf($abfrage($a['kopf']['Location']), $ip)['code'], 'state', 'Vorgang 601 s alt → state');
gleich($fehlschlaege(), $vorher, 'state-Fehler zählen nie (Audit leer)');

// E.2 Einrichtung und Sitzung
$url = Anmeldung::starten('anmelden', null, false);
$a = $idp->bearbeiten('GET', '/application/o/authorize/', $abfrage($url), [], []);
Einstellungen::markeErneuern();
$r = Anmeldung::rueckruf($abfrage($a['kopf']['Location']), $ip);
pruefe($r['code'] === 'einrichtung' && $letzterFehlschlag()['grund'] === 'einrichtung' && $fehlschlaege() === $vorher + 1,
    'Marke zwischendurch neu → einrichtung (ab hier mit Audit)');
gleich($ablauf($person($subPlaner, 'planer-dort'), ['iss_param' => 'https://evil.example/'])['code'], 'iss', 'iss-Parameter fremd → iss');
$abmelden();
pruefe($ablauf(null, ['iss_param' => true])['ok'], 'iss-Parameter gleich dem Aussteller → angemeldet');
$abmelden();
$url = Anmeldung::starten('anmelden', null, false);
$a = $idp->bearbeiten('GET', '/application/o/authorize/', $abfrage($url), [], []);
$offenerRueckruf = $abfrage($a['kopf']['Location']);
pruefe($ablauf()['ok'] && Auth::id() === $planer, 'in derselben Sitzung angemeldet');
$identitaeten = Database::select('SELECT * FROM sso_identitaeten ORDER BY id');
$r = Anmeldung::rueckruf($offenerRueckruf, $ip);
pruefe($r['code'] === 'angemeldet' && Auth::id() === $planer && Database::select('SELECT * FROM sso_identitaeten ORDER BY id') == $identitaeten,
    'angemeldete Sitzung beim Zweck anmelden → angemeldet, kein Konto verändert');
$abmelden();

// E.2b Geändert, während der Rückruf auf den Anmeldedienst wartet – von einem
// anderen Prozess, also am Zwischenspeicher der Einstellungen dieser Anfrage
// vorbei (direkt in der Datenbank).
$httpIdp = $idp->http();
$waehrend = null;
Http::$attrappe = static function (string $m, string $url, array $o = []) use ($httpIdp, &$waehrend): array {
    if ($waehrend !== null && str_contains($url, '/token/')) {
        $w = $waehrend;
        $waehrend = null;
        $w();
    }
    return $httpIdp($m, $url, $o);
};
$ssoZeilen = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM user_roles WHERE quelle = 'sso'");
$direkt = static fn (string $schluessel, string $wert) => Database::execute('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$wert, $schluessel]);
Database::execute("DELETE FROM user_roles WHERE quelle = 'sso'");
$waehrend = static function () use ($direkt): void {
    // wie Einstellungen::ausschalten() in einer anderen Anfrage
    $direkt(Einstellungen::AKTIV, '0');
    Database::execute("DELETE FROM user_roles WHERE quelle = 'sso'");
};
$r = $ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
pruefe($r['code'] === 'einrichtung' && Auth::id() === null && $ssoZeilen() === 0,
    'E.2: SSO aus, während der Rückruf auf den Token-Endpunkt wartet → einrichtung, keine Rolle aus Gruppen geschrieben');
Settings::put(Einstellungen::AKTIV, '1');
$marke = Einstellungen::marke();
$waehrend = static fn () => $direkt(Einstellungen::MARKE, bin2hex(random_bytes(8)));
$r = $ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
pruefe($r['code'] === 'einrichtung' && Auth::id() === null && $ssoZeilen() === 0, 'E.2: neue Verbindung (Marke) während des Wartens → einrichtung');
Settings::put(Einstellungen::MARKE, $marke);
// „Verwaltung per SSO“ aus, während gewartet wird: die verwaltende Rolle aus der Gruppe kommt nicht (wieder).
Settings::put(Einstellungen::ADMINS, '1');
alsBenutzer($admin);
$gruppeV = 'pm-verwaltung-' . $kennung;
pruefe(Konten::zuordnungSpeichern($gruppeV, 'rolle', $rolleVerwalter)['ok'], 'E.2: Gruppe → verwaltende Rolle (mit „Verwaltung per SSO“)');
alsBenutzer(null);
$abmelden();
$waehrend = static fn () => $direkt(Einstellungen::ADMINS, '0');
$r = $ablauf($person($subPlaner, 'planer-dort', [$gruppeV]));
pruefe(Database::scalar("SELECT id FROM user_roles WHERE user_id = ? AND role_id = ? AND quelle = 'sso'", [$planer, $rolleVerwalter]) === false
    && !Auth::can('system.users.manage'), 'E.2: „Verwaltung per SSO“ aus während des Wartens → die verwaltende Rolle aus der Gruppe kommt nicht');
$waehrend = null;
$abmelden();
Database::execute('DELETE FROM sso_zuordnungen WHERE gruppe = ?', [$gruppeV]);
Database::execute("DELETE FROM user_roles WHERE quelle = 'sso'");
Http::$attrappe = $httpIdp;
Settings::vergessen();

// E.3 Fehler des Anbieters
$r = $ablauf(null, ['authorize_fehler' => 'access_denied', 'authorize_beschreibung' => '<script>alert(1)</script>' . str_repeat('x', 300)]);
pruefe($r['code'] === 'abgebrochen' && mb_strlen($r['meldung']) <= 200 && str_starts_with($r['meldung'], 'Authentik meldet: <script>'),
    'error=access_denied → abgebrochen, Meldung ≤ 200 Zeichen (escaped wird beim Anzeigen)');
pruefe($letzterFehlschlag() == ['grund' => 'abgebrochen', 'zweck' => 'anmelden', 'fehler' => 'access_denied'], 'Audit: abgebrochen mit dem Fehlercode');
gleich($ablauf(null, ['authorize_fehler' => 'Böse<>'])['code'], 'abgebrochen', 'error mit fremden Zeichen → abgebrochen');
gleich($letzterFehlschlag()['fehler'] ?? null, 'unbekannt', '… im Audit als „unbekannt“');
gleich($ablauf(null, [], 'anmelden', null, static fn (array $z): array => ['code' => 'mit leerzeichen'] + $z)['code'], 'code', 'Code mit Leerzeichen → code');
gleich($ablauf(null, [], 'anmelden', null, static fn (array $z): array => ['code' => ['x']] + $z)['code'], 'code', 'Code als Array → code');

// E.4 Token-Endpunkt
$r = $ablauf(null, ['token_fehler' => 'invalid_grant']);
pruefe($r['code'] === 'token' && str_contains($r['meldung'], 'nicht angenommen'), 'invalid_grant → token, „nicht angenommen“');
$r = $ablauf(null, ['token_fehler' => 'invalid_client']);
pruefe($r['code'] === 'token' && str_contains($r['meldung'], 'Client-ID oder Secret'), 'invalid_client → token, „Client-ID oder Secret“');
$r = $ablauf();
$letzter = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'token'));
$letzter = end($letzter);
pruefe($r['ok'] && $letzter['pkce_ok'] === true && $letzter['client_auth'] === 'basic' && $letzter['client_ok'] === true,
    'Rückruf: die Attrappe meldet pkce_ok, basic, client_ok');
$abmelden();
$idp->steuern(['discovery' => ['token_endpoint_auth_methods_supported' => ['client_secret_post']]]);
gleich(Anbieter::discovery(true)['auth_methode'], 'post', 'Discovery nur mit client_secret_post');
$r = $ablauf();
$letzter = array_values(array_filter($idp->protokoll(), static fn (array $e): bool => $e['pfad'] === 'token'));
pruefe($r['ok'] && end($letzter)['client_auth'] === 'post' && end($letzter)['client_ok'] === true, 'nur client_secret_post angeboten → client_auth post');
$abmelden();
Anbieter::discovery(true);
foreach (['signatur_kaputt' => true, 'nonce' => 'falsch', 'aud' => 'fremd', 'exp_delta' => -61, 'alg' => 'HS256'] as $was => $wert) {
    $r = $ablauf(null, [$was => $wert]);
    pruefe(!$r['ok'] && Auth::id() === null, 'ID-Token mit ' . $was . ' → abgelehnt (' . $r['code'] . ')');
}

// E.4b Signing Key in Authentik auf eine andere Art gewechselt (RSA → EC): Die
// gespeicherte Discovery (bis 24 h) nennt nur RS256 – sie wird dann einmal neu
// geholt, gedrosselt wie das JWKS; ein alg, das wir nie annehmen, nie.
Anbieter::discovery(true);
gleich(Anbieter::discovery()['algs'], ['RS256'], 'E.4: Discovery im Zwischenspeicher nur mit RS256');
$ec = ['discovery' => ['id_token_signing_alg_values_supported' => ['ES256']], 'alg' => 'ES256'];
$n = $anfragen('discovery');
$r = $ablauf($person($subPlaner, 'planer-dort'), $ec);
pruefe($r['ok'] && Auth::id() === $planer && $anfragen('discovery') === $n + 1 && Anbieter::discovery()['algs'] === ['ES256'],
    'E.4: ID-Token mit ES256, gespeichert nur RS256 → Discovery einmal neu, angemeldet');
$abmelden();
Anbieter::discovery(true);
$n = $anfragen('discovery');
$r = $ablauf(null, $ec);
pruefe($r['code'] === 'alg' && Auth::id() === null && $anfragen('discovery') === $n, 'E.4: … innerhalb von 300 s nicht noch einmal (alg, keine Anfrage)');
$uhr += Anbieter::JWKS_NEU_FRUEHESTENS + 1;
$r = $ablauf(null, $ec);
pruefe($r['ok'] && $anfragen('discovery') === $n + 1, 'E.4: … nach 300 s wieder');
$abmelden();
$uhr += Anbieter::JWKS_NEU_FRUEHESTENS + 1;
Anbieter::discovery(true);
$n = $anfragen('discovery');
$r = $ablauf(null, ['alg' => 'HS256']);
pruefe($r['code'] === 'alg' && $anfragen('discovery') === $n, 'E.4: HS256 → alg, ohne die Discovery neu zu holen');
$uhr -= 2 * (Anbieter::JWKS_NEU_FRUEHESTENS + 1);
Settings::forget(Einstellungen::DISCOVERY_NEU_AM);

// E.5 Userinfo
$n = $anfragen('userinfo');
$r = $ablauf($person($subPlaner, 'planer-aus-userinfo'), ['claims_im_id_token' => false]);
pruefe($r['ok'] && $anfragen('userinfo') === $n + 1, 'ohne Profil-Claims im ID-Token → Userinfo abgerufen');
gleich((string) Database::scalar('SELECT name_dort FROM sso_identitaeten WHERE user_id = ?', [$planer]), 'planer-aus-userinfo', 'Name dort aus der Userinfo');
$abmelden();
$n = $anfragen('userinfo');
$ablauf($person($subPlaner, 'planer-dort'));
gleich($anfragen('userinfo'), $n, 'mit allen Claims im ID-Token keine Userinfo');
$abmelden();
$r = $ablauf(null, ['claims_im_id_token' => false, 'userinfo_sub' => 'jemand-anders']);
pruefe($r['code'] === 'userinfo' && Auth::id() === null, 'sub der Userinfo abweichend → userinfo, nicht angemeldet');

// E.6 Bremse
$grenzen = [Config::get('security.ip_attempts'), Config::get('security.lockout_attempts')];
Config::set('security.ip_attempts', 3);
Config::set('security.lockout_attempts', 3);
$ipB = '203.0.113.' . random_int(10, 250);
for ($i = 0; $i < 3; $i++) {
    $ablauf(null, ['token_fehler' => 'invalid_grant'], 'anmelden', null, null, null, $ipB);
}
$n = $anfragen('token');
$r = $ablauf(null, [], 'anmelden', null, null, null, $ipB);
pruefe($r['code'] === 'gebremst' && str_contains($r['meldung'], 'Zu viele') && $anfragen('token') === $n && Auth::id() === null,
    'nach ip_attempts Fehlschlägen: gebremst, ohne Token-Anfrage');
pruefe(Anmeldung::gebremst($ipB) !== null && Anmeldung::gebremst('192.0.2.' . random_int(1, 250)) === null, 'die Bremse gilt je Adresse');
$r = Auth::attempt($kennung . '-planer', $pw, $ipB);
pruefe($r['ok'], 'die Kennwort-Anmeldung derselben Adresse ist nicht gebremst');
$abmelden();
Config::set('security.ip_attempts', $grenzen[0]);
Config::set('security.lockout_attempts', $grenzen[1]);

// ================================================================ F. Verknüpfung, Anfragen, Anlage
// F.1 Umbenennen beim Anmeldedienst
$r = $ablauf($person($subPlaner, 'planer-umbenannt'));
pruefe($r['ok'] && Auth::id() === $planer && Auth::name() === $kennung . '-planer', 'umbenannt beim Anmeldedienst → dasselbe Konto, der lokale Name bleibt');
gleich((string) Database::scalar('SELECT name_dort FROM sso_identitaeten WHERE user_id = ?', [$planer]), 'planer-umbenannt', 'name_dort neu');
$abmelden();

// F.2 Gleichnamiges lokales Konto, Anlage aus → Anfrage
$erika = BenutzerService::anlegen($kennung . '-erika', $pw, 'planung', false);
$subErika = 'sub-erika-' . $kennung;
Settings::put(Einstellungen::ANLEGEN, '0');
$r = $ablauf($person($subErika, $kennung . '-erika', [$gruppeA, '123']));
$anfrage = Database::first('SELECT * FROM sso_anfragen WHERE subjekt = ?', [$subErika]);
pruefe($r['code'] === 'kein_konto' && $r['meldung'] === Konten::T_KEIN_KONTO && Konten::identitaet($erika) === null && Auth::id() === null,
    'gleichnamiges Konto, Anlage aus → kein_konto, nicht verknüpft');
pruefe($anfrage !== null && $anfrage['grund'] === 'kein_konto' && (int) $anfrage['anzahl'] === 1 && json_decode((string) $anfrage['gruppen'], true) === [$gruppeA, '123']
    && (int) $anfrage['email_bestaetigt'] === 1 && $anfrage['name_dort'] === $kennung . '-erika', 'Anmeldeanfrage mit Grund, Gruppen und bestätigter E-Mail');
$ablauf();
gleich((int) Database::scalar('SELECT anzahl FROM sso_anfragen WHERE subjekt = ?', [$subErika]), 2, 'zweiter Versuch → anzahl 2');
$vorschlag = array_values(array_filter(Konten::anfragen(), static fn (array $x): bool => $x['subjekt'] === $subErika))[0] ?? [];
gleich($vorschlag['vorschlag'] ?? null, $erika, 'Vorschlag: das Konto gleichen Namens (nur Vorauswahl)');

// F.3 Groß/Klein im Subjekt (W1)
$gross = BenutzerService::anlegen($kennung . '-gross', $pw, 'planung', false);
$subGross = 'AbC-' . $kennung;
Konten::verknuepfen($gross, $iss, $subGross, 'cli', '', []);
$r = $ablauf($person(strtolower($subGross), 'klein-' . $kennung));
pruefe(!$r['ok'] && Auth::id() !== $gross && Konten::identitaetFinden($iss, strtolower($subGross)) === null
    && (int) (Konten::identitaetFinden($iss, $subGross)['user_id'] ?? 0) === $gross, 'sub „abc…“ trifft nicht die Identität „AbC…“');

// F.4 Freischalten
alsBenutzer($verwalter);
pruefe(!Konten::anfrageFreischalten((int) $anfrage['id'], $admin)['ok'], 'freischalten an ein *-Konto ohne darfVerwalten → abgelehnt');
alsBenutzer($planer);
pruefe(!Konten::anfrageFreischalten((int) $anfrage['id'], $erika)['ok'], 'freischalten ohne system.users.manage → abgelehnt');
alsBenutzer($admin);
pruefe(!Konten::anfrageFreischalten((int) $anfrage['id'], $gross)['ok'], 'freischalten an ein schon verknüpftes Konto → abgelehnt');
Konten::anfrageMerken(['sub' => $subPlaner, 'preferred_username' => 'x'], [], 'kein_konto');
$anfragePlaner = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', [$subPlaner]);
$r = Konten::anfrageFreischalten($anfragePlaner, $erika);
pruefe(!$r['ok'] && $r['message'] === Konten::T_VERKNUEPFT, 'freischalten einer Identität, die anderswo verknüpft ist → abgelehnt');
$kundeId = Database::insert('kunden', ['name' => 'Prüfung SSO ' . $kennung, 'aktiv' => 1, 'created_at' => gmdate('c')]);
$kundenzugang = KundenService::kontoErzeugen($kundeId, $kennung . '-kz', '', '', password_hash($pw, PASSWORD_DEFAULT), false);
pruefe(!Konten::anfrageFreischalten((int) $anfrage['id'], $kundenzugang)['ok'], 'freischalten an einen Kundenzugang → abgelehnt');
$r = Konten::anfrageFreischalten((int) $anfrage['id'], $erika);
$ident = Konten::identitaet($erika);
pruefe($r['ok'] && ($ident['weg'] ?? null) === 'freischaltung' && ($ident['subjekt'] ?? null) === $subErika
    && Database::first('SELECT id FROM sso_anfragen WHERE id = ?', [(int) $anfrage['id']]) === null, 'als Administrator freigeschaltet: verknüpft, Anfrage weg');
$abmelden();
$r = $ablauf($person($subErika, 'erika-dort'));
pruefe($r['ok'] && Auth::id() === $erika, 'die nächste Anmeldung geht');
$abmelden();

// F.5 Neues Konto aus einer Anfrage
$subNeu = 'sub-neu-' . $kennung;
gleich($ablauf($person($subNeu, $kennung . '-neu', ['pm-x']))['code'], 'kein_konto', 'unbekannte Person → kein_konto');
$anfrageNeu = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', [$subNeu]);
alsBenutzer($verwalter);
$r = Konten::anfrageAnlegen($anfrageNeu, ['username' => $kennung . '-neu', 'rollen' => [(string) $rolle('planung')]]);
pruefe(!$r['ok'] && Database::first('SELECT id FROM users WHERE username = ?', [$kennung . '-neu']) === null,
    'anlegen mit einer Rolle, die der Aufrufer nicht vergeben darf → abgelehnt, kein Konto');
alsBenutzer($admin);
pruefe(!Konten::anfrageAnlegen($anfrageNeu, ['username' => strtoupper($kennung) . '-PLANER'])['ok'], 'anlegen mit vergebenem Namen (andere Schreibweise) → abgelehnt');
$r = Konten::anfrageAnlegen($anfrageNeu, ['username' => $kennung . '-neu', 'rollen' => [(string) $rolle('planung')]]);
$neu = BenutzerService::find((int) ($r['user_id'] ?? 0)) ?? [];
pruefe($r['ok'] && (int) ($neu['nur_sso'] ?? 0) === 1 && (int) $neu['must_change_password'] === 0 && !password_verify('', (string) $neu['password_hash'])
    && (Konten::identitaet((int) $neu['id'])['weg'] ?? null) === 'freischaltung', 'anlegen: Konto nur_sso, verknüpft');
gleich(Database::select('SELECT role_id, quelle FROM user_roles WHERE user_id = ?', [(int) ($neu['id'] ?? 0)]),
    [['role_id' => $rolle('planung'), 'quelle' => 'hand']], 'anlegen: die gewählte Rolle von Hand');
$abmelden();
pruefe($ablauf()['ok'] && Auth::id() === (int) ($neu['id'] ?? -1), 'das neue Konto meldet sich an');
$abmelden();
alsBenutzer($admin);
Konten::anfrageMerken(['sub' => 'sub-weg-' . $kennung], [], 'kein_konto');
$weg = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['sub-weg-' . $kennung]);
pruefe(Konten::anfrageVerwerfen($weg)['ok'] && Database::first('SELECT id FROM sso_anfragen WHERE id = ?', [$weg]) === null, 'Anfrage verworfen');
$abmelden();

// F.6 Automatische Anlage
Settings::put(Einstellungen::ANLEGEN, '1');
$subAuto = 'sub-auto-' . $kennung;
$r = $ablauf($person($subAuto, $kennung . '-auto', [$gruppeA], ['name' => 'Anton Auto', 'email_verified' => false]));
$auto = Database::first('SELECT * FROM users WHERE username = ?', [$kennung . '-auto']) ?? [];
pruefe($r['ok'] && !empty($r['angelegt']) && Auth::id() === (int) ($auto['id'] ?? -1), 'Anlage an + zugeordnete Gruppe → Konto angelegt und angemeldet');
pruefe((int) ($auto['nur_sso'] ?? 0) === 1 && (int) ($auto['must_change_password'] ?? 1) === 0 && !password_verify('', (string) ($auto['password_hash'] ?? ''))
    && ($auto['full_name'] ?? '') === 'Anton Auto' && ($auto['email'] ?? 'x') === '' && str_contains((string) ($auto['notizen'] ?? ''), 'SSO'),
    'angelegt: nur_sso, ohne Kennwortwechsel, ohne nutzbares Kennwort, Name, E-Mail nur bestätigt, Notiz');
$zeile = Database::first("SELECT * FROM user_roles WHERE user_id = ? AND quelle = 'sso'", [(int) ($auto['id'] ?? 0)]) ?? [];
pruefe((int) ($zeile['role_id'] ?? 0) === $rolleA && abs((int) Zeit::ts($zeile['gueltig_bis'] ?? null) - ($uhr + Einstellungen::sitzungDauer())) <= 5,
    'angelegt: Rolle aus der Gruppe bis Ende der Sitzung');
gleich((int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [(int) ($auto['id'] ?? 0)]), 0, 'angelegt: keine Freigabe');
$audit = json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'sso.konto_angelegt' ORDER BY id DESC LIMIT 1"), true);
gleich($audit, ['user_id' => (int) ($auto['id'] ?? 0), 'rollen' => [$rolleA], 'kunde_id' => null], 'Audit sso.konto_angelegt');
$abmelden();
BenutzerService::anlegen($kennung . '-max', $pw, 'kunde', false);
$r = $ablauf($person('sub-max-' . $kennung, $kennung . '-MAX', [$gruppeA]));
pruefe($r['code'] === 'name' && Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['sub-max-' . $kennung]) !== null
    && (int) Database::scalar('SELECT COUNT(*) FROM users WHERE LOWER(username) = ?', [$kennung . '-max']) === 1, 'Name vergeben (andere Schreibweise) → name + Anfrage, kein Konto');
gleich($ablauf($person('sub-j-' . $kennung, 'jürgen', [$gruppeA]))['code'], 'name', '„jürgen“ → name');
gleich($ablauf($person('sub-ohne-' . $kennung, $kennung . '-ohne', ['nicht-zugeordnet']))['code'], 'kein_konto', 'Anlage an, aber keine zugeordnete Gruppe → kein_konto');
Settings::put(Einstellungen::ANLEGEN, '0');
pruefe(Auth::id() === null, 'nach den Ablehnungen niemand angemeldet');

// F.7 Höchstens 200 Anfragen – die älteste geht
Database::execute('DELETE FROM sso_anfragen');
$uhrVorher = $uhr;
for ($i = 0; $i <= Konten::MAX_ANFRAGEN; $i++) {
    $uhr++;
    Konten::anfrageMerken(['sub' => 'viele-' . $i . '-' . $kennung], [], 'kein_konto');
}
$uhr = $uhrVorher;
pruefe((int) Database::scalar('SELECT COUNT(*) FROM sso_anfragen') === Konten::MAX_ANFRAGEN
    && Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['viele-0-' . $kennung]) === null
    && Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['viele-1-' . $kennung]) !== null, '201 Anfragen → die älteste ist weg');
Database::execute('DELETE FROM sso_anfragen');

// F.8 Verbinden im Profil – wer darf (Anmeldung::verbindenFehler, Start und Link im Profil)
$abmelden();
gleich(Anmeldung::verbindenFehler(), 'Bitte zuerst anmelden.', 'verbinden: ohne Anmeldung → abgelehnt');
$verbinder = BenutzerService::anlegen($kennung . '-verbinder', $pw, 'planung', false);
pruefe(Auth::attempt($kennung . '-verbinder', $pw, '192.0.2.11')['ok'] && Anmeldung::verbindenFehler() === null, 'verbinden: Kennwort-Sitzung, unverknüpft → erlaubt');
Settings::put(Einstellungen::AKTIV, '0');
gleich(Anmeldung::verbindenFehler(), Anmeldung::T_NICHT_EINGERICHTET, 'verbinden: SSO aus → abgelehnt');
Settings::put(Einstellungen::AKTIV, '1');
Konten::verknuepfen($verbinder, $iss, 'sub-verbinder-' . $kennung, 'cli', '', []);
pruefe(str_contains((string) Anmeldung::verbindenFehler(), 'schon'), 'verbinden: schon verknüpft → abgelehnt');
$abmelden();
pruefe($ablauf($person($subPlaner, 'planer-dort'))['ok'] && str_contains((string) Anmeldung::verbindenFehler(), 'mit Kennwort'), 'verbinden: in einer SSO-Sitzung → abgelehnt');
$abmelden();
pruefe(Auth::attempt($kennung . '-kz', $pw, '192.0.2.11')['ok'] && Anmeldung::verbindenFehler() === Konten::T_KUNDE_NIE, 'verbinden: Kundenzugang → abgelehnt');
$abmelden();
Settings::put(Einstellungen::NUR_SSO, '1');
pruefe(Auth::attempt($kennung . '-admin', $pw, '192.0.2.11')['ok'] && Auth::anmeldeweg() === 'notzugang'
    && str_contains((string) Anmeldung::verbindenFehler(), 'Notzugang'), 'verbinden: Notzugang → abgelehnt');
Settings::put(Einstellungen::NUR_SSO, '0');
$abmelden();

// ================================================================ G. Prüfkette
$planerKonto = static fn (): array => (array) BenutzerService::find($planer);
Database::update('users', $planer, ['is_active' => 0]);
$r = $ablauf($person($subPlaner, 'planer-dort'));
pruefe($r['code'] === 'deaktiviert' && Auth::id() === null && !isset($_SESSION['user_id']), 'deaktiviert → abgelehnt, keine Sitzung');
Database::update('users', $planer, ['is_active' => 1, 'gueltig_bis' => Zeit::iso(time() - 60)]);
gleich($ablauf()['code'], 'zeitraum', 'gueltig_bis vorbei → zeitraum');
Database::update('users', $planer, ['gueltig_bis' => null, 'gueltig_ab' => Zeit::iso(time() + 3600)]);
gleich($ablauf()['code'], 'zeitraum', 'gueltig_ab künftig → zeitraum');
Database::update('users', $planer, ['gueltig_ab' => null, 'locked_until' => gmdate('c', time() + 3600), 'failed_logins' => 3]);
$r = $ablauf();
pruefe($r['ok'] && Auth::id() === $planer && $planerKonto()['locked_until'] === null && (int) $planerKonto()['failed_logins'] === 0,
    'locked_until (Kennwort-Bremse) hält SSO nicht auf und wird zurückgesetzt (W4)');
$abmelden();
Einstellungen::optionenSpeichern(['pflichtgruppen' => 'pm-pflicht-' . $kennung]);
gleich($ablauf($person($subPlaner, 'planer-dort', []))['code'], 'pflichtgruppe', 'Pflichtgruppe fehlt → pflichtgruppe');
pruefe($ablauf($person($subPlaner, 'planer-dort', ['pm-pflicht-' . $kennung]))['ok'], 'mit Pflichtgruppe → angemeldet');
$abmelden();
Einstellungen::optionenSpeichern(['pflichtgruppen' => '']);
$subVerwalter = 'sub-verwalter-' . $kennung;
Konten::verknuepfen($verwalter, $iss, $subVerwalter, 'cli', '', []);
$r = $ablauf($person($subVerwalter, 'verwalter-dort'));
pruefe($r['code'] === 'verwaltung' && $r['meldung'] === Schutz::T_VERWALTUNG && Auth::id() === null, 'Hand-Rolle mit system.users.manage, sso.admins aus → verwaltung');
Settings::put(Einstellungen::ADMINS, '1');
pruefe($ablauf()['ok'] && Auth::id() === $verwalter, 'sso.admins an → angemeldet');
$abmelden();
Settings::put(Einstellungen::MFA, '1');
gleich($ablauf($person($subVerwalter, 'verwalter-dort', [], ['amr' => ['pwd']]))['code'], 'mfa', 'MFA-Pflicht, amr [pwd] → mfa');
gleich($ablauf($person($subVerwalter, 'verwalter-dort', [], ['amr' => null]))['code'], 'mfa', 'MFA-Pflicht, amr fehlt → mfa');
pruefe($ablauf($person($subVerwalter, 'verwalter-dort', [], ['amr' => ['pwd', 'mfa']]))['ok'], 'MFA-Pflicht, amr [pwd, mfa] → angemeldet');
$sitzungVerwalter = [(array) Auth::user(), (array) $_SESSION['sso'], (int) $_SESSION['angemeldet']];
pruefe(Schutz::sitzungGilt(...$sitzungVerwalter) && $sitzungVerwalter[1]['mfa'] === true, 'Sitzung mit zweitem Faktor gilt');
pruefe(!Schutz::sitzungGilt($sitzungVerwalter[0], ['mfa' => false] + $sitzungVerwalter[1], $sitzungVerwalter[2]),
    'Sitzung eines Verwaltungskontos ohne zweiten Faktor endet, sobald die MFA-Pflicht gilt');
$abmelden();
gleich($ablauf($person($subPlaner, 'planer-dort', [], ['amr' => null]))['code'], 'ok', 'MFA-Pflicht: Personal ohne Verwaltungsrechte braucht kein amr');
Settings::put(Einstellungen::MFA, '0');
Settings::put(Einstellungen::ADMINS, '0');
$abmelden();

// ================================================================ H. Rollen aus Gruppen
$ssoRollen = static fn (int $userId): array => array_map('intval', array_column(Database::select(
    "SELECT role_id FROM user_roles WHERE user_id = ? AND quelle = 'sso' ORDER BY role_id", [$userId]), 'role_id'));
$letztesAudit = static fn (string $aktion): array => (array) json_decode((string) Database::scalar(
    'SELECT details FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [$aktion]), true);
// H.1 Gruppe neu → Rolle; Gruppe weg → Rolle weg
$ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
gleich($ssoRollen($planer), [$rolleA], 'Gruppe neu → Rolle aus der Gruppe');
gleich($letztesAudit('sso.rollen'), ['user_id' => $planer, 'hinzu' => [$rolleA], 'weg' => []], 'Audit sso.rollen (hinzu)');
pruefe(in_array('steuern.protokoll', Auth::permissions(), true), 'das Recht der Rolle gilt in dieser Sitzung');
$abmelden();
$ablauf($person($subPlaner, 'planer-dort', []));
gleich($ssoRollen($planer), [], 'Gruppe weg → Rolle weg');
gleich($letztesAudit('sso.rollen'), ['user_id' => $planer, 'hinzu' => [], 'weg' => [$rolleA]], 'Audit sso.rollen (weg)');
$abmelden();
// H.2 Hand-Rolle gleich der zugeordneten
alsBenutzer($admin);
Konten::zuordnungSpeichern('pm-planung-' . $kennung, 'rolle', $rolle('planung'));
$abmelden();
$ablauf($person($subPlaner, 'planer-dort', ['pm-planung-' . $kennung]));
gleich((int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$planer, $rolle('planung')]), 1,
    'eine Hand-Rolle gleich der zugeordneten bekommt keine zweite Zeile');
$abmelden();
// H.3 Benutzerverwaltung lässt SSO-Zeilen stehen; eine angehakte Rolle ersetzt sie
$ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
$abmelden();
alsBenutzer($admin);
$daten = ['username' => $kennung . '-planer', 'is_active' => 1];
pruefe(BenutzerService::speichern($planer, $daten, [$rolle('planung') => ['aktiv' => 1]])['ok'] && $ssoRollen($planer) === [$rolleA],
    'Speichern in der Benutzerverwaltung lässt die Rolle aus der Gruppe stehen');
BenutzerService::speichern($planer, $daten, [$rolle('planung') => ['aktiv' => 1], $rolleA => ['aktiv' => 1]]);
pruefe($ssoRollen($planer) === [] && (string) Database::scalar('SELECT quelle FROM user_roles WHERE user_id = ? AND role_id = ?', [$planer, $rolleA]) === 'hand',
    'eine angehakte Rolle ersetzt die Zeile aus der Gruppe (dann von Hand)');
BenutzerService::speichern($planer, $daten, [$rolle('planung') => ['aktiv' => 1]]);
gleich((int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$planer, $rolleA]), 0, 'abgehakt → weg');
// H.4 Abgelaufen: weder Recht noch Rollen-Freigabe
$freigabe = Database::insert('freigaben', ['subject_type' => 'role', 'subject_id' => $rolleA, 'ziel_art' => 'site', 'ziel_id' => 0, 'ziel_kanal' => '',
    'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'notiz' => $kennung, 'created_by' => 'test', 'created_at' => gmdate('c')]);
$abmelden();
$ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
$hatFreigabe = static fn (): bool => array_filter(Zugriff::freigaben($planer), static fn (array $f): bool => (int) $f['id'] === $freigabe) !== [];
pruefe(in_array('steuern.protokoll', Auth::permissions(), true) && $hatFreigabe(), 'Rolle aus der Gruppe: Recht und Rollen-Freigabe gelten');
Database::execute("UPDATE user_roles SET gueltig_bis = ? WHERE user_id = ? AND quelle = 'sso'", [Zeit::iso(time() - 60), $planer]);
Auth::rechteVergessen();
Zugriff::vergessen();
pruefe(!in_array('steuern.protokoll', Auth::permissions(), true) && !$hatFreigabe(), 'abgelaufen: weder Recht noch Rollen-Freigabe');
Database::delete('freigaben', $freigabe);
$abmelden();
// H.5 Kennwort-Anmeldung und gelöschte Zuordnung entfernen SSO-Zeilen
$ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
$abmelden();
pruefe(Auth::attempt($kennung . '-planer', $pw, $ip)['ok'] && $ssoRollen($planer) === [] && Auth::anmeldeweg() === 'kennwort',
    'Kennwort-Anmeldung entfernt die Rollen aus Gruppen, Weg kennwort');
gleich(json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'auth.login' ORDER BY id DESC LIMIT 1"), true),
    ['ip' => $ip, 'weg' => 'kennwort'], 'Audit auth.login mit Weg');
$abmelden();
$ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
$abmelden();
alsBenutzer($admin);
pruefe(Konten::zuordnungLoeschen($zuordnungA)['ok'] && $ssoRollen($planer) === [], 'gelöschte Zuordnung entfernt die Rollen dieser Rolle sofort');
Konten::zuordnungSpeichern($gruppeA, 'rolle', $rolleA);
// H.6 Eine *-Rolle direkt in der Datenbank wird übergangen
$adminRolle = (int) Database::scalar('SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1');
Database::insert('sso_zuordnungen', ['gruppe' => 'pm-admin-' . $kennung, 'art' => 'rolle', 'ziel_id' => $adminRolle, 'created_by' => 'sql', 'created_at' => gmdate('c')]);
$abmelden();
$r = $ablauf($person($subPlaner, 'planer-dort', ['pm-admin-' . $kennung]));
pruefe($r['ok'] && $ssoRollen($planer) === [] && !Auth::can('*'), '*-Rolle aus der Datenbank ohne sso.admins übergangen');
gleich($letztesAudit('sso.rolle_uebergangen'), ['user_id' => $planer, 'role_id' => $adminRolle], 'Audit sso.rolle_uebergangen');
Database::execute('DELETE FROM sso_zuordnungen WHERE gruppe = ?', ['pm-admin-' . $kennung]);
$abmelden();
// H.7 zuordnungFehler
alsBenutzer($verwalter);
pruefe(Konten::zuordnungFehler('pm-neu', 'rolle', $rolleA) !== null, 'Rolle, die der Aufrufer nicht vergeben darf → abgelehnt');
alsBenutzer($admin);
pruefe(str_contains((string) Konten::zuordnungFehler('pm-neu', 'rolle', $adminRolle), 'allen Rechten'), '*-Rolle ohne sso.admins → abgelehnt (auch mit *)');
pruefe(str_contains((string) Konten::zuordnungFehler('pm-neu', 'rolle', $rolleVerwalter), 'Verwaltung per SSO'), 'verwaltende Rolle ohne sso.admins → abgelehnt');
Settings::put(Einstellungen::ADMINS, '1');
pruefe(Konten::zuordnungFehler('pm-neu', 'rolle', $adminRolle) === null && Konten::zuordnungFehler('pm-neu', 'rolle', $rolleVerwalter) === null,
    'mit sso.admins und * zuordenbar');
alsBenutzer($verwalter);
pruefe(Konten::zuordnungFehler('pm-neu', 'rolle', $adminRolle) !== null, '*-Rolle ohne eigenes * → abgelehnt (auch mit sso.admins)');
Settings::put(Einstellungen::ADMINS, '0');
$rolleB = (int) BenutzerService::rolleSpeichern(null, $kennung . '-alles', 'Prüfung', ['dashboard.view'])['id'];
Database::insert('freigaben', ['subject_type' => 'role', 'subject_id' => $rolleB, 'ziel_art' => 'alles', 'ziel_id' => 0, 'ziel_kanal' => '',
    'darf_bearbeiten' => 0, 'darf_steuern' => 0, 'notiz' => $kennung, 'created_by' => 'test', 'created_at' => gmdate('c')]);
$rolleVerwalter2 = (int) BenutzerService::rolleSpeichern(null, $kennung . '-verwalter2', 'Prüfung', ['system.users.manage', 'dashboard.view'])['id'];
Database::insert('user_roles', ['user_id' => $verwalter, 'role_id' => $rolleVerwalter2, 'gueltig_ab' => null, 'gueltig_bis' => null]);
alsBenutzer($verwalter);
pruefe(str_contains((string) Konten::zuordnungFehler('pm-neu', 'rolle', $rolleB), 'Freigaben auf alles'), 'Rolle mit alles-Freigabe für Nicht-* → abgelehnt');
alsBenutzer($admin);
pruefe(Konten::zuordnungFehler('pm-neu', 'rolle', $rolleB) === null, '… mit * zuordenbar');
Database::insert('sso_zuordnungen', ['gruppe' => 'pm-kunde-' . $kennung, 'art' => 'kunde', 'ziel_id' => $kundeId, 'created_by' => 'sql', 'created_at' => gmdate('c')]);
pruefe(str_contains((string) Konten::zuordnungFehler('pm-kunde-' . $kennung, 'rolle', $rolleA), 'entweder'), 'Gruppe zugleich Kundengruppe → abgelehnt');
pruefe(Konten::zuordnungFehler($gruppeA, 'rolle', $rolleA) !== null && Konten::zuordnungFehler(" \x01 ", 'rolle', $rolleA) !== null
    && Konten::zuordnungFehler(str_repeat('g', 151), 'rolle', $rolleA) !== null, 'doppelt, Steuerzeichen, 151 Zeichen → abgelehnt');
pruefe(!Konten::zuordnungSpeichern('pm-neu', 'rolle', 999999)['ok'], 'unbekannte Rolle → abgelehnt');
Database::execute('DELETE FROM sso_zuordnungen WHERE gruppe = ?', ['pm-kunde-' . $kennung]);
alsBenutzer($planer);
pruefe(Konten::zuordnungFehler('pm-neu', 'rolle', $rolleA) !== null && !Konten::zuordnungLoeschen((int) Database::scalar(
    'SELECT id FROM sso_zuordnungen WHERE gruppe = ?', [$gruppeA]))['ok'], 'ohne system.users.manage weder anlegen noch löschen');
// H.8 Die Gruppe „123“ (W23)
alsBenutzer($admin);
pruefe(Konten::zuordnungSpeichern('123', 'rolle', $rolleA)['ok'], 'Zuordnung der Gruppe „123“');
$abmelden();
$ablauf($person($subPlaner, 'planer-dort', ['123']));
gleich($ssoRollen($planer), [$rolleA], 'Gruppe „123“ gibt die Rolle (nie als Array-Schlüssel)');
gleich(Konten::gruppen(['groups' => [123, '123', ' 123 ', 'a', "b\x01", str_repeat('g', 151), ['x'], 'a']]), ['123', 'a'],
    'gruppen(): nur Strings, getrimmt, ohne Steuerzeichen/Überlänge/Doppelte');
$abmelden();
// H.9 Dieselbe Gruppe in anderer Schreibweise für dasselbe Ziel: Der eindeutige
// Index vergleicht unter MariaDB ohne Groß/Klein – vorher ablehnen statt 500.
alsBenutzer($admin);
$r = Konten::zuordnungSpeichern(strtoupper($gruppeA), 'rolle', $rolleA);
pruefe(!$r['ok'] && str_contains($r['message'], 'Schreibweise „' . $gruppeA . '“')
    && (int) Database::scalar('SELECT COUNT(*) FROM sso_zuordnungen WHERE LOWER(gruppe) = LOWER(?)', [$gruppeA]) === 1,
    'H.9: dieselbe Gruppe in anderer Schreibweise für dieselbe Rolle → abgelehnt');
gleich(Konten::zuordnungFehler(strtoupper($gruppeA), 'rolle', $rolle('planung')), null, 'H.9: … für eine andere Rolle zuordenbar');
// H.10 Zu einem anderen Anmeldedienst umstellen, während es Zuordnungen gibt, nur,
// wer jede davon selbst anlegen dürfte – sonst vergäbe dessen Gruppe die Rollen.
$einrichterRolle = (int) BenutzerService::rolleSpeichern(null, $kennung . '-einrichter', 'Prüfung', ['sso.manage', 'dashboard.view'])['id'];
$einrichter = BenutzerService::anlegen($kennung . '-einrichter', $pw, (string) Database::scalar('SELECT role_key FROM roles WHERE id = ?', [$einrichterRolle]), false);
$verbindung = static fn (): array => [Einstellungen::aussteller(), Einstellungen::clientId(), Einstellungen::secretZustand(), Einstellungen::marke(),
    Einstellungen::aktiv()];
$stand = $verbindung();
alsBenutzer($einrichter);
$r = Einstellungen::verbindungSpeichern('https://idp-fremd.example.test/application/o/pm/', $client['id'], str_repeat('s', 24), 'Authentik');
pruefe(!$r['ok'] && str_contains($r['message'], 'anderen Anmeldedienst') && str_contains($r['message'], 'Benutzer verwalten') && $verbindung() === $stand,
    'H.10: nur „SSO einrichten“, Zuordnungen da: anderer Aussteller → abgelehnt, nichts geändert');
$r = Einstellungen::verbindungSpeichern($iss, $client['id'] . '-anders', '', 'Authentik');
pruefe(!$r['ok'] && $verbindung() === $stand, 'H.10: … ebenso eine andere Client-ID');
$r = Einstellungen::verbindungSpeichern($iss, $client['id'], '', 'Authentik');
pruefe($r['ok'] && $verbindung() === $stand, 'H.10: dieselbe Verbindung (nur Beschriftung) → gespeichert');
alsBenutzer($admin);
gleich(Einstellungen::verbindungRechtFehler(), null, 'H.10: die Administration dürfte umstellen');
alsBenutzer(null);
$abmelden();

// ================================================================ I. Sitzung
$ablauf($person($subPlaner, 'planer-dort'));
$sitzung = static fn (): array => [(array) BenutzerService::find($planer), (array) ($_SESSION['sso'] ?? []), (int) ($_SESSION['angemeldet'] ?? 0)];
pruefe(Schutz::sitzungGilt(...$sitzung()) && Auth::anmeldeweg() === 'sso', 'sitzungGilt nach der Anmeldung');
pruefe(($_SESSION['sso']['ident'] ?? 0) === (int) Konten::identitaet($planer)['id'] && ($_SESSION['sso']['name'] ?? '') === 'planer-dort'
    && ($_SESSION['sso']['marke'] ?? '') === Einstellungen::marke() && is_int($_SESSION['sso']['auth_time'] ?? null), 'SSO-Angaben in der Sitzung');
Einstellungen::markeErneuern();
pruefe(!Schutz::sitzungGilt(...$sitzung()), 'nach markeErneuern: Sitzung zu Ende');
Auth::vergessen();
pruefe(Auth::user() === null && !isset($_SESSION['user_id']), 'Auth::user() beendet sie bei der nächsten Anfrage');
$abmelden();
$ablauf();
Konten::sitzungenBeenden($planer);
pruefe(!Schutz::sitzungGilt(...$sitzung()), 'nach sitzungenBeenden: Sitzung zu Ende');
Database::execute('UPDATE sso_identitaeten SET abgemeldet_am = NULL WHERE user_id = ?', [$planer]);
pruefe(Schutz::sitzungGilt(...$sitzung()), '(Prüfung: ohne abgemeldet_am gilt sie wieder)');
$uhr += Einstellungen::sitzungDauer() + 1;
pruefe(!Schutz::sitzungGilt(...$sitzung()), 'nach Ablauf von sitzungDauer(): Sitzung zu Ende');
$uhr -= Einstellungen::sitzungDauer() + 1;
Einstellungen::ausschalten();
pruefe(!Schutz::sitzungGilt(...$sitzung()), 'nach ausschalten(): Sitzung zu Ende');
pruefe(Einstellungen::einschalten()['ok'], '(wieder eingeschaltet)');
$abmelden();
Settings::put(Einstellungen::ADMINS, '1');
$ablauf($person($subVerwalter, 'verwalter-dort'));
$sitzungV = [(array) BenutzerService::find($verwalter), (array) $_SESSION['sso'], (int) $_SESSION['angemeldet']];
pruefe(Schutz::sitzungGilt(...$sitzungV), 'Verwaltungskonto mit sso.admins: Sitzung gilt');
Settings::put(Einstellungen::ADMINS, '0');
pruefe(!Schutz::sitzungGilt(...$sitzungV), 'sso.admins aus: Sitzung des Verwaltungskontos zu Ende');
$abmelden();
$ablauf($person($subPlaner, 'planer-dort'));
pruefe(Konten::loesen($planer) && !Schutz::sitzungGilt(...$sitzung()) && Konten::identitaet($planer) === null, 'nach Konten::loesen: Sitzung zu Ende');
gleich($letztesAudit('sso.geloest'), ['user_id' => $planer, 'weg' => 'cli'], 'Audit sso.geloest');
pruefe(!Konten::loesen($planer), 'ein zweites Lösen: nichts zu lösen');
Konten::verknuepfen($planer, $iss, $subPlaner, 'cli', 'planer-dort', []);
$abmelden();
// I.3 kennwortVerboten
Database::update('users', $planer, ['nur_sso' => 1]);
pruefe(str_contains((string) Schutz::kennwortVerboten($planerKonto()), 'nur über Authentik'), 'kennwortVerboten: Konto mit nur_sso');
$r = Auth::attempt($kennung . '-planer', $pw, $ip);
pruefe(!$r['ok'] && str_contains($r['message'], 'nur über') && Auth::id() === null, 'nur_sso: Kennwort-Anmeldung abgelehnt (nach dem Kennwort)');
gleich(Auth::attempt($kennung . '-planer', 'falsch-' . $pw, $ip)['message'], 'Benutzername oder Passwort falsch.', 'nur_sso, falsches Kennwort: die allgemeine Meldung');
Database::update('users', $planer, ['nur_sso' => 0, 'failed_logins' => 0]);
Settings::put(Einstellungen::NUR_SSO, '1');
pruefe(str_contains((string) Schutz::kennwortVerboten($planerKonto()), 'verknüpfte Konten'), 'kennwortVerboten: „nur SSO“ an und verknüpft');
pruefe(Schutz::kennwortVerboten((array) BenutzerService::find($gross)) !== null && Schutz::kennwortVerboten((array) BenutzerService::find($admin)) === null,
    '„nur SSO“: unverknüpft → null');
pruefe(Auth::attempt($kennung . '-planer', $pw, $ip)['ok'] === false, '„nur SSO“: verknüpftes Konto meldet sich nicht mit Kennwort an');
$_SESSION = ['user_id' => $planer, 'konto' => $planer, 'angemeldet' => time(), 'anmeldeweg' => 'kennwort',
    'kennwort' => hash('sha256', 'pm-sitzung|' . $planerKonto()['password_hash'])];
Auth::vergessen();
pruefe(Auth::user() === null, '„nur SSO“: eine laufende Kennwort-Sitzung eines verknüpften Kontos endet');
$abmelden();
pruefe(Auth::attempt($kennung . '-admin', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'notzugang', '„nur SSO“: unverknüpftes *-Konto → Weg notzugang');
gleich(json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'auth.login' ORDER BY id DESC LIMIT 1"), true)['weg'] ?? null,
    'notzugang', 'Audit auth.login weg notzugang');
pruefe(Schutz::letzterNotzugang($admin) === (count(Schutz::notzugaenge()) === 1), 'letzterNotzugang passt zur Liste der Notzugänge');
$abmelden();
Settings::put(Einstellungen::NUR_SSO, '0');
pruefe(Auth::attempt($kennung . '-planer', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'kennwort', '„nur SSO“ aus: wieder mit Kennwort');
$abmelden();

// ================================================================ J. Nur SSO und Notzugang
$schalter = static fn (string $s): bool => Einstellungen::schalter($s);
Settings::put(Einstellungen::NUR_SSO, '0');
Settings::put(Einstellungen::ADMINS, '0');
Settings::put(Einstellungen::MFA, '0');
pruefe(Anbieter::pruefen()['ok'] && Einstellungen::letztePruefungOk(), 'J: Verbindung frisch geprüft, Befund ohne Fehler');
$chef = BenutzerService::anlegen($kennung . '-chef', $pw, 'admin', false);
$subChef = 'sub-chef-' . $kennung;
Konten::verknuepfen($chef, $iss, $subChef, 'cli', 'chef-dort', []);
$kundeKonto = BenutzerService::anlegen($kennung . '-kunde', $pw, 'kunde', false);
$verwalter2 = BenutzerService::anlegen($kennung . '-verwalter2', $pw, 'kunde', false);
Database::insert('user_roles', ['user_id' => $verwalter2, 'role_id' => $rolleVerwalter, 'gueltig_ab' => null, 'gueltig_bis' => null]);

// J.1 Schutz speichern
$abmelden();
alsBenutzer($planer);
$r = Einstellungen::schutzSpeichern(['admins' => true], '');
pruefe(!$r['ok'] && str_contains($r['message'], 'alle Rechte') && !$schalter(Einstellungen::ADMINS), 'J.1: Schutz ohne alle Rechte → abgelehnt');
alsBenutzer(null);
$abmelden();
pruefe(Auth::attempt($kennung . '-chef', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'kennwort', 'J.1: Chef (alle Rechte, verknüpft) mit Kennwort angemeldet');
$r = Einstellungen::schutzSpeichern(['admins' => true, 'nur_sso' => true], 'nur SSO');
pruefe(!$r['ok'] && str_contains($r['message'], 'erst über Authentik anmelden') && !$schalter(Einstellungen::ADMINS) && !$schalter(Einstellungen::NUR_SSO),
    'J.1: „nur SSO“ ohne SSO-Sitzung → abgelehnt, auch der zweite Schalter nicht gespeichert');
$r = Einstellungen::schutzSpeichern(['admins' => true], '');
pruefe($r['ok'] && $schalter(Einstellungen::ADMINS), 'J.1: „Verwaltung per SSO“ aus einer Kennwort-Sitzung eingeschaltet');
gleich($letztesAudit('einstellungen.sso'), ['schalter' => ['admins' => true]], 'J.1: Audit nennt nur den geänderten Schalter');
gleich(Einstellungen::schutzSpeichern(['admins' => true], '')['message'], 'Am Schutz hat sich nichts geändert.', 'J.1: nichts geändert');
$abmelden();
$r = $ablauf($person($subChef, 'chef-dort'));
pruefe($r['ok'] && Auth::id() === $chef && Auth::anmeldeweg() === 'sso', 'J.1: Chef über SSO angemeldet');
$offenePunkte = array_values(array_filter(Schutz::nurSsoVorbedingungen(), static fn (array $b): bool => !$b['ok']));
gleich($offenePunkte, [], 'J.1: die Checkliste ist ganz erfüllt');
gleich(count(Schutz::nurSsoVorbedingungen()), 5, 'J.1: fünf Punkte (alle Rechte, Prüfung, SSO-Sitzung, Verwaltung per SSO, Notzugang)');
$r = Einstellungen::schutzSpeichern(['admins' => false, 'nur_sso' => true], 'nur SSO');
pruefe(!$r['ok'] && str_contains($r['message'], 'bleibt „Verwaltung per SSO“ an') && !$schalter(Einstellungen::NUR_SSO) && $schalter(Einstellungen::ADMINS),
    'J.1: „nur SSO“ ohne „Verwaltung per SSO“ → abgelehnt');
$notzugaenge = array_map('intval', array_column(Schutz::notzugaenge(), 'id'));
pruefe(in_array($admin, $notzugaenge, true) && !in_array($chef, $notzugaenge, true) && !in_array($verwalter2, $notzugaenge, true),
    'J.1: Notzugänge sind unverknüpfte Konten mit allen Rechten (nicht der verknüpfte Chef, nicht der Verwalter)');
Database::execute('UPDATE users SET is_active = 0 WHERE id IN (' . implode(',', $notzugaenge) . ')');
$r = Einstellungen::schutzSpeichern(['nur_sso' => true], 'nur SSO');
pruefe(!$r['ok'] && str_contains($r['message'], 'keinen Notzugang') && !$schalter(Einstellungen::NUR_SSO), 'J.1: ohne Notzugang → abgelehnt');
Database::execute('UPDATE users SET is_active = 1 WHERE id IN (' . implode(',', $notzugaenge) . ')');
$befund = (string) Settings::get(Einstellungen::BEFUND, '');
Settings::put(Einstellungen::BEFUND, (string) json_encode([['ok' => true, 'text' => 'a'], ['ok' => false, 'text' => 'b']]));
$r = Einstellungen::schutzSpeichern(['nur_sso' => true], 'nur SSO');
pruefe(!$r['ok'] && str_contains($r['message'], 'Verbindung prüfen') && !$schalter(Einstellungen::NUR_SSO), 'J.1: letzte Prüfung mit Befund → abgelehnt');
Settings::put(Einstellungen::BEFUND, $befund);
foreach (['', 'nur', 'nur SSO!', 'SSO nur'] as $falsch) {
    $r = Einstellungen::schutzSpeichern(['nur_sso' => true], $falsch);
    pruefe(!$r['ok'] && str_contains($r['message'], 'eintippen') && !$schalter(Einstellungen::NUR_SSO), 'J.1: eingetippt ' . json_encode($falsch) . ' → abgelehnt');
}
$r = Einstellungen::schutzSpeichern(['admins' => true, 'nur_sso' => true], "  Nur   sso ");
pruefe($r['ok'] && $schalter(Einstellungen::NUR_SSO) && str_contains($r['message'], 'nur noch über Authentik'), 'J.1: mit allem → „nur SSO“ an (eingetippt ohne Groß/Klein)');
gleich($letztesAudit('einstellungen.sso'), ['schalter' => ['nur_sso' => true]], 'J.1: Audit „nur SSO“ an');
Auth::vergessen();
pruefe(Auth::id() === $chef && Auth::anmeldeweg() === 'sso', 'J.1: die eigene SSO-Sitzung läuft weiter');
// Die MFA-Pflicht nicht aus einer SSO-Sitzung ohne zweiten Faktor – sie endete sofort.
$r = Einstellungen::schutzSpeichern(['mfa_verwaltung' => true], '');
pruefe(!$r['ok'] && str_contains($r['message'], 'ohne zweiten Faktor') && !$schalter(Einstellungen::MFA), 'J.1: MFA-Pflicht aus einer SSO-Sitzung ohne zweiten Faktor → abgelehnt');
$abmelden();
pruefe($ablauf($person($subChef, 'chef-dort', [], ['amr' => ['pwd', 'mfa']]))['ok'], 'J.1: Chef mit zweitem Faktor über SSO angemeldet');
$r = Einstellungen::schutzSpeichern(['mfa_verwaltung' => true], '');
pruefe($r['ok'] && $schalter(Einstellungen::MFA) && $schalter(Einstellungen::NUR_SSO), 'J.1: … dann geht die MFA-Pflicht, „nur SSO“ bleibt');
Auth::vergessen();
pruefe(Auth::id() === $chef, 'J.1: die Sitzung mit zweitem Faktor läuft weiter');
pruefe(Einstellungen::schutzSpeichern(['mfa_verwaltung' => false], '')['ok'] && !$schalter(Einstellungen::MFA), 'J.1: MFA-Pflicht wieder aus');
$warnungen = Schutz::nurSsoWarnungen();
pruefe(in_array($kennung . '-verwalter2', $warnungen['verwaltend'], true) && !in_array($kennung . '-admin', $warnungen['verwaltend'], true)
    && $warnungen['verknuepft'] >= 3, 'J.1: Warnliste: unverknüpfter Verwalter (kein Notzugang), Zahl der verknüpften Konten');
$abmelden();

// J.2 Kennwort-Anmeldung bei „nur SSO“
$r = Auth::attempt($kennung . '-planer', $pw, $ip);
pruefe(!$r['ok'] && str_contains($r['message'], 'Bitte über Authentik anmelden') && Auth::id() === null, 'J.2: verknüpftes Personal mit richtigem Kennwort → „Bitte über …“');
gleich(json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1"), true),
    ['reason' => 'nur SSO'], 'J.2: … als auth.login_failed (nur SSO)');
gleich(Auth::attempt($kennung . '-planer', 'falsch-' . $pw, $ip)['message'], 'Benutzername oder Passwort falsch.', 'J.2: falsches Kennwort → die allgemeine Meldung');
Database::update('users', $planer, ['failed_logins' => 0, 'locked_until' => null]);
pruefe(Auth::attempt($kennung . '-admin', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'notzugang', 'J.2: unverknüpftes Konto mit allen Rechten → Weg notzugang');
gleich(json_decode((string) Database::scalar("SELECT details FROM audit_log WHERE action = 'auth.login' ORDER BY id DESC LIMIT 1"), true)['weg'] ?? null,
    'notzugang', 'J.2: Audit auth.login mit Weg notzugang');
$abmelden();
pruefe(Auth::attempt($kennung . '-kunde', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'kennwort', 'J.2: unverknüpftes Konto mit Rolle „kunde“ → Weg kennwort');
$abmelden();

// J.3 Der letzte Notzugang bleibt
$andere = array_values(array_diff(array_map('intval', array_column(Schutz::notzugaenge(), 'id')), [$admin]));
if ($andere !== []) {
    Database::execute('UPDATE users SET is_active = 0 WHERE id IN (' . implode(',', $andere) . ')');
}
pruefe(Schutz::letzterNotzugang($admin) && !Schutz::letzterNotzugang($chef), 'J.3: der Administrator ist der letzte Notzugang');
alsBenutzer($chef);
gleich($code(static fn () => Konten::verknuepfen($admin, $iss, 'sub-letzter-' . $kennung, 'cli', '', [])), 'notzugang', 'J.3: den letzten Notzugang verknüpfen → abgelehnt');
$adminRolle = (int) Database::scalar("SELECT role_id FROM user_roles WHERE user_id = ? AND quelle = 'hand'", [$admin]);
$daten = ['username' => $kennung . '-admin', 'is_active' => 1, 'nur_sso_feld' => '1'];
gleich(BenutzerService::speichern($admin, ['is_active' => 0] + $daten, [$adminRolle => ['aktiv' => 1]])['message'], Schutz::T_LETZTER_NOTZUGANG,
    'J.3: … deaktivieren → abgelehnt');
gleich(BenutzerService::speichern($admin, ['nur_sso' => '1'] + $daten, [$adminRolle => ['aktiv' => 1]])['message'], Schutz::T_LETZTER_NOTZUGANG,
    'J.3: … auf „nur über SSO“ stellen → abgelehnt');
gleich(BenutzerService::speichern($admin, $daten, [])['message'], Schutz::T_LETZTER_NOTZUGANG, 'J.3: … „*“ nehmen → abgelehnt');
gleich(BenutzerService::loeschen($admin)['message'], Schutz::T_LETZTER_NOTZUGANG, 'J.3: … löschen → abgelehnt');
pruefe(BenutzerService::speichern($admin, ['full_name' => 'Notfall ' . $kennung] + $daten, [$adminRolle => ['aktiv' => 1]])['ok']
    && Schutz::letzterNotzugang($admin), 'J.3: andere Änderungen am letzten Notzugang gehen');
// Auch nicht auf Termin: ein Enddatum am Konto oder an der Rolle mit allen Rechten.
$morgen = date('Y-m-d', time() + 86400);
gleich(BenutzerService::speichern($admin, ['gueltig_bis' => $morgen] + $daten, [$adminRolle => ['aktiv' => 1]])['message'], Schutz::T_LETZTER_NOTZUGANG,
    'J.3: … ein Enddatum am Konto → abgelehnt');
gleich(BenutzerService::speichern($admin, $daten, [$adminRolle => ['aktiv' => 1, 'bis' => $morgen]])['message'], Schutz::T_LETZTER_NOTZUGANG,
    'J.3: … ein Enddatum an der Rolle mit allen Rechten → abgelehnt');
pruefe(Database::scalar('SELECT gueltig_bis FROM users WHERE id = ?', [$admin]) === null
    && Database::scalar("SELECT gueltig_bis FROM user_roles WHERE user_id = ? AND role_id = ? AND quelle = 'hand'", [$admin, $adminRolle]) === null,
    'J.3: … beides nicht gespeichert');
// Zwei Notzugänge lassen sich nicht nacheinander auslaufen lassen: Es zählt nur einer ohne Enddatum.
$notfall2 = BenutzerService::anlegen($kennung . '-notfall2', $pw, 'admin', false);
pruefe(!Schutz::letzterNotzugang($admin) && Schutz::notzugangDauerhaft((array) BenutzerService::find($notfall2)), 'J.3: mit einem zweiten Notzugang ist der erste nicht mehr der letzte');
pruefe(BenutzerService::speichern($admin, ['gueltig_bis' => $morgen] + $daten, [$adminRolle => ['aktiv' => 1]])['ok']
    && Schutz::notzugangWirksam((array) BenutzerService::find($admin)) && !Schutz::notzugangDauerhaft((array) BenutzerService::find($admin)),
    'J.3: … er bekommt ein Enddatum (wirksam, aber nicht dauerhaft)');
$daten2 = ['username' => $kennung . '-notfall2', 'is_active' => 1, 'nur_sso_feld' => '1'];
$rolle2 = (int) Database::scalar("SELECT role_id FROM user_roles WHERE user_id = ? AND quelle = 'hand'", [$notfall2]);
pruefe(Schutz::letzterNotzugang($notfall2) && BenutzerService::speichern($notfall2, ['gueltig_bis' => $morgen] + $daten2, [$rolle2 => ['aktiv' => 1]])['message']
    === Schutz::T_LETZTER_NOTZUGANG, 'J.3: … dann ist der zweite der letzte – ihm ein Enddatum → abgelehnt');
pruefe(BenutzerService::speichern($admin, $daten, [$adminRolle => ['aktiv' => 1]])['ok'] && BenutzerService::loeschen($notfall2)['ok']
    && Schutz::letzterNotzugang($admin), 'J.3: Enddatum wieder weg, der zweite gelöscht – der erste ist wieder der letzte');
alsBenutzer(null);
if ($andere !== []) {
    Database::execute('UPDATE users SET is_active = 1 WHERE id IN (' . implode(',', $andere) . ')');
}

// J.4 Der Notausgang auf der Kommandozeile
$p = proc_open([PHP_BINARY, APP_ROOT . '/bin/power', 'sso:aus', '--nur-sso'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $rohre, APP_ROOT, getenv());
$cliAus = is_resource($p) ? (string) stream_get_contents($rohre[1]) . (string) stream_get_contents($rohre[2]) : '';
$rc = is_resource($p) ? proc_close($p) : -1;
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
pruefe($rc === 0 && str_contains($cliAus, '„Nur SSO“ ist aus') && !$schalter(Einstellungen::NUR_SSO) && Einstellungen::aktiv() && $schalter(Einstellungen::ADMINS),
    'J.4: bin/power sso:aus --nur-sso → „nur SSO“ aus, SSO bleibt an (' . trim($cliAus) . ')');
pruefe(Auth::attempt($kennung . '-planer', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'kennwort', 'J.4: verknüpftes Konto wieder mit Kennwort');
$abmelden();

// J.5 „Verwaltung per SSO“ aus nimmt verwaltende Rollen aus Gruppen sofort
Database::insert('user_roles', ['user_id' => $kundeKonto, 'role_id' => $rolleVerwalter, 'gueltig_bis' => gmdate('c', time() + 3600), 'quelle' => 'sso']);
Database::insert('user_roles', ['user_id' => $kundeKonto, 'role_id' => $rolleA, 'gueltig_bis' => gmdate('c', time() + 3600), 'quelle' => 'sso']);
alsBenutzer($admin);
$r = Einstellungen::schutzSpeichern(['admins' => false], '');
pruefe($r['ok'] && !$schalter(Einstellungen::ADMINS) && $ssoRollen($kundeKonto) === [$rolleA], 'J.5: sso.admins aus → verwaltende Rolle aus der Gruppe weg, die andere bleibt');
gleich($letztesAudit('einstellungen.sso'), ['schalter' => ['admins' => false], 'sso_rollen' => 1], 'J.5: Audit mit der Zahl der entfernten Rollen');
pruefe(in_array($rolleVerwalter, Schutz::verwaltendeRollen(), true) && !in_array($rolleA, Schutz::verwaltendeRollen(), true)
    && in_array((int) Database::scalar('SELECT id FROM roles WHERE is_system = 1'), Schutz::verwaltendeRollen(), true), 'J.5: verwaltende Rollen: Systemrolle und Verwaltungsrechte');
alsBenutzer(null);
Database::execute("DELETE FROM user_roles WHERE user_id = ? AND quelle = 'sso'", [$kundeKonto]);

// ================================================================ K. Bestätigen (Step-up)
$abmelden();
pruefe($ablauf($person($subPlaner, 'planer-dort'))['ok'] && Auth::id() === $planer, 'K: Planer über SSO angemeldet');
gleich(Anmeldung::bestaetigenFehler('token:5'), null, 'K: Bestätigen über SSO angeboten');
pruefe(Anmeldung::bestaetigenFehler('alles:5') !== null && str_contains((string) Anmeldung::bestaetigenFehler('kundenmodus:7'), 'nicht mehr aktiv'),
    'K: unbekannter Zweck bzw. Kundenmodus ohne Kundenmodus → nicht angeboten');
// K.1 einmal, 300 s, nur dieser Zweck, nur dieses Konto
$r = $ablauf(null, [], 'token:5', $planer, null, '/system/schnittstelle');
pruefe($r['ok'] && $r['zweck'] === 'token' && $r['ziel'] === '/system/schnittstelle' && Auth::id() === $planer && Auth::anmeldeweg() === 'sso',
    'K.1: Step-up token:5 → bestätigt, zurück zum Ziel, die Sitzung bleibt');
pruefe(Anmeldung::bestaetigt('token:5', false) && !Anmeldung::bestaetigt('token:6', false), 'K.1: gilt für genau diesen Zweck');
$uhr += Anmeldung::BESTAETIGT_SEKUNDEN + 1;
pruefe(!Anmeldung::bestaetigt('token:5', false), 'K.1: nach 301 s nicht mehr');
$uhr -= Anmeldung::BESTAETIGT_SEKUNDEN + 1;
Auth::alsBenutzer($admin);
pruefe(!Anmeldung::bestaetigt('token:5', false), 'K.1: für ein anderes Konto nicht');
Auth::alsBenutzer(null);
pruefe(Auth::id() === $planer && Auth::bestaetigen('token:5', '') && !Auth::bestaetigen('token:5', ''), 'K.1: Auth::bestaetigen ohne Kennwort: einmal, danach nicht mehr');
gleich($letztesAudit('auth.bestaetigung_fehlversuch'), ['zweck' => 'token:5'], 'K.1: der zweite Versuch steht im Audit');
// K.2 andere Person, nicht neu angemeldet, Verwaltung ohne sso.admins (W7)
$r = $ablauf($person('sub-fremd-' . $kennung, 'fremd-dort'), [], 'token:5', $planer);
pruefe($r['code'] === 'andere_person' && !Anmeldung::bestaetigt('token:5', false) && Auth::id() === $planer,
    'K.2: andere Person beim Anmeldedienst → andere_person, nicht bestätigt, angemeldet bleibt der Planer');
$r = $ablauf($person($subPlaner, 'planer-dort', [], ['auth_time' => time() - 3600]), ['prompt_ignorieren' => true], 'token:5', $planer);
pruefe($r['code'] === 'nicht_neu' && !Anmeldung::bestaetigt('token:5', false), 'K.2: Anbieter übergeht prompt=login (auth_time alt) → nicht_neu');
$abmelden();
pruefe(Auth::attempt($kennung . '-verwalter', $pw, $ip)['ok'] && !$schalter(Einstellungen::ADMINS), 'K.2: Verwalter (verknüpft) mit Kennwort, sso.admins aus');
pruefe(str_contains((string) Anmeldung::bestaetigenFehler('token:5'), 'Verwaltung per SSO'), 'K.2: Verwaltungskonto: Bestätigen über SSO nicht angeboten (W7)');
$r = $ablauf($person($subVerwalter, 'verwalter-dort'), [], 'token:5', $verwalter);
pruefe($r['code'] === 'verwaltung' && !Anmeldung::bestaetigt('token:5', false), 'K.2: … und im Rückruf abgelehnt (verwaltung)');
// Mit „Verwaltung per SSO“ und MFA-Pflicht: Bestätigen nur mit zweitem Faktor – dieselbe Schranke wie beim Anmelden (S12).
Settings::put(Einstellungen::ADMINS, '1');
Settings::put(Einstellungen::MFA, '1');
gleich(Anmeldung::bestaetigenFehler('token:5'), null, 'K.2: mit „Verwaltung per SSO“: Bestätigen über SSO angeboten');
unset($_SESSION[Anmeldung::BEIM_ANBIETER], $_SESSION[Anmeldung::HINWEIS]);
$r = $ablauf($person($subVerwalter, 'verwalter-dort', [], ['amr' => ['pwd']]), [], 'token:5', $verwalter);
pruefe($r['code'] === 'mfa' && !Anmeldung::bestaetigt('token:5', false) && !isset($_SESSION[Anmeldung::BEIM_ANBIETER]),
    'K.2: MFA-Pflicht, dort nur mit Kennwort angemeldet (amr pwd) → mfa, nicht bestätigt');
$r = $ablauf($person($subVerwalter, 'verwalter-dort', [], ['amr' => ['pwd', 'mfa']]), [], 'token:5', $verwalter);
pruefe($r['ok'] && Anmeldung::bestaetigt('token:5'), 'K.2: … mit zweitem Faktor bestätigt');
// Die Kennwort-Sitzung hat sich dabei beim Anmeldedienst angemeldet – beim Abmelden hier zählt das wie eine SSO-Sitzung.
pruefe(Auth::anmeldeweg() === 'kennwort' && ($_SESSION[Anmeldung::BEIM_ANBIETER] ?? null) === true && is_string($_SESSION[Anmeldung::HINWEIS] ?? null),
    'K.2: nach dem Bestätigen in einer Kennwort-Sitzung: „beim Anmeldedienst angemeldet“ und der Hinweis fürs Abmelden dort');
Settings::put(Einstellungen::MFA, '0');
Settings::put(Einstellungen::ADMINS, '0');
// K.3 Auth::bestaetigen mit Kennwort
pruefe(!Auth::bestaetigen('token:5', ''), 'K.3: ohne Kennwort und ohne Step-up → falsch');
pruefe(Auth::bestaetigen('token:5', $pw), 'K.3: mit richtigem Kennwort (nicht nur_sso) → wahr');
pruefe(!Auth::bestaetigen('token:5', 'falsch-' . $pw) && (int) Database::scalar('SELECT failed_logins FROM users WHERE id = ?', [$verwalter]) === 0,
    'K.3: falsches Kennwort → falsch, zählt nicht gegen das Konto');
try {
    Auth::bestaetigen('alles', $pw);
    pruefe(false, 'K.3: unbekannter Zweck → LogicException');
} catch (LogicException) {
    pruefe(true, 'K.3: unbekannter Zweck → LogicException');
}
Database::update('users', $verwalter, ['nur_sso' => 1]);
alsBenutzer($verwalter);
pruefe(!Auth::bestaetigen('token:5', $pw) && !Auth::kennwortBestaetigen(7, $pw), 'K.3: Konto mit nur_sso und richtigem Kennwort → falsch (auch beim Kundenmodus)');
Database::update('users', $verwalter, ['nur_sso' => 0]);
alsBenutzer(null);
$abmelden();
// K.4 Kundenmodus entsperren über den Anmeldedienst
pruefe($ablauf($person($subPlaner, 'planer-dort'))['ok'], 'K.4: Planer über SSO angemeldet');
$bericht = 900000 + random_int(1, 99999);
$_SESSION[Kundenmodus::SITZUNG] = ['bericht' => $bericht, 'seit' => time(), 'fehl' => 0, 'bis' => 0, 'user' => $planer, 'name' => '', 'geraet' => 0];
pruefe(Anmeldung::bestaetigenFehler('kundenmodus:' . $bericht) === null && str_contains((string) Anmeldung::bestaetigenFehler('token:5'), 'Im Kundenmodus')
    && Anmeldung::bestaetigenFehler('kundenmodus:' . ($bericht + 1)) !== null, 'K.4: im Kundenmodus nur dessen Entsperren');
gleich($ablauf(null, [], 'token:5', $planer)['code'], 'kundenmodus', 'K.4: im Kundenmodus ein anderer Zweck → kundenmodus');
$r = $ablauf($person('sub-fremd-' . $kennung, 'fremd-dort'), [], 'kundenmodus:' . $bericht, $planer);
pruefe($r['code'] === 'andere_person' && $r['ziel'] === '/berichte/' . $bericht . '/kunde' && (Kundenmodus::aktiv()['fehl'] ?? 0) === 1,
    'K.4: andere Person → bleibt gesperrt, zurück zur Kundenseite, ein Fehlversuch');
$r = $ablauf($person($subPlaner, 'planer-dort'), [], 'kundenmodus:' . $bericht, $planer);
pruefe($r['ok'] && $r['zweck'] === 'kundenmodus' && Kundenmodus::aktiv() === null && Auth::id() === $planer, 'K.4: dieselbe Identität, frisch → Kundenmodus beendet');
gleich(array_column(BerichtService::ereignisse($bericht), 'details', 'ereignis')['kundenmodus_ende'] ?? null, ['weg' => 'sso'], 'K.4: Ereignis kundenmodus_ende mit Weg sso');
$_SESSION[Kundenmodus::SITZUNG] = ['bericht' => $bericht, 'seit' => time(), 'fehl' => 0, 'bis' => 0, 'user' => $planer, 'name' => '', 'geraet' => 0];
for ($i = 0; $i < Kundenmodus::HOECHSTENS_FEHL; $i++) {
    $ablauf($person('sub-fremd-' . $kennung, 'fremd-dort'), [], 'kundenmodus:' . $bericht, $planer);
}
pruefe(Kundenmodus::wartezeit() > 0 && Kundenmodus::aktiv() !== null && Auth::id() === $planer, 'K.4: nach fünf Fehlversuchen gebremst, weiter gesperrt (nicht abgemeldet)');
pruefe(in_array('kundenmodus_gebremst', array_column(BerichtService::ereignisse($bericht), 'ereignis'), true), 'K.4: Ereignis kundenmodus_gebremst');
$n = $anfragen('token');
$fehl = (int) (Kundenmodus::aktiv()['fehl'] ?? 0);
$r = $ablauf($person($subPlaner, 'planer-dort'), [], 'kundenmodus:' . $bericht, $planer);
pruefe($r['code'] === 'kundenmodus' && $r['meldung'] === Anmeldung::T_GEBREMST && $anfragen('token') === $n && (int) (Kundenmodus::aktiv()['fehl'] ?? 0) === $fehl,
    'K.4: gebremst → abgelehnt, ohne den Code einzulösen und ohne weiteren Fehlversuch');
unset($_SESSION[Kundenmodus::SITZUNG]);
Database::execute('DELETE FROM bericht_ereignisse WHERE bericht_id = ?', [$bericht]);
$abmelden();

// ================================================================ L. Kunden über Gruppen (mit Kundenbereich)
if (!SsoKunden::verfuegbar()) {
    fwrite(STDOUT, "  Kunden: übersprungen – kein Kundenbereich\n");
} else {
    Settings::put(Einstellungen::ANLEGEN, '0');
    Settings::put(Einstellungen::ADMINS, '0');
    Settings::put(Einstellungen::NUR_SSO, '0');
    Settings::put(Einstellungen::PFLICHTGRUPPEN, '[]');
    Settings::put(Einstellungen::KUNDEN, '0');
    alsBenutzer($admin);
    $kunde1 = (int) KundenService::speichern(null, ['name' => 'Prüfung SSO L1 ' . $kennung, 'aktiv' => 1])['id'];
    $kunde2 = (int) KundenService::speichern(null, ['name' => 'Prüfung SSO L2 ' . $kennung, 'aktiv' => 1])['id'];
    $kunde3 = (int) KundenService::speichern(null, ['name' => 'Prüfung SSO L3 ' . $kennung, 'aktiv' => 0])['id'];
    $rolleKundenpflege = (int) BenutzerService::rolleSpeichern(null, $kennung . '-kundenpflege', 'Prüfung', ['kunden.view', 'kunden.manage'])['id'];
    $rolleKundenblick = (int) BenutzerService::rolleSpeichern(null, $kennung . '-kundenblick', 'Prüfung', ['kunden.view'])['id'];
    // Pflegt Kunden, sieht aber nicht alles, was ein Kunde sieht (ohne „Alle Standorte …“) – gibt also keine Zugänge aus.
    $pfleger = BenutzerService::anlegen($kennung . '-pfleger', $pw, 'kunde', false);
    $blick = BenutzerService::anlegen($kennung . '-blick', $pw, 'kunde', false);
    Database::execute('DELETE FROM user_roles WHERE user_id IN (?, ?)', [$pfleger, $blick]);
    Database::insert('user_roles', ['user_id' => $pfleger, 'role_id' => $rolleKundenpflege]);
    Database::insert('user_roles', ['user_id' => $blick, 'role_id' => $rolleKundenblick]);
    $kg1 = 'pm-kunde-1-' . $kennung;
    $kg2 = 'pm-kunde-2-' . $kennung;

    // L.0 Schalter und Kundengruppen – wer darf was
    pruefe(str_contains((string) Konten::zuordnungFehler($kg1, 'kunde', $kunde1), 'erst mit'), 'L.0: Kundengruppe bei sso.kunden aus → abgelehnt');
    alsBenutzer($blick);
    pruefe(!Einstellungen::kundenSchalten(true)['ok'] && !Einstellungen::schalter(Einstellungen::KUNDEN), 'L.0: sso.kunden ohne kunden.manage → abgelehnt');
    alsBenutzer($admin);
    pruefe(Einstellungen::kundenSchalten(true)['ok'] && Einstellungen::schalter(Einstellungen::KUNDEN), 'L.0: sso.kunden als Administrator an');
    gleich($letztesAudit('einstellungen.sso'), ['schalter' => ['kunden' => true]], 'L.0: Audit einstellungen.sso mit dem Schalter');
    alsBenutzer($blick);
    pruefe(str_contains((string) Konten::zuordnungFehler($kg1, 'kunde', $kunde1), 'Kunden pflegen'), 'L.0: Kundengruppe ohne kunden.manage → abgelehnt');
    alsBenutzer($pfleger);
    pruefe(str_contains((string) Konten::zuordnungFehler($kg1, 'kunde', $kunde1), 'gibt Zugänge aus'),
        'L.0: Kundengruppe ohne „sieht alles, was der Kunde sieht“ → abgelehnt (wie ein Zugang von Hand)');
    alsBenutzer($admin);
    pruefe(str_contains((string) Konten::zuordnungFehler($kg1, 'kunde', $kunde3), 'nicht aktiv'), 'L.0: Kundengruppe eines beendeten Kunden → abgelehnt');
    pruefe(Konten::zuordnungSpeichern($kg1, 'kunde', $kunde1)['ok'] && Konten::zuordnungSpeichern($kg2, 'kunde', $kunde2)['ok'], 'L.0: zwei Kundengruppen zugeordnet');
    pruefe(str_contains((string) Konten::zuordnungFehler($kg1, 'kunde', $kunde2), 'anderen Kunden'), 'L.0: eine Gruppe gehört zu genau einem Kunden');
    pruefe(str_contains((string) Konten::zuordnungFehler($kg1, 'rolle', $rolleA), 'entweder'), 'L.0: Kundengruppe zugleich als Rollen-Gruppe → abgelehnt');
    pruefe(str_contains((string) Konten::zuordnungFehler($gruppeA, 'kunde', $kunde1), 'entweder'), 'L.0: Rollen-Gruppe zugleich als Kundengruppe → abgelehnt');
    gleich(array_column(Konten::kundengruppen($kunde1), 'gruppe'), [$kg1], 'L.0: Kundengruppen je Kunde');
    pruefe(Einstellungen::kundenSchalten(false)['ok'], '(sso.kunden aus)');
    alsBenutzer($pfleger);
    $r = Einstellungen::kundenSchalten(true);
    pruefe(!$r['ok'] && str_contains($r['message'], 'nicht ausgeben') && !Einstellungen::schalter(Einstellungen::KUNDEN),
        'L.0: wieder an nur, wer die Zugänge aller Kunden mit Kundengruppe ausgeben dürfte');
    alsBenutzer(null);
    $abmelden();

    // L.1 sso.kunden aus: eine Kundengruppe ohne Rollen-Gruppe legt nichts an
    $subK = 'sub-kunde-' . $kennung;
    $nameK = $kennung . '-klara';
    $r = $ablauf($person($subK, $nameK, [$kg1]));
    pruefe($r['code'] === 'kein_konto' && Database::first('SELECT id FROM users WHERE username = ?', [$nameK]) === null && Auth::id() === null,
        'L.1: sso.kunden aus: Kundengruppe ohne Rollen-Gruppe → kein_konto, kein Zugang');
    $anfrageK = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', [$subK]);
    alsBenutzer($admin);
    pruefe(Einstellungen::kundenSchalten(true)['ok'], '(sso.kunden wieder an)');
    $lPersonal = BenutzerService::anlegen($kennung . '-lpersonal', $pw, 'planung', false);
    $kundenAnfrage = array_values(array_filter(Konten::anfragen(), static fn (array $a): bool => (int) $a['id'] === $anfrageK))[0] ?? [];
    pruefe(!empty($kundenAnfrage['kundengruppe']), 'L.1: die Anfrage zeigt die Kundengruppe');
    $r = Konten::anfrageFreischalten($anfrageK, $lPersonal);
    pruefe(!$r['ok'] && $r['message'] === Konten::T_ANFRAGE_KUNDE && Konten::identitaet($lPersonal) === null,
        'L.1: Anfrage mit Kundengruppe an ein Konto des Personals freischalten → abgelehnt');
    $r = Konten::anfrageAnlegen($anfrageK, ['username' => $kennung . '-lneu']);
    pruefe(!$r['ok'] && $r['message'] === Konten::T_ANFRAGE_KUNDE && Database::first('SELECT id FROM users WHERE username = ?', [$kennung . '-lneu']) === null,
        'L.1: … und kein Konto des Personals daraus');
    alsBenutzer(null);

    // L.2 sso.kunden an: eine Kundengruppe → Kundenzugang dieses Kunden
    Settings::put(Einstellungen::PFLICHTGRUPPEN, json_encode(['pm-personal-' . $kennung]));
    $r = $ablauf($person($subK, $nameK, [$kg1], ['name' => 'Klara Kunde']), [], 'anmelden', null, null, '/dashboard');
    $kz = (array) Database::first('SELECT * FROM users WHERE username = ?', [$nameK]);
    $kzId = (int) ($kz['id'] ?? 0);
    pruefe($r['ok'] && !empty($r['angelegt']) && Auth::id() === $kzId && $kzId > 0, 'L.2: Kundengruppe → Kundenzugang angelegt und angemeldet (ohne sso.anlegen, Pflichtgruppen gelten nicht)');
    gleich($r['ziel'], '/kundenbereich', 'L.2: der Kundenzugang landet im Kundenbereich, nicht auf dem Ziel aus der Adresse');
    pruefe((int) ($kz['kunde_id'] ?? 0) === $kunde1 && (int) $kz['nur_sso'] === 1 && (int) $kz['must_change_password'] === 0
        && !password_verify('', (string) $kz['password_hash']) && $kz['full_name'] === 'Klara Kunde', 'L.2: kunde_id, nur_sso, ohne Kennwortwechsel und ohne nutzbares Kennwort');
    gleich((int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$kzId]), 0, 'L.2: keine Rolle');
    gleich((int) Database::scalar("SELECT COUNT(*) FROM freigaben WHERE subject_type = 'user' AND subject_id = ?", [$kzId]), 0, 'L.2: keine Freigabe am Konto');
    gleich(Konten::identitaet($kzId)['weg'] ?? null, 'anlage', 'L.2: verknüpft, Weg anlage');
    gleich($letztesAudit('sso.konto_angelegt'), ['user_id' => $kzId, 'rollen' => [], 'kunde_id' => $kunde1], 'L.2: Audit sso.konto_angelegt mit dem Kunden');
    gleich(Auth::permissions(), [], 'L.2: Auth::permissions() = [] (angemeldet)');
    pruefe(Schutz::sitzungGilt($kz, (array) ($_SESSION['sso'] ?? []), (int) ($_SESSION['angemeldet'] ?? 0)), 'L.2: die SSO-Sitzung des Kundenzugangs gilt');
    gleich(Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', [$subK]), null, 'L.2: die Anfrage von vorhin ist weg');
    $abmelden();
    Database::insert('user_roles', ['user_id' => $kzId, 'role_id' => $rolleA, 'quelle' => 'hand']);
    alsBenutzer($kzId);
    gleich(Auth::permissions(), [], 'L.2: auch mit einer Rolle per SQL keine Rechte (alsBenutzer)');
    alsBenutzer(null);
    Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$kzId]);
    pruefe($ablauf($person($subK, $nameK . '-dort', [$kg1]))['ok'] && Auth::id() === $kzId, 'L.2: die nächste Anmeldung: derselbe Zugang');
    pruefe((int) Database::scalar('SELECT COUNT(*) FROM users WHERE kunde_id = ?', [$kunde1]) === 1
        && (string) Database::scalar('SELECT name_dort FROM sso_identitaeten WHERE user_id = ?', [$kzId]) === $nameK . '-dort'
        && (int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$kzId]) === 0, 'L.2: kein zweiter Zugang, Name dort neu, keine Rollen aus Gruppen');
    $abmelden();
    Settings::put(Einstellungen::PFLICHTGRUPPEN, '[]');
    // Die Haken im Kundenbereich: kein Einrichtungslink, kein Kennwort, Name aus SSO nicht für lokale Zugänge
    $link = Einrichtung::erzeugen($kzId);
    gleich(Einrichtung::finden($link['token']), null, 'L.2: Einrichtungslink eines Zugangs nur über SSO gilt nie');
    Database::update('users', $kzId, ['einrichtung_hash' => null, 'einrichtung_bis' => null]);
    alsBenutzer($admin);
    pruefe(KundenService::zugangLink($kunde1, $kzId)['message'] === KundenService::NUR_SSO && KundenService::zugangKennwort($kunde1, $kzId)['message'] === KundenService::NUR_SSO,
        'L.2: für einen Zugang nur über SSO weder Einrichtungslink noch vorläufiges Kennwort');
    $zeile = array_values(array_filter(KundenService::zugaenge($kunde1), static fn (array $z): bool => (int) $z['id'] === $kzId))[0] ?? [];
    pruefe(($zeile['sso']['weg'] ?? null) === 'anlage' && ($zeile['sso']['name_dort'] ?? null) === $nameK . '-dort' && ($zeile['sso']['zuletzt_am'] ?? null) !== null
        && (int) ($zeile['nur_sso'] ?? 0) === 1 && !isset($zeile['password_hash']), 'L.2: zugaenge() mit sso (Weg, Name dort, zuletzt), ohne Kennwort-Hash');
    $r = KundenService::zugangAnlegen($kunde2, ['username' => strtoupper($nameK) . '-DORT'], 'kennwort');
    pruefe(!$r['ok'] && $r['message'] === KundenService::SSO_NAME, 'L.2: lokaler Zugang mit dem Namen einer SSO-Anmeldung (Name dort, andere Schreibweise) → abgelehnt');
    pruefe(KundenService::zugangAnlegen($kunde2, ['username' => $kennung . '-lokal2'], 'kennwort')['ok'], 'L.2: ein anderer Name geht');
    alsBenutzer(null);
    // Selbst wenn jemand sein Kennwort kennte (hier per SQL gesetzt): nur über SSO.
    Database::update('users', $kzId, ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    $r = Auth::attempt($nameK, $pw, $ip);
    pruefe(!$r['ok'] && str_contains($r['message'], 'nur über') && Auth::id() === null, 'L.2: Kennwort-Anmeldung des Zugangs nur über SSO → abgelehnt');
    Database::update('users', $kzId, ['password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'failed_logins' => 0]);
    $abmelden();

    // L.3 Ablehnungen: zwei Kunden, gemischt, Kunde inaktiv, andere bzw. keine Kundengruppe
    $r = $ablauf($person($subK, $nameK, [$kg1, $kg2]));
    pruefe($r['code'] === 'kunden_mehrere' && Auth::id() === null, 'L.3: zwei Kundengruppen → kunden_mehrere');
    $r = $ablauf($person($subK, $nameK, [$kg1, $gruppeA]));
    pruefe($r['code'] === 'gemischt' && Auth::id() === null, 'L.3: Kunden- und Rollen-Gruppe → gemischt');
    $r = $ablauf($person('sub-gemischt-' . $kennung, $kennung . '-gemischt', [$kg2, $gruppeA]));
    pruefe($r['code'] === 'gemischt' && Database::first('SELECT id FROM users WHERE username = ?', [$kennung . '-gemischt']) === null,
        'L.3: neue Person mit Kunden- und Rollen-Gruppe → gemischt, kein Konto');
    $r = $ablauf($person($subPlaner, 'planer-dort', [$kg1]));
    pruefe($r['code'] === 'gemischt' && Auth::id() === null, 'L.3: Personal in einer Kundengruppe → gemischt');
    alsBenutzer($admin);
    KundenService::speichern($kunde2, ['name' => 'Prüfung SSO L2 ' . $kennung, 'aktiv' => 0]);
    alsBenutzer(null);
    \App\Kunden\Kundenbereich::vergessen();
    $r = $ablauf($person('sub-inaktiv-' . $kennung, $kennung . '-inaktiv', [$kg2]));
    pruefe($r['code'] === 'kunde_inaktiv' && Database::first('SELECT id FROM users WHERE username = ?', [$kennung . '-inaktiv']) === null,
        'L.3: Kunde nicht aktiv → kunde_inaktiv, kein Zugang');
    alsBenutzer($admin);
    KundenService::speichern($kunde2, ['name' => 'Prüfung SSO L2 ' . $kennung, 'aktiv' => 1]);
    alsBenutzer(null);
    \App\Kunden\Kundenbereich::vergessen();
    $vorherAbw = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'sso.kunde_abweichend'");
    $r = $ablauf($person($subK, $nameK, [$kg2]));
    pruefe($r['code'] === 'kunde_abweichend' && Auth::id() === null && (int) Database::scalar('SELECT kunde_id FROM users WHERE id = ?', [$kzId]) === $kunde1,
        'L.3: später nur eine andere Kundengruppe → kunde_abweichend, kunde_id unverändert');
    pruefe((int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'sso.kunde_abweichend'") === $vorherAbw + 1
        && $letztesAudit('sso.kunde_abweichend') == ['user_id' => $kzId, 'kunde_id' => $kunde1, 'gruppen_kunde_id' => $kunde2], 'L.3: Audit sso.kunde_abweichend');
    $r = $ablauf($person($subK, $nameK, []));
    pruefe($r['code'] === 'kundengruppe' && Auth::id() === null, 'L.3: die Gruppe des eigenen Kunden fehlt → kundengruppe');

    // L.4 ein lokaler Kundenzugang wird nie verknüpft – auch nicht über eine Freischaltung
    alsBenutzer($admin);
    $lokal = (int) KundenService::zugangAnlegen($kunde1, ['username' => $kennung . '-lokal'], 'kennwort')['user_id'];
    alsBenutzer(null);
    $r = $ablauf($person('sub-lokal-' . $kennung, $kennung . '-lokal', [$kg1]));
    pruefe($r['code'] === 'name' && Konten::identitaet($lokal) === null && Auth::id() === null, 'L.4: lokaler Kundenzugang gleichen Namens → name, nicht verknüpft');
    $anfrageL = (int) Database::scalar('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['sub-lokal-' . $kennung]);
    alsBenutzer($admin);
    $r = Konten::anfrageFreischalten($anfrageL, $lokal);
    pruefe(!$r['ok'] && Konten::identitaet($lokal) === null, 'L.4: … auch nicht über die Freischaltung');
    alsBenutzer(null);
    $lokalKonto = (array) BenutzerService::find($lokal);
    gleich(Schutz::darfPerSso($lokalKonto), 'kunde_lokal', 'L.4: darfPerSso: lokaler Kundenzugang → kunde_lokal');
    gleich($code(static fn () => Schutz::pruefkette($lokalKonto, ['sub' => 'x'], [$kg1], KundenService::find($kunde1), [])), 'kunde_lokal',
        'L.4: Prüfkette: lokaler Kundenzugang → kunde_lokal');
    gleich($code(static fn () => Konten::verknuepfen($lokal, $iss, 'sub-lokal-x-' . $kennung, 'profil', '', [])), 'kunde_lokal', 'L.4: verknuepfen (Profil) → kunde_lokal');

    // L.5 sso.kunden aus und Kundengruppe entfernen: die SSO-Sitzungen der Kundenzugänge enden
    $kzSitzung = static function () use ($kzId): array {
        return [(array) BenutzerService::find($kzId), (array) ($_SESSION['sso'] ?? []), (int) ($_SESSION['angemeldet'] ?? 0)];
    };
    pruefe($ablauf($person($subK, $nameK, [$kg1]))['ok'] && Schutz::sitzungGilt(...$kzSitzung()), 'L.5: Kundenzugang über SSO angemeldet');
    alsBenutzer($admin);
    Einstellungen::kundenSchalten(false);
    alsBenutzer(null);
    pruefe(!Schutz::sitzungGilt(...$kzSitzung()), 'L.5: sso.kunden aus → Sitzung zu Ende (I.2 mit Kundenbereich)');
    Auth::vergessen();
    pruefe(Auth::user() === null, 'L.5: … Auth::user() beendet sie');
    $abmelden();
    $r = $ablauf($person($subK, $nameK, [$kg1]));
    pruefe($r['code'] === 'kunden_aus' && $r['meldung'] === Schutz::T_KUNDEN_AUS && Auth::id() === null, 'L.5: sso.kunden aus → Anmeldung des Zugangs aus SSO abgelehnt (kunden_aus)');
    alsBenutzer($admin);
    Einstellungen::kundenSchalten(true);
    alsBenutzer(null);
    pruefe($ablauf($person($subK, $nameK, [$kg1]))['ok'] && Schutz::sitzungGilt(...$kzSitzung()), 'L.5: wieder an → angemeldet');
    alsBenutzer($admin);
    $zKg1 = (int) Database::scalar("SELECT id FROM sso_zuordnungen WHERE gruppe = ? AND art = 'kunde'", [$kg1]);
    $r = Konten::zuordnungLoeschen($zKg1);
    alsBenutzer(null);
    pruefe($r['ok'] && ($r['art'] ?? '') === 'kunde' && ($r['ziel_id'] ?? 0) === $kunde1 && !Schutz::sitzungGilt(...$kzSitzung()),
        'L.5: Kundengruppe entfernt → die SSO-Sitzung des Kundenzugangs endet');
    gleich($letztesAudit('sso.zuordnung')['sso_sitzungen'] ?? null, 1, 'L.5: Audit sso.zuordnung nennt die beendeten Sitzungen');
    $abmelden();
    alsBenutzer($admin);
    pruefe(Konten::zuordnungSpeichern($kg1, 'kunde', $kunde1)['ok'], '(Kundengruppe wieder da)');
    alsBenutzer(null);
    pruefe($ablauf($person($subK, $nameK, [$kg1]))['ok'], 'L.5: danach meldet sich der Zugang wieder an');
    $abmelden();
    alsBenutzer($admin);
    pruefe(KundenService::zugangSsoSitzungen($kunde1, $kzId)['ok'] && !KundenService::zugangSsoSitzungen($kunde1, $lokal)['ok']
        && !KundenService::zugangSsoSitzungen($kunde2, $kzId)['ok'], 'L.5: Sitzungen über SSO beenden (Kundenseite) – nur für Zugänge aus SSO dieses Kunden');
    alsBenutzer(null);

    // L.6 Kunde löschen nimmt Kundengruppen und Zugänge (mit Verknüpfung) mit
    alsBenutzer($admin);
    KundenService::speichern($kunde1, ['name' => 'Prüfung SSO L1 ' . $kennung, 'aktiv' => 0]);
    $r = KundenService::loeschen($kunde1, 'Prüfung SSO L1 ' . $kennung);
    alsBenutzer(null);
    pruefe($r['ok'] && Konten::kundengruppen($kunde1) === [] && Database::first('SELECT id FROM sso_identitaeten WHERE user_id = ?', [$kzId]) === null
        && BenutzerService::find($kzId) === null, 'L.6: Kunde gelöscht → Kundengruppen, Zugänge und ihre Verknüpfungen weg');
    pruefe(($letztesAudit('kunden.loeschen')['sso_gruppen'] ?? 0) === 1, 'L.6: Audit kunden.loeschen nennt die Kundengruppen');
    Settings::put(Einstellungen::KUNDEN, '0');
    $abmelden();
}

// ================================================================ M. Geheimnisse im Ablauf
$r = $ablauf($person($subPlaner, 'planer-dort'), ['kennung' => $kennung]);
$ssoCode = (string) ($r['_zurueck']['code'] ?? '');
$hinweis = $_SESSION[Anmeldung::HINWEIS] ?? null;
pruefe($r['ok'] && str_contains($ssoCode, $kennung) && is_string($hinweis) && substr_count($hinweis, '.') === 2,
    'M: angemeldet mit gekennzeichnetem Code, das ID-Token als Hinweis fürs Abmelden');
$ohneHinweis = $_SESSION;
unset($ohneHinweis[Anmeldung::HINWEIS]);
$sitzungText = (string) json_encode($ohneHinweis);
$idSignatur = is_string($hinweis) ? (string) (explode('.', $hinweis)[2] ?? '') : '';
pruefe($idSignatur !== '' && !str_contains($sitzungText, $ssoCode) && !str_contains($sitzungText, 'at-' . $kennung) && !str_contains($sitzungText, $idSignatur)
    && !str_contains($sitzungText, $client['secret']) && !str_contains($sitzungText, 'verifier'),
    'M.3: Sitzung ohne Code, Access-Token, Verifier und Secret; das ID-Token nur unter sso_hinweis');
$abmelden();
$idp->steuern(['kennung' => $kennung]);
$url = Anmeldung::starten('anmelden', null, false);
$a = $idp->bearbeiten('GET', '/application/o/authorize/', $abfrage($url), [], []);
$zurueck = $abfrage((string) $a['kopf']['Location']);
$idp->zuruecksetzen();
Http::$attrappe = static function (string $m, string $u, #[\SensitiveParameter] array $o): array {
    throw new RuntimeException('Attrappe kaputt');
};
$r = Anmeldung::rueckruf($zurueck, $ip);
Http::$attrappe = $idp->http();
pruefe($r['code'] === 'intern' && $r['meldung'] === Anmeldung::T_INTERN && Auth::id() === null, 'M.4: Ausnahme beim Einlösen → intern, nicht angemeldet');
$abmelden();
$geheimAblauf = [$ssoCode, (string) ($zurueck['code'] ?? '-'), 'at-' . $kennung, $idSignatur];

// ================================================================ N. Kommandozeile (Kindprozesse in der Instanz)
// Die Kindprozesse erreichen die Attrappe wie dieser Prozess über
// Http::$attrappe – gesetzt per auto_prepend_file, ihr Zustand liegt in ihrem
// Ordner. Alles andere (Datenbank, Einstellungen) ist die der Instanz.
$abmelden();
alsBenutzer(null);
$idp->zuruecksetzen();
$umleitung = $instanzOrdner . '/oidc-umleitung.php';
file_put_contents($umleitung, "<?php\n// tests/sso.php: Anfragen dieses Kindprozesses gehen an die OIDC-Attrappe.\n"
    . 'require ' . var_export(APP_ROOT . '/src/Messung/Http.php', true) . ";\n"
    . 'require ' . var_export(APP_ROOT . '/tests/hilfen/oidc-attrappe.php', true) . ";\n"
    . '\App\Messung\Http::$attrappe = OidcAttrappe::laden(' . var_export($instanzOrdner . '/oidc', true) . ")->http();\n");
/** bin/power in der Instanz: Ausgabe (stdout und stderr) und Rückgabewert; $eingabe kommt auf die Standardeingabe. */
$cli = static function (array $argumente, string $eingabe = '', array $umgebung = []) use ($umleitung): array {
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $umleitung, APP_ROOT . '/bin/power'], $argumente),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, APP_ROOT, $umgebung + getenv());
    if (!is_resource($p)) {
        return ['rc' => -1, 'aus' => ''];
    }
    fwrite($rohre[0], $eingabe);
    fclose($rohre[0]);
    $aus = (string) stream_get_contents($rohre[1]) . (string) stream_get_contents($rohre[2]);
    $rc = proc_close($p);
    Settings::vergessen();
    Zugriff::vergessen();
    Auth::rechteVergessen();
    return ['rc' => $rc, 'aus' => $aus];
};
$ohneSecret = static fn (string $text): bool => !str_contains($text, $client['secret']) && !str_contains($text, rawurlencode($client['secret']))
    && !str_contains($text, (string) Settings::get(Settings::GEHEIM_SSO_SECRET, '-'));
pruefe(Einstellungen::aktiv(), 'N: SSO an');

// N.1 sso:zustand – ohne Secret
$r = $cli(['sso:zustand']);
pruefe($r['rc'] === 0 && str_contains($r['aus'], 'Aussteller:      ' . $iss) && str_contains($r['aus'], 'Client-ID:       ' . $client['id'])
    && str_contains($r['aus'], 'hinterlegt (verschlüsselt') && str_contains($r['aus'], 'RS256') && str_contains($r['aus'], 'rsa-1')
    && str_contains($r['aus'], 'Notzugänge:') && str_contains($r['aus'], 'Verknüpfungen:') && str_contains($r['aus'], 'Anfragen:'),
    'N.1: sso:zustand nennt Aussteller, Client-ID, Secret hinterlegt, Algorithmen, Schlüssel, Verknüpfungen, Anfragen und Notzugänge');
pruefe($ohneSecret($r['aus']), 'N.1: sso:zustand ohne Secret (weder Klartext noch verschlüsselt)');
pruefe(preg_match('/^Notzugänge:\s+.*' . preg_quote($kennung . '-admin', '/') . '/mu', $r['aus']) === 1, 'N.1: der Notzugang mit Namen');

// N.2 sso:pruefen – Exit 0, mit falschem Secret Exit 1
$r = $cli(['sso:pruefen']);
pruefe($r['rc'] === 0 && str_contains($r['aus'], '✓ Client-Zugang angenommen') && str_contains($r['aus'], 'Verbindung in Ordnung') && $ohneSecret($r['aus']),
    'N.2: sso:pruefen → Befunde zeilenweise, Exit 0, ohne Secret');

// N.3 sso:einrichten – das Secret nur von der Standardeingabe
$r = $cli(['sso:einrichten']);
pruefe($r['rc'] === 1 && str_contains($r['aus'], 'Aufruf:'), 'N.3: sso:einrichten ohne Angaben → Aufruf');
$r = $cli(['sso:einrichten', $iss, $client['id'], 'ein-secret-als-argument-' . $kennung]);
pruefe($r['rc'] === 1 && str_contains($r['aus'], 'nie als Argument') && Einstellungen::secret() === $client['secret'],
    'N.3: ein Secret als Argument wird abgelehnt, nichts geändert');
$falsch = 'falsch-' . $kennung . '-' . bin2hex(random_bytes(8));
$marke = Einstellungen::marke();
$r = $cli(['sso:einrichten', $iss, $client['id']], $falsch . "\n");
pruefe($r['rc'] === 0 && Einstellungen::secret() === $falsch && Einstellungen::marke() !== $marke && !str_contains($r['aus'], $falsch)
    && str_contains($r['aus'], 'Redirect URI in Authentik (Strict): https://pm.example.test/login/sso/rueckruf'),
    'N.3: sso:einrichten über die Standardeingabe – Secret gesetzt, Marke neu, nicht in der Ausgabe, Rückruf-Adresse genannt');
gleich($letztesAudit('einstellungen.sso'), ['aussteller' => $iss, 'client_id' => $client['id'], 'secret' => 'gesetzt'], 'N.3: Audit ohne Secret');
pruefe((string) Database::scalar("SELECT username FROM audit_log WHERE action = 'einstellungen.sso' ORDER BY id DESC LIMIT 1") === 'cli', 'N.3: Akteur im Audit: cli');
$r = $cli(['sso:pruefen']);
pruefe($r['rc'] === 1 && str_contains($r['aus'], '✗ Client-ID oder Secret falsch') && !str_contains($r['aus'], $falsch), 'N.2: mit falschem Secret → ✗, Exit 1');
$r = $cli(['sso:einrichten', $iss, $client['id'], '--name=Authentik'], '');
pruefe($r['rc'] === 0 && Einstellungen::secret() === $falsch, 'N.3: leere Eingabe lässt das Secret, wie es ist');
$r = $cli(['sso:einrichten', $iss, $client['id']], $client['secret'] . "\r\n");
pruefe($r['rc'] === 0 && Einstellungen::secret() === $client['secret'] && $ohneSecret($r['aus']), 'N.3: das Secret mit Sonderzeichen über die Standardeingabe');

// N.4 sso:aus / sso:an
$r = $cli(['sso:aus']);
pruefe($r['rc'] === 0 && !Einstellungen::aktiv(), 'N.4: sso:aus → aus');
$r = $cli(['sso:an']);
pruefe($r['rc'] === 0 && Einstellungen::aktiv() && str_contains($r['aus'], '✓ Client-Zugang angenommen') && str_contains($r['aus'], 'eingeschaltet'),
    'N.4: sso:an → frisch geprüft und an');
Settings::put(Einstellungen::GEPRUEFT_AM, '');
$idp->steuern(['discovery_404' => true]);
$r = $cli(['sso:aus']);
$r = $cli(['sso:an']);
$idp->zuruecksetzen();
pruefe($r['rc'] === 1 && !Einstellungen::aktiv() && str_contains($r['aus'], 'Discovery nicht gefunden'), 'N.4: sso:an bei fehlschlagender Prüfung → bleibt aus, Exit 1');
pruefe($cli(['sso:an'])['rc'] === 0 && Einstellungen::aktiv(), 'N.4: wieder an');

// N.5 sso:verknuepfungen – Subjekt nur gekürzt
$r = $cli(['sso:verknuepfungen', $kennung . '-planer']);
pruefe($r['rc'] === 0 && str_contains($r['aus'], $kennung . '-planer') && str_contains($r['aus'], 'planer-dort') && str_contains($r['aus'], 'cli')
    && str_contains($r['aus'], substr($subPlaner, 0, 8) . '…') && !str_contains($r['aus'], $subPlaner),
    'N.5: sso:verknuepfungen <benutzer> → Weg, Name dort, Subjekt gekürzt');
$r = $cli(['sso:verknuepfungen']);
pruefe($r['rc'] === 0 && str_contains($r['aus'], $kennung . '-planer') && str_contains($r['aus'], $kennung . '-chef'), 'N.5: ohne Benutzer alle');

// N.6 sso:aussteller-umstellen – derselbe Anmeldedienst unter neuem Issuer
$alt = 'https://idp.example.test/application/o/vorher/';
Database::execute('UPDATE sso_identitaeten SET aussteller = ?, schluessel = ? WHERE user_id = ?', [$alt, Konten::schluessel($alt, $subPlaner), $planer]);
$r = $ablauf($person($subPlaner, 'planer-dort'));
pruefe(!$r['ok'] && Auth::id() !== $planer, 'N.6: Identität bei einem anderen Aussteller → die Anmeldung findet das Konto nicht');
$abmelden();
pruefe(str_contains($cli(['sso:zustand'])['aus'], 'bei einem anderen Aussteller – bin/power sso:aussteller-umstellen'), 'N.6: sso:zustand nennt die fremden Verknüpfungen');
$r = $cli(['sso:aussteller-umstellen', $iss]);
pruefe($r['rc'] === 1 && str_contains($r['aus'], 'DERSELBEN') && str_contains($r['aus'], '1 Verknüpfung(en) bei ' . $alt)
    && (string) Database::scalar('SELECT aussteller FROM sso_identitaeten WHERE user_id = ?', [$planer]) === $alt,
    'N.6: ohne --ja nur die Übersicht und die Warnung, nichts umgestellt');
// Ein Zusammenstoß: dasselbe Subjekt gehört beim neuen Aussteller schon einem anderen Konto.
$zweiter = BenutzerService::anlegen($kennung . '-zweiter', $pw, 'planung', false);
Konten::verknuepfen($zweiter, $iss, $subPlaner, 'cli', 'zweiter-dort', []);
$r = $cli(['sso:aussteller-umstellen', $iss, '--ja']);
pruefe($r['rc'] === 1 && str_contains($r['aus'], 'zusammen') && (string) Database::scalar('SELECT aussteller FROM sso_identitaeten WHERE user_id = ?', [$planer]) === $alt,
    'N.6: Zusammenstoß mit einer Verknüpfung beim neuen Aussteller → nichts umgestellt');
Konten::loesen($zweiter);
$r = $cli(['sso:aussteller-umstellen', $iss, '--ja']);
$i = Database::first('SELECT * FROM sso_identitaeten WHERE user_id = ?', [$planer]);
pruefe($r['rc'] === 0 && str_contains($r['aus'], '1 Verknüpfung(en) auf ' . $iss . ' umgestellt') && $i !== null && $i['aussteller'] === $iss
    && $i['schluessel'] === Konten::schluessel($iss, $subPlaner), 'N.6: --ja → Aussteller und schluessel umgeschrieben');
pruefe(($letztesAudit('sso.aussteller_umgestellt')['anzahl'] ?? null) === 1, 'N.6: Audit sso.aussteller_umgestellt');
$r = $ablauf($person($subPlaner, 'planer-dort'));
pruefe($r['ok'] && Auth::id() === $planer, 'N.6: danach meldet sich das Konto wieder an');
pruefe(Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', [$subPlaner]) === null, 'N.6: die Anfrage des gescheiterten Versuchs ist mit der Anmeldung weg');
$abmelden();
$r = $cli(['sso:aussteller-umstellen', $iss, '--ja']);
pruefe($r['rc'] === 0 && str_contains($r['aus'], 'nichts zu tun'), 'N.6: ein zweites Mal: nichts zu tun');

// N.7 user:password bei „nur SSO“
$nurSsoKonto = BenutzerService::anlegen($kennung . '-nursso', $pw, 'planung', false);
Database::execute('UPDATE users SET nur_sso = 1 WHERE id = ?', [$nurSsoKonto]);
$r = $cli(['user:password', $kennung . '-nursso', 'Neu-' . $pw]);
pruefe($r['rc'] === 0 && str_contains($r['aus'], 'Passwort gesetzt.') && str_contains($r['aus'], 'Das Kennwort gilt erst ohne „nur SSO“ am Konto'),
    'N.7: user:password für ein Konto „nur über SSO“ → Hinweis');
Settings::put(Einstellungen::NUR_SSO, '1');
$r = $cli(['user:password', $kennung . '-planer', $pw]);
Settings::put(Einstellungen::NUR_SSO, '0');
pruefe($r['rc'] === 0 && str_contains($r['aus'], 'sso:aus --nur-sso'), 'N.7: user:password für ein verknüpftes Konto bei „nur SSO“ → Hinweis auf den Notausgang');
$r = $cli(['user:password', $kennung . '-admin', $pw]);
pruefe($r['rc'] === 0 && !str_contains($r['aus'], 'Hinweis'), 'N.7: user:password sonst ohne Hinweis');

// N.8 pruefen – mit SSO eine eigene Zeile (Demo-Modus: ohne InfluxDB/MQTT)
$demoConfig = $instanzOrdner . '/config-demo.php';
file_put_contents($demoConfig, "<?php\n\$c = require " . var_export((string) getenv('POWERMANAGEMENT_CONFIG'), true) . ";\n\$c['app']['demo'] = true;\nreturn \$c;\n");
$r = $cli(['pruefen'], '', ['POWERMANAGEMENT_CONFIG' => $demoConfig]);
pruefe($r['rc'] === 0 && preg_match('/^SSO:\s+OK – ' . preg_quote($iss, '/') . ', \d+ Schlüssel, Client-Zugang angenommen$/mu', $r['aus']) === 1,
    'N.8: bin/power pruefen → „SSO: OK – <Issuer>, n Schlüssel, Client-Zugang angenommen“ (' . trim($r['aus']) . ')');
$idp->steuern(['token_fehler' => 'invalid_client']);
$r = $cli(['pruefen'], '', ['POWERMANAGEMENT_CONFIG' => $demoConfig]);
$idp->zuruecksetzen();
pruefe($r['rc'] === 1 && preg_match('/^SSO:\s+FEHLER – /mu', $r['aus']) === 1, 'N.8: bei einem Fehler „SSO: FEHLER – …“ und Exit 1');
$cli(['sso:pruefen']);
$r = $cli(['sso:aus']);
$r = $cli(['pruefen'], '', ['POWERMANAGEMENT_CONFIG' => $demoConfig]);
pruefe($r['rc'] === 0 && !str_contains($r['aus'], 'SSO:'), 'N.8: ohne SSO keine Zeile');
pruefe($cli(['sso:an'])['rc'] === 0, 'N.8: wieder an');

// N.9 sso:loesen
$r = $cli(['sso:loesen', $kennung . '-planer']);
pruefe($r['rc'] === 1 && str_contains($r['aus'], '--ja') && Konten::identitaet($planer) !== null, 'N.9: sso:loesen ohne --ja → nichts gelöst');
$r = $cli(['sso:loesen', $kennung . '-planer', '--ja']);
pruefe($r['rc'] === 0 && Konten::identitaet($planer) === null && ($letztesAudit('sso.geloest')['user_id'] ?? null) === $planer, 'N.9: sso:loesen --ja → gelöst, Audit');
$r = $cli(['sso:loesen', $kennung . '-planer', '--ja']);
pruefe($r['rc'] === 1 && str_contains($r['aus'], 'mit keiner SSO-Anmeldung verknüpft'), 'N.9: ein zweites Mal → nicht verknüpft');
Konten::verknuepfen($planer, $iss, $subPlaner, 'cli', 'planer-dort', []);

// N.10 Wartung: abgelaufene Rollen aus Gruppen, alte Anfragen, inaktive Konten „nur über SSO“.
// Im Prozess: bin/power wartung räumte in einem Kindprozess auch storage/ der
// Arbeitskopie gegen die (leere) Datenbank der Instanz auf.
$jetzt = time();
Database::execute("DELETE FROM user_roles WHERE quelle = 'sso'");
Database::insert('user_roles', ['user_id' => $planer, 'role_id' => $rolleA, 'gueltig_ab' => null, 'gueltig_bis' => Zeit::iso($jetzt - 2 * 86400), 'quelle' => 'sso']);
Database::insert('user_roles', ['user_id' => $verwalter, 'role_id' => $rolleA, 'gueltig_ab' => null, 'gueltig_bis' => Zeit::iso($jetzt - 3600), 'quelle' => 'sso']);
Database::insert('user_roles', ['user_id' => $zweiter, 'role_id' => $rolleA, 'gueltig_ab' => null, 'gueltig_bis' => Zeit::iso($jetzt - 2 * 86400), 'quelle' => 'hand']);
Database::execute('DELETE FROM sso_anfragen');
$uhr = $jetzt - (Konten::ANFRAGE_TAGE + 1) * 86400;
Konten::anfrageMerken(['sub' => 'alt-' . $kennung], [], 'kein_konto');
$uhr = $jetzt - (Konten::ANFRAGE_TAGE - 1) * 86400;
Konten::anfrageMerken(['sub' => 'neu-' . $kennung], [], 'kein_konto');
$uhr = $jetzt;
$vor40 = Zeit::iso($jetzt - 40 * 86400);
$inaktiv = BenutzerService::anlegen($kennung . '-inaktiv', $pw, 'planung', false);
$frisch = BenutzerService::anlegen($kennung . '-frisch', $pw, 'planung', false);
$lokal = BenutzerService::anlegen($kennung . '-lokal', $pw, 'planung', false);
Database::execute('UPDATE users SET nur_sso = 1, created_at = ? WHERE id IN (?, ?)', [$vor40, $inaktiv, $frisch]);
Database::execute('UPDATE users SET created_at = ? WHERE id = ?', [$vor40, $lokal]);
Konten::verknuepfen($frisch, $iss, 'frisch-' . $kennung, 'cli', 'frisch-dort', []);
Database::execute('UPDATE sso_identitaeten SET zuletzt_am = ? WHERE user_id = ?', [Zeit::iso($jetzt - 5 * 86400), $frisch]);
Konten::verknuepfen($inaktiv, $iss, 'inaktiv-' . $kennung, 'cli', 'inaktiv-dort', []);
Database::execute('UPDATE sso_identitaeten SET zuletzt_am = ? WHERE user_id = ?', [Zeit::iso($jetzt - 31 * 86400), $inaktiv]);
Settings::put(Einstellungen::INAKTIV_TAGE, '0');
$m = Konten::wartung($jetzt);
$aktiv = static fn (int $id): bool => (int) Database::scalar('SELECT is_active FROM users WHERE id = ?', [$id]) === 1;
$rollenDa = static fn (int $id, string $quelle): bool => Database::first('SELECT id FROM user_roles WHERE user_id = ? AND role_id = ? AND quelle = ?', [$id, $rolleA, $quelle]) !== null;
pruefe(!$rollenDa($planer, 'sso') && $rollenDa($verwalter, 'sso') && $rollenDa($zweiter, 'hand'),
    'N.10: wartung löscht Rollen aus Gruppen, die seit über einem Tag abgelaufen sind – die seit einer Stunde und Rollen von Hand bleiben');
pruefe(Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['alt-' . $kennung]) === null
    && Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['neu-' . $kennung]) !== null, 'N.10: Anfragen älter als 30 Tage weg, jüngere bleiben');
pruefe($aktiv($inaktiv) && $aktiv($frisch) && $aktiv($lokal), 'N.10: ohne sso.inaktiv_tage wird niemand deaktiviert');
pruefe(count($m) === 1 && str_contains($m[0], '1 abgelaufene Rolle(n)') && str_contains($m[0], '1 Anmeldeanfrage(n)'), 'N.10: Meldung (' . implode(' ', $m) . ')');
Settings::put(Einstellungen::INAKTIV_TAGE, '30');
$m = Konten::wartung($jetzt);
pruefe(!$aktiv($inaktiv) && $aktiv($frisch) && $aktiv($lokal), 'N.10: sso.inaktiv_tage 30 → das Konto „nur über SSO“ ohne Anmeldung seit 31 Tagen ist deaktiviert, die anderen nicht');
pruefe(($letztesAudit('sso.konto_deaktiviert') === ['user_id' => $inaktiv, 'tage' => 30]) && str_contains(implode(' ', $m), $kennung . '-inaktiv'),
    'N.10: Audit sso.konto_deaktiviert {user_id, tage} und der Name in der Meldung');
Database::execute('UPDATE users SET is_active = 1 WHERE id = ?', [$inaktiv]);
Database::execute('DELETE FROM sso_identitaeten WHERE user_id = ?', [$inaktiv]);
Database::execute('UPDATE users SET created_at = ? WHERE id = ?', [Zeit::iso($jetzt - 10 * 86400), $inaktiv]);
Konten::wartung($jetzt);
pruefe($aktiv($inaktiv), 'N.10: ohne Identität zählt das Anlegen (vor 10 Tagen) – bleibt aktiv');
Database::execute('UPDATE users SET created_at = ? WHERE id = ?', [$vor40, $inaktiv]);
Konten::wartung($jetzt);
pruefe(!$aktiv($inaktiv), 'N.10: ohne Identität, angelegt vor 40 Tagen → deaktiviert');
Settings::put(Einstellungen::INAKTIV_TAGE, '0');
pruefe(Konten::wartung($jetzt) === [], 'N.10: nichts zu tun → keine Meldung');
$quelle = (string) file_get_contents(APP_ROOT . '/bin/power');
$wartung = (string) substr($quelle, (int) strpos($quelle, "case 'wartung':"));
pruefe(str_contains(substr($wartung, 0, (int) strpos($wartung, 'break;' . "\n\n    default:")), 'SsoKonten::wartung()'), 'N.10: bin/power wartung ruft Konten::wartung()');

// N.11 Netzweg: verbinden_ueber und ca_datei (config.php → Einstellungen::verbindung → Http)
Config::set('sso.verbinden_ueber', 'kaputt');
pruefe(Einstellungen::verbindung() === [] && str_contains(implode(' ', Einstellungen::verbindungFehler()), 'host:port:ziel:zielport'), 'N.11: verbinden_ueber kaputt → nicht benutzt, Fehler genannt');
$p = Anbieter::pruefen();
pruefe(!$p['ok'] && str_contains((string) json_encode($p['befunde'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'sso.verbinden_ueber in config/config.php'), 'N.11: „Prüfen“ scheitert daran');
Config::set('sso.verbinden_ueber', 'andere.example.test:443:10.20.0.20:443');
pruefe(str_contains(implode(' ', Einstellungen::verbindungFehler()), 'idp.example.test:443'), 'N.11: verbinden_ueber für einen anderen Host als den Aussteller → Fehler');
Config::set('sso.verbinden_ueber', 'IDP.example.test:443:10.20.0.20:443');
$gesehen = [];
Http::$attrappe = static function (string $m, string $u, #[\SensitiveParameter] array $o) use ($idp, &$gesehen): array {
    $gesehen[] = $o['verbinden_ueber'] ?? '-';
    return ($idp->http())($m, $u, $o);
};
$p = Anbieter::pruefen();
pruefe($p['ok'] && Einstellungen::verbindungFehler() === [] && $gesehen !== [] && array_unique($gesehen) === ['IDP.example.test:443:10.20.0.20:443']
    && str_contains((string) json_encode($p['befunde'], JSON_UNESCAPED_UNICODE), 'Verbindung über 10.20.0.20:443'),
    'N.11: gültiges verbinden_ueber geht mit jeder Anfrage an den Anmeldedienst mit, „Prüfen“ nennt es');
Http::$attrappe = $idp->http();
Config::set('sso.verbinden_ueber', '');
foreach (['ca.pem' => 'braucht einen absoluten Pfad', $instanzOrdner . '/fehlt.pem' => 'nicht lesbar', $umleitung => 'kein Zertifikat'] as $pfad => $erwartet) {
    Config::set('sso.ca_datei', $pfad);
    pruefe(str_contains(implode(' ', Einstellungen::verbindungFehler()), $erwartet) && Einstellungen::verbindung() === [], 'N.11: ca_datei ' . basename($pfad) . ' → ' . $erwartet);
}
Config::set('sso.ca_datei', '');

$netz = $instanzOrdner . '/netz';
@mkdir($netz, 0700);
$name = 'idp-netz.example.test';
$openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
$erzeugt = false;
if ($openssl !== '') {
    exec(escapeshellarg($openssl) . ' req -x509 -newkey rsa:2048 -nodes -days 1 -subj ' . escapeshellarg('/CN=' . $name)
        . ' -addext ' . escapeshellarg('subjectAltName=DNS:' . $name) . ' -keyout ' . escapeshellarg($netz . '/schluessel.pem')
        . ' -out ' . escapeshellarg($netz . '/zertifikat.pem') . ' 2>/dev/null', $_aus, $rc);
    $erzeugt = $rc === 0 && is_file($netz . '/zertifikat.pem');
}
if (!$erzeugt || !function_exists('curl_init')) {
    fwrite(STDOUT, "  N.11: echter Netzweg übersprungen – openssl oder php-curl fehlt\n");
} else {
    $port = freierPort();
    hintergrund([$openssl, 's_server', '-accept', '127.0.0.1:' . $port, '-cert', $netz . '/zertifikat.pem', '-key', $netz . '/schluessel.pem', '-www', '-quiet']);
    for ($i = 0; $i < 50 && !erreichbar('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    // Ein Proxy aus der Umgebung (curl liest https_proxy) gehört nicht in diese Prüfung.
    foreach (['no_proxy', 'NO_PROXY'] as $v) {
        putenv($v . '=' . $name . ',falsch.example.test,127.0.0.1');
    }
    Http::$attrappe = null;
    $url = 'https://' . $name . ':' . $port . '/';
    $ueber = $name . ':' . $port . ':127.0.0.1:' . $port;
    $ca = $netz . '/zertifikat.pem';
    $a = Http::anfrage('GET', $url, ['zeit' => 5]);
    pruefe(!$a['ok'], 'N.11: ohne verbinden_ueber ist ' . $name . ' nicht erreichbar (' . $a['fehler'] . ')');
    $a = Http::anfrage('GET', $url, ['zeit' => 5, 'verbinden_ueber' => $ueber, 'ca_datei' => $ca]);
    pruefe($a['ok'] && $a['status'] === 200, 'N.11: verbinden_ueber + ca_datei → über 127.0.0.1 verbunden, Zertifikat für ' . $name . ' geprüft (' . $a['fehler'] . ')');
    $a = Http::anfrage('GET', $url, ['zeit' => 5, 'verbinden_ueber' => $ueber]);
    pruefe(!$a['ok'] && $a['nummer'] === 60, 'N.11: ohne die eigene CA scheitert die Zertifikatsprüfung (curl 60: ' . $a['fehler'] . ')');
    $a = Http::anfrage('GET', 'https://falsch.example.test:' . $port . '/', ['zeit' => 5, 'verbinden_ueber' => 'falsch.example.test:' . $port . ':127.0.0.1:' . $port, 'ca_datei' => $ca]);
    pruefe(!$a['ok'] && in_array($a['nummer'], [51, 60], true), 'N.11: anderer Name auf demselben Ziel → Name passt nicht zum Zertifikat (curl ' . $a['nummer'] . ')');
    Http::$ohneCurl = true;
    $a = Http::anfrage('GET', $url, ['zeit' => 5, 'verbinden_ueber' => $ueber]);
    $b = Http::anfrage('GET', $url, ['zeit' => 5, 'ca_datei' => $ca]);
    Http::$ohneCurl = false;
    pruefe(!$a['ok'] && !$b['ok'] && $a['fehler'] === 'Für diese Einstellung ist php-curl nötig.' && $b['fehler'] === $a['fehler'],
        'N.11: über PHP-Streams → „php-curl nötig“ statt still anders zu verbinden');
    $a = Http::anfrage('GET', $url, ['zeit' => 5, 'verbinden_ueber' => $ueber . "\r\nX: y", 'ca_datei' => $ca]);
    pruefe(!$a['ok'] && str_contains($a['fehler'], 'verbinden_ueber'), 'N.11: verbinden_ueber mit Zeilenumbruch → abgelehnt');
    Config::set('sso.ca_datei', $ca);
    pruefe(Einstellungen::verbindungFehler() === [] && (Einstellungen::verbindung()['ca_datei'] ?? '') === $ca, 'N.11: ca_datei mit Zertifikat taugt');
    Config::set('sso.ca_datei', '');
    Http::$attrappe = $idp->http();
}

// N.12 Demo: eingetragen, aber aus – und nie über eine echte Einrichtung
$demoSso = new ReflectionMethod(App\Services\DemoSeed::class, 'sso');
$demoReset = new ReflectionMethod(App\Services\DemoSeed::class, 'ssoZuruecksetzen');
$demoIss = App\Services\DemoSeed::SSO_AUSSTELLER;
$zaehlen = static fn (): array => [(int) Database::scalar('SELECT COUNT(*) FROM sso_identitaeten'), (int) Database::scalar('SELECT COUNT(*) FROM sso_anfragen'),
    (int) Database::scalar("SELECT COUNT(*) FROM sso_zuordnungen WHERE gruppe LIKE 'pm-%'")];
$vorher = $zaehlen();
pruefe($demoSso->invoke(null, $planer) === false && $zaehlen() === $vorher && Einstellungen::aussteller() === $iss,
    'N.12: Demo über einer echten Einrichtung → nichts angelegt, Aussteller bleibt');
$demoReset->invoke(null);
pruefe($zaehlen() === $vorher, 'N.12: Demo-Reset über einer echten Einrichtung → nichts gelöscht');
$gesichert = [Einstellungen::AUSSTELLER => $iss, Einstellungen::CLIENT_ID => Einstellungen::clientId(), Einstellungen::NAME => Einstellungen::name(),
    Einstellungen::AKTIV => '1'];
Settings::forget(Einstellungen::AUSSTELLER);
$demoKonto = BenutzerService::anlegen($kennung . '-demo', $pw, 'technik', false);
pruefe($demoSso->invoke(null, $demoKonto) === true && Einstellungen::aussteller() === $demoIss && Einstellungen::clientId() === 'demo-powermanagement'
    && !Einstellungen::schalter(Einstellungen::AKTIV) && Einstellungen::secretZustand() !== 'hinterlegt', 'N.12: Demo trägt Authentik ein – aus, ohne passendes Secret');
$i = Konten::identitaet($demoKonto);
pruefe($i !== null && $i['weg'] === 'freischaltung' && $i['name_dort'] === 'technik@example.com' && preg_match('/^[0-9a-f]{64}$/', (string) $i['subjekt']) === 1
    && $i['schluessel'] === Konten::schluessel($demoIss, (string) $i['subjekt']), 'N.12: Demo-Verknüpfung (Weg freischaltung, Subjekt wie hashed user ID)');
$demoZaehlung = $zaehlen();
pruefe($demoZaehlung[1] === $vorher[1] + 1 && $demoZaehlung[2] === $vorher[2] + 2
    && Database::first("SELECT id FROM sso_anfragen WHERE voller_name = 'Erika Beispiel' AND aussteller = ?", [$demoIss]) !== null,
    'N.12: Demo-Anfrage „Erika Beispiel“ und die Gruppen pm-planung/pm-technik');
$demoSso->invoke(null, $demoKonto);
pruefe($zaehlen() === $demoZaehlung, 'N.12: ein zweites Mal nichts doppelt');
$demoReset->invoke(null);
pruefe(Konten::identitaet($demoKonto) === null && Database::first('SELECT id FROM sso_anfragen WHERE aussteller = ?', [$demoIss]) === null
    && (int) Database::scalar("SELECT COUNT(*) FROM sso_zuordnungen WHERE gruppe IN ('pm-planung', 'pm-technik')") === 0
    && Database::first('SELECT id FROM sso_identitaeten WHERE user_id = ?', [$planer]) !== null, 'N.12: Demo-Reset räumt nur die Demo weg');
foreach ($gesichert as $k => $w) {
    Settings::put($k, $w);
}
pruefe(Einstellungen::aktiv() && Einstellungen::secretZustand() === 'hinterlegt', 'N.12: Einrichtung wiederhergestellt');

// ================================================================ O. Während eines Updates
// Die Schreibsperre der Instanz (storage/update/ist/wartung, wie
// update-debian.sh sie setzt): Kein Vorgang beginnt, und kein Rückruf
// schreibt – weder an der Tür noch nach dem Warten auf den Anmeldedienst,
// auch kein Fehlschlag (Audit, Anfrage, Bremse, Fehlversuch des
// Kundenmodus). Danach geht alles wieder.
$abmelden();
alsBenutzer(null);
Http::$attrappe = $idp->http();
$idp->zuruecksetzen();
$upd = (string) Config::get('update.verzeichnis');
pruefe($upd !== '' && str_starts_with($upd, $instanzOrdner . '/'), 'O: storage/update der Instanz');
@mkdir($upd . '/ist', 0700, true);
$sperre = static function (bool $an) use ($upd): void {
    $pfad = $upd . '/ist/wartung';
    $an ? file_put_contents($pfad, UpdateService::KOPF_WARTUNG . "\nseit " . time() . "\nkennung -\n") : @unlink($pfad);
    UpdateService::vergessen();
};
/** Alle Tabellen der Instanz (ohne die Zwischenspeicher des Anmeldedienstes) als Prüfsumme. */
$stand = static function (): string {
    $teile = [];
    foreach (array_column(Database::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name') as $t) {
        $zeilen = $t === 'settings'
            ? Database::select('SELECT setting_key, setting_value FROM settings WHERE setting_key NOT IN (?, ?, ?, ?) ORDER BY setting_key',
                [Einstellungen::DISCOVERY, Einstellungen::DISCOVERY_AM, Einstellungen::JWKS, Einstellungen::JWKS_AM])
            : Database::select('SELECT * FROM "' . $t . '"');
        $teile[] = $t . ':' . md5((string) json_encode($zeilen));
    }
    return md5(implode('|', $teile));
};
/** Hält eine Anfrage storage/update/.anfragen (angemeldet – das Update wartete auf sie)? */
$angemeldet = static function () use ($upd): bool {
    $h = fopen($upd . '/' . UpdateService::ANFRAGEN, 'c');
    $frei = flock($h, LOCK_EX | LOCK_NB);
    if ($frei) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return !$frei;
};
// Während des Wartens auf den Token-Endpunkt etwas tun (das Update beginnt) – und Einlösungen zählen.
$httpO = $idp->http();
$eingeloest = 0;
$waehrend = null;
Http::$attrappe = static function (string $m, string $url, array $o = []) use ($httpO, &$waehrend, &$eingeloest): array {
    if (str_contains($url, '/token/')) {
        $eingeloest++;
        if ($waehrend !== null) {
            $w = $waehrend;
            $waehrend = null;
            $w();
        }
    }
    return $httpO($m, $url, $o);
};

// O.1 Meldungen und Start
gleich(Anmeldung::updateSperre('anmelden'), null, 'O.1: ohne Sperre beginnt jeder Vorgang');
$sperre(true);
pruefe(str_contains((string) Anmeldung::updateSperre('anmelden'), 'Gerade wird das System aktualisiert – die Anmeldung über ' . Einstellungen::name())
    && !str_contains((string) Anmeldung::updateSperre('anmelden'), 'Update'), 'O.1: Anmelden – Hinweis ohne das Wort „Update“ (auf der Anmeldeseite stehen auch Kunden)');
pruefe(str_contains((string) Anmeldung::updateSperre('verbinden'), 'nicht verbunden') && str_contains((string) Anmeldung::updateSperre('kundenmodus:5'), 'Kundenmodus bleibt an')
    && str_contains((string) Anmeldung::updateSperre('token:5'), 'Bestätigen über'), 'O.1: Verbinden, Kundenmodus, Token – je ein eigener Hinweis');
$sperre(false);

// O.2 Der Rückruf kommt während der Sperre an (begonnen vorher): nichts geschrieben, der Vorgang ist verbraucht
UpdateService::anfrageAbmelden();
$vorher = $stand();
$eingeloest = 0;
$r = $ablauf($person($subPlaner, 'planer-dort', [$gruppeA]), [], 'anmelden', null, static function (array $q) use ($sperre): array {
    $sperre(true);
    return $q;
});
pruefe(!$r['ok'] && $r['code'] === Anmeldung::UPDATE && $r['ziel'] === '/login' && $r['meldung'] === Anmeldung::updateText('anmelden') && Auth::id() === null,
    'O.2: Rückruf während der Sperre → update, zurück zur Anmeldung, nicht angemeldet');
gleich($stand(), $vorher, 'O.2: nichts geschrieben (alle Tabellen, auch Audit und Bremse)');
gleich($eingeloest, 0, 'O.2: der Code wurde nicht eingelöst');
pruefe(!$angemeldet(), 'O.2: abgewiesen – das Update wartet nicht auf diese Anfrage');
$sperre(false);
gleich(Anmeldung::rueckruf($r['_zurueck'], $ip)['code'], 'state', 'O.2: derselbe Rückruf nach dem Update → state (der Vorgang galt einmal)');
$r = $ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
pruefe($r['ok'] && Auth::id() === $planer, 'O.2: nach dem Update noch einmal – angemeldet');
$abmelden();

// O.3 Das Update beginnt, während der Rückruf auf den Anbieter wartet
UpdateService::anfrageAbmelden();
$vorher = $stand();
$warAngemeldet = null;
$waehrend = static function () use ($sperre, $angemeldet, &$warAngemeldet): void {
    $sperre(true);
    $warAngemeldet = $angemeldet();
};
$r = $ablauf($person($subPlaner, 'planer-dort', [$gruppeA]));
pruefe($r['code'] === Anmeldung::UPDATE && Auth::id() === null, 'O.3: Sperre während des Wartens → nach dem Warten noch einmal gefragt: update, nicht angemeldet');
pruefe($warAngemeldet === true, 'O.3: der Rückruf war angemeldet, bevor er wartete – das Update wartet auf ihn, bevor es sichert');
gleich($stand(), $vorher, 'O.3: nichts geschrieben (Rollen aus Gruppen, letzte Anmeldung, Identität, Audit)');
UpdateService::anfrageAbmelden();
$sperre(false);

// O.4 Automatische Anlage, Anmeldeanfrage und ein Fehler des Anbieters – während der Sperre nichts davon gespeichert
Settings::put(Einstellungen::ANLEGEN, '1');
$vorher = $stand();
$waehrend = static fn () => $sperre(true);
$r = $ablauf($person('sub-upd-neu-' . $kennung, $kennung . '-updneu', [$gruppeA]));
pruefe($r['code'] === Anmeldung::UPDATE && Database::first('SELECT id FROM users WHERE username = ?', [$kennung . '-updneu']) === null,
    'O.4: automatische Anlage während der Sperre → kein Konto (seine ID wäre nach einem Zurückrollen wieder frei)');
gleich($stand(), $vorher, 'O.4: Anlage – nichts geschrieben');
$sperre(false);
Settings::put(Einstellungen::ANLEGEN, '0');
$vorher = $stand();
$waehrend = static fn () => $sperre(true);
$r = $ablauf($person('sub-upd-anfrage-' . $kennung, $kennung . '-updanfrage'));
pruefe($r['code'] === Anmeldung::UPDATE && !str_contains($r['meldung'], 'Anfrage')
    && Database::first('SELECT id FROM sso_anfragen WHERE subjekt = ?', ['sub-upd-anfrage-' . $kennung]) === null,
    'O.4: unbekannte Person während der Sperre → keine Anmeldeanfrage, Meldung zum Update statt „Anfrage eingegangen“');
gleich($stand(), $vorher, 'O.4: Anfrage – nichts geschrieben');
$sperre(false);
$fehl = $fehlschlaege();
$waehrend = static fn () => $sperre(true);
$r = $ablauf($person($subPlaner, 'planer-dort'), ['token_fehler' => 'invalid_grant']);
pruefe($r['code'] === Anmeldung::UPDATE && $fehlschlaege() === $fehl, 'O.4: Fehler des Anbieters während der Sperre → update, kein Fehlschlag fürs Audit und die Bremse');
$sperre(false);
UpdateService::anfrageAbmelden();

// O.5 Verbinden und Kundenmodus entsperren: nichts verknüpft, der Kundenmodus bleibt, kein Fehlversuch
$verbO = BenutzerService::anlegen($kennung . '-updverb', $pw, 'planung', false);
pruefe(Auth::attempt($kennung . '-updverb', $pw, $ip)['ok'] && Auth::anmeldeweg() === 'kennwort', 'O.5: mit Kennwort angemeldet');
$vorher = $stand();
$waehrend = static fn () => $sperre(true);
$r = $ablauf($person('sub-updverb-' . $kennung, 'updverb-dort'), [], 'verbinden', $verbO);
pruefe($r['code'] === Anmeldung::UPDATE && $r['ziel'] === '/profil' && Konten::identitaet($verbO) === null && Auth::id() === $verbO,
    'O.5: Verbinden während der Sperre → nicht verknüpft, zurück ins Profil, die Sitzung bleibt');
gleich($stand(), $vorher, 'O.5: Verbinden – nichts geschrieben');
$sperre(false);
$r = $ablauf($person('sub-updverb-' . $kennung, 'updverb-dort'), [], 'verbinden', $verbO);
pruefe($r['ok'] && Konten::identitaet($verbO) !== null, 'O.5: nach dem Update verbunden');
$abmelden();
pruefe($ablauf($person($subPlaner, 'planer-dort'))['ok'], 'O.5: Planer über SSO angemeldet');
$bericht = 900000 + random_int(1, 99999);
$_SESSION[Kundenmodus::SITZUNG] = ['bericht' => $bericht, 'seit' => time(), 'fehl' => 0, 'bis' => 0, 'user' => $planer, 'name' => '', 'geraet' => 0];
UpdateService::anfrageAbmelden();
$vorher = $stand();
foreach (['andere Person' => $person('sub-fremd-' . $kennung, 'fremd-dort'), 'dieselbe Identität' => $person($subPlaner, 'planer-dort')] as $wer => $p) {
    $waehrend = static fn () => $sperre(true);
    $r = $ablauf($p, [], 'kundenmodus:' . $bericht, $planer);
    pruefe($r['code'] === Anmeldung::UPDATE && $r['ziel'] === '/berichte/' . $bericht . '/kunde' && Kundenmodus::aktiv() !== null
        && (int) (Kundenmodus::aktiv()['fehl'] ?? -1) === 0 && Auth::id() === $planer, 'O.5: Kundenmodus entsperren (' . $wer . ') während der Sperre → bleibt gesperrt, kein Fehlversuch');
    $sperre(false);
}
gleich($stand(), $vorher, 'O.5: Kundenmodus – nichts geschrieben (kein Ereignis)');
$r = $ablauf($person($subPlaner, 'planer-dort'), [], 'kundenmodus:' . $bericht, $planer);
pruefe($r['ok'] && Kundenmodus::aktiv() === null, 'O.5: nach dem Update entsperrt');
Database::execute('DELETE FROM bericht_ereignisse WHERE bericht_id = ?', [$bericht]);
$abmelden();
UpdateService::anfrageAbmelden();

// O.6 Einschalten prüft mit Warten – hat inzwischen ein Update begonnen, bleibt es aus
Settings::put(Einstellungen::AKTIV, '0');
$sperre(true);
$r = Einstellungen::einschalten();
pruefe(!$r['ok'] && str_contains($r['message'], 'Gerade läuft ein Update – nicht eingeschaltet') && !Einstellungen::schalter(Einstellungen::AKTIV),
    'O.6: Einschalten während der Sperre → bleibt aus');
$sperre(false);
pruefe(Einstellungen::einschalten()['ok'] && Einstellungen::aktiv(), 'O.6: danach eingeschaltet');
Http::$attrappe = $idp->http();
$waehrend = null;

// O.7 Migration 017 im Update: Die Sicherung (VACUUM INTO wie update-debian.sh)
// entsteht vor den Migrationen, 017 läuft in Stufe 2 danach. Eine Datenbank wie
// auf main (ohne 017, aber mit 018), gesichert, migriert, zurückgelegt
// (db_zuruecklegen aus update-debian.sh), noch einmal migriert. Dazu das
// verschlüsselte Client-Secret: übersteht Update und Zurückrollen.
$sim = $instanzOrdner . '/update-sim';
mkdir($sim . '/main', 0700, true);
mkdir($sim . '/.schnappschuss', 0700, true);
foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $datei) {
    if (basename($datei) !== '017_sso.sql') {
        copy($datei, $sim . '/main/' . basename($datei));
    }
}
$simDb = $sim . '/database.sqlite';
$snap = $sim . '/.schnappschuss/database.sqlite';
$instanzDb = (string) Config::get('database.path');
$aufDb = static function (string $pfad): void {
    Config::set('database.path', $pfad);
    Database::trennen();
    Settings::vergessen();
    Auth::vergessen();
    Zugriff::vergessen();
};
$spalten = static fn (string $tabelle): array => array_column(Database::select('PRAGMA table_info("' . $tabelle . '")'), 'name');
$tabelle = static fn (string $name): bool => Database::scalar("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]) !== false;
$sichern = static function () use ($simDb, $snap): void {
    @unlink($snap);
    $p = new PDO('sqlite:' . $simDb);
    $p->exec('VACUUM INTO ' . $p->quote($snap));
};
file_put_contents($sim . '/zuruecklegen.sh', <<<'SH'
    set -Eeuo pipefail
    SCHNAPPSCHUSS="$1"; DB="$2"
    as_app() { "$@"; }
    eval "$(sed -n '/^db_zuruecklegen() {$/,/^}$/p' "$3")"
    declare -F db_zuruecklegen > /dev/null || { echo 'db_zuruecklegen fehlt in update-debian.sh'; exit 9; }
    db_zuruecklegen
    SH);
$zuruecklegen = static function () use ($sim, $snap, $simDb, $aufDb): int {
    Database::trennen();   // wie PHP-FPM, der beim Zurückrollen steht
    exec('bash ' . escapeshellarg($sim . '/zuruecklegen.sh') . ' ' . escapeshellarg($snap) . ' ' . escapeshellarg($simDb) . ' '
        . escapeshellarg(APP_ROOT . '/deploy/update-debian.sh') . ' 2>&1', $aus, $rc);
    $aufDb($simDb);
    return $rc;
};
file_put_contents($sim . '/config.php', '<?php $c = require ' . var_export((string) getenv('POWERMANAGEMENT_CONFIG'), true)
    . '; $c["database"]["path"] = ' . var_export($simDb, true) . '; return $c;');
/** bin/power auf der nachgestellten Datenbank (wie update-debian.sh als App-Benutzer). */
$simPower = static function (array $argumente) use ($sim): array {
    $p = proc_open(array_merge([PHP_BINARY, APP_ROOT . '/bin/power'], $argumente), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $rohre, APP_ROOT, ['POWERMANAGEMENT_CONFIG' => $sim . '/config.php'] + getenv());
    $aus = (string) stream_get_contents($rohre[1]) . (string) stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    return ['rc' => proc_close($p), 'aus' => $aus];
};
$aufDb($simDb);
$angewandt = Database::migrate($sim . '/main');
pruefe(!in_array('017_sso.sql', $angewandt, true) && in_array('018_tanks.sql', $angewandt, true) && !$tabelle('sso_identitaeten')
    && !in_array('nur_sso', $spalten('users'), true) && !in_array('quelle', $spalten('user_roles'), true), 'O.7: Datenbank wie auf main – 018 ja, 017 nicht');
Database::insert('users', ['username' => $kennung . '-sim', 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'created_at' => gmdate('c')]);
Settings::put('pruefung.update_sim', 'vor dem Update ' . $kennung);
$sichern();
$r = $simPower(['migrate']);
pruefe($r['rc'] === 0 && str_contains($r['aus'], '017_sso.sql') && !str_contains($r['aus'], '018_tanks.sql'), 'O.7: Stufe 2 nach der Sicherung: bin/power migrate wendet nur 017 an (nach 018)');
$aufDb($simDb);
pruefe($tabelle('sso_identitaeten') && $tabelle('sso_anfragen') && $tabelle('sso_zuordnungen') && in_array('nur_sso', $spalten('users'), true)
    && in_array('quelle', $spalten('user_roles'), true), 'O.7: danach SSO-Tabellen und -Spalten da');
// Was nach der Sicherung geschrieben wurde (hier: eine SSO-Verbindung), nimmt das Zurückrollen mit.
$r = Einstellungen::verbindungSpeichern($iss, $client['id'], 'sim-' . $kennung . '-' . bin2hex(random_bytes(8)), 'Authentik', true);
pruefe($r['ok'] && Einstellungen::secretZustand() === 'hinterlegt', 'O.7: nach der Migration eine Verbindung eingetragen');
gleich($zuruecklegen(), 0, 'O.7: zurückgelegt (db_zuruecklegen aus update-debian.sh)');
pruefe(!$tabelle('sso_identitaeten') && !in_array('nur_sso', $spalten('users'), true) && !in_array('quelle', $spalten('user_roles'), true)
    && Database::scalar('SELECT name FROM migrations WHERE name = ?', ['017_sso.sql']) === false, 'O.7: nach dem Zurückrollen wieder ohne 017');
pruefe(Database::scalar('SELECT id FROM users WHERE username = ?', [$kennung . '-sim']) !== false && Settings::get('pruefung.update_sim') === 'vor dem Update ' . $kennung
    && Settings::get(Einstellungen::AUSSTELLER, '') === '' && Settings::get(Settings::GEHEIM_SSO_SECRET, '') === '',
    'O.7: der Stand vor dem Update ist da, die Verbindung von danach nicht');
$r = $simPower(['migrate']);
$aufDb($simDb);
pruefe($r['rc'] === 0 && str_contains($r['aus'], '017_sso.sql') && $tabelle('sso_identitaeten') && in_array('nur_sso', $spalten('users'), true),
    'O.7: der nächste Versuch migriert 017 noch einmal sauber');

// Das Secret übersteht ein Update (Sicherung, Migration ohne Neues, Prüfung) und ein Zurückrollen.
$secret = 'sim-secret-' . $kennung . '-' . bin2hex(random_bytes(8));
pruefe(Einstellungen::verbindungSpeichern($iss, $client['id'], $secret, 'Authentik', true)['ok'] && Einstellungen::secret() === $secret,
    'O.7: Verbindung mit Secret eingetragen (verschlüsselt)');
pruefe(!str_contains((string) Settings::get(Settings::GEHEIM_SSO_SECRET, ''), $secret), 'O.7: in der Datenbank nur verschlüsselt');
$sichern();
Database::trennen();
$r = $simPower(['migrate']);
$z = $simPower(['sso:zustand']);
pruefe($r['rc'] === 0 && $z['rc'] === 0 && str_contains($z['aus'], 'hinterlegt (verschlüsselt') && !str_contains($z['aus'], $secret),
    'O.7: nach dem Update liest bin/power das Secret (sso:zustand: hinterlegt), ohne es zu zeigen');
$aufDb($simDb);
$neuesSecret = 'sim-anders-' . bin2hex(random_bytes(8));
Einstellungen::verbindungSpeichern($iss, $client['id'], $neuesSecret, 'Authentik', true);
pruefe(Einstellungen::secret() === $neuesSecret, 'O.7: nach der Sicherung ein anderes Secret eingetragen');
gleich($zuruecklegen(), 0, 'O.7: zurückgelegt');
pruefe(Einstellungen::secret() === $secret && Einstellungen::secretZustand() === 'hinterlegt' && Einstellungen::aussteller() === $iss,
    'O.7: nach dem Zurückrollen gilt das Secret der Sicherung – entschlüsselt, an dieselbe Verbindung gebunden');
$aufDb($instanzDb);
pruefe(Einstellungen::aktiv() && Einstellungen::secret() === $client['secret'], 'O.7: zurück auf der Datenbank der Instanz');

// ================================================================ Geheimnisse
$audit = Database::select("SELECT username, action, target_id, details FROM audit_log WHERE action LIKE 'einstellungen.sso%'");
pruefe(count($audit) > 5, 'Einstellungen im Audit');
$alles = json_encode(Database::select('SELECT * FROM audit_log'));
pruefe(!str_contains((string) $alles, $client['secret']) && !str_contains((string) $alles, rawurlencode($client['secret'])) && !str_contains((string) $alles, $geheimCode),
    'Secret und Code in keiner Spalte des Audits');
$imAudit = array_values(array_filter($geheimAblauf, static fn (string $g): bool => str_contains((string) $alles, $g)));
pruefe($imAudit === [], 'M.1: Code, Access- und ID-Token des Ablaufs in keiner Spalte des Audits');
clearstatcache();
$neu = (string) @file_get_contents($logDatei, false, null, $logVorher);
pruefe(!str_contains($neu, $client['secret']) && !str_contains($neu, rawurlencode($client['secret'])) && !str_contains($neu, $geheimCode)
    && !str_contains($neu, $geheimVerifier) && !str_contains($neu, 'pm-probe-'), 'Secret, Code und Probe-Code in keiner neuen Zeile des Logs');
pruefe(array_values(array_filter($geheimAblauf, static fn (string $g): bool => str_contains($neu, $g))) === [],
    'M.1: Code, Access- und ID-Token des Ablaufs in keiner neuen Zeile des Logs');
pruefe(str_contains($neu, '"schritt":"discovery"') && str_contains($neu, '"schritt":"probe"'), 'das Log nennt Schritt, Status und Dauer');
pruefe(str_contains($neu, '"schritt":"rueckruf"') && str_contains($neu, 'RuntimeException: Attrappe kaputt'), 'das Log nennt Rückruf-Gründe und bei „intern“ nur Klasse und Text');

Http::$attrappe = null;
ende('sso');
