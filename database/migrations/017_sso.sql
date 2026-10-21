-- Anmeldung über SSO (OpenID Connect, etwa Authentik) – optional.
--
-- Ein Konto wird mit einer Identität beim Anmeldedienst nur über Aussteller
-- (iss) und Subjekt (sub) verknüpft: laut OIDC Core 5.7 die einzigen
-- stabilen Angaben. Benutzername und E-Mail-Adresse kann dort jemand ändern
-- – sie verknüpfen nie von selbst.
--
-- schluessel  sha256(aussteller "\n" subjekt), hex: eindeutig und ohne
--             Kollationsfragen (MySQL vergliche subjekt sonst ohne Groß/Klein).
--             Nach dem Lesen werden aussteller und subjekt in PHP exakt verglichen.
-- weg         profil | freischaltung | anlage | cli – wie die Verknüpfung entstand
-- name_dort   preferred_username der letzten Anmeldung (nur Anzeige)
-- gruppen     Gruppennamen der letzten Anmeldung, JSON, höchstens 50 (nur Anzeige;
--             die Rechte stehen in user_roles)
-- abgemeldet_am  SSO-Sitzungen, die bis dahin begonnen haben, sind zu Ende
CREATE TABLE sso_identitaeten (
    id {{PK}},
    user_id INT NOT NULL,
    schluessel CHAR(64) NOT NULL,
    aussteller VARCHAR(255) NOT NULL,
    subjekt VARCHAR(255) NOT NULL,
    weg VARCHAR(16) NOT NULL,
    name_dort VARCHAR(190) NOT NULL DEFAULT '',
    gruppen {{JSON}} NULL,
    angelegt_von VARCHAR(64) NOT NULL DEFAULT '',
    angelegt_am {{TS}} NOT NULL,
    zuletzt_am {{TS}} NULL,
    abgemeldet_am {{TS}} NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_sso_identitaeten ON sso_identitaeten (schluessel)
;--split
CREATE UNIQUE INDEX idx_sso_identitaeten_konto ON sso_identitaeten (user_id, aussteller)
;--split
-- Gruppe beim Anmeldedienst → Rolle (art = rolle, ziel_id = roles.id) oder
-- → Kunde des Kundenbereichs (art = kunde, ziel_id = kunden.id, nur mit
-- der Einstellung sso.kunden). Gruppennamen werden in PHP exakt verglichen.
-- Freigaben kommen nie aus Gruppen.
CREATE TABLE sso_zuordnungen (
    id {{PK}},
    gruppe VARCHAR(150) NOT NULL,
    art VARCHAR(8) NOT NULL,
    ziel_id INT NOT NULL,
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_sso_zuordnungen ON sso_zuordnungen (gruppe, art, ziel_id)
;--split
-- Anmeldeanfragen: jemand hat sich beim Anmeldedienst angemeldet, hier gibt
-- es aber (noch) kein Konto. Die Verwaltung schaltet frei (verknüpft mit
-- einem Konto oder legt eins an) oder verwirft. Gespeichert sind nur die
-- geprüften Angaben des ID-Tokens; nach 30 Tagen räumt „wartung“ auf.
-- grund: kein_konto | name (Benutzername vergeben oder ungültig)
CREATE TABLE sso_anfragen (
    id {{PK}},
    schluessel CHAR(64) NOT NULL,
    aussteller VARCHAR(255) NOT NULL,
    subjekt VARCHAR(255) NOT NULL,
    name_dort VARCHAR(190) NOT NULL DEFAULT '',
    voller_name VARCHAR(190) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    email_bestaetigt {{BOOL}} NOT NULL DEFAULT 0,
    gruppen {{JSON}} NULL,
    grund VARCHAR(16) NOT NULL DEFAULT '',
    anzahl INT NOT NULL DEFAULT 1,
    erste_am {{TS}} NOT NULL,
    letzte_am {{TS}} NOT NULL
){{ENGINE}}
;--split
CREATE UNIQUE INDEX idx_sso_anfragen ON sso_anfragen (schluessel)
;--split
-- Woher eine Rollenzuweisung kommt: hand (Benutzerverwaltung) oder sso
-- (Gruppe bei der letzten SSO-Anmeldung; gueltig_bis = Ende jener Sitzung).
-- Eine Anmeldung mit Kennwort entfernt die sso-Zeilen.
ALTER TABLE user_roles ADD COLUMN quelle VARCHAR(8) NOT NULL DEFAULT 'hand'
;--split
-- nur_sso: dieses Konto meldet sich nur über SSO an (automatisch angelegte
-- Konten immer); ein Kennwort gilt dafür nicht.
ALTER TABLE users ADD COLUMN nur_sso {{BOOL}} NOT NULL DEFAULT 0
