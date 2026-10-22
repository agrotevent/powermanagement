/**
 * „Plan neu rechnen“ auf der Karte: das Fenster mit Angaben und Knopf, und
 * die Abfrage, wie weit es ist.
 *
 * Gerechnet wird auf dem Server im Hintergrund, in einen neuen Ordner
 * (PlanTileService) – die Karte bleibt so lange benutzbar und zeigt den
 * bisherigen Plan. Gefragt wird /grundriss/<id>/kacheln/stand, und zwar wie
 * die Livewerte (live.js, karte.js): immer erst, wenn die vorige Antwort da
 * ist, dann PAUSE später – ein träger Server bekommt so keinen Stau, hinter
 * dem das Speichern wartet. Nicht in einem verborgenen Reiter, und nur so
 * lange, wie gerechnet wird.
 *
 * Kein Text aus einer Antwort wird HTML: alles per textContent.
 */

/** Zustände, in denen noch gerechnet wird (PlanTileService::OFFEN). */
export const OFFEN = ['angefordert', 'wartet', 'laeuft'];

/** So lange nach einer Antwort kommt die nächste Frage (ms). */
export const PAUSE = 2500;

/** Länger wartet eine Frage nicht auf ihre Antwort (ms). */
export const FRIST = 15000;

/**
 * Die Abfrage des Zustands.
 *
 *   start()   fragen, bis nicht mehr gerechnet wird
 *   einmal()  einmal fragen (eine Kachel fehlte, die Livewerte nennen einen
 *             neuen Stand) – läuft dabei etwas, geht es von selbst weiter
 *   stop()    aufhören
 *
 * Antworten: jede gültige an `antwort(daten)`. 423 (Kundenmodus, auch
 * `kundenmodus: true`) → `neuLaden()` – das führt zur Kundenseite; 401 →
 * `abgemeldet()` und Schluss; 403/404 → Schluss.
 *
 * @param {object} o
 * @param {string} o.adresse
 * @param {(daten: object) => void} o.antwort
 * @param {() => void} o.neuLaden
 * @param {() => void} [o.abgemeldet]
 * @param {typeof fetch} [o.holen]
 * @param {{setTimeout: Function, clearTimeout: Function}} [o.zeit]
 * @param {Document} [o.dokument]
 * @param {number} [o.pause]
 * @param {number} [o.frist]
 */
export function standAbfrage(o) {
    const holen = o.holen || ((adresse, optionen) => fetch(adresse, optionen));
    const zeit = o.zeit || { setTimeout: (f, ms) => setTimeout(f, ms), clearTimeout: (t) => clearTimeout(t) };
    const dokument = o.dokument || document;
    const pause = o.pause ?? PAUSE;
    const frist = o.frist ?? FRIST;
    let unterwegs = false;   // eine Frage ist draußen
    let weiter = false;      // nach der Antwort noch einmal fragen
    let wecker = null;       // die nächste Frage, geplant

    async function fragen() {
        wecker = null;
        if (unterwegs || dokument.hidden) {
            // Verborgen: visibilitychange holt die Frage nach.
            return;
        }
        unterwegs = true;
        const abbruch = typeof AbortController === 'function' ? new AbortController() : null;
        const uhr = zeit.setTimeout(() => { if (abbruch) { abbruch.abort(); } }, frist);
        let status = 0;
        let daten = null;
        try {
            const antwort = await holen(o.adresse, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: abbruch ? abbruch.signal : undefined,
            });
            status = antwort.status;
            daten = await antwort.json().catch(() => null);
        } catch (fehler) {
            // Netz weg oder Frist um: beim nächsten Mal.
        } finally {
            zeit.clearTimeout(uhr);
            unterwegs = false;
        }
        if (status === 423 || (daten && daten.kundenmodus === true)) {
            weiter = false;
            o.neuLaden();
            return;
        }
        if (status === 401 || (daten && daten.anmelden === true)) {
            weiter = false;
            if (o.abgemeldet) { o.abgemeldet(); }
            return;
        }
        if (status === 403 || status === 404) {
            weiter = false;
            return;
        }
        if (daten && daten.ok === true) {
            weiter = OFFEN.includes(daten.zustand);
            o.antwort(daten);
        }
        if (weiter && wecker === null && !unterwegs) {
            wecker = zeit.setTimeout(fragen, pause);
        }
    }

    if (dokument.addEventListener) {
        dokument.addEventListener('visibilitychange', () => {
            if (!dokument.hidden && weiter && !unterwegs && wecker === null) { fragen(); }
        });
    }

    return {
        start() {
            weiter = true;
            if (!unterwegs && wecker === null) { fragen(); }
        },
        einmal() {
            if (!unterwegs && wecker === null) { fragen(); }
        },
        stop() {
            weiter = false;
            if (wecker !== null) {
                zeit.clearTimeout(wecker);
                wecker = null;
            }
        },
        /** Fragt gerade oder hat die nächste Frage geplant. */
        get laeuft() {
            return unterwegs || wecker !== null;
        },
    };
}

function knoten(tag, klasse, text) {
    const k = document.createElement(tag);
    if (klasse) { k.className = klasse; }
    if (text !== undefined && text !== null) { k.textContent = String(text); }
    return k;
}

function zahl(wert) {
    return Number(wert || 0).toLocaleString('de-DE');
}

/**
 * Das Fenster „Plan neu rechnen“ in der Seitenleiste.
 *
 * @param {HTMLElement} ziel  #seite-inhalt
 * @param {object} plan  aus karte-daten: art, breite, hoehe, fassung, fassung_aktuell, qualitaet,
 *                       qualitaeten [{wert, name, hinweis}], empfohlen, gebaut, dauer
 * @param {{zustand: string, meldung: string}} zustand
 * @param {(qualitaet: string) => Promise<void>} senden
 * @returns {{zustandSetzen: (z: {zustand: string, meldung: string}) => void, offen: () => boolean}}
 */
export function planNeuPanel(ziel, plan, zustand, senden) {
    const aufbereiten = plan.art !== 'kacheln';
    ziel.replaceChildren();
    ziel.appendChild(knoten('h2', '', aufbereiten ? 'Plan aufbereiten' : 'Plan neu rechnen'));
    ziel.appendChild(knoten('p', 'small muted', aufbereiten
        ? 'Der Server rechnet den Grundriss im Hintergrund in Kacheln. Bis dahin zeichnet der Browser den Plan selbst.'
        : 'Der Server rechnet den Grundriss im Hintergrund noch einmal in Kacheln. Die Karte bleibt so lange benutzbar '
            + 'und zeigt danach den neuen Plan – geht etwas schief, bleibt der bisherige.'));

    if (!aufbereiten) {
        const liste = knoten('dl', 'kv');
        const zeile = (name, wert) => {
            liste.appendChild(knoten('dt', '', name));
            liste.appendChild(knoten('dd', '', wert));
        };
        zeile('Jetzt', zahl(plan.breite) + ' × ' + zahl(plan.hoehe) + ' Pixel');
        zeile('Fassung', String(plan.fassung) + (plan.fassung < plan.fassung_aktuell ? ' (aktuell: ' + plan.fassung_aktuell + ')' : ''));
        const qualitaet = (plan.qualitaeten || []).find((q) => q.wert === plan.qualitaet);
        zeile('Qualität', qualitaet ? qualitaet.name : (plan.qualitaet_name || 'Standard'));
        if (plan.gebaut) { zeile('Gerechnet', plan.gebaut + (plan.dauer ? ' (' + plan.dauer + ')' : '')); }
        ziel.appendChild(liste);
    }
    if (plan.empfohlen) {
        ziel.appendChild(knoten('div', 'notice warn small mt', 'Empfohlen: Dieser Plan ist mit einer älteren Fassung gerechnet. '
            + 'Neu gerechnet wird er schärfer, und Haarlinien bleiben in der Übersicht zu sehen.'));
    }

    const formular = knoten('form', 'mt');
    if ((plan.qualitaeten || []).length > 1) {
        const feld = knoten('fieldset', 'field');
        feld.appendChild(knoten('legend', '', 'Qualität'));
        plan.qualitaeten.forEach((q) => {
            const label = knoten('label', 'check');
            const wahl = knoten('input');
            wahl.type = 'radio';
            wahl.name = 'qualitaet';
            wahl.value = q.wert;
            wahl.checked = q.wert === plan.qualitaet;
            label.appendChild(wahl);
            label.appendChild(document.createTextNode(' ' + q.name + (q.hinweis ? ' – ' + q.hinweis : '')));
            feld.appendChild(label);
        });
        formular.appendChild(feld);
    }
    const knopf = knoten('button', 'btn btn-primary btn-sm', aufbereiten ? 'Jetzt aufbereiten' : 'Neu rechnen');
    knopf.type = 'submit';
    const reihe = knoten('div', 'btn-row');
    reihe.appendChild(knopf);
    formular.appendChild(reihe);
    const meldung = knoten('p', 'small mt');
    meldung.setAttribute('role', 'status');
    formular.appendChild(meldung);
    ziel.appendChild(formular);

    const zustandSetzen = (z) => {
        const laeuft = OFFEN.includes(z && z.zustand);
        knopf.disabled = laeuft;
        knopf.textContent = laeuft ? 'Wird gerechnet …' : (aufbereiten ? 'Jetzt aufbereiten' : 'Neu rechnen');
        meldung.textContent = z && z.meldung ? z.meldung : '';
    };
    zustandSetzen(zustand);

    formular.addEventListener('submit', async (ereignis) => {
        ereignis.preventDefault();
        if (knopf.disabled) { return; }
        knopf.disabled = true;
        const gewaehlt = formular.querySelector('input[name="qualitaet"]:checked');
        try {
            await senden(gewaehlt ? gewaehlt.value : '');
        } finally {
            // Läuft es jetzt, hat zustandSetzen() den Knopf schon wieder gesperrt.
            if (knopf.textContent !== 'Wird gerechnet …') { knopf.disabled = false; }
        }
    });

    return {
        zustandSetzen,
        offen: () => ziel.contains(formular),
    };
}
