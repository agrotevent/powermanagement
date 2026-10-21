/*
 * Kachelstufe der Karte (public/assets/js/module/plan-karte.js) ohne Browser:
 * das echte Leaflet aus public/assets/vendor auf einem DOM von Hand, die
 * echte planKarte() mit einer Kachelpyramide vom Server. Ergebnis als JSON
 * auf der Standardausgabe.
 *
 * Geprüft wird je devicePixelRatio (1, 1,25, 2, 3) über alle Zoomstufen:
 * welche Stufe Leaflet lädt, wie stark der Browser sie skaliert, ob die
 * Kacheln ungestreckt bleiben und wo ihre Ecken auf dem Bildschirm landen
 * – gegen die Stelle, an die Leaflet einen Marker auf denselben Planpunkt
 * setzt. Dazu animiertes Zoomen über mehrere Stufen (dort reicht Leaflet
 * eine schon berechnete Stufe weiter).
 *
 * Aufruf: node tests/hilfen/plan-kacheln.cjs public/assets/vendor/leaflet/leaflet.js public/assets/js/module/plan-karte.js
 */
const fs = require('fs');
const vm = require('vm');
const [leafletDatei, karteDatei] = process.argv.slice(2);

// ------------------------------------------------------------ DOM von Hand
class Knoten {
    constructor(tag, ns) {
        this.tagName = String(tag).toUpperCase();
        this.namespaceURI = ns || 'http://www.w3.org/1999/xhtml';
        this.style = {};
        this.childNodes = [];
        this.parentNode = null;
        this.attribute = {};
        this.dataset = {};
        this.className = '';
        this.hoerer = {};
        this.clientWidth = 0;
        this.clientHeight = 0;
        if (ns === 'http://www.w3.org/2000/svg') { this.createSVGRect = () => ({}); }
    }
    get children() { return this.childNodes; }
    get firstChild() { return this.childNodes[0] || null; }
    get offsetWidth() { return this.clientWidth; }
    appendChild(k) { return this.insertBefore(k, null); }
    insertBefore(k, vor) {
        if (k.tagName === '#FRAGMENT') {
            k.childNodes.slice().forEach((c) => this.insertBefore(c, vor));
            return k;
        }
        if (k.parentNode) { k.parentNode.removeChild(k); }
        const i = vor ? this.childNodes.indexOf(vor) : -1;
        if (i < 0) { this.childNodes.push(k); } else { this.childNodes.splice(i, 0, k); }
        k.parentNode = this;
        return k;
    }
    removeChild(k) {
        const i = this.childNodes.indexOf(k);
        if (i >= 0) { this.childNodes.splice(i, 1); }
        k.parentNode = null;
        return k;
    }
    setAttribute(n, w) { this.attribute[n] = String(w); }
    getAttribute(n) { return n in this.attribute ? this.attribute[n] : null; }
    removeAttribute(n) { delete this.attribute[n]; }
    addEventListener(n, f) { (this.hoerer[n] = this.hoerer[n] || []).push(f); }
    removeEventListener(n, f) { this.hoerer[n] = (this.hoerer[n] || []).filter((g) => g !== f); }
    getBoundingClientRect() { return { left: 0, top: 0, right: this.clientWidth, bottom: this.clientHeight, width: this.clientWidth, height: this.clientHeight }; }
    getElementsByClassName(name) {
        const treffer = [];
        const suchen = (k) => k.childNodes.forEach((c) => {
            if ((' ' + c.className + ' ').includes(' ' + name + ' ')) { treffer.push(c); }
            suchen(c);
        });
        suchen(this);
        return treffer;
    }
    querySelector() { return null; }
    alle(pruefen) {
        const treffer = [];
        const suchen = (k) => k.childNodes.forEach((c) => { if (pruefen(c)) { treffer.push(c); } suchen(c); });
        suchen(this);
        return treffer;
    }
}

const documentElement = new Knoten('html');
// Leaflet sucht beim Laden die CSS-Eigenschaften im Stil des Dokuments:
// mit transform, transition und MozPerspective gilt der Browser als 3D-fähig
// (Zoomstufen als scale(), animiertes Zoomen) – wie jeder aktuelle Browser.
Object.assign(documentElement.style, { transform: '', transition: '', MozPerspective: '', userSelect: '' });
const document = {
    documentElement,
    body: new Knoten('body'),
    defaultView: { getComputedStyle: () => ({}) },
    createElement: (tag) => new Knoten(tag),
    createElementNS: (ns, tag) => new Knoten(tag, ns),
    createDocumentFragment: () => new Knoten('#fragment'),
    getElementById: () => null,
    addEventListener() {},
    removeEventListener() {},
};
const fenster = globalThis;
fenster.window = fenster;
fenster.document = document;
fenster.Element = Knoten;
fenster.devicePixelRatio = 1;
fenster.screen = { deviceXDPI: 96, logicalXDPI: 96 };
fenster.addEventListener = () => {};
// Node vor 21 kennt navigator nicht (Debian 13 bringt Node 20 mit).
if (typeof fenster.navigator === 'undefined') {
    fenster.navigator = { userAgent: 'Node.js', platform: process.platform };
}
fenster.removeEventListener = () => {};

const fehler = [];
process.on('uncaughtException', (e) => { fehler.push(String(e && e.message ? e.message : e)); });

try {
    vm.runInThisContext(fs.readFileSync(leafletDatei, 'utf8'), { filename: 'leaflet.js' });
} catch (e) {
    process.stdout.write(JSON.stringify({ abbruch: 'Leaflet lädt nicht: ' + String(e && e.message ? e.message : e), fehler }));
    process.exit(0);
}

// ------------------------------------------------------------ Prüfungen
const warten = (ms) => new Promise((fertig) => setTimeout(fertig, ms));

async function main() {
    const quelle = fs.readFileSync(karteDatei, 'utf8');
    const modul = await import('data:text/javascript;base64,' + Buffer.from(quelle).toString('base64'));
    const ergebnis = { dichten: {}, animiert: {}, fehler };

    // Ein A0-Plan wie vom Server: 16 772 × 11 924 Pixel, sieben Stufen.
    const plan = { breite: 16772, hoehe: 11924, maxZoom: 7, stand: '0123456789ab' };
    const neueKarte = async (dichte, breite, hoehe) => {
        fenster.devicePixelRatio = dichte;
        const el = new Knoten('div');
        el.clientWidth = breite;
        el.clientHeight = hoehe;
        el.dataset = {
            planArt: 'kacheln', planUrl: '/grundriss/7/datei', planBreite: String(plan.breite), planHoehe: String(plan.hoehe),
            planSeite: '1', planMaxZoom: String(plan.maxZoom), planKachelUrl: '/grundriss/7/kachel/{z}/{x}/{y}?v=' + plan.stand,
            planKachel: '256', planZusatz: '2',
        };
        const karte = await modul.planKarte(el, {});
        let lage = null;
        karte.map.eachLayer((l) => { if (l instanceof L.TileLayer) { lage = l; } });
        return { el, karte, map: karte.map, lage };
    };

    /*
     * Wo landet die linke obere Ecke einer Kachel auf dem Bildschirm? Aus
     * den Transformationen, die Leaflet gesetzt hat: Kartenebene + Stufe
     * (verschoben, skaliert) + Lage der Kachel in der Stufe.
     */
    const ecke = (map, lage, kachel) => {
        const z = kachel.coords.z;
        const stufe = lage._levels[z].el;
        const t = /translate3d\((-?[\d.e-]+)px, ?(-?[\d.e-]+)px, ?0(?:px)?\)(?: scale\(([\d.e-]+)\))?/.exec(stufe.style.transform);
        const skala = t[3] !== undefined ? Number(t[3]) : 1;
        const kartenebene = map._getMapPanePos();
        const pos = kachel.el._leaflet_pos;
        return { x: kartenebene.x + Number(t[1]) + pos.x * skala, y: kartenebene.y + Number(t[2]) + pos.y * skala, skala };
    };

    for (const [dichte, breite, hoehe] of [[1, 1440, 900], [1.25, 1200, 800], [2, 820, 1180], [3, 390, 844]]) {
        const { map, lage, karte } = await neueKarte(dichte, breite, hoehe);
        const zeilen = [];
        const min = map.getMinZoom();
        const max = map.getMaxZoom();
        let stufeFalsch = 0, vergroessert = 0, zuKlein = 0, gestreckt = 0, adresseFalsch = 0, versatz = 0, ohneKacheln = 0;
        let groessterVersatz = 0;
        for (let zoom = min; zoom <= max + 1e-9; zoom += 0.05) {
            map.setZoom(zoom, { animate: false });
            const z = map.getZoom();
            const erwartet = Math.min(plan.maxZoom, Math.max(0, Math.ceil(z + Math.log2(dichte) - 0.05)));
            const kacheln = Object.values(lage._tiles).filter((k) => k.current);
            if (lage._tileZoom !== erwartet) { stufeFalsch++; }
            if (kacheln.length === 0) { ohneKacheln++; continue; }
            // Gerätepixel je Kachelpixel: über 1 wird die Kachel vergrößert.
            const geraet = Math.pow(2, z - lage._tileZoom) * dichte;
            if (lage._tileZoom < plan.maxZoom && geraet > 1.036) { vergroessert++; }
            if (lage._tileZoom > 0 && geraet <= 0.5) { zuKlein++; }
            for (const k of kacheln) {
                if (k.el.style.width !== '256px' || k.el.style.height !== '256px') { gestreckt++; }
                if (k.el.src !== `/grundriss/7/kachel/${k.coords.z}/${k.coords.x}/${k.coords.y}?v=${plan.stand}`) { adresseFalsch++; }
                // Dieselbe Planstelle als Marker: Leaflet setzt ihn auf latLngToContainerPoint.
                const e = ecke(map, lage, k);
                const soll = map.latLngToContainerPoint(map.unproject([k.coords.x * 256, k.coords.y * 256], k.coords.z));
                const d = Math.max(Math.abs(e.x - soll.x), Math.abs(e.y - soll.y));
                groessterVersatz = Math.max(groessterVersatz, d);
                if (d > 1) { versatz++; }
            }
            zeilen.push({ zoom: +z.toFixed(2), stufe: lage._tileZoom, geraetPxJeKachelPx: +geraet.toFixed(3), kacheln: kacheln.length });
        }
        // Ein Marker auf 50 %/50 % sitzt, wo latLngToLayerPoint ihn hinsetzt.
        const punkt = karte.punkt(50, 50, 'probe', '').addTo(map);
        const lp = map.latLngToLayerPoint(karte.zuLatLng(50, 50));
        ergebnis.dichten[String(dichte)] = {
            ansichten: zeilen.length, stufeFalsch, vergroessert, zuKlein, gestreckt, adresseFalsch, versatz, ohneKacheln,
            groessterVersatz: +groessterVersatz.toFixed(3),
            markerAufPunkt: punkt._icon._leaflet_pos.x === lp.x && punkt._icon._leaflet_pos.y === lp.y,
            grund: { zIndex: map.getPane('planGrund') ? map.getPane('planGrund').style.zIndex : null,
                pfade: map.getPane('planGrund') ? map.getPane('planGrund').alle((k) => k.tagName === 'PATH').map((p) => p.getAttribute('fill')) : [] },
            stichproben: zeilen.filter((_, i) => i % 20 === 0),
        };
    }

    // Animiert über mehrere Stufen: Leaflet ruft dabei _setView aus _update
    // mit der schon berechneten Stufe auf.
    for (const dichte of [1, 2, 3]) {
        const { map, lage } = await neueKarte(dichte, 390, 844);
        const schritte = [];
        const vorher = fehler.length;
        for (const ziel of [map.getZoom() + 3, map.getZoom() - 0.5, map.getMaxZoom(), map.getMinZoom() + 0.2]) {
            map.setZoom(ziel);
            await warten(450);
            const z = map.getZoom();
            schritte.push({ ziel: +ziel.toFixed(2), zoom: +z.toFixed(2), stufe: lage._tileZoom,
                erwartet: Math.min(plan.maxZoom, Math.max(0, Math.ceil(z + Math.log2(dichte) - 0.05))),
                stufenMitKacheln: Object.keys(lage._levels).map(Number).filter((s) => lage._levels[s].el.childNodes.length > 0) });
        }
        ergebnis.animiert[String(dichte)] = { schritte, fehler: fehler.slice(vorher) };
    }

    // Die Rechnung selbst
    ergebnis.kachelStufe = typeof modul.kachelStufe === 'function'
        ? [[0, 1], [3, 1], [3.04, 1], [3.06, 1], [3, 2], [2.5, 3], [4.47, 3]].map(([z, d]) => modul.kachelStufe(z, d))
        : null;
    process.stdout.write(JSON.stringify(ergebnis));
    process.exit(0);
}

main().catch((e) => {
    const text = String(e && e.stack ? e.stack : e).replace(/data:text\/javascript;base64,[A-Za-z0-9+/=]+/g, 'plan-karte.js');
    process.stdout.write(JSON.stringify({ abbruch: text, fehler }));
    process.exit(0);
});
