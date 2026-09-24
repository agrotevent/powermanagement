/*
 * Messwert → Line Protocol
 *
 * Die einzige Stelle, an der Line Protocol entsteht. Alle Wege – der
 * PHP-Sammler über MQTT, die Shelly-Übersetzer, die Geräteliste – laufen
 * hier durch. So gibt es genau eine Stelle, die Escaping und Feldtypen
 * richtig machen muss.
 *
 * Eingang, eines von beiden:
 *   a) normalisierter Messwert
 *        msg.topic   = "pm/messwert/<geraet>/<kanal>"
 *        msg.payload = JSON-Text oder Objekt:
 *          { ts, typ, modell, phasen: { L1: {u,i,p,…}, …, sum: {…} },
 *            zustand: { soc: 87, schalter: 1, motorzustand: "Läuft" } }
 *   b) fertige Punkte (Messungen außerhalb des Messwert-Schemas, etwa
 *      die Geräteliste)
 *        msg.punkte = [{ messung, tags: {…}, felder: {…}, ts }]
 *
 * Ausgang: msg.zeilen = ["strom,geraet=…,kanal=…,phase=L1,typ=shelly u=230.1,… 1727200000000", …]
 *
 * Warum so streng mit den Typen: InfluxDB legt den Typ eines Feldes mit dem
 * ersten geschriebenen Wert fest. Kommt einmal „p=5i" (Ganzzahl) und später
 * „p=5.2" (Float), weist die Datenbank jeden Wert des anderen Typs ab – für
 * den ganzen Shard, also bis zu einer Woche lang. Deshalb gilt:
 *   – jede Zahl geht als Float hinaus. Ohne „i" am Ende ist eine Zahl in
 *     Line Protocol bereits ein Float, „1" ist also richtig.
 *   – Wahrheitswerte werden 1/0, ebenfalls als Float.
 *   – nur echte Texte werden String-Felder.
 *   – null, NaN und ±Infinity fallen weg; ein Punkt ohne Felder wird gar
 *     nicht geschrieben (InfluxDB würde die ganze Zeile ablehnen).
 */

const ZAHL_ALS_TEXT = /^\s*[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\s*$/;

// Messungsname: Komma und Leerzeichen trennen in Line Protocol die Teile
// einer Zeile und müssen deshalb maskiert werden.
function maskiereMessung(text) {
    return String(text)
        .replace(/[\r\n]+/g, ' ')
        .replace(/\\/g, '\\\\')
        .replace(/([, ])/g, '\\$1');
}

// Tag-Schlüssel, Tag-Werte und Feldschlüssel: zusätzlich das
// Gleichheitszeichen. Der Backslash selbst wird ebenfalls verdoppelt –
// sonst würde ein Wert, der auf „\" endet, das folgende Komma maskieren
// und die Zeile zerlegen. Zeilenumbrüche beenden in Line Protocol eine
// Zeile und werden zu Leerzeichen.
function maskiereSchluessel(text) {
    return String(text)
        .replace(/[\r\n]+/g, ' ')
        .replace(/\\/g, '\\\\')
        .replace(/([,= ])/g, '\\$1');
}

// „time" ist in InfluxDB kein zulässiger Schlüssel, und Schlüssel mit „_"
// vorn sind in 2.x für interne Spalten reserviert (_measurement, _field …).
function zulaessigerSchluessel(schluessel) {
    return schluessel !== '' && schluessel !== 'time' && schluessel[0] !== '_';
}

// Liefert den Feldwert als Text für Line Protocol oder null, wenn der Wert
// nicht geschrieben werden soll.
//   zahlenAusText: Zahlen, die als Text ankommen ("230.1"), werden zu Floats.
//   Das gilt nur für Messwerte (phasen) – dort ist jeder Wert eine Zahl. Im
//   Zustand sind Texte erlaubt; dort bleibt ein Text ein Text, sonst hätte
//   ein Feld je nach Inhalt mal den einen, mal den anderen Typ.
function feldwert(wert, zahlenAusText) {
    if (typeof wert === 'boolean') {
        return wert ? '1' : '0';
    }
    if (typeof wert === 'string' && zahlenAusText && ZAHL_ALS_TEXT.test(wert)) {
        wert = Number(wert);
    }
    if (typeof wert === 'number') {
        // String(1) ergibt "1" – in Line Protocol ein Float, kein Integer.
        return Number.isFinite(wert) ? String(wert) : null;
    }
    if (typeof wert === 'string') {
        return '"' + wert
            .replace(/[\r\n]+/g, ' ')
            .replace(/\\/g, '\\\\')
            .replace(/"/g, '\\"') + '"';
    }
    // null, undefined, Objekte, Listen: nicht darstellbar
    return null;
}

// Zeitstempel in Millisekunden (die Schreibanfrage läuft mit precision=ms).
// Sekunden, Mikro- und Nanosekunden werden erkannt und umgerechnet – ein
// Sammler, der sich in der Einheit vertut, landet sonst im Jahr 1970 oder
// weit in der Zukunft, und niemand findet die Werte wieder.
function zeitstempel(ts) {
    let t = ts;
    if (typeof t === 'string' && t.trim() !== '') {
        t = Number(t);
    }
    if (typeof t !== 'number' || !Number.isFinite(t) || t <= 0) {
        return Date.now();
    }
    while (t > 1e14) {
        t = t / 1000;          // µs → ms, ns → µs → ms
    }
    if (t < 1e11) {
        t = t * 1000;          // Sekunden → ms
    }
    return Math.round(t);
}

// Baut eine Zeile oder liefert null, wenn kein einziges Feld übrig bleibt.
function zeile(messung, tags, felder, ts, zahlenAusText) {
    const teile = [];
    for (const schluessel of Object.keys(felder || {})) {
        if (!zulaessigerSchluessel(schluessel)) {
            continue;
        }
        const wert = feldwert(felder[schluessel], zahlenAusText);
        if (wert !== null) {
            teile.push(maskiereSchluessel(schluessel) + '=' + wert);
        }
    }
    if (teile.length === 0) {
        return null;
    }
    // Tags ohne Wert sind in Line Protocol unzulässig und fallen weg. Die
    // Sortierung nach Schlüssel empfiehlt InfluxDB – sie spart dort Arbeit.
    const tagText = Object.keys(tags || {})
        .filter((k) => zulaessigerSchluessel(k) && tags[k] !== undefined && tags[k] !== null && String(tags[k]) !== '')
        .sort()
        .map((k) => ',' + maskiereSchluessel(k) + '=' + maskiereSchluessel(tags[k]))
        .join('');
    return maskiereMessung(messung) + tagText + ' ' + teile.join(',') + ' ' + zeitstempel(ts);
}

function istObjekt(wert) {
    return wert !== null && typeof wert === 'object' && !Array.isArray(wert);
}

// Zähler für die Statusanzeige – nur alle zwei Sekunden auffrischen, damit
// ein offener Editor nicht mit Statusmeldungen überschwemmt wird.
const zaehler = context.get('zaehler') || { zeilen: 0, ungueltig: 0, angezeigt: 0 };
context.set('zaehler', zaehler);

function status() {
    const jetzt = Date.now();
    if (jetzt - zaehler.angezeigt < 2000) {
        return;
    }
    zaehler.angezeigt = jetzt;
    node.status({
        fill: zaehler.ungueltig > 0 ? 'yellow' : 'green',
        shape: 'dot',
        text: zaehler.zeilen + ' Zeilen' + (zaehler.ungueltig > 0 ? ', ' + zaehler.ungueltig + ' ungültig' : ''),
    });
}

function ungueltig(grund) {
    zaehler.ungueltig++;
    // Nicht jede kaputte Nachricht ins Protokoll – eine Meldung je Minute
    // genügt, um den Fehler zu finden.
    const jetzt = Date.now();
    if (!zaehler.gewarnt || jetzt - zaehler.gewarnt > 60000) {
        zaehler.gewarnt = jetzt;
        node.warn('Messwert verworfen (' + grund + '): ' + String(msg.topic || '(ohne Thema)'));
    }
    status();
    return null;
}

const punkte = [];

if (Array.isArray(msg.punkte)) {
    for (const p of msg.punkte) {
        if (istObjekt(p) && p.messung) {
            punkte.push({ messung: p.messung, tags: p.tags || {}, felder: p.felder || {}, ts: p.ts, zahlen: false });
        }
    }
} else {
    // pm/messwert/<geraet>/<kanal> – das erste Segment (Präfix) wird nicht
    // geprüft, damit ein anderes Präfix nur im mqtt-in-Node geändert werden
    // muss. Ein Kanal darf weitere „/" enthalten.
    const teile = String(msg.topic || '').split('/');
    if (teile.length < 3 || teile[1] !== 'messwert' || teile[2] === '') {
        return ungueltig('Thema passt nicht zu <präfix>/messwert/<gerät>/<kanal>');
    }
    const geraet = teile[2];
    const kanal = teile.slice(3).join('/') || 'geraet';

    let daten = msg.payload;
    if (Buffer.isBuffer(daten)) {
        daten = daten.toString('utf8');
    }
    if (typeof daten === 'string') {
        try {
            daten = JSON.parse(daten);
        } catch (e) {
            return ungueltig('kein gültiges JSON');
        }
    }
    if (!istObjekt(daten)) {
        return ungueltig('Nutzlast ist kein JSON-Objekt');
    }

    const typ = daten.typ === undefined || daten.typ === null ? undefined : String(daten.typ);

    if (istObjekt(daten.phasen)) {
        for (const phase of Object.keys(daten.phasen)) {
            const werte = daten.phasen[phase];
            if (istObjekt(werte)) {
                punkte.push({ messung: 'strom', tags: { geraet, kanal, phase, typ }, felder: werte, ts: daten.ts, zahlen: true });
            }
        }
    }
    if (istObjekt(daten.zustand)) {
        punkte.push({ messung: 'zustand', tags: { geraet, kanal, typ }, felder: daten.zustand, ts: daten.ts, zahlen: false });
    }
}

const zeilen = [];
for (const p of punkte) {
    const z = zeile(p.messung, p.tags, p.felder, p.ts, p.zahlen);
    if (z !== null) {
        zeilen.push(z);
    }
}

if (zeilen.length === 0) {
    // Leere Punkte werden gar nicht erst geschrieben – das ist kein Fehler,
    // etwa wenn ein Shelly einen Status ohne Messwerte schickt.
    return null;
}

zaehler.zeilen += zeilen.length;
status();
return { zeilen };
