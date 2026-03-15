<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function sws_resolve_role(): string {
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

$swsRoleName = sws_resolve_role();
$swsRoleRank = match($swsRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$swsUserZone = $_SESSION['zone'] ?? '';

// ── HELPERS ──────────────────────────────────────────────────────────────────
function sws_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function sws_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function sws_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) sws_err('Invalid JSON', 400);
    return is_array($d) ? $d : [];
}
function sws_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
    if ($res === false || $res === '') {
        if ($code >= 400) sws_err('Supabase request failed', 500);
        return [];
    }
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        sws_err('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

// Compute status from stock levels (mirrors JS statusOf())
function sws_status(array $item): string {
    if (!$item['active'])                          return 'Inactive';
    if ((int)$item['stock'] === 0)                 return 'Out of Stock';
    if ((int)$item['stock'] > (int)$item['max_level']) return 'Overstocked';
    if ((int)$item['stock'] <= (int)$item['min_level']) return 'Low Stock';
    return 'In Stock';
}

// Build full inventory item for JS
function sws_build_item(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'code'          => $row['code']           ?? '',
        'name'          => $row['name']           ?? '',
        'category'      => $row['category']       ?? '',
        'uom'           => $row['uom']            ?? 'pcs',
        'zone'          => $row['zone']           ?? '',
        'bin'           => $row['bin']            ?? '',
        'stock'         => (int)($row['stock']    ?? 0),
        'min'           => (int)($row['min_level']?? 0),
        'max'           => (int)($row['max_level']?? 100),
        'rop'           => (int)($row['rop']      ?? 0),
        'lastRestocked' => $row['last_restocked'] ?? '',
        'active'        => (bool)($row['active']  ?? true),
        'status'        => sws_status($row),
    ];
}

// Build full cycle count record for JS
function sws_build_cc(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'recordNo'       => $row['record_no']      ?? '',
        'countDate'      => $row['count_date']     ?? '',
        'itemCode'       => $row['item_code']      ?? '',
        'itemName'       => $row['item_name']      ?? '',
        'category'       => $row['category']       ?? '',
        'uom'            => $row['uom']            ?? 'pcs',
        'zone'           => $row['zone']           ?? '',
        'physicalCount'  => (int)($row['physical_count'] ?? 0),
        'systemCount'    => (int)($row['system_count']   ?? 0),
        'variance'       => (int)($row['variance']       ?? 0),
        'notes'          => $row['notes']          ?? '',
        'countedBy'      => $row['counted_by']     ?? '',
        'status'         => $row['status']         ?? 'Pending',
        'approvedBy'     => $row['approved_by']    ?? '',
        'approvedDate'   => $row['approved_date']  ?? '',
    ];
}

function sws_next_cc_no(): string {
    $rows = sws_sb('sws_cycle_counts', 'GET', ['select' => 'record_no', 'order' => 'id.desc', 'limit' => '1']);
    $next = 1;
    if (!empty($rows) && preg_match('/CC-(\d+)/', $rows[0]['record_no'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return sprintf('CC-%03d', $next);
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET next item code ───────────────────────────────────────────────────
        if ($api === 'next_code' && $method === 'GET') {
            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'code',
                'order'  => 'id.desc',
                'limit'  => '1',
            ]);
            $next = 1;
            if (!empty($rows) && preg_match('/ITM-(\d+)/', $rows[0]['code'] ?? '', $m)) {
                $next = ((int)$m[1]) + 1;
            }
            sws_ok(['code' => sprintf('ITM-%04d', $next)]);
        }

        // ── GET zones (role-scoped) ───────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $zoneQuery = ['select' => 'id,name,color', 'order' => 'id.asc'];
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone) {
                $zoneQuery['id'] = 'eq.' . $swsUserZone;
            }
            $rows = sws_sb('sws_zones', 'GET', $zoneQuery);
            sws_ok($rows);
        }

        // ── GET inventory list (role-scoped) ──────────────────────────────────
        if ($api === 'inventory' && $method === 'GET') {
            $invQuery = [
                'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                'order'  => 'code.asc',
            ];
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone) {
                $invQuery['zone'] = 'eq.' . $swsUserZone;
            }
            $rows = sws_sb('sws_inventory', 'GET', $invQuery);
            sws_ok(array_map('sws_build_item', $rows));
        }

        // ── GET single inventory item (role-scoped) ───────────────────────────
        if ($api === 'item' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) sws_err('Missing id', 400);
            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sws_err('Item not found', 404);
            $item = $rows[0];
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone && ($item['zone'] ?? '') !== $swsUserZone) {
                sws_err('Not authorized to access this item', 403);
            }
            sws_ok(sws_build_item($item));
        }

        // ── POST save inventory item (add / edit) ─────────────────────────────
        if ($api === 'save_item' && $method === 'POST') {
            $b = sws_body();
            $code     = trim($b['code']     ?? '');
            $name     = trim($b['name']     ?? '');
            $category = trim($b['category'] ?? '');
            $uom      = trim($b['uom']      ?? 'pcs');
            $zone     = trim($b['zone']     ?? '');
            $bin      = trim($b['bin']      ?? '');
            $stock    = (int)($b['stock']   ?? 0);
            $min      = (int)($b['min']     ?? 0);
            $max      = (int)($b['max']     ?? 100);
            $rop      = (int)($b['rop']     ?? 0);
            $date     = trim($b['lastRestocked'] ?? date('Y-m-d'));
            $active   = (bool)($b['active'] ?? true);

            if ($code === '') sws_err('Item code is required', 400);
            if ($name === '') sws_err('Item name is required', 400);
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone) {
                $zone = $swsUserZone;
            }
            if ($zone === '') sws_err('Zone is required', 400);

            $editId = (int)($b['id'] ?? 0);
            $now    = date('Y-m-d H:i:s');
            $payload = [
                'code' => $code, 'name' => $name, 'category' => $category,
                'uom' => $uom, 'zone' => $zone, 'bin' => $bin,
                'stock' => $stock, 'min_level' => $min, 'max_level' => $max,
                'rop' => $rop, 'last_restocked' => $date ?: null, 'active' => $active,
                'updated_at' => $now,
            ];

            if ($editId) {
                // Managers/Staff can only edit items in their own zone
                if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone) {
                    $cur = sws_sb('sws_inventory', 'GET', [
                        'select' => 'id,zone',
                        'id'     => 'eq.' . $editId,
                        'limit'  => '1',
                    ]);
                    if (empty($cur) || ($cur[0]['zone'] ?? '') !== $swsUserZone) {
                        sws_err('Not authorized to edit this item', 403);
                    }
                }
                sws_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                sws_sb('sws_inventory_audit', 'POST', [], [[
                    'item_id' => $editId, 'action' => 'edit',
                    'detail' => 'Item updated', 'actor_name' => $actor,
                    'ip_address' => $ip, 'occurred_at' => $now,
                ]]);
                $rows = sws_sb('sws_inventory', 'GET', [
                    'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                    'id' => 'eq.' . $editId, 'limit' => '1',
                ]);
                sws_ok(sws_build_item($rows[0]));
            }

            // Create
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $_SESSION['user_id'] ?? null;
            $payload['created_at']      = $now;
            $inserted = sws_sb('sws_inventory', 'POST', [], [$payload]);
            if (empty($inserted)) sws_err('Failed to create item', 500);
            $newId = (int)$inserted[0]['id'];
            sws_sb('sws_inventory_audit', 'POST', [], [[
                'item_id' => $newId, 'action' => 'add',
                'detail' => 'Item created', 'actor_name' => $actor,
                'ip_address' => $ip, 'occurred_at' => $now,
            ]]);
            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                'id' => 'eq.' . $newId, 'limit' => '1',
            ]);
            sws_ok(sws_build_item($rows[0]));
        }

        // ── POST adjust stock (role & zone aware) ─────────────────────────────
        if ($api === 'adjust' && $method === 'POST') {
            $b       = sws_body();
            $id      = (int)($b['id']    ?? 0);
            $type    = trim($b['type']   ?? 'add'); // add | remove | set
            $qty     = (int)($b['qty']   ?? 0);
            $notes   = trim($b['notes']  ?? '');
            $now     = date('Y-m-d H:i:s');

            if (!$id) sws_err('Missing item id', 400);

            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,stock,zone', 'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sws_err('Item not found', 404);
            $item     = $rows[0];
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone && ($item['zone'] ?? '') !== $swsUserZone) {
                sws_err('Not authorized to adjust this item', 403);
            }
            $oldStock = (int)$item['stock'];

            $newStock = match($type) {
                'add'    => $oldStock + $qty,
                'remove' => max(0, $oldStock - $qty),
                'set'    => $qty,
                default  => $oldStock,
            };

            sws_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $id], [
                'stock'          => $newStock,
                'last_restocked' => date('Y-m-d'),
                'updated_at'     => $now,
            ]);
            sws_sb('sws_stock_adjustments', 'POST', [], [[
                'item_id'    => $id,   'adj_type'  => $type,
                'quantity'   => $qty,  'old_stock' => $oldStock,
                'new_stock'  => $newStock, 'notes' => $notes,
                'actor_name' => $actor, 'occurred_at' => $now,
            ]]);
            sws_sb('sws_inventory_audit', 'POST', [], [[
                'item_id'    => $id,    'action'    => 'adjust',
                'detail'     => ucfirst($type) . ' ' . $qty . ' — ' . ($notes ?: 'Stock adjustment'),
                'old_stock'  => $oldStock, 'new_stock' => $newStock,
                'actor_name' => $actor,    'ip_address' => $ip, 'occurred_at' => $now,
            ]]);

            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            sws_ok(sws_build_item($rows[0]));
        }

        // ── POST transfer stock (role & zone aware) ───────────────────────────
        if ($api === 'transfer' && $method === 'POST') {
            $b      = sws_body();
            $id     = (int)($b['id']     ?? 0);
            $qty    = (int)($b['qty']    ?? 0);
            $toZone = trim($b['toZone']  ?? '');
            $toBin  = trim($b['toBin']   ?? '');
            $notes  = trim($b['notes']   ?? '');
            $now    = date('Y-m-d H:i:s');

            if (!$id)       sws_err('Missing item id', 400);
            if (!$qty)      sws_err('Quantity is required', 400);
            if (!$toBin)    sws_err('Destination bin is required', 400);

            // Users cannot initiate transfers; Managers only within their zone
            if ($swsRoleRank <= 1) {
                sws_err('Not authorized to transfer stock', 403);
            }

            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,stock,zone,bin', 'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sws_err('Item not found', 404);
            $item     = $rows[0];
            if ($swsRoleName === 'Manager' && $swsUserZone && ($item['zone'] ?? '') !== $swsUserZone) {
                sws_err('Not authorized to transfer this item', 403);
            }
            if ($swsRoleName === 'Manager' && $swsUserZone && $toZone && $toZone !== $swsUserZone) {
                sws_err('Managers may only transfer within their zone', 403);
            }
            $oldStock = (int)$item['stock'];
            if ($qty > $oldStock) sws_err('Transfer quantity exceeds available stock', 400);
            $newStock = $oldStock - $qty;

            sws_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $id], [
                'stock' => $newStock, 'updated_at' => $now,
            ]);
            sws_sb('sws_stock_adjustments', 'POST', [], [[
                'item_id'    => $id,    'adj_type'  => 'transfer_out',
                'quantity'   => $qty,   'old_stock' => $oldStock,
                'new_stock'  => $newStock, 'notes'  => $notes,
                'to_zone'    => $toZone ?: null, 'to_bin' => $toBin,
                'actor_name' => $actor, 'occurred_at' => $now,
            ]]);
            sws_sb('sws_inventory_audit', 'POST', [], [[
                'item_id'   => $id,    'action'    => 'transfer',
                'detail'    => "Transferred {$qty} units to {$toZone}/{$toBin}",
                'old_stock' => $oldStock, 'new_stock' => $newStock,
                'actor_name'=> $actor, 'ip_address' => $ip, 'occurred_at' => $now,
            ]]);

            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            sws_ok(sws_build_item($rows[0]));
        }

        // ── POST toggle active (Admin/SuperAdmin only) ────────────────────────
        if ($api === 'toggle_active' && $method === 'POST') {
            if ($swsRoleRank < 3) {
                sws_err('Not authorized to activate/deactivate items', 403);
            }
            $b    = sws_body();
            $id   = (int)($b['id'] ?? 0);
            $now  = date('Y-m-d H:i:s');
            if (!$id) sws_err('Missing id', 400);
            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,active,code', 'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sws_err('Item not found', 404);
            $newActive = !((bool)$rows[0]['active']);
            sws_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $id], ['active' => $newActive, 'updated_at' => $now]);
            sws_sb('sws_inventory_audit', 'POST', [], [[
                'item_id'    => $id,
                'action'     => $newActive ? 'activate' : 'deactivate',
                'detail'     => $newActive ? 'Item activated' : 'Item deactivated',
                'actor_name' => $actor, 'ip_address' => $ip, 'occurred_at' => $now,
            ]]);
            $rows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id,code,name,category,uom,zone,bin,stock,min_level,max_level,rop,last_restocked,active',
                'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            sws_ok(sws_build_item($rows[0]));
        }

        // ── GET cycle counts (role-scoped) ────────────────────────────────────
        if ($api === 'cycle_counts' && $method === 'GET') {
            $ccQuery = [
                'select' => 'id,record_no,count_date,item_code,item_name,category,uom,zone,physical_count,system_count,variance,notes,counted_by,status,approved_by,approved_date',
                'order'  => 'count_date.desc,id.desc',
            ];
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone) {
                $ccQuery['zone'] = 'eq.' . $swsUserZone;
            }
            $rows = sws_sb('sws_cycle_counts', 'GET', $ccQuery);
            sws_ok(array_map('sws_build_cc', $rows));
        }

        // ── POST save cycle count (role-aware) ────────────────────────────────
        if ($api === 'save_cc' && $method === 'POST') {
            $b          = sws_body();
            $countDate  = trim($b['countDate']     ?? '');
            $itemCode   = trim($b['itemCode']      ?? '');
            $itemName   = trim($b['itemName']      ?? '');
            $category   = trim($b['category']      ?? '');
            $uom        = trim($b['uom']           ?? 'pcs');
            $zone       = trim($b['zone']          ?? '');
            $physical   = (int)($b['physicalCount']?? 0);
            $system     = (int)($b['systemCount']  ?? 0);
            $notes      = trim($b['notes']         ?? '');
            $countedBy  = trim($b['countedBy']     ?? '');
            $status     = trim($b['status']        ?? 'Pending');

            if (!$countDate)  sws_err('Count date is required', 400);
            if (!$itemCode)   sws_err('Item is required', 400);
            if (!$countedBy)  sws_err('Counted by is required', 400);

            $allowedStatus = ['Pending','Matched','Over','Short','Flagged','Approved','Rejected'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Pending';

            // Auto-set status from variance if Pending
            if ($status === 'Pending') {
                $variance = $physical - $system;
                $status = $variance === 0 ? 'Matched' : ($variance > 0 ? 'Over' : 'Short');
            }

            $editId = (int)($b['id'] ?? 0);
            $now    = date('Y-m-d H:i:s');

            // Fetch item_id if available
            $invRows = sws_sb('sws_inventory', 'GET', ['select' => 'id,name,category,uom', 'code' => 'eq.' . $itemCode, 'limit' => '1']);
            $itemId  = !empty($invRows) ? (int)$invRows[0]['id'] : null;
            if (!$itemName && !empty($invRows)) $itemName = $invRows[0]['name'];
            if (!$category && !empty($invRows)) $category = $invRows[0]['category'];
            if (!$uom      && !empty($invRows)) $uom      = $invRows[0]['uom'];

            // Managers/Staff are restricted to their own zone
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone) {
                $zone = $swsUserZone;
            }

            $payload = [
                'count_date'     => $countDate,
                'item_id'        => $itemId,
                'item_code'      => $itemCode,
                'item_name'      => $itemName,
                'category'       => $category,
                'uom'            => $uom,
                'zone'           => $zone ?: null,
                'physical_count' => $physical,
                'system_count'   => $system,
                'notes'          => $notes,
                'counted_by'     => $countedBy,
                'status'         => $status,
                'updated_at'     => $now,
            ];

            if ($editId) {
                // For Staff, do not allow edits to existing records (encode & submit only)
                if ($swsRoleRank <= 1) {
                    sws_err('Not authorized to edit cycle count', 403);
                }
                sws_sb('sws_cycle_counts', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                $rows = sws_sb('sws_cycle_counts', 'GET', [
                    'select' => 'id,record_no,count_date,item_code,item_name,category,uom,zone,physical_count,system_count,variance,notes,counted_by,status,approved_by,approved_date',
                    'id' => 'eq.' . $editId, 'limit' => '1',
                ]);
                sws_ok(sws_build_cc($rows[0]));
            }

            $payload['record_no']       = sws_next_cc_no();
            $payload['created_user_id'] = $_SESSION['user_id'] ?? null;
            $payload['created_at']      = $now;
            $inserted = sws_sb('sws_cycle_counts', 'POST', [], [$payload]);
            if (empty($inserted)) sws_err('Failed to create cycle count record', 500);
            $newId = (int)$inserted[0]['id'];
            $rows = sws_sb('sws_cycle_counts', 'GET', [
                'select' => 'id,record_no,count_date,item_code,item_name,category,uom,zone,physical_count,system_count,variance,notes,counted_by,status,approved_by,approved_date',
                'id' => 'eq.' . $newId, 'limit' => '1',
            ]);
            sws_ok(sws_build_cc($rows[0]));
        }

        // ── POST cycle count action (approve / reject / flag / override) ──────
        if ($api === 'cc_action' && $method === 'POST') {
            $b      = sws_body();
            $id     = (int)($b['id']   ?? 0);
            $type   = trim($b['type']  ?? '');
            $now    = date('Y-m-d H:i:s');

            if (!$id)   sws_err('Missing id', 400);
            if (!$type) sws_err('Missing type', 400);

            $rows = sws_sb('sws_cycle_counts', 'GET', [
                'select' => 'id,record_no,item_id,item_code,physical_count,system_count,status,zone',
                'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) sws_err('Record not found', 404);
            $rec = $rows[0];

            // Zone & role checks
            if (($swsRoleName === 'Manager' || $swsRoleName === 'Staff') && $swsUserZone && ($rec['zone'] ?? '') !== $swsUserZone) {
                sws_err('Not authorized to manage this cycle count', 403);
            }
            if ($swsRoleRank <= 1) {
                sws_err('Not authorized to perform this action', 403);
            }
            if (in_array($type, ['approve','reject'], true) && $swsRoleRank < 3) {
                sws_err('Only Admin or Super Admin may approve/reject', 403);
            }
            if ($type === 'override' && $swsRoleRank < 4) {
                sws_err('Only Super Admin may override counts', 403);
            }

            $patch = ['updated_at' => $now];
            switch ($type) {
                case 'approve':
                    $patch['status']        = 'Approved';
                    $patch['approved_by']   = $actor;
                    $patch['approved_date'] = date('Y-m-d');
                    break;
                case 'reject':
                    $patch['status']        = 'Rejected';
                    $patch['approved_by']   = $actor;
                    $patch['approved_date'] = date('Y-m-d');
                    break;
                case 'flag':
                    $patch['status'] = 'Flagged';
                    break;
                case 'override':
                    // Override: accept physical count as correct, sync system count
                    $patch['status']        = 'Approved';
                    $patch['system_count']  = (int)$rec['physical_count'];
                    $patch['approved_by']   = $actor;
                    $patch['approved_date'] = date('Y-m-d');
                    // Also update inventory stock if item linked
                    if ($rec['item_id']) {
                        sws_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $rec['item_id']], [
                            'stock' => (int)$rec['physical_count'], 'updated_at' => $now,
                        ]);
                        sws_sb('sws_inventory_audit', 'POST', [], [[
                            'item_id'   => (int)$rec['item_id'], 'action' => 'adjust',
                            'detail'    => 'Cycle count override — stock corrected to ' . $rec['physical_count'],
                            'old_stock' => (int)$rec['system_count'], 'new_stock' => (int)$rec['physical_count'],
                            'actor_name'=> $actor, 'ip_address' => $ip, 'occurred_at' => $now,
                        ]]);
                    }
                    break;
                default:
                    sws_err('Unsupported action', 400);
            }

            sws_sb('sws_cycle_counts', 'PATCH', ['id' => 'eq.' . $id], $patch);
            $rows = sws_sb('sws_cycle_counts', 'GET', [
                'select' => 'id,record_no,count_date,item_code,item_name,category,uom,zone,physical_count,system_count,variance,notes,counted_by,status,approved_by,approved_date',
                'id' => 'eq.' . $id, 'limit' => '1',
            ]);
            sws_ok(sws_build_cc($rows[0]));
        }

        sws_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        sws_err('Server error: ' . $e->getMessage(), 500);
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
<title>Inventory Management — SWS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{--bg:#F3F6F2;--s:#FFFFFF;--t1:#1A2B1C;--t2:#5D7263;--t3:#9EB5A4;--bd:rgba(46,125,50,.12);--bdm:rgba(46,125,50,.22);--grn:#2E7D32;--gdk:#1B5E20;--glt:#4CAF50;--gxl:#E8F5E9;--amb:#D97706;--red:#DC2626;--blu:#2563EB;--shsm:0 1px 4px rgba(46,125,50,.08);--shmd:0 4px 20px rgba(46,125,50,.11);--shlg:0 12px 40px rgba(0,0,0,.14);--rad:14px;--tr:all .18s ease;}
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
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}.btn-ghost:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn-sm{font-size:12px;padding:6px 13px}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2)}
.btn.ionly:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn-danger.ionly:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.btn-warn.ionly:hover{background:#FEF3C7;color:var(--amb);border-color:#FDE68A}
.btn-blue.ionly:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE}
.sum-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;animation:UP .4s .06s both}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:16px 18px;box-shadow:var(--shsm);display:flex;align-items:center;gap:12px;transition:var(--tr)}
.sc:hover{box-shadow:var(--shmd);transform:translateY(-2px)}
.sc-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:19px}
.ic-g{background:#E8F5E9;color:#2E7D32}.ic-a{background:#FEF3C7;color:#D97706}.ic-r{background:#FEF2F2;color:#DC2626}.ic-b{background:#EFF6FF;color:#2563EB}.ic-t{background:#CCFBF1;color:#0D9488}.ic-p{background:#F5F3FF;color:#7C3AED}
.sc-v{font-size:24px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}.sc-l{font-size:11.5px;color:var(--t2);margin-top:3px;font-weight:500}
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
.inv-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--t2);padding:8px 7px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none}
.inv-tbl thead th.ns{cursor:default}.inv-tbl thead th:hover:not(.ns){color:var(--grn)}.inv-tbl thead th.sorted{color:var(--grn)}
.inv-tbl thead th .si-c{margin-left:2px;opacity:.4;font-size:10px;vertical-align:middle}.inv-tbl thead th.sorted .si-c{opacity:1}
.inv-tbl thead th:first-child{width:34px;padding-left:10px;padding-right:4px}
.inv-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s}
.inv-tbl tbody tr:last-child{border-bottom:none}.inv-tbl tbody tr:hover{background:#F7FBF7}.inv-tbl tbody tr.row-sel{background:#F0FDF4}
.inv-tbl tbody td{padding:9px 7px;vertical-align:middle;cursor:pointer;white-space:nowrap}
.inv-tbl tbody td:first-child{cursor:default;padding-left:10px;padding-right:4px;width:34px}
.inv-tbl tbody td:last-child{white-space:nowrap;cursor:default;padding:6px 7px}
.cb-wrap{display:flex;align-items:center;justify-content:center}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer}
.mono{font-family:'DM Mono',monospace}.code-cell{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--grn)}
.name-cell{font-weight:600;color:var(--t1)}.sub-cell{font-size:11px;color:var(--t3);margin-top:1px}
.num-cell{font-family:'DM Mono',monospace;font-size:12px;font-weight:700;text-align:right}.date-cell{font-size:11.5px;color:var(--t2)}
.zone-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600}.zone-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.act-cell{display:flex;gap:3px;align-items:center}.btn.ionly{width:26px;height:26px;font-size:13px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.b-in{background:#DCFCE7;color:#166534}.b-low{background:#FEF3C7;color:#92400E}.b-out{background:#FEE2E2;color:#991B1B}.b-over{background:#EFF6FF;color:#1D4ED8}.b-inact{background:#F3F4F6;color:#6B7280}
.pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2)}
.pg-btns{display:flex;gap:5px}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1)}
.pgb:hover{background:var(--gxl);border-color:var(--grn);color:var(--grn)}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff}.pgb:disabled{opacity:.4;pointer-events:none}
.empty{padding:64px 20px;text-align:center;color:var(--t3)}.empty i{font-size:48px;display:block;margin-bottom:12px;color:#C8E6C9}
#ccViewModal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s}
#ccViewModal.on{opacity:1;pointer-events:all}
#ccSlider{position:fixed;top:0;right:-620px;bottom:0;width:580px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#ccSlider.on{right:0}
.pg-tabs{display:flex;gap:4px;margin-bottom:18px;background:var(--s);border:1px solid var(--bd);border-radius:12px;padding:4px;width:fit-content;box-shadow:var(--shsm)}
.pg-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 20px;border-radius:9px;border:none;cursor:pointer;transition:var(--tr);color:var(--t2);background:transparent;display:flex;align-items:center;gap:7px}
.pg-tab i{font-size:16px}.pg-tab:hover{background:var(--gxl);color:var(--grn)}.pg-tab.active{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.28)}
.cc-sum-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.var-pos{color:#2563EB;font-weight:800}.var-neg{color:#DC2626;font-weight:800}.var-zero{color:#166534;font-weight:800}
.b-matched{background:#DCFCE7;color:#166534}.b-over{background:#EFF6FF;color:#1D4ED8}.b-short{background:#FEE2E2;color:#991B1B}
.b-flagged{background:#FEF3C7;color:#92400E}.b-approved{background:#D1FAE5;color:#065F46}.b-rejected{background:#FCE7F3;color:#9D174D}.b-pending{background:#F3F4F6;color:#374151}
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s}
#viewModal.on{opacity:1;pointer-events:all}
.vm-box{background:var(--s);border-radius:16px;width:720px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden}
.vm-hd{padding:20px 24px 0;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0}
.vm-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.vm-av{width:48px;height:48px;border-radius:12px;background:var(--grn);display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
.vm-nm{font-size:18px;font-weight:800;color:var(--t1);line-height:1.2}.vm-meta{font-size:12px;color:var(--t2);margin-top:3px;font-family:'DM Mono',monospace}
.vm-cl{width:34px;height:34px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:19px;color:var(--t2);transition:var(--tr)}
.vm-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.vm-chips{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}
.vm-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--t2);background:var(--s);border:1px solid var(--bd);border-radius:8px;padding:4px 10px}
.vm-chip i{font-size:13px;color:var(--grn)}
.vm-tabs{display:flex;gap:4px}
.vm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--t2);border:none;background:transparent;display:flex;align-items:center;gap:6px}
.vm-tab:hover{background:rgba(46,125,50,.08);color:var(--t1)}.vm-tab.active{background:var(--grn);color:#fff}.vm-tab i{font-size:14px}
.vm-bd{flex:1;overflow-y:auto;padding:20px 24px}
.vm-bd::-webkit-scrollbar{width:4px}.vm-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.vm-tp{display:none;flex-direction:column;gap:14px}.vm-tp.active{display:flex}
.vm-sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.vm-sb{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px}
.vm-sb .sbv{font-size:18px;font-weight:800;color:var(--t1);line-height:1}.vm-sb .sbv.mono{font-family:'DM Mono',monospace;font-size:14px;color:var(--grn)}.vm-sb .sbl{font-size:11px;color:var(--t2);margin-top:3px}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);display:block;margin-bottom:3px}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--t1)}.vm-ii .vm{font-size:13px;color:var(--t2)}.vm-full{grid-column:1/-1}
.vm-ft{padding:14px 24px;border-top:1px solid var(--bd);background:var(--s);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap}
.stk-bar-wrap{margin-top:6px}.stk-bar-track{height:6px;background:#E5E7EB;border-radius:4px;overflow:hidden}.stk-bar-fill{height:100%;border-radius:4px}
.sfill-in{background:#22C55E}.sfill-low{background:#F59E0B}.sfill-out{background:#EF4444}.sfill-over{background:#3B82F6}
#slOverlay,#ccSlOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s}
#slOverlay.on,#ccSlOverlay.on{opacity:1;pointer-events:all}
#mainSlider{position:fixed;top:0;right:-620px;bottom:0;width:580px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#mainSlider.on{right:0}
.sl-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:#F0FAF0;flex-shrink:0}
.sl-title{font-size:17px;font-weight:700;color:var(--t1)}.sl-sub{font-size:12px;color:var(--t2);margin-top:2px}
.sl-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr)}
.sl-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.sl-bd{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px}
.sl-bd::-webkit-scrollbar{width:4px}.sl-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.sl-ft{padding:16px 24px;border-top:1px solid var(--bd);background:#F0FAF0;display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.fg{display:flex;flex-direction:column;gap:5px}.fg2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.fl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2)}.fl span{color:var(--red);margin-left:2px}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px}
.fta{resize:vertical;min-height:68px}
.fdiv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px}
.fdiv::after{content:'';flex:1;height:1px;background:var(--bd)}
.fhint{font-size:11.5px;color:var(--t3);margin-top:3px}
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
.cs-opt.cs-none{color:var(--t3);cursor:default;font-size:12px;padding:12px}
.cs-opt.cs-none:hover{background:none}

.adj-row{display:flex;align-items:center;gap:10px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px}
.adj-label{font-size:13px;font-weight:600;color:var(--t1);flex:1}
.adj-ctrl{display:flex;align-items:center;gap:8px}
.adj-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:18px;color:var(--grn);font-weight:700;transition:var(--tr)}
.adj-btn:hover{background:var(--grn);color:#fff}
.adj-val{font-family:'DM Mono',monospace;font-size:18px;font-weight:800;color:var(--t1);min-width:52px;text-align:center;border:none;outline:none;background:transparent}
#confirmModal{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
#confirmModal.on{opacity:1;pointer-events:all}
.cm-box{background:var(--s);border-radius:14px;padding:26px 26px 22px;width:400px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.22)}
.cm-icon{font-size:44px;margin-bottom:8px;line-height:1}.cm-title{font-size:17px;font-weight:700;color:var(--t1);margin-bottom:6px}
.cm-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px}.cm-acts{display:flex;gap:10px;justify-content:flex-end}
#toastWrap{position:fixed;bottom:26px;right:26px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:210px;animation:TIN .3s ease}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}.toast.out{animation:TOUT .3s ease forwards}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@media(max-width:900px){.sum-grid{grid-template-columns:repeat(3,1fr)}.fg2,.fg3{grid-template-columns:1fr}.vm-sbs{grid-template-columns:repeat(2,1fr)}.vm-ig{grid-template-columns:1fr}}
@media(max-width:600px){.wrap{padding:0 0 2rem}.sum-grid{grid-template-columns:repeat(2,1fr)}#mainSlider{width:100vw}}
</style>
</head>
<body>

<main class="main-content" id="mainContent">
<div class="wrap">

  <div class="ph">
    <div class="ph-l">
      <p class="ey">SWS · Smart Warehousing System</p>
      <h1>Inventory Management</h1>
    </div>
    <div class="ph-r" id="phActions"></div>
  </div>

  <div class="sum-grid" id="sumGrid"></div>

  <div class="pg-tabs">
    <button class="pg-tab active" id="tabInv" onclick="switchTab('inv')"><i class="bx bx-package"></i> Inventory</button>
    <button class="pg-tab"        id="tabCyc" onclick="switchTab('cyc')"><i class="bx bx-list-check"></i> Cycle Count</button>
  </div>

  <!-- INVENTORY SECTION -->
  <div id="secInv">
    <div class="toolbar">
      <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search by item code, name, or bin…"></div>
      <select class="sel" id="fZone"><option value="">All Zones</option></select>
      <select class="sel" id="fCat"><option value="">All Categories</option></select>
      <select class="sel" id="fStat">
        <option value="">All Statuses</option>
        <option>In Stock</option><option>Low Stock</option><option>Out of Stock</option><option>Overstocked</option>
      </select>
      <div class="date-wrap">
        <input type="date" class="fi-date" id="fFrom" title="Restocked From">
        <span>–</span>
        <input type="date" class="fi-date" id="fTo" title="Restocked To">
      </div>
    </div>
    <div class="bulk-bar" id="bulkBar">
      <span class="bulk-ct" id="bulkCt">0 selected</span>
      <div class="bulk-sep"></div>
      <button class="btn btn-ghost btn-sm" id="bExport"><i class="bx bx-export"></i> Export Selected</button>
      <button class="btn btn-ghost btn-sm" id="bPrint"><i class="bx bx-printer"></i> Print Selected</button>
      <button class="btn btn-ghost btn-sm" id="bBatchAdj"><i class="bx bx-transfer"></i> Batch Adjust</button>
      <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    </div>
    <div class="tbl-card">
      <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;"><div style="min-width:1100px;">
      <table class="inv-tbl" id="tbl">
        <thead><tr>
          <th class="ns"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
          <th data-col="code">Item Code <i class="bx bx-sort si-c"></i></th>
          <th data-col="name">Item Name <i class="bx bx-sort si-c"></i></th>
          <th data-col="category">Category <i class="bx bx-sort si-c"></i></th>
          <th data-col="uom">UOM <i class="bx bx-sort si-c"></i></th>
          <th data-col="zone">Zone <i class="bx bx-sort si-c"></i></th>
          <th data-col="bin">Bin # <i class="bx bx-sort si-c"></i></th>
          <th data-col="stock" style="text-align:right">Stock <i class="bx bx-sort si-c"></i></th>
          <th data-col="min"   style="text-align:right">Min <i class="bx bx-sort si-c"></i></th>
          <th data-col="max"   style="text-align:right">Max <i class="bx bx-sort si-c"></i></th>
          <th data-col="rop"   style="text-align:right">ROP <i class="bx bx-sort si-c"></i></th>
          <th data-col="lastRestocked">Last Restocked <i class="bx bx-sort si-c"></i></th>
          <th data-col="status">Status <i class="bx bx-sort si-c"></i></th>
          <th class="ns">Actions</th>
        </tr></thead>
        <tbody id="tbody"></tbody>
      </table>
      </div></div>
      <div class="pager" id="pager"></div>
    </div>
  </div>

  <!-- CYCLE COUNT SECTION -->
  <div id="secCyc" style="display:none">
    <div class="cc-sum-grid" id="ccSumGrid"></div>
    <div class="toolbar" id="ccToolbar">
      <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="ccSrch" placeholder="Search by item code or name…"></div>
      <select class="sel" id="ccFZone"><option value="">All Zones</option></select>
      <select class="sel" id="ccFCat"><option value="">All Categories</option></select>
      <select class="sel" id="ccFStat">
        <option value="">All Statuses</option>
        <option>Matched</option><option>Over</option><option>Short</option>
        <option>Flagged</option><option>Approved</option><option>Rejected</option><option>Pending</option>
      </select>
      <div class="date-wrap">
        <input type="date" class="fi-date" id="ccFrom">
        <span>–</span>
        <input type="date" class="fi-date" id="ccTo">
      </div>
    </div>
    <div class="bulk-bar" id="ccBulkBar">
      <span class="bulk-ct" id="ccBulkCt">0 selected</span>
      <div class="bulk-sep"></div>
      <button class="btn btn-ghost btn-sm" id="ccBExport"><i class="bx bx-export"></i> Export Selected</button>
      <button class="btn btn-ghost btn-sm" id="ccBPrint"><i class="bx bx-printer"></i> Print Selected</button>
      <button class="btn btn-ghost btn-sm" id="ccBBatchApprove"><i class="bx bx-check-double"></i> Batch Approve</button>
      <button class="btn btn-ghost btn-sm" id="ccClearSel"><i class="bx bx-x-circle"></i> Clear</button>
    </div>
    <div class="tbl-card">
      <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;"><div style="min-width:900px;">
      <table class="inv-tbl" id="ccTbl">
        <thead><tr>
          <th class="ns"><div class="cb-wrap"><input type="checkbox" class="cb" id="ccCheckAll"></div></th>
          <th data-ccol="countDate">Count Date <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="itemCode">Item Code <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="itemName">Item <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="category">Category <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="zone">Zone <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="physicalCount" style="text-align:right">Physical Count <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="systemCount"   style="text-align:right">System Count <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="variance"       style="text-align:right">Variance <i class="bx bx-sort si-c"></i></th>
          <th data-ccol="status">Status <i class="bx bx-sort si-c"></i></th>
          <th class="ns">Actions</th>
        </tr></thead>
        <tbody id="ccTbody"></tbody>
      </table>
      </div></div>
      <div class="pager" id="ccPager"></div>
    </div>
  </div>

</div>
</main>

<div id="toastWrap"></div>
<div id="slOverlay"></div>

<!-- MAIN SLIDER -->
<div id="mainSlider">
  <div class="sl-hd">
    <div><div class="sl-title" id="slTitle">Add Inventory Item</div><div class="sl-sub" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-cl" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-bd" id="slBody"></div>
  <div class="sl-ft">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-send"></i> Submit</button>
  </div>
</div>

<!-- VIEW MODAL -->
<div id="viewModal">
  <div class="vm-box">
    <div class="vm-hd">
      <div class="vm-top">
        <div style="display:flex;align-items:center;gap:14px;">
          <div class="vm-av" id="vmAv"><i class="bx bx-package"></i></div>
          <div><div class="vm-nm" id="vmNm">—</div><div class="vm-meta" id="vmMeta">—</div></div>
        </div>
        <button class="vm-cl" id="vmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="vm-chips" id="vmChips"></div>
      <div class="vm-tabs">
        <button class="vm-tab active" data-vt="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <button class="vm-tab" data-vt="st"><i class="bx bx-bar-chart-alt-2"></i> Stock Levels</button>
      </div>
    </div>
    <div class="vm-bd"><div class="vm-tp active" id="vt-ov"></div><div class="vm-tp" id="vt-st"></div></div>
    <div class="vm-ft" id="vmFoot"></div>
  </div>
</div>

<!-- CONFIRM MODAL -->
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

<!-- CC VIEW MODAL -->
<div id="ccViewModal">
  <div class="vm-box">
    <div class="vm-hd">
      <div class="vm-top">
        <div style="display:flex;align-items:center;gap:14px;">
          <div class="vm-av" id="ccVmAv" style="background:#2563EB"><i class="bx bx-list-check"></i></div>
          <div><div class="vm-nm" id="ccVmNm">—</div><div class="vm-meta" id="ccVmMeta">—</div></div>
        </div>
        <button class="vm-cl" id="ccVmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="vm-chips" id="ccVmChips"></div>
      <div class="vm-tabs">
        <button class="vm-tab active" data-cvt="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <button class="vm-tab" data-cvt="hist"><i class="bx bx-time"></i> History</button>
      </div>
    </div>
    <div class="vm-bd"><div class="vm-tp active" id="cvt-ov"></div><div class="vm-tp" id="cvt-hist"></div></div>
    <div class="vm-ft" id="ccVmFoot"></div>
  </div>
</div>

<!-- CC OVERLAY -->
<div id="ccSlOverlay"></div>

<!-- CC SLIDER -->
<div id="ccSlider">
  <div class="sl-hd">
    <div><div class="sl-title" id="ccSlTitle">New Cycle Count</div><div class="sl-sub" id="ccSlSub">Record a physical inventory count</div></div>
    <button class="sl-cl" id="ccSlClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-bd" id="ccSlBody"></div>
  <div class="sl-ft">
    <button class="btn btn-ghost btn-sm" id="ccSlCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="ccSlSubmit"><i class="bx bx-send"></i> Submit</button>
  </div>
</div>

<script>
// ── ROLE CONTEXT FROM PHP ─────────────────────────────────────────────────────
const ROLE      = '<?= addslashes($swsRoleName) ?>';
const USER_ZONE = '<?= addslashes((string)$swsUserZone) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── STATE ─────────────────────────────────────────────────────────────────────
let INV=[], CC=[], ZONES=[];
let sortCol='code', sortDir='asc', page=1;
const PAGE=10;
let selectedIds=new Set();
let sliderMode=null, sliderTargetId=null, confirmCb=null;
let ccSortCol='countDate', ccSortDir='desc', ccPage=1;
const CC_PAGE=10;
let ccSel=new Set();
let ccSliderMode=null, ccSliderTargetId=null;

// ── CONSTANTS ─────────────────────────────────────────────────────────────────
const CATS_DEFAULT=['Raw Materials','Safety & PPE','Fuels & Lubricants','Office Supplies','Electrical & IT','Tools & Equipment','Finished Goods','Chemicals','Spare Parts'];
function getCategories(){
    const fromInv=[...new Set(INV.map(i=>i.category).filter(Boolean))].sort();
    // Merge live categories with defaults, deduped
    return [...new Set([...fromInv,...CATS_DEFAULT])].sort();
}
const UOMS=['pcs','sets','bags','rolls','liters','kg','meters','pairs','reams','cans','boxes','sqm'];

// ── LOAD ALL ──────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        [ZONES, INV, CC] = await Promise.all([
            apiGet(API+'?api=zones'),
            apiGet(API+'?api=inventory'),
            apiGet(API+'?api=cycle_counts'),
        ]);
    } catch(e) { toast('Failed to load data: '+e.message,'d'); }
    switchTab('inv');
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD  = d => { if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const zn  = id => ZONES.find(z=>z.id===id) || {name:id||'—', color:'#6B7280'};

function badge(st) {
    const m={'In Stock':'b-in','Low Stock':'b-low','Out of Stock':'b-out','Overstocked':'b-over','Inactive':'b-inact'};
    return `<span class="badge ${m[st]||''}">${st}</span>`;
}
function stockFillClass(st) {
    return {'In Stock':'sfill-in','Low Stock':'sfill-low','Out of Stock':'sfill-out','Overstocked':'sfill-over'}[st]||'sfill-in';
}
function stockPct(it) { return it.max===0?0:Math.min(100,Math.round((it.stock/it.max)*100)); }

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered() {
    const q=document.getElementById('srch').value.trim().toLowerCase();
    const fz=document.getElementById('fZone').value;
    const fc=document.getElementById('fCat').value;
    const fs=document.getElementById('fStat').value;
    const ff=document.getElementById('fFrom').value;
    const ft=document.getElementById('fTo').value;
    return INV.filter(it=>{
        if(q&&!it.code.toLowerCase().includes(q)&&!it.name.toLowerCase().includes(q)&&!it.bin.toLowerCase().includes(q)) return false;
        if(fz&&it.zone!==fz) return false;
        if(fc&&it.category!==fc) return false;
        if(fs&&it.status!==fs) return false;
        if(ff&&it.lastRestocked<ff) return false;
        if(ft&&it.lastRestocked>ft) return false;
        return true;
    });
}
function getSorted(list) {
    return [...list].sort((a,b)=>{
        let va=a[sortCol], vb=b[sortCol];
        if(['stock','min','max','rop'].includes(sortCol)) return sortDir==='asc'?va-vb:vb-va;
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── RENDER INVENTORY ──────────────────────────────────────────────────────────
function renderStats() {
    const active=INV.filter(i=>i.active).length;
    const inStk=INV.filter(i=>i.status==='In Stock').length;
    const low=INV.filter(i=>i.status==='Low Stock').length;
    const out=INV.filter(i=>i.status==='Out of Stock').length;
    const over=INV.filter(i=>i.status==='Overstocked').length;
    document.getElementById('sumGrid').innerHTML=`
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-package"></i></div><div><div class="sc-v">${active}</div><div class="sc-l">Total Active Items</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${inStk}</div><div class="sc-l">In Stock</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-error"></i></div><div><div class="sc-v">${low}</div><div class="sc-l">Low Stock</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-x-circle"></i></div><div><div class="sc-v">${out}</div><div class="sc-l">Out of Stock</div></div></div>
        <div class="sc"><div class="sc-ic ic-p"><i class="bx bx-trending-up"></i></div><div><div class="sc-v">${over}</div><div class="sc-l">Overstocked</div></div></div>`;
}

function buildDropdowns() {
    const zones=[...new Set(INV.map(i=>i.zone))].sort();
    const cats=[...new Set(INV.map(i=>i.category))].sort();
    const fz=document.getElementById('fZone'); const fzv=fz.value;
    fz.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option value="${z}" ${z===fzv?'selected':''}>${zn(z).name}</option>`).join('');
    const fc=document.getElementById('fCat'); const fcv=fc.value;
    fc.innerHTML='<option value="">All Categories</option>'+cats.map(c=>`<option ${c===fcv?'selected':''}>${c}</option>`).join('');
}

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
        tb.innerHTML=`<tr><td colspan="14"><div class="empty"><i class="bx bx-package"></i><p>No inventory items found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(it=>{
            const z=zn(it.zone); const chk=selectedIds.has(it.code);
            return `<tr class="${chk?'row-sel':''}" data-id="${it.code}">
                <td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${it.code}" ${chk?'checked':''}></div></td>
                <td onclick="openView('${it.code}')"><span class="code-cell">${esc(it.code)}</span></td>
                <td onclick="openView('${it.code}')" style="max-width:160px;overflow:hidden;text-overflow:ellipsis" title="${esc(it.name)}"><div class="name-cell" style="overflow:hidden;text-overflow:ellipsis">${esc(it.name)}</div></td>
                <td onclick="openView('${it.code}')" style="max-width:100px;overflow:hidden;text-overflow:ellipsis" title="${esc(it.category)}">${esc(it.category)}</td>
                <td onclick="openView('${it.code}')" style="color:var(--t2);font-size:12px">${esc(it.uom)}</td>
                <td onclick="openView('${it.code}')"><div class="zone-pill"><div class="zone-dot" style="background:${z.color}"></div>${it.zone}</div></td>
                <td onclick="openView('${it.code}')"><span class="mono" style="font-size:11.5px">${esc(it.bin)}</span></td>
                <td onclick="openView('${it.code}')"><span class="num-cell" style="${it.status==='Low Stock'?'color:#D97706':it.status==='Out of Stock'?'color:#DC2626':it.status==='Overstocked'?'color:#2563EB':''}">${it.stock.toLocaleString()}</span></td>
                <td onclick="openView('${it.code}')"><span class="num-cell" style="font-weight:500;color:var(--t3)">${it.min}</span></td>
                <td onclick="openView('${it.code}')"><span class="num-cell" style="font-weight:500;color:var(--t3)">${it.max}</span></td>
                <td onclick="openView('${it.code}')"><span class="num-cell" style="font-weight:500;color:var(--t2)">${it.rop}</span></td>
                <td onclick="openView('${it.code}')"><span class="date-cell">${fD(it.lastRestocked)}</span></td>
                <td onclick="openView('${it.code}')">${badge(it.status)}</td>
                <td onclick="event.stopPropagation()">
                    <div class="act-cell">
                        <button class="btn ionly" onclick="openView('${it.code}')" title="View"><i class="bx bx-show"></i></button>
                        ${ROLE==='User'||ROLE==='Staff' ? '' : `<button class="btn ionly" onclick="openSlider('edit','${it.code}')" title="Edit"><i class="bx bx-edit"></i></button>`}
                        <button class="btn ionly btn-blue" onclick="openSlider('adjust','${it.code}')" title="Adjust Stock"><i class="bx bx-transfer-alt"></i></button>
                        ${ROLE==='Manager'||ROLE==='Admin'||ROLE==='Super Admin' ? `<button class="btn ionly btn-warn" onclick="openSlider('transfer','${it.code}')" title="Transfer"><i class="bx bx-move"></i></button>` : ''}
                        ${ROLE==='Admin'||ROLE==='Super Admin' ? `<button class="btn ionly btn-danger" onclick="confirmDeactivate('${it.code}')" title="${it.active?'Deactivate':'Activate'}"><i class="bx ${it.active?'bx-block':'bx-check'}"></i></button>` : ''}
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
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} items`}</span>
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
['srch','fZone','fCat','fStat','fFrom','fTo'].forEach(id=>
    document.getElementById(id).addEventListener('input',()=>{page=1;renderList();})
);

// ── BULK BAR ──────────────────────────────────────────────────────────────────
function updateBulkBar(){
    const n=selectedIds.size;
    document.getElementById('bulkBar').classList.toggle('on',n>0);
    document.getElementById('bulkCt').textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice){
    const ca=document.getElementById('checkAll');
    const ids=slice.map(p=>p.code);
    const allChk=ids.length>0&&ids.every(id=>selectedIds.has(id));
    const someChk=ids.some(id=>selectedIds.has(id));
    ca.checked=allChk; ca.indeterminate=!allChk&&someChk;
}
document.getElementById('checkAll').addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(p=>{if(this.checked) selectedIds.add(p.code); else selectedIds.delete(p.code);});
    renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});
document.getElementById('bExport').addEventListener('click',()=>{ doExport([...selectedIds]); toast(`Exported ${selectedIds.size} item(s).`,'s'); });
document.getElementById('bPrint').addEventListener('click',()=>toast(`Print queued for ${selectedIds.size} item(s).`,'s'));
document.getElementById('bBatchAdj').addEventListener('click',()=>toast(`Batch adjust for ${selectedIds.size} item(s) — coming soon.`,'w'));

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function setVmTab(name){
    document.querySelectorAll('.vm-tab[data-vt]').forEach(t=>t.classList.toggle('active',t.dataset.vt===name));
    document.querySelectorAll('#viewModal .vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
}
document.querySelectorAll('.vm-tab[data-vt]').forEach(t=>t.addEventListener('click',()=>setVmTab(t.dataset.vt)));
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this)closeView();});

function openView(code){
    const it=INV.find(i=>i.code===code); if(!it)return;
    const z=zn(it.zone); const pct=stockPct(it);
    document.getElementById('vmNm').textContent=it.name;
    document.getElementById('vmMeta').textContent=`${it.code} · ${it.category}`;
    document.getElementById('vmChips').innerHTML=`
        <div class="vm-chip"><i class="bx bx-grid-alt"></i>${z.name}</div>
        <div class="vm-chip"><i class="bx bx-map-pin"></i>Bin ${it.bin}</div>
        <div class="vm-chip"><i class="bx bx-ruler"></i>${it.uom}</div>
        <div class="vm-chip"><i class="bx bx-calendar"></i>Restocked ${fD(it.lastRestocked)}</div>`;
    document.getElementById('vt-ov').innerHTML=`
        <div class="vm-sbs">
            <div class="vm-sb"><div class="sbv">${it.stock.toLocaleString()}</div><div class="sbl">Current Stock</div></div>
            <div class="vm-sb"><div class="sbv">${it.min}</div><div class="sbl">Min Level</div></div>
            <div class="vm-sb"><div class="sbv">${it.max}</div><div class="sbl">Max Level</div></div>
            <div class="vm-sb"><div class="sbv">${it.rop}</div><div class="sbl">Reorder Point</div></div>
        </div>
        <div class="vm-ig">
            <div class="vm-ii"><label>Item Code</label><div class="v mono">${esc(it.code)}</div></div>
            <div class="vm-ii"><label>Category</label><div class="v">${esc(it.category)}</div></div>
            <div class="vm-ii"><label>Zone</label><div class="v" style="color:${z.color}">${z.name}</div></div>
            <div class="vm-ii"><label>Bin Number</label><div class="v mono">${esc(it.bin)}</div></div>
            <div class="vm-ii"><label>Unit of Measure</label><div class="v">${esc(it.uom)}</div></div>
            <div class="vm-ii"><label>Last Restocked</label><div class="vm">${fD(it.lastRestocked)}</div></div>
            <div class="vm-ii"><label>Status</label><div class="v">${badge(it.status)}</div></div>
            <div class="vm-ii"><label>Active</label><div class="v">${it.active?'<span style="color:#166534;font-weight:700">Yes</span>':'<span style="color:#991B1B;font-weight:700">No (Inactive)</span>'}</div></div>
        </div>`;
    document.getElementById('vt-st').innerHTML=`
        <div style="background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:18px 20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                <span style="font-size:13px;font-weight:700;color:var(--t1)">Stock Level</span>
                <div><span style="font-family:'DM Mono',monospace;font-size:22px;font-weight:800">${it.stock.toLocaleString()}</span><span style="font-size:12px;color:var(--t2);margin-left:4px">${it.uom}</span></div>
            </div>
            <div class="stk-bar-track" style="height:10px;border-radius:6px"><div class="stk-bar-fill ${stockFillClass(it.status)}" style="width:${pct}%;height:100%;border-radius:6px"></div></div>
            <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:11px;color:var(--t3);font-family:'DM Mono',monospace">
                <span>0</span><span>Min ${it.min}</span><span>ROP ${it.rop}</span><span>Max ${it.max}</span>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
            <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px;text-align:center">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:4px">Available</div>
                <div style="font-size:20px;font-weight:800;color:var(--t1)">${Math.max(0,it.stock-it.min).toLocaleString()}</div>
                <div style="font-size:11px;color:var(--t3)">above min</div>
            </div>
            <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px;text-align:center">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:4px">To Max</div>
                <div style="font-size:20px;font-weight:800;color:var(--t1)">${Math.max(0,it.max-it.stock).toLocaleString()}</div>
                <div style="font-size:11px;color:var(--t3)">units needed</div>
            </div>
            <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px;text-align:center">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:4px">Fill Rate</div>
                <div style="font-size:20px;font-weight:800;color:var(--t1)">${pct}%</div>
                <div style="font-size:11px;color:var(--t3)">of max capacity</div>
            </div>
        </div>`;
    document.getElementById('vmFoot').innerHTML=`
        <button class="btn btn-ghost btn-sm" onclick="closeView();openSlider('edit','${it.code}')"><i class="bx bx-edit"></i> Edit</button>
        <button class="btn btn-ghost btn-sm" onclick="closeView();openSlider('adjust','${it.code}')"><i class="bx bx-transfer-alt"></i> Adjust Stock</button>
        <button class="btn btn-ghost btn-sm" onclick="closeView();openSlider('transfer','${it.code}')"><i class="bx bx-move"></i> Transfer</button>
        <button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;
    setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
}
function closeView(){document.getElementById('viewModal').classList.remove('on');}

// ── SLIDER ────────────────────────────────────────────────────────────────────
function openSlider(mode, code){
    sliderMode=mode; sliderTargetId=code;
    const it=code?INV.find(i=>i.code===code):null;
    const titles={add:'Add Inventory Item',edit:'Edit Item',adjust:'Adjust Stock',transfer:'Transfer Item'};
    const subs={add:'Fill in all required fields below',edit:'Update item details',adjust:'Increase or decrease current stock level',transfer:'Move item to another zone or bin'};
    document.getElementById('slTitle').textContent=titles[mode];
    document.getElementById('slSub').textContent=subs[mode];
    document.getElementById('slBody').innerHTML=buildSliderBody(mode,it);
    if(mode==='adjust'){
        document.getElementById('adjMinus').addEventListener('click',()=>{const v=document.getElementById('adjQty');v.value=Math.max(0,+v.value-1);});
        document.getElementById('adjPlus').addEventListener('click',()=>{const v=document.getElementById('adjQty');v.value=+v.value+1;});
    }
    document.getElementById('mainSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(()=>{const f=document.getElementById('slBody').querySelector('input:not([readonly]),select');if(f)f.focus();},350);

    // Auto-generate item code for new items
    if(mode==='add'){
        apiGet(API+'?api=next_code').then(d=>{
            const el=document.getElementById('fCode');
            if(el){ el.value=d.code; el.removeAttribute('readonly'); el.style.background=''; el.style.color=''; }
        }).catch(()=>{ const el=document.getElementById('fCode'); if(el){ el.removeAttribute('readonly'); el.placeholder='e.g. ITM-0031'; } });

        // Wire zone change to auto-suggest bin
        setTimeout(()=>{
            const zoneEl=document.getElementById('fZoneSl');
            if(zoneEl){
                function suggestBin(){
                    const binEl=document.getElementById('fBin');
                    if(!binEl||binEl.dataset.manual==='1') return;
                    const zid=zoneEl.value; // e.g. ZN-A01
                    const letter=zid.match(/ZN-([A-Z])/)?.[1]||'A';
                    // Count existing items in this zone to suggest next slot
                    const inZone=INV.filter(i=>i.zone===zid).length+1;
                    const row=Math.ceil(inZone/4); // 4 items per row
                    const slot=((inZone-1)%4)+1;
                    binEl.value=`${letter}-${String(slot).padStart(2,'0')}-R${row}`;
                }
                suggestBin();
                zoneEl.addEventListener('change', suggestBin);
                // Mark as manually edited if user types
                const binEl=document.getElementById('fBin');
                if(binEl) binEl.addEventListener('input',()=>binEl.dataset.manual='1');
            }
        },100);
    }
}

function buildSliderBody(mode,it){
    const zoneOpts=ZONES.map(z=>`<option value="${z.id}" ${it&&it.zone===z.id?'selected':''}>${z.name}</option>`).join('');
    if(mode==='add'||mode==='edit'){
        return `
            <div class="fg2">
                <div class="fg"><label class="fl">Item Code</label><input type="text" class="fi" id="fCode" placeholder="Generating…" value="${it?esc(it.code):''}" style="font-family:'DM Mono',monospace" ${it?'':'readonly'}></div>
                <div class="fg"><label class="fl">Item Name <span>*</span></label><input type="text" class="fi" id="fName" placeholder="Item name" value="${it?esc(it.name):''}"></div>
            </div>
            <div class="fg2">
                <div class="fg"><label class="fl">Category <span>*</span></label>
                    <input type="text" class="fi" id="fCatSl" list="catList" placeholder="Select or type category…" value="${it?esc(it.category):''}">
                    <datalist id="catList">${getCategories().map(c=>`<option value="${c}"></option>`).join('')}</datalist>
                </div>
                <div class="fg"><label class="fl">Unit of Measure <span>*</span></label><select class="fs" id="fUom">${UOMS.map(u=>`<option ${it&&it.uom===u?'selected':''}>${u}</option>`).join('')}</select></div>
            </div>
            <div class="fdiv">Location</div>
            <div class="fg2">
                <div class="fg"><label class="fl">Zone <span>*</span></label><select class="fs" id="fZoneSl">${zoneOpts}</select></div>
                <div class="fg"><label class="fl">Bin Number</label><input type="text" class="fi" id="fBin" placeholder="Auto-generated" value="${it?esc(it.bin):''}" style="${it?'':'background:var(--bg);color:var(--t2);font-family:\'DM Mono\',monospace'}"></div>
            </div>
            <div class="fdiv">Stock Levels</div>
            <div class="fg3">
                <div class="fg"><label class="fl">Current Stock</label><input type="number" class="fi" id="fStock" min="0" value="${it?it.stock:0}"></div>
                <div class="fg"><label class="fl">Min Level <span>*</span></label><input type="number" class="fi" id="fMin" min="0" value="${it?it.min:0}"></div>
                <div class="fg"><label class="fl">Max Level <span>*</span></label><input type="number" class="fi" id="fMax" min="1" value="${it?it.max:100}"></div>
            </div>
            <div class="fg2">
                <div class="fg"><label class="fl">Reorder Point <span>*</span></label><input type="number" class="fi" id="fRop" min="0" value="${it?it.rop:0}"></div>
                <div class="fg"><label class="fl">Last Restocked</label><input type="date" class="fi" id="fDate" value="${it?it.lastRestocked:''}"></div>
            </div>
            <div class="fg2">
                <div class="fg"><label class="fl">Status</label><select class="fs" id="fActiveSl"><option value="1" ${!it||it.active?'selected':''}>Active</option><option value="0" ${it&&!it.active?'selected':''}>Inactive</option></select></div>
            </div>`;
    }
    if(mode==='adjust'){
        const cur=it?it.stock:0;
        return `
            <div style="background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px">
                <div style="width:40px;height:40px;border-radius:10px;background:var(--grn);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;flex-shrink:0"><i class="bx bx-package"></i></div>
                <div><div style="font-weight:700;color:var(--t1)">${esc(it.name)}</div><div style="font-size:11.5px;color:var(--t3);font-family:'DM Mono',monospace">${esc(it.code)} · ${it.uom}</div></div>
            </div>
            <div class="fg"><label class="fl">Adjustment Type <span>*</span></label>
                <select class="fs" id="adjType">
                    <option value="add">Add Stock (Receive)</option>
                    <option value="remove">Remove Stock (Consume / Damage)</option>
                    <option value="set">Set Exact Quantity</option>
                </select>
            </div>
            <div class="adj-row">
                <div class="adj-label">Quantity<br><span style="font-size:11px;color:var(--t3)">Current: ${cur.toLocaleString()} ${it.uom}</span></div>
                <div class="adj-ctrl">
                    <button class="adj-btn" id="adjMinus"><i class="bx bx-minus"></i></button>
                    <input type="number" class="adj-val" id="adjQty" value="1" min="0">
                    <button class="adj-btn" id="adjPlus"><i class="bx bx-plus"></i></button>
                </div>
            </div>
            <div class="fg"><label class="fl">Reason / Notes</label><textarea class="fta" id="adjNote" placeholder="e.g. Monthly restock delivery, damaged during transport…"></textarea></div>`;
    }
    if(mode==='transfer'){
        const toZoneOpts=ZONES.filter(z=>z.id!==it.zone).map(z=>`<option value="${z.id}">${z.name}</option>`).join('');
        return `
            <div style="background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px">
                <div style="width:40px;height:40px;border-radius:10px;background:var(--grn);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;flex-shrink:0"><i class="bx bx-move"></i></div>
                <div><div style="font-weight:700;color:var(--t1)">${esc(it.name)}</div><div style="font-size:11.5px;color:var(--t3)">${esc(it.zone)} · ${esc(it.bin)}</div></div>
            </div>
            <div class="fg2">
                <div class="fg"><label class="fl">From Zone</label><input type="text" class="fi" value="${esc(zn(it.zone).name)}" disabled></div>
                <div class="fg"><label class="fl">From Bin</label><input type="text" class="fi" value="${esc(it.bin)}" disabled></div>
            </div>
            <div class="fdiv">Transfer Destination</div>
            <div class="fg2">
                <div class="fg"><label class="fl">To Zone <span>*</span></label><select class="fs" id="tZone">${toZoneOpts}</select></div>
                <div class="fg"><label class="fl">To Bin <span>*</span></label><input type="text" class="fi" id="tBin" placeholder="e.g. B-01-R3"></div>
            </div>
            <div class="fg2">
                <div class="fg"><label class="fl">Quantity to Transfer <span>*</span></label><input type="number" class="fi" id="tQty" min="1" max="${it.stock}" value="${Math.min(1,it.stock)}"><div class="fhint">Available: ${it.stock.toLocaleString()} ${it.uom}</div></div>
                <div class="fg"><label class="fl">Transfer Date</label><input type="date" class="fi" id="tDate" value="${new Date().toISOString().split('T')[0]}"></div>
            </div>
            <div class="fg"><label class="fl">Notes</label><textarea class="fta" id="tNote" placeholder="Reason for transfer…"></textarea></div>`;
    }
    return '';
}

function closeSlider(){
    document.getElementById('mainSlider').classList.remove('on');
    document.getElementById('slOverlay').classList.remove('on');
    sliderMode=null; sliderTargetId=null;
}
document.getElementById('slOverlay').addEventListener('click',closeSlider);
document.getElementById('slClose').addEventListener('click',closeSlider);
document.getElementById('slCancel').addEventListener('click',closeSlider);

document.getElementById('slSubmit').addEventListener('click',async()=>{
    const mode=sliderMode;
    const it=sliderTargetId?INV.find(i=>i.code===sliderTargetId):null;
    const btn=document.getElementById('slSubmit'); btn.disabled=true;
    try {
        if(mode==='add'||mode==='edit'){
            const code=document.getElementById('fCode')?.value.trim();
            const name=document.getElementById('fName')?.value.trim();
            const zone=document.getElementById('fZoneSl')?.value;
            // bin is auto-generated — fallback if still empty
            let bin=document.getElementById('fBin')?.value.trim();
            if(!bin){
                const letter=zone?.match(/ZN-([A-Z])/)?.[1]||'A';
                const inZone=INV.filter(i=>i.zone===zone).length+1;
                const row=Math.ceil(inZone/4);
                const slot=((inZone-1)%4)+1;
                bin=`${letter}-${String(slot).padStart(2,'0')}-R${row}`;
                const binEl=document.getElementById('fBin');
                if(binEl) binEl.value=bin;
            }
            if(!code){toast('Item code is generating, please wait…','w');btn.disabled=false;return;}
            if(!name){toast('Item name is required.','w');btn.disabled=false;return;}
            if(!zone){toast('Please select a zone.','w');btn.disabled=false;return;}
            const payload={
                code,name,
                category:document.getElementById('fCatSl')?.value,
                uom:document.getElementById('fUom')?.value,
                zone,bin,
                stock:+document.getElementById('fStock')?.value||0,
                min:+document.getElementById('fMin')?.value||0,
                max:+document.getElementById('fMax')?.value||100,
                rop:+document.getElementById('fRop')?.value||0,
                lastRestocked:document.getElementById('fDate')?.value||new Date().toISOString().split('T')[0],
                active:document.getElementById('fActiveSl')?.value==='1',
            };
            if(it) payload.id=it.id;
            const saved=await apiPost(API+'?api=save_item',payload);
            // Update or insert in INV
            const idx=INV.findIndex(i=>i.code===saved.code);
            if(idx>-1) INV[idx]=saved; else INV.unshift(saved);
            toast(`${saved.code} ${it?'updated':'added'} successfully.`,'s');
        }
        else if(mode==='adjust'){
            if(!it) return;
            const updated=await apiPost(API+'?api=adjust',{
                id:it.id,
                type:document.getElementById('adjType')?.value,
                qty:+document.getElementById('adjQty')?.value||0,
                notes:document.getElementById('adjNote')?.value.trim(),
            });
            const idx=INV.findIndex(i=>i.id===updated.id); if(idx>-1) INV[idx]=updated;
            toast(`Stock adjusted. New qty: ${updated.stock.toLocaleString()}.`,'s');
        }
        else if(mode==='transfer'){
            if(!it) return;
            const qty=+document.getElementById('tQty')?.value||0;
            const toBin=document.getElementById('tBin')?.value.trim();
            if(!toBin){toast('Destination bin is required.','w');return;}
            if(qty<1||qty>it.stock){toast(`Transfer qty must be between 1 and ${it.stock}.`,'w');return;}
            const updated=await apiPost(API+'?api=transfer',{
                id:it.id, qty,
                toZone:document.getElementById('tZone')?.value,
                toBin,
                notes:document.getElementById('tNote')?.value.trim(),
            });
            const idx=INV.findIndex(i=>i.id===updated.id); if(idx>-1) INV[idx]=updated;
            toast(`${qty} ${it.uom} of "${it.name}" transferred.`,'s');
        }
        closeSlider(); renderList();
        if(document.getElementById('viewModal').classList.contains('on')&&sliderTargetId) openView(sliderTargetId);
    } catch(e){ toast(e.message,'d'); }
    finally{ btn.disabled=false; }
});

// ── DEACTIVATE ────────────────────────────────────────────────────────────────
function confirmDeactivate(code){
    const it=INV.find(i=>i.code===code); if(!it) return;
    const action=it.active?'Deactivate':'Activate';
    document.getElementById('cmIcon').textContent=it.active?'⛔':'✅';
    document.getElementById('cmTitle').textContent=`${action} Item`;
    document.getElementById('cmBody').innerHTML=`${action} <strong>${esc(it.name)}</strong> (${esc(it.code)})?`;
    document.getElementById('cmConfirm').className=`btn btn-sm ${it.active?'btn-ghost':'btn-primary'}`;
    document.getElementById('cmConfirm').textContent=action;
    confirmCb=async()=>{
        try{
            const updated=await apiPost(API+'?api=toggle_active',{id:it.id});
            const idx=INV.findIndex(i=>i.id===updated.id); if(idx>-1) INV[idx]=updated;
            renderList(); toast(`${it.code} ${updated.active?'activated':'deactivated'}.`,'s');
        }catch(e){toast(e.message,'d');}
    };
    document.getElementById('confirmModal').classList.add('on');
}
document.getElementById('cmConfirm').addEventListener('click',async()=>{if(confirmCb)await confirmCb();document.getElementById('confirmModal').classList.remove('on');confirmCb=null;});
document.getElementById('cmCancel').addEventListener('click',()=>{document.getElementById('confirmModal').classList.remove('on');confirmCb=null;});
document.getElementById('confirmModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');confirmCb=null;}});

// ── EXPORT ────────────────────────────────────────────────────────────────────
function doExport(ids){
    const list=ids.length?INV.filter(i=>ids.includes(i.code)):getFiltered();
    const hdrs=['Code','Name','Category','UOM','Zone','Bin','Stock','Min','Max','ROP','Last Restocked','Status'];
    const cols=['code','name','category','uom','zone','bin','stock','min','max','rop','lastRestocked'];
    const rows=[hdrs.join(','),...list.map(it=>[...cols.map(c=>`"${String(it[c]||'').replace(/"/g,'""')}"`),`"${it.status}"`].join(','))];
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='inventory.csv'; a.click();
}
// exportBtn and printBtn are rendered dynamically via switchTab

// ── CYCLE COUNT ───────────────────────────────────────────────────────────────
function ccBadge(st){
    const m={Matched:'b-matched',Over:'b-over',Short:'b-short',Flagged:'b-flagged',Approved:'b-approved',Rejected:'b-rejected',Pending:'b-pending'};
    return `<span class="badge ${m[st]||''}">${st}</span>`;
}
function varCell(v){
    if(v===0) return `<span class="var-zero">0</span>`;
    return v>0?`<span class="var-pos">+${v}</span>`:`<span class="var-neg">${v}</span>`;
}

function ccGetFiltered(){
    const q=document.getElementById('ccSrch').value.trim().toLowerCase();
    const fz=document.getElementById('ccFZone').value;
    const fc=document.getElementById('ccFCat').value;
    const fs=document.getElementById('ccFStat').value;
    const ff=document.getElementById('ccFrom').value;
    const ft=document.getElementById('ccTo').value;
    return CC.filter(r=>{
        if(q&&!r.itemCode.toLowerCase().includes(q)&&!r.itemName.toLowerCase().includes(q)) return false;
        if(fz&&r.zone!==fz) return false;
        if(fc&&r.category!==fc) return false;
        if(fs&&r.status!==fs) return false;
        if(ff&&r.countDate<ff) return false;
        if(ft&&r.countDate>ft) return false;
        return true;
    });
}
function ccGetSorted(list){
    return [...list].sort((a,b)=>{
        let va=a[ccSortCol], vb=b[ccSortCol];
        if(['physicalCount','systemCount','variance'].includes(ccSortCol)) return ccSortDir==='asc'?va-vb:vb-va;
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return ccSortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

function ccRenderStats(){
    const total=CC.length;
    const matched=CC.filter(r=>r.status==='Matched'||r.status==='Approved').length;
    const shorts=CC.filter(r=>r.status==='Short').length;
    const flagged=CC.filter(r=>r.status==='Flagged'||r.status==='Pending').length;
    document.getElementById('ccSumGrid').innerHTML=`
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-list-check"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">Total Count Records</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${matched}</div><div class="sc-l">Matched / Approved</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-down-arrow-circle"></i></div><div><div class="sc-v">${shorts}</div><div class="sc-l">Short</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-error-alt"></i></div><div><div class="sc-v">${flagged}</div><div class="sc-l">Flagged / Pending</div></div></div>`;
}

function ccBuildDropdowns(){
    const zones=[...new Set(CC.map(r=>r.zone))].sort();
    const cats=[...new Set(CC.map(r=>r.category))].sort();
    const fz=document.getElementById('ccFZone'); const fzv=fz.value;
    fz.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option value="${z}" ${z===fzv?'selected':''}>${zn(z).name}</option>`).join('');
    const fc=document.getElementById('ccFCat'); const fcv=fc.value;
    fc.innerHTML='<option value="">All Categories</option>'+cats.map(c=>`<option ${c===fcv?'selected':''}>${c}</option>`).join('');
}

function ccRenderList(){
    ccRenderStats(); ccBuildDropdowns();
    const data=ccGetSorted(ccGetFiltered()), total=data.length;
    const pages=Math.max(1,Math.ceil(total/CC_PAGE));
    if(ccPage>pages) ccPage=pages;
    const slice=data.slice((ccPage-1)*CC_PAGE,ccPage*CC_PAGE);
    document.querySelectorAll('#ccTbl thead th[data-ccol]').forEach(th=>{
        const col=th.dataset.ccol;
        th.classList.toggle('sorted',col===ccSortCol);
        const ic=th.querySelector('.si-c');
        if(ic) ic.className=`bx ${col===ccSortCol?(ccSortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} si-c`;
    });
    const tb=document.getElementById('ccTbody');
    if(!slice.length){
        tb.innerHTML=`<tr><td colspan="11"><div class="empty"><i class="bx bx-list-check"></i><p>No cycle count records found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(r=>{
            const chk=ccSel.has(r.recordNo); const z=zn(r.zone);
            const canAct=!['Approved','Rejected'].includes(r.status);
            return `<tr class="${chk?'row-sel':''}" data-ccid="${r.recordNo}">
                <td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb cc-row-cb" data-id="${r.recordNo}" ${chk?'checked':''}></div></td>
                <td onclick="ccOpenView('${r.recordNo}')"><span class="date-cell">${fD(r.countDate)}</span></td>
                <td onclick="ccOpenView('${r.recordNo}')"><span class="code-cell">${esc(r.itemCode)}</span></td>
                <td onclick="ccOpenView('${r.recordNo}')" style="max-width:160px;overflow:hidden;text-overflow:ellipsis" title="${esc(r.itemName)}"><span class="name-cell">${esc(r.itemName)}</span></td>
                <td onclick="ccOpenView('${r.recordNo}')" style="max-width:110px;overflow:hidden;text-overflow:ellipsis" title="${esc(r.category)}">${esc(r.category)}</td>
                <td onclick="ccOpenView('${r.recordNo}')"><div class="zone-pill"><div class="zone-dot" style="background:${z.color}"></div>${r.zone}</div></td>
                <td onclick="ccOpenView('${r.recordNo}')" style="text-align:right"><span class="num-cell">${r.physicalCount.toLocaleString()}</span></td>
                <td onclick="ccOpenView('${r.recordNo}')" style="text-align:right"><span class="num-cell" style="color:var(--t2)">${r.systemCount.toLocaleString()}</span></td>
                <td onclick="ccOpenView('${r.recordNo}')" style="text-align:right">${varCell(r.variance)}</td>
                <td onclick="ccOpenView('${r.recordNo}')">${ccBadge(r.status)}</td>
                <td onclick="event.stopPropagation()">
                    <div class="act-cell">
                        <button class="btn ionly" onclick="ccOpenView('${r.recordNo}')" title="View"><i class="bx bx-show"></i></button>
                        <button class="btn ionly" onclick="ccOpenSlider('edit','${r.recordNo}')" title="Edit" ${!canAct?'disabled style="opacity:.4;pointer-events:none"':''}><i class="bx bx-edit"></i></button>
                        <button class="btn ionly btn-blue" onclick="ccAction('approve','${r.recordNo}')" title="Approve" ${!canAct?'disabled style="opacity:.4;pointer-events:none"':''}><i class="bx bx-check"></i></button>
                        <button class="btn ionly btn-danger" onclick="ccAction('reject','${r.recordNo}')" title="Reject" ${!canAct?'disabled style="opacity:.4;pointer-events:none"':''}><i class="bx bx-x"></i></button>
                        <button class="btn ionly btn-warn" onclick="ccAction('flag','${r.recordNo}')" title="Flag" ${r.status==='Flagged'?'disabled style="opacity:.4;pointer-events:none"':''}><i class="bx bx-flag"></i></button>
                        <button class="btn ionly btn-blue" onclick="ccAction('override','${r.recordNo}')" title="Override"><i class="bx bx-transfer-alt"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
        document.querySelectorAll('.cc-row-cb').forEach(cb=>{
            cb.addEventListener('change',function(){
                const id=this.dataset.id;
                if(this.checked) ccSel.add(id); else ccSel.delete(id);
                this.closest('tr').classList.toggle('row-sel',this.checked);
                ccUpdateBulkBar(); ccSyncCheckAll(slice);
            });
        });
    }
    ccSyncCheckAll(slice);
    const s=(ccPage-1)*CC_PAGE+1, e=Math.min(ccPage*CC_PAGE,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=ccPage-2&&i<=ccPage+2)) btns+=`<button class="pgb ${i===ccPage?'active':''}" onclick="ccGoPage(${i})">${i}</button>`;
        else if(i===ccPage-3||i===ccPage+3) btns+=`<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('ccPager').innerHTML=`
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} records`}</span>
        <div class="pg-btns">
            <button class="pgb" onclick="ccGoPage(${ccPage-1})" ${ccPage<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
            ${btns}
            <button class="pgb" onclick="ccGoPage(${ccPage+1})" ${ccPage>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
        </div>`;
}
window.ccGoPage=p=>{ccPage=p;ccRenderList();};

document.querySelectorAll('#ccTbl thead th[data-ccol]').forEach(th=>{
    th.addEventListener('click',()=>{
        const col=th.dataset.ccol;
        ccSortDir=ccSortCol===col?(ccSortDir==='asc'?'desc':'asc'):'asc';
        ccSortCol=col; ccPage=1; ccRenderList();
    });
});
['ccSrch','ccFZone','ccFCat','ccFStat','ccFrom','ccTo'].forEach(id=>{
    const el=document.getElementById(id);
    if(el) el.addEventListener('input',()=>{ccPage=1;ccRenderList();});
});

function ccUpdateBulkBar(){
    const n=ccSel.size;
    document.getElementById('ccBulkBar').classList.toggle('on',n>0);
    document.getElementById('ccBulkCt').textContent=n===1?'1 selected':`${n} selected`;
}
function ccSyncCheckAll(slice){
    const ca=document.getElementById('ccCheckAll');
    const ids=slice.map(r=>r.recordNo);
    const allChk=ids.length>0&&ids.every(id=>ccSel.has(id));
    const someChk=ids.some(id=>ccSel.has(id));
    ca.checked=allChk; ca.indeterminate=!allChk&&someChk;
}
document.getElementById('ccCheckAll').addEventListener('change',function(){
    const slice=ccGetSorted(ccGetFiltered()).slice((ccPage-1)*CC_PAGE,ccPage*CC_PAGE);
    slice.forEach(r=>{if(this.checked) ccSel.add(r.recordNo); else ccSel.delete(r.recordNo);});
    ccRenderList(); ccUpdateBulkBar();
});
document.getElementById('ccClearSel').addEventListener('click',()=>{ccSel.clear();ccRenderList();ccUpdateBulkBar();});
document.getElementById('ccBExport').addEventListener('click',()=>toast(`Exported ${ccSel.size} cycle count record(s).`,'s'));
document.getElementById('ccBPrint').addEventListener('click',()=>toast(`Print queued for ${ccSel.size} record(s).`,'s'));
document.getElementById('ccBBatchApprove').addEventListener('click',async()=>{
    let n=0;
    const promises=[];
    ccSel.forEach(rno=>{
        const r=CC.find(x=>x.recordNo===rno);
        if(r&&!['Approved','Rejected'].includes(r.status)){
            promises.push(apiPost(API+'?api=cc_action',{id:r.id,type:'approve'}).then(updated=>{
                const idx=CC.findIndex(x=>x.id===updated.id); if(idx>-1){CC[idx]=updated;n++;}
            }));
        }
    });
    try{ await Promise.all(promises); ccSel.clear(); ccRenderList(); ccUpdateBulkBar(); toast(`${n} record(s) approved.`,'s'); }
    catch(e){ toast(e.message,'d'); }
});

// ── CC ACTIONS ────────────────────────────────────────────────────────────────
function ccAction(type,rno){
    const r=CC.find(x=>x.recordNo===rno); if(!r) return;
    const labels={approve:'Approve',reject:'Reject',flag:'Flag for Review',override:'Override Count'};
    const icons={approve:'✅',reject:'❌',flag:'🚩',override:'🔄'};
    document.getElementById('cmIcon').textContent=icons[type];
    document.getElementById('cmTitle').textContent=labels[type];
    document.getElementById('cmBody').innerHTML=`${labels[type]} count record <strong>${esc(r.recordNo)}</strong> for <strong>${esc(r.itemName)}</strong>?`;
    document.getElementById('cmConfirm').textContent=labels[type];
    document.getElementById('cmConfirm').className=`btn btn-sm ${type==='reject'?'btn-ghost':'btn-primary'}`;
    confirmCb=async()=>{
        try{
            const updated=await apiPost(API+'?api=cc_action',{id:r.id,type});
            const idx=CC.findIndex(x=>x.id===updated.id); if(idx>-1) CC[idx]=updated;
            ccRenderList();
            toast(`Record ${r.recordNo} ${type==='approve'?'approved':type==='reject'?'rejected':type==='flag'?'flagged':'overridden'}.`,'s');
        }catch(e){toast(e.message,'d');}
    };
    document.getElementById('confirmModal').classList.add('on');
}

// ── CC VIEW MODAL ─────────────────────────────────────────────────────────────
function setCcVmTab(name){
    document.querySelectorAll('#ccViewModal .vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.cvt===name));
    document.querySelectorAll('#ccViewModal .vm-tp').forEach(p=>p.classList.toggle('active',p.id==='cvt-'+name));
}
document.querySelectorAll('#ccViewModal .vm-tab').forEach(t=>t.addEventListener('click',()=>setCcVmTab(t.dataset.cvt)));
document.getElementById('ccVmClose').addEventListener('click',()=>document.getElementById('ccViewModal').classList.remove('on'));
document.getElementById('ccViewModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});

function ccOpenView(rno){
    const r=CC.find(x=>x.recordNo===rno); if(!r) return;
    const z=zn(r.zone);
    document.getElementById('ccVmNm').textContent=r.itemName;
    document.getElementById('ccVmMeta').textContent=`${r.recordNo} · ${r.itemCode} · ${r.countDate}`;
    document.getElementById('ccVmChips').innerHTML=`
        <div class="vm-chip"><i class="bx bx-grid-alt"></i>${z.name}</div>
        <div class="vm-chip"><i class="bx bx-category"></i>${r.category}</div>
        <div class="vm-chip"><i class="bx bx-user"></i>Counted by ${esc(r.countedBy)}</div>
        <div class="vm-chip"><i class="bx bx-calendar"></i>${fD(r.countDate)}</div>`;
    document.getElementById('cvt-ov').innerHTML=`
        <div class="vm-sbs" style="grid-template-columns:repeat(3,1fr)">
            <div class="vm-sb"><div class="sbv">${r.physicalCount.toLocaleString()}</div><div class="sbl">Physical Count</div></div>
            <div class="vm-sb"><div class="sbv" style="color:var(--t2)">${r.systemCount.toLocaleString()}</div><div class="sbl">System Count</div></div>
            <div class="vm-sb"><div class="sbv ${r.variance>0?'var-pos':r.variance<0?'var-neg':'var-zero'}">${r.variance>0?'+':''}${r.variance}</div><div class="sbl">Variance</div></div>
        </div>
        <div class="vm-ig">
            <div class="vm-ii"><label>Record ID</label><div class="v mono">${esc(r.recordNo)}</div></div>
            <div class="vm-ii"><label>Item Code</label><div class="v mono">${esc(r.itemCode)}</div></div>
            <div class="vm-ii"><label>Zone</label><div class="v" style="color:${z.color}">${z.name}</div></div>
            <div class="vm-ii"><label>Category</label><div class="v">${esc(r.category)}</div></div>
            <div class="vm-ii"><label>UOM</label><div class="vm">${esc(r.uom)}</div></div>
            <div class="vm-ii"><label>Status</label><div class="v">${ccBadge(r.status)}</div></div>
            <div class="vm-ii"><label>Counted By</label><div class="vm">${esc(r.countedBy)}</div></div>
            <div class="vm-ii"><label>Approved By</label><div class="vm">${r.approvedBy||'—'}</div></div>
            <div class="vm-ii vm-full"><label>Notes</label><div class="vm">${r.notes||'—'}</div></div>
        </div>`;
    document.getElementById('cvt-hist').innerHTML=`
        <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:16px 18px">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:12px">Activity Timeline</div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <div style="display:flex;gap:10px;align-items:flex-start">
                    <div style="width:8px;height:8px;border-radius:50%;background:var(--grn);margin-top:4px;flex-shrink:0"></div>
                    <div><div style="font-size:12.5px;font-weight:600">Count submitted</div><div style="font-size:11px;color:var(--t3)">${fD(r.countDate)} · ${esc(r.countedBy)}</div></div>
                </div>
                ${r.approvedDate?`<div style="display:flex;gap:10px;align-items:flex-start">
                    <div style="width:8px;height:8px;border-radius:50%;background:${r.status==='Rejected'?'#DC2626':'#2563EB'};margin-top:4px;flex-shrink:0"></div>
                    <div><div style="font-size:12.5px;font-weight:600">${r.status} by ${esc(r.approvedBy)}</div><div style="font-size:11px;color:var(--t3)">${fD(r.approvedDate)}</div></div>
                </div>`:'<div style="display:flex;gap:10px;align-items:flex-start"><div style="width:8px;height:8px;border-radius:50%;background:var(--t3);margin-top:4px;flex-shrink:0"></div><div style="font-size:12.5px;color:var(--t2)">Awaiting review</div></div>'}
            </div>
        </div>`;
    const canAct=!['Approved','Rejected'].includes(r.status);
    document.getElementById('ccVmFoot').innerHTML=`
        ${canAct?`<button class="btn btn-ghost btn-sm" onclick="document.getElementById('ccViewModal').classList.remove('on');ccOpenSlider('edit','${r.recordNo}')"><i class="bx bx-edit"></i> Edit</button>`:''}
        ${canAct?`<button class="btn btn-primary btn-sm" onclick="document.getElementById('ccViewModal').classList.remove('on');ccAction('approve','${r.recordNo}')"><i class="bx bx-check"></i> Approve</button>`:''}
        ${canAct?`<button class="btn btn-ghost btn-sm" onclick="document.getElementById('ccViewModal').classList.remove('on');ccAction('reject','${r.recordNo}')"><i class="bx bx-x"></i> Reject</button>`:''}
        ${canAct?`<button class="btn btn-ghost btn-sm" onclick="document.getElementById('ccViewModal').classList.remove('on');ccAction('flag','${r.recordNo}')"><i class="bx bx-flag"></i> Flag</button>`:''}
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('ccViewModal').classList.remove('on')">Close</button>`;
    setCcVmTab('ov');
    document.getElementById('ccViewModal').classList.add('on');
}

// ── CC SLIDER ─────────────────────────────────────────────────────────────────
function ccOpenSlider(mode,rno){
    ccSliderMode=mode; ccSliderTargetId=rno;
    const r=rno?CC.find(x=>x.recordNo===rno):null;
    document.getElementById('ccSlTitle').textContent=mode==='add'?'New Cycle Count':'Edit Count Record';
    document.getElementById('ccSlSub').textContent=mode==='add'?'Record a physical inventory count':'Update count details';
    const itemArr=INV.filter(i=>i.active);
    const zoneOpts=ZONES.map(z=>`<option value="${z.id}" ${r&&r.zone===z.id?'selected':''}>${z.name}</option>`).join('');
    const statOpts=['Pending','Matched','Over','Short','Flagged'].map(s=>`<option ${r&&r.status===s?'selected':''}>${s}</option>`).join('');
    document.getElementById('ccSlBody').innerHTML=`
        <div class="fg2">
            <div class="fg"><label class="fl">Count Date <span>*</span></label><input type="date" class="fi" id="ccFDate" value="${r?r.countDate:new Date().toISOString().split('T')[0]}"></div>
            <div class="fg"><label class="fl">Counted By <span>*</span></label><input type="text" class="fi" id="ccFBy" placeholder="Staff name" value="${r?esc(r.countedBy):''}"></div>
        </div>
        <div class="fg"><label class="fl">Item <span>*</span></label>
            <div class="cs-wrap" id="csWrap">
                <input type="text" class="cs-input" id="ccFItemSearch" placeholder="Search item code or name…" autocomplete="off" value="${r?r.itemCode+' — '+r.itemName:''}">
                <input type="hidden" id="ccFItem" value="${r?r.itemCode:''}">
                <div class="cs-drop" id="csDropdown"></div>
            </div>
        </div>
        <div class="fg2">
            <div class="fg"><label class="fl">Zone <span>*</span></label><select class="fs" id="ccFZoneSl">${zoneOpts}</select></div>
            <div class="fg"><label class="fl">Status</label><select class="fs" id="ccFStat">${statOpts}</select></div>
        </div>
        <div class="fdiv">Count Values</div>
        <div class="fg2">
            <div class="fg"><label class="fl">Physical Count <span>*</span></label><input type="number" class="fi" id="ccFPhys" min="0" value="${r?r.physicalCount:0}"></div>
            <div class="fg"><label class="fl">System Count <span>*</span></label><input type="number" class="fi" id="ccFSys" min="0" value="${r?r.systemCount:0}"></div>
        </div>
        <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:4px">Variance Preview</div>
            <div id="ccVarVal" style="font-size:22px;font-weight:800">—</div>
        </div>
        <div class="fg"><label class="fl">Notes</label><textarea class="fta" id="ccFNotes" placeholder="Observations, discrepancies…">${r?esc(r.notes):''}</textarea></div>`;

    function updateVar(){
        const p=+document.getElementById('ccFPhys').value||0;
        const s=+document.getElementById('ccFSys').value||0;
        const v=p-s;
        const el=document.getElementById('ccVarVal');
        el.textContent=v>0?`+${v}`:String(v);
        el.className=v>0?'var-pos':v<0?'var-neg':'var-zero';
    }
    document.getElementById('ccFPhys').addEventListener('input',updateVar);
    document.getElementById('ccFSys').addEventListener('input',updateVar);
    updateVar();

    // ── Custom searchable item select ────────────────────────────────────────
    const csInput=document.getElementById('ccFItemSearch');
    const csHidden=document.getElementById('ccFItem');
    const csDrop=document.getElementById('csDropdown');
    let csHl=-1;

    function csRender(q){
        const lq=(q||'').toLowerCase();
        const filtered=itemArr.filter(i=>
            i.code.toLowerCase().includes(lq)||i.name.toLowerCase().includes(lq)
        ).slice(0,60);
        if(!filtered.length){
            csDrop.innerHTML='<div class="cs-opt cs-none">No items found</div>';
        } else {
            csDrop.innerHTML=filtered.map(i=>
                `<div class="cs-opt" data-code="${i.code}" data-name="${esc(i.name)}">
                    <span class="cs-code">${esc(i.code)}</span>
                    <span class="cs-name">${esc(i.name)}</span>
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

    function csSelect(code,name){
        csHidden.value=code;
        csInput.value=code+' — '+name;
        csDrop.classList.remove('open');
        const inv=INV.find(i=>i.code===code);
        if(inv){
            const zEl=document.getElementById('ccFZoneSl');
            if(zEl) zEl.value=inv.zone;
        }
    }

    if(csInput){
        csInput.addEventListener('focus',()=>{ csRender(csInput.value); csDrop.classList.add('open'); });
        csInput.addEventListener('input',()=>{ csHidden.value=''; csRender(csInput.value); csDrop.classList.add('open'); });
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
    }

    document.getElementById('ccSlOverlay').classList.add('on');
    document.getElementById('ccSlider').classList.add('on');
    setTimeout(()=>document.getElementById('ccFDate').focus(),350);
}
function ccCloseSlider(){
    document.getElementById('ccSlider').classList.remove('on');
    document.getElementById('ccSlOverlay').classList.remove('on');
    ccSliderMode=null; ccSliderTargetId=null;
}
document.getElementById('ccSlClose').addEventListener('click',ccCloseSlider);
document.getElementById('ccSlOverlay').addEventListener('click',ccCloseSlider);
document.getElementById('ccSlCancel').addEventListener('click',ccCloseSlider);

document.getElementById('ccSlSubmit').addEventListener('click',async()=>{
    const date=document.getElementById('ccFDate').value;
    const by=document.getElementById('ccFBy').value.trim();
    const itemCode=document.getElementById('ccFItem').value.trim();
    const zone=document.getElementById('ccFZoneSl').value;
    const stat=document.getElementById('ccFStat').value;
    const phys=+document.getElementById('ccFPhys').value||0;
    const sys=+document.getElementById('ccFSys').value||0;
    const notes=document.getElementById('ccFNotes').value.trim();
    if(!date||!by||!itemCode){toast('Fill in all required fields.','w');return;}
    const inv=INV.find(i=>i.code===itemCode)||{name:itemCode,category:'—',uom:'pcs'};
    const btn=document.getElementById('ccSlSubmit'); btn.disabled=true;
    try{
        const payload={
            countDate:date, itemCode, itemName:inv.name, category:inv.category,
            uom:inv.uom, zone, physicalCount:phys, systemCount:sys,
            notes, countedBy:by, status:stat,
        };
        if(ccSliderMode==='edit'){
            const r=CC.find(x=>x.recordNo===ccSliderTargetId);
            if(r) payload.id=r.id;
        }
        const saved=await apiPost(API+'?api=save_cc',payload);
        const idx=CC.findIndex(x=>x.id===saved.id);
        if(idx>-1) CC[idx]=saved; else CC.unshift(saved);
        toast(`${saved.recordNo} ${ccSliderMode==='add'?'added':'updated'}.`,'s');
        ccCloseSlider(); ccRenderList();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
});

// ── TAB SWITCHER ──────────────────────────────────────────────────────────────
function switchTab(tab){
    document.getElementById('secInv').style.display=tab==='inv'?'':'none';
    document.getElementById('secCyc').style.display=tab==='cyc'?'':'none';
    document.getElementById('tabInv').classList.toggle('active',tab==='inv');
    document.getElementById('tabCyc').classList.toggle('active',tab==='cyc');
    if(tab==='inv'){
        document.getElementById('phActions').innerHTML=`
            <button class="btn btn-ghost" onclick="doExport([]);toast('Inventory exported.','s')"><i class="bx bx-export"></i> Export</button>
            <button class="btn btn-ghost" onclick="window.print()"><i class="bx bx-printer"></i> Print</button>
            <button class="btn btn-primary" onclick="openSlider('add',null)"><i class="bx bx-plus"></i> Add Item</button>`;
        renderList();
    } else {
        document.getElementById('phActions').innerHTML=`
            <button class="btn btn-ghost" onclick="toast('Cycle count exported.','s')"><i class="bx bx-export"></i> Export</button>
            <button class="btn btn-ghost" onclick="window.print()"><i class="bx bx-printer"></i> Print</button>
            <button class="btn btn-primary" onclick="ccOpenSlider('add',null)"><i class="bx bx-plus"></i> New Count</button>`;
        ccRenderList();
    }
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