<?php
declare(strict_types=1);

/**
 * OIDC-Attrappe für die Prüfskripte: ein Anmeldedienst mit den Pfaden und
 * Antwortformen von Authentik (Anwendung „pm-test“, Issuer je Anwendung).
 *
 * Eine Datei, zwei Arten des Aufrufs:
 *
 *   Als Server:  OIDC_ATTRAPPE=<ordner> php -S 127.0.0.1:<B> tests/hilfen/oidc-attrappe.php
 *   Im Prozess:  $idp = OidcAttrappe::einrichten($ordner, 'https://idp.example.test');
 *                Http::$attrappe = $idp->http();
 *
 * Sie lädt bootstrap.php nicht. Ihr Zustand liegt nur in ihrem Ordner
 * (zustand.json unter flock, die Schlüssel als PEM): Client, „angemeldeter“
 * Benutzer, Steuerung, ausgegebene Codes und Tokens, Protokoll. Das
 * Protokoll nennt je Anfrage, was die Anwendung geschickt hat (Client-
 * Anmeldung, PKCE, prompt …) – nie Secret, Code oder Token.
 *
 * Endpunkte (wie Authentik 2026.x):
 *   GET  /application/o/pm-test/.well-known/openid-configuration
 *   GET  /application/o/authorize/        sofort 302 zur redirect_uri (keine Oberfläche)
 *   POST /application/o/token/            Basic (rawurldecode = unquote) oder Post, Code einmalig 60 s, PKCE S256
 *   GET  /application/o/userinfo/         Bearer
 *   GET  /application/o/pm-test/jwks/     RSA (mit x5c wie Authentik) und EC
 *   GET  /application/o/pm-test/end-session/
 *
 * steuern() schaltet Abweichungen ein (null entfernt eine):
 *   alg (RS256|ES256|HS256|none), kid, signatur_kaputt, crit, fuenf_teile;
 *   iss, aud (String|Liste), azp, exp_delta, iat_delta, nbf_delta, nonce (falsch|fehlt),
 *   claims_im_id_token (false: nur in der Userinfo), userinfo_sub;
 *   token_fehler, authorize_fehler, authorize_beschreibung;
 *   discovery (Felder überschreiben, null entfernt), discovery_404, umleiten, gross
 *   (true = alle JSON-Endpunkte, sonst discovery|jwks|token|userinfo oder eine Liste davon);
 *   schluessel_wechseln (neue Schlüssel rsa-2/ec-2, die alten fallen weg), prompt_ignorieren,
 *   iss_param, kennung (steht in Codes und Access-Tokens – für die Suche nach Geheimnissen).
 */
final class OidcAttrappe
{
    public const SLUG = 'pm-test';

    /** Im Prozess: dieselbe Uhr wie die Anwendung (Einstellungen::jetzt). */
    public static ?\Closure $uhr = null;

    /** mit → [Datei, kid, alg] */
    private const SCHLUESSEL = [
        'rsa'      => ['rsa.pem', 'rsa-1', 'RS256'],
        'rsa-neu'  => ['rsa-neu.pem', 'rsa-2', 'RS256'],
        'ec'       => ['ec.pem', 'ec-1', 'ES256'],
        'ec-neu'   => ['ec-neu.pem', 'ec-2', 'ES256'],
        'rsa-1024' => ['rsa-1024.pem', 'rsa-1024', 'RS256'],
    ];

    private function __construct(private readonly string $ordner)
    {
    }

    /**
     * Neu einrichten: Schlüssel (RSA 2048 „rsa-1“, EC P-256 „ec-1“, dazu die
     * für den Wechsel und RSA 1024), Client-ID und ein Secret mit den
     * Sonderzeichen %+:/~ und „%41“ (prüft die Kodierung der Basic-Anmeldung:
     * ohne rawurlencode käme beim Anmeldedienst „A“ an).
     *
     * @param list<string> $redirectUris leer: die erste benutzte gilt (wie Authentik)
     * @param list<string> $logoutUris   Rücksprung nach dem Abmelden (Typ „Logout“)
     */
    public static function einrichten(string $ordner, string $basis, array $redirectUris = [], array $logoutUris = []): self
    {
        if (!is_dir($ordner)) {
            mkdir($ordner, 0700, true);
        }
        foreach (self::SCHLUESSEL as $mit => [$datei]) {
            $k = $mit === 'ec' || $mit === 'ec-neu'
                ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
                : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $mit === 'rsa-1024' ? 1024 : 2048]);
            if ($k === false || !openssl_pkey_export($k, $pem)) {
                throw new RuntimeException('OidcAttrappe: Schlüssel ' . $mit . ' ließ sich nicht erzeugen.');
            }
            file_put_contents($ordner . '/' . $datei, $pem);
            chmod($ordner . '/' . $datei, 0600);
        }
        $idp = new self($ordner);
        $idp->schreiben([
            'basis'         => rtrim($basis, '/'),
            'client'        => ['id' => 'pm-test-' . bin2hex(random_bytes(4)), 'secret' => 'Ge%41he+im:/~' . bin2hex(random_bytes(12))],
            'redirect_uris' => array_values($redirectUris),
            'logout_uris'   => array_values($logoutUris),
            'benutzer'      => null,
            'steuern'       => [],
            'codes'         => [],
            'tokens'        => [],
            'protokoll'     => [],
            'versatz'       => 0,
        ]);
        return $idp;
    }

    public static function laden(string $ordner): self
    {
        if (!is_file($ordner . '/zustand.json')) {
            throw new RuntimeException('OidcAttrappe: kein Zustand in ' . $ordner);
        }
        return new self($ordner);
    }

    public function aussteller(): string
    {
        return $this->lesen()['basis'] . '/application/o/' . self::SLUG . '/';
    }

    /** @return array{id:string,secret:string} */
    public function client(): array
    {
        return $this->lesen()['client'];
    }

    /**
     * Wer bei der Attrappe „angemeldet“ ist: sub, preferred_username, name,
     * email, email_verified, groups, amr, auth_time (Vorgabe: jetzt).
     */
    public function benutzer(array $b): void
    {
        $jetzt = $this->jetzt();
        $this->aendern(static function (array &$z) use ($b, $jetzt): void {
            $z['benutzer'] = $b + ['auth_time' => $jetzt];
        });
    }

    /** Abweichungen setzen (null entfernt eine); zuruecksetzen() nimmt alle weg. */
    public function steuern(array $aenderung): void
    {
        $this->aendern(static function (array &$z) use ($aenderung): void {
            foreach ($aenderung as $k => $v) {
                if ($v === null) {
                    unset($z['steuern'][$k]);
                } else {
                    $z['steuern'][$k] = $v;
                }
            }
        });
    }

    public function zuruecksetzen(): void
    {
        $this->aendern(static function (array &$z): void {
            $z['steuern'] = [];
        });
    }

    /** Für den Server: die Uhr um $sekunden verstellen (im Prozess: $uhr). */
    public function versatz(int $sekunden): void
    {
        $this->aendern(static function (array &$z) use ($sekunden): void {
            $z['versatz'] = $sekunden;
        });
    }

    /**
     * Je Anfrage: pfad, methode, client_auth (basic|post|keine), client_ok,
     * pkce_ok, prompt, max_age, scope, redirect_uri, response_mode, hint_ok,
     * post_logout_redirect_uri – nie Secret, Code oder Token.
     *
     * @return list<array<string,mixed>>
     */
    public function protokoll(): array
    {
        return $this->lesen()['protokoll'];
    }

    /**
     * Eine Anfrage beantworten.
     *
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $post
     * @param array<string,string> $kopf
     * @return array{status:int, kopf:array<string,string>, rumpf:string}
     */
    public function bearbeiten(string $methode, string $pfad, array $query, array $post, array $kopf): array
    {
        $methode = strtoupper($methode);
        $kopf = array_change_key_case($kopf, CASE_LOWER);
        $basis = '/application/o/';
        return match (true) {
            $pfad === $basis . self::SLUG . '/.well-known/openid-configuration' => $this->discovery(),
            $pfad === $basis . 'authorize/'                                      => $this->authorize($query),
            $pfad === $basis . 'token/' && $methode === 'POST'                   => $this->token($post, $kopf),
            $pfad === $basis . 'userinfo/'                                       => $this->userinfo($kopf),
            $pfad === $basis . self::SLUG . '/jwks/'                             => $this->jwks(),
            $pfad === $basis . self::SLUG . '/end-session/'                      => $this->endSession($query),
            default => ['status' => 404, 'kopf' => ['Content-Type' => 'text/html'], 'rumpf' => '<h1>Not Found</h1>'],
        };
    }

    /**
     * Für Http::$attrappe: fn($methode, $url, $optionen) → wie Http::anfrage.
     * Eine andere Herkunft als die der Attrappe ist „nicht erreichbar“; die
     * Option „max“ wird beachtet (Nummer 63 wie CURLE_FILESIZE_EXCEEDED).
     */
    public function http(): \Closure
    {
        return function (string $methode, string $url, array $optionen = []): array {
            $p = parse_url($url);
            $b = parse_url($this->lesen()['basis']);
            $herkunft = static fn (array $u): string => ($u['scheme'] ?? '') . '://' . strtolower($u['host'] ?? '')
                . ':' . ($u['port'] ?? (($u['scheme'] ?? '') === 'https' ? 443 : 80));
            if (!is_array($p) || !is_array($b) || $herkunft($p) !== $herkunft($b)) {
                return ['ok' => false, 'status' => 0, 'rumpf' => '', 'fehler' => 'Could not resolve host: ' . (is_array($p) ? ($p['host'] ?? '') : ''), 'nummer' => 6];
            }
            parse_str((string) ($p['query'] ?? ''), $query);
            $post = [];
            if (isset($optionen['rumpf'])) {
                parse_str((string) $optionen['rumpf'], $post);
            }
            $a = $this->bearbeiten($methode, (string) ($p['path'] ?? '/'), $query, $post, (array) ($optionen['kopf'] ?? []));
            $max = isset($optionen['max']) ? (int) $optionen['max'] : 0;
            if ($max > 0 && strlen($a['rumpf']) > $max) {
                return ['ok' => false, 'status' => $a['status'], 'rumpf' => '', 'fehler' => 'Die Antwort ist zu groß – abgebrochen.', 'nummer' => 63];
            }
            return ['ok' => true, 'status' => $a['status'], 'rumpf' => $a['rumpf'], 'fehler' => '', 'nummer' => 0];
        };
    }

    /**
     * Ein ID-Token mit genau diesen Claims. $kopf ergänzt oder ersetzt den
     * Kopf (null entfernt eine Angabe). $mit: rsa|rsa-neu|ec|ec-neu|rsa-1024
     * (signiert mit diesem Schlüssel, dessen kid im Kopf), hmac:<schlüssel>
     * (HS256, kid rsa-1 – die klassische Verwechslung), none (mit einem
     * Schein-Teil als Signatur, damit nicht schon das Format scheitert).
     */
    public function idToken(array $claims, array $kopf = [], string $mit = 'rsa'): string
    {
        [$alg, $kid] = match (true) {
            isset(self::SCHLUESSEL[$mit]) => [self::SCHLUESSEL[$mit][2], self::SCHLUESSEL[$mit][1]],
            str_starts_with($mit, 'hmac:') => ['HS256', 'rsa-1'],
            $mit === 'none' => ['none', null],
            default => throw new InvalidArgumentException('OidcAttrappe: unbekannter Schlüssel ' . $mit),
        };
        $h = ['alg' => $alg, 'typ' => 'JWT'] + ($kid === null ? [] : ['kid' => $kid]);
        foreach ($kopf as $k => $v) {
            if ($v === null) {
                unset($h[$k]);
            } else {
                $h[$k] = $v;
            }
        }
        return $this->signieren(self::json($h), self::json($claims), $mit);
    }

    /** Ein JWS aus rohem JSON für Kopf und Claims (für Format-Prüfungen: Listen, Tiefe …). */
    public function signieren(string $kopfJson, string $claimsJson, string $mit = 'rsa'): string
    {
        $eingabe = self::b64($kopfJson) . '.' . self::b64($claimsJson);
        if ($mit === 'none') {
            return $eingabe . '.' . self::b64('none');
        }
        if (str_starts_with($mit, 'hmac:')) {
            return $eingabe . '.' . self::b64(hash_hmac('sha256', $eingabe, substr($mit, 5), true));
        }
        $schluessel = openssl_pkey_get_private((string) file_get_contents($this->ordner . '/' . self::SCHLUESSEL[$mit][0]));
        if ($schluessel === false || !openssl_sign($eingabe, $sig, $schluessel, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OidcAttrappe: Signieren mit ' . $mit . ' ging nicht.');
        }
        if (self::SCHLUESSEL[$mit][2] === 'ES256') {
            $sig = self::derZuRs($sig);
        }
        return $eingabe . '.' . self::b64($sig);
    }

    /** Der öffentliche Schlüssel als JWK (wie im JWKS). */
    public function jwk(string $mit): array
    {
        [$datei, $kid, $alg] = self::SCHLUESSEL[$mit];
        $d = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($this->ordner . '/' . $datei)));
        if ($alg === 'ES256') {
            return ['kty' => 'EC', 'alg' => 'ES256', 'use' => 'sig', 'kid' => $kid, 'crv' => 'P-256',
                'x' => self::b64(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => self::b64(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))];
        }
        $jwk = ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => self::b64($d['rsa']['n']), 'e' => self::b64($d['rsa']['e'])];
        if ($mit === 'rsa') {
            // Authentik schickt das Zertifikat mit – PowerManagement liest es nie
            // (hier absichtlich kein passendes, nur Bytes in der richtigen Form).
            $jwk['x5c'] = [base64_encode("\x30\x82\x01\x0a" . random_bytes(266))];
            $jwk['x5t'] = self::b64(random_bytes(20));
        }
        return $jwk;
    }

    /** DER SEQUENCE{INTEGER r, INTEGER s} → R‖S je 32 Byte (JWS-Form von ES256). */
    public static function derZuRs(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos += ord($der[1]) & 0x7f;
        }
        $teile = [];
        for ($i = 0; $i < 2; $i++) {
            $laenge = ord($der[$pos + 1]);
            $wert = ltrim(substr($der, $pos + 2, $laenge), "\0");
            $teile[] = str_pad($wert, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $laenge;
        }
        return $teile[0] . $teile[1];
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------ Endpunkte

    private function discovery(): array
    {
        $this->protokollieren(['pfad' => 'discovery', 'methode' => 'GET']);
        $z = $this->lesen();
        $s = $z['steuern'];
        if (!empty($s['discovery_404'])) {
            return ['status' => 404, 'kopf' => ['Content-Type' => 'text/html'], 'rumpf' => '<h1>Not Found</h1>'];
        }
        if ($this->trifft($s['umleiten'] ?? null, 'discovery')) {
            return $this->umleitung($z['basis'] . '/if/flow/default-authentication-flow/');
        }
        $b = $z['basis'] . '/application/o/';
        $d = [
            'issuer'                                => $this->aussteller(),
            'authorization_endpoint'                => $b . 'authorize/',
            'token_endpoint'                        => $b . 'token/',
            'userinfo_endpoint'                     => $b . 'userinfo/',
            'end_session_endpoint'                  => $b . self::SLUG . '/end-session/',
            'introspection_endpoint'                => $b . 'introspect/',
            'revocation_endpoint'                   => $b . 'revoke/',
            'device_authorization_endpoint'         => $b . 'device/',
            'response_types_supported'              => ['code', 'id_token', 'id_token token', 'code token', 'code id_token', 'code id_token token'],
            'response_modes_supported'              => ['query', 'fragment', 'form_post'],
            'jwks_uri'                              => $b . self::SLUG . '/jwks/',
            'grant_types_supported'                 => ['authorization_code', 'refresh_token', 'implicit', 'client_credentials', 'password', 'urn:ietf:params:oauth:grant-type:device_code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'subject_types_supported'               => ['public'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'acr_values_supported'                  => ['goauthentik.io/providers/oauth2/default'],
            'scopes_supported'                      => ['openid', 'email', 'profile'],
            'request_parameter_supported'           => false,
            'claims_supported'                      => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'acr', 'amr', 'nonce', 'email', 'email_verified', 'name', 'given_name', 'preferred_username', 'nickname', 'groups'],
            'claims_parameter_supported'            => false,
            'code_challenge_methods_supported'      => ['plain', 'S256'],
        ];
        foreach ((array) ($s['discovery'] ?? []) as $k => $v) {
            if ($v === null) {
                unset($d[$k]);
            } else {
                $d[$k] = $v;
            }
        }
        return $this->jsonAntwort(200, $d, 'discovery');
    }

    private function jwks(): array
    {
        $this->protokollieren(['pfad' => 'jwks', 'methode' => 'GET']);
        $s = $this->lesen()['steuern'];
        if ($this->trifft($s['umleiten'] ?? null, 'jwks')) {
            return $this->umleitung($this->lesen()['basis'] . '/');
        }
        $keys = !empty($s['schluessel_wechseln']) ? [$this->jwk('rsa-neu'), $this->jwk('ec-neu')] : [$this->jwk('rsa'), $this->jwk('ec')];
        return $this->jsonAntwort(200, ['keys' => $keys], 'jwks');
    }

    private function authorize(array $q): array
    {
        $jetzt = $this->jetzt();
        $text = static fn (mixed $v): ?string => is_string($v) ? $v : null;
        $eintrag = [
            'pfad' => 'authorize', 'methode' => 'GET',
            'prompt' => $text($q['prompt'] ?? null), 'max_age' => $text($q['max_age'] ?? null), 'scope' => $text($q['scope'] ?? null),
            'redirect_uri' => $text($q['redirect_uri'] ?? null), 'response_mode' => $text($q['response_mode'] ?? null),
            'response_type' => $text($q['response_type'] ?? null), 'code_challenge_method' => $text($q['code_challenge_method'] ?? null),
            'nonce_da' => is_string($q['nonce'] ?? null) && $q['nonce'] !== '',
        ];
        $ergebnis = $this->aendern(function (array &$z) use ($q, $text, $jetzt, &$eintrag): array {
            $s = $z['steuern'];
            $redirect = $text($q['redirect_uri'] ?? null);
            if ($text($q['client_id'] ?? null) !== $z['client']['id']) {
                $eintrag['fehler'] = 'client_id';
                return ['status' => 400, 'kopf' => ['Content-Type' => 'text/html'], 'rumpf' => '<h1>Application not found</h1>'];
            }
            if ($redirect === null || ($z['redirect_uris'] !== [] && !in_array($redirect, $z['redirect_uris'], true))) {
                $eintrag['fehler'] = 'redirect_uri';
                return ['status' => 400, 'kopf' => ['Content-Type' => 'text/html'], 'rumpf' => '<h1>Redirect URI Error</h1>'];
            }
            if ($z['redirect_uris'] === []) {
                $z['redirect_uris'] = [$redirect];   // wie Authentik: die erste benutzte gilt
            }
            $zurueck = function (array $p) use ($redirect, $q, $s): array {
                $p['state'] = $q['state'] ?? '';
                if (!empty($s['iss_param'])) {
                    $p['iss'] = is_string($s['iss_param']) ? $s['iss_param'] : $this->aussteller();
                }
                return $this->umleitung($redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($p, '', '&', PHP_QUERY_RFC3986));
            };
            if (!empty($s['authorize_fehler'])) {
                $eintrag['fehler'] = 'gesteuert';
                return $zurueck(['error' => (string) $s['authorize_fehler'],
                    'error_description' => (string) ($s['authorize_beschreibung'] ?? 'The resource owner or authorization server denied the request')]);
            }
            if ($text($q['response_type'] ?? null) !== 'code') {
                $eintrag['fehler'] = 'response_type';
                return $zurueck(['error' => 'unsupported_response_type', 'error_description' => 'Only code is supported here']);
            }
            if (!in_array('openid', explode(' ', (string) $text($q['scope'] ?? null)), true)) {
                $eintrag['fehler'] = 'scope';
                return $zurueck(['error' => 'invalid_scope', 'error_description' => 'openid fehlt']);
            }
            if ($text($q['code_challenge_method'] ?? null) !== 'S256' || !is_string($q['code_challenge'] ?? null) || $q['code_challenge'] === '') {
                $eintrag['fehler'] = 'pkce';
                return $zurueck(['error' => 'invalid_request', 'error_description' => 'PKCE S256 erwartet']);
            }
            if (!is_array($z['benutzer'])) {
                $eintrag['fehler'] = 'nicht_angemeldet';
                return $zurueck(['error' => 'login_required', 'error_description' => 'Niemand angemeldet']);
            }
            if (str_contains((string) $text($q['prompt'] ?? null), 'login') && empty($s['prompt_ignorieren'])) {
                $z['benutzer']['auth_time'] = $jetzt;
            }
            $code = 'code-' . (is_string($s['kennung'] ?? null) ? $s['kennung'] . '-' : '') . bin2hex(random_bytes(16));
            $z['codes'][$code] = [
                'redirect_uri' => $redirect, 'nonce' => $text($q['nonce'] ?? null), 'code_challenge' => $q['code_challenge'],
                'scope' => $text($q['scope'] ?? null), 'at' => $jetzt, 'benutzer' => $z['benutzer'],
            ];
            return $zurueck(['code' => $code]);
        });
        $this->protokollieren($eintrag);
        return $ergebnis;
    }

    private function token(array $post, array $kopf): array
    {
        $jetzt = $this->jetzt();
        $eintrag = ['pfad' => 'token', 'methode' => 'POST', 'client_auth' => 'keine', 'client_ok' => false, 'pkce_ok' => null,
            'redirect_uri' => is_string($post['redirect_uri'] ?? null) ? $post['redirect_uri'] : null,
            'grant_type' => is_string($post['grant_type'] ?? null) ? $post['grant_type'] : null];
        $ergebnis = $this->aendern(function (array &$z) use ($post, $kopf, $jetzt, &$eintrag): array {
            $s = $z['steuern'];
            if ($this->trifft($s['umleiten'] ?? null, 'token')) {
                return $this->umleitung($z['basis'] . '/');
            }
            $id = null;
            $secret = null;
            $auth = (string) ($kopf['authorization'] ?? '');
            if (str_starts_with($auth, 'Basic ')) {
                $roh = (string) base64_decode(substr($auth, 6), true);
                [$id, $secret] = array_pad(explode(':', $roh, 2), 2, '');
                // Authentik: unquote, nicht unquote_plus – „+“ bleibt „+“.
                [$id, $secret] = [rawurldecode($id), rawurldecode($secret)];
                $eintrag['client_auth'] = 'basic';
            } elseif (isset($post['client_id'])) {
                $id = is_string($post['client_id']) ? $post['client_id'] : '';
                $secret = is_string($post['client_secret'] ?? null) ? $post['client_secret'] : '';
                $eintrag['client_auth'] = 'post';
            }
            $eintrag['client_ok'] = $id === $z['client']['id'] && is_string($secret) && hash_equals($z['client']['secret'], $secret);
            $fehler = fn (int $status, string $e): array => $this->jsonAntwort($status, ['error' => $e, 'error_description' => 'Attrappe: ' . $e], 'token');
            if (!$eintrag['client_ok']) {
                return $fehler(401, 'invalid_client');
            }
            if (($post['grant_type'] ?? null) !== 'authorization_code') {
                return $fehler(400, 'unsupported_grant_type');
            }
            if (!empty($s['token_fehler'])) {
                return $fehler(400, (string) $s['token_fehler']);
            }
            $code = is_string($post['code'] ?? null) ? $post['code'] : '';
            $c = $z['codes'][$code] ?? null;
            unset($z['codes'][$code]);
            if (!is_array($c) || $jetzt - (int) $c['at'] > 60) {
                return $fehler(400, 'invalid_grant');
            }
            if (($post['redirect_uri'] ?? null) !== $c['redirect_uri']) {
                return $fehler(400, 'invalid_grant');
            }
            $verifier = is_string($post['code_verifier'] ?? null) ? $post['code_verifier'] : '';
            $eintrag['pkce_ok'] = $verifier !== '' && hash_equals((string) $c['code_challenge'], self::b64(hash('sha256', $verifier, true)));
            if (!$eintrag['pkce_ok']) {
                return $fehler(400, 'invalid_grant');
            }
            $b = $c['benutzer'];
            $profil = array_intersect_key($b, array_flip(['preferred_username', 'name', 'email', 'email_verified', 'groups', 'nickname', 'given_name']));
            $access = 'at-' . (is_string($s['kennung'] ?? null) ? $s['kennung'] . '-' : '') . bin2hex(random_bytes(16));
            $z['tokens'][$access] = ['bis' => $jetzt + 300, 'claims' => ['sub' => (string) $b['sub']] + $profil];
            $claims = [
                'iss'       => $s['iss'] ?? $this->ausstellerAus($z),
                'sub'       => (string) $b['sub'],
                'aud'       => $s['aud'] ?? $z['client']['id'],
                'exp'       => $jetzt + (int) ($s['exp_delta'] ?? 300),
                'iat'       => $jetzt + (int) ($s['iat_delta'] ?? 0),
                'auth_time' => (int) $b['auth_time'],
                'acr'       => 'goauthentik.io/providers/oauth2/default',
                'sid'       => hash('sha256', (string) $b['sub'] . $z['client']['id']),
            ];
            if (isset($b['amr'])) {
                $claims['amr'] = $b['amr'];
            }
            if (isset($s['nbf_delta'])) {
                $claims['nbf'] = $jetzt + (int) $s['nbf_delta'];
            }
            if (isset($s['azp'])) {
                $claims['azp'] = $s['azp'];
            }
            $nonce = $s['nonce'] ?? null;
            if ($nonce === 'falsch') {
                $claims['nonce'] = 'falsch-' . bin2hex(random_bytes(4));
            } elseif ($nonce !== 'fehlt' && $c['nonce'] !== null) {
                $claims['nonce'] = $c['nonce'];
            }
            if (($s['claims_im_id_token'] ?? true) !== false) {
                $claims += $profil;
            }
            $mit = match ($s['alg'] ?? 'RS256') {
                'ES256' => !empty($s['schluessel_wechseln']) ? 'ec-neu' : 'ec',
                'HS256' => 'hmac:' . $z['client']['secret'],
                'none'  => 'none',
                default => !empty($s['schluessel_wechseln']) ? 'rsa-neu' : 'rsa',
            };
            $kopfAenderung = [];
            if (array_key_exists('kid', $s)) {
                $kopfAenderung['kid'] = $s['kid'] === false ? null : $s['kid'];
            }
            if (!empty($s['crit'])) {
                $kopfAenderung['crit'] = ['exp'];
            }
            $idToken = $this->idToken($claims, $kopfAenderung, $mit);
            if (!empty($s['signatur_kaputt'])) {
                $teile = explode('.', $idToken);
                $sig = base64_decode(strtr($teile[2], '-_', '+/'));
                $sig[0] = chr(ord($sig[0]) ^ 0x01);
                $teile[2] = self::b64($sig);
                $idToken = implode('.', $teile);
            }
            if (!empty($s['fuenf_teile'])) {
                $idToken .= '.' . self::b64('x') . '.' . self::b64('y');
            }
            return $this->jsonAntwort(200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 300,
                'id_token' => $idToken, 'scope' => (string) $c['scope']], 'token');
        });
        $this->protokollieren($eintrag);
        return $ergebnis;
    }

    private function userinfo(array $kopf): array
    {
        $z = $this->lesen();
        $s = $z['steuern'];
        $this->protokollieren(['pfad' => 'userinfo', 'methode' => 'GET']);
        if ($this->trifft($s['umleiten'] ?? null, 'userinfo')) {
            return $this->umleitung($z['basis'] . '/');
        }
        $auth = (string) ($kopf['authorization'] ?? '');
        $t = str_starts_with($auth, 'Bearer ') ? $z['tokens'][substr($auth, 7)] ?? null : null;
        if (!is_array($t) || $this->jetzt() > (int) $t['bis']) {
            return $this->jsonAntwort(401, ['error' => 'invalid_token'], 'userinfo');
        }
        $claims = $t['claims'];
        if (isset($s['userinfo_sub'])) {
            $claims['sub'] = (string) $s['userinfo_sub'];
        }
        return $this->jsonAntwort(200, $claims, 'userinfo');
    }

    private function endSession(array $q): array
    {
        $z = $this->lesen();
        $hinweis = is_string($q['id_token_hint'] ?? null) ? $q['id_token_hint'] : '';
        $ruecksprung = is_string($q['post_logout_redirect_uri'] ?? null) ? $q['post_logout_redirect_uri'] : null;
        $ok = $this->hinweisGueltig($hinweis);
        $this->protokollieren(['pfad' => 'end-session', 'methode' => 'GET', 'hint_ok' => $ok,
            'client_id_ok' => ($q['client_id'] ?? null) === $z['client']['id'], 'post_logout_redirect_uri' => $ruecksprung]);
        if ($ok && $ruecksprung !== null && in_array($ruecksprung, $z['logout_uris'], true)) {
            return $this->umleitung($ruecksprung);
        }
        return ['status' => 200, 'kopf' => ['Content-Type' => 'text/html; charset=utf-8'], 'rumpf' => '<h1>Abgemeldet (Attrappe)</h1>'];
    }

    // ------------------------------------------------------------ intern

    /** Signatur eines ID-Tokens mit einem eigenen Schlüssel – exp egal (wie Authentik beim id_token_hint). */
    private function hinweisGueltig(string $jwt): bool
    {
        $teile = explode('.', $jwt);
        if (count($teile) !== 3) {
            return false;
        }
        $kopf = json_decode((string) base64_decode(strtr($teile[0], '-_', '+/')), true);
        if (!is_array($kopf) || !is_string($kopf['kid'] ?? null)) {
            return false;
        }
        foreach (self::SCHLUESSEL as $mit => [$datei, $kid, $alg]) {
            if ($kid !== $kopf['kid'] || ($kopf['alg'] ?? null) !== $alg) {
                continue;
            }
            $sig = (string) base64_decode(strtr($teile[2], '-_', '+/'));
            if ($alg === 'ES256') {
                if (strlen($sig) !== 64) {
                    return false;
                }
                $int = static function (string $b): string {
                    $b = ltrim($b, "\0");
                    if ($b === '' || ord($b[0]) > 0x7f) {
                        $b = "\0" . $b;
                    }
                    return "\x02" . chr(strlen($b)) . $b;
                };
                $inhalt = $int(substr($sig, 0, 32)) . $int(substr($sig, 32));
                $sig = "\x30" . chr(strlen($inhalt)) . $inhalt;
            }
            $pub = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($this->ordner . '/' . $datei)))['key'];
            return openssl_verify($teile[0] . '.' . $teile[1], $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
        }
        return false;
    }

    private function jsonAntwort(int $status, array $daten, string $endpunkt): array
    {
        $s = $this->lesen()['steuern'];
        if ($this->trifft($s['gross'] ?? null, $endpunkt)) {
            $daten['fuellung'] = str_repeat('x', 70000);
        }
        return ['status' => $status, 'kopf' => ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], 'rumpf' => self::json($daten)];
    }

    private function umleitung(string $ziel): array
    {
        return ['status' => 302, 'kopf' => ['Location' => $ziel], 'rumpf' => ''];
    }

    /** Gilt eine Abweichung (true = überall, Name oder Liste von Namen) für diesen Endpunkt? */
    private function trifft(mixed $wert, string $endpunkt): bool
    {
        return $wert === true || $wert === $endpunkt || (is_array($wert) && in_array($endpunkt, $wert, true));
    }

    private function ausstellerAus(array $z): string
    {
        return $z['basis'] . '/application/o/' . self::SLUG . '/';
    }

    private function jetzt(): int
    {
        return self::$uhr !== null ? (int) (self::$uhr)() : time() + (int) ($this->lesen()['versatz'] ?? 0);
    }

    private function protokollieren(array $eintrag): void
    {
        $this->aendern(static function (array &$z) use ($eintrag): void {
            $z['protokoll'][] = $eintrag;
        });
    }

    private function lesen(): array
    {
        $roh = @file_get_contents($this->ordner . '/zustand.json');
        $z = is_string($roh) ? json_decode($roh, true) : null;
        if (!is_array($z)) {
            throw new RuntimeException('OidcAttrappe: Zustand unlesbar');
        }
        return $z;
    }

    private function schreiben(array $z): void
    {
        $tmp = $this->ordner . '/zustand.json.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, self::json($z));
        rename($tmp, $this->ordner . '/zustand.json');
    }

    /**
     * Den Zustand unter Sperre lesen, ändern und schreiben (der Server kann
     * mehrere Anfragen zugleich bearbeiten).
     */
    private function aendern(callable $aenderung): mixed
    {
        $sperre = fopen($this->ordner . '/zustand.lock', 'c');
        if ($sperre === false) {
            throw new RuntimeException('OidcAttrappe: keine Sperre');
        }
        try {
            flock($sperre, LOCK_EX);
            $z = $this->lesen();
            $ergebnis = $aenderung($z);
            $this->schreiben($z);
            return $ergebnis;
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }

    private static function json(mixed $wert): string
    {
        return (string) json_encode($wert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

// Als Router des eingebauten Servers: jede Anfrage an die Attrappe.
if (PHP_SAPI === 'cli-server' && is_string(getenv('OIDC_ATTRAPPE')) && getenv('OIDC_ATTRAPPE') !== '') {
    $idp = OidcAttrappe::laden((string) getenv('OIDC_ATTRAPPE'));
    $kopf = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $antwort = $idp->bearbeiten((string) $_SERVER['REQUEST_METHOD'], (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
        $_GET, $_POST, $kopf);
    http_response_code($antwort['status']);
    foreach ($antwort['kopf'] as $name => $wert) {
        header($name . ': ' . $wert);
    }
    echo $antwort['rumpf'];
    return true;
}
