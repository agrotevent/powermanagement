/**
 * Livewerte, Schalten und die Auswahl für die Auswertung.
 *
 * Drei Dinge, die auf mehreren Seiten gebraucht werden (Übersicht,
 * Anlagenseite, Karte). Ohne Rahmenwerk: fetch und ein paar Zeilen DOM.
 *
 *   [data-live-url]     alle 5 s neu laden und die Kacheln [data-objekt-id]
 *                       aktualisieren – nur die Zahlen, nicht die Kachel.
 *   [data-schalten]     Schaltbefehl mit Rückfrage; die Freigabe prüft der
 *                       Server bei jedem Klick erneut.
 *   [data-auswertung]   Messgerät in die Auswertung aufnehmen/herausnehmen.
 */
(function () {
    'use strict';

    var PM = window.PM = window.PM || {};

    PM.post = function (url, daten) {
        var koerper = new URLSearchParams();
        koerper.append('_token', window.PM_TOKEN || '');
        Object.keys(daten || {}).forEach(function (k) {
            var v = daten[k];
            if (Array.isArray(v)) {
                v.forEach(function (e) { koerper.append(k + '[]', e); });
            } else if (v !== null && v !== undefined) {
                koerper.append(k, v);
            }
        });
        return fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: koerper,
            credentials: 'same-origin',
        }).then(function (antwort) {
            return antwort.json().catch(function () {
                return { ok: false, message: 'Unerwartete Antwort (' + antwort.status + ').' };
            });
        });
    };

    /** Kurze Meldung unten am Rand. */
    PM.melden = function (text, ok) {
        var box = document.getElementById('pm-meldung');
        if (!box) {
            box = document.createElement('div');
            box.id = 'pm-meldung';
            box.setAttribute('role', 'status');
            document.body.appendChild(box);
        }
        box.textContent = text;
        box.className = ok === false ? 'fehler sichtbar' : 'sichtbar';
        clearTimeout(PM._meldungZeit);
        PM._meldungZeit = setTimeout(function () { box.className = ''; }, 5000);
    };

    PM.format = function (wert, einheit, stellen) {
        if (wert === null || wert === undefined || isNaN(wert)) { return '–'; }
        stellen = stellen === undefined ? 1 : stellen;
        if (['W', 'VA', 'var', 'Wh'].indexOf(einheit) !== -1 && Math.abs(wert) >= 1000) {
            wert = wert / 1000;
            einheit = 'k' + einheit;
            if (Math.abs(wert) >= 1000) { wert = wert / 1000; einheit = 'M' + einheit.substring(1); }
        }
        return wert.toLocaleString('de-DE', { minimumFractionDigits: stellen, maximumFractionDigits: stellen }) + (einheit ? ' ' + einheit : '');
    };

    PM.schalten = function (knopf) {
        if (!window.confirm(knopf.dataset.frage || 'Wirklich schalten?')) { return; }
        knopf.disabled = true;
        knopf.classList.add('knopf-laeuft');
        PM.post('/steuerung/schalten', {
            quelle: knopf.dataset.quelle,
            befehl: knopf.dataset.befehl,
            kanal: knopf.dataset.kanal || '',
            objekt: knopf.dataset.objekt || '',
        }).then(function (erg) {
            PM.melden(erg.message || (erg.ok ? 'Erledigt.' : 'Fehlgeschlagen.'), !!erg.ok);
            if (erg.ok && PM.liveNeu) { setTimeout(PM.liveNeu, 1200); }
        }).catch(function () {
            PM.melden('Keine Verbindung zum Server.', false);
        }).finally(function () {
            knopf.disabled = false;
            knopf.classList.remove('knopf-laeuft');
        });
    };

    PM.auswertung = function (knopf) {
        PM.post('/auswertung/umschalten', { mp: knopf.dataset.auswertung }).then(function (erg) {
            if (!erg.ok) { PM.melden(erg.message || 'Nicht möglich.', false); return; }
            document.querySelectorAll('[data-auswertung="' + knopf.dataset.auswertung + '"]').forEach(function (k) {
                k.classList.toggle('aktiv', erg.enthalten);
                k.setAttribute('aria-pressed', erg.enthalten ? 'true' : 'false');
                var text = k.querySelector('[data-auswertung-text]');
                if (text) { text.textContent = erg.enthalten ? 'in der Auswertung' : 'zur Auswertung'; }
            });
            var zahl = document.querySelector('[data-auswertung-zahl]');
            if (zahl) { zahl.textContent = erg.anzahl; }
            PM.melden(erg.message, true);
        });
    };

    document.addEventListener('click', function (e) {
        var schalter = e.target.closest('[data-schalten]');
        if (schalter) { e.preventDefault(); PM.schalten(schalter); return; }
        var auswahl = e.target.closest('[data-auswertung]');
        if (auswahl) { e.preventDefault(); PM.auswertung(auswahl); }
    });

    // --------------------------------------------------------------- Kacheln
    function kachelSetzen(kachel, l) {
        // Die eigene Klasse behalten (Kachel, Tabellenzeile, Baumkarte) –
        // nur die Stufe wechselt.
        var basis = kachel.dataset.basis;
        if (basis === undefined) {
            basis = kachel.className.replace(/\s*\bstufe-\S+/g, '');
            kachel.dataset.basis = basis;
        }
        kachel.className = basis + ' stufe-' + (l.stufe || 'keine');
        var p = kachel.querySelector('[data-feld="p"]');
        if (p) { p.textContent = PM.format(l.p, 'W'); }
        var a = kachel.querySelector('[data-feld="auslastung"]');
        if (a) { a.textContent = l.auslastung !== null && l.auslastung !== undefined ? Math.round(l.auslastung) + ' %' : ''; }
        var groesster = 0;
        Object.keys(l.i || {}).forEach(function (k) { groesster = Math.max(groesster, l.i[k]); });
        ['L1', 'L2', 'L3'].forEach(function (phase) {
            var zeile = kachel.querySelector('[data-phase="' + phase + '"]');
            if (!zeile) { return; }
            var i = l.i ? l.i[phase] : undefined;
            zeile.hidden = i === undefined;
            if (i === undefined) { return; }
            zeile.querySelector('[data-strom]').textContent = PM.format(i, 'A');
            var u = l.u ? l.u[phase] : undefined;
            zeile.querySelector('[data-spannung]').textContent = u !== undefined ? PM.format(u, 'V', 0) : '';
            var balken = zeile.querySelector('[data-balken]');
            if (balken) {
                var breite = l.auslastung !== null && l.auslastung !== undefined && groesster > 0
                    ? Math.min(100, Math.max(2, i / groesster * l.auslastung)) : 0;
                balken.style.width = breite + '%';
            }
        });
        var z = kachel.querySelector('[data-feld="zustand"]');
        if (z) {
            var teile = [];
            var zst = l.zustand || {};
            if (zst.soc !== undefined) { teile.push('SOC ' + Math.round(zst.soc) + ' %'); }
            if (zst.tank_prozent !== undefined) { teile.push('Tank ' + Math.round(zst.tank_prozent) + ' %'); }
            if (zst.drehzahl !== undefined) { teile.push('U/min ' + Math.round(zst.drehzahl)); }
            if (zst.motorzustand !== undefined) { teile.push(String(zst.motorzustand)); }
            if (zst.zustand_text !== undefined) { teile.push(String(zst.zustand_text)); }
            z.innerHTML = '';
            teile.forEach(function (t) {
                var chip = document.createElement('span');
                chip.className = 'chip';
                chip.textContent = t;
                z.appendChild(chip);
            });
        }
        var h = kachel.querySelector('[data-feld="hinweis"]');
        if (h) { h.textContent = l.hinweis || ''; }
        // Messpunkte in Listen (Anlagenseite): Leistung und Schaltzustand
        Object.keys(l.messpunkte || {}).forEach(function (id) {
            var m = l.messpunkte[id];
            document.querySelectorAll('[data-mp-wert="' + id + '"]').forEach(function (el) {
                el.textContent = m.online ? PM.format(m.p, 'W') + (m.i_max !== null ? ' · ' + PM.format(m.i_max, 'A') : '') : 'keine Werte';
            });
            document.querySelectorAll('[data-mp-schalter="' + id + '"]').forEach(function (el) {
                el.textContent = m.schalter === null ? '' : (m.schalter ? 'ein' : 'aus');
                el.className = 'badge ' + (m.schalter === null ? '' : (m.schalter ? 'ok' : 'bad'));
            });
        });
    }

    var liveQuelle = document.querySelector('[data-live-url]');
    // Der Stand steht in einem eigenen Feld – trägt ein ganzer Kopf die
    // Adresse (Anlagenseite), darf er nicht vom Text überschrieben werden.
    var liveStand = liveQuelle ? liveQuelle.querySelector('[data-live-stand]') : null;
    if (liveQuelle && !liveStand) {
        if (liveQuelle.children.length) {
            liveStand = document.createElement('span');
            liveStand.className = 'small muted';
            liveStand.setAttribute('data-live-stand', '');
            liveQuelle.appendChild(liveStand);
        } else {
            liveStand = liveQuelle;
        }
    }
    if (liveQuelle) {
        PM.liveNeu = function () {
            if (document.hidden) { return; }
            fetch(liveQuelle.dataset.liveUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (a) { return a.json(); })
                .then(function (daten) {
                    // Sitzung abgelaufen: der Server antwortet 401 mit Meldung (JSON).
                    if (daten && daten.ok === false) {
                        liveStand.textContent = daten.message || 'Livewerte gerade nicht erreichbar – neuer Versuch in 5 Sekunden.';
                        return;
                    }
                    Object.keys(daten.objekte || {}).forEach(function (id) {
                        document.querySelectorAll('[data-objekt-id="' + id + '"]').forEach(function (kachel) {
                            kachelSetzen(kachel, daten.objekte[id]);
                        });
                    });
                    // Bereiche: Kachel wie bei einer Anlage, dazu die Mitglieder.
                    Object.keys(daten.bereiche || {}).forEach(function (id) {
                        var b = daten.bereiche[id];
                        document.querySelectorAll('[data-bereich-id="' + id + '"]').forEach(function (kachel) {
                            kachelSetzen(kachel, b);
                        });
                        Object.keys(b.mitglieder || {}).forEach(function (schluessel) {
                            var m = b.mitglieder[schluessel];
                            document.querySelectorAll('[data-mitglied="' + schluessel + '"]').forEach(function (zeile) {
                                var p = zeile.querySelector('[data-feld="p"]');
                                if (p) { p.textContent = PM.format(m.p, 'W'); }
                                var a = zeile.querySelector('[data-feld="anteil"]');
                                if (a) { a.textContent = m.anteil !== null && m.anteil !== undefined ? Math.round(m.anteil) + ' %' : ''; }
                                var st = zeile.querySelector('[data-feld="status"]');
                                if (st) { st.textContent = m.status === 'ok' ? '' : m.status; }
                            });
                        });
                    });
                    var uhr = new Date((daten.ts || Date.now() / 1000) * 1000);
                    liveStand.textContent = 'Stand ' + uhr.toLocaleTimeString('de-DE') + ' – aktualisiert alle 5 Sekunden.';
                })
                .catch(function () {
                    liveStand.textContent = 'Livewerte gerade nicht erreichbar – neuer Versuch in 5 Sekunden.';
                });
        };
        setInterval(PM.liveNeu, 5000);
        // Die Seite kommt schon mit Werten; der erste Abruf folgt trotzdem
        // gleich, damit alles, was nur das Skript füllt, nicht 5 s leer bleibt.
        setTimeout(PM.liveNeu, 400);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { PM.liveNeu(); } });
    }
})();
