/**
 * Die Karte von PowerManagement.
 *
 * Grundlage ist dieselbe Leaflet-Karte wie in MasterAdmin (plan-karte.js):
 * Punkte stehen in Prozent des Plans, die Karte rechnet in Plankoordinaten.
 * Darauf liegen:
 *
 *   Anlagen        Anschlüsse, Generatoren, Wechselrichter, Verteilungen –
 *                  Rand in der Farbe der Auslastung, daneben die Leistung.
 *   Kabel          Linienzug von einer Anlage zur anderen, mit Anschluss an
 *                  beiden Enden; Länge aus dem Maßstab.
 *   Kabelwege      Trassen ohne feste Enden (gestrichelt).
 *   Kabelbrücken   gelb-schwarz, mit Modell und Anzahl der Elemente.
 *   MasterAdmin    Racks, Dosen und Accesspoints zur Orientierung (abschaltbar).
 *
 * Werkzeuge: Ansehen, Verschieben, Anlage setzen (auch aus dem Katalog der
 * Standard-Verteiler), Kabel, Kabelweg, Kabelbrücke, Maßstab.
 *
 * Geschrieben wird über kleine POSTs; die Seite lädt sich dabei nicht neu,
 * damit der Ausschnitt bleibt, in dem man auf dem Tablet gerade arbeitet.
 */

const kennung = new URL(import.meta.url).search;
const { planKarte } = await import('./plan-karte.js' + kennung);

const el = document.getElementById('karte');
const seite = document.getElementById('seite-inhalt');
const statusEl = document.getElementById('karte-status');
const daten = JSON.parse(document.getElementById('karte-daten').textContent);
const etage = Number(el.dataset.etage);
const PM = window.PM;

const karte = await planKarte(el, { melden: (t) => status(t) });
const map = karte.map;
// Mehr Rand um den Plan als beim reinen Ansehen: Die Werte je Phase und die
// Fenster an Anlagen am Planrand brauchen Platz, sonst schneidet die Karte
// sie ab, weil sie nicht weit genug nachschieben darf.
map.setMaxBounds(karte.grenzen.pad(0.9));

const ebenen = {
    kabelweg: L.layerGroup().addTo(map),
    kabelbruecke: L.layerGroup().addTo(map),
    kabel: L.layerGroup().addTo(map),
    fremd: L.layerGroup(),
    // Feststromanschlüsse (gehören zur Location) und mobile Anlagen lassen
    // sich getrennt ausblenden – etwa um nur zu sehen, was vor Ort fest ist.
    fest: L.layerGroup().addTo(map),
    mobil: L.layerGroup().addTo(map),
    entwurf: L.layerGroup().addTo(map),
    griffe: L.layerGroup().addTo(map),
    position: L.layerGroup().addTo(map),
};

let stand = { objekte: [], leitungen: [], fremd: {}, massstab: null, geo: null, aufbau: null };
const marker = new Map();
const linien = new Map();
let live = {};
let modus = 'ansehen';
let auswahl = null;           // { art: 'objekt'|'leitung', id }
let entwurf = null;           // { art, punkte: [{x,y}], von, zu }
let setzenWahl = null;        // { art, fest, vorlage_id, name } oder { objekt_id }
// Modi, in denen ein Tipp in den Plan einen Punkt setzt.
const ZEICHNEN = ['setzen', 'kabel', 'kabelweg', 'bruecke', 'massstab', 'geo'];

// ------------------------------------------------------------------ Hilfen

function esc(text) {
    return String(text === null || text === undefined ? '' : text)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function status(text) {
    statusEl.textContent = text || '';
    statusEl.classList.toggle('sichtbar', !!text);
}

function laengeImPlan(punkte) {
    const m = stand.massstab;
    if (!m || punkte.length < 2) { return null; }
    const v = m.verhaeltnis;
    const ab = (a, b) => Math.hypot(b.x - a.x, (b.y - a.y) * v);
    const referenz = ab(m.a, m.b);
    if (!(referenz > 0)) { return null; }
    let summe = 0;
    for (let i = 1; i < punkte.length; i++) { summe += ab(punkte[i - 1], punkte[i]); }
    return summe / referenz * m.meter;
}

// Georeferenz – dieselbe Rechnung wie PlanGeoService: Ebene um Passpunkt A,
// Plan in Breiten-Einheiten, Drehstreckung aus den beiden Passpunkten.
const ERDRADIUS = 6371008.8;

function geoAbbildung(g) {
    const k = ERDRADIUS * Math.PI / 180;
    const kx = k * Math.cos(g.a.lat * Math.PI / 180);
    const px = g.b.x - g.a.x;
    const py = -(g.b.y - g.a.y) * g.verhaeltnis;
    const ost = (g.b.lon - g.a.lon) * kx;
    const nord = (g.b.lat - g.a.lat) * k;
    const betrag = px * px + py * py;
    return { sr: (ost * px + nord * py) / betrag, si: (nord * px - ost * py) / betrag, k, kx };
}

/** Breite/Länge → Prozent des Plans (auch außerhalb 0–100). */
function geoZuPlan(g, lat, lon) {
    const { sr, si, k, kx } = geoAbbildung(g);
    const ost = (lon - g.a.lon) * kx;
    const nord = (lat - g.a.lat) * k;
    const betrag = sr * sr + si * si;
    const qx = (ost * sr + nord * si) / betrag;
    const qy = (nord * sr - ost * si) / betrag;
    return { x: g.a.x + qx, y: g.a.y - qy / g.verhaeltnis, meterJeEinheit: Math.sqrt(betrag) };
}

/** Stand einer Position im laufenden Aufbau („o:12“, „l:34“) – oder null. */
function aufbauStand(schluessel) {
    return stand.aufbau && stand.aufbau.posten ? (stand.aufbau.posten[schluessel] || null) : null;
}

function koordinate(wert) {
    return Number(wert).toFixed(6);
}

function meter(wert) {
    return wert === null || wert === undefined ? '–' : wert.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' m';
}

function objektNachId(id) {
    return stand.objekte.find((o) => o.id === id) || null;
}

// ------------------------------------------------------------------ Laden

async function laden() {
    const antwort = await fetch(`/karte/${etage}/stand`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    stand = await antwort.json();
    zeichnen();
}

function zeichnen() {
    Object.values(ebenen).forEach((g) => { if (g !== ebenen.entwurf) { g.clearLayers(); } });
    marker.clear();
    linien.clear();
    stand.leitungen.forEach(linieZeichnen);
    stand.objekte.forEach(markerZeichnen);
    fremdZeichnen();
    liveAnwenden();
    if (auswahl && auswahl.art === 'leitung' && modus === 'verschieben') { griffeZeigen(auswahl.id); }
}

function markerZeichnen(o) {
    const html = `<span class="pm-symbol" style="--art-farbe:${esc(o.farbe)}">${esc(o.kuerzel)}</span>`
        + `<span class="plabel">${esc(o.name)}</span>`
        + `<span class="pm-wert" data-wert title="Antippen: Werte je Phase"></span>`
        + (o.messgeraete.length ? '<span class="pm-mess" title="Messgerät eingebaut">M</span>' : '');
    const aufbau = aufbauStand('o:' + o.id);
    const m = karte.punkt(o.x, o.y, `pm-anlage art-${o.art}${o.fest ? ' fest' : ''}${aufbau ? ' aufbau-' + aufbau : ''} stufe-keine`, html,
        { draggable: false, title: o.name + (o.fest ? ' (fest installiert)' : '') });
    m.on('click', (e) => { L.DomEvent.stopPropagation(e); objektKlick(o, e); });
    m.on('dragend', () => objektVerschoben(o, m));
    m.addTo(o.fest ? ebenen.fest : ebenen.mobil);
    marker.set(o.id, m);
    if (modus === 'verschieben' && o.darf) { m.dragging.enable(); }
}

function linieZeichnen(l) {
    const punkte = l.punkte.map((p) => ({ x: p[0], y: p[1] }));
    if (punkte.length < 2) { return; }
    let lage;
    if (l.art === 'kabelbruecke') {
        lage = L.layerGroup([
            karte.linie(punkte, { color: '#facc15', weight: 13, opacity: 1, lineCap: 'butt' }),
            karte.linie(punkte, { color: '#111827', weight: 13, opacity: 1, dashArray: '5 7', lineCap: 'butt', className: 'pm-bruecke' + (aufbauStand('l:' + l.id) ? ' aufbau-' + aufbauStand('l:' + l.id) : '') }),
        ]);
    } else if (l.art === 'kabelweg') {
        const weg = karte.linie(punkte, { color: '#64748b', weight: 5, opacity: 0.8, dashArray: l.fest ? null : '10 8', className: 'pm-kabelweg' });
        // Fest verlegt: mit dunklem Rand, wie die Feststromanschlüsse.
        lage = l.fest ? L.layerGroup([karte.linie(punkte, { color: '#0f172a', weight: 9, opacity: 0.9, interactive: true }), weg]) : weg;
    } else {
        const q = l.querschnitt || 2.5;
        const staerke = Math.max(3, Math.min(9, 2 + Math.log2(q)));
        const kabel = karte.linie(punkte, {
            color: l.fest ? (l.farbe || '#e2e8f0') : (l.farbe || '#1e293b'),
            weight: staerke,
            className: 'pm-kabel' + (l.fest ? ' fest' : '') + (l.hinweise.length ? ' hinweis' : '') + (aufbauStand('l:' + l.id) ? ' aufbau-' + aufbauStand('l:' + l.id) : ''),
        });
        lage = l.fest ? L.layerGroup([karte.linie(punkte, { color: '#0f172a', weight: staerke + 4, opacity: 1 }), kabel]) : kabel;
    }
    const text = (l.fest ? 'fest verlegt · ' : '') + (l.art === 'kabel'
        ? `${esc(l.name || 'Kabel')}: ${esc(l.von_name || '?')} → ${esc(l.zu_name || '?')}${l.laenge !== null ? ' · ' + meter(l.laenge) : ''}`
        : l.art === 'kabelbruecke'
            ? `Kabelbrücke${l.modell ? ' ' + esc(l.modell) : ''} × ${l.anzahl}${l.name ? ' · ' + esc(l.name) : ''}`
            : `Kabelweg${l.name ? ' ' + esc(l.name) : ''}${l.laenge !== null ? ' · ' + meter(l.laenge) : ''}`);
    lage.eachLayer ? lage.eachLayer((x) => x.bindTooltip(text, { sticky: true })) : lage.bindTooltip(text, { sticky: true });
    const klick = (e) => {
        L.DomEvent.stopPropagation(e);
        // Beim Zeichnen ist eine Linie im Weg kein Ziel, sondern ein Punkt –
        // etwa ein neues Kabel entlang eines Kabelwegs.
        if (ZEICHNEN.includes(modus)) { map.fire('click', { latlng: e.latlng }); return; }
        leitungKlick(l);
    };
    lage.eachLayer ? lage.eachLayer((x) => x.on('click', klick)) : lage.on('click', klick);
    // Fest verlegtes gehört zur Ebene der Feststromanschlüsse – blendet man
    // das Mobile aus, bleibt die Infrastruktur der Location stehen.
    lage.addTo(l.fest ? ebenen.fest : (ebenen[l.art] || ebenen.kabel));
    linien.set(l.id, lage);
}

function fremdZeichnen() {
    const f = stand.fremd || {};
    (f.racks || []).forEach((r) => {
        karte.punkt(r.x, r.y, 'pm-fremd rack', `<span class="dot"></span><span class="plabel">${esc(r.name)}${r.watt ? ' · ' + r.watt + ' W' : ''}</span>`, { interactive: false }).addTo(ebenen.fremd);
    });
    (f.dosen || []).forEach((d) => {
        karte.punkt(d.x, d.y, 'pm-fremd dose', `<span class="dot"></span><span class="plabel">${esc(d.label)}</span>`, { interactive: false }).addTo(ebenen.fremd);
    });
    (f.aps || []).forEach((a) => {
        karte.punkt(a.x, a.y, 'pm-fremd ap', `<span class="dot"></span><span class="plabel">${esc(a.name)}</span>`, { interactive: false }).addTo(ebenen.fremd);
    });
}

// ------------------------------------------------------------------ Live

// Immer nur eine Abfrage zugleich – wie in live.js: eine langsame InfluxDB
// soll keinen Stau beim Server erzeugen, hinter dem das Speichern wartet.
let liveLaeuft = false;

async function liveLaden() {
    if (document.hidden || liveLaeuft) { return; }
    liveLaeuft = true;
    const abbruch = new AbortController();
    const frist = setTimeout(() => abbruch.abort(), 15000);
    try {
        const antwort = await fetch(`/karte/${etage}/live`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: abbruch.signal });
        live = (await antwort.json()).objekte || {};
        liveAnwenden();
        if (auswahl && auswahl.art === 'objekt') { panelLiveSetzen(auswahl.id); }
    } catch (e) { /* nächster Versuch in 5 Sekunden */ } finally {
        clearTimeout(frist);
        liveLaeuft = false;
    }
}

function liveAnwenden() {
    if (detail && detail.popup.isOpen()) { detail.popup.setContent(detailHtml(detail.objekt)); }
    marker.forEach((m, id) => {
        const l = live[id];
        const knoten = m.getElement();
        if (!knoten) { return; }
        knoten.className = knoten.className.replace(/\bstufe-\S+/g, '') + ' stufe-' + (l ? l.stufe : 'keine');
        const wert = knoten.querySelector('[data-wert]');
        if (wert) {
            wert.textContent = l && l.p !== null && l.p !== undefined
                ? PM.format(l.p, 'W') + (l.auslastung !== null && l.auslastung !== undefined ? ' · ' + Math.round(l.auslastung) + ' %' : '')
                : '';
        }
    });
}

// ------------------------------------------------------------------ Werkzeuge

function setModus(neu) {
    modus = neu;
    entwurfVerwerfen();
    document.querySelectorAll('[data-modus]').forEach((b) => b.classList.toggle('aktiv', b.dataset.modus === neu));
    el.classList.toggle('platzieren', ZEICHNEN.includes(neu));
    el.classList.toggle('aufbau-modus', neu === 'aufbau');
    marker.forEach((m, id) => {
        const o = objektNachId(id);
        if (neu === 'verschieben' && o && o.darf) { m.dragging.enable(); } else { m.dragging.disable(); }
    });
    ebenen.griffe.clearLayers();
    const hilfe = {
        ansehen: '',
        verschieben: 'Anlagen ziehen – feste wie mobile, angeschlossene Kabel folgen. Ein Kabel, einen Kabelweg oder eine Kabelbrücke antippen, um sie zu verschieben.',
        setzen: 'Art oder Standard-Verteiler wählen, dann in den Plan tippen.',
        kabel: 'Kabel: zuerst die speisende Anlage antippen, dann den Weg, dann die Anlage am Ende. Esc bricht ab, ⌫ nimmt den letzten Punkt.',
        kabelweg: 'Kabelweg: Punkte setzen, mit „Fertig“ oder Doppeltipp abschließen.',
        bruecke: 'Kabelbrücke: Anfang und Ende quer über den Weg tippen.',
        massstab: 'Maßstab: zwei Punkte mit bekanntem Abstand tippen (z. B. Maßstabsleiste des Plans).',
        geo: 'Georeferenz: zwei gut erkennbare Punkte weit auseinander antippen (Gebäudeecken, Tor, Mast), dann ihre Koordinaten eintragen.',
        aufbau: aufbauText(),
    };
    status(hilfe[neu] || '');
    if (neu === 'setzen') { setzenPanel(); }
    if (neu === 'geo') { geoPanel(); }
    if (neu === 'aufbau') { aufbauUebersicht(); }
    if (neu === 'kabelweg') { entwurfPanel('kabelweg'); }
}

document.querySelectorAll('[data-modus]').forEach((b) => b.addEventListener('click', () => setModus(b.dataset.modus)));
document.querySelector('[data-aktion="vollbild"]').addEventListener('click', async () => {
    await karte.vollbild.umschalten();
    karte.nachmessen();
});
document.querySelectorAll('[data-ebene]').forEach((box) => box.addEventListener('change', () => {
    const name = box.dataset.ebene;
    if (ebenen[name]) {
        box.checked ? ebenen[name].addTo(map) : ebenen[name].remove();
    } else {
        el.classList.toggle('ohne-' + name, !box.checked);
    }
}));
document.addEventListener('keydown', (e) => {
    if (!entwurf) { return; }
    if (e.key === 'Escape') { entwurfVerwerfen(); status('Abgebrochen.'); }
    if (e.key === 'Backspace' && !['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
        e.preventDefault();
        entwurf.punkte.pop();
        entwurfZeichnen();
    }
});
seite.addEventListener('click', (e) => {
    const vorhanden = e.target.closest('[data-setzen-vorhanden]');
    if (vorhanden) {
        setModus('setzen');
        setzenWahl = { objekt_id: vorhanden.dataset.setzenVorhanden, name: vorhanden.textContent.trim() };
        status('„' + setzenWahl.name + '“: jetzt in den Plan tippen.');
    }
});

map.on('click', (e) => {
    const p = karte.zuProzent(e.latlng);
    if (modus === 'setzen') { return setzenKlick(p); }
    if (modus === 'kabel') {
        if (!entwurf) { status('Zuerst die speisende Anlage antippen.'); return; }
        entwurf.punkte.push(p);
        entwurfZeichnen();
        return;
    }
    if (modus === 'kabelweg' || modus === 'bruecke' || modus === 'massstab' || modus === 'geo') {
        if (!entwurf || (modus === 'geo' && entwurf.punkte.length >= 2)) { entwurf = { art: modus, punkte: [] }; }
        entwurf.punkte.push(p);
        entwurfZeichnen();
        if (modus === 'bruecke' && entwurf.punkte.length === 2) { entwurfPanel('bruecke'); }
        if (modus === 'massstab' && entwurf.punkte.length === 2) { massstabPanel(); }
        if (modus === 'geo') { geoPanel(); }
        return;
    }
    if (modus === 'ansehen') { auswahl = null; }
});
map.on('dblclick', () => {
    if (!entwurf || !['kabelweg', 'kabel'].includes(modus)) { return; }
    // Ein Doppeltipp sind auch zwei einfache: den doppelten Punkt wieder weg.
    const n = entwurf.punkte.length;
    if (n >= 2 && Math.hypot(entwurf.punkte[n - 1].x - entwurf.punkte[n - 2].x, entwurf.punkte[n - 1].y - entwurf.punkte[n - 2].y) < 0.3) {
        entwurf.punkte.pop();
        entwurfZeichnen();
    }
    if (entwurf.punkte.length < 2) { return; }
    // Kabel mit offenem Ende (etwa zu einem Verbraucher ohne eigene Anlage).
    if (modus === 'kabel') { kabelPanel(null); } else { entwurfPanel('kabelweg', true); }
});

function entwurfZeichnen() {
    ebenen.entwurf.clearLayers();
    if (!entwurf) { return; }
    const farbe = { kabel: '#2563eb', kabelweg: '#64748b', bruecke: '#ca8a04', massstab: '#db2777', geo: '#0d9488' }[entwurf.art] || '#2563eb';
    if (entwurf.punkte.length >= 2) {
        karte.linie(entwurf.punkte, { color: farbe, weight: 4, dashArray: '6 6' }).addTo(ebenen.entwurf);
    }
    entwurf.punkte.forEach((p, i) => {
        const nr = entwurf.art === 'geo' ? `<span class="geo-nr">${i + 1}</span>` : '';
        karte.punkt(p.x, p.y, 'plan-kabel-punkt' + (i === 0 ? ' ende' : ''), '<span class="griff"></span>' + nr, { interactive: false }).addTo(ebenen.entwurf);
    });
    const l = laengeImPlan(entwurf.punkte);
    if (entwurf.art !== 'massstab' && entwurf.art !== 'geo') {
        status((entwurf.art === 'kabel' ? 'Kabel' : entwurf.art === 'bruecke' ? 'Kabelbrücke' : 'Kabelweg')
            + ': ' + entwurf.punkte.length + ' Punkt(e)' + (l !== null ? ', ' + meter(l) : ''));
    }
}

function entwurfVerwerfen() {
    entwurf = null;
    ebenen.entwurf.clearLayers();
}

// ------------------------------------------------------------------ Anlagen

function objektKlick(o, e = null) {
    // Tipp auf den Wert neben der Anlage: die Werte je Phase direkt im Plan.
    const ziel = e && e.originalEvent && e.originalEvent.target;
    if ((modus === 'ansehen' || modus === 'verschieben') && ziel && ziel.closest && ziel.closest('[data-wert]')) {
        detailZeigen(o);
        return;
    }
    if (modus === 'kabel') {
        // Wie der Server: an einem Tank endet kein Kabel.
        if (o.elektrisch === false) {
            status('Ein Kabel endet nicht an einem ' + (daten.arten[o.art] ? daten.arten[o.art].name : 'Tank') + '.');
            return;
        }
        if (!entwurf) {
            entwurf = { art: 'kabel', punkte: [{ x: o.x, y: o.y }], von: o.id };
            entwurfZeichnen();
            status('Kabel ab „' + o.name + '“ – Weg tippen, dann die Anlage am Ende.');
            return;
        }
        if (o.id === entwurf.von) { return; }
        entwurf.punkte.push({ x: o.x, y: o.y });
        entwurf.zu = o.id;
        entwurfZeichnen();
        kabelPanel(null);
        return;
    }
    // Eine Anlage ist beim Zeichnen ein genauer Punkt – eine Energiesäule
    // etwa ein guter Passpunkt für die Georeferenz.
    if (['kabelweg', 'bruecke', 'massstab', 'geo'].includes(modus)) { map.fire('click', { latlng: karte.zuLatLng(o.x, o.y) }); return; }
    if (modus === 'setzen') { return; }
    if (modus === 'aufbau') { aufbauPanel('objekt', o.id); return; }
    objektPanel(o.id);
}

async function objektVerschoben(o, m) {
    const p = karte.zuProzent(m.getLatLng());
    const erg = await PM.post('/karte/objekt/verschieben', { id: o.id, floor_id: etage, x: p.x, y: p.y });
    if (!erg.ok) { PM.melden(erg.message, false); await laden(); return; }
    // Kabel, die an der Anlage hängen, gehen mit.
    const alt = { x: o.x, y: o.y };
    o.x = p.x; o.y = p.y;
    for (const l of stand.leitungen.filter((x) => x.von === o.id || x.zu === o.id)) {
        const punkte = l.punkte.map((q) => ({ x: q[0], y: q[1] }));
        if (l.von === o.id && punkte.length) { punkte[0] = { x: p.x, y: p.y }; }
        if (l.zu === o.id && punkte.length) { punkte[punkte.length - 1] = { x: p.x, y: p.y }; }
        await PM.post('/karte/leitung/verlauf', { id: l.id, punkte: JSON.stringify(punkte) });
    }
    status('„' + o.name + '“ verschoben' + (Math.hypot(alt.x - p.x, alt.y - p.y) > 0 ? '.' : ''));
    await laden();
}

function setzenPanel() {
    const vorlage = (v) => `<option value="vorlage:${v.id}">${esc(v.name)} – ${esc(v.kurz)}</option>`;
    const festVorlagen = daten.vorlagen.filter((v) => v.fest).map(vorlage).join('');
    // Mobile Katalog-Typen nach Art: Verteiler (und was sonst Strom führt), Generatoren, Tanks.
    const gruppe = (v) => (v.art === 'generator' || v.art === 'tank' ? v.art : 'verteiler');
    const mobil = (g) => daten.vorlagen.filter((v) => !v.fest && gruppe(v) === g).map(vorlage).join('');
    const mobilGruppen = [['verteiler', 'Standard-Verteiler (mobil)'], ['generator', 'Generatoren aus dem Katalog'], ['tank', 'Tanks aus dem Katalog']]
        .map(([g, titel]) => [titel, mobil(g)]).filter(([, optionen]) => optionen)
        .map(([titel, optionen]) => `<optgroup label="${esc(titel)}">${optionen}</optgroup>`).join('');
    const mobilArten = Object.entries(daten.arten).filter(([k]) => k !== 'anschluss')
        .map(([k, a]) => `<option value="mobil:${k}">${esc(a.name)} (leer)</option>`).join('');
    seite.innerHTML = `
        <h2>Anlage setzen</h2>
        <div class="field"><label for="setzen-wahl">Was</label>
            <select id="setzen-wahl">
                <optgroup label="Feststromanschluss – gehört zur Location">
                    <option value="fest:anschluss">Feststromanschluss (leer)</option>
                    ${festVorlagen}
                    <option value="fest:verteilung">Verteilung, fest eingebaut (leer)</option>
                </optgroup>
                ${mobilGruppen}
                <optgroup label="Neue mobile Anlage">${mobilArten}</optgroup>
            </select></div>
        <div class="field"><label for="setzen-name">Name</label>
            <input id="setzen-name" placeholder="leer: Name aus dem Katalog"></div>
        <p class="help">Danach in den Plan tippen. Feststromanschlüsse (Hausanschluss, Energiesäule, Wandverteiler …) gehören zur
            Location und werden beim Location-Abgleich übertragen, mobile Anlagen bleiben hier. Anschlüsse, Sicherungen und RCDs
            kommen bei einem Katalog-Eintrag mit und lassen sich an der neuen Anlage einzeln ändern.</p>`;
    const wahl = seite.querySelector('#setzen-wahl');
    const name = seite.querySelector('#setzen-name');
    const merken = () => {
        const [art, wert] = wahl.value.split(':');
        setzenWahl = art === 'vorlage'
            ? { vorlage_id: wert, name: name.value }
            : { art: wert, fest: art === 'fest' ? 1 : 0, name: name.value };
    };
    wahl.addEventListener('change', merken);
    name.addEventListener('input', merken);
    merken();
}

async function setzenKlick(p) {
    if (!setzenWahl) { setzenPanel(); return; }
    const anfrage = { floor_id: etage, x: p.x, y: p.y };
    if (setzenWahl.objekt_id) {
        anfrage.objekt_id = setzenWahl.objekt_id;
    } else if (setzenWahl.vorlage_id) {
        anfrage.vorlage_id = setzenWahl.vorlage_id;
        anfrage.name = setzenWahl.name || '';
    } else {
        anfrage.art = setzenWahl.art;
        anfrage.fest = setzenWahl.fest;
        const grundname = setzenWahl.fest && setzenWahl.art === 'anschluss' ? 'Feststromanschluss' : daten.arten[setzenWahl.art].name;
        anfrage.name = setzenWahl.name || (grundname + ' ' + (stand.objekte.length + 1));
    }
    const erg = await PM.post('/karte/objekt/setzen', anfrage);
    PM.melden(erg.message || (erg.ok ? 'Gesetzt.' : 'Nicht möglich.'), !!erg.ok);
    if (!erg.ok) { return; }
    if (setzenWahl.objekt_id) {
        const knopf = document.querySelector(`[data-setzen-vorhanden="${setzenWahl.objekt_id}"]`);
        if (knopf) { knopf.closest('li').remove(); }
        setzenWahl = null;
    }
    await laden();
    setModus('ansehen');
    if (erg.id) { objektPanel(erg.id); }
}

async function objektPanel(id) {
    auswahl = { art: 'objekt', id };
    seite.innerHTML = '<p class="muted">Lade …</p>';
    const antwort = await fetch(`/karte/objekt/${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const o = await antwort.json();
    if (!o.ok) { seite.innerHTML = `<p class="muted">${esc(o.message)}</p>`; return; }
    const art = daten.arten[o.art];
    const abgaenge = o.anschluesse.filter((a) => a.richtung === 'abgang');
    const eingaenge = o.anschluesse.filter((a) => a.richtung === 'eingang');
    const anschlussZeile = (a) => `<li><span class="stecker" style="--stecker-farbe:${esc(a.farbe)}"></span>
        <strong>${esc(a.bezeichnung)}</strong> ${esc(a.typ)}${a.phase ? ' · ' + esc(a.phase) : ''}
        ${a.schutz ? `<div class="small muted">${esc(a.schutz)}</div>` : ''}
        <div class="small">${a.belegt ? (a.richtung === 'eingang' ? '← ' : '→ ') + esc(a.belegt) : '<span class="muted">frei</span>'}</div></li>`;
    seite.innerHTML = `
        <div class="seite-kopf">
            <span class="art-zeichen" style="--art-farbe:${esc(art.farbe)}">${esc(art.kuerzel)}</span>
            <div><h2>${esc(o.name)}</h2><div class="small muted">${esc(o.art_name)}${o.kennung ? ' · ' + esc(o.kennung) : ''} · ${esc(o.status)}</div></div>
        </div>
        <div class="small muted">${esc(o.modell)}</div>
        ${o.geo ? `<div class="small anlage-lage">Lage ${koordinate(o.geo.lat)}, ${koordinate(o.geo.lon)} ·
            <a href="${esc(o.geo.link)}" target="_blank" rel="noopener">in der Karte öffnen</a></div>` : ''}
        ${o.inventar && o.inventar.length ? `<div class="small anlage-lage">Aus dem Inventar: ${o.inventar.map((i) => `<strong>${esc(i.kennung)}</strong>${i.anschluss ? ' (' + esc(i.anschluss) + ')' : ''}`).join(', ')}</div>` : ''}
        ${o.darf && o.elektrisch && o.art !== 'anschluss' && daten.darf.inventar ? `<div class="small"><a href="/anlagen/${o.id}?reiter=messung">Verteiler oder Zwischenstecker aus dem Inventar einsetzen …</a></div>` : ''}
        ${o.elektrisch ? '<div class="seite-live" data-seite-live></div>' : ''}
        ${o.messgeraete.length ? `<h3 class="mt">Messgeräte</h3><ul class="liste-schlicht messgeraete">${o.messgeraete.map((m) => `
            <li><span class="messgeraet"><span class="messgeraet-zeichen">M</span>
                <span class="messgeraet-name">${esc(m.titel)}</span>
                <span class="messgeraet-wert" data-mp-wert="${m.id}"></span>
                ${o.darf_statistik ? `<button type="button" class="btn btn-sm auswahl-knopf${m.in_auswertung ? ' aktiv' : ''}" data-auswertung="${m.id}" aria-pressed="${m.in_auswertung}">
                    <span data-auswertung-text>${m.in_auswertung ? 'in der Auswertung' : 'zur Auswertung'}</span></button>` : ''}
            </span></li>`).join('')}</ul>` : ''}
        ${o.schaltbar.length ? `<h3 class="mt">Schalten</h3>${o.schaltbar.map((s) => `
            <div class="schalt-zeile"><div class="schalt-titel"><strong>${esc(s.titel)}</strong><span class="small muted">${esc(s.quelle)}${s.kanal ? ' · ' + esc(s.kanal) : ''}</span></div>
            <div class="btn-row">${s.befehle.map((b) => `<button type="button" class="btn btn-sm ${b.art === 'aus' ? 'btn-danger' : b.art === 'ein' ? 'btn-primary' : ''}"
                data-schalten data-quelle="${s.quelle_id}" data-befehl="${esc(b.schluessel)}" data-kanal="${esc(s.kanal || b.kanal)}" data-objekt="${s.objekt_id}"
                data-frage="${esc(s.titel + ': „' + b.name + '“ wirklich ausführen?')}">${esc(b.name)}</button>`).join('')}</div></div>`).join('')}` : ''}
        <h3 class="mt">${o.elektrisch ? 'Anschlüsse' : 'Inhalt'}</h3>
        <p class="small">${esc(o.kurz)}</p>
        ${eingaenge.length ? `<div class="small muted">Eingänge</div><ul class="anschluss-liste">${eingaenge.map(anschlussZeile).join('')}</ul>` : ''}
        ${abgaenge.length ? `<div class="small muted">Abgänge</div><ul class="anschluss-liste">${abgaenge.map(anschlussZeile).join('')}</ul>` : ''}
        <div class="btn-row mt">
            <a class="btn btn-sm btn-primary" href="/anlagen/${o.id}">Details & Dokumentation</a>
            ${o.darf ? `<a class="btn btn-sm" href="/anlagen/${o.id}/bearbeiten">Bearbeiten</a>` : ''}
            ${o.darf_statistik && o.messgeraete.length ? `<a class="btn btn-sm" href="/statistik?anlage=${o.id}">Statistik</a>` : ''}
            ${o.darf ? `<button type="button" class="btn btn-sm btn-ghost" data-aus-plan="${o.id}">Aus dem Plan nehmen</button>` : ''}
        </div>`;
    panelLiveSetzen(id, o.live);
    // Auf dem Handy steht die Seitenleiste unter der Karte – hinrollen.
    if (window.matchMedia('(max-width: 1100px)').matches) {
        seite.closest('.karte-seite').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    const aus = seite.querySelector('[data-aus-plan]');
    if (aus) {
        aus.addEventListener('click', async () => {
            if (!window.confirm('„' + o.name + '“ aus dem Plan nehmen? Die Anlage bleibt erhalten.')) { return; }
            const erg = await PM.post('/karte/objekt/entfernen', { id: o.id });
            PM.melden(erg.message, !!erg.ok);
            await laden();
            seite.innerHTML = '<p class="muted">Aus dem Plan genommen.</p>';
        });
    }
}

function panelLiveSetzen(id, vorgabe) {
    const ziel = seite.querySelector('[data-seite-live]');
    const l = vorgabe || live[id];
    if (!ziel || !l) { return; }
    const phasen = ['L1', 'L2', 'L3'].filter((p) => l.i && l.i[p] !== undefined);
    const z = l.zustand || {};
    ziel.innerHTML = `
        <div class="seite-werte stufe-${esc(l.stufe)}">
            <div><span class="label">Leistung</span><strong>${PM.format(l.p, 'W')}</strong></div>
            <div><span class="label">Auslastung</span><strong>${l.auslastung !== null && l.auslastung !== undefined ? Math.round(l.auslastung) + ' %' : '–'}</strong></div>
            ${!(l.phasen && (l.phasen.L1 || l.phasen.sum)) ? phasen.map((p) => `<div><span class="label">${p}</span><strong>${PM.format(l.i[p], 'A')}</strong></div>`).join('') : ''}
            ${z.soc !== undefined ? `<div><span class="label">SOC</span><strong>${Math.round(z.soc)} %</strong></div>` : ''}
            ${z.tank_prozent !== undefined ? `<div><span class="label">Tank</span><strong>${Math.round(z.tank_prozent)} %</strong></div>` : ''}
            ${z.motorzustand !== undefined ? `<div><span class="label">Motor</span><strong>${esc(z.motorzustand)}</strong></div>` : ''}
        </div>
        ${l.hinweis ? `<div class="small muted">${esc(l.hinweis)}</div>` : ''}
        ${detailTabelle(l, true)}`;
    Object.keys(l.messpunkte || {}).forEach((mid) => {
        const m = l.messpunkte[mid];
        seite.querySelectorAll(`[data-mp-wert="${mid}"]`).forEach((w) => {
            w.textContent = m.online ? PM.format(m.p, 'W') + (m.schalter !== null ? (m.schalter ? ' · ein' : ' · aus') : '') : 'keine Werte';
        });
    });
}

// ------------------------------------------------------------------ Werte je Phase

let detail = null;            // { objekt, popup } – das offene Detailfenster im Plan

function zahl(wert, einheit, stellen = 1) {
    return wert === null || wert === undefined ? '–' : PM.format(wert, einheit, stellen);
}

/**
 * Tabelle Spannung, Strom, Leistung, cos φ je Phase – im Plan mit Einheit
 * an jeder Zahl, in der schmalen Seitenleiste kompakt (Einheit in der Zeile).
 */
function detailTabelle(l, kompakt = false) {
    const ph = l.phasen || {};
    const spalten = ['L1', 'L2', 'L3'].filter((p) => ph[p]);
    const summe = ph.sum || {};
    if (!spalten.length && !Object.keys(summe).length) { return ''; }
    const zeile = (titel, feld, einheit, stellen = 1) => {
        const werte = spalten.map((p) => ph[p][feld]);
        const gesamt = summe[feld];
        if (werte.every((w) => w === undefined) && gesamt === undefined) { return ''; }
        // Kompakt: Leistungen in k…, Zahl ohne Einheit.
        const kilo = kompakt && ['W', 'var', 'VA'].includes(einheit);
        const zeigen = (w) => (w === undefined ? '–' : (kompakt ? PM.format(kilo ? w / 1000 : w, '', kilo ? 1 : stellen) : PM.format(w, einheit, stellen)));
        const kopf = kompakt ? `${titel} <span class="muted">${kilo ? 'k' + einheit : einheit}</span>` : titel;
        return `<tr><th>${kopf}</th>${spalten.map((p) => `<td>${zeigen(ph[p][feld])}</td>`).join('')}
            <td>${gesamt !== undefined ? zeigen(gesamt) : ''}</td></tr>`;
    };
    const pf = (w) => (w === undefined ? '–' : w.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    return `<div class="pm-phasen-wrap"><table class="pm-phasen">
        <thead><tr><th></th>${spalten.map((p) => `<th>${p}</th>`).join('')}<th>${spalten.length ? 'Σ' : 'gesamt'}</th></tr></thead>
        <tbody>
            ${zeile('Spannung', 'u', 'V', 1)}
            ${zeile('Strom', 'i', 'A', 1)}
            ${zeile('Leistung', 'p', 'W', 1)}
            ${zeile('Blindl.', 'q', 'var', 1)}
            ${zeile('Scheinl.', 's', 'VA', 1)}
            ${spalten.some((p) => ph[p].pf !== undefined) || summe.pf !== undefined
                ? `<tr><th>cos φ</th>${spalten.map((p) => `<td>${pf(ph[p].pf)}</td>`).join('')}<td>${summe.pf !== undefined ? pf(summe.pf) : ''}</td></tr>` : ''}
        </tbody></table></div>
        <div class="pm-phasen-fuss small">
            ${ph.N && ph.N.i !== undefined ? `<span>N: ${zahl(ph.N.i, 'A')}</span>` : ''}
            ${l.f !== null && l.f !== undefined ? `<span>Frequenz: <strong>${l.f.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Hz</strong></span>` : ''}
            ${l.auslastung !== null && l.auslastung !== undefined ? `<span>Auslastung: <strong>${Math.round(l.auslastung)} %</strong></span>` : ''}
            ${l.alter_s !== null && l.alter_s !== undefined ? `<span class="muted">vor ${l.alter_s} s</span>` : ''}
        </div>`;
}

function detailHtml(o) {
    const l = live[o.id];
    const kopf = `<div class="pm-detail-kopf"><strong>${esc(o.name)}</strong><span class="small muted">${esc(o.art_name || '')}</span></div>`;
    if (!l) { return kopf + '<p class="small muted">Noch keine Livewerte.</p>'; }
    const tabelle = detailTabelle(l);
    return `<div class="pm-detail stufe-${esc(l.stufe)}">${kopf}
        ${tabelle || `<p class="small muted">${esc(l.hinweis || 'Keine Messwerte je Phase.')}</p>`}
        ${tabelle && l.hinweis ? `<div class="small muted">${esc(l.hinweis)}</div>` : ''}
        <div class="btn-row mt"><button type="button" class="btn btn-sm" data-detail-anlage="${o.id}">Anlage im Seitenfenster</button></div></div>`;
}

function detailZeigen(o) {
    const m = marker.get(o.id);
    if (!m) { return; }
    if (detail) { detail.popup.remove(); }
    const popup = L.popup({ maxWidth: 380, minWidth: 240, className: 'pm-detail-popup', autoPanPadding: [16, 16] })
        .setLatLng(m.getLatLng())
        .setContent(detailHtml(o))
        .openOn(map);
    detail = { objekt: o, popup };
    // Solange das Fenster offen ist, weichen die Zoomknöpfe – sonst liegen
    // sie auf dem Handy über dem Schließen-Kreuz.
    el.classList.add('detail-offen');
    popup.on('remove', () => {
        if (detail && detail.popup === popup) { detail = null; }
        el.classList.remove('detail-offen');
    });
}

el.addEventListener('click', (e) => {
    const knopf = e.target.closest('[data-detail-anlage]');
    if (knopf) { objektPanel(Number(knopf.dataset.detailAnlage)); }
});

// ------------------------------------------------------------------ Leitungen

async function leitungKlick(l) {
    if (ZEICHNEN.includes(modus)) { return; }
    if (modus === 'aufbau') { aufbauPanel('leitung', l.id); return; }
    auswahl = { art: 'leitung', id: l.id };
    if (modus === 'verschieben') { griffeZeigen(l.id); }
    const antwort = await fetch(`/karte/leitung/${l.id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const d = await antwort.json();
    if (!d.ok) { seite.innerHTML = `<p class="muted">${esc(d.message)}</p>`; return; }
    if (d.leitung.art === 'kabel') { kabelPanel(d); } else { entwurfPanel(d.leitung.art === 'kabelbruecke' ? 'bruecke' : 'kabelweg', false, d); }
}

/**
 * Griffe zum Ändern einer Linie – für feste und mobile gleichermaßen:
 *   Punkt ziehen            verschiebt ihn
 *   ⊕ zwischen zwei Punkten zieht einen neuen Punkt heraus
 *   Doppeltipp / langes Drücken auf einen Zwischenpunkt entfernt ihn
 *   ✥ in der Mitte           verschiebt die ganze Linie (angeschlossene Kabelenden bleiben an ihrer Anlage)
 *   Kabelende auf eine Anlage ziehen steckt es dort an; ins Leere gezogen wird es gelöst.
 */
function griffeZeigen(id) {
    ebenen.griffe.clearLayers();
    const l = stand.leitungen.find((x) => x.id === id);
    if (!l || !daten.darf.leitung) { return; }
    const punkte = l.punkte.map((p) => ({ x: p[0], y: p[1] }));
    const lage = linien.get(id);
    const vorschau = () => {
        const ll = punkte.map((q) => karte.zuLatLng(q.x, q.y));
        if (lage && lage.eachLayer) { lage.eachLayer((x) => { if (x.setLatLngs) { x.setLatLngs(ll); } }); } else if (lage && lage.setLatLngs) { lage.setLatLngs(ll); }
    };
    const verlaufSpeichern = async (text) => {
        const erg = await PM.post('/karte/leitung/verlauf', { id, punkte: JSON.stringify(punkte) });
        PM.melden(erg.ok ? text : erg.message, !!erg.ok);
        await laden();
    };
    const istEnde = (i) => l.art === 'kabel' && (i === 0 || i === punkte.length - 1);

    punkte.forEach((p, i) => {
        const g = karte.punkt(p.x, p.y, 'plan-kabel-punkt' + (i === 0 || i === punkte.length - 1 ? ' ende' : ''), '<span class="griff"></span>', { draggable: true });
        g.on('drag', () => { punkte[i] = karte.zuProzent(g.getLatLng()); vorschau(); });
        g.on('dragend', async () => {
            if (!istEnde(i)) { await verlaufSpeichern('Verlauf gespeichert.'); return; }
            // Kabelende: an der Anlage darunter anstecken, sonst lösen.
            const ende = i === 0 ? 'von' : 'zu';
            const ziel = anlageBei(g.getLatLng());
            if (ziel) { punkte[i] = { x: ziel.x, y: ziel.y }; }
            const bisher = l[ende] || null;
            const neu = ziel ? ziel.id : null;
            if (neu === bisher) { await verlaufSpeichern(ziel ? 'Verlauf gespeichert.' : 'Verlauf gespeichert (offenes Ende).'); return; }
            const erg = await PM.post('/karte/leitung/ende', { id, ende, objekt_id: neu || 0, punkte: JSON.stringify(punkte) });
            PM.melden(erg.message, !!erg.ok);
            await laden();
            if (erg.ok && neu) { leitungKlick(stand.leitungen.find((x) => x.id === id) || l); }
        });
        // Zwischenpunkt entfernen: Doppeltipp, Rechtsklick oder langes Drücken.
        const entfernen = async (e) => {
            if (e && e.originalEvent) { L.DomEvent.preventDefault(e.originalEvent); }
            if (i === 0 || i === punkte.length - 1 || punkte.length <= 2) { return; }
            punkte.splice(i, 1);
            await verlaufSpeichern('Punkt entfernt.');
        };
        g.on('dblclick', entfernen);
        g.on('contextmenu', entfernen);
        g.addTo(ebenen.griffe);
    });

    // ⊕ zwischen zwei Punkten: beim Ziehen entsteht dort ein neuer Punkt.
    for (let k = 0; k < punkte.length - 1; k++) {
        const a = punkte[k];
        const b = punkte[k + 1];
        const h = karte.punkt((a.x + b.x) / 2, (a.y + b.y) / 2, 'plan-kabel-punkt zwischen', '<span class="griff">+</span>', { draggable: true, title: 'Ziehen: neuer Punkt' });
        let eingefuegt = false;
        h.on('drag', () => {
            const q = karte.zuProzent(h.getLatLng());
            if (!eingefuegt) { punkte.splice(k + 1, 0, q); eingefuegt = true; } else { punkte[k + 1] = q; }
            vorschau();
        });
        h.on('dragend', () => verlaufSpeichern('Punkt eingefügt.'));
        h.addTo(ebenen.griffe);
    }

    // ✥ die ganze Linie: Kabelenden an einer Anlage bleiben dort.
    const angeschlossen = (i) => l.art === 'kabel' && ((i === 0 && l.von) || (i === punkte.length - 1 && l.zu));
    const beweglich = punkte.map((_, i) => !angeschlossen(i));
    if (beweglich.some(Boolean)) {
        const m = Math.floor((punkte.length - 1) / 2);
        const mitte = { x: (punkte[m].x + punkte[m + 1].x) / 2, y: (punkte[m].y + punkte[m + 1].y) / 2 };
        const start = punkte.map((q) => ({ ...q }));
        // Der Griff sitzt per CSS ein Stück über der Linie, neben dem ⊕.
        const h = karte.punkt(mitte.x, mitte.y, 'plan-kabel-punkt ganz', '<span class="griff">✥</span>', { draggable: true, title: 'Ganze Linie verschieben' });
        h.on('drag', () => {
            const q = karte.zuProzent(h.getLatLng());
            const dx = q.x - mitte.x;
            const dy = q.y - mitte.y;
            start.forEach((s0, i) => {
                if (beweglich[i]) { punkte[i] = { x: Math.max(0, Math.min(100, s0.x + dx)), y: Math.max(0, Math.min(100, s0.y + dy)) }; }
            });
            vorschau();
        });
        h.on('dragend', () => verlaufSpeichern(l.art === 'kabel' ? 'Kabel verschoben.' : (l.art === 'kabelbruecke' ? 'Kabelbrücke verschoben.' : 'Kabelweg verschoben.')));
        h.addTo(ebenen.griffe);
    }
    status(l.art === 'kabel'
        ? 'Punkte ziehen · ⊕ zieht einen neuen Punkt heraus · Doppeltipp entfernt einen Punkt · ✥ verschiebt das Kabel · ein Ende auf eine Anlage ziehen steckt es dort an.'
        : 'Punkte ziehen · ⊕ zieht einen neuen Punkt heraus · Doppeltipp entfernt einen Punkt · ✥ verschiebt die ganze Linie.');
}

/** Die Anlage unter einem Punkt der Karte (bis gut 20 Pixel daneben) – oder null. */
function anlageBei(latlng) {
    const ziel = map.latLngToContainerPoint(latlng);
    let beste = null;
    let abstand = 22;
    stand.objekte.forEach((o) => {
        const m = marker.get(o.id);
        if (!m || !map.hasLayer(m)) { return; }
        const d = map.latLngToContainerPoint(m.getLatLng()).distanceTo(ziel);
        if (d < abstand) { abstand = d; beste = o; }
    });
    return beste;
}

async function anschluesseVon(objektId, richtung) {
    if (!objektId) { return []; }
    const antwort = await fetch(`/karte/objekt/${objektId}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const o = await antwort.json();
    return o.ok ? o.anschluesse.filter((a) => a.richtung === richtung).map((a) => ({ id: a.id, text: `${a.bezeichnung} · ${a.typ}${a.belegt ? ' (belegt)' : ''}`, frei: !a.belegt })) : [];
}

/** Formular für ein Kabel – neu (aus dem Entwurf) oder vorhanden. */
async function kabelPanel(d) {
    const neu = d === null;
    const l = neu ? {
        id: 0, name: '', kabeltyp: 'H07RN-F', adern: 5, querschnitt: null, laenge_m: null, notizen: '',
        von: entwurf.von, zu: entwurf.zu || null, von_anschluss_id: null, zu_anschluss_id: null,
        von_name: (objektNachId(entwurf.von) || {}).name, zu_name: entwurf.zu ? (objektNachId(entwurf.zu) || {}).name : null, hinweise: [],
    } : d.leitung;
    const darf = neu || d.darf;
    const vonListe = neu ? await anschluesseVon(l.von, 'abgang') : d.enden.von;
    const zuListe = neu ? await anschluesseVon(l.zu, 'eingang') : d.enden.zu;
    const planLaenge = neu ? laengeImPlan(entwurf.punkte) : l.laenge_plan;
    const optionen = (liste, gewaehlt) => '<option value="0">– ohne Anschluss –</option>' + liste.map((a) =>
        `<option value="${a.id}"${Number(gewaehlt) === a.id ? ' selected' : ''}${!a.frei && Number(gewaehlt) !== a.id ? ' disabled' : ''}>${esc(a.text)}</option>`).join('');
    // Vorschlag bei einem neuen Kabel: der erste freie Anschluss. Ein
    // vorhandenes Kabel ohne Anschluss bleibt so – sonst steckte Speichern
    // ein Erdkabel unbemerkt an die nächste freie Dose.
    const vonWahl = l.von_anschluss_id || (neu ? (vonListe.find((a) => a.frei) || {}).id : 0);
    const zuWahl = l.zu_anschluss_id || (neu ? (zuListe.find((a) => a.frei) || {}).id : 0);
    // Fest verlegt geht nur zwischen Feststromanschlüssen; neu ist ein Kabel
    // zwischen zwei Feststromanschlüssen von Haus aus fest.
    const endeFest = (id) => !id || !!(objektNachId(id) || {}).fest;
    const festMoeglich = endeFest(l.von) && endeFest(l.zu);
    const fest = neu ? (festMoeglich && !!l.von && !!l.zu) : !!l.fest;
    seite.innerHTML = `
        <h2>${neu ? 'Neues Kabel' : esc(l.name || 'Kabel')}</h2>
        <p class="small"><strong>${esc(l.von_name || '?')}</strong> → <strong>${esc(l.zu_name || 'offenes Ende')}</strong></p>
        ${(l.hinweise || []).map((h) => `<div class="notice warn small">${esc(h)}</div>`).join('')}
        <form id="kabel-form">
            <div class="field"><label>Abgang an ${esc(l.von_name || '–')}</label><select name="von_anschluss_id"${darf ? '' : ' disabled'}>${optionen(vonListe, vonWahl)}</select></div>
            <div class="field"><label>Eingang an ${esc(l.zu_name || '–')}</label><select name="zu_anschluss_id"${darf ? '' : ' disabled'}>${optionen(zuListe, zuWahl)}</select></div>
            <div class="field-row">
                <div class="field"><label>Kabeltyp</label><input name="kabeltyp" list="kabeltypen" value="${esc(l.kabeltyp)}"${darf ? '' : ' disabled'}></div>
                <div class="field"><label>Adern</label><input name="adern" type="number" min="1" max="61" value="${esc(l.adern || '')}"${darf ? '' : ' disabled'}></div>
            </div>
            <div class="field-row">
                <div class="field"><label>Querschnitt (mm²)</label><select name="querschnitt_mm2"${darf ? '' : ' disabled'}><option value="">–</option>
                    ${daten.querschnitte.map((q) => `<option value="${q}"${Number(l.querschnitt) === q ? ' selected' : ''}>${String(q).replace('.', ',')}</option>`).join('')}</select></div>
                <div class="field"><label>Länge (m)</label><input name="laenge_m" inputmode="decimal" value="${l.laenge_m !== null && l.laenge_m !== undefined ? esc(String(l.laenge_m).replace('.', ',')) : ''}" placeholder="${planLaenge !== null ? 'Plan: ' + meter(planLaenge) : 'kein Maßstab'}"${darf ? '' : ' disabled'}></div>
            </div>
            ${!neu && l.belastbarkeit ? `<p class="help">Belastbarkeit rund ${l.belastbarkeit} A (Richtwert, frei in Luft).</p>` : ''}
            <div class="field"><label>Name</label><input name="name" value="${esc(l.name)}" placeholder="z. B. HV → Bühne"${darf ? '' : ' disabled'}></div>
            <input type="hidden" name="fest" value="0">
            <div class="check"><input type="checkbox" id="kabel-fest" name="fest" value="1"${fest ? ' checked' : ''}${darf && (festMoeglich || fest) ? '' : ' disabled'}>
                <label for="kabel-fest"><strong>Fest verlegt</strong> – gehört zur Location (z. B. Erdkabel) und wird beim Location-Abgleich übertragen.
                ${festMoeglich ? '' : '<span class="muted">Nur zwischen Feststromanschlüssen möglich.</span>'}</label></div>
            <div class="field"><label>Notiz</label><input name="notizen" value="${esc(l.notizen || '')}"${darf ? '' : ' disabled'}></div>
            <datalist id="kabeltypen">${daten.kabeltypen.map((k) => `<option value="${esc(k)}">`).join('')}</datalist>
            ${darf ? `<div class="btn-row"><button class="btn btn-primary btn-sm" type="submit">Speichern</button>
                ${neu ? '<button class="btn btn-sm btn-ghost" type="button" data-verwerfen>Verwerfen</button>' : '<button class="btn btn-sm btn-danger" type="button" data-loeschen>Löschen</button>'}</div>` : ''}
        </form>`;
    const form = seite.querySelector('#kabel-form');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(form).entries());
        const anfrage = Object.assign(f, { art: 'kabel', floor_id: etage, von_objekt_id: l.von || 0, zu_objekt_id: l.zu || 0 });
        if (neu) { anfrage.punkte = JSON.stringify(entwurf.punkte); } else { anfrage.id = l.id; }
        const erg = await PM.post('/karte/leitung/speichern', anfrage);
        PM.melden(erg.message, !!erg.ok);
        if (!erg.ok) { return; }
        entwurfVerwerfen();
        await laden();
        if (neu) { setModus('kabel'); }
        seite.innerHTML = '<p class="muted">Gespeichert. Das nächste Kabel an der speisenden Anlage beginnen – oder ein Werkzeug wählen.</p>';
    });
    const verwerfen = form.querySelector('[data-verwerfen]');
    if (verwerfen) { verwerfen.addEventListener('click', () => { entwurfVerwerfen(); seite.innerHTML = '<p class="muted">Verworfen.</p>'; }); }
    const loeschen = form.querySelector('[data-loeschen]');
    if (loeschen) { loeschen.addEventListener('click', () => leitungLoeschen(l.id)); }
}

/** Formular für Kabelweg oder Kabelbrücke. */
function entwurfPanel(art, fertig = false, d = null) {
    const neu = d === null;
    const l = neu ? { id: 0, name: '', modell: art === 'bruecke' ? (daten.bruecken[4] || '') : '', anzahl: 1, notizen: '' } : d.leitung;
    const darf = neu ? daten.darf.leitung : d.darf;
    const titel = art === 'bruecke' ? 'Kabelbrücke' : 'Kabelweg';
    if (neu && art === 'kabelweg' && !fertig) {
        seite.innerHTML = `<h2>Kabelweg zeichnen</h2><p class="small muted">Punkte in den Plan tippen. Mit „Fertig“ oder Doppeltipp abschließen.</p>
            <button type="button" class="btn btn-primary btn-sm" data-fertig>Fertig</button>`;
        seite.querySelector('[data-fertig]').addEventListener('click', () => {
            if (entwurf && entwurf.punkte.length >= 2) { entwurfPanel('kabelweg', true); } else { status('Mindestens zwei Punkte setzen.'); }
        });
        return;
    }
    const laenge = neu ? laengeImPlan(entwurf.punkte) : l.laenge_plan;
    seite.innerHTML = `
        <h2>${neu ? 'Neue ' + titel : esc(l.name || titel)}</h2>
        ${laenge !== null && laenge !== undefined ? `<p class="small muted">Länge im Plan: ${meter(laenge)}</p>` : ''}
        <form id="linie-form">
            ${art === 'bruecke' ? `
            <div class="field-row">
                <div class="field"><label>Modell</label><input name="modell" list="bruecken" value="${esc(l.modell)}"${darf ? '' : ' disabled'}></div>
                <div class="field"><label>Anzahl Elemente</label><input name="anzahl" type="number" min="1" value="${esc(l.anzahl)}"${darf ? '' : ' disabled'}></div>
            </div>
            <datalist id="bruecken">${daten.bruecken.map((b) => `<option value="${esc(b)}">`).join('')}</datalist>` : ''}
            <div class="field"><label>Name</label><input name="name" value="${esc(l.name)}" placeholder="${art === 'bruecke' ? 'z. B. Überfahrt Hauptweg' : 'z. B. Trasse Ost'}"${darf ? '' : ' disabled'}></div>
            ${art === 'bruecke' ? '' : `<input type="hidden" name="fest" value="0">
            <div class="check"><input type="checkbox" id="weg-fest" name="fest" value="1"${l.fest ? ' checked' : ''}${darf ? '' : ' disabled'}>
                <label for="weg-fest"><strong>Fest verlegt</strong> – Trasse der Location (Kabelkanal, Leerrohr), wird beim Location-Abgleich übertragen.</label></div>`}
            <div class="field"><label>Notiz</label><input name="notizen" value="${esc(l.notizen || '')}"${darf ? '' : ' disabled'}></div>
            ${darf ? `<div class="btn-row"><button class="btn btn-primary btn-sm" type="submit">Speichern</button>
                ${neu ? '<button class="btn btn-sm btn-ghost" type="button" data-verwerfen>Verwerfen</button>' : '<button class="btn btn-sm btn-danger" type="button" data-loeschen>Löschen</button>'}</div>` : ''}
        </form>`;
    const form = seite.querySelector('#linie-form');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(form).entries());
        const anfrage = Object.assign(f, { art: art === 'bruecke' ? 'kabelbruecke' : 'kabelweg', floor_id: etage });
        if (neu) { anfrage.punkte = JSON.stringify(entwurf.punkte); } else { anfrage.id = l.id; }
        const erg = await PM.post('/karte/leitung/speichern', anfrage);
        PM.melden(erg.message, !!erg.ok);
        if (!erg.ok) { return; }
        entwurfVerwerfen();
        await laden();
        seite.innerHTML = '<p class="muted">Gespeichert.</p>';
    });
    const verwerfen = form.querySelector('[data-verwerfen]');
    if (verwerfen) { verwerfen.addEventListener('click', () => { entwurfVerwerfen(); seite.innerHTML = '<p class="muted">Verworfen.</p>'; }); }
    const loeschen = form.querySelector('[data-loeschen]');
    if (loeschen) { loeschen.addEventListener('click', () => leitungLoeschen(l.id)); }
}

async function leitungLoeschen(id) {
    if (!window.confirm('Diese Linie wirklich löschen?')) { return; }
    const erg = await PM.post('/karte/leitung/loeschen', { id });
    PM.melden(erg.message, !!erg.ok);
    ebenen.griffe.clearLayers();
    await laden();
    seite.innerHTML = '<p class="muted">Gelöscht.</p>';
}

function massstabPanel() {
    seite.innerHTML = `
        <h2>Maßstab setzen</h2>
        <p class="small muted">Wie weit liegen die beiden Punkte in Wirklichkeit auseinander?</p>
        <form id="massstab-form">
            <div class="field"><label for="massstab-meter">Abstand</label><input id="massstab-meter" name="meter" placeholder="z. B. 100 m" required></div>
            <div class="btn-row"><button class="btn btn-primary btn-sm" type="submit">Übernehmen</button>
            ${stand.massstab && stand.massstab.quelle !== 'georeferenz' ? '<button class="btn btn-sm btn-ghost" type="button" data-entfernen>Maßstab entfernen</button>' : ''}</div>
            ${stand.geo ? '<p class="help">Der Plan ist georeferenziert. Ein gesetzter Maßstab hat für Längen Vorrang.</p>' : ''}
        </form>`;
    const form = seite.querySelector('#massstab-form');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const [a, b] = entwurf.punkte;
        const erg = await PM.post('/karte/massstab', {
            floor_id: etage, ax: a.x, ay: a.y, bx: b.x, by: b.y,
            meter: form.meter.value, verhaeltnis: karte.quelle.hoehe / karte.quelle.breite,
        });
        PM.melden(erg.message, !!erg.ok);
        if (erg.ok) { entwurfVerwerfen(); await laden(); setModus('ansehen'); }
    });
    const entfernen = form.querySelector('[data-entfernen]');
    if (entfernen) {
        entfernen.addEventListener('click', async () => {
            const erg = await PM.post('/karte/massstab', { floor_id: etage, entfernen: 1 });
            PM.melden(erg.message, !!erg.ok);
            entwurfVerwerfen();
            await laden();
        });
    }
}

function geoPanel() {
    const g = stand.geo;
    const punkte = entwurf && entwurf.art === 'geo' ? entwurf.punkte : [];
    const vorhanden = g ? `
        <div class="notice small">Georeferenziert: ${meter(g.meter)} zwischen den Passpunkten
            (${koordinate(g.a.lat)}, ${koordinate(g.a.lon)} und ${koordinate(g.b.lat)}, ${koordinate(g.b.lon)}).
            Zum Ändern zwei neue Punkte tippen.</div>` : '';
    if (punkte.length < 2) {
        seite.innerHTML = `<h2>Georeferenz</h2>${vorhanden}
            <p class="small">${punkte.length === 0 ? 'Punkt 1 im Plan antippen.' : 'Jetzt Punkt 2 antippen – möglichst weit weg von Punkt 1.'}</p>
            <p class="help">Gut sind Punkte, die man im Plan und in der Wirklichkeit eindeutig findet: Gebäudeecken, Tore, Masten,
                Einmündungen. Die Koordinaten kommen aus OpenStreetMap oder Google Maps (Rechtsklick auf die Stelle) – oder vor Ort
                mit „Meine Position“.</p>
            ${g && daten.darf.massstab ? '<div class="btn-row"><button class="btn btn-sm btn-ghost" type="button" data-geo-entfernen>Georeferenz entfernen</button></div>' : ''}`;
        entfernenKnopf();
        return;
    }
    const feld = (k, nr) => `
        <div class="field"><label for="geo-${k}">Punkt ${nr} – Breite, Länge</label>
            <input id="geo-${k}" name="${k}_geo" placeholder="51.23456, 7.12345" autocomplete="off" required>
            <div class="btn-row"><button class="btn btn-sm" type="button" data-gps="${k}">Meine Position</button>
                <span class="small muted" data-gps-info="${k}"></span></div></div>`;
    seite.innerHTML = `<h2>Georeferenz</h2>
        <form id="geo-form">
            ${feld('a', 1)}${feld('b', 2)}
            <p class="help">Auch „51,23456; 7,12345“, Grad/Minuten/Sekunden oder ein Link aus OpenStreetMap bzw. Google Maps.</p>
            <div class="btn-row"><button class="btn btn-primary btn-sm" type="submit">Übernehmen</button>
                ${g ? '<button class="btn btn-sm btn-ghost" type="button" data-geo-entfernen>Georeferenz entfernen</button>' : ''}</div>
        </form>`;
    const form = seite.querySelector('#geo-form');
    seite.querySelectorAll('[data-gps]').forEach((knopf) => knopf.addEventListener('click', () => {
        const k = knopf.dataset.gps;
        const info = seite.querySelector(`[data-gps-info="${k}"]`);
        if (!navigator.geolocation) { info.textContent = 'Kein GPS im Browser (nur über HTTPS verfügbar).'; return; }
        info.textContent = 'Position wird bestimmt …';
        navigator.geolocation.getCurrentPosition((pos) => {
            form[k + '_geo'].value = koordinate(pos.coords.latitude) + ', ' + koordinate(pos.coords.longitude);
            info.textContent = '± ' + Math.round(pos.coords.accuracy) + ' m';
        }, (fehler) => {
            info.textContent = fehler.code === 1 ? 'Standortfreigabe verweigert.' : 'Keine Position bekommen.';
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    }));
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const [a, b] = entwurf.punkte;
        const erg = await PM.post('/karte/georeferenz', {
            floor_id: etage, ax: a.x, ay: a.y, bx: b.x, by: b.y, a_geo: form.a_geo.value, b_geo: form.b_geo.value,
            verhaeltnis: karte.quelle.hoehe / karte.quelle.breite,
        });
        PM.melden(erg.message, !!erg.ok);
        if (erg.ok) { entwurfVerwerfen(); await laden(); setModus('ansehen'); positionKnopf(); }
    });
    entfernenKnopf();

    function entfernenKnopf() {
        const knopf = seite.querySelector('[data-geo-entfernen]');
        if (!knopf) { return; }
        knopf.addEventListener('click', async () => {
            if (!window.confirm('Georeferenz dieser Etage entfernen?')) { return; }
            const erg = await PM.post('/karte/georeferenz', { floor_id: etage, entfernen: 1 });
            PM.melden(erg.message, !!erg.ok);
            entwurfVerwerfen();
            await laden();
            geoPanel();
        });
    }
}

// ------------------------------------------------------------------ Aufbau

const AUFBAU_TEXT = { offen: 'offen', bestaetigt: 'wie geplant', geaendert: 'geändert', entfallen: 'entfallen', zusaetzlich: 'zusätzlich' };
const AUFBAU_KLASSE = { offen: '', bestaetigt: 'ok', geaendert: 'warn', entfallen: 'bad', zusaetzlich: 'info' };

function aufbauText() {
    const a = stand.aufbau;
    if (!a) { return 'Auf diesem Standort läuft kein Aufbau.'; }
    const z = a.zaehler;
    const gesamt = Object.values(z).reduce((x, y) => x + y, 0) - z.zusaetzlich;
    return `Aufbau „${a.name}“: ${gesamt - z.offen} von ${gesamt} erledigt – Anlage oder Kabel antippen und bestätigen.`;
}

function aufbauUebersicht() {
    const a = stand.aufbau;
    if (!a) { seite.innerHTML = '<h2>Aufbau</h2><p class="small muted">Auf diesem Standort läuft kein Aufbau. Gestartet wird unter „Aufbau &amp; Abgleich“.</p>'; return; }
    const z = a.zaehler;
    seite.innerHTML = `<h2>Aufbau: ${esc(a.name)}</h2>
        <p class="small">Jede Anlage und jedes Kabel antippen: <strong>wie geplant</strong>, <strong>geändert</strong> (was anders ist, beim Kabel die echte
            Länge) oder <strong>entfällt</strong>. Grau gestrichelt ist noch offen.</p>
        <ul class="liste-schlicht small aufbau-zaehler">
            ${Object.keys(AUFBAU_TEXT).map((k) => `<li><span class="badge ${AUFBAU_KLASSE[k]}">${AUFBAU_TEXT[k]}</span> ${z[k]}</li>`).join('')}
        </ul>
        <div class="btn-row mt"><a class="btn btn-sm" href="/aufbau/${a.id}">Liste</a><a class="btn btn-sm" href="/aufbau/${a.id}/abgleich">Abgleich</a></div>`;
}

async function aufbauPanel(art, id) {
    const a = stand.aufbau;
    if (!a) { aufbauUebersicht(); return; }
    const schluessel = (art === 'objekt' ? 'o:' : 'l:') + id;
    const st = aufbauStand(schluessel);
    const o = art === 'objekt' ? objektNachId(id) : null;
    const l = art === 'leitung' ? stand.leitungen.find((x) => x.id === id) : null;
    if (!st) {
        seite.innerHTML = `<h2>${esc(o ? o.name : (l && l.name) || 'Kabel')}</h2><p class="small muted">Gehört nicht zum Aufbau (fest installiert oder Kabelweg).</p>`;
        return;
    }
    const name = o ? o.name : (l.name || (l.art === 'kabelbruecke' ? 'Kabelbrücke' : `${l.von_name || '?'} → ${l.zu_name || 'offenes Ende'}`));
    const notiz = (a.notizen && a.notizen[schluessel]) || '';
    const laengeIst = a.laengen && a.laengen[schluessel] !== undefined ? a.laengen[schluessel] : null;
    const darf = art === 'objekt' ? !!(o && o.darf) : !!daten.darf.leitung;
    seite.innerHTML = `
        <h2>${esc(name)}</h2>
        <p class="small">${art === 'objekt' ? esc(o.art_name) : (l.art === 'kabelbruecke' ? 'Kabelbrücke' : 'Kabel' + (l.laenge !== null ? ' · Plan ' + meter(l.laenge) : ''))}
            · <span class="badge ${AUFBAU_KLASSE[st]}">${AUFBAU_TEXT[st]}</span></p>
        ${notiz ? `<p class="small muted">${esc(notiz)}</p>` : ''}
        ${st === 'zusaetzlich' ? '<p class="small muted">Beim Aufbau dazugekommen – nicht im Plan.</p>' : darf ? `
        <div class="aufbau-wahl">
            <button type="button" class="btn btn-primary${st === 'bestaetigt' ? ' aktiv' : ''}" data-aufbau="bestaetigt" aria-pressed="${st === 'bestaetigt'}">✓ Wie geplant</button>
            <button type="button" class="btn${st === 'geaendert' ? ' aktiv' : ''}" data-aufbau-aendern aria-pressed="${st === 'geaendert'}">Geändert …</button>
            <button type="button" class="btn btn-ghost${st === 'entfallen' ? ' aktiv' : ''}" data-aufbau="entfallen" aria-pressed="${st === 'entfallen'}">Entfällt</button>
            ${st !== 'offen' ? '<button type="button" class="btn btn-ghost btn-sm" data-aufbau="offen">↺ zurücknehmen</button>' : ''}
        </div>
        <form class="aufbau-aenderung" hidden>
            ${art === 'leitung' && l.art === 'kabel' ? `<div class="field"><label for="aufbau-laenge">Echte Länge (m)</label>
                <input id="aufbau-laenge" name="laenge_ist" inputmode="decimal" value="${laengeIst !== null ? String(laengeIst).replace('.', ',') : ''}" placeholder="${l.laenge !== null ? 'Plan: ' + meter(l.laenge) : ''}"></div>` : ''}
            <div class="field"><label for="aufbau-notiz">Was ist anders?</label>
                <input id="aufbau-notiz" name="notiz" value="${esc(notiz)}" placeholder="${art === 'objekt' ? 'z. B. 10 m weiter links, PD 63 statt PD 32' : 'z. B. um die Bühne herum'}"></div>
            <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
            ${art === 'objekt' ? '<p class="help">Steht die Anlage woanders, im Modus „Verschieben“ an die echte Stelle ziehen – der Abgleich misst den Versatz.</p>' : ''}
        </form>
        ${art === 'objekt' && daten.darf.inventar ? '<div data-aufbau-inventar class="mt"></div>' : ''}` : '<p class="small muted">Das darfst du nicht bestätigen.</p>'}`;
    const senden = async (neuerStand, extra = {}) => {
        const erg = await PM.post(`/aufbau/${a.id}/posten`, { art, ref_id: id, status: neuerStand, ...extra });
        PM.melden(erg.message, !!erg.ok);
        if (erg.ok && erg.aufbau) {
            stand.aufbau = erg.aufbau;
            zeichnen();
            status(aufbauText());
            aufbauPanel(art, id);
        }
    };
    seite.querySelectorAll('[data-aufbau]').forEach((b) => b.addEventListener('click', () => senden(b.dataset.aufbau)));
    const aendern = seite.querySelector('[data-aufbau-aendern]');
    const form = seite.querySelector('.aufbau-aenderung');
    if (aendern && form) {
        aendern.addEventListener('click', () => { form.hidden = false; (form.querySelector('input') || form).focus(); });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            senden('geaendert', { notiz: form.notiz.value, laenge_ist: form.laenge_ist ? form.laenge_ist.value : '' });
        });
    }
    // Welcher Verteiler aus dem Inventar steht hier?
    const inv = seite.querySelector('[data-aufbau-inventar]');
    if (inv) {
        const antwort = await fetch(`/karte/objekt/${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await antwort.json();
        if (!d.ok) { return; }
        const drin = (d.inventar || []).map((i) => `<strong>${esc(i.kennung)}</strong>${i.anschluss ? ' (' + esc(i.anschluss) + ')' : ''}`).join(', ');
        const frei = d.inventar_frei || [];
        inv.innerHTML = `<h3>Aus dem Inventar</h3>
            <p class="small">${drin ? 'Steckt hier: ' + drin : 'Noch nichts aus dem Inventar.'}</p>
            ${frei.length ? `<div class="field"><label for="aufbau-stueck">Welches Stück steht hier?</label>
                <select id="aufbau-stueck"><option value="">– wählen –</option>${frei.map((i) => `<option value="${i.id}" data-art="${esc(i.art)}">${esc(i.kennung)} – ${esc(i.typ)}${i.passend ? ' ✓' : ''}${i.woanders ? ' (jetzt: ' + esc(i.woanders) + ')' : ''}</option>`).join('')}</select></div>
                <div class="field" data-stecker-ort hidden><label for="aufbau-ort">Zwischenstecker steckt an</label>
                <select id="aufbau-ort"><option value="0">der Einspeisung</option>${d.anschluesse.filter((x) => x.richtung === 'abgang').map((x) => `<option value="${x.id}">Abgang ${esc(x.bezeichnung)} · ${esc(x.typ)}</option>`).join('')}</select></div>
                <button type="button" class="btn btn-sm" data-einsetzen>Einsetzen</button>` : '<p class="small muted">Nichts Freies im Inventar.</p>'}`;
        const wahl = inv.querySelector('#aufbau-stueck');
        const ort = inv.querySelector('[data-stecker-ort]');
        if (wahl) {
            wahl.addEventListener('change', () => {
                const opt = wahl.selectedOptions[0];
                ort.hidden = !(opt && opt.dataset.art === 'zwischenstecker');
            });
            inv.querySelector('[data-einsetzen]').addEventListener('click', async () => {
                if (!wahl.value) { return; }
                const erg = await PM.post(`/anlagen/${id}/einsetzen`, { inventar_id: wahl.value, anschluss_id: ort.hidden ? 0 : inv.querySelector('#aufbau-ort').value, typ_uebernehmen: 1 });
                PM.melden(erg.message, !!erg.ok);
                if (erg.ok) { await laden(); aufbauPanel(art, id); }
            });
        }
    }
}

// Die eigene GPS-Position im Plan – beim Aufbau: Wo stehe ich, wo muss der Verteiler hin?
let positionWache = null;
let positionErste = true;

function positionZeigen(pos) {
    ebenen.position.clearLayers();
    if (!stand.geo) { status('Diese Etage ist nicht georeferenziert.'); return; }
    const p = geoZuPlan(stand.geo, pos.coords.latitude, pos.coords.longitude);
    const genau = Math.round(pos.coords.accuracy);
    // Genauigkeit als Kreis (in Breiten-Einheiten, y mit dem Seitenverhältnis).
    const r = pos.coords.accuracy / p.meterJeEinheit;
    const kreis = [];
    for (let i = 0; i < 36; i++) {
        const w = i / 36 * 2 * Math.PI;
        kreis.push(karte.zuLatLng(p.x + r * Math.cos(w), p.y + r * Math.sin(w) / stand.geo.verhaeltnis));
    }
    L.polygon(kreis, { color: '#2563eb', weight: 1, fillColor: '#3b82f6', fillOpacity: 0.12, interactive: false }).addTo(ebenen.position);
    karte.punkt(p.x, p.y, 'pm-position', '<span class="pm-position-punkt"></span>', { interactive: false }).addTo(ebenen.position);
    const draussen = p.x < 0 || p.x > 100 || p.y < 0 || p.y > 100;
    status('Deine Position ± ' + genau + ' m' + (draussen ? ' – außerhalb des Plans.' : '.'));
    if (positionErste && !draussen) { map.setView(karte.zuLatLng(p.x, p.y), Math.max(map.getZoom(), karte.quelle.maxZoom - 1)); }
    positionErste = false;
}

function positionKnopf() {
    const knopf = document.querySelector('[data-aktion="position"]');
    if (!knopf || knopf.dataset.bereit) { return; }
    knopf.dataset.bereit = '1';
    knopf.addEventListener('click', () => {
        if (positionWache !== null) {
            navigator.geolocation.clearWatch(positionWache);
            positionWache = null;
            ebenen.position.clearLayers();
            knopf.classList.remove('aktiv');
            knopf.setAttribute('aria-pressed', 'false');
            status('');
            return;
        }
        if (!navigator.geolocation) { status('Kein GPS im Browser (nur über HTTPS verfügbar).'); return; }
        status('Position wird bestimmt …');
        positionErste = true;
        knopf.classList.add('aktiv');
        knopf.setAttribute('aria-pressed', 'true');
        positionWache = navigator.geolocation.watchPosition(positionZeigen, (fehler) => {
            status(fehler.code === 1 ? 'Standortfreigabe verweigert.' : 'Keine Position bekommen.');
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
    });
}

// ------------------------------------------------------------------ Start

await laden();
positionKnopf();
await liveLaden();
setInterval(liveLaden, 5000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) { liveLaden(); } });
PM.liveNeu = liveLaden;

if (el.dataset.startModus === 'aufbau' && stand.aufbau && document.querySelector('[data-modus="aufbau"]')) { setModus('aufbau'); }
const fokus = Number(el.dataset.fokus || 0);
if (fokus && marker.has(fokus)) {
    map.setView(marker.get(fokus).getLatLng(), Math.max(map.getZoom(), karte.quelle.maxZoom - 1));
    objektPanel(fokus);
}
if (modus !== 'aufbau') { status(''); }
