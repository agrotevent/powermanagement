/*
 * Unterschriftsfläche (public/assets/js/unterschrift.js) ohne Browser:
 * Zeiger-Ereignisse von Hand, Ergebnis als JSON auf der Standardausgabe.
 * Aufruf: node tests/hilfen/unterschrift-flaeche.cjs public/assets/js/unterschrift.js
 */
const fs = require('fs');
const code = fs.readFileSync(process.argv[2], 'utf8');
const hoerer = {};
const ctx = new Proxy({}, { get: (t, k) => (k in t ? t[k] : () => {}), set: (t, k, v) => { t[k] = v; return true; } });
const canvas = {
    tagName: 'CANVAS', clientWidth: 900, clientHeight: 300, width: 0, height: 0,
    classList: { toggle() {} },
    getContext: () => ctx,
    addEventListener: (n, f) => { hoerer[n] = f; },
    getBoundingClientRect: () => ({ left: 0, top: 0, width: 900, height: 300 }),
    setPointerCapture() {}, releasePointerCapture() {},
};
global.window = { devicePixelRatio: 1, requestAnimationFrame: (f) => f(), getComputedStyle: () => ({ color: '#000' }), addEventListener() {} };
new Function('window', code)(global.window);
const ev = (id, art, x, y) => ({ pointerId: id, pointerType: art, button: 0, clientX: x, clientY: y, preventDefault() {} });
const out = {};

// 1. Handballen liegt zuerst auf, dann kommt der Stift.
let pad = window.PMUnterschrift.an(canvas, {});
hoerer.pointerdown(ev(1, 'touch', 450, 280));
hoerer.pointermove(ev(1, 'touch', 460, 285));
hoerer.pointerdown(ev(2, 'pen', 50, 50));
for (let i = 1; i <= 40; i++) { hoerer.pointermove(ev(2, 'pen', 50 + i * 20, 50 + (i % 2) * 200)); }
hoerer.pointermove(ev(1, 'touch', 470, 290));
hoerer.pointerup(ev(2, 'pen', 850, 250));
hoerer.pointerup(ev(1, 'touch', 470, 290));
let d = JSON.parse(pad.daten());
out.handballen = { striche: d.s.length, punkte: d.s.map((s) => s.length), erster: d.s[0] ? d.s[0][0] : null, gueltig: pad.gueltig() };

// 2. Viele Punkte mit vierstelligen Koordinaten: nie mehr als der Server annimmt.
pad = window.PMUnterschrift.an(canvas, {});
for (let s = 0; s < 60; s++) {
    hoerer.pointerdown(ev(10 + s, 'mouse', 100, 40));
    for (let i = 0; i < 400; i++) { hoerer.pointermove(ev(10 + s, 'mouse', 100 + (i % 80) * 10, i % 2 === 0 ? 40 : 290)); }
    hoerer.pointerup(ev(10 + s, 'mouse', 100, 40));
}
d = pad.daten();
out.gross = { bytes: d.length, punkte: JSON.parse(d).s.reduce((a, s) => a + s.length, 0), gueltig: pad.gueltig() };
process.stdout.write(JSON.stringify(out));
