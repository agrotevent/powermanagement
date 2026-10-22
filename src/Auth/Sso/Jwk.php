<?php
declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * Signaturschlüssel aus dem JWKS des Anmeldedienstes (RFC 7517/7518).
 *
 * Gelesen werden nur die Pflichtangaben: RSA `n`/`e` (mindestens 2048 Bit)
 * und EC P-256 `x`/`y`. Daraus entsteht von Hand eine SubjectPublicKeyInfo
 * (PEM) – openssl_pkey_new() nimmt keine RSA-Bestandteile an. `x5c`, `x5u`
 * und `jku` werden nie gelesen: ein zweiter Weg zum Schlüssel wäre nur ein
 * zweiter Weg, ihn zu fälschen.
 */
final class Jwk
{
    public const MIN_RSA_BITS = 2048;

    /**
     * Ein Signaturschlüssel aus dem JWKS – oder null, wenn unbrauchbar.
     * RSA: n/e, ≥ 2048 Bit; EC: crv P-256, x/y je 32 Byte. use leer oder 'sig'.
     * alg am JWK leer oder RS256 (RSA) / ES256 (EC). x5c, x5u, jku werden nie gelesen.
     *
     * @return array{kid:string,kty:string,alg:string,pem:string}|null
     */
    public static function schluessel(array $jwk): ?array
    {
        return self::lesen($jwk)[0];
    }

    /** Warum dieser JWK übergangen wird (für „Verbindung prüfen“) – null, wenn er taugt. */
    public static function grund(array $jwk): ?string
    {
        return self::lesen($jwk)[1];
    }

    /** 64 Byte R‖S (JWS, RFC 7518 3.4) → DER SEQUENCE{INTEGER r, INTEGER s}; andere Länge → SsoFehler('signatur'). */
    public static function es256Der(string $rs): string
    {
        if (strlen($rs) !== 64) {
            throw new SsoFehler('signatur', 'Das Anmeldetoken ist ungültig (signatur).');
        }
        return self::tlv(0x30, self::ganzzahl(substr($rs, 0, 32)) . self::ganzzahl(substr($rs, 32)));
    }

    /** @return array{0:?array{kid:string,kty:string,alg:string,pem:string},1:?string} */
    private static function lesen(array $jwk): array
    {
        $kid = $jwk['kid'] ?? '';
        if (!is_string($kid) || strlen($kid) > 255) {
            return [null, 'ohne gültige Kennung (kid)'];
        }
        $use = $jwk['use'] ?? '';
        if (!is_string($use) || ($use !== '' && $use !== 'sig')) {
            return [null, 'nicht zum Signieren (use=' . self::text($use) . ')'];
        }
        $alg = $jwk['alg'] ?? '';
        if (!is_string($alg)) {
            return [null, 'Algorithmus ungültig'];
        }
        $kty = $jwk['kty'] ?? null;
        try {
            if ($kty === 'RSA') {
                if ($alg !== '' && $alg !== 'RS256') {
                    return [null, 'Algorithmus ' . self::text($alg) . ' (nur RS256)'];
                }
                if (!is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
                    return [null, 'RSA ohne n/e'];
                }
                $n = IdToken::b64($jwk['n']);
                $e = ltrim(IdToken::b64($jwk['e']), "\0");
                // e muss ungerade und größer als 1 sein (e = 1 machte jede „Signatur“ gültig).
                if ($e === '' || strlen($e) > 8 || (ord($e[strlen($e) - 1]) & 1) === 0 || $e === "\x01") {
                    return [null, 'RSA mit ungültigem Exponenten'];
                }
                $spki = self::tlv(0x30, self::tlv(0x30, self::tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . self::tlv(0x05, ''))
                    . self::tlv(0x03, "\0" . self::tlv(0x30, self::ganzzahl($n) . self::ganzzahl($e))));
                $pem = self::pem($spki);
                $d = self::details($pem);
                if ($d === null || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                    return [null, 'RSA-Schlüssel nicht lesbar'];
                }
                if ((int) ($d['bits'] ?? 0) < self::MIN_RSA_BITS) {
                    return [null, 'RSA mit ' . (int) ($d['bits'] ?? 0) . ' Bit (mindestens ' . self::MIN_RSA_BITS . ')'];
                }
            } elseif ($kty === 'EC') {
                $crv = $jwk['crv'] ?? null;
                if ($crv !== 'P-256') {
                    return [null, 'Kurve ' . self::text($crv) . ' (nur P-256)'];
                }
                if ($alg !== '' && $alg !== 'ES256') {
                    return [null, 'Algorithmus ' . self::text($alg) . ' (nur ES256)'];
                }
                if (!is_string($jwk['x'] ?? null) || !is_string($jwk['y'] ?? null)) {
                    return [null, 'EC ohne x/y'];
                }
                $x = IdToken::b64($jwk['x']);
                $y = IdToken::b64($jwk['y']);
                if (strlen($x) !== 32 || strlen($y) !== 32) {
                    return [null, 'EC mit falscher Länge von x/y'];
                }
                $pem = self::pem(self::tlv(0x30, self::tlv(0x30, self::tlv(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . self::tlv(0x06, "\x2a\x86\x48\xce\x3d\x03\x01\x07"))
                    . self::tlv(0x03, "\0\x04" . $x . $y)));
                // OpenSSL lehnt Punkte außerhalb der Kurve schon beim Einlesen ab.
                $d = self::details($pem);
                if ($d === null || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($d['ec']['curve_name'] ?? null) !== 'prime256v1') {
                    return [null, 'EC-Punkt nicht auf der Kurve P-256'];
                }
            } else {
                return [null, 'Schlüsselart ' . self::text($kty) . ' (nur RSA und EC)'];
            }
        } catch (SsoFehler) {
            return [null, 'Bestandteile nicht base64url-kodiert'];
        }
        return [['kid' => $kid, 'kty' => $kty, 'alg' => $alg, 'pem' => $pem], null];
    }

    private static function details(string $pem): ?array
    {
        $schluessel = @openssl_pkey_get_public($pem);
        $d = $schluessel === false ? false : openssl_pkey_get_details($schluessel);
        while (openssl_error_string() !== false) {
            // Fehlerstapel leeren
        }
        return is_array($d) ? $d : null;
    }

    private static function pem(string $spki): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function laenge(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $s = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($s)) . $s;
    }

    private static function tlv(int $typ, string $wert): string
    {
        return chr($typ) . self::laenge(strlen($wert)) . $wert;
    }

    /** DER-INTEGER, vorzeichenlos: führende Nullen weg, bei gesetztem obersten Bit eine davor. */
    private static function ganzzahl(string $b): string
    {
        $b = ltrim($b, "\0");
        if ($b === '' || ord($b[0]) > 0x7f) {
            $b = "\0" . $b;
        }
        return self::tlv(0x02, $b);
    }

    /** Eine Angabe aus dem JWKS für die Meldung – kurz und ohne Steuerzeichen. */
    private static function text(mixed $wert): string
    {
        if (!is_string($wert)) {
            return $wert === null ? 'fehlt' : gettype($wert);
        }
        return mb_substr((string) preg_replace('/[^\x20-\x7e]+/', '?', $wert), 0, 40);
    }
}
