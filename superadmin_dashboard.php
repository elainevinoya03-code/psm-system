<?php
// ── BOOTSTRAP ─────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── HELPERS (mirrors disposal page pattern) ───────────────────────────────────
function dash_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function dash_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function dash_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * Supabase REST helper — identical signature to ad_sb() in the disposal page.
 * Returns decoded JSON array on success, throws RuntimeException on failure.
 */
function dash_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = [
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: return=representation',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res && $code >= 400) throw new RuntimeException('Supabase request failed');
    $data = json_decode($res, true);
    if ($code >= 400) throw new RuntimeException(is_array($data) ? ($data['message'] ?? $res) : $res);
    return is_array($data) ? $data : [];
}

/** Build a clean disposal DTO — same fields as ad_build() in the disposal page */
function dash_build_disposal(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'disposalId'    => $row['disposal_id']    ?? '',
        'assetId'       => $row['asset_id']        ?? '',
        'assetName'     => $row['asset_name']      ?? '',
        'assetDbId'     => (int)($row['asset_db_id'] ?? 0),
        'zone'          => $row['zone']            ?? '',
        'reason'        => $row['reason']          ?? '',
        'method'        => $row['method']          ?? '',
        'disposalDate'  => $row['disposal_date']   ?? '',
        'approvedBy'    => $row['approved_by']     ?? '',
        'disposalValue' => (float)($row['disposal_value'] ?? 0),
        'bookValue'     => (float)($row['book_value']     ?? 0),
        'status'        => $row['status']          ?? 'Pending Approval',
        'raRef'         => $row['ra_ref']          ?? '',
        'remarks'       => $row['remarks']         ?? '',
        'saRemarks'     => $row['sa_remarks']      ?? '',
        'isSa'          => (bool)($row['is_sa']    ?? false),
        'createdBy'     => $row['created_by']      ?? '',
        'createdAt'     => $row['created_at']      ?? '',
        'updatedAt'     => $row['updated_at']      ?? '',
    ];
}

// ── AJAX API ROUTER ───────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET dashboard summary (all stats in one shot) ─────────────────────
        if ($api === 'summary' && $method === 'GET') {
            // 1. Disposals — all statuses
            $disposals = dash_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_id,asset_name,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,is_sa,created_at',
                'order'  => 'created_at.desc',
            ]);

            // 2. Assets — active/assigned/maintenance
            $assets = dash_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,status,category,zone,current_value',
            ]);

            // 3. Maintenance schedules — overdue / due soon
            $maintenances = dash_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_name,type,freq,zone,next_due,status,tech',
                'status' => 'in.(Scheduled,In Progress,Overdue)',
                'order'  => 'next_due.asc',
                'limit'  => '50',
            ]);

            // 4. Repair logs — active
            $repairs = dash_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_name,zone,status,date_reported,repair_cost',
                'status' => 'in.(Reported,In Progress,Escalated)',
                'order'  => 'date_reported.desc',
                'limit'  => '50',
            ]);

            // 5. Notifications — unread ALMS
            $notifs = dash_sb('notifications', 'GET', [
                'select'   => 'id,notif_id,category,severity,title,status,created_at',
                'module'   => 'eq.ALMS',
                'status'   => 'eq.unread',
                'order'    => 'created_at.desc',
                'limit'    => '20',
            ]);

            // 6. Recent audit trail (ALMS assets + disposals combined)
            $auditAsset = dash_sb('alms_asset_audit_log', 'GET', [
                'select'     => 'id,action_label,actor_name,actor_role,css_class,icon,is_super_admin,occurred_at',
                'order'      => 'occurred_at.desc',
                'limit'      => '10',
            ]);
            $auditDisposal = dash_sb('alms_disposal_audit_log', 'GET', [
                'select'     => 'id,action_label,actor_name,actor_role,css_class,icon,is_super_admin,occurred_at',
                'order'      => 'occurred_at.desc',
                'limit'      => '10',
            ]);

            // ── Compute disposal KPIs (mirrors ad_build / compliance panel) ───
            $pend    = count(array_filter($disposals, fn($d) => $d['status'] === 'Pending Approval'));
            $appr    = count(array_filter($disposals, fn($d) => $d['status'] === 'Approved'));
            $comp    = count(array_filter($disposals, fn($d) => $d['status'] === 'Completed'));
            $rej     = count(array_filter($disposals, fn($d) => $d['status'] === 'Rejected'));
            $cancelled = count(array_filter($disposals, fn($d) => $d['status'] === 'Cancelled'));

            $completedRows = array_filter($disposals, fn($d) => $d['status'] === 'Completed');
            $totalRecovered = array_sum(array_column($completedRows, 'disposal_value'));
            $totalBook      = array_sum(array_column($completedRows, 'book_value'));
            $recoveryPct    = $totalBook > 0 ? round(($totalRecovered / $totalBook) * 100, 1) : 0;

            // Method breakdown
            $methods = ['Sold','Scrapped','Donated','Auctioned','Transferred'];
            $methodCounts = [];
            foreach ($methods as $m) {
                $methodCounts[$m] = count(array_filter($disposals, fn($d) => $d['method'] === $m));
            }

            // Zone breakdown for disposals
            $zoneGroups = [];
            foreach ($disposals as $d) {
                $z = $d['zone'] ?? 'Unknown';
                $zoneGroups[$z] = ($zoneGroups[$z] ?? 0) + 1;
            }
            arsort($zoneGroups);

            // ── Asset KPIs ────────────────────────────────────────────────────
            $assetActive  = count(array_filter($assets, fn($a) => $a['status'] === 'Active'));
            $assetAssigned= count(array_filter($assets, fn($a) => $a['status'] === 'Assigned'));
            $assetMaint   = count(array_filter($assets, fn($a) => $a['status'] === 'Under Maintenance'));
            $assetDisposed= count(array_filter($assets, fn($a) => $a['status'] === 'Disposed'));
            $totalAssets  = count($assets);
            $totalAssetValue = array_sum(array_column($assets, 'current_value'));

            // Availability rate
            $available = $assetActive + $assetAssigned;
            $availPct  = $totalAssets > 0 ? round(($available / $totalAssets) * 100, 1) : 0;

            // ── Maintenance KPIs ──────────────────────────────────────────────
            $today = date('Y-m-d');
            $soon  = date('Y-m-d', strtotime('+7 days'));
            $overdueMS   = count(array_filter($maintenances, fn($m) => $m['status'] === 'Overdue' || ($m['next_due'] < $today && !in_array($m['status'], ['Completed','Skipped']))));
            $dueSoonMS   = count(array_filter($maintenances, fn($m) => $m['next_due'] >= $today && $m['next_due'] <= $soon && $m['status'] === 'Scheduled'));
            $inProgressMS= count(array_filter($maintenances, fn($m) => $m['status'] === 'In Progress'));

            // ── Repair KPIs ───────────────────────────────────────────────────
            $escalated   = count(array_filter($repairs, fn($r) => $r['status'] === 'Escalated'));
            $totalRepairCost = array_sum(array_column($repairs, 'repair_cost'));

            dash_ok([
                'disposal' => [
                    'pending'        => $pend,
                    'approved'       => $appr,
                    'completed'      => $comp,
                    'rejected'       => $rej,
                    'cancelled'      => $cancelled,
                    'totalRecovered' => $totalRecovered,
                    'totalBook'      => $totalBook,
                    'recoveryPct'    => $recoveryPct,
                    'methodCounts'   => $methodCounts,
                    'zoneGroups'     => $zoneGroups,
                    'recent'         => array_slice(array_map('dash_build_disposal', $disposals), 0, 8),
                ],
                'asset' => [
                    'active'       => $assetActive,
                    'assigned'     => $assetAssigned,
                    'maintenance'  => $assetMaint,
                    'disposed'     => $assetDisposed,
                    'total'        => $totalAssets,
                    'totalValue'   => $totalAssetValue,
                    'availPct'     => $availPct,
                ],
                'maintenance' => [
                    'overdue'     => $overdueMS,
                    'dueSoon'     => $dueSoonMS,
                    'inProgress'  => $inProgressMS,
                    'upcoming'    => array_slice($maintenances, 0, 5),
                ],
                'repair' => [
                    'active'      => count($repairs),
                    'escalated'   => $escalated,
                    'totalCost'   => $totalRepairCost,
                    'recent'      => array_slice($repairs, 0, 5),
                ],
                'notifications' => array_slice($notifs, 0, 6),
                'auditTrail' => array_slice(
                    array_merge(
                        array_map(fn($a) => array_merge($a, ['source' => 'Asset']),     $auditAsset),
                        array_map(fn($a) => array_merge($a, ['source' => 'Disposal']),  $auditDisposal)
                    ),
                    0, 12
                ),
            ]);
        }

        // ── POST approve/reject/complete/cancel/saoverride (same as disposal page) ──
        if ($api === 'action' && $method === 'POST') {
            $b    = dash_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$id)   dash_err('Missing id', 400);
            if (!$type) dash_err('Missing type', 400);

            $rows = dash_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_name,status,method,disposal_value,asset_db_id',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) dash_err('Disposal not found', 404);
            $d = $rows[0];

            $patch      = ['updated_at' => $now];
            $auditLabel = '';
            $auditNote  = trim($b['remarks'] ?? '');
            $auditIcon  = 'bx-info-circle';
            $auditClass = 'ad-s';
            $isSA       = false;

            switch ($type) {
                case 'approve':
                    if ($d['status'] !== 'Pending Approval')
                        dash_err('Only Pending Approval records can be approved.', 400);
                    $patch['status']      = 'Approved';
                    $patch['approved_by'] = $actor;
                    $patch['is_sa']       = true;
                    $auditLabel = 'Approved by Super Admin (via Dashboard)';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ad-a';
                    $auditNote  = $auditNote ?: 'Approved from the enterprise dashboard.';
                    $isSA       = true;
                    break;

                case 'reject':
                    if ($d['status'] !== 'Pending Approval')
                        dash_err('Only Pending Approval records can be rejected.', 400);
                    $patch['status']     = 'Rejected';
                    $patch['sa_remarks'] = trim($b['remarks'] ?? '');
                    $auditLabel = 'Rejected by Super Admin (via Dashboard)';
                    $auditIcon  = 'bx-x-circle';
                    $auditClass = 'ad-r';
                    $auditNote  = $auditNote ?: 'Rejected from the enterprise dashboard.';
                    $isSA       = true;
                    break;

                case 'complete':
                    if ($d['status'] !== 'Approved')
                        dash_err('Only Approved records can be completed.', 400);
                    $patch['status'] = 'Completed';
                    // Mark linked asset as Disposed — same logic as disposal page
                    if (!empty($d['asset_db_id'])) {
                        try {
                            dash_sb('alms_assets', 'PATCH',
                                ['id' => 'eq.' . $d['asset_db_id']],
                                ['status' => 'Disposed', 'updated_at' => $now]
                            );
                        } catch (Throwable $e) {}
                    }
                    $auditLabel = 'Disposal Completed (via Dashboard)';
                    $auditIcon  = 'bx-check-double';
                    $auditClass = 'ad-d';
                    $auditNote  = $auditNote ?: 'Completed from the enterprise dashboard.';
                    break;

                case 'cancel':
                    if (in_array($d['status'], ['Completed', 'Cancelled'], true))
                        dash_err('Record is already ' . strtolower($d['status']) . '.', 400);
                    $patch['status']  = 'Cancelled';
                    $patch['remarks'] = trim($b['remarks'] ?? '') ?: 'Cancelled from dashboard.';
                    $auditLabel = 'Disposal Cancelled (via Dashboard)';
                    $auditIcon  = 'bx-minus-circle';
                    $auditClass = 'ad-x';
                    $auditNote  = $auditNote ?: 'Cancelled from the enterprise dashboard.';
                    break;

                case 'saoverride':
                    $newStatus = trim($b['newStatus'] ?? '');
                    $allowed   = ['Pending Approval','Approved','Completed','Cancelled','Rejected'];
                    if (!in_array($newStatus, $allowed, true)) dash_err('Invalid target status.', 400);
                    $patch['status']     = $newStatus;
                    $patch['sa_remarks'] = trim($b['remarks'] ?? '');
                    if ($newStatus === 'Approved') {
                        $patch['approved_by'] = $actor;
                        $patch['is_sa']       = true;
                    }
                    $auditLabel = 'Status Override → ' . $newStatus . ' (via Dashboard)';
                    $auditIcon  = 'bx-shield-quarter';
                    $auditClass = 'ad-o';
                    $auditNote  = $auditNote ?: 'Super Admin override from enterprise dashboard.';
                    $isSA       = true;
                    break;

                default:
                    dash_err('Unsupported action', 400);
            }

            dash_sb('alms_disposals', 'PATCH', ['id' => 'eq.' . $id], $patch);
            dash_sb('alms_disposal_audit_log', 'POST', [], [[
                'disposal_id'    => $id,
                'action_label'   => $auditLabel,
                'actor_name'     => $actor,
                'actor_role'     => $isSA ? 'Super Admin' : 'Admin',
                'note'           => $auditNote,
                'icon'           => $auditIcon,
                'css_class'      => $auditClass,
                'is_super_admin' => $isSA,
                'ip_address'     => $ip,
                'occurred_at'    => $now,
            ]]);

            // Return updated row (same as disposal page)
            $updated = dash_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_id,asset_name,asset_db_id,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,remarks,sa_remarks,is_sa,created_by,created_at,updated_at',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            dash_ok(dash_build_disposal($updated[0]));
        }

        // ── POST batch action (mirrors disposal page batch endpoint) ──────────
        if ($api === 'batch' && $method === 'POST') {
            $b    = dash_body();
            $ids  = array_map('intval', $b['ids']  ?? []);
            $type = trim($b['type'] ?? '');
            $now  = date('Y-m-d H:i:s');

            if (empty($ids)) dash_err('No IDs provided.', 400);
            if (!$type)      dash_err('Missing type.', 400);

            $updated   = 0;
            $auditNote = trim($b['remarks'] ?? '');

            foreach ($ids as $id) {
                $rows = dash_sb('alms_disposals', 'GET', [
                    'select' => 'id,status,asset_db_id',
                    'id'     => 'eq.' . $id, 'limit' => '1',
                ]);
                if (empty($rows)) continue;
                $d = $rows[0];

                $patch      = ['updated_at' => $now];
                $auditLabel = '';
                $auditIcon  = 'bx-check-double';
                $auditClass = 'ad-a';

                if ($type === 'batch-approve') {
                    if ($d['status'] !== 'Pending Approval') continue;
                    $patch['status']      = 'Approved';
                    $patch['approved_by'] = $actor;
                    $patch['is_sa']       = true;
                    $auditLabel = 'Bulk Approved via Dashboard';
                    $auditClass = 'ad-a';
                } elseif ($type === 'batch-complete') {
                    if ($d['status'] !== 'Approved') continue;
                    $patch['status'] = 'Completed';
                    $auditLabel = 'Bulk Completed via Dashboard';
                    $auditClass = 'ad-d';
                    if (!empty($d['asset_db_id'])) {
                        try { dash_sb('alms_assets', 'PATCH', ['id' => 'eq.' . $d['asset_db_id']], ['status' => 'Disposed', 'updated_at' => $now]); } catch(Throwable $e) {}
                    }
                } elseif ($type === 'batch-reject') {
                    if ($d['status'] !== 'Pending Approval') continue;
                    $patch['status'] = 'Rejected';
                    $auditLabel = 'Bulk Rejected via Dashboard';
                    $auditIcon  = 'bx-x-circle';
                    $auditClass = 'ad-r';
                } else continue;

                dash_sb('alms_disposals', 'PATCH', ['id' => 'eq.' . $id], $patch);
                dash_sb('alms_disposal_audit_log', 'POST', [], [[
                    'disposal_id'    => $id,
                    'action_label'   => $auditLabel,
                    'actor_name'     => $actor,
                    'actor_role'     => 'Super Admin',
                    'note'           => $auditNote,
                    'icon'           => $auditIcon,
                    'css_class'      => $auditClass,
                    'is_super_admin' => true,
                    'ip_address'     => $ip,
                    'occurred_at'    => $now,
                ]]);
                $updated++;
            }
            dash_ok(['updated' => $updated]);
        }

        dash_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        dash_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LOG1 Super Admin Dashboard</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
*,*::before,*::after{box-sizing:border-box;}
#mainContent,.sa-toasts,.action-modal{
  --s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary,#1A2E1C);--t2:var(--text-secondary,#5D6F62);--t3:#9EB0A2;
  --hbg:var(--hover-bg-light,#F0FAF0);--bg:var(--bg-color,#F4F7F4);
  --grn:var(--primary-color,#2E7D32);--gdk:var(--primary-dark,#1B5E20);
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.2);
  --rad:12px;--tr:all .18s cubic-bezier(.4,0,.2,1);
}
/* LAYOUT */
.sa-wrap{max-width:1520px;margin:0 auto;padding:0 0 5rem;}
/* PAGE HEADER */
.sa-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;animation:UP .4s both;}
.sa-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.sa-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.sa-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-approve{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}.btn-approve:hover{background:#BBF7D0;}
.btn-reject{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}.btn-reject:hover{background:#FCA5A5;}
.btn-complete{background:#CCFBF1;color:#115E59;border:1px solid #99F6E4;}.btn-complete:hover{background:#99F6E4;}
.btn-cancel-ad{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}.btn-cancel-ad:hover{background:#E5E7EB;}
.btn-override{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}.btn-override:hover{background:#FDE68A;}
.btn-sm{font-size:12px;padding:7px 14px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);}
.btn.ionly:hover{background:var(--hbg);color:var(--grn);}
.btn.ionly.btn-approve:hover{background:#DCFCE7;color:#166534;border-color:#BBF7D0;}
.btn.ionly.btn-reject:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.btn.ionly.btn-complete:hover{background:#CCFBF1;color:#115E59;border-color:#99F6E4;}
.btn.ionly.btn-override:hover{background:#FEF3C7;color:#92400E;border-color:#FCD34D;}
.btn:disabled{opacity:.4;pointer-events:none;}
/* SECTION DIVIDER */
.sec-div{display:flex;align-items:center;gap:10px;margin:28px 0 16px;animation:UP .4s both;}
.sec-div span{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);}
.sec-div::after{content:'';flex:1;height:1px;background:var(--bd);}
/* KPI BAR */
.kpi-bar{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:22px;animation:UP .4s .06s both;}
.kc{background:var(--s);border:1px solid var(--bd);border-radius:14px;padding:16px 18px;box-shadow:var(--shmd);position:relative;overflow:hidden;}
.kc::after{content:'';position:absolute;top:0;right:0;width:4px;height:100%;border-radius:0 14px 14px 0;}
.kc-g::after{background:var(--grn)}.kc-r::after{background:var(--red)}.kc-a::after{background:var(--amb)}.kc-b::after{background:var(--blu)}.kc-t::after{background:var(--tel)}.kc-p::after{background:var(--pur)}
.kc-lbl{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:6px;}
.kc-val{font-size:24px;font-weight:800;color:var(--t1);line-height:1;font-family:'DM Mono',monospace;}
.kc-val.sm{font-size:15px;}
.kc-sub{font-size:11px;color:var(--t2);margin-top:4px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
.kc-bar{height:4px;background:#E5E7EB;border-radius:2px;margin-top:8px;overflow:hidden;}
.kc-bar-inner{height:100%;border-radius:2px;transition:width .6s ease;}
/* GRID */
.sa-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;}
.sa-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:18px;}
.sa-grid-w{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:18px;}
.sa-full{margin-bottom:18px;}
/* CARDS */
.card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s both;}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--bd);background:var(--bg);}
.card-hd-l{display:flex;align-items:center;gap:10px;}
.card-hd-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)}.ic-d{background:#F3F4F6;color:#374151}
.ic-p{background:#F5F3FF;color:var(--pur)}
.card-hd-t{font-size:14px;font-weight:700;color:var(--t1);}
.card-hd-s{font-size:11.5px;color:var(--t2);margin-top:1px;}
.card-hd-r{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.card-body{padding:18px 20px;}
/* COMPLIANCE PANEL */
.comp-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.comp-item{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;}
.ci-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.ci-label i{font-size:13px;}
.comp-ra{display:flex;flex-direction:column;gap:7px;}
.comp-ra-row{display:flex;align-items:center;justify-content:space-between;font-size:12.5px;}
.cr-label{color:var(--t2);font-weight:500;}
.cr-val{font-family:'DM Mono',monospace;font-weight:700;color:var(--t1);}
.cr-tag{font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;}
.cr-ok{background:#DCFCE7;color:#166534}.cr-warn{background:#FEF3C7;color:#92400E}.cr-bad{background:#FEE2E2;color:#991B1B}
.method-bars{display:flex;flex-direction:column;gap:6px;}
.method-bar-row{display:flex;align-items:center;gap:8px;font-size:11.5px;}
.method-bar-label{min-width:75px;color:var(--t2);font-weight:500;}
.method-bar-track{flex:1;height:6px;background:var(--bd);border-radius:3px;overflow:hidden;}
.method-bar-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.method-bar-val{min-width:28px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--t1);}
.value-recovery{display:flex;flex-direction:column;gap:6px;}
.vr-row{display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px dashed rgba(46,125,50,.12);font-size:12px;}
.vr-row:last-child{border-bottom:none;}
.vr-label{color:var(--t2);font-weight:500;display:flex;align-items:center;gap:5px;}
.vr-label i{font-size:13px;color:var(--grn);}
.vr-val{font-family:'DM Mono',monospace;font-weight:700;color:var(--grn);}
/* DISPOSAL TABLE */
.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.sa-tbl{width:100%;min-width:800px;border-collapse:collapse;font-size:12.5px;}
.sa-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;}
.sa-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s;}
.sa-tbl tbody tr:last-child{border-bottom:none;}
.sa-tbl tbody tr:hover{background:var(--hbg);}
.sa-tbl tbody td{padding:10px 12px;vertical-align:middle;}
.sa-tbl tbody td:last-child{white-space:nowrap;}
/* ASSET SUMMARY CARDS */
.asset-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.asc{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;text-align:center;}
.asc-v{font-size:22px;font-weight:800;color:var(--t1);font-family:'DM Mono',monospace;}
.asc-l{font-size:11px;color:var(--t2);margin-top:2px;}
/* MAINTENANCE ROWS */
.maint-item{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--bd);gap:10px;}
.maint-item:last-child{border-bottom:none;}
.maint-item:first-child{padding-top:0;}
.maint-info{flex:1;min-width:0;}
.maint-name{font-size:13px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.maint-meta{font-size:11px;color:var(--t2);margin-top:2px;display:flex;gap:8px;flex-wrap:wrap;}
/* BADGES */
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-pending{background:#FEF3C7;color:#92400E}.b-approved{background:#DCFCE7;color:#166534}
.b-completed{background:#CCFBF1;color:#115E59}.b-cancelled{background:#F3F4F6;color:#374151}
.b-rejected{background:#FEE2E2;color:#991B1B}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;white-space:nowrap;}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
.c-grn{background:#DCFCE7;color:#166534}.c-amb{background:#FEF3C7;color:#92400E}
.c-red{background:#FEE2E2;color:#991B1B}.c-blu{background:#EFF6FF;color:#1D4ED8}
.c-tel{background:#CCFBF1;color:#0F766E}.c-gry{background:#F3F4F6;color:#374151}
.sa-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:3px 9px;}
/* METHOD PILL */
.method-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.mp-sold{background:#EFF6FF;color:#1D4ED8}.mp-scrapped{background:#F3F4F6;color:#374151}
.mp-donated{background:#F5F3FF;color:#6D28D9}.mp-auctioned{background:#FEF3C7;color:#92400E}
.mp-transferred{background:#CCFBF1;color:#115E59}
/* AUDIT */
.audit-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd);}
.audit-item:last-child{border-bottom:none;padding-bottom:0;}
.audit-item:first-child{padding-top:0;}
.audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}
.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}
.ad-x{background:#F3F4F6;color:#374151}.ad-d{background:#CCFBF1;color:#115E59}
.ad-o{background:#FEF3C7;color:#D97706}
.audit-body{flex:1;min-width:0;}
.audit-body .au{font-size:12.5px;font-weight:500;color:var(--t1);}
.audit-body .at{font-size:11px;color:#9EB0A2;margin-top:2px;font-family:'DM Mono',monospace;}
.audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
/* NOTIFICATION ITEMS */
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--bd);}
.notif-item:last-child{border-bottom:none;padding-bottom:0;}
.notif-ic{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.sev-Critical{background:#FEE2E2;color:#DC2626}
.sev-High{background:#FEF3C7;color:#D97706}
.sev-Medium{background:#EFF6FF;color:#2563EB}
.sev-Low{background:#F3F4F6;color:#6B7280}
.notif-title{font-size:12.5px;font-weight:600;color:var(--t1);}
.notif-meta{font-size:11px;color:var(--t2);margin-top:2px;}
/* ACTION MODAL */
.action-modal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;padding:20px;}
.action-modal.on{opacity:1;pointer-events:all;}
.am-box{background:var(--s);border-radius:16px;padding:28px 28px 24px;width:440px;max-width:92vw;box-shadow:var(--shlg);}
.am-icon{font-size:46px;margin-bottom:10px;line-height:1;}
.am-title{font-size:18px;font-weight:700;color:var(--t1);margin-bottom:6px;}
.am-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px;}
.am-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400E;}
.am-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.am-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.am-fg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2);}
.am-fg textarea,.am-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.am-fg textarea{resize:vertical;min-height:68px;}
.am-fg textarea:focus,.am-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.am-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.am-acts{display:flex;gap:10px;justify-content:flex-end;}
/* BULK BAR */
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:12px;margin-bottom:14px;flex-wrap:wrap;}
.bulk-bar.on{display:flex;}
.bulk-count{font-size:13px;font-weight:700;color:#166534;}
/* SKELETON LOADER */
.skel{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.06) 50%,var(--bg) 75%);background-size:200% 100%;animation:SKEL 1.4s infinite;border-radius:8px;}
@keyframes SKEL{0%{background-position:200% 0}100%{background-position:-200% 0}}
/* LOADING OVERLAY */
.loading-overlay{display:flex;align-items:center;justify-content:center;padding:40px;flex-direction:column;gap:10px;color:var(--t3);}
.loading-overlay i{font-size:32px;animation:SPIN .9s linear infinite;}
@keyframes SPIN{to{transform:rotate(360deg)}}
/* EMPTY */
.empty-state{padding:40px;text-align:center;color:var(--t3);}
.empty-state i{font-size:40px;display:block;margin-bottom:10px;color:#C8E6C9;}
/* TOASTS */
.sa-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}
.toast.out{animation:TOUT .3s ease forwards;}
/* ANIMATIONS */
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
/* RESPONSIVE */
@media(max-width:1280px){.kpi-bar{grid-template-columns:repeat(3,1fr)}.sa-grid-3{grid-template-columns:1fr 1fr}.comp-grid{grid-template-columns:1fr}}
@media(max-width:900px){.sa-grid,.sa-grid-w{grid-template-columns:1fr}.kpi-bar{grid-template-columns:1fr 1fr}.asset-stat-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="sa-wrap">

  <!-- PAGE HEADER -->
  <div class="sa-ph">
    <div>
      <p class="ey">Logistics 1 — Super Admin</p>
      <h1>Enterprise Dashboard</h1>
    </div>
    <div class="sa-ph-r">
      <span id="liveClock" style="font-family:'DM Mono',monospace;font-size:12px;color:var(--t3);"></span>
      <button class="btn btn-ghost btn-sm" onclick="loadAll()"><i class="bx bx-refresh"></i> Refresh</button>
    </div>
  </div>

  <!-- KPI BAR (live from Supabase) -->
  <div class="kpi-bar" id="kpiBar">
    <?php for($i=0;$i<6;$i++): ?>
    <div class="kc kc-g"><div class="skel" style="height:14px;width:60%;margin-bottom:10px;"></div><div class="skel" style="height:28px;width:40%;margin-bottom:6px;"></div><div class="skel" style="height:10px;width:80%;"></div></div>
    <?php endfor; ?>
  </div>

  <!-- RA 9184 COMPLIANCE + VALUE RECOVERY PANEL -->
  <div class="sec-div"><span>ALMS · Disposal Compliance &amp; Value Recovery</span><span class="sa-badge" style="margin-left:6px;"><i class="bx bx-shield-quarter"></i> Super Admin</span></div>
  <div class="card sa-full" id="complianceCard">
    <div class="card-hd">
      <div class="card-hd-l">
        <div class="card-hd-ic ic-a"><i class="bx bx-shield-alt-2"></i></div>
        <div>
          <div class="card-hd-t">RA 9184 Compliance &amp; Value Recovery Tracking</div>
          <div class="card-hd-s">Live from <code>alms_disposals</code> — mirrors disposal module analytics</div>
        </div>
      </div>
      <div class="card-hd-r" id="complianceChips"></div>
    </div>
    <div class="card-body">
      <div class="comp-grid" id="complianceGrid">
        <div class="loading-overlay" style="grid-column:1/-1"><i class="bx bx-loader-alt"></i><span>Loading disposal data…</span></div>
      </div>
    </div>
  </div>

  <!-- DISPOSAL RECORDS TABLE + AUDIT TRAIL -->
  <div class="sec-div"><span>Asset Disposals — Pending Actions</span></div>
  <div class="sa-grid-w">
    <!-- DISPOSAL RECORDS TABLE -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-r"><i class="bx bx-trash"></i></div>
          <div>
            <div class="card-hd-t">Disposal Records</div>
            <div class="card-hd-s">Recent 8 — actionable from this dashboard</div>
          </div>
        </div>
        <div class="card-hd-r">
          <a href="<?= LOG1_WEB_BASE ?>/alms/disposal.php" class="btn btn-ghost btn-sm"><i class="bx bx-link-external"></i> Full Module</a>
        </div>
      </div>

      <!-- BULK BAR -->
      <div class="bulk-bar" id="bulkBar" style="margin:0 16px 0;">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <div style="width:1px;height:22px;background:rgba(46,125,50,.25);"></div>
        <button class="btn btn-approve btn-sm" id="batchApproveBtn"><i class="bx bx-check-double"></i> Bulk Approve</button>
        <button class="btn btn-complete btn-sm" id="batchCompleteBtn"><i class="bx bx-check-circle"></i> Bulk Complete</button>
        <button class="btn btn-reject btn-sm" id="batchRejectBtn"><i class="bx bx-x"></i> Bulk Reject</button>
        <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
        <span class="sa-badge" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> SA Only</span>
      </div>

      <div class="tbl-scroll">
        <table class="sa-tbl">
          <thead>
            <tr>
              <th style="width:38px;"><input type="checkbox" id="checkAll" style="width:14px;height:14px;accent-color:var(--grn);cursor:pointer;"></th>
              <th>Disposal ID</th>
              <th>Asset / Zone</th>
              <th>Method</th>
              <th>Date</th>
              <th>Value</th>
              <th>Status</th>
              <th class="no-sort">Actions</th>
            </tr>
          </thead>
          <tbody id="disposalTbody">
            <tr><td colspan="8"><div class="loading-overlay"><i class="bx bx-loader-alt"></i><span>Loading…</span></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- AUDIT TRAIL SIDEBAR -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-b"><i class="bx bx-history"></i></div>
          <div>
            <div class="card-hd-t">ALMS Audit Trail</div>
            <div class="card-hd-s">Live asset &amp; disposal events</div>
          </div>
        </div>
      </div>
      <div class="card-body" id="auditTrail">
        <div class="loading-overlay"><i class="bx bx-loader-alt"></i><span>Loading…</span></div>
      </div>
    </div>
  </div>

  <!-- ASSET STATS + MAINTENANCE + REPAIRS -->
  <div class="sec-div"><span>Asset &amp; Maintenance Overview</span></div>
  <div class="sa-grid-3">
    <!-- Asset Summary -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-g"><i class="bx bx-cube-alt"></i></div>
          <div>
            <div class="card-hd-t">Asset Registry</div>
            <div class="card-hd-s">Status breakdown</div>
          </div>
        </div>
      </div>
      <div class="card-body">
        <div class="asset-stat-grid" id="assetStatGrid">
          <div class="asc"><div class="skel" style="height:24px;width:50%;margin:0 auto 6px;"></div><div class="skel" style="height:12px;width:70%;margin:0 auto;"></div></div>
          <div class="asc"><div class="skel" style="height:24px;width:50%;margin:0 auto 6px;"></div><div class="skel" style="height:12px;width:70%;margin:0 auto;"></div></div>
          <div class="asc"><div class="skel" style="height:24px;width:50%;margin:0 auto 6px;"></div><div class="skel" style="height:12px;width:70%;margin:0 auto;"></div></div>
          <div class="asc"><div class="skel" style="height:24px;width:50%;margin:0 auto 6px;"></div><div class="skel" style="height:12px;width:70%;margin:0 auto;"></div></div>
        </div>
        <div id="assetAvailBar"></div>
      </div>
    </div>

    <!-- Maintenance Schedules -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-a"><i class="bx bx-wrench"></i></div>
          <div>
            <div class="card-hd-t">Maintenance</div>
            <div class="card-hd-s">Upcoming &amp; overdue</div>
          </div>
        </div>
        <div id="maintChips" class="card-hd-r"></div>
      </div>
      <div class="card-body" id="maintList">
        <div class="loading-overlay"><i class="bx bx-loader-alt"></i></div>
      </div>
    </div>

    <!-- Repairs -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-r"><i class="bx bx-tool"></i></div>
          <div>
            <div class="card-hd-t">Active Repairs</div>
            <div class="card-hd-s">Open repair &amp; service logs</div>
          </div>
        </div>
        <div id="repairChips" class="card-hd-r"></div>
      </div>
      <div class="card-body" id="repairList">
        <div class="loading-overlay"><i class="bx bx-loader-alt"></i></div>
      </div>
    </div>
  </div>

  <!-- NOTIFICATIONS -->
  <div class="sec-div"><span>ALMS Notifications</span></div>
  <div class="sa-grid">
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-r"><i class="bx bx-bell"></i></div>
          <div>
            <div class="card-hd-t">Unread ALMS Alerts</div>
            <div class="card-hd-s">From <code>notifications</code> table</div>
          </div>
        </div>
        <div id="notifCount" class="card-hd-r"></div>
      </div>
      <div class="card-body" id="notifList">
        <div class="loading-overlay"><i class="bx bx-loader-alt"></i></div>
      </div>
    </div>

    <!-- Zone Breakdown for Disposals -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-t"><i class="bx bx-map-alt"></i></div>
          <div>
            <div class="card-hd-t">Disposals by Zone</div>
            <div class="card-hd-s">Live distribution from <code>alms_disposals</code></div>
          </div>
        </div>
      </div>
      <div class="card-body" id="zoneBreakdown">
        <div class="loading-overlay"><i class="bx bx-loader-alt"></i></div>
      </div>
    </div>
  </div>

</div><!-- .sa-wrap -->
</main>

<div class="sa-toasts" id="toastWrap"></div>

<!-- ACTION MODAL -->
<div class="action-modal" id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body"  id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="amSaText"></span></div>
    <div id="amDynamic"></div>
    <div class="am-fg">
      <label>Remarks / Notes (optional)</label>
      <textarea id="amRemarks" placeholder="Add remarks for this action…"></textarea>
    </div>
    <div class="am-acts">
      <button class="btn btn-ghost btn-sm" id="amCancel">Cancel</button>
      <button class="btn btn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── API helpers (same pattern as disposal page) ────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p,{method:'POST',body:JSON.stringify(b)});

// ── STATE ──────────────────────────────────────────────────────────────────────
let SUMMARY = null;
let selectedIds = new Set();
let actionCb = null;

// ── HELPERS ────────────────────────────────────────────────────────────────────
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fM   = n => '₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const fMK  = n => n>=1000000?'₱'+(n/1000000).toFixed(1)+'M':n>=1000?'₱'+(n/1000).toFixed(0)+'K':fM(n);
const fD   = d => { if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const fDT  = d => { if(!d) return '—'; return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); };

function badge(s){
    const m={'Pending Approval':'b-pending','Approved':'b-approved','Completed':'b-completed','Cancelled':'b-cancelled','Rejected':'b-rejected'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}
function methodPill(m){
    const cls={Sold:'mp-sold',Scrapped:'mp-scrapped',Donated:'mp-donated',Auctioned:'mp-auctioned',Transferred:'mp-transferred'};
    const icon={Sold:'bx-dollar',Scrapped:'bx-trash',Donated:'bx-gift',Auctioned:'bx-gavel',Transferred:'bx-transfer'};
    return `<span class="method-pill ${cls[m]||''}"><i class="bx ${icon[m]||'bx-box'}"></i>${esc(m)}</span>`;
}
function sevIcons(s){
    return {Critical:'bx-error',High:'bx-error-circle',Medium:'bx-info-circle',Low:'bx-bell'}[s]||'bx-bell';
}

// ── CLOCK ──────────────────────────────────────────────────────────────────────
function tick(){ document.getElementById('liveClock').textContent = new Date().toLocaleString('en-PH',{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
setInterval(tick,1000); tick();

// ── LOAD ALL DATA ──────────────────────────────────────────────────────────────
async function loadAll(){
    try {
        SUMMARY = await apiGet(API + '?api=summary');
        renderKpis();
        renderCompliance();
        renderDisposalTable();
        renderAuditTrail();
        renderAssetStats();
        renderMaintenance();
        renderRepairs();
        renderNotifications();
        renderZoneBreakdown();
    } catch(e) {
        toast('Failed to load dashboard: ' + e.message, 'd');
    }
}

// ── KPI BAR ────────────────────────────────────────────────────────────────────
function renderKpis(){
    const d = SUMMARY.disposal;
    const a = SUMMARY.asset;
    const m = SUMMARY.maintenance;
    const r = SUMMARY.repair;

    document.getElementById('kpiBar').innerHTML = `
        <div class="kc kc-r">
            <div class="kc-lbl">Pending Approvals</div>
            <div class="kc-val">${d.pending}</div>
            <div class="kc-sub"><span class="chip c-amb">${d.approved} Approved</span></div>
            <div class="kc-bar"><div class="kc-bar-inner" style="width:${Math.min(100,d.pending*10)}%;background:var(--red)"></div></div>
        </div>
        <div class="kc kc-t">
            <div class="kc-lbl">Completed Disposals</div>
            <div class="kc-val">${d.completed}</div>
            <div class="kc-sub"><span class="chip c-gry">${d.cancelled} Cancelled</span></div>
        </div>
        <div class="kc kc-g">
            <div class="kc-lbl">Recovery Rate</div>
            <div class="kc-val">${d.recoveryPct}<span style="font-size:15px;color:var(--t2)">%</span></div>
            <div class="kc-sub"><i class="bx bx-money-withdraw"></i> ${fMK(d.totalRecovered)} recovered</div>
            <div class="kc-bar"><div class="kc-bar-inner" style="width:${Math.min(100,d.recoveryPct)}%;background:var(--grn)"></div></div>
        </div>
        <div class="kc kc-b">
            <div class="kc-lbl">Asset Availability</div>
            <div class="kc-val">${a.availPct}<span style="font-size:15px;color:var(--t2)">%</span></div>
            <div class="kc-sub">${a.total} total assets · ${fMK(a.totalValue)} value</div>
            <div class="kc-bar"><div class="kc-bar-inner" style="width:${Math.min(100,a.availPct)}%;background:var(--blu)"></div></div>
        </div>
        <div class="kc kc-a">
            <div class="kc-lbl">Maintenance Overdue</div>
            <div class="kc-val">${m.overdue}</div>
            <div class="kc-sub"><span class="chip c-amb">${m.dueSoon} due soon</span></div>
        </div>
        <div class="kc kc-p">
            <div class="kc-lbl">Active Repairs</div>
            <div class="kc-val">${r.active}</div>
            <div class="kc-sub"><span class="chip c-red">${r.escalated} escalated</span></div>
        </div>`;
}

// ── COMPLIANCE PANEL (mirrors disposal page renderCompliance) ──────────────────
function renderCompliance(){
    const d = SUMMARY.disposal;
    const METHODS = ['Sold','Scrapped','Donated','Auctioned','Transferred'];
    const METHOD_BAR = {Sold:'#2563EB',Scrapped:'#6B7280',Donated:'#7C3AED',Auctioned:'#D97706',Transferred:'#0D9488'};
    const mCounts = METHODS.map(m => ({label:m, val:d.methodCounts[m]||0}));
    const maxM = Math.max(...mCounts.map(x=>x.val), 1);
    const raTotal = d.pending + d.approved + d.completed + d.rejected;

    document.getElementById('complianceChips').innerHTML = `
        <span class="chip c-red">${d.pending} Pending</span>
        <span class="chip c-grn">${d.completed} Completed</span>`;

    document.getElementById('complianceGrid').innerHTML = `
        <div class="comp-item">
            <div class="ci-label"><i class="bx bx-shield-alt-2" style="color:var(--grn)"></i> RA 9184 Compliance Status</div>
            <div class="comp-ra">
                <div class="comp-ra-row"><span class="cr-label">Total Records (excl. cancelled)</span><span class="cr-val">${raTotal}</span></div>
                <div class="comp-ra-row"><span class="cr-label">Completed Disposals</span><span class="cr-val">${d.completed}</span></div>
                <div class="comp-ra-row"><span class="cr-label">Pending Approval</span><span class="cr-val">${d.pending}</span></div>
                <div class="comp-ra-row"><span class="cr-label">Approved Awaiting Completion</span><span class="cr-val">${d.approved}</span></div>
                <div class="comp-ra-row"><span class="cr-label">Rejected Records</span>
                    <span class="cr-val cr-tag ${d.rejected>0?'cr-warn':'cr-ok'}">${d.rejected}</span>
                </div>
            </div>
        </div>
        <div class="comp-item">
            <div class="ci-label"><i class="bx bx-bar-chart-alt-2" style="color:var(--blu)"></i> Disposals by Method</div>
            <div class="method-bars">
                ${mCounts.map(m => `
                    <div class="method-bar-row">
                        <div class="method-bar-label">${m.label}</div>
                        <div class="method-bar-track"><div class="method-bar-fill" style="width:${Math.round(m.val/maxM*100)}%;background:${METHOD_BAR[m.label]}"></div></div>
                        <div class="method-bar-val">${m.val}</div>
                    </div>`).join('')}
            </div>
        </div>
        <div class="comp-item">
            <div class="ci-label"><i class="bx bx-money" style="color:var(--tel)"></i> Value Recovery Tracking</div>
            <div class="value-recovery">
                <div class="vr-row"><span class="vr-label"><i class="bx bx-book-open"></i> Total Book Value</span><span class="vr-val">${fM(d.totalBook)}</span></div>
                <div class="vr-row"><span class="vr-label"><i class="bx bx-money-withdraw"></i> Total Recovered</span><span class="vr-val">${fM(d.totalRecovered)}</span></div>
                <div class="vr-row"><span class="vr-label"><i class="bx bx-trending-up"></i> Recovery Rate</span>
                    <span class="vr-val" style="color:${d.recoveryPct>=30?'#166534':'#D97706'}">${d.recoveryPct}%</span>
                </div>
                <div class="vr-row"><span class="vr-label"><i class="bx bx-x-circle" style="color:var(--t2)"></i> Rejected</span><span class="vr-val" style="color:var(--red)">${d.rejected}</span></div>
                <div class="vr-row"><span class="vr-label"><i class="bx bx-minus-circle" style="color:var(--t2)"></i> Cancelled</span><span class="vr-val" style="color:var(--t2)">${d.cancelled}</span></div>
            </div>
        </div>`;
}

// ── DISPOSAL TABLE ─────────────────────────────────────────────────────────────
function renderDisposalTable(){
    const rows = SUMMARY.disposal.recent;
    if (!rows.length) {
        document.getElementById('disposalTbody').innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="bx bx-trash"></i><p>No disposal records found.</p></div></td></tr>`;
        return;
    }
    document.getElementById('disposalTbody').innerHTML = rows.map(d => {
        const isPending  = d.status === 'Pending Approval';
        const isApproved = d.status === 'Approved';
        const isComp     = d.status === 'Completed';
        const isCancelled= d.status === 'Cancelled';
        const chk = selectedIds.has(d.disposalId);
        return `<tr class="${chk?'row-selected':''}">
            <td style="padding-left:14px"><input type="checkbox" class="row-cb" data-id="${esc(d.disposalId)}" data-dbid="${d.id}" style="width:14px;height:14px;accent-color:var(--grn);cursor:pointer;" ${chk?'checked':''}></td>
            <td><span style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);">${esc(d.disposalId)}</span></td>
            <td>
                <div style="font-weight:600;font-size:13px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;">${esc(d.assetName)}</div>
                <div style="font-size:11px;color:var(--t2);">${esc(d.zone)}</div>
            </td>
            <td>${methodPill(d.method)}</td>
            <td style="font-size:12px;color:var(--t2);white-space:nowrap;">${fD(d.disposalDate)}</td>
            <td style="font-family:'DM Mono',monospace;font-size:12.5px;font-weight:700;white-space:nowrap;">${d.disposalValue>0?fM(d.disposalValue):'—'}</td>
            <td>${badge(d.status)}</td>
            <td>
                <div style="display:flex;gap:4px;align-items:center;">
                    ${isPending?`<button class="btn ionly btn-approve" onclick="doAction('approve',${d.id},'${esc(d.disposalId)}','${esc(d.assetName)}')" title="Approve"><i class="bx bx-check"></i></button>`:''}
                    ${isPending?`<button class="btn ionly btn-reject" onclick="doAction('reject',${d.id},'${esc(d.disposalId)}','${esc(d.assetName)}')" title="Reject"><i class="bx bx-x"></i></button>`:''}
                    ${isApproved?`<button class="btn ionly btn-complete" onclick="doAction('complete',${d.id},'${esc(d.disposalId)}','${esc(d.assetName)}')" title="Complete"><i class="bx bx-check-double"></i></button>`:''}
                    ${!isCancelled&&!isComp?`<button class="btn ionly btn-cancel-ad" onclick="doAction('cancel',${d.id},'${esc(d.disposalId)}','${esc(d.assetName)}')" title="Cancel"><i class="bx bx-minus-circle"></i></button>`:''}
                    <button class="btn ionly btn-override" onclick="doAction('saoverride',${d.id},'${esc(d.disposalId)}','${esc(d.assetName)}')" title="SA Override"><i class="bx bx-shield-quarter"></i></button>
                </div>
            </td>
        </tr>`;
    }).join('');

    // Checkbox listeners
    document.querySelectorAll('.row-cb').forEach(cb => {
        cb.addEventListener('change', function(){
            const did = this.dataset.id;
            if (this.checked) selectedIds.add(did); else selectedIds.delete(did);
            this.closest('tr').style.background = this.checked ? '#F0FDF4' : '';
            updateBulkBar();
            syncCheckAll();
        });
    });
    syncCheckAll();
}

// ── CHECKALL ───────────────────────────────────────────────────────────────────
function syncCheckAll(){
    const ca = document.getElementById('checkAll');
    const all = SUMMARY.disposal.recent.map(d=>d.disposalId);
    ca.checked = all.length>0 && all.every(id=>selectedIds.has(id));
    ca.indeterminate = !ca.checked && all.some(id=>selectedIds.has(id));
}
document.getElementById('checkAll').addEventListener('change', function(){
    const rows = SUMMARY?.disposal?.recent || [];
    rows.forEach(d => { if(this.checked) selectedIds.add(d.disposalId); else selectedIds.delete(d.disposalId); });
    renderDisposalTable(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click', ()=>{ selectedIds.clear(); renderDisposalTable(); updateBulkBar(); });

function updateBulkBar(){
    const n = selectedIds.size;
    document.getElementById('bulkBar').classList.toggle('on', n>0);
    document.getElementById('bulkCount').textContent = n===1?'1 selected':`${n} selected`;
}

// ── AUDIT TRAIL ────────────────────────────────────────────────────────────────
function renderAuditTrail(){
    const rows = SUMMARY.auditTrail;
    if (!rows.length) {
        document.getElementById('auditTrail').innerHTML = `<div class="empty-state"><i class="bx bx-history"></i><p>No audit entries.</p></div>`;
        return;
    }
    // Sort by occurred_at desc
    rows.sort((a,b) => new Date(b.occurred_at) - new Date(a.occurred_at));
    document.getElementById('auditTrail').innerHTML = rows.slice(0,10).map(a => `
        <div class="audit-item">
            <div class="audit-dot ${a.css_class||'ad-s'}"><i class="bx ${a.icon||'bx-info-circle'}"></i></div>
            <div class="audit-body">
                <div class="au">${esc(a.action_label)} ${a.is_super_admin?'<span class="sa-tag">SA</span>':''}</div>
                <div class="at"><i class="bx bx-user" style="font-size:10px;margin-right:3px"></i>${esc(a.actor_name)} · <span style="background:#F3F4F6;padding:1px 5px;border-radius:4px;font-size:10px;">${esc(a.source||'')}</span></div>
            </div>
            <div class="audit-ts">${fDT(a.occurred_at)}</div>
        </div>`).join('');
}

// ── ASSET STATS ────────────────────────────────────────────────────────────────
function renderAssetStats(){
    const a = SUMMARY.asset;
    document.getElementById('assetStatGrid').innerHTML = `
        <div class="asc"><div class="asc-v" style="color:#166534">${a.active}</div><div class="asc-l">Active</div></div>
        <div class="asc"><div class="asc-v" style="color:#2563EB">${a.assigned}</div><div class="asc-l">Assigned</div></div>
        <div class="asc"><div class="asc-v" style="color:#D97706">${a.maintenance}</div><div class="asc-l">In Maintenance</div></div>
        <div class="asc"><div class="asc-v" style="color:#6B7280">${a.disposed}</div><div class="asc-l">Disposed</div></div>`;
    const pct = a.availPct;
    document.getElementById('assetAvailBar').innerHTML = `
        <div style="margin-top:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                <span style="font-size:11.5px;color:var(--t2);">Asset Availability Rate</span>
                <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:${pct>=80?'#166534':pct>=60?'#D97706':'#DC2626'}">${pct}%</span>
            </div>
            <div style="height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden;">
                <div style="height:100%;width:${pct}%;background:${pct>=80?'var(--grn)':pct>=60?'var(--amb)':'var(--red)'};border-radius:4px;transition:width .6s ease;"></div>
            </div>
            <div style="font-size:11px;color:var(--t3);margin-top:4px;">Total asset value: <strong style="color:var(--t2);">${fMK(a.totalValue)}</strong></div>
        </div>`;
}

// ── MAINTENANCE ────────────────────────────────────────────────────────────────
function renderMaintenance(){
    const m = SUMMARY.maintenance;
    document.getElementById('maintChips').innerHTML = `
        ${m.overdue>0?`<span class="chip c-red">${m.overdue} Overdue</span>`:''}
        ${m.dueSoon>0?`<span class="chip c-amb">${m.dueSoon} Due Soon</span>`:''}`;
    if (!m.upcoming.length) {
        document.getElementById('maintList').innerHTML = `<div class="empty-state"><i class="bx bx-wrench"></i><p>No upcoming maintenance.</p></div>`;
        return;
    }
    document.getElementById('maintList').innerHTML = m.upcoming.map(s => {
        const today = new Date().toISOString().split('T')[0];
        const overdue = s.next_due < today && !['Completed','Skipped'].includes(s.status);
        return `<div class="maint-item">
            <div class="maint-info">
                <div class="maint-name">${esc(s.asset_name)}</div>
                <div class="maint-meta">
                    <span style="font-family:'DM Mono',monospace;">${esc(s.schedule_id)}</span>
                    <span>${esc(s.type)} · ${esc(s.freq)}</span>
                    ${s.zone?`<span>${esc(s.zone)}</span>`:''}
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                <span style="font-size:11.5px;font-weight:700;color:${overdue?'var(--red)':'var(--t2)'};">${fD(s.next_due)}</span>
                <span class="chip ${overdue?'c-red':s.status==='In Progress'?'c-blu':'c-amb'}">${overdue?'Overdue':s.status}</span>
            </div>
        </div>`;
    }).join('');
}

// ── REPAIRS ────────────────────────────────────────────────────────────────────
function renderRepairs(){
    const r = SUMMARY.repair;
    document.getElementById('repairChips').innerHTML = `
        ${r.escalated>0?`<span class="chip c-red">${r.escalated} Escalated</span>`:''}
        <span class="chip c-gry">${fMK(r.totalCost)} Cost</span>`;
    if (!r.recent.length) {
        document.getElementById('repairList').innerHTML = `<div class="empty-state"><i class="bx bx-tool"></i><p>No active repairs.</p></div>`;
        return;
    }
    document.getElementById('repairList').innerHTML = r.recent.map(rep => `
        <div class="maint-item">
            <div class="maint-info">
                <div class="maint-name">${esc(rep.asset_name)}</div>
                <div class="maint-meta">
                    <span style="font-family:'DM Mono',monospace;">${esc(rep.log_id)}</span>
                    <span>${esc(rep.zone)}</span>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                <span class="chip ${rep.status==='Escalated'?'c-red':rep.status==='In Progress'?'c-blu':'c-amb'}">${esc(rep.status)}</span>
                ${rep.repair_cost>0?`<span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--t1);">${fMK(rep.repair_cost)}</span>`:''}
            </div>
        </div>`).join('');
}

// ── NOTIFICATIONS ──────────────────────────────────────────────────────────────
function renderNotifications(){
    const notifs = SUMMARY.notifications;
    document.getElementById('notifCount').innerHTML = notifs.length>0
        ? `<span class="chip c-red">${notifs.length} Unread</span>` : '';
    if (!notifs.length) {
        document.getElementById('notifList').innerHTML = `<div class="empty-state"><i class="bx bx-check-circle" style="color:var(--grn)"></i><p>No unread alerts.</p></div>`;
        return;
    }
    document.getElementById('notifList').innerHTML = notifs.map(n => `
        <div class="notif-item">
            <div class="notif-ic sev-${esc(n.severity)}"><i class="bx ${sevIcons(n.severity)}"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="notif-title">${esc(n.title)}</div>
                <div class="notif-meta">${esc(n.category)} · ${fDT(n.created_at)}</div>
            </div>
            <span class="chip ${n.severity==='Critical'?'c-red':n.severity==='High'?'c-amb':n.severity==='Medium'?'c-blu':'c-gry'}">${esc(n.severity)}</span>
        </div>`).join('');
}

// ── ZONE BREAKDOWN ─────────────────────────────────────────────────────────────
function renderZoneBreakdown(){
    const zones = SUMMARY.disposal.zoneGroups;
    const entries = Object.entries(zones);
    if (!entries.length) {
        document.getElementById('zoneBreakdown').innerHTML = `<div class="empty-state"><i class="bx bx-map-alt"></i><p>No zone data.</p></div>`;
        return;
    }
    const maxVal = Math.max(...entries.map(([,v])=>v), 1);
    const colors = ['#2E7D32','#2563EB','#0D9488','#D97706','#7C3AED','#DC2626','#6B7280'];
    document.getElementById('zoneBreakdown').innerHTML = entries.map(([zone,count],i) => `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <div style="min-width:120px;font-size:12px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(zone)}</div>
            <div style="flex:1;height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden;">
                <div style="height:100%;width:${Math.round(count/maxVal*100)}%;background:${colors[i%colors.length]};border-radius:4px;transition:width .5s ease;"></div>
            </div>
            <div style="min-width:24px;text-align:right;font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--t1);">${count}</div>
        </div>`).join('');
}

// ── ACTION MODAL (same logic as disposal page doAction / amConfirm) ────────────
function doAction(type, dbId, disposalId, assetName){
    const cfg = {
        approve:    {icon:'✅',title:'Approve Disposal',    sa:true, saText:'You are exercising Super Admin authority.', btn:'btn-approve', label:'<i class="bx bx-check"></i> Approve',       extra:''},
        reject:     {icon:'❌',title:'Reject Disposal',     sa:true, saText:'This disposal will be returned for revision.',btn:'btn-reject',  label:'<i class="bx bx-x"></i> Reject',           extra:''},
        complete:   {icon:'🏁',title:'Complete Disposal',   sa:false,saText:'', btn:'btn-complete', label:'<i class="bx bx-check-double"></i> Complete', extra:''},
        cancel:     {icon:'⛔',title:'Cancel Disposal',     sa:false,saText:'', btn:'btn-cancel-ad',label:'<i class="bx bx-minus-circle"></i> Cancel',   extra:''},
        saoverride: {icon:'🛡️',title:'SA Status Override',  sa:true, saText:'Super Admin authority to override status.',
            btn:'btn-override',label:'<i class="bx bx-shield-quarter"></i> Apply Override',
            extra:`<div class="am-fg"><label>Override to Status <span style="color:var(--red)">*</span></label>
                   <select id="amNewStatus"><option value="">Select…</option><option>Pending Approval</option><option>Approved</option><option>Completed</option><option>Cancelled</option><option>Rejected</option></select></div>`},
    };
    const c = cfg[type]; if(!c) return;
    document.getElementById('amIcon').textContent = c.icon;
    document.getElementById('amTitle').textContent = c.title;
    document.getElementById('amBody').innerHTML = `Disposal <strong>${esc(disposalId)}</strong> — <strong>${esc(assetName)}</strong>`;
    const san = document.getElementById('amSaNote');
    if(c.sa){ san.style.display='flex'; document.getElementById('amSaText').textContent=c.saText; }
    else san.style.display='none';
    document.getElementById('amDynamic').innerHTML = c.extra||'';
    document.getElementById('amRemarks').value = '';
    const cb = document.getElementById('amConfirm');
    cb.className = `btn btn-sm ${c.btn}`; cb.innerHTML = c.label;
    document.getElementById('actionModal').classList.add('on');
    actionCb = async () => {
        const rmk = document.getElementById('amRemarks').value.trim();
        const payload = {id: dbId, type, remarks: rmk};
        if (type === 'saoverride') {
            const ns = document.getElementById('amNewStatus')?.value;
            if(!ns){ toast('Please select a target status.','w'); return false; }
            payload.newStatus = ns;
        }
        try {
            const updated = await apiPost(API+'?api=action', payload);
            // Update in-memory state
            const idx = SUMMARY.disposal.recent.findIndex(d=>d.id===updated.id);
            if(idx>-1) SUMMARY.disposal.recent[idx] = updated;
            toast(`${updated.disposalId} — ${type} applied.`, 's');
            // Full reload for compliance panel + KPIs
            const fresh = await apiGet(API+'?api=summary');
            SUMMARY = fresh;
            renderKpis(); renderCompliance(); renderDisposalTable();
            renderAssetStats(); renderAuditTrail();
        } catch(e){ toast(e.message,'d'); }
    };
}

document.getElementById('amConfirm').addEventListener('click', async () => {
    if (!actionCb) return;
    const result = await actionCb();
    if (result === false) return;
    document.getElementById('actionModal').classList.remove('on');
    actionCb = null;
});
document.getElementById('amCancel').addEventListener('click', () => { document.getElementById('actionModal').classList.remove('on'); actionCb=null; });
document.getElementById('actionModal').addEventListener('click', function(e){ if(e.target===this){ this.classList.remove('on'); actionCb=null; }});

// ── BATCH BUTTONS ──────────────────────────────────────────────────────────────
async function runBatch(type, validStatuses, confirmMsg){
    const rows = SUMMARY?.disposal?.recent || [];
    const valid = [...selectedIds].map(did => rows.find(d=>d.disposalId===did)).filter(d=>d&&validStatuses.includes(d.status));
    if (!valid.length){ toast('No eligible records in selection.','w'); return; }
    if (!confirm(confirmMsg.replace('{n}', valid.length))) return;
    try {
        const r = await apiPost(API+'?api=batch',{type,ids:valid.map(d=>d.id)});
        toast(`${r.updated} record(s) updated.`,'s');
        selectedIds.clear();
        const fresh = await apiGet(API+'?api=summary');
        SUMMARY = fresh;
        renderKpis(); renderCompliance(); renderDisposalTable();
        renderAssetStats(); updateBulkBar();
    } catch(e){ toast(e.message,'d'); }
}
document.getElementById('batchApproveBtn').addEventListener('click', ()=>runBatch('batch-approve',['Pending Approval'],'Bulk approve {n} pending disposal(s)?'));
document.getElementById('batchCompleteBtn').addEventListener('click',()=>runBatch('batch-complete',['Approved'],'Bulk complete {n} approved disposal(s)?'));
document.getElementById('batchRejectBtn').addEventListener('click',  ()=>runBatch('batch-reject', ['Pending Approval'],'Bulk reject {n} pending disposal(s)?'));

// ── TOAST ──────────────────────────────────────────────────────────────────────
function toast(msg, type='s'){
    const ic = {s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
    const el = document.createElement('div');
    el.className = `toast t${type}`;
    el.innerHTML = `<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(()=>{ el.classList.add('out'); setTimeout(()=>el.remove(),320); },3500);
}

// ── INIT ───────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>