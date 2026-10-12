/*
 * MQTT-Zugänge merken
 *
 * Die Oberfläche veröffentlicht auf pm/status/mqtt-zugaenge (retained,
 * QoS 1), welche Shellys bestätigt über ihren eigenen MQTT-Zugang
 * verbunden sind:
 *   { "ts": 1727200000000,
 *     "geschuetzt": { "kuehlhaus": "shelly/kuehlhaus",
 *                     "shelly1-b929cc": "shellies/shelly1-B929CC" } }
 * Schlüssel ist der Gerätename, wie ihn die Shelly-Funktionen bilden
 * (letztes Segment des Präfixes, klein), Wert das genaue Präfix des Geräts.
 * Ein leeres Objekt heißt: kein Gerät ist geschützt.
 *
 * Wozu: Den Baum <präfix>/# eines solchen Geräts sperrt der Broker für den
 * gemeinsamen Zugang „shelly". Den Gerätenamen leiten die Shelly-Funktionen
 * aber verlustbehaftet aus dem Thema ab – kuehlhaus/status/… ergibt
 * denselben Namen wie shelly/kuehlhaus/status/…, shellies/SHELLY1-b929cc
 * denselben wie shellies/shelly1-B929CC. Unter einem solchen Doppelgänger
 * könnte der gemeinsame Zugang Messwerte und Geräteangaben für das
 * geschützte Gerät einspeisen. „Shelly Gen2+ übersetzen", „Shelly Gen2+
 * Geräteinfo" und „Shelly Gen1 übersetzen" verwerfen deshalb alles, was für
 * ein Gerät dieser Liste unter einem anderen Präfix ankommt. Unter pm/#
 * darf der gemeinsame Zugang nicht schreiben – die Liste selbst kann er
 * nicht fälschen.
 *
 * Die Liste liegt im globalen Kontext (mqtt_geschuetzt): Gen1 und Gen2+
 * liegen auf verschiedenen Tabs, der Flow-Kontext reichte nicht.
 *
 * Ungültiges (kein JSON, kein Objekt, „geschuetzt" fehlt oder ist kein
 * Objekt, Gerätename oder Präfix leer oder mit + # NUL, Präfix länger als
 * 200 Zeichen) wird übergangen; es gilt die letzte gültige Liste. Ein
 * Eintrag „__proto__" taugt nicht als Schlüssel und wird ausgelassen – ein
 * solches Gerät nehmen die Shelly-Funktionen ohnehin nicht an.
 *
 * Nach einem Neustart von Node-RED ist die Liste leer, bis der Broker die
 * gespeicherte Nachricht beim Abonnieren schickt.
 *
 * Quittung (einziger Ausgang → „pm/status/nodered-schutz"): Jede
 * angenommene Liste bestätigt die Funktion retained mit QoS 1:
 *   { "ts": 1727200000000, "anzahl": 3, "zeit": 1727200000123 }
 * ts ist der Zeitstempel der angenommenen Liste (null, wenn sie keinen
 * numerischen hatte), anzahl die Zahl der geschützten Geräte, zeit der
 * Zeitpunkt der Annahme (ms). Läuft eine Installation mit alten, von Hand
 * geänderten Flows ohne diese Funktion, fehlt die Quittung oder bleibt bei
 * einem älteren ts stehen – daran ist zu erkennen, dass der Schutz nicht
 * wirkt. Ungültiges wird nicht quittiert; es bleibt die letzte Quittung
 * stehen, so wie die letzte gültige Liste gilt. Auch die Quittung liegt
 * unter pm/#, der gemeinsame Zugang kann sie nicht fälschen.
 */

const SCHLUESSEL = 'mqtt_geschuetzt';
const PRAEFIX_MAX = 200;
const QUITTUNG = 'pm/status/nodered-schutz';

function hat(objekt, schluessel) {
    return Object.prototype.hasOwnProperty.call(objekt, schluessel);
}

function brauchbar(text) {
    return typeof text === 'string' && text !== '' && !/[+#\u0000]/.test(text);
}

function istObjekt(wert) {
    return wert !== null && typeof wert === 'object' && !Array.isArray(wert);
}

function uebergehen(grund) {
    const bisher = global.get(SCHLUESSEL);
    const anzahl = bisher ? Object.keys(bisher).length : 0;
    node.warn('Liste der MQTT-Zugänge übergangen (' + grund + '), es gilt die letzte gültige.');
    node.status({ fill: 'yellow', shape: 'ring', text: 'ungültig übergangen – ' + anzahl + ' geschützt' });
    return null;
}

let daten = msg.payload;
if (Buffer.isBuffer(daten)) {
    daten = daten.toString('utf8');
}
if (typeof daten === 'string') {
    try {
        daten = JSON.parse(daten);
    } catch (e) {
        return uebergehen('kein JSON');
    }
}
if (!istObjekt(daten) || !hat(daten, 'geschuetzt') || !istObjekt(daten.geschuetzt)) {
    return uebergehen('„geschuetzt" fehlt');
}

const neu = {};
for (const geraet of Object.keys(daten.geschuetzt)) {
    if (geraet === '__proto__') {
        continue;
    }
    const praefix = daten.geschuetzt[geraet];
    if (!brauchbar(geraet) || !brauchbar(praefix) || praefix.length > PRAEFIX_MAX) {
        return uebergehen('ungültiger Eintrag');
    }
    neu[geraet] = praefix;
}

global.set(SCHLUESSEL, neu);
const anzahl = Object.keys(neu).length;
node.status({ fill: 'green', shape: 'dot', text: anzahl + ' geschützt' });
return {
    topic: QUITTUNG,
    retain: true,
    qos: 1,
    payload: JSON.stringify({
        ts: typeof daten.ts === 'number' && Number.isFinite(daten.ts) ? daten.ts : null,
        anzahl,
        zeit: Date.now(),
    }),
};
