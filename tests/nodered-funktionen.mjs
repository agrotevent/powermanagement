#!/usr/bin/env node
/*
 * Prüft die Function-Nodes aus deploy/node-red/flows.json – ohne Node-RED
 * und ohne Testrahmen:
 *
 *   node tests/nodered-funktionen.mjs
 *
 * Jede Function-Node wird über ihren Namen aus flows.json geholt und ihr
 * Code in node:vm ausgeführt, mit Attrappen für msg, node, context, flow,
 * global und env – so, wie Node-RED ihn ausführt. Geprüft wird der Code,
 * der tatsächlich ausgeliefert wird, nicht eine Kopie davon.
 *
 * Die Uhr (Date) ist ebenfalls eine Attrappe: Puffer, Wartezeiten und
 * „jünger als 30 s" lassen sich so prüfen, ohne zu warten.
 *
 * Exit-Code 0, wenn alles stimmt; sonst 1 mit einer Beschreibung dessen,
 * was abweicht.
 */

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const WURZEL = join(dirname(fileURLToPath(import.meta.url)), '..');
const FLOWS_DATEI = join(WURZEL, 'deploy', 'node-red', 'flows.json');
const flows = JSON.parse(readFileSync(FLOWS_DATEI, 'utf8'));

// ------------------------------------------------------------ Attrappen

const uhr = { jetzt: 1727200000000 };

// Date, dessen „jetzt" die Prüfung bestimmt. Date mit Argumenten bleibt
// das echte.
class Uhrzeit extends Date {
    constructor(...args) {
        if (args.length === 0) {
            super(uhr.jetzt);
        } else {
            super(...args);
        }
    }

    static now() {
        return uhr.jetzt;
    }
}

function speicher() {
    const daten = new Map();
    return {
        get: (k) => daten.get(k),
        set: (k, v) => {
            if (v === undefined) {
                daten.delete(k);
            } else {
                daten.set(k, v);
            }
        },
        keys: () => [...daten.keys()],
    };
}

// Holt eine Function-Node über ihren Namen und macht sie aufrufbar.
// Rückgabe von lauf(msg): ein Feld je Ausgang, darin die Liste der
// Nachrichten – so, wie Node-RED die Rückgabe verteilt. Nodes desselben
// Tabs teilen sich „flow", alle Nodes teilen sich „global".
function funktion(name, { flow = speicher(), global: globalKontext = speicher(), env = {} } = {}) {
    const def = flows.find((n) => n.type === 'function' && n.name === name);
    if (!def) {
        throw new Error('Function-Node „' + name + '" fehlt in flows.json');
    }
    const protokoll = { warnungen: [], fehler: [], status: [] };
    const gesendet = [];
    const node = {
        id: def.id,
        name: def.name,
        warn: (t) => protokoll.warnungen.push(String(t)),
        error: (t) => protokoll.fehler.push(String(t)),
        log: () => {},
        debug: () => {},
        trace: () => {},
        status: (s) => protokoll.status.push(s),
        send: (m) => gesendet.push(m),
        done: () => {},
    };
    const sandbox = vm.createContext({
        node,
        context: speicher(),
        flow,
        global: globalKontext,
        env: { get: (k) => env[k] },
        Buffer,
        Date: Uhrzeit,
        console,
        setTimeout,
        clearTimeout,
    });
    const code = vm.runInContext('(function (msg) {\n' + def.func + '\n})', sandbox, { filename: def.name });

    function verteilen(ergebnis) {
        const ausgaenge = Array.from({ length: def.outputs }, () => []);
        if (ergebnis === null || ergebnis === undefined) {
            return ausgaenge;
        }
        const liste = Array.isArray(ergebnis) ? ergebnis : [ergebnis];
        liste.forEach((eintrag, i) => {
            if (i >= def.outputs || eintrag === null || eintrag === undefined) {
                return;
            }
            for (const m of Array.isArray(eintrag) ? eintrag : [eintrag]) {
                if (m !== null && m !== undefined) {
                    ausgaenge[i].push(m);
                }
            }
        });
        return ausgaenge;
    }

    return {
        def,
        protokoll,
        lauf(msg) {
            const ausgaenge = verteilen(code(msg));
            for (const m of gesendet.splice(0)) {
                verteilen(m).forEach((liste, i) => ausgaenge[i].push(...liste));
            }
            return ausgaenge;
        },
    };
}

// ------------------------------------------------------------ Prüfungen

const pruefungen = [];
function pruefe(titel, fn) {
    pruefungen.push({ titel, fn });
}

const lp = funktion('Messwert → Line Protocol');

// Normalisierte Nachrichten (und msg.punkte) durch den Umwandler schicken
// und alle entstandenen Zeilen einsammeln.
function zeilenAus(nachrichten) {
    const zeilen = [];
    for (const m of nachrichten) {
        const [aus] = lp.lauf(m);
        for (const a of aus) {
            zeilen.push(...a.zeilen);
        }
    }
    return zeilen;
}

const T = () => String(uhr.jetzt);

pruefe('flows.json nutzt nur Kern-Nodes und ist in sich stimmig', () => {
    const erlaubt = new Set(['tab', 'comment', 'mqtt-broker', 'mqtt in', 'mqtt out', 'function', 'inject',
        'http request', 'link in', 'link out', 'switch', 'change', 'debug', 'catch', 'status']);
    const ids = new Set(flows.map((n) => n.id));
    assert.equal(ids.size, flows.length, 'IDs sind nicht eindeutig');
    for (const n of flows) {
        assert.ok(erlaubt.has(n.type), 'kein Kern-Node: ' + n.type + ' (' + n.id + ')');
        for (const ziele of n.wires || []) {
            for (const ziel of ziele) {
                assert.ok(ids.has(ziel), n.id + ' verweist auf unbekannte Node ' + ziel);
            }
        }
        if (n.z) {
            assert.ok(ids.has(n.z), n.id + ' liegt auf unbekanntem Tab ' + n.z);
        }
        if (n.type === 'link out') {
            for (const ziel of n.links) {
                const gegen = flows.find((x) => x.id === ziel);
                assert.ok(gegen && gegen.type === 'link in' && gegen.links.includes(n.id), 'Link ' + n.id + ' → ' + ziel + ' einseitig');
            }
        }
        if (n.type === 'mqtt in' || n.type === 'mqtt out') {
            assert.equal(n.broker, 'pm_broker', n.id + ' nutzt einen anderen Broker');
        }
        if (n.type === 'function') {
            assert.deepEqual(n.libs, [], n.id + ' lädt Zusatzmodule');
        }
    }
    const broker = flows.find((n) => n.id === 'pm_broker');
    assert.equal(broker.broker, '${MQTT_HOST}');
    assert.equal(broker.port, '${MQTT_PORT}');
    assert.ok(!('credentials' in broker) && !('user' in broker) && !('password' in broker), 'Zugangsdaten stehen in flows.json');
    const themen = flows.filter((n) => n.type === 'mqtt in').map((n) => n.topic).sort();
    assert.deepEqual(themen, ['+/online', '+/pm-nodered/rpc', '+/status/#', 'pm-nodered/rpc', 'pm/messwert/#',
        'pm/status/mqtt-zugaenge', 'shellies/#', 'shelly/+/online', 'shelly/+/pm-nodered/rpc', 'shelly/+/status/#']);
    // Liste der MQTT-Zugänge: retained mit QoS 1, an „MQTT-Zugänge merken"
    // aus der Quelle. Die Liste liegt im globalen Kontext, weil Gen1 und
    // Gen2+ auf verschiedenen Tabs liegen.
    const zugaenge = flows.filter((n) => n.type === 'mqtt in' && n.topic === 'pm/status/mqtt-zugaenge');
    assert.equal(zugaenge.length, 1);
    assert.equal(zugaenge[0].qos, '1');
    assert.equal(zugaenge[0].rap, true);
    assert.deepEqual(zugaenge[0].wires, [['pm_mqtt_zugaenge']]);
    const merken = flows.find((n) => n.id === 'pm_mqtt_zugaenge');
    assert.equal(merken.type, 'function');
    assert.equal(merken.name, 'MQTT-Zugänge merken');
    assert.equal(merken.func, readFileSync(join(WURZEL, 'deploy', 'node-red', 'funktionen', 'mqtt-zugaenge.js'), 'utf8'));
    const tabVon = (id) => flows.find((n) => n.id === id).z;
    assert.notEqual(tabVon('pm_mqtt_zugaenge'), tabVon('pm_gen2_uebersetzen'));
    assert.notEqual(tabVon('pm_gen1_uebersetzen'), tabVon('pm_gen2_uebersetzen'));
    // RPC-Antworten: im Baum des Geräts und auf dem alten Thema, alle an die
    // Geräteinfo, mit QoS 1
    const rpcEingaenge = flows.filter((n) => n.type === 'mqtt in' && n.topic.endsWith('pm-nodered/rpc'));
    assert.equal(rpcEingaenge.length, 3);
    for (const n of rpcEingaenge) {
        assert.equal(n.qos, '1', n.id + ': QoS');
        assert.deepEqual(n.wires, [['pm_gen2_geraeteinfo']], n.id + ': Ziel');
    }
    const status = flows.find((n) => n.id === 'pm_status_aus');
    assert.equal(status.topic, 'pm/status/nodered');
    assert.equal(status.retain, 'true');
});

pruefe('flows.json entspricht den Quellen in deploy/node-red/funktionen', () => {
    execFileSync(process.execPath, [join(WURZEL, 'deploy', 'node-red', 'flows-bauen.mjs'), '--pruefen'], { stdio: 'pipe' });
});

pruefe('Shelly Pro 3EM: em:0 ergibt Phasen L1–L3 und Summe, leeres N entfällt', () => {
    const gen2 = funktion('Shelly Gen2+ übersetzen');
    const [aus] = gen2.lauf({
        topic: 'shellypro3em-a0b1c2d3e4f5/status/em:0',
        payload: JSON.stringify({
            id: 0, a_current: 1.234, a_voltage: 230.1, a_act_power: 250.3, a_aprt_power: 260, a_pf: 0.95, a_freq: 50,
            b_current: 0.5, b_voltage: 231, b_act_power: 100, b_aprt_power: 110, b_pf: 0.9, b_freq: 50,
            c_current: 0, c_voltage: 229.5, c_act_power: 0, c_aprt_power: 0, c_pf: 0, c_freq: 50,
            n_current: null, total_current: 1.734, total_act_power: 350.3, total_aprt_power: 370,
            user_calibrated_phase: [],
        }),
    });
    const zeilen = zeilenAus(aus).filter((z) => z.startsWith('strom,'));
    const g = 'geraet=shellypro3em-a0b1c2d3e4f5,kanal=em:0';
    assert.deepEqual(zeilen, [
        `strom,${g},phase=L1,typ=shelly u=230.1,i=1.234,p=250.3,s=260,pf=0.95,f=50 ${T()}`,
        `strom,${g},phase=L2,typ=shelly u=231,i=0.5,p=100,s=110,pf=0.9,f=50 ${T()}`,
        `strom,${g},phase=L3,typ=shelly u=229.5,i=0,p=0,s=0,pf=0,f=50 ${T()}`,
        `strom,${g},phase=sum,typ=shelly i=1.734,p=350.3,s=370 ${T()}`,
    ]);
});

pruefe('Shelly Pro 3EM: emdata:0 landet als Energie im Kanal em:0', () => {
    const gen2 = funktion('Shelly Gen2+ übersetzen');
    const [aus] = gen2.lauf({
        topic: 'shellypro3em-a0b1c2d3e4f5/status/emdata:0',
        payload: JSON.stringify({
            id: 0, a_total_act_energy: 1000.5, a_total_act_ret_energy: 0, b_total_act_energy: 2000,
            b_total_act_ret_energy: 1.5, c_total_act_energy: 3000, c_total_act_ret_energy: 0,
            total_act: 6000.5, total_act_ret: 1.5,
        }),
    });
    const zeilen = zeilenAus(aus).filter((z) => z.startsWith('strom,'));
    const g = 'geraet=shellypro3em-a0b1c2d3e4f5,kanal=em:0';
    assert.deepEqual(zeilen, [
        `strom,${g},phase=L1,typ=shelly e=1000.5,e_rueck=0 ${T()}`,
        `strom,${g},phase=L2,typ=shelly e=2000,e_rueck=1.5 ${T()}`,
        `strom,${g},phase=L3,typ=shelly e=3000,e_rueck=0 ${T()}`,
        `strom,${g},phase=sum,typ=shelly e=6000.5,e_rueck=1.5 ${T()}`,
    ]);
});

pruefe('Shelly Gen2 switch:0 ergibt Leistung (sum) und Zustand (Schalter, Temperatur)', () => {
    const gen2 = funktion('Shelly Gen2+ übersetzen');
    const [aus] = gen2.lauf({
        topic: 'shelly/kuehlhaus/status/switch:0',
        payload: JSON.stringify({
            id: 0, source: 'init', output: true, apower: 1200.5, voltage: 229.8, freq: 49.98, current: 5.3, pf: 0.99,
            aenergy: { total: 12345.678, by_minute: [1, 2, 3], minute_ts: 1727199960 },
            ret_aenergy: { total: 0 }, temperature: { tC: 41.2, tF: 106.2 },
        }),
    });
    const zeilen = zeilenAus(aus).filter((z) => !z.startsWith('geraet,'));
    assert.deepEqual(zeilen, [
        `strom,geraet=kuehlhaus,kanal=switch:0,phase=sum,typ=shelly u=229.8,i=5.3,p=1200.5,pf=0.99,f=49.98,e=12345.678,e_rueck=0 ${T()}`,
        `zustand,geraet=kuehlhaus,kanal=switch:0,typ=shelly schalter=1,temperatur=41.2 ${T()}`,
    ]);
});

pruefe('Shelly Gen2: online, Geräteinfo per RPC und fremde Themen', () => {
    const flow = speicher();
    const gen2 = funktion('Shelly Gen2+ übersetzen', { flow });
    const info = funktion('Shelly Gen2+ Geräteinfo', { flow });

    // Verbindungszustand – Kanal „geraet"
    const [online] = gen2.lauf({ topic: 'shellyplus1pm-aabbcc/online', payload: 'true' });
    assert.deepEqual(zeilenAus(online), [`zustand,geraet=shellyplus1pm-aabbcc,kanal=geraet,typ=shelly online=1 ${T()}`]);

    // Erstes Sehen: Geräteeintrag sofort, RPC-Anfrage an das Präfix,
    // Absender im Baum des Geräts
    const [aus, rpc] = gen2.lauf({ topic: 'shelly/Halle 3/status/sys', payload: '{"mac":"A0B1C2D3E4F5"}' });
    assert.deepEqual(zeilenAus(aus), [`geraet,geraet=halle-3,typ=shelly generation=2,praefix="shelly/Halle 3" ${T()}`]);
    assert.equal(rpc.length, 1);
    assert.equal(rpc[0].topic, 'shelly/Halle 3/rpc');
    const anfrage = JSON.parse(rpc[0].payload);
    assert.deepEqual({ src: anfrage.src, method: anfrage.method }, { src: 'shelly/Halle 3/pm-nodered', method: 'Shelly.GetDeviceInfo' });

    // Antwort: Zuordnung über die Anfrage-ID, nicht über src
    const [punkte] = info.lauf({
        topic: 'shelly/Halle 3/pm-nodered/rpc',
        payload: JSON.stringify({
            id: anfrage.id, src: 'shellyplus1pm-a0b1c2d3e4f5', dst: 'shelly/Halle 3/pm-nodered',
            result: { name: 'Halle "3"', id: 'shellyplus1pm-a0b1c2d3e4f5', mac: 'A0B1C2D3E4F5', model: 'SNSW-001P16EU', gen: 2, ver: '1.4.4', app: 'Plus1PM' },
        }),
    });
    assert.deepEqual(zeilenAus(punkte), [
        `geraet,geraet=halle-3,typ=shelly modell="SNSW-001P16EU",produkt="Plus1PM",name="Halle \\"3\\"",firmware="1.4.4",generation=2,mac="A0B1C2D3E4F5",praefix="shelly/Halle 3" ${T()}`,
    ]);

    // Innerhalb von 6 h keine zweite Anfrage, danach wieder
    assert.equal(gen2.lauf({ topic: 'shelly/Halle 3/status/sys', payload: '{}' })[1].length, 0);
    uhr.jetzt += 6 * 3600 * 1000 + 1;
    assert.equal(gen2.lauf({ topic: 'shelly/Halle 3/status/sys', payload: '{}' })[1].length, 1);
    uhr.jetzt -= 6 * 3600 * 1000 + 1;

    // Eigene Themen und Gen1 gehören nicht hierher
    assert.deepEqual(gen2.lauf({ topic: 'pm/status/nodered', payload: '{"geschrieben":1}' }), [[], []]);
    assert.deepEqual(gen2.lauf({ topic: 'shellies/online', payload: 'true' }), [[], []]);
    // Absender von Node-RED und Oberfläche (pm-…), auch im Baum eines Geräts
    assert.deepEqual(gen2.lauf({ topic: 'pm-nodered/status/sys', payload: '{}' }), [[], []]);
    assert.deepEqual(gen2.lauf({ topic: 'pm-steuerung-0a1b2c3d/online', payload: 'true' }), [[], []]);
    assert.deepEqual(gen2.lauf({ topic: 'shellyplus1pm-neu/pm-probe', payload: 'true' }), [[], []]);
    assert.deepEqual(gen2.lauf({ topic: 'shelly/halle-9/pm-probe/status/sys', payload: '{}' }), [[], []]);
    assert.deepEqual(gen2.lauf({ topic: '$SYS/status/x', payload: '{}' }), [[], []]);
    assert.equal(flow.get('gen2_geraete')['halle-9'], undefined, 'Prüf-Thema hat ein Gerät angelegt');
});

// Offene Anfragen im Flow-Kontext, als Liste
const offeneAnfragen = (flow) => Object.values(flow.get('gen2_rpc') || {});
const geraeteinfo = (id, dst, zusatz = {}) => JSON.stringify(Object.assign({
    id, src: 'shellyplus1pm-a0b1c2d3e4f5', dst,
    result: { id: 'shellyplus1pm-a0b1c2d3e4f5', mac: 'A0B1C2D3E4F5', model: 'SNSW-001P16EU', gen: 2, ver: '1.5.0', app: 'Plus1PM' },
}, zusatz));

pruefe('Shelly Gen2: RPC-Absender im Baum des Geräts (<id> und shelly/<name>)', () => {
    const flow = speicher();
    const gen2 = funktion('Shelly Gen2+ übersetzen', { flow });
    for (const [thema, praefix, geraet] of [
        ['shellypro4pm-a0b1c2d3e4f5/status/switch:0', 'shellypro4pm-a0b1c2d3e4f5', 'shellypro4pm-a0b1c2d3e4f5'],
        ['shelly/kuehlhaus/status/switch:0', 'shelly/kuehlhaus', 'kuehlhaus'],
    ]) {
        const [, rpc] = gen2.lauf({ topic: thema, payload: '{"id":0,"output":true}' });
        assert.equal(rpc.length, 1, thema);
        assert.equal(rpc[0].topic, praefix + '/rpc');
        const a = JSON.parse(rpc[0].payload);
        assert.equal(a.src, praefix + '/pm-nodered');
        assert.equal(a.method, 'Shelly.GetDeviceInfo');
        assert.ok(Number.isInteger(a.id), 'id ist eine ganze Zahl');
        // Objekte aus der Sandbox haben ein anderes Object.prototype – kopieren
        const offen = { ...flow.get('gen2_rpc')[a.id] };
        assert.deepEqual(offen, { geraet, praefix, src: praefix + '/pm-nodered', t: uhr.jetzt, versuch: 1 });
    }
    assert.deepEqual(offeneAnfragen(flow).map((a) => a.src).sort(),
        ['shelly/kuehlhaus/pm-nodered', 'shellypro4pm-a0b1c2d3e4f5/pm-nodered']);

    // Gerätenamen wie Eigenschaften von Object.prototype
    const [, rpc] = gen2.lauf({ topic: 'shelly/constructor/status/switch:0', payload: '{"id":0}' });
    assert.equal(JSON.parse(rpc[0].payload).src, 'shelly/constructor/pm-nodered');
    assert.equal(gen2.lauf({ topic: 'shelly/constructor/status/switch:0', payload: '{"id":0}' })[1].length, 0);
    assert.deepEqual(gen2.lauf({ topic: '__proto__/status/switch:0', payload: '{"id":0}' }), [[], []]);
    assert.deepEqual(gen2.lauf({ topic: 'shelly/__PROTO__/online', payload: 'true' }), [[], []]);
    assert.equal(Object.keys(flow.get('gen2_geraete')).includes('__proto__'), false);
});

pruefe('Shelly Gen2 Geräteinfo: nur die passende Antwort zählt, sonst bleibt die Anfrage offen', () => {
    const flow = speicher();
    const gen2 = funktion('Shelly Gen2+ übersetzen', { flow });
    const info = funktion('Shelly Gen2+ Geräteinfo', { flow });
    const [, rpc] = gen2.lauf({ topic: 'shellyplus1pm-a0b1c2d3e4f5/status/switch:0', payload: '{"id":0}' });
    const { id, src } = JSON.parse(rpc[0].payload);
    const p = 'shellyplus1pm-a0b1c2d3e4f5';
    assert.equal(src, p + '/pm-nodered');

    // richtige id auf fremdem Thema: im Baum eines anderen Geräts, auf dem
    // alten Thema, unter shelly/<name>
    for (const thema of ['shellyplus1pm-fremd/pm-nodered/rpc', 'pm-nodered/rpc', 'shelly/fremd/pm-nodered/rpc']) {
        assert.deepEqual(info.lauf({ topic: thema, payload: geraeteinfo(id, src) }), [[]], thema);
    }
    // falsches dst
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(id, 'pm-nodered') }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(id, undefined) }), [[]]);
    // eine Anfrage oder Meldung (method) ist keine Antwort
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(id, src, { method: 'Shelly.GetDeviceInfo' }) }), [[]]);
    // ohne result und error, als Liste, kaputt, falsche Themen
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: JSON.stringify({ id, src: p, dst: src }) }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: JSON.stringify([{ id, dst: src, result: {} }]) }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: '{kaputt' }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc/x', payload: geraeteinfo(id, src) }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/rpc', payload: geraeteinfo(id, src) }), [[]]);
    assert.equal(offeneAnfragen(flow).length, 1, 'Anfrage wurde verworfen');
    assert.equal(info.protokoll.warnungen.length, 0);

    // die echte Antwort kommt danach noch an – mit Präfix
    const [punkte] = info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(id, src) });
    assert.deepEqual(zeilenAus(punkte), [
        `geraet,geraet=${p},typ=shelly modell="SNSW-001P16EU",produkt="Plus1PM",firmware="1.5.0",generation=2,mac="A0B1C2D3E4F5",praefix="${p}" ${T()}`,
    ]);
    assert.equal(offeneAnfragen(flow).length, 0, 'beantwortete Anfrage bleibt offen');

    // Fehlerantwort: Warnung, kein Punkt, Anfrage erledigt
    uhr.jetzt += 6 * 3600 * 1000 + 1;
    try {
        const [, rpc2] = gen2.lauf({ topic: p + '/status/switch:0', payload: '{"id":0}' });
        const a2 = JSON.parse(rpc2[0].payload);
        const [nichts] = info.lauf({ topic: p + '/pm-nodered/rpc', payload: JSON.stringify({ id: a2.id, src: p, dst: a2.src, error: { code: -103, message: 'busy' } }) });
        assert.equal(nichts.length, 0);
        assert.ok(info.protokoll.warnungen.some((w) => w.includes(p) && w.includes('busy')));
        assert.equal(offeneAnfragen(flow).length, 0);
    } finally {
        uhr.jetzt -= 6 * 3600 * 1000 + 1;
    }
});

pruefe('Shelly Gen2: ohne Antwort nach 60 s genau ein zweiter Versuch mit Absender pm-nodered', () => {
    const start = uhr.jetzt;
    try {
        const flow = speicher();
        const gen2 = funktion('Shelly Gen2+ übersetzen', { flow });
        const info = funktion('Shelly Gen2+ Geräteinfo', { flow });
        const p = 'shelly/werkstatt';
        const status = () => gen2.lauf({ topic: p + '/status/switch:0', payload: '{"id":0,"apower":5}' })[1];

        const [erste] = status();
        const a1 = JSON.parse(erste.payload);
        assert.equal(a1.src, p + '/pm-nodered');
        uhr.jetzt = start + 30 * 1000;
        assert.equal(status().length, 0, 'nach 30 s schon ein zweiter Versuch');
        uhr.jetzt = start + 60 * 1000;
        assert.equal(status().length, 0, 'nach genau 60 s schon ein zweiter Versuch');
        // online ist keine Statusmeldung
        uhr.jetzt = start + 61 * 1000;
        assert.deepEqual(gen2.lauf({ topic: p + '/online', payload: 'true' })[1], []);

        const zweite = status();
        assert.equal(zweite.length, 1);
        assert.equal(zweite[0].topic, p + '/rpc');
        const a2 = JSON.parse(zweite[0].payload);
        assert.deepEqual({ src: a2.src, method: a2.method }, { src: 'pm-nodered', method: 'Shelly.GetDeviceInfo' });
        assert.notEqual(a2.id, a1.id);
        assert.deepEqual({ ...flow.get('gen2_rpc')[a2.id] }, { geraet: 'werkstatt', praefix: p, src: 'pm-nodered', t: uhr.jetzt, versuch: 2 });
        assert.equal(offeneAnfragen(flow).length, 2, 'Versuch 1 bleibt offen');

        // genau einmal
        for (const s of [62, 120, 599, 700]) {
            uhr.jetzt = start + s * 1000;
            assert.equal(status().length, 0, 'weiterer Versuch nach ' + s + ' s');
        }

        // Antwort auf Versuch 2 (altes Thema): Gerät aus der Anfrage, beide Versuche erledigt
        assert.deepEqual(info.lauf({ topic: 'pm-nodered/rpc', payload: geraeteinfo(a2.id, p + '/pm-nodered') }), [[]], 'dst des ersten Versuchs');
        const [punkte] = info.lauf({ topic: 'pm-nodered/rpc', payload: geraeteinfo(a2.id, 'pm-nodered') });
        assert.equal(zeilenAus(punkte)[0],
            `geraet,geraet=werkstatt,typ=shelly modell="SNSW-001P16EU",produkt="Plus1PM",firmware="1.5.0",generation=2,mac="A0B1C2D3E4F5",praefix="${p}" ${T()}`);
        assert.equal(offeneAnfragen(flow).length, 0);
        uhr.jetzt = start + 800 * 1000;
        assert.equal(status().length, 0);

        // Antwort auf Versuch 1 rechtzeitig: kein zweiter Versuch
        const q = 'shellyplusplugs-0a0b0c0d0e0f';
        const [b1] = gen2.lauf({ topic: q + '/status/switch:0', payload: '{"id":0}' })[1];
        const b = JSON.parse(b1.payload);
        uhr.jetzt += 10 * 1000;
        assert.equal(info.lauf({ topic: q + '/pm-nodered/rpc', payload: geraeteinfo(b.id, q + '/pm-nodered') })[0].length, 1);
        uhr.jetzt += 120 * 1000;
        assert.equal(gen2.lauf({ topic: q + '/status/switch:0', payload: '{"id":0}' })[1].length, 0);

        // Versuch 1 kommt nach Versuch 2 doch noch an: gilt, danach ist nichts mehr offen
        const r = 'shelly/spaet';
        const [c1] = gen2.lauf({ topic: r + '/status/switch:0', payload: '{"id":0}' })[1];
        uhr.jetzt += 61 * 1000;
        const [c2] = gen2.lauf({ topic: r + '/status/switch:0', payload: '{"id":0}' })[1];
        assert.equal(JSON.parse(c2.payload).src, 'pm-nodered');
        const c = JSON.parse(c1.payload);
        assert.equal(info.lauf({ topic: r + '/pm-nodered/rpc', payload: geraeteinfo(c.id, r + '/pm-nodered') })[0].length, 1);
        assert.equal(offeneAnfragen(flow).filter((a) => a.praefix === r).length, 0);
    } finally {
        uhr.jetzt = start;
    }
});

pruefe('Shelly Gen2: Rückfall auch nach langer Pause des Geräts; Aufräumen der offenen Anfragen', () => {
    const start = uhr.jetzt;
    try {
        const flow = speicher();
        const gen2 = funktion('Shelly Gen2+ übersetzen', { flow });
        const status = (p) => gen2.lauf({ topic: p + '/status/switch:0', payload: '{"id":0}' })[1];
        const praefixeOffen = () => offeneAnfragen(flow).map((a) => a.praefix + ':' + a.versuch).sort();

        // Versuch 1 an ein Gerät, das danach 11 min schweigt; inzwischen
        // meldet sich ein anderes Gerät zum ersten Mal und räumt auf
        assert.equal(status('shelly/ruhig').length, 1);
        uhr.jetzt = start + 11 * 60 * 1000;
        assert.equal(status('shellyplus1-zweites').length, 1);
        assert.deepEqual(praefixeOffen(), ['shelly/ruhig:1', 'shellyplus1-zweites:1'], 'Versuch 1 ohne Rückfall verfallen');
        // die nächste Statusmeldung bringt den Rückfall trotzdem
        uhr.jetzt += 1000;
        const zweite = status('shelly/ruhig');
        assert.equal(zweite.length, 1);
        assert.equal(JSON.parse(zweite[0].payload).src, 'pm-nodered');

        // mit Rückfall verfallen beide Versuche nach 10 min, ein Versuch 1
        // ohne Rückfall erst nach 6 h
        uhr.jetzt += 11 * 60 * 1000;
        assert.equal(status('shellyplus1-drittes').length, 1);
        assert.deepEqual(praefixeOffen(), ['shellyplus1-drittes:1', 'shellyplus1-zweites:1']);
        uhr.jetzt = start + 11 * 60 * 1000 + 6 * 3600 * 1000 + 1;
        assert.equal(status('shellyplus1-viertes').length, 1);
        assert.deepEqual(praefixeOffen(), ['shellyplus1-drittes:1', 'shellyplus1-viertes:1']);

        // neues Präfix für denselben Gerätenamen: neue Runde, die alten
        // Anfragen des Geräts sind überholt
        assert.equal(status('shelly/shellyplus1-drittes').length, 1);
        assert.deepEqual(praefixeOffen(), ['shelly/shellyplus1-drittes:1', 'shellyplus1-viertes:1']);
    } finally {
        uhr.jetzt = start;
    }
});

pruefe('Shelly Gen2 Geräteinfo nach einem Neustart: Thema im Baum ordnet zu, altes Thema nicht', () => {
    // Neustart von Node-RED: der Flow-Kontext ist leer
    const flow = speicher();
    const info = funktion('Shelly Gen2+ Geräteinfo', { flow });
    const p = 'shellyplus1pm-a0b1c2d3e4f5';

    const [punkte] = info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(1234567, p + '/pm-nodered') });
    assert.deepEqual(zeilenAus(punkte), [
        `geraet,geraet=${p},typ=shelly modell="SNSW-001P16EU",produkt="Plus1PM",firmware="1.5.0",generation=2,mac="A0B1C2D3E4F5",praefix="${p}" ${T()}`,
    ]);
    // shelly/<name>: Gerätename aus dem letzten Segment, Präfix vollständig
    const [lager] = info.lauf({ topic: 'shelly/Lager/pm-nodered/rpc', payload: geraeteinfo(7, 'shelly/Lager/pm-nodered') });
    assert.match(zeilenAus(lager)[0], /^geraet,geraet=lager,typ=shelly .*,praefix="shelly\/Lager" /);

    // altes Thema ohne offene Anfrage: von wem, ist nicht zu erkennen
    assert.deepEqual(info.lauf({ topic: 'pm-nodered/rpc', payload: geraeteinfo(1234567, 'pm-nodered') }), [[]]);
    // dst passt nicht zum Thema
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(1, 'pm-nodered') }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(1, 'shellyplus1pm-anders/pm-nodered') }), [[]]);
    // kein Präfix eines Shelly
    for (const praefix of ['pm', 'pm-steuerung-0a1b2c3d', 'shellies', 'a/b', 'shellies/x']) {
        assert.deepEqual(info.lauf({ topic: praefix + '/pm-nodered/rpc', payload: geraeteinfo(1, praefix + '/pm-nodered') }), [[]], praefix);
    }
    // method, id als Text, prototypische Schlüssel
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo(1, p + '/pm-nodered', { method: 'NotifyStatus' }) }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo('1', p + '/pm-nodered') }), [[]]);
    assert.deepEqual(info.lauf({ topic: p + '/pm-nodered/rpc', payload: geraeteinfo('constructor', p + '/pm-nodered') }), [[]]);
    assert.equal(flow.get('gen2_rpc'), undefined, 'Antwort ohne Anfrage hat den Kontext verändert');
});

pruefe('Shelly Gen1: Relais – energy in Wattminuten wird zu Wh, Schaltzustand 1/0', () => {
    const gen1 = funktion('Shelly Gen1 übersetzen');
    const zeilen = [
        ...zeilenAus(gen1.lauf({ topic: 'shellies/shelly1pm-84CCA8A1B2C3/relay/0/energy', payload: '600' })[0]),
        ...zeilenAus(gen1.lauf({ topic: 'shellies/shelly1pm-84CCA8A1B2C3/relay/0/power', payload: '42.5' })[0]),
        ...zeilenAus(gen1.lauf({ topic: 'shellies/shelly1pm-84CCA8A1B2C3/relay/0', payload: 'on' })[0]),
        ...zeilenAus(gen1.lauf({ topic: 'shellies/shelly1pm-84CCA8A1B2C3/relay/0/command', payload: 'off' })[0]),
    ];
    const g = 'geraet=shelly1pm-84cca8a1b2c3,kanal=relay:0';
    assert.deepEqual(zeilen, [
        `geraet,geraet=shelly1pm-84cca8a1b2c3,typ=shelly generation=1,praefix="shellies/shelly1pm-84CCA8A1B2C3" ${T()}`,
        `strom,${g},phase=sum,typ=shelly e=10 ${T()}`,
        `strom,${g},phase=sum,typ=shelly p=42.5 ${T()}`,
        `zustand,${g},typ=shelly schalter=1 ${T()}`,
    ]);
});

pruefe('Shelly Gen1 3EM: Phasen L1–L3, Summe erst wenn alle frisch sind', () => {
    const gen1 = funktion('Shelly Gen1 übersetzen');
    const id = 'shellies/shellyem3-A1B2C3';
    const lauf = (feld, n, wert) => zeilenAus(gen1.lauf({ topic: `${id}/emeter/${n}/${feld}`, payload: String(wert) })[0])
        .filter((z) => !z.startsWith('geraet,'));
    const g = 'geraet=shellyem3-a1b2c3,kanal=emeter';

    // Erste Runde: noch unklar, wie viele Phasen das Gerät hat – keine Summe
    assert.deepEqual(lauf('power', 0, 100), [`strom,${g},phase=L1,typ=shelly p=100 ${T()}`]);
    assert.deepEqual(lauf('reactive_power', 0, -3.5), [`strom,${g},phase=L1,typ=shelly q=-3.5 ${T()}`]);
    assert.equal(lauf('power', 1, 200).length, 1);
    assert.equal(lauf('power', 2, 300.5).length, 1);
    // Zweite Runde: Summe mit dem Wert der letzten Phase, alle aus dieser Runde
    assert.equal(lauf('power', 0, 110).length, 1);
    assert.equal(lauf('power', 1, 210).length, 1);
    assert.deepEqual(lauf('power', 2, 310.5), [
        `strom,${g},phase=L3,typ=shelly p=310.5 ${T()}`,
        `strom,${g},phase=sum,typ=shelly p=630.5 ${T()}`,
    ]);
    // L2 und L3 kommen erst nach 31 s – L1 ist dann zu alt, keine Summe
    lauf('power', 0, 120);
    uhr.jetzt += 31000;
    lauf('power', 1, 220);
    assert.equal(lauf('power', 2, 320).length, 1);
    // nächste vollständige Runde: wieder eine Summe
    lauf('power', 0, 130);
    lauf('power', 1, 230);
    assert.deepEqual(lauf('power', 2, 330).slice(1), [`strom,${g},phase=sum,typ=shelly p=690 ${T()}`]);
    // Zählerstände in Wh gehen unverändert als e/e_rueck
    assert.deepEqual(lauf('total_returned', 0, 12.5), [`strom,${g},phase=L1,typ=shelly e_rueck=12.5 ${T()}`]);
    uhr.jetzt -= 31000;
});

pruefe('Shelly Gen1: announce ergibt einen Geräteeintrag mit generation=1', () => {
    const gen1 = funktion('Shelly Gen1 übersetzen');
    const [aus] = gen1.lauf({
        topic: 'shellies/announce',
        payload: JSON.stringify({ id: 'shellyem3-A1B2C3', model: 'SHEM-3', mac: 'A0B1C2A1B2C3', ip: '192.168.10.23', new_fw: false, fw_ver: '20230913-114244/v1.14.0-gcb84623' }),
    });
    assert.deepEqual(zeilenAus(aus), [
        `geraet,geraet=shellyem3-a1b2c3,typ=shelly generation=1,praefix="shellies/shellyem3-A1B2C3",modell="SHEM-3",firmware="20230913-114244/v1.14.0-gcb84623",mac="A0B1C2A1B2C3",ip="192.168.10.23" ${T()}`,
    ]);
});

pruefe('Shelly Gen1: Prüf-Themen pm-… im Baum übergangen, announce im Baum des Geräts', () => {
    const flow = speicher();
    const gen1 = funktion('Shelly Gen1 übersetzen', { flow });
    for (const thema of ['shellies/shelly1-B929CC/pm-probe/0a1b2c3d', 'shellies/shelly1-B929CC/pm-probe/relay/0',
        'shellies/shelly1-B929CC/pm-konto-0a1b2c3d/rpc']) {
        assert.deepEqual(gen1.lauf({ topic: thema, payload: 'on' }), [[]], thema);
    }
    assert.equal(flow.get('gen1_geraete'), undefined, 'Prüf-Thema hat ein Gerät angelegt');

    // Eigener Zugang: shellies/announce ist gesperrt, shellies/<id>/announce nicht
    const [aus] = gen1.lauf({
        topic: 'shellies/shelly1-B929CC/announce',
        payload: JSON.stringify({ id: 'anderes-geraet', model: 'SHSW-1', mac: 'A4CF12B929CC', ip: '192.168.10.31', new_fw: false, fw_ver: '20230913-112003/v1.14.0-gcb84623' }),
    });
    assert.deepEqual(zeilenAus(aus), [
        `geraet,geraet=shelly1-b929cc,typ=shelly generation=1,praefix="shellies/shelly1-B929CC",modell="SHSW-1",firmware="20230913-112003/v1.14.0-gcb84623",mac="A4CF12B929CC",ip="192.168.10.31" ${T()}`,
    ]);
    // danach normale Werte des Geräts
    assert.deepEqual(zeilenAus(gen1.lauf({ topic: 'shellies/shelly1-B929CC/relay/0', payload: 'off' })[0]), [
        `geraet,geraet=shelly1-b929cc,typ=shelly generation=1,praefix="shellies/shelly1-B929CC" ${T()}`,
        `zustand,geraet=shelly1-b929cc,kanal=relay:0,typ=shelly schalter=0 ${T()}`,
    ]);

    // Gerätenamen wie Eigenschaften von Object.prototype: eigener Eintrag,
    // kein Fehler, keine Verschmutzung
    for (const name of ['constructor', 'toString']) {
        const p = 'shellies/' + name;
        const zeilen = [];
        for (const nr of [0, 1, 0, 1]) {
            zeilen.push(...zeilenAus(gen1.lauf({ topic: p + '/emeter/' + nr + '/power', payload: String(100 + nr) })[0]));
        }
        assert.equal(gen1.protokoll.fehler.length, 0, name);
        assert.ok(zeilen.includes(`geraet,geraet=${name.toLowerCase()},typ=shelly generation=1,praefix="${p}" ${T()}`), name + ': kein Geräteeintrag');
        assert.ok(zeilen.includes(`strom,geraet=${name.toLowerCase()},kanal=emeter,phase=sum,typ=shelly p=201 ${T()}`), name + ': keine Summe');
    }
    assert.deepEqual(gen1.lauf({ topic: 'shellies/__proto__/emeter/0/power', payload: '1' }), [[]]);
    assert.deepEqual(gen1.lauf({ topic: 'shellies/__proto__/announce', payload: '{"model":"x"}' }), [[]]);
    assert.equal(Object.getPrototypeOf(flow.get('gen1_geraete')), Object.getPrototypeOf(flow.get('gen1_emeter')));
    assert.equal(Object.keys(flow.get('gen1_geraete')).includes('__proto__'), false);

    // shellies/announce: die ID aus der Nutzlast muss genau eine Themenebene sein
    for (const id of ['a/b', 'x/pm-probe', '+', '#', '', 42, null, { id: 'x' }]) {
        assert.deepEqual(gen1.lauf({ topic: 'shellies/announce', payload: JSON.stringify({ id, model: 'SHSW-1' }) }), [[]], JSON.stringify(id));
    }
});

// Liste der Shellys mit eigenem MQTT-Zugang, wie sie die Oberfläche
// retained auf pm/status/mqtt-zugaenge veröffentlicht
const ZUGAENGE_THEMA = 'pm/status/mqtt-zugaenge';
const schutzliste = (geschuetzt) => ({ topic: ZUGAENGE_THEMA, payload: JSON.stringify({ ts: uhr.jetzt, geschuetzt }) });
const SCHUTZ = {
    kuehlhaus: 'shelly/kuehlhaus',
    'shellypro4pm-a0b1c2d3e4f5': 'shellypro4pm-a0b1c2d3e4f5',
    'shelly1-b929cc': 'shellies/shelly1-B929CC',
};
// Objekte aus der Sandbox haben ein anderes Object.prototype – kopieren
const gemerkt = (global) => ({ ...global.get('mqtt_geschuetzt') });

pruefe('MQTT-Zugänge: Liste im globalen Kontext, Ungültiges übergangen, letzte gültige gilt', () => {
    const global = speicher();
    const zug = funktion('MQTT-Zugänge merken', { global });
    assert.deepEqual(zug.lauf(schutzliste(SCHUTZ)), []);
    assert.deepEqual(gemerkt(global), SCHUTZ);
    assert.equal(zug.protokoll.warnungen.length, 0);
    assert.equal(zug.protokoll.status.at(-1).text, '3 geschützt');

    const p200 = 'shelly/' + 'x'.repeat(193);
    const ungueltig = [
        '{kaputt', '', 'null', '[]', '"text"', '42',
        '{}', '{"ts":1}', '{"geschuetzt":null}', '{"geschuetzt":[]}', '{"geschuetzt":"shelly/a"}',
        ...[{ a: '' }, { a: 42 }, { a: null }, { a: true }, { a: { p: 'shelly/a' } }, { a: ['shelly/a'] },
            { a: 'shelly/+' }, { a: 'shelly/#' }, { a: '+/status' }, { a: 'shelly/a\u0000' }, { a: p200 + 'x' },
            { '': 'shelly/a' }, { 'a+': 'shelly/a' }, { 'a#': 'shelly/a' }, { 'a\u0000': 'shelly/a' },
            // ein ungültiger Eintrag verwirft die ganze Nachricht
            { neu: 'shelly/neu', kaputt: '' },
        ].map((geschuetzt) => JSON.stringify({ ts: 1, geschuetzt })),
    ];
    for (const payload of ungueltig) {
        assert.deepEqual(zug.lauf({ topic: ZUGAENGE_THEMA, payload }), [], payload);
        assert.deepEqual(gemerkt(global), SCHUTZ, 'übernommen: ' + payload);
    }
    assert.equal(zug.protokoll.warnungen.length, ungueltig.length);
    assert.ok(zug.protokoll.warnungen.every((w) => !w.includes('shelly/')), 'Warnung nennt den Inhalt');
    assert.equal(zug.protokoll.status.at(-1).text, 'ungültig übergangen – 3 geschützt');

    // Puffer und 200 Zeichen gehen; „__proto__" wird ausgelassen, ohne den
    // Prototyp zu setzen
    zug.lauf({
        topic: ZUGAENGE_THEMA,
        payload: Buffer.from('{"ts":1,"geschuetzt":{"__proto__":{"kuehlhaus":"x"},"lang":"' + p200 + '","constructor":"shelly/constructor"}}'),
    });
    const liste = global.get('mqtt_geschuetzt');
    assert.deepEqual(gemerkt(global), { lang: p200, constructor: 'shelly/constructor' });
    assert.equal(Object.getPrototypeOf(Object.getPrototypeOf(liste)), null, 'Prototyp der Liste verändert');
    assert.equal(liste.kuehlhaus, undefined);

    // leere Liste: nichts geschützt
    zug.lauf(schutzliste({}));
    assert.deepEqual(gemerkt(global), {});
    assert.equal(zug.protokoll.status.at(-1).text, '0 geschützt');
});

pruefe('MQTT-Zugänge: Doppelgänger eines Geräts mit eigenem Zugang verworfen, sein Präfix nicht', () => {
    const start = uhr.jetzt;
    try {
        // „MQTT-Zugänge merken", die Gen2+-Funktionen und Gen1 liegen auf
        // verschiedenen Tabs: eigener Flow-Kontext, gemeinsamer globaler
        const global = speicher();
        const flowGen2 = speicher();
        const flowGen1 = speicher();
        const zug = funktion('MQTT-Zugänge merken', { global });
        const gen2 = funktion('Shelly Gen2+ übersetzen', { flow: flowGen2, global });
        const info = funktion('Shelly Gen2+ Geräteinfo', { flow: flowGen2, global });
        const gen1 = funktion('Shelly Gen1 übersetzen', { flow: flowGen1, global });
        const schalter = '{"id":0,"output":true,"apower":5}';

        // Noch ohne Liste: ein Doppelgänger von shelly/kuehlhaus bekommt eine
        // Anfrage – sie ist noch offen, wenn die Liste ankommt
        const [, rpcAlt] = gen2.lauf({ topic: 'kuehlhaus/status/switch:0', payload: schalter });
        const alt = JSON.parse(rpcAlt[0].payload);
        assert.equal(alt.src, 'kuehlhaus/pm-nodered');

        zug.lauf(schutzliste(SCHUTZ));

        // Gen2+: einteiliges Präfix statt shelly/<name>, andere Schreibweise,
        // shelly/<id> statt <id>, Gerätename eines Gen1-Geräts
        for (const thema of [
            'kuehlhaus/status/switch:0', 'kuehlhaus/online', 'Kuehlhaus/status/switch:0', 'shelly/KUEHLHAUS/status/switch:0',
            'shelly/shellypro4pm-a0b1c2d3e4f5/status/switch:0', 'SHELLYPRO4PM-A0B1C2D3E4F5/online',
            'shelly1-b929cc/status/switch:0', 'shelly/shelly1-B929CC/online',
        ]) {
            assert.deepEqual(gen2.lauf({ topic: thema, payload: thema.endsWith('/online') ? 'true' : schalter }), [[], []], thema);
        }
        assert.deepEqual(Object.keys(flowGen2.get('gen2_geraete')), ['kuehlhaus'], 'Doppelgänger hat ein Gerät angelegt');

        // Geräteinfo unter dem Doppelgänger: weder auf die alte Anfrage noch
        // ohne Anfrage (nach einem Neustart) – die Anfrage bleibt offen
        assert.deepEqual(info.lauf({ topic: 'kuehlhaus/pm-nodered/rpc', payload: geraeteinfo(alt.id, alt.src) }), [[]]);
        assert.deepEqual(info.lauf({ topic: 'kuehlhaus/pm-nodered/rpc', payload: geraeteinfo(4711, 'kuehlhaus/pm-nodered') }), [[]]);
        assert.deepEqual(info.lauf({ topic: 'shelly/Kuehlhaus/pm-nodered/rpc', payload: geraeteinfo(4712, 'shelly/Kuehlhaus/pm-nodered') }), [[]]);
        assert.deepEqual(info.lauf({ topic: 'shelly1-b929cc/pm-nodered/rpc', payload: geraeteinfo(4713, 'shelly1-b929cc/pm-nodered') }), [[]]);
        assert.deepEqual(offeneAnfragen(flowGen2).map((a) => a.src), ['kuehlhaus/pm-nodered']);

        // das eigene Präfix geht durch wie bisher
        const [werte, rpc] = gen2.lauf({ topic: 'shelly/kuehlhaus/status/switch:0', payload: schalter });
        assert.deepEqual(zeilenAus(werte), [
            `strom,geraet=kuehlhaus,kanal=switch:0,phase=sum,typ=shelly p=5 ${T()}`,
            `zustand,geraet=kuehlhaus,kanal=switch:0,typ=shelly schalter=1 ${T()}`,
            `geraet,geraet=kuehlhaus,typ=shelly generation=2,praefix="shelly/kuehlhaus" ${T()}`,
        ]);
        const a1 = JSON.parse(rpc[0].payload);
        assert.equal(a1.src, 'shelly/kuehlhaus/pm-nodered');
        assert.deepEqual(zeilenAus(gen2.lauf({ topic: 'shellypro4pm-a0b1c2d3e4f5/online', payload: 'false' })[0]),
            [`zustand,geraet=shellypro4pm-a0b1c2d3e4f5,kanal=geraet,typ=shelly online=0 ${T()}`]);

        // Rückfall auf pm-nodered/rpc: dorthin darf das Gerät nicht schreiben,
        // die Antwort zählt nicht; die im eigenen Baum schon
        uhr.jetzt += 61 * 1000;
        const [rueckfall] = gen2.lauf({ topic: 'shelly/kuehlhaus/status/switch:0', payload: schalter })[1];
        const a2 = JSON.parse(rueckfall.payload);
        assert.equal(a2.src, 'pm-nodered');
        assert.deepEqual(info.lauf({ topic: 'pm-nodered/rpc', payload: geraeteinfo(a2.id, 'pm-nodered') }), [[]]);
        assert.equal(offeneAnfragen(flowGen2).filter((a) => a.geraet === 'kuehlhaus' && a.praefix === 'shelly/kuehlhaus').length, 2);
        const [punkte] = info.lauf({ topic: 'shelly/kuehlhaus/pm-nodered/rpc', payload: geraeteinfo(a1.id, a1.src) });
        assert.equal(zeilenAus(punkte)[0],
            `geraet,geraet=kuehlhaus,typ=shelly modell="SNSW-001P16EU",produkt="Plus1PM",firmware="1.5.0",generation=2,mac="A0B1C2D3E4F5",praefix="shelly/kuehlhaus" ${T()}`);
        assert.equal(offeneAnfragen(flowGen2).filter((a) => a.praefix === 'shelly/kuehlhaus').length, 0);
        // nach einem Neustart im eigenen Baum ebenso
        assert.equal(info.lauf({ topic: 'shelly/kuehlhaus/pm-nodered/rpc', payload: geraeteinfo(99, 'shelly/kuehlhaus/pm-nodered') })[0].length, 1);
        assert.equal(info.protokoll.warnungen.length, 0);

        // Gen1: andere Schreibweise der ID, shellies/announce (dort darf ein
        // Gerät mit eigenem Zugang nicht schreiben), Gerätenamen von Gen2+-Geräten
        for (const [thema, payload] of [
            ['shellies/SHELLY1-b929cc/relay/0', 'on'],
            ['shellies/shelly1-b929cc/relay/0/power', '1000'],
            ['shellies/Shelly1-B929CC/online', 'false'],
            ['shellies/shelly1-b929cc/announce', JSON.stringify({ id: 'shelly1-b929cc', model: 'SHSW-1', fw_ver: 'falsch' })],
            ['shellies/announce', JSON.stringify({ id: 'shelly1-B929CC', model: 'SHSW-1', fw_ver: 'falsch' })],
            ['shellies/announce', JSON.stringify({ id: 'SHELLY1-b929cc', model: 'SHSW-1' })],
            ['shellies/kuehlhaus/relay/0', 'off'],
            ['shellies/shellypro4pm-a0b1c2d3e4f5/emeter/0/power', '1'],
        ]) {
            assert.deepEqual(gen1.lauf({ topic: thema, payload }), [[]], thema);
        }
        assert.equal(flowGen1.get('gen1_geraete'), undefined, 'Doppelgänger hat ein Gerät angelegt');
        assert.equal(flowGen1.get('gen1_emeter'), undefined);
        // das eigene Präfix geht durch
        assert.deepEqual(zeilenAus(gen1.lauf({ topic: 'shellies/shelly1-B929CC/relay/0', payload: 'on' })[0]), [
            `geraet,geraet=shelly1-b929cc,typ=shelly generation=1,praefix="shellies/shelly1-B929CC" ${T()}`,
            `zustand,geraet=shelly1-b929cc,kanal=relay:0,typ=shelly schalter=1 ${T()}`,
        ]);
        assert.deepEqual(zeilenAus(gen1.lauf({ topic: 'shellies/shelly1-B929CC/announce', payload: JSON.stringify({ id: 'x', model: 'SHSW-1' }) })[0]),
            [`geraet,geraet=shelly1-b929cc,typ=shelly generation=1,praefix="shellies/shelly1-B929CC",modell="SHSW-1" ${T()}`]);

        // Geräte ohne eigenen Zugang wie bisher
        assert.equal(zeilenAus(gen1.lauf({ topic: 'shellies/announce', payload: JSON.stringify({ id: 'shelly1-AAAAAA', model: 'SHSW-1' }) })[0]).length, 1);
        assert.equal(zeilenAus(gen1.lauf({ topic: 'shellies/SHELLY1-aaaaaa/relay/0', payload: 'on' })[0]).length, 2);
        assert.equal(zeilenAus(gen2.lauf({ topic: 'shellyplus1-frei/status/switch:0', payload: schalter })[0]).length, 3);
        assert.equal(zeilenAus(gen2.lauf({ topic: 'shelly/frei/online', payload: 'true' })[0]).length, 1);

        // Ungültiges ändert nichts – auch nicht eine gelöschte gespeicherte
        // Nachricht (leere Nutzlast)
        zug.lauf({ topic: ZUGAENGE_THEMA, payload: '' });
        zug.lauf({ topic: ZUGAENGE_THEMA, payload: JSON.stringify({ ts: 1, geschuetzt: { kuehlhaus: 'kuehlhaus/#' } }) });
        assert.deepEqual(gen2.lauf({ topic: 'kuehlhaus/status/switch:0', payload: schalter }), [[], []]);
        assert.deepEqual(gen1.lauf({ topic: 'shellies/SHELLY1-b929cc/relay/0', payload: 'on' }), [[]]);

        // leere Liste: kein Schutz mehr, alles wie vorher
        zug.lauf(schutzliste({}));
        assert.ok(zeilenAus(gen2.lauf({ topic: 'kuehlhaus/status/switch:0', payload: schalter })[0])
            .includes(`strom,geraet=kuehlhaus,kanal=switch:0,phase=sum,typ=shelly p=5 ${T()}`));
        assert.ok(zeilenAus(gen1.lauf({ topic: 'shellies/SHELLY1-b929cc/relay/0', payload: 'off' })[0])
            .includes(`zustand,geraet=shelly1-b929cc,kanal=relay:0,typ=shelly schalter=0 ${T()}`));
        assert.equal(info.lauf({ topic: 'kuehlhaus/pm-nodered/rpc', payload: geraeteinfo(4711, 'kuehlhaus/pm-nodered') })[0].length, 1);
    } finally {
        uhr.jetzt = start;
    }
});

pruefe('Escaping: Tags und Feldschlüssel (Komma, Leerzeichen, =, \\), Texte (" und \\)', () => {
    const zeilen = zeilenAus([{
        topic: 'pm/messwert/gen 1,a=b/kanal\\',
        payload: {
            ts: 1727200000123,
            typ: 'generator',
            zustand: { 'motor zustand': 'Läuft "gut"', 'a,b=c': 1, pfad: 'C:\\Temp\\', mehrzeilig: 'eins\nzwei' },
        },
    }]);
    assert.deepEqual(zeilen, [
        'zustand,geraet=gen\\ 1\\,a\\=b,kanal=kanal\\\\,typ=generator motor\\ zustand="Läuft \\"gut\\"",a\\,b\\=c=1,pfad="C:\\\\Temp\\\\",mehrzeilig="eins zwei" 1727200000123',
    ]);
});

pruefe('Float-Regel: kein i-Suffix, Wahrheitswerte 1/0, null/NaN/Infinity entfallen, leere Punkte fehlen', () => {
    const zeilen = zeilenAus([{
        topic: 'pm/messwert/victron-gx/batterie',
        payload: JSON.stringify({
            ts: 1727200000000,
            typ: 'victron',
            phasen: {
                sum: { u: 52, i: -12, p: '624.5', leer: null, text: 'n/a' },
                L1: { u: null, i: null },
                L2: {},
            },
            zustand: { soc: 87, laden: true, fehler: false, zahl_als_text: '42', nichts: null, gross: 1e21, tief: -0.000001 },
        }),
    }]);
    assert.deepEqual(zeilen, [
        'strom,geraet=victron-gx,kanal=batterie,phase=sum,typ=victron u=52,i=-12,p=624.5,text="n/a" 1727200000000',
        'zustand,geraet=victron-gx,kanal=batterie,typ=victron soc=87,laden=1,fehler=0,zahl_als_text="42",gross=1e+21,tief=-0.000001 1727200000000',
    ]);
    // NaN/Infinity kommen nicht über JSON, wohl aber über interne Nachrichten
    const [aus] = lp.lauf({ topic: 'pm/messwert/x/y', payload: { phasen: { sum: { p: NaN, q: Infinity, s: -Infinity } } } });
    assert.equal(aus.length, 0);
    for (const z of zeilen) {
        assert.ok(!/=-?\d+(\.\d+)?i[, ]/.test(z), 'Integer-Feld in: ' + z);
    }
});

pruefe('Generischer Eingang pm/messwert: Beispiel aus der Schnittstelle, Zeit in Sekunden', () => {
    const zeilen = zeilenAus([{
        topic: 'pm/messwert/comap-ig200/generator',
        payload: JSON.stringify({
            ts: 1727200000,
            typ: 'comap',
            modell: 'InteliGen 200',
            phasen: {
                L1: { u: 230.1, i: 1.2, p: 250.3, q: 0, s: 260, pf: 0.95, f: 50.0, e: 1234.5, e_rueck: 0 },
                N: { i: 0.3 },
                sum: { p: 800.2, i: 3.5, e: 123456.7 },
            },
            zustand: { soc: 87, schalter: 1, motorzustand: 'Läuft' },
        }),
    }]);
    const g = 'geraet=comap-ig200,kanal=generator';
    assert.deepEqual(zeilen, [
        `strom,${g},phase=L1,typ=comap u=230.1,i=1.2,p=250.3,q=0,s=260,pf=0.95,f=50,e=1234.5,e_rueck=0 1727200000000`,
        `strom,${g},phase=N,typ=comap i=0.3 1727200000000`,
        `strom,${g},phase=sum,typ=comap p=800.2,i=3.5,e=123456.7 1727200000000`,
        `zustand,${g},typ=comap soc=87,schalter=1,motorzustand="Läuft" 1727200000000`,
    ]);
    // Ohne ts gilt die Empfangszeit, ohne typ entfällt der Tag
    assert.deepEqual(zeilenAus([{ topic: 'pm/messwert/zaehler', payload: '{"phasen":{"sum":{"e":5}}}' }]),
        [`strom,geraet=zaehler,kanal=geraet,phase=sum e=5 ${T()}`]);
    // Kaputtes JSON: nichts schreiben, aber zählen und melden
    const vorher = lp.protokoll.warnungen.length;
    assert.equal(lp.lauf({ topic: 'pm/messwert/a/b', payload: '{kaputt' })[0].length, 0);
    assert.equal(lp.protokoll.warnungen.length, vorher + 1);
});

pruefe('Schreiber: bündelt, meldet sich an, puffert bei Fehlern und wiederholt', () => {
    const env = { INFLUX_URL: 'http://127.0.0.1:8086/', INFLUX_DB: 'strom', INFLUX_TOKEN: 'geheim==' };
    const s = funktion('InfluxDB-Schreiber', { env });
    const zeilen = (n, p = 'z') => Array.from({ length: n }, (_, i) => `test,q=${p} w=${i} 1`);

    // unter 500 Zeilen: warten auf den Takt
    assert.deepEqual(s.lauf({ zeilen: zeilen(10) }), [[], []]);
    let [anfrage] = s.lauf({ takt: true });
    assert.equal(anfrage.length, 1);
    assert.equal(anfrage[0].url, 'http://127.0.0.1:8086/write?db=strom&precision=ms');
    assert.equal(anfrage[0].headers.Authorization, 'Token geheim==');
    assert.equal(anfrage[0].payload.split('\n').length, 10);
    assert.equal(anfrage[0].method, undefined, 'msg.method brächte im http-request-Node eine Warnung');

    // während eine Anfrage läuft, geht keine zweite hinaus
    s.lauf({ zeilen: zeilen(600) });
    assert.equal(s.lauf({ takt: true })[0].length, 0);

    // Antwort 204: sofort der nächste Stapel, weil ≥ 500 Zeilen warten
    [anfrage] = s.lauf({ pmStapel: anfrage[0].pmStapel, statusCode: 204, payload: '' });
    assert.equal(anfrage.length, 1);
    assert.equal(anfrage[0].payload.split('\n').length, 600);

    // Verbindungsfehler: kommt doppelt (Ausgang und Catch), zählt einmal
    const stapel = anfrage[0].pmStapel;
    s.lauf({ pmStapel: stapel, statusCode: 'ECONNREFUSED', payload: 'Error: connect ECONNREFUSED' });
    s.lauf({ pmStapel: stapel, error: { message: 'connect ECONNREFUSED' } });
    assert.equal(s.lauf({ takt: true })[0].length, 0, 'sofort erneut statt nach einer Pause');
    uhr.jetzt += 2000;
    [anfrage] = s.lauf({ takt: true });
    assert.equal(anfrage.length, 1);
    assert.equal(anfrage[0].payload.split('\n').length, 600, 'Zeilen gingen beim Fehler verloren');
    s.lauf({ pmStapel: anfrage[0].pmStapel, statusCode: 204 });

    // Herzschlag: Zeile in den Puffer, Status retained auf MQTT
    let [, meldung] = s.lauf({ herzschlag: true });
    assert.equal(meldung[0].topic, 'pm/status/nodered');
    assert.equal(meldung[0].retain, true);
    const zahlen = JSON.parse(meldung[0].payload);
    assert.deepEqual({ g: zahlen.geschrieben, f: zahlen.fehler, p: zahlen.puffer, v: zahlen.verworfen, ts: zahlen.ts },
        { g: 610, f: 1, p: 0, v: 0, ts: uhr.jetzt });
    [anfrage] = s.lauf({ takt: true });
    assert.equal(anfrage[0].payload, `pipeline,quelle=nodered geschrieben=610,fehler=1,puffer=0,verworfen=0 ${T()}`);

    // 400: Inhalt abgelehnt – nicht wiederholen, sondern verwerfen und zählen
    s.lauf({ pmStapel: anfrage[0].pmStapel, statusCode: 400, payload: '{"error":"unable to parse"}' });
    assert.ok(s.protokoll.warnungen.some((w) => w.includes('unable to parse')));
    assert.equal(s.lauf({ takt: true })[0].length, 0);
    [, meldung] = s.lauf({ herzschlag: true });
    assert.equal(JSON.parse(meldung[0].payload).verworfen, 1);

    // Feldtyp-Konflikt (partial write): die übrigen Zeilen sind geschrieben,
    // verworfen ist nur, was InfluxDB als „dropped" meldet
    s.lauf({ zeilen: zeilen(9) });
    [anfrage] = s.lauf({ takt: true });
    s.lauf({
        pmStapel: anfrage[0].pmStapel,
        statusCode: 400,
        payload: '{"error":"partial write: field type conflict: input field \\"motorzustand\\" on measurement \\"zustand\\" is type string, already exists as type float dropped=1"}',
    });
    [, meldung] = s.lauf({ herzschlag: true });
    const nachKonflikt = JSON.parse(meldung[0].payload);
    // im Stapel: der Herzschlag von eben und 9 Zeilen – eine abgelehnt
    assert.deepEqual({ g: nachKonflikt.geschrieben, v: nachKonflikt.verworfen }, { g: 610 + 9, v: 2 });
    uhr.jetzt -= 2000;
});

pruefe('Schreiber: höchstens 50 000 Zeilen, höchstens 5 Minuten; 1.8 mit Benutzer/Kennwort', () => {
    const s = funktion('InfluxDB-Schreiber', { env: { INFLUX_URL: 'http://influx:8086', INFLUX_USER: 'pm', INFLUX_PASSWORD: 'a&b' } });
    // Datenbank weg: der erste Stapel scheitert, danach sammelt sich alles
    s.lauf({ zeilen: Array.from({ length: 10 }, (_, i) => 'alt w=' + i + ' 1') });
    let [anfrage] = s.lauf({ takt: true });
    assert.equal(anfrage[0].url, 'http://influx:8086/write?db=strom&precision=ms&u=pm&p=a%26b');
    assert.equal(anfrage[0].headers.Authorization, undefined);
    s.lauf({ pmStapel: anfrage[0].pmStapel, statusCode: 503, payload: 'unavailable' });

    for (let i = 0; i < 60; i++) {
        s.lauf({ zeilen: Array.from({ length: 1000 }, (_, j) => 'neu w=' + j + ' 1') });
    }
    let [, meldung] = s.lauf({ herzschlag: true });
    let z = JSON.parse(meldung[0].payload);
    assert.equal(z.puffer, 50000);
    assert.equal(z.verworfen, 10 + 10000, 'die ältesten Zeilen müssen zuerst gehen');

    // 5 Minuten später ist alles zu alt
    uhr.jetzt += 5 * 60 * 1000 + 1;
    s.lauf({ takt: true });
    [, meldung] = s.lauf({ herzschlag: true });
    z = JSON.parse(meldung[0].payload);
    // Der erste Herzschlag hat eine Zeile über die Grenze geschoben (+1),
    // danach ist alles älter als 5 Minuten.
    assert.equal(z.puffer, 0);
    assert.equal(z.verworfen, 10 + 10000 + 1 + 50000);
    uhr.jetzt -= 5 * 60 * 1000 + 1;
});

// ------------------------------------------------------------ Ausführen

let fehler = 0;
for (const { titel, fn } of pruefungen) {
    try {
        fn();
        console.log('  ok    ' + titel);
    } catch (e) {
        fehler++;
        console.log('  FEHLER ' + titel);
        console.log(String(e && e.stack ? e.stack : e).split('\n').map((z) => '         ' + z).join('\n'));
    }
}
console.log('');
if (fehler > 0) {
    console.log(fehler + ' von ' + pruefungen.length + ' Prüfungen fehlgeschlagen');
    process.exit(1);
}
console.log(pruefungen.length + ' Prüfungen bestanden');
