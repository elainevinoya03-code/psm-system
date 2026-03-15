-- ============================================================
-- SWS — Smart Warehousing System: Inventory & Cycle Count
-- Run AFTER user.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. ZONES
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_zones (
    id          VARCHAR(10)  PRIMARY KEY,   -- e.g. ZN-A01
    name        VARCHAR(100) NOT NULL,
    color       VARCHAR(20)  NOT NULL DEFAULT '#2E7D32',
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

INSERT INTO sws_zones (id, name, color) VALUES
    ('ZN-A01','Zone A — Raw Materials',       '#2E7D32'),
    ('ZN-B02','Zone B — Safety & PPE',        '#0D9488'),
    ('ZN-C03','Zone C — Fuels & Lubricants',  '#DC2626'),
    ('ZN-D04','Zone D — Office Supplies',     '#2563EB'),
    ('ZN-E05','Zone E — Electrical & IT',     '#7C3AED'),
    ('ZN-F06','Zone F — Tools & Equipment',   '#D97706'),
    ('ZN-G07','Zone G — Finished Goods',      '#059669')
ON CONFLICT (id) DO NOTHING;


-- ────────────────────────────────────────────────────────────
-- 2. INVENTORY ITEMS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_inventory (
    id              BIGSERIAL     PRIMARY KEY,
    code            VARCHAR(30)   UNIQUE NOT NULL,  -- e.g. ITM-0001
    name            VARCHAR(255)  NOT NULL,
    category        VARCHAR(100)  NOT NULL DEFAULT '',
    uom             VARCHAR(30)   NOT NULL DEFAULT 'pcs',
    zone            VARCHAR(10)   REFERENCES sws_zones(id),
    bin             VARCHAR(30)   NOT NULL DEFAULT '',
    stock           INTEGER       NOT NULL DEFAULT 0,
    min_level       INTEGER       NOT NULL DEFAULT 0,
    max_level       INTEGER       NOT NULL DEFAULT 100,
    rop             INTEGER       NOT NULL DEFAULT 0,   -- reorder point
    last_restocked  DATE,
    active          BOOLEAN       NOT NULL DEFAULT TRUE,
    created_user_id VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by      VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swsi_zone     ON sws_inventory (zone);
CREATE INDEX IF NOT EXISTS idx_swsi_category ON sws_inventory (category);
CREATE INDEX IF NOT EXISTS idx_swsi_active   ON sws_inventory (active);
CREATE INDEX IF NOT EXISTS idx_swsi_code     ON sws_inventory (code);

CREATE OR REPLACE FUNCTION set_swsi_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_swsi_updated_at ON sws_inventory;
CREATE TRIGGER trg_swsi_updated_at
    BEFORE UPDATE ON sws_inventory
    FOR EACH ROW EXECUTE FUNCTION set_swsi_updated_at();


-- ────────────────────────────────────────────────────────────
-- 3. INVENTORY AUDIT LOG
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_inventory_audit (
    id           BIGSERIAL    PRIMARY KEY,
    item_id      BIGINT       NOT NULL REFERENCES sws_inventory(id) ON DELETE CASCADE,
    action       VARCHAR(50)  NOT NULL,   -- add | edit | adjust | transfer | deactivate | activate
    detail       TEXT         NOT NULL DEFAULT '',
    old_stock    INTEGER,
    new_stock    INTEGER,
    actor_name   VARCHAR(150) NOT NULL,
    ip_address   VARCHAR(45),
    occurred_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swsia_item_id    ON sws_inventory_audit (item_id);
CREATE INDEX IF NOT EXISTS idx_swsia_occurred   ON sws_inventory_audit (occurred_at DESC);


-- ────────────────────────────────────────────────────────────
-- 4. STOCK ADJUSTMENTS LOG
-- (granular record of every adjust / transfer)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_stock_adjustments (
    id           BIGSERIAL    PRIMARY KEY,
    item_id      BIGINT       NOT NULL REFERENCES sws_inventory(id) ON DELETE CASCADE,
    adj_type     VARCHAR(20)  NOT NULL,   -- add | remove | set | transfer_out | transfer_in
    quantity     INTEGER      NOT NULL,
    old_stock    INTEGER      NOT NULL,
    new_stock    INTEGER      NOT NULL,
    notes        TEXT         NOT NULL DEFAULT '',
    to_zone      VARCHAR(10)  REFERENCES sws_zones(id),
    to_bin       VARCHAR(30),
    actor_name   VARCHAR(150) NOT NULL,
    occurred_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swsadj_item_id  ON sws_stock_adjustments (item_id);
CREATE INDEX IF NOT EXISTS idx_swsadj_occurred ON sws_stock_adjustments (occurred_at DESC);


-- ────────────────────────────────────────────────────────────
-- 5. CYCLE COUNT RECORDS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_cycle_counts (
    id            BIGSERIAL    PRIMARY KEY,
    record_no     VARCHAR(20)  UNIQUE NOT NULL,  -- e.g. CC-001
    count_date    DATE         NOT NULL,
    item_id       BIGINT       REFERENCES sws_inventory(id) ON DELETE SET NULL,
    item_code     VARCHAR(30)  NOT NULL,
    item_name     VARCHAR(255) NOT NULL DEFAULT '',
    category      VARCHAR(100) NOT NULL DEFAULT '',
    uom           VARCHAR(30)  NOT NULL DEFAULT 'pcs',
    zone          VARCHAR(10)  REFERENCES sws_zones(id),
    physical_count INTEGER     NOT NULL DEFAULT 0,
    system_count   INTEGER     NOT NULL DEFAULT 0,
    variance       INTEGER     GENERATED ALWAYS AS (physical_count - system_count) STORED,
    notes          TEXT        NOT NULL DEFAULT '',
    counted_by     VARCHAR(150) NOT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'Pending'
                       CHECK (status IN ('Pending','Matched','Over','Short','Flagged','Approved','Rejected')),
    approved_by    VARCHAR(150) NOT NULL DEFAULT '',
    approved_date  DATE,
    created_user_id VARCHAR(20) REFERENCES users(user_id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swscc_item_id    ON sws_cycle_counts (item_id);
CREATE INDEX IF NOT EXISTS idx_swscc_status     ON sws_cycle_counts (status);
CREATE INDEX IF NOT EXISTS idx_swscc_count_date ON sws_cycle_counts (count_date DESC);
CREATE INDEX IF NOT EXISTS idx_swscc_zone       ON sws_cycle_counts (zone);

CREATE OR REPLACE FUNCTION set_swscc_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_swscc_updated_at ON sws_cycle_counts;
CREATE TRIGGER trg_swscc_updated_at
    BEFORE UPDATE ON sws_cycle_counts
    FOR EACH ROW EXECUTE FUNCTION set_swscc_updated_at();


-- ────────────────────────────────────────────────────────────
-- 6. HELPER VIEWS
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW sws_inventory_status AS
SELECT
    i.*,
    z.name  AS zone_name,
    z.color AS zone_color,
    CASE
        WHEN NOT i.active     THEN 'Inactive'
        WHEN i.stock = 0      THEN 'Out of Stock'
        WHEN i.stock > i.max_level THEN 'Overstocked'
        WHEN i.stock <= i.min_level THEN 'Low Stock'
        ELSE 'In Stock'
    END AS status
FROM sws_inventory i
LEFT JOIN sws_zones z ON z.id = i.zone;

CREATE OR REPLACE VIEW sws_cycle_count_summary AS
SELECT
    c.*,
    z.name  AS zone_name,
    z.color AS zone_color
FROM sws_cycle_counts c
LEFT JOIN sws_zones z ON z.id = c.zone;


-- ────────────────────────────────────────────────────────────
-- 7. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE sws_zones              DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_inventory          DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_inventory_audit    DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_stock_adjustments  DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_cycle_counts       DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- SWS — Stock In / Stock Out Transactions
-- Run AFTER user.sql and sws_inventory.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. TRANSACTIONS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_transactions (
    id              BIGSERIAL     PRIMARY KEY,
    txn_id          VARCHAR(20)   UNIQUE NOT NULL,  -- TXN-SI-0001 / TXN-SO-0001
    type            VARCHAR(3)    NOT NULL CHECK (type IN ('in','out')),
    date_time       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    item_id         BIGINT        REFERENCES sws_inventory(id) ON DELETE SET NULL,
    item_code       VARCHAR(30)   NOT NULL,
    item_name       VARCHAR(255)  NOT NULL DEFAULT '',
    qty             INTEGER       NOT NULL CHECK (qty > 0),
    uom             VARCHAR(30)   NOT NULL DEFAULT 'pcs',
    ref_doc         VARCHAR(100)  NOT NULL DEFAULT '',
    ref_type        VARCHAR(10)   NOT NULL DEFAULT 'PO'
                        CHECK (ref_type IN ('PO','PR','TO','RR','DR','WO')),
    zone            VARCHAR(10)   REFERENCES sws_zones(id),
    bin             VARCHAR(30)   NOT NULL DEFAULT '',
    processed_by    VARCHAR(150)  NOT NULL DEFAULT '',
    status          VARCHAR(20)   NOT NULL DEFAULT 'Pending'
                        CHECK (status IN ('Pending','Processing','Completed','Discrepancy','Cancelled','Voided')),
    notes           TEXT          NOT NULL DEFAULT '',
    discrepancy     BOOLEAN       NOT NULL DEFAULT FALSE,
    disc_note       TEXT          NOT NULL DEFAULT '',
    created_user_id VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by      VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swst_type       ON sws_transactions (type);
CREATE INDEX IF NOT EXISTS idx_swst_status     ON sws_transactions (status);
CREATE INDEX IF NOT EXISTS idx_swst_item_code  ON sws_transactions (item_code);
CREATE INDEX IF NOT EXISTS idx_swst_zone       ON sws_transactions (zone);
CREATE INDEX IF NOT EXISTS idx_swst_date_time  ON sws_transactions (date_time DESC);
CREATE INDEX IF NOT EXISTS idx_swst_txn_id     ON sws_transactions (txn_id);

CREATE OR REPLACE FUNCTION set_swst_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_swst_updated_at ON sws_transactions;
CREATE TRIGGER trg_swst_updated_at
    BEFORE UPDATE ON sws_transactions
    FOR EACH ROW EXECUTE FUNCTION set_swst_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. TRANSACTION AUDIT LOG
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_txn_audit (
    id           BIGSERIAL    PRIMARY KEY,
    txn_id       VARCHAR(20)  NOT NULL,
    action       VARCHAR(50)  NOT NULL,  -- created | updated | cancelled | voided | overridden
    detail       TEXT         NOT NULL DEFAULT '',
    actor_name   VARCHAR(150) NOT NULL,
    ip_address   VARCHAR(45),
    occurred_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swstxa_txn_id   ON sws_txn_audit (txn_id);
CREATE INDEX IF NOT EXISTS idx_swstxa_occurred ON sws_txn_audit (occurred_at DESC);


-- ────────────────────────────────────────────────────────────
-- 3. HELPER VIEW
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW sws_transactions_full AS
SELECT
    t.*,
    z.name  AS zone_name,
    z.color AS zone_color
FROM sws_transactions t
LEFT JOIN sws_zones z ON z.id = t.zone;


-- ────────────────────────────────────────────────────────────
-- 4. NEXT TXN ID FUNCTION
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION sws_next_txn_id(p_type VARCHAR)
RETURNS VARCHAR AS $$
DECLARE
    prefix  VARCHAR;
    last_no INT := 0;
    last_id VARCHAR;
BEGIN
    prefix := CASE p_type WHEN 'in' THEN 'TXN-SI' ELSE 'TXN-SO' END;
    SELECT txn_id INTO last_id
    FROM sws_transactions
    WHERE type = p_type
    ORDER BY id DESC LIMIT 1;
    IF last_id IS NOT NULL THEN
        last_no := CAST(SPLIT_PART(last_id, '-', 3) AS INT);
    END IF;
    RETURN prefix || '-' || LPAD(CAST(last_no + 1 AS VARCHAR), 4, '0');
END;
$$ LANGUAGE plpgsql;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled)
-- ────────────────────────────────────────────────────────────
ALTER TABLE sws_transactions DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_txn_audit    DISABLE ROW LEVEL SECURITY;

-- ============================================================
-- SWS — Bin & Location Mapping
-- Run AFTER user.sql and sws_inventory.sql
-- Database: PostgreSQL (Supabase)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. BIN LOCATIONS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_bins (
    id              BIGSERIAL     PRIMARY KEY,
    bin_id          VARCHAR(20)   UNIQUE NOT NULL,   -- e.g. BIN-0001
    code            VARCHAR(30)   NOT NULL,           -- e.g. A01-R1-L1
    zone            VARCHAR(10)   NOT NULL REFERENCES sws_zones(id),
    row             VARCHAR(10)   NOT NULL,           -- e.g. R1, T1
    level           VARCHAR(10)   NOT NULL,           -- e.g. L1, L2
    capacity        INTEGER       NOT NULL DEFAULT 100,
    used            INTEGER       NOT NULL DEFAULT 0,
    status          VARCHAR(20)   NOT NULL DEFAULT 'Available'
                        CHECK (status IN ('Occupied','Available','Reserved','Inactive')),
    active          BOOLEAN       NOT NULL DEFAULT TRUE,
    notes           TEXT          NOT NULL DEFAULT '',
    created_user_id VARCHAR(20)   REFERENCES users(user_id) ON DELETE SET NULL,
    created_by      VARCHAR(150)  NOT NULL DEFAULT 'system',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    UNIQUE (zone, row, level)
);

CREATE INDEX IF NOT EXISTS idx_swsb_zone       ON sws_bins (zone);
CREATE INDEX IF NOT EXISTS idx_swsb_status     ON sws_bins (status);
CREATE INDEX IF NOT EXISTS idx_swsb_active     ON sws_bins (active);
CREATE INDEX IF NOT EXISTS idx_swsb_code       ON sws_bins (code);
CREATE INDEX IF NOT EXISTS idx_swsb_created_at ON sws_bins (created_at DESC);

CREATE OR REPLACE FUNCTION set_swsb_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_swsb_updated_at ON sws_bins;
CREATE TRIGGER trg_swsb_updated_at
    BEFORE UPDATE ON sws_bins
    FOR EACH ROW EXECUTE FUNCTION set_swsb_updated_at();


-- ────────────────────────────────────────────────────────────
-- 2. BIN ITEMS (many-to-many: bins <-> inventory)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_bin_items (
    id         BIGSERIAL  PRIMARY KEY,
    bin_id     BIGINT     NOT NULL REFERENCES sws_bins(id) ON DELETE CASCADE,
    item_id    BIGINT     REFERENCES sws_inventory(id) ON DELETE SET NULL,
    item_name  VARCHAR(255) NOT NULL,   -- snapshot / fallback if item_id is null
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (bin_id, item_name)
);

CREATE INDEX IF NOT EXISTS idx_swsbi_bin_id  ON sws_bin_items (bin_id);
CREATE INDEX IF NOT EXISTS idx_swsbi_item_id ON sws_bin_items (item_id);


-- ────────────────────────────────────────────────────────────
-- 3. BIN AUDIT LOG
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sws_bin_audit (
    id           BIGSERIAL    PRIMARY KEY,
    bin_id       BIGINT       NOT NULL REFERENCES sws_bins(id) ON DELETE CASCADE,
    action       VARCHAR(50)  NOT NULL,   -- create | edit | activate | deactivate | delete | reassign
    detail       TEXT         NOT NULL DEFAULT '',
    actor_name   VARCHAR(150) NOT NULL,
    ip_address   VARCHAR(45),
    occurred_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swsba_bin_id   ON sws_bin_audit (bin_id);
CREATE INDEX IF NOT EXISTS idx_swsba_occurred ON sws_bin_audit (occurred_at DESC);


-- ────────────────────────────────────────────────────────────
-- 4. HELPER VIEW: bins with item list and utilisation %
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW sws_bins_full AS
SELECT
    b.*,
    z.name                                  AS zone_name,
    z.color                                 AS zone_color,
    COALESCE(
        ARRAY_AGG(bi.item_name ORDER BY bi.item_name)
            FILTER (WHERE bi.item_name IS NOT NULL),
        '{}'::TEXT[]
    )                                       AS items,
    CASE WHEN b.capacity > 0
         THEN LEAST(100, ROUND((b.used::NUMERIC / b.capacity) * 100))
         ELSE 0
    END                                     AS util_pct
FROM sws_bins b
JOIN sws_zones z ON z.id = b.zone
LEFT JOIN sws_bin_items bi ON bi.bin_id = b.id
GROUP BY b.id, z.name, z.color;


-- ────────────────────────────────────────────────────────────
-- 5. RLS (disabled — app-level auth)
-- ────────────────────────────────────────────────────────────
ALTER TABLE sws_bins       DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_bin_items  DISABLE ROW LEVEL SECURITY;
ALTER TABLE sws_bin_audit  DISABLE ROW LEVEL SECURITY;