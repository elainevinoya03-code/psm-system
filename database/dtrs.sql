-- ============================================================
-- DTRS — Document Tracking & Registry System
-- Run AFTER user.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. DOCUMENTS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_documents (
    id               BIGSERIAL     PRIMARY KEY,
    doc_id           VARCHAR(30)   UNIQUE NOT NULL,           -- e.g. DTRS-2025-0001
    title            VARCHAR(255)  NOT NULL,
    ref_number       VARCHAR(100)  NOT NULL DEFAULT '',       -- e.g. REF-2025-001
    doc_type         VARCHAR(100)  NOT NULL,                  -- Memo, Contract, Invoice…
    category         VARCHAR(100)  NOT NULL,                  -- Financial, Legal…
    department       VARCHAR(100)  NOT NULL,
    direction        VARCHAR(20)   NOT NULL DEFAULT 'Incoming'
                         CHECK (direction IN ('Incoming','Outgoing')),
    sender           VARCHAR(255)  NOT NULL,
    recipient        VARCHAR(255)  NOT NULL,
    assigned_to      VARCHAR(150)  NOT NULL,
    doc_date         TIMESTAMPTZ   NOT NULL,
    priority         VARCHAR(30)   NOT NULL DEFAULT 'Normal'
                         CHECK (priority IN ('Normal','Urgent','Confidential','High Value')),
    retention        VARCHAR(20)   NOT NULL DEFAULT '1 Year',
    notes            TEXT          NOT NULL DEFAULT '',

    -- File / capture metadata
    capture_mode     VARCHAR(20)   NOT NULL DEFAULT 'physical'
                         CHECK (capture_mode IN ('physical','digital')),
    file_name        VARCHAR(255)  NOT NULL DEFAULT '',
    file_size_kb     NUMERIC(10,1) NOT NULL DEFAULT 0,
    file_ext         VARCHAR(10)   NOT NULL DEFAULT '',
    file_path        TEXT          NOT NULL DEFAULT '',       -- Supabase Storage path: YYYY/DTRS-YYYY-####.ext

    -- OCR / AI extraction
    ocr_text         TEXT          NOT NULL DEFAULT '',       -- raw OCR output
    ai_confidence    SMALLINT      NOT NULL DEFAULT 0,        -- 0–100
    ai_auto_filled   BOOLEAN       NOT NULL DEFAULT FALSE,
    needs_validation BOOLEAN       NOT NULL DEFAULT FALSE,    -- confidence < 70

    -- Status lifecycle
    status           VARCHAR(30)   NOT NULL DEFAULT 'Registered'
                         CHECK (status IN (
                             'Registered','In Transit','Received',
                             'Processing','Completed','Archived','Rejected'
                         )),

    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dtrs_status       ON dtrs_documents (status);
CREATE INDEX IF NOT EXISTS idx_dtrs_doc_type     ON dtrs_documents (doc_type);
CREATE INDEX IF NOT EXISTS idx_dtrs_category     ON dtrs_documents (category);
CREATE INDEX IF NOT EXISTS idx_dtrs_department   ON dtrs_documents (department);
CREATE INDEX IF NOT EXISTS idx_dtrs_direction    ON dtrs_documents (direction);
CREATE INDEX IF NOT EXISTS idx_dtrs_assigned_to  ON dtrs_documents (assigned_to);
CREATE INDEX IF NOT EXISTS idx_dtrs_priority     ON dtrs_documents (priority);
CREATE INDEX IF NOT EXISTS idx_dtrs_needs_val    ON dtrs_documents (needs_validation);
CREATE INDEX IF NOT EXISTS idx_dtrs_doc_date     ON dtrs_documents (doc_date DESC);
CREATE INDEX IF NOT EXISTS idx_dtrs_created_at   ON dtrs_documents (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_dtrs_created_user ON dtrs_documents (created_user_id);
CREATE INDEX IF NOT EXISTS idx_dtrs_ref_number   ON dtrs_documents (ref_number);

CREATE OR REPLACE FUNCTION set_dtrs_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_dtrs_updated_at ON dtrs_documents;
CREATE TRIGGER trg_dtrs_updated_at
    BEFORE UPDATE ON dtrs_documents
    FOR EACH ROW EXECUTE FUNCTION set_dtrs_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. AUDIT LOG
-- Immutable trail of every action on a document.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    doc_id         BIGINT       NOT NULL REFERENCES dtrs_documents(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'dc-s',
    -- dc-c = registered/created     dc-s = updated/edited
    -- dc-a = received/completed      dc-r = rejected
    -- dc-t = in transit              dc-x = archived
    -- dc-o = override/escalated
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dtrs_al_doc_id   ON dtrs_audit_log (doc_id);
CREATE INDEX IF NOT EXISTS idx_dtrs_al_occurred ON dtrs_audit_log (occurred_at ASC);
CREATE INDEX IF NOT EXISTS idx_dtrs_al_is_sa    ON dtrs_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. SEQUENCE TABLE (for auto-incrementing IDs across restarts)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_sequences (
    key         VARCHAR(50) PRIMARY KEY,
    last_val    INTEGER     NOT NULL DEFAULT 0
);

INSERT INTO dtrs_sequences (key, last_val) VALUES
    ('doc_id',  0),
    ('ref_num', 0)
ON CONFLICT (key) DO NOTHING;


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW dtrs_document_summary AS
SELECT
    d.*,
    CASE
        WHEN d.needs_validation AND d.status = 'Registered'
        THEN TRUE ELSE FALSE
    END AS is_pending_validation,
    CASE
        WHEN d.doc_date < NOW() - INTERVAL '30 days'
             AND d.status NOT IN ('Completed','Archived','Rejected')
        THEN TRUE ELSE FALSE
    END AS is_stale
FROM dtrs_documents d;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE dtrs_documents  DISABLE ROW LEVEL SECURITY;
ALTER TABLE dtrs_audit_log  DISABLE ROW LEVEL SECURITY;
ALTER TABLE dtrs_sequences  DISABLE ROW LEVEL SECURITY;

-- ────────────────────────────────────────────────────────────
-- 6. MIGRATIONS — run if table already exists
-- Safe to run multiple times (IF NOT EXISTS / IF EXISTS guards)
-- ────────────────────────────────────────────────────────────
ALTER TABLE dtrs_documents
    ADD COLUMN IF NOT EXISTS file_path TEXT NOT NULL DEFAULT '';

-- Create the Supabase Storage bucket manually in the dashboard:
--   Dashboard → Storage → New Bucket
--   Name   : dtrs-documents
--   Public : OFF  (files served via signed URLs only)

-- ============================================================
-- DTRS — Lifecycle Migration
-- Run AFTER dtrs.sql (dtrs_documents + dtrs_audit_log must exist)
-- Database: PostgreSQL (Supabase)
-- Safe to run multiple times — all ADD COLUMN IF NOT EXISTS
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. ADD MISSING COLUMNS TO dtrs_documents
-- ────────────────────────────────────────────────────────────
ALTER TABLE dtrs_documents
    ADD COLUMN IF NOT EXISTS retention_stage     VARCHAR(20)   NOT NULL DEFAULT 'Active'
                                 CHECK (retention_stage IN ('Active','Archive','Review','Disposed')),
    ADD COLUMN IF NOT EXISTS access_level        VARCHAR(20)   NOT NULL DEFAULT 'Internal'
                                 CHECK (access_level IN ('Public','Internal','Restricted','Confidential')),
    ADD COLUMN IF NOT EXISTS disposed_at         TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS disposed_by         VARCHAR(150),
    ADD COLUMN IF NOT EXISTS disposal_reason     TEXT,
    ADD COLUMN IF NOT EXISTS retention_extended  BOOLEAN       NOT NULL DEFAULT FALSE;


-- ────────────────────────────────────────────────────────────
-- 2. BACK-FILL retention_stage based on doc_date for existing rows
-- Rule: 0–3 yrs → Active | 3–7 yrs → Archive | 7+ yrs → Review
-- Only touches rows that are still at the default 'Active' and
-- whose doc_date suggests they should be further along.
-- ────────────────────────────────────────────────────────────
UPDATE dtrs_documents
SET retention_stage = CASE
    WHEN EXTRACT(EPOCH FROM (NOW() - doc_date)) / 31536000 >= 7 THEN 'Review'
    WHEN EXTRACT(EPOCH FROM (NOW() - doc_date)) / 31536000 >= 3 THEN 'Archive'
    ELSE 'Active'
END
WHERE disposed_at IS NULL;


-- ────────────────────────────────────────────────────────────
-- 3. INDEXES on new columns
-- ────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_dtrs_retention_stage ON dtrs_documents (retention_stage);
CREATE INDEX IF NOT EXISTS idx_dtrs_access_level    ON dtrs_documents (access_level);
CREATE INDEX IF NOT EXISTS idx_dtrs_disposed_at     ON dtrs_documents (disposed_at);


-- ────────────────────────────────────────────────────────────
-- 4. dtrs_retention_policies TABLE
-- Stores per-category retention schedule rules.
-- Editable by Super Admin only (enforced at app level).
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_retention_policies (
    id              SERIAL        PRIMARY KEY,
    category        VARCHAR(100)  NOT NULL UNIQUE,
    active_years    SMALLINT      NOT NULL DEFAULT 3,
    archive_years   SMALLINT      NOT NULL DEFAULT 3,
    review_years    SMALLINT      NOT NULL DEFAULT 7,
    default_action  VARCHAR(100)  NOT NULL DEFAULT 'Compliance Review',
    updated_by      VARCHAR(150)  NOT NULL DEFAULT 'system',
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- Seed default rules
INSERT INTO dtrs_retention_policies (category, active_years, archive_years, review_years, default_action)
VALUES
    ('Financial',      3, 3,  7, 'Compliance Review'),
    ('Legal',          5, 5, 10, 'Compliance Review'),
    ('HR',             3, 3,  7, 'Extend / Dispose'),
    ('Compliance',     3, 3,  7, 'Compliance Review'),
    ('Procurement',    3, 3,  7, 'Dispose'),
    ('Administrative', 2, 2,  5, 'Dispose'),
    ('Operational',    3, 3,  7, 'Compliance Review')
ON CONFLICT (category) DO NOTHING;

ALTER TABLE dtrs_retention_policies DISABLE ROW LEVEL SECURITY;


-- ────────────────────────────────────────────────────────────
-- 5. VERIFY — run these SELECTs to confirm migration succeeded
-- ────────────────────────────────────────────────────────────
SELECT column_name, data_type, column_default
FROM information_schema.columns
WHERE table_name = 'dtrs_documents'
  AND column_name IN (
      'retention_stage','access_level',
      'disposed_at','disposed_by','disposal_reason','retention_extended'
  )
ORDER BY column_name;

SELECT * FROM dtrs_retention_policies ORDER BY category;

-- ============================================================
-- DTRS — Document Routing Tables
-- Run AFTER user.sql and dtrs.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. DOCUMENT ROUTES (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_routes (
    id              BIGSERIAL     PRIMARY KEY,
    route_id        VARCHAR(30)   UNIQUE NOT NULL,         -- e.g. RT-2025-0001
    doc_id          VARCHAR(30)   NOT NULL,                -- references dtrs_documents.doc_id (soft ref)
    doc_db_id       BIGINT        REFERENCES dtrs_documents(id) ON DELETE SET NULL,
    doc_name        VARCHAR(255)  NOT NULL,
    doc_type        VARCHAR(100)  NOT NULL DEFAULT '',
    from_dept       VARCHAR(100)  NOT NULL,
    to_dept         VARCHAR(100)  NOT NULL,
    assignee        VARCHAR(150)  NOT NULL,
    route_type      VARCHAR(50)   NOT NULL DEFAULT 'For Review'
                        CHECK (route_type IN ('For Action','For Review','For Signature','For Filing')),
    priority        VARCHAR(20)   NOT NULL DEFAULT 'Normal'
                        CHECK (priority IN ('Normal','Urgent','Rush')),
    due_date        DATE,
    date_routed     DATE          NOT NULL DEFAULT CURRENT_DATE,
    status          VARCHAR(20)   NOT NULL DEFAULT 'In Transit'
                        CHECK (status IN ('In Transit','Received','Returned','Completed')),
    module          VARCHAR(100)  NOT NULL DEFAULT '',
    notes           TEXT          NOT NULL DEFAULT '',
    is_overridden   BOOLEAN       NOT NULL DEFAULT FALSE,
    override_reason TEXT,
    overridden_by   VARCHAR(150),
    overridden_at   TIMESTAMPTZ,
    created_user_id VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by      VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dr_status      ON dtrs_routes (status);
CREATE INDEX IF NOT EXISTS idx_dr_route_type  ON dtrs_routes (route_type);
CREATE INDEX IF NOT EXISTS idx_dr_from_dept   ON dtrs_routes (from_dept);
CREATE INDEX IF NOT EXISTS idx_dr_to_dept     ON dtrs_routes (to_dept);
CREATE INDEX IF NOT EXISTS idx_dr_assignee    ON dtrs_routes (assignee);
CREATE INDEX IF NOT EXISTS idx_dr_doc_id      ON dtrs_routes (doc_id);
CREATE INDEX IF NOT EXISTS idx_dr_date_routed ON dtrs_routes (date_routed DESC);
CREATE INDEX IF NOT EXISTS idx_dr_created_at  ON dtrs_routes (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_dr_overridden  ON dtrs_routes (is_overridden);

CREATE OR REPLACE FUNCTION set_dr_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_dr_updated_at ON dtrs_routes;
CREATE TRIGGER trg_dr_updated_at
    BEFORE UPDATE ON dtrs_routes
    FOR EACH ROW EXECUTE FUNCTION set_dr_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. ROUTE HISTORY STEPS
-- One row per step in the routing chain timeline.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_route_history (
    id          BIGSERIAL    PRIMARY KEY,
    route_id    BIGINT       NOT NULL REFERENCES dtrs_routes(id) ON DELETE CASCADE,
    role_label  VARCHAR(255) NOT NULL,    -- e.g. "Originated — Legal Dept."
    actor_name  VARCHAR(150) NOT NULL DEFAULT '',
    step_type   VARCHAR(20)  NOT NULL DEFAULT 'rtd-done'
                    CHECK (step_type IN ('rtd-done','rtd-current','rtd-pending','rtd-return')),
    icon        VARCHAR(50)  NOT NULL DEFAULT 'bx-check',
    note        TEXT         NOT NULL DEFAULT '',
    occurred_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_drh_route_id ON dtrs_route_history (route_id);
CREATE INDEX IF NOT EXISTS idx_drh_occurred ON dtrs_route_history (occurred_at ASC);


-- ────────────────────────────────────────────────────────────
-- 3. ROUTE AUDIT LOG
-- Immutable trail of every action on a route.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtrs_route_audit (
    id             BIGSERIAL    PRIMARY KEY,
    route_id       BIGINT       NOT NULL REFERENCES dtrs_routes(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Admin',
    dot_class      VARCHAR(20)  NOT NULL DEFAULT 'dot-b',
    -- dot-g = received/completed   dot-b = routed/updated
    -- dot-o = returned/override    dot-r = cancelled
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dra_route_id  ON dtrs_route_audit (route_id);
CREATE INDEX IF NOT EXISTS idx_dra_occurred  ON dtrs_route_audit (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_dra_is_sa     ON dtrs_route_audit (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW: routes with step + audit counts
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW dtrs_route_summary AS
SELECT
    r.*,
    COALESCE(hc.step_count, 0)  AS step_count,
    COALESCE(ac.audit_count, 0) AS audit_count
FROM dtrs_routes r
LEFT JOIN (
    SELECT route_id, COUNT(*) AS step_count
    FROM dtrs_route_history GROUP BY route_id
) hc ON hc.route_id = r.id
LEFT JOIN (
    SELECT route_id, COUNT(*) AS audit_count
    FROM dtrs_route_audit GROUP BY route_id
) ac ON ac.route_id = r.id;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE dtrs_routes        DISABLE ROW LEVEL SECURITY;
ALTER TABLE dtrs_route_history DISABLE ROW LEVEL SECURITY;
ALTER TABLE dtrs_route_audit   DISABLE ROW LEVEL SECURITY;


-- ────────────────────────────────────────────────────────────
-- 6. VERIFY
-- ────────────────────────────────────────────────────────────
SELECT table_name FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN ('dtrs_routes','dtrs_route_history','dtrs_route_audit')
ORDER BY table_name;