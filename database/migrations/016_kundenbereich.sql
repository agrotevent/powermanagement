-- Kundenbereich: Kunden (Firmen, Veranstalter) mit eigenen Zugängen sehen
-- ihre Locations, ihre Übergabeberichte (Prüf- und Messprotokolle) und die
-- Prüfnachweise (DGUV V3) der Geräte, die sie gemietet haben.
--
-- Ein Kundenzugang ist ein Konto mit users.kunde_id (genau ein Kunde). Er hat
-- keine Rolle und keine Rechte und erreicht nur /kundenbereich (Tor im
-- Router). Was er sieht, kommt aus drei Quellen – nie aus Freitext und nie
-- aus dem Standort allein (ein Gelände hat mehrere Veranstalter):
--   Locations      Freigaben am Kunden: freigaben.subject_type = 'kunde',
--                  subject_id = kunden.id, nur ziel_art = 'site', nie
--                  bearbeiten oder schalten, immer mit Ende und Datenzeitraum
--   Protokolle     berichte.kunde_id (ausdrücklich zugeordnet)
--   Prüfnachweise  pruefnachweise.kunde_id mit status = 'freigegeben'
-- Protokolle und Nachweise bleiben sichtbar, wenn die Location-Freigabe
-- abgelaufen ist; sie enden mit dem Kunden.
--
-- aktiv, gueltig_bis  danach sind alle Zugänge des Kunden zu.
-- ewx_kunde_ref       customerRefId des Kunden in Eventworx (ObjectId, 24 hex),
--                     gesetzt mit dem ersten verknüpften Vorgang; jeder weitere
--                     muss passen. Nur aus einer serverseitig gelesenen Antwort.
-- ewx_kunde_name      Name, den Eventworx dabei nannte (nur zur Kontrolle).
-- notizen             nur für das Personal, nie im Kundenbereich.
CREATE TABLE kunden (
    id {{PK}},
    name VARCHAR(255) NOT NULL,
    kundennummer VARCHAR(64) NOT NULL DEFAULT '',
    aktiv {{BOOL}} NOT NULL DEFAULT 1,
    gueltig_bis {{TS}} NULL,
    ewx_host VARCHAR(190) NOT NULL DEFAULT '',
    ewx_kunde_ref VARCHAR(24) NULL,
    ewx_kunde_name VARCHAR(255) NOT NULL DEFAULT '',
    notizen {{TEXT}} NULL,
    created_by INT NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
-- Ein Eventworx-Kunde gehört höchstens einem Kunden hier (NULL zählt nicht).
CREATE UNIQUE INDEX idx_kunden_ewx ON kunden (ewx_host, ewx_kunde_ref)
;--split
CREATE INDEX idx_kunden_name ON kunden (name)
;--split
-- Kundenzugang: Konto eines Kunden (genau einer); NULL = Personal.
-- einrichtung_hash  HMAC-SHA256 des Einmal-Links (nie der Link selbst),
-- einrichtung_bis   Ablauf; beides wird beim Einlösen geleert.
ALTER TABLE users ADD COLUMN kunde_id INT NULL
;--split
ALTER TABLE users ADD COLUMN einrichtung_hash CHAR(64) NULL
;--split
ALTER TABLE users ADD COLUMN einrichtung_bis {{TS}} NULL
;--split
CREATE INDEX idx_users_kunde ON users (kunde_id)
;--split
CREATE UNIQUE INDEX idx_users_einrichtung ON users (einrichtung_hash)
;--split
-- Bericht ↔ Kunde – außerhalb von inhalt/inhalt_hash, also auch nach dem
-- Einfrieren setz- und korrigierbar; gilt für alle Fassungen eines Berichts.
-- ewx_kunde_ref: customerRefId des verknüpften Vorgangs (nur Vorschlag).
ALTER TABLE berichte ADD COLUMN kunde_id INT NULL
;--split
ALTER TABLE berichte ADD COLUMN ewx_kunde_ref VARCHAR(24) NOT NULL DEFAULT ''
;--split
CREATE INDEX idx_berichte_kunde ON berichte (kunde_id, status)
;--split
-- Für Kunden-Freigaben eines Standorts (Überschneidung, Löschen des Standorts).
CREATE INDEX idx_freigaben_ziel ON freigaben (ziel_art, ziel_id)
;--split
-- Eventworx-Vorgänge (Auftrag, Lieferschein) eines Kunden: Quelle für
-- Prüfnachweise am Vorgang und Vorschlag für die Bericht-Zuordnung. Ein
-- Vorgang gehört höchstens einem Kunden. von/bis: Mietzeit als Ortsdatum.
CREATE TABLE kunden_vorgaenge (
    id {{PK}},
    kunde_id INT NOT NULL,
    ewx_host VARCHAR(190) NOT NULL,
    ewx_id VARCHAR(40) NOT NULL,
    ewx_art VARCHAR(24) NOT NULL DEFAULT '',
    ewx_nummer VARCHAR(64) NOT NULL DEFAULT '',
    ewx_kunde_ref VARCHAR(24) NOT NULL DEFAULT '',
    titel VARCHAR(255) NOT NULL DEFAULT '',
    von VARCHAR(10) NULL,
    bis VARCHAR(10) NULL,
    site_id INT NULL,
    verknuepft_von INT NULL,
    verknuepft_am {{TS}} NOT NULL,
    dokumente_am {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_kunden_vorgaenge ON kunden_vorgaenge (ewx_host, ewx_id)
;--split
CREATE INDEX idx_kunden_vorgaenge_kunde ON kunden_vorgaenge (kunde_id)
;--split
-- Dateien der Prüfnachweise Byte für Byte (base64, wie bericht_pdf: die
-- Sicherung vor einem Update enthält nur config.php und die Datenbank).
-- Höchstens 10 MiB je Datei, gleiche Bytes nur einmal (sha256).
CREATE TABLE pruefnachweis_dateien (
    id {{PK}},
    sha256 CHAR(64) NOT NULL,
    art VARCHAR(8) NOT NULL DEFAULT 'pdf',
    groesse INT NOT NULL,
    daten {{JSON}} NOT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_pruefnachweis_dateien ON pruefnachweis_dateien (sha256)
;--split
-- Prüfnachweise ortsveränderlicher Betriebsmittel (DGUV V3, DIN EN 50699) –
-- nicht der Übergabebericht der Anlage (der steht in berichte).
-- status    entwurf | freigegeben | zurueckgezogen (Kunden sehen nur freigegeben)
-- quelle    hand | ewx_vorgang
-- ergebnis  '' (nicht angegeben) | bestanden | maengel – nur aus dem Protokoll
-- Daten als Ortsdatum JJJJ-MM-TT; einsatz_*: Mietzeit (Vorgabe aus dem Vorgang).
-- herkunft  woher genau (Eventworx: Host, Vorgang, Datei, Zeit; Upload: Name,
--           wer, wann) – nie Inhalte.
-- ewx_datei_id  Datei in Eventworx; höchstens einmal übernommen, also auch nie
--           für zwei Kunden.
CREATE TABLE pruefnachweise (
    id {{PK}},
    kunde_id INT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'entwurf',
    quelle VARCHAR(16) NOT NULL,
    bezeichnung VARCHAR(255) NOT NULL,
    inventarnummer VARCHAR(80) NOT NULL DEFAULT '',
    seriennummer VARCHAR(80) NOT NULL DEFAULT '',
    norm VARCHAR(120) NOT NULL DEFAULT '',
    ergebnis VARCHAR(16) NOT NULL DEFAULT '',
    geprueft_am VARCHAR(10) NULL,
    naechste_pruefung VARCHAR(10) NULL,
    einsatz_von VARCHAR(10) NULL,
    einsatz_bis VARCHAR(10) NULL,
    hinweis VARCHAR(500) NOT NULL DEFAULT '',
    site_id INT NULL,
    vorgang_id INT NULL,
    inventar_id INT NULL,
    datei_id INT NULL,
    datei_name VARCHAR(190) NOT NULL DEFAULT '',
    ewx_host VARCHAR(190) NOT NULL DEFAULT '',
    ewx_datei_id VARCHAR(24) NULL,
    herkunft {{JSON}} NULL,
    aufbewahren_bis VARCHAR(10) NULL,
    erstellt_von INT NULL,
    freigegeben_von INT NULL,
    freigegeben_am {{TS}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE INDEX idx_pruefnachweise_kunde ON pruefnachweise (kunde_id, status)
;--split
CREATE INDEX idx_pruefnachweise_datei ON pruefnachweise (datei_id)
;--split
CREATE INDEX idx_pruefnachweise_inventar ON pruefnachweise (inventar_id)
;--split
CREATE UNIQUE INDEX idx_pruefnachweise_ewx ON pruefnachweise (ewx_host, ewx_datei_id)
;--split
-- Wer hat welches Dokument im Kundenbereich geladen – ohne IP (die steht 400
-- Tage im audit_log). Geht mit dem Dokument bzw. dem Kunden.
-- art: bericht | nachweis
CREATE TABLE kunden_abrufe (
    id {{PK}},
    kunde_id INT NOT NULL,
    user_id INT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    art VARCHAR(16) NOT NULL,
    ziel_id INT NOT NULL,
    sha256 CHAR(64) NULL,
    am {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE INDEX idx_kunden_abrufe_kunde ON kunden_abrufe (kunde_id, am)
;--split
CREATE INDEX idx_kunden_abrufe_ziel ON kunden_abrufe (art, ziel_id)
;--split
-- Rechte für vorhandene Rollen (Neuinstallation: Permissions::standardRollen).
-- Die Rolle „kunde“ bekommt nichts: Kundenzugänge haben keine Rechte.
INSERT INTO role_permissions (role_id, permission) SELECT id, 'kunden.view' FROM roles WHERE role_key IN ('planung', 'technik')
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'kunden.manage' FROM roles WHERE role_key = 'planung'
;--split
INSERT INTO role_permissions (role_id, permission) SELECT id, 'kunden.nachweise' FROM roles WHERE role_key IN ('planung', 'technik')
