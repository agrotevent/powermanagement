<?php
declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * Prüfung des ID-Tokens (OIDC Core 3.1.3.7) – rein: ohne Netz, ohne
 * Datenbank, ohne Uhr (die Zeit kommt mit $soll).
 *
 * Geprüft wird immer alles, auch die Signatur, obwohl das Token über TLS
 * direkt vom Token-Endpunkt kommt: So hängt die Anmeldung nicht an der
 * Annahme, dass genau dieser Weg sicher ist (Proxy, `verbinden_ueber`).
 *
 * Nur RS256 und ES256, und `kty` des Schlüssels muss zu `alg` passen – eine
 * Verwechslung (HS256 mit dem öffentlichen RSA-Schlüssel als Geheimnis,
 * `none`) ist damit ausgeschlossen. Kopf-Angaben, die woanders Schlüssel
 * holen ließen (`jku`, `x5u`, `jwk`, `x5c`), werden nie gelesen; `crit` und
 * `enc` (JWE) lehnen ab.
 */
final class IdToken
{
    public const MAX_LAENGE = 16384, SPIEL = 60, HOECHSTENS_ALT = 600;

    public const T_UHR = 'Die Uhr dieses Servers oder des Anmeldedienstes geht falsch (Zeitsynchronisation prüfen).';
    public const T_ISS = 'Der Aussteller im Token passt nicht – Proxy vor Authentik (Host, X-Forwarded-Proto) und Issuer prüfen.';
    public const T_NEU = 'Der Anmeldedienst hat nicht neu angemeldet.';

    /**
     * Das ID-Token prüfen und seine Claims zurückgeben.
     *
     * $soll['auth_nach']: Für Bestätigungen und das Verbinden muss `auth_time`
     * mindestens so groß sein (sonst Code `nicht_neu`), null = egal.
     *
     * @param array{aussteller:string, client_id:string, algs:list<string>, nonce:string, jetzt:int, auth_nach:?int} $soll
     * @param \Closure(string $kid, string $kty): ?array{kid:string,kty:string,alg:string,pem:string} $schluessel
     * @return array<string,mixed> geprüfte Claims
     * @throws SsoFehler format|alg|kid|signatur|iss|aud|azp|exp|iat|nbf|nonce|sub|nicht_neu
     */
    public static function pruefen(#[\SensitiveParameter] string $jwt, array $soll, \Closure $schluessel): array
    {
        // Auch JWE (5 Teile) und Base64 mit + / = scheitern hier.
        if (strlen($jwt) > self::MAX_LAENGE || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jwt) !== 1) {
            throw self::ungueltig('format');
        }
        [$k, $r, $s] = explode('.', $jwt);
        $kopf = self::objekt(self::b64($k));
        $claims = self::objekt(self::b64($r));
        $sig = self::b64($s);

        $alg = $kopf['alg'] ?? null;
        if (!is_string($alg) || !in_array($alg, ['RS256', 'ES256'], true) || !in_array($alg, $soll['algs'], true)) {
            throw self::ungueltig('alg');
        }
        if (array_key_exists('crit', $kopf) || array_key_exists('enc', $kopf) || !is_string($kopf['kid'] ?? '')) {
            throw self::ungueltig('format');
        }
        $kty = $alg === 'RS256' ? 'RSA' : 'EC';
        $jwk = $schluessel((string) ($kopf['kid'] ?? ''), $kty);
        if (!is_array($jwk) || ($jwk['kty'] ?? null) !== $kty || !is_string($jwk['alg'] ?? null)
            || ($jwk['alg'] !== '' && $jwk['alg'] !== $alg) || !is_string($jwk['pem'] ?? null)) {
            throw self::ungueltig('kid');
        }
        if ($alg === 'ES256') {
            $sig = Jwk::es256Der($sig);
        }
        $pub = @openssl_pkey_get_public($jwk['pem']);
        // openssl_verify gibt 1, 0 oder -1/false – nur 1 ist gültig (-1 bei kaputter Signatur nachgeprüft).
        if ($pub === false || openssl_verify($k . '.' . $r, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            while (openssl_error_string() !== false) {
                // Fehlerstapel leeren, er gehörte sonst zur nächsten Prüfung
            }
            throw self::ungueltig('signatur');
        }

        $j = $soll['jetzt'];
        $zahl = static fn (mixed $v): ?int => is_int($v) ? $v : (is_float($v) && is_finite($v) ? (int) floor($v) : null);
        if (!is_string($claims['iss'] ?? null) || !hash_equals($soll['aussteller'], $claims['iss'])) {
            throw new SsoFehler('iss', self::T_ISS);
        }
        $aud = $claims['aud'] ?? null;
        $aud = is_string($aud) ? [$aud] : $aud;
        if (!is_array($aud) || count($aud) !== 1 || !is_string($aud[0] ?? null) || !hash_equals($soll['client_id'], $aud[0])) {
            throw self::ungueltig('aud');
        }
        if (array_key_exists('azp', $claims) && (!is_string($claims['azp']) || !hash_equals($soll['client_id'], $claims['azp']))) {
            throw self::ungueltig('azp');
        }
        $exp = $zahl($claims['exp'] ?? null);
        if ($exp === null || $exp + self::SPIEL <= $j) {
            throw new SsoFehler('exp', self::T_UHR);
        }
        $iat = $zahl($claims['iat'] ?? null);
        if ($iat === null || $iat > $j + self::SPIEL || $iat < $j - self::HOECHSTENS_ALT) {
            throw new SsoFehler('iat', self::T_UHR);
        }
        if (array_key_exists('nbf', $claims) && (($n = $zahl($claims['nbf'])) === null || $n > $j + self::SPIEL)) {
            throw new SsoFehler('nbf', self::T_UHR);
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($soll['nonce'], $claims['nonce'])) {
            throw self::ungueltig('nonce');
        }
        if (!is_string($claims['sub'] ?? null) || preg_match('/^[\x21-\x7e]{1,255}$/', $claims['sub']) !== 1) {
            throw self::ungueltig('sub');
        }
        if ($soll['auth_nach'] !== null && (($at = $zahl($claims['auth_time'] ?? null)) === null || $at < $soll['auth_nach'])) {
            throw new SsoFehler('nicht_neu', self::T_NEU);
        }
        return $claims;
    }

    /**
     * Nur `alg` aus dem Kopf – ungeprüft, ohne Signatur. Für die Frage, ob
     * nach einer Ablehnung `alg` die Discovery neu geholt werden soll; null,
     * wenn das Format nicht taugt.
     */
    public static function kopfAlg(#[\SensitiveParameter] string $jwt): ?string
    {
        if (strlen($jwt) > self::MAX_LAENGE || preg_match('/^([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jwt, $m) !== 1) {
            return null;
        }
        try {
            $alg = self::objekt(self::b64($m[1]))['alg'] ?? null;
        } catch (SsoFehler) {
            return null;
        }
        return is_string($alg) ? $alg : null;
    }

    /**
     * base64url streng: nur [A-Za-z0-9_-], ohne Auffüllung, Länge mod 4 ≠ 1
     * und kanonisch (wieder kodiert genau gleich) – sonst SsoFehler('format').
     */
    public static function b64(string $teil): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $teil) !== 1 || strlen($teil) % 4 === 1) {
            throw self::ungueltig('format');
        }
        $roh = base64_decode(strtr($teil, '-_', '+/') . str_repeat('=', (4 - strlen($teil) % 4) % 4), true);
        if ($roh === false || rtrim(strtr(base64_encode($roh), '+/', '-_'), '=') !== $teil) {
            throw self::ungueltig('format');
        }
        return $roh;
    }

    /** Ein JSON-Objekt (Kopf oder Claims), höchstens 16 Ebenen tief. */
    private static function objekt(string $json): array
    {
        if ($json === '' || $json[0] !== '{') {
            throw self::ungueltig('format');
        }
        try {
            $wert = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::ungueltig('format');
        }
        if (!is_array($wert)) {
            throw self::ungueltig('format');
        }
        return $wert;
    }

    private static function ungueltig(string $code): SsoFehler
    {
        return new SsoFehler($code, 'Das Anmeldetoken ist ungültig (' . $code . ').');
    }
}
