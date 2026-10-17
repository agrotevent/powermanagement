-- Übergabeberichte „Elektrische Anlage“ (Prüfprotokoll nach DIN VDE 0100-600,
-- Errichterbestätigung nach DGUV Vorschrift 3 § 5 Abs. 4): am Tablet
-- ausfüllen, Prüfer und Kunde unterschreiben, PDF zur Übergabe.
--
-- Ein Bericht ist ein JSON-Dokument (inhalt) mit Kopf, Prüfpunkten, Messung
-- und Stromkreisen. Zahlen darin sind Dezimaltext ("0.42"), nie Gleitkomma –
-- sonst hinge der Hash an der Zahlendarstellung. Mit der Prüfer-Unterschrift
-- wird der Inhalt eingefroren (inhalt_hash) und die Nummer vergeben; danach
-- ändert nur noch eine neue Fassung (Korrektur).
-- status: entwurf | geprueft | abgeschlossen | ersetzt | verworfen
-- ewx_*: Verweis auf den Eventworx-Vorgang (Kennung, Nummer, Host); die
--        übernommenen Kundendaten selbst stehen im inhalt.
-- standort_entfernt: der Standort des Berichts wurde gelöscht – sichtbar
--        dann nur noch mit „alle Standorte“, anders als ein Bericht, der nie
--        einen Standort hatte (den sieht auch sein Ersteller).
CREATE TABLE berichte (
    id {{PK}},
    nummer VARCHAR(40) NULL,
    jahr INT NULL,
    laufnr INT NULL,
    fassung INT NOT NULL DEFAULT 1,
    vorgaenger_id INT NULL,
    ersetzt_durch_id INT NULL,
    korrektur_grund VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'entwurf',
    version INT NOT NULL DEFAULT 1,
    site_id INT NULL,
    aufbau_id INT NULL,
    standort_name VARCHAR(190) NOT NULL DEFAULT '',
    standort_entfernt {{BOOL}} NOT NULL DEFAULT 0,
    titel VARCHAR(255) NOT NULL DEFAULT '',
    veranstaltung VARCHAR(255) NOT NULL DEFAULT '',
    kunde_name VARCHAR(255) NOT NULL DEFAULT '',
    pruefdatum VARCHAR(10) NOT NULL,
    ewx_host VARCHAR(190) NOT NULL DEFAULT '',
    ewx_id VARCHAR(40) NOT NULL DEFAULT '',
    ewx_art VARCHAR(24) NOT NULL DEFAULT '',
    ewx_nummer VARCHAR(64) NOT NULL DEFAULT '',
    ewx_am {{TS}} NULL,
    inhalt {{JSON}} NOT NULL,
    inhalt_hash CHAR(64) NULL,
    pruefer_id INT NULL,
    eingefroren_am {{TS}} NULL,
    abgeschlossen_am {{TS}} NULL,
    pdf_hash CHAR(64) NULL,
    aufbewahren_bis VARCHAR(10) NULL,
    erstellt_von INT NOT NULL,
    geaendert_von INT NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_berichte_nummer ON berichte (jahr, laufnr, fassung)
;--split
CREATE INDEX idx_berichte_site ON berichte (site_id, status)
;--split
CREATE INDEX idx_berichte_aufbau ON berichte (aufbau_id)
;--split
CREATE INDEX idx_berichte_vorgaenger ON berichte (vorgaenger_id)
;--split
-- Die höchste je vergebene laufende Nummer eines Jahres: eine Nummer wird
-- nie wieder vergeben, auch nicht, nachdem ein Bericht nach Ablauf der
-- Aufbewahrungsfrist gelöscht wurde.
CREATE TABLE bericht_nummern (
    jahr INT NOT NULL PRIMARY KEY,
    laufnr INT NOT NULL
){{ENGINE}}
;--split
-- Welche Anlagen ein Bericht abdeckt: „Letzte Prüfung“ an Anlage und
-- Inventar, Querschnitt aus dem letzten Bericht, Aufräumen beim Löschen.
-- inventar_id: das Stück, das zur Zeit des Berichts an der Anlage im Einsatz war.
CREATE TABLE bericht_objekte (
    id {{PK}},
    bericht_id INT NOT NULL,
    objekt_id INT NULL,
    inventar_id INT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_bericht_objekte ON bericht_objekte (bericht_id, objekt_id)
;--split
CREATE INDEX idx_bericht_objekte_objekt ON bericht_objekte (objekt_id)
;--split
CREATE INDEX idx_bericht_objekte_inventar ON bericht_objekte (inventar_id)
;--split
-- Unterschriften. striche: nur Geometrie {"s":[[[x,y],…],…]}, x/y ganze
-- Zahlen 0..10000 auf einer Fläche 3:1 – keine Zeit, kein Druck (DSGVO).
-- art: gezeichnet | abwesend | verweigert (die letzten beiden nur Kunde, ohne Striche)
-- kette_hash bindet Inhalt, vorige Unterschrift, Striche, Angaben, Zeit und
-- Konto; siegel = HMAC darüber mit einem aus app.key abgeleiteten Schlüssel.
-- anfrage: Idempotenzschlüssel des Geräts (Neuversuch = dieselbe Zeile).
CREATE TABLE bericht_unterschriften (
    id {{PK}},
    bericht_id INT NOT NULL,
    rolle VARCHAR(8) NOT NULL,
    art VARCHAR(12) NOT NULL DEFAULT 'gezeichnet',
    name VARCHAR(120) NOT NULL DEFAULT '',
    funktion VARCHAR(120) NOT NULL DEFAULT '',
    ort VARCHAR(120) NOT NULL DEFAULT '',
    striche {{JSON}} NULL,
    striche_hash CHAR(64) NULL,
    angaben {{JSON}} NULL,
    inhalt_hash CHAR(64) NOT NULL,
    vorher_hash CHAR(64) NULL,
    kette_hash CHAR(64) NOT NULL,
    siegel CHAR(64) NOT NULL,
    schluessel_id VARCHAR(16) NOT NULL,
    user_id INT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    unterschrieben_am {{TS}} NOT NULL,
    geraet_am {{TS}} NULL,
    anfrage VARCHAR(64) NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_bericht_unterschrift_rolle ON bericht_unterschriften (bericht_id, rolle)
;--split
CREATE UNIQUE INDEX idx_bericht_unterschrift_anfrage ON bericht_unterschriften (anfrage)
;--split
CREATE INDEX idx_bericht_unterschrift_striche ON bericht_unterschriften (striche_hash)
;--split
-- Das übergebene PDF Byte für Byte (base64). In der Datenbank, weil die
-- Sicherung vor einem Update nur config.php und die Datenbank enthält.
-- siegel: HMAC über Bericht, Nummer, Fassung, Kette der Kundenzeile und
-- SHA-256 der Datei, mit demselben aus app.key abgeleiteten Schlüssel wie
-- die Unterschriften. Ohne den Schlüssel lässt sich ein ausgetauschtes PDF
-- nicht stimmig nachsiegeln – die Prüfsummen allein stehen nebeneinander in
-- derselben Datenbank und ließen sich mit austauschen.
CREATE TABLE bericht_pdf (
    id {{PK}},
    bericht_id INT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    groesse INT NOT NULL,
    daten {{JSON}} NOT NULL,
    erzeuger VARCHAR(16) NOT NULL,
    erzeugt_am {{TS}} NOT NULL,
    siegel CHAR(64) NOT NULL,
    schluessel_id VARCHAR(16) NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_bericht_pdf ON bericht_pdf (bericht_id)
;--split
CREATE INDEX idx_bericht_pdf_sha ON bericht_pdf (sha256)
;--split
-- Lebenslauf eines Berichts (angelegt, unterschrieben, zurückgezogen,
-- Kundenmodus, PDF abgerufen, Korrektur …) ohne Kundendaten und ohne IP.
-- Anders als audit_log räumt „wartung“ hier nicht nach 400 Tagen auf –
-- die Einträge gehen erst mit dem Bericht.
CREATE TABLE bericht_ereignisse (
    id {{PK}},
    bericht_id INT NOT NULL,
    ereignis VARCHAR(32) NOT NULL,
    am {{TS}} NOT NULL,
    user_id INT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    details {{JSON}} NULL
){{ENGINE}}
;--split
CREATE INDEX idx_bericht_ereignisse ON bericht_ereignisse (bericht_id, id)
;--split
-- Kundenmodus am Gerät: ein HttpOnly-Cookie mit Zufallswert, hier nur sein
-- Hash. Die Sperre überdauert so Ablauf der Sitzung und neue Anmeldung in
-- diesem Browser – ein im Browser gespeichertes Kennwort entsperrt nichts.
CREATE TABLE kundenmodus_geraete (
    id {{PK}},
    token_hash CHAR(64) NOT NULL,
    bericht_id INT NOT NULL,
    user_id INT NOT NULL,
    seit {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_kundenmodus_geraete ON kundenmodus_geraete (token_hash)
;--split
CREATE INDEX idx_kundenmodus_geraete_user ON kundenmodus_geraete (user_id, bericht_id)
;--split
-- Prüf- und Messgeräte der Firma (Installationstester, Erdungsmesser) –
-- nicht die Messgeräte (Shellys) im Inventar.
CREATE TABLE pruefgeraete (
    id {{PK}},
    fabrikat VARCHAR(80) NOT NULL,
    typ VARCHAR(80) NOT NULL,
    seriennummer VARCHAR(80) NOT NULL DEFAULT '',
    norm VARCHAR(160) NOT NULL DEFAULT 'DIN EN 61557 (VDE 0413)',
    kalibriert_bis VARCHAR(10) NOT NULL DEFAULT '',
    aktiv {{BOOL}} NOT NULL DEFAULT 1,
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
-- Qualifikation des Prüfers (z. B. „Elektrofachkraft für Veranstaltungstechnik“);
-- ohne sie keine Prüfer-Unterschrift. Pflegt nur die Benutzerverwaltung.
ALTER TABLE users ADD COLUMN pruefer_qualifikation VARCHAR(120) NOT NULL DEFAULT ''
;--split
-- Neue Rechte für die vorhandenen Standardrollen (neue Installationen
-- bekommen sie über Permissions::standardRollen). „kunde“ bekommt nichts:
-- ein Gelände hat mehrere Veranstalter, und Berichte enthalten Kundendaten.
INSERT INTO role_permissions (role_id, permission) SELECT id, 'berichte.view' FROM roles WHERE role_key IN ('planung', 'technik')
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'berichte.manage' FROM roles WHERE role_key IN ('planung', 'technik')
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'berichte.eventworx' FROM roles WHERE role_key IN ('planung', 'technik')
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'berichte.pruefen' FROM roles WHERE role_key = 'technik'
