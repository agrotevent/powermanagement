-- Kern: Benutzer, Rollen, Rechte, Audit, Einstellungen.
--
-- Anders als in MasterAdmin tragen Konto und Rollenzuweisung einen
-- Gültigkeitszeitraum: ein Zugang für einen Veranstalter endet mit der
-- Veranstaltung, ohne dass jemand daran denken muss, ihn abzuschalten.
CREATE TABLE users (
    id {{PK}},
    username VARCHAR(64) NOT NULL UNIQUE,
    full_name VARCHAR(190) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    is_active {{BOOL}} NOT NULL DEFAULT 1,
    must_change_password {{BOOL}} NOT NULL DEFAULT 0,
    gueltig_ab {{TS}} NULL,
    gueltig_bis {{TS}} NULL,
    notizen {{TEXT}} NULL,
    failed_logins INT NOT NULL DEFAULT 0,
    locked_until {{TS}} NULL,
    last_login_at {{TS}} NULL,
    last_login_ip VARCHAR(64) NULL,
    created_at {{TS}} NOT NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
;--split
CREATE TABLE roles (
    id {{PK}},
    role_key VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    description {{TEXT}} NULL,
    is_system {{BOOL}} NOT NULL DEFAULT 0,
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE TABLE role_permissions (
    id {{PK}},
    role_id INT NOT NULL,
    permission VARCHAR(64) NOT NULL
){{ENGINE}};
;--split
CREATE UNIQUE INDEX idx_role_permission ON role_permissions (role_id, permission);
;--split
CREATE TABLE user_roles (
    id {{PK}},
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    gueltig_ab {{TS}} NULL,
    gueltig_bis {{TS}} NULL
){{ENGINE}};
;--split
CREATE UNIQUE INDEX idx_user_role ON user_roles (user_id, role_id);
;--split
CREATE TABLE audit_log (
    id {{PK}},
    user_id INT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(64) NOT NULL DEFAULT '',
    target_id VARCHAR(64) NOT NULL DEFAULT '',
    details {{JSON}} NULL,
    ip VARCHAR(64) NOT NULL DEFAULT '',
    created_at {{TS}} NOT NULL
){{ENGINE}};
;--split
CREATE INDEX idx_audit_created ON audit_log (created_at);
;--split
CREATE TABLE settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value {{TEXT}} NULL,
    updated_at {{TS}} NULL
){{ENGINE}};
