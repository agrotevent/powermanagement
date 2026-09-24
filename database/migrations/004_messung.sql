-- Messung und Steuerung.
--
-- Shelly-Geräte melden sich selbst über MQTT und brauchen keinen Eintrag
-- hier – sie tauchen in der InfluxDB auf, sobald Node-RED ihre Werte
-- schreibt. Eingetragen werden nur Quellen, die aktiv abgefragt werden:
-- Modbus TCP (Victron GX, ComAp über WireGuard), ComAp WebSupervisor und
-- Victron VRM. Und Shelly-Geräte, die geschaltet werden sollen.
CREATE TABLE datenquellen (
    id {{PK}},
    art VARCHAR(32) NOT NULL,                   -- modbus | comap_cloud | victron_vrm | shelly
    name VARCHAR(190) NOT NULL,
    geraet VARCHAR(128) NOT NULL,               -- Kennung in der InfluxDB (Tag „geraet“)
    host VARCHAR(190) NOT NULL DEFAULT '',
    port INT NOT NULL DEFAULT 502,
    unit_id INT NOT NULL DEFAULT 1,
    profil VARCHAR(64) NOT NULL DEFAULT '',     -- Registerprofil (Modbus)
    extern_id VARCHAR(190) NOT NULL DEFAULT '', -- unitGuid (ComAp), idSite (VRM), MQTT-Präfix (Shelly)
    zugang_enc {{TEXT}} NULL,                   -- verschlüsselt: Schlüssel, Token, Kennwort
    intervall_s INT NOT NULL DEFAULT 5,
    aktiv {{BOOL}} NOT NULL DEFAULT 1,
    letzter_kontakt {{TS}} NULL,
    letzter_versuch {{TS}} NULL,
    letzter_fehler {{TEXT}} NULL,
    notizen {{TEXT}} NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE UNIQUE INDEX idx_datenquellen_geraet ON datenquellen (geraet);
;--split
-- Registerprofile für Modbus TCP. Mitgeliefert: Victron GX (System),
-- Victron VE.Bus, ComAp InteliGen 200 (Vorlage). Eigene lassen sich anlegen.
CREATE TABLE registerprofile (
    id {{PK}},
    schluessel VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    hersteller VARCHAR(64) NOT NULL DEFAULT '',
    beschreibung {{TEXT}} NULL,
    register_json {{JSON}} NOT NULL,            -- Liste der Werte
    befehle_json {{JSON}} NULL,                 -- Liste der Schaltbefehle
    ist_mitgeliefert {{BOOL}} NOT NULL DEFAULT 0,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
-- Messgeräte in einer Anlage: welcher Messwert gehört wohin – an die
-- Einspeisung der Anlage, an ein Schutzorgan oder an einen einzelnen Abgang.
-- Jeder Messpunkt lässt sich in Karte und Verteilerplan anklicken und in
-- die Auswertung übernehmen.
CREATE TABLE messpunkte (
    id {{PK}},
    objekt_id INT NOT NULL,
    anschluss_id INT NULL,
    schutzorgan_id INT NULL,
    geraet VARCHAR(128) NOT NULL,
    kanal VARCHAR(64) NOT NULL DEFAULT '',
    rolle VARCHAR(32) NOT NULL DEFAULT 'einspeisung', -- einspeisung | abgang | verbrauch | erzeugung | batterie
    bezeichnung VARCHAR(190) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_messpunkte_objekt ON messpunkte (objekt_id);
;--split
CREATE INDEX idx_messpunkte_geraet ON messpunkte (geraet, kanal);
;--split
-- Jeder Schaltbefehl, ob erfolgreich oder nicht.
CREATE TABLE schaltvorgaenge (
    id {{PK}},
    objekt_id INT NULL,
    datenquelle_id INT NULL,
    geraet VARCHAR(128) NOT NULL DEFAULT '',
    befehl VARCHAR(64) NOT NULL,
    kanal VARCHAR(64) NOT NULL DEFAULT '',
    ergebnis VARCHAR(16) NOT NULL DEFAULT 'offen', -- ok | fehler
    meldung {{TEXT}} NULL,
    user_id INT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_schalt_objekt ON schaltvorgaenge (objekt_id, created_at);
