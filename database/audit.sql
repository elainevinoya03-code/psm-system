-- ============================================================
-- UNIFIED AUDIT LOG VIEW
-- Aggregates all module audit tables into one queryable view.
-- Run AFTER all module SQL files have been executed.
-- Database: PostgreSQL (Supabase)
-- ============================================================

CREATE OR REPLACE VIEW v_audit_unified AS

-- ── 1. USER MANAGEMENT (audit_logs) ────────────────────────────────────────
SELECT
    'UAL-' || id::TEXT               AS log_id,
    'User Mgmt'                       AS module,
    action                            AS action_label,
    performed_by                      AS actor_name,
    'Admin'                           AS actor_role,
    'Login/Access'                    AS action_type,
    'USR-' || user_id                 AS record_ref,
    ip_address,
    is_sa                             AS is_super_admin,
    NULL::TEXT                        AS note,
    created_at                        AS occurred_at
FROM audit_logs

UNION ALL

-- ── 2. PSM — Purchase Request Audit ────────────────────────────────────────
SELECT
    'PSM-PR-' || id::TEXT,
    'PSM',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN action_label ILIKE '%creat%'  THEN 'Create'
        WHEN action_label ILIKE '%approv%' THEN 'Approve'
        WHEN action_label ILIKE '%edit%' OR action_label ILIKE '%updat%' THEN 'Edit'
        WHEN action_label ILIKE '%delet%' THEN 'Delete'
        WHEN action_label ILIKE '%export%' THEN 'Export'
        ELSE 'Edit'
    END,
    'PR-' || pr_id::TEXT,
    ip_address,
    is_super_admin,
    remarks,
    occurred_at
FROM psm_pr_audit_log

UNION ALL

-- ── 3. PSM — Supplier Audit ─────────────────────────────────────────────────
SELECT
    'PSM-SUP-' || id::TEXT,
    'PSM',
    action_label,
    COALESCE(actor_name, 'System'),
    'Admin',
    CASE
        WHEN action_label ILIKE '%creat%' OR action_label ILIKE '%add%' THEN 'Create'
        WHEN action_label ILIKE '%approv%' OR action_label ILIKE '%accredit%' THEN 'Approve'
        WHEN action_label ILIKE '%delet%' OR action_label ILIKE '%remov%' THEN 'Delete'
        ELSE 'Edit'
    END,
    'SUPP-' || supplier_id::TEXT,
    NULL,
    FALSE,
    NULL,
    occurred_at
FROM psm_supplier_audit_log

UNION ALL

-- ── 4. PSM — RFQ Audit ──────────────────────────────────────────────────────
SELECT
    'PSM-RFQ-' || id::TEXT,
    'PSM',
    action_label,
    actor_name,
    'Admin',
    CASE
        WHEN action_label ILIKE '%creat%' OR action_label ILIKE '%issu%' THEN 'Create'
        WHEN action_label ILIKE '%approv%' OR action_label ILIKE '%award%' THEN 'Approve'
        ELSE 'Edit'
    END,
    'RFQ-' || rfq_id::TEXT,
    ip_address,
    FALSE,
    NULL,
    occurred_at
FROM psm_rfq_audit_log

UNION ALL

-- ── 5. PSM — Quotation Evaluation Audit ────────────────────────────────────
SELECT
    'PSM-QE-' || id::TEXT,
    'PSM',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN action_label ILIKE '%scor%' THEN 'Approve'
        WHEN action_label ILIKE '%winner%' OR action_label ILIKE '%endors%' THEN 'Approve'
        WHEN action_label ILIKE '%overrid%' THEN 'Edit'
        ELSE 'Edit'
    END,
    'EVAL-' || evaluation_id::TEXT,
    ip_address,
    is_super_admin,
    remarks,
    occurred_at
FROM psm_evaluation_audit_log

UNION ALL

-- ── 6. PSM — Purchase Order Audit ──────────────────────────────────────────
SELECT
    'PSM-PO-' || id::TEXT,
    'PSM',
    action_label,
    actor_name,
    'Admin',
    CASE
        WHEN action_label ILIKE '%creat%' OR action_label ILIKE '%draft%' THEN 'Create'
        WHEN action_label ILIKE '%approv%' OR action_label ILIKE '%confirm%' THEN 'Approve'
        WHEN action_label ILIKE '%cancel%' OR action_label ILIKE '%void%' THEN 'Delete'
        ELSE 'Edit'
    END,
    'PO-' || po_id::TEXT,
    ip_address,
    is_super_admin,
    NULL,
    occurred_at
FROM psm_po_audit_log

UNION ALL

-- ── 7. PSM — Contract Audit ─────────────────────────────────────────────────
SELECT
    'PSM-CNT-' || id::TEXT,
    'PSM',
    action_label,
    actor_name,
    'Admin',
    CASE
        WHEN action_label ILIKE '%creat%' THEN 'Create'
        WHEN action_label ILIKE '%approv%' THEN 'Approve'
        WHEN action_label ILIKE '%terminat%' OR action_label ILIKE '%archiv%' THEN 'Delete'
        ELSE 'Edit'
    END,
    'CNT-' || contract_id::TEXT,
    ip_address,
    FALSE,
    NULL,
    occurred_at
FROM psm_contract_audit_log

UNION ALL

-- ── 8. PSM — Receipt Audit ──────────────────────────────────────────────────
SELECT
    'PSM-RCV-' || id::TEXT,
    'PSM',
    action_label,
    actor_name,
    'Admin',
    CASE
        WHEN action_label ILIKE '%receiv%' OR action_label ILIKE '%creat%' THEN 'Create'
        WHEN action_label ILIKE '%inspect%' OR action_label ILIKE '%approv%' THEN 'Approve'
        WHEN action_label ILIKE '%reject%' OR action_label ILIKE '%disput%' THEN 'Delete'
        ELSE 'Edit'
    END,
    'RCV-' || receipt_id::TEXT,
    ip_address,
    FALSE,
    NULL,
    occurred_at
FROM psm_receipt_audit_log

UNION ALL

-- ── 9. SWS — Inventory Audit ────────────────────────────────────────────────
SELECT
    'SWS-INV-' || id::TEXT,
    'SWS',
    COALESCE(detail, action),
    actor_name,
    'Admin',
    CASE
        WHEN action IN ('add','activate') THEN 'Create'
        WHEN action IN ('deactivate','transfer_out') THEN 'Delete'
        WHEN action = 'adjust' THEN 'Approve'
        ELSE 'Edit'
    END,
    'ITM-' || item_id::TEXT,
    ip_address,
    FALSE,
    CASE WHEN old_stock IS NOT NULL THEN 'Stock: ' || old_stock::TEXT || ' → ' || new_stock::TEXT ELSE NULL END,
    occurred_at
FROM sws_inventory_audit

UNION ALL

-- ── 10. SWS — Transaction Audit ─────────────────────────────────────────────
SELECT
    'SWS-TXN-' || id::TEXT,
    'SWS',
    action || CASE WHEN detail <> '' THEN ': ' || detail ELSE '' END,
    actor_name,
    'Admin',
    CASE
        WHEN action = 'created'    THEN 'Create'
        WHEN action = 'cancelled' OR action = 'voided' THEN 'Delete'
        WHEN action = 'overridden' THEN 'Approve'
        ELSE 'Edit'
    END,
    txn_id,
    ip_address,
    FALSE,
    NULL,
    occurred_at
FROM sws_txn_audit

UNION ALL

-- ── 11. SWS — Bin Audit ─────────────────────────────────────────────────────
SELECT
    'SWS-BIN-' || id::TEXT,
    'SWS',
    action || CASE WHEN detail <> '' THEN ': ' || detail ELSE '' END,
    actor_name,
    'Admin',
    CASE
        WHEN action = 'create'     THEN 'Create'
        WHEN action IN ('delete','deactivate') THEN 'Delete'
        ELSE 'Edit'
    END,
    'BIN-' || bin_id::TEXT,
    ip_address,
    FALSE,
    NULL,
    occurred_at
FROM sws_bin_audit

UNION ALL

-- ── 12. ALMS — Asset Audit ──────────────────────────────────────────────────
SELECT
    'ALMS-AST-' || id::TEXT,
    'ALMS',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ad-c' THEN 'Create'
        WHEN css_class = 'ad-x' THEN 'Delete'
        WHEN css_class IN ('ad-a','ad-d') THEN 'Approve'
        ELSE 'Edit'
    END,
    'AST-' || asset_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM alms_asset_audit_log

UNION ALL

-- ── 13. ALMS — Maintenance Audit ────────────────────────────────────────────
SELECT
    'ALMS-MNT-' || id::TEXT,
    'ALMS',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ad-c' THEN 'Create'
        WHEN css_class = 'ad-a' THEN 'Approve'
        ELSE 'Edit'
    END,
    'SCH-' || schedule_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM alms_maintenance_audit_log

UNION ALL

-- ── 14. ALMS — Repair Audit ─────────────────────────────────────────────────
SELECT
    'ALMS-RPR-' || id::TEXT,
    'ALMS',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ed-s' THEN 'Create'
        WHEN css_class IN ('ed-c','ed-x') THEN 'Approve'
        WHEN css_class = 'ed-e' THEN 'Edit'
        ELSE 'Edit'
    END,
    'RPR-' || log_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM alms_repair_audit_log

UNION ALL

-- ── 15. ALMS — Disposal Audit ───────────────────────────────────────────────
SELECT
    'ALMS-DSP-' || id::TEXT,
    'ALMS',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ad-s' THEN 'Create'
        WHEN css_class IN ('ad-a','ad-d') THEN 'Approve'
        WHEN css_class IN ('ad-r','ad-x') THEN 'Delete'
        ELSE 'Edit'
    END,
    'DSP-' || disposal_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM alms_disposal_audit_log

UNION ALL

-- ── 16. PLT — Project Audit ─────────────────────────────────────────────────
SELECT
    'PLT-PRJ-' || id::TEXT,
    'PLT',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN dot_class = 'dot-g' THEN 'Create'
        WHEN dot_class = 'dot-r' THEN 'Delete'
        WHEN dot_class = 'dot-o' THEN 'Approve'
        ELSE 'Edit'
    END,
    'PRJ-' || project_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM plt_audit_log

UNION ALL

-- ── 17. PLT — Delivery Audit ────────────────────────────────────────────────
SELECT
    'PLT-DLV-' || id::TEXT,
    'PLT',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ad-c' THEN 'Create'
        WHEN css_class = 'ad-a' THEN 'Approve'
        WHEN css_class = 'ad-r' THEN 'Delete'
        ELSE 'Edit'
    END,
    'DLV-' || delivery_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM plt_delivery_audit_log

UNION ALL

-- ── 18. PLT — Assignment Audit ──────────────────────────────────────────────
SELECT
    'PLT-ASN-' || id::TEXT,
    'PLT',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ad-c' THEN 'Create'
        WHEN css_class = 'ad-a' THEN 'Approve'
        WHEN css_class = 'ad-r' THEN 'Delete'
        ELSE 'Edit'
    END,
    'ASN-' || assignment_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM plt_assignment_audit_log

UNION ALL

-- ── 19. PLT — Milestone Audit ───────────────────────────────────────────────
SELECT
    'PLT-MST-' || id::TEXT,
    'PLT',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'ad-c' THEN 'Create'
        WHEN css_class = 'ad-a' THEN 'Approve'
        WHEN css_class IN ('ad-r','ad-x') THEN 'Delete'
        ELSE 'Edit'
    END,
    'MST-' || milestone_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM plt_milestone_audit_log

UNION ALL

-- ── 20. DTRS — Document Audit ───────────────────────────────────────────────
SELECT
    'DTRS-DOC-' || id::TEXT,
    'DTRS',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN css_class = 'dc-c' THEN 'Create'
        WHEN css_class = 'dc-a' THEN 'Approve'
        WHEN css_class IN ('dc-r','dc-x') THEN 'Delete'
        WHEN css_class = 'dc-t' THEN 'Edit'
        ELSE 'Edit'
    END,
    'DOC-' || doc_id::TEXT,
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM dtrs_audit_log

UNION ALL

-- ── 21. DTRS — Route Audit ──────────────────────────────────────────────────
SELECT
    'DTRS-RTE-' || id::TEXT,
    'DTRS',
    action_label,
    actor_name,
    actor_role,
    CASE
        WHEN dot_class = 'dot-g' THEN 'Create'
        WHEN dot_class = 'dot-r' THEN 'Delete'
        WHEN dot_class = 'dot-o' THEN 'Approve'
        ELSE 'Edit'
    END,
    'RTE-' || route_id::TEXT,
    ip_address,
    is_super_admin,
    NULL,
    occurred_at
FROM dtrs_route_audit

UNION ALL

-- ── 22. RPM — Role Audit ────────────────────────────────────────────────────
SELECT
    'RPM-ROL-' || id::TEXT,
    'System',
    action_label,
    actor_name,
    'Super Admin',
    CASE
        WHEN css_class = 'ad-c' THEN 'Create'
        WHEN css_class = 'ad-r' THEN 'Delete'
        WHEN css_class = 'ad-t' THEN 'Create'
        ELSE 'Edit'
    END,
    COALESCE('ROLE-' || role_id::TEXT, 'SYSTEM'),
    ip_address,
    is_super_admin,
    note,
    occurred_at
FROM role_audit_log;

-- ── VERIFY ───────────────────────────────────────────────────────────────────
-- Run this after creating the view to confirm it works:
-- SELECT module, COUNT(*) FROM v_audit_unified GROUP BY module ORDER BY module;