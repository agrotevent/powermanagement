-- Standorte und Etagen mit Grundriss – dieselbe Gestalt wie in MasterAdmin,
-- damit beide Werkzeuge dieselben Locations aus dem gemeinsamen
-- Repository lesen und schreiben können (sync_key = Ordnername dort).
CREATE TABLE sites (
    id {{PK}},
    name VARCHAR(190) NOT NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    notes {{TEXT}} NULL,
    sync_key VARCHAR(64) NOT NULL DEFAULT '',
    synced_at {{TS}} NULL,
    synced_commit VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE TABLE floors (
    id {{PK}},
    site_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    level_no INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    plan_file VARCHAR(255) NOT NULL DEFAULT '',
    plan_type VARCHAR(16) NOT NULL DEFAULT '',
    plan_page INT NOT NULL DEFAULT 1,
    plan_original_name VARCHAR(255) NOT NULL DEFAULT '',
    plan_uploaded_at {{TS}} NULL,
    plan_uploaded_by VARCHAR(64) NOT NULL DEFAULT '',
    scale_note VARCHAR(190) NOT NULL DEFAULT '',
    notes {{TEXT}} NULL,
    sync_key VARCHAR(64) NOT NULL DEFAULT '',
    -- Maßstab: zwei Punkte auf dem Plan (Prozent) und ihr Abstand in Metern.
    massstab_ax REAL NULL,
    massstab_ay REAL NULL,
    massstab_bx REAL NULL,
    massstab_by REAL NULL,
    massstab_m REAL NULL,
    massstab_verhaeltnis REAL NULL,
    massstab_von VARCHAR(190) NOT NULL DEFAULT '',
    massstab_am {{TS}} NULL,
    -- Was MasterAdmin auf dieser Etage führt (Dosen, Racks, Accesspoints),
    -- unverändert aus location.json. Nur zur Orientierung auf der Karte;
    -- gepflegt wird es dort, nicht hier.
    fremdbestand {{JSON}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE INDEX idx_floors_site ON floors (site_id, sort_order);
