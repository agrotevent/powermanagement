/**
 * Unterschrift erfassen – eigener Code ohne Bibliothek.
 *
 * Gespeichert wird nur die Linie: Striche als Listen von Punkten, jeder
 * Punkt zwei ganze Zahlen 0..10000 (x bezogen auf die Breite, y auf die
 * Höhe einer Fläche 3:1). Keine Zeit, kein Druck, keine Geschwindigkeit –
 * Bibliotheken wie signature_pad liefern genau das mit, deshalb dieser
 * Code. Der Server prüft dasselbe noch einmal (Unterschrift::pruefen).
 *
 *   var pad = PMUnterschrift.an(flaeche, { geaendert: function () { … } });
 *   pad.leer(), pad.gueltig(), pad.daten(), pad.loeschen(), pad.rueckgaengig(), pad.zeichnen()
 *   var d = PMUnterschrift.dialog(dialogElement, { senden: function (werte) { … } });  // Vollbild-Dialog
 *
 * Eingabe über Pointer Events mit setPointerCapture; wurde einmal ein Stift
 * erkannt, zählt kein Finger mehr (Handballen auf dem Tablet). Die Striche
 * liegen als Vektoren vor und werden nach Größenänderung und Drehung neu
 * gezeichnet – geglättet über quadratische Kurven durch die Mittelpunkte.
 *
 * PMUnterschrift.schluessel(name) liefert den Idempotenzschlüssel einer
 * Unterschrift (sessionStorage): ein Neuversuch nach verlorener Antwort
 * sendet denselben, der Server legt dann keine zweite Zeile an.
 */
(function () {
    'use strict';

    var RASTER = 10000;
    var MIN_ABSTAND = 20;
    var MAX_STRICHE = 200;
    var MAX_PUNKTE = 6000;
    var MIN_BREITE = 1000;
    var MIN_HOEHE = 500;
    var MIN_LAENGE = 3000;

    function begrenzen(w) { return Math.max(0, Math.min(RASTER, Math.round(w))); }

    /** Abstand in Breiten-Einheiten – die Fläche ist dreimal so breit wie hoch. */
    function abstand(a, b) {
        var dx = a[0] - b[0];
        var dy = (a[1] - b[1]) / 3;
        return Math.sqrt(dx * dx + dy * dy);
    }

    function an(element, optionen) {
        var o = optionen || {};
        var canvas = element.tagName === 'CANVAS' ? element : element.querySelector('canvas');
        var ctx = canvas.getContext('2d');
        var striche = [];
        var punkte = 0;
        var aktiv = null;       // pointerId des laufenden Strichs
        var strich = null;
        var stift = false;      // einmal Stift erkannt: Finger zählen nicht mehr
        var geplant = false;

        function geaendert() {
            element.classList.toggle('hat-striche', striche.length > 0);
            if (typeof o.geaendert === 'function') { o.geaendert(); }
        }

        function groesse() {
            var b = canvas.clientWidth;
            var h = canvas.clientHeight;
            var dpr = window.devicePixelRatio || 1;
            if (b === 0 || h === 0) { return false; }
            if (canvas.width !== Math.round(b * dpr) || canvas.height !== Math.round(h * dpr)) {
                canvas.width = Math.round(b * dpr);
                canvas.height = Math.round(h * dpr);
            }
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            return true;
        }

        function zeichnen() {
            if (!groesse()) { return; }
            var b = canvas.clientWidth;
            var h = canvas.clientHeight;
            ctx.clearRect(0, 0, b, h);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.lineWidth = Math.max(1.6, b / 380);
            ctx.strokeStyle = window.getComputedStyle(canvas).color || '#1e3a8a';
            ctx.fillStyle = ctx.strokeStyle;
            var x = function (p) { return p[0] / RASTER * b; };
            var y = function (p) { return p[1] / RASTER * h; };
            striche.forEach(function (s) {
                if (s.length === 1) {
                    ctx.beginPath();
                    ctx.arc(x(s[0]), y(s[0]), ctx.lineWidth / 2, 0, Math.PI * 2);
                    ctx.fill();
                    return;
                }
                ctx.beginPath();
                ctx.moveTo(x(s[0]), y(s[0]));
                for (var i = 1; i < s.length - 1; i++) {
                    ctx.quadraticCurveTo(x(s[i]), y(s[i]), (x(s[i]) + x(s[i + 1])) / 2, (y(s[i]) + y(s[i + 1])) / 2);
                }
                ctx.lineTo(x(s[s.length - 1]), y(s[s.length - 1]));
                ctx.stroke();
            });
        }

        function planen() {
            if (geplant) { return; }
            geplant = true;
            window.requestAnimationFrame(function () { geplant = false; zeichnen(); });
        }

        function punkt(e) {
            var r = canvas.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) { return null; }
            return [begrenzen((e.clientX - r.left) / r.width * RASTER), begrenzen((e.clientY - r.top) / r.height * RASTER)];
        }

        function zaehlt(e) {
            if (e.pointerType === 'pen') { stift = true; }
            if (stift && e.pointerType === 'touch') { return false; }
            return !(e.pointerType === 'mouse' && e.button !== 0);
        }

        canvas.addEventListener('pointerdown', function (e) {
            if (aktiv !== null || !zaehlt(e) || striche.length >= MAX_STRICHE || punkte >= MAX_PUNKTE) { return; }
            var p = punkt(e);
            if (!p) { return; }
            e.preventDefault();
            aktiv = e.pointerId;
            try { canvas.setPointerCapture(e.pointerId); } catch (x) { /* ältere Browser */ }
            strich = [p];
            striche.push(strich);
            punkte++;
            planen();
        });

        canvas.addEventListener('pointermove', function (e) {
            if (e.pointerId !== aktiv || strich === null) { return; }
            e.preventDefault();
            var liste = typeof e.getCoalescedEvents === 'function' ? e.getCoalescedEvents() : [];
            if (liste.length === 0) { liste = [e]; }
            liste.forEach(function (ev) {
                if (punkte >= MAX_PUNKTE) { return; }
                var p = punkt(ev);
                if (p && abstand(p, strich[strich.length - 1]) >= MIN_ABSTAND) {
                    strich.push(p);
                    punkte++;
                }
            });
            planen();
        });

        function ende(e) {
            if (e.pointerId !== aktiv) { return; }
            aktiv = null;
            strich = null;
            try { canvas.releasePointerCapture(e.pointerId); } catch (x) { /* schon frei */ }
            planen();
            geaendert();
        }
        canvas.addEventListener('pointerup', ende);
        canvas.addEventListener('pointercancel', ende);
        // Kein Scrollen, Zoomen oder Kontextmenü auf der Fläche (touch-action: none im CSS).
        canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });

        if ('ResizeObserver' in window) {
            new ResizeObserver(planen).observe(canvas);
        } else {
            window.addEventListener('resize', planen);
            window.addEventListener('orientationchange', planen);
        }

        return {
            leer: function () { return striche.length === 0; },
            /** Dieselbe Mindestgröße wie auf dem Server: Rahmen 10 % × 5 %, Länge 3000. */
            gueltig: function () {
                var minX = RASTER, minY = RASTER, maxX = 0, maxY = 0, laenge = 0;
                striche.forEach(function (s) {
                    s.forEach(function (p, i) {
                        minX = Math.min(minX, p[0]); maxX = Math.max(maxX, p[0]);
                        minY = Math.min(minY, p[1]); maxY = Math.max(maxY, p[1]);
                        if (i > 0) { laenge += abstand(p, s[i - 1]); }
                    });
                });
                return maxX - minX >= MIN_BREITE && maxY - minY >= MIN_HOEHE && laenge >= MIN_LAENGE;
            },
            daten: function () { return JSON.stringify({ s: striche }); },
            loeschen: function () { striche = []; punkte = 0; planen(); geaendert(); },
            rueckgaengig: function () {
                var weg = striche.pop();
                if (weg) { punkte -= weg.length; }
                planen();
                geaendert();
            },
            zeichnen: zeichnen
        };
    }

    // ---------------------------------------------------- Dialog

    /**
     * Der Vollbild-Dialog (berichte/_unterschrift-dialog.php): Fläche, „Letzten
     * Strich zurück“, „Löschen“, Angaben zur Person. „Unterschreiben“ ist erst
     * aktiv, wenn die Unterschrift groß genug ist und die Pflichtfelder
     * stehen; o.senden(werte) bekommt die Angaben (name, ort …).
     */
    function dialog(el, optionen) {
        var o = optionen || {};
        var flaeche = el.querySelector('[data-unterschrift-flaeche]');
        var senden = el.querySelector('[data-unterschrift-senden]');
        var zurueck = el.querySelector('[data-unterschrift-zurueck]');
        var loeschen = el.querySelector('[data-unterschrift-loeschen]');
        var stand = el.querySelector('[data-unterschrift-stand]');
        var meldungFeld = el.querySelector('[data-unterschrift-meldung]');
        var pad = null;
        var laeuft = false;

        function werte() {
            var w = {};
            el.querySelectorAll('[data-unterschrift-feld]').forEach(function (f) { w[f.dataset.unterschriftFeld] = f.value.trim(); });
            return w;
        }

        function fehlt() {
            if (!pad || pad.leer()) { return 'Bitte im Feld unterschreiben.'; }
            if (!pad.gueltig()) { return 'Die Unterschrift ist noch zu klein – bitte größer unterschreiben.'; }
            var leer = Array.prototype.filter.call(el.querySelectorAll('[data-unterschrift-feld][required]'), function (f) {
                return f.value.trim().length < 2;
            })[0];
            if (leer) {
                var label = el.querySelector('label[for="' + leer.id + '"]');
                return 'Bitte „' + (label ? label.textContent.replace(/\s*\*$/, '') : 'Name') + '“ ausfüllen.';
            }
            return '';
        }

        function aktualisieren() {
            var grund = fehlt();
            var nichts = !pad || pad.leer();
            senden.disabled = laeuft || grund !== '';
            zurueck.disabled = laeuft || nichts;
            loeschen.disabled = laeuft || nichts;
            stand.textContent = laeuft ? 'wird gesendet …' : (grund !== '' ? grund : 'Bereit zum Unterschreiben.');
        }

        function schliessen() {
            if (typeof el.close === 'function') { el.close(); } else { el.removeAttribute('open'); }
        }

        el.addEventListener('input', aktualisieren);
        el.addEventListener('click', function (e) {
            var k = e.target.closest ? e.target.closest('button') : null;
            if (!k || laeuft) { return; }
            if (k.hasAttribute('data-unterschrift-zurueck')) { pad.rueckgaengig(); }
            if (k.hasAttribute('data-unterschrift-loeschen')) { pad.loeschen(); }
            if (k.hasAttribute('data-dialog-zu')) { schliessen(); }
            if (k.hasAttribute('data-unterschrift-senden') && fehlt() === '' && typeof o.senden === 'function') { o.senden(werte()); }
        });

        return {
            oeffnen: function () {
                if (typeof el.showModal === 'function') {
                    if (!el.open) { el.showModal(); }
                } else {
                    el.setAttribute('open', '');
                }
                if (!pad) { pad = an(flaeche, { geaendert: aktualisieren }); }
                meldungFeld.textContent = '';
                window.requestAnimationFrame(function () { pad.zeichnen(); aktualisieren(); });
            },
            schliessen: schliessen,
            daten: function () { return pad ? pad.daten() : ''; },
            werte: werte,
            meldung: function (text, fehler) {
                meldungFeld.textContent = text;
                meldungFeld.classList.toggle('fehler', !!fehler);
            },
            laeuft: function (an) { laeuft = !!an; aktualisieren(); }
        };
    }

    // ---------------------------------------------------- Idempotenzschlüssel

    var imSpeicher = {};

    function neuerSchluessel() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
        var b = new Uint8Array(16);
        window.crypto.getRandomValues(b);
        return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    function schluessel(name) {
        var k = 'pm-unterschrift:' + name;
        try {
            var da = window.sessionStorage.getItem(k);
            if (da) { return da; }
            var neu = neuerSchluessel();
            window.sessionStorage.setItem(k, neu);
            return neu;
        } catch (e) {
            if (!imSpeicher[k]) { imSpeicher[k] = neuerSchluessel(); }
            return imSpeicher[k];
        }
    }

    function schluesselLoeschen(name) {
        var k = 'pm-unterschrift:' + name;
        delete imSpeicher[k];
        try { window.sessionStorage.removeItem(k); } catch (e) { /* privater Modus */ }
    }

    window.PMUnterschrift = { an: an, dialog: dialog, schluessel: schluessel, schluesselLoeschen: schluesselLoeschen };
}());
