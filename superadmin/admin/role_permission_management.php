<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE GUARD (mirror of sidebar role logic) ───────────────────────────────────
function rpm_resolve_role(): string {
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

$rpmRoleName = rpm_resolve_role();
$rpmRoleRank = match($rpmRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};

// Only Super Admin may access Role & Permission Management
if ($rpmRoleRank < 4) {
    // For API calls, respond with JSON 403
    if (isset($_GET['api'])) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Forbidden — Super Admin only']);
        exit;
    }

    // For direct page hits, send them back to their dashboard
    $dashboardUrl = match($rpmRoleName) {
        'Super Admin' => '/superadmin_dashboard.php',
        'Admin'       => '/admin_dashboard.php',
        'Manager'     => '/manager_dashboard.php',
        default       => '/user_dashboard.php',
    };
    header('Location: ' . $dashboardUrl);
    exit;
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function rpm_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function rpm_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function rpm_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Supabase REST helper — identical pattern to dc_sb() / rlm_sb() */
function rpm_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

/** Raw URL fetch for queries that need repeated param keys */
function rpm_fetch(string $url): array {
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
 * Build a clean role DTO with permissions shaped as
 * { moduleName: { V:0|1, C:0|1, E:0|1, A:0|1, D:0|1 }, … }
 */
function rpm_build(array $role, array $permRows): array {
    $perms = [];
    foreach ($permRows as $p) {
        if ((int)$p['role_id'] !== (int)$role['id']) continue;
        $mod = $p['module'];
        if (!isset($perms[$mod])) $perms[$mod] = ['V'=>0,'C'=>0,'E'=>0,'A'=>0,'D'=>0];
        $perms[$mod][$p['permission_key']] = $p['enabled'] ? 1 : 0;
    }
    return [
        'id'          => (int)$role['id'],
        'name'        => $role['name']        ?? '',
        'type'        => $role['role_type']   ?? 'custom',
        'desc'        => $role['description'] ?? '',
        'active'      => (bool)($role['active'] ?? true),
        'users'       => (int)($role['user_count'] ?? 0),
        'createdAt'   => $role['created_at']  ?? '',
        'updatedAt'   => $role['updated_at']  ?? '',
        'perms'       => $perms,
    ];
}

/** Seed all module × permission rows for a new role */
function rpm_seed_perms(int $roleId, array $perms): void {
    $rows = [];
    foreach ($perms as $module => $keys) {
        foreach (['V','C','E','A','D'] as $k) {
            $rows[] = [
                'role_id'        => $roleId,
                'module'         => $module,
                'permission_key' => $k,
                'enabled'        => isset($keys[$k]) && $keys[$k] ? true : false,
            ];
        }
    }
    if ($rows) rpm_sb('role_permissions', 'POST', [], $rows);
}

/** Upsert all permissions for an existing role */
function rpm_upsert_perms(int $roleId, array $perms): void {
    // Delete existing then re-insert (cleanest pattern with Supabase REST)
    rpm_sb('role_permissions', 'DELETE', ['role_id' => 'eq.' . $roleId]);
    rpm_seed_perms($roleId, $perms);
}

// ── PRESET DEFINITIONS (server-side mirror of JS PRESET_DEFS) ─────────────
$MODULES_DEF = [
    ['group'=>'Smart Warehousing System (SWS)',  'items'=>['Warehouse Overview','Inventory Management','Stock In / Stock Out','Bin & Location Mapping','Inventory Reports']],
    ['group'=>'Procurement & Sourcing (PSM)',     'items'=>['Purchase Requests','Supplier Management','RFQ','Quotation Evaluation','Purchase Orders','Contract Management','Receiving & Inspection','Supplier Performance','Procurement Reports']],
    ['group'=>'Project Logistics Tracker (PLT)', 'items'=>['Active Projects','Delivery Schedule','Logistics Assignments','Milestone Tracking','Project Reports']],
    ['group'=>'Asset Lifecycle (ALMS)',           'items'=>['Asset Registry','Asset Assignment','Preventive Maintenance','Repair & Service Logs','Asset Disposal','Asset Reports']],
    ['group'=>'Document Tracking (DTRS)',         'items'=>['Document Registry','Document Capture','Document Routing','Incoming / Outgoing Logs','Archiving & Retrieval','Retention & Compliance','Document Reports']],
];

function rpm_all_modules(): array {
    global $MODULES_DEF;
    $all = [];
    foreach ($MODULES_DEF as $g) foreach ($g['items'] as $item) $all[] = $item;
    return $all;
}

function rpm_preset(string $key): array {
    global $MODULES_DEF;
    $perms = [];
    foreach ($MODULES_DEF as $g) {
        foreach ($g['items'] as $item) {
            switch ($key) {
                case 'Super Admin': $perms[$item] = ['V'=>1,'C'=>1,'E'=>1,'A'=>1,'D'=>1]; break;
                case 'Admin':       $perms[$item] = ['V'=>1,'C'=>1,'E'=>1,'A'=>0,'D'=>0]; break;
                case 'Manager':     $perms[$item] = ['V'=>1,'C'=>0,'E'=>0,'A'=>1,'D'=>0]; break;
                case 'Staff':       $perms[$item] = ['V'=>1,'C'=>1,'E'=>0,'A'=>0,'D'=>0]; break;
                case 'ra9184':
                    $perms[$item] = str_contains($g['group'], 'PSM')
                        ? ['V'=>1,'C'=>1,'E'=>0,'A'=>0,'D'=>0]
                        : ['V'=>1,'C'=>0,'E'=>0,'A'=>0,'D'=>0]; break;
                case 'read-only':   $perms[$item] = ['V'=>1,'C'=>0,'E'=>0,'A'=>0,'D'=>0]; break;
                case 'warehouse':
                    $perms[$item] = str_contains($g['group'], 'SWS')
                        ? ['V'=>1,'C'=>1,'E'=>1,'A'=>0,'D'=>0]
                        : (str_contains($g['group'], 'ALMS') ? ['V'=>1,'C'=>0,'E'=>0,'A'=>0,'D'=>0]
                        : ['V'=>0,'C'=>0,'E'=>0,'A'=>0,'D'=>0]); break;
                case 'compliance':
                    $perms[$item] = str_contains($g['group'], 'DTRS')
                        ? ['V'=>1,'C'=>1,'E'=>1,'A'=>1,'D'=>0]
                        : (str_contains($g['group'], 'PSM') ? ['V'=>1,'C'=>0,'E'=>0,'A'=>0,'D'=>0]
                        : ['V'=>0,'C'=>0,'E'=>0,'A'=>0,'D'=>0]); break;
                default:            $perms[$item] = ['V'=>0,'C'=>0,'E'=>0,'A'=>0,'D'=>0];
            }
        }
    }
    return $perms;
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET /api=stats ────────────────────────────────────────────────────
        if ($api === 'stats' && $method === 'GET') {
            $roles = rpm_sb('rpm_role_summary', 'GET', ['select' => 'id,active,role_type,user_count']);
            $total    = count($roles);
            $active   = count(array_filter($roles, fn($r) => $r['active']));
            $users    = array_sum(array_column($roles, 'user_count'));
            $custom   = count(array_filter($roles, fn($r) => $r['role_type'] === 'custom'));
            rpm_ok(['total' => $total, 'active' => $active, 'users' => (int)$users, 'custom' => $custom]);
        }

        // ── GET /api=roles ────────────────────────────────────────────────────
        // Returns all roles with their full permission map
        if ($api === 'roles' && $method === 'GET') {
            $roles = rpm_sb('rpm_role_summary', 'GET', [
                'select' => '*',
                'order'  => 'id.asc',
            ]);
            $permRows = rpm_sb('role_permissions', 'GET', ['select' => 'role_id,module,permission_key,enabled']);
            $result   = array_map(fn($r) => rpm_build($r, $permRows), $roles);
            rpm_ok(array_values($result));
        }

        // ── POST /api=create ──────────────────────────────────────────────────
        if ($api === 'create' && $method === 'POST') {
            $b    = rpm_body();
            $name = trim($b['name'] ?? '');
            $type = trim($b['type'] ?? 'custom');
            $desc = trim($b['desc'] ?? '');
            $perms = $b['perms'] ?? [];
            if (!$name) rpm_err('Role name is required', 400);

            // Check uniqueness
            $exists = rpm_sb('roles', 'GET', ['select' => 'id', 'name' => 'eq.' . $name, 'limit' => '1']);
            if (!empty($exists)) rpm_err('A role with that name already exists', 409);

            $now = date('Y-m-d H:i:s');
            $inserted = rpm_sb('roles', 'POST', [], [[
                'name'        => $name,
                'description' => $desc,
                'role_type'   => $type,
                'active'      => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]]);
            if (empty($inserted)) rpm_err('Failed to create role', 500);
            $newId = (int)$inserted[0]['id'];

            // Seed permissions — if none provided, default to all-off
            if (empty($perms)) $perms = rpm_preset('custom');
            rpm_seed_perms($newId, $perms);

            // Audit log
            rpm_sb('role_audit_log', 'POST', [], [[
                'role_id'       => $newId,
                'action_label'  => "Role \"{$name}\" created",
                'actor_name'    => $actor,
                'icon'          => 'bx-plus-circle',
                'css_class'     => 'ad-c',
                'note'          => "Type: {$type}. " . ($desc ?: ''),
                'ip_address'    => $ip,
                'is_super_admin'=> true,
                'occurred_at'   => $now,
            ]]);

            rpm_ok(['id' => $newId, 'name' => $name]);
        }

        // ── POST /api=update ──────────────────────────────────────────────────
        if ($api === 'update' && $method === 'POST') {
            $b    = rpm_body();
            $id   = (int)($b['id']   ?? 0);
            $name = trim($b['name']  ?? '');
            $type = trim($b['type']  ?? 'custom');
            $desc = trim($b['desc']  ?? '');
            $perms = $b['perms']     ?? [];
            if (!$id)   rpm_err('Missing id', 400);
            if (!$name) rpm_err('Role name is required', 400);

            $now = date('Y-m-d H:i:s');
            rpm_sb('roles', 'PATCH', ['id' => 'eq.' . $id], [
                'name'        => $name,
                'description' => $desc,
                'role_type'   => $type,
                'updated_at'  => $now,
            ]);

            if (!empty($perms)) rpm_upsert_perms($id, $perms);

            rpm_sb('role_audit_log', 'POST', [], [[
                'role_id'       => $id,
                'action_label'  => "Role \"{$name}\" permissions updated",
                'actor_name'    => $actor,
                'icon'          => 'bx-edit',
                'css_class'     => 'ad-b',
                'note'          => 'Role permissions edited via RPM module.',
                'ip_address'    => $ip,
                'is_super_admin'=> true,
                'occurred_at'   => $now,
            ]]);

            rpm_ok(['updated' => true]);
        }

        // ── POST /api=toggle-active ───────────────────────────────────────────
        // Activate or deactivate a role
        if ($api === 'toggle-active' && $method === 'POST') {
            $b      = rpm_body();
            $id     = (int)($b['id']     ?? 0);
            $active = (bool)($b['active'] ?? false);
            $note   = trim($b['note']    ?? '');
            if (!$id) rpm_err('Missing id', 400);

            $rows = rpm_sb('roles', 'GET', ['id' => 'eq.' . $id, 'select' => 'name', 'limit' => '1']);
            if (empty($rows)) rpm_err('Role not found', 404);
            $name = $rows[0]['name'];

            $now = date('Y-m-d H:i:s');
            rpm_sb('roles', 'PATCH', ['id' => 'eq.' . $id], [
                'active'     => $active,
                'updated_at' => $now,
            ]);

            $label = $active ? "Role \"{$name}\" activated" : "Role \"{$name}\" deactivated";
            rpm_sb('role_audit_log', 'POST', [], [[
                'role_id'       => $id,
                'action_label'  => $label,
                'actor_name'    => $actor,
                'icon'          => $active ? 'bx-check-circle' : 'bx-block',
                'css_class'     => $active ? 'ad-c' : 'ad-r',
                'note'          => $note,
                'ip_address'    => $ip,
                'is_super_admin'=> true,
                'occurred_at'   => $now,
            ]]);

            rpm_ok(['active' => $active]);
        }

        // ── POST /api=clone ───────────────────────────────────────────────────
        if ($api === 'clone' && $method === 'POST') {
            $b  = rpm_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) rpm_err('Missing id', 400);

            $rows = rpm_sb('roles', 'GET', ['id' => 'eq.' . $id, 'select' => '*', 'limit' => '1']);
            if (empty($rows)) rpm_err('Role not found', 404);
            $src = $rows[0];

            $newName = trim($src['name']) . ' (Copy)';
            $now = date('Y-m-d H:i:s');
            $inserted = rpm_sb('roles', 'POST', [], [[
                'name'        => $newName,
                'description' => trim($src['description'] ?? ''),
                'role_type'   => 'custom',
                'active'      => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]]);
            if (empty($inserted)) rpm_err('Failed to clone role', 500);
            $newId = (int)$inserted[0]['id'];

            // Copy permissions from source
            $srcPerms = rpm_sb('role_permissions', 'GET', [
                'select'  => 'module,permission_key,enabled',
                'role_id' => 'eq.' . $id,
            ]);
            $permsMap = [];
            foreach ($srcPerms as $p) {
                $permsMap[$p['module']][$p['permission_key']] = $p['enabled'] ? 1 : 0;
            }
            if (!empty($permsMap)) rpm_seed_perms($newId, $permsMap);

            rpm_sb('role_audit_log', 'POST', [], [[
                'role_id'       => $newId,
                'action_label'  => "Role \"{$src['name']}\" cloned as \"{$newName}\"",
                'actor_name'    => $actor,
                'icon'          => 'bx-copy',
                'css_class'     => 'ad-t',
                'note'          => 'Cloned role created for customization.',
                'ip_address'    => $ip,
                'is_super_admin'=> true,
                'occurred_at'   => $now,
            ]]);

            rpm_ok(['id' => $newId, 'name' => $newName]);
        }

        // ── POST /api=save-matrix ─────────────────────────────────────────────
        // Batch-save all permission toggles from the matrix view
        if ($api === 'save-matrix' && $method === 'POST') {
            $b    = rpm_body();
            $rows = $b['rows'] ?? []; // [{ roleId, module, perm, enabled }]
            if (empty($rows)) rpm_err('No rows provided', 400);

            // Group by roleId → module → perm map then upsert
            $byRole = [];
            foreach ($rows as $r) {
                $rid = (int)$r['roleId'];
                $mod = $r['module'];
                $pk  = $r['perm'];
                if (!isset($byRole[$rid][$mod])) $byRole[$rid][$mod] = ['V'=>0,'C'=>0,'E'=>0,'A'=>0,'D'=>0];
                $byRole[$rid][$mod][$pk] = $r['enabled'] ? 1 : 0;
            }

            $now = date('Y-m-d H:i:s');
            foreach ($byRole as $roleId => $permsMap) {
                rpm_upsert_perms($roleId, $permsMap);
            }

            rpm_sb('role_audit_log', 'POST', [], [[
                'role_id'       => null,
                'action_label'  => 'Permission Matrix batch save',
                'actor_name'    => $actor,
                'icon'          => 'bx-save',
                'css_class'     => 'ad-b',
                'note'          => 'Quarterly permission review via matrix grid.',
                'ip_address'    => $ip,
                'is_super_admin'=> true,
                'occurred_at'   => $now,
            ]]);

            rpm_ok(['saved' => count($rows)]);
        }

        // ── POST /api=apply-preset ────────────────────────────────────────────
        // Apply a named preset to a specific role (or all roles for ra9184)
        if ($api === 'apply-preset' && $method === 'POST') {
            $b      = rpm_body();
            $preset = trim($b['preset'] ?? '');
            $roleId = isset($b['roleId']) ? (int)$b['roleId'] : null;
            if (!$preset) rpm_err('Missing preset key', 400);

            $perms = rpm_preset($preset);
            $now   = date('Y-m-d H:i:s');

            if ($roleId) {
                rpm_upsert_perms($roleId, $perms);
            } else {
                // Apply to all non-SA roles
                $roles = rpm_sb('roles', 'GET', ['select' => 'id,role_type', 'active' => 'eq.true']);
                foreach ($roles as $r) {
                    if ($r['role_type'] !== 'Super Admin') rpm_upsert_perms((int)$r['id'], $perms);
                }
            }

            $label = $preset === 'ra9184'
                ? 'RA 9184 compliance preset applied to all procurement roles'
                : "Preset \"{$preset}\" applied";

            rpm_sb('role_audit_log', 'POST', [], [[
                'role_id'       => $roleId,
                'action_label'  => $label,
                'actor_name'    => $actor,
                'icon'          => 'bx-certification',
                'css_class'     => 'ad-c',
                'note'          => 'Segregation of duty enforced via preset.',
                'ip_address'    => $ip,
                'is_super_admin'=> true,
                'occurred_at'   => $now,
            ]]);

            rpm_ok(['applied' => $preset]);
        }

        // ── GET /api=audit ────────────────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            $rows = rpm_sb('role_audit_log', 'GET', [
                'select' => '*',
                'order'  => 'occurred_at.desc',
                'limit'  => '200',
            ]);
            rpm_ok(array_values($rows));
        }

        rpm_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        rpm_err('Server error: ' . $e->getMessage(), 500);
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
<title>Role & Permission Management — System Administration</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root {
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

.rpm-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem}

/* PAGE HEADER */
.rpm-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:rpUP .4s both}
.rpm-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px}
.rpm-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;margin:0}
.rpm-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* BUTTONS */
.rbtn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.rbtn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32)}
.rbtn-primary:hover{background:var(--gdk);transform:translateY(-1px)}
.rbtn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}
.rbtn-ghost:hover{background:var(--hbg);color:var(--t1)}
.rbtn-blue{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE}
.rbtn-blue:hover{background:#DBEAFE}
.rbtn-warn{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D}
.rbtn-warn:hover{background:#FDE68A}
.rbtn-purple{background:#F5F3FF;color:var(--pur);border:1px solid #DDD6FE}
.rbtn-teal{background:#CCFBF1;color:#0F766E;border:1px solid #99F6E4}
.rbtn-teal:hover{background:#99F6E4}
.rbtn-red{background:#FEE2E2;color:var(--red);border:1px solid #FECACA}
.rbtn-red:hover{background:#FCA5A5}
.rbtn-sm{font-size:12px;padding:6px 13px}
.rbtn-xs{font-size:11px;padding:4px 9px;border-radius:7px}
.rbtn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:6px}
.rbtn:disabled{opacity:.45;pointer-events:none}

/* STATS */
.rpm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;animation:rpUP .4s .05s both}
.rpm-sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px}
.rpm-sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-g{background:#DCFCE7;color:#166534}
.ic-a{background:#FEF3C7;color:var(--amb)}.ic-p{background:#F5F3FF;color:var(--pur)}
.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}
.rpm-sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1}
.rpm-sc-l{font-size:11px;color:var(--t2);margin-top:2px}

/* SKELETON */
.skeleton{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.07) 50%,var(--bg) 75%);background-size:400% 100%;animation:shimmer 1.4s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}

/* TABS */
.rpm-tabs{display:flex;gap:4px;margin-bottom:20px;background:var(--s);border:1px solid var(--bd);border-radius:12px;padding:5px;width:fit-content;animation:rpUP .4s .08s both}
.rpm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 18px;border-radius:9px;cursor:pointer;transition:var(--tr);color:var(--t2);border:none;background:transparent;display:flex;align-items:center;gap:7px}
.rpm-tab:hover{background:var(--hbg);color:var(--t1)}
.rpm-tab.active{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3)}
.rpm-tab i{font-size:15px}

/* SA BADGE */
.sa-excl{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:3px 8px}
.sa-excl i{font-size:11px}

/* ROLE CARDS */
#view-cards{animation:rpUP .3s both}
.rpm-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.rpm-sw{position:relative;flex:1;min-width:200px}
.rpm-sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none}
.rpm-si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.rpm-si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.rpm-si::placeholder{color:var(--t3)}
.rpm-sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.rpm-sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.role-card{background:var(--s);border:1.5px solid var(--bd);border-radius:16px;padding:20px;box-shadow:var(--shmd);transition:var(--tr);cursor:default;position:relative;overflow:hidden}
.role-card:hover{border-color:var(--bdm);box-shadow:0 8px 32px rgba(46,125,50,.14);transform:translateY(-2px)}
.role-card.inactive{opacity:.65}
.role-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:16px 16px 0 0}
.rc-sa::before    {background:linear-gradient(90deg,#D97706,#F59E0B)}
.rc-admin::before {background:linear-gradient(90deg,#DC2626,#EF4444)}
.rc-mgr::before   {background:linear-gradient(90deg,#2563EB,#60A5FA)}
.rc-staff::before {background:linear-gradient(90deg,#2E7D32,#4CAF50)}
.rc-custom::before{background:linear-gradient(90deg,#7C3AED,#A78BFA)}
.rc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px}
.rc-ti{display:flex;align-items:center;gap:10px}
.rc-nm{font-size:15px;font-weight:700;color:var(--t1)}
.rc-uc{font-size:11px;color:var(--t2);margin-top:2px;display:flex;align-items:center;gap:4px}
.rc-acts{display:flex;gap:4px;flex-shrink:0}
.rc-body{border-top:1px solid var(--bd);padding-top:12px}
.rc-mods{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.rc-mod{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px}
.rc-perms{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px}
.perm-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:6px}
.pt-v{background:#EFF6FF;color:#2563EB}.pt-c{background:#DCFCE7;color:#166534}
.pt-e{background:#FEF3C7;color:#92400E}.pt-a{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0}
.pt-d{background:#FEE2E2;color:#DC2626}
.rc-foot{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.rc-created{font-size:11px;color:var(--t3);font-family:'DM Mono',monospace}
.status-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.sp-active  {background:#DCFCE7;color:#166534}
.sp-inactive{background:#F3F4F6;color:#6B7280}
.sp-active::before,.sp-inactive::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}

/* PERMISSION MATRIX */
#view-matrix{display:none;animation:rpUP .3s both}
.matrix-wrap{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd)}
.matrix-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:16px 20px;border-bottom:1px solid var(--bd);background:var(--bg)}
.matrix-top h3{font-size:14px;font-weight:700;color:var(--t1);margin:0;display:flex;align-items:center;gap:8px}
.matrix-top h3 i{font-size:16px;color:var(--grn)}
.matrix-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.matrix-tbl{width:100%;border-collapse:collapse;font-size:12px}
.matrix-tbl th,.matrix-tbl td{padding:0;border-right:1px solid var(--bd)}
.matrix-tbl th:last-child,.matrix-tbl td:last-child{border-right:none}
.mt-header-role{background:var(--bg);text-align:center;padding:12px 8px;font-size:10.5px;font-weight:700;letter-spacing:.05em;color:var(--t2);min-width:110px;border-bottom:2px solid var(--bdm);white-space:nowrap}
.mt-header-role.th-mod{text-align:left;min-width:160px;padding-left:16px}
.mt-header-role .role-label{display:flex;flex-direction:column;align-items:center;gap:4px}
.mt-sub-hd{background:#FAFCFA}
.mt-sub-hd td{text-align:center;padding:6px 4px;font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t3);border-bottom:1px solid var(--bd)}
.mt-sub-hd td.mod-cell{padding-left:16px;text-align:left}
.mt-grp{background:linear-gradient(135deg,var(--bg),#EDF7ED)}
.mt-grp td{padding:8px 16px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--grn);border-bottom:1px solid var(--bd)}
.mt-row td{padding:0;border-bottom:1px solid var(--bd)}
.mt-row:last-child td{border-bottom:none}
.mt-row:hover td{background:var(--hbg)}
.mt-module{padding:11px 16px;font-size:12px;font-weight:500;color:var(--t1);white-space:nowrap;display:flex;align-items:center;gap:7px}
.mt-module i{font-size:13px;color:var(--t2)}
.mt-toggle-cell{text-align:center;vertical-align:middle;padding:0}
.toggle-wrap{display:flex;align-items:center;justify-content:center;padding:10px 4px}
.rpm-toggle{position:relative;width:30px;height:17px;flex-shrink:0;cursor:pointer}
.rpm-toggle input{opacity:0;width:0;height:0}
.toggle-sl{position:absolute;cursor:pointer;inset:0;background:#D1D5DB;border-radius:17px;transition:.2s}
.toggle-sl::before{content:'';position:absolute;width:13px;height:13px;background:#fff;border-radius:50%;left:2px;bottom:2px;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.rpm-toggle input:checked + .toggle-sl{background:var(--grn)}
.rpm-toggle input:checked + .toggle-sl::before{transform:translateX(13px)}
.rpm-toggle input:disabled + .toggle-sl{opacity:.45;cursor:not-allowed}
.sa-lock{display:flex;align-items:center;justify-content:center;padding:10px 4px}
.sa-lock i{font-size:13px;color:#D97706}
.matrix-legend{display:flex;align-items:center;gap:14px;padding:12px 20px;border-top:1px solid var(--bd);background:var(--bg);flex-wrap:wrap}
.ml-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--t2)}
.ml-dot{width:10px;height:10px;border-radius:3px}
.ml-on{background:var(--grn)}.ml-off{background:#D1D5DB}

/* AUDIT */
#view-audit{display:none;animation:rpUP .3s both}
.audit-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd)}
.audit-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--bg);border-bottom:1px solid var(--bd);flex-wrap:wrap;gap:10px}
.audit-card-head h3{font-size:13px;font-weight:700;color:var(--t1);margin:0;display:flex;align-items:center;gap:7px}
.audit-list{padding:0 20px}
.audit-item{display:flex;gap:12px;padding:14px 0;border-bottom:1px solid var(--bd)}
.audit-item:last-child{border-bottom:none}
.audit-dot{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px}
.ad-c{background:#DCFCE7;color:#166534}.ad-e{background:#FEF3C7;color:#D97706}
.ad-r{background:#FEE2E2;color:#DC2626}.ad-p{background:#F5F3FF;color:#7C3AED}
.ad-b{background:#EFF6FF;color:#2563EB}.ad-t{background:#CCFBF1;color:#0D9488}
.audit-bd .au{font-size:13px;font-weight:500;color:var(--t1)}
.audit-bd .at{font-size:11px;color:#9EB0A2;margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic}
.audit-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px}
.audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D}

/* SLIDE-OVER */
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s}
#slOverlay.on{opacity:1;pointer-events:all}
#roleSlider{position:fixed;top:0;right:-560px;bottom:0;width:520px;max-width:100vw;background:#fff;z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#roleSlider.on{right:0}
.sl-hdr{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0}
.sl-title{font-size:17px;font-weight:700;color:var(--t1)}
.sl-sub{font-size:12px;color:var(--t2);margin-top:2px}
.sl-close{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0}
.sl-close:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.sl-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:18px}
.sl-body::-webkit-scrollbar{width:4px}
.sl-body::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.sl-foot{padding:16px 24px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.fg{display:flex;flex-direction:column;gap:6px}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2)}
.fl span{color:var(--red);margin-left:2px}
.fi,.fta,.fsel{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fta:focus,.fsel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.fsel{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px}
.fta{resize:vertical;min-height:72px}
.sfd{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px}
.sfd::after{content:'';flex:1;height:1px;background:var(--bd)}
.perm-section{background:var(--bg);border:1px solid var(--bd);border-radius:12px;overflow:hidden}
.perm-sec-head{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--bd);font-size:12px;font-weight:700;color:var(--t1)}
.perm-sec-head i{font-size:14px;color:var(--grn)}
.perm-row{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--bd)}
.perm-row:last-child{border-bottom:none}
.perm-row:hover{background:var(--hbg)}
.perm-row-label{font-size:12.5px;color:var(--t1);font-weight:500}
.perm-toggles{display:flex;gap:8px}
.pt-item{display:flex;flex-direction:column;align-items:center;gap:3px}
.pt-item span{font-size:9px;font-weight:700;color:var(--t3);letter-spacing:.05em}
.perm-toggle-mini{position:relative;width:26px;height:15px;cursor:pointer}
.perm-toggle-mini input{opacity:0;width:0;height:0;position:absolute}
.ptm-sl{position:absolute;cursor:pointer;inset:0;background:#D1D5DB;border-radius:15px;transition:.2s}
.ptm-sl::before{content:'';position:absolute;width:11px;height:11px;background:#fff;border-radius:50%;left:2px;bottom:2px;transition:.2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.perm-toggle-mini input:checked + .ptm-sl{background:var(--grn)}
.perm-toggle-mini input:checked + .ptm-sl::before{transform:translateX(11px)}

/* ACTION MODAL */
#actionModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;padding:20px}
#actionModal.on{opacity:1;pointer-events:all}
.am-box{background:var(--s);border-radius:16px;padding:28px 28px 24px;width:420px;max-width:100%;box-shadow:var(--shlg)}
.am-icon{font-size:46px;margin-bottom:10px;line-height:1}
.am-title{font-size:18px;font-weight:700;color:var(--t1);margin-bottom:6px}
.am-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px}
.am-sa{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400E}
.am-sa i{font-size:15px;flex-shrink:0;margin-top:1px}
.am-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:18px}
.am-fg label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2)}
.am-fg textarea{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;resize:vertical;min-height:72px;width:100%;transition:var(--tr)}
.am-fg textarea:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.am-acts{display:flex;gap:10px;justify-content:flex-end}
.preset-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.preset-chip{padding:10px 12px;border:1.5px solid var(--bd);border-radius:10px;cursor:pointer;transition:var(--tr);background:var(--s)}
.preset-chip:hover{border-color:var(--grn);background:var(--hbg)}
.preset-chip.selected{border-color:var(--grn);background:#E8F5E9}
.preset-chip .pc-name{font-size:12px;font-weight:700;color:var(--t1)}
.preset-chip .pc-desc{font-size:10.5px;color:var(--t2);margin-top:2px}

/* COMPLIANCE BANNER */
.compliance-banner{display:flex;align-items:flex-start;gap:12px;background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FCD34D;border-radius:12px;padding:14px 18px;margin-bottom:20px;animation:rpUP .4s .02s both}
.compliance-banner i{font-size:20px;color:#D97706;flex-shrink:0;margin-top:1px}
.compliance-banner .cb-title{font-size:13px;font-weight:700;color:#92400E}
.compliance-banner .cb-text{font-size:12px;color:#B45309;margin-top:2px;line-height:1.5}
.compliance-banner .cb-actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}

/* TOASTS */
.rpm-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.rpm-toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:tIN .3s ease}
.rpm-toast.ts{background:var(--grn)}.rpm-toast.tw{background:var(--amb)}.rpm-toast.td{background:var(--red)}
.rpm-toast.out{animation:tOUT .3s ease forwards}

/* EMPTY */
.rpm-empty{padding:64px 20px;text-align:center;color:var(--t3)}
.rpm-empty i{font-size:52px;display:block;margin-bottom:12px;color:#C8E6C9}

/* ANIMATIONS */
@keyframes rpUP{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes tIN {from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)}}
@keyframes tOUT{from{opacity:1;transform:translateY(0)}   to{opacity:0;transform:translateY(8px)}}
@keyframes rpSHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:768px){#roleSlider{width:100vw}.fr{grid-template-columns:1fr}.rpm-stats{grid-template-columns:repeat(2,1fr)}.preset-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="rpm-wrap">

  <!-- PAGE HEADER -->
  <div class="rpm-ph">
    <div>
      <p class="ey">System Administration</p>
      <h1>Role &amp; Permission Management</h1>
    </div>
    <div class="rpm-ph-r">
      <span class="sa-excl"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
      <button class="rbtn rbtn-ghost" id="auditBtn"><i class="bx bx-shield-quarter"></i> Permission Audit</button>
      <button class="rbtn rbtn-primary" id="createRoleBtn"><i class="bx bx-plus"></i> Create Role</button>
    </div>
  </div>

  <!-- RA 9184 COMPLIANCE BANNER -->
  <div class="compliance-banner">
    <i class="bx bx-certification"></i>
    <div>
      <div class="cb-title">RA 9184 Compliance Notice — Government Procurement Reform Act</div>
      <div class="cb-text">Role permissions affecting procurement workflows (PSM module) must comply with RA 9184 segregation-of-duty requirements. At minimum, the roles for PR Creation, PO Approval, and Receiving &amp; Inspection must be assigned to different users.</div>
      <div class="cb-actions">
        <button class="rbtn rbtn-warn rbtn-xs" id="ra9184Btn"><i class="bx bx-check-shield"></i> Apply RA 9184 Preset</button>
        <button class="rbtn rbtn-ghost rbtn-xs" id="exportComplianceBtn"><i class="bx bx-download"></i> Export Compliance Report</button>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <div class="rpm-stats" id="statsBar">
    <?php for ($i = 0; $i < 5; $i++): ?>
    <div class="rpm-sc"><div class="rpm-sc-ic skeleton" style="width:38px;height:38px"></div><div><div class="skeleton" style="height:16px;width:36px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <?php endfor; ?>
  </div>

  <!-- TABS -->
  <div class="rpm-tabs">
    <button class="rpm-tab active" data-view="cards"><i class="bx bx-id-card"></i> Role Cards</button>
    <button class="rpm-tab" data-view="matrix"><i class="bx bx-grid-alt"></i> Permission Matrix</button>
    <button class="rpm-tab" data-view="audit"><i class="bx bx-history"></i> Permission Audit</button>
  </div>

  <!-- ═══ VIEW: ROLE CARDS ═══ -->
  <div id="view-cards">
    <div class="rpm-toolbar">
      <div class="rpm-sw"><i class="bx bx-search"></i><input type="text" class="rpm-si" id="cardSearch" placeholder="Search roles…"></div>
      <select class="rpm-sel" id="cardFilter">
        <option value="">All Statuses</option>
        <option value="active">Active Only</option>
        <option value="inactive">Inactive Only</option>
      </select>
    </div>
    <div class="cards-grid" id="cardsGrid">
      <div style="grid-column:1/-1;padding:40px;text-align:center"><div class="skeleton" style="height:14px;width:40%;margin:0 auto"></div></div>
    </div>
  </div>

  <!-- ═══ VIEW: PERMISSION MATRIX ═══ -->
  <div id="view-matrix" style="display:none">
    <div class="matrix-wrap">
      <div class="matrix-top">
        <h3><i class="bx bx-grid-alt"></i> Module × Role Permission Matrix</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="rbtn rbtn-ghost rbtn-sm" onclick="matrixToggleAll(true)"><i class="bx bx-check-double"></i> Enable All</button>
          <button class="rbtn rbtn-warn rbtn-sm" onclick="matrixToggleAll(false)"><i class="bx bx-x-circle"></i> Clear All</button>
          <button class="rbtn rbtn-primary rbtn-sm" id="saveMatrixBtn"><i class="bx bx-save"></i> Save Matrix</button>
        </div>
      </div>
      <div class="matrix-scroll"><table class="matrix-tbl" id="matrixTbl"></table></div>
      <div class="matrix-legend">
        <span style="font-size:11px;font-weight:700;color:var(--t2);margin-right:4px">LEGEND:</span>
        <div class="ml-item"><div class="ml-dot ml-on"></div> Enabled</div>
        <div class="ml-item"><div class="ml-dot ml-off"></div> Disabled</div>
        <div class="ml-item"><i class="bx bx-lock" style="font-size:13px;color:#D97706"></i> Locked (Super Admin)</div>
        <span style="margin-left:auto;font-size:11px;color:var(--t3);font-family:'DM Mono',monospace">V=View · C=Create · E=Edit · A=Approve · D=Delete</span>
      </div>
    </div>
  </div>

  <!-- ═══ VIEW: PERMISSION AUDIT ═══ -->
  <div id="view-audit" style="display:none">
    <div class="audit-card">
      <div class="audit-card-head">
        <h3><i class="bx bx-history" style="font-size:16px;color:var(--grn)"></i> Permission Change Audit Trail</h3>
        <button class="rbtn rbtn-ghost rbtn-sm" id="exportAuditBtn"><i class="bx bx-export"></i> Export CSV</button>
      </div>
      <div class="audit-list" id="auditList">
        <div style="padding:28px;text-align:center"><div class="skeleton" style="height:12px;width:60%;margin:0 auto"></div></div>
      </div>
    </div>
  </div>

</div><!-- /.rpm-wrap -->
</main>

<div class="rpm-toasts" id="toastWrap"></div>

<!-- ═══ SLIDE-OVER ═══ -->
<div id="slOverlay"></div>
<div id="roleSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Create Role</div><div class="sl-sub" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="fr">
      <div class="fg"><label class="fl">Role Name <span>*</span></label><input type="text" class="fi" id="fRoleName" placeholder="e.g. Branch Auditor"></div>
      <div class="fg"><label class="fl">Role Type</label>
        <select class="fsel" id="fRoleType"><option value="custom">Custom</option><option value="Super Admin">Super Admin</option><option value="Admin">Admin</option><option value="Manager">Manager</option><option value="Staff">Staff</option></select>
      </div>
    </div>
    <div class="fg"><label class="fl">Description</label><textarea class="fta" id="fRoleDesc" placeholder="Describe what this role can do…"></textarea></div>
    <div class="sfd">RA 9184 Compliance Presets <span class="sa-excl" style="margin-left:8px"><i class="bx bx-shield-quarter"></i> SA Only</span></div>
    <div class="preset-grid">
      <div class="preset-chip" onclick="selectPreset(this,'ra9184')"><div class="pc-name">⚖️ RA 9184 Compliant</div><div class="pc-desc">Full procurement segregation</div></div>
      <div class="preset-chip" onclick="selectPreset(this,'read-only')"><div class="pc-name">👁️ Read-Only Auditor</div><div class="pc-desc">View access across all modules</div></div>
      <div class="preset-chip" onclick="selectPreset(this,'warehouse')"><div class="pc-name">🏭 Warehouse Operator</div><div class="pc-desc">SWS full + ALMS limited</div></div>
      <div class="preset-chip" onclick="selectPreset(this,'compliance')"><div class="pc-name">📋 Compliance Officer</div><div class="pc-desc">DTRS full + PSM view</div></div>
    </div>
    <div class="sfd">Module Permissions</div>
    <div id="slPermSections"></div>
  </div>
  <div class="sl-foot">
    <button class="rbtn rbtn-ghost rbtn-sm" id="slCancel">Cancel</button>
    <button class="rbtn rbtn-primary rbtn-sm" id="slSubmit"><i class="bx bx-save"></i> <span id="slSubmitLabel">Save Role</span></button>
  </div>
</div>

<!-- ═══ ACTION MODAL ═══ -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">⚙️</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body" id="amBody"></div>
    <div class="am-sa" id="amSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="amSaText"></span></div>
    <div class="am-fg"><label>Remarks / Notes (optional)</label><textarea id="amRemarks" placeholder="Add remarks for this action…"></textarea></div>
    <div class="am-acts">
      <button class="rbtn rbtn-ghost rbtn-sm" id="amCancel">Cancel</button>
      <button class="rbtn rbtn-sm" id="amConfirm">Confirm</button>
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
const apiGet  = p     => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, { method:'POST', body:JSON.stringify(b) });

// ── CONSTANTS ─────────────────────────────────────────────────────────────────
const MODULES_DEF = [
    {group:'Smart Warehousing System (SWS)',  icon:'bx-package',  items:['Warehouse Overview','Inventory Management','Stock In / Stock Out','Bin & Location Mapping','Inventory Reports']},
    {group:'Procurement & Sourcing (PSM)',    icon:'bx-cart-alt', items:['Purchase Requests','Supplier Management','RFQ','Quotation Evaluation','Purchase Orders','Contract Management','Receiving & Inspection','Supplier Performance','Procurement Reports']},
    {group:'Project Logistics Tracker (PLT)',icon:'bx-task',     items:['Active Projects','Delivery Schedule','Logistics Assignments','Milestone Tracking','Project Reports']},
    {group:'Asset Lifecycle (ALMS)',          icon:'bx-wrench',   items:['Asset Registry','Asset Assignment','Preventive Maintenance','Repair & Service Logs','Asset Disposal','Asset Reports']},
    {group:'Document Tracking (DTRS)',        icon:'bx-file-find',items:['Document Registry','Document Capture','Document Routing','Incoming / Outgoing Logs','Archiving & Retrieval','Retention & Compliance','Document Reports']},
];
const PERMS_KEYS   = ['V','C','E','A','D'];
const PERMS_LABELS = {V:'View',C:'Create',E:'Edit',A:'Approve',D:'Delete'};

const ROLE_STYLES = {
    'Super Admin':{cls:'rc-sa',   ic:'ic-a',icon:'bx-crown',        color:'#D97706'},
    'Admin':      {cls:'rc-admin',ic:'ic-r',icon:'bx-shield-alt-2', color:'#DC2626'},
    'Manager':    {cls:'rc-mgr',  ic:'ic-b',icon:'bx-user-pin',     color:'#2563EB'},
    'Staff':      {cls:'rc-staff',ic:'ic-g',icon:'bx-user',         color:'#2E7D32'},
    'custom':     {cls:'rc-custom',ic:'ic-p',icon:'bx-customize',   color:'#7C3AED'},
};
const rs = r => ROLE_STYLES[r.type] || ROLE_STYLES['custom'];

// ── STATE ─────────────────────────────────────────────────────────────────────
const esc     = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD      = d => { if(!d)return'—'; try{return new Date(d).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});}catch{return d;} };
const fmtTs   = d => { if(!d)return'—'; try{return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});}catch{return d;} };

let allRoles    = [];
let editRoleId  = null;
let actionTarget= null, actionKey = null;
let sliderPerms = {}; // tracks current perm state in the slider

// ── INIT ──────────────────────────────────────────────────────────────────────
loadStats();
loadRoles();

// ── STATS ─────────────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const d = await apiGet(API + '?api=stats');
        document.getElementById('statsBar').innerHTML = `
          <div class="rpm-sc"><div class="rpm-sc-ic ic-b"><i class="bx bx-group"></i></div><div><div class="rpm-sc-v">${d.total}</div><div class="rpm-sc-l">Total Roles</div></div></div>
          <div class="rpm-sc"><div class="rpm-sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="rpm-sc-v">${d.active}</div><div class="rpm-sc-l">Active Roles</div></div></div>
          <div class="rpm-sc"><div class="rpm-sc-ic ic-a"><i class="bx bx-user-check"></i></div><div><div class="rpm-sc-v">${d.users}</div><div class="rpm-sc-l">Users Assigned</div></div></div>
          <div class="rpm-sc"><div class="rpm-sc-ic ic-p"><i class="bx bx-customize"></i></div><div><div class="rpm-sc-v">${d.custom}</div><div class="rpm-sc-l">Custom Roles</div></div></div>
          <div class="rpm-sc"><div class="rpm-sc-ic ic-r"><i class="bx bx-certification"></i></div><div><div class="rpm-sc-v">✓</div><div class="rpm-sc-l">RA 9184 Status</div></div></div>`;
    } catch(e) { toast('Failed to load stats: ' + e.message, 'd'); }
}

// ── ROLES ─────────────────────────────────────────────────────────────────────
async function loadRoles() {
    try {
        allRoles = await apiGet(API + '?api=roles');
        renderCards();
    } catch(e) {
        toast('Failed to load roles: ' + e.message, 'd');
        document.getElementById('cardsGrid').innerHTML =
            '<div style="grid-column:1/-1;padding:40px;text-align:center;color:var(--red);font-size:13px">Error loading roles. Please refresh.</div>';
    }
}

// ── ROLE CARDS ────────────────────────────────────────────────────────────────
function permSummary(r) {
    const set = new Set();
    Object.values(r.perms || {}).forEach(p => PERMS_KEYS.forEach(k => { if (p[k]) set.add(k); }));
    return [...set];
}
function modulesWithAccess(r) {
    return MODULES_DEF
        .map(g => ({ label: g.group.split('(')[1]?.replace(')','').trim() || g.group.split(' ')[0], items: g.items }))
        .filter(g => g.items.some(m => Object.values(r.perms?.[m] || {}).some(Boolean)))
        .map(g => g.label);
}

function renderCards() {
    const q = (document.getElementById('cardSearch').value || '').toLowerCase();
    const f = document.getElementById('cardFilter').value;
    const filtered = allRoles.filter(r => {
        if (q && !r.name.toLowerCase().includes(q) && !(r.desc||'').toLowerCase().includes(q)) return false;
        if (f === 'active'   && !r.active) return false;
        if (f === 'inactive' &&  r.active) return false;
        return true;
    });
    const grid = document.getElementById('cardsGrid');
    if (!filtered.length) {
        grid.innerHTML = '<div style="grid-column:1/-1"><div class="rpm-empty"><i class="bx bx-id-card"></i><p>No roles found.</p></div></div>';
        return;
    }
    grid.innerHTML = filtered.map(r => {
        const s = rs(r), mods = modulesWithAccess(r), ps = permSummary(r);
        const isSystem = r.type !== 'custom';
        return `<div class="role-card ${s.cls} ${r.active?'':'inactive'}">
          <div class="rc-head">
            <div class="rc-ti">
              <div class="rpm-sc-ic ${s.ic}"><i class="bx ${s.icon}"></i></div>
              <div>
                <div class="rc-nm">${esc(r.name)}</div>
                <div class="rc-uc"><i class="bx bx-user"></i>${r.users} user${r.users!==1?'s':''} &nbsp;·&nbsp; ID ${r.id}</div>
              </div>
            </div>
            <div class="rc-acts">
              <button class="rbtn rbtn-blue rbtn-xs ionly" onclick="openEdit(${r.id})" title="Edit Permissions"><i class="bx bx-edit"></i></button>
              <button class="rbtn rbtn-ghost rbtn-xs ionly" onclick="cloneRole(${r.id})" title="Clone Role"><i class="bx bx-copy"></i></button>
              ${r.active
                ? `<button class="rbtn rbtn-warn rbtn-xs ionly" onclick="promptAct(${r.id},'deactivate')" title="Deactivate"><i class="bx bx-block"></i></button>`
                : `<button class="rbtn rbtn-teal rbtn-xs ionly" onclick="promptAct(${r.id},'activate')" title="Activate"><i class="bx bx-check-circle"></i></button>`}
            </div>
          </div>
          <div class="rc-body">
            <div style="font-size:12px;color:var(--t2);margin-bottom:10px;line-height:1.5">${esc(r.desc||'No description.')}</div>
            <div class="rc-mods">${mods.length ? mods.map(m=>`<span class="rc-mod" style="background:rgba(46,125,50,.09);color:var(--grn);border:1px solid rgba(46,125,50,.2)">${m}</span>`).join('') : '<span style="font-size:11px;color:var(--t3)">No module access</span>'}</div>
            <div class="rc-perms">${ps.map(k=>{const cl={V:'pt-v',C:'pt-c',E:'pt-e',A:'pt-a',D:'pt-d'}[k]||'';return`<span class="perm-tag ${cl}">${k} — ${PERMS_LABELS[k]}</span>`;}).join('')||'<span style="font-size:11px;color:var(--t3)">No permissions enabled</span>'}</div>
            <div class="rc-foot">
              <span class="rc-created">Created ${fD(r.createdAt)}</span>
              <div style="display:flex;gap:6px;align-items:center">
                ${isSystem?`<span class="sa-excl"><i class="bx bx-lock-alt"></i> System</span>`:''}
                <span class="status-pill ${r.active?'sp-active':'sp-inactive'}">${r.active?'Active':'Inactive'}</span>
              </div>
            </div>
          </div>
        </div>`;
    }).join('');
}

['cardSearch','cardFilter'].forEach(id =>
    document.getElementById(id).addEventListener('input', renderCards));

// ── PERMISSION MATRIX ─────────────────────────────────────────────────────────
function renderMatrix() {
    const activeRoles = allRoles.filter(r => r.active);
    const tbl = document.getElementById('matrixTbl');
    if (!activeRoles.length) { tbl.innerHTML = '<tr><td style="padding:30px;text-align:center;color:var(--t3)">No active roles.</td></tr>'; return; }

    const COL = 5;
    let html = `<colgroup><col style="min-width:180px">`;
    activeRoles.forEach(() => { for(let i=0;i<COL;i++) html+=`<col style="min-width:40px">`; });
    html += `</colgroup><thead><tr><th class="mt-header-role th-mod">Module / Feature</th>`;
    activeRoles.forEach(r => {
        const s = rs(r);
        html += `<th class="mt-header-role" colspan="${COL}"><div class="role-label"><i class="bx ${s.icon}" style="font-size:15px;color:${s.color}"></i><span>${esc(r.name)}</span></div></th>`;
    });
    html += `</tr><tr class="mt-sub-hd"><td class="mod-cell"></td>`;
    activeRoles.forEach(() => PERMS_KEYS.forEach(k => { html += `<td>${k}</td>`; }));
    html += `</tr></thead><tbody>`;

    MODULES_DEF.forEach(g => {
        html += `<tr class="mt-grp"><td colspan="${1+activeRoles.length*COL}"><span><i class="bx ${g.icon}" style="font-size:13px;margin-right:6px;vertical-align:middle"></i>${g.group}</span></td></tr>`;
        g.items.forEach(item => {
            html += `<tr class="mt-row"><td><div class="mt-module"><i class="bx bx-chevron-right"></i>${esc(item)}</div></td>`;
            activeRoles.forEach(r => {
                const isSA = r.type === 'Super Admin';
                PERMS_KEYS.forEach(k => {
                    const on = !!(r.perms?.[item]?.[k]);
                    html += isSA
                        ? `<td class="mt-toggle-cell"><div class="sa-lock" title="Locked — Super Admin always has full access"><i class="bx bx-lock"></i></div></td>`
                        : `<td class="mt-toggle-cell"><div class="toggle-wrap"><label class="rpm-toggle" title="${PERMS_LABELS[k]}"><input type="checkbox" data-role="${r.id}" data-mod="${esc(item)}" data-perm="${k}" ${on?'checked':''}><span class="toggle-sl"></span></label></div></td>`;
                });
            });
            html += `</tr>`;
        });
    });
    html += `</tbody>`;
    tbl.innerHTML = html;
}

function matrixToggleAll(val) {
    document.querySelectorAll('#matrixTbl input[type=checkbox]').forEach(cb => cb.checked = val);
    toast(val ? 'All permissions enabled.' : 'All permissions cleared.', 'w');
}

document.getElementById('saveMatrixBtn').addEventListener('click', async () => {
    const btn = document.getElementById('saveMatrixBtn');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Saving…`;
    try {
        const rows = [];
        document.querySelectorAll('#matrixTbl input[type=checkbox]').forEach(cb => {
            rows.push({ roleId: +cb.dataset.role, module: cb.dataset.mod, perm: cb.dataset.perm, enabled: cb.checked });
        });
        await apiPost(API + '?api=save-matrix', { rows });
        // Refresh allRoles so cards + matrix stay in sync
        allRoles = await apiGet(API + '?api=roles');
        renderCards();
        toast('Permission matrix saved successfully.', 's');
    } catch(e) { toast('Save failed: ' + e.message, 'd'); }
    finally { btn.disabled = false; btn.innerHTML = `<i class="bx bx-save"></i> Save Matrix`; }
});

// ── AUDIT ─────────────────────────────────────────────────────────────────────
async function loadAudit() {
    try {
        const rows = await apiGet(API + '?api=audit');
        document.getElementById('auditList').innerHTML = rows.length
            ? rows.map(a => `
                <div class="audit-item">
                  <div class="audit-dot ${a.css_class || 'ad-b'}"><i class="bx ${a.icon || 'bx-edit'}"></i></div>
                  <div class="audit-bd" style="flex:1;min-width:0">
                    <div class="au">${esc(a.action_label)} ${a.is_super_admin ? '<span class="sa-tag">Super Admin</span>' : ''}</div>
                    <div class="at"><i class="bx bx-user" style="font-size:11px"></i>${esc(a.actor_name)}${a.ip_address?` <span class="audit-ip"><i class="bx bx-desktop" style="font-size:10px;margin-right:2px"></i>${esc(a.ip_address)}</span>`:''}</div>
                    ${a.note ? `<div class="audit-note">"${esc(a.note)}"</div>` : ''}
                  </div>
                  <div class="audit-ts">${fmtTs(a.occurred_at)}</div>
                </div>`).join('')
            : '<div style="padding:32px;text-align:center;color:var(--t3);font-size:13px">No audit entries yet.</div>';
    } catch(e) { toast('Audit load error: ' + e.message, 'd'); }
}

// ── SLIDE-OVER ────────────────────────────────────────────────────────────────
function buildSlPermSections(perms) {
    return MODULES_DEF.map(g => `
      <div class="perm-section">
        <div class="perm-sec-head"><i class="bx ${g.icon}"></i>${g.group}</div>
        ${g.items.map(item => `
          <div class="perm-row">
            <span class="perm-row-label">${esc(item)}</span>
            <div class="perm-toggles">
              ${PERMS_KEYS.map(k => `
                <div class="pt-item">
                  <label class="perm-toggle-mini" title="${PERMS_LABELS[k]}">
                    <input type="checkbox" data-mod="${esc(item)}" data-perm="${k}" ${(perms?.[item]?.[k]) ? 'checked' : ''}>
                    <span class="ptm-sl"></span>
                  </label>
                  <span>${k}</span>
                </div>`).join('')}
            </div>
          </div>`).join('')}
      </div>`).join('');
}

function buildEmptyPerms() {
    const p = {};
    MODULES_DEF.forEach(g => g.items.forEach(item => { p[item] = {V:0,C:0,E:0,A:0,D:0}; }));
    return p;
}

function openCreate() {
    editRoleId = null;
    document.getElementById('slTitle').textContent = 'Create Role';
    document.getElementById('slSub').textContent   = 'Define a new role and configure module permissions';
    document.getElementById('slSubmitLabel').textContent = 'Save Role';
    document.getElementById('fRoleName').value = '';
    document.getElementById('fRoleType').value = 'custom';
    document.getElementById('fRoleDesc').value = '';
    document.querySelectorAll('.preset-chip').forEach(c => c.classList.remove('selected'));
    document.getElementById('slPermSections').innerHTML = buildSlPermSections(buildEmptyPerms());
    openSlider();
}

function openEdit(id) {
    const r = allRoles.find(x => x.id === id);
    if (!r) return;
    editRoleId = id;
    document.getElementById('slTitle').textContent = `Edit Role — ${r.name}`;
    document.getElementById('slSub').textContent   = 'Update role name, description, and module permissions';
    document.getElementById('slSubmitLabel').textContent = 'Save Changes';
    document.getElementById('fRoleName').value = r.name;
    document.getElementById('fRoleType').value = r.type;
    document.getElementById('fRoleDesc').value = r.desc || '';
    document.querySelectorAll('.preset-chip').forEach(c => c.classList.remove('selected'));
    document.getElementById('slPermSections').innerHTML = buildSlPermSections(r.perms || buildEmptyPerms());
    openSlider();
}

function openSlider() {
    document.getElementById('roleSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(() => document.getElementById('fRoleName').focus(), 350);
}
function closeSlider() {
    document.getElementById('roleSlider').classList.remove('on');
    document.getElementById('slOverlay').classList.remove('on');
    editRoleId = null;
}

document.getElementById('slOverlay').addEventListener('click', e => { if (e.target === document.getElementById('slOverlay')) closeSlider(); });
document.getElementById('slClose').addEventListener('click', closeSlider);
document.getElementById('slCancel').addEventListener('click', closeSlider);
document.getElementById('createRoleBtn').addEventListener('click', openCreate);

document.getElementById('slSubmit').addEventListener('click', async () => {
    const name  = document.getElementById('fRoleName').value.trim();
    const type  = document.getElementById('fRoleType').value;
    const desc  = document.getElementById('fRoleDesc').value.trim();
    if (!name) { shk('fRoleName'); return toast('Role name is required.', 'w'); }

    // Collect perms from toggles
    const perms = {};
    document.querySelectorAll('#slPermSections input[type=checkbox]').forEach(cb => {
        const mod = cb.dataset.mod, pk = cb.dataset.perm;
        if (!perms[mod]) perms[mod] = {V:0,C:0,E:0,A:0,D:0};
        perms[mod][pk] = cb.checked ? 1 : 0;
    });

    const btn = document.getElementById('slSubmit');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Saving…`;

    try {
        if (editRoleId) {
            await apiPost(API + '?api=update', { id: editRoleId, name, type, desc, perms });
            toast(`${name} updated successfully.`, 's');
        } else {
            const saved = await apiPost(API + '?api=create', { name, type, desc, perms });
            toast(`Role created (ID ${saved.id}).`, 's');
        }
        closeSlider();
        allRoles = await apiGet(API + '?api=roles');
        loadStats();
        renderCards();
    } catch(e) {
        toast('Save failed: ' + e.message, 'd');
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="bx bx-save"></i> <span id="slSubmitLabel">${editRoleId ? 'Save Changes' : 'Save Role'}</span>`;
    }
});

// ── PRESETS ───────────────────────────────────────────────────────────────────
const PRESET_PERMS = {
    'ra9184':    () => buildPreset('ra9184'),
    'read-only': () => buildPreset('read-only'),
    'warehouse': () => buildPreset('warehouse'),
    'compliance':() => buildPreset('compliance'),
};

function buildPreset(key) {
    const p = {};
    MODULES_DEF.forEach(g => g.items.forEach(item => {
        switch(key) {
            case 'ra9184':
                p[item] = g.group.includes('PSM') ? {V:1,C:1,E:0,A:0,D:0} : {V:1,C:0,E:0,A:0,D:0}; break;
            case 'read-only':
                p[item] = {V:1,C:0,E:0,A:0,D:0}; break;
            case 'warehouse':
                p[item] = g.group.includes('SWS') ? {V:1,C:1,E:1,A:0,D:0}
                        : g.group.includes('ALMS') ? {V:1,C:0,E:0,A:0,D:0}
                        : {V:0,C:0,E:0,A:0,D:0}; break;
            case 'compliance':
                p[item] = g.group.includes('DTRS') ? {V:1,C:1,E:1,A:1,D:0}
                        : g.group.includes('PSM')  ? {V:1,C:0,E:0,A:0,D:0}
                        : {V:0,C:0,E:0,A:0,D:0}; break;
            default:
                p[item] = {V:0,C:0,E:0,A:0,D:0};
        }
    }));
    return p;
}

function selectPreset(el, key) {
    document.querySelectorAll('.preset-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    const perms = PRESET_PERMS[key] ? PRESET_PERMS[key]() : buildEmptyPerms();
    document.getElementById('slPermSections').innerHTML = buildSlPermSections(perms);
    toast('Preset applied — review permissions below.', 's');
}

document.getElementById('ra9184Btn').addEventListener('click', async () => {
    try {
        await apiPost(API + '?api=apply-preset', { preset: 'ra9184' });
        allRoles = await apiGet(API + '?api=roles');
        renderCards();
        toast('RA 9184 compliance preset applied to all procurement roles.', 's');
    } catch(e) { toast('Failed: ' + e.message, 'd'); }
});

// ── CLONE ─────────────────────────────────────────────────────────────────────
window.cloneRole = async id => {
    try {
        const saved = await apiPost(API + '?api=clone', { id });
        allRoles = await apiGet(API + '?api=roles');
        loadStats();
        renderCards();
        toast(`Role "${saved.name}" created. Edit it to customize.`, 's');
    } catch(e) { toast('Clone failed: ' + e.message, 'd'); }
};

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
window.promptAct = (id, type) => {
    const r = allRoles.find(x => x.id === id);
    if (!r) return;
    actionTarget = id; actionKey = type;
    const cfg = {
        deactivate: { icon:'⛔', title:'Deactivate Role', btn:'rbtn-red',  label:'<i class="bx bx-block"></i> Deactivate' },
        activate:   { icon:'✅', title:'Activate Role',   btn:'rbtn-teal', label:'<i class="bx bx-check-circle"></i> Activate' },
    };
    const c = cfg[type];
    document.getElementById('amIcon').textContent  = c.icon;
    document.getElementById('amTitle').textContent = c.title;
    document.getElementById('amBody').innerHTML    = `Role: <strong>${esc(r.name)}</strong> &nbsp;·&nbsp; ${r.users} user(s) assigned`;
    document.getElementById('amSaNote').style.display = 'flex';
    document.getElementById('amSaText').textContent = type === 'deactivate'
        ? `Deactivating this role will revoke access for ${r.users} user(s).`
        : 'Reactivating will restore access for all users assigned to this role.';
    document.getElementById('amRemarks').value = '';
    const cb = document.getElementById('amConfirm');
    cb.className = `rbtn rbtn-sm ${c.btn}`; cb.innerHTML = c.label;
    document.getElementById('actionModal').classList.add('on');
};

document.getElementById('amConfirm').addEventListener('click', async () => {
    const note   = document.getElementById('amRemarks').value.trim();
    const active = actionKey === 'activate';
    const btn    = document.getElementById('amConfirm');
    btn.disabled = true;
    try {
        await apiPost(API + '?api=toggle-active', { id: actionTarget, active, note });
        const r = allRoles.find(x => x.id === actionTarget);
        if (r) r.active = active;
        renderCards();
        loadStats();
        toast(`${allRoles.find(x=>x.id===actionTarget)?.name || 'Role'} ${active?'activated':'deactivated'}.`, 's');
    } catch(e) { toast('Failed: ' + e.message, 'd'); }
    finally { btn.disabled = false; }
    document.getElementById('actionModal').classList.remove('on');
});
document.getElementById('amCancel').addEventListener('click', () => document.getElementById('actionModal').classList.remove('on'));
document.getElementById('actionModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('on'); });

// ── TABS ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.rpm-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.rpm-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const v = tab.dataset.view;
        ['cards','matrix','audit'].forEach(n => {
            document.getElementById('view-' + n).style.display = n === v ? 'block' : 'none';
        });
        if (v === 'matrix') renderMatrix();
        if (v === 'audit')  loadAudit();
    });
});

document.getElementById('auditBtn').addEventListener('click', () => {
    document.querySelectorAll('.rpm-tab').forEach(t => t.classList.toggle('active', t.dataset.view === 'audit'));
    ['cards','matrix','audit'].forEach(n => document.getElementById('view-'+n).style.display = n==='audit'?'block':'none');
    loadAudit();
});

// ── EXPORT ────────────────────────────────────────────────────────────────────
document.getElementById('exportComplianceBtn').addEventListener('click', async () => {
    try {
        const rows = await apiGet(API + '?api=audit');
        const cols = ['action_label','actor_name','note','ip_address','occurred_at'];
        const lines = [cols.join(',')];
        rows.forEach(r => lines.push(cols.map(c => `"${String(r[c]??'').replace(/"/g,'""')}"`).join(',')));
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type:'text/csv' }));
        a.download = `compliance_audit_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        toast('Compliance report exported.', 's');
    } catch(e) { toast('Export failed: ' + e.message, 'd'); }
});

document.getElementById('exportAuditBtn').addEventListener('click', () =>
    document.getElementById('exportComplianceBtn').click());

// ── UTILS ─────────────────────────────────────────────────────────────────────
function shk(id) {
    const el = document.getElementById(id);
    el.style.borderColor = '#DC2626';
    el.style.animation   = 'none';
    el.offsetHeight;
    el.style.animation   = 'rpSHK .3s ease';
    setTimeout(() => { el.style.borderColor = ''; el.style.animation = ''; }, 600);
}
function toast(msg, type = 's') {
    const ic = { s:'bx-check-circle', w:'bx-error', d:'bx-error-circle' };
    const el = document.createElement('div');
    el.className = `rpm-toast t${type}`;
    el.innerHTML = `<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3500);
}
</script>
</body>
</html>