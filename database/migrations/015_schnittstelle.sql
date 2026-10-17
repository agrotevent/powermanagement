-- Schnittstelle (API) zwischen Instanzen – dasselbe Protokoll wie MasterAdmin
-- (/api/v1, Token „mtma_…“). Als 015 geplant (014: Übergabeberichte);
-- umbenennen nur, solange sie noch nirgends eingespielt ist.
--
-- Seite A (teilt): api_tokens, api_token_standorte, api_vorschlaege,
--   api_zuordnungen und die Prüfsumme der Plandatei je Etage
--   (floors.plan_sha256/plan_sha_marke).
-- Seite B (übernimmt): api_partner und die partner_*-Spalten (auch
--   partner_gesendet an Anlagen und Leitungen). Der eigene
--   sync_key bleibt dabei unangetastet: an ihm hängt der Location-Abgleich
--   über Git und alles, was diese Instanz selbst weitergibt.
--
-- token_hash  sha256(Token) hex – darüber wird angemeldet.
-- token_enc   Crypto::encrypt(Token) – nur für „Anzeigen“; beim Widerrufen geleert.
-- user_id     gilt nur, solange dieser Benutzer aktiv und gültig ist.
CREATE TABLE api_tokens (
    id {{PK}},
    name VARCHAR(190) NOT NULL,
    notiz VARCHAR(500) NOT NULL DEFAULT '',
    token_prefix VARCHAR(16) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    token_enc {{TEXT}} NULL,
    befugnisse {{TEXT}} NULL,
    user_id INTEGER NULL,
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL,
    expires_at {{TS}} NULL,
    last_used_at {{TS}} NULL,
    last_ip VARCHAR(64) NOT NULL DEFAULT '',
    revoked_at {{TS}} NULL,
    revoked_by VARCHAR(64) NOT NULL DEFAULT ''
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_api_tokens_hash ON api_tokens (token_hash)
;--split
-- Welche Standorte ein Token sieht (alle Etagen, auch künftige) und ob es
-- Änderungen dazu schicken darf. Eigene Tabelle statt freigaben: dort prüft
-- die Benutzerverwaltung jedes Subjekt als Benutzer oder Rolle.
CREATE TABLE api_token_standorte (
    id {{PK}},
    token_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    darf_bearbeiten {{BOOL}} NOT NULL DEFAULT 0,
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_api_token_standorte ON api_token_standorte (token_id, site_id)
;--split
-- Eingegangene Änderungen anderer Instanzen. daten = {location, strom?} als
-- LONGTEXT unter MySQL (TEXT endete bei 64 KB). Dateien unter
-- storage/vorschlaege/<id>/ mit Zufallsnamen; dateien = {"plaene/x.svg":
-- {datei, sha256, bytes, mime}}.
-- status offen | angenommen | abgelehnt | ersetzt
-- Was ein Token mit „plaene.schreiben“ direkt übernimmt, steht hier gleich
-- als angenommen (entschieden_von = api:<Name>, entscheidung.direkt = true).
CREATE TABLE api_vorschlaege (
    id {{PK}},
    token_id INTEGER NOT NULL,
    token_name VARCHAR(190) NOT NULL DEFAULT '',
    absender VARCHAR(190) NOT NULL DEFAULT '',
    site_id INTEGER NULL,
    site_key VARCHAR(64) NOT NULL,
    basis_stand VARCHAR(64) NOT NULL DEFAULT '',
    daten {{JSON}} NOT NULL,
    dateien {{JSON}} NULL,
    bytes {{BIGINT}} NOT NULL DEFAULT 0,
    notiz {{TEXT}} NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'offen',
    created_at {{TS}} NOT NULL,
    entschieden_von VARCHAR(64) NOT NULL DEFAULT '',
    entschieden_at {{TS}} NULL,
    entscheidung {{JSON}} NULL
){{ENGINE}}
;--split
CREATE INDEX idx_api_vorschlaege_status ON api_vorschlaege (status, created_at)
;--split
CREATE INDEX idx_api_vorschlaege_token ON api_vorschlaege (token_id, status)
;--split
-- Prüfsumme der Plandatei zwischenspeichern: die Liste fragt sie für jede
-- Etage ab. marke = plan_file|Größe|mtime – stimmt sie nicht, neu rechnen.
ALTER TABLE floors ADD COLUMN plan_sha256 VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE floors ADD COLUMN plan_sha_marke VARCHAR(255) NOT NULL DEFAULT ''
;--split
-- Seite B: eingetragene Instanzen. produkt '' = MasterAdmin, 'powermanagement'.
CREATE TABLE api_partner (
    id {{PK}},
    name VARCHAR(190) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    token_enc {{TEXT}} NOT NULL,
    token_prefix VARCHAR(16) NOT NULL DEFAULT '',
    remote_name VARCHAR(190) NOT NULL DEFAULT '',
    remote_produkt VARCHAR(32) NOT NULL DEFAULT '',
    remote_befugnisse {{TEXT}} NULL,
    verified_at {{TS}} NULL,
    letzter_fehler VARCHAR(500) NOT NULL DEFAULT '',
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}}
;--split
-- partner_stand  „stand“, wie ihn die Gegenseite beim letzten Übernehmen lieferte.
-- partner_hash   eigener Fingerabdruck direkt danach („hier geändert“).
ALTER TABLE sites ADD COLUMN partner_id INTEGER NULL
;--split
ALTER TABLE sites ADD COLUMN partner_schluessel VARCHAR(190) NOT NULL DEFAULT ''
;--split
ALTER TABLE sites ADD COLUMN partner_stand VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE sites ADD COLUMN partner_hash VARCHAR(64) NOT NULL DEFAULT ''
;--split
ALTER TABLE sites ADD COLUMN partner_at {{TS}} NULL
;--split
CREATE INDEX idx_sites_partner ON sites (partner_id, partner_schluessel)
;--split
ALTER TABLE floors ADD COLUMN partner_schluessel VARCHAR(190) NOT NULL DEFAULT ''
;--split
ALTER TABLE objekte ADD COLUMN partner_schluessel VARCHAR(190) NOT NULL DEFAULT ''
;--split
CREATE INDEX idx_objekte_partner ON objekte (site_id, partner_schluessel)
;--split
ALTER TABLE leitungen ADD COLUMN partner_schluessel VARCHAR(190) NOT NULL DEFAULT ''
;--split
CREATE INDEX idx_leitungen_partner ON leitungen (floor_id, partner_schluessel)
;--split
-- Seite A: unter welchem Schlüssel eine andere Instanz eine Anlage oder
-- Leitung geschickt hat, die hier neu angelegt wurde.
--
-- Ein neuer Eintrag bekommt hier einen freien Schlüssel (freierSchluessel –
-- der der Gegenseite kann instanzweit schon vergeben sein). Ohne diese
-- Zuordnung legte jedes weitere Hochladen oder Annehmen eine Dublette an,
-- weil die Gegenseite den Eintrag weiter unter ihrem Schlüssel schickt.
-- Je Token: zwei Instanzen mit zufällig gleichem Schlüssel meinen nicht
-- dasselbe.
--
-- tabelle           objekte | leitungen
-- fremd_schluessel  der Schlüssel, wie das Token ihn geschickt hat
-- ziel_id           die Zeile hier (objekte.id bzw. leitungen.id)
CREATE TABLE api_zuordnungen (
    id {{PK}},
    token_id INTEGER NOT NULL,
    tabelle VARCHAR(16) NOT NULL,
    fremd_schluessel VARCHAR(190) NOT NULL,
    ziel_id INTEGER NOT NULL,
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_api_zuordnungen ON api_zuordnungen (token_id, tabelle, fremd_schluessel)
;--split
CREATE INDEX idx_api_zuordnungen_ziel ON api_zuordnungen (tabelle, ziel_id)
;--split
-- Seite B: unter welchem Schlüssel ein eigener Eintrag (noch ohne
-- partner_schluessel) zuletzt an die verknüpfte Instanz ging. Meist der
-- eigene sync_key; kollidiert der mit dem Schlüssel eines anderen Eintrags
-- dort, ein freier. Daran erkennt das nächste Übernehmen den Eintrag wieder
-- („gesendet_als“), statt ihn ein zweites Mal anzulegen.
ALTER TABLE objekte ADD COLUMN partner_gesendet VARCHAR(190) NOT NULL DEFAULT ''
;--split
ALTER TABLE leitungen ADD COLUMN partner_gesendet VARCHAR(190) NOT NULL DEFAULT ''
