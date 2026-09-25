-- Bereiche: Anlagen und einzelne gemessene Abgänge, deren Last zusammen
-- gerechnet wird – etwa eine Foodtruck-Meile, die an drei Aggregaten und
-- mehreren Verteilungen hängt. Die Aggregate speisen auch anderes; deshalb
-- zählt nicht ihre Leistung, sondern die der Mitglieder.
--
-- Ein Mitglied ist entweder eine ganze Anlage (objekt_id: ihre
-- Einspeisung bzw. die Summe ihrer gemessenen Abgänge) oder ein einzelnes
-- Messgerät (messpunkt_id: etwa der Shelly an dem Abgang, an dem ein
-- Foodtruck hängt). Was schon in einem anderen Mitglied enthalten ist –
-- eine nachgeschaltete Verteilung, ein Abgang einer Mitglieds-Verteilung –,
-- zählt nicht doppelt; das rechnet BereichService aus den Kabeln aus.
--
-- grenze_kw / grenze_a: optionale Grenzwerte des Bereichs (etwa die
-- zugesagte Anschlussleistung) für die Auslastung.
CREATE TABLE bereiche (
    id {{PK}},
    site_id INT NULL,
    name VARCHAR(190) NOT NULL,
    farbe VARCHAR(16) NOT NULL DEFAULT '',
    grenze_kw REAL NULL,
    grenze_a REAL NULL,
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE INDEX idx_bereiche_site ON bereiche (site_id);
;--split
CREATE TABLE bereich_mitglieder (
    id {{PK}},
    bereich_id INT NOT NULL,
    objekt_id INT NULL,
    messpunkt_id INT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_bereich_mitglieder ON bereich_mitglieder (bereich_id);
