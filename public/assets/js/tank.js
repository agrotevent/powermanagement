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
})();
