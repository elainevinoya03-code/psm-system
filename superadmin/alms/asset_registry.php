<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ar_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ar_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ar_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function ar_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

function ar_next_id(): string {
    $year = date('Y');
    $rows = ar_sb('alms_assets', 'GET', [
        'select'   => 'asset_id',
        'asset_id' => 'like.AST-' . $year . '-%',
        'order'    => 'id.desc',
        'limit'    => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/AST-\d{4}-(\d+)/', $rows[0]['asset_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'AST-' . $year . '-' . sprintf('%04d', $next);
}

function ar_build(array $row): array {
    return [
        'id'           => (int)$row['id'],
        'assetId'      => $row['asset_id']      ?? '',
        'name'         => $row['name']           ?? '',
        'category'     => $row['category']       ?? '',
        'type'         => $row['type']           ?? '',
        'brand'        => $row['brand']          ?? '',
        'serial'       => $row['serial']         ?? '',
        'zone'         => $row['zone']           ?? '',
        'dept'         => $row['dept']           ?? '',
        'purchaseDate' => $row['purchase_date']  ?? '',
        'purchaseCost' => (float)($row['purchase_cost']  ?? 0),
        'currentValue' => (float)($row['current_value']  ?? 0),
        'condition'    => $row['condition']      ?? 'Good',
        'status'       => $row['status']         ?? 'Active',
        'assignee'     => $row['assignee']       ?? '',
        'assignDate'   => $row['assign_date']    ?? '',
        'returnDate'   => $row['return_date']    ?? '',
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET categories (from alms_asset_categories, with fallback) ──────────
        if ($api === 'categories' && $method === 'GET') {
            $defaults = [
                ['id'=>1,'name'=>'IT Equipment',    'icon'=>'bx-laptop', 'color'=>'#2563EB'],
                ['id'=>2,'name'=>'Vehicles',         'icon'=>'bx-car',    'color'=>'#D97706'],
                ['id'=>3,'name'=>'Heavy Machinery',  'icon'=>'bx-cog',    'color'=>'#DC2626'],
                ['id'=>4,'name'=>'Office Furniture', 'icon'=>'bx-chair',  'color'=>'#0D9488'],
                ['id'=>5,'name'=>'Tools & Equipment','icon'=>'bx-wrench', 'color'=>'#7C3AED'],
                ['id'=>6,'name'=>'Other',            'icon'=>'bx-package','color'=>'#6B7280'],
            ];
            try {
                $rows = ar_sb('alms_asset_categories', 'GET', [
                    'select' => 'id,name,icon,color',
                    'order'  => 'name.asc',
                ]);
            } catch (Throwable $e) {
                // Table does not exist yet — return defaults silently
                $rows = [];
            }
            if (empty($rows)) $rows = $defaults;
            ar_ok(array_map(fn($r) => [
                'id'    => (int)($r['id']    ?? 0),
                'name'  => $r['name']  ?? '',
                'icon'  => $r['icon']  ?? 'bx-package',
                'color' => $r['color'] ?? '#6B7280',
            ], $rows));
        }

        // ── GET zones (from sws_zones) ────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $rows = ar_sb('sws_zones', 'GET', ['select' => 'id,name,color', 'order' => 'id.asc']);
            if (empty($rows)) {
                $rows = [
                    ['id' => 'ZN-A01', 'name' => 'Zone A — Raw Materials',      'color' => '#2E7D32'],
                    ['id' => 'ZN-B02', 'name' => 'Zone B — Safety & PPE',       'color' => '#0D9488'],
                    ['id' => 'ZN-C03', 'name' => 'Zone C — Fuels & Lubricants', 'color' => '#DC2626'],
                    ['id' => 'ZN-D04', 'name' => 'Zone D — Office Supplies',    'color' => '#2563EB'],
                    ['id' => 'ZN-E05', 'name' => 'Zone E — Electrical & IT',    'color' => '#7C3AED'],
                    ['id' => 'ZN-F06', 'name' => 'Zone F — Tools & Equipment',  'color' => '#D97706'],
                    ['id' => 'ZN-G07', 'name' => 'Zone G — Finished Goods',     'color' => '#059669'],
                ];
            }
            ar_ok($rows);
        }

        // ── GET staff list from users ─────────────────────────────────────────
        if ($api === 'staff' && $method === 'GET') {
            $rows = ar_sb('users', 'GET', [
                'select' => 'user_id,first_name,last_name',
                'status' => 'eq.Active',
                'order'  => 'first_name.asc',
            ]);
            $staff = array_map(fn($r) => [
                'id'   => $r['user_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            ], $rows);
            $staff = array_values(array_filter($staff, fn($s) => $s['name'] !== ''));
            ar_ok($staff);
        }

        // ── GET assets list ───────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $rows = ar_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,category,type,brand,serial,zone,dept,purchase_date,purchase_cost,current_value,condition,status,assignee,assign_date,return_date',
                'order'  => 'id.desc',
            ]);
            ar_ok(array_map('ar_build', $rows));
        }

        // ── GET single asset ──────────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ar_err('Missing id', 400);
            $rows = ar_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,category,type,brand,serial,zone,dept,purchase_date,purchase_cost,current_value,condition,status,assignee,assign_date,return_date',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) ar_err('Asset not found', 404);
            ar_ok(ar_build($rows[0]));
        }

        // ── GET audit log for an asset ────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ar_err('Missing id', 400);
            $rows = ar_sb('alms_asset_audit_log', 'GET', [
                'select'   => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'asset_id' => 'eq.' . $id,
                'order'    => 'occurred_at.desc',
            ]);
            ar_ok($rows);
        }

        // ── POST save asset (create / edit) ───────────────────────────────────
        if ($api === 'save' && $method === 'POST') {
            $b             = ar_body();
            $name          = trim($b['name']         ?? '');
            $category      = trim($b['category']     ?? '');
            $type          = trim($b['type']         ?? '');
            $brand         = trim($b['brand']        ?? '');
            $serial        = trim($b['serial']       ?? '');
            $zone          = trim($b['zone']         ?? '');
            $dept          = trim($b['dept']         ?? '');
            $purchaseDate  = trim($b['purchaseDate'] ?? '') ?: null;
            $purchaseCost  = (float)($b['purchaseCost'] ?? 0);
            $currentValue  = (float)($b['currentValue']  ?? $purchaseCost);
            $condition     = trim($b['condition']    ?? 'Good');
            $status        = trim($b['status']       ?? 'Active');
            $editId        = (int)($b['id']          ?? 0);

            if (!$name)         ar_err('Asset name is required', 400);
            if (!$category)     ar_err('Category is required', 400);
            if (!$zone)         ar_err('Zone is required', 400);
            if ($purchaseCost <= 0) ar_err('Purchase cost is required', 400);

            $allowedStatus = ['Active', 'Assigned', 'Under Maintenance', 'Disposed', 'Lost/Stolen'];
            $allowedCond   = ['New', 'Good', 'Fair', 'Poor'];
            if (!in_array($status,    $allowedStatus, true)) $status    = 'Active';
            if (!in_array($condition, $allowedCond,   true)) $condition = 'Good';

            $now = date('Y-m-d H:i:s');
            $payload = [
                'name'          => $name,
                'category'      => $category,
                'type'          => $type,
                'brand'         => $brand,
                'serial'        => $serial,
                'zone'          => $zone,
                'dept'          => $dept,
                'purchase_date' => $purchaseDate,
                'purchase_cost' => $purchaseCost,
                'current_value' => $currentValue ?: $purchaseCost,
                'condition'     => $condition,
                'status'        => $status,
                'updated_at'    => $now,
            ];

            if ($editId) {
                $existing = ar_sb('alms_assets', 'GET', [
                    'select' => 'id,asset_id,status',
                    'id'     => 'eq.' . $editId,
                    'limit'  => '1',
                ]);
                if (empty($existing)) ar_err('Asset not found', 404);
                ar_sb('alms_assets', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                ar_sb('alms_asset_audit_log', 'POST', [], [[
                    'asset_id'      => $editId,
                    'action_label'  => 'Asset Details Updated',
                    'actor_name'    => $actor,
                    'actor_role'    => 'Admin',
                    'note'          => 'Fields updated by ' . $actor . '.',
                    'icon'          => 'bx-edit',
                    'css_class'     => 'ad-s',
                    'is_super_admin'=> false,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $rows = ar_sb('alms_assets', 'GET', [
                    'select' => 'id,asset_id,name,category,type,brand,serial,zone,dept,purchase_date,purchase_cost,current_value,condition,status,assignee,assign_date,return_date',
                    'id'     => 'eq.' . $editId, 'limit' => '1',
                ]);
                ar_ok(ar_build($rows[0]));
            }

            // Create
            $assetId = ar_next_id();
            $payload['asset_id']       = $assetId;
            $payload['assignee']       = '';
            $payload['assign_date']    = null;
            $payload['return_date']    = null;
            $payload['created_by']     = $actor;
            $payload['created_user_id']= $_SESSION['user_id'] ?? null;
            $payload['created_at']     = $now;

            $inserted = ar_sb('alms_assets', 'POST', [], [$payload]);
            if (empty($inserted)) ar_err('Failed to create asset', 500);
            $newId = (int)$inserted[0]['id'];

            ar_sb('alms_asset_audit_log', 'POST', [], [[
                'asset_id'      => $newId,
                'action_label'  => 'Asset Registered',
                'actor_name'    => $actor,
                'actor_role'    => 'System Admin',
                'note'          => 'Initial asset record created.',
                'icon'          => 'bx-package',
                'css_class'     => 'ad-c',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = ar_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,category,type,brand,serial,zone,dept,purchase_date,purchase_cost,current_value,condition,status,assignee,assign_date,return_date',
                'id'     => 'eq.' . $newId, 'limit' => '1',
            ]);
            ar_ok(ar_build($rows[0]));
        }

        // ── POST action (assign / transfer / dispose / mark-lost) ─────────────
        if ($api === 'action' && $method === 'POST') {
            $b    = ar_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$id)   ar_err('Missing id', 400);
            if (!$type) ar_err('Missing type', 400);

            $rows = ar_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,status,zone,assignee',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) ar_err('Asset not found', 404);
            $asset = $rows[0];

            $patch       = ['updated_at' => $now];
            $auditLabel  = '';
            $auditNote   = trim($b['remarks'] ?? '');
            $auditIcon   = 'bx-info-circle';
            $auditClass  = 'ad-s';
            $isSA        = false;

            switch ($type) {

                case 'assign':
                    $assignee   = trim($b['assignee']   ?? '');
                    $returnDate = trim($b['returnDate']  ?? '') ?: null;
                    if ($assignee) {
                        $patch['assignee']    = $assignee;
                        $patch['assign_date'] = date('Y-m-d');
                        $patch['return_date'] = $returnDate;
                        $patch['status']      = 'Assigned';
                        $auditLabel = 'Asset Assigned to ' . $assignee;
                        $auditIcon  = 'bx-user-check';
                        $auditClass = 'ad-s';
                        $auditNote  = $auditNote ?: 'Assigned to ' . $assignee . ($returnDate ? ', return due ' . $returnDate : '') . '.';
                    } else {
                        // Return / unassign
                        $patch['assignee']    = '';
                        $patch['assign_date'] = null;
                        $patch['return_date'] = null;
                        $patch['status']      = 'Active';
                        $auditLabel = 'Asset Returned / Unassigned';
                        $auditIcon  = 'bx-undo';
                        $auditClass = 'ad-a';
                        $auditNote  = $auditNote ?: 'Returned from ' . ($asset['assignee'] ?: 'previous assignee') . '.';
                    }
                    break;

                case 'transfer':
                    $newZone = trim($b['zone'] ?? '');
                    if (!$newZone) ar_err('Destination zone is required', 400);
                    $oldZone = $asset['zone'];
                    $patch['zone'] = $newZone;
                    $auditLabel = 'Cross-zone Transfer: ' . $oldZone . ' → ' . $newZone;
                    $auditIcon  = 'bx-transfer';
                    $auditClass = 'ad-o';
                    $auditNote  = $auditNote ?: 'Transferred from ' . $oldZone . ' to ' . $newZone . '.';
                    $isSA       = true;
                    break;

                case 'maintenance':
                    if (in_array($asset['status'], ['Disposed', 'Lost/Stolen'], true))
                        ar_err('Cannot send a disposed or lost asset for maintenance.', 400);
                    $patch['status']   = 'Under Maintenance';
                    $patch['assignee'] = '';
                    $auditLabel = 'Sent for Maintenance';
                    $auditIcon  = 'bx-wrench';
                    $auditClass = 'ad-o';
                    $auditNote  = $auditNote ?: 'Asset sent for maintenance.';
                    break;

                case 'restore':
                    if ($asset['status'] !== 'Under Maintenance')
                        ar_err('Only assets under maintenance can be restored.', 400);
                    $patch['status'] = 'Active';
                    $auditLabel = 'Restored to Active';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ad-a';
                    $auditNote  = $auditNote ?: 'Maintenance completed, asset restored.';
                    break;

                case 'dispose':
                    if (in_array($asset['status'], ['Disposed'], true))
                        ar_err('Asset is already disposed.', 400);
                    $patch['status']   = 'Disposed';
                    $patch['assignee'] = '';
                    $auditLabel = 'Force Disposed by Super Admin';
                    $auditIcon  = 'bx-trash';
                    $auditClass = 'ad-x';
                    $auditNote  = $auditNote ?: 'Manually disposed by Super Admin.';
                    $isSA       = true;
                    break;

                case 'mark-lost':
                    if (in_array($asset['status'], ['Disposed', 'Lost/Stolen'], true))
                        ar_err('Asset is already disposed or marked lost.', 400);
                    $patch['status']   = 'Lost/Stolen';
                    $patch['assignee'] = '';
                    $auditLabel = 'Marked as Lost/Stolen';
                    $auditIcon  = 'bx-error-circle';
                    $auditClass = 'ad-r';
                    $auditNote  = $auditNote ?: 'Reported as lost or stolen.';
                    $isSA       = true;
                    break;

                default:
                    ar_err('Unsupported action', 400);
            }

            ar_sb('alms_assets', 'PATCH', ['id' => 'eq.' . $id], $patch);
            ar_sb('alms_asset_audit_log', 'POST', [], [[
                'asset_id'      => $id,
                'action_label'  => $auditLabel,
                'actor_name'    => $actor,
                'actor_role'    => $isSA ? 'Super Admin' : 'Admin',
                'note'          => $auditNote,
                'icon'          => $auditIcon,
                'css_class'     => $auditClass,
                'is_super_admin'=> $isSA,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = ar_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,category,type,brand,serial,zone,dept,purchase_date,purchase_cost,current_value,condition,status,assignee,assign_date,return_date',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            ar_ok(ar_build($rows[0]));
        }

        // ── POST batch action (batch assign / batch dispose) ──────────────────
        if ($api === 'batch' && $method === 'POST') {
            $b    = ar_body();
            $ids  = array_map('intval', $b['ids']  ?? []);
            $type = trim($b['type'] ?? '');
            $now  = date('Y-m-d H:i:s');

            if (empty($ids)) ar_err('No asset IDs provided.', 400);
            if (!$type)       ar_err('Missing batch type.', 400);

            $updated    = 0;
            $auditNote  = trim($b['remarks'] ?? '');

            foreach ($ids as $id) {
                $rows = ar_sb('alms_assets', 'GET', [
                    'select' => 'id,asset_id,status,zone,assignee',
                    'id'     => 'eq.' . $id, 'limit' => '1',
                ]);
                if (empty($rows)) continue;
                $asset = $rows[0];

                $patch      = ['updated_at' => $now];
                $auditLabel = '';
                $auditIcon  = 'bx-transfer';
                $auditClass = 'ad-s';
                $isSA       = false;

                if ($type === 'batch-assign') {
                    $assignee   = trim($b['assignee'] ?? '');
                    if (!$assignee) continue;
                    if (in_array($asset['status'], ['Disposed','Lost/Stolen'], true)) continue;
                    $patch['assignee']    = $assignee;
                    $patch['assign_date'] = date('Y-m-d');
                    $patch['status']      = 'Assigned';
                    $auditLabel = 'Bulk Assigned to ' . $assignee;
                    $auditClass = 'ad-s';

                } elseif ($type === 'batch-dispose') {
                    if ($asset['status'] === 'Disposed') continue;
                    $patch['status']   = 'Disposed';
                    $patch['assignee'] = '';
                    $auditLabel = 'Bulk Force Disposed by Super Admin';
                    $auditIcon  = 'bx-trash';
                    $auditClass = 'ad-x';
                    $isSA       = true;
                } else {
                    continue;
                }

                ar_sb('alms_assets', 'PATCH', ['id' => 'eq.' . $id], $patch);
                ar_sb('alms_asset_audit_log', 'POST', [], [[
                    'asset_id'      => $id,
                    'action_label'  => $auditLabel,
                    'actor_name'    => $actor,
                    'actor_role'    => $isSA ? 'Super Admin' : 'Admin',
                    'note'          => $auditNote,
                    'icon'          => $auditIcon,
                    'css_class'     => $auditClass,
                    'is_super_admin'=> $isSA,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $updated++;
            }

            ar_ok(['updated' => $updated]);
        }

        ar_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ar_err('Server error: ' . $e->getMessage(), 500);
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
<title>Asset Registry — ALMS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
#mainContent,#prSlider,#slOverlay,#actionModal,#viewModal,.pr-toasts{--s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);--t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;--hbg:var(--hover-bg-light);--bg:var(--bg-color);--grn:var(--primary-color);--gdk:var(--primary-dark);--red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);--rad:12px;--tr:var(--transition);}
#mainContent *,#prSlider *,#slOverlay *,#actionModal *,#viewModal *,.pr-toasts *{box-sizing:border-box;}
.pr-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem;}
.pr-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.pr-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.pr-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.pr-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-approve{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}.btn-approve:hover{background:#BBF7D0;}
.btn-reject{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}.btn-reject:hover{background:#FCA5A5;}
.btn-override{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE;}.btn-override:hover{background:#DBEAFE;}
.btn-warn{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}.btn-warn:hover{background:#FDE68A;}
.btn-teal{background:#CCFBF1;color:var(--tel);border:1px solid #99F6E4;}.btn-teal:hover{background:#99F6E4;}
.btn-sm{font-size:12px;padding:6px 13px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);}
.btn.ionly:hover{background:var(--hbg);color:var(--grn);border-color:var(--grn);}
.btn.ionly.btn-override:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE;}
.btn.ionly.btn-approve:hover{background:#DCFCE7;color:#166534;border-color:#BBF7D0;}
.btn.ionly.btn-reject:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.btn.ionly.btn-warn:hover{background:#FEF3C7;color:#92400E;border-color:#FCD34D;}
.btn.ionly.btn-teal:hover{background:#CCFBF1;color:var(--tel);border-color:#99F6E4;}
.btn:disabled{opacity:.4;pointer-events:none;}
.pr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both;}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px;}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:#6D28D9}.ic-d{background:#F3F4F6;color:#374151}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;}.sc-l{font-size:11px;color:var(--t2);margin-top:2px;}
.pr-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:UP .4s .1s both;}
.sw{position:relative;flex:1;min-width:220px;}.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none;}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}.si::placeholder{color:var(--t3);}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:12px;margin-bottom:14px;flex-wrap:wrap;}
.bulk-bar.on{display:flex;}.bulk-count{font-size:13px;font-weight:700;color:#166534;}.bulk-sep{width:1px;height:22px;background:rgba(46,125,50,.25);}
.sa-exclusive{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:2px 7px;}
.sa-exclusive i{font-size:11px;}
.pr-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.pr-tbl{width:auto;min-width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed;}
.pr-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none;overflow:hidden;}
.pr-tbl thead th.no-sort{cursor:default;}.pr-tbl thead th:hover:not(.no-sort){color:var(--grn);}.pr-tbl thead th.sorted{color:var(--grn);}
.pr-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle;}.pr-tbl thead th.sorted .sic{opacity:1;}
.pr-tbl thead th:first-child,.pr-tbl tbody td:first-child{padding-left:12px;padding-right:4px;}
.pr-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}.pr-tbl tbody tr:last-child{border-bottom:none;}.pr-tbl tbody tr:hover{background:var(--hbg);}.pr-tbl tbody tr.row-selected{background:#F0FDF4;}
.pr-tbl tbody td{padding:11px 10px;vertical-align:middle;cursor:pointer;overflow:hidden;}
.pr-tbl tbody td:first-child{cursor:default;}.pr-tbl tbody td:last-child{overflow:visible;white-space:nowrap;cursor:default;padding:8px;}
.pr-num{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);white-space:nowrap;display:block;overflow:hidden;text-overflow:ellipsis;}
.pr-amt{font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--t1);white-space:nowrap;}
.req-cell{display:flex;flex-direction:column;gap:2px;min-width:0;width:100%;}
.req-name{font-weight:600;color:var(--t1);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.req-sub{font-size:11px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.act-cell{display:flex;gap:3px;align-items:center;}
.dept-dot{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cb-wrap{display:flex;align-items:center;justify-content:center;}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer;}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-active{background:#DCFCE7;color:#166534;}.b-assigned{background:#EFF6FF;color:#1D4ED8;}.b-maintenance{background:#FEF3C7;color:#92400E;}.b-disposed{background:#F3F4F6;color:#4B5563;}.b-lost{background:#FEE2E2;color:#991B1B;}
.pr-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}.pgb:disabled{opacity:.4;pointer-events:none;}
.empty{padding:72px 20px;text-align:center;color:var(--t3);}.empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9;}
/* Searchable select */
.cs-wrap{position:relative;width:100%;}
.cs-input{width:100%;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.cs-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.cs-input::placeholder{color:var(--t3);}
.cs-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--s);border:1px solid var(--bdm);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.13);z-index:9999;max-height:220px;overflow-y:auto;}
.cs-drop.open{display:block;}
.cs-drop::-webkit-scrollbar{width:4px;}.cs-drop::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.cs-opt{padding:9px 12px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:background .12s;}
.cs-opt:hover,.cs-opt.hl{background:var(--hbg);}
.cs-opt .cs-name{font-size:13px;color:var(--t1);font-weight:500;}
.cs-opt .cs-sub{font-size:10.5px;color:var(--t3);}
.cs-opt.cs-none{color:var(--t3);cursor:default;font-size:12px;padding:12px;}.cs-opt.cs-none:hover{background:none;}
/* View Modal */
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#viewModal.on{opacity:1;pointer-events:all;}
.vm-box{background:#fff;border-radius:20px;width:780px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;}
.vm-mhd{padding:24px 28px 0;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg-color);flex-shrink:0;}
.vm-mtp{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;}
.vm-msi{display:flex;align-items:center;gap:16px;}
.vm-mav{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;color:#fff;flex-shrink:0;}
.vm-mnm{font-size:20px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mid{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-secondary);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mcl{width:36px;height:36px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-secondary);transition:all .15s;flex-shrink:0;}
.vm-mcl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.vm-mmt{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.vm-mc{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);background:#fff;border:1px solid rgba(46,125,50,.14);border-radius:8px;padding:5px 10px;line-height:1;}
.vm-mc i{font-size:14px;color:var(--primary-color);}
.vm-mtb{display:flex;gap:4px;}
.vm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--text-secondary);border:none;background:transparent;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.vm-tab:hover{background:var(--hover-bg-light);}.vm-tab.active{background:var(--primary-color);color:#fff;}.vm-tab i{font-size:14px;}
.vm-mbd{flex:1;overflow-y:auto;padding:24px 28px;background:#fff;}
.vm-mbd::-webkit-scrollbar{width:4px;}.vm-mbd::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px;}
.vm-tp{display:none;flex-direction:column;gap:18px;}.vm-tp.active{display:flex;}
.vm-sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.vm-sb{background:var(--bg-color);border:1px solid rgba(46,125,50,.14);border-radius:10px;padding:14px 16px;}
.vm-sb .sbv{font-size:18px;font-weight:800;color:var(--text-primary);line-height:1;}.vm-sb .sbv.mono{font-family:'DM Mono',monospace;font-size:13px;color:var(--primary-color);}.vm-sb .sbl{font-size:11px;color:var(--text-secondary);margin-top:3px;}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9EB0A2;display:block;margin-bottom:4px;}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--text-primary);line-height:1.5;}.vm-ii .v.muted{font-weight:400;color:#4B5563;}.vm-full{grid-column:1/-1;}
.vm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.vm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.vm-audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(46,125,50,.14);}
.vm-audit-item:last-child{border-bottom:none;padding-bottom:0;}
.vm-audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}.ad-e{background:#F3F4F6;color:#6B7280}.ad-o{background:#FEF3C7;color:#D97706}.ad-x{background:#F3F4F6;color:#374151}.ad-d{background:#F5F3FF;color:#6D28D9}
.vm-audit-body{flex:1;min-width:0;}.vm-audit-body .au{font-size:13px;font-weight:500;color:var(--text-primary);}
.vm-audit-body .at{font-size:11px;color:#9EB0A2;margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.vm-audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic;}
.vm-audit-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px;}
.vm-audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
.vm-mft{padding:16px 28px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg-color);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
/* Slide-over */
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s;}
#slOverlay.on{opacity:1;pointer-events:all;}
#prSlider{position:fixed;top:0;right:-600px;bottom:0;width:560px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18);}
#prSlider.on{right:0;}
.sl-hdr{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0;}
.sl-title{font-size:17px;font-weight:700;color:var(--t1);}.sl-subtitle{font-size:12px;color:var(--t2);margin-top:2px;}
.sl-close{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0;}
.sl-close:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.sl-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px;}
.sl-body::-webkit-scrollbar{width:4px;}.sl-body::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.sl-foot{padding:16px 24px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:5px;}.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2);}.fl span{color:var(--red);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.fdiv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;}
.fdiv::after{content:'';flex:1;height:1px;background:var(--bd);}
/* Action modal */
#actionModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
#actionModal.on{opacity:1;pointer-events:all;}
.am-box{background:var(--s);border-radius:16px;padding:28px 28px 24px;width:440px;max-width:92vw;box-shadow:var(--shlg);}
.am-icon{font-size:46px;margin-bottom:10px;line-height:1;}.am-title{font-size:18px;font-weight:700;color:var(--t1);margin-bottom:6px;}
.am-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px;}
.am-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.am-fg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2);}
.am-fg textarea,.am-fg input,.am-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.am-fg textarea{resize:vertical;min-height:68px;}
.am-fg textarea:focus,.am-fg input:focus,.am-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.am-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.am-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400E;}
.am-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.am-acts{display:flex;gap:10px;justify-content:flex-end;}
.pr-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}.toast.out{animation:TOUT .3s ease forwards;}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@media(max-width:900px){.pr-stats{grid-template-columns:repeat(2,1fr)}.fr{grid-template-columns:1fr}#prSlider{width:100vw}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="pr-wrap">

  <div class="pr-ph">
    <div>
      <p class="ey">ALMS · Asset Lifecycle &amp; Maintenance</p>
      <h1>Asset Registry</h1>
    </div>
    <div class="pr-ph-r">
      <button class="btn btn-ghost" onclick="doExport()"><i class="bx bx-export"></i> Export CSV</button>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Add Asset</button>
    </div>
  </div>

  <div class="pr-stats" id="statsBar"></div>

  <div class="pr-tb">
    <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search by asset name, ID, or serial…"></div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Active</option><option>Assigned</option><option>Under Maintenance</option>
      <option>Disposed</option><option>Lost/Stolen</option>
    </select>
    <select class="sel" id="fCategory"><option value="">All Categories</option></select>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
  </div>

  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-ghost btn-sm" id="batchAssignBtn"><i class="bx bx-user-plus"></i> Bulk Assign</button>
    <button class="btn btn-reject btn-sm" id="batchDisposeBtn"><i class="bx bx-trash"></i> Bulk Dispose</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Actions Included</span>
  </div>

  <div class="pr-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="pr-tbl" id="tbl">
      <colgroup>
        <col style="width:38px">
        <col style="width:115px">
        <col style="width:200px">
        <col style="width:145px">
        <col style="width:155px">
        <col style="width:115px">
        <col style="width:135px">
        <col style="width:145px">
        <col style="width:150px">
      </colgroup>
      <thead><tr>
        <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
        <th data-col="assetId">Asset ID <i class="bx bx-sort sic"></i></th>
        <th data-col="name">Asset / Brand <i class="bx bx-sort sic"></i></th>
        <th data-col="category">Category <i class="bx bx-sort sic"></i></th>
        <th data-col="zone">Zone / Dept <i class="bx bx-sort sic"></i></th>
        <th data-col="purchaseCost">Cost <i class="bx bx-sort sic"></i></th>
        <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
        <th data-col="assignee">Assignee <i class="bx bx-sort sic"></i></th>
        <th class="no-sort">Actions</th>
      </tr></thead>
      <tbody id="tbody"></tbody>
    </table>
    </div>
    <div class="pr-pager" id="pager"></div>
  </div>

</div>
</main>

<div class="pr-toasts" id="toastWrap"></div>
<div id="slOverlay"></div>

<div id="prSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Add New Asset</div><div class="sl-subtitle" id="slSub">Fill in asset details below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="fg">
      <label class="fl">Asset Name <span>*</span></label>
      <input type="text" class="fi" id="fName" placeholder="e.g. ThinkPad T14 Gen 3">
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Category <span>*</span></label>
        <div class="cs-wrap">
          <input type="text" class="cs-input" id="csCatSearch" placeholder="Search category…" autocomplete="off">
          <input type="hidden" id="fCatSl" value="">
          <div class="cs-drop" id="csCatDrop"></div>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Type</label>
        <input type="text" class="fi" id="fType" placeholder="e.g. Laptop">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Brand / Model</label>
        <input type="text" class="fi" id="fBrand" placeholder="e.g. Lenovo">
      </div>
      <div class="fg">
        <label class="fl">Serial Number</label>
        <input type="text" class="fi" id="fSerial" placeholder="e.g. PF3D28L">
      </div>
    </div>
    <div class="fdiv">Location & Assignment</div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Zone / Location <span>*</span></label>
        <div class="cs-wrap">
          <input type="text" class="cs-input" id="csZoneSearch" placeholder="Search zone…" autocomplete="off">
          <input type="hidden" id="fZoneSl" value="">
          <div class="cs-drop" id="csZoneDrop"></div>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Department</label>
        <select class="fs" id="fDeptSl">
          <option value="">Select…</option>
          <option>Logistics</option><option>Procurement</option><option>Operations</option>
          <option>Engineering</option><option>IT</option><option>Admin</option><option>Finance</option>
        </select>
      </div>
    </div>
    <div class="fdiv">Purchase Details</div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Purchase Date</label>
        <input type="date" class="fi" id="fPurchDate">
      </div>
      <div class="fg">
        <label class="fl">Purchase Cost (₱) <span>*</span></label>
        <input type="number" class="fi" id="fPurchCost" placeholder="0.00" min="0" step="0.01">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Current Value (₱)</label>
        <input type="number" class="fi" id="fCurrVal" placeholder="Leave blank to match purchase cost" min="0" step="0.01">
      </div>
      <div class="fg">
        <label class="fl">Condition</label>
        <select class="fs" id="fCondition">
          <option>New</option><option>Good</option><option>Fair</option><option>Poor</option>
        </select>
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Initial Status</label>
        <select class="fs" id="fStatusSl">
          <option>Active</option><option>Assigned</option><option>Under Maintenance</option>
        </select>
      </div>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-save"></i> Save Asset</button>
  </div>
</div>

<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body" id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="amSaText"></span></div>
    <div id="amDynamicInputs"></div>
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

<div id="viewModal">
  <div class="vm-box">
    <div class="vm-mhd">
      <div class="vm-mtp">
        <div class="vm-msi">
          <div class="vm-mav" id="vmAvatar"><i class="bx bx-package"></i></div>
          <div><div class="vm-mnm" id="vmName"></div><div class="vm-mid" id="vmMid"></div></div>
        </div>
        <button class="vm-mcl" id="vmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="vm-mmt" id="vmChips"></div>
      <div class="vm-mtb">
        <button class="vm-tab active" data-t="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <button class="vm-tab" data-t="au"><i class="bx bx-shield-quarter"></i> Audit Trail</button>
      </div>
    </div>
    <div class="vm-mbd">
      <div class="vm-tp active" id="vt-ov"></div>
      <div class="vm-tp"        id="vt-au"></div>
    </div>
    <div class="vm-mft" id="vmFoot"></div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── STATE ─────────────────────────────────────────────────────────────────────
let ASSETS=[], ZONES=[], STAFF=[], CATS=[];
let sortCol='assetId', sortDir='desc', page=1;
const PAGE=10;
let selectedIds=new Set();
let actionTarget=null, actionKey=null, actionCb=null;
let editId=null;

// Built dynamically from CATS after load — static fallback for known categories
let CAT_META = {
    'IT Equipment':    {icon:'bx-laptop',  color:'#2563EB'},
    'Vehicles':        {icon:'bx-car',     color:'#D97706'},
    'Heavy Machinery': {icon:'bx-cog',     color:'#DC2626'},
    'Office Furniture':{icon:'bx-chair',   color:'#0D9488'},
    'Tools & Equipment':{icon:'bx-wrench', color:'#7C3AED'},
    'Other':           {icon:'bx-package', color:'#6B7280'},
};

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        [CATS, ZONES, STAFF, ASSETS] = await Promise.all([
            apiGet(API+'?api=categories'),
            apiGet(API+'?api=zones'),
            apiGet(API+'?api=staff').catch(()=>[]),
            apiGet(API+'?api=list'),
        ]);
        // Rebuild CAT_META from live data, merging over static fallback
        CATS.forEach(c => { CAT_META[c.name] = {icon: c.icon||'bx-package', color: c.color||'#6B7280'}; });
        if (!STAFF.length) STAFF = [
            {id:'s1',name:'Mark Ocampo'},{id:'s2',name:'Pedro Reyes'},
            {id:'s3',name:'Ana Santos'},{id:'s4',name:'Rico Dela Cruz'},
            {id:'s5',name:'Luz Bautista'},
        ];
    } catch(e) { toast('Failed to load data: '+e.message,'d'); }
    renderList();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD  = d=>{ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const fM  = n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const zc  = z=>{ const zn=ZONES.find(x=>x.name===z||x.id===z); return zn?zn.color:'#6B7280'; };
function badge(s){
    const m={'Active':'b-active','Assigned':'b-assigned','Under Maintenance':'b-maintenance','Disposed':'b-disposed','Lost/Stolen':'b-lost'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered(){
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const fs = document.getElementById('fStatus').value;
    const fc = document.getElementById('fCategory').value;
    const fz = document.getElementById('fZone').value;
    return ASSETS.filter(a=>{
        if(q&&!a.assetId.toLowerCase().includes(q)&&!a.name.toLowerCase().includes(q)&&!a.serial.toLowerCase().includes(q)) return false;
        if(fs&&a.status!==fs) return false;
        if(fc&&a.category!==fc) return false;
        if(fz&&a.zone!==fz) return false;
        return true;
    });
}
function getSorted(list){
    return [...list].sort((a,b)=>{
        let va=a[sortCol], vb=b[sortCol];
        if(sortCol==='purchaseCost'||sortCol==='currentValue') return sortDir==='asc'?va-vb:vb-va;
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── BUILD DROPDOWNS ───────────────────────────────────────────────────────────
function buildDropdowns(){
    // fCategory filter — merge live CATS with any extra names already in assets
    const liveNames = new Set(CATS.map(c=>c.name));
    const allCats   = [...liveNames, ...[...new Set(ASSETS.map(a=>a.category))].filter(n=>n&&!liveNames.has(n))].sort();
    const cEl=document.getElementById('fCategory'), cv=cEl.value;
    cEl.innerHTML='<option value="">All Categories</option>'+allCats.map(c=>`<option ${c===cv?'selected':''}>${esc(c)}</option>`).join('');
    // fZone filter — live zones merged with asset zone names
    const liveZones = new Set(ZONES.map(z=>z.name));
    const allZones  = [...liveZones, ...[...new Set(ASSETS.map(a=>a.zone))].filter(z=>z&&!liveZones.has(z))].sort();
    const zEl=document.getElementById('fZone'), zv=zEl.value;
    zEl.innerHTML='<option value="">All Zones</option>'+allZones.map(z=>`<option ${z===zv?'selected':''}>${esc(z)}</option>`).join('');
}

// ── RENDER STATS ──────────────────────────────────────────────────────────────
function renderStats(){
    const tot  = ASSETS.length;
    const act  = ASSETS.filter(a=>a.status==='Active').length;
    const asg  = ASSETS.filter(a=>a.status==='Assigned').length;
    const mnt  = ASSETS.filter(a=>a.status==='Under Maintenance').length;
    const dsp  = ASSETS.filter(a=>a.status==='Disposed').length;
    const lost = ASSETS.filter(a=>a.status==='Lost/Stolen').length;
    document.getElementById('statsBar').innerHTML=`
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-package"></i></div><div><div class="sc-v">${tot}</div><div class="sc-l">Total Assets</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${act}</div><div class="sc-l">Active</div></div></div>
        <div class="sc"><div class="sc-ic ic-p"><i class="bx bx-user-check"></i></div><div><div class="sc-v">${asg}</div><div class="sc-l">Assigned</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-wrench"></i></div><div><div class="sc-v">${mnt}</div><div class="sc-l">Maintenance</div></div></div>
        <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-trash"></i></div><div><div class="sc-v">${dsp}</div><div class="sc-l">Disposed</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-error-circle"></i></div><div><div class="sc-v">${lost}</div><div class="sc-l">Lost/Stolen</div></div></div>`;
}

// ── RENDER TABLE ──────────────────────────────────────────────────────────────
function renderList(){
    renderStats(); buildDropdowns();
    const data=getSorted(getFiltered()), total=data.length;
    const pages=Math.max(1,Math.ceil(total/PAGE));
    if(page>pages) page=pages;
    const slice=data.slice((page-1)*PAGE,page*PAGE);

    document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
        const c=th.dataset.col;
        th.classList.toggle('sorted',c===sortCol);
        const ic=th.querySelector('.sic');
        if(ic) ic.className=`bx ${c===sortCol?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} sic`;
    });

    const tb=document.getElementById('tbody');
    if(!slice.length){
        tb.innerHTML=`<tr><td colspan="9"><div class="empty"><i class="bx bx-package"></i><p>No assets found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(a=>{
            const chk=selectedIds.has(a.assetId);
            const cat=CAT_META[a.category]||{icon:'bx-package',color:'#6B7280'};
            const canAct=!['Disposed','Lost/Stolen'].includes(a.status);
            return `<tr class="${chk?'row-selected':''}">
                <td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${a.assetId}" ${chk?'checked':''}></div></td>
                <td onclick="openView(${a.id})"><span class="pr-num">${esc(a.assetId)}</span></td>
                <td onclick="openView(${a.id})">
                    <div class="req-cell">
                        <div class="req-name">${esc(a.name)}</div>
                        <div class="req-sub">${esc(a.brand)}${a.serial?' · '+esc(a.serial):''}</div>
                    </div>
                </td>
                <td onclick="openView(${a.id})">
                    <div class="req-cell">
                        <span style="color:${cat.color};font-weight:600;font-size:12px">${esc(a.category)}</span>
                        <div class="req-sub">${esc(a.type)}</div>
                    </div>
                </td>
                <td onclick="openView(${a.id})">
                    <div class="req-cell">
                        <div class="req-name" style="font-size:12px">${esc(a.zone)}</div>
                        <div class="req-sub">${esc(a.dept)}</div>
                    </div>
                </td>
                <td onclick="openView(${a.id})"><span class="pr-amt">${fM(a.purchaseCost)}</span></td>
                <td onclick="openView(${a.id})">${badge(a.status)}</td>
                <td onclick="openView(${a.id})">
                    <span class="dept-dot">${a.assignee
                        ?`<i class="bx bx-user"></i>${esc(a.assignee)}`
                        :'<span style="color:#9CA3AF;font-style:italic">Unassigned</span>'}</span>
                </td>
                <td onclick="event.stopPropagation()">
                    <div class="act-cell">
                        <button class="btn ionly" onclick="openEdit(${a.id})" title="Edit"><i class="bx bx-edit"></i></button>
                        <button class="btn ionly btn-override" onclick="doAction('transfer',${a.id})" title="Transfer Zone"><i class="bx bx-transfer"></i></button>
                        ${canAct?`
                        <button class="btn ionly btn-approve" onclick="doAction('assign',${a.id})" title="Assign / Return"><i class="bx bx-user-check"></i></button>
                        <button class="btn ionly btn-warn" onclick="doAction('maintenance',${a.id})" title="Send for Maintenance"><i class="bx bx-wrench"></i></button>
                        <button class="btn ionly btn-reject" onclick="doAction('dispose',${a.id})" title="Force Dispose"><i class="bx bx-trash"></i></button>`:''}
                    </div>
                </td>
            </tr>`;
        }).join('');
        document.querySelectorAll('.row-cb').forEach(cb=>{
            cb.addEventListener('change',function(){
                const id=this.dataset.id;
                if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
                this.closest('tr').classList.toggle('row-selected',this.checked);
                updateBulkBar(); syncCheckAll(slice);
            });
        });
    }
    syncCheckAll(slice);
    const s=(page-1)*PAGE+1, e=Math.min(page*PAGE,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=page-2&&i<=page+2)) btns+=`<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if(i===page-3||i===page+3) btns+=`<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML=`
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} assets`}</span>
        <div class="pg-btns">
            <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
            ${btns}
            <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
        </div>`;
}
window.goPage=p=>{page=p;renderList();};

document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
    th.addEventListener('click',()=>{
        const c=th.dataset.col;
        sortDir=sortCol===c?(sortDir==='asc'?'desc':'asc'):'asc';
        sortCol=c; page=1; renderList();
    });
});
['srch','fStatus','fCategory','fZone'].forEach(id=>
    document.getElementById(id).addEventListener('input',()=>{page=1;renderList();})
);

// ── BULK ──────────────────────────────────────────────────────────────────────
function updateBulkBar(){
    const n=selectedIds.size;
    document.getElementById('bulkBar').classList.toggle('on',n>0);
    document.getElementById('bulkCount').textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice){
    const ca=document.getElementById('checkAll');
    const ids=slice.map(a=>a.assetId);
    const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
    const some=ids.some(id=>selectedIds.has(id));
    ca.checked=all; ca.indeterminate=!all&&some;
}
document.getElementById('checkAll').addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(a=>{if(this.checked) selectedIds.add(a.assetId); else selectedIds.delete(a.assetId);});
    renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});

document.getElementById('batchAssignBtn').addEventListener('click',()=>{
    const valid=[...selectedIds].map(assetId=>ASSETS.find(a=>a.assetId===assetId)).filter(a=>a&&!['Disposed','Lost/Stolen'].includes(a.status));
    if(!valid.length){toast('No assignable assets selected.','w');return;}
    showActionModal('👤',`Bulk Assign ${valid.length} Asset(s)`,
        `Assign <strong>${valid.length}</strong> asset(s) to a person.`,
        false,'',
        `<div class="am-fg"><label>Assignee <span style="color:var(--red)">*</span></label>
         <input type="text" id="amBatchAssignee" placeholder="e.g. John Doe" list="staffList">
         <datalist id="staffList">${STAFF.map(s=>`<option value="${esc(s.name)}">`).join('')}</datalist></div>`,
        'btn-approve','<i class="bx bx-check"></i> Assign All',
        async()=>{
            const assignee=document.getElementById('amBatchAssignee')?.value.trim();
            if(!assignee){toast('Assignee name is required.','w');return false;}
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-assign',ids:valid.map(a=>a.id),assignee,remarks:rmk});
                const updated=await apiGet(API+'?api=list'); ASSETS=updated;
                selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} asset(s) assigned to ${assignee}.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});

document.getElementById('batchDisposeBtn').addEventListener('click',()=>{
    const valid=[...selectedIds].map(assetId=>ASSETS.find(a=>a.assetId===assetId)).filter(a=>a&&a.status!=='Disposed');
    if(!valid.length){toast('No disposable assets selected.','w');return;}
    showActionModal('🗑️',`Bulk Dispose ${valid.length} Asset(s)`,
        `Force-dispose <strong>${valid.length}</strong> asset(s). This cannot be undone.`,
        true,'Super Admin force dispose bypasses standard depreciation workflow.',
        '','btn-reject','<i class="bx bx-trash"></i> Dispose All',
        async()=>{
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-dispose',ids:valid.map(a=>a.id),remarks:rmk});
                const updated=await apiGet(API+'?api=list'); ASSETS=updated;
                selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} asset(s) disposed.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
function showActionModal(icon,title,body,sa,saText,extraHtml,btnClass,btnLabel,onConfirm=null){
    document.getElementById('amIcon').textContent=icon;
    document.getElementById('amTitle').textContent=title;
    document.getElementById('amBody').innerHTML=body;
    const san=document.getElementById('amSaNote');
    if(sa){san.style.display='flex';document.getElementById('amSaText').textContent=saText;}
    else san.style.display='none';
    document.getElementById('amDynamicInputs').innerHTML=extraHtml||'';
    document.getElementById('amRemarks').value='';
    const cb=document.getElementById('amConfirm');
    cb.className=`btn btn-sm ${btnClass}`; cb.innerHTML=btnLabel;
    actionCb=onConfirm;
    document.getElementById('actionModal').classList.add('on');
}

function doAction(type, dbId){
    const a=ASSETS.find(x=>x.id===dbId); if(!a) return;
    actionTarget=dbId; actionKey=type;

    const zoneOpts=ZONES.filter(z=>z.name!==a.zone&&z.id!==a.zone).map(z=>`<option value="${esc(z.name)}">${esc(z.name)}</option>`).join('');
    const staffOpts=STAFF.map(s=>`<option value="${esc(s.name)}">`).join('');

    const cfg={
        assign:{
            icon:'👤', title:'Assign / Return Asset',
            body:`<strong>${esc(a.name)}</strong> (${esc(a.assetId)}) — current assignee: <strong>${a.assignee||'None'}</strong>.<br>Leave assignee blank to return the asset.`,
            sa:false, saText:'',
            extra:`<div class="am-fg"><label>Assignee (blank = return)</label>
                   <input type="text" id="amInputAsg" value="${esc(a.assignee)}" placeholder="e.g. John Doe" list="asgStaff">
                   <datalist id="asgStaff">${staffOpts}</datalist></div>
                   <div class="am-fg"><label>Return Due Date</label><input type="date" id="amInputRet" value="${a.returnDate||''}"></div>`,
            btn:'btn-approve', label:'<i class="bx bx-check"></i> Update Assignment',
        },
        transfer:{
            icon:'🔀', title:'Cross-zone Transfer',
            body:`Transfer <strong>${esc(a.name)}</strong> from <strong>${esc(a.zone)}</strong> to a new zone.`,
            sa:true, saText:'Super Admin exclusive action. Full audit logged.',
            extra:`<div class="am-fg"><label>Destination Zone <span style="color:var(--red)">*</span></label>
                   <select id="amInputZone">${zoneOpts||`<option value="">No other zones available</option>`}</select></div>`,
            btn:'btn-override', label:'<i class="bx bx-transfer"></i> Execute Transfer',
        },
        maintenance:{
            icon:'🔧', title:'Send for Maintenance',
            body:`Mark <strong>${esc(a.name)}</strong> (${esc(a.assetId)}) as Under Maintenance.`,
            sa:false, saText:'',
            extra:'',
            btn:'btn-warn', label:'<i class="bx bx-wrench"></i> Send for Maintenance',
        },
        restore:{
            icon:'✅', title:'Restore to Active',
            body:`Mark <strong>${esc(a.name)}</strong> (${esc(a.assetId)}) as Active after maintenance.`,
            sa:false, saText:'',
            extra:'',
            btn:'btn-approve', label:'<i class="bx bx-check-circle"></i> Restore Active',
        },
        dispose:{
            icon:'🗑️', title:'Force Dispose',
            body:`Mark <strong>${esc(a.name)}</strong> (${esc(a.assetId)}) as permanently Disposed.`,
            sa:true, saText:'Super Admin force dispose bypasses standard depreciation workflow.',
            extra:'',
            btn:'btn-reject', label:'<i class="bx bx-trash"></i> Dispose Asset',
        },
        'mark-lost':{
            icon:'🔍', title:'Mark as Lost / Stolen',
            body:`Flag <strong>${esc(a.name)}</strong> (${esc(a.assetId)}) as Lost or Stolen.`,
            sa:true, saText:'Super Admin exclusive. Full audit logged.',
            extra:'',
            btn:'btn-reject', label:'<i class="bx bx-error-circle"></i> Mark Lost/Stolen',
        },
    };
    const c=cfg[type]; if(!c) return;
    showActionModal(c.icon,c.title,c.body,c.sa,c.saText,c.extra,c.btn,c.label);
}

document.getElementById('amConfirm').addEventListener('click',async()=>{
    if(actionCb){const r=await actionCb();if(r===false)return;document.getElementById('actionModal').classList.remove('on');actionCb=null;return;}
    const a=ASSETS.find(x=>x.id===actionTarget); if(!a) return;
    const rmk=document.getElementById('amRemarks').value.trim();
    const payload={id:a.id, type:actionKey, remarks:rmk};
    if(actionKey==='assign'){
        payload.assignee  =document.getElementById('amInputAsg')?.value.trim()||'';
        payload.returnDate=document.getElementById('amInputRet')?.value||'';
    }
    if(actionKey==='transfer'){
        const z=document.getElementById('amInputZone')?.value;
        if(!z){toast('Please select a destination zone.','w');return;}
        payload.zone=z;
    }
    try{
        const updated=await apiPost(API+'?api=action',payload);
        const idx=ASSETS.findIndex(x=>x.id===updated.id); if(idx>-1) ASSETS[idx]=updated;
        const msgs={assign:'Assignment updated.',transfer:`Transferred to ${payload.zone}.`,maintenance:'Sent for maintenance.',restore:'Restored to Active.',dispose:'Asset disposed.',['mark-lost']:'Asset marked as Lost/Stolen.'};
        toast(msgs[actionKey]||'Action applied.','s');
        document.getElementById('actionModal').classList.remove('on');
        renderList();
        if(document.getElementById('viewModal').classList.contains('on')) renderDetail(updated);
    }catch(e){toast(e.message,'d');}
});
document.getElementById('amCancel').addEventListener('click',()=>{document.getElementById('actionModal').classList.remove('on');actionCb=null;});
document.getElementById('actionModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');actionCb=null;}});

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function openView(dbId){
    const a=ASSETS.find(x=>x.id===dbId); if(!a) return;
    renderDetail(a); setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
}
function closeView(){document.getElementById('viewModal').classList.remove('on');}
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this)closeView();});
document.querySelectorAll('.vm-tab').forEach(t=>t.addEventListener('click',()=>setVmTab(t.dataset.t)));
function setVmTab(name){
    document.querySelectorAll('.vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.t===name));
    document.querySelectorAll('.vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
    if(name==='au') loadAuditTrail(currentViewId);
}
let currentViewId=null;

function renderDetail(a){
    currentViewId=a.id;
    const cat=CAT_META[a.category]||{icon:'bx-package',color:'#6B7280'};
    const canAct=!['Disposed','Lost/Stolen'].includes(a.status);
    document.getElementById('vmAvatar').innerHTML=`<i class='bx ${cat.icon}'></i>`;
    document.getElementById('vmAvatar').style.background=cat.color;
    document.getElementById('vmName').innerHTML=esc(a.name);
    document.getElementById('vmMid').innerHTML=`<span style="font-family:'DM Mono',monospace">${esc(a.assetId)}</span>&nbsp;·&nbsp;${esc(a.category)}&nbsp;${badge(a.status)}`;
    document.getElementById('vmChips').innerHTML=`
        <div class="vm-mc"><i class="bx bx-calendar"></i>Purchased ${fD(a.purchaseDate)}</div>
        <div class="vm-mc"><i class="bx bx-money"></i>${fM(a.purchaseCost)}</div>
        <div class="vm-mc"><i class="bx bx-map"></i>${esc(a.zone)}</div>
        ${a.assignee?`<div class="vm-mc"><i class="bx bx-user"></i>${esc(a.assignee)}</div>`:''}`;
    document.getElementById('vmFoot').innerHTML=`
        <button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${a.id})"><i class="bx bx-edit"></i> Edit</button>
        ${canAct?`<button class="btn btn-approve btn-sm" onclick="closeView();doAction('assign',${a.id})"><i class="bx bx-user-check"></i> Assign</button>`:''}
        ${canAct?`<button class="btn btn-override btn-sm" onclick="closeView();doAction('transfer',${a.id})"><i class="bx bx-transfer"></i> Transfer</button>`:''}
        ${a.status==='Under Maintenance'?`<button class="btn btn-teal btn-sm" onclick="closeView();doAction('restore',${a.id})"><i class="bx bx-check-circle"></i> Restore</button>`:''}
        ${canAct&&a.status!=='Under Maintenance'?`<button class="btn btn-warn btn-sm" onclick="closeView();doAction('maintenance',${a.id})"><i class="bx bx-wrench"></i> Maintenance</button>`:''}
        ${!['Disposed','Lost/Stolen'].includes(a.status)?`<button class="btn btn-reject btn-sm" onclick="closeView();doAction('dispose',${a.id})"><i class="bx bx-trash"></i> Dispose</button>`:''}
        <button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;
    document.getElementById('vt-ov').innerHTML=`
        <div class="vm-sbs">
            <div class="vm-sb"><div class="sbv mono">${fM(a.purchaseCost)}</div><div class="sbl">Purchase Cost</div></div>
            <div class="vm-sb"><div class="sbv mono" style="color:#059669">${fM(a.currentValue)}</div><div class="sbl">Current Value</div></div>
            <div class="vm-sb"><div class="sbv">${esc(a.condition)}</div><div class="sbl">Condition</div></div>
            <div class="vm-sb"><div class="sbv">${a.assignee?'Assigned':'Available'}</div><div class="sbl">State</div></div>
        </div>
        <div class="vm-ig">
            <div class="vm-ii"><label>Asset Type</label><div class="v">${esc(a.type)||'—'}</div></div>
            <div class="vm-ii"><label>Brand / Model</label><div class="v">${esc(a.brand)||'—'}</div></div>
            <div class="vm-ii"><label>Serial Number</label><div class="v muted" style="font-family:'DM Mono',monospace">${esc(a.serial)||'—'}</div></div>
            <div class="vm-ii"><label>Department</label><div class="v">${esc(a.dept)||'—'}</div></div>
            <div class="vm-ii"><label>Zone / Location</label><div class="v">${esc(a.zone)}</div></div>
            <div class="vm-ii"><label>Purchase Date</label><div class="v muted">${fD(a.purchaseDate)}</div></div>
            <div class="vm-ii"><label>Assignee</label><div class="v">${a.assignee?`<i class="bx bx-user"></i> ${esc(a.assignee)}`:'<span class="muted">Unassigned</span>'}</div></div>
            <div class="vm-ii"><label>Assign Date</label><div class="v muted">${fD(a.assignDate)}</div></div>
            <div class="vm-ii"><label>Return Due</label><div class="v muted">${fD(a.returnDate)}</div></div>
        </div>`;
    document.getElementById('vt-au').innerHTML=`
        <div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Full audit trail — visible to Super Admin only.</span></div>
        <div id="auditContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading…</div></div>`;
}

async function loadAuditTrail(dbId){
    if(!dbId) return;
    const wrap=document.getElementById('auditContent'); if(!wrap) return;
    try{
        const rows=await apiGet(API+'?api=audit&id='+dbId);
        if(!rows.length){wrap.innerHTML=`<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">No audit entries yet.</div>`;return;}
        wrap.innerHTML=rows.map(lg=>`
            <div class="vm-audit-item">
                <div class="vm-audit-dot ${lg.css_class||'ad-s'}"><i class="bx ${lg.icon||'bx-info-circle'}"></i></div>
                <div class="vm-audit-body">
                    <div class="au">${esc(lg.action_label)} ${lg.is_super_admin?'<span class="sa-tag">Super Admin</span>':''}</div>
                    <div class="at"><i class="bx bx-user" style="font-size:11px"></i>${esc(lg.actor_name)} · ${esc(lg.actor_role)}
                        ${lg.ip_address?`<span class="vm-audit-ip"><i class="bx bx-desktop" style="font-size:10px;margin-right:2px"></i>${esc(lg.ip_address)}</span>`:''}
                    </div>
                    ${lg.note?`<div class="vm-audit-note">"${esc(lg.note)}"</div>`:''}
                </div>
                <div class="vm-audit-ts">${lg.occurred_at?new Date(lg.occurred_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}</div>
            </div>`).join('');
    }catch(e){wrap.innerHTML=`<div style="text-align:center;color:var(--red);padding:24px;font-size:13px">Failed to load audit trail.</div>`;}
}

// ── SLIDER ────────────────────────────────────────────────────────────────────
function wireCatSearch(selectedVal=''){
    const inp  = document.getElementById('csCatSearch');
    const hid  = document.getElementById('fCatSl');
    const drop = document.getElementById('csCatDrop');
    if(!inp) return;
    // Pre-fill
    if(selectedVal){ hid.value=selectedVal; inp.value=selectedVal; }
    if(inp.dataset.wired) return;
    inp.dataset.wired='1';
    let hl=-1;
    function render(q){
        const lq=(q||'').toLowerCase();
        // Merge live CATS with any extra names found in existing assets
        const liveNames=new Set(CATS.map(c=>c.name));
        const extraNames=[...new Set(ASSETS.map(a=>a.category))].filter(n=>n&&!liveNames.has(n));
        const all=[...CATS, ...extraNames.map(n=>({name:n,icon:'bx-package',color:'#6B7280'}))];
        const filtered=all.filter(c=>c.name.toLowerCase().includes(lq));
        if(!filtered.length){
            drop.innerHTML='<div class="cs-opt cs-none">No categories found</div>';
        } else {
            drop.innerHTML=filtered.map(c=>`
                <div class="cs-opt" data-name="${esc(c.name)}">
                    <span class="cs-name" style="display:flex;align-items:center;gap:7px">
                        <i class="bx ${c.icon}" style="font-size:15px;color:${c.color};flex-shrink:0"></i>
                        ${esc(c.name)}
                    </span>
                </div>`).join('');
            drop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
                opt.addEventListener('mousedown',e=>{
                    e.preventDefault();
                    hid.value=opt.dataset.name;
                    inp.value=opt.dataset.name;
                    drop.classList.remove('open');
                });
            });
        }
        hl=-1;
    }
    inp.addEventListener('focus', ()=>{ render(inp.value); drop.classList.add('open'); });
    inp.addEventListener('input', ()=>{ hid.value=''; render(inp.value); drop.classList.add('open'); });
    inp.addEventListener('blur',  ()=>setTimeout(()=>drop.classList.remove('open'),150));
    inp.addEventListener('keydown', e=>{
        const opts=[...drop.querySelectorAll('.cs-opt:not(.cs-none)')];
        if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,opts.length-1); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); }
        else if(e.key==='Enter'&&hl>=0){ e.preventDefault(); const o=opts[hl]; if(o){ hid.value=o.dataset.name; inp.value=o.dataset.name; drop.classList.remove('open'); } }
        else if(e.key==='Escape'){ drop.classList.remove('open'); }
        opts.forEach((o,i)=>o.classList.toggle('hl',i===hl));
        if(hl>=0&&opts[hl]) opts[hl].scrollIntoView({block:'nearest'});
    });
}

function wireZoneSearch(selectedVal=''){
    const inp  = document.getElementById('csZoneSearch');
    const hid  = document.getElementById('fZoneSl');
    const drop = document.getElementById('csZoneDrop');
    if(!inp) return;
    // Pre-fill
    if(selectedVal){ hid.value=selectedVal; inp.value=selectedVal; }
    if(inp.dataset.wired) return;
    inp.dataset.wired='1';
    let hl=-1;
    function render(q){
        const lq=(q||'').toLowerCase();
        const filtered=ZONES.filter(z=>z.name.toLowerCase().includes(lq)||z.id.toLowerCase().includes(lq));
        drop.innerHTML=filtered.length
            ?filtered.map(z=>`<div class="cs-opt" data-name="${esc(z.name)}" style="border-left:3px solid ${z.color}">
                <span class="cs-name">${esc(z.name)}</span></div>`).join('')
            :'<div class="cs-opt cs-none">No zones found</div>';
        drop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
            opt.addEventListener('mousedown',e=>{e.preventDefault();hid.value=opt.dataset.name;inp.value=opt.dataset.name;drop.classList.remove('open');});
        });
        hl=-1;
    }
    inp.addEventListener('focus',()=>{render(inp.value);drop.classList.add('open');});
    inp.addEventListener('input',()=>{hid.value='';render(inp.value);drop.classList.add('open');});
    inp.addEventListener('blur', ()=>setTimeout(()=>drop.classList.remove('open'),150));
    inp.addEventListener('keydown',e=>{
        const opts=[...drop.querySelectorAll('.cs-opt:not(.cs-none)')];
        if(e.key==='ArrowDown'){e.preventDefault();hl=Math.min(hl+1,opts.length-1);}
        else if(e.key==='ArrowUp'){e.preventDefault();hl=Math.max(hl-1,0);}
        else if(e.key==='Enter'&&hl>=0){e.preventDefault();const o=opts[hl];if(o){hid.value=o.dataset.name;inp.value=o.dataset.name;drop.classList.remove('open');}}
        else if(e.key==='Escape'){drop.classList.remove('open');}
        opts.forEach((o,i)=>o.classList.toggle('hl',i===hl));
        if(hl>=0&&opts[hl]) opts[hl].scrollIntoView({block:'nearest'});
    });
}

function openSlider(mode='create', a=null){
    editId=mode==='edit'?a.id:null;
    document.getElementById('slTitle').textContent=mode==='edit'?`Edit Asset — ${a.assetId}`:'Add New Asset';
    document.getElementById('slSub').textContent=mode==='edit'?'Update asset details below':'Fill in asset details below';
    if(mode==='edit'&&a){
        document.getElementById('fName').value=a.name;
        document.getElementById('fType').value=a.type;
        document.getElementById('fBrand').value=a.brand;
        document.getElementById('fSerial').value=a.serial;
        document.getElementById('fDeptSl').value=a.dept;
        document.getElementById('fPurchDate').value=a.purchaseDate||'';
        document.getElementById('fPurchCost').value=a.purchaseCost||'';
        document.getElementById('fCurrVal').value=a.currentValue||'';
        document.getElementById('fCondition').value=a.condition||'Good';
        document.getElementById('fStatusSl').value=['Disposed','Lost/Stolen'].includes(a.status)?'Active':a.status;
        const ci=document.getElementById('csCatSearch'); if(ci) delete ci.dataset.wired;
        wireCatSearch(a.category);
        const zi=document.getElementById('csZoneSearch'); if(zi) delete zi.dataset.wired;
        wireZoneSearch(a.zone);
    } else {
        ['fName','fType','fBrand','fSerial','fPurchDate','fPurchCost','fCurrVal'].forEach(id=>document.getElementById(id).value='');
        ['fDeptSl'].forEach(id=>document.getElementById(id).value='');
        document.getElementById('fCondition').value='New';
        document.getElementById('fStatusSl').value='Active';
        const ci=document.getElementById('csCatSearch'); if(ci){ci.value='';delete ci.dataset.wired;}
        document.getElementById('fCatSl').value='';
        wireCatSearch('');
        const zi=document.getElementById('csZoneSearch'); if(zi){zi.value='';delete zi.dataset.wired;}
        document.getElementById('fZoneSl').value='';
        wireZoneSearch('');
    }
    document.getElementById('prSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(()=>{ wireCatSearch(); wireZoneSearch(); document.getElementById('fName').focus(); },100);
}
function openEdit(dbId){ const a=ASSETS.find(x=>x.id===dbId); if(a) openSlider('edit',a); }
function closeSlider(){
    document.getElementById('prSlider').classList.remove('on');
    document.getElementById('slOverlay').classList.remove('on');
    editId=null;
}
document.getElementById('slOverlay').addEventListener('click',function(e){if(e.target===this)closeSlider();});
document.getElementById('slClose').addEventListener('click',closeSlider);
document.getElementById('slCancel').addEventListener('click',closeSlider);
document.getElementById('createBtn').addEventListener('click',()=>openSlider('create'));

document.getElementById('slSubmit').addEventListener('click',async()=>{
    const btn=document.getElementById('slSubmit'); btn.disabled=true;
    try{
        const name     =document.getElementById('fName').value.trim();
        const category =document.getElementById('fCatSl').value;
        const zone     =document.getElementById('fZoneSl').value;
        const purchaseCost=parseFloat(document.getElementById('fPurchCost').value)||0;
        if(!name)         {shk('fName');    toast('Asset name is required.','w');return;}
        if(!category)     {shk('csCatSearch');   toast('Category is required.','w');return;}
        if(!zone)         {shk('csZoneSearch'); toast('Zone is required.','w');return;}
        if(purchaseCost<=0){shk('fPurchCost'); toast('Purchase cost is required.','w');return;}
        const payload={
            name, category, zone, purchaseCost,
            type:         document.getElementById('fType').value.trim(),
            brand:        document.getElementById('fBrand').value.trim(),
            serial:       document.getElementById('fSerial').value.trim(),
            dept:         document.getElementById('fDeptSl').value,
            purchaseDate: document.getElementById('fPurchDate').value||null,
            currentValue: parseFloat(document.getElementById('fCurrVal').value)||purchaseCost,
            condition:    document.getElementById('fCondition').value,
            status:       document.getElementById('fStatusSl').value,
        };
        if(editId) payload.id=editId;
        const saved=await apiPost(API+'?api=save',payload);
        const idx=ASSETS.findIndex(x=>x.id===saved.id);
        if(idx>-1) ASSETS[idx]=saved; else{ASSETS.unshift(saved);page=1;}
        toast(`${saved.assetId} ${editId?'updated':'registered'}.`,'s');
        closeSlider(); renderList();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
});

// ── EXPORT ────────────────────────────────────────────────────────────────────
function doExport(){
    const cols=['assetId','name','category','type','brand','serial','zone','dept','purchaseDate','purchaseCost','currentValue','condition','status','assignee'];
    const hdrs=['Asset ID','Name','Category','Type','Brand','Serial','Zone','Dept','Purchase Date','Purchase Cost','Current Value','Condition','Status','Assignee'];
    const rows=[hdrs.join(','),...getFiltered().map(a=>cols.map(c=>`"${String(a[c]??'').replace(/"/g,'""')}"`).join(','))];
    const el=document.createElement('a');
    el.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    el.download='asset_registry.csv'; el.click();
    toast('Assets exported.','s');
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
function shk(id){const el=document.getElementById(id);if(!el)return;el.style.borderColor='var(--red)';el.style.animation='none';el.offsetHeight;el.style.animation='SHK .3s ease';setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);}
function toast(msg,type='s'){
    const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
    const el=document.createElement('div');
    el.className=`toast t${type}`;
    el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),320);},3500);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>