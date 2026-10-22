/*
 * Die Abfrage „Plan neu rechnen“ (public/assets/js/module/plan-neu.js) ohne
 * Browser: fetch, Uhr und Dokument von Hand. Ergebnis als JSON auf der
 * Standardausgabe.
 *
 * Geprüft wird, was die Regel verlangt (Abfragen starten erst, wenn die
 * vorige fertig ist): nie zwei Fragen zugleich, auch wenn start() und
 * einmal() dazwischenkommen; die nächste erst PAUSE nach der Antwort; Schluss
 * bei „fertig“ und „fehler“; 423 (Kundenmodus) → neu laden; 401 → Schluss;
 * im verborgenen Reiter keine Frage, beim Zurückkommen gleich eine; nach
 * einem Netzfehler die nächste wieder nach der Pause; einmal() fragt einmal
 * und läuft nur weiter, wenn gerechnet wird. Dazu: Das Fenster zeigt die
 * Meldung als Text, nicht als HTML.
 *
 * Aufruf: node tests/hilfen/plan-neu.cjs public/assets/js/module/plan-neu.js
 */
const fs = require('fs');

const [datei] = process.argv.slice(2);

// ------------------------------------------------------------ Uhr, fetch, Dokument
function uhr() {
    let jetzt = 0;
    let nr = 0;
    const wecker = new Map();
    return {
        setTimeout(f, ms) { nr++; wecker.set(nr, { f, um: jetzt + ms, ms }); return nr; },
        clearTimeout(id) { wecker.delete(id); },
        geplant() { return [...wecker.values()].map((w) => w.ms); },
        /** Die Uhr um ms vorstellen und fällige Wecker auslösen. */
        async vor(ms) {
            jetzt += ms;
            for (const [id, w] of [...wecker.entries()].sort((a, b) => a[1].um - b[1].um)) {
                if (w.um <= jetzt && wecker.has(id)) { wecker.delete(id); w.f(); }
            }
            await ruhe();
        },
    };
}

const ruhe = () => new Promise((fertig) => setImmediate(fertig));

function netz() {
    const offen = [];
    const n = { anfragen: 0, gleichzeitig: 0, hoechstens: 0, adressen: [], optionen: [] };
    n.holen = (adresse, optionen) => {
        n.anfragen++;
        n.gleichzeitig++;
        n.hoechstens = Math.max(n.hoechstens, n.gleichzeitig);
        n.adressen.push(adresse);
        n.optionen.push(optionen);
        return new Promise((fertig, fehler) => offen.push({ fertig, fehler }));
    };
    /** Die älteste offene Frage beantworten (status, json) – oder mit einem Netzfehler. */
    n.antworten = async (status, daten) => {
        const a = offen.shift();
        if (!a) { throw new Error('keine Frage offen'); }
        n.gleichzeitig--;
        if (status === 0) { a.fehler(new TypeError('Failed to fetch')); } else { a.fertig({ status, json: async () => daten }); }
        await ruhe();
        await ruhe();
    };
    n.offen = () => offen.length;
    return n;
}

function dokument() {
    const hoerer = {};
    return {
        hidden: false,
        addEventListener(name, f) { (hoerer[name] = hoerer[name] || []).push(f); },
        feuern(name) { (hoerer[name] || []).forEach((f) => f()); },
    };
}

async function main() {
    const quelle = fs.readFileSync(datei, 'utf8');
    const modul = await import('data:text/javascript;base64,' + Buffer.from(quelle).toString('base64'));
    const ergebnis = { pause: modul.PAUSE, offen: modul.OFFEN };

    const aufbau = () => {
        const z = uhr();
        const n = netz();
        const d = dokument();
        const s = { antworten: [], neuGeladen: 0, abgemeldet: 0 };
        const abfrage = modul.standAbfrage({
            adresse: '/grundriss/7/kacheln/stand', holen: n.holen, zeit: z, dokument: d,
            antwort: (daten) => s.antworten.push(daten.zustand),
            neuLaden: () => { s.neuGeladen++; },
            abgemeldet: () => { s.abgemeldet++; },
        });
        return { z, n, d, s, abfrage };
    };
    const ok = (zustand) => ({ ok: true, zustand, meldung: 'x', stand: '0123456789ab' });

    // Laufend → nächste erst nach der Antwort plus Pause; dazwischen keine zweite Frage.
    {
        const { z, n, s, abfrage } = aufbau();
        abfrage.start();
        abfrage.start();
        abfrage.einmal();
        const vorAntwort = n.anfragen;
        await z.vor(10_000);
        const nochImmer = n.anfragen;
        await n.antworten(200, ok('laeuft'));
        const direktDanach = n.anfragen;
        const geplant = z.geplant();
        await z.vor(modul.PAUSE - 1);
        const knappDavor = n.anfragen;
        await z.vor(1);
        const nachPause = n.anfragen;
        abfrage.einmal();
        await n.antworten(200, ok('wartet'));
        await z.vor(modul.PAUSE);
        await n.antworten(200, ok('fertig'));
        await z.vor(60_000);
        ergebnis.laufend = {
            vorAntwort, nochImmer, direktDanach, geplant, knappDavor, nachPause, ende: n.anfragen, hoechstens: n.hoechstens,
            antworten: s.antworten, laeuftNoch: abfrage.laeuft, adresse: n.adressen[0], accept: n.optionen[0].headers.Accept,
            cache: n.optionen[0].cache, signal: !!n.optionen[0].signal,
        };
    }
    // fehler → Schluss
    {
        const { z, n, s, abfrage } = aufbau();
        abfrage.start();
        await n.antworten(200, ok('fehler'));
        await z.vor(60_000);
        ergebnis.fehler = { anfragen: n.anfragen, antworten: s.antworten, laeuftNoch: abfrage.laeuft };
    }
    // 423 → neu laden, 401 → abgemeldet; danach keine Frage mehr
    for (const [name, status, daten] of [['kundenmodus', 423, { ok: false, kundenmodus: true }], ['abgemeldet', 401, { ok: false, anmelden: true }],
        ['verboten', 403, { ok: false }]]) {
        const { z, n, s, abfrage } = aufbau();
        abfrage.start();
        await n.antworten(status, daten);
        await z.vor(60_000);
        ergebnis[name] = { anfragen: n.anfragen, neuGeladen: s.neuGeladen, abgemeldet: s.abgemeldet, laeuftNoch: abfrage.laeuft };
    }
    // Verborgener Reiter: keine Frage; zurück im Vordergrund gleich eine.
    {
        const { z, n, d, abfrage } = aufbau();
        abfrage.start();
        await n.antworten(200, ok('laeuft'));
        d.hidden = true;
        await z.vor(modul.PAUSE * 4);
        const verborgen = n.anfragen;
        d.hidden = false;
        d.feuern('visibilitychange');
        await ruhe();
        ergebnis.verborgen = { verborgen, zurueck: n.anfragen, hoechstens: n.hoechstens };
    }
    // Netzfehler: nächste Frage nach der Pause (solange es weitergehen soll).
    {
        const { z, n, abfrage } = aufbau();
        abfrage.start();
        await n.antworten(0, null);
        const direkt = n.anfragen;
        await z.vor(modul.PAUSE);
        ergebnis.netzfehler = { direkt, danach: n.anfragen };
    }
    // einmal(): fragt einmal; läuft nichts, kommt keine zweite – läuft etwas, geht es weiter.
    {
        const { z, n, abfrage } = aufbau();
        abfrage.einmal();
        await n.antworten(200, ok('fertig'));
        await z.vor(60_000);
        const ohne = n.anfragen;
        abfrage.einmal();
        await n.antworten(200, ok('laeuft'));
        await z.vor(modul.PAUSE);
        ergebnis.einmal = { ohne, mitLauf: n.anfragen };
    }

    // Das Fenster: Text aus der Antwort bleibt Text.
    {
        const knoten = (tag) => {
            const k = { tagName: tag.toUpperCase(), kinder: [], className: '', textContent: '', attribute: {}, hoerer: {}, disabled: false,
                appendChild(c) { this.kinder.push(c); return c; }, replaceChildren() { this.kinder = []; },
                setAttribute(n, w) { this.attribute[n] = w; }, addEventListener(n, f) { this.hoerer[n] = f; },
                querySelector() { return null; }, contains() { return true; } };
            Object.defineProperty(k, 'innerHTML', { set() { throw new Error('innerHTML gesetzt'); }, get() { return ''; } });
            return k;
        };
        globalThis.document = { createElement: knoten, createTextNode: (t) => ({ textContent: t }) };
        const ziel = knoten('div');
        const boese = '<img src=x onerror=alert(1)>';
        const fenster = modul.planNeuPanel(ziel, { art: 'kacheln', breite: 8386, hoehe: 5962, fassung: 1, fassung_aktuell: 2, qualitaet: 'standard',
            qualitaeten: [{ wert: 'standard', name: 'Standard' }, { wert: 'fein', name: 'Fein', hinweis: boese }], empfohlen: true, gebaut: boese, dauer: '' },
        { zustand: 'laeuft', meldung: boese }, async () => {});
        const texte = [];
        const sammeln = (k) => { if (k.textContent) { texte.push(k.textContent); } (k.kinder || []).forEach(sammeln); };
        sammeln(ziel);
        const knopf = [];
        const suchen = (k) => { if (k.tagName === 'BUTTON') { knopf.push(k); } (k.kinder || []).forEach(suchen); };
        suchen(ziel);
        const beimLauf = knopf[0] ? [knopf[0].disabled, knopf[0].textContent] : null;
        fenster.zustandSetzen({ zustand: 'fehler', meldung: 'gescheitert' });
        ergebnis.fenster = { boeseAlsText: texte.filter((t) => t.includes(boese)).length, beimLauf,
            nachFehler: knopf[0] ? [knopf[0].disabled, knopf[0].textContent] : null, ueberschrift: texte[0] };
    }

    process.stdout.write(JSON.stringify(ergebnis));
}

main().catch((e) => {
    process.stdout.write(JSON.stringify({ abbruch: String(e && e.stack ? e.stack : e).replace(/data:text\/javascript;base64,[A-Za-z0-9+/=]+/g, 'plan-neu.js') }));
});
