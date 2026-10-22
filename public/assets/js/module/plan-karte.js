/**
 * Die Karte hinter den Grundriss-Ansichten – übernommen aus MasterAdmin,
 * damit beide Werkzeuge denselben Plan gleich zeigen.
 *
 * Grundrisse wurden vorher als ein großes Bild in einen Rahmen gelegt und
 * die Dosen als Prozentwerte darüber gestapelt. Das hatte zwei Haken: ein
 * 8000 Pixel breiter Hallenplan ruckelte, und die Prozentwerte bezogen
 * sich auf den Rahmen statt auf den Plan – sobald der Rahmen höher war als
 * das Bild, wanderte jeder Marker beim Zoomen nach oben.
 *
 * Jetzt macht das Leaflet: die Karte rechnet in Plankoordinaten, nicht in
 * Bildschirmpixeln. Ein Marker sitzt auf einem Punkt des Plans und bleibt
 * dort – ob der Plan zwanzigfach vergrößert ist oder auf Briefmarkengröße.
 * Gezeichnet wird in Kacheln, damit auch ein großer Plan flüssig bleibt:
 *
 *   Raster (PNG/JPEG)  Kachelpyramide vom Server (PlanTileService)
 *   PDF                Kacheln im Browser aus den Vektordaten (pdf.js)
 *   SVG                ein Overlay, das der Browser verlustfrei skaliert
 *
 * In der Datenbank stehen weiterhin Prozentwerte. Das ist der richtige
 * Ort dafür: wird ein Plan später gegen eine schärfere Fassung getauscht,
 * stimmen die Dosen weiterhin.
 */

/** Wandelt die data-Attribute des Rahmens in eine Planbeschreibung um. */
export function quelleAus(el) {
    return {
        art: el.dataset.planArt || 'bild',
        url: el.dataset.planUrl || '',
        breite: parseFloat(el.dataset.planBreite || '0'),
        hoehe: parseFloat(el.dataset.planHoehe || '0'),
        seite: parseInt(el.dataset.planSeite || '1', 10),
        maxZoom: parseInt(el.dataset.planMaxZoom || '0', 10),
        kachelUrl: el.dataset.planKachelUrl || '',
        kachel: parseInt(el.dataset.planKachel || '256', 10),
        // Wie weit über die Originalauflösung hinaus gezoomt werden darf.
        zusatz: parseInt(el.dataset.planZusatz || '2', 10),
        // Stand der Kachelpyramide (PlanTileService::stand()) – neu gerechnet: ein neuer.
        stand: el.dataset.planStand || '',
    };
}

/**
 * Größte Canvas-Fläche, auf die man sich verlassen kann.
 *
 * Safari verweigert darüber hinaus die Fläche – ohne Fehler, die Kachel
 * bleibt schlicht weiß. Chrome ist großzügiger, aber auch dort ist
 * irgendwann Schluss. 16,8 Megapixel (4096 × 4096) sind der Wert, den
 * jeder aktuelle Browser hergibt.
 */
const CANVAS_GRENZE = 4096 * 4096;

/**
 * Wie viele Stufen über die Seitengröße hinaus gezeichnet werden dürfen.
 *
 * pdf.js bekommt den Kachelausschnitt über einen Viewport der *ganzen*
 * Seite in Kachelauflösung. Der vervierfacht sich mit jeder Stufe: bei
 * einem A3-Plan sind es auf der vierten Stufe schon 64 Megapixel. Hier
 * wird so weit heruntergezählt, bis der Viewport sicher zu zeichnen ist.
 *
 * Das ist der Notweg für Installationen ohne poppler – liegt der Plan
 * gerastert als Kachelpyramide vor, stellt sich die Frage nicht.
 */
function sichereZusatz(quelle) {
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    let zusatz = Math.max(0, quelle.zusatz);
    while (zusatz > 0) {
        const faktor = Math.pow(2, zusatz) * dpr;
        if (quelle.breite * faktor * quelle.hoehe * faktor <= CANVAS_GRENZE) {
            break;
        }
        zusatz--;
    }
    return zusatz;
}

/**
 * Vollbild für den Kartenrahmen.
 *
 * Auf dem Tablet ist der Plan in einer Spalte neben Formularen kaum zu
 * gebrauchen; zum Einzeichnen braucht es die ganze Fläche.
 *
 * Zwei Wege dorthin, und der zweite ist nicht bloß Zierde: iPadOS-Safari
 * kennt die Fullscreen-API für gewöhnliche Elemente nicht – dort gibt es
 * sie nur für Video. Deshalb legt sich die Karte im Zweifel per CSS über
 * die Seite. Für den Benutzer sieht beides gleich aus.
 */
function vollbild(el) {
    const echtMoeglich = !!(el.requestFullscreen || el.webkitRequestFullscreen);

    const zustand = {
        get aktiv() {
            return el.classList.contains('plan-vollbild')
                || document.fullscreenElement === el
                || document.webkitFullscreenElement === el;
        },
        get echt() {
            return echtMoeglich;
        },
        async ein() {
            if (echtMoeglich) {
                try {
                    await (el.requestFullscreen ? el.requestFullscreen() : el.webkitRequestFullscreen());
                    return;
                } catch (fehler) {
                    // Manche Browser lehnen den Wunsch ab, wenn er nicht
                    // direkt aus einem Tippen kommt. Dann eben per CSS.
                }
            }
            el.classList.add('plan-vollbild');
            document.body.classList.add('plan-vollbild-an');
        },
        async aus() {
            if (document.fullscreenElement === el && document.exitFullscreen) {
                try {
                    await document.exitFullscreen();
                } catch (fehler) { /* dann bleibt nur der CSS-Weg */ }
            } else if (document.webkitFullscreenElement === el && document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
            el.classList.remove('plan-vollbild');
            document.body.classList.remove('plan-vollbild-an');
        },
        async umschalten() {
            return zustand.aktiv ? zustand.aus() : zustand.ein();
        },
    };

    return zustand;
}

/**
 * Baut die Karte in den übergebenen Rahmen.
 *
 * @returns {Promise<object>} Karte mit Umrechnungen und Markerhilfen
 */
export async function planKarte(el, optionen = {}) {
    const quelle = quelleAus(el);
    const melden = optionen.melden || (() => {});

    let seite = null;
    if (quelle.art === 'pdf') {
        melden('Grundriss wird gelesen …');
        try {
            seite = await pdfSeite(quelle);
            const ansicht = seite.getViewport({ scale: 1 });
            quelle.breite = ansicht.width;
            quelle.hoehe = ansicht.height;
            quelle.maxZoom = stufen(ansicht.width, ansicht.height, quelle.kachel);
        } catch (fehler) {
            /*
             * Schlägt das fehl, blieb die Karte bisher einfach leer und
             * niemand wusste, warum. Der Grund steht jetzt auf der Seite.
             */
            melden('Das PDF ließ sich nicht öffnen: ' + (fehler && fehler.message ? fehler.message : fehler)
                + ' – liegt es am Ausliefern von JavaScript, hilft ein Blick in die Browser-Konsole.');
            el.classList.add('plan-fehler');
            throw fehler;
        }
    }
    if (!(quelle.breite > 0) || !(quelle.hoehe > 0)) {
        quelle.breite = 1191;
        quelle.hoehe = 842;
    }
    if (quelle.art === 'pdf') {
        quelle.zusatz = sichereZusatz(quelle);
    }
    // Bei PDF kennt erst der Browser die Seitengröße – im Rahmen vermerken,
    // damit andere Teile der Seite denselben Maßstab benutzen können.
    el.dataset.planBreite = String(Math.round(quelle.breite));
    el.dataset.planHoehe = String(Math.round(quelle.hoehe));
    el.dataset.planMaxZoom = String(quelle.maxZoom);

    // Eine Planeinheit ist ein Bildpunkt des Plans auf der höchsten Stufe.
    // (let: kachelnWechseln() setzt sie mit einer feineren Pyramide neu.)
    let einheit = 1 / Math.pow(2, quelle.maxZoom);
    const map = L.map(el, {
        crs: L.CRS.Simple,
        minZoom: 0,
        maxZoom: quelle.maxZoom + quelle.zusatz,
        zoomSnap: 0,
        zoomDelta: 0.5,
        wheelPxPerZoomLevel: 140,
        zoomControl: false,
        attributionControl: false,
        maxBoundsViscosity: 0.75,
        doubleClickZoom: false,
    });
    L.control.zoom({ position: 'topright', zoomInTitle: 'größer', zoomOutTitle: 'kleiner' }).addTo(map);

    const grenzen = L.latLngBounds(
        L.latLng(-quelle.hoehe * einheit, 0),
        L.latLng(0, quelle.breite * einheit)
    );
    map.setMaxBounds(grenzen.pad(0.35));

    /** Kachelebene der Pyramide vom Server – Stufe nach Gerätepixeln (geraetepixel). */
    const serverKacheln = (url, maxNativeZoom) => {
        const lage = new (L.TileLayer.extend(geraetepixel))(url, {
            tileSize: quelle.kachel,
            minNativeZoom: 0,
            maxNativeZoom,
            bounds: grenzen,
            noWrap: true,
            keepBuffer: 3,
            className: 'plan-kachel',
        });
        // Eine Kachel fehlt (404): vielleicht wurde der Plan neu gerechnet – karte.js fragt nach.
        if (optionen.kachelFehler) {
            lage.on('tileerror', optionen.kachelFehler);
        }
        return lage;
    };
    let kachelLage = null;

    if (quelle.art === 'kacheln' && quelle.kachelUrl !== '') {
        /*
         * Papierweiß unter dem Plan.
         *
         * Auf gebrochenen Zoomstufen rundet der Browser die Ränder jeder
         * Kachel für sich – dazwischen bleibt mitunter eine Haarfuge. Früher
         * bekam jede Kachel dafür ein Pixel mehr (ueberlappen); dabei wurde
         * aber jedes Bild von 256 auf 257 Pixel gestreckt und neu abgetastet,
         * auch auf ganzen Stufen: gemessen blieben auf der Originalstufe 40 %
         * der Schärfe übrig, Striche rückten bis zu einem Bildpunkt (am
         * Telefon drei Gerätepixel) aus der Lage. Die Fugen verhinderte das
         * nicht einmal – auf einem dunklen Plan gemessen 2,5- bis 6,6-mal so
         * viele helle Fugenpixel wie ohne Streckung. Jetzt bleibt jede Kachel
         * pixelgenau, und durch eine Fuge scheint Weiß statt Kartengrau – auf
         * einem Plan auf Papier ist das nicht zu sehen.
         */
        map.createPane('planGrund').style.zIndex = '150';
        L.rectangle(grenzen, {
            pane: 'planGrund', stroke: false, fillColor: '#ffffff', fillOpacity: 1, interactive: false,
        }).addTo(map);
        kachelLage = serverKacheln(quelle.kachelUrl, quelle.maxZoom).addTo(map);
    } else if (quelle.art === 'pdf') {
        pdfKachelLage(seite, quelle).addTo(map);
    } else if (quelle.url !== '') {
        L.imageOverlay(quelle.url, grenzen, { className: 'plan-bild', alt: 'Grundriss' }).addTo(map);
    } else {
        // Ohne Grundriss (PowerManagement: Etage ohne Plan) bleibt eine
        // leere Fläche im selben Koordinatensystem – Anlagen lassen sich
        // trotzdem setzen, und ein später hochgeladener Plan passt dazu.
        L.rectangle(grenzen, { color: '#cbd5e1', weight: 1, fillColor: '#ffffff', fillOpacity: 1, interactive: false }).addTo(map);
    }

    map.fitBounds(grenzen);
    // fitBounds trifft die Stufe, auf der der ganze Plan hineinpasst – das
    // ist die sinnvolle Ausgangslage und zugleich die Untergrenze.
    map.setMinZoom(Math.max(0, map.getZoom() - 1.5));

    /** Prozent des Plans → Kartenpunkt. */
    const zuLatLng = (x, y) => L.latLng(
        -(Number(y) / 100) * quelle.hoehe * einheit,
        (Number(x) / 100) * quelle.breite * einheit
    );

    /** Ein Punkt als {x,y} oder [x,y] – beide Schreibweisen kommen vor. */
    const paarZuLatLng = (punkt) => (Array.isArray(punkt)
        ? zuLatLng(punkt[0], punkt[1])
        : zuLatLng(punkt.x, punkt.y));

    /** Kartenpunkt → Prozent des Plans, auf den Plan begrenzt. */
    const zuProzent = (latlng) => ({
        x: klemme((latlng.lng / einheit / quelle.breite) * 100),
        y: klemme((-latlng.lat / einheit / quelle.hoehe) * 100),
    });

    const karte = {
        map,
        quelle,
        grenzen,
        zuLatLng,
        zuProzent,
        /** Marker mit punktgenauem Anker: der Punkt sitzt auf der Koordinate. */
        punkt(x, y, klassen, html, zusatz = {}) {
            return L.marker(zuLatLng(x, y), {
                icon: L.divIcon({
                    className: 'plan-pin ' + klassen,
                    html,
                    iconSize: [0, 0],
                    iconAnchor: [0, 0],
                }),
                keyboard: false,
                riseOnHover: true,
                ...zusatz,
            });
        },
        ganz() {
            map.fitBounds(grenzen);
        },
        /**
         * Ein Linienzug in Plankoordinaten – so wird ein Mobilkabel gezeichnet.
         *
         * @param {Array<{x:number,y:number}|[number,number]>} punkte
         */
        linie(punkte, optionen = {}) {
            return L.polyline(punkte.map(paarZuLatLng), {
                weight: 4,
                opacity: 0.9,
                lineCap: 'round',
                lineJoin: 'round',
                ...optionen,
            });
        },
        /** Prozentpunkte aus einem Linienzug zurücklesen. */
        linieZuProzent(linie) {
            return linie.getLatLngs().map((ll) => zuProzent(ll));
        },
        /** Die Kachelebene vom Server (null bei PDF im Browser, Bild, SVG). */
        get kachelLage() {
            return kachelLage;
        },
        /**
         * Auf eine neu gerechnete Pyramide umsteigen, ohne die Seite neu zu
         * laden (plan: Antwort von /grundriss/<id>/kacheln/stand).
         *
         * Geht nur, wenn sie im Kartenraster dieselbe Fläche bedeckt –
         * Breite und Höhe durch 2^maxZoom bis auf ein Pixel gleich: Dann
         * stimmen alle Koordinaten weiter (Marker, Kabel, Maßstab,
         * Georeferenz rechnen in Prozent mal Breite mal Planeinheit). Ein
         * doppelt so fein gerechneter Plan (eine Stufe mehr) ist so einer,
         * ebenso einer, an dem sich nur Filter oder Format geändert haben.
         * Sonst false – dann muss die Seite neu geladen werden.
         *
         * Die neue Ebene kommt über die alte, die alte geht erst, wenn die
         * neue geladen ist: kein weißes Aufblitzen.
         */
        kachelnWechseln(plan) {
            if (kachelLage === null || !plan || typeof plan.kachel_url !== 'string' || plan.kachel_url === '') {
                return false;
            }
            const faktor = Math.pow(2, plan.max_zoom - quelle.maxZoom);
            if (plan.kachel !== quelle.kachel || Math.abs(plan.breite - quelle.breite * faktor) > 1
                || Math.abs(plan.hoehe - quelle.hoehe * faktor) > 1) {
                return false;
            }
            const alt = kachelLage;
            const zusatz = map.getMaxZoom() - quelle.maxZoom;
            kachelLage = serverKacheln(plan.kachel_url, plan.max_zoom);
            kachelLage.once('load', () => map.removeLayer(alt));
            kachelLage.addTo(map);
            Object.assign(quelle, { breite: plan.breite, hoehe: plan.hoehe, maxZoom: plan.max_zoom, kachelUrl: plan.kachel_url });
            einheit = 1 / Math.pow(2, quelle.maxZoom);
            map.setMaxZoom(quelle.maxZoom + zusatz);
            el.dataset.planBreite = String(plan.breite);
            el.dataset.planHoehe = String(plan.hoehe);
            el.dataset.planMaxZoom = String(plan.max_zoom);
            el.dataset.planKachelUrl = plan.kachel_url;
            return true;
        },
        vollbild: vollbild(el),
    };

    /*
     * Beim Wechsel in den Vollbildmodus ändert sich die Fläche, ohne dass
     * ein resize-Ereignis kommt. Ohne invalidateSize bleibt Leaflet bei
     * den alten Maßen – die Kacheln stehen dann neben dem Rahmen.
     */
    const nachmessen = () => window.setTimeout(() => map.invalidateSize(), 120);
    document.addEventListener('fullscreenchange', nachmessen);
    document.addEventListener('webkitfullscreenchange', nachmessen);
    karte.nachmessen = nachmessen;

    melden(optionen.bereit || 'Grundriss geladen.');
    return karte;
}

/**
 * Eine Haarlinie zwischen zwei Kacheln vermeiden.
 *
 * Auf gebrochenen Zoomstufen skaliert der Browser die Kacheln und rundet
 * ihre Ränder unterschiedlich – dazwischen blitzt dann der Hintergrund
 * durch. Jede Kachel bekommt deshalb ein Pixel mehr und überlappt ihre
 * Nachbarin. Leaflet setzt die Größe in _initTile selbst, deshalb muss die
 * Korrektur danach kommen.
 *
 * Nur noch für die Kacheln aus dem PDF (pdfKachelLage): Dort wird das
 * zusätzliche Pixel mitgezeichnet. Ein fertiges Bild vom Server würde
 * dabei gestreckt und unscharf – darunter liegt stattdessen Papierweiß.
 */
const ueberlappen = {
    _initTile(kachel) {
        L.GridLayer.prototype._initTile.call(this, kachel);
        const groesse = this.getTileSize();
        kachel.style.width = (groesse.x + 1) + 'px';
        kachel.style.height = (groesse.y + 1) + 'px';
    },
};

/**
 * Welche Stufe der Kachelpyramide zur Zoomstufe passt – gezählt in
 * Gerätepixeln.
 *
 * Leaflet nimmt die Stufe der gerundeten Zoomstufe, gedacht in CSS-Pixeln.
 * Auf dem Tablet (devicePixelRatio 2) und dem Telefon (3) zog der Browser
 * damit jede Kachel auf das Zwei- bis Vierfache, obwohl die feinere Stufe
 * auf dem Server lag; auf gebrochenen Zoomstufen (zoomSnap 0) kam bis zum
 * 1,41-Fachen dazu. Hier zählt die Stufe in Gerätepixeln und wird
 * aufgerundet: Eine Kachel wird nie vergrößert, solange es eine feinere
 * gibt (0,05: eine Spur bis 3,5 % lädt keine ganze Stufe nach), und
 * höchstens auf die Hälfte verkleinert.
 */
export function kachelStufe(zoom, dichte = 1) {
    return Math.ceil(zoom + Math.log2(Math.max(1, dichte || 1)) - 0.05);
}

/**
 * Kachelebene, die ihre Stufe nach kachelStufe() wählt.
 *
 * Die Koordinaten bleiben, wie sie sind: Leaflet skaliert jede Stufe auf
 * die Zoomstufe der Karte (_setZoomTransform); Marker, Kabel, Maßstab und
 * Georeferenz rechnen weiter in Pixeln der obersten Stufe und wissen von
 * der Kachelstufe nichts.
 *
 * _setView ist die Fassung aus Leaflet 1.9.4 mit einer Änderung: Die Stufe
 * kommt aus der ungerundeten Zoomstufe. _update fragt _clampZoom mit
 * derselben Rechnung, sonst hielte es die Stufe für zu weit weg und setzte
 * die Ansicht immer wieder neu.
 *
 * Eine Stelle reicht schon die fertige Stufe weiter: Liegt die geladene
 * mehr als eine Stufe daneben (animiertes Zoomen über mehrere Stufen),
 * ruft _update _setView(center, _clampZoom(zoom)). Dort noch einmal
 * umgerechnet, rückte die Stufe jedes Mal um log2(dichte) weiter weg – am
 * Telefon rief sich das ohne Ende selbst auf. Aus _update heraus gilt die
 * Zahl darum schon als Stufe, und die Kacheln werden auf die Zoomstufe der
 * Karte gesetzt statt auf die Stufe.
 */
const geraetepixel = {
    _clampZoom(zoom) {
        return L.GridLayer.prototype._clampZoom.call(this, kachelStufe(zoom, window.devicePixelRatio));
    },
    _update(center) {
        this._updateTiefe = (this._updateTiefe || 0) + 1;
        try {
            L.GridLayer.prototype._update.call(this, center);
        } finally {
            this._updateTiefe--;
        }
    },
    _setView(center, zoom, noPrune, noUpdate) {
        const ausUpdate = this._updateTiefe > 0;
        let tileZoom = Math.round(zoom);
        if ((this.options.maxZoom !== undefined && tileZoom > this.options.maxZoom)
            || (this.options.minZoom !== undefined && tileZoom < this.options.minZoom)) {
            tileZoom = undefined;
        } else {
            tileZoom = ausUpdate ? zoom : this._clampZoom(zoom);
        }
        const neueStufe = this.options.updateWhenZooming && (tileZoom !== this._tileZoom);
        if (!noUpdate || neueStufe) {
            this._tileZoom = tileZoom;
            if (this._abortLoading) {
                this._abortLoading();
            }
            this._updateLevels();
            this._resetGrid();
            if (tileZoom !== undefined) {
                this._update(center);
            }
            if (!noPrune) {
                this._pruneTiles();
            }
            this._noPrune = !!noPrune;
        }
        this._setZoomTransforms(center, ausUpdate ? this._map.getZoom() : zoom);
    },
};

function klemme(wert) {
    return Math.max(0, Math.min(100, wert));
}

function stufen(breite, hoehe, kachel) {
    return Math.max(0, Math.ceil(Math.log2(Math.max(breite, hoehe) / kachel)));
}

// ------------------------------------------------------------------- PDF

let pdfjs = null;

/*
 * pdf.js liegt bewusst unter .js und nicht unter .mjs.
 *
 * Ein ES-Modul erkennt der Browser am MIME-Typ, nicht an der Endung – und
 * .mjs steht in älteren mime.types von nginx schlicht nicht drin. Dann
 * kommt application/octet-stream zurück, der Browser lehnt das Modul ab,
 * und der Grundriss bleibt leer, ohne dass irgendwo etwas danebengeht.
 * Genau das ist auf bestehenden Installationen passiert, deren
 * nginx-Site älter ist als der Eintrag in der mitgelieferten Vorlage –
 * und die Vorlage wird bei einem Update mit Absicht nicht überschrieben.
 *
 * .js kennt jede nginx-Fassung. Damit hängt die Anzeige nicht mehr an
 * einer Server-Einstellung, die niemand im Blick hat.
 */
async function pdfSeite(quelle) {
    if (pdfjs === null) {
        const kennung = new URL(import.meta.url).search;
        pdfjs = await import('/assets/vendor/pdfjs/pdf.min.js' + kennung);
        pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js' + kennung;
    }
    const doc = await pdfjs.getDocument({ url: quelle.url, isEvalSupported: false }).promise;
    return doc.getPage(Math.min(Math.max(quelle.seite, 1), doc.numPages));
}

/**
 * Kachelebene, die ihre Kacheln direkt aus dem PDF zeichnet.
 *
 * Statt das PDF einmal in ein riesiges Bild zu rendern, bekommt jede
 * Kachel ihren Ausschnitt in genau der Auflösung, in der sie zu sehen ist.
 * Damit ist der Plan auf jeder Zoomstufe scharf – ein gerasterter Plan
 * wäre irgendwann matschig – und der Speicher bleibt überschaubar.
 *
 * pdf.js kann immer nur einen Auftrag je Seite abarbeiten, deshalb die
 * Warteschlange. Kacheln, die der Nutzer inzwischen weggescrollt hat,
 * werden abgebrochen statt umsonst gezeichnet.
 */
function pdfKachelLage(seite, quelle) {
    const warteschlange = [];
    let laeuft = null;

    const Lage = L.GridLayer.extend({
        ...ueberlappen,
        createTile(koordinaten, fertig) {
            const kachel = L.DomUtil.create('canvas', 'leaflet-tile plan-kachel');
            const groesse = this.getTileSize();
            const dpr = Math.min(2, window.devicePixelRatio || 1);

            // Ein Pixel mehr als die Kachel groß ist: die Überlappung wird
            // aus dem PDF mitgezeichnet, nicht gestreckt – der Plan bleibt
            // also maßhaltig und die Naht zur Nachbarkachel verschwindet.
            kachel.width = Math.round((groesse.x + 1) * dpr);
            kachel.height = Math.round((groesse.y + 1) * dpr);
            // Welcher Ausschnitt hier steht – hilft beim Nachmessen.
            kachel.dataset.kachel = koordinaten.z + '/' + koordinaten.x + '/' + koordinaten.y;

            const auftrag = {
                kachel,
                fertig,
                dpr,
                skala: Math.pow(2, koordinaten.z - quelle.maxZoom) * dpr,
                x: koordinaten.x * groesse.x * dpr,
                y: koordinaten.y * groesse.y * dpr,
                weg: false,
            };
            kachel._auftrag = auftrag;
            warteschlange.push(auftrag);
            arbeiten();
            return kachel;
        },
    });

    async function arbeiten() {
        if (laeuft !== null) { return; }
        const auftrag = warteschlange.shift();
        if (auftrag === undefined) { return; }
        if (auftrag.weg) { arbeiten(); return; }

        const ctx = auftrag.kachel.getContext('2d', { alpha: false });
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, auftrag.kachel.width, auftrag.kachel.height);

        try {
            laeuft = seite.render({
                canvasContext: ctx,
                viewport: seite.getViewport({ scale: auftrag.skala }),
                // Den Ausschnitt an die richtige Stelle schieben: der
                // Seitenursprung liegt um die Kachelposition verschoben.
                transform: [1, 0, 0, 1, -auftrag.x, -auftrag.y],
            });
            await laeuft.promise;
            auftrag.fertig(null, auftrag.kachel);
        } catch (fehler) {
            if (fehler && fehler.name !== 'RenderingCancelledException') {
                auftrag.fertig(fehler, auftrag.kachel);
            }
        } finally {
            laeuft = null;
            arbeiten();
        }
    }

    const lage = new Lage({
        tileSize: quelle.kachel,
        minNativeZoom: 0,
        maxNativeZoom: quelle.maxZoom + quelle.zusatz,
        noWrap: true,
        keepBuffer: 2,
        bounds: L.latLngBounds(
            L.latLng(-quelle.hoehe / Math.pow(2, quelle.maxZoom), 0),
            L.latLng(0, quelle.breite / Math.pow(2, quelle.maxZoom))
        ),
    });

    lage.on('tileunload', (ereignis) => {
        const auftrag = ereignis.tile && ereignis.tile._auftrag;
        if (auftrag) {
            auftrag.weg = true;
        }
    });

    return lage;
}
