/*
 * Shelly Gen2+ Geräteinfo
 *
 * Antwort auf Shelly.GetDeviceInfo (gestellt von „Shelly Gen2+ übersetzen"),
 * eingegangen auf pm-nodered/rpc:
 *   { "id": 7, "src": "shellypro3em-a0b1c2d3e4f5", "dst": "pm-nodered",
 *     "result": { "id": "shellypro3em-a0b1c2d3e4f5", "mac": "A0B1C2D3E4F5",
 *                 "model": "SPEM-003CEBEU", "gen": 2, "ver": "1.4.4",
 *                 "app": "Pro3EM", "name": "Hauptverteilung", … } }
 *
 * Daraus entsteht ein Punkt der Messung „geraet" – aus ihr baut die
 * Oberfläche die Liste der Geräte:
 *   geraet,geraet=<g>,typ=shelly modell="…",name="…",firmware="…",generation=2,mac="…"
 */

function geraeteName(text) {
    const teile = String(text).split('/');
    return teile[teile.length - 1].toLowerCase().replace(/[^a-z0-9_.-]/g, '-');
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
if (antwort === null || typeof antwort !== 'object') {
    return null;
}

// Zu welcher Anfrage gehört die Antwort? Die Zuordnung steht im
// Flow-Kontext; nur nach einem Neustart von Node-RED zwischen Anfrage und
// Antwort fehlt sie – dann dient die Geräte-ID (src) als Name.
const offen = flow.get('gen2_rpc') || {};
const anfrage = offen[antwort.id];
if (anfrage) {
    delete offen[antwort.id];
    flow.set('gen2_rpc', offen);
}

if (antwort.error || antwort.result === null || typeof antwort.result !== 'object') {
    const grund = antwort.error ? (antwort.error.message || JSON.stringify(antwort.error)) : 'keine Nutzdaten';
    node.warn('Geräteinfo von ' + String(antwort.src || '?') + ' nicht erhalten: ' + grund);
    return null;
}

const r = antwort.result;
const quelle = antwort.src || r.id;
if (!anfrage && !quelle) {
    return null;
}
const geraet = anfrage ? anfrage.geraet : geraeteName(quelle);

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
            praefix: anfrage ? anfrage.praefix : undefined,
        },
        ts: Date.now(),
    }],
};
