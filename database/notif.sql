-- ============================================================
-- NOTIFICATIONS — Persistent Alert Store
-- Run AFTER all module SQL files.
-- Database: PostgreSQL (Supabase)
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id              BIGSERIAL     PRIMARY KEY,
    notif_id        VARCHAR(20)   UNIQUE NOT NULL,          -- e.g. ALT-0001
    category        VARCHAR(50)   NOT NULL,                 -- Low Stock | PO Pending | Delivery Delay | Maintenance Due | Document Issues | Project Overdue | Assignment Overdue
    module          VARCHAR(20)   NOT NULL,                 -- SWS | PSM | PLT | ALMS | DTRS | System
    severity        VARCHAR(20)   NOT NULL DEFAULT 'Medium'
                        CHECK (severity IN ('Critical','High','Medium','Low')),
    title           VARCHAR(500)  NOT NULL,
    description     TEXT          NOT NULL DEFAULT '',
    zone            VARCHAR(150)  NOT NULL DEFAULT '',
    status          VARCHAR(20)   NOT NULL DEFAULT 'unread'
                        CHECK (status IN ('unread','read','escalated','dismissed')),
    source_table    VARCHAR(100)  NOT NULL DEFAULT '',      -- e.g. sws_inventory
    source_id       BIGINT,                                 -- FK to the triggering row
    escalated_by    VARCHAR(150),
    escalated_at    TIMESTAMPTZ,
    escalate_priority VARCHAR(20),
    escalate_remarks  TEXT,
    dismissed_by    VARCHAR(150),
    dismissed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notif_status    ON notifications (status);
CREATE INDEX IF NOT EXISTS idx_notif_module    ON notifications (module);
CREATE INDEX IF NOT EXISTS idx_notif_category  ON notifications (category);
CREATE INDEX IF NOT EXISTS idx_notif_severity  ON notifications (severity);
CREATE INDEX IF NOT EXISTS idx_notif_zone      ON notifications (zone);
CREATE INDEX IF NOT EXISTS idx_notif_source    ON notifications (source_table, source_id);
CREATE INDEX IF NOT EXISTS idx_notif_created   ON notifications (created_at DESC);

CREATE OR REPLACE FUNCTION set_notif_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_notif_updated_at ON notifications;
CREATE TRIGGER trg_notif_updated_at
    BEFORE UPDATE ON notifications
    FOR EACH ROW EXECUTE FUNCTION set_notif_updated_at();

ALTER TABLE notifications DISABLE ROW LEVEL SECURITY;

-- VERIFY
SELECT COUNT(*) AS total_notifications FROM notifications;