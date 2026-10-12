-- Eigene MQTT-Zugänge je Shelly: schrittweise Umstellung vom gemeinsamen
-- Zugang „shelly“.
--
-- benutzer   Broker-Benutzer: Gen2 = MQTT-Präfix Byte für Byte
--            (shellypro4pm-a0b1c2d3e4f5, shelly/halle-3), Gen1 = Geräte-ID
--            (shelly1-B929CC, Präfix shellies/<ID>). Mosquitto gibt ihm über
--            „pattern readwrite %u/#“ bzw. „shellies/%u/#“ nur den eigenen
--            Themenbaum.
-- praefix    MQTT-Präfix des Geräts (Themenbaum <praefix>/#).
-- geraet     Name in der InfluxDB (letztes Segment, klein).
-- kennwort_hash  Hash, wie er in der Kennwortdatei des Brokers steht – das
--            Kennwort selbst wird nie gespeichert.
-- zustand    angelegt | uebertragen | pruefung | eigen
-- sperre_bis Ende der Prüf-Sperre des gemeinsamen Zugangs (Zustand pruefung).
CREATE TABLE mqtt_zugaenge (
    id {{PK}},
    benutzer VARCHAR(80) NOT NULL,
    praefix VARCHAR(100) NOT NULL,
    geraet VARCHAR(80) NOT NULL,
    generation INTEGER NOT NULL,
    kennwort_hash VARCHAR(120) NOT NULL,
    zustand VARCHAR(16) NOT NULL DEFAULT 'angelegt',
    sperre_bis {{TS}} NULL,
    neustart_noetig {{BOOL}} NOT NULL DEFAULT 0,
    kennwort_am {{TS}} NOT NULL,
    uebertragen_am {{TS}} NULL,
    bestaetigt_am {{TS}} NULL,
    meldung VARCHAR(255) NOT NULL DEFAULT '',
    created_by INTEGER NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_mqtt_zugaenge_benutzer ON mqtt_zugaenge (benutzer)
;--split
CREATE UNIQUE INDEX idx_mqtt_zugaenge_praefix ON mqtt_zugaenge (praefix)
;--split
CREATE UNIQUE INDEX idx_mqtt_zugaenge_geraet ON mqtt_zugaenge (geraet)
