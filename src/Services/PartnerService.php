<?php
declare(strict_types=1);

namespace App\Services;

use App\Messung\Http;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Logger;

/**
 * Andere Instanzen (Seite B, die fragt): eintragen, Verbindung, Meldungen.
 *
 * Eine andere Instanz – PowerManagement oder MasterAdmin – gibt unter
 * System → Schnittstelle (API) ein Token aus; hier wird es mit ihrer Adresse
 * eingetragen. Danach lassen sich von dort Standorte übernehmen
 * (PartnerAbgleich). Beide sprechen dasselbe Protokoll (/api/v1, Token
 * „mtma_…“).
 *
 * Das Token liegt mit app.key verschlüsselt in api_partner und verlässt die
 * Instanz nur im Authorization-Kopf an genau die Adresse, für die es
 * eingetragen wurde. Die Adresse lässt sich deshalb nicht ändern – sonst
 * ließe sich ein hinterlegtes Token an einen fremden Server lenken. Ein
 * neues Token kommt nur über „Prüfen“, und nur, wenn die Gegenseite es
 * annimmt. alle() und find() geben token_enc nie heraus.
 *
 * Angefragt wird vom Server aus, nicht vom Browser – wer eine Instanz
 * einträgt (Recht „api.partner“), lässt diesen Server eine Adresse seiner
 * Wahl aufrufen. Deshalb nur http(s), keine Weiterleitungen, keine
 * Zugangsdaten in der Adresse.
 */
final class PartnerService
{
    /** Sekunden für Namensauflösung und Verbindungsaufbau. */
    public const VERBINDUNGSZEIT = 10;

    /** Sekunden für eine Anfrage (Liste, Location, Vorschlag). */
    public const ZEITLIMIT = 60;

    /** Sekunden für eine Plandatei – ein A0-PDF über eine langsame Leitung. */
    public const DATEI_ZEITLIMIT = 180;

    /** Größte JSON-Antwort, die angenommen wird. */
    public const MAX_ANTWORT = 32 * 1024 * 1024;

    /** So sieht ein Token der Gegenseite aus (MasterAdmin gibt längere aus als wir). */
    public const TOKEN_MUSTER = '/^mtma_[A-Za-z0-9_-]{20,195}$/';

    /** remote_produkt einer PowerManagement-Instanz; leer = MasterAdmin. */
    public const PRODUKT_PM = 'powermanagement';

    private const TOKEN_FALSCH = 'Das ist kein Token einer MasterAdmin- oder PowerManagement-Instanz (es beginnt mit „mtma_“).';
    private const TOKEN_ABGELEHNT = 'Das Token wird dort nicht (mehr) angenommen – abgelaufen, widerrufen oder falsch.';

    // ---------------------------------------------------------------- Adresse

    /**
     * Was an einer Adresse nicht stimmt – oder null.
     *
     * Erlaubt: http(s)://name[:port][/pfad] (eine Installation in einem
     * Unterordner). Ein angehängtes /api oder /api/v1 wird übergangen – die
     * Gegenseite zeigt ihre Adresse so an. Nicht erlaubt: Zugangsdaten in der
     * Adresse, „?…“, „#…“, Leer- und Steuerzeichen, Backslashes und Namen,
     * die curl und PHP verschieden lesen könnten.
     */
    public static function adresseFehler(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'Bitte die Adresse der anderen Instanz angeben.';
        }
        if (strlen($url) > 255) {
            return 'Die Adresse ist zu lang (höchstens 255 Zeichen).';
        }
        if (preg_match('/[\s\x00-\x1F\x7F\\\\]/u', $url) === 1) {
            return 'Die Adresse enthält Leerzeichen, Steuerzeichen oder einen Backslash.';
        }
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return 'Die Adresse bitte ohne „?…“ und „#…“ angeben.';
        }
        $teile = parse_url(self::kuerzen($url));
        if (!is_array($teile) || !in_array(strtolower((string) ($teile['scheme'] ?? '')), ['http', 'https'], true)) {
            return 'Die Adresse bitte als http://… oder https://… angeben.';
        }
        $autoritaet = (string) preg_replace('#^[a-z]+://([^/]*).*$#is', '$1', $url);
        if (isset($teile['user']) || isset($teile['pass']) || str_contains($autoritaet, '@')) {
            return 'Zugangsdaten gehören nicht in die Adresse – das Token kommt in sein eigenes Feld.';
        }
        $host = (string) ($teile['host'] ?? '');
        if ($host === '' || preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)$/', $host) !== 1) {
            return 'In der Adresse fehlt der Name oder die IP der anderen Instanz.';
        }
        if (isset($teile['path']) && preg_match('#^(/[A-Za-z0-9._~%-]+)*$#', (string) $teile['path']) !== 1) {
            return 'Der Pfad in der Adresse enthält Zeichen, die hier nicht vorkommen sollten.';
        }
        return null;
    }

    /**
     * Die Adresse, wie sie gespeichert wird: ohne /api(/v1), ohne
     * abschließenden Schrägstrich, Schema und Name klein. Nur für Adressen
     * ohne adresseFehler().
     */
    public static function adresse(string $url): string
    {
        $t = parse_url(self::kuerzen(trim($url))) ?: [];
        return strtolower((string) ($t['scheme'] ?? '')) . '://' . strtolower((string) ($t['host'] ?? ''))
            . (isset($t['port']) ? ':' . (int) $t['port'] : '') . (string) ($t['path'] ?? '');
    }

    private static function kuerzen(string $url): string
    {
        $url = rtrim($url, '/');
        return rtrim((string) preg_replace('#/api(/v1)?$#i', '', $url), '/');
    }

    /** Geht die Verbindung unverschlüsselt über ein Netz (http, nicht nur zu sich selbst)? */
    public static function unverschluesselt(string $adresse): bool
    {
        $t = parse_url($adresse) ?: [];
        if (strtolower((string) ($t['scheme'] ?? '')) !== 'http') {
            return false;
        }
        $host = strtolower(trim((string) ($t['host'] ?? ''), '[]'));
        return !($host === 'localhost' || $host === '::1' || str_starts_with($host, '127.'));
    }

    // ---------------------------------------------------------------- Bestand

    /** @return list<array<string,mixed>> ohne token_enc, mit befugnisse, locations, unverschluesselt */
    public static function alle(): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM api_partner ORDER BY name, id') as $z) {
            $z = self::aufbereiten($z);
            $z['locations'] = (int) Database::scalar('SELECT COUNT(*) FROM sites WHERE partner_id = ?', [(int) $z['id']]);
            $out[] = $z;
        }
        return $out;
    }

    /** @return array<string,mixed>|null ohne token_enc */
    public static function find(int $id): ?array
    {
        $z = Database::first('SELECT * FROM api_partner WHERE id = ?', [$id]);
        return $z === null ? null : self::aufbereiten($z);
    }

    private static function aufbereiten(array $z): array
    {
        unset($z['token_enc']);
        $liste = json_decode((string) ($z['remote_befugnisse'] ?? '[]'), true);
        $z['befugnisse'] = is_array($liste) ? array_values(array_filter($liste, 'is_string')) : [];
        $z['unverschluesselt'] = self::unverschluesselt((string) $z['base_url']);
        $z['powermanagement'] = (string) $z['remote_produkt'] === self::PRODUKT_PM;
        return $z;
    }

    public static function istPowerManagement(array $partner): bool
    {
        return (string) ($partner['remote_produkt'] ?? '') === self::PRODUKT_PM;
    }

    /** Hat das Token dort diese Befugnis (Stand der letzten Prüfung)? */
    public static function darfDort(array $partner, string $befugnis): bool
    {
        return in_array($befugnis, (array) ($partner['befugnisse'] ?? []), true);
    }

    // ---------------------------------------------------------------- Pflege

    /**
     * Eine Instanz eintragen – erst, wenn sie mit diesem Token antwortet und
     * es dort Grundrisse lesen darf.
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function anlegen(string $name, string $url, string $token): array
    {
        $fehler = Crypto::schluesselFehler();
        if ($fehler !== null) {
            return ['ok' => false, 'message' => 'Ohne eigenen app.key lässt sich kein Token ablegen. ' . $fehler];
        }
        $fehler = self::adresseFehler($url);
        if ($fehler !== null) {
            return ['ok' => false, 'message' => $fehler];
        }
        $adresse = self::adresse($url);
        $token = trim($token);
        if (preg_match(self::TOKEN_MUSTER, $token) !== 1) {
            return ['ok' => false, 'message' => self::TOKEN_FALSCH];
        }
        $info = self::info(['base_url' => $adresse, 'token' => $token]);
        if (!$info['ok']) {
            return ['ok' => false, 'message' => $info['message']];
        }
        try {
            $verschluesselt = Crypto::encrypt($token);
        } catch (\Throwable $e) {
            Logger::error('Instanz eintragen: Token nicht verschlüsselt – ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Das Token ließ sich nicht verschlüsselt ablegen – app.key prüfen.'];
        }
        $name = mb_substr(self::text($name), 0, 190);
        if ($name === '') {
            $name = $info['werte']['remote_name'] !== '' ? $info['werte']['remote_name'] : (string) parse_url($adresse, PHP_URL_HOST);
        }
        $id = Database::insert('api_partner', [
            'name'         => $name,
            'base_url'     => $adresse,
            'token_enc'    => $verschluesselt,
            'token_prefix' => substr($token, 0, 12),
            'created_by'   => mb_substr(\App\Auth\Auth::name(), 0, 64),
            'created_at'   => gmdate('c'),
            'updated_at'   => gmdate('c'),
        ] + $info['werte']);
        Audit::log('api.partner.anlegen', 'api_partner', (string) $id, ['adresse' => $adresse, 'produkt' => $info['werte']['remote_produkt']]);
        return ['ok' => true, 'id' => $id, 'message' => sprintf('Verbunden mit „%s“ (%s).', $info['werte']['remote_name'] !== '' ? $info['werte']['remote_name'] : $adresse,
            self::produktName($info['werte']['remote_produkt']))];
    }

    /**
     * Erneut fragen, was das Token dort darf – mit $neuesToken das Token
     * ersetzen, aber nur, wenn die Gegenseite das neue annimmt.
     *
     * @return array{ok:bool,message:string}
     */
    public static function pruefen(int $id, string $neuesToken = ''): array
    {
        $zeile = Database::first('SELECT * FROM api_partner WHERE id = ?', [$id]);
        if ($zeile === null) {
            return ['ok' => false, 'message' => 'Diese Instanz ist hier nicht eingetragen.'];
        }
        $neu = trim($neuesToken);
        if ($neu !== '' && preg_match(self::TOKEN_MUSTER, $neu) !== 1) {
            return ['ok' => false, 'message' => self::TOKEN_FALSCH];
        }
        $info = self::info($neu !== '' ? ['base_url' => (string) $zeile['base_url'], 'token' => $neu] : ['id' => $id] + $zeile);
        if (!$info['ok']) {
            // Ein abgelehntes neues Token sagt nichts über das hinterlegte.
            if ($neu === '') {
                Database::update('api_partner', $id, ['letzter_fehler' => mb_substr($info['message'], 0, 500), 'updated_at' => gmdate('c')]);
            }
            return ['ok' => false, 'message' => $info['message']];
        }
        $felder = $info['werte'] + ['letzter_fehler' => '', 'updated_at' => gmdate('c')];
        if ($neu !== '') {
            try {
                $felder['token_enc'] = Crypto::encrypt($neu);
            } catch (\Throwable $e) {
                Logger::error('Instanz prüfen: Token nicht verschlüsselt – ' . $e->getMessage());
                return ['ok' => false, 'message' => 'Das Token ließ sich nicht verschlüsselt ablegen – app.key prüfen.'];
            }
            $felder['token_prefix'] = substr($neu, 0, 12);
        }
        Database::update('api_partner', $id, $felder);
        if ($neu !== '') {
            Audit::log('api.partner.token', 'api_partner', (string) $id, ['adresse' => (string) $zeile['base_url'], 'produkt' => $info['werte']['remote_produkt']]);
        }
        $befugnisse = json_decode((string) $info['werte']['remote_befugnisse'], true) ?: [];
        return ['ok' => true, 'message' => ($neu !== '' ? 'Neues Token hinterlegt. ' : '') . 'Die Verbindung steht – Befugnisse dort: '
            . (implode(', ', $befugnisse) ?: 'keine') . '.'];
    }

    /**
     * Eine Instanz austragen. Übernommenes bleibt, wie es ist – nur die
     * Verknüpfungen mit dort gehen mit (sonst hingen Standorte und Anlagen an
     * einer Instanz, die es hier nicht mehr gibt).
     *
     * @return array{ok:bool,message:string}
     */
    public static function loeschen(int $id): array
    {
        $p = self::find($id);
        if ($p === null) {
            return ['ok' => false, 'message' => 'Diese Instanz ist hier nicht eingetragen.'];
        }
        $sites = array_map('intval', array_column(Database::select('SELECT id FROM sites WHERE partner_id = ?', [$id]), 'id'));
        foreach ($sites as $siteId) {
            self::verknuepfungLoesen($siteId);
        }
        Database::execute('DELETE FROM api_partner WHERE id = ?', [$id]);
        Audit::log('api.partner.loeschen', 'api_partner', (string) $id, ['adresse' => (string) $p['base_url'], 'produkt' => (string) $p['remote_produkt'], 'locations' => count($sites)]);
        return ['ok' => true, 'message' => sprintf('„%s“ ausgetragen. Übernommene Standorte (%d) bleiben, wie sie sind.', (string) $p['name'], count($sites))];
    }

    /**
     * Alle partner_*-Spalten eines Standorts leeren – an Standort, Etagen,
     * Anlagen und Kabeln. Die Daten bleiben. Für PartnerAbgleich::loesen()
     * und loeschen(); Rechte prüft der Aufrufer.
     */
    public static function verknuepfungLoesen(int $siteId): void
    {
        Database::execute("UPDATE sites SET partner_id = NULL, partner_schluessel = '', partner_stand = '', partner_hash = '', partner_at = NULL WHERE id = ?", [$siteId]);
        Database::execute("UPDATE floors SET partner_schluessel = '' WHERE site_id = ?", [$siteId]);
        Database::execute("UPDATE objekte SET partner_schluessel = '' WHERE site_id = ?", [$siteId]);
        Database::execute("UPDATE leitungen SET partner_schluessel = '' WHERE floor_id IN (SELECT id FROM floors WHERE site_id = ?)", [$siteId]);
    }

    /**
     * GET /info – und was davon hier gespeichert wird.
     *
     * @return array{ok:bool,message:string,werte:array{remote_name:string,remote_produkt:string,remote_befugnisse:string,verified_at:string}}
     */
    private static function info(array $partner): array
    {
        $leer = ['remote_name' => '', 'remote_produkt' => '', 'remote_befugnisse' => '[]', 'verified_at' => ''];
        $r = self::anfrage($partner, 'GET', '/info');
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'werte' => $leer];
        }
        $d = (array) $r['daten'];
        if (!is_array($d['token'] ?? null) || !is_array($d['token']['befugnisse'] ?? null)) {
            return ['ok' => false, 'werte' => $leer,
                'message' => 'Die Gegenseite meldet keine Befugnisse – ist unter dieser Adresse eine MasterAdmin- oder PowerManagement-Instanz?'];
        }
        $befugnisse = [];
        foreach ($d['token']['befugnisse'] as $b) {
            if (is_string($b) && preg_match('/^[a-z0-9._-]{1,64}$/', $b) === 1 && !in_array($b, $befugnisse, true) && count($befugnisse) < 50) {
                $befugnisse[] = $b;
            }
        }
        if (!in_array('plaene.lesen', $befugnisse, true)) {
            return ['ok' => false, 'werte' => $leer,
                'message' => 'Das Token darf dort keine Grundrisse lesen – es braucht die Befugnis „plaene.lesen“.'];
        }
        return ['ok' => true, 'message' => '', 'werte' => [
            'remote_name'       => mb_substr(self::text($d['instanz'] ?? ''), 0, 190),
            'remote_produkt'    => ($d['produkt'] ?? '') === self::PRODUKT_PM ? self::PRODUKT_PM : '',
            'remote_befugnisse' => (string) json_encode($befugnisse),
            'verified_at'       => gmdate('c'),
        ]];
    }

    public static function produktName(string $produkt): string
    {
        return $produkt === self::PRODUKT_PM ? 'PowerManagement' : 'MasterAdmin';
    }

    // ---------------------------------------------------------------- Anfragen

    /**
     * Eine Anfrage an die andere Instanz.
     *
     * @param array<string,mixed> $partner Zeile aus find() – oder
     *        ['base_url' => …, 'token' => …] für die erste Prüfung
     * @param array<string,mixed>|null $koerper geht als JSON hinaus
     * @param bool $roh eine Datei: Antwort in 'roh', längere Zeitgrenze, höchstens
     *        so groß wie ein Grundriss-Upload
     * @return array{ok:bool,status:int,message:string,daten:mixed,roh?:string}
     *         status 0 = keine Verbindung
     */
    public static function anfrage(array $partner, string $methode, string $pfad, ?array $koerper = null, bool $roh = false): array
    {
        $fehler = static fn (int $status, string $meldung, mixed $daten = null): array
            => ['ok' => false, 'status' => $status, 'message' => $meldung, 'daten' => $daten];
        if (Http::$attrappe === null && !function_exists('curl_init')) {
            // Ohne curl nähmen PHP-Streams Weiterleitungen mit – samt Token.
            return $fehler(0, 'Die PHP-Erweiterung curl fehlt (Paket php-curl).');
        }
        $token = isset($partner['token']) ? (string) $partner['token'] : self::gespeichertesToken((int) ($partner['id'] ?? 0));
        if ($token === '') {
            return $fehler(0, 'Für diese Instanz ist kein lesbares Token hinterlegt – mit „Prüfen“ ein neues eintragen.');
        }
        $url = rtrim((string) ($partner['base_url'] ?? ''), '/') . '/api/v1' . $pfad;
        $zeit = $roh ? self::DATEI_ZEITLIMIT : self::ZEITLIMIT;
        $kopf = ['Authorization' => 'Bearer ' . $token, 'Accept' => $roh ? '*/*' : 'application/json'];
        $optionen = ['verbindung' => self::VERBINDUNGSZEIT, 'zeit' => $zeit, 'max' => $roh ? SiteService::MAX_UPLOAD_BYTES : self::MAX_ANTWORT];
        if ($koerper !== null) {
            $kopf['Content-Type'] = 'application/json';
            $optionen['rumpf'] = (string) json_encode($koerper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
        $a = Http::anfrage($methode, $url, ['kopf' => $kopf] + $optionen);
        if (!$a['ok']) {
            if ((int) ($a['nummer'] ?? 0) === Http::ZU_GROSS) {
                return $fehler((int) $a['status'], 'Die Antwort von dort ist zu groß – abgebrochen. ' . (string) $a['fehler']);
            }
            return $fehler(0, self::verbindungsfehler((int) ($a['nummer'] ?? 0), (string) $a['fehler'], $url, $zeit));
        }
        $status = (int) $a['status'];
        $daten = json_decode((string) $a['rumpf'], true, 64);
        if ($roh) {
            if ($status === 200) {
                return ['ok' => true, 'status' => 200, 'message' => '', 'daten' => null, 'roh' => (string) $a['rumpf']];
            }
            return $fehler($status, match (true) {
                $status === 401 => self::TOKEN_ABGELEHNT,
                $status === 429 => 'Die Gegenseite bremst gerade – bitte später erneut.',
                is_array($daten) => self::fremdeMeldung($daten, $status),
                default => sprintf('Die Gegenseite antwortet mit HTTP %d.', $status),
            });
        }
        if (!is_array($daten)) {
            return $fehler($status, sprintf('Die Antwort ist kein JSON (HTTP %d) – stimmt die Adresse?', $status));
        }
        if ($status === 401) {
            return $fehler($status, self::TOKEN_ABGELEHNT, $daten);
        }
        if ($status === 429) {
            return $fehler($status, 'Die Gegenseite bremst gerade – bitte später erneut.', $daten);
        }
        if ($status < 200 || $status >= 300 || ($daten['ok'] ?? true) === false) {
            return $fehler($status, self::fremdeMeldung($daten, $status), $daten);
        }
        return ['ok' => true, 'status' => $status, 'message' => '', 'daten' => $daten];
    }

    /**
     * Die Meldung der Gegenseite: nur Text, ohne Steuerzeichen, höchstens
     * 300 Zeichen. Angezeigt wird sie immer mit View::e().
     */
    private static function fremdeMeldung(array $daten, int $status): string
    {
        $text = mb_substr(self::text($daten['message'] ?? ''), 0, 300);
        return $text !== '' ? $text : sprintf('Die Gegenseite antwortet mit HTTP %d.', $status);
    }

    private static function gespeichertesToken(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        try {
            return Crypto::decrypt((string) Database::scalar('SELECT token_enc FROM api_partner WHERE id = ?', [$id]));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Was curl meldet, so gesagt, dass klar wird, wo zu suchen ist.
     *
     * Gefragt wird vom Server aus, auf dem diese Instanz läuft – dass die
     * Adresse im Browser aufgeht, sagt darüber nichts. Der Text von curl
     * steht am Ende, für alle, die ihn suchen. Die Nummern statt der
     * Konstanten: die gibt es nur mit geladener Erweiterung.
     */
    public static function verbindungsfehler(int $nummer, string $text, string $url, int $zeitlimit = self::ZEITLIMIT): string
    {
        $teile = parse_url($url) ?: [];
        $host = (string) ($teile['host'] ?? $url);
        $port = (int) ($teile['port'] ?? (strtolower((string) ($teile['scheme'] ?? '')) === 'https' ? 443 : 80));
        $ziel = '„' . $host . ':' . $port . '“';
        $sekunden = self::VERBINDUNGSZEIT;
        $satz = match (true) {
            // CURLE_COULDNT_RESOLVE_HOST
            $nummer === 6 => sprintf('Der Server, auf dem diese Instanz läuft, kann den Namen „%s“ nicht auflösen – sein DNS kennt ihn nicht. '
                . 'Die Adresse mit der IP der anderen Instanz eintragen oder den Namen im DNS bzw. in /etc/hosts des Servers nachtragen.', $host),
            // CURLE_OPERATION_TIMEDOUT, noch beim Auflösen
            $nummer === 28 && stripos($text, 'resolv') !== false => sprintf('Der Server, auf dem diese Instanz läuft, bekommt von seinem DNS-Server '
                . 'keine Antwort zu „%s“ (nach %d s aufgegeben). Die Adresse mit der IP der anderen Instanz eintragen oder den Nameserver '
                . 'des Servers prüfen: /etc/resolv.conf, im Proxmox-Container unter „DNS“.', $host, $sekunden),
            // … beim Verbindungsaufbau
            $nummer === 28 && stripos($text, 'connect') !== false => sprintf('%s antwortet dem Server hier nicht (nach %d s aufgegeben). Meist sperrt '
                . 'eine Firewall dazwischen oder es fehlt eine Route zwischen den Netzen – oder die Adresse stimmt nicht.', $ziel, $sekunden),
            // … mitten in der Antwort: die Verbindung stand, es dauerte nur.
            $nummer === 28 => sprintf('Die andere Instanz hat nicht innerhalb von %d s fertig geantwortet – sie ist erreichbar, aber zu langsam '
                . 'oder überlastet. Später erneut versuchen.', $zeitlimit),
            // CURLE_COULDNT_CONNECT
            $nummer === 7 => sprintf('%s nimmt vom Server hier aus keine Verbindung an. Läuft dort der Webserver, stimmen Port und http/https, '
                . 'lässt eine Firewall dazwischen durch?', $ziel),
            // CURLE_SSL_CONNECT_ERROR
            $nummer === 35 => sprintf('Die verschlüsselte Verbindung zu %s kommt nicht zustande – spricht die Gegenseite unter dieser Adresse überhaupt https?', $ziel),
            // CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT
            in_array($nummer, [51, 58, 60], true) => sprintf('Der Server hier vertraut dem Zertifikat von „%s“ nicht – selbst signiert, abgelaufen oder für '
                . 'einen anderen Namen ausgestellt. Ein gültiges Zertifikat einrichten oder die ausstellende Stelle auf dem Server hier '
                . 'hinterlegen (/usr/local/share/ca-certificates, dann update-ca-certificates).', $host),
            default => '',
        };
        $text = mb_substr(self::text($text), 0, 300);
        if ($satz === '') {
            return 'Keine Verbindung: ' . ($text !== '' ? $text : 'keine Antwort.');
        }
        return 'Keine Verbindung: ' . $satz . ($text !== '' ? ' (curl: ' . $text . ')' : '');
    }

    /** Ein Text von außen: nur Zeichenketten, ohne Steuerzeichen, getrimmt. */
    private static function text(mixed $wert): string
    {
        return is_string($wert) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $wert)) : '';
    }
}
