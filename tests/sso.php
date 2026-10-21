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
 *
 * Die Abschnitte D–N (Start, Rückruf, Konten, Rollen, Sitzung, nur SSO,
 * Step-up, Kunden, CLI) kommen mit den nächsten Schritten dazu.
 */

require __DIR__ . '/hilfen/instanz.php';
inEigenerInstanz(__FILE__, ['app' => ['url' => 'https://pm.example.test']]);
require __DIR__ . '/hilfen/pruefung.php';
require __DIR__ . '/hilfen/oidc-attrappe.php';

use App\Auth\Sso\Anbieter;
use App\Auth\Sso\Einstellungen;
use App\Auth\Sso\IdToken;
use App\Auth\Sso\Jwk;
use App\Auth\Sso\SsoFehler;
use App\Messung\Http;
use App\Services\BenutzerService;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Settings;

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
$idp = OidcAttrappe::einrichten($instanzOrdner . '/oidc', 'https://idp.example.test');
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
pruefe($a['status'] === 400 && !isset($a['kopf']['Location']), 'Attrappe: andere als die zuerst benutzte Rückruf-Adresse → 400 ohne Weiterleitung');
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

// ================================================================ Geheimnisse
$audit = Database::select("SELECT username, action, target_id, details FROM audit_log WHERE action LIKE 'einstellungen.sso%'");
pruefe(count($audit) > 5, 'Einstellungen im Audit');
$alles = json_encode(Database::select('SELECT * FROM audit_log'));
pruefe(!str_contains((string) $alles, $client['secret']) && !str_contains((string) $alles, rawurlencode($client['secret'])) && !str_contains((string) $alles, $geheimCode),
    'Secret und Code in keiner Spalte des Audits');
clearstatcache();
$neu = (string) @file_get_contents($logDatei, false, null, $logVorher);
pruefe(!str_contains($neu, $client['secret']) && !str_contains($neu, rawurlencode($client['secret'])) && !str_contains($neu, $geheimCode)
    && !str_contains($neu, $geheimVerifier) && !str_contains($neu, 'pm-probe-'), 'Secret, Code und Probe-Code in keiner neuen Zeile des Logs');
pruefe(str_contains($neu, '"schritt":"discovery"') && str_contains($neu, '"schritt":"probe"'), 'das Log nennt Schritt, Status und Dauer');

Http::$attrappe = null;
ende('sso');
