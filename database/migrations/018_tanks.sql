-- Tanks und Tanksensoren.
--
-- Ein Tank ist eine Anlage der Art „tank“ (objekte; Inhalt, Bauform, Maße in
-- eigenschaften), ein Kraftstoffschlauch eine Leitung der Art „kraftstoff“ –
-- dafür braucht es keine Spalte. Neu sind:
--
-- inventar.eigenschaften  Angaben zu einem Tanksensor (JSON): Messprinzip, Feld
--                         des Rohwerts, Signal, Bürde bzw. Wandler, Messbereich,
--                         Totzone, Meldeschritt. Sie wandern mit dem Sensor von
--                         Tank zu Tank.
-- tank_kennlinien         Wie aus dem Rohwert eines Messpunkts Füllhöhe und Liter
--                         werden – eine vollständige Rechnung je Fassung (Sensor ×
--                         Einbau × Tank, App\Tanks\TankRechnung). Die InfluxDB hält
--                         nur Rohwerte; gerechnet wird beim Lesen. Für einen Wert
--                         zur Zeit t gilt die Fassung mit der größten id unter allen
--                         mit ab <= t. Fassungen werden nie geändert: Eine Korrektur
--                         ist eine neue Fassung, eine rückwirkende hat ab = Beginn
--                         des Messpunkts.
-- tank_ereignisse         Betankungen (Liter laut Lieferschein) und Peilungen
--                         (Anzeige, Peilstab) – Protokoll, Kalibrierung und
--                         Verbrauch je Tag. Keine Preise, keine Kundendaten.
ALTER TABLE inventar ADD COLUMN eigenschaften {{JSON}} NULL
;--split
CREATE TABLE tank_kennlinien (
    id {{PK}},
    messpunkt_id INT NOT NULL,
    objekt_id INT NOT NULL,                          -- der Tank (Rechte, Aufräumen)
    ab {{TS}} NOT NULL,                              -- UTC, ISO 8601
    rechnung {{JSON}} NOT NULL,                      -- TankRechnung::pruefen()
    anlass VARCHAR(32) NOT NULL,                     -- einsatz | shelly | sensor | tank | peilung | betankung | hand | rueckwirkend | zurueck
    ereignis_id INT NULL,                            -- Peilung/Betankung, aus der sie stammt
    geprueft {{BOOL}} NOT NULL DEFAULT 0,            -- vor Ort mit Peilung oder Lieferschein bestätigt
    von VARCHAR(64) NOT NULL DEFAULT '',
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE INDEX idx_tank_kennlinien_messpunkt ON tank_kennlinien (messpunkt_id, ab)
;--split
CREATE INDEX idx_tank_kennlinien_objekt ON tank_kennlinien (objekt_id)
;--split
CREATE TABLE tank_ereignisse (
    id {{PK}},
    objekt_id INT NOT NULL,
    messpunkt_id INT NULL,                           -- welcher Sensor damals im Tank war
    art VARCHAR(16) NOT NULL,                        -- betankung | peilung
    zeit {{TS}} NOT NULL,                            -- UTC; Betankung: Ende des Tankens
    liter REAL NULL,                                 -- Betankung: geliefert; Peilung: Inhalt
    prozent REAL NULL,                               -- Peilung an der Anzeige
    hoehe_mm REAL NULL,                              -- Peilung mit dem Peilstab
    roh REAL NULL,                                   -- Peilung: Rohwert (vom Server)
    roh_vor REAL NULL,                               -- Betankung: Rohwert davor (vom Server)
    roh_nach REAL NULL,                              -- Betankung: Rohwert danach (vom Server)
    lieferschein VARCHAR(64) NOT NULL DEFAULT '',
    von VARCHAR(64) NOT NULL DEFAULT '',
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE INDEX idx_tank_ereignisse_objekt ON tank_ereignisse (objekt_id, zeit)
