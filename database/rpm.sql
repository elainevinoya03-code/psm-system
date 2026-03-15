-- ============================================================
-- RPM — Role & Permission Management Migration
-- Run AFTER user.sql (roles, users, user_roles must exist)
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. EXTEND roles TABLE
-- Add columns needed by the RPM module.
-- Safe to run multiple times — IF NOT EXISTS guards.
-- ────────────────────────────────────────────────────────────
ALTER TABLE roles
    ADD COLUMN IF NOT EXISTS role_type   VARCHAR(50)  NOT NULL DEFAULT 'custom'
                                 CHECK (role_type IN ('Super Admin','Admin','Manager','Staff','custom')),
    ADD COLUMN IF NOT EXISTS active      BOOLEAN      NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW();

-- Back-fill role_type for the 4 seeded system roles
UPDATE roles SET role_type = name WHERE name IN ('Super Admin','Admin','Manager','Staff');

-- Auto-update updated_at
CREATE OR REPLACE FUNCTION set_roles_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_roles_updated_at ON roles;
CREATE TRIGGER trg_roles_updated_at
    BEFORE UPDATE ON roles
    FOR EACH ROW EXECUTE FUNCTION set_roles_updated_at();

CREATE INDEX IF NOT EXISTS idx_roles_active    ON roles (active);
CREATE INDEX IF NOT EXISTS idx_roles_role_type ON roles (role_type);


-- ────────────────────────────────────────────────────────────
-- 2. role_permissions
-- One row per (role_id, module, permission_key).
-- permission_key: V | C | E | A | D
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS role_permissions (
    id             BIGSERIAL   PRIMARY KEY,
    role_id        INT         NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    module         VARCHAR(255) NOT NULL,
    permission_key VARCHAR(1)  NOT NULL CHECK (permission_key IN ('V','C','E','A','D')),
    enabled        BOOLEAN     NOT NULL DEFAULT FALSE,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (role_id, module, permission_key)
);

CREATE INDEX IF NOT EXISTS idx_rp_role_id ON role_permissions (role_id);
CREATE INDEX IF NOT EXISTS idx_rp_module  ON role_permissions (module);
CREATE INDEX IF NOT EXISTS idx_rp_enabled ON role_permissions (enabled);

CREATE OR REPLACE FUNCTION set_rp_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_rp_updated_at ON role_permissions;
CREATE TRIGGER trg_rp_updated_at
    BEFORE UPDATE ON role_permissions
    FOR EACH ROW EXECUTE FUNCTION set_rp_updated_at();


-- ────────────────────────────────────────────────────────────
-- 3. role_audit_log
-- Immutable trail of every action on a role or permission.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS role_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    role_id        INT          REFERENCES roles(id) ON DELETE SET NULL,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL DEFAULT 'Super Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-edit',
    css_class      VARCHAR(20)  NOT NULL DEFAULT 'ad-b',
    -- ad-c = created    ad-b = updated/saved    ad-r = deactivated
    -- ad-c = activated  ad-t = cloned           ad-p = preset applied
    note           TEXT         NOT NULL DEFAULT '',
    ip_address     VARCHAR(45),
    is_super_admin BOOLEAN      NOT NULL DEFAULT TRUE,
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ral_role_id   ON role_audit_log (role_id);
CREATE INDEX IF NOT EXISTS idx_ral_occurred  ON role_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_ral_is_sa     ON role_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW: roles with user count + permission summary
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW rpm_role_summary AS
SELECT
    r.*,
    COALESCE(uc.user_count, 0)  AS user_count,
    COALESCE(pc.perm_count, 0)  AS perm_count,
    COALESCE(pc.enabled_count,0)AS enabled_count
FROM roles r
LEFT JOIN (
    SELECT role_id, COUNT(DISTINCT user_id) AS user_count
    FROM user_roles GROUP BY role_id
) uc ON uc.role_id = r.id
LEFT JOIN (
    SELECT role_id,
           COUNT(*)                              AS perm_count,
           COUNT(*) FILTER (WHERE enabled)       AS enabled_count
    FROM role_permissions GROUP BY role_id
) pc ON pc.role_id = r.id;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE role_permissions DISABLE ROW LEVEL SECURITY;
ALTER TABLE role_audit_log   DISABLE ROW LEVEL SECURITY;


-- ────────────────────────────────────────────────────────────
-- 6. VERIFY
-- ────────────────────────────────────────────────────────────
SELECT id, name, role_type, active, created_at FROM roles ORDER BY id;
SELECT COUNT(*) AS permission_rows FROM role_permissions;