<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function sio_resolve_role(): string {
    if (!empty($_SESSION['role'])) {
        $r = (string)$_SESSION['role'];
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

$sioRoleName = sio_resolve_role();
$sioRoleRank = match($sioRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$sioUserZone = $_SESSION['zone'] ?? '';
$sioUserId   = $_SESSION['user_id'] ?? null;

// ── HELPERS ──────────────────────────────────────────────────────────────────
function sio_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function sio_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function sio_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function sio_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
    if (!$res && $code >= 400) sio_err('Supabase request failed', 500);
    $data = json_decode($res, true);
    if ($code >= 400) sio_err(is_array($data) ? ($data['message'] ?? $res) : $res, 400);
    return is_array($data) ? $data : [];
}

function sio_next_txn_id(string $type): string {
    $prefix = $type === 'in' ? 'TXN-SI' : 'TXN-SO';
    $rows = sio_sb('sws_transactions', 'GET', [
        'select' => 'txn_id',
        'type'   => 'eq.' . $type,
        'order'  => 'id.desc',
        'limit'  => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/TXN-S[IO]-(\d+)/', $rows[0]['txn_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return $prefix . '-' . sprintf('%04d', $next);
}

function sio_build(array $row): array {
    return [
        'id'          => (int)$row['id'],
        'txnId'       => $row['txn_id']       ?? '',
        'type'        => $row['type']          ?? 'in',
        'dateTime'    => $row['date_time']     ?? '',
        'itemCode'    => $row['item_code']     ?? '',
        'itemName'    => $row['item_name']     ?? '',
        'qty'         => (int)($row['qty']     ?? 0),
        'uom'         => $row['uom']           ?? 'pcs',
        'refDoc'      => $row['ref_doc']       ?? '',
        'refType'     => $row['ref_type']      ?? 'PO',
        'zone'        => $row['zone']          ?? '',
        'bin'         => $row['bin']           ?? '',
        'processedBy' => $row['processed_by']  ?? '',
        'status'      => $row['status']        ?? 'Pending',
        'notes'       => $row['notes']         ?? '',
        'discrepancy' => (bool)($row['discrepancy'] ?? false),
        'discNote'    => $row['disc_note']     ?? '',
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET zones (role-scoped) ───────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $zoneQuery = ['select' => 'id,name,color', 'order' => 'id.asc'];
            if (($sioRoleName === 'Manager' || $sioRoleName === 'Staff') && $sioUserZone) {
                $zoneQuery['id'] = 'eq.' . $sioUserZone;
            }
            $rows = sio_sb('sws_zones', 'GET', $zoneQuery);
            if (empty($rows)) {
                // Fallback seed data
                $rows = [
                    ['id'=>'ZN-A01','name'=>'Zone A — Raw Materials',      'color'=>'#2E7D32'],
                    ['id'=>'ZN-B02','name'=>'Zone B — Safety & PPE',       'color'=>'#0D9488'],
                    ['id'=>'ZN-C03','name'=>'Zone C — Fuels & Lubricants', 'color'=>'#DC2626'],
                    ['id'=>'ZN-D04','name'=>'Zone D — Office Supplies',    'color'=>'#2563EB'],
                    ['id'=>'ZN-E05','name'=>'Zone E — Electrical & IT',    'color'=>'#7C3AED'],
                    ['id'=>'ZN-F06','name'=>'Zone F — Tools & Equipment',  'color'=>'#D97706'],
                    ['id'=>'ZN-G07','name'=>'Zone G — Finished Goods',     'color'=>'#059669'],
                ];
            }
            sio_ok($rows);
        }

        // ── GET inventory items for dropdowns (role-scoped) ──────────────────
        if ($api === 'items' && $method === 'GET') {
            $invQuery = [
                'select' => 'id,code,name,uom,zone,bin,stock',
                'active' => 'eq.true',
                'order'  => 'code.asc',
            ];
            if (($sioRoleName === 'Manager' || $sioRoleName === 'Staff') && $sioUserZone) {
                $invQuery['zone'] = 'eq.' . $sioUserZone;
            }
            $rows = sio_sb('sws_inventory', 'GET', $invQuery);
            sio_ok(array_map(fn($r) => [
                'id'    => (int)$r['id'],
                'code'  => $r['code']  ?? '',
                'name'  => $r['name']  ?? '',
                'uom'   => $r['uom']   ?? 'pcs',
                'zone'  => $r['zone']  ?? '',
                'bin'   => $r['bin']   ?? '',
                'stock' => (int)($r['stock'] ?? 0),
            ], $rows));
        }

        // ── GET staff list from users ─────────────────────────────────────────
        if ($api === 'staff' && $method === 'GET') {
            $rows = sio_sb('users', 'GET', [
                'select' => 'user_id,first_name,last_name',
                'status' => 'eq.Active',
                'order'  => 'first_name.asc',
            ]);
            $staff = array_map(fn($r) => [
                'id'   => $r['user_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            ], $rows);
            // Filter out blank names
            $staff = array_values(array_filter($staff, fn($s) => $s['name'] !== ''));
            sio_ok($staff);
        }

        // ── GET transactions list (role-scoped) ───────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $txQuery = [
                'select' => 'id,txn_id,type,date_time,item_code,item_name,qty,uom,ref_doc,ref_type,zone,bin,processed_by,status,notes,discrepancy,disc_note,created_user_id',
                'order'  => 'date_time.desc,id.desc',
            ];
            if ($sioRoleName === 'Manager' && $sioUserZone) {
                $txQuery['zone'] = 'eq.' . $sioUserZone;
            } elseif ($sioRoleName === 'Staff' && $sioUserId) {
                $txQuery['created_user_id'] = 'eq.' . $sioUserId;
            }
            $rows = sio_sb('sws_transactions', 'GET', $txQuery);
            sio_ok(array_map('sio_build', $rows));
        }

        // ── GET single transaction (role-scoped) ──────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) sio_err('Missing id', 400);
            $rows = sio_sb('sws_transactions', 'GET', [
                'select' => 'id,txn_id,type,date_time,item_code,item_name,qty,uom,ref_doc,ref_type,zone,bin,processed_by,status,notes,discrepancy,disc_note,created_user_id',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sio_err('Transaction not found', 404);
            $txn = $rows[0];
            if ($sioRoleName === 'Manager' && $sioUserZone && ($txn['zone'] ?? '') !== $sioUserZone) {
                sio_err('Not authorized to access this transaction', 403);
            }
            if ($sioRoleName === 'Staff' && $sioUserId && ($txn['created_user_id'] ?? null) !== $sioUserId) {
                sio_err('Not authorized to access this transaction', 403);
            }
            sio_ok(sio_build($txn));
        }

        // ── GET next txn ID ────────────────────────────────────────────────────
        if ($api === 'next_id' && $method === 'GET') {
            $type = trim($_GET['type'] ?? 'in');
            sio_ok(['txnId' => sio_next_txn_id($type)]);
        }

        // ── GET next reference doc number by type ─────────────────────────────
        if ($api === 'next_ref' && $method === 'GET') {
            $refType = strtoupper(trim($_GET['refType'] ?? 'PO'));
            $year    = date('Y');
            // Find latest ref_doc matching this type and year
            $rows = sio_sb('sws_transactions', 'GET', [
                'select'   => 'ref_doc',
                'ref_type' => 'eq.' . $refType,
                'ref_doc'  => 'like.' . $refType . '-' . $year . '-%',
                'order'    => 'id.desc',
                'limit'    => '1',
            ]);
            $next = 1;
            if (!empty($rows)) {
                $last = $rows[0]['ref_doc'] ?? '';
                if (preg_match('/(\d+)$/', $last, $m)) {
                    $next = ((int)$m[1]) + 1;
                }
            }
            sio_ok(['refDoc' => $refType . '-' . $year . '-' . sprintf('%04d', $next)]);
        }

        // ── POST save transaction (create / edit, role-aware) ─────────────────
        if ($api === 'save' && $method === 'POST') {
            $b          = sio_body();
            $type       = trim($b['type']        ?? 'in');
            $dateTime   = trim($b['dateTime']     ?? '');
            $itemCode   = trim($b['itemCode']     ?? '');
            $qty        = (int)($b['qty']         ?? 0);
            $refDoc     = trim($b['refDoc']       ?? '');
            $refType    = trim($b['refType']      ?? 'PO');
            $zone       = trim($b['zone']         ?? '');
            $bin        = trim($b['bin']          ?? '');
            $staff      = trim($b['processedBy']  ?? '');
            $status     = trim($b['status']       ?? 'Pending');
            $notes      = trim($b['notes']        ?? '');
            $disc       = (bool)($b['discrepancy'] ?? false);
            $discNote   = trim($b['discNote']     ?? '');
            $editId     = (int)($b['id']          ?? 0);

            if (!$dateTime) sio_err('Date/time is required', 400);
            if (!$itemCode) sio_err('Item is required', 400);
            if ($qty < 1)   sio_err('Quantity must be at least 1', 400);
            if (!$staff)    sio_err('Processed by is required', 400);

            $allowedType   = ['in','out'];
            $allowedStatus = ['Pending','Processing','Completed','Discrepancy','Cancelled','Voided'];
            $allowedRef    = ['PO','PR','TO','RR','DR','WO'];
            if (!in_array($type, $allowedType, true))       $type   = 'in';
            if (!in_array($status, $allowedStatus, true))   $status = 'Pending';
            if (!in_array($refType, $allowedRef, true))     $refType = 'PO';

            // Enforce zone for Manager/Staff
            if (($sioRoleName === 'Manager' || $sioRoleName === 'Staff') && $sioUserZone) {
                $zone = $sioUserZone;
            }

            // Fetch item details
            $invRows  = sio_sb('sws_inventory', 'GET', ['select' => 'id,name,uom', 'code' => 'eq.' . $itemCode, 'limit' => '1']);
            $itemId   = !empty($invRows) ? (int)$invRows[0]['id'] : null;
            $itemName = !empty($invRows) ? $invRows[0]['name']    : $itemCode;
            $uom      = !empty($invRows) ? $invRows[0]['uom']     : 'pcs';

            $now = date('Y-m-d H:i:s');
            $payload = [
                'type'          => $type,
                'date_time'     => $dateTime,
                'item_id'       => $itemId,
                'item_code'     => $itemCode,
                'item_name'     => $itemName,
                'qty'           => $qty,
                'uom'           => $uom,
                'ref_doc'       => $refDoc,
                'ref_type'      => $refType,
                'zone'          => $zone ?: null,
                'bin'           => $bin,
                'processed_by'  => $staff,
                'status'        => $status,
                'notes'         => $notes,
                'discrepancy'   => $disc,
                'disc_note'     => $discNote,
                'updated_at'    => $now,
            ];

            if ($editId) {
                // Edit — only allowed when Pending
                $existing = sio_sb('sws_transactions', 'GET', ['select' => 'id,status,txn_id,zone,created_user_id', 'id' => 'eq.' . $editId, 'limit' => '1']);
                if (empty($existing)) sio_err('Transaction not found', 404);
                if ($existing[0]['status'] !== 'Pending') sio_err('Only Pending transactions can be edited', 400);
                // Manager: only their zone; User/Staff: only own records
                if ($sioRoleName === 'Manager' && $sioUserZone && ($existing[0]['zone'] ?? '') !== $sioUserZone) {
                    sio_err('Not authorized to edit this transaction', 403);
                }
                if ($sioRoleName === 'Staff' && $sioUserId && ($existing[0]['created_user_id'] ?? null) !== $sioUserId) {
                    sio_err('Not authorized to edit this transaction', 403);
                }
                sio_sb('sws_transactions', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                sio_sb('sws_txn_audit', 'POST', [], [[
                    'txn_id'    => $existing[0]['txn_id'],
                    'action'    => 'updated',
                    'detail'    => 'Transaction edited',
                    'actor_name'=> $actor, 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);
                $rows = sio_sb('sws_transactions', 'GET', [
                    'select' => 'id,txn_id,type,date_time,item_code,item_name,qty,uom,ref_doc,ref_type,zone,bin,processed_by,status,notes,discrepancy,disc_note',
                    'id' => 'eq.' . $editId, 'limit' => '1',
                ]);
                sio_ok(sio_build($rows[0]));
            }

            // Create
            $txnId = sio_next_txn_id($type);
            $payload['txn_id']       = $txnId;
            $payload['created_by']   = $actor;
            $payload['created_user_id'] = $_SESSION['user_id'] ?? null;
            $payload['created_at']   = $now;

            $inserted = sio_sb('sws_transactions', 'POST', [], [$payload]);
            if (empty($inserted)) sio_err('Failed to create transaction', 500);
            $newId = (int)$inserted[0]['id'];

            // Update inventory stock if Completed
            if ($status === 'Completed' && $itemId) {
                $invNow = sio_sb('sws_inventory', 'GET', ['select' => 'stock', 'id' => 'eq.' . $itemId, 'limit' => '1']);
                $oldStock = !empty($invNow) ? (int)$invNow[0]['stock'] : 0;
                $newStock = $type === 'in' ? $oldStock + $qty : max(0, $oldStock - $qty);
                sio_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $itemId], ['stock' => $newStock, 'updated_at' => $now]);
            }

            sio_sb('sws_txn_audit', 'POST', [], [[
                'txn_id'    => $txnId,
                'action'    => 'created',
                'detail'    => ucfirst($type) . ' transaction created for ' . $itemCode . ' qty=' . $qty,
                'actor_name'=> $actor, 'ip_address' => $ip, 'occurred_at' => $now,
            ]]);

            $rows = sio_sb('sws_transactions', 'GET', [
                'select' => 'id,txn_id,type,date_time,item_code,item_name,qty,uom,ref_doc,ref_type,zone,bin,processed_by,status,notes,discrepancy,disc_note',
                'id' => 'eq.' . $newId, 'limit' => '1',
            ]);
            sio_ok(sio_build($rows[0]));
        }

        // ── POST action (cancel / void / override) ─────────────────────────────
        if ($api === 'action' && $method === 'POST') {
            $b      = sio_body();
            $id     = (int)($b['id']   ?? 0);
            $type   = trim($b['type']  ?? '');
            $now    = date('Y-m-d H:i:s');

            if (!$id)   sio_err('Missing id', 400);
            if (!$type) sio_err('Missing type', 400);

            $rows = sio_sb('sws_transactions', 'GET', [
                'select' => 'id,txn_id,type,status,item_id,qty,zone,created_user_id',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sio_err('Transaction not found', 404);
            $txn = $rows[0];

            // Role & ownership checks
            if ($sioRoleName === 'Manager' && $sioUserZone && ($txn['zone'] ?? '') !== $sioUserZone) {
                sio_err('Not authorized to manage this transaction', 403);
            }
            if ($sioRoleName === 'Staff' && $sioUserId && ($txn['created_user_id'] ?? null) !== $sioUserId) {
                sio_err('Not authorized to manage this transaction', 403);
            }

            $patch = ['updated_at' => $now];
            $auditDetail = '';

            switch ($type) {
                case 'cancel':
                    if (!in_array($txn['status'], ['Pending','Processing'], true))
                        sio_err('Only Pending or Processing transactions can be cancelled', 400);
                    $patch['status'] = 'Cancelled';
                    $auditDetail = 'Transaction cancelled';
                    break;

                case 'void':
                    if ($sioRoleRank < 3) {
                        sio_err('Only Admin or Super Admin may void transactions', 403);
                    }
                    if ($txn['status'] !== 'Completed')
                        sio_err('Only Completed transactions can be voided', 400);
                    $patch['status'] = 'Voided';
                    $auditDetail = 'Transaction voided';
                    // Reverse stock
                    if ($txn['item_id']) {
                        $invNow  = sio_sb('sws_inventory', 'GET', ['select' => 'stock', 'id' => 'eq.' . $txn['item_id'], 'limit' => '1']);
                        $oldSt   = !empty($invNow) ? (int)$invNow[0]['stock'] : 0;
                        $newSt   = $txn['type'] === 'in' ? max(0, $oldSt - (int)$txn['qty']) : $oldSt + (int)$txn['qty'];
                        sio_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $txn['item_id']], ['stock' => $newSt, 'updated_at' => $now]);
                    }
                    break;

                case 'override':
                    if ($sioRoleRank < 4) {
                        sio_err('Only Super Admin may override transactions', 403);
                    }
                    $reason   = trim($b['reason']  ?? '');
                    $newStatus= trim($b['status']   ?? $txn['status']);
                    $newQty   = (int)($b['qty']     ?? $txn['qty']);
                    $discFlag = (int)($b['discrepancy'] ?? 0);
                    if (!$reason) sio_err('Override reason is required', 400);

                    $patch['status'] = $newStatus;
                    $patch['qty']    = max(1, $newQty);
                    $patch['discrepancy'] = $discFlag === 1;
                    $patch['notes']  = '[OVERRIDE] ' . $reason;
                    $auditDetail = 'Override: ' . $reason;
                    break;

                default:
                    sio_err('Unsupported action', 400);
            }

            sio_sb('sws_transactions', 'PATCH', ['id' => 'eq.' . $id], $patch);
            sio_sb('sws_txn_audit', 'POST', [], [[
                'txn_id'    => $txn['txn_id'],
                'action'    => $type,
                'detail'    => $auditDetail,
                'actor_name'=> $actor, 'ip_address' => $ip, 'occurred_at' => $now,
            ]]);

            $rows = sio_sb('sws_transactions', 'GET', [
                'select' => 'id,txn_id,type,date_time,item_code,item_name,qty,uom,ref_doc,ref_type,zone,bin,processed_by,status,notes,discrepancy,disc_note',
                'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            sio_ok(sio_build($rows[0]));
        }

        sio_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        sio_err('Server error: ' . $e->getMessage(), 500);
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
<title>Stock In / Stock Out — SWS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{--bg:#F3F6F2;--s:#FFFFFF;--t1:#1A2B1C;--t2:#5D7263;--t3:#9EB5A4;--bd:rgba(46,125,50,.12);--bdm:rgba(46,125,50,.22);--grn:#2E7D32;--gdk:#1B5E20;--glt:#4CAF50;--gxl:#E8F5E9;--amb:#D97706;--red:#DC2626;--blu:#2563EB;--teal:#0D9488;--shsm:0 1px 4px rgba(46,125,50,.08);--shmd:0 4px 20px rgba(46,125,50,.11);--shlg:0 12px 40px rgba(0,0,0,.14);--rad:14px;--tr:all .18s ease;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;}
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem}
.ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px;animation:UP .4s both}
.ph-l .ey{font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--grn);margin-bottom:5px}
.ph-l h1{font-size:28px;font-weight:800;color:var(--t1);line-height:1.15;letter-spacing:-.3px}
.ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn i{font-size:16px}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 10px rgba(46,125,50,.28)}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px)}
.btn-secondary{background:var(--blu);color:#fff;box-shadow:0 2px 10px rgba(37,99,235,.24)}.btn-secondary:hover{background:#1d4ed8;transform:translateY(-1px)}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}.btn-ghost:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn-sm{font-size:12px;padding:6px 13px}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2)}
.btn.ionly:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn-danger.ionly:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.btn-warn.ionly:hover{background:#FEF3C7;color:var(--amb);border-color:#FDE68A}
.btn-blue.ionly:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE}
.btn-teal.ionly:hover{background:#CCFBF1;color:var(--teal);border-color:#99F6E4}
.btn:disabled{opacity:.4;pointer-events:none}
.sum-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;animation:UP .4s .06s both}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:16px 18px;box-shadow:var(--shsm);display:flex;align-items:center;gap:12px;transition:var(--tr)}
.sc:hover{box-shadow:var(--shmd);transform:translateY(-2px)}
.sc-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:19px}
.ic-g{background:#E8F5E9;color:#2E7D32}.ic-a{background:#FEF3C7;color:#D97706}.ic-r{background:#FEF2F2;color:#DC2626}.ic-b{background:#EFF6FF;color:#2563EB}.ic-t{background:#CCFBF1;color:#0D9488}
.sc-info{flex:1;min-width:0}.sc-v{font-size:24px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}.sc-l{font-size:11.5px;color:var(--t2);margin-top:3px;font-weight:500}.sc-sub{font-size:11px;color:var(--t3);margin-top:2px}
.pg-tabs{display:flex;gap:4px;margin-bottom:14px;background:var(--s);border:1px solid var(--bd);border-radius:12px;padding:4px;width:fit-content;box-shadow:var(--shsm);animation:UP .4s .09s both}
.pg-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 22px;border-radius:9px;border:none;cursor:pointer;transition:var(--tr);color:var(--t2);background:transparent;display:flex;align-items:center;gap:7px}
.pg-tab i{font-size:16px}.pg-tab:hover{background:var(--gxl);color:var(--grn)}.pg-tab.active{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.28)}
.pg-tab.active-out{background:var(--blu);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.24)}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;animation:UP .4s .1s both}
.sw{position:relative;flex:1;min-width:220px}
.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}.si::placeholder{color:var(--t3)}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.fi-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.fi-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.date-wrap{display:flex;align-items:center;gap:6px}.date-wrap span{font-size:12px;color:var(--t3);font-weight:500}
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:#F0FDF4;border:1px solid rgba(46,125,50,.2);border-radius:12px;margin-bottom:12px;flex-wrap:wrap}
.bulk-bar.on{display:flex}.bulk-ct{font-size:13px;font-weight:700;color:#166534}.bulk-sep{width:1px;height:20px;background:rgba(46,125,50,.2)}
.tbl-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both}
.inv-tbl{width:100%;border-collapse:collapse;font-size:12px}
.inv-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--t2);padding:8px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none}
.inv-tbl thead th.ns{cursor:default}.inv-tbl thead th:hover:not(.ns){color:var(--grn)}.inv-tbl thead th.sorted{color:var(--grn)}
.inv-tbl thead th .si-c{margin-left:2px;opacity:.4;font-size:10px;vertical-align:middle}.inv-tbl thead th.sorted .si-c{opacity:1}
.inv-tbl thead th:first-child{width:34px;padding-left:12px;padding-right:4px}
.inv-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s}
.inv-tbl tbody tr:last-child{border-bottom:none}.inv-tbl tbody tr:hover{background:#F7FBF7}.inv-tbl tbody tr.row-sel{background:#F0FDF4}
.inv-tbl tbody td{padding:10px 10px;vertical-align:middle;white-space:nowrap}
.inv-tbl tbody td:first-child{cursor:default;padding-left:12px;padding-right:4px;width:34px}
.inv-tbl tbody td:last-child{white-space:nowrap;cursor:default;padding:6px 8px}
.cb-wrap{display:flex;align-items:center;justify-content:center}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer}
.mono{font-family:'DM Mono',monospace}.code-cell{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--grn)}
.txn-cell{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--t1);letter-spacing:.02em}
.name-cell{font-weight:600;color:var(--t1);font-size:12px}.sub-cell{font-size:11px;color:var(--t3);margin-top:1px}
.num-cell{font-family:'DM Mono',monospace;font-size:12px;font-weight:700}.date-cell{font-size:11.5px;color:var(--t2)}
.time-cell{font-size:10.5px;color:var(--t3);margin-top:1px}
.ref-cell{font-family:'DM Mono',monospace;font-size:11px;color:var(--blu);font-weight:600}
.zone-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600}.zone-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.act-cell{display:flex;gap:3px;align-items:center}
.qty-in{color:#166534;font-weight:800}.qty-out{color:#991B1B;font-weight:800}
.type-in-badge{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:6px;background:#DCFCE7;color:#166534}
.type-out-badge{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:6px;background:#FEE2E2;color:#991B1B}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.b-completed{background:#DCFCE7;color:#166534}.b-pending{background:#FEF3C7;color:#92400E}.b-processing{background:#EFF6FF;color:#1D4ED8}
.b-cancelled{background:#F3F4F6;color:#6B7280}.b-voided{background:#FCE7F3;color:#9D174D}.b-discrepancy{background:#FEE2E2;color:#991B1B}
.disc-flag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:var(--red);background:#FEE2E2;border-radius:6px;padding:2px 7px}
.disc-flag i{font-size:11px}
.pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2)}
.pg-btns{display:flex;gap:5px}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1)}
.pgb:hover{background:var(--gxl);border-color:var(--grn);color:var(--grn)}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff}.pgb:disabled{opacity:.4;pointer-events:none}
.empty{padding:64px 20px;text-align:center;color:var(--t3)}.empty i{font-size:48px;display:block;margin-bottom:12px;color:#C8E6C9}
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s}
#slOverlay.on{opacity:1;pointer-events:all}
#mainSlider{position:fixed;top:0;right:-640px;bottom:0;width:600px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#mainSlider.on{right:0}
.sl-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);flex-shrink:0}
.sl-hd.in-mode{background:linear-gradient(135deg,#F0FAF0,#E8F5E9)}.sl-hd.out-mode{background:linear-gradient(135deg,#FFF8F8,#FEE2E2)}.sl-hd.view-mode{background:var(--bg)}
.sl-title{font-size:17px;font-weight:700;color:var(--t1)}.sl-sub{font-size:12px;color:var(--t2);margin-top:2px}
.sl-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0}
.sl-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.sl-bd{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px}
.sl-bd::-webkit-scrollbar{width:4px}.sl-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.sl-ft{padding:16px 24px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.fg{display:flex;flex-direction:column;gap:5px}.fg2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.fl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2)}.fl span{color:var(--red);margin-left:2px}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.fi:disabled,.fs:disabled{background:var(--bg);color:var(--t3);cursor:not-allowed}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px}
.fta{resize:vertical;min-height:68px}
.fdiv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px}
.fdiv::after{content:'';flex:1;height:1px;background:var(--bd)}
.fhint{font-size:11.5px;color:var(--t3);margin-top:3px}
/* Custom searchable select */
.cs-wrap{position:relative;width:100%}
.cs-input{width:100%;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.cs-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.cs-input::placeholder{color:var(--t3)}
.cs-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--s);border:1px solid var(--bdm);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.13);z-index:9999;max-height:220px;overflow-y:auto}
.cs-drop.open{display:block}
.cs-drop::-webkit-scrollbar{width:4px}.cs-drop::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.cs-opt{padding:9px 12px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:background .12s}
.cs-opt:hover,.cs-opt.hl{background:var(--gxl)}
.cs-opt .cs-code{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--grn)}
.cs-opt .cs-name{font-size:13px;color:var(--t1);font-weight:500}
.cs-opt .cs-stock{font-size:11px;color:var(--t3)}
.cs-opt.cs-none{color:var(--t3);cursor:default;font-size:12px;padding:12px}.cs-opt.cs-none:hover{background:none}
/* View panel */
.vp-section{background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:18px 20px}
.vp-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);margin-bottom:12px}
.vp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.vp-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.vp-item label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);display:block;margin-bottom:3px}
.vp-item .v{font-size:13px;font-weight:500;color:var(--t1)}.vp-item .vm{font-size:13px;color:var(--t2)}.vp-full{grid-column:1/-1}
.vp-statbox{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.vp-sb{background:var(--s);border:1px solid var(--bd);border-radius:10px;padding:14px;text-align:center}
.vp-sb .sbv{font-size:20px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}
.vp-sb .sbl{font-size:11px;color:var(--t2);margin-top:4px}
.disc-banner{background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.disc-banner i{font-size:20px;color:var(--red);flex-shrink:0}
.disc-banner-txt{font-size:12.5px;color:#991B1B;font-weight:600}
.override-banner{background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px;font-size:12.5px;color:#92400E;font-weight:600}
.override-banner i{font-size:18px;color:var(--amb);flex-shrink:0}
#confirmModal{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
#confirmModal.on{opacity:1;pointer-events:all}
.cm-box{background:var(--s);border-radius:14px;padding:26px 26px 22px;width:420px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.22)}
.cm-icon{font-size:44px;margin-bottom:8px;line-height:1}.cm-title{font-size:17px;font-weight:700;color:var(--t1);margin-bottom:6px}
.cm-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px}.cm-acts{display:flex;gap:10px;justify-content:flex-end}
#toastWrap{position:fixed;bottom:26px;right:26px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:210px;animation:TIN .3s ease}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}.toast.out{animation:TOUT .3s ease forwards}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@media(max-width:900px){.sum-grid{grid-template-columns:repeat(2,1fr)}.fg2,.fg3{grid-template-columns:1fr}.vp-grid,.vp-grid-3{grid-template-columns:1fr}}
@media(max-width:600px){.wrap{padding:0 0 2rem}.sum-grid{grid-template-columns:1fr 1fr}#mainSlider{width:100vw}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="wrap">

  <div class="ph">
    <div class="ph-l">
      <p class="ey">SWS · Smart Warehousing System</p>
      <h1>Stock In / Stock Out</h1>
    </div>
    <div class="ph-r">
      <button class="btn btn-ghost" onclick="doExport()"><i class="bx bx-export"></i> Export</button>
      <button class="btn btn-ghost" onclick="window.print()"><i class="bx bx-printer"></i> Print</button>
      <button class="btn btn-primary" onclick="openSlider('in',null)"><i class="bx bx-log-in-circle"></i> Stock In</button>
      <button class="btn btn-secondary" onclick="openSlider('out',null)"><i class="bx bx-log-out-circle"></i> Stock Out</button>
    </div>
  </div>

  <div class="sum-grid" id="sumGrid"></div>

  <div class="pg-tabs">
    <button class="pg-tab active" id="tabAll" onclick="switchTab('all')"><i class="bx bx-list-ul"></i> All Transactions</button>
    <button class="pg-tab" id="tabIn"  onclick="switchTab('in')"><i class="bx bx-log-in-circle"></i> Stock In</button>
    <button class="pg-tab" id="tabOut" onclick="switchTab('out')"><i class="bx bx-log-out-circle"></i> Stock Out</button>
  </div>

  <div class="toolbar">
    <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search by transaction ID, item code, or reference…"></div>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <select class="sel" id="fItem"><option value="">All Items</option></select>
    <select class="sel" id="fStat">
      <option value="">All Statuses</option>
      <option>Completed</option><option>Pending</option><option>Processing</option>
      <option>Cancelled</option><option>Voided</option><option>Discrepancy</option>
    </select>
    <div class="date-wrap">
      <input type="date" class="fi-date" id="fFrom">
      <span>–</span>
      <input type="date" class="fi-date" id="fTo">
    </div>
  </div>

  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-ct" id="bulkCt">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-ghost btn-sm" id="bExport"><i class="bx bx-export"></i> Export</button>
    <button class="btn btn-ghost btn-sm" id="bPrint"><i class="bx bx-printer"></i> Print</button>
    <button class="btn btn-ghost btn-sm" id="bCancelSel"><i class="bx bx-x-circle"></i> Cancel Selected</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x"></i> Clear</button>
  </div>

  <div class="tbl-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;"><div style="min-width:1150px;">
    <table class="inv-tbl" id="tbl">
      <thead><tr>
        <th class="ns"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
        <th data-col="txnId">Transaction ID <i class="bx bx-sort si-c"></i></th>
        <th data-col="dateTime">Date & Time <i class="bx bx-sort si-c"></i></th>
        <th data-col="type">Type <i class="bx bx-sort si-c"></i></th>
        <th data-col="itemCode">Item <i class="bx bx-sort si-c"></i></th>
        <th data-col="qty" style="text-align:right">Qty <i class="bx bx-sort si-c"></i></th>
        <th data-col="refDoc">Reference Doc <i class="bx bx-sort si-c"></i></th>
        <th data-col="zone">Zone / Bin <i class="bx bx-sort si-c"></i></th>
        <th data-col="processedBy">Processed By <i class="bx bx-sort si-c"></i></th>
        <th data-col="status">Status <i class="bx bx-sort si-c"></i></th>
        <th class="ns">Actions</th>
      </tr></thead>
      <tbody id="tbody"></tbody>
    </table>
    </div></div>
    <div class="pager" id="pager"></div>
  </div>

</div>
</main>

<div id="toastWrap"></div>
<div id="slOverlay"></div>

<div id="mainSlider">
  <div class="sl-hd in-mode" id="slHd">
    <div><div class="sl-title" id="slTitle">Create Stock In</div><div class="sl-sub" id="slSub">Record incoming inventory transaction</div></div>
    <button class="sl-cl" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-bd" id="slBody"></div>
  <div class="sl-ft" id="slFoot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-send"></i> Submit</button>
  </div>
</div>

<div id="confirmModal">
  <div class="cm-box">
    <div class="cm-icon" id="cmIcon">⚠️</div>
    <div class="cm-title" id="cmTitle">Confirm</div>
    <div class="cm-body"  id="cmBody"></div>
    <div class="cm-acts">
      <button class="btn btn-ghost btn-sm" id="cmCancel">Cancel</button>
      <button class="btn btn-sm" id="cmConfirm">Confirm</button>
    </div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
const CURRENT_USER = '<?= htmlspecialchars($_SESSION["full_name"] ?? ($_SESSION["user_id"] ?? ""), ENT_QUOTES) ?>';
const ROLE        = '<?= addslashes($sioRoleName) ?>';
const USER_ZONE   = '<?= addslashes((string)$sioUserZone) ?>';

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
let TXN=[], ZONES=[], ITEMS=[], STAFF=[];
let activeTab='all', sortCol='dateTime', sortDir='desc', page=1;
const PAGE=10;
let selectedIds=new Set();
let sliderMode=null, sliderTargetId=null, confirmCb=null;

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        [ZONES, ITEMS, STAFF, TXN] = await Promise.all([
            apiGet(API+'?api=zones'),
            apiGet(API+'?api=items'),
            apiGet(API+'?api=staff').catch(()=>[]),
            apiGet(API+'?api=list'),
        ]);
    } catch(e) { toast('Failed to load data: '+e.message,'d'); }
    // Fallback staff list if DB returns nothing
    if(!STAFF.length) STAFF=[
        {id:'s1',name:'J. Santos'},{id:'s2',name:'R. Dela Cruz'},
        {id:'s3',name:'A. Reyes'},{id:'s4',name:'P. Bautista'},
        {id:'s5',name:'L. Villanueva'},{id:'s6',name:'F. Garcia'},
        {id:'s7',name:'M. Cruz'},{id:'s8',name:'T. Hernandez'},
    ];
    switchTab('all');
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fDT = d => {
    if(!d) return {date:'—',time:''};
    const dt=new Date(d);
    return {date:dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}),time:dt.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'})};
};
const zn   = id => ZONES.find(z=>z.id===id)||{name:id||'—',color:'#6B7280'};
const item = code => ITEMS.find(i=>i.code===code)||{code,name:code,uom:'',zone:'',bin:'',stock:0};
const getMTD = type => {
    const now=new Date(); const m=now.getMonth(); const y=now.getFullYear();
    return TXN.filter(t=>{const d=new Date(t.dateTime);return t.type===type&&d.getMonth()===m&&d.getFullYear()===y&&!['Voided','Cancelled'].includes(t.status);}).reduce((a,t)=>a+t.qty,0);
};
function badge(st) {
    const m={Completed:'b-completed',Pending:'b-pending',Processing:'b-processing',Cancelled:'b-cancelled',Voided:'b-voided',Discrepancy:'b-discrepancy'};
    return `<span class="badge ${m[st]||''}">${st}</span>`;
}

// ── RENDER STATS ──────────────────────────────────────────────────────────────
function renderStats() {
    const totalIn=getMTD('in'), totalOut=getMTD('out');
    const pending=TXN.filter(t=>t.status==='Pending').length;
    const disc=TXN.filter(t=>t.discrepancy).length;
    document.getElementById('sumGrid').innerHTML=`
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-log-in-circle"></i></div><div class="sc-info"><div class="sc-v">${totalIn.toLocaleString()}</div><div class="sc-l">Total Stock In (MTD)</div><div class="sc-sub">units received this month</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-log-out-circle"></i></div><div class="sc-info"><div class="sc-v">${totalOut.toLocaleString()}</div><div class="sc-l">Total Stock Out (MTD)</div><div class="sc-sub">units released this month</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-time-five"></i></div><div class="sc-info"><div class="sc-v">${pending}</div><div class="sc-l">Pending Transactions</div><div class="sc-sub">awaiting processing</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-error-alt"></i></div><div class="sc-info"><div class="sc-v">${disc}</div><div class="sc-l">Discrepancy Alerts</div><div class="sc-sub">require investigation</div></div></div>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered() {
    const q=document.getElementById('srch').value.trim().toLowerCase();
    const fz=document.getElementById('fZone').value;
    const fi=document.getElementById('fItem').value;
    const fs=document.getElementById('fStat').value;
    const ff=document.getElementById('fFrom').value;
    const ft=document.getElementById('fTo').value;
    return TXN.filter(t=>{
        if(activeTab==='in'&&t.type!=='in') return false;
        if(activeTab==='out'&&t.type!=='out') return false;
        if(q&&!t.txnId.toLowerCase().includes(q)&&!t.itemCode.toLowerCase().includes(q)&&!t.itemName.toLowerCase().includes(q)&&!t.refDoc.toLowerCase().includes(q)) return false;
        if(fz&&t.zone!==fz) return false;
        if(fi&&t.itemCode!==fi) return false;
        if(fs&&t.status!==fs) return false;
        if(ff&&t.dateTime.slice(0,10)<ff) return false;
        if(ft&&t.dateTime.slice(0,10)>ft) return false;
        return true;
    });
}
function getSorted(list) {
    return [...list].sort((a,b)=>{
        let va=a[sortCol]||'', vb=b[sortCol]||'';
        if(sortCol==='qty') return sortDir==='asc'?va-vb:vb-va;
        va=String(va).toLowerCase(); vb=String(vb).toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── BUILD DROPDOWNS ───────────────────────────────────────────────────────────
function buildDropdowns() {
    const zones=[...new Set(TXN.map(t=>t.zone))].sort();
    const fz=document.getElementById('fZone'); const fzv=fz.value;
    fz.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option value="${z}" ${z===fzv?'selected':''}>${zn(z).name}</option>`).join('');
    const items=[...new Set(TXN.map(t=>t.itemCode))].sort();
    const fi=document.getElementById('fItem'); const fiv=fi.value;
    fi.innerHTML='<option value="">All Items</option>'+items.map(c=>{const it=item(c);return `<option value="${c}" ${c===fiv?'selected':''}>${c} — ${it.name}</option>`;}).join('');
}

// ── RENDER TABLE ──────────────────────────────────────────────────────────────
function renderList() {
    renderStats(); buildDropdowns();
    const data=getSorted(getFiltered()), total=data.length;
    const pages=Math.max(1,Math.ceil(total/PAGE));
    if(page>pages) page=pages;
    const slice=data.slice((page-1)*PAGE,page*PAGE);

    document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
        const c=th.dataset.col;
        th.classList.toggle('sorted',c===sortCol);
        const ic=th.querySelector('.si-c');
        if(ic) ic.className=`bx ${c===sortCol?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} si-c`;
    });

    const tb=document.getElementById('tbody');
    if(!slice.length){
        tb.innerHTML=`<tr><td colspan="11"><div class="empty"><i class="bx bx-transfer"></i><p>No transactions found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(t=>{
            const chk=selectedIds.has(t.txnId);
            const z=zn(t.zone), dt=fDT(t.dateTime);
            const isOwner  = !!t.createdUserId && t.createdUserId === '<?= addslashes((string)$sioUserId) ?>';
            const canEditBase  = t.status==='Pending';
            const canCancelBase=['Pending','Processing'].includes(t.status);
            const canVoidBase  = t.status==='Completed';
            // Role-specific permissions
            const canEdit =
                (ROLE==='Super Admin' || ROLE==='Admin') ? canEditBase :
                (ROLE==='Manager' || ROLE==='Staff' || ROLE==='User') ? (canEditBase && isOwner) :
                false;
            const canCancel =
                (ROLE==='Super Admin' || ROLE==='Admin') ? canCancelBase :
                (ROLE==='Manager' || ROLE==='Staff' || ROLE==='User') ? (canCancelBase && isOwner) :
                false;
            const canVoid   = (ROLE==='Super Admin' || ROLE==='Admin') ? canVoidBase : false;
            const canOverride = (ROLE==='Super Admin');
            return `<tr class="${chk?'row-sel':''}" data-id="${t.txnId}">
                <td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${t.txnId}" ${chk?'checked':''}></div></td>
                <td onclick="openView('${t.txnId}')">
                    <div class="txn-cell">${esc(t.txnId)}</div>
                    ${t.discrepancy?`<div class="disc-flag" style="margin-top:3px"><i class="bx bx-error-circle"></i>Discrepancy</div>`:''}
                </td>
                <td onclick="openView('${t.txnId}')"><div class="date-cell">${dt.date}</div><div class="time-cell">${dt.time}</div></td>
                <td onclick="openView('${t.txnId}')">
                    ${t.type==='in'?`<span class="type-in-badge"><i class="bx bx-log-in-circle"></i>Stock In</span>`:`<span class="type-out-badge"><i class="bx bx-log-out-circle"></i>Stock Out</span>`}
                </td>
                <td onclick="openView('${t.txnId}')" style="max-width:170px">
                    <div class="code-cell">${esc(t.itemCode)}</div>
                    <div class="sub-cell" style="overflow:hidden;text-overflow:ellipsis;max-width:160px" title="${esc(t.itemName)}">${esc(t.itemName)}</div>
                </td>
                <td onclick="openView('${t.txnId}')" style="text-align:right">
                    <span class="num-cell ${t.type==='in'?'qty-in':'qty-out'}">${t.type==='in'?'+':'−'}${t.qty.toLocaleString()}</span>
                    <div class="sub-cell" style="text-align:right">${t.uom}</div>
                </td>
                <td onclick="openView('${t.txnId}')"><div class="ref-cell">${esc(t.refDoc)}</div><div class="sub-cell">${t.refType}</div></td>
                <td onclick="openView('${t.txnId}')">
                    <div class="zone-pill"><div class="zone-dot" style="background:${z.color}"></div>${t.zone||'—'}</div>
                    <div class="sub-cell mono" style="font-size:10.5px">${esc(t.bin)}</div>
                </td>
                <td onclick="openView('${t.txnId}')"><span class="date-cell">${esc(t.processedBy)}</span></td>
                <td onclick="openView('${t.txnId}')">${badge(t.status)}</td>
                <td onclick="event.stopPropagation()">
                    <div class="act-cell">
                        <button class="btn ionly" onclick="openView('${t.txnId}')" title="View"><i class="bx bx-show"></i></button>
                        <button class="btn ionly" onclick="openSlider('edit','${t.txnId}')" title="Edit (Pending only)" ${canEdit?'':'disabled'}><i class="bx bx-edit"></i></button>
                        <button class="btn ionly btn-warn" onclick="doAction('cancel','${t.txnId}')" title="Cancel" ${canCancel?'':'disabled'}><i class="bx bx-x-circle"></i></button>
                        <button class="btn ionly btn-danger" onclick="doAction('void','${t.txnId}')" title="Void" ${canVoid?'':'disabled'}><i class="bx bx-block"></i></button>
                        ${canOverride ? `<button class="btn ionly btn-teal" onclick="openSlider('override','${t.txnId}')" title="Override"><i class="bx bx-transfer-alt"></i></button>` : ''}
                    </div>
                </td>
            </tr>`;
        }).join('');
        document.querySelectorAll('.row-cb').forEach(cb=>{
            cb.addEventListener('change',function(){
                const id=this.dataset.id;
                if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
                this.closest('tr').classList.toggle('row-sel',this.checked);
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
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} transactions`}</span>
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
        sortDir=sortCol===c?(sortDir==='asc'?'desc':'asc'):'desc';
        sortCol=c; page=1; renderList();
    });
});
['srch','fZone','fItem','fStat','fFrom','fTo'].forEach(id=>
    document.getElementById(id).addEventListener('input',()=>{page=1;renderList();})
);

// ── TABS ──────────────────────────────────────────────────────────────────────
function switchTab(tab) {
    activeTab=tab; page=1;
    document.getElementById('tabAll').className='pg-tab'+(tab==='all'?' active':'');
    document.getElementById('tabIn').className ='pg-tab'+(tab==='in'?' active':'');
    document.getElementById('tabOut').className='pg-tab'+(tab==='out'?' active-out active':'');
    renderList();
}

// ── BULK ──────────────────────────────────────────────────────────────────────
function updateBulkBar(){
    const n=selectedIds.size;
    document.getElementById('bulkBar').classList.toggle('on',n>0);
    document.getElementById('bulkCt').textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice){
    const ca=document.getElementById('checkAll');
    const ids=slice.map(t=>t.txnId);
    const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
    const some=ids.some(id=>selectedIds.has(id));
    ca.checked=all; ca.indeterminate=!all&&some;
}
document.getElementById('checkAll').addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(t=>{if(this.checked) selectedIds.add(t.txnId); else selectedIds.delete(t.txnId);});
    renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});
document.getElementById('bExport').addEventListener('click',()=>toast(`Exported ${selectedIds.size} transaction(s).`,'s'));
document.getElementById('bPrint').addEventListener('click',()=>toast(`Print queued for ${selectedIds.size} transaction(s).`,'s'));
document.getElementById('bCancelSel').addEventListener('click',async()=>{
    const ids=[...selectedIds].filter(id=>{const t=TXN.find(x=>x.txnId===id);return t&&['Pending','Processing'].includes(t.status);});
    if(!ids.length){toast('No cancellable transactions selected.','w');return;}
    let n=0;
    for(const id of ids){
        try{
            const t=TXN.find(x=>x.txnId===id);
            const updated=await apiPost(API+'?api=action',{id:t.id,type:'cancel'});
            const idx=TXN.findIndex(x=>x.id===updated.id); if(idx>-1) TXN[idx]=updated; n++;
        }catch{}
    }
    selectedIds.clear(); renderList(); updateBulkBar();
    toast(`${n} transaction(s) cancelled.`,'s');
});

// ── SLIDER ────────────────────────────────────────────────────────────────────
function openSlider(mode, txnId) {
    sliderMode=mode; sliderTargetId=txnId;
    const t=txnId?TXN.find(x=>x.txnId===txnId):null;
    const hd=document.getElementById('slHd');
    hd.className='sl-hd '+(mode==='in'?'in-mode':mode==='out'?'out-mode':'view-mode');
    const cfg={
        in:      {title:'Create Stock In',     sub:'Record incoming inventory transaction'},
        out:     {title:'Create Stock Out',    sub:'Record outgoing inventory transaction'},
        edit:    {title:'Edit Transaction',    sub:`Editing ${t?t.txnId:'—'} · Pending only`},
        view:    {title:'Transaction Details', sub:t?`${t.txnId} · ${t.type==='in'?'Stock In':'Stock Out'}`:''},
        override:{title:'Override Transaction',sub:`Override ${t?t.txnId:'—'} · Super Admin only`},
    };
    document.getElementById('slTitle').textContent=cfg[mode]?.title||'';
    document.getElementById('slSub').textContent=cfg[mode]?.sub||'';
    const body=document.getElementById('slBody');
    const foot=document.getElementById('slFoot');

    if(mode==='view'){
        body.innerHTML=buildViewBody(t);
        foot.innerHTML=`
            ${t&&t.status==='Pending'?`<button class="btn btn-ghost btn-sm" onclick="closeSlider();openSlider('edit','${t.txnId}')"><i class="bx bx-edit"></i> Edit</button>`:''}
            ${t&&['Pending','Processing'].includes(t.status)?`<button class="btn btn-ghost btn-sm" onclick="closeSlider();doAction('cancel','${t.txnId}')"><i class="bx bx-x-circle"></i> Cancel</button>`:''}
            ${t&&t.status==='Completed'?`<button class="btn btn-ghost btn-sm" onclick="closeSlider();doAction('void','${t.txnId}')"><i class="bx bx-block"></i> Void</button>`:''}
            <button class="btn btn-ghost btn-sm" onclick="closeSlider()">Close</button>`;
    } else if(mode==='override'){
        body.innerHTML=buildOverrideBody(t);
        foot.innerHTML=`<button class="btn btn-ghost btn-sm" onclick="closeSlider()">Cancel</button><button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-transfer-alt"></i> Apply Override</button>`;
        document.getElementById('slSubmit').onclick=submitOverride;
    } else {
        body.innerHTML=buildFormBody(mode,t);
        foot.innerHTML=`<button class="btn btn-ghost btn-sm" onclick="closeSlider()">Cancel</button><button class="btn ${mode==='out'?'btn-secondary':'btn-primary'} btn-sm" id="slSubmit"><i class="bx bx-send"></i> Submit</button>`;
        document.getElementById('slSubmit').onclick=submitForm;
        wireItemSearch(t);
        // Wire discrepancy toggle
        setTimeout(()=>{
            const fd=document.getElementById('fDisc');
            if(fd) fd.addEventListener('change',function(){document.getElementById('fDiscNoteWrap').style.display=this.value==='1'?'':'none';});
        },100);

        // Auto-generate reference doc for new transactions
        if(!sliderTargetId){
            const rt=document.getElementById('fRefType');
            const rd=document.getElementById('fRefDoc');
            async function genRefDoc(){
                if(!rd||rd.dataset.manual==='1') return;
                rd.value='Generating…'; rd.setAttribute('readonly','');
                try{
                    const refType=rt?rt.value:'PO';
                    const d=await apiGet(API+'?api=next_ref&refType='+encodeURIComponent(refType));
                    rd.value=d.refDoc;
                    rd.removeAttribute('readonly');
                }catch(e){ rd.value=''; rd.removeAttribute('readonly'); rd.placeholder='e.g. PO-2026-0052'; }
            }
            genRefDoc();
            if(rt) rt.addEventListener('change', genRefDoc);
            if(rd) rd.addEventListener('input', ()=>rd.dataset.manual='1');
        }
    }
    document.getElementById('slOverlay').classList.add('on');
    document.getElementById('mainSlider').classList.add('on');
    setTimeout(()=>{const f=body.querySelector('input:not([disabled]),select:not([disabled])');if(f)f.focus();},350);
}

function closeSlider(){
    document.getElementById('mainSlider').classList.remove('on');
    document.getElementById('slOverlay').classList.remove('on');
    sliderMode=null; sliderTargetId=null;
}
document.getElementById('slOverlay').addEventListener('click',closeSlider);
document.getElementById('slClose').addEventListener('click',closeSlider);

// ── ITEM SEARCHABLE SELECT ────────────────────────────────────────────────────
function wireItemSearch(t) {
    setTimeout(()=>{
        const csInput=document.getElementById('csItemSearch');
        const csHidden=document.getElementById('fItemSl');
        const csDrop=document.getElementById('csItemDrop');
        if(!csInput) return;
        let csHl=-1;

        function csRender(q){
            const lq=(q||'').toLowerCase();
            const filtered=ITEMS.filter(i=>i.code.toLowerCase().includes(lq)||i.name.toLowerCase().includes(lq)).slice(0,60);
            if(!filtered.length){
                csDrop.innerHTML='<div class="cs-opt cs-none">No items found</div>';
            } else {
                csDrop.innerHTML=filtered.map(i=>
                    `<div class="cs-opt" data-code="${i.code}" data-name="${esc(i.name)}">
                        <span class="cs-code">${esc(i.code)}</span>
                        <span class="cs-name">${esc(i.name)}</span>
                        <span class="cs-stock">${i.uom} · ${i.stock.toLocaleString()} in stock</span>
                    </div>`
                ).join('');
                csDrop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
                    opt.addEventListener('mousedown',e=>{
                        e.preventDefault();
                        csSelect(opt.dataset.code,opt.dataset.name);
                    });
                });
            }
            csHl=-1;
        }

        function csSelect(code, name){
            csHidden.value=code;
            csInput.value=code+' — '+name;
            csDrop.classList.remove('open');
            autoFillItem(code);
        }

        // Pre-fill if editing
        if(t&&t.itemCode){
            const it=item(t.itemCode);
            csHidden.value=t.itemCode;
            csInput.value=t.itemCode+(it.name?' — '+it.name:'');
        }

        csInput.addEventListener('focus',()=>{csRender(csInput.value);csDrop.classList.add('open');});
        csInput.addEventListener('input',()=>{csHidden.value='';csRender(csInput.value);csDrop.classList.add('open');});
        csInput.addEventListener('blur',()=>setTimeout(()=>csDrop.classList.remove('open'),150));
        csInput.addEventListener('keydown',e=>{
            const opts=[...csDrop.querySelectorAll('.cs-opt:not(.cs-none)')];
            if(e.key==='ArrowDown'){e.preventDefault();csHl=Math.min(csHl+1,opts.length-1);}
            else if(e.key==='ArrowUp'){e.preventDefault();csHl=Math.max(csHl-1,0);}
            else if(e.key==='Enter'&&csHl>=0){e.preventDefault();const o=opts[csHl];if(o)csSelect(o.dataset.code,o.dataset.name);}
            else if(e.key==='Escape'){csDrop.classList.remove('open');}
            opts.forEach((o,i)=>o.classList.toggle('hl',i===csHl));
            if(csHl>=0&&opts[csHl]) opts[csHl].scrollIntoView({block:'nearest'});
        });

        // ── Zone searchable select ────────────────────────────────────────────
        const czInput=document.getElementById('csZoneSearch');
        const czHidden=document.getElementById('fZoneSl');
        const czDrop=document.getElementById('csZoneDrop');
        // Auto-fill Processed By with current logged-in user
        const cfInputEl=document.getElementById('csStaffSearch');
        const cfHiddenEl=document.getElementById('fStaff');
        if(cfInputEl&&cfHiddenEl&&!sliderTargetId&&CURRENT_USER){
            cfInputEl.value=CURRENT_USER;
            cfHiddenEl.value=CURRENT_USER;
        }

        if(czInput){
            let czHl=-1;
            function czRender(q){
                const lq=(q||'').toLowerCase();
                const filtered=ZONES.filter(z=>z.id.toLowerCase().includes(lq)||z.name.toLowerCase().includes(lq));
                czDrop.innerHTML=filtered.length
                    ? filtered.map(z=>`<div class="cs-opt" data-id="${z.id}" data-name="${esc(z.name)}" style="border-left:3px solid ${z.color}">
                            <span class="cs-code">${z.id}</span>
                            <span class="cs-name">${esc(z.name)}</span>
                        </div>`).join('')
                    : '<div class="cs-opt cs-none">No zones found</div>';
                czDrop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
                    opt.addEventListener('mousedown',e=>{e.preventDefault();czSelect(opt.dataset.id,opt.dataset.name);});
                });
                czHl=-1;
            }
            function czSelect(id,name){
                czHidden.value=id;
                czInput.value=name;
                czDrop.classList.remove('open');
            }
            const preZone=czHidden.value||(t?t.zone:'');
            if(preZone){const z=ZONES.find(x=>x.id===preZone);if(z){czHidden.value=z.id;czInput.value=z.name;}}
            czInput.addEventListener('focus',()=>{czRender(czInput.value);czDrop.classList.add('open');});
            czInput.addEventListener('input',()=>{czHidden.value='';czRender(czInput.value);czDrop.classList.add('open');});
            czInput.addEventListener('blur',()=>setTimeout(()=>czDrop.classList.remove('open'),150));
            czInput.addEventListener('keydown',e=>{
                const opts=[...czDrop.querySelectorAll('.cs-opt:not(.cs-none)')];
                if(e.key==='ArrowDown'){e.preventDefault();czHl=Math.min(czHl+1,opts.length-1);}
                else if(e.key==='ArrowUp'){e.preventDefault();czHl=Math.max(czHl-1,0);}
                else if(e.key==='Enter'&&czHl>=0){e.preventDefault();const o=opts[czHl];if(o)czSelect(o.dataset.id,o.dataset.name);}
                else if(e.key==='Escape'){czDrop.classList.remove('open');}
                opts.forEach((o,i)=>o.classList.toggle('hl',i===czHl));
                if(czHl>=0&&opts[czHl]) opts[czHl].scrollIntoView({block:'nearest'});
            });
        }

        // ── Staff searchable select ───────────────────────────────────────────
        const cfInput =document.getElementById('csStaffSearch');
        const cfHidden=document.getElementById('fStaff');
        const cfDrop  =document.getElementById('csStaffDrop');
        if(cfInput){
            let cfHl=-1;
            function cfRender(q){
                const lq=(q||'').toLowerCase();
                const filtered=STAFF.filter(s=>s.name.toLowerCase().includes(lq));
                cfDrop.innerHTML=filtered.length
                    ? filtered.map(s=>`<div class="cs-opt" data-name="${esc(s.name)}"><span class="cs-name">${esc(s.name)}</span></div>`).join('')
                    : '<div class="cs-opt cs-none">No staff found</div>';
                cfDrop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
                    opt.addEventListener('mousedown',e=>{e.preventDefault();cfSelect(opt.dataset.name);});
                });
                cfHl=-1;
            }
            function cfSelect(name){
                cfHidden.value=name;
                cfInput.value=name;
                cfDrop.classList.remove('open');
            }
            cfInput.addEventListener('focus',()=>{cfRender(cfInput.value);cfDrop.classList.add('open');});
            cfInput.addEventListener('input',()=>{cfHidden.value='';cfRender(cfInput.value);cfDrop.classList.add('open');});
            cfInput.addEventListener('blur',()=>setTimeout(()=>cfDrop.classList.remove('open'),150));
            cfInput.addEventListener('keydown',e=>{
                const opts=[...cfDrop.querySelectorAll('.cs-opt:not(.cs-none)')];
                if(e.key==='ArrowDown'){e.preventDefault();cfHl=Math.min(cfHl+1,opts.length-1);}
                else if(e.key==='ArrowUp'){e.preventDefault();cfHl=Math.max(cfHl-1,0);}
                else if(e.key==='Enter'&&cfHl>=0){e.preventDefault();const o=opts[cfHl];if(o)cfSelect(o.dataset.name);}
                else if(e.key==='Escape'){cfDrop.classList.remove('open');}
                opts.forEach((o,i)=>o.classList.toggle('hl',i===cfHl));
                if(cfHl>=0&&opts[cfHl]) opts[cfHl].scrollIntoView({block:'nearest'});
            });
        }
    },100);
}

function autoFillItem(code){
    const it=item(code); if(!it) return;
    // Zone — hidden input + visible text input
    const czHidden=document.getElementById('fZoneSl');
    const czInput =document.getElementById('csZoneSearch');
    if(czHidden&&it.zone){
        czHidden.value=it.zone;
        // Auto-fill Processed By with current logged-in user
        const cfInputEl=document.getElementById('csStaffSearch');
        const cfHiddenEl=document.getElementById('fStaff');
        if(cfInputEl&&cfHiddenEl&&!sliderTargetId&&CURRENT_USER){
            cfInputEl.value=CURRENT_USER;
            cfHiddenEl.value=CURRENT_USER;
        }

        if(czInput){const z=ZONES.find(x=>x.id===it.zone);if(z)czInput.value=z.name;}
    }
    const fb=document.getElementById('fBinSl');
    const fu=document.getElementById('fUomSl');
    if(fb&&it.bin) fb.value=it.bin;
    if(fu&&it.uom) fu.value=it.uom;
}

// ── FORM BODY ─────────────────────────────────────────────────────────────────
function buildFormBody(mode, t){

    const refTypes=['PO','PR','TO','RR','DR','WO'].map(r=>`<option ${t&&t.refType===r?'selected':''}>${r}</option>`).join('');
    const statuses=['Pending','Processing','Completed','Discrepancy'].map(s=>`<option ${t&&t.status===s?'selected':''}>${s}</option>`).join('');
    const isOut=(mode==='out')||(mode==='edit'&&t?.type==='out');
    const now=new Date();
    const todayDate=now.toISOString().slice(0,10);
    const todayTime=now.toTimeString().slice(0,5);
    return `
        <div class="fdiv">${isOut?'Outgoing':'Incoming'} Transaction</div>
        <div class="fg2">
            <div class="fg"><label class="fl">Date <span>*</span></label><input type="date" class="fi" id="fDate" value="${t?t.dateTime.slice(0,10):todayDate}"></div>
            <div class="fg"><label class="fl">Time <span>*</span></label><input type="time" class="fi" id="fTime" value="${t?t.dateTime.slice(11,16):todayTime}"></div>
        </div>
        <div class="fg">
            <label class="fl">Item <span>*</span></label>
            <div class="cs-wrap">
                <input type="text" class="cs-input" id="csItemSearch" placeholder="Search item code or name…" autocomplete="off">
                <input type="hidden" id="fItemSl" value="${t?t.itemCode:''}">
                <div class="cs-drop" id="csItemDrop"></div>
            </div>
        </div>
        <div class="fg2">
            <div class="fg"><label class="fl">Quantity <span>*</span></label><input type="number" class="fi" id="fQty" min="1" value="${t?t.qty:1}"></div>
            <div class="fg"><label class="fl">Unit of Measure</label><input type="text" class="fi" id="fUomSl" value="${t?t.uom:''}" disabled></div>
        </div>
        <div class="fdiv">Reference & Location</div>
        <div class="fg2">
            <div class="fg"><label class="fl">Reference Document</label><input type="text" class="fi" id="fRefDoc" placeholder="Generating…" value="${t?esc(t.refDoc):''}" style="font-family:'DM Mono',monospace" ${t?'':'readonly'}></div>
            <div class="fg"><label class="fl">Reference Type <span>*</span></label><select class="fs" id="fRefType">${refTypes}</select></div>
        </div>
        <div class="fg2">
            <div class="fg"><label class="fl">Zone</label>
            <div class="cs-wrap">
                <input type="text" class="cs-input" id="csZoneSearch" placeholder="Search zone…" autocomplete="off">
                <input type="hidden" id="fZoneSl" value="${t?t.zone:''}">
                <div class="cs-drop" id="csZoneDrop"></div>
            </div>
        </div>
            <div class="fg"><label class="fl">Bin Number</label><input type="text" class="fi" id="fBinSl" value="${t?esc(t.bin):''}" placeholder="e.g. A-01-R1"></div>
        </div>
        <div class="fdiv">Processing</div>
        <div class="fg2">
            <div class="fg"><label class="fl">Processed By <span>*</span></label>
            <div class="cs-wrap">
                <input type="text" class="cs-input" id="csStaffSearch" placeholder="Search staff name…" autocomplete="off" value="${t?esc(t.processedBy):''}">
                <input type="hidden" id="fStaff" value="${t?esc(t.processedBy):''}">
                <div class="cs-drop" id="csStaffDrop"></div>
            </div>
        </div>
            <div class="fg"><label class="fl">Status <span>*</span></label><select class="fs" id="fStatus">${statuses}</select></div>
        </div>
        <div class="fg"><label class="fl">Notes</label><textarea class="fta" id="fNotes" placeholder="Delivery notes, reference details…">${t?esc(t.notes):''}</textarea></div>
        <div class="fg"><label class="fl">Discrepancy?</label>
            <select class="fs" id="fDisc">
                <option value="0" ${t&&!t.discrepancy?'selected':''}>None</option>
                <option value="1" ${t&&t.discrepancy?'selected':''}>Flag Discrepancy</option>
            </select>
        </div>
        <div class="fg" id="fDiscNoteWrap" style="${t&&t.discrepancy?'':'display:none'}">
            <label class="fl">Discrepancy Details</label>
            <textarea class="fta" id="fDiscNote" placeholder="Describe the discrepancy…">${t&&t.discNote?esc(t.discNote):''}</textarea>
        </div>`;
}

// ── VIEW BODY ─────────────────────────────────────────────────────────────────
function buildViewBody(t){
    if(!t) return '<p>Not found.</p>';
    const z=zn(t.zone), dt=fDT(t.dateTime);
    return `
        ${t.discrepancy?`<div class="disc-banner"><i class="bx bx-error-circle"></i><div><div class="disc-banner-txt">Discrepancy Flagged</div><div style="font-size:11.5px;color:#991B1B;margin-top:2px;font-weight:400">${esc(t.discNote||'No details provided')}</div></div></div>`:''}
        <div class="vp-statbox">
            <div class="vp-sb"><div class="sbv ${t.type==='in'?'qty-in':'qty-out'}">${t.type==='in'?'+':'−'}${t.qty.toLocaleString()}</div><div class="sbl">${t.uom} ${t.type==='in'?'Received':'Released'}</div></div>
            <div class="vp-sb"><div class="sbv" style="font-family:'DM Mono',monospace;font-size:14px;color:${t.type==='in'?'#166534':'#991B1B'}">${t.type==='in'?'IN':'OUT'}</div><div class="sbl">Transaction Type</div></div>
            <div class="vp-sb"><div>${badge(t.status)}</div><div class="sbl" style="margin-top:6px">Status</div></div>
        </div>
        <div class="vp-section">
            <div class="vp-section-title">Transaction Info</div>
            <div class="vp-grid">
                <div class="vp-item"><label>Transaction ID</label><div class="v mono">${esc(t.txnId)}</div></div>
                <div class="vp-item"><label>Date & Time</label><div class="v">${dt.date} ${dt.time}</div></div>
                <div class="vp-item"><label>Item Code</label><div class="v mono" style="color:var(--grn)">${esc(t.itemCode)}</div></div>
                <div class="vp-item"><label>Item Name</label><div class="v">${esc(t.itemName)}</div></div>
                <div class="vp-item"><label>Quantity</label><div class="v" style="font-family:'DM Mono',monospace;font-weight:800">${t.qty.toLocaleString()} ${t.uom}</div></div>
                <div class="vp-item"><label>Processed By</label><div class="vm">${esc(t.processedBy)}</div></div>
            </div>
        </div>
        <div class="vp-section">
            <div class="vp-section-title">Reference & Location</div>
            <div class="vp-grid">
                <div class="vp-item"><label>Reference Document</label><div class="v" style="color:var(--blu);font-family:'DM Mono',monospace;font-weight:600">${esc(t.refDoc)}</div></div>
                <div class="vp-item"><label>Reference Type</label><div class="v">${esc(t.refType)}</div></div>
                <div class="vp-item"><label>Zone</label><div class="v" style="color:${z.color}">${z.name}</div></div>
                <div class="vp-item"><label>Bin</label><div class="v mono">${esc(t.bin)}</div></div>
            </div>
        </div>
        ${t.notes?`<div class="vp-section"><div class="vp-section-title">Notes</div><div style="font-size:13px;color:var(--t2);line-height:1.6">${esc(t.notes)}</div></div>`:''}`;
}

// ── OVERRIDE BODY ─────────────────────────────────────────────────────────────
function buildOverrideBody(t){
    if(!t) return '';
    return `
        <div class="override-banner"><i class="bx bx-shield-alt-2"></i>This action requires Super Admin privileges and will be logged in the audit trail.</div>
        <div style="background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px">
            <div style="width:40px;height:40px;border-radius:10px;background:${t.type==='in'?'var(--grn)':'var(--red)'};display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;flex-shrink:0"><i class="bx ${t.type==='in'?'bx-log-in-circle':'bx-log-out-circle'}"></i></div>
            <div><div style="font-weight:700;color:var(--t1)">${esc(t.itemName)}</div><div style="font-size:11.5px;color:var(--t3);font-family:'DM Mono',monospace">${esc(t.txnId)} · ${badge(t.status)}</div></div>
        </div>
        <div class="fg2">
            <div class="fg"><label class="fl">Override Status</label>
                <select class="fs" id="ovStatus">
                    ${['Pending','Processing','Completed','Discrepancy','Cancelled','Voided'].map(s=>`<option ${t.status===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div>
            <div class="fg"><label class="fl">Override Quantity</label><input type="number" class="fi" id="ovQty" min="1" value="${t.qty}"></div>
        </div>
        <div class="fg"><label class="fl">Discrepancy Flag</label>
            <select class="fs" id="ovDisc">
                <option value="0" ${!t.discrepancy?'selected':''}>None</option>
                <option value="1" ${t.discrepancy?'selected':''}>Flagged</option>
                <option value="2">Resolved</option>
            </select>
        </div>
        <div class="fg"><label class="fl">Override Reason <span>*</span></label><textarea class="fta" id="ovReason" placeholder="Provide justification for this override…"></textarea></div>`;
}

// ── SUBMIT FORM ───────────────────────────────────────────────────────────────
async function submitForm(){
    const btn=document.getElementById('slSubmit'); btn.disabled=true;
    try{
        const date   =document.getElementById('fDate')?.value;
        const time   =document.getElementById('fTime')?.value||'00:00';
        const itemCode=document.getElementById('fItemSl')?.value;
        const qty    =+document.getElementById('fQty')?.value||0;
        let refDoc=document.getElementById('fRefDoc')?.value.trim();
        if(!refDoc||refDoc==='Generating…'){
            try{
                const refType=document.getElementById('fRefType')?.value||'PO';
                const d=await apiGet(API+'?api=next_ref&refType='+encodeURIComponent(refType));
                refDoc=d.refDoc;
                const rdEl=document.getElementById('fRefDoc');
                if(rdEl) rdEl.value=refDoc;
            }catch(e){ toast('Could not generate reference number.','d'); return; }
        }
        const refType=document.getElementById('fRefType')?.value;
        const zone   =document.getElementById('fZoneSl')?.value;
        const bin    =document.getElementById('fBinSl')?.value.trim();
        const staff  =document.getElementById('fStaff')?.value;
        const status =document.getElementById('fStatus')?.value;
        const notes  =document.getElementById('fNotes')?.value.trim();
        const disc   =document.getElementById('fDisc')?.value==='1';
        const discNote=document.getElementById('fDiscNote')?.value.trim()||'';

        if(!date)     {toast('Date is required.','w');return;}
        if(!itemCode) {toast('Please select an item.','w');return;}
        if(qty<1)     {toast('Quantity must be at least 1.','w');return;}
        if(!staff)    {toast('Processed by is required.','w');return;}

        const t=sliderTargetId?TXN.find(x=>x.txnId===sliderTargetId):null;
        const type=sliderMode==='edit'?t?.type:sliderMode;
        const payload={
            type,dateTime:`${date}T${time}:00`,
            itemCode,qty,refDoc,refType,zone,bin,
            processedBy:staff,status,notes,
            discrepancy:disc,discNote,
        };
        if(t) payload.id=t.id;

        const saved=await apiPost(API+'?api=save',payload);
        const idx=TXN.findIndex(x=>x.id===saved.id);
        if(idx>-1) TXN[idx]=saved; else TXN.unshift(saved);
        toast(`${saved.txnId} ${t?'updated':'created'}.`,'s');
        closeSlider(); renderList();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
}

// ── SUBMIT OVERRIDE ───────────────────────────────────────────────────────────
async function submitOverride(){
    const btn=document.getElementById('slSubmit'); btn.disabled=true;
    try{
        const reason=document.getElementById('ovReason')?.value.trim();
        if(!reason){toast('Override reason is required.','w');return;}
        const t=TXN.find(x=>x.txnId===sliderTargetId); if(!t) return;
        const updated=await apiPost(API+'?api=action',{
            id:t.id, type:'override',
            status:document.getElementById('ovStatus')?.value,
            qty:+document.getElementById('ovQty')?.value||t.qty,
            discrepancy:+document.getElementById('ovDisc')?.value,
            reason,
        });
        const idx=TXN.findIndex(x=>x.id===updated.id); if(idx>-1) TXN[idx]=updated;
        toast(`${t.txnId} overridden successfully.`,'s');
        closeSlider(); renderList();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
}

// ── VIEW ──────────────────────────────────────────────────────────────────────
function openView(txnId){openSlider('view',txnId);}

// ── CANCEL / VOID ─────────────────────────────────────────────────────────────
function doAction(type, txnId){
    const t=TXN.find(x=>x.txnId===txnId); if(!t) return;
    const cfg={
        cancel:{icon:'⛔',title:'Cancel Transaction',body:`Cancel <strong>${esc(t.txnId)}</strong>?`,confirm:'Cancel Transaction',cls:'btn-ghost'},
        void:  {icon:'🚫',title:'Void Transaction',  body:`Void completed transaction <strong>${esc(t.txnId)}</strong>? This cannot be undone.`,confirm:'Void Transaction',cls:'btn-ghost'},
    };
    const c=cfg[type];
    document.getElementById('cmIcon').textContent=c.icon;
    document.getElementById('cmTitle').textContent=c.title;
    document.getElementById('cmBody').innerHTML=c.body;
    document.getElementById('cmConfirm').textContent=c.confirm;
    document.getElementById('cmConfirm').className=`btn btn-sm ${c.cls}`;
    confirmCb=async()=>{
        try{
            const updated=await apiPost(API+'?api=action',{id:t.id,type});
            const idx=TXN.findIndex(x=>x.id===updated.id); if(idx>-1) TXN[idx]=updated;
            renderList(); toast(`${t.txnId} ${type==='cancel'?'cancelled':'voided'}.`,'s');
        }catch(e){toast(e.message,'d');}
    };
    document.getElementById('confirmModal').classList.add('on');
}
document.getElementById('cmConfirm').addEventListener('click',async()=>{if(confirmCb)await confirmCb();document.getElementById('confirmModal').classList.remove('on');confirmCb=null;});
document.getElementById('cmCancel').addEventListener('click',()=>{document.getElementById('confirmModal').classList.remove('on');confirmCb=null;});
document.getElementById('confirmModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');confirmCb=null;}});

// ── EXPORT ────────────────────────────────────────────────────────────────────
function doExport(){
    const list=getFiltered();
    const hdrs=['Transaction ID','Type','Date','Time','Item Code','Item Name','Qty','UOM','Reference Doc','Ref Type','Zone','Bin','Processed By','Status','Discrepancy','Notes'];
    const rows=[hdrs.join(','),...list.map(t=>{
        const dt=fDT(t.dateTime);
        return [t.txnId,t.type==='in'?'Stock In':'Stock Out',dt.date,dt.time,t.itemCode,`"${t.itemName.replace(/"/g,'""')}"`,t.qty,t.uom,t.refDoc,t.refType,t.zone,t.bin,t.processedBy,t.status,t.discrepancy?'Yes':'No',`"${(t.notes||'').replace(/"/g,'""')}"`].join(',');
    })];
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='stock_transactions.csv'; a.click();
    toast('Transactions exported.','s');
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
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