/*
 * Shelly Gen2+ Geräteinfo
 *
 * Antwort auf Shelly.GetDeviceInfo (gestellt von „Shelly Gen2+ übersetzen").
 * Der Shelly antwortet an <src>/rpc. Versuch 1 hat den Absender
 * <präfix>/pm-nodered, die Antwort kommt also im Baum des Geräts an:
 *   Thema shelly/halle-3/pm-nodered/rpc
 *   { "id": 1234567, "src": "shellypro3em-a0b1c2d3e4f5", "dst": "shelly/halle-3/pm-nodered",
 *     "result": { "id": "shellypro3em-a0b1c2d3e4f5", "mac": "A0B1C2D3E4F5",
 *                 "model": "SPEM-003CEBEU", "gen": 2, "ver": "1.4.4",
 *                 "app": "Pro3EM", "name": "Hauptverteilung", … } }
 * Versuch 2 (nach 60 s ohne Antwort) hat den alten Absender pm-nodered,
 * die Antwort kommt auf pm-nodered/rpc mit "dst": "pm-nodered".
 *
 * Es gilt nur eine Antwort, die zu einer offenen Anfrage passt: gleiche id,
 * Thema = <src der Anfrage>/rpc, dst = src der Anfrage und keine „method"
 * (dann wäre es eine Anfrage oder Meldung, keine Antwort). Passt etwas
 * nicht, bleibt die Anfrage offen – eine falsche Nachricht verdrängt die
 * echte Antwort nicht.
 *
 * Fehlt die offene Anfrage (Node-RED wurde neu gestartet, während die
 * Antwort unterwegs war), zählt nur eine Antwort im Baum eines Geräts:
 * Gerät und Präfix ergeben sich dann aus dem Thema, so wie bei den
 * Statusmeldungen. Auf pm-nodered/rpc ist ohne Anfrage nicht zu erkennen,
 * von welchem Gerät die Antwort stammt – sie wird verworfen.
 *
 * Daraus entsteht ein Punkt der Messung „geraet" – aus ihr baut die
 * Oberfläche die Liste der Geräte:
 *   geraet,geraet=<g>,typ=shelly modell="…",name="…",firmware="…",generation=2,mac="…",praefix="…"
 */

const ABSENDER = 'pm-nodered';

// Wie in „Shelly Gen2+ übersetzen": kein Präfix eines Shelly.
function fremd(e) {
    return e === 'pm' || e.startsWith('pm-') || e === 'shellies' || e === '$SYS';
}

function geraeteName(text) {
    const teile = String(text).split('/');
    return teile[teile.length - 1].toLowerCase().replace(/[^a-z0-9_.-]/g, '-');
}

function hat(objekt, schluessel) {
    return Object.prototype.hasOwnProperty.call(objekt, schluessel);
}

// Thema: pm-nodered/rpc (alter Absender) oder <präfix>/pm-nodered/rpc
const thema = String(msg.topic || '');
let themaPraefix = '';
if (thema !== ABSENDER + '/rpc') {
    const treffer = /^(.+)\/pm-nodered\/rpc$/.exec(thema);
    if (!treffer) {
        return null;
    }
    themaPraefix = treffer[1];
}

let antwort = msg.payload;
if (Buffer.isBuffer(antwort)) {
    antwort = antwort.toString('utf8');
}
if (typeof antwort === 'string') {
    try {
        antwort = JSON.parse(antwort);
    } catch (e) {
        return null;
    }
}
if (antwort === null || typeof antwort !== 'object' || Array.isArray(antwort)) {
    return null;
}
if (hat(antwort, 'method') || !Number.isInteger(antwort.id) || (!hat(antwort, 'result') && !hat(antwort, 'error'))) {
    return null;
}

// Zu welcher Anfrage gehört die Antwort? Die Zuordnung steht im Flow-Kontext.
const offen = flow.get('gen2_rpc') || {};
const schluessel = String(antwort.id);
const anfrage = hat(offen, schluessel) ? offen[schluessel] : null;
let geraet;
let praefix;
if (anfrage) {
    if (thema !== anfrage.src + '/rpc' || antwort.dst !== anfrage.src) {
        // gehört nicht zu dieser Anfrage – sie bleibt offen
        return null;
    }
    // Beantwortet: diese Anfrage und die übrigen Versuche für dasselbe Gerät
    // sind erledigt (sonst stellte „übersetzen" noch einen Versuch 2).
    for (const id of Object.keys(offen)) {
        if (id === schluessel || (offen[id].geraet === anfrage.geraet && offen[id].praefix === anfrage.praefix)) {
            delete offen[id];
        }
    }
    flow.set('gen2_rpc', offen);
    geraet = anfrage.geraet;
    praefix = anfrage.praefix;
} else {
    // Ohne Anfrage nur im Baum eines Geräts: <id> oder shelly/<name>, wie in
    // „Shelly Gen2+ übersetzen", und dst muss der Absender in diesem Baum sein.
    const teile = themaPraefix.split('/');
    const passt = themaPraefix !== ''
        && antwort.dst === themaPraefix + '/' + ABSENDER
        && teile.every((t) => t !== '')
        && !fremd(teile[0])
        && (teile.length === 1 || (teile.length === 2 && teile[0] === 'shelly'));
    if (!passt) {
        return null;
    }
    geraet = geraeteName(themaPraefix);
    praefix = themaPraefix;
}

if (antwort.error || antwort.result === null || typeof antwort.result !== 'object' || Array.isArray(antwort.result)) {
    const grund = antwort.error ? (antwort.error.message || JSON.stringify(antwort.error)) : 'keine Nutzdaten';
    node.warn('Geräteinfo von ' + geraet + ' nicht erhalten: ' + String(grund).slice(0, 200));
    return null;
}

const r = antwort.result;
const text = (wert) => (typeof wert === 'string' && wert !== '' ? wert : undefined);

node.status({ fill: 'green', shape: 'dot', text: geraet + ': ' + (text(r.app) || text(r.model) || '?') });

return {
    punkte: [{
        messung: 'geraet',
        tags: { geraet, typ: 'shelly' },
        felder: {
            modell: text(r.model),
            // „app" ist der lesbare Produktname (Pro3EM, PlusPlugS …) –
            // das Modell ist nur die Bestellnummer.
            produkt: text(r.app),
            name: text(r.name),
            firmware: text(r.ver) || text(r.fw_id),
            // Gen3/Gen4 melden sich mit gen 3/4 – das geht so weiter.
            generation: typeof r.gen === 'number' ? r.gen : 2,
            mac: text(r.mac),
            praefix,
        },
        ts: Date.now(),
    }],
};
