-- Fest installiert oder mobil.
--
-- Feststromanschlüsse – Hausanschluss, Energiesäule, Wandverteiler,
-- Unterflurkasten, fest eingebaute Hallenverteilung – gehören zur Location.
-- Sie stehen bei jeder Veranstaltung an derselben Stelle und werden beim
-- Location-Abgleich mit dem gemeinsamen Repository übertragen.
--
-- Mobile Anlagen – Verteiler, Aggregate, Speicher, Kabel, Kabelbrücken –
-- gehören zur Veranstaltung und bleiben in dieser Installation.
--
-- Vorhandene Stromanschlüsse (Hausanschluss, Baustromverteiler, Landstrom)
-- gelten als fest; alles andere als mobil. Beides lässt sich je Anlage
-- ändern.
ALTER TABLE objekte ADD COLUMN fest {{BOOL}} NOT NULL DEFAULT 0;
;--split
UPDATE objekte SET fest = 1 WHERE art = 'anschluss';
;--split
CREATE INDEX idx_objekte_fest ON objekte (site_id, fest);
