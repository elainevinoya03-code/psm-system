<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── SESSION GUARD ─────────────────────────────────────────────────────────────
// Prevent access after logout — no-cache headers stop browser back-button replay
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

if (empty($_SESSION['user_id'])) {
    // Fully destroy the session so nothing leaks
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();

    // For API calls return 401, for page requests redirect to login
    if (isset($_GET['api'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in again.']);
        exit;
    }

    header('Location: /login.php');
    exit;
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ad_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ad_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ad_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function ad_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $zone   = $_SESSION['zone'] ?? '';

    try {

        // ── GET: KPI summary ──────────────────────────────────────────────────
        if ($api === 'kpi' && $method === 'GET') {
            // SWS inventory
            $inv = ad_sb('sws_inventory', 'GET', [
                'select' => 'id,stock,min_level,max_level,active,zone',
                'active' => 'eq.true',
            ]);
            $totalItems  = count($inv);
            $lowStock    = count(array_filter($inv, fn($i) => (int)$i['stock'] <= (int)$i['min_level'] && (int)$i['stock'] > 0));
            $outOfStock  = count(array_filter($inv, fn($i) => (int)$i['stock'] === 0));
            $totalValue  = array_sum(array_column($inv, 'stock')); // count proxy

            // SWS bins
            $bins = ad_sb('sws_bins', 'GET', [
                'select' => 'id,capacity,used,active,status',
                'active' => 'eq.true',
            ]);
            $totalCap  = array_sum(array_column($bins, 'capacity'));
            $totalUsed = array_sum(array_column($bins, 'used'));
            $binOccPct = $totalCap > 0 ? round(($totalUsed / $totalCap) * 100, 1) : 0;

            // PSM PRs
            $prs = ad_sb('psm_purchase_requests', 'GET', [
                'select' => 'id,status,total_amount',
            ]);
            $pendingPRs  = count(array_filter($prs, fn($r) => in_array($r['status'], ['Pending','Draft'])));
            $approvedPRs = count(array_filter($prs, fn($r) => $r['status'] === 'Approved'));
            $totalPRVal  = array_sum(array_column(
                array_filter($prs, fn($r) => in_array($r['status'], ['Approved','Pending'])),
                'total_amount'
            ));

            // PSM pipeline stages
            $pipeline = [
                'Filed'     => count(array_filter($prs, fn($r) => in_array($r['status'], ['Draft','Pending']))),
                'Approved'  => $approvedPRs,
                'PO Issued' => 0,
                'Delivered' => 0,
                'Closed'    => count(array_filter($prs, fn($r) => $r['status'] === 'Rejected')),
            ];
            $pos = ad_sb('psm_purchase_orders', 'GET', ['select' => 'id,status']);
            foreach ($pos as $po) {
                if (in_array($po['status'], ['Sent','Confirmed','Partially Fulfilled'])) $pipeline['PO Issued']++;
                if ($po['status'] === 'Fulfilled') $pipeline['Delivered']++;
            }

            // PLT deliveries
            $deliveries = ad_sb('plt_deliveries', 'GET', ['select' => 'id,status,is_late']);
            $activeDeliveries = count(array_filter($deliveries, fn($d) => in_array($d['status'], ['Scheduled','In Transit'])));
            $lateDeliveries   = count(array_filter($deliveries, fn($d) => $d['is_late']));
            $slaTotal         = count($deliveries);
            $slaMet           = count(array_filter($deliveries, fn($d) => !$d['is_late'] && in_array($d['status'], ['Delivered','Force Completed'])));
            $slaPct           = $slaTotal > 0 ? round($slaMet / $slaTotal * 100, 1) : 100;

            // ALMS assets
            $assets = ad_sb('alms_assets', 'GET', ['select' => 'id,status']);
            $totalAssets     = count($assets);
            $activeAssets    = count(array_filter($assets, fn($a) => in_array($a['status'], ['Active','Assigned'])));
            $maintAssets     = count(array_filter($assets, fn($a) => $a['status'] === 'Under Maintenance'));
            $assetAvailPct   = $totalAssets > 0 ? round($activeAssets / $totalAssets * 100, 1) : 0;

            // ALMS maintenance due
            $maint = ad_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,status,next_due',
                'status' => 'in.(Scheduled,Overdue)',
            ]);
            $maintDue = count(array_filter($maint, fn($m) =>
                $m['status'] === 'Overdue' || ($m['next_due'] && $m['next_due'] <= date('Y-m-d', strtotime('+7 days')))
            ));

            // DTRS
            $docs = ad_sb('dtrs_documents', 'GET', [
                'select' => 'id,status,needs_validation',
                'status' => 'in.(Registered,In Transit,Processing)',
            ]);
            $docFlags   = count(array_filter($docs, fn($d) => $d['needs_validation']));
            $docPending = count($docs);

            // Notifications
            $notifs = ad_sb('notifications', 'GET', [
                'select' => 'id,severity,module',
                'status' => 'eq.unread',
                'limit'  => '100',
            ]);
            $critAlerts = count(array_filter($notifs, fn($n) => $n['severity'] === 'Critical'));
            $highAlerts = count(array_filter($notifs, fn($n) => $n['severity'] === 'High'));

            // Zone users
            $users = ad_sb('users', 'GET', [
                'select' => 'user_id,status',
                'status' => 'eq.Active',
            ]);

            ad_ok([
                'sws'      => ['totalItems' => $totalItems, 'lowStock' => $lowStock, 'outOfStock' => $outOfStock, 'binOccPct' => $binOccPct],
                'psm'      => ['pendingPRs' => $pendingPRs, 'approvedPRs' => $approvedPRs, 'totalPRVal' => $totalPRVal, 'pipeline' => $pipeline],
                'plt'      => ['activeDeliveries' => $activeDeliveries, 'lateDeliveries' => $lateDeliveries, 'slaPct' => $slaPct],
                'alms'     => ['totalAssets' => $totalAssets, 'activeAssets' => $activeAssets, 'maintDue' => $maintDue, 'assetAvailPct' => $assetAvailPct],
                'dtrs'     => ['docPending' => $docPending, 'docFlags' => $docFlags],
                'alerts'   => ['total' => count($notifs), 'critical' => $critAlerts, 'high' => $highAlerts],
                'users'    => ['active' => count($users)],
            ]);
        }

        // ── GET: Zone users ────────────────────────────────────────────────────
        if ($api === 'users' && $method === 'GET') {
            $query = [
                'select' => 'user_id,first_name,last_name,email,zone,status,last_login,emp_id,permissions',
                'order'  => 'first_name.asc',
                'limit'  => '50',
            ];
            if (!empty($_GET['zone']))   $query['zone']   = 'eq.' . $_GET['zone'];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            $rows = ad_sb('users', 'GET', $query);

            // Get roles for each user
            $userIds = array_column($rows, 'user_id');
            $roleMap = [];
            if (!empty($userIds)) {
                $urRows = ad_sb('user_roles', 'GET', [
                    'select'  => 'user_id,role_id',
                    'user_id' => 'in.(' . implode(',', $userIds) . ')',
                ]);
                $roleIds = array_unique(array_column($urRows, 'role_id'));
                if (!empty($roleIds)) {
                    $roleRows = ad_sb('roles', 'GET', [
                        'select' => 'id,name',
                        'id'     => 'in.(' . implode(',', $roleIds) . ')',
                    ]);
                    $roleNames = array_column($roleRows, 'name', 'id');
                    foreach ($urRows as $ur) {
                        $roleMap[$ur['user_id']][] = $roleNames[$ur['role_id']] ?? 'Unknown';
                    }
                }
            }

            ad_ok(array_map(fn($r) => [
                'userId'    => $r['user_id'],
                'name'      => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'email'     => $r['email']      ?? '',
                'zone'      => $r['zone']       ?? '',
                'status'    => $r['status']     ?? '',
                'lastLogin' => $r['last_login'] ?? null,
                'empId'     => $r['emp_id']     ?? '',
                'roles'     => $roleMap[$r['user_id']] ?? [],
            ], $rows));
        }

        // ── POST: Update user status ──────────────────────────────────────────
        if ($api === 'user-status' && $method === 'POST') {
            $b      = ad_body();
            $userId = trim($b['userId'] ?? '');
            $status = trim($b['status'] ?? '');
            $now    = date('Y-m-d H:i:s');
            if (!$userId) ad_err('Missing userId', 400);
            $allowed = ['Active','Inactive','Suspended','Locked'];
            if (!in_array($status, $allowed, true)) ad_err('Invalid status', 400);
            ad_sb('users', 'PATCH', ['user_id' => 'eq.' . $userId], [
                'status'     => $status,
                'updated_at' => $now,
            ]);
            ad_sb('audit_logs', 'POST', [], [[
                'user_id'      => $userId,
                'action'       => 'Status changed to ' . $status,
                'performed_by' => $actor,
                'ip_address'   => $ip,
                'remarks'      => 'Changed via Admin Dashboard',
                'is_sa'        => false,
                'created_at'   => $now,
            ]]);
            ad_ok(['updated' => true]);
        }

        // ── GET: SWS inventory ────────────────────────────────────────────────
        if ($api === 'sws-inventory' && $method === 'GET') {
            $query = [
                'select' => 'id,code,name,category,zone,stock,min_level,max_level,rop,uom,active,updated_at',
                'active' => 'eq.true',
                'order'  => 'stock.asc',
                'limit'  => '60',
            ];
            if (!empty($_GET['zone']))   $query['zone']     = 'eq.' . $_GET['zone'];
            if (!empty($_GET['status'])) {
                if ($_GET['status'] === 'Out of Stock') $query['stock'] = 'eq.0';
                elseif ($_GET['status'] === 'Low Stock') { /* handled client-side */ }
            }
            $rows = ad_sb('sws_inventory', 'GET', $query);
            ad_ok(array_map(fn($r) => [
                ...$r,
                'stock'    => (int)$r['stock'],
                'minLevel' => (int)$r['min_level'],
                'maxLevel' => (int)$r['max_level'],
                'rop'      => (int)$r['rop'],
                'status'   => (int)$r['stock'] === 0 ? 'Out of Stock'
                            : ((int)$r['stock'] <= (int)$r['min_level'] ? 'Low Stock'
                            : ((int)$r['stock'] > (int)$r['max_level'] ? 'Overstocked' : 'In Stock')),
            ], $rows));
        }

        // ── GET: SWS bins ──────────────────────────────────────────────────────
        if ($api === 'sws-bins' && $method === 'GET') {
            $query = [
                'select' => 'id,bin_id,code,zone,capacity,used,status,active',
                'active' => 'eq.true',
                'order'  => 'zone.asc,code.asc',
                'limit'  => '80',
            ];
            if (!empty($_GET['zone'])) $query['zone'] = 'eq.' . $_GET['zone'];
            $rows = ad_sb('sws_bins', 'GET', $query);
            ad_ok(array_map(fn($r) => [
                ...$r,
                'capacity' => (int)$r['capacity'],
                'used'     => (int)$r['used'],
                'utilPct'  => (int)$r['capacity'] > 0
                    ? min(100, round(((int)$r['used'] / (int)$r['capacity']) * 100))
                    : 0,
            ], $rows));
        }

        // ── GET: PSM purchase requests ─────────────────────────────────────────
        if ($api === 'psm-prs' && $method === 'GET') {
            $query = [
                'select' => 'id,pr_number,requestor_name,department,date_filed,date_needed,status,total_amount,item_count',
                'order'  => 'date_filed.desc',
                'limit'  => '30',
            ];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            ad_ok(ad_sb('psm_purchase_requests', 'GET', $query));
        }

        // ── GET: PSM purchase orders ───────────────────────────────────────────
        if ($api === 'psm-pos' && $method === 'GET') {
            $query = [
                'select' => 'id,po_number,pr_reference,supplier_name,branch,issued_by,date_issued,delivery_date,status,total_amount,fulfill_pct',
                'order'  => 'date_issued.desc',
                'limit'  => '30',
            ];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            ad_ok(ad_sb('psm_purchase_orders', 'GET', $query));
        }

        // ── GET: PLT deliveries ────────────────────────────────────────────────
        if ($api === 'plt-deliveries' && $method === 'GET') {
            $query = [
                'select' => 'id,delivery_id,supplier,po_ref,zone,assigned_to,expected_date,actual_date,is_late,status',
                'order'  => 'expected_date.asc',
                'limit'  => '30',
            ];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            ad_ok(ad_sb('plt_deliveries', 'GET', $query));
        }

        // ── GET: PLT projects ──────────────────────────────────────────────────
        if ($api === 'plt-projects' && $method === 'GET') {
            $query = [
                'select' => 'id,project_id,name,zone,manager,priority,start_date,end_date,progress,status,budget,spend',
                'status' => 'in.(Planning,Active,On Hold,Delayed)',
                'order'  => 'end_date.asc',
                'limit'  => '20',
            ];
            ad_ok(ad_sb('plt_projects', 'GET', $query));
        }

        // ── GET: ALMS assets ───────────────────────────────────────────────────
        if ($api === 'alms-assets' && $method === 'GET') {
            $query = [
                'select' => 'id,asset_id,name,category,zone,status,condition,assignee,purchase_cost,current_value',
                'order'  => 'name.asc',
                'limit'  => '50',
            ];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            ad_ok(ad_sb('alms_assets', 'GET', $query));
        }

        // ── GET: ALMS maintenance schedules ───────────────────────────────────
        if ($api === 'alms-maintenance' && $method === 'GET') {
            $rows = ad_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_name,type,freq,zone,next_due,tech,status',
                'status' => 'in.(Scheduled,Overdue,In Progress)',
                'order'  => 'next_due.asc',
                'limit'  => '30',
            ]);
            ad_ok($rows);
        }

        // ── GET: ALMS repair logs ──────────────────────────────────────────────
        if ($api === 'alms-repairs' && $method === 'GET') {
            $rows = ad_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_name,zone,issue,date_reported,technician,repair_cost,status',
                'status' => 'in.(Reported,In Progress,Escalated)',
                'order'  => 'date_reported.desc',
                'limit'  => '30',
            ]);
            ad_ok($rows);
        }

        // ── GET: DTRS documents ────────────────────────────────────────────────
        if ($api === 'dtrs-docs' && $method === 'GET') {
            $rows = ad_sb('dtrs_documents', 'GET', [
                'select' => 'id,doc_id,title,doc_type,category,department,priority,status,needs_validation,doc_date,assigned_to,direction',
                'status' => 'in.(Registered,In Transit,Processing)',
                'order'  => 'doc_date.desc',
                'limit'  => '30',
            ]);
            ad_ok($rows);
        }

        // ── GET: Notifications / alerts ────────────────────────────────────────
        if ($api === 'alerts' && $method === 'GET') {
            $query = [
                'select' => 'id,notif_id,category,module,severity,title,description,zone,status,source_table,created_at',
                'status' => 'eq.unread',
                'order'  => 'created_at.desc',
                'limit'  => '25',
            ];
            if (!empty($_GET['module'])) $query['module'] = 'eq.' . $_GET['module'];
            ad_ok(ad_sb('notifications', 'GET', $query));
        }

        // ── POST: Alert dismiss / escalate ────────────────────────────────────
        if ($api === 'alert-action' && $method === 'POST') {
            $b    = ad_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');
            if (!$id || !$type) ad_err('Missing id or type', 400);

            if ($type === 'dismiss') {
                ad_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], [
                    'status'       => 'dismissed',
                    'dismissed_by' => $actor,
                    'dismissed_at' => $now,
                    'updated_at'   => $now,
                ]);
                ad_ok(['dismissed' => true]);
            }
            if ($type === 'escalate') {
                ad_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], [
                    'status'            => 'escalated',
                    'escalated_by'      => $actor,
                    'escalated_at'      => $now,
                    'escalate_priority' => trim($b['priority'] ?? 'High'),
                    'escalate_remarks'  => trim($b['remarks']  ?? ''),
                    'updated_at'        => $now,
                ]);
                ad_ok(['escalated' => true]);
            }
            ad_err('Unknown action type', 400);
        }

        // ── GET: Audit feed from unified view ──────────────────────────────────
        if ($api === 'audit-feed' && $method === 'GET') {
            $query = [
                'select' => 'log_id,module,action_label,actor_name,actor_role,action_type,record_ref,is_super_admin,occurred_at',
                'order'  => 'occurred_at.desc',
                'limit'  => (string)(int)($_GET['limit'] ?? 20),
            ];
            if (!empty($_GET['module'])) $query['module'] = 'eq.' . $_GET['module'];
            ad_ok(ad_sb('v_audit_unified', 'GET', $query));
        }

        // ── GET: SWS zones ─────────────────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            ad_ok(ad_sb('sws_zones', 'GET', ['select' => 'id,name,color']));
        }

        // ── GET: Budget snapshot from PSM + PLT ───────────────────────────────
        if ($api === 'budget' && $method === 'GET') {
            $prs = ad_sb('psm_purchase_requests', 'GET', ['select' => 'id,total_amount,status,department']);
            $pos = ad_sb('psm_purchase_orders',   'GET', ['select' => 'id,total_amount,status,branch']);

            // Group by dept / branch as module proxy
            $modules = ['SWS','PSM','PLT','ALMS','DTRS'];
            $depts   = array_unique(array_merge(
                array_column($prs, 'department'),
                array_column($pos, 'branch')
            ));

            // Aggregate actual spend from approved POs
            $spend = [];
            foreach ($pos as $po) {
                if (in_array($po['status'], ['Confirmed','Partially Fulfilled','Fulfilled'])) {
                    $key = $po['branch'] ?? 'Other';
                    $spend[$key] = ($spend[$key] ?? 0) + (float)$po['total_amount'];
                }
            }
            ad_ok(['spend' => $spend, 'departments' => array_values($depts)]);
        }

        // ── GET: Zone traceability (PO → Stock → Asset → Doc) ─────────────────
        if ($api === 'traceability' && $method === 'GET') {
            $pos   = ad_sb('psm_purchase_orders',   'GET', ['select' => 'id,po_number,pr_reference,supplier_name,status', 'order' => 'date_issued.desc', 'limit' => '15']);
            $prefs = array_unique(array_column($pos, 'pr_reference'));
            $rows  = array_map(fn($po) => [
                'poNumber'   => $po['po_number']      ?? '',
                'prRef'      => $po['pr_reference']   ?? '',
                'supplier'   => $po['supplier_name']  ?? '',
                'poStatus'   => $po['status']         ?? '',
            ], $pos);
            ad_ok($rows);
        }

        // ── GET: Role audit log ────────────────────────────────────────────────
        if ($api === 'role-audit' && $method === 'GET') {
            $rows = ad_sb('role_audit_log', 'GET', [
                'select'     => 'id,action_label,actor_name,css_class,note,occurred_at',
                'order'      => 'occurred_at.desc',
                'limit'      => '20',
            ]);
            ad_ok($rows);
        }

        ad_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ad_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────────
include("includes/superadmin_sidebar.php");
include("includes/header.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — LOG1</title>
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/header.css">
<style>
*,*::before,*::after{box-sizing:border-box;}
#mainContent,#modalOverlay,.sa-toasts{
  --s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary,#1A2E1C);--t2:var(--text-secondary,#5D6F62);--t3:#9EB0A2;
  --hbg:var(--hover-bg-light,#F0FAF0);--bg:var(--bg-color,#F4F7F4);
  --grn:var(--primary-color,#2E7D32);--gdk:var(--primary-dark,#1B5E20);
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.2);
  --rad:12px;--tr:all .18s cubic-bezier(.4,0,.2,1);
}
.sa-wrap{max-width:1520px;margin:0 auto;padding:0 0 5rem;}
.sa-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;animation:UP .4s both;}
.sa-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.sa-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.sa-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.zone-pill{display:inline-flex;align-items:center;gap:5px;background:#E8F5E9;color:var(--grn);border:1.5px solid rgba(46,125,50,.3);font-size:11.5px;font-weight:700;padding:5px 12px;border-radius:20px;}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-danger{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}.btn-danger:hover{background:#FCA5A5;}
.btn-warn{background:#FFFBEB;color:#92400E;border:1px solid #FCD34D;}.btn-warn:hover{background:#FEF3C7;}
.btn-sm{font-size:12px;padding:7px 14px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn:disabled{opacity:.4;pointer-events:none;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;border-radius:7px;}
/* MODULE FILTER */
.mod-toggles{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:20px;animation:UP .4s .03s both;}
.mod-tog{font-family:'Inter',sans-serif;font-size:12px;font-weight:700;padding:6px 14px;border-radius:20px;border:1.5px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;transition:var(--tr);}
.mod-tog:hover{border-color:var(--grn);color:var(--grn);}
.mod-tog.active{color:#fff;border-color:transparent;}
.mod-tog[data-m="ALL"].active{background:var(--grn);}
.mod-tog[data-m="SWS"].active{background:#2563EB;}.mod-tog[data-m="PSM"].active{background:#0D9488;}
.mod-tog[data-m="PLT"].active{background:#7C3AED;}.mod-tog[data-m="ALMS"].active{background:#D97706;}
.mod-tog[data-m="DTRS"].active{background:#DC2626;}
/* KPI BAR */
.kpi-bar{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:22px;animation:UP .4s .06s both;}
.kpi-card{background:var(--s);border:1px solid var(--bd);border-radius:14px;padding:16px 18px;box-shadow:var(--shmd);position:relative;overflow:hidden;}
.kpi-card::after{content:'';position:absolute;top:0;right:0;width:4px;height:100%;border-radius:0 14px 14px 0;}
.kpi-card.kc-grn::after{background:var(--grn)}.kpi-card.kc-blu::after{background:var(--blu)}
.kpi-card.kc-amb::after{background:var(--amb)}.kpi-card.kc-red::after{background:var(--red)}
.kpi-card.kc-tel::after{background:var(--tel)}.kpi-card.kc-pur::after{background:var(--pur)}
.kpi-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:6px;}
.kpi-val{font-size:22px;font-weight:800;color:var(--t1);line-height:1;margin-bottom:4px;font-family:'DM Mono',monospace;}
.kpi-val.sm{font-size:16px;}
.kpi-sub{font-size:11px;color:var(--t2);}
.kpi-bar-fill{height:4px;background:#E5E7EB;border-radius:2px;margin-top:8px;overflow:hidden;}
.kpi-bar-inner{height:100%;border-radius:2px;transition:width .6s ease;}
.kpi-skel{background:linear-gradient(90deg,#E5E7EB 25%,#F3F4F6 50%,#E5E7EB 75%);background-size:200%;animation:SKEL 1.5s infinite;border-radius:4px;height:24px;width:50px;}
@keyframes SKEL{from{background-position:200% 0}to{background-position:-200% 0}}
/* GRIDS */
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
.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:var(--pur)}.ic-d{background:#F3F4F6;color:#374151}
.card-hd-t{font-size:14px;font-weight:700;color:var(--t1);}
.card-hd-s{font-size:11.5px;color:var(--t2);margin-top:1px;}
.card-hd-r{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-left:auto;}
.card-body{padding:18px 20px;}
/* PIPELINE */
.pipeline-wrap{display:flex;gap:2px;height:28px;border-radius:8px;overflow:hidden;margin-bottom:10px;}
.pipe-seg{display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:700;color:#fff;transition:var(--tr);cursor:pointer;white-space:nowrap;overflow:hidden;}
.pipe-seg:hover{filter:brightness(1.1);}
.pipeline-legend{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.pl-item{display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--t2);}
.pl-dot{width:8px;height:8px;border-radius:2px;flex-shrink:0;}
/* TABLES */
.sa-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.sa-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:9px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;}
.sa-tbl thead th.no-sort{cursor:default;}
.sa-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s;}
.sa-tbl tbody tr:last-child{border-bottom:none;}.sa-tbl tbody tr:hover{background:var(--hbg);}
.sa-tbl tbody td{padding:10px 12px;vertical-align:middle;}
/* CHIPS */
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;white-space:nowrap;}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
.c-grn{background:#DCFCE7;color:#166534}.c-amb{background:#FEF3C7;color:#92400E}
.c-red{background:#FEE2E2;color:#991B1B}.c-blu{background:#EFF6FF;color:#1D4ED8}
.c-tel{background:#CCFBF1;color:#0F766E}.c-gry{background:#F3F4F6;color:#374151}.c-pur{background:#F5F3FF;color:#5B21B6}
.role-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;border:1px solid;}
.rb-sa{background:#FEF3C7;color:#92400E;border-color:#FCD34D;}
.rb-ad{background:#DCFCE7;color:#166534;border-color:#BBF7D0;}
.rb-mg{background:#EFF6FF;color:#1D4ED8;border-color:#BFDBFE;}
.rb-st{background:#F3F4F6;color:#374151;border-color:#D1D5DB;}
.online-dot{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--t2);}
.online-dot::before{content:'';width:7px;height:7px;border-radius:50%;background:#22C55E;flex-shrink:0;}
.offline-dot::before{background:#9CA3AF;}
/* BAR CHART */
.bar-chart{display:flex;align-items:flex-end;gap:6px;height:110px;padding-top:10px;}
.bc-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;}
.bc-bar{width:100%;border-radius:4px 4px 0 0;transition:height .5s ease;min-height:4px;cursor:pointer;}
.bc-bar:hover{filter:brightness(.9);}
.bc-val{font-size:9.5px;font-family:'DM Mono',monospace;font-weight:700;color:var(--t1);}
.bc-lbl{font-size:9px;color:var(--t3);text-align:center;}
/* HEALTH GRID */
.health-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;}
.hc{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;}
.hc-t{font-size:11.5px;font-weight:700;color:var(--t1);margin-bottom:4px;}
.hc-s{font-size:11px;color:var(--t2);margin-bottom:8px;}
.hc-prog{height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden;}
.hc-bar{height:100%;border-radius:3px;transition:width .5s ease;}
.hc-val{font-family:'DM Mono',monospace;font-size:12px;font-weight:700;margin-top:5px;}
/* CONFIG */
.cfg-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 0;border-bottom:1px solid var(--bd);}
.cfg-row:last-child{border-bottom:none;padding-bottom:0;}.cfg-row:first-child{padding-top:0;}
.cfg-l{flex:1;}.cfg-t{font-size:13px;font-weight:600;color:var(--t1);}
.cfg-s{font-size:11.5px;color:var(--t2);margin-top:2px;}
.cfg-v{display:flex;align-items:center;gap:8px;flex-shrink:0;}
.cfg-input{font-family:'Inter',sans-serif;font-size:13px;padding:7px 10px;border:1px solid var(--bdm);border-radius:8px;background:var(--s);color:var(--t1);outline:none;width:90px;text-align:right;transition:var(--tr);}
.cfg-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);}
.tgl{width:38px;height:21px;border-radius:11px;position:relative;flex-shrink:0;cursor:pointer;transition:var(--tr);}
.tgl.on{background:var(--grn);}.tgl.off{background:#D1D5DB;}
.tgl-knob{width:15px;height:15px;border-radius:50%;background:#fff;position:absolute;top:3px;transition:var(--tr);box-shadow:0 1px 3px rgba(0,0,0,.2);}
.tgl.on .tgl-knob{left:20px;}.tgl.off .tgl-knob{left:3px;}
/* SECTION DIVIDER */
.sec-divider{display:flex;align-items:center;gap:10px;margin:24px 0 14px;animation:UP .4s both;}
.sec-divider span{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);}
.sec-divider::after{content:'';flex:1;height:1px;background:var(--bd);}
/* SCOPE NOTICE */
.scope-notice{display:flex;align-items:center;gap:9px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 15px;font-size:12px;font-weight:500;color:#92400E;margin-bottom:16px;}
.scope-notice i{font-size:16px;flex-shrink:0;color:var(--amb);}
/* REPORT TABS */
.rpt-tabs{display:flex;gap:3px;padding:14px 20px 0;border-bottom:1px solid var(--bd);}
.rpt-tab{font-family:'Inter',sans-serif;font-size:12.5px;font-weight:600;padding:8px 15px;border-radius:8px 8px 0 0;cursor:pointer;border:none;background:transparent;color:var(--t2);transition:var(--tr);white-space:nowrap;display:flex;align-items:center;gap:6px;}
.rpt-tab.active{background:var(--grn);color:#fff;}.rpt-tab:hover:not(.active){background:var(--hbg);color:var(--t1);}
.rpt-panel{display:none;padding:20px;}.rpt-panel.active{display:block;}
.rpt-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.rpt-sel{font-family:'Inter',sans-serif;font-size:12px;padding:7px 24px 7px 10px;border:1px solid var(--bdm);border-radius:9px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;}
.rpt-sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);outline:none;}
.fi-date-sm{font-family:'Inter',sans-serif;font-size:12px;padding:7px 10px;border:1px solid var(--bdm);border-radius:9px;background:var(--s);color:var(--t1);outline:none;}
.fi-date-sm:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);}
/* AUDIT */
.audit-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd);}
.audit-item:last-child{border-bottom:none;padding-bottom:0;}.audit-item:first-child{padding-top:0;}
.audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}
.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}
.ad-e{background:#F3F4F6;color:#6B7280}.ad-o{background:#FEF3C7;color:#D97706}
.ad-x{background:#F3F4F6;color:#374151}.ad-d{background:#F5F3FF;color:#6D28D9}
.audit-body{flex:1;min-width:0;}
.audit-body .au{font-size:12.5px;font-weight:500;color:var(--t1);}
.audit-body .at{font-size:11px;color:#9EB0A2;margin-top:2px;display:flex;align-items:center;gap:5px;}
.audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
/* LOADING */
.loading-row{padding:32px;text-align:center;color:var(--t3);font-size:13px;}
.spin{display:inline-block;width:18px;height:18px;border:2px solid var(--bd);border-top-color:var(--grn);border-radius:50%;animation:SPIN .7s linear infinite;vertical-align:middle;margin-right:8px;}
@keyframes SPIN{to{transform:rotate(360deg)}}
.empty-state{padding:40px 20px;text-align:center;color:var(--t3);}
.empty-state i{font-size:40px;display:block;margin-bottom:10px;}
/* MODAL */
#modalOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s;}
#modalOverlay.on{opacity:1;pointer-events:all;}
.modal-box{background:#fff;border-radius:16px;width:500px;max-width:100%;box-shadow:var(--shlg);overflow:hidden;}
.modal-box.wide{width:760px;}
.mhd{padding:20px 22px 16px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;}
.mhd-t{font-size:16px;font-weight:700;color:var(--t1);}.mhd-s{font-size:12px;color:var(--t2);margin-top:2px;}
.m-cl{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:18px;color:var(--t2);transition:var(--tr);}
.m-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.mbody{padding:20px 22px;display:flex;flex-direction:column;gap:14px;max-height:70vh;overflow-y:auto;}
.mbody::-webkit-scrollbar{width:4px;}.mbody::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.mft{padding:14px 22px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:8px;justify-content:flex-end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}
.fg input,.fg select,.fg textarea{font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;border:1px solid var(--bdm);border-radius:9px;background:#fff;color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);}
.fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:28px;}
.fg textarea{resize:vertical;min-height:70px;}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fn{font-size:11px;color:var(--t2);background:var(--bg);border-radius:8px;padding:8px 12px;border:1px solid var(--bd);}
/* TOAST */
.sa-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}
.toast.out{animation:TOUT .3s ease forwards;}
/* PROG */
.prog-wrap{display:flex;align-items:center;gap:8px;}
.prog-track{flex:1;height:5px;background:#E5E7EB;border-radius:3px;overflow:hidden;min-width:50px;}
.prog-fill{height:100%;border-radius:3px;transition:width .5s ease;}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@media(max-width:1280px){.kpi-bar{grid-template-columns:repeat(3,1fr);}.health-grid{grid-template-columns:repeat(3,1fr);}.sa-grid-3{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){.sa-grid,.sa-grid-w{grid-template-columns:1fr;}.kpi-bar{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.kpi-bar{grid-template-columns:1fr;}.health-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="sa-wrap">

  <div class="sa-ph">
    <div>
      <p class="ey">Logistics 1 — Admin Dashboard</p>
      <h1>Zone Operations Control</h1>
    </div>
    <div class="sa-ph-r">
      <span id="liveClock" style="font-family:'DM Mono',monospace;font-size:12px;color:var(--t3);"></span>
      <div class="zone-pill"><i class="bx bx-map-pin"></i> <span id="zonePillLabel">Loading…</span></div>
      <button class="btn btn-ghost btn-sm" onclick="refreshAll()"><i class="bx bx-refresh"></i> Refresh</button>
    </div>
  </div>

  <div class="mod-toggles">
    <span style="font-size:12px;font-weight:600;color:var(--t2)">Filter by Module:</span>
    <button class="mod-tog active" data-m="ALL"  onclick="setMod('ALL',this)">All Modules</button>
    <button class="mod-tog"        data-m="SWS"  onclick="setMod('SWS',this)"><i class="bx bx-package" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>SWS</button>
    <button class="mod-tog"        data-m="PSM"  onclick="setMod('PSM',this)"><i class="bx bx-receipt" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>PSM</button>
    <button class="mod-tog"        data-m="PLT"  onclick="setMod('PLT',this)"><i class="bx bx-trip" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>PLT</button>
    <button class="mod-tog"        data-m="ALMS" onclick="setMod('ALMS',this)"><i class="bx bx-wrench" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>ALMS</button>
    <button class="mod-tog"        data-m="DTRS" onclick="setMod('DTRS',this)"><i class="bx bx-file" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>DTRS</button>
  </div>

  <!-- KPI BAR — loaded via API -->
  <div class="kpi-bar" id="kpiBar">
    <?php for($i=0;$i<5;$i++): ?>
    <div class="kpi-card kc-grn"><div class="kpi-label">&nbsp;</div><div class="kpi-skel"></div></div>
    <?php endfor; ?>
  </div>

  <!-- SWS SECTION -->
  <div class="mod-section" data-mod="SWS">
    <div class="sec-divider"><span>SWS — Smart Warehousing</span></div>
    <div class="sa-grid-w">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-b"><i class="bx bx-package"></i></div>
            <div><div class="card-hd-t">Inventory Status</div><div class="card-hd-s">All items — live from sws_inventory</div></div>
          </div>
          <div class="card-hd-r">
            <select class="rpt-sel" id="swsStatusFilter" onchange="loadSWSInventory()">
              <option value="">All Statuses</option><option>In Stock</option><option>Low Stock</option><option>Out of Stock</option><option>Overstocked</option>
            </select>
            <select class="rpt-sel" id="swsZoneFilter" onchange="loadSWSInventory()"><option value="">All Zones</option></select>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="sa-tbl">
            <thead><tr><th>Code</th><th>Item</th><th>Zone</th><th>Stock</th><th>Min/ROP</th><th>Status</th></tr></thead>
            <tbody id="swsInvTbody"><tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-a"><i class="bx bx-grid-alt"></i></div>
            <div><div class="card-hd-t">Bin Utilisation</div><div class="card-hd-s">sws_bins — occupancy %</div></div>
          </div>
        </div>
        <div class="card-body" id="swsBinsBody"><div class="loading-row"><span class="spin"></span>Loading…</div></div>
      </div>
    </div>
  </div>

  <!-- PSM SECTION -->
  <div class="mod-section" data-mod="PSM">
    <div class="sec-divider"><span>PSM — Procurement</span></div>
    <div class="sa-grid-w">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-t"><i class="bx bx-receipt"></i></div>
            <div><div class="card-hd-t">Purchase Requests</div><div class="card-hd-s">psm_purchase_requests</div></div>
          </div>
          <div class="card-hd-r">
            <select class="rpt-sel" id="psmPRFilter" onchange="loadPSMPRs()">
              <option value="">All</option><option>Pending</option><option>Draft</option><option>Approved</option><option>Rejected</option>
            </select>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="sa-tbl">
            <thead><tr><th>PR #</th><th>Requestor</th><th>Department</th><th>Filed</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
            <tbody id="psmPRTbody"><tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-t"><i class="bx bx-git-branch"></i></div>
            <div><div class="card-hd-t">Procurement Pipeline</div><div class="card-hd-s">RA 9184 stages — live counts</div></div>
          </div>
        </div>
        <div class="card-body">
          <div class="pipeline-wrap" id="psmPipeline"><div style="background:#E5E7EB;width:100%;height:100%;border-radius:8px;"></div></div>
          <div class="pipeline-legend" id="psmLegend"></div>
        </div>
      </div>
    </div>
    <div class="sa-full card">
      <div class="card-hd">
        <div class="card-hd-l"><div class="card-hd-ic ic-t"><i class="bx bx-purchase-tag"></i></div>
          <div><div class="card-hd-t">Purchase Orders</div><div class="card-hd-s">psm_purchase_orders</div></div>
        </div>
        <div class="card-hd-r">
          <select class="rpt-sel" id="psmPOFilter" onchange="loadPSMPOs()">
            <option value="">All</option><option>Draft</option><option>Sent</option><option>Confirmed</option><option>Partially Fulfilled</option><option>Fulfilled</option><option>Cancelled</option>
          </select>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="sa-tbl">
          <thead><tr><th>PO #</th><th>PR Ref</th><th>Supplier</th><th>Issued By</th><th>Date Issued</th><th>Delivery Date</th><th>Total</th><th>Fulfillment</th><th>Status</th></tr></thead>
          <tbody id="psmPOTbody"><tr><td colspan="9" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PLT SECTION -->
  <div class="mod-section" data-mod="PLT">
    <div class="sec-divider"><span>PLT — Project Logistics Tracker</span></div>
    <div class="sa-grid">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-p"><i class="bx bx-trip"></i></div>
            <div><div class="card-hd-t">Deliveries</div><div class="card-hd-s">plt_deliveries — active &amp; scheduled</div></div>
          </div>
          <div class="card-hd-r">
            <select class="rpt-sel" id="pltDelivFilter" onchange="loadPLTDeliveries()">
              <option value="">All</option><option>Scheduled</option><option>In Transit</option><option>Delayed</option><option>Delivered</option>
            </select>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="sa-tbl">
            <thead><tr><th>ID</th><th>Supplier</th><th>Expected</th><th>Assigned</th><th>Status</th></tr></thead>
            <tbody id="pltDelivTbody"><tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-p"><i class="bx bx-briefcase-alt-2"></i></div>
            <div><div class="card-hd-t">Active Projects</div><div class="card-hd-s">plt_projects — progress &amp; budget</div></div>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="sa-tbl">
            <thead><tr><th>ID</th><th>Name</th><th>Progress</th><th>Budget Used</th><th>Status</th></tr></thead>
            <tbody id="pltProjTbody"><tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ALMS SECTION -->
  <div class="mod-section" data-mod="ALMS">
    <div class="sec-divider"><span>ALMS — Asset Lifecycle &amp; Maintenance</span></div>
    <div class="sa-grid">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-a"><i class="bx bx-cube-alt"></i></div>
            <div><div class="card-hd-t">Asset Registry</div><div class="card-hd-s">alms_assets — all statuses</div></div>
          </div>
          <div class="card-hd-r">
            <select class="rpt-sel" id="almsAssetFilter" onchange="loadALMSAssets()">
              <option value="">All</option><option>Active</option><option>Assigned</option><option>Under Maintenance</option><option>Disposed</option>
            </select>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="sa-tbl">
            <thead><tr><th>Asset ID</th><th>Name</th><th>Category</th><th>Zone</th><th>Condition</th><th>Value</th><th>Status</th></tr></thead>
            <tbody id="almsAssetTbody"><tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l"><div class="card-hd-ic ic-a"><i class="bx bx-wrench"></i></div>
            <div><div class="card-hd-t">Maintenance &amp; Repairs</div><div class="card-hd-s">Schedules due + open repair logs</div></div>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="sa-tbl">
            <thead><tr><th>ID</th><th>Asset</th><th>Type</th><th>Due / Reported</th><th>Status</th></tr></thead>
            <tbody id="almsMaintTbody"><tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- DTRS SECTION -->
  <div class="mod-section" data-mod="DTRS">
    <div class="sec-divider"><span>DTRS — Document Tracking &amp; Registry</span></div>
    <div class="sa-full card">
      <div class="card-hd">
        <div class="card-hd-l"><div class="card-hd-ic ic-r"><i class="bx bx-file-blank"></i></div>
          <div><div class="card-hd-t">Document Queue</div><div class="card-hd-s">dtrs_documents — processing pipeline</div></div>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="sa-tbl">
          <thead><tr><th>Doc ID</th><th>Title</th><th>Type</th><th>Department</th><th>Direction</th><th>Priority</th><th>Assigned</th><th>Validation</th><th>Status</th></tr></thead>
          <tbody id="dtrsTbody"><tr><td colspan="9" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ZONE SYSTEM HEALTH -->
  <div class="sa-full card">
    <div class="card-hd">
      <div class="card-hd-l"><div class="card-hd-ic ic-p"><i class="bx bx-pulse"></i></div>
        <div><div class="card-hd-t">Zone System Health</div><div class="card-hd-s">Module record counts — live from Supabase</div></div>
      </div>
      <span class="chip c-grn" id="healthOverallChip" style="margin-left:auto;">Loading…</span>
    </div>
    <div class="card-body"><div class="health-grid" id="healthGrid"></div></div>
  </div>

  <!-- ALERT INBOX + AUDIT FEED -->
  <div class="sec-divider"><span>Alerts &amp; Activity</span></div>
  <div class="sa-grid-w">
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l"><div class="card-hd-ic ic-r"><i class="bx bx-bell"></i></div>
          <div><div class="card-hd-t">Zone Alert Inbox</div><div class="card-hd-s">notifications table — unread</div></div>
        </div>
        <div class="card-hd-r">
          <select class="rpt-sel" id="alertModFilter" onchange="loadAlerts()">
            <option value="">All</option><option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
          </select>
          <span class="chip c-red" id="alertCountChip">—</span>
        </div>
      </div>
      <div class="card-body" id="alertInbox"><div class="loading-row"><span class="spin"></span>Loading…</div></div>
    </div>
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l"><div class="card-hd-ic ic-b"><i class="bx bx-history"></i></div>
          <div><div class="card-hd-t">Audit Feed</div><div class="card-hd-s">v_audit_unified — last 20 actions</div></div>
        </div>
        <div class="card-hd-r">
          <select class="rpt-sel" id="auditFeedMod" onchange="loadAuditFeed()">
            <option value="">All</option><option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
          </select>
        </div>
      </div>
      <div class="card-body" id="auditFeedBody"><div class="loading-row"><span class="spin"></span>Loading…</div></div>
    </div>
  </div>

  <!-- TEAM & USER MANAGEMENT -->
  <div class="sec-divider"><span>Team &amp; User Management</span></div>
  <div class="scope-notice"><i class="bx bx-lock-alt"></i> Zone-scoped. Cross-zone and Super Admin account changes require Super Admin access.</div>
  <div class="card sa-full">
    <div class="card-hd">
      <div class="card-hd-l"><div class="card-hd-ic ic-d"><i class="bx bx-group"></i></div>
        <div><div class="card-hd-t">Zone Team Directory</div><div class="card-hd-s">Live from users table — status &amp; roles</div></div>
      </div>
      <div class="card-hd-r">
        <select class="rpt-sel" id="userStatusFilter" onchange="loadUsers()">
          <option value="">All Statuses</option><option>Active</option><option>Inactive</option><option>Suspended</option><option>Locked</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="openModal('addUser')"><i class="bx bx-user-plus"></i> Add User</button>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table class="sa-tbl">
        <thead><tr><th>User</th><th>Roles</th><th>Zone</th><th>Email</th><th>Last Login</th><th>Status</th><th class="no-sort">Actions</th></tr></thead>
        <tbody id="userTbody"><tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- REPORTS -->
  <div class="sec-divider"><span>Zone Reports &amp; Compliance</span></div>
  <div class="scope-notice"><i class="bx bx-lock-alt"></i> Zone reports only. Site-wide reports require Super Admin access.</div>
  <div class="card sa-full">
    <div class="rpt-tabs">
      <button class="rpt-tab active" data-t="inv"   onclick="setRptTab('inv',this)"><i class="bx bx-package"></i> Inventory</button>
      <button class="rpt-tab"        data-t="psm"   onclick="setRptTab('psm',this)"><i class="bx bx-receipt"></i> PSM</button>
      <button class="rpt-tab"        data-t="alms"  onclick="setRptTab('alms',this)"><i class="bx bx-wrench"></i> ALMS</button>
      <button class="rpt-tab"        data-t="trace" onclick="setRptTab('trace',this)"><i class="bx bx-git-merge"></i> Traceability</button>
      <button class="rpt-tab"        data-t="audit" onclick="setRptTab('audit',this)"><i class="bx bx-history"></i> Audit Log</button>
    </div>
    <div class="rpt-panel active" id="rpt-inv">
      <div class="rpt-filters">
        <select class="rpt-sel" id="rptInvZone"   onchange="renderRptInventory()"><option value="">All Zones</option></select>
        <select class="rpt-sel" id="rptInvStatus" onchange="renderRptInventory()"><option value="">All Statuses</option><option>In Stock</option><option>Low Stock</option><option>Out of Stock</option></select>
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptInvTable','inventory_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="sa-tbl" id="rptInvTable"><thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Zone</th><th>Stock</th><th>Min</th><th>ROP</th><th>Status</th></tr></thead><tbody id="rptInvTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-psm">
      <div class="rpt-filters">
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptPSMTable','psm_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="sa-tbl" id="rptPSMTable"><thead><tr><th>PR #</th><th>Requestor</th><th>Dept</th><th>Filed</th><th>Total</th><th>Status</th></tr></thead><tbody id="rptPSMTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-alms">
      <div class="rpt-filters">
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptALMSTable','alms_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="sa-tbl" id="rptALMSTable"><thead><tr><th>Asset ID</th><th>Name</th><th>Category</th><th>Zone</th><th>Condition</th><th>Value</th><th>Status</th></tr></thead><tbody id="rptALMSTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-trace">
      <div class="rpt-filters">
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptTraceTable','traceability_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="sa-tbl" id="rptTraceTable"><thead><tr><th>PO #</th><th>PR Ref</th><th>Supplier</th><th>PO Status</th></tr></thead><tbody id="rptTraceTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-audit">
      <div class="rpt-filters">
        <select class="rpt-sel" id="rptAuditMod" onchange="loadFullAudit()">
          <option value="">All Modules</option><option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptAuditTable','audit_log')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="sa-tbl" id="rptAuditTable"><thead><tr><th>Log ID</th><th>Module</th><th>Action</th><th>Actor</th><th>Role</th><th>Record</th><th>When</th></tr></thead><tbody id="rptAuditTbody"><tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody></table></div>
    </div>
  </div>

  <!-- ZONE CONFIG -->
  <div class="sec-divider"><span>Zone Configuration</span></div>
  <div class="sa-grid">
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l"><div class="card-hd-ic ic-d"><i class="bx bx-slider-alt"></i></div>
          <div><div class="card-hd-t">Zone Thresholds</div><div class="card-hd-s">Reorder points, budget alerts, SLA targets</div></div>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="toast('Zone thresholds saved.','s')"><i class="bx bx-save"></i> Save</button>
      </div>
      <div class="card-body" id="cfgRows"></div>
    </div>
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l"><div class="card-hd-ic ic-b"><i class="bx bx-bell"></i></div>
          <div><div class="card-hd-t">Zone Notifications</div><div class="card-hd-s">Alert triggers and delivery preferences</div></div>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="toast('Notification settings saved.','s')"><i class="bx bx-save"></i> Save</button>
      </div>
      <div class="card-body" id="notifRows"></div>
    </div>
  </div>

</div>
</main>

<div class="sa-toasts" id="toastWrap"></div>
<div id="modalOverlay">
  <div class="modal-box" id="modalBox">
    <div class="mhd"><div><div class="mhd-t" id="mhdTitle"></div><div class="mhd-s" id="mhdSub"></div></div>
      <button class="m-cl" onclick="closeModal()"><i class="bx bx-x"></i></button></div>
    <div class="mbody" id="mBody"></div>
    <div class="mft" id="mFoot"></div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── API ──────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p     => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── CACHE ────────────────────────────────────────────────────────────────────
let CACHE = { inventory:[], bins:[], prs:[], pos:[], deliveries:[], projects:[],
              assets:[], maintenance:[], repairs:[], docs:[], alerts:[], zones:[], traceability:[] };

// ── HELPERS ──────────────────────────────────────────────────────────────────
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fM   = n => '₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const fD   = d => { if(!d)return'—'; try{return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});}catch(e){return d;} };
const fDT  = d => { if(!d)return'—'; try{return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});}catch(e){return d;} };
const spin = (cols) => `<tr><td colspan="${cols}" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
const empty = (msg, icon='bx-inbox', cols=1) => `<tr><td colspan="${cols}"><div class="empty-state"><i class="bx ${icon}"></i><p>${msg}</p></div></td></tr>`;

function statusChip(s) {
    const m = {
        'Active':'c-grn','Assigned':'c-blu','In Progress':'c-blu','In Transit':'c-blu','Scheduled':'c-grn',
        'Completed':'c-tel','Overdue':'c-red','Low Stock':'c-amb','Out of Stock':'c-red','In Stock':'c-grn',
        'Overstocked':'c-pur','Pending':'c-amb','Draft':'c-gry','Rejected':'c-red','Approved':'c-grn',
        'Delayed':'c-red','Registered':'c-blu','Processing':'c-pur','Reported':'c-red','Escalated':'c-red',
        'Delivered':'c-tel','Fulfilled':'c-tel','Partially Fulfilled':'c-amb','Cancelled':'c-gry',
        'Sent':'c-blu','Confirmed':'c-grn','Under Maintenance':'c-amb','Disposed':'c-gry','Lost/Stolen':'c-red',
        'Good':'c-grn','Fair':'c-amb','Poor':'c-red','New':'c-tel','Force Completed':'c-tel',
        'Inactive':'c-gry','Suspended':'c-red','Locked':'c-red',
    };
    return `<span class="chip ${m[s]||'c-gry'}">${esc(s)}</span>`;
}
function priorityChip(p) {
    const m={Critical:'c-red',High:'c-amb',Medium:'c-blu',Low:'c-gry',Normal:'c-gry',Urgent:'c-red','High Value':'c-pur',Confidential:'c-pur'};
    return `<span class="chip ${m[p]||'c-gry'}">${esc(p)}</span>`;
}
function progBar(pct, color='var(--grn)') {
    return `<div class="prog-wrap"><div class="prog-track"><div class="prog-fill" style="width:${Math.min(pct,100)}%;background:${color}"></div></div><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;min-width:36px;text-align:right;">${pct}%</span></div>`;
}

// ── CLOCK ────────────────────────────────────────────────────────────────────
function updateClock(){
    document.getElementById('liveClock').textContent =
        new Date().toLocaleString('en-PH',{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock,1000); updateClock();

// ── MODULE FILTER ─────────────────────────────────────────────────────────────
function setMod(m, el){
    document.querySelectorAll('.mod-tog').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    document.querySelectorAll('.mod-section').forEach(s=>{
        s.style.display = (m==='ALL'||m===s.dataset.mod) ? '' : 'none';
    });
}

// ── KPI ───────────────────────────────────────────────────────────────────────
async function loadKPI(){
    try {
        const k = await apiGet(API+'?api=kpi');
        const slaPct = k.plt.slaPct;
        document.getElementById('kpiBar').innerHTML=`
            <div class="kpi-card kc-grn">
                <div class="kpi-label">SWS Inventory</div>
                <div class="kpi-val">${k.sws.totalItems}</div>
                <div class="kpi-sub">${k.sws.lowStock} low · ${k.sws.outOfStock} out · ${k.sws.binOccPct}% bin occ.</div>
                <div class="kpi-bar-fill"><div class="kpi-bar-inner" style="width:${k.sws.totalItems>0?Math.round(((k.sws.totalItems-k.sws.outOfStock)/k.sws.totalItems)*100):0}%;background:var(--grn)"></div></div>
            </div>
            <div class="kpi-card kc-tel">
                <div class="kpi-label">PSM Pipeline</div>
                <div class="kpi-val">${k.psm.pendingPRs}</div>
                <div class="kpi-sub">Pending PRs · ${k.psm.approvedPRs} approved</div>
            </div>
            <div class="kpi-card kc-pur">
                <div class="kpi-label">PLT Delivery SLA</div>
                <div class="kpi-val">${slaPct}<span style="font-size:14px;color:var(--t2);">%</span></div>
                <div class="kpi-sub">${k.plt.activeDeliveries} active · ${k.plt.lateDeliveries} late</div>
                <div class="kpi-bar-fill"><div class="kpi-bar-inner" style="width:${slaPct}%;background:${slaPct<85?'var(--red)':slaPct<95?'var(--amb)':'var(--grn)'}"></div></div>
            </div>
            <div class="kpi-card kc-amb">
                <div class="kpi-label">ALMS Asset Health</div>
                <div class="kpi-val">${k.alms.assetAvailPct}<span style="font-size:14px;color:var(--t2);">%</span></div>
                <div class="kpi-sub">${k.alms.totalAssets} assets · ${k.alms.maintDue} maint. due</div>
                <div class="kpi-bar-fill"><div class="kpi-bar-inner" style="width:${k.alms.assetAvailPct}%;background:var(--amb)"></div></div>
            </div>
            <div class="kpi-card kc-red">
                <div class="kpi-label">Alerts</div>
                <div class="kpi-val">${k.alerts.total}</div>
                <div class="kpi-sub">${k.alerts.critical} critical · ${k.alerts.high} high</div>
                <div style="display:flex;gap:5px;margin-top:8px;flex-wrap:wrap;">
                    ${k.alerts.critical>0?`<span class="chip c-red">${k.alerts.critical} Critical</span>`:''}
                    ${k.alerts.high>0?`<span class="chip c-amb">${k.alerts.high} High</span>`:''}
                </div>
            </div>`;

        // Render pipeline from KPI data
        renderPipeline(k.psm.pipeline);
        renderHealthGrid(k);
    } catch(e){ toast('KPI load failed: '+e.message,'d'); }
}

// ── PSM PIPELINE ──────────────────────────────────────────────────────────────
function renderPipeline(pipeline){
    if(!pipeline) return;
    const COLORS = {'Filed':'#6B7280','Approved':'#2563EB','PO Issued':'#0D9488','Delivered':'#22C55E','Closed':'#166534'};
    const total  = Object.values(pipeline).reduce((s,v)=>s+v,0);
    if(!total){ document.getElementById('psmPipeline').innerHTML='<div style="background:#E5E7EB;width:100%;height:100%;border-radius:8px;"></div>'; return; }
    document.getElementById('psmPipeline').innerHTML = Object.entries(pipeline).map(([label,count])=>{
        const pct = Math.round(count/total*100);
        return `<div class="pipe-seg" style="width:${pct}%;background:${COLORS[label]||'#6B7280'};" title="${label}: ${count}" onclick="toast('${label}: ${count} (${pct}%)','s')">${pct>8?count:''}</div>`;
    }).join('');
    document.getElementById('psmLegend').innerHTML = Object.entries(pipeline).map(([label,count])=>
        `<div class="pl-item"><div class="pl-dot" style="background:${COLORS[label]||'#6B7280'}"></div>${label} (${count})</div>`
    ).join('');
}

// ── HEALTH GRID ───────────────────────────────────────────────────────────────
function renderHealthGrid(k){
    const modules = [
        {label:'SWS',  val:k.sws.totalItems,     sub:'Active items',       color:'#2563EB'},
        {label:'PSM',  val:k.psm.pendingPRs,      sub:'Pending PRs',        color:'#0D9488'},
        {label:'PLT',  val:k.plt.activeDeliveries,sub:'Active deliveries',  color:'#7C3AED'},
        {label:'ALMS', val:k.alms.totalAssets,    sub:'Total assets',       color:'#D97706'},
        {label:'DTRS', val:k.dtrs.docPending,     sub:'Docs in queue',      color:'#DC2626'},
    ];
    const healthy = modules.filter(m=>m.val>=0).length;
    document.getElementById('healthOverallChip').textContent = `${healthy}/${modules.length} Modules`;
    document.getElementById('healthGrid').innerHTML = modules.map(m=>`
        <div class="hc">
          <div class="hc-t">${m.label}</div>
          <div class="hc-s">${m.sub}</div>
          <div class="hc-prog"><div class="hc-bar" style="width:100%;background:${m.color}"></div></div>
          <div class="hc-val" style="color:${m.color}">${m.val}</div>
        </div>`).join('');
}

// ── SWS ───────────────────────────────────────────────────────────────────────
async function loadSWSInventory(){
    const status = document.getElementById('swsStatusFilter').value;
    const zone   = document.getElementById('swsZoneFilter').value;
    document.getElementById('swsInvTbody').innerHTML=spin(6);
    try {
        const url = API+'?api=sws-inventory'+(zone?'&zone='+encodeURIComponent(zone):'');
        CACHE.inventory = await apiGet(url);
        // Populate zone filters
        const zones=[...new Set(CACHE.inventory.map(i=>i.zone).filter(Boolean))];
        ['swsZoneFilter','rptInvZone'].forEach(id=>{
            const el=document.getElementById(id); if(!el)return;
            const cv=el.value;
            el.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option ${z===cv?'selected':''}>${esc(z)}</option>`).join('');
        });
        let rows = CACHE.inventory;
        if(status) rows=rows.filter(r=>r.status===status);
        document.getElementById('swsInvTbody').innerHTML = rows.length
            ? rows.slice(0,30).map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.code)}</td>
                <td style="font-weight:600;">${esc(r.name)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.zone||'—')}</td>
                <td style="font-family:'DM Mono',monospace;font-weight:700;">${r.stock} <span style="font-size:10.5px;color:var(--t3);">${esc(r.uom||'')}</span></td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;color:var(--t2);">${r.minLevel} / ${r.rop}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : empty('No items match this filter','bx-package',6);
        // Mirror to report
        renderRptInventory();
    } catch(e){ document.getElementById('swsInvTbody').innerHTML=`<tr><td colspan="6" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadSWSBins(){
    document.getElementById('swsBinsBody').innerHTML='<div class="loading-row"><span class="spin"></span>Loading…</div>';
    try {
        CACHE.bins = await apiGet(API+'?api=sws-bins');
        if(!CACHE.bins.length){ document.getElementById('swsBinsBody').innerHTML='<div class="empty-state"><i class="bx bx-grid-alt"></i><p>No bins found</p></div>'; return; }
        document.getElementById('swsBinsBody').innerHTML = CACHE.bins.slice(0,12).map(b=>`
            <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--bd);">
              <div style="width:50px;font-family:'DM Mono',monospace;font-size:10.5px;font-weight:700;color:var(--grn);flex-shrink:0;">${esc(b.code)}</div>
              <div style="flex:1;">${progBar(b.utilPct, b.utilPct>90?'var(--red)':b.utilPct>70?'var(--amb)':'var(--grn)')}</div>
              <div style="flex-shrink:0;">${statusChip(b.status)}</div>
            </div>`).join('');
    } catch(e){ document.getElementById('swsBinsBody').innerHTML=`<div style="color:var(--red);font-size:12.5px;padding:12px;">${esc(e.message)}</div>`; }
}

// ── PSM ───────────────────────────────────────────────────────────────────────
async function loadPSMPRs(){
    const status = document.getElementById('psmPRFilter').value;
    document.getElementById('psmPRTbody').innerHTML=spin(7);
    try {
        const url = API+'?api=psm-prs'+(status?'&status='+encodeURIComponent(status):'');
        CACHE.prs = await apiGet(url);
        document.getElementById('psmPRTbody').innerHTML = CACHE.prs.length
            ? CACHE.prs.slice(0,20).map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.pr_number)}</td>
                <td style="font-weight:600;">${esc(r.requestor_name)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.department)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.date_filed)}</td>
                <td style="font-family:'DM Mono',monospace;">${r.item_count}</td>
                <td style="font-family:'DM Mono',monospace;font-weight:700;">${fM(r.total_amount)}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : empty('No purchase requests','bx-receipt',7);
        // Mirror to report
        document.getElementById('rptPSMTbody').innerHTML = CACHE.prs.map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;">${esc(r.pr_number)}</td>
            <td>${esc(r.requestor_name)}</td><td>${esc(r.department)}</td>
            <td>${fD(r.date_filed)}</td><td style="font-family:'DM Mono',monospace;">${fM(r.total_amount)}</td>
            <td>${statusChip(r.status)}</td></tr>`).join('') || empty('No data','bx-receipt',6);
    } catch(e){ document.getElementById('psmPRTbody').innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadPSMPOs(){
    const status = document.getElementById('psmPOFilter').value;
    document.getElementById('psmPOTbody').innerHTML=spin(9);
    try {
        const url = API+'?api=psm-pos'+(status?'&status='+encodeURIComponent(status):'');
        CACHE.pos = await apiGet(url);
        document.getElementById('psmPOTbody').innerHTML = CACHE.pos.length
            ? CACHE.pos.slice(0,20).map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.po_number)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.pr_reference)}</td>
                <td style="font-weight:600;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.supplier_name)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.issued_by)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.date_issued)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.delivery_date)}</td>
                <td style="font-family:'DM Mono',monospace;font-weight:700;">${fM(r.total_amount)}</td>
                <td style="min-width:110px;">${progBar(r.fulfill_pct||0,'var(--tel)')}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : empty('No purchase orders','bx-purchase-tag',9);
    } catch(e){ document.getElementById('psmPOTbody').innerHTML=`<tr><td colspan="9" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── PLT ───────────────────────────────────────────────────────────────────────
async function loadPLTDeliveries(){
    const status = document.getElementById('pltDelivFilter').value;
    document.getElementById('pltDelivTbody').innerHTML=spin(5);
    try {
        const url = API+'?api=plt-deliveries'+(status?'&status='+encodeURIComponent(status):'');
        CACHE.deliveries = await apiGet(url);
        document.getElementById('pltDelivTbody').innerHTML = CACHE.deliveries.length
            ? CACHE.deliveries.slice(0,15).map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.delivery_id)}</td>
                <td style="font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.supplier)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;color:${r.is_late?'var(--red)':''};">${fD(r.expected_date)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.assigned_to)}</td>
                <td>${statusChip(r.status)} ${r.is_late?'<span class="chip c-red" style="font-size:9.5px;">Late</span>':''}</td>
              </tr>`).join('')
            : empty('No deliveries found','bx-trip',5);
    } catch(e){ document.getElementById('pltDelivTbody').innerHTML=`<tr><td colspan="5" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadPLTProjects(){
    document.getElementById('pltProjTbody').innerHTML=spin(5);
    try {
        CACHE.projects = await apiGet(API+'?api=plt-projects');
        document.getElementById('pltProjTbody').innerHTML = CACHE.projects.length
            ? CACHE.projects.slice(0,10).map(r=>{
                const budgPct = r.budget>0?Math.min(100,Math.round(r.spend/r.budget*100)):0;
                const budgClr = budgPct>100?'var(--red)':budgPct>85?'var(--amb)':'var(--grn)';
                return `<tr>
                    <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.project_id)}</td>
                    <td style="font-weight:600;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.name)}</td>
                    <td style="min-width:110px;">${progBar(r.progress||0,'var(--grn)')}</td>
                    <td style="min-width:110px;">${progBar(budgPct,budgClr)}</td>
                    <td>${statusChip(r.status)}</td>
                  </tr>`;
              }).join('')
            : empty('No active projects','bx-briefcase-alt-2',5);
    } catch(e){ document.getElementById('pltProjTbody').innerHTML=`<tr><td colspan="5" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── ALMS ──────────────────────────────────────────────────────────────────────
async function loadALMSAssets(){
    const status = document.getElementById('almsAssetFilter').value;
    document.getElementById('almsAssetTbody').innerHTML=spin(7);
    try {
        const url = API+'?api=alms-assets'+(status?'&status='+encodeURIComponent(status):'');
        CACHE.assets = await apiGet(url);
        document.getElementById('almsAssetTbody').innerHTML = CACHE.assets.length
            ? CACHE.assets.slice(0,20).map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.asset_id)}</td>
                <td style="font-weight:600;">${esc(r.name)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.category)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.zone)}</td>
                <td>${statusChip(r.condition)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fM(r.current_value)}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : empty('No assets found','bx-cube-alt',7);
        // Mirror to ALMS report
        document.getElementById('rptALMSTbody').innerHTML = CACHE.assets.map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.asset_id)}</td>
            <td>${esc(r.name)}</td><td>${esc(r.category)}</td><td>${esc(r.zone)}</td>
            <td>${statusChip(r.condition)}</td><td style="font-family:'DM Mono',monospace;">${fM(r.current_value)}</td>
            <td>${statusChip(r.status)}</td></tr>`).join('') || empty('No data','bx-cube-alt',7);
    } catch(e){ document.getElementById('almsAssetTbody').innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadALMSMaintenance(){
    document.getElementById('almsMaintTbody').innerHTML=spin(5);
    try {
        const [maint, repairs] = await Promise.all([
            apiGet(API+'?api=alms-maintenance'),
            apiGet(API+'?api=alms-repairs'),
        ]);
        CACHE.maintenance = maint;
        CACHE.repairs     = repairs;
        const today = new Date().toISOString().split('T')[0];
        const maintRows = maint.slice(0,8).map(r=>{
            const over = r.next_due && r.next_due < today;
            return `<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--grn);">${esc(r.schedule_id)}</td>
                <td style="font-weight:600;">${esc(r.asset_name)}</td>
                <td style="font-size:11.5px;">${esc(r.type)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;color:${over?'var(--red)':''};">${fD(r.next_due)}</td>
                <td>${statusChip(r.status)}</td></tr>`;
        });
        const repairRows = repairs.slice(0,8).map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--red);">${esc(r.log_id)}</td>
            <td style="font-weight:600;">${esc(r.asset_name)}</td>
            <td style="font-size:11.5px;color:var(--t2);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.issue)}</td>
            <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.date_reported)}</td>
            <td>${statusChip(r.status)}</td></tr>`);
        document.getElementById('almsMaintTbody').innerHTML = [...maintRows,...repairRows].join('') || empty('No maintenance items','bx-wrench',5);
    } catch(e){ document.getElementById('almsMaintTbody').innerHTML=`<tr><td colspan="5" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── DTRS ──────────────────────────────────────────────────────────────────────
async function loadDTRS(){
    document.getElementById('dtrsTbody').innerHTML=spin(9);
    try {
        CACHE.docs = await apiGet(API+'?api=dtrs-docs');
        document.getElementById('dtrsTbody').innerHTML = CACHE.docs.length
            ? CACHE.docs.slice(0,20).map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.doc_id)}</td>
                <td style="font-weight:600;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.title)}</td>
                <td style="font-size:11.5px;">${esc(r.doc_type)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.department)}</td>
                <td><span class="chip ${r.direction==='Incoming'?'c-blu':'c-tel'}">${esc(r.direction)}</span></td>
                <td>${priorityChip(r.priority)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.assigned_to||'—')}</td>
                <td>${r.needs_validation?'<span class="chip c-amb">Needed</span>':'<span class="chip c-grn">Clear</span>'}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : empty('No documents in queue','bx-file-blank',9);
    } catch(e){ document.getElementById('dtrsTbody').innerHTML=`<tr><td colspan="9" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── ALERTS ────────────────────────────────────────────────────────────────────
async function loadAlerts(){
    const mod = document.getElementById('alertModFilter').value;
    const el  = document.getElementById('alertInbox');
    el.innerHTML='<div class="loading-row"><span class="spin"></span>Loading…</div>';
    try {
        const url = API+'?api=alerts'+(mod?'&module='+encodeURIComponent(mod):'');
        CACHE.alerts = await apiGet(url);
        document.getElementById('alertCountChip').textContent = CACHE.alerts.length+' Unread';
        if(!CACHE.alerts.length){ el.innerHTML='<div class="empty-state"><i class="bx bx-bell-off"></i><p>No active alerts</p></div>'; return; }
        const sevIcon={Critical:'bx-error-circle',High:'bx-error',Medium:'bx-time-five',Low:'bx-info-circle'};
        const sevCls ={Critical:'al-r',High:'al-a',Medium:'al-b',Low:'al-g'};
        el.innerHTML = CACHE.alerts.slice(0,12).map(a=>`
            <div class="audit-item" id="alert-row-${a.id}">
              <div class="audit-dot ${sevCls[a.severity]||'al-b'}"><i class="bx ${sevIcon[a.severity]||'bx-bell'}"></i></div>
              <div class="audit-body">
                <div class="au">${esc(a.title)}</div>
                <div class="at">
                  <span style="background:#F3F4F6;padding:1px 5px;border-radius:4px;font-size:10px;">${a.module}</span>
                  <span class="chip ${a.severity==='Critical'?'c-red':a.severity==='High'?'c-amb':'c-blu'}" style="font-size:9.5px;">${a.severity}</span>
                </div>
                <div style="display:flex;gap:5px;margin-top:6px;">
                  <button class="btn btn-warn btn-xs" onclick="escalateAlert(${a.id})"><i class="bx bx-up-arrow-circle"></i> Escalate</button>
                  <button class="btn btn-ghost btn-xs" onclick="dismissAlert(${a.id})"><i class="bx bx-check"></i> Dismiss</button>
                </div>
              </div>
              <div class="audit-ts">${fDT(a.created_at)}</div>
            </div>`).join('');
    } catch(e){ el.innerHTML=`<div style="color:var(--red);font-size:12.5px;padding:12px;">${esc(e.message)}</div>`; }
}

async function dismissAlert(id){
    try {
        await apiPost(API+'?api=alert-action',{id,type:'dismiss'});
        document.getElementById('alert-row-'+id)?.remove();
        CACHE.alerts=CACHE.alerts.filter(a=>a.id!==id);
        document.getElementById('alertCountChip').textContent=CACHE.alerts.length+' Unread';
        toast('Alert dismissed.','s');
    } catch(e){ toast(e.message,'d'); }
}

function escalateAlert(id){
    const a=CACHE.alerts.find(x=>x.id===id); if(!a) return;
    openModal({
        title:'Escalate Alert',sub:a.title,wide:false,
        body:`<div class="fn"><i class="bx bx-info-circle" style="vertical-align:middle;margin-right:5px;"></i>${esc(a.title)} · <strong>${a.severity}</strong> · ${a.module}</div>
              <div class="fg"><label>Priority</label><select id="escPri"><option>High</option><option>Critical</option><option>Medium</option></select></div>
              <div class="fg"><label>Remarks</label><textarea id="escRmk" placeholder="Reason for escalation…"></textarea></div>`,
        foot:`<button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
              <button class="btn btn-warn btn-sm" onclick="doEscalate(${id})"><i class="bx bx-up-arrow-circle"></i> Escalate</button>`,
    });
}
async function doEscalate(id){
    const priority=document.getElementById('escPri')?.value||'High';
    const remarks =document.getElementById('escRmk')?.value.trim()||'';
    try {
        await apiPost(API+'?api=alert-action',{id,type:'escalate',priority,remarks});
        document.getElementById('alert-row-'+id)?.remove();
        CACHE.alerts=CACHE.alerts.filter(a=>a.id!==id);
        document.getElementById('alertCountChip').textContent=CACHE.alerts.length+' Unread';
        closeModal(); toast('Alert escalated.','w');
    } catch(e){ toast(e.message,'d'); }
}

// ── AUDIT FEED ────────────────────────────────────────────────────────────────
async function loadAuditFeed(){
    const mod = document.getElementById('auditFeedMod').value;
    const el  = document.getElementById('auditFeedBody');
    el.innerHTML='<div class="loading-row"><span class="spin"></span>Loading…</div>';
    try {
        const url = API+'?api=audit-feed&limit=20'+(mod?'&module='+encodeURIComponent(mod):'');
        const rows = await apiGet(url);
        if(!rows.length){ el.innerHTML='<div class="empty-state"><i class="bx bx-history"></i><p>No recent activity</p></div>'; return; }
        const modIcons={SWS:'bx-package',PSM:'bx-receipt',PLT:'bx-trip',ALMS:'bx-wrench',DTRS:'bx-file-blank','User Mgmt':'bx-user',System:'bx-shield-alt-2'};
        const typeCls ={Create:'ad-c',Edit:'ad-s',Approve:'ad-a',Delete:'ad-r'};
        el.innerHTML = rows.map(r=>`
            <div class="audit-item">
              <div class="audit-dot ${typeCls[r.action_type]||'ad-s'}"><i class="bx ${modIcons[r.module]||'bx-info-circle'}"></i></div>
              <div class="audit-body">
                <div class="au">${esc(r.action_label)} ${r.is_super_admin?'<span style="font-size:9.5px;font-weight:700;background:#FEF3C7;color:#92400E;padding:1px 5px;border-radius:4px;">SA</span>':''}</div>
                <div class="at">
                  <i class="bx bx-user" style="font-size:11px;"></i>${esc(r.actor_name)}
                  <span style="background:#F3F4F6;padding:1px 5px;border-radius:4px;font-size:10px;">${r.module}</span>
                  <span style="font-size:10px;color:var(--t3);">${esc(r.record_ref||'')}</span>
                </div>
              </div>
              <div class="audit-ts">${fDT(r.occurred_at)}</div>
            </div>`).join('');
    } catch(e){ el.innerHTML=`<div style="color:var(--red);font-size:12.5px;padding:12px;">${esc(e.message)}</div>`; }
}

// ── FULL AUDIT TABLE ──────────────────────────────────────────────────────────
async function loadFullAudit(){
    const mod = document.getElementById('rptAuditMod').value;
    document.getElementById('rptAuditTbody').innerHTML=spin(7);
    try {
        const url = API+'?api=audit-feed&limit=50'+(mod?'&module='+encodeURIComponent(mod):'');
        const rows = await apiGet(url);
        document.getElementById('rptAuditTbody').innerHTML = rows.length
            ? rows.map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.log_id)}</td>
                <td><span style="background:#F3F4F6;padding:1px 7px;border-radius:5px;font-size:10.5px;font-weight:700;">${r.module}</span></td>
                <td style="font-weight:500;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.action_label)}</td>
                <td>${esc(r.actor_name)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.actor_role)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.record_ref||'—')}</td>
                <td style="font-family:'DM Mono',monospace;font-size:10.5px;color:var(--t3);">${fDT(r.occurred_at)}</td>
              </tr>`).join('')
            : empty('No audit entries','bx-history',7);
    } catch(e){ document.getElementById('rptAuditTbody').innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── USERS ─────────────────────────────────────────────────────────────────────
async function loadUsers(){
    const status = document.getElementById('userStatusFilter').value;
    document.getElementById('userTbody').innerHTML=spin(7);
    try {
        const url = API+'?api=users'+(status?'&status='+encodeURIComponent(status):'');
        const users = await apiGet(url);
        document.getElementById('userTbody').innerHTML = users.length
            ? users.map((u,i)=>{
                const ini=u.name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
                const clrs=['#2E7D32','#2563EB','#0D9488','#D97706','#7C3AED','#DC2626'];
                const roleHtml=(u.roles||[]).map(r=>{
                    const cls=r.includes('Super')?'rb-sa':r.includes('Admin')?'rb-ad':r.includes('Manager')?'rb-mg':'rb-st';
                    return `<span class="role-badge ${cls}">${esc(r)}</span>`;
                }).join(' ')||'<span style="font-size:11px;color:var(--t3);">No role</span>';
                return `<tr>
                    <td>
                      <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:28px;height:28px;border-radius:8px;background:${clrs[i%6]};display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;">${ini}</div>
                        <div>
                          <div style="font-size:12.5px;font-weight:700;color:var(--t1);">${esc(u.name)}</div>
                          <div style="font-size:10.5px;color:var(--t3);">${esc(u.empId||'')}</div>
                        </div>
                      </div>
                    </td>
                    <td>${roleHtml}</td>
                    <td style="font-size:11.5px;color:var(--t2);">${esc(u.zone)}</td>
                    <td style="font-size:11.5px;color:var(--t2);">${esc(u.email)}</td>
                    <td style="font-family:'DM Mono',monospace;font-size:10.5px;color:var(--t3);">${fDT(u.lastLogin)}</td>
                    <td>${statusChip(u.status)}</td>
                    <td>
                      <div style="display:flex;gap:4px;">
                        <button class="btn btn-ghost btn-xs ionly" title="Edit" onclick="openModal('editUser','${esc(u.userId)}','${esc(u.name)}')"><i class="bx bx-edit"></i></button>
                        <button class="btn btn-ghost btn-xs ionly" title="Reset Password" onclick="toast('Password reset email sent to ${esc(u.name)}.','s')"><i class="bx bx-key"></i></button>
                        <button class="btn ${u.status==='Active'?'btn-danger':'btn-ghost'} btn-xs ionly" title="${u.status==='Active'?'Deactivate':'Activate'}" onclick="toggleUserStatus('${esc(u.userId)}','${u.status==='Active'?'Inactive':'Active'}')"><i class="bx ${u.status==='Active'?'bx-user-x':'bx-user-check'}"></i></button>
                      </div>
                    </td>
                  </tr>`;
              }).join('')
            : empty('No users found','bx-group',7);
    } catch(e){ document.getElementById('userTbody').innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function toggleUserStatus(userId, newStatus){
    try {
        await apiPost(API+'?api=user-status',{userId,status:newStatus});
        toast(`User status changed to ${newStatus}.`,'s');
        loadUsers();
    } catch(e){ toast(e.message,'d'); }
}

// ── TRACEABILITY ──────────────────────────────────────────────────────────────
async function loadTraceability(){
    try {
        CACHE.traceability = await apiGet(API+'?api=traceability');
        document.getElementById('rptTraceTbody').innerHTML = CACHE.traceability.length
            ? CACHE.traceability.map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--grn);">${esc(r.poNumber)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.prRef)}</td>
                <td style="font-weight:500;">${esc(r.supplier)}</td>
                <td>${statusChip(r.poStatus)}</td>
              </tr>`).join('')
            : empty('No traceability data','bx-git-merge',4);
    } catch(e){ /* silent — tab may not be open */ }
}

// ── REPORT HELPERS ────────────────────────────────────────────────────────────
function renderRptInventory(){
    const zone   = document.getElementById('rptInvZone')?.value||'';
    const status = document.getElementById('rptInvStatus')?.value||'';
    let rows = CACHE.inventory;
    if(zone)   rows=rows.filter(r=>r.zone===zone);
    if(status) rows=rows.filter(r=>r.status===status);
    document.getElementById('rptInvTbody').innerHTML = rows.map(r=>`<tr>
        <td style="font-family:'DM Mono',monospace;font-size:11px;color:var(--grn);font-weight:700;">${esc(r.code)}</td>
        <td style="font-weight:600;">${esc(r.name)}</td>
        <td>${esc(r.category)}</td><td>${esc(r.zone||'—')}</td>
        <td style="font-family:'DM Mono',monospace;font-weight:700;">${r.stock}</td>
        <td style="font-family:'DM Mono',monospace;">${r.minLevel}</td>
        <td style="font-family:'DM Mono',monospace;">${r.rop}</td>
        <td>${statusChip(r.status)}</td></tr>`).join('') || empty('No items match filters','bx-package',8);
}

function setRptTab(name,el){
    document.querySelectorAll('.rpt-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.rpt-panel').forEach(p=>p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('rpt-'+name).classList.add('active');
    if(name==='audit') loadFullAudit();
    if(name==='trace') loadTraceability();
}

function exportTable(tableId, filename){
    const tbl=document.getElementById(tableId); if(!tbl){toast('Table not found','d');return;}
    const rows=[];
    tbl.querySelectorAll('thead tr').forEach(r=>rows.push([...r.querySelectorAll('th')].map(c=>'"'+c.textContent.trim().replace(/"/g,'""')+'"').join(',')));
    tbl.querySelectorAll('tbody tr').forEach(r=>rows.push([...r.querySelectorAll('td')].map(c=>'"'+c.textContent.trim().replace(/"/g,'""')+'"').join(',')));
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download=filename+'.csv'; a.click();
    toast('Exported: '+filename+'.csv','s');
}

// ── CONFIG & NOTIFICATIONS (static toggles — no backend writes needed) ────────
function renderConfig(){
    const CFG=[
        {label:'Zone Reorder Point',    sub:'Stock threshold before PR trigger',    key:'rop',  val:20,unit:'units'},
        {label:'Zone Budget Alert',     sub:'Warn when spend exceeds % of budget',  key:'budg', val:85,unit:'%'},
        {label:'Zone SLA — Delivery',   sub:'Min delivery compliance target',       key:'sla',  val:95,unit:'%'},
        {label:'Low Stock Alert Level', sub:'SWS: alert fires below this level',    key:'ls',   val:8, unit:'units'},
        {label:'PR Approval Timeout',   sub:'Escalate if no action within N days',  key:'pto',  val:3, unit:'days'},
    ];
    document.getElementById('cfgRows').innerHTML=CFG.map(c=>`
        <div class="cfg-row"><div class="cfg-l"><div class="cfg-t">${c.label}</div><div class="cfg-s">${c.sub}</div></div>
          <div class="cfg-v"><input type="number" class="cfg-input" id="cfg-${c.key}" value="${c.val}"><span style="font-size:11.5px;color:var(--t2);">${c.unit}</span></div>
        </div>`).join('');
}

function renderNotifications(){
    const TGLS=[
        {label:'Email on Critical Alert', sub:'Zone-level critical alert → email',       on:true},
        {label:'Auto-PR on Low Stock',    sub:'Auto-generate zone PR at ROP threshold',   on:true},
        {label:'Zone SLA Breach Alert',   sub:'Notify when SLA falls below target',       on:true},
        {label:'Daily Zone Summary',      sub:'7 AM daily zone operations summary',       on:false},
    ];
    document.getElementById('notifRows').innerHTML=TGLS.map((t,i)=>`
        <div class="cfg-row"><div class="cfg-l"><div class="cfg-t">${t.label}</div><div class="cfg-s">${t.sub}</div></div>
          <div class="tgl ${t.on?'on':'off'}" id="tgl-n${i}" onclick="tglNotif('n${i}')"><div class="tgl-knob"></div></div>
        </div>`).join('');
}
function tglNotif(id){
    const el=document.getElementById('tgl-'+id);
    el.classList.toggle('on'); el.classList.toggle('off');
    toast('Notification preference saved.','s');
}

// ── MODAL ─────────────────────────────────────────────────────────────────────
function openModal({title,sub,wide,body,foot}){
    document.getElementById('mgModalBox')?.classList.toggle('wide',!!wide);
    document.getElementById('modalBox')?.classList.toggle('wide',!!wide);
    document.getElementById('mhdTitle').textContent=title||'';
    document.getElementById('mhdSub').textContent=sub||'';
    document.getElementById('mBody').innerHTML=body||'';
    document.getElementById('mFoot').innerHTML=foot||'<button class="btn btn-ghost btn-sm" onclick="closeModal()">Close</button>';
    document.getElementById('modalOverlay').classList.add('on');
}

// Override for addUser / editUser signatures
const _openModal = openModal;
window.openModal = function(type, userId, userName) {
    if(type==='addUser'){
        _openModal({
            title:'Add Zone User',sub:'User will be scoped to this zone only.',wide:false,
            body:`<div class="fr2">
                    <div class="fg"><label>First Name *</label><input id="newFName" placeholder="Juan"></div>
                    <div class="fg"><label>Last Name *</label><input id="newLName" placeholder="Dela Cruz"></div>
                  </div>
                  <div class="fg"><label>Email *</label><input type="email" id="newEmail" placeholder="user@company.ph"></div>
                  <div class="fr2">
                    <div class="fg"><label>Zone *</label><input id="newZone" placeholder="NCR Zone 1"></div>
                    <div class="fg"><label>Employee ID</label><input id="newEmpId" placeholder="EMP-0001"></div>
                  </div>
                  <div class="fn"><i class="bx bx-info-circle" style="vertical-align:middle;margin-right:5px;"></i>A temporary password will be emailed. Role assignment happens after creation. Super Admin roles require Super Admin access.</div>`,
            foot:`<button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
                  <button class="btn btn-primary btn-sm" onclick="closeModal();toast('User creation requires direct Supabase Auth setup. Open Supabase dashboard to create the auth user, then add to the users table.','w')"><i class="bx bx-user-plus"></i> Add User</button>`,
        });
    } else if(type==='editUser'){
        _openModal({
            title:'Change User Status — '+(userName||''),sub:'Status change is logged to the audit trail.',wide:false,
            body:`<div class="fg"><label>New Status *</label>
                    <select id="newUserStatus">
                      <option>Active</option><option>Inactive</option><option>Suspended</option><option>Locked</option>
                    </select>
                  </div>
                  <div class="fn"><i class="bx bx-info-circle" style="vertical-align:middle;margin-right:5px;"></i>Role changes require the RPM module. This form handles status changes only.</div>`,
            foot:`<button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
                  <button class="btn btn-primary btn-sm" onclick="doUserStatusModal('${userId}')"><i class="bx bx-save"></i> Apply Change</button>`,
        });
    } else {
        _openModal({title:type,sub:'',wide:false,body:'',foot:'<button class="btn btn-ghost btn-sm" onclick="closeModal()">Close</button>'});
    }
};

async function doUserStatusModal(userId){
    const status=document.getElementById('newUserStatus')?.value;
    if(!status){toast('Select a status','w');return;}
    await toggleUserStatus(userId,status);
    closeModal();
}

function closeModal(){ document.getElementById('modalOverlay').classList.remove('on'); }
document.getElementById('modalOverlay').addEventListener('click',function(e){if(e.target===this)closeModal();});

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg,type='s'){
    const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
    const el=document.createElement('div');
    el.className='toast t'+type;
    el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),320);},3500);
}

// ── REFRESH ALL ───────────────────────────────────────────────────────────────
function refreshAll(){
    loadKPI();
    loadSWSInventory().then(()=>loadSWSBins());
    loadPSMPRs();
    loadPSMPOs();
    loadPLTDeliveries();
    loadPLTProjects();
    loadALMSAssets();
    loadALMSMaintenance();
    loadDTRS();
    loadAlerts();
    loadAuditFeed();
    loadUsers();
    toast('Dashboard refreshed.','s');
}

// ── INIT ──────────────────────────────────────────────────────────────────────
(async()=>{
    try {
        const zones = await apiGet(API+'?api=zones');
        CACHE.zones = zones;
        document.getElementById('zonePillLabel').textContent =
            zones.length ? zones.length+' Zones' : 'Zone Data';
    } catch(e){ document.getElementById('zonePillLabel').textContent='Zone Data'; }

    renderConfig();
    renderNotifications();

    await Promise.allSettled([
        loadKPI(),
        loadSWSInventory().then(()=>loadSWSBins()),
        loadPSMPRs(),
        loadPSMPOs(),
        loadPLTDeliveries(),
        loadPLTProjects(),
        loadALMSAssets(),
        loadALMSMaintenance(),
        loadDTRS(),
        loadAlerts(),
        loadAuditFeed(),
        loadUsers(),
    ]);
})();

// Auto-refresh KPIs + alerts every 3 minutes
setInterval(()=>{ loadKPI(); loadAlerts(); loadAuditFeed(); }, 3*60*1000);
</script>
</body>
</html>