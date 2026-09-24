/*
 * Shelly Gen2+ übersetzen
 *
 * Shelly Plus/Pro/Gen3/Gen4 schicken mit „Generic status update over MQTT"
 * den vollständigen Status jeder Komponente auf
 *     <präfix>/status/<komponente>      z. B. shellypro3em-a0b1c2d3e4f5/status/em:0
 * und ihren Verbindungszustand auf
 *     <präfix>/online                   "true" / "false"
 * Das Präfix ist ab Werk die Geräte-ID; unterstützt wird zusätzlich
 * shelly/<name>/…, wie es viele im Gerät von Hand eintragen.
 *
 * Ausgang 1: normalisierte Messwerte (dasselbe Format, das der PHP-Sammler
 *            auf pm/messwert/<gerät>/<kanal> schickt) und Punkte für die
 *            Geräteliste – beides geht weiter an „Messwert → Line Protocol".
 * Ausgang 2: RPC-Anfrage Shelly.GetDeviceInfo an <präfix>/rpc. Die Antwort
 *            kommt auf pm-nodered/rpc und landet in „Shelly Gen2+ Geräteinfo".
 *
 * Einheiten, wie sie der Shelly liefert und die Oberfläche erwartet:
 * u V, i A, p W, s VA, pf ohne Einheit, f Hz, e/e_rueck Wh (Zählerstand).
 */

const ABSENDER = 'pm-nodered';
const INFO_ALLE_MS = 6 * 3600 * 1000;    // Geräteinfo alle 6 h auffrischen
const ANFRAGE_VERFALL_MS = 10 * 60 * 1000;

// Themen, die zwar auf +/status/# oder +/online passen, aber nicht von
// einem Shelly kommen: die eigenen (pm/status/nodered!), Gen1 und die
// RPC-Antworten.
const FREMDE_PRAEFIXE = ['pm', 'shellies', ABSENDER, '$SYS'];

// Gerätename für Tags: letztes Segment des Präfixes, klein, nur
// [a-z0-9_.-] – alles andere wird zu „-". So bleibt der Name in Themen,
// Adressen und InfluxQL ohne Anführungszeichen benutzbar.
function geraeteName(praefix) {
    const teile = String(praefix).split('/');
    return teile[teile.length - 1].toLowerCase().replace(/[^a-z0-9_.-]/g, '-');
}

function zahl(wert) {
    return typeof wert === 'number' && Number.isFinite(wert) ? wert : undefined;
}

function einsNull(wert) {
    return typeof wert === 'boolean' ? (wert ? 1 : 0) : undefined;
}

// Entfernt leere Werte; liefert undefined, wenn nichts übrig bleibt – dann
// entsteht für diese Phase gar kein Punkt.
function bereinigt(werte) {
    const aus = {};
    for (const k of Object.keys(werte)) {
        if (werte[k] !== undefined && werte[k] !== null) {
            aus[k] = werte[k];
        }
    }
    return Object.keys(aus).length > 0 ? aus : undefined;
}

// Thema zerlegen: shelly/<name>/status/… oder <präfix>/status/…
const thema = String(msg.topic || '');
const teile = thema.split('/');
let praefix;
let art;
let komponente;
if (teile[0] === 'shelly' && teile.length >= 3 && (teile[2] === 'status' || teile[2] === 'online')) {
    praefix = teile.slice(0, 2).join('/');
    art = teile[2];
    komponente = teile.slice(3).join('/');
} else {
    praefix = teile[0];
    art = teile[1];
    komponente = teile.slice(2).join('/');
}
if (!praefix || FREMDE_PRAEFIXE.includes(teile[0])) {
    return null;
}

const geraet = geraeteName(praefix);
const jetzt = Date.now();
let text = msg.payload;
if (Buffer.isBuffer(text)) {
    text = text.toString('utf8');
}
text = typeof text === 'string' ? text : JSON.stringify(text);

function messwert(kanal, phasen, zustand) {
    const nutzlast = { ts: jetzt, typ: 'shelly' };
    let leer = true;
    const p = {};
    for (const phase of Object.keys(phasen || {})) {
        const werte = phasen[phase] ? bereinigt(phasen[phase]) : undefined;
        if (werte) {
            p[phase] = werte;
            leer = false;
        }
    }
    if (!leer) {
        nutzlast.phasen = p;
    }
    const z = zustand ? bereinigt(zustand) : undefined;
    if (z) {
        nutzlast.zustand = z;
        leer = false;
    }
    return leer ? null : { topic: 'pm/messwert/' + geraet + '/' + kanal, payload: nutzlast };
}

// ------------------------------------------------------------ online
if (art === 'online' && komponente === '') {
    const t = text.trim().toLowerCase();
    if (t !== 'true' && t !== 'false') {
        return null;
    }
    return [messwert('geraet', null, { online: t === 'true' ? 1 : 0 }), null];
}

if (art !== 'status' || !/^[a-z0-9_]+(:\d+)?$/.test(komponente)) {
    return null;
}

let s;
try {
    s = JSON.parse(text);
} catch (e) {
    return null;
}
if (s === null || typeof s !== 'object' || Array.isArray(s)) {
    return null;
}

const [typ, nummer] = komponente.split(':');
const nr = nummer === undefined ? '0' : nummer;
const ausgabe = [];

// ------------------------------------------------------------ Komponenten
if (typ === 'switch') {
    ausgabe.push(messwert(komponente, {
        sum: {
            u: zahl(s.voltage),
            i: zahl(s.current),
            p: zahl(s.apower),
            pf: zahl(s.pf),
            f: zahl(s.freq),
            e: s.aenergy ? zahl(s.aenergy.total) : undefined,
            e_rueck: s.ret_aenergy ? zahl(s.ret_aenergy.total) : undefined,
        },
    }, {
        schalter: einsNull(s.output),
        temperatur: s.temperature ? zahl(s.temperature.tC) : undefined,
    }));
} else if (typ === 'em') {
    // Pro 3EM im Drehstrom-Profil: a/b/c sind die Phasen L1/L2/L3.
    const phase = (x) => ({
        u: zahl(s[x + '_voltage']),
        i: zahl(s[x + '_current']),
        p: zahl(s[x + '_act_power']),
        s: zahl(s[x + '_aprt_power']),
        pf: zahl(s[x + '_pf']),
        f: zahl(s[x + '_freq']),
    });
    ausgabe.push(messwert('em:' + nr, {
        L1: phase('a'),
        L2: phase('b'),
        L3: phase('c'),
        N: { i: zahl(s.n_current) },
        sum: { i: zahl(s.total_current), p: zahl(s.total_act_power), s: zahl(s.total_aprt_power) },
    }));
} else if (typ === 'emdata') {
    // Zählerstände – bewusst derselbe Kanal wie die Leistung (em:N), damit
    // Leistung und Energie einer Messstelle zusammen abgefragt werden können.
    const phase = (x) => ({
        e: zahl(s[x + '_total_act_energy']),
        e_rueck: zahl(s[x + '_total_act_ret_energy']),
    });
    ausgabe.push(messwert('em:' + nr, {
        L1: phase('a'),
        L2: phase('b'),
        L3: phase('c'),
        sum: { e: zahl(s.total_act), e_rueck: zahl(s.total_act_ret) },
    }));
} else if (typ === 'em1') {
    ausgabe.push(messwert('em1:' + nr, {
        sum: {
            u: zahl(s.voltage),
            i: zahl(s.current),
            p: zahl(s.act_power),
            s: zahl(s.aprt_power),
            pf: zahl(s.pf),
            f: zahl(s.freq),
        },
    }));
} else if (typ === 'em1data') {
    ausgabe.push(messwert('em1:' + nr, {
        sum: { e: zahl(s.total_act_energy), e_rueck: zahl(s.total_act_ret_energy) },
    }));
} else if (typ === 'pm1') {
    ausgabe.push(messwert(komponente, {
        sum: {
            u: zahl(s.voltage),
            i: zahl(s.current),
            p: zahl(s.apower),
            pf: zahl(s.pf),
            f: zahl(s.freq),
            e: s.aenergy ? zahl(s.aenergy.total) : undefined,
            e_rueck: s.ret_aenergy ? zahl(s.ret_aenergy.total) : undefined,
        },
    }));
} else if (typ === 'temperature') {
    ausgabe.push(messwert(komponente, null, { temperatur: zahl(s.tC) }));
} else if (typ === 'input') {
    // Digitaleingang: state true/false. Analogeingang: percent.
    ausgabe.push(messwert(komponente, null, {
        eingang: einsNull(s.state) !== undefined ? einsNull(s.state) : zahl(s.percent),
    }));
}

// ------------------------------------------------------------ Geräteliste
// Beim ersten Sehen eines Geräts (und danach alle 6 h) nach Modell und
// Firmware fragen. Zusätzlich sofort einen Punkt mit Präfix und Generation
// schreiben: dann steht das Gerät in der Liste, auch wenn es auf RPC über
// MQTT nicht antwortet – und die Oberfläche kennt das Präfix, unter dem sie
// ihm Befehle schicken kann.
const bekannte = flow.get('gen2_geraete') || {};
const eintrag = bekannte[geraet];
let anfrage = null;
if (!eintrag || jetzt - eintrag.angefragt > INFO_ALLE_MS || eintrag.praefix !== praefix) {
    bekannte[geraet] = { praefix, angefragt: jetzt };
    flow.set('gen2_geraete', bekannte);

    const offen = flow.get('gen2_rpc') || {};
    for (const id of Object.keys(offen)) {
        if (jetzt - offen[id].t > ANFRAGE_VERFALL_MS) {
            delete offen[id];
        }
    }
    const id = (flow.get('gen2_rpc_nr') || 0) + 1;
    flow.set('gen2_rpc_nr', id);
    // Die Antwort nennt als src nur die Geräte-ID. Mit eigenem Präfix
    // (shelly/<name>) wäre daraus der Gerätename nicht abzuleiten – deshalb
    // merken, welche Anfrage an welches Gerät ging.
    offen[id] = { geraet, praefix, t: jetzt };
    flow.set('gen2_rpc', offen);

    anfrage = {
        topic: praefix + '/rpc',
        payload: JSON.stringify({ id, src: ABSENDER, method: 'Shelly.GetDeviceInfo' }),
    };
    ausgabe.push({
        punkte: [{
            messung: 'geraet',
            tags: { geraet, typ: 'shelly' },
            felder: { generation: 2, praefix },
            ts: jetzt,
        }],
    });

    node.status({ fill: 'green', shape: 'dot', text: Object.keys(bekannte).length + ' Geräte' });
}

const messwerte = ausgabe.filter((m) => m !== null);
return [messwerte.length > 0 ? messwerte : null, anfrage];
