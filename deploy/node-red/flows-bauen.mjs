#!/usr/bin/env node
/*
 * Erzeugt deploy/node-red/flows.json aus den Quellen in funktionen/.
 *
 *   node deploy/node-red/flows-bauen.mjs            flows.json neu schreiben
 *   node deploy/node-red/flows-bauen.mjs --pruefen  nur prüfen, ob sie aktuell ist
 *
 * Warum ein Bauschritt: In flows.json steht der Code jeder Function-Node als
 * ein einziger JSON-String. Darin lässt sich weder sinnvoll lesen noch ein
 * Unterschied im Review erkennen. Die Quellen liegen deshalb als normale
 * .js-Dateien daneben; flows.json wird daraus erzeugt und mit eingecheckt,
 * damit die Installation ohne diesen Schritt auskommt.
 *
 * Wer die Flows im Node-RED-Editor ändert und die Änderung ins Repository
 * übernehmen will, überträgt sie in funktionen/*.js bzw. hierher und baut
 * neu – tests/nodered-funktionen.mjs meldet, wenn beides auseinanderläuft.
 *
 * Alle IDs sind fest und lesbar (pm_…). Das Installationsskript schreibt die
 * Zugangsdaten des Brokers unter der ID „pm_broker" nach flows_cred.json,
 * und eine neue Fassung der Flows ersetzt beim Import die alten Nodes,
 * statt sie zu verdoppeln.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HIER = dirname(fileURLToPath(import.meta.url));
const ZIEL = join(HIER, 'flows.json');
const quelle = (datei) => readFileSync(join(HIER, 'funktionen', datei), 'utf8');

const BROKER = 'pm_broker';

// ------------------------------------------------------------ Bausteine
function tab(id, label, info) {
    return { id, type: 'tab', label, disabled: false, info };
}

function kommentar(id, z, name, info, x, y) {
    return { id, type: 'comment', z, name, info, x, y, wires: [] };
}

function mqttIn(id, z, name, topic, qos, x, y, ziele) {
    return {
        id, type: 'mqtt in', z, name, topic, qos, datatype: 'utf8', broker: BROKER,
        nl: false, rap: true, rh: 0, inputs: 0, x, y, wires: [ziele],
    };
}

function mqttOut(id, z, name, topic, qos, retain, x, y) {
    return {
        id, type: 'mqtt out', z, name, topic, qos, retain, respTopic: '', contentType: '',
        userProps: '', correl: '', expiry: '', broker: BROKER, x, y, wires: [],
    };
}

function funktion(id, z, name, datei, ausgaenge, info, x, y, wires) {
    return {
        id, type: 'function', z, name, func: quelle(datei), outputs: ausgaenge, timeout: 0, noerr: 0,
        initialize: '', finalize: '', libs: [], info, x, y, wires,
    };
}

function inject(id, z, name, props, wiederholen, einmalNach, x, y, ziele, extra = {}) {
    return Object.assign({
        id, type: 'inject', z, name, props, repeat: wiederholen, crontab: '',
        once: einmalNach !== null, onceDelay: einmalNach === null ? 0.1 : einmalNach,
        topic: '', x, y, wires: [ziele],
    }, extra);
}

function linkOut(id, z, name, ziele, x, y) {
    return { id, type: 'link out', z, name, mode: 'link', links: ziele, x, y, wires: [] };
}

function linkIn(id, z, name, quellen, x, y, ziele) {
    return { id, type: 'link in', z, name, links: quellen, x, y, wires: [ziele] };
}

function debug(id, z, name, eigenschaft, x, y) {
    return {
        id, type: 'debug', z, name, active: false, tosidebar: true, console: false, tostatus: false,
        complete: eigenschaft, targetType: 'msg', statusVal: '', statusType: 'auto', x, y, wires: [],
    };
}

// ------------------------------------------------------------ Tabs
const T_START = 'pm_tab_start';
const T_GEN2 = 'pm_tab_gen2';
const T_GEN1 = 'pm_tab_gen1';
const T_SCHREIBEN = 'pm_tab_schreiben';

const LINK_ZIEL = 'pm_link_umwandeln';
const LINK_QUELLEN = ['pm_link_messwert', 'pm_link_gen2', 'pm_link_gen2_info', 'pm_link_gen1'];

const flows = [
    tab(T_START, 'PowerManagement', [
        'Messwerte aus MQTT nach InfluxDB.',
        '',
        'Der PHP-Sammler (Victron, ComAp, Cloud) veröffentlicht normalisierte',
        'Messwerte auf `pm/messwert/<gerät>/<kanal>`. Shelly-Geräte sprechen',
        'ihr eigenes Format; die Tabs „Shelly Gen2+" und „Shelly Gen1"',
        'übersetzen es in dasselbe Schema. Alles läuft im Tab „Schreiben"',
        'zusammen und geht gebündelt an die InfluxDB.',
    ].join('\n')),
    tab(T_GEN2, 'Shelly Gen2+', 'Shelly Plus/Pro/Gen3/Gen4 – Status über MQTT („Generic status update over MQTT").'),
    tab(T_GEN1, 'Shelly Gen1', 'Shelly Gen1 – ein Thema je Wert unter shellies/<id>/…'),
    tab(T_SCHREIBEN, 'Schreiben', 'Line Protocol erzeugen, puffern und an die InfluxDB schicken.'),

    // Broker: Adresse und Port aus der Umgebung (/etc/powermanagement/node-red.env),
    // Benutzer und Kennwort aus flows_cred.json – beides schreibt das
    // Installationsskript. So steht in flows.json kein Zugang.
    {
        id: BROKER, type: 'mqtt-broker', name: 'Mosquitto (PowerManagement)',
        broker: '${MQTT_HOST}', port: '${MQTT_PORT}', clientid: '', autoConnect: true,
        usetls: false, protocolVersion: '4', keepalive: '60', cleansession: true, autoUnsubscribe: true,
        birthTopic: '', birthQos: '0', birthRetain: 'false', birthPayload: '', birthMsg: {},
        closeTopic: '', closeQos: '0', closeRetain: 'false', closePayload: '', closeMsg: {},
        willTopic: '', willQos: '0', willRetain: 'false', willPayload: '', willMsg: {},
        userProps: '', sessionExpiry: '',
    },

    // -------------------------------------------------------- Überblick
    kommentar('pm_kommentar_start', T_START, 'So fließen die Daten', [
        '```',
        'Shelly ──MQTT──► Mosquitto ◄──MQTT── PHP-Sammler (Modbus, Cloud)',
        '                    │',
        '                    ▼',
        '                 Node-RED  ──►  InfluxDB (Datenbank „strom")',
        '```',
        '',
        '* **pm/messwert/#** – normalisierte Messwerte des PHP-Sammlers, gehen',
        '  unverändert weiter nach „Schreiben".',
        '* **Shelly Gen2+ / Gen1** – übersetzen die Shelly-Themen in dasselbe Schema.',
        '* **Schreiben** – erzeugt Line Protocol, puffert bei Ausfällen der',
        '  InfluxDB bis zu 5 Minuten und meldet alle 30 s den eigenen Zustand',
        '  auf `pm/status/nodered` und in die Messung `pipeline`.',
        '',
        'Zugänge stehen nicht hier, sondern in `/etc/powermanagement/node-red.env`',
        '(InfluxDB, Broker-Adresse) und `flows_cred.json` (Broker-Anmeldung).',
        '',
        'Eigene Änderungen an diesen Flows bleiben bei Updates erhalten. Eine',
        'neue Fassung legt das Update als `flows.powermanagement-neu.json` ab.',
    ].join('\n'), 190, 60),
    mqttIn('pm_in_messwert', T_START, 'pm/messwert/#', 'pm/messwert/#', '1', 150, 140, ['pm_link_messwert']),
    linkOut('pm_link_messwert', T_START, 'zu „Schreiben"', [LINK_ZIEL], 355, 140),

    // -------------------------------------------------------- Shelly Gen2+
    kommentar('pm_kommentar_gen2', T_GEN2, 'Shelly Gen2+ (Plus, Pro, Gen3, Gen4)', [
        'Im Gerät unter **Einstellungen → MQTT**: Server `<IP>:1883`, Benutzer `shelly`,',
        '„Generic status update over MQTT" und „RPC status notifications" einschalten.',
        '',
        'Das Präfix ist ab Werk die Geräte-ID (`shellypro3em-a0b1c2d3e4f5`);',
        '`shelly/<name>` geht ebenso. Der Gerätename in der Datenbank ist das',
        'letzte Segment des Präfixes.',
        '',
        'Beim ersten Sehen eines Geräts und danach alle 6 h fragt der Flow per',
        'RPC `Shelly.GetDeviceInfo` ab (Antwort auf `pm-nodered/rpc`) und trägt',
        'Modell, Firmware und MAC in die Messung `geraet` ein.',
    ].join('\n'), 200, 40),
    mqttIn('pm_gen2_in_status', T_GEN2, '+/status/#', '+/status/#', '0', 130, 100, ['pm_gen2_uebersetzen']),
    mqttIn('pm_gen2_in_status_shelly', T_GEN2, 'shelly/+/status/#', 'shelly/+/status/#', '0', 150, 140, ['pm_gen2_uebersetzen']),
    mqttIn('pm_gen2_in_online', T_GEN2, '+/online', '+/online', '0', 120, 180, ['pm_gen2_uebersetzen']),
    mqttIn('pm_gen2_in_online_shelly', T_GEN2, 'shelly/+/online', 'shelly/+/online', '0', 140, 220, ['pm_gen2_uebersetzen']),
    funktion('pm_gen2_uebersetzen', T_GEN2, 'Shelly Gen2+ übersetzen', 'shelly-gen2.js', 2,
        'Übersetzt `<präfix>/status/<komponente>` und `<präfix>/online` in normalisierte Messwerte.\n\n'
        + 'Ausgang 1: Messwerte → „Schreiben". Ausgang 2: RPC-Anfrage an `<präfix>/rpc`.',
        400, 160, [['pm_link_gen2'], ['pm_gen2_rpc_aus']]),
    linkOut('pm_link_gen2', T_GEN2, 'zu „Schreiben"', [LINK_ZIEL], 605, 140),
    mqttOut('pm_gen2_rpc_aus', T_GEN2, 'RPC an das Gerät', '', '1', 'false', 660, 180),
    mqttIn('pm_gen2_in_rpc', T_GEN2, 'pm-nodered/rpc', 'pm-nodered/rpc', '1', 140, 300, ['pm_gen2_geraeteinfo']),
    funktion('pm_gen2_geraeteinfo', T_GEN2, 'Shelly Gen2+ Geräteinfo', 'shelly-gen2-geraeteinfo.js', 1,
        'Antwort auf `Shelly.GetDeviceInfo` → Punkt der Messung `geraet` (Modell, Firmware, MAC).',
        400, 300, [['pm_link_gen2_info']]),
    linkOut('pm_link_gen2_info', T_GEN2, 'zu „Schreiben"', [LINK_ZIEL], 605, 300),

    // -------------------------------------------------------- Shelly Gen1
    kommentar('pm_kommentar_gen1', T_GEN1, 'Shelly Gen1', [
        'Im Gerät unter **Internet & Security → Advanced Developer Settings**:',
        '„Enable action execution via MQTT", Server `<IP>:1883`, Benutzer `shelly`.',
        '',
        '`relay/N/energy` liefert Gen1 in **Wattminuten** – der Flow rechnet in Wh um.',
        'Die Summe über die Phasen eines EM/3EM entsteht hier, sobald von allen',
        'Phasen ein frischer Wert (< 30 s) vorliegt.',
        '',
        'Nach dem Start fragt der Flow einmal mit `announce` an `shellies/command`',
        'alle Gen1-Geräte nach Modell und Firmware.',
    ].join('\n'), 130, 40),
    mqttIn('pm_gen1_in', T_GEN1, 'shellies/#', 'shellies/#', '0', 120, 120, ['pm_gen1_uebersetzen']),
    funktion('pm_gen1_uebersetzen', T_GEN1, 'Shelly Gen1 übersetzen', 'shelly-gen1.js', 1,
        'Übersetzt die Gen1-Themen unter `shellies/<id>/…` in normalisierte Messwerte und Geräteeinträge.',
        350, 120, [['pm_link_gen1']]),
    linkOut('pm_link_gen1', T_GEN1, 'zu „Schreiben"', [LINK_ZIEL], 535, 120),
    inject('pm_gen1_announce', T_GEN1, 'announce nach dem Start',
        [{ p: 'payload' }, { p: 'topic', vt: 'str' }], '', 10, 170, 200, ['pm_gen1_befehl_aus'],
        { topic: 'shellies/command', payload: 'announce', payloadType: 'str' }),
    mqttOut('pm_gen1_befehl_aus', T_GEN1, 'shellies/command', '', '0', 'false', 420, 200),

    // -------------------------------------------------------- Schreiben
    kommentar('pm_kommentar_schreiben', T_SCHREIBEN, 'Line Protocol und InfluxDB', [
        'Ziel und Zugang aus der Umgebung (`/etc/powermanagement/node-red.env`):',
        '',
        '* `INFLUX_URL` – z. B. `http://127.0.0.1:8086`',
        '* `INFLUX_DB` – Datenbank bzw. Bucket, Vorgabe `strom`',
        '* `INFLUX_TOKEN` – API-Token (2.x/3.x; bei 1.8 auch `benutzer:kennwort`)',
        '* sonst `INFLUX_USER` / `INFLUX_PASSWORD` für 1.8',
        '',
        'Geschrieben wird an `/write?db=…&precision=ms` – der Endpunkt, den',
        'InfluxDB 1.8, 2.x (über die DBRP-Zuordnung) und 3.x gleichermaßen kennen.',
        '',
        'Alle Zahlen gehen als Float hinaus, Wahrheitswerte als 1/0 – sonst gibt',
        'es in der InfluxDB Feldtyp-Konflikte.',
    ].join('\n'), 170, 40),
    linkIn(LINK_ZIEL, T_SCHREIBEN, 'Messwerte', LINK_QUELLEN, 95, 120, ['pm_line_protocol']),
    funktion('pm_line_protocol', T_SCHREIBEN, 'Messwert → Line Protocol', 'line-protocol.js', 1,
        'Normalisierte Messwerte (`pm/messwert/<gerät>/<kanal>`) und fertige Punkte (`msg.punkte`) → `msg.zeilen`.',
        270, 120, [['pm_schreiber', 'pm_debug_zeilen']]),
    debug('pm_debug_zeilen', T_SCHREIBEN, 'Zeilen ansehen', 'zeilen', 490, 60),
    inject('pm_takt', T_SCHREIBEN, 'Takt 1 s', [{ p: 'takt', v: 'true', vt: 'bool' }], '1', 1, 290, 180, ['pm_schreiber']),
    inject('pm_herzschlag', T_SCHREIBEN, 'Herzschlag 30 s', [{ p: 'herzschlag', v: 'true', vt: 'bool' }], '30', 5, 280, 220, ['pm_schreiber']),
    funktion('pm_schreiber', T_SCHREIBEN, 'InfluxDB-Schreiber', 'influx-schreiber.js', 2,
        'Sammelt Zeilen, schickt sie jede Sekunde bzw. ab 500 Zeilen an die InfluxDB, '
        + 'puffert bei Fehlern bis zu 5 Minuten (höchstens 50 000 Zeilen).\n\n'
        + 'Ausgang 1: Anfrage an die InfluxDB. Ausgang 2: Status auf `pm/status/nodered`.',
        510, 160, [['pm_influx_write'], ['pm_status_aus']]),
    {
        id: 'pm_influx_write', type: 'http request', z: T_SCHREIBEN, name: 'InfluxDB /write',
        method: 'POST', ret: 'txt', paytoqs: 'ignore', url: '', tls: '', persist: true, proxy: '',
        insecureHTTPParser: false, authType: '', senderr: false, headers: [],
        x: 750, y: 120, wires: [['pm_schreiber']],
    },
    {
        id: 'pm_catch_write', type: 'catch', z: T_SCHREIBEN, name: 'Fehler beim Schreiben',
        scope: ['pm_influx_write'], uncaught: false, x: 520, y: 260, wires: [['pm_schreiber']],
    },
    mqttOut('pm_status_aus', T_SCHREIBEN, 'pm/status/nodered', 'pm/status/nodered', '1', 'true', 770, 200),
];

const text = JSON.stringify(flows, null, 4) + '\n';

if (process.argv.includes('--pruefen')) {
    let vorhanden = '';
    try {
        vorhanden = readFileSync(ZIEL, 'utf8');
    } catch {
        // fehlt – gilt als veraltet
    }
    if (vorhanden !== text) {
        console.error('deploy/node-red/flows.json ist nicht auf dem Stand der Quellen.');
        console.error('Neu bauen: node deploy/node-red/flows-bauen.mjs');
        process.exit(1);
    }
    console.log('flows.json ist aktuell.');
} else {
    writeFileSync(ZIEL, text);
    console.log('geschrieben: ' + ZIEL + ' (' + flows.length + ' Nodes)');
}
