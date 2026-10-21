/**
 * Übergabebericht (berichte/show.php): das gespeicherte PDF teilen –
 * navigator.share mit der Datei, etwa an die Mail-App des Tablets.
 *
 * Das gibt es nur in einem sicheren Kontext (HTTPS) und nur, wo der
 * Browser Dateien teilen kann; sonst bleibt der Knopf verborgen und
 * „Herunterladen“ der Weg. Die Datei wird beim Laden der Seite geholt:
 * navigator.share verlangt eine frische Geste, ein Laden erst beim Tippen
 * wäre in Safari zu spät. Texte nur per textContent.
 */
(function () {
    'use strict';

    var knopf = document.querySelector('[data-teilen]');
    if (!knopf || !window.isSecureContext || !navigator.share || !navigator.canShare || typeof File !== 'function' || !window.fetch) { return; }
    var meldung = document.querySelector('[data-teilen-meldung]');
    var datei = null;

    function sagen(text) {
        if (meldung) { meldung.textContent = text; }
    }

    fetch(knopf.getAttribute('data-teilen'), { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })
        .then(function (antwort) {
            var typ = antwort.headers.get('Content-Type') || '';
            if (!antwort.ok || typ.indexOf('application/pdf') !== 0) { throw new Error('kein PDF'); }
            return antwort.blob();
        })
        .then(function (blob) {
            var f = new File([blob], knopf.getAttribute('data-name') || 'Bericht.pdf', { type: 'application/pdf' });
            if (!navigator.canShare({ files: [f] })) { return; }
            datei = f;
            knopf.hidden = false;
        })
        .catch(function () { /* Teilen bleibt verborgen – Herunterladen geht weiter. */ });

    knopf.addEventListener('click', function () {
        if (!datei) { return; }
        sagen('');
        navigator.share({ files: [datei], title: knopf.getAttribute('data-titel') || '' })
            .catch(function (fehler) {
                if (fehler && fehler.name === 'AbortError') { return; }
                sagen('Teilen hat nicht geklappt – bitte „Herunterladen“ nehmen.');
            });
    });
}());
