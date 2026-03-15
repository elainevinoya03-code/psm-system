-- ============================================================
-- ALMS — Asset Lifecycle & Maintenance System: Asset Registry
-- Run AFTER user.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. ASSETS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_assets (
    id               BIGSERIAL     PRIMARY KEY,
    asset_id         VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. AST-2025-0001
    name             VARCHAR(255)  NOT NULL,
    category         VARCHAR(100)  NOT NULL,
    type             VARCHAR(100)  NOT NULL DEFAULT '',
    brand            VARCHAR(150)  NOT NULL DEFAULT '',
    serial           VARCHAR(100)  NOT NULL DEFAULT '',
    zone             VARCHAR(100)  NOT NULL,
    dept             VARCHAR(100)  NOT NULL DEFAULT '',
    purchase_date    DATE,
    purchase_cost    NUMERIC(15,2) NOT NULL DEFAULT 0,
    current_value    NUMERIC(15,2) NOT NULL DEFAULT 0,
    condition        VARCHAR(20)   NOT NULL DEFAULT 'Good'
                         CHECK (condition IN ('New','Good','Fair','Poor')),
    status           VARCHAR(30)   NOT NULL DEFAULT 'Active'
                         CHECK (status IN (
                             'Active','Assigned','Under Maintenance',
                             'Disposed','Lost/Stolen'
                         )),
    assignee         VARCHAR(150)  NOT NULL DEFAULT '',
    assign_date      DATE,
    return_date      DATE,
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_status      ON alms_assets (status);
CREATE INDEX IF NOT EXISTS idx_alms_category    ON alms_assets (category);
CREATE INDEX IF NOT EXISTS idx_alms_zone        ON alms_assets (zone);
CREATE INDEX IF NOT EXISTS idx_alms_assignee    ON alms_assets (assignee);
CREATE INDEX IF NOT EXISTS idx_alms_serial      ON alms_assets (serial);
CREATE INDEX IF NOT EXISTS idx_alms_created_at  ON alms_assets (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_alms_user        ON alms_assets (created_user_id);

CREATE OR REPLACE FUNCTION set_alms_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_alms_updated_at ON alms_assets;
CREATE TRIGGER trg_alms_updated_at
    BEFORE UPDATE ON alms_assets
    FOR EACH ROW EXECUTE FUNCTION set_alms_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. ASSET AUDIT LOG
-- Immutable trail of every action on an asset.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_asset_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    asset_id       BIGINT       NOT NULL REFERENCES alms_assets(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ad-s',
    -- ad-c = registered/created      ad-s = assigned/updated
    -- ad-a = returned/restored       ad-r = lost/stolen
    -- ad-o = transferred/maintenance ad-x = disposed
    -- ad-d = override
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_al_asset_id   ON alms_asset_audit_log (asset_id);
CREATE INDEX IF NOT EXISTS idx_alms_al_occurred   ON alms_asset_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_alms_al_is_sa      ON alms_asset_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. HELPER VIEW
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW alms_asset_summary AS
SELECT
    a.*,
    CASE
        WHEN a.return_date < CURRENT_DATE
             AND a.status = 'Assigned'
        THEN TRUE ELSE FALSE
    END AS is_overdue_return,
    CASE
        WHEN a.purchase_cost > 0
        THEN ROUND((a.current_value / a.purchase_cost) * 100, 2)
        ELSE 0
    END AS value_retention_pct
FROM alms_assets a;


-- ────────────────────────────────────────────────────────────
-- 4. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE alms_assets          DISABLE ROW LEVEL SECURITY;
ALTER TABLE alms_asset_audit_log DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- ALMS — Preventive Maintenance Schedules
-- Run AFTER user.sql and alms.sql (asset registry)
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. MAINTENANCE SCHEDULES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_maintenance_schedules (
    id               BIGSERIAL     PRIMARY KEY,
    schedule_id      VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. SCH-2025-0001
    asset_id         VARCHAR(30)   NOT NULL,                 -- snapshot of asset_id string
    asset_name       VARCHAR(255)  NOT NULL,                 -- snapshot of asset name
    asset_db_id      BIGINT        REFERENCES alms_assets(id) ON DELETE SET NULL,
    type             VARCHAR(50)   NOT NULL
                         CHECK (type IN (
                             'Inspection','Lubrication','Calibration',
                             'Cleaning','Replacement','Testing','Overhaul'
                         )),
    freq             VARCHAR(20)   NOT NULL
                         CHECK (freq IN ('Daily','Weekly','Monthly','Quarterly','Annual')),
    zone             VARCHAR(100)  NOT NULL,
    last_done        DATE,
    next_due         DATE          NOT NULL,
    tech_id          VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    tech             VARCHAR(150)  NOT NULL DEFAULT '',
    tech_color       VARCHAR(20)   NOT NULL DEFAULT '#6B7280',
    tech_zone        VARCHAR(100)  NOT NULL DEFAULT '',
    status           VARCHAR(30)   NOT NULL DEFAULT 'Scheduled'
                         CHECK (status IN (
                             'Scheduled','In Progress','Completed',
                             'Overdue','Skipped'
                         )),
    notes            TEXT          NOT NULL DEFAULT '',
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_ms_status     ON alms_maintenance_schedules (status);
CREATE INDEX IF NOT EXISTS idx_alms_ms_asset_id   ON alms_maintenance_schedules (asset_id);
CREATE INDEX IF NOT EXISTS idx_alms_ms_asset_db   ON alms_maintenance_schedules (asset_db_id);
CREATE INDEX IF NOT EXISTS idx_alms_ms_next_due   ON alms_maintenance_schedules (next_due ASC);
CREATE INDEX IF NOT EXISTS idx_alms_ms_zone       ON alms_maintenance_schedules (zone);
CREATE INDEX IF NOT EXISTS idx_alms_ms_tech_id    ON alms_maintenance_schedules (tech_id);
CREATE INDEX IF NOT EXISTS idx_alms_ms_created_at ON alms_maintenance_schedules (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_alms_ms_user       ON alms_maintenance_schedules (created_user_id);

CREATE OR REPLACE FUNCTION set_alms_ms_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_alms_ms_updated_at ON alms_maintenance_schedules;
CREATE TRIGGER trg_alms_ms_updated_at
    BEFORE UPDATE ON alms_maintenance_schedules
    FOR EACH ROW EXECUTE FUNCTION set_alms_ms_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. MAINTENANCE AUDIT LOG
-- Immutable trail of every action on a schedule.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_maintenance_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    schedule_id    BIGINT       NOT NULL REFERENCES alms_maintenance_schedules(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ad-s',
    -- ad-c = created              ad-s = updated/rescheduled
    -- ad-a = completed/done       ad-o = started/in progress
    -- ad-e = skipped              ad-d = override by Super Admin
    -- ad-r = flagged overdue
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_mal_schedule_id ON alms_maintenance_audit_log (schedule_id);
CREATE INDEX IF NOT EXISTS idx_alms_mal_occurred    ON alms_maintenance_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_alms_mal_is_sa       ON alms_maintenance_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. HELPER VIEW: schedule list with overdue flag
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW alms_maintenance_summary AS
SELECT
    s.*,
    CASE
        WHEN s.next_due < CURRENT_DATE
             AND s.status NOT IN ('Completed','Skipped')
        THEN TRUE ELSE FALSE
    END AS is_overdue,
    CASE
        WHEN s.next_due BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'
             AND s.status = 'Scheduled'
        THEN TRUE ELSE FALSE
    END AS is_due_soon,
    CASE
        WHEN s.tech_id IS NOT NULL
             AND s.tech_zone <> s.zone
        THEN TRUE ELSE FALSE
    END AS is_cross_zone
FROM alms_maintenance_schedules s;


-- ────────────────────────────────────────────────────────────
-- 4. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE alms_maintenance_schedules  DISABLE ROW LEVEL SECURITY;
ALTER TABLE alms_maintenance_audit_log  DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- ALMS — Repair & Service Logs
-- Run AFTER user.sql and alms.sql (asset registry)
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. REPAIR LOGS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_repair_logs (
    id               BIGSERIAL     PRIMARY KEY,
    log_id           VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. RSL-2025-0001
    asset_id         VARCHAR(30)   NOT NULL DEFAULT '',      -- snapshot of asset_id string
    asset_name       VARCHAR(255)  NOT NULL,                 -- snapshot of asset name
    asset_db_id      BIGINT        REFERENCES alms_assets(id) ON DELETE SET NULL,
    zone             VARCHAR(100)  NOT NULL,
    issue            TEXT          NOT NULL,
    date_reported    DATE          NOT NULL,
    date_completed   DATE,
    technician       VARCHAR(150)  NOT NULL,
    tech_user_id     VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    provider         VARCHAR(150)  NOT NULL DEFAULT '',
    supplier_id      BIGINT        REFERENCES psm_suppliers(id) ON DELETE SET NULL,
    supplier_rating  NUMERIC(3,1),                              -- snapshot at time of log
    repair_cost      NUMERIC(15,2) NOT NULL DEFAULT 0,
    cost_overridden  BOOLEAN       NOT NULL DEFAULT FALSE,
    original_cost    NUMERIC(15,2),
    status           VARCHAR(30)   NOT NULL DEFAULT 'Reported'
                         CHECK (status IN (
                             'Reported','In Progress','Completed',
                             'Cancelled','Escalated'
                         )),
    remarks          TEXT          NOT NULL DEFAULT '',
    sa_remarks       TEXT          NOT NULL DEFAULT '',
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_rl_status       ON alms_repair_logs (status);
CREATE INDEX IF NOT EXISTS idx_alms_rl_asset_id     ON alms_repair_logs (asset_id);
CREATE INDEX IF NOT EXISTS idx_alms_rl_asset_db     ON alms_repair_logs (asset_db_id);
CREATE INDEX IF NOT EXISTS idx_alms_rl_zone         ON alms_repair_logs (zone);
CREATE INDEX IF NOT EXISTS idx_alms_rl_provider     ON alms_repair_logs (provider);
CREATE INDEX IF NOT EXISTS idx_alms_rl_supplier_id  ON alms_repair_logs (supplier_id);
CREATE INDEX IF NOT EXISTS idx_alms_rl_technician   ON alms_repair_logs (technician);
CREATE INDEX IF NOT EXISTS idx_alms_rl_date_rep     ON alms_repair_logs (date_reported DESC);
CREATE INDEX IF NOT EXISTS idx_alms_rl_cost_over    ON alms_repair_logs (cost_overridden);
CREATE INDEX IF NOT EXISTS idx_alms_rl_created_at   ON alms_repair_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_alms_rl_tech_user    ON alms_repair_logs (tech_user_id);
CREATE INDEX IF NOT EXISTS idx_alms_rl_created_user ON alms_repair_logs (created_user_id);

CREATE OR REPLACE FUNCTION set_alms_rl_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_alms_rl_updated_at ON alms_repair_logs;
CREATE TRIGGER trg_alms_rl_updated_at
    BEFORE UPDATE ON alms_repair_logs
    FOR EACH ROW EXECUTE FUNCTION set_alms_rl_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. REPAIR AUDIT / ESCALATION LOG
-- Immutable trail of every action on a repair log.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_repair_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    log_id         BIGINT       NOT NULL REFERENCES alms_repair_logs(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ed-s',
    -- ed-s = created/updated/assigned    ed-o = started / cost override
    -- ed-c = completed / force completed ed-e = escalated
    -- ed-r = flagged / reported          ed-x = cancelled
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_ral_log_id    ON alms_repair_audit_log (log_id);
CREATE INDEX IF NOT EXISTS idx_alms_ral_occurred  ON alms_repair_audit_log (occurred_at ASC);
CREATE INDEX IF NOT EXISTS idx_alms_ral_is_sa     ON alms_repair_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. COST COMPARISON TABLE
-- Stores per-provider benchmark estimates per repair log,
-- used to drive the "Cost Comparison" tab in the view modal.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_repair_cost_compare (
    id              BIGSERIAL     PRIMARY KEY,
    log_id          BIGINT        NOT NULL REFERENCES alms_repair_logs(id) ON DELETE CASCADE,
    provider        VARCHAR(150)  NOT NULL,
    estimated_cost  NUMERIC(15,2) NOT NULL DEFAULT 0,
    is_actual       BOOLEAN       NOT NULL DEFAULT FALSE,  -- TRUE = the provider actually used
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_rcc_log_id ON alms_repair_cost_compare (log_id);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW: repair list with overdue and cost flags
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW alms_repair_summary AS
SELECT
    r.*,
    CASE
        WHEN r.date_reported < CURRENT_DATE - INTERVAL '30 days'
             AND r.status IN ('Reported','In Progress','Escalated')
        THEN TRUE ELSE FALSE
    END AS is_long_running,
    CASE
        WHEN r.date_reported >= DATE_TRUNC('month', CURRENT_DATE)
        THEN TRUE ELSE FALSE
    END AS is_mtd
FROM alms_repair_logs r;


-- ────────────────────────────────────────────────────────────
-- 6. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE alms_repair_logs        DISABLE ROW LEVEL SECURITY;
ALTER TABLE alms_repair_audit_log   DISABLE ROW LEVEL SECURITY;
ALTER TABLE alms_repair_cost_compare DISABLE ROW LEVEL SECURITY;

-- ────────────────────────────────────────────────────────────
-- 7. MIGRATION — add supplier columns to existing table
-- Safe to run on an existing DB; skips if columns already exist.
-- ────────────────────────────────────────────────────────────
ALTER TABLE alms_repair_logs
    ADD COLUMN IF NOT EXISTS supplier_id     BIGINT REFERENCES psm_suppliers(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS supplier_rating NUMERIC(3,1);

CREATE INDEX IF NOT EXISTS idx_alms_rl_supplier_id ON alms_repair_logs (supplier_id);

-- ============================================================
-- ALMS — Asset Disposal Tables
-- Run AFTER user.sql and alms.sql (asset registry)
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. DISPOSALS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_disposals (
    id               BIGSERIAL     PRIMARY KEY,
    disposal_id      VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. DSP-2025-0001
    asset_id         VARCHAR(30)   NOT NULL DEFAULT '',      -- snapshot of asset_id string
    asset_name       VARCHAR(255)  NOT NULL,
    asset_db_id      BIGINT        REFERENCES alms_assets(id) ON DELETE SET NULL,
    zone             VARCHAR(100)  NOT NULL,
    reason           TEXT          NOT NULL,
    method           VARCHAR(30)   NOT NULL
                         CHECK (method IN ('Sold','Scrapped','Donated','Auctioned','Transferred')),
    disposal_date    DATE          NOT NULL,
    approved_by      VARCHAR(150)  NOT NULL DEFAULT '',
    disposal_value   NUMERIC(15,2) NOT NULL DEFAULT 0,
    book_value       NUMERIC(15,2) NOT NULL DEFAULT 0,
    status           VARCHAR(30)   NOT NULL DEFAULT 'Pending Approval'
                         CHECK (status IN (
                             'Pending Approval','Approved','Completed',
                             'Cancelled','Rejected'
                         )),
    ra_ref           VARCHAR(100)  NOT NULL DEFAULT '',      -- RA 9184 BAC reference
    remarks          TEXT          NOT NULL DEFAULT '',
    sa_remarks       TEXT          NOT NULL DEFAULT '',
    is_sa            BOOLEAN       NOT NULL DEFAULT FALSE,   -- TRUE if SA approved/acted
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_d_status      ON alms_disposals (status);
CREATE INDEX IF NOT EXISTS idx_alms_d_zone        ON alms_disposals (zone);
CREATE INDEX IF NOT EXISTS idx_alms_d_method      ON alms_disposals (method);
CREATE INDEX IF NOT EXISTS idx_alms_d_asset_id    ON alms_disposals (asset_id);
CREATE INDEX IF NOT EXISTS idx_alms_d_asset_db    ON alms_disposals (asset_db_id);
CREATE INDEX IF NOT EXISTS idx_alms_d_is_sa       ON alms_disposals (is_sa);
CREATE INDEX IF NOT EXISTS idx_alms_d_date        ON alms_disposals (disposal_date DESC);
CREATE INDEX IF NOT EXISTS idx_alms_d_created_at  ON alms_disposals (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_alms_d_created_user ON alms_disposals (created_user_id);

CREATE OR REPLACE FUNCTION set_alms_d_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_alms_d_updated_at ON alms_disposals;
CREATE TRIGGER trg_alms_d_updated_at
    BEFORE UPDATE ON alms_disposals
    FOR EACH ROW EXECUTE FUNCTION set_alms_d_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. DISPOSAL AUDIT LOG
-- Immutable trail of every action on a disposal record.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_disposal_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    disposal_id    BIGINT       NOT NULL REFERENCES alms_disposals(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ad-s',
    -- ad-s = created/updated       ad-a = approved/completed
    -- ad-r = rejected              ad-x = cancelled
    -- ad-d = disposal completed    ad-o = sa override
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_dal_disposal_id ON alms_disposal_audit_log (disposal_id);
CREATE INDEX IF NOT EXISTS idx_alms_dal_occurred    ON alms_disposal_audit_log (occurred_at ASC);
CREATE INDEX IF NOT EXISTS idx_alms_dal_is_sa       ON alms_disposal_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. RA 9184 COMPLIANCE ROWS
-- One row per compliance requirement per disposal.
-- Seeded automatically when a disposal is created.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alms_disposal_ra_compliance (
    id           BIGSERIAL    PRIMARY KEY,
    disposal_id  BIGINT       NOT NULL REFERENCES alms_disposals(id) ON DELETE CASCADE,
    req_code     VARCHAR(20)  NOT NULL,            -- e.g. Sec. 79
    req_desc     TEXT         NOT NULL,
    req_key      VARCHAR(50)  NOT NULL,            -- e.g. certUnservice
    status       VARCHAR(20)  NOT NULL DEFAULT 'Pending'
                     CHECK (status IN ('Pending','Met','N/A')),
    notes        TEXT         NOT NULL DEFAULT '',
    updated_by   VARCHAR(150) NOT NULL DEFAULT 'system',
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_alms_dra_disposal_id ON alms_disposal_ra_compliance (disposal_id);
CREATE INDEX IF NOT EXISTS idx_alms_dra_status      ON alms_disposal_ra_compliance (status);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW: disposal list with RA compliance summary
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW alms_disposal_summary AS
SELECT
    d.*,
    COALESCE(ra.total_reqs,    0) AS total_reqs,
    COALESCE(ra.met_reqs,      0) AS met_reqs,
    COALESCE(ra.pending_reqs,  0) AS pending_reqs,
    CASE
        WHEN d.book_value > 0
        THEN ROUND((d.disposal_value / d.book_value) * 100, 2)
        ELSE 0
    END AS recovery_pct,
    CASE
        WHEN d.disposal_date < CURRENT_DATE
             AND d.status IN ('Pending Approval','Approved')
        THEN TRUE ELSE FALSE
    END AS is_overdue_approval
FROM alms_disposals d
LEFT JOIN (
    SELECT
        disposal_id,
        COUNT(*)                                   AS total_reqs,
        COUNT(*) FILTER (WHERE status = 'Met')     AS met_reqs,
        COUNT(*) FILTER (WHERE status = 'Pending') AS pending_reqs
    FROM alms_disposal_ra_compliance
    GROUP BY disposal_id
) ra ON ra.disposal_id = d.id;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE alms_disposals              DISABLE ROW LEVEL SECURITY;
ALTER TABLE alms_disposal_audit_log     DISABLE ROW LEVEL SECURITY;
ALTER TABLE alms_disposal_ra_compliance DISABLE ROW LEVEL SECURITY;