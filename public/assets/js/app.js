// Kleine Helfer ohne Framework.

/*
 * PMNetz.post(adresse, FormData, ms[, { keepalive }]): POST mit Zeitgrenze.
 *
 * fetch kennt von sich aus keine Zeitgrenze – eine hängende Anfrage (Funkloch,
 * Proxy, der nicht antwortet) blockierte Autosave und Unterschrift sonst
 * minutenlang. Nach `ms` wird abgebrochen, und es geht weiter wie bei einem
 * Netzfehler (das Versprechen wird abgelehnt). Ergebnis: { status, erg } mit
 * der gelesenen JSON-Antwort. `.abbrechen()` am Versprechen bricht von Hand
 * ab (etwa „Abbrechen“ im Unterschriftsdialog).
 */
window.PMNetz = {
    post: function (adresse, daten, ms, optionen) {
        var steuerung = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var abbrechen = function () { if (steuerung) { steuerung.abort(); } };
        var uhr = window.setTimeout(abbrechen, ms || 15000);
        var anfrage = { method: 'POST', body: daten, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (steuerung) { anfrage.signal = steuerung.signal; }
        if (optionen && optionen.keepalive) { anfrage.keepalive = true; }
        var p = fetch(adresse, anfrage).then(function (antwort) {
            return antwort.json().then(function (erg) {
                return { status: antwort.status, erg: erg || {} };
            }, function (fehler) {
                if (steuerung && steuerung.signal.aborted) { throw fehler; }
                return { status: antwort.status, erg: { ok: false, message: 'Unerwartete Antwort des Servers (' + antwort.status + ').' } };
            });
        }).then(function (r) {
            window.clearTimeout(uhr);
            return r;
        }, function (fehler) {
            window.clearTimeout(uhr);
            throw fehler;
        });
        p.abbrechen = abbrechen;
        return p;
    }
};

/*
 * Kundenmodus in einem anderen Tab gestartet (bericht-kunde.js meldet es per
 * BroadcastChannel, ersatzweise über localStorage): dieser Tab zeigt dann
 * nichts mehr von dem, was er geladen hat – etwa Kundendaten eines anderen
 * Berichts –, sondern geht zur Kundenseite. Der Server sperrt jede neue
 * Anfrage ohnehin; hier geht es um das, was schon auf dem Bildschirm steht.
 */
(function () {
    function sperren(nachricht) {
        var id = nachricht && /^\d+$/.test(String(nachricht.bericht)) ? String(nachricht.bericht) : '';
        if (id === '') { return; }
        var ziel = '/berichte/' + id + '/kunde';
        if (window.location.pathname === ziel) { return; }
        var inhalt = document.querySelector('main') || document.body;
        if (inhalt) {
            while (inhalt.firstChild) { inhalt.removeChild(inhalt.firstChild); }
            var hinweis = document.createElement('p');
            hinweis.className = 'notice';
            hinweis.textContent = 'Kundenmodus aktiv – dieses Gerät ist gesperrt.';
            inhalt.appendChild(hinweis);
        }
        window.location.replace(ziel);
    }
    try {
        if (typeof window.BroadcastChannel === 'function') {
            new window.BroadcastChannel('pm-kundenmodus').onmessage = function (e) { sperren(e.data); };
        }
    } catch (e) { /* ohne BroadcastChannel bleibt localStorage */ }
    window.addEventListener('storage', function (e) {
        if (e.key !== 'pm-kundenmodus' || !e.newValue) { return; }
        try { sperren(JSON.parse(e.newValue)); } catch (x) { /* kaputt: nichts tun */ }
    });
}());

document.addEventListener('DOMContentLoaded', function () {
    // Navigation auf Tablet/Smartphone ein- und ausblenden.
    var navToggle = document.getElementById('nav-toggle');
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.getElementById('nav-backdrop');
    if (navToggle && sidebar) {
        var setNav = function (open) {
            sidebar.classList.toggle('open', open);
            if (backdrop) { backdrop.classList.toggle('show', open); }
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        navToggle.addEventListener('click', function () {
            setNav(!sidebar.classList.contains('open'));
        });
        if (backdrop) { backdrop.addEventListener('click', function () { setNav(false); }); }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { setNav(false); }
        });
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () { setNav(false); });
        });
    }

    /*
     * Abmelden räumt die lokalen Entwürfe der Übergabeberichte weg
     * (pm-bericht:<id>:<benutzer>): Tablets werden geteilt, und was hier
     * noch stünde, sähe der Nächste nach seiner Anmeldung nicht – es läge
     * aber im Speicher des Geräts.
     */
    document.querySelectorAll('form[action="/logout"]').forEach(function (formular) {
        formular.addEventListener('submit', function () {
            try {
                for (var i = window.localStorage.length - 1; i >= 0; i--) {
                    var schluessel = window.localStorage.key(i);
                    if (schluessel && schluessel.indexOf('pm-bericht:') === 0) { window.localStorage.removeItem(schluessel); }
                }
            } catch (e) { /* privater Modus: nichts gespeichert */ }
        });
    });

    // Port-Kacheln: Klick markiert die Checkbox – oder das Optionsfeld.
    document.querySelectorAll('.port input[type=checkbox], .port input[type=radio]').forEach(function (box) {
        var tile = box.closest('.port');
        var sync = function () { tile.classList.toggle('selected', box.checked); };
        box.addEventListener('change', function () {
            // Bei Optionsfeldern verlieren die übrigen der Gruppe ihre
            // Markierung, ohne dass dort ein Ereignis ankommt.
            if (box.type === 'radio' && box.name) {
                document.querySelectorAll('.port input[type=radio][name="' + box.name + '"]').forEach(function (other) {
                    var otherTile = other.closest('.port');
                    if (otherTile) { otherTile.classList.toggle('selected', other.checked); }
                });
                return;
            }
            sync();
        });
        sync();
    });

    // "Alle auswählen" in Tabellen und Port-Gittern. Mit data-toggle-name
    // nur die Kästchen dieses Namens (eine Spalte, etwa „sehen[]“).
    document.querySelectorAll('[data-toggle-all]').forEach(function (master) {
        master.addEventListener('change', function () {
            var scope = document.querySelector(master.dataset.toggleAll);
            if (!scope) { return; }
            scope.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(function (box) {
                if (master.dataset.toggleName && box.name !== master.dataset.toggleName) { return; }
                box.checked = master.checked;
                box.dispatchEvent(new Event('change'));
            });
        });
    });

    /*
     * Auswahl per Knopf: `data-auswahl-an="<Selektor>"` hakt die passenden
     * Felder an (etwa „alle neuen“ im Katalog-Import), `data-auswahl-aus`
     * nimmt die Haken weg. `data-auswahl-zahl="<Selektor>"` zeigt, wie viele
     * davon angehakt sind.
     */
    var zaehler = document.querySelectorAll('[data-auswahl-zahl]');
    var zaehlen = function () {
        zaehler.forEach(function (feld) {
            var boxen = document.querySelectorAll(feld.dataset.auswahlZahl);
            var an = 0;
            boxen.forEach(function (box) { if (box.checked) { an++; } });
            feld.textContent = an + ' von ' + boxen.length + ' ausgewählt';
        });
    };
    document.querySelectorAll('[data-auswahl-an], [data-auswahl-aus]').forEach(function (knopf) {
        knopf.addEventListener('click', function () {
            var an = knopf.hasAttribute('data-auswahl-an');
            document.querySelectorAll(an ? knopf.dataset.auswahlAn : knopf.dataset.auswahlAus).forEach(function (box) {
                if (box.disabled || box.checked === an) { return; }
                box.checked = an;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            });
            zaehlen();
        });
    });
    if (zaehler.length) {
        document.addEventListener('change', function (ereignis) {
            if (ereignis.target && ereignis.target.type === 'checkbox') { zaehlen(); }
        });
        zaehlen();
    }

    // Sicherheitsabfragen.
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('submit', function (event) {
            if (!window.confirm(el.dataset.confirm)) { event.preventDefault(); }
        });
        el.addEventListener('click', function (event) {
            if (el.tagName !== 'BUTTON') { return; }
            /*
             * Ein Knopf *in* einem Formular wurde hier übersprungen –
             * die Rückfrage fiel dann stillschweigend aus. Bei einem
             * Knopf, der löscht, ist das der gefährlichste Fall
             * überhaupt: alles sieht aus wie vorgesehen, nur fragt
             * niemand. Trägt das Formular selbst schon ein
             * `data-confirm`, bleibt es bei dessen Rückfrage – sonst
             * käme sie zweimal.
             */
            if (el.form !== null && el.form.hasAttribute('data-confirm')) { return; }
            if (!window.confirm(el.dataset.confirm)) { event.preventDefault(); }
        });
    });

    // Auth-Modus: Felder passend ein-/ausblenden.
    var authMode = document.querySelector('[data-auth-mode]');
    if (authMode) {
        var apply = function () {
            var mode = authMode.value;
            document.querySelectorAll('[data-when-password]').forEach(function (el) {
                el.style.display = (mode === 'password' || mode === 'both') ? '' : 'none';
            });
            document.querySelectorAll('[data-when-key]').forEach(function (el) {
                el.style.display = (mode === 'key' || mode === 'both') ? '' : 'none';
            });
        };
        authMode.addEventListener('change', apply);
        apply();
    }

    /*
     * Knöpfe, hinter denen etwas dauert.
     *
     * „Auf Updates prüfen" braucht gemessen 683 ms für die Anfrage allein,
     * über eine langsame Leitung ein Vielfaches. Solange passierte
     * sichtbar nichts, und man klickte noch einmal. Und noch einmal.
     *
     * Der erste Anlauf war zu einfach gedacht: Knopf beim Absenden sperren
     * und einen Streifen einblenden. Das kommt nie aufs Bild – gemessen
     * wirft Chromium das alte Dokument beim Absenden eines Formulars
     * sofort weg, samt aller Änderungen daran.
     *
     * Also wird nicht navigiert. Das Formular geht per fetch hinaus, die
     * Seite bleibt stehen und zeigt, dass gearbeitet wird; erst danach
     * wird neu geladen. Ohne JavaScript bleibt es beim gewöhnlichen
     * Formular mit Weiterleitung – langsam, aber nie kaputt.
     */
    document.querySelectorAll('form[data-laeuft]').forEach(function (formular) {
        formular.addEventListener('submit', function (ereignis) {
            /*
             * Hat die Rückfrage (`data-confirm`) abgebrochen, wird nichts
             * abgeschickt – dann gehört auch kein Balken hierher.
             * `preventDefault` hält andere Zuhörer nicht auf, also muss
             * hier nachgesehen werden.
             */
            if (ereignis.defaultPrevented) { return; }

            /*
             * Der gedrückte Knopf – ein Formular kann mehrere haben
             * („Übernehmen“ und „Nur verknüpfen“). FormData nimmt seinen
             * Namen und Wert nicht mit; ohne sie käme beim Server die
             * falsche Aktion an. Gesperrt werden alle, sonst ginge der
             * andere Knopf während der Wartezeit noch hinaus.
             */
            var knoepfe = formular.querySelectorAll('button:not([type=button])');
            var knopf = ereignis.submitter && ereignis.submitter.form === formular ? ereignis.submitter : knoepfe[0];
            if (!knopf || knopf.disabled) { return; }

            ereignis.preventDefault();

            var daten = new FormData(formular);
            if (knopf.name) { daten.append(knopf.name, knopf.value); }
            knoepfe.forEach(function (k) { k.disabled = true; });
            var vorher = knopf.textContent;
            knopf.textContent = knopf.dataset.laeuft || formular.dataset.laeuft || 'läuft …';

            var anzeige = document.createElement('div');
            anzeige.className = 'knopf-laeuft';

            /*
             * Der Hilfetext daneben erklärt, was der Knopf tut. Während
             * er es tut, sagt der Hinweis darunter dasselbe – zweimal
             * derselbe Satz liest sich wie ein Fehler.
             */
            var hilfe = formular.querySelector('.help');
            if (hilfe) { hilfe.hidden = true; }

            var balken = document.createElement('div');
            balken.className = 'progress gross unbestimmt';
            balken.setAttribute('role', 'progressbar');
            balken.setAttribute('aria-label', formular.dataset.laeuft || 'läuft');
            balken.appendChild(document.createElement('span'));

            var text = document.createElement('div');
            text.className = 'small muted';
            text.setAttribute('role', 'status');
            text.textContent = formular.dataset.laeuftHinweis
                || 'Das dauert einen Moment. Bitte nicht noch einmal klicken.';

            anzeige.appendChild(balken);
            anzeige.appendChild(text);
            knopf.insertAdjacentElement('afterend', anzeige);

            fetch(formular.action, {
                method: (formular.method || 'post').toUpperCase(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: daten,
                /*
                 * Der Umleitung wird hier NICHT gefolgt. Täte sie es,
                 * holte der Browser die Zielseite im Hintergrund – und
                 * die verbraucht die Meldung („Hochgeladen: …"), noch
                 * bevor jemand sie sieht. Das anschließende Neuladen
                 * zeigte dann eine Seite ohne jede Rückmeldung; genau
                 * das stand hier, bis es jemandem auffiel.
                 */
                redirect: 'manual',
            }).then(function (antwort) {
                // Was zurückkommt, zeigt die neu geladene Seite. Hier
                // zählt nur, dass es durch ist. Eine abgefangene
                // Umleitung kommt als undurchsichtige Antwort (Status 0)
                // zurück – das ist der Normalfall und kein Fehler.
                if (!antwort.ok && antwort.status >= 500) {
                    throw new Error('HTTP ' + antwort.status);
                }
                /*
                 * Neu laden stellt die Scrollposition wieder her. Steht
                 * der Knopf weit unten und die Meldung ganz oben, bleibt
                 * sie ungesehen – gemessen auf den Einstellungen: 918 px
                 * tief, die Meldung 833 px über dem sichtbaren Bereich.
                 * Wer das nicht will, setzt `data-laeuft-oben`.
                 */
                if (formular.hasAttribute('data-laeuft-oben') && 'scrollRestoration' in window.history) {
                    window.history.scrollRestoration = 'manual';
                }
                window.location.reload();
            }).catch(function (fehler) {
                knoepfe.forEach(function (k) { k.disabled = false; });
                knopf.textContent = vorher;
                if (hilfe) { hilfe.hidden = false; }
                balken.remove();
                text.className = 'small';
                text.textContent = 'Das hat nicht geklappt: ' + (fehler && fehler.message ? fehler.message : fehler)
                    + ' – noch einmal versuchen.';
            });
        });
    });

    /*
     * Eigenes Gerät oder Fremdgerät.
     *
     * Bei einem Fremdgerät sind Zugangsdaten sinnlos – es wird nie
     * angesprochen. Sie stehen zu lassen wäre eine Einladung, sie
     * auszufüllen, und danach zu suchen, warum nichts passiert. Die
     * Pflicht auf der Adresse fällt mit weg: ohne sie ließe sich das
     * Formular sonst gar nicht abschicken.
     */
    var eigentum = document.querySelector('[data-eigentum]');
    if (eigentum) {
        var nachfuehrenEigentum = function () {
            var fremd = eigentum.value === 'foreign';
            document.querySelectorAll('[data-nur-fremd]').forEach(function (feld) {
                feld.hidden = !fremd;
            });
            document.querySelectorAll('[data-nur-eigen]').forEach(function (feld) {
                feld.hidden = fremd;
            });
            document.querySelectorAll('[data-eigen-pflicht]').forEach(function (feld) {
                feld.required = !fremd;
            });
        };
        eigentum.addEventListener('change', nachfuehrenEigentum);
        nachfuehrenEigentum();
    }

    /*
     * Menüleiste schmal stellen.
     *
     * Die Klasse setzt schon der Kopf der Seite (siehe layout/app.php),
     * damit nichts springt; hier wird nur noch umgeschaltet und gemerkt.
     */
    var menueSchalter = document.getElementById('menue-schalter');
    if (menueSchalter) {
        var nachfuehrenMenue = function () {
            var schmal = document.documentElement.classList.contains('menue-schmal');
            menueSchalter.setAttribute('aria-pressed', schmal ? 'true' : 'false');
            menueSchalter.setAttribute('aria-label', schmal ? 'Menü ausklappen' : 'Menü schmaler stellen');
        };
        menueSchalter.addEventListener('click', function () {
            var schmal = document.documentElement.classList.toggle('menue-schmal');
            try {
                localStorage.setItem('menue', schmal ? 'schmal' : 'breit');
            } catch (e) { /* privater Modus: gilt dann nur für diese Seite */ }
            nachfuehrenMenue();
            // Leaflet muss wissen, dass es mehr Platz hat – sonst bleibt
            // der Grundriss in der alten Breite stehen.
            window.dispatchEvent(new Event('resize'));
        });
        nachfuehrenMenue();
    }

    /*
     * Gruppen im Menü auf- und zuklappen.
     *
     * Gemerkt wird, welche offen sind – nicht, welche zu sind. Kommt
     * später eine Gruppe dazu, ist sie damit zugeklappt und macht die
     * Leiste nicht ungefragt wieder länger.
     */
    var gruppen = document.querySelectorAll('[data-nav-gruppe]');
    if (gruppen.length > 0) {
        var merkeGruppen = function () {
            var offen = [];
            gruppen.forEach(function (gruppe) {
                if (gruppe.classList.contains('offen')) {
                    offen.push(gruppe.dataset.navGruppe);
                }
            });
            try {
                localStorage.setItem('menue-gruppen', JSON.stringify(offen));
            } catch (e) { /* privater Modus: gilt dann nur für diese Seite */ }
        };

        gruppen.forEach(function (gruppe) {
            var titel = gruppe.querySelector('.nav-group-title');
            if (!titel) { return; }
            titel.addEventListener('click', function () {
                var auf = gruppe.classList.toggle('offen');
                titel.setAttribute('aria-expanded', auf ? 'true' : 'false');
                merkeGruppen();
            });
        });
    }

    // Auswahlfelder mit Wirkung – als data-Attribut statt onchange="…": die
    // Content-Security-Policy lässt nur Skripte aus Dateien zu.
    // data-absenden: das Formular beim Ändern gleich absenden (Filter).
    document.querySelectorAll('[data-absenden]').forEach(function (el) {
        el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
    });
    // data-neu-laden="art": die Seite mit ?art=<Wert> neu laden (plus data-neu-laden-zusatz).
    document.querySelectorAll('[data-neu-laden]').forEach(function (el) {
        el.addEventListener('change', function () {
            var parameter = new URLSearchParams(el.dataset.neuLadenZusatz || '');
            parameter.set(el.dataset.neuLaden, el.value);
            window.location.search = '?' + parameter.toString();
        });
    });
    // data-fest-von-vorlage: „fest“ übernimmt der gewählte Katalog-Typ.
    document.querySelectorAll('[data-fest-von-vorlage]').forEach(function (el) {
        el.addEventListener('change', function () {
            var option = el.selectedOptions[0];
            var fest = el.form ? el.form.querySelector('#fest') : null;
            if (option && fest && option.dataset.fest !== undefined) { fest.checked = option.dataset.fest === '1'; }
        });
    });

    // data-auswaehlen: ein Feld zum Kopieren (Token) beim Antippen ganz markieren.
    document.querySelectorAll('[data-auswaehlen]').forEach(function (feld) {
        ['focus', 'click'].forEach(function (art) {
            feld.addEventListener(art, function () { if (feld.select) { feld.select(); } });
        });
    });

    // data-kopieren="#id": Text des Elements (bei Eingabefeldern ihr Wert) in
    // die Zwischenablage. Ohne sichere Verbindung (http im lokalen Netz) gibt
    // es navigator.clipboard nicht – dann markieren und den alten Weg nehmen.
    document.querySelectorAll('[data-kopieren]').forEach(function (knopf) {
        knopf.addEventListener('click', function () {
            var quelle = document.querySelector(knopf.dataset.kopieren);
            if (!quelle) { return; }
            var feld = quelle.tagName === 'INPUT' || quelle.tagName === 'TEXTAREA';
            var text = feld ? quelle.value : quelle.textContent;
            var gemeldet = function () {
                var vorher = knopf.textContent;
                knopf.textContent = 'kopiert';
                window.setTimeout(function () { knopf.textContent = vorher; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(gemeldet);
                return;
            }
            if (feld) {
                quelle.focus();
                quelle.select();
            } else {
                var bereich = document.createRange();
                bereich.selectNodeContents(quelle);
                var auswahl = window.getSelection();
                auswahl.removeAllRanges();
                auswahl.addRange(bereich);
            }
            try {
                if (document.execCommand('copy')) { gemeldet(); }
            } catch (e) { /* markiert ist es – dann von Hand kopieren */ }
        });
    });

    // Laufende Aufträge alle 4 Sekunden nachladen.
    var live = document.querySelector('[data-job-live]');
    if (live) {
        setInterval(function () {
            fetch(live.dataset.jobLive, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.job && ['success', 'failed', 'partial', 'cancelled'].indexOf(data.job.status) !== -1) {
                        window.location.reload();
                    }
                })
                .catch(function () { /* still */ });
        }, 4000);
    }
});
