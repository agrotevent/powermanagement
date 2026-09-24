/*
 * Node-RED-Einstellungen für PowerManagement
 *
 * Liegt nach der Installation im userDir /var/lib/powermanagement/node-red.
 * Das Installations- und das Update-Skript ersetzen diese Datei nur, solange
 * sie niemand von Hand geändert hat; sonst legen sie die neue Fassung als
 * settings.powermanagement-neu.js daneben.
 *
 * Gegenüber der Vorlage von Node-RED geändert und warum:
 *
 *   uiHost 127.0.0.1   Der Editor ist nur über nginx unter /node-red/
 *                      erreichbar, nicht direkt auf Port 1880 aus dem Netz.
 *   httpAdminRoot      /node-red – derselbe Pfad wie außen am nginx, damit
 *                      Verweise und WebSocket ohne Umschreiben stimmen.
 *   adminAuth          Anmeldung „admin" mit dem Kennwort, das das
 *                      Installationsskript erzeugt. Der bcrypt-Hash steht in
 *                      admin-kennwort.bcrypt neben dieser Datei – so bleibt
 *                      diese Datei selbst für alle Installationen gleich und
 *                      kann bei Updates ersetzt werden, ohne dass das Kennwort
 *                      verloren geht.
 *   credentialSecret   false: Zugangsdaten (MQTT-Anmeldung) stehen im Klartext
 *                      in flows_cred.json. Das ist Absicht – nur so kann das
 *                      Installationsskript sie dort hineinschreiben, ohne
 *                      Node-RED zu starten und den Umweg über den Editor zu
 *                      gehen. Geschützt ist die Datei über ihre Rechte (600,
 *                      Benutzer nodered) im ebenfalls geschützten userDir
 *                      (750). Ein Schlüssel in dieser Datei würde nichts
 *                      verbessern: wer flows_cred.json lesen kann, kann auch
 *                      den Schlüssel lesen.
 *   Umgebung           MQTT_HOST/MQTT_PORT und INFLUX_* kommen aus
 *                      /etc/powermanagement/node-red.env (systemd
 *                      EnvironmentFile). Die Flows lesen sie mit env.get()
 *                      bzw. ${…} im Broker-Node.
 */

const fs = require('fs');
const path = require('path');

// Kennwort-Hash lesen. Fehlt die Datei, wird ein Hash gesetzt, zu dem es
// kein Kennwort gibt: der Editor bleibt dann gesperrt statt offen. Offen
// wäre er über nginx aus dem ganzen Netz erreichbar.
function adminHash() {
    try {
        const hash = fs.readFileSync(path.join(__dirname, 'admin-kennwort.bcrypt'), 'utf8').trim();
        if (/^\$2[aby]\$\d\d\$.{53}$/.test(hash)) {
            return hash;
        }
        console.error('admin-kennwort.bcrypt enthält keinen bcrypt-Hash – Editor gesperrt.');
    } catch (e) {
        console.error('admin-kennwort.bcrypt fehlt – Editor gesperrt. Neues Kennwort: siehe docs/installation-debian-lxc.md');
    }
    return '$2b$10$gesperrtgesperrtgesperrtgesperrtgesperrtgesperrtgespe';
}

module.exports = {
    flowFile: 'flows.json',
    flowFilePretty: true,
    credentialSecret: false,

    uiHost: '127.0.0.1',
    uiPort: process.env.PORT || 1880,
    httpAdminRoot: '/node-red',
    // HTTP-Endpunkte eigener Flows (http in) bleiben lokal auf Port 1880 –
    // nginx reicht nur /node-red/ durch.
    httpNodeRoot: '/',

    adminAuth: {
        type: 'credentials',
        users: [{
            username: 'admin',
            password: adminHash(),
            permissions: '*',
        }],
    },

    diagnostics: {
        enabled: true,
        ui: true,
    },
    runtimeState: {
        enabled: false,
        ui: false,
    },
    telemetry: {
        // Keine Rückfrage im Editor und kein Kontakt nach außen; über neue
        // Fassungen informiert das Update-Skript.
        enabled: false,
        updateNotification: false,
    },

    logging: {
        console: {
            level: 'info',
            metrics: false,
            audit: false,
        },
    },

    // Kontext nur im Speicher: Puffer und Zähler der Flows überleben einen
    // Neustart nicht – das ist gewollt, sie sind Zwischenstände.
    contextStorage: {
        default: { module: 'memory' },
    },

    exportGlobalContextKeys: false,

    // Zusatzmodule in Function-Nodes brauchen die Flows nicht. Wer Nodes aus
    // der Palette nachinstalliert, kann das weiterhin über den Editor tun.
    functionExternalModules: false,
    functionGlobalContext: {},
    functionTimeout: 0,
    globalFunctionTimeout: 0,

    debugMaxLength: 1000,
    mqttReconnectTime: 15000,
    serialReconnectTime: 15000,

    lang: 'de',

    editorTheme: {
        page: {
            title: 'Node-RED · PowerManagement',
        },
        header: {
            title: 'Node-RED · PowerManagement',
        },
        projects: {
            enabled: false,
        },
        codeEditor: {
            lib: 'monaco',
        },
        multiplayer: {
            enabled: false,
        },
    },
};
