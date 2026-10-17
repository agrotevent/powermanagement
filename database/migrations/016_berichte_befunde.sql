-- Übergabeberichte: Befunde der Prüfung (015 ist für die Plan-Schnittstelle vergeben).
--
-- PDF-Siegel: HMAC über Bericht, Nummer, Fassung, Kette der Kundenzeile und
-- SHA-256 der Datei, mit demselben aus app.key abgeleiteten Schlüssel wie
-- die Unterschriften. Ohne den Schlüssel lässt sich ein ausgetauschtes PDF
-- nicht stimmig nachsiegeln – die Prüfsummen allein stehen nebeneinander in
-- derselben Datenbank und ließen sich mit austauschen.
ALTER TABLE bericht_pdf ADD COLUMN siegel CHAR(64) NULL
;--split
ALTER TABLE bericht_pdf ADD COLUMN schluessel_id VARCHAR(16) NOT NULL DEFAULT ''
;--split
-- Die höchste je vergebene laufende Nummer eines Jahres: eine Nummer wird
-- nie wieder vergeben, auch nicht, nachdem ein Bericht nach Ablauf der
-- Aufbewahrungsfrist gelöscht wurde.
CREATE TABLE bericht_nummern (
    jahr INT NOT NULL PRIMARY KEY,
    laufnr INT NOT NULL
){{ENGINE}}
;--split
INSERT INTO bericht_nummern (jahr, laufnr)
    SELECT jahr, MAX(laufnr) FROM berichte WHERE jahr IS NOT NULL AND laufnr IS NOT NULL GROUP BY jahr
;--split
-- Der Standort des Berichts wurde gelöscht: sichtbar dann nur noch mit
-- „alle Standorte“ – anders als ein Bericht, der nie einen Standort hatte
-- (den sieht auch sein Ersteller).
ALTER TABLE berichte ADD COLUMN standort_entfernt {{BOOL}} NOT NULL DEFAULT 0
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
