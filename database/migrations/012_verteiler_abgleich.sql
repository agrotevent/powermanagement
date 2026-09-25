-- Verteiler-Abgleich: Standard-Verteiler aus dem Katalog und Stücke aus dem
-- Inventar gehen wie die Locations über das gemeinsame Repository
-- (verteiler/typen/<schlüssel>.json, verteiler/inventar/<schlüssel>.json).
--
-- sync_key ist der Dateiname dort (Typen: objekte.sync_key, den gibt es
-- schon). synced_hash ist der Fingerabdruck des Inhalts beim letzten
-- Abgleich – daran erkennt der Abgleich, ob sich seitdem hier, dort oder
-- auf beiden Seiten etwas geändert hat.
ALTER TABLE objekte ADD COLUMN synced_hash VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE objekte ADD COLUMN synced_at {{TS}} NULL
;--split
ALTER TABLE inventar ADD COLUMN sync_key VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE inventar ADD COLUMN synced_hash VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE inventar ADD COLUMN synced_at {{TS}} NULL
;--split
CREATE INDEX idx_inventar_sync ON inventar (sync_key)
