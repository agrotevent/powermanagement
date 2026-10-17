/*
 * Inventar-Formular: weitere Shelly-Zeilen, und die Auswahl „Sitzt an“
 * folgt dem Verteiler-Typ (dessen Abgänge).
 *
 * Beim Tanksensor: nur die Felder, die zu Messprinzip und Signal gehören
 * (data-zeigen-prinzip, data-zeigen-signal), ein Vorschlag für den Wert des
 * Geräts aus dem Kanal (voltmeter/adc → Spannung, input → Analogeingang)
 * und für die Spannung bei 4 mA aus dem Ausgang des Wandlers. Ohne Skript
 * stehen alle Felder da; geprüft wird ohnehin auf dem Server.
 */
(function () {
    'use strict';
    var form = document.getElementById('inventar-form');
    if (!form) { return; }
    var zeilen = form.querySelector('[data-inventar-zeilen]');
    var typ = form.querySelector('[data-stellen-je-typ]');

    function stellenSetzen(select, stellen) {
        var alt = select.value || select.dataset.stelle || '';
        select.innerHTML = '';
        Object.keys(stellen).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k;
            o.textContent = stellen[k];
            if (k === alt) { o.selected = true; }
            select.appendChild(o);
        });
    }

    if (typ) {
        var jeTyp = JSON.parse(typ.dataset.stellenJeTyp || '{}');
        typ.addEventListener('change', function () {
            var stellen = jeTyp[typ.value] || jeTyp[''] || {};
            zeilen.querySelectorAll('select[name="stelle[]"]').forEach(function (s) { stellenSetzen(s, stellen); });
        });
    }

    var dazu = form.querySelector('[data-zeile-dazu]');
    if (dazu) {
        dazu.addEventListener('click', function () {
            var alle = zeilen.querySelectorAll('.inventar-zeile');
            var neu = alle[alle.length - 1].cloneNode(true);
            neu.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            zeilen.appendChild(neu);
            neu.querySelector('input').focus();
        });
    }

    if (!form.hasAttribute('data-tanksensor')) { return; }

    var prinzip = form.querySelector('[data-sensor-prinzip]');
    var feld = form.querySelector('[data-sensor-feld]');
    var signal = form.querySelector('[data-sensor-signal]');
    var einheit = form.querySelector('[data-sensor-einheit]');
    var direkt = form.querySelector('[data-sensor-direkt]');
    var schritt = form.querySelector('[data-sensor-schritt]');
    var kanal = form.querySelector('[data-sensor-kanal]');
    var u4 = form.querySelector('[data-sensor-u4]');
    var u20 = form.querySelector('[data-sensor-u20]');
    var buerde = form.querySelector('#s_buerde');
    var endwert = form.querySelector('[data-wandler-endwert]');
    var leitungsbruch = form.querySelector('[data-leitungsbruch]');
    var schritte = JSON.parse(form.dataset.schritte || '{}');
    var feldVonHand = false;

    function zahl(text) {
        var z = parseFloat(String(text || '').replace(/\s/g, '').replace(',', '.'));
        return isFinite(z) ? z : null;
    }
    function komma(z) { return String(z).replace('.', ','); }

    // Welche Felder gehören zu Prinzip und Signal?
    function zeigen() {
        form.querySelectorAll('[data-zeigen-signal]').forEach(function (el) {
            el.hidden = el.dataset.zeigenSignal.split(' ').indexOf(signal.value) < 0;
        });
        form.querySelectorAll('[data-zeigen-prinzip]').forEach(function (el) {
            el.hidden = el.dataset.zeigenPrinzip.split(' ').indexOf(prinzip.value) < 0;
        });
        if (schritt && schritte[feld.value] !== undefined) { schritt.placeholder = komma(schritte[feld.value]); }
        var v = u4 ? zahl(u4.value) : null;
        if (leitungsbruch) { leitungsbruch.hidden = !(signal.value === '4-20mA-wandler' && v !== null && v < 0.5); }
    }

    // Was zueinander passt: ein fertiger Füllstand kommt direkt, ein
    // Abstand von oben ist Ultraschall, eine Sonde misst in Wassersäule.
    function abstimmen(woher) {
        var f = feld.value;
        var fertig = ['tank_prozent', 'tank_liter', 'x'].indexOf(f) >= 0;
        if (woher === 'feld' && fertig) { prinzip.value = 'direkt'; }
        if (woher === 'feld' && f === 'abstand_mm') { prinzip.value = 'ultraschall'; }
        var direktWert = prinzip.value === 'direkt' || f === 'abstand_mm' || f === 'fuellhoehe_mm';
        if (direktWert) {
            signal.value = 'direkt';
        } else if (signal.value === 'direkt') {
            signal.value = f === 'u' ? '4-20mA-wandler' : '4-20mA';
        }
        if (signal.value === '4-20mA-wandler' && f !== 'u') { signal.value = '4-20mA'; }
        // Zurück auf die Spannung, ohne Bürde eingetragen: wieder der Wandler (Vorgabe).
        if (woher === 'feld' && f === 'u' && signal.value === '4-20mA' && buerde && buerde.value.trim() === '') { signal.value = '4-20mA-wandler'; }
        if (einheit) {
            var hydro = ['mbar', 'mWS', 'mm_wasser'];
            if (prinzip.value === 'hydrostatisch' && hydro.indexOf(einheit.value) < 0) { einheit.value = 'mWS'; }
            if (prinzip.value !== 'hydrostatisch' && einheit.value !== 'mm') { einheit.value = 'mm'; }
        }
        if (direkt && prinzip.value === 'direkt') {
            var passend = { tank_prozent: 'prozent', tank_liter: 'liter', fuellhoehe_mm: 'mm' }[f];
            if (passend) { direkt.value = passend; }
        }
        zeigen();
    }

    prinzip.addEventListener('change', function () { abstimmen('prinzip'); });
    feld.addEventListener('change', function () { feldVonHand = true; abstimmen('feld'); });
    signal.addEventListener('change', zeigen);
    if (u4) { u4.addEventListener('input', zeigen); }

    // Aus dem Kanal den Wert des Geräts vorschlagen – solange niemand ihn selbst gewählt hat.
    if (kanal) {
        kanal.addEventListener('change', function () {
            if (feldVonHand) { return; }
            var k = kanal.value.trim();
            var vorschlag = /^(voltmeter|adc):\d+$/.test(k) ? 'u' : (/^input:\d+$/.test(k) ? 'eingang' : (/^bthome/.test(k) ? 'abstand_mm' : null));
            if (vorschlag && vorschlag !== feld.value) {
                feld.value = vorschlag;
                abstimmen('feld');
            }
        });
    }

    // Ausgang des Wandlers gewählt: 20 mA = Endwert, 4 mA = ein Fünftel davon.
    if (endwert) {
        endwert.addEventListener('change', function () {
            var opt = endwert.selectedOptions[0];
            if (!opt || !opt.value) { return; }
            u20.value = opt.value;
            u4.value = opt.dataset.u4 || '';
            zeigen();
        });
    }

    zeigen();
})();
