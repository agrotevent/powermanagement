/**
 * Diagramme der Statistikseite (uPlot).
 *
 * Jedes [data-mp] holt seine Reihe von /statistik/daten – zugeschnitten auf
 * das Datenfenster des Benutzers, das macht der Server. Lücken (null) bleiben
 * Lücken: uPlot zieht keine Linie über einen Zeitraum ohne Freigabe.
 */
(function () {
    'use strict';
    if (typeof uPlot === 'undefined') { return; }

    var einstellung = JSON.parse(document.getElementById('statistik-daten').textContent);
    var FARBEN = { L1: '#8b5a2b', L2: '#111827', L3: '#6b7280', N: '#2563eb', sum: '#b45309' };
    // Zeitachse auf Deutsch, 24 Stunden (uPlot-Tabelle: Abstand, Format, …).
    var ZEITACHSE = [
        [3600 * 24 * 365, '{YYYY}', null, null, null, null, null, null, 1],
        [3600 * 24 * 28, '{MMM}', '\n{YYYY}', null, null, null, null, null, 1],
        [3600 * 24, '{D}.{M}.', '\n{YYYY}', null, null, null, null, null, 1],
        [3600, '{HH}:{mm}', '\n{D}.{M}.{YY}', null, '\n{D}.{M}.', null, null, null, 1],
        [60, '{HH}:{mm}', '\n{D}.{M}.{YY}', null, '\n{D}.{M}.', null, null, null, 1],
        [1, ':{ss}', '\n{D}.{M}.{YY} {HH}:{mm}', null, '\n{D}.{M}. {HH}:{mm}', null, '\n{HH}:{mm}', null, 1],
    ];
    var VERGLEICH = ['#b45309', '#2563eb', '#16a34a', '#7c3aed', '#dc2626', '#0891b2', '#ca8a04', '#db2777'];

    // quelle: eigener Endpunkt im selben Format (etwa die Summe eines Bereichs).
    function adresse(mp, feld, messung, phasen, agg, quelle) {
        var p = new URLSearchParams();
        if (!quelle) { p.set('mp', mp); }
        p.set('feld', feld);
        p.set('messung', messung || 'strom');
        p.set('zeitraum', einstellung.zeitraum);
        if (einstellung.zeitraum === 'frei') { p.set('von', einstellung.von); p.set('bis', einstellung.bis); }
        (phasen || []).forEach(function (ph) { p.append('phase[]', ph); });
        if (agg) { p.set('agg', agg); }
        return (quelle || '/statistik/daten') + '?' + p.toString();
    }

    function breite(el) { return Math.max(280, el.clientWidth); }

    function formatWert(einheit) {
        return function (u, v) { return v === null || v === undefined ? '–' : (window.PM ? window.PM.format(v, einheit, einheit === '%' || einheit === 'Hz' ? 1 : 1) : v); };
    }

    function zeichnen(el, zeiten, serien, einheit, namen, farben, grenze) {
        el.innerHTML = '';
        if (!zeiten.length) {
            el.innerHTML = '<div class="diagramm-leer">Keine Messwerte im gewählten Zeitraum.</div>';
            return;
        }
        var daten = [zeiten.map(function (ms) { return ms / 1000; })];
        var reihen = [{ label: 'Zeit', value: '{DD}.{MM}.{YYYY} {HH}:{mm}' }];
        namen.forEach(function (name, i) {
            daten.push(serien[i]);
            reihen.push({
                label: name,
                stroke: farben[i],
                width: name === 'Summe' ? 2.2 : 1.4,
                spanGaps: false,
                value: formatWert(einheit),
            });
        });
        // Grenzwert als gestrichelte Linie (Anschlussleistung eines Bereichs).
        if (grenze) {
            daten.push(zeiten.map(function () { return grenze; }));
            reihen.push({ label: 'Grenze', stroke: '#dc2626', width: 1.4, dash: [6, 6], value: formatWert(einheit) });
        }
        var plot = new uPlot({
            width: breite(el),
            height: 240,
            series: reihen,
            scales: { x: { time: true } },
            axes: [
                { stroke: '#64748b', grid: { stroke: '#eef2f7' }, values: ZEITACHSE },
                { stroke: '#64748b', grid: { stroke: '#eef2f7' }, size: 70,
                  values: function (u, werte) { return werte.map(function (v) { return window.PM ? window.PM.format(v, einheit, 0) : v; }); } },
            ],
            cursor: { drag: { x: true, y: false } },
            legend: { live: true },
            tzDate: function (ts) { return uPlot.tzDate(new Date(ts * 1000), 'Europe/Berlin'); },
        }, daten, el);
        new ResizeObserver(function () { plot.setSize({ width: breite(el), height: 240 }); }).observe(el);
    }

    document.querySelectorAll('[data-mp]').forEach(function (el) {
        var phasen = (el.dataset.phasen || '').split(',').filter(Boolean);
        el.innerHTML = '<div class="diagramm-leer">Lade …</div>';
        fetch(adresse(el.dataset.mp, el.dataset.feld, el.dataset.messung, phasen, null, el.dataset.quelle), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (a) { return a.json(); })
            .then(function (d) {
                if (d.ok === false && d.message) { el.innerHTML = '<div class="diagramm-leer">' + d.message + '</div>'; return; }
                var namen = [], serien = [], farben = [];
                Object.keys(d.serien).forEach(function (name) {
                    namen.push(name === 'sum' ? 'Summe' : (name === el.dataset.feld ? d.name : name));
                    serien.push(d.serien[name]);
                    farben.push(FARBEN[name] || '#b45309');
                });
                zeichnen(el, d.zeiten, serien, d.einheit, namen, farben, el.dataset.grenze ? Number(el.dataset.grenze) : null);
            })
            .catch(function () { el.innerHTML = '<div class="diagramm-leer">Die Messwerte ließen sich nicht laden.</div>'; });
    });

    // Vergleich mehrerer Messgeräte: Summenleistung übereinander.
    document.querySelectorAll('[data-vergleich]').forEach(function (el) {
        var ids = el.dataset.vergleich.split(',');
        var titel = JSON.parse(el.dataset.titel || '{}');
        Promise.all(ids.map(function (id) {
            return fetch(adresse(id, 'p', 'strom', ['sum']), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (a) { return a.json(); });
        })).then(function (alle) {
            var zeiten = {};
            alle.forEach(function (d) { d.zeiten.forEach(function (t) { zeiten[t] = true; }); });
            var achse = Object.keys(zeiten).map(Number).sort(function (a, b) { return a - b; });
            var serien = alle.map(function (d) {
                var werte = {};
                d.zeiten.forEach(function (t, i) { werte[t] = (d.serien.sum || [])[i]; });
                return achse.map(function (t) { return werte[t] === undefined ? null : werte[t]; });
            });
            zeichnen(el, achse, serien, 'W', ids.map(function (id) { return titel[id] || ('Messpunkt ' + id); }),
                ids.map(function (_, i) { return VERGLEICH[i % VERGLEICH.length]; }));
        });
    });
})();
