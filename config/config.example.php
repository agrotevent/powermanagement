<?php
/*
 * PowerManagement – Konfiguration.
 *
 * Kopieren nach config/config.php und anpassen. Die Datei wird von Updates
 * nie angefasst. Werte, die sich im Betrieb ändern können sollen (Titel,
 * Repository-Adresse, InfluxDB-Zugang …), stehen zusätzlich unter
 * System → Einstellungen und überschreiben das hier.
 */
return [
    'app' => [
        'name'      => 'PowerManagement',
        'subtitle'  => 'Stromverteilung & Energiedaten',
        'short_name'=> 'PM',
        // Schlüssel für die verschlüsselte Ablage von Zugangsdaten
        // (Modbus-/Cloud-Zugänge, Repository-Token). Erzeugen mit:
        //   php -r 'echo "base64:".base64_encode(random_bytes(32)),"\n";'
        'key'       => 'base64:CHANGEME000000000000000000000000000000000000',
        'timezone'  => 'Europe/Berlin',
        'debug'     => false,
        // Demo-Modus: keine InfluxDB, kein MQTT, keine Geräte nötig – die
        // Messwerte werden erzeugt. Zum Ausprobieren und für Screenshots.
        'demo'      => false,
        // Unter welcher Adresse die Oberfläche erreichbar ist (für Links
        // in Meldungen). Leer: aus der Anfrage abgeleitet.
        'url'       => '',
    ],

    'database' => [
        // sqlite (Vorgabe) oder mysql
        'driver'   => 'sqlite',
        'path'     => __DIR__ . '/../storage/database.sqlite',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'powermanagement',
        'username' => 'powermanagement',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'security' => [
        'session_name'        => 'pm_session',
        'idle_timeout'        => 3600,
        'lockout_attempts'    => 5,
        'lockout_minutes'     => 15,
        // Fehlversuche je Adresse im selben Zeitraum, dann wird sie gebremst.
        'ip_attempts'         => 20,
        // Längste Sitzung in Sekunden, auch wenn sie benutzt wird (0 = ohne).
        'max_session'         => 43200,
        // Sitzungs-Cookie nur über HTTPS: true erzwingt es, wenn der Proxy
        // davor kein X-Forwarded-Proto schickt; null = wie die Anfrage kam.
        'cookie_secure'       => null,
        'password_min_length' => 12,
        // Adressen von Reverse-Proxys, deren X-Forwarded-For geglaubt wird.
        'trusted_proxies'     => ['127.0.0.1'],
    ],

    /*
     * InfluxDB – hier landen alle Messwerte (Node-RED schreibt, die
     * Oberfläche liest). Abgefragt wird über die v1-kompatible Schnittstelle
     * mit InfluxQL; das verstehen InfluxDB 1.8, 2.x und 3.x gleichermaßen.
     *
     *   InfluxDB 2.x: token = API-Token, database = Name des Buckets
     *   InfluxDB 1.8: username/password statt token
     */
    'influx' => [
        'url'      => 'http://127.0.0.1:8086',
        'token'    => '',
        'username' => '',
        'password' => '',
        'org'      => 'powermanagement',
        'database' => 'strom',
        'timeout'  => 8,
    ],

    /*
     * MQTT-Broker (Mosquitto). Der Sammler veröffentlicht hier seine
     * Messwerte, die Steuerung schickt Schaltbefehle an Shelly-Geräte.
     */
    'mqtt' => [
        'host'     => '127.0.0.1',
        'port'     => 1883,
        'username' => 'powermanagement',
        'password' => '',
        // Themenpräfix für normalisierte Messwerte. Die Node-RED-Flows
        // (deploy/node-red) hören fest auf pm/messwert/# und melden sich auf
        // pm/status/nodered – wer das Präfix ändert, muss die Flows anpassen.
        'prefix'   => 'pm',
    ],

    'sammler' => [
        // Wohin der Sammler (Modbus, Cloud) schreibt:
        //   mqtt   → Node-RED übernimmt und schreibt in die InfluxDB (Vorgabe)
        //   influx → direkt in die InfluxDB, ohne Node-RED
        'ziel'           => 'mqtt',
        'modbus_timeout' => 3,
    ],

    'inventory' => [
        // Adresse des gemeinsamen Location-Repositories (dasselbe wie in
        // MasterAdmin). Wird in der Oberfläche gepflegt.
        'repo_url' => '',
        'branch'   => 'main',
    ],

    /*
     * Eventworx – Kundendaten für Übergabeberichte, nur lesend. Mandant und
     * API-Token werden unter System → Einstellungen eingetragen (das Token
     * verschlüsselt, nie aus dieser Datei). Erlaubt ist dort nur
     * https://<mandant>.eventworx.biz.
     *
     * basis: nur für eine Test- oder eigene Installation, z. B.
     * 'https://eventworx.intern.example'. Gilt nur, wenn in der Oberfläche
     * genau diese Adresse eingetragen wird. Sonst leer lassen.
     */
    'eventworx' => [
        'basis' => '',
    ],
];
