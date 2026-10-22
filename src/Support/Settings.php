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

    private static ?array $cache = null;

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
        $existing = Database::first('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing) {
            Database::execute('UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?', [$value, gmdate('c'), $key]);
        } else {
            Database::execute('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', [$key, $value, gmdate('c')]);
        }
        self::$cache = null;
        if (array_key_exists($key, self::OVERRIDABLE)) {
            Config::set($key, $value);
        }
    }

    public static function forget(string $key): void
    {
        Database::execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
        self::$cache = null;
    }

    /** Überschreibungen in die Laufzeitkonfiguration übernehmen. */
    public static function apply(): void
    {
        foreach (self::all() as $key => $value) {
            if (array_key_exists($key, self::OVERRIDABLE) && $value !== '') {
                Config::set($key, $value);
            }
        }
    }

    /**
     * Ein Geheimnis lesen: zuerst das in der Oberfläche eingetragene
     * (verschlüsselt), sonst der Wert aus der Konfigurationsdatei.
     */
    public static function geheimnis(string $schluessel, string $configKey): string
    {
        $abgelegt = self::all()[$schluessel] ?? '';
        if ($abgelegt !== '') {
            $klar = Crypto::decrypt($abgelegt);
            if ($klar !== '') {
                return $klar;
            }
        }
        return trim((string) Config::get($configKey, ''));
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
