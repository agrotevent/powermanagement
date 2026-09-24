-- Freigaben mit Zeitfenstern.
--
-- Eine Freigabe sagt: wer (Benutzer oder Rolle) sieht was (alles, einen
-- Standort, eine einzelne Anlage oder ein einzelnes Gerät) – und wann.
--
--   gueltig_ab / gueltig_bis   in welchem Zeitraum die Freigabe überhaupt
--                              wirkt. Danach ist sie weg, als hätte es sie
--                              nie gegeben.
--   daten_ab / daten_bis       welcher Ausschnitt der Messdaten sichtbar
--                              ist. Ein Veranstalter sieht so die Werte
--                              seiner Veranstaltung – nicht die der
--                              Woche davor.
--
-- Leer heißt jeweils: ohne Grenze auf dieser Seite.
--
-- Schalten ist eine eigene Entscheidung (darf_steuern) und lässt sich bis
-- auf ein einzelnes Gerät und dort auf einen einzelnen Kanal herunter
-- vergeben (ziel_art = geraet, ziel_id = datenquellen.id, ziel_kanal).
CREATE TABLE freigaben (
    id {{PK}},
    subject_type VARCHAR(16) NOT NULL DEFAULT 'user', -- user | role
    subject_id INT NOT NULL,
    ziel_art VARCHAR(16) NOT NULL DEFAULT 'site',     -- alles | site | objekt | geraet
    ziel_id INT NULL,
    ziel_kanal VARCHAR(64) NOT NULL DEFAULT '',       -- nur bei geraet: leer = alle Kanäle
    darf_bearbeiten {{BOOL}} NOT NULL DEFAULT 0,
    darf_steuern {{BOOL}} NOT NULL DEFAULT 0,
    gueltig_ab {{TS}} NULL,
    gueltig_bis {{TS}} NULL,
    daten_ab {{TS}} NULL,
    daten_bis {{TS}} NULL,
    notiz VARCHAR(255) NOT NULL DEFAULT '',
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_freigaben_subjekt ON freigaben (subject_type, subject_id);
