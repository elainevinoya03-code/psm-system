<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── GUARD: must be logged in ──────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];
$ip            = $_SERVER['REMOTE_ADDR'] ?? null;

// ── SUPABASE HELPER ───────────────────────────────────────────────────────────
function prof_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
    $data = json_decode($res, true);
    if ($code >= 400) throw new RuntimeException(is_array($data) ? ($data['message'] ?? $res) : $res);
    return is_array($data) ? $data : [];
}

// ── JSON RESPONSE HELPERS ─────────────────────────────────────────────────────
function prof_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function prof_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function prof_body(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    try {

        // ── GET profile ───────────────────────────────────────────────────────
        if ($api === 'profile' && $method === 'GET') {
            // Fetch user + roles via users_with_roles view
            $rows = prof_sb('users_with_roles', 'GET', [
                'select'  => 'user_id,first_name,last_name,email,zone,status,emp_id,phone,permissions,remarks,last_login,created_at,updated_at,roles',
                'user_id' => 'eq.' . $currentUserId,
                'limit'   => '1',
            ]);
            if (empty($rows)) prof_err('User not found', 404);
            $u = $rows[0];

            // Fetch recent activity (last 10 audit log entries)
            $activity = prof_sb('audit_logs', 'GET', [
                'select'  => 'id,action,performed_by,ip_address,remarks,is_sa,created_at',
                'user_id' => 'eq.' . $currentUserId,
                'order'   => 'created_at.desc',
                'limit'   => '10',
            ]);

            prof_ok([
                'userId'     => $u['user_id']    ?? '',
                'firstName'  => $u['first_name'] ?? '',
                'lastName'   => $u['last_name']  ?? '',
                'fullName'   => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                'email'      => $u['email']       ?? '',
                'zone'       => $u['zone']        ?? '',
                'status'     => $u['status']      ?? 'Active',
                'empId'      => $u['emp_id']      ?? '',
                'phone'      => $u['phone']       ?? '',
                'permissions'=> $u['permissions'] ?? [],
                'remarks'    => $u['remarks']     ?? '',
                'lastLogin'  => $u['last_login']  ?? null,
                'createdAt'  => $u['created_at']  ?? '',
                'updatedAt'  => $u['updated_at']  ?? '',
                'roles'      => $u['roles']       ?? [],
                'activity'   => $activity,
            ]);
        }

        // ── POST update profile ───────────────────────────────────────────────
        if ($api === 'update' && $method === 'POST') {
            $b         = prof_body();
            $firstName = trim($b['firstName'] ?? '');
            $lastName  = trim($b['lastName']  ?? '');
            $phone     = trim($b['phone']     ?? '');
            $zone      = trim($b['zone']      ?? '');

            if (!$firstName) prof_err('First name is required.', 400);
            if (!$lastName)  prof_err('Last name is required.', 400);

            $now = date('Y-m-d H:i:s');
            prof_sb('users', 'PATCH', ['user_id' => 'eq.' . $currentUserId], [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'phone'      => $phone ?: null,
                'zone'       => $zone  ?: null,
                'updated_at' => $now,
            ]);

            // Log the update
            prof_sb('audit_logs', 'POST', [], [[
                'user_id'      => $currentUserId,
                'action'       => 'Profile Updated',
                'performed_by' => trim("$firstName $lastName"),
                'ip_address'   => $ip,
                'remarks'      => 'User updated their own profile.',
                'is_sa'        => false,
                'created_at'   => $now,
            ]]);

            // Sync session
            $_SESSION['full_name'] = trim("$firstName $lastName");

            prof_ok(['message' => 'Profile updated successfully.']);
        }

        // ── POST change password ──────────────────────────────────────────────
        if ($api === 'change-password' && $method === 'POST') {
            $b           = prof_body();
            $newPassword = trim($b['newPassword'] ?? '');
            $confirm     = trim($b['confirm']      ?? '');

            if (strlen($newPassword) < 8)       prof_err('Password must be at least 8 characters.', 400);
            if ($newPassword !== $confirm)       prof_err('Passwords do not match.', 400);
            if (!preg_match('/[A-Z]/', $newPassword)) prof_err('Password must contain at least one uppercase letter.', 400);
            if (!preg_match('/[0-9]/', $newPassword)) prof_err('Password must contain at least one number.', 400);

            // Get auth_id for this user
            $rows = prof_sb('users', 'GET', [
                'select'  => 'auth_id',
                'user_id' => 'eq.' . $currentUserId,
                'limit'   => '1',
            ]);
            if (empty($rows) || empty($rows[0]['auth_id'])) prof_err('Auth ID not found.', 404);
            $authId = $rows[0]['auth_id'];

            // Call Supabase Admin API to update password
            $ch = curl_init(SUPABASE_URL . '/auth/v1/admin/users/' . $authId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
                    'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                ],
                CURLOPT_POSTFIELDS => json_encode(['password' => $newPassword]),
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 400) {
                $err = json_decode($res, true);
                prof_err($err['message'] ?? 'Password update failed.', 400);
            }

            // Audit log
            $now = date('Y-m-d H:i:s');
            prof_sb('audit_logs', 'POST', [], [[
                'user_id'      => $currentUserId,
                'action'       => 'Password Changed',
                'performed_by' => $_SESSION['full_name'] ?? $currentUserId,
                'ip_address'   => $ip,
                'remarks'      => 'User changed their own password.',
                'is_sa'        => false,
                'created_at'   => $now,
            ]]);

            prof_ok(['message' => 'Password changed successfully.']);
        }

        prof_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        prof_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML RENDER ───────────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Logistics 1</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
/* ── FOUNDATIONS ──────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;}
#mainContent{
    --grn:#2E7D32;--gdk:#1B5E20;--glt:#E8F5E9;
    --t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;
    --s:#fff;--bg:var(--bg-color);--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
    --hbg:var(--hover-bg-light);
    --red:#DC2626;--amb:#D97706;--blu:#2563EB;
    --shsm:0 2px 8px rgba(46,125,50,.10);
    --shmd:0 4px 24px rgba(46,125,50,.13);
    --shlg:0 12px 48px rgba(0,0,0,.16);
    --rad:14px;--tr:all .18s ease;
    font-family:'Sora',sans-serif;
}

/* ── LAYOUT ───────────────────────────────────────────────────────────────── */
.pf-wrap{max-width:960px;margin:0 auto;padding:0 0 5rem;}

/* ── PAGE HEADER ──────────────────────────────────────────────────────────── */
.pf-ph{margin-bottom:32px;animation:fadeUp .4s both;}
.pf-ph .eyebrow{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--grn);margin-bottom:5px;}
.pf-ph h1{font-size:28px;font-weight:800;color:var(--t1);line-height:1.15;}

/* ── HERO CARD ────────────────────────────────────────────────────────────── */
.pf-hero{
    background:var(--s);
    border:1px solid var(--bd);
    border-radius:20px;
    overflow:hidden;
    box-shadow:var(--shmd);
    margin-bottom:20px;
    animation:fadeUp .4s .05s both;
    position:relative;
}
.pf-hero-banner{
    height:88px;
    background:linear-gradient(135deg,#1B5E20 0%,#2E7D32 45%,#388E3C 70%,#43A047 100%);
    position:relative;
    overflow:hidden;
}
.pf-hero-banner::before{
    content:'';position:absolute;inset:0;
    background:repeating-linear-gradient(45deg,transparent,transparent 24px,rgba(255,255,255,.04) 24px,rgba(255,255,255,.04) 25px);
}
.pf-hero-banner::after{
    content:'';position:absolute;right:-40px;top:-40px;
    width:180px;height:180px;border-radius:50%;
    background:rgba(255,255,255,.06);
}
.pf-hero-body{padding:0 28px 24px;display:flex;align-items:flex-end;gap:20px;margin-top:-32px;position:relative;flex-wrap:wrap;}

/* AVATAR */
.pf-avatar{
    width:72px;height:72px;border-radius:18px;
    display:flex;align-items:center;justify-content:center;
    font-size:26px;font-weight:800;color:#fff;
    border:3px solid #fff;box-shadow:var(--shmd);
    flex-shrink:0;cursor:default;user-select:none;
    transition:transform .2s;
}
.pf-avatar:hover{transform:scale(1.04);}

.pf-hero-info{flex:1;min-width:0;padding-top:36px;}
.pf-hero-name{font-size:22px;font-weight:800;color:var(--t1);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.pf-hero-sub{font-size:12.5px;color:var(--t2);margin-top:4px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.pf-hero-sub span{display:inline-flex;align-items:center;gap:5px;}
.pf-hero-sub i{font-size:14px;color:var(--grn);}

/* STATUS BADGE */
.status-dot{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;padding:4px 11px;border-radius:20px;}
.sd-active{background:#DCFCE7;color:#166534;}
.sd-inactive{background:#F3F4F6;color:#374151;}
.sd-suspended{background:#FEF3C7;color:#92400E;}
.sd-locked{background:#FEE2E2;color:#991B1B;}
.status-dot::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;}

/* ROLE CHIPS */
.role-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;}
.role-chip{font-size:11px;font-weight:700;padding:3px 10px;border-radius:8px;letter-spacing:.03em;}
.rc-sa{background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;}
.rc-admin{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}
.rc-mgr{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.rc-staff{background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;}
.rc-default{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}

/* STATS ROW */
.pf-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;border-top:1px solid var(--bd);background:var(--bd);}
.pf-stat{background:var(--s);padding:14px 20px;text-align:center;}
.pf-stat .sv{font-size:18px;font-weight:800;color:var(--t1);font-family:'DM Mono',monospace;}
.pf-stat .sl{font-size:11px;color:var(--t2);margin-top:2px;}

/* ── GRID ─────────────────────────────────────────────────────────────────── */
.pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.pf-col{display:flex;flex-direction:column;gap:16px;}

/* ── CARD ─────────────────────────────────────────────────────────────────── */
.pf-card{
    background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);
    box-shadow:var(--shsm);overflow:hidden;
}
.pf-card.anim-1{animation:fadeUp .4s .1s both;}
.pf-card.anim-2{animation:fadeUp .4s .15s both;}
.pf-card.anim-3{animation:fadeUp .4s .2s both;}
.pf-card.anim-4{animation:fadeUp .4s .25s both;}

.card-hdr{
    display:flex;align-items:center;justify-content:space-between;
    padding:16px 20px;border-bottom:1px solid var(--bd);background:var(--bg);
}
.card-hdr-l{display:flex;align-items:center;gap:9px;}
.card-hdr-l i{font-size:17px;color:var(--grn);}
.card-hdr-l h3{font-size:13.5px;font-weight:700;color:var(--t1);}
.card-hdr-r{display:flex;align-items:center;gap:7px;}
.card-body{padding:20px;}

/* ── FORM FIELDS ──────────────────────────────────────────────────────────── */
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.fg:last-child{margin-bottom:0;}
.fl{font-size:10.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--t3);}
.fl span{color:var(--red);margin-left:2px;}
.fi,.fs{
    font-family:'Sora',sans-serif;font-size:13px;
    padding:10px 13px;border:1px solid var(--bdm);border-radius:10px;
    background:var(--s);color:var(--t1);outline:none;
    transition:var(--tr);width:100%;
}
.fi:focus,.fs:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.fi:read-only,.fi[disabled]{background:var(--bg);color:var(--t2);cursor:default;}
.fi::placeholder{color:var(--t3);}
.fs{appearance:none;cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* PASSWORD STRENGTH */
.pw-strength{height:4px;border-radius:2px;margin-top:6px;background:var(--bd);overflow:hidden;}
.pw-fill{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0;}
.pw-label{font-size:11px;color:var(--t3);margin-top:3px;}

/* ── BUTTONS ──────────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}
.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-danger{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}
.btn-danger:hover{background:#FECACA;}
.btn-sm{font-size:12px;padding:6px 13px;}
.btn:disabled{opacity:.45;pointer-events:none;}
.btn-row{display:flex;gap:9px;justify-content:flex-end;margin-top:16px;}

/* ── INFO ROWS ────────────────────────────────────────────────────────────── */
.info-list{display:flex;flex-direction:column;gap:0;}
.info-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid rgba(46,125,50,.07);gap:12px;}
.info-row:last-child{border-bottom:none;padding-bottom:0;}
.info-row:first-child{padding-top:0;}
.ir-label{font-size:11.5px;color:var(--t3);font-weight:600;display:flex;align-items:center;gap:6px;flex-shrink:0;}
.ir-label i{font-size:14px;color:var(--grn);}
.ir-val{font-size:13px;color:var(--t1);font-weight:500;text-align:right;word-break:break-all;}
.ir-val.mono{font-family:'DM Mono',monospace;font-size:12px;color:var(--grn);}
.ir-val.muted{color:var(--t2);font-weight:400;}

/* ── ACTIVITY LOG ─────────────────────────────────────────────────────────── */
.activity-list{display:flex;flex-direction:column;gap:0;}
.activity-item{display:flex;align-items:flex-start;gap:12px;padding:11px 0;border-bottom:1px solid rgba(46,125,50,.07);}
.activity-item:last-child{border-bottom:none;padding-bottom:0;}
.activity-item:first-child{padding-top:0;}
.act-dot{
    width:28px;height:28px;border-radius:8px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:13px;
}
.act-dot-default{background:#EFF6FF;color:#2563EB;}
.act-dot-pw{background:#FEF3C7;color:#D97706;}
.act-dot-login{background:#DCFCE7;color:#166534;}
.act-body{flex:1;min-width:0;}
.act-action{font-size:12.5px;font-weight:600;color:var(--t1);}
.act-meta{font-size:11px;color:var(--t3);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.act-meta .ip{font-family:'DM Mono',monospace;background:var(--bg);border:1px solid var(--bd);padding:1px 6px;border-radius:4px;font-size:10px;}
.act-ts{font-size:10.5px;color:var(--t3);font-family:'DM Mono',monospace;flex-shrink:0;margin-left:auto;white-space:nowrap;}

/* ── PERMISSIONS ──────────────────────────────────────────────────────────── */
.perm-grid{display:flex;flex-wrap:wrap;gap:6px;}
.perm-tag{font-size:11px;font-weight:600;padding:3px 10px;border-radius:7px;background:var(--glt);color:var(--grn);border:1px solid rgba(46,125,50,.2);}
.perm-empty{font-size:12.5px;color:var(--t3);font-style:italic;}

/* ── SKELETON LOADER ─────────────────────────────────────────────────────── */
.skeleton{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.06) 50%,var(--bg) 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:8px;}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.sk-line{height:14px;border-radius:7px;margin-bottom:8px;}
.sk-circle{width:72px;height:72px;border-radius:18px;}

/* ── TOAST ────────────────────────────────────────────────────────────────── */
.pf-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:11px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:200px;animation:toastIn .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}
.toast.out{animation:toastOut .3s ease forwards;}

/* ── EDIT TOGGLE ─────────────────────────────────────────────────────────── */
.edit-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;vertical-align:middle;}

/* ── DIVIDER ─────────────────────────────────────────────────────────────── */
.pf-divider{font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;margin:4px 0 12px;}
.pf-divider::after{content:'';flex:1;height:1px;background:var(--bd);}

/* ── ANIMATIONS ───────────────────────────────────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(6px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}

@media(max-width:700px){
    .pf-grid{grid-template-columns:1fr;}
    .pf-stats{grid-template-columns:1fr 1fr;}
    .pf-hero-body{gap:14px;}
    .fr{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="pf-wrap">

  <!-- PAGE HEADER -->
  <div class="pf-ph">
    <p class="eyebrow">Account</p>
    <h1>My Profile</h1>
  </div>

  <!-- HERO CARD (skeleton until loaded) -->
  <div class="pf-hero" id="heroCard">
    <div class="pf-hero-banner"></div>
    <div class="pf-hero-body">
      <div class="pf-avatar skeleton sk-circle" id="heroAvatar"></div>
      <div class="pf-hero-info">
        <div class="skeleton sk-line" style="width:200px;height:22px"></div>
        <div class="skeleton sk-line" style="width:280px;height:14px;margin-top:8px"></div>
        <div class="role-chips" id="heroRoles">
          <div class="skeleton sk-line" style="width:80px;height:22px"></div>
        </div>
      </div>
    </div>
    <div class="pf-stats" id="heroStats">
      <div class="pf-stat"><div class="skeleton sk-line" style="width:60px;margin:0 auto"></div></div>
      <div class="pf-stat"><div class="skeleton sk-line" style="width:60px;margin:0 auto"></div></div>
      <div class="pf-stat"><div class="skeleton sk-line" style="width:60px;margin:0 auto"></div></div>
    </div>
  </div>

  <!-- TWO-COLUMN GRID -->
  <div class="pf-grid">

    <!-- LEFT COLUMN -->
    <div class="pf-col">

      <!-- ACCOUNT INFORMATION (read-only) -->
      <div class="pf-card anim-1">
        <div class="card-hdr">
          <div class="card-hdr-l"><i class="bx bx-id-card"></i><h3>Account Information</h3></div>
        </div>
        <div class="card-body">
          <div class="info-list" id="accountInfo">
            <div class="info-row"><span class="ir-label"><i class="bx bx-hash"></i>User ID</span><span class="skeleton sk-line" style="width:80px"></span></div>
            <div class="info-row"><span class="ir-label"><i class="bx bx-briefcase-alt"></i>Employee ID</span><span class="skeleton sk-line" style="width:90px"></span></div>
            <div class="info-row"><span class="ir-label"><i class="bx bx-envelope"></i>Email</span><span class="skeleton sk-line" style="width:160px"></span></div>
            <div class="info-row"><span class="ir-label"><i class="bx bx-map-pin"></i>Zone</span><span class="skeleton sk-line" style="width:100px"></span></div>
            <div class="info-row"><span class="ir-label"><i class="bx bx-time"></i>Last Login</span><span class="skeleton sk-line" style="width:130px"></span></div>
            <div class="info-row"><span class="ir-label"><i class="bx bx-calendar-plus"></i>Member Since</span><span class="skeleton sk-line" style="width:110px"></span></div>
          </div>
        </div>
      </div>

      <!-- PERMISSIONS -->
      <div class="pf-card anim-2">
        <div class="card-hdr">
          <div class="card-hdr-l"><i class="bx bx-key"></i><h3>Permissions</h3></div>
        </div>
        <div class="card-body">
          <div id="permissionsWrap">
            <div class="skeleton sk-line" style="width:100%"></div>
          </div>
        </div>
      </div>

      <!-- RECENT ACTIVITY -->
      <div class="pf-card anim-3">
        <div class="card-hdr">
          <div class="card-hdr-l"><i class="bx bx-history"></i><h3>Recent Activity</h3></div>
          <span style="font-size:11px;color:var(--t3)">Last 10 actions</span>
        </div>
        <div class="card-body">
          <div class="activity-list" id="activityList">
            <div class="activity-item">
              <div class="skeleton sk-circle" style="width:28px;height:28px;border-radius:8px;flex-shrink:0"></div>
              <div style="flex:1"><div class="skeleton sk-line" style="width:80%"></div><div class="skeleton sk-line" style="width:50%;height:10px"></div></div>
            </div>
            <div class="activity-item">
              <div class="skeleton sk-circle" style="width:28px;height:28px;border-radius:8px;flex-shrink:0"></div>
              <div style="flex:1"><div class="skeleton sk-line" style="width:70%"></div><div class="skeleton sk-line" style="width:40%;height:10px"></div></div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /LEFT -->

    <!-- RIGHT COLUMN -->
    <div class="pf-col">

      <!-- EDIT PERSONAL INFO -->
      <div class="pf-card anim-1">
        <div class="card-hdr">
          <div class="card-hdr-l"><i class="bx bx-edit-alt"></i><h3>Edit Profile</h3></div>
          <span class="edit-badge"><i class="bx bx-pencil" style="font-size:9px"></i> Editable</span>
        </div>
        <div class="card-body">
          <div class="fr">
            <div class="fg">
              <label class="fl">First Name <span>*</span></label>
              <input type="text" class="fi" id="fFirstName" placeholder="First name">
            </div>
            <div class="fg">
              <label class="fl">Last Name <span>*</span></label>
              <input type="text" class="fi" id="fLastName" placeholder="Last name">
            </div>
          </div>
          <div class="fg">
            <label class="fl">Email Address</label>
            <input type="email" class="fi" id="fEmail" readonly placeholder="email@company.com">
            <span style="font-size:11px;color:var(--t3);margin-top:2px">Email cannot be changed. Contact Super Admin.</span>
          </div>
          <div class="fr">
            <div class="fg">
              <label class="fl">Phone Number</label>
              <input type="text" class="fi" id="fPhone" placeholder="+63 9XX XXX XXXX">
            </div>
            <div class="fg">
              <label class="fl">Zone</label>
              <input type="text" class="fi" id="fZone" readonly placeholder="Assigned zone">
              <span style="font-size:11px;color:var(--t3);margin-top:2px">Zone is managed by Admin.</span>
            </div>
          </div>
          <div class="btn-row">
            <button class="btn btn-ghost btn-sm" id="cancelEditBtn" style="display:none">
              <i class="bx bx-x"></i> Cancel
            </button>
            <button class="btn btn-primary btn-sm" id="saveProfileBtn" disabled>
              <i class="bx bx-save"></i> Save Changes
            </button>
          </div>
        </div>
      </div>

      <!-- CHANGE PASSWORD -->
      <div class="pf-card anim-2">
        <div class="card-hdr">
          <div class="card-hdr-l"><i class="bx bx-lock-alt"></i><h3>Change Password</h3></div>
        </div>
        <div class="card-body">
          <div class="fg">
            <label class="fl">New Password <span>*</span></label>
            <div style="position:relative">
              <input type="password" class="fi" id="fNewPw" placeholder="Min. 8 characters" style="padding-right:44px">
              <button onclick="togglePw('fNewPw','eyePw1')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--t3);font-size:18px;padding:0;line-height:1">
                <i class="bx bx-hide" id="eyePw1"></i>
              </button>
            </div>
            <div class="pw-strength"><div class="pw-fill" id="pwFill"></div></div>
            <div class="pw-label" id="pwLabel">Enter a password</div>
          </div>
          <div class="fg">
            <label class="fl">Confirm Password <span>*</span></label>
            <div style="position:relative">
              <input type="password" class="fi" id="fConfirmPw" placeholder="Repeat new password" style="padding-right:44px">
              <button onclick="togglePw('fConfirmPw','eyePw2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--t3);font-size:18px;padding:0;line-height:1">
                <i class="bx bx-hide" id="eyePw2"></i>
              </button>
            </div>
            <div id="pwMatchHint" style="font-size:11px;margin-top:3px;display:none"></div>
          </div>
          <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;font-size:11.5px;color:var(--t2);line-height:1.7;">
            <div style="font-weight:700;color:var(--t1);margin-bottom:4px;font-size:12px;">Password requirements:</div>
            <div id="req-len"  class="pw-req">✕ At least 8 characters</div>
            <div id="req-upper" class="pw-req">✕ One uppercase letter (A–Z)</div>
            <div id="req-num"  class="pw-req">✕ One number (0–9)</div>
          </div>
          <div class="btn-row">
            <button class="btn btn-primary btn-sm" id="changePwBtn" disabled>
              <i class="bx bx-lock-open-alt"></i> Update Password
            </button>
          </div>
        </div>
      </div>

    </div><!-- /RIGHT -->

  </div><!-- /GRID -->

</div>
</main>

<div class="pf-toasts" id="toastWrap"></div>

<style>
.pw-req{font-size:11.5px;color:var(--t3);display:flex;align-items:center;gap:5px;transition:color .2s;}
.pw-req.ok{color:#16A34A;}
.pw-req.ok::before{content:'✓';font-weight:700;}
</style>

<script>
const API = '<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p    => apiFetch(p);
const apiPost = (p,b)=> apiFetch(p,{method:'POST',body:JSON.stringify(b)});

const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fDT = d => { if(!d) return '—'; return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); };
const fD  = d => { if(!d) return '—'; return new Date(d).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };

// ── STATE ─────────────────────────────────────────────────────────────────────
let PROFILE = null;
let ORIGINAL = {};   // snapshot for dirty-check
let dirty = false;

// ── LOAD PROFILE ──────────────────────────────────────────────────────────────
async function loadProfile() {
    try {
        PROFILE = await apiGet(API + '?api=profile');
        renderHero();
        renderAccountInfo();
        renderPermissions();
        renderActivity();
        fillEditForm();
        ORIGINAL = { firstName: PROFILE.firstName, lastName: PROFILE.lastName, phone: PROFILE.phone };
    } catch(e) {
        toast('Failed to load profile: ' + e.message, 'd');
    }
}

// ── AVATAR HELPERS ────────────────────────────────────────────────────────────
function avatarInitials(name) {
    return String(name||'').split(/[\s\-]+/).map(w=>w[0]).join('').slice(0,2).toUpperCase() || '?';
}
function avatarColor(name) {
    const colors = ['#1B5E20','#1565C0','#4A148C','#BF360C','#004D40','#37474F','#880E4F'];
    let h = 0;
    for (let i=0;i<name.length;i++) h = name.charCodeAt(i) + ((h<<5)-h);
    return colors[Math.abs(h) % colors.length];
}

// ── RENDER HERO ───────────────────────────────────────────────────────────────
function renderHero() {
    const p = PROFILE;
    const color = avatarColor(p.fullName);
    const initials = avatarInitials(p.fullName);
    const statusCls = {Active:'sd-active',Inactive:'sd-inactive',Suspended:'sd-suspended',Locked:'sd-locked'};

    // Avatar
    const av = document.getElementById('heroAvatar');
    av.className = 'pf-avatar';
    av.style.background = color;
    av.textContent = initials;
    av.title = p.fullName;

    // Hero info (replace skeleton content)
    const info = av.nextElementSibling;
    info.innerHTML = `
        <div class="pf-hero-name">
            ${esc(p.fullName)}
            <span class="status-dot ${statusCls[p.status]||'sd-inactive'}">${esc(p.status)}</span>
        </div>
        <div class="pf-hero-sub">
            <span><i class="bx bx-envelope"></i>${esc(p.email)}</span>
            <span><i class="bx bx-map-pin"></i>${esc(p.zone||'—')}</span>
            ${p.empId?`<span><i class="bx bx-briefcase-alt"></i>${esc(p.empId)}</span>`:''}
        </div>
        <div class="role-chips">
            ${(p.roles||[]).map(r=>{
                const cls = r.includes('Super Admin')?'rc-sa':r.includes('Admin')?'rc-admin':r.includes('Manager')?'rc-mgr':r.includes('Staff')?'rc-staff':'rc-default';
                return `<span class="role-chip ${cls}">${esc(r)}</span>`;
            }).join('')}
        </div>`;

    // Stats
    const since = p.createdAt ? Math.floor((Date.now()-new Date(p.createdAt))/86400000) : 0;
    const actCount = p.activity ? p.activity.length : 0;
    document.getElementById('heroStats').innerHTML = `
        <div class="pf-stat"><div class="sv">${esc(p.userId)}</div><div class="sl">User ID</div></div>
        <div class="pf-stat"><div class="sv">${since}</div><div class="sl">Days as Member</div></div>
        <div class="pf-stat"><div class="sv">${actCount}</div><div class="sl">Recent Actions</div></div>`;
}

// ── RENDER ACCOUNT INFO ───────────────────────────────────────────────────────
function renderAccountInfo() {
    const p = PROFILE;
    document.getElementById('accountInfo').innerHTML = `
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-hash"></i>User ID</span>
            <span class="ir-val mono">${esc(p.userId)}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-briefcase-alt"></i>Employee ID</span>
            <span class="ir-val mono">${esc(p.empId||'—')}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-envelope"></i>Email</span>
            <span class="ir-val" style="font-size:12.5px">${esc(p.email)}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-phone"></i>Phone</span>
            <span class="ir-val ${!p.phone?'muted':''}">${esc(p.phone||'Not set')}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-map-pin"></i>Zone</span>
            <span class="ir-val">${esc(p.zone||'—')}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-time"></i>Last Login</span>
            <span class="ir-val muted">${fDT(p.lastLogin)}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-calendar-plus"></i>Member Since</span>
            <span class="ir-val muted">${fD(p.createdAt)}</span>
        </div>
        <div class="info-row">
            <span class="ir-label"><i class="bx bx-refresh"></i>Last Updated</span>
            <span class="ir-val muted">${fDT(p.updatedAt)}</span>
        </div>`;
}

// ── RENDER PERMISSIONS ────────────────────────────────────────────────────────
function renderPermissions() {
    const perms = PROFILE.permissions || [];
    // Filter out empty/null values from postgres array
    const clean = perms.filter(p => p && p !== 'NULL');
    const wrap = document.getElementById('permissionsWrap');
    if (!clean.length) {
        wrap.innerHTML = `<p class="perm-empty">No explicit permissions assigned. Access is role-based.</p>`;
    } else {
        wrap.innerHTML = `<div class="perm-grid">${clean.map(p=>`<span class="perm-tag">${esc(p)}</span>`).join('')}</div>`;
    }
}

// ── RENDER ACTIVITY ───────────────────────────────────────────────────────────
function renderActivity() {
    const acts = PROFILE.activity || [];
    const el = document.getElementById('activityList');
    if (!acts.length) {
        el.innerHTML = `<p style="font-size:12.5px;color:var(--t3);font-style:italic;text-align:center;padding:16px 0">No recent activity recorded.</p>`;
        return;
    }
    const iconMap = {
        'Login':          {cls:'act-dot-login',   icon:'bx-log-in'},
        'Profile Updated':{cls:'act-dot-default',  icon:'bx-edit'},
        'Password Changed':{cls:'act-dot-pw',      icon:'bx-lock-alt'},
    };
    el.innerHTML = acts.map(a => {
        const key  = Object.keys(iconMap).find(k=>a.action?.includes(k)) || '';
        const cfg  = iconMap[key] || {cls:'act-dot-default', icon:'bx-info-circle'};
        return `<div class="activity-item">
            <div class="act-dot ${cfg.cls}"><i class="bx ${cfg.icon}"></i></div>
            <div class="act-body">
                <div class="act-action">${esc(a.action)}</div>
                <div class="act-meta">
                    ${a.ip_address?`<span class="ip">${esc(a.ip_address)}</span>`:''}
                    ${a.is_sa?`<span style="font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px">SA</span>`:''}
                </div>
            </div>
            <div class="act-ts">${a.created_at?new Date(a.created_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}</div>
        </div>`;
    }).join('');
}

// ── FILL EDIT FORM ────────────────────────────────────────────────────────────
function fillEditForm() {
    const p = PROFILE;
    document.getElementById('fFirstName').value = p.firstName || '';
    document.getElementById('fLastName').value  = p.lastName  || '';
    document.getElementById('fEmail').value      = p.email     || '';
    document.getElementById('fPhone').value      = p.phone     || '';
    document.getElementById('fZone').value       = p.zone      || '';
}

// ── DIRTY CHECK ───────────────────────────────────────────────────────────────
function checkDirty() {
    if (!PROFILE) return;
    const now = {
        firstName: document.getElementById('fFirstName').value.trim(),
        lastName:  document.getElementById('fLastName').value.trim(),
        phone:     document.getElementById('fPhone').value.trim(),
    };
    dirty = JSON.stringify(now) !== JSON.stringify(ORIGINAL);
    document.getElementById('saveProfileBtn').disabled = !dirty;
    document.getElementById('cancelEditBtn').style.display = dirty ? '' : 'none';
}

['fFirstName','fLastName','fPhone'].forEach(id => {
    document.getElementById(id).addEventListener('input', checkDirty);
});

// ── CANCEL EDIT ───────────────────────────────────────────────────────────────
document.getElementById('cancelEditBtn').addEventListener('click', () => {
    fillEditForm(); checkDirty();
});

// ── SAVE PROFILE ──────────────────────────────────────────────────────────────
document.getElementById('saveProfileBtn').addEventListener('click', async () => {
    const btn = document.getElementById('saveProfileBtn');
    const firstName = document.getElementById('fFirstName').value.trim();
    const lastName  = document.getElementById('fLastName').value.trim();
    const phone     = document.getElementById('fPhone').value.trim();

    if (!firstName) { shk('fFirstName'); toast('First name is required.','w'); return; }
    if (!lastName)  { shk('fLastName');  toast('Last name is required.','w');  return; }

    btn.disabled = true; btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Saving…';
    try {
        await apiPost(API + '?api=update', { firstName, lastName, phone });
        PROFILE.firstName = firstName; PROFILE.lastName = lastName;
        PROFILE.fullName  = `${firstName} ${lastName}`.trim();
        PROFILE.phone     = phone;
        ORIGINAL = { firstName, lastName, phone };
        dirty = false;
        document.getElementById('saveProfileBtn').disabled = true;
        document.getElementById('cancelEditBtn').style.display = 'none';
        renderHero();
        renderAccountInfo();
        toast('Profile updated successfully.', 's');
    } catch(e) { toast(e.message, 'd'); }
    finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-save"></i> Save Changes';
        checkDirty();
    }
});

// ── PASSWORD STRENGTH ─────────────────────────────────────────────────────────
document.getElementById('fNewPw').addEventListener('input', function () {
    const v = this.value;
    const len   = v.length >= 8;
    const upper = /[A-Z]/.test(v);
    const num   = /[0-9]/.test(v);
    const score = [len, upper, num].filter(Boolean).length;

    const fill  = document.getElementById('pwFill');
    const label = document.getElementById('pwLabel');
    const pct   = score === 0 ? 0 : score === 1 ? 33 : score === 2 ? 66 : 100;
    const colors = ['','#EF4444','#D97706','#16A34A'];
    const labels = ['Enter a password','Weak','Fair','Strong'];
    fill.style.width  = pct + '%';
    fill.style.background = colors[score] || '';
    label.textContent = labels[score];
    label.style.color = colors[score] || 'var(--t3)';

    const toggle = (id, ok) => {
        const el = document.getElementById(id);
        el.className = 'pw-req' + (ok ? ' ok' : '');
        el.textContent = (ok ? '✓ ' : '✕ ') + el.textContent.replace(/^[✓✕] /, '');
    };
    toggle('req-len',   len);
    toggle('req-upper', upper);
    toggle('req-num',   num);

    checkPwMatch();
    document.getElementById('changePwBtn').disabled = !(len && upper && num && checkPwMatch(true));
});

document.getElementById('fConfirmPw').addEventListener('input', () => {
    checkPwMatch();
    const pw  = document.getElementById('fNewPw').value;
    const len   = pw.length >= 8;
    const upper = /[A-Z]/.test(pw);
    const num   = /[0-9]/.test(pw);
    document.getElementById('changePwBtn').disabled = !(len && upper && num && checkPwMatch(true));
});

function checkPwMatch(silent = false) {
    const pw  = document.getElementById('fNewPw').value;
    const cpw = document.getElementById('fConfirmPw').value;
    const hint = document.getElementById('pwMatchHint');
    if (!cpw) { hint.style.display = 'none'; return false; }
    const match = pw === cpw;
    if (!silent) {
        hint.style.display = '';
        hint.textContent   = match ? '✓ Passwords match' : '✕ Passwords do not match';
        hint.style.color   = match ? '#16A34A' : 'var(--red)';
    }
    return match;
}

document.getElementById('changePwBtn').addEventListener('click', async () => {
    const btn = document.getElementById('changePwBtn');
    const newPw = document.getElementById('fNewPw').value;
    const conf  = document.getElementById('fConfirmPw').value;

    if (!checkPwMatch()) { toast('Passwords do not match.', 'w'); return; }

    btn.disabled = true; btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Updating…';
    try {
        await apiPost(API + '?api=change-password', { newPassword: newPw, confirm: conf });
        document.getElementById('fNewPw').value      = '';
        document.getElementById('fConfirmPw').value  = '';
        document.getElementById('pwFill').style.width = '0';
        document.getElementById('pwLabel').textContent = 'Enter a password';
        document.getElementById('pwLabel').style.color  = '';
        document.getElementById('pwMatchHint').style.display = 'none';
        ['req-len','req-upper','req-num'].forEach(id => {
            const el = document.getElementById(id);
            el.className = 'pw-req';
            el.textContent = '✕ ' + el.textContent.replace(/^[✓✕] /, '');
        });
        // Refresh activity
        PROFILE = await apiGet(API + '?api=profile');
        renderActivity();
        toast('Password updated successfully.', 's');
    } catch(e) { toast(e.message, 'd'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="bx bx-lock-open-alt"></i> Update Password'; }
});

// ── PASSWORD VISIBILITY TOGGLE ────────────────────────────────────────────────
function togglePw(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bx bx-show';
    } else {
        inp.type = 'password';
        icon.className = 'bx bx-hide';
    }
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
function shk(id) {
    const el = document.getElementById(id); if (!el) return;
    el.style.borderColor = 'var(--red)'; el.style.animation = 'none';
    el.offsetHeight; el.style.animation = 'SHK .3s ease';
    setTimeout(() => { el.style.borderColor = ''; el.style.animation = ''; }, 600);
}
function toast(msg, type = 's') {
    const icons = {s:'bx-check-circle', w:'bx-error', d:'bx-error-circle'};
    const el = document.createElement('div');
    el.className = `toast t${type}`;
    el.innerHTML = `<i class="bx ${icons[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3500);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadProfile();
</script>
</body>
</html>