-- Georeferenz eines Grundrisses.
--
-- Zwei Passpunkte: ein Punkt auf dem Plan (Prozent, wie überall) und seine
-- geografische Lage (WGS84, Grad). Daraus ergeben sich Maßstab, Drehung und
-- Lage des Plans – und damit Koordinaten jeder Anlage, die Navigation zum
-- Verteiler beim Aufbau und die eigene GPS-Position auf dem Plan.
-- Das Seitenverhältnis (Höhe durch Breite) kommt wie beim Maßstab aus dem
-- Browser.
ALTER TABLE floors ADD COLUMN geo_ax REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_ay REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_a_lat REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_a_lon REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_bx REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_by REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_b_lat REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_b_lon REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_verhaeltnis REAL NULL
;--split
ALTER TABLE floors ADD COLUMN geo_von VARCHAR(190) NOT NULL DEFAULT ''
;--split
ALTER TABLE floors ADD COLUMN geo_am {{TS}} NULL;
