/*
 * Formulare und Links mit `data-laeuft` (public/assets/js/app.js) und die
 * Live-Abfrage im Kundenmodus (public/assets/js/live.js) ohne Browser:
 * DOM, fetch, Navigation API und Ereignisse von Hand, Ergebnis als JSON auf
 * der Standardausgabe.
 * Aufruf: node tests/hilfen/laeuft.cjs public/assets/js/app.js public/assets/js/live.js
 */
const fs = require('fs');
const vm = require('vm');
const [appQuelle, liveQuelle] = process.argv.slice(2).map((d) => fs.readFileSync(d, 'utf8'));

function datensatz(attribute) {
    const d = {};
    for (const [n, v] of Object.entries(attribute)) {
        if (n.startsWith('data-')) { d[n.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = v; }
    }
    return d;
}

function element(tag, attribute = {}) {
    const hoerer = {};
    const el = {
        tagName: tag.toUpperCase(), attribute: { ...attribute }, dataset: datensatz(attribute), kinder: [], children: [],
        hidden: false, disabled: false, textContent: '', className: '', style: {},
        classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
        addEventListener(name, f) { (hoerer[name] = hoerer[name] || []).push(f); },
        feuern(name, ereignis) { (hoerer[name] || []).forEach((f) => f(ereignis)); return ereignis; },
        hasAttribute: (n) => n in el.attribute,
        getAttribute: (n) => (n in el.attribute ? el.attribute[n] : null),
        setAttribute: (n, v) => { el.attribute[n] = String(v); },
        removeAttribute: (n) => { delete el.attribute[n]; },
        appendChild: (k) => { el.kinder.push(k); return k; },
        insertAdjacentElement: (_, k) => { el.danach = k; return k; },
        querySelector: () => null,
        querySelectorAll: () => [],
        remove() { el.entfernt = true; },
    };
    return el;
}

function formular(attribute) {
    const f = element('form', { method: 'post', ...attribute });
    f.action = attribute.action;
    f.method = 'post';
    const knopf = element('button', { type: 'submit' });
    knopf.form = f;
    knopf.name = '';
    knopf.value = '';
    knopf.textContent = 'Los';
    knopf.closest = () => knopf;
    f.knopf = knopf;
    f.querySelectorAll = (sel) => (sel.startsWith('button') ? [knopf] : []);
    return f;
}

function ereignis(weiteres = {}) {
    return { defaultPrevented: false, button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false,
        preventDefault() { this.defaultPrevented = true; }, ...weiteres };
}

const warten = () => new Promise((r) => setTimeout(r, 0));

/**
 * Eine frische Seite: app.js mit genau diesen Elementen in einem eigenen
 * Kontext (eigenes `window`, wie ein eigener Reiter), DOMContentLoaded
 * gefeuert. Was eine Seite später noch tut, zählt nur bei ihr.
 */
function seite(elemente, { bestaetigen = () => true, antwort } = {}) {
    const anfragen = [];
    const zustand = { neuGeladen: 0 };
    const hoerer = {};
    const auswahl = (sel) => elemente.filter((e) => (e.passt || []).includes(sel));
    // Navigation API (Chromium): `navigateerror`, wenn das Navigieren abbricht (Stopp, Esc).
    const navigation = { addEventListener: (n, f) => { (hoerer['navigation:' + n] = hoerer['navigation:' + n] || []).push(f); } };
    const kontext = {
        navigation,
        document: {
            hidden: false,
            documentElement: element('html'),
            body: element('body'),
            addEventListener: (n, f) => { (hoerer[n] = hoerer[n] || []).push(f); },
            querySelector: (sel) => auswahl(sel)[0] || null,
            querySelectorAll: (sel) => auswahl(sel),
            getElementById: () => null,
            createElement: (tag) => element(tag),
        },
        location: { reload: () => { zustand.neuGeladen++; }, pathname: '/' },
        history: {},
        confirm: bestaetigen,
        setTimeout,
        clearTimeout,
        setInterval: () => 0,
        addEventListener: (n, f) => { (hoerer['window:' + n] = hoerer['window:' + n] || []).push(f); },
        localStorage: { length: 0, key: () => null, removeItem() {}, setItem() {} },
        AbortController,
        URLSearchParams,
        FormData: class { constructor() { this.felder = []; } append(n, v) { this.felder.push([n, v]); } },
        fetch: (adresse, init) => {
            anfragen.push({ adresse, init: init || {} });
            return Promise.resolve(antwort || { ok: false, status: 0, type: 'opaqueredirect' });
        },
    };
    kontext.window = kontext;
    vm.createContext(kontext);
    vm.runInContext(appQuelle, kontext);
    (hoerer.DOMContentLoaded || []).forEach((f) => f());
    const abbrechen = () => (hoerer['navigation:navigateerror'] || []).forEach((f) => f({ error: { name: 'AbortError' } }));
    return { anfragen, zustand, kontext, abbrechen };
}

const hinweisText = (el) => (el.danach && !el.danach.entfernt ? el.danach.kinder.map((k) => k.textContent).join('') : '');

(async () => {
    const out = {};

    // 1. Formular mit data-laeuft: per fetch, ohne JSON zu verlangen, danach neu laden.
    {
        const f = formular({ action: '/verteiler-abgleich/hochladen', 'data-laeuft': 'lädt hoch …' });
        f.passt = ['form[data-laeuft]'];
        const s = seite([f]);
        const e = f.feuern('submit', ereignis({ submitter: f.knopf }));
        await warten();
        const kopf = s.anfragen[0] ? s.anfragen[0].init.headers || {} : null;
        out.fetch = {
            abgefangen: e.defaultPrevented, anfragen: s.anfragen.length, kopf,
            umleitung: s.anfragen[0] ? s.anfragen[0].init.redirect : null, neuGeladen: s.zustand.neuGeladen,
        };
    }

    // 2. data-laeuft-seite (Kennwort genau einmal): der Browser schickt selbst ab, kein fetch;
    //    ein zweites Absenden – auch eines anderen data-laeuft-Formulars – wird abgefangen.
    {
        const f = formular({ action: '/datenquellen/mqtt-zugaenge/erneuern', 'data-laeuft': 'erneuert …', 'data-laeuft-seite': '' });
        const anderes = formular({ action: '/datenquellen/mqtt-zugaenge/pruefen', 'data-laeuft': 'prüft …' });
        f.passt = ['form[data-laeuft]'];
        anderes.passt = ['form[data-laeuft]'];
        const s = seite([f, anderes]);
        const erstes = f.feuern('submit', ereignis({ submitter: f.knopf })).defaultPrevented;
        await warten();
        const zweites = f.feuern('submit', ereignis({ submitter: f.knopf })).defaultPrevented;
        const drittes = anderes.feuern('submit', ereignis({ submitter: anderes.knopf })).defaultPrevented;
        out.seite = { erstesAbgefangen: erstes, zweitesAbgefangen: zweites, anderesAbgefangen: drittes, anfragen: s.anfragen.length,
            hinweis: f.knopf.danach ? f.knopf.danach.kinder.map((k) => k.textContent).join('') : '', knopfGesperrt: f.knopf.disabled };
    }

    // 3. Rückfrage abgebrochen: nichts gesperrt – der nächste Versuch geht hinaus.
    {
        let antwort = false;
        const f = formular({ action: '/datenquellen/mqtt-zugaenge/erneuern', 'data-laeuft': 'erneuert …', 'data-laeuft-seite': '', 'data-confirm': 'Sicher?' });
        f.passt = ['form[data-laeuft]', '[data-confirm]'];
        const s = seite([f], { bestaetigen: () => antwort });
        const abgebrochen = f.feuern('submit', ereignis({ submitter: f.knopf })).defaultPrevented;
        antwort = true;
        const bestaetigt = f.feuern('submit', ereignis({ submitter: f.knopf })).defaultPrevented;
        out.rueckfrage = { abgebrochenAbgefangen: abgebrochen, danachAbgefangen: bestaetigt, anfragen: s.anfragen.length };
    }

    // 4. Link mit data-laeuft („Repository abfragen“): der erste Klick navigiert, jeder
    //    weitere wird abgefangen; Strg-Klick (neuer Reiter) bleibt beim Browser.
    {
        const a = element('a', { href: '/location?holen=1', 'data-laeuft': 'fragt ab …', 'data-laeuft-hinweis': 'Fragt das Repository ab.' });
        a.textContent = 'Repository abfragen';
        a.passt = ['a[data-laeuft]'];
        const f = formular({ action: '/location/uebernehmen', 'data-laeuft': 'übernimmt …' });
        f.passt = ['form[data-laeuft]'];
        const s = seite([a, f]);
        const strg = a.feuern('click', ereignis({ ctrlKey: true })).defaultPrevented;
        const erster = a.feuern('click', ereignis()).defaultPrevented;
        const zweiter = a.feuern('click', ereignis()).defaultPrevented;
        const strgDanach = a.feuern('click', ereignis({ ctrlKey: true })).defaultPrevented;
        const formularDanach = f.feuern('submit', ereignis({ submitter: f.knopf })).defaultPrevented;
        out.link = { strgAbgefangen: strg, ersterAbgefangen: erster, zweiterAbgefangen: zweiter, strgDanachAbgefangen: strgDanach,
            formularDanachAbgefangen: formularDanach, anfragen: s.anfragen.length, text: a.textContent,
            gesperrt: a.getAttribute('aria-disabled'), hinweis: a.danach ? a.danach.kinder.map((k) => k.textContent).join('') : '' };
    }

    // 5. Strg-/Umschalt-Klick auf ein Formular mit data-laeuft: Der Browser schickt es in
    //    einen neuen Reiter ab – hier wird nichts gesperrt, kein fetch, kein Hinweis, und
    //    das nächste Formular geht hinaus. Ein gewöhnlicher Klick danach sperrt wie sonst.
    {
        const f = formular({ action: '/datenquellen/mqtt-zugaenge/erneuern', 'data-laeuft': 'erneuert …', 'data-laeuft-seite': '' });
        const anderes = formular({ action: '/datenquellen/mqtt-zugaenge/pruefen', 'data-laeuft': 'prüft …' });
        f.passt = ['form[data-laeuft]'];
        anderes.passt = ['form[data-laeuft]'];
        const s = seite([f, anderes]);
        f.feuern('click', ereignis({ target: f.knopf, ctrlKey: true }));
        const strg = f.feuern('submit', ereignis({ submitter: f.knopf })).defaultPrevented;
        await warten();
        const nachStrg = { knopfGesperrt: f.knopf.disabled, text: f.knopf.textContent, hinweis: hinweisText(f.knopf) };
        anderes.feuern('click', ereignis({ target: anderes.knopf, shiftKey: true }));
        const umschaltAnderes = anderes.feuern('submit', ereignis({ submitter: anderes.knopf })).defaultPrevented;
        const anfragenUmschalt = s.anfragen.length;
        await warten();
        anderes.feuern('click', ereignis({ target: anderes.knopf }));
        anderes.feuern('submit', ereignis({ submitter: anderes.knopf }));
        out.strgFormular = { strgAbgefangen: strg, nachStrg, umschaltAnderesAbgefangen: umschaltAnderes, anfragenUmschalt,
            anfragenDanach: s.anfragen.length };

        const zweite = formular({ action: '/datenquellen/mqtt-zugaenge/erneuern', 'data-laeuft': 'erneuert …', 'data-laeuft-seite': '' });
        zweite.passt = ['form[data-laeuft]'];
        seite([zweite]);
        zweite.feuern('click', ereignis({ target: zweite.knopf, ctrlKey: true }));
        zweite.feuern('submit', ereignis({ submitter: zweite.knopf }));
        await warten();
        zweite.feuern('click', ereignis({ target: zweite.knopf }));
        const normal = zweite.feuern('submit', ereignis({ submitter: zweite.knopf })).defaultPrevented;
        await warten();
        Object.assign(out.strgFormular, { normalAbgefangen: normal, normalGesperrt: zweite.knopf.disabled, normalHinweis: hinweisText(zweite.knopf) });
    }

    // 6. Navigieren abgebrochen (Stopp, Esc → navigateerror): Link, Formular mit
    //    data-laeuft-seite und das Neuladen nach fetch geben die Seite wieder frei.
    {
        const a = element('a', { href: '/location?holen=1', 'data-laeuft': 'fragt ab …', 'data-laeuft-hinweis': 'Fragt das Repository ab.' });
        a.textContent = 'Repository abfragen';
        a.passt = ['a[data-laeuft]'];
        const f = formular({ action: '/datenquellen/mqtt-zugaenge/erneuern', 'data-laeuft': 'erneuert …', 'data-laeuft-seite': '' });
        const anderes = formular({ action: '/location/uebernehmen', 'data-laeuft': 'übernimmt …' });
        f.passt = ['form[data-laeuft]'];
        anderes.passt = ['form[data-laeuft]'];
        const s = seite([a, f, anderes]);
        const ergebnis = {};

        a.feuern('click', ereignis());
        const linkGesperrt = a.getAttribute('aria-disabled') === 'true' && a.feuern('click', ereignis()).defaultPrevented;
        s.abbrechen();
        ergebnis.link = { vorher: linkGesperrt, text: a.textContent, gesperrt: a.getAttribute('aria-disabled'), hinweis: hinweisText(a),
            wiederAbgefangen: a.feuern('click', ereignis()).defaultPrevented };
        s.abbrechen();

        f.feuern('submit', ereignis({ submitter: f.knopf }));
        await warten();
        const seiteGesperrt = f.knopf.disabled && anderes.feuern('submit', ereignis({ submitter: anderes.knopf })).defaultPrevented && s.anfragen.length === 0;
        s.abbrechen();
        ergebnis.seite = { vorher: seiteGesperrt, knopfGesperrt: f.knopf.disabled, text: f.knopf.textContent, hinweis: hinweisText(f.knopf) };

        anderes.feuern('submit', ereignis({ submitter: anderes.knopf }));
        for (let i = 0; i < 3; i++) { await warten(); }
        const neuGeladen = s.zustand.neuGeladen;
        const nachFetch = anderes.knopf.disabled;
        s.abbrechen();
        ergebnis.neuladen = { anfragen: s.anfragen.length, neuGeladen, vorherGesperrt: nachFetch, knopfGesperrt: anderes.knopf.disabled,
            text: anderes.knopf.textContent, hinweis: hinweisText(anderes.knopf),
            linkDanachAbgefangen: a.feuern('click', ereignis()).defaultPrevented };
        out.abbruch = ergebnis;
    }

    // 7. live.js: 423 mit „kundenmodus“ → neu laden (zur Kundenseite); 401 → nur die Meldung.
    for (const [name, daten, status] of [['kundenmodus', { ok: false, kundenmodus: true, message: 'Kundenmodus aktiv' }, 423],
        ['abgelaufen', { ok: false, anmelden: true, message: 'Sitzung abgelaufen' }, 401]]) {
        const quelle = element('div', { 'data-live-url': '/dashboard/live' });
        quelle.passt = ['[data-live-url]'];
        const s = seite([quelle], { antwort: { ok: false, status, json: () => Promise.resolve(daten) } });
        // Nur der eine Abruf hier – keine Wiederholung, keine Zeitgrenze von 15 s.
        s.kontext.setTimeout = () => 0;
        s.kontext.clearTimeout = () => {};
        vm.runInContext(liveQuelle, s.kontext);
        s.kontext.PM.liveNeu();
        for (let i = 0; i < 5; i++) { await warten(); }
        out['live_' + name] = { neuGeladen: s.zustand.neuGeladen, stand: quelle.textContent };
    }

    process.stdout.write(JSON.stringify(out));
})().catch((e) => {
    process.stdout.write(JSON.stringify({ fehler: String(e && e.stack ? e.stack : e) }));
});
