/*
 * Geräteprüfung: Prüfberichte unterschreiben – einzeln oder gesammelt.
 *
 * Der Knopf [data-gp-unterschreiben] nimmt die Prüfungen aus data-gp-ids
 * (kommagetrennt) oder die angehakten input[data-gp-auswahl] der Liste und
 * öffnet den Unterschreiben-Dialog (unterschrift.js). Eine Unterschrift
 * deckt den ganzen Durchgang: Die Auswahl geht in Stücken zu höchstens 25
 * nacheinander hinaus, jedes mit denselben Strichen und derselben Kennung
 * (`anfrage`) – der Server legt so nichts doppelt an, auch nicht beim
 * Neuversuch nach einem Netzfehler. Antwortet er 503 mit `wartung` (ein
 * Update läuft), bleibt alles stehen und dasselbe Stück geht nach einer
 * Pause noch einmal hinaus. Prüfart und „Verantwortung übernehmen“ gelten für
 * den ganzen Durchgang.
 *
 * Gespeichert wird nur der Linienzug (unterschrift.js) – keine Zeit, kein
 * Druck. Texte aus Antworten nur per textContent.
 */
(function () {
    'use strict';

    var STUECK = 25;
    var ZEIT = 120000;            // je Stück: bis zu 25 Berichte samt PDF
    var WARTUNG_PAUSE = 30000;    // Retry-After der Schreibsperre eines Updates

    var bereich = document.querySelector('[data-gp-bereich]');
    if (!bereich || !window.PMUnterschrift || !window.PMNetz) { return; }
    var knopf = bereich.querySelector('[data-gp-unterschreiben]');
    var stand = bereich.querySelector('[data-gp-stand]');
    var fehlerListe = bereich.querySelector('[data-gp-fehler]');
    var dlgEl = bereich.querySelector('[data-unterschrift-dialog]');
    if (!knopf || !dlgEl) { return; }

    var ids = [];
    var laufend = null;
    var fertig = false;

    var dialog = window.PMUnterschrift.dialog(dlgEl, {
        senden: durchgang,
        abbrechen: function () { if (laufend && laufend.abbrechen) { laufend.abbrechen(); } laufend = null; }
    });

    function feld(name) { return bereich.querySelector('[data-gp-feld="' + name + '"]'); }

    function auswahl() {
        if (knopf.dataset.gpIds) {
            return knopf.dataset.gpIds.split(',').filter(function (x) { return /^\d+$/.test(x); });
        }
        return Array.prototype.filter.call(document.querySelectorAll('input[data-gp-auswahl]'), function (c) { return c.checked; })
            .map(function (c) { return c.value; }).filter(function (x) { return /^\d+$/.test(x); });
    }

    /** Kurzer Fingerabdruck der Striche (FNV-1a) – dieselben Striche, dieselbe Kennung. */
    function kurz(text) {
        var h = 0x811c9dc5;
        for (var i = 0; i < text.length; i++) {
            h ^= text.charCodeAt(i);
            h = Math.imul(h, 0x01000193) >>> 0;
        }
        return ('0000000' + h.toString(16)).slice(-8);
    }

    /** Die ersten Fehler als Text (Prüfung: Meldung). */
    function fehlerText(liste) {
        var teile = liste.slice(0, 5).map(function (f) { return 'Prüfung ' + String(f.id) + ': ' + String(f.message || ''); });
        return teile.join(' · ') + (liste.length > 5 ? ' · und ' + (liste.length - 5) + ' weitere' : '');
    }

    function fehlerZeigen(liste) {
        while (fehlerListe.firstChild) { fehlerListe.removeChild(fehlerListe.firstChild); }
        liste.forEach(function (f) {
            var li = document.createElement('li');
            li.textContent = 'Prüfung ' + String(f.id) + ': ' + String(f.message || '');
            fehlerListe.appendChild(li);
        });
        fehlerListe.hidden = liste.length === 0;
    }

    knopf.addEventListener('click', function () {
        ids = auswahl();
        if (ids.length === 0) {
            stand.textContent = 'Bitte zuerst Prüfungen anhaken.';
            return;
        }
        fertig = false;
        stand.textContent = ids.length === 1 ? '1 Prüfung ausgewählt.' : ids.length + ' Prüfungen ausgewählt.';
        dialog.oeffnen();
    });

    // Nach einem Durchgang mit Rest: beim Schließen neu laden (Liste und Stände frisch).
    dlgEl.addEventListener('close', function () {
        if (fertig) { window.location.reload(); }
    });

    function durchgang(werte) {
        var striche = dialog.daten();
        var name = 'gp:' + kurz(striche);
        var schluessel = window.PMUnterschrift.schluessel(name);
        var pruefart = feld('pruefart');
        var uebernehmen = feld('uebernehmen');
        var stuecke = [];
        for (var i = 0; i < ids.length; i += STUECK) { stuecke.push(ids.slice(i, i + STUECK)); }
        var ergebnis = { unterschrieben: [], fehler: [], hinweise: [] };
        dialog.laeuft(true);
        fehlerZeigen([]);

        function stueck(n) {
            if (n >= stuecke.length) { abschluss(); return; }
            dialog.meldung(stuecke.length > 1 ? 'unterschreibt … Teil ' + (n + 1) + ' von ' + stuecke.length : 'unterschreibt …', false);
            var daten = new FormData();
            daten.append('_token', window.PM_TOKEN || '');
            daten.append('ids', JSON.stringify(stuecke[n].map(Number)));
            daten.append('striche', striche);
            daten.append('name', werte.name || '');
            daten.append('ort', werte.ort || '');
            daten.append('pruefart', pruefart ? pruefart.value : '');
            daten.append('uebernehmen', uebernehmen && uebernehmen.checked ? '1' : '0');
            daten.append('anfrage', schluessel);
            laufend = window.PMNetz.post('/geraetepruefung/unterschreiben', daten, ZEIT);
            laufend.then(function (r) {
                laufend = null;
                var erg = r.erg || {};
                if (r.status === 200) {
                    (erg.unterschrieben || []).forEach(function (id) { ergebnis.unterschrieben.push(id); });
                    (erg.fehler || []).forEach(function (f) { ergebnis.fehler.push(f); });
                    (erg.hinweise || []).forEach(function (h) { ergebnis.hinweise.push(h); });
                    stueck(n + 1);
                    return;
                }
                if (r.status === 503 && erg.wartung) {
                    // Ein Update läuft: alles bleibt stehen, dasselbe Stück geht gleich noch einmal hinaus.
                    dialog.meldung('Gerade läuft ein Update – die Unterschrift bleibt stehen und wird in etwa einer halben Minute noch einmal gesendet.', false);
                    var uhr = window.setTimeout(function () {
                        laufend = null;
                        if (dlgEl.hasAttribute('open')) { stueck(n); } else { dialog.laeuft(false); }
                    }, WARTUNG_PAUSE);
                    laufend = { abbrechen: function () { window.clearTimeout(uhr); } };
                    return;
                }
                if (r.status === 423) { window.location.reload(); return; }
                dialog.laeuft(false);
                if (r.status === 401) {
                    dialog.meldung('Abgemeldet – bitte neu anmelden; es ist nichts doppelt unterschrieben.', true);
                    return;
                }
                if (r.status === 409) { window.PMUnterschrift.schluesselLoeschen(name); }
                fehlerZeigen(ergebnis.fehler);
                dialog.meldung(String(erg.message || 'Nicht unterschrieben.') + (ergebnis.unterschrieben.length > 0
                    ? ' Schon unterschrieben: ' + ergebnis.unterschrieben.length + '.' : '')
                    + (ergebnis.fehler.length > 0 ? ' Nicht unterschrieben: ' + fehlerText(ergebnis.fehler) : ''), true);
                if (ergebnis.unterschrieben.length > 0) { fertig = true; }
            }, function () {
                laufend = null;
                dialog.laeuft(false);
                dialog.meldung('Keine Verbindung zum Server. Bitte noch einmal „Unterschreiben und einfrieren“ – es wird nichts doppelt angelegt.', true);
            });
        }

        function abschluss() {
            // Mit Rest bleibt die Kennung: ein zweites „Unterschreiben“ mit denselben
            // Strichen zählt das schon Unterschriebene als erledigt.
            if (ergebnis.fehler.length === 0) { window.PMUnterschrift.schluesselLoeschen(name); }
            var n = ergebnis.unterschrieben.length;
            var text = (n === 1 ? '1 Prüfbericht' : n + ' Prüfberichte') + ' unterschrieben.';
            if (ergebnis.fehler.length === 0 && ergebnis.hinweise.length === 0) {
                dialog.meldung(text + ' Lädt …', false);
                window.location.reload();
                return;
            }
            dialog.laeuft(false);
            fertig = true;
            fehlerZeigen(ergebnis.fehler);
            dialog.meldung(text + (ergebnis.fehler.length > 0 ? ' Nicht unterschrieben: ' + fehlerText(ergebnis.fehler) : '')
                + (ergebnis.hinweise.length > 0 ? ' Hinweis: ' + ergebnis.hinweise.join(' ') : '') + ' Schließen lädt die Seite neu.', ergebnis.fehler.length > 0);
        }

        stueck(0);
    }
}());
