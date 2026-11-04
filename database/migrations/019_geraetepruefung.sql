-- Geräteprüfung (DGUV Vorschrift 3) ortsveränderlicher elektrischer
-- Betriebsmittel mit dem Prüfgerät BENNING ST 755+.
--
-- Prüflinge (eigener Bestand oder Geräte eines Kunden) und ihre Prüfungen
-- kommen aus der Datenbank des Prüfgeräts (Import) oder werden hier gepflegt
-- und als Datenbank für das Prüfgerät geschrieben (Export aus einer Vorlage).
-- Bewertet wird nur am Prüfgerät; hier wird das Gesamtergebnis nur abgeleitet.
-- Zu jeder Prüfung unterschreibt ein Prüfer einen Prüfbericht – danach ist er
-- eingefroren (inhalt_hash, Siegel, PDF Byte für Byte), eine Korrektur ist
-- eine neue Fassung. Bestandene Berichte von Kundengeräten werden Prüfnachweise
-- im Kundenbereich (erst als Entwurf, sichtbar erst nach Freigabe von Hand).
-- Keine Fremdschlüssel (wie überall); Zahlen als Dezimaltext mit Punkt,
-- Zeiten in UTC (ISO 8601), Tage als Ortsdatum JJJJ-MM-TT.
--
-- Kunden: Anschrift und Kontakt für Prüfberichte und die Datei des
-- Prüfgeräts – alle freiwillig, nie im Audit und nie im Kundenbereich.
ALTER TABLE kunden ADD COLUMN strasse VARCHAR(120) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN plz VARCHAR(16) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN ort VARCHAR(120) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN telefon VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN fax VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN ansprechpartner VARCHAR(120) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN ansprechpartner_abteilung VARCHAR(120) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN ansprechpartner_telefon VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE kunden ADD COLUMN ansprechpartner_email VARCHAR(190) NOT NULL DEFAULT ''
;--split
-- Prüfabläufe des Prüfgeräts (Tabelle Pruefung der Vorlage). Angelegt werden
-- sie nur am Gerät; hier werden sie übernommen und Prüflingen zugeordnet.
-- geraet_id         Pruefung_ID als Text (ab 1 000 000 am Gerät angelegt)
-- ablauf, ablauf_2  Bitmasken als Dezimaltext (int64)
-- fest              Werksablauf; individuell: Einzelablauf eines Geräts
-- ungueltig         am Gerät ungültig oder nicht mehr in der Vorlage – nie
--                   gelöscht, Prüflinge verweisen darauf
-- norm              von Hand; leer = abgeleitet (Messarten::norm)
-- zubehoer          Liste von pruefgeraete.id (etwa Messadapter)
CREATE TABLE gp_pruefablaeufe (
    id {{PK}},
    geraet_id VARCHAR(20) NOT NULL,
    nr VARCHAR(20) NOT NULL DEFAULT '',
    name VARCHAR(120) NOT NULL,
    ablauf VARCHAR(24) NOT NULL DEFAULT '0',
    ablauf_2 VARCHAR(24) NOT NULL DEFAULT '0',
    schutzklasse VARCHAR(4) NOT NULL DEFAULT '',
    grenzwerte_index VARCHAR(20) NOT NULL DEFAULT '',
    fest {{BOOL}} NOT NULL DEFAULT 0,
    individuell {{BOOL}} NOT NULL DEFAULT 0,
    ungueltig {{BOOL}} NOT NULL DEFAULT 0,
    norm VARCHAR(160) NOT NULL DEFAULT '',
    zubehoer {{JSON}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_pruefablaeufe_geraet ON gp_pruefablaeufe (geraet_id)
;--split
-- Prüflinge. kunde_id NULL = eigener Bestand, sonst ein Gerät des Kunden.
-- inventar_id       nur beim eigenen Bestand, nie ein Tanksensor
-- kennung           Prüflings-ID bzw. Barcode am Gerät (Geraete_ID), getrimmt
-- eindeutig         'e:' bzw. 'k<kunde_id>:' + kleingeschriebene Kennung –
--                   eine Kennung je Besitzer nur einmal (Prueflinge::eindeutig)
-- schutzklasse      '1' | '2' | '3'; pruefablauf_id: gp_pruefablaeufe.id
-- leitungslaenge (m), querschnitt (mm²), nennleistung: Dezimaltext mit Punkt
-- letzte_pruefung, naechste_pruefung  Ortsdatum, nach jeder Prüfung neu
--                   gerechnet (Prueflinge::faelligkeitNeu)
-- letztes_ergebnis  '' | bestanden | nicht_bestanden
-- technik           Felder des Geräts, die hier niemand pflegt (für den Export)
CREATE TABLE gp_prueflinge (
    id {{PK}},
    kunde_id INT NULL,
    inventar_id INT NULL,
    kennung VARCHAR(50) NOT NULL,
    eindeutig VARCHAR(80) NOT NULL,
    bezeichnung VARCHAR(120) NOT NULL DEFAULT '',
    hersteller VARCHAR(80) NOT NULL DEFAULT '',
    typ VARCHAR(80) NOT NULL DEFAULT '',
    modell VARCHAR(80) NOT NULL DEFAULT '',
    seriennummer VARCHAR(80) NOT NULL DEFAULT '',
    abteilung VARCHAR(80) NOT NULL DEFAULT '',
    gebaeude VARCHAR(80) NOT NULL DEFAULT '',
    bemerkung VARCHAR(500) NOT NULL DEFAULT '',
    schutzklasse VARCHAR(4) NOT NULL DEFAULT '1',
    pruefablauf_id INT NULL,
    intervall_monate INT NOT NULL DEFAULT 12,
    leitungslaenge VARCHAR(16) NOT NULL DEFAULT '',
    querschnitt VARCHAR(16) NOT NULL DEFAULT '',
    leiter VARCHAR(8) NOT NULL DEFAULT '',
    nennleistung VARCHAR(16) NOT NULL DEFAULT '',
    ausser_betrieb {{BOOL}} NOT NULL DEFAULT 0,
    letzte_pruefung VARCHAR(10) NULL,
    letztes_ergebnis VARCHAR(16) NOT NULL DEFAULT '',
    naechste_pruefung VARCHAR(10) NULL,
    technik {{JSON}} NULL,
    created_by INT NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_prueflinge_eindeutig ON gp_prueflinge (eindeutig)
;--split
CREATE INDEX idx_gp_prueflinge_kunde ON gp_prueflinge (kunde_id)
;--split
CREATE INDEX idx_gp_prueflinge_inventar ON gp_prueflinge (inventar_id)
;--split
CREATE INDEX idx_gp_prueflinge_faellig ON gp_prueflinge (naechste_pruefung)
;--split
-- Ein Import einer Datenbank des Prüfgeräts. zahlen: nur Zahlen
-- ({pruefungen_neu, pruefungen_da, prueflinge_neu, …}), nie Inhalte.
CREATE TABLE gp_importe (
    id {{PK}},
    sha256 CHAR(64) NOT NULL,
    dateiname VARCHAR(120) NOT NULL DEFAULT '',
    groesse INT NOT NULL DEFAULT 0,
    messgeraet VARCHAR(80) NOT NULL DEFAULT '',
    messgeraet_sn VARCHAR(80) NOT NULL DEFAULT '',
    db_version VARCHAR(20) NOT NULL DEFAULT '',
    zahlen {{JSON}} NULL,
    created_by INT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE INDEX idx_gp_importe_sha ON gp_importe (sha256)
;--split
-- Prüfungen aus dem Prüfgerät – nach dem Import unveränderlich (Beweis).
-- schluessel        Fingerabdruck der Prüfung (Pruefungen::schluessel): dieselbe
--                   Prüfung aus Sicherung und Hauptdatenbank nur einmal
-- geprueft_am       UTC ISO 8601; am_geraet: Zeit wie im Gerät (Ortszeit, roh)
-- pruefer           Name laut Gerät (nicht das Konto, das unterschreibt)
-- ergebnis          bestanden | nicht_bestanden – abgeleitet: ein Schritt oder
--                   Wert nicht in Ordnung oder eine Antwort der Sichtprüfung
--                   „nicht in Ordnung“ heißt nicht bestanden
-- sicht             '' | ok | maengel
-- daten             Normalform der Prüfung (nur Text, Wahrheitswerte, Listen)
CREATE TABLE gp_pruefungen (
    id {{PK}},
    pruefling_id INT NOT NULL,
    import_id INT NULL,
    schluessel CHAR(64) NOT NULL,
    geprueft_am VARCHAR(32) NOT NULL,
    am_geraet VARCHAR(40) NOT NULL DEFAULT '',
    pruefer VARCHAR(80) NOT NULL DEFAULT '',
    prueffirma VARCHAR(120) NOT NULL DEFAULT '',
    ort VARCHAR(120) NOT NULL DEFAULT '',
    auftrag VARCHAR(80) NOT NULL DEFAULT '',
    bemerkung VARCHAR(500) NOT NULL DEFAULT '',
    ablauf_name VARCHAR(120) NOT NULL DEFAULT '',
    ablauf_geraet_id VARCHAR(20) NOT NULL DEFAULT '',
    messgeraet VARCHAR(80) NOT NULL DEFAULT '',
    messgeraet_sn VARCHAR(80) NOT NULL DEFAULT '',
    firmware VARCHAR(40) NOT NULL DEFAULT '',
    ergebnis VARCHAR(16) NOT NULL,
    sicht VARCHAR(16) NOT NULL DEFAULT '',
    daten {{JSON}} NOT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_pruefungen_schluessel ON gp_pruefungen (schluessel)
;--split
CREATE INDEX idx_gp_pruefungen_pruefling ON gp_pruefungen (pruefling_id, geprueft_am)
;--split
CREATE INDEX idx_gp_pruefungen_zeit ON gp_pruefungen (geprueft_am)
;--split
-- Vorlage für den Export: eine Datenbank des Prüfgeräts ohne Kunden-, Geräte-
-- und Prüfdaten (nur Prüfabläufe, Grenzwerte, Sichtprüfung, Systemdaten),
-- Byte für Byte als base64 – in der Datenbank, weil die Sicherung vor einem
-- Update nur config.php und die Datenbank enthält. Genau eine ist aktiv.
CREATE TABLE gp_vorlagen (
    id {{PK}},
    sha256 CHAR(64) NOT NULL,
    groesse INT NOT NULL,
    daten {{JSON}} NOT NULL,
    db_version VARCHAR(20) NOT NULL DEFAULT '',
    messgeraet VARCHAR(80) NOT NULL DEFAULT '',
    messgeraet_sn VARCHAR(80) NOT NULL DEFAULT '',
    import_id INT NULL,
    aktiv {{BOOL}} NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
-- Ein Export für das Prüfgerät – nur Zahlen, keine Inhalte.
CREATE TABLE gp_exporte (
    id {{PK}},
    vorlage_id INT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    kunden INT NOT NULL DEFAULT 0,
    prueflinge INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
-- Gemerkte Zuordnung „Kunde im Prüfgerät“ → hier, nur als Vorschlag beim
-- nächsten Import (nie still). schluessel: sha256('name:' + normalisierter
-- Name im Gerät) bzw. sha256('nummer:' + Kundennummer im Gerät);
-- kunde_id NULL = eigener Bestand.
CREATE TABLE gp_kunden_zuordnung (
    id {{PK}},
    schluessel CHAR(64) NOT NULL,
    kunde_id INT NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_kunden_zuordnung ON gp_kunden_zuordnung (schluessel)
;--split
-- Prüfberichte: eine Zeile je unterschriebener Fassung, eingefroren ab dem
-- Anlegen (Unterschrift, Nummer, Inhalt, Siegel und PDF in einem Schritt).
-- nummer            GP-<jahr>-<laufnr>, Korrektur: dieselbe + '-K<fassung-1>'
-- status            gueltig | korrektur (angefordert, noch gültig bis zur neuen
--                   Fassung) | ersetzt (durch die Fassung ersetzt_durch_id)
-- grund             Grund der Korrektur
-- kunde_id          Kopie zum Filtern; der Inhalt trägt die eingefrorene Kopie
-- ergebnis          Kopie aus dem Inhalt (bestanden | nicht_bestanden)
-- inhalt            Kanon::json, nur Text; inhalt_hash = sha256(inhalt)
-- kette_hash, siegel  Kette über Inhalt, Unterschrift und Konto; siegel =
--                   HMAC darüber mit einem aus app.key abgeleiteten Schlüssel
-- pruefer_id        users.id des Unterzeichners
-- korrektur_grund, korrektur_von, korrektur_am  eine angeforderte Korrektur
--                   (status korrektur): die Prüfung ist wieder offen, beim
--                   nächsten Unterschreiben entsteht Fassung n+1 mit diesem
--                   Grund; nicht Teil der Kette (kein Inhalt)
-- korrektur_siegel  HMAC über Bericht, Fassung, Grund, Konto und Zeit der
--                   Korrektur (Schlüssel wie siegel) – ohne ihn gilt eine per
--                   SQL gesetzte „Korrektur angefordert“ als Befund
-- korrektur_schluessel_id  Kennung des Schlüssels, mit dem korrektur_siegel
--                   entstand (nach einem Wechsel von app.key: nicht prüfbar,
--                   die Korrektur wird neu angefordert)
CREATE TABLE gp_berichte (
    id {{PK}},
    pruefung_id INT NOT NULL,
    pruefling_id INT NOT NULL,
    kunde_id INT NULL,
    nummer VARCHAR(40) NOT NULL,
    jahr INT NOT NULL,
    laufnr INT NOT NULL,
    fassung INT NOT NULL DEFAULT 1,
    vorgaenger_id INT NULL,
    grund VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'gueltig',
    ersetzt_durch_id INT NULL,
    ergebnis VARCHAR(16) NOT NULL,
    inhalt {{JSON}} NOT NULL,
    inhalt_hash CHAR(64) NOT NULL,
    unterschrift_id INT NOT NULL,
    pruefer_id INT NOT NULL,
    kette_hash CHAR(64) NOT NULL,
    siegel CHAR(64) NOT NULL,
    schluessel_id VARCHAR(16) NOT NULL,
    pdf_hash CHAR(64) NOT NULL,
    eingefroren_am {{TS}} NOT NULL,
    korrektur_grund VARCHAR(500) NOT NULL DEFAULT '',
    korrektur_von INT NULL,
    korrektur_am {{TS}} NULL,
    korrektur_siegel VARCHAR(64) NOT NULL DEFAULT '',
    korrektur_schluessel_id VARCHAR(16) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_berichte_nummer ON gp_berichte (jahr, laufnr, fassung)
;--split
CREATE UNIQUE INDEX idx_gp_berichte_pruefung ON gp_berichte (pruefung_id, fassung)
;--split
CREATE INDEX idx_gp_berichte_pruefling ON gp_berichte (pruefling_id)
;--split
CREATE INDEX idx_gp_berichte_kunde ON gp_berichte (kunde_id)
;--split
-- Die höchste je vergebene laufende Nummer eines Jahres: eine Nummer wird nie
-- wieder vergeben.
CREATE TABLE gp_bericht_nummern (
    jahr INT NOT NULL PRIMARY KEY,
    laufnr INT NOT NULL
){{ENGINE}}
;--split
-- Eine Unterschrift des Prüfers (nur Striche, keine Zeit, kein Druck) – deckt
-- einen ganzen Durchgang (Sammelunterschrift über mehrere Prüfungen).
-- anfrage           Idempotenzschlüssel des Durchgangs (Neuversuch = dieselbe Zeile)
-- name              wie unterschrieben (= full_name des Kontos)
-- striche           {"s":[[[x,y],…],…]} wie bericht_unterschriften
-- striche_hash      eindeutig; außerdem nie gleich einem in bericht_unterschriften
CREATE TABLE gp_unterschriften (
    id {{PK}},
    anfrage VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(190) NOT NULL DEFAULT '',
    name VARCHAR(120) NOT NULL,
    qualifikation VARCHAR(120) NOT NULL DEFAULT '',
    ort VARCHAR(120) NOT NULL DEFAULT '',
    striche {{JSON}} NOT NULL,
    striche_hash CHAR(64) NOT NULL,
    unterschrieben_am {{TS}} NOT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_unterschriften_anfrage ON gp_unterschriften (anfrage)
;--split
CREATE UNIQUE INDEX idx_gp_unterschriften_striche ON gp_unterschriften (striche_hash)
;--split
-- Das PDF eines Prüfberichts Byte für Byte (base64). siegel: HMAC über
-- Bericht, Nummer, Fassung und SHA-256 der Datei (wie bericht_pdf).
CREATE TABLE gp_bericht_pdf (
    id {{PK}},
    bericht_id INT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    groesse INT NOT NULL,
    daten {{JSON}} NOT NULL,
    erzeuger VARCHAR(40) NOT NULL,
    siegel CHAR(64) NOT NULL,
    schluessel_id VARCHAR(16) NOT NULL,
    erzeugt_am {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_gp_bericht_pdf ON gp_bericht_pdf (bericht_id)
;--split
-- Prüfnachweis aus einem Prüfbericht: höchstens einer je Bericht (NULL zählt nicht).
ALTER TABLE pruefnachweise ADD COLUMN gp_bericht_id INT NULL
;--split
CREATE UNIQUE INDEX idx_pruefnachweise_gp ON pruefnachweise (gp_bericht_id)
;--split
-- Rechte für vorhandene Rollen (Neuinstallation: Permissions::standardRollen).
-- Die Rolle „kunde“ bekommt nichts: Kundenzugänge haben keine Rechte.
INSERT INTO role_permissions (role_id, permission) SELECT id, 'geraetepruefung.view' FROM roles WHERE role_key IN ('planung', 'technik')
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'geraetepruefung.manage' FROM roles WHERE role_key IN ('planung', 'technik')
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'geraetepruefung.unterschreiben' FROM roles WHERE role_key = 'technik'
