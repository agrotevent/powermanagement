<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Einstellungen, die im laufenden Betrieb geändert werden können und
 * Werte aus config/config.php überschreiben (Tabelle `settings`).
 *
 * Wie in MasterAdmin: was sich im Betrieb ändert, gehört in die
 * Oberfläche, nicht in eine Datei, an die auf einem LXC niemand ohne
 * Shell herankommt. Zugangsdaten stehen nicht in OVERRIDABLE – sie werden
 * verschlüsselt unter eigenen Schlüsseln abgelegt und nie wieder
 * angezeigt (GEHEIM_*).
 *
 * **Ein Geheimnis gilt für sein Ziel.** InfluxDB-Token und -Kennwort, das
 * Kennwort am MQTT-Broker und das Token des Repositories werden beim Setzen
 * an das dann wirksame Ziel gebunden (BINDUNG, ziel()) – wie
 * `eventworx.token_host` und `sso.secret_fuer`. Zeigt die Adresse danach
 * woandershin, gibt geheimnis() es nicht heraus; der Wert aus config.php
 * gilt nur, solange die Einstellungen das Ziel nicht von dem aus config.php
 * wegbewegt haben (zielWieInDatei()).
 */
final class Settings
{
    /** Schlüssel, die aus der Datenbank in die Konfiguration übernommen werden. */
    public const OVERRIDABLE = [
        'app.name'          => 'Titel der Oberfläche',
        'app.subtitle'      => 'Untertitel neben dem Logo',
        'app.short_name'    => 'Kürzel im Logo (1–3 Zeichen)',
        'app.timezone'      => 'Zeitzone',
        'influx.url'        => 'Adresse der InfluxDB',
        'influx.database'   => 'Datenbank bzw. Bucket in der InfluxDB',
        'influx.org'        => 'Organisation in der InfluxDB (nur 2.x)',
        'influx.username'   => 'Benutzer der InfluxDB (nur 1.8)',
        'mqtt.host'         => 'Adresse des MQTT-Brokers',
        'mqtt.port'         => 'Port des MQTT-Brokers',
        'mqtt.username'     => 'Benutzer am MQTT-Broker',
        'sammler.ziel'      => 'Ziel des Sammlers (mqtt oder influx)',
        'inventory.repo_url'=> 'Adresse des Location-Repositories',
        'inventory.branch'  => 'Zweig im Location-Repository',
        'grenzen.warnung'   => 'Auslastung, ab der eine Anlage gelb wird (%)',
        'grenzen.alarm'     => 'Auslastung, ab der eine Anlage rot wird (%)',
        'grenzen.spannungsfall' => 'Zulässiger Spannungsfall auf einem Kabel (%)',
        // Tanks (TankRechnung::GRENZEN; Warnung/Alarm am Tank selbst gehen vor)
        'grenzen.tank_warnung'        => 'Füllstand, unter dem ein Tank gelb wird (%)',
        'grenzen.tank_alarm'          => 'Füllstand, unter dem ein Tank rot wird (%)',
        'grenzen.tank_rest_warnung_h' => 'Restlaufzeit, unter der ein Tank gelb wird (h)',
        'grenzen.tank_rest_alarm_h'   => 'Restlaufzeit, unter der ein Tank rot wird (h)',
        'grenzen.tank_stumm_h'        => 'Tanksensor ohne Lebenszeichen gilt als stumm nach (h)',
        'grenzen.tank_vorlauf_h'      => 'Lieferzeit für Kraftstoff – „nachbestellen bis“ (h)',
        'material.reserve'  => 'Kabelreserve in der Materialliste (%)',
    ];

    /** Verschlüsselt abgelegte Zugangsdaten – nie in OVERRIDABLE. */
    public const GEHEIM_INFLUX_TOKEN   = 'influx.token_enc';
    public const GEHEIM_INFLUX_KENNWORT = 'influx.password_enc';
    public const GEHEIM_MQTT_KENNWORT  = 'mqtt.password_enc';
    public const GEHEIM_REPO_TOKEN     = 'inventory.token_enc';
    /** API-Token für Eventworx – gilt nur für `eventworx.token_host` (App\Services\Eventworx). */
    public const GEHEIM_EVENTWORX_TOKEN = 'eventworx.token_enc';
    /** Client-Secret der Anmeldung über SSO – gilt nur für `sso.secret_fuer` (App\Auth\Sso\Einstellungen). */
    public const GEHEIM_SSO_SECRET = 'sso.client_secret_enc';

    /**
     * Geheimnis → Schlüssel seiner Bindung. put() schreibt sie beim Setzen,
     * nie beim ersten Gebrauch (sonst bände ein vorher umgestelltes Ziel).
     * Ältere Geheimnisse ohne Bindung bindet `bin/power migrate` einmal an
     * das Ziel zur Zeit des Updates (bindungenNachholen()).
     */
    public const BINDUNG = [
        self::GEHEIM_INFLUX_TOKEN    => 'influx.token_fuer',
        self::GEHEIM_INFLUX_KENNWORT => 'influx.password_fuer',
        self::GEHEIM_MQTT_KENNWORT   => 'mqtt.password_fuer',
        self::GEHEIM_REPO_TOKEN      => 'inventory.token_fuer',
    ];

    /** Die Einstellungen, die das Ziel eines Geheimnisses bilden (Schema, Host, Port, Benutzer). */
    public const ZIEL = [
        self::GEHEIM_INFLUX_TOKEN    => ['influx.url'],
        self::GEHEIM_INFLUX_KENNWORT => ['influx.url', 'influx.username'],
        self::GEHEIM_MQTT_KENNWORT   => ['mqtt.host', 'mqtt.port', 'mqtt.username'],
        self::GEHEIM_REPO_TOKEN      => ['inventory.repo_url'],
    ];

    /** Dieselben Vorgaben wie in Influx::url() und Mqtt::ausKonfiguration(). */
    private const ZIEL_VORGABE = [
        'influx.url'         => 'http://127.0.0.1:8086',
        'influx.username'    => '',
        'mqtt.host'          => '127.0.0.1',
        'mqtt.port'          => '1883',
        'mqtt.username'      => '',
        'inventory.repo_url' => '',
    ];

    private static ?array $cache = null;

    /**
     * Die Werte aus config.php, bevor eine Einstellung sie überschrieb –
     * gemerkt beim ersten apply()/put()/forget().
     *
     * @var array<string,mixed>|null
     */
    private static ?array $datei = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = Database::select('SELECT setting_key, setting_value FROM settings');
        } catch (\Throwable) {
            // Tabelle existiert noch nicht (vor der ersten Migration)
            return self::$cache = [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return self::$cache = $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function put(string $key, string $value): void
    {
        self::dateiMerken();
        self::schreiben($key, $value);
        if (array_key_exists($key, self::OVERRIDABLE)) {
            // Leer heißt wie in apply(): der Wert aus config.php gilt.
            if ($value !== '') {
                Config::set($key, $value);
            } elseif ((self::$datei[$key] ?? null) !== null) {
                Config::set($key, self::$datei[$key]);
            }
        }
        if (isset(self::BINDUNG[$key])) {
            // Ein Geheimnis gilt für das Ziel, für das es jetzt eingetragen wird.
            self::schreiben(self::BINDUNG[$key], self::ziel($key));
        }
    }

    public static function forget(string $key): void
    {
        self::dateiMerken();
        Database::execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
        if (isset(self::BINDUNG[$key])) {
            Database::execute('DELETE FROM settings WHERE setting_key = ?', [self::BINDUNG[$key]]);
        }
        self::$cache = null;
        // Wie in der nächsten Anfrage: wieder der Wert aus config.php.
        if (array_key_exists($key, self::OVERRIDABLE) && (self::$datei[$key] ?? null) !== null) {
            Config::set($key, self::$datei[$key]);
        }
    }

    private static function schreiben(string $key, string $value): void
    {
        $existing = Database::first('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing) {
            Database::execute('UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?', [$value, gmdate('c'), $key]);
        } else {
            Database::execute('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', [$key, $value, gmdate('c')]);
        }
        self::$cache = null;
    }

    /** Die Werte aus config.php merken, bevor eine Einstellung sie überschreibt. */
    private static function dateiMerken(): void
    {
        if (self::$datei !== null) {
            return;
        }
        self::$datei = [];
        foreach (array_keys(self::OVERRIDABLE) as $key) {
            self::$datei[$key] = Config::get($key);
        }
    }

    /** Überschreibungen in die Laufzeitkonfiguration übernehmen. */
    public static function apply(): void
    {
        self::dateiMerken();
        foreach (self::all() as $key => $value) {
            if (array_key_exists($key, self::OVERRIDABLE) && $value !== '') {
                Config::set($key, $value);
            }
        }
    }

    /**
     * Ein Geheimnis lesen: zuerst das in der Oberfläche eingetragene
     * (verschlüsselt) – nur für das Ziel, für das es eingetragen wurde –,
     * sonst der Wert aus der Konfigurationsdatei, solange das Ziel das aus
     * der Datei ist. Sonst leer: lieber ohne Anmeldung scheitern, als das
     * Geheimnis an ein umgestelltes Ziel zu schicken.
     */
    public static function geheimnis(string $schluessel, string $configKey): string
    {
        $klar = self::gebunden($schluessel);
        if ($klar !== '') {
            return $klar;
        }
        $ausDatei = trim((string) Config::get($configKey, ''));
        if ($ausDatei !== '' && isset(self::ZIEL[$schluessel]) && !self::zielWieInDatei($schluessel)) {
            return '';
        }
        return $ausDatei;
    }

    /** Das eingetragene Geheimnis – leer, wenn keins da ist oder es für ein anderes Ziel gilt. */
    public static function gebunden(string $schluessel): string
    {
        $abgelegt = self::all()[$schluessel] ?? '';
        if ($abgelegt === '' || (isset(self::BINDUNG[$schluessel]) && !self::bindungPasst($schluessel))) {
            return '';
        }
        return Crypto::decrypt($abgelegt);
    }

    /** Gilt das eingetragene Geheimnis für das jetzige Ziel? */
    public static function bindungPasst(string $schluessel): bool
    {
        $bindung = self::all()[self::BINDUNG[$schluessel] ?? ''] ?? null;
        return $bindung !== null && hash_equals($bindung, self::ziel($schluessel));
    }

    /** Eingetragen, aber für ein anderes Ziel – für Hinweise, ohne das Geheimnis. */
    public static function geheimnisFremd(string $schluessel): bool
    {
        return isset(self::BINDUNG[$schluessel]) && self::geheimnisHinterlegt($schluessel) && !self::bindungPasst($schluessel);
    }

    /**
     * Das wirksame Ziel eines Geheimnisses als Text (Schema, Host, Port,
     * Benutzer) – aus den Einstellungen, sonst aus config.php. So wird es
     * gebunden und verglichen.
     */
    public static function ziel(string $schluessel): string
    {
        $werte = [];
        foreach (self::ZIEL[$schluessel] ?? [] as $key) {
            $eingestellt = trim(self::all()[$key] ?? '');
            $werte[$key] = $eingestellt !== '' ? $eingestellt : self::ausDatei($key);
        }
        return self::zielText($schluessel, $werte);
    }

    /**
     * Zeigt das Ziel noch dahin, wohin config.php es stellt? Nur dann gilt ein
     * Geheimnis aus config.php (bzw. aus der Umgebung des Dienstes).
     */
    public static function zielWieInDatei(string $schluessel): bool
    {
        $werte = [];
        foreach (self::ZIEL[$schluessel] ?? [] as $key) {
            $werte[$key] = self::ausDatei($key);
        }
        return self::zielText($schluessel, $werte) === self::ziel($schluessel);
    }

    /**
     * Ältere Geheimnisse (vor der Bindung eingetragen) an das jetzige Ziel
     * binden – aus `bin/power migrate`, also während des Updates unter der
     * Schreibsperre. Gibt die gebundenen Schlüssel zurück.
     *
     * @return list<string>
     */
    public static function bindungenNachholen(): array
    {
        $gebunden = [];
        foreach (self::BINDUNG as $schluessel => $bindung) {
            if ((self::all()[$schluessel] ?? '') !== '' && !isset(self::all()[$bindung])) {
                self::schreiben($bindung, self::ziel($schluessel));
                $gebunden[] = $schluessel;
            }
        }
        return $gebunden;
    }

    /** Der Wert aus config.php (vor jeder Einstellung), sonst die Vorgabe der Clients. */
    private static function ausDatei(string $key): string
    {
        self::dateiMerken();
        $wert = array_key_exists($key, self::$datei) ? self::$datei[$key] : Config::get($key);
        $wert = is_scalar($wert) ? trim((string) $wert) : '';
        return $wert !== '' ? $wert : (self::ZIEL_VORGABE[$key] ?? '');
    }

    /** @param array<string,string> $w */
    private static function zielText(string $schluessel, array $w): string
    {
        return match ($schluessel) {
            self::GEHEIM_INFLUX_TOKEN    => self::adresse($w['influx.url']),
            self::GEHEIM_INFLUX_KENNWORT => self::adresse($w['influx.url']) . ' ' . $w['influx.username'],
            self::GEHEIM_MQTT_KENNWORT   => 'mqtt://' . strtolower($w['mqtt.host']) . ':' . (int) $w['mqtt.port'] . ' ' . $w['mqtt.username'],
            self::GEHEIM_REPO_TOKEN      => \App\Services\LocationSync::server($w['inventory.repo_url']),
            default                      => '',
        };
    }

    /** Schema, Host und Port einer Adresse (klein, Port ausgeschrieben) – der Pfad zählt nicht. */
    private static function adresse(string $url): string
    {
        $teile = parse_url($url);
        if (!is_array($teile) || !isset($teile['scheme'], $teile['host'])) {
            return strtolower($url);
        }
        $schema = strtolower($teile['scheme']);
        $port = $teile['port'] ?? ($schema === 'https' ? 443 : 80);
        return $schema . '://' . strtolower($teile['host']) . ':' . $port;
    }

    /** Geheimnis eintragen; leer heißt: nichts ändern. „-“ entfernt es. */
    public static function geheimnisSetzen(string $schluessel, string $klartext): void
    {
        $klartext = trim($klartext);
        if ($klartext === '') {
            return;
        }
        if ($klartext === '-') {
            self::forget($schluessel);
            return;
        }
        self::put($schluessel, Crypto::encrypt($klartext));
    }

    public static function geheimnisHinterlegt(string $schluessel): bool
    {
        return (self::all()[$schluessel] ?? '') !== '';
    }

    /** Kürzel fürs Logo – aus dem Titel abgeleitet, wenn nichts gesetzt ist. */
    public static function shortName(): string
    {
        $configured = trim((string) Config::get('app.short_name', ''));
        if ($configured !== '') {
            return mb_strtoupper(mb_substr($configured, 0, 3));
        }

        $name = trim((string) Config::get('app.name', 'PowerManagement'));
        $words = preg_split('/[\s\-_.]+/u', $name) ?: [];
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }
        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    /** Name für den Home-Bildschirm (rund zwölf Zeichen passen unter das Symbol). */
    public static function homeName(): string
    {
        $name = trim((string) Config::get('app.name', 'PowerManagement'));
        if ($name === '') {
            return 'Power';
        }
        if (mb_strlen($name) <= 12) {
            return $name;
        }
        $words = preg_split('/\s+/u', $name) ?: [];
        $letztes = (string) end($words);
        return ($letztes !== '' && mb_strlen($letztes) <= 14) ? $letztes : mb_substr($name, 0, 12);
    }

    /** Für Prüfskripte: Zwischenspeicher verwerfen. */
    public static function vergessen(): void
    {
        self::$cache = null;
    }
}
