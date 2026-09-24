-- Anlagen: Stromanschlüsse, Generatoren, Wechselrichter, Verteilungen.
--
-- Steckverbinder-Katalog: Powerlock, CEE 125/63/32/16 A und Schuko stehen
-- von Anfang an darin (siehe Seed in AnlagenService::grundbestand()).
CREATE TABLE steckertypen (
    id {{PK}},
    schluessel VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    strom_a REAL NOT NULL DEFAULT 16,
    phasen INT NOT NULL DEFAULT 3,
    spannung_v INT NOT NULL DEFAULT 400,
    farbe VARCHAR(16) NOT NULL DEFAULT '#dc2626',
    reihenfolge INT NOT NULL DEFAULT 0,
    ist_standard {{BOOL}} NOT NULL DEFAULT 0,
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE TABLE objekte (
    id {{PK}},
    art VARCHAR(32) NOT NULL,                   -- anschluss | generator | wechselrichter | verteilung
    name VARCHAR(190) NOT NULL,
    kennung VARCHAR(64) NOT NULL DEFAULT '',    -- Inventarnummer / Aufkleber
    site_id INT NULL,
    floor_id INT NULL,
    plan_x REAL NULL,
    plan_y REAL NULL,
    hersteller VARCHAR(190) NOT NULL DEFAULT '',
    modell VARCHAR(190) NOT NULL DEFAULT '',
    seriennummer VARCHAR(190) NOT NULL DEFAULT '',
    nennleistung_kva REAL NULL,
    nennstrom_a REAL NULL,
    spannung_v INT NOT NULL DEFAULT 400,
    phasen INT NOT NULL DEFAULT 3,
    status VARCHAR(32) NOT NULL DEFAULT 'geplant', -- geplant | aufgebaut | in_betrieb | abgebaut | defekt
    eigenschaften {{JSON}} NULL,                -- je Art: Tankinhalt, Batteriekapazität, Zählernummer …
    steuerung_gesperrt {{BOOL}} NOT NULL DEFAULT 0,
    ist_vorlage {{BOOL}} NOT NULL DEFAULT 0,
    notizen {{TEXT}} NULL,
    sync_key VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE INDEX idx_objekte_ort ON objekte (site_id, floor_id);
;--split
-- Schutzorgane in einer Verteilung (oder am Generator): Hauptschalter,
-- RCD, RCBO, Leitungsschutzschalter, NH-Sicherung. Sie bilden einen Baum:
-- ein LS hängt hinter einem RCD, der RCD hinter dem Hauptschalter.
CREATE TABLE schutzorgane (
    id {{PK}},
    objekt_id INT NOT NULL,
    art VARCHAR(16) NOT NULL DEFAULT 'ls',          -- hauptschalter | rcd | rcbo | ls | nh | sonstiges
    bezeichnung VARCHAR(64) NOT NULL DEFAULT '',     -- Betriebsmittelkennzeichen, z. B. Q1, F3
    nennstrom_a REAL NULL,
    charakteristik VARCHAR(8) NOT NULL DEFAULT '',   -- B, C, D, K, gG …
    fehlerstrom_ma INT NULL,                         -- RCD/RCBO: 30, 300 …
    rcd_typ VARCHAR(8) NOT NULL DEFAULT '',          -- A, F, B, B+
    pole INT NOT NULL DEFAULT 3,
    vorgeschaltet_id INT NULL,                       -- das Schutzorgan davor
    notizen {{TEXT}} NULL,
    reihenfolge INT NOT NULL DEFAULT 0,
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_schutzorgane_objekt ON schutzorgane (objekt_id, reihenfolge);
;--split
-- Die einzelnen Buchsen und Stecker einer Anlage. Eine Zeile je Anschluss,
-- damit jedes Kabel an genau einem Anschluss hängen kann. Abgänge hängen an
-- einem Schutzorgan – daraus ergibt sich, welcher RCD sie schützt.
CREATE TABLE anschluesse (
    id {{PK}},
    objekt_id INT NOT NULL,
    richtung VARCHAR(16) NOT NULL DEFAULT 'abgang', -- eingang | abgang
    steckertyp_id INT NOT NULL,
    nr INT NOT NULL DEFAULT 1,
    bezeichnung VARCHAR(64) NOT NULL DEFAULT '',
    schutzorgan_id INT NULL,
    phase VARCHAR(8) NOT NULL DEFAULT '',            -- bei einphasigen Abgängen: L1, L2, L3
    notizen {{TEXT}} NULL,
    reihenfolge INT NOT NULL DEFAULT 0,
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_anschluesse_objekt ON anschluesse (objekt_id, richtung, reihenfolge);
;--split
-- Linien auf der Karte: Kabel (Verbindung zwischen zwei Anlagen), freie
-- Kabelwege (Trassen) und Kabelbrücken.
CREATE TABLE leitungen (
    id {{PK}},
    art VARCHAR(32) NOT NULL DEFAULT 'kabel',   -- kabel | kabelweg | kabelbruecke
    floor_id INT NULL,
    name VARCHAR(190) NOT NULL DEFAULT '',
    von_objekt_id INT NULL,
    von_anschluss_id INT NULL,
    zu_objekt_id INT NULL,
    zu_anschluss_id INT NULL,
    kabeltyp VARCHAR(64) NOT NULL DEFAULT '',   -- z. B. H07RN-F
    adern INT NULL,
    querschnitt_mm2 REAL NULL,
    laenge_m REAL NULL,                         -- von Hand; leer = aus dem Maßstab
    anzahl INT NOT NULL DEFAULT 1,              -- bei Kabelbrücken: Anzahl der Elemente
    modell VARCHAR(190) NOT NULL DEFAULT '',    -- bei Kabelbrücken: z. B. 5-Kanal
    punkte {{JSON}} NULL,                       -- [[x,y], …] in Prozent des Plans
    farbe VARCHAR(16) NOT NULL DEFAULT '',
    notizen {{TEXT}} NULL,
    sync_key VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE INDEX idx_leitungen_floor ON leitungen (floor_id);
;--split
CREATE INDEX idx_leitungen_von ON leitungen (von_objekt_id);
;--split
CREATE INDEX idx_leitungen_zu ON leitungen (zu_objekt_id);
