-- ============================================================
-- USER MANAGEMENT SYSTEM — Full SQL Schema
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. ROLES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    id          SERIAL       PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

INSERT INTO roles (name, description) VALUES
    ('Super Admin', 'Full system access across all zones'),
    ('Admin',       'Administrative access within assigned zone'),
    ('Manager',     'Management-level access'),
    ('Staff',       'Standard staff access')
ON CONFLICT (name) DO NOTHING;


-- ────────────────────────────────────────────────────────────
-- 2. USERS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    user_id      VARCHAR(20)  PRIMARY KEY,
    auth_id      UUID         UNIQUE,
    first_name   VARCHAR(100) NOT NULL,
    last_name    VARCHAR(100) NOT NULL,
    email        VARCHAR(255) NOT NULL UNIQUE,
    zone         VARCHAR(100) NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'Active'
                     CHECK (status IN ('Active','Inactive','Suspended','Locked')),
    emp_id       VARCHAR(50)  UNIQUE,
    phone        VARCHAR(50),
    permissions  TEXT[]       NOT NULL DEFAULT '{}',
    remarks      TEXT,
    last_login   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_status     ON users (status);
CREATE INDEX IF NOT EXISTS idx_users_zone       ON users (zone);
CREATE INDEX IF NOT EXISTS idx_users_email      ON users (email);
CREATE INDEX IF NOT EXISTS idx_users_emp_id     ON users (emp_id);
CREATE INDEX IF NOT EXISTS idx_users_auth_id    ON users (auth_id);
CREATE INDEX IF NOT EXISTS idx_users_created_at ON users (created_at DESC);


-- ────────────────────────────────────────────────────────────
-- 3. USER ROLES (many-to-many)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_roles (
    id          SERIAL       PRIMARY KEY,
    user_id     VARCHAR(20)  NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role_id     INT          NOT NULL REFERENCES roles(id)      ON DELETE CASCADE,
    assigned_by VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    assigned_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, role_id)
);

CREATE INDEX IF NOT EXISTS idx_user_roles_user_id ON user_roles (user_id);
CREATE INDEX IF NOT EXISTS idx_user_roles_role_id ON user_roles (role_id);


-- ────────────────────────────────────────────────────────────
-- 4. AUDIT LOGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
    id           BIGSERIAL    PRIMARY KEY,
    user_id      VARCHAR(20)  NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    action       VARCHAR(255) NOT NULL,
    performed_by VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    ip_address   VARCHAR(45),
    remarks      TEXT,
    is_sa        BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_user_id    ON audit_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs (created_at DESC);


-- ────────────────────────────────────────────────────────────
-- 5. VIEW: users_with_roles
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW users_with_roles AS
SELECT
    u.user_id,
    u.auth_id,
    u.first_name,
    u.last_name,
    CONCAT(u.first_name, ' ', u.last_name) AS full_name,
    u.email,
    u.zone,
    u.status,
    u.emp_id,
    u.phone,
    u.permissions,
    u.remarks,
    u.last_login,
    u.created_at,
    u.updated_at,
    ARRAY_AGG(r.name ORDER BY r.name) FILTER (WHERE r.name IS NOT NULL) AS roles
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.user_id
LEFT JOIN roles      r  ON r.id       = ur.role_id
GROUP BY
    u.user_id,
    u.auth_id,
    u.first_name,
    u.last_name,
    u.email,
    u.zone,
    u.status,
    u.emp_id,
    u.phone,
    u.permissions,
    u.remarks,
    u.last_login,
    u.created_at,
    u.updated_at;


-- ────────────────────────────────────────────────────────────
-- 6. AUTO-UPDATE updated_at TRIGGER
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_users_updated_at ON users;
CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION set_updated_at();


-- ────────────────────────────────────────────────────────────
-- 7. ROW LEVEL SECURITY
-- ────────────────────────────────────────────────────────────
ALTER TABLE users      DISABLE ROW LEVEL SECURITY;
ALTER TABLE roles      DISABLE ROW LEVEL SECURITY;
ALTER TABLE user_roles DISABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- INSERT SUPER ADMIN USER
-- Run this AFTER schema.sql
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- STEP 1: Create login credentials in Supabase Auth
-- ← CHANGE email and password before running
-- ────────────────────────────────────────────────────────────
INSERT INTO auth.users (
    id,
    instance_id,
    email,
    encrypted_password,
    email_confirmed_at,
    role,
    aud,
    created_at,
    updated_at,
    raw_app_meta_data,
    raw_user_meta_data,
    is_super_admin,
    confirmation_token,
    recovery_token,
    email_change_token_new,
    email_change
)
VALUES (
    extensions.uuid_generate_v4(),
    '00000000-0000-0000-0000-000000000000',
    'superadmin@company.com',                -- ← CHANGE: your email
    crypt('SuperAdmin@1234', gen_salt('bf')), -- ← CHANGE: your password
    NOW(),
    'authenticated',
    'authenticated',
    NOW(),
    NOW(),
    '{"provider":"email","providers":["email"]}',
    '{}',
    FALSE,
    '',
    '',
    '',
    ''
);


-- ────────────────────────────────────────────────────────────
-- STEP 2: Insert into app users table
-- ← CHANGE fields as needed
-- ────────────────────────────────────────────────────────────
INSERT INTO users (
    user_id,
    auth_id,
    first_name,
    last_name,
    email,
    zone,
    status,
    emp_id,
    phone,
    remarks
)
VALUES (
    'USR-1001',
    (SELECT id FROM auth.users WHERE email = 'superadmin@company.com'),
    'Super',                    -- ← CHANGE: first name
    'Admin',                    -- ← CHANGE: last name
    'superadmin@company.com',   -- ← CHANGE: must match Step 1 email
    'Head Office',              -- ← CHANGE: zone
    'Active',
    'EMP-0001',
    '',                         -- ← CHANGE: phone (optional)
    'Initial Super Admin account'
);


-- ────────────────────────────────────────────────────────────
-- STEP 3: Assign Super Admin role
-- ────────────────────────────────────────────────────────────
INSERT INTO user_roles (user_id, role_id, assigned_by)
SELECT 'USR-1001', id, 'System'
FROM roles
WHERE name = 'Super Admin';


-- ────────────────────────────────────────────────────────────
-- STEP 4: Verify
-- ────────────────────────────────────────────────────────────
SELECT * FROM users_with_roles WHERE user_id = 'USR-1001';