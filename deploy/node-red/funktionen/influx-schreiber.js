/*
 * InfluxDB-Schreiber
 *
 * Sammelt Zeilen und schickt sie gebündelt an den v1-kompatiblen Endpunkt
 *     <INFLUX_URL>/write?db=<INFLUX_DB>&precision=ms
 * Den verstehen InfluxDB 1.8, 2.x (über die DBRP-Zuordnung) und 3.x
 * gleichermaßen – die Flows müssen nicht wissen, welche Fassung läuft.
 *
 * Eingänge (an der Eigenschaft erkennbar):
 *   msg.zeilen      neue Zeilen von „Messwert → Line Protocol"
 *   msg.takt        jede Sekunde: fällige Zeilen abschicken
 *   msg.herzschlag  alle 30 s: eigene Zahlen schreiben und auf MQTT melden
 *   msg.pmStapel    Antwort des http-request-Nodes (oder dessen Fehler über
 *                   den Catch-Node) zu einem abgeschickten Stapel
 *
 * Ausgang 1: Anfrage an den http-request-Node
 * Ausgang 2: Status für pm/status/nodered (retained)
 *
 * Fällt die InfluxDB aus, bleiben die Zeilen bis zu 5 Minuten im Speicher
 * (höchstens 50 000, danach gehen die ältesten verloren und werden gezählt)
 * und werden mit wachsendem Abstand erneut geschickt. Ein Neustart von
 * Node-RED leert den Puffer – dafür ist er nicht gedacht; er überbrückt den
 * Neustart der Datenbank, ein Update oder eine kurze Netzstörung.
 */

const SOFORT_AB = 500;             // so viele Zeilen warten nicht auf den Takt
const STAPEL_MAX = 5000;           // Zeilen je Anfrage
const PUFFER_MAX = 50000;
const ALTER_MAX_MS = 5 * 60 * 1000;
const ANTWORT_FRIST_MS = 30 * 1000;
const WARTEN_MIN_MS = 2000;
const WARTEN_MAX_MS = 30 * 1000;

const z = context.get('schreiber') || {
    bloecke: [],          // [{ t: Eingang in ms, zeilen: [...] }] – älteste vorn
    anzahl: 0,            // Zeilen in allen Blöcken
    unterwegs: null,      // { id, bloecke, n, t }
    stapelNr: 0,
    geschrieben: 0,
    fehler: 0,
    verworfen: 0,
    warten: 0,
    naechsterVersuch: 0,
    letzterFehler: null,
    letzterErfolg: null,
    statusText: '',
};
context.set('schreiber', z);
const jetzt = Date.now();

function verwerfen(n, grund) {
    if (n <= 0) {
        return;
    }
    z.verworfen += n;
    node.warn(n + ' Zeilen verworfen: ' + grund);
}

// Zu alte und überzählige Zeilen entfernen – immer die ältesten zuerst.
function aufraeumen() {
    let zuAlt = 0;
    while (z.bloecke.length > 0 && jetzt - z.bloecke[0].t > ALTER_MAX_MS) {
        zuAlt += z.bloecke[0].zeilen.length;
        z.anzahl -= z.bloecke[0].zeilen.length;
        z.bloecke.shift();
    }
    verwerfen(zuAlt, 'älter als 5 Minuten, InfluxDB nicht erreichbar');

    let zuViel = 0;
    while (z.anzahl > PUFFER_MAX && z.bloecke.length > 0) {
        const block = z.bloecke[0];
        const weg = Math.min(block.zeilen.length, z.anzahl - PUFFER_MAX);
        block.zeilen.splice(0, weg);
        z.anzahl -= weg;
        zuViel += weg;
        if (block.zeilen.length === 0) {
            z.bloecke.shift();
        }
    }
    verwerfen(zuViel, 'Puffer voll (' + PUFFER_MAX + ' Zeilen)');
}

function adresse() {
    const basis = String(env.get('INFLUX_URL') || 'http://127.0.0.1:8086').replace(/\/+$/, '');
    let url = basis + '/write?db=' + encodeURIComponent(env.get('INFLUX_DB') || 'strom') + '&precision=ms';
    const kopf = { 'Content-Type': 'text/plain; charset=utf-8' };
    const token = env.get('INFLUX_TOKEN');
    if (token) {
        // 2.x/3.x: API-Token. 1.8 versteht hier auch „benutzer:kennwort".
        kopf.Authorization = 'Token ' + token;
    } else if (env.get('INFLUX_USER')) {
        url += '&u=' + encodeURIComponent(env.get('INFLUX_USER')) + '&p=' + encodeURIComponent(env.get('INFLUX_PASSWORD') || '');
    }
    return { url, kopf };
}

// Nächsten Stapel abschicken, wenn nichts unterwegs ist und keine Pause läuft.
function senden() {
    if (z.unterwegs) {
        if (jetzt - z.unterwegs.t < ANTWORT_FRIST_MS) {
            return null;
        }
        // Keine Antwort in der Frist – als Fehler werten, Zeilen zurücklegen.
        fehlschlag('keine Antwort nach ' + (ANTWORT_FRIST_MS / 1000) + ' s', true);
    }
    if (z.anzahl === 0 || jetzt < z.naechsterVersuch) {
        return null;
    }

    const bloecke = [];
    const zeilen = [];
    while (z.bloecke.length > 0 && zeilen.length < STAPEL_MAX) {
        const block = z.bloecke[0];
        const platz = STAPEL_MAX - zeilen.length;
        if (block.zeilen.length <= platz) {
            z.bloecke.shift();
            bloecke.push(block);
            for (const zeile of block.zeilen) {
                zeilen.push(zeile);
            }
        } else {
            const teil = { t: block.t, zeilen: block.zeilen.splice(0, platz) };
            bloecke.push(teil);
            for (const zeile of teil.zeilen) {
                zeilen.push(zeile);
            }
        }
    }
    z.anzahl -= zeilen.length;
    z.stapelNr++;
    z.unterwegs = { id: z.stapelNr, bloecke, n: zeilen.length, t: jetzt };

    // Die Methode (POST) steht im http-request-Node selbst; msg.method
    // brächte dort bei jeder Anfrage eine Warnung.
    const ziel = adresse();
    return {
        url: ziel.url,
        headers: ziel.kopf,
        payload: zeilen.join('\n'),
        requestTimeout: 15000,
        pmStapel: z.stapelNr,
    };
}

// Stapel ist nicht angekommen: Zeilen wieder vorn einreihen (mit ihrem
// ursprünglichen Eingangszeitpunkt, damit die 5-Minuten-Grenze gilt) und
// mit wachsendem Abstand erneut versuchen.
function fehlschlag(grund, zuruecklegen) {
    const u = z.unterwegs;
    z.unterwegs = null;
    z.fehler++;
    z.letzterFehler = grund;
    if (u && zuruecklegen) {
        z.bloecke = u.bloecke.concat(z.bloecke);
        z.anzahl += u.n;
        z.warten = Math.min(WARTEN_MAX_MS, Math.max(WARTEN_MIN_MS, z.warten * 2));
        z.naechsterVersuch = jetzt + z.warten;
        aufraeumen();
    } else if (u) {
        verwerfen(u.n, grund);
    }
}

function statusZeigen() {
    let text;
    let farbe;
    if (z.letzterFehler) {
        farbe = 'red';
        text = 'Fehler: ' + String(z.letzterFehler).slice(0, 60) + ' – Puffer ' + z.anzahl;
    } else {
        farbe = 'green';
        text = z.geschrieben + ' geschrieben' + (z.verworfen > 0 ? ', ' + z.verworfen + ' verworfen' : '') + ', Puffer ' + z.anzahl;
    }
    if (text !== z.statusText) {
        z.statusText = text;
        node.status({ fill: farbe, shape: farbe === 'green' ? 'dot' : 'ring', text });
    }
}

let anfrage = null;
let meldung = null;

if (msg.pmStapel !== undefined) {
    // ------------------------------------------------ Antwort der InfluxDB
    // Ein Verbindungsfehler kommt doppelt an (Ausgang und Catch) – es zählt
    // nur die erste Antwort zu dem Stapel, der gerade unterwegs ist.
    if (!z.unterwegs || msg.pmStapel !== z.unterwegs.id) {
        return null;
    }
    const code = msg.statusCode;
    const ok = !msg.error && typeof code === 'number' && code >= 200 && code < 300;
    if (ok) {
        z.geschrieben += z.unterwegs.n;
        z.unterwegs = null;
        z.letzterErfolg = jetzt;
        z.letzterFehler = null;
        z.warten = 0;
        z.naechsterVersuch = 0;
        if (z.anzahl >= SOFORT_AB) {
            anfrage = senden();
        }
    } else {
        const text = msg.error ? String(msg.error.message || msg.error) : String(msg.payload || '').slice(0, 300);
        const grund = (typeof code === 'number' ? 'HTTP ' + code : String(code || 'Verbindungsfehler')) + (text ? ': ' + text : '');
        if (code === 400 || code === 422) {
            // Die Datenbank lehnt den Inhalt ab (Syntax, Feldtyp-Konflikt).
            // Nochmals schicken ändert daran nichts und hielte nur alle
            // anderen Zeilen fünf Minuten lang auf. Bei einem Feldtyp-
            // Konflikt („partial write … dropped=1") hat InfluxDB die übrigen
            // Zeilen geschrieben – dann nur die abgelehnten als verworfen
            // zählen.
            node.warn('InfluxDB lehnt Zeilen ab – ' + grund);
            const abgelehnt = /dropped=(\d+)/.exec(text);
            if (abgelehnt && Number(abgelehnt[1]) <= z.unterwegs.n) {
                z.geschrieben += z.unterwegs.n - Number(abgelehnt[1]);
                z.unterwegs.n = Number(abgelehnt[1]);
            }
            fehlschlag(grund, false);
        } else {
            // Nicht erreichbar, Anmeldung falsch, Datenbank fehlt, 5xx …
            // – Zeilen behalten und später erneut versuchen.
            fehlschlag(grund, true);
        }
    }
    statusZeigen();
} else if (Array.isArray(msg.zeilen)) {
    // ------------------------------------------------ neue Zeilen
    if (msg.zeilen.length > 0) {
        z.bloecke.push({ t: jetzt, zeilen: msg.zeilen.slice() });
        z.anzahl += msg.zeilen.length;
        if (z.anzahl > PUFFER_MAX) {
            aufraeumen();
        }
        if (z.anzahl >= SOFORT_AB) {
            anfrage = senden();
        }
    }
} else if (msg.herzschlag) {
    // ------------------------------------------------ Herzschlag
    // Kommt über denselben Weg in die Datenbank wie alles andere – bleibt
    // er aus, stimmt etwas mit Node-RED oder dem Schreiben nicht.
    // Die Zahlen gelten für den Moment vor dieser Zeile – in Datenbank und
    // MQTT-Meldung dieselben.
    const zahlen = { geschrieben: z.geschrieben, fehler: z.fehler, puffer: z.anzahl, verworfen: z.verworfen };
    const werte = Object.keys(zahlen).map((k) => k + '=' + zahlen[k]).join(',');
    z.bloecke.push({ t: jetzt, zeilen: ['pipeline,quelle=nodered ' + werte + ' ' + jetzt] });
    z.anzahl += 1;
    aufraeumen();
    meldung = {
        topic: 'pm/status/nodered',
        retain: true,
        qos: 1,
        payload: JSON.stringify(Object.assign({ ts: jetzt }, zahlen, {
            letzter_erfolg: z.letzterErfolg,
            letzter_fehler: z.letzterFehler,
        })),
    };
} else if (msg.takt) {
    // ------------------------------------------------ Takt
    aufraeumen();
    anfrage = senden();
    statusZeigen();
}

if (!anfrage && !meldung) {
    return null;
}
return [anfrage, meldung];
