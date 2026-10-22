/*
 * Tankseite: Formulare vor Ort, ohne Inline-JS.
 *
 *   [data-peilart]        Was abgelesen wurde (Anzeige %, Inhalt l, Peilstab
 *                         mm) – die Einheit am Wertfeld ([data-peileinheit])
 *                         folgt der Auswahl (data-einheit der Option).
 *   [data-prinzip-wahl]   Welcher Tanksensor eingebaut wird – je nach
 *                         Messprinzip (data-prinzip der Option) das passende
 *                         Einbaumaß: [data-nur-prinzip] bzw.
 *                         [data-ausser-prinzip]; bei Ultraschall ist die
 *                         Höhe der Sensorfläche Pflicht.
 *
 *   [data-tank-tage]      Verbrauch je Tag (Tankseite, Statistik): einmal von
 *                         der Adresse im Attribut geladen, Zeilen und
 *                         Hinweise per textContent. Erkannte Betankungen ohne
 *                         Eintrag bekommen – wenn die Seite die Vorlage
 *                         [data-tage-vorlage] mitbringt (nur mit „bearbeiten“)
 *                         – ein Formular „Als Betankung eintragen“ mit Zeit
 *                         und Menge vorbelegt. Antwortet der Server während
 *                         eines Updates mit 502–504 (Dienste starten neu),
 *                         wird nach einer Pause noch einmal geladen.
 *
 * Eintragen (Peilung, Betankung, Kennlinie) sind gewöhnliche Formulare: Während
 * der Schreibsperre eines Updates antwortet der Server mit Meldung und
 * Weiterleitung, im Kartenpanel (karte-tank.js, JSON) mit 503 und der
 * Meldung – die Eingaben bleiben dort stehen.
 *
 * Ohne Skript stehen alle Felder da; geprüft wird ohnehin auf dem Server.
 * Livewerte und Verlauf kommen von live.js und statistik.js.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-peilart]').forEach(function (auswahl) {
        var form = auswahl.form;
        var einheit = form ? form.querySelector('[data-peileinheit]') : null;
        var feld = form ? form.querySelector('input[name="wert"]') : null;
        var setzen = function () {
            var option = auswahl.selectedOptions[0];
            var text = option && option.dataset.einheit ? option.dataset.einheit : '';
            if (einheit) { einheit.textContent = text ? '(' + text + ')' : ''; }
            if (feld) { feld.placeholder = text === '%' ? 'z. B. 60' : (text === 'mm' ? 'z. B. 650' : 'z. B. 1800'); }
        };
        auswahl.addEventListener('change', setzen);
        setzen();
    });

    document.querySelectorAll('[data-prinzip-wahl]').forEach(function (auswahl) {
        var form = auswahl.form;
        if (!form) { return; }
        var setzen = function () {
            var option = auswahl.selectedOptions[0];
            var prinzip = option && option.dataset.prinzip ? option.dataset.prinzip : '';
            form.querySelectorAll('[data-nur-prinzip]').forEach(function (el) {
                var an = prinzip === el.dataset.nurPrinzip;
                el.hidden = !an;
                el.querySelectorAll('input').forEach(function (i) { i.required = an; if (!an) { i.value = ''; } });
            });
            form.querySelectorAll('[data-ausser-prinzip]').forEach(function (el) {
                var aus = prinzip === el.dataset.ausserPrinzip;
                el.hidden = aus;
                if (aus) { el.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
            });
        };
        auswahl.addEventListener('change', setzen);
        setzen();
    });

    function zelle(zeile, beschriftung, text, klasse) {
        var td = document.createElement('td');
        // Ohne Text auch ohne Beschriftung – sonst stünde auf dem Handy ein leeres „Hinweis“ da.
        if (text !== '') { td.dataset.label = beschriftung; }
        if (klasse) { td.className = klasse; }
        td.textContent = text;
        zeile.appendChild(td);
    }

    var formulare = 0;
    function eintragenFormular(vorlage, b) {
        var kopie = vorlage.content.firstElementChild.cloneNode(true);
        var nr = ++formulare;
        kopie.querySelectorAll('[data-tage-wert]').forEach(function (feld) {
            var name = feld.dataset.tageWert;
            feld.id = 'tage-' + nr + '-' + name;
            if (name === 'zeit') { feld.value = b.eintragen_zeit; }
            if (name === 'liter') { feld.value = b.eintragen_liter; }
        });
        kopie.querySelectorAll('[data-tage-fuer]').forEach(function (label) {
            label.htmlFor = 'tage-' + nr + '-' + label.dataset.tageFuer;
        });
        return kopie;
    }

    document.querySelectorAll('[data-tank-tage]').forEach(function (block) {
        var status = block.querySelector('[data-tage-status]');
        var tabelle = block.querySelector('[data-tage-tabelle]');
        var zeilen = block.querySelector('[data-tage-zeilen]');
        var summe = block.querySelector('[data-tage-summe]');
        var erkanntBlock = block.querySelector('[data-tage-erkannt-block]');
        var erkannt = block.querySelector('[data-tage-erkannt]');
        var schwelle = block.querySelector('[data-tage-schwelle]');
        var vorlage = block.querySelector('template[data-tage-vorlage]');
        var melden = function (text) {
            status.textContent = text;
            status.hidden = text === '';
        };
        // Während eines Updates starten PHP-FPM und nginx neu: 502/503/504
        // (auch eine Fehlerseite ohne JSON) sind dann kein Fehler – gleich
        // noch einmal, immer erst nach der vorigen Antwort, höchstens zehnmal.
        var versuche = 0;
        var laden;
        var nochmal = function (antwort) {
            var warten = parseInt(antwort.headers.get('Retry-After') || '', 10);
            warten = isNaN(warten) ? 15 : Math.min(Math.max(warten, 5), 60);
            versuche++;
            melden('Gerade läuft wohl ein Update – der Verbrauch je Tag kommt gleich.');
            window.setTimeout(laden, warten * 1000);
        };
        laden = function () {
            fetch(block.dataset.tankTage, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (a) {
                    if (a.status >= 502 && a.status <= 504 && versuche < 10) {
                        nochmal(a);
                        return null;
                    }
                    return a.json();
                })
                .then(function (d) {
                    if (d === null) { return; }   // kommt gleich noch einmal
                    // Kundenmodus, gestartet in einem anderen Reiter, während diese
                    // Seite lud (423): neu laden führt zur Kundenseite – wie live.js.
                    if (d && d.kundenmodus === true) {
                        window.location.reload();
                        return;
                    }
                    if (d.ok === false && d.message) {
                        // Als Text: die Meldung kann Teile einer Fehlerantwort der Datenbank enthalten.
                        melden(d.message);
                        return;
                    }
                    var tage = (d.tage || []).slice().reverse();     // jüngster Tag zuerst
                    melden(d.hinweis || (tage.length ? '' : 'Keine Messwerte im Zeitraum.'));
                    if (schwelle && d.schwelle_text) { schwelle.textContent = 'ab ' + d.schwelle_text; }
                    zeilen.replaceChildren();
                    tage.forEach(function (t) {
                        var tr = document.createElement('tr');
                        zelle(tr, 'Tag', t.text, 'nowrap');
                        zelle(tr, 'Verbrauch', t.verbrauch_text, 'num');
                        zelle(tr, 'Getankt', t.betankt_text, 'num');
                        zelle(tr, 'Ø l/h', t.l_h_text, 'num');
                        zelle(tr, 'Hinweis', t.hinweis || '', 'small');
                        zeilen.appendChild(tr);
                    });
                    tabelle.hidden = tage.length === 0;
                    summe.textContent = d.summe && d.summe.text ? d.summe.text : '';
                    summe.hidden = summe.textContent === '';
                    erkannt.replaceChildren();
                    (d.betankungen || []).slice().reverse().forEach(function (b) {
                        if (b.quelle !== 'erkannt') { return; }
                        var li = document.createElement('li');
                        var kopf = document.createElement('div');
                        var stark = document.createElement('strong');
                        stark.textContent = b.zeit_text;
                        kopf.appendChild(stark);
                        kopf.appendChild(document.createTextNode(' · Anstieg ≈ ' + b.erkannt_text));
                        li.appendChild(kopf);
                        if (vorlage && d.darf_eintragen && b.eintragen) {
                            li.appendChild(eintragenFormular(vorlage, b));
                        } else if (vorlage && d.darf_eintragen) {
                            var alt = document.createElement('div');
                            alt.className = 'muted';
                            alt.textContent = 'älter als 7 Tage – lässt sich nicht mehr eintragen';
                            li.appendChild(alt);
                        }
                        erkannt.appendChild(li);
                    });
                    erkanntBlock.hidden = erkannt.children.length === 0;
                })
                .catch(function () { melden('Der Verbrauch je Tag ließ sich nicht laden.'); });
        };
        laden();
    });
})();
