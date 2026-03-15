-- ============================================================
-- PLT — Project Logistics Tracker
-- Run AFTER user.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. PROJECTS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_projects (
    id               BIGSERIAL     PRIMARY KEY,
    project_id       VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. PLT-2025-0001
    name             VARCHAR(255)  NOT NULL,
    zone             VARCHAR(100)  NOT NULL,
    manager          VARCHAR(150)  NOT NULL,
    priority         VARCHAR(20)   NOT NULL DEFAULT 'Medium'
                         CHECK (priority IN ('Critical','High','Medium','Low')),
    start_date       DATE          NOT NULL,
    end_date         DATE          NOT NULL,
    ref              VARCHAR(100)  NOT NULL DEFAULT '',       -- PO/PR reference
    budget           NUMERIC(15,2) NOT NULL DEFAULT 0,
    spend            NUMERIC(15,2) NOT NULL DEFAULT 0,
    progress         SMALLINT      NOT NULL DEFAULT 0
                         CHECK (progress BETWEEN 0 AND 100),
    status           VARCHAR(30)   NOT NULL DEFAULT 'Planning'
                         CHECK (status IN (
                             'Planning','Active','On Hold',
                             'Delayed','Completed','Terminated'
                         )),
    description      TEXT          NOT NULL DEFAULT '',
    conflict         BOOLEAN       NOT NULL DEFAULT FALSE,
    conflict_note    TEXT          NOT NULL DEFAULT '',
    created_user_id  VARCHAR(20),
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_plt_status      ON plt_projects (status);
CREATE INDEX IF NOT EXISTS idx_plt_zone        ON plt_projects (zone);
CREATE INDEX IF NOT EXISTS idx_plt_priority    ON plt_projects (priority);
CREATE INDEX IF NOT EXISTS idx_plt_manager     ON plt_projects (manager);
CREATE INDEX IF NOT EXISTS idx_plt_start_date  ON plt_projects (start_date DESC);
CREATE INDEX IF NOT EXISTS idx_plt_end_date    ON plt_projects (end_date ASC);
CREATE INDEX IF NOT EXISTS idx_plt_conflict    ON plt_projects (conflict);
CREATE INDEX IF NOT EXISTS idx_plt_created_at  ON plt_projects (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_plt_user        ON plt_projects (created_user_id);

CREATE OR REPLACE FUNCTION set_plt_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_plt_updated_at ON plt_projects;
CREATE TRIGGER trg_plt_updated_at
    BEFORE UPDATE ON plt_projects
    FOR EACH ROW EXECUTE FUNCTION set_plt_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. MILESTONES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_milestones (
    id           BIGSERIAL    PRIMARY KEY,
    project_id   BIGINT       NOT NULL REFERENCES plt_projects(id) ON DELETE CASCADE,
    name         VARCHAR(255) NOT NULL,
    target_date  DATE,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    status       VARCHAR(20)  NOT NULL DEFAULT 'Pending'
                     CHECK (status IN ('Pending','In Progress','Completed','Skipped')),
    notes        TEXT         NOT NULL DEFAULT '',
    completed_at TIMESTAMPTZ,
    completed_by VARCHAR(150) NOT NULL DEFAULT '',
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltm_project_id ON plt_milestones (project_id);
CREATE INDEX IF NOT EXISTS idx_pltm_status     ON plt_milestones (status);
CREATE INDEX IF NOT EXISTS idx_pltm_sort       ON plt_milestones (project_id, sort_order);

CREATE OR REPLACE FUNCTION set_pltm_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_pltm_updated_at ON plt_milestones;
CREATE TRIGGER trg_pltm_updated_at
    BEFORE UPDATE ON plt_milestones
    FOR EACH ROW EXECUTE FUNCTION set_pltm_updated_at();


-- ────────────────────────────────────────────────────────────
-- 3. AUDIT LOG
-- Immutable trail of every action on a project.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    project_id     BIGINT       NOT NULL REFERENCES plt_projects(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    actor_user_id  VARCHAR(20),
    note           TEXT         NOT NULL DEFAULT '',
    dot_class      VARCHAR(30)  NOT NULL DEFAULT 'dot-b',
    -- dot-g = created/completed    dot-b = edited/updated
    -- dot-o = override/budget      dot-r = closed/terminated
    -- dot-gy = archived/skipped
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltal_project_id  ON plt_audit_log (project_id);
CREATE INDEX IF NOT EXISTS idx_pltal_occurred_at ON plt_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_pltal_is_sa       ON plt_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 4. SPEND LOG
-- Optional granular record of every spend entry against a project.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_spend_log (
    id           BIGSERIAL     PRIMARY KEY,
    project_id   BIGINT        NOT NULL REFERENCES plt_projects(id) ON DELETE CASCADE,
    amount       NUMERIC(15,2) NOT NULL,
    description  TEXT          NOT NULL DEFAULT '',
    ref_doc      VARCHAR(100)  NOT NULL DEFAULT '',   -- PO/OR/invoice ref
    recorded_by  VARCHAR(150)  NOT NULL DEFAULT 'system',
    recorded_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltsl_project_id ON plt_spend_log (project_id);
CREATE INDEX IF NOT EXISTS idx_pltsl_recorded   ON plt_spend_log (recorded_at DESC);


-- ────────────────────────────────────────────────────────────
-- 5. HELPER VIEW: project list with milestone counts
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW plt_project_summary AS
SELECT
    p.*,
    COALESCE(mc.total_milestones,   0) AS total_milestones,
    COALESCE(mc.done_milestones,    0) AS done_milestones,
    COALESCE(sl.total_spend_logged, 0) AS total_spend_logged,
    CASE
        WHEN p.budget > 0
        THEN ROUND((p.spend::NUMERIC / p.budget) * 100, 2)
        ELSE 0
    END AS budget_pct,
    CASE
        WHEN p.end_date < CURRENT_DATE AND p.status NOT IN ('Completed','Terminated')
        THEN TRUE ELSE FALSE
    END AS is_overdue
FROM plt_projects p
LEFT JOIN (
    SELECT
        project_id,
        COUNT(*)                                         AS total_milestones,
        COUNT(*) FILTER (WHERE status = 'Completed')    AS done_milestones
    FROM plt_milestones
    GROUP BY project_id
) mc ON mc.project_id = p.id
LEFT JOIN (
    SELECT project_id, SUM(amount) AS total_spend_logged
    FROM plt_spend_log
    GROUP BY project_id
) sl ON sl.project_id = p.id;


-- ────────────────────────────────────────────────────────────
-- 6. RLS (disabled — app-level auth, same pattern as PSM/SWS)
-- ────────────────────────────────────────────────────────────
ALTER TABLE plt_projects  DISABLE ROW LEVEL SECURITY;
ALTER TABLE plt_milestones DISABLE ROW LEVEL SECURITY;
ALTER TABLE plt_audit_log DISABLE ROW LEVEL SECURITY;
ALTER TABLE plt_spend_log DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- PLT — Delivery Schedule Tables
-- Run AFTER user.sql and plt.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. DELIVERIES (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_deliveries (
    id               BIGSERIAL     PRIMARY KEY,
    delivery_id      VARCHAR(30)   UNIQUE NOT NULL,   -- e.g. DS-2025-0001
    supplier         VARCHAR(255)  NOT NULL,
    supplier_type    VARCHAR(50)   NOT NULL DEFAULT 'Supplier'
                         CHECK (supplier_type IN ('Supplier','Vendor','Contractor','Distributor')),
    po_ref           VARCHAR(100)  NOT NULL,
    project          VARCHAR(255)  NOT NULL DEFAULT '',
    zone             VARCHAR(100)  NOT NULL,
    assigned_to      VARCHAR(150)  NOT NULL,
    expected_date    DATE          NOT NULL,
    actual_date      DATE,
    is_late          BOOLEAN       NOT NULL DEFAULT FALSE,
    status           VARCHAR(30)   NOT NULL DEFAULT 'Scheduled'
                         CHECK (status IN (
                             'Scheduled','In Transit','Delivered',
                             'Delayed','Cancelled','Force Completed'
                         )),
    items            TEXT          NOT NULL DEFAULT '[]',   -- JSON array of item names
    gps_data         TEXT,                                  -- JSON: {lat, lng, loc}
    notes            TEXT          NOT NULL DEFAULT '',
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltd_status        ON plt_deliveries (status);
CREATE INDEX IF NOT EXISTS idx_pltd_zone          ON plt_deliveries (zone);
CREATE INDEX IF NOT EXISTS idx_pltd_supplier      ON plt_deliveries (supplier);
CREATE INDEX IF NOT EXISTS idx_pltd_assigned_to   ON plt_deliveries (assigned_to);
CREATE INDEX IF NOT EXISTS idx_pltd_expected_date ON plt_deliveries (expected_date DESC);
CREATE INDEX IF NOT EXISTS idx_pltd_po_ref        ON plt_deliveries (po_ref);
CREATE INDEX IF NOT EXISTS idx_pltd_is_late       ON plt_deliveries (is_late);
CREATE INDEX IF NOT EXISTS idx_pltd_created_at    ON plt_deliveries (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pltd_user          ON plt_deliveries (created_user_id);

CREATE OR REPLACE FUNCTION set_pltd_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_pltd_updated_at ON plt_deliveries;
CREATE TRIGGER trg_pltd_updated_at
    BEFORE UPDATE ON plt_deliveries
    FOR EACH ROW EXECUTE FUNCTION set_pltd_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. DELIVERY AUDIT LOG
-- Immutable trail of every action on a delivery.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_delivery_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    delivery_id    BIGINT       NOT NULL REFERENCES plt_deliveries(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ad-s',
    -- ad-c = created/scheduled    ad-s = assigned/updated
    -- ad-a = completed/approved   ad-r = cancelled/rejected
    -- ad-e = edited               ad-o = override/reassign
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltdal_delivery_id  ON plt_delivery_audit_log (delivery_id);
CREATE INDEX IF NOT EXISTS idx_pltdal_occurred_at  ON plt_delivery_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_pltdal_is_sa        ON plt_delivery_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. HELPER VIEW: delivery list with item count
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW plt_delivery_summary AS
SELECT
    d.*,
    CASE
        WHEN d.actual_date IS NOT NULL AND d.actual_date > d.expected_date
        THEN TRUE ELSE FALSE
    END AS is_overdue,
    CASE
        WHEN d.expected_date < CURRENT_DATE
             AND d.status NOT IN ('Delivered','Force Completed','Cancelled')
        THEN TRUE ELSE FALSE
    END AS is_unresolved_late
FROM plt_deliveries d;


-- ────────────────────────────────────────────────────────────
-- 4. RLS (disabled — app-level auth, same pattern as PSM/SWS/PLT)
-- ────────────────────────────────────────────────────────────
ALTER TABLE plt_deliveries        DISABLE ROW LEVEL SECURITY;
ALTER TABLE plt_delivery_audit_log DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- PLT — Logistics Assignments Tables
-- Run AFTER user.sql and plt.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. ASSIGNMENTS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_assignments (
    id               BIGSERIAL     PRIMARY KEY,
    assignment_id    VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. LA-2025-0001
    task             VARCHAR(500)  NOT NULL,
    assigned_to      VARCHAR(150)  NOT NULL DEFAULT 'Unassigned',
    zone             VARCHAR(100)  NOT NULL,
    priority         VARCHAR(20)   NOT NULL DEFAULT 'Medium'
                         CHECK (priority IN ('Critical','High','Medium','Low')),
    date_created     DATE          NOT NULL DEFAULT CURRENT_DATE,
    due_date         DATE          NOT NULL,
    status           VARCHAR(30)   NOT NULL DEFAULT 'Unassigned'
                         CHECK (status IN (
                             'Unassigned','Assigned','In Progress',
                             'Completed','Overdue','Escalated'
                         )),
    notes            TEXT          NOT NULL DEFAULT '',
    checklist        TEXT          NOT NULL DEFAULT '[]',    -- JSON array [{id,text,done}]
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_plta_status      ON plt_assignments (status);
CREATE INDEX IF NOT EXISTS idx_plta_zone        ON plt_assignments (zone);
CREATE INDEX IF NOT EXISTS idx_plta_priority    ON plt_assignments (priority);
CREATE INDEX IF NOT EXISTS idx_plta_assigned_to ON plt_assignments (assigned_to);
CREATE INDEX IF NOT EXISTS idx_plta_due_date    ON plt_assignments (due_date ASC);
CREATE INDEX IF NOT EXISTS idx_plta_created_at  ON plt_assignments (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_plta_user        ON plt_assignments (created_user_id);

CREATE OR REPLACE FUNCTION set_plta_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_plta_updated_at ON plt_assignments;
CREATE TRIGGER trg_plta_updated_at
    BEFORE UPDATE ON plt_assignments
    FOR EACH ROW EXECUTE FUNCTION set_plta_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. ASSIGNMENT AUDIT LOG
-- Immutable trail of every action on an assignment.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_assignment_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    assignment_id  BIGINT       NOT NULL REFERENCES plt_assignments(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ad-s',
    -- ad-c = created            ad-s = assigned/updated
    -- ad-a = completed          ad-r = unassigned/reset
    -- ad-e = edited             ad-o = reassigned/override
    -- ad-p = escalated          ad-fc = force-completed
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltaal_assignment_id ON plt_assignment_audit_log (assignment_id);
CREATE INDEX IF NOT EXISTS idx_pltaal_occurred_at   ON plt_assignment_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_pltaal_is_sa         ON plt_assignment_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. HELPER VIEW
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW plt_assignment_summary AS
SELECT
    a.*,
    CASE
        WHEN a.due_date < CURRENT_DATE
             AND a.status NOT IN ('Completed','Escalated')
        THEN TRUE ELSE FALSE
    END AS is_overdue
FROM plt_assignments a;


-- ────────────────────────────────────────────────────────────
-- 4. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE plt_assignments         DISABLE ROW LEVEL SECURITY;
ALTER TABLE plt_assignment_audit_log DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- PLT — Milestone Tracking Extended Tables
-- Run AFTER user.sql and plt.sql
-- Database: PostgreSQL (Supabase)
-- NOTE: Uses plt_milestones_ext to avoid conflict with the
--       existing plt_milestones table in plt.sql (which is
--       project-scoped). This module is standalone / cross-project.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. MILESTONES EXTENDED (standalone, cross-project)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_milestones_ext (
    id               BIGSERIAL     PRIMARY KEY,
    milestone_id     VARCHAR(30)   UNIQUE NOT NULL,          -- e.g. MS-2025-0001
    name             VARCHAR(500)  NOT NULL,
    project          VARCHAR(255)  NOT NULL,                 -- project name (free-text or FK)
    zone             VARCHAR(100)  NOT NULL,
    target_date      DATE          NOT NULL,
    completion_date  DATE,
    progress         SMALLINT      NOT NULL DEFAULT 0
                         CHECK (progress BETWEEN 0 AND 100),
    status           VARCHAR(30)   NOT NULL DEFAULT 'Pending'
                         CHECK (status IN (
                             'Pending','In Progress','Completed',
                             'Overdue','Skipped','Force Completed'
                         )),
    notes            TEXT          NOT NULL DEFAULT '',
    deps             TEXT          NOT NULL DEFAULT '[]',    -- JSON array of milestone_id strings
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltme_status      ON plt_milestones_ext (status);
CREATE INDEX IF NOT EXISTS idx_pltme_project     ON plt_milestones_ext (project);
CREATE INDEX IF NOT EXISTS idx_pltme_zone        ON plt_milestones_ext (zone);
CREATE INDEX IF NOT EXISTS idx_pltme_target_date ON plt_milestones_ext (target_date ASC);
CREATE INDEX IF NOT EXISTS idx_pltme_created_at  ON plt_milestones_ext (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pltme_user        ON plt_milestones_ext (created_user_id);

CREATE OR REPLACE FUNCTION set_pltme_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_pltme_updated_at ON plt_milestones_ext;
CREATE TRIGGER trg_pltme_updated_at
    BEFORE UPDATE ON plt_milestones_ext
    FOR EACH ROW EXECUTE FUNCTION set_pltme_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. MILESTONE AUDIT LOG
-- Immutable trail of every action on a milestone.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plt_milestone_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    milestone_id   BIGINT       NOT NULL REFERENCES plt_milestones_ext(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    actor_role     VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    actor_user_id  VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    note           TEXT         NOT NULL DEFAULT '',
    icon           VARCHAR(50)  NOT NULL DEFAULT 'bx-info-circle',
    css_class      VARCHAR(30)  NOT NULL DEFAULT 'ad-s',
    -- ad-c = created            ad-s = edited/updated
    -- ad-a = completed          ad-r = flagged/overdue
    -- ad-o = in progress        ad-p = dep override
    -- ad-t = force completed    ad-x = skipped
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pltmal_milestone_id ON plt_milestone_audit_log (milestone_id);
CREATE INDEX IF NOT EXISTS idx_pltmal_occurred_at  ON plt_milestone_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_pltmal_is_sa        ON plt_milestone_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 3. HELPER VIEW: milestone list with overdue flag
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW plt_milestone_summary AS
SELECT
    m.*,
    CASE
        WHEN m.target_date < CURRENT_DATE
             AND m.status NOT IN ('Completed','Force Completed','Skipped')
        THEN TRUE ELSE FALSE
    END AS is_overdue,
    -- Count how many of this milestone's deps are still unmet
    -- (deps is a JSON array of milestone_id strings — resolved at app level)
    CASE
        WHEN m.deps = '[]' OR m.deps IS NULL THEN 0
        ELSE jsonb_array_length(m.deps::jsonb)
    END AS dep_count
FROM plt_milestones_ext m;


-- ────────────────────────────────────────────────────────────
-- 4. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE plt_milestones_ext      DISABLE ROW LEVEL SECURITY;
ALTER TABLE plt_milestone_audit_log DISABLE ROW LEVEL SECURITY;