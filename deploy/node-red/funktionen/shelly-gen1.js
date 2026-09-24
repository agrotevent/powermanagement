/*
 * Shelly Gen1 übersetzen
 *
 * Gen1-Geräte (Shelly 1PM, 2.5, Plug S, EM, 3EM …) veröffentlichen jeden
 * Wert auf einem eigenen Thema unter shellies/<id>/…:
 *   relay/N                 "on" / "off" / "overpower"
 *   relay/N/power           W
 *   relay/N/energy          Wattminuten (!) seit dem letzten Neustart
 *   emeter/N/power|voltage|current|pf|reactive_power|total|total_returned
 *                           total/total_returned in Wh
 *   input/N                 "0" / "1"
 *   online                  "true" / "false"
 *   temperature             °C
 *   announce                JSON mit id, model, mac, ip, fw_ver
 * und shellies/announce als Antwort auf „announce" an shellies/command.
 *
 * Ausgang: normalisierte Messwerte und Punkte für die Geräteliste – weiter
 * an „Messwert → Line Protocol".
 *
 * Die Summe über die Phasen eines EM/3EM (phase=sum) rechnet Gen1 nicht
 * selbst. Sie entsteht hier, sobald von allen bisher gesehenen Phasen ein
 * neuer Wert vorliegt, der jünger als 30 s ist. Ältere Werte zu addieren
 * ergäbe eine Summe, die es so nie gab.
 */

const INFO_ALLE_MS = 6 * 3600 * 1000;
const FRISCH_MS = 30 * 1000;
const EMETER_FELDER = {
    power: 'p',
    voltage: 'u',
    current: 'i',
    pf: 'pf',
    reactive_power: 'q',
    total: 'e',
    total_returned: 'e_rueck',
};
const SUMMEN_FELDER = ['p', 'e', 'e_rueck'];

function geraeteName(id) {
    return String(id).toLowerCase().replace(/[^a-z0-9_.-]/g, '-');
}

function alsZahl(text) {
    const t = String(text).trim();
    if (t === '') {
        return undefined;
    }
    const n = Number(t);
    return Number.isFinite(n) ? n : undefined;
}

const teile = String(msg.topic || '').split('/');
if (teile[0] !== 'shellies' || teile.length < 2) {
    return null;
}
let text = msg.payload;
if (Buffer.isBuffer(text)) {
    text = text.toString('utf8');
}
text = typeof text === 'string' ? text : JSON.stringify(text);
const jetzt = Date.now();

function geraetePunkt(id, felder) {
    return {
        punkte: [{
            messung: 'geraet',
            tags: { geraet: geraeteName(id), typ: 'shelly' },
            felder: Object.assign({ generation: 1, praefix: 'shellies/' + id }, felder),
            ts: jetzt,
        }],
    };
}

// ------------------------------------------------------------ announce
if (teile[1] === 'announce' || (teile.length === 3 && teile[2] === 'announce')) {
    let a;
    try {
        a = JSON.parse(text);
    } catch (e) {
        return null;
    }
    const id = teile[1] === 'announce' ? a && a.id : teile[1];
    if (!a || typeof a !== 'object' || !id) {
        return null;
    }
    const txt = (wert) => (typeof wert === 'string' && wert !== '' ? wert : undefined);
    return geraetePunkt(id, {
        modell: txt(a.model),
        firmware: txt(a.fw_ver),
        mac: txt(a.mac),
        ip: txt(a.ip),
    });
}

// shellies/command und Befehlsthemen (…/command) schickt Node-RED bzw. die
// Oberfläche selbst – das sind keine Messwerte.
if (teile[1] === 'command' || teile[teile.length - 1] === 'command') {
    return null;
}

const id = teile[1];
const geraet = geraeteName(id);
const rest = teile.slice(2);
const ausgabe = [];

function messwert(kanal, phasen, zustand) {
    const nutzlast = { ts: jetzt, typ: 'shelly' };
    if (phasen) {
        nutzlast.phasen = phasen;
    }
    if (zustand) {
        nutzlast.zustand = zustand;
    }
    return { topic: 'pm/messwert/' + geraet + '/' + kanal, payload: nutzlast };
}

// Das Gerät in die Liste eintragen, sobald es sich überhaupt meldet –
// announce kommt nur beim Verbinden und auf Nachfrage.
const bekannte = flow.get('gen1_geraete') || {};
if (!bekannte[geraet] || jetzt - bekannte[geraet] > INFO_ALLE_MS) {
    bekannte[geraet] = jetzt;
    flow.set('gen1_geraete', bekannte);
    ausgabe.push(geraetePunkt(id, {}));
    node.status({ fill: 'green', shape: 'dot', text: Object.keys(bekannte).length + ' Geräte' });
}

if (rest.length === 1 && rest[0] === 'online') {
    const t = text.trim().toLowerCase();
    if (t === 'true' || t === 'false') {
        ausgabe.push(messwert('geraet', null, { online: t === 'true' ? 1 : 0 }));
    }
} else if (rest.length === 1 && rest[0] === 'temperature') {
    const wert = alsZahl(text);
    if (wert !== undefined) {
        ausgabe.push(messwert('geraet', null, { temperatur: wert }));
    }
} else if (rest[0] === 'relay' && /^\d+$/.test(rest[1] || '')) {
    const kanal = 'relay:' + rest[1];
    if (rest.length === 2) {
        const t = text.trim().toLowerCase();
        if (t === 'on' || t === 'off' || t === 'overpower') {
            // overpower: das Gerät hat wegen Überlast abgeschaltet
            ausgabe.push(messwert(kanal, null, { schalter: t === 'on' ? 1 : 0 }));
        }
    } else if (rest.length === 3 && rest[2] === 'power') {
        const wert = alsZahl(text);
        if (wert !== undefined) {
            ausgabe.push(messwert(kanal, { sum: { p: wert } }));
        }
    } else if (rest.length === 3 && rest[2] === 'energy') {
        // Gen1 zählt hier in Wattminuten – durch 60 sind es Wh wie überall.
        const wert = alsZahl(text);
        if (wert !== undefined) {
            ausgabe.push(messwert(kanal, { sum: { e: wert / 60 } }));
        }
    }
} else if (rest[0] === 'input' && rest.length === 2 && /^\d+$/.test(rest[1])) {
    const wert = alsZahl(text);
    if (wert !== undefined) {
        ausgabe.push(messwert('input:' + rest[1], null, { eingang: wert }));
    }
} else if (rest[0] === 'emeter' && rest.length === 3 && /^\d+$/.test(rest[1]) && EMETER_FELDER[rest[2]]) {
    const feld = EMETER_FELDER[rest[2]];
    const wert = alsZahl(text);
    const nr = Number(rest[1]);
    if (wert !== undefined && nr <= 2) {
        const phase = 'L' + (nr + 1);
        ausgabe.push(messwert('emeter', { [phase]: { [feld]: wert } }));

        if (SUMMEN_FELDER.includes(feld)) {
            // Je Gerät: letzter Wert je Phase und Feld mit laufender Nummer.
            // Summiert wird, wenn jede bekannte Phase seit der letzten Summe
            // dieses Feldes einen neuen und noch frischen Wert hat.
            //
            // Wie viele Phasen ein Gerät hat (EM: 2, 3EM: 3), sagt es nicht.
            // Es veröffentlicht aber in jeder Runde alle – erst wenn eine
            // Phase zum zweiten Mal kommt, ist eine Runde vorbei und die
            // Liste vollständig. Vorher entstünde nach dem Start eine
            // „Summe" aus nur einer Phase.
            //
            // Die Runde läuft emeter/0, /1, /2 der Reihe nach. Summiert wird
            // deshalb beim Wert der höchsten Phase: dann stammen alle
            // Summanden aus derselben Runde.
            const alle = flow.get('gen1_emeter') || {};
            const g = alle[geraet] || { nr: 0, phasen: {}, summe: {}, runde: {} };
            alle[geraet] = g;
            g.nr++;
            g.phasen[phase] = g.phasen[phase] || {};
            if (g.phasen[phase][feld]) {
                g.runde[feld] = true;
            }
            g.phasen[phase][feld] = { w: wert, t: jetzt, nr: g.nr };

            const letzte = g.summe[feld] || 0;
            const bekanntePhasen = Object.keys(g.phasen).sort();
            const werte = bekanntePhasen.map((ph) => g.phasen[ph][feld]);
            const vollstaendig = g.runde[feld]
                && phase === bekanntePhasen[bekanntePhasen.length - 1]
                && werte.every((x) => x && x.nr > letzte && jetzt - x.t <= FRISCH_MS);
            if (vollstaendig) {
                g.summe[feld] = g.nr;
                const summe = werte.reduce((a, x) => a + x.w, 0);
                ausgabe.push(messwert('emeter', { sum: { [feld]: summe } }));
            }
            flow.set('gen1_emeter', alle);
        }
    }
}

return ausgabe.length > 0 ? [ausgabe] : null;
