-- Inventar: Verteiler und Zwischenstecker aus dem Lager, mit ihren Shellys.
--
-- Ein Inventarstück ist ein bestimmtes Gerät mit Aufkleber (PD63-07, ZS-63-02).
-- Seine Shellys sind ihm fest zugeordnet: an der Einspeisung, an einzelnen
-- Abgängen – oder, beim Zwischenstecker, im Durchgang. Beim Aufbau wird es
-- in eine geplante Anlage eingesetzt (Einsatz); dann messen seine Shellys
-- dort, genau für die Dauer des Einsatzes.
CREATE TABLE inventar (
    id {{PK}},
    art VARCHAR(32) NOT NULL DEFAULT 'verteilung',  -- verteilung | zwischenstecker
    kennung VARCHAR(64) NOT NULL,                   -- Inventarnummer / Aufkleber
    name VARCHAR(190) NOT NULL DEFAULT '',
    vorlage_id INT NULL,                            -- Verteilung: Typ aus dem Katalog (Standard-Verteiler)
    steckertyp_id INT NULL,                         -- Zwischenstecker: Steckverbinder (CEE 63 A …)
    seriennummer VARCHAR(190) NOT NULL DEFAULT '',
    defekt {{BOOL}} NOT NULL DEFAULT 0,
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE INDEX idx_inventar_kennung ON inventar (kennung)
;--split
-- Welcher Kanal welchen Shellys wo im Inventarstück misst.
-- stelle: einspeisung | abgang:<nr> (Nummer des Abgangs im Katalog-Typ) | durchgang
CREATE TABLE inventar_messgeraete (
    id {{PK}},
    inventar_id INT NOT NULL,
    geraet VARCHAR(128) NOT NULL,
    kanal VARCHAR(64) NOT NULL DEFAULT '',
    stelle VARCHAR(32) NOT NULL DEFAULT 'einspeisung',
    bezeichnung VARCHAR(190) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE INDEX idx_inventar_messgeraete ON inventar_messgeraete (inventar_id)
;--split
-- Wo ein Inventarstück eingesetzt war: Anlage, beim Zwischenstecker der
-- Anschluss (leer = Einspeisung), von – bis (leer = noch dort).
CREATE TABLE einsaetze (
    id {{PK}},
    inventar_id INT NOT NULL,
    objekt_id INT NOT NULL,
    anschluss_id INT NULL,
    objekt_name VARCHAR(190) NOT NULL DEFAULT '',   -- für die Geschichte, falls die Anlage gelöscht wird
    ab {{TS}} NOT NULL,
    bis {{TS}} NULL,
    von VARCHAR(64) NOT NULL DEFAULT '',
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE INDEX idx_einsaetze_inventar ON einsaetze (inventar_id, bis)
;--split
CREATE INDEX idx_einsaetze_objekt ON einsaetze (objekt_id)
;--split
-- Messpunkte aus einem Einsatz gelten nur für seine Dauer. Leer = immer.
ALTER TABLE messpunkte ADD COLUMN einsatz_id INT NULL
;--split
ALTER TABLE messpunkte ADD COLUMN gueltig_ab {{TS}} NULL
;--split
ALTER TABLE messpunkte ADD COLUMN gueltig_bis {{TS}} NULL
;--split
-- Die Stromplanung pflegt das Inventar (neue Installationen bekommen das
-- Recht über die Standardrollen).
INSERT INTO role_permissions (role_id, permission) SELECT id, 'inventar.manage' FROM roles WHERE role_key = 'planung'
