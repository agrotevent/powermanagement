/*
 * Prüfung der Dateigröße vor dem Hochladen (public/assets/js/app.js,
 * `data-max-bytes`) ohne Browser: ein nachgebautes Dateifeld, gewählte
 * Dateien von Hand, Ergebnis als JSON auf der Standardausgabe.
 * Aufruf: node tests/hilfen/datei-grenze.cjs public/assets/js/app.js <grenze> <text>
 */
const fs = require('fs');
const code = fs.readFileSync(process.argv[2], 'utf8');
const grenze = process.argv[3] || '104857600';
const text = process.argv[4] || '100 MB';

function element(tag) {
    return {
        tagName: String(tag).toUpperCase(), dataset: {}, hidden: false, textContent: '', className: '', attrs: {}, hoerer: {},
        setAttribute(k, v) { this.attrs[k] = String(v); },
        getAttribute(k) { return this.attrs[k]; },
        hasAttribute(k) { return k in this.attrs; },
        addEventListener(n, f) { (this.hoerer[n] = this.hoerer[n] || []).push(f); },
        insertAdjacentElement(_wo, el) { this.danach = el; },
        appendChild() {}, remove() {},
        querySelectorAll() { return []; }, querySelector() { return null; },
        classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
    };
}

const feld = element('input');
feld.type = 'file';
feld.dataset = { maxBytes: grenze, maxText: text };
feld.files = [];
feld.gueltigkeit = '';
feld.setCustomValidity = function (t) { this.gueltigkeit = t; };

let bereit = null;
const document = {
    addEventListener(n, f) { if (n === 'DOMContentLoaded') { bereit = f; } },
    querySelectorAll(sel) { return sel === 'input[type=file][data-max-bytes]' ? [feld] : []; },
    querySelector() { return null; },
    getElementById() { return null; },
    createElement: element,
    documentElement: element('html'),
    body: element('body'),
};
const speicher = { getItem() { return null; }, setItem() {}, removeItem() {} };
const window = { addEventListener() {}, location: { pathname: '/standorte' }, history: {}, localStorage: speicher };

new Function('window', 'document', 'localStorage', code)(window, document, speicher);
bereit();

const waehlen = (groesse) => {
    feld.files = [{ name: 'Hallenplan.pdf', size: groesse }];
    (feld.hoerer.change || []).forEach((f) => f());
    return { gueltigkeit: feld.gueltigkeit, meldung: feld.danach ? feld.danach.textContent : null, versteckt: feld.danach ? feld.danach.hidden : null };
};
const out = {
    anfang: { gueltigkeit: feld.gueltigkeit, versteckt: feld.danach ? feld.danach.hidden : null, rolle: feld.danach ? feld.danach.attrs.role : null },
    ueber: waehlen(Number(grenze) + 1),
    genau: waehlen(Number(grenze)),
    viel: waehlen(250 * 1048576),
};
process.stdout.write(JSON.stringify(out));
