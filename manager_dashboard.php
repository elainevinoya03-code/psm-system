<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── SESSION GUARD ─────────────────────────────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

if (empty($_SESSION['user_id'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();

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
function mg_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function mg_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function mg_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
    $zone   = $_SESSION['zone'] ?? '';  // Manager's assigned zone

    try {

        // ── GET: KPI summary ─────────────────────────────────────────────────
        if ($api === 'kpi' && $method === 'GET') {
            // SWS: inventory stats
            $inv = mg_sb('sws_inventory', 'GET', [
                'select' => 'id,stock,min_level,zone,active',
                'active' => 'eq.true',
            ]);
            $lowStock   = count(array_filter($inv, fn($i) => $i['stock'] <= $i['min_level'] && $i['stock'] > 0));
            $outOfStock = count(array_filter($inv, fn($i) => $i['stock'] == 0));
            $totalItems = count($inv);

            // PSM: PRs pending
            $prs = mg_sb('psm_purchase_requests', 'GET', [
                'select' => 'id,status',
                'status' => 'in.(Pending,Draft)',
            ]);

            // PLT: deliveries
            $deliveries = mg_sb('plt_deliveries', 'GET', [
                'select' => 'id,status,is_late,expected_date',
            ]);
            $activeDeliveries = count(array_filter($deliveries, fn($d) => in_array($d['status'], ['Scheduled','In Transit'])));
            $lateDeliveries   = count(array_filter($deliveries, fn($d) => $d['is_late']));

            // ALMS: maintenance due + assets
            $maintenance = mg_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,status,next_due',
                'status' => 'in.(Scheduled,Overdue)',
            ]);
            $mntDue = count(array_filter($maintenance, fn($m) =>
                $m['status'] === 'Overdue' || ($m['next_due'] && $m['next_due'] <= date('Y-m-d', strtotime('+7 days')))
            ));

            // DTRS: documents pending
            $docs = mg_sb('dtrs_documents', 'GET', [
                'select' => 'id,status,needs_validation',
                'status' => 'in.(Registered,In Transit,Processing)',
            ]);
            $docFlags = count(array_filter($docs, fn($d) => $d['needs_validation']));

            // Notifications: unread alerts
            $notifs = mg_sb('notifications', 'GET', [
                'select'   => 'id,severity,module,category',
                'status'   => 'eq.unread',
                'order'    => 'created_at.desc',
                'limit'    => '50',
            ]);
            $critAlerts = count(array_filter($notifs, fn($n) => $n['severity'] === 'Critical'));
            $highAlerts = count(array_filter($notifs, fn($n) => $n['severity'] === 'High'));

            mg_ok([
                'sws'      => ['totalItems' => $totalItems, 'lowStock' => $lowStock, 'outOfStock' => $outOfStock],
                'psm'      => ['pendingPRs' => count($prs)],
                'plt'      => ['activeDeliveries' => $activeDeliveries, 'lateDeliveries' => $lateDeliveries],
                'alms'     => ['maintenanceDue' => $mntDue],
                'dtrs'     => ['docsPending' => count($docs), 'docFlags' => $docFlags],
                'alerts'   => ['total' => count($notifs), 'critical' => $critAlerts, 'high' => $highAlerts],
            ]);
        }

        // ── GET: Notifications / Alerts ──────────────────────────────────────
        if ($api === 'alerts' && $method === 'GET') {
            $query = [
                'select' => 'id,notif_id,category,module,severity,title,description,zone,status,source_table,created_at',
                'status' => 'eq.unread',
                'order'  => 'created_at.desc',
                'limit'  => '20',
            ];
            if (!empty($_GET['module'])) $query['module'] = 'eq.' . $_GET['module'];
            $rows = mg_sb('notifications', 'GET', $query);
            mg_ok($rows);
        }

        // ── POST: Dismiss / Escalate alert ───────────────────────────────────
        if ($api === 'alert-action' && $method === 'POST') {
            $raw  = file_get_contents('php://input');
            $b    = json_decode($raw, true) ?: [];
            $id   = (int)($b['id'] ?? 0);
            $type = trim($b['type'] ?? '');
            $actor= $_SESSION['full_name'] ?? 'Manager';
            $now  = date('Y-m-d H:i:s');

            if (!$id)   mg_err('Missing id', 400);
            if (!$type) mg_err('Missing type', 400);

            if ($type === 'dismiss') {
                mg_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], [
                    'status'       => 'dismissed',
                    'dismissed_by' => $actor,
                    'dismissed_at' => $now,
                    'updated_at'   => $now,
                ]);
                mg_ok(['dismissed' => true]);
            }
            if ($type === 'escalate') {
                mg_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], [
                    'status'            => 'escalated',
                    'escalated_by'      => $actor,
                    'escalated_at'      => $now,
                    'escalate_priority' => trim($b['priority'] ?? 'High'),
                    'escalate_remarks'  => trim($b['remarks'] ?? ''),
                    'updated_at'        => $now,
                ]);
                mg_ok(['escalated' => true]);
            }
            mg_err('Unknown action type', 400);
        }

        // ── GET: SWS inventory with status ───────────────────────────────────
        if ($api === 'sws-inventory' && $method === 'GET') {
            $query = [
                'select' => 'id,code,name,category,zone,stock,min_level,max_level,active,updated_at',
                'active' => 'eq.true',
                'order'  => 'stock.asc',
                'limit'  => '50',
            ];
            if (!empty($_GET['zone'])) $query['zone'] = 'eq.' . $_GET['zone'];
            $rows = mg_sb('sws_inventory', 'GET', $query);
            mg_ok(array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'code'     => $r['code']      ?? '',
                'name'     => $r['name']      ?? '',
                'category' => $r['category']  ?? '',
                'zone'     => $r['zone']      ?? '',
                'stock'    => (int)($r['stock']     ?? 0),
                'minLevel' => (int)($r['min_level'] ?? 0),
                'maxLevel' => (int)($r['max_level'] ?? 0),
                'status'   => (int)($r['stock'] ?? 0) === 0 ? 'Out of Stock'
                            : ((int)($r['stock'] ?? 0) <= (int)($r['min_level'] ?? 0) ? 'Low Stock' : 'In Stock'),
            ], $rows));
        }

        // ── GET: PSM purchase requests ────────────────────────────────────────
        if ($api === 'psm-prs' && $method === 'GET') {
            $query = [
                'select' => 'id,pr_number,requestor_name,department,date_filed,status,total_amount,item_count',
                'order'  => 'date_filed.desc',
                'limit'  => '20',
            ];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            $rows = mg_sb('psm_purchase_requests', 'GET', $query);
            mg_ok($rows);
        }

        // ── GET: PLT deliveries ───────────────────────────────────────────────
        if ($api === 'plt-deliveries' && $method === 'GET') {
            $query = [
                'select' => 'id,delivery_id,supplier,po_ref,zone,assigned_to,expected_date,actual_date,is_late,status',
                'order'  => 'expected_date.asc',
                'limit'  => '20',
            ];
            if (!empty($_GET['status'])) $query['status'] = 'eq.' . $_GET['status'];
            $rows = mg_sb('plt_deliveries', 'GET', $query);
            mg_ok($rows);
        }

        // ── GET: ALMS maintenance due ─────────────────────────────────────────
        if ($api === 'alms-maintenance' && $method === 'GET') {
            $rows = mg_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_name,type,freq,zone,next_due,tech,status',
                'status' => 'in.(Scheduled,Overdue,In Progress)',
                'order'  => 'next_due.asc',
                'limit'  => '20',
            ]);
            mg_ok($rows);
        }

        // ── GET: DTRS document queue ──────────────────────────────────────────
        if ($api === 'dtrs-docs' && $method === 'GET') {
            $rows = mg_sb('dtrs_documents', 'GET', [
                'select' => 'id,doc_id,title,doc_type,department,priority,status,needs_validation,doc_date,assigned_to',
                'status' => 'in.(Registered,In Transit,Processing)',
                'order'  => 'doc_date.desc',
                'limit'  => '20',
            ]);
            mg_ok($rows);
        }

        // ── GET: PLT assignments ──────────────────────────────────────────────
        if ($api === 'plt-assignments' && $method === 'GET') {
            $rows = mg_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,task,assigned_to,zone,priority,due_date,status',
                'status' => 'neq.Completed',
                'order'  => 'due_date.asc',
                'limit'  => '30',
            ]);
            mg_ok($rows);
        }

        // ── GET: Unified recent audit feed ────────────────────────────────────
        if ($api === 'audit-feed' && $method === 'GET') {
            $limit  = (int)($_GET['limit'] ?? 15);
            $module = trim($_GET['module'] ?? '');
            $query  = [
                'select' => 'log_id,module,action_label,actor_name,actor_role,action_type,record_ref,occurred_at',
                'order'  => 'occurred_at.desc',
                'limit'  => (string)$limit,
            ];
            if ($module) $query['module'] = 'eq.' . $module;
            $rows = mg_sb('v_audit_unified', 'GET', $query);
            mg_ok($rows);
        }

        // ── GET: SWS zones list ───────────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $zones = mg_sb('sws_zones', 'GET', ['select' => 'id,name,color']);
            mg_ok($zones);
        }

        // ── GET: SWS bins with utilisation ────────────────────────────────────
        if ($api === 'bins' && $method === 'GET') {
            $query = [
                'select' => 'id,bin_id,code,zone,capacity,used,status,active',
                'active' => 'eq.true',
                'order'  => 'zone.asc,code.asc',
                'limit'  => '60',
            ];
            if (!empty($_GET['zone'])) $query['zone'] = 'eq.' . $_GET['zone'];
            $rows = mg_sb('sws_bins', 'GET', $query);
            mg_ok(array_map(fn($r) => [
                ...$r,
                'util_pct' => $r['capacity'] > 0
                    ? min(100, round(($r['used'] / $r['capacity']) * 100))
                    : 0,
            ], $rows));
        }

        // ── GET: Users in zone (team roster) ─────────────────────────────────
        if ($api === 'team' && $method === 'GET') {
            $query = ['select' => 'user_id,first_name,last_name,zone,status,last_login,emp_id'];
            if ($zone) $query['zone'] = 'eq.' . $zone;
            else       $query['status'] = 'eq.Active';
            $query['order'] = 'first_name.asc';
            $query['limit'] = '30';
            $rows = mg_sb('users', 'GET', $query);
            mg_ok(array_map(fn($r) => [
                'userId'    => $r['user_id']    ?? '',
                'name'      => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'zone'      => $r['zone']       ?? '',
                'status'    => $r['status']     ?? '',
                'lastLogin' => $r['last_login'] ?? null,
            ], $rows));
        }

        // ── GET: ALMS repair logs open ────────────────────────────────────────
        if ($api === 'alms-repairs' && $method === 'GET') {
            $rows = mg_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_name,zone,issue,date_reported,technician,status',
                'status' => 'in.(Reported,In Progress,Escalated)',
                'order'  => 'date_reported.desc',
                'limit'  => '20',
            ]);
            mg_ok($rows);
        }

        // ── GET: Projects active ──────────────────────────────────────────────
        if ($api === 'plt-projects' && $method === 'GET') {
            $rows = mg_sb('plt_projects', 'GET', [
                'select' => 'id,project_id,name,zone,manager,priority,start_date,end_date,progress,status,budget,spend',
                'status' => 'in.(Planning,Active,On Hold,Delayed)',
                'order'  => 'end_date.asc',
                'limit'  => '15',
            ]);
            mg_ok($rows);
        }

        mg_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        mg_err('Server error: ' . $e->getMessage(), 500);
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
<title>Manager Dashboard — LOG1</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
/* ── TOKENS ── */
*,*::before,*::after{box-sizing:border-box;}
#mainContent,#mgModal,#mgModalOverlay,.mg-toasts{
  --s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary,#1A2E1C);--t2:var(--text-secondary,#5D6F62);--t3:#9EB0A2;
  --hbg:var(--hover-bg-light,#F0FAF0);--bg:var(--bg-color,#F4F7F4);
  --grn:var(--primary-color,#2E7D32);--gdk:var(--primary-dark,#1B5E20);
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.2);
  --rad:12px;--tr:all .18s cubic-bezier(.4,0,.2,1);
}

/* ── LAYOUT ── */
.mg-wrap{max-width:1520px;margin:0 auto;padding:0 0 5rem;}
.mg-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;animation:UP .4s both;}
.mg-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.mg-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.mg-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.zone-pill{display:inline-flex;align-items:center;gap:5px;background:#E8F5E9;color:var(--grn);border:1.5px solid rgba(46,125,50,.3);font-size:11.5px;font-weight:700;padding:5px 12px;border-radius:20px;}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-danger{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}.btn-danger:hover{background:#FCA5A5;}
.btn-warn{background:#FFFBEB;color:#92400E;border:1px solid #FCD34D;}.btn-warn:hover{background:#FEF3C7;}
.btn-blu{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE;}.btn-blu:hover{background:#DBEAFE;}
.btn-sm{font-size:12px;padding:7px 14px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn:disabled{opacity:.4;pointer-events:none;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;border-radius:7px;}

/* ── KPI BAR ── */
.kpi-bar{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:22px;animation:UP .4s .06s both;}
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
.kpi-skeleton{background:linear-gradient(90deg,#E5E7EB 25%,#F3F4F6 50%,#E5E7EB 75%);background-size:200%;animation:SKEL 1.5s infinite;}
@keyframes SKEL{from{background-position:200% 0}to{background-position:-200% 0}}

/* ── MODULE FILTER TOGGLES ── */
.mod-toggles{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:20px;animation:UP .4s .03s both;}
.mod-tog{font-family:'Inter',sans-serif;font-size:12px;font-weight:700;padding:6px 14px;border-radius:20px;border:1.5px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;transition:var(--tr);}
.mod-tog:hover{border-color:var(--grn);color:var(--grn);}
.mod-tog.active{color:#fff;border-color:transparent;}
.mod-tog[data-m="ALL"].active{background:var(--grn);}
.mod-tog[data-m="SWS"].active{background:#2563EB;}
.mod-tog[data-m="PSM"].active{background:#0D9488;}
.mod-tog[data-m="PLT"].active{background:#7C3AED;}
.mod-tog[data-m="ALMS"].active{background:#D97706;}
.mod-tog[data-m="DTRS"].active{background:#DC2626;}

/* ── GRID ── */
.mg-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;}
.mg-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:18px;}
.mg-grid-w{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:18px;}
.mg-full{margin-bottom:18px;}

/* ── CARDS ── */
.card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s both;}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--bd);background:var(--bg);}
.card-hd-l{display:flex;align-items:center;gap:10px;}
.card-hd-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:var(--pur)}
.ic-d{background:#F3F4F6;color:#374151}
.card-hd-t{font-size:14px;font-weight:700;color:var(--t1);}
.card-hd-s{font-size:11.5px;color:var(--t2);margin-top:1px;}
.card-hd-r{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-left:auto;}
.card-body{padding:18px 20px;}

/* ── TABLES ── */
.mg-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.mg-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:9px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;}
.mg-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s;}
.mg-tbl tbody tr:last-child{border-bottom:none;}.mg-tbl tbody tr:hover{background:var(--hbg);}
.mg-tbl tbody td{padding:10px 12px;vertical-align:middle;}

/* ── CHIPS / BADGES ── */
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
.c-grn{background:#DCFCE7;color:#166534}.c-amb{background:#FEF3C7;color:#92400E}
.c-red{background:#FEE2E2;color:#991B1B}.c-blu{background:#EFF6FF;color:#1D4ED8}
.c-tel{background:#CCFBF1;color:#0F766E}.c-gry{background:#F3F4F6;color:#374151}.c-pur{background:#F5F3FF;color:#5B21B6}

/* ── ALERT ITEMS ── */
.alert-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd);}
.alert-item:last-child{border-bottom:none;padding-bottom:0;}.alert-item:first-child{padding-top:0;}
.alert-dot{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;}
.al-r{background:#FEE2E2;color:#DC2626}.al-a{background:#FEF3C7;color:#D97706}
.al-g{background:#DCFCE7;color:#166534}.al-b{background:#EFF6FF;color:#2563EB}
.alert-body{flex:1;min-width:0;}
.alert-body .ab{font-size:12.5px;font-weight:500;color:var(--t1);line-height:1.4;}
.alert-body .at{font-size:11px;color:#9EB0A2;margin-top:2px;display:flex;align-items:center;gap:5px;}
.alert-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;align-self:flex-start;margin-top:2px;}
.alert-actions{display:flex;gap:4px;margin-top:6px;}

/* ── FEED ITEMS ── */
.feed-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd);}
.feed-item:last-child{border-bottom:none;padding-bottom:0;}.feed-item:first-child{padding-top:0;}
.feed-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.fd-c{background:#DCFCE7;color:#166534}.fd-s{background:#EFF6FF;color:#2563EB}
.fd-a{background:#DCFCE7;color:#166534}.fd-r{background:#FEE2E2;color:#DC2626}
.fd-e{background:#F3F4F6;color:#6B7280}.fd-o{background:#FEF3C7;color:#D97706}
.feed-body{flex:1;min-width:0;}
.feed-body .fb{font-size:12.5px;font-weight:500;color:var(--t1);}
.feed-body .ft{font-size:11px;color:#9EB0A2;margin-top:2px;display:flex;align-items:center;gap:5px;}
.feed-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}

/* ── REPORT TABS ── */
.rpt-tabs{display:flex;gap:3px;padding:14px 20px 0;border-bottom:1px solid var(--bd);}
.rpt-tab{font-family:'Inter',sans-serif;font-size:12.5px;font-weight:600;padding:8px 15px;border-radius:8px 8px 0 0;cursor:pointer;border:none;background:transparent;color:var(--t2);transition:var(--tr);white-space:nowrap;display:flex;align-items:center;gap:6px;}
.rpt-tab.active{background:var(--grn);color:#fff;}.rpt-tab:hover:not(.active){background:var(--hbg);color:var(--t1);}
.rpt-panel{display:none;padding:20px;}.rpt-panel.active{display:block;}
.rpt-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.rpt-sel{font-family:'Inter',sans-serif;font-size:12px;padding:7px 24px 7px 10px;border:1px solid var(--bdm);border-radius:9px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;}
.rpt-sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);outline:none;}
.fi-date-sm{font-family:'Inter',sans-serif;font-size:12px;padding:7px 10px;border:1px solid var(--bdm);border-radius:9px;background:var(--s);color:var(--t1);outline:none;}
.fi-date-sm:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);}

/* ── BAR CHART ── */
.bar-chart{display:flex;align-items:flex-end;gap:6px;height:110px;padding-top:10px;}
.bc-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;}
.bc-bar{width:100%;border-radius:4px 4px 0 0;transition:height .5s ease;min-height:4px;cursor:pointer;}
.bc-bar:hover{filter:brightness(.9);}
.bc-val{font-size:9.5px;font-family:'DM Mono',monospace;font-weight:700;color:var(--t1);}
.bc-lbl{font-size:9px;color:var(--t3);text-align:center;}

/* ── SCOPE NOTICE ── */
.scope-notice{display:flex;align-items:center;gap:9px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 15px;font-size:12px;font-weight:500;color:#92400E;margin-bottom:16px;}
.scope-notice i{font-size:16px;flex-shrink:0;color:var(--amb);}

/* ── SECTION DIVIDER ── */
.sec-div{display:flex;align-items:center;gap:10px;margin:24px 0 14px;}
.sec-div span{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);}
.sec-div::after{content:'';flex:1;height:1px;background:var(--bd);}

/* ── EMPTY STATE ── */
.empty-state{padding:40px 20px;text-align:center;color:var(--t3);}
.empty-state i{font-size:40px;display:block;margin-bottom:10px;}
.empty-state p{font-size:12.5px;}

/* ── PRIORITY BARS ── */
.prog-wrap{display:flex;align-items:center;gap:8px;}
.prog-track{flex:1;height:5px;background:#E5E7EB;border-radius:3px;overflow:hidden;min-width:50px;}
.prog-fill{height:100%;border-radius:3px;transition:width .5s ease;}

/* ── VIEW-ONLY BADGE ── */
.view-only{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:var(--t3);background:var(--bg);border:1px solid var(--bd);padding:3px 9px;border-radius:20px;}

/* ── MODAL ── */
#mgModalOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s;}
#mgModalOverlay.on{opacity:1;pointer-events:all;}
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
.fn{font-size:11px;color:var(--t2);background:var(--bg);border-radius:8px;padding:8px 12px;border:1px solid var(--bd);}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* ── TOAST ── */
.mg-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}
.toast.out{animation:TOUT .3s ease forwards;}

/* ── LOADING SPINNER ── */
.loading-row{padding:32px;text-align:center;color:var(--t3);}
.spin{display:inline-block;width:20px;height:20px;border:2px solid var(--bd);border-top-color:var(--grn);border-radius:50%;animation:SPIN .7s linear infinite;vertical-align:middle;margin-right:8px;}
@keyframes SPIN{to{transform:rotate(360deg)}}

@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}

/* ── RESPONSIVE ── */
@media(max-width:1280px){.kpi-bar{grid-template-columns:repeat(3,1fr);}.mg-grid-3{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){.mg-grid,.mg-grid-w{grid-template-columns:1fr;}.kpi-bar{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.kpi-bar{grid-template-columns:1fr;}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="mg-wrap">

  <!-- PAGE HEADER -->
  <div class="mg-ph">
    <div>
      <p class="ey">Logistics 1 — Manager Dashboard</p>
      <h1>Team Operations Overview</h1>
    </div>
    <div class="mg-ph-r">
      <span id="liveClock" style="font-family:'DM Mono',monospace;font-size:12px;color:var(--t3);"></span>
      <div class="zone-pill"><i class="bx bx-map-pin"></i> <span id="zonePillLabel">Loading…</span></div>
      <button class="btn btn-ghost btn-sm" onclick="refreshAll()"><i class="bx bx-refresh"></i> Refresh</button>
    </div>
  </div>

  <!-- MODULE FILTER TOGGLES -->
  <div class="mod-toggles">
    <span style="font-size:12px;font-weight:600;color:var(--t2)">Filter by Module:</span>
    <button class="mod-tog active" data-m="ALL"  onclick="setMod('ALL',this)">All Modules</button>
    <button class="mod-tog"        data-m="SWS"  onclick="setMod('SWS',this)"><i class="bx bx-package" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>SWS</button>
    <button class="mod-tog"        data-m="PSM"  onclick="setMod('PSM',this)"><i class="bx bx-receipt" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>PSM</button>
    <button class="mod-tog"        data-m="PLT"  onclick="setMod('PLT',this)"><i class="bx bx-trip" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>PLT</button>
    <button class="mod-tog"        data-m="ALMS" onclick="setMod('ALMS',this)"><i class="bx bx-wrench" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>ALMS</button>
    <button class="mod-tog"        data-m="DTRS" onclick="setMod('DTRS',this)"><i class="bx bx-file" style="vertical-align:middle;font-size:13px;margin-right:2px"></i>DTRS</button>
  </div>

  <!-- KPI BAR -->
  <div class="kpi-bar" id="kpiBar">
    <div class="kpi-card kc-grn"><div class="kpi-label">SWS Inventory</div><div class="kpi-val kpi-skeleton" style="height:24px;width:50px;border-radius:4px;">&nbsp;</div></div>
    <div class="kpi-card kc-tel"><div class="kpi-label">PSM Pending PRs</div><div class="kpi-val kpi-skeleton" style="height:24px;width:40px;border-radius:4px;">&nbsp;</div></div>
    <div class="kpi-card kc-pur"><div class="kpi-label">PLT Deliveries</div><div class="kpi-val kpi-skeleton" style="height:24px;width:40px;border-radius:4px;">&nbsp;</div></div>
    <div class="kpi-card kc-amb"><div class="kpi-label">ALMS Maint. Due</div><div class="kpi-val kpi-skeleton" style="height:24px;width:40px;border-radius:4px;">&nbsp;</div></div>
    <div class="kpi-card kc-red"><div class="kpi-label">DTRS Doc Queue</div><div class="kpi-val kpi-skeleton" style="height:24px;width:40px;border-radius:4px;">&nbsp;</div></div>
    <div class="kpi-card kc-blu"><div class="kpi-label">Active Alerts</div><div class="kpi-val kpi-skeleton" style="height:24px;width:40px;border-radius:4px;">&nbsp;</div></div>
  </div>

  <!-- MODULE SECTION PANELS — shown/hidden per module filter -->

  <!-- SWS SECTION -->
  <div class="mod-section" data-mod="SWS">
    <div class="sec-div"><span>SWS — Smart Warehousing</span></div>
    <div class="mg-grid-w">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l">
            <div class="card-hd-ic ic-b"><i class="bx bx-package"></i></div>
            <div><div class="card-hd-t">Inventory Status</div><div class="card-hd-s">Low stock & out-of-stock items</div></div>
          </div>
          <div class="card-hd-r">
            <select class="rpt-sel" id="swsZoneFilter" onchange="loadSWSInventory()">
              <option value="">All Zones</option>
            </select>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="mg-tbl">
            <thead><tr><th>Code</th><th>Item</th><th>Zone</th><th>Stock</th><th>Status</th></tr></thead>
            <tbody id="swsInventoryTbody"><tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l">
            <div class="card-hd-ic ic-a"><i class="bx bx-grid-alt"></i></div>
            <div><div class="card-hd-t">Bin Utilisation</div><div class="card-hd-s">Occupancy by bin</div></div>
          </div>
        </div>
        <div class="card-body" id="swsBins"><div class="loading-row"><span class="spin"></span>Loading…</div></div>
      </div>
    </div>
  </div>

  <!-- PSM SECTION -->
  <div class="mod-section" data-mod="PSM">
    <div class="sec-div"><span>PSM — Procurement &amp; Supplier Management</span></div>
    <div class="mg-full card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-t"><i class="bx bx-receipt"></i></div>
          <div><div class="card-hd-t">Purchase Request Queue</div><div class="card-hd-s">Pending &amp; draft PRs awaiting action</div></div>
        </div>
        <div class="card-hd-r">
          <select class="rpt-sel" id="psmStatusFilter" onchange="loadPSMPRs()">
            <option value="">All Statuses</option>
            <option value="Pending">Pending</option>
            <option value="Draft">Draft</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
          </select>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="mg-tbl">
          <thead><tr><th>PR #</th><th>Requestor</th><th>Department</th><th>Date Filed</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
          <tbody id="psmPRTbody"><tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- PLT SECTION -->
  <div class="mod-section" data-mod="PLT">
    <div class="sec-div"><span>PLT — Project Logistics Tracker</span></div>
    <div class="mg-grid">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l">
            <div class="card-hd-ic ic-p"><i class="bx bx-trip"></i></div>
            <div><div class="card-hd-t">Active Deliveries</div><div class="card-hd-s">Scheduled &amp; in-transit deliveries</div></div>
          </div>
          <div class="card-hd-r">
            <select class="rpt-sel" id="pltDelivFilter" onchange="loadPLTDeliveries()">
              <option value="">All Statuses</option>
              <option value="Scheduled">Scheduled</option>
              <option value="In Transit">In Transit</option>
              <option value="Delayed">Delayed</option>
            </select>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="mg-tbl">
            <thead><tr><th>Delivery ID</th><th>Supplier</th><th>PO Ref</th><th>Expected</th><th>Assigned To</th><th>Status</th></tr></thead>
            <tbody id="pltDelivTbody"><tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l">
            <div class="card-hd-ic ic-p"><i class="bx bx-task"></i></div>
            <div><div class="card-hd-t">Open Assignments</div><div class="card-hd-s">Pending &amp; in-progress tasks</div></div>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="mg-tbl">
            <thead><tr><th>Assignment</th><th>Assigned To</th><th>Due</th><th>Priority</th><th>Status</th></tr></thead>
            <tbody id="pltAssignTbody"><tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="mg-full card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-p"><i class="bx bx-briefcase-alt-2"></i></div>
          <div><div class="card-hd-t">Active Projects</div><div class="card-hd-s">Current project progress &amp; budget</div></div>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="mg-tbl">
          <thead><tr><th>Project ID</th><th>Name</th><th>Manager</th><th>Deadline</th><th>Progress</th><th>Budget Used</th><th>Status</th></tr></thead>
          <tbody id="pltProjectsTbody"><tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ALMS SECTION -->
  <div class="mod-section" data-mod="ALMS">
    <div class="sec-div"><span>ALMS — Asset Lifecycle &amp; Maintenance</span></div>
    <div class="mg-grid">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l">
            <div class="card-hd-ic ic-a"><i class="bx bx-wrench"></i></div>
            <div><div class="card-hd-t">Maintenance Due</div><div class="card-hd-s">Scheduled &amp; overdue tasks</div></div>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="mg-tbl">
            <thead><tr><th>Schedule ID</th><th>Asset</th><th>Type</th><th>Next Due</th><th>Technician</th><th>Status</th></tr></thead>
            <tbody id="almsMaintTbody"><tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-l">
            <div class="card-hd-ic ic-r"><i class="bx bx-bug"></i></div>
            <div><div class="card-hd-t">Open Repair Logs</div><div class="card-hd-s">Reported &amp; in-progress repairs</div></div>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="mg-tbl">
            <thead><tr><th>Log ID</th><th>Asset</th><th>Issue</th><th>Reported</th><th>Tech</th><th>Status</th></tr></thead>
            <tbody id="almsRepairTbody"><tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- DTRS SECTION -->
  <div class="mod-section" data-mod="DTRS">
    <div class="sec-div"><span>DTRS — Document Tracking &amp; Registry</span></div>
    <div class="mg-full card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-r"><i class="bx bx-file-blank"></i></div>
          <div><div class="card-hd-t">Document Processing Queue</div><div class="card-hd-s">Registered, in-transit &amp; processing documents</div></div>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="mg-tbl">
          <thead><tr><th>Doc ID</th><th>Title</th><th>Type</th><th>Department</th><th>Priority</th><th>Assigned To</th><th>Needs Validation</th><th>Status</th></tr></thead>
          <tbody id="dtrsTbody"><tr><td colspan="8" class="loading-row"><span class="spin"></span>Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ALERTS + AUDIT FEED -->
  <div class="sec-div"><span>Live Activity &amp; Alerts</span></div>
  <div class="mg-grid-w">
    <!-- ALERT INBOX — live from notifications table -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-r"><i class="bx bx-bell"></i></div>
          <div><div class="card-hd-t">Team Alert Inbox</div><div class="card-hd-s">Live from notifications table — unread alerts</div></div>
        </div>
        <div class="card-hd-r">
          <select class="rpt-sel" id="alertModFilter" onchange="loadAlerts()">
            <option value="">All Modules</option>
            <option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
          </select>
          <span class="chip c-red" id="alertCountChip">—</span>
        </div>
      </div>
      <div class="card-body" id="alertInbox"><div class="loading-row"><span class="spin"></span>Loading…</div></div>
    </div>

    <!-- AUDIT FEED — live from v_audit_unified view -->
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-b"><i class="bx bx-history"></i></div>
          <div><div class="card-hd-t">Recent Activity</div><div class="card-hd-s">v_audit_unified — last 15 actions</div></div>
        </div>
        <div class="card-hd-r">
          <select class="rpt-sel" id="feedModFilter" onchange="loadAuditFeed()">
            <option value="">All</option>
            <option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
          </select>
          <span class="view-only"><i class="bx bx-lock-alt"></i> View Only</span>
        </div>
      </div>
      <div class="card-body" id="auditFeed"><div class="loading-row"><span class="spin"></span>Loading…</div></div>
    </div>
  </div>

  <!-- REPORTS -->
  <div class="sec-div"><span>Team Reports</span></div>
  <div class="scope-notice"><i class="bx bx-lock-alt"></i> Team-scoped reports only. Site-wide analytics require Zone Admin or Super Admin access.</div>
  <div class="card mg-full">
    <div class="rpt-tabs">
      <button class="rpt-tab active" data-t="inv"   onclick="setRptTab('inv',this)"><i class="bx bx-package"></i> Inventory</button>
      <button class="rpt-tab"        data-t="delivs" onclick="setRptTab('delivs',this)"><i class="bx bx-trip"></i> Deliveries</button>
      <button class="rpt-tab"        data-t="maint"  onclick="setRptTab('maint',this)"><i class="bx bx-wrench"></i> Maintenance</button>
      <button class="rpt-tab"        data-t="docs"   onclick="setRptTab('docs',this)"><i class="bx bx-file"></i> Documents</button>
    </div>
    <div class="rpt-panel active" id="rpt-inv">
      <div class="rpt-filters">
        <select class="rpt-sel" id="rptInvZone" onchange="renderRptInventory()"><option value="">All Zones</option></select>
        <select class="rpt-sel" id="rptInvStatus" onchange="renderRptInventory()">
          <option value="">All Statuses</option><option>In Stock</option><option>Low Stock</option><option>Out of Stock</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptInvTable','inventory_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="mg-tbl" id="rptInvTable"><thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Zone</th><th>Stock</th><th>Min Level</th><th>Status</th></tr></thead><tbody id="rptInvTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-delivs">
      <div class="rpt-filters">
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptDelivTable','deliveries_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="mg-tbl" id="rptDelivTable"><thead><tr><th>ID</th><th>Supplier</th><th>PO Ref</th><th>Expected</th><th>Actual</th><th>Late?</th><th>Status</th></tr></thead><tbody id="rptDelivTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-maint">
      <div class="rpt-filters">
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptMaintTable','maintenance_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="mg-tbl" id="rptMaintTable"><thead><tr><th>ID</th><th>Asset</th><th>Type</th><th>Frequency</th><th>Next Due</th><th>Tech</th><th>Status</th></tr></thead><tbody id="rptMaintTbody"></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-docs">
      <div class="rpt-filters">
        <button class="btn btn-primary btn-sm" onclick="exportTable('rptDocsTable','documents_report')"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto;"><table class="mg-tbl" id="rptDocsTable"><thead><tr><th>Doc ID</th><th>Title</th><th>Type</th><th>Priority</th><th>Assigned</th><th>Status</th></tr></thead><tbody id="rptDocsTbody"></tbody></table></div>
    </div>
  </div>

</div>
</main>

<div class="mg-toasts" id="toastWrap"></div>

<!-- MODAL -->
<div id="mgModalOverlay">
  <div class="modal-box" id="mgModalBox">
    <div class="mhd">
      <div><div class="mhd-t" id="mhdTitle"></div><div class="mhd-s" id="mhdSub"></div></div>
      <button class="m-cl" onclick="closeModal()"><i class="bx bx-x"></i></button>
    </div>
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
const apiGet  = p  => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── CACHE ────────────────────────────────────────────────────────────────────
let DATA = {
    inventory: [], prs: [], deliveries: [], assignments: [],
    projects: [], maintenance: [], repairs: [], docs: [], alerts: [], zones: []
};

// ── HELPERS ──────────────────────────────────────────────────────────────────
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fM   = n => '₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const fD   = d => { if(!d)return'—'; try{return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});}catch(e){return d;} };
const fDT  = d => { if(!d)return'—'; try{return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});}catch(e){return d;} };
const empty = (msg,icon='bx-inbox') => `<div class="empty-state"><i class="bx ${icon}"></i><p>${msg}</p></div>`;

function statusChip(s) {
    const m = {
        'Active':'c-grn','Assigned':'c-blu','In Progress':'c-blu','In Transit':'c-blu',
        'Completed':'c-tel','Scheduled':'c-grn','Overdue':'c-red','Low Stock':'c-amb',
        'Out of Stock':'c-red','In Stock':'c-grn','Pending':'c-amb','Draft':'c-gry',
        'Rejected':'c-red','Approved':'c-grn','Delayed':'c-red','Registered':'c-blu',
        'Processing':'c-pur','Reported':'c-red','Escalated':'c-red','Delivered':'c-tel',
        'Cancelled':'c-gry','Force Completed':'c-tel',
    };
    return `<span class="chip ${m[s]||'c-gry'}">${esc(s)}</span>`;
}
function priorityChip(p) {
    const m={Critical:'c-red',High:'c-amb',Medium:'c-blu',Low:'c-gry',Normal:'c-gry',Urgent:'c-red','High Value':'c-pur',Confidential:'c-pur'};
    return `<span class="chip ${m[p]||'c-gry'}">${esc(p)}</span>`;
}
function progBar(pct, color='var(--grn)') {
    return `<div class="prog-wrap"><div class="prog-track"><div class="prog-fill" style="width:${pct}%;background:${color}"></div></div><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;min-width:32px;text-align:right;">${pct}%</span></div>`;
}

// ── CLOCK ────────────────────────────────────────────────────────────────────
function updateClock() {
    document.getElementById('liveClock').textContent =
        new Date().toLocaleString('en-PH',{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock,1000); updateClock();

// ── MODULE FILTER ─────────────────────────────────────────────────────────────
let activeMod = 'ALL';
function setMod(m, el){
    activeMod = m;
    document.querySelectorAll('.mod-tog').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    document.querySelectorAll('.mod-section').forEach(s=>{
        const sm = s.dataset.mod;
        s.style.display = (m==='ALL' || m===sm) ? '' : 'none';
    });
}

// ── LOAD KPIs ─────────────────────────────────────────────────────────────────
async function loadKPI(){
    try {
        const k = await apiGet(API+'?api=kpi');
        document.getElementById('kpiBar').innerHTML=`
            <div class="kpi-card kc-grn">
                <div class="kpi-label">SWS Inventory</div>
                <div class="kpi-val">${k.sws.totalItems}</div>
                <div class="kpi-sub">${k.sws.lowStock} low · ${k.sws.outOfStock} out</div>
                <div class="kpi-bar-fill"><div class="kpi-bar-inner" style="width:${k.sws.totalItems>0?Math.round(((k.sws.totalItems-k.sws.outOfStock)/k.sws.totalItems)*100):0}%;background:var(--grn)"></div></div>
            </div>
            <div class="kpi-card kc-tel">
                <div class="kpi-label">PSM Pending PRs</div>
                <div class="kpi-val">${k.psm.pendingPRs}</div>
                <div class="kpi-sub">Awaiting review or approval</div>
            </div>
            <div class="kpi-card kc-pur">
                <div class="kpi-label">PLT Deliveries</div>
                <div class="kpi-val">${k.plt.activeDeliveries}</div>
                <div class="kpi-sub ${k.plt.lateDeliveries>0?'c-red':''}">${k.plt.lateDeliveries} late</div>
            </div>
            <div class="kpi-card kc-amb">
                <div class="kpi-label">ALMS Maint. Due</div>
                <div class="kpi-val">${k.alms.maintenanceDue}</div>
                <div class="kpi-sub">Within 7 days or overdue</div>
            </div>
            <div class="kpi-card kc-red">
                <div class="kpi-label">DTRS Doc Queue</div>
                <div class="kpi-val">${k.dtrs.docsPending}</div>
                <div class="kpi-sub">${k.dtrs.docFlags} need validation</div>
            </div>
            <div class="kpi-card kc-blu">
                <div class="kpi-label">Active Alerts</div>
                <div class="kpi-val">${k.alerts.total}</div>
                <div class="kpi-sub">${k.alerts.critical} critical · ${k.alerts.high} high</div>
            </div>`;
    } catch(e){ toast('KPI load failed: '+e.message,'d'); }
}

// ── LOAD SWS ──────────────────────────────────────────────────────────────────
async function loadSWSInventory(){
    const zone = document.getElementById('swsZoneFilter').value;
    const tb = document.getElementById('swsInventoryTbody');
    tb.innerHTML=`<tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        const url = API+'?api=sws-inventory'+(zone?'&zone='+encodeURIComponent(zone):'');
        DATA.inventory = await apiGet(url);
        // Populate report zone filter
        const zones=[...new Set(DATA.inventory.map(i=>i.zone).filter(Boolean))];
        ['swsZoneFilter','rptInvZone'].forEach(id=>{
            const el=document.getElementById(id); if(!el)return;
            const cv=el.value;
            el.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option ${z===cv?'selected':''}>${esc(z)}</option>`).join('');
        });
        renderSWSInventory();
    } catch(e){ tb.innerHTML=`<tr><td colspan="5" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}
function renderSWSInventory(){
    const rows = DATA.inventory.slice(0,20);
    document.getElementById('swsInventoryTbody').innerHTML = rows.length
        ? rows.map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11.5px;color:var(--grn);font-weight:700;">${esc(r.code)}</td>
            <td style="font-weight:600;">${esc(r.name)}</td>
            <td><span style="font-size:11px;color:var(--t2);">${esc(r.zone||'—')}</span></td>
            <td><span style="font-family:'DM Mono',monospace;font-weight:700;">${r.stock}</span><span style="font-size:11px;color:var(--t3);"> / ${r.minLevel} min</span></td>
            <td>${statusChip(r.status)}</td>
          </tr>`).join('')
        : `<tr><td colspan="5">${empty('No inventory items found','bx-package')}</td></tr>`;
}

async function loadSWSBins(){
    const el = document.getElementById('swsBins');
    el.innerHTML='<div class="loading-row"><span class="spin"></span>Loading…</div>';
    try {
        DATA.bins = await apiGet(API+'?api=bins');
        if(!DATA.bins.length){el.innerHTML=empty('No bins found','bx-grid-alt');return;}
        el.innerHTML = DATA.bins.slice(0,12).map(b=>`
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--bd);">
              <div style="width:36px;text-align:right;font-family:'DM Mono',monospace;font-size:10.5px;font-weight:700;color:var(--grn);flex-shrink:0;">${esc(b.code)}</div>
              <div style="flex:1;">
                ${progBar(b.util_pct, b.util_pct>90?'var(--red)':b.util_pct>70?'var(--amb)':'var(--grn)')}
              </div>
              <div style="flex-shrink:0;">${statusChip(b.status)}</div>
            </div>`).join('');
    } catch(e){ el.innerHTML=`<div style="color:var(--red);font-size:12.5px;padding:12px;">${esc(e.message)}</div>`; }
}

// ── LOAD PSM ──────────────────────────────────────────────────────────────────
async function loadPSMPRs(){
    const status = document.getElementById('psmStatusFilter').value;
    const tb = document.getElementById('psmPRTbody');
    tb.innerHTML=`<tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        const url = API+'?api=psm-prs'+(status?'&status='+encodeURIComponent(status):'');
        DATA.prs = await apiGet(url);
        tb.innerHTML = DATA.prs.length
            ? DATA.prs.map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.pr_number)}</td>
                <td style="font-weight:600;">${esc(r.requestor_name)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.department)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.date_filed)}</td>
                <td style="font-family:'DM Mono',monospace;">${r.item_count}</td>
                <td style="font-family:'DM Mono',monospace;font-weight:700;">${fM(r.total_amount)}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : `<tr><td colspan="7">${empty('No purchase requests found','bx-receipt')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── LOAD PLT ──────────────────────────────────────────────────────────────────
async function loadPLTDeliveries(){
    const status = document.getElementById('pltDelivFilter').value;
    const tb = document.getElementById('pltDelivTbody');
    tb.innerHTML=`<tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        const url = API+'?api=plt-deliveries'+(status?'&status='+encodeURIComponent(status):'');
        DATA.deliveries = await apiGet(url);
        tb.innerHTML = DATA.deliveries.length
            ? DATA.deliveries.map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.delivery_id)}</td>
                <td style="font-weight:600;">${esc(r.supplier)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${esc(r.po_ref)}</td>
                <td style="font-size:11.5px;">${fD(r.expected_date)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.assigned_to)}</td>
                <td>${statusChip(r.status)} ${r.is_late?'<span class="chip c-red" style="margin-left:4px;">Late</span>':''}</td>
              </tr>`).join('')
            : `<tr><td colspan="6">${empty('No deliveries found','bx-trip')}</td></tr>`;
        // mirror to report tab
        document.getElementById('rptDelivTbody').innerHTML = DATA.deliveries.map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.delivery_id)}</td>
            <td>${esc(r.supplier)}</td><td>${esc(r.po_ref)}</td>
            <td>${fD(r.expected_date)}</td><td>${fD(r.actual_date)}</td>
            <td>${r.is_late?'<span class="chip c-red">Late</span>':'<span class="chip c-grn">On Time</span>'}</td>
            <td>${statusChip(r.status)}</td></tr>`).join('') || `<tr><td colspan="7">${empty('No data','bx-trip')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="6" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadPLTAssignments(){
    const tb = document.getElementById('pltAssignTbody');
    tb.innerHTML=`<tr><td colspan="5" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        DATA.assignments = await apiGet(API+'?api=plt-assignments');
        tb.innerHTML = DATA.assignments.length
            ? DATA.assignments.map(r=>`<tr>
                <td style="font-size:12.5px;font-weight:600;">${esc(r.task.slice(0,50)+(r.task.length>50?'…':''))}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.assigned_to)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.due_date)}</td>
                <td>${priorityChip(r.priority)}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : `<tr><td colspan="5">${empty('No open assignments','bx-task')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="5" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadPLTProjects(){
    const tb = document.getElementById('pltProjectsTbody');
    tb.innerHTML=`<tr><td colspan="7" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        DATA.projects = await apiGet(API+'?api=plt-projects');
        tb.innerHTML = DATA.projects.length
            ? DATA.projects.map(r=>{
                const budgetPct = r.budget>0 ? Math.min(100,Math.round(r.spend/r.budget*100)) : 0;
                const budgetColor = budgetPct>100?'var(--red)':budgetPct>85?'var(--amb)':'var(--grn)';
                return `<tr>
                    <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.project_id)}</td>
                    <td style="font-weight:600;">${esc(r.name)}</td>
                    <td style="font-size:11.5px;color:var(--t2);">${esc(r.manager)}</td>
                    <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.end_date)}</td>
                    <td style="min-width:130px;">${progBar(r.progress,'var(--grn)')}</td>
                    <td style="min-width:120px;">${progBar(budgetPct,budgetColor)}</td>
                    <td>${statusChip(r.status)}</td>
                  </tr>`;
              }).join('')
            : `<tr><td colspan="7">${empty('No active projects','bx-briefcase-alt-2')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── LOAD ALMS ─────────────────────────────────────────────────────────────────
async function loadALMSMaintenance(){
    const tb = document.getElementById('almsMaintTbody');
    tb.innerHTML=`<tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        DATA.maintenance = await apiGet(API+'?api=alms-maintenance');
        const today = new Date().toISOString().split('T')[0];
        tb.innerHTML = DATA.maintenance.length
            ? DATA.maintenance.map(r=>{
                const overdue = r.next_due && r.next_due < today;
                return `<tr>
                    <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.schedule_id)}</td>
                    <td style="font-weight:600;">${esc(r.asset_name)}</td>
                    <td>${esc(r.type)}</td>
                    <td style="font-family:'DM Mono',monospace;font-size:11.5px;color:${overdue?'var(--red)':''};">${fD(r.next_due)} ${overdue?'<span class="chip c-red" style="font-size:10px;padding:1px 6px;">Overdue</span>':''}</td>
                    <td style="font-size:11.5px;color:var(--t2);">${esc(r.tech||'—')}</td>
                    <td>${statusChip(r.status)}</td>
                  </tr>`;
              }).join('')
            : `<tr><td colspan="6">${empty('No maintenance scheduled','bx-wrench')}</td></tr>`;
        // mirror to report tab
        document.getElementById('rptMaintTbody').innerHTML = DATA.maintenance.map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.schedule_id)}</td>
            <td>${esc(r.asset_name)}</td><td>${esc(r.type)}</td><td>${esc(r.freq)}</td>
            <td>${fD(r.next_due)}</td><td>${esc(r.tech||'—')}</td>
            <td>${statusChip(r.status)}</td></tr>`).join('') || `<tr><td colspan="7">${empty('No data','bx-wrench')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="6" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

async function loadALMSRepairs(){
    const tb = document.getElementById('almsRepairTbody');
    tb.innerHTML=`<tr><td colspan="6" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        DATA.repairs = await apiGet(API+'?api=alms-repairs');
        tb.innerHTML = DATA.repairs.length
            ? DATA.repairs.map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.log_id)}</td>
                <td style="font-weight:600;">${esc(r.asset_name)}</td>
                <td style="font-size:12px;color:var(--t2);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.issue)}</td>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;">${fD(r.date_reported)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.technician||'—')}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : `<tr><td colspan="6">${empty('No open repair logs','bx-bug')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="6" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── LOAD DTRS ─────────────────────────────────────────────────────────────────
async function loadDTRS(){
    const tb = document.getElementById('dtrsTbody');
    tb.innerHTML=`<tr><td colspan="8" class="loading-row"><span class="spin"></span>Loading…</td></tr>`;
    try {
        DATA.docs = await apiGet(API+'?api=dtrs-docs');
        tb.innerHTML = DATA.docs.length
            ? DATA.docs.map(r=>`<tr>
                <td style="font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn);">${esc(r.doc_id)}</td>
                <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.title)}</td>
                <td style="font-size:11.5px;">${esc(r.doc_type)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.department)}</td>
                <td>${priorityChip(r.priority)}</td>
                <td style="font-size:11.5px;color:var(--t2);">${esc(r.assigned_to||'—')}</td>
                <td>${r.needs_validation?'<span class="chip c-amb">Yes</span>':'<span class="chip c-grn">No</span>'}</td>
                <td>${statusChip(r.status)}</td>
              </tr>`).join('')
            : `<tr><td colspan="8">${empty('No documents in queue','bx-file-blank')}</td></tr>`;
        // mirror to report
        document.getElementById('rptDocsTbody').innerHTML = DATA.docs.map(r=>`<tr>
            <td style="font-family:'DM Mono',monospace;font-size:11px;">${esc(r.doc_id)}</td>
            <td>${esc(r.title)}</td><td>${esc(r.doc_type)}</td><td>${priorityChip(r.priority)}</td>
            <td>${esc(r.assigned_to||'—')}</td><td>${statusChip(r.status)}</td></tr>`).join('')
            || `<tr><td colspan="6">${empty('No data','bx-file')}</td></tr>`;
    } catch(e){ tb.innerHTML=`<tr><td colspan="8" style="text-align:center;color:var(--red);padding:24px;">${esc(e.message)}</td></tr>`; }
}

// ── LOAD ALERTS ───────────────────────────────────────────────────────────────
async function loadAlerts(){
    const mod = document.getElementById('alertModFilter').value;
    const el  = document.getElementById('alertInbox');
    el.innerHTML='<div class="loading-row"><span class="spin"></span>Loading…</div>';
    try {
        const url = API+'?api=alerts'+(mod?'&module='+encodeURIComponent(mod):'');
        DATA.alerts = await apiGet(url);
        document.getElementById('alertCountChip').textContent = DATA.alerts.length + ' Active';
        if(!DATA.alerts.length){ el.innerHTML=empty('No active alerts','bx-bell'); return; }

        const sevIcon = {Critical:'bx-error-circle',High:'bx-error',Medium:'bx-time-five',Low:'bx-info-circle'};
        const sevCls  = {Critical:'al-r',High:'al-a',Medium:'al-b',Low:'al-g'};

        el.innerHTML = DATA.alerts.slice(0,10).map(a=>`
            <div class="alert-item" id="alert-${a.id}">
              <div class="alert-dot ${sevCls[a.severity]||'al-b'}"><i class="bx ${sevIcon[a.severity]||'bx-bell'}"></i></div>
              <div class="alert-body">
                <div class="ab">${esc(a.title)}</div>
                <div class="at">
                  <span style="background:#F3F4F6;padding:1px 5px;border-radius:4px;font-size:10px;">${a.module}</span>
                  <span class="chip ${sevCls[a.severity]||'c-blu'}" style="font-size:9.5px;">${a.severity}</span>
                </div>
                <div class="alert-actions">
                  <button class="btn btn-warn btn-xs" onclick="escalateAlert(${a.id})"><i class="bx bx-up-arrow-circle"></i> Escalate</button>
                  <button class="btn btn-ghost btn-xs" onclick="dismissAlert(${a.id})"><i class="bx bx-check"></i> Dismiss</button>
                </div>
              </div>
              <div class="alert-ts">${fDT(a.created_at)}</div>
            </div>`).join('');
    } catch(e){ el.innerHTML=`<div style="color:var(--red);font-size:12.5px;padding:12px;">${esc(e.message)}</div>`; }
}

async function dismissAlert(id){
    try {
        await apiPost(API+'?api=alert-action',{id,type:'dismiss'});
        document.getElementById('alert-'+id)?.remove();
        DATA.alerts = DATA.alerts.filter(a=>a.id!==id);
        document.getElementById('alertCountChip').textContent = DATA.alerts.length+' Active';
        toast('Alert dismissed.','s');
    } catch(e){ toast(e.message,'d'); }
}

function escalateAlert(id){
    const a = DATA.alerts.find(x=>x.id===id); if(!a) return;
    openModal({
        title:'Escalate Alert to Admin',
        sub:`${a.title}`,
        wide:false,
        body:`
            <div class="fn"><i class="bx bx-info-circle" style="vertical-align:middle;margin-right:6px;"></i>${esc(a.title)} — <strong>${a.severity}</strong> · <strong>${a.module}</strong></div>
            <div class="fg"><label>Priority</label><select id="escPriority"><option>High</option><option>Critical</option><option>Medium</option></select></div>
            <div class="fg"><label>Remarks</label><textarea id="escRemarks" placeholder="Describe the escalation reason…"></textarea></div>`,
        foot:`<button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
              <button class="btn btn-warn btn-sm" onclick="submitEscalate(${id})"><i class="bx bx-up-arrow-circle"></i> Escalate</button>`,
    });
}
async function submitEscalate(id){
    const priority = document.getElementById('escPriority')?.value||'High';
    const remarks  = document.getElementById('escRemarks')?.value.trim()||'';
    try {
        await apiPost(API+'?api=alert-action',{id,type:'escalate',priority,remarks});
        document.getElementById('alert-'+id)?.remove();
        DATA.alerts = DATA.alerts.filter(a=>a.id!==id);
        document.getElementById('alertCountChip').textContent = DATA.alerts.length+' Active';
        closeModal(); toast('Alert escalated to Zone Admin.','w');
    } catch(e){ toast(e.message,'d'); }
}

// ── AUDIT FEED ────────────────────────────────────────────────────────────────
async function loadAuditFeed(){
    const mod = document.getElementById('feedModFilter').value;
    const el  = document.getElementById('auditFeed');
    el.innerHTML='<div class="loading-row"><span class="spin"></span>Loading…</div>';
    try {
        const url = API+'?api=audit-feed&limit=15'+(mod?'&module='+encodeURIComponent(mod):'');
        const rows = await apiGet(url);
        if(!rows.length){el.innerHTML=empty('No recent activity','bx-history');return;}

        const dotCls = (mod,atype) => {
            if(atype==='Create') return 'fd-c';
            if(atype==='Delete') return 'fd-r';
            if(atype==='Approve') return 'fd-a';
            return 'fd-s';
        };
        const modIcons={SWS:'bx-package',PSM:'bx-receipt',PLT:'bx-trip',ALMS:'bx-wrench',DTRS:'bx-file-blank','User Mgmt':'bx-user','System':'bx-shield-alt-2'};

        el.innerHTML = rows.map(r=>`
            <div class="feed-item">
              <div class="feed-dot ${dotCls(r.module,r.action_type)}">
                <i class="bx ${modIcons[r.module]||'bx-info-circle'}"></i>
              </div>
              <div class="feed-body">
                <div class="fb">${esc(r.action_label)}</div>
                <div class="ft">
                  <i class="bx bx-user" style="font-size:11px;"></i>${esc(r.actor_name)}
                  <span style="background:#F3F4F6;padding:1px 5px;border-radius:4px;font-size:10px;">${r.module}</span>
                  <span style="font-size:10px;color:var(--t3);">${esc(r.record_ref||'')}</span>
                </div>
              </div>
              <div class="feed-ts">${fDT(r.occurred_at)}</div>
            </div>`).join('');
    } catch(e){ el.innerHTML=`<div style="color:var(--red);font-size:12.5px;padding:12px;">${esc(e.message)}</div>`; }
}

// ── REPORT INVENTORY ──────────────────────────────────────────────────────────
function renderRptInventory(){
    const zone   = document.getElementById('rptInvZone')?.value||'';
    const status = document.getElementById('rptInvStatus')?.value||'';
    let rows = DATA.inventory;
    if(zone)   rows=rows.filter(r=>r.zone===zone);
    if(status) rows=rows.filter(r=>r.status===status);
    document.getElementById('rptInvTbody').innerHTML = rows.map(r=>`<tr>
        <td style="font-family:'DM Mono',monospace;font-size:11px;color:var(--grn);font-weight:700;">${esc(r.code)}</td>
        <td style="font-weight:600;">${esc(r.name)}</td>
        <td>${esc(r.category)}</td>
        <td>${esc(r.zone||'—')}</td>
        <td style="font-family:'DM Mono',monospace;font-weight:700;">${r.stock}</td>
        <td style="font-family:'DM Mono',monospace;">${r.minLevel}</td>
        <td>${statusChip(r.status)}</td></tr>`).join('')
    || `<tr><td colspan="7">${empty('No items match filters','bx-package')}</td></tr>`;
}

// ── REPORT TABS ───────────────────────────────────────────────────────────────
function setRptTab(name,el){
    document.querySelectorAll('.rpt-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.rpt-panel').forEach(p=>p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('rpt-'+name).classList.add('active');
}

// ── MODAL ─────────────────────────────────────────────────────────────────────
function openModal({title,sub,wide,body,foot}){
    const box=document.getElementById('mgModalBox');
    box.classList.toggle('wide',!!wide);
    document.getElementById('mhdTitle').textContent=title||'';
    document.getElementById('mhdSub').textContent=sub||'';
    document.getElementById('mBody').innerHTML=body||'';
    document.getElementById('mFoot').innerHTML=foot||'<button class="btn btn-ghost btn-sm" onclick="closeModal()">Close</button>';
    document.getElementById('mgModalOverlay').classList.add('on');
}
function closeModal(){ document.getElementById('mgModalOverlay').classList.remove('on'); }
document.getElementById('mgModalOverlay').addEventListener('click',function(e){if(e.target===this)closeModal();});

// ── EXPORT TABLE ─────────────────────────────────────────────────────────────
function exportTable(tableId, filename){
    const tbl = document.getElementById(tableId); if(!tbl){toast('Table not found','d');return;}
    const rows=[];
    tbl.querySelectorAll('thead tr').forEach(r=>rows.push([...r.querySelectorAll('th')].map(c=>'"'+c.textContent.trim().replace(/"/g,'""')+'"').join(',')));
    tbl.querySelectorAll('tbody tr').forEach(r=>rows.push([...r.querySelectorAll('td')].map(c=>'"'+c.textContent.trim().replace(/"/g,'""')+'"').join(',')));
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download=filename+'.csv'; a.click();
    toast('CSV exported: '+filename+'.csv','s');
}

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
    loadSWSInventory();
    loadSWSBins();
    loadPSMPRs();
    loadPLTDeliveries();
    loadPLTAssignments();
    loadPLTProjects();
    loadALMSMaintenance();
    loadALMSRepairs();
    loadDTRS();
    loadAlerts();
    loadAuditFeed();
    toast('Dashboard refreshed.','s');
}

// ── INIT ──────────────────────────────────────────────────────────────────────
(async()=>{
    // Zone label
    try {
        const zones = await apiGet(API+'?api=zones');
        DATA.zones = zones;
        document.getElementById('zonePillLabel').textContent = zones.length
            ? 'All Zones ('+zones.length+')'
            : 'No Zones';
    } catch(e){ document.getElementById('zonePillLabel').textContent='Zone Data'; }

    // Load everything in parallel
    await Promise.allSettled([
        loadKPI(),
        loadSWSInventory().then(()=>{loadSWSBins();}),
        loadPSMPRs(),
        loadPLTDeliveries(),
        loadPLTAssignments(),
        loadPLTProjects(),
        loadALMSMaintenance(),
        loadALMSRepairs(),
        loadDTRS(),
        loadAlerts(),
        loadAuditFeed(),
    ]);
})();

// Auto-refresh every 3 minutes
setInterval(()=>{ loadKPI(); loadAlerts(); loadAuditFeed(); }, 3*60*1000);
</script>
</body>
</html>