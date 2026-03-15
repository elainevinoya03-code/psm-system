<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function sws_loc_resolve_role(): string {
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

$locRoleName = sws_loc_resolve_role();
$locRoleRank = match($locRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$locUserZone = $_SESSION['zone'] ?? '';
$locUserId   = $_SESSION['user_id'] ?? null;

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
    if ($query) {
        $parts = [];
        foreach ($query as $k => $v) {
            if (preg_match('/^([a-z]+\.)(.+)$/', (string)$v, $m)) {
                $parts[] = urlencode($k) . '=' . $m[1] . rawurlencode($m[2]);
            } else {
                $parts[] = urlencode($k) . '=' . rawurlencode((string)$v);
            }
        }
        $url .= '?' . implode('&', $parts);
    }
    $prefer = ($method === 'DELETE') ? 'return=minimal' : 'return=representation';
    $headers = [
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: ' . $prefer,
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
    if ($method === 'DELETE') {
        if ($code >= 400) {
            $data = json_decode($res, true);
            $msg  = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
            sws_err('Supabase DELETE: ' . $msg, 400);
        }
        return [];
    }
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

function sws_build_bin(array $row): array {
    $items = $row['items'] ?? null;
    if ($items === null || $items === '{}' || $items === '') {
        $items = [];
    } elseif (is_string($items)) {
        $trimmed = trim($items);
        if ($trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            $items = is_array($decoded) ? $decoded : [];
        } else {
            $inner = trim($trimmed, '{}');
            if ($inner === '') {
                $items = [];
            } else {
                $items = array_map(fn($s) => trim($s, '"'), str_getcsv($inner));
            }
        }
    } elseif (!is_array($items)) {
        $items = [];
    }
    $items = array_values(array_filter($items, fn($i) => $i !== null && $i !== ''));
    $used     = (int)($row['used']     ?? 0);
    $capacity = max(1, (int)($row['capacity'] ?? 1));
    return [
        'id'        => (int)$row['id'],
        'binId'     => $row['bin_id']    ?? '',
        'code'      => $row['code']      ?? '',
        'zone'      => $row['zone']      ?? '',
        'zoneName'  => $row['zone_name'] ?? '',
        'zoneColor' => $row['zone_color'] ?? '#6B7280',
        'row'       => $row['row']       ?? '',
        'level'     => $row['level']     ?? '',
        'capacity'  => $capacity,
        'used'      => $used,
        'utilPct'   => min(100, (int)round(($used / $capacity) * 100)),
        'status'    => $row['status']    ?? 'Available',
        'active'    => (bool)($row['active'] ?? true),
        'notes'     => $row['notes']     ?? '',
        'items'     => $items,
        'createdAt' => $row['created_at'] ?? '',
        'updatedAt' => $row['updated_at'] ?? '',
    ];
}

function sws_fetch_bin(int $binId): array {
    $rows = sws_sb('sws_bins', 'GET', [
        'select' => 'id,bin_id,code,zone,row,level,capacity,used,status,active,notes,created_at,updated_at',
        'id'     => 'eq.' . $binId,
        'limit'  => '1',
    ]);
    if (empty($rows)) sws_err('Bin not found after save', 500);
    $bin = $rows[0];
    $zoneRows = sws_sb('sws_zones', 'GET', [
        'select' => 'id,name,color',
        'id'     => 'eq.' . $bin['zone'],
        'limit'  => '1',
    ]);
    $bin['zone_name']  = !empty($zoneRows) ? $zoneRows[0]['name']  : $bin['zone'];
    $bin['zone_color'] = !empty($zoneRows) ? $zoneRows[0]['color'] : '#6B7280';
    $itemRows = sws_sb('sws_bin_items', 'GET', [
        'select'  => 'item_name',
        'bin_id'  => 'eq.' . $binId,
        'order'   => 'item_name.asc',
    ]);
    $bin['items'] = array_column($itemRows, 'item_name');
    return sws_build_bin($bin);
}

function sws_next_bin_id(): string {
    $rows = sws_sb('sws_bins', 'GET', [
        'select' => 'bin_id',
        'order'  => 'id.desc',
        'limit'  => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/BIN-(\d+)/', $rows[0]['bin_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return sprintf('BIN-%04d', $next);
}

function sws_derive_status(array $bin): string {
    if (!$bin['active']) return 'Inactive';
    if (empty($bin['items']) && $bin['used'] == 0) return 'Available';
    return $bin['status'] === 'Reserved' ? 'Reserved' : 'Occupied';
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        if ($api === 'debug_bin' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) sws_err('Missing id', 400);
            $bin   = sws_sb('sws_bins',      'GET', ['select' => '*', 'id' => 'eq.' . $id, 'limit' => '1']);
            $items = sws_sb('sws_bin_items', 'GET', ['select' => '*', 'bin_id' => 'eq.' . $id]);
            sws_ok(['bin' => $bin[0] ?? null, 'items' => $items, 'built' => $id ? sws_fetch_bin($id) : null]);
        }

        if ($api === 'inv_items' && $method === 'GET') {
            $invQuery = [
                'select' => 'id,code,name,category,uom,zone',
                'active' => 'eq.true',
                'order'  => 'name.asc',
            ];
            if (($locRoleName === 'Manager' || $locRoleName === 'Staff') && $locUserZone) {
                $invQuery['zone'] = 'eq.' . $locUserZone;
            }
            $rows = sws_sb('sws_inventory', 'GET', $invQuery);
            sws_ok($rows);
        }

        if ($api === 'zones' && $method === 'GET') {
            $zoneQuery = ['select' => 'id,name,color', 'order' => 'id.asc'];
            if (($locRoleName === 'Manager' || $locRoleName === 'Staff') && $locUserZone) {
                $zoneQuery['id'] = 'eq.' . $locUserZone;
            }
            $rows = sws_sb('sws_zones', 'GET', $zoneQuery);
            sws_ok($rows);
        }

        if ($api === 'save_zone' && $method === 'POST') {
            // Only Admin / Super Admin can add or edit zones
            if ($locRoleRank < 3) {
                sws_err('Not authorized to manage zones', 403);
            }
            $b        = sws_body();
            $id       = strtoupper(trim($b['id']    ?? ''));
            $name     = trim($b['name']  ?? '');
            $color    = trim($b['color'] ?? '#2E7D32');
            $editMode = (bool)($b['edit'] ?? false);

            if (!$id)   sws_err('Zone ID is required', 400);
            if (!$name) sws_err('Zone name is required', 400);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#2E7D32';

            if ($editMode) {
                sws_sb('sws_zones', 'PATCH', ['id' => 'eq.' . $id], [
                    'name'  => $name,
                    'color' => $color,
                ]);
            } else {
                $existing = sws_sb('sws_zones', 'GET', ['select' => 'id', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (!empty($existing)) sws_err("Zone ID '{$id}' already exists", 409);
                sws_sb('sws_zones', 'POST', [], [[
                    'id'    => $id,
                    'name'  => $name,
                    'color' => $color,
                ]]);
            }

            $rows = sws_sb('sws_zones', 'GET', ['select' => 'id,name,color', 'id' => 'eq.' . $id, 'limit' => '1']);
            sws_ok($rows[0] ?? ['id' => $id, 'name' => $name, 'color' => $color]);
        }

        if ($api === 'delete_zone' && $method === 'POST') {
            // Only Super Admin can delete zones
            if ($locRoleRank < 4) {
                sws_err('Not authorized to delete zones', 403);
            }
            $b  = sws_body();
            $id = strtoupper(trim($b['id'] ?? ''));
            if (!$id) sws_err('Zone ID is required', 400);
            $bins = sws_sb('sws_bins', 'GET', ['select' => 'id', 'zone' => 'eq.' . $id, 'limit' => '1']);
            if (!empty($bins)) sws_err('Cannot delete zone — it has bins assigned to it. Reassign or delete those bins first.', 409);
            sws_sb('sws_zones', 'DELETE', ['id' => 'eq.' . $id]);
            sws_ok(['deleted' => true, 'id' => $id]);
        }

        if ($api === 'bins' && $method === 'GET') {
            $binQuery = [
                'select' => 'id,bin_id,code,zone,row,level,capacity,used,status,active,notes,created_at,updated_at,created_user_id',
                'order'  => 'code.asc',
            ];
            if (($locRoleName === 'Manager' || $locRoleName === 'Staff') && $locUserZone) {
                $binQuery['zone'] = 'eq.' . $locUserZone;
            }
            $binRows = sws_sb('sws_bins', 'GET', $binQuery);
            if (empty($binRows)) { sws_ok([]); }
            $zoneRows = sws_sb('sws_zones', 'GET', ['select' => 'id,name,color']);
            $zoneMap  = [];
            foreach ($zoneRows as $z) $zoneMap[$z['id']] = $z;
            $allItems = sws_sb('sws_bin_items', 'GET', ['select' => 'bin_id,item_name', 'order' => 'item_name.asc']);
            $itemMap  = [];
            foreach ($allItems as $item) {
                $itemMap[$item['bin_id']][] = $item['item_name'];
            }
            $result = [];
            foreach ($binRows as $bin) {
                $zid = $bin['zone'];
                $bin['zone_name']  = $zoneMap[$zid]['name']  ?? $zid;
                $bin['zone_color'] = $zoneMap[$zid]['color'] ?? '#6B7280';
                $bin['items']      = $itemMap[$bin['id']] ?? [];
                $result[] = sws_build_bin($bin);
            }
            sws_ok($result);
        }

        if ($api === 'bin' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) sws_err('Missing id', 400);
            sws_ok(sws_fetch_bin($id));
        }

        if ($api === 'next_bin_id' && $method === 'GET') {
            sws_ok(['binId' => sws_next_bin_id()]);
        }

        if ($api === 'save_bin' && $method === 'POST') {
            $b = sws_body();
            $zone     = trim($b['zone']     ?? '');
            $row      = trim($b['row']      ?? '');
            $level    = trim($b['level']    ?? '');
            $capacity = max(1, (int)($b['capacity'] ?? 100));
            $used     = max(0, (int)($b['used']     ?? 0));
            $status   = trim($b['status']   ?? 'Available');
            $active   = (bool)($b['active'] ?? true);
            $notes    = trim($b['notes']    ?? '');
            $items    = array_filter(array_map('trim', (array)($b['items'] ?? [])));
            $editId   = (int)($b['id'] ?? 0);
            $now      = date('Y-m-d H:i:s');

            if (($locRoleName === 'Manager' || $locRoleName === 'Staff') && $locUserZone) {
                $zone = $locUserZone;
            }
            if (!$zone)  sws_err('Zone is required', 400);
            if (!$row)   sws_err('Row is required', 400);
            if (!$level) sws_err('Level is required', 400);

            $allowedStatus = ['Occupied', 'Available', 'Reserved', 'Inactive'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Available';

            $zoneParts = explode('-', $zone);
            $zoneShort = $zoneParts[1] ?? $zone;
            $code = "{$zoneShort}-{$row}-{$level}";

            // Role: Manager can edit only within zone; Staff/User cannot add bins
            if ($locRoleRank <= 1 && !$editId) {
                sws_err('Not authorized to add bins', 403);
            }

            $payload = [
                'code'       => $code,
                'zone'       => $zone,
                'row'        => $row,
                'level'      => $level,
                'capacity'   => $capacity,
                'used'       => $used,
                'status'     => $status,
                'active'     => $active,
                'notes'      => $notes,
                'updated_at' => $now,
            ];

            if ($editId) {
                // For Manager, ensure bin is in their zone
                if ($locRoleName === 'Manager' && $locUserZone) {
                    $cur = sws_sb('sws_bins', 'GET', [
                        'select' => 'id,zone',
                        'id'     => 'eq.' . $editId,
                        'limit'  => '1',
                    ]);
                    if (empty($cur) || ($cur[0]['zone'] ?? '') !== $locUserZone) {
                        sws_err('Not authorized to edit this bin', 403);
                    }
                }
                sws_sb('sws_bins', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                sws_sb('sws_bin_items', 'DELETE', ['bin_id' => 'eq.' . $editId]);
                $itemRows = [];
                foreach (array_values($items) as $itemName) {
                    if ($itemName === '') continue;
                    $row_data = ['bin_id' => $editId, 'item_name' => $itemName];
                    $invRows = sws_sb('sws_inventory', 'GET', [
                        'select' => 'id', 'name' => 'eq.' . $itemName, 'limit' => '1',
                    ]);
                    if (!empty($invRows)) $row_data['item_id'] = (int)$invRows[0]['id'];
                    $itemRows[] = $row_data;
                }
                if (!empty($itemRows)) {
                    sws_sb('sws_bin_items', 'POST', [], $itemRows);
                }
                sws_sb('sws_bin_audit', 'POST', [], [[
                    'bin_id'      => $editId,
                    'action'      => 'edit',
                    'detail'      => "Bin {$code} updated — " . count($itemRows) . " item(s) assigned",
                    'actor_name'  => $actor,
                    'ip_address'  => $ip,
                    'occurred_at' => $now,
                ]]);
                sws_ok(sws_fetch_bin($editId));
            }

            $payload['bin_id']          = sws_next_bin_id();
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $_SESSION['user_id'] ?? null;
            $payload['created_at']      = $now;

            $inserted = sws_sb('sws_bins', 'POST', [], [$payload]);
            if (empty($inserted)) sws_err('Failed to create bin', 500);
            $newId = (int)$inserted[0]['id'];

            $itemRows = [];
            foreach (array_values($items) as $itemName) {
                if ($itemName === '') continue;
                $row_data = ['bin_id' => $newId, 'item_name' => $itemName];
                $invRows = sws_sb('sws_inventory', 'GET', [
                    'select' => 'id', 'name' => 'eq.' . $itemName, 'limit' => '1',
                ]);
                if (!empty($invRows)) $row_data['item_id'] = (int)$invRows[0]['id'];
                $itemRows[] = $row_data;
            }
            if (!empty($itemRows)) {
                sws_sb('sws_bin_items', 'POST', [], $itemRows);
            }

            sws_sb('sws_bin_audit', 'POST', [], [[
                'bin_id'     => $newId,
                'action'     => 'create',
                'detail'     => "Bin {$code} created",
                'actor_name' => $actor,
                'ip_address' => $ip,
                'occurred_at'=> $now,
            ]]);

            $rows = sws_fetch_bin($newId);
            sws_ok($rows);
        }

        if ($api === 'toggle_bin_active' && $method === 'POST') {
            // Only Admin / Super Admin can activate/deactivate bins
            if ($locRoleRank < 3) {
                sws_err('Not authorized to change bin activation', 403);
            }
            $b   = sws_body();
            $id  = (int)($b['id'] ?? 0);
            $now = date('Y-m-d H:i:s');
            if (!$id) sws_err('Missing id', 400);
            $rows = sws_sb('sws_bins', 'GET', [
                'select' => 'id,bin_id,code,active',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) sws_err('Bin not found', 404);
            $newActive = !((bool)$rows[0]['active']);
            $newStatus = $newActive ? 'Available' : 'Inactive';
            sws_sb('sws_bins', 'PATCH', ['id' => 'eq.' . $id], [
                'active'     => $newActive,
                'status'     => $newStatus,
                'updated_at' => $now,
            ]);
            sws_sb('sws_bin_audit', 'POST', [], [[
                'bin_id'     => $id,
                'action'     => $newActive ? 'activate' : 'deactivate',
                'detail'     => "Bin {$rows[0]['code']} " . ($newActive ? 'activated' : 'deactivated'),
                'actor_name' => $actor,
                'ip_address' => $ip,
                'occurred_at'=> $now,
            ]]);
            sws_ok(sws_fetch_bin($id));
        }

        if ($api === 'delete_bin' && $method === 'POST') {
            // Only Super Admin can permanently delete bins
            if ($locRoleRank < 4) {
                sws_err('Not authorized to delete bins', 403);
            }
            $b  = sws_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) sws_err('Missing id', 400);
            $rows = sws_sb('sws_bins', 'GET', [
                'select' => 'id,code',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) sws_err('Bin not found', 404);
            $code = $rows[0]['code'];
            sws_sb('sws_bins', 'DELETE', ['id' => 'eq.' . $id]);
            sws_ok(['deleted' => true, 'code' => $code]);
        }

        if ($api === 'reassign_item' && $method === 'POST') {
            $b        = sws_body();
            $srcId    = (int)($b['srcId']    ?? 0);
            $dstId    = (int)($b['dstId']    ?? 0);
            $itemName = trim($b['itemName']   ?? '');
            $notes    = trim($b['notes']      ?? '');
            $now      = date('Y-m-d H:i:s');

            if (!$srcId)    sws_err('Source bin id required', 400);
            if (!$dstId)    sws_err('Destination bin id required', 400);
            if (!$itemName) sws_err('Item name required', 400);
            if ($srcId === $dstId) sws_err('Source and destination must differ', 400);

            $srcRows = sws_sb('sws_bins', 'GET', ['select' => 'id,code,status', 'id' => 'eq.' . $srcId, 'limit' => '1']);
            $dstRows = sws_sb('sws_bins', 'GET', ['select' => 'id,code,status,active', 'id' => 'eq.' . $dstId, 'limit' => '1']);
            if (empty($srcRows)) sws_err('Source bin not found', 404);
            if (empty($dstRows)) sws_err('Destination bin not found', 404);
            if (!$dstRows[0]['active']) sws_err('Destination bin is inactive', 400);

            sws_sb('sws_bin_items', 'DELETE', [
                'bin_id'    => 'eq.' . $srcId,
                'item_name' => 'eq.' . $itemName,
            ]);

            $srcItems = sws_sb('sws_bin_items', 'GET', ['select' => 'id', 'bin_id' => 'eq.' . $srcId]);
            if (empty($srcItems) && $srcRows[0]['status'] === 'Occupied') {
                sws_sb('sws_bins', 'PATCH', ['id' => 'eq.' . $srcId], [
                    'status'     => 'Available',
                    'updated_at' => $now,
                ]);
            }

            $invRows = sws_sb('sws_inventory', 'GET', [
                'select' => 'id',
                'name'   => 'eq.' . $itemName,
                'limit'  => '1',
            ]);
            $itemId = !empty($invRows) ? (int)$invRows[0]['id'] : null;

            $existCheck = sws_sb('sws_bin_items', 'GET', [
                'select'    => 'id',
                'bin_id'    => 'eq.' . $dstId,
                'item_name' => 'eq.' . $itemName,
                'limit'     => '1',
            ]);
            if (empty($existCheck)) {
                $dst_row = ['bin_id' => $dstId, 'item_name' => $itemName];
                if ($itemId !== null) $dst_row['item_id'] = $itemId;
                sws_sb('sws_bin_items', 'POST', [], [$dst_row]);
            }

            if ($dstRows[0]['status'] === 'Available') {
                sws_sb('sws_bins', 'PATCH', ['id' => 'eq.' . $dstId], [
                    'status'     => 'Occupied',
                    'updated_at' => $now,
                ]);
            }

            $srcCode = $srcRows[0]['code'];
            $dstCode = $dstRows[0]['code'];
            sws_sb('sws_bin_audit', 'POST', [], [
                [
                    'bin_id'     => $srcId,
                    'action'     => 'reassign',
                    'detail'     => "'{$itemName}' moved to {$dstCode}" . ($notes ? " — {$notes}" : ''),
                    'actor_name' => $actor,
                    'ip_address' => $ip,
                    'occurred_at'=> $now,
                ],
                [
                    'bin_id'     => $dstId,
                    'action'     => 'reassign',
                    'detail'     => "'{$itemName}' received from {$srcCode}" . ($notes ? " — {$notes}" : ''),
                    'actor_name' => $actor,
                    'ip_address' => $ip,
                    'occurred_at'=> $now,
                ],
            ]);

            sws_ok([
                'src' => sws_fetch_bin($srcId),
                'dst' => sws_fetch_bin($dstId),
            ]);
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
<title>Bin &amp; Location Mapping — SWS</title>
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:   #F3F6F2; --s:#FFFFFF; --t1:#1A2B1C; --t2:#5D7263; --t3:#9EB5A4;
  --bd:   rgba(46,125,50,.12); --bdm:rgba(46,125,50,.22);
  --grn:  #2E7D32; --gdk:#1B5E20; --gxl:#E8F5E9;
  --amb:  #D97706; --red:#DC2626; --blu:#2563EB; --pur:#7C3AED; --tea:#0D9488;
  --shsm: 0 1px 4px rgba(46,125,50,.08);
  --shmd: 0 4px 20px rgba(46,125,50,.11);
  --shlg: 0 12px 40px rgba(0,0,0,.14);
  --rad:  14px; --tr:all .18s ease;
  --occ:#16a34a; --occ-bg:#dcfce7; --occ-bd:#86efac;
  --avl:#2563eb; --avl-bg:#eff6ff; --avl-bd:#bfdbfe;
  --res:#d97706; --res-bg:#fef3c7; --res-bd:#fde68a;
  --ina:#6b7280; --ina-bg:#f3f4f6; --ina-bd:#e5e7eb;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;}
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
.btn-sm{font-size:12px;padding:6px 13px}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2)}
.btn.ionly:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn-danger-ghost{background:var(--s);color:var(--red);border:1px solid #FECACA}.btn-danger-ghost:hover{background:#FEE2E2;border-color:var(--red)}
.btn:disabled{opacity:.4;pointer-events:none}
.sum-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;animation:UP .4s .05s both}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:16px 18px;box-shadow:var(--shsm);display:flex;align-items:center;gap:12px;transition:var(--tr)}
.sc:hover{box-shadow:var(--shmd);transform:translateY(-2px)}
.sc-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:19px}
.ic-g{background:#E8F5E9;color:#2E7D32}.ic-b{background:#EFF6FF;color:#2563EB}.ic-a{background:#FEF3C7;color:#D97706}.ic-r{background:#FEF2F2;color:#DC2626}.ic-t{background:#CCFBF1;color:#0D9488}
.sc-v{font-size:24px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}.sc-l{font-size:11.5px;color:var(--t2);margin-top:3px;font-weight:500}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;animation:UP .4s .09s both}
.sw{position:relative;flex:1;min-width:200px}.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}.si::placeholder{color:var(--t3)}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.view-toggle{display:flex;background:var(--s);border:1px solid var(--bdm);border-radius:10px;overflow:hidden}
.vt-btn{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 14px;border:none;cursor:pointer;transition:var(--tr);color:var(--t2);background:transparent;display:flex;align-items:center;gap:6px}
.vt-btn i{font-size:16px}.vt-btn:hover{background:var(--gxl);color:var(--grn)}.vt-btn.active{background:var(--grn);color:#fff}
.zone-legend{display:flex;flex-wrap:nowrap;gap:6px;margin-bottom:16px;animation:UP .4s .11s both;overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch}
.zone-legend::-webkit-scrollbar{height:3px}.zone-legend::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.zl-pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:5px 11px;border-radius:20px;background:var(--s);border:1.5px solid var(--bd);color:var(--t2);cursor:pointer;transition:var(--tr);white-space:nowrap;flex-shrink:0}
.zl-pill .zdot{width:8px;height:8px;border-radius:50%;flex-shrink:0}.zl-pill:hover{border-color:var(--bdm);color:var(--t1)}.zl-pill.active{color:var(--t1);background:var(--gxl);border-color:var(--bdm)}
.floor-wrap{background:var(--s);border:1px solid var(--bd);border-radius:16px;box-shadow:var(--shmd);overflow:hidden;animation:UP .4s .13s both}
.floor-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--bd);background:var(--bg);flex-wrap:wrap;gap:10px}
.floor-title{font-size:13px;font-weight:700;color:var(--t2);display:flex;align-items:center;gap:8px}.floor-title i{font-size:16px;color:var(--grn)}
.floor-legend{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.fl-leg{display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;color:var(--t2)}
.fl-dot{width:10px;height:4px;border-radius:2px;flex-shrink:0}.fl-dot.occ{background:var(--occ)}.fl-dot.avl{background:var(--avl)}.fl-dot.res{background:var(--res)}.fl-dot.ina{background:var(--ina)}
.floor-body{padding:20px 24px;overflow-x:auto}
.zone-section{margin-bottom:28px}.zone-section:last-child{margin-bottom:0}
.zone-label{display:flex;align-items:center;gap:8px;font-size:10.5px;font-weight:700;letter-spacing:.10em;text-transform:uppercase;color:var(--t2);margin-bottom:12px}
.zone-label .zlabel-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}.zone-label-line{flex:1;height:1px;background:var(--bd)}
.row-wrap{display:flex;flex-direction:column;gap:8px}
.zone-row{display:flex;align-items:center;gap:8px}
.row-label{font-size:10px;font-weight:700;color:var(--t3);letter-spacing:.06em;text-transform:uppercase;min-width:28px;flex-shrink:0;text-align:right}
.bin-grid-row{display:flex;gap:8px;flex-wrap:nowrap}
.bin-cell{width:96px;border-radius:10px;cursor:pointer;flex-shrink:0;background:var(--s);border:1px solid var(--bd);box-shadow:var(--shsm);transition:var(--tr);position:relative;display:flex;flex-direction:column;overflow:hidden}
.bin-cell:hover{transform:translateY(-2px);box-shadow:var(--shmd);z-index:2;border-color:var(--bdm)}.bin-cell.ina{opacity:.65}.bin-cell.ina:hover{opacity:1}
.bin-strip{height:4px;width:100%;flex-shrink:0}
.bin-cell.occ .bin-strip{background:var(--occ)}.bin-cell.avl .bin-strip{background:var(--avl)}.bin-cell.res .bin-strip{background:var(--res)}.bin-cell.ina .bin-strip{background:var(--ina)}
.bin-inner{padding:8px 9px 9px;display:flex;flex-direction:column;gap:3px;flex:1}
.bin-code{font-family:'DM Mono',monospace;font-size:10px;font-weight:700;line-height:1;color:var(--t1)}
.bin-item{font-size:9.5px;color:var(--t2);line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:78px;margin-top:2px}
.bin-item-more{font-size:9px;color:var(--t3);font-weight:600}
.bin-util-bar{margin-top:auto;padding-top:6px}
.bin-util-track{height:3px;background:rgba(0,0,0,.07);border-radius:2px;overflow:hidden}.bin-util-fill{height:100%;border-radius:2px;transition:width .3s}
.bin-cell.occ .bin-util-fill{background:var(--occ)}.bin-cell.avl .bin-util-fill{background:var(--avl)}.bin-cell.res .bin-util-fill{background:var(--res)}.bin-cell.ina .bin-util-fill{background:var(--ina)}
.bin-util-row{display:flex;align-items:center;justify-content:space-between;margin-top:3px}
.bin-util-pct{font-size:9px;font-weight:700;font-family:'DM Mono',monospace}
.bin-cell.occ .bin-util-pct{color:var(--occ)}.bin-cell.avl .bin-util-pct{color:var(--avl)}.bin-cell.res .bin-util-pct{color:var(--res)}.bin-cell.ina .bin-util-pct{color:var(--ina)}
.bin-status-lbl{font-size:8.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.bin-cell.occ .bin-status-lbl{color:var(--occ)}.bin-cell.avl .bin-status-lbl{color:var(--avl)}.bin-cell.res .bin-status-lbl{color:var(--res)}.bin-cell.ina .bin-status-lbl{color:var(--ina)}
.list-view{display:none}.list-view.on{display:block}.floor-view.hidden{display:none}
.inv-tbl{width:100%;border-collapse:collapse;font-size:12px}
.inv-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--t2);padding:9px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none}
.inv-tbl thead th.ns{cursor:default}.inv-tbl thead th:hover:not(.ns){color:var(--grn)}.inv-tbl thead th.sorted{color:var(--grn)}
.inv-tbl thead th .si-c{margin-left:2px;opacity:.4;font-size:10px;vertical-align:middle}.inv-tbl thead th.sorted .si-c{opacity:1}
.inv-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s}.inv-tbl tbody tr:last-child{border-bottom:none}.inv-tbl tbody tr:hover{background:#F7FBF7}
.inv-tbl tbody td{padding:10px 12px;vertical-align:middle;white-space:nowrap}.inv-tbl tbody td:last-child{white-space:nowrap;cursor:default}
.mono{font-family:'DM Mono',monospace}.code-cell{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700;color:var(--grn)}
.act-cell{display:flex;gap:3px;align-items:center}
.util-cell{display:flex;align-items:center;gap:8px;min-width:100px}
.util-bar-wrap{flex:1}.util-track{height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden}.util-fill{height:100%;border-radius:3px}
.util-pct{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;min-width:30px;text-align:right}
.zone-pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600}.zdot-sm{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.b-occ{background:var(--occ-bg);color:var(--occ)}.b-avl{background:var(--avl-bg);color:var(--avl)}.b-res{background:var(--res-bg);color:var(--res)}.b-ina{background:var(--ina-bg);color:var(--ina)}
.pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2)}
.pg-btns{display:flex;gap:5px}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1)}
.pgb:hover{background:var(--gxl);border-color:var(--grn);color:var(--grn)}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff}.pgb:disabled{opacity:.4;pointer-events:none}
.empty{padding:64px 20px;text-align:center;color:var(--t3)}.empty i{font-size:48px;display:block;margin-bottom:12px;color:#C8E6C9}
.vp-section{background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:16px 18px}
.vp-section-title{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);margin-bottom:10px}
.vp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.vp-item label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);display:block;margin-bottom:3px}
.vp-item .v{font-size:13px;font-weight:600;color:var(--t1)}.vp-item .vm{font-size:13px;color:var(--t2)}.vp-full{grid-column:1/-1}
.vp-stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.vp-stat{background:var(--s);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;text-align:center}
.vp-stat .sv{font-size:20px;font-weight:800;color:var(--t1);font-variant-numeric:tabular-nums;line-height:1}.vp-stat .sl{font-size:11px;color:var(--t2);margin-top:4px}
.vp-util-bar-track{height:8px;background:#e5e7eb;border-radius:5px;overflow:hidden;margin:8px 0 4px}
.vp-util-bar-fill{height:100%;border-radius:5px;transition:width .4s}
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s}
#slOverlay.on{opacity:1;pointer-events:all}
#mainSlider{position:fixed;top:0;right:-620px;bottom:0;width:580px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#mainSlider.on{right:0}
.sl-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:#F0FAF0;flex-shrink:0}
.sl-title{font-size:17px;font-weight:700;color:var(--t1)}.sl-sub{font-size:12px;color:var(--t2);margin-top:2px}
.sl-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0}
.sl-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.sl-bd{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px}
.sl-bd::-webkit-scrollbar{width:4px}.sl-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.sl-ft{padding:16px 24px;border-top:1px solid var(--bd);background:#F0FAF0;display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.fg{display:flex;flex-direction:column;gap:5px}.fg2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.fl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2)}.fl span{color:var(--red);margin-left:2px}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}.fi:disabled,.fs:disabled{background:var(--bg);color:var(--t3);cursor:not-allowed}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px}
.fta{resize:vertical;min-height:68px}
.fdiv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px}.fdiv::after{content:'';flex:1;height:1px;background:var(--bd)}
.fhint{font-size:11.5px;color:var(--t3);margin-top:3px}
.util-preview{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px}
.util-preview-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:8px}
.util-preview-bar-track{height:8px;background:#e5e7eb;border-radius:5px;overflow:hidden}.util-preview-bar-fill{height:100%;background:var(--grn);border-radius:5px;transition:width .3s}
.util-preview-nums{display:flex;justify-content:space-between;margin-top:5px;font-size:11.5px;color:var(--t2);font-family:'DM Mono',monospace}
.item-chips{display:flex;flex-wrap:wrap;gap:6px}
.item-chip{display:inline-flex;align-items:center;gap:5px;background:var(--gxl);border:1px solid rgba(46,125,50,.2);border-radius:7px;padding:4px 8px;font-size:12px;font-weight:600;color:var(--grn)}
.item-chip-x{background:none;border:none;color:var(--grn);cursor:pointer;font-size:14px;padding:0;display:flex;align-items:center;transition:color .15s}.item-chip-x:hover{color:var(--red)}
.reassign-from{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.reassign-from-icon{width:36px;height:36px;border-radius:9px;background:var(--grn);display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;flex-shrink:0}
#confirmModal{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;padding:20px}
#confirmModal.on{opacity:1;pointer-events:all}
.cm-box{background:var(--s);border-radius:14px;padding:26px 26px 22px;width:420px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.22)}
.cm-icon{font-size:44px;margin-bottom:8px;line-height:1}.cm-title{font-size:17px;font-weight:700;color:var(--t1);margin-bottom:6px}
.cm-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px}.cm-acts{display:flex;gap:10px;justify-content:flex-end}
#toastWrap{position:fixed;bottom:26px;right:26px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:210px;animation:TIN .3s ease}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}.toast.out{animation:TOUT .3s ease forwards}
@keyframes UP  {from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN {from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}   to{opacity:0;transform:translateY(8px)}}
.is-wrap{position:relative;width:100%}
.is-input{width:100%;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.is-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.is-input::placeholder{color:var(--t3)}
.is-drop{display:none;position:fixed;background:var(--s);border:1px solid var(--bdm);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.18);z-index:99999;max-height:220px;overflow-y:auto;min-width:200px}
.is-drop.open{display:block}
.is-drop::-webkit-scrollbar{width:4px}.is-drop::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.is-opt{padding:9px 12px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:background .12s}
.is-opt:hover,.is-opt.hl{background:var(--gxl)}
.is-opt .is-code{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--grn)}
.is-opt .is-name{font-size:13px;color:var(--t1);font-weight:500}
.is-opt .is-meta{font-size:11px;color:var(--t3)}
.is-opt.is-none{color:var(--t3);cursor:default;font-size:12px;padding:14px;text-align:center}
.is-opt.is-none:hover{background:none}
#zoneOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9010;opacity:0;pointer-events:none;transition:opacity .25s}
#zoneOverlay.on{opacity:1;pointer-events:all}
#zoneSlider{position:fixed;top:0;right:-520px;bottom:0;width:480px;max-width:100vw;background:var(--s);z-index:9011;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#zoneSlider.on{right:0}
.zone-list{display:flex;flex-direction:column;flex:1;overflow-y:auto;padding:16px 20px;gap:8px}
.zone-list::-webkit-scrollbar{width:4px}.zone-list::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.zone-row-item{display:flex;align-items:center;gap:10px;background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:12px 14px;transition:var(--tr)}
.zone-row-item:hover{border-color:var(--bdm);box-shadow:var(--shsm)}
.zone-swatch{width:28px;height:28px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff}
.zone-info{flex:1;min-width:0}
.zone-info .zi-id{font-family:'DM Mono',monospace;font-size:10.5px;font-weight:700;color:var(--t3);letter-spacing:.08em}
.zone-info .zi-name{font-size:13px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.zone-info .zi-bins{font-size:11px;color:var(--t3);margin-top:1px}
.zone-edit-form{background:var(--s);border:1.5px solid var(--grn);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:10px}
.zone-edit-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.zone-color-row{display:flex;align-items:center;gap:10px}
.color-swatch-input{width:38px;height:38px;border-radius:8px;border:1px solid var(--bdm);padding:2px;cursor:pointer;background:transparent}
.color-presets{display:flex;gap:5px;flex-wrap:wrap}
.cp{width:22px;height:22px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:var(--tr);flex-shrink:0}
.cp:hover,.cp.active{border-color:#fff;box-shadow:0 0 0 2px var(--grn)}
@media(max-width:1100px){.sum-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.sum-grid{grid-template-columns:1fr 1fr}.fg2,.fg3{grid-template-columns:1fr}#mainSlider{width:100vw}.wrap{padding:16px 14px 3rem}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="wrap">

  <div class="ph">
    <div class="ph-l">
      <p class="ey">SWS · Smart Warehousing System</p>
      <h1>Bin &amp; Location Mapping</h1>
    </div>
    <div class="ph-r">
      <button class="btn btn-ghost" onclick="doExport()"><i class="bx bx-export"></i> Export</button>
      <button class="btn btn-ghost" onclick="window.print()"><i class="bx bx-printer"></i> Print</button>
      <button class="btn btn-ghost" onclick="openZoneManager()"><i class="bx bx-layer"></i> Manage Zones</button>
      <button class="btn btn-primary" onclick="openSlider('add',null)"><i class="bx bx-plus"></i> Add Bin</button>
    </div>
  </div>

  <div class="sum-grid" id="sumGrid"></div>

  <div class="toolbar">
    <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search bin code, zone, or item…"></div>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <select class="sel" id="fStat">
      <option value="">All Statuses</option>
      <option value="Occupied">Occupied</option>
      <option value="Available">Available</option>
      <option value="Reserved">Reserved</option>
      <option value="Inactive">Inactive</option>
    </select>
    <div class="view-toggle">
      <button class="vt-btn active" id="btnGrid" onclick="setView('grid')"><i class="bx bx-grid-alt"></i> Grid View</button>
      <button class="vt-btn" id="btnList" onclick="setView('list')"><i class="bx bx-list-ul"></i> List View</button>
    </div>
  </div>

  <div class="zone-legend" id="zoneLegend"></div>

  <div class="floor-wrap">
    <div class="floor-header">
      <div class="floor-title"><i class="bx bx-building-house"></i> Warehouse 1 — Main Floor Layout</div>
      <div class="floor-legend">
        <div class="fl-leg"><div class="fl-dot occ"></div>Occupied</div>
        <div class="fl-leg"><div class="fl-dot avl"></div>Available</div>
        <div class="fl-leg"><div class="fl-dot res"></div>Reserved</div>
        <div class="fl-leg"><div class="fl-dot ina"></div>Inactive</div>
      </div>
    </div>
    <div class="floor-view floor-body" id="floorView"></div>
    <div class="list-view" id="listView">
      <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><div style="min-width:900px;">
      <table class="inv-tbl" id="listTbl">
        <thead><tr>
          <th data-col="code">Bin Code <i class="bx bx-sort si-c"></i></th>
          <th data-col="zone">Zone <i class="bx bx-sort si-c"></i></th>
          <th data-col="row">Row <i class="bx bx-sort si-c"></i></th>
          <th data-col="level">Level <i class="bx bx-sort si-c"></i></th>
          <th data-col="status">Status <i class="bx bx-sort si-c"></i></th>
          <th class="ns">Assigned Items</th>
          <th data-col="utilPct" style="text-align:right">Capacity Util. <i class="bx bx-sort si-c"></i></th>
          <th data-col="capacity" style="text-align:right">Capacity <i class="bx bx-sort si-c"></i></th>
          <th class="ns">Actions</th>
        </tr></thead>
        <tbody id="listTbody"></tbody>
      </table>
      </div></div>
      <div class="pager" id="pager"></div>
    </div>
  </div>

</div>
</main>

<div id="toastWrap"></div>
<div id="slOverlay"></div>

<div id="mainSlider">
  <div class="sl-hd">
    <div><div class="sl-title" id="slTitle">Add Bin</div><div class="sl-sub" id="slSub">Create a new bin location</div></div>
    <button class="sl-cl" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-bd" id="slBody"></div>
  <div class="sl-ft" id="slFoot"></div>
</div>

<div id="confirmModal">
  <div class="cm-box">
    <div class="cm-icon" id="cmIcon">⚠️</div>
    <div class="cm-title" id="cmTitle">Confirm</div>
    <div class="cm-body" id="cmBody"></div>
    <div class="cm-acts">
      <button class="btn btn-ghost btn-sm" id="cmCancel">Cancel</button>
      <button class="btn btn-sm" id="cmConfirm">Confirm</button>
    </div>
  </div>
</div>

<div id="zoneOverlay"></div>
<div id="zoneSlider">
  <div class="sl-hd">
    <div><div class="sl-title" id="zSlTitle">Manage Zones</div><div class="sl-sub" id="zSlSub">Add, edit, or remove warehouse zones</div></div>
    <button class="sl-cl" id="zSlClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-bd" id="zSlBody" style="gap:0;padding:0"></div>
  <div class="sl-ft" id="zSlFoot">
    <button class="btn btn-ghost btn-sm" id="zSlCancel">Close</button>
    <button class="btn btn-primary btn-sm" id="zSlAdd"><i class="bx bx-plus"></i> Add Zone</button>
  </div>
</div>

<script>
// ── ROLE CONTEXT & API ────────────────────────────────────────────────────────
const API      = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
const ROLE     = '<?= addslashes($locRoleName) ?>';
const USER_ZONE= '<?= addslashes((string)$locUserZone) ?>';
async function apiFetch(path, opts = {}) {
    const r = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p, b) => apiFetch(p, { method: 'POST', body: JSON.stringify(b) });

// ── STATE ─────────────────────────────────────────────────────────────────────
let BINS = [], ZONES = [], INV_ITEMS = [];
let viewMode    = 'grid';
let activeZone  = '';
let sortCol     = 'code';
let sortDir     = 'asc';
let page        = 1;
const PAGE      = 15;
let sliderMode  = null;
let sliderBinId = null;
let confirmCb   = null;
let chipItems   = [];

// ── LOAD ALL ──────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        [ZONES, BINS, INV_ITEMS] = await Promise.all([
            apiGet(API + '?api=zones'),
            apiGet(API + '?api=bins'),
            apiGet(API + '?api=inv_items'),
        ]);
    } catch(e) { toast('Failed to load data: ' + e.message, 'd'); }
    renderAll();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const zn  = id => ZONES.find(z => z.id === id) || { id, name: id, color: '#6B7280' };
const stClass = s => ({ Occupied:'occ', Available:'avl', Reserved:'res', Inactive:'ina' }[s] || 'ina');
const stBadge = s => {
    const m = { Occupied:'b-occ', Available:'b-avl', Reserved:'b-res', Inactive:'b-ina' };
    return `<span class="badge ${m[s] || ''}">${s}</span>`;
};
const utilColor = pct => pct > 85 ? '#DC2626' : pct > 60 ? '#D97706' : '#2E7D32';

// ── FILTER ────────────────────────────────────────────────────────────────────
function getFiltered() {
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const fz = activeZone || document.getElementById('fZone').value;
    const fs = document.getElementById('fStat').value;
    return BINS.filter(b => {
        if (q && !b.code.toLowerCase().includes(q)
               && !b.zoneName.toLowerCase().includes(q)
               && !b.items.some(i => i.toLowerCase().includes(q))) return false;
        if (fz && b.zone !== fz) return false;
        if (fs && b.status !== fs) return false;
        return true;
    });
}

// ── STATS ─────────────────────────────────────────────────────────────────────
function renderStats() {
    const total = BINS.length;
    const occ   = BINS.filter(b => b.status === 'Occupied').length;
    const avl   = BINS.filter(b => b.status === 'Available').length;
    const res   = BINS.filter(b => b.status === 'Reserved').length;
    const ina   = BINS.filter(b => b.status === 'Inactive').length;
    document.getElementById('sumGrid').innerHTML = `
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-grid-alt"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">Total Bins${ROLE==='Manager'||ROLE==='Staff'?' (My Zone)':''}</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-package"></i></div><div><div class="sc-v">${occ}</div><div class="sc-l">Occupied</div></div></div>
        <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${avl}</div><div class="sc-l">Available</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-bookmark"></i></div><div><div class="sc-v">${res}</div><div class="sc-l">Reserved</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-block"></i></div><div><div class="sc-v">${ina}</div><div class="sc-l">Inactive</div></div></div>`;
}

// ── ZONE DROPDOWN + LEGEND ────────────────────────────────────────────────────
function buildZoneDropdown() {
    const el = document.getElementById('fZone');
    const v  = el.value;
    el.innerHTML = '<option value="">All Zones</option>' + ZONES.map(z =>
        `<option value="${z.id}" ${z.id === v ? 'selected' : ''}>${z.name}</option>`
    ).join('');
}
function renderZoneLegend() {
    const fz  = activeZone || document.getElementById('fZone').value;
    let html  = `<div class="zl-pill ${!fz ? 'active' : ''}" data-zone-filter=""><div class="zdot" style="background:#6B7280;width:9px;height:9px;border-radius:50%;flex-shrink:0"></div>All Zones</div>`;
    ZONES.forEach(z => {
        const cnt = binCountForZone(z.id);
        html += `<div class="zl-pill ${activeZone === z.id ? 'active' : ''}" data-zone-filter="${z.id}">
            <div class="zdot" style="background:${z.color}"></div>
            ${esc(z.name)}
            <span style="font-family:'DM Mono',monospace;font-size:10px;color:var(--t3);margin-left:2px">${cnt}</span>
            <button class="zone-edit-pill-btn" data-zone-id="${z.id}" title="Edit zone"
                style="background:none;border:none;cursor:pointer;padding:0 0 0 4px;color:var(--t3);font-size:14px;display:flex;align-items:center;line-height:1;transition:color .15s"
                onmouseover="this.style.color='var(--grn)'" onmouseout="this.style.color='var(--t3)'">
                <i class="bx bx-edit-alt"></i>
            </button>
        </div>`;
    });
    const el = document.getElementById('zoneLegend');
    el.innerHTML = html;
    el.querySelectorAll('[data-zone-filter]').forEach(pill => {
        pill.addEventListener('click', () => setZoneFilter(pill.dataset.zoneFilter));
    });
    el.querySelectorAll('.zone-edit-pill-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            openZoneEdit(btn.dataset.zoneId);
        });
    });
}
function setZoneFilter(id) {
    activeZone = id;
    document.getElementById('fZone').value = id;
    renderZoneLegend(); renderAll();
}

// ── GRID VIEW ─────────────────────────────────────────────────────────────────
function renderGrid() {
    const filtered = getFiltered();
    const fv = document.getElementById('floorView');
    fv.innerHTML = '';
    if (!filtered.length) {
        fv.innerHTML = `<div class="empty"><i class="bx bx-grid-alt"></i><p>No bins match the current filters.</p></div>`;
        return;
    }
    const zoneMap = {};
    filtered.forEach(b => { if (!zoneMap[b.zone]) zoneMap[b.zone] = []; zoneMap[b.zone].push(b); });

    ZONES.forEach(z => {
        const zoneBins = zoneMap[z.id];
        if (!zoneBins || !zoneBins.length) return;
        const rowMap = {};
        zoneBins.forEach(b => { if (!rowMap[b.row]) rowMap[b.row] = []; rowMap[b.row].push(b); });
        const sec = document.createElement('div');
        sec.className = 'zone-section';
        let html = `<div class="zone-label"><div class="zlabel-dot" style="background:${z.color}"></div>${z.name}<div class="zone-label-line"></div></div><div class="row-wrap">`;
        Object.keys(rowMap).sort().forEach(row => {
            html += `<div class="zone-row"><div class="row-label">${row}</div><div class="bin-grid-row">`;
            rowMap[row].sort((a, b) => a.level.localeCompare(b.level)).forEach(b => {
                const sc = stClass(b.status);
                const firstItem = b.items[0] || '';
                const extraCount = b.items.length > 1 ? b.items.length - 1 : 0;
                html += `
                    <div class="bin-cell ${sc}" onclick="openView(${b.id})">
                        <div class="bin-strip"></div>
                        <div class="bin-inner">
                            <div class="bin-code">${esc(b.code)}</div>
                            ${firstItem ? `<div class="bin-item" title="${esc(b.items.join(', '))}">${esc(firstItem)}</div>` : ''}
                            ${extraCount ? `<div class="bin-item-more">+${extraCount} more</div>` : ''}
                            <div class="bin-util-bar">
                                <div class="bin-util-track"><div class="bin-util-fill" style="width:${b.utilPct}%"></div></div>
                                <div class="bin-util-row">
                                    <div class="bin-status-lbl">${b.status}</div>
                                    <div class="bin-util-pct">${b.utilPct}%</div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });
            html += `</div></div>`;
        });
        html += `</div>`;
        sec.innerHTML = html;
        fv.appendChild(sec);
    });
}

// ── LIST VIEW ─────────────────────────────────────────────────────────────────
function renderList() {
    const data = getFiltered().sort((a, b) => {
        let va = a[sortCol], vb = b[sortCol];
        if (sortCol === 'utilPct' || sortCol === 'capacity') return sortDir === 'asc' ? va - vb : vb - va;
        va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    const total = data.length, pages = Math.max(1, Math.ceil(total / PAGE));
    if (page > pages) page = pages;
    const slice = data.slice((page - 1) * PAGE, page * PAGE);

    document.querySelectorAll('#listTbl thead th[data-col]').forEach(th => {
        const c = th.dataset.col;
        th.classList.toggle('sorted', c === sortCol);
        const ic = th.querySelector('.si-c');
        if (ic) ic.className = `bx ${c === sortCol ? (sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort'} si-c`;
    });

    const tb = document.getElementById('listTbody');
    if (!slice.length) {
        tb.innerHTML = `<tr><td colspan="9"><div class="empty"><i class="bx bx-grid-alt"></i><p>No bins found.</p></div></td></tr>`;
    } else {
        tb.innerHTML = slice.map(b => {
            const z = zn(b.zone); const uc = utilColor(b.utilPct);
            return `<tr>
                <td><span class="code-cell">${esc(b.code)}</span></td>
                <td><div class="zone-pill"><div class="zdot-sm" style="background:${z.color}"></div>${z.name}</div></td>
                <td style="color:var(--t2);font-size:12px">${b.row}</td>
                <td style="color:var(--t2);font-size:12px">${b.level}</td>
                <td>${stBadge(b.status)}</td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;">
                    ${b.items.length
                        ? b.items.map(i => `<span style="display:inline-block;background:var(--gxl);border-radius:5px;padding:2px 7px;font-size:11px;font-weight:600;color:var(--grn);margin:2px 2px 2px 0">${esc(i)}</span>`).join('')
                        : `<span style="color:var(--t3);font-size:12px">—</span>`}
                </td>
                <td>
                    <div class="util-cell" style="justify-content:flex-end;">
                        <div class="util-bar-wrap"><div class="util-track"><div class="util-fill" style="width:${b.utilPct}%;background:${uc}"></div></div></div>
                        <span class="util-pct" style="color:${uc}">${b.utilPct}%</span>
                    </div>
                </td>
                <td style="text-align:right"><span class="mono" style="font-size:12px;font-weight:700">${b.capacity.toLocaleString()}</span></td>
                <td>
                    <div class="act-cell">
                        <button class="btn ionly" onclick="openView(${b.id})" title="View"><i class="bx bx-show"></i></button>
                        ${ROLE==='User'||ROLE==='Staff' ? '' : `<button class="btn ionly" onclick="openSlider('edit',${b.id})" title="Edit"><i class="bx bx-edit"></i></button>`}
                        ${ROLE==='User'||ROLE==='Staff' ? '' : `<button class="btn ionly" onclick="openSlider('reassign',${b.id})" title="Reassign Item" ${b.items.length ? '' : 'disabled'}><i class="bx bx-transfer"></i></button>`}
                        ${ROLE==='Admin'||ROLE==='Super Admin' ? `<button class="btn ionly" onclick="toggleActive(${b.id})" title="${b.active ? 'Deactivate' : 'Activate'}"><i class="bx ${b.active ? 'bx-block' : 'bx-check-circle'}"></i></button>` : ''}
                        ${ROLE==='Super Admin' ? `<button class="btn ionly" style="border-color:#FECACA;color:var(--red)" onmouseover="this.style.background='#FEE2E2'" onmouseout="this.style.background=''" onclick="deleteBin(${b.id})" title="Delete"><i class="bx bx-trash"></i></button>` : ''}
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    const s = (page - 1) * PAGE + 1, e = Math.min(page * PAGE, total);
    let btns = '';
    for (let i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || (i >= page - 2 && i <= page + 2)) btns += `<button class="pgb ${i === page ? 'active' : ''}" onclick="listGoPage(${i})">${i}</button>`;
        else if (i === page - 3 || i === page + 3) btns += `<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML = `
        <span>${total === 0 ? 'No results' : `Showing ${s}–${e} of ${total} bins`}</span>
        <div class="pg-btns">
            <button class="pgb" onclick="listGoPage(${page - 1})" ${page <= 1 ? 'disabled' : ''}><i class="bx bx-chevron-left"></i></button>
            ${btns}
            <button class="pgb" onclick="listGoPage(${page + 1})" ${page >= pages ? 'disabled' : ''}><i class="bx bx-chevron-right"></i></button>
        </div>`;
}
window.listGoPage = p => { page = p; renderList(); };

document.querySelectorAll('#listTbl thead th[data-col]').forEach(th => {
    th.addEventListener('click', () => {
        const c = th.dataset.col;
        sortDir = sortCol === c ? (sortDir === 'asc' ? 'desc' : 'asc') : 'asc';
        sortCol = c; page = 1; renderList();
    });
});

// ── VIEW TOGGLE ───────────────────────────────────────────────────────────────
function setView(mode) {
    viewMode = mode;
    document.getElementById('btnGrid').classList.toggle('active', mode === 'grid');
    document.getElementById('btnList').classList.toggle('active', mode === 'list');
    document.getElementById('floorView').classList.toggle('hidden', mode === 'list');
    document.getElementById('listView').classList.toggle('on', mode === 'list');
    if (mode === 'grid') renderGrid(); else renderList();
}

// ── RENDER ALL ────────────────────────────────────────────────────────────────
function renderAll() {
    renderStats(); buildZoneDropdown(); renderZoneLegend();
    if (viewMode === 'grid') renderGrid(); else renderList();
}

['srch', 'fZone', 'fStat'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => {
        if (id === 'fZone') activeZone = document.getElementById('fZone').value;
        page = 1; renderAll();
    });
});

// ── VIEW PANEL ────────────────────────────────────────────────────────────────
function openView(binId) {
    const b = BINS.find(x => x.id === binId); if (!b) return;
    const z = zn(b.zone); const uc = utilColor(b.utilPct);
    document.getElementById('slTitle').textContent = b.code;
    document.getElementById('slSub').textContent   = `${z.name} · Row ${b.row} · Level ${b.level}`;
    document.getElementById('slBody').innerHTML = `
        <div class="vp-stat-row">
            <div class="vp-stat"><div class="sv">${b.used.toLocaleString()}</div><div class="sl">Used Units</div></div>
            <div class="vp-stat"><div class="sv">${b.capacity.toLocaleString()}</div><div class="sl">Max Capacity</div></div>
            <div class="vp-stat"><div class="sv" style="color:${uc}">${b.utilPct}%</div><div class="sl">Utilization</div></div>
        </div>
        <div class="vp-section">
            <div class="vp-section-title">Bin Details</div>
            <div class="vp-grid">
                <div class="vp-item"><label>Bin Code</label><div class="v mono" style="color:var(--grn)">${esc(b.code)}</div></div>
                <div class="vp-item"><label>Status</label><div class="v">${stBadge(b.status)}</div></div>
                <div class="vp-item"><label>Zone</label><div class="v" style="color:${z.color}">${z.name}</div></div>
                <div class="vp-item"><label>Row / Level</label><div class="v mono">${b.row} / ${b.level}</div></div>
                <div class="vp-item"><label>Active</label><div class="v">${b.active ? '<span style="color:#166534;font-weight:700">Yes</span>' : '<span style="color:#991B1B;font-weight:700">No</span>'}</div></div>
                <div class="vp-item"><label>Bin ID</label><div class="vm mono" style="font-size:11.5px">${esc(b.binId)}</div></div>
            </div>
        </div>
        <div class="vp-section">
            <div class="vp-section-title">Capacity Utilization</div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--t2);font-weight:600">
                <span>Used: ${b.used.toLocaleString()} units</span>
                <span style="color:${uc};font-family:'DM Mono',monospace;font-weight:700">${b.utilPct}%</span>
            </div>
            <div class="vp-util-bar-track"><div class="vp-util-bar-fill" style="width:${b.utilPct}%;background:${uc}"></div></div>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--t3);font-family:'DM Mono',monospace">
                <span>0</span><span>Max ${b.capacity.toLocaleString()}</span>
            </div>
        </div>
        <div class="vp-section">
            <div class="vp-section-title">Assigned Items (${b.items.length})</div>
            ${b.items.length
                ? `<div style="display:flex;flex-wrap:wrap;gap:6px">${b.items.map(i => `<span style="display:inline-flex;align-items:center;gap:5px;background:var(--gxl);border:1px solid rgba(46,125,50,.2);border-radius:7px;padding:4px 10px;font-size:12px;font-weight:600;color:var(--grn)"><i class="bx bx-package" style="font-size:13px"></i>${esc(i)}</span>`).join('')}</div>`
                : `<div style="font-size:13px;color:var(--t3)">No items assigned to this bin.</div>`}
        </div>
        ${b.notes ? `<div class="vp-section"><div class="vp-section-title">Notes</div><div style="font-size:13px;color:var(--t2);line-height:1.6">${esc(b.notes)}</div></div>` : ''}`;
    document.getElementById('slFoot').innerHTML = `
        <button class="btn btn-ghost btn-sm" onclick="closeSlider();openSlider('edit',${b.id})"><i class="bx bx-edit"></i> Edit</button>
        <button class="btn btn-ghost btn-sm" onclick="closeSlider();openSlider('reassign',${b.id})" ${b.items.length ? '' : 'disabled'}><i class="bx bx-transfer"></i> Reassign</button>
        <button class="btn btn-ghost btn-sm" onclick="closeSlider();toggleActive(${b.id})"><i class="bx ${b.active ? 'bx-block' : 'bx-check-circle'}"></i> ${b.active ? 'Deactivate' : 'Activate'}</button>
        <button class="btn btn-ghost btn-sm" onclick="closeSlider()">Close</button>`;
    document.getElementById('slOverlay').classList.add('on');
    document.getElementById('mainSlider').classList.add('on');
}

// ── SLIDER ────────────────────────────────────────────────────────────────────
function openSlider(mode, binId) {
    sliderMode = mode; sliderBinId = binId; chipItems = [];
    const b = binId ? BINS.find(x => x.id === binId) : null;
    const z = b ? zn(b.zone) : null;

    const titles = { add:'Add Bin', edit:'Edit Bin', reassign:'Reassign Item' };
    const subs   = { add:'Create a new bin location', edit:`Editing ${b ? b.code : '—'}`, reassign:`Move items from ${b ? b.code : '—'} to another bin` };
    document.getElementById('slTitle').textContent = titles[mode] || '';
    document.getElementById('slSub').textContent   = subs[mode] || '';

    const body = document.getElementById('slBody');
    const foot = document.getElementById('slFoot');

    if (mode === 'add' || mode === 'edit') {
        const zoneOpts = ZONES.map(z => `<option value="${z.id}" ${b && b.zone === z.id ? 'selected' : ''}>${z.name}</option>`).join('');
        const defZone  = b ? b.zone : (ZONES[0]?.id || '');
        const { rowOpts, lvlOpts } = getRowLevelOpts(defZone, b);
        const statOpts = ['Available','Occupied','Reserved','Inactive'].map(s => `<option ${b && b.status === s ? 'selected' : ''}>${s}</option>`).join('');
        const curPct   = b ? b.utilPct : 0;
        const curUsed  = b ? b.used : 0;
        const curCap   = b ? b.capacity : 100;
        chipItems = b ? [...b.items] : [];

        const rowField = mode === 'add'
            ? `<input type="text" class="fi" id="fRow" placeholder="Auto-filled" value="" oninput="this.dataset.auto='0';updateBinCodePreview()">`
            : `<select class="fs" id="fRow" onchange="updateBinCodePreview()">${rowOpts}</select>`;
        const lvlField = mode === 'add'
            ? `<input type="text" class="fi" id="fLevel" placeholder="Auto-filled" value="" oninput="this.dataset.auto='0';updateBinCodePreview()">`
            : `<select class="fs" id="fLevel" onchange="updateBinCodePreview()">${lvlOpts}</select>`;

        body.innerHTML = `
            <div class="fdiv">Location Details</div>
            <div class="fg">
                <label class="fl">Zone <span>*</span></label>
                <select class="fs" id="fZoneSl" onchange="${mode === 'add' ? 'autoFillRowLevel(this.value);updateBinCodePreview()' : 'onZoneChange(this.value);updateBinCodePreview()'}">${zoneOpts}</select>
            </div>
            <div class="fg2">
                <div class="fg"><label class="fl">Row <span>*</span></label>${rowField}</div>
                <div class="fg"><label class="fl">Level <span>*</span></label>${lvlField}</div>
            </div>
            <div id="rowLevelHint" class="fhint" style="margin-top:-8px;margin-bottom:4px"></div>
            <div class="fg" style="margin-top:-4px">
                <label class="fl">Bin Code Preview</label>
                <div id="binCodePreview" style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--grn);padding:8px 12px;background:var(--gxl);border:1px solid rgba(46,125,50,.2);border-radius:10px">—</div>
            </div>
            ${mode === 'edit' ? `<div class="fg"><label class="fl">Current Bin Code</label><input type="text" class="fi" id="fCode" value="${esc(b.code)}" disabled></div>` : ''}
            <div class="fdiv">Capacity</div>
            <div class="fg2">
                <div class="fg">
                    <label class="fl">Max Capacity <span>*</span></label>
                    <input type="number" class="fi" id="fCap" min="1" value="${curCap}" oninput="updateUtilPreview()">
                    <div class="fhint">Max units this bin can hold</div>
                </div>
                <div class="fg">
                    <label class="fl">Current Used</label>
                    <input type="number" class="fi" id="fUsed" min="0" value="${curUsed}" oninput="updateUtilPreview()">
                </div>
            </div>
            <div class="util-preview">
                <div class="util-preview-label">Utilization Preview</div>
                <div class="util-preview-bar-track"><div class="util-preview-bar-fill" id="utilPreviewFill" style="width:${curPct}%"></div></div>
                <div class="util-preview-nums"><span id="utilPreviewUsed">${curUsed}</span><span id="utilPreviewPct">${curPct}%</span><span id="utilPreviewCap">${curCap}</span></div>
            </div>
            <div class="fdiv">Status &amp; Items</div>
            <div class="fg"><label class="fl">Status <span>*</span></label><select class="fs" id="fStat2">${statOpts}</select></div>
            <div class="fg">
                <label class="fl">Assigned Items</label>
                <div class="fhint" style="margin-bottom:6px">Only items belonging to the selected zone are searchable</div>
                <div class="item-chips" id="itemChips"></div>
                <div class="is-wrap" style="margin-top:8px" id="itemSearchWrap">
                    <input type="text" class="is-input" id="itemSearchInput" placeholder="Search item code or name…" autocomplete="off">
                </div>
            </div>
            <div class="fg"><label class="fl">Notes</label><textarea class="fta" id="fNotes" placeholder="Additional notes…">${b ? esc(b.notes) : ''}</textarea></div>`;

        refreshChips();
        wireItemSearch();

        if (mode === 'add' && defZone) {
            autoFillRowLevel(defZone);
            updateBinCodePreview();
        } else if (mode === 'edit') {
            updateBinCodePreview();
        }

        foot.innerHTML = `
            <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
            <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-save"></i> ${mode === 'add' ? 'Create Bin' : 'Save Changes'}</button>`;
        document.getElementById('slCancel').onclick = closeSlider;
        document.getElementById('slSubmit').onclick = submitBinForm;
    }
    else if (mode === 'reassign') {
        const otherBins = BINS.filter(x => x.id !== binId && x.active);
        const targetOpts = otherBins.map(x =>
            `<option value="${x.id}">${x.code} — ${zn(x.zone).name} (${x.status})</option>`
        ).join('');
        body.innerHTML = `
            <div class="reassign-from">
                <div class="reassign-from-icon"><i class="bx bx-transfer"></i></div>
                <div>
                    <div style="font-weight:700;font-size:14px">${esc(b.code)}</div>
                    <div style="font-size:11.5px;color:var(--t3)">${z?.name || ''} · ${b.row} / ${b.level}</div>
                </div>
            </div>
            <div class="fg">
                <label class="fl">Item to Reassign <span>*</span></label>
                <select class="fs" id="raItem">${b.items.length ? b.items.map(i => `<option>${esc(i)}</option>`).join('') : '<option>No items assigned</option>'}</select>
            </div>
            <div class="fg">
                <label class="fl">Destination Bin <span>*</span></label>
                <select class="fs" id="raTarget">${targetOpts || '<option>No other active bins</option>'}</select>
            </div>
            <div class="fg"><label class="fl">Reason / Notes</label><textarea class="fta" id="raNotes" placeholder="Reason for reassignment…"></textarea></div>`;
        foot.innerHTML = `
            <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
            <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-transfer"></i> Reassign Item</button>`;
        document.getElementById('slCancel').onclick = closeSlider;
        document.getElementById('slSubmit').onclick = submitReassign;
    }

    document.getElementById('slOverlay').classList.add('on');
    document.getElementById('mainSlider').classList.add('on');
    setTimeout(() => { const f = body.querySelector('input:not([disabled]),select'); if (f) f.focus(); }, 350);
}

// ── ROW / LEVEL AUTO-FILL ─────────────────────────────────────────────────────
function parseRowNum(r)   { return parseInt((r || '').replace(/\D/g, '')) || 1; }
function parseLevelNum(l) { return parseInt((l || '').replace(/\D/g, '')) || 1; }
function fmtRow(n)   { return 'R' + n; }
function fmtLevel(n) { return 'L' + n; }

function inferZoneGrid(zoneId) {
    const zoneBins = BINS.filter(b => b.zone === zoneId && b.active);
    if (!zoneBins.length) return { levelsPerRow: 7, maxRow: 0, maxLevel: 0 };
    const rowNums   = zoneBins.map(b => parseRowNum(b.row));
    const levelNums = zoneBins.map(b => parseLevelNum(b.level));
    const maxRow    = Math.max(...rowNums);
    const maxLevel  = Math.max(...levelNums);
    const rowLevelMap = {};
    zoneBins.forEach(b => {
        const r = parseRowNum(b.row);
        if (!rowLevelMap[r]) rowLevelMap[r] = new Set();
        rowLevelMap[r].add(parseLevelNum(b.level));
    });
    const maxLevelsInARow = Math.max(...Object.values(rowLevelMap).map(s => s.size));
    const levelsPerRow = Math.max(maxLevelsInARow, maxLevel, 7);
    return { levelsPerRow, maxRow, maxLevel };
}

function nextFreeSlot(zoneId, excludeId = null) {
    const taken = new Set(
        BINS.filter(b => b.zone === zoneId && b.id !== excludeId)
            .map(b => b.row + '|' + b.level)
    );
    const { levelsPerRow } = inferZoneGrid(zoneId);
    const lpr = Math.max(levelsPerRow, 1);
    for (let r = 1; r <= 3; r++) {
        for (let l = 1; l <= lpr; l++) {
            const key = fmtRow(r) + '|' + fmtLevel(l);
            if (!taken.has(key)) return { row: fmtRow(r), level: fmtLevel(l) };
        }
    }
    const maxR = Math.max(0, ...BINS.filter(b => b.zone === zoneId).map(b => parseRowNum(b.row)));
    return { row: fmtRow(Math.min(maxR + 1, 3)), level: fmtLevel(1) };
}

function getRowLevelOpts(zoneId, current) {
    const zoneBins  = BINS.filter(b => b.zone === zoneId);
    const rows   = [...new Set(zoneBins.map(b => b.row))].sort((a,b) => parseRowNum(a) - parseRowNum(b));
    const levels = [...new Set(zoneBins.map(b => b.level))].sort((a,b) => parseLevelNum(a) - parseLevelNum(b));
    const rowList   = rows.length   ? rows   : ['R1','R2','R3'];
    const levelList = levels.length ? levels : ['L1','L2','L3','L4','L5','L6','L7'];
    const rowOpts   = rowList.map(r => `<option ${current && current.row   === r ? 'selected' : ''}>${r}</option>`).join('');
    const lvlOpts   = levelList.map(l => `<option ${current && current.level === l ? 'selected' : ''}>${l}</option>`).join('');
    return { rowOpts, lvlOpts };
}

function onZoneChange(zoneId) {
    const { rowOpts, lvlOpts } = getRowLevelOpts(zoneId, null);
    const rEl = document.getElementById('fRow');
    const lEl = document.getElementById('fLevel');
    if (rEl && rEl.tagName === 'SELECT') rEl.innerHTML = rowOpts;
    if (lEl && lEl.tagName === 'SELECT') lEl.innerHTML = lvlOpts;
}

function autoFillRowLevel(zoneId) {
    const slot = nextFreeSlot(zoneId);
    const rEl  = document.getElementById('fRow');
    const lEl  = document.getElementById('fLevel');
    if (rEl) { rEl.value = slot.row;   rEl.dataset.auto = '1'; }
    if (lEl) { lEl.value = slot.level; lEl.dataset.auto = '1'; }
    updateRowLevelHint(zoneId, slot);
    updateBinCodePreview();
}

function updateRowLevelHint(zoneId, slot) {
    const hint = document.getElementById('rowLevelHint');
    if (!hint) return;
    const zoneBins = BINS.filter(b => b.zone === zoneId && b.active);
    const { levelsPerRow, maxRow } = inferZoneGrid(zoneId);
    if (!zoneBins.length) {
        hint.innerHTML = `<span style="color:var(--grn);font-weight:600">${slot.row} / ${slot.level}</span> — first bin in this zone`;
        return;
    }
    const MAX_ROWS  = 3;
    const taken     = zoneBins.length;
    const gridRows  = Math.min(Math.max(maxRow, 1), MAX_ROWS);
    const total     = gridRows * levelsPerRow;
    const pctFull   = Math.round((taken / total) * 100);
    const isFull    = slot.row === fmtRow(MAX_ROWS) && taken >= total;
    hint.innerHTML = `<span style="color:${isFull ? 'var(--amb)' : 'var(--grn)'};font-weight:600">${slot.row} / ${slot.level}</span>`
        + ` — next available &nbsp;·&nbsp; ${taken} of ${total} slots used`
        + ` &nbsp;·&nbsp; grid ${gridRows}R × ${levelsPerRow}L (max R3)`
        + (isFull ? ` <span style="color:var(--red);font-weight:600">⚠ Zone full (R3 limit reached)</span>` : ` (${pctFull}% full)`);
}

function updateUtilPreview() {
    const used = +document.getElementById('fUsed')?.value || 0;
    const cap  = +document.getElementById('fCap')?.value  || 1;
    const pct  = Math.min(100, Math.round((used / cap) * 100));
    const fill = document.getElementById('utilPreviewFill');
    if (fill) { fill.style.width = `${pct}%`; fill.style.background = utilColor(pct); }
    const pu = document.getElementById('utilPreviewUsed'); if (pu) pu.textContent = used;
    const pp = document.getElementById('utilPreviewPct');  if (pp) pp.textContent = `${pct}%`;
    const pc = document.getElementById('utilPreviewCap');  if (pc) pc.textContent = cap;
}

function updateBinCodePreview() {
    const zoneEl = document.getElementById('fZoneSl');
    const rowEl  = document.getElementById('fRow');
    const lvlEl  = document.getElementById('fLevel');
    const prev   = document.getElementById('binCodePreview');
    if (!zoneEl || !rowEl || !lvlEl || !prev) return;
    const zone   = zoneEl.value;
    const row    = (rowEl.value  || rowEl.options?.[rowEl.selectedIndex]?.value || '').trim().toUpperCase();
    const level  = (lvlEl.value  || lvlEl.options?.[lvlEl.selectedIndex]?.value || '').trim().toUpperCase();
    if (!zone || !row || !level) { prev.textContent = '—'; return; }
    const zoneParts = zone.split('-');
    const zoneShort = zoneParts[1] ?? zone;
    const code = `${zoneShort}-${row}-${level}`;
    const conflict = BINS.find(b => b.code === code && b.id !== sliderBinId);
    if (conflict) {
        prev.innerHTML = `<span style="color:var(--red)">${esc(code)}</span> <span style="font-size:11px;font-family:'Inter',sans-serif;font-weight:500;color:var(--red)">⚠ already exists</span>`;
    } else {
        prev.textContent = code;
        prev.style.color = 'var(--grn)';
    }
}

// ── ITEM SEARCH PICKER ────────────────────────────────────────────────────────
function wireItemSearch() {
    const inp = document.getElementById('itemSearchInput');
    if (!inp) return;
    const old = document.getElementById('itemSearchDrop');
    if (old) old.remove();
    const drop = document.createElement('div');
    drop.id        = 'itemSearchDrop';
    drop.className = 'is-drop';
    document.body.appendChild(drop);
    let hlIdx = -1;

    function positionDrop() {
        const rect       = inp.getBoundingClientRect();
        const maxH       = 220;
        const spaceBelow = window.innerHeight - rect.bottom - 8;
        const spaceAbove = rect.top - 8;
        drop.style.width  = rect.width + 'px';
        drop.style.left   = rect.left + 'px';
        if (spaceBelow >= Math.min(maxH, 120) || spaceBelow >= spaceAbove) {
            drop.style.top       = (rect.bottom + 4) + 'px';
            drop.style.bottom    = 'auto';
            drop.style.maxHeight = Math.max(spaceBelow, 80) + 'px';
        } else {
            drop.style.bottom    = 'auto';
            drop.style.maxHeight = Math.max(spaceAbove, 80) + 'px';
            drop.style.top       = (rect.top - Math.min(spaceAbove, maxH) - 4) + 'px';
        }
    }

    function getCurrentZone() { return document.getElementById('fZoneSl')?.value || ''; }
    function getTakenInZone(zoneId) {
        const taken = new Set();
        BINS.forEach(b => {
            if (b.zone !== zoneId) return;
            if (b.id === sliderBinId) return;
            b.items.forEach(name => taken.add(name));
        });
        return taken;
    }

    function getOpts(q) {
        const lq     = q.toLowerCase();
        const zoneId = getCurrentZone();
        const taken  = getTakenInZone(zoneId);
        return INV_ITEMS
            .filter(it => it.zone === zoneId)
            .filter(it => !q || it.code.toLowerCase().includes(lq) || it.name.toLowerCase().includes(lq) || (it.category || '').toLowerCase().includes(lq))
            .filter(it => !chipItems.includes(it.name))
            .map(it => ({ ...it, takenInZone: taken.has(it.name) }))
            .sort((a, b) => a.takenInZone - b.takenInZone)
            .slice(0, 60);
    }

    function renderDrop(q) {
        const zoneId   = getCurrentZone();
        const zoneObj  = ZONES.find(z => z.id === zoneId);
        const zoneName = zoneObj ? zoneObj.name : 'this zone';
        const opts     = getOpts(q);
        if (!zoneId) {
            drop.innerHTML = `<div class="is-opt is-none"><i class="bx bx-layer" style="font-size:16px;display:block;margin-bottom:4px"></i>Select a zone first</div>`;
        } else if (!opts.length) {
            const totalInZone = INV_ITEMS.filter(it => it.zone === zoneId).length;
            drop.innerHTML = `<div class="is-opt is-none"><i class="bx bx-search" style="font-size:16px;display:block;margin-bottom:4px"></i>${q ? `No items in <strong>${esc(zoneName)}</strong> match "${esc(q)}"` : totalInZone === 0 ? `No inventory items are assigned to <strong>${esc(zoneName)}</strong>` : `All items in <strong>${esc(zoneName)}</strong> are already assigned`}</div>`;
        } else {
            drop.innerHTML = opts.map((it, i) => {
                if (it.takenInZone) {
                    return `<div class="is-opt" data-taken="1" style="opacity:.42;cursor:not-allowed;pointer-events:none" title="Already assigned to another bin in this zone">
                        <span class="is-code">${esc(it.code)}</span>
                        <span class="is-name">${esc(it.name)}</span>
                        <span class="is-meta" style="display:flex;align-items:center;gap:4px"><i class="bx bx-lock-alt" style="font-size:11px"></i>In use in this zone · ${esc(it.category)} · ${esc(it.uom)}</span>
                    </div>`;
                }
                return `<div class="is-opt ${i === hlIdx ? 'hl' : ''}" data-name="${esc(it.name)}">
                    <span class="is-code">${esc(it.code)}</span>
                    <span class="is-name">${esc(it.name)}</span>
                    <span class="is-meta">${esc(it.category)} · ${esc(it.uom)}</span>
                </div>`;
            }).join('');
            drop.querySelectorAll('.is-opt:not([data-taken])').forEach(el => {
                el.addEventListener('mousedown', e => { e.preventDefault(); selectItem(el.dataset.name); });
            });
        }
        hlIdx = -1;
    }

    function selectItem(name) {
        if (!name || chipItems.includes(name)) return;
        chipItems.push(name);
        refreshChips();
        inp.value = '';
        drop.classList.remove('open');
        inp.focus();
        const statEl = document.getElementById('fStat2');
        if (statEl && statEl.value === 'Available') statEl.value = 'Occupied';
    }

    function openDrop()  { positionDrop(); renderDrop(inp.value); drop.classList.add('open'); }
    function closeDrop() { drop.classList.remove('open'); }

    inp.addEventListener('focus',   openDrop);
    inp.addEventListener('input',   () => { hlIdx = -1; positionDrop(); renderDrop(inp.value); drop.classList.add('open'); });
    inp.addEventListener('blur',    () => setTimeout(closeDrop, 160));
    inp.addEventListener('keydown', e => {
        const opts = [...drop.querySelectorAll('.is-opt:not(.is-none):not([data-taken])')];
        if      (e.key === 'ArrowDown') { e.preventDefault(); hlIdx = Math.min(hlIdx + 1, opts.length - 1); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); hlIdx = Math.max(hlIdx - 1, 0); }
        else if (e.key === 'Enter')     { e.preventDefault(); if (hlIdx >= 0 && opts[hlIdx]) selectItem(opts[hlIdx].dataset.name); }
        else if (e.key === 'Escape')    { closeDrop(); }
        opts.forEach((o, i) => o.classList.toggle('hl', i === hlIdx));
        if (hlIdx >= 0 && opts[hlIdx]) opts[hlIdx].scrollIntoView({ block: 'nearest' });
    });

    const slBody = document.getElementById('slBody');
    if (slBody) slBody.addEventListener('scroll', () => { if (drop.classList.contains('open')) positionDrop(); }, { passive: true });
    window.addEventListener('resize', () => { if (drop.classList.contains('open')) positionDrop(); }, { passive: true });
    const zoneEl = document.getElementById('fZoneSl');
    if (zoneEl) zoneEl.addEventListener('change', () => { if (drop.classList.contains('open')) renderDrop(inp.value); });
}

// ── ITEM CHIPS ────────────────────────────────────────────────────────────────
function removeItemChip(name) {
    chipItems = chipItems.filter(x => x !== name);
    refreshChips();
    const inp = document.getElementById('itemSearchInput');
    if (inp) {
        const drop = document.getElementById('itemSearchDrop');
        if (drop && drop.classList.contains('open')) inp.dispatchEvent(new Event('input'));
    }
}
function refreshChips() {
    const el = document.getElementById('itemChips'); if (!el) return;
    if (!chipItems.length) {
        el.innerHTML = `<div style="font-size:12px;color:var(--t3);padding:4px 0">No items assigned yet</div>`;
        return;
    }
    el.innerHTML = chipItems.map(it =>
        `<div class="item-chip">${esc(it)}
            <button class="item-chip-x" data-name="${esc(it)}" onclick="removeItemChip(this.dataset.name)" title="Remove">
                <i class="bx bx-x"></i>
            </button>
        </div>`
    ).join('');
}

// ── SUBMIT BIN FORM ───────────────────────────────────────────────────────────
async function submitBinForm() {
    const zone  = document.getElementById('fZoneSl')?.value;
    const row   = (document.getElementById('fRow')?.value   || '').trim().toUpperCase();
    const level = (document.getElementById('fLevel')?.value || '').trim().toUpperCase();
    const cap   = +document.getElementById('fCap')?.value || 0;
    const used  = +document.getElementById('fUsed')?.value || 0;
    let   stat  = document.getElementById('fStat2')?.value;
    const notes = document.getElementById('fNotes')?.value.trim();
    if (!zone || !row || !level || cap < 1) { toast('Fill in all required fields.', 'w'); return; }
    const zoneParts = zone.split('-');
    const zoneShort = zoneParts[1] ?? zone;
    const code = `${zoneShort}-${row}-${level}`;
    const conflict = BINS.find(b => b.code === code && b.id !== sliderBinId);
    if (conflict) { toast(`Bin ${code} already exists in this zone.`, 'w'); return; }
    if (chipItems.length > 0 && stat === 'Available') stat = 'Occupied';
    const statEl = document.getElementById('fStat2');
    if (statEl) statEl.value = stat;
    const btn = document.getElementById('slSubmit'); btn.disabled = true;
    try {
        const payload = { zone, row, level, capacity: cap, used, status: stat, active: stat !== 'Inactive', notes, items: chipItems };
        if (sliderMode === 'edit') {
            const b = BINS.find(x => x.id === sliderBinId);
            if (b) payload.id = b.id;
        }
        const saved = await apiPost(API + '?api=save_bin', payload);
        const idx = BINS.findIndex(x => x.id === saved.id);
        if (idx > -1) BINS[idx] = saved; else BINS.unshift(saved);
        toast(`Bin ${saved.code} ${sliderMode === 'add' ? 'created' : 'updated'}.`, 's');
        closeSlider(); renderAll();
    } catch(e) { toast(e.message, 'd'); }
    finally { btn.disabled = false; }
}

// ── SUBMIT REASSIGN ───────────────────────────────────────────────────────────
async function submitReassign() {
    const itemName = document.getElementById('raItem')?.value;
    const targetId = +document.getElementById('raTarget')?.value;
    const notes    = document.getElementById('raNotes')?.value.trim();
    if (!itemName || !targetId) { toast('Select item and destination.', 'w'); return; }
    const btn = document.getElementById('slSubmit'); btn.disabled = true;
    try {
        const result = await apiPost(API + '?api=reassign_item', {
            srcId: sliderBinId, dstId: targetId, itemName, notes,
        });
        [result.src, result.dst].forEach(updated => {
            const idx = BINS.findIndex(x => x.id === updated.id);
            if (idx > -1) BINS[idx] = updated; else BINS.push(updated);
        });
        toast(`"${itemName}" moved from ${result.src.code} to ${result.dst.code}.`, 's');
        closeSlider(); renderAll();
    } catch(e) { toast(e.message, 'd'); }
    finally { btn.disabled = false; }
}

// ── TOGGLE ACTIVE ─────────────────────────────────────────────────────────────
function toggleActive(binId) {
    const b = BINS.find(x => x.id === binId); if (!b) return;
    const action = b.active ? 'Deactivate' : 'Activate';
    showConfirm(b.active ? '⛔' : '✅', `${action} Bin`,
        `${action} bin <strong>${esc(b.code)}</strong>?`, action,
        async () => {
            try {
                const updated = await apiPost(API + '?api=toggle_bin_active', { id: b.id });
                const idx = BINS.findIndex(x => x.id === updated.id);
                if (idx > -1) BINS[idx] = updated;
                renderAll();
                toast(`${b.code} ${updated.active ? 'activated' : 'deactivated'}.`, 's');
            } catch(e) { toast(e.message, 'd'); }
        });
}

// ── DELETE BIN ────────────────────────────────────────────────────────────────
function deleteBin(binId) {
    const b = BINS.find(x => x.id === binId); if (!b) return;
    showConfirm('🗑️', 'Permanently Delete',
        `<strong>Permanently delete</strong> bin <strong>${esc(b.code)}</strong>? This cannot be undone.`,
        'Delete Permanently',
        async () => {
            try {
                await apiPost(API + '?api=delete_bin', { id: b.id });
                BINS = BINS.filter(x => x.id !== binId);
                renderAll();
                toast(`Bin ${b.code} permanently deleted.`, 'd');
            } catch(e) { toast(e.message, 'd'); }
        }, true);
}

function closeSlider() {
    document.getElementById('mainSlider').classList.remove('on');
    document.getElementById('slOverlay').classList.remove('on');
    const drop = document.getElementById('itemSearchDrop');
    if (drop) drop.remove();
    chipItems = [];
}
document.getElementById('slOverlay').addEventListener('click', closeSlider);
document.getElementById('slClose').addEventListener('click', closeSlider);

// ── CONFIRM MODAL ─────────────────────────────────────────────────────────────
function showConfirm(icon, title, body, label, cb, isDanger = false) {
    document.getElementById('cmIcon').textContent  = icon;
    document.getElementById('cmTitle').textContent = title;
    document.getElementById('cmBody').innerHTML    = body;
    const btn = document.getElementById('cmConfirm');
    btn.textContent = label;
    btn.className = `btn btn-sm ${isDanger ? 'btn-danger-ghost' : 'btn-ghost'}`;
    confirmCb = cb;
    document.getElementById('confirmModal').classList.add('on');
}
document.getElementById('cmConfirm').addEventListener('click', async () => { if (confirmCb) await confirmCb(); document.getElementById('confirmModal').classList.remove('on'); confirmCb = null; });
document.getElementById('cmCancel').addEventListener('click',  () => { document.getElementById('confirmModal').classList.remove('on'); confirmCb = null; });
document.getElementById('confirmModal').addEventListener('click', function(e) { if (e.target === this) { this.classList.remove('on'); confirmCb = null; } });

// ── EXPORT ────────────────────────────────────────────────────────────────────
function doExport() {
    const list = getFiltered();
    const hdrs = ['Bin ID','Code','Zone','Row','Level','Status','Capacity','Used','Util %','Items','Notes'];
    const rows = [hdrs.join(','), ...list.map(b =>
        [b.binId, b.code, zn(b.zone).name, b.row, b.level, b.status, b.capacity, b.used, b.utilPct + '%', `"${b.items.join('; ')}"`, `"${b.notes}"`].join(',')
    )];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([rows.join('\n')], { type: 'text/csv' }));
    a.download = 'bin_mapping.csv'; a.click();
    toast('Bin data exported.', 's');
}

// ── ZONE MANAGER ─────────────────────────────────────────────────────────────
const COLOR_PRESETS = ['#2E7D32','#0D9488','#DC2626','#2563EB','#7C3AED','#D97706','#059669','#0EA5E9','#EC4899','#6B7280'];

// ─────────────────────────────────────────────────────────────────────────────
// zoneEditId tracks whether we're creating (null) or editing (zone id string).
// It is set ONLY inside showZoneForm so both entry points (pill button and
// the list's edit button) always arrive at a consistent state.
// ─────────────────────────────────────────────────────────────────────────────
let zoneEditId = null;

// Called from the zone-legend pill edit button — opens slider straight to form
function openZoneEdit(zoneId) {
    // Open slider first, then render the form (showZoneForm sets zoneEditId)
    document.getElementById('zoneOverlay').classList.add('on');
    document.getElementById('zoneSlider').classList.add('on');
    showZoneForm(zoneId);  // ← do NOT reset zoneEditId here; showZoneForm owns it
}

function openZoneManager() {
    renderZoneList();
    document.getElementById('zoneOverlay').classList.add('on');
    document.getElementById('zoneSlider').classList.add('on');
}

function closeZoneManager() {
    document.getElementById('zoneSlider').classList.remove('on');
    document.getElementById('zoneOverlay').classList.remove('on');
    zoneEditId = null;
}
document.getElementById('zoneOverlay').addEventListener('click', closeZoneManager);
document.getElementById('zSlClose').addEventListener('click',   closeZoneManager);
document.getElementById('zSlCancel').addEventListener('click',  closeZoneManager);
document.getElementById('zSlAdd').addEventListener('click',     () => showZoneForm(null));

function binCountForZone(zoneId) {
    return BINS.filter(b => b.zone === zoneId).length;
}

function renderZoneList() {
    document.getElementById('zSlTitle').textContent = 'Manage Zones';
    document.getElementById('zSlSub').textContent   = `${ZONES.length} zone${ZONES.length !== 1 ? 's' : ''} configured`;

    // Restore the default footer (Close + Add Zone buttons)
    document.getElementById('zSlFoot').innerHTML = `
        <button class="btn btn-ghost btn-sm" id="zSlCancel">Close</button>
        <button class="btn btn-primary btn-sm" id="zSlAdd"><i class="bx bx-plus"></i> Add Zone</button>`;
    document.getElementById('zSlCancel').onclick = closeZoneManager;
    document.getElementById('zSlAdd').onclick    = () => showZoneForm(null);

    const body = document.getElementById('zSlBody');
    if (!ZONES.length) {
        body.innerHTML = `<div style="padding:40px 20px;text-align:center;color:var(--t3)"><i class="bx bx-layer" style="font-size:40px;display:block;margin-bottom:10px;color:#C8E6C9"></i><p>No zones yet. Click <strong>Add Zone</strong> to create one.</p></div>`;
        return;
    }

    const rows = ZONES.map(z => {
        const cnt      = binCountForZone(z.id);
        const hasBins  = cnt > 0;
        return `<div class="zone-row-item" id="zri-${esc(z.id)}">
            <div class="zone-swatch" style="background:${z.color}"><i class="bx bx-layer"></i></div>
            <div class="zone-info">
                <div class="zi-id">${esc(z.id)}</div>
                <div class="zi-name">${esc(z.name)}</div>
                <div class="zi-bins">${cnt} bin${cnt !== 1 ? 's' : ''}</div>
            </div>
            <button class="btn ionly zl-edit-btn" data-zone-id="${esc(z.id)}" title="Edit zone">
                <i class="bx bx-edit"></i>
            </button>
            <button class="btn ionly zl-del-btn" data-zone-id="${esc(z.id)}" title="${hasBins ? 'Remove all bins first' : 'Delete zone'}"
                style="border-color:#FECACA;color:var(--red);${hasBins ? 'opacity:.3;cursor:not-allowed;' : ''}"
                ${hasBins ? 'disabled' : ''}
                onmouseover="if(!this.disabled)this.style.background='#FEE2E2'"
                onmouseout="this.style.background=''">
                <i class="bx bx-trash"></i>
            </button>
        </div>`;
    }).join('');
    body.innerHTML = `<div class="zone-list">${rows}</div>`;

    // Wire edit buttons
    body.querySelectorAll('.zl-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => showZoneForm(btn.dataset.zoneId));
    });
    // Wire delete buttons (only those that are not disabled)
    body.querySelectorAll('.zl-del-btn:not([disabled])').forEach(btn => {
        btn.addEventListener('click', () => confirmDeleteZone(btn.dataset.zoneId));
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// nextZoneId — generates the next available Zone ID.
// Format matches the schema convention: ZN-{LETTER}{TWO_DIGIT_INDEX}
// where the number is tied to the letter's alphabetical position.
// e.g. A=01, B=02, C=03 ... Z=26.  If ZN-A01 is taken → ZN-B02 → ZN-C03 ...
// If all 26 letter-based slots are taken, falls back to ZN-A01.
// ─────────────────────────────────────────────────────────────────────────────
function nextZoneId() {
    const taken   = new Set(ZONES.map(z => z.id));
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // Primary strategy: ZN-A01, ZN-B02 … ZN-Z26
    // Find the next letter whose canonical ID is not yet taken
    for (let i = 0; i < letters.length; i++) {
        const letter    = letters[i];
        const num       = String(i + 1).padStart(2, '0');
        const candidate = `ZN-${letter}${num}`;
        if (!taken.has(candidate)) return candidate;
    }

    // All 26 canonical slots taken — find any free ZN-Xnn slot
    for (const letter of letters) {
        for (let n = 1; n <= 99; n++) {
            const candidate = `ZN-${letter}${String(n).padStart(2, '0')}`;
            if (!taken.has(candidate)) return candidate;
        }
    }

    return 'ZN-A01';
}

// ─────────────────────────────────────────────────────────────────────────────
// showZoneForm — renders the add/edit form and sets zoneEditId correctly.
// editId: string  → edit mode; null/undefined → add mode
// ─────────────────────────────────────────────────────────────────────────────
function showZoneForm(editId) {
    // Canonicalize: treat empty string as null (no editId)
    zoneEditId = editId || null;

    const z        = zoneEditId ? ZONES.find(x => x.id === zoneEditId) : null;
    const defColor = z ? z.color : COLOR_PRESETS[0];
    const isEdit   = !!zoneEditId;

    // Auto-generate a suggested ID for new zones
    const autoId = isEdit ? '' : nextZoneId();

    document.getElementById('zSlTitle').textContent = isEdit ? 'Edit Zone' : 'Add Zone';
    document.getElementById('zSlSub').textContent   = isEdit ? `Editing ${z?.name || zoneEditId}` : 'Create a new warehouse zone';

    const presetHtml = COLOR_PRESETS.map(c =>
        `<div class="cp ${c === defColor ? 'active' : ''}" style="background:${c}" data-color="${c}" onclick="pickPresetColor(this)" title="${c}"></div>`
    ).join('');

    // Edit mode: disabled readonly field. Add mode: editable with auto-gen + refresh button.
    const idFieldAttrs = isEdit
        ? `disabled class="fi" style="font-family:'DM Mono',monospace;text-transform:uppercase;background:var(--bg);color:var(--t3)"`
        : `class="fi" style="font-family:'DM Mono',monospace;text-transform:uppercase" oninput="this.value=this.value.toUpperCase();zoneIdEdited=true"`;

    document.getElementById('zSlBody').innerHTML = `
        <div style="padding:20px 20px 0">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div class="fg2">
                    <div class="fg">
                        <label class="fl">Zone ID <span>*</span></label>
                        ${isEdit
                            ? `<input type="text" ${idFieldAttrs} id="zFId" value="${esc(z.id)}">`
                            : `<div style="display:flex;gap:6px;align-items:center">
                                   <input type="text" ${idFieldAttrs} id="zFId"
                                       placeholder="ZN-A01, ZN-B02…"
                                       value="${autoId}"
                                       style="font-family:'DM Mono',monospace;text-transform:uppercase;flex:1">
                                   <button type="button" id="zIdRefreshBtn"
                                       title="Regenerate ID"
                                       style="flex-shrink:0;width:36px;height:38px;border-radius:9px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;transition:var(--tr)"
                                       onmouseover="this.style.background='var(--gxl)';this.style.color='var(--grn)';this.style.borderColor='var(--grn)'"
                                       onmouseout="this.style.background='';this.style.color='';this.style.borderColor=''">
                                       <i class="bx bx-refresh"></i>
                                   </button>
                               </div>`
                        }
                        <div class="fhint">${isEdit ? 'Zone ID cannot be changed' : 'Auto-generated — edit freely or click <i class="bx bx-refresh" style="vertical-align:middle;font-size:12px"></i> to regenerate'}</div>
                    </div>
                    <div class="fg">
                        <label class="fl">Zone Name <span>*</span></label>
                        <input type="text" class="fi" id="zFName"
                            placeholder="e.g. Zone A — Receiving"
                            value="${z ? esc(z.name) : ''}">
                    </div>
                </div>
                <div class="fg">
                    <label class="fl">Color</label>
                    <div class="zone-color-row">
                        <input type="color" class="color-swatch-input" id="zFColorPicker"
                            value="${defColor}" oninput="syncColorFromPicker(this.value)">
                        <input type="text" class="fi" id="zFColor"
                            value="${defColor}" placeholder="#2E7D32"
                            style="font-family:'DM Mono',monospace;flex:1;text-transform:uppercase"
                            oninput="syncColorFromText(this.value)">
                    </div>
                    <div class="color-presets" style="margin-top:8px">${presetHtml}</div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px">
                    <div id="zPreviewSwatch" style="width:32px;height:32px;border-radius:9px;background:${defColor};flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;color:#fff">
                        <i class="bx bx-layer"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3)">Preview</div>
                        <div id="zPreviewName" style="font-size:13px;font-weight:600;color:var(--t1)">${z ? esc(z.name) : 'Zone Name'}</div>
                    </div>
                </div>
            </div>
        </div>`;

    // Live preview of name
    document.getElementById('zFName').addEventListener('input', e => {
        document.getElementById('zPreviewName').textContent = e.target.value || 'Zone Name';
    });

    // Add-mode only: wire refresh button + live duplicate check
    if (!isEdit) {
        const zFId = document.getElementById('zFId');

        // Refresh button regenerates a free ID
        document.getElementById('zIdRefreshBtn')?.addEventListener('click', () => {
            zFId.value = nextZoneId();
            checkZoneIdConflict(zFId.value);
            zFId.focus();
        });

        // Live conflict indicator as user types
        zFId.addEventListener('input', () => checkZoneIdConflict(zFId.value));

        // Validate the auto-generated value immediately
        checkZoneIdConflict(autoId);
    }

    // Render footer with Back + Save buttons
    document.getElementById('zSlFoot').innerHTML = `
        <button class="btn btn-ghost btn-sm" id="zBackBtn">← Back</button>
        <button class="btn btn-primary btn-sm" id="zSaveBtn">
            <i class="bx bx-save"></i> ${isEdit ? 'Save Changes' : 'Create Zone'}
        </button>`;
    document.getElementById('zBackBtn').onclick = () => renderZoneList();
    document.getElementById('zSaveBtn').onclick = submitZoneForm;

    // Focus name field
    setTimeout(() => document.getElementById('zFName')?.focus(), 150);
}

// Live Zone ID conflict indicator (add mode only)
function checkZoneIdConflict(val) {
    const hint = document.querySelector('#zSlBody .fhint');
    if (!hint) return;
    const v = (val || '').trim().toUpperCase();
    if (!v) {
        hint.innerHTML = 'Auto-generated — edit freely or click <i class="bx bx-refresh" style="vertical-align:middle;font-size:12px"></i> to regenerate';
        hint.style.color = '';
        return;
    }
    const conflict = ZONES.find(z => z.id === v);
    if (conflict) {
        hint.innerHTML = `<span style="color:var(--red)">⚠ "${esc(v)}" already exists — choose a different ID</span>`;
    } else {
        hint.innerHTML = `<span style="color:var(--grn)">✓ "${esc(v)}" is available</span>`;
    }
}

function pickPresetColor(el) {
    const color = el.dataset.color;
    document.querySelectorAll('.cp').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('zFColor').value       = color;
    document.getElementById('zFColorPicker').value = color;
    updateColorPreview(color);
}
function syncColorFromPicker(val) {
    document.getElementById('zFColor').value = val.toUpperCase();
    document.querySelectorAll('.cp').forEach(c => c.classList.toggle('active', c.dataset.color === val));
    updateColorPreview(val);
}
function syncColorFromText(val) {
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        document.getElementById('zFColorPicker').value = val;
        document.querySelectorAll('.cp').forEach(c => c.classList.toggle('active', c.dataset.color.toLowerCase() === val.toLowerCase()));
        updateColorPreview(val);
    }
}
function updateColorPreview(color) {
    const sw = document.getElementById('zPreviewSwatch');
    if (sw) sw.style.background = color;
}

async function submitZoneForm() {
    // In edit mode the Zone ID field is disabled so read from zoneEditId instead
    const id    = zoneEditId
                    ? zoneEditId
                    : (document.getElementById('zFId')?.value.trim().toUpperCase() || '');
    const name  = document.getElementById('zFName')?.value.trim()   || '';
    const color = document.getElementById('zFColor')?.value.trim()  || '#2E7D32';

    if (!id)   { toast('Zone ID is required.', 'w'); return; }
    if (!name) { toast('Zone name is required.', 'w'); return; }
    if (!/^#[0-9A-Fa-f]{6}$/.test(color)) { toast('Enter a valid hex color (e.g. #2E7D32).', 'w'); return; }

    const btn = document.getElementById('zSaveBtn');
    if (btn) btn.disabled = true;

    try {
        // ── FIX: pass edit:true when zoneEditId is set ──────────────────────
        const saved = await apiPost(API + '?api=save_zone', {
            id,
            name,
            color,
            edit: !!zoneEditId,   // ← was always false when opened from pill
        });

        // Update local ZONES array
        const idx = ZONES.findIndex(z => z.id === saved.id);
        if (idx > -1) ZONES[idx] = saved; else ZONES.push(saved);
        ZONES.sort((a, b) => a.id.localeCompare(b.id));

        toast(`Zone ${saved.id} ${zoneEditId ? 'updated' : 'created'}.`, 's');

        // Return to zone list
        renderZoneList();

        // Refresh zone-dependent UI
        buildZoneDropdown();
        renderZoneLegend();
        renderAll();
    } catch(e) {
        toast(e.message, 'd');
        if (btn) btn.disabled = false;
    }
}

function confirmDeleteZone(zoneId) {
    const z = ZONES.find(x => x.id === zoneId); if (!z) return;
    showConfirm('🗑️', 'Delete Zone',
        `Permanently delete zone <strong>${esc(z.name)}</strong> (${esc(z.id)})?<br>
         <span style="font-size:12px;color:var(--red)">This cannot be undone.</span>`,
        'Delete Zone',
        async () => {
            try {
                await apiPost(API + '?api=delete_zone', { id: z.id });
                ZONES = ZONES.filter(x => x.id !== zoneId);
                toast(`Zone ${z.id} deleted.`, 'd');
                renderZoneList();
                buildZoneDropdown(); renderZoneLegend(); renderAll();
            } catch(e) { toast(e.message, 'd'); }
        }, true);
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 's') {
    const ic = { s:'bx-check-circle', w:'bx-error', d:'bx-error-circle' };
    const el = document.createElement('div');
    el.className = `toast t${type}`;
    el.innerHTML = `<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3500);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>