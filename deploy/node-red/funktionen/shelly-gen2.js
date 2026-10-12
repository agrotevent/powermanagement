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
 * Ausgang 2: RPC-Anfrage Shelly.GetDeviceInfo an <präfix>/rpc mit dem
 *            Absender (src) <präfix>/pm-nodered. Der Shelly antwortet an
 *            <src>/rpc, also <präfix>/pm-nodered/rpc – im Baum des Geräts.
 *            Nur dorthin darf ein Shelly mit eigenem MQTT-Zugang schreiben
 *            (Benutzer = Präfix, Recht auf <präfix>/#), und genau diesen
 *            Baum sperrt der Broker für den gemeinsamen Zugang „shelly",
 *            sobald der eigene Zugang bestätigt ist. Bleibt die Antwort 60 s
 *            aus, fragt der Flow beim nächsten Status des Geräts genau
 *            einmal mit dem alten Absender pm-nodered (Antwort auf
 *            pm-nodered/rpc). Die Antworten wertet „Shelly Gen2+ Geräteinfo"
 *            aus.
 *
 * Für ein Gerät mit eigenem MQTT-Zugang (Liste aus „MQTT-Zugänge merken")
 * gilt nur sein eigenes Präfix: Meldungen unter einem anderen Präfix, das
 * denselben Gerätenamen ergibt, werden verworfen.
 *
 * Einheiten, wie sie der Shelly liefert und die Oberfläche erwartet:
 * u V, i A, p W, s VA, pf ohne Einheit, f Hz, e/e_rueck Wh (Zählerstand).
 */

const ABSENDER = 'pm-nodered';
const INFO_ALLE_MS = 6 * 3600 * 1000;    // Geräteinfo alle 6 h auffrischen
const RUECKFALL_NACH_MS = 60 * 1000;     // ohne Antwort: einmal mit altem Absender
const ANFRAGE_VERFALL_MS = 10 * 60 * 1000;

// Themen, die zwar auf +/status/# oder +/online passen, aber nicht von
// einem Shelly kommen: die eigenen (pm/status/nodered!), die Absender der
// Oberfläche und von Node-RED (pm-nodered, pm-steuerung-…, pm-konto-…),
// Gen1 und der Broker selbst.
function fremd(e) {
    return e === 'pm' || e.startsWith('pm-') || e === 'shellies' || e === '$SYS';
}

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
if (teile[0] === 'shelly' && teile.length >= 3
    && (teile[2] === 'status' || teile[2] === 'online' || teile[2].startsWith('pm-'))) {
    praefix = teile.slice(0, 2).join('/');
    art = teile[2];
    komponente = teile.slice(3).join('/');
} else {
    praefix = teile[0];
    art = teile[1] || '';
    komponente = teile.slice(2).join('/');
}
// Im Baum eines Geräts liegen auch die Absender von Oberfläche und Node-RED
// (<präfix>/pm-…/rpc) – das sind keine Meldungen des Geräts.
if (!praefix || fremd(teile[0]) || art.startsWith('pm-')) {
    return null;
}

const geraet = geraeteName(praefix);
// „__proto__" taugt nicht als Schlüssel im Flow-Kontext (die Zuweisung
// setzte den Prototyp, statt einen Eintrag anzulegen).
if (geraet === '__proto__') {
    return null;
}
// Geräte mit eigenem MQTT-Zugang (globaler Kontext, „MQTT-Zugänge merken"):
// Gerätename → genaues Präfix. Ihren Baum sperrt der Broker für den
// gemeinsamen Zugang, nicht aber einen Doppelgänger mit demselben
// Gerätenamen (kuehlhaus/… statt shelly/kuehlhaus/…). Was für ein solches
// Gerät unter einem anderen Präfix ankommt, stammt nicht von ihm.
const geschuetzt = global.get('mqtt_geschuetzt') || {};
if (Object.prototype.hasOwnProperty.call(geschuetzt, geraet) && geschuetzt[geraet] !== praefix) {
    return null;
}
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
// nur eigene Schlüssel – ein Gerät „constructor" fände sonst Object.prototype
const eintrag = Object.prototype.hasOwnProperty.call(bekannte, geraet) ? bekannte[geraet] : undefined;
const offen = flow.get('gen2_rpc') || {};
let anfrage = null;

// Anfrage stellen und als offen merken. Die Antwort nennt als src nur die
// Geräte-ID; mit eigenem Präfix (shelly/<name>) wäre daraus der Gerätename
// nicht abzuleiten. Deshalb steht hier, welche Anfrage an welches Gerät ging
// und mit welchem Absender – nur eine Antwort auf <src>/rpc mit dst = src
// gilt („Shelly Gen2+ Geräteinfo").
//
// Die id ist zufällig, nicht fortlaufend: Beim Rückfall auf pm-nodered/rpc
// kann jeder mit dem gemeinsamen Zugang dorthin schreiben, die Anfrage an
// ein gesperrtes Gerät aber nicht mitlesen – eine fortlaufende Nummer wäre
// zu erraten.
function rpcAnfrage(src, versuch) {
    let id;
    do {
        id = 1000000 + Math.floor(Math.random() * 1999000000);
    } while (Object.prototype.hasOwnProperty.call(offen, String(id)));
    offen[id] = { geraet, praefix, src, t: jetzt, versuch };
    flow.set('gen2_rpc', offen);
    return {
        topic: praefix + '/rpc',
        payload: JSON.stringify({ id, src, method: 'Shelly.GetDeviceInfo' }),
    };
}

// Wartet diese Anfrage noch auf ihren Rückfall (Versuch 1, zu dem es keinen
// Versuch 2 gibt)?
function wartetAufRueckfall(a) {
    return a.versuch === 1 && !Object.keys(offen).some((id) => offen[id].versuch === 2
        && offen[id].geraet === a.geraet && offen[id].praefix === a.praefix);
}

if (!eintrag || jetzt - eintrag.angefragt > INFO_ALLE_MS || eintrag.praefix !== praefix) {
    bekannte[geraet] = { praefix, angefragt: jetzt };
    flow.set('gen2_geraete', bekannte);

    // Aufräumen. Die neue Runde ersetzt die alten Anfragen dieses Geräts.
    // Sonst verfällt eine offene Anfrage nach 10 min – ein Versuch 1, dem
    // der Rückfall noch fehlt, aber erst nach 6 h: Der Rückfall kommt mit
    // der nächsten Statusmeldung des Geräts, und die lässt bei einem Gerät
    // ohne Last auch einmal länger auf sich warten.
    const verfallen = Object.keys(offen).filter((id) => {
        const a = offen[id];
        const frist = wartetAufRueckfall(a) ? INFO_ALLE_MS : ANFRAGE_VERFALL_MS;
        return a.geraet === geraet || !(jetzt - a.t <= frist);
    });
    for (const id of verfallen) {
        delete offen[id];
    }
    // Versuch 1: Absender im Baum des Geräts
    anfrage = rpcAnfrage(praefix + '/' + ABSENDER, 1);
    ausgabe.push({
        punkte: [{
            messung: 'geraet',
            tags: { geraet, typ: 'shelly' },
            felder: { generation: 2, praefix },
            ts: jetzt,
        }],
    });

    node.status({ fill: 'green', shape: 'dot', text: Object.keys(bekannte).length + ' Geräte' });
} else {
    // Versuch 2: Versuch 1 ist älter als 60 s und unbeantwortet (eine
    // Antwort löscht alle offenen Anfragen des Geräts) – dann einmal mit dem
    // alten Absender pm-nodered, für Geräte, die nicht in ihren eigenen Baum
    // antworten. Ein Shelly mit eigenem Zugang darf pm-nodered/rpc nicht
    // schreiben; seine Antwort auf Versuch 1 gilt weiterhin.
    const erster = Object.keys(offen).map((id) => offen[id])
        .find((a) => a.geraet === geraet && a.praefix === praefix && wartetAufRueckfall(a));
    if (erster && jetzt - erster.t > RUECKFALL_NACH_MS) {
        anfrage = rpcAnfrage(ABSENDER, 2);
    }
}

const messwerte = ausgabe.filter((m) => m !== null);
return [messwerte.length > 0 ? messwerte : null, anfrage];
