CREATE TABLE psm_purchase_requests (
  id             BIGSERIAL PRIMARY KEY,
  pr_number      VARCHAR(30) UNIQUE NOT NULL,
  requestor_name VARCHAR(150) NOT NULL,
  department     VARCHAR(100) NOT NULL,
  date_filed     DATE NOT NULL,
  date_needed    DATE NOT NULL,
  status         VARCHAR(30) NOT NULL,
  purpose        TEXT,
  remarks        TEXT,
  total_amount   NUMERIC(15,2) NOT NULL DEFAULT 0,
  item_count     INTEGER NOT NULL DEFAULT 0,
  created_user_id VARCHAR(20) REFERENCES users(user_id),
  created_by     VARCHAR(100) DEFAULT 'system',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_pr_items (
  id           BIGSERIAL PRIMARY KEY,
  pr_id        BIGINT NOT NULL REFERENCES psm_purchase_requests(id) ON DELETE CASCADE,
  line_no      INTEGER NOT NULL,
  item_name    VARCHAR(255) NOT NULL,
  specification TEXT,
  unit         VARCHAR(50) NOT NULL DEFAULT 'pcs',
  quantity     NUMERIC(12,2) NOT NULL DEFAULT 1,
  unit_price   NUMERIC(15,2) NOT NULL DEFAULT 0,
  line_total   NUMERIC(15,2) NOT NULL DEFAULT 0
);

CREATE TABLE psm_pr_audit_log (
  id            BIGSERIAL PRIMARY KEY,
  pr_id         BIGINT NOT NULL REFERENCES psm_purchase_requests(id) ON DELETE CASCADE,
  action_label  VARCHAR(255) NOT NULL,
  actor_name    VARCHAR(150) NOT NULL,
  actor_role    VARCHAR(100) NOT NULL,
  remarks       TEXT,
  ip_address    VARCHAR(45),
  icon          VARCHAR(50),
  css_class     VARCHAR(50),
  is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
  occurred_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── RFQ MODULE ─────────────────────────────────────────────────────────────

CREATE TABLE psm_suppliers (
  id             BIGSERIAL PRIMARY KEY,
  name           VARCHAR(255) NOT NULL,
  category       VARCHAR(100) NOT NULL DEFAULT '',
  contact_person VARCHAR(150),
  email          VARCHAR(255),
  phone          VARCHAR(50),
  address        TEXT,
  website        VARCHAR(255),
  status         VARCHAR(30) NOT NULL DEFAULT 'Active',
  accreditation  VARCHAR(50) NOT NULL DEFAULT 'Pending',
  rating         NUMERIC(3,1) NOT NULL DEFAULT 3.5,
  notes          TEXT,
  is_flagged     BOOLEAN NOT NULL DEFAULT FALSE,
  created_user_id VARCHAR(20),
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_supplier_metrics (
  id           BIGSERIAL PRIMARY KEY,
  supplier_id  BIGINT NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE UNIQUE,
  total_pos    INTEGER NOT NULL DEFAULT 0,
  completed    INTEGER NOT NULL DEFAULT 0,
  on_time_pct  NUMERIC(5,2) NOT NULL DEFAULT 0,
  quality_avg  NUMERIC(3,1) NOT NULL DEFAULT 0,
  issue_count  INTEGER NOT NULL DEFAULT 0,
  overall_rating NUMERIC(3,1) NOT NULL DEFAULT 0,
  branch       VARCHAR(100) DEFAULT 'Head Office',
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_supplier_evaluations (
  id           BIGSERIAL PRIMARY KEY,
  supplier_id  BIGINT NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE,
  po_reference VARCHAR(50) NOT NULL,
  branch       VARCHAR(100) DEFAULT 'Head Office',
  on_time      BOOLEAN NOT NULL DEFAULT TRUE,
  quality      INTEGER NOT NULL DEFAULT 5,
  issues       TEXT,
  remarks      TEXT,
  evaluated_by VARCHAR(150),
  evaluated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_supplier_audit_log (
  id           BIGSERIAL PRIMARY KEY,
  supplier_id  BIGINT NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE,
  action_label VARCHAR(255) NOT NULL,
  actor_name   VARCHAR(150),
  remarks      TEXT,
  dot_class    VARCHAR(30) NOT NULL DEFAULT 'hd-b',
  occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_rfqs (
  id           BIGSERIAL PRIMARY KEY,
  rfq_no       VARCHAR(30)  UNIQUE NOT NULL,
  pr_ref       VARCHAR(50)  NOT NULL,
  department   VARCHAR(100) NOT NULL DEFAULT '',
  date_issued  DATE         NOT NULL,
  deadline     DATE         NOT NULL,
  status       VARCHAR(30)  NOT NULL DEFAULT 'Draft',
  items        TEXT         NOT NULL DEFAULT '',
  notes        TEXT,
  evaluator    VARCHAR(150),
  override_reason TEXT,
  sent_by      VARCHAR(150),
  mod_by       VARCHAR(150),
  created_user_id VARCHAR(20),
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_rfq_suppliers (
  id          BIGSERIAL PRIMARY KEY,
  rfq_id      BIGINT NOT NULL REFERENCES psm_rfqs(id) ON DELETE CASCADE,
  supplier_id BIGINT NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE,
  UNIQUE(rfq_id, supplier_id)
);

CREATE TABLE psm_rfq_responses (
  id          BIGSERIAL PRIMARY KEY,
  rfq_id      BIGINT NOT NULL REFERENCES psm_rfqs(id) ON DELETE CASCADE,
  supplier_id BIGINT NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE,
  amount      NUMERIC(15,2) NOT NULL DEFAULT 0,
  lead_days   INTEGER NOT NULL DEFAULT 0,
  notes       TEXT,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE psm_rfq_audit_log (
  id           BIGSERIAL PRIMARY KEY,
  rfq_id       BIGINT NOT NULL REFERENCES psm_rfqs(id) ON DELETE CASCADE,
  action_label VARCHAR(255) NOT NULL,
  actor_name   VARCHAR(150) NOT NULL,
  dot_class    VARCHAR(30)  NOT NULL DEFAULT 'dot-b',
  ip_address   VARCHAR(45),
  occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PSM — Quotation Evaluation Schema
-- Extends psm.sql (requires psm_rfqs, psm_rfq_responses, psm_suppliers)
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ── 1. EVALUATIONS ─────────────────────────────────────────────────────────
-- Stores per-supplier scoring for each RFQ
CREATE TABLE IF NOT EXISTS psm_quotation_evaluations (
    id               BIGSERIAL    PRIMARY KEY,
    rfq_id           BIGINT       NOT NULL REFERENCES psm_rfqs(id)      ON DELETE CASCADE,
    supplier_id      BIGINT       NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE,
    response_id      BIGINT       REFERENCES psm_rfq_responses(id)      ON DELETE SET NULL,

    -- Quoted pricing (mirrors / snapshot from rfq_responses)
    unit_price       NUMERIC(15,2) NOT NULL DEFAULT 0,
    total_price      NUMERIC(15,2) NOT NULL DEFAULT 0,
    delivery_terms   VARCHAR(255)  NOT NULL DEFAULT '',
    warranty         VARCHAR(255)  NOT NULL DEFAULT '',

    -- Scoring (0–100 each)
    price_score      NUMERIC(5,2)  NOT NULL DEFAULT 0,
    quality_score    NUMERIC(5,2)  NOT NULL DEFAULT 0,
    delivery_score   NUMERIC(5,2)  NOT NULL DEFAULT 0,
    warranty_score   NUMERIC(5,2)  NOT NULL DEFAULT 0,

    -- Computed: price*0.4 + quality*0.3 + delivery*0.2 + warranty*0.1
    overall_score    NUMERIC(5,2)  NOT NULL DEFAULT 0,

    remarks          TEXT,

    -- Status lifecycle: pending → scored → winner → endorsed
    eval_status      VARCHAR(30)   NOT NULL DEFAULT 'Pending'
                         CHECK (eval_status IN ('Pending','Scored','Winner','Endorsed')),

    scored_by        VARCHAR(150),
    scored_by_user_id VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,
    scored_at        TIMESTAMPTZ,

    -- SA override tracking
    is_overridden    BOOLEAN       NOT NULL DEFAULT FALSE,
    override_reason  TEXT,
    overridden_by    VARCHAR(150),
    overridden_at    TIMESTAMPTZ,

    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    UNIQUE (rfq_id, supplier_id)
);

CREATE INDEX IF NOT EXISTS idx_qe_rfq_id      ON psm_quotation_evaluations (rfq_id);
CREATE INDEX IF NOT EXISTS idx_qe_supplier_id ON psm_quotation_evaluations (supplier_id);
CREATE INDEX IF NOT EXISTS idx_qe_status      ON psm_quotation_evaluations (eval_status);
CREATE INDEX IF NOT EXISTS idx_qe_scored_at   ON psm_quotation_evaluations (scored_at DESC);


-- ── 2. EVALUATION AUDIT LOG ─────────────────────────────────────────────────
-- Full immutable audit trail of every action on an evaluation
CREATE TABLE IF NOT EXISTS psm_evaluation_audit_log (
    id              BIGSERIAL    PRIMARY KEY,
    evaluation_id   BIGINT       NOT NULL REFERENCES psm_quotation_evaluations(id) ON DELETE CASCADE,
    rfq_id          BIGINT       NOT NULL REFERENCES psm_rfqs(id)                  ON DELETE CASCADE,
    supplier_id     BIGINT       NOT NULL REFERENCES psm_suppliers(id)             ON DELETE CASCADE,

    action_label    VARCHAR(255) NOT NULL,   -- e.g. "Scored", "Winner Selected", "SA Override - Winner"
    actor_name      VARCHAR(150) NOT NULL,
    actor_role      VARCHAR(100) NOT NULL DEFAULT 'Super Admin',
    actor_user_id   VARCHAR(20)  REFERENCES users(user_id) ON DELETE SET NULL,

    -- Snapshot of scores at time of action
    price_score     NUMERIC(5,2),
    quality_score   NUMERIC(5,2),
    delivery_score  NUMERIC(5,2),
    warranty_score  NUMERIC(5,2),
    overall_score   NUMERIC(5,2),

    remarks         TEXT,
    ip_address      VARCHAR(45),
    is_super_admin  BOOLEAN      NOT NULL DEFAULT FALSE,
    occurred_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_eal_evaluation_id ON psm_evaluation_audit_log (evaluation_id);
CREATE INDEX IF NOT EXISTS idx_eal_rfq_id        ON psm_evaluation_audit_log (rfq_id);
CREATE INDEX IF NOT EXISTS idx_eal_occurred_at   ON psm_evaluation_audit_log (occurred_at DESC);


-- ── 3. ENDORSEMENT LOG ──────────────────────────────────────────────────────
-- Records when a winning quote is endorsed to Legal
CREATE TABLE IF NOT EXISTS psm_endorsement_log (
    id              BIGSERIAL    PRIMARY KEY,
    rfq_id          BIGINT       NOT NULL REFERENCES psm_rfqs(id)      ON DELETE CASCADE,
    evaluation_id   BIGINT       NOT NULL REFERENCES psm_quotation_evaluations(id) ON DELETE CASCADE,
    supplier_id     BIGINT       NOT NULL REFERENCES psm_suppliers(id) ON DELETE CASCADE,

    notes           TEXT         NOT NULL,
    legal_contact   VARCHAR(150),

    endorsed_by      VARCHAR(150) NOT NULL,
    endorsed_by_user_id VARCHAR(20) REFERENCES users(user_id) ON DELETE SET NULL,
    endorsed_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_el_rfq_id ON psm_endorsement_log (rfq_id);


-- ── 4. SCORING WEIGHTS CONFIG ────────────────────────────────────────────────
-- Allows Super Admin to adjust scoring criteria weights per branch / globally
CREATE TABLE IF NOT EXISTS psm_scoring_weights (
    id            SERIAL       PRIMARY KEY,
    scope         VARCHAR(50)  NOT NULL DEFAULT 'global',  -- 'global' or branch name
    price_weight  NUMERIC(4,2) NOT NULL DEFAULT 0.40,
    quality_weight NUMERIC(4,2) NOT NULL DEFAULT 0.30,
    delivery_weight NUMERIC(4,2) NOT NULL DEFAULT 0.20,
    warranty_weight NUMERIC(4,2) NOT NULL DEFAULT 0.10,
    updated_by    VARCHAR(150),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (scope)
);

-- Default global weights
INSERT INTO psm_scoring_weights (scope, price_weight, quality_weight, delivery_weight, warranty_weight)
VALUES ('global', 0.40, 0.30, 0.20, 0.10)
ON CONFLICT (scope) DO NOTHING;


-- ── 5. AUTO-UPDATE updated_at TRIGGER ────────────────────────────────────────
CREATE OR REPLACE FUNCTION set_qe_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_qe_updated_at ON psm_quotation_evaluations;
CREATE TRIGGER trg_qe_updated_at
    BEFORE UPDATE ON psm_quotation_evaluations
    FOR EACH ROW EXECUTE FUNCTION set_qe_updated_at();


-- ── 6. HELPER VIEW: Evaluation Summary ───────────────────────────────────────
CREATE OR REPLACE VIEW psm_evaluation_summary AS
SELECT
    e.id,
    e.rfq_id,
    r.rfq_no,
    r.pr_ref,
    r.department,
    r.date_issued,
    e.supplier_id,
    s.name          AS supplier_name,
    s.category      AS supplier_category,
    sm.branch       AS branch,
    e.unit_price,
    e.total_price,
    e.delivery_terms,
    e.warranty,
    e.price_score,
    e.quality_score,
    e.delivery_score,
    e.warranty_score,
    e.overall_score,
    e.remarks,
    e.eval_status,
    e.scored_by,
    e.scored_at,
    e.is_overridden,
    e.override_reason,
    e.overridden_by,
    e.overridden_at,
    e.created_at,
    e.updated_at,
    RANK() OVER (
        PARTITION BY e.rfq_id
        ORDER BY e.overall_score DESC NULLS LAST
    ) AS rank_in_rfq
FROM psm_quotation_evaluations e
JOIN psm_rfqs       r  ON r.id = e.rfq_id
JOIN psm_suppliers  s  ON s.id = e.supplier_id
LEFT JOIN psm_supplier_metrics sm ON sm.supplier_id = e.supplier_id;

-- ============================================================
-- PSM — Purchase Orders Tables
-- Run AFTER user.sql and psm.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. PURCHASE ORDERS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_purchase_orders (
    id                 BIGSERIAL     PRIMARY KEY,
    po_number          VARCHAR(30)   UNIQUE NOT NULL,
    pr_reference       VARCHAR(50)   NOT NULL,
    supplier_name      VARCHAR(255)  NOT NULL,
    supplier_category  VARCHAR(100)  NOT NULL DEFAULT 'General',
    branch             VARCHAR(100)  NOT NULL DEFAULT 'Main Office',
    issued_by          VARCHAR(150)  NOT NULL,
    date_issued        DATE          NOT NULL,
    delivery_date      DATE,
    payment_terms      VARCHAR(100)  NOT NULL DEFAULT 'Net 30',
    remarks            TEXT          NOT NULL DEFAULT '',
    status             VARCHAR(30)   NOT NULL DEFAULT 'Draft'
                           CHECK (status IN ('Draft','Sent','Confirmed',
                                             'Partially Fulfilled','Fulfilled',
                                             'Cancelled','Voided')),
    fulfill_pct        SMALLINT      NOT NULL DEFAULT 0 CHECK (fulfill_pct BETWEEN 0 AND 100),
    total_amount       NUMERIC(15,2) NOT NULL DEFAULT 0,
    created_user_id    VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by         VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ppo_status      ON psm_purchase_orders (status);
CREATE INDEX IF NOT EXISTS idx_ppo_branch      ON psm_purchase_orders (branch);
CREATE INDEX IF NOT EXISTS idx_ppo_supplier    ON psm_purchase_orders (supplier_name);
CREATE INDEX IF NOT EXISTS idx_ppo_issued_by   ON psm_purchase_orders (issued_by);
CREATE INDEX IF NOT EXISTS idx_ppo_date_issued ON psm_purchase_orders (date_issued DESC);
CREATE INDEX IF NOT EXISTS idx_ppo_created_at  ON psm_purchase_orders (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ppo_user        ON psm_purchase_orders (created_user_id);

-- auto-update updated_at
CREATE OR REPLACE FUNCTION set_ppo_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_ppo_updated_at ON psm_purchase_orders;
CREATE TRIGGER trg_ppo_updated_at
    BEFORE UPDATE ON psm_purchase_orders
    FOR EACH ROW EXECUTE FUNCTION set_ppo_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. LINE ITEMS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_po_items (
    id         BIGSERIAL     PRIMARY KEY,
    po_id      BIGINT        NOT NULL REFERENCES psm_purchase_orders(id) ON DELETE CASCADE,
    line_no    SMALLINT      NOT NULL,
    item_name  VARCHAR(255)  NOT NULL,
    unit       VARCHAR(50)   NOT NULL DEFAULT 'pcs',
    quantity   NUMERIC(12,2) NOT NULL DEFAULT 1,
    unit_price NUMERIC(15,2) NOT NULL DEFAULT 0,
    line_total NUMERIC(15,2) NOT NULL DEFAULT 0,
    UNIQUE (po_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_poi_po_id ON psm_po_items (po_id);


-- ────────────────────────────────────────────────────────────
-- 3. AUDIT LOG (per-PO)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_po_audit_log (
    id             BIGSERIAL    PRIMARY KEY,
    po_id          BIGINT       NOT NULL REFERENCES psm_purchase_orders(id) ON DELETE CASCADE,
    action_label   VARCHAR(255) NOT NULL,
    actor_name     VARCHAR(150) NOT NULL,
    dot_class      VARCHAR(30)  NOT NULL DEFAULT 'dot-b',
    is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
    ip_address     VARCHAR(45),
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ppal_po_id      ON psm_po_audit_log (po_id);
CREATE INDEX IF NOT EXISTS idx_ppal_occurred   ON psm_po_audit_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_ppal_is_sa      ON psm_po_audit_log (is_super_admin);


-- ────────────────────────────────────────────────────────────
-- 4. SEND LOG  (optional — tracks email dispatch)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_po_send_log (
    id               BIGSERIAL    PRIMARY KEY,
    po_id            BIGINT       NOT NULL REFERENCES psm_purchase_orders(id) ON DELETE CASCADE,
    recipient_email  VARCHAR(255),
    message          TEXT,
    sent_by          VARCHAR(150) NOT NULL,
    sent_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ppsl_po_id ON psm_po_send_log (po_id);


-- ────────────────────────────────────────────────────────────
-- 5. APPROVAL CHAIN (per-PO, 3 levels)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_po_approvals (
    id            BIGSERIAL    PRIMARY KEY,
    po_id         BIGINT       NOT NULL REFERENCES psm_purchase_orders(id) ON DELETE CASCADE,
    level         SMALLINT     NOT NULL,          -- 1=Proc.Officer, 2=Dept.Manager, 3=Finance
    role_label    VARCHAR(100) NOT NULL,
    approved_by   VARCHAR(150),
    approved_at   TIMESTAMPTZ,
    is_done       BOOLEAN      NOT NULL DEFAULT FALSE,
    UNIQUE (po_id, level)
);

CREATE INDEX IF NOT EXISTS idx_ppap_po_id ON psm_po_approvals (po_id);


-- ────────────────────────────────────────────────────────────
-- 6. HELPER VIEW: PO list with item count
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW psm_po_summary AS
SELECT
    po.*,
    COALESCE(ic.item_count, 0)  AS item_count,
    COALESCE(a1.is_done, FALSE) AS approval_l1_done,
    COALESCE(a2.is_done, FALSE) AS approval_l2_done,
    COALESCE(a3.is_done, FALSE) AS approval_l3_done
FROM psm_purchase_orders po
LEFT JOIN (
    SELECT po_id, COUNT(*) AS item_count FROM psm_po_items GROUP BY po_id
) ic ON ic.po_id = po.id
LEFT JOIN psm_po_approvals a1 ON a1.po_id = po.id AND a1.level = 1
LEFT JOIN psm_po_approvals a2 ON a2.po_id = po.id AND a2.level = 2
LEFT JOIN psm_po_approvals a3 ON a3.po_id = po.id AND a3.level = 3;


-- ────────────────────────────────────────────────────────────
-- 7. RLS  (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE psm_purchase_orders DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_po_items        DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_po_audit_log    DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_po_send_log     DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_po_approvals    DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- PSM — Contract Management Tables
-- Run AFTER user.sql and psm.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. CONTRACTS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_contracts (
    id               BIGSERIAL     PRIMARY KEY,
    contract_no      VARCHAR(30)   UNIQUE NOT NULL,
    po_ref           VARCHAR(50)   NOT NULL,
    supplier         VARCHAR(255)  NOT NULL,
    branch           VARCHAR(100)  NOT NULL DEFAULT 'Main Office',
    contract_type    VARCHAR(100)  NOT NULL DEFAULT 'Supply Agreement',
    value            NUMERIC(15,2) NOT NULL DEFAULT 0,
    start_date       DATE          NOT NULL,
    expiry_date      DATE          NOT NULL,

    -- legal_status drives the legal workflow
    legal_status     VARCHAR(30)   NOT NULL DEFAULT 'Pending Review'
                         CHECK (legal_status IN (
                             'Pending Review', 'Under Review', 'Approved', 'Rejected'
                         )),

    -- status is the raw operational status stored in DB.
    -- The computed status (Expiring Soon / Expired) is derived at runtime.
    status           VARCHAR(30)   NOT NULL DEFAULT 'Active'
                         CHECK (status IN (
                             'Active', 'Under Review', 'Expiring Soon',
                             'Expired', 'Terminated', 'Archived'
                         )),

    renewal          SMALLINT      NOT NULL DEFAULT 0,  -- 0 = no flag, 1 = flagged
    notes            TEXT          NOT NULL DEFAULT '',
    sa_notes         TEXT          NOT NULL DEFAULT '',

    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pcm_status      ON psm_contracts (status);
CREATE INDEX IF NOT EXISTS idx_pcm_legal       ON psm_contracts (legal_status);
CREATE INDEX IF NOT EXISTS idx_pcm_supplier    ON psm_contracts (supplier);
CREATE INDEX IF NOT EXISTS idx_pcm_branch      ON psm_contracts (branch);
CREATE INDEX IF NOT EXISTS idx_pcm_expiry      ON psm_contracts (expiry_date ASC);
CREATE INDEX IF NOT EXISTS idx_pcm_created_at  ON psm_contracts (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pcm_user        ON psm_contracts (created_user_id);

-- Auto-update updated_at
CREATE OR REPLACE FUNCTION set_pcm_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_pcm_updated_at ON psm_contracts;
CREATE TRIGGER trg_pcm_updated_at
    BEFORE UPDATE ON psm_contracts
    FOR EACH ROW EXECUTE FUNCTION set_pcm_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. CONTRACT DOCUMENTS
-- Stores metadata for uploaded contract files.
-- Actual file bytes live in Supabase Storage bucket "contract-docs".
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_contract_documents (
    id            BIGSERIAL    PRIMARY KEY,
    contract_id   BIGINT       NOT NULL REFERENCES psm_contracts(id) ON DELETE CASCADE,
    file_name     VARCHAR(255) NOT NULL,
    file_size     VARCHAR(30)  NOT NULL DEFAULT '',   -- e.g. "2.4 MB"
    file_type     VARCHAR(10)  NOT NULL DEFAULT 'pdf',-- 'pdf' | 'docx'
    file_path     TEXT         NOT NULL DEFAULT '',   -- Supabase Storage path
    uploaded_by   VARCHAR(150) NOT NULL DEFAULT 'Super Admin',
    uploaded_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pcd_contract_id ON psm_contract_documents (contract_id);
CREATE INDEX IF NOT EXISTS idx_pcd_uploaded_at ON psm_contract_documents (uploaded_at DESC);


-- ────────────────────────────────────────────────────────────
-- 3. CONTRACT AUDIT LOG
-- Immutable trail of every action on a contract.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_contract_audit_log (
    id            BIGSERIAL    PRIMARY KEY,
    contract_id   BIGINT       NOT NULL REFERENCES psm_contracts(id) ON DELETE CASCADE,
    action_label  VARCHAR(255) NOT NULL,
    actor_name    VARCHAR(150) NOT NULL,
    dot_class     VARCHAR(30)  NOT NULL DEFAULT 'dot-b',
    -- dot-g = created/approved   dot-b = edited/sent
    -- dot-o = renewal/warning    dot-r = terminated/rejected
    -- dot-pu = legal             dot-gy = archived
    ip_address    VARCHAR(45),
    occurred_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pcal_contract_id  ON psm_contract_audit_log (contract_id);
CREATE INDEX IF NOT EXISTS idx_pcal_occurred_at  ON psm_contract_audit_log (occurred_at DESC);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW: contract list with doc count
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW psm_contract_summary AS
SELECT
    c.*,
    COALESCE(dc.doc_count, 0) AS doc_count
FROM psm_contracts c
LEFT JOIN (
    SELECT contract_id, COUNT(*) AS doc_count
    FROM psm_contract_documents
    GROUP BY contract_id
) dc ON dc.contract_id = c.id;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth, same pattern as other PSM tables)
-- ────────────────────────────────────────────────────────────
ALTER TABLE psm_contracts          DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_contract_documents DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_contract_audit_log DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- PSM — Receiving & Inspection Tables
-- Run AFTER user.sql and psm.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. RECEIPTS (header)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_receipts (
    id               BIGSERIAL     PRIMARY KEY,
    receipt_no       VARCHAR(30)   UNIQUE NOT NULL,
    po_ref           VARCHAR(50)   NOT NULL,
    supplier         VARCHAR(255)  NOT NULL,
    branch           VARCHAR(100)  NOT NULL DEFAULT 'Main Office',
    delivery_date    DATE          NOT NULL,
    location         VARCHAR(255)  NOT NULL DEFAULT '',
    items_expected   INTEGER       NOT NULL DEFAULT 0,
    items_received   INTEGER       NOT NULL DEFAULT 0,
    condition        VARCHAR(30)   NOT NULL DEFAULT 'Good'
                         CHECK (condition IN ('Good','Minor Damage','Damaged','Mixed','—')),
    inspected_by     VARCHAR(150)  NOT NULL DEFAULT '—',
    status           VARCHAR(30)   NOT NULL DEFAULT 'Pending'
                         CHECK (status IN (
                             'Pending','Received','Partially Received',
                             'Rejected','Disputed','Completed'
                         )),
    flag             SMALLINT      NOT NULL DEFAULT 0,
    -- 0=none 1=Short Delivery 2=Damage 3=Wrong Items 4=Missing Docs 5=Quality Issue
    override         SMALLINT      NOT NULL DEFAULT 0,
    cross_update     VARCHAR(10)   NOT NULL DEFAULT '0',
    -- '0' | 'sws' | 'alms' | 'both'
    notes            TEXT          NOT NULL DEFAULT '',
    sa_notes         TEXT          NOT NULL DEFAULT '',
    created_user_id  VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by       VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_prc_status        ON psm_receipts (status);
CREATE INDEX IF NOT EXISTS idx_prc_po_ref        ON psm_receipts (po_ref);
CREATE INDEX IF NOT EXISTS idx_prc_supplier      ON psm_receipts (supplier);
CREATE INDEX IF NOT EXISTS idx_prc_branch        ON psm_receipts (branch);
CREATE INDEX IF NOT EXISTS idx_prc_delivery_date ON psm_receipts (delivery_date DESC);
CREATE INDEX IF NOT EXISTS idx_prc_created_at    ON psm_receipts (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_prc_user          ON psm_receipts (created_user_id);

CREATE OR REPLACE FUNCTION set_prc_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_prc_updated_at ON psm_receipts;
CREATE TRIGGER trg_prc_updated_at
    BEFORE UPDATE ON psm_receipts
    FOR EACH ROW EXECUTE FUNCTION set_prc_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. RECEIPT LINE ITEMS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_receipt_items (
    id          BIGSERIAL    PRIMARY KEY,
    receipt_id  BIGINT       NOT NULL REFERENCES psm_receipts(id) ON DELETE CASCADE,
    description VARCHAR(255) NOT NULL,
    expected    INTEGER      NOT NULL DEFAULT 0,
    received    INTEGER      NOT NULL DEFAULT 0,
    condition   VARCHAR(30)  NOT NULL DEFAULT 'Good'
);

CREATE INDEX IF NOT EXISTS idx_pri_receipt_id ON psm_receipt_items (receipt_id);


-- ────────────────────────────────────────────────────────────
-- 3. RECEIPT AUDIT LOG
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS psm_receipt_audit_log (
    id           BIGSERIAL    PRIMARY KEY,
    receipt_id   BIGINT       NOT NULL REFERENCES psm_receipts(id) ON DELETE CASCADE,
    action_label VARCHAR(255) NOT NULL,
    actor_name   VARCHAR(150) NOT NULL,
    dot_class    VARCHAR(20)  NOT NULL DEFAULT 'blue',
    -- green | red | orange | blue | teal | purple
    ip_address   VARCHAR(45),
    occurred_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pral_receipt_id  ON psm_receipt_audit_log (receipt_id);
CREATE INDEX IF NOT EXISTS idx_pral_occurred_at ON psm_receipt_audit_log (occurred_at DESC);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW psm_receipt_summary AS
SELECT
    r.*,
    COALESCE(ic.item_count, 0) AS item_count,
    CASE WHEN r.items_expected > 0
         THEN ROUND(r.items_received::numeric / r.items_expected * 100)
         ELSE 100
    END AS fulfillment_pct
FROM psm_receipts r
LEFT JOIN (
    SELECT receipt_id, COUNT(*) AS item_count
    FROM psm_receipt_items
    GROUP BY receipt_id
) ic ON ic.receipt_id = r.id;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE psm_receipts          DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_receipt_items     DISABLE ROW LEVEL SECURITY;
ALTER TABLE psm_receipt_audit_log DISABLE ROW LEVEL SECURITY;