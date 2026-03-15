<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE GUARD (mirror of sidebar role logic) ─────────────────────────────────
function al_resolve_role(): string {
    if (!empty($_SESSION['role'])) {
        $r = $_SESSION['role'];
        if (str_contains($r, 'Super Admin')) return 'Super Admin';
        if (str_contains($r, 'Admin'))       return 'Admin';
        if (str_contains($r, 'Manager'))     return 'Manager';
        return 'Staff';
    }
    if (!empty($_SESSION['roles'])) {
        $r = is_array($_SESSION['roles'])
            ? implode(',', $_SESSION['roles'])
            : (string)$_SESSION['roles'];
        if (str_contains($r, 'Super Admin')) return 'Super Admin';
        if (str_contains($r, 'Admin'))       return 'Admin';
        if (str_contains($r, 'Manager'))     return 'Manager';
    }
    return 'Staff';
}

$alRoleName = al_resolve_role();
$alRoleRank = match($alRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};

// Only Super Admin may access Audit Logs (page + APIs)
if ($alRoleRank < 4) {
    $isApi = isset($_GET['api'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

    if ($isApi) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Forbidden — Super Admin only']);
        exit;
    }

    $dashboardUrl = match($alRoleName) {
        'Super Admin' => '/superadmin_dashboard.php',
        'Admin'       => '/admin_dashboard.php',
        'Manager'     => '/manager_dashboard.php',
        default       => '/user_dashboard.php',
    };
    header('Location: ' . $dashboardUrl);
    exit;
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function al_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function al_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/** Supabase REST — same pattern as all other modules */
function al_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

/** Raw URL fetch — for complex filter queries */
function al_fetch(string $url): array {
    $headers = [
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: return=representation',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code < 400) ? (json_decode($res, true) ?: []) : [];
}

/**
 * Map action_type → display badge class + dot color + icon
 */
function al_meta(string $actionType): array {
    return match ($actionType) {
        'Create' => ['badgeClass' => 'ab-create', 'color' => '#2E7D32', 'icon' => 'bx-plus-circle'],
        'Edit'   => ['badgeClass' => 'ab-edit',   'color' => '#2563EB', 'icon' => 'bx-edit'],
        'Delete' => ['badgeClass' => 'ab-delete', 'color' => '#DC2626', 'icon' => 'bx-trash'],
        'Approve'=> ['badgeClass' => 'ab-approve','color' => '#059669', 'icon' => 'bx-check-circle'],
        'Login'  => ['badgeClass' => 'ab-login',  'color' => '#7C3AED', 'icon' => 'bx-log-in'],
        'View'   => ['badgeClass' => 'ab-view',   'color' => '#6B7280', 'icon' => 'bx-show'],
        'Export' => ['badgeClass' => 'ab-export', 'color' => '#0D9488', 'icon' => 'bx-export'],
        default  => ['badgeClass' => 'ab-view',   'color' => '#6B7280', 'icon' => 'bx-info-circle'],
    };
}

/** Build a clean DTO from a unified view row */
function al_build(array $row): array {
    $meta = al_meta($row['action_type'] ?? 'Edit');
    // Infer compliance violation flag:
    // Login from unlikely context, Delete with is_super_admin=false, Export
    $isVio  = false;
    $vioReason = '';
    $actionType = $row['action_type'] ?? '';
    $isSa   = (bool)($row['is_super_admin'] ?? false);
    if ($actionType === 'Delete' && !$isSa) {
        $isVio = true;
        $vioReason = 'Deletion performed without Super Admin approval';
    } elseif ($actionType === 'Export' && !$isSa) {
        $isVio = true;
        $vioReason = 'Sensitive data export by non-SA user';
    }

    return [
        'id'           => $row['log_id']        ?? '',
        'module'       => $row['module']         ?? '',
        'actionLabel'  => $row['action_label']   ?? '',
        'actionType'   => $actionType,
        'actorName'    => $row['actor_name']     ?? '',
        'actorRole'    => $row['actor_role']     ?? '',
        'recordRef'    => $row['record_ref']     ?? '',
        'ip'           => $row['ip_address']     ?? '',
        'isSuperAdmin' => $isSa,
        'note'         => $row['note']           ?? '',
        'occurredAt'   => $row['occurred_at']    ?? '',
        'isVio'        => $isVio,
        'vioReason'    => $vioReason,
        'badgeClass'   => $meta['badgeClass'],
        'color'        => $meta['color'],
        'icon'         => $meta['icon'],
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    try {

        // ── GET /api=stats ────────────────────────────────────────────────────
        // Aggregate counts from the unified view
        if ($api === 'stats' && $method === 'GET') {
            $rows = al_fetch(SUPABASE_URL . '/rest/v1/v_audit_unified?select=module,action_type,is_super_admin,occurred_at&order=occurred_at.desc&limit=5000');

            $total    = count($rows);
            $today    = date('Y-m-d');
            $todayCnt = 0;
            $vioCnt   = 0;
            $mods     = [];

            foreach ($rows as $r) {
                $mods[$r['module']] = true;
                $dt = substr($r['occurred_at'] ?? '', 0, 10);
                if ($dt === $today) $todayCnt++;
                // Compute violation
                $at   = $r['action_type'] ?? '';
                $isSa = (bool)($r['is_super_admin'] ?? false);
                if (($at === 'Delete' || $at === 'Export') && !$isSa) $vioCnt++;
            }

            al_ok([
                'total'    => $total,
                'today'    => $todayCnt,
                'vio'      => $vioCnt,
                'modules'  => count($mods),
            ]);
        }

        // ── GET /api=list ─────────────────────────────────────────────────────
        // Paginated, filtered, sorted log list
        if ($api === 'list' && $method === 'GET') {
            $page    = max(1, (int)($_GET['page']    ?? 1));
            $perPage = max(1, min(100, (int)($_GET['per'] ?? 15)));
            $search  = trim($_GET['q']       ?? '');
            $module  = trim($_GET['module']  ?? '');
            $action  = trim($_GET['action']  ?? '');
            $from    = trim($_GET['from']    ?? '');
            $to      = trim($_GET['to']      ?? '');
            $vioOnly = $_GET['vio'] === '1';
            $sortCol = trim($_GET['sort']    ?? 'occurred_at');
            $sortDir = $_GET['dir'] === 'asc' ? 'asc' : 'desc';

            $allowed = ['occurred_at','module','action_type','actor_name','record_ref'];
            if (!in_array($sortCol, $allowed, true)) $sortCol = 'occurred_at';

            $parts = [
                'select=*',
                "order={$sortCol}.{$sortDir}",
                'limit=5000',
            ];
            if ($module) $parts[] = 'module=eq.' . urlencode($module);
            if ($action) $parts[] = 'action_type=eq.' . urlencode($action);
            if ($from)   $parts[] = 'occurred_at=gte.' . urlencode($from . 'T00:00:00');
            if ($to)     $parts[] = 'occurred_at=lte.' . urlencode($to   . 'T23:59:59');
            if ($search) $parts[] = 'or=' . urlencode(
                "(log_id.ilike.*{$search}*,actor_name.ilike.*{$search}*,action_label.ilike.*{$search}*,record_ref.ilike.*{$search}*)"
            );

            $url  = SUPABASE_URL . '/rest/v1/v_audit_unified?' . implode('&', $parts);
            $rows = al_fetch($url);

            // Post-filter: violation flag (computed, not stored)
            $built = array_map('al_build', $rows);
            if ($vioOnly) {
                $built = array_values(array_filter($built, fn($r) => $r['isVio']));
            }

            $total  = count($built);
            $offset = ($page - 1) * $perPage;
            $slice  = array_slice($built, $offset, $perPage);

            al_ok([
                'items'   => array_values($slice),
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        // ── GET /api=correlation ──────────────────────────────────────────────
        // Returns cross-module correlation stats
        if ($api === 'correlation' && $method === 'GET') {
            $rows = al_fetch(SUPABASE_URL . '/rest/v1/v_audit_unified?select=module,action_type,actor_name,is_super_admin&limit=5000');

            $modCounts    = [];
            $actionCounts = [];
            $userCounts   = [];
            $userMods     = [];
            $vioCnt       = 0;

            foreach ($rows as $r) {
                $mod    = $r['module']      ?? 'System';
                $act    = $r['action_type'] ?? 'Edit';
                $actor  = $r['actor_name']  ?? '';
                $isSa   = (bool)($r['is_super_admin'] ?? false);

                $modCounts[$mod]      = ($modCounts[$mod]      ?? 0) + 1;
                $actionCounts[$act]   = ($actionCounts[$act]   ?? 0) + 1;
                $userCounts[$actor]   = ($userCounts[$actor]   ?? 0) + 1;
                $userMods[$actor][$mod] = true;

                if (($act === 'Delete' || $act === 'Export') && !$isSa) $vioCnt++;
            }

            arsort($modCounts);
            arsort($actionCounts);
            arsort($userCounts);

            $topMod    = array_key_first($modCounts);
            $topAction = array_key_first($actionCounts);
            $topUser   = array_key_first($userCounts);
            $crossUsers = count(array_filter($userMods, fn($mods) => count($mods) > 1));

            al_ok([
                'topModule'     => ['name' => $topMod,    'count' => $modCounts[$topMod]    ?? 0],
                'topAction'     => ['name' => $topAction, 'count' => $actionCounts[$topAction] ?? 0],
                'topUser'       => ['name' => $topUser,   'count' => $userCounts[$topUser]  ?? 0],
                'crossUsers'    => $crossUsers,
                'psmCount'      => $modCounts['PSM']  ?? 0,
                'swsCount'      => $modCounts['SWS']  ?? 0,
                'violations'    => $vioCnt,
                'moduleBreakdown' => $modCounts,
                'actionBreakdown' => $actionCounts,
            ]);
        }

        // ── GET /api=export ───────────────────────────────────────────────────
        // Returns full filtered dataset for CSV export
        if ($api === 'export' && $method === 'GET') {
            $module  = trim($_GET['module']  ?? '');
            $action  = trim($_GET['action']  ?? '');
            $from    = trim($_GET['from']    ?? '');
            $to      = trim($_GET['to']      ?? '');
            $vioOnly = $_GET['vio'] === '1';
            $search  = trim($_GET['q']       ?? '');

            $parts = ['select=*', 'order=occurred_at.desc', 'limit=10000'];
            if ($module) $parts[] = 'module=eq.'      . urlencode($module);
            if ($action) $parts[] = 'action_type=eq.' . urlencode($action);
            if ($from)   $parts[] = 'occurred_at=gte.' . urlencode($from . 'T00:00:00');
            if ($to)     $parts[] = 'occurred_at=lte.' . urlencode($to   . 'T23:59:59');

            $rows  = al_fetch(SUPABASE_URL . '/rest/v1/v_audit_unified?' . implode('&', $parts));
            $built = array_map('al_build', $rows);
            if ($vioOnly) $built = array_values(array_filter($built, fn($r) => $r['isVio']));

            al_ok(array_values($built));
        }

        al_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        al_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Logs — System Administration</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{
  --s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:#1A2E1D;--t2:#5D6F62;--t3:#9EB0A2;
  --hbg:#EEF5EE;--bg:#F6FAF6;
  --grn:#2E7D32;--gdk:#1B5E20;
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px;--tr:all .18s ease;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px}

.al-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem}

/* PAGE HEADER */
.al-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:alUP .4s both}
.al-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px}
.al-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;margin:0}
.al-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sa-excl{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:3px 8px}
.sa-excl i{font-size:11px}

/* BUTTONS */
.abtn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.abtn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32)}
.abtn-primary:hover{background:var(--gdk);transform:translateY(-1px)}
.abtn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}
.abtn-ghost:hover{background:var(--hbg);color:var(--t1)}
.abtn-warn{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D}
.abtn-warn:hover{background:#FDE68A}
.abtn-red{background:#FEE2E2;color:var(--red);border:1px solid #FECACA}
.abtn-red:hover{background:#FCA5A5}
.abtn-purple{background:#F5F3FF;color:var(--pur);border:1px solid #DDD6FE}
.abtn-purple:hover{background:#EDE9FE}
.abtn-sm{font-size:12px;padding:6px 13px}
.abtn-xs{font-size:11px;padding:4px 9px;border-radius:7px}
.abtn:disabled{opacity:.45;pointer-events:none}

/* STATS */
.al-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:22px;animation:alUP .4s .05s both}
.al-sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px}
.al-sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-g{background:#DCFCE7;color:#166534}
.ic-r{background:#FEE2E2;color:var(--red)}.ic-a{background:#FEF3C7;color:var(--amb)}
.ic-p{background:#F5F3FF;color:var(--pur)}.ic-t{background:#CCFBF1;color:var(--tel)}
.al-sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1}
.al-sc-l{font-size:11px;color:var(--t2);margin-top:2px}

/* SKELETON */
.skeleton{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.07) 50%,var(--bg) 75%);background-size:400% 100%;animation:shimmer 1.4s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}

/* VIOLATION BANNER */
.al-vio-banner{display:flex;align-items:flex-start;gap:12px;background:linear-gradient(135deg,#FFF1F1,#FEE2E2);border:1px solid #FECACA;border-radius:12px;padding:13px 18px;margin-bottom:20px;animation:alUP .4s .02s both}
.al-vio-banner i{font-size:20px;color:var(--red);flex-shrink:0;margin-top:1px}
.al-vio-banner .vb-title{font-size:13px;font-weight:700;color:#991B1B}
.al-vio-banner .vb-text{font-size:12px;color:#B91C1C;margin-top:2px;line-height:1.5}
.al-vio-banner .vb-acts{display:flex;gap:8px;margin-top:9px;flex-wrap:wrap}

/* CORRELATION PANEL */
.corr-panel{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);margin-bottom:20px;display:none;animation:alUP .3s both}
.corr-panel.on{display:block}
.corr-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--bg);border-bottom:1px solid var(--bd)}
.corr-head h3{font-size:13px;font-weight:700;color:var(--t1);margin:0;display:flex;align-items:center;gap:7px}
.corr-body{padding:16px 20px;display:flex;flex-wrap:wrap;gap:12px}
.corr-item{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 16px;flex:1;min-width:160px}
.corr-item .ci-val{font-size:20px;font-weight:800;color:var(--t1);line-height:1}
.corr-item .ci-lbl{font-size:11px;color:var(--t2);margin-top:3px}
.corr-item .ci-sub{font-size:10.5px;color:var(--t3);margin-top:2px;font-family:'DM Mono',monospace}

/* TOOLBAR */
.al-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;animation:alUP .4s .1s both}
.al-sw{position:relative;flex:1;min-width:220px}
.al-sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none}
.al-si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.al-si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.al-si::placeholder{color:var(--t3)}
.al-sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.al-sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.al-drw{display:flex;align-items:center;gap:6px}
.al-drw span{font-size:12px;color:var(--t3);font-weight:500}
.al-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.al-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}

/* CHIPS */
.al-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;min-height:0}
.al-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;background:#E8F5E9;color:var(--grn);border:1px solid rgba(46,125,50,.25);border-radius:20px;padding:3px 10px}
.al-chip button{background:none;border:none;cursor:pointer;font-size:13px;color:var(--grn);line-height:1;padding:0;margin-left:2px;opacity:.7}
.al-chip button:hover{opacity:1}
.al-chip.vio{background:#FEE2E2;color:var(--red);border-color:rgba(220,38,38,.25)}
.al-chip.vio button{color:var(--red)}

/* TABLE CARD */
.al-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:alUP .4s .13s both}
.al-tbl{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed}
.al-tbl col.c-id  {width:100px}.al-tbl col.c-usr{width:145px}.al-tbl col.c-act{width:200px}
.al-tbl col.c-mod {width:90px} .al-tbl col.c-rec{width:140px}.al-tbl col.c-dt {width:135px}
.al-tbl col.c-ip  {width:115px}.al-tbl col.c-view{width:50px}
.al-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none}
.al-tbl thead th.ns{cursor:default}
.al-tbl thead th:hover:not(.ns){color:var(--grn)}
.al-tbl thead th.srt{color:var(--grn)}
.al-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle}
.al-tbl thead th.srt .sic{opacity:1}
.al-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s}
.al-tbl tbody tr:last-child{border-bottom:none}
.al-tbl tbody tr:hover{background:var(--hbg)}
.al-tbl tbody tr.vio-row{background:#FFF5F5}
.al-tbl tbody tr.vio-row:hover{background:#FEE2E2}
.al-tbl tbody td{padding:11px 12px;vertical-align:middle;max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.al-tbl tbody td:last-child{overflow:visible;max-width:none;padding:8px;text-align:center}

/* CELLS */
.lid-cell{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--t2)}
.usr-cell{display:flex;align-items:center;gap:7px;min-width:0}
.usr-av{width:26px;height:26px;border-radius:50%;font-size:9px;font-weight:700;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.usr-nm{font-weight:600;color:var(--t1);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.act-cell{display:flex;align-items:center;gap:6px;min-width:0}
.act-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.act-txt{font-size:12.5px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rec-cell{font-size:11.5px;color:var(--t2);font-family:'DM Mono',monospace}
.dt-cell{font-size:11.5px;color:var(--t2);white-space:nowrap}
.ip-cell{font-family:'DM Mono',monospace;font-size:11px;color:var(--t3);white-space:nowrap}
.mod-pill{display:inline-flex;align-items:center;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;white-space:nowrap}
.mp-SWS{background:#EFF6FF;color:var(--blu)}.mp-PSM{background:#DCFCE7;color:#166534}
.mp-PLT{background:#FEF3C7;color:#92400E}.mp-ALMS{background:#CCFBF1;color:var(--tel)}
.mp-DTRS{background:#F5F3FF;color:var(--pur)}.mp-System{background:#FEE2E2;color:var(--red)}
.mp-User-Mgmt,.mp-User\ Mgmt{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0}
.act-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;white-space:nowrap}
.ab-create{background:#DCFCE7;color:#166534}.ab-edit{background:#EFF6FF;color:var(--blu)}
.ab-delete{background:#FEE2E2;color:var(--red)}.ab-approve{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0}
.ab-login{background:#F5F3FF;color:var(--pur)}.ab-view{background:#F3F4F6;color:#6B7280}
.ab-export{background:#CCFBF1;color:var(--tel)}
.vio-flag{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;color:var(--red);background:#FEE2E2;border:1px solid #FECACA;border-radius:5px;padding:2px 6px;margin-left:6px}
.vio-flag i{font-size:11px}
.view-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:14px;color:var(--t2);transition:var(--tr)}
.view-btn:hover{background:var(--hbg);color:var(--grn);border-color:var(--grn)}

/* PAGINATION */
.al-pg{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2)}
.pg-btns{display:flex;gap:5px}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1)}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn)}
.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff}
.pgb:disabled{opacity:.4;pointer-events:none}

/* EMPTY */
.al-empty{padding:72px 20px;text-align:center;color:var(--t3)}
.al-empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9}

/* DETAIL MODAL */
#detailModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;padding:20px}
#detailModal.on{opacity:1;pointer-events:all}
.dm-box{background:#fff;border-radius:20px;width:620px;max-width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.22);overflow:hidden}
.dm-hd{padding:22px 26px 0;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0}
.dm-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.dm-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.dm-title{font-size:16px;font-weight:800;color:var(--t1)}
.dm-sub{font-size:12px;color:var(--t2);margin-top:3px;font-family:'DM Mono',monospace}
.dm-cl{width:34px;height:34px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr)}
.dm-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.dm-chips{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}
.dm-chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--t2);background:#fff;border:1px solid var(--bd);border-radius:7px;padding:4px 10px}
.dm-chip i{font-size:13px;color:var(--grn)}
.dm-bd{flex:1;overflow-y:auto;padding:20px 26px}
.dm-bd::-webkit-scrollbar{width:4px}
.dm-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.dm-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.dm-fi label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);display:block;margin-bottom:4px}
.dm-fi .v{font-size:13px;font-weight:500;color:var(--t1);line-height:1.5}
.dm-fi .v.mono{font-family:'DM Mono',monospace;font-size:12px;color:var(--t2)}
.dm-full{grid-column:1/-1}
.dm-vio-note{display:flex;align-items:flex-start;gap:8px;background:#FFF5F5;border:1px solid #FECACA;border-radius:10px;padding:10px 14px;font-size:12px;color:#991B1B;margin-top:4px}
.dm-vio-note i{font-size:15px;flex-shrink:0;margin-top:1px}
.dm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;margin-top:4px}
.dm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px}
.dm-ft{padding:14px 26px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap}

/* TOASTS */
.al-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.al-toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:tIN .3s ease}
.al-toast.ts{background:var(--grn)}.al-toast.tw{background:var(--amb)}
.al-toast.out{animation:tOUT .3s ease forwards}

@keyframes alUP{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes tIN {from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)}}
@keyframes tOUT{from{opacity:1;transform:translateY(0)}  to{opacity:0;transform:translateY(8px)}}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){.al-stats{grid-template-columns:repeat(3,1fr)}.dm-grid{grid-template-columns:1fr}}
@media(max-width:600px){.al-stats{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="al-wrap">

  <!-- PAGE HEADER -->
  <div class="al-ph">
    <div>
      <p class="ey">System Administration</p>
      <h1>Audit Logs</h1>
    </div>
    <div class="al-ph-r">
      <span class="sa-excl"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
      <button class="abtn abtn-purple abtn-sm" id="corrBtn"><i class="bx bx-git-merge"></i> Cross-Module Correlation</button>
      <button class="abtn abtn-warn abtn-sm"   id="vioBtn"><i class="bx bx-error-circle"></i> Compliance Violations</button>
      <button class="abtn abtn-primary abtn-sm" id="exportBtn"><i class="bx bx-export"></i> Export Audit Trail</button>
    </div>
  </div>

  <!-- STATS -->
  <div class="al-stats" id="statsBar">
    <?php for ($i = 0; $i < 5; $i++): ?>
    <div class="al-sc"><div class="al-sc-ic skeleton" style="width:38px;height:38px"></div><div><div class="skeleton" style="height:16px;width:36px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <?php endfor; ?>
  </div>

  <!-- VIOLATION BANNER -->
  <div class="al-vio-banner" id="vioBanner" style="display:none">
    <i class="bx bx-shield-x"></i>
    <div>
      <div class="vb-title">Compliance Violation Filter Active</div>
      <div class="vb-text" id="vioBannerText">Showing flagged actions that may violate RA 9184 or system security policies.</div>
      <div class="vb-acts">
        <button class="abtn abtn-red abtn-xs" onclick="exportViolations()"><i class="bx bx-download"></i> Export Violations Report</button>
        <button class="abtn abtn-ghost abtn-xs" onclick="clearVioFilter()"><i class="bx bx-x"></i> Clear Filter</button>
      </div>
    </div>
  </div>

  <!-- CROSS-MODULE CORRELATION PANEL -->
  <div class="corr-panel" id="corrPanel">
    <div class="corr-head">
      <h3><i class="bx bx-git-merge" style="color:var(--pur)"></i> Cross-Module Action Correlation</h3>
      <button class="abtn abtn-ghost abtn-xs" onclick="document.getElementById('corrPanel').classList.remove('on')"><i class="bx bx-x"></i> Close</button>
    </div>
    <div class="corr-body" id="corrBody">
      <div class="skeleton" style="height:80px;flex:1;border-radius:10px"></div>
      <div class="skeleton" style="height:80px;flex:1;border-radius:10px"></div>
      <div class="skeleton" style="height:80px;flex:1;border-radius:10px"></div>
    </div>
  </div>

  <!-- TOOLBAR -->
  <div class="al-tb">
    <div class="al-sw"><i class="bx bx-search"></i><input type="text" class="al-si" id="srch" placeholder="Search by Log ID, user, action, or record…"></div>
    <select class="al-sel" id="fModule">
      <option value="">All Modules</option>
      <option>SWS</option><option>PSM</option><option>PLT</option>
      <option>ALMS</option><option>DTRS</option><option>System</option><option>User Mgmt</option>
    </select>
    <select class="al-sel" id="fAction">
      <option value="">All Actions</option>
      <option>Create</option><option>Edit</option><option>Delete</option>
      <option>Approve</option><option>Login</option><option>View</option><option>Export</option>
    </select>
    <div class="al-drw">
      <input type="date" class="al-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="al-date" id="fDateTo" title="Date To">
    </div>
  </div>

  <!-- ACTIVE FILTER CHIPS -->
  <div class="al-chips" id="filterChips"></div>

  <!-- TABLE -->
  <div class="al-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
      <table class="al-tbl" id="tbl">
        <colgroup>
          <col class="c-id"><col class="c-usr"><col class="c-act"><col class="c-mod">
          <col class="c-rec"><col class="c-dt"><col class="c-ip"><col class="c-view">
        </colgroup>
        <thead>
          <tr>
            <th data-col="log_id">Log ID <i class="bx bx-sort sic"></i></th>
            <th data-col="actor_name">User <i class="bx bx-sort sic"></i></th>
            <th data-col="action_label">Action <i class="bx bx-sort sic"></i></th>
            <th data-col="module">Module <i class="bx bx-sort sic"></i></th>
            <th data-col="record_ref">Record Ref <i class="bx bx-sort sic"></i></th>
            <th data-col="occurred_at">Date &amp; Time <i class="bx bx-sort sic"></i></th>
            <th data-col="ip_address">IP Address <i class="bx bx-sort sic"></i></th>
            <th class="ns" style="text-align:center">View</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <tr><td colspan="8" style="padding:32px;text-align:center"><div class="skeleton" style="height:14px;width:50%;margin:0 auto"></div></td></tr>
        </tbody>
      </table>
    </div>
    <div class="al-pg" id="pager"></div>
  </div>

</div>
</main>

<div class="al-toasts" id="toastWrap"></div>

<!-- DETAIL MODAL -->
<div id="detailModal">
  <div class="dm-box">
    <div class="dm-hd">
      <div class="dm-top">
        <div style="display:flex;align-items:center;gap:12px">
          <div class="dm-icon" id="dmIcon"></div>
          <div><div class="dm-title" id="dmTitle"></div><div class="dm-sub" id="dmSub"></div></div>
        </div>
        <button class="dm-cl" id="dmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="dm-chips" id="dmChips"></div>
    </div>
    <div class="dm-bd"><div class="dm-grid" id="dmGrid"></div></div>
    <div class="dm-ft">
      <button class="abtn abtn-ghost abtn-sm" id="dmCloseFt">Close</button>
      <button class="abtn abtn-primary abtn-sm" id="dmExportEntry"><i class="bx bx-export"></i> Export Entry</button>
    </div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
    const r = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet = p => apiFetch(p);

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc     = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtTs   = d => { try{ return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }catch{return d;} };
const todayStr = () => new Date().toISOString().split('T')[0];
const COLORS  = ['#2E7D32','#DC2626','#2563EB','#0D9488','#7C3AED','#D97706','#059669','#6B7280','#0891B2','#B45309'];
const gc      = n => COLORS[Math.abs(String(n).split('').reduce((h,c)=>h*31+c.charCodeAt(0)|0,0))%COLORS.length];
const ini     = n => String(n).split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const modPillClass = m => { const k = (m||'').replace(/\s+/g,'-'); return `mp-${k}`; };

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentPage  = 1;
const PER_PAGE   = 15;
let sortCol      = 'occurred_at';
let sortDir      = 'desc';
let vioOnly      = false;
let cachedDetail = null; // holds last opened log entry for export

// ── INIT ──────────────────────────────────────────────────────────────────────
loadStats();
loadTable();

// ── STATS ─────────────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const d = await apiGet(API + '?api=stats');
        document.getElementById('statsBar').innerHTML = `
          <div class="al-sc"><div class="al-sc-ic ic-b"><i class="bx bx-list-ul"></i></div><div><div class="al-sc-v">${d.total.toLocaleString()}</div><div class="al-sc-l">Total Log Entries</div></div></div>
          <div class="al-sc"><div class="al-sc-ic ic-g"><i class="bx bx-calendar-check"></i></div><div><div class="al-sc-v">${d.today}</div><div class="al-sc-l">Entries Today</div></div></div>
          <div class="al-sc"><div class="al-sc-ic ic-r"><i class="bx bx-shield-x"></i></div><div><div class="al-sc-v">${d.vio}</div><div class="al-sc-l">Compliance Flags</div></div></div>
          <div class="al-sc"><div class="al-sc-ic ic-p"><i class="bx bx-layer"></i></div><div><div class="al-sc-v">${d.modules}</div><div class="al-sc-l">Modules Tracked</div></div></div>
          <div class="al-sc"><div class="al-sc-ic ic-t"><i class="bx bx-shield-quarter"></i></div><div><div class="al-sc-v">✓</div><div class="al-sc-l">Immutable Log</div></div></div>`;
    } catch(e) { toast('Stats error: ' + e.message, 'w'); }
}

// ── TABLE ─────────────────────────────────────────────────────────────────────
async function loadTable() {
    const params = new URLSearchParams({
        api:    'list',
        page:   currentPage,
        per:    PER_PAGE,
        sort:   sortCol,
        dir:    sortDir,
        vio:    vioOnly ? '1' : '0',
        ...(document.getElementById('srch').value.trim()  && { q:      document.getElementById('srch').value.trim() }),
        ...(document.getElementById('fModule').value       && { module: document.getElementById('fModule').value }),
        ...(document.getElementById('fAction').value       && { action: document.getElementById('fAction').value }),
        ...(document.getElementById('fDateFrom').value     && { from:   document.getElementById('fDateFrom').value }),
        ...(document.getElementById('fDateTo').value       && { to:     document.getElementById('fDateTo').value }),
    });
    try {
        const d = await apiGet(API + '?' + params);
        renderTable(d);
        renderChips();
    } catch(e) {
        toast('Failed to load logs: ' + e.message, 'w');
        document.getElementById('tbody').innerHTML =
            `<tr><td colspan="8" style="padding:24px;text-align:center;color:var(--red);font-size:12px">Error loading data. Please refresh.</td></tr>`;
    }
}

function renderTable(d) {
    // Sort icons
    document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
        const c = th.dataset.col;
        th.classList.toggle('srt', c === sortCol);
        const ic = th.querySelector('.sic');
        if (ic) ic.className = `bx ${c === sortCol ? (sortDir==='asc'?'bx-sort-up':'bx-sort-down') : 'bx-sort'} sic`;
    });

    const tb = document.getElementById('tbody');
    if (!d.items.length) {
        tb.innerHTML = `<tr><td colspan="8"><div class="al-empty"><i class="bx bx-search-alt"></i><p>No log entries match your filters.</p></div></td></tr>`;
    } else {
        tb.innerHTML = d.items.map(l => `
          <tr class="${l.isVio ? 'vio-row' : ''}">
            <td><span class="lid-cell">${esc(l.id)}</span></td>
            <td>
              <div class="usr-cell">
                <div class="usr-av" style="background:${gc(l.actorName)}">${ini(l.actorName)}</div>
                <span class="usr-nm" title="${esc(l.actorName)}">${esc(l.actorName)}</span>
              </div>
            </td>
            <td>
              <div class="act-cell">
                <span class="act-dot" style="background:${l.color}"></span>
                <span class="act-badge ${l.badgeClass}">${esc(l.actionType)}</span>
                <span class="act-txt" title="${esc(l.actionLabel)}">${esc(l.actionLabel)}</span>
                ${l.isVio ? `<span class="vio-flag"><i class="bx bx-error-circle"></i>Flag</span>` : ''}
              </div>
            </td>
            <td><span class="mod-pill ${modPillClass(l.module)}">${esc(l.module)}</span></td>
            <td><span class="rec-cell" title="${esc(l.recordRef)}">${esc(l.recordRef)}</span></td>
            <td><span class="dt-cell">${fmtTs(l.occurredAt)}</span></td>
            <td><span class="ip-cell">${esc(l.ip || '—')}</span></td>
            <td><button class="view-btn" onclick="openDetail(${JSON.stringify(l).replace(/"/g,'&quot;')})" title="View Details"><i class="bx bx-show"></i></button></td>
          </tr>`).join('');
    }

    // Pagination
    const total = d.total, pages = d.pages, page = d.page;
    const s = (page-1)*PER_PAGE+1, e = Math.min(page*PER_PAGE, total);
    let btns = '';
    for (let i = 1; i <= pages; i++) {
        if (i===1||i===pages||(i>=page-2&&i<=page+2))
            btns += `<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if (i===page-3||i===page+3) btns += `<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML = `
      <span>${total===0 ? 'No results' : `Showing ${s}–${e} of ${total.toLocaleString()} entries`}</span>
      <div class="pg-btns">
        <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
        ${btns}
        <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
      </div>`;
}

window.goPage = p => { currentPage = p; loadTable(); };

// ── SORT ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
    th.addEventListener('click', () => {
        const c = th.dataset.col;
        sortDir = sortCol === c ? (sortDir==='asc'?'desc':'asc') : 'desc';
        sortCol = c; currentPage = 1; loadTable();
    });
});

// ── FILTERS ───────────────────────────────────────────────────────────────────
['srch','fModule','fAction','fDateFrom','fDateTo'].forEach(id =>
    document.getElementById(id).addEventListener('input', () => { currentPage = 1; loadTable(); }));

function renderChips() {
    const chips = [];
    const vals = { fModule:{id:'fModule',label:'Module'}, fAction:{id:'fAction',label:'Action'}, fDateFrom:{id:'fDateFrom',label:'From'}, fDateTo:{id:'fDateTo',label:'To'} };
    Object.values(vals).forEach(({id,label}) => {
        const v = document.getElementById(id).value;
        if (v) chips.push(`<span class="al-chip">${label}: ${esc(v)}<button onclick="clearFilter('${id}')">×</button></span>`);
    });
    const q = document.getElementById('srch').value.trim();
    if (q) chips.push(`<span class="al-chip">Search: "${esc(q)}"<button onclick="document.getElementById('srch').value='';currentPage=1;loadTable()">×</button></span>`);
    if (vioOnly) chips.push(`<span class="al-chip vio"><i class="bx bx-shield-x" style="font-size:11px"></i> Compliance Violations Only<button onclick="clearVioFilter()">×</button></span>`);
    document.getElementById('filterChips').innerHTML = chips.join('');
}

window.clearFilter = id => { document.getElementById(id).value = ''; currentPage = 1; loadTable(); };
window.clearVioFilter = () => {
    vioOnly = false;
    document.getElementById('vioBanner').style.display = 'none';
    document.getElementById('vioBtn').className = 'abtn abtn-warn abtn-sm';
    document.getElementById('vioBtn').innerHTML = '<i class="bx bx-error-circle"></i> Compliance Violations';
    currentPage = 1; loadTable();
};

// ── VIOLATION FILTER ──────────────────────────────────────────────────────────
document.getElementById('vioBtn').addEventListener('click', () => {
    vioOnly = !vioOnly;
    const banner = document.getElementById('vioBanner');
    banner.style.display = vioOnly ? 'flex' : 'none';
    document.getElementById('vioBtn').className = vioOnly ? 'abtn abtn-red abtn-sm' : 'abtn abtn-warn abtn-sm';
    document.getElementById('vioBtn').innerHTML  = vioOnly
        ? '<i class="bx bx-x-circle"></i> Clear Violation Filter'
        : '<i class="bx bx-error-circle"></i> Compliance Violations';
    currentPage = 1; loadTable();
});

window.exportViolations = async () => {
    try {
        const rows = await apiGet(API + '?api=export&vio=1');
        exportCSV(rows, 'violations_report');
        toast(`Violations report exported — ${rows.length} entries.`, 's');
    } catch(e) { toast('Export failed: ' + e.message, 'w'); }
};

// ── CROSS-MODULE CORRELATION ──────────────────────────────────────────────────
document.getElementById('corrBtn').addEventListener('click', async () => {
    const panel = document.getElementById('corrPanel');
    if (panel.classList.contains('on')) { panel.classList.remove('on'); return; }
    panel.classList.add('on');

    try {
        const d = await apiGet(API + '?api=correlation');
        document.getElementById('corrBody').innerHTML = `
          <div class="corr-item"><div class="ci-val">${d.topModule.count}</div><div class="ci-lbl">Most Active Module</div><div class="ci-sub">${esc(d.topModule.name)}</div></div>
          <div class="corr-item"><div class="ci-val">${d.topAction.count}</div><div class="ci-lbl">Most Common Action</div><div class="ci-sub">${esc(d.topAction.name)}</div></div>
          <div class="corr-item"><div class="ci-val">${d.topUser.count}</div><div class="ci-lbl">Most Active User</div><div class="ci-sub">${esc((d.topUser.name||'').split(' ')[0])}</div></div>
          <div class="corr-item"><div class="ci-val">${d.crossUsers}</div><div class="ci-lbl">Cross-Module Users</div><div class="ci-sub">Active in 2+ modules</div></div>
          <div class="corr-item"><div class="ci-val">${d.psmCount}</div><div class="ci-lbl">PSM → SWS Flow</div><div class="ci-sub">PSM logs · SWS: ${d.swsCount}</div></div>
          <div class="corr-item"><div class="ci-val">${d.violations}</div><div class="ci-lbl">Compliance Flags</div><div class="ci-sub">Requires review</div></div>`;
    } catch(e) {
        document.getElementById('corrBody').innerHTML = `<div style="padding:20px;color:var(--red);font-size:12px">Error loading correlation data: ${esc(e.message)}</div>`;
    }
});

// ── DETAIL MODAL ──────────────────────────────────────────────────────────────
window.openDetail = l => {
    cachedDetail = l;
    document.getElementById('dmIcon').innerHTML = `<i class="bx ${esc(l.icon)}" style="color:${l.color}"></i>`;
    document.getElementById('dmIcon').style.background = l.color + '18';
    document.getElementById('dmTitle').textContent = l.actionLabel;
    document.getElementById('dmSub').textContent   = l.id;
    document.getElementById('dmChips').innerHTML = `
      <div class="dm-chip"><i class="bx bx-user"></i>${esc(l.actorName)} · ${esc(l.actorRole)}</div>
      <div class="dm-chip"><i class="bx bx-time-five"></i>${fmtTs(l.occurredAt)}</div>
      <span class="mod-pill ${modPillClass(l.module)}" style="font-size:11.5px;padding:4px 10px">${esc(l.module)}</span>
      <span class="act-badge ${l.badgeClass}" style="font-size:11px;padding:3px 9px">${esc(l.actionType)}</span>`;
    document.getElementById('dmGrid').innerHTML = `
      <div class="dm-fi"><label>Log ID</label><div class="v mono">${esc(l.id)}</div></div>
      <div class="dm-fi"><label>Performed By</label><div class="v">${esc(l.actorName)}</div></div>
      <div class="dm-fi"><label>Role</label><div class="v">${esc(l.actorRole)}</div></div>
      <div class="dm-fi"><label>Module</label><div class="v">${esc(l.module)}</div></div>
      <div class="dm-fi"><label>Action Type</label><div class="v">${esc(l.actionType)}</div></div>
      <div class="dm-fi"><label>Super Admin Action</label><div class="v">${l.isSuperAdmin ? 'Yes' : 'No'}</div></div>
      <div class="dm-fi dm-full"><label>Action Description</label><div class="v">${esc(l.actionLabel)}</div></div>
      <div class="dm-fi"><label>Record Reference</label><div class="v mono">${esc(l.recordRef || '—')}</div></div>
      <div class="dm-fi"><label>IP Address</label><div class="v mono">${esc(l.ip || '—')}</div></div>
      <div class="dm-fi dm-full"><label>Date &amp; Time</label><div class="v">${fmtTs(l.occurredAt)}</div></div>
      ${l.note ? `<div class="dm-fi dm-full"><label>Notes</label><div class="v">${esc(l.note)}</div></div>` : ''}
      ${l.isVio ? `<div class="dm-fi dm-full"><label>Compliance Flag</label><div class="dm-vio-note"><i class="bx bx-shield-x"></i><span>${esc(l.vioReason)}</span></div></div>` : ''}
      <div class="dm-fi dm-full"><div class="dm-sa-note"><i class="bx bx-shield-quarter"></i><span>This log entry is read-only and immutable. Audit records cannot be edited or deleted. Super Admin access only.</span></div></div>`;
    document.getElementById('detailModal').classList.add('on');
};

document.getElementById('dmClose').addEventListener('click',   () => document.getElementById('detailModal').classList.remove('on'));
document.getElementById('dmCloseFt').addEventListener('click', () => document.getElementById('detailModal').classList.remove('on'));
document.getElementById('detailModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('on'); });
document.getElementById('dmExportEntry').addEventListener('click', () => {
    if (!cachedDetail) return;
    const cols = ['id','module','actionType','actionLabel','actorName','actorRole','recordRef','ip','occurredAt','isSuperAdmin','isVio','vioReason'];
    const lines = [cols.join(','), cols.map(c => `"${String(cachedDetail[c]??'').replace(/"/g,'""')}"`).join(',')];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\n')], {type:'text/csv'}));
    a.download = `${cachedDetail.id}.csv`; a.click();
    toast('Log entry exported.', 's');
});

// ── EXPORT ────────────────────────────────────────────────────────────────────
document.getElementById('exportBtn').addEventListener('click', async () => {
    const btn = document.getElementById('exportBtn');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Exporting…`;
    try {
        const p = new URLSearchParams({
            ...(document.getElementById('fModule').value    && { module: document.getElementById('fModule').value }),
            ...(document.getElementById('fAction').value    && { action: document.getElementById('fAction').value }),
            ...(document.getElementById('fDateFrom').value  && { from:   document.getElementById('fDateFrom').value }),
            ...(document.getElementById('fDateTo').value    && { to:     document.getElementById('fDateTo').value }),
            ...(vioOnly && { vio: '1' }),
            ...(document.getElementById('srch').value.trim() && { q: document.getElementById('srch').value.trim() }),
        });
        const rows = await apiGet(API + '?api=export&' + p);
        exportCSV(rows, 'audit_log_export');
        toast(`Exported ${rows.length} audit entries.`, 's');
    } catch(e) { toast('Export failed: ' + e.message, 'w'); }
    finally { btn.disabled = false; btn.innerHTML = `<i class="bx bx-export"></i> Export Audit Trail`; }
});

function exportCSV(rows, filename) {
    const cols = ['id','module','actionType','actionLabel','actorName','actorRole','recordRef','ip','occurredAt','isSuperAdmin','isVio','vioReason'];
    const hdrs = ['Log ID','Module','Action Type','Action Description','User','Role','Record Ref','IP Address','Date & Time','SA Action','Compliance Flag','Violation Reason'];
    const lines = [hdrs.join(','), ...rows.map(r => cols.map(c => `"${String(r[c]??'').replace(/"/g,'""')}"`).join(','))];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\n')], {type:'text/csv'}));
    a.download = `${filename}_${todayStr()}.csv`; a.click();
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 's') {
    const ic = { s:'bx-check-circle', w:'bx-error' };
    const el = document.createElement('div');
    el.className = `al-toast t${type}`;
    el.innerHTML = `<i class="bx ${ic[type]||ic.s}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3500);
}
</script>
</body>
</html>