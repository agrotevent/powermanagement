/**
 * System → Update: Fortschritt eines laufenden Updates abfragen.
 *
 *   [data-update-seite]   Wurzel (Karte „Fortschritt“) mit data-stand-url,
 *                         data-installiert (Stand beim Laden) und data-zustand
 *
 * Nur bei „angefordert“ und „läuft“ wird abgefragt, alle 2 s, immer erst
 * nach der vorigen Antwort. Während des Updates starten die Dienste neu:
 * Netzfehler, 502/503/504 oder eine Antwort ohne JSON (Fehlerseite) sind
 * dann kein Fehler – es geht in größerem Abstand weiter. 423 (Kundenmodus
 * in einem anderen Reiter) lädt neu – das führt zur Kundenseite. Texte nur
 * per textContent; Suchen und Starten sind gewöhnliche Formulare.
 */
(function () {
    'use strict';

    var wurzel = document.querySelector('[data-update-seite]');
    if (!wurzel || !window.fetch) { return; }

    var url = wurzel.dataset.standUrl || '/system/update/stand';
    var installiert = wurzel.dataset.installiert || '';
    var protokollStand = wurzel.dataset.protokollStand || '';
    var zustand = wurzel.dataset.zustand || '';

    var feld = function (name) { return wurzel.querySelector('[data-update-' + name + ']'); };
    var text = feld('text');
    var schritt = feld('schritt');
    var schrittName = feld('schrittname');
    var balken = feld('balken');
    var laufzeit = feld('laufzeit');
    var hinweis = feld('hinweis');
    var protokoll = feld('protokoll');
    var konsole = feld('konsole');

    var ABSTAND = 2000;
    var ABSTAND_NEUSTART = 5000;
    var GEDULD = 20 * 60 * 1000;

    var zeitgeber = null;
    var laeuft = false;
    var beendet = false;
    var stoerungSeit = null;

    // Das Protokoll folgt den neuen Zeilen, solange man nicht selbst
    // hochgescrollt hat – also beim Laden einmal ans Ende.
    if (protokoll) { protokoll.scrollTop = protokoll.scrollHeight; }

    function aktiv(z) { return z === 'angefordert' || z === 'laeuft'; }

    function dauer(sekunden) {
        if (sekunden === null || sekunden === undefined) { return ''; }
        var m = Math.floor(sekunden / 60);
        var s = sekunden % 60;
        return m + ':' + (s < 10 ? '0' : '') + s + ' min';
    }

    function hinweisZeigen(inhalt) {
        if (!hinweis) { return; }
        while (hinweis.firstChild) { hinweis.removeChild(hinweis.firstChild); }
        if (inhalt === null) { hinweis.hidden = true; return; }
        if (typeof inhalt === 'string') {
            hinweis.textContent = inhalt;
        } else {
            hinweis.appendChild(inhalt);
        }
        hinweis.hidden = false;
    }

    function satz(daten) {
        var lauf = daten.lauf;
        if (daten.zustand === 'angefordert') {
            return daten.haengt ? 'Der Auftrag wird nicht übernommen – der Update-Dienst reagiert nicht.'
                : 'Auftrag liegt bereit – der Update-Dienst übernimmt ihn gleich.';
        }
        if (daten.zustand === 'laeuft') {
            var was = lauf && lauf.aktion === 'pruefen' ? 'Suche läuft …'
                : (lauf && lauf.aktion === 'wiederaufnehmen' ? 'Ein unterbrochenes Update wird zurückgerollt …' : 'Update läuft …');
            return (daten.keine_rueckmeldung ? 'Keine Rückmeldung vom Update-Dienst seit ' + dauer(lauf ? lauf.puls_alter : null) + ' – ' : '') + was;
        }
        if (daten.verschwunden) {
            return 'Der Update-Dienst meldet sich seit über 30 Minuten nicht mehr – ein unterbrochenes Update rollt er beim nächsten Start zurück.';
        }
        if (lauf && lauf.ergebnis) {
            var t = lauf.ergebnis_text + (lauf.grund_text ? '. ' + lauf.grund_text : '');
            if (lauf.alt && lauf.neu && lauf.alt !== lauf.neu) { t += ' (' + lauf.alt + ' → ' + lauf.neu + ')'; }
            return t;
        }
        return 'Noch kein Lauf.';
    }

    function zeigen(daten) {
        var lauf = daten.lauf;
        if (text) { text.textContent = satz(daten); }
        var imLauf = daten.zustand === 'laeuft' && lauf;
        if (schritt) { schritt.textContent = imLauf ? lauf.schritt + ' / ' + lauf.gesamt : ''; }
        if (schrittName) { schrittName.textContent = imLauf ? (lauf.schritt_name || '') : ''; }
        if (laufzeit) { laufzeit.textContent = lauf && lauf.dauer !== null ? dauer(lauf.dauer) : ''; }
        if (balken) {
            var prozent = 0;
            if (imLauf) {
                prozent = Math.round(100 * Math.min(lauf.schritt, lauf.gesamt) / Math.max(1, lauf.gesamt));
            } else if (lauf && !aktiv(daten.zustand)) {
                prozent = 100;
            }
            balken.classList.toggle('unbestimmt', daten.zustand === 'angefordert');
            balken.classList.toggle('ok', !aktiv(daten.zustand) && daten.zustand === 'fertig');
            balken.classList.toggle('bad', !aktiv(daten.zustand) && daten.zustand === 'fehler');
            balken.setAttribute('aria-valuenow', String(prozent));
            var span = balken.querySelector('span');
            if (span) { span.style.width = prozent + '%'; }
        }
        if (protokoll && daten.protokoll) {
            protokollStand = daten.protokoll.stand || protokollStand;
            if (typeof daten.protokoll.zeilen === 'string') {
                var unten = protokoll.scrollTop + protokoll.clientHeight >= protokoll.scrollHeight - 8;
                protokoll.textContent = daten.protokoll.zeilen;
                protokoll.hidden = daten.protokoll.zeilen === '';
                if (unten) { protokoll.scrollTop = protokoll.scrollHeight; }
            }
        }
        if (konsole) { konsole.hidden = !(daten.zustand === 'fehler' || daten.verschwunden || daten.haengt); }
    }

    function ende(daten) {
        beendet = true;
        var lauf = daten.lauf || {};
        var neu = daten.installiert && daten.installiert !== installiert;
        var neuLaut = lauf.neu_voll && lauf.neu_voll !== installiert
            && (lauf.ergebnis === 'eingespielt' || lauf.ergebnis === 'eingespielt_mit_warnung');
        // Neuer Stand: neue Skripte und Stile – einmal neu laden. Nach einer
        // Suche zeigt die Seite so den neuen Stand.
        if ((installiert !== '' && (neu || neuLaut)) || lauf.aktion === 'pruefen') {
            hinweisZeigen(lauf.aktion === 'pruefen' ? 'Suche fertig – die Seite wird neu geladen …' : 'Neuer Stand – die Seite wird neu geladen …');
            window.setTimeout(function () { window.location.reload(); }, lauf.aktion === 'pruefen' ? 1500 : 3000);
        }
    }

    function planen(abstand) {
        window.clearTimeout(zeitgeber);
        if (beendet || document.hidden) { return; }
        zeitgeber = window.setTimeout(abfragen, abstand);
    }

    function stoerung() {
        var jetzt = Date.now();
        if (stoerungSeit === null) { stoerungSeit = jetzt; }
        if (jetzt - stoerungSeit > GEDULD) {
            hinweisZeigen('Seit über 20 Minuten keine Antwort vom Server. Bitte auf der Konsole nachsehen (Befehle unten).');
            if (konsole) { konsole.hidden = false; }
        } else {
            hinweisZeigen('Dienste starten neu – Verbindung kommt gleich wieder …');
        }
        planen(ABSTAND_NEUSTART);
    }

    function abgemeldet() {
        beendet = true;
        var p = document.createElement('span');
        p.appendChild(document.createTextNode('Sitzung abgelaufen – '));
        var a = document.createElement('a');
        a.href = '/login';
        a.textContent = 'neu anmelden';
        p.appendChild(a);
        hinweisZeigen(p);
    }

    function abfragen() {
        if (laeuft || beendet) { return; }
        laeuft = true;
        var abbruch = window.AbortController ? new AbortController() : null;
        var frist = abbruch ? window.setTimeout(function () { abbruch.abort(); }, 10000) : null;
        fetch(url + (protokollStand ? '?stand=' + encodeURIComponent(protokollStand) : ''), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: abbruch ? abbruch.signal : undefined,
        }).then(function (antwort) {
            if (antwort.status === 401 || antwort.status === 419 || (antwort.redirected && /\/login/.test(antwort.url))) {
                abgemeldet();
                return null;
            }
            // Kundenmodus, gestartet in einem anderen Reiter (423): neu laden
            // führt zur Kundenseite, statt hier weiter den Stand zu zeigen.
            if (antwort.status === 423) {
                beendet = true;
                window.location.reload();
                return null;
            }
            var art = antwort.headers.get('Content-Type') || '';
            if (!antwort.ok || art.indexOf('application/json') === -1) {
                stoerung();
                return null;
            }
            return antwort.json().then(function (daten) {
                stoerungSeit = null;
                hinweisZeigen(null);
                zeigen(daten);
                zustand = daten.zustand;
                if (aktiv(daten.zustand)) {
                    planen(ABSTAND);
                } else {
                    ende(daten);
                }
            });
        }).catch(function () {
            stoerung();
        }).then(function () {
            if (frist !== null) { window.clearTimeout(frist); }
            laeuft = false;
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearTimeout(zeitgeber);
        } else if (!beendet && aktiv(zustand)) {
            planen(0);
        }
    });

    if (aktiv(zustand)) {
        planen(ABSTAND);
    }
}());
