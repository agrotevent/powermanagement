/**
 * Kundenmodus: der Kunde bestätigt am Gerät des Prüfers den Erhalt des
 * Berichts und unterschreibt (berichte/kunde.php).
 *
 * „Bericht erhalten“ ist Pflicht, erst dann öffnet die Unterschrift
 * (unterschrift.js). Gesendet wird mit einem Idempotenzschlüssel: geht die
 * Antwort im schwachen Netz verloren, schickt „Unterschreiben“ dieselbe
 * Anfrage noch einmal, und der Server legt keine zweite Zeile an. Danach
 * lädt die Seite neu und zeigt „Bitte geben Sie das Gerät zurück“.
 *
 * Ein Autosave gibt es hier nicht. Texte aus Antworten nur per textContent.
 */
(function () {
    'use strict';

    var wurzel = document.querySelector('[data-kunde]');
    if (!wurzel || !window.PMUnterschrift) { return; }
    var berichtId = wurzel.dataset.kunde;
    var ADRESSE = '/berichte/' + berichtId + '/kunde';
    var SCHLUESSEL = 'kunde:' + berichtId;
    var meldung = wurzel.querySelector('[data-kunde-meldung]');
    var weiter = wurzel.querySelector('[data-kunde-weiter]');
    var dlg = wurzel.querySelector('[data-unterschrift-dialog]');
    if (!weiter || !dlg) { return; }

    function zeigen(text, fehler) {
        meldung.textContent = text;
        meldung.classList.toggle('fehler', !!fehler);
    }

    function bestaetigungen() {
        return Array.prototype.filter.call(wurzel.querySelectorAll('[data-bestaetigung]'), function (c) { return c.checked; })
            .map(function (c) { return c.value; });
    }

    /** Die erste nicht gesetzte Pflicht-Bestätigung – oder null. */
    function pflichtOffen() {
        return Array.prototype.filter.call(wurzel.querySelectorAll('[data-bestaetigung][data-pflicht]'), function (c) { return !c.checked; })[0] || null;
    }

    function neuLaden(text) {
        unterschrift.meldung(text + ' Lädt …', false);
        window.setTimeout(function () { window.location.href = ADRESSE; }, 900);
    }

    var unterschrift = window.PMUnterschrift.dialog(dlg, { senden: senden });

    wurzel.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-bestaetigung][data-pflicht]') && e.target.checked) { zeigen('', false); }
    });

    weiter.addEventListener('click', function () {
        var offen = pflichtOffen();
        if (offen) {
            var text = offen.closest('label') ? offen.closest('label').textContent.replace(/\s*\(Pflicht\)\s*$/, '').trim() : 'Bericht erhalten';
            zeigen('Bitte zuerst bestätigen: „' + text + '“.', true);
            offen.focus();
            return;
        }
        zeigen('', false);
        unterschrift.oeffnen();
    });

    function senden(werte) {
        var daten = new FormData();
        daten.append('_token', window.PM_TOKEN || '');
        daten.append('striche', unterschrift.daten());
        daten.append('name', werte.name || '');
        daten.append('funktion', werte.funktion || '');
        daten.append('ort', werte.ort || '');
        daten.append('vorbehalte', (wurzel.querySelector('[data-kunde-vorbehalte]') || { value: '' }).value);
        bestaetigungen().forEach(function (b) { daten.append('bestaetigungen[]', b); });
        daten.append('anfrage', window.PMUnterschrift.schluessel(SCHLUESSEL));
        daten.append('geraet_am', new Date().toISOString());

        unterschrift.laeuft(true);
        unterschrift.meldung('wird gesendet …', false);
        fetch(ADRESSE + '/unterschreiben', { method: 'POST', body: daten, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (antwort) {
                return antwort.json().then(function (erg) { return { status: antwort.status, erg: erg || {} }; },
                    function () { return { status: antwort.status, erg: { ok: false, message: 'Unerwartete Antwort (' + antwort.status + ').' } }; });
            }, function () { return { status: 0, erg: { ok: false, message: 'Keine Verbindung zum Server.' } }; })
            .then(function (r) {
                if (r.status === 200 && r.erg.ok) {
                    window.PMUnterschrift.schluesselLoeschen(SCHLUESSEL);
                    neuLaden('Vielen Dank – unterschrieben.');
                    return;
                }
                unterschrift.laeuft(false);
                if (r.status === 0) {
                    unterschrift.meldung('Keine Verbindung. Bitte noch einmal „Unterschreiben“ – es wird nichts doppelt angelegt.', true);
                    return;
                }
                if (r.status === 401 || r.status === 409 || r.status === 423) {
                    // Abgemeldet, schon unterschrieben oder nicht mehr offen: die Seite zeigt, wie es steht.
                    unterschrift.laeuft(true);
                    neuLaden(r.erg.message || 'Der Bericht ist nicht mehr zur Unterschrift offen.');
                    return;
                }
                if (r.status === 419) {
                    unterschrift.meldung((r.erg.message || 'Sicherheitstoken abgelaufen.') + ' Bitte das Gerät an den Prüfer zurückgeben.', true);
                    return;
                }
                unterschrift.meldung(r.erg.message || 'Nicht unterschrieben – bitte noch einmal versuchen.', true);
            });
    }
}());
