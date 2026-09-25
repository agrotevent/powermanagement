/*
 * Inventar-Formular: weitere Shelly-Zeilen, und die Auswahl „Sitzt an“
 * folgt dem Verteiler-Typ (dessen Abgänge).
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
})();
