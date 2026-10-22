// tests/hilfen/tank-tage.cjs <tank.js> <einmal|immer>
//
// Der Verbrauch je Tag aus tank.js ohne Browser: ein Block [data-tank-tage],
// fetch als Attrappe. „einmal“: erst 503 mit Retry-After (Dienste starten
// während eines Updates neu, Fehlerseite ohne JSON), dann die Antwort.
// „immer“: nur 502 – nach zehn Versuchen bleibt es bei der Fehlermeldung.
// Ausgabe: JSON mit Abrufen, Pausen und den Meldungen in ihrer Reihenfolge.
'use strict';

const fs = require('fs');
const vm = require('vm');

const quelle = fs.readFileSync(process.argv[2], 'utf8');
const art = process.argv[3] || 'einmal';

function element(name) {
    const e = {
        name, hidden: false, className: '', dataset: {}, kinder: [], verlauf: [], _text: '',
        appendChild(k) { this.kinder.push(k); return k; },
        replaceChildren(...k) { this.kinder = k; },
        querySelectorAll() { return []; },
        querySelector() { return null; },
        get children() { return this.kinder; },
    };
    Object.defineProperty(e, 'textContent', {
        get() { return this._text; },
        set(t) { this._text = String(t); this.verlauf.push(this._text); },
    });
    return e;
}

const teile = {};
for (const s of ['[data-tage-status]', '[data-tage-tabelle]', '[data-tage-zeilen]', '[data-tage-summe]',
    '[data-tage-erkannt-block]', '[data-tage-erkannt]', '[data-tage-schwelle]']) {
    teile[s] = element(s);
}
const block = element('block');
block.dataset.tankTage = '/anlagen/1/tank/tage?zeitraum=7d';
block.querySelector = (s) => teile[s] || null;

const daten = {
    ok: true, hinweis: '', tage: [{ text: 'Mo 20.10.', verbrauch_text: '120 l', betankt_text: '', l_h_text: '5,0 l/h', hinweis: '' }],
    betankungen: [], summe: { text: '120 l in 1 Tag' },
};
const antworten = art === 'immer'
    ? []
    : [{ status: 503, kopf: { 'Retry-After': '30' }, text: '<html>Wartung</html>' }, { status: 200, kopf: {}, text: JSON.stringify(daten) }];
const abrufe = [];
const pausen = [];
let neuGeladen = 0;

const fetch = (url) => {
    abrufe.push(url);
    const a = antworten.shift() || { status: 502, kopf: {}, text: '<html>Bad Gateway</html>' };
    return Promise.resolve({
        status: a.status,
        ok: a.status >= 200 && a.status < 300,
        headers: { get: (n) => (Object.prototype.hasOwnProperty.call(a.kopf, n) ? a.kopf[n] : null) },
        json: () => Promise.resolve().then(() => JSON.parse(a.text)),
    });
};
const document = {
    querySelectorAll: (s) => (s === '[data-tank-tage]' ? [block] : []),
    createElement: (n) => element(n),
    createTextNode: (t) => ({ textContent: t }),
};
const window = {
    // Ohne zu warten – nur festhalten, wie lange es gewartet hätte.
    setTimeout: (f, ms) => { pausen.push(ms); setImmediate(f); return pausen.length; },
    location: { reload() { neuGeladen++; } },
};

try {
    vm.runInNewContext(quelle, { document, window, fetch, console, setImmediate });
} catch (fehler) {
    process.stdout.write(JSON.stringify({ fehler: String(fehler && fehler.stack || fehler) }));
    process.exit(0);
}
setTimeout(() => {
    const status = teile['[data-tage-status]'];
    process.stdout.write(JSON.stringify({
        abrufe: abrufe.length,
        pausen,
        meldungen: status.verlauf,
        status: status.textContent,
        statusVersteckt: status.hidden,
        zeilen: teile['[data-tage-zeilen]'].kinder.length,
        summe: teile['[data-tage-summe]'].textContent,
        neuGeladen,
    }));
}, 300);
