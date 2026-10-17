/**
 * Tanks auf der Karte – ein Modul neben karte.js.
 *
 *   tankMarkerSetzen(knoten, l, name)   Pegel im Zeichen (--fuellstand), der
 *                                       Wert daneben, Stand mit Alter als Titel.
 *   tankPanel(seite, o, aktionen)       Seitenleiste: Füllstand, Tanksensor mit
 *                                       Ein- und Ausbau, letzte Peilungen und
 *                                       Betankungen, Tankseite.
 *   tankPanelLive(seite, l)             die Werte darin neu setzen (alle 5 s).
 *   einsetzenFormular(ziel, frei, id, aktionen)
 *                                       Tanksensor mit Einbaumaß einsetzen –
 *                                       im Panel und im Aufbau-Modus.
 *
 * Alles aus Antworten des Servers kommt per textContent ins Dokument, nie
 * als HTML. Was jemand darf, sagt der Server (o.darf, o.tank.darf); er
 * prüft es beim Schreiben ohnehin noch einmal.
 */

const PM = window.PM;

/** Ein Element mit Attributen und Kindern (Text wird zu Textknoten). */
function el(tag, attribute = {}, ...kinder) {
    const e = document.createElement(tag);
    Object.entries(attribute).forEach(([k, v]) => {
        if (v === null || v === undefined || v === false) { return; }
        if (k === 'klasse') { e.className = v; } else { e.setAttribute(k, v === true ? '' : String(v)); }
    });
    kinder.flat().forEach((k) => {
        if (k !== null && k !== undefined && k !== false && k !== '') { e.append(k instanceof Node ? k : document.createTextNode(String(k))); }
    });
    return e;
}

const da = (w) => w !== null && w !== undefined;
const ganz = (w, einheit) => (da(w) ? PM.format(w, einheit, 0) : '');
const kurz = (w, einheit) => w.toLocaleString('de-DE', { maximumFractionDigits: 3 }) + (einheit ? ' ' + einheit : '');

/** „80 % · 2.390 l“, ohne Kalibrierung „860 mm“, bei einem Fehler „Fehler“. */
export function tankWertText(t) {
    if (!t || !t.sensor) { return ''; }
    if (t.fehler) { return 'Fehler'; }
    const teile = [];
    if (da(t.prozent)) { teile.push(Math.round(t.prozent) + ' %'); }
    if (da(t.liter)) { teile.push(ganz(t.liter, 'l')); }
    if (!teile.length && da(t.hoehe_mm)) { teile.push(ganz(t.hoehe_mm, 'mm')); }
    if (!teile.length && da(t.roh)) { teile.push(kurz(t.roh, t.roh_einheit)); }
    return teile.join(' · ');
}

/** Pegel und Wert am Zeichen; die Stufe (Rand) setzt karte.js. */
export function tankMarkerSetzen(knoten, l, name) {
    const t = l && l.tank ? l.tank : null;
    const pegel = t && da(t.prozent) && !t.fehler ? Math.max(0, Math.min(100, t.prozent)) : 0;
    knoten.style.setProperty('--fuellstand', String(pegel));
    const wert = knoten.querySelector('[data-wert]');
    if (wert) {
        wert.textContent = tankWertText(t);
        wert.title = 'Antippen: Füllstand';
    }
    const text = name + (l && l.hinweis ? ' – ' + l.hinweis : '');
    knoten.title = text;
    knoten.setAttribute('aria-label', text);
}

/** Die Werte im Panel: Balken, Inhalt, Füllstand, Rohwert mit Alter, Hinweis. */
export function tankPanelLive(seite, l) {
    const ziel = seite.querySelector('[data-tank-live]');
    if (!ziel || !l) { return; }
    const t = l.tank || {};
    const zelle = (beschriftung, wert) => el('div', {}, el('span', { klasse: 'label' }, beschriftung), el('strong', {}, wert || '–'));
    const kinder = [];
    if (t.sensor) {
        const balken = el('span');
        balken.style.width = Math.max(0, Math.min(100, da(t.prozent) && !t.fehler ? t.prozent : 0)) + '%';
        kinder.push(el('div', { klasse: 'seite-werte tank-werte stufe-' + (l.stufe || 'keine') },
            el('div', { klasse: 'tank-balken tank-balken-voll', 'aria-hidden': 'true' }, balken),
            zelle('Inhalt', ganz(t.liter, 'l')),
            zelle('Füllstand', da(t.prozent) ? Math.round(t.prozent) + ' %' : ''),
            zelle('Nutzbar', ganz(t.nutzbar_l, 'l')),
            zelle('Füllhöhe', ganz(t.hoehe_mm, 'mm')),
            zelle('Rohwert', da(t.roh) ? kurz(t.roh, t.roh_einheit) + (t.stand ? ' · ' + t.stand : '') : ''),
            zelle('Gerät', t.online === true ? 'online' : (t.online === false ? 'offline' : '')),
        ));
        kinder.push(el('div', { klasse: 'small' }, 'Kennlinie: ', t.kennlinie || '–'));
    } else {
        kinder.push(el('div', { klasse: 'seite-werte tank-werte stufe-' + (l.stufe || 'keine') }, zelle('Füllstand', '')));
    }
    if (l.hinweis) { kinder.push(el('div', { klasse: 'small muted' }, l.hinweis)); }
    ziel.replaceChildren(...kinder);
}

/**
 * Tanksensor einsetzen: Sensor wählen, Einbaumaß – bei Ultraschall die Höhe
 * der Sensorfläche (Pflicht), sonst die der Sondenspitze (leer = 20 mm).
 *
 * @param aktionen { nachher: async () => {} } – nach erfolgreichem Einsetzen
 */
export function einsetzenFormular(ziel, frei, objektId, aktionen) {
    const auswahl = el('select', { id: 'tank-stueck', required: true }, el('option', { value: '' }, '– Tanksensor wählen –'),
        frei.map((i) => el('option', { value: i.id, 'data-prinzip': i.prinzip || '' },
            i.kennung + ' – ' + i.typ + (i.woanders ? ' (jetzt: ' + i.woanders + ')' : ''))));
    const offset = el('input', { id: 'tank-offset', inputmode: 'decimal', placeholder: '20' });
    const dLeer = el('input', { id: 'tank-dleer', inputmode: 'decimal', placeholder: 'z. B. 1300' });
    const offsetFeld = el('div', { klasse: 'field' }, el('label', { for: 'tank-offset' }, 'Sondenspitze über dem Boden (mm)'), offset);
    const dLeerFeld = el('div', { klasse: 'field', hidden: true }, el('label', { for: 'tank-dleer' }, 'Sensorfläche über dem Boden (mm)'), dLeer);
    const knopf = el('button', { type: 'submit', klasse: 'btn btn-sm btn-primary' }, 'Einsetzen');
    const form = el('form', { klasse: 'tank-einsetzen' },
        el('div', { klasse: 'field' }, el('label', { for: 'tank-stueck' }, 'Welcher Tanksensor?'), auswahl),
        offsetFeld, dLeerFeld, knopf,
        el('p', { klasse: 'help' }, 'Ein Sensor, der schon im Tank steckt, wird ersetzt. Danach peilen – die Einbautiefe ändert sich mit jedem Einbau.'));
    auswahl.addEventListener('change', () => {
        const opt = auswahl.selectedOptions[0];
        const ultraschall = !!opt && opt.dataset.prinzip === 'ultraschall';
        dLeerFeld.hidden = !ultraschall;
        dLeer.required = ultraschall;
        offsetFeld.hidden = ultraschall;
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!auswahl.value) { return; }
        knopf.disabled = true;
        try {
            const erg = await PM.post(`/anlagen/${objektId}/einsetzen`, {
                inventar_id: auswahl.value,
                'einbau[offset_mm]': offsetFeld.hidden ? '' : offset.value,
                'einbau[d_leer_mm]': dLeerFeld.hidden ? '' : dLeer.value,
            });
            PM.melden(erg.message || (erg.ok ? 'Eingesetzt.' : 'Nicht möglich.'), !!erg.ok);
            if (erg.ok) { await aktionen.nachher(); }
        } finally {
            knopf.disabled = false;
        }
    });
    ziel.replaceChildren(form);
}

/**
 * Die Seitenleiste eines Tanks.
 *
 * @param o        Antwort von /karte/objekt/{id} (mit o.tank, o.live, o.inventar_frei)
 * @param aktionen { art: {name, kuerzel, farbe}, neuLaden: async () => {}, ausPlan: async () => {}, koordinate: (x) => '' }
 */
export function tankPanel(seite, o, aktionen) {
    const tank = o.tank || { sensor: null, ereignisse: [], darf: {} };
    const art = aktionen.art;
    const zeichen = el('span', { klasse: 'art-zeichen' }, art.kuerzel);
    zeichen.style.setProperty('--art-farbe', art.farbe);
    const kopf = el('div', { klasse: 'seite-kopf' }, zeichen,
        el('div', {}, el('h2', {}, o.name), el('div', { klasse: 'small muted' }, [o.art_name, o.kennung, o.status].filter(Boolean).join(' · '))));
    const teile = [kopf];
    if (o.modell) { teile.push(el('div', { klasse: 'small muted' }, o.modell)); }
    if (o.geo) {
        teile.push(el('div', { klasse: 'small anlage-lage' }, 'Lage ' + aktionen.koordinate(o.geo.lat) + ', ' + aktionen.koordinate(o.geo.lon) + ' · ',
            el('a', { href: o.geo.link, target: '_blank', rel: 'noopener' }, 'in der Karte öffnen')));
    }
    teile.push(el('div', { 'data-tank-live': true }));
    teile.push(el('h3', { klasse: 'mt' }, 'Inhalt'), el('p', { klasse: 'small' }, o.kurz));

    teile.push(el('h3', { klasse: 'mt' }, 'Tanksensor'));
    teile.push(el('p', { klasse: 'small' }, tank.sensor
        ? [el('strong', {}, tank.sensor.kennung), ' ', tank.sensor.kurz]
        : 'Kein Tanksensor eingebaut.'));
    const einsetzenZiel = el('div', { klasse: 'mt', hidden: true });
    const knoepfe = el('div', { klasse: 'btn-row' });
    const frei = o.inventar_frei || [];
    if (tank.darf.einsetzen && frei.length) {
        const knopf = el('button', { type: 'button', klasse: 'btn btn-sm' }, tank.sensor ? 'Anderen Tanksensor einsetzen …' : 'Tanksensor einsetzen …');
        knopf.addEventListener('click', () => {
            einsetzenFormular(einsetzenZiel, frei, o.id, { nachher: aktionen.neuLaden });
            einsetzenZiel.hidden = false;
            knopf.hidden = true;
            const erstes = einsetzenZiel.querySelector('select');
            if (erstes) { erstes.focus(); }
        });
        knoepfe.append(knopf);
    } else if (tank.darf.einsetzen && !tank.sensor) {
        teile.push(el('p', { klasse: 'small muted' }, 'Kein freier Tanksensor im Inventar.'));
    }
    if (tank.sensor && tank.sensor.einsatz_id) {
        const abbau = el('button', { type: 'button', klasse: 'btn btn-sm btn-ghost' }, 'Ausbauen');
        abbau.addEventListener('click', async () => {
            if (!window.confirm(tank.sensor.kennung + ' ausbauen? Der Sensor misst dann nicht mehr hier.')) { return; }
            const erg = await PM.post(`/einsatz/${tank.sensor.einsatz_id}/beenden`, {});
            PM.melden(erg.message || (erg.ok ? 'Ausgebaut.' : 'Nicht möglich.'), !!erg.ok);
            if (erg.ok) { await aktionen.neuLaden(); }
        });
        knoepfe.append(abbau);
    }
    if (knoepfe.childElementCount) { teile.push(knoepfe); }
    teile.push(einsetzenZiel);

    if (tank.ereignisse && tank.ereignisse.length) {
        teile.push(el('h3', { klasse: 'mt' }, 'Zuletzt'));
        teile.push(el('ul', { klasse: 'liste-schlicht small' }, tank.ereignisse.map((e) => el('li', {}, el('span', { klasse: 'muted' }, e.zeit), ' ', e.text))));
    }

    const unten = el('div', { klasse: 'btn-row mt' }, el('a', { klasse: 'btn btn-sm btn-primary', href: `/anlagen/${o.id}` }, 'Tankseite'));
    if (o.darf) {
        unten.append(el('a', { klasse: 'btn btn-sm', href: `/anlagen/${o.id}/bearbeiten` }, 'Bearbeiten'));
        const aus = el('button', { type: 'button', klasse: 'btn btn-sm btn-ghost' }, 'Aus dem Plan nehmen');
        aus.addEventListener('click', aktionen.ausPlan);
        unten.append(aus);
    }
    teile.push(unten);
    seite.replaceChildren(...teile);
    tankPanelLive(seite, o.live);
}
