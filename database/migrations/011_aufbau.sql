-- Aufbau: der Übergang von der Planung zum echten Aufbau – und danach der
-- Abgleich, was davon so gekommen ist.
--
-- Beim Start wird der Plan eines Standorts festgehalten (mobile Anlagen,
-- mobile Kabel, Kabelbrücken, Materialliste). Beim Aufbau wird jede Position
-- per Klick bestätigt, als geändert markiert oder als entfallen. Mit
-- „Aufbau fertig“ wird der Ist-Stand festgehalten; die Veranstaltung läuft
-- (Betrieb), bis sie beendet wird. Der Abgleich vergleicht Plan und Ist und
-- die gemessene Last mit dem, was geplant war.
CREATE TABLE aufbauten (
    id {{PK}},
    site_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'aufbau',   -- aufbau | betrieb | beendet
    gestartet_am {{TS}} NOT NULL,
    gestartet_von VARCHAR(64) NOT NULL DEFAULT '',
    betrieb_ab {{TS}} NULL,
    beendet_am {{TS}} NULL,
    plan {{JSON}} NULL,
    ist {{JSON}} NULL,
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE INDEX idx_aufbauten_site ON aufbauten (site_id, status)
;--split
-- Was beim Aufbau zu einer geplanten Position gesagt wurde.
-- art: objekt | leitung; status: bestaetigt | geaendert | entfallen
CREATE TABLE aufbau_posten (
    id {{PK}},
    aufbau_id INT NOT NULL,
    art VARCHAR(16) NOT NULL,
    ref_id INT NOT NULL,
    status VARCHAR(16) NOT NULL,
    laenge_ist REAL NULL,
    notiz VARCHAR(500) NOT NULL DEFAULT '',
    am {{TS}} NOT NULL,
    von VARCHAR(64) NOT NULL DEFAULT ''
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_aufbau_posten ON aufbau_posten (aufbau_id, art, ref_id)
