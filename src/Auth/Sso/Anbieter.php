<?php
declare(strict_types=1);

namespace App\Auth\Sso;

use App\Messung\Http;
use App\Support\Logger;
use App\Support\Settings;

/**
 * Der Anmeldedienst (OpenID Provider) aus Sicht dieser Anwendung: Discovery,
 * Signaturschlüssel (JWKS), Code einlösen, Userinfo, Abmelde-Adresse und
 * „Verbindung prüfen“.
 *
 * Netz nur über Http::anfrage – nie mit Umleitungen (ein 3xx ist ein Fehler),
 * höchstens 64 KiB je Antwort, 8 s je Anfrage. Alle Endpunkte müssen https
 * und dieselbe Herkunft wie der Aussteller haben (Einstellungen::
 * gleicheHerkunft): Das Secret geht nur dorthin. Ins Log kommen nur Schritt,
 * Status und Dauer – nie Adresse mit Query, Kopfzeilen oder Rumpf.
 */
final class Anbieter
{
    public const MAX_ANTWORT = 65536, ZEIT = 8, CACHE = 86400, JWKS_NEU_FRUEHESTENS = 300;
    /** Signaturverfahren, die PowerManagement annimmt. */
    public const ALGS = ['RS256', 'ES256'];

    private const T_UMLEITUNG = 'Der Anmeldedienst leitet um – Issuer genau so eintragen, wie Authentik ihn nennt.';
    private const T_404 = 'Discovery nicht gefunden – in Authentik den Issuer-Modus ‚Each provider has a different issuer‘ (Standard) nutzen und den Issuer genau kopieren.';
    private const T_HS256 = 'Authentik signiert ohne Schlüssel (HS256) – in Authentik einen Signaturschlüssel (Signing Key) wählen.';
    private const T_ZU_GROSS = 'Die Antwort des Anmeldedienstes ist größer als 64 KB – Issuer prüfen.';
    private const KOPF = ['Accept' => 'application/json', 'User-Agent' => 'PowerManagement'];

    // ------------------------------------------------------------ Discovery

    /**
     * Die geprüfte Discovery – aus dem Zwischenspeicher (≤ 24 h) oder frisch
     * geholt und dann gespeichert.
     *
     * @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:?string,jwks_uri:string,end_session_endpoint:?string,algs:list<string>,auth_methode:'basic'|'post',s256:?bool,am:int}
     */
    public static function discovery(bool $frisch = false): array
    {
        $aussteller = Einstellungen::aussteller();
        if (Einstellungen::ausstellerFehler($aussteller) !== null) {
            throw new SsoFehler('einrichtung', 'Die Anmeldung über SSO ist nicht eingerichtet.');
        }
        if (!$frisch) {
            $c = self::discoveryCache();
            if ($c !== null && Einstellungen::jetzt() - $c['am'] <= self::CACHE) {
                return $c;
            }
        }
        $d = self::discoveryHolen($aussteller);
        self::discoverySpeichern($d);
        return $d;
    }

    /**
     * Die Discovery aus dem Zwischenspeicher, wenn sie höchstens 24 h alt ist –
     * ohne Netz, wirft nie; sonst null. So weiß der Aufrufer, ob discovery()
     * warten würde (und gibt dann vorher die Sitzung frei).
     */
    public static function discoveryGespeichert(): ?array
    {
        try {
            $c = self::discoveryCache();
            return $c !== null && Einstellungen::jetzt() - $c['am'] <= self::CACHE ? $c : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reine Prüfung einer Discovery-Antwort – wirft SsoFehler('discovery', …).
     *
     * @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:?string,jwks_uri:string,end_session_endpoint:?string,algs:list<string>,auth_methode:'basic'|'post',s256:?bool}
     */
    public static function discoveryPruefen(array $d, string $aussteller): array
    {
        $fehler = static fn (string $text): SsoFehler => new SsoFehler('discovery', $text);
        if (!is_string($d['issuer'] ?? null) || !hash_equals($aussteller, $d['issuer'])) {
            throw $fehler('Der Anmeldedienst nennt einen anderen Aussteller („' . self::kurz(is_string($d['issuer'] ?? null) ? $d['issuer'] : '') . '“)'
                . ' – Issuer genau kopieren; ein Proxy vor Authentik muss Host und X-Forwarded-Proto weitergeben.');
        }
        $soll = Einstellungen::herkunft($aussteller);
        $endpunkte = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $feld) {
            if (!is_string($d[$feld] ?? null) || $d[$feld] === '') {
                throw $fehler('In der Discovery fehlt ' . $feld . '.');
            }
            $endpunkte[$feld] = $d[$feld];
        }
        foreach (['userinfo_endpoint', 'end_session_endpoint'] as $feld) {
            $endpunkte[$feld] = is_string($d[$feld] ?? null) && $d[$feld] !== '' ? $d[$feld] : null;
        }
        foreach ($endpunkte as $feld => $url) {
            if ($url !== null && ($soll === null || Einstellungen::herkunft($url) !== $soll)) {
                throw $fehler($feld . ' liegt nicht unter https:// auf demselben Host wie der Aussteller – PowerManagement schickt das Secret nur dorthin.');
            }
        }
        $liste = static fn (string $feld): ?array => array_key_exists($feld, $d)
            ? (is_array($d[$feld]) ? array_values(array_filter($d[$feld], 'is_string')) : []) : null;
        $arten = $liste('response_types_supported');
        if ($arten !== null && !in_array('code', $arten, true)) {
            throw $fehler('Der Anmeldedienst bietet den Code-Flow (response_type „code“) nicht an.');
        }
        $pkce = $liste('code_challenge_methods_supported');
        if ($pkce !== null && !in_array('S256', $pkce, true)) {
            throw $fehler('Der Anmeldedienst bietet PKCE mit S256 nicht an.');
        }
        $grants = $liste('grant_types_supported');
        if ($grants !== null && !in_array('authorization_code', $grants, true)) {
            throw $fehler('Der Anmeldedienst erlaubt den Grant „Authorization Code“ nicht.');
        }
        $angeboten = $liste('id_token_signing_alg_values_supported') ?? [];
        $algs = array_values(array_filter(self::ALGS, static fn (string $a): bool => in_array($a, $angeboten, true)));
        if ($algs === []) {
            throw $fehler(in_array('HS256', $angeboten, true) || $angeboten === []
                ? self::T_HS256
                : 'Der Anmeldedienst signiert nur mit ' . self::kurz(implode(', ', $angeboten)) . ' – PowerManagement nimmt RS256 und ES256.');
        }
        $methoden = $liste('token_endpoint_auth_methods_supported');
        if ($methoden === null || in_array('client_secret_basic', $methoden, true)) {
            $methode = 'basic';
        } elseif (in_array('client_secret_post', $methoden, true)) {
            $methode = 'post';
        } else {
            throw $fehler('Der Token-Endpunkt nimmt weder client_secret_basic noch client_secret_post an.');
        }
        return [
            'issuer'                 => $d['issuer'],
            'authorization_endpoint' => $endpunkte['authorization_endpoint'],
            'token_endpoint'         => $endpunkte['token_endpoint'],
            'userinfo_endpoint'      => $endpunkte['userinfo_endpoint'],
            'jwks_uri'               => $endpunkte['jwks_uri'],
            'end_session_endpoint'   => $endpunkte['end_session_endpoint'],
            'algs'                   => $algs,
            'auth_methode'           => $methode,
            's256'                   => $pkce === null ? null : true,
        ];
    }

    // ------------------------------------------------------------ Schlüssel

    /**
     * Schlüssel zu kid/kty aus dem Zwischenspeicher; unbekannt (oder der
     * Speicher älter als 24 h) → JWKS einmal neu, frühestens alle 300 s
     * (global – viele erfundene kids lösen keine Flut von Abrufen aus).
     * Ohne kid: nur, wenn es genau einen Schlüssel dieser Art gibt.
     *
     * @return array{kid:string,kty:string,alg:string,pem:string}|null
     */
    public static function schluessel(string $kid, string $kty): ?array
    {
        $jetzt = Einstellungen::jetzt();
        [$liste, $am] = self::jwksCache();
        $treffer = $liste === null ? null : self::finden($liste, $kid, $kty);
        if ($treffer !== null && $am !== null && $jetzt - $am <= self::CACHE) {
            return $treffer;
        }
        $zuletzt = (string) Settings::get(Einstellungen::JWKS_NEU_AM, '');
        if (ctype_digit($zuletzt) && $jetzt - (int) $zuletzt < self::JWKS_NEU_FRUEHESTENS && $jetzt >= (int) $zuletzt) {
            return $treffer;
        }
        Settings::put(Einstellungen::JWKS_NEU_AM, (string) $jetzt);
        try {
            $neu = self::jwksHolen(self::discovery()['jwks_uri']);
        } catch (SsoFehler $e) {
            if ($treffer !== null) {
                Logger::info('sso', ['schritt' => 'jwks', 'grund' => 'alter Stand weiter benutzt']);
                return $treffer;
            }
            throw $e;
        }
        self::jwksSpeichern($neu);
        return self::finden($neu, $kid, $kty);
    }

    /**
     * Die brauchbaren Signaturschlüssel eines JWKS – unbrauchbare Einträge
     * werden still übergangen; $uebergangen nennt sie mit Grund (für pruefen()).
     *
     * @param list<string>|null $uebergangen
     * @return list<array{kid:string,kty:string,alg:string,pem:string}>
     */
    public static function jwksPruefen(array $jwks, ?array &$uebergangen = null): array
    {
        $uebergangen = [];
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new SsoFehler('jwks', 'Das JWKS des Anmeldedienstes enthält keine Schlüsselliste.');
        }
        $out = [];
        foreach (array_slice($keys, 0, 50) as $jwk) {
            if (!is_array($jwk)) {
                $uebergangen[] = 'ein Eintrag ohne Schlüssel';
                continue;
            }
            $s = Jwk::schluessel($jwk);
            if ($s === null) {
                $uebergangen[] = 'Schlüssel „' . self::kurz(is_string($jwk['kid'] ?? null) ? $jwk['kid'] : '') . '“: ' . (string) Jwk::grund($jwk);
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    // ------------------------------------------------------------ Token, Userinfo

    /**
     * Den Anmeldecode am Token-Endpunkt einlösen (mit PKCE-Verifier und
     * Client-Anmeldung). Das Access-Token braucht es nur für die Userinfo.
     *
     * @return array{id_token:string, access_token:string}
     */
    public static function codeEinloesen(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier): array
    {
        if (Einstellungen::secret() === '') {
            throw new SsoFehler('einrichtung', 'Die Anmeldung über SSO ist nicht eingerichtet (Client-Secret fehlt).');
        }
        $d = self::discovery();
        if (!Einstellungen::gleicheHerkunft($d['token_endpoint'])) {
            throw new SsoFehler('discovery', 'Der Token-Endpunkt liegt nicht beim Aussteller – PowerManagement schickt das Secret nur dorthin.');
        }
        $a = self::tokenAnfrage($d, ['grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => Einstellungen::rueckrufAdresse(), 'code_verifier' => $verifier], 'token');
        $j = $a['json'];
        if ($a['status'] !== 200) {
            throw new SsoFehler('token', self::tokenFehlerText(self::fehlerCode($j)));
        }
        if (!is_string($j['id_token'] ?? null) || $j['id_token'] === '') {
            throw new SsoFehler('token', 'Der Anmeldedienst hat kein ID-Token geschickt – ist der Scope „openid“ zugeordnet?');
        }
        if (array_key_exists('token_type', $j) && (!is_string($j['token_type']) || strcasecmp($j['token_type'], 'Bearer') !== 0)) {
            throw new SsoFehler('token', 'Der Anmeldedienst schickt eine unbekannte Token-Art.');
        }
        return ['id_token' => $j['id_token'], 'access_token' => is_string($j['access_token'] ?? null) ? $j['access_token'] : ''];
    }

    /** Die Claims vom Userinfo-Endpunkt (sub vergleicht der Aufrufer). */
    public static function userinfo(#[\SensitiveParameter] string $accessToken): array
    {
        $d = self::discovery();
        $url = $d['userinfo_endpoint'];
        if ($url === null || !Einstellungen::gleicheHerkunft($url)) {
            throw new SsoFehler('userinfo', 'Der Anmeldedienst nennt keinen Userinfo-Endpunkt – in Authentik „Include claims in id_token“ einschalten.');
        }
        if (preg_match('/^[\x21-\x7e]{1,4096}$/', $accessToken) !== 1) {
            throw new SsoFehler('userinfo', 'Der Anmeldedienst hat kein brauchbares Access-Token für die Userinfo geschickt.');
        }
        return self::holen('GET', $url, self::KOPF + ['Authorization' => 'Bearer ' . $accessToken], null, 'userinfo')['json'];
    }

    /**
     * Adresse zum Abmelden beim Anmeldedienst (end_session_endpoint mit
     * id_token_hint, client_id, bei sso.abmelde_rueckkehr auch
     * post_logout_redirect_uri) – nur aus dem geprüften Zwischenspeicher, kein
     * Netz, wirft nie. null ohne Endpunkt oder Zwischenspeicher.
     */
    public static function abmeldeAdresse(#[\SensitiveParameter] string $idTokenHinweis): ?string
    {
        try {
            $d = self::discoveryCache();
            $url = $d['end_session_endpoint'] ?? null;
            if ($url === null || !Einstellungen::gleicheHerkunft($url)
                || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $idTokenHinweis) !== 1
                || strlen($idTokenHinweis) > IdToken::MAX_LAENGE) {
                return null;
            }
            $p = ['id_token_hint' => $idTokenHinweis, 'client_id' => Einstellungen::clientId()];
            $rueckkehr = Einstellungen::abmeldeRueckkehr();
            if (Einstellungen::schalter(Einstellungen::RUECKKEHR) && $rueckkehr !== '') {
                $p['post_logout_redirect_uri'] = $rueckkehr;
            }
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------ Verbindung prüfen

    /**
     * „Verbindung prüfen“: Discovery und JWKS frisch, dazu eine Token-Probe
     * mit einem erfundenen Code – `invalid_client` heißt Client-ID oder
     * Secret falsch, `invalid_grant` heißt angenommen. Die Zwischenspeicher
     * werden nur bei Erfolg ersetzt; der Befund (ohne Geheimnisse) bleibt
     * für die Einstellungsseite stehen.
     *
     * @return array{ok:bool, befunde:list<array{ok:bool,text:string}>}
     */
    public static function pruefen(): array
    {
        $befunde = [];
        $ok = true;
        $gut = static function (string $text) use (&$befunde): void {
            $befunde[] = ['ok' => true, 'text' => $text];
        };
        $schlecht = static function (string $text) use (&$befunde, &$ok): void {
            $befunde[] = ['ok' => false, 'text' => $text];
            $ok = false;
        };

        $aussteller = Einstellungen::aussteller();
        $fehler = Einstellungen::ausstellerFehler($aussteller);
        if ($fehler !== null) {
            $schlecht($fehler);
            return self::befundSpeichern(false, $befunde);
        }
        $gut('Aussteller: ' . $aussteller);
        $fehler = Einstellungen::appUrlFehler();
        $fehler === null ? $gut('Rückruf-Adresse: ' . Einstellungen::rueckrufAdresse()) : $schlecht($fehler);
        $clientId = Einstellungen::clientId();
        $clientOk = preg_match('/^[\x21-\x7e]{1,255}$/', $clientId) === 1;
        if (!$clientOk) {
            $schlecht('Die Client-ID fehlt.');
        }
        $secret = Einstellungen::secretZustand();
        if ($secret !== 'hinterlegt') {
            $schlecht(match ($secret) {
                'andere_verbindung' => 'Das Secret gehört zu einer anderen Verbindung – bitte neu eingeben.',
                'unlesbar'          => 'Das Secret lässt sich nicht entschlüsseln (app.key geändert?) – bitte neu eingeben.',
                default             => 'Das Client-Secret fehlt.',
            });
        }

        try {
            $d = self::discoveryHolen($aussteller);
        } catch (SsoFehler $e) {
            $schlecht($e->getMessage());
            return self::befundSpeichern(false, $befunde);
        }
        $gut('Discovery gelesen – Endpunkte auf ' . (string) parse_url($d['authorization_endpoint'], PHP_URL_HOST));
        $gut('Signatur: ' . implode(', ', $d['algs']));
        $gut($d['s256'] === true ? 'PKCE mit S256 angeboten' : 'Der Anmeldedienst nennt keine PKCE-Verfahren – PowerManagement verlangt trotzdem S256.');
        $gut('Client-Anmeldung am Token-Endpunkt: ' . ($d['auth_methode'] === 'basic' ? 'client_secret_basic' : 'client_secret_post'));
        $gut($d['end_session_endpoint'] !== null ? 'Abmelden beim Anmeldedienst wird angeboten'
            : 'Der Anmeldedienst bietet kein Abmelden an (end_session_endpoint) – abgemeldet wird nur hier.');

        $schluessel = [];
        try {
            $schluessel = self::jwksHolen($d['jwks_uri'], $uebergangen);
            foreach ($uebergangen as $u) {
                $gut('Hinweis: ' . $u . ' – übergangen');
            }
            $passend = array_values(array_filter($schluessel, static fn (array $s): bool
                => in_array($s['kty'] === 'RSA' ? 'RS256' : 'ES256', $d['algs'], true)));
            if ($passend === []) {
                $schlecht('Kein brauchbarer Signaturschlüssel für ' . implode('/', $d['algs']) . ' im JWKS – in Authentik einen Signing Key (RSA ab 2048 Bit oder EC P-256) wählen.');
            } else {
                $gut(count($passend) . ' Signaturschlüssel: ' . implode(', ', array_map(static fn (array $s): string
                    => ($s['kid'] === '' ? '(ohne kid)' : self::kurz($s['kid'])) . ' (' . $s['kty'] . ')', $passend)));
            }
        } catch (SsoFehler $e) {
            $schlecht('Signaturschlüssel: ' . $e->getMessage());
        }

        if ($secret === 'hinterlegt' && $clientOk) {
            try {
                $a = self::tokenAnfrage($d, ['grant_type' => 'authorization_code', 'code' => 'pm-probe-' . bin2hex(random_bytes(8)),
                    'redirect_uri' => Einstellungen::rueckrufAdresse(), 'code_verifier' => self::b64url(random_bytes(32))], 'probe');
                $code = self::fehlerCode($a['json']);
                if ($a['status'] === 200) {
                    $schlecht('Der Token-Endpunkt hat einen erfundenen Code angenommen – so antwortet kein OIDC-Anbieter, dem PowerManagement trauen kann.');
                } elseif ($code === 'invalid_client') {
                    $schlecht('Client-ID oder Secret falsch – in Authentik nachsehen und neu eintragen.');
                } elseif ($a['status'] === 400) {
                    $gut('Client-Zugang angenommen (Probe: ' . $code . ')');
                } else {
                    $schlecht('Der Token-Endpunkt lehnt die Client-Anmeldung ab (HTTP ' . $a['status'] . ', ' . $code . ').');
                }
            } catch (SsoFehler $e) {
                $schlecht('Token-Probe: ' . $e->getMessage());
            }
        }

        if ($ok) {
            self::discoverySpeichern($d + ['am' => Einstellungen::jetzt()]);
            self::jwksSpeichern($schluessel);
        }
        return self::befundSpeichern($ok, $befunde);
    }

    /**
     * Die Zwischenspeicher fürs Anzeigen (Einstellungen::zustand) – kein Netz,
     * wirft nie.
     *
     * @return array{discovery:?array, discovery_am:?int, kids:list<string>, jwks_am:?int}
     */
    public static function zwischenspeicher(): array
    {
        try {
            $d = self::discoveryCache();
            [$liste, $am] = self::jwksCache();
            return ['discovery' => $d, 'discovery_am' => $d['am'] ?? null,
                'kids' => array_map(static fn (array $s): string => $s['kid'], $liste ?? []), 'jwks_am' => $am];
        } catch (\Throwable) {
            return ['discovery' => null, 'discovery_am' => null, 'kids' => [], 'jwks_am' => null];
        }
    }

    // ------------------------------------------------------------ intern

    /** Discovery holen und prüfen, ohne zu speichern. */
    private static function discoveryHolen(string $aussteller): array
    {
        $url = rtrim($aussteller, '/') . '/.well-known/openid-configuration';
        $j = self::holen('GET', $url, self::KOPF, null, 'discovery')['json'];
        return self::discoveryPruefen($j, $aussteller) + ['am' => Einstellungen::jetzt()];
    }

    private static function discoverySpeichern(array $d): void
    {
        $am = (int) ($d['am'] ?? Einstellungen::jetzt());
        unset($d['am']);
        Settings::put(Einstellungen::DISCOVERY, (string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Settings::put(Einstellungen::DISCOVERY_AM, (string) $am);
    }

    /** Der gespeicherte Stand, wenn er zum jetzigen Aussteller gehört und stimmig ist – sonst null. */
    private static function discoveryCache(): ?array
    {
        $d = json_decode((string) Settings::get(Einstellungen::DISCOVERY, ''), true);
        $am = (string) Settings::get(Einstellungen::DISCOVERY_AM, '');
        $aussteller = Einstellungen::aussteller();
        if (!is_array($d) || !ctype_digit($am) || !is_string($d['issuer'] ?? null) || !hash_equals($aussteller, $d['issuer'])) {
            return null;
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $feld) {
            if (!is_string($d[$feld] ?? null) || !Einstellungen::gleicheHerkunft($d[$feld])) {
                return null;
            }
        }
        foreach (['userinfo_endpoint', 'end_session_endpoint'] as $feld) {
            $wert = $d[$feld] ?? null;
            if ($wert !== null && (!is_string($wert) || !Einstellungen::gleicheHerkunft($wert))) {
                return null;
            }
            $d[$feld] = $wert;
        }
        $algs = $d['algs'] ?? null;
        if (!is_array($algs) || $algs === [] || array_diff($algs, self::ALGS) !== [] || !in_array($d['auth_methode'] ?? null, ['basic', 'post'], true)) {
            return null;
        }
        return [
            'issuer' => $d['issuer'], 'authorization_endpoint' => $d['authorization_endpoint'], 'token_endpoint' => $d['token_endpoint'],
            'userinfo_endpoint' => $d['userinfo_endpoint'], 'jwks_uri' => $d['jwks_uri'], 'end_session_endpoint' => $d['end_session_endpoint'],
            'algs' => array_values($algs), 'auth_methode' => $d['auth_methode'], 's256' => ($d['s256'] ?? null) === true ? true : null,
            'am' => (int) $am,
        ];
    }

    /**
     * @param list<string>|null $uebergangen
     * @return list<array{kid:string,kty:string,alg:string,pem:string}>
     */
    private static function jwksHolen(string $url, ?array &$uebergangen = null): array
    {
        if (!Einstellungen::gleicheHerkunft($url)) {
            throw new SsoFehler('jwks', 'jwks_uri liegt nicht beim Aussteller.');
        }
        return self::jwksPruefen(self::holen('GET', $url, self::KOPF, null, 'jwks')['json'], $uebergangen);
    }

    private static function jwksSpeichern(array $liste): void
    {
        Settings::put(Einstellungen::JWKS, (string) json_encode(array_values($liste), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Settings::put(Einstellungen::JWKS_AM, (string) Einstellungen::jetzt());
    }

    /** @return array{0:?list<array{kid:string,kty:string,alg:string,pem:string}>, 1:?int} */
    private static function jwksCache(): array
    {
        $liste = json_decode((string) Settings::get(Einstellungen::JWKS, ''), true);
        $am = (string) Settings::get(Einstellungen::JWKS_AM, '');
        if (!is_array($liste) || !array_is_list($liste)) {
            return [null, null];
        }
        $out = [];
        foreach ($liste as $s) {
            if (is_array($s) && is_string($s['kid'] ?? null) && in_array($s['kty'] ?? null, ['RSA', 'EC'], true)
                && is_string($s['alg'] ?? null) && is_string($s['pem'] ?? null)) {
                $out[] = ['kid' => $s['kid'], 'kty' => $s['kty'], 'alg' => $s['alg'], 'pem' => $s['pem']];
            }
        }
        return [$out, ctype_digit($am) ? (int) $am : null];
    }

    /** @param list<array{kid:string,kty:string,alg:string,pem:string}> $liste */
    private static function finden(array $liste, string $kid, string $kty): ?array
    {
        if ($kid !== '') {
            foreach ($liste as $s) {
                if ($s['kid'] === $kid && $s['kty'] === $kty) {
                    return $s;
                }
            }
            return null;
        }
        $passend = array_values(array_filter($liste, static fn (array $s): bool => $s['kty'] === $kty));
        return count($passend) === 1 ? $passend[0] : null;
    }

    /**
     * POST an den Token-Endpunkt mit der Client-Anmeldung, die die Discovery nennt.
     * RFC 6749 §2.3.1: erst form-kodieren, dann Base64 – mit rawurlencode, denn
     * Authentik dekodiert mit unquote, nicht unquote_plus (utils.py:157-160).
     *
     * @return array{status:int, json:array}
     */
    private static function tokenAnfrage(array $d, #[\SensitiveParameter] array $rumpf, string $schritt): array
    {
        $kopf = ['Content-Type' => 'application/x-www-form-urlencoded'] + self::KOPF;
        if ($d['auth_methode'] === 'basic') {
            $kopf['Authorization'] = 'Basic ' . base64_encode(rawurlencode(Einstellungen::clientId()) . ':' . rawurlencode(Einstellungen::secret()));
        } else {
            $rumpf += ['client_id' => Einstellungen::clientId(), 'client_secret' => Einstellungen::secret()];
        }
        return self::holen('POST', $d['token_endpoint'], $kopf, http_build_query($rumpf, '', '&', PHP_QUERY_RFC3986), $schritt, true);
    }

    /**
     * Eine Anfrage an den Anmeldedienst: Status 200 (beim Token-Endpunkt auch
     * 400/401, um den Fehler zu lesen), höchstens 64 KiB, ein JSON-Objekt.
     *
     * @param array<string,string> $kopf
     * @return array{status:int, json:array}
     */
    private static function holen(string $methode, string $url, #[\SensitiveParameter] array $kopf, #[\SensitiveParameter] ?string $rumpf,
        string $schritt, bool $fehlerLesen = false): array
    {
        $code = $schritt === 'probe' ? 'token' : $schritt;
        $optionen = ['kopf' => $kopf, 'zeit' => self::ZEIT, 'verbindung' => 4, 'max' => self::MAX_ANTWORT] + Einstellungen::verbindung();
        if ($rumpf !== null) {
            $optionen['rumpf'] = $rumpf;
        }
        $start = hrtime(true);
        $a = Http::anfrage($methode, $url, $optionen);
        $status = (int) ($a['status'] ?? 0);
        Logger::info('sso', ['schritt' => $schritt, 'status' => $status, 'ms' => (int) round((hrtime(true) - $start) / 1e6)]);
        if (!($a['ok'] ?? false)) {
            if ((int) ($a['nummer'] ?? 0) === Http::ZU_GROSS) {
                throw new SsoFehler($code, self::T_ZU_GROSS);
            }
            throw new SsoFehler($code, 'Der Anmeldedienst ist nicht erreichbar (' . self::kurz((string) ($a['fehler'] ?? '')) . ')'
                . ' – Adresse, DNS und ausgehendes HTTPS in der Firewall prüfen.');
        }
        if ($status >= 300 && $status < 400) {
            throw new SsoFehler($code, self::T_UMLEITUNG);
        }
        if ($schritt === 'discovery' && $status === 404) {
            throw new SsoFehler('discovery', self::T_404);
        }
        if (!in_array($status, $fehlerLesen ? [200, 400, 401] : [200], true)) {
            throw new SsoFehler($code, 'Der Anmeldedienst antwortet mit HTTP ' . $status . '.');
        }
        $text = (string) ($a['rumpf'] ?? '');
        if (strlen($text) > self::MAX_ANTWORT) {
            throw new SsoFehler($code, self::T_ZU_GROSS);
        }
        $text = ltrim($text);
        $json = null;
        if ($text !== '' && $text[0] === '{') {
            try {
                $json = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }
        if (!is_array($json)) {
            throw new SsoFehler($code, 'Der Anmeldedienst antwortet nicht mit JSON (HTTP ' . $status . ').');
        }
        return ['status' => $status, 'json' => $json];
    }

    /** `error` einer Fehlerantwort (RFC 6749 §5.2) – nur harmlose Zeichen, sonst „unbekannt“. */
    private static function fehlerCode(array $json): string
    {
        $e = $json['error'] ?? null;
        return is_string($e) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $e) === 1 ? $e : 'unbekannt';
    }

    private static function tokenFehlerText(string $code): string
    {
        return match ($code) {
            'invalid_client' => 'Client-ID oder Secret passt nicht – in Authentik nachsehen und neu eintragen.',
            'invalid_grant'  => 'Der Anmeldecode wurde nicht angenommen (abgelaufen, schon benutzt, falsche Rückruf-Adresse oder Grant ‚Authorization Code‘ nicht erlaubt) – bitte erneut anmelden.',
            default          => 'Der Anmeldedienst lehnt ab (' . $code . ').',
        };
    }

    /** @param list<array{ok:bool,text:string}> $befunde */
    private static function befundSpeichern(bool $ok, array $befunde): array
    {
        Settings::put(Einstellungen::GEPRUEFT_AM, (string) Einstellungen::jetzt());
        Settings::put(Einstellungen::BEFUND, (string) json_encode($befunde, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['ok' => $ok, 'befunde' => $befunde];
    }

    /** Fremder Text für eine Meldung: ohne Steuerzeichen, höchstens 120 Zeichen. */
    private static function kurz(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        if (preg_match('//u', $text) !== 1) {
            $text = (string) preg_replace('/[^\x20-\x7e]+/', '?', $text);
        }
        return mb_substr(trim($text), 0, 120);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
