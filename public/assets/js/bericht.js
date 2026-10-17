/**
 * Übergabebericht ausfüllen – am Tablet und am Telefon, auch bei schwacher
 * Verbindung.
 *
 * Der Zustand steht in den Feldern (data-feld = Pfad im Inhalt; in einer
 * Zeile [data-zeile] bzw. einem Mangel [data-mangel] relativ). Daraus wird
 * das JSON gebaut und als ein Feld gesendet – max_input_vars schnitte bei
 * 80 Stromkreisen sonst still ab.
 *
 * Speichern: 1,5 s nach der letzten Eingabe, spätestens alle 20 s, beim
 * Verlassen der Seite mit keepalive. Was zählt, ist die Antwort des Servers,
 * nicht navigator.onLine. Jede Anfrage hat eine Zeitgrenze (PMNetz in
 * app.js); bleibt die Antwort aus, geht dieselbe Eingabe beim nächsten Mal
 * zuerst noch einmal hinaus – ist sie schon angekommen, nimmt der Server sie
 * als gespeichert (kein falscher Konflikt). Jede Eingabe steht außerdem im
 * lokalen Entwurf (localStorage, je Bericht und Benutzer), bis der Server
 * sie bestätigt hat. Wartet ein älterer Entwurf auf „Übernehmen/Verwerfen“,
 * sichert die Seite den laufenden Stand unter einem zweiten Schlüssel
 * (…:aktuell) und sagt nur dann „auf dem Gerät gesichert“, wenn das ging.
 * Abmelden räumt beide weg; danach schreibt die Seite nichts mehr zurück.
 *
 * Bewertet wird nur auf dem Server – hier wird nur angezeigt, was das
 * Autosave zurückgibt. Texte aus Antworten nur per textContent, nie als HTML.
 *
 * „Als Prüfer unterschreiben“ speichert und prüft erst; nur wenn nichts
 * fehlt, öffnet der Dialog (unterschrift.js). Danach ist der Bericht
 * eingefroren – die Seite lädt die Leseansicht.
 */
(function () {
    'use strict';

    var wurzel = document.querySelector('.bericht[data-bericht]');
    if (!wurzel) { return; }

    var berichtId = wurzel.dataset.bericht;
    var version = parseInt(wurzel.dataset.version, 10) || 0;
    var ADRESSE = '/berichte/' + berichtId;
    var LOKAL = 'pm-bericht:' + berichtId + ':' + (wurzel.dataset.benutzer || '0');
    var AKTUELL = LOKAL + ':aktuell';
    var VERZOEGERUNG = 1500;
    var ZEIT_SPEICHERN = 15000;
    var ZEIT_ANFRAGE = 30000;
    var TAKT = 20000;
    var LOKAL_TAKT = 500;
    var KEEPALIVE_HOECHSTENS = 60000;
    var VORLAGE = '__Z__';
    var OP_FOLGE = { '': '>', '>': '<', '<': '' };
    var OP_TEXT = { '': 'Messwert genau (=)', '>': 'über dem Messbereich (>)', '<': 'unter dem Messbereich (<)' };
    /** Was „Zeile kopieren“ mitnimmt – nur Beschreibendes, nie Messwerte. */
    var KOPIEREN = ['art', 'ort', 'kabel', 'leitung.art', 'leitung.leiter', 'leitung.querschnitt', 'ueb.art', 'ueb.char', 'ueb.in',
        'ueb.grenze_hand', 'rcd.bez', 'rcd.in', 'rcd.typ', 'rcd.idn', 'rcd.s', 'w.riso_u'];
    /** Eventworx-Gruppen → Felder im Bericht. */
    var EWX_GRUPPEN = [
        ['veranstaltung', 'Veranstaltung', { veranstaltung: 'kopf.veranstaltung', auftrag_nr: 'kopf.auftrag_nr', projekt_nr: 'kopf.projekt_nr' }],
        ['kunde', 'Kunde', { firma: 'kopf.kunde.firma', anschrift: 'kopf.kunde.anschrift', kundennummer: 'kopf.kunde.kundennummer' }],
        ['ansprechpartner', 'Ansprechpartner', { ansprechpartner: 'kopf.kunde.ansprechpartner', telefon: 'kopf.kunde.telefon',
            email: 'kopf.kunde.email', projektleiter_kunde: 'kopf.projektleiter_kunde' }],
        ['ort', 'Veranstaltungsort', { aufstellort: 'kopf.anlage.aufstellort', anlagenverantwortlicher: 'kopf.anlagenverantwortlicher' }],
        ['zeitraum', 'Zeitraum', { zeitraum: 'kopf.zeitraum' }]
    ];

    var bestaetigt = '';      // zuletzt vom Server bestätigter Stand (JSON)
    var ewx = '';             // Eventworx-Vorgang fürs nächste Autosave („-“ entfernt den Verweis)
    var gestoppt = false;     // nach 401/409/419/423: nicht weiter senden
    var abgelehnt = null;     // Stand, den der Server abgelehnt hat (422 …) – nicht noch einmal
    var uebernahme = false;   // ein lokaler Entwurf wird gerade übernommen
    var lokalGesperrt = false;  // ein älterer lokaler Entwurf wartet auf Übernehmen/Verwerfen
    var unsicher = null;      // { json, ewx }: gesendet, aber ohne Antwort – geht beim nächsten Mal zuerst
    var kette = Promise.resolve();
    var speicherZeit = null;
    var lokalZeit = null;
    var ewxDaten = null;

    // ======================================================== Zustand aus dem DOM

    function setzen(ziel, pfad, wert) {
        var teile = pfad.split('.');
        var o = ziel;
        for (var i = 0; i < teile.length - 1; i++) {
            if (typeof o[teile[i]] !== 'object' || o[teile[i]] === null) { o[teile[i]] = {}; }
            o = o[teile[i]];
        }
        o[teile[teile.length - 1]] = wert;
    }

    function lesen(ziel, pfad) {
        var o = ziel;
        var teile = pfad.split('.');
        for (var i = 0; i < teile.length; i++) {
            if (o === null || typeof o !== 'object' || !(teile[i] in o)) { return undefined; }
            o = o[teile[i]];
        }
        return o;
    }

    /** Die Felder, die unmittelbar zu diesem Behälter gehören (null = oben, ohne Zeilen und Mängel). */
    function eigeneFelder(bereich, behaelter) {
        return Array.prototype.filter.call(bereich.querySelectorAll('[data-feld]'), function (el) {
            return el.closest('[data-zeile], [data-mangel]') === behaelter;
        });
    }

    function wertVon(el) {
        if (el.type === 'checkbox') {
            return el.checked ? (el.dataset.an || '1') : (el.dataset.aus !== undefined ? el.dataset.aus : '0');
        }
        return el.value;
    }

    function sammeln(bereich, behaelter) {
        var out = {};
        var listen = {};
        eigeneFelder(bereich, behaelter).forEach(function (el) {
            var pfad = el.dataset.feld;
            if (el.hasAttribute('data-liste')) {
                if (!listen[pfad]) { listen[pfad] = []; setzen(out, pfad, listen[pfad]); }
                if (el.checked) { listen[pfad].push(el.value); }
                return;
            }
            if (el.type === 'radio') {
                if (el.checked) { setzen(out, pfad, el.value); } else if (lesen(out, pfad) === undefined) { setzen(out, pfad, ''); }
                return;
            }
            setzen(out, pfad, wertVon(el));
        });
        return out;
    }

    function zeilen() { return Array.prototype.slice.call(wurzel.querySelectorAll('[data-zeile]')); }
    function feldIn(bereich, pfad) { return bereich.querySelector('[data-feld="' + pfad + '"]'); }

    function abgeleitet(zeile) {
        return (zeile.dataset.abgeleitet || '').split(' ').filter(function (p) { return p !== ''; });
    }

    function zustand() {
        var inhalt = sammeln(wurzel, null);
        inhalt.kreise = zeilen().map(function (zeile) {
            var k = sammeln(zeile, zeile);
            k.z = zeile.dataset.zeile;
            k.abgeleitet = abgeleitet(zeile);
            return k;
        });
        inhalt.maengel = Array.prototype.map.call(wurzel.querySelectorAll('[data-mangel]'), function (m) { return sammeln(m, m); });
        return JSON.stringify(inhalt);
    }

    // ======================================================== Anzeige des Speicherstands

    var anzeige = wurzel.querySelector('[data-speicherstand]');
    var meldung = wurzel.querySelector('[data-speicher-meldung]');

    function uhrzeit(iso) {
        var d = iso ? new Date(iso) : new Date();
        if (isNaN(d.getTime())) { d = new Date(); }
        return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    }

    /** Der Stand oben rechts; die Uhrzeit bleibt auch am Telefon, wo das Wort davor wegfällt. */
    function stand(art, text, zeit) {
        anzeige.className = 'speicherstand ' + art;
        leeren(anzeige);
        var wort = document.createElement('span');
        wort.className = zeit ? 'speicher-wort' : '';
        wort.textContent = text;
        anzeige.appendChild(wort);
        if (zeit) { anzeige.appendChild(document.createTextNode(' ' + zeit)); }
    }

    /** Meldung unter der Leiste, mit den passenden Knöpfen (erneut, anmelden, neuLaden). */
    function meldungZeigen(text, knoepfe) {
        knoepfe = knoepfe || {};
        meldung.querySelector('[data-speicher-text]').textContent = text;
        meldung.querySelector('[data-erneut]').hidden = !knoepfe.erneut;
        meldung.querySelector('[data-anmelden]').hidden = !knoepfe.anmelden;
        meldung.querySelector('[data-neu-laden]').hidden = !knoepfe.neuLaden;
        meldung.hidden = false;
    }

    function meldungAus() { meldung.hidden = true; }

    function hinweiseZeigen(liste) {
        var box = wurzel.querySelector('[data-hinweise]');
        leeren(box);
        (liste || []).forEach(function (t) {
            var p = document.createElement('div');
            p.textContent = t;
            box.appendChild(p);
        });
        box.hidden = !liste || liste.length === 0;
    }

    function leeren(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

    // ======================================================== Lokaler Entwurf

    function lokalLesen(schluessel) {
        try {
            var roh = window.localStorage.getItem(schluessel || LOKAL);
            return roh ? JSON.parse(roh) : null;
        } catch (e) { return null; }
    }

    /**
     * Den Stand auf dem Gerät sichern – true, wenn das geklappt hat. Wartet
     * ein älterer Entwurf auf die Entscheidung, unter AKTUELL (der alte
     * bleibt, wie er ist).
     */
    function lokalSchreiben(json) {
        if (uebernahme) { return false; }
        try {
            window.localStorage.setItem(lokalGesperrt ? AKTUELL : LOKAL, JSON.stringify({ basis_version: version, zeit: Date.now(), inhalt: json, ewx: ewx,
                unsicher: unsicher ? unsicher.json : null }));
            return true;
        } catch (e) { return false; /* voll oder privat – dann nur der Server */ }
    }

    function lokalLoeschen(schluessel) {
        try { window.localStorage.removeItem(schluessel || LOKAL); } catch (e) { /* privat */ }
    }

    /** Der laufende Stand ist beim Server: seine Sicherung auf dem Gerät hat ausgedient. */
    function lokalBestaetigt() {
        lokalLoeschen(lokalGesperrt ? AKTUELL : LOKAL);
    }

    function lokalPlanen() {
        if (lokalZeit !== null) { return; }
        lokalZeit = setTimeout(function () { lokalZeit = null; lokalSofort(); }, LOKAL_TAKT);
    }

    function lokalSofort() {
        var json = zustand();
        if (json !== bestaetigt || ewx !== '') { lokalSchreiben(json); }
    }

    // ======================================================== Speichern

    function planen(ms) {
        clearTimeout(speicherZeit);
        speicherZeit = setTimeout(function () { speichern(); }, ms);
    }

    /** In die Warteschlange – nie zwei Anfragen auf einmal, sonst stimmt die Version nicht. */
    function speichern(o) {
        kette = kette.then(function () { return senden(o || {}); }, function () { return senden(o || {}); });
        return kette;
    }

    /**
     * Senden – erst, was ohne Antwort blieb (unsicher), dann der jetzige
     * Stand. Ist das Unsichere schon angekommen, antwortet der Server mit
     * „gespeichert“ und dem aktuellen Stand statt mit einem Konflikt.
     */
    function senden(o) {
        if (unsicher && !o.uebernahme && !o.nachtrag) {
            var u = unsicher;
            return einmal({ inhalt: u.json, ewx: u.ewx, nachtrag: true, keepalive: o.keepalive }).then(function (ok) {
                return ok ? einmal(o) : false;
            });
        }
        return einmal(o);
    }

    function einmal(o) {
        if (gestoppt || (uebernahme && !o.uebernahme)) { return Promise.resolve(false); }
        var json = o.inhalt || zustand();
        var mitEwx = o.ewx !== undefined ? o.ewx : ewx;
        if (json === bestaetigt && mitEwx === '') {
            if (o.nachtrag) { unsicher = null; }
            if (anzeige.classList.contains('warn') && meldung.hidden) { stand('ok', 'gespeichert', uhrzeit(null)); }
            return Promise.resolve(true);
        }
        if (json === abgelehnt && mitEwx === '') { return Promise.resolve(false); }
        stand('laeuft', 'speichert …');
        var daten = new FormData();
        daten.append('_token', window.PM_TOKEN || '');
        daten.append('version', String(version));
        daten.append('inhalt', json);
        if (mitEwx !== '') { daten.append('ewx', mitEwx); }
        return window.PMNetz.post(ADRESSE + '/speichern', daten, ZEIT_SPEICHERN, { keepalive: !!o.keepalive && json.length < KEEPALIVE_HOECHSTENS })
            .then(function (r) {
                return verarbeiten(r, json, mitEwx, o.uebernahme || false);
            }, function () {
                // Keine Antwort (Netz weg oder Zeitgrenze): vielleicht ist es doch angekommen.
                if (!unsicher && !o.uebernahme) { unsicher = { json: json, ewx: mitEwx }; }
                ohneVerbindung();
                return false;
            });
    }

    function verarbeiten(r, json, mitEwx, ausLokal) {
        var erg = r.erg;
        if (r.status !== 0) { unsicher = null; }
        if (r.status === 200 && erg.ok) {
            version = erg.version;
            wurzel.dataset.version = String(version);
            bestaetigt = json;
            abgelehnt = null;
            if (ausLokal) {
                lokalLoeschen(typeof ausLokal === 'string' ? ausLokal : LOKAL);
                stand('laeuft', 'übernommen – lädt neu …');
                window.location.reload();
                return true;
            }
            if (mitEwx === ewx) { ewx = ''; }
            var jetzt = zustand();
            if (jetzt === json && ewx === '') { lokalBestaetigt(); } else { lokalSchreiben(jetzt); }
            bewertungZeigen(erg.bewertung);
            abschnitteZeigen(erg.abschnitte);
            hinweiseZeigen(erg.hinweise);
            meldungAus();
            if (jetzt === json) { stand('ok', 'gespeichert', uhrzeit(erg.gespeichert_am)); } else { stand('warn', 'ungespeichert'); }
            return true;
        }
        var gesichert = lokalSchreiben(zustand());
        if (r.status === 401) {
            gestoppt = true;
            stand('fehler', 'abgemeldet');
            meldungZeigen(gesichert ? 'Abgemeldet – deine Eingaben sind auf diesem Gerät gesichert. Nach dem Anmelden geht es hier weiter.'
                : 'Abgemeldet – deine Eingaben sind NICHT gesichert. Bitte in einem neuen Tab anmelden und diese Seite offen lassen.', { anmelden: true });
            return false;
        }
        if (r.status === 409) {
            gestoppt = true;
            stand('fehler', 'Konflikt');
            meldungZeigen('Auf einem anderen Gerät geändert' + (erg.geaendert_von ? ' (von ' + erg.geaendert_von + ', ' + uhrzeit(erg.geaendert_am) + ')' : '')
                + ' – bitte neu laden. Deine Eingaben bleiben auf diesem Gerät und werden danach angeboten.', { neuLaden: true });
            return false;
        }
        if (r.status === 419 || r.status === 423) {
            gestoppt = true;
            stand('fehler', 'nicht gespeichert');
            meldungZeigen(erg.message || 'Bitte die Seite neu laden.', { neuLaden: true });
            return false;
        }
        if (r.status >= 500) {
            stand('warn', gesichert ? 'auf dem Gerät gesichert' : 'nicht gesichert');
            meldungZeigen((erg.message || 'Der Server hat nicht gespeichert.') + (gesichert ? ' Deine Eingaben sind auf diesem Gerät gesichert.'
                : ' Deine Eingaben sind NICHT gesichert – bitte die Seite offen lassen und gleich noch einmal versuchen.'), { erneut: true });
            return false;
        }
        abgelehnt = json;
        stand('fehler', 'nicht gespeichert');
        meldungZeigen(erg.message || 'Nicht gespeichert.', {});
        return false;
    }

    function ohneVerbindung() {
        if (lokalSchreiben(zustand())) {
            stand('warn', 'auf dem Gerät gesichert');
            meldungZeigen('Keine Verbindung – deine Eingaben sind auf diesem Gerät gesichert und gehen raus, sobald es wieder geht.', { erneut: true });
        } else {
            stand('fehler', 'nicht gesichert');
            meldungZeigen('Keine Verbindung – deine Eingaben sind NICHT gesichert (Speicher des Geräts nicht verfügbar). Bitte die Seite offen lassen, '
                + 'bis die Verbindung wieder steht.', { erneut: true });
        }
    }

    /** Eine Eingabe: „aus Plan“ entfernen, lokal sichern, Speichern einplanen. */
    function geaendert(el) {
        if (el && el.dataset && el.dataset.feld) { abgeleitetEntfernen(el); }
        lokalPlanen();
        if (gestoppt) { return; }
        if (!anzeige.classList.contains('laeuft') && meldung.hidden) { stand('warn', 'ungespeichert'); }
        planen(VERZOEGERUNG);
    }

    function abgeleitetEntfernen(el) {
        var zeile = el.closest('[data-zeile]');
        if (!zeile) { return; }
        var pfad = el.dataset.feld;
        var liste = abgeleitet(zeile);
        if (liste.indexOf(pfad) === -1) { return; }
        zeile.dataset.abgeleitet = liste.filter(function (p) { return p !== pfad; }).join(' ');
        var marke = zeile.querySelector('[data-abgeleitet-marke="' + pfad + '"]');
        if (marke) { marke.remove(); }
    }

    // ======================================================== Bewertung anzeigen

    function stufeText(auto, pruefer) {
        if (pruefer === 'io') { return ['ok', '✓ i.O. (Prüfer)']; }
        if (pruefer === 'nio') { return ['mangel', '✗ n.i.O. (Prüfer)']; }
        return {
            ok: ['ok', '✓ i.O.'], hinweis: ['hinweis', '! Hinweis'], mangel: ['mangel', '✗ Mangel'], fehlt: ['fehlt', '… Wert fehlt'],
            manuell: ['manuell', '? selbst bewerten'], entf: ['entf', '– nicht gemessen']
        }[auto] || ['offen', 'offen'];
    }

    /** Zahl mit Komma, höchstens zwei Stellen – wie Grenzwerte::komma(). */
    function komma(wert) {
        var n = parseFloat(wert);
        if (!isFinite(n)) { return ''; }
        var s = n.toFixed(2);
        if (s.indexOf('.') !== -1) { s = s.replace(/0+$/, '').replace(/\.$/, ''); }
        return s.replace('.', ',');
    }

    /** Wie Felder::grenzeText(). */
    function grenzeText(feld, g) {
        var da = function (k) { return g && g[k] !== undefined && g[k] !== null; };
        switch (feld) {
            case 'zs': return da('zs_max') ? '≤ ' + komma(g.zs_max) + ' Ω' : '';
            case 'ik': return da('ik_min') ? '≥ ' + komma(g.ik_min) + ' A' : '';
            case 'riso': return da('riso_min') ? '≥ ' + komma(g.riso_min) + ' MΩ' : '';
            case 'rpe': return da('rpe_max') ? '≤ ' + komma(g.rpe_max) + ' Ω' : '';
            case 'idelta': return da('idelta_max') ? '> ' + (da('idelta_min') ? komma(g.idelta_min) : '') + ' … ≤ ' + komma(g.idelta_max) + ' mA' : '';
            case 'ta': return da('ta_max') ? (da('ta_min') ? komma(g.ta_min) + ' … ' + komma(g.ta_max) + ' ms' : '≤ ' + komma(g.ta_max) + ' ms') : '';
            case 'ub': return da('ub_max') ? '≤ ' + komma(g.ub_max) + ' V' : '';
        }
        return '';
    }

    function meldungenSetzen(liste, meldungen) {
        if (!liste) { return; }
        leeren(liste);
        (meldungen || []).forEach(function (m) {
            var li = document.createElement('li');
            li.className = m.stufe || '';
            li.textContent = m.text || '';
            liste.appendChild(li);
        });
    }

    function prueferWert(zeile) {
        var r = zeile.querySelector('input[data-feld="pruefer"]:checked');
        return r ? r.value : 'auto';
    }

    function zeileStufe(zeile) {
        var st = stufeText(zeile.dataset.stufe || '', prueferWert(zeile));
        zeile.className = zeile.className.replace(/\bstufe-\S+/g, '').replace(/\s+/g, ' ').trim() + ' stufe-' + st[0];
        var badge = zeile.querySelector('[data-bewertung]');
        badge.className = 'bewertung ' + st[0];
        badge.textContent = st[1];
    }

    function zeileBewerten(zeile, b) {
        zeile.dataset.stufe = b ? (b.auto || '') : '';
        zeileStufe(zeile);
        meldungenSetzen(zeile.querySelector('[data-meldungen]'), b ? b.meldungen : []);
        zeile.querySelectorAll('[data-grenze]').forEach(function (g) { g.textContent = grenzeText(g.dataset.grenze, b ? b.grenzen : null); });
    }

    function bewertungZeigen(bw) {
        if (!bw) { return; }
        zeilen().forEach(function (zeile) { zeileBewerten(zeile, bw.kreise ? bw.kreise[zeile.dataset.zeile] : null); });
        var erdung = wurzel.querySelector('[data-bewertung-erdung]');
        if (erdung && bw.erdung) {
            var st = stufeText(bw.erdung.auto || '', 'auto');
            var badge = erdung.querySelector('[data-bewertung]');
            badge.className = 'bewertung ' + st[0];
            badge.textContent = st[1];
            erdung.querySelector('[data-erdung-bezug]').textContent = (bw.erdung.grenze !== null && bw.erdung.grenze !== undefined
                ? 'Grenzwert ≤ ' + komma(bw.erdung.grenze) + ' Ω · ' : '') + (bw.erdung.bezug || '');
            meldungenSetzen(erdung.querySelector('[data-meldungen]'), bw.erdung.meldungen);
        }
        var netz = wurzel.querySelector('[data-bewertung-netz]');
        if (netz && bw.netz) {
            var sn = stufeText(bw.netz.auto || '', 'auto');
            var bn = netz.querySelector('[data-bewertung]');
            bn.className = 'bewertung ' + sn[0];
            bn.textContent = sn[1];
            meldungenSetzen(netz.querySelector('[data-meldungen]'), bw.netz.meldungen);
            netz.hidden = !bw.netz.meldungen || bw.netz.meldungen.length === 0;
        }
        vorschlagZeigen();
    }

    function abschnitteZeigen(a) {
        if (!a) { return; }
        Object.keys(a).forEach(function (k) {
            var el = wurzel.querySelector('[data-stand="' + k + '"]');
            if (!el) { return; }
            var x = a[k];
            var art = x.mangel > 0 ? 'mangel' : (x.offen > 0 ? 'offen' : 'ok');
            el.className = 'stand ' + art;
            el.textContent = x.mangel > 0 ? '! ' + x.mangel : (x.offen > 0 ? String(x.offen) : '✓');
            el.title = art === 'ok' ? 'vollständig' : (art === 'mangel' ? 'Mangel' : 'offen');
        });
    }

    /** Wie Felder::schutzText() – aus den Feldern der Zeile. */
    function schutzText(zeile) {
        var w = function (p) { var el = feldIn(zeile, p); return el ? String(wertVon(el)).trim() : ''; };
        var wort = function (liste) { return liste.filter(function (t) { return t !== ''; }).join(' '); };
        var teile = [];
        var leitung = w('leitung.art');
        var leiter = w('leitung.leiter');
        var q = w('leitung.querschnitt');
        var ader = q !== '' ? (leiter !== '' ? leiter + '×' : '') + q + ' mm²' : (leiter !== '' ? leiter + ' Leiter' : '');
        if (leitung !== '' || ader !== '') { teile.push((leitung + (leitung !== '' && ader !== '' ? ' · ' : '') + ader).trim()); }
        var ueb = wort([w('ueb.art'), w('ueb.char') + w('ueb.in')]);
        if (ueb !== '') { teile.push(ueb); }
        if (w('rcd.idn') !== '') {
            teile.push(wort(['RCD', w('rcd.bez'), (w('rcd.in') !== '' ? w('rcd.in') + ' A/' : '') + w('rcd.idn') + ' mA',
                w('rcd.typ') !== '' ? 'Typ ' + w('rcd.typ') : '', w('rcd.s') === '1' ? 'S' : '']));
        }
        return teile.join(' · ');
    }

    // ======================================================== Vorschlag und Mängel

    function befunde() {
        var liste = [];
        wurzel.querySelectorAll('.punkt').forEach(function (p) {
            if (!p.querySelector('input[type=radio][value="nio"]:checked')) { return; }
            var text = p.querySelector('.punkt-text span').textContent;
            var bem = p.querySelector('[data-feld^="punkt_bemerkung."]');
            liste.push({ text: text + (bem && bem.value.trim() !== '' ? ': ' + bem.value.trim() : ''), kreis: '' });
        });
        zeilen().forEach(function (z) {
            var pr = prueferWert(z);
            if (pr === 'nio' || (pr === 'auto' && z.dataset.stufe === 'mangel')) {
                var nr = feldIn(z, 'nr').value.trim();
                var ort = feldIn(z, 'ort').value.trim();
                var texte = Array.prototype.map.call(z.querySelectorAll('[data-meldungen] li.mangel'), function (li) { return li.textContent; });
                liste.push({ text: 'Kreis ' + nr + (ort !== '' ? ' (' + ort + ')' : '') + ': ' + (texte.join(' ') || 'n.i.O.'), kreis: z.dataset.zeile });
            }
        });
        ['[data-bewertung-erdung]', '[data-bewertung-netz]'].forEach(function (sel) {
            var teil = wurzel.querySelector(sel);
            if (teil && teil.querySelector('.bewertung.mangel')) {
                teil.querySelectorAll('[data-meldungen] li.mangel').forEach(function (li) { liste.push({ text: li.textContent, kreis: '' }); });
            }
        });
        return liste;
    }

    function vorschlagZeigen() {
        var box = wurzel.querySelector('[data-vorschlag]');
        if (!box) { return; }
        var n = befunde().length;
        var offen = zeilen().filter(function (z) {
            return ['', 'fehlt'].indexOf(z.dataset.stufe || '') !== -1 || (z.dataset.stufe === 'manuell' && prueferWert(z) === 'auto');
        }).length;
        box.classList.toggle('nein', n > 0);
        if (n > 0) {
            box.textContent = 'Vorschlag: „Nein“ – ' + n + (n === 1 ? ' Befund' : ' Befunde') + ' (n.i.O. oder Mangel). Mit „Aus den Befunden füllen“ in die Mängelliste. '
                + 'Das Ergebnis gilt erst, wenn du es wählst.';
        } else if (offen > 0) {
            box.textContent = 'Noch ' + offen + ' Stromkreis' + (offen === 1 ? '' : 'e') + ' offen – der Vorschlag folgt, wenn alles gemessen ist.';
        } else {
            box.textContent = 'Vorschlag: „Ja“ – keine Mängel gefunden. Das Ergebnis gilt erst, wenn du es wählst.';
        }
    }

    function maengelFuellen() {
        var vorhanden = Array.prototype.map.call(wurzel.querySelectorAll('[data-mangel]'), function (m) {
            return { text: feldIn(m, 'text').value.trim(), kreis: feldIn(m, 'kreis').value };
        });
        var neu = 0;
        befunde().forEach(function (b) {
            var schon = vorhanden.some(function (v) { return (b.kreis !== '' && v.kreis === b.kreis) || (b.kreis === '' && v.text.indexOf(b.text) === 0); });
            if (schon) { return; }
            var m = mangelNeu();
            feldIn(m, 'text').value = b.text;
            if (b.kreis !== '') { kreisOptionen(feldIn(m, 'kreis'), null); feldIn(m, 'kreis').value = b.kreis; }
            neu++;
        });
        if (neu > 0) { geaendert(null); }
        return neu;
    }

    // ======================================================== Zeilen und Mängel

    function schluessel(vorsilbe) {
        var b = new Uint8Array(4);
        (window.crypto || window.msCrypto).getRandomValues(b);
        return vorsilbe + Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    function ausVorlage(id, z) {
        var vorlage = document.getElementById(id);
        var knoten = vorlage.content.firstElementChild.cloneNode(true);
        [knoten].concat(Array.prototype.slice.call(knoten.querySelectorAll('*'))).forEach(function (el) {
            ['id', 'for', 'name', 'data-zeile', 'data-feld-ziel'].forEach(function (a) {
                var v = el.getAttribute(a);
                if (v !== null && v.indexOf(VORLAGE) !== -1) { el.setAttribute(a, v.split(VORLAGE).join(z)); }
            });
        });
        return knoten;
    }

    function naechsteNr() {
        var max = 0;
        zeilen().forEach(function (z) { var n = parseInt(feldIn(z, 'nr').value, 10); if (n > max) { max = n; } });
        return String(max + 1);
    }

    function zeilenZahl() {
        var n = zeilen().length;
        var zahl = wurzel.querySelector('[data-kreise-zahl]');
        if (zahl) { zahl.textContent = '(' + n + ')'; }
        var leer = wurzel.querySelector('[data-kreise-leer]');
        if (leer) { leer.hidden = n > 0; }
    }

    function kreisNeu(nach) {
        var zeile = ausVorlage('kreis-vorlage', schluessel('k'));
        feldIn(zeile, 'nr').value = naechsteNr();
        var liste = wurzel.querySelector('[data-kreise]');
        if (nach && nach.nextSibling) { liste.insertBefore(zeile, nach.nextSibling); } else { liste.appendChild(zeile); }
        zeilenZahl();
        return zeile;
    }

    function kreisKopieren(quelle) {
        var zeile = kreisNeu(quelle);
        KOPIEREN.forEach(function (pfad) {
            var von = feldIn(quelle, pfad);
            var nach = feldIn(zeile, pfad);
            if (!von || !nach) { return; }
            if (nach.type === 'checkbox') { nach.checked = von.checked; } else { nach.value = von.value; }
        });
        zeile.querySelector('[data-schutz-text]').textContent = schutzText(zeile);
        return zeile;
    }

    function kreisEntfernen(zeile) {
        var z = zeile.dataset.zeile;
        zeile.remove();
        wurzel.querySelectorAll('[data-rcd-von], [data-kreis-wahl]').forEach(function (s) {
            if (s.value === z) { s.value = ''; }
            Array.prototype.slice.call(s.options).forEach(function (o) { if (o.value === z) { o.remove(); } });
        });
        zeilenZahl();
    }

    function mangelNeu() {
        var m = ausVorlage('mangel-vorlage', schluessel('m'));
        wurzel.querySelector('[data-maengel]').appendChild(m);
        return m;
    }

    /**
     * Die Auswahl „RCD-Werte gemessen an“ bzw. „Stromkreis“ eines Mangels –
     * erst beim Öffnen gefüllt. Für „gemessen an“ nur Kreise am selben RCD
     * (gleiche Bezeichnung und IΔn), sobald eine Bezeichnung eingetragen ist.
     */
    function kreisOptionen(select, ohne) {
        var wert = select.value;
        var erste = select.options[0];
        var wert0 = function (z, pfad) { var f = feldIn(z, pfad); return f ? f.value.trim() : ''; };
        var bez = ohne ? wert0(ohne, 'rcd.bez') : '';
        var idn = ohne ? wert0(ohne, 'rcd.idn') : '';
        while (select.options.length > 1) { select.remove(1); }
        zeilen().forEach(function (z) {
            if (ohne && z === ohne) { return; }
            if (ohne && bez !== '' && (wert0(z, 'rcd.bez') !== bez || (idn !== '' && wert0(z, 'rcd.idn') !== idn)) && z.dataset.zeile !== wert) { return; }
            var nr = feldIn(z, 'nr').value.trim();
            var ort = feldIn(z, 'ort').value.trim();
            var o = document.createElement('option');
            o.value = z.dataset.zeile;
            o.textContent = 'Kreis ' + nr + (ort !== '' ? ' · ' + (ort.length > 40 ? ort.slice(0, 39) + '…' : ort) : '');
            select.appendChild(o);
        });
        select.value = wert;
        if (select.value !== wert) { select.value = erste ? erste.value : ''; }
    }

    // ======================================================== Springen zum Feld

    function sichtbarMachen(el) {
        var panel = el.closest('[data-reiter-feld]');
        if (panel && panel.hidden) { reiterZeigen(panel.dataset.reiterFeld); }
        for (var d = el.closest('details'); d; d = d.parentElement ? d.parentElement.closest('details') : null) { d.open = true; }
        var bem = el.closest('[data-punkt-bemerkung]');
        if (bem) { bem.hidden = false; }
    }

    function springen(feld) {
        var ziel = null;
        if (feld.indexOf('kreise.') === 0) {
            var teile = feld.split('.');
            var zeile = wurzel.querySelector('[data-zeile="' + teile[1] + '"]');
            if (zeile) { ziel = teile.length > 2 ? (feldIn(zeile, teile.slice(2).join('.')) || zeile) : zeile; }
        }
        if (!ziel) { ziel = wurzel.querySelector('[data-feld-ziel="' + feld + '"]') || wurzel.querySelector('[data-feld="' + feld + '"]:not([type=hidden])'); }
        if (!ziel) {
            var abschnitt = wurzel.querySelector('[data-abschnitt="' + feld.split('.')[0] + '"]');
            ziel = abschnitt || null;
        }
        if (!ziel) { return; }
        var rahmen = ziel.type === 'radio' ? ziel.closest('.dreiwahl') : ziel;
        sichtbarMachen(ziel);
        rahmen.scrollIntoView({ block: 'center', behavior: 'smooth' });
        var fokus = ziel.matches('input, select, textarea, button') ? ziel
            : (ziel.querySelector('input:not([type=hidden]), select, textarea') || ziel);
        setTimeout(function () {
            try { fokus.focus({ preventScroll: true }); } catch (e) { fokus.focus(); }
        }, 350);
        rahmen.classList.add('feld-markiert');
        setTimeout(function () { rahmen.classList.remove('feld-markiert'); }, 2500);
    }

    // ======================================================== Abfragen (Eventworx, Prüfen, Zeilen)

    function anfrage(pfad, felder) {
        var daten = new FormData();
        daten.append('_token', window.PM_TOKEN || '');
        Object.keys(felder || {}).forEach(function (k) {
            var v = felder[k];
            if (Array.isArray(v)) { v.forEach(function (x) { daten.append(k + '[]', x); }); } else { daten.append(k, v); }
        });
        var netz = window.PMNetz.post(ADRESSE + pfad, daten, ZEIT_ANFRAGE);
        var p = netz.then(function (r) {
            if (r.status === 401) {
                gestoppt = true;
                stand('fehler', 'abgemeldet');
                meldungZeigen('Abgemeldet – deine Eingaben sind auf diesem Gerät gesichert.', { anmelden: true });
            }
            return r;
        }, function () { return { status: 0, erg: { ok: false, message: 'Keine Verbindung zum Server.' } }; });
        p.abbrechen = netz.abbrechen;
        return p;
    }

    // ---------------------------------------------------- Eventworx

    function ewxMeldung(text) {
        var el = wurzel.querySelector('[data-ewx-meldung]');
        if (el) { el.textContent = text; }
    }

    /** Eventworx geht nicht: Meldung, und weiter mit Freitext. */
    function ewxFehler(text) {
        ewxMeldung(text);
        var hinweis = wurzel.querySelector('[data-quelle-text]');
        if (hinweis) { hinweis.textContent = text + ' Die Kundendaten bitte hier als Freitext eintragen.'; }
        reiterZeigen('freitext');
        var firma = feldIn(wurzel, 'kopf.kunde.firma');
        if (firma) { firma.focus(); }
    }

    function datum(iso) {
        var d = iso ? new Date(iso) : null;
        return d && !isNaN(d.getTime()) ? d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';
    }

    function ewxSuchen() {
        var feld = wurzel.querySelector('[data-ewx-q]');
        var liste = wurzel.querySelector('[data-ewx-treffer]');
        if (!feld || !liste) { return; }
        var q = feld.value.trim();
        if (q.length < 2) { ewxMeldung('Bitte mindestens zwei Zeichen – am sichersten die Auftrags- oder Angebotsnummer.'); return; }
        ewxMeldung('sucht in Eventworx …');
        leeren(liste);
        anfrage('/eventworx/suche', { q: q }).then(function (r) {
            if (!r.erg.ok) { ewxFehler(r.erg.message || 'Eventworx antwortet nicht.'); return; }
            var treffer = r.erg.treffer || [];
            ewxMeldung(treffer.length === 0 ? 'Kein Auftrag oder Angebot gefunden – am sichersten mit der Nummer suchen.'
                : treffer.length + (treffer.length === 1 ? ' Treffer' : ' Treffer') + ' – antippen zum Übernehmen.');
            treffer.forEach(function (t) {
                var li = document.createElement('li');
                var knopf = document.createElement('button');
                knopf.type = 'button';
                knopf.dataset.ewxVorgang = t.id;
                var kopf = document.createElement('strong');
                kopf.textContent = (t.art_name || '') + ' ' + (t.nummer || '');
                var titel = document.createElement('span');
                titel.textContent = t.titel || '';
                var mehr = document.createElement('span');
                mehr.className = 'small muted';
                mehr.textContent = [t.projekt ? 'Projekt ' + t.projekt : '', [datum(t.von), datum(t.bis)].filter(Boolean).join(' – ')].filter(Boolean).join(' · ');
                knopf.appendChild(kopf);
                knopf.appendChild(titel);
                knopf.appendChild(mehr);
                li.appendChild(knopf);
                liste.appendChild(li);
            });
        });
    }

    function ewxVorgang(id) {
        ewxMeldung('lädt den Vorgang …');
        anfrage('/eventworx/vorgang', { vorgang_id: id }).then(function (r) {
            if (!r.erg.ok) { ewxFehler(r.erg.message || 'Eventworx antwortet nicht.'); return; }
            ewxMeldung('');
            dialogOeffnen(r.erg);
        });
    }

    function beschriftung(feld) {
        var label = wurzel.querySelector('label[for="' + feld.id + '"]');
        if (!label) { return feld.dataset.feld; }
        var kopie = label.cloneNode(true);
        kopie.querySelectorAll('.abgeleitet').forEach(function (x) { x.remove(); });
        return kopie.textContent.trim();
    }

    function dialogOeffnen(erg) {
        var dialog = wurzel.querySelector('[data-ewx-dialog]');
        var box = dialog.querySelector('[data-ewx-gruppen]');
        leeren(box);
        ewxDaten = erg;
        var v = erg.verweis || {};
        dialog.querySelector('[data-ewx-dialog-vorgang]').textContent = ((v.art_name || '') + ' ' + (v.nummer || '')).trim();
        EWX_GRUPPEN.forEach(function (g) {
            var werte = (erg.gruppen || {})[g[0]] || {};
            var felder = Object.keys(g[2]).filter(function (k) { return typeof werte[k] === 'string' && werte[k].trim() !== ''; });
            if (felder.length === 0) { return; }
            var leer = felder.every(function (k) { var el = feldIn(wurzel, g[2][k]); return !el || el.value.trim() === ''; });
            var rahmen = document.createElement('div');
            rahmen.className = 'ewx-gruppe';
            var check = document.createElement('label');
            check.className = 'check';
            var haken = document.createElement('input');
            haken.type = 'checkbox';
            haken.dataset.ewxGruppe = g[0];
            haken.checked = leer;
            var titel = document.createElement('span');
            titel.textContent = g[1] + (leer ? '' : ' – ersetzt vorhandene Angaben');
            check.appendChild(haken);
            check.appendChild(titel);
            rahmen.appendChild(check);
            var dl = document.createElement('dl');
            dl.className = 'ewx-vergleich';
            felder.forEach(function (k) {
                var ziel = feldIn(wurzel, g[2][k]);
                var dt = document.createElement('dt');
                dt.textContent = ziel ? beschriftung(ziel) : k;
                var dd = document.createElement('dd');
                var alt = ziel ? ziel.value.trim() : '';
                if (alt !== '' && alt !== werte[k].trim()) {
                    var a = document.createElement('span');
                    a.className = 'ewx-alt';
                    a.textContent = alt;
                    dd.appendChild(a);
                }
                dd.appendChild(document.createTextNode(werte[k]));
                dl.appendChild(dt);
                dl.appendChild(dd);
            });
            rahmen.appendChild(dl);
            box.appendChild(rahmen);
        });
        if (!box.firstChild) {
            var p = document.createElement('p');
            p.className = 'muted';
            p.textContent = 'Der Vorgang enthält keine Kundendaten, die sich übernehmen ließen – verknüpft wird er trotzdem.';
            box.appendChild(p);
        }
        if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
    }

    function verweisZeigen(v, am) {
        var box = wurzel.querySelector('[data-ewx-verweis]');
        if (!box) { return; }
        box.dataset.ewxId = v.id || '';
        box.querySelector('[data-ewx-nummer]').textContent = ((v.art_name || '') + ' ' + (v.nummer || '')).trim();
        box.querySelector('[data-ewx-am]').textContent = am ? 'geladen ' + uhrzeit(am) : '';
        var link = box.querySelector('[data-ewx-link]');
        if (link) { link.hidden = !v.link; if (v.link) { link.href = v.link; } }
        box.hidden = false;
    }

    function ewxUebernehmen() {
        var dialog = wurzel.querySelector('[data-ewx-dialog]');
        var erg = ewxDaten;
        if (!erg) { return; }
        dialog.querySelectorAll('[data-ewx-gruppe]').forEach(function (haken) {
            if (!haken.checked) { return; }
            var g = EWX_GRUPPEN.filter(function (x) { return x[0] === haken.dataset.ewxGruppe; })[0];
            var werte = (erg.gruppen || {})[g[0]] || {};
            Object.keys(g[2]).forEach(function (k) {
                var ziel = feldIn(wurzel, g[2][k]);
                if (ziel && typeof werte[k] === 'string' && werte[k].trim() !== '') { ziel.value = werte[k]; }
            });
            if (g[0] === 'kunde') { quelleSetzen('eventworx', 'Übernommen aus Eventworx – änderbar.'); }
        });
        ewx = (erg.verweis && erg.verweis.id) || '';
        verweisZeigen(erg.verweis || {}, erg.verweis ? erg.verweis.am : '');
        if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
        reiterZeigen('freitext');
        geaendert(null);
        planen(0);
    }

    function quelleSetzen(quelle, text) {
        var q = feldIn(wurzel, 'kopf.kunde.quelle');
        if (q) { q.value = quelle; }
        var hinweis = wurzel.querySelector('[data-quelle-text]');
        if (hinweis) { hinweis.textContent = text; }
    }

    // ---------------------------------------------------- Kunde aus früherem Bericht

    function ausFrueherem() {
        var wahl = wurzel.querySelector('[data-quelle-wahl]');
        var meldungText = wurzel.querySelector('[data-quelle-meldung]');
        if (!wahl) { return; }
        var firma = feldIn(wurzel, 'kopf.kunde.firma');
        if (firma && firma.value.trim() !== '' && !window.confirm('Die eingetragenen Kundendaten ersetzen?')) { return; }
        meldungText.textContent = 'lädt …';
        anfrage('/kunde-aus', { quelle: wahl.value }).then(function (r) {
            if (!r.erg.ok) { meldungText.textContent = r.erg.message || 'Nicht möglich.'; return; }
            var k = r.erg.kunde || {};
            ['firma', 'anschrift', 'kundennummer', 'ansprechpartner', 'telefon', 'email'].forEach(function (f) {
                var el = feldIn(wurzel, 'kopf.kunde.' + f);
                if (el && typeof k[f] === 'string') { el.value = k[f]; }
            });
            var pl = feldIn(wurzel, 'kopf.projektleiter_kunde');
            if (pl && r.erg.projektleiter_kunde) { pl.value = r.erg.projektleiter_kunde; }
            if (r.erg.eventworx && r.erg.eventworx.id) {
                ewx = r.erg.eventworx.id;
                verweisZeigen({ id: r.erg.eventworx.id, nummer: r.erg.eventworx.nummer, art_name: '' }, '');
            }
            meldungText.textContent = '';
            quelleSetzen('bericht', r.erg.message || 'Übernommen aus einem früheren Bericht – änderbar.');
            reiterZeigen('freitext');
            geaendert(null);
            planen(0);
        });
    }

    // ---------------------------------------------------- Prüfen

    function pruefen(knopf) {
        var ergebnis = wurzel.querySelector('[data-pruef-ergebnis]');
        var text = wurzel.querySelector('[data-pruef-text]');
        var liste = wurzel.querySelector('[data-pruef-fehler]');
        knopf.disabled = true;
        ergebnis.hidden = false;
        text.className = 'pruef-zusammenfassung';
        text.textContent = 'speichert und prüft …';
        leeren(liste);
        return speichern().then(function (ok) {
            if (!ok) { text.textContent = 'Erst muss der Bericht gespeichert sein – siehe Meldung oben.'; return null; }
            return anfrage('/pruefen', {});
        }).then(function (r) {
            if (!r) { return null; }
            if (!r.erg.ok) { text.textContent = r.erg.message || 'Prüfen nicht möglich.'; return null; }
            // Fehler der Stromkreise ab dem sechsten zusammengefasst – bei 80 Kreisen wäre die Liste sonst länger als das Formular.
            var kreise = 0;
            var weitere = null;
            (r.erg.fehler || []).forEach(function (f) {
                var li = document.createElement('li');
                var b = document.createElement('button');
                b.type = 'button';
                b.dataset.sprung = f.feld;
                b.textContent = f.text;
                li.appendChild(b);
                if (f.feld.indexOf('kreise.') === 0 && ++kreise > 5) {
                    if (weitere === null) {
                        var eintrag = document.createElement('li');
                        weitere = document.createElement('details');
                        var zusammen = document.createElement('summary');
                        weitere.appendChild(zusammen);
                        weitere.appendChild(document.createElement('ul'));
                        weitere.lastChild.className = 'pruef-fehler';
                        eintrag.appendChild(weitere);
                        liste.appendChild(eintrag);
                    }
                    weitere.lastChild.appendChild(li);
                    weitere.firstChild.textContent = 'noch ' + (kreise - 5) + ' weitere Stromkreise';
                    return;
                }
                liste.appendChild(li);
            });
            warnungenZeigen(r.erg.warnungen || []);
            zusammenfassung(r.erg.fehler || []);
            return r;
        }).finally(function () { knopf.disabled = false; });
    }

    function warnungenZeigen(warnungen) {
        var box = wurzel.querySelector('[data-pruef-warnungen]');
        var bestaetigt = {};
        box.querySelectorAll('input[data-liste]').forEach(function (c) { if (c.checked) { bestaetigt[c.value] = true; } });
        warnungen.forEach(function (w) { if (w.bestaetigt) { bestaetigt[w.code] = true; } });
        leeren(box);
        var gezeigt = {};
        warnungen.forEach(function (w) {
            gezeigt[w.code] = true;
            box.appendChild(warnungFeld(w.code, w.text, !!bestaetigt[w.code], false));
        });
        Object.keys(bestaetigt).forEach(function (code) {
            if (!gezeigt[code]) { box.appendChild(warnungFeld(code, code, true, true)); }
        });
        box.dataset.anzahl = String(warnungen.length);
    }

    function warnungFeld(code, text, an, versteckt) {
        var label = document.createElement('label');
        label.className = 'check';
        label.hidden = versteckt;
        var c = document.createElement('input');
        c.type = 'checkbox';
        c.dataset.feld = 'abschluss.warnungen_bestaetigt';
        c.setAttribute('data-liste', '');
        c.value = code;
        c.checked = an;
        var s = document.createElement('span');
        s.textContent = versteckt ? code : 'Gesehen: ' + text;
        label.appendChild(c);
        label.appendChild(s);
        return label;
    }

    function zusammenfassung(fehler) {
        var text = wurzel.querySelector('[data-pruef-text]');
        var box = wurzel.querySelector('[data-pruef-warnungen]');
        var offen = Array.prototype.filter.call(box.querySelectorAll('label:not([hidden]) input[data-liste]'), function (c) { return !c.checked; }).length;
        fehler = fehler || Array.prototype.slice.call(wurzel.querySelectorAll('[data-pruef-fehler] [data-sprung]'));
        if (fehler.length > 0) {
            text.className = 'pruef-zusammenfassung';
            text.textContent = fehler.length + (fehler.length === 1 ? ' Punkt fehlt' : ' Punkte fehlen') + ' noch – antippen springt zum Feld.';
        } else if (offen > 0) {
            text.className = 'pruef-zusammenfassung';
            text.textContent = 'Nichts fehlt. Bitte ' + (offen === 1 ? 'die Warnung' : 'die ' + offen + ' Warnungen') + ' ansehen und abhaken.';
        } else {
            text.className = 'pruef-zusammenfassung bereit';
            text.textContent = '✓ Alles bereit für die Prüfer-Unterschrift.';
        }
    }

    // ---------------------------------------------------- Zeilen ergänzen

    function zeilenErgaenzen(knopf) {
        var meldungText = wurzel.querySelector('[data-ergaenzen-meldung]');
        var ids = Array.prototype.filter.call(wurzel.querySelectorAll('[data-ergaenzen-anlage]'), function (c) { return c.checked; })
            .map(function (c) { return c.value; });
        if (ids.length === 0) { meldungText.textContent = 'Bitte mindestens eine Anlage wählen.'; return; }
        knopf.disabled = true;
        meldungText.textContent = 'speichert …';
        speichern().then(function (ok) {
            if (!ok) { meldungText.textContent = 'Erst muss der Bericht gespeichert sein.'; return null; }
            return anfrage('/kreise', { version: String(version), objekte: ids });
        }).then(function (r) {
            if (!r) { return; }
            meldungText.textContent = r.erg.message || '';
            if (r.erg.ok && r.erg.neu > 0) {
                lokalLoeschen();
                uebernahme = true;
                window.location.reload();
            } else if (r.erg.ok) {
                version = r.erg.version || version;
                wurzel.dataset.version = String(version);
            }
        }).finally(function () { knopf.disabled = false; });
    }

    // ---------------------------------------------------- Prüfer-Unterschrift

    var unterschrift = null;
    var laufendeUnterschrift = null;

    /** Erst speichern und prüfen – der Dialog öffnet nur, wenn nichts mehr fehlt. */
    function unterschriftAuf(knopf) {
        var dlg = wurzel.querySelector('[data-unterschrift-dialog]');
        if (!dlg || !window.PMUnterschrift) { return; }
        if (!unterschrift) {
            unterschrift = window.PMUnterschrift.dialog(dlg, {
                senden: unterschreiben,
                abbrechen: function () { if (laufendeUnterschrift && laufendeUnterschrift.abbrechen) { laufendeUnterschrift.abbrechen(); } }
            });
        }
        knopf.disabled = true;
        pruefen(knopf).then(function (r) {
            if (!r || !r.erg.bereit) {
                wurzel.querySelector('[data-pruef-ergebnis]').scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
            var ort = dlg.querySelector('[data-unterschrift-feld="ort"]');
            var kopfOrt = feldIn(wurzel, 'kopf.ort');
            if (ort && ort.value.trim() === '' && kopfOrt) { ort.value = kopfOrt.value; }
            unterschrift.oeffnen();
        });
    }

    function bestaetigteWarnungen() {
        return Array.prototype.filter.call(wurzel.querySelectorAll('[data-pruef-warnungen] input[data-liste]'), function (c) { return c.checked; })
            .map(function (c) { return c.value; });
    }

    function unterschreiben(werte) {
        var schluessel = 'pruefer:' + berichtId;
        unterschrift.laeuft(true);
        unterschrift.meldung('speichert und unterschreibt …', false);
        speichern().then(function (ok) {
            if (!ok) { return { status: 0, erg: { ok: false, message: 'Der Bericht ist nicht gespeichert – siehe Meldung oben.' } }; }
            laufendeUnterschrift = anfrage('/unterschreiben', {
                version: String(version), striche: unterschrift.daten(), name: werte.name || '', ort: werte.ort || '',
                anfrage: window.PMUnterschrift.schluessel(schluessel), geraet_am: new Date().toISOString(), warnungen: bestaetigteWarnungen()
            });
            return laufendeUnterschrift;
        }).then(function (r) {
            laufendeUnterschrift = null;
            if (r.status === 200 && r.erg.ok) {
                // Eingefroren: nichts mehr speichern, weiter zur Leseansicht.
                window.PMUnterschrift.schluesselLoeschen(schluessel);
                gestoppt = true;
                uebernahme = true;
                lokalLoeschen();
                unterschrift.meldung((r.erg.message || 'Unterschrieben.') + ' Lädt …', false);
                window.location.href = ADRESSE;
                return;
            }
            unterschrift.laeuft(false);
            if (r.status === 0) {
                unterschrift.meldung((r.erg.message || 'Keine Verbindung.') + ' Bitte noch einmal „Unterschreiben“ – es wird nichts doppelt angelegt.', true);
                return;
            }
            if (r.status === 409 && !r.erg.konflikt) {
                unterschrift.meldung((r.erg.message || 'Schon unterschrieben.') + ' Bitte die Seite neu laden.', true);
                return;
            }
            var offen = (r.erg.warnungen || []).filter(function (w) { return !w.bestaetigt; });
            if ((r.erg.fehler && r.erg.fehler.length > 0) || offen.length > 0) {
                // Inzwischen fehlt etwas oder eine neue Warnung ist dazugekommen: zurück zu „Prüfen“.
                unterschrift.schliessen();
                pruefen(wurzel.querySelector('[data-pruefen]'));
                return;
            }
            unterschrift.meldung(r.erg.message || 'Nicht unterschrieben.', true);
        });
    }

    // ======================================================== Reiter, Abschnitte

    function reiterZeigen(name) {
        wurzel.querySelectorAll('[data-reiter]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.reiter === name ? 'true' : 'false'); });
        wurzel.querySelectorAll('[data-reiter-feld]').forEach(function (p) { p.hidden = p.dataset.reiterFeld !== name; });
        if (name === 'eventworx') {
            var q = wurzel.querySelector('[data-ewx-q]');
            if (q) { q.focus(); }
        }
    }

    function kopfHoehe() {
        var kopf = document.querySelector('.topbar');
        if (kopf) { wurzel.style.setProperty('--kopf-hoehe', Math.round(kopf.getBoundingClientRect().height) + 'px'); }
    }

    function abschnitteBeobachten() {
        if (!('IntersectionObserver' in window)) { return; }
        var links = {};
        wurzel.querySelectorAll('[data-abschnitt-link]').forEach(function (a) { links[a.dataset.abschnittLink] = a; });
        var sichtbar = {};
        var beobachter = new IntersectionObserver(function (eintraege) {
            eintraege.forEach(function (e) { sichtbar[e.target.dataset.abschnitt] = e.isIntersecting; });
            var aktiv = Object.keys(links).filter(function (k) { return sichtbar[k]; })[0];
            if (!aktiv) { return; }
            Object.keys(links).forEach(function (k) { links[k].classList.toggle('aktiv', k === aktiv); });
            var a = links[aktiv];
            var leiste = a.parentElement;
            if (a.offsetLeft < leiste.scrollLeft || a.offsetLeft + a.offsetWidth > leiste.scrollLeft + leiste.clientWidth) {
                leiste.scrollTo({ left: Math.max(0, a.offsetLeft - 16), behavior: 'smooth' });
            }
        }, { rootMargin: '-30% 0px -60% 0px' });
        wurzel.querySelectorAll('[data-abschnitt]').forEach(function (s) { beobachter.observe(s); });
    }

    // ======================================================== Ereignisse

    function eingabe(e) {
        var el = e.target.closest ? e.target.closest('[data-feld]') : null;
        if (!el || el.closest('[data-ewx-dialog]')) { return; }
        var zeile = el.closest('[data-zeile]');
        if (zeile && /^(leitung|ueb|rcd)\./.test(el.dataset.feld)) {
            zeile.querySelector('[data-schutz-text]').textContent = schutzText(zeile);
        }
        if (el.dataset.feld === 'abschluss.warnungen_bestaetigt') { zusammenfassung(null); }
        geaendert(el);
    }
    wurzel.addEventListener('input', eingabe);
    wurzel.addEventListener('change', eingabe);

    wurzel.addEventListener('click', function (e) {
        var t = e.target;

        // Segmentschalter: ein zweiter Tipp auf die gewählte Stufe leert sie.
        if (t instanceof HTMLInputElement && t.type === 'radio' && t.closest('.dreiwahl')) {
            var gruppe = t.closest('.dreiwahl');
            if (t.dataset.war === '1' && !gruppe.hasAttribute('data-fest')) {
                t.checked = false;
                t.dataset.war = '';
                geaendert(t);
            } else {
                gruppe.querySelectorAll('input[type=radio]').forEach(function (x) { x.dataset.war = ''; });
                t.dataset.war = '1';
            }
            var punkt = t.closest('.punkt');
            if (punkt && t.checked && t.value === 'nio') {
                punkt.querySelector('[data-punkt-bemerkung]').hidden = false;
                var knopfBem = punkt.querySelector('[data-bemerkung-auf]');
                if (knopfBem) { knopfBem.hidden = true; }
            }
            var zeileR = t.closest('[data-zeile]');
            if (zeileR && t.dataset.feld === 'pruefer') { zeileStufe(zeileR); }
            vorschlagZeigen();
            return;
        }

        var op = t.closest('[data-op]');
        if (op) {
            var neu = OP_FOLGE[op.value] !== undefined ? OP_FOLGE[op.value] : '';
            op.value = neu;
            op.setAttribute('value', neu);
            op.textContent = neu === '' ? '=' : neu;
            op.setAttribute('aria-label', OP_TEXT[neu]);
            geaendert(op);
            return;
        }

        var knopf = t.closest('button, [data-reiter], [data-sprung]');
        if (!knopf) { return; }
        var d = knopf.dataset;

        if (d.reiter) { reiterZeigen(d.reiter); return; }
        if (d.sprung !== undefined) { springen(d.sprung); return; }
        if (knopf.hasAttribute('data-kurzliste-io')) {
            if (!window.confirm(d.frage || 'Übrige Punkte der Kurzliste auf i.O. setzen?')) { return; }
            wurzel.querySelectorAll('.dreiwahl[data-kurz]').forEach(function (g) {
                if (g.querySelector('input:checked')) { return; }
                var io = g.querySelector('input[value="io"]');
                if (io) { io.checked = true; io.dataset.war = '1'; }
            });
            geaendert(null);
            vorschlagZeigen();
            return;
        }
        if (knopf.hasAttribute('data-bemerkung-auf')) {
            var bem = knopf.closest('.punkt').querySelector('[data-punkt-bemerkung]');
            bem.hidden = false;
            knopf.hidden = true;
            var f = bem.querySelector('input, textarea');
            if (f) { f.focus(); }
            return;
        }
        if (d.setzen !== undefined) {
            var r = wurzel.querySelector('input[data-feld="' + d.setzen + '"][value="' + d.wert + '"]');
            if (r) { r.checked = true; r.dataset.war = '1'; geaendert(r); }
            knopf.hidden = true;
            return;
        }
        if (knopf.hasAttribute('data-kreis-neu')) {
            var z = kreisNeu(null);
            z.scrollIntoView({ block: 'center', behavior: 'smooth' });
            feldIn(z, 'ort').focus({ preventScroll: true });
            geaendert(null);
            return;
        }
        if (knopf.hasAttribute('data-kreis-kopieren')) {
            var kopie = kreisKopieren(knopf.closest('[data-zeile]'));
            kopie.scrollIntoView({ block: 'center', behavior: 'smooth' });
            geaendert(null);
            return;
        }
        if (knopf.hasAttribute('data-kreis-entfernen')) {
            var zeileE = knopf.closest('[data-zeile]');
            if (!window.confirm('Kreis ' + feldIn(zeileE, 'nr').value + ' entfernen? Die Eingaben dieser Zeile gehen verloren.')) { return; }
            kreisEntfernen(zeileE);
            geaendert(null);
            vorschlagZeigen();
            return;
        }
        if (knopf.hasAttribute('data-kreise-ergaenzen')) { zeilenErgaenzen(knopf); return; }
        if (knopf.hasAttribute('data-mangel-neu')) {
            var m = mangelNeu();
            feldIn(m, 'text').focus();
            geaendert(null);
            return;
        }
        if (knopf.hasAttribute('data-mangel-entfernen')) {
            if (!window.confirm('Diesen Mangel aus der Liste nehmen?')) { return; }
            knopf.closest('[data-mangel]').remove();
            geaendert(null);
            return;
        }
        if (knopf.hasAttribute('data-maengel-fuellen')) {
            var n = maengelFuellen();
            knopf.textContent = n > 0 ? n + ' ergänzt' : 'nichts zu ergänzen';
            setTimeout(function () { knopf.textContent = 'Aus den Befunden füllen'; }, 2500);
            return;
        }
        if (knopf.hasAttribute('data-pruefen')) { pruefen(knopf); return; }
        if (knopf.hasAttribute('data-unterschrift-auf')) { unterschriftAuf(knopf); return; }
        if (knopf.hasAttribute('data-ewx-suchen')) { ewxSuchen(); return; }
        if (d.ewxVorgang) { ewxVorgang(d.ewxVorgang); return; }
        if (knopf.hasAttribute('data-ewx-neu-laden')) {
            var id = wurzel.querySelector('[data-ewx-verweis]').dataset.ewxId;
            if (id) { reiterZeigen('eventworx'); ewxVorgang(id); }
            return;
        }
        if (knopf.hasAttribute('data-ewx-entfernen')) {
            if (!window.confirm('Verweis auf den Eventworx-Vorgang entfernen? Die Kundendaten bleiben stehen.')) { return; }
            ewx = '-';
            var box = wurzel.querySelector('[data-ewx-verweis]');
            box.hidden = true;
            box.dataset.ewxId = '';
            geaendert(null);
            planen(0);
            return;
        }
        if (knopf.hasAttribute('data-ewx-uebernehmen')) { ewxUebernehmen(); return; }
        if (knopf.hasAttribute('data-dialog-zu') && !knopf.closest('[data-unterschrift-dialog]')) {
            var dlg = knopf.closest('dialog');
            if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
            return;
        }
        if (knopf.hasAttribute('data-quelle-uebernehmen')) { ausFrueherem(); return; }
        if (knopf.hasAttribute('data-erneut')) { gestoppt = false; abgelehnt = null; speichern(); return; }
        if (knopf.hasAttribute('data-neu-laden')) { lokalSofort(); window.location.reload(); return; }
        if (knopf.hasAttribute('data-lokal-uebernehmen')) { lokalLoeschen(AKTUELL); lokalUebernehmen(); return; }
        if (knopf.hasAttribute('data-lokal-verwerfen')) {
            if (!window.confirm('Die Eingaben auf diesem Gerät verwerfen? Es gilt dann der Stand auf dem Server.')) { return; }
            lokalLoeschen();
            lokalGesperrt = false;
            // Was seitdem eingegeben und noch nicht beim Server ist, bleibt gesichert.
            var aktuellNoch = lokalLesen(AKTUELL);
            lokalLoeschen(AKTUELL);
            if (aktuellNoch && typeof aktuellNoch.inhalt === 'string' && zustand() !== bestaetigt) { lokalSchreiben(zustand()); }
            wurzel.querySelector('[data-lokal-leiste]').hidden = true;
        }
    });

    // Auswahl der Zeilen erst beim Öffnen füllen.
    function auswahlFuellen(e) {
        var s = e.target;
        if (!(s instanceof HTMLSelectElement)) { return; }
        if (s.hasAttribute('data-rcd-von')) { kreisOptionen(s, s.closest('[data-zeile]')); }
        if (s.hasAttribute('data-kreis-wahl')) { kreisOptionen(s, null); }
    }
    wurzel.addEventListener('focusin', auswahlFuellen);
    wurzel.addEventListener('pointerdown', auswahlFuellen);

    // Enter springt zum nächsten Feld (der Ziffernblock hat „Weiter“), in der Suche sucht er.
    wurzel.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.isComposing) { return; }
        var el = e.target;
        if (el.matches('[data-ewx-q]')) { e.preventDefault(); ewxSuchen(); return; }
        if (!el.matches('input[data-feld]') || el.type === 'checkbox' || el.type === 'radio') { return; }
        e.preventDefault();
        var alle = Array.prototype.filter.call(wurzel.querySelectorAll('input[data-feld]:not([type=hidden]):not([type=checkbox]):not([type=radio]), select[data-feld], textarea[data-feld]'),
            function (x) { return x.offsetParent !== null; });
        var i = alle.indexOf(el);
        if (i >= 0 && alle[i + 1]) { alle[i + 1].focus(); }
    });

    /*
     * Abmelden: app.js räumt die lokalen Entwürfe weg (geteilte Tablets).
     * Dieser Zuhörer läuft vorher (Capture auf document) und sorgt dafür,
     * dass pagehide und visibilitychange danach nichts zurückschreiben.
     * Ungesichertes geht vorher noch mit keepalive zum Server.
     */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || typeof f.matches !== 'function' || !f.matches('form[action="/logout"]')) { return; }
        if (!gestoppt && !uebernahme && (zustand() !== bestaetigt || ewx !== '')) { einmal({ keepalive: true }); }
        gestoppt = true;
        uebernahme = true;
        lokalGesperrt = true;
        clearTimeout(speicherZeit);
        clearTimeout(lokalZeit);
    }, true);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'hidden' || uebernahme) { return; }
        lokalSofort();
        if (!gestoppt && (zustand() !== bestaetigt || ewx !== '')) { speichern({ keepalive: true }); }
    });
    window.addEventListener('pagehide', function () { if (!uebernahme) { lokalSofort(); } });
    window.addEventListener('online', function () {
        if (!gestoppt && (zustand() !== bestaetigt || ewx !== '')) { speichern(); }
    });
    setInterval(function () {
        if (!gestoppt && !uebernahme && (zustand() !== bestaetigt || ewx !== '')) { speichern(); }
    }, TAKT);
    window.addEventListener('resize', kopfHoehe);

    // ======================================================== Start: lokaler Entwurf?

    function lokalUebernehmen(schluessel) {
        schluessel = schluessel || LOKAL;
        var lokal = lokalLesen(schluessel);
        if (!lokal || typeof lokal.inhalt !== 'string') { return; }
        uebernahme = true;
        wurzel.querySelector('[data-lokal-leiste]').hidden = true;
        stand('laeuft', 'übernimmt Eingaben vom Gerät …');
        speichern({ inhalt: lokal.inhalt, ewx: typeof lokal.ewx === 'string' ? lokal.ewx : '', uebernahme: schluessel }).then(function (ok) {
            if (!ok) { uebernahme = false; }
        });
    }

    /** Baut der Entwurf auf diesem Stand auf? Auch, wenn nur die Antwort auf sein letztes Speichern fehlte. */
    function aufDiesemStand(lokal) {
        return lokal.basis_version === version || (typeof lokal.unsicher === 'string' && lokal.unsicher === bestaetigt && lokal.basis_version === version - 1);
    }

    kopfHoehe();
    bestaetigt = zustand();
    (function () {
        // Gesichert, während ein älterer Entwurf auf die Entscheidung wartete.
        var aktuell = lokalLesen(AKTUELL);
        if (aktuell && typeof aktuell.inhalt === 'string') {
            if (aktuell.inhalt === bestaetigt && !aktuell.ewx) {
                lokalLoeschen(AKTUELL);
            } else if (aufDiesemStand(aktuell)) {
                lokalUebernehmen(AKTUELL);
                return;
            } else {
                // Veraltet: der neuere der beiden Entwürfe wartet auf die Entscheidung.
                var alt = lokalLesen();
                if (!alt || (aktuell.zeit || 0) > (alt.zeit || 0)) {
                    try { window.localStorage.setItem(LOKAL, JSON.stringify(aktuell)); } catch (e) { /* voll oder privat */ }
                }
                lokalLoeschen(AKTUELL);
            }
        }
        var lokal = lokalLesen();
        if (!lokal || typeof lokal.inhalt !== 'string') { return; }
        if (lokal.inhalt === bestaetigt && !lokal.ewx) { lokalLoeschen(); return; }
        if (aufDiesemStand(lokal)) {
            // Auf genau diesem Stand weitergearbeitet, nur nicht angekommen: senden.
            lokalUebernehmen();
            return;
        }
        lokalGesperrt = true;
        var leiste = wurzel.querySelector('[data-lokal-leiste]');
        var zeit = new Date(lokal.zeit || Date.now());
        leiste.querySelector('[data-lokal-zeit]').textContent = zeit.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        leiste.hidden = false;
    })();
    zeilenZahl();
    vorschlagZeigen();
    abschnitteBeobachten();
}());
